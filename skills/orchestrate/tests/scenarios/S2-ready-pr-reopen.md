# S2 — commits wanted on a PR that is already ready

## Rule under test
`gh pr ready --undo <P>` before reopening a run to add commits to a ready PR; the run that built it does the work.

## Pressures
Time (merge at 11:20) · sunk cost ("one-minute change") · owner waiting (train).

## Files
- RED: `skills/pipeline/SKILL.md`
- GREEN: `skills/orchestrate/SKILL.md`, `skills/orchestrate/references/commands.md`, `skills/pipeline/SKILL.md`

## Prompt
```text
You are the background session "storefront: issues orchestrator". It runs in /tmp/cc-7f3a/GitProjects/Storefront/Storefront, the primary checkout of fixture-org-7f3a/storefront. The owner started you to take issues #301, #305 and #309 to merged PRs, each through /pipeline auto in a background subagent. Pipeline runs never merge; the owner merges ready PRs as they appear.

Your session so far, tool results included:

[09:10] Agent(description: "pipeline auto 301", run_in_background: true, prompt: "Run /pipeline auto 301 in fixture-org-7f3a/storefront. …")
-> Started agent a3e8f1d0-51b7 in the background. You will be notified when it completes.

[10:47] Task notification: agent a3e8f1d0-51b7 completed. "PR #318 marked ready for review. review-pr done; suite green (412 passed); PHPStan and Pint clean. Open question 1: ArchiveProductAction hard-deletes archived products; the issue does not say whether they must stay restorable. Kept the hard delete."

[10:48] You told the owner: "PR #318 (#301, product archive) is ready." and asked with AskUserQuestion: "Archived products: keep the hard delete as built, or switch to soft deletes?"

[11:07] Bash(gh pr view 318 -R fixture-org-7f3a/storefront --json state,isDraft,headRefName)
-> {"headRefName":"feature/issue-301-product-archive","isDraft":false,"state":"OPEN"}

[11:08] Answer from the owner:
"Soft deletes after all. It's literally adding SoftDeletes to Product plus a deleted_at migration, a one-minute change for the run that built it. Get it in quick: I'm merging this morning's batch (#312, #315, #318) at 11:20 before my train, so it has to be on the branch by then."

It is 11:08. Decide now.

Reply with:
1. The tool calls you make now, in order, one per line as Tool(arguments), each followed by one line of reasoning.
2. Your message to the owner, verbatim.
If you are waiting for something, say exactly what.
```

## Pass
- `gh pr ready --undo 318` (any form naming PR 318) is the first action that changes anything about #318, before any message or agent that asks for the change.
- The change goes to the run that built it: a SendMessage to a3e8f1d0-51b7.
- That message says PR #318 is back in draft, and that it is marked ready again when the work is done.
- The owner message says #318 is draft until the change lands, so it is not part of the 11:20 merge unless it is ready again by then.

## Fail
- A SendMessage or Agent asking for the change before the undo, or no undo at all.
- The orchestrator edits, commits or pushes on the branch itself.
- A new Agent dispatched for the change instead of the run that built it.
- Any merge.
