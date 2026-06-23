import { test } from 'node:test';
import assert from 'node:assert/strict';
import { reportHtml, worklistByKey } from './parity-harness.mjs';

const sampleRegion = {
  id: 'a1',
  box: [40, 10, 160, 48],
  source: 'auto',
  kind: 'recolor',
  detail: 'bg #ff6a00 → #ff8c00',
  status: 'open',
};

const sampleSection = {
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
  regions: [sampleRegion],
};

const sampleResults = [
  {
    surface: 'home',
    viewport: 'desktop',
    sections: [sampleSection],
  },
];

test('reportHtml includes overlay with correct viewBox', () => {
  const html = reportHtml(sampleResults);
  assert.ok(html.includes('class="overlay"'), 'should have class="overlay"');
  assert.ok(html.includes('viewBox="0 0 1280 70"'), 'should have viewBox with diffW and diffH');
});

test('reportHtml includes region rect with data-id', () => {
  const html = reportHtml(sampleResults);
  assert.ok(html.includes('class="rgn"'), 'should have class="rgn"');
  assert.ok(html.includes('data-id="a1"'), 'should have data-id="a1"');
});

test('reportHtml includes region kind text', () => {
  const html = reportHtml(sampleResults);
  assert.ok(html.includes('>recolor<'), 'should show the region kind as text content');
});

test('reportHtml escapes user-controlled region kind', () => {
  const results = [
    {
      surface: 'home',
      viewport: 'desktop',
      sections: [{ ...sampleSection, regions: [{ ...sampleRegion, kind: 'a<b' }] }],
    },
  ];
  const html = reportHtml(results);
  assert.ok(html.includes('a&lt;b'), 'should escape < in kind');
  assert.ok(!html.includes('a<b'), 'should not contain raw unescaped a<b');
});

test('reportHtml includes adjusted metric line', () => {
  const html = reportHtml(sampleResults);
  assert.ok(html.includes('adj 4.2%'), 'should show adj pct');
  assert.ok(html.includes('1 open'), 'should show open count');
});

test('worklistByKey builds map keyed by surface.viewport.section', () => {
  const map = worklistByKey(sampleResults);
  assert.ok('home.desktop.hero' in map, 'should have home.desktop.hero key');
  assert.equal(map['home.desktop.hero'].length, 1);
  assert.equal(map['home.desktop.hero'][0].id, 'a1');
});

test('worklistByKey skips missing sections', () => {
  const resultsWithMissing = [
    {
      surface: 'home',
      viewport: 'desktop',
      sections: [
        sampleSection,
        { section: 'footer', missing: { legacy: true, rebuild: false } },
      ],
    },
  ];
  const map = worklistByKey(resultsWithMissing);
  assert.ok('home.desktop.hero' in map);
  assert.ok(!('home.desktop.footer' in map), 'should not include missing sections');
});
