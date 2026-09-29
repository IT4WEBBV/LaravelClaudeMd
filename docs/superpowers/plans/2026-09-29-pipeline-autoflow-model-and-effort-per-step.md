# `autoflow` runs every step on an explicit model and effort, from one table — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task by task. Steps use checkbox (`- [ ]`) syntax for tracking. In an `autoflow` implement step, execute inline, task by task, with no subagents.

**Goal:** Every `autoflow` step's agent runs on a model and effort from one PHP table (`full` and `light` profiles, a loop-back entry for `implement`, a retry and a smoke entry), handed to the workflow script by `launch`, overridable per step from the manifest; and `run_cost_cli.php` weighs each call by its model (#51).

**Architecture:**
- `skills/pipeline/checks/agents.php` (new): `PIPELINE_AGENTS`, `pipeline_agent_steps()`, `pipeline_agent_table()`, `pipeline_agent_override_problem()`, `pipeline_start_profile()`; `DesignSize::profile()` in `design_size.php` (Task 1).
- `skills/pipeline/checks/dispatch_cli.php`: `launch` halts on an invalid override and answers `profile` and `agents` (Task 2).
- `skills/pipeline/workflow/pipeline-autoflow.js`: `settingFor()`, the retry on `agents.retry`, the smoke entry, the agents check, `profile` kept current; the replay records each call's model and effort (Task 3).
- `skills/pipeline/checks/run_cost.php`: `PIPELINE_MODEL_FACTORS`, the family per call, the step line naming families (Task 4).
- Docs: engine.md §Agents per step and §`autoflow`, §Failure policy; manifest.md; SKILL.md; `LockStepTest` pins the table (Task 5).

**Tech Stack:** PHP 8.4 on the host, Pest 4, Node (the script replay), Markdown skill references.

**Spec:** `docs/superpowers/specs/2026-09-29-pipeline-autoflow-model-and-effort-per-step-design.md`

## Global Constraints

- Suite, from the worktree root on the host: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`. `vendor/` is missing in a fresh worktree: run `composer install --no-interaction --quiet` first. `AutoflowScriptTest` needs `node` on PATH.
- Models are `opus`, `sonnet`, `fable`; efforts are `low`, `medium`, `high`, `xhigh`, `max`. The script (`pipeline-autoflow.js`) contains none of these, nor `haiku`, as a quoted literal (Task 3's `LockStepTest` case).
- The agents-table halt, verbatim (Task 3): `args carry no complete agents table: re-run launch from checks that have pipeline_agent_table()`.
- The override halt, verbatim prefix (Task 2): `the manifest's agents override is invalid: <what>`, `<what>` being `pipeline_agent_override_problem()`'s answer (Task 1).
- The routing tables, statuses, the bound, the ledger shape, the briefs, `pipeline_leg_writable_keys()`, `interactive`, `run_audit.php` and the wall-time measurement do not change.
- engine.md `## ` headings do not change except the new `## Agents per step — one table, explicit model and effort`: `LockStepTest` resolves the briefs' `§` names against them.
- This repo has no `.changelog/` and no `CHANGELOG.md`: no changelog entry. The PR closes #51.
- The PR body carries the spec's *A run in flight when this lands*: a running workflow keeps its script and args; the new script over an older `launch` answer halts before any agent with the agents-table reason; a relaunch fixes it.

## Review Focus

1. **A Bounded escalation mid-run** must move the grow-form `design:spec` and every later step to `full`, and a resume after it must start on `full` although the spec may still say Bounded. Task 3's dataset *a Bounded escalation* pins the script; Task 1's `pipeline_start_profile()` dataset and Task 2's launch case pin the resume.
2. **A resumed run whose ledger already counts a `verify-ui` or `review-pr` loop-back** starts `implement:run` on `opus xhigh`; a plan gap (charged to `review-plan`, which loops to `design`) does not. Task 3's resume dataset pins all three.
3. **The new script on an older `launch` answer** (no `agents`, no `profile`, or a table missing a step) halts before any agent instead of running on `undefined` model and effort, which `agent()` would read as "inherit". Task 3's incomplete-agents dataset pins it.
4. **A hand-written override with a typo** (`design:run`, `review:plan`, `haiku`, `extreme`, `{}`) halts `launch` without writing the manifest, even with `--decision` and on a finished run. Task 1's problem dataset and Task 2's halt cases pin it.
5. **A step transcript with `<synthetic>` messages or no model** adds no family to the step line and weighs as Opus, so existing figures keep their numbers. Task 4's cases pin it.

---

## File Structure

- Create `skills/pipeline/checks/agents.php` (Task 1) and `skills/pipeline/checks/tests/AgentsTest.php` (Task 1).
- Modify `skills/pipeline/checks/design_size.php` and `tests/DesignSizeTest.php` (Task 1), `tests/Pest.php` (Task 1), `skills/pipeline/checks/dispatch_cli.php` and `tests/DispatchCliTest.php` (Task 2), `skills/pipeline/workflow/pipeline-autoflow.js`, `tests/autoflow_replay.mjs`, `tests/AutoflowScriptTest.php` and `tests/LockStepTest.php` (Task 3), `skills/pipeline/checks/run_cost.php` and `tests/RunCostTest.php` (Task 4), `skills/pipeline/references/engine.md`, `skills/pipeline/references/manifest.md`, `skills/pipeline/SKILL.md` and `tests/LockStepTest.php` (Task 5).

---

### Task 1: the table, the override and the start profile — `agents.php`

**Files:**
- Create: `skills/pipeline/checks/agents.php`
- Modify: `skills/pipeline/checks/design_size.php` (`DesignSize::profile()`), `skills/pipeline/checks/tests/Pest.php` (load `agents.php`)
- Test: `skills/pipeline/checks/tests/AgentsTest.php` (new), `skills/pipeline/checks/tests/DesignSizeTest.php`

**Interfaces:**
- Consumes: `pipeline_legs()` (`pipeline.php`), `pipeline_steps(string $leg, string $mode): array` (`dispatch.php`), `pipeline_ledger(array $manifest): array` (`manifest.php`), `DesignSize` (`design_size.php`).
- Produces:
  - `const PIPELINE_AGENT_MODELS = ['opus', 'sonnet', 'fable']`, `const PIPELINE_AGENT_EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max']`, `const PIPELINE_AGENTS` (shape `{full, light, loopedBack, retry, smoke}`, each entry `['model' => string, 'effort' => string]`).
  - `pipeline_agent_steps(): array` — every `autoflow` `<leg>:<step>`, in leg order.
  - `pipeline_agent_table(array $override): array` — the script's `agents`.
  - `pipeline_agent_override_problem(mixed $override): ?string` — what is wrong with an override, or null.
  - `pipeline_start_profile(array $manifest, DesignSize $size): string` — `'full'` or `'light'`.
  - `DesignSize::profile(): string` — `Bounded` → `'light'`, `Architectural` → `'full'`.

- [ ] **Step 1: Write the failing tests**

Add to the end of `skills/pipeline/checks/tests/DesignSizeTest.php`:

```php
it('names the agents profile a size runs on', function () {
    expect(DesignSize::Bounded->profile())->toBe('light');
    expect(DesignSize::Architectural->profile())->toBe('full');
});
```

Create `skills/pipeline/checks/tests/AgentsTest.php`:

```php
<?php

/** A profile as `model effort` per step, the way the docs and the replay write it. */
function agents_settings(array $profile): array
{
    return array_map(fn (array $entry) => "{$entry['model']} {$entry['effort']}", $profile);
}

it('holds an explicit model and effort for every autoflow step in both profiles', function () {
    $table = pipeline_agent_table([]);

    expect(agents_settings($table['full']))->toBe([
        'design:spec' => 'opus high',
        'design:plan' => 'opus high',
        'review-plan:review' => 'fable high',
        'review-plan:resolve' => 'opus high',
        'handoff:run' => 'sonnet low',
        'implement:run' => 'opus high',
        'verify-ui:run' => 'sonnet high',
        'review-pr:review' => 'fable high',
        'review-pr:resolve' => 'opus high',
    ]);
    expect(agents_settings($table['light']))->toBe([
        'design:spec' => 'opus medium',
        'design:plan' => 'opus medium',
        'review-plan:review' => 'fable medium',
        'review-plan:resolve' => 'opus medium',
        'handoff:run' => 'sonnet low',
        'implement:run' => 'opus high',
        'verify-ui:run' => 'sonnet medium',
        'review-pr:review' => 'fable high',
        'review-pr:resolve' => 'opus medium',
    ]);
    expect(agents_settings($table['loopedBack']))->toBe(['implement:run' => 'opus xhigh']);
    expect($table['retry'])->toBe(['model' => 'opus', 'effort' => 'xhigh']);
    expect($table['smoke'])->toBe(['model' => 'sonnet', 'effort' => 'low']);
    expect(array_keys($table['full']))->toBe(pipeline_agent_steps());
});

it('lists every autoflow step, in leg order', function () {
    expect(pipeline_agent_steps())->toBe([
        'design:spec', 'design:plan', 'review-plan:review', 'review-plan:resolve', 'handoff:run',
        'implement:run', 'verify-ui:run', 'review-pr:review', 'review-pr:resolve',
    ]);
});

it('lays an override over the step it names in both profiles and its loop-back entry, and leaves the rest', function () {
    $override = ['implement:run' => ['model' => 'sonnet', 'effort' => 'max'], 'review-plan:review' => ['effort' => 'low']];
    $table = pipeline_agent_table($override);
    $default = pipeline_agent_table([]);
    $others = fn (array $profile) => array_diff_key($profile, $override);

    foreach (['full', 'light', 'loopedBack'] as $profile) {
        expect($table[$profile]['implement:run'])->toBe(['model' => 'sonnet', 'effort' => 'max']);
    }
    expect($table['full']['review-plan:review'])->toBe(['model' => 'fable', 'effort' => 'low']);
    expect($table['light']['review-plan:review'])->toBe(['model' => 'fable', 'effort' => 'low']);
    expect($others($table['full']))->toBe($others($default['full']));
    expect($others($table['light']))->toBe($others($default['light']));
    expect(array_keys($table['loopedBack']))->toBe(['implement:run']);
    expect($table['retry'])->toBe($default['retry']);
    expect($table['smoke'])->toBe($default['smoke']);
});

it('accepts no override and an override of one field', function (mixed $override) {
    expect(pipeline_agent_override_problem($override))->toBeNull();
})->with([
    'none' => [[]],
    'one step, one field' => [['review-pr:resolve' => ['effort' => 'medium']]],
    'two steps, both fields' => [['design:spec' => ['model' => 'fable', 'effort' => 'max'], 'handoff:run' => ['model' => 'opus', 'effort' => 'low']]],
]);

it('names what is wrong with an override', function (mixed $override, string $problem) {
    expect(pipeline_agent_override_problem($override))->toBe($problem);
})->with([
    'a string' => ['opus', 'it is not an object'],
    'a list' => [[['model' => 'opus']], 'it is not an object'],
    'an interactive step' => [['design:run' => ['effort' => 'low']], '`design:run` is not an autoflow step'],
    'a step the pipeline does not have' => [['review:plan' => ['effort' => 'low']], '`review:plan` is not an autoflow step'],
    'an entry with no field' => [['handoff:run' => []], '`handoff:run` names no model or effort'],
    'an entry that is a string' => [['handoff:run' => 'opus'], '`handoff:run` names no model or effort'],
    'an unknown field' => [['handoff:run' => ['model' => 'opus', 'thinking' => 'on']], '`handoff:run` has an unknown field `thinking`'],
    'a model outside the three' => [['handoff:run' => ['model' => 'haiku']], '`handoff:run` names model "haiku", not one of opus, sonnet, fable'],
    'an effort outside the levels' => [['handoff:run' => ['effort' => 'extreme']], '`handoff:run` names effort "extreme", not one of low, medium, high, xhigh, max'],
    'an effort that is not a string' => [['handoff:run' => ['effort' => 3]], '`handoff:run` names effort 3, not one of low, medium, high, xhigh, max'],
]);

it('starts a run on full after an escalation, on the spec\'s size once a spec exists, and on the light flag before', function (array $manifest, DesignSize $size, string $profile) {
    expect(pipeline_start_profile($manifest, $size))->toBe($profile);
})->with(function () {
    $spec = ['artifacts' => ['spec' => 'spec.md']];
    $escalated = ['gate_ledger' => [['gate' => 'design-size', 'leg' => 'handoff', 'at' => '2026-09-29T10:00:00Z', 'reason' => 'migration', 'outcome' => 'escalated']]];

    return [
        'no spec, no light flag' => [[], DesignSize::Architectural, 'full'],
        'no spec, the light flag' => [['light' => true], DesignSize::Architectural, 'light'],
        'a Bounded spec without the light flag' => [$spec, DesignSize::Bounded, 'light'],
        'an Architectural spec with the light flag' => [[...$spec, 'light' => true], DesignSize::Architectural, 'full'],
        'an escalation over a Bounded spec and the light flag' => [[...$spec, ...$escalated, 'light' => true], DesignSize::Bounded, 'full'],
        'an escalation before a spec, with the light flag' => [[...$escalated, 'light' => true], DesignSize::Architectural, 'full'],
    ];
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='AgentsTest|DesignSizeTest'`
Expected: FAIL — `Call to undefined function pipeline_agent_table()` (and the other new functions), `Call to undefined method DesignSize::profile()`.

- [ ] **Step 3: Write the implementation**

In `skills/pipeline/checks/design_size.php`, add after `fromSpec()`:

```php
    /** The agents profile a design of this size runs on (`../references/engine.md` §Agents per step). */
    public function profile(): string
    {
        return match ($this) {
            self::Bounded => 'light',
            self::Architectural => 'full',
        };
    }
```

Create `skills/pipeline/checks/agents.php`:

```php
<?php

/**
 * Which model and effort each `autoflow` step's agent runs on (`../references/engine.md` §Agents per
 * step): one table, handed to the workflow script in `launch`'s `start` answer, so the script names no
 * model or effort and nothing inherits the session's settings. Models are `agent()`'s aliases, efforts
 * its levels. Pure: `dispatch_cli.php` reads the manifest.
 */

const PIPELINE_AGENT_MODELS = ['opus', 'sonnet', 'fable'];

const PIPELINE_AGENT_EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

/** `full` and `light` hold every step; `loopedBack` replaces a step's entry once a gate looped back to its leg; `retry` reruns a review that returned nothing; `smoke` runs a smoke run's stubs. */
const PIPELINE_AGENTS = [
    'full' => [
        'design:spec' => ['model' => 'opus', 'effort' => 'high'],
        'design:plan' => ['model' => 'opus', 'effort' => 'high'],
        'review-plan:review' => ['model' => 'fable', 'effort' => 'high'],
        'review-plan:resolve' => ['model' => 'opus', 'effort' => 'high'],
        'handoff:run' => ['model' => 'sonnet', 'effort' => 'low'],
        'implement:run' => ['model' => 'opus', 'effort' => 'high'],
        'verify-ui:run' => ['model' => 'sonnet', 'effort' => 'high'],
        'review-pr:review' => ['model' => 'fable', 'effort' => 'high'],
        'review-pr:resolve' => ['model' => 'opus', 'effort' => 'high'],
    ],
    'light' => [
        'design:spec' => ['model' => 'opus', 'effort' => 'medium'],
        'design:plan' => ['model' => 'opus', 'effort' => 'medium'],
        'review-plan:review' => ['model' => 'fable', 'effort' => 'medium'],
        'review-plan:resolve' => ['model' => 'opus', 'effort' => 'medium'],
        'handoff:run' => ['model' => 'sonnet', 'effort' => 'low'],
        'implement:run' => ['model' => 'opus', 'effort' => 'high'],
        'verify-ui:run' => ['model' => 'sonnet', 'effort' => 'medium'],
        'review-pr:review' => ['model' => 'fable', 'effort' => 'high'],
        'review-pr:resolve' => ['model' => 'opus', 'effort' => 'medium'],
    ],
    'loopedBack' => [
        'implement:run' => ['model' => 'opus', 'effort' => 'xhigh'],
    ],
    'retry' => ['model' => 'opus', 'effort' => 'xhigh'],
    'smoke' => ['model' => 'sonnet', 'effort' => 'low'],
];

/** @return list<string> every `<leg>:<step>` of an `autoflow` run, in leg order */
function pipeline_agent_steps(): array
{
    return array_merge(...array_map(
        fn (string $leg) => array_map(fn (string $step) => "{$leg}:{$step}", pipeline_steps($leg, 'autoflow')),
        pipeline_legs(),
    ));
}

/** The script's `agents`: the table, with each step the manifest's override names laid over its entry in both profiles and its loop-back entry. */
function pipeline_agent_table(array $override): array
{
    $table = PIPELINE_AGENTS;
    foreach ($override as $step => $fields) {
        foreach (['full', 'light', 'loopedBack'] as $profile) {
            if (isset($table[$profile][$step])) {
                $table[$profile][$step] = [...$table[$profile][$step], ...$fields];
            }
        }
    }

    return $table;
}

/** What is wrong with the manifest's `agents` override, or null: an object of `autoflow` steps, each naming a model, an effort or both. */
function pipeline_agent_override_problem(mixed $override): ?string
{
    if (! is_array($override) || ($override !== [] && array_is_list($override))) {
        return 'it is not an object';
    }
    foreach ($override as $step => $entry) {
        $problem = in_array($step, pipeline_agent_steps(), true)
            ? pipeline_agent_entry_problem($step, $entry)
            : "`{$step}` is not an autoflow step";
        if ($problem !== null) {
            return $problem;
        }
    }

    return null;
}

function pipeline_agent_entry_problem(string $step, mixed $entry): ?string
{
    if (! is_array($entry) || array_is_list($entry)) {
        return "`{$step}` names no model or effort";
    }
    $unknown = array_diff(array_keys($entry), ['model', 'effort']);
    $outside = fn (string $field, array $allowed) => array_key_exists($field, $entry) && ! in_array($entry[$field], $allowed, true);

    return match (true) {
        $unknown !== [] => "`{$step}` has an unknown field `" . reset($unknown) . '`',
        $outside('model', PIPELINE_AGENT_MODELS) => "`{$step}` names model " . json_encode($entry['model']) . ', not one of ' . implode(', ', PIPELINE_AGENT_MODELS),
        $outside('effort', PIPELINE_AGENT_EFFORTS) => "`{$step}` names effort " . json_encode($entry['effort']) . ', not one of ' . implode(', ', PIPELINE_AGENT_EFFORTS),
        default => null,
    };
}

/**
 * The profile a run starts on, which the script keeps current from there: `full` once the ledger records
 * an escalation (one way, once per run); else the spec's size once a spec exists; else the `light` flag.
 * `$size` is `dispatch_cli_design_size($manifest)`, which answers Architectural for no spec as well.
 */
function pipeline_start_profile(array $manifest, DesignSize $size): string
{
    return match (true) {
        in_array('escalated', array_column(pipeline_ledger($manifest), 'outcome'), true) => 'full',
        ! empty($manifest['artifacts']['spec']) => $size->profile(),
        default => empty($manifest['light']) ? 'full' : 'light',
    };
}
```

In `skills/pipeline/checks/tests/Pest.php`, add `'agents.php'` to the list, right after `'dispatch.php'`:

```php
foreach (['triggers.php', 'pipeline.php', 'manifest.php', 'checks.php', 'board.php', 'proof.php', 'proof_render.php', 'design_size.php', 'suite.php', 'dispatch.php', 'agents.php', 'brief.php', 'run_cost.php', 'kickoff.php', 'ci.php', 'statusline.php'] as $f) {
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php -l skills/pipeline/checks/agents.php && ./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='AgentsTest|DesignSizeTest'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/agents.php skills/pipeline/checks/design_size.php skills/pipeline/checks/tests/AgentsTest.php skills/pipeline/checks/tests/DesignSizeTest.php skills/pipeline/checks/tests/Pest.php
git commit -m "feat(pipeline): one table of model and effort per autoflow step, with a manifest override (#51)"
```

---

### Task 2: `launch` answers `profile` and `agents`, and halts on an invalid override

**Files:**
- Modify: `skills/pipeline/checks/dispatch_cli.php` (require `agents.php`; `dispatch_cli_launch()`; new `dispatch_cli_agents_problem()`)
- Test: `skills/pipeline/checks/tests/DispatchCliTest.php`

**Interfaces:**
- Consumes: `pipeline_agent_table(array $override): array`, `pipeline_agent_override_problem(mixed $override): ?string`, `pipeline_start_profile(array $manifest, DesignSize $size): string` (Task 1).
- Produces: `launch`'s `start` answer gains, after `tables`, `'profile' => 'full'|'light'` and `'agents' => pipeline_agent_table($manifest['agents'] ?? [])` (Task 3's script reads both); `dispatch_cli_agents_problem(array $manifest): ?string`.

- [ ] **Step 1: Write the failing tests**

In `DispatchCliTest.php`, in *launches from the cursor with the ledger's loop-backs, the design size and ui*, the expected answer gains two keys after `'tables' => pipeline_routing_tables(),` (the fixture's spec is Bounded, so the run starts on `light`):

```php
        'tables' => pipeline_routing_tables(),
        'profile' => 'light',
        'agents' => pipeline_agent_table([]),
```

Add after *hands the autoflow script its routing tables, from the functions interactive mode routes by*:

```php
it('hands the script its agents with the manifest\'s override laid over them, and the profile to start on', function () {
    $override = ['review-plan:review' => ['model' => 'opus', 'effort' => 'xhigh']];
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'light' => true, 'agents' => $override]);
    $start = dispatch_cli(['launch', $fixture['manifest'], $fixture['diff']])['json'];

    expect($start['agents'])->toBe(pipeline_agent_table($override));
    expect($start['agents']['full']['review-plan:review'])->toBe(['model' => 'opus', 'effort' => 'xhigh']);
    expect($start['profile'])->toBe('light');
});

it('starts a run on full once its ledger records an escalation, whatever the spec and the light flag say', function () {
    $escalated = ['gate' => 'design-size', 'leg' => 'handoff', 'at' => '2026-09-29T10:00:00Z', 'reason' => 'migration', 'outcome' => 'escalated'];
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'light' => true, 'cursor' => ['leg' => 'design', 'status' => 'pending'], 'gate_ledger' => [$escalated], 'artifacts' => ['spec' => 'spec.md', 'plan' => 'plan.md', 'pr' => null, 'issue' => null]]);
    file_put_contents($fixture['dir'] . '/spec.md', "# x — design\n\n**Design size:** Bounded\n");

    $start = dispatch_cli(['launch', $fixture['manifest'], $fixture['diff']])['json'];

    expect($start['size'])->toBe('Bounded');
    expect($start['profile'])->toBe('full');
});

it('halts a launch whose agents override is invalid, and leaves the manifest as it was', function (array $manifest, mixed $agents, string $what) {
    $fixture = dispatch_fixture(['mode' => 'autoflow', ...$manifest, 'agents' => $agents]);
    $before = file_get_contents($fixture['manifest']);

    expect(dispatch_cli(['launch', $fixture['manifest'], $fixture['diff'], '--decision', 'Keep the guard'])['json'])
        ->toBe(['action' => 'halt', 'reason' => "the manifest's agents override is invalid: {$what}"]);
    expect(file_get_contents($fixture['manifest']))->toBe($before);
})->with([
    'a step autoflow does not have' => [[], ['design:run' => ['effort' => 'low']], '`design:run` is not an autoflow step'],
    'a model outside the three' => [[], ['handoff:run' => ['model' => 'haiku']], '`handoff:run` names model "haiku", not one of opus, sonnet, fable'],
    'a list, on a finished run' => [['cursor' => ['leg' => 'review-pr', 'status' => 'done']], [['model' => 'opus']], 'it is not an object'],
]);
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='DispatchCliTest'`
Expected: FAIL — the start answer has no `profile` or `agents` (`Undefined array key "agents"` / a `toBe` diff), and the invalid overrides answer `start` or `done` instead of the halt.

- [ ] **Step 3: Write the implementation**

In `skills/pipeline/checks/dispatch_cli.php`, add after `require_once __DIR__ . '/dispatch.php';`:

```php
require_once __DIR__ . '/agents.php';
```

In `dispatch_cli_launch()`, extend the first manifest check:

```php
    $problem = dispatch_cli_invalid($manifest)
        ?? dispatch_cli_mode_problem('launch starts autoflow runs', $manifest)
        ?? dispatch_cli_agents_problem($manifest);
```

and replace the return's `'size'` line and the end of the answer, reading the size once:

```php
    $size = dispatch_cli_design_size($manifest);

    return [
        'action' => 'start',
        'startLeg' => $leg,
        'startStep' => pipeline_step($manifest, $leg),
        'loops' => pipeline_loop_counts(pipeline_ledger($manifest)),
        'ui' => $triggers['ui'],
        'size' => $size->value,
        'manifest' => $manifestPath,
        'worktree' => $manifest['worktree'],
        'noOpen' => ! in_array((string) getenv('PIPELINE_NO_OPEN'), ['', '0'], true),
        'checks' => __DIR__,
        'tables' => pipeline_routing_tables(),
        'profile' => pipeline_start_profile($manifest, $size),
        'agents' => pipeline_agent_table($manifest['agents'] ?? []),
    ];
```

Add after `dispatch_cli_mode_problem()`:

```php
/** A hand-set `agents` override `launch` cannot hand to the script (`../references/engine.md` §Agents per step), or null. */
function dispatch_cli_agents_problem(array $manifest): ?string
{
    $problem = pipeline_agent_override_problem($manifest['agents'] ?? []);

    return $problem === null ? null : "the manifest's agents override is invalid: {$problem}";
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php -l skills/pipeline/checks/dispatch_cli.php && ./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='DispatchCliTest|AutoflowScriptTest'`
Expected: PASS (`AutoflowScriptTest` still passes: the script ignores the new keys until Task 3).

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/dispatch_cli.php skills/pipeline/checks/tests/DispatchCliTest.php
git commit -m "feat(pipeline): launch hands the autoflow script its agents and start profile (#51)"
```

---

### Task 3: the script runs every step on the table it is given

**Files:**
- Modify: `skills/pipeline/workflow/pipeline-autoflow.js`, `skills/pipeline/checks/tests/autoflow_replay.mjs`
- Test: `skills/pipeline/checks/tests/AutoflowScriptTest.php`, `skills/pipeline/checks/tests/LockStepTest.php`

**Interfaces:**
- Consumes: `args.agents` (`{full, light, loopedBack, retry, smoke}`) and `args.profile` from Task 2's `start` answer; `PIPELINE_AGENT_MODELS`, `PIPELINE_AGENT_EFFORTS` (Task 1) in `LockStepTest`.
- Produces: every `agent()` call carries `model` and `effort`; the replay prints `{labels, prompts, settings, result}`, `settings` being `"<model> <effort>"` per call in call order.

- [ ] **Step 1: Make the replay record each call's model and effort**

In `autoflow_replay.mjs`, change the header comment's last sentence to *Prints {labels, prompts, settings, result}: the agent labels, prompts and `<model> <effort>` in call order and what the script returned.*, and:

```js
const labels = []
const prompts = []
const settings = []
```

```js
async function agent(prompt, opts) {
  labels.push(opts.label)
  prompts.push(prompt)
  settings.push(`${opts.model} ${opts.effort}`)
```

```js
process.stdout.write(JSON.stringify({ labels, prompts, settings, result }) + '\n')
```

In `AutoflowScriptTest.php`, the two tests that compare the whole replay gain `'settings' => []` between `prompts` and `result`:

```php
    expect($replay)->toBe(['labels' => [], 'prompts' => [], 'settings' => [], 'result' => ['action' => 'halt', 'leg' => 'handoff', 'reason' => 'handoff has no resolve step']]);
```

```php
    expect(autoflow_replay($start, []))->toBe(['labels' => [], 'prompts' => [], 'settings' => [], 'result' => ['action' => 'halt', 'leg' => 'implement', 'reason' => 'args are not a launch start answer']]);
```

Update `autoflow_replay()`'s docblock to name `{labels, prompts, settings, result}`, and give `autoflow_start()` a `light` flag:

```php
/** `launch`'s start answer for an autoflow run whose cursor is on `$leg`; `$spec` is the committed spec's text, `$plan` the plan's recorded path, `$light` the manifest's `light` flag. */
function autoflow_start(string $leg, array $ledger = [], ?string $spec = null, ?string $plan = null, bool $light = false): array
{
    $artifacts = ['spec' => $spec === null ? null : 'spec.md', 'plan' => $plan, 'pr' => null, 'issue' => null];
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => $leg, 'status' => 'pending'], 'gate_ledger' => $ledger, 'artifacts' => $artifacts, ...($light ? ['light' => true] : [])]);
```

(the rest of `autoflow_start()` is unchanged).

- [ ] **Step 2: Write the failing tests**

Append to the **end** of `AutoflowScriptTest.php` (the datasets use `AUTOFLOW_STOP`, which is defined partway down the file and must exist when a dataset is built):

```php
it('runs every step on its full entry without light, and implement on xhigh once verify-ui or review-pr looped back', function () {
    $design = [...AUTOFLOW_C, 'size' => 'Architectural'];
    $replay = autoflow_replay(autoflow_start('design'), [
        'design:spec' => [$design, $design], 'design:plan' => [$design, $design],
        'review-plan:review' => [AUTOFLOW_C, AUTOFLOW_C],
        'review-plan:resolve' => [AUTOFLOW_LB, AUTOFLOW_C],
        'handoff:run' => [AUTOFLOW_C],
        'implement:run' => [[...AUTOFLOW_C, 'ui' => true], [...AUTOFLOW_C, 'ui' => true], [...AUTOFLOW_C, 'ui' => false]],
        'verify-ui:run' => [AUTOFLOW_LB, AUTOFLOW_C],
        'review-pr:review' => [AUTOFLOW_C, AUTOFLOW_C],
        'review-pr:resolve' => [AUTOFLOW_LB, AUTOFLOW_C],
    ]);

    expect($replay['settings'])->toBe([
        'opus high', 'opus high', 'fable high', 'opus high', 'opus high', 'opus high', 'fable high', 'opus high',
        'sonnet low', 'opus high', 'sonnet high', 'opus xhigh', 'sonnet high',
        'fable high', 'opus high', 'opus xhigh', 'fable high', 'opus high',
    ]);
    expect($replay['result'])->toBe(['action' => 'done']);
});

it('runs a light run\'s Bounded design and every step after it on light, where implement and review-pr\'s review keep full\'s setting', function () {
    $replay = autoflow_replay(autoflow_start('design', light: true), [
        'design:spec' => [[...AUTOFLOW_C, 'size' => 'Bounded']],
        'review-plan:review' => [AUTOFLOW_C], 'review-plan:resolve' => [AUTOFLOW_C],
        'handoff:run' => [AUTOFLOW_C],
        'implement:run' => [[...AUTOFLOW_C, 'ui' => true]],
        'verify-ui:run' => [AUTOFLOW_C],
        'review-pr:review' => [AUTOFLOW_C], 'review-pr:resolve' => [AUTOFLOW_C],
    ]);

    expect($replay['labels'])->toBe(['design:spec', 'review-plan:review', 'review-plan:resolve', 'handoff:run', 'implement:run', 'verify-ui:run', 'review-pr:review', 'review-pr:resolve']);
    expect($replay['settings'])->toBe(['opus medium', 'fable medium', 'opus medium', 'sonnet low', 'opus high', 'sonnet medium', 'fable high', 'opus medium']);
    expect($replay['result'])->toBe(['action' => 'done']);
});

it('moves a light run to full when its design turns out Architectural, and on a Bounded escalation', function (string $leg, ?string $spec, array $returns, array $labels, array $settings) {
    $replay = autoflow_replay(autoflow_start($leg, spec: $spec, light: true), $returns);

    expect($replay['labels'])->toBe($labels);
    expect($replay['settings'])->toBe($settings);
    expect($replay['result'])->toBe(['action' => 'halt', 'leg' => 'review-plan', 'reason' => 'stub stop']);
})->with([
    'a spec step that writes an Architectural design' => ['design', null, [
        'design:spec' => [[...AUTOFLOW_C, 'size' => 'Architectural']],
        'design:plan' => [[...AUTOFLOW_C, 'size' => 'Architectural']],
        'review-plan:review' => [AUTOFLOW_STOP],
    ], ['design:spec', 'design:plan', 'review-plan:review'], ['opus medium', 'opus high', 'fable high']],
    'a Bounded escalation' => ['review-plan', "# x — design\n\n**Design size:** Bounded\n", [
        'review-plan:review' => [AUTOFLOW_PI, AUTOFLOW_STOP],
        'design:spec' => [[...AUTOFLOW_C, 'size' => 'Architectural']],
        'design:plan' => [[...AUTOFLOW_C, 'size' => 'Architectural']],
    ], ['review-plan:review', 'design:spec', 'design:plan', 'review-plan:review'], ['fable medium', 'opus high', 'opus high', 'fable high']],
]);

it('starts implement on its loop-back entry when the ledger already counts a verify-ui or review-pr loop-back, and not after a plan gap', function (array $entry, string $setting) {
    $replay = autoflow_replay(autoflow_start('implement', [$entry]), ['implement:run' => [[...AUTOFLOW_STOP, 'ui' => false]]]);

    expect($replay['settings'])->toBe([$setting]);
})->with([
    'a review-pr loop-back' => [['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => '2026-09-29T10:00:00Z', 'review' => 'r', 'outcome' => 'looped-back'], 'opus xhigh'],
    'a verify-ui loop-back' => [['gate' => 'verify-ui', 'leg' => 'verify-ui', 'cycle' => 1, 'at' => '2026-09-29T10:00:00Z', 'outcome' => 'looped-back'], 'opus xhigh'],
    'a plan gap' => [['gate' => 'plan-approval', 'leg' => 'implement', 'cycle' => 1, 'at' => '2026-09-29T10:00:00Z', 'reason' => 'needs a queue', 'outcome' => 'looped-back'], 'opus high'],
]);

it('reruns a review that returned nothing once, on the retry entry, and no other step', function () {
    $review = autoflow_replay(autoflow_start('review-plan'), ['review-plan:review' => [null, AUTOFLOW_STOP]]);

    expect($review['labels'])->toBe(['review-plan:review', 'review-plan:review']);
    expect($review['settings'])->toBe(['fable high', 'opus xhigh']);
    expect($review['result'])->toBe(['action' => 'halt', 'leg' => 'review-plan', 'reason' => 'stub stop']);

    $handoff = autoflow_replay(autoflow_start('handoff'), ['handoff:run' => [null]]);

    expect($handoff['labels'])->toBe(['handoff:run']);
    expect($handoff['result'])->toBe(['action' => 'halt', 'leg' => 'handoff', 'reason' => 'the agent returned nothing']);
});

it('follows whatever agents launch hands it, and retries a review that runs on another model', function () {
    $start = autoflow_start('review-plan');
    $start['agents']['full']['review-plan:review'] = ['model' => 'opus', 'effort' => 'max'];
    $start['agents']['retry'] = ['model' => 'sonnet', 'effort' => 'medium'];

    expect(autoflow_replay($start, ['review-plan:review' => [null, AUTOFLOW_STOP]])['settings'])->toBe(['opus max', 'sonnet medium']);
});

it('runs a smoke run\'s stub steps on the smoke entry and never retries one', function () {
    $start = [...autoflow_start('review-plan'), 'stub' => ['prompt' => 'Return it.', 'steps' => ['review-plan:review' => [AUTOFLOW_C]]]];
    $replay = autoflow_replay($start, ['review-plan:review' => [null]]);

    expect($replay['settings'])->toBe(['sonnet low']);
    expect($replay['result'])->toBe(['action' => 'halt', 'leg' => 'review-plan', 'reason' => 'the agent returned nothing']);
});

it('halts before any agent when launch\'s agents or profile are missing or incomplete', function (callable $break) {
    $replay = autoflow_replay($break(autoflow_start('handoff')), []);

    expect($replay['labels'])->toBe([]);
    expect($replay['result'])->toBe(['action' => 'halt', 'leg' => 'handoff', 'reason' => 'args carry no complete agents table: re-run launch from checks that have pipeline_agent_table()']);
})->with([
    'no agents' => [function (array $start) { unset($start['agents']); return $start; }],
    'a step missing from light' => [function (array $start) { unset($start['agents']['light']['design:plan']); return $start; }],
    'an entry without an effort' => [function (array $start) { unset($start['agents']['full']['handoff:run']['effort']); return $start; }],
    'an empty model' => [function (array $start) { $start['agents']['full']['implement:run']['model'] = ''; return $start; }],
    'no retry entry' => [function (array $start) { unset($start['agents']['retry']); return $start; }],
    'no smoke entry' => [function (array $start) { unset($start['agents']['smoke']); return $start; }],
    'a loop-back entry for a step the run does not have' => [function (array $start) { $start['agents']['loopedBack']['design:run'] = ['model' => 'opus', 'effort' => 'high']; return $start; }],
    'no profile' => [function (array $start) { unset($start['profile']); return $start; }],
    'a profile that is neither full nor light' => [function (array $start) { $start['profile'] = 'medium'; return $start; }],
]);
```

Append to `LockStepTest.php`:

```php
it('keeps every model and effort out of the autoflow script, which takes them from launch', function () {
    $script = (string) file_get_contents(__DIR__ . '/../../workflow/pipeline-autoflow.js');

    foreach ([...PIPELINE_AGENT_MODELS, 'haiku', ...PIPELINE_AGENT_EFFORTS] as $name) {
        expect(preg_match("/(['\"`]){$name}\\1/", $script))->toBe(0, "the autoflow script names '{$name}'");
    }
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='AutoflowScriptTest|LockStepTest'`
Expected: FAIL — `settings` holds `undefined undefined` / `fable undefined` / `sonnet undefined` where the tests expect table entries; the incomplete-agents cases run agents instead of halting; `LockStepTest` reports `the autoflow script names 'opus'`.

- [ ] **Step 4: Write the implementation**

In `skills/pipeline/workflow/pipeline-autoflow.js`, replace the comment above `COPIED`:

```js
// The routing tables are launch's: `tables` in its start answer, built by pipeline_routing_tables() from
// the functions interactive mode uses. So are the agents: `agents` (pipeline_agent_table()) and the
// profile the run starts on (pipeline_start_profile()), so the script names no model or effort
// (engine.md §Agents per step). meta.phases repeats the legs as labels only (meta must be a pure
// literal). AutoflowScriptTest replays this script on launch's answer with agent() faked.
```

Add after `complete()`:

```js
function isSetting(entry) {
  return typeof entry?.model === 'string' && entry.model !== '' && typeof entry?.effort === 'string' && entry.effort !== ''
}

// Both profiles cover every step of the tables, a loop-back entry names one of those steps, and the
// retry and smoke entries and the profile to start on are there.
function completeAgents(agents, profile, steps) {
  const keys = Object.entries(steps).flatMap(([leg, list]) => list.map(step => `${leg}:${step}`))
  const { full, light, loopedBack, retry, smoke } = agents ?? {}
  const covers = table => typeof table === 'object' && table !== null && keys.every(key => isSetting(table[key]))
  return covers(full) && covers(light)
    && typeof loopedBack === 'object' && loopedBack !== null && Object.entries(loopedBack).every(([key, entry]) => keys.includes(key) && isSetting(entry))
    && isSetting(retry) && isSetting(smoke)
    && ['full', 'light'].includes(profile)
}

function setting({ model, effort }) {
  return { model, effort }
}

// A step whose leg a gate has looped back to in this run (loops counts on from the ledger's) takes its
// loop-back entry when it has one; every other step its entry in the current profile.
function settingFor(leg, step) {
  const key = `${leg}:${step}`
  const looped = Object.entries(loopTarget).some(([gate, target]) => target === leg && loops[gate] > 0)
  return setting((looped && agents.loopedBack[key]) || agents[profile][key])
}
```

Replace `runStep()`:

```js
async function runStep(leg, step) {
  const returns = args.stub?.steps[`${leg}:${step}`]?.shift()
  if (args.stub && !returns) return { status: 'halted', reason: `the smoke run has no stub for ${leg}:${step}` }
  const opts = {
    label: `${leg}:${step}`,
    phase: leg,
    schema: returns?.throw ? UNSATISFIABLE : schemaFor(leg, step),
    ...(returns ? setting(agents.smoke) : settingFor(leg, step)),
  }
  const prompt = returns ? stubPrompt(leg, step, returns) : stepPrompt(leg, step)
  try {
    const result = await agent(prompt, opts)
    const retried = result === null && step === 'review' && !returns ? await agent(prompt, { ...opts, ...setting(agents.retry) }) : result
    return retried ?? { status: 'halted', reason: 'the agent returned nothing' }
  } catch (error) {
    return { status: 'halted', reason: `the agent failed: ${error?.message ?? error}` }
  }
}
```

After the `review-plan` check (`if (!legs.includes(args.startLeg) || …) return halt(…)`), add:

```js
if (!completeAgents(args.agents, args.profile, steps)) return halt(args.startLeg, 'args carry no complete agents table: re-run launch from checks that have pipeline_agent_table()')
const agents = args.agents
```

After `let size = args.size`, add:

```js
let profile = args.profile
```

In the step loop, replace `if (leg === 'design') size = result.size` with:

```js
    if (leg === 'design') {
      size = result.size
      profile = size === 'Bounded' ? 'light' : 'full'
    }
```

and replace `if (!counted) exempted = true` with:

```js
  if (!counted) {
    exempted = true
    profile = 'full' // an escalation: the grow-form design and every step after it run on full
  }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='AutoflowScriptTest|LockStepTest'`
Expected: PASS, the earlier `AutoflowScriptTest` cases included (the retried-review smoke pass still reruns `review-pr:review`).

- [ ] **Step 6: Commit**

```bash
git add skills/pipeline/workflow/pipeline-autoflow.js skills/pipeline/checks/tests/autoflow_replay.mjs skills/pipeline/checks/tests/AutoflowScriptTest.php skills/pipeline/checks/tests/LockStepTest.php
git commit -m "feat(pipeline): the autoflow script runs every step on launch's agents table (#51)"
```

---

### Task 4: `run_cost.php` weighs each call by its model

**Files:**
- Modify: `skills/pipeline/checks/run_cost.php`
- Test: `skills/pipeline/checks/tests/RunCostTest.php`

**Interfaces:**
- Produces: `const PIPELINE_MODEL_FACTORS`; `pipeline_model_family(string $model): ?string`; `pipeline_transcript_usage(string $jsonl): array` now `list<array{model: string, usage: array}>`; `pipeline_call_cost(array $usage, string $model = ''): float`; `pipeline_transcript_cost()` gains `models: list<string>`; each step line of `pipeline_run_cost_lines()` names its families.

- [ ] **Step 1: Write the failing tests**

In `RunCostTest.php`, give `cost_call()` a model:

```php
function cost_call(string $id, int $input, int $write, int $read, int $output = 50, ?array $split = null, ?string $at = null, ?string $model = null): string
{
    $usage = ['input_tokens' => $input, 'cache_creation_input_tokens' => $write, 'cache_read_input_tokens' => $read, 'output_tokens' => $output];
    if ($split !== null) {
        $usage['cache_creation'] = $split;
    }
    $record = ['type' => 'assistant', 'message' => ['id' => $id, 'role' => 'assistant', 'usage' => $usage, ...($model === null ? [] : ['model' => $model])]];

    return json_encode($at === null ? $record : [...$record, 'timestamp' => "2026-09-25T{$at}Z"]);
}
```

In *weighs each call as the token audit does, once per message id*, add `expect($cost['models'])->toBe([]);` after the `peak` expectation, and change the empty-transcript line to:

```php
    expect(pipeline_transcript_cost(''))->toBe(['calls' => 0, 'cost' => 0.0, 'peak' => 0, 'models' => []]);
```

Append:

```php
it('weighs each token type by its model\'s factor, and a call of no known family as Opus', function () {
    $usage = ['input_tokens' => 1000, 'cache_creation_input_tokens' => 2000, 'cache_read_input_tokens' => 10000, 'output_tokens' => 100];

    // Opus: 1000 + 2000 × 1.25 + 10000 × 0.1 + 100 × 5 = 5000
    expect(pipeline_call_cost($usage, 'claude-opus-5-5'))->toEqualWithDelta(5000, 0.001);
    // Fable: 1000 × 2.5 + 2500 × 2.5 + 1000 × 1.25 + 500 × 2.5 = 11250
    expect(pipeline_call_cost($usage, 'claude-fable-5-1'))->toEqualWithDelta(11250, 0.001);
    // Sonnet: 1000 × 0.5 + 2500 × 0.5 + 1000 × 1.0 + 500 × 0.5 = 3000
    expect(pipeline_call_cost($usage, 'claude-sonnet-5-5'))->toEqualWithDelta(3000, 0.001);
    // Haiku: 1000 × 0.25 + 2500 × 0.25 + 1000 × 0.5 + 500 × 0.25 = 1500
    expect(pipeline_call_cost($usage, 'claude-haiku-4-5-20251001'))->toEqualWithDelta(1500, 0.001);
    foreach (['<synthetic>', '', 'claude-mythos-1', 'gpt-5'] as $other) {
        expect(pipeline_call_cost($usage, $other))->toEqualWithDelta(5000, 0.001);
    }
    expect(pipeline_call_cost($usage))->toEqualWithDelta(5000, 0.001);
    // a 1h write on Fable: 1000 × 2.5 + 2000 × 2 × 2.5 + 1000 × 1.25 + 500 × 2.5 = 15000
    $hour = [...$usage, 'cache_creation' => ['ephemeral_5m_input_tokens' => 0, 'ephemeral_1h_input_tokens' => 2000]];
    expect(pipeline_call_cost($hour, 'claude-fable-5-1'))->toEqualWithDelta(15000, 0.001);
});

it('reads each call\'s family from its model id, and none from a model that is not a claude- one', function () {
    expect(pipeline_model_family('claude-fable-5-1'))->toBe('fable');
    expect(pipeline_model_family('claude-haiku-4-5-20251001'))->toBe('haiku');
    expect(pipeline_model_family('claude-mythos-1'))->toBe('mythos');
    expect(pipeline_model_family('<synthetic>'))->toBeNull();
    expect(pipeline_model_family(''))->toBeNull();
});

it('names the families a step\'s calls ran on, in first-seen order, and none for a step without a model', function () {
    $dir = cost_run([
        'a1' => ['review-plan:review', implode("\n", [
            cost_call('m1', 10000, 20000, 100000, 1000, null, '10:00:00.000', 'claude-opus-5-5'),
            cost_call('m2', 0, 0, 0, 0, null, '10:01:00.000', '<synthetic>'),
            cost_call('m3', 10000, 20000, 100000, 1000, null, '10:02:00.000', 'claude-fable-5-1'),
            cost_call('m4', 10000, 20000, 100000, 1000, null, '10:03:00.000', 'claude-opus-5-5'),
        ]), ['status' => 'continued']],
        'a2' => ['handoff:run', cost_call('m5', 10000, 20000, 100000, 1000, null, '10:04:00.000'), ['status' => 'continued']],
    ]);

    // a1: 50000 (Opus) + 0 + 112500 (Fable) + 50000 = 212500; a2 (no model, as Opus): 50000
    expect(checks_cli('run_cost_cli.php', [$dir])['stdout'])->toBe(implode("\n", [
        'review-plan:review (opus+fable): 0.21M over 4 calls, peak 130k, 3.0 min (0.0 waiting on tools)',
        'handoff:run: 0.05M over 1 calls, peak 130k, 0.0 min (0.0 waiting on tools)',
        'run: 0.26M weighted over 2 steps in 4.0 min; largest step peak 130k (review-plan:review)',
    ]));
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='RunCostTest'`
Expected: FAIL — `Call to undefined function pipeline_model_family()`, a Fable call weighing 5000 instead of 11250, no `models` key, no `(opus+fable)` on the step line.

- [ ] **Step 3: Write the implementation**

In `skills/pipeline/checks/run_cost.php`, extend the file docblock's first sentence with *, each call weighed by its model's factor per token type* and add after `PIPELINE_COST_WEIGHTS`:

```php
/**
 * Each model family's rate per token type relative to Opus 5.5's, from the `claude-api` skill's price
 * table (cached 2026-09-25): Opus $4 / $20, read $0.20; Fable 5.1 $10 / $50, read $0.25; Sonnet 5.5
 * $2 / $10, read $0.20; Haiku 4.5 $1 / $5, read $0.10. Cache writes are 1.25× / 2× input on every
 * model, so their factor is the input factor. Opus is 1.0, so every figure measured so far keeps its number.
 */
const PIPELINE_MODEL_FACTORS = [
    'opus' => ['input' => 1.0, 'write5m' => 1.0, 'write1h' => 1.0, 'read' => 1.0, 'output' => 1.0],
    'fable' => ['input' => 2.5, 'write5m' => 2.5, 'write1h' => 2.5, 'read' => 1.25, 'output' => 2.5],
    'sonnet' => ['input' => 0.5, 'write5m' => 0.5, 'write1h' => 0.5, 'read' => 1.0, 'output' => 0.5],
    'haiku' => ['input' => 0.25, 'write5m' => 0.25, 'write1h' => 0.25, 'read' => 0.5, 'output' => 0.25],
];
```

Replace `pipeline_transcript_usage()`, `pipeline_call_cost()` and `pipeline_transcript_cost()`:

```php
/** @return list<array{model: string, usage: array}> one per API call */
function pipeline_transcript_usage(string $jsonl): array
{
    $calls = [];
    foreach (pipeline_jsonl($jsonl) as $entry) {
        $message = $entry['message'] ?? null;
        if (! is_array($message) || ($message['role'] ?? null) !== 'assistant' || empty($message['usage'])) {
            continue;
        }
        $calls[$message['id'] ?? $entry['uuid'] ?? count($calls)] = ['model' => (string) ($message['model'] ?? ''), 'usage' => $message['usage']];
    }

    return array_values($calls);
}

/** The word after `claude-` (`claude-fable-5-1` → `fable`); null for `<synthetic>`, no model, or any other name. */
function pipeline_model_family(string $model): ?string
{
    return preg_match('/^claude-([a-z]+)/', $model, $match) ? $match[1] : null;
}

/** Writes without a 5m/1h split count as 5m writes, as `usage.py` counts them. A family the factor table lacks weighs as Opus. */
function pipeline_call_cost(array $usage, string $model = ''): float
{
    $split = $usage['cache_creation'] ?? [];
    $factors = PIPELINE_MODEL_FACTORS[pipeline_model_family($model) ?? 'opus'] ?? PIPELINE_MODEL_FACTORS['opus'];
    $weight = fn (string $type) => PIPELINE_COST_WEIGHTS[$type] * $factors[$type];

    return ($usage['input_tokens'] ?? 0) * $weight('input')
        + ($split === [] ? ($usage['cache_creation_input_tokens'] ?? 0) : ($split['ephemeral_5m_input_tokens'] ?? 0)) * $weight('write5m')
        + ($split['ephemeral_1h_input_tokens'] ?? 0) * $weight('write1h')
        + ($usage['cache_read_input_tokens'] ?? 0) * $weight('read')
        + ($usage['output_tokens'] ?? 0) * $weight('output');
}
```

```php
/** @return array{calls: int, cost: float, peak: int, models: list<string>} `models`: the families the calls ran on, in first-seen order */
function pipeline_transcript_cost(string $jsonl): array
{
    $calls = pipeline_transcript_usage($jsonl);
    $usage = array_column($calls, 'usage');

    return [
        'calls' => count($calls),
        'cost' => (float) array_sum(array_map(fn (array $call) => pipeline_call_cost($call['usage'], $call['model']), $calls)),
        'peak' => $usage === [] ? 0 : max(array_map('pipeline_call_context', $usage)),
        'models' => array_values(array_unique(array_filter(array_map(fn (array $call) => pipeline_model_family($call['model']), $calls)))),
    ];
}
```

In `pipeline_run_cost_lines()`, extend the docblock's step shape with `models: list<string>` and replace the step line's `sprintf`:

```php
        ...array_map(fn (array $step) => sprintf('%s%s: %.2fM over %d calls, peak %dk, %.1f min (%.1f waiting on tools)', $step['label'], $step['models'] === [] ? '' : ' (' . implode('+', $step['models']) . ')', $step['cost'] / 1e6, $step['calls'], intdiv($step['peak'], 1000), $step['wall'] / 60, $step['waiting'] / 60), $steps),
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php -l skills/pipeline/checks/run_cost.php && ./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='RunCostTest|RunAuditTest'`
Expected: PASS; the existing cost cases keep their numbers (their transcripts carry no model).

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/run_cost.php skills/pipeline/checks/tests/RunCostTest.php
git commit -m "feat(pipeline): run cost weighs each call by its model, and names the models per step (#51)"
```

---

### Task 5: the docs, pinned to the table

**Files:**
- Modify: `skills/pipeline/references/engine.md` (new §Agents per step; §`autoflow`; §Failure policy), `skills/pipeline/references/manifest.md`, `skills/pipeline/SKILL.md`
- Test: `skills/pipeline/checks/tests/LockStepTest.php`

**Interfaces:**
- Consumes: `pipeline_agent_table(array $override): array` (Task 1).

- [ ] **Step 1: Write the failing test**

Append to `LockStepTest.php`:

```php
it('keeps engine.md\'s agents table in lock-step with pipeline_agent_table()', function () {
    $section = lockstep_section('engine.md', 'Agents per step');
    $table = pipeline_agent_table([]);
    $cell = fn (array $entry) => "{$entry['model']} {$entry['effort']}";
    $rows = [];
    foreach ($table['full'] as $step => $entry) {
        $rows[] = "| `{$step}` | {$cell($entry)} | {$cell($table['light'][$step])} |";
    }
    foreach ($table['loopedBack'] as $step => $entry) {
        $rows[] = "| `{$step}` after a loop-back | {$cell($entry)} | {$cell($entry)} |";
    }
    $rows[] = "| a review that returned nothing, once | {$cell($table['retry'])} | {$cell($table['retry'])} |";
    $rows[] = "| a smoke run's stub step | {$cell($table['smoke'])} | {$cell($table['smoke'])} |";

    expect($rows)->toHaveCount(12);
    foreach ($rows as $row) {
        expect($section)->toContain($row);
    }
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='LockStepTest'`
Expected: FAIL — `engine.md has no section starting '## Agents per step'`.

- [ ] **Step 3: Write the docs**

**engine.md — the new section.** Insert directly before `## Interactive — the same loop, the human resolves`:

```markdown
## Agents per step — one table, explicit model and effort

Every `autoflow` step's agent runs on a model and an effort from one table, `PIPELINE_AGENTS` in
`../checks/agents.php`; no step inherits the session's `~/.claude/settings.json`, which differs per
machine and changes silently. `launch` hands the table to the script as `agents` in its `start` answer
(`pipeline_agent_table()`, the manifest's override laid over it) with `profile`, the profile the run
starts on (`pipeline_start_profile()`); the script names no model or effort, and a missing or
incomplete `agents` or `profile` halts it before any agent. Models are `agent()`'s aliases, efforts its
levels. The owner's constraints are tokens (plan limits), quality and speed, not price.

| Step | `full` | `light` | Why |
|---|---|---|---|
| `design:spec` | opus high | opus medium | Full: a mistake surfaces only at `review-plan` and costs a loop (design, review, resolve). Light: a ~25-line design, and escalation is the safety net. |
| `design:plan` | opus high | opus medium | As `design:spec`. The plan step runs only on an Architectural design, so on `full`; the `light` entry keeps every step in both profiles. |
| `review-plan:review` | fable high | fable medium | Full: independent of the author, Fable's documented starting point; xhigh added nothing measurable in two runs, and `low` answers from memory more. Light: a short spec is flatter work. |
| `review-plan:resolve` | opus high | opus medium | Full: it decides which findings to reject. Light: few findings on a short plan. |
| `handoff:run` | sonnet low | sonnet low | Near-mechanical. Haiku 4.5 has no effort setting and writes `implement`'s prompt: rejected. |
| `implement:run` | opus high | opus high | Light keeps high: TDD and the escalation check after every commit happen here, and its time goes to CI and Pint, not the model. |
| `verify-ui:run` | sonnet high | sonnet medium | Mostly browser operation; full stays high because it is a gate that can send the run back to `implement`. Light: few states to capture. |
| `review-pr:review` | fable high | fable high | The last gate before a human merges, on either size. |
| `review-pr:resolve` | opus high | opus medium | Full: nothing reviews it afterwards unless it loops back. Light: targeted fixes on a small diff. |
| `implement:run` after a loop-back | opus xhigh | opus xhigh | A `verify-ui` or `review-pr` loop-back is the failure signal to rerun with more effort. |
| a review that returned nothing, once | opus xhigh | opus xhigh | Rare; it fires on `null`, not on a review with no findings, and compensates for reviewing with the author's model. |
| a smoke run's stub step | sonnet low | sonnet low | A stub does no real work. |

**Which profile.** `launch` starts the run on `full` once the ledger records an `escalated` entry; else,
once a spec exists, on `light` when it says Bounded and `full` otherwise; else on `light` when the
manifest says `light`, the only signal before `design` runs. The script then sets the profile after
every `continued` design step from the size it returned (`light` for Bounded), and to `full` on a
Bounded escalation, so the grow-form design and every step after it run on `full`. Every step, `design`
included, runs on the current profile.

**The loop-back entry.** A step whose leg a gate has looped back to in this run — `loops` counts on from
the ledger's, so a resume keeps it — takes its `loopedBack` entry when it has one: `implement:run` after
a `verify-ui` or `review-pr` loop-back. A plan gap loops back to `design`, which has none.

**The override.** A manifest may set `agents: {"<leg>:<step>": {"model": …, "effort": …}}` by hand for a
one-off experiment; either field may be left out and keeps the table's. It replaces that step's entry
in both profiles and its loop-back entry; the retry and smoke entries are not overridable. `launch`
halts on an override that is not an object of `autoflow` steps each naming a known model or effort
(*the manifest's agents override is invalid: …*), and a leg that writes `agents` halts at the next brief.

**Fable stays the reviewer.** Reviews on Opus would be the largest token lever, but give up an
independent reviewer. `run_cost_cli.php` weighs each call by its model (§`autoflow`), so a model swap
shows in the figure; effort shows mostly as turns and wall time.
```

**engine.md — §`autoflow`.** Replace the `start` answer comment line:

```
# → {"action":"start","startLeg":…,"startStep":…,"loops":{…},"ui":…,"size":…,"manifest":…,"worktree":…,"noOpen":…,"checks":…,"tables":{…}}
```

with:

```
# → {"action":"start","startLeg":…,"startStep":…,"loops":{…},"ui":…,"size":…,"manifest":…,"worktree":…,"noOpen":…,"checks":…,"tables":{…},"profile":…,"agents":{…}}
```

In the **`launch`** bullet, after the sentence ending *so the script keeps no copy of them.*, add:

```markdown
  `agents` and `profile` are the step agents' models and efforts and the profile the run starts on
  (§Agents per step); an invalid `agents` override in the manifest halts `launch` with the other
  manifest checks, before anything is written.
```

In the **The script** bullet, replace:

```markdown
  on `launch`'s answer. A review step runs on Fable, and once more on Opus when it returns nothing;
  `handoff` runs at low effort; a step that throws or returns nothing halts the run.
```

with:

```markdown
  on `launch`'s answer. Every step runs on the model and effort `agents` gives it (§Agents per step),
  and `agents` or `profile` missing or incomplete halts before any agent; a review step that returns
  nothing runs once more on the retry entry; a step that throws or returns nothing halts the run.
```

In the paragraph after the `run_cost_cli.php` / `run_audit.php` commands, replace:

```markdown
the Workflow result. `run_cost_cli.php` prints per step the weighted cost, the peak context, the wall
```

with:

```markdown
the Workflow result. `run_cost_cli.php` prints per step the weighted cost — each call's token types
weighed per model (`PIPELINE_MODEL_FACTORS`, relative to Opus), the models named after the step's
label — the peak context, the wall
```

**engine.md — §Failure policy.** Replace:

```markdown
  - **In `autoflow`** a review step that returns nothing runs once more, on Opus; a step that throws,
```

with:

```markdown
  - **In `autoflow`** a review step that returns nothing runs once more, on the retry entry (Opus,
    §Agents per step); a step that throws,
```

and replace:

```markdown
  itself runs on Fable; when it returns nothing — a usage limit in a background session — the script
  runs it once more on Opus (in an interactive session a usage limit pauses the workflow, which
```

with:

```markdown
  itself runs on Fable (§Agents per step); when it returns nothing — a usage limit in a background
  session — the script runs it once more on the retry entry, Opus (in an interactive session a usage
  limit pauses the workflow, which
```

**manifest.md.** Replace the `light` row:

```markdown
| `light` | optional | the invocation's `light`; read only while `design` has not run |
```

with:

```markdown
| `light` | optional | the invocation's `light`: it permits a Bounded design, and in `autoflow` `launch` starts the run on the `light` agents profile while no spec exists (`engine.md` §Agents per step); read only while `design` has not run |
| `agents` | optional | `autoflow` only, set by hand for a one-off experiment: `{"<leg>:<step>": {"model"?, "effort"?}}`, laid over that step's entry in both profiles and its loop-back entry. `launch` halts on an invalid one; a leg that changes it halts the run (`engine.md` §Agents per step) |
```

**SKILL.md.** In the **Cost per run** bullet, replace `(weighted cost and wall time per step,` with `(cost weighted per model and wall time per step,`. In the **`light` permits a small design.** bullet, after *every leg and both reviews still run.*, add:

```markdown
  In `autoflow` a small change also runs on lighter agents on every leg but `implement` and
  `review-pr`'s review (`references/engine.md` §Agents per step).
```

- [ ] **Step 4: Run the whole suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, `LockStepTest`'s agents-table case and *keeps every engine.md section a brief names* included.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/references/engine.md skills/pipeline/references/manifest.md skills/pipeline/SKILL.md skills/pipeline/checks/tests/LockStepTest.php
git commit -m "docs(pipeline): one table of agents per autoflow step, pinned by LockStepTest (#51)"
```
