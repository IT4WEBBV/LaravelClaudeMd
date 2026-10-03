# The dev stack

Read by: `steps/design.md`, `steps/implement.md`, `steps/verify-ui.md`

## Dev-stack readiness — pipeline-owned, no hesitation

Several legs need the worktree's stack: `implement` runs the suite after each step, and
`verify-ui` drives a real browser. **The `implement` step brings the stack up itself, first thing,
without asking** (its brief says so; `verify-ui` does the same if it is down) (`restart.sh`;
non-destructive) and leaves it running afterwards. Starting the stack is a routine owned action,
never a "shall I start docker?" prompt. This is the house preference [[docker-stack-no-hesitation]]. If the stack
genuinely cannot start, that is a **hard failure** (`session.md` §Failure policy), not a reason to hesitate.

*Worktree now, stack later:* creating the worktree is cheap (git); the stack starts lazily, only
before `implement` — nothing is spun up merely to brainstorm. One exception: a design step that probes
a claim whose command needs the stack brings it up then, without asking, and leaves it running for
`implement` (`steps/design.md` §What design proves). There a stack that cannot start is no hard failure: the claim stays
unprobed, with no `Probed:` line, and the step carries on.
