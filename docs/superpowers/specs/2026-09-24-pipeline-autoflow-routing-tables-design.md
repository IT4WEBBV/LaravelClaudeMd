# `pipeline-autoflow`'s routing tables come from `launch` — design

**Design size:** Architectural

Issue #71. Builds on `2026-09-23-pipeline-auto-workflow-design.md` (PR #50) and on `launch` as #69 left
it. `skills/pipeline/workflow/pipeline-autoflow.js` routes an `autoflow` run by five tables it keeps by
hand: `LEGS`, `STEPS`, `LOOP_TARGET`, `ALLOWED` and `BOUND`. They repeat `pipeline_legs()`,
`pipeline_steps()`, `pipeline_loop_target()`, `LegStatus::allowedFor()` and `PIPELINE_LOOP_BOUND`,
which `auto` and `interactive` route by. `LockStepTest` keeps the copies in step by matching the
script's text. So every table change is made twice, and the test catches only the drift shapes it knows
how to match. `ALLOWED` is also lossy: its `review`, `resolve` and `run` keys each stand for several
legs, so if `allowedFor()` ever treated `handoff` differently from `implement`, the script would not
see it and neither would the test.

## The change

The tested PHP becomes the only source. `launch` passes the tables to the script in `args`, the way it
already passes `loops`, `ui` and `size`.

### `pipeline_routing_tables()` in `dispatch.php`

A pure function that sits next to the functions it reads:

```php
pipeline_routing_tables(): array
// → ['legs'       => pipeline_legs(),
//    'steps'      => [<leg> => pipeline_steps(<leg>), …],             every leg, `run` legs too
//    'loopTarget' => [<leg> => pipeline_loop_target(<leg>), …],       only legs with a target
//    'allowed'    => ['<leg>:<step>' => allowedFor(<leg>, <step>) values, …], every pair of `steps`
//    'bound'      => PIPELINE_LOOP_BOUND]
```

With today's functions that is 6 legs, 8 `allowed` entries and 3 loop targets: about 600 bytes of JSON.

### `launch`

`dispatch_cli_launch()` adds `'tables' => pipeline_routing_tables()` to its `start` answer. Nothing else
in `launch` changes. `done` and `halt` answers carry no tables, because they start no script.

### The script

- `const { legs, steps, loopTarget, allowed, bound } = args.tables`, read after the start-answer guard.
  `LEGS`, `STEPS`, `LOOP_TARGET`, `ALLOWED` and `BOUND` are removed.
- The guard already halts on `args` that are not a `start` answer. It now also halts when `tables` is
  missing or incomplete, with the same reason, *"args are not a launch start answer"*, and before any
  agent. **Complete** means all of these hold:
  - `legs` is a non-empty array;
  - every leg has a non-empty `steps` array;
  - every `<leg>:<step>` pair has an `allowed` array;
  - `loopTarget` is an object whose keys and values are all legs;
  - `bound` is an integer.
  A script that would otherwise route on `undefined` (a schema with no `enum`, `++loops[gate] > undefined`
  never true) halts instead of routing wrongly.
- `schemaFor()` looks up `allowed[`${leg}:${step}`]` and has no fallback key. The step loop reads
  `steps[leg]` with no `?? ['run']`. Its local `steps` variable becomes `remaining`, so it no longer
  shadows the table.
- `loops` starts with 0 for every key of `loopTarget`, then takes `args.loops` over it. It no longer
  names the three gates itself.
- `meta.phases` stays a literal. The Workflow runtime requires `meta` to be a pure literal, and it is
  display-only, so a drift there changes a label, not a route. The header comment says so.

**Out of scope:** the literals left in the script are behaviour, not tables. They are:
- `'verify-ui'` as the conditional leg in `nextLeg()`;
- `'design'` and `'review-plan'` as the target and gate of `plan-insufficient`;
- `COPIED`, the step-specific schema fields;
- the model and effort choices.

`pipeline_next_leg()` and `pipeline_route()` hold the same literals in PHP. The issue names five tables,
and these are not among them.

### Tests

- **`DispatchCliTest`**:
  - the existing launch test's expected `start` answer gains `tables`;
  - a new test checks `launch`'s `tables` against the functions directly, not against
    `pipeline_routing_tables()`: `legs`, `bound`, `steps` per leg, `loopTarget` per leg (absent where
    the function gives null) and `allowed` per `<leg>:<step>`, with no extra keys.
- **`LockStepTest`** drops its third test, the JS half. Its `manifest.md` and `gates.md` halves stay.
- **`AutoflowScriptTest`** (new) with **`tests/autoflow_replay.mjs`** (new) replaces the text match with
  a behavioural check. The harness runs the byte-identical script under `node`, outside the Workflow
  runtime:
  - it reads `{script, args, returns}` from stdin;
  - it turns `export const meta` into `const meta` and runs the rest as an async function body
    `(args, agent, log)`;
  - its `agent()` records each label and returns the next return scripted for it;
  - its `agent()` throws, as the runtime's schema validation would, when there is no scripted return,
    when the schema is unsatisfiable, or when the scripted status is not in the schema's `enum`;
  - it prints `{labels, result}`.

  `args` is always a real `launch` answer on a `dispatch_fixture()` manifest, so the tables under test
  are the ones `launch` prints. The cases:

  | Case | Start | Expected |
  |---|---|---|
  | the smoke run's `a-done` stubs | `design` | its 16 labels, `{action: done}` |
  | the smoke run's `b-gap-bound` stubs | `implement` | its 9 labels, halt at `implement`: *"review-plan: loop-back bound exhausted"* |
  | `tables.bound` set to 0 and `loops['verify-ui']` removed; `verify-ui` loops back | `implement` | halt at `verify-ui` on the bound: the script uses the bound it is given, and `loops` defaults from `loopTarget` |
  | `looped-back` removed from `allowed['review-pr:resolve']`, resolve returns it | `review-pr` | halt *"the agent failed: review-pr:resolve may not return looped-back"*: the schema comes from `allowed` |
  | a Bounded spec, two `plan-approval` loop-backs in the ledger; `handoff` and then `review-plan:review` return `plan-insufficient` | `handoff` | the first is exempt; the second is counted and halts at `review-plan` on the bound |
  | `startStep: resolve` on `handoff` | `handoff` | no agent, halt *"handoff has no resolve step"* |
  | no `tables`; `allowed` missing `handoff:run`; `steps` missing `verify-ui`; a `loopTarget` to no leg; `bound` `'2'` | `handoff` | no agent, halt *"args are not a launch start answer"* |

  Against today's script, the rows that change a table, and the missing or incomplete tables, fail: 6 of
  the 10 tests. That shows the script uses no table of its own.

A scratch copy of this branch with the change passes the whole pipeline suite: 260 tests, which is 250
on main, minus 1, plus 11.

### The smoke pass

The issue's *Done when* asks that the smoke pass still match, for at least `a-done` and one
bound-exhaustion scenario. The smoke run's stubs take their `args` from `launch` (the 2026-09-23 plan,
Task 6, Step 3), so they get `tables` the same way with no change to them.

An `autoflow` implement step cannot start agents, so it cannot start a Workflow either. Two routes cover
this:
- The replay test covers the routing of `a-done` and `b-gap-bound` in the suite, on every run.
- If the implementing session has the Workflow tool, it also runs those two scenarios against the saved
  script with the stub prompt. If it does not, the PR body says that the Workflow smoke pass was not
  run, and names the two scenarios, so the owner can run them before merging.

### Docs that change

- `references/engine.md` §`autoflow`:
  - the `launch` answer shape gains `"tables":{…}`;
  - the `launch` bullet names `tables` (`pipeline_routing_tables()`);
  - the script bullet says each step's schema comes from `tables.allowed` and the bound from
    `tables.bound`.
- `references/engine.md` §A plan gap on an Architectural design: "the script's `BOUND`" becomes
  "`tables.bound`".
- `references/gates.md` §Loop-backs:
  - `autoflow` routes on `tables.loopTarget` and `tables.bound` from `launch`;
  - `LockStepTest` holds this list to the function only.
- The script's header comment.

The 2026-09-23 plan and spec are records of what was built, and they stay as they are.

## Alternatives considered

- **Keep the copies and harden `LockStepTest`** (parse the JS object literals instead of matching
  strings). This still means two edits per change, and a parser for a moving script. Rejected: the
  issue's point is one source.
- **Generate the tables into the script at build time** (a PHP step that rewrites the literals). This
  adds a build step to a repo that has none, and the generated text still has to be committed and
  checked. Rejected: `args` already carries `launch`'s other answers.
- **Have the script call `dispatch_cli.php`.** Rejected: a Workflow script has no filesystem or process
  access.
- **`allowed` keyed as the script keys it today** (`'design:run'`, `review`, `resolve`, `'verify-ui:run'`,
  `run`). The issue says this, but PHP would then need a hand-picked representative leg per shared key,
  the same list `LockStepTest` hard-codes today, and that list silently goes wrong the day `allowedFor()`
  tells two legs sharing a key apart. Every `<leg>:<step>` pair costs three more entries and removes the
  script's fallback lookup. See *Assumptions* 1.

## Assumptions

These are the questions brainstorming would have asked, with the answer assumed.

1. *`allowed` keyed as the script keys it today, as the issue says, or by every `<leg>:<step>`?* By
   every pair. This departs from the issue's wording, for the reason in *Alternatives considered*: the
   shared keys need a hand-kept mapping in PHP, which is the kind of copy this issue removes. The
   script's lookup becomes `allowed[`${leg}:${step}`]`, and the 8 entries add about 150 bytes.
2. *`steps` for every leg, or only the two review legs as `STEPS` has today?* Every leg, so the script
   keeps no `['run']` default.
3. *`loopTarget` with null entries, or only legs that have a target?* Only legs with a target, matching
   `LOOP_TARGET` and `gates.md`.
4. *What counts as "incomplete" tables?* The five checks under *The script*: whatever would otherwise
   let the script route on `undefined`. The script does not check deeper, for example that `legs`
   equals `meta.phases`: the tables are `launch`'s output, and `DispatchCliTest` pins them.
5. *How is the smoke pass shown when the implement step cannot start a Workflow?* By the replay test in
   the suite, and by a Workflow smoke run when the tool is there. When it is not, the PR body says the
   run was not made (*The smoke pass*). The replay test does not run `brief` or the stub prompt. That
   is enough for this change, which moves only where the routing tables come from.
6. *Is `node` an acceptable dependency of the pipeline suite?* Yes, and it is required, not skipped: a
   skipped test would hide exactly the drift this issue exists to catch. `node` v24 is on this machine;
   on a machine without it, the test fails and its message names `node`.
7. *Where does `pipeline_routing_tables()` live?* In `dispatch.php`, beside `pipeline_steps()`,
   `pipeline_loop_target()`, `LegStatus` and `PIPELINE_LOOP_BOUND`. `dispatch_cli.php` already loads it
   along with `pipeline.php`.
8. *Should `loops` still list the three gates in the script?* No. Its defaults come from
   `loopTarget`'s keys, and `launch`'s `loops` (`pipeline_loop_counts()`) overrides them as before.
9. *Should `meta.phases` get a check against `pipeline_legs()`?* No. The issue keeps it a literal and
   display-only, and `LockStepTest` loses its JS half entirely.
10. *Changelog?* None. This repo has no `.changelog/` and no `CHANGELOG.md`.
