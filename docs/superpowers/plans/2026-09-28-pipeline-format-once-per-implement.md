# `format` once per `implement` step Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task by task. Steps use checkbox (`- [ ]`) syntax for tracking. In an `autoflow` implement step, execute inline, task by task, with no subagents.

**Goal:** `implement` runs a repo's `format` check once per step, over the whole tree, before its last suite run and the push, instead of after every plan step; engine.md records the Pint cache measurement that makes a changed-files list unnecessary.

**Architecture:**
- `skills/pipeline/checks/brief.php`: the `implement:run` line in `pipeline_leg_overrides()` (both modes) says when `format` runs.
- Docs: engine.md §Mechanical checks (*What runs, and when*, plus a Pint cache paragraph) and §Stations (the `implement` row); pipeline `SKILL.md`'s *Mechanical checks* bullet.
- Nothing else: no `checks.php` placeholder, no workflow script change.

**Tech Stack:** PHP 8.4 on the host, Pest 4, Markdown skill references.

**Spec:** `docs/superpowers/specs/2026-09-28-pipeline-format-once-per-implement-design.md`

## Global Constraints

- Suite, from the worktree root on the host: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`. `vendor/` is missing in a fresh worktree: run `composer install --no-interaction --quiet` first.
- The new brief line, verbatim: ``Test-first; after each plan step the suite and `static-analysis`; `format` once, over the whole tree, when the code is complete: before the last suite run and the push, its changes committed, and again only after a later change (engine.md §Mechanical checks, §Suite reuse). Record `suite` after every full run.``
- The engine.md headings `## Mechanical checks — the deterministic layer inside \`implement\`` and `## Suite reuse — once per tree` do not change: `LockStepTest` resolves the brief's § names against them.
- `checks.php`, `pipeline-autoflow.js`, `dispatch.php`, the `review-pr` brief lines and viewiemedia's config do not change.
- This repo has no `.changelog/` and no `CHANGELOG.md`: no changelog entry.

## Review Focus

1. **Both modes.** `pipeline_leg_overrides()` builds `implement:run` once for both modes; the test asserts the line in `autoflow` and in `interactive`.
2. **The § names in the new line.** `LockStepTest` reads `§Mechanical checks` and `§Suite reuse` out of every brief line with `/§([^,):;]+)/`; the line keeps the comma between them and the closing parenthesis after the second.
3. **The old wording is gone everywhere it lived:** `brief.php`, engine.md's `implement` row, `SKILL.md`. Task 2's greps pin each.
4. **Suite reuse.** The rule places `format` before the last suite run; the engine.md text says why (a Pint change after the suite changes the tree key and costs a second full suite at `review-pr`).

---

## File Structure

- Modify `skills/pipeline/checks/brief.php`, `skills/pipeline/checks/tests/BriefTest.php`.
- Modify `skills/pipeline/references/engine.md`, `skills/pipeline/SKILL.md`.

---

### Task 1: the `implement` brief line

**Files:**
- Modify: `skills/pipeline/checks/brief.php:49`
- Test: `skills/pipeline/checks/tests/BriefTest.php`

**Interfaces:**
- Consumes: `pipeline_brief(array $manifest, string $leg, string $manifestPath, ?string $step = null): string` and the test helper `brief_manifest(string $leg, array $extra = []): array`, both existing.
- Produces: the `implement:run` line above; Task 2's docs describe the same rule.

- [ ] **Step 1: Write the failing test.** Append to `BriefTest.php`:

```php
it('runs format once per implement step, before the last suite run and the push, in both modes', function () {
    foreach (['autoflow', 'interactive'] as $mode) {
        expect(pipeline_brief(brief_manifest('implement', ['mode' => $mode]), 'implement', '/tmp/m.json'))
            ->toContain('Test-first; after each plan step the suite and `static-analysis`; `format` once, over the whole tree, when the code is complete: before the last suite run and the push, its changes committed, and again only after a later change (engine.md §Mechanical checks, §Suite reuse). Record `suite` after every full run.')
            ->not->toContain('after each step the suite and the mechanical checks');
    }
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='runs format once per implement step'`
Expected: FAIL, the `toContain` on the new line (the brief still says *after each step the suite and the mechanical checks*).

- [ ] **Step 3: Change the line.** In `brief.php`, `pipeline_leg_overrides()`, `'implement:run'`, replace

```php
            'Test-first; after each step the suite and the mechanical checks (engine.md §Mechanical checks, §Suite reuse). Record `suite` after every full run.',
```

with

```php
            'Test-first; after each plan step the suite and `static-analysis`; `format` once, over the whole tree, when the code is complete: before the last suite run and the push, its changes committed, and again only after a later change (engine.md §Mechanical checks, §Suite reuse). Record `suite` after every full run.',
```

- [ ] **Step 4: Run the test and the suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='runs format once per implement step'`
Expected: PASS.

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, 0 failed (`LockStepTest`'s *keeps every engine.md section a brief names* included).

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/brief.php skills/pipeline/checks/tests/BriefTest.php
git commit -m "feat(pipeline): implement brief: format once per step, before the last suite run and the push (#79)"
```

---

### Task 2: engine.md and `SKILL.md`

**Files:**
- Modify: `skills/pipeline/references/engine.md` (§Stations, the `implement` row; §Mechanical checks, *What runs, and when*)
- Modify: `skills/pipeline/SKILL.md:31-33`

**Interfaces:**
- Consumes: Task 1's rule, stated in the same terms.
- Produces: nothing code reads, except the two headings `LockStepTest` resolves, which stay as they are.

- [ ] **Step 1: The `implement` row.** In engine.md §Stations, replace

```markdown
execute the plan **test-first, running the suite and the repo's mechanical checks after each step** (§Mechanical checks)
```

with

```markdown
execute the plan **test-first, running the suite and the repo's `static-analysis` after each step and its `format` once before the push** (§Mechanical checks)
```

- [ ] **Step 2: *What runs, and when*.** In §Mechanical checks, replace

```markdown
**What runs, and when.** After each step: the test suite (skipped when §Suite reuse finds this tree
already green), then `static-analysis` over the whole
declared scope, then `format` over the whole tree. **No file lists and no diff-scoping** — measured
on Deploy, scoping to two files costs 4.7s against 11.1s for all of `app/` because the analyser's
bootstrap is a fixed ~4.5s floor, and paying that 6.4s removes host→container path mapping,
touched-file tracking, and any need for a pre-ready backstop.
```

with

```markdown
**What runs, and when.** After each plan step: the test suite (skipped when §Suite reuse finds this
tree already green), then `static-analysis` over the whole declared scope.
`format` runs **once per `implement` step**, over the whole tree, when the step's code is complete:
before its last suite run, so the recorded `suite` covers the formatted tree (a Pint change after the
suite changes the tree key and costs a second full suite at `review-pr`), and before the push, with
what it changed committed. A change after it (the fix for a red suite or a finding) runs it once more.
**No file lists and no diff-scoping** for `static-analysis` — measured
on Deploy, scoping to two files costs 4.7s against 11.1s for all of `app/` because the analyser's
bootstrap is a fixed ~4.5s floor, and paying that 6.4s removes host→container path mapping,
touched-file tracking, and any need for a pre-ready backstop.

**Pint's cache makes every call after the first cheap.** Measured on viewiemedia (#79: 1299 files,
Pint 1.32), a whole-tree run takes ~39 s with an empty cache and ~1.3 s with a warm one. Pint keeps
its cache in the container's temp dir without being told to, and viewiemedia mounts `/tmp` on a named
volume, so only the first call in a fresh stack pays. A changed-files list would save that one call
and nothing after it, so there is none.
```

- [ ] **Step 3: `SKILL.md`.** Replace

```markdown
- **Mechanical checks** — `implement` also runs a repo's PHPStan/Pint checks after each step when
  the repo declares them in a committed `## Checks` block (`references/engine.md`
  §Mechanical checks). Opt-in: repos that have not declared them are unaffected.
```

with

```markdown
- **Mechanical checks** — `implement` also runs a repo's PHPStan check after each step and its Pint
  check once before the push, when the repo declares them in a committed `## Checks` block
  (`references/engine.md` §Mechanical checks). Opt-in: repos that have not declared them are unaffected.
```

- [ ] **Step 4: Check and run the suite**

Run: `grep -rn "mechanical checks after each step\|PHPStan/Pint\|after each step the suite and the mechanical checks" skills/pipeline --exclude-dir=tests`
Expected: no output (`BriefTest.php` quotes the old line in its `not->toContain`, hence the exclusion).

Run: `grep -c 'runs \*\*once per `implement` step\*\*\|Pint.s cache makes every call after the first cheap' skills/pipeline/references/engine.md`
Expected: `2`.

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, 0 failed.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/references/engine.md skills/pipeline/SKILL.md
git commit -m "docs(pipeline): engine.md: format once per implement step; Pint's cache measured, no file list (#79)"
```
