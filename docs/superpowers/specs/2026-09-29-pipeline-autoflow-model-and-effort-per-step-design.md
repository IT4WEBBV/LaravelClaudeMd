# `autoflow` runs every step on an explicit model and effort, from one table — design

**Design size:** Architectural

**Date:** 2026-09-29
**Issue:** IT4WEBBV/LaravelClaudeMd#51
**Canonical home:** a new `skills/pipeline/checks/agents.php` (the table, the override, the start profile);
`dispatch_cli_launch()` in `skills/pipeline/checks/dispatch_cli.php`; `runStep()` and the step loop in
`skills/pipeline/workflow/pipeline-autoflow.js`; `skills/pipeline/checks/run_cost.php`; pipeline
`references/engine.md` (a new §Agents per step, §`autoflow`), `references/manifest.md` and `SKILL.md`.
**Written unattended** by the `design:spec` step of a `/pipeline autoflow` run. Every question the
brainstorm would have asked is answered in *Assumptions*, so `/critique plan` audits exactly those.
Nothing below was built or run.

## Problem

`runStep()` in `pipeline-autoflow.js` sets a model or an effort in three cases only:

```js
const model = returns ? 'sonnet' : step === 'review' ? 'fable' : undefined
…(model ? { model } : {}),
…(leg === 'handoff' ? { effort: 'low' } : {}),
…
const retried = result === null && model === 'fable' ? await agent(prompt, { ...opts, model: 'opus' }) : result
```

Every other step inherits the session's model and effort, which come from `~/.claude/settings.json`: it
differs per machine and can change at any time (today `effortLevel: xhigh` globally,
`modelSettings.claude-opus-5-5.effortLevel: high`). Nothing in the repo records the choice, so a settings
edit silently moves every run. And a `light` run, whose design is Bounded (~25 lines), runs every later
step with the same agents as a large change.

The owner's constraints are tokens (plan limits), quality and speed, not price. The issue's defaults
table is settled (manifest decision, 2026-09-29): *"Use the defaults table as written in the issue. The
table has rows for design:spec and design:plan, which #73 introduced; auto no longer exists, so the Later
section about auto is moot."*

`run_cost_cli.php` weights token types (cache read 0.1, writes 1.25/2, output 5) and is model-blind, so
a model swap does not show in its figure.

## Change

### The table — `PIPELINE_AGENTS` in `checks/agents.php`

One PHP constant is the single source. Models are the aliases `agent()` takes (`opus`, `sonnet`, `fable`);
efforts are `agent()`'s levels (`low`, `medium`, `high`, `xhigh`, `max`).

| Step | `full` | `light` |
|---|---|---|
| `design:spec` | opus high | opus medium |
| `design:plan` | opus high | opus medium |
| `review-plan:review` | fable high | fable medium |
| `review-plan:resolve` | opus high | opus medium |
| `handoff:run` | sonnet low | sonnet low |
| `implement:run` | opus high | opus high |
| `verify-ui:run` | sonnet high | sonnet medium |
| `review-pr:review` | fable high | fable high |
| `review-pr:resolve` | opus high | opus medium |

Three more entries, outside the profiles:

- **`loopedBack`**: `implement:run` → opus xhigh. An entry here replaces the profile's once a gate that
  loops back to the step's leg has looped back in this run (`loops[gate] > 0` for a gate with
  `tables.loopTarget[gate] === leg`): for `implement` that is `verify-ui` or `review-pr`, exactly the
  issue's rule, derived from the routing tables instead of a second list. `review-plan` targets `design`,
  which has no entry, so a plan gap changes nothing here.
- **`retry`**: opus xhigh — the one rerun of a review step that returned nothing (`null`, not a review
  with no findings), as today but at an explicit effort.
- **`smoke`**: sonnet low — every stub step of a smoke run (`args.stub`), today `sonnet` at inherited
  effort. With it here the script holds no model or effort name at all.

The shape the script receives:

```
agents: {
  full:       { "<leg>:<step>": {model, effort}, … },   // every step of tables.steps
  light:      { … },                                    // the same keys
  loopedBack: { "implement:run": {model, effort} },
  retry:      {model, effort},
  smoke:      {model, effort},
}
```

`design:plan`'s `light` entry is unreachable (the plan step runs only on an Architectural size, which is
`full`), and is kept so every step has an entry in both profiles and the script's completeness check stays
one rule.

### Which profile — one variable, `profile`

`launch` answers `profile: 'full' | 'light'`, from `pipeline_start_profile(array $manifest, DesignSize
$size): string` in `agents.php` (pure; `launch` passes `dispatch_cli_design_size($manifest)`):

1. `full` when the ledger has an entry with `outcome: 'escalated'`: escalation is one-way and once per
   run, so a design that grew never returns to `light`, a resume included.
2. Else, when `artifacts.spec` is set: `light` when the spec says Bounded, `full` otherwise.
3. Else (no spec yet): `light` when the manifest has `light: true`, `full` otherwise — the only signal
   before `design` runs.

The script keeps it current with two assignments and no other rule:

- after every `continued` design step: `profile = size === 'Bounded' && !exempted ? 'light' : 'full'`
  (next to the existing `size = result.size`), so once a run has escalated it stays on `full`;
- on a `plan-insufficient` while the size is Bounded (an escalation): `profile = 'full'`, so the grow-form
  `design:spec` and every step after it take `full`.

Every step, `design` included, runs on `profile`. That gives the issue's rule: `light` once the size is
Bounded, `design:spec` on the `light` flag before a spec exists, `full` again from an escalation on. A
`light` run whose spec step writes an Architectural design runs `design:plan` and everything after it on
`full`.

### The script

- `settingFor(leg, step)`: `agents.loopedBack[key]` when a gate targeting `leg` has looped back and the
  entry exists, else `agents[profile][key]`. `runStep()` spreads it into `opts` (`model` and `effort`)
  on every call; a stub step takes `agents.smoke` instead.
- The retry: a `review` step whose agent returns `null` reruns once on `{...opts, ...agents.retry}`. The
  condition becomes `step === 'review' && !returns` instead of `model === 'fable'`, so an override that
  runs a review on another model keeps its retry, and a smoke run still never retries (as today, where
  stubs run on `sonnet`).
- `complete()` gains an agents check: `agents.full` and `agents.light` hold an entry for every
  `<leg>:<step>` of `tables.steps`, each with a non-empty string `model` and `effort`; `retry` and `smoke`
  are such entries; `loopedBack` is an object whose keys are steps of `tables.steps` and whose values are
  such entries; `profile` is `full` or `light`. A missing or incomplete table halts before any agent:
  `args carry no complete agents table: re-run launch from checks that have pipeline_agent_table()`.
- The script contains no model or effort literal; the `COPIED` / `UNSATISFIABLE` constants stay.
- `meta.phases` gets no `model`: `meta` is a pure literal and would be a copy of the table.

### The manifest override — `agents`

An optional manifest field, set by hand for a one-off experiment (no experiment is scheduled):

```json
"agents": { "review-plan:review": { "model": "opus", "effort": "xhigh" } }
```

`pipeline_agent_table(array $override): array` returns the script's `agents`: `PIPELINE_AGENTS` with each
overridden step's fields merged over its entry in `full`, `light` and, when it has one, `loopedBack`. An
override may name one field (`{"effort": "medium"}`) and keeps the other. The run keeps the setting for
that step whatever the profile or loop-backs; the `retry` and `smoke` entries are not overridable.

`pipeline_agent_override_problem(mixed $override): ?string` refuses, and `launch` halts with
`the manifest's agents override is invalid: <what>` (a `pipeline_halt`, no manifest write, like the other
invalid-manifest halts) on: not an object; a key that is not an `autoflow` `<leg>:<step>`
(`pipeline_steps($leg, 'autoflow')`); an entry with no field, an unknown field, a model outside
`opus`/`sonnet`/`fable`, or an effort outside `low`…`max`. `agents` is not a leg-writable key
(`pipeline_leg_writable_keys()` is unchanged), so a step that writes it halts at the next brief.

### `launch`

`dispatch_cli_launch()`'s `start` answer gains two keys, next to `tables`:

```
'profile' => pipeline_start_profile($manifest, dispatch_cli_design_size($manifest)),
'agents' => pipeline_agent_table($manifest['agents'] ?? []),
```

after the override check, which runs with the other manifest checks before anything is written.
`dispatch_cli.php` requires `agents.php`; `tests/Pest.php` loads it.

### The docs, pinned by `LockStepTest`

- **engine.md** gets `## Agents per step — one table, explicit model and effort`, after §`autoflow`: the
  table above with the issue's *Why* column, one row per step written exactly
  `` | `<leg>:<step>` | <model> <effort> | <model> <effort> | <why> | ``; then
  `` | `implement:run` after a loop-back | opus xhigh | opus xhigh | … | ``,
  `| a review that returned nothing, once | opus xhigh | opus xhigh | … |` and
  `| a smoke run's stub step | sonnet low | sonnet low | … |`; the profile rule, the loop-back rule, the
  override, and why Fable stays the reviewer. §`autoflow`'s sentence *"A review step runs on Fable, and
  once more on Opus when it returns nothing; `handoff` runs at low effort"* becomes a pointer to it, and
  the `start` answer line gains `"profile":…,"agents":{…}`.
- **manifest.md**: an `agents` row (optional; the override above) and the `light` row says it also picks
  the `light` profile until a spec exists.
- **SKILL.md**: the `light` bullet says a small change also gets lighter agents on the design,
  review-plan, verify-ui and resolve steps, and names §Agents per step.
- **`LockStepTest`** gets two cases: the engine.md section contains every row string built from
  `pipeline_agent_table([])` (the nine steps, the loop-back row, the retry row, the smoke row); and the
  script's source contains none of `'opus'`, `'sonnet'`, `'fable'`, `'haiku'`, `'low'`, `'medium'`,
  `'high'`, `'xhigh'`, `'max'` as quoted literals, so the table cannot be copied into it.

### `run_cost.php` — a factor per model and token type

`PIPELINE_MODEL_FACTORS`, relative to Opus 5.5's rates per token type, from the `claude-api` skill's
price table (cached 2026-09-25):

| family | input | cache write (5m, 1h) | cache read | output |
|---|---|---|---|---|
| `opus` (Opus 5.5: $4 / $20, read $0.20) | 1.0 | 1.0 | 1.0 | 1.0 |
| `fable` (Fable 5.1: $10 / $50, read $0.25) | 2.5 | 2.5 | 1.25 | 2.5 |
| `sonnet` (Sonnet 5.5: $2 / $10, read $0.20) | 0.5 | 0.5 | 1.0 | 0.5 |
| `haiku` (Haiku 4.5: $1 / $5, read $0.10) | 0.25 | 0.25 | 0.5 | 0.25 |

- `pipeline_transcript_usage()` returns `{model, usage}` per API call (still deduplicated by
  `message.id`, the last occurrence winning), `model` being `message.model`.
- `pipeline_call_cost(array $usage, string $model = '')` multiplies each type's existing weight by the
  family's factor for that type. The family is the word after `claude-` (`claude-fable-5-1` → `fable`); a
  family not in the table, `<synthetic>` or no model counts as `opus` (factor 1.0), so every figure
  measured so far on Opus stays what it was.
- Each step line names the families its calls ran on, in first-seen order, joined with `+`:
  `review-plan:review (fable): 0.09M over 2 calls, …`; a step with no model shows no parenthetical. The
  `run:` line is unchanged in form.
- engine.md §`autoflow` and SKILL.md say the cost is weighted per model.

## Approaches considered

1. **The table in PHP, handed to the script in `launch`'s answer (chosen).** The route #71 took for the
   routing tables: tested PHP owns it, the script keeps no copy, `LockStepTest` pins the docs to it, and
   a stale script or answer halts before any agent.
2. **The table in the script.** Fewer moving parts, but it is a second source beside the docs, the
   override would have to be read from the manifest by a script that cannot read files, and the
   profile's start state (`light` flag, spec, ledger) is only readable in PHP.
3. **Agent types (`.claude/agents/*.md`) per step.** `auto`'s route, removed with `auto` (#87); effort
   in a frontmatter file is again a second source, and `agentType` does not combine with a per-call
   profile.
4. **One profile, no `light`.** Simpler, but the issue's point is that a Bounded change should not pay
   for large-change agents on every leg.

## What does not change

- The routing tables, statuses, the bound, the ledger shape, the briefs, `interactive` (its agents are
  the session's; the issue scopes the table to `autoflow`).
- `run_audit.php`, the journal reader and the wall-time measurement (#78).
- Fable stays the reviewer on both review legs: reviews on Opus would be the largest token lever, but
  give up an independent reviewer; not part of this change.

## A run in flight when this lands

A workflow already running keeps its script and args. A relaunch of an older manifest works unchanged:
the manifest has no `agents` and `launch` hands out the new keys. The new script over an old `launch`
answer (a cached answer passed by hand) halts before any agent with the agents-table reason.

## Done when

- `AgentsTest` (new): `pipeline_agent_table([])` has every `autoflow` step in both profiles with the
  table's values, `loopedBack`, `retry`, `smoke`; an override of one step replaces it in `full`, `light`
  and `loopedBack` and leaves every other step; a one-field override keeps the other field;
  `pipeline_agent_override_problem()` names each refused shape; `pipeline_start_profile()` for each of
  its three rules, the escalated ledger over a Bounded spec and a `light` flag included.
- `DispatchCliTest`: `launch`'s `start` answer carries `profile` and `agents` (with the manifest's
  override applied); an invalid override halts `launch` and leaves the manifest as it was.
- `AutoflowScriptTest` (the replay records each call's `model` and `effort`): a run without `light`
  gives every step its `full` entry; a `light` run gives `design:spec` `opus medium` and, after a Bounded
  return, `review-plan:review` `fable medium` and `review-pr:review` `fable high`; a `light` run whose
  spec step returns Architectural runs `design:plan` and later steps on `full`; a Bounded escalation runs
  the grow-form `design:spec` and every later step on `full`; `implement:run` runs `high` first and
  `xhigh` after a `verify-ui` or a `review-pr` loop-back, and after a resume whose `loops` already count
  one; a review returning `null` reruns on `retry`; the script follows whatever `agents` it is given
  (an edited entry shows in the call); a missing or incomplete `agents` or `profile` halts before any
  agent.
- `LockStepTest`: the two cases above.
- `RunCostTest`: a Fable call and a Sonnet call weigh their factors per token type; an unknown family and
  `<synthetic>` weigh as Opus; the step line names the families; the existing Opus expectations keep
  their numbers.

## Assumptions

1. *Does "the table has rows for design:spec and design:plan" mean two separate settings?* The issue's
   single `design` row (Opus high / Opus medium) applies to both steps; they get one row each so every
   `<leg>:<step>` has an entry.
2. *Which profile does `design:spec` take on a resume or a loop-back, when a spec already exists?* The
   spec's size, not the flag: a `light` run whose spec is Architectural reruns its spec step on `full`.
   The flag only decides while no spec exists, which is what the issue means by "the only signal before
   design runs". `launch` passes the decided `profile`, because only PHP can tell "no spec" from an
   Architectural one (`dispatch_cli_design_size()` answers Architectural for both).
3. *Does the grow-form `design:spec` after an escalation run `light`?* No, `full`: the issue says every
   step after an escalation takes `full`, and growing a design into an Architectural one is not the
   ~25-line work `light` is sized for. A resume after an escalation keeps `full` through the ledger's
   `escalated` entry.
4. *What triggers `implement`'s xhigh after a resume?* `launch`'s `loops`, counted from the ledger, so a
   run relaunched after a `review-pr` loop-back keeps xhigh. A reconstructed ledger (`cycle: unknown`)
   counts as the bound and so as looped back; that is rare and errs towards more effort.
5. *Does a plan gap raise `implement`'s effort?* No: it loops back to `design`, charged to `review-plan`,
   and the issue names only `review-pr` and `verify-ui`.
6. *May the override name the retry, the smoke stub or the loop-back entry on its own?* No: `leg:step`
   keys only, applied to that step in both profiles and its loop-back entry. One key per step keeps the
   manifest field as the issue wrote it.
7. *Which models may an override name?* `opus`, `sonnet`, `fable`. Haiku 4.5 has no effort setting (the
   issue rejects it for `handoff` for that reason, and the `claude-api` skill confirms `effort` errors on
   Haiku 4.5), and every entry carries an effort.
8. *Do the aliases resolve to the models the issue means?* `opus` → Opus 5.5 and `fable` → Fable 5.1, as
   the current transcripts show (`claude-opus-5-5`, `claude-fable-5-1` in `message.model`); `sonnet` →
   Sonnet 5.5, the current Sonnet. The table stores the alias, so a new model generation needs no change
   here.
9. *Why normalise the cost factors to Opus rather than re-derive every weight from prices?* The owner
   compares runs across time, and every figure so far is an Opus figure under the existing weights;
   factor 1.0 for Opus keeps them comparable. The factors are a proxy for plan-limit use, as the issue
   says; the prices are the skill's cached table. Haiku 4.5's cache-read price is not in that table and
   is taken as $0.10 (0.1× its input, the usual ratio); cache writes are taken as 1.25× / 2× input on
   every model, so their factor equals the input factor.
10. *Is naming the model on each step line in scope?* Yes, as the smallest way to make "a model swap
    shows up" readable: a changed figure with no visible model would not say why it changed.
11. *Why a new `agents.php` and not `dispatch.php`?* `dispatch.php` holds routing (441 lines); the table,
    its override and the start profile are one small unit with its own test file.
12. *Does `interactive` get the table?* No. Its design and resolve steps run inline in the human's
    session, and the issue scopes the change to `autoflow`'s `agent()` calls.
13. No probe was needed: `agent()` already takes `model` (`fable`, `opus`, `sonnet`) and `effort`
    (`low` for `handoff`) in today's script, and the Workflow reference lists `effort` as
    `low | medium | high | xhigh | max`.
14. *What does a run do when a grow-form spec step returns Bounded after an escalation?* It stays on
    `full`: the design assignment reads `exempted` (the script's existing once-per-run escalation flag),
    so escalation is one way in the run exactly as it is on a resume, which takes `full` from the
    ledger's `escalated` entry. One condition in the existing line, not a third rule. (Changed after
    `review-plan` cycle 1; the plan step had first let the profile follow the returned size.)
15. *What does a step line name for a call whose model is `<synthetic>`, empty, or a family the factor
    table lacks?* Assumed by the plan step: a model that does not start with `claude-` names nothing (so
    `<synthetic>` and a missing model add no family to the parenthetical); `claude-<word>-…` names
    `<word>` even when the table lacks it, and weighs it as `opus`. The line then says which model ran
    without claiming a factor for it.
16. *Does the override check run on a finished run's `launch`?* Assumed by the plan step: yes, with the
    other manifest checks and before the finished rule, so a `done` manifest with an invalid `agents`
    halts (nothing written) instead of answering `done`.
