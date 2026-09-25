<?php

/**
 * After a `/pipeline autoflow` run: per step its weighted cost, peak context, wall time and the part of
 * that spent waiting on tools; for the run the total cost, its span in minutes and the largest step peak.
 *
 *   php run_cost_cli.php <run transcript dir>
 *
 * The dir is `~/.claude/projects/<project>/<session>/subagents/workflows/wf_<id>/`, named in the
 * Workflow result. Always exits 0: cost is reported, never a halt.
 */

require_once __DIR__ . '/run_cost.php';

$dir = rtrim((string) ($argv[1] ?? ''), '/');
$read = fn (string $path) => is_file($path) ? (string) file_get_contents($path) : '';

$steps = array_map(function (array $step) use ($dir, $read) {
    $transcript = $read("{$dir}/agent-{$step['agent']}.jsonl");

    return ['label' => $step['label'], ...pipeline_transcript_cost($transcript), ...pipeline_transcript_time($transcript)];
}, pipeline_run_journal($read("{$dir}/journal.jsonl")));

echo implode("\n", pipeline_run_cost_lines($steps)), "\n";
exit(0);
