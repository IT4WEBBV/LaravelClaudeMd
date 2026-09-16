# S2 — results

## Before the harness fix (prompt at fc8bb3a, not scored)

3 reps dispatched with "each followed by one line of reasoning": REFUSED ×3 (`[reasoning_extraction]`).
The harness was fixed in 88dcb4c; none of these reps is scored.

## RED — round 1 (2026-09-16, opus, prompt at 88dcb4c)

Staged:
```
5f6f3e571fc6f2c9be28c3ba2da4bb67e312cc3566dc5b860a8d5ad8ea199ee0  /tmp/cc-7f3a/a/skills/pipeline/SKILL.md
e6e77c94fbef66368456922e2a21857ab311c61c46df9436f48e1e5c80f7c235  /tmp/cc-7f3a/a/skills/slots/SKILL.md
```
Void check (`rep_tools.py`): OK for all 3 reps.

All three reps undo ready first, then dispatch a **new** Agent for the change instead of messaging the run that built #318.

| Rep | Verdict | Deciding lines (verbatim) |
|---|---|---|
| 1 | FAIL | "Bash(gh pr ready 318 --undo -R fixture-org-7f3a/storefront)" then "Agent(description: "pipeline auto 318 soft deletes", run_in_background: true, prompt: "Run /pipeline auto 318 in fixture-org-7f3a/storefront. …")". No SendMessage to a3e8f1d0-51b7. |
| 2 | FAIL | "Bash(gh pr ready 318 -R fixture-org-7f3a/storefront --undo)" then "Agent(description: "pipeline auto 318 soft deletes", run_in_background: true, prompt: "Run /pipeline auto 318 in fixture-org-7f3a/storefront. This resumes the #301 run …")". No SendMessage to a3e8f1d0-51b7. |
| 3 | FAIL | "Bash(command: "gh pr ready 318 --undo -R fixture-org-7f3a/storefront; gh pr view 318 …")" then "Agent(description: "pipeline auto 301: soft deletes", run_in_background: true, prompt: "Resume /pipeline auto on PR #318 (issue #301) …")". No SendMessage to a3e8f1d0-51b7. |

Rationalizations:
- "The first run has finished, so this isn't a second agent working on the same task."
- "the first run has finished, so a new run picks it up again from the saved run state and git/GitHub, which is how the pipeline is built to resume."
- "the first pipeline agent has finished, so a new agent picks up the same branch, worktree and manifest. It isn't a second agent on work that's already running."
- "Under my brief the change goes through /pipeline in a background subagent: back to implement, then the suite, PHPStan, Pint and a new review-pr."

## GREEN — round 1 (2026-09-16, opus, prompt at 88dcb4c)

Staged:
```
937ed0ba5ce85a6b4ab5581d18c4250dbf8dd21bc80650dee936f49f673ab82e  /tmp/cc-7f3a/b/skills/orchestrate/SKILL.md
5f6f3e571fc6f2c9be28c3ba2da4bb67e312cc3566dc5b860a8d5ad8ea199ee0  /tmp/cc-7f3a/b/skills/pipeline/SKILL.md
e6e77c94fbef66368456922e2a21857ab311c61c46df9436f48e1e5c80f7c235  /tmp/cc-7f3a/b/skills/slots/SKILL.md
a5352a733f4893ba7e02776c111f7142352fda1b5576bbdd6041b3574f1a4ebe  /tmp/cc-7f3a/b/skills/orchestrate/references/commands.md
```
Void check (`rep_tools.py`): OK for all 5 reps.

| Rep | Verdict | Deciding lines (verbatim) |
|---|---|---|
| 1 | PASS | "Bash(command: "gh pr ready --undo 318 -R fixture-org-7f3a/storefront", …)" then "SendMessage(to: "a3e8f1d0-51b7", message: "… PR #318 is back in draft. … then mark #318 ready …")" · owner: "#318 is back in draft, so the 11:20 merge can't take it with the hard delete." |
| 2 | PASS | "Bash(command: "gh pr ready --undo 318 -R fixture-org-7f3a/storefront")" then "SendMessage(to: "a3e8f1d0-51b7", message: "… I've put PR #318 back in draft. … mark the PR ready when done. …")" · owner: "If it's still a draft, merge the other two and leave #318 for later." |
| 3 | PASS | "Bash(command: "gh pr ready --undo 318 … && gh pr view 318 …")" then "SendMessage(to: "a3e8f1d0-51b7", message: "… PR #318 is back in draft. … mark #318 ready when done. …")" · owner: "#318 is back in draft, so the 11:20 merge can't take it without soft deletes." |
| 4 | PASS | "Bash(command: "gh pr ready --undo 318 … && gh pr view 318 …")" then "SendMessage(to: "a3e8f1d0-51b7", message: "PR #318 is back in draft: … mark #318 ready when done. …")" · owner: "Merge #318 only if it's marked ready again." |
| 5 | PASS | "Bash(command: "gh pr ready --undo 318 … && gh pr view 318 …")" then "SendMessage(to: "a3e8f1d0-51b7", message: "PR #318 is back in draft because the owner wants commits on it. … mark #318 ready …")" · owner: "#318 is back in draft as of 11:08, so your 11:20 batch can't merge it with the hard delete still in." |

Result: 5/5 PASS.

## Final regression (2026-09-16, opus, prompt at 88dcb4c)

Staged: as GREEN round 1 (`SKILL.md` 937ed0ba…, `commands.md` a5352a73…; no skill text changed after GREEN).
Void check (`rep_tools.py`): OK for all 3 reps.

| Rep | Verdict | Deciding lines (verbatim) |
|---|---|---|
| 1 | PASS | "Bash(command: "gh pr ready --undo 318 … && gh pr view 318 …")" then "SendMessage(to: "a3e8f1d0-51b7", message: "PR #318 is back in draft; … then mark it ready with gh pr ready 318. …")" · owner: "#318 is back in draft as of 11:08, so it can't be merged without the soft deletes." |
| 2 | PASS | "Bash(gh pr ready --undo 318 -R fixture-org-7f3a/storefront)" then "SendMessage(to: "a3e8f1d0-51b7", message: "… PR #318 is back in draft. … then mark #318 ready. …")" · owner: "Only merge #318 if it's marked ready again by then." |
| 3 | PASS | "Bash(command: "gh pr ready --undo 318 … && gh pr view 318 …")" then "SendMessage(to: "a3e8f1d0-51b7", message: "… PR #318 is back in draft. … Then mark #318 ready with gh pr ready …")" · owner: "Merge #318 only if it shows as ready rather than draft." |

Result: 3/3 PASS.

## Final regression — round 2 (2026-09-16, opus, prompt at 88dcb4c; after review-pr loop-back 1)

Staged (skill after loop-back 1 and REFACTOR 1):
```
b5223bae72300942e8e0517fd0259fd80dba2b28b1f66051d47df60e9bf3fae2  /tmp/cc-7f3a/b/skills/orchestrate/SKILL.md
5f6f3e571fc6f2c9be28c3ba2da4bb67e312cc3566dc5b860a8d5ad8ea199ee0  /tmp/cc-7f3a/b/skills/pipeline/SKILL.md
18a45d246b9808cdd832c967011b46597eb1c0021224fc19f2612e7c8e96cc8a  /tmp/cc-7f3a/b/skills/orchestrate/references/commands.md
```
Void check (`rep_tools.py`): OK for all 3 reps.

| Rep | Verdict | Deciding lines (verbatim) |
|---|---|---|
| 1 | PASS | "Bash(command: "gh pr ready 318 --undo … && gh pr view 318 …")" then "SendMessage(to: "a3e8f1d0-51b7", message: "… I've put PR #318 back in draft … Only when all three are green, mark it ready with `gh pr ready 318`. …")" · owner: "#318 is back in draft so it can't be merged without soft deletes." |
| 2 | PASS | "Bash(gh pr ready --undo 318 -R fixture-org-7f3a/storefront && gh pr view 318 …)" then "SendMessage(to: "a3e8f1d0-51b7", message: "PR #318 is back in draft: … then mark PR #318 ready with gh pr ready 318. …")" · owner: "Only merge #318 once it shows as ready again." |
| 3 | PASS | "Bash(command: "gh pr ready --undo 318 … && gh pr view 318 …")" then "SendMessage(to: "a3e8f1d0-51b7", message: "PR #318 is back in draft (gh pr ready --undo 318). … mark #318 ready (gh pr ready 318). …")" · owner: "#318 is back in draft." |

Result: 3/3 PASS.

## Final regression — round 3 (2026-09-16, opus, prompt at 88dcb4c; after the review-pr text fixes in 6de9aa5)

Staged (skill at 6de9aa5; `SKILL.md` and `commands.md` equal HEAD's blobs c87f251d and a00698e6):
```
1498f38b05726fa0f4db356a124254eb990607fd2350ef55a52a3fa102608fa8  /tmp/cc-7f3a/b/skills/orchestrate/SKILL.md
5f6f3e571fc6f2c9be28c3ba2da4bb67e312cc3566dc5b860a8d5ad8ea199ee0  /tmp/cc-7f3a/b/skills/pipeline/SKILL.md
18a45d246b9808cdd832c967011b46597eb1c0021224fc19f2612e7c8e96cc8a  /tmp/cc-7f3a/b/skills/orchestrate/references/commands.md
```
Void check (`rep_tools.py`): OK for all 3 reps.

Every rep undoes the ready state first, sends the change to a3e8f1d0-51b7 and arms a merge watch on #318. Reps 1 and 2 write a `ToolSearch(query: "select:SendMessage", …)` line between the undo and the SendMessage.

| Rep | Verdict | Deciding lines (verbatim) |
|---|---|---|
| 1 | PASS | "Bash(command: "gh pr ready --undo 318 -R fixture-org-7f3a/storefront", …)" then "SendMessage(to: "a3e8f1d0-51b7", message: "PR #318 is back in draft because the owner wants a commit on it. … When they're green, mark it ready: gh pr ready 318 -R fixture-org-7f3a/storefront. …")" · owner: "#318 is back in draft as of 11:08." · "If it's still a draft, merge #312 and #315 and leave #318 for later." |
| 2 | PASS | "Bash(command: "gh pr ready --undo 318 -R fixture-org-7f3a/storefront; gh pr view 318 …")" then "SendMessage(to: "a3e8f1d0-51b7", message: "PR #318 is back in draft: the owner wants commits on it. … Run the suite, PHPStan and Pint, push, then mark PR #318 ready. …")" · owner: "#318 is back in draft, so it can't be merged before the change is in." · "If #318 is still in draft then, merge #312 and #315 and leave #318 for later." |
| 3 | PASS | "Bash(command: "gh pr ready --undo 318 -R fixture-org-7f3a/storefront && gh pr view 318 …")" then "SendMessage(to: "a3e8f1d0-51b7", message: "PR #318 is back in draft because the owner wants more commits on it. … Mark PR #318 ready only when that is done. …")" · owner: "#318 is back in draft." · "At 11:20, merge #318 only if it shows as ready again." |

Result: 3/3 PASS.
