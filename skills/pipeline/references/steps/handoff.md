# The `handoff` step

Read also: `shared/plan-falls-short.md`

`handoff` is no skill: it is one command, `dispatch_cli.php handoff <manifest>` (`../../checks/handoff.php`),
in both modes, run by a dispatched agent. Pushing a branch and opening a draft PR is mechanical. The run
goes to `implement` next.

## The command

Run it as the brief prints it, as its own command: it is the whole step, and it records `continued` or
`halted` itself.

What it does: it pushes the branch (never forced), opens the **draft PR** or adopts the open draft the
branch already has, with `--base` on a run on a base; an English title `Implement: <the spec's heading>
(issue: #N)` and a body that names the spec and the plan; it references the issue **without a closing
keyword**, `Part of #N` — this PR carries no implementation yet (`steps/finish.md` §Closing links settles
closing) —; it sets the board Component where the repo's `## Board` names a `component-default`; it posts
no comment; and it files the run's **proof page** (`proof-store.md` §Where a page lives). It records the
PR number and the page itself, through `record`'s code. The order of its checks and acts is
`machinery.md` §`handoff` in order.

**Repair nothing it reports**: no force-push, no `gh pr create` or `gh pr edit` by hand. A halt it
recorded, a refusal, or a denied command is a halt with that reason. Every outward act is idempotent:
after any failure, running the step again is the repair.

**The leg's name is not a skill to invoke.** Do not invoke the `handoff` skill (`/handoff`): it is
written for a person who closes a plan cycle, asks the owner a question and posts a prompt comment that
a run cannot use.
