<?php

function dispatch_manifest(string $leg, array $ledger = []): array
{
    return ['branch' => 'feature/x', 'worktree' => '/tmp/wt', 'mode' => 'auto', 'cursor' => ['leg' => $leg, 'status' => 'pending'], 'gate_ledger' => $ledger];
}

it('names the gate each gate leg writes, and back', function () {
    expect(pipeline_gate_of('review-plan'))->toBe('plan-approval');
    expect(pipeline_gate_of('review-pr'))->toBe('pr-review');
    expect(pipeline_gate_of('verify-ui'))->toBe('verify-ui');
    expect(pipeline_gate_of('implement'))->toBeNull();
    expect(pipeline_leg_of_gate('pr-review'))->toBe('review-pr');
    expect(pipeline_leg_of_gate('design-size'))->toBeNull();
});

it('loops each looping leg back to where the work is redone', function () {
    expect(pipeline_loop_target('review-plan'))->toBe('design');
    expect(pipeline_loop_target('verify-ui'))->toBe('implement');
    expect(pipeline_loop_target('review-pr'))->toBe('implement');
    expect(pipeline_loop_target('handoff'))->toBeNull();
});

it('derives review or resolve from the open ledger entry', function () {
    $open = ['gate' => 'plan-approval', 'review' => 'Step 4 drops the link.'];

    expect(pipeline_step(dispatch_manifest('review-plan'), 'review-plan'))->toBe('review');
    expect(pipeline_step(dispatch_manifest('review-plan', [$open]), 'review-plan'))->toBe('resolve');
    expect(pipeline_step(dispatch_manifest('review-plan', [[...$open, 'outcome' => 'looped-back']]), 'review-plan'))->toBe('review');
    expect(pipeline_step(dispatch_manifest('review-pr', [$open]), 'review-pr'))->toBe('review');
    expect(pipeline_step(dispatch_manifest('implement', [$open]), 'implement'))->toBe('run');
});

it('runs design and the resolve step inline outside auto', function () {
    expect(pipeline_runs_inline('interactive', 'design', 'run'))->toBeTrue();
    expect(pipeline_runs_inline('interactive', 'review-pr', 'resolve'))->toBeTrue();
    expect(pipeline_runs_inline('interactive', 'review-pr', 'review'))->toBeFalse();
    expect(pipeline_runs_inline('interactive', 'implement', 'run'))->toBeFalse();
    expect(pipeline_runs_inline('mangled', 'design', 'run'))->toBeTrue();
    expect(pipeline_runs_inline('auto', 'design', 'run'))->toBeFalse();
    expect(pipeline_runs_inline('auto', 'review-plan', 'resolve'))->toBeFalse();
});

it('allows each step only the statuses it can honestly return', function () {
    $values = fn (string $leg, string $step) => array_map(fn (LegStatus $status) => $status->value, LegStatus::allowedFor($leg, $step));

    expect($values('design', 'run'))->toBe(['continued', 'halted']);
    expect($values('review-plan', 'review'))->toBe(['continued', 'halted', 'plan-insufficient']);
    expect($values('review-pr', 'resolve'))->toBe(['continued', 'looped-back', 'halted', 'plan-insufficient']);
    expect($values('verify-ui', 'run'))->toBe(['continued', 'looped-back', 'halted', 'plan-insufficient']);
    expect($values('implement', 'run'))->toBe(['continued', 'halted', 'plan-insufficient']);
});

it('lets a leg write only its results', function () {
    expect(pipeline_leg_writable_keys())->toBe(['artifacts', 'last_sha', 'suite', 'gate_ledger', 'cursor.status', 'cursor.reason']);
});
