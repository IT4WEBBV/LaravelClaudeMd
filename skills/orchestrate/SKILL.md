---
name: orchestrate
description: Use when several GitHub issues should go to merged PRs through /pipeline auto runs from one long-running session ("/orchestrate 429 411"), or such a session is about to restart a quiet run, add commits to a ready PR, launch beside existing worktrees, tear a slot down, or report open questions.
---

# Orchestrate

## Overview

One `/pipeline auto <issue>` run per issue, in dependency order. **Pipeline owns every leg**; this skill owns what happens between runs, and never merges, commits or edits a file. Commands: `references/commands.md`.

**Not this skill:** one issue (`/pipeline`); a side task (`spinoff`).

## Where it runs

A `claude --bg` session in the primary checkout; never `EnterWorktree`. Elsewhere you are the **launcher**: Step 1 read-only with the owner present, then `spinoff` the orchestrator and stop (commands §Where am I).

`/orchestrate <issue> …` needs `repo`, `worktree.create`, `worktree.remove`, `branch.issue` in `.claude/work-on.config.md`; name a missing one and stop.

## Steps

1. **Map before launching anything** (commands §Map, §Owner). An issue is **in flight** when a worktree or open PR carries its `branch.issue` prefix. A requested issue in flight or orphaned is **never dispatched**: ask *adopt* / *leave it out*; *resume* only when §Owner finds no owner, never "stop it, then resume". A dependency in flight outside the set: adopt it, say so. Another live orchestrator over the same issues: stop and ask.
2. **Order** (commands §Dependencies): native `blocked_by` plus each `#M` on a `Depends on` line. Satisfied means its PR **MERGED**, or the issue closed as completed. Start satisfied issues, lowest first, while a slot is free and fewer than 4 runs are working (a PR awaiting merge does not count). A satisfied dependency still an **open native blocker** halts pipeline at kickoff: ask *close #M* / *leave #N out* / *owner handles it*. Report the plan once.
3. **Dispatch**: Agent tool, `run_in_background: true`, no `isolation` or model override, the brief (commands §Brief) with nothing added.
4. **Watch each PR** (commands §Watch) and keep the rules below.
5. **A run returns.** Ready PR: tell the owner in two lines, open its proof page once, arm the merge watch. Open questions: ask only a genuine fork (two paths that ship different code), in one batched `AskUserQuestion`, 2–4 options, recommendation first. Decide remarks and mechanical calls; report them. Halted: ask *resume after <fix>* / *leave it out* / *owner takes over*, quoting the reason. **Ask last:** dispatch, arm watches and tear down first, then `AskUserQuestion`, owner away or not, never a plain message.
6. **After a merge** (commands §Teardown): clean, HEAD equals the merged `headRefOid`, PR MERGED, nothing owns the worktree. All hold: **tear down without asking**, even under an ask-first brief and ahead of `slots`' confirm step. Then re-map and start what the merge unblocked. A check fails: ask, quoting the output. A PR **closed without merge** is never torn down or satisfied: ask about it and everything waiting on it.
7. **Resume.** Inputs (issues, decisions, cap) from your brief, progress from Step 1. A dead orchestrator's runs show as orphaned: one batched question. Re-arm watches and `notify_when_idle`, tear down merged, dispatch. After a compaction, message your agent ids.

## The rules that slip

- **One agent per run, ever.** Never dispatch for an issue with a dispatched or adopted run: no fresh run, resume agent, backup or restart. Wait for the completion notice.
- **Suspected stall** (no notice, no commit or PR change for 90 minutes): one `SendMessage`. "Queued for delivery" means alive; "resumed it in the background" means that send was the recovery. Replace only after a completion notice **and** demonstrably unfinished work, with the original stood down.
- **No status pings** ("are you still working?": about 30 over 70 runs, none finished sooner). Never poll `ListAgents`.
- **Commits wanted on a ready PR:** `gh pr ready --undo <P>` **first**. Then `SendMessage` the run that built it, even when finished: back in draft, mark ready when done, delete no remote branch. Never commit yourself or start a new agent for it.
- **Teardown only when merged**, not when ready, dirty, in flight, closed, "done" to the owner, or for disk space. A Docker prune counts.
- **Adopted sessions** may hold a message for approval: report the delivery notice.

## Common mistakes

| Mistake | Why it fails |
|---|---|
| A fresh run, or "stop it, resume in a new agent", for a quiet run | A live suite looks identical; two agents in one worktree revert each other (BreinStraat2 #879). |
| Commits asked on a ready PR, undo later | The owner merged mid-flight twice; both commits orphaned (PRs #939, #949). |
| "The run finished, so a new agent isn't a second one" | `SendMessage` resumes it; a new agent is the double dispatch. |
| Tearing down later, or asking first | Nine idle slots piled up in a day. Step 6 is decided. |
| "Clean and pushed, so only the volumes go" | A ready PR can still need commits. |
| Every question left in a report or PR body | 29 open questions across 4 PRs never reached the owner. |
| "Merging is the owner's call, nothing to watch" | An unwatched merge: no teardown, no dependent started. |
| "Four PRs in flight, the cap is full" | Only working runs count; ready PRs wait on the owner. |
| Launching without mapping, or on "its own branch" | #413 overlapped #429, found only via `git worktree list`. Step 1 covers every branch. |

## Red flags: stop

- "Obviously dead"; "the owner said restart"; "still undelivered".
- "Just a resume"; "stop it, then resume"; "the first run is done".
- "One-line change, the undo can wait".
- "Clean up after the next one"; "ask first, it deletes a database".
- "Everything is pushed"; "the queue is full".
- "I'll list the questions in the report"; "a blocking question stalls the runs".
- "A separate branch avoids the clash"; "don't let it sit idle".
