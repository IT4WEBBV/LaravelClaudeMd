# Reviewing

Read by: `steps/review-plan-review.md`, `steps/review-pr-review.md`

## A review step

**In `autoflow` a review step applies `/critique`'s procedure itself** — Stage 0, Stage 1 and the rubric —
because a workflow agent cannot start the reviewer, so `--verify` and `alternatives` are unavailable.

**A review is prose, not a verdict.** `/critique` returns the review it wrote — no severity ranking,
no verdict enum, no structured block (`../../../critique/SKILL.md`). The review step stores it verbatim:
it writes it to `<manifest stem>.review.md`, which `record` appends as the open ledger entry. The
step acts on nothing; the resolve step reads the review and acts (`shared/resolving.md` §Resolving a review).

**A review step's brief is crafted context** (`../../../critique/SKILL.md` §Reviewer contract): pointers,
decisions and overrides — never an earlier review, an earlier action, or another step's output. A
re-review of the PR names the commit the last completed review saw, never that review
(`steps/review-pr-review.md` §Scoped re-review).

**A review step that returns `plan-insufficient` writes no review file**, so `record` adds no review
entry (`shared/plan-falls-short.md` §A resolve step loops back).
