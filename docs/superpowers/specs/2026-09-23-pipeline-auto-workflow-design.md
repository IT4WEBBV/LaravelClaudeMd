# `/pipeline auto` as a Workflow script — design

Builds on `2026-09-22-pipeline-dispatcher-loop-design.md` (PR #50). That design made the `auto`
engine a pure dispatcher: an LLM agent that only runs `dispatch_cli.php next`, dispatches the brief it
prints, runs `dispatch_cli.php returned`, and repeats. This design removes that agent. In `auto` the
pipeline becomes a **program that calls agents**: the order of steps, the loop-backs, their bounds and
the halts are JavaScript; agents exist only inside steps. Interactive mode is unchanged.

## Why

- **The engine was an LLM following prose.** Before PR #50, 11 of 18 engines edited files and 13 ran
  the suite although the rules said they mostly should not (token audit 2026-09-22, re-run
  2026-09-23). PR #50 shrinks what the LLM is asked to do and polices it with tested PHP; each review
  of that design asked for more policing. A script has nothing to police.
- **Tokens.** A script has no context to replay. The dispatcher's remaining cost (PR #50's estimate:
  about 5–6 percent of all usage) goes to zero, and the 150k peak invariant and its measurement
  script have nothing left to measure.
- **The field went the same way.** GSD 2 and bmad-loop both rewrote an LLM or prompt orchestrator
  into code, bmad-loop explicitly to save tokens ("no LLM in the control loop"). OpenAI Symphony,
  Agentless and Anthropic's Workflows docs keep control flow in code. No one has measured an LLM
  orchestrator against a script; the case rests on the mechanism.

## Verified before writing (experiments, 2026-09-23)

- Workflow agents **inherit the launching session's working directory**. A single run could rely on
  that; `/orchestrate`'s parallel runs cannot, so the design does not (§Where a step works).
- Workflow agents **cannot start agents**: no Agent tool (only `SendMessage`, `RemoteTrigger`,
  `TaskList`, `TaskUpdate`, `TaskStop` beside the normal file, Bash and Skill tools). A skill that
  dispatches a subagent inside one fails, and in the experiment the agent **reported success anyway**
  (`/critique` asked the main session to dispatch its reviewer and claimed the reviewer had started).
- A workflow agent briefed like a leg, in a background job in auto permission mode, ran `git push`,
  `gh pr create --draft`, `gh pr comment` and `gh pr close --delete-branch` with **no permission
  prompt or denial** (throwaway PR #55). Not tested: `gh pr ready`, `docker exec`, `restart.sh`.
  The brief said the owner authorised the run; the docs say the prompt a script passes does not count
  as the user's request to the classifier, so the line's effect on the classifier is unknown.
- Documented (workflows docs, workflow-authoring reference): per-call `model`, `effort` and `schema`;
  `agent()` returns null on an unrecoverable error; a schema that fails five times errors the run;
  `~/.claude/workflows/` is available in every project; a skill whose instructions start a workflow
  counts as the user's opt-in; a script outside the working directory needs `/add-dir` or a Read
  allow rule; no duration limit; resume only in the same session or after `claude --resume`.

## The design

### Who does what in `auto`

```
invoking session   kickoff (unchanged) → dispatch_cli.php launch → Workflow(pipeline-auto, args)
                   … on return: dispatch_cli.php finish, after-handoff halt duties, report
workflow script    for each step: agent(prompt, opts) → {status, reason, ui} → next step, or return
step agent         dispatch_cli.php brief <manifest> <leg> <step> → do the leg → write the manifest
                   → return {status, reason, ui}
```

The invoking session is the main session or `/orchestrate`, and it starts the workflow itself (the
docs: subagents never get the Workflow tool). It is an agent only at the edges, before the script
starts and after it returns, and even there it runs one tested command at each edge. Nothing reads a
step's work in between. A halt lands in the session that launched the run, with its reason.

### Where a step works

The worktree travels in the brief (*"Work only in `<worktree>`"*, `brief.php`) and in absolute paths,
not in the launch directory. `/orchestrate` launches up to four runs from the primary checkout, and one
session directory cannot be four worktrees. Commands that key on `getcwd()` (§Suite reuse's tree key,
`pipeline_exclude_manifest`) are run with `-C <worktree>` or from `cd <worktree>`, as the brief says.

### The script

`skills/pipeline/workflow/pipeline-auto.js`, about 70 lines:

```js
const STEPS = { 'review-plan': ['review', 'resolve'], 'review-pr': ['review', 'resolve'] }
const LOOP_TARGET = { 'review-plan': 'design', 'verify-ui': 'implement', 'review-pr': 'implement' }
const ALLOWED = {                                  // LegStatus::allowedFor(), as schema enums
  'design:run': ['continued', 'halted'],
  review: ['continued', 'halted', 'plan-insufficient'],
  resolve: ['continued', 'looped-back', 'halted'],
  'verify-ui:run': ['continued', 'looped-back', 'halted', 'plan-insufficient'],
  run: ['continued', 'halted', 'plan-insufficient'],
}
const BOUND = 2
const loops = { ...args.loops }                   // from the ledger, by `launch`
let ui = args.ui

let leg = args.startLeg, from = args.startStep
while (leg) {
  const all = STEPS[leg] ?? ['run']
  const steps = from ? all.slice(all.indexOf(from)) : all
  from = undefined
  let result
  for (const step of steps) {
    result = await runStep(leg, step)
    if (result.status !== 'continued') break
  }
  if (result.status === 'halted') return halt(leg, result.reason)
  if (result.ui !== undefined) ui = result.ui
  if (result.status === 'continued') { leg = nextLeg(leg, ui); continue }

  const gap = result.status === 'plan-insufficient'
  const target = gap ? 'design' : LOOP_TARGET[leg]
  if (!target) return halt(leg, `no loop-back from ${leg}`)
  const bounded = !(gap && args.size === 'Bounded')          // an escalation is not a loop-back
  const gate = gap ? 'review-plan' : leg
  if (bounded && ++loops[gate] > BOUND) return halt(leg, `${gate}: loop-back bound exhausted`)
  leg = target
}
return { action: 'done' }
```

- **`runStep`** gives each step a schema whose `status` is an `enum` of what that step may return
  (`ALLOWED`, mirroring `LegStatus::allowedFor()` after the gate-skip fix below); `reason` and `ui`
  are optional. A review step runs with `model: 'fable'`, retried once with `model: 'opus'` when
  `agent()` returns null (a usage limit in a background session; in an interactive session the run
  pauses and continues by itself; also any other unrecoverable error). `handoff` runs with
  `effort: 'low'`. `agent()` is wrapped in `try`/`catch`: a thrown error (a schema that failed five
  times) and a null from any other step become `halt(leg, <error or "the agent returned nothing">)`.
- **`args`** come from `launch` (below): `startLeg`, `startStep`, `loops` per gate, `ui`, `size`
  (the spec's design size, read from its header), `manifest`, `worktree`, `noOpen`.
- **Unknown transitions halt.** A status outside the step's enum fails the schema; a loop-back from a
  leg with no target halts. Nothing ends the loop as `done` except `review-pr`'s resolve step
  continuing.
- **The Bounded exemption is decided by `args.size`**, which `launch` reads from the committed spec,
  not by the step. On an Architectural spec every `plan-insufficient` counts toward `review-plan`'s
  bound.
- **The leg order and loop targets are repeated** from `pipeline.php` (`pipeline_legs()`,
  `pipeline_next_leg()`, `pipeline_loop_target()`) and `dispatch.php` (`allowedFor`): about fifteen
  lines, used by interactive mode. A second copy is accepted; a third would be abstracted. The smoke
  run (step 6) is their test.

### The step prompt

Fixed lines around the step:

1. Run `php ~/.claude/skills/pipeline/checks/dispatch_cli.php brief <manifest> <leg> <step>` and
   follow the brief it prints; it is complete. If the command prints a halt, return `halted` with its
   reason.
2. You cannot start agents. Where a skill or the brief would dispatch one, do that work yourself;
   where that is impossible, return `halted` with the reason.
3. Finish as the brief's `## Return` says.
4. `implement` only: after the last commit, write the diff to `<manifest stem>.diff` and return as
   `ui` the `ui` value `pipeline_triggers` prints for it, copied, not judged.
5. The finish step only: `PIPELINE_NO_OPEN=<args.noOpen>` for the proof page's `open` (the script
   cannot set the environment).

### `brief` — the step's first command

`dispatch_cli.php brief <manifest> <leg> <step>`:

- **writes `cursor: {leg, status: pending}`**, as `next` does today, so the manifest stays a cursor
  during an `auto` run. The leg comes from the script's prompt: the step announces the step it was
  given, it does not choose one. Every exit that is not the script's own return (a `TaskStop`, a dead
  session) then leaves the cursor at the step that was running;
- **halts when the ledger does not support the step**: `resolve` with no open entry for the gate,
  `review` with one already open. The resolve brief can then never point at the wrong review;
- prints the brief: `pipeline_brief()` with the step as a parameter instead of derived.

### No check on what a step reports

The script trusts each step's `status`. That is the settled choice for v1, and its cost is this:

- **Mechanical:** the review/resolve sequence, the loop targets, the bounds and the Bounded exemption.
  No step picks the next one, a status a step may not return fails its schema, and a loop-back with
  no target halts.
- **Reported, not checked:**
  - a step's `status`: a step can claim work it did not do (the experiment's false-success pattern).
    The next gate is the catch: `review-plan` reads the spec and plan, `review-pr` reads the code;
  - `implement`'s `ui`: a wrong `false` skips `verify-ui`, a gate `gates.md` calls mandatory. The
    prompt asks for `pipeline_triggers`' printed value, a copy rather than a judgement, which narrows
    but does not close this.
- **Measured after every run** (§Measurement): `pipeline_triggers` over the final PR diff against
  whether a `verify-ui` entry exists, and each step's reported status against the ledger it left. A
  mismatch is the trigger for a check, the natural one a separate Haiku agent that only runs
  `returned` and passes its output on, since the agent that did the work should not run its own check.

### What the briefs change — in `auto` only

The lines below are added only when `mode === 'auto'`. Interactive steps are Agent-tool subagents that
can dispatch; their briefs stay as they are. `BriefTest` pins both modes.

- **Review steps:** *"Apply `/critique`'s `plan` (`pr`) procedure yourself — Stage 0, Stage 1 and the
  rubric in `references/rubrics.md`. You are the reviewer; do not dispatch one."* The review step is a
  fresh agent holding only the brief, so `critique/SKILL.md`'s reviewer contract (crafted context,
  never session history) holds. `--verify` and `alternatives` are not available: both dispatch.
- **`review-plan:resolve`:** the independent read (a dispatched agent) is dropped.
- **`implement`:** *"Execute the plan inline, task by task; no subagents."*
- **Every step:** *"The owner authorised this run, including pushing the branch, opening the draft PR
  and marking it ready after `review-pr`; the pipeline never merges."* For the agent's benefit; the
  classifier does not count it as the user's request.
- **`## Return`:** write results and `cursor.status` (and `cursor.reason` when halting) into the
  manifest as today, then return `{status, reason}` as the structured result, instead of replying with
  one line.

### The gate-skip fix, for interactive

In `auto` the bug the PR #50 reviews found cannot happen: the script knows its step, and `brief` halts
on a step the ledger does not support. Interactive still derives the step (`pipeline_step()`), so it
gets two arms in `LegStatus::allowedFor()` and `pipeline_ledger_problem()`:

- **resolve steps may no longer return `plan-insufficient`.** A resolve step that finds a plan gap
  returns `looped-back`; if `implement` then finds the plan short, it reports the gap itself, as it
  already does. The open entry is always completed and no bound is charged twice;
- **a review step that returns `plan-insufficient` may add no open entry** for its gate, so a Bounded
  escalation found after the review was written cannot leave a stale review behind.

`DispatchTest` and `ReturnedTest` pin both; `manifest.md`'s `plan-insufficient` row and `engine.md`'s
plan-gap section are corrected to match.

### `launch` and `finish` — the two edges

- **`dispatch_cli.php launch <manifest> <diff> [--from <leg>]`** prints `{startLeg, startStep, loops,
  ui, size, …}` or `{action: 'done'}` or a halt. It does what the dispatcher did at the start of a
  run: `manifest_validate`, the finished rules of `next`, the invariant check (`manifest.md`: the
  recorded spec and plan exist at `last_sha`, the PR in the expected state), `startStep` from
  `pipeline_step()`, loop counts per gate from the ledger's `looped-back` entries (an `unknown` cycle
  gives that gate `BOUND`, which permits no loop-back), `ui` from `pipeline_triggers` over the diff,
  `size` from the spec's header. `--from review-pr` re-arms a run whose PR needs new commits: it moves
  the cursor there and is `/orchestrate`'s way to restart a run without editing a file. The invariant
  check runs **once per launch**, not per leg; `manifest.md` §Invariant check says so.
- **`dispatch_cli.php finish <manifest> <decision-json>`** records the script's return: `done` sets
  `cursor.status: done`; a halt sets `cursor: {leg, status: halted, reason}`, with `leg` defaulting
  to `cursor.leg` (which `brief` keeps current). When the workflow itself errored, the invoking
  session passes `{action: 'halt', reason: <the error>}` and the cursor names the step that was
  running. After `handoff`, the invoking session then does the failure policy's duties: the reason
  into the PR body, the proof page opened once.
- **Resume** is `/pipeline` as today: `launch` starts from the cursor. `resumeFromRunId` is a
  same-session convenience, never the mechanism.

### Launch

`SKILL.md`'s `auto` path: kickoff as today, `launch`, then start the workflow with its output as
`args`. Two routes, both checked in step 1 from another repo:

- **Primary: a saved workflow by name**, `~/.claude/workflows/pipeline-auto.js` as a symlink to
  `skills/pipeline/workflow/pipeline-auto.js`, linked by `hooks/git-freshness.sh` as it links skills.
  Permission rules can name a saved workflow (`Workflow(pipeline-auto)`), which a `claude --bg`
  orchestrator needs. Unverified: whether a symlinked personal workflow loads.
- **Fallback: `scriptPath`** into the skill directory, which needs a Read allow rule for
  `~/.claude/skills/**` outside this repo and asks consent on the first launch in auto mode.

Starting a workflow from a skill is the documented opt-in. Unattended runs need auto permission mode
or allow rules for push, `gh` and `docker`.

### `/orchestrate`

- **Dispatch:** `launch`, then the workflow, from the primary checkout; the worktree travels in the
  brief (§Where a step works).
- **Stalls:** the rule stays activity-based: no notice and no commit or PR change for 90 minutes. The
  orchestrator then stops the run (`TaskStop`, step 1 checks that it stops a workflow and what its
  running agent does), records it with `finish` as a halt, and asks *resume* / *leave it out*.
- **Commits wanted on a ready PR:** there is no run to message any more; `launch --from review-pr`
  and a new workflow. "One agent per run, ever" becomes "one workflow per run at a time".
- **Step 5** drops `engine_peak_cli.php` for `run_cost_cli.php`.

### What goes, what stays

| Goes | Stays |
|---|---|
| The LLM dispatcher (`engine.md` §The dispatcher), its 150k invariant, `engine_peak.php`, `engine_peak_cli.php`, `EnginePeakTest` | `dispatch.php`, `dispatch_cli.php` `next`/`returned` and `brief.php`, for interactive, which is unchanged |
| | Kickoff, the work item, the manifest and what legs write into it, reconstruction, navigation, failure policy, the review/resolve split |

New: `dispatch_cli.php launch`, `brief` and `finish`; the step parameter on `pipeline_brief()`; the
`auto`-only brief lines; the two gate-skip arms; `workflow/pipeline-auto.js`; `run_cost.php` +
`run_cost_cli.php` and `run_audit.php` (below).

### Measurement — cost per run, and the two reported inputs

The old metric (engines' share of usage) would fall even if total usage rose, because the work moves
into step agents. What answers the question:

- **Cost per run.** Baseline: old engine runs since 2026-09-14, the engine plus every agent it
  dispatched, from the audit's `rows.json` (weights: cache read 0.1, 5m write 1.25, 1h write 2, output
  5); median, quartiles and code lines per run (`pipeline_code_lines` over the run's PR diff) into the
  PR body before the first new run. New runs: `run_cost_cli.php` sums the same weights over one
  workflow run's agent transcripts, deduplicated by `message.id`, and prints the per-step breakdown
  and the largest step peak. The comparison is indicative, not controlled: cost is reported with code
  lines alongside.
- **The run audit.** `run_audit.php <manifest> <diff>` prints two facts after each run:
  `pipeline_triggers` over the final PR diff against whether the ledger has a `verify-ui` entry, and
  whether each gate's ledger entries agree with the path the run took. Both are reported with the
  run.
- **Keep it** when, after 6 runs, the median cost per run is at least 30 percent below the baseline
  median, the run audit found nothing, and no unattended run stalled on a permission.
- **Add the Haiku check** on the first run-audit mismatch. **Revert** on an unattended permission
  stall that allow rules cannot clear, `implement` peaking above 250k on a plan under 300 code lines
  (it can no longer delegate), or median cost not at least 30 percent below the baseline after 6 runs.

## Alternatives considered

- **Step agents run `returned` themselves, guarded by a `begin` command** (this spec's first draft):
  hands the agent that did the work the power to move the run, which then needed per-step tokens,
  snapshot checks and mode-conditional returns to police. Rejected by the owner as the machinery
  spiral again. `brief` writing the cursor is not that: it records the step the script chose.
- **A separate Haiku agent per step that runs the PHP check:** a clean fail-closed check at one small
  agent per step. Deferred until the run audit finds a mismatch.
- **Merge PR #50 first, then this:** ships an LLM dispatcher this design deletes. Chosen against by
  the owner.
- **Interactive as a workflow per stage** (the docs' pattern for sign-off between stages): possible
  later; v1 leaves interactive untouched.

## Out of scope

- Leg-side write commands for the manifest (#52), the `pipeline_ledger()` accessor (#53). #51
  (per-leg effort) is covered for `handoff`; anything further waits for the cost data.
- Ideas from the research, for later: token budgets per run, a "defer and ledger" exit at a
  non-converging gate, model-tier escalation on repeated fix loops, acceptance criteria agreed before
  implementing.

## Steps

1. **Verify first**, from a checkout of another repo, with throwaway workflows:
   - `docker exec` and `restart.sh` from a workflow agent in a Laravel project, unattended. A stall
     here kills the design; nothing below starts before it passes;
   - `gh pr ready` / `gh pr ready --undo` on a throwaway draft PR;
   - a saved workflow symlinked into `~/.claude/workflows/`, started by name; and `scriptPath` into
     the skill directory;
   - `TaskStop` on a running workflow, and what its running agent does after;
   - whether `agent()` throws on a schema that fails five times;
   - where a workflow run's agent transcripts land.
2. `pipeline_brief()` step parameter and the `auto`-only lines and `## Return`, test-first
   (`BriefTest`, both modes).
3. The two gate-skip arms, test-first (`DispatchTest`, `ReturnedTest`).
4. `dispatch_cli.php launch`, `brief` and `finish`, test-first (`DispatchCliTest`).
5. `run_cost.php` + `run_cost_cli.php` from `engine_peak.php`, and `run_audit.php`, test-first; delete
   `engine_peak*`.
6. `workflow/pipeline-auto.js`; a smoke run on a throwaway manifest whose steps only write their
   status, one stub per `(leg, status)` pair, covering every loop-back, the bound, the Bounded
   exemption, a halt, a thrown schema error and done. It is the script's `ReturnedTest`.
7. The symlink in `hooks/git-freshness.sh` (or the fallback), with its hook test.
8. Docs: `engine.md` §The loop, §The dispatcher, §Failure policy, §Design size's plan gap;
   `manifest.md` §What a leg writes, §Invariant check, the `plan-insufficient` row; `gates.md` §How the
   dispatcher calls Phase A; `SKILL.md`'s `auto` path; `orchestrate/SKILL.md` and
   `references/commands.md`. `LockStepTest` stays green.
9. Baseline cost per old run from `rows.json` into the PR body, with the keep, add-a-check and revert
   criteria.
10. Both suites green; `/critique pr`; one real `/pipeline auto` run in a Laravel project, measured
    with `run_cost_cli.php` and `run_audit.php`.
