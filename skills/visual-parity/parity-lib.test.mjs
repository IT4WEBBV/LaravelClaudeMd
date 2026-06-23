import { test } from 'node:test';
import assert from 'node:assert/strict';
import { maskFromDiff, clusterMask, classifyKind, iou, mergeRegions, countMaskInBoxes, adjustedPct, worklistFilename, readWorklist, writeWorklist, priorSectionRegions } from './parity-lib.mjs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { mkdtemp, rm } from 'node:fs/promises';

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

test('mergeRegions preserves a full human-shaped region verbatim and drops the overlapping auto', () => {
  const fresh = [{ id: 'a1', box: [10, 10, 100, 40], source: 'auto', kind: 'recolor', detail: 'bg x → y', status: 'open' }];
  const human = { id: 'h1', box: [11, 11, 99, 39], source: 'human', kind: 'missing', note: 'the dot', status: 'open' };
  const merged = mergeRegions(fresh, [human]);
  assert.equal(merged.length, 1);
  assert.deepEqual(merged[0], human);
});

test('countMaskInBoxes / adjustedPct: wontfix pixels are excluded', () => {
  // 10x10 mask, all set = 100 changed pixels.
  const mask = new Uint8Array(100).fill(1);
  const inWontfix = countMaskInBoxes(mask, 10, [[0, 0, 5, 10]]); // left half = 50
  assert.equal(inWontfix, 50);
  assert.equal(adjustedPct(100, 100, inWontfix), 50);
});

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
