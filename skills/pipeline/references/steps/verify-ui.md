# The `verify-ui` step

Read also: `shared/dev-stack.md`, `shared/plan-falls-short.md`, `shared/proof-payload.md`

`verify-ui` runs only when the `ui` trigger fires, and is then non-skippable (`gates.md` §`verify-ui`).
It invokes `browser-verification` (the skill's "show me" hand-off is an interactive nicety), adds the
shots to the run's page in the proof store with `proof_cli.php write` — a `state` on every shot, and a
first client summary and explainer — and posts a **text-only** record comment to the PR. The run goes
to `review-pr` next.

## The check

Bring the dev stack up if it is down (`shared/dev-stack.md` §Dev-stack readiness), and invoke
`browser-verification`. Return `continued`, or `looped-back` when the check fails: the run goes back to
`implement` (`gates.md` §Loop-backs). Playwright genuinely unavailable is a halt: no visual claim
without proof (`session.md` §Failure policy).

## Taking the shots

Take before shots only when the spec names a before state to show: check out the base detached in the
run's worktree (`git checkout --detach origin/<base>`), capture them, and check the branch out again
(`git switch <branch>`) before any after shot and before the pass returns, whatever its status; before
writing the page, `git rev-parse --abbrev-ref HEAD` names the branch. Shoot the before shots on the
base, then the after shots in the same order. The payload's fields, the pairing and the defect shots
are `shared/proof-payload.md` §Shots.

## The record comment

**The PR still gets a comment, and it is load-bearing.** The manifest is reconstructable from
git + gh (`manifest.md` §Reconstruction), so the only durable evidence that this non-skippable
gate ran must live on the PR. The comment records *what* was verified — routes, states, outcome,
shot count — and does not carry the images, which live on the proof page (`proof-store.md`). The path `write` printed goes to `record` as `--proof`.
