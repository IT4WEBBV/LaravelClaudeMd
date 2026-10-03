# Decisions — why the pipeline is as it is

History for the maintainer: what was decided, the measurement when there is one, and what it replaced. Rule text in
`SKILL.md` and `references/` says what the pipeline does now and keeps at most one line of why, with the issue that
holds the rest here. Newest issue first; history with no issue follows, under its PR or its date.

## #146 — open questions carry a kind

On IT4WEBBV/Deploy #480 the run finished, the CI gate went green, `gh pr ready` ran and the proof page opened; only
then were the two open questions from the PR body asked, and the owner had already merged. Both were remarks on a
choice already made, not forks. Nothing told a fork from a remark, and no command read open questions. Decided:
every open question carries a kind (`blocking`, `follow-up`, `remark`), and the kind says when it reaches the owner;
`finish` and the CI gate answer `ask` while a `blocking` one is unanswered. Open questions written before kinds read
as `blocking`.

Pending, not yet a rule: a design that departs from a mockup (#145) is to record the departure as a `blocking` open
question once #145 lands.

## #125 — `handoff` is a command

Pushing a branch and opening a draft PR is mechanical, and the `handoff` skill, written for a person who closes a
plan cycle, asks a question and posts a prompt comment that a run cannot use. Decided: the `handoff` leg is
`dispatch_cli.php handoff`, which posts no comment. A PR opened before the command existed may still carry the
skill's prompt comment, whose template ends with *"implementation fully done → take the PR out of draft"*: the
`implement` brief's "leave the PR draft" line covers it.

## #124 — a run merges its base into its own branch

In one `/orchestrate` batch on IT4WEBBV/Deploy (#456–#461, 2026-09-29/30) a run fell behind its base four times,
and each time it halted for an owner answer and a relaunch. The global `CLAUDE.md` told every step not to merge on
its own initiative, no brief said who merges or when, and a step that noticed improvised: a rebase in one run, a
halt in the next. Decided: code decides whether a writing step merges (`pipeline_base_state()`), the step merges
with a plain `git merge`, and the finish step's merge gets its own review round at the CI gate. Replaced: halting on
"behind".

## #113 — `review-plan:review`'s plan gap reaches `design`

`design:plan` after a `review-plan` loop-back got no plan-gap line. Decided: `review-plan:review`'s own
`plan-insufficient` is a plan return (`pipeline_is_plan_return()`), so its `design` brief carries the plan-gap line,
though it is no plan gap for `pipeline_done_legs()` or `run_audit.php`.

## #105 — design may probe a behavioural claim

Reading confirms what code declares, not what it does. After #92, 3 of the 11 runs that reached `implement` came
back `plan-insufficient`, against 0 of the 7 before; two of the three were a claim about existing behaviour that
nobody ran (IT4WEBBV/TallUi#429: a bound `null` was said to throw a `TypeError`; IT4WEBBV/viewiemedia#2116: a
`SocialFactory` row that did not hold). Each cost about 0.9–1.3M weighted tokens: `design`, `review-plan` and
`handoff` again. Decided: a design step may answer one yes/no claim with one throwaway command, recorded as a
`Probed:` line.

## #104 — design leaves the plan-gap entry unchanged

A design that answered a plan gap by writing `actions` onto the gap entry halted the run at the next brief ("the
leg rewrote ledger entry 1"). Decided: `design` leaves the entry as it is, with no `actions`; what it did goes in
the spec, the plan and the reason it returns.

## #96 — the size alone is no plan gap

A `handoff` that read the brief's plan-gap line as a rule for every Architectural spec looped a covered plan back
for "the owner's plan approval". Decided: the plan-gap lines say that an Architectural plan needs no approval beyond
`review-plan`'s, and a gap is only files or behaviour the plan does not name.

## #92 — design does not build or run the plan's code

Every `autoflow` design in this repo built the plan's code in a scratch copy and ran the suite there, 5–10 suite
calls per design, with design peaks here of 119k–269k against 131–156k for viewiemedia designs that did not;
`implement` then re-typed the same code. The plan became a diff in prose, so `review-plan` reviewed code instead of
design. Decided: design confirms by reading; the approach probe (owner's decision) is the one exception, joined by
the claim probe in #105.

## #88 — a re-review reads what changed since the last completed review

On IT4WEBBV/Asimo PR #183 the change since the first review was 4 files / 13 lines of a 16-file / 1493-line PR,
and each of three relaunches' review steps peaked at 156k–208k context. A cheaper model lowers the price per token;
only the target lowers the tokens. Decided: a `review-pr` review step records `reviewed_sha`, and a later review is
scoped to what changed since the newest `continued` one. Replaced: a full review on every relaunch.

A run in flight when `reviewed_sha` landed halted once: its review step, briefed before the key existed, returned an
entry without one and the next boundary halted on it; a relaunch went on to the resolve step, and that cycle was
never a base.

Open: `git log --remerge-diff` (git 2.36+; one machine runs 2.33) could show a merge as only what its resolution
changed, instead of the file read whole.

## #79 — Pint's cache, no changed-files list for `format`

Measured on viewiemedia (1299 files, Pint 1.32): a whole-tree run takes ~39 s with an empty cache and ~1.3 s with a
warm one. Pint keeps its cache in the container's temp dir without being told to, and viewiemedia mounts `/tmp` on a
named volume, so only the first call in a fresh stack pays. A changed-files list would save that one call and
nothing after it, so there is none.

## PR #46 — proof pages are named by a short title

Runs that copied the previous run's `run.json` payload grew its title from 84 to 596 characters in five runs.
Decided: `title` is required and at most 70 characters, and the payload table is the schema; an existing `run.json`
is not an example.

## PR #19 — no diff-scoping for `static-analysis`

Measured on Deploy: scoping PHPStan to two files costs 4.7 s against 11.1 s for all of `app/`, because the
analyser's bootstrap is a fixed ~4.5 s floor. Paying that 6.4 s removes host→container path mapping, touched-file
tracking, and any need for a pre-ready backstop, so the check runs over the whole declared scope.
