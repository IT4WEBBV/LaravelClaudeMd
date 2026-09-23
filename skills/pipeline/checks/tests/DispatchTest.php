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
    expect($values('review-pr', 'resolve'))->toBe(['continued', 'looped-back', 'halted']);
    expect($values('review-plan', 'resolve'))->toBe(['continued', 'looped-back', 'halted']);
    expect($values('verify-ui', 'run'))->toBe(['continued', 'looped-back', 'halted', 'plan-insufficient']);
    expect($values('implement', 'run'))->toBe(['continued', 'halted', 'plan-insufficient']);
});

it('lets a leg write only its results', function () {
    expect(pipeline_leg_writable_keys())->toBe(['artifacts', 'last_sha', 'suite', 'gate_ledger', 'cursor.status', 'cursor.reason']);
});

it('lists each leg\'s steps', function () {
    expect(pipeline_steps('review-plan'))->toBe(['review', 'resolve']);
    expect(pipeline_steps('review-pr'))->toBe(['review', 'resolve']);
    expect(pipeline_steps('implement'))->toBe(['run']);
});

it('counts the loop-backs so far per looping leg, and gives an unknown count the bound', function () {
    $ledger = [
        ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'outcome' => 'looped-back'],
        ['gate' => 'plan-approval', 'leg' => 'implement', 'cycle' => 2, 'outcome' => 'looped-back'],
        ['gate' => 'design-size', 'leg' => 'implement', 'outcome' => 'escalated'],
        ['gate' => 'verify-ui', 'cycle' => 1, 'outcome' => 'continued'],
        ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 'unknown', 'review' => 'r'],
    ];

    expect(pipeline_loop_counts($ledger))->toBe(['review-plan' => 2, 'review-pr' => 2, 'verify-ui' => 0]);
    expect(pipeline_loop_counts([]))->toBe(['review-plan' => 0, 'review-pr' => 0, 'verify-ui' => 0]);
});

it('refuses a step the ledger does not support', function () {
    $open = ['gate' => 'plan-approval', 'review' => 'r'];

    expect(pipeline_step_problem(dispatch_manifest('review-plan'), 'review-plan', 'review'))->toBeNull();
    expect(pipeline_step_problem(dispatch_manifest('review-plan', [$open]), 'review-plan', 'resolve'))->toBeNull();
    expect(pipeline_step_problem(dispatch_manifest('implement'), 'implement', 'run'))->toBeNull();
    expect(pipeline_step_problem(dispatch_manifest('review-plan'), 'review-plan', 'resolve'))->toBe('no open plan-approval review to resolve');
    expect(pipeline_step_problem(dispatch_manifest('review-plan', [$open]), 'review-plan', 'review'))->toBe('gate_ledger[0] is an open plan-approval review; resolve it first');
    expect(pipeline_step_problem(dispatch_manifest('implement'), 'implement', 'review'))->toBe('implement has no review step');
    expect(pipeline_step_problem(dispatch_manifest('implement'), 'sideways', 'run'))->toBe('sideways has no run step');
});

it('expects the run\'s PR to be an open draft', function () {
    expect(pipeline_pr_problem(7, ['state' => 'OPEN', 'isDraft' => true]))->toBeNull();
    expect(pipeline_pr_problem(7, ['state' => 'OPEN', 'isDraft' => false]))->toBe('PR #7 is not a draft; a run only works on a draft PR (`gh pr ready --undo 7` first)');
    expect(pipeline_pr_problem(7, ['state' => 'MERGED', 'isDraft' => false]))->toBe('PR #7 is merged');
    expect(pipeline_pr_problem(7, null))->toBe('PR #7 cannot be read');
});
