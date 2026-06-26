// ─────────────────────────────────────────────────────────────────────────────
// Pixel-parity harness — REFERENCE vs REBUILD, section by section.
//
// Why pixel-diff and not DOM-diff: when the two sides are a REWRITE (different
// markup), element matching breaks. So we diff PIXELS, aligned by content anchors
// (heading text), band by band. The red diff image + band heights are the gate —
// NOT eyeballing, and NOT the % alone (it undercounts background-heavy bands).
//
// Setup (once, in the project):
//   npm init -y && npm i -D playwright pixelmatch pngjs && npx playwright install chromium
//
// Usage:
//   node parity-harness.mjs                  → all SURFACES, all VIEWPORTS
//   node parity-harness.mjs home             → one surface, BOTH viewports
//   node parity-harness.mjs home desktop     → filter surface AND viewport
//   (run WITHOUT a viewport arg to keep every viewport in one report.html)
//
// Output (./out/): per <surface>.<viewport>.<section>.{legacy,rebuild,diff}.png
//                  + report.html + analysis.json
// ─────────────────────────────────────────────────────────────────────────────

import http from 'node:http';
import { chromium } from 'playwright';
import pixelmatch from 'pixelmatch';
import { PNG } from 'pngjs';
import { promises as fs, existsSync, mkdirSync } from 'fs';
import path from 'path';
import { fileURLToPath, pathToFileURL } from 'url';
import {
  maskFromDiff, clusterMask, classifyKind, mergeRegions,
  countMaskInBoxes, adjustedPct, worklistFilename, readWorklist, writeWorklist, priorSectionRegions,
  normalizeConfig, resolveStorageState, groupResultsByReport, reportFilename, feedbackMarkdown,
} from './parity-lib.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const OUT_DIR = path.join(__dirname, 'out');
const SERVE_PORT = Number(process.argv.find(a => /^\d+$/.test(a))) || 8088;

// ═══════════════════════ CONFIG — EDIT PER PROJECT ══════════════════════════
const LEGACY = 'https://reference.example.com';   // the implementation to match
const REBUILD = 'https://rebuild.example.test';    // your new build

const VIEWPORTS = [
    { name: 'desktop', width: 1920, height: 1080 },
    { name: 'mobile', width: 390, height: 844 },
];

// Each surface is a page; sections are cut by content anchors (heading text),
// ordered top→bottom. A band runs from its anchor to the next anchor below it.
//   fixedTop:   cut at a literal y (e.g. the sticky nav at 0)
//   anchor:     heading text contained in an <h1/h2/h3>
//   selector:   a CSS selector (e.g. 'footer') when there's no heading
//   legacyOnly: section that exists only on the reference — used as a cut line so
//               neighbours stay aligned, but it is not diffed (e.g. a dropped feature)
const SURFACES = [
    {
        name: 'home',
        path: '/',
        waitText: 'some text that proves the page rendered',
        sections: [
            { name: 'nav', fixedTop: 0 },
            { name: 'hero', anchor: 'Hero heading' },
            { name: 'feature', anchor: 'Feature heading' },
            // { name: 'dropped', anchor: 'Only in legacy', legacyOnly: true },
            { name: 'footer', selector: 'footer' },
        ],
    },
];
// ═════════════════════════════════════════════════════════════════════════════

// NOISE_MIN_PIXELS — diff blobs (connected groups of red pixels) smaller than this pixel count
//   are silently dropped from the worklist. Raise to suppress anti-aliasing noise;
//   lower (minimum 1) to surface very small regressions. If the worklist is unexpectedly
//   empty while the % is non-trivial, lower this value and re-run.
const NOISE_MIN_PIXELS = 12;   // diff blobs smaller than this (changed-pixel count) are ignored

// SERVE_PORT — HTTP port used by `node parity-harness.mjs --serve` annotation mode.
//   Override on the command line by passing a bare number: `node parity-harness.mjs --serve 9000`.
//   The port stored here is the hard-coded default; the CLI number takes precedence.

const PIXELMATCH_OPTS = {
    // threshold — per-pixel colour-delta cutoff. 0.1 is lenient (keeps AA noise low), but a
    // SUBTLE recolor (a few levels per channel) can fall UNDER it and never register as a
    // changed pixel — so it never reaches the worklist. Lower toward 0.05 when near-pixel
    // COLOUR parity matters, at the cost of more anti-alias noise.
    threshold: 0.1,      // per-pixel colour delta before it counts as different
    includeAA: false,    // ignore anti-aliasing (font hinting differs across stacks)
    alpha: 0.4,
    diffColor: [255, 0, 0],
};

async function prepare(page, url, surface) {
    await page.goto(url, { waitUntil: 'networkidle' });
    if (surface.waitText) {
        await page.getByText(surface.waitText).first().waitFor({ state: 'visible', timeout: 20000 }).catch(() => {});
    }
    // dismiss common consent/cookie banners so they aren't a diff variable
    for (const sel of ['.js-cookie-consent-agree', '[data-cookie-accept]', 'button:has-text("Accept")', 'button:has-text("Akkoord")']) {
        await page.locator(sel).first().click({ timeout: 800 }).catch(() => {});
    }
    // trigger lazy images, then return to top
    await page.evaluate(async () => {
        const h = document.body.scrollHeight;
        for (let y = 0; y <= h; y += 600) { window.scrollTo(0, y); await new Promise(r => setTimeout(r, 120)); }
        window.scrollTo(0, 0);
        await new Promise(r => setTimeout(r, 800));
    });
    // mask dynamic regions (video thumbnails, carousels) so they don't diff run-to-run
    await page.addStyleTag({ content: 'iframe,video{visibility:hidden !important}' });
}

// Returns { tops: {sectionName: yTop}, pageW, pageH } in document coordinates.
async function anchorTops(page, sections) {
    return page.evaluate((sections) => {
        const headings = Array.from(document.querySelectorAll('h1,h2,h3'));
        const out = {};
        for (const s of sections) {
            if (s.fixedTop !== undefined) { out[s.name] = s.fixedTop; continue; }
            let el = null;
            if (s.selector) el = document.querySelector(s.selector);
            else if (s.anchor) el = headings.find(h => h.textContent.replace(/\s+/g, ' ').trim().includes(s.anchor));
            if (!el) { out[s.name] = null; continue; }
            const r = el.getBoundingClientRect();
            out[s.name] = Math.max(0, Math.round(r.top + window.scrollY) - 28); // a little above to include top padding
        }
        return { tops: out, pageW: document.documentElement.scrollWidth, pageH: document.documentElement.scrollHeight };
    }, sections);
}

async function fullPagePng(page) {
    return PNG.sync.read(await page.screenshot({ fullPage: true, type: 'png' }));
}

function cropBand(src, top, bottom) {
    const t = Math.max(0, Math.min(top, src.height - 1));
    const b = Math.max(t + 1, Math.min(bottom, src.height));
    const out = new PNG({ width: src.width, height: b - t });
    PNG.bitblt(src, out, 0, t, src.width, b - t, 0, 0);
    return out;
}

function diffPngs(a, b) {
    const width = Math.min(a.width, b.width);
    const height = Math.min(a.height, b.height);
    const reframe = (src) => {
        if (src.width === width && src.height === height) return src;
        const out = new PNG({ width, height });
        PNG.bitblt(src, out, 0, 0, width, height, 0, 0);
        return out;
    };
    const A = reframe(a), B = reframe(b);
    const diff = new PNG({ width, height });
    const n = pixelmatch(A.data, B.data, diff.data, width, height, PIXELMATCH_OPTS);
    return { diff, numDiff: n, total: width * height };
}

function nextTop(allTops, top, pageH) {
    const greater = Object.values(allTops).filter(v => v !== null && v > top + 4);
    return greater.length ? Math.min(...greater) : pageH;
}

// Resolve the active config: external --config <path> module (default-exporting the config
// object) or the in-file CONFIG block as a backward-compatible fallback.
export async function loadConfig(argv = []) {
  const ci = argv.indexOf('--config');
  if (ci !== -1 && argv[ci + 1]) {
    const abs = path.resolve(process.cwd(), argv[ci + 1]);
    const mod = await import(pathToFileURL(abs).href);
    return { ...normalizeConfig(mod.default), dir: path.dirname(abs) };
  }
  return { ...normalizeConfig({
    legacy: LEGACY, rebuild: REBUILD, viewports: VIEWPORTS, surfaces: SURFACES,
    authProfiles: {}, noiseMinPixels: NOISE_MIN_PIXELS, threshold: PIXELMATCH_OPTS.threshold,
  }), dir: __dirname };
}

async function run() {
    const argv = process.argv.slice(2);
    const CONFIG = await loadConfig(argv);
    PIXELMATCH_OPTS.threshold = CONFIG.threshold;
    const ri = argv.indexOf('--report');
    const reportFilter = ri !== -1 ? argv[ri + 1] : null;
    const filters = [];
    for (let i = 0; i < argv.length; i++) {
        if (argv[i] === '--config' || argv[i] === '--report') { i++; continue; }
        if (argv[i] === '--serve' || /^\d+$/.test(argv[i])) continue;
        filters.push(argv[i]);
    }
    const vpNames = CONFIG.viewports.map(x => x.name);
    let surfaces = CONFIG.surfaces.filter(s => !filters.length || filters.includes(s.name));
    if (reportFilter) surfaces = surfaces.filter(s => (s.report || 'default') === reportFilter);
    const vps = CONFIG.viewports.filter(v => !filters.length || filters.includes(v.name) || !filters.some(f => vpNames.includes(f)));

    if (!existsSync(OUT_DIR)) mkdirSync(OUT_DIR, { recursive: true });

    const browser = await chromium.launch({ headless: true });
    const results = [];
    try {
        for (const surface of surfaces) {
            for (const vp of vps) {
                console.log(`\n→ ${surface.name} @ ${vp.name} (${vp.width}×${vp.height})`);
                const stateRel = resolveStorageState(surface, CONFIG.authProfiles, CONFIG.defaultStorageState);
                const stateAbs = stateRel ? path.resolve(CONFIG.dir, stateRel) : undefined;
                const ctx = await browser.newContext({ viewport: { width: vp.width, height: vp.height }, ignoreHTTPSErrors: true, deviceScaleFactor: 1, storageState: stateAbs && existsSync(stateAbs) ? stateAbs : undefined });
                const lp = await ctx.newPage(), rp = await ctx.newPage();
                await prepare(lp, `${CONFIG.legacy}${surface.path}`, surface);
                await prepare(rp, `${CONFIG.rebuild}${surface.path}`, surface);

                const [legMeta, rebMeta] = await Promise.all([anchorTops(lp, surface.sections), anchorTops(rp, surface.sections)]);
                const [legPng, rebPng] = await Promise.all([fullPagePng(lp), fullPagePng(rp)]);

                const tag = `${surface.name}.${vp.name}`;
                const sectionResults = [];
                const priorWorklist = await readWorklist(path.join(OUT_DIR, worklistFilename(surface.name, vp.name)));

                for (const s of surface.sections) {
                    if (s.legacyOnly) continue;
                    const lt = legMeta.tops[s.name], rt = rebMeta.tops[s.name];
                    if (lt == null || rt == null) { sectionResults.push({ section: s.name, missing: { legacy: lt == null, rebuild: rt == null } }); continue; }
                    const lBand = cropBand(legPng, lt, nextTop(legMeta.tops, lt, legMeta.pageH));
                    const rBand = cropBand(rebPng, rt, nextTop(rebMeta.tops, rt, rebMeta.pageH));
                    const { diff, numDiff, total } = diffPngs(lBand, rBand);
                    const base = `${tag}.${s.name}`;
                    await fs.writeFile(path.join(OUT_DIR, `${base}.legacy.png`), PNG.sync.write(lBand));
                    await fs.writeFile(path.join(OUT_DIR, `${base}.rebuild.png`), PNG.sync.write(rBand));
                    await fs.writeFile(path.join(OUT_DIR, `${base}.diff.png`), PNG.sync.write(diff));
                    const fresh = await detectRegions(lp, rp, diff, lt, rt, { minPixels: CONFIG.noiseMinPixels });
                    const merged = mergeRegions(fresh, priorSectionRegions(priorWorklist, s.name));
                    await classifyHumanRegions(lp, rp, merged, lt, rt);
                    const wontfixBoxes = merged.filter(r => r.status === 'wontfix').map(r => r.box);
                    const changedInWontfix = countMaskInBoxes(maskFromDiff(diff.data, diff.width, diff.height), diff.width, wontfixBoxes);
                    sectionResults.push({
                        section: s.name, base,
                        pct: (numDiff / total * 100).toFixed(2),
                        legacyH: lBand.height, rebuildH: rBand.height,
                        legacyTop: lt, rebuildTop: rt, diffW: diff.width, diffH: diff.height,
                        adjustedPct: adjustedPct(numDiff, total, changedInWontfix),
                        openCount: merged.filter(r => r.status === 'open').length,
                        regions: merged,
                    });
                    console.log(`   ${s.name.padEnd(13)} ${(numDiff / total * 100).toFixed(2).padStart(6)}%  (legacy ${lBand.height}px / rebuild ${rBand.height}px)`);
                }
                await writeWorklist(
                    path.join(OUT_DIR, worklistFilename(surface.name, vp.name)),
                    {
                        surface: surface.name, viewport: vp.name,
                        sections: sectionResults.filter(s => !s.missing).map(s => ({
                            section: s.section, legacyTop: s.legacyTop, rebuildTop: s.rebuildTop,
                            pixelPct: +s.pct, adjustedPct: s.adjustedPct, openCount: s.openCount, regions: s.regions,
                        })),
                    },
                );
                results.push({ surface: surface.name, viewport: vp.name, report: surface.report || 'default', sections: sectionResults });
                await ctx.close();
            }
        }
        await fs.writeFile(path.join(OUT_DIR, 'analysis.json'), JSON.stringify(results, null, 2));
        for (const g of groupResultsByReport(results)) {
            const file = reportFilename(g.name);
            await fs.writeFile(path.join(OUT_DIR, file), reportHtml(g.results, { reportName: g.name === 'default' ? null : g.name }));
            console.log(`\nReport: file://${path.join(OUT_DIR, file)}`);
        }
    } finally {
        await browser.close();
    }
}

export function reportScript(worklistByKey, reportName) {
  const safeJson = JSON.stringify(worklistByKey).replace(/</g, '\\u003c');
  return `<script>
const WL = ${safeJson}; // key: surface.viewport.section -> regions[]
const REPORT_NAME = ${JSON.stringify(reportName || null)};
const STATUSES = ['open','wontfix'];
const served = location.protocol.startsWith('http');

// fix-list serializer — the SAME pure function the Node side uses (embedded verbatim)
${feedbackMarkdown.toString()}

const paneOf = (rg) => rg.pane || (rg.kind === 'missing' ? 'legacy' : 'rebuild');
const colorFor = (rg) => rg.status === 'wontfix' ? '#22c55e' : (rg.source === 'human' ? '#60a5fa' : '#f59e0b');
const labelOf = (rg) => rg.source === 'human' && !rg.kind ? 'you' : (rg.kind || 'unclassified');
const escapeHtml = (s) => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
// identical machine differences (same kind+detail) collapse into one row; humans never group
const groupKey = (rg) => rg.source === 'human' ? 'h:' + rg.id : 'a:' + (rg.kind || '?') + '|' + (rg.detail || '');
function groupsFor(k) {
  const live = (WL[k] || []).filter(r => r.status !== 'fixed');
  const map = new Map();
  for (const rg of live) { const g = groupKey(rg); if (!map.has(g)) map.set(g, []); map.get(g).push(rg); }
  // human annotations lead the list — never buried under machine rows (sort is stable, so machine order is preserved)
  return [...map.values()].sort((a, b) => (b[0].source === 'human') - (a[0].source === 'human'));
}

// ── editor popover: note + ignore toggle + delete (NO kind — categories are machine-owned) ──
const ed = document.createElement('div'); ed.id = 'editor';
const mkRow = (t, child) => { const r=document.createElement('div'); r.className='ed-row'; const l=document.createElement('label'); l.textContent=t; r.appendChild(l); r.appendChild(child); return r; };
const edNote = document.createElement('input'); edNote.type='text'; edNote.placeholder='what looks wrong? (your words)';
const edSeg = document.createElement('div'); edSeg.className='ed-seg';
STATUSES.forEach(st => { const b=document.createElement('button'); b.type='button'; b.dataset.st=st; b.textContent = st==='wontfix' ? 'ignore' : 'open'; edSeg.appendChild(b); });
const edActions = document.createElement('div'); edActions.className='ed-actions';
const edDel = document.createElement('button'); edDel.className='ed-danger'; edDel.textContent='Delete';
const edClose = document.createElement('button'); edClose.id='ed-close'; edClose.textContent='Done';
const spacer = document.createElement('span'); spacer.style.flex='1';
edActions.append(edDel, spacer, edClose);
ed.append(mkRow('Note', edNote), mkRow('Status', edSeg), edActions);
document.body.appendChild(ed);

let editing = null; // { k, ids: [...] }
const members = () => editing ? (WL[editing.k]||[]).filter(r => editing.ids.includes(r.id)) : [];
function openEditor(k, ids, clientX, clientY) {
  if (typeof ids === 'string') ids = [ids];
  const list = (WL[k]||[]).filter(r => ids.includes(r.id)); if (!list.length) return;
  editing = { k, ids };
  const rep = list[0];
  edNote.value = rep.note || '';
  edSeg.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.st === (rep.status || 'open')));
  ed.style.display = 'block';
  const w = 280, pad = 12;
  let left = Math.min(clientX + 10, window.innerWidth - w - pad);
  let top = clientY + 10; if (top + 160 > window.innerHeight) top = Math.max(pad, clientY - 170);
  ed.style.left = Math.max(pad, left) + 'px'; ed.style.top = (top + window.scrollY) + 'px';
  showIds(k, ids, true);
}
function closeEditor() { if (editing) showIds(editing.k, editing.ids, false); ed.style.display = 'none'; editing = null; }
edNote.oninput = () => { members().forEach(rg => { rg.note = edNote.value; rg.source = rg.source || 'human'; }); renderAll(); };
edSeg.querySelectorAll('button').forEach(b => b.onclick = () => {
  if (!editing) return;
  members().forEach(rg => { rg.status = b.dataset.st; rg.source = rg.source || 'human'; });
  edSeg.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
  renderAll();
});
edDel.onclick = () => { if (!editing) return; WL[editing.k] = (WL[editing.k]||[]).filter(r => !editing.ids.includes(r.id)); closeEditor(); renderAll(); };
edClose.onclick = closeEditor;
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeEditor(); });

function svgPoint(svg, evt) {
  const vb = svg.viewBox.baseVal, rect = svg.getBoundingClientRect();
  return { x: Math.round((evt.clientX-rect.left)/rect.width*vb.width), y: Math.round((evt.clientY-rect.top)/rect.height*vb.height) };
}

function drawPane(svg, k, pane) {
  svg.querySelectorAll('.rgn,.rgn-label').forEach(n => n.remove());
  for (const rg of WL[k] || []) {
    if (rg.status === 'fixed' || paneOf(rg) !== pane) continue;
    const [x,y,w,h] = rg.box, stroke = colorFor(rg);
    const human = rg.source === 'human';
    const rect = document.createElementNS('http://www.w3.org/2000/svg','rect');
    rect.setAttribute('class', 'rgn' + (human ? ' show' : '')); rect.dataset.id = rg.id;   // human boxes stay visible
    rect.setAttribute('x',x); rect.setAttribute('y',y); rect.setAttribute('width',w); rect.setAttribute('height',h);
    rect.setAttribute('fill','transparent'); rect.setAttribute('stroke',stroke); rect.setAttribute('stroke-width','2');
    if (rg.status === 'wontfix') { rect.setAttribute('stroke-dasharray','6 4'); rect.setAttribute('opacity','0.65'); }
    rect.onclick = (e) => { e.stopPropagation(); openEditor(k, [rg.id], e.clientX, e.clientY); };
    svg.appendChild(rect);
    const t = document.createElementNS('http://www.w3.org/2000/svg','text');
    t.setAttribute('class','rgn-label' + (human ? ' show' : '')); t.dataset.id = rg.id;
    t.setAttribute('x',x+2); t.setAttribute('y',y+12); t.setAttribute('fill',stroke); t.setAttribute('font-size','11');
    t.textContent = labelOf(rg); svg.appendChild(t);
  }
}

function drawFixlist(box, k) {
  const groups = groupsFor(k);
  box.innerHTML = '';
  if (!groups.length) { box.innerHTML = '<div class="fix-empty">no differences — clean ✓</div>'; return; }
  for (const members of groups) {
    const rep = members[0], ids = members.map(m => m.id);
    const ignored = members.every(m => m.status === 'wontfix');
    const row = document.createElement('div');
    row.className = 'fix-row' + (ignored ? ' ignored' : ''); row.dataset.id = rep.id; row.dataset.ids = ids.join(',');
    const count = members.length > 1 ? '<span class="count">×' + members.length + '</span>' : '';
    row.innerHTML = '<span class="cat" style="color:' + colorFor(rep) + '">' + escapeHtml(labelOf(rep)) + '</span>'
      + '<span class="meta">' + (rep.detail ? escapeHtml(rep.detail) : '(' + rep.box.join(',') + ')') + '</span>' + count
      + (rep.note ? '<span class="note">' + escapeHtml(rep.note) + '</span>' : '');
    row.onmouseenter = () => showIds(k, ids, true);
    row.onmouseleave = () => showIds(k, ids, false);
    row.onclick = (e) => openEditor(k, ids, e.clientX, e.clientY);
    box.appendChild(row);
  }
}
// reveal/hide a set of MACHINE boxes (human boxes are always visible, so leave them alone)
function showIds(k, ids, on) {
  const regions = WL[k] || [];
  for (const id of ids) {
    const rg = regions.find(r => r.id === id);
    if (rg && rg.source === 'human') continue;
    document.querySelectorAll('.sec[data-key="' + CSS.escape(k) + '"] .overlay [data-id="' + CSS.escape(id) + '"]').forEach(el => el.classList.toggle('show', on));
  }
}

function renderAll() {
  document.querySelectorAll('.sec[data-key]').forEach(sec => {
    const k = sec.dataset.key;
    sec.querySelectorAll('.pane').forEach(p => drawPane(p.querySelector('.overlay'), k, p.dataset.pane));
    drawFixlist(sec.querySelector('.fixlist-rows'), k);
    const open = (WL[k]||[]).filter(r => r.status !== 'wontfix' && r.status !== 'fixed').length;
    const c = sec.querySelector('.opencount'); if (c) c.textContent = open + ' open';
  });
}

// drag on a readable pane to add a human region tagged with that pane
document.querySelectorAll('.sec .pane').forEach(pane => {
  const svg = pane.querySelector('.overlay'); const sec = pane.closest('.sec'); const k = sec.dataset.key;
  svg.style.pointerEvents = 'all';
  let start = null;
  svg.addEventListener('mousedown', e => { if (e.target.classList.contains('rgn')) return; start = svgPoint(svg,e); });
  svg.addEventListener('mouseleave', () => { start = null; });
  svg.addEventListener('mouseup', e => {
    if (!start) return;
    const end = svgPoint(svg,e);
    const x = Math.min(start.x,end.x), y = Math.min(start.y,end.y), w = Math.abs(end.x-start.x), h = Math.abs(end.y-start.y);
    start = null; if (w < 4 || h < 4) return;
    const id = 'h' + Date.now() + '-' + Math.floor(Math.random() * 1e6);
    (WL[k] = WL[k] || []).push({ id, box:[x,y,w,h], source:'human', kind:null, note:'', status:'open', pane: pane.dataset.pane });
    renderAll(); sec.querySelector('.fixlist').scrollTop = 0; openEditor(k, [id], e.clientX, e.clientY);
  });
});

// raw-diff toggle per section
document.querySelectorAll('.diff-toggle').forEach(btn => {
  btn.onclick = () => { const d = btn.closest('.sec').querySelector('.diffrow'); d.hidden = !d.hidden; btn.textContent = d.hidden ? 'show raw diff ▸' : 'hide raw diff ▾'; };
});

// fullscreen zoom on a pane image
document.querySelectorAll('.pane img').forEach(i => { i.onclick = () => document.fullscreenElement ? document.exitFullscreen() : i.requestFullscreen(); });

// Copy feedback — file://-safe: clipboard, else select-in-textarea fallback. Never blocks.
const fb = document.getElementById('copyfeedback');
function flash(t){ const o = fb.dataset.label; fb.textContent = t; setTimeout(() => fb.textContent = o, 1500); }
fb.dataset.label = fb.textContent;
fb.onclick = async () => {
  const md = feedbackMarkdown(WL, { reportName: REPORT_NAME });
  if (navigator.clipboard && navigator.clipboard.writeText) {
    try { await navigator.clipboard.writeText(md); flash('Copied ✓'); return; } catch (e) {}
  }
  const ta = document.getElementById('fbtext'); ta.value = md; ta.hidden = false; ta.focus(); ta.select();
  try { document.execCommand('copy'); flash('Copied ✓'); } catch (e) { flash('select + copy'); }
};

// optional disk persistence — only when served over http(s)
const saveBtn = document.getElementById('save');
if (served) {
  saveBtn.hidden = false;
  saveBtn.onclick = async () => {
    saveBtn.textContent = 'Saving…';
    const files = {};
    for (const [k, regions] of Object.entries(WL)) {
      const [surface, viewport, section] = k.split('.');
      const id = surface + '.' + viewport;
      (files[id] = files[id] || { surface, viewport, sections: [] }).sections.push({ section, regions });
    }
    for (const data of Object.values(files)) {
      await fetch('/worklist', { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify(data) });
    }
    saveBtn.textContent = 'Saved ✓'; setTimeout(() => saveBtn.textContent = 'Save to disk', 1500);
  };
}

renderAll();
\x3c/script>`;
}

export function worklistByKey(results) {
  const map = {};
  for (const r of results) for (const s of r.sections) {
    if (s.missing) continue;
    map[`${r.surface}.${r.viewport}.${s.section}`] = s.regions ?? [];
  }
  return map;
}

export function reportHtml(results, { reportName } = {}) {
    const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    const paneOf = (rg) => rg.pane || (rg.kind === 'missing' ? 'legacy' : 'rebuild');
    const colorFor = (rg) => rg.status === 'wontfix' ? '#22c55e' : (rg.source === 'human' ? '#60a5fa' : '#f59e0b');
    const labelOf = (rg) => rg.source === 'human' && !rg.kind ? 'you' : (rg.kind || 'unclassified');
    const groupKey = (rg) => rg.source === 'human' ? 'h:' + rg.id : 'a:' + (rg.kind || '?') + '|' + (rg.detail || '');
    const groupRegions = (regions) => {
      const map = new Map();
      for (const rg of regions) { const g = groupKey(rg); if (!map.has(g)) map.set(g, []); map.get(g).push(rg); }
      // human annotations lead the list — never buried under machine rows (sort is stable, so machine order is preserved)
      return [...map.values()].sort((a, b) => (b[0].source === 'human') - (a[0].source === 'human'));
    };
    const boxSvg = (rg) => {
      const [x, y, w, h] = rg.box, stroke = colorFor(rg);
      const cls = 'rgn' + (rg.source === 'human' ? ' show' : '');
      const dash = rg.status === 'wontfix' ? 'stroke-dasharray="6 4" opacity="0.65"' : '';
      return `<rect class="${cls}" data-id="${esc(rg.id)}" x="${x}" y="${y}" width="${w}" height="${h}" fill="transparent" stroke="${stroke}" stroke-width="2" ${dash}></rect>` +
        `<text class="rgn-label${rg.source === 'human' ? ' show' : ''}" data-id="${esc(rg.id)}" x="${x + 2}" y="${y + 12}" fill="${stroke}" font-size="11">${esc(labelOf(rg))}</text>`;
    };
    const fixRow = (members) => {
      const rep = members[0], ids = members.map(m => esc(m.id)).join(',');
      const ignored = members.every(m => m.status === 'wontfix');
      const count = members.length > 1 ? `<span class="count">×${members.length}</span>` : '';
      const meta = rep.detail ? esc(rep.detail) : `(${rep.box.join(',')})`;
      return `<div class="fix-row${ignored ? ' ignored' : ''}" data-id="${esc(rep.id)}" data-ids="${ids}">` +
        `<span class="cat" style="color:${colorFor(rep)}">${esc(labelOf(rep))}</span>` +
        `<span class="meta">${meta}</span>${count}` +
        (rep.note ? `<span class="note">${esc(rep.note)}</span>` : '') + `</div>`;
    };

    const block = (r) => {
        const rows = r.sections.map(s => {
            if (s.missing) return `<div class="sec miss"><h3>${esc(s.section)} — ANCHOR NOT FOUND (legacy:${s.missing.legacy} rebuild:${s.missing.rebuild})</h3></div>`;
            const heavy = parseFloat(s.pct) > 0.5;
            const live = (s.regions ?? []).filter(rg => rg.status !== 'fixed');
            const legacyBoxes = live.filter(rg => paneOf(rg) === 'legacy').map(boxSvg).join('');
            const rebuildBoxes = live.filter(rg => paneOf(rg) === 'rebuild').map(boxSvg).join('');
            const fixRows = live.length ? groupRegions(live).map(fixRow).join('') : '<div class="fix-empty">no differences — clean ✓</div>';
            const k = `${r.surface}.${r.viewport}.${s.section}`;
            return `<div class="sec" data-key="${esc(k)}">
              <h3>${esc(s.section)} — <span class="${heavy ? 'bad' : 'ok'}">${s.pct}% diff</span>
                <span class="dim">· adj ${s.adjustedPct}% · <span class="opencount">${s.openCount} open</span> · (legacy ${s.legacyH}px / rebuild ${s.rebuildH}px) · <button class="diff-toggle">show raw diff ▸</button></span></h3>
              <div class="panes">
                <figure class="pane legacy" data-pane="legacy"><figcaption>legacy · ✎ draw</figcaption>
                  <div class="canvas"><img src="${s.base}.legacy.png"><svg class="overlay" preserveAspectRatio="none" viewBox="0 0 ${s.diffW ?? 1} ${s.legacyH ?? s.diffH ?? 1}">${legacyBoxes}</svg></div></figure>
                <figure class="pane rebuild" data-pane="rebuild"><figcaption>rebuild · ✎ draw</figcaption>
                  <div class="canvas"><img src="${s.base}.rebuild.png"><svg class="overlay" preserveAspectRatio="none" viewBox="0 0 ${s.diffW ?? 1} ${s.rebuildH ?? s.diffH ?? 1}">${rebuildBoxes}</svg></div></figure>
                <div class="fixlist"><div class="fixlist-rows">${fixRows}</div></div>
              </div>
              <div class="diffrow" hidden><figcaption>raw diff (red = differs)</figcaption><img src="${s.base}.diff.png"></div>
            </div>`;
        }).join('\n');
        return `<section class="surface"><h2>${esc(r.surface)} @ ${esc(r.viewport)}</h2>${rows}</section>`;
    };
    return `<!doctype html><html><head><meta charset="utf-8"><title>Visual parity — reference vs rebuild</title>
<style>
  body{font-family:ui-sans-serif,system-ui,sans-serif;margin:0;background:#0a0a0a;color:#f5f5f5}
  header{padding:1rem 1.5rem;background:#18181b;border-bottom:1px solid #27272a;position:sticky;top:0;z-index:40;display:flex;gap:.75rem;align-items:center}
  header h1{margin:0;font-size:1rem;flex:1}
  header button{background:#f59e0b;color:#000;border:none;border-radius:6px;padding:6px 12px;font-weight:600;cursor:pointer}
  header button#save{background:#3f3f46;color:#fff}
  h2{font-size:1rem;color:#a1a1aa;margin:1.5rem 1.5rem .5rem}
  .surface{border-bottom:1px solid #27272a;padding-bottom:1rem}
  .sec{padding:.5rem 1.5rem 1rem} .sec h3{font-size:.9rem;font-weight:500;color:#d4d4d8;margin:.5rem 0}
  .ok{color:#22c55e;font-weight:700}.bad{color:#ef4444;font-weight:700}.dim{color:#71717a;font-size:.8rem}
  .panes{display:grid;grid-template-columns:1fr 1fr minmax(240px,.8fr);gap:.75rem;align-items:start}
  figure{margin:0}figcaption{font-size:.7rem;color:#71717a;text-transform:uppercase;letter-spacing:.05em;padding-bottom:.25rem}
  .canvas{position:relative;display:block}
  .canvas img{width:100%;display:block;border:1px solid #27272a;background:#fff;cursor:zoom-in}
  img:fullscreen{width:auto;height:100vh;background:#fff}
  .overlay{position:absolute;inset:0;width:100%;height:100%;pointer-events:none}
  .overlay .rgn,.overlay .rgn-label{visibility:hidden}
  .overlay .rgn.show{visibility:visible;fill:rgba(96,165,250,.18);pointer-events:all;cursor:pointer}
  .overlay .rgn-label.show{visibility:visible}
  .pane .canvas{cursor:crosshair}
  .miss h3{color:#f59e0b}
  .fixlist{font-size:.8rem;border:1px solid #27272a;border-radius:6px;background:#0f0f10;max-height:480px;overflow:auto}
  .fix-row{padding:.35rem .5rem;border-bottom:1px solid #1f1f22;cursor:pointer;display:flex;gap:.4rem;flex-wrap:wrap;align-items:baseline}
  .fix-row:hover{background:#1c1c20}
  .fix-row.ignored{opacity:.5}
  .fix-row .cat{font-weight:700}
  .fix-row .meta{color:#a1a1aa;font-size:.75rem}
  .fix-row .count{color:#fbbf24;font-size:.72rem;font-weight:700}
  .fix-row .note{color:#f5f5f5;flex-basis:100%}
  .fix-empty{color:#22c55e;padding:.5rem}
  .diff-toggle{background:#27272a;color:#a1a1aa;border:1px solid #3f3f46;border-radius:5px;padding:1px 7px;cursor:pointer;font-size:.7rem}
  .diffrow{padding:.5rem 0}
  .diffrow img{width:50%;display:block;border:1px solid #27272a;background:#fff}
  #fbtext{position:fixed;bottom:8px;right:8px;width:40vw;height:30vh;z-index:60;background:#0a0a0a;color:#ddd;border:1px solid #3f3f46}
  #editor{position:absolute;z-index:50;display:none;width:280px;background:#18181b;border:1px solid #3f3f46;border-radius:8px;padding:10px;box-shadow:0 8px 24px rgba(0,0,0,.55);font-size:.8rem}
  #editor .ed-row{display:flex;align-items:center;gap:8px;margin-bottom:8px}
  #editor label{width:46px;color:#a1a1aa}
  #editor input{flex:1;background:#0a0a0a;color:#f5f5f5;border:1px solid #3f3f46;border-radius:5px;padding:4px 6px}
  #editor .ed-seg{display:flex;gap:4px;flex:1}
  #editor .ed-seg button{flex:1;background:#0a0a0a;color:#a1a1aa;border:1px solid #3f3f46;border-radius:5px;padding:4px;cursor:pointer;text-transform:capitalize}
  #editor .ed-seg button.on{background:#f59e0b;color:#000;border-color:#f59e0b;font-weight:600}
  #editor .ed-seg button[data-st=wontfix].on{background:#22c55e;color:#000;border-color:#22c55e}
  #editor .ed-actions{display:flex;align-items:center;gap:8px}
  #editor .ed-danger{background:#7f1d1d;color:#fff;border:1px solid #991b1b;border-radius:5px;padding:4px 8px;cursor:pointer}
  #editor #ed-close{background:#3f3f46;color:#fff;border:none;border-radius:5px;padding:4px 12px;cursor:pointer}
</style></head><body>
<header><h1>Visual parity — legacy │ rebuild │ fix-list · annotate on the readable panes · % undercounts background-heavy bands → trust the panes</h1><button id="copyfeedback">Copy feedback</button><button id="save" hidden>Save to disk</button></header>
<textarea id="fbtext" hidden></textarea>
${results.map(block).join('\n')}
${reportScript(worklistByKey(results), reportName)}
</body></html>`;
}

// Detect + classify regions for ONE section, from its already-computed band diff PNG.
// diff: the pngjs PNG returned by diffPngs() for this section (band-local pixels).
// legacyTop / rebuildTop: document y of the band's top on each side.
export async function detectRegions(lp, rp, diff, legacyTop, rebuildTop, { minPixels = 12 } = {}) {
  const mask = maskFromDiff(diff.data, diff.width, diff.height);
  const regions = clusterMask(mask, diff.width, diff.height, { minPixels });
  const out = [];
  let n = 0;
  for (const reg of regions) {
    const cx = reg.x + Math.floor(reg.w / 2);
    const cy = reg.y + Math.floor(reg.h / 2);
    const legacyHit = await captureHit(lp, cx, legacyTop + cy);
    const rebuildHit = await captureHit(rp, cx, rebuildTop + cy);
    const { kind, detail } = classifyKind(legacyHit, rebuildHit);
    out.push({ id: `a${++n}`, box: [reg.x, reg.y, reg.w, reg.h], source: 'auto', kind, detail, status: 'open' });
  }
  return out;
}

// Classify human-drawn regions the same way auto regions are classified: hit-test the box
// centre on both sides and run classifyKind. Run on re-runs so a box YOU drew comes back
// labelled — categories are the machine's job, not yours. Preserves note/status/pane.
export async function classifyHumanRegions(lp, rp, regions, legacyTop, rebuildTop) {
  for (const rg of regions) {
    if (rg.source !== 'human' || (rg.kind && rg.kind !== 'other')) continue;
    const cx = rg.box[0] + Math.floor(rg.box[2] / 2);
    const cy = rg.box[1] + Math.floor(rg.box[3] / 2);
    const lh = await captureHit(lp, cx, legacyTop + cy);
    const rh = await captureHit(rp, cx, rebuildTop + cy);
    const { kind, detail } = classifyKind(lh, rh);
    rg.kind = kind; rg.detail = detail;
  }
  return regions;
}

// Hit-test a document point on one render; returns the Hit shape classifyKind expects.
export async function captureHit(page, docX, docY) {
  return page.evaluate(({ x, y }) => {
    window.scrollTo(0, Math.max(0, y - window.innerHeight / 2));
    const el = document.elementFromPoint(x, y - window.scrollY);
    if (!el) return { present: false };
    const b = el.getBoundingClientRect();
    const cs = getComputedStyle(el);
    return {
      present: true,
      tag: el.tagName.toLowerCase(),
      box: {
        x: Math.round(b.left + window.scrollX), y: Math.round(b.top + window.scrollY),
        w: Math.round(b.width), h: Math.round(b.height),
      },
      styles: {
        color: cs.color, backgroundColor: cs.backgroundColor, backgroundImage: cs.backgroundImage,
        backgroundSize: cs.backgroundSize, backgroundPosition: cs.backgroundPosition,
        borderRadius: cs.borderRadius, boxShadow: cs.boxShadow,
        fontFamily: cs.fontFamily, fontSize: cs.fontSize, fontWeight: cs.fontWeight,
        zIndex: cs.zIndex,
      },
    };
  }, { x: docX, y: docY });
}

export function serve(port = SERVE_PORT) {
  const types = { '.html': 'text/html', '.png': 'image/png', '.json': 'application/json', '.js': 'text/javascript' };
  const server = http.createServer(async (req, res) => {
    if (req.method === 'POST' && req.url === '/worklist') {
      let body = '';
      req.on('data', c => body += c);
      req.on('end', async () => {
        try {
          const data = JSON.parse(body);
          const safeName = /^[\w-]+$/;
          if (!safeName.test(data.surface) || !safeName.test(data.viewport)) { res.writeHead(400).end('bad surface/viewport'); return; }
          const target = path.join(OUT_DIR, worklistFilename(data.surface, data.viewport));
          if (target !== OUT_DIR && !target.startsWith(OUT_DIR + path.sep)) { res.writeHead(400).end('bad surface/viewport'); return; }
          await writeWorklist(target, data);
          res.writeHead(200).end('ok');
        } catch (e) { res.writeHead(400).end(String(e)); }
      });
      return;
    }
    const rel = req.url === '/' ? 'report.html' : decodeURIComponent(req.url.split('?')[0]).replace(/^\/+/, '');
    const file = path.join(OUT_DIR, rel);
    if (file !== OUT_DIR && !file.startsWith(OUT_DIR + path.sep)) { res.writeHead(403).end('forbidden'); return; }
    try {
      const buf = await fs.readFile(file);
      res.writeHead(200, { 'content-type': types[path.extname(file)] || 'application/octet-stream' }).end(buf);
    } catch { res.writeHead(404).end('not found'); }
  });
  return new Promise((resolve) => server.listen(port, () => {
    console.log(`Serving report: http://localhost:${server.address().port}/`);
    resolve(server);
  }));
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  if (process.argv.includes('--serve')) { serve().catch(e => { console.error(e); process.exit(1); }); }
  else { run().catch(e => { console.error(e); process.exit(1); }); }
}
