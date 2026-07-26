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
 * Gate policy for a mode.
 *
 * `interactive` stops at every gate — the human is present, so the gate is their turn.
 * `auto` adjudicates: the reviews still run, but their findings are proposals the engine
 * resolves itself, escalating only what an independent check confirms
 * (`pipeline_should_escalate`). An unrecognised mode gets the stricter policy.
 * See `../references/gates.md`.
 *
 * @return array{auto_continue: bool, gates: array{plan-approval: string, pr-review: string}}
 */
function pipeline_resolve_policy(string $mode): array
{
    $gate = $mode === 'auto' ? 'adjudicate' : 'stop';

    return [
        'auto_continue' => $mode === 'auto',
        'gates' => ['plan-approval' => $gate, 'pr-review' => $gate],
    ];
}

/**
 * Does this finding stop an `auto` run?
 *
 * Total over every finding the triage in `../references/engine.md` produces: the findings that
 * never went to adjudication — every Tier-2 — arrive with $adjudication === 'none'. Only a
 * *confirmed* blocking finding escalates; `refuted` and `uncertain` both continue, because
 * ambiguity buys a line in the PR body, not an interrupt. A malformed reviewer block is a
 * machinery failure handled by the engine's retry-then-halt rule, never routed through here.
 *
 * A *finding*, never a verdict. An overall `rework` is a property of the whole review, and a
 * confirmed one loops back rather than escalating — a decision that needs the cycle count this
 * function does not take. Passing a verdict here reads as "do not escalate" and silently drops
 * it; the engine's precedence rule routes it instead.
 *
 * @param  array{tier?: int|string, kind?: string}  $finding  a `findings[]` entry, or the
 *         synthesised `['kind' => 'architecture', …]` entry for a non-`none` architecture_judgment
 * @param  string  $adjudication  'none' | 'refuted' | 'confirmed' | 'uncertain'
 */
function pipeline_should_escalate(array $finding, string $adjudication): bool
{
    if ($adjudication !== 'confirmed') {
        return false;
    }

    return ($finding['kind'] ?? null) === 'architecture'
        || (int) ($finding['tier'] ?? 2) === 1;
}
