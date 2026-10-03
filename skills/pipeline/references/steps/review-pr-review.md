# The `review-pr` review step

Read also: `shared/checks.md`, `shared/plan-falls-short.md`, `shared/review-pr.md`, `shared/reviewing.md`

This step is the last review before a human merges: it invokes `/critique pr` (in `autoflow` it applies
the procedure itself, `shared/reviewing.md` §A review step) and appends an open `pr-review` entry. It is
not the `review-pr` skill (`shared/review-pr.md` §The leg is not the `review-pr` skill). The finish step
acts on the review next.

## The review

- Review the PR with `/critique pr`, stating the suite line from the brief and, when the repo declares
  checks, their result qualified by its scope (`shared/checks.md` §Mechanical checks).
- Write the review verbatim to `<manifest stem>.review.md`; `record` appends it as the open `pr-review`
  entry with the commit reviewed (`reviewed_sha`).
- Act on nothing. Read-only on the checkout: the review file is the only file the step writes.
- A settled CI, conflict or answer decision is a finding of this review (`shared/review-pr.md` §The rounds).

## Scoped re-review — what a review after a completed one reads

A run re-entered at `review-pr` (`launch --from review-pr`: an owner's request on a ready PR, the CI
gate's fix round) starts with a review step. Once the PR has passed a review, that step reviews what
changed since, not the whole PR again. A cheaper model lowers the price per token; only the target lowers
the tokens (#88).

**The review step records what it saw.** Its entry carries `reviewed_sha`, the output of
`git rev-parse HEAD`. A `review-pr` review step whose entry lacks a 40-character one halts, and so does a
resolve step that adds, changes or removes it (`pipeline_ledger_problem()`, `manifest.md` §`gate_ledger`).

**The base** is `pipeline_review_base()`: the `reviewed_sha` of the newest `continued` `pr-review` entry
that has one, newer than the latest escalation or plan gap (`pipeline_reset_at()`, the cut
`pipeline_done_legs()` makes: code reviewed against a plan that grew is reviewed whole again). A halted,
looped-back or open review is never a base: its findings were not dispositioned there.

**The target** is `pipeline_review_scope()`, which `brief` (and `next` / `returned` in `interactive`)
computes with git in the worktree for `review-pr`'s review step only, and writes into its brief as one
override line:

- the branch's own commits since the base, as patches: `git log -p --no-merges <sha>..HEAD ^<base>`, plus
  `git diff HEAD`; Stage 0 runs over both. `<base>` is `origin/<manifest base>`, else `origin/HEAD`.
  `^<base>` leaves out what a merge of main brought in and keeps a merged-in side's commits that are not
  on main (a pull of the PR branch onto local commits), which `--first-parent` would drop;
- read whole at HEAD, the files where a merge since the base met the branch's changes: per merge not on
  the base, the files both sides changed since they last met (every conflict, a clean merge of a shared
  file, a resolution that took one side), and the files the merge commit changed against every parent
  (an edit made in the merge itself).

What the settled decisions ask of the PR (an owner's request, the CI round's failure) stays in the
target wherever it lies, also outside the delta. With nothing committed since the base the target is
only that: the review checks what the settled decisions ask of the PR and says the branch did not move;
it does not widen to the whole PR.

**Otherwise the review is full:** no `continued` entry with a sha, a sha HEAD does not contain
(a rebase, a force-push), a base ref git cannot resolve (with no manifest `base` and `origin/HEAD` unset,
every re-review on that machine stays full; `git remote set-head origin --auto` sets it), or any git call
that fails. The scope is never narrower than git could prove textually. It cannot see a semantic
conflict: a merge that changes only files the branch did not touch lists none, even where the branch's
code depends on them. The full review has that blind spot too; the suite and CI cover it.

**The earlier review is not carried:** the brief names its commit, never its entry
(`machinery.md` §What a leg brief consists of). A chain of scoped reviews is as sound as the earliest full review in it; the base rule keeps
an undispositioned review out of the chain.
