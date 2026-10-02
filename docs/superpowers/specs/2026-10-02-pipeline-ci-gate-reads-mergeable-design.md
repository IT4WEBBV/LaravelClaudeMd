# The CI gate reads whether the PR merges cleanly: a conflict is a fix round, an unknown a wait — design

**Design size:** Architectural

**Date:** 2026-10-02
**Issue:** IT4WEBBV/LaravelClaudeMd#149
**Canonical home:** `skills/pipeline/checks/ci.php` (the answer), `skills/pipeline/checks/dispatch_cli.php`
(`dispatch_cli_ci`: one more gh field), `skills/pipeline/checks/brief.php` (the round's lines for
`review-pr`), pipeline `references/engine.md` §The CI gate, pipeline `references/manifest.md`
(`decisions` row), pipeline `SKILL.md` §`autoflow` step 5.
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. The issue body is the approved
scope and is not re-litigated here; where this design departs from its letter, *Assumptions* says so and
why. Every question the brainstorm would have asked is answered in *Assumptions*, so `/critique plan`
audits exactly those. Nothing below was built or run; what it relies on was read or probed (see *What
was read*).

## Problem

As the issue states it: `dispatch_cli.php ci` answered `{"action":"ready","verdict":"none","sha":"73b99f7…"}`
for PR #148 while GitHub reported it `CONFLICTING` / `DIRTY` against main. PR #147 had merged into main
after #148's review and both changed eight files. The repo has no CI checks, so the verdict was `none`,
and the PR was marked ready; only a manual `gh pr view 148 --json mergeable,mergeStateStatus` put it back
in draft.

Confirmed in the code: `dispatch_cli_ci()` reads `gh pr view <pr> --json headRefOid,statusCheckRollup`
and nothing else from the PR, and `pipeline_ci_answer()` (`checks/ci.php`) decides on four inputs only:
an unreviewed merge, an unreadable view, a head mismatch and the checks' verdict. Whether the PR merges
into its base is read nowhere. The gate's `ready` is the last thing between a run and `gh pr ready`
(engine.md §Who takes the PR out of draft), so a conflicting PR goes ready whenever its checks are
green or absent. On a repo whose CI runs on `pull_request`, GitHub runs no workflow for a conflicting
PR's merge ref, so `none` is the likely verdict there too, and `none` reads `ready` from the third read.

Probed: `gh pr view` serves a `mergeable` field, and GitHub can answer `UNKNOWN` for it:
`{"headRefOid":"d5f309b…","mergeable":"UNKNOWN","statusCheckRollup":[]}` for the merged #148
(`gh pr view 148 --json headRefOid,mergeable,statusCheckRollup`). The issue saw `UNKNOWN` on the first
read after `gh pr ready` and `CONFLICTING` on the next. GitHub's `MergeableState` has three values:
`MERGEABLE`, `CONFLICTING`, `UNKNOWN`.

## Settled by the owner

The issue's proposal, taken as the scope: the gate reads `mergeable` for the PR head; `CONFLICTING`
answers `fix` with a decision to merge the base (engine.md §Catching up with the base); `UNKNOWN` is a
`wait`. A dispatch-suite test: a conflicting PR answers `fix`, an unknown one `wait`, a clean one `ready`.

## Approaches

**What a conflict's `fix` does.**

- *(recommended)* **A conflict round of its own**, counted as the CI round and the merge round are: the
  answer is `fix` with verdict `conflicting` and a decision that starts
  `Conflict with the base on the PR's head commit `. The session does exactly what it does for every
  `fix` today: `launch --from review-pr --decision "<its decision>"` and a new workflow. `review-pr`'s
  resolve step is already a catch-up step (`PIPELINE_CATCH_UP_STEPS`), and a textual conflict means both
  sides changed a file, so `pipeline_base_state()` puts the merge line first in that step's brief: the
  merge, its conflict resolutions and the suite after it are existing behaviour (engine.md §Catching up
  with the base). Once that `finish` prints `done`, the gate's existing merge round sees the merge
  (`pipeline_review_scope()` lists the files it met) and gives the resolutions their own scoped review,
  so nothing conflict-resolved reaches a ready PR unreviewed. Nothing in the session's or orchestrate's
  loop changes: `fix` is already generic there.
- **Reuse the merge round's decision.** Count a conflict as the `Unreviewed merge` round. Rejected: that
  round is spent before the conflict's merge exists, so the merge it produces would then go ready
  unreviewed, which is the hole #124 closed.
- **Halt on a conflict** and let the owner merge. Rejected: the issue asks for `fix`, and engine.md
  §Catching up with the base exists because halting on "behind" cost an owner answer and a relaunch four
  times in one batch (#124).

**Where the read sits in the gate.** After the head comparison and before the checks: `mergeable` is
GitHub's answer for its head, so it counts only once that head is the worktree's `HEAD`; and a
conflicting PR gets no CI on its merge ref, so its checks say nothing about the code that would merge.
The unreviewed-merge round keeps running first, before the PR is read, as now.

## Design

### 1. The gate (`checks/ci.php`, `checks/dispatch_cli.php`)

`dispatch_cli_ci()` asks gh for `headRefOid,mergeable,statusCheckRollup` in its one call. In
`pipeline_ci_answer()` the order becomes:

1. unreviewed merge (unchanged);
2. unreadable view (unchanged);
3. head mismatch (unchanged);
4. **mergeability, new:** `$view['mergeable']`
   - `UNKNOWN` → `['action' => 'wait', 'verdict' => 'unknown', 'sha' => <head>]`; at the 120th read
     (`PIPELINE_CI_POLLS`) a halt: `GitHub had not worked out whether PR #<pr> merges into its base after
     an hour, on <sha>`.
   - `CONFLICTING` → `pipeline_ci_conflict($manifest, $read)`: with no conflict round spent,
     `['action' => 'fix', 'verdict' => 'conflicting', 'sha' => <head>, 'decision' => PIPELINE_CONFLICT .
     "<sha>: GitHub reports PR #<pr> CONFLICTING with its base; review-pr's resolve step merges the base
     (engine.md §Catching up with the base)"]`; with the round spent, a halt:
     `PR #<pr> conflicts with its base again after the conflict round, on <sha>: merge the base into the
     branch (engine.md §Catching up with the base), push, and run the CI gate again`.
   - `MERGEABLE` (or any other value) → on to the checks.
5. the checks' verdict (unchanged).

New constant `PIPELINE_CONFLICT = "Conflict with the base on the PR's head commit "` beside
`PIPELINE_CI_RED` and `PIPELINE_MERGE_UNREVIEWED`, and `pipeline_conflict_rounds()` beside
`pipeline_ci_rounds()` and `pipeline_merge_rounds()`, through `pipeline_decisions_starting()`. A run may
have one of each round. Halts name `review-pr` through `pipeline_ci_halt()`, as every gate halt does, so
`finish` records them there unchanged. Any value other than `UNKNOWN` and `CONFLICTING` goes on to the
checks: a value GitHub may add later is not a reason to hold a PR the old gate would have readied.

The docblock of `pipeline_ci_answer()` names the new field (`gh pr view <pr> --json
headRefOid,mergeable,statusCheckRollup`).

### 2. The round's brief lines (`checks/brief.php`)

As `pipeline_ci_round_line()` gives the CI round's two lines while `pipeline_ci_rounds() > 0`, a new
`pipeline_conflict_round_line($step)` gives one per `review-pr` step while `pipeline_conflict_rounds() > 0`:

- review: ``The settled `Conflict with the base` decision is a finding of this review unless HEAD
  already contains the base's tip (`git merge-base --is-ancestor origin/<base> HEAD`): name the conflict
  and leave the merge to the resolve step, since a review step does not merge (engine.md §Catching up
  with the base).``
- resolve: ``Resolve the `Conflict with the base` finding with the merge this brief's catch-up override
  asks for, and name it in `actions`; when this brief has no such override, say so in `actions` and
  change nothing for it: the CI gate reads the PR's mergeability again (engine.md §The CI gate).``

`<base>` is written as the literal placeholder text in the line, as the CI round's `<run>` is: the
brief's state section already names the run's base when it is not the default. The lines sit right
after the CI round's line in `pipeline_brief_overrides()`.

### 3. Docs

- **engine.md §The CI gate.** The intro sentence names the new field in the gh call. The table gets two
  rows between `mismatch` and `green`:
  - `conflicting`: GitHub reports the PR `CONFLICTING` with its base → `fix` the first time in a run;
    `halt` once that round is spent;
  - `unknown`: GitHub has not worked out mergeability yet (it computes it lazily, after a push or
    `gh pr ready`) → `wait`; `halt` at the 120th read.
  A bullet after *A merge the review did not see*: **A conflict with the base** (#149): the answer, its
  decision, what the review and resolve steps do (§2), and that the merge it makes gets the merge round
  at the next gate; once per run, apart from the other two rounds; why it is read after the head
  comparison and before the checks. The *`fix`* bullet's first sentence covers all three decisions. The
  *In `interactive`* bullet adds that a conflict is shown to the human like any other non-`ready`
  answer.
- **manifest.md** `decisions` row: "one of the CI gate's three records, a red CI, an unreviewed merge or
  a conflict with the base".
- **pipeline `SKILL.md`** step 5: `**fix**` (a red CI, a merge the last review did not see, or a
  conflict with the base).
- Orchestrate's `SKILL.md` and `references/commands.md` §Finish treat `fix` generically: no change.

## Testing

TDD, on the existing suites; the plan writes each case before its code.

**`CiTest.php`.** `ci_view()` gains `'mergeable' => 'MERGEABLE'`, with a way to set another value, so
every existing case keeps its answer. New cases:

1. `CONFLICTING` with green checks, and with no checks and workflows at the first read, answers
   `fix` / `conflicting` with the decision verbatim; with the conflict round spent, the halt verbatim,
   naming `review-pr`.
2. `UNKNOWN` at read 119 answers `wait` / `unknown`; at 120 the halt verbatim.
3. A mismatched head wins over `CONFLICTING` (`mismatch`); an unreviewed merge wins over
   `CONFLICTING` (`merge`); `CONFLICTING` wins over a red check.
4. `pipeline_conflict_rounds()` counts only its own prefix, and the three round counters stay apart.

**`DispatchCliTest.php`.** `ci_head()` and the `none` view gain `mergeable`. The one-gh-read case expects
`pr view 7 --json headRefOid,mergeable,statusCheckRollup`. New: the issue's three answers through the
CLI: a conflicting PR `fix`, an unknown one `wait`, a clean one `ready`; and a spent round's halt that
`finish` records on `review-pr`.

**`BriefTest.php`.** With a `Conflict with the base…` decision, `review-pr`'s review and resolve briefs
carry their line verbatim; without it, neither.

**Suite.** `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
(`LockStepTest` reads §The CI gate's heading, which does not change).

## Done when

- The suite passes with the cases above.
- `dispatch_cli.php ci` on a conflicting PR answers `fix` with verdict `conflicting`; on an `UNKNOWN`
  one, `wait`; on a mergeable one with no checks, `ready` (the issue's three).
- engine.md, manifest.md and pipeline `SKILL.md` say what §3 says.

## Assumptions

Each question the brainstorm would have asked the owner, and the answer assumed.

1. **How does a conflict's `fix` get the base merged?** Through the existing `launch --from review-pr`
   relaunch: the resolve step's catch-up override does the merge (§Approaches). No new launch path, no
   session-side merge (the invoking session never writes to the branch).
2. **Is the conflict round bounded?** Once per run, counted from `decisions` as the other two rounds
   are, so a resumed run halts on its next conflict too. A sibling merging again during the round can
   leave a second conflict; that halts rather than loops. Unbounded rounds would loop forever when the
   resolve step cannot merge (an offline fetch gives it no catch-up line).
3. **Does the conflict's merge get reviewed?** Yes, by the existing merge round at the next gate, when
   that round is unspent. When it is spent the merge goes on unreviewed, as any further merge does today
   (engine.md §The CI gate, *A further unreviewed merge neither halts nor loops*); this change does not
   widen that.
4. **Is the resolve step's catch-up override guaranteed on a conflict?** On a textual conflict both
   sides changed a file, so `pipeline_base_state()` finds a shared file and gives the line, unless a git
   call fails (it then gives none). Without it the resolve step changes nothing for the conflict and the
   next gate halts on the spent round. Reasoned from `pipeline_base_state()`, not probed.
5. **Why `mergeable` and not `mergeStateStatus`?** The issue names `mergeable`. `mergeStateStatus` also
   reports `BEHIND`, `BLOCKED`, `UNSTABLE` and others, which depend on branch protection and are not
   this issue; `DIRTY` there is `CONFLICTING` here.
6. **How long does the gate wait on `UNKNOWN`?** As on pending checks: up to the 120th read, an hour at
   30 s. GitHub normally settles within a read or two (the issue); an hour of `UNKNOWN` is GitHub's
   trouble, a halt the owner should see.
7. **Verdict names?** `conflicting` and `unknown`, GitHub's own words in lower case, as `red`, `green`
   and `pending` are the gate's.
8. **Does an interactive run change?** Only in what its finish step shows the human: the same answers,
   no automatic round (engine.md §The CI gate, *In `interactive`*).
9. **Changelog?** The repo has no `CHANGELOG.md` and no `.changelog/`: none.

Added by the `plan` step, where the plan needed an answer this design did not give:

10. **May the two new halt reasons hold a single quote?** No. The session hands a halt to `finish` as
    `finish <manifest> '<the answer>'` (pipeline `SKILL.md` step 5), and `json_encode` leaves `'` as it
    is, so a quote in the reason ends the shell argument early. The plan pins both reasons free of one.
    The existing `mismatch` halt (`PR #<pr>'s head on GitHub…`) does hold one; that is outside this
    issue and stays as it is.
11. **Do the new brief lines join `LockStepTest`'s list of lines whose `§` names must be engine.md
    headings?** Yes: both name sections (`§Catching up with the base`, `§The CI gate`), and the test
    exists so a renamed heading fails the suite. The CI round's lines are not on that list today; adding
    them is not this issue.
12. **Does an `interactive` run's `review-pr` brief carry the conflict line?** Whenever a `Conflict with
    the base` decision is recorded, as the CI round's line does: neither checks the mode. An
    `interactive` run records none (assumption 8), so in practice only `autoflow` sees it.
13. **What does `pipeline_ci_answer()` do with a `mergeable` value that is neither `UNKNOWN` nor
    `CONFLICTING`, an empty string included?** Goes on to the checks, as §1 says for any other value;
    the plan pins that with a value GitHub does not send today.

## Relation to other work

- #153 (sibling in this batch, no PR yet): when it lands first and shares files, the base is merged as
  engine.md §Catching up with the base says.
- #124 (merged): the merge round this design leans on for reviewing the conflict's resolution.

## What was read

`skills/pipeline/checks/ci.php`; `checks/dispatch_cli.php` (`dispatch_cli_ci`, `dispatch_cli_pr_view`,
`dispatch_cli_unreviewed`); `checks/brief.php` (`pipeline_brief`, `pipeline_brief_overrides`,
`PIPELINE_CATCH_UP_STEPS`, `pipeline_base_state`, `pipeline_review_scope`, `pipeline_catch_up_line`,
`pipeline_ci_round_line`); `checks/tests/CiTest.php`, `DispatchCliTest.php` (`ci_fixture`, the gate
cases), `BriefTest.php` (the CI round lines), `LockStepTest.php` (the pinned headings); engine.md §Who
takes the PR out of draft, §The CI gate, §Catching up with the base, §Scoped re-review; manifest.md's
`decisions` row; pipeline `SKILL.md` step 5; orchestrate `SKILL.md` step 5 and `references/commands.md`
§Finish; the `mergeable` probe above.
