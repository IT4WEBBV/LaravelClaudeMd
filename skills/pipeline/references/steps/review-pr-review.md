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

**The target is the brief's scope line**, present once a review of the PR has completed and HEAD still
contains the commit it saw; without that line the review is full. Read beyond the target only where a
finding needs it. What `brief` computes for that line, and when it falls back to a full review, is
`machinery.md` §The review scope.

What the settled decisions ask of the PR (an owner's request, the CI round's failure) stays in the
target wherever it lies, also outside the delta. With nothing committed since the reviewed commit the target is
only that: the review checks what the settled decisions ask of the PR and says the branch did not move;
it does not widen to the whole PR.

**The earlier review is not carried:** the brief names its commit, never its entry
(`machinery.md` §What a leg brief consists of). A chain of scoped reviews is as sound as the earliest full review in it; the base rule
(`machinery.md` §The review scope) keeps an undispositioned review out of the chain.
