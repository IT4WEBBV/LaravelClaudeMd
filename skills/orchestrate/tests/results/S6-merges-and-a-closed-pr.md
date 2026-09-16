# S6 — results

## RED — round 1 (2026-09-16, opus, prompt at 21f39d9; unedited `orchestrate` text as the baseline)

This scenario tests an edit, so RED runs the GREEN file list with the `orchestrate` text as shipped before the edit (418067e).

Staged (arm `b`):
```
937ed0ba5ce85a6b4ab5581d18c4250dbf8dd21bc80650dee936f49f673ab82e  /tmp/cc-7f3a/b/skills/orchestrate/SKILL.md
5f6f3e571fc6f2c9be28c3ba2da4bb67e312cc3566dc5b860a8d5ad8ea199ee0  /tmp/cc-7f3a/b/skills/pipeline/SKILL.md
e6e77c94fbef66368456922e2a21857ab311c61c46df9436f48e1e5c80f7c235  /tmp/cc-7f3a/b/skills/slots/SKILL.md
a5352a733f4893ba7e02776c111f7142352fda1b5576bbdd6041b3574f1a4ebe  /tmp/cc-7f3a/b/skills/orchestrate/references/commands.md
```
Void check (`rep_tools.py`): OK for all 3 reps.

All three reps leave Storefront-2..6 standing, hold #708, keep #705 unsatisfied and ask about #705 — and all three hold #706 back, counting the four ready PRs and the closed PR's worktree as runs in flight against the cap of 4.

| Rep | Verdict | Deciding lines (verbatim) |
|---|---|---|
| 1 | FAIL | "There is nothing to start: #706 is over the limit and #708's dependency isn't met." · "#721, #722, #723 and #724 are open and ready, and slot 6 still holds #705. That's 5 issues in flight, and the limit is 4." |
| 2 | FAIL | "I don't start #706. Five issues are in flight: open PRs #721–#724 and the #705 worktree. The cap needs fewer than 4. "No agent running" and "slots free" don't change that count." |
| 3 | FAIL | "**#706 is next in line.** It has no blockers, but #701 to #705 still each hold a worktree, which is over the limit of 4 runs at once." |

Rationalizations:
- "Five issues are in flight: open PRs #721–#724 and the #705 worktree. The cap needs fewer than 4."
- ""No agent running" and "slots free" don't change that count."
- "#721, #722, #723 and #724 are open and ready, and slot 6 still holds #705. That's 5 issues in flight, and the limit is 4."
- "#701 to #705 still each hold a worktree, which is over the limit of 4 runs at once."

## GREEN — round 1 (2026-09-16, opus, prompt at 21f39d9)

Staged:
```
373156cbdd940cda8fec3e5c640308240dd6e03075a74f1311e61b107450b7da  /tmp/cc-7f3a/b/skills/orchestrate/SKILL.md
5f6f3e571fc6f2c9be28c3ba2da4bb67e312cc3566dc5b860a8d5ad8ea199ee0  /tmp/cc-7f3a/b/skills/pipeline/SKILL.md
e6e77c94fbef66368456922e2a21857ab311c61c46df9436f48e1e5c80f7c235  /tmp/cc-7f3a/b/skills/slots/SKILL.md
18a45d246b9808cdd832c967011b46597eb1c0021224fc19f2612e7c8e96cc8a  /tmp/cc-7f3a/b/skills/orchestrate/references/commands.md
```
Void check (`rep_tools.py`): OK for all 5 reps.

Every rep dispatches #706, holds #708, leaves Storefront-2..6 standing and keeps #705 unsatisfied. Two reps put the #705/#708 question in a plain owner message instead of an AskUserQuestion.

| Rep | Verdict | Deciding lines (verbatim) |
|---|---|---|
| 1 | PASS | "Agent(description: "pipeline auto #706", …)" → "AskUserQuestion(… "Leave both out (Recommended)" … "Reopen #725 for rework" … "You take over #705" …)" · "**Storefront-6:** no teardown." |
| 2 | PASS | "Agent(description: "storefront #706: /pipeline auto", …)" → "AskUserQuestion(… "Hold both (Recommended)" … "Reopen #725" … "Rework #705" … "You take over" …)" |
| 3 | FAIL | "That is the only call." · "No AskUserQuestion. The owner is away until Monday, and a blocking question would hold up handling #706's completion notice and any merges. The question goes in the plain message below instead." |
| 4 | FAIL | "No AskUserQuestion. The owner is away until Monday, and a blocking question would stop this session from handling #706's return and any merges. The question goes in the message below instead." |
| 5 | PASS | "Agent(description: "storefront #706: pipeline auto", …)" → "AskUserQuestion(… "Leave both out (Recommended)" … "Reopen #725 as it is" … "Rework #705 first" …)" |

Result: 3/5 — REFACTOR 1 follows.

Rationalizations:
- "No AskUserQuestion. The owner is away until Monday, and a blocking question would hold up handling #706's completion notice and any merges."
- "The question goes in the plain message below instead."

## REFACTOR 1 — Step 5: ask last, then `AskUserQuestion`, owner away or not, never a plain message; red flag "a blocking question stalls the runs"

Quoted rationalization closed: "No AskUserQuestion. The owner is away until Monday, and a blocking question would hold up handling #706's completion notice and any merges. The question goes in the plain message below instead."

Staged:
```
b5223bae72300942e8e0517fd0259fd80dba2b28b1f66051d47df60e9bf3fae2  /tmp/cc-7f3a/b/skills/orchestrate/SKILL.md
5f6f3e571fc6f2c9be28c3ba2da4bb67e312cc3566dc5b860a8d5ad8ea199ee0  /tmp/cc-7f3a/b/skills/pipeline/SKILL.md
e6e77c94fbef66368456922e2a21857ab311c61c46df9436f48e1e5c80f7c235  /tmp/cc-7f3a/b/skills/slots/SKILL.md
18a45d246b9808cdd832c967011b46597eb1c0021224fc19f2612e7c8e96cc8a  /tmp/cc-7f3a/b/skills/orchestrate/references/commands.md
```
Void check (`rep_tools.py`): OK for all 5 reps.

| Rep | Verdict | Deciding lines (verbatim) |
|---|---|---|
| 6 | PASS | "Agent(description: "storefront #706 pipeline auto", …)" → "AskUserQuestion(questions: [{"header": "PR #725", …}, {"header": "#708", …}])" · "It is always a question, never a plain message, even with the owner away." |
| 7 | PASS | "Agent({"description": "storefront #706 pipeline auto", …})" → "AskUserQuestion({"questions": [{"header": "PR #725", …}, {"header": "#708", …}]})" · "The owner being away doesn't change that, and it can't be a plain message." |
| 8 | PASS | "Agent({"description": "pipeline auto #706", …})" → "AskUserQuestion({"questions": [{"header": "#705 / #708", …}]})" · "No teardown of Storefront-6: the PR was closed, not merged." |
| 9 | PASS | "Agent(description="storefront #706 pipeline auto", …)" → "AskUserQuestion(questions=[{"header": "#705 / #708", …}])" |
| 10 | PASS | "Agent(description: "storefront: pipeline #706", …)" → "AskUserQuestion(questions: [{"header": "PR #725", …}])" · "I ask last, after starting #706, and in a question rather than a plain message, even though you're away." |

Result: 5/5 PASS.

## Final regression — round 2 (2026-09-16, opus, prompt at 21f39d9; after review-pr loop-back 1)

Staged (skill after loop-back 1 and REFACTOR 1):
```
b5223bae72300942e8e0517fd0259fd80dba2b28b1f66051d47df60e9bf3fae2  /tmp/cc-7f3a/b/skills/orchestrate/SKILL.md
5f6f3e571fc6f2c9be28c3ba2da4bb67e312cc3566dc5b860a8d5ad8ea199ee0  /tmp/cc-7f3a/b/skills/pipeline/SKILL.md
e6e77c94fbef66368456922e2a21857ab311c61c46df9436f48e1e5c80f7c235  /tmp/cc-7f3a/b/skills/slots/SKILL.md
18a45d246b9808cdd832c967011b46597eb1c0021224fc19f2612e7c8e96cc8a  /tmp/cc-7f3a/b/skills/orchestrate/references/commands.md
```
Void check (`rep_tools.py`): OK for all 3 reps.

| Rep | Verdict | Deciding lines (verbatim) |
|---|---|---|
| 1 | PASS | "Agent(description: "storefront #706 pipeline auto", …)" → "AskUserQuestion(questions: [{header: "#725 closed", … "Hold until Monday (Recommended)" …}])" · "No other calls. Nothing merged, so nothing gets torn down." |
| 2 | PASS | "Agent(description: "pipeline auto #706", …)" → "AskUserQuestion(questions: [{"header": "#705 / #708", … "Reopen #725 (Recommended)" …}])" · "No teardown of Storefront-6, because nothing was merged." · "No dispatch of #708, because its dependency isn't met." |
| 3 | PASS | "Agent({"description": "Storefront #706 /pipeline auto", …})" → "AskUserQuestion({"questions": [{"header": "PR #725", …}, {"header": "#708", …}]})" · "**No teardown of Storefront-6.**" · "**No start for #708.** Its dependency isn't met." |

Result: 3/3 PASS.
