# design:plan after a review-plan plan return gets the plan-gap line Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task by task. Steps use checkbox (`- [ ]`) syntax for tracking. In an `autoflow` implement step, execute inline, task by task, with no subagents.

**Goal:** A `design` brief whose newest ledger entry is `review-plan:review`'s own `plan-insufficient` (a plan return) carries the same plan-gap line a later-leg plan gap gets: extend the existing plan for the entry's `reason`, and leave the entry unchanged (#113).

**Architecture:**
- `skills/pipeline/checks/brief.php`: the design plan-gap line in `pipeline_brief_overrides()` is given on `pipeline_is_plan_return()` instead of `pipeline_is_plan_gap()`. The line's text does not change.
- Docs in the same task: the `pipeline_is_plan_return()` docblock in `skills/pipeline/checks/pipeline.php`, and engine.md §Design size, *A plan gap on an Architectural design*, step 3.

**Tech Stack:** PHP 8.4 on the host, Pest 4, Markdown skill references.

**Spec:** `docs/superpowers/specs/2026-09-30-pipeline-plan-return-brief-line-design.md`

## Global Constraints

- Suite, from the worktree root on the host: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`. `vendor/` is missing in a fresh worktree: run `composer install --no-interaction --quiet` first.
- The design plan-gap line stays verbatim: ``Plan gap: extend the plan (and the spec where it must say more) to cover the entry's `reason`; describe what is already built as state, do not re-design it (engine.md §Design size). Leave that entry as it is, with no `actions`: what you did goes in the spec, the plan and the reason you return.``
- Unchanged: `pipeline_is_plan_gap()` and its readers (`pipeline_reset_at()`, `pipeline_done_legs()`, `pipeline_entry_kind()`, `run_audit.php`); `pipeline_design_step()`; `pipeline_plan_gap_lines()`; `pipeline_ledger_problem()`; `manifest.md`; `pipeline-autoflow.js`.
- engine.md headings do not change and the new sentence names no `§`: `LockStepTest` resolves the brief's `§` names against the headings.
- This repo has no `.changelog/` and no `CHANGELOG.md`: no changelog entry. The PR closes #113.

## Review Focus

1. **A `review-plan:resolve` loop-back does not get the line.** It carries `review` (and `actions`), so it is no plan return; the design step re-designs what the review named. Task 1's modified test pins it.
2. **A plan return that is no longer the newest entry gives no line.** Once `review-plan` has approved the extended plan (a `continued` entry with `review`), a later design brief must not tell the step to answer the old `reason` again. Task 1's new test pins it.
3. **`interactive`'s `design:run` gets the line too** (spec *Assumptions* 4): `brief_manifest()` defaults to `interactive`, so Task 1's modified test briefs `design:run`.
4. **The route and the brief agree.** In `autoflow` the manifest itself must pick `design:plan` for a plan return and that brief must carry the line; Task 1's new test calls `pipeline_brief()` without a step, so `pipeline_design_step()` picks it (spec *Assumptions* 8).
5. **A later-leg plan gap keeps the line.** The `$gap` case (`leg: implement`) in the existing test stays as it is.

---

## File Structure

- Modify `skills/pipeline/checks/brief.php` (the condition), `skills/pipeline/checks/pipeline.php` (a docblock).
- Modify `skills/pipeline/checks/tests/BriefTest.php`.
- Modify `skills/pipeline/references/engine.md`.

---

### Task 1: the design brief gives the plan-gap line on every plan return

**Files:**
- Modify: `skills/pipeline/checks/brief.php:210-212` (`pipeline_brief_overrides()`)
- Modify: `skills/pipeline/checks/pipeline.php:103-107` (the `pipeline_is_plan_return()` docblock)
- Modify: `skills/pipeline/references/engine.md:694-699` (§Design size, *A plan gap on an Architectural design*, step 3)
- Test: `skills/pipeline/checks/tests/BriefTest.php:150-152` and a new test

**Interfaces:**
- Consumes, all existing: `pipeline_brief(array $manifest, string $leg, string $manifestPath, ?string $step = null, ?callable $git = null): string` (with no step it resolves `pipeline_step()`, which for an `autoflow` design is `pipeline_design_step()`); `pipeline_is_plan_return(array $entry): bool` in `pipeline.php`; the test helper `brief_manifest(string $leg, array $extra = []): array` (mode `interactive` unless overridden).
- Produces: nothing a later task calls.

- [ ] **Step 1: Write the failing tests.** In `BriefTest.php`, the test *tells a later leg how to report a plan gap, and design to extend the plan for it*, replace

```php
    $reviewLoop = [...$gap, 'leg' => 'review-plan'];
    expect(pipeline_brief(brief_manifest('design', ['gate_ledger' => [$reviewLoop]]), 'design', '/tmp/m.json'))
        ->not->toContain('Plan gap');
```

with

```php
    $reviewReturn = [...$gap, 'leg' => 'review-plan'];
    expect(pipeline_brief(brief_manifest('design', ['gate_ledger' => [$reviewReturn]]), 'design', '/tmp/m.json'))
        ->toContain('- redo what `gate_ledger[0]` looped back for')
        ->toContain('Plan gap: extend the plan')
        ->toContain('Leave that entry as it is, with no `actions`: what you did goes in the spec, the plan and the reason you return.');

    $reviewLoop = [...$reviewReturn, 'review' => 'r', 'actions' => [['claim' => 'x', 'disposition' => 'integrated', 'note' => 'n']]];
    expect(pipeline_brief(brief_manifest('design', ['gate_ledger' => [$reviewLoop]]), 'design', '/tmp/m.json'))
        ->toContain('- redo what `gate_ledger[0]` looped back for')
        ->not->toContain('Plan gap');
```

Then append a new test after it:

```php
it('tells autoflow\'s plan step to extend the plan review-plan returned as insufficient, and to leave that entry alone', function () {
    $resolved = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-30T09:00:00Z', 'review' => 'r', 'actions' => [['claim' => 'x', 'disposition' => 'integrated', 'note' => 'n']], 'outcome' => 'looped-back'];
    $return = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 2, 'at' => '2026-09-30T10:00:00Z', 'reason' => 'needs a queue', 'outcome' => 'looped-back'];
    $approved = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 3, 'at' => '2026-09-30T11:00:00Z', 'review' => 'ok', 'outcome' => 'continued'];
    $artifacts = ['spec' => 'docs/superpowers/specs/2026-09-30-x-design.md', 'plan' => 'docs/superpowers/plans/2026-09-30-x.md', 'pr' => null, 'issue' => null];
    $manifest = fn (array $ledger) => brief_manifest('design', ['mode' => 'autoflow', 'artifacts' => $artifacts, 'gate_ledger' => $ledger]);

    expect(pipeline_brief($manifest([$resolved, $return]), 'design', '/tmp/m.json'))
        ->toContain('`design` leg, `plan` step')
        ->toContain('- redo what `gate_ledger[1]` looped back for')
        ->toContain('- The plan goes at `docs/superpowers/plans/2026-09-30-x.md`, beside the spec')
        ->toContain('- Plan gap: extend the plan (and the spec where it must say more) to cover the entry\'s `reason`')
        ->toContain('Leave that entry as it is, with no `actions`: what you did goes in the spec, the plan and the reason you return.');

    expect(pipeline_brief($manifest([$resolved, $return, $approved]), 'design', '/tmp/m.json', 'spec'))
        ->not->toContain('Plan gap');
});
```

The last expectation names the step because, with the plan set and no plan return newest, the manifest itself picks `spec` (`pipeline_design_step()`); naming it keeps the case independent of that routing.

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='plan gap|review-plan returned as insufficient'`
Expected: FAIL: *tells a later leg how to report a plan gap, and design to extend the plan for it* on `toContain('Plan gap: extend the plan')` for `$reviewReturn`, and *tells autoflow's plan step to extend the plan review-plan returned as insufficient* on `toContain('- Plan gap: extend the plan …')`. Its earlier expectations (`plan` step, `gate_ledger[1]`, the plan path) already pass. The other tests the filter matches pass.

- [ ] **Step 3: Change the condition.** In `brief.php`, `pipeline_brief_overrides()`, replace

```php
    if ($leg === 'design' && pipeline_is_plan_gap(end($ledger) ?: [])) {
```

with

```php
    if ($leg === 'design' && pipeline_is_plan_return(end($ledger) ?: [])) {
```

The line inside the branch does not change.

- [ ] **Step 4: Run them to see them pass**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='plan gap|review-plan returned as insufficient'`
Expected: PASS.

- [ ] **Step 5: The docblock.** In `pipeline.php`, replace

```php
/**
 * The entry a step appends when it returns `plan-insufficient` on an Architectural design: a `plan-approval`
 * loop-back that no review wrote. Unlike `pipeline_is_plan_gap()` it counts `review-plan:review`'s own; a
 * `review-plan` loop-back is a resolved review and carries its `review`.
 */
```

with

```php
/**
 * The entry a step appends when it returns `plan-insufficient` on an Architectural design: a `plan-approval`
 * loop-back that no review wrote. Unlike `pipeline_is_plan_gap()` it counts `review-plan:review`'s own; a
 * `review-plan` loop-back is a resolved review and carries its `review`. It sends `autoflow` to `design:plan`
 * (`pipeline_design_step()`) and gives the design brief its plan-gap line (`pipeline_brief_overrides()`).
 */
```

- [ ] **Step 6: engine.md, step 3.** In §Design size, *A plan gap on an Architectural design*, replace

```markdown
   (updating the existing PR) and `implement` run again, as after an escalation. In `autoflow` only
   `design:plan` reruns (*`autoflow`'s design*).
```

with

```markdown
   (updating the existing PR) and `implement` run again, as after an escalation. In `autoflow` only
   `design:plan` reruns (*`autoflow`'s design*). `review-plan:review`'s own `plan-insufficient` is
   answered the same way, and its `design` brief carries the same plan-gap line: it is a plan return
   (`pipeline_is_plan_return()`), though no plan gap for `pipeline_done_legs()` or `run_audit.php` (#113).
```

- [ ] **Step 7: The whole suite**

Run: `php -l skills/pipeline/checks/brief.php && php -l skills/pipeline/checks/pipeline.php && ./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: `No syntax errors detected` twice, then PASS, 0 failed (`LockStepTest` included: no heading changed, and the brief line's `§Design size` still resolves; `DispatchTest`'s `pipeline_is_plan_return()` cases are untouched).

- [ ] **Step 8: Commit**

```bash
git add skills/pipeline/checks/brief.php skills/pipeline/checks/pipeline.php skills/pipeline/checks/tests/BriefTest.php skills/pipeline/references/engine.md
git commit -m "feat(pipeline): design briefs a review-plan plan return with the plan-gap line (#113)"
```
