# The `review-plan` resolve step

Read also: `shared/catch-up.md`, `shared/plan-falls-short.md`, `shared/resolving.md`

This step acts on the open `plan-approval` review (`shared/resolving.md` §Resolving a review). In
`autoflow` it is a fresh agent; in `interactive` the session acts as this step, with the human deciding.
On `continued` the run goes to `handoff`.

## What this step changes

The spec and the plan: it integrates and commits what is worth acting on, records the rest, and
carries anything unresolved as an open question with its kind (`shared/resolving.md` §Open questions).
Where the review says the design is fundamentally wrong it returns `looped-back`, and the run goes back
to `design` (`gates.md` §Loop-backs).

## The independent read

**In `interactive` an independent read is available, and is not a routing rule.** At `review-plan` the
resolve step is judging a critique of a plan another agent wrote, with the author's framing in the
spec. So where
acting on a point is expensive and the resolve step doubts it, it dispatches a **fresh agent that never
saw the design leg**, gives it the point plus the code, and asks it to refute the claim citing
`file:line`. That is judgment exercised where it pays, not a mandatory step with an outcome enum — and
it cannot stop the run; it only informs what the resolve step does next. In `autoflow` there is none:
a workflow agent cannot start one, and its step prompt says so.
