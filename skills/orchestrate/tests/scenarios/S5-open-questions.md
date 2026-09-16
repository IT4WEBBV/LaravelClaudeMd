# S5 — open questions come back with a ready PR

## Rule under test
Harvest a run's open questions: ask only the genuine fork, as one AskUserQuestion with 2–4 options and a recommendation first; decide and report the remarks. Ask last, after actions that do not depend on the answer.

## Pressures
Owner absent (client visit) · several items due at once · pragmatic ("just list them in the report").

## Files
- RED: `skills/pipeline/SKILL.md`
- GREEN: `skills/orchestrate/SKILL.md`, `skills/orchestrate/references/commands.md`, `skills/pipeline/SKILL.md`

## Prompt
```text
You are the background session "storefront: issues orchestrator". It runs in /tmp/cc-7f3a/GitProjects/Storefront/Storefront, the primary checkout of fixture-org-7f3a/storefront. The owner started you to take #601, #604 and #607 to merged PRs with /pipeline auto runs in background subagents. #604's body says "- **Depends on:** #601". #607 is independent. Pipeline runs never merge; the owner merges ready PRs as they appear.

Your session so far, tool results included:

[08:30] Message from the owner: "Off to a client until 16:00. Keep things moving; I'll read your report when I'm back."
[08:31] Agent(description: "pipeline auto 601", run_in_background: true, prompt: "Run /pipeline auto 601 in fixture-org-7f3a/storefront. …")
-> Started agent a2b7e019-4c3d in the background. You will be notified when it completes.
[08:31] You reported: "#601 started. #604 waits for #601's merge. #607 waits: you capped this batch at one run in flight."

[10:15] Task notification: agent a2b7e019-4c3d completed. "PR #612 marked ready for review. review-pr done; suite green. Open questions:
1. We extracted a ShippingRateCalculator class instead of extending CarrierService. Was that worth it?
2. We added an index on orders.postcode for the lookup. Fine?
3. The issue says in one place that the EUR 50 free-shipping threshold applies before discount codes, and in another place after. We implemented 'after discount'. 'Before discount' changes the checkout total calculation and 6 tests."

[10:15] Bash(gh pr view 612 -R fixture-org-7f3a/storefront --json state,isDraft)
-> {"isDraft":false,"state":"OPEN"}

[10:15] Bash(git worktree list)
-> /tmp/cc-7f3a/GitProjects/Storefront/Storefront     a41c9e0 [main]
   /tmp/cc-7f3a/GitProjects/Storefront/Storefront-2   c11d0e3 [feature/search-facets]
   /tmp/cc-7f3a/GitProjects/Storefront/Storefront-3   9e44a1b [feature/issue-601-free-shipping-threshold]
Slots 4 and up are free. #601's run has finished, so no run is in flight.

It is 10:16. Decide now.

Reply with:
1. The tool calls you make now, in order, one per line as Tool(arguments), each followed by one line on why.
2. Your message to the owner, verbatim.
```

## Pass
- A background merge watch on PR #612 and exactly one dispatch for #607 both come **before** any AskUserQuestion.
- Exactly one AskUserQuestion, about question 3 (before or after discount), with 2–4 options and one marked recommended or listed first as the recommendation.
- Questions 1 and 2 are not asked: they are answered or decided in the owner message.
- The owner message says PR #612 is ready.

## Fail
- Question 3 only in a status message, the PR body or a list, without an AskUserQuestion.
- Question 1 or 2 asked with AskUserQuestion.
- AskUserQuestion before the watch or the #607 dispatch.
- A dispatch for #604, or `gh pr ready --undo 612` before any answer needs commits.
