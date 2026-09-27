# `design` reads the plan's code, it does not run it Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task by task. Steps use checkbox (`- [ ]`) syntax for tracking. In an `autoflow` implement step, execute inline, task by task, with no subagents.

**Goal:** the `design:run` brief tells design not to build or run the plan's code, allows one narrow probe to choose between approaches, and drops the *Verified before writing* header from the plan format; engine.md §What design proves holds the why.

**Architecture:**
- `skills/pipeline/references/engine.md` gains a `##` section, **What design proves — reading, not running**, after §Design size and before §The proof store, and one sentence in the not-exemplars paragraph of §What a leg brief consists of.
- `pipeline_leg_overrides()` in `skills/pipeline/checks/brief.php` gains two `design:run` lines and changes the exemplar line; the new line names `§What design proves`, which `LockStepTest` checks against engine.md's headings.
- `BriefTest.php` pins the three lines in both modes.

**Tech Stack:** PHP 8.4 on the host, Pest 4, Markdown skill references.

**Spec:** `docs/superpowers/specs/2026-09-27-pipeline-design-reads-not-runs-design.md`

## Global Constraints

- The three brief lines are exactly the spec's §The `design:run` overrides lines 3–5; Task 2 Step 3 carries them as PHP (single-quoted, `\'` for each apostrophe) and Task 2 Step 1 pins them verbatim.
- Order of `design:run`: brainstorming line, Assumptions line, the two new lines, the changed exemplar line, the commit line. The same in `autoflow` and `interactive`.
- The engine.md heading is exactly `## What design proves — reading, not running` (an em dash, as the other headings), so `LockStepTest`'s regex `^## (.+?)(?: — .*)?$` reads it as `What design proves`.
- Nothing else changes: not `pipeline-autoflow.js`, `dispatch.php`, other legs' overrides, `SKILL.md`, `gates.md`, `manifest.md`, the `/critique` rubric, or the five existing plans that carry the header.
- Suite, from the worktree root on the host: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests` (run `composer install --no-interaction --quiet` first if `vendor/` is missing).
- This repo has no `.changelog/` and no `CHANGELOG.md`, so there is no changelog entry.

## Review Focus

1. **Task order.** The brief line names `§What design proves`; if it lands before the engine.md section, `LockStepTest` (*keeps every engine.md section a brief names*) goes red. Task 1 adds the section first, and Task 2 runs `LockStepTest` after the brief change.
2. **The apostrophes.** `plan's` appears in all three lines inside PHP single quotes; an unescaped `'` is a parse error that takes the whole suite down. Task 2 Step 4 runs `php -l` on `brief.php`.
3. **The old exemplar line must be gone, not kept beside the new one.** The new test asserts `for test or proof policy.` (with the full stop) is absent.
4. **The exception must not read as licence to run the plan's code.** Lines 3 and 4 sit next to each other, and line 4 names `never the plan's code, never the suite`; the test pins it verbatim.
5. **Both modes.** The test loops over `autoflow` and `interactive`, since the `design:run` overrides do not depend on the mode today and must not start to.

---

## File Structure

- Modify `skills/pipeline/references/engine.md`: new section before `## The proof store — where the visual record actually lives`; one sentence appended to the not-exemplars paragraph in §What a leg brief consists of.
- Modify `skills/pipeline/checks/brief.php`: the `'design:run'` array in `pipeline_leg_overrides()` (lines 25–30 today).
- Modify `skills/pipeline/checks/tests/BriefTest.php`: one new test after *permits the design size the invocation allowed*.

---

### Task 1: engine.md §What design proves

**Files:**
- Modify: `skills/pipeline/references/engine.md`

**Interfaces:**
- Consumes: nothing.
- Produces: the heading `## What design proves — reading, not running`, which Task 2's brief line names as `§What design proves`.

- [ ] **Step 1: Add the section.** Insert this block immediately before the line `## The proof store — where the visual record actually lives` (after the paragraph ending `in \`interactive\` \`pipeline_returned()\` halts on either.`), with one blank line on each side:

```markdown
## What design proves — reading, not running

`design` writes a spec and a plan; **it does not build or run the plan's code**, in a scratch copy or
anywhere else. It confirms the signatures, APIs and paths the plan relies on by reading them, `php -l`
or grep. The plan's `Expected:` lines are predictions: `implement` proves them, test-first (§Stations),
and a plan that falls short comes back as a plan gap (§Design size).

Why (#92): before this rule every `autoflow` design in this repo built the plan's code in a scratch copy
and ran the suite there, 5–10 suite calls per design, with design peaks here of 119k–269k against
131–156k for viewiemedia designs that did not; `implement` then re-typed the same code. The plan became
a diff in prose, so `review-plan` reviewed code instead of design.

**The one exception is a probe.** When the choice between approaches hinges on whether one of them
works at all, `design` answers that one question with throwaway code: a few lines run on their own,
never the plan's code, never the suite. The question and what the probe showed go into the spec, beside
the approach they decided (owner, #92). The probe is brainstorming's *Spike* steps used as one step
inside an Architectural design, not a third design size: a Spike ends in a reported recommendation with
no spec and no plan, which a run cannot finish on, so the pipeline never classifies a work item as Spike
(§Design size). The probe's terminal state is its sentence in the spec.

**A plan carries no *Verified before writing* header.** The plans that have one are records and stay as
they are; they are not exemplars for it (§What a leg brief consists of).
```

- [ ] **Step 2: Point the not-exemplars paragraph at it.** In §What a leg brief consists of, replace

```markdown
**Plans and specs committed before 2026-09-14 are not exemplars** for test or proof policy. Many carry
the rules below, and a design subagent that reads them as examples copies the rules forward.
```

with

```markdown
**Plans and specs committed before 2026-09-14 are not exemplars** for test or proof policy. Many carry
the rules below, and a design subagent that reads them as examples copies the rules forward. No plan is
an exemplar for a *Verified before writing* header either (§What design proves).
```

- [ ] **Step 3: Check the heading is found.**

Run: `grep -n '^## What design proves — reading, not running$' skills/pipeline/references/engine.md`
Expected: one line, numbered between the `### A plan gap on an Architectural design` line and the `## The proof store` line.

Run: `grep -n 'header either (§What design proves)' skills/pipeline/references/engine.md`
Expected: one line, in §What a leg brief consists of.

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter=LockStep`
Expected: PASS (nothing names the section yet; the existing sections are untouched).

- [ ] **Step 4: Commit**

```bash
git add skills/pipeline/references/engine.md
git commit -m "docs(pipeline): engine.md §What design proves: design reads, implement runs (#92)"
```

---

### Task 2: the `design:run` brief lines

**Files:**
- Modify: `skills/pipeline/checks/brief.php` (the `'design:run'` array in `pipeline_leg_overrides()`)
- Test: `skills/pipeline/checks/tests/BriefTest.php`

**Interfaces:**
- Consumes: Task 1's heading, as `§What design proves`. Existing: `pipeline_brief(array $manifest, string $leg, string $manifestPath, ?string $step = null): string` and the test helper `brief_manifest(string $leg, array $extra = []): array`, both unchanged.
- Produces: nothing new for other code.

- [ ] **Step 1: Write the failing test.** In `BriefTest.php`, add after the test `it('permits the design size the invocation allowed', …);`:

```php
it('tells design to confirm by reading, probe only to choose, and leave the Expected lines to implement', function () {
    foreach (['autoflow', 'interactive'] as $mode) {
        expect(pipeline_brief(brief_manifest('design', ['mode' => $mode]), 'design', '/tmp/m.json'))
            ->toContain('Do not build or run the plan\'s code, in a scratch copy or anywhere else: confirm the signatures and APIs it relies on by reading, `php -l` or grep; `implement` proves the plan\'s Expected lines (engine.md §What design proves).')
            ->toContain('The one exception: when the choice between approaches hinges on whether one of them works at all, answer that question with a throwaway probe (a few lines run on their own, never the plan\'s code, never the suite) and write the question and what the probe showed into the spec.')
            ->toContain('Plans and specs committed before 2026-09-14 are not exemplars for test or proof policy, and no plan\'s `Verified before writing` header is part of the format.')
            ->not->toContain('for test or proof policy.');
    }
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='tells design to confirm by reading'`
Expected: FAIL, the first `toContain` (the brief has no `Do not build or run the plan's code` line yet).

- [ ] **Step 3: Change the overrides.** In `brief.php`, replace the `'design:run'` array with:

```php
        'design:run' => [
            'Invoke `superpowers:brainstorming`; on the Architectural path it hands over to `superpowers:writing-plans` (engine.md §Design size).',
            'Where brainstorming would ask the human, write each question and the answer you assumed into the spec\'s `## Assumptions` section, so `/critique plan` audits exactly those.',
            'Do not build or run the plan\'s code, in a scratch copy or anywhere else: confirm the signatures and APIs it relies on by reading, `php -l` or grep; `implement` proves the plan\'s Expected lines (engine.md §What design proves).',
            'The one exception: when the choice between approaches hinges on whether one of them works at all, answer that question with a throwaway probe (a few lines run on their own, never the plan\'s code, never the suite) and write the question and what the probe showed into the spec.',
            'Plans and specs committed before 2026-09-14 are not exemplars for test or proof policy, and no plan\'s `Verified before writing` header is part of the format.',
            'Commit the spec, then the plan: two commits. Set `artifacts.spec` and `artifacts.plan`.',
        ],
```

- [ ] **Step 4: Run the test, the lock-step test and the suite**

Run: `php -l skills/pipeline/checks/brief.php`
Expected: `No syntax errors detected in skills/pipeline/checks/brief.php`

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='tells design to confirm by reading|keeps every engine.md section a brief names'`
Expected: PASS, 2 tests.

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, 0 failed; one test more than on `main`.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/brief.php skills/pipeline/checks/tests/BriefTest.php
git commit -m "feat(pipeline): design:run brief: read, do not run, the plan's code; probe only to choose (#92)"
```
