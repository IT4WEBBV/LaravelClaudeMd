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

## #124 — a run merges its base into its own branch

In one `/orchestrate` batch on IT4WEBBV/Deploy (#456–#461, 2026-09-29/30) a run fell behind its base four times,
and each time it halted for an owner answer and a relaunch. The global `CLAUDE.md` told every step not to merge on
its own initiative, no brief said who merges or when, and a step that noticed improvised: a rebase in one run, a
halt in the next. Decided: code decides whether a writing step merges (`pipeline_base_state()`), the step merges
with a plain `git merge`, and the finish step's merge gets its own review round at the CI gate. Replaced: halting on
"behind".

## #96 — the size alone is no plan gap

A `handoff` that read the brief's plan-gap line as a rule for every Architectural spec looped a covered plan back
for "the owner's plan approval". Decided: the plan-gap lines say that an Architectural plan needs no approval beyond
`review-plan`'s, and a gap is only files or behaviour the plan does not name.

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
