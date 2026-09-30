# design:plan after a review-plan plan return gets the plan-gap line — design

**Design size:** Architectural

**Date:** 2026-09-30
**Issue:** IT4WEBBV/LaravelClaudeMd#113 (follow-up from the #73 review, PR #110, *Open questions*)
**Canonical home:** the design plan-gap line in `pipeline_brief_overrides()`
(`skills/pipeline/checks/brief.php`); `pipeline_is_plan_return()` (`skills/pipeline/checks/pipeline.php`);
pipeline `references/engine.md` (§Design size, *A plan gap on an Architectural design*).
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. Every question the brainstorm
would have asked is answered in *Assumptions*, so `/critique plan` audits exactly those. Nothing below was
built or run.

## Problem

`review-plan:review` may return `plan-insufficient` on an Architectural design: its brief carries
`pipeline_plan_gap_lines('review')`, so it appends `{gate: plan-approval, leg: review-plan, cycle, at,
reason, outcome: looped-back}` with no `review` and returns. That entry is a **plan return**
(`pipeline_is_plan_return()`: a `plan-approval` loop-back with no `review` key), so
`pipeline_design_step()` sends the run to `design:plan` alone (engine.md, *`autoflow`'s design*, the
rerun table: "a plan gap; `review-plan:review`'s counts too").

The `design:plan` brief then says, from `pipeline_brief_pointers()`:

> - redo what `gate_ledger[N]` looped back for

but not the plan-gap line, because `pipeline_brief_overrides()` gives that line only when
`pipeline_is_plan_gap(end($ledger))` holds, and `pipeline_is_plan_gap()` excludes `leg: review-plan`
(`skills/pipeline/checks/brief.php`, the `if ($leg === 'design' && pipeline_is_plan_gap(...))` branch):

> Plan gap: extend the plan (and the spec where it must say more) to cover the entry's `reason`; describe
> what is already built as state, do not re-design it (engine.md §Design size). Leave that entry as it is,
> with no `actions`: what you did goes in the spec, the plan and the reason you return.

So the step is told neither to extend the existing plan for the entry's `reason` nor to leave the entry
alone. A step that records its answer on the entry is halted by the next brief's ledger check
(`pipeline_ledger_problem()`: *design added actions to ledger entry N (plan-approval)*), the #104 class of
halt. `design:run` in `interactive` had the same omission before #110; #110's spec (Assumption 4) kept
`pipeline_is_plan_gap()` unchanged on purpose. Now that this route runs a step whose main instruction is
"invoke `writing-plans`", a fresh plan written beside, or over, the reviewed one is the likelier misread.

The existing test pins the omission: `BriefTest.php`, *tells a later leg how to report a plan gap, and
design to extend the plan for it*, asserts that a `design` brief over `[...$gap, 'leg' => 'review-plan']`
(no `review` key: a plan return) does **not** contain `Plan gap`.

## Approaches

1. **Give the line on `pipeline_is_plan_return()` (chosen; the issue's direction).** One condition in
   `pipeline_brief_overrides()` changes. `pipeline_is_plan_return()` holds for every plan gap (a gap entry
   has no `review` by definition, `manifest.md` *A plan gap*) and for `review-plan:review`'s own return,
   and not for a `review-plan:resolve` loop-back (which carries `review`). It is already the predicate
   that routes the run to `design:plan`, so the brief and the route agree on which entries the plan step
   answers.
2. **Widen `pipeline_is_plan_gap()` to include `leg: review-plan`.** Rejected: it is also read by
   `pipeline_reset_at()` / `pipeline_done_legs()`, `pipeline_entry_kind()` and `run_audit.php`, which
   counts `review-plan`'s own return as a `review-plan` loop-back, not a gap
   (`RunAuditTest.php`, *counts a review-plan step's plan gap or escalation as a review-plan loop-back*).
   #110 Assumption 4 keeps it as is for those readers.
3. **A separate line for a review-plan return** ("Plan return: …"). Rejected: what the step must do is the
   same as for a gap (extend the plan for `reason`, leave the entry unchanged), and the issue asks for "the
   same" instruction. A second wording is a second thing to keep in step.

## Design

### The condition (`pipeline_brief_overrides()`, `brief.php`)

```php
if ($leg === 'design' && pipeline_is_plan_return(end($ledger) ?: [])) {
    $lines[] = 'Plan gap: extend the plan …';   // the line itself unchanged
}
```

The line's text does not change. Its effect by newest ledger entry:

| newest entry | `is_plan_gap` | `is_plan_return` | line today | line after |
|---|---|---|---|---|
| plan gap (`leg` implement / handoff / verify-ui / review-pr, no `review`) | yes | yes | yes | yes |
| `review-plan:review`'s return (`leg: review-plan`, no `review`) | no | yes | **no** | **yes** |
| `review-plan:resolve` loop-back (`review`, `actions`) | no | no | no | no |
| `design-size` escalation, or no entry | no | no | no | no |

It applies on every design step: `design:plan` in `autoflow` (the route this issue is about) and
`design:run` in `interactive`, where `pipeline_route()` sends an Architectural `plan-insufficient` from
`review-plan:review` back to `design` through `pipeline_loop_back()` (via `pipeline_returned()`). `design:spec` never runs on a plan
return in `autoflow` (`pipeline_design_step()` picks `plan`), and `brief` refuses the other step.

"Describe what is already built as state" reads harmlessly for a return found before `handoff`: nothing is
built yet, so there is nothing to describe (*Assumptions* 2).

### Docs

- `pipeline.php`, the `pipeline_is_plan_return()` docblock: add that the design brief's plan-gap line is
  given on it too.
- engine.md, *A plan gap on an Architectural design*, step 3: one sentence that `review-plan:review`'s own
  `plan-insufficient` (a plan return, `pipeline_is_plan_return()`) is answered the same way, and its
  `design` brief carries the same line, though it is no plan gap for `pipeline_done_legs()` or
  `run_audit.php`. No new `§` name, so `LockStepTest` is unaffected.

### What does not change

`pipeline_is_plan_gap()` and its readers (`pipeline_reset_at()`, `pipeline_done_legs()`,
`pipeline_entry_kind()`, `run_audit.php`); `pipeline_design_step()`; `pipeline_plan_gap_lines()`; the
plan-gap line's wording; `pipeline_ledger_problem()`; `manifest.md`; `pipeline-autoflow.js`.

## Tests

Written first, seen red:

- `BriefTest.php`, *tells a later leg how to report a plan gap, and design to extend the plan for it*: the
  `$reviewLoop` case (`[...$gap, 'leg' => 'review-plan']`) flips from `not->toContain('Plan gap')` to
  `toContain('Plan gap: extend the plan')`; a `review-plan:resolve` loop-back (the same entry plus
  `review` and `actions`) takes its place as the case that does **not** get the line.
- `BriefTest.php`, new: an `autoflow` `design:plan` brief (`pipeline_brief($manifest, 'design', …,
  'plan')`) over a manifest with `artifacts.spec` and `artifacts.plan` set whose ledger ends with
  `{gate: plan-approval, leg: review-plan, cycle: 2, at, reason: 'needs a queue', outcome: looped-back}`
  (after an earlier `review-plan` entry, so the index is not 0) contains `- redo what
  \`gate_ledger[1]\` looped back for`, `Plan gap: extend the plan` and `Leave that entry as it is, with no
  \`actions\``. Red today on the second and third.

The whole pipeline suite passes (`vendor/bin/pest -c skills/pipeline/checks/phpunit.xml
--test-directory=skills/pipeline/checks/tests`, the form of `composer test` for the critique checks).

## Out of scope

- Renaming the line to cover both kinds, or a separate "plan return" wording (*Approaches* 3).
- `pipeline_entry_kind()` naming a review-plan return `plan gap` in a halt reason; it keeps `plan-approval`
  (*Assumptions* 3).
- A `DispatchCliTest` replay of a design that writes onto a review-plan return: the ledger check is
  entry-kind agnostic and #104's replay already covers it.

## Assumptions

Questions the brainstorm would have asked the owner, with the answer assumed.

1. **Should the line change wording for a review-plan return?** No: the issue asks for "the same"
   instruction, and the step's duties are identical.
2. **Does "describe what is already built as state" mislead a step answering a review-plan return found
   before anything is built?** No: with nothing built there is nothing to describe; the clause guards a
   later-leg gap and costs nothing here.
3. **Should the halt reason call a review-plan return a `plan gap` too (`pipeline_entry_kind()`)?** No:
   the halt names the entry by index, which is unambiguous, and `pipeline_entry_kind()` shares
   `pipeline_is_plan_gap()` with `run_audit.php`'s notion of a gap; changing it is beyond the issue.
4. **Does `interactive`'s `design:run` get the line too?** Yes: the overrides are built the same in both
   modes, and the same entry sends `interactive` back to `design`.
5. **Does this PR close #113?** Yes; `review-pr` settles the closing links (engine.md §Closing links).
6. **Changelog?** This repository has no `.changelog/` and no `CHANGELOG.md`, so none is written.
7. **Which `cycle` does the new test's plan return carry?** `2`: it follows a `review-plan` entry of cycle
   1, and `pipeline_next_cycle()` counts every `plan-approval` entry. The brief does not read the value;
   the test keeps it coherent. (Added by the plan step.)
8. **Does the new test name the step, or let the manifest pick it?** It lets the manifest pick it:
   `pipeline_brief()` without a step resolves `pipeline_step()` → `pipeline_design_step()`, so one
   assertion pins both that the route goes to `design:plan` and that the brief it gets carries the line.
   (Added by the plan step.)
