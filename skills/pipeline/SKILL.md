---
name: pipeline
description: Use when walking a feature end-to-end through the full development chain — design, plan review, handoff, implement, UI verification, PR review — interactive or unattended, and when resuming or navigating an in-progress run. Triggers on "/pipeline", "run the pipeline", "take this through the pipeline", "next step" / "go to step X" while a run is active.
---

# pipeline

## Overview

A **trampoline** that walks a feature through its chain of station skills, carrying state from
one to the next so the review gates become un-skippable **by construction** rather than by
memory. It is the *spine*, not better station logic — each station already owns its own quality
(`brainstorming`, `writing-plans`, `/critique`, `handoff`, `work-on`, `browser-verification`).

Core principle: **read the manifest → pick the next leg → run it → write the manifest → stop or
continue.** No long-lived brain; a lost run reconstructs from git + gh. See the references before
driving a run — the enforcement lives there, not in this summary:

- **`references/engine.md`** — the loop, kickoff/worktree, dev-stack readiness, the per-station
  briefs, failure policy, navigation. **Read this first.**
- **`references/gates.md`** — modes, content triggers, and the forward-navigation guardrail.
- **`references/manifest.md`** — the disposable-cursor state file and its reconstruction.

The deterministic guardrails are tested PHP in `checks/` (run
`./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`).

## Invocation and navigation

```
/pipeline [interactive|auto] <idea | spec-path | pr#>   # start a run (mode defaults to interactive)
/pipeline                                               # resume the current branch's run
```

- **One entry point.** `/pipeline` starts a run, or — when a manifest (or reconstructable
  PR/branch state) for the current branch exists — **resumes** it after the invariant checks.
- **Mode defaults to `interactive`.** `auto` is an explicit opt-in for unattended runs; a fresh
  `/pipeline <idea>` never runs unattended by surprise.
- **Navigation is natural language, not more commands.** Once loaded the engine holds the cursor,
  so drive it by saying so — *"next step"*, *"go to step X"*, *"re-run review-plan"*, *"skip
  ahead to handoff"*. A slash command is only a cold-session trigger; there is no separate
  `/next`.
- **One guardrail on jumps.** Backward navigation is free; **forward past a gate that has not run
  is refused** — the same mechanism behind the un-skippable-review promise (`gates.md`).

## Non-goals

- **Replacing any station's judgment.** The pipeline sequences skills; it does not out-think them.
- **New review logic** (that is `/critique`) or **new bug-hunting** (that is `/code-review`).
- **Tearing down worktrees.** It creates one worktree for the run and **never removes it** —
  teardown is destructive and stays the human's call.
- **Posting to GitHub beyond what `handoff`/`work-on` already do**, and nothing it writes ever
  addresses a person.
- **A findings store, or any persistent state not reconstructable** from git + gh.
