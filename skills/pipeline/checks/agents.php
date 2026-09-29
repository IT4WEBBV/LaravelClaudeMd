<?php

/**
 * Which model and effort each `autoflow` step's agent runs on (`../references/engine.md` §Agents per
 * step): one table, handed to the workflow script in `launch`'s `start` answer, so the script names no
 * model or effort and nothing inherits the session's settings. Models are `agent()`'s aliases, efforts
 * its levels. Pure: `dispatch_cli.php` reads the manifest.
 */

const PIPELINE_AGENT_MODELS = ['opus', 'sonnet', 'fable'];

const PIPELINE_AGENT_EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

/**
 * The agents tier an `autoflow` run's invocation named (`../references/engine.md` §Agents per step):
 * `medium` or `light`, and `full` with no word. Its values are `PIPELINE_AGENTS`' tier keys.
 */
enum AgentTier: string
{
    case Full = 'full';
    case Medium = 'medium';
    case Light = 'light';

    /** The manifest's `tier`; else `medium` for a legacy `light: true`; else `full`. A `tier` that is not one of the three reads as `full`, the heavier side. */
    public static function fromManifest(array $manifest): self
    {
        if (array_key_exists('tier', $manifest)) {
            $tier = $manifest['tier'];

            return (is_string($tier) ? self::tryFrom($tier) : null) ?? self::Full;
        }

        return empty($manifest['light']) ? self::Full : self::Medium;
    }

    /** Whether the word permits a Bounded design (`../references/engine.md` §Design size). */
    public function permitsBounded(): bool
    {
        return $this !== self::Full;
    }

    /** The tier a design of this size runs on: up, never down. */
    public function forDesign(DesignSize $size): self
    {
        return $size === DesignSize::Bounded ? $this : self::Full;
    }
}

/** `full` and `light` hold every step; `loopedBack` replaces a step's entry once a gate looped back to its leg; `retry` reruns a review that returned nothing; `smoke` runs a smoke run's stubs. */
const PIPELINE_AGENTS = [
    'full' => [
        'design:spec' => ['model' => 'opus', 'effort' => 'high'],
        'design:plan' => ['model' => 'opus', 'effort' => 'high'],
        'review-plan:review' => ['model' => 'fable', 'effort' => 'high'],
        'review-plan:resolve' => ['model' => 'opus', 'effort' => 'high'],
        'handoff:run' => ['model' => 'sonnet', 'effort' => 'low'],
        'implement:run' => ['model' => 'opus', 'effort' => 'high'],
        'verify-ui:run' => ['model' => 'sonnet', 'effort' => 'high'],
        'review-pr:review' => ['model' => 'fable', 'effort' => 'high'],
        'review-pr:resolve' => ['model' => 'opus', 'effort' => 'high'],
    ],
    'light' => [
        'design:spec' => ['model' => 'opus', 'effort' => 'medium'],
        'design:plan' => ['model' => 'opus', 'effort' => 'medium'],
        'review-plan:review' => ['model' => 'fable', 'effort' => 'medium'],
        'review-plan:resolve' => ['model' => 'opus', 'effort' => 'medium'],
        'handoff:run' => ['model' => 'sonnet', 'effort' => 'low'],
        'implement:run' => ['model' => 'opus', 'effort' => 'high'],
        'verify-ui:run' => ['model' => 'sonnet', 'effort' => 'medium'],
        'review-pr:review' => ['model' => 'fable', 'effort' => 'high'],
        'review-pr:resolve' => ['model' => 'opus', 'effort' => 'medium'],
    ],
    'loopedBack' => [
        'implement:run' => ['model' => 'opus', 'effort' => 'xhigh'],
    ],
    'retry' => ['model' => 'opus', 'effort' => 'xhigh'],
    'smoke' => ['model' => 'sonnet', 'effort' => 'low'],
];

/** @return list<string> every `<leg>:<step>` of an `autoflow` run, in leg order */
function pipeline_agent_steps(): array
{
    return array_merge(...array_map(
        fn (string $leg) => array_map(fn (string $step) => "{$leg}:{$step}", pipeline_steps($leg, 'autoflow')),
        pipeline_legs(),
    ));
}

/** The script's `agents`: the table, with each step the manifest's override names laid over its entry in both profiles and its loop-back entry. */
function pipeline_agent_table(array $override): array
{
    $table = PIPELINE_AGENTS;
    foreach ($override as $step => $fields) {
        foreach (['full', 'light', 'loopedBack'] as $profile) {
            if (isset($table[$profile][$step])) {
                $table[$profile][$step] = [...$table[$profile][$step], ...$fields];
            }
        }
    }

    return $table;
}

/** What is wrong with the manifest's `agents` override, or null: an object of `autoflow` steps, each naming a model, an effort or both. */
function pipeline_agent_override_problem(mixed $override): ?string
{
    if (! is_array($override) || ($override !== [] && array_is_list($override))) {
        return 'it is not an object';
    }
    foreach ($override as $step => $entry) {
        $problem = in_array($step, pipeline_agent_steps(), true)
            ? pipeline_agent_entry_problem($step, $entry)
            : "`{$step}` is not an autoflow step";
        if ($problem !== null) {
            return $problem;
        }
    }

    return null;
}

function pipeline_agent_entry_problem(string $step, mixed $entry): ?string
{
    if (! is_array($entry) || array_is_list($entry)) {
        return "`{$step}` names no model or effort";
    }
    $unknown = array_diff(array_keys($entry), ['model', 'effort']);
    $outside = fn (string $field, array $allowed) => array_key_exists($field, $entry) && ! in_array($entry[$field], $allowed, true);

    return match (true) {
        $unknown !== [] => "`{$step}` has an unknown field `" . reset($unknown) . '`',
        $outside('model', PIPELINE_AGENT_MODELS) => "`{$step}` names model " . json_encode($entry['model']) . ', not one of ' . implode(', ', PIPELINE_AGENT_MODELS),
        $outside('effort', PIPELINE_AGENT_EFFORTS) => "`{$step}` names effort " . json_encode($entry['effort']) . ', not one of ' . implode(', ', PIPELINE_AGENT_EFFORTS),
        default => null,
    };
}

/**
 * The profile a run starts on, which the script keeps current from there: `full` once the ledger records
 * an escalation (one way, once per run); else the spec's size once a spec exists; else the `light` flag.
 * `$size` is `dispatch_cli_design_size($manifest)`, which answers Architectural for no spec as well.
 */
function pipeline_start_profile(array $manifest, DesignSize $size): string
{
    return match (true) {
        in_array('escalated', array_column(pipeline_ledger($manifest), 'outcome'), true) => 'full',
        ! empty($manifest['artifacts']['spec']) => $size->profile(),
        default => empty($manifest['light']) ? 'full' : 'light',
    };
}
