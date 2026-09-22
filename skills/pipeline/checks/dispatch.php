<?php

/**
 * The dispatcher's decisions (`../references/engine.md` §The loop). Pure: `dispatch_cli.php` reads and
 * writes the manifest; these functions only decide which step comes next and whether a return holds.
 */

enum LegStatus: string
{
    case Continued = 'continued';
    case LoopedBack = 'looped-back';
    case Halted = 'halted';
    case PlanInsufficient = 'plan-insufficient';

    /** @return list<self> */
    public static function allowedFor(string $leg, string $step): array
    {
        return match (true) {
            $leg === 'design' => [self::Continued, self::Halted],
            $step === 'resolve', $leg === 'verify-ui' => [self::Continued, self::LoopedBack, self::Halted, self::PlanInsufficient],
            default => [self::Continued, self::Halted, self::PlanInsufficient],
        };
    }
}

const PIPELINE_GATE_OF = ['review-plan' => 'plan-approval', 'review-pr' => 'pr-review', 'verify-ui' => 'verify-ui'];

function pipeline_gate_of(string $leg): ?string
{
    return PIPELINE_GATE_OF[$leg] ?? null;
}

function pipeline_leg_of_gate(string $gate): ?string
{
    $leg = array_search($gate, PIPELINE_GATE_OF, true);

    return $leg === false ? null : $leg;
}

function pipeline_loop_target(string $leg): ?string
{
    return ['review-plan' => 'design', 'verify-ui' => 'implement', 'review-pr' => 'implement'][$leg] ?? null;
}

/** A review written and not yet acted on: it has a `review` and no `outcome`. */
function pipeline_is_open(mixed $entry): bool
{
    return is_array($entry) && array_key_exists('review', $entry) && ! array_key_exists('outcome', $entry);
}

function pipeline_open_entry(array $ledger, ?string $gate): ?int
{
    foreach ($ledger as $index => $entry) {
        if ($gate !== null && ($entry['gate'] ?? null) === $gate && pipeline_is_open($entry)) {
            return $index;
        }
    }

    return null;
}

/** `review` or `resolve` on the two review legs, derived from the ledger; `run` everywhere else. */
function pipeline_step(array $manifest, string $leg): string
{
    if (! in_array($leg, ['review-plan', 'review-pr'], true)) {
        return 'run';
    }

    return pipeline_open_entry($manifest['gate_ledger'] ?? [], pipeline_gate_of($leg)) === null ? 'review' : 'resolve';
}

/** Anything that is not `auto` behaves as interactive (`gates.md` §Modes): the human designs and resolves. */
function pipeline_runs_inline(string $mode, string $leg, string $step): bool
{
    return $mode !== 'auto' && ($leg === 'design' || $step === 'resolve');
}

/** @return list<string> the only manifest keys a leg may change; `cursor.*` is one level down */
function pipeline_leg_writable_keys(): array
{
    return ['artifacts', 'last_sha', 'suite', 'gate_ledger', 'cursor.status', 'cursor.reason'];
}
