# `design` may probe a behavioural claim the plan relies on — design

**Design size:** Architectural

**Date:** 2026-09-30
**Issue:** IT4WEBBV/LaravelClaudeMd#105 (related: #92, PR #97)
**Canonical home:** the `design` overrides in `pipeline_leg_overrides()`
(`skills/pipeline/checks/brief.php`), pinned by `skills/pipeline/checks/tests/BriefTest.php`; pipeline
`references/engine.md` §What design proves, with one sentence each in §Dev-stack readiness and
§Design size (*`autoflow`'s design*).
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. Every question the brainstorm
would have asked is answered in *Assumptions*, so `/critique plan` audits exactly those. Nothing below was
built or run; no probe was needed, since no approach hinged on whether something works at all.

## Problem

Since #92 (PR #97) a design step's brief says: do not build or run the plan's code; confirm the
signatures and APIs it relies on by reading, `php -l` or grep. It did what it was for: 0 suite runs over
15 design passes after #97, against 7–10 per design before.

The price came back as `plan-insufficient` loop-backs: 3 in the 11 runs after #97 that reached
`implement`, against 0 in the 7 before. Two of the three came from a claim in the plan about what
existing code *does*, which nobody ran:

- IT4WEBBV/TallUi#429 (PR #430): the plan said a bound `null` throws a `TypeError`; it does not.
- IT4WEBBV/viewiemedia#2116 (PR #2118): the plan relied on a `SocialFactory` row that did not hold.

Each loop-back cost about 0.9–1.3M weighted tokens (`design`, `review-plan` and `handoff` again).
Reading, `php -l` and grep confirm what code declares, not what it does.

The brief has one exception today, the #92 probe: a few throwaway lines when *the choice between
approaches* hinges on whether one of them works at all. It does not cover a claim inside the chosen
approach, and since #73 it is not in the brief of the step that writes the plan at all:

| step | `$reads` (no build, no run) | `$probe` (to choose an approach) |
|---|---|---|
| `design:run` (`interactive`) | yes | yes |
| `design:spec` (`autoflow`) | yes | yes |
| `design:plan` (`autoflow`) | yes | **no** (`BriefTest` pins `not->toContain('throwaway probe')`) |

The issue names `design:run` because it was written before the split; in `autoflow` the plan's claims
are written by `design:plan`, and the spec's by `design:spec`.

## Approaches

1. **A second probe line, on every design step (chosen).** A new override line, beside the #92 lines,
   on `design:run`, `design:spec` and `design:plan`: one throwaway command per behavioural claim, its
   outcome recorded on one line beside what relies on it. The #92 lines keep their meaning; the
   approach probe stays off `design:plan`, which chooses no approach.
2. **Widen the #92 probe line to cover claims.** One line instead of two, but `design:plan` would then
   carry "the choice between approaches", which it must not make (it does not re-design what the spec
   settles), and the two probes record in different places (the spec beside the approach; the plan
   beside the task). Rejected.
3. **Only on `design:run` and `design:plan`, the steps that write a plan.** Closest to the issue's
   letter. Rejected: a spec states behaviour too (*Problem* "as found in the code", a design that leans
   on what a call returns), `design:plan` does not re-design what the spec settles, and a refuted claim
   is cheapest to meet in the step that can still choose differently.
4. **Let `review-plan` or `implement` probe instead.** Rejected: `review-plan:review` is read-only and
   its finding is a loop-back, which is the cost this issue removes; `implement` finding it is today's
   behaviour.

## Design

### The rule

A design step may answer **one yes/no question about what existing code does** with **one throwaway
command**, when the spec or the plan relies on the answer:

- **What counts as the command:** a `php -r` or tinker one-liner (`php artisan tinker --execute="…"`),
  or one existing test selected by filter. It runs in the repo's own way (in a project, inside the `web`
  container).
- **What stays excluded:** the suite, or any filter wide enough to be one; the plan's code, in a scratch
  copy or anywhere else; a new file of any kind (a probe test, a script); a *Verified before writing*
  header.
- **One command per claim.** A command that fails for its own reasons (a typo, a wrong namespace) may be
  corrected; it stays one question. There is no cap on the number of claims probed in a pass: the
  *Measure* below watches it.
- **What it is for:** behaviour that reading cannot settle. A signature, a path, a config key or a
  column is still confirmed by reading, `php -l` or grep.
- **The record:** one line beside the task that relies on the claim (in the plan), or beside the claim
  in the spec when the step writes no plan:

  ```markdown
  Probed: a bound `null` throws a `TypeError`: no, nothing is thrown (`php -r '…'`)
  ```

  `Probed:`, the claim, what the command showed, the command. A claim with no such line was not run:
  `review-plan` reads it as assumed, and its rubric question *"what is stated as fact but never
  verified, and what would verify it?"* applies to it unchanged.
- **A refuted claim** changes what is written: the step designs or plans on what the probe showed, and
  the line records the refutation. In `design:plan`, where the refuted claim is one the spec's design
  rests on, the step corrects the spec's sentence and adds the `Probed:` line there, committed before
  the plan as an `## Assumptions` addition is; where the refutation overturns the chosen approach, it
  returns `halted` with the probe as the reason (a design step may return only `continued` or `halted`,
  `LegStatus::allowedFor()`), because it may not re-design.
- **A claim the spec records as probed is not probed again** by `design:plan`; the plan's task cites it.

### The dev stack

A one-liner in a Laravel project needs the worktree's stack (the viewiemedia case is a factory row).
§Dev-stack readiness says the stack starts lazily, only before `implement`. A design step that probes
brings the stack up first, without asking (`restart.sh`, non-destructive), only when the command needs
it, and leaves it running: `implement` needs it next. A stack that cannot start is not a halt here: the
claim stays unprobed, without a `Probed:` line, and the step carries on. A design with no claim to probe
starts nothing, as today.

### The brief lines (`pipeline_leg_overrides()`, `brief.php`)

`$reads` and `$exemplars` are unchanged. `$probe` changes its first words, because it is no longer the
only exception; the rest of the line is unchanged:

> An exception, to choose an approach: when the choice between approaches hinges on whether one of them
> works at all, answer that question with a throwaway probe (a few lines run on their own, never the
> plan's code, never the suite) and write the question and what the probe showed into the spec.

New, `$claim`:

> An exception, to check a claim: when the spec or the plan relies on what existing code does, which
> reading cannot show, answer that one yes/no question with one throwaway command (a `php -r` or tinker
> one-liner, or one existing test by filter; never the suite, never the plan's code, no new file),
> bringing the dev stack up first when the command needs it (engine.md §Dev-stack readiness), and write
> `Probed: <claim>: <what it showed> (<command>)` on one line beside the task that relies on it, or
> beside the claim in the spec when this step writes no plan.

Order per step:

| step | lines |
|---|---|
| `design:run` | invoke, `$assumptions`, `$reads`, `$probe`, **`$claim`**, `$exemplars`, commit |
| `design:spec` | invoke, `$assumptions`, `$reads`, `$probe`, **`$claim`**, `$exemplars`, commit |
| `design:plan` | read cold, assumptions-in-spec, `$reads`, **`$claim`**, `$exemplars`, commit |

The same in `autoflow` and `interactive`. `§Dev-stack readiness` is an existing `##` heading, so
`LockStepTest`'s *keeps every engine.md section a brief names* holds; the name ends at `)`, inside that
test's `§([^,):;]+)` pattern.

### engine.md

- **§What design proves.** The paragraph *The one exception is a probe* becomes *Two exceptions, both
  probes*: the approach probe as it stands (owner, #92; brainstorming's Spike steps used as one step),
  and the claim probe, with *The rule* above in prose, the two loop-backs and their cost as the why
  (#105), the `Probed:` line, the refuted-claim rule and that `design:plan` does not probe again what the
  spec records. The first paragraph's "reading them, `php -l` or grep" stays the default. The
  *Verified before writing* paragraph is unchanged: a `Probed:` line sits beside one task, it is not a
  header.
- **§Dev-stack readiness.** *Worktree now, stack later* gains the exception: a design step that probes a
  claim whose command needs the stack brings it up then (§What design proves); nothing is spun up
  merely to brainstorm.
- **§Design size, *`autoflow`'s design*.** The `design:plan` bullet gains one sentence: it may probe a
  behavioural claim, not an approach (§What design proves).

### What does not change

`pipeline-autoflow.js`, `dispatch.php`, the manifest schema and `manifest.md`, `gates.md`, `SKILL.md`,
the `/critique plan` rubric and the `review-plan` brief, the `implement` brief (it still returns
`plan-insufficient` on a gap), `run_cost.php` and `run_audit.php`, the Bounded spec and plan templates
(a `Probed:` line sits under the step it belongs to), and the plans that carry a *Verified before
writing* header.

## Tests

Written first, seen red against today's `brief.php`:

- `BriefTest.php`, *tells design to confirm by reading, probe only to choose, and leave the Expected
  lines to implement* (renamed: *…, probe to choose or to check a claim, and …*): for `autoflow`
  `design:spec` and `interactive` `design:run`, the brief contains `$reads`, the reworded `$probe` and
  the new `$claim` verbatim, in that order (`$claim` directly after `$probe`, `$probe` directly after
  `$reads`: asserted on the joined `- ` lines, so the issue's *next to the #92 line* is pinned), and no
  longer contains `The one exception`.
- `BriefTest.php`, *splits autoflow's design into a spec step that stops at the spec and a plan step
  that reads it cold*: the plan brief contains `$claim` verbatim directly after `$reads`, and
  `not->toContain('throwaway probe')` becomes `not->toContain('to choose an approach')` (the claim line
  says "throwaway command", so the old needle would still pass, but it no longer names what it guards).
- `LockStepTest`, unchanged, covers the `§Dev-stack readiness` pointer.

The whole pipeline suite passes (`vendor/bin/pest -c skills/pipeline/checks/phpunit.xml
--test-directory=skills/pipeline/checks/tests`).

## Measure

Not in this PR; read from the next runs, as #92's was:

- `plan-insufficient` loop-backs whose `reason` is a behavioural claim nobody ran (the ledger);
- `design:spec` and `design:plan` cost and peak (`run_cost_cli.php`);
- probes per design pass: the `Probed:` lines in the run's spec and plan, checked against the step's
  transcript, where each is a single command;
- the #92 guard: 0 suite runs in a design step.

## Out of scope

- A counter for probes or suite runs in `run_cost_cli.php`: the numbers above are read by hand over a
  handful of runs, as #92's were; tooling follows if the count is kept.
- An `Assumed:` marker on every behavioural claim that was not probed (*Assumptions* 4).
- A probe by `review-plan` or `implement` (*Approaches* 4).
- The `experiment` skill: its confirmation step needs the user, which an `autoflow` step cannot get
  (#92, Assumption 1).

## Assumptions

Questions the brainstorm would have asked the owner, with the answer assumed.

1. **The issue names `design:run`; which steps get the line?** All three design steps. The issue
   predates the #73 split; in `autoflow` the plan is `design:plan`'s, which has no probe line today, and
   the two loop-backs it cites are plan claims.
2. **Does `design:plan` get the approach probe as well?** No: it does not choose between approaches
   (#73), and its test keeps saying so.
3. **Where does the record go when the step writes no plan (`design:spec`, Architectural)?** In the
   spec, beside the claim. The issue's "next to the task that relies on it" holds wherever a plan is
   written: `design:run`, `design:plan`, and a Bounded `design:spec`.
4. **Must every unprobed behavioural claim be marked `Assumed:`?** No. The issue asks that the plan
   names the *probed* claim; a claim without a `Probed:` line is thereby assumed, and marking each one
   would be a rule of the brief's own invention (engine.md §What a leg brief consists of).
5. **What does the record line look like?** `Probed: <claim>: <what it showed> (<command>)`. A fixed
   first word makes the lines greppable for the *Measure*, and the command shows `review-plan` that it
   was one command.
6. **May a probe start the dev stack?** Yes, only when its command needs it, without asking
   ([[docker-stack-no-hesitation]]); otherwise the viewiemedia case could not have been probed. A stack
   start costs a minute or two against 0.9–1.3M tokens for a loop-back. A stack that cannot start
   leaves the claim unprobed and is no halt: design does not otherwise need the stack.
7. **May the probe be a new test file?** No: a new test is the plan's code. "A single test filter"
   selects one existing test.
8. **Is there a cap on probes per pass?** No fixed number; one command per claim, and the *Measure*
   counts them. A cap would be arbitrary before the first numbers.
9. **Does the opening of the #92 line change?** Yes, from "The one exception:" to "An exception, to
   choose an approach:". With two exceptions "the one" is false, and `design:plan` carries only the
   second, so neither line may count ("the other exception").
10. **What does `design:plan` do when a probe refutes what the spec rests on?** It corrects the spec's
    sentence with the `Probed:` line and plans on the truth; when the refutation overturns the chosen
    approach it returns `halted` with the probe as the reason. There is no loop from `design:plan` to
    `design:spec`, and adding one is beyond the issue.
11. **Does `/critique plan`'s rubric or the `review-plan` brief change?** No. The rubric's *"what is
    stated as fact but never verified?"* reads a `Probed:` line as verified and everything else as
    before (#92, Assumption 4).
12. **Does this PR close #105?** The first Done-when line, yes; the second (a run after the merge shows
    probes only as single commands) is measured afterwards. `review-pr` settles the closing links
    (engine.md §Closing links).
13. **Changelog?** This repository has no `.changelog/` and no `CHANGELOG.md`, so none is written.
14. **Sibling run #52 (orchestrate's note)?** It has no PR yet; if it changes `brief.php`, `BriefTest.php`
    or engine.md first, the writing steps merge the base (engine.md §Catching up with the base).
