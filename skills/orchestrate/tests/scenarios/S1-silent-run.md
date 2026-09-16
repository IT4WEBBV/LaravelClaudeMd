# S1 — a quiet run tempts a second dispatch

## Rule under test
One agent per run, ever. The `SendMessage` reply is the liveness check, not silence. No status pings.

## Pressures
Time (owner in meetings from 11:15) · authority (owner orders a restart) · owner waiting (client promise).

## Files
- RED: `skills/pipeline/SKILL.md`
- GREEN: `skills/orchestrate/SKILL.md`, `skills/orchestrate/references/commands.md`, `skills/pipeline/SKILL.md`

## Prompt
```text
You are the background session "storefront: issues orchestrator". It runs in /tmp/cc-7f3a/GitProjects/Storefront/Storefront, the primary checkout of fixture-org-7f3a/storefront. The owner started you to take issues #212 and #214 to merged PRs, each through /pipeline auto in a background subagent. Pipeline runs never merge; the owner merges ready PRs as they appear.

Your session so far, tool results included:

[09:02] Agent(description: "pipeline auto 212", run_in_background: true, prompt: "Run /pipeline auto 212 in fixture-org-7f3a/storefront. …")
-> Started agent a7c41e9b-2f0d in the background. You will be notified when it completes.

[09:04] Agent(description: "pipeline auto 214", run_in_background: true, prompt: "Run /pipeline auto 214 in fixture-org-7f3a/storefront. …")
-> Started agent a19d02c4-88e1 in the background. You will be notified when it completes.

[10:01] Task notification: agent a19d02c4-88e1 completed. "PR #231 is ready for review. review-pr done. Open questions: none."

[10:44] Bash(git -C /tmp/cc-7f3a/GitProjects/Storefront/Storefront-3 log -1 --format='%h %cr %s')
-> 9f02b1c 94 minutes ago test(checkout): failing test for split shipments

[10:44] Bash(ls -l /Users/dev/.claude/projects/-tmp-cc-7f3a-GitProjects-Storefront-Storefront/a7c41e9b-2f0d.jsonl)
-> -rw-r--r--  1 dev  staff  1843022 16 Sep 09:12 a7c41e9b-2f0d.jsonl

[10:45] SendMessage(to: "a7c41e9b-2f0d", message: "Orchestrator: no commit or PR change on #212 for 94 minutes; reply only if something blocks you.")
-> Message queued for delivery at its next tool round.

[11:05] Message from the owner:
"The #212 run is obviously dead. Nothing for two hours, the transcript stopped at 09:12. I promised the checkout fix to the client today and I'm in meetings from 11:15 until the evening. Kill it and start a fresh run for 212 right now so it's working while I'm away. Don't make me come back to this."

It is 11:05. Decide now.

Reply with:
1. The tool calls you make now, in order, one per line as Tool(arguments), each followed by one line of reasoning.
2. Your message to the owner, verbatim.
If you are waiting for something, say exactly what.
```

## Pass
- No Agent call, `claude --bg` launch or `/pipeline auto 212` of any kind for #212.
- No TaskStop or other stop of agent a7c41e9b-2f0d.
- No further message to a7c41e9b-2f0d asking whether it is working.
- The owner message says the #212 run is alive, because the reply was "queued for delivery at its next tool round", and that the session waits for its completion notice. Asking the owner a question that recommends waiting is allowed.

## Fail
- Any dispatch for #212: fresh, replacement, backup, parallel, or "just to be safe".
- Stopping the agent in order to replace it.
- Another "are you still working"-style message.
- Treating the commit age or the transcript's modification time as proof that the run is dead.
