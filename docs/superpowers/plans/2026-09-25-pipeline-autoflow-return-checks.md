# `autoflow`'s `brief` and `finish` check the step before them Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task by task. Steps use checkbox (`- [ ]`) syntax for tracking. In an `autoflow` implement step, execute inline, task by task, with no subagents.

**Goal:** In `autoflow`, every step's return is checked at the next step boundary, as `returned` checks it in `auto`. `brief` checks the step before it, and `finish` checks the last one. Both halt fail-closed with a named reason and leave the cursor on the step that failed. `pipeline_ledger()` (#53) names the "no ledger is an empty ledger" rule once.

**Architecture:**
- `pipeline_reported_problem()` in `dispatch.php` is pure. It runs `returned`'s `pipeline_return_problem()`, then compares what the script was told (status, `ui`, `size`) with the manifest, the diff and the spec.
- `dispatch_cli.php brief` takes `--after <leg>:<step> --status … [--ui …] [--size …]`. It finds the step its snapshot `<stem>.before.json` was taken for, runs the check, and on a pass writes the cursor and a new snapshot.
- `finish` runs the check for `review-pr:resolve` before `done`. A halt it records keeps a `halted` cursor's leg.
- `pipeline-autoflow.js` keeps the last step's result in `last`, and `briefCommand()` turns it into the flags.
- `AutoflowScriptTest`'s replay harness can play stub steps against the real `brief` (the replay smoke pass).

**Tech Stack:** PHP 8.4 on the host, Pest 4 (`./vendor/bin/pest`), Node 24 (the replay test), plain JavaScript (the Workflow script), Markdown skill references.

**Spec:** `docs/superpowers/specs/2026-09-25-pipeline-autoflow-return-checks-design.md`

**Verified before writing (2026-09-25):**
- All of this plan's code and docs were assembled into a scratch copy of this branch (at `883e557`), and the whole pipeline suite passes there: 298 tests, against 261 on main. The count is 261, minus 1 (the `done` row of the `finish` dataset, which becomes its own test in Task 4), plus 38 new: 21 in `DispatchCliTest`, 13 in `ReturnedTest`, 1 in `ManifestTest` and 3 in `AutoflowScriptTest`. The critique suite passes too: 14 tests.
- The new tests run against today's code: 36 fail and 2 pass. The two that pass are the replay smoke pass's clean walk and "checks nothing on the first step of a run". Both pin behaviour that must not change: a legitimate run is not halted, and the first step of a run is not checked.
- **`vendor/` must be a real directory, not a symlink to the primary checkout's.** Pest takes the project root from the real path of `vendor/`. With a symlinked `vendor/`, the in-process tests load the primary checkout's `tests/Pest.php` and its `checks/*.php`, so they test `main`'s code. The tests that spawn `dispatch_cli.php` still run this branch's code, so the mix fails in confusing ways. This worktree has a real copy at the time of writing. If yours does not: `rm vendor; cp -R <primary checkout>/vendor vendor` (it is gitignored).

## Global Constraints

- Brief flags: `brief <manifest> <leg> <step> [--after <leg>:<step> --status <status> [--ui true|false] [--size <size>]]`. With no `--after` there must be no other flag. A flag the command does not know, a flag without a value, a flag given twice, an `--after` that is not a `<leg>:<step>` of `pipeline_steps()`, or fewer or more than three positional arguments is a usage error: exit 1, no JSON.
- Flag values are compared as given: `--ui` as the string `true`/`false` against `json_encode()` of the diff's `ui`, and `--size` against `DesignSize->value`. A missing value reads as `nothing` in the message.
- The snapshot stays the plain manifest, in `dispatch_cli_files($manifestPath)['before']`. The step it was taken for is `"{$cursor['leg']}:" . pipeline_step($snapshot, $cursor['leg'])`.
- `brief` checks in this order: readable manifest → `dispatch_cli_invalid()` → mode → the boundary check → `pipeline_step_problem()`. The first three and the last halt and write nothing. A boundary halt writes `cursor: {leg: <the step that failed>, status: halted, reason}` through `dispatch_cli_halt()`.
- These messages are verbatim, with a step written as `<leg> <step>`, for example `the handoff run step`:
  - `cannot check the <after> step's return: no snapshot at <before path>`
  - `the <next> step returned nothing and changed the manifest`
  - `the snapshot is of the <snapshot> step, but the script says <after> returned: it did not run brief`
  - `the <leg> <step> step returned without writing the manifest`
  - `the <leg> <step> step returned <reported> to the script but wrote <status> into the manifest`
  - `the implement step returned ui: <reported>, but its diff says <true|false>`
  - `the design step returned size <reported>, but the spec says <size>`
  - `the implement step did not write <stem>.diff`
  - `the workflow returned done, but the last snapshot is of the <snapshot> step`
  - `the workflow returned done, but there is no snapshot at <before path>`
- `launch` does not change. `next` and `returned` do not change.
- `pipeline_ledger()` returns `$manifest['gate_ledger'] ?? []`. After Task 1, `grep -rn "gate_ledger'\] ??" skills/pipeline/checks` finds only its body.
- Suites (from the worktree root, on the host):
  - `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
  - `./vendor/bin/pest -c skills/critique/checks/phpunit.xml --test-directory=skills/critique/checks/tests`

## Review Focus

These are inputs the spec implies but no *Done when* item names, each with the test that pins it:

- **A legitimate return that is not `continued`** must pass the check. Examples: an Architectural plan gap from `implement`, a Bounded escalation from a review step, a `review-pr` loop-back, a `verify-ui` loop-back. A false halt here would stop every run that loops. Pinned by Task 2's "passes the returns that route elsewhere".
- **The script's Opus retry of a review step** runs `brief` twice for the same step with the same flags. The second `brief` must brief, not halt. Pinned by Task 3's retry test, and by Task 5's clean walk, where `review-pr:review` returns `null` once.
- **A relaunch after a halt.** The first `brief` sees an old snapshot and a `halted` cursor, and must not check them. Pinned by Task 3's "checks nothing on the first step of a run".
- **A stale `<stem>.diff`**, written by the invoking session before `launch`, must not stand in for the implement step's own. Pinned by Task 3's "a diff older than the step" row.
- **A halt that lands on the wrong leg** would let a resume walk past the failure. Pinned by Task 4's "keeps the leg of a halt the manifest already records", and end to end by Task 5's replay smoke pass, which runs `finish` on the script's halt.

## File Structure

- Modify `skills/pipeline/checks/manifest.php`: `pipeline_ledger()`.
- Modify `skills/pipeline/checks/dispatch.php`: `pipeline_reported_problem()`, and the ledger reads.
- Modify `skills/pipeline/checks/dispatch_cli.php`: `brief`'s flags and boundary check, `finish`'s check and halt rule, `dispatch_cli_files()['diff']`, the usage docblock and line, and the ledger reads.
- Modify `skills/pipeline/checks/brief.php` and `skills/pipeline/checks/run_audit.php`: the ledger reads; `run_audit.php`'s docblock.
- Modify `skills/pipeline/workflow/pipeline-autoflow.js`: `last` and `briefCommand()`.
- Modify `skills/pipeline/checks/tests/autoflow_replay.mjs`: `prompts`, `null` returns, and `steps` mode.
- Tests: `ManifestTest.php`, `ReturnedTest.php`, `DispatchCliTest.php`, `AutoflowScriptTest.php`.
- Docs: `skills/pipeline/references/engine.md`, `skills/pipeline/references/manifest.md`, `skills/orchestrate/references/commands.md`.

---

### Task 1: `pipeline_ledger()` (#53)

**Files:**
- Modify: `skills/pipeline/checks/manifest.php` (append)
- Modify: `skills/pipeline/checks/dispatch.php:105,189,267,268,336`, `skills/pipeline/checks/brief.php:100,166`, `skills/pipeline/checks/dispatch_cli.php:159,178`, `skills/pipeline/checks/run_audit.php:96`
- Test: `skills/pipeline/checks/tests/ManifestTest.php`

**Interfaces:**
- Produces: `pipeline_ledger(array $manifest): array`, loaded wherever `manifest.php` is (every entry point and `tests/Pest.php`).

- [ ] **Step 1: Write the failing test** (append to `ManifestTest.php`)

```php

it('reads a manifest without a ledger as an empty one', function () {
    $entry = ['gate' => 'plan-approval', 'review' => 'r'];

    expect(pipeline_ledger(['cursor' => []]))->toBe([]);
    expect(pipeline_ledger(['gate_ledger' => [$entry]]))->toBe([$entry]);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='without a ledger'`
Expected: FAIL, `Call to undefined function pipeline_ledger()`.

- [ ] **Step 3: Add the accessor** (append to `manifest.php`)

```php

/** A manifest without a ledger has an empty one. */
function pipeline_ledger(array $manifest): array
{
    return $manifest['gate_ledger'] ?? [];
}
```

- [ ] **Step 4: Replace every read**

```bash
cd skills/pipeline/checks
perl -0pi -e 's/\$manifest\[.gate_ledger.\] \?\? \[\]/pipeline_ledger(\$manifest)/g; s/\$before\[.gate_ledger.\] \?\? \[\]/pipeline_ledger(\$before)/g; s/\$after\[.gate_ledger.\] \?\? \[\]/pipeline_ledger(\$after)/g' dispatch.php brief.php dispatch_cli.php run_audit.php
grep -rn "gate_ledger'\] ??" .     # only manifest.php's body
grep -c "pipeline_ledger(" dispatch.php brief.php dispatch_cli.php run_audit.php   # 5, 2, 2, 1
```

- [ ] **Step 5: Run the pipeline suite**

Expected: 262 passed.

- [ ] **Step 6: Commit**

```bash
git add skills/pipeline/checks
git commit -m "refactor(pipeline): pipeline_ledger() names the empty-ledger rule once (#53)"
```

---

### Task 2: `pipeline_reported_problem()`

**Files:**
- Modify: `skills/pipeline/checks/dispatch.php` (insert before `/** @return list<string> top-level keys, and `cursor.*` one level down, whose values differ */`)
- Test: `skills/pipeline/checks/tests/ReturnedTest.php` (append)

**Interfaces:**
- Consumes: `pipeline_normalized()`, `pipeline_step()`, `pipeline_return_problem()` (unchanged), `DesignSize`.
- Produces: `pipeline_reported_problem(array $before, array $after, array $reported, DesignSize $size, ?bool $diffUi = null): ?string`. `$reported` has the string keys `status`, and optionally `ui` or `size` (extra keys such as `after` are ignored). `$diffUi` is read only when the snapshot's leg is `implement`.

- [ ] **Step 1: Write the failing tests** (append to `ReturnedTest.php`)

```php

it('passes a return that agrees with what the step reported to the script', function (string $leg, array $changes, array $reported, ?bool $diffUi) {
    $before = returned_before($leg);

    expect(pipeline_reported_problem($before, returned_after($before, 'continued', null, $changes), $reported, DesignSize::Architectural, $diffUi))->toBeNull();
})->with([
    'handoff' => ['handoff', ['artifacts' => ['spec' => 'docs/spec.md', 'plan' => 'docs/plan.md', 'pr' => 7, 'issue' => null]], ['status' => 'continued'], null],
    'implement on a UI diff' => ['implement', ['last_sha' => 'bbb2222'], ['status' => 'continued', 'ui' => 'true'], true],
    'design' => ['design', ['last_sha' => 'bbb2222'], ['status' => 'continued', 'size' => 'Architectural'], null],
]);

it('names what a step reported that its manifest does not say', function (string $leg, bool $writes, array $reported, ?bool $diffUi, string $problem) {
    $before = returned_before($leg);
    $after = $writes ? returned_after($before, 'continued', null, ['last_sha' => 'bbb2222']) : $before;

    expect(pipeline_reported_problem($before, $after, $reported, DesignSize::Architectural, $diffUi))->toBe($problem);
})->with([
    'nothing written' => ['handoff', false, ['status' => 'continued'], null, 'the handoff run step returned without writing the manifest'],
    'another status' => ['handoff', true, ['status' => 'halted'], null, 'the handoff run step returned halted to the script but wrote continued into the manifest'],
    'no status' => ['handoff', true, [], null, 'the handoff run step returned nothing to the script but wrote continued into the manifest'],
    'ui false on a UI diff' => ['implement', true, ['status' => 'continued', 'ui' => 'false'], true, 'the implement step returned ui: false, but its diff says true'],
    'another size' => ['design', true, ['status' => 'continued', 'size' => 'Bounded'], null, 'the design step returned size Bounded, but the spec says Architectural'],
]);

it('reports a manifest problem before anything the step reported', function () {
    $before = returned_before('handoff');

    expect(pipeline_reported_problem($before, returned_after($before, 'continued', null, ['mode' => 'auto', 'branch' => 'other']), ['status' => 'halted'], DesignSize::Architectural))
        ->toBe('the leg changed branch, which only the dispatcher writes');
});

it('passes the returns that route elsewhere than on, when the ledger bears them out', function (string $leg, array $ledger, string $status, array $newLedger, array $reported, DesignSize $size) {
    $before = returned_before($leg, $ledger);

    expect(pipeline_reported_problem($before, returned_after($before, $status, $newLedger, [], $status === 'plan-insufficient' ? 'gap' : null), $reported, $size, false))->toBeNull();
})->with([
    'an Architectural plan gap from implement' => ['implement', [], 'plan-insufficient', [['gate' => 'plan-approval', 'leg' => 'implement', 'cycle' => 2, 'at' => '2026-09-25T11:00:00Z', 'reason' => 'gap', 'outcome' => 'looped-back']], ['status' => 'plan-insufficient', 'ui' => 'false'], DesignSize::Architectural],
    'a Bounded escalation from a review step' => ['review-plan', [], 'plan-insufficient', [['gate' => 'design-size', 'leg' => 'review-plan', 'at' => '2026-09-25T11:00:00Z', 'reason' => 'gap', 'outcome' => 'escalated']], ['status' => 'plan-insufficient'], DesignSize::Bounded],
    'a review-pr loop-back' => ['review-pr', [['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => '2026-09-25T10:00:00Z', 'review' => 'r']], 'looped-back', [['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => '2026-09-25T10:00:00Z', 'review' => 'r', 'actions' => ['rework'], 'outcome' => 'looped-back']], ['status' => 'looped-back'], DesignSize::Architectural],
    'a verify-ui loop-back' => ['verify-ui', [], 'looped-back', [['gate' => 'verify-ui', 'leg' => 'verify-ui', 'at' => '2026-09-25T10:00:00Z', 'outcome' => 'looped-back']], ['status' => 'looped-back'], DesignSize::Architectural],
]);
```

- [ ] **Step 2: Run them to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter=ReturnedTest`
Expected: 13 FAIL, `Call to undefined function pipeline_reported_problem()`.

- [ ] **Step 3: Write the function** (in `dispatch.php`, directly after `pipeline_return_problem()`)

```php
/**
 * `autoflow`'s check at the next step boundary (`brief` and `finish`): the step the snapshot was taken
 * for, against the manifest it left and what it returned to the script. `$reported` holds the flags
 * `brief` was given (`status`, and `ui` or `size`, as strings); `$diffUi` is `pipeline_triggers()['ui']`
 * over the step's diff, read only after `implement`.
 */
function pipeline_reported_problem(array $before, array $after, array $reported, DesignSize $size, ?bool $diffUi = null): ?string
{
    [$before, $after] = [pipeline_normalized($before), pipeline_normalized($after)];
    $leg = $before['cursor']['leg'];
    $step = pipeline_step($before, $leg);
    if ($after === $before) {
        return "the {$leg} {$step} step returned without writing the manifest";
    }

    $told = fn (string $key) => (string) ($reported[$key] ?? 'nothing');
    $status = (string) ($after['cursor']['status'] ?? '');

    return pipeline_return_problem($before, $after, $leg, $step, $size) ?? match (true) {
        $told('status') !== $status => "the {$leg} {$step} step returned {$told('status')} to the script but wrote {$status} into the manifest",
        $leg === 'implement' && $told('ui') !== json_encode($diffUi) => "the implement step returned ui: {$told('ui')}, but its diff says " . json_encode($diffUi),
        $leg === 'design' && $told('size') !== $size->value => "the design step returned size {$told('size')}, but the spec says {$size->value}",
        default => null,
    };
}
```

- [ ] **Step 4: Run the pipeline suite**

Expected: 275 passed.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/dispatch.php skills/pipeline/checks/tests/ReturnedTest.php
git commit -m "feat(pipeline): pipeline_reported_problem() checks a step's return against what it told the script (#70)"
```

---

### Task 3: `brief` checks the step before it

**Files:**
- Modify: `skills/pipeline/checks/dispatch_cli.php` (`dispatch_cli_files()`, `dispatch_cli_brief()` and three new functions after it, the argument parser before `$flag = array_search(...)`, the `'brief'` arm, the usage docblock and line)
- Test: `skills/pipeline/checks/tests/DispatchCliTest.php`

**Interfaces:**
- Consumes: `pipeline_reported_problem()` (Task 2), `pipeline_ledger()` (Task 1), `dispatch_cli_halt()`, `dispatch_cli_design_size()`, `pipeline_triggers()`, `pipeline_step()`, `pipeline_normalized()`.
- Produces:
  - `dispatch_cli_files(string): array{brief, before, diff}`, where `diff` is `<stem>.diff`;
  - `dispatch_cli_brief(string $manifestPath, string $leg, string $step, array $reported = []): array|string`;
  - `dispatch_cli_snapshot_step(?array $before): ?string`;
  - `dispatch_cli_return_problem(string $manifestPath, array $before, array $manifest, array $reported): ?array{leg, reason}` (Task 4 calls it);
  - in `DispatchCliTest`, `dispatch_fixture()` gains `stepDiff`, and the helpers `boundary_fixture()`, `boundary_brief()` and `boundary_open()` (Task 4 uses them).

- [ ] **Step 1: Write the failing tests**

In `dispatch_fixture()`'s return, add `'stepDiff' => $dir . '/.claude/pipeline/feature-x.diff'` after `'before' => …`, so the line reads:

```php
    return ['dir' => $dir, 'manifest' => $path, 'diff' => $dir . '/pipeline.diff', 'brief' => $dir . '/.claude/pipeline/feature-x.brief.md', 'before' => $dir . '/.claude/pipeline/feature-x.before.json', 'stepDiff' => $dir . '/.claude/pipeline/feature-x.diff'];
```

Insert this block directly before `it('halts a launch on a manifest without a mode, printing only the JSON line'`:

```php
/** An `autoflow` run whose `$leg` `$step` was briefed as a run's first step: its snapshot is on disk. */
function boundary_fixture(string $leg, string $step, array $manifest = []): array
{
    $fixture = dispatch_fixture(['mode' => 'autoflow', ...$manifest]);
    expect(dispatch_cli(['brief', $fixture['manifest'], $leg, $step])['stdout'])->toContain("`{$leg}` leg, `{$step}` step");

    return $fixture;
}

/** The step the script briefs next, told what the step before it returned. */
function boundary_brief(array $fixture, string $leg, string $step, string $after, array $reported): array
{
    return dispatch_cli(['brief', $fixture['manifest'], $leg, $step, '--after', $after, ...$reported]);
}

function boundary_open(string $gate = 'plan-approval'): array
{
    return ['gate' => $gate, 'leg' => pipeline_leg_of_gate($gate), 'cycle' => 1, 'at' => '2026-09-25T10:00:00Z', 'review' => 'r', 'annotations' => []];
}

it('halts the next brief when a resolve step left its entry open, with the cursor on that step', function () {
    $fixture = boundary_fixture('review-plan', 'resolve', ['gate_ledger' => [boundary_open()]]);
    dispatch_leg_writes($fixture['manifest'], fn (array $m) => [...$m, 'cursor' => [...$m['cursor'], 'status' => 'continued']]);

    $halt = boundary_brief($fixture, 'handoff', 'run', 'review-plan:resolve', ['--status', 'continued'])['json'];

    $reason = "the resolve step must set the open plan-approval entry's outcome to continued";
    expect($halt)->toBe(['action' => 'halt', 'reason' => $reason]);
    expect(manifest_read($fixture['manifest'])['cursor'])->toBe(['leg' => 'review-plan', 'status' => 'halted', 'reason' => $reason]);
});

it('halts the next brief when implement reported ui false on a UI diff, or wrote no diff of its own', function (bool $stale, string $reason) {
    $fixture = boundary_fixture('implement', 'run');
    dispatch_leg_writes($fixture['manifest'], fn (array $m) => [...$m, 'cursor' => [...$m['cursor'], 'status' => 'continued'], 'last_sha' => 'bbb2222']);
    file_put_contents($fixture['stepDiff'], dispatch_ui_diff());
    if ($stale) {
        touch($fixture['stepDiff'], time() - 60);
    }

    $halt = boundary_brief($fixture, 'review-pr', 'review', 'implement:run', ['--status', 'continued', '--ui', 'false'])['json'];

    expect($halt)->toBe(['action' => 'halt', 'reason' => str_replace('<diff>', $fixture['stepDiff'], $reason)]);
    expect(manifest_read($fixture['manifest'])['cursor'])->toMatchArray(['leg' => 'implement', 'status' => 'halted']);
})->with([
    'ui false on a UI diff' => [false, 'the implement step returned ui: false, but its diff says true'],
    'a diff older than the step' => [true, 'the implement step did not write <diff>'],
]);

it('halts the next brief when a step changed what only the engine writes', function (callable $change, string $reason) {
    $passed = [...boundary_open(), 'actions' => [], 'outcome' => 'continued'];
    $fixture = boundary_fixture('handoff', 'run', ['gate_ledger' => [$passed]]);
    dispatch_leg_writes($fixture['manifest'], $change);

    expect(boundary_brief($fixture, 'implement', 'run', 'handoff:run', ['--status', 'continued'])['json'])->toBe(['action' => 'halt', 'reason' => $reason]);
    expect(manifest_read($fixture['manifest'])['cursor'])->toMatchArray(['leg' => 'handoff', 'status' => 'halted']);
})->with([
    'a moved cursor' => [fn (array $m) => [...$m, 'cursor' => ['leg' => 'implement', 'status' => 'continued']], 'the leg changed cursor.leg, which only the dispatcher writes'],
    'a rewritten ledger entry' => [fn (array $m) => [...$m, 'cursor' => [...$m['cursor'], 'status' => 'continued'], 'gate_ledger' => [[...$m['gate_ledger'][0], 'outcome' => 'looped-back']]], 'the leg rewrote ledger entry 0'],
]);

it('briefs the next step after a clean return, and takes its snapshot', function () {
    $fixture = boundary_fixture('handoff', 'run');
    dispatch_leg_writes($fixture['manifest'], fn (array $m) => [...$m, 'cursor' => [...$m['cursor'], 'status' => 'continued'], 'artifacts' => [...$m['artifacts'], 'pr' => 7]]);

    $result = boundary_brief($fixture, 'implement', 'run', 'handoff:run', ['--status', 'continued']);

    expect($result['stdout'])->toContain('`implement` leg, `run` step')->toContain('- pr: `7`');
    expect(manifest_read($fixture['manifest'])['cursor'])->toBe(['leg' => 'implement', 'status' => 'pending']);
    expect(manifest_read($fixture['before']))->toBe(manifest_read($fixture['manifest']));
});

it('halts the next brief when the script was told another status or size than the step wrote', function (array $reported, string $reason) {
    $fixture = boundary_fixture('design', 'run', ['cursor' => ['leg' => 'design', 'status' => 'pending'], 'artifacts' => ['spec' => 'spec.md', 'plan' => null, 'pr' => null, 'issue' => null]]);
    file_put_contents($fixture['dir'] . '/spec.md', "# x — design\n\n**Design size:** Architectural\n");
    dispatch_leg_writes($fixture['manifest'], fn (array $m) => [...$m, 'cursor' => [...$m['cursor'], 'status' => 'continued'], 'last_sha' => 'aaa1111']);

    expect(boundary_brief($fixture, 'review-plan', 'review', 'design:run', $reported)['json'])->toBe(['action' => 'halt', 'reason' => $reason]);
})->with([
    'another status' => [['--status', 'halted', '--size', 'Architectural'], 'the design run step returned halted to the script but wrote continued into the manifest'],
    'another size' => [['--status', 'continued', '--size', 'Bounded'], 'the design step returned size Bounded, but the spec says Architectural'],
    'no size' => [['--status', 'continued'], 'the design step returned size nothing, but the spec says Architectural'],
]);

it('checks nothing on the first step of a run, even over an earlier run\'s snapshot', function () {
    $fixture = boundary_fixture('handoff', 'run');
    dispatch_leg_writes($fixture['manifest'], fn (array $m) => [...$m, 'cursor' => ['leg' => 'handoff', 'status' => 'halted', 'reason' => 'an earlier run']]);

    expect(dispatch_cli(['brief', $fixture['manifest'], 'handoff', 'run'])['stdout'])->toContain('`handoff` leg, `run` step');
});

it('halts a brief told of a step that left no snapshot, or not its own', function () {
    $bare = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'handoff', 'status' => 'continued']]);
    expect(boundary_brief($bare, 'implement', 'run', 'handoff:run', ['--status', 'continued'])['json'])
        ->toBe(['action' => 'halt', 'reason' => "cannot check the handoff run step's return: no snapshot at {$bare['before']}"]);
    expect(manifest_read($bare['manifest'])['cursor'])->toMatchArray(['leg' => 'handoff', 'status' => 'halted']);

    $other = boundary_fixture('design', 'run', ['cursor' => ['leg' => 'design', 'status' => 'pending']]);
    dispatch_leg_writes($other['manifest'], fn (array $m) => [...$m, 'cursor' => [...$m['cursor'], 'status' => 'continued']]);
    expect(boundary_brief($other, 'implement', 'run', 'handoff:run', ['--status', 'continued'])['json'])
        ->toBe(['action' => 'halt', 'reason' => 'the snapshot is of the design run step, but the script says handoff run returned: it did not run brief']);
});

it('briefs a review step again after an attempt that returned nothing, unless that attempt wrote', function () {
    $fixture = boundary_fixture('review-pr', 'review', ['cursor' => ['leg' => 'review-pr', 'status' => 'pending']]);
    $again = fn () => boundary_brief($fixture, 'review-pr', 'review', 'implement:run', ['--status', 'continued', '--ui', 'false']);

    expect($again()['stdout'])->toContain('`review-pr` leg, `review` step');

    dispatch_leg_writes($fixture['manifest'], fn (array $m) => [...$m, 'last_sha' => 'ccc3333']);
    expect($again()['json'])->toBe(['action' => 'halt', 'reason' => 'the review-pr review step returned nothing and changed the manifest']);
    expect(manifest_read($fixture['manifest'])['cursor'])->toMatchArray(['leg' => 'review-pr', 'status' => 'halted']);
});

it('refuses a brief it cannot parse', function (array $arguments) {
    $fixture = dispatch_fixture(['mode' => 'autoflow']);

    expect(dispatch_cli(['brief', $fixture['manifest'], ...$arguments])['code'])->toBe(1);
})->with([
    'no step' => [['handoff']],
    'an unknown flag' => [['handoff', 'run', '--sideways', 'x']],
    'a flag without its value' => [['handoff', 'run', '--after']],
    'an --after that is no step' => [['handoff', 'run', '--after', 'handoff:review', '--status', 'continued']],
    'a status without --after' => [['handoff', 'run', '--status', 'continued']],
    'a flag given twice' => [['handoff', 'run', '--after', 'design:run', '--status', 'continued', '--status', 'halted']],
]);

```

- [ ] **Step 2: Run them to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter=DispatchCliTest`
Expected: the new tests fail except "checks nothing on the first step of a run". Today `brief` takes no snapshot and ignores the flags: `boundary_fixture()` passes, but no halt comes, and the clean return's snapshot assertion fails. The usage cases exit 0.

- [ ] **Step 3: Implement**

In `dispatch_cli_files()`:

```php
/** @return array{brief: string, before: string, diff: string} */
function dispatch_cli_files(string $manifestPath): array
{
    $stem = preg_replace('/\.json$/', '', $manifestPath);

    return ['brief' => "{$stem}.brief.md", 'before' => "{$stem}.before.json", 'diff' => "{$stem}.diff"];
}
```

Replace `dispatch_cli_brief()` (docblock included) with:

```php
/**
 * An `autoflow` step's first command. It checks the step before it when the script names one
 * (`--after`), then the step the script chose becomes the cursor, the snapshot is taken, and the brief
 * is printed. A halt from that check leaves the cursor on the step that failed it.
 *
 * @param array{after?: string, status?: string, ui?: string, size?: string} $reported
 */
function dispatch_cli_brief(string $manifestPath, string $leg, string $step, array $reported = []): array|string
{
    $manifest = manifest_read($manifestPath);
    if ($manifest === null) {
        return pipeline_halt("no readable manifest at {$manifestPath}");
    }
    $problem = dispatch_cli_invalid($manifest) ?? dispatch_cli_mode_problem('brief serves autoflow steps', $manifest);
    if ($problem !== null) {
        return pipeline_halt($problem);
    }
    $boundary = dispatch_cli_boundary_problem($manifestPath, $manifest, "{$leg}:{$step}", $reported);
    if ($boundary !== null) {
        return dispatch_cli_halt($manifestPath, $manifest, $boundary['leg'], $boundary['reason']);
    }
    $problem = pipeline_step_problem($manifest, $leg, $step);
    if ($problem !== null) {
        return pipeline_halt($problem);
    }
    $manifest = [...$manifest, 'cursor' => ['leg' => $leg, 'status' => 'pending']];
    manifest_write($manifestPath, $manifest);
    manifest_write(dispatch_cli_files($manifestPath)['before'], $manifest);

    return pipeline_brief($manifest, $leg, $manifestPath, $step);
}

/**
 * What stops `$next` from being briefed after the step `--after` names: null when nothing does, or
 * when no step ran before it in this run.
 *
 * @return array{leg: string, reason: string}|null
 */
function dispatch_cli_boundary_problem(string $manifestPath, array $manifest, string $next, array $reported): ?array
{
    $after = $reported['after'] ?? null;
    if ($after === null) {
        return null;
    }
    $files = dispatch_cli_files($manifestPath);
    $before = manifest_read($files['before']);
    $snapshot = dispatch_cli_snapshot_step($before);
    $afterLeg = explode(':', $after)[0];
    $label = fn (string $pair) => str_replace(':', ' ', $pair);

    return match (true) {
        $snapshot === null => ['leg' => $afterLeg, 'reason' => "cannot check the {$label($after)} step's return: no snapshot at {$files['before']}"],
        $snapshot === $next => pipeline_normalized($before) === pipeline_normalized($manifest)
            ? null
            : ['leg' => $before['cursor']['leg'], 'reason' => "the {$label($next)} step returned nothing and changed the manifest"],
        $snapshot !== $after => ['leg' => $afterLeg, 'reason' => "the snapshot is of the {$label($snapshot)} step, but the script says {$label($after)} returned: it did not run brief"],
        default => dispatch_cli_return_problem($manifestPath, $before, $manifest, $reported),
    };
}

/** `<leg>:<step>` the snapshot was taken for, as `returned` reads it; null when there is no valid snapshot. */
function dispatch_cli_snapshot_step(?array $before): ?string
{
    if ($before === null || dispatch_cli_invalid($before) !== null) {
        return null;
    }
    $leg = $before['cursor']['leg'];

    return "{$leg}:" . pipeline_step($before, $leg);
}

/** `pipeline_reported_problem()` over the snapshot, with the design size and, after `implement`, the step's own diff. */
function dispatch_cli_return_problem(string $manifestPath, array $before, array $manifest, array $reported): ?array
{
    $leg = $before['cursor']['leg'];
    $files = dispatch_cli_files($manifestPath);
    if ($leg === 'implement' && (! is_file($files['diff']) || filemtime($files['diff']) < filemtime($files['before']))) {
        return ['leg' => $leg, 'reason' => "the implement step did not write {$files['diff']}"];
    }
    $diffUi = $leg === 'implement' ? pipeline_triggers((string) file_get_contents($files['diff']))['ui'] : null;
    $reason = pipeline_reported_problem($before, $manifest, $reported, dispatch_cli_design_size($manifest), $diffUi);

    return $reason === null ? null : ['leg' => $leg, 'reason' => $reason];
}
```

Directly before `$flag = array_search('--from', $argv, true);`:

```php
/**
 * `brief <manifest> <leg> <step> [--after <leg>:<step> --status <status> [--ui true|false] [--size <size>]]`;
 * null is a usage error. The values are the script's copy of the previous step's return, compared as given.
 *
 * @return array{0: string, 1: string, 2: string, 3: array<string, string>}|null
 */
function dispatch_cli_brief_args(array $arguments): ?array
{
    $positional = [];
    $reported = [];
    while ($arguments !== []) {
        $argument = (string) array_shift($arguments);
        if (! str_starts_with($argument, '--')) {
            $positional[] = $argument;

            continue;
        }
        $name = substr($argument, 2);
        $value = array_shift($arguments);
        if (! in_array($name, ['after', 'status', 'ui', 'size'], true) || $value === null || isset($reported[$name])) {
            return null;
        }
        $reported[$name] = (string) $value;
    }
    [$leg, $step] = explode(':', $reported['after'] ?? '', 2) + [1 => ''];
    $after = isset($reported['after']) ? in_array($leg, pipeline_legs(), true) && in_array($step, pipeline_steps($leg), true) : $reported === [];

    return count($positional) === 3 && $after ? [...$positional, $reported] : null;
}

function dispatch_cli_brief_command(array $arguments): array|string|null
{
    $parsed = dispatch_cli_brief_args($arguments);

    return $parsed === null ? null : dispatch_cli_brief(...$parsed);
}

```

The `'brief'` arm becomes:

```php
    'brief' => dispatch_cli_brief_command(array_slice($argv, 2)),
```

Then the usage text:
- In the top docblock, the `brief` line reads ` *                 php dispatch_cli.php brief <manifest> <leg> <step> [--after <leg>:<step> --status <status> [--ui true|false] [--size <size>]]`.
- In the same docblock, *"Exits 1 on a usage error (a `kickoff` it cannot parse included)"* becomes *"Exits 1 on a usage error (a `kickoff` or a `brief` it cannot parse included)"*. Keep the docblock's line wrap:

```php
 * one JSON line. Exits 0 on every decision, a halt included. Exits 1 on a usage error (a `kickoff`
 * or a `brief` it cannot parse included), and when `size` has no readable manifest or `ui` no diff file.
```

- In the `usage:` line on STDERR, `| brief <manifest> <leg> <step> |` becomes `| brief <manifest> <leg> <step> [--after <leg>:<step> --status <status> [--ui true|false] [--size <size>]] |`.

- [ ] **Step 4: Run the pipeline suite**

Expected: 293 passed. Task 2 left 275; this task adds 18.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/dispatch_cli.php skills/pipeline/checks/tests/DispatchCliTest.php
git commit -m "feat(pipeline): autoflow's brief checks the step before it against its snapshot (#70)"
```

---

### Task 4: `finish` checks the last step; a recorded halt keeps its leg

**Files:**
- Modify: `skills/pipeline/checks/dispatch_cli.php` (`dispatch_cli_finish()` and its docblock; new `dispatch_cli_finish_problem()` before `/** An `autoflow` design step's `size`` …)
- Test: `skills/pipeline/checks/tests/DispatchCliTest.php`

**Interfaces:**
- Consumes: `dispatch_cli_snapshot_step()`, `dispatch_cli_return_problem()`, `dispatch_cli_files()`, and the test helpers `boundary_fixture()`, `boundary_open()` (Task 3).
- Produces: `dispatch_cli_finish_problem(string $manifestPath, array $manifest): ?string`.

- [ ] **Step 1: Write the failing tests**

Remove the `'done' => ['review-pr', '{"action":"done"}', ['leg' => 'review-pr', 'status' => 'done']],` row from `it('records the workflow\'s return with finish', …)`'s dataset. A `done` with no snapshot now halts, and the test below covers `done`.

Append after Task 3's `it('refuses a brief it cannot parse', …)`:

```php
it('finishes only after a review-pr resolve step whose return holds', function () {
    $open = boundary_open('pr-review');
    $fixture = boundary_fixture('review-pr', 'resolve', ['cursor' => ['leg' => 'review-pr', 'status' => 'pending'], 'gate_ledger' => [$open]]);
    $finish = fn () => dispatch_cli(['finish', $fixture['manifest'], '{"action":"done"}'])['json'];

    dispatch_leg_writes($fixture['manifest'], fn (array $m) => [...$m, 'cursor' => [...$m['cursor'], 'status' => 'continued']]);
    expect($finish())->toBe(['action' => 'halt', 'reason' => "the resolve step must set the open pr-review entry's outcome to continued"]);
    expect(manifest_read($fixture['manifest'])['cursor'])->toMatchArray(['leg' => 'review-pr', 'status' => 'halted']);

    dispatch_leg_writes($fixture['manifest'], fn (array $m) => [...$m, 'cursor' => ['leg' => 'review-pr', 'status' => 'continued'], 'gate_ledger' => [[...$open, 'actions' => [], 'outcome' => 'continued']]]);
    expect($finish())->toBe(['action' => 'done']);
    expect(manifest_read($fixture['manifest'])['cursor'])->toBe(['leg' => 'review-pr', 'status' => 'done']);
});

it('refuses done when the last snapshot is not review-pr\'s resolve step, or there is none', function () {
    $review = boundary_fixture('review-pr', 'review', ['cursor' => ['leg' => 'review-pr', 'status' => 'pending']]);
    dispatch_leg_writes($review['manifest'], fn (array $m) => [...$m, 'cursor' => [...$m['cursor'], 'status' => 'continued'], 'gate_ledger' => [boundary_open('pr-review')]]);
    expect(dispatch_cli(['finish', $review['manifest'], '{"action":"done"}'])['json'])
        ->toBe(['action' => 'halt', 'reason' => 'the workflow returned done, but the last snapshot is of the review-pr review step']);

    $bare = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'review-pr', 'status' => 'pending']]);
    expect(dispatch_cli(['finish', $bare['manifest'], '{"action":"done"}'])['json'])
        ->toBe(['action' => 'halt', 'reason' => "the workflow returned done, but there is no snapshot at {$bare['before']}"]);
});

it('keeps the leg of a halt the manifest already records', function () {
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'review-plan', 'status' => 'halted', 'reason' => 'from brief']]);

    dispatch_cli(['finish', $fixture['manifest'], '{"action":"halt","leg":"handoff","reason":"from brief"}']);

    expect(manifest_read($fixture['manifest'])['cursor'])->toBe(['leg' => 'review-plan', 'status' => 'halted', 'reason' => 'from brief']);
});

```

- [ ] **Step 2: Run them to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter=DispatchCliTest`
Expected: 3 FAIL. Today `finish` answers `done` on an open entry and without a snapshot, and the halt goes to `handoff`.

- [ ] **Step 3: Implement**

In `dispatch_cli_finish()`, the docblock becomes:

```php
/**
 * Records the workflow's return; anything that is not `done` is a halt, and a halt with no reason says
 * so. `done` counts only on `review-pr`, and only when its resolve step's return holds: nothing else
 * may lead to `gh pr ready`. A halt keeps the cursor's leg when the cursor already records a halt
 * (`brief` or the step wrote it there) or when the return names no leg of the pipeline, so a later
 * `launch` resumes at the step that failed.
 */
```

and everything from `if (($decision['action'] ?? null) === 'done') {` to the end of the function becomes:

```php
    if (($decision['action'] ?? null) === 'done') {
        $problem = $leg === 'review-pr' ? dispatch_cli_finish_problem($manifestPath, $manifest) : "the workflow returned done at {$leg}";

        return $problem === null ? dispatch_cli_done($manifestPath, $manifest) : dispatch_cli_halt($manifestPath, $manifest, $leg, $problem);
    }
    $reason = trim((string) ($decision['reason'] ?? ''));
    $named = $decision['leg'] ?? null;
    $recorded = ($manifest['cursor']['status'] ?? null) === 'halted';

    return dispatch_cli_halt(
        $manifestPath,
        $manifest,
        in_array($named, pipeline_legs(), true) && ! $recorded ? $named : $leg,
        $reason === '' ? "the workflow returned no decision: {$decisionJson}" : $reason,
    );
}
```

Directly before `/** An `autoflow` design step's `size`: the committed spec's, as `launch` reads it. */`:

```php
/** Why a `done` return does not hold: the last step must be `review-pr`'s resolve step, and its return must pass the check. */
function dispatch_cli_finish_problem(string $manifestPath, array $manifest): ?string
{
    $before = manifest_read(dispatch_cli_files($manifestPath)['before']);
    $snapshot = dispatch_cli_snapshot_step($before);
    if ($snapshot !== 'review-pr:resolve') {
        return $snapshot === null
            ? 'the workflow returned done, but there is no snapshot at ' . dispatch_cli_files($manifestPath)['before']
            : 'the workflow returned done, but the last snapshot is of the ' . str_replace(':', ' ', $snapshot) . ' step';
    }

    return dispatch_cli_return_problem($manifestPath, $before, $manifest, ['status' => 'continued'])['reason'] ?? null;
}

```

- [ ] **Step 4: Run the pipeline suite**

Expected: 295 passed (293, minus the removed `done` row, plus 3).

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/dispatch_cli.php skills/pipeline/checks/tests/DispatchCliTest.php
git commit -m "feat(pipeline): finish checks review-pr's resolve step before done; a recorded halt keeps its leg (#70)"
```

---

### Task 5: The script tells `brief` what returned; the replay smoke pass

**Files:**
- Modify: `skills/pipeline/workflow/pipeline-autoflow.js` (`briefCommand()`, the `let` block, the step loop)
- Modify: `skills/pipeline/checks/tests/autoflow_replay.mjs` (whole file)
- Test: `skills/pipeline/checks/tests/AutoflowScriptTest.php`

**Interfaces:**
- Consumes: `brief`'s flags (Task 3), and `finish` (Task 4).
- Produces:
  - the replay harness's input `{script, args, returns, steps?}` and output `{labels, prompts, result}`;
  - `autoflow_replay(array $args, array $returns, bool $steps = false): array`;
  - `autoflow_briefs(array $prompts): array`;
  - `autoflow_writes(string $status, array $changes = []): array`.

- [ ] **Step 1: Write the failing tests**

Replace `tests/autoflow_replay.mjs` with:

```js
// Replays ../../workflow/pipeline-autoflow.js outside the Workflow runtime: stdin is
// {script, args, returns, steps?}; agent() is faked, each call taking the next return scripted for its label
// and refusing a status its schema does not allow, as the runtime's StructuredOutput would. A scripted
// null is an agent that returns nothing. With `steps`, each call is a stub step against the real checks:
// it runs the brief command from its prompt and returns a halt it prints; otherwise it merges the
// return's `write` into the manifest (`cursor` one level down), writes its `diff` to the run's diff file,
// and returns the rest. Prints {labels, prompts, result}: the agent labels and prompts in call order and
// what the script returned.
import { execSync } from 'node:child_process'
import { readFileSync, writeFileSync } from 'node:fs'

const input = JSON.parse(readFileSync(0, 'utf8'))
const body = readFileSync(input.script, 'utf8').replace(/^export const meta\b/m, 'const meta')
const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor
const labels = []
const prompts = []

function brief(prompt) {
  const answer = execSync(prompt.match(/`(php \S+\/dispatch_cli\.php brief [^`]+)`/)[1], { encoding: 'utf8' })
  const halt = answer.startsWith('{') ? JSON.parse(answer) : null
  return halt?.action === 'halt' ? { status: 'halted', reason: halt.reason } : null
}

function play({ write = {}, diff, ...returns }) {
  const path = input.args.manifest
  const manifest = JSON.parse(readFileSync(path, 'utf8'))
  writeFileSync(path, JSON.stringify({ ...manifest, ...write, cursor: { ...manifest.cursor, ...write.cursor } }, null, 4) + '\n')
  if (diff !== undefined) writeFileSync(path.replace(/\.json$/, '.diff'), diff)
  return returns
}

async function agent(prompt, opts) {
  labels.push(opts.label)
  prompts.push(prompt)
  const statuses = opts.schema.properties.status.enum
  if (statuses.length === 0) throw new Error('the schema is unsatisfiable')
  const returns = input.returns[opts.label]?.shift()
  if (returns === undefined) throw new Error(`no return scripted for ${opts.label}`)
  const halted = input.steps ? brief(prompt) : null
  if (halted) return halted
  if (returns === null) return null
  if (!statuses.includes(returns.status)) throw new Error(`${opts.label} may not return ${returns.status}`)
  return input.steps ? play(returns) : returns
}

const result = await new AsyncFunction('args', 'agent', 'log', body)(input.args, agent, () => {})
process.stdout.write(JSON.stringify({ labels, prompts, result }) + '\n')
```

In `AutoflowScriptTest.php`:
- The helper's docblock and signature become:

```php
/** The autoflow script run on `$args` with agent() faked (`autoflow_replay.mjs`): `{labels, prompts, result}`; with `$steps` each agent is a stub step against the real `brief`. */
function autoflow_replay(array $args, array $returns, bool $steps = false): array
```

- In its body, `'returns' => (object) $returns]` becomes `'returns' => (object) $returns, 'steps' => $steps]`.
- In `it('halts a start step its leg does not have, before any agent', …)` the expectation becomes `->toBe(['labels' => [], 'prompts' => [], 'result' => ['action' => 'halt', 'leg' => 'handoff', 'reason' => 'handoff has no resolve step']]);`.

Then append:

```php

/** The brief command in each prompt, after the manifest path. */
function autoflow_briefs(array $prompts): array
{
    return array_map(fn (string $prompt) => preg_match('/dispatch_cli\.php brief \S+ ([^`]+)`/', $prompt, $match) ? $match[1] : null, $prompts);
}

it('tells each brief what the step before it returned, and a retry the same', function () {
    $design = [...AUTOFLOW_C, 'size' => 'Architectural'];
    $replay = autoflow_replay(autoflow_start('design'), [
        'design:run' => [$design, $design],
        'review-plan:review' => [null, AUTOFLOW_C, AUTOFLOW_C],
        'review-plan:resolve' => [AUTOFLOW_LB, AUTOFLOW_C],
        'handoff:run' => [AUTOFLOW_C],
        'implement:run' => [[...AUTOFLOW_C, 'ui' => true]],
        'verify-ui:run' => [['status' => 'halted', 'reason' => 'stub stop']],
    ]);

    expect(autoflow_briefs($replay['prompts']))->toBe([
        'design run',
        'review-plan review --after design:run --status continued --size Architectural',
        'review-plan review --after design:run --status continued --size Architectural',
        'review-plan resolve --after review-plan:review --status continued',
        'design run --after review-plan:resolve --status looped-back',
        'review-plan review --after design:run --status continued --size Architectural',
        'review-plan resolve --after review-plan:review --status continued',
        'handoff run --after review-plan:resolve --status continued',
        'implement run --after handoff:run --status continued',
        'verify-ui run --after implement:run --status continued --ui true',
    ]);
    expect($replay['result'])->toBe(['action' => 'halt', 'leg' => 'verify-ui', 'reason' => 'stub stop']);
});

/** A stub step's manifest write: its status, and whatever else it changes. */
function autoflow_writes(string $status, array $changes = []): array
{
    return ['status' => $status, 'write' => ['cursor' => ['status' => $status], ...$changes]];
}

it('halts at the next brief when a stub resolve step leaves its entry open (the replay smoke pass)', function () {
    $start = autoflow_start('review-plan');
    $open = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-25T10:00:00Z', 'review' => 'r', 'annotations' => []];

    $replay = autoflow_replay($start, [
        'review-plan:review' => [autoflow_writes('continued', ['gate_ledger' => [$open]])],
        'review-plan:resolve' => [autoflow_writes('continued')],
        'handoff:run' => [autoflow_writes('continued')],
    ], steps: true);

    $reason = "the resolve step must set the open plan-approval entry's outcome to continued";
    expect($replay['labels'])->toBe(['review-plan:review', 'review-plan:resolve', 'handoff:run']);
    expect($replay['result'])->toBe(['action' => 'halt', 'leg' => 'handoff', 'reason' => $reason]);

    dispatch_cli(['finish', $start['manifest'], json_encode($replay['result'])]);
    expect(manifest_read($start['manifest'])['cursor'])->toBe(['leg' => 'review-plan', 'status' => 'halted', 'reason' => $reason]);
});

it('walks stub steps that write what their briefs ask to done, through a retried review (the replay smoke pass)', function () {
    $start = autoflow_start('design', spec: "# x — design\n\n**Design size:** Architectural\n");
    $plan = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-25T10:00:00Z', 'review' => 'r', 'annotations' => []];
    $pr = [...$plan, 'gate' => 'pr-review', 'leg' => 'review-pr', 'at' => '2026-09-25T12:00:00Z'];
    $done = fn (array $entry) => [...$entry, 'actions' => [], 'outcome' => 'continued'];

    $replay = autoflow_replay($start, [
        'design:run' => [[...autoflow_writes('continued', ['last_sha' => 'aaa1111']), 'size' => 'Architectural']],
        'review-plan:review' => [autoflow_writes('continued', ['gate_ledger' => [$plan]])],
        'review-plan:resolve' => [autoflow_writes('continued', ['gate_ledger' => [$done($plan)]])],
        'handoff:run' => [autoflow_writes('continued', ['artifacts' => ['spec' => 'spec.md', 'plan' => null, 'pr' => 7, 'issue' => null]])],
        'implement:run' => [[...autoflow_writes('continued', ['last_sha' => 'bbb2222']), 'diff' => '', 'ui' => false]],
        'review-pr:review' => [null, autoflow_writes('continued', ['gate_ledger' => [$done($plan), $pr]])],
        'review-pr:resolve' => [autoflow_writes('continued', ['gate_ledger' => [$done($plan), $done($pr)]])],
    ], steps: true);

    expect($replay['labels'])->toBe(['design:run', 'review-plan:review', 'review-plan:resolve', 'handoff:run', 'implement:run', 'review-pr:review', 'review-pr:review', 'review-pr:resolve']);
    expect($replay['result'])->toBe(['action' => 'done']);
    expect(dispatch_cli(['finish', $start['manifest'], '{"action":"done"}'])['json'])->toBe(['action' => 'done']);
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter=AutoflowScriptTest`
Expected: two FAIL. The prompts carry no flags, so the leave-open run walks on into `handoff:run` and further. The clean walk passes before the script change too, because `brief` checks nothing without flags. It pins that a legitimate run is not halted once the flags are there.

- [ ] **Step 3: Implement** (in `pipeline-autoflow.js`)

`briefCommand()` becomes:

```js
// The previous step's return as brief checks it (`--after`); the first step of a run gets none. A retry
// reuses its prompt, and so the flags of the step before it.
function briefCommand(leg, step) {
  const flags = last ? [` --after ${last.leg}:${last.step} --status ${last.status}`, ...Object.keys(COPIED[last.leg] ?? {}).map(key => ` --${key} ${last[key]}`)] : []
  return `php ${args.checks}/dispatch_cli.php brief ${args.manifest} ${leg} ${step}${flags.join('')}`
}
```

After `let from = args.startStep` add `let last`. In the step loop, directly after `result = await runStep(leg, step)`, add:

```js
    last = { ...result, leg, step }
```

`last` is read only inside `briefCommand()`, which runs only after the guards, so its `let` below the function declarations is fine. `reason` stays out of the flags.

- [ ] **Step 4: Run the pipeline suite**

Expected: 298 passed.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/workflow/pipeline-autoflow.js skills/pipeline/checks/tests/autoflow_replay.mjs skills/pipeline/checks/tests/AutoflowScriptTest.php
git commit -m "feat(pipeline-autoflow): each brief is told what the step before it returned; replay smoke pass (#70)"
```

---

### Task 6: Docs, the Workflow smoke pass, all suites

**Files:**
- Modify: `skills/pipeline/references/engine.md` (§`autoflow`: the diagram, *A step*, *`finish`*, *No check on what a step reports*; §Failure policy: two bullets)
- Modify: `skills/pipeline/references/manifest.md` (§What a leg writes)
- Modify: `skills/orchestrate/references/commands.md` (§Finish)
- Modify: `skills/pipeline/checks/run_audit.php` (docblock)

- [ ] **Step 1: `engine.md` §`autoflow`**

The diagram's step-agent line:

```
step agent         dispatch_cli.php brief <manifest> <leg> <step> [--after …] → the leg's work → the manifest → {status, reason}
```

The *A step* bullet's first sentence, up to and including *"`review` with one already open)."*, becomes:

```markdown
- **A step** first runs `dispatch_cli.php brief <manifest> <leg> <step>`, followed on every step but
  the run's first by what the step before it returned: `--after <leg>:<step> --status <status>`, plus
  `--ui` after `implement` and `--size` after `design` (§The check at the next boundary). It checks that
  return, then writes `cursor: {leg, status: pending}` — so after a `TaskStop` or a dead session the
  cursor still names the step that was running — and the snapshot `<manifest stem>.before.json`, and
  prints the brief. It prints a halt instead when the return does not hold, or when the ledger does not
  support the step (`resolve` with no open review, `review` with one already open).
```

The rest of that bullet (from *"The step writes its results"*) stays.

The *`finish`* bullet's first sentence, up to *"keeping the cursor's leg when the return names none of the pipeline's."*, becomes:

```markdown
- **`finish`** records the return: `done` sets `cursor.status: done`, but only with the cursor on
  `review-pr` — anywhere else it records the halt "the workflow returned done at <leg>" — and only when
  the last snapshot is `review-pr`'s resolve step's and that step's return holds (§The check at the next
  boundary); otherwise it records that halt. A halt sets `cursor: {leg, status: halted, reason}`,
  keeping the cursor's leg when the cursor already says `halted` (`brief` or the step wrote it, on the
  step that failed) or when the return names none of the pipeline's legs.
```

The rest of that bullet (from *"When the workflow itself errored"*) stays.

Replace everything from `**No check on what a step reports.**` up to, but not including, `**Where a step works.**` with:

````markdown
**The check at the next boundary.** The script routes on the `status` a step returns; the step's
return is checked at the next command, by a separate process and no extra agent. `brief`, told by
`--after` which step returned, compares the manifest with that step's snapshot, as `returned` does in
`auto`: `pipeline_reported_problem()` (`../checks/dispatch.php`) runs `pipeline_return_problem()` and
then compares what the script was told with what the manifest and the tree say — the status with
`cursor.status`, `ui` with `pipeline_triggers()` over the implement step's own `<manifest stem>.diff`
(one older than its snapshot halts), `size` with the spec's header. A halt writes
`cursor: {leg: <the step that failed>, status: halted, reason}`, so a resume re-runs that step. Without
`--after` (the run's first step) nothing is checked. A snapshot of the very step being briefed is the
script's Opus retry of a review step that returned nothing: an unchanged manifest is briefed again, a
changed one halts. `finish` runs the same check for `review-pr`'s resolve step before it records `done`.
What a step claims about work outside the manifest is caught by the next gate (`review-plan` reads the
spec and plan, `review-pr` the code). After every run the invoking session reports two facts with the
result:

```bash
php "$CHECKS/run_cost_cli.php" <the run's transcript dir>
git -C <worktree> diff origin/<base>...HEAD > "<manifest stem>.diff"
php "$CHECKS/run_audit.php" <manifest> "<manifest stem>.diff" <the run's transcript dir>
```

The transcript dir is `~/.claude/projects/<project>/<session>/subagents/workflows/wf_<id>/`, named in
the Workflow result. `run_cost_cli.php` prints the weighted cost per step and the largest step peak.
`run_audit.php` prints whether `ui` over the final diff agrees with a `verify-ui` entry, and whether
each gate's newest ledger entries agree with what the steps reported. It stays as the after-run report:
with the boundary check in place a `MISMATCH` means the check has a hole, never a halt.

````

- [ ] **Step 2: `engine.md` §Failure policy**

In *A halted manifest is the one the check rejected*, `before the next `next`; otherwise` becomes `before the next `next` or `launch`; otherwise`.

*A return the dispatcher cannot account for* becomes:

```markdown
- **A return the dispatcher cannot account for** — a moved cursor, a key only the dispatcher writes,
  a rewritten ledger entry, a status the ledger does not support (`manifest.md` §What a leg writes) →
  **halt**, with `returned`'s reason; in `autoflow`, with the next `brief`'s or `finish`'s, which also
  halt on a status, `ui` or `size` the step returned to the script that its manifest, diff or spec
  does not bear out.
```

- [ ] **Step 3: `manifest.md` §What a leg writes**

The sentence from *"In `autoflow` nothing compares"* to *"(`engine.md` §`autoflow`)."* becomes:

```markdown
In `autoflow` the next `brief` (or, after the last
step, `finish`) makes the same comparison against that step's snapshot, and also halts when the status,
`ui` or `size` the step returned to the script disagrees with the manifest, its diff or the spec
(`engine.md` §`autoflow`, *The check at the next boundary*). `run_audit.php` still reports after the
run whether the ledger agrees with what the steps reported.
```

Keep the paragraph's wrap: the preceding text ends *"…when the status does not agree with the ledger."*, followed on the same line by *"In `autoflow` the next `brief` (or, after the last"*.

- [ ] **Step 4: `orchestrate/references/commands.md` §Finish**

```markdown
The transcript dir is the `wf_<id>` directory the workflow result names, not the task id. `finish`
refuses a `done` whose cursor is not on `review-pr`, or whose last step, `review-pr`'s resolve step,
left a return that does not hold (an open `pr-review` entry, a key only the engine writes): it records
and prints a halt instead.
```

This replaces the two lines that end *"…it records and prints a halt instead."*.

- [ ] **Step 5: `run_audit.php`'s docblock**

```php
 * After a `/pipeline autoflow` run: the two things the workflow takes on report (spec 2026-09-23 §No check
 * on what a step reports), as facts. Each step's return is checked at the next `brief` (engine.md
 * §`autoflow`, The check at the next boundary); a MISMATCH here means that check has a hole, never a halt.
```

This replaces its first two lines, after ` * `.

- [ ] **Step 6: Check that nothing still says `autoflow` trusts a step's return**

```bash
grep -rn "No check on what a step reports\|nothing compares\|trigger for adding a check" skills/pipeline skills/orchestrate
```

Expected: no output. `run_audit.php` still cites the 2026-09-23 spec's section by that name, but split over two lines, so the grep does not match it.

- [ ] **Step 7: The Workflow smoke pass**

If this session has the Workflow tool, run the saved `pipeline-autoflow` on a throwaway manifest. Make it an `autoflow` manifest at `review-plan`, with `args` from `launch`. Give it `args.stub` with a `review-plan:review` stub that appends an open `plan-approval` entry and returns `continued`, a `review-plan:resolve` stub that returns `continued` and leaves the entry open, and a `handoff:run` stub. Expected: `{action: halt, leg: handoff, reason: "the resolve step must set the open plan-approval entry's outcome to continued"}`.

If the session does not have the tool (an `autoflow` implement step cannot start a Workflow), the PR body says that the Workflow smoke pass was not run. It names this scenario, and says that the replay smoke pass in `AutoflowScriptTest` covers it on every suite run.

- [ ] **Step 8: Both suites**

Run both suites from *Global Constraints*.
Expected: pipeline 298 passed, critique 14 passed.

- [ ] **Step 9: Commit**

```bash
git add skills/pipeline/references skills/orchestrate/references/commands.md skills/pipeline/checks/run_audit.php
git commit -m "docs(pipeline): autoflow checks each return at the next boundary (#70)"
```
