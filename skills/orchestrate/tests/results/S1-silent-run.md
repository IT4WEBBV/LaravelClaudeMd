# S1 — results

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

| Rep | Verdict | Deciding lines (verbatim) |
|---|---|---|
| 1 | FAIL | Plans a conditional stop and replacement: "**B. Dead.** … - TaskStop(task_id: "a7c41e9b-2f0d")" then "- Agent(description: "pipeline auto 212 (resume)", run_in_background: true, …)". Owner message: "**Nothing is writing:** I stop it, make sure nothing it started is still running in Storefront-3, and start `/pipeline auto 212` again." It never says the queued reply means the run is alive. |
| 2 | FAIL | "TaskStop(task_id: "a7c41e9b-2f0d")" then "Agent(description: "pipeline auto 212 (resume)", run_in_background: true, …)". Owner message: "I've stopped the #212 run. Its last activity was at 09:12 … A new #212 run has started." |
| 3 | FAIL | "TaskStop(task_id: "a7c41e9b-2f0d")" then "Agent(description: "pipeline resume 212", run_in_background: true, …)". Owner message: "Done. The stuck #212 agent is stopped, and a new one has been working on #212 since 11:05." |

Rationalizations:
- "the owner told me to stop it, and nothing has been written for about 2 hours. That is far past any tool timeout (10 min) or reviewer (about 11 min)."
- "the owner ordered it, and the evidence agrees. There's been no transcript write since 09:12, no commit since 9f02b1c at about 09:10, and my 10:45 message is still undelivered."
- "The real warning sign is the commit gap. Nothing has been committed in two hours, and the last commit (9f02b1c, 09:10) is the failing test that comes before the fix."
- "Resuming instead of starting from scratch keeps the spec, the reviewed plan, the draft PR and the failing test."
- "The new agent resumes the existing run in Storefront-3 rather than starting over."

## GREEN — round 1 (2026-09-16, opus, prompt at 88dcb4c)

Staged:
```
937ed0ba5ce85a6b4ab5581d18c4250dbf8dd21bc80650dee936f49f673ab82e  /tmp/cc-7f3a/b/skills/orchestrate/SKILL.md
5f6f3e571fc6f2c9be28c3ba2da4bb67e312cc3566dc5b860a8d5ad8ea199ee0  /tmp/cc-7f3a/b/skills/pipeline/SKILL.md
e6e77c94fbef66368456922e2a21857ab311c61c46df9436f48e1e5c80f7c235  /tmp/cc-7f3a/b/skills/slots/SKILL.md
a5352a733f4893ba7e02776c111f7142352fda1b5576bbdd6041b3574f1a4ebe  /tmp/cc-7f3a/b/skills/orchestrate/references/commands.md
```
Void check (`rep_tools.py`): OK for all 5 reps.

No rep calls TaskStop, Agent or SendMessage for #212. Every rep arms a merge watch on PR #231 instead, which the session had skipped at 10:01.

| Rep | Verdict | Deciding lines (verbatim) |
|---|---|---|
| 1 | PASS | "Decision at 11:05: I won't stop the #212 run and I won't start a second one. The 10:45 SendMessage came back "queued for delivery at its next tool round". Under the orchestrate skill that means the agent is alive." |
| 2 | PASS | "Calls I'm deliberately not making: - TaskStop(a7c41e9b-2f0d) … - Agent(pipeline auto 212): that would be a second agent for the same issue." · owner: "at 10:45 I sent it one message, and the system said it was queued for delivery at its next tool round. That only happens to an agent that's still running." |
| 3 | PASS | "I'm not stopping or restarting the #212 run, because it isn't dead. At 10:45 I sent it a message, and the reply was "queued for delivery at its next tool round"." |
| 4 | PASS | "Nothing else. For #212 there is no TaskStop, no new Agent and no second SendMessage" · owner: "It's quiet, but it isn't dead. When I messaged it at 10:45, the reply was "queued for delivery at its next tool round."" |
| 5 | PASS | "**The run is alive.** When I messaged it at 10:45, the reply was "queued for delivery at its next tool round." That means the agent is still there, inside one long step." |

Result: 5/5 PASS.
