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

- Workflow agents **inherit the launching session's working directory**: launching from the run's
  worktree is enough.
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
invoking session   kickoff (unchanged) → loop counts from the ledger → Workflow(pipeline-auto, args)
                   … on return: record done or halt in the manifest, after-handoff halt duties, report
workflow script    for each step: agent(prompt, opts) → {status, reason, ui} → next step, or return
step agent         php dispatch_cli.php brief <manifest> <leg> <step> → do the leg → write the manifest
                   → return {status, reason, ui}
```

The invoking session is the main session or `/orchestrate`. It is an agent, but only at the edges:
before the script starts and after it returns. Nothing reads a step's work between the two.

### The script

`skills/pipeline/workflow/pipeline-auto.js`, about 60 lines:

```js
const LEGS = ['design', 'review-plan', 'handoff', 'implement', 'verify-ui', 'review-pr']
const LOOP_TARGET = { 'review-plan': 'design', 'verify-ui': 'implement', 'review-pr': 'implement' }
const BOUND = 2
const loops = { 'review-plan': 0, 'verify-ui': 0, 'review-pr': 0, ...args.loops }   // from the ledger at kickoff
let ui = args.ui

let leg = args.startLeg
while (leg) {
  const steps = leg === 'review-plan' || leg === 'review-pr' ? ['review', 'resolve'] : ['run']
  let result
  for (const step of steps) {
    result = await runStep(leg, step)
    if (result.status !== 'continued') break
  }
  if (result.status === 'halted') return halt(leg, result.reason)
  if (result.ui !== undefined) ui = result.ui
  if (result.status === 'looped-back' || result.status === 'plan-insufficient') {
    const gate = result.status === 'plan-insufficient' ? 'review-plan' : leg
    if (!result.escalated && ++loops[gate] > BOUND) return halt(leg, `${gate}: loop-back bound exhausted`)
    leg = result.status === 'plan-insufficient' ? 'design' : LOOP_TARGET[leg]
    continue
  }
  leg = nextLeg(leg, ui)                          // steps over verify-ui unless ui fired; null after review-pr
}
return { action: 'done' }
```

- **`runStep`** builds the prompt and options: `model: 'fable'` on a review step, falling back to
  `model: 'opus'` when `agent()` returns null (a usage limit in a background session; in an
  interactive session the run pauses and continues by itself); `effort: 'low'` on `handoff`. A null
  from any other step, or from the Opus retry, is a halt: *"`<leg> <step>`: the agent returned
  nothing"*. The schema requires only `status`; `reason`, `ui` and `escalated` are optional.
- **`loops`** start from the ledger, so a relaunched run does not get fresh cycles. The invoking
  session counts each gate's `looped-back` entries with the existing PHP; an `unknown` cycle
  (a reconstructed manifest) starts that gate at `BOUND`, which permits no loop-back.
- **The leg order and loop targets are repeated** from `pipeline.php` (`pipeline_legs()`,
  `pipeline_next_leg()`, `pipeline_loop_target()`): about ten lines, used by interactive mode. A
  second copy is accepted; a third would be abstracted.

### The step prompt

Four fixed lines around the step:

1. Run `php ~/.claude/skills/pipeline/checks/dispatch_cli.php brief <manifest> <leg> <step>` and
   follow the brief it prints; it is complete.
2. You cannot start agents. Where a skill or the brief would dispatch one, do that work yourself;
   where that is impossible, return `halted` with the reason.
3. Write your results into the manifest as the brief's `## Return` says.
4. Return `{status, reason}`; the `implement` step also returns `ui` (from `pipeline_triggers` over
   `git diff origin/<base>...HEAD` after its last commit), and a step that escalated a Bounded design
   returns `escalated: true`.

`PIPELINE_NO_OPEN` travels in `args` into the finish step's prompt; the script cannot set the
environment.

### No check on what a step reports

The script trusts each step's `{status}`. That is the settled choice for v1, with its cost stated:

- **What cannot go wrong:** the order. No step picks the next one, so no gate can be skipped and no
  bound exceeded; the review/resolve split cannot be walked past, because the script, not the step,
  decides that `resolve` follows `review`.
- **What can:** a step claiming work it did not do (the experiment's false-success pattern). The next
  gate is the catch: `review-plan` reads the spec and plan, `review-pr` reads the code. It is caught
  later and by judgment, not at once and mechanically.
- **Measured:** every run's ledger is compared with what the steps claimed (the measurement below). A
  false report in the first runs is a reason to add a check — the natural one is a separate Haiku
  agent that only runs `returned` and passes its output on, since the agent that did the work should
  not run its own check.

### What the briefs change — in `auto` only

`pipeline_brief()` takes the step as a parameter instead of deriving it from the ledger (the script
knows which step it runs), and the lines below are added only when `mode === 'auto'`. Interactive
steps are Agent-tool subagents that can dispatch; their briefs stay as they are. `BriefTest` pins
both modes.

- **Review steps:** *"Apply `/critique`'s `plan` (`pr`) procedure yourself — Stage 0, Stage 1 and the
  rubric in `references/rubrics.md`. You are the reviewer; do not dispatch one."* The review step is a
  fresh agent holding only the brief, so `critique/SKILL.md`'s reviewer contract (crafted context,
  never session history) holds. `--verify` and `alternatives` are not available: both dispatch.
- **`review-plan:resolve`:** the independent read (a dispatched agent) is dropped.
- **`implement`:** *"Execute the plan inline, task by task; no subagents."*
- **Every step:** *"The owner authorised this run, including pushing the branch, opening the draft PR
  and marking it ready after `review-pr`; the pipeline never merges."* For the agent's benefit; the
  classifier does not count it as the user's request.

### The gate-skip fix, for interactive

In `auto` the bug the PR #50 reviews found cannot happen: the script knows its step and never derives
it from the ledger. Interactive still derives it (`pipeline_step()`), so it keeps a fix: **resolve
steps may no longer return `plan-insufficient`** (`LegStatus::allowedFor`). A resolve step that finds
a plan gap returns `looped-back`; if `implement` then finds the plan short, it reports the gap itself,
as it already does. The open entry is always completed, no bound is charged twice, and no new outcome
is needed. `DispatchTest` and `ReturnedTest` follow.

### Halts, done, resume

- **Halt:** the script returns `{action: 'halt', leg, reason}`. The invoking session writes it to the
  manifest cursor (`dispatch_cli.php halt <manifest> <leg> <reason>`, new) and, after `handoff`, does
  the failure policy's duties: the reason into the PR body, the proof page opened once. The same
  holds when the workflow itself errors (a schema failed five times): the invoking session records a
  halt with that error.
- **Done:** the finish step marked the PR ready; the invoking session sets `cursor.status: done`.
- **Resume:** a halted run is resumed with `/pipeline`, as today: the manifest is the durable state.
  In `auto`, `/pipeline` starts a new workflow from the cursor's leg, with loop counts from the
  ledger. `resumeFromRunId` is a same-session convenience, never the mechanism.

### Launch

`SKILL.md`'s `auto` path: kickoff as today, then
`Workflow({scriptPath: "$HOME/.claude/skills/pipeline/workflow/pipeline-auto.js", args})` from the
run's worktree, with `args = {manifest, startLeg, loops, ui, noOpen}`. Starting a workflow from a
skill is the documented opt-in. **Unverified and first in the steps:** launching by `scriptPath` from
another repo, where the skills directory is outside the working directory; the fallback is a Read
allow rule for `~/.claude/skills/**` or a copy in `~/.claude/workflows/`. Unattended runs need auto
permission mode or allow rules for push, `gh` and `docker`.

### `/orchestrate`

- Its dispatch step launches the workflow from the run's worktree instead of an engine agent.
- **Stalls:** `/workflows` is a TUI a `claude --bg` orchestrator cannot use. A run that returns
  nothing for 90 minutes is treated as halted: the orchestrator stops it (`TaskStop`) and asks
  *resume* / *leave it out*, as for any halt.
- **"Commits wanted on a ready PR"** no longer has a run to message: the answer is a new workflow
  started at `review-pr` from the re-armed manifest. The rule "one agent per run, ever" becomes "one
  workflow per run at a time".
- Its step 5 drops `engine_peak_cli.php` for `run_cost_cli.php`.

### What goes, what stays

| Goes | Stays |
|---|---|
| The LLM dispatcher (`engine.md` §The dispatcher), its 150k invariant, `engine_peak.php`, `engine_peak_cli.php`, `EnginePeakTest` | `dispatch.php`, `dispatch_cli.php` `next`/`returned` and `brief.php`, for interactive, which is unchanged |
| | Kickoff, the work item, the manifest and what legs write into it, reconstruction, navigation, failure policy, the review/resolve split |

New: `dispatch_cli.php brief` and `halt`, the step parameter on `pipeline_brief()`, the `auto`-only
brief lines, `workflow/pipeline-auto.js`, `run_cost.php` + `run_cost_cli.php`.

### Measurement — cost per run

The old metric (engines' share of usage) would fall even if total usage rose, because the work moves
into step agents. What answers the question is **weighted cost per run**:

- **Baseline:** old engine runs since 2026-09-14 — the engine plus every agent it dispatched — from
  the audit's `rows.json` (weights: cache read 0.1, 5m write 1.25, 1h write 2, output 5). Median,
  quartiles and code lines per run (`pipeline_code_lines` over the run's PR diff) go into the PR body
  before the first new run.
- **New runs:** `run_cost_cli.php` sums the same weights over one workflow run's agent transcripts,
  deduplicated by `message.id`, and prints the per-step breakdown and the largest step peak. Where a
  run's transcripts land is checked before this is written (steps 1 and 5).
- **The comparison is indicative, not controlled:** different issues differ in size. Cost is
  reported with code lines alongside it.
- **Keep it** when, after 6 runs, the median cost per run is at least 30 percent below the baseline
  median, no gate was skipped and no unattended run stalled on a permission.
- **Revert or add a check** when any of: a step's reported status contradicted by its ledger or the
  code (add the Haiku check first); an unattended permission stall that allow rules cannot clear;
  `implement` peaking above 250k on a plan under 300 code lines (it can no longer delegate); median
  cost not at least 30 percent below the baseline after 6 runs.

## Alternatives considered

- **Step agents run `returned` themselves, guarded by a `begin` command** (this spec's first draft):
  hands the agent that did the work the power to move the run, which then needed per-step tokens,
  snapshot checks and mode-conditional returns to police. Rejected by the owner as the machinery
  spiral again.
- **A separate Haiku agent per step that runs the PHP check:** a clean fail-closed check at one small
  agent per step. Deferred until a false report is seen.
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

1. **Verify first**, from a checkout of another repo: launch a two-step stub workflow by `scriptPath`
   into `~/.claude/skills/pipeline/workflow/`; confirm the schema return and where the run's agent
   transcripts land. Run `gh pr ready` / `gh pr ready --undo` on a throwaway draft PR from a workflow
   agent.
2. `pipeline_brief()` step parameter and the `auto`-only lines, test-first (`BriefTest`, both modes).
3. `dispatch_cli.php brief` and `halt`, test-first (`DispatchCliTest`).
4. The gate-skip fix for interactive, test-first (`DispatchTest`, `ReturnedTest`).
5. `run_cost.php` + `run_cost_cli.php` from `engine_peak.php`, test-first; delete `engine_peak*`.
6. `workflow/pipeline-auto.js`; a smoke run on a throwaway manifest whose steps only write their
   status, covering a loop-back, the bound, a halt and done.
7. Docs: `engine.md` §The loop, §The dispatcher, §Failure policy; `SKILL.md`'s `auto` path;
   `orchestrate/SKILL.md` and `references/commands.md`; `LockStepTest` stays green.
8. Baseline cost per old run from `rows.json` into the PR body, with the keep and revert criteria.
9. Both suites green; `/critique pr`; one real `/pipeline auto` run in a Laravel project, measured
   with `run_cost_cli.php`.
