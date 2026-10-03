# The `review-plan` review step

Read also: `shared/plan-falls-short.md`, `shared/reviewing.md`

This step reviews the design before anything is pushed: it invokes `/critique plan` on the spec and the
plan (in `autoflow` it applies the procedure itself, `shared/reviewing.md` §A review step) and appends
the review verbatim as the open `plan-approval` entry. A fresh resolve step acts on it next.

## The review

- Review the spec and the plan with `/critique plan`, the unchanged rubric, on either design size.
- Write the review verbatim to `<manifest stem>.review.md`; `record` appends it as the open
  `plan-approval` entry.
- Act on nothing. Read-only on the checkout: the review file is the only file the step writes.
- The project-vs-package call arrives as part of the review: it is a `/critique plan` judgment, made in
  prose (`gates.md` §Content triggers).
- On a Bounded spec the escalation check comes first (`shared/plan-falls-short.md` §On a Bounded spec).
