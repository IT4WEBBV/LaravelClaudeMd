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

import { chromium } from 'playwright';
import pixelmatch from 'pixelmatch';
import { PNG } from 'pngjs';
import { promises as fs, existsSync, mkdirSync } from 'fs';
import path from 'path';
import { fileURLToPath, pathToFileURL } from 'url';
import {
  maskFromDiff, clusterMask, classifyKind, mergeRegions,
  countMaskInBoxes, adjustedPct, worklistFilename, readWorklist, writeWorklist, priorSectionRegions,
} from './parity-lib.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const OUT_DIR = path.join(__dirname, 'out');

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

const NOISE_MIN_PIXELS = 12;   // diff blobs smaller than this (changed-pixel count) are ignored

const PIXELMATCH_OPTS = {
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

async function run() {
    const filters = process.argv.slice(2);
    const surfaces = SURFACES.filter(s => !filters.length || filters.includes(s.name));
    const vpNames = VIEWPORTS.map(x => x.name);
    const vps = VIEWPORTS.filter(v => !filters.length || filters.includes(v.name) || !filters.some(f => vpNames.includes(f)));

    if (!existsSync(OUT_DIR)) mkdirSync(OUT_DIR, { recursive: true });

    const browser = await chromium.launch({ headless: true });
    const results = [];
    try {
        for (const surface of surfaces) {
            for (const vp of vps) {
                console.log(`\n→ ${surface.name} @ ${vp.name} (${vp.width}×${vp.height})`);
                const ctx = await browser.newContext({ viewport: { width: vp.width, height: vp.height }, ignoreHTTPSErrors: true, deviceScaleFactor: 1 });
                const lp = await ctx.newPage(), rp = await ctx.newPage();
                await prepare(lp, `${LEGACY}${surface.path}`, surface);
                await prepare(rp, `${REBUILD}${surface.path}`, surface);

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
                    const fresh = await detectRegions(lp, rp, diff, lt, rt, { minPixels: NOISE_MIN_PIXELS });
                    const merged = mergeRegions(fresh, priorSectionRegions(priorWorklist, s.name));
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
                results.push({ surface: surface.name, viewport: vp.name, sections: sectionResults });
                await ctx.close();
            }
        }
        await fs.writeFile(path.join(OUT_DIR, 'analysis.json'), JSON.stringify(results, null, 2));
        await fs.writeFile(path.join(OUT_DIR, 'report.html'), reportHtml(results));
        console.log(`\nReport: file://${path.join(OUT_DIR, 'report.html')}`);
    } finally {
        await browser.close();
    }
}

function reportHtml(results) {
    const block = (r) => {
        const rows = r.sections.map(s => {
            if (s.missing) return `<div class="sec miss"><h3>${s.section} — ANCHOR NOT FOUND (legacy:${s.missing.legacy} rebuild:${s.missing.rebuild})</h3></div>`;
            const heavy = parseFloat(s.pct) > 0.5;
            return `<div class="sec">
              <h3>${s.section} — <span class="${heavy ? 'bad' : 'ok'}">${s.pct}% diff</span> <span class="dim">(legacy ${s.legacyH}px / rebuild ${s.rebuildH}px)</span></h3>
              <div class="trio">
                <figure><figcaption>legacy</figcaption><img src="${s.base}.legacy.png"></figure>
                <figure><figcaption>rebuild</figcaption><img src="${s.base}.rebuild.png"></figure>
                <figure><figcaption>diff (red = differs)</figcaption><img src="${s.base}.diff.png"></figure>
              </div>
            </div>`;
        }).join('\n');
        return `<section class="surface"><h2>${r.surface} @ ${r.viewport}</h2>${rows}</section>`;
    };
    return `<!doctype html><html><head><meta charset="utf-8"><title>Visual parity — reference vs rebuild</title>
<style>
  body{font-family:ui-sans-serif,system-ui,sans-serif;margin:0;background:#0a0a0a;color:#f5f5f5}
  header{padding:1rem 1.5rem;background:#18181b;border-bottom:1px solid #27272a;position:sticky;top:0}
  h1{margin:0;font-size:1.1rem} h2{font-size:1rem;color:#a1a1aa;margin:1.5rem 1.5rem .5rem}
  .surface{border-bottom:1px solid #27272a;padding-bottom:1rem}
  .sec{padding:.5rem 1.5rem 1rem} .sec h3{font-size:.9rem;font-weight:500;color:#d4d4d8;margin:.5rem 0}
  .ok{color:#22c55e;font-weight:700}.bad{color:#ef4444;font-weight:700}.dim{color:#71717a;font-size:.8rem}
  .trio{display:grid;grid-template-columns:1fr 1fr 1fr;gap:.75rem}
  figure{margin:0}figcaption{font-size:.7rem;color:#71717a;text-transform:uppercase;letter-spacing:.05em;padding-bottom:.25rem}
  img{width:100%;display:block;border:1px solid #27272a;background:#fff;cursor:zoom-in}
  img:fullscreen{width:auto;height:100vh;background:#fff}
  .miss h3{color:#f59e0b}
</style></head><body>
<header><h1>Visual parity — reference vs rebuild · green ≤0.5% · click image to zoom · % UNDERCOUNTS background-heavy bands → trust the diff image + heights</h1></header>
${results.map(block).join('\n')}
<script>document.querySelectorAll('img').forEach(i=>i.onclick=()=>document.fullscreenElement?document.exitFullscreen():i.requestFullscreen());</script>
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

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  run().catch(e => { console.error(e); process.exit(1); });
}
