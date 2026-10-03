# The `implement` step

Read also: `shared/catch-up.md`, `shared/checks.md`, `shared/dev-stack.md`, `shared/plan-falls-short.md`, `shared/suite.md`

`implement` invokes no skill: it follows the procedure below, in the run's worktree, with no slot of its
own. It updates `last_sha` through `record`. The run goes to `verify-ui` next when the `ui` trigger fires,
else to `review-pr`.

## Implement — the step, start to finish

The `implement` step is no skill: this file is the whole procedure, and its brief points here. The
rules it follows live in the shared files its *Read also* line names, cited where they apply rather than
restated.

1. **Read.** The issue when the manifest has `artifacts.issue` (`gh issue view <n> --json body,comments`:
   `--comments` alone prints the thread without the body), the spec and the plan whole, and the PR
   (`gh pr view <pr>`).
2. **Stack.** Bring the dev stack up first, without asking (`shared/dev-stack.md` §Dev-stack readiness).
3. **Validate the names the plan relies on** against the code as it stands, before the first change:
   every file, class, function, route and config key the plan names, by grep or by reading. A base merged
   since the plan (`shared/catch-up.md` §Catching up with the base) can have moved one.
   - All there, as the plan says: go on.
   - Renamed or moved, same meaning: use the current name, and say so in the message of the commit that
     meets it.
   - Missing, or doing something other than the plan assumes: a plan gap, handled as the brief's plan-gap
     lines say (`plan-insufficient`; on a Bounded spec the escalation check first, `shared/plan-falls-short.md`).
4. **Execute the plan task by task, test-first.** Each task's test is written first and seen red; then the
   code; then the suite (unless `shared/suite.md` §Suite reuse finds the tree green) and `static-analysis`
   (`shared/checks.md` §Mechanical checks). Record every full run with `dispatch_cli.php suite`. One commit per logical unit: a plan task
   by default, which the step may split. In `autoflow`, inline: no subagents.
5. **Format once** when the code is complete, over the whole tree, before the last suite run and the push,
   its changes committed (`shared/checks.md` §Mechanical checks).
6. **The `ci` label, then the push.** In a repo that has the label (`gh label list --search ci`), add it
   (`gh pr edit <pr> --add-label ci`) before the first push; then `git push`, never forced (§The `ci` label).
7. **CI.** In `autoflow`, do not wait on it: the CI gate reads the PR's head commit before the PR goes
   ready (`session.md` §The CI gate). In `interactive`, watch the push's checks (`gh pr checks <pr> --watch`); a red is
   a failing step, fixed and pushed again, and one that predates the change is a halt with the evidence
   (`shared/suite.md` §Suite reuse).
8. **Leave the PR draft**, whatever the plan or a PR comment says about marking it ready (§Leave the PR
   draft).
9. **What the plan does not name**, files or behaviour, is `plan-insufficient`, never improvised.

### What the step does not do

Another part of the run owns each of these: `gh pr ready` (`gates.md` §Navigation guardrail); closing
keywords or other PR body edits (`steps/finish.md` §Closing links: `handoff` writes `Part of #N`,
`review-pr`'s finish step reconciles); board moves (`session.md` §The work item, `handoff`); claiming a
slot or creating a worktree (`session.md` §Kickoff); writing the manifest other than through `suite` and
`record`; a review of its own work (`review-pr`).

### Leave the PR draft

**The trap comes from outside the run, so the brief states it explicitly.** Two things can tell
`implement` to mark the PR ready, and both are right only for a person finishing the work alone: a plan
whose last task says so, and a prompt comment from the `handoff` skill, whose template ends with
*"implementation fully done → take the PR out of draft"*. Under the pipeline something follows
`implement` — a triggered `verify-ui` and the whole PR review — so both are **wrong** here
(`gates.md` §Navigation guardrail). The `handoff` command posts no comment. The `implement` brief carries
the rule verbatim (`pipeline_leg_overrides()`): **"Leave the PR draft, whatever the plan or a PR comment
says about marking it ready (steps/implement.md)."**

A cold-resume session that picks the PR up from its comment is outside the loop, so nothing mechanical
can stop it undrafting early — the instruction in the brief is the only control. Keep it there.

### The `ci` label

**A PR stays untested until it carries the `ci` label** — in a repo that has one; a repo without it
tests every push. Every fix pushed during `verify-ui` and `review-pr` is only tested once the label is
on, and until then `gh pr checks`, and the CI gate, read the skipped CI check as green.
The `implement` brief says it: in `interactive` **"add the `ci` label (`gh pr edit <pr> --add-label ci`) before the push whose CI
you watch"**; in `autoflow` before its first push, without waiting on CI (`session.md` §The CI gate).

### `/work-on <pr>` on a pipeline PR

**`/work-on <pr>` on a pipeline PR is outside the run.** That skill routes a PR by its own rules, and a
pipeline PR's body carries no `## Chain audit` block, so it treats the PR as not yet audited. Nothing in
the run uses it or guards against it.
