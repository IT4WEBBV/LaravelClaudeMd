# `pipeline-autoflow`'s routing tables come from `launch` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task by task. Steps use checkbox (`- [ ]`) syntax for tracking. In an `autoflow` implement step, execute inline, task by task, with no subagents.

**Goal:** `pipeline-autoflow.js` keeps no routing table of its own. It routes by the `tables` that `dispatch_cli.php launch` builds from the PHP functions `auto` and `interactive` route by. A replay test replaces `LockStepTest`'s text match of the script.

**Architecture:**
- `pipeline_routing_tables()` in `dispatch.php` builds `{legs, steps, loopTarget, allowed, bound}` from `pipeline_legs()`, `pipeline_steps()`, `pipeline_loop_target()`, `LegStatus::allowedFor()` and `PIPELINE_LOOP_BOUND`.
- `launch` adds the result to its `start` answer.
- The script destructures `args.tables` after a guard that halts on missing or incomplete tables.
- `AutoflowScriptTest` runs the unchanged script under `node`, with `agent()` faked, on real `launch` answers.

**Tech Stack:** PHP 8.4 on the host, Pest 4 (`./vendor/bin/pest`), Node 24 (for the replay test only), plain JavaScript (the Workflow script), Markdown skill references.

**Spec:** `docs/superpowers/specs/2026-09-24-pipeline-autoflow-routing-tables-design.md`

**Verified before writing (2026-09-24):**
- Tasks 1 and 2's code, assembled into a scratch copy of this branch, passes the whole pipeline suite: 261 tests. That is 250 on main, minus `LockStepTest`'s JS half, plus 1 in `DispatchCliTest` and 11 in `AutoflowScriptTest`. Re-run after the plan review's edits (the tables halt reason, the non-empty `allowed` check and its dataset case).
- Against today's script, `AutoflowScriptTest` has 7 failures and 4 passes. The passes are `a-done`, `b-gap-bound`, the Bounded exemption and the start-step halt, whose routing does not change.
- The harness ran the current script under Node v24.12.0 with top-level `return` and `await` inside an `AsyncFunction` body.

## Global Constraints

- `tables` is exactly `{legs, steps, loopTarget, allowed, bound}`:
  - `steps` has every leg (`run` legs too);
  - `loopTarget` has only the legs with a target;
  - `allowed` is keyed by every `<leg>:<step>` pair of `steps`, and its values are `LegStatus` values;
  - `bound` is `PIPELINE_LOOP_BOUND`.
- Only the `start` answer carries `tables`. `done` and `halt` do not.
- The script halts with `args are not a launch start answer`, before any agent, when `action` is not `start` or `startLeg` is not a leg. It halts with `args carry no complete tables: re-run launch from checks that have pipeline_routing_tables()`, before any agent, when `tables` is missing or incomplete, so a launch from an older `checks` directory names its cause. Incomplete means any of these:
  - `legs` is not a non-empty array;
  - a leg has no non-empty `steps` array;
  - a `<leg>:<step>` has no non-empty `allowed` array;
  - `loopTarget` is not an object whose keys and values are all legs;
  - `bound` is not an integer.
- `meta.phases` stays a literal.
- These script literals stay: `'verify-ui'` in `nextLeg()`, `'design'` and `'review-plan'` for `plan-insufficient`, `COPIED`, `UNSATISFIABLE`, and the model and effort choices.
- `node` is required by the suite and never skipped.
- Suites (from the worktree root, on the host):
  - `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
  - `./vendor/bin/pest -c skills/critique/checks/phpunit.xml --test-directory=skills/critique/checks/tests`
  - `bash skills/orchestrate/tests/owners_test.sh`
- This repo has no `.changelog/` and no `CHANGELOG.md`, so there is no changelog entry.
- The 2026-09-23 spec and plan are records and are not edited.

## Review Focus

1. **A launch from an older `checks` directory**, one without `tables`, reaching the new script (for example a stale `~/.claude/workflows` link on one machine against a fresh checkout on the other) must halt before any agent, not route on `undefined`, with a reason that names `tables` rather than `action` or `startLeg`. Pinned in Task 2 by the `no tables` dataset case.
2. **A `loops` without a gate's key** (an older or hand-built answer) must still count loop-backs. `++undefined > bound` is never true, so without defaults the bound would never be reached. Pinned in Task 2: `unset($start['loops']['verify-ui'])` in the bound-0 case.
3. **The Bounded exemption, once per run, with `launch`'s ledger counts already at the bound.** The first `plan-insufficient` is exempt and the second halts. Pinned in Task 2 by the Bounded case.
4. **A `startStep` the leg does not have** must halt naming it, before any agent, now that `steps` comes from `args`. Pinned in Task 2 by the start-step case.
5. **`node` missing on a machine**: the suite fails with *"AutoflowScriptTest needs node on PATH"* instead of a confusing pipe error. Pinned in Task 2 by the `toBeResource` guard in `autoflow_replay()`. It cannot be exercised without removing `node`.

---

## File Structure

- Modify `skills/pipeline/checks/dispatch.php`: add `pipeline_routing_tables()`.
- Modify `skills/pipeline/checks/dispatch_cli.php`: add `tables` to `launch`'s `start` answer.
- Modify `skills/pipeline/checks/tests/DispatchCliTest.php`: add `tables` to the expected answer, plus a new test.
- Create `skills/pipeline/checks/tests/autoflow_replay.mjs`: the Node harness.
- Create `skills/pipeline/checks/tests/AutoflowScriptTest.php`: the replay cases.
- Modify `skills/pipeline/workflow/pipeline-autoflow.js`: read the tables from `args`.
- Modify `skills/pipeline/checks/tests/LockStepTest.php`: drop the JS half.
- Modify `skills/pipeline/references/engine.md` and `skills/pipeline/references/gates.md`: the docs.
- Modify `README.md`: `node` as a machine-setup requirement of the pipeline suite.

---

### Task 1: `launch` hands over the routing tables

**Files:**
- Modify: `skills/pipeline/checks/dispatch.php` (after `pipeline_loop_target()`, before `pipeline_is_open()`)
- Modify: `skills/pipeline/checks/dispatch_cli.php` (`dispatch_cli_launch()`'s return)
- Test: `skills/pipeline/checks/tests/DispatchCliTest.php`

**Interfaces:**
- Produces: `pipeline_routing_tables(): array{legs: list<string>, steps: array<string, list<string>>, loopTarget: array<string, string>, allowed: array<string, list<string>>, bound: int}`.
- Produces: `launch`'s `start` answer gains `"tables"` with that shape, JSON-encoded. `steps`, `loopTarget` and `allowed` are objects.

- [ ] **Step 1: Install the dev dependencies in the worktree** (`vendor/` is gitignored and not in the worktree)

A symlinked `vendor/` does not work, because Pest cannot name the test classes.

Run: `composer install --no-interaction --quiet && ./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`

Expected: green, 250 passed.

- [ ] **Step 2: Write the failing tests**

In `skills/pipeline/checks/tests/DispatchCliTest.php`, find the test `it('launches from the cursor with the ledger\'s loop-backs, the design size and ui', …)`. Add the last line of its expected answer after `'checks'`:

```php
        'checks' => realpath(__DIR__ . '/..'),
        'tables' => pipeline_routing_tables(),
    ]);
```

Then add this test directly after that test, before `it('marks noOpen when the launch runs unattended', …)`. It checks the tables against the functions themselves, not against `pipeline_routing_tables()`:

```php
it('hands the autoflow script its routing tables, from the functions interactive mode routes by', function () {
    $fixture = dispatch_fixture(['mode' => 'autoflow']);
    $tables = dispatch_cli(['launch', $fixture['manifest'], $fixture['diff']])['json']['tables'];
    $values = fn (array $statuses) => array_map(fn (LegStatus $status) => $status->value, $statuses);

    expect($tables['legs'])->toBe(pipeline_legs());
    expect($tables['bound'])->toBe(PIPELINE_LOOP_BOUND);
    expect(array_keys($tables['steps']))->toBe(pipeline_legs());
    expect(array_keys($tables['loopTarget']))->toBe(array_values(array_filter(pipeline_legs(), fn (string $leg) => pipeline_loop_target($leg) !== null)));
    $pairs = [];
    foreach (pipeline_legs() as $leg) {
        expect($tables['steps'][$leg])->toBe(pipeline_steps($leg));
        expect($tables['loopTarget'][$leg] ?? null)->toBe(pipeline_loop_target($leg));
        foreach (pipeline_steps($leg) as $step) {
            $pairs[] = "{$leg}:{$step}";
            expect($tables['allowed']["{$leg}:{$step}"])->toBe($values(LegStatus::allowedFor($leg, $step)));
        }
    }
    expect(array_keys($tables['allowed']))->toBe($pairs);
});
```

- [ ] **Step 3: Run them to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='launches from the cursor|hands the autoflow script'`

Expected: both FAIL. The first fails with `Call to undefined function pipeline_routing_tables()`. The second fails on a null `tables`: `array_keys()` or an offset on null.

- [ ] **Step 4: Write the implementation**

In `skills/pipeline/checks/dispatch.php`, directly after `pipeline_loop_target()`:

```php
/**
 * What the `autoflow` script routes by, as `launch` hands it over (`tables` in its `start` answer), so the
 * script keeps no copy: the legs in order, each leg's steps, the loop-back targets, the statuses each
 * `<leg>:<step>` may return, and the bound.
 *
 * @return array{legs: list<string>, steps: array<string, list<string>>, loopTarget: array<string, string>, allowed: array<string, list<string>>, bound: int}
 */
function pipeline_routing_tables(): array
{
    $legs = pipeline_legs();
    $steps = array_combine($legs, array_map(pipeline_steps(...), $legs));
    $allowed = [];
    foreach ($steps as $leg => $legSteps) {
        foreach ($legSteps as $step) {
            $allowed["{$leg}:{$step}"] = array_map(fn (LegStatus $status) => $status->value, LegStatus::allowedFor($leg, $step));
        }
    }

    return [
        'legs' => $legs,
        'steps' => $steps,
        'loopTarget' => array_filter(array_combine($legs, array_map(pipeline_loop_target(...), $legs))),
        'allowed' => $allowed,
        'bound' => PIPELINE_LOOP_BOUND,
    ];
}
```

In `skills/pipeline/checks/dispatch_cli.php`, `dispatch_cli_launch()`'s `start` return, after `'checks' => __DIR__,`:

```php
        'checks' => __DIR__,
        'tables' => pipeline_routing_tables(),
    ];
```

- [ ] **Step 5: Run the suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`

Expected: green, 251 passed. `LockStepTest` is still green, because the script is unchanged.

- [ ] **Step 6: Commit**

```bash
git add skills/pipeline/checks/dispatch.php skills/pipeline/checks/dispatch_cli.php skills/pipeline/checks/tests/DispatchCliTest.php
git commit -m "pipeline: launch hands the autoflow script its routing tables (#71)"
```

---

### Task 2: The script routes by `args.tables`; a replay test replaces the text match

**Files:**
- Create: `skills/pipeline/checks/tests/autoflow_replay.mjs`
- Create: `skills/pipeline/checks/tests/AutoflowScriptTest.php`
- Modify: `skills/pipeline/workflow/pipeline-autoflow.js`
- Modify: `skills/pipeline/checks/tests/LockStepTest.php`

**Interfaces:**
- Consumes: `launch`'s `start` answer with `tables` (Task 1), and `dispatch_fixture()` and `dispatch_cli()` from `DispatchCliTest.php`. Pest loads every test file before it runs any test, so both are defined when these tests run.
- Produces: `autoflow_replay(array $args, array $returns): array{labels: list<string>, result: array}` and `autoflow_start(string $leg, array $ledger = [], ?string $spec = null): array`. `$returns` maps an agent label (`<leg>:<step>`) to the list of structured results its calls return, in order.

- [ ] **Step 1: Write the harness**: `skills/pipeline/checks/tests/autoflow_replay.mjs`

```js
// Replays ../../workflow/pipeline-autoflow.js outside the Workflow runtime: stdin is
// {script, args, returns}; agent() is faked, each call taking the next return scripted for its label and
// refusing a status its schema does not allow, as the runtime's StructuredOutput would. Prints
// {labels, result}: the agent labels in call order and what the script returned.
import { readFileSync } from 'node:fs'

const input = JSON.parse(readFileSync(0, 'utf8'))
const body = readFileSync(input.script, 'utf8').replace(/^export const meta\b/m, 'const meta')
const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor
const labels = []

async function agent(prompt, opts) {
  labels.push(opts.label)
  const statuses = opts.schema.properties.status.enum
  if (statuses.length === 0) throw new Error('the schema is unsatisfiable')
  const returns = input.returns[opts.label]?.shift()
  if (!returns) throw new Error(`no return scripted for ${opts.label}`)
  if (!statuses.includes(returns.status)) throw new Error(`${opts.label} may not return ${returns.status}`)
  return returns
}

const result = await new AsyncFunction('args', 'agent', 'log', body)(input.args, agent, () => {})
process.stdout.write(JSON.stringify({ labels, result }) + '\n')
```

- [ ] **Step 2: Write the failing test**: `skills/pipeline/checks/tests/AutoflowScriptTest.php`

The first two tests replay the smoke run's `a-done` and `b-gap-bound` stubs (the 2026-09-23 plan, Task 6, Step 4). Every `args` is a real `launch` answer.

```php
<?php

/** The autoflow script run on `$args` with agent() faked (`autoflow_replay.mjs`): `{labels, result}`. */
function autoflow_replay(array $args, array $returns): array
{
    $process = proc_open(['node', __DIR__ . '/autoflow_replay.mjs'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect($process)->toBeResource('AutoflowScriptTest needs node on PATH');
    fwrite($pipes[0], json_encode(['script' => __DIR__ . '/../../workflow/pipeline-autoflow.js', 'args' => $args, 'returns' => (object) $returns], JSON_UNESCAPED_SLASHES));
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0, "node failed: {$stderr}");

    return json_decode($stdout, true);
}

/** `launch`'s start answer for an autoflow run whose cursor is on `$leg`; `$spec` is the committed spec's text. */
function autoflow_start(string $leg, array $ledger = [], ?string $spec = null): array
{
    $artifacts = ['spec' => $spec === null ? null : 'spec.md', 'plan' => null, 'pr' => null, 'issue' => null];
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => $leg, 'status' => 'pending'], 'gate_ledger' => $ledger, 'artifacts' => $artifacts]);
    if ($spec !== null) {
        file_put_contents($fixture['dir'] . '/spec.md', $spec);
    }

    return dispatch_cli(['launch', $fixture['manifest'], $fixture['diff']])['json'];
}

const AUTOFLOW_C = ['status' => 'continued'];
const AUTOFLOW_LB = ['status' => 'looped-back'];
const AUTOFLOW_PI = ['status' => 'plan-insufficient', 'reason' => 'stub gap'];

it('walks to done on launch\'s tables, through a loop-back at each gate', function () {
    $design = [...AUTOFLOW_C, 'size' => 'Architectural'];
    $replay = autoflow_replay(autoflow_start('design'), [
        'design:run' => [$design, $design],
        'review-plan:review' => [AUTOFLOW_C, AUTOFLOW_C],
        'review-plan:resolve' => [AUTOFLOW_LB, AUTOFLOW_C],
        'handoff:run' => [AUTOFLOW_C],
        'implement:run' => [[...AUTOFLOW_C, 'ui' => true], [...AUTOFLOW_C, 'ui' => true], [...AUTOFLOW_C, 'ui' => false]],
        'verify-ui:run' => [AUTOFLOW_LB, AUTOFLOW_C],
        'review-pr:review' => [AUTOFLOW_C, AUTOFLOW_C],
        'review-pr:resolve' => [AUTOFLOW_LB, AUTOFLOW_C],
    ]);

    expect($replay['labels'])->toBe([
        'design:run', 'review-plan:review', 'review-plan:resolve', 'design:run', 'review-plan:review', 'review-plan:resolve',
        'handoff:run', 'implement:run', 'verify-ui:run', 'implement:run', 'verify-ui:run',
        'review-pr:review', 'review-pr:resolve', 'implement:run', 'review-pr:review', 'review-pr:resolve',
    ]);
    expect($replay['result'])->toBe(['action' => 'done']);
});

it('halts on launch\'s bound when plan gaps keep looping back to design', function () {
    $design = [...AUTOFLOW_C, 'size' => 'Architectural'];
    $replay = autoflow_replay(autoflow_start('implement'), [
        'implement:run' => [[...AUTOFLOW_PI, 'ui' => false], [...AUTOFLOW_PI, 'ui' => false]],
        'design:run' => [$design, $design],
        'review-plan:review' => [AUTOFLOW_C, AUTOFLOW_C],
        'review-plan:resolve' => [AUTOFLOW_LB, AUTOFLOW_C],
        'handoff:run' => [AUTOFLOW_C],
    ]);

    expect($replay['labels'])->toBe([
        'implement:run', 'design:run', 'review-plan:review', 'review-plan:resolve', 'design:run',
        'review-plan:review', 'review-plan:resolve', 'handoff:run', 'implement:run',
    ]);
    expect($replay['result'])->toBe(['action' => 'halt', 'leg' => 'implement', 'reason' => 'review-plan: loop-back bound exhausted']);
});

it('routes by the tables it is given, not by a copy of its own', function () {
    $start = autoflow_start('implement');
    $start['tables']['bound'] = 0;
    unset($start['loops']['verify-ui']);
    $unbounded = autoflow_replay($start, ['implement:run' => [[...AUTOFLOW_C, 'ui' => true]], 'verify-ui:run' => [AUTOFLOW_LB]]);

    expect($unbounded['result'])->toBe(['action' => 'halt', 'leg' => 'verify-ui', 'reason' => 'verify-ui: loop-back bound exhausted']);

    $start = autoflow_start('review-pr');
    $start['tables']['allowed']['review-pr:resolve'] = ['continued', 'halted'];
    $narrowed = autoflow_replay($start, ['review-pr:review' => [AUTOFLOW_C], 'review-pr:resolve' => [AUTOFLOW_LB]]);

    expect($narrowed['result'])->toBe(['action' => 'halt', 'leg' => 'review-pr', 'reason' => 'the agent failed: review-pr:resolve may not return looped-back']);
});

it('exempts one Bounded escalation and then counts on from the ledger\'s loop-backs', function () {
    $looped = fn (int $cycle) => ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => $cycle, 'at' => "2026-09-24T0{$cycle}:00:00Z", 'review' => 'r', 'outcome' => 'looped-back'];
    $start = autoflow_start('handoff', [$looped(1), $looped(2)], "# x — design\n\n**Design size:** Bounded\n");
    $replay = autoflow_replay($start, [
        'handoff:run' => [AUTOFLOW_PI],
        'design:run' => [[...AUTOFLOW_C, 'size' => 'Bounded']],
        'review-plan:review' => [AUTOFLOW_PI],
    ]);

    expect($start['loops']['review-plan'])->toBe(2);
    expect($replay['labels'])->toBe(['handoff:run', 'design:run', 'review-plan:review']);
    expect($replay['result'])->toBe(['action' => 'halt', 'leg' => 'review-plan', 'reason' => 'review-plan: loop-back bound exhausted']);
});

it('halts a start step its leg does not have, before any agent', function () {
    $replay = autoflow_replay([...autoflow_start('handoff'), 'startStep' => 'resolve'], []);

    expect($replay)->toBe(['labels' => [], 'result' => ['action' => 'halt', 'leg' => 'handoff', 'reason' => 'handoff has no resolve step']]);
});

it('halts before any agent when launch\'s tables are missing or incomplete', function (callable $break) {
    $replay = autoflow_replay($break(autoflow_start('handoff')), []);

    expect($replay['labels'])->toBe([]);
    expect($replay['result'])->toBe(['action' => 'halt', 'leg' => 'handoff', 'reason' => 'args carry no complete tables: re-run launch from checks that have pipeline_routing_tables()']);
})->with([
    'no tables' => [function (array $start) { unset($start['tables']); return $start; }],
    'a step without statuses' => [function (array $start) { unset($start['tables']['allowed']['handoff:run']); return $start; }],
    'a step with an empty status list' => [function (array $start) { $start['tables']['allowed']['handoff:run'] = []; return $start; }],
    'a leg without steps' => [function (array $start) { unset($start['tables']['steps']['verify-ui']); return $start; }],
    'a loop-back to no leg' => [function (array $start) { $start['tables']['loopTarget']['review-pr'] = 'nowhere'; return $start; }],
    'a bound that is not a number' => [function (array $start) { $start['tables']['bound'] = '2'; return $start; }],
]);
```

- [ ] **Step 3: Run it to see it fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter=AutoflowScriptTest`

Expected: 7 failed, 4 passed. The failures are `routes by the tables it is given` (today's script ignores `tables.bound`) and all six `missing or incomplete` cases (today's script never looks at `tables`). The four that pass are `walks to done`, `halts on launch's bound`, `exempts one Bounded escalation` and `halts a start step`, whose routing this task does not change.

- [ ] **Step 4: Make the script read `args.tables`**: `skills/pipeline/workflow/pipeline-autoflow.js`

Replace the comment and the five tables (from `// Repeated from pipeline.php` through `const BOUND = 2`) with this comment. Keep `COPIED` and `UNSATISFIABLE` as they are:

```js
// The routing tables are launch's: `tables` in its start answer, built by pipeline_routing_tables() from
// the functions interactive mode uses. meta.phases repeats the legs as labels only (meta must be a pure
// literal). AutoflowScriptTest replays this script on launch's answer with agent() faked.
```

Delete these four lines, which sit after `UNSATISFIABLE`. They come back after the guard in this step:

```js
const loops = { 'review-plan': 0, 'verify-ui': 0, 'review-pr': 0, ...args.loops }
let ui = args.ui
let size = args.size
let exempted = false
```

Put `complete()` in their place, directly above `nextLeg()`, and make `nextLeg()` read `legs`:

```js
function complete(tables) {
  const { legs, steps, loopTarget, allowed, bound } = tables ?? {}
  const filled = list => Array.isArray(list) && list.length > 0
  return filled(legs)
    && legs.every(leg => filled(steps?.[leg]) && steps[leg].every(step => filled(allowed?.[`${leg}:${step}`])))
    && typeof loopTarget === 'object' && loopTarget !== null && Object.entries(loopTarget).every(([from, to]) => legs.includes(from) && legs.includes(to))
    && Number.isInteger(bound)
}

function nextLeg(leg) {
  return legs.slice(legs.indexOf(leg) + 1).find(next => next !== 'verify-ui' || ui)
}
```

In `schemaFor()`, the status enum has no fallback key:

```js
    status: { type: 'string', enum: allowed[`${leg}:${step}`] },
```

Replace the two guard lines, `if (args.action !== 'start' || !LEGS.includes(…` and `if (args.startStep && !(STEPS[…`, and the `let leg = args.startLeg` line after them, with:

```js
if (args.action !== 'start') return halt(args.startLeg ?? 'launch', 'args are not a launch start answer')
if (!complete(args.tables)) return halt(args.startLeg ?? 'launch', 'args carry no complete tables: re-run launch from checks that have pipeline_routing_tables()')
const { legs, steps, loopTarget, allowed, bound } = args.tables
if (!legs.includes(args.startLeg)) return halt(args.startLeg ?? 'launch', 'args are not a launch start answer')
if (args.startStep && !steps[args.startLeg].includes(args.startStep)) return halt(args.startLeg, `${args.startLeg} has no ${args.startStep} step`)

const loops = { ...Object.fromEntries(Object.keys(loopTarget).map(gate => [gate, 0])), ...args.loops }
let ui = args.ui
let size = args.size
let exempted = false
let leg = args.startLeg
```

In the `while (leg)` loop, the step list reads `steps` and its local no longer shadows it:

```js
  const all = steps[leg]
  const remaining = from ? all.slice(all.indexOf(from)) : all
  from = undefined
  let result
  for (const step of remaining) {
```

Change `LOOP_TARGET[leg]` to `loopTarget[leg]`, and `> BOUND)` to `> bound)`.

Check that nothing of the old tables is left:

Run: `grep -nE 'LEGS|STEPS|LOOP_TARGET|ALLOWED|BOUND' skills/pipeline/workflow/pipeline-autoflow.js`

Expected: no output.

- [ ] **Step 5: Drop `LockStepTest`'s JS half**

In `skills/pipeline/checks/tests/LockStepTest.php`, delete the whole third test, `it('keeps the autoflow script in lock-step with the legs, loop-backs, statuses and bound it repeats', …)`. After today's script change it fails on `const LEGS = [`. Keep `lockstep_section()` and the `manifest.md` and `gates.md` tests.

- [ ] **Step 6: Run the suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`

Expected: green, 261 passed.

- [ ] **Step 7: Commit**

```bash
git add skills/pipeline/workflow/pipeline-autoflow.js skills/pipeline/checks/tests/autoflow_replay.mjs skills/pipeline/checks/tests/AutoflowScriptTest.php skills/pipeline/checks/tests/LockStepTest.php
git commit -m "pipeline-autoflow: route by launch's tables; a replay test replaces the script's lock-step match (#71)"
```

---

### Task 3: Docs, the smoke pass, all suites

**Files:**
- Modify: `skills/pipeline/references/engine.md` (§`autoflow` — a program that calls agents; §A plan gap on an Architectural design)
- Modify: `skills/pipeline/references/gates.md` (§Loop-backs)
- Modify: `README.md` (§Bootstrapping a new machine)

- [ ] **Step 1: `engine.md` §`autoflow`**

In the command block, the `launch` answer line becomes:

```
# → {"action":"start","startLeg":…,"startStep":…,"loops":{…},"ui":…,"size":…,"manifest":…,"worktree":…,"noOpen":…,"checks":…,"tables":{…}}
```

In the **`launch`** bullet, after the sentence ending `so every step's `brief` runs the same code.`, add:

```markdown
  `tables` is what the script routes by, `pipeline_routing_tables()`: the legs in order, each leg's
  steps, the loop-back targets, the statuses per `<leg>:<step>` and the bound, built from the
  functions `auto` and `interactive` route by, so the script keeps no copy of them.
```

In the **The script** bullet, replace

```markdown
- **The script** gives each step a schema whose `status` allows only what that step may return
  (`LegStatus::allowedFor()`), continues, loops back or returns on that status, counts each loop-back
  against the bound of 2 per gate (`gates.md` §Loop-backs), and returns `{action: done}` or
```

with

```markdown
- **The script** gives each step a schema whose `status` allows only what that step may return
  (`tables.allowed`, from `LegStatus::allowedFor()`), continues, loops back or returns on that status,
  counts each loop-back against `tables.bound`, 2 per gate (`gates.md` §Loop-backs), and returns `{action: done}` or
```

In the same bullet, replace the sentence `A status it cannot route halts, and so do `args` that are not a `launch` `start` answer.` (it wraps across two lines) with:

```markdown
A status it cannot route halts, and so do `args` that are not a `launch` `start` answer; `tables`
missing or incomplete halts with a reason that names them. `AutoflowScriptTest` replays the script
on `launch`'s answer.
```

- [ ] **Step 2: `engine.md` §A plan gap on an Architectural design**

Replace `the script's `BOUND` in `autoflow`` with `` `tables.bound` in `autoflow` ``.

Check that no stale name is left:

Run: `grep -nE "script's .BOUND|LOOP_TARGET|LockStepTest fails when either" skills/pipeline/references/*.md`

Expected: only `gates.md` §Loop-backs lines, which Step 3 changes.

- [ ] **Step 3: `gates.md` §Loop-backs**

Replace

```markdown
`interactive` `pipeline_returned()` evaluates both; in `autoflow` the workflow script does
(`LOOP_TARGET` and `BOUND` in `../workflow/pipeline-autoflow.js`, starting from `launch`'s ledger
counts). Keep this list in lock-step with the function and the script: `LockStepTest` fails when
either drifts from the function.
```

with

```markdown
`interactive` `pipeline_returned()` evaluates both; in `autoflow` the workflow script does, on
`tables.loopTarget` and `tables.bound` from `launch`'s `start` answer (`pipeline_routing_tables()`),
starting from `launch`'s ledger counts. Keep this list in lock-step with the function: `LockStepTest`
fails when it drifts.
```

Run: `grep -rnE 'LOOP_TARGET|const BOUND|script.s .BOUND' skills/pipeline skills/orchestrate`

Expected: no output.

- [ ] **Step 4: `README.md` §Bootstrapping a new machine**

After the paragraph that ends with the `git-freshness.sh` modes list (the `checkout` bullet), before `## Linking the skills`, add:

```markdown
The pipeline skill's suite needs `node` on PATH: `AutoflowScriptTest` replays the autoflow Workflow
script under it, and fails rather than skips without it, so a machine without `node` has a red suite.
```

- [ ] **Step 5: Run every suite**

Run each of the three suites under *Global Constraints*.

Expected: the pipeline suite green with 261 passed, the critique suite green, and `owners_test.sh` passing.

- [ ] **Step 6: The Workflow smoke pass, when this session has the Workflow tool**

With the Workflow tool: follow the 2026-09-23 plan, Task 6, Steps 3 to 5, for `a-done` and `b-gap-bound` only. Use this worktree's `skills/pipeline/workflow/pipeline-autoflow.js` as `scriptPath`, and `"mode":"autoflow"` in the scenario manifests. The `launch` lines now carry `tables`; pass them unchanged as `args` with the `stub` key. Record the labels, the return and the cursor after `finish` for both, for the PR body.

Without the Workflow tool (an `autoflow` implement step cannot start agents, so it cannot start a Workflow): do not halt. Record for the PR body: *"Workflow smoke pass not run (no Workflow tool in the implementing session): `a-done` and `b-gap-bound` are replayed by `AutoflowScriptTest`; the Workflow run of both is left for review."*

- [ ] **Step 7: Commit**

```bash
git add skills/pipeline/references/engine.md skills/pipeline/references/gates.md README.md
git commit -m "pipeline docs: autoflow routes by launch's tables; AutoflowScriptTest replaces the script's lock-step match (#71)"
```
