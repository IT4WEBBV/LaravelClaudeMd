# Pipeline dispatcher loop Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the `/pipeline auto` engine into a pure dispatcher whose steps, briefs and return checks are tested PHP, and commit a script that checks its peak context against 150k after every run.

**Architecture:** Pure decision functions in `skills/pipeline/checks/dispatch.php` (steps, statuses, the fail-closed return check, routing) and `brief.php` (generated briefs); one impure CLI, `dispatch_cli.php`, that the dispatcher calls once per step; `engine_peak.php` + `engine_peak_cli.php` for the invariant. The reference docs are rewritten to match, with a Pest lock-step test tying `manifest.md` and `gates.md` to the PHP.

**Tech Stack:** PHP 8.4 on the host, Pest 4 (`./vendor/bin/pest`), Markdown skill references.

**Spec:** docs/superpowers/specs/2026-09-22-pipeline-dispatcher-loop-design.md

## Global Constraints

- Work only in `/Users/jroelofs/GitProjects/LaravelClaudeMd/LaravelClaudeMd/.claude/worktrees/pipeline-dispatcher-loop`. `~/.claude/skills/pipeline` is a symlink to the MAIN checkout; never edit through it.
- Pipeline suite: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`. Critique suite: `./vendor/bin/pest -c skills/critique/checks/phpunit.xml --test-directory=skills/critique/checks/tests`. Both run on the host from the worktree root.
- Test-first: every new function's test is written first and seen red. That is its proof; no other test policy.
- Settled decisions a–f in the spec are not reopened; the rejected alternatives are not re-proposed.
- `~/.claude/token-audit/2026-09-22/*.py` are read, never modified.
- The repo has no changelog; none is added.
- Commits: no `Co-Authored-By`, no AI attribution, nothing addressed to a person.
- Leftover worktrees `.claude/worktrees/proof-short-titles`, `../lcm-proof-open`, `../wt-gitignore` are not touched.
- Invariant: dispatcher peak context under **150000** tokens (`PIPELINE_ENGINE_PEAK_LIMIT`); an annotation, never a halt.

## File structure

| File | Responsibility |
|---|---|
| `skills/pipeline/checks/dispatch.php` (new) | `LegStatus`, gate/loop maps, `pipeline_step`, `pipeline_runs_inline`, `pipeline_leg_writable_keys`, `pipeline_returned` and its helpers. Pure |
| `skills/pipeline/checks/brief.php` (new) | `pipeline_leg_overrides`, `pipeline_brief` and its section helpers. Pure |
| `skills/pipeline/checks/dispatch_cli.php` (new) | `next` / `returned`: reads and writes the manifest, snapshot and brief file; prints one JSON line |
| `skills/pipeline/checks/engine_peak.php` (new) | `pipeline_transcript_peak`, `pipeline_find_transcript`, `pipeline_engine_peak_line`. Pure except the glob |
| `skills/pipeline/checks/engine_peak_cli.php` (new) | `<agent-id> [--projects-dir DIR]` → one line, exit 0 |
| `skills/pipeline/checks/tests/Pest.php` | also loads `dispatch.php`, `brief.php`, `engine_peak.php` (never the CLIs, which run on require) |
| `skills/pipeline/checks/tests/{DispatchTest,ReturnedTest,BriefTest,DispatchCliTest,EnginePeakTest,LockStepTest}.php` (new) | one per unit above |
| `skills/pipeline/references/{manifest,gates,engine}.md`, `skills/pipeline/SKILL.md`, `skills/orchestrate/SKILL.md` | text, per spec §6–§10 |

---

### Task 1: Steps, statuses and the gate maps

**Files:**
- Create: `skills/pipeline/checks/dispatch.php`
- Modify: `skills/pipeline/checks/tests/Pest.php:6`
- Test: `skills/pipeline/checks/tests/DispatchTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `enum LegStatus: string` (`Continued`, `LoopedBack`, `Halted`, `PlanInsufficient`) with `static allowedFor(string $leg, string $step): list<LegStatus>`; `pipeline_gate_of(string $leg): ?string`; `pipeline_leg_of_gate(string $gate): ?string`; `pipeline_loop_target(string $leg): ?string`; `pipeline_is_open(mixed $entry): bool` (false for a malformed entry, never a throw); `pipeline_open_entry(array $ledger, ?string $gate): ?int`; `pipeline_step(array $manifest, string $leg): string` (`run` | `review` | `resolve`); `pipeline_runs_inline(string $mode, string $leg, string $step): bool`; `pipeline_leg_writable_keys(): list<string>`.

- [ ] **Step 1: Load the new files in the Pest bootstrap**

In `skills/pipeline/checks/tests/Pest.php`, extend the list on line 6 (the bootstrap skips files that do not exist yet, so later tasks' red stays an undefined-function failure):

```php
foreach (['triggers.php', 'pipeline.php', 'manifest.php', 'checks.php', 'board.php', 'proof.php', 'proof_render.php', 'design_size.php', 'suite.php', 'dispatch.php', 'brief.php', 'engine_peak.php'] as $f) {
```

- [ ] **Step 2: Write the failing test**

`skills/pipeline/checks/tests/DispatchTest.php`:

```php
<?php

function dispatch_manifest(string $leg, array $ledger = []): array
{
    return ['branch' => 'feature/x', 'worktree' => '/tmp/wt', 'mode' => 'auto', 'cursor' => ['leg' => $leg, 'status' => 'pending'], 'gate_ledger' => $ledger];
}

it('names the gate each gate leg writes, and back', function () {
    expect(pipeline_gate_of('review-plan'))->toBe('plan-approval');
    expect(pipeline_gate_of('review-pr'))->toBe('pr-review');
    expect(pipeline_gate_of('verify-ui'))->toBe('verify-ui');
    expect(pipeline_gate_of('implement'))->toBeNull();
    expect(pipeline_leg_of_gate('pr-review'))->toBe('review-pr');
    expect(pipeline_leg_of_gate('design-size'))->toBeNull();
});

it('loops each looping leg back to where the work is redone', function () {
    expect(pipeline_loop_target('review-plan'))->toBe('design');
    expect(pipeline_loop_target('verify-ui'))->toBe('implement');
    expect(pipeline_loop_target('review-pr'))->toBe('implement');
    expect(pipeline_loop_target('handoff'))->toBeNull();
});

it('derives review or resolve from the open ledger entry', function () {
    $open = ['gate' => 'plan-approval', 'review' => 'Step 4 drops the link.'];

    expect(pipeline_step(dispatch_manifest('review-plan'), 'review-plan'))->toBe('review');
    expect(pipeline_step(dispatch_manifest('review-plan', [$open]), 'review-plan'))->toBe('resolve');
    expect(pipeline_step(dispatch_manifest('review-plan', [[...$open, 'outcome' => 'looped-back']]), 'review-plan'))->toBe('review');
    expect(pipeline_step(dispatch_manifest('review-pr', [$open]), 'review-pr'))->toBe('review');
    expect(pipeline_step(dispatch_manifest('implement', [$open]), 'implement'))->toBe('run');
});

it('runs design and the resolve step inline outside auto', function () {
    expect(pipeline_runs_inline('interactive', 'design', 'run'))->toBeTrue();
    expect(pipeline_runs_inline('interactive', 'review-pr', 'resolve'))->toBeTrue();
    expect(pipeline_runs_inline('interactive', 'review-pr', 'review'))->toBeFalse();
    expect(pipeline_runs_inline('interactive', 'implement', 'run'))->toBeFalse();
    expect(pipeline_runs_inline('mangled', 'design', 'run'))->toBeTrue();
    expect(pipeline_runs_inline('auto', 'design', 'run'))->toBeFalse();
    expect(pipeline_runs_inline('auto', 'review-plan', 'resolve'))->toBeFalse();
});

it('allows each step only the statuses it can honestly return', function () {
    $values = fn (string $leg, string $step) => array_map(fn (LegStatus $status) => $status->value, LegStatus::allowedFor($leg, $step));

    expect($values('design', 'run'))->toBe(['continued', 'halted']);
    expect($values('review-plan', 'review'))->toBe(['continued', 'halted', 'plan-insufficient']);
    expect($values('review-pr', 'resolve'))->toBe(['continued', 'looped-back', 'halted', 'plan-insufficient']);
    expect($values('verify-ui', 'run'))->toBe(['continued', 'looped-back', 'halted', 'plan-insufficient']);
    expect($values('implement', 'run'))->toBe(['continued', 'halted', 'plan-insufficient']);
});

it('lets a leg write only its results', function () {
    expect(pipeline_leg_writable_keys())->toBe(['artifacts', 'last_sha', 'suite', 'gate_ledger', 'cursor.status', 'cursor.reason']);
});
```

- [ ] **Step 3: Run it to see it fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter=DispatchTest`
Expected: FAIL, `Call to undefined function pipeline_gate_of()` (and `Class "LegStatus" not found`).

- [ ] **Step 4: Write the implementation**

`skills/pipeline/checks/dispatch.php`:

```php
<?php

/**
 * The dispatcher's decisions (`../references/engine.md` §The loop). Pure: `dispatch_cli.php` reads and
 * writes the manifest; these functions only decide which step comes next and whether a return holds.
 */

enum LegStatus: string
{
    case Continued = 'continued';
    case LoopedBack = 'looped-back';
    case Halted = 'halted';
    case PlanInsufficient = 'plan-insufficient';

    /** @return list<self> */
    public static function allowedFor(string $leg, string $step): array
    {
        return match (true) {
            $leg === 'design' => [self::Continued, self::Halted],
            $step === 'resolve', $leg === 'verify-ui' => [self::Continued, self::LoopedBack, self::Halted, self::PlanInsufficient],
            default => [self::Continued, self::Halted, self::PlanInsufficient],
        };
    }
}

const PIPELINE_GATE_OF = ['review-plan' => 'plan-approval', 'review-pr' => 'pr-review', 'verify-ui' => 'verify-ui'];

function pipeline_gate_of(string $leg): ?string
{
    return PIPELINE_GATE_OF[$leg] ?? null;
}

function pipeline_leg_of_gate(string $gate): ?string
{
    $leg = array_search($gate, PIPELINE_GATE_OF, true);

    return $leg === false ? null : $leg;
}

function pipeline_loop_target(string $leg): ?string
{
    return ['review-plan' => 'design', 'verify-ui' => 'implement', 'review-pr' => 'implement'][$leg] ?? null;
}

/** A review written and not yet acted on: it has a `review` and no `outcome`. */
function pipeline_is_open(mixed $entry): bool
{
    return is_array($entry) && array_key_exists('review', $entry) && ! array_key_exists('outcome', $entry);
}

function pipeline_open_entry(array $ledger, ?string $gate): ?int
{
    foreach ($ledger as $index => $entry) {
        if ($gate !== null && ($entry['gate'] ?? null) === $gate && pipeline_is_open($entry)) {
            return $index;
        }
    }

    return null;
}

/** `review` or `resolve` on the two review legs, derived from the ledger; `run` everywhere else. */
function pipeline_step(array $manifest, string $leg): string
{
    if (! in_array($leg, ['review-plan', 'review-pr'], true)) {
        return 'run';
    }

    return pipeline_open_entry($manifest['gate_ledger'] ?? [], pipeline_gate_of($leg)) === null ? 'review' : 'resolve';
}

/** Anything that is not `auto` behaves as interactive (`gates.md` §Modes): the human designs and resolves. */
function pipeline_runs_inline(string $mode, string $leg, string $step): bool
{
    return $mode !== 'auto' && ($leg === 'design' || $step === 'resolve');
}

/** @return list<string> the only manifest keys a leg may change; `cursor.*` is one level down */
function pipeline_leg_writable_keys(): array
{
    return ['artifacts', 'last_sha', 'suite', 'gate_ledger', 'cursor.status', 'cursor.reason'];
}
```

- [ ] **Step 5: Run it to see it pass, then the whole suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, 110 tests (104 existing + 6).

- [ ] **Step 6: Commit**

```bash
git add skills/pipeline/checks/dispatch.php skills/pipeline/checks/tests/DispatchTest.php skills/pipeline/checks/tests/Pest.php
git commit -m "pipeline: steps, leg statuses and gate maps for the dispatcher"
```

---

### Task 2: The fail-closed return check and routing — `pipeline_returned()`

**Files:**
- Modify: `skills/pipeline/checks/dispatch.php` (append)
- Test: `skills/pipeline/checks/tests/ReturnedTest.php`

**Interfaces:**
- Consumes: Task 1's functions; `manifest_validate()` (`manifest.php`); `pipeline_next_leg()` (`pipeline.php`); `DesignSize` (`design_size.php`).
- Produces: `pipeline_returned(array $before, array $after, array $triggers, DesignSize $size): array` returning `['action' => 'dispatch', 'leg' => string]`, `['action' => 'retry']`, `['action' => 'halt', 'reason' => string]` or `['action' => 'done']`. Helpers `pipeline_dispatch(string $leg): array`, `pipeline_halt(string $reason): array`.

- [ ] **Step 1: Write the failing test**

`skills/pipeline/checks/tests/ReturnedTest.php`:

```php
<?php

$noUi = ['package' => false, 'migration' => false, 'auth' => false, 'ui' => false];
$ui = [...$noUi, 'ui' => true];
$open = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'Step 4 drops the link.'];

function returned_before(string $leg, array $ledger = [], array $cursor = []): array
{
    return [
        'branch' => 'feature/x', 'worktree' => '/tmp/wt', 'mode' => 'auto',
        'cursor' => ['leg' => $leg, 'status' => 'pending', ...$cursor],
        'artifacts' => ['spec' => 'docs/spec.md', 'plan' => 'docs/plan.md', 'pr' => null, 'issue' => null],
        'last_sha' => 'aaa1111', 'gate_ledger' => $ledger,
    ];
}

/** The manifest as a leg returns it: a status, the ledger it left, anything else it changed. */
function returned_after(array $before, string $status, ?array $ledger = null, array $changes = [], ?string $reason = null): array
{
    $cursor = [...$before['cursor'], 'status' => $status];
    if ($reason !== null) {
        $cursor['reason'] = $reason;
    }

    return [...$before, 'cursor' => $cursor, 'gate_ledger' => $ledger ?? $before['gate_ledger'], ...$changes];
}

it('sends a review step on to its resolve step', function () use ($noUi, $open) {
    $before = returned_before('review-plan');

    expect(pipeline_returned($before, returned_after($before, 'continued', [$open]), $noUi, DesignSize::Architectural))
        ->toBe(['action' => 'dispatch', 'leg' => 'review-plan']);
});

it('moves to the next leg once a resolve step continues, and finishes after review-pr', function () use ($noUi, $open) {
    $before = returned_before('review-plan', [$open]);
    $resolved = [[...$open, 'actions' => [], 'outcome' => 'continued']];
    expect(pipeline_returned($before, returned_after($before, 'continued', $resolved), $noUi, DesignSize::Architectural))
        ->toBe(['action' => 'dispatch', 'leg' => 'handoff']);

    $prOpen = [...$open, 'gate' => 'pr-review', 'leg' => 'review-pr'];
    $before = returned_before('review-pr', [$prOpen]);
    expect(pipeline_returned($before, returned_after($before, 'continued', [[...$prOpen, 'outcome' => 'continued']]), $noUi, DesignSize::Architectural))
        ->toBe(['action' => 'done']);
});

it('steps over verify-ui unless ui fired', function () use ($noUi, $ui) {
    $before = returned_before('implement');
    $after = returned_after($before, 'continued', null, ['last_sha' => 'bbb2222']);

    expect(pipeline_returned($before, $after, $noUi, DesignSize::Architectural))->toBe(['action' => 'dispatch', 'leg' => 'review-pr']);
    expect(pipeline_returned($before, $after, $ui, DesignSize::Architectural))->toBe(['action' => 'dispatch', 'leg' => 'verify-ui']);
});

it('loops back twice and halts on the third', function () use ($noUi, $open) {
    $looped = [...$open, 'outcome' => 'looped-back'];

    $before = returned_before('review-plan', [$looped, $open]);
    expect(pipeline_returned($before, returned_after($before, 'looped-back', [$looped, $looped]), $noUi, DesignSize::Architectural))
        ->toBe(['action' => 'dispatch', 'leg' => 'design']);

    $before = returned_before('review-plan', [$looped, $looped, $open]);
    $decision = pipeline_returned($before, returned_after($before, 'looped-back', [$looped, $looped, $looped]), $noUi, DesignSize::Architectural);
    expect($decision['action'])->toBe('halt');
    expect($decision['reason'])->toContain('bound exhausted');
});

it('permits no loop-back once the count is unknown', function () use ($noUi, $open) {
    $unknown = [...$open, 'cycle' => 'unknown'];
    $before = returned_before('review-plan', [$unknown]);
    $decision = pipeline_returned($before, returned_after($before, 'looped-back', [[...$unknown, 'outcome' => 'looped-back']]), $noUi, DesignSize::Architectural);

    expect($decision['action'])->toBe('halt');
    expect($decision['reason'])->toContain('unknown');
});

it('loops verify-ui back to implement on its own thin entry', function () use ($ui) {
    $before = returned_before('verify-ui');
    $entry = ['gate' => 'verify-ui', 'cycle' => 1, 'at' => '2026-09-22T11:00:00Z', 'outcome' => 'looped-back'];

    expect(pipeline_returned($before, returned_after($before, 'looped-back', [$entry]), $ui, DesignSize::Architectural))
        ->toBe(['action' => 'dispatch', 'leg' => 'implement']);
    expect(pipeline_returned($before, returned_after($before, 'continued'), $ui, DesignSize::Architectural)['action'])->toBe('halt');
});

it('grows a Bounded design and halts an Architectural one on plan-insufficient', function () use ($noUi) {
    $before = returned_before('implement');
    $escalated = ['gate' => 'design-size', 'leg' => 'implement', 'at' => '2026-09-22T12:00:00Z', 'reason' => 'migration', 'outcome' => 'escalated'];
    $after = returned_after($before, 'plan-insufficient', [$escalated], [], 'migration');

    expect(pipeline_returned($before, $after, $noUi, DesignSize::Bounded))->toBe(['action' => 'dispatch', 'leg' => 'design']);
    expect(pipeline_returned($before, $after, $noUi, DesignSize::Architectural))->toBe(['action' => 'halt', 'reason' => 'plan insufficient: migration']);

    $withoutEntry = returned_after($before, 'plan-insufficient', null, [], 'migration');
    expect(pipeline_returned($before, $withoutEntry, $noUi, DesignSize::Bounded)['reason'])->toContain('design-size');
});

it('halts with the leg\'s own reason, and refuses a halt without one', function () use ($noUi) {
    $before = returned_before('implement');

    expect(pipeline_returned($before, returned_after($before, 'halted', null, [], 'the stack will not start'), $noUi, DesignSize::Architectural))
        ->toBe(['action' => 'halt', 'reason' => 'the stack will not start']);
    expect(pipeline_returned($before, returned_after($before, 'halted'), $noUi, DesignSize::Architectural)['reason'])
        ->toContain('without a reason');
});

it('retries an empty review once, then halts; any other empty return halts at once', function () use ($noUi) {
    $before = returned_before('review-plan');
    expect(pipeline_returned($before, $before, $noUi, DesignSize::Architectural))->toBe(['action' => 'retry']);

    $retried = returned_before('review-plan', [], ['retried' => true]);
    expect(pipeline_returned($retried, $retried, $noUi, DesignSize::Architectural)['action'])->toBe('halt');

    $implement = returned_before('implement');
    expect(pipeline_returned($implement, $implement, $noUi, DesignSize::Architectural)['action'])->toBe('halt');
});

it('halts on every return it cannot account for', function (array $after, string $reason) use ($noUi) {
    $decision = pipeline_returned(returned_before('review-plan', [['gate' => 'plan-approval', 'review' => 'old', 'outcome' => 'continued']]), $after, $noUi, DesignSize::Architectural);

    expect($decision['action'])->toBe('halt');
    expect($decision['reason'])->toContain($reason);
})->with(function () {
    $old = ['gate' => 'plan-approval', 'review' => 'old', 'outcome' => 'continued'];
    $before = returned_before('review-plan', [$old]);
    $open = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 2, 'at' => '2026-09-22T13:00:00Z', 'review' => 'new'];

    return [
        'a moved cursor' => [returned_after($before, 'continued', [$old, $open], ['cursor' => ['leg' => 'handoff', 'status' => 'continued']]), 'cursor.leg'],
        'a dispatcher key' => [returned_after($before, 'continued', [$old, $open], ['mode' => 'interactive']), 'mode'],
        'a new top-level key' => [returned_after($before, 'continued', [$old, $open], ['notes' => 'x']), 'notes'],
        'a rewritten entry' => [returned_after($before, 'continued', [[...$old, 'review' => 'edited'], $open]), 'ledger entry 0'],
        'an unknown status' => [returned_after($before, 'done', [$old, $open]), 'not a leg status'],
        'a status the step cannot return' => [returned_after($before, 'looped-back', [$old, $open]), 'cannot return looped-back'],
        'a lost required key' => [array_diff_key(returned_after($before, 'continued', [$old, $open]), ['worktree' => true]), 'worktree'],
        'a review without its entry' => [returned_after($before, 'continued', [$old]), 'one open plan-approval entry'],
    ];
});

it('tolerates a leg that reorders keys or drops cursor.retried', function () use ($noUi, $open) {
    $before = returned_before('review-plan', [], ['retried' => true]);
    $after = returned_after($before, 'continued', [array_reverse($open, true)]);
    $after = array_reverse([...$after, 'cursor' => ['status' => 'continued', 'leg' => 'review-plan']], true);

    expect(pipeline_returned($before, $after, $noUi, DesignSize::Architectural))
        ->toBe(['action' => 'dispatch', 'leg' => 'review-plan']);
});

it('halts when a resolve step sets an outcome other than its status', function () use ($noUi, $open) {
    $before = returned_before('review-plan', [$open]);
    $decision = pipeline_returned($before, returned_after($before, 'continued', [[...$open, 'outcome' => 'looped-back']]), $noUi, DesignSize::Architectural);

    expect($decision['reason'])->toContain('outcome to continued');
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter=ReturnedTest`
Expected: FAIL, `Call to undefined function pipeline_returned()`.

- [ ] **Step 3: Write the implementation**

Append to `skills/pipeline/checks/dispatch.php`:

```php
/**
 * What the dispatcher does after a step returns (spec §3). Fail-closed: a return the checks cannot
 * account for halts the run and says why.
 *
 * @return array{action: string, leg?: string, reason?: string}
 */
function pipeline_returned(array $before, array $after, array $triggers, DesignSize $size): array
{
    [$before, $after] = [pipeline_normalized($before), pipeline_normalized(pipeline_keep_retried($before, $after))];
    $leg = $before['cursor']['leg'];
    $step = pipeline_step($before, $leg);

    if ($after === $before) {
        return $step === 'review' && empty($before['cursor']['retried'])
            ? ['action' => 'retry']
            : pipeline_halt("the {$leg} {$step} step returned without writing the manifest");
    }

    $problem = pipeline_return_problem($before, $after, $leg, $step, $size);

    return $problem === null ? pipeline_route($after, $leg, $step, $triggers, $size) : pipeline_halt($problem);
}

/**
 * Key order is not content. Legs rewrite JSON with whatever tool they hold, so every comparison
 * runs over recursively key-sorted copies; list order (the ledger) is left as it is.
 */
function pipeline_normalized(array $value): array
{
    if (! array_is_list($value)) {
        ksort($value);
    }

    return array_map(fn ($item) => is_array($item) ? pipeline_normalized($item) : $item, $value);
}

/** `cursor.retried` is the dispatcher's; a leg that rewrites the cursor without it has not changed it. */
function pipeline_keep_retried(array $before, array $after): array
{
    if (isset($before['cursor']['retried']) && is_array($after['cursor'] ?? null) && ! array_key_exists('retried', $after['cursor'])) {
        $after['cursor']['retried'] = $before['cursor']['retried'];
    }

    return $after;
}

function pipeline_return_problem(array $before, array $after, string $leg, string $step, DesignSize $size): ?string
{
    $missing = manifest_validate($after);
    if ($missing !== []) {
        return 'the manifest lost ' . implode(', ', $missing);
    }

    $forbidden = array_diff(pipeline_changed_keys($before, $after), pipeline_leg_writable_keys());
    if ($forbidden !== []) {
        return 'the leg changed ' . implode(', ', $forbidden) . ', which only the dispatcher writes';
    }

    $status = LegStatus::tryFrom((string) ($after['cursor']['status'] ?? ''));
    if ($status === null) {
        return 'cursor.status is not a leg status: ' . json_encode($after['cursor']['status'] ?? null);
    }
    if (! in_array($status, LegStatus::allowedFor($leg, $step), true)) {
        return "the {$leg} {$step} step cannot return {$status->value}";
    }
    if ($status === LegStatus::Halted && trim((string) ($after['cursor']['reason'] ?? '')) === '') {
        return 'the leg halted without a reason';
    }

    return pipeline_ledger_problem($before['gate_ledger'] ?? [], $after['gate_ledger'] ?? [], $status, $leg, $step, $size);
}

/** @return list<string> top-level keys, and `cursor.*` one level down, whose values differ */
function pipeline_changed_keys(array $before, array $after): array
{
    $flat = function (array $manifest): array {
        $cursor = is_array($manifest['cursor'] ?? null) ? $manifest['cursor'] : [];
        unset($manifest['cursor']);
        foreach ($cursor as $key => $value) {
            $manifest["cursor.{$key}"] = $value;
        }

        return $manifest;
    };
    [$old, $new] = [$flat($before), $flat($after)];

    return array_values(array_filter(
        array_unique([...array_keys($old), ...array_keys($new)]),
        fn ($key) => ($old[$key] ?? null) !== ($new[$key] ?? null),
    ));
}

/** The ledger only grows, and what it grew by agrees with the step and its status. */
function pipeline_ledger_problem(array $old, array $new, LegStatus $status, string $leg, string $step, DesignSize $size): ?string
{
    $gate = pipeline_gate_of($leg);
    $open = $step === 'resolve' ? pipeline_open_entry($old, $gate) : null;
    $kept = ['gate', 'leg', 'cycle', 'at', 'review'];

    foreach ($old as $index => $entry) {
        $same = $index === $open
            ? pipeline_pick($new[$index] ?? [], $kept) === pipeline_pick($entry, $kept)
            : ($new[$index] ?? null) === $entry;
        if (! $same) {
            return "the leg rewrote ledger entry {$index}";
        }
    }

    $added = array_slice($new, count($old));
    $addedTo = fn (string $name) => array_values(array_filter($added, fn ($entry) => ($entry['gate'] ?? null) === $name));

    return match (true) {
        $status === LegStatus::Halted => null,
        $status === LegStatus::PlanInsufficient => $size === DesignSize::Bounded
            && array_filter($addedTo('design-size'), fn ($entry) => ($entry['outcome'] ?? null) === 'escalated') === []
                ? 'plan-insufficient on a Bounded design needs a new design-size entry with outcome escalated'
                : null,
        $step === 'review' => count($addedTo($gate)) === 1 && pipeline_is_open($addedTo($gate)[0])
            ? null
            : "the review step must add exactly one open {$gate} entry",
        $step === 'resolve' => ($new[$open]['outcome'] ?? null) === $status->value
            ? null
            : "the resolve step must set the open {$gate} entry's outcome to {$status->value}",
        $leg === 'verify-ui' => count($addedTo('verify-ui')) === 1 && ($addedTo('verify-ui')[0]['outcome'] ?? null) === $status->value
            ? null
            : "the verify-ui step must add one verify-ui entry with outcome {$status->value}",
        default => null,
    };
}

function pipeline_pick(array $entry, array $keys): array
{
    return array_map(fn (string $key) => $entry[$key] ?? null, $keys);
}

function pipeline_route(array $after, string $leg, string $step, array $triggers, DesignSize $size): array
{
    $reason = (string) ($after['cursor']['reason'] ?? '');

    return match (LegStatus::from($after['cursor']['status'])) {
        LegStatus::Halted => pipeline_halt($reason),
        LegStatus::PlanInsufficient => $size === DesignSize::Bounded
            ? pipeline_dispatch('design')
            : pipeline_halt('plan insufficient: ' . ($reason === '' ? 'no reason given' : $reason)),
        LegStatus::LoopedBack => pipeline_loop_back($after['gate_ledger'] ?? [], $leg),
        LegStatus::Continued => pipeline_continue($leg, $step, $triggers),
    };
}

function pipeline_continue(string $leg, string $step, array $triggers): array
{
    if ($step === 'review') {
        return pipeline_dispatch($leg);
    }
    $next = pipeline_next_leg($leg, $triggers);

    return $next === null ? ['action' => 'done'] : pipeline_dispatch($next);
}

/** The bound is read from the ledger, never from memory (`../references/manifest.md` §gate_ledger). */
function pipeline_loop_back(array $ledger, string $leg): array
{
    $gate = pipeline_gate_of($leg);
    $entries = array_filter($ledger, fn ($entry) => ($entry['gate'] ?? null) === $gate);

    if (in_array('unknown', array_column($entries, 'cycle'), true)) {
        return pipeline_halt("{$gate}: the loop-back count is unknown after a reconstruction, so no loop-back is allowed");
    }
    $loops = count(array_filter($entries, fn ($entry) => ($entry['outcome'] ?? null) === 'looped-back'));
    if ($loops > 2) {
        return pipeline_halt("{$gate}: loop-back bound exhausted, {$loops} loop-backs where 2 are allowed");
    }

    return pipeline_dispatch(pipeline_loop_target($leg));
}

function pipeline_dispatch(string $leg): array
{
    return ['action' => 'dispatch', 'leg' => $leg];
}

function pipeline_halt(string $reason): array
{
    return ['action' => 'halt', 'reason' => $reason];
}
```

- [ ] **Step 4: Run it to see it pass, then the whole suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, all tests.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/dispatch.php skills/pipeline/checks/tests/ReturnedTest.php
git commit -m "pipeline: validate every leg return and route it, fail-closed"
```

---

### Task 3: Generated briefs — `pipeline_brief()`

**Files:**
- Create: `skills/pipeline/checks/brief.php`
- Test: `skills/pipeline/checks/tests/BriefTest.php`

**Interfaces:**
- Consumes: `pipeline_step`, `pipeline_open_entry`, `pipeline_gate_of`, `pipeline_leg_of_gate`, `pipeline_loop_target`, `pipeline_leg_writable_keys`, `LegStatus::allowedFor` (Task 1); `pipeline_legs()`.
- Produces: `pipeline_leg_overrides(): array<string, list<string>>` keyed `<leg>:<step>`; `pipeline_brief(array $manifest, string $leg): string`; `pipeline_manifest_path(array $manifest): string` (`<worktree>/.claude/pipeline/<branch with / → ->.json`).

- [ ] **Step 1: Write the failing test**

`skills/pipeline/checks/tests/BriefTest.php`:

```php
<?php

function brief_manifest(string $leg, array $extra = []): array
{
    return [
        'branch' => 'feature/x', 'worktree' => '/tmp/wt', 'mode' => 'auto',
        'cursor' => ['leg' => $leg, 'status' => 'pending'],
        'artifacts' => ['idea' => '/tmp/idea.md', 'spec' => 'docs/spec.md', 'plan' => null, 'pr' => 42, 'issue' => null],
        'decisions' => ['The engine never edits.'],
        'last_sha' => 'abc1234',
        'suite' => ['tree' => 't1', 'outcome' => 'green', 'passed' => 104, 'failed' => 0, 'at' => '2026-09-22T10:00:00Z'],
        'gate_ledger' => [],
        ...$extra,
    ];
}

it('has overrides for every leg and step', function () {
    foreach (pipeline_legs() as $leg) {
        foreach (in_array($leg, ['review-plan', 'review-pr'], true) ? ['review', 'resolve'] : ['run'] as $step) {
            expect(pipeline_leg_overrides())->toHaveKey("{$leg}:{$step}");
        }
    }
});

it('carries the pointers, the settled decisions and the suite line', function () {
    $brief = pipeline_brief(brief_manifest('implement'), 'implement');

    expect($brief)
        ->toContain('`implement` leg, `run` step, of a `/pipeline auto` run')
        ->toContain('/tmp/wt/.claude/pipeline/feature-x.json')
        ->toContain('- spec: `docs/spec.md`')
        ->toContain('- pr: `42`')
        ->not->toContain('- plan:')
        ->toContain('The engine never edits.')
        ->toContain('full suite green over tree `t1` at `abc1234`: 104 passed, 0 failed')
        ->toContain('Leave the PR draft; this overrides any mark-ready instruction')
        ->toContain('`plan-insufficient`')
        ->toContain('Never move `cursor.leg`');
});

it('permits the design size the invocation allowed', function () {
    expect(pipeline_brief(brief_manifest('design'), 'design'))->toContain('the Architectural path is required');
    expect(pipeline_brief(brief_manifest('design', ['light' => true]), 'design'))->toContain('the Bounded path is permitted');
});

it('asks for grow form only after an escalation no plan approval has answered', function () {
    $escalated = ['gate' => 'design-size', 'leg' => 'implement', 'at' => '2026-09-22T12:00:00Z', 'reason' => 'migration', 'outcome' => 'escalated'];
    $approved = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'at' => '2026-09-22T13:00:00Z', 'review' => 'ok', 'outcome' => 'continued'];

    expect(pipeline_brief(brief_manifest('design', ['gate_ledger' => [$escalated]]), 'design'))->toContain('Grow form');
    expect(pipeline_brief(brief_manifest('design', ['gate_ledger' => [$escalated, $approved]]), 'design'))->not->toContain('Grow form');
});

it('gives the reviewer crafted context: no earlier review, no earlier actions', function () {
    $earlier = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'OLD REVIEW TEXT', 'actions' => [['claim' => 'x', 'disposition' => 'integrated', 'note' => 'OLD ACTION']], 'outcome' => 'looped-back'];
    $brief = pipeline_brief(brief_manifest('review-plan', ['gate_ledger' => [$earlier]]), 'review-plan');

    expect($brief)
        ->toContain('`review` step')
        ->toContain('/critique plan')
        ->toContain('this review\'s `cycle`: `2`')
        ->toContain('The engine never edits.')
        ->not->toContain('OLD REVIEW TEXT')
        ->not->toContain('OLD ACTION')
        ->not->toContain('gate_ledger[0]');
});

it('points a resolve step at its open review without copying it', function () {
    $done = ['gate' => 'design-size', 'leg' => 'implement', 'at' => '2026-09-22T09:00:00Z', 'outcome' => 'escalated'];
    $open = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'NEW REVIEW'];
    $brief = pipeline_brief(brief_manifest('review-plan', ['gate_ledger' => [$done, $open]]), 'review-plan');

    expect($brief)
        ->toContain('`resolve` step')
        ->toContain('the open review: `gate_ledger[1]`')
        ->toContain('Change nothing the review did not name.')
        ->not->toContain('NEW REVIEW');
});

it('points a looped-back leg at the entry that sent it back', function () {
    $planLoop = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r', 'outcome' => 'looped-back'];
    $uiLoop = ['gate' => 'verify-ui', 'cycle' => 1, 'at' => '2026-09-22T11:00:00Z', 'outcome' => 'looped-back'];

    expect(pipeline_brief(brief_manifest('design', ['gate_ledger' => [$planLoop]]), 'design'))->toContain('`gate_ledger[0]` looped back');
    expect(pipeline_brief(brief_manifest('implement', ['gate_ledger' => [$uiLoop]]), 'implement'))->toContain('`gate_ledger[0]` looped back');
    expect(pipeline_brief(brief_manifest('handoff', ['gate_ledger' => [$planLoop]]), 'handoff'))->not->toContain('looped back');
});

it('makes the review-pr resolve step the finish step', function () {
    $open = ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r'];
    $brief = pipeline_brief(brief_manifest('review-pr', ['gate_ledger' => [$open]]), 'review-pr');

    expect($brief)->toContain('the finish step')->toContain('gh pr ready')->toContain('proof_cli.php open');
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter=BriefTest`
Expected: FAIL, `Call to undefined function pipeline_leg_overrides()`.

- [ ] **Step 3: Write the implementation**

`skills/pipeline/checks/brief.php`:

```php
<?php

/**
 * Every step's brief (`../references/engine.md` §What a leg brief consists of). A brief points at the
 * rules; it never restates them, and it holds nothing a station does not ask for.
 */

/** @return array<string, list<string>> keyed `<leg>:<step>` */
function pipeline_leg_overrides(): array
{
    $actOnReview = [
        'Act on the open review with the edit/rework boundary (engine.md §`auto`): integrate and commit edits and small fixes; where the review says the work is fundamentally wrong, loop back.',
        'Change nothing the review did not name.',
        'Carry anything unresolved verbatim as an open question.',
    ];
    $completeEntry = 'Complete the open entry: `actions`, then `outcome`, equal to the status you return.';

    return [
        'design:run' => [
            'Invoke `superpowers:brainstorming`; on the Architectural path it hands over to `superpowers:writing-plans` (engine.md §Design size).',
            'Where brainstorming would ask the human, write each question and the answer you assumed into the spec\'s `## Assumptions` section, so `/critique plan` audits exactly those.',
            'Plans and specs committed before 2026-09-14 are not exemplars for test or proof policy.',
            'Commit the spec, then the plan: two commits. Set `artifacts.spec` and `artifacts.plan`.',
        ],
        'review-plan:review' => [
            'Invoke `/critique plan` on the spec and the plan.',
            'Append its review verbatim as a new `plan-approval` ledger entry with `gate`, `leg`, `cycle`, `at`, `review` and `annotations`, and no `outcome`.',
            'Act on nothing. Read-only on the checkout; the manifest is the only file you write.',
        ],
        'review-plan:resolve' => [
            ...$actOnReview,
            'The independent read (engine.md §`auto`) is available.',
            $completeEntry,
        ],
        'handoff:run' => [
            'Invoke `handoff pr`. The PR opens draft and references the issue without a closing keyword (engine.md §Closing links). Set `artifacts.pr`.',
        ],
        'implement:run' => [
            'Bring the dev stack up first, without asking (engine.md §Dev-stack readiness).',
            'Follow `work-on`\'s logic in this worktree; claim no second slot.',
            'Test-first; after each step the suite and the mechanical checks (engine.md §Mechanical checks, §Suite reuse). Record `suite` after every full run.',
            'Leave the PR draft; this overrides any mark-ready instruction in the plan, the PR comment, or `work-on`\'s own logic.',
            'Files or behaviour the plan does not name: return `plan-insufficient` with the reason instead of improvising.',
        ],
        'verify-ui:run' => [
            'Bring the dev stack up if it is down. Invoke `browser-verification`.',
            'Write the proof page (engine.md §The proof store), set `artifacts.proof` to the path `write` printed, and post the text-only record comment.',
            'Append the thin `verify-ui` entry with outcome `continued`, or `looped-back` when the check fails.',
        ],
        'review-pr:review' => [
            'Invoke `/critique pr`, stating the suite line above and the mechanical-check result qualified by its scope (engine.md §Mechanical checks).',
            'Append its review verbatim as a new `pr-review` ledger entry with `gate`, `leg`, `cycle`, `at`, `review` and `annotations`, and no `outcome`.',
            'Act on nothing. Read-only on the checkout; the manifest is the only file you write.',
        ],
        'review-pr:resolve' => [
            'You are the finish step.',
            ...$actOnReview,
            'On a loop-back, stop there: no suite, no `gh pr ready`.',
            'Run the suite unless engine.md §Suite reuse finds this tree green; record `suite`.',
            'Reconcile the closing links (engine.md §Closing links) and write `issue_links` on the entry.',
            'When `artifacts.proof` is set, rewrite the proof page with the final open questions and ledger.',
            'Run `gh pr ready`. The last action is `proof_cli.php open` on `artifacts.proof` (engine.md §The proof store).',
            $completeEntry,
        ],
    ];
}

function pipeline_brief(array $manifest, string $leg): string
{
    $step = pipeline_step($manifest, $leg);

    return implode("\n\n", [
        pipeline_brief_role($manifest, $leg, $step),
        pipeline_brief_pointers($manifest, $leg, $step),
        pipeline_brief_state($manifest, $leg),
        pipeline_brief_overrides($manifest, $leg, $step),
        pipeline_brief_return($leg, $step),
    ]) . "\n";
}

function pipeline_manifest_path(array $manifest): string
{
    return rtrim($manifest['worktree'], '/') . '/.claude/pipeline/' . str_replace('/', '-', $manifest['branch']) . '.json';
}

function pipeline_brief_role(array $manifest, string $leg, string $step): string
{
    return "# Brief: `{$leg}` leg, `{$step}` step\n\n"
        . "You are the `{$leg}` leg, `{$step}` step, of a `/pipeline {$manifest['mode']}` run. "
        . "Work only in `{$manifest['worktree']}` on `{$manifest['branch']}`. "
        . 'The engine.md sections this brief names are in `~/.claude/skills/pipeline/references/engine.md`.';
}

function pipeline_brief_pointers(array $manifest, string $leg, string $step): string
{
    $ledger = $manifest['gate_ledger'] ?? [];
    $lines = ['- manifest: `' . pipeline_manifest_path($manifest) . '`'];

    foreach ($manifest['artifacts'] ?? [] as $name => $value) {
        if ($value !== null && $value !== '') {
            $lines[] = "- {$name}: `{$value}`";
        }
    }
    if ($step === 'review') {
        $lines[] = '- this review\'s `cycle`: `' . pipeline_next_cycle($ledger, pipeline_gate_of($leg)) . '`';
    }
    if ($step === 'resolve') {
        $lines[] = '- the open review: `gate_ledger[' . pipeline_open_entry($ledger, pipeline_gate_of($leg)) . ']`';
    }
    $loopBack = pipeline_loop_back_entry($ledger, $leg);
    if ($loopBack !== null) {
        $lines[] = "- redo what `gate_ledger[{$loopBack}]` looped back for";
    }

    return "## Pointers\n\n" . implode("\n", $lines);
}

/** 1-based pass number for the next entry of this gate; `unknown` stays unknown (`../references/manifest.md` §reconstruction). */
function pipeline_next_cycle(array $ledger, string $gate): int|string
{
    $cycles = array_column(array_filter($ledger, fn ($entry) => ($entry['gate'] ?? null) === $gate), 'cycle');

    return in_array('unknown', $cycles, true) ? 'unknown' : count($cycles) + 1;
}

/** The newest ledger entry, when it looped back to this leg. */
function pipeline_loop_back_entry(array $ledger, string $leg): ?int
{
    $index = array_key_last($ledger);
    if ($index === null) {
        return null;
    }
    $entry = $ledger[$index];
    $from = pipeline_leg_of_gate((string) ($entry['gate'] ?? ''));

    return ($entry['outcome'] ?? null) === 'looped-back' && $from !== null && pipeline_loop_target($from) === $leg ? $index : null;
}

function pipeline_brief_state(array $manifest, string $leg): string
{
    $decisions = $manifest['decisions'] ?? [];
    $lines = $decisions === [] ? ['- settled decisions: none'] : array_map(fn (string $decision) => "- settled: {$decision}", $decisions);
    $sha = $manifest['last_sha'] ?? 'unknown';
    $lines[] = "- last_sha: `{$sha}`";

    $suite = $manifest['suite'] ?? null;
    if ($suite !== null) {
        $lines[] = "- full suite {$suite['outcome']} over tree `{$suite['tree']}` at `{$sha}`: {$suite['passed']} passed, {$suite['failed']} failed";
    }
    if ($leg === 'design') {
        $lines[] = empty($manifest['light'])
            ? '- design size: the Architectural path is required (no `light`)'
            : '- design size: the Bounded path is permitted (`light`)';
    }

    return "## Settled decisions and state\n\n" . implode("\n", $lines);
}

function pipeline_brief_overrides(array $manifest, string $leg, string $step): string
{
    $lines = pipeline_leg_overrides()["{$leg}:{$step}"];

    if ($leg === 'design' && pipeline_design_grows($manifest['gate_ledger'] ?? [])) {
        $lines[] = 'Grow form: the design escalated from Bounded (engine.md §Design size). Grow the spec and the plan; do not re-design them.';
    }
    if ($leg !== 'design') {
        $lines[] = 'While the spec\'s header says `**Design size:** Bounded`, run the escalation check first (engine.md §Design size); on escalation append the `design-size` entry and return `plan-insufficient`.';
    }

    return "## Overrides\n\n" . implode("\n", array_map(fn (string $line) => "- {$line}", $lines));
}

/** A design-size escalation that no plan approval has answered yet. */
function pipeline_design_grows(array $ledger): bool
{
    $grows = false;
    foreach ($ledger as $entry) {
        $outcome = $entry['outcome'] ?? null;
        $grows = match ($entry['gate'] ?? null) {
            'design-size' => $outcome === 'escalated' ? true : $grows,
            'plan-approval' => $outcome === 'continued' ? false : $grows,
            default => $grows,
        };
    }

    return $grows;
}

function pipeline_brief_return(string $leg, string $step): string
{
    $keys = implode(', ', array_map(fn (string $key) => "`{$key}`", pipeline_leg_writable_keys()));
    $statuses = implode(', ', array_map(fn (LegStatus $status) => "`{$status->value}`", LegStatus::allowedFor($leg, $step)));

    return "## Return\n\n"
        . "Write your results into the manifest ({$keys}; `cursor.reason` only when you halt) and nothing else. Never move `cursor.leg`.\n"
        . "Set `cursor.status` to one of {$statuses}, and reply with one line naming it.";
}
```

- [ ] **Step 4: Run it to see it pass, then the whole suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, all tests.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/brief.php skills/pipeline/checks/tests/BriefTest.php
git commit -m "pipeline: generate every step's brief from the manifest"
```

---

### Task 4: The dispatcher's one command — `dispatch_cli.php`

**Files:**
- Create: `skills/pipeline/checks/dispatch_cli.php`
- Test: `skills/pipeline/checks/tests/DispatchCliTest.php`

**Interfaces:**
- Consumes: `pipeline_triggers()` (`triggers.php`), `pipeline_legs()`, `manifest_read/write/validate()`, `DesignSize::fromSpec()`, Task 1–3 functions.
- Produces: `php dispatch_cli.php next <manifest>` and `php dispatch_cli.php returned <manifest> <diff-file>`, each printing one JSON line: `{"action":"dispatch"|"retry","leg","step","inline","prompt"}`, `{"action":"halt","reason"}` or `{"action":"done"}`; exit 0, or 1 on a usage error. Side files next to the manifest: `<stem>.brief.md`, `<stem>.before.json`.

- [ ] **Step 1: Write the failing test**

`skills/pipeline/checks/tests/DispatchCliTest.php`:

```php
<?php

/** A worktree-shaped temp dir with a manifest and an empty diff; nothing touches a real checkout. */
function dispatch_fixture(array $manifest = []): array
{
    $dir = sys_get_temp_dir() . '/pipeline-dispatch-' . uniqid();
    mkdir($dir . '/.claude/pipeline', 0777, true);
    $path = $dir . '/.claude/pipeline/feature-x.json';
    manifest_write($path, [
        'branch' => 'feature/x', 'worktree' => $dir, 'mode' => 'auto',
        'cursor' => ['leg' => 'review-plan', 'status' => 'pending'],
        'artifacts' => ['spec' => null, 'plan' => null, 'pr' => null, 'issue' => null],
        'gate_ledger' => [],
        ...$manifest,
    ]);
    file_put_contents($dir . '/pipeline.diff', '');

    return ['dir' => $dir, 'manifest' => $path, 'diff' => $dir . '/pipeline.diff', 'brief' => $dir . '/.claude/pipeline/feature-x.brief.md', 'before' => $dir . '/.claude/pipeline/feature-x.before.json'];
}

function dispatch_cli(array $arguments): array
{
    $process = proc_open([PHP_BINARY, __DIR__ . '/../dispatch_cli.php', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($process), 'json' => json_decode($stdout, true)];
}

/** Play a leg: change the manifest the way the leg would. */
function dispatch_leg_writes(string $path, callable $change): void
{
    manifest_write($path, $change(manifest_read($path)));
}

it('writes the brief and the snapshot and prints one dispatch line', function () {
    $fixture = dispatch_fixture();
    $result = dispatch_cli(['next', $fixture['manifest']]);

    expect($result['code'])->toBe(0);
    expect($result['json'])->toMatchArray(['action' => 'dispatch', 'leg' => 'review-plan', 'step' => 'review', 'inline' => false]);
    expect($result['json']['prompt'])->toContain($fixture['brief']);
    expect(file_get_contents($fixture['brief']))->toContain('/critique plan');
    expect(manifest_read($fixture['before']))->toBe(manifest_read($fixture['manifest']));
});

it('routes a review step to its resolve step and briefs it', function () {
    $fixture = dispatch_fixture();
    dispatch_cli(['next', $fixture['manifest']]);
    dispatch_leg_writes($fixture['manifest'], fn (array $m) => [
        ...$m,
        'cursor' => [...$m['cursor'], 'status' => 'continued'],
        'gate_ledger' => [['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r']],
    ]);

    $result = dispatch_cli(['returned', $fixture['manifest'], $fixture['diff']]);

    expect($result['json'])->toMatchArray(['action' => 'dispatch', 'leg' => 'review-plan', 'step' => 'resolve']);
    expect(manifest_read($fixture['manifest'])['cursor'])->toBe(['leg' => 'review-plan', 'status' => 'pending']);
    expect(file_get_contents($fixture['brief']))->toContain('the open review: `gate_ledger[0]`');
});

it('halts and records the reason when a leg moves the cursor', function () {
    $fixture = dispatch_fixture();
    dispatch_cli(['next', $fixture['manifest']]);
    dispatch_leg_writes($fixture['manifest'], fn (array $m) => [...$m, 'cursor' => ['leg' => 'handoff', 'status' => 'continued']]);

    $result = dispatch_cli(['returned', $fixture['manifest'], $fixture['diff']]);

    expect($result['json']['action'])->toBe('halt');
    expect($result['json']['reason'])->toContain('cursor.leg');
    expect(manifest_read($fixture['manifest'])['cursor'])->toMatchArray(['leg' => 'review-plan', 'status' => 'halted']);
});

it('retries an empty review once, then halts', function () {
    $fixture = dispatch_fixture();
    dispatch_cli(['next', $fixture['manifest']]);

    $retry = dispatch_cli(['returned', $fixture['manifest'], $fixture['diff']]);
    expect($retry['json'])->toMatchArray(['action' => 'retry', 'leg' => 'review-plan', 'step' => 'review']);
    expect(manifest_read($fixture['manifest'])['cursor']['retried'])->toBeTrue();

    expect(dispatch_cli(['returned', $fixture['manifest'], $fixture['diff']])['json']['action'])->toBe('halt');
});

it('halts when the diff file is missing', function () {
    $fixture = dispatch_fixture();
    dispatch_cli(['next', $fixture['manifest']]);

    expect(dispatch_cli(['returned', $fixture['manifest'], $fixture['dir'] . '/missing.diff'])['json']['action'])->toBe('halt');
});

it('runs design inline outside auto', function () {
    $fixture = dispatch_fixture(['mode' => 'interactive', 'cursor' => ['leg' => 'design', 'status' => 'pending']]);

    expect(dispatch_cli(['next', $fixture['manifest']])['json'])->toMatchArray(['leg' => 'design', 'inline' => true]);
});

it('reads the design size from the spec to route plan-insufficient', function (string $header, string $action) {
    $fixture = dispatch_fixture(['cursor' => ['leg' => 'implement', 'status' => 'pending'], 'artifacts' => ['spec' => 'spec.md', 'plan' => null, 'pr' => 7, 'issue' => null]]);
    file_put_contents($fixture['dir'] . '/spec.md', "# x — design\n\n{$header}\n");
    dispatch_cli(['next', $fixture['manifest']]);
    dispatch_leg_writes($fixture['manifest'], fn (array $m) => [
        ...$m,
        'cursor' => [...$m['cursor'], 'status' => 'plan-insufficient', 'reason' => 'migration'],
        'gate_ledger' => [['gate' => 'design-size', 'leg' => 'implement', 'at' => '2026-09-22T12:00:00Z', 'reason' => 'migration', 'outcome' => 'escalated']],
    ]);

    expect(dispatch_cli(['returned', $fixture['manifest'], $fixture['diff']])['json']['action'])->toBe($action);
})->with([
    'Bounded grows' => ['**Design size:** Bounded', 'dispatch'],
    'Architectural halts' => ['**Design size:** Architectural', 'halt'],
]);

it('records a finished run as done and does not re-dispatch it', function () {
    $open = ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r'];
    $fixture = dispatch_fixture(['cursor' => ['leg' => 'review-pr', 'status' => 'pending'], 'gate_ledger' => [$open]]);
    dispatch_cli(['next', $fixture['manifest']]);
    dispatch_leg_writes($fixture['manifest'], fn (array $m) => [
        ...$m,
        'cursor' => [...$m['cursor'], 'status' => 'continued'],
        'gate_ledger' => [[...$open, 'actions' => [], 'outcome' => 'continued']],
    ]);

    expect(dispatch_cli(['returned', $fixture['manifest'], $fixture['diff']])['json'])->toBe(['action' => 'done']);
    expect(manifest_read($fixture['manifest'])['cursor']['status'])->toBe('done');
    expect(dispatch_cli(['next', $fixture['manifest']])['json'])->toBe(['action' => 'done']);
});

it('refuses a manifest it cannot read, and a bad command', function () {
    expect(dispatch_cli(['next', '/nonexistent/manifest.json'])['json']['action'])->toBe('halt');
    expect(dispatch_cli(['sideways'])['code'])->toBe(1);
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter=DispatchCliTest`
Expected: FAIL — the subprocess cannot open `dispatch_cli.php`, so `json` is null and the first assertion fails.

- [ ] **Step 3: Write the implementation**

`skills/pipeline/checks/dispatch_cli.php`:

```php
<?php

/**
 * The dispatcher's one command (`../references/engine.md` §The loop). Everything impure the dispatcher
 * needs is here, so its context per step is one JSON line.
 *
 *   php dispatch_cli.php next <manifest>
 *   php dispatch_cli.php returned <manifest> <diff-file>
 *
 * Exits 0 on every decision, a halt included: the JSON line says what happened. Exits 1 on a usage error.
 */

require_once __DIR__ . '/triggers.php';
require_once __DIR__ . '/pipeline.php';
require_once __DIR__ . '/manifest.php';
require_once __DIR__ . '/design_size.php';
require_once __DIR__ . '/dispatch.php';
require_once __DIR__ . '/brief.php';

/** @return array{brief: string, before: string} */
function dispatch_cli_files(string $manifestPath): array
{
    $stem = preg_replace('/\.json$/', '', $manifestPath);

    return ['brief' => "{$stem}.brief.md", 'before' => "{$stem}.before.json"];
}

function dispatch_cli_emit(string $manifestPath, array $manifest, string $action = 'dispatch'): array
{
    $leg = $manifest['cursor']['leg'];
    $step = pipeline_step($manifest, $leg);
    $files = dispatch_cli_files($manifestPath);

    manifest_write($manifestPath, $manifest);
    manifest_write($files['before'], $manifest);
    file_put_contents($files['brief'], pipeline_brief($manifest, $leg));

    return [
        'action' => $action,
        'leg' => $leg,
        'step' => $step,
        'inline' => pipeline_runs_inline((string) $manifest['mode'], $leg, $step),
        'prompt' => "You are the `{$leg}` leg ({$step}) of a /pipeline run. Your brief is {$files['brief']}; read it first, it is complete.",
    ];
}

function dispatch_cli_halt(string $manifestPath, array $manifest, string $leg, string $reason): array
{
    manifest_write($manifestPath, [...$manifest, 'cursor' => ['leg' => $leg, 'status' => 'halted', 'reason' => $reason]]);

    return pipeline_halt($reason);
}

function dispatch_cli_next(string $manifestPath): array
{
    $manifest = manifest_read($manifestPath);
    if ($manifest === null) {
        return pipeline_halt("no readable manifest at {$manifestPath}");
    }
    $missing = manifest_validate($manifest);
    $leg = $manifest['cursor']['leg'] ?? null;
    if ($missing !== [] || ! in_array($leg, pipeline_legs(), true)) {
        return pipeline_halt('the manifest is invalid: ' . ($missing === [] ? 'cursor.leg is not a leg' : 'missing ' . implode(', ', $missing)));
    }
    if (($manifest['cursor']['status'] ?? null) === 'done') {
        return ['action' => 'done'];
    }

    return dispatch_cli_emit($manifestPath, [...$manifest, 'cursor' => ['leg' => $leg, 'status' => 'pending']]);
}

function dispatch_cli_returned(string $manifestPath, string $diffPath): array
{
    $before = manifest_read(dispatch_cli_files($manifestPath)['before']);
    $after = manifest_read($manifestPath);
    if ($before === null || $after === null || ! is_file($diffPath)) {
        return pipeline_halt('cannot check the return: the snapshot, the manifest or the diff file is missing');
    }

    $triggers = pipeline_triggers((string) file_get_contents($diffPath));
    $decision = pipeline_returned($before, $after, $triggers, dispatch_cli_design_size($after));

    return match ($decision['action']) {
        'dispatch' => dispatch_cli_emit($manifestPath, [...$after, 'cursor' => ['leg' => $decision['leg'], 'status' => 'pending']]),
        'retry' => dispatch_cli_emit($manifestPath, [...$before, 'cursor' => [...$before['cursor'], 'retried' => true]], 'retry'),
        'halt' => dispatch_cli_halt($manifestPath, $after, $before['cursor']['leg'], $decision['reason']),
        'done' => dispatch_cli_done($manifestPath, $after),
    };
}

/** A finished run says so in its cursor, so a later `next` does not re-dispatch review-pr. */
function dispatch_cli_done(string $manifestPath, array $manifest): array
{
    manifest_write($manifestPath, [...$manifest, 'cursor' => ['leg' => $manifest['cursor']['leg'], 'status' => 'done']]);

    return ['action' => 'done'];
}

/** Read from the committed spec, never stored; a spec that cannot be read is Architectural. */
function dispatch_cli_design_size(array $manifest): DesignSize
{
    $spec = (string) ($manifest['artifacts']['spec'] ?? '');
    $path = $spec === '' || str_starts_with($spec, '/') ? $spec : rtrim($manifest['worktree'], '/') . '/' . $spec;

    return DesignSize::fromSpec($path !== '' && is_file($path) ? (string) file_get_contents($path) : '');
}

$result = match ($argv[1] ?? '') {
    'next' => dispatch_cli_next((string) ($argv[2] ?? '')),
    'returned' => dispatch_cli_returned((string) ($argv[2] ?? ''), (string) ($argv[3] ?? '')),
    default => null,
};

if ($result === null) {
    fwrite(STDERR, "usage: dispatch_cli.php next <manifest> | returned <manifest> <diff-file>\n");
    exit(1);
}

echo json_encode($result, JSON_UNESCAPED_SLASHES), "\n";
exit(0);
```

- [ ] **Step 4: Run it to see it pass, then the whole suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, all tests.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/dispatch_cli.php skills/pipeline/checks/tests/DispatchCliTest.php
git commit -m "pipeline: dispatch_cli.php, the dispatcher's one command per step"
```

---

### Task 5: The 150k invariant — `engine_peak.php` and its CLI

**Files:**
- Create: `skills/pipeline/checks/engine_peak.php`, `skills/pipeline/checks/engine_peak_cli.php`
- Test: `skills/pipeline/checks/tests/EnginePeakTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `const PIPELINE_ENGINE_PEAK_LIMIT = 150000`; `pipeline_transcript_peak(string $jsonl): array{calls: int, peak: int}`; `pipeline_find_transcript(string $projectsDir, string $agentId): ?string`; `pipeline_engine_peak_line(string $agentId, ?array $peak): string`; CLI `php engine_peak_cli.php <agent-id> [--projects-dir DIR]` (one line, exit 0 always).

- [ ] **Step 1: Write the failing test**

`skills/pipeline/checks/tests/EnginePeakTest.php`:

```php
<?php

function peak_call(string $id, int $input, int $write, int $read): string
{
    return json_encode(['type' => 'assistant', 'message' => ['id' => $id, 'role' => 'assistant', 'usage' => [
        'input_tokens' => $input, 'cache_creation_input_tokens' => $write, 'cache_read_input_tokens' => $read, 'output_tokens' => 50,
    ]]]);
}

function peak_projects(string $agentId, string $jsonl): string
{
    $root = sys_get_temp_dir() . '/pipeline-peak-' . uniqid();
    mkdir("{$root}/-Users-x-repo/session-1/subagents", 0777, true);
    file_put_contents("{$root}/-Users-x-repo/session-1/subagents/agent-{$agentId}.jsonl", $jsonl);

    return $root;
}

function peak_cli(array $arguments): array
{
    $process = proc_open([PHP_BINARY, __DIR__ . '/../engine_peak_cli.php', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($process), 'stdout' => trim($stdout)];
}

it('takes the peak over deduplicated assistant calls, as the audit does', function () {
    $jsonl = implode("\n", [
        peak_call('m1', 3, 20000, 0),
        peak_call('m1', 3, 20000, 0),
        peak_call('m2', 1, 1000, 140000),
        '{"type":"user","message":{"role":"user","content":"hi"}}',
        'not json',
        '',
    ]);

    expect(pipeline_transcript_peak($jsonl))->toBe(['calls' => 2, 'peak' => 141001]);
    expect(pipeline_transcript_peak(peak_call('m1', 0, 1000, 0) . "\n" . peak_call('m1', 0, 5000, 0)))->toBe(['calls' => 1, 'peak' => 5000]);
    expect(pipeline_transcript_peak(''))->toBe(['calls' => 0, 'peak' => 0]);
});

it('judges the peak against 150k as an annotation', function () {
    expect(pipeline_engine_peak_line('abc', ['calls' => 3, 'peak' => 149999]))->toBe('engine abc: peak 149k over 3 calls, within the 150k invariant');
    expect(pipeline_engine_peak_line('abc', ['calls' => 9, 'peak' => 150000]))->toBe('engine abc: peak 150k over 9 calls, over the 150k invariant (annotation, not a halt)');
    expect(pipeline_engine_peak_line('abc', null))->toBe('engine abc: not measured (no transcript)');
    expect(pipeline_engine_peak_line('abc', ['calls' => 0, 'peak' => 0]))->toBe('engine abc: not measured (no transcript)');
});

it('finds a dispatcher transcript by agent id and reports it, always exiting 0', function () {
    $root = peak_projects('a3a2b25333125d540', implode("\n", [peak_call('m1', 3, 20000, 0), peak_call('m2', 1, 1000, 140000)]));

    expect(peak_cli(['a3a2b25333125d540', '--projects-dir', $root]))
        ->toBe(['code' => 0, 'stdout' => 'engine a3a2b25333125d540: peak 141k over 2 calls, within the 150k invariant']);
    expect(peak_cli(['agent-a3a2b25333125d540', '--projects-dir', $root])['stdout'])->toContain('peak 141k');
    expect(peak_cli(['ffff', '--projects-dir', $root]))->toBe(['code' => 0, 'stdout' => 'engine ffff: not measured (no transcript)']);
    expect(peak_cli(['../x', '--projects-dir', $root])['stdout'])->toContain('not measured');
    expect(peak_cli([])['code'])->toBe(0);
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter=EnginePeakTest`
Expected: FAIL, `Call to undefined function pipeline_transcript_peak()`.

- [ ] **Step 3: Write the implementation**

`skills/pipeline/checks/engine_peak.php`:

```php
<?php

/**
 * The dispatcher's peak context against the 150k invariant (spec 2026-09-22 §8), measured as the
 * 2026-09-22 token audit measured it (`usage.py`): assistant messages deduplicated by `message.id`,
 * the last occurrence winning; context per call = input + cache writes + cache reads.
 */

const PIPELINE_ENGINE_PEAK_LIMIT = 150000;

/** @return array{calls: int, peak: int} */
function pipeline_transcript_peak(string $jsonl): array
{
    $usage = [];
    foreach (explode("\n", $jsonl) as $line) {
        $entry = json_decode($line, true);
        $message = is_array($entry) ? ($entry['message'] ?? null) : null;
        if (! is_array($message) || ($message['role'] ?? null) !== 'assistant' || empty($message['usage'])) {
            continue;
        }
        $usage[$message['id'] ?? $entry['uuid'] ?? count($usage)] = $message['usage'];
    }

    $contexts = array_map(
        fn (array $call) => ($call['input_tokens'] ?? 0) + ($call['cache_creation_input_tokens'] ?? 0) + ($call['cache_read_input_tokens'] ?? 0),
        array_values($usage),
    );

    return ['calls' => count($contexts), 'peak' => $contexts === [] ? 0 : max($contexts)];
}

/** `<projects>/<project>/<session>/subagents/agent-<id>.jsonl`, or null. */
function pipeline_find_transcript(string $projectsDir, string $agentId): ?string
{
    $id = preg_replace('/^agent-/', '', $agentId);
    if (! preg_match('/^[a-z0-9]+$/i', $id)) {
        return null;
    }

    return (glob(rtrim($projectsDir, '/') . "/*/*/subagents/agent-{$id}.jsonl") ?: [])[0] ?? null;
}

function pipeline_engine_peak_line(string $agentId, ?array $peak): string
{
    if ($peak === null || $peak['calls'] === 0) {
        return "engine {$agentId}: not measured (no transcript)";
    }
    $limit = intdiv(PIPELINE_ENGINE_PEAK_LIMIT, 1000);
    $verdict = $peak['peak'] < PIPELINE_ENGINE_PEAK_LIMIT
        ? "within the {$limit}k invariant"
        : "over the {$limit}k invariant (annotation, not a halt)";

    return sprintf('engine %s: peak %dk over %d calls, %s', $agentId, intdiv($peak['peak'], 1000), $peak['calls'], $verdict);
}
```

`skills/pipeline/checks/engine_peak_cli.php`:

```php
<?php

/**
 * After a `/pipeline auto` run: the dispatcher's peak context against 150k.
 *
 *   php engine_peak_cli.php <agent-id> [--projects-dir DIR]
 *
 * The agent id is the one the Agent tool returned when the dispatcher was launched. Always exits 0:
 * the invariant is an annotation, never a halt.
 */

require_once __DIR__ . '/engine_peak.php';

$agentId = (string) ($argv[1] ?? '');
$flag = array_search('--projects-dir', $argv, true);
$projectsDir = $flag === false ? getenv('HOME') . '/.claude/projects' : (string) ($argv[$flag + 1] ?? '');
$transcript = $agentId === '' ? null : pipeline_find_transcript($projectsDir, $agentId);

echo pipeline_engine_peak_line(
    $agentId === '' ? '(no agent id)' : $agentId,
    $transcript === null ? null : pipeline_transcript_peak((string) file_get_contents($transcript)),
), "\n";
exit(0);
```

- [ ] **Step 4: Run it to see it pass, then the whole suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, all tests.

- [ ] **Step 5: Cross-check once against the audit**

`rows.json` is generated, not committed: when it is missing, build it first with `cd ~/.claude/token-audit/2026-09-22 && python3 usage.py` (running the audit scripts is fine; they are never modified). Then pick one pre-change engine transcript from the audit's `rows.json` and compare:

```bash
php -r '$rows = json_decode(file_get_contents(getenv("HOME") . "/.claude/token-audit/2026-09-22/rows.json"), true);
        foreach ($rows as $r) {
            if ($r["sub"] && $r["max_ctx"] > 200000 && $r["start"] < "2026-09-21") {
                echo basename($r["path"], ".jsonl"), " ", intdiv($r["max_ctx"], 1000), "k ", $r["calls"], "\n";
                break;
            }
        }'
php skills/pipeline/checks/engine_peak_cli.php <the agent-… name printed above>
```

Expected: the CLI prints the same `k` peak and the same call count as the first command. A mismatch means the port differs from `usage.py`; fix `pipeline_transcript_peak`, add the case to `EnginePeakTest` first, and re-run. Record both lines for the PR body.

- [ ] **Step 6: Commit**

```bash
git add skills/pipeline/checks/engine_peak.php skills/pipeline/checks/engine_peak_cli.php skills/pipeline/checks/tests/EnginePeakTest.php
git commit -m "pipeline: measure the dispatcher's peak context against 150k"
```

---

### Task 6: `manifest.md` and `gates.md`, locked to the PHP

**Files:**
- Modify: `skills/pipeline/references/manifest.md` (Fields table lines 14-25; new section after `## gate_ledger`; `outcome` row line 82)
- Modify: `skills/pipeline/references/gates.md` (new section before `## How the engine calls Phase A`; that section lines 98-119)
- Test: `skills/pipeline/checks/tests/LockStepTest.php`

**Interfaces:**
- Consumes: `LegStatus::cases()`, `pipeline_leg_writable_keys()`, `pipeline_loop_target()`, `pipeline_legs()`.
- Produces: `manifest.md` section `## What a leg writes — checked on every return`; `gates.md` section `## Loop-backs — where a looped-back leg goes`.

- [ ] **Step 1: Write the failing test**

`skills/pipeline/checks/tests/LockStepTest.php`:

```php
<?php

/** One `## ` section of a reference doc, heading included. */
function lockstep_section(string $doc, string $heading): string
{
    $markdown = (string) file_get_contents(__DIR__ . "/../../references/{$doc}");
    $start = strpos($markdown, "\n## {$heading}");
    expect($start)->not->toBeFalse("{$doc} has no section starting '## {$heading}'");
    $end = strpos($markdown, "\n## ", $start + 1);

    return $end === false ? substr($markdown, $start) : substr($markdown, $start, $end - $start);
}

it('keeps manifest.md in lock-step with the statuses and the leg-writable keys', function () {
    $section = lockstep_section('manifest.md', 'What a leg writes');

    foreach (LegStatus::cases() as $status) {
        expect($section)->toContain("`{$status->value}`");
    }
    foreach (pipeline_leg_writable_keys() as $key) {
        expect($section)->toContain("`{$key}`");
    }
});

it('keeps gates.md in lock-step with the loop-back targets', function () {
    $section = lockstep_section('gates.md', 'Loop-backs');

    foreach (pipeline_legs() as $leg) {
        $target = pipeline_loop_target($leg);
        if ($target !== null) {
            expect($section)->toContain("`{$leg}` → `{$target}`");
        }
    }
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter=LockStepTest`
Expected: FAIL, `manifest.md has no section starting '## What a leg writes'` and the same for `gates.md`.

- [ ] **Step 3: Edit `manifest.md`**

In the Fields table, replace the `cursor` and `artifacts` rows and add two rows after `suite`:

```markdown
| `cursor` | **required** | `{leg, status, reason?, retried?}` — the current leg; `status` is `pending` (set by the dispatcher) or the status the leg returned; `reason` only with `halted`; `retried` only after a review step's single retry |
| `artifacts` | optional | pointers: idea, spec path, plan path, PR number, issue number (`engine.md` §The work item), `proof` — the proof page `verify-ui` wrote |
| `decisions` | optional | the settled decisions from the invocation, verbatim, as a list. Every brief carries them (`engine.md` §What a leg brief consists of) |
| `light` | optional | the invocation's `light`; read only while `design` has not run |
```

In *Two rules that keep the file honest*, extend the first bullet's exception: after the sentence ending *"so there is no PR body to recover it from."* add: *"`decisions` is the second exception, for the same reason: the invocation that carried them is gone once the run starts."*

In the `gate_ledger` key table, replace the `outcome` row:

```markdown
| `outcome` | `continued` \| `looped-back` \| `halted` \| `escalated` (only on `design-size`). **Absent on an open entry**: a review step writes the review without an outcome, and only the resolve step sets it |
```

Insert this section after `## gate_ledger …` and before `## Invariant check`:

```markdown
## What a leg writes — checked on every return

A leg writes only its results: `artifacts`, `last_sha`, `suite`, its `gate_ledger` entry, and
`cursor.status` — plus `cursor.reason` when it halts. It never moves `cursor.leg` and never writes a
brief. After every return the dispatcher compares the manifest with its snapshot
(`pipeline_returned()`, `../checks/dispatch.php`) and **halts** when any other key changed, when an
existing ledger entry was rewritten (the resolve step may only complete the open entry), or when the
status does not agree with the ledger.

| `cursor.status` | Meaning |
|---|---|
| `continued` | the step did its work; a review step has appended one open entry |
| `looped-back` | a resolve step or `verify-ui` sends the work back (`gates.md` §Loop-backs); its entry says so |
| `halted` | a hard failure; `cursor.reason` says what |
| `plan-insufficient` | the plan does not cover what the change needs. Bounded: a `design-size` entry with `outcome: escalated` is appended and the design grows. Architectural: the run halts |

Keep this section in lock-step with `LegStatus` and `pipeline_leg_writable_keys()`; `LockStepTest`
fails when they drift.
```

- [ ] **Step 4: Edit `gates.md`**

Insert before `## How the engine calls Phase A`:

```markdown
## Loop-backs — where a looped-back leg goes

`pipeline_loop_target($leg)` (`../checks/dispatch.php`):

- `review-plan` → `design`
- `verify-ui` → `implement`
- `review-pr` → `implement`

Each is bounded to 2 per gate, counted from the gate's `looped-back` ledger entries; the third halts,
and so does any loop-back once the count is `unknown` (`manifest.md` §Reconstruction). The dispatcher
evaluates both in `pipeline_returned()`. Keep this list in lock-step with the function; `LockStepTest`
fails when they drift.
```

Replace the section `## How the engine calls Phase A` (its heading, prose and code block) with:

````markdown
## How the dispatcher calls Phase A

Once per step, one command (`engine.md` §The loop). It computes the triggers from a diff file the
dispatcher writes but never reads:

```bash
CHECKS="$HOME/.claude/skills/pipeline/checks"
git -C <worktree> diff origin/<base>...HEAD > "$TMPDIR/pipeline.diff"
php "$CHECKS/dispatch_cli.php" returned <manifest> "$TMPDIR/pipeline.diff"
# → {"action":"dispatch","leg":…,"step":…,"inline":…,"prompt":…} | {"action":"retry",…}
#   | {"action":"halt","reason":…} | {"action":"done"}
```

A leg that needs the triggers itself — the package annotation, the Bounded escalation check — calls
`pipeline_triggers()` over the same diff, with the repo's `composer.json` `name` for the in-package
case:

```bash
php -r 'require "skills/pipeline/checks/triggers.php";
        echo json_encode(pipeline_triggers(
          file_get_contents(getenv("TMPDIR") . "/pipeline.diff"),
          json_decode(file_get_contents("composer.json"), true)["name"] ?? null
        )), "\n";'
# → {"package":…,"migration":…,"auth":…,"ui":…}
```

Navigation is pure functions — call `pipeline_can_navigate` / `pipeline_next_leg` /
`pipeline_gate_legs` directly (they take no I/O). The manifest's `gate_ledger` records which gates
have run; `pipeline_can_navigate`'s `$doneLegs` is `pipeline_done_legs()` over it, which drops gate
passes older than the latest `design-size` escalation and never counts an open entry.
````

- [ ] **Step 5: Run it to see it pass, then the whole suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, all tests.

- [ ] **Step 6: Commit**

```bash
git add skills/pipeline/references/manifest.md skills/pipeline/references/gates.md skills/pipeline/checks/tests/LockStepTest.php
git commit -m "pipeline: manifest.md and gates.md describe the dispatcher, locked to the PHP"
```

---

### Task 7: `engine.md`, `SKILL.md` and `orchestrate/SKILL.md`

No test of its own: this text mirrors the PHP tested in Tasks 1–6. Line numbers are from `591bdc2`.

**Files:**
- Modify: `skills/pipeline/references/engine.md`
- Modify: `skills/pipeline/SKILL.md:15-17`, `:37-38`
- Modify: `skills/orchestrate/SKILL.md:26`

**Interfaces:**
- Consumes: the CLIs and function names from Tasks 1–5, exactly as named there.

- [ ] **Step 1: Replace `engine.md` §The loop (lines 7-29) with**

````markdown
## The loop

```
read manifest (or reconstruct it)          # manifest_read / manifest_infer_cursor
  → invariant check                         # recorded artifact at last_sha; PR as expected
  → dispatch_cli.php next                   # brief + snapshot → one dispatch line
  → dispatch the step                       # a fresh background agent; wait for its completion notice
  → dispatch_cli.php returned               # validate the manifest, route, write it
  → dispatch | retry | halt | done
```

```bash
CHECKS="$HOME/.claude/skills/pipeline/checks"
php "$CHECKS/dispatch_cli.php" next <manifest>                        # start or resume
git -C <worktree> diff origin/<base>...HEAD > "$TMPDIR/pipeline.diff"
php "$CHECKS/dispatch_cli.php" returned <manifest> "$TMPDIR/pipeline.diff"   # after every return
```

Each prints one JSON line. On `dispatch` or `retry`, pass its `prompt` — one line naming the brief
file `pipeline_brief()` wrote — to a background agent; when `inline` is true, run the step in this
session instead (§Interactive). On `halt`, stop (§Failure policy). On `done`, return.

**Wait for the completion notice.** No `sleep`, `date`, file-mtime or `ListAgents` polling while a
step runs.

**Control rule — the whole model, and it fails closed:**

- **Every step is a fresh agent** briefed by `pipeline_brief($manifest, $leg)`
  (`../checks/brief.php`). It writes its results and a status into the manifest (`manifest.md`
  §What a leg writes) and replies with one line. The dispatcher never reads that reply for content:
  `returned` compares the manifest with the snapshot taken at dispatch and **halts** on anything it
  cannot account for.
- **Legs never pick the next leg and never write a brief.** `pipeline_returned()`
  (`../checks/dispatch.php`) routes: `continued` → the next step or leg (`pipeline_next_leg`),
  `looped-back` → `gates.md` §Loop-backs within the bound, `halted` → stop, `plan-insufficient` →
  grow a Bounded design or halt an Architectural one.
- **Auto-continuation spans only dispatched steps.** The loop never tries to "become a skill inline
  and then regain control": a skill that tail-calls its successor (as `brainstorming` invokes
  `writing-plans`) would never return, so an inline auto-continuation would silently walk past the
  next gate. A lost step **halts the chain; it never skips a gate.**

## The dispatcher — what it does, and never does

`/pipeline auto` runs the loop in **one background agent, the dispatcher**, launched by the invoking
session (the main session or `orchestrate`). Its steps are depth 2; `/critique`'s reviewer and the
independent read are depth 3.

**It keeps:** kickoff (§The work item, §Kickoff, the manifest exclusion, writing `decisions` and
`light` from the invocation), the invariant check (`manifest.md`), `dispatch_cli.php` (which holds
`manifest_validate`, `manifest_write`, `pipeline_next_leg`, the loop bound and the return checks),
navigation through `pipeline_can_navigate`, and the halts (§Failure policy).

**It never** reads an artifact, a review, a diff or test output; never edits; never runs the suite.
Diffs go to a file that only `dispatch_cli.php` reads. Of this document it needs only §The loop, this
section, §The work item, §Kickoff, §Failure policy and §Navigation; each step's brief names the
sections that step needs.

**Its peak context stays under 150k per run.** After the dispatcher's completion notice, the invoking
session runs

```bash
php ~/.claude/skills/pipeline/checks/engine_peak_cli.php <the dispatcher's agent id>
```

and reports its line with the run's result. Over 150k is an **annotation, never a halt**. Baseline
before this design: median peak 253k; engines 18.1 percent of all usage since 2026-09-14.

## Interactive — the same loop, the human resolves

`interactive` runs the same `next` / `returned` pair. `inline` is true for `design` (the human drives
the brainstorm) and for every `resolve` step: the session shows the review from the open ledger entry,
the human decides, the session carries that out — on `review-pr` including the finish work below —
completes the entry with the human's `actions` and `outcome`, and runs `returned`. Every other step is
dispatched as in `auto`. After each step the session stops and continues when the human says so
(§Navigation), as `interactive` always has.
````

- [ ] **Step 2: Update §Dev-stack readiness (lines 163-174)**

Replace *"**Before the first such leg (`implement`), the pipeline brings the stack up itself, without asking**"* with *"**The `implement` step brings the stack up itself, first thing, without asking** (its brief says so; `verify-ui` does the same if it is down)"*, and *"under the pipeline the pipeline *is* that caller"* with *"under the pipeline the step's brief *is* that caller"*. Keep the rest.

- [ ] **Step 3: Update §Stations (lines 176-188)**

Replace the `review-plan` row's *Autonomous form* cell with: *"two steps (`pipeline_step`): a **review** agent invokes `/critique plan` and appends the review verbatim as an open `plan-approval` entry; a fresh **resolve** agent acts on it (§`auto`)"*. Replace the `review-pr` row's *Autonomous form* cell with: *"a **review** agent invokes `/critique pr` and appends an open `pr-review` entry; the **finish** step (its resolve step) acts on it, runs the suite unless reused, reconciles closing links (§Closing links), rewrites the proof page, runs `gh pr ready`, and opens the page last (§The proof store)"*. In the `implement` row's *Invokes* cell, append *"The step brings the stack up itself (§Dev-stack readiness)."* Keep the other cells.

- [ ] **Step 4: Update §Escalation "When to check" (lines 266-268)**

Replace the two bullets with:

```markdown
**When to check.** Only while the spec says Bounded, by the step itself — its brief says so:
- after every commit in `implement`, and
- first thing in every later step.

On escalation the step appends the `design-size` entry and returns `plan-insufficient`; the dispatcher
moves the cursor to `design` (`pipeline_returned()`), whose brief asks for the grow form.
```

In "On escalation, grow the design" item 2, replace *"Move the cursor back to `design`"* with *"The dispatcher moves the cursor back to `design`"*.

- [ ] **Step 5: Update §The proof store, §Who takes the PR out of draft, §Closing links**

- §The proof store (lines 359-365): replace *"`review-pr`'s last action"* with *"the finish step's last action"*; replace *"A run that **halts** after the page exists opens it on the same rule"* with *"A run that **halts** after the page exists opens it on the same rule: the dispatcher runs `proof_cli.php open <artifacts.proof>` when that pointer is set"*.
- §Who takes the PR out of draft (lines 389-408): replace *"until **`review-pr` has passed**"* with *"until **`review-pr`'s finish step**"*; replace the sentence starting *"When dispatching `implement`, say"* with *"The `implement` brief carries it verbatim (`pipeline_leg_overrides()`): **"Leave the PR draft; this overrides any mark-ready instruction in the plan, the PR comment, or `work-on`'s own logic."**"*
- §Closing links (line 429): replace *"**At `review-pr`, before `gh pr ready`, reconcile**"* with *"**At `review-pr`'s finish step, before `gh pr ready`, reconcile**"*.

- [ ] **Step 6: Replace §What a leg brief consists of, the first paragraph and list (lines 464-475), with**

```markdown
## What a leg brief consists of

Every brief is generated by `pipeline_brief($manifest, $leg)` (`../checks/brief.php`); nobody writes
one by hand — not the dispatcher, not a leg, not a coordinator. The dispatcher's prompt is one line
naming the brief file. A brief consists of:

- **pointers** to the artifacts (idea, spec, plan, PR, issue, proof page), the manifest, and — on a
  resolve step or after a loop-back — the ledger entry to act on, by index;
- **the settled decisions** (`decisions`) and the manifest state the step needs, including §Suite
  reuse's last suite tree and the permitted design size on `design`;
- **the overrides for that leg and step** from `pipeline_leg_overrides()`, pointing at the section of
  this file that holds each rule, e.g. *"leave the PR draft"* (§Who takes the PR out of draft);
- **the return contract**: which keys the step may write and which statuses it may return;
- **nothing a station does not ask for.** No test policy, proof format or process of anyone's own
  invention.

**A review step's brief is crafted context** (`../../critique/SKILL.md` §Reviewer contract): pointers,
decisions and overrides — never an earlier review, an earlier action, or another step's output.
```

Keep the rest of the section (the not-exemplars paragraph and the invented-rules table).

- [ ] **Step 7: Replace §`auto` "What the engine does with a review" and the independent read (lines 585-617) with**

```markdown
## `auto` — a fresh agent resolves the review

`interactive` gives every resolve step to the human (§Interactive). Everything below is the `auto`
resolve step.

**A review is prose, not a verdict.** `/critique` returns the review it wrote — no severity ranking,
no verdict enum, no structured block (`../../critique/SKILL.md`). The review step stores it verbatim in
the open ledger entry; the resolve step reads it the way a person would and acts on its own judgment.
The risk position behind that: the pipeline never merges, so every output is a PR read before merge
and the worst case is a discarded branch, while a needless interrupt costs the one thing `auto` exists
to protect.

**What the resolve step does with a review** — and its brief says so:

- **Act on what is worth acting on.** Edits to the spec, the plan or the code, and small code fixes,
  are integrated and committed by the resolve step. Rework — a review saying the work is
  fundamentally wrong — is not an edit; it loops back (next bullet). Record the rest —
  already-mitigated observations, notes for posterity — without an edit. **Change nothing the review
  did not name.**
- **Loop back** where the review says the work is fundamentally wrong: `review-plan` → `design`,
  `verify-ui` → `implement`, `review-pr` → `implement` (`gates.md` §Loop-backs). Bounded (§Failure
  policy).
- **Never interrupt on a finding.** Anything unresolved goes into the PR body as an open question,
  carried **verbatim**. Ambiguity buys a line in the PR, not an interrupt.
- **Log** the actions and the outcome on the open entry (`manifest.md`), projected onto the PR.
  *Overruling a reviewer is fine; overruling one invisibly is what turns a gate into decoration.*

**Why a fresh agent, not the dispatcher.** The rule this replaces kept review fixes in the engine
session because a fresh agent must first re-read what the engine held (spec 2026-09-14 §5, n=2). The
2026-09-22 audit measured the other side: in-engine review-fix phases cost a median 0.92M weighted
tokens at 250k+ context, against about 0.4–0.5M for a fresh agent doing the same work.

**An independent read is available, and is not a routing rule.** At `review-plan` the resolve step is
judging a critique of a plan another agent wrote, with the author's framing in the spec. So where
acting on a point is expensive and the resolve step doubts it, it dispatches a **fresh agent that never
saw the design leg**, gives it the point plus the code, and asks it to refute the claim citing
`file:line`. That is judgment exercised where it pays, not a mandatory step with an outcome enum — and
it cannot stop the run; it only informs what the resolve step does next.
```

- [ ] **Step 8: Update §Failure policy (lines 619-661)**

- First bullet: replace *"or the reviewer returns nothing after a single retry"* with *"or a review step returns nothing after a single retry (`dispatch_cli.php` answers `retry` once, then `halt`)"*. Replace *"Write the failure to the manifest"* with *"`returned` writes the failure to the manifest (`cursor.status: halted`, `cursor.reason`)"*.
- Bound exhaustion, *After `handoff`* bullet: replace *"Leave it **draft**, write the reason into the PR body, stop."* with *"Leave it **draft**, append the reason to the PR body without reading it (`gh pr view <pr> --json body --jq .body > "$TMPDIR/body.md"`, append the reason, `gh pr edit <pr> --body-file "$TMPDIR/body.md"`), stop."*
- Bound exhaustion, *Count the cycles* bullet: prepend *"`pipeline_returned()` does the counting:"*.
- Add a bullet after *Mechanical-check exhaustion*: *"- **A return the dispatcher cannot account for** — a moved cursor, a key only the dispatcher writes, a rewritten ledger entry, a status the ledger does not support (`manifest.md` §What a leg writes) → **halt**, with `returned`'s reason."*

- [ ] **Step 9: Update §Suite reuse and §Mechanical checks**

- §Suite reuse, *The reviewer is told* bullet: replace *"The `review-pr` brief states"* with *"`pipeline_brief()` states, from the manifest,"*.
- §Mechanical checks, *Into `review-pr`* paragraph: replace *"The brief states the result"* with *"The review step states the result, in its `/critique pr` invocation,"*.

- [ ] **Step 10: Update `skills/pipeline/SKILL.md`**

Replace lines 15-17 (*"Core principle: … See the references before driving a run — the enforcement lives there, not in this summary:"*) with:

```markdown
Core principle: **a dispatcher that only loops** — read the manifest, ask `dispatch_cli.php` for the
next step, dispatch it as a fresh agent with the brief `pipeline_brief()` generated, and let the same
command validate what came back. The dispatcher never reads artifacts, reviews, diffs or test output,
never edits and never runs the suite; review fixes and finishing the PR belong to fresh resolve
agents. No long-lived brain; a lost run reconstructs from git + gh. See the references before driving
a run — the enforcement lives there, not in this summary:
```

After the *Visual proof* bullet add:

```markdown
- **The 150k invariant** — `/pipeline auto` runs the dispatcher as one background agent; after its
  completion notice, the invoking session runs `php checks/engine_peak_cli.php <its agent id>` and
  reports the line. Over 150k peak context is an annotation, never a halt
  (`references/engine.md` §The dispatcher).
```

- [ ] **Step 11: Update `skills/orchestrate/SKILL.md` Step 5 (line 26)**

After *"**A run returns.**"* insert: *"Run `php ~/.claude/skills/pipeline/checks/engine_peak_cli.php <its agent id>` and put the line in whichever report follows (pipeline `engine.md` §The dispatcher)."* Change nothing else in the file.

- [ ] **Step 12: Check the removed rule is gone and nothing names the old engine behaviour**

Run: `grep -n "in the engine session\|the engine reads it and acts\|the engine reads them and acts\|When dispatching \`implement\`\|The engine knows what it built\|When the engine believes\|Record \`outcome: halted\`" skills/pipeline/references/engine.md skills/pipeline/references/gates.md skills/pipeline/references/manifest.md skills/pipeline/SKILL.md`
Expected: no output. Each of these named the old in-engine behaviour (gates.md:22 and :49, engine.md:454, :578 and :646, manifest.md:47 at the base commit): reword each to name the step that now does it (the resolve step, the finish step, or the leg). Bound exhaustion no longer records `outcome: halted` on the entry — the resolve step writes `looped-back` and the dispatcher halts via `cursor` — so engine.md:646 says that instead. Also document the dispatcher-written cursor status `done` (a finished run; `next` answers `done` and dispatches nothing) wherever manifest.md lists cursor statuses.

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS (`LockStepTest` still green).

- [ ] **Step 13: Commit**

```bash
git add skills/pipeline/references/engine.md skills/pipeline/SKILL.md skills/orchestrate/SKILL.md
git commit -m "pipeline: engine.md describes the dispatcher loop and fresh resolve agents"
```

---

### Task 8: Verify, and the PR body's measurement section

**Files:** none changed.

- [ ] **Step 1: Both suites**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, all tests.

Run: `./vendor/bin/pest -c skills/critique/checks/phpunit.xml --test-directory=skills/critique/checks/tests`
Expected: PASS.

- [ ] **Step 2: The CLIs run from a clean shell**

Run: `php skills/pipeline/checks/dispatch_cli.php` → exit 1 with the usage line.
Run: `php skills/pipeline/checks/engine_peak_cli.php nonexistent0` → `engine nonexistent0: not measured (no transcript)`, exit 0.

- [ ] **Step 3: Nothing stray**

Run: `git status --short` → empty. Run: `git log --oneline origin/main..HEAD` → the spec, the plan and the task commits only.

- [ ] **Step 4: The PR body carries this section** (written by the step that edits the PR body; no file in the repo)

```markdown
## Measurement

**Baseline** (token audit 2026-09-22, `~/.claude/token-audit/2026-09-22/`): since 2026-09-14 the
`/pipeline auto` engine had a median peak context of **253k** (12 of 17 above 200k), and engines were
**18.1 percent** of all usage.

**Invariant:** dispatcher peak context under 150k per run — an annotation, not a halt.

**How the next real run is measured:** after the first `/pipeline auto` run on this engine, the
invoking session runs `php ~/.claude/skills/pipeline/checks/engine_peak_cli.php <dispatcher agent id>`
for its peak against 150k. The share of usage is re-measured with the audit scripts
(`cd ~/.claude/token-audit/2026-09-22 && python3 usage.py && python3 an13.py`).

**Port cross-check:** <the two lines from Task 5 Step 5>.
```
