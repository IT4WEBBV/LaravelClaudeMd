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
