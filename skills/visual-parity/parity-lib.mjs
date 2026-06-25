// Pure, dependency-free logic for the visual-parity worklist.
// Imports NOTHING external so its tests run without npm install.

import { promises as fs } from 'node:fs';

// pixelmatch paints changed pixels in the diff color (default red, full alpha);
// unchanged pixels are faded grayscale. A pixel is "changed" when it is strongly red.
export function maskFromDiff(data, width, height) {
  const mask = new Uint8Array(width * height);
  for (let p = 0; p < width * height; p++) {
    const r = data[p * 4], g = data[p * 4 + 1], b = data[p * 4 + 2];
    if (r > 200 && g < 80 && b < 80) mask[p] = 1;
  }
  return mask;
}

// 8-connected flood fill (iterative stack — no recursion, safe for big images).
export function clusterMask(mask, width, height, { minPixels = 12 } = {}) {
  const visited = new Uint8Array(width * height);
  const regions = [];
  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      const start = y * width + x;
      if (!mask[start] || visited[start]) continue;
      let minX = x, maxX = x, minY = y, maxY = y, count = 0;
      const stack = [start];
      visited[start] = 1;
      while (stack.length) {
        const p = stack.pop();
        const py = (p / width) | 0;
        const px = p - py * width;
        count++;
        if (px < minX) minX = px;
        if (px > maxX) maxX = px;
        if (py < minY) minY = py;
        if (py > maxY) maxY = py;
        for (let dy = -1; dy <= 1; dy++) {
          for (let dx = -1; dx <= 1; dx++) {
            if (dx === 0 && dy === 0) continue;
            const nx = px + dx, ny = py + dy;
            if (nx < 0 || ny < 0 || nx >= width || ny >= height) continue;
            const ni = ny * width + nx;
            if (mask[ni] && !visited[ni]) { visited[ni] = 1; stack.push(ni); }
          }
        }
      }
      if (count >= minPixels) {
        regions.push({ x: minX, y: minY, w: maxX - minX + 1, h: maxY - minY + 1, pixels: count });
      }
    }
  }
  return regions;
}

const COLOR_KEYS = ['backgroundColor', 'color', 'backgroundImage', 'backgroundSize', 'backgroundPosition', 'borderRadius', 'boxShadow'];
const FONT_KEYS = ['fontFamily', 'fontSize', 'fontWeight'];

function firstStyleDiff(a, b, keys) {
  for (const k of keys) {
    if (a[k] !== b[k]) return `${k} ${a[k]} → ${b[k]}`;
  }
  return null;
}

function describeBox(hit) {
  const b = hit.box;
  return `${hit.tag ?? 'el'} ${b.w}×${b.h} @ (${b.x},${b.y})`;
}

export function classifyKind(legacy, rebuild, { posTol = 2, sizeTol = 2 } = {}) {
  const lPresent = legacy && legacy.present;
  const rPresent = rebuild && rebuild.present;
  if (lPresent && !rPresent) return { kind: 'missing', detail: `legacy has ${describeBox(legacy)}; rebuild empty` };
  if (!lPresent && rPresent) return { kind: 'extra', detail: `rebuild has ${describeBox(rebuild)}; legacy empty` };
  if (!lPresent && !rPresent) return { kind: 'unclassified', detail: 'no element at point on either side' };

  const lb = legacy.box, rb = rebuild.box;
  const samePos = Math.abs(lb.x - rb.x) <= posTol && Math.abs(lb.y - rb.y) <= posTol;
  const sameSize = Math.abs(lb.w - rb.w) <= sizeTol && Math.abs(lb.h - rb.h) <= sizeTol;

  if (samePos && sameSize) {
    if (legacy.styles.zIndex !== rebuild.styles.zIndex) {
      return { kind: 'overlap', detail: `z-index ${legacy.styles.zIndex} → ${rebuild.styles.zIndex}` };
    }
    const colorDiff = firstStyleDiff(legacy.styles, rebuild.styles, COLOR_KEYS);
    if (colorDiff) return { kind: 'recolor', detail: colorDiff };
    const fontDiff = firstStyleDiff(legacy.styles, rebuild.styles, FONT_KEYS);
    if (fontDiff) return { kind: 'typography', detail: fontDiff };
    return { kind: 'unclassified', detail: 'box and tracked styles match — source unclear' };
  }
  if (sameSize) return { kind: 'shift', detail: `Δx ${rb.x - lb.x}px, Δy ${rb.y - lb.y}px` };
  if (samePos) return { kind: 'resize', detail: `Δw ${rb.w - lb.w}px, Δh ${rb.h - lb.h}px` };
  return { kind: 'unclassified', detail: `box ${describeBox(legacy)} → ${describeBox(rebuild)} (position and size both differ)` };
}

const boxObj = (a) => ({ x: a[0], y: a[1], w: a[2], h: a[3] });

export function iou(a, b) {
  const ix = Math.max(0, Math.min(a.x + a.w, b.x + b.w) - Math.max(a.x, b.x));
  const iy = Math.max(0, Math.min(a.y + a.h, b.y + b.h) - Math.max(a.y, b.y));
  const inter = ix * iy;
  const union = a.w * a.h + b.w * b.h - inter;
  return union === 0 ? 0 : inter / union;
}

export function mergeRegions(freshAuto, priorRegions, { iouThreshold = 0.5 } = {}) {
  const humans = priorRegions.filter(r => r.source === 'human');
  const out = [];
  for (const fa of freshAuto) {
    const covered = humans.some(h => iou(boxObj(h.box), boxObj(fa.box)) >= iouThreshold);
    if (!covered) out.push(fa);
  }
  for (const h of humans) out.push(h);
  return out;
}

export function countMaskInBoxes(mask, width, boxes) {
  let n = 0;
  for (const [bx, by, bw, bh] of boxes) {
    for (let y = by; y < by + bh; y++) {
      for (let x = bx; x < bx + bw; x++) {
        if (mask[y * width + x]) n++;
      }
    }
  }
  return n;
}

export function adjustedPct(totalChanged, totalPixels, changedInsideWontfix) {
  if (totalPixels === 0) return 0;
  const adj = Math.max(0, totalChanged - changedInsideWontfix);
  return +(adj / totalPixels * 100).toFixed(2);
}

export function worklistFilename(surface, viewport) {
  return `worklist.${surface}.${viewport}.json`;
}

export async function readWorklist(filePath) {
  try {
    return JSON.parse(await fs.readFile(filePath, 'utf8'));
  } catch {
    return null;
  }
}

export async function writeWorklist(filePath, data) {
  await fs.writeFile(filePath, JSON.stringify(data, null, 2));
}

export function priorSectionRegions(prior, sectionName) {
  const section = prior?.sections?.find(s => s.section === sectionName);
  return section?.regions ?? [];
}

// ── Fix-list serializer (reused in the browser report via .toString()) ───────
// worklistByKey: { "surface.viewport.section": Region[] }. Drops fixed items;
// human regions without a machine kind render as [you].
export function feedbackMarkdown(worklistByKey, { reportName } = {}) {
  const bySurfaceVp = {};
  for (const [k, regions] of Object.entries(worklistByKey || {})) {
    const dot = k.indexOf('.'), dot2 = k.indexOf('.', dot + 1);
    const surface = k.slice(0, dot), viewport = k.slice(dot + 1, dot2), section = k.slice(dot2 + 1);
    for (const r of regions || []) {
      if (r.status === 'fixed') continue;
      const head = `${surface} @ ${viewport}`;
      (bySurfaceVp[head] = bySurfaceVp[head] || []).push({ section, r });
    }
  }
  const lines = [`## Visual parity feedback${reportName ? ` — report: ${reportName}` : ''}`];
  for (const [head, items] of Object.entries(bySurfaceVp)) {
    lines.push('', `### ${head}`);
    // collapse identical machine differences (same section+kind+detail); humans stay individual
    const groups = new Map();
    for (const it of items) {
      const r = it.r;
      const sig = r.source === 'human' ? 'h:' + r.id : `${it.section}|${r.kind || '?'}|${r.detail || ''}`;
      if (!groups.has(sig)) groups.set(sig, []);
      groups.get(sig).push(it);
    }
    for (const members of groups.values()) {
      const { section, r } = members[0];
      const count = members.length;
      const cat = r.source === 'human' && !r.kind ? 'you' : (r.kind || 'unclassified');
      const where = count > 1
        ? (r.detail ? `${r.detail} ×${count}` : `${count} regions`)
        : (r.detail ? `${r.detail} @ (${r.box.join(',')})` : `@ (${r.box.join(',')})`);
      const note = r.note ? ` — note: "${r.note}"` : '';
      lines.push(`- [${cat}] ${section} — ${where}${note} — ${r.status || 'open'}`);
    }
  }
  return lines.join('\n');
}

// ── Per-surface auth resolution ──────────────────────────────────────────────
// surface.auth -> authProfiles[surface.auth]; no auth -> defaultState; unknown -> throw.
export function resolveStorageState(surface, authProfiles = {}, defaultState) {
  if (!surface.auth) return defaultState;
  if (!(surface.auth in authProfiles)) throw new Error(`unknown auth profile: ${surface.auth}`);
  return authProfiles[surface.auth];
}

// ── Named-report grouping ────────────────────────────────────────────────────
// Splits results by their `report` tag (default 'default'), preserving first-seen order.
export function groupResultsByReport(results) {
  const order = [], byName = {};
  for (const r of results) {
    const name = r.report || 'default';
    if (!(name in byName)) { byName[name] = []; order.push(name); }
    byName[name].push(r);
  }
  return order.map(name => ({ name, results: byName[name] }));
}

export function reportFilename(groupName) {
  return groupName === 'default' ? 'report.html' : `report.${groupName}.html`;
}

// ── Config normalization (defaults mirror the in-file CONFIG constants) ───────
const DEFAULT_VIEWPORTS = [
  { name: 'desktop', width: 1920, height: 1080 },
  { name: 'mobile',  width: 390,  height: 844 },
];
export function normalizeConfig(raw) {
  if (!raw?.legacy || !raw?.rebuild || !Array.isArray(raw?.surfaces)) {
    throw new Error('config must define legacy, rebuild, and surfaces');
  }
  return {
    legacy: raw.legacy, rebuild: raw.rebuild,
    viewports: raw.viewports ?? DEFAULT_VIEWPORTS,
    surfaces: raw.surfaces,
    authProfiles: raw.authProfiles ?? {},
    defaultStorageState: raw.defaultStorageState,
    noiseMinPixels: raw.noiseMinPixels ?? 12,
    threshold: raw.threshold ?? 0.1,
  };
}
