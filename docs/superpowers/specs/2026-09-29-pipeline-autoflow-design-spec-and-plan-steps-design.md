# `autoflow`'s design runs as a spec step and a plan step — design

**Design size:** Architectural

**Date:** 2026-09-29
**Issue:** IT4WEBBV/LaravelClaudeMd#73
**Canonical home:** `pipeline_steps()`, `pipeline_step()`, `pipeline_step_problem()` and
`pipeline_routing_tables()` in `skills/pipeline/checks/dispatch.php`; the design overrides in
`skills/pipeline/checks/brief.php`; the step loop in `skills/pipeline/workflow/pipeline-autoflow.js`;
pipeline `references/engine.md` (§`autoflow`, §Stations, §Design size) and `references/manifest.md`.
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. Every question the brainstorm
would have asked is answered in *Assumptions*, so `/critique plan` audits exactly those. Nothing below was
built or run.

## Problem

`autoflow`'s `design` leg is one agent (`design:run`): it brainstorms, writes and commits the spec, then
invokes `superpowers:writing-plans` and writes and commits the plan. Everything it read for the spec is
still in its context while it writes the plan.

The owner's measurement after #92 (comment on #73, 2026-09-29): across 13 `autoflow` runs whose design
started after #97, design no longer runs code (0 suite runs in 15 passes), yet the first-pass design peaks
stayed at 129k–332k, 6 of 13 at 211k or more; this repo's median went from 195k to 213k. The peak comes from
reading (whole-file reads of `brief.php`, `dispatch_cli.php` and the tests), not from prototyping. The
postponement was lifted; #72 (one agent per plan task) was closed, so no step returns `tasks`.

## Change

Split `autoflow`'s Architectural design into two steps the script runs in order, the way it runs `review`
and `resolve`:

- **`design:spec`** explores, brainstorms, writes and commits the spec, sets `artifacts.spec` and removes
  `artifacts.plan`. It stops where brainstorming hands over to `writing-plans`.
- **`design:plan`** is a fresh agent. It reads the committed spec cold, and the code the spec points at,
  invokes `writing-plans`, commits the plan at the path beside the spec and sets `artifacts.plan`.

Both return `size`, copied from `dispatch_cli.php size`, as `design:run` does today; the boundary check
compares it with the spec header after either step.

### Which steps a design has

| mode | size | steps |
|---|---|---|
| `interactive` | either | `run` (unchanged: the human designs inline, in one session) |
| `autoflow` | Architectural | `spec`, `plan` |
| `autoflow` | Bounded | `spec` only: it writes the ~15-line spec **and** the ~10-line plan, two commits, as `design:run` does today |

The size is known only once a spec exists, and on a `light` run brainstorming picks it. So `design:spec`
always runs first; the script reads the `size` it returned and skips `design:plan` when it says Bounded.

- `pipeline_steps(string $leg, string $mode)` takes the mode: `design` in `autoflow` is `['spec', 'plan']`,
  everywhere else `['run']`; the review legs keep `['review', 'resolve']`. Every caller has the mode:
  `pipeline_step()` and `pipeline_step_problem()` from the manifest, `pipeline_routing_tables()` and
  `dispatch_cli_brief_args()` are `autoflow`'s.
- `PIPELINE_BOUNDED_STEPS = ['design' => ['spec']]`: the legs whose steps differ on a Bounded design.
  `pipeline_routing_tables()` hands it to the script as `tables.bounded`.

### Which design step comes next — derived, as `review` / `resolve` is

`pipeline_step($manifest, 'design')` in `autoflow` is `pipeline_design_step($manifest)`:

- `spec` when `artifacts.spec` is empty;
- `plan` when `artifacts.spec` is set and `artifacts.plan` is empty (the spec step ran and cleared it);
- `plan` when the newest ledger entry is a plan return: `gate: plan-approval`, `outcome: looped-back`, and
  no `review` key (the entry a step writes when it returns `plan-insufficient` on an Architectural spec,
  `review-plan:review`'s included);
- `spec` otherwise: a `review-plan` loop-back (a resolved review, it has `review`) or a `design-size`
  escalation (the grow form). A navigation back to `design` (`--from design`) derives by the same rule:
  `plan` while the newest entry is still a plan return, `spec` otherwise.

This is what the snapshot check needs: `dispatch_cli_snapshot_step()` reads the step from the snapshot the
brief took before the step ran, and each of the pre-step states above names one step. `launch` gives the
same answer as `startStep`, so a resumed run starts at the step that has not run.

`pipeline_step_problem()` refuses a design step the manifest does not call for: `brief <manifest> design
plan` over a manifest whose next design step is `spec` halts, and so does the reverse. The script and the
PHP each route re-entry (below); a disagreement halts at the brief instead of running the wrong step.

### Loop-backs into design

The script decides from what it already has, the status and the size:

| what sent the run back | the step that returned | the script reruns |
|---|---|---|
| a plan gap: `plan-insufficient` on an Architectural design | any step after `design` | `design:plan` only |
| a review loop-back: `looped-back` from `review-plan:resolve` | `review-plan:resolve` | `design:spec`, then `design:plan` |
| an escalation: `plan-insufficient` on a Bounded design (the grow form) | any step after `design` | `design:spec`; it grows the spec to Architectural, so `design:plan` follows |

In the script: `from = gap && size !== 'Bounded' ? 'plan' : undefined` at the loop-back to `design`. The
ledger entry that goes with each status is already checked at the next brief (`pipeline_ledger_problem()`),
and `pipeline_design_step()` reads the same entry, so the choice stays mechanical on both sides.

### The script

- `stepsOf(leg)`: `tables.bounded[leg]` when the size is Bounded and the table has the leg, else
  `tables.steps[leg]`. It is re-read after each step, because `design:spec`'s return can change the size.
- The size is taken from every `continued` design step, not only after the leg.
- `complete()` requires `tables.bounded`: an object whose keys are legs and whose lists are non-empty and
  hold only that leg's steps.
- The start-step check uses `stepsOf(args.startLeg)`.
- `COPIED` stays keyed by leg, so both design steps return `size`, and the brief after either gets `--size`.

### The briefs

`pipeline_leg_overrides()` keeps `design:run` for `interactive` and adds:

- `design:spec` — invoke `superpowers:brainstorming` and stop at the spec: on the Architectural path the
  plan is `design:plan`'s, so the step does not invoke `writing-plans` and commits no plan. The
  `## Assumptions` line, the no-build line, the probe line and the pre-2026-09-14 line, as `design:run`
  has them. Commit the spec; on the Bounded path, commit the plan as a second commit (a Bounded design
  has no plan step). Then, after the last commit and in one manifest write, set `artifacts.spec` and
  remove `artifacts.plan`, or on the Bounded path set it to the plan. A step that halts before that write
  leaves the manifest calling for the spec step again, never for the plan step over a half-written spec
  or a Bounded spec with no plan.
- `design:plan` — read the committed spec cold and the code it points at, and invoke
  `superpowers:writing-plans` on it; do not re-design. Where the plan needs an answer the spec does not
  give, add the question and the assumed answer to the spec's `## Assumptions` and commit that before the
  plan. The no-build line and the pre-2026-09-14 line. Commit the plan; set `artifacts.plan`.
- The plan's path is derived from the spec's, by the naming both already follow
  (`pipeline_plan_path()`: `…/specs/<date>-<slug>-design.md` → `…/plans/<date>-<slug>.md`). The
  `design:plan` brief names it: when that file exists it is this design's plan, from an earlier pass or
  the Bounded plan the design grew from, and the step updates it in place and writes no second plan. The
  spec step removes the `artifacts.plan` pointer, not the file, so a review loop-back and the grow form
  reach the plan they extend, and a loop-back on a later day does not leave a second, dated plan beside
  a stale one. A spec named otherwise gets no path line, and `writing-plans` names the plan.

The grow-form line splits by step: `design:spec` grows the spec (header Architectural, a `## Grown from
Bounded` section), `design:plan` adds the remaining steps to the plan; `design:run` keeps today's line. The
plan-gap line is unchanged and reaches only `design:plan` in `autoflow`, since a gap never reruns the spec
step; it already lets the plan step extend the spec where it must say more.

### What does not change

- `pipeline_legs()`, navigation, gates, the ledger shape, `pipeline_loop_target()`, the bound.
- `run_cost.php` and `run_audit.php`: labels come from the agent's `label` (`design:spec`, `design:plan`),
  and `run_audit` counts no design status. Older transcripts keep `design:run`.
- `interactive` (`next`, `returned`, `pipeline_runs_inline()`).

### A run in flight when this lands

An `autoflow` run still on the old script asks for `brief … design run` or passes `--after design:run`.
The first halts cleanly (`design has no run step`); the second is a usage error (exit 1, the text on
stderr, no halt JSON) that the step agent has to read. A relaunch fixes both: `launch` hands out the new
tables and `startStep`. The PR body says so.

## Approaches considered

1. **Two steps of the `design` leg, the step derived from the manifest (chosen).** The pattern `review` /
   `resolve` already uses: nothing new is stored, `launch` resumes at the right step, and the boundary check
   identifies the snapshot's step from the snapshot.
2. **Store the step in the cursor (`cursor.step`).** Simpler derivation, but it adds a dispatcher-owned key
   inside the object every leg rewrites; `pipeline_keep_retried()` exists because legs drop such keys, and
   each drop here would be a halt ("the leg changed cursor.step").
3. **A separate `plan` leg.** Changes `pipeline_legs()`, navigation, the done-legs rule, `interactive`,
   `manifest_infer_cursor()` and every doc that lists the legs, for a split the issue scopes to the
   script's steps.

## Done when

- `AutoflowScriptTest`: an Architectural run labels `design:spec`, `design:plan`; a Bounded `design:spec`
  goes straight to `review-plan:review`; a plan gap from `implement` reruns only `design:plan`; a
  `review-plan` loop-back reruns `design:spec` and `design:plan`; a Bounded escalation reruns
  `design:spec` and then `design:plan`. The stub-step (smoke) replay walks a plan gap through the real
  `brief` to `design:plan` without a halt.
- `DispatchTest` / `DispatchCliTest`: `pipeline_steps()` per mode; `pipeline_design_step()` for each row of
  its rule; `brief … design plan` over a manifest that calls for `spec` halts, and the reverse; `launch`'s
  `startStep` and `tables.bounded`.
- `BriefTest`: `design:spec` and `design:plan` carry their lines; `interactive` `design:run` is unchanged;
  the grow-form line per step.
- `BriefTest`: the `design:plan` brief names the plan beside the spec, over a grown manifest too; the
  `design:spec` brief says to write `artifacts` last and to commit no plan on the Architectural path.
- After merge, not in this PR (#73's first criterion): over the next 5 Architectural `autoflow` runs in
  this repo, `run_cost_cli.php` shows `design:spec` and `design:plan`, and the median of each run's
  higher first-pass design peak (the larger of the two steps') is below 213k, the single step's median
  in the #92 measurement. See Assumption 11 for what follows if it is not.

## Assumptions

1. *Does `interactive` split too?* No. There the human drives the design inline in one session; a split
   would stop the brainstorm for a `/pipeline` re-invocation between spec and plan. The issue scopes the
   split to the script.
2. *How does a Bounded design stay one step when the size is only known after the spec?* `design:spec` runs
   first on every `autoflow` design and writes the plan too on the Bounded path; the script skips
   `design:plan` on the `size` it returned. The step's name says less than it does on Bounded, which is the
   price of choosing the steps before the size exists.
3. *Does a `review-plan` loop-back that asks only for plan changes rerun the spec step?* Yes, both run. The
   resolve step already integrates edits and small fixes itself and loops back only for a design that is
   fundamentally wrong (engine.md §Resolving a review), and the spec step's pointer to the entry limits it to
   what the review names. The rerun stays decidable from the status alone, with no new ledger field.
4. *Is `review-plan:review`'s `plan-insufficient` a plan gap here?* Yes: its entry is a plan return (no
   `review` key), so only `design:plan` reruns, as the issue says of a plan gap. `pipeline_is_plan_gap()`
   still excludes it, for `run_audit` and the brief's plan-gap line, and is not changed.
5. *How does the snapshot check know which design step ran?* By deriving it from the pre-step manifest, the
   way `review` / `resolve` is derived. That needs the spec step to remove `artifacts.plan`; a plan written
   for an earlier spec is not this spec's plan, so an empty `artifacts.plan` means what it says.
6. *May `design:plan` change the spec?* Only its `## Assumptions` (a gap the cold read found), and on a plan
   gap where the spec must say more, as the plan-gap line already allows. Each spec change is committed
   before the plan.
7. *Does `design:plan` return `tasks`?* No: #72 is closed.
8. *Do `run_cost` and `run_audit` need changes?* No: see *What does not change*. The issue lists
   `run_cost` labels; they follow from the agent labels.
9. *Where does the re-entry rule live in the script?* Next to the `design` loop-back target the script
   already hard-codes; the PHP derivation and the brief's refusal check it at every design step, and a
   stub-step replay proves they agree.
10. No probe was needed: no approach hinged on whether something works at all.
11. *What if the split does not lower the peak?* The measurement puts the peak in whole-file reads of
    `brief.php`, `dispatch_cli.php` and the tests, and `design:plan` reads the code the spec points at to
    write verbatim test and code bodies: its reads alone may reach the old peak, which moves the peak to
    the plan step and adds one agent's fixed cost. This is not measurable before merge. The criterion
    under *Done when* decides it: if the median does not fall below 213k, #73 is reopened with the
    numbers, and the plan step's reads (not a further split) are the next change.
12. *Can a resume land on the plan step of a Bounded design, or over a half-revised spec?* Not through
    the spec step: it writes `artifacts` once, after its last commit, so a halt before that write leaves
    the manifest calling for the spec step. Only a hand-edited manifest (spec set, plan empty, a Bounded
    header) reaches it; `launch` then halts with `design has no plan step`, and the fix is to set
    `artifacts.plan` or clear `artifacts.spec`. `pipeline_design_step()` stays size-blind, since the size
    reader (`dispatch_cli_design_size()`) reads files and `dispatch.php` does not.
13. *Which plan file does the plan step write?* The one beside the spec (`pipeline_plan_path()`), so a
    rerun updates the plan it is extending instead of writing a new dated file; see *The briefs*.
