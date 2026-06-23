# Visual Parity v2 — Categorized Worklist Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an agent-facing, categorized `worklist.json` to the visual-parity harness — located diff regions classified by *kind* with exact numbers — and a two-way served report where a human can annotate those regions, all merging into one shared file.

**Architecture:** Extract the pure algorithmic logic (diff-mask clustering, kind classification, IoU merge, metrics) into a dependency-free `parity-lib.mjs` unit-tested with Node's built-in test runner. The existing `parity-harness.mjs` keeps its band/anchor/diff pipeline unchanged and gains: a per-band auto-detection step (cluster → Playwright hit-test on both renders → classify) that seeds `out/worklist.<surface>.<viewport>.json`, and a `--serve` mode (Node `http`) that serves an interactive report whose box edits `POST /worklist` back to disk.

**Tech Stack:** Node ESM (`.mjs`), Playwright, pixelmatch, pngjs (all already used); `node:test` + `node:assert` for unit tests; `node:http` for serve mode. No new dependencies.

## Global Constraints

- **No new runtime dependencies.** Clustering/classification/merge are pure; serve mode uses `node:http`.
- **`parity-lib.mjs` MUST stay dependency-free** — it imports nothing external (no playwright/pixelmatch/pngjs), so its tests run in the skill repo with no `npm install`.
- **Additive only.** Do not change `prepare()`, `anchorTops()`, band cutting, `diffPngs()`, cookie dismissal, lazy-image scroll, or dynamic-region masking. v2 only *adds* steps after the existing per-band diff.
- **`unclassified` is mandatory.** The classifier never emits a `kind` label it cannot back with numbers; ambiguous cases return `{ kind: 'unclassified', detail }`.
- **Coordinates:** `deviceScaleFactor: 1` (already set). Region boxes are stored in **band-local image coordinates** (matching the diff PNG, so the report overlay places them directly); the worklist file also records each section's `legacyTop` / `rebuildTop` so document coordinates are recoverable per side.
- **Worklist file:** one file per surface+viewport — `out/worklist.<surface>.<viewport>.json` — containing all that surface's sections.
- **Human touch wins:** the moment a human edits a region (kind/note/status), it becomes `source: "human"` and is preserved across re-runs by IoU match; fresh auto-regions regenerate each run.
- **Commits:** no `Co-Authored-By` line, no AI-attribution text (repo rule).
- **Files live in** `skills/visual-parity/`. Run unit tests from the repo root with `node --test skills/visual-parity/`.

---

## File Structure

```
skills/visual-parity/
  parity-lib.mjs            ← NEW: pure logic (mask, cluster, classify, iou, merge, metrics, worklist I/O). Dependency-free.
  parity-lib.test.mjs       ← NEW: node:test unit suite for parity-lib.
  parity-harness.mjs        ← MODIFY: + captureHit (Playwright), auto-detection wiring in run(), --serve, interactive report overlay.
  fixtures/
    ref.html                ← NEW: reference fixture page with planted differences.
    new.html                ← NEW: rebuild fixture page (recolor / shift / resize / missing planted).
  integration.test.mjs      ← NEW: Playwright-backed test for captureHit against fixtures (Part A, Task 5).
  SKILL.md                  ← MODIFY: worklist-is-the-gate loop, CONFIG additions, serve usage (Task 10).
```

`parity-lib.mjs` is the unit-tested core. `parity-harness.mjs` is the integration shell (Playwright/server/CLI). Splitting keeps each file focused and lets the algorithmic core be tested without a browser.

---

# PART A — Headless worklist (agent-facing core)

## Task 1: Diff-mask → clustered regions

**Files:**
- Create: `skills/visual-parity/parity-lib.mjs`
- Test: `skills/visual-parity/parity-lib.test.mjs`

**Interfaces:**
- Produces:
  - `maskFromDiff(data: Uint8Array|Buffer, width: number, height: number): Uint8Array` — `1` where the pixelmatch diff buffer marked a changed (red) pixel, else `0`.
  - `clusterMask(mask: Uint8Array, width: number, height: number, opts?: { minPixels?: number }): Region[]` where `Region = { x, y, w, h, pixels }` (band-local coords, `pixels` = changed-pixel count). Default `minPixels = 12`.

- [ ] **Step 1: Write the failing test**

```javascript
// skills/visual-parity/parity-lib.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { maskFromDiff, clusterMask } from './parity-lib.mjs';

// Helper: build an RGBA buffer, paint a filled red rect (pixelmatch diff color).
function rgbaWithRedRect(width, height, rects) {
  const data = new Uint8Array(width * height * 4);
  for (let i = 0; i < width * height; i++) { data[i*4+3] = 255; } // opaque, black
  for (const { x, y, w, h } of rects) {
    for (let yy = y; yy < y + h; yy++) {
      for (let xx = x; xx < x + w; xx++) {
        const p = (yy * width + xx) * 4;
        data[p] = 255; data[p+1] = 0; data[p+2] = 0; data[p+3] = 255;
      }
    }
  }
  return data;
}

test('maskFromDiff marks red pixels only', () => {
  const data = rgbaWithRedRect(4, 1, [{ x: 1, y: 0, w: 2, h: 1 }]);
  const mask = maskFromDiff(data, 4, 1);
  assert.deepEqual([...mask], [0, 1, 1, 0]);
});

test('clusterMask finds two separated blobs with correct boxes', () => {
  const data = rgbaWithRedRect(20, 20, [
    { x: 2, y: 2, w: 4, h: 4 },
    { x: 14, y: 14, w: 3, h: 3 },
  ]);
  const mask = maskFromDiff(data, 20, 20);
  const regions = clusterMask(mask, 20, 20, { minPixels: 4 });
  assert.equal(regions.length, 2);
  const sorted = regions.sort((a, b) => a.x - b.x);
  assert.deepEqual({ x: sorted[0].x, y: sorted[0].y, w: sorted[0].w, h: sorted[0].h }, { x: 2, y: 2, w: 4, h: 4 });
  assert.deepEqual({ x: sorted[1].x, y: sorted[1].y, w: sorted[1].w, h: sorted[1].h }, { x: 14, y: 14, w: 3, h: 3 });
});

test('clusterMask drops sub-threshold speckle', () => {
  const data = rgbaWithRedRect(20, 20, [{ x: 5, y: 5, w: 1, h: 1 }]);
  const mask = maskFromDiff(data, 20, 20);
  assert.equal(clusterMask(mask, 20, 20, { minPixels: 4 }).length, 0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `node --test skills/visual-parity/parity-lib.test.mjs`
Expected: FAIL — `Cannot find module './parity-lib.mjs'` / exports undefined.

- [ ] **Step 3: Write minimal implementation**

```javascript
// skills/visual-parity/parity-lib.mjs
// Pure, dependency-free logic for the visual-parity worklist.
// Imports NOTHING external so its tests run without npm install.

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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `node --test skills/visual-parity/parity-lib.test.mjs`
Expected: PASS — 3 tests.

- [ ] **Step 5: Commit**

```bash
git add skills/visual-parity/parity-lib.mjs skills/visual-parity/parity-lib.test.mjs
git commit -m "feat(visual-parity): diff-mask clustering into located regions"
```

---

## Task 2: Kind classification

**Files:**
- Modify: `skills/visual-parity/parity-lib.mjs`
- Test: `skills/visual-parity/parity-lib.test.mjs`

**Interfaces:**
- Consumes: `Region` from Task 1 (not directly — operates on hit objects).
- Produces:
  - `Hit = { present: boolean, tag?: string, box?: {x,y,w,h}, styles?: StyleSlice }`
  - `StyleSlice` keys: `color, backgroundColor, backgroundImage, backgroundSize, backgroundPosition, borderRadius, boxShadow, fontFamily, fontSize, fontWeight, zIndex` (all strings).
  - `classifyKind(legacy: Hit, rebuild: Hit, opts?: { posTol?: number, sizeTol?: number }): { kind: string, detail: string }`. `kind ∈ {recolor, shift, resize, missing, extra, typography, overlap, unclassified}`. Defaults `posTol = 2`, `sizeTol = 2`.

- [ ] **Step 1: Write the failing test**

```javascript
// append to skills/visual-parity/parity-lib.test.mjs
import { classifyKind } from './parity-lib.mjs';

const baseStyles = {
  color: 'rgb(0, 0, 0)', backgroundColor: 'rgb(255, 106, 0)', backgroundImage: 'none',
  backgroundSize: 'auto', backgroundPosition: '0% 0%', borderRadius: '0px', boxShadow: 'none',
  fontFamily: 'Inter', fontSize: '16px', fontWeight: '400', zIndex: 'auto',
};
const hit = (box, styles = {}) => ({ present: true, tag: 'div', box, styles: { ...baseStyles, ...styles } });
const box = (x, y, w, h) => ({ x, y, w, h });

test('classifyKind: recolor when boxes match but background differs', () => {
  const l = hit(box(10, 10, 100, 40));
  const r = hit(box(10, 10, 100, 40), { backgroundColor: 'rgb(255, 140, 0)' });
  const out = classifyKind(l, r);
  assert.equal(out.kind, 'recolor');
  assert.match(out.detail, /backgroundColor/);
});

test('classifyKind: shift when same size, moved origin', () => {
  const out = classifyKind(hit(box(10, 10, 100, 40)), hit(box(10, 34, 100, 40)));
  assert.equal(out.kind, 'shift');
  assert.match(out.detail, /Δy 24px/);
});

test('classifyKind: resize when same origin, different size', () => {
  const out = classifyKind(hit(box(10, 10, 100, 40)), hit(box(10, 10, 100, 20)));
  assert.equal(out.kind, 'resize');
  assert.match(out.detail, /Δh -20px/);
});

test('classifyKind: missing when legacy present and rebuild absent', () => {
  assert.equal(classifyKind(hit(box(0, 0, 8, 8)), { present: false }).kind, 'missing');
});

test('classifyKind: extra when rebuild present and legacy absent', () => {
  assert.equal(classifyKind({ present: false }, hit(box(0, 0, 8, 8))).kind, 'extra');
});

test('classifyKind: typography when boxes match and font differs', () => {
  const out = classifyKind(hit(box(0, 0, 50, 20)), hit(box(0, 0, 50, 20), { fontWeight: '700' }));
  assert.equal(out.kind, 'typography');
});

test('classifyKind: overlap when z-index differs at same box', () => {
  const out = classifyKind(hit(box(0, 0, 50, 20), { zIndex: '1' }), hit(box(0, 0, 50, 20), { zIndex: '5' }));
  assert.equal(out.kind, 'overlap');
});

test('classifyKind: unclassified when boxes and styles all match', () => {
  assert.equal(classifyKind(hit(box(0, 0, 50, 20)), hit(box(0, 0, 50, 20))).kind, 'unclassified');
});

test('classifyKind: unclassified when both absent', () => {
  assert.equal(classifyKind({ present: false }, { present: false }).kind, 'unclassified');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `node --test skills/visual-parity/parity-lib.test.mjs`
Expected: FAIL — `classifyKind is not a function`.

- [ ] **Step 3: Write minimal implementation**

```javascript
// append to skills/visual-parity/parity-lib.mjs

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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `node --test skills/visual-parity/parity-lib.test.mjs`
Expected: PASS — all Task 1 + Task 2 tests green.

- [ ] **Step 5: Commit**

```bash
git add skills/visual-parity/parity-lib.mjs skills/visual-parity/parity-lib.test.mjs
git commit -m "feat(visual-parity): classify diff regions by kind"
```

---

## Task 3: IoU, preserve-merge, and metrics

**Files:**
- Modify: `skills/visual-parity/parity-lib.mjs`
- Test: `skills/visual-parity/parity-lib.test.mjs`

**Interfaces:**
- Produces:
  - `WorklistRegion = { id, box: [x,y,w,h], source: 'auto'|'human', kind, detail?, note?, status: 'open'|'wontfix'|'fixed' }`
  - `iou(a: {x,y,w,h}, b: {x,y,w,h}): number`
  - `mergeRegions(freshAuto: WorklistRegion[], priorRegions: WorklistRegion[], opts?: { iouThreshold?: number }): WorklistRegion[]` — keeps every `source:'human'` prior region; emits each fresh auto region unless a human region already covers that spot (IoU ≥ threshold). Default `iouThreshold = 0.5`.
  - `countMaskInBoxes(mask: Uint8Array, width: number, boxes: [x,y,w,h][]): number` — count of set mask pixels inside any box.
  - `adjustedPct(totalChanged: number, totalPixels: number, changedInsideWontfix: number): number` — `(totalChanged − changedInsideWontfix) / totalPixels × 100`, rounded to 2dp, floored at 0.

- [ ] **Step 1: Write the failing test**

```javascript
// append to skills/visual-parity/parity-lib.test.mjs
import { iou, mergeRegions, countMaskInBoxes, adjustedPct } from './parity-lib.mjs';

const region = (id, box, over = {}) => ({ id, box, source: 'auto', kind: 'recolor', status: 'open', ...over });

test('iou: identical boxes = 1, disjoint = 0', () => {
  assert.equal(iou({ x: 0, y: 0, w: 10, h: 10 }, { x: 0, y: 0, w: 10, h: 10 }), 1);
  assert.equal(iou({ x: 0, y: 0, w: 10, h: 10 }, { x: 100, y: 100, w: 10, h: 10 }), 0);
});

test('mergeRegions: human wontfix over an auto spot drops the fresh auto, keeps human', () => {
  const fresh = [region('a1', [10, 10, 100, 40])];
  const prior = [region('h1', [11, 11, 99, 39], { source: 'human', status: 'wontfix' })];
  const merged = mergeRegions(fresh, prior);
  assert.equal(merged.length, 1);
  assert.equal(merged[0].id, 'h1');
  assert.equal(merged[0].status, 'wontfix');
});

test('mergeRegions: human region with no overlap is kept alongside fresh auto', () => {
  const fresh = [region('a1', [10, 10, 50, 50])];
  const prior = [region('h1', [400, 400, 20, 20], { source: 'human', status: 'open', kind: 'missing' })];
  const merged = mergeRegions(fresh, prior);
  assert.equal(merged.length, 2);
  assert.ok(merged.some(r => r.id === 'a1' && r.source === 'auto'));
  assert.ok(merged.some(r => r.id === 'h1' && r.source === 'human'));
});

test('mergeRegions: fresh auto with no prior is included as open', () => {
  const merged = mergeRegions([region('a1', [0, 0, 10, 10])], []);
  assert.equal(merged.length, 1);
  assert.equal(merged[0].status, 'open');
});

test('countMaskInBoxes / adjustedPct: wontfix pixels are excluded', () => {
  // 10x10 mask, all set = 100 changed pixels.
  const mask = new Uint8Array(100).fill(1);
  const inWontfix = countMaskInBoxes(mask, 10, [[0, 0, 5, 10]]); // left half = 50
  assert.equal(inWontfix, 50);
  assert.equal(adjustedPct(100, 100, inWontfix), 50);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `node --test skills/visual-parity/parity-lib.test.mjs`
Expected: FAIL — `iou is not a function`.

- [ ] **Step 3: Write minimal implementation**

```javascript
// append to skills/visual-parity/parity-lib.mjs

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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `node --test skills/visual-parity/parity-lib.test.mjs`
Expected: PASS — all unit tests green.

- [ ] **Step 5: Commit**

```bash
git add skills/visual-parity/parity-lib.mjs skills/visual-parity/parity-lib.test.mjs
git commit -m "feat(visual-parity): IoU preserve-merge and wontfix-adjusted metric"
```

---

## Task 4: Worklist file schema + read/write

**Files:**
- Modify: `skills/visual-parity/parity-lib.mjs`
- Test: `skills/visual-parity/parity-lib.test.mjs`

**Interfaces:**
- Produces:
  - `worklistFilename(surface: string, viewport: string): string` → `worklist.<surface>.<viewport>.json`
  - `readWorklist(filePath: string): Promise<Worklist|null>` — `null` if absent or unparseable.
  - `writeWorklist(filePath: string, data: Worklist): Promise<void>` — pretty JSON.
  - `Worklist = { surface, viewport, sections: Section[] }`
  - `Section = { section, legacyTop, rebuildTop, pixelPct, adjustedPct, openCount, regions: WorklistRegion[] }`
  - `priorSectionRegions(prior: Worklist|null, sectionName: string): WorklistRegion[]` — the prior file's regions for a named section, or `[]`.

Note: `parity-lib.mjs` may import `node:fs` and `node:path` — these are built-ins, not external deps, so the dependency-free constraint holds.

- [ ] **Step 1: Write the failing test**

```javascript
// append to skills/visual-parity/parity-lib.test.mjs
import { worklistFilename, readWorklist, writeWorklist, priorSectionRegions } from './parity-lib.mjs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { mkdtemp, rm } from 'node:fs/promises';

test('worklistFilename composes surface and viewport', () => {
  assert.equal(worklistFilename('home', 'desktop'), 'worklist.home.desktop.json');
});

test('readWorklist returns null for a missing file', async () => {
  assert.equal(await readWorklist('/no/such/file.json'), null);
});

test('writeWorklist then readWorklist round-trips; priorSectionRegions filters by section', async () => {
  const dir = await mkdtemp(join(tmpdir(), 'vp-'));
  try {
    const file = join(dir, worklistFilename('home', 'desktop'));
    const data = {
      surface: 'home', viewport: 'desktop',
      sections: [{
        section: 'hero', legacyTop: 100, rebuildTop: 100, pixelPct: 4.2, adjustedPct: 4.2, openCount: 1,
        regions: [{ id: 'a1', box: [1, 2, 3, 4], source: 'auto', kind: 'recolor', status: 'open' }],
      }],
    };
    await writeWorklist(file, data);
    const back = await readWorklist(file);
    assert.deepEqual(back, data);
    assert.equal(priorSectionRegions(back, 'hero').length, 1);
    assert.equal(priorSectionRegions(back, 'nope').length, 0);
  } finally {
    await rm(dir, { recursive: true, force: true });
  }
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `node --test skills/visual-parity/parity-lib.test.mjs`
Expected: FAIL — `worklistFilename is not a function`.

- [ ] **Step 3: Write minimal implementation**

```javascript
// append to skills/visual-parity/parity-lib.mjs
import { promises as fs } from 'node:fs';

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
```

(Put the `import` line at the TOP of the file with any other imports; shown here inline for context.)

- [ ] **Step 4: Run test to verify it passes**

Run: `node --test skills/visual-parity/parity-lib.test.mjs`
Expected: PASS — all unit tests green.

- [ ] **Step 5: Commit**

```bash
git add skills/visual-parity/parity-lib.mjs skills/visual-parity/parity-lib.test.mjs
git commit -m "feat(visual-parity): worklist file schema and read/write"
```

---

## Task 5: Playwright hit-test capture + fixtures

**Files:**
- Create: `skills/visual-parity/fixtures/ref.html`
- Create: `skills/visual-parity/fixtures/new.html`
- Create: `skills/visual-parity/integration.test.mjs`
- Modify: `skills/visual-parity/parity-harness.mjs` (add and export `captureHit`)

**Interfaces:**
- Produces: `captureHit(page: import('playwright').Page, docX: number, docY: number): Promise<Hit>` — scrolls the document point into view, runs `elementFromPoint`, returns the `Hit` shape from Task 2 (`{ present, tag, box, styles }`) with `box`/`styles` in document coordinates.

This task needs the harness's runtime deps. Install once in the skill repo:
`cd skills/visual-parity && npm init -y && npm i -D playwright pixelmatch pngjs && npx playwright install chromium`
(That creates a local `package.json`/`node_modules` for the skill's own tests; it is git-ignored — see Step 5.)

- [ ] **Step 1: Write the failing test**

```html
<!-- skills/visual-parity/fixtures/ref.html -->
<!doctype html><meta charset="utf-8"><title>ref</title>
<style>body{margin:0;font:16px Inter,sans-serif}
.cta{position:absolute;left:40px;top:200px;width:160px;height:48px;background:#ff6a00}
.dot{position:absolute;left:40px;top:300px;width:12px;height:12px;border-radius:50%;background:#000}
</style>
<div class="cta">Buy</div>
<div class="dot"></div>
<div style="height:1200px"></div>
```

```html
<!-- skills/visual-parity/fixtures/new.html -->
<!doctype html><meta charset="utf-8"><title>new</title>
<style>body{margin:0;font:16px Inter,sans-serif}
.cta{position:absolute;left:40px;top:200px;width:160px;height:48px;background:#ff8c00} /* recolor */
/* .dot intentionally removed -> missing */
</style>
<div class="cta">Buy</div>
<div style="height:1200px"></div>
```

```javascript
// skills/visual-parity/integration.test.mjs
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { pathToFileURL } from 'node:url';
import { join } from 'node:path';
import { captureHit } from './parity-harness.mjs';

let browser, page;
before(async () => {
  browser = await chromium.launch();
  page = await browser.newPage();
});
after(async () => { await browser.close(); });

test('captureHit reads the CTA box and background on the reference', async () => {
  await page.goto(pathToFileURL(join(process.cwd(), 'fixtures/ref.html')).href);
  const hit = await captureHit(page, 120, 224); // centre of the CTA
  assert.equal(hit.present, true);
  assert.deepEqual(hit.box, { x: 40, y: 200, w: 160, h: 48 });
  assert.equal(hit.styles.backgroundColor, 'rgb(255, 106, 0)');
});

test('captureHit reports absent where the dot was removed in the rebuild', async () => {
  await page.goto(pathToFileURL(join(process.cwd(), 'fixtures/new.html')).href);
  const hit = await captureHit(page, 46, 306); // where the dot used to be
  // body is still hit, but not a 12x12 dot — assert it is NOT the dot box.
  assert.notDeepEqual(hit.box, { x: 40, y: 300, w: 12, h: 12 });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd skills/visual-parity && node --test integration.test.mjs`
Expected: FAIL — `captureHit` is not exported by `parity-harness.mjs`.

- [ ] **Step 3: Write minimal implementation**

Add to `skills/visual-parity/parity-harness.mjs` (export it so the test can import; the existing `run()` call at the bottom stays):

```javascript
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd skills/visual-parity && node --test integration.test.mjs`
Expected: PASS — 2 tests.

- [ ] **Step 5: Commit**

```bash
# keep the skill repo clean of installed deps
printf 'node_modules/\npackage-lock.json\nout/\n' > skills/visual-parity/.gitignore
git add skills/visual-parity/.gitignore skills/visual-parity/fixtures/ref.html skills/visual-parity/fixtures/new.html \
        skills/visual-parity/integration.test.mjs skills/visual-parity/parity-harness.mjs
git commit -m "feat(visual-parity): Playwright hit-test capture with fixtures"
```

---

## Task 6: Wire auto-detection into `run()` and emit worklists

**Files:**
- Modify: `skills/visual-parity/parity-harness.mjs`
- Test: extend `skills/visual-parity/integration.test.mjs`

**Interfaces:**
- Consumes: `maskFromDiff`, `clusterMask`, `classifyKind`, `mergeRegions`, `countMaskInBoxes`, `adjustedPct`, `worklistFilename`, `readWorklist`, `writeWorklist`, `priorSectionRegions` (Tasks 1–4), `captureHit` (Task 5).
- Produces:
  - `detectRegions(lp, rp, diff, legacyTop, rebuildTop, opts): Promise<WorklistRegion[]>` — for one section: mask the **already-computed** band `diff` PNG (so the mask matches the report image exactly), cluster, hit-test both renders at each region centre (legacy at `legacyTop + region.cy`, rebuild at `rebuildTop + region.cy`, same `x`), classify, return `WorklistRegion[]` with band-local boxes, `source:'auto'`, `status:'open'`.
  - `out/worklist.<surface>.<viewport>.json` written each run, preserve-merged with any prior file.

- [ ] **Step 1: Write the failing test**

```javascript
// append to skills/visual-parity/integration.test.mjs
import { detectRegions } from './parity-harness.mjs';
import { PNG } from 'pngjs';

test('detectRegions classifies a recolored CTA as recolor', async () => {
  const ref = await browser.newPage();   // default viewport 1280×720
  const neu = await browser.newPage();
  await ref.goto(pathToFileURL(join(process.cwd(), 'fixtures/ref.html')).href);
  await neu.goto(pathToFileURL(join(process.cwd(), 'fixtures/new.html')).href);
  // Synthesize a diff PNG for a band at doc top=190, height=70.
  // CTA sits at doc y 200..248 → band-local y 10..58, x 40..200.
  const W = 1280, H = 70;
  const diff = new PNG({ width: W, height: H });
  for (let i = 0; i < W * H; i++) diff.data[i * 4 + 3] = 255;
  for (let y = 10; y < 58; y++) for (let x = 40; x < 200; x++) {
    const p = (y * W + x) * 4; diff.data[p] = 255; diff.data[p + 1] = 0; diff.data[p + 2] = 0; diff.data[p + 3] = 255;
  }
  const regions = await detectRegions(ref, neu, diff, 190, 190, { minPixels: 50 });
  await ref.close(); await neu.close();
  assert.ok(regions.length >= 1);
  assert.ok(regions.some(r => r.kind === 'recolor'));
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd skills/visual-parity && node --test integration.test.mjs`
Expected: FAIL — `detectRegions` is not exported.

- [ ] **Step 3: Write minimal implementation**

Add the imports at the top of `parity-harness.mjs`:

```javascript
import {
  maskFromDiff, clusterMask, classifyKind, mergeRegions,
  countMaskInBoxes, adjustedPct, worklistFilename, readWorklist, writeWorklist, priorSectionRegions,
} from './parity-lib.mjs';
```

Add `detectRegions`. It masks the **existing** band `diff` PNG (the same pixels the report shows), clusters, hit-tests both pages at each region centre, classifies:

```javascript
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
```

Then, inside `run()`'s per-section loop, add auto-detection and **enrich the existing `sectionResults` entry** (no parallel array — the report already iterates `sectionResults`). The existing loop destructures `const { diff, numDiff, total } = diffPngs(lBand, rBand)`, so `diff`/`numDiff`/`total` are in scope. Replace the existing non-missing `sectionResults.push({...})` with the enriched push below, then write the worklist after the section loop:

```javascript
// --- inside run(), before the section loop, per (surface, vp): ---
const priorWorklist = await readWorklist(path.join(OUT_DIR, worklistFilename(surface.name, vp.name)));

// --- inside the section loop, REPLACING the existing non-missing sectionResults.push(...): ---
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

// --- after the section loop, per (surface, vp): ---
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
```

The enriched `sectionResults` flows into `reportHtml(results)` unchanged (via the existing `results.push({ surface, viewport, sections: sectionResults })`), so Task 7 reads `s.regions`, `s.diffW/diffH`, `s.openCount`, `s.adjustedPct` straight off each section.

Add a CONFIG constant near the other CONFIG values:

```javascript
const NOISE_MIN_PIXELS = 12;   // diff blobs smaller than this (changed-pixel count) are ignored
```

(`diff` here is the per-section diff `PNG` already produced by `diffPngs`; expose it from `diffPngs`'s return — it already returns `{ diff, numDiff, total }`, so capture `diff` in the loop where `numDiff`/`total` are destructured.)

- [ ] **Step 4: Run test to verify it passes**

Run: `cd skills/visual-parity && node --test integration.test.mjs`
Expected: PASS — recolor region detected.

Then run the full harness against fixtures to confirm a worklist file is written. Temporarily point CONFIG `LEGACY`/`REBUILD` at the fixture files via a one-off check:

Run: `cd skills/visual-parity && node -e "import('./parity-lib.mjs').then(m=>console.log(m.worklistFilename('home','desktop')))"`
Expected: prints `worklist.home.desktop.json` (sanity that lib is importable from the harness dir).

- [ ] **Step 5: Commit**

```bash
git add skills/visual-parity/parity-harness.mjs skills/visual-parity/integration.test.mjs
git commit -m "feat(visual-parity): auto-detect and emit categorized worklist per run"
```

**>>> CHECKPOINT — Part A complete.** `node parity-harness.mjs` now writes `out/worklist.<surface>.<viewport>.json` with located, kind-classified regions, preserve-merged across runs. The agent can work this file with no UI. Part B adds the human annotation loop on top.

---

# PART B — Two-way served report (human annotation)

## Task 7: Render auto-regions on the report's diff images

**Files:**
- Modify: `skills/visual-parity/parity-harness.mjs` (`reportHtml` + per-section metric line)

**Interfaces:**
- Consumes: the `worklist.*.json` data computed in Task 6 (pass `worklistSections` into `reportHtml` alongside `results`).
- Produces: report bands that overlay each region as a positioned box on the diff image, scaled from band-local image pixels to displayed pixels, plus a header line showing `pixel% · adjusted% · N open`.

- [ ] **Step 1: Confirm the payload and build the overlay key-map**

Task 6 already enriched each `sectionResults` entry with `regions`, `legacyTop`/`rebuildTop`, `diffW`/`diffH`, `adjustedPct`, and `openCount`, so no payload change is needed here. In `reportHtml`, build a `worklistByKey` object mapping `"surface.viewport.section" → regions[]` from `results` (used by the interactive script in Task 8):

```javascript
function worklistByKey(results) {
  const map = {};
  for (const r of results) for (const s of r.sections) {
    if (s.missing) continue;
    map[`${r.surface}.${r.viewport}.${s.section}`] = s.regions ?? [];
  }
  return map;
}
```

- [ ] **Step 2: Overlay boxes in `reportHtml`**

Replace the diff `<figure>` in `reportHtml`'s `rows` builder with a positioned wrapper that draws boxes. The box coordinates are band-local image pixels; the displayed image is `width:100%`, so scale by `displayedWidth / naturalWidth` via CSS percentages:

```javascript
const diffFig = (s) => `
  <figure class="diffwrap" data-section="${s.section}" data-vp="${r.viewport}" data-surface="${r.surface}">
    <figcaption>diff (red = differs) · ${s.openCount ?? 0} open</figcaption>
    <div class="canvas">
      <img src="${s.base}.diff.png">
      <svg class="overlay" preserveAspectRatio="none" viewBox="0 0 ${s.diffW ?? 1} ${s.diffH ?? 1}">
        ${(s.regions ?? []).map(rg => boxSvg(rg)).join('')}
      </svg>
    </div>
  </figure>`;

const boxSvg = (rg) => {
  const [x, y, w, h] = rg.box;
  const stroke = rg.source === 'human' ? '#3b82f6' : '#f59e0b';
  const dash = rg.status === 'wontfix' ? 'stroke-dasharray="6 4" opacity="0.5"' : '';
  return `<rect class="rgn" data-id="${rg.id}" x="${x}" y="${y}" width="${w}" height="${h}"
    fill="transparent" stroke="${stroke}" stroke-width="2" ${dash}></rect>
    <text x="${x + 2}" y="${y + 12}" fill="${stroke}" font-size="11">${rg.kind}</text>`;
};
```

Where `s.diffW`/`s.diffH` are the diff PNG natural dimensions — capture them in `run()` from the `diff` PNG (`diff.width`, `diff.height`) and include in the section result. Use `viewBox` so SVG coordinates are the band-local pixels and `preserveAspectRatio="none"` makes the overlay stretch with the `width:100%` image.

Add CSS to the report `<style>`:

```css
.canvas{position:relative;display:inline-block;width:100%}
.canvas img{width:100%;display:block}
.overlay{position:absolute;inset:0;width:100%;height:100%;pointer-events:none}
.overlay .rgn{pointer-events:all;cursor:pointer}
```

Also update the section `<h3>` (Component 4 metric line) to show the adjusted % and open count next to the existing pixel %:

```javascript
// in reportHtml's rows builder, replace the existing <h3> with:
`<h3>${s.section} — <span class="${heavy ? 'bad' : 'ok'}">${s.pct}% diff</span>
  <span class="dim">· adj ${s.adjustedPct}% · ${s.openCount} open · (legacy ${s.legacyH}px / rebuild ${s.rebuildH}px)</span></h3>`
```

- [ ] **Step 3: Verify in the browser**

Run the harness against the fixtures, open the report, confirm the recolored CTA shows an amber box labelled `recolor` and (after a manual human mark later) styling differs. This is a manual visual check — use the `browser-verification` skill to capture annotated proof that boxes land on the right pixels.

- [ ] **Step 4: Commit**

```bash
git add skills/visual-parity/parity-harness.mjs
git commit -m "feat(visual-parity): overlay categorized regions on report diff images"
```

---

## Task 8: Interactive editing — click, edit, drag-to-add

**Files:**
- Modify: `skills/visual-parity/parity-harness.mjs` (`reportHtml` `<script>`)

**Interfaces:**
- Produces: in-browser editing that mutates an in-memory worklist and is ready to POST (Task 9). Click a box → edit `kind`/`note`/`status` or delete; drag on a diff canvas → add a `source:'human'` box; any edit flips `source` to `'human'`.

- [ ] **Step 1: Replace the report `<script>` with the editor**

```javascript
const reportScript = (worklistByKey) => `
<script>
const WL = ${JSON.stringify(worklistByKey)}; // key: surface.viewport.section -> regions[]
const KINDS = ['recolor','shift','resize','missing','extra','typography','overlap','ignore','other','unclassified'];
const key = (el) => el.dataset.surface + '.' + el.dataset.vp + '.' + el.dataset.section;

function redraw(wrap) {
  const k = key(wrap), svg = wrap.querySelector('.overlay');
  svg.querySelectorAll('.rgn,text').forEach(n => n.remove());
  for (const rg of WL[k] || []) {
    const [x,y,w,h] = rg.box;
    const stroke = rg.source === 'human' ? '#3b82f6' : '#f59e0b';
    const rect = document.createElementNS('http://www.w3.org/2000/svg','rect');
    rect.setAttribute('class','rgn'); rect.dataset.id = rg.id;
    rect.setAttribute('x',x); rect.setAttribute('y',y); rect.setAttribute('width',w); rect.setAttribute('height',h);
    rect.setAttribute('fill','transparent'); rect.setAttribute('stroke',stroke); rect.setAttribute('stroke-width','2');
    if (rg.status === 'wontfix') { rect.setAttribute('stroke-dasharray','6 4'); rect.setAttribute('opacity','0.5'); }
    rect.onclick = (e) => { e.stopPropagation(); editRegion(k, rg.id, wrap); };
    svg.appendChild(rect);
    const t = document.createElementNS('http://www.w3.org/2000/svg','text');
    t.setAttribute('x',x+2); t.setAttribute('y',y+12); t.setAttribute('fill',stroke); t.setAttribute('font-size','11');
    t.textContent = rg.kind; svg.appendChild(t);
  }
}

function editRegion(k, id, wrap) {
  const rg = (WL[k]||[]).find(r => r.id === id); if (!rg) return;
  const kind = prompt('kind (' + KINDS.join('/') + ') — blank to DELETE:', rg.kind);
  if (kind === null) return;
  if (kind === '') { WL[k] = WL[k].filter(r => r.id !== id); redraw(wrap); return; }
  rg.kind = kind;
  rg.note = prompt('note:', rg.note || '') || '';
  rg.status = prompt('status (open/wontfix/fixed):', rg.status) || rg.status;
  rg.source = 'human';
  redraw(wrap);
}

function svgPoint(svg, evt) {
  const vb = svg.viewBox.baseVal, rect = svg.getBoundingClientRect();
  return { x: Math.round((evt.clientX-rect.left)/rect.width*vb.width), y: Math.round((evt.clientY-rect.top)/rect.height*vb.height) };
}

document.querySelectorAll('.diffwrap').forEach(wrap => {
  redraw(wrap);
  const svg = wrap.querySelector('.overlay');
  let start = null;
  svg.style.pointerEvents = 'all';
  svg.addEventListener('mousedown', e => { if (e.target.classList.contains('rgn')) return; start = svgPoint(svg,e); });
  svg.addEventListener('mouseup', e => {
    if (!start) return;
    const end = svgPoint(svg,e), k = key(wrap);
    const x = Math.min(start.x,end.x), y = Math.min(start.y,end.y);
    const w = Math.abs(end.x-start.x), h = Math.abs(end.y-start.y);
    start = null;
    if (w < 4 || h < 4) return;
    (WL[k] = WL[k] || []).push({ id: 'h'+Date.now(), box:[x,y,w,h], source:'human', kind:'other', note:'', status:'open' });
    editRegion(k, WL[k][WL[k].length-1].id, wrap);
  });
});
</script>`;
```

Render it by **replacing the existing one-line `<script>…</script>`** at the bottom of `reportHtml` with `reportScript(worklistByKey(results))`, using the `worklistByKey` helper added in Task 7. (The old one-liner only wired image fullscreen-on-click; fold that `requestFullscreen` behavior into `reportScript` so it is preserved — bind it on the `img` elements, not the `.rgn` boxes.)

- [ ] **Step 2: Verify in the browser**

Manual check (browser-verification skill): click the amber CTA box → change kind, set a note, set `wontfix` → box turns blue/dashed. Drag a new rectangle near the removed dot → editor opens → set kind `missing` → blue box appears. No console errors.

- [ ] **Step 3: Commit**

```bash
git add skills/visual-parity/parity-harness.mjs
git commit -m "feat(visual-parity): interactive region editing and drag-to-add in report"
```

---

## Task 9: Serve mode — `--serve` with `POST /worklist`

**Files:**
- Modify: `skills/visual-parity/parity-harness.mjs` (CLI dispatch, serve function, Save button)

**Interfaces:**
- Consumes: `OUT_DIR`, `writeWorklist`-shaped files.
- Produces: `node parity-harness.mjs --serve [port]` serves `out/` (report + images) and accepts `POST /worklist` with a full `Worklist` JSON body, writing it to `out/worklist.<surface>.<viewport>.json`. A "Save worklist" button in the report posts the in-memory `WL` grouped back into the file schema.

- [ ] **Step 1: Add the server and CLI branch**

```javascript
import http from 'node:http';

const SERVE_PORT = Number(process.argv.find(a => /^\d+$/.test(a))) || 8088;

async function serve() {
  const types = { '.html':'text/html', '.png':'image/png', '.json':'application/json', '.js':'text/javascript' };
  const server = http.createServer(async (req, res) => {
    if (req.method === 'POST' && req.url === '/worklist') {
      let body = '';
      req.on('data', c => body += c);
      req.on('end', async () => {
        try {
          const data = JSON.parse(body);
          await writeWorklist(path.join(OUT_DIR, worklistFilename(data.surface, data.viewport)), data);
          res.writeHead(200).end('ok');
        } catch (e) { res.writeHead(400).end(String(e)); }
      });
      return;
    }
    const rel = req.url === '/' ? '/report.html' : decodeURIComponent(req.url.split('?')[0]);
    const file = path.join(OUT_DIR, rel);
    try {
      const buf = await fs.readFile(file);
      res.writeHead(200, { 'content-type': types[path.extname(file)] || 'application/octet-stream' }).end(buf);
    } catch { res.writeHead(404).end('not found'); }
  });
  server.listen(SERVE_PORT, () => console.log(`Serving report: http://localhost:${SERVE_PORT}/`));
}

// CLI dispatch at the very bottom, replacing the bare `run().catch(...)`:
if (process.argv.includes('--serve')) {
  serve();
} else {
  run().catch(e => { console.error(e); process.exit(1); });
}
```

- [ ] **Step 2: Add the Save button + POST in the report**

Add to the report header HTML: `<button id="save">Save worklist</button>` and to `reportScript`:

```javascript
document.getElementById('save').onclick = async () => {
  // regroup WL (key surface.vp.section) into per-surface/viewport files
  const files = {};
  for (const [k, regions] of Object.entries(WL)) {
    const [surface, viewport, section] = k.split('.');
    const id = surface + '.' + viewport;
    (files[id] = files[id] || { surface, viewport, sections: [] }).sections.push({ section, regions });
  }
  for (const data of Object.values(files)) {
    await fetch('/worklist', { method:'POST', headers:{'content-type':'application/json'}, body: JSON.stringify(data) });
  }
  alert('Saved.');
};
```

(Section metadata like `legacyTop`/`pixelPct` are preserved by the harness on the next run; the POST carries the human-edited regions, which is what Task 6's preserve-merge consumes.)

- [ ] **Step 3: Verify end-to-end (manual)**

Run: `cd skills/visual-parity && node parity-harness.mjs` (against fixtures), then `node parity-harness.mjs --serve`.
- Open `http://localhost:8088/`, mark a region `wontfix`, Save → confirm `out/worklist.home.desktop.json` on disk now shows that region `source:human status:wontfix`.
- Re-run `node parity-harness.mjs` → confirm the `wontfix` region survived (preserve-merge) and `adjustedPct < pixelPct` for that section.

- [ ] **Step 4: Commit**

```bash
git add skills/visual-parity/parity-harness.mjs
git commit -m "feat(visual-parity): serve mode with POST /worklist round-trip"
```

---

## Task 10: SKILL.md prose, CONFIG, and setup updates

**Files:**
- Modify: `skills/visual-parity/SKILL.md`
- Modify: `skills/visual-parity/parity-harness.mjs` (CONFIG block comments)

**Interfaces:** documentation only — no code interfaces.

- [ ] **Step 1: Update the loop and "Stand up the harness" sections**

In `SKILL.md`, revise so the worklist — not the `%` — is the gate. Add to the loop after "Run harness": "the harness now also writes `out/worklist.<surface>.<viewport>.json` — located regions classified by kind." Add a step: "Work the worklist top to bottom (each region has box + kind + numbers); `unclassified` regions are the ones to fall back to manual DOM measurement on." Add the serve/annotation step: "Optionally `node parity-harness.mjs --serve`, mark/correct regions in the browser, Save — your edits merge into the same worklist and persist across re-runs (`wontfix` = intentional, drops from the adjusted %)."

- [ ] **Step 2: Update the setup commands**

Change "Copy `parity-harness.mjs`" to "Copy `parity-harness.mjs` **and** `parity-lib.mjs`". Add the new commands:

```bash
node parity-harness.mjs            # writes report.html + worklist.*.json
node parity-harness.mjs --serve    # serve the report for annotation (default :8088)
```

- [ ] **Step 3: Document the new CONFIG knobs**

In `parity-harness.mjs` CONFIG block, add comments for `NOISE_MIN_PIXELS` (blob noise floor) and `SERVE_PORT`. In `SKILL.md`'s CONFIG description, mention both.

- [ ] **Step 4: Add a note to the Red Flags / Rationalization sections**

Add one row to the rationalization table: `| "The worklist is empty so it's done" | Empty worklist with a non-trivial pixel% means the noise floor hid regions or the diff is background-only — open the diff image and lower NOISE_MIN_PIXELS. |`

- [ ] **Step 5: Commit**

```bash
git add skills/visual-parity/SKILL.md skills/visual-parity/parity-harness.mjs
git commit -m "docs(visual-parity): worklist-is-the-gate loop, serve usage, CONFIG knobs"
```

---

## Final verification

- [ ] Run the full unit suite: `node --test skills/visual-parity/parity-lib.test.mjs` → all green.
- [ ] Run the integration suite: `cd skills/visual-parity && node --test integration.test.mjs` → all green.
- [ ] Run the harness against fixtures, serve, annotate, re-run → confirm preserve-merge + adjusted %.
- [ ] Re-read `SKILL.md` end-to-end for coherence with the worklist gate.
- [ ] Confirm `skills/visual-parity/.gitignore` keeps `node_modules/`, `out/`, `package-lock.json` out of git.
