<?php

/** @return list<string> */
function pipeline_legs(): array
{
    return ['design', 'review-plan', 'handoff', 'implement', 'verify-ui', 'review-pr'];
}

/** Gate legs that must not be skipped forward-over. verify-ui only counts when UI is touched. */
function pipeline_gate_legs(array $triggers): array
{
    $gates = ['review-plan', 'review-pr'];
    if (! empty($triggers['ui'])) {
        $gates[] = 'verify-ui';
    }

    return $gates;
}

function pipeline_next_leg(string $cursor, array $triggers): ?string
{
    $legs = pipeline_legs();
    $i = array_search($cursor, $legs, true);
    if ($i === false) {
        return null;
    }
    for ($j = $i + 1; $j < count($legs); $j++) {
        if ($legs[$j] === 'verify-ui' && empty($triggers['ui'])) {
            continue; // skip the conditional leg
        }

        return $legs[$j];
    }

    return null;
}

function pipeline_can_navigate(string $from, string $to, array $doneLegs, array $triggers): bool
{
    $legs = pipeline_legs();
    $fi = array_search($from, $legs, true);
    $ti = array_search($to, $legs, true);
    if ($fi === false || $ti === false) {
        return false;
    }
    if ($ti <= $fi) {
        return true; // backward or same: always allowed
    }
    // forward: every gate leg strictly before $to must have run
    foreach (pipeline_gate_legs($triggers) as $gate) {
        $gi = array_search($gate, $legs, true);
        if ($gi < $ti && ! in_array($gate, $doneLegs, true)) {
            return false;
        }
    }

    return true;
}

/**
 * The gate legs that have run, as `pipeline_can_navigate`'s `$doneLegs`. A gate counts once it has
 * a `continued` entry — but only one newer than the latest `design-size` escalation or plan gap (a
 * `plan-approval` loop-back written by a leg after `review-plan`): a plan that grew is a different
 * plan, and the pass over the old one must not let navigation skip the re-review
 * (`../references/engine.md` §Design size).
 */
function pipeline_done_legs(array $ledger): array
{
    $legOf = ['plan-approval' => 'review-plan', 'pr-review' => 'review-pr', 'verify-ui' => 'verify-ui'];

    $resetAt = array_column(
        array_filter($ledger, fn (array $entry) => ($entry['outcome'] ?? null) === 'escalated' || pipeline_is_plan_gap($entry)),
        'at',
    );
    $since = $resetAt === [] ? '' : max($resetAt);

    $done = [];
    foreach ($ledger as $entry) {
        $leg = $legOf[$entry['gate'] ?? ''] ?? null;
        if ($leg !== null && ($entry['outcome'] ?? null) === 'continued' && ($entry['at'] ?? '') > $since) {
            $done[] = $leg;
        }
    }

    return array_values(array_unique($done));
}

/** A `plan-approval` loop-back written by a leg after `review-plan`: the approved plan fell short (`../references/engine.md` §Design size). */
function pipeline_is_plan_gap(array $entry): bool
{
    return ($entry['gate'] ?? null) === 'plan-approval'
        && ($entry['outcome'] ?? null) === 'looped-back'
        && ($entry['leg'] ?? 'review-plan') !== 'review-plan';
}
