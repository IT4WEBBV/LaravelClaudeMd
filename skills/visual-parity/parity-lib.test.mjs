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
