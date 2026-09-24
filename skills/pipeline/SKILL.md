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

Core principle: **a loop that only loops; every step is a fresh agent.** In `auto` the loop is a
dispatcher agent that asks `dispatch_cli.php` for the next step, dispatches it with the brief
`pipeline_brief()` generated and lets the same command validate what came back, never reading
artifacts, reviews, diffs or test output itself. In `autoflow` the loop is a program, the saved
workflow `pipeline-autoflow` (`workflow/pipeline-autoflow.js`), whose steps each run
`dispatch_cli.php brief`, do their leg, write the manifest and return a status. In both, review fixes
and finishing the PR belong to fresh resolve agents. `interactive` walks the same legs through
`next` / `returned`, with the human resolving each review. No long-lived brain; a lost run reconstructs from
git + gh. See the references before driving a run — the enforcement lives there, not in this summary:

- **`references/engine.md`** — the loop, the work item, kickoff/worktree, dev-stack readiness, the
  per-station briefs, failure policy, navigation. **Read this first.**
- **`references/gates.md`** — modes, content triggers, and the forward-navigation guardrail.
- **`references/manifest.md`** — the disposable-cursor state file and its reconstruction.
- **The work item** — a run that carries an issue claims it (board → **In Progress**), refuses to
  start on work with an open blocker, and settles at `review-pr` whether merging closes it
  (`references/engine.md` §The work item, §Closing links). The board half is opt-in per repo via
  the `## Board` block those repos already have; a board-less repo skips it **silently** and runs
  exactly as before, the same way an unadopted `## Checks` block is never mentioned.
- **Mechanical checks** — `implement` also runs a repo's PHPStan/Pint checks after each step when
  the repo declares them in a committed `## Checks` block (`references/engine.md`
  §Mechanical checks). Opt-in: repos that have not declared them are unaffected.
- **Visual proof** — when `pipeline_triggers(...)['ui']` fires, `verify-ui` writes a durable page to
  `~/GitProjects/_proofs/<repo>/pr-<n>-<topic>/index.html` and the PR gets a text-only record comment
  (`references/engine.md` §The proof store). The finished page **opens in the browser once**, as the
  run's last action; `PIPELINE_NO_OPEN=1` suppresses that for headless and unattended runs.
  Backend-only runs have no page and are unaffected.
- **The 150k invariant** — `/pipeline auto` runs the dispatcher as one background agent; after its
  completion notice, the invoking session runs `php checks/engine_peak_cli.php <its agent id>` and
  reports the line. Over 150k peak context is an annotation, never a halt
  (`references/engine.md` §The dispatcher).
- **Cost per run** — after every `autoflow` run the invoking session reports two outputs with the
  result: `checks/run_cost_cli.php` (weighted cost per step, the largest step peak) and
  `checks/run_audit.php` (whether `ui` and each gate's ledger agree with what the steps reported). A
  `MISMATCH` is a signal, never a halt (`references/engine.md` §`autoflow`).

The deterministic guardrails are tested PHP in `checks/` (run
`./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`).

## Invocation and navigation

```
/pipeline [interactive|auto|autoflow] [light] <idea | number | spec-path>   # start a run (mode defaults to interactive)
/pipeline                                                                   # resume the current branch's run
```

- **One entry point.** `/pipeline` starts a run, or — when a manifest (or reconstructable
  PR/branch state) for the current branch exists — **resumes** it after the invariant checks.
- **A number is an issue or a PR, and it classifies itself** — the `issues` endpoint returns both,
  and a PR has a non-null `pull_request` (`references/engine.md` §The work item). There is no
  separate issue and PR syntax to remember.
- **Mode defaults to `interactive`.** `auto` and `autoflow` are explicit opt-ins for unattended
  runs; a fresh `/pipeline <idea>` never runs unattended by surprise.
- **`light` permits a small design.** A Bounded design is a ~15-line spec and a ~10-line plan
  instead of a full design; every leg and both reviews still run. Without `light`, `interactive`
  asks when brainstorming finds the change small, and `auto` and `autoflow` always write the full
  design. A Bounded run that turns out bigger grows its design and is re-reviewed
  (`references/engine.md` §Design size).
- **Navigation is natural language, not more commands.** Once loaded the engine holds the cursor,
  so drive it by saying so — *"next step"*, *"go to step X"*, *"re-run review-plan"*, *"skip
  ahead to handoff"*. A slash command is only a cold-session trigger; there is no separate
  `/next`.
- **One guardrail on jumps.** Backward navigation is free; **forward past a gate that has not run
  is refused** — the same mechanism behind the un-skippable-review promise (`gates.md`).

## `autoflow` — how a run starts and ends

The two unattended modes run side by side until the keep/revert decision: after 6 `autoflow` runs,
measured against `docs/superpowers/specs/2026-09-23-pipeline-auto-workflow-design.md` §Measurement,
the decision is "keep `autoflow` and delete `auto`" or "delete `autoflow`"
(`docs/superpowers/plans/2026-09-23-pipeline-auto-workflow.md` Amendment A). PR #50 carries the
numbers.

The invoking session (this one, or `orchestrate`) holds only the two edges of an `autoflow` run
(`references/engine.md` §`autoflow`):

1. **Kickoff.** With `CHECKS="$HOME/.claude/skills/pipeline/checks"`:
   `php "$CHECKS/dispatch_cli.php" kickoff <primary checkout> <number | idea> [--light] [--decision "<verbatim>"]…`
   does §The work item and §Kickoff in one call (`references/engine.md` §Kickoff). `ready`: its
   `manifest` is the run's, and its `notes` go into the report. A halt: report it and stop; never
   create the worktree another way. A denied kickoff call is reported like a halt: nothing is
   retried in another form. A resume skips this step.
2. **Launch.** `git -C <worktree> diff origin/<base>...HEAD > "<manifest stem>.diff"`, then
   `PIPELINE_NO_OPEN=<1 unattended, else 0> php "$CHECKS/dispatch_cli.php" launch <manifest> "<manifest stem>.diff"`.
   `done` or a halt: report it and stop. A resume starts here: `launch` starts from the cursor.
3. **Start the saved workflow `pipeline-autoflow`** by name, with `launch`'s JSON as `args`, and wait
   for its completion notice. Starting it from this skill is the owner's opt-in; unattended runs need
   auto permission mode or allow rules for `git push`, `gh` and `docker`.
4. **Finish.** `php "$CHECKS/dispatch_cli.php" finish <manifest> '<its return as JSON>'`, or
   `'{"action":"halt","reason":"<the error>"}'` when the workflow errored. `finish` refuses a `done`
   whose cursor is not on `review-pr`: it records a halt and prints it instead.
5. **`finish` printed `done`:** `gh pr ready <pr>`. The manifest already says done; when
   `gh pr ready` is denied the PR stays draft and no halt is written: put the denial in the report,
   and the owner runs `gh pr ready` by hand. **A halt after `handoff`:** the reason into the PR body
   and the proof page opened once (`references/engine.md` §Failure policy).
6. **Report** the result with the two cost-per-run outputs above, and arm the merge watch
   (`references/engine.md` §After the merge).

`~/.claude/workflows/pipeline-autoflow.js` is a symlink to `workflow/pipeline-autoflow.js`, linked by
`hooks/git-freshness.sh` as it links the skills.

## Non-goals

- **Replacing any station's judgment.** The pipeline sequences skills; it does not out-think them.
- **New review logic** (that is `/critique`) or **new bug-hunting** (that is `/code-review`).
- **Tearing down a worktree before its PR is merged,** or one the run did not create. After the
  merge the run removes its own slot without asking (`references/engine.md` §After the merge).
- **Posting to GitHub beyond what `handoff`/`work-on` already do**, and nothing it writes ever
  addresses a person.
- **A findings store, or any persistent state not reconstructable** from git + gh.
