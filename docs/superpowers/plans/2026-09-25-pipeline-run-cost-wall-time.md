# `run_cost_cli.php` reports wall time per step Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task by task. Steps use checkbox (`- [ ]`) syntax for tracking. In an `autoflow` implement step, execute inline, task by task, with no subagents.

**Goal:** every step line of `run_cost_cli.php` ends in `<wall> min (<waiting> waiting on tools)`, and the `run:` line says `in <span> min`.

**Architecture:**
- `run_cost.php` (pure) gains `pipeline_transcript_time()`: earliest to latest record timestamp, plus the union of every `tool_use` → `tool_result` interval. Three helpers: `pipeline_jsonl()` (shared line decoding, also used by the two existing parsers), `pipeline_record_time()`, `pipeline_union_seconds()`.
- `pipeline_run_cost_lines()` prints the minutes; `pipeline_run_seconds()` gives the run's span over the timed steps.
- `run_cost_cli.php` reads each transcript once and merges cost and time per step.

**Tech Stack:** PHP 8.4 on the host, Pest 4 (`./vendor/bin/pest`), Markdown skill references.

**Spec:** `docs/superpowers/specs/2026-09-25-pipeline-run-cost-wall-time-design.md`

**Verified before writing (2026-09-25):**
- The code and tests below, applied to this branch, pass the whole pipeline suite: 263 tests (261 on main plus the 2 new ones; the CLI test is extended in place).
- Against today's `run_cost.php`, the three new or changed tests fail (`Call to undefined function pipeline_transcript_time()`, and the CLI output without minutes); the cost and journal tests pass.
- Against the real Asimo run `wf_c355120f-4e4` the command prints, e.g., `implement:run: 0.63M over 47 calls, peak 131k, 17.5 min (14.8 waiting on tools)` and `run: 3.82M weighted over 10 steps in 63.8 min; largest step peak 152k (review-plan:review)`, matching an independent Python count of the same transcripts.

## Global Constraints

- Step line: `sprintf('%s: %.2fM over %d calls, peak %dk, %.1f min (%.1f waiting on tools)', …)`, minutes = seconds / 60.
- Run line: `sprintf('run: %.2fM weighted over %d steps in %.1f min; largest step peak %dk (%s)', …)`. The no-steps line `run: not measured (no step transcripts)` is unchanged.
- `pipeline_transcript_time()` returns exactly `['start' => ?float, 'end' => ?float, 'wall' => float, 'waiting' => float]`, seconds; `start`/`end` are Unix times. No timestamped record → `['start' => null, 'end' => null, 'wall' => 0.0, 'waiting' => 0.0]`.
- Waiting is the **union** of the intervals, never their sum; the union never exceeds wall.
- The run's time is the span (earliest `start` to latest `end` over timed steps), not the sum of walls; 0.0 when no step is timed.
- `run_cost_cli.php` always exits 0.
- `pipeline_transcript_usage()`, `pipeline_transcript_cost()` and `pipeline_run_journal()` keep their behaviour; `run_audit.php` is not touched.
- Suite, from the worktree root on the host: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests` (run `composer install --no-interaction --quiet` first if `vendor/` is missing).
- This repo has no `.changelog/` and no `CHANGELOG.md`, so there is no changelog entry.
- The 2026-09-23 spec and plan are records and are not edited.

## Review Focus

1. **The first record of every real transcript is a `user` record whose `content` is a string** (the brief), not a block list. It must count for wall time and not break the block loop. Pinned in Task 1 by the `"the brief"` record.
2. **Parallel tool calls** (one assistant turn, several `tool_use` records, results in any order) must count their overlap once, or `review-plan:review` reads 4.0 min waiting instead of 1.3. Pinned in Task 1 by `t1`/`t2`: a sum would give 269.5 s, the union 210.0 s.
3. **Milliseconds** in `2026-09-25T09:59:00.250Z` must survive parsing (`strtotime` drops them). Pinned in Task 1 by the `509.75` wall.
4. **A result timestamped before its call** (clock skew) must add 0, not subtract. Pinned in Task 1 by `t5`.
5. **A step with no timed records** (a missing agent file, or an old fixture) must print `0.0 min (0.0 waiting on tools)` and must not drag the run's span to the Unix epoch. Pinned in Task 2 by the `handoff:run` case, whose run span stays 6.5 min.

---

## File Structure

- Modify `skills/pipeline/checks/run_cost.php`: add `pipeline_jsonl()`, `pipeline_record_time()`, `pipeline_union_seconds()`, `pipeline_transcript_time()`, `pipeline_run_seconds()`; the two existing parsers use `pipeline_jsonl()`; `pipeline_run_cost_lines()` prints minutes.
- Modify `skills/pipeline/checks/run_cost_cli.php`: read each transcript once, merge cost and time; docblock.
- Modify `skills/pipeline/checks/tests/RunCostTest.php`: timestamped fixtures, one new transcript-time test, the CLI test extended, one new CLI test.
- Modify `skills/pipeline/references/engine.md` (§`autoflow`, the sentence after the three commands) and `skills/pipeline/SKILL.md` (*Cost per run*).

---

### Task 1: `pipeline_transcript_time()` and its helpers

**Files:**
- Modify: `skills/pipeline/checks/run_cost.php`
- Test: `skills/pipeline/checks/tests/RunCostTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `pipeline_jsonl(string $jsonl): array` (list of decoded JSON objects); `pipeline_record_time(array $entry): ?float`; `pipeline_union_seconds(array $intervals): float` (list of `[float $start, float $end]`); `pipeline_transcript_time(string $jsonl): array{start: ?float, end: ?float, wall: float, waiting: float}`. Test helpers: `cost_call(…, ?array $split = null, ?string $at = null)`, `cost_tool_use(string $id, string $at)`, `cost_tool_result(string $id, string $at)`, with `$at` as `HH:MM:SS.mmm` on 2026-09-25 UTC.

- [ ] **Step 1: Timestamped fixtures.** In `RunCostTest.php`, replace `cost_call()` and add the two tool helpers right after it:

```php
function cost_call(string $id, int $input, int $write, int $read, int $output = 50, ?array $split = null, ?string $at = null): string
{
    $usage = ['input_tokens' => $input, 'cache_creation_input_tokens' => $write, 'cache_read_input_tokens' => $read, 'output_tokens' => $output];
    if ($split !== null) {
        $usage['cache_creation'] = $split;
    }
    $record = ['type' => 'assistant', 'message' => ['id' => $id, 'role' => 'assistant', 'usage' => $usage]];

    return json_encode($at === null ? $record : [...$record, 'timestamp' => "2026-09-25T{$at}Z"]);
}

/** An assistant record calling tool $id at $at (`HH:MM:SS.mmm`, 2026-09-25 UTC). */
function cost_tool_use(string $id, string $at): string
{
    return json_encode(['type' => 'assistant', 'timestamp' => "2026-09-25T{$at}Z", 'message' => ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => $id, 'name' => 'Bash', 'input' => []]]]]);
}

/** A user record answering tool $id at $at. */
function cost_tool_result(string $id, string $at): string
{
    return json_encode(['type' => 'user', 'timestamp' => "2026-09-25T{$at}Z", 'message' => ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => $id, 'content' => 'ok']]]]);
}
```

- [ ] **Step 2: Write the failing test.** Insert before `it('reads a run\'s steps from its journal, …`:

```php
it('times a transcript from its earliest to its latest record, counting overlapping tool waits once', function () {
    $time = pipeline_transcript_time(implode("\n", [
        cost_call('m1', 1, 0, 0, 50, null, '10:00:00.000'),
        '{"type":"user","timestamp":"2026-09-25T10:00:10.000Z","message":{"role":"user","content":"the brief"}}',
        cost_tool_use('t1', '10:01:00.000'),
        cost_tool_use('t2', '10:01:00.500'),
        cost_tool_result('t2', '10:02:00.000'),
        cost_tool_result('t1', '10:03:00.000'),
        cost_tool_use('t3', '10:05:00.000'),
        cost_tool_result('t3', '10:06:30.000'),
        cost_tool_use('t4', '10:07:00.000'),
        cost_tool_result('t9', '10:07:30.000'),
        cost_tool_use('t5', '10:04:00.000'),
        cost_tool_result('t5', '10:03:30.000'),
        cost_call('m2', 1, 0, 0),
        '{"type":"user","timestamp":"not a time","message":{"role":"user","content":"hi"}}',
        'not json',
        '',
        cost_call('m0', 1, 0, 0, 50, null, '09:59:00.250'),
    ]));

    // t1 and t2 overlap: 10:01:00 to 10:03:00 is 120 s; t3 adds 90 s; t4 (no result), t9 (no call) and t5 (result before call) add nothing
    expect($time['start'])->toEqualWithDelta((float) strtotime('2026-09-25T09:59:00Z') + 0.25, 0.001);
    expect($time['end'])->toEqualWithDelta((float) strtotime('2026-09-25T10:07:30Z'), 0.001);
    expect($time['wall'])->toEqualWithDelta(509.75, 0.001);
    expect($time['waiting'])->toEqualWithDelta(210.0, 0.001);
    expect(pipeline_transcript_time("not json\n" . cost_call('m1', 1, 0, 0)))->toBe(['start' => null, 'end' => null, 'wall' => 0.0, 'waiting' => 0.0]);
    expect(pipeline_transcript_time(''))->toBe(['start' => null, 'end' => null, 'wall' => 0.0, 'waiting' => 0.0]);
});
```

The last record in line order is the earliest in time, so `start` must be a minimum, not the first line. It can fail on the defect three ways: the function is missing, a summed waiting gives 269.5, and a millisecond-dropping parser gives 510.0.

- [ ] **Step 3: Run it to see it fail.**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='times a transcript'`
Expected: FAIL, `Call to undefined function pipeline_transcript_time()`.

- [ ] **Step 4: Shared line decoding.** In `run_cost.php`, add above `pipeline_transcript_usage()`:

```php
/** @return list<array> the lines that decode to a JSON object */
function pipeline_jsonl(string $jsonl): array
{
    return array_values(array_filter(array_map(fn (string $line) => json_decode($line, true), explode("\n", $jsonl)), 'is_array'));
}
```

In `pipeline_transcript_usage()` replace the loop head

```php
    foreach (explode("\n", $jsonl) as $line) {
        $entry = json_decode($line, true);
        $message = is_array($entry) ? ($entry['message'] ?? null) : null;
```

with

```php
    foreach (pipeline_jsonl($jsonl) as $entry) {
        $message = $entry['message'] ?? null;
```

and in `pipeline_run_journal()` replace

```php
    foreach (explode("\n", $jsonl) as $line) {
        $entry = json_decode($line, true);
        $agent = is_array($entry) ? ($entry['agentId'] ?? null) : null;
```

with

```php
    foreach (pipeline_jsonl($jsonl) as $entry) {
        $agent = $entry['agentId'] ?? null;
```

- [ ] **Step 5: The time functions.** Extend the file docblock's last sentence with: ` And how long it took (spec 2026-09-25): wall time per step, the part of it spent waiting on tools, the run's span.` (the docblock then reads "… cache reads. And how long it took …"). Add after `pipeline_run_journal()`:

```php
/** A record's `timestamp` as Unix seconds with milliseconds; null when it has none that parses. */
function pipeline_record_time(array $entry): ?float
{
    $timestamp = $entry['timestamp'] ?? null;
    if (! is_string($timestamp)) {
        return null;
    }
    try {
        return (float) (new DateTimeImmutable($timestamp))->format('U.u');
    } catch (Exception) {
        return null;
    }
}

/**
 * The length of the intervals' union, so overlapping (parallel) tool calls count once. Sorted by start,
 * each interval adds only the part past the furthest end so far; one that ends before it starts adds 0.
 *
 * @param list<array{float, float}> $intervals
 */
function pipeline_union_seconds(array $intervals): float
{
    usort($intervals, fn (array $a, array $b) => $a[0] <=> $b[0]);
    $total = 0.0;
    $reach = -INF;
    foreach ($intervals as [$start, $end]) {
        $total += max(0.0, $end - max($start, $reach));
        $reach = max($reach, $end);
    }

    return $total;
}

/**
 * How long one step agent ran: its earliest to latest record, and the union of each `tool_use` to its
 * `tool_result`. A call without result, or a result without call, adds nothing.
 *
 * @return array{start: ?float, end: ?float, wall: float, waiting: float}
 */
function pipeline_transcript_time(string $jsonl): array
{
    $times = [];
    $calls = [];
    $intervals = [];
    foreach (pipeline_jsonl($jsonl) as $entry) {
        $time = pipeline_record_time($entry);
        if ($time === null) {
            continue;
        }
        $times[] = $time;
        $content = $entry['message']['content'] ?? null;
        foreach (is_array($content) ? $content : [] as $block) {
            $type = $block['type'] ?? null;
            if ($type === 'tool_use') {
                $calls[(string) ($block['id'] ?? '')] = $time;
            }
            $called = $type === 'tool_result' ? ($calls[(string) ($block['tool_use_id'] ?? '')] ?? null) : null;
            if ($called !== null) {
                $intervals[] = [$called, $time];
            }
        }
    }

    return $times === []
        ? ['start' => null, 'end' => null, 'wall' => 0.0, 'waiting' => 0.0]
        : ['start' => min($times), 'end' => max($times), 'wall' => max($times) - min($times), 'waiting' => pipeline_union_seconds($intervals)];
}
```

- [ ] **Step 6: Run the file's tests.**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter=RunCostTest`
Expected: PASS, 4 tests (the weighing and journal tests unchanged, which pins `pipeline_jsonl()`).

- [ ] **Step 7: Commit.**

```bash
git add skills/pipeline/checks/run_cost.php skills/pipeline/checks/tests/RunCostTest.php
git commit -m "pipeline: run_cost.php times a step transcript, tool waits counted once (#78)"
```

### Task 2: minutes in the output, the run's span

**Files:**
- Modify: `skills/pipeline/checks/run_cost.php` (`pipeline_run_cost_lines()`, new `pipeline_run_seconds()`)
- Modify: `skills/pipeline/checks/run_cost_cli.php`
- Test: `skills/pipeline/checks/tests/RunCostTest.php`

**Interfaces:**
- Consumes: `pipeline_transcript_time()` and the fixture helpers from Task 1; `cost_run()` and `checks_cli()` already in the test file.
- Produces: `pipeline_run_seconds(array $steps): float`; `pipeline_run_cost_lines(array $steps): list<string>` where each step is `array{label: string, calls: int, cost: float, peak: int, start: ?float, end: ?float, wall: float, waiting: float}`.

- [ ] **Step 1: Extend the CLI test.** Replace the whole `it('prints the cost per step and the largest step peak, always exiting 0', …)` test with:

```php
it('prints the cost and minutes per step, the run\'s span and the largest step peak, always exiting 0', function () {
    $dir = cost_run([
        'a1' => ['review-plan:review', implode("\n", [
            cost_call('m1', 3, 20000, 0, 50, null, '10:00:00.000'),
            cost_tool_use('t1', '10:00:30.000'),
            cost_tool_result('t1', '10:02:00.000'),
            cost_call('m2', 1, 1000, 140000, 50, ['ephemeral_5m_input_tokens' => 0, 'ephemeral_1h_input_tokens' => 1000], '10:06:00.000'),
        ]), ['status' => 'continued']],
        'a2' => ['implement:run', implode("\n", [
            cost_call('m3', 0, 0, 300000, 2000, null, '10:07:00.000'),
            cost_tool_use('t2', '10:08:00.000'),
            cost_tool_result('t2', '10:20:00.000'),
        ]), ['status' => 'continued', 'ui' => false]],
    ]);

    // the run spans 10:00 to 10:20, a minute longer than its steps' 6.0 + 13.0: the gap between them counts
    expect(checks_cli('run_cost_cli.php', [$dir]))->toBe(['code' => 0, 'stdout' => implode("\n", [
        'review-plan:review: 0.04M over 2 calls, peak 141k, 6.0 min (1.5 waiting on tools)',
        'implement:run: 0.04M over 1 calls, peak 300k, 13.0 min (12.0 waiting on tools)',
        'run: 0.08M weighted over 2 steps in 20.0 min; largest step peak 300k (implement:run)',
    ])]);
    expect(checks_cli('run_cost_cli.php', ['/nonexistent']))->toBe(['code' => 0, 'stdout' => 'run: not measured (no step transcripts)']);
    expect(checks_cli('run_cost_cli.php', [])['code'])->toBe(0);
});
```

The tool records carry no `usage`, so the call counts and costs are the ones the test had before.

- [ ] **Step 2: Add the untimed-step test** at the end of the file:

```php
it('prints a step without timestamps as 0.0 min and spans the run over the timed steps only', function () {
    $dir = cost_run([
        'a1' => ['design:run', implode("\n", [cost_call('m1', 3, 20000, 0, 50, null, '10:00:00.000'), cost_call('m2', 3, 20000, 0, 50, null, '10:06:30.000')]), ['status' => 'continued']],
        'a2' => ['handoff:run', cost_call('m3', 0, 0, 300000, 2000), ['status' => 'continued']],
    ]);

    expect(checks_cli('run_cost_cli.php', [$dir])['stdout'])->toBe(implode("\n", [
        'design:run: 0.05M over 2 calls, peak 20k, 6.5 min (0.0 waiting on tools)',
        'handoff:run: 0.04M over 1 calls, peak 300k, 0.0 min (0.0 waiting on tools)',
        'run: 0.09M weighted over 2 steps in 6.5 min; largest step peak 300k (handoff:run)',
    ]));
});
```

- [ ] **Step 3: Run them to see them fail.**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter=RunCostTest`
Expected: the two CLI tests FAIL (output has no minutes); the other three PASS.

- [ ] **Step 4: The run's span and the new line format.** In `run_cost.php`, replace the `@param` docblock and body of `pipeline_run_cost_lines()`, adding `pipeline_run_seconds()` just above it:

```php
/** The run's span, earliest step start to latest step end, over the steps that have one. */
function pipeline_run_seconds(array $steps): float
{
    $timed = array_filter($steps, fn (array $step) => $step['start'] !== null);

    return $timed === [] ? 0.0 : max(array_column($timed, 'end')) - min(array_column($timed, 'start'));
}

/** @param list<array{label: string, calls: int, cost: float, peak: int, start: ?float, end: ?float, wall: float, waiting: float}> $steps */
function pipeline_run_cost_lines(array $steps): array
{
    if ($steps === []) {
        return ['run: not measured (no step transcripts)'];
    }
    $largest = array_reduce($steps, fn (?array $carry, array $step) => $carry === null || $step['peak'] > $carry['peak'] ? $step : $carry);

    return [
        ...array_map(fn (array $step) => sprintf('%s: %.2fM over %d calls, peak %dk, %.1f min (%.1f waiting on tools)', $step['label'], $step['cost'] / 1e6, $step['calls'], intdiv($step['peak'], 1000), $step['wall'] / 60, $step['waiting'] / 60), $steps),
        sprintf('run: %.2fM weighted over %d steps in %.1f min; largest step peak %dk (%s)', array_sum(array_column($steps, 'cost')) / 1e6, count($steps), pipeline_run_seconds($steps) / 60, intdiv($largest['peak'], 1000), $largest['label']),
    ];
}
```

- [ ] **Step 5: The command reads each transcript once.** In `run_cost_cli.php`, replace the docblock's first line with

```php
 * After a `/pipeline autoflow` run: per step its weighted cost, peak context, wall time and the part of
 * that spent waiting on tools; for the run the total cost, its span in minutes and the largest step peak.
```

and the `$steps = array_map(…);` statement with

```php
$steps = array_map(function (array $step) use ($dir, $read) {
    $transcript = $read("{$dir}/agent-{$step['agent']}.jsonl");

    return ['label' => $step['label'], ...pipeline_transcript_cost($transcript), ...pipeline_transcript_time($transcript)];
}, pipeline_run_journal($read("{$dir}/journal.jsonl")));
```

- [ ] **Step 6: Run the whole pipeline suite.**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, 263 tests.

- [ ] **Step 7: Commit.**

```bash
git add skills/pipeline/checks/run_cost.php skills/pipeline/checks/run_cost_cli.php skills/pipeline/checks/tests/RunCostTest.php
git commit -m "pipeline: run_cost_cli.php prints wall time per step and the run's span (#78)"
```

### Task 3: docs, and one real run

**Files:**
- Modify: `skills/pipeline/references/engine.md` (§`autoflow — a program that calls agents`, the paragraph after the three `run_cost_cli.php` / `run_audit.php` commands)
- Modify: `skills/pipeline/SKILL.md` (*Cost per run* bullet)

**Interfaces:**
- Consumes: the output format from Task 2.
- Produces: nothing code depends on.

- [ ] **Step 1: engine.md.** Replace the sentence

```
`run_cost_cli.php` prints the weighted cost per step and the largest step peak.
```

with

```
`run_cost_cli.php` prints per step the weighted cost, the peak context, the wall time and the part of
it spent waiting on tools, and on its `run:` line the total, the run's span in minutes and the largest
step peak.
```

(Re-wrap the paragraph at the file's ~105-character width.)

- [ ] **Step 2: SKILL.md.** In the *Cost per run* bullet replace `(weighted cost per step, the largest step peak)` with `(weighted cost and wall time per step, the run's span, the largest step peak)`, re-wrapping the bullet.

- [ ] **Step 3: Smoke on a real run.** Pick the newest `wf_*` directory with more than one `agent-*.jsonl`:

```bash
for d in $(ls -dt ~/.claude/projects/*/*/subagents/workflows/wf_* | head -40); do echo "$(ls "$d"/agent-*.jsonl 2>/dev/null | wc -l) $d"; done | sort -rn | head -1
php skills/pipeline/checks/run_cost_cli.php <that dir>
```

Expected: exit 0; every step line ends in `<a> min (<b> waiting on tools)` with `b ≤ a`; the `run:` minutes are at least the largest step's. Put the output in the PR body as the measured record.

- [ ] **Step 4: Suite once more.**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, 263 tests.

- [ ] **Step 5: Commit.**

```bash
git add skills/pipeline/references/engine.md skills/pipeline/SKILL.md
git commit -m "pipeline docs: run_cost_cli.php reports wall time per step and per run (#78)"
```
