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
