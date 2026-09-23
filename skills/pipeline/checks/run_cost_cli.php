<?php

/**
 * After a `/pipeline auto` run: its weighted cost per step and the largest step peak.
 *
 *   php run_cost_cli.php <run transcript dir>
 *
 * The dir is `~/.claude/projects/<project>/<session>/subagents/workflows/wf_<id>/`, named in the
 * Workflow result. Always exits 0: cost is reported, never a halt.
 */

require_once __DIR__ . '/run_cost.php';

$dir = rtrim((string) ($argv[1] ?? ''), '/');
$read = fn (string $path) => is_file($path) ? (string) file_get_contents($path) : '';

$steps = array_map(
    fn (array $step) => ['label' => $step['label'], ...pipeline_transcript_cost($read("{$dir}/agent-{$step['agent']}.jsonl"))],
    pipeline_run_journal($read("{$dir}/journal.jsonl")),
);

echo implode("\n", pipeline_run_cost_lines($steps)), "\n";
exit(0);
