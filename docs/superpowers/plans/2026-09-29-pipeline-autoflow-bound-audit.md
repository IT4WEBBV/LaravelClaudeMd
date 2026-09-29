# `autoflow` bound audit and `review-plan` loop target guard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task by task. Steps use checkbox (`- [ ]`) syntax for tracking. In an `autoflow` implement step, execute inline, task by task, with no subagents.

**Goal:** `run_audit.php` prints a `bound:` line that flags a gate the run looped back at more often than `PIPELINE_LOOP_BOUND` allows, and `pipeline-autoflow.js` halts before any agent on `args` whose `tables.loopTarget` has no `review-plan`.

**Architecture:**
- `skills/pipeline/checks/run_audit.php`: two new functions, `run_audit_charged()` (the looping leg a journal step charges) and `run_audit_bound()` (the line); the CLI appends the line after the gate lines.
- `skills/pipeline/workflow/pipeline-autoflow.js`: the existing *not a launch start answer* guard also checks `'review-plan' in loopTarget`.
- Docs: engine.md §`autoflow` (the script bullet, the after-run paragraph) and pipeline `SKILL.md` (*Cost per run*).

**Tech Stack:** PHP 8.4 on the host, Pest 4, Node (the replay harness `autoflow_replay.mjs`), Markdown skill references.

**Spec:** `docs/superpowers/specs/2026-09-29-pipeline-autoflow-bound-audit-design.md`

## Global Constraints

- Suite, from the worktree root on the host: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`. `vendor/` is missing in a fresh worktree: run `composer install --no-interaction --quiet` first. `AutoflowScriptTest` needs `node` on PATH.
- The line, verbatim in shape: `bound: the ledger's loop-backs where the run looped back [<leg> <count>, …], 2 allowed[, not counting the <leg> loop-back the run halted on] — agree|MISMATCH`, printed as the sixth and last line.
- The script's halt reason stays exactly `args are not a launch start answer`.
- `run_audit.php` always exits 0; a `MISMATCH` is a signal, never a halt.
- `dispatch.php`, `dispatch_cli.php`, `complete()` in the script, `run_audit_gates()`, `run_audit_ui()`, `gates.md` and `manifest.md` do not change.
- This repo has no `.changelog/` and no `CHANGELOG.md`: no changelog entry.

## Review Focus

1. **The loop-back a run halted on.** A correct bound halt leaves `PIPELINE_LOOP_BOUND + 1` looped-back entries; the line must say `agree`. Task 1's *does not count the loop-back a run halted on* test pins it.
2. **A resumed run after a bound halt** passes the gate without looping back; the ledger still holds three. Not judged: `[]`, `agree`. Same test.
3. **A plan gap from `implement` or `verify-ui`** charges `review-plan`, counted from `plan-approval` looped-back entries whose `leg` is not `review-plan`. Task 1's *charges a plan gap to review-plan* test pins it.
4. **The existing full-output assertions.** *reports a run whose ledger agrees with every step* compares the whole stdout and gains the line; *counts a review-plan step's plan gap or escalation* compared everything after line 0 and now compares lines 1–4 only. Both edits are in Task 1.
5. **The guard's position.** It must run after `complete()` and the destructuring (it reads `loopTarget`) and before any agent; Task 2's test expects no labels and no prompts.

---

## File Structure

- Modify `skills/pipeline/checks/run_audit.php`, `skills/pipeline/checks/tests/RunAuditTest.php`.
- Modify `skills/pipeline/workflow/pipeline-autoflow.js`, `skills/pipeline/checks/tests/AutoflowScriptTest.php`.
- Modify `skills/pipeline/references/engine.md`, `skills/pipeline/SKILL.md`.

---

### Task 1: the `bound:` line in `run_audit.php`

**Files:**
- Modify: `skills/pipeline/checks/run_audit.php` (docblock lines 3–11; new functions after `run_audit_line()`; the CLI's last lines 97–99)
- Test: `skills/pipeline/checks/tests/RunAuditTest.php`
- Modify: `skills/pipeline/references/engine.md` (§`autoflow`, the paragraph after the three after-run commands), `skills/pipeline/SKILL.md` (*Cost per run*)

**Interfaces:**
- Consumes (existing): `pipeline_run_journal(string $jsonl): list<array{agent: string, label: string, result: mixed}>` (`run_cost.php`), `pipeline_loop_counts(array $ledger): array<string, int>` keyed `review-plan`, `review-pr`, `verify-ui`, and `PIPELINE_LOOP_BOUND` (both `dispatch.php`, already required by `run_audit.php`). Test helpers `audit_run(array $ledger, string $diff, array $reports): array{code: int, stdout: string}` and the `$entry` closure in `RunAuditTest.php`.
- Produces: `run_audit_charged(array $step): ?string` and `run_audit_bound(array $steps, array $ledger): string`.

- [ ] **Step 1: Write the failing tests.** In `RunAuditTest.php`, after `$uiDiff = …;` add:

```php
$round = [['review-pr:review', ['status' => 'continued']], ['review-pr:resolve', ['status' => 'looped-back']], ['implement:run', ['status' => 'continued', 'ui' => false]]];
$passes = [['review-pr:review', ['status' => 'continued']], ['review-pr:resolve', ['status' => 'continued']]];
$prLooped = fn (int $loops) => array_map(fn (int $cycle) => $entry('pr-review', 'review-pr', $cycle, 'looped-back'), range(1, $loops));
$boundLine = fn (array $ledger, array $steps) => explode("\n", audit_run($ledger, '', $steps)['stdout'])[5];
```

In *reports a run whose ledger agrees with every step*, append to the expected lines, after the `plan gaps:` line:

```php
        "bound: the ledger's loop-backs where the run looped back [review-plan 1], 2 allowed — agree",
```

In *counts a review-plan step's plan gap or escalation as a review-plan loop-back, where the ledger has it*, change

```php
        expect(array_slice(explode("\n", audit_run([$recorded], '', $reports)['stdout']), 1))->toBe([
```

to

```php
        expect(array_slice(explode("\n", audit_run([$recorded], '', $reports)['stdout']), 1, 4))->toBe([
```

Before *says so when an input is missing, and exits 0*, add:

```php
it('flags a gate the run looped back at when the ledger then holds more loop-backs than the bound', function () use ($entry, $round, $passes, $prLooped, $boundLine) {
    $run = fn (int $loops) => [...array_merge(...array_fill(0, $loops, $round)), ...$passes];

    expect($boundLine([...$prLooped(3), $entry('pr-review', 'review-pr', 4, 'continued')], $run(3)))
        ->toBe("bound: the ledger's loop-backs where the run looped back [review-pr 3], 2 allowed — MISMATCH");
    expect($boundLine([...$prLooped(2), $entry('pr-review', 'review-pr', 3, 'continued')], $run(2)))
        ->toBe("bound: the ledger's loop-backs where the run looped back [review-pr 2], 2 allowed — agree");
});

it('does not count the loop-back a run halted on, nor judge a gate the run did not loop back at', function () use ($entry, $round, $passes, $prLooped, $boundLine) {
    $halted = [...$round, ...$round, ...array_slice($round, 0, 2)];
    $resumed = [...$prLooped(3), $entry('pr-review', 'review-pr', 4, 'continued')];

    expect($boundLine($prLooped(3), $halted))
        ->toBe("bound: the ledger's loop-backs where the run looped back [review-pr 2], 2 allowed, not counting the review-pr loop-back the run halted on — agree");
    expect($boundLine($resumed, $passes))
        ->toBe("bound: the ledger's loop-backs where the run looped back [], 2 allowed — agree");
});

it('charges a plan gap to review-plan, and does not count the one the run halted on', function () use ($entry, $boundLine) {
    $gap = ['implement:run', ['status' => 'plan-insufficient', 'reason' => 'gap', 'ui' => false]];
    $replan = [['design:run', ['status' => 'continued', 'size' => 'Architectural']], ['review-plan:review', ['status' => 'continued']], ['review-plan:resolve', ['status' => 'continued']], ['handoff:run', ['status' => 'continued']]];
    $ledger = array_map(fn (int $cycle) => $entry('plan-approval', 'implement', $cycle, 'looped-back'), [1, 2, 3]);

    expect($boundLine($ledger, [$gap, ...$replan, $gap, ...$replan, $gap]))
        ->toBe("bound: the ledger's loop-backs where the run looped back [review-plan 2], 2 allowed, not counting the review-plan loop-back the run halted on — agree");
});
```

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter=RunAuditTest`
Expected: FAIL: *reports a run whose ledger agrees* (stdout has five lines, not six) and the three new tests (the output has no sixth line); *flags a UI diff*, *compares a reported plan gap*, *counts a review-plan step's plan gap* and *says so when an input is missing* PASS.

- [ ] **Step 3: Write the functions.** In `run_audit.php`, after `run_audit_line()`, add:

```php
/** The looping leg a journal step's return charges a loop-back to, as the script charges it; null for any other return. */
function run_audit_charged(array $step): ?string
{
    [$leg, $name] = explode(':', $step['label'], 2) + [1 => ''];
    $status = is_array($step['result']) ? ($step['result']['status'] ?? null) : null;

    return match (true) {
        $status === 'plan-insufficient' => 'review-plan',
        $status === 'looped-back' && ($name === 'resolve' || $leg === 'verify-ui') => $leg,
        default => null,
    };
}

/**
 * Whether the script ran with `PIPELINE_LOOP_BOUND` (#86): `tables.bound` and `loops` reach it through the
 * invoking session's copy of `launch`'s answer. At each gate the run routed a loop-back at, the ledger's
 * looped-back count (`pipeline_loop_counts()`, what `launch` counts from) stays within the bound. A
 * loop-back as the journal's last step is the one the script halted on (a routed one starts the next
 * step): the resolve step recorded it, so it is in the ledger, and it is not counted.
 *
 * @param list<array{label: string, result: mixed}> $steps
 */
function run_audit_bound(array $steps, array $ledger): string
{
    $charged = array_map(run_audit_charged(...), $steps);
    $refused = array_slice($charged, -1)[0] ?? null;
    $routed = array_count_values(array_filter($charged));
    if ($refused !== null) {
        $routed[$refused]--;
    }
    $counts = pipeline_loop_counts($ledger);
    $judged = array_keys(array_filter($routed));
    $held = array_combine($judged, array_map(fn (string $leg) => $counts[$leg] - (int) ($leg === $refused), $judged));

    return sprintf(
        "bound: the ledger's loop-backs where the run looped back [%s], %d allowed%s — %s",
        implode(', ', array_map(fn (string $leg, int $count) => "{$leg} {$count}", $judged, $held)),
        PIPELINE_LOOP_BOUND,
        $refused === null ? '' : ", not counting the {$refused} loop-back the run halted on",
        array_filter($held, fn (int $count) => $count > PIPELINE_LOOP_BOUND) === [] ? 'agree' : 'MISMATCH',
    );
}
```

Replace the CLI's last lines

```php
$ledger = pipeline_ledger($manifest);
echo implode("\n", [run_audit_ui(pipeline_triggers($diff), $ledger), ...run_audit_gates(pipeline_run_journal($journal), $ledger)]), "\n";
exit(0);
```

with

```php
$ledger = pipeline_ledger($manifest);
$steps = pipeline_run_journal($journal);
echo implode("\n", [run_audit_ui(pipeline_triggers($diff), $ledger), ...run_audit_gates($steps, $ledger), run_audit_bound($steps, $ledger)]), "\n";
exit(0);
```

In the file docblock, replace

```php
 * After a `/pipeline autoflow` run: the two things the workflow takes on report (spec 2026-09-23 §No check
 * on what a step reports), as facts. Each step's return is checked at the next `brief` (engine.md
 * §`autoflow`, The check at the next boundary); a MISMATCH here means that check has a hole, never a halt.
```

with

```php
 * After a `/pipeline autoflow` run: the two things the workflow takes on report (spec 2026-09-23 §No check
 * on what a step reports), as facts, and the bound it takes on transcription (#86). Each step's return is
 * checked at the next `brief` (engine.md §`autoflow`, The check at the next boundary); a MISMATCH here
 * means a check has a hole or the script ran with another bound, never a halt.
```

- [ ] **Step 4: Run the tests**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter=RunAuditTest`
Expected: PASS, 8 tests.

- [ ] **Step 5: engine.md.** In §`autoflow`, replace

```markdown
minutes and the largest step peak. `run_audit.php` prints whether `ui` over the final diff agrees with
a `verify-ui` entry, and whether each gate's newest ledger entries agree with what the steps reported.
It stays as the after-run report: with the boundary check in place a `MISMATCH` means the check has a
hole, never a halt.
```

with

```markdown
minutes and the largest step peak. `run_audit.php` prints whether `ui` over the final diff agrees with
a `verify-ui` entry, whether each gate's newest ledger entries agree with what the steps reported, and
(`bound:`) whether each gate the run looped back at holds no more `looped-back` ledger entries than
`PIPELINE_LOOP_BOUND`, the loop-back the run halted on not counted: `tables.bound` and `loops` reach the
script through the invoking session's copy of `launch`'s answer, and no boundary check counts
loop-backs. It stays as the after-run report: with the boundary check in place a `MISMATCH` means a
check has a hole or the script ran with another bound, never a halt.
```

- [ ] **Step 6: `SKILL.md`.** In the *Cost per run* bullet, replace

```markdown
  step peak) and `checks/run_audit.php` (whether `ui` and each gate's ledger agree with what the steps
  reported). A `MISMATCH` is a signal, never a halt (`references/engine.md` §`autoflow`).
```

with

```markdown
  step peak) and `checks/run_audit.php` (whether `ui` and each gate's ledger agree with what the steps
  reported, and whether the ledger's loop-backs stay within the bound). A `MISMATCH` is a signal, never
  a halt (`references/engine.md` §`autoflow`).
```

- [ ] **Step 7: Run the suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, 0 failed.

- [ ] **Step 8: Commit**

```bash
git add skills/pipeline/checks/run_audit.php skills/pipeline/checks/tests/RunAuditTest.php skills/pipeline/references/engine.md skills/pipeline/SKILL.md
git commit -m "feat(pipeline): run_audit prints whether each gate the run looped back at stays within the bound (#86)"
```

---

### Task 2: the script refuses `tables.loopTarget` without `review-plan`

**Files:**
- Modify: `skills/pipeline/workflow/pipeline-autoflow.js:109`
- Test: `skills/pipeline/checks/tests/AutoflowScriptTest.php`
- Modify: `skills/pipeline/references/engine.md` (§`autoflow`, *The script* bullet)

**Interfaces:**
- Consumes (existing): `autoflow_start(string $leg, array $ledger = [], ?string $spec = null): array` and `autoflow_replay(array $args, array $returns, bool $steps = false): array` in `AutoflowScriptTest.php`.
- Produces: nothing other tasks use.

- [ ] **Step 1: Write the failing test.** In `AutoflowScriptTest.php`, after *halts before any agent when launch's tables are missing or incomplete* (its `->with([...]);` block), add:

```php
it('halts before any agent when launch\'s tables have no review-plan loop-back, the gate plan gaps are charged to', function () {
    $start = autoflow_start('implement');
    unset($start['tables']['loopTarget']['review-plan']);

    expect(autoflow_replay($start, []))->toBe(['labels' => [], 'prompts' => [], 'result' => ['action' => 'halt', 'leg' => 'implement', 'reason' => 'args are not a launch start answer']]);
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='plan gaps are charged to'`
Expected: FAIL: labels `['implement:run']` and reason `the agent failed: no return scripted for implement:run` (today's script starts the agent).

- [ ] **Step 3: The guard.** In `pipeline-autoflow.js`, replace

```js
if (!legs.includes(args.startLeg)) return halt(args.startLeg ?? 'launch', 'args are not a launch start answer')
```

with

```js
if (!legs.includes(args.startLeg) || !('review-plan' in loopTarget)) return halt(args.startLeg ?? 'launch', 'args are not a launch start answer') // a plan gap is charged to loops['review-plan']
```

- [ ] **Step 4: Run the test**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter=AutoflowScriptTest`
Expected: PASS, every `AutoflowScriptTest` test.

- [ ] **Step 5: engine.md.** In §`autoflow`, *The script* bullet, replace

```markdown
  `review-plan`'s bound. A status it cannot route halts, and so do `args` that are not a `launch` `start` answer; `tables`
```

with

```markdown
  `review-plan`'s bound. A status it cannot route halts, and so do `args` that are not a `launch` `start` answer
  or whose `tables.loopTarget` has no `review-plan`, the gate every `plan-insufficient` is charged to; `tables`
```

- [ ] **Step 6: Run the suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, 0 failed.

- [ ] **Step 7: Commit**

```bash
git add skills/pipeline/workflow/pipeline-autoflow.js skills/pipeline/checks/tests/AutoflowScriptTest.php skills/pipeline/references/engine.md
git commit -m "feat(pipeline): autoflow halts before any agent when tables.loopTarget has no review-plan (#86)"
```
