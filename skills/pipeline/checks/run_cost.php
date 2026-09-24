<?php

/**
 * What one `/pipeline autoflow` run cost (spec 2026-09-23 §Measurement), weighted as the 2026-09-22 token
 * audit weighs usage (`usage.py`). Assistant messages are deduplicated by `message.id`, the last
 * occurrence winning; context per call = input + cache writes + cache reads.
 */

const PIPELINE_COST_WEIGHTS = ['input' => 1.0, 'write5m' => 1.25, 'write1h' => 2.0, 'read' => 0.1, 'output' => 5.0];

/** @return list<array> one usage block per API call */
function pipeline_transcript_usage(string $jsonl): array
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

    return array_values($usage);
}

/** Writes without a 5m/1h split count as 5m writes, as `usage.py` counts them. */
function pipeline_call_cost(array $usage): float
{
    $split = $usage['cache_creation'] ?? [];
    $weights = PIPELINE_COST_WEIGHTS;

    return ($usage['input_tokens'] ?? 0) * $weights['input']
        + ($split === [] ? ($usage['cache_creation_input_tokens'] ?? 0) : ($split['ephemeral_5m_input_tokens'] ?? 0)) * $weights['write5m']
        + ($split['ephemeral_1h_input_tokens'] ?? 0) * $weights['write1h']
        + ($usage['cache_read_input_tokens'] ?? 0) * $weights['read']
        + ($usage['output_tokens'] ?? 0) * $weights['output'];
}

function pipeline_call_context(array $usage): int
{
    return ($usage['input_tokens'] ?? 0) + ($usage['cache_creation_input_tokens'] ?? 0) + ($usage['cache_read_input_tokens'] ?? 0);
}

/** @return array{calls: int, cost: float, peak: int} */
function pipeline_transcript_cost(string $jsonl): array
{
    $usage = pipeline_transcript_usage($jsonl);

    return [
        'calls' => count($usage),
        'cost' => (float) array_sum(array_map('pipeline_call_cost', $usage)),
        'peak' => $usage === [] ? 0 : max(array_map('pipeline_call_context', $usage)),
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
    foreach (explode("\n", $jsonl) as $line) {
        $entry = json_decode($line, true);
        $agent = is_array($entry) ? ($entry['agentId'] ?? null) : null;
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

/** @param list<array{label: string, calls: int, cost: float, peak: int}> $steps */
function pipeline_run_cost_lines(array $steps): array
{
    if ($steps === []) {
        return ['run: not measured (no step transcripts)'];
    }
    $largest = array_reduce($steps, fn (?array $carry, array $step) => $carry === null || $step['peak'] > $carry['peak'] ? $step : $carry);

    return [
        ...array_map(fn (array $step) => sprintf('%s: %.2fM over %d calls, peak %dk', $step['label'], $step['cost'] / 1e6, $step['calls'], intdiv($step['peak'], 1000)), $steps),
        sprintf('run: %.2fM weighted over %d steps; largest step peak %dk (%s)', array_sum(array_column($steps, 'cost')) / 1e6, count($steps), intdiv($largest['peak'], 1000), $largest['label']),
    ];
}
