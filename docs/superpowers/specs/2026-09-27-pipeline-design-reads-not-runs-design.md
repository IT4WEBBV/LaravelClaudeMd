# `design` reads the plan's code, it does not run it — design

**Design size:** Architectural

**Date:** 2026-09-27
**Issue:** IT4WEBBV/LaravelClaudeMd#92
**Canonical home:** the `design:run` overrides in `skills/pipeline/checks/brief.php`, pinned by
`skills/pipeline/checks/tests/BriefTest.php`, and a new section of pipeline `references/engine.md`,
§What design proves, that holds the rule and why.
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. Every question the brainstorm
would have asked is answered in *Assumptions*, so `/critique plan` audits exactly those. This design
follows its own rule: nothing below was built or run in a scratch copy; every signature it relies on was
read.

## Problem

In every `autoflow` run in this repo the `design` step builds the plan's code in a scratch copy and runs
the suite there: 5–10 suite calls per design (#65, #68, #69, #70, #71, #78; viewiemedia #2114 in a
smaller form). Design peaks: #70 269k (1.18M weighted), #69 198k, #71 195k, #68 185k, #78 141k, #65 119k;
viewiemedia designs that did not do it peaked at 131–156k. `implement` then re-types the verified code
from the plan (its peaks 82–148k).

Nothing asks for it. The `design:run` overrides only invoke `superpowers:brainstorming` →
`superpowers:writing-plans` and ask for two commits. The pressure comes from writing-plans' `Expected:
FAIL/PASS` lines and its type-consistency self-review, and from `/critique plan`'s *"what is stated as
fact but never verified?"*. It spread through exemplar plans: #69's plan carries a header
`**Verified before writing (2026-09-24):** … 244 tests`, and the designs of #71, #70 and #78 read
earlier plans as a format reference and repeated it. Five plans in `docs/superpowers/plans/` now carry
the header.

The cost is a design that does `implement`'s work once already, and a plan that is a diff in prose, so
`review-plan` reviews code rather than design.

## Settled direction (owner, 2026-09-25)

- The `design:run` brief says: do not build or run the plan's code; confirm the signatures and APIs it
  relies on by reading, `php -l` or grep; `implement` proves the plan's Expected lines.
- Plans no longer carry a *Verified before writing* header. Existing plans stay as they are (records);
  the brief's exemplar line says the header is not part of the format.

The owner added on the issue (2026-09-25): *"there is sometimes a use case if the brainstorm needs to
decide between multiple approaches and cannot tell beforehand which will actually work … Sometimes you
need to experiment."*

## Approaches

1. **Brief lines plus one engine.md section (chosen).** The `design:run` overrides carry the rule in
   one line and point at a new engine.md section that holds the why, the measurements and the probe
   exception. The exemplar line gains the header. This is how every other brief rule is built: the
   brief points, engine.md explains (§What a leg brief consists of).
2. **Brief lines only.** Smallest diff, but the numbers and the probe exception would live nowhere a
   later reader of engine.md finds them, and the brief would have to restate them.
3. **Also change `writing-plans` or `/critique plan`'s rubric.** `writing-plans` is an upstream
   superpowers skill this repo does not own; the rubric question *"what is stated as fact but never
   verified, and what would verify it?"* is still right. A plan's Expected lines answer *what would
   verify it*: `implement`'s test-first step. Out of scope (Assumption 4).

## Design

### The `design:run` overrides

`pipeline_leg_overrides()` returns, for `design:run`, in this order (the same in `autoflow` and
`interactive`):

1. `Invoke \`superpowers:brainstorming\`; …` — unchanged.
2. `Where brainstorming would ask the human, …` — unchanged.
3. **New:** ``Do not build or run the plan's code, in a scratch copy or anywhere else: confirm the
   signatures and APIs it relies on by reading, `php -l` or grep; `implement` proves the plan's Expected
   lines (engine.md §What design proves).``
4. **New:** `The one exception: when the choice between approaches hinges on whether one of them works
   at all, answer that question with a throwaway probe (a few lines run on their own, never the plan's
   code, never the suite) and write the question and what the probe showed into the spec.`
5. **Changed:** ``Plans and specs committed before 2026-09-14 are not exemplars for test or proof
   policy, and no plan's `Verified before writing` header is part of the format.``
6. `Commit the spec, then the plan: two commits. …` — unchanged.

Line 3 names `§What design proves`, so `LockStepTest`'s *keeps every engine.md section a brief names*
guards the new section. Lines 3 and 4 sit together so the exception reads as one, bounded by the line
it excepts.

### engine.md §What design proves

A new `##` section, **What design proves — reading, not running**, placed after §Design size (after
its *A plan gap on an Architectural design* subsection) and before §The proof store. It says:

- `design` writes a spec and a plan; it does not build or run the plan's code. It confirms the
  signatures, APIs and paths the plan relies on by reading them, `php -l` or grep. The plan's
  `Expected:` lines are predictions; `implement` proves them, test-first (§Stations).
- Why: the measurements in *Problem*, in two sentences.
- The probe exception (line 4 above), attributed to the owner on #92.
- A plan carries no *Verified before writing* header; the plans that have one are records, left as
  they are, and not exemplars for it.

The not-exemplars paragraph in §What a leg brief consists of gains one sentence pointing at the new
section, so a reader of that paragraph finds the header rule too.

### What does not change

`pipeline-autoflow.js`, `dispatch.php`, the manifest schema, every other leg's overrides, `SKILL.md`,
`gates.md`, `manifest.md`, the `/critique` rubric, and the plans that already carry the header.

## Tests

`BriefTest.php` gains one test, written first and seen red against today's `brief.php`:

- *tells design to confirm by reading, probe only to choose, and leave the Expected lines to
  implement*: in both `autoflow` and `interactive`, the `design` brief contains line 3, line 4 and the
  changed line 5 verbatim, and no longer contains the old line 5 alone (it ends in `policy.`, so the
  test asserts `for test or proof policy.` is absent).

`LockStepTest`'s existing section test covers the new `§What design proves` pointer. The whole pipeline
suite passes.

The issue's second Done-when line, *a run after the merge shows no scratch-copy suite runs in
`design`*, is measured on the next runs with `run_cost_cli.php`, not in this PR.

## Out of scope

- Editing the five existing plans' headers: they are records (owner, settled).
- #72 and #73: decided after the measurement this change enables.
- `review-plan` and `implement` briefs (Assumptions 4 and 5).

## Assumptions

Questions the brainstorm would have asked the owner, with the answer assumed.

1. **Does the owner's comment about experimenting change the settled line?** Assumed it adds a narrow
   exception, not a loophole: a throwaway probe of one question that decides between approaches, never
   the plan's code, never the suite, and its result written into the spec. Building the plan's code in
   a scratch copy stays forbidden. The `experiment` skill is not named: its Iron Law needs the user's
   confirmation, which an `autoflow` step cannot get.
2. **Where does a probe's result go?** Into the spec, beside the approach it decided, not into
   *Assumptions*: a probed answer is not an assumed one, and `/critique plan` should read it as
   evidence.
3. **Is `php -l` right for every repo?** Assumed yes as written: every repo the pipeline runs in today
   is PHP (this one and Laravel projects). The line lists it beside reading and grep, so a non-PHP
   repo still has two ways.
4. **Should `/critique plan`'s rubric or the `review-plan` brief change?** Assumed no. *"What is stated
   as fact but never verified, and what would verify it?"* stays a good question; for a plan's
   Expected lines the answer is `implement`'s test-first step, and §What design proves says so. If
   `review-plan` starts demanding scratch runs, that shows up in the measurement and gets its own issue.
5. **Should the `implement` brief change, now that the plan is unverified?** Assumed no. `implement`
   already runs test-first with the suite after each step and returns `plan-insufficient` on a gap;
   those loop-backs are what the issue's *Measure* counts.
6. **A new engine.md section, or a paragraph in §What a leg brief consists of?** A section: the brief
   line needs a `§` target, and the rule is about what the `design` leg does, not about briefs.
7. **Pin the full lines or fragments in `BriefTest`?** The full lines: the issue asks that `BriefTest`
   pins the `design:run` line, and a reworded line should be a deliberate test change.
8. **Changelog?** This repo has no `.changelog/` directory and no `CHANGELOG.md`, so none is written.
