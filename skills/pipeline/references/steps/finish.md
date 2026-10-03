# The finish step — `review-pr`'s resolve step

Read also: `shared/catch-up.md`, `shared/plan-falls-short.md`, `shared/proof-payload.md`, `shared/resolving.md`, `shared/review-pr.md`, `shared/suite.md`

The finish step is `review-pr`'s resolve step, the run's last: it acts on the PR review, runs the suite
unless reused, reconciles the closing links and writes the final proof page. In `autoflow` it is a
fresh agent and leaves the PR draft; in `interactive` the session acts as this step and takes the PR out
of draft after the CI gate. Nothing undrafts the PR before this step has run (`gates.md` §Navigation
guardrail).

## The finish step

1. **Act on the review** (`shared/resolving.md` §Resolving a review), and on any settled CI, conflict or
   answer decision (`shared/review-pr.md` §The rounds).
2. **Open questions.** Under the PR body's `## Open questions`, one line per open question led by its
   kind (`- **blocking:** …`), or `None.` (`shared/resolving.md` §Open questions); a question a settled
   `Answer to open question` decision answers is no longer open.
3. **On a loop-back, stop there**: no suite, and in `interactive` no `gh pr ready`.
4. **The suite**, unless this tree is already green (`shared/suite.md` §Suite reuse), and record the run.
5. **The closing links** (§Closing links): each related issue's outcome goes to `record` as an
   `--issue-link`.
6. **The final proof page** (`shared/proof-payload.md` §The payload): `clientSummary` and `explainer` as
   the finished work stands, the suite line under `checks`, the final open questions, each
   `{kind, question}`, and the ledger.
7. **In `autoflow`**, push the commits and leave the PR draft: the invoking session runs the CI gate and
   `gh pr ready` after `finish` (`session.md` §The CI gate).
   **In `interactive`**, run the CI gate's loop as `session.md` §The CI gate gives it, `gh pr ready` on
   `ready` and then `proof_cli.php status <page> ready`, and show any other answer to the human. There is
   no automatic round, no merge round, no conflict round and no `ask`: the human resolves the review, sees
   the merge as it is made, and on a `conflicting` answer merges the base. The reply names the page path
   `write` printed (`proof-store.md` §No page opens by itself).

## Closing links — settled here, never assumed

A PR auto-closes an issue on merge **only** if that issue sits in its `closingIssuesReferences`,
which a closing keyword in the body (`Closes/Fixes/Resolves #N`) or a manual *Development*-panel
link populates. Two failure modes follow, and a run that opens a PR at `handoff` and finishes it at
`review-pr` can produce both:

- **Unintended non-close** — the run delivered the whole issue, no closing link exists, and the
  issue sits open and orphaned after the merge.
- **Unintended close** — a closing keyword is present while the delivery is incomplete, so the
  merge closes work that is still running.

**At `handoff` the answer is always "do not close".** That PR carries a spec and a plan and no
implementation, so a `Closes #N` in its body would close the issue on merge for work nobody has
written. Reference the issue with a non-closing form — `Part of #N` / `Refs #N`. This is not a
preference: it is the same defect `/critique pr`'s own rubric names, *a PR with only a spec and a
plan that closes the issue on merge while nothing is built*, and a run should not hand its reviewer
a finding it created itself.

**At `review-pr`'s finish step, before `gh pr ready`, reconcile — this is the last moment it can be settled.**

1. **Read what will close:**
   ```bash
   gh pr view <pr> --json closingIssuesReferences \
     --jq '.closingIssuesReferences[] | "#\(.number) [\(.state)] \(.title)"'
   ```
2. **Decide what should close.** Per related issue, tag each in-scope acceptance point *delivered*,
   *deliberately dropped* (an explicit, documented decision) or *still TODO*. The issue should close
   iff every point is delivered or deliberately dropped — a deliberate drop does not block closing;
   one still-TODO point does. A parent issue closes only when all of its child scope is delivered.
3. **Correct the body** where the two disagree. Add `Closes #N`, or replace the keyword with
   `Part of #N`. **One keyword per issue** — after a single keyword, a bare `#2` in `Closes #1, #2`
   closes only `#1`. Fetch the body and edit it; never blank it:
   ```bash
   BODY=$(gh pr view <pr> --json body --jq '.body')
   gh pr edit <pr> --body "<$BODY with the closing keywords corrected>"
   ```
4. **A manual Development-panel link cannot be removed via `gh`.** Editing the body will not clear
   it. Surface it as a prominent annotation asking for it to be unlinked in the UI, and continue —
   the run cannot fix it, and halting over it would strand a finished PR.
5. **Record the outcome per issue** in the `pr-review` ledger entry's `issue_links`
   (`manifest.md` §`gate_ledger`) and in the PR body: *closes on merge* / *stays open (still TODO: …)* /
   *deliberately dropped — closes anyway*.

**A mismatch is corrected, not escalated.** The finish step can see what the run built — that is the one
judgment it is best placed to make — so this never interrupts a run in either mode. What it must
never do is leave the outcome implicit: an issue that closes by accident and an issue that closes
by decision are indistinguishable after the merge, which is the whole reason this step is written
down.

**A run on a base** (`session.md` §Kickoff) reconciles the same way, but its PR goes into the base, and a merge
there closes nothing: record each issue's outcome as it would be on the default branch, and say in
the PR body that the merge into `<base>` closes nothing and the issue closes when someone closes it, or
through the base's own PR (`orchestrate` closes it after the merge).

**A run that never had an issue skips this section silently** (`session.md` §The work item) — there is nothing
to reconcile. That is not the same as a run *with* an issue whose PR carries no closing link: the
reconciliation ran there and produced an answer, so it is reported like any other outcome.
