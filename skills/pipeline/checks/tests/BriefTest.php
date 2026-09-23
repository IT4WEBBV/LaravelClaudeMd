<?php

function brief_manifest(string $leg, array $extra = []): array
{
    return [
        'branch' => 'feature/x', 'worktree' => '/tmp/wt', 'mode' => 'auto',
        'cursor' => ['leg' => $leg, 'status' => 'pending'],
        'artifacts' => ['idea' => '/tmp/idea.md', 'spec' => 'docs/spec.md', 'plan' => null, 'pr' => 42, 'issue' => null],
        'decisions' => ['The engine never edits.'],
        'last_sha' => 'abc1234',
        'suite' => ['tree' => 't1', 'outcome' => 'green', 'passed' => 104, 'failed' => 0, 'at' => '2026-09-22T10:00:00Z'],
        'gate_ledger' => [],
        ...$extra,
    ];
}

it('names the manifest where the dispatcher found it, not where the branch name suggests', function () {
    $nested = '/tmp/wt/.claude/pipeline/feature/issue-395-x.json';

    expect(pipeline_brief(brief_manifest('implement'), 'implement', $nested))
        ->toContain("- manifest: `{$nested}`")
        ->not->toContain('feature-x.json');
});

it('completes the open entry before the finish step\'s last action', function () {
    $open = ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r'];
    $brief = pipeline_brief(brief_manifest('review-pr', ['gate_ledger' => [$open]]), 'review-pr', '/tmp/m.json');

    expect(strpos($brief, 'Complete the open entry'))->toBeLessThan(strpos($brief, 'The last action is `proof_cli.php open`'));
});

it('has overrides for every leg and step, in both modes', function () {
    foreach (['auto', 'interactive'] as $mode) {
        foreach (pipeline_legs() as $leg) {
            foreach (in_array($leg, ['review-plan', 'review-pr'], true) ? ['review', 'resolve'] : ['run'] as $step) {
                expect(pipeline_leg_overrides($mode))->toHaveKey("{$leg}:{$step}");
            }
        }
    }
});

it('carries the pointers, the settled decisions and the suite line', function () {
    $brief = pipeline_brief(brief_manifest('implement'), 'implement', '/tmp/wt/.claude/pipeline/feature-x.json');

    expect($brief)
        ->toContain('`implement` leg, `run` step, of a `/pipeline auto` run')
        ->toContain('/tmp/wt/.claude/pipeline/feature-x.json')
        ->toContain('- spec: `docs/spec.md`')
        ->toContain('- pr: `42`')
        ->not->toContain('- plan:')
        ->toContain('The engine never edits.')
        ->toContain('full suite green over tree `t1` at `abc1234`: 104 passed, 0 failed')
        ->toContain('Leave the PR draft; this overrides any mark-ready instruction')
        ->toContain('`plan-insufficient`')
        ->toContain('Never move `cursor.leg`');
});

it('permits the design size the invocation allowed', function () {
    expect(pipeline_brief(brief_manifest('design'), 'design', '/tmp/wt/.claude/pipeline/feature-x.json'))->toContain('the Architectural path is required');
    expect(pipeline_brief(brief_manifest('design', ['light' => true]), 'design', '/tmp/wt/.claude/pipeline/feature-x.json'))->toContain('the Bounded path is permitted');
});

it('asks for grow form only after an escalation no plan approval has answered', function () {
    $escalated = ['gate' => 'design-size', 'leg' => 'implement', 'at' => '2026-09-22T12:00:00Z', 'reason' => 'migration', 'outcome' => 'escalated'];
    $approved = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'at' => '2026-09-22T13:00:00Z', 'review' => 'ok', 'outcome' => 'continued'];

    expect(pipeline_brief(brief_manifest('design', ['gate_ledger' => [$escalated]]), 'design', '/tmp/wt/.claude/pipeline/feature-x.json'))->toContain('Grow form');
    expect(pipeline_brief(brief_manifest('design', ['gate_ledger' => [$escalated, $approved]]), 'design', '/tmp/wt/.claude/pipeline/feature-x.json'))->not->toContain('Grow form');
});

it('tells a later leg how to report a plan gap, and design to extend the plan for it', function () {
    expect(pipeline_brief(brief_manifest('implement'), 'implement', '/tmp/m.json'))
        ->toContain('On an Architectural spec, append a `plan-approval` entry with `leg`, `cycle`, `at`, `reason` and outcome `looped-back`');

    $gap = ['gate' => 'plan-approval', 'leg' => 'implement', 'cycle' => 2, 'at' => '2026-09-22T12:00:00Z', 'reason' => 'needs a queue', 'outcome' => 'looped-back'];
    expect(pipeline_brief(brief_manifest('design', ['gate_ledger' => [$gap]]), 'design', '/tmp/m.json'))
        ->toContain('- redo what `gate_ledger[0]` looped back for')
        ->toContain('Plan gap: extend the plan');

    $reviewLoop = [...$gap, 'leg' => 'review-plan'];
    expect(pipeline_brief(brief_manifest('design', ['gate_ledger' => [$reviewLoop]]), 'design', '/tmp/m.json'))
        ->not->toContain('Plan gap');
});

it('gives the reviewer crafted context: no earlier review, no earlier actions', function () {
    $earlier = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'OLD REVIEW TEXT', 'actions' => [['claim' => 'x', 'disposition' => 'integrated', 'note' => 'OLD ACTION']], 'outcome' => 'looped-back'];
    $brief = pipeline_brief(brief_manifest('review-plan', ['mode' => 'interactive', 'gate_ledger' => [$earlier]]), 'review-plan', '/tmp/wt/.claude/pipeline/feature-x.json');

    expect($brief)
        ->toContain('`review` step')
        ->toContain('/critique plan')
        ->toContain('this review\'s `cycle`: `2`')
        ->toContain('The engine never edits.')
        ->not->toContain('OLD REVIEW TEXT')
        ->not->toContain('OLD ACTION')
        ->not->toContain('gate_ledger[0]');
});

it('points a resolve step at its open review without copying it', function () {
    $done = ['gate' => 'design-size', 'leg' => 'implement', 'at' => '2026-09-22T09:00:00Z', 'outcome' => 'escalated'];
    $open = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'NEW REVIEW'];
    $brief = pipeline_brief(brief_manifest('review-plan', ['gate_ledger' => [$done, $open]]), 'review-plan', '/tmp/wt/.claude/pipeline/feature-x.json');

    expect($brief)
        ->toContain('`resolve` step')
        ->toContain('the open review: `gate_ledger[1]`')
        ->toContain('Change nothing the review did not name.')
        ->not->toContain('NEW REVIEW');
});

it('points a looped-back leg at the entry that sent it back', function () {
    $planLoop = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r', 'outcome' => 'looped-back'];
    $uiLoop = ['gate' => 'verify-ui', 'cycle' => 1, 'at' => '2026-09-22T11:00:00Z', 'outcome' => 'looped-back'];

    expect(pipeline_brief(brief_manifest('design', ['gate_ledger' => [$planLoop]]), 'design', '/tmp/wt/.claude/pipeline/feature-x.json'))->toContain('`gate_ledger[0]` looped back');
    expect(pipeline_brief(brief_manifest('implement', ['gate_ledger' => [$uiLoop]]), 'implement', '/tmp/wt/.claude/pipeline/feature-x.json'))->toContain('`gate_ledger[0]` looped back');
    expect(pipeline_brief(brief_manifest('handoff', ['gate_ledger' => [$planLoop]]), 'handoff', '/tmp/wt/.claude/pipeline/feature-x.json'))->not->toContain('looped back');
});

it('makes the review-pr resolve step the finish step', function () {
    $open = ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r'];
    $brief = pipeline_brief(brief_manifest('review-pr', ['mode' => 'interactive', 'gate_ledger' => [$open]]), 'review-pr', '/tmp/wt/.claude/pipeline/feature-x.json');

    expect($brief)->toContain('the finish step')->toContain('gh pr ready')->toContain('proof_cli.php open');
});

it('takes the step from its caller when given one, and derives it otherwise', function () {
    expect(pipeline_brief(brief_manifest('review-plan'), 'review-plan', '/tmp/m.json', 'resolve'))->toContain('`review-plan` leg, `resolve` step');
    expect(pipeline_brief(brief_manifest('review-plan'), 'review-plan', '/tmp/m.json'))->toContain('`review-plan` leg, `review` step');
});

it('has an auto reviewer apply /critique itself, and an interactive one invoke it', function (string $leg, string $procedure) {
    $auto = pipeline_brief(brief_manifest($leg), $leg, '/tmp/m.json', 'review');
    $interactive = pipeline_brief(brief_manifest($leg, ['mode' => 'interactive']), $leg, '/tmp/m.json', 'review');

    expect($auto)
        ->toContain("Apply `/critique`'s `{$procedure}` procedure")
        ->toContain('rubric in `~/.claude/skills/critique/references/rubrics.md`')
        ->toContain('You are the reviewer; do not dispatch one')
        ->not->toContain("Invoke `/critique {$procedure}`");
    expect($interactive)->toContain("Invoke `/critique {$procedure}`")->not->toContain('do not dispatch one');
})->with([['review-plan', 'plan'], ['review-pr', 'pr']]);

it('drops the independent read and the subagents in auto', function () {
    $open = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r'];

    expect(pipeline_brief(brief_manifest('review-plan', ['gate_ledger' => [$open]]), 'review-plan', '/tmp/m.json'))->not->toContain('independent read');
    expect(pipeline_brief(brief_manifest('review-plan', ['mode' => 'interactive', 'gate_ledger' => [$open]]), 'review-plan', '/tmp/m.json'))->toContain('independent read');
    expect(pipeline_brief(brief_manifest('implement'), 'implement', '/tmp/m.json'))->toContain('Execute the plan inline, task by task; no subagents.');
    expect(pipeline_brief(brief_manifest('implement', ['mode' => 'interactive']), 'implement', '/tmp/m.json'))->not->toContain('no subagents');
});

it('tells implement to add the ci label before the push whose CI it watches, in both modes', function () {
    expect(pipeline_brief(brief_manifest('implement'), 'implement', '/tmp/m.json'))
        ->toContain('Add the `ci` label (`gh pr edit <pr> --add-label ci`) before the push whose CI you watch.');
    expect(pipeline_brief(brief_manifest('implement', ['mode' => 'interactive']), 'implement', '/tmp/m.json'))
        ->toContain('Add the `ci` label (`gh pr edit <pr> --add-label ci`) before the push whose CI you watch.');
});

it('leaves the PR draft at the auto finish step for the session that launched the run', function () {
    $open = ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r'];
    $brief = pipeline_brief(brief_manifest('review-pr', ['gate_ledger' => [$open]]), 'review-pr', '/tmp/m.json');

    expect($brief)
        ->toContain('Leave the PR draft; the session that launched the run marks it ready.')
        ->toContain('On a loop-back, stop there: no suite.')
        ->not->toContain('gh pr ready');
    expect(strpos($brief, 'Complete the open entry'))->toBeLessThan(strpos($brief, 'The last action is `proof_cli.php open`'));
});

it('tells every auto step where to work, that the run is authorised, and to return a structured result', function () {
    foreach (pipeline_legs() as $leg) {
        foreach (in_array($leg, ['review-plan', 'review-pr'], true) ? ['review', 'resolve'] : ['run'] as $step) {
            $auto = pipeline_brief(brief_manifest($leg), $leg, '/tmp/m.json', $step);
            $interactive = pipeline_brief(brief_manifest($leg, ['mode' => 'interactive']), $leg, '/tmp/m.json', $step);

            expect($auto)
                ->toContain('Run every command from `cd /tmp/wt`')
                ->toContain('The owner authorised this run, including pushing the branch and opening the draft PR; the pipeline never merges.')
                ->toContain('then return `{status, reason}` as your structured result');
            expect($interactive)
                ->not->toContain('The owner authorised this run')
                ->not->toContain('`cd /tmp/wt`')
                ->toContain('and reply with one line naming it');
        }
    }
});

it('tells a resolve step to loop back on a plan gap, and a review step to leave no review behind', function () {
    $open = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r'];

    expect(pipeline_brief(brief_manifest('review-plan', ['gate_ledger' => [$open]]), 'review-plan', '/tmp/m.json'))
        ->toContain('A plan gap or a Bounded escalation found while resolving is a loop-back: return `looped-back`')
        ->not->toContain('`plan-insufficient`');
    expect(pipeline_brief(brief_manifest('review-plan'), 'review-plan', '/tmp/m.json'))
        ->toContain('When you return `plan-insufficient`, append no review entry.');
    expect(pipeline_brief(brief_manifest('implement'), 'implement', '/tmp/m.json'))
        ->toContain('run the escalation check first')
        ->not->toContain('append no review entry');
});
