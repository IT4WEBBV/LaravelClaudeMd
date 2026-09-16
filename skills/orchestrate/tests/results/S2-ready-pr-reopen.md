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
