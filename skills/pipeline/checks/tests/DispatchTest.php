<?php

function dispatch_manifest(string $leg, array $ledger = []): array
{
    return ['branch' => 'feature/x', 'worktree' => '/tmp/wt', 'mode' => 'interactive', 'cursor' => ['leg' => $leg, 'status' => 'pending'], 'gate_ledger' => $ledger];
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

it('runs design and the resolve step inline outside autoflow', function () {
    expect(pipeline_runs_inline('interactive', 'design', 'run'))->toBeTrue();
    expect(pipeline_runs_inline('interactive', 'review-pr', 'resolve'))->toBeTrue();
    expect(pipeline_runs_inline('interactive', 'review-pr', 'review'))->toBeFalse();
    expect(pipeline_runs_inline('interactive', 'implement', 'run'))->toBeFalse();
    expect(pipeline_runs_inline('mangled', 'design', 'run'))->toBeTrue();
    expect(pipeline_runs_inline('autoflow', 'design', 'run'))->toBeFalse();
    expect(pipeline_runs_inline('autoflow', 'review-pr', 'resolve'))->toBeFalse();
    expect(pipeline_runs_inline('mangled', 'review-plan', 'resolve'))->toBeTrue();
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

it('lists each leg\'s steps: autoflow designs in a spec step and a plan step, interactive in one', function () {
    expect(pipeline_steps('review-plan', 'autoflow'))->toBe(['review', 'resolve']);
    expect(pipeline_steps('review-pr', 'interactive'))->toBe(['review', 'resolve']);
    expect(pipeline_steps('implement', 'autoflow'))->toBe(['run']);
    expect(pipeline_steps('design', 'autoflow'))->toBe(['spec', 'plan']);
    expect(pipeline_steps('design', 'interactive'))->toBe(['run']);
    expect(pipeline_steps('design', 'mangled'))->toBe(['run']);
    expect(PIPELINE_BOUNDED_STEPS)->toBe(['design' => ['spec']]);
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

it('refuses the removed auto mode by naming autoflow, and nothing else', function () {
    expect(pipeline_retired_mode('auto'))->toBe('mode auto was removed; autoflow is the unattended mode: kick off without --mode, and resume an auto manifest by setting its mode to autoflow and running launch');
    expect(pipeline_retired_mode('autoflow'))->toBeNull();
    expect(pipeline_retired_mode('interactive'))->toBeNull();
    expect(pipeline_retired_mode('mangled'))->toBeNull();
});

function design_manifest(?string $spec, ?string $plan, array $ledger = [], string $mode = 'autoflow'): array
{
    return [...dispatch_manifest('design', $ledger), 'mode' => $mode, 'artifacts' => ['spec' => $spec, 'plan' => $plan]];
}

const DESIGN_REVIEW_LOOP = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-29T10:00:00Z', 'review' => 'r', 'outcome' => 'looped-back'];
const DESIGN_GAP = ['gate' => 'plan-approval', 'leg' => 'implement', 'cycle' => 1, 'at' => '2026-09-29T11:00:00Z', 'reason' => 'needs a queue', 'outcome' => 'looped-back'];

it('derives autoflow\'s next design step from the spec, the plan and the newest ledger entry', function () {
    $approved = [...DESIGN_REVIEW_LOOP, 'outcome' => 'continued'];
    $reviewGap = [...DESIGN_GAP, 'leg' => 'review-plan'];
    $escalated = ['gate' => 'design-size', 'leg' => 'implement', 'at' => '2026-09-29T12:00:00Z', 'reason' => 'migration', 'outcome' => 'escalated'];

    expect(pipeline_step(design_manifest(null, null), 'design'))->toBe('spec');
    expect(pipeline_step(design_manifest('s.md', null), 'design'))->toBe('plan');
    expect(pipeline_step(design_manifest('s.md', 'p.md', [$approved]), 'design'))->toBe('spec');
    expect(pipeline_step(design_manifest('s.md', 'p.md', [DESIGN_REVIEW_LOOP]), 'design'))->toBe('spec');
    expect(pipeline_step(design_manifest('s.md', 'p.md', [DESIGN_GAP]), 'design'))->toBe('plan');
    expect(pipeline_step(design_manifest('s.md', 'p.md', [$reviewGap]), 'design'))->toBe('plan');
    expect(pipeline_step(design_manifest('s.md', 'p.md', [DESIGN_GAP, $approved]), 'design'))->toBe('spec');
    expect(pipeline_step(design_manifest('s.md', 'p.md', [$escalated]), 'design'))->toBe('spec');
    expect(pipeline_step(design_manifest(null, null, [DESIGN_GAP]), 'design'))->toBe('spec');
    expect(pipeline_step(design_manifest('s.md', null, [], 'interactive'), 'design'))->toBe('run');
});

it('reads a plan return as the entry of a plan-insufficient on an Architectural design, review-plan\'s included', function () {
    expect(pipeline_is_plan_return(DESIGN_GAP))->toBeTrue();
    expect(pipeline_is_plan_return([...DESIGN_GAP, 'leg' => 'review-plan']))->toBeTrue();
    expect(pipeline_is_plan_return(DESIGN_REVIEW_LOOP))->toBeFalse();
    expect(pipeline_is_plan_return([...DESIGN_GAP, 'outcome' => 'continued']))->toBeFalse();
    expect(pipeline_is_plan_return([...DESIGN_GAP, 'gate' => 'design-size', 'outcome' => 'escalated']))->toBeFalse();
    expect(pipeline_is_plan_return([]))->toBeFalse();
});

it('refuses the design step the manifest does not call for', function () {
    $refusal = fn (string $next, string $step) => "the manifest calls for the design {$next} step, not {$step}: the plan step follows a spec step that set artifacts.spec and removed artifacts.plan, or a plan-insufficient on an Architectural design";

    expect(pipeline_step_problem(design_manifest(null, null), 'design', 'spec'))->toBeNull();
    expect(pipeline_step_problem(design_manifest('s.md', null), 'design', 'plan'))->toBeNull();
    expect(pipeline_step_problem(design_manifest('s.md', 'p.md', [DESIGN_GAP]), 'design', 'plan'))->toBeNull();
    expect(pipeline_step_problem(design_manifest(null, null), 'design', 'plan'))->toBe($refusal('spec', 'plan'));
    expect(pipeline_step_problem(design_manifest('s.md', 'p.md', [DESIGN_REVIEW_LOOP]), 'design', 'plan'))->toBe($refusal('spec', 'plan'));
    expect(pipeline_step_problem(design_manifest('s.md', null), 'design', 'spec'))->toBe($refusal('plan', 'spec'));
    expect(pipeline_step_problem(design_manifest('s.md', null), 'design', 'run'))->toBe('design has no run step');
    expect(pipeline_step_problem(design_manifest('s.md', null, [], 'interactive'), 'design', 'run'))->toBeNull();
});
