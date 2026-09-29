# The plan-gap line applies only to a gap, and a plan-gap entry is read-only — design

**Design size:** Architectural

**Date:** 2026-09-29
**Issues:** IT4WEBBV/LaravelClaudeMd#96, and #104 folded in by the owner
**Canonical home:** `pipeline_plan_gap_lines()` and the design plan-gap line in
`skills/pipeline/checks/brief.php`; `pipeline_ledger_problem()` in `skills/pipeline/checks/dispatch.php`;
pipeline `references/manifest.md` (*A plan gap*) and `references/engine.md` (§Design size, *A plan gap on
an Architectural design*; §Failure policy).
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. Every question the brainstorm
would have asked is answered in *Assumptions*, so `/critique plan` audits exactly those. Nothing below was
built or run.

## Problem

Two misreads of the same plan-gap routing, one on each side of the loop-back.

**#96: the line that reports a gap reads as an approval gate.** `pipeline_plan_gap_lines()` gives every step
after `design` except a resolve step (`review-plan:review`, `handoff:run`, `implement:run`, `verify-ui:run`,
`review-pr:review`) this line:

> On an Architectural spec, append a `plan-approval` entry with `leg`, `cycle`, `at`, `reason` and outcome
> `looped-back` before returning `plan-insufficient`.

It is meant for a step that has found a plan gap, but nothing in it says so. In #92's autoflow run
(2026-09-27) `handoff:run` read it as a rule for every Architectural spec: it wrote a plan-gap entry whose
reason named no gap (*"autoflow does not hand off an Architectural design without the owner's plan
approval"*) and returned `plan-insufficient`; the next `design:run` found nothing to extend and halted. Cost:
one extra design step (0.12M) and a halt for the owner. #87's `handoff` got the same line and read it right,
so it happens sometimes.

**#104: design answers a gap by writing onto the gap entry.** In an autoflow run for IT4WEBBV/Retenium#1297,
`implement` returned `plan-insufficient` and appended the plan-gap entry (`gate_ledger[1]`). `design`
extended the spec and plan correctly and then also wrote an `actions` array onto that entry. The next brief
halted with *"the leg rewrote ledger entry 1"*: `pipeline_ledger_problem()` lets only a resolve step complete
its own open entry, and every other earlier entry must come back unchanged. The halt was right; the brief
had never said the entry is read-only, and `manifest.md` presents `actions` as where "the resolve step or
human" records what was done, which a design step answering a gap reasonably reaches for. The halt reason
named neither the leg nor the key, so the repair (restore `gate_ledger[1]` from `<manifest stem>.before.json`)
took reading the snapshot. Cost: a whole design leg (0.35M, 4.5 min), an empty `review-plan:review` start,
and the owner's recovery.

## Settled direction (owner, 2026-09-29)

Verbatim in the manifest's `decisions`:

1. The plan-gap line in `pipeline_plan_gap_lines()` becomes conditional: it applies only once a step has
   found a plan gap.
2. The design brief says the plan-gap ledger entry stays unchanged, and what design did goes in the spec, the
   plan and the step's reason.
3. `references/manifest.md` lists the plan-gap entry's fields as closed (no `actions`).
4. `pipeline_ledger_problem()` names the leg and the key in its halt, e.g. *design added actions to ledger
   entry 1 (plan gap)*.
5. No check that refuses a plan-gap reason naming no file or task (#96's second bullet): wording only.
6. The PR closes both #96 and #104.

## Approaches

1. **Wording in the briefs and the references, plus a halt reason that names what changed (chosen).** The
   directions settle it: both misreads come from a line that says too little, and the check that caught
   #104 already exists and only needs to say more.
2. **Let `design` complete the plan-gap entry, as a resolve step completes its review** (widen
   `pipeline_ledger_problem()`'s exception to the newest plan gap on a design step). Rejected: the entry is
   the loop-back's record, counted by `pipeline_loop_back()` and `pipeline_loop_counts()` and read by
   `pipeline_done_legs()` and `run_audit.php`; a design step that may rewrite it may also rewrite its
   `outcome` or `cycle`. Direction 3 closes it instead.
3. **A `pipeline_reported_problem()` check on a gap reason that names no file, behaviour or task** (#96's
   second bullet). Rejected by direction 5: a reason is prose, and deciding whether it names part of the
   plan is judgement a check cannot make.

## Design

### The lines a later step gets (`pipeline_plan_gap_lines()`)

The two non-resolve lines become a parallel pair, each opening with the size it applies to and saying
that it applies only on its trigger:

- Bounded, today *"While the spec's header says `**Design size:** Bounded`, run the escalation check first
  (engine.md §Design size); on escalation append the `design-size` entry and return
  `plan-insufficient`."*, becomes:

  ``On a Bounded spec (its header says `**Design size:** Bounded`): run the escalation check first (engine.md §Design size), and only on escalation append the `design-size` entry and return `plan-insufficient`.``

- Architectural, today the line quoted in *Problem*, becomes:

  ``On an Architectural spec: only when the plan falls short of what this step needs (files or behaviour it does not name), append a `plan-approval` entry with `leg`, `cycle`, `at`, a `reason` naming what the plan lacks, and outcome `looped-back`, then return `plan-insufficient`. The size alone is no gap: an Architectural plan needs no approval beyond `review-plan`'s.``

The last sentence answers the #92 reading directly: that run's `handoff` gave the size as its reason. The
review step's extra line (*"When you return `plan-insufficient`, append no review entry."*) and the resolve
step's line stay as they are.

### The line `design` gets on a plan gap (`pipeline_brief_overrides()`)

Today:

> Plan gap: extend the plan (and the spec where it must say more) to cover the entry's `reason`; describe
> what is already built as state, do not re-design it (engine.md §Design size).

It gains one sentence:

``Plan gap: extend the plan (and the spec where it must say more) to cover the entry's `reason`; describe what is already built as state, do not re-design it (engine.md §Design size). Leave that entry as it is, with no `actions`: what you did goes in the spec, the plan and the reason you return.``

"The reason you return" is the `reason` of the step's `{status, reason}` in `autoflow` and its reply line in
`interactive`: design writes no `cursor.reason` unless it halts (the brief's `## Return`). The grow-form
line for a `design-size` entry is left as it is (*Assumptions* 3).

### The halt names the leg, the key and the entry (`pipeline_ledger_problem()`)

Today every rewritten earlier entry halts with *"the leg rewrote ledger entry {i}"*. It becomes
*"{leg} {change} ledger entry {i} ({kind})"*:

- `{change}` is what differs between the entry before the step and after it, top-level keys only, in this
  order and joined with ` and `: `added <keys> to`, `changed <keys> on`, `removed <keys> from`, keys joined
  with `, `. An entry missing afterwards is `removed`; one that is no longer an object is `replaced`.
- On a resolve step's own open entry only the kept keys count (`gate`, `leg`, `cycle`, `at`, `review`,
  `annotations`), as today; the keys it may add (`actions`, `outcome`, `issue_links`) are not a change.
- `{kind}` is `plan gap` when `pipeline_is_plan_gap()` holds for the entry as it was, else its `gate`.

Examples: *design added actions to ledger entry 1 (plan gap)* (#104), *review-plan changed annotations on
ledger entry 0 (plan-approval)*, *handoff changed outcome on ledger entry 0 (plan-approval)*, *implement added
actions to and changed reason on ledger entry 1 (plan gap)*, *handoff removed ledger entry 0 (plan-approval)*.

Two small functions in `dispatch.php` beside `pipeline_ledger_problem()`: `pipeline_entry_change(array $old,
mixed $new, ?array $only): ?string` returns the `{change}` text or null when the entry is unchanged, and
`pipeline_entry_kind(array $entry): string` returns `{kind}`. `pipeline_is_plan_gap()` lives in
`pipeline.php`, which every caller of `pipeline_ledger_problem()` (`dispatch_cli.php`, `run_audit.php`,
the Pest bootstrap) already loads. Both the interactive `pipeline_returned()` and autoflow's
`pipeline_reported_problem()` go through `pipeline_return_problem()`, so both halt with the new reason.
The comparison runs on the normalized (key-sorted) manifests, as today, so key order is still not a change.

### Docs

- `manifest.md`, *A plan gap*: the fields are closed: `gate`, `leg`, `cycle`, `at`, `reason` and
  `outcome: looped-back`, **and nothing else**: no `review` and no `actions`, because nothing reviews it and
  no step completes it. The `design` step that answers it leaves it unchanged; what design did goes in the
  spec, the plan and the reason it returns. A step that changes it halts the run
  (*What a leg writes*).
- engine.md, *A plan gap on an Architectural design*: step 1 says the size alone is never a gap (the #92
  misread, with a pointer to #96); step 3 says `design` leaves the entry unchanged, what it did going in the
  spec, the plan and the reason it returns.
- engine.md §Failure policy, *A halted manifest is the one the check rejected*: the repair from
  `<manifest stem>.before.json` also applies when the reason names a ledger entry the leg rewrote, e.g.
  *design added actions to ledger entry 1 (plan gap)*. Today it names only "a key the leg was not allowed to
  change", and `gate_ledger` is a key the leg may change, so #104's repair was not plainly covered.

### What does not change

`LegStatus`, `pipeline_route()`, `pipeline_reported_problem()`'s own checks, `pipeline-autoflow.js`, the
resolve step's plan-gap line, the review step's no-review-entry line, `implement:run`'s *"Files or behaviour
the plan does not name"* line, `run_audit.php`.

## Tests

Written first, seen red:

- `BriefTest.php`:
  - `handoff:run`, `implement:run`, `verify-ui:run` and `review-pr:review` (a dataset) carry the new
    Architectural and Bounded lines verbatim and not the old Architectural line;
  - the existing *tells a later leg how to report a plan gap* test pins the new Architectural line instead of
    the old one;
  - `design` after a plan gap carries *"Leave that entry as it is, with no `actions`"*.
- `ReturnedTest.php` (interactive, `pipeline_returned()`): the halt reason, exactly, for design adding
  `actions` to a plan gap, a resolve step changing `annotations` on its open entry, a step removing an
  entry, and two kinds of change at once.
- `DispatchCliTest.php` (autoflow, the next `brief`): #104 replayed: a `design:run` that adds `actions` to
  the plan-gap entry halts `brief review-plan review --after design:run` with *design added actions to
  ledger entry 1 (plan gap)*; the existing *a rewritten ledger entry* case expects *handoff changed outcome on
  ledger entry 0 (plan-approval)* instead of *the leg rewrote ledger entry 0*.
- `LockStepTest` still passes; no override line gains a new `§` name.

The whole pipeline suite passes.

## Out of scope

- A check on the content of a plan-gap `reason` (direction 5).
- The same read-only clause on the grow-form line for a `design-size` entry (*Assumptions* 3).
- `run_audit.php` flagging a gap whose reason names no gap: it compares the ledger with what the steps
  reported, and #92's run agreed with itself.

## Assumptions

Questions the brainstorm would have asked the owner, with the answer assumed.

1. **Does "keep the Bounded line consistent" (#96) change what the Bounded line asks?** No: only its shape.
   It already applies only on escalation; it now opens the same way as the Architectural line (*On a …
   spec:*) and says *only on escalation*, so the pair reads as two conditions, not one rule and one gate.
2. **Should the new Architectural line say what a good `reason` looks like?** Yes, briefly: *"a `reason`
   naming what the plan lacks"*. Direction 5 rules out a check, not the wording, and the #92 reason named the
   size instead of a gap.
3. **Does the grow-form line (after a `design-size` escalation) get the same "leave the entry" clause?** No.
   The directions name the plan-gap entry; neither issue reports a write onto a `design-size` entry; and if one happens,
   the halt now names the leg, the key and `(design-size)`, so the repair is as plain as for a plan gap.
4. **How does the halt reason name several changes, or a missing entry?** As in *The halt names the leg…*:
   the verbs in a fixed order joined with *and*, and *removed* / *replaced* for an entry that is gone or no
   longer an object. The direction gives one example; the rest follows its form.
5. **Leg, or leg and step, in the halt reason?** The leg, as in the direction's example. The step is already
   in the cursor the halt leaves behind.
6. **Does `interactive` get the same lines?** Yes. `pipeline_plan_gap_lines()` and the design plan-gap line
   are built the same in both modes, and `pipeline_returned()` shares the ledger check.
7. **Does this PR close #96 and #104?** Both (direction 6). `review-pr` settles the closing links
   (engine.md §Closing links).
8. **Changelog?** This repository has no `.changelog/` and no `CHANGELOG.md`, so none is written.
