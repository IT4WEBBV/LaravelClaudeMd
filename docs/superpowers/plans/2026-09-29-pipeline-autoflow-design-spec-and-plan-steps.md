# `autoflow`'s design runs as a spec step and a plan step Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task by task. Steps use checkbox (`- [ ]`) syntax for tracking. In an `autoflow` implement step, execute inline, task by task, with no subagents.

**Goal:** In `autoflow`, `design` runs as `design:spec` (brainstorm, commit the spec) and then a fresh `design:plan` (read the spec cold, write the plan); a Bounded design stays the spec step alone, a plan gap reruns only `design:plan`, a review loop-back and an escalation rerun both (#73).

**Architecture:**
- `skills/pipeline/checks/brief.php`: `design:spec` and `design:plan` overrides beside `interactive`'s `design:run`, and a grow-form line per step (Task 1).
- `skills/pipeline/checks/dispatch.php` / `pipeline.php` / `dispatch_cli.php`: `pipeline_steps($leg, $mode)`, `PIPELINE_BOUNDED_STEPS`, the derived design step (`pipeline_design_step()`, `pipeline_is_plan_return()`), `pipeline_step_problem()` refusing the other design step, `tables.bounded` (Task 2).
- `skills/pipeline/workflow/pipeline-autoflow.js`: `stepsOf()`, the size taken after each design step, `from = 'plan'` on a plan gap, `tables.bounded` in `complete()` (Task 3).
- Docs: engine.md §`autoflow`, §Stations, §Design size; manifest.md `artifacts` (Task 4).

**Tech Stack:** PHP 8.4 on the host, Pest 4, Node (the script replay), Markdown skill references.

**Spec:** `docs/superpowers/specs/2026-09-29-pipeline-autoflow-design-spec-and-plan-steps-design.md`

## Global Constraints

- Suite, from the worktree root on the host: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`. `vendor/` is missing in a fresh worktree: run `composer install --no-interaction --quiet` first. `AutoflowScriptTest` needs `node` on PATH.
- The design-step refusal, verbatim (Task 2 produces it, Task 3's smoke case expects it): `the manifest calls for the design {next} step, not {step}: the plan step follows a spec step that set artifacts.spec and removed artifacts.plan, or a plan-insufficient on an Architectural design`.
- `interactive` does not change: `design` there is one step, `run`, with today's overrides and grow-form line.
- `pipeline_legs()`, `LegStatus::allowedFor()`, `pipeline_loop_target()`, `PIPELINE_LOOP_BOUND`, the ledger shape, `run_cost.php`, `run_audit.php` and `pipeline_is_plan_gap()` do not change.
- engine.md `## ` headings do not change: `LockStepTest` resolves the briefs' `§` names against them.
- No step returns `tasks` (#72 is closed).
- This repo has no `.changelog/` and no `CHANGELOG.md`: no changelog entry. The PR closes #73.

## Review Focus

1. **A plan step that finds the spec step kept the old `artifacts.plan`** must halt at its brief, not run over a stale plan pointer. Task 3's smoke case *the spec step kept the old plan* pins it through the real `brief`.
2. **A resumed run** (`launch` on a halted design) starts at the step that has not run: `plan` when the spec is set and the plan is not. Task 2's `launch` test and Task 3's first smoke case (a run started at `design` with a committed spec) pin it.
3. **`review-plan:review`'s own `plan-insufficient`** on an Architectural design reruns only `design:plan`, on both sides: the script (status and size) and `pipeline_design_step()` (an entry with no `review`). Task 2 pins the PHP row, Task 3 the script's labels.
4. **A Bounded escalation** reruns `design:spec` and then, because the grown spec returns Architectural, `design:plan`; a second Bounded `plan-insufficient` stays counted. Task 3's escalation case and the existing *exempts one Bounded escalation* case pin both.
5. **`tables` from an older `launch`** (no `bounded`) halts before any agent instead of running a Bounded design's plan step. Task 3's incomplete-tables dataset gains that row.

---

## File Structure

- Modify `skills/pipeline/checks/brief.php` (Task 1), `skills/pipeline/checks/dispatch.php`, `skills/pipeline/checks/pipeline.php`, `skills/pipeline/checks/dispatch_cli.php` (Task 2), `skills/pipeline/workflow/pipeline-autoflow.js` (Task 3), `skills/pipeline/references/engine.md` and `skills/pipeline/references/manifest.md` (Task 4).
- Tests: `skills/pipeline/checks/tests/BriefTest.php` (Tasks 1, 2), `DispatchTest.php` and `DispatchCliTest.php` (Task 2), `AutoflowScriptTest.php` (Task 3).

---

### Task 1: the briefs of `design:spec` and `design:plan`

**Files:**
- Modify: `skills/pipeline/checks/brief.php` (`pipeline_leg_overrides()`, `pipeline_brief_overrides()`, a new `pipeline_grow_form_line()`)
- Test: `skills/pipeline/checks/tests/BriefTest.php`

**Interfaces:**
- Produces: override keys `design:spec` and `design:plan` in `pipeline_leg_overrides($mode)` for both modes (Task 2's key test and `brief` rely on them); `pipeline_grow_form_line(string $step): string`.

- [ ] **Step 1: Write the failing tests**

In `BriefTest.php`, replace the test *tells design to confirm by reading, probe only to choose, and leave the Expected lines to implement* with this version, which names the step (after Task 2 an `autoflow` brief without a step derives `plan` from `brief_manifest()`'s spec):

```php
it('tells design to confirm by reading, probe only to choose, and leave the Expected lines to implement', function () {
    foreach ([['autoflow', 'spec'], ['interactive', 'run']] as [$mode, $step]) {
        expect(pipeline_brief(brief_manifest('design', ['mode' => $mode]), 'design', '/tmp/m.json', $step))
            ->toContain('Do not build or run the plan\'s code, in a scratch copy or anywhere else: confirm the signatures and APIs it relies on by reading, `php -l` or grep; `implement` proves the plan\'s Expected lines (engine.md §What design proves).')
            ->toContain('The one exception: when the choice between approaches hinges on whether one of them works at all, answer that question with a throwaway probe (a few lines run on their own, never the plan\'s code, never the suite) and write the question and what the probe showed into the spec.')
            ->toContain('Plans and specs committed before 2026-09-14 are not exemplars for test or proof policy, and no plan\'s `Verified before writing` header is part of the format.')
            ->not->toContain('for test or proof policy.');
    }
});
```

Add after it:

```php
it('splits autoflow\'s design into a spec step that stops at the spec and a plan step that reads it cold', function () {
    $manifest = brief_manifest('design', ['mode' => 'autoflow']);
    $spec = pipeline_brief($manifest, 'design', '/tmp/m.json', 'spec');
    $plan = pipeline_brief($manifest, 'design', '/tmp/m.json', 'plan');

    expect($spec)
        ->toContain('`design` leg, `spec` step')
        ->toContain('- Invoke `superpowers:brainstorming` and stop at the spec: on the Architectural path, where brainstorming hands over to `superpowers:writing-plans`, the plan is the next step\'s, `design:plan` (engine.md §Design size).')
        ->toContain('write each question and the answer you assumed into the spec\'s `## Assumptions` section')
        ->toContain('- Commit the spec. Set `artifacts.spec` and remove `artifacts.plan`: the plan step writes this spec\'s plan and sets it. On the Bounded path, commit the plan as well, a second commit, and set `artifacts.plan`: a Bounded design has no plan step.')
        ->not->toContain('Commit the spec, then the plan: two commits.');
    expect($plan)
        ->toContain('`design` leg, `plan` step')
        ->toContain('- Read the committed spec (`artifacts.spec`) cold, and the code it points at, and invoke `superpowers:writing-plans` on it; do not re-design what the spec settles (engine.md §Design size).')
        ->toContain('- Where the plan needs an answer the spec does not give, add the question and the answer you assumed to the spec\'s `## Assumptions` and commit that before the plan, so `/critique plan` audits it.')
        ->toContain('Do not build or run the plan\'s code')
        ->toContain('Plans and specs committed before 2026-09-14 are not exemplars')
        ->toContain('- Commit the plan. Set `artifacts.plan`.')
        ->not->toContain('throwaway probe')
        ->not->toContain('Invoke `superpowers:brainstorming`');
    expect(pipeline_brief(brief_manifest('design'), 'design', '/tmp/m.json', 'run'))
        ->toContain('- Commit the spec, then the plan: two commits. Set `artifacts.spec` and `artifacts.plan`.');
});
```

Replace the test *asks for grow form only after an escalation no plan approval has answered* with:

```php
it('asks for grow form only after an escalation no plan approval has answered, split over autoflow\'s two design steps', function () {
    $escalated = ['gate' => 'design-size', 'leg' => 'implement', 'at' => '2026-09-22T12:00:00Z', 'reason' => 'migration', 'outcome' => 'escalated'];
    $approved = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'at' => '2026-09-22T13:00:00Z', 'review' => 'ok', 'outcome' => 'continued'];
    $grown = fn (string $mode, string $step, array $ledger) => pipeline_brief(brief_manifest('design', ['mode' => $mode, 'gate_ledger' => $ledger]), 'design', '/tmp/wt/.claude/pipeline/feature-x.json', $step);

    expect($grown('interactive', 'run', [$escalated]))->toContain('- Grow form: the design escalated from Bounded (engine.md §Design size). Grow the spec and the plan; do not re-design them.');
    expect($grown('interactive', 'run', [$escalated, $approved]))->not->toContain('Grow form');
    expect($grown('autoflow', 'spec', [$escalated]))->toContain('- Grow form: the design escalated from Bounded (engine.md §Design size). Grow the spec: its header says `**Design size:** Architectural` and a `## Grown from Bounded` section says what changed, why it grew, what already exists and what remains; do not re-design it. The plan step adds the remaining steps to the plan.');
    expect($grown('autoflow', 'plan', [$escalated]))
        ->toContain('- Grow form: the design escalated from Bounded (engine.md §Design size). Add the remaining steps to the plan, as the spec\'s `## Grown from Bounded` section names them; do not re-design it.')
        ->not->toContain('Grow the spec');
    expect($grown('autoflow', 'plan', [$escalated, $approved]))->not->toContain('Grow form');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='design'`
Expected: FAIL. All three tests fail on their `autoflow` `design:spec` / `design:plan` briefs: `pipeline_brief_overrides()` reads `pipeline_leg_overrides(...)["design:spec"]`, an undefined key (a warning Pest reports, or a `TypeError` in `array_map` over null); the `interactive` `design:run` assertions alone would pass.

- [ ] **Step 3: Implement**

In `brief.php`, `pipeline_leg_overrides()`: before the `return [`, add the lines the three design steps share:

```php
    $assumptions = 'Where brainstorming would ask the human, write each question and the answer you assumed into the spec\'s `## Assumptions` section, so `/critique plan` audits exactly those.';
    $reads = 'Do not build or run the plan\'s code, in a scratch copy or anywhere else: confirm the signatures and APIs it relies on by reading, `php -l` or grep; `implement` proves the plan\'s Expected lines (engine.md §What design proves).';
    $probe = 'The one exception: when the choice between approaches hinges on whether one of them works at all, answer that question with a throwaway probe (a few lines run on their own, never the plan\'s code, never the suite) and write the question and what the probe showed into the spec.';
    $exemplars = 'Plans and specs committed before 2026-09-14 are not exemplars for test or proof policy, and no plan\'s `Verified before writing` header is part of the format.';
```

and replace the `'design:run' => [ … ],` entry with:

```php
        'design:run' => [
            'Invoke `superpowers:brainstorming`; on the Architectural path it hands over to `superpowers:writing-plans` (engine.md §Design size).',
            $assumptions,
            $reads,
            $probe,
            $exemplars,
            'Commit the spec, then the plan: two commits. Set `artifacts.spec` and `artifacts.plan`.',
        ],
        'design:spec' => [
            'Invoke `superpowers:brainstorming` and stop at the spec: on the Architectural path, where brainstorming hands over to `superpowers:writing-plans`, the plan is the next step\'s, `design:plan` (engine.md §Design size).',
            $assumptions,
            $reads,
            $probe,
            $exemplars,
            'Commit the spec. Set `artifacts.spec` and remove `artifacts.plan`: the plan step writes this spec\'s plan and sets it. On the Bounded path, commit the plan as well, a second commit, and set `artifacts.plan`: a Bounded design has no plan step.',
        ],
        'design:plan' => [
            'Read the committed spec (`artifacts.spec`) cold, and the code it points at, and invoke `superpowers:writing-plans` on it; do not re-design what the spec settles (engine.md §Design size).',
            'Where the plan needs an answer the spec does not give, add the question and the answer you assumed to the spec\'s `## Assumptions` and commit that before the plan, so `/critique plan` audits it.',
            $reads,
            $exemplars,
            'Commit the plan. Set `artifacts.plan`.',
        ],
```

In `pipeline_brief_overrides()`, replace

```php
    if ($leg === 'design' && pipeline_design_grows($ledger)) {
        $lines[] = 'Grow form: the design escalated from Bounded (engine.md §Design size). Grow the spec and the plan; do not re-design them.';
    }
```

with

```php
    if ($leg === 'design' && pipeline_design_grows($ledger)) {
        $lines[] = pipeline_grow_form_line($step);
    }
```

and add after `pipeline_design_grows()`:

```php
/** What the grow form asks of each design step: `autoflow`'s spec step grows the spec, its plan step the plan (engine.md §Design size). */
function pipeline_grow_form_line(string $step): string
{
    $escalated = 'Grow form: the design escalated from Bounded (engine.md §Design size).';

    return $escalated . ' ' . match ($step) {
        'spec' => 'Grow the spec: its header says `**Design size:** Architectural` and a `## Grown from Bounded` section says what changed, why it grew, what already exists and what remains; do not re-design it. The plan step adds the remaining steps to the plan.',
        'plan' => 'Add the remaining steps to the plan, as the spec\'s `## Grown from Bounded` section names them; do not re-design it.',
        default => 'Grow the spec and the plan; do not re-design them.',
    };
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='BriefTest|LockStepTest'`
Expected: PASS (`LockStepTest` resolves the new lines' `§Design size` and `§What design proves`).

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/brief.php skills/pipeline/checks/tests/BriefTest.php
git commit -m "feat(pipeline): briefs for autoflow's design:spec and design:plan steps (#73)"
```

---

### Task 2: `autoflow`'s design has two steps, the next one derived from the manifest

**Files:**
- Modify: `skills/pipeline/checks/dispatch.php` (`pipeline_steps()`, a new `PIPELINE_BOUNDED_STEPS`, `pipeline_routing_tables()`, `pipeline_step()`, a new `pipeline_design_step()`, `pipeline_step_problem()`)
- Modify: `skills/pipeline/checks/pipeline.php` (a new `pipeline_is_plan_return()` beside `pipeline_is_plan_gap()`)
- Modify: `skills/pipeline/checks/dispatch_cli.php` (`dispatch_cli_brief_args()`)
- Test: `skills/pipeline/checks/tests/DispatchTest.php`, `DispatchCliTest.php`, `BriefTest.php`

**Interfaces:**
- Consumes: the `design:spec` / `design:plan` override keys (Task 1).
- Produces: `pipeline_steps(string $leg, string $mode): array`; `PIPELINE_BOUNDED_STEPS = ['design' => ['spec']]`; `pipeline_design_step(array $manifest): string` (`spec` | `plan`); `pipeline_is_plan_return(array $entry): bool`; `tables.bounded` in `launch`'s answer (Task 3's script reads it); the refusal text in *Global Constraints*.

- [ ] **Step 1: Write the failing tests**

`DispatchTest.php`: replace the test *lists each leg's steps* with:

```php
it('lists each leg\'s steps: autoflow designs in a spec step and a plan step, interactive in one', function () {
    expect(pipeline_steps('review-plan', 'autoflow'))->toBe(['review', 'resolve']);
    expect(pipeline_steps('review-pr', 'interactive'))->toBe(['review', 'resolve']);
    expect(pipeline_steps('implement', 'autoflow'))->toBe(['run']);
    expect(pipeline_steps('design', 'autoflow'))->toBe(['spec', 'plan']);
    expect(pipeline_steps('design', 'interactive'))->toBe(['run']);
    expect(pipeline_steps('design', 'mangled'))->toBe(['run']);
    expect(PIPELINE_BOUNDED_STEPS)->toBe(['design' => ['spec']]);
});
```

and add at the end of the file:

```php
function design_manifest(?string $spec, ?string $plan, array $ledger = [], string $mode = 'autoflow'): array
{
    return [...dispatch_manifest('design', $ledger), 'mode' => $mode, 'artifacts' => ['spec' => $spec, 'plan' => $plan]];
}

const DESIGN_REVIEW_LOOP = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-29T10:00:00Z', 'review' => 'r', 'outcome' => 'looped-back'];
const DESIGN_GAP = ['gate' => 'plan-approval', 'leg' => 'implement', 'cycle' => 1, 'at' => '2026-09-29T11:00:00Z', 'reason' => 'needs a queue', 'outcome' => 'looped-back'];

it('derives autoflow\'s next design step from the spec, the plan and the newest ledger entry', function () {
    $approved = [...DESIGN_REVIEW_LOOP, 'outcome' => 'continued'];
    $reviewGap = [...DESIGN_GAP, 'leg' => 'review-plan'];
    $escalated = ['gate' => 'design-size', 'leg' => 'implement', 'at' => '2026-09-29T12:00:00Z', 'reason' => 'migration', 'outcome' => 'escalated'];

    expect(pipeline_step(design_manifest(null, null), 'design'))->toBe('spec');
    expect(pipeline_step(design_manifest('s.md', null), 'design'))->toBe('plan');
    expect(pipeline_step(design_manifest('s.md', 'p.md', [$approved]), 'design'))->toBe('spec');
    expect(pipeline_step(design_manifest('s.md', 'p.md', [DESIGN_REVIEW_LOOP]), 'design'))->toBe('spec');
    expect(pipeline_step(design_manifest('s.md', 'p.md', [DESIGN_GAP]), 'design'))->toBe('plan');
    expect(pipeline_step(design_manifest('s.md', 'p.md', [$reviewGap]), 'design'))->toBe('plan');
    expect(pipeline_step(design_manifest('s.md', 'p.md', [DESIGN_GAP, $approved]), 'design'))->toBe('spec');
    expect(pipeline_step(design_manifest('s.md', 'p.md', [$escalated]), 'design'))->toBe('spec');
    expect(pipeline_step(design_manifest(null, null, [DESIGN_GAP]), 'design'))->toBe('spec');
    expect(pipeline_step(design_manifest('s.md', null, [], 'interactive'), 'design'))->toBe('run');
});

it('reads a plan return as the entry of a plan-insufficient on an Architectural design, review-plan\'s included', function () {
    expect(pipeline_is_plan_return(DESIGN_GAP))->toBeTrue();
    expect(pipeline_is_plan_return([...DESIGN_GAP, 'leg' => 'review-plan']))->toBeTrue();
    expect(pipeline_is_plan_return(DESIGN_REVIEW_LOOP))->toBeFalse();
    expect(pipeline_is_plan_return([...DESIGN_GAP, 'outcome' => 'continued']))->toBeFalse();
    expect(pipeline_is_plan_return([...DESIGN_GAP, 'gate' => 'design-size', 'outcome' => 'escalated']))->toBeFalse();
    expect(pipeline_is_plan_return([]))->toBeFalse();
});

it('refuses the design step the manifest does not call for', function () {
    $refusal = fn (string $next, string $step) => "the manifest calls for the design {$next} step, not {$step}: the plan step follows a spec step that set artifacts.spec and removed artifacts.plan, or a plan-insufficient on an Architectural design";

    expect(pipeline_step_problem(design_manifest(null, null), 'design', 'spec'))->toBeNull();
    expect(pipeline_step_problem(design_manifest('s.md', null), 'design', 'plan'))->toBeNull();
    expect(pipeline_step_problem(design_manifest('s.md', 'p.md', [DESIGN_GAP]), 'design', 'plan'))->toBeNull();
    expect(pipeline_step_problem(design_manifest(null, null), 'design', 'plan'))->toBe($refusal('spec', 'plan'));
    expect(pipeline_step_problem(design_manifest('s.md', 'p.md', [DESIGN_REVIEW_LOOP]), 'design', 'plan'))->toBe($refusal('spec', 'plan'));
    expect(pipeline_step_problem(design_manifest('s.md', null), 'design', 'spec'))->toBe($refusal('plan', 'spec'));
    expect(pipeline_step_problem(design_manifest('s.md', null), 'design', 'run'))->toBe('design has no run step');
    expect(pipeline_step_problem(design_manifest('s.md', null, [], 'interactive'), 'design', 'run'))->toBeNull();
});
```

`DispatchCliTest.php`:

1. In *hands the autoflow script its routing tables, from the functions interactive mode routes by*, replace both `pipeline_steps($leg)` calls with `pipeline_steps($leg, 'autoflow')`, and add before the final `expect(array_keys($tables['allowed']))…` line:

```php
    expect($tables['bounded'])->toBe(PIPELINE_BOUNDED_STEPS);
    foreach ($tables['bounded'] as $leg => $bounded) {
        expect(array_diff($bounded, $tables['steps'][$leg]))->toBe([]);
    }
```

2. Add after that test:

```php
it('launches a design at the step the manifest calls for', function (?string $spec, string $step) {
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'design', 'status' => 'halted', 'reason' => 'x'], 'artifacts' => ['spec' => $spec, 'plan' => null, 'pr' => null, 'issue' => null]]);
    file_put_contents($fixture['dir'] . '/spec.md', "# x — design\n\n**Design size:** Architectural\n");

    expect(dispatch_cli(['launch', $fixture['manifest'], $fixture['diff']])['json']['startStep'])->toBe($step);
})->with([
    'no spec yet' => [null, 'spec'],
    'a spec whose plan is not written' => ['spec.md', 'plan'],
]);
```

3. In *halts the next brief naming the leg, the key and the entry when design writes onto the plan gap it answers (#104)*: change `boundary_fixture('design', 'run', ['gate_ledger' => [$approved, $gap]])` to `boundary_fixture('design', 'plan', ['gate_ledger' => [$approved, $gap], 'artifacts' => ['spec' => 'spec.md', 'plan' => 'plan.md', 'pr' => null, 'issue' => null]])`, and `'design:run'` in its `boundary_brief()` call to `'design:plan'`.

4. In *halts the next brief when the script was told another status or size than the step wrote*: change `boundary_fixture('design', 'run', …)` to `boundary_fixture('design', 'plan', …)` (its manifest has a spec and no plan, so `plan` is the step it calls for), `'design:run'` to `'design:plan'`, and the *another status* reason to `'the design plan step returned halted to the script but wrote continued into the manifest'`.

5. In *halts a brief told of a step that left no snapshot, not its own, or none*: change `boundary_fixture('design', 'run', …)` to `boundary_fixture('design', 'spec', …)` and its reason to `'the snapshot is of the design spec step, but the script says handoff run returned: it did not run brief'`.

6. In *refuses a brief it cannot parse*, dataset *a flag given twice*: `'design:run'` → `'design:spec'`. Add a row: `'an --after naming interactive\'s design step' => [['review-plan', 'review', '--after', 'design:run', '--status', 'continued']],`.

7. Add after *halts a step the ledger does not support, and leaves the manifest alone*:

```php
it('halts the brief of a design step the manifest does not call for, and leaves the manifest alone', function () {
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'design', 'status' => 'pending']]);

    expect(dispatch_cli(['brief', $fixture['manifest'], 'design', 'plan'])['json'])->toBe(['action' => 'halt', 'reason' => 'the manifest calls for the design spec step, not plan: the plan step follows a spec step that set artifacts.spec and removed artifacts.plan, or a plan-insufficient on an Architectural design']);
    expect(manifest_read($fixture['manifest'])['cursor'])->toBe(['leg' => 'design', 'status' => 'pending']);
});

it('briefs the plan step after a spec step that committed its spec', function () {
    $fixture = boundary_fixture('design', 'spec', ['cursor' => ['leg' => 'design', 'status' => 'pending']]);
    file_put_contents($fixture['dir'] . '/spec.md', "# x — design\n\n**Design size:** Architectural\n");
    dispatch_leg_writes($fixture['manifest'], fn (array $m) => [...$m, 'cursor' => [...$m['cursor'], 'status' => 'continued'], 'artifacts' => [...$m['artifacts'], 'spec' => 'spec.md']]);

    expect(boundary_brief($fixture, 'design', 'plan', 'design:spec', ['--status', 'continued', '--size', 'Architectural'])['stdout'])
        ->toContain('`design` leg, `plan` step')
        ->toContain('- spec: `spec.md`');
});
```

`BriefTest.php`: in *has overrides for every leg and step, in autoflow and interactive*, replace the inner `foreach (in_array($leg, ['review-plan', 'review-pr'], true) ? ['review', 'resolve'] : ['run'] as $step)` with `foreach (pipeline_steps($leg, $mode) as $step)`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='DispatchTest|DispatchCliTest|BriefTest'`
Expected: FAIL: `pipeline_steps()` is called with two arguments where it takes one (PHP ignores the extra one, so `pipeline_steps('design', 'autoflow')` returns `['run']`); `PIPELINE_BOUNDED_STEPS`, `pipeline_is_plan_return()` are undefined; `brief … design spec` halts with `design has no spec step`; `tables` has no `bounded`.

- [ ] **Step 3: Implement**

`pipeline.php`, after `pipeline_is_plan_gap()`:

```php
/**
 * The entry a step appends when it returns `plan-insufficient` on an Architectural design: a `plan-approval`
 * loop-back that no review wrote. Unlike `pipeline_is_plan_gap()` it counts `review-plan:review`'s own; a
 * `review-plan` loop-back is a resolved review and carries its `review`.
 */
function pipeline_is_plan_return(array $entry): bool
{
    return ($entry['gate'] ?? null) === 'plan-approval'
        && ($entry['outcome'] ?? null) === 'looped-back'
        && ! array_key_exists('review', $entry);
}
```

`dispatch.php`: replace `pipeline_steps()` with

```php
/** The legs whose steps differ on a Bounded design: its spec step commits the plan too (`../references/engine.md` §Design size). */
const PIPELINE_BOUNDED_STEPS = ['design' => ['spec']];

/** @return list<string> `autoflow` designs in a spec step and a plan step; `interactive` designs inline, in one */
function pipeline_steps(string $leg, string $mode): array
{
    return match (true) {
        in_array($leg, ['review-plan', 'review-pr'], true) => ['review', 'resolve'],
        $leg === 'design' && $mode === 'autoflow' => ['spec', 'plan'],
        default => ['run'],
    };
}
```

In `pipeline_routing_tables()`: the docblock's `@return` shape gains `bounded: array<string, list<string>>` and says "the steps a Bounded design runs instead"; replace

```php
    $steps = array_combine($legs, array_map(pipeline_steps(...), $legs));
```

with

```php
    $steps = array_combine($legs, array_map(fn (string $leg) => pipeline_steps($leg, 'autoflow'), $legs));
```

and add `'bounded' => PIPELINE_BOUNDED_STEPS,` after `'loopTarget' => …,` in the returned array.

Replace `pipeline_step()` (and its docblock) with

```php
/** `review` or `resolve` on the two review legs, derived from the ledger; `spec` or `plan` on `autoflow`'s design; `run` everywhere else. */
function pipeline_step(array $manifest, string $leg): string
{
    return match (pipeline_steps($leg, (string) ($manifest['mode'] ?? ''))) {
        ['run'] => 'run',
        ['spec', 'plan'] => pipeline_design_step($manifest),
        default => pipeline_open_entry(pipeline_ledger($manifest), pipeline_gate_of($leg)) === null ? 'review' : 'resolve',
    };
}

/**
 * `autoflow`'s next design step, read from the manifest before the step runs: `plan` once the spec step has
 * set `artifacts.spec` and removed `artifacts.plan`, or when the newest ledger entry is a plan return
 * (`pipeline_is_plan_return()`); `spec` otherwise — no spec yet, a `review-plan` loop-back, an escalation.
 */
function pipeline_design_step(array $manifest): string
{
    $artifacts = $manifest['artifacts'] ?? [];
    $ledger = pipeline_ledger($manifest);
    $planned = ! empty($artifacts['plan']) && ! pipeline_is_plan_return(end($ledger) ?: []);

    return empty($artifacts['spec']) || $planned ? 'spec' : 'plan';
}
```

In `pipeline_step_problem()`: the first check becomes

```php
    if (! in_array($leg, pipeline_legs(), true) || ! in_array($step, pipeline_steps($leg, (string) ($manifest['mode'] ?? '')), true)) {
```

and the `match` gains, before `default => null,`:

```php
        $leg === 'design' && $step !== pipeline_step($manifest, $leg) => 'the manifest calls for the design ' . pipeline_step($manifest, $leg) . " step, not {$step}: the plan step follows a spec step that set artifacts.spec and removed artifacts.plan, or a plan-insufficient on an Architectural design",
```

Update its docblock to "Why the ledger or the design's artifacts do not support running this step now (`brief`'s check), or null."

`dispatch_cli.php`, `dispatch_cli_brief_args()`: `in_array($step, pipeline_steps($leg), true)` → `in_array($step, pipeline_steps($leg, 'autoflow'), true)` (`brief` serves `autoflow` steps only).

Confirm no other caller: `grep -rn "pipeline_steps(" skills/` lists only `dispatch.php`, `dispatch_cli.php` and the tests.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php -l skills/pipeline/checks/dispatch.php && php -l skills/pipeline/checks/pipeline.php && ./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='DispatchTest|DispatchCliTest|BriefTest|ReturnedTest|LockStepTest|RunAuditTest'`
Expected: PASS. `AutoflowScriptTest` is not run here: its stubs still name `design:run`, which Task 3 changes together with the script.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/dispatch.php skills/pipeline/checks/pipeline.php skills/pipeline/checks/dispatch_cli.php skills/pipeline/checks/tests/DispatchTest.php skills/pipeline/checks/tests/DispatchCliTest.php skills/pipeline/checks/tests/BriefTest.php
git commit -m "feat(pipeline): autoflow's design has a spec step and a plan step, the next one derived from the manifest (#73)"
```

---

### Task 3: the script runs the design steps, skips the plan step on a Bounded design, and reruns only the plan step on a plan gap

**Files:**
- Modify: `skills/pipeline/workflow/pipeline-autoflow.js`
- Test: `skills/pipeline/checks/tests/AutoflowScriptTest.php`

**Interfaces:**
- Consumes: `tables.steps.design = ['spec', 'plan']`, `tables.bounded = {design: ['spec']}`, `launch`'s `startStep` (Task 2); the refusal text (*Global Constraints*).
- Produces: nothing later tasks call.

- [ ] **Step 1: Write the failing tests**

In `AutoflowScriptTest.php`:

1. `autoflow_start()` gains the plan:

```php
/** `launch`'s start answer for an autoflow run whose cursor is on `$leg`; `$spec` is the committed spec's text, `$plan` the plan's recorded path. */
function autoflow_start(string $leg, array $ledger = [], ?string $spec = null, ?string $plan = null): array
{
    $artifacts = ['spec' => $spec === null ? null : 'spec.md', 'plan' => $plan, 'pr' => null, 'issue' => null];
```

(the rest of the function unchanged).

2. *walks to done on launch's tables, through a loop-back at each gate*: the returns' `'design:run' => [$design, $design],` becomes `'design:spec' => [$design, $design], 'design:plan' => [$design, $design],` and the expected labels become

```php
        'design:spec', 'design:plan', 'review-plan:review', 'review-plan:resolve', 'design:spec', 'design:plan', 'review-plan:review', 'review-plan:resolve',
        'handoff:run', 'implement:run', 'verify-ui:run', 'implement:run', 'verify-ui:run',
        'review-pr:review', 'review-pr:resolve', 'implement:run', 'review-pr:review', 'review-pr:resolve',
```

3. *halts on launch's bound when plan gaps keep looping back to design*: `'design:run' => [$design, $design],` becomes `'design:spec' => [$design], 'design:plan' => [$design, $design],` and the expected labels become

```php
        'implement:run', 'design:plan', 'review-plan:review', 'review-plan:resolve', 'design:spec', 'design:plan',
        'review-plan:review', 'review-plan:resolve', 'handoff:run', 'implement:run',
```

4. *exempts one Bounded escalation and then counts on from the ledger's loop-backs*: `'design:run' =>` becomes `'design:spec' =>`, and the expected labels `['handoff:run', 'design:spec', 'review-plan:review']`.

5. *tells each brief what the step before it returned, and a retry the same*: `'design:run' => [$design, $design],` becomes `'design:spec' => [$design, $design], 'design:plan' => [$design, $design],` and the expected briefs become

```php
        'design spec',
        'design plan --after design:spec --status continued --size Architectural',
        'review-plan review --after design:plan --status continued --size Architectural',
        'review-plan review --after design:plan --status continued --size Architectural',
        'review-plan resolve --after review-plan:review --status continued',
        'design spec --after review-plan:resolve --status looped-back',
        'design plan --after design:spec --status continued --size Architectural',
        'review-plan review --after design:plan --status continued --size Architectural',
        'review-plan resolve --after review-plan:review --status continued',
        'handoff run --after review-plan:resolve --status continued',
        'implement run --after handoff:run --status continued',
        'verify-ui run --after implement:run --status continued --ui true',
```

6. *walks stub steps that write what their briefs ask to done, through a retried review (the replay smoke pass)*: the run starts at `design` with a committed spec and no plan, so `launch` starts it at the plan step. Add `expect($start['startStep'])->toBe('plan');` after `$start = …`; replace `'design:run' => [[...autoflow_writes('continued', ['last_sha' => 'aaa1111']), 'size' => 'Architectural']],` with `'design:plan' => [[...autoflow_writes('continued', ['last_sha' => 'aaa1111', 'artifacts' => ['spec' => 'spec.md', 'plan' => 'plan.md', 'pr' => null, 'issue' => null]]), 'size' => 'Architectural']],`; in the handoff return `'plan' => null` becomes `'plan' => 'plan.md'`; and the expected labels start with `'design:plan'` instead of `'design:run'`.

7. *halts before any agent when launch's tables are missing or incomplete*: add two dataset rows:

```php
    'no bounded table' => [function (array $start) { unset($start['tables']['bounded']); return $start; }],
    'a bounded step its leg does not have' => [function (array $start) { $start['tables']['bounded']['design'] = ['run']; return $start; }],
```

8. Add at the end of the file:

```php
const AUTOFLOW_STOP = ['status' => 'halted', 'reason' => 'stub stop'];

it('runs a Bounded design as its spec step alone', function () {
    $replay = autoflow_replay(autoflow_start('design'), [
        'design:spec' => [[...AUTOFLOW_C, 'size' => 'Bounded']],
        'review-plan:review' => [AUTOFLOW_STOP],
    ]);

    expect($replay['labels'])->toBe(['design:spec', 'review-plan:review']);
    expect(autoflow_briefs($replay['prompts'])[1])->toBe('review-plan review --after design:spec --status continued --size Bounded');
    expect($replay['result'])->toBe(['action' => 'halt', 'leg' => 'review-plan', 'reason' => 'stub stop']);
});

it('reruns only the plan step on a plan gap, and the spec step first on an escalation', function (string $leg, ?string $spec, array $returns, array $labels) {
    $replay = autoflow_replay(autoflow_start($leg, spec: $spec), [...$returns, 'review-plan:review' => [...($returns['review-plan:review'] ?? []), AUTOFLOW_STOP]]);

    expect($replay['labels'])->toBe($labels);
    expect($replay['result'])->toBe(['action' => 'halt', 'leg' => 'review-plan', 'reason' => 'stub stop']);
})->with([
    'a gap found by implement' => ['implement', null, [
        'implement:run' => [[...AUTOFLOW_PI, 'ui' => false]],
        'design:plan' => [[...AUTOFLOW_C, 'size' => 'Architectural']],
    ], ['implement:run', 'design:plan', 'review-plan:review']],
    'a gap found by review-plan\'s review' => ['review-plan', null, [
        'review-plan:review' => [AUTOFLOW_PI],
        'design:plan' => [[...AUTOFLOW_C, 'size' => 'Architectural']],
    ], ['review-plan:review', 'design:plan', 'review-plan:review']],
    'a Bounded escalation, grown to Architectural' => ['handoff', "# x — design\n\n**Design size:** Bounded\n", [
        'handoff:run' => [AUTOFLOW_PI],
        'design:spec' => [[...AUTOFLOW_C, 'size' => 'Architectural']],
        'design:plan' => [[...AUTOFLOW_C, 'size' => 'Architectural']],
    ], ['handoff:run', 'design:spec', 'design:plan', 'review-plan:review']],
]);

it('walks a plan gap through the real briefs to the plan step alone (the replay smoke pass)', function () {
    $start = autoflow_start('implement', spec: "# x — design\n\n**Design size:** Architectural\n", plan: 'plan.md');
    $gap = ['gate' => 'plan-approval', 'leg' => 'implement', 'cycle' => 1, 'at' => '2026-09-29T10:00:00Z', 'reason' => 'needs a queue', 'outcome' => 'looped-back'];

    $replay = autoflow_replay($start, [
        'implement:run' => [[...autoflow_writes('plan-insufficient', ['gate_ledger' => [$gap]]), 'reason' => 'needs a queue', 'diff' => '', 'ui' => false]],
        'design:plan' => [[...autoflow_writes('continued', ['last_sha' => 'aaa1111']), 'size' => 'Architectural']],
        'review-plan:review' => [[...AUTOFLOW_STOP, 'write' => ['cursor' => AUTOFLOW_STOP]]],
    ], steps: true);

    expect($replay['labels'])->toBe(['implement:run', 'design:plan', 'review-plan:review']);
    expect($replay['result'])->toBe(['action' => 'halt', 'leg' => 'review-plan', 'reason' => 'stub stop']);
});

it('reruns the spec step and then the plan step on a review loop-back, whose brief refuses a kept plan (the replay smoke pass)', function (bool $removes, array $labels, array $result) {
    $start = autoflow_start('review-plan', spec: "# x — design\n\n**Design size:** Architectural\n", plan: 'plan.md');
    $open = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-29T10:00:00Z', 'review' => 'r', 'annotations' => []];
    $artifacts = ['spec' => 'spec.md', 'plan' => $removes ? null : 'plan.md', 'pr' => null, 'issue' => null];

    $replay = autoflow_replay($start, [
        'review-plan:review' => [autoflow_writes('continued', ['gate_ledger' => [$open]]), [...AUTOFLOW_STOP, 'write' => ['cursor' => AUTOFLOW_STOP]]],
        'review-plan:resolve' => [autoflow_writes('looped-back', ['gate_ledger' => [[...$open, 'actions' => [], 'outcome' => 'looped-back']]])],
        'design:spec' => [[...autoflow_writes('continued', ['artifacts' => $artifacts]), 'size' => 'Architectural']],
        'design:plan' => [[...autoflow_writes('continued', ['artifacts' => [...$artifacts, 'plan' => 'plan.md']]), 'size' => 'Architectural']],
    ], steps: true);

    expect($replay['labels'])->toBe($labels);
    expect($replay['result'])->toBe($result);
})->with([
    'the spec step removed artifacts.plan' => [
        true,
        ['review-plan:review', 'review-plan:resolve', 'design:spec', 'design:plan', 'review-plan:review'],
        ['action' => 'halt', 'leg' => 'review-plan', 'reason' => 'stub stop'],
    ],
    'the spec step kept the old plan' => [
        false,
        ['review-plan:review', 'review-plan:resolve', 'design:spec', 'design:plan'],
        ['action' => 'halt', 'leg' => 'design', 'reason' => 'the manifest calls for the design spec step, not plan: the plan step follows a spec step that set artifacts.spec and removed artifacts.plan, or a plan-insufficient on an Architectural design'],
    ],
]);
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='AutoflowScriptTest'`
Expected: FAIL. The Architectural walks and the brief list already pass (Task 2's tables give `design` `['spec', 'plan']`); these fail: *runs a Bounded design as its spec step alone* (the script runs `design:plan` after a Bounded spec: `no return scripted for design:plan`, a halt), every *reruns only the plan step* row (the script reruns `design:spec` first: no return scripted for it), both smoke cases (`design:spec` / `design:plan` order), *exempts one Bounded escalation* (a `design:plan` after the Bounded `design:spec`), and the two new incomplete-tables rows (the script ignores `bounded`, so it starts an agent).

- [ ] **Step 3: Implement**

In `pipeline-autoflow.js`:

`complete()`:

```js
function complete(tables) {
  const { legs, steps, loopTarget, allowed, bound, bounded } = tables ?? {}
  const filled = list => Array.isArray(list) && list.length > 0
  return filled(legs)
    && legs.every(leg => filled(steps?.[leg]) && steps[leg].every(step => filled(allowed?.[`${leg}:${step}`])))
    && typeof loopTarget === 'object' && loopTarget !== null && Object.entries(loopTarget).every(([from, to]) => legs.includes(from) && legs.includes(to))
    && typeof bounded === 'object' && bounded !== null && Object.entries(bounded).every(([leg, list]) => legs.includes(leg) && filled(list) && list.every(step => steps[leg].includes(step)))
    && Number.isInteger(bound)
}
```

After `nextLeg()`, add:

```js
// A Bounded design runs fewer steps (tables.bounded): its spec step commits the plan too. Read after every
// step, since the spec step's size decides whether the plan step runs.
function stepsOf(leg) {
  return (size === 'Bounded' && bounded[leg]) || steps[leg]
}
```

Replace everything from `const { legs, steps, loopTarget, allowed, bound } = args.tables` to the end of the file with:

```js
const { legs, steps, loopTarget, allowed, bound, bounded } = args.tables
if (!legs.includes(args.startLeg) || !('review-plan' in loopTarget)) return halt(args.startLeg ?? 'launch', 'args are not a launch start answer') // a plan gap is charged to loops['review-plan']

const loops = { ...Object.fromEntries(Object.keys(loopTarget).map(gate => [gate, 0])), ...args.loops }
let ui = args.ui
let size = args.size
let exempted = false
let leg = args.startLeg
let from = args.startStep
let last
while (leg) {
  let index = from ? stepsOf(leg).indexOf(from) : 0
  if (index < 0) return halt(leg, `${leg} has no ${from} step`)
  from = undefined
  let result
  for (; index < stepsOf(leg).length; index++) {
    const step = stepsOf(leg)[index]
    result = await runStep(leg, step)
    last = { ...result, leg, step }
    log(`${leg}:${step} ${result.status}${result.reason ? `: ${result.reason}` : ''}`)
    if (result.status !== 'continued') break
    if (leg === 'design') size = result.size
  }
  if (result.status === 'halted') return halt(leg, result.reason)
  if (leg === 'implement') ui = result.ui
  if (result.status === 'continued') {
    leg = nextLeg(leg)
    continue
  }

  const gap = result.status === 'plan-insufficient'
  if (!gap && result.status !== 'looped-back') return halt(leg, `unknown status ${result.status}`)
  const target = gap ? 'design' : loopTarget[leg]
  if (!target) return halt(leg, `no loop-back from ${leg}`)
  const counted = !(gap && size === 'Bounded' && !exempted) // a Bounded escalation is not a loop-back; escalation is one-way, so once per run
  if (!counted) exempted = true
  const gate = gap ? 'review-plan' : leg
  if (counted && ++loops[gate] > bound) return halt(leg, `${gate}: loop-back bound exhausted`)
  if (gap && size !== 'Bounded') from = 'plan' // a plan gap reruns only the plan step; a review loop-back and an escalation rerun the spec step first
  leg = target
}
return { action: 'done' }
```

This drops the separate start-step check (`if (args.startStep && !steps[…].includes(…))`): the loop's first `index < 0` gives the same halt, before any agent, now against `stepsOf()`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='AutoflowScriptTest'`
Expected: PASS, including *halts a start step its leg does not have, before any agent* unchanged (`handoff has no resolve step`, no labels).

Then the whole suite: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/workflow/pipeline-autoflow.js skills/pipeline/checks/tests/AutoflowScriptTest.php
git commit -m "feat(pipeline): the autoflow script runs design:spec then design:plan, the spec step alone on a Bounded design (#73)"
```

---

### Task 4: the docs say how `autoflow` designs

**Files:**
- Modify: `skills/pipeline/references/engine.md` (§`autoflow`, §Stations, §Design size), `skills/pipeline/references/manifest.md` (the `artifacts` row)

- [ ] **Step 1: engine.md §`autoflow`, the step bullet**

Replace

```
  its diff), and `design` returns `size`, copied from `dispatch_cli.php size <manifest>`
  (`DesignSize::fromSpec()` over the spec it committed). Both are required on every return of their
  step and ignored on a halt; the script takes `ui` only from `implement` and `size` only from `design`.
```

with

```
  its diff), and each `design` step returns `size`, copied from `dispatch_cli.php size <manifest>`
  (`DesignSize::fromSpec()` over the committed spec). Both are required on every return of their
  step and ignored on a halt; the script takes `ui` only from `implement` and `size` only from `design`,
  after each design step: the spec step's size decides whether the plan step runs (§Design size,
  *`autoflow`'s design*).
```

and in the same bullet, `support the step (`resolve` with no open review, `review` with one already open)` becomes `support the step (`resolve` with no open review, `review` with one already open, the design step the manifest does not call for)`.

- [ ] **Step 2: engine.md §Stations, the design row's *Autonomous form* cell**

Replace the cell text `a subagent turns a tight brief into a spec **and must write the questions it would have asked plus its assumed answers into the spec**, so `/critique plan` audits exactly those assumptions. The brief says which path is permitted: Bounded only with `light`, otherwise Architectural` with

```
two steps (`pipeline_steps()`): a **spec** agent turns a tight brief into a spec **and must write the questions it would have asked plus its assumed answers into the spec**, so `/critique plan` audits exactly those assumptions; a fresh **plan** agent reads the committed spec cold and writes the plan. A Bounded design is the spec step alone (§Design size, *`autoflow`'s design*). The brief says which path is permitted: Bounded only with `light`, otherwise Architectural
```

- [ ] **Step 3: engine.md §Design size, a new subsection**

Insert before `### What a Bounded design commits`:

```markdown
### `autoflow`'s design — a spec step and a plan step

In `autoflow` the `design` leg is two steps (`pipeline_steps()`), so the agent that writes the plan reads
the committed spec cold, and the spec agent's exploration does not ride along into the plan (#73):

- **`design:spec`** brainstorms, commits the spec, sets `artifacts.spec` and removes `artifacts.plan`: a
  plan written for an earlier spec is not this spec's plan. It stops where brainstorming hands over to
  `writing-plans`. On the Bounded path it commits the plan as well and sets `artifacts.plan`: a Bounded
  design is this step alone (`PIPELINE_BOUNDED_STEPS`), and the script skips `design:plan` on the `size`
  the spec step returned.
- **`design:plan`** reads the spec and the code it points at, invokes `writing-plans`, commits the plan
  and sets `artifacts.plan`. An answer the plan needs and the spec does not give goes into the spec's
  `## Assumptions`, committed before the plan.

Both return `size`. The next design step is read from the manifest, as `review` / `resolve` is
(`pipeline_design_step()`): `plan` when the spec is set and the plan is not, or when the newest ledger
entry is a plan return (a `plan-approval` loop-back with no `review`); `spec` otherwise. `launch` starts
there, and `brief` refuses the other step. On a loop-back the script reruns:

| what sent the run back | the script reruns |
|---|---|
| `plan-insufficient` on an Architectural design (a plan gap; `review-plan:review`'s counts too) | `design:plan` only |
| `looped-back` from `review-plan:resolve` | `design:spec`, then `design:plan` |
| `plan-insufficient` on a Bounded design (an escalation) | `design:spec`, which grows the spec, then `design:plan` |

`interactive` keeps one `design:run` step: the human designs inline, in one session.
```

- [ ] **Step 4: engine.md, the grow form and the plan gap**

In *Escalation — the design grows, one way*, step 2, after the line `   - commit the spec, then the plan.` add:

```
   In `autoflow` the spec step grows the spec and the plan step adds the remaining steps
   (*`autoflow`'s design*).
```

In *A plan gap on an Architectural design*, step 3, replace `   (updating the existing PR) and `implement` run again, as after an escalation.` with

```
   (updating the existing PR) and `implement` run again, as after an escalation. In `autoflow` only
   `design:plan` reruns (*`autoflow`'s design*).
```

- [ ] **Step 5: manifest.md, the `artifacts` row**

Replace `| `artifacts` | optional | pointers: idea, spec path, plan path, PR number, issue number (`engine.md` §The work item), `proof` — the proof page `verify-ui` wrote |` with

```
| `artifacts` | optional | pointers: idea, spec path, plan path, PR number, issue number (`engine.md` §The work item), `proof` — the proof page `verify-ui` wrote. In `autoflow` the design's spec step removes `plan` and the plan step sets it again, so an empty `plan` beside a `spec` means the plan step is next (`engine.md` §Design size, *`autoflow`'s design*) |
```

- [ ] **Step 6: Verify and commit**

Run: `grep -n "design:run\|design run" skills/pipeline/references/*.md skills/pipeline/SKILL.md`
Expected: only the new subsection's `interactive` line names `design:run`.

Run the whole suite: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS (`LockStepTest` still finds every `§` heading the briefs name).

```bash
git add skills/pipeline/references/engine.md skills/pipeline/references/manifest.md
git commit -m "docs(pipeline): autoflow designs in a spec step and a plan step (#73)"
```
