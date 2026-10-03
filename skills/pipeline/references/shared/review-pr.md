# The `review-pr` leg

Read by: `steps/review-pr-review.md`, `steps/finish.md`

## The leg is not the `review-pr` skill

`DevOps-Claude-Config` ships a skill named `review-pr`, linked into every session, and the leg does not
use it: its review step is `/critique pr`, its resolve step the finish step. That skill settles the
draft status itself (`gh pr ready` on a clean review) and posts its own review comment, which would come
before the CI gate. Both `review-pr` steps' briefs therefore open their overrides with
(`pipeline_leg_overrides()`): **"The leg's name is not a skill to invoke: do not invoke the `review-pr`
skill (`/review-pr`), which posts its own review comment and changes the PR's draft state. This brief is
the whole step."**

## The rounds — CI, conflict, answer

The CI gate (`session.md` §The CI gate) can send a finished run back to `review-pr` with a settled
decision. What `ci` computes for each round is `machinery.md` §The CI gate; here is what the two steps do.

**The CI round** (a decision that starts `CI red on the PR's head commit`). The review step reads the
failing job's log and states the failure as a finding; the finish step fixes it, or shows it unrelated
(the same failure on the base branch, or a flake whose failed jobs it reruns without waiting); then
`finish`, and the gate again.

**The merge round** (a decision that starts `Unreviewed merge on the PR's head commit`). The review is
scoped and reads the files the merge met whole (`steps/review-pr-review.md` §Scoped re-review); it
records a `reviewed_sha` that contains the merge.

**The conflict round** (a decision that starts `Conflict with the base on the PR's head commit`). The
review step names the conflict as a finding and leaves the merge to the resolve step, since a review
step does not merge. The resolve step makes the merge its catch-up override asks for (a textual
conflict means both sides changed a file, so `shared/catch-up.md` gives that override), resolves it,
runs the suite and pushes; when its brief has no such override, it says so in `actions` and changes
nothing for it. At the next gate the merge round reviews those resolutions when it is unspent; when it
is spent they go on unreviewed.

**The answer round** (a decision that starts `Answer to open question`). The review step names what an
answer that departs from what the PR built changes, as a finding; the resolve step integrates it, and
the question it answers is no longer open. Its `finish` may ask again about new `blocking` questions.
