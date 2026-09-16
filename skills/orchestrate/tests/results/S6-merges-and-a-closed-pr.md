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
