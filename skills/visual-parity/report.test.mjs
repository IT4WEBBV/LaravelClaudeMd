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
  { surface: 'home', viewport: 'desktop', sections: [sampleSection] },
];

test('reportHtml renders legacy + rebuild panes and a fix-list', () => {
  const html = reportHtml(sampleResults);
  assert.ok(html.includes('class="pane legacy"'), 'legacy pane');
  assert.ok(html.includes('class="pane rebuild"'), 'rebuild pane');
  assert.ok(html.includes('class="fixlist"'), 'fix-list pane');
});

test('reportHtml renders the region box and a linked fix-list row', () => {
  const html = reportHtml(sampleResults);
  assert.ok(html.includes('class="rgn" data-id="a1"'), 'box rect with data-id');
  assert.ok(html.includes('class="fix-row" data-id="a1"'), 'fix-list row with the same data-id');
});

test('reportHtml shows the machine kind read-only (no kind <select>)', () => {
  const html = reportHtml(sampleResults);
  assert.ok(html.includes('>recolor<'), 'machine kind shown as text');
  assert.ok(!html.includes('<select'), 'human no longer picks a kind');
});

test('reportHtml keeps the raw diff behind a toggle', () => {
  const html = reportHtml(sampleResults);
  assert.ok(html.includes('class="diff-toggle"'), 'diff toggle button');
  assert.ok(html.includes('home.desktop.hero.diff.png'), 'raw diff image still referenced');
});

test('reportHtml escapes user-controlled region kind', () => {
  const results = [
    { surface: 'home', viewport: 'desktop', sections: [{ ...sampleSection, regions: [{ ...sampleRegion, kind: 'a<b' }] }] },
  ];
  const html = reportHtml(results);
  assert.ok(html.includes('a&lt;b'), 'should escape < in kind');
  assert.ok(!html.includes('>a<b<'), 'no raw unescaped a<b in markup');
});

test('reportHtml shows adjusted metric line + open count', () => {
  const html = reportHtml(sampleResults);
  assert.ok(html.includes('adj 4.2%'), 'adjusted pct');
  assert.ok(html.includes('1 open'), 'open count');
});

test('reportHtml embeds feedbackMarkdown + the report name', () => {
  const html = reportHtml(sampleResults, { reportName: 'members' });
  assert.ok(html.includes('function feedbackMarkdown'), 'serializer embedded for client-side Copy feedback');
  assert.ok(html.includes('const REPORT_NAME = "members"'), 'report name embedded');
  assert.ok(html.includes('id="copyfeedback"'), 'Copy feedback button present');
});

test('worklistByKey builds map keyed by surface.viewport.section', () => {
  const map = worklistByKey(sampleResults);
  assert.ok('home.desktop.hero' in map);
  assert.equal(map['home.desktop.hero'].length, 1);
  assert.equal(map['home.desktop.hero'][0].id, 'a1');
});

test('worklistByKey skips missing sections', () => {
  const resultsWithMissing = [
    {
      surface: 'home',
      viewport: 'desktop',
      sections: [sampleSection, { section: 'footer', missing: { legacy: true, rebuild: false } }],
    },
  ];
  const map = worklistByKey(resultsWithMissing);
  assert.ok('home.desktop.hero' in map);
  assert.ok(!('home.desktop.footer' in map));
});
