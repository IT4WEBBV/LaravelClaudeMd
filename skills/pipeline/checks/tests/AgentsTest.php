<?php

/** A profile as `model effort` per step, the way the docs and the replay write it. */
function agents_settings(array $profile): array
{
    return array_map(fn (array $entry) => "{$entry['model']} {$entry['effort']}", $profile);
}

it('holds an explicit model and effort for every autoflow step in every tier', function () {
    $table = pipeline_agent_table([]);

    expect(array_keys($table))->toBe(['full', 'medium', 'light', 'loopedBack', 'retry', 'smoke']);
    expect(agents_settings($table['full']))->toBe([
        'design:spec' => 'opus high',
        'design:plan' => 'opus high',
        'review-plan:review' => 'fable high',
        'review-plan:resolve' => 'opus high',
        'handoff:run' => 'sonnet low',
        'implement:run' => 'opus high',
        'verify-ui:run' => 'sonnet high',
        'review-pr:review' => 'fable high',
        'review-pr:resolve' => 'opus high',
    ]);
    expect(agents_settings($table['medium']))->toBe([
        'design:spec' => 'opus medium',
        'design:plan' => 'opus medium',
        'review-plan:review' => 'fable medium',
        'review-plan:resolve' => 'opus medium',
        'handoff:run' => 'sonnet low',
        'implement:run' => 'opus high',
        'verify-ui:run' => 'sonnet medium',
        'review-pr:review' => 'fable high',
        'review-pr:resolve' => 'opus medium',
    ]);
    expect(agents_settings($table['light']))->toBe([
        'design:spec' => 'opus medium',
        'design:plan' => 'opus medium',
        'review-plan:review' => 'opus medium',
        'review-plan:resolve' => 'sonnet medium',
        'handoff:run' => 'sonnet low',
        'implement:run' => 'sonnet high',
        'verify-ui:run' => 'sonnet medium',
        'review-pr:review' => 'opus high',
        'review-pr:resolve' => 'sonnet high',
    ]);
    expect(agents_settings($table['loopedBack']))->toBe(['implement:run' => 'opus xhigh']);
    expect($table['retry'])->toBe(['model' => 'opus', 'effort' => 'xhigh']);
    expect($table['smoke'])->toBe(['model' => 'sonnet', 'effort' => 'low']);
    foreach (AgentTier::cases() as $tier) {
        expect(array_keys($table[$tier->value]))->toBe(pipeline_agent_steps());
    }
});

it('lists every autoflow step, in leg order', function () {
    expect(pipeline_agent_steps())->toBe([
        'design:spec', 'design:plan', 'review-plan:review', 'review-plan:resolve', 'handoff:run',
        'implement:run', 'verify-ui:run', 'review-pr:review', 'review-pr:resolve',
    ]);
});

it('lays an override over the step it names in every tier and its loop-back entry, and leaves the rest', function () {
    $override = ['implement:run' => ['model' => 'sonnet', 'effort' => 'max'], 'review-plan:review' => ['effort' => 'low']];
    $table = pipeline_agent_table($override);
    $default = pipeline_agent_table([]);
    $others = fn (array $profile) => array_diff_key($profile, $override);

    foreach (['full', 'medium', 'light', 'loopedBack'] as $profile) {
        expect($table[$profile]['implement:run'])->toBe(['model' => 'sonnet', 'effort' => 'max']);
    }
    expect($table['full']['review-plan:review'])->toBe(['model' => 'fable', 'effort' => 'low']);
    expect($table['medium']['review-plan:review'])->toBe(['model' => 'fable', 'effort' => 'low']);
    expect($table['light']['review-plan:review'])->toBe(['model' => 'opus', 'effort' => 'low']);
    foreach (['full', 'medium', 'light'] as $tier) {
        expect($others($table[$tier]))->toBe($others($default[$tier]));
    }
    expect(array_keys($table['loopedBack']))->toBe(['implement:run']);
    expect($table['retry'])->toBe($default['retry']);
    expect($table['smoke'])->toBe($default['smoke']);
});

it('accepts no override and an override of one field', function (mixed $override) {
    expect(pipeline_agent_override_problem($override))->toBeNull();
})->with([
    'none' => [[]],
    'one step, one field' => [['review-pr:resolve' => ['effort' => 'medium']]],
    'two steps, both fields' => [['design:spec' => ['model' => 'fable', 'effort' => 'max'], 'handoff:run' => ['model' => 'opus', 'effort' => 'low']]],
]);

it('names what is wrong with an override', function (mixed $override, string $problem) {
    expect(pipeline_agent_override_problem($override))->toBe($problem);
})->with([
    'a string' => ['opus', 'it is not an object'],
    'a list' => [[['model' => 'opus']], 'it is not an object'],
    'an interactive step' => [['design:run' => ['effort' => 'low']], '`design:run` is not an autoflow step'],
    'a step the pipeline does not have' => [['review:plan' => ['effort' => 'low']], '`review:plan` is not an autoflow step'],
    'an entry with no field' => [['handoff:run' => []], '`handoff:run` names no model or effort'],
    'an entry that is a string' => [['handoff:run' => 'opus'], '`handoff:run` names no model or effort'],
    'an unknown field' => [['handoff:run' => ['model' => 'opus', 'thinking' => 'on']], '`handoff:run` has an unknown field `thinking`'],
    'a model outside the three' => [['handoff:run' => ['model' => 'haiku']], '`handoff:run` names model "haiku", not one of opus, sonnet, fable'],
    'an effort outside the levels' => [['handoff:run' => ['effort' => 'extreme']], '`handoff:run` names effort "extreme", not one of low, medium, high, xhigh, max'],
    'an effort that is not a string' => [['handoff:run' => ['effort' => 3]], '`handoff:run` names effort 3, not one of low, medium, high, xhigh, max'],
]);

it('starts a run on full after an escalation, on the named tier moved up by the spec\'s size once a spec exists, and on the named tier before', function (array $manifest, DesignSize $size, string $profile) {
    expect(pipeline_start_profile($manifest, $size))->toBe($profile);
})->with(function () {
    $spec = ['artifacts' => ['spec' => 'spec.md']];
    $escalated = ['gate_ledger' => [['gate' => 'design-size', 'leg' => 'handoff', 'at' => '2026-09-29T10:00:00Z', 'reason' => 'migration', 'outcome' => 'escalated']]];

    return [
        'no spec, no word' => [[], DesignSize::Architectural, 'full'],
        'no spec, medium' => [['tier' => 'medium'], DesignSize::Architectural, 'medium'],
        'no spec, light' => [['tier' => 'light'], DesignSize::Architectural, 'light'],
        'no spec, a legacy light flag' => [['light' => true], DesignSize::Architectural, 'medium'],
        'a Bounded spec with no word' => [$spec, DesignSize::Bounded, 'full'],
        'a Bounded spec with medium' => [[...$spec, 'tier' => 'medium'], DesignSize::Bounded, 'medium'],
        'a Bounded spec with light' => [[...$spec, 'tier' => 'light'], DesignSize::Bounded, 'light'],
        'a Bounded spec with a legacy light flag' => [[...$spec, 'light' => true], DesignSize::Bounded, 'medium'],
        'an Architectural spec with light' => [[...$spec, 'tier' => 'light'], DesignSize::Architectural, 'full'],
        'an escalation over a Bounded spec with light' => [[...$spec, ...$escalated, 'tier' => 'light'], DesignSize::Bounded, 'full'],
        'an escalation before a spec, with light' => [[...$escalated, 'tier' => 'light'], DesignSize::Architectural, 'full'],
    ];
});

it('reads the tier the invocation named from the manifest, and the heavier side from anything else', function (array $manifest, AgentTier $tier) {
    expect(AgentTier::fromManifest($manifest))->toBe($tier);
})->with([
    'no word' => [[], AgentTier::Full],
    'tier: medium' => [['tier' => 'medium'], AgentTier::Medium],
    'tier: light' => [['tier' => 'light'], AgentTier::Light],
    'tier: full, written by hand' => [['tier' => 'full'], AgentTier::Full],
    'a legacy light: true' => [['light' => true], AgentTier::Medium],
    'tier beside a legacy light: true' => [['tier' => 'light', 'light' => true], AgentTier::Light],
    'an unknown tier' => [['tier' => 'heavy'], AgentTier::Full],
    'a tier naming another table entry' => [['tier' => 'loopedBack'], AgentTier::Full],
    'a tier that is not a string' => [['tier' => true], AgentTier::Full],
    'a tier that is a list' => [['tier' => ['light']], AgentTier::Full],
    'an unknown tier beside a legacy light: true' => [['tier' => 'heavy', 'light' => true], AgentTier::Full],
]);

it('permits a Bounded design on medium and light, not on full', function () {
    expect(AgentTier::Full->permitsBounded())->toBeFalse();
    expect(AgentTier::Medium->permitsBounded())->toBeTrue();
    expect(AgentTier::Light->permitsBounded())->toBeTrue();
});

it('keeps a Bounded design on the named tier and moves an Architectural one up to full', function (AgentTier $tier) {
    expect($tier->forDesign(DesignSize::Bounded))->toBe($tier);
    expect($tier->forDesign(DesignSize::Architectural))->toBe(AgentTier::Full);
})->with(AgentTier::cases());
