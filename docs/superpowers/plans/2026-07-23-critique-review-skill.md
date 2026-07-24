# /critique Review Skill Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the `/critique` skill — a four-mode review skill whose deterministic pre-pass runs as tested JS and whose modes/pipeline live in an authored SKILL.md.

**Architecture:** Two phases. **Phase A** is the deterministic stage-0 pre-pass: pure functions over a parsed unified diff (plus one filesystem check), TDD with `node:test`, colocated `.test.mjs` files matching the `visual-parity` skill's pattern. **Phase B** is the prose skill — `references/rubrics.md` and `SKILL.md` — authored with `superpowers:writing-skills`, wiring in the Phase A checks and verified by a real dry run.

**Tech Stack:** Node ≥18 (`node:test`, `node:assert/strict`, ES modules) for the checks; Markdown for the skill prose. No new dependencies — everything uses the Node standard library and `git`/`find` subprocesses, matching `skills/visual-parity/`.

**Spec:** `docs/superpowers/specs/2026-07-23-critique-review-skill-design.md` (v6, approved).

## Global Constraints

Every task inherits these, copied verbatim from the spec:

- **Language deviation (decided at plan time):** stage-0 checks are `.mjs` functions, not shell scripts — `bats`/`shellcheck` are absent and the house runner is `node:test`. Checks shell out to `git`/`find` only where the input is the filesystem.
- **The unit of review is the whole change:** `git diff origin/main...HEAD` plus uncommitted working-tree changes; with a PR number, `gh pr diff <n>`. Never commit ranges, never individual commits.
- **A stage-0 heuristic check must return ≤5 candidates on a normal diff.** One that returns a long list has relocated noise, not removed it, and is cut.
- **An exact check has no licence to be wrong.** One false positive on a real branch demotes it to heuristic or deletes it.
- **No findings store.** No ledger, no state between runs, except the drop tally inside `rubrics.md`. Re-review is a fresh run or labels a pasted prior report.
- **Nothing is posted to GitHub** without an explicit instruction, and nothing ever addresses a person.
- **Default reviewer model is Fable**, configurable. Reasoning effort is session-level; the skill promises no per-dispatch effort.
- **Findings cap: 15**, with the remainder listed as category-slug one-liners; Tier-3 dropped and disclosed by category.
- **The verdict column (CONFIRMED/PLAUSIBLE) appears only in `pr` mode.**
- **Skill home:** `skills/critique/`, symlinked into `~/.claude/skills/critique`; no per-project config.

---

## Phase A — Deterministic pre-pass (TDD)

### Task 1: Scaffold + diff parser

**Files:**
- Create: `skills/critique/checks/diff-parse.mjs`
- Test: `skills/critique/checks/diff-parse.test.mjs`

**Interfaces:**
- Produces: `parseDiff(diffText: string) => Array<{ file: string, added: Array<{ line: number, text: string }> }>` — `line` is the new-file line number of each added line; `text` excludes the leading `+`.

- [ ] **Step 1: Write the failing test**

```js
// skills/critique/checks/diff-parse.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { parseDiff } from './diff-parse.mjs';

const SAMPLE = `diff --git a/app/Foo.php b/app/Foo.php
--- a/app/Foo.php
+++ b/app/Foo.php
@@ -10,3 +10,4 @@ class Foo
 context line
-old line
+new line one
+new line two
diff --git a/app/Bar.php b/app/Bar.php
--- a/app/Bar.php
+++ b/app/Bar.php
@@ -1,2 +1,3 @@
+first added
 unchanged`;

test('parseDiff groups added lines by file with new-file line numbers', () => {
  const files = parseDiff(SAMPLE);
  assert.equal(files.length, 2);
  assert.equal(files[0].file, 'app/Foo.php');
  assert.deepEqual(files[0].added, [
    { line: 11, text: 'new line one' },
    { line: 12, text: 'new line two' },
  ]);
  assert.equal(files[1].file, 'app/Bar.php');
  assert.deepEqual(files[1].added, [{ line: 1, text: 'first added' }]);
});

test('parseDiff returns empty array for empty input', () => {
  assert.deepEqual(parseDiff(''), []);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `node --test skills/critique/checks/diff-parse.test.mjs`
Expected: FAIL — `Cannot find module './diff-parse.mjs'`.

- [ ] **Step 3: Write minimal implementation**

```js
// skills/critique/checks/diff-parse.mjs

// Parse a unified diff into per-file added lines.
// `line` is the line number in the NEW file; `text` drops the leading '+'.
export function parseDiff(diffText) {
  const files = [];
  let current = null;
  let newLineNo = 0;

  for (const raw of diffText.split('\n')) {
    if (raw.startsWith('+++ ')) {
      const path = raw.slice(4).replace(/^b\//, '').trim();
      current = { file: path, added: [] };
      files.push(current);
      continue;
    }
    if (raw.startsWith('--- ')) continue;
    if (raw.startsWith('@@')) {
      const m = raw.match(/\+(\d+)/);
      newLineNo = m ? parseInt(m[1], 10) : 0;
      continue;
    }
    if (!current) continue;

    if (raw.startsWith('+')) {
      current.added.push({ line: newLineNo, text: raw.slice(1) });
      newLineNo++;
    } else if (raw.startsWith('-')) {
      // removed line: does not advance the new-file counter
    } else {
      newLineNo++; // context line
    }
  }
  return files;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `node --test skills/critique/checks/diff-parse.test.mjs`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add skills/critique/checks/diff-parse.mjs skills/critique/checks/diff-parse.test.mjs
git commit -m "feat(critique): unified diff parser for stage-0 checks"
```

---

### Task 2: `@php` in Blade — exact check

**Files:**
- Create: `skills/critique/checks/checks.mjs`
- Test: `skills/critique/checks/checks.test.mjs`

**Interfaces:**
- Consumes: `parseDiff` output (Task 1).
- Produces: `checkBladePhp(files) => Array<{ check: 'blade-php', file, line, text }>`.

- [ ] **Step 1: Write the failing test**

```js
// skills/critique/checks/checks.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { parseDiff } from './diff-parse.mjs';
import { checkBladePhp } from './checks.mjs';

test('checkBladePhp flags @php only in added Blade lines', () => {
  const diff = `+++ b/resources/views/x.blade.php
@@ -1,0 +1,2 @@
+@php $x = 1; @endphp
+<div>ok</div>
+++ b/app/Y.php
@@ -1,0 +1,1 @@
+// @php in a comment in a php file, not blade`;
  const findings = checkBladePhp(parseDiff(diff));
  assert.equal(findings.length, 1);
  assert.equal(findings[0].check, 'blade-php');
  assert.equal(findings[0].file, 'resources/views/x.blade.php');
  assert.equal(findings[0].line, 1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `node --test skills/critique/checks/checks.test.mjs`
Expected: FAIL — `Cannot find module './checks.mjs'`.

- [ ] **Step 3: Write minimal implementation**

```js
// skills/critique/checks/checks.mjs

export function checkBladePhp(files) {
  const findings = [];
  for (const f of files) {
    if (!f.file.endsWith('.blade.php')) continue;
    for (const { line, text } of f.added) {
      if (/@php\b/.test(text)) {
        findings.push({ check: 'blade-php', file: f.file, line, text: text.trim() });
      }
    }
  }
  return findings;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `node --test skills/critique/checks/checks.test.mjs`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add skills/critique/checks/checks.mjs skills/critique/checks/checks.test.mjs
git commit -m "feat(critique): exact check for @php in Blade"
```

---

### Task 3: Null-safety heuristic — `?->` and `??`

**Files:**
- Modify: `skills/critique/checks/checks.mjs`
- Modify: `skills/critique/checks/checks.test.mjs`

**Interfaces:**
- Produces: `checkNullSafety(files) => Array<{ check: 'null-safe-op'|'null-coalesce', file, line, text }>`.

- [ ] **Step 1: Write the failing test**

```js
// append to skills/critique/checks/checks.test.mjs
import { checkNullSafety } from './checks.mjs';

test('checkNullSafety flags ?-> anywhere and ?? outside config only', () => {
  const diff = `+++ b/app/Service.php
@@ -1,0 +1,2 @@
+$name = $user?->profile?->name;
+$fallback = $value ?? 'default';
+++ b/config/app.php
@@ -1,0 +1,1 @@
+'env' => env('APP_ENV') ?? 'production',`;
  const c = checkNullSafety(parseDiff(diff));
  const kinds = c.map((x) => `${x.check}@${x.file}`);
  assert.ok(kinds.includes('null-safe-op@app/Service.php'));
  assert.ok(kinds.includes('null-coalesce@app/Service.php'));
  // ?? inside config/ is a legitimate default, not a candidate
  assert.ok(!kinds.includes('null-coalesce@config/app.php'));
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `node --test skills/critique/checks/checks.test.mjs`
Expected: FAIL — `checkNullSafety is not a function`.

- [ ] **Step 3: Write minimal implementation**

```js
// append to skills/critique/checks/checks.mjs

export function checkNullSafety(files) {
  const candidates = [];
  for (const f of files) {
    if (!f.file.endsWith('.php')) continue;
    const inConfig = f.file.startsWith('config/');
    for (const { line, text } of f.added) {
      if (/\?->/.test(text)) {
        candidates.push({ check: 'null-safe-op', file: f.file, line, text: text.trim() });
      }
      if (!inConfig && /\?\?/.test(text)) {
        candidates.push({ check: 'null-coalesce', file: f.file, line, text: text.trim() });
      }
    }
  }
  return candidates;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `node --test skills/critique/checks/checks.test.mjs`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add skills/critique/checks/checks.mjs skills/critique/checks/checks.test.mjs
git commit -m "feat(critique): null-safety heuristic (?-> and ?? outside config)"
```

---

### Task 4: `->each(` on a possible builder — heuristic

**Files:**
- Modify: `skills/critique/checks/checks.mjs`
- Modify: `skills/critique/checks/checks.test.mjs`

**Interfaces:**
- Produces: `checkEachOnBuilder(files) => Array<{ check: 'each-on-builder', file, line, text }>`.

- [ ] **Step 1: Write the failing test**

```js
// append to skills/critique/checks/checks.test.mjs
import { checkEachOnBuilder } from './checks.mjs';

test('checkEachOnBuilder flags ->each( but not ->get()->each(', () => {
  const diff = `+++ b/app/Report.php
@@ -1,0 +1,2 @@
+User::query()->where('active', true)->each(fn (\$u) => \$u->touch());
+User::query()->where('active', true)->get()->each(fn (\$u) => \$u->touch());`;
  const c = checkEachOnBuilder(parseDiff(diff));
  assert.equal(c.length, 1);
  assert.equal(c[0].line, 1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `node --test skills/critique/checks/checks.test.mjs`
Expected: FAIL — `checkEachOnBuilder is not a function`.

- [ ] **Step 3: Write minimal implementation**

```js
// append to skills/critique/checks/checks.mjs

export function checkEachOnBuilder(files) {
  const candidates = [];
  for (const f of files) {
    if (!f.file.endsWith('.php')) continue;
    for (const { line, text } of f.added) {
      if (/->each\(/.test(text) && !/->get\(\)\s*->each\(/.test(text)) {
        candidates.push({ check: 'each-on-builder', file: f.file, line, text: text.trim() });
      }
    }
  }
  return candidates;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `node --test skills/critique/checks/checks.test.mjs`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add skills/critique/checks/checks.mjs skills/critique/checks/checks.test.mjs
git commit -m "feat(critique): each-on-builder heuristic"
```

---

### Task 5: Writes inside `database/migrations/` — heuristic

**Files:**
- Modify: `skills/critique/checks/checks.mjs`
- Modify: `skills/critique/checks/checks.test.mjs`

**Interfaces:**
- Produces: `checkMigrationWrites(files) => Array<{ check: 'migration-write', file, line, text }>`.

- [ ] **Step 1: Write the failing test**

```js
// append to skills/critique/checks/checks.test.mjs
import { checkMigrationWrites } from './checks.mjs';

test('checkMigrationWrites flags data writes only under database/migrations', () => {
  const diff = `+++ b/database/migrations/2026_01_01_000000_x.php
@@ -1,0 +1,2 @@
+        Schema::table('orders', fn (Blueprint \$t) => \$t->string('status'));
+        DB::table('orders')->update(['status' => 'active']);
+++ b/app/Actions/DoThing.php
@@ -1,0 +1,1 @@
+        DB::table('orders')->update(['x' => 1]);`;
  const c = checkMigrationWrites(parseDiff(diff));
  assert.equal(c.length, 1);
  assert.equal(c[0].file, 'database/migrations/2026_01_01_000000_x.php');
  assert.match(c[0].text, /DB::table/);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `node --test skills/critique/checks/checks.test.mjs`
Expected: FAIL — `checkMigrationWrites is not a function`.

- [ ] **Step 3: Write minimal implementation**

```js
// append to skills/critique/checks/checks.mjs

const MIGRATION_WRITE = /\bDB::|->insert\(|->update\(|->delete\(|::create\(|::insert\(/;

export function checkMigrationWrites(files) {
  const candidates = [];
  for (const f of files) {
    if (!/^database\/migrations\/.*\.php$/.test(f.file)) continue;
    for (const { line, text } of f.added) {
      if (MIGRATION_WRITE.test(text)) {
        candidates.push({ check: 'migration-write', file: f.file, line, text: text.trim() });
      }
    }
  }
  return candidates;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `node --test skills/critique/checks/checks.test.mjs`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add skills/critique/checks/checks.mjs skills/critique/checks/checks.test.mjs
git commit -m "feat(critique): migration data-write heuristic"
```

---

### Task 6: Changelog fragment — heuristic question

**Files:**
- Modify: `skills/critique/checks/checks.mjs`
- Modify: `skills/critique/checks/checks.test.mjs`

**Interfaces:**
- Produces: `checkChangelogFragment(files) => Array<{ check: 'changelog-fragment', question: string }>` — at most one element.

- [ ] **Step 1: Write the failing test**

```js
// append to skills/critique/checks/checks.test.mjs
import { checkChangelogFragment } from './checks.mjs';

test('checkChangelogFragment asks when code changed but no fragment added', () => {
  const codeOnly = `+++ b/app/Foo.php
@@ -1,0 +1,1 @@
+// change`;
  assert.equal(checkChangelogFragment(parseDiff(codeOnly)).length, 1);

  const withFragment = `+++ b/app/Foo.php
@@ -1,0 +1,1 @@
+// change
+++ b/.changelog/unreleased/feature-x.md
@@ -1,0 +1,1 @@
+<details>`;
  assert.equal(checkChangelogFragment(parseDiff(withFragment)).length, 0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `node --test skills/critique/checks/checks.test.mjs`
Expected: FAIL — `checkChangelogFragment is not a function`.

- [ ] **Step 3: Write minimal implementation**

```js
// append to skills/critique/checks/checks.mjs

export function checkChangelogFragment(files) {
  const hasCode = files.some(
    (f) => /\.(php|js|vue)$/.test(f.file) && f.added.length > 0,
  );
  const hasFragment = files.some(
    (f) => f.file.startsWith('.changelog/unreleased/') && f.file.endsWith('.md'),
  );
  if (hasCode && !hasFragment) {
    return [{
      check: 'changelog-fragment',
      question: 'No .changelog/unreleased/ fragment. Is this a user-visible change that needs one?',
    }];
  }
  return [];
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `node --test skills/critique/checks/checks.test.mjs`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add skills/critique/checks/checks.mjs skills/critique/checks/checks.test.mjs
git commit -m "feat(critique): changelog-fragment heuristic question"
```

---

### Task 7: Vendor-hack — exact filesystem check

**Files:**
- Create: `skills/critique/checks/vendor.mjs`
- Test: `skills/critique/checks/vendor.test.mjs`

**Interfaces:**
- Produces: `checkVendorHacks(repoRoot: string) => Array<{ check: 'vendor-hack', file }>` — files under `vendor/it4web/` newer than `vendor/composer/installed.json`.

- [ ] **Step 1: Write the failing test**

```js
// skills/critique/checks/vendor.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, mkdir, writeFile, utimes } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { checkVendorHacks } from './vendor.mjs';

test('checkVendorHacks finds it4web php files newer than installed.json', async () => {
  const root = await mkdtemp(join(tmpdir(), 'critique-vendor-'));
  await mkdir(join(root, 'vendor/composer'), { recursive: true });
  await mkdir(join(root, 'vendor/it4web/tallui'), { recursive: true });
  await writeFile(join(root, 'vendor/composer/installed.json'), '{}');
  const past = new Date(Date.now() - 60_000);
  await utimes(join(root, 'vendor/composer/installed.json'), past, past);
  await writeFile(join(root, 'vendor/it4web/tallui/Hacked.php'), '<?php');

  const findings = checkVendorHacks(root);
  assert.equal(findings.length, 1);
  assert.match(findings[0].file, /Hacked\.php$/);
  assert.equal(findings[0].check, 'vendor-hack');
});

test('checkVendorHacks returns empty when vendor/it4web is absent', async () => {
  const root = await mkdtemp(join(tmpdir(), 'critique-vendor-'));
  assert.deepEqual(checkVendorHacks(root), []);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `node --test skills/critique/checks/vendor.test.mjs`
Expected: FAIL — `Cannot find module './vendor.mjs'`.

- [ ] **Step 3: Write minimal implementation**

```js
// skills/critique/checks/vendor.mjs
import { execFileSync } from 'node:child_process';

// Files under vendor/it4web/ modified after the last composer install —
// the CLAUDE.md "vendor hack" check. Filesystem state, not the diff.
export function checkVendorHacks(repoRoot) {
  try {
    const out = execFileSync(
      'find',
      ['vendor/it4web/', '-newer', 'vendor/composer/installed.json', '-name', '*.php'],
      { cwd: repoRoot, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] },
    );
    return out.split('\n').filter(Boolean).map((file) => ({ check: 'vendor-hack', file }));
  } catch {
    return []; // vendor/it4web or installed.json absent → nothing to report
  }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `node --test skills/critique/checks/vendor.test.mjs`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add skills/critique/checks/vendor.mjs skills/critique/checks/vendor.test.mjs
git commit -m "feat(critique): vendor-hack filesystem check"
```

---

### Task 8: Dispatcher + CLI + candidate-cap guard

**Files:**
- Create: `skills/critique/checks/run-checks.mjs`
- Test: `skills/critique/checks/run-checks.test.mjs`

**Interfaces:**
- Consumes: every check from Tasks 2–7.
- Produces: `runChecks(diffText, repoRoot) => { exact: [...], heuristic: [...] }`. CLI: reads a diff on stdin, prints the result as JSON.

- [ ] **Step 1: Write the failing test**

```js
// skills/critique/checks/run-checks.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { runChecks } from './run-checks.mjs';

const COMBINED = `+++ b/resources/views/x.blade.php
@@ -1,0 +1,1 @@
+@php $x = 1; @endphp
+++ b/app/Service.php
@@ -1,0 +1,1 @@
+$n = $user?->name;`;

test('runChecks routes exact vs heuristic findings', () => {
  const { exact, heuristic } = runChecks(COMBINED, '/nonexistent-root');
  assert.ok(exact.some((f) => f.check === 'blade-php'));
  assert.ok(heuristic.some((f) => f.check === 'null-safe-op'));
  // changelog question fires: code changed, no fragment
  assert.ok(heuristic.some((f) => f.check === 'changelog-fragment'));
});

test('no single heuristic check exceeds 5 candidates on this diff', () => {
  const { heuristic } = runChecks(COMBINED, '/nonexistent-root');
  const byCheck = {};
  for (const f of heuristic) byCheck[f.check] = (byCheck[f.check] ?? 0) + 1;
  for (const [check, n] of Object.entries(byCheck)) {
    assert.ok(n <= 5, `${check} returned ${n} candidates (>5 relocates noise)`);
  }
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `node --test skills/critique/checks/run-checks.test.mjs`
Expected: FAIL — `Cannot find module './run-checks.mjs'`.

- [ ] **Step 3: Write minimal implementation**

```js
// skills/critique/checks/run-checks.mjs
import { parseDiff } from './diff-parse.mjs';
import {
  checkBladePhp,
  checkNullSafety,
  checkEachOnBuilder,
  checkMigrationWrites,
  checkChangelogFragment,
} from './checks.mjs';
import { checkVendorHacks } from './vendor.mjs';

export function runChecks(diffText, repoRoot) {
  const files = parseDiff(diffText);
  const exact = [
    ...checkBladePhp(files),
    ...checkVendorHacks(repoRoot),
  ];
  const heuristic = [
    ...checkChangelogFragment(files),
    ...checkNullSafety(files),
    ...checkEachOnBuilder(files),
    ...checkMigrationWrites(files),
  ];
  return { exact, heuristic };
}

// CLI: `git diff origin/main...HEAD | node run-checks.mjs`
if (import.meta.url === `file://${process.argv[1]}`) {
  let input = '';
  process.stdin.setEncoding('utf8');
  process.stdin.on('data', (c) => { input += c; });
  process.stdin.on('end', () => {
    const result = runChecks(input, process.cwd());
    process.stdout.write(JSON.stringify(result, null, 2) + '\n');
  });
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `node --test skills/critique/checks/run-checks.test.mjs`
Expected: PASS (2 tests).

- [ ] **Step 5: Run the whole Phase A suite**

Run: `node --test skills/critique/checks/`
Expected: PASS — all check tests green.

- [ ] **Step 6: Commit**

```bash
git add skills/critique/checks/run-checks.mjs skills/critique/checks/run-checks.test.mjs
git commit -m "feat(critique): stage-0 dispatcher, CLI, and candidate-cap guard"
```

---

## Phase B — Prose skill (writing-skills)

### Task 9: `references/rubrics.md`

**Files:**
- Create: `skills/critique/references/rubrics.md`

**Interfaces:**
- Consumes: nothing executable; read by the SKILL.md pipeline.
- Produces: the four mode rubrics (hazard classes + principles pointer), the category-slug list (including the stage-0 slugs `blade-php`, `vendor-hack`, `null-safe-op`, `null-coalesce`, `each-on-builder`, `migration-write`, `changelog-fragment`), and a drop-tally block.

- [ ] **Step 1: Invoke the authoring skill**

Announce and invoke `superpowers:writing-skills`. It governs the format of this reference file and the SKILL.md in Task 10.

- [ ] **Step 2: Write `rubrics.md`** transcribing, verbatim in structure, the spec's "Rubric structure" and "Modes" sections:
  - `pr` hazard classes: database / in flight / inside the app / outward-facing, each requiring a *hazard found / checked, clean / not applicable* verdict, reported as **conditional** hazards; package-repo weighting via `composer.json` `name` match.
  - `plan` questions (always vs plan-present subsets).
  - `missing` rules, including the **cite ≥2 in-repo paths** evidence requirement.
  - `alternatives` axis assignment and the distinctness pass.
  - Principles layer as a **pointer** to `~/GitProjects/LaravelClaudeMd/LaravelClaudeMd/CLAUDE.md`, not a copy.
  - Tier definitions: **Tier 3 = subjective preference with no named consequence**; a house-rule violation with a consequence is Tier 2.
  - A `## Drop tally` block: a table of `category-slug | drops`, with the rule "at 3 drops in a slug, propose a scoping amendment; approval required; never auto-apply".

- [ ] **Step 3: Commit**

```bash
git add skills/critique/references/rubrics.md
git commit -m "feat(critique): rubrics reference — modes, tiers, drop tally"
```

---

### Task 10: `SKILL.md`

**Files:**
- Create: `skills/critique/SKILL.md`

**Interfaces:**
- Consumes: `checks/run-checks.mjs` (Task 8), `references/rubrics.md` (Task 9).
- Produces: the skill entry point.

- [ ] **Step 1: Write the frontmatter and triggers** (via `writing-skills`)

```markdown
---
name: critique
description: Use when reviewing a design/plan before implementation or a change before merge — the plan, pr, alternatives, and missing review modes. Triggers on "/critique", "review this plan", "review this PR", "critique the design", "look for missing cases".
---
```

- [ ] **Step 2: Write the invocation + mode-inference section**, exactly matching the spec: the five forms, the `--verify` flag, and the inference order (spec-newer-than-HEAD → `plan`; any change vs `origin/main` incl. uncommitted → `pr`; else ask). State that the chosen mode is announced first.

- [ ] **Step 3: Write the reviewer contract** — crafted context (never session history) and read-only (never move `HEAD`; `git worktree add` for other revisions).

- [ ] **Step 4: Write the six-stage pipeline**, wiring the pieces:
  - **Stage 0:** assemble the target diff (`git diff origin/main...HEAD` + uncommitted, or `gh pr diff <n>`), pipe it to `node skills/critique/checks/run-checks.mjs`, and fold `exact` findings straight into the report and `heuristic` candidates into the Stage-2 prompt. State the assembled target ("N files, M uncommitted").
  - **Stages 1–5** as specified: assemble context, single Fable reviewer (model configurable) over the mode rubric, filter (drop no-scenario + Tier 3, cap 15, disclose remainder by slug), report table (claim ≤60 chars, tier, verdict *pr-only*, category, location, failure scenario) + overall verdict, triage dispositions pointing at `receiving-code-review`.
  - **Stage 6:** on a drop, increment the `rubrics.md` tally; at 3 in a slug, propose an amendment (approval required).

- [ ] **Step 5: Write the four mode sections** as thin pointers into `references/rubrics.md` (do not duplicate the rubric text).

- [ ] **Step 6: Commit**

```bash
git add skills/critique/SKILL.md
git commit -m "feat(critique): SKILL.md — pipeline, modes, reviewer contract"
```

---

### Task 11: Wire up, verify triggering, and smoke-test

**Files:**
- Create: symlink `~/.claude/skills/critique` → `skills/critique/`
- Verify: no new file.

- [ ] **Step 1: Symlink the skill** (matches the multi-repo setup in CLAUDE.md)

```bash
ln -sfn ~/GitProjects/LaravelClaudeMd/LaravelClaudeMd/skills/critique ~/.claude/skills/critique
ls -l ~/.claude/skills/critique
```

Expected: symlink resolves to the repo skill dir.

- [ ] **Step 2: Verify the full Phase A suite still passes**

Run: `node --test skills/critique/checks/`
Expected: PASS — every check test green.

- [ ] **Step 3: `writing-skills` trigger check** — confirm the description/triggers fire on the intended phrasings and not on unrelated ones, per the `writing-skills` verification guidance.

- [ ] **Step 4: Smoke-test `plan` mode on this very spec** (the spec's own validation item)

In a fresh session: `/critique plan docs/superpowers/specs/2026-07-23-critique-review-skill-design.md`
Expected: it announces `plan` mode, states which artifacts it found (design only), and surfaces ≥1 genuine unverified assumption, with a signal ratio (kept ÷ reported) above 60%. Record the result in the PR description; **do not** post it anywhere on GitHub.

- [ ] **Step 5: Smoke-test the stage-0 CLI on a real diff**

```bash
git diff origin/main...HEAD | node skills/critique/checks/run-checks.mjs
```

Expected: valid JSON `{ exact, heuristic }`; no heuristic check exceeds 5 candidates.

- [ ] **Step 6: Commit any fixes** surfaced by verification, then stop for review.

```bash
git add -A && git commit -m "chore(critique): wire up skill and record smoke-test results"
```

---

## Self-Review

**1. Spec coverage.** Every spec section maps to a task:
- Stage-0 exact/heuristic checks → Tasks 2–8 (the two cut checks, `if (!$x)` and hand-rolled-markup, are correctly absent — they live in the principles layer, Task 9).
- Modes, hazard classes, tiers, drop tally → Task 9.
- Invocation, inference, pipeline stages 1–6, reviewer contract, verdict format → Task 10.
- Whole-change target incl. uncommitted → Task 10 Stage 0 + Task 11 Step 5.
- Validation items → Task 11 (trigger check, plan smoke-test, candidate-cap in Task 8 test). Items needing several real targets (signal ratio over ten runs, fan-out A/B, already-merged-PR runs) are **post-merge validation**, not build tasks — flagged here so they are not forgotten.
- `--verify`, package weighting, `alternatives`/`missing` details → prose in Tasks 9–10; no executable component, so no dedicated task.

**2. Placeholder scan.** No "TBD"/"handle appropriately". Phase A steps carry complete code; Phase B steps are prose authored via `writing-skills` and enumerate their required content rather than deferring it.

**3. Type consistency.** Every check returns `{ check, file?, line?, text?|question? }`; `runChecks` returns `{ exact, heuristic }`; `parseDiff` returns `[{ file, added:[{line,text}] }]`. Names are identical across Tasks 1–8 and referenced unchanged in Task 10.

**Known limitation carried forward:** the signal-ratio, fan-out-A/B, and merged-PR-replay validations require real review runs and live in the spec's validation section as post-merge acceptance, not in this build plan.
