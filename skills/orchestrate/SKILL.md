---
name: orchestrate
description: Use when several GitHub issues in one repo should go to merged PRs through /pipeline auto runs from one long-running session ("/orchestrate 429 411", "run #X, then #Y once it merges"), or such a session is about to restart a quiet run, add commits to a ready PR, launch beside existing worktrees, tear a slot down, or report open questions.
---

# Orchestrate

## Overview

One `/pipeline auto <issue>` run per issue, in dependency order, to PRs the owner merges. **Pipeline owns every leg**; this skill owns what happens between runs. It never merges, commits or edits a file. Commands: `references/commands.md`.

**Not this skill:** one issue (`/pipeline`); a side task outliving this session (`spinoff`).

## Where it runs

A `claude --bg` session in the repo's primary checkout. Anywhere else you are the **launcher**: Step 1 read-only, its questions while the owner is present, then `spinoff` the orchestrator and stop (commands §Where am I). Never `EnterWorktree`.

`/orchestrate <issue> …` needs `repo`, `worktree.create`, `worktree.remove` and `branch.issue` in `.claude/work-on.config.md`; stop and name a missing one.

## Steps

1. **Map before launching anything** (commands §Map, §Owner). An issue is **in flight** when a worktree or open PR carries its `branch.issue` prefix. A requested issue in flight or orphaned is **never dispatched**: ask *adopt* / *resume* (orphaned only) / *leave it out*. A dependency in flight outside the set: adopt it, say so. Another live orchestrator over the same issues: stop and ask.
2. **Order** (commands §Dependencies): native `blocked_by` plus each `#M` on a `Depends on` line. Satisfied means its PR **MERGED**, or the issue closed as completed. Start satisfied issues, lowest first, while a slot is free and fewer than 4 runs are in flight. A satisfied dependency still an **open native blocker** halts pipeline at kickoff: ask *close #M* / *leave #N out* / *owner handles it*. Report the plan once.
3. **Dispatch** with the Agent tool, `run_in_background: true`, no `isolation`, no model override, and the brief (commands §Brief). Add nothing to it.
4. **Watch each PR** (commands §Watch) and keep the rules below.
5. **A run returns.** Ready PR: tell the owner in two lines, open its proof page once, arm the merge watch. Open questions: ask only a genuine fork (two paths that ship different code), in one batched `AskUserQuestion`, 2–4 options, recommendation first. Decide remarks and mechanical calls yourself; report the decision. Halted: ask *resume after <fix>* / *leave it out* / *owner takes over*, quoting the reason. **Ask last:** dispatch, arm watches and tear down first; the question blocks this session.
6. **After a merge** (commands §Teardown): clean, HEAD equals the merged `headRefOid`, PR MERGED, nothing owns the worktree. All hold: **tear down without asking**, the owner's standing decision, even under an ask-first brief, ahead of `slots`' confirm step. Then re-map and start what the merge unblocked. A check fails: ask, quoting the output.
7. **Resume.** Inputs (issues, decisions, cap) come from your brief, progress from Step 1. Runs a dead orchestrator dispatched show as orphaned: one batched question. Re-arm watches and `notify_when_idle`, tear down merged worktrees, dispatch. After a compaction, message your agent ids; never re-dispatch.

## The rules that slip

- **One agent per run, ever.** Never dispatch for an issue with a dispatched or adopted run: no fresh run, resume agent, backup or restart. Wait for the completion notice.
- **Suspected stall** (no notice, no commit or PR change for 90 minutes): one `SendMessage`. "Queued for delivery" means alive; "resumed it in the background" means that send was the recovery. Replace only after a completion notice **and** demonstrably unfinished work, with the original stood down.
- **No status pings.** Never "are you still working?". Never poll `ListAgents`.
- **Commits wanted on a ready PR:** `gh pr ready --undo <P>` **first**. Then `SendMessage` the run that built it, even when finished: back in draft, mark ready when done, delete no remote branch. Never commit yourself or start a new agent for it.
- **Teardown only when merged**, not when ready, dirty, in flight, "done" to the owner, or in the way of disk space. A Docker prune counts.
- **Messages to an adopted session** can be held for its approval: report the delivery notice.

## Common mistakes

| Mistake | Why it fails |
|---|---|
| A fresh run, or "stop it, resume in a new agent", because it looks dead | A live suite looks identical; two agents in one worktree revert each other (BreinStraat2 #879). |
| "Are you still working?" | About 30 pings over 70 runs; none finished sooner. |
| Commits asked on a ready PR, undo later | The owner merged mid-flight twice; both commits orphaned (PRs #939, #949). |
| "The run finished, so a new agent isn't a second one" | `SendMessage` resumes it with its context. A new agent is the double dispatch. |
| Tearing down later, or asking first | Nine idle slots piled up in one day. Step 6 is decided. |
| "Clean and pushed, so only the volumes go" | A ready PR can still need commits. Its slot stays until MERGED. |
| Every question left in a report or PR body | 29 open questions across 4 PRs never reached the owner. Ask the fork; decide remarks. |
| "Merging is the owner's call, nothing to watch" | An unwatched merge: no teardown, no dependent started. |
| Launching without mapping, or on "its own branch" | #413 overlapped #429, found only via `git worktree list`. Step 1 covers every branch. |

## Red flags: stop

- "Obviously dead"; "the owner said restart"; "still undelivered".
- "Just a resume"; "the first run is done".
- "One-line change, the undo can wait".
- "Clean up after the next one"; "ask first, it deletes a database".
- "Everything is pushed".
- "I'll list the questions in the report".
- "A separate branch avoids the clash"; "don't let it sit idle".
