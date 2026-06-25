# Visual-parity Annotation Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. **Note:** the experiment gates (Phase E1–E3) require driving a live browser (Playwright MCP) against real sites and are best executed *inline* in the main session, not by a subagent.

**Goal:** Rework the `visual-parity` skill so the human only ever touches the report (read → mark readable panes → paste/screenshot back), while Claude owns the config, the run, and the categorization; and so one run can emit several intent-scoped reports.

**Architecture:** Keep the Node engine. Extract the new behavior into **dependency-free pure functions in `parity-lib.mjs`** (Node-tested without npm) and reuse them in the browser report by embedding their `.toString()` source. The report becomes a three-pane `legacy | rebuild | fix-list` layout; handback is a clipboard Markdown blob that works on `file://`. Per-surface `auth` + `report` tags drive named reports.

**Tech Stack:** Node 24, Playwright, pixelmatch, pngjs, `node:test`. Browser report is vanilla JS + inline SVG.

## Global Constraints

- Engine code (`parity-harness.mjs`, `parity-lib.mjs`, report template) MUST stay **byte-identical between the skill and the test-bed** — only the project `visual-parity.config.mjs` differs. (Verify with `diff` before the PR.)
- `parity-lib.mjs` imports **nothing external** (only `node:fs`) so its tests run without `npm install`. All new pure logic goes here.
- All new pure functions reused in the browser are embedded via `fn.toString()` — **one implementation**, Node-tested, never duplicated.
- Backward-compatible: no `--config` → in-file CONFIG; no `report` tag → `report.html`; no `auth` profile → existing single default `storageState`; region/worklist JSON shape unchanged.
- Keep the existing skill test suites green: `parity-lib.test.mjs`, `report.test.mjs`, `integration.test.mjs`.
- Prove every browser-dependent change with `/experiment` **before** porting to the skill.
- PR to `IT4WEBBV/LaravelClaudeMd` on a **feature branch** (never `main`); **no `Co-Authored-By`, no AI-attribution** lines; fold in the pending editor-popover change; update the changelog if the repo keeps one.

## File Structure

| File | Repo | Responsibility | Action |
|------|------|----------------|--------|
| `parity-lib.mjs` | both | Pure logic: diff/cluster/classify + **new** `feedbackMarkdown`, `groupResultsByReport`, `resolveStorageState`, `normalizeConfig` | Modify |
| `parity-lib.test.mjs` | skill | Unit tests for the above | Modify |
| `parity-harness.mjs` | both | Engine: `--config` load, per-surface auth, report grouping, three-pane `reportHtml`, file://-safe `reportScript`, classify human regions | Modify |
| `report.test.mjs` | skill | `reportHtml` structure + `feedbackMarkdown` embedding | Rewrite/extend |
| `integration.test.mjs` | skill | serve round-trip (keep) + `--config` load + file:// handback smoke | Extend |
| `login-state.mjs` | skill (new template) | mint one auth profile's combined storageState: `node login-state.mjs <email> <pass> <out.json>` | Create |
| `SKILL.md` | skill | document run-from-skill, config, fix-list, copy-feedback, named reports + auth | Modify |
| `visual-parity.config.mjs` | test-bed | BreinStraat2 surfaces/urls/authProfiles (Claude's artifact) | Create |

Develop the **pure functions (Phase A) in the skill repo feature branch** with `node:test` (fast, no live sites). Develop **browser/experiment work (Phases B–E) in the test-bed** against real sites, then port. Keep lib byte-identical by copying after each change.

---

## Phase 0: Branch + test-bed config

### Task 0: Cut the PR branch and author the test-bed config

**Files:**
- Create: `BreinStraat2/tools/visual-parity/visual-parity.config.mjs`
- Branch: `LaravelClaudeMd` → `feature/visual-parity-annotation-redesign`

- [ ] **Step 1: Cut the skill feature branch (carries the pending editor-popover change)**

```bash
cd ~/GitProjects/LaravelClaudeMd/LaravelClaudeMd
git checkout -b feature/visual-parity-annotation-redesign
git status --short   # expect: M skills/visual-parity/parity-harness.mjs (the popover work rides along)
```

- [ ] **Step 2: Author `visual-parity.config.mjs`** by lifting the existing test-bed CONFIG/SURFACES block into a default export. Add `authProfiles` and per-surface `auth`/`report` tags:

```js
export default {
  legacy:  'https://act.breinstraat.nl',
  rebuild: 'https://breinstraat2.it4web.net',
  viewports: [
    { name: 'desktop', width: 1920, height: 1080 },
    { name: 'mobile',  width: 390,  height: 844 },
  ],
  noiseMinPixels: 40,
  threshold: 0.1,
  authProfiles: { maria: 'auth.maria.json', karel: 'auth.karel.json' },
  surfaces: [
    // public marketing pages (report: 'public', no auth) — copy the 10 existing entries
    { name: 'home', path: '/', waitText: 'echt begrijpen', report: 'public', sections: [/* existing */] },
    // … about, conditions, … (all report: 'public')
    // member pages (report: 'members')
    { name: 'landing', path: '/mijnverhaal',          waitText: 'Mijn verhaal',   auth: 'maria', report: 'members', sections: [{ name: 'content', anchor: 'Mijn verhaal' }] },
    { name: 'wizard',  path: '/mijnverhaal/invullen',  waitText: 'Mijn verhaal',   auth: 'maria', report: 'members', sections: [{ name: 'content', anchor: 'Mijn verhaal' }] },
    { name: 'profiel', path: '/profiel',               waitText: 'Email wijzigen', auth: 'maria', report: 'members', sections: [{ name: 'content', anchor: 'Email wijzigen' }] },
    { name: 'qa-intro',path: '/vraagenantwoord',       waitText: 'Mijn Verhaal',   auth: 'maria', report: 'members', sections: [{ name: 'content', anchor: 'Vraag & Antwoord' }] },
    { name: 'edit',    path: '/mijnverhaal/aanpassen',  waitText: 'Mijn verhaal',   auth: 'karel', report: 'members', sections: [{ name: 'content', anchor: 'Mijn verhaal' }] },
  ],
};
```

- [ ] **Step 3: Commit the branch point** (skill repo, spec + nothing else yet) — defer; no commit needed until Task 1 produces code. The config is test-bed scratch (gitignored area / not part of the PR).

---

## Phase A: Pure lib foundations (TDD in the skill repo)

### Task 1: `feedbackMarkdown(worklistByKey, opts)` — serialize the fix list

**Files:**
- Modify: `skills/visual-parity/parity-lib.mjs`
- Test: `skills/visual-parity/parity-lib.test.mjs`

**Interfaces:**
- Consumes: the keyed worklist map `worklistByKey` (already produced by `parity-harness.mjs#worklistByKey`): `{ "surface.viewport.section": Region[] }`, `Region = { id, box:[x,y,w,h], source, kind, detail?, note?, status }`.
- Produces: `feedbackMarkdown(wlByKey, { reportName } = {}) -> string`. Reused in-browser via `.toString()`.

- [ ] **Step 1: Write the failing test**

```js
import { feedbackMarkdown } from './parity-lib.mjs';

test('feedbackMarkdown groups by surface@viewport, drops fixed, labels human as [you]', () => {
  const wl = {
    'landing.desktop.content': [
      { id: 'a1', box: [40,10,160,48], source: 'auto', kind: 'recolor', detail: 'bg #fff → #f7f7f7', note: 'too grey', status: 'open' },
      { id: 'a2', box: [0,0,10,10], source: 'auto', kind: 'shift', detail: 'Δy 12px', status: 'fixed' },
      { id: 'h1', box: [120,200,80,30], source: 'human', kind: undefined, note: 'logo too big', status: 'open' },
    ],
  };
  const md = feedbackMarkdown(wl, { reportName: 'members' });
  assert.match(md, /## Visual parity feedback — report: members/);
  assert.match(md, /### landing @ desktop/);
  assert.match(md, /- \[recolor\] content — bg #fff → #f7f7f7 @ \(40,10,160,48\) — note: "too grey" — open/);
  assert.match(md, /- \[you\] content — @ \(120,200,80,30\) — note: "logo too big" — open/);
  assert.ok(!md.includes('Δy 12px'), 'fixed items are dropped');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `node --test parity-lib.test.mjs` (from `skills/visual-parity/`)
Expected: FAIL — `feedbackMarkdown is not a function`.

- [ ] **Step 3: Implement in `parity-lib.mjs`**

```js
export function feedbackMarkdown(worklistByKey, { reportName } = {}) {
  const bySurfaceVp = {};
  for (const [k, regions] of Object.entries(worklistByKey || {})) {
    const dot = k.indexOf('.'), dot2 = k.indexOf('.', dot + 1);
    const surface = k.slice(0, dot), viewport = k.slice(dot + 1, dot2), section = k.slice(dot2 + 1);
    for (const r of regions) {
      if (r.status === 'fixed') continue;
      const head = `${surface} @ ${viewport}`;
      (bySurfaceVp[head] = bySurfaceVp[head] || []).push({ section, r });
    }
  }
  const lines = [`## Visual parity feedback${reportName ? ` — report: ${reportName}` : ''}`];
  for (const [head, items] of Object.entries(bySurfaceVp)) {
    lines.push('', `### ${head}`);
    for (const { section, r } of items) {
      const cat = r.source === 'human' && !r.kind ? 'you' : (r.kind || 'unclassified');
      const where = r.detail ? `${r.detail} @ (${r.box.join(',')})` : `@ (${r.box.join(',')})`;
      const note = r.note ? ` — note: "${r.note}"` : '';
      lines.push(`- [${cat}] ${section} — ${where}${note} — ${r.status || 'open'}`);
    }
  }
  return lines.join('\n');
}
```

- [ ] **Step 4: Run to verify it passes** — `node --test parity-lib.test.mjs` → PASS.
- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat(visual-parity): feedbackMarkdown fix-list serializer"`

### Task 2: `resolveStorageState(surface, authProfiles, defaultState)`

**Interfaces:** `resolveStorageState(surface, authProfiles = {}, defaultState) -> string | undefined`. `surface.auth` → `authProfiles[surface.auth]`; missing profile name throws (config bug, fail loud); no `surface.auth` → `defaultState`.

- [ ] **Step 1: Failing test**

```js
import { resolveStorageState } from './parity-lib.mjs';
test('resolveStorageState: per-surface profile, default fallback, unknown throws', () => {
  const profiles = { maria: 'auth.maria.json' };
  assert.equal(resolveStorageState({ name: 'landing', auth: 'maria' }, profiles, undefined), 'auth.maria.json');
  assert.equal(resolveStorageState({ name: 'home' }, profiles, 'auth.json'), 'auth.json');
  assert.equal(resolveStorageState({ name: 'home' }, profiles, undefined), undefined);
  assert.throws(() => resolveStorageState({ name: 'x', auth: 'ghost' }, profiles, undefined), /unknown auth profile/);
});
```

- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement**

```js
export function resolveStorageState(surface, authProfiles = {}, defaultState) {
  if (!surface.auth) return defaultState;
  if (!(surface.auth in authProfiles)) throw new Error(`unknown auth profile: ${surface.auth}`);
  return authProfiles[surface.auth];
}
```

- [ ] **Step 4: Run → PASS.**
- [ ] **Step 5: Commit** — `feat(visual-parity): per-surface storageState resolution`

### Task 3: `groupResultsByReport(results)`

**Interfaces:** `groupResultsByReport(results) -> Array<{ name: string, results: Result[] }>`. Each `Result` carries a `report` field (default `'default'`). Groups preserve input order; `'default'` group → report file `report.html`, named group `X` → `report.X.html` (file naming handled in the harness, not here).

- [ ] **Step 1: Failing test**

```js
import { groupResultsByReport } from './parity-lib.mjs';
test('groupResultsByReport splits by report tag, default first', () => {
  const results = [
    { surface: 'home', viewport: 'desktop', report: 'public', sections: [] },
    { surface: 'landing', viewport: 'desktop', report: 'members', sections: [] },
    { surface: 'home', viewport: 'mobile', report: 'public', sections: [] },
    { surface: 'x', viewport: 'desktop', sections: [] }, // untagged → 'default'
  ];
  const groups = groupResultsByReport(results);
  assert.deepEqual(groups.map(g => g.name), ['public', 'members', 'default']);
  assert.equal(groups[0].results.length, 2);
});
```

- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement**

```js
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
```

- [ ] **Step 4: Run → PASS.**
- [ ] **Step 5: Commit** — `feat(visual-parity): group results into named reports`

### Task 4: `normalizeConfig(raw)`

**Interfaces:** `normalizeConfig(raw) -> { legacy, rebuild, viewports, surfaces, authProfiles, noiseMinPixels, threshold }` with defaults applied; throws if `legacy`/`rebuild`/`surfaces` missing.

- [ ] **Step 1: Failing test**

```js
import { normalizeConfig } from './parity-lib.mjs';
test('normalizeConfig applies defaults and validates', () => {
  const c = normalizeConfig({ legacy: 'a', rebuild: 'b', surfaces: [{ name: 'home', path: '/' }] });
  assert.equal(c.noiseMinPixels, 12);
  assert.equal(c.threshold, 0.1);
  assert.deepEqual(c.authProfiles, {});
  assert.equal(c.viewports.length, 2);
  assert.throws(() => normalizeConfig({ legacy: 'a' }), /surfaces/);
});
```

- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement** (defaults mirror the current in-file constants)

```js
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
    noiseMinPixels: raw.noiseMinPixels ?? 12,
    threshold: raw.threshold ?? 0.1,
  };
}
```

- [ ] **Step 4: Run → PASS.**
- [ ] **Step 5: Commit** — `feat(visual-parity): normalizeConfig with defaults`

---

## Phase B: Harness wiring — `--config`, per-surface auth, named reports

> Work these in the **test-bed harness**, then sync lib (Phase A already landed in the skill — copy `parity-lib.mjs` to the test-bed first so it has the new functions).

### Task 5: Load an external config with `--config`

**Files:** Modify `parity-harness.mjs` (CONFIG resolution near top + `run()`).

**Interfaces:** Produces a single `CONFIG` object via `await loadConfig(argv)`. With `--config <path>`: `normalizeConfig((await import(pathToFileURL(abs))).default)`. Without: `normalizeConfig({ legacy: LEGACY, rebuild: REBUILD, viewports: VIEWPORTS, surfaces: SURFACES, authProfiles: {...}, noiseMinPixels: NOISE_MIN_PIXELS, threshold: PIXELMATCH_OPTS.threshold })` from the existing in-file constants (back-compat).

- [ ] **Step 1:** Add a `--config <path>` parse + `loadConfig()` that returns the normalized object; route `run()` to read `CONFIG.legacy/rebuild/viewports/surfaces/authProfiles/...` instead of the bare module constants. Keep the in-file constants as the no-arg fallback.
- [ ] **Step 2: Integration test (skill)** in `integration.test.mjs`: write a temp `cfg.mjs`, import the harness's `loadConfig`, assert it normalizes. (Export `loadConfig`.)

```js
import { loadConfig } from './parity-harness.mjs';
test('loadConfig reads an external --config module', async () => {
  const f = join(tmpdir(), `vpcfg-${Date.now()}.mjs`);
  writeFileSync(f, `export default { legacy:'a', rebuild:'b', surfaces:[{name:'home',path:'/'}] }`);
  const cfg = await loadConfig(['--config', f]);
  assert.equal(cfg.legacy, 'a'); assert.equal(cfg.noiseMinPixels, 12);
  unlinkSync(f);
});
```

- [ ] **Step 3:** Run `node --test integration.test.mjs` → PASS.
- [ ] **Step 4:** Smoke from the skill dir against the test-bed config: `node ~/GitProjects/.../skills/visual-parity/parity-harness.mjs --config ~/GitProjects/Breinstraat2/BreinStraat2/tools/visual-parity/visual-parity.config.mjs home` → expect it captures `home` and writes a report. (Confirms deps resolve from the skill's own `node_modules`.)
- [ ] **Step 5: Commit** — `feat(visual-parity): --config external config loading`

### Task 6: Per-surface auth + named report grouping in `run()`

**Files:** Modify `parity-harness.mjs#run()` + `worklistByKey` consumers.

- [ ] **Step 1:** In the `(surface, viewport)` loop, resolve `storageState` via `resolveStorageState(surface, CONFIG.authProfiles, DEFAULT_STATE)` (DEFAULT_STATE = `existsSync(STORAGE_STATE) ? STORAGE_STATE : undefined`), joining profile paths relative to the config dir. Tag each pushed result with `report: surface.report || 'default'`.
- [ ] **Step 2:** Replace the single `report.html` write with: `for (const g of groupResultsByReport(results)) fs.writeFile(path.join(OUT_DIR, reportFilename(g.name)), reportHtml(g.results, { reportName: g.name }))`. Pass the group name through to `reportHtml`/`feedbackMarkdown`.
- [ ] **Step 3:** Add a `--report <name>` filter (narrows surfaces to that tag for a focused re-run).
- [ ] **Step 4: Commit** — `feat(visual-parity): per-surface auth + named report files`

---

## Phase E2 (gate): prove named reports + per-surface auth

### Task 7: `/experiment` — one run, two non-clobbering reports in correct auth states

- [ ] **Step 1:** Mint profiles: `node login-state.mjs maria@test.nl 1234 auth.maria.json`; try `node login-state.mjs karel@test.nl 1234 auth.karel.json`.
- [ ] **Step 2:** Run one sweep: `node <skill>/parity-harness.mjs --config ./visual-parity.config.mjs landing edit home`.
- [ ] **Step 3 — PASS criteria:**
  - `out/report.public.html` exists and references only public surfaces; `out/report.members.html` exists and references only member surfaces. Neither clobbers the other.
  - In `report.members.html`, the `landing`/`edit` rebuild captures show **logged-in** content (not the login redirect).
  - If `karel@test.nl/1234` is **not** a completed youth (the `edit` page redirects to landing), record it: prove the mechanism with guest+maria only and leave `karel` a documented config entry. Log this outcome in the experiment notes.
- [ ] **Step 4:** Record the experiment result (the `/experiment` skill writes its own log). Only adopt Phase B if PASS.

---

## Phase C: Report UI rework + handback

### Task 8: Three-pane `reportHtml` (legacy | rebuild | fix-list) + machine boxes on readable panes

**Files:** Modify `parity-harness.mjs#reportHtml` + CSS.

**Interfaces:** `reportHtml(results, { reportName } = {})`. Each section renders three columns:
1. `legacy` `<img>` with an SVG overlay (drawable; shows `missing`-kind boxes).
2. `rebuild` `<img>` with an SVG overlay (drawable; shows all machine boxes + human boxes, mapped from diff-local coords to the rebuild image: the box `[x,y,w,h]` is already band-local and the readable pane shows the same band, so coordinates map directly via the section's `diffW/diffH` viewBox).
3. `fix-list` `<div>`: one row per non-fixed region — `[kind|you] · detail/box · note · status`, each row carrying `data-id` linked to its box.
- A small **`diff ▸`** toggle reveals the raw `<base>.diff.png` per section.

- [ ] **Step 1: Rewrite `report.test.mjs` structural assertions** to the new layout (replace `trio`/single-overlay assertions):

```js
test('reportHtml renders legacy + rebuild overlays and a fix-list row per region', () => {
  const html = reportHtml(sampleResults);
  assert.ok(html.includes('class="pane legacy"'));
  assert.ok(html.includes('class="pane rebuild"'));
  assert.ok(html.includes('class="fixlist"'));
  assert.ok(html.includes('data-id="a1"'));            // box on a pane
  assert.ok(html.includes('class="fix-row" data-id="a1"')); // linked fix-list row
  assert.ok(html.includes('>recolor<'));               // machine kind shown read-only
});
test('reportHtml keeps the raw diff behind a toggle', () => {
  assert.ok(reportHtml(sampleResults).includes('home.desktop.hero.diff.png'));
  assert.ok(reportHtml(sampleResults).includes('class="diff-toggle"'));
});
```

- [ ] **Step 2: Run → FAIL** (old structure).
- [ ] **Step 3: Implement** the three-pane `block`/`diffFig` rewrite + fix-list builder + CSS (`.panes{grid-template-columns:1fr 1fr minmax(220px,.7fr)}`, `.fixlist`, `.fix-row`, `.diff-toggle`). Render boxes on legacy (missing) and rebuild (rest+human); fix-row hover toggles a `.hi` class on the matching box.
- [ ] **Step 4: Run `node --test report.test.mjs` → PASS.**
- [ ] **Step 5: Commit** — `feat(visual-parity): three-pane report with fix-list`

### Task 9: Editor without category; Copy-feedback file://-safe; POST only when served

**Files:** Modify `parity-harness.mjs#reportScript` + embed `feedbackMarkdown`.

- [ ] **Step 1:** Remove the `edKind` dropdown from the popover. Editor = note input + an **ignore/intentional** segmented toggle (sets `status: 'wontfix'` ↔ `'open'`) + Delete. Machine `kind` shows as a read-only label in the fix-row.
- [ ] **Step 2:** Embed the lib serializer into the page: `reportHtml` appends `${feedbackMarkdown.toString()}` and a `const REPORT_NAME=${JSON.stringify(reportName||null)}`. Wire a **Copy feedback** button:

```js
async function copyFeedback() {
  const md = feedbackMarkdown(WL, { reportName: REPORT_NAME });
  try { await navigator.clipboard.writeText(md); flash('Copied ✓'); }
  catch { showInTextarea(md); }      // file:// without clipboard perms → manual-copy textarea + Download .md
}
```

- [ ] **Step 3:** Gate the optional disk save: only attempt `POST /worklist` when `location.protocol` starts with `http`; on `file://` skip entirely (no hang). Keep the served POST/merge path unchanged.
- [ ] **Step 4: Add an integration smoke test** (skill, `integration.test.mjs`) that opens the generated report from a `file://` URL in Playwright, clicks Copy feedback, and asserts no uncaught error + the textarea/clipboard path is reachable (extend the existing `reportScript loads WL` smoke):

```js
test('file:// Copy feedback does not throw and yields markdown', async () => {
  const html = reportHtml(sampleResults);
  const f = join(tmpdir(), `vp-fb-${Date.now()}.html`); writeFileSync(f, html);
  const p = await browser.newPage(); const errs = []; p.on('pageerror', e => errs.push(e));
  await p.goto(pathToFileURL(f).href);
  const md = await p.evaluate(() => feedbackMarkdown(WL, { reportName: REPORT_NAME }));
  assert.equal(errs.length, 0); assert.match(md, /## Visual parity feedback/);
  await p.close(); unlinkSync(f);
});
```

- [ ] **Step 5: Run → PASS. Commit** — `feat(visual-parity): machine-owned categories + file://-safe copy-feedback`

---

## Phase E1 (gate): prove handback on file:// AND served

### Task 10: `/experiment` — Copy feedback round-trips both ways

- [ ] **Step 1:** Open `out/report.public.html` via `file://` in the Playwright MCP. Draw a box on the **rebuild** pane, type a note, click **Copy feedback**, read the clipboard (or textarea). **PASS:** Markdown contains the new `[you]` row with the note; button never sticks; no console error.
- [ ] **Step 2:** `node <skill>/parity-harness.mjs --serve` (port free per session note; use another port if `EADDRINUSE`). Open over `http://localhost`. Repeat the draw, then confirm the optional **POST** still merges into `out/worklist.public.*.json` on disk.
- [ ] **Step 3:** Record `/experiment` result. Adopt Phase C only if both PASS.

---

## Phase D: Classify human-drawn regions on re-run

### Task 11: Hit-test + classify human regions during the run

**Files:** Modify `parity-harness.mjs` (region pipeline in `run()` / `detectRegions` consumers).

- [ ] **Step 1:** After `mergeRegions`, for each merged region with `source==='human'` and no machine `kind` (or `kind==='other'`), hit-test its box centre on both sides (reuse `captureHit`) and set `kind/detail` via `classifyKind` — without overwriting the human's `note`/`status`.
- [ ] **Step 2: Integration test** (skill) extending the recolor test: seed a human region over the recolored CTA band, run the classify step, assert it gains `kind==='recolor'` while keeping its note.
- [ ] **Step 3: Run → PASS. Commit** — `feat(visual-parity): auto-classify human-drawn regions on re-run`

## Phase E3 (gate): prove a drawn box comes back categorized

### Task 12: `/experiment` — human box gets a machine kind

- [ ] **Step 1:** In a test-bed worklist, add a human box over a known real difference; re-run that surface.
- [ ] **Step 2 — PASS:** the box reappears with a machine `kind` + `detail`, note/status preserved, and renders that kind in the fix-list. Record `/experiment` result.

---

## Phase F: Port to skill, docs, PR

### Task 13: Sync engine to the skill + login-state template + SKILL.md

- [ ] **Step 1:** Copy the proven `parity-harness.mjs` + `parity-lib.mjs` + report template from the test-bed to the skill. Restore the skill's **generic** in-file CONFIG placeholder block (example URLs/surfaces) and `NOISE_MIN_PIXELS = 12`. Verify byte-identity of everything except the CONFIG block: `diff <(sed CONFIG) ...` / targeted `diff`.
- [ ] **Step 2:** Add the generic `login-state.mjs` template to the skill (params `<email> <pass> <out.json>`, logs into both `legacy`/`rebuild` login forms — documented as project-customizable).
- [ ] **Step 3:** Update `SKILL.md`: run-from-skill invocation + `--config`; the config shape; the three-pane fix-list report; **Copy feedback** (file:// + served) replacing the save-hang note; categories are machine-owned (drop "human picks kind"); named reports + per-surface auth + `login-state.mjs`. Update the CONFIG-knobs table and the loop diagram where they reference the old report.
- [ ] **Step 4:** Run the **full skill suite**: `node --test *.test.mjs` from `skills/visual-parity/` → all green.
- [ ] **Step 5: Commit** — `feat(visual-parity): run-from-skill, fix-list report, named reports + auth; docs`

### Task 14: Convert the test-bed to run-from-skill + open the PR

- [ ] **Step 1:** In the test-bed, delete the copied engine (`parity-harness.mjs`, `parity-lib.mjs`, `node_modules` if desired) leaving only `visual-parity.config.mjs`, `auth.*.json`, `login-state.mjs`, `out/`. Confirm `node <skill>/parity-harness.mjs --config ./visual-parity.config.mjs home` still works.
- [ ] **Step 2:** Update the changelog if `LaravelClaudeMd` keeps one (`git tag --sort=-v:refname | head -5` for the next version).
- [ ] **Step 3:** Push the skill feature branch; `gh pr create` to `IT4WEBBV/LaravelClaudeMd` — body summarizes the redesign, links the spec; **no co-author / no AI-attribution**.
- [ ] **Step 4:** Verify the diff: only `skills/visual-parity/*` changed; engine byte-identical to the test-bed except CONFIG.

---

## Self-Review

**Spec coverage:**
- Distribution / run-from-skill / config-is-Claude's → Tasks 0, 4, 5, 13, 14. ✓
- Three-pane fix-list report, machine boxes on readable panes, raw-diff toggle → Task 8. ✓
- Categories machine-owned (no kind dropdown) + classify human regions → Tasks 9, 11, 12. ✓
- Handback Copy-feedback Markdown, file:// + served, POST optional → Tasks 1, 9, 10. ✓
- Named reports + per-surface auth + login-state → Tasks 2, 3, 6, 7, 13. ✓
- Keep skill tests green + add tests → every TDD task + Tasks 8, 9, 11. ✓
- Backward-compat (no `--config`/`report`/`auth`) → Tasks 4, 5, 6 defaults. ✓
- Byte-identity engine constraint → Tasks 13, 14 verify. ✓
- Fold pending editor-popover change → Task 0 branch carries it; Task 9 builds on it. ✓

**Placeholder scan:** Config example in Task 0 uses `/* existing */` for the 10 known public surfaces (a copy instruction, not unknown content) — acceptable. No TBD/TODO elsewhere.

**Type consistency:** `feedbackMarkdown(worklistByKey, {reportName})`, `resolveStorageState(surface, authProfiles, defaultState)`, `groupResultsByReport(results)→[{name,results}]`, `reportFilename(name)`, `normalizeConfig(raw)`, `loadConfig(argv)`, `reportHtml(results,{reportName})` — names/signatures consistent across tasks. ✓
