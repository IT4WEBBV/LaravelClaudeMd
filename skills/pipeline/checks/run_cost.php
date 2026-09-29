<?php

/**
 * What one `/pipeline autoflow` run cost (spec 2026-09-23 §Measurement), weighted as the 2026-09-22 token
 * audit weighs usage (`usage.py`), each call weighed by its model's factor per token type. Assistant
 * messages are deduplicated by `message.id`, the last occurrence winning; context per call = input +
 * cache writes + cache reads. And how long it took (spec 2026-09-25): wall time per step, the part of it
 * spent waiting on tools, the run's span.
 */

const PIPELINE_COST_WEIGHTS = ['input' => 1.0, 'write5m' => 1.25, 'write1h' => 2.0, 'read' => 0.1, 'output' => 5.0];

/**
 * Each model family's rate per token type relative to Opus 5.5's, from the `claude-api` skill's price
 * table (cached 2026-09-25): Opus $4 / $20, read $0.20; Fable 5.1 $10 / $50, read $0.25; Sonnet 5.5
 * $2 / $10, read $0.20; Haiku 4.5 $1 / $5, read $0.10. Cache writes are 1.25× / 2× input on every
 * model, so their factor is the input factor. Opus is 1.0, so every figure measured so far keeps its number.
 * These factors multiply PIPELINE_COST_WEIGHTS, which stay a proxy (cache read 0.1× input where Opus 5.5's
 * real ratio is 0.05×): change the two together, or runs stop being comparable with each other.
 */
const PIPELINE_MODEL_FACTORS = [
    'opus' => ['input' => 1.0, 'write5m' => 1.0, 'write1h' => 1.0, 'read' => 1.0, 'output' => 1.0],
    'fable' => ['input' => 2.5, 'write5m' => 2.5, 'write1h' => 2.5, 'read' => 1.25, 'output' => 2.5],
    'sonnet' => ['input' => 0.5, 'write5m' => 0.5, 'write1h' => 0.5, 'read' => 1.0, 'output' => 0.5],
    'haiku' => ['input' => 0.25, 'write5m' => 0.25, 'write1h' => 0.25, 'read' => 0.5, 'output' => 0.25],
];

/** @return list<array> the lines that decode to a JSON object */
function pipeline_jsonl(string $jsonl): array
{
    return array_values(array_filter(array_map(fn (string $line) => json_decode($line, true), explode("\n", $jsonl)), 'is_array'));
}

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

function pipeline_call_context(array $usage): int
{
    return ($usage['input_tokens'] ?? 0) + ($usage['cache_creation_input_tokens'] ?? 0) + ($usage['cache_read_input_tokens'] ?? 0);
}

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

/**
 * The steps a workflow run started, in order, from its `journal.jsonl`: `started` names an agent's
 * label, `result` its return value. A step that never returned has a null result.
 *
 * @return list<array{agent: string, label: string, result: mixed}>
 */
function pipeline_run_journal(string $jsonl): array
{
    $steps = [];
    foreach (pipeline_jsonl($jsonl) as $entry) {
        $agent = $entry['agentId'] ?? null;
        if ($agent === null) {
            continue;
        }
        if (($entry['type'] ?? null) === 'started') {
            $steps[$agent] = ['agent' => $agent, 'label' => (string) ($entry['label'] ?? $agent), 'result' => null];
        }
        if (($entry['type'] ?? null) === 'result' && isset($steps[$agent])) {
            $steps[$agent]['result'] = $entry['result'] ?? null;
        }
    }

    return array_values($steps);
}

/** A record's `timestamp` as Unix seconds with milliseconds; null when it has none that parses (`''` would parse as now). */
function pipeline_record_time(array $entry): ?float
{
    $timestamp = $entry['timestamp'] ?? null;
    if (! is_string($timestamp) || $timestamp === '') {
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

/** The run's span, earliest step start to latest step end, over the steps that have one. */
function pipeline_run_seconds(array $steps): float
{
    $timed = array_filter($steps, fn (array $step) => $step['start'] !== null);

    return $timed === [] ? 0.0 : max(array_column($timed, 'end')) - min(array_column($timed, 'start'));
}

/** @param list<array{label: string, calls: int, cost: float, peak: int, models: list<string>, start: ?float, end: ?float, wall: float, waiting: float}> $steps */
function pipeline_run_cost_lines(array $steps): array
{
    if ($steps === []) {
        return ['run: not measured (no step transcripts)'];
    }
    $largest = array_reduce($steps, fn (?array $carry, array $step) => $carry === null || $step['peak'] > $carry['peak'] ? $step : $carry);

    return [
        ...array_map(fn (array $step) => sprintf('%s%s: %.2fM over %d calls, peak %dk, %.1f min (%.1f waiting on tools)', $step['label'], $step['models'] === [] ? '' : ' (' . implode('+', $step['models']) . ')', $step['cost'] / 1e6, $step['calls'], intdiv($step['peak'], 1000), $step['wall'] / 60, $step['waiting'] / 60), $steps),
        sprintf('run: %.2fM weighted over %d steps in %.1f min; largest step peak %dk (%s)', array_sum(array_column($steps, 'cost')) / 1e6, count($steps), pipeline_run_seconds($steps) / 60, intdiv($largest['peak'], 1000), $largest['label']),
    ];
}
