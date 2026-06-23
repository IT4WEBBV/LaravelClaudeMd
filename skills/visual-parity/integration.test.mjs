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
