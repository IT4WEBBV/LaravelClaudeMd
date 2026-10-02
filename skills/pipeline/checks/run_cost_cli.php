<?php

/**
 * After a `/pipeline autoflow` run: per step its weighted cost, peak context, wall time and the part of
 * that spent waiting on tools; for the run the total cost, its span in minutes and the largest step peak.
 *
 *   php run_cost_cli.php <run transcript dir> [<proof page>]
 *
 * The dir is `~/.claude/projects/<project>/<session>/subagents/workflows/wf_<id>/`, named in the
 * Workflow result. Given the run's proof page (`artifacts.proof`) it also files the figures into it, one entry per
 * transcript dir (`proof_add_cost()`), so the page and the store index show them. Always exits 0: cost is reported,
 * never a halt, and a page that cannot take the figures is one line on stderr.
 */

require_once __DIR__ . '/run_cost.php';
require_once __DIR__ . '/proof_store.php';

$dir = rtrim((string) ($argv[1] ?? ''), '/');
$page = (string) ($argv[2] ?? '');
$read = fn (string $path) => is_file($path) ? (string) file_get_contents($path) : '';

$steps = array_map(function (array $step) use ($dir, $read) {
    $transcript = $read("{$dir}/agent-{$step['agent']}.jsonl");

    return ['label' => $step['label'], ...pipeline_transcript_cost($transcript), ...pipeline_transcript_time($transcript)];
}, pipeline_run_journal($read("{$dir}/journal.jsonl")));

echo implode("\n", pipeline_run_cost_lines($steps)), "\n";

if ($page !== '' && $steps !== []) {
    $record = pipeline_run_cost_record(basename($dir), $steps);
    $problem = proof_store_amend($page, fn (array $run): array => proof_add_cost($run, $record));
    if ($problem !== null) {
        fwrite(STDERR, "proof: cost not filed: {$problem}\n");
    }
}
exit(0);
