---
name: orchestrate
description: Use when several GitHub issues should go to merged PRs through /pipeline auto or autoflow runs from one long-running session ("/orchestrate 429 411", "/orchestrate autoflow 429", "run #X, then #Y once it merges"), or such a session is about to restart a quiet run, add commits to a ready PR, launch beside existing worktrees, tear a slot down, or report open questions.
---

# Orchestrate

## Overview

One unattended `/pipeline` run per issue, in dependency order. **Pipeline owns every leg**; this skill owns what happens between runs, and never merges, commits or edits a tracked file. Commands: `references/commands.md`.

**Not this skill:** one issue (`/pipeline`); a side task (`spinoff`).

## Where it runs

A `claude --bg` session in the primary checkout; never `EnterWorktree`. Elsewhere you are the **launcher**: run Step 1 read-only and ask its questions, then `spinoff` the orchestrator and stop (commands §Where am I).

`/orchestrate [auto|autoflow] <issue> …` picks the engine for the batch, `auto` when omitted. It needs `repo`, `worktree.create`, `worktree.remove`, `branch.issue` in `.claude/work-on.config.md`; name a missing one and stop.

## Steps

1. **Map before launching anything** (commands §Map, §Owner). An issue is **in flight** when a worktree or open PR carries its `branch.issue` prefix. A requested issue in flight or orphaned is **never dispatched**: ask *adopt* / *leave it out*; *resume* only when §Owner finds no owner, never "stop it, then resume". A dependency in flight outside the set: adopt it. Another live orchestrator over the same issues: stop and ask.
2. **Order** (commands §Dependencies): native `blocked_by` plus each `#M` on a `Depends on` line. Satisfied means its PR **MERGED**, or the issue closed as completed. Start satisfied issues, lowest first, while a slot is free and fewer than 4 runs are working (a PR awaiting merge does not count). A satisfied dependency still an **open native blocker** halts pipeline at kickoff: ask *close #M* / *leave #N out* / *owner handles it*. Report the plan once.
3. **Dispatch.** `auto`: Agent tool, `run_in_background: true`, no `isolation` or model override, the brief (commands §Brief). `autoflow`: from the primary checkout, pipeline `SKILL.md` §`autoflow` — how a run starts and ends steps 1–3, the workflow `pipeline-autoflow` in the background (commands §Launch); the worktree travels in `launch`'s args and the step briefs, never in this session's directory.
4. **Watch each PR** (commands §Watch) and keep the rules below.
5. **A run returns.** `auto`: run `php ~/.claude/skills/pipeline/checks/engine_peak_cli.php <its agent id>` and put the line in whichever report follows (pipeline `engine.md` §The dispatcher — what it does, and never does). `autoflow`: `finish` it, then when `finish` prints `done` run `gh pr ready <P>` yourself; a denial leaves the manifest done and the PR draft, writes no halt, and goes in the report for the owner to run `gh pr ready` by hand. Put `run_cost_cli.php` and `run_audit.php`'s lines in whichever report follows (commands §Finish). Ready PR: tell the owner in two lines, open its proof page once, arm the merge watch. Open questions: ask only a genuine fork (two paths that ship different code), in one batched `AskUserQuestion`, 2–4 options, recommendation first. Decide remarks and mechanical calls; report them. Halted: ask *resume after <fix>* / *leave it out* / *owner takes over*, quoting the reason. **Ask last:** dispatch, arm watches and tear down first, then `AskUserQuestion`, owner away or not, never a plain message.
6. **After a merge** (commands §Teardown): clean, HEAD equals the merged `headRefOid`, PR MERGED, nothing owns the worktree. All hold: **tear down without asking**, even under an ask-first brief and ahead of `slots`' confirm step. Then re-map and start what the merge unblocked. A check fails: ask, quoting the output. A PR **closed without merge** is never torn down or satisfied: ask about it and everything waiting on it.
7. **Resume.** Inputs from your brief, progress from Step 1. A dead orchestrator's runs show as orphaned: one batched question. Re-arm watches and `notify_when_idle`, tear down merged, dispatch. After a compaction: `auto`, message your agent ids; `autoflow`, your dispatch record (task id → issue) still names each run and its completion notice still arrives.

## The rules that slip

- **One agent per run, ever** (`auto`); **one workflow per run at a time** (`autoflow`). Never dispatch for an issue with a dispatched or adopted run: no fresh run, resume agent, backup or restart. Wait for the completion notice.
- **Suspected stall** (no notice, no commit or PR change for 90 minutes). `auto`: one `SendMessage`. "Queued for delivery" means alive; "resumed it in the background" means that send was the recovery. Replace only after a completion notice **and** demonstrably unfinished work, with the original stood down. `autoflow`: `TaskStop` its workflow, which kills the running step's command and starts no further step; `finish` it as a halt (commands §Finish); ask *resume* / *leave it out*. *Resume* is a new `launch` and workflow.
- **No status pings** ("are you still working?": about 30 over 70 runs, none finished sooner). Never poll `ListAgents`.
- **Commits wanted on a ready PR:** `gh pr ready --undo <P>` **first**. Then, by the engine that built it: `auto`, `SendMessage` that run, even when finished: back in draft, mark ready when done, delete no remote branch; `autoflow`, the request into the manifest's `decisions`, `launch --from review-pr` and a new workflow (commands §Launch). Never commit yourself or start a new agent for it.
- **Teardown only when merged**, not when ready, dirty, in flight, closed, "done" to the owner, or for disk space. A Docker prune counts.
- **Adopted sessions** may hold a message for approval: report the delivery notice.

## Common mistakes

| Mistake | Why it fails |
|---|---|
| A fresh run, or "stop it, resume in a new agent", for a quiet run; a second workflow beside one | A live suite looks identical; two runs in one worktree revert each other. |
| Commits asked on a ready PR, undo later | The owner merged mid-flight twice; both commits orphaned. |
| "The run finished, so a new agent isn't a second one" (`auto`) | `SendMessage` resumes it; a new agent is the double dispatch. |
| Relaunching a stopped `autoflow` run without `finish` | The cursor still says the stopped step is pending; `finish` records why it stopped, so the next `launch` resumes from a reason. |
| Tearing down later, or asking first | Nine idle slots piled up in a day. Step 6 is decided. |
| "Clean and pushed, so only the volumes go" | A ready PR can still need commits. Its slot stays until MERGED. |
| Every question left in a report or PR body | 29 open questions across 4 PRs never reached the owner. |
| "Merging is the owner's call, nothing to watch" | An unwatched merge: no teardown, no dependent started. |
| "Four PRs in flight, the cap is full" | Only working runs count; ready PRs wait on the owner. |
| Launching without mapping, or on "its own branch" | #413 overlapped #429, found only via `git worktree list`. Step 1 covers every branch. |

## Red flags: stop

- "Obviously dead"; "the owner said restart"; "still undelivered"; "just start another workflow".
- "Just a resume"; "stop it, then resume" (`autoflow`: a stall only, through `TaskStop` and `finish`); "the first run is done".
- "One-line change, the undo can wait".
- "Clean up after the next one"; "ask first, it deletes a database".
- "Everything is pushed"; "the queue is full".
- "I'll list the questions in the report"; "a blocking question stalls the runs".
- "A separate branch avoids the clash"; "don't let it sit idle".
