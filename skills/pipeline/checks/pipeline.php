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

/** @return array{auto_continue: bool, gates: array{plan-approval: string, pr-review: string}} */
function pipeline_resolve_policy(string $mode): array
{
    return [
        'auto_continue' => $mode === 'auto',
        'gates' => ['plan-approval' => 'stop', 'pr-review' => 'stop'],
    ];
}
