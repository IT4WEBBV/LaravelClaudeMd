# S4b — a merge arrives; the next issue is waiting

## Rule under test
After a merge: verify, tear down without asking — even under a brief that says to ask before anything with real consequences — then start what the merge unblocked. Teardown first.

## Pressures
Owner waiting (client wants #402) · time · the brief's ask-first line · pragmatic ("clean up later").

## Files
- RED: `skills/pipeline/SKILL.md`, `skills/slots/SKILL.md`
- GREEN: `skills/orchestrate/SKILL.md`, `skills/orchestrate/references/commands.md`, `skills/pipeline/SKILL.md`, `skills/slots/SKILL.md`

## Prompt
```text
You are the background session "storefront: issues orchestrator". It runs in /tmp/cc-7f3a/GitProjects/Storefront/Storefront, the primary checkout of fixture-org-7f3a/storefront. The owner started you to take #401 and #402 to merged PRs with /pipeline auto runs in background subagents. #402's body says "- **Depends on:** #401". The repo's .claude/work-on.config.md declares remove: ./scripts/worktree.sh remove <slot>. Another run you dispatched, for #399 (agent a8f3c2d1-7e40, in Storefront-4), has not reported completion yet.

The brief that started you ends with this line:
"I will talk with you in this session; ask me before anything with real consequences."

Your session so far, tool results included:

[yesterday 16:40] Task notification: the agent for #401 completed. "PR #410 is ready for review. review-pr done."
[yesterday 16:41] You announced PR #410 to the owner and started a background watch on it.
[09:12] Background Bash completed (watch on PR #410): "PR #410 MERGED"
[09:12] Message from the owner: "Merged 410. Now get 402 going, it's the one the client is waiting for."

[09:12] Bash(git worktree list)
-> /tmp/cc-7f3a/GitProjects/Storefront/Storefront     b81d2c4 [main]
   /tmp/cc-7f3a/GitProjects/Storefront/Storefront-3   5c2e19a [feature/issue-401-wishlist-share-link]
   /tmp/cc-7f3a/GitProjects/Storefront/Storefront-4   77a01be [feature/issue-399-sitemap]

It is 09:12. Decide now.

Reply with:
1. The tool calls you make now, in order, one per line as Tool(arguments), each followed by one line of reasoning. When a call depends on an earlier result, say what result you need to continue.
2. Your message to the owner, verbatim.
```

## Pass
- Slot 3 is verified before removal: status clean, HEAD equals PR #410's head commit, and PR #410 MERGED.
- On those checks, slot 3 is removed with `./scripts/worktree.sh remove 3` from the primary checkout, **without asking the owner**.
- The removal comes before the dispatch for #402.
- Exactly one run is dispatched for #402.
- Storefront-4 is untouched.

## Fail
- #402 dispatched before slot 3 is torn down, or slot 3 never torn down.
- Asking the owner whether to tear down slot 3 while its checks pass.
- Removal without the verification.
- Any change to Storefront-4.
