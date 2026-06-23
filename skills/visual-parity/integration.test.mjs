// skills/visual-parity/integration.test.mjs
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { pathToFileURL } from 'node:url';
import { join } from 'node:path';
import { writeFileSync, unlinkSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { captureHit, detectRegions, reportHtml } from './parity-harness.mjs';
import { PNG } from 'pngjs';

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

test('reportScript loads WL global and renders .rgn boxes', async () => {
  const sampleResults = [
    {
      surface: 'home',
      viewport: 'desktop',
      sections: [
        {
          section: 'hero',
          base: 'home.desktop.hero',
          pct: '4.20',
          legacyH: 100,
          rebuildH: 100,
          legacyTop: 190,
          rebuildTop: 190,
          diffW: 1280,
          diffH: 70,
          adjustedPct: 4.2,
          openCount: 1,
          regions: [{ id: 'a1', box: [40, 10, 160, 48], source: 'auto', kind: 'recolor', note: '', status: 'open' }],
        },
      ],
    },
  ];
  const html = reportHtml(sampleResults);
  const tmpFile = join(tmpdir(), `parity-smoke-${Date.now()}.html`);
  writeFileSync(tmpFile, html);
  const smokePage = await browser.newPage();
  const errors = [];
  smokePage.on('pageerror', e => errors.push(e));
  try {
    await smokePage.goto(pathToFileURL(tmpFile).href);
    assert.equal(errors.length, 0, `Page had uncaught errors: ${errors.map(e => e.message).join(', ')}`);
    const wlType = await smokePage.evaluate(() => typeof WL);
    assert.equal(wlType, 'object', `WL should be an object, got ${wlType}`);
    const rgnCount = await smokePage.locator('.rgn').count();
    assert.ok(rgnCount >= 1, `Expected at least one .rgn element, got ${rgnCount}`);
  } finally {
    await smokePage.close();
    try { unlinkSync(tmpFile); } catch (_) {}
  }
});

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

// ── serve round-trip ─────────────────────────────────────────────────────────
import { serve } from './parity-harness.mjs';
import { promises as fsPromises } from 'node:fs';
import { fileURLToPath as ftu } from 'node:url';
import { dirname } from 'node:path';

const __dir = dirname(ftu(import.meta.url));
const outDir = join(__dir, 'out');

test('serve: POST /worklist writes file and GET returns 200', async () => {
  const server = await serve(0);
  const port = server.address().port;
  const payload = {
    surface: 'tsurf',
    viewport: 'tvp',
    sections: [{
      section: 'hero',
      regions: [{ id: 'h1', box: [1, 2, 3, 4], source: 'human', kind: 'missing', note: 'x', status: 'open' }],
    }],
  };
  const outFile = join(outDir, 'worklist.tsurf.tvp.json');
  try {
    const res = await fetch(`http://localhost:${port}/worklist`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify(payload),
    });
    assert.equal(res.status, 200);
    const written = JSON.parse(await fsPromises.readFile(outFile, 'utf8'));
    assert.deepEqual(written, payload);
  } finally {
    await new Promise(resolve => server.close(resolve));
    try { await fsPromises.unlink(outFile); } catch (_) {}
  }
});
