<?php

/**
 * After a `/pipeline autoflow` run: the two things the workflow takes on report (spec 2026-09-23 §No check
 * on what a step reports), as facts. A MISMATCH is the trigger for adding a check, never a halt.
 *
 *   php run_audit.php <manifest> <final PR diff> <run transcript dir>
 *
 * Always exits 0.
 */

require_once __DIR__ . '/triggers.php';
require_once __DIR__ . '/pipeline.php';
require_once __DIR__ . '/manifest.php';
require_once __DIR__ . '/dispatch.php';
require_once __DIR__ . '/run_cost.php';

/** `implement` reported `ui`; the final diff and the ledger say whether `verify-ui` should have run and did. */
function run_audit_ui(array $triggers, array $ledger): string
{
    $ran = array_filter($ledger, fn ($entry) => ($entry['gate'] ?? null) === 'verify-ui') !== [];

    return sprintf(
        'ui: the final diff %s the UI, the ledger has %s verify-ui entry — %s',
        $triggers['ui'] ? 'touches' : 'does not touch',
        $ran ? 'a' : 'no',
        $triggers['ui'] === $ran ? 'agree' : 'MISMATCH',
    );
}

/**
 * Per gate, what the run's steps reported against that many of the gate's newest ledger entries, so
 * the entries of an earlier run on the same manifest are never compared. A `review-plan` step's
 * `plan-insufficient` counts as a `review-plan` loop-back on both sides: the entry it writes (a
 * `plan-approval` loop-back, or on a Bounded spec a `design-size` escalation) names the leg
 * `review-plan`, which `pipeline_is_plan_gap` does not count as a gap.
 *
 * @param list<array{label: string, result: mixed}> $steps
 * @return list<string>
 */
function run_audit_gates(array $steps, array $ledger): array
{
    $reported = ['review-plan' => [], 'verify-ui' => [], 'review-pr' => [], 'plan gaps' => []];
    foreach ($steps as $step) {
        [$leg, $name] = explode(':', $step['label'], 2) + [1 => ''];
        $status = is_array($step['result']) ? ($step['result']['status'] ?? null) : null;
        if ($status === 'plan-insufficient' && $leg === 'review-plan') {
            $reported['review-plan'][] = 'looped-back';
        } elseif ($status === 'plan-insufficient') {
            $reported['plan gaps'][] = $leg;
        } elseif (in_array($status, ['continued', 'looped-back'], true) && ($name === 'resolve' || $leg === 'verify-ui')) {
            $reported[$leg][] = $status;
        }
    }

    $recorded = ['review-plan' => [], 'verify-ui' => [], 'review-pr' => [], 'plan gaps' => []];
    foreach ($ledger as $entry) {
        $outcome = $entry['outcome'] ?? null;
        $leg = pipeline_leg_of_gate((string) ($entry['gate'] ?? ''));
        if ($outcome === 'escalated' && ($entry['leg'] ?? null) === 'review-plan') {
            $recorded['review-plan'][] = 'looped-back';
        } elseif ($outcome === 'escalated' || pipeline_is_plan_gap($entry)) {
            $recorded['plan gaps'][] = (string) ($entry['leg'] ?? '');
        } elseif ($leg !== null && in_array($outcome, ['continued', 'looped-back'], true)) {
            $recorded[$leg][] = $outcome;
        }
    }

    return array_map(
        fn (string $name) => run_audit_line($name, $reported[$name], $reported[$name] === [] ? [] : array_slice($recorded[$name], -count($reported[$name]))),
        array_keys($reported),
    );
}

function run_audit_line(string $name, array $reported, array $recorded): string
{
    return sprintf(
        "%s: the steps reported [%s], the ledger's newest entries say [%s] — %s",
        $name,
        implode(', ', $reported),
        implode(', ', $recorded),
        $reported === $recorded ? 'agree' : 'MISMATCH',
    );
}

$read = fn (string $path) => is_file($path) ? (string) file_get_contents($path) : null;
$manifest = manifest_read((string) ($argv[1] ?? ''));
$diff = $read((string) ($argv[2] ?? ''));
$journal = $read(rtrim((string) ($argv[3] ?? ''), '/') . '/journal.jsonl');

if ($manifest === null || $diff === null || $journal === null) {
    echo "run audit: not performed (usage: run_audit.php <manifest> <final PR diff> <run transcript dir>; each must exist)\n";
    exit(0);
}

$ledger = pipeline_ledger($manifest);
echo implode("\n", [run_audit_ui(pipeline_triggers($diff), $ledger), ...run_audit_gates(pipeline_run_journal($journal), $ledger)]), "\n";
exit(0);
