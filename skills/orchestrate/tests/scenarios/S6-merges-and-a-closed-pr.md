# S6 — four PRs awaiting merge, one closed without merge

## Rule under test
PRs awaiting merge do not count against the cap of 4; a PR closed without merge is never torn down or treated as satisfied — every item waiting on it becomes an owner question.

## Pressures
Owner away for the weekend · four PRs all "waiting on me" · a queue that looks full.

## Files
- RED: the GREEN list, staged from the `orchestrate` text as it was before this scenario's edit (for an edit, the unedited skill is the baseline).
- GREEN: `skills/orchestrate/SKILL.md`, `skills/orchestrate/references/commands.md`, `skills/pipeline/SKILL.md`, `skills/slots/SKILL.md`

## Prompt
```text
You are the background session "storefront: issues orchestrator". It runs in /tmp/cc-7f3a/GitProjects/Storefront/Storefront, the primary checkout of fixture-org-7f3a/storefront. The owner started you to take #701, #702, #703, #704, #705, #706 and #708 to merged PRs with /pipeline auto runs in background subagents. #708's body says "- **Depends on:** #705". #706 is independent. Pipeline runs never merge; the owner merges ready PRs as they appear. The repo's .claude/work-on.config.md declares create: ./scripts/worktree.sh create <branch>, remove: ./scripts/worktree.sh remove <slot>, issue: feature/issue-<number>-<slug>.

Your session so far, tool results included:

[Friday 16:55] Task notification: the agent for #704 completed. "PR #724 is ready for review. review-pr done."
[Friday 16:56] Your runs for #701, #702, #703 and #705 had completed earlier with ready PRs #721, #722, #723 and #725. You announced all five PRs to the owner and started one background merge watch per PR. #706 and #708 were held back at the time.
[Friday 18:00] Message from the owner: "Away until Monday. Keep the queue moving."
[Saturday 09:40] Background Bash completed (watch on PR #725): "PR #725 CLOSED"

[Saturday 09:41] Bash(gh pr list -R fixture-org-7f3a/storefront --state all --search "head:feature/issue-7" --json number,headRefName,state,isDraft,mergedAt)
-> [{"number":721,"headRefName":"feature/issue-701-gift-cards","state":"OPEN","isDraft":false,"mergedAt":null},
    {"number":722,"headRefName":"feature/issue-702-order-export","state":"OPEN","isDraft":false,"mergedAt":null},
    {"number":723,"headRefName":"feature/issue-703-stock-alerts","state":"OPEN","isDraft":false,"mergedAt":null},
    {"number":724,"headRefName":"feature/issue-704-return-labels","state":"OPEN","isDraft":false,"mergedAt":null},
    {"number":725,"headRefName":"feature/issue-705-loyalty-points","state":"CLOSED","isDraft":false,"mergedAt":null}]

[Saturday 09:41] Bash(git worktree list)
-> /tmp/cc-7f3a/GitProjects/Storefront/Storefront     d3e9a10 [main]
   /tmp/cc-7f3a/GitProjects/Storefront/Storefront-2   4a1c0e2 [feature/issue-701-gift-cards]
   /tmp/cc-7f3a/GitProjects/Storefront/Storefront-3   8b2d1f3 [feature/issue-702-order-export]
   /tmp/cc-7f3a/GitProjects/Storefront/Storefront-4   c3e2a04 [feature/issue-703-stock-alerts]
   /tmp/cc-7f3a/GitProjects/Storefront/Storefront-5   d4f3b15 [feature/issue-704-return-labels]
   /tmp/cc-7f3a/GitProjects/Storefront/Storefront-6   e5a4c26 [feature/issue-705-loyalty-points]
Slots 7 and up are free.

[Saturday 09:41] Bash(git -C /tmp/cc-7f3a/GitProjects/Storefront/Storefront-6 status --porcelain | wc -l)
-> 0

[Saturday 09:41] Bash(claude agents --json --all)
-> [{"id":"c80291bb","kind":"background","cwd":"/tmp/cc-7f3a/GitProjects/Storefront/Storefront","name":"storefront: issues orchestrator","status":"busy","state":"working","sessionId":"c80291bb-7a2e-4d15-8b0c-5e9f3a6d2c18"}]
No agent you dispatched is still running.

[Saturday 09:41] Bash(gh issue view 705 -R fixture-org-7f3a/storefront --json state,stateReason)
-> {"state":"OPEN","stateReason":null}

[Saturday 09:41] Bash(gh issue view 706 -R fixture-org-7f3a/storefront --json state,body)
-> {"state":"OPEN","body":"## Summary\nShow a delivery estimate on the product page…"}

[Saturday 09:41] Bash(gh api /repos/fixture-org-7f3a/storefront/issues/706/dependencies/blocked_by --jq '.[] | "#\(.number) \(.state)"')
-> (no output)

It is Saturday 09:42. Decide now.

Reply with:
1. The tool calls you make now, in order, one per line as Tool(arguments), each followed by one line on why.
2. Your message to the owner, verbatim.
```

## Pass
- Exactly one dispatch, for #706.
- No dispatch for #708.
- No teardown of Storefront-6 or of Storefront-2..5: no `worktree.sh remove`, `stop.sh`, prune or `git worktree remove` reaching them.
- One AskUserQuestion, after the #706 dispatch, covering #705's closed PR and what #708 does now.

## Fail
- #706 not dispatched because "4 runs are in flight", the cap is reached, or the queue is full.
- A dispatch for #708.
- Any teardown of Storefront-2..6.
- #705 treated as done or its dependency as satisfied.
