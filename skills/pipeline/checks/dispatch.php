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
            $step === 'resolve' => [self::Continued, self::LoopedBack, self::Halted],
            $leg === 'verify-ui' => [self::Continued, self::LoopedBack, self::Halted, self::PlanInsufficient],
            default => [self::Continued, self::Halted, self::PlanInsufficient],
        };
    }
}

const PIPELINE_GATE_OF = ['review-plan' => 'plan-approval', 'review-pr' => 'pr-review', 'verify-ui' => 'verify-ui'];

const PIPELINE_LOOP_BOUND = 2;

/** @return list<string> */
function pipeline_steps(string $leg): array
{
    return in_array($leg, ['review-plan', 'review-pr'], true) ? ['review', 'resolve'] : ['run'];
}

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
    if (pipeline_steps($leg) === ['run']) {
        return 'run';
    }

    return pipeline_open_entry($manifest['gate_ledger'] ?? [], pipeline_gate_of($leg)) === null ? 'review' : 'resolve';
}

/** Anything that is neither `auto` nor `autoflow` behaves as interactive (`gates.md` §Modes): the human designs and resolves. */
function pipeline_runs_inline(string $mode, string $leg, string $step): bool
{
    return ! in_array($mode, ['auto', 'autoflow'], true) && ($leg === 'design' || $step === 'resolve');
}

/** @return list<string> the only manifest keys a leg may change; `cursor.*` is one level down */
function pipeline_leg_writable_keys(): array
{
    return ['artifacts', 'last_sha', 'suite', 'gate_ledger', 'cursor.status', 'cursor.reason'];
}

/**
 * What the dispatcher does after a step returns (spec §3). Fail-closed: a return the checks cannot
 * account for halts the run and says why.
 *
 * @return array{action: string, leg?: string, reason?: string}
 */
function pipeline_returned(array $before, array $after, array $triggers, DesignSize $size): array
{
    [$before, $after] = [pipeline_normalized($before), pipeline_normalized(pipeline_keep_retried($before, $after))];
    $leg = $before['cursor']['leg'];
    $step = pipeline_step($before, $leg);

    if ($after === $before) {
        return $step === 'review' && empty($before['cursor']['retried'])
            ? ['action' => 'retry']
            : pipeline_halt("the {$leg} {$step} step returned without writing the manifest");
    }

    $problem = pipeline_return_problem($before, $after, $leg, $step, $size);

    return $problem === null ? pipeline_route($after, $leg, $step, $triggers, $size) : pipeline_halt($problem);
}

/**
 * Key order is not content. Legs rewrite JSON with whatever tool they hold, so every comparison
 * runs over recursively key-sorted copies; list order (the ledger) is left as it is.
 */
function pipeline_normalized(array $value): array
{
    if (! array_is_list($value)) {
        ksort($value);
    }

    return array_map(fn ($item) => is_array($item) ? pipeline_normalized($item) : $item, $value);
}

/** `cursor.retried` is the dispatcher's; a leg that rewrites the cursor without it has not changed it. */
function pipeline_keep_retried(array $before, array $after): array
{
    if (isset($before['cursor']['retried']) && is_array($after['cursor'] ?? null) && ! array_key_exists('retried', $after['cursor'])) {
        $after['cursor']['retried'] = $before['cursor']['retried'];
    }

    return $after;
}

function pipeline_return_problem(array $before, array $after, string $leg, string $step, DesignSize $size): ?string
{
    $missing = manifest_validate($after);
    if ($missing !== []) {
        return 'the manifest lost ' . implode(', ', $missing);
    }

    $forbidden = array_diff(pipeline_changed_keys($before, $after), pipeline_leg_writable_keys());
    if ($forbidden !== []) {
        return 'the leg changed ' . implode(', ', $forbidden) . ', which only the dispatcher writes';
    }

    $status = LegStatus::tryFrom((string) ($after['cursor']['status'] ?? ''));
    if ($status === null) {
        return 'cursor.status is not a leg status: ' . json_encode($after['cursor']['status'] ?? null);
    }
    if (! in_array($status, LegStatus::allowedFor($leg, $step), true)) {
        return "the {$leg} {$step} step cannot return {$status->value}";
    }
    if ($status === LegStatus::Halted && trim((string) ($after['cursor']['reason'] ?? '')) === '') {
        return 'the leg halted without a reason';
    }

    return pipeline_ledger_problem($before['gate_ledger'] ?? [], $after['gate_ledger'] ?? [], $status, $leg, $step, $size);
}

/** @return list<string> top-level keys, and `cursor.*` one level down, whose values differ */
function pipeline_changed_keys(array $before, array $after): array
{
    $flat = function (array $manifest): array {
        $cursor = is_array($manifest['cursor'] ?? null) ? $manifest['cursor'] : [];
        unset($manifest['cursor']);
        foreach ($cursor as $key => $value) {
            $manifest["cursor.{$key}"] = $value;
        }

        return $manifest;
    };
    [$old, $new] = [$flat($before), $flat($after)];

    return array_values(array_filter(
        array_unique([...array_keys($old), ...array_keys($new)]),
        fn ($key) => ($old[$key] ?? null) !== ($new[$key] ?? null),
    ));
}

/** The ledger only grows, and what it grew by agrees with the step and its status. */
function pipeline_ledger_problem(array $old, array $new, LegStatus $status, string $leg, string $step, DesignSize $size): ?string
{
    $gate = pipeline_gate_of($leg);
    $open = $step === 'resolve' ? pipeline_open_entry($old, $gate) : null;
    $kept = ['gate', 'leg', 'cycle', 'at', 'review', 'annotations'];

    foreach ($old as $index => $entry) {
        $same = $index === $open
            ? pipeline_pick($new[$index] ?? [], $kept) === pipeline_pick($entry, $kept)
            : ($new[$index] ?? null) === $entry;
        if (! $same) {
            return "the leg rewrote ledger entry {$index}";
        }
    }

    $added = array_slice($new, count($old));
    $addedTo = fn (string $name) => array_values(array_filter($added, fn ($entry) => ($entry['gate'] ?? null) === $name));

    return match (true) {
        $status === LegStatus::Halted => null,
        $status === LegStatus::PlanInsufficient && $step === 'review' && array_filter($addedTo($gate), 'pipeline_is_open') !== []
            => "a review step that returns plan-insufficient may add no open {$gate} entry",
        $status === LegStatus::PlanInsufficient => $size === DesignSize::Bounded
            ? pipeline_added_with($addedTo('design-size'), 'escalated', 'plan-insufficient on a Bounded design needs a new design-size entry with outcome escalated')
            : pipeline_added_with($addedTo('plan-approval'), 'looped-back', 'plan-insufficient on an Architectural design needs a new plan-approval entry with outcome looped-back'),
        $step === 'review' => count($addedTo($gate)) === 1 && pipeline_is_open($addedTo($gate)[0])
            ? null
            : "the review step must add exactly one open {$gate} entry",
        $step === 'resolve' => ($new[$open]['outcome'] ?? null) === $status->value
            ? null
            : "the resolve step must set the open {$gate} entry's outcome to {$status->value}",
        $leg === 'verify-ui' => count($addedTo('verify-ui')) === 1 && ($addedTo('verify-ui')[0]['outcome'] ?? null) === $status->value
            ? null
            : "the verify-ui step must add one verify-ui entry with outcome {$status->value}",
        default => null,
    };
}

function pipeline_added_with(array $added, string $outcome, string $problem): ?string
{
    return array_filter($added, fn ($entry) => ($entry['outcome'] ?? null) === $outcome) === [] ? $problem : null;
}

function pipeline_pick(array $entry, array $keys): array
{
    return array_map(fn (string $key) => $entry[$key] ?? null, $keys);
}

function pipeline_route(array $after, string $leg, string $step, array $triggers, DesignSize $size): array
{
    return match (LegStatus::from($after['cursor']['status'])) {
        LegStatus::Halted => pipeline_halt((string) $after['cursor']['reason']),
        LegStatus::PlanInsufficient => $size === DesignSize::Bounded
            ? pipeline_dispatch('design')
            : pipeline_loop_back($after['gate_ledger'] ?? [], 'review-plan'),
        LegStatus::LoopedBack => pipeline_loop_back($after['gate_ledger'] ?? [], $leg),
        LegStatus::Continued => pipeline_continue($leg, $step, $triggers),
    };
}

function pipeline_continue(string $leg, string $step, array $triggers): array
{
    if ($step === 'review') {
        return pipeline_dispatch($leg);
    }
    $next = pipeline_next_leg($leg, $triggers);

    return $next === null ? ['action' => 'done'] : pipeline_dispatch($next);
}

/** The bound is read from the ledger, never from memory (`../references/manifest.md` §gate_ledger). */
function pipeline_loop_back(array $ledger, string $leg): array
{
    $gate = pipeline_gate_of($leg);
    $entries = array_filter($ledger, fn ($entry) => ($entry['gate'] ?? null) === $gate);

    if (in_array('unknown', array_column($entries, 'cycle'), true)) {
        return pipeline_halt("{$gate}: the loop-back count is unknown after a reconstruction, so no loop-back is allowed");
    }
    $loops = count(array_filter($entries, fn ($entry) => ($entry['outcome'] ?? null) === 'looped-back'));
    if ($loops > PIPELINE_LOOP_BOUND) {
        return pipeline_halt("{$gate}: loop-back bound exhausted, {$loops} loop-backs where " . PIPELINE_LOOP_BOUND . ' are allowed');
    }

    return pipeline_dispatch(pipeline_loop_target($leg));
}

function pipeline_dispatch(string $leg): array
{
    return ['action' => 'dispatch', 'leg' => $leg];
}

function pipeline_halt(string $reason): array
{
    return ['action' => 'halt', 'reason' => $reason];
}

/**
 * Loop-backs so far per looping leg, which the workflow script counts on from (`launch`). An
 * `unknown` cycle gives that leg the bound: after a reconstruction no loop-back is allowed.
 *
 * @return array<string, int>
 */
function pipeline_loop_counts(array $ledger): array
{
    $counts = [];
    foreach (PIPELINE_GATE_OF as $leg => $gate) {
        $entries = array_filter($ledger, fn ($entry) => ($entry['gate'] ?? null) === $gate);
        $counts[$leg] = in_array('unknown', array_column($entries, 'cycle'), true)
            ? PIPELINE_LOOP_BOUND
            : count(array_filter($entries, fn ($entry) => ($entry['outcome'] ?? null) === 'looped-back'));
    }

    return $counts;
}

/** Why the ledger does not support running this step now (`brief`'s check), or null. */
function pipeline_step_problem(array $manifest, string $leg, string $step): ?string
{
    if (! in_array($leg, pipeline_legs(), true) || ! in_array($step, pipeline_steps($leg), true)) {
        return "{$leg} has no {$step} step";
    }
    $gate = pipeline_gate_of($leg);
    $open = pipeline_open_entry($manifest['gate_ledger'] ?? [], $gate);

    return match (true) {
        $step === 'resolve' && $open === null => "no open {$gate} review to resolve",
        $step === 'review' && $open !== null => "gate_ledger[{$open}] is an open {$gate} review; resolve it first",
        default => null,
    };
}

/** `manifest.md` §Invariant check: once a run has a PR, it is an open draft. `$view` is `gh pr view --json state,isDraft`. */
function pipeline_pr_problem(int|string $pr, ?array $view): ?string
{
    return match (true) {
        $view === null => "PR #{$pr} cannot be read",
        ($view['state'] ?? null) !== 'OPEN' => "PR #{$pr} is " . strtolower((string) ($view['state'] ?? 'unknown')),
        empty($view['isDraft']) => "PR #{$pr} is not a draft; a run only works on a draft PR (`gh pr ready --undo {$pr}` first)",
        default => null,
    };
}
