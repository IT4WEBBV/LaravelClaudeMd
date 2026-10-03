<?php

function brief_manifest(string $leg, array $extra = []): array
{
    return [
        'branch' => 'feature/x', 'worktree' => '/tmp/wt', 'mode' => 'interactive',
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

it('has the finish step write its actions, and open no page', function () {
    $open = ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r'];
    $brief = pipeline_brief(brief_manifest('review-pr', ['gate_ledger' => [$open]]), 'review-pr', '/tmp/m.json');

    expect($brief)->toContain('m.actions.json')->not->toContain('proof_cli.php open');
});

it('has overrides for every leg and step, in autoflow and interactive', function () {
    foreach (['autoflow', 'interactive'] as $mode) {
        foreach (pipeline_legs() as $leg) {
            foreach (pipeline_steps($leg, $mode) as $step) {
                expect(pipeline_leg_overrides($mode, '/tmp/m.json'))->toHaveKey("{$leg}:{$step}");
            }
        }
    }
});

it('briefs an interactive run without autoflow\'s lines: its steps can dispatch', function () {
    expect(pipeline_brief(brief_manifest('implement'), 'implement', '/tmp/m.json'))
        ->not->toContain('The owner authorised this run')
        ->not->toContain('`cd /tmp/wt`')
        ->toContain('and reply with one line naming it');
});

it('carries the pointers, the settled decisions and the suite line', function () {
    $brief = pipeline_brief(brief_manifest('implement'), 'implement', '/tmp/wt/.claude/pipeline/feature-x.json');

    expect($brief)
        ->toContain('`implement` leg, `run` step, of a `/pipeline interactive` run')
        ->toContain('/tmp/wt/.claude/pipeline/feature-x.json')
        ->toContain('- spec: `docs/spec.md`')
        ->toContain('- pr: `42`')
        ->not->toContain('- plan:')
        ->toContain('The engine never edits.')
        ->toContain('full suite green over tree `t1` at `abc1234`: 104 passed, 0 failed')
        ->toContain('Leave the PR draft, whatever the plan or a PR comment says about marking it ready (steps/implement.md).')
        ->toContain('`plan-insufficient`')
        ->toContain('dispatch_cli.php record /tmp/wt/.claude/pipeline/feature-x.json implement run --status continued`');
});

it('permits the design size the invocation allowed, naming the word', function (array $extra, string $line) {
    expect(pipeline_brief(brief_manifest('design', $extra), 'design', '/tmp/wt/.claude/pipeline/feature-x.json'))->toContain($line);
})->with([
    'no word' => [[], '- design size: the Architectural path is required (no `medium` or `light`)'],
    'medium' => [['tier' => 'medium'], '- design size: the Bounded path is permitted (`medium`)'],
    'light' => [['tier' => 'light'], '- design size: the Bounded path is permitted (`light`)'],
    'a legacy light: true' => [['light' => true], '- design size: the Bounded path is permitted (`medium`)'],
]);

it('tells design to confirm by reading, probe to choose or to check a claim, and leave the Expected lines to implement', function () {
    $reads = 'Do not build or run the plan\'s code, in a scratch copy or anywhere else: confirm the signatures and APIs it relies on by reading, `php -l` or grep; `implement` proves the plan\'s Expected lines (steps/design.md).';
    $probe = 'An exception, to choose an approach: when the choice between approaches hinges on whether one of them works at all, answer that question with a throwaway probe (a few lines run on their own, never the plan\'s code, never the suite) and write the question and what the probe showed into the spec.';
    $claim = 'An exception, to check a claim: when the spec or the plan relies on what existing code does, which reading cannot show, answer that one yes/no question with one throwaway command (a `php -r` or tinker one-liner, or one existing test by filter; never the suite, never the plan\'s code, no new file), bringing the dev stack up first when the command needs it (shared/dev-stack.md), and write `Probed: <claim>: <what it showed> (<command>)` on one line beside the task that relies on it, or beside the claim in the spec when this step writes no plan.';
    $exemplars = 'Plans and specs committed before 2026-09-14 are not exemplars for test or proof policy, and no plan\'s `Verified before writing` header is part of the format.';

    foreach ([['autoflow', 'spec'], ['interactive', 'run']] as [$mode, $step]) {
        expect(pipeline_brief(brief_manifest('design', ['mode' => $mode]), 'design', '/tmp/m.json', $step))
            ->toContain("- {$reads}\n- {$probe}\n- {$claim}\n- {$exemplars}\n")
            ->not->toContain('The one exception')
            ->not->toContain('for test or proof policy.');
    }
});

it('splits autoflow\'s design into a spec step that stops at the spec and a plan step that reads it cold', function () {
    $manifest = brief_manifest('design', ['mode' => 'autoflow']);
    $spec = pipeline_brief($manifest, 'design', '/tmp/m.json', 'spec');
    $plan = pipeline_brief($manifest, 'design', '/tmp/m.json', 'plan');

    expect($spec)
        ->toContain('`design` leg, `spec` step')
        ->toContain('- Invoke `superpowers:brainstorming` and stop at the spec: on the Architectural path, where brainstorming hands over to `superpowers:writing-plans`, the plan is the next step\'s, `design:plan`, so do not invoke `writing-plans` and commit no plan (steps/design.md).')
        ->toContain('write each question and the answer you assumed into the spec\'s `## Assumptions` section')
        ->toContain('- Commit the spec; on the Bounded path, commit the plan as well, a second commit, at `docs/superpowers/plans/<date>-<slug>.md` beside the spec `docs/superpowers/specs/<date>-<slug>-design.md` (`pipeline_plan_path()`): a Bounded design has no plan step, and a grown design\'s plan step extends the plan it finds there. After your last commit the paths go to `record`: `--spec`, and `--plan` on the Bounded path only; it sets `artifacts.spec` and removes or sets `artifacts.plan` as the spec\'s size calls for. A halt before that leaves the manifest calling for this step again.')
        ->not->toContain('Commit the spec, then the plan: two commits.')
        ->not->toContain('The plan goes at');
    expect($plan)
        ->toContain('`design` leg, `plan` step')
        ->toContain('- Read the committed spec (`artifacts.spec`) cold, and the code it points at, and invoke `superpowers:writing-plans` on it; do not re-design what the spec settles (steps/design.md).')
        ->toContain('- Where the plan needs an answer the spec does not give, add the question and the answer you assumed to the spec\'s `## Assumptions` and commit that before the plan, so `/critique plan` audits it.')
        ->toContain(
            '- Do not build or run the plan\'s code, in a scratch copy or anywhere else: confirm the signatures and APIs it relies on by reading, `php -l` or grep; `implement` proves the plan\'s Expected lines (steps/design.md).' . "\n"
            . '- An exception, to check a claim: when the spec or the plan relies on what existing code does, which reading cannot show, answer that one yes/no question with one throwaway command (a `php -r` or tinker one-liner, or one existing test by filter; never the suite, never the plan\'s code, no new file), bringing the dev stack up first when the command needs it (shared/dev-stack.md), and write `Probed: <claim>: <what it showed> (<command>)` on one line beside the task that relies on it, or beside the claim in the spec when this step writes no plan.' . "\n"
            . '- Plans and specs committed before 2026-09-14 are not exemplars'
        )
        ->toContain('- Commit the plan; its path goes to `record` as `--plan`.')
        ->not->toContain('to choose an approach')
        ->not->toContain('Invoke `superpowers:brainstorming`');
    expect(pipeline_brief(brief_manifest('design'), 'design', '/tmp/m.json', 'run'))
        ->toContain('- Commit the spec, then the plan: two commits; their paths go to `record` as `--spec` and `--plan`.');
});

it('asks for grow form only after an escalation no plan approval has answered, split over autoflow\'s two design steps', function () {
    $escalated = ['gate' => 'design-size', 'leg' => 'implement', 'at' => '2026-09-22T12:00:00Z', 'reason' => 'migration', 'outcome' => 'escalated'];
    $approved = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'at' => '2026-09-22T13:00:00Z', 'review' => 'ok', 'outcome' => 'continued'];
    $grown = fn (string $mode, string $step, array $ledger) => pipeline_brief(brief_manifest('design', ['mode' => $mode, 'gate_ledger' => $ledger]), 'design', '/tmp/wt/.claude/pipeline/feature-x.json', $step);

    expect($grown('interactive', 'run', [$escalated]))->toContain('- Grow form: the design escalated from Bounded (steps/design.md). Grow the spec and the plan; do not re-design them.');
    expect($grown('interactive', 'run', [$escalated, $approved]))->not->toContain('Grow form');
    expect($grown('autoflow', 'spec', [$escalated]))->toContain('- Grow form: the design escalated from Bounded (steps/design.md). Grow the spec: its header says `**Design size:** Architectural` and a `## Grown from Bounded` section says what changed, why it grew, what already exists and what remains; do not re-design it. The plan step adds the remaining steps to the plan.');
    expect($grown('autoflow', 'plan', [$escalated]))
        ->toContain('- Grow form: the design escalated from Bounded (steps/design.md). Add the remaining steps to the plan, as the spec\'s `## Grown from Bounded` section names them; do not re-design it.')
        ->not->toContain('Grow the spec');
    expect($grown('autoflow', 'plan', [$escalated, $approved]))->not->toContain('Grow form');
});

it('points the plan step at the plan beside the spec, the one a loop-back or the grow form extends', function () {
    $escalated = ['gate' => 'design-size', 'leg' => 'implement', 'at' => '2026-09-22T12:00:00Z', 'reason' => 'migration', 'outcome' => 'escalated'];
    $spec = 'docs/superpowers/specs/2026-09-22-x-design.md';
    $line = '- The plan goes at `docs/superpowers/plans/2026-09-22-x.md`, beside the spec: when that file exists it is this design\'s plan, from an earlier pass or the Bounded plan this design grew from, so update it in place and write no second plan.';
    $brief = fn (string $step, array $ledger) => pipeline_brief(brief_manifest('design', ['mode' => 'autoflow', 'gate_ledger' => $ledger, 'artifacts' => ['spec' => $spec, 'plan' => null, 'pr' => 42, 'issue' => null]]), 'design', '/tmp/m.json', $step);

    expect($brief('plan', []))->toContain($line);
    expect($brief('plan', [$escalated]))
        ->toContain($line)
        ->toContain('Add the remaining steps to the plan');
    expect($brief('spec', [$escalated]))->not->toContain('The plan goes at');
    expect(pipeline_brief(brief_manifest('design', ['mode' => 'autoflow']), 'design', '/tmp/m.json', 'plan'))->not->toContain('The plan goes at');
    expect(pipeline_plan_path($spec))->toBe('docs/superpowers/plans/2026-09-22-x.md');
    expect(pipeline_plan_path('/tmp/wt/docs/superpowers/specs/2026-09-22-x-design.md'))->toBe('/tmp/wt/docs/superpowers/plans/2026-09-22-x.md');
    expect(pipeline_plan_path('docs/spec.md'))->toBeNull();
});

it('tells a later leg how to report a plan gap, and design to extend the plan for it', function () {
    expect(pipeline_brief(brief_manifest('implement'), 'implement', '/tmp/m.json'))
        ->toContain('On an Architectural spec: only when the plan falls short of what this step needs');

    $gap = ['gate' => 'plan-approval', 'leg' => 'implement', 'cycle' => 2, 'at' => '2026-09-22T12:00:00Z', 'reason' => 'needs a queue', 'outcome' => 'looped-back'];
    expect(pipeline_brief(brief_manifest('design', ['gate_ledger' => [$gap]]), 'design', '/tmp/m.json'))
        ->toContain('- redo what `gate_ledger[0]` looped back for')
        ->toContain('Plan gap: extend the plan')
        ->toContain('Leave that entry as it is, with no `actions`: what you did goes in the spec, the plan and the reason you return.');

    $reviewReturn = [...$gap, 'leg' => 'review-plan'];
    expect(pipeline_brief(brief_manifest('design', ['gate_ledger' => [$reviewReturn]]), 'design', '/tmp/m.json'))
        ->toContain('- redo what `gate_ledger[0]` looped back for')
        ->toContain('Plan gap: extend the plan')
        ->toContain('Leave that entry as it is, with no `actions`: what you did goes in the spec, the plan and the reason you return.');

    $reviewLoop = [...$reviewReturn, 'review' => 'r', 'actions' => [['claim' => 'x', 'disposition' => 'integrated', 'note' => 'n']]];
    expect(pipeline_brief(brief_manifest('design', ['gate_ledger' => [$reviewLoop]]), 'design', '/tmp/m.json'))
        ->toContain('- redo what `gate_ledger[0]` looped back for')
        ->not->toContain('Plan gap');
});

it('tells autoflow\'s plan step to extend the plan review-plan returned as insufficient, and to leave that entry alone', function () {
    $resolved = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-30T09:00:00Z', 'review' => 'r', 'actions' => [['claim' => 'x', 'disposition' => 'integrated', 'note' => 'n']], 'outcome' => 'looped-back'];
    $return = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 2, 'at' => '2026-09-30T10:00:00Z', 'reason' => 'needs a queue', 'outcome' => 'looped-back'];
    $approved = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 3, 'at' => '2026-09-30T11:00:00Z', 'review' => 'ok', 'outcome' => 'continued'];
    $artifacts = ['spec' => 'docs/superpowers/specs/2026-09-30-x-design.md', 'plan' => 'docs/superpowers/plans/2026-09-30-x.md', 'pr' => null, 'issue' => null];
    $manifest = fn (array $ledger) => brief_manifest('design', ['mode' => 'autoflow', 'artifacts' => $artifacts, 'gate_ledger' => $ledger]);

    expect(pipeline_brief($manifest([$resolved, $return]), 'design', '/tmp/m.json'))
        ->toContain('`design` leg, `plan` step')
        ->toContain('- redo what `gate_ledger[1]` looped back for')
        ->toContain('- The plan goes at `docs/superpowers/plans/2026-09-30-x.md`, beside the spec')
        ->toContain('- Plan gap: extend the plan (and the spec where it must say more) to cover the entry\'s `reason`')
        ->toContain('Leave that entry as it is, with no `actions`: what you did goes in the spec, the plan and the reason you return.');

    expect(pipeline_brief($manifest([$resolved, $return, $approved]), 'design', '/tmp/m.json', 'spec'))
        ->not->toContain('Plan gap');
});

it('gives the reviewer crafted context: no earlier review, no earlier actions', function () {
    $earlier = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'OLD REVIEW TEXT', 'actions' => [['claim' => 'x', 'disposition' => 'integrated', 'note' => 'OLD ACTION']], 'outcome' => 'looped-back'];
    $brief = pipeline_brief(brief_manifest('review-plan', ['mode' => 'interactive', 'gate_ledger' => [$earlier]]), 'review-plan', '/tmp/wt/.claude/pipeline/feature-x.json');

    expect($brief)
        ->toContain('`review` step')
        ->toContain('/critique plan')
        ->not->toContain('`cycle`')
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

    expect($brief)->toContain('the finish step')->toContain('gh pr ready')->toContain('Your reply names the page path `write` printed')->not->toContain('proof_cli.php open');
});

it('tells both review-pr steps the leg is not the review-pr skill, in either mode', function (string $mode, string $step, string $next) {
    $line = '- The leg\'s name is not a skill to invoke: do not invoke the `review-pr` skill (`/review-pr`), which posts its own review comment and changes the PR\'s draft state. This brief is the whole step (shared/review-pr.md).';
    $open = ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r'];
    $manifest = brief_manifest('review-pr', ['mode' => $mode, 'gate_ledger' => $step === 'resolve' ? [$open] : []]);

    expect(pipeline_brief($manifest, 'review-pr', '/tmp/m.json', $step))
        ->toContain("## Overrides\n\n{$line}\n- {$next}");
    expect(pipeline_brief($manifest, 'review-pr', '/tmp/m.json', 'resolve', brief_git_behind()))
        ->toContain("## Overrides\n\n- Catch up with the base first")
        ->toContain("Record the merge as that file says.\n{$line}\n- You are the finish step.");
})->with([
    'autoflow review' => ['autoflow', 'review', 'Apply `/critique`\'s `pr` procedure'],
    'interactive review' => ['interactive', 'review', 'Invoke `/critique pr`'],
    'autoflow resolve' => ['autoflow', 'resolve', 'You are the finish step.'],
    'interactive resolve' => ['interactive', 'resolve', 'You are the finish step.'],
]);

it('says the leg is not a skill to no step but handoff\'s and review-pr\'s', function () {
    foreach (['autoflow', 'interactive'] as $mode) {
        foreach (array_diff(pipeline_legs(), ['handoff', 'review-pr']) as $leg) {
            foreach (pipeline_steps($leg, $mode) as $step) {
                expect(pipeline_brief(brief_manifest($leg, ['mode' => $mode]), $leg, '/tmp/m.json', $step))
                    ->not->toContain('not a skill to invoke');
            }
        }
    }
});

it('takes the step from its caller when given one, and derives it otherwise', function () {
    expect(pipeline_brief(brief_manifest('review-plan'), 'review-plan', '/tmp/m.json', 'resolve'))->toContain('`review-plan` leg, `resolve` step');
    expect(pipeline_brief(brief_manifest('review-plan'), 'review-plan', '/tmp/m.json'))->toContain('`review-plan` leg, `review` step');
});

it('has an autoflow reviewer apply /critique itself, and an interactive one invoke it', function (string $leg, string $procedure) {
    $autoflow = pipeline_brief(brief_manifest($leg, ['mode' => 'autoflow']), $leg, '/tmp/m.json', 'review');
    $interactive = pipeline_brief(brief_manifest($leg, ['mode' => 'interactive']), $leg, '/tmp/m.json', 'review');

    expect($autoflow)
        ->toContain("Apply `/critique`'s `{$procedure}` procedure")
        ->toContain('rubric in `~/.claude/skills/critique/references/rubrics.md`')
        ->toContain('You are the reviewer; do not dispatch one')
        ->not->toContain("Invoke `/critique {$procedure}`");
    expect($interactive)->toContain("Invoke `/critique {$procedure}`")->not->toContain('do not dispatch one');
})->with([['review-plan', 'plan'], ['review-pr', 'pr']]);

it('drops the independent read and the subagents in autoflow', function () {
    $open = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r'];

    expect(pipeline_brief(brief_manifest('review-plan', ['mode' => 'autoflow', 'gate_ledger' => [$open]]), 'review-plan', '/tmp/m.json'))->not->toContain('independent read');
    expect(pipeline_brief(brief_manifest('review-plan', ['mode' => 'interactive', 'gate_ledger' => [$open]]), 'review-plan', '/tmp/m.json'))->toContain('independent read');
    expect(pipeline_brief(brief_manifest('implement', ['mode' => 'autoflow']), 'implement', '/tmp/m.json'))->toContain('Execute the plan inline, task by task; no subagents.');
    expect(pipeline_brief(brief_manifest('implement', ['mode' => 'interactive']), 'implement', '/tmp/m.json'))->not->toContain('no subagents');
});

it('tells an autoflow implement not to wait on CI, and an interactive one to label before the push whose CI it watches', function () {
    expect(pipeline_brief(brief_manifest('implement', ['mode' => 'autoflow']), 'implement', '/tmp/m.json'))
        ->toContain('Add the `ci` label (`gh pr edit <pr> --add-label ci`) before your first push, in a repo that has one, and do not wait on CI after it: the CI gate reads the PR\'s head commit before the PR goes ready (steps/implement.md).')
        ->not->toContain('the push whose CI you watch');
    expect(pipeline_brief(brief_manifest('implement'), 'implement', '/tmp/m.json'))
        ->toContain('Add the `ci` label (`gh pr edit <pr> --add-label ci`) before the push whose CI you watch.');
});

it('leaves the PR draft at the autoflow finish step for the session that launched the run', function () {
    $open = ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r'];
    $brief = pipeline_brief(brief_manifest('review-pr', ['mode' => 'autoflow', 'gate_ledger' => [$open]]), 'review-pr', '/tmp/m.json');

    expect($brief)
        ->toContain('Push your commits and leave the PR draft; the session that launched the run marks it ready after the CI gate (steps/finish.md).')
        ->toContain('On a loop-back, stop there: no suite.')
        ->not->toContain('gh pr ready')
        ->not->toContain('proof_cli.php status');
    expect($brief)->toContain('m.actions.json')->not->toContain('proof_cli.php open')->not->toContain('Your reply names the page path');
});

it('has the interactive finish step run the CI gate before gh pr ready', function () {
    $open = ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r'];

    expect(pipeline_brief(brief_manifest('review-pr', ['gate_ledger' => [$open]]), 'review-pr', '/tmp/m.json'))
        ->toContain('Run the CI gate (steps/finish.md) and `gh pr ready` when it answers `ready`, then `proof_cli.php status <the path write printed> ready`; show any other answer to the human. Your reply names the page path `write` printed: no page opens by itself (proof-store.md).');
});

it('makes a recorded red CI a finding of review-pr\'s review and resolve steps, and only then', function () {
    $open = ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 2, 'at' => '2026-09-22T12:00:00Z', 'review' => 'r'];
    $round = ['mode' => 'autoflow', 'decisions' => ['The engine never edits.', "CI red on the PR's head commit abc123: CI / ci failed (https://github.com/acme/app/actions/runs/11/job/12)"]];
    $review = 'The settled `CI red on the PR\'s head commit` decision is a finding of this review: read the failing job\'s log (`gh run view <run> --log-failed`, the run id from its link) and state the failure and its cause (shared/review-pr.md).';
    $resolve = 'Fix the `CI red` finding, or show it is unrelated to this change (the same failure on the base branch, or a flake: start `gh run rerun <run> --failed` and do not wait on it), and say which in `actions`; the CI gate reads the head commit again (shared/review-pr.md).';

    expect(pipeline_brief(brief_manifest('review-pr', $round), 'review-pr', '/tmp/m.json', 'review'))->toContain($review)->not->toContain($resolve);
    expect(pipeline_brief(brief_manifest('review-pr', [...$round, 'gate_ledger' => [$open]]), 'review-pr', '/tmp/m.json', 'resolve'))->toContain($resolve)->not->toContain($review);
    expect(pipeline_brief(brief_manifest('review-pr', ['mode' => 'autoflow']), 'review-pr', '/tmp/m.json', 'review'))->not->toContain('finding of this review: read the failing job');
    expect(pipeline_brief(brief_manifest('implement', $round), 'implement', '/tmp/m.json'))->not->toContain($review)->not->toContain($resolve);
});

it('makes a recorded conflict with the base a finding of review-pr\'s review and resolve steps, after the CI round\'s line, and only then (#149)', function () {
    $open = ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 2, 'at' => '2026-09-22T12:00:00Z', 'review' => 'r'];
    $conflict = "Conflict with the base on the PR's head commit abc123: GitHub reports PR #42 CONFLICTING with its base; review-pr's resolve step merges the base (shared/catch-up.md)";
    $red = "CI red on the PR's head commit abc123: CI / ci failed (https://github.com/acme/app/actions/runs/11/job/12)";
    $round = ['mode' => 'autoflow', 'decisions' => ['The engine never edits.', $conflict]];
    $review = 'The settled `Conflict with the base` decision is a finding of this review: name the conflict and leave the merge to the resolve step, since a review step does not merge (shared/review-pr.md).';
    $resolve = 'Resolve the `Conflict with the base` finding with the merge this brief\'s catch-up override asks for, and name it in `actions`; when this brief has no such override, say so in `actions` and change nothing for it: the CI gate reads the PR\'s mergeability again (shared/review-pr.md).';

    expect(pipeline_brief(brief_manifest('review-pr', $round), 'review-pr', '/tmp/m.json', 'review'))->toContain($review)->not->toContain($resolve);
    expect(pipeline_brief(brief_manifest('review-pr', [...$round, 'gate_ledger' => [$open]]), 'review-pr', '/tmp/m.json', 'resolve'))->toContain($resolve)->not->toContain($review);
    expect(pipeline_brief(brief_manifest('review-pr', ['mode' => 'autoflow', 'decisions' => [$red]]), 'review-pr', '/tmp/m.json', 'review'))->not->toContain('`Conflict with the base` decision is a finding');
    expect(pipeline_brief(brief_manifest('implement', $round), 'implement', '/tmp/m.json'))->not->toContain($review)->not->toContain($resolve);

    $both = pipeline_brief(brief_manifest('review-pr', ['mode' => 'autoflow', 'decisions' => [$red, $conflict]]), 'review-pr', '/tmp/m.json', 'review');
    expect($both)->toContain('`CI red on the PR\'s head commit` decision is a finding')->toContain($review);
    expect(strpos($both, '`CI red on the PR\'s head commit` decision is a finding'))->toBeLessThan(strpos($both, $review));
});

it('has both resolve steps give every open question a kind, blocking when unsure, in both modes (#146)', function (string $mode, string $leg) {
    $open = ['gate' => pipeline_gate_of($leg), 'leg' => $leg, 'cycle' => 1, 'at' => '2026-09-22T12:00:00Z', 'review' => 'r'];
    $brief = pipeline_brief(brief_manifest($leg, ['mode' => $mode, 'gate_ledger' => [$open]]), $leg, '/tmp/m.json', 'resolve');

    expect($brief)
        ->toContain('- Carry anything unresolved verbatim as an open question with its kind: `blocking` when the answer changes this PR\'s code, `follow-up` for work outside it, `remark` for a note on a choice already made; `blocking` when unsure. A `blocking` question names its options in `note`, the one the PR built first (shared/resolving.md).')
        ->not->toContain('- Carry anything unresolved verbatim as an open question.');
})->with(['autoflow', 'interactive'])->with(['review-plan', 'review-pr']);

it('has review-pr\'s finish step lead each PR body open question with its kind, and verify-ui file an unrenderable before state as a remark (#146)', function () {
    $open = ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => '2026-09-22T12:00:00Z', 'review' => 'r'];

    expect(pipeline_brief(brief_manifest('review-pr', ['mode' => 'autoflow', 'gate_ledger' => [$open]]), 'review-pr', '/tmp/m.json', 'resolve'))
        ->toContain('- Under the PR body\'s `## Open questions`, one line per open question led by its kind (`- **blocking:** …`), or `None.`; a question a settled `Answer to open question` decision answers is no longer open.');
    expect(pipeline_brief(brief_manifest('verify-ui', ['mode' => 'autoflow']), 'verify-ui', '/tmp/m.json', 'run'))
        ->toContain('- A before state the base cannot render is left out, and is an open question of kind `remark` in `openQuestions`, whose items are `{kind, question}` (shared/proof-payload.md).');
});

it('makes a recorded answer to an open question a finding of review-pr\'s review and resolve steps, and only then (#146)', function () {
    $open = ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 2, 'at' => '2026-09-22T12:00:00Z', 'review' => 'r'];
    $answer = 'Answer to open question gate_ledger[3].actions[0] ("Queue or cron?"): queue';
    $round = ['mode' => 'autoflow', 'decisions' => ['The engine never edits.', $answer]];
    $review = 'A settled `Answer to open question` decision whose answer departs from what the PR built is a finding of this review: name what it changes (shared/review-pr.md).';
    $resolve = 'Integrate each settled `Answer to open question` decision that departs from what the PR built, and name it in `actions`; the question it answers is no longer open (shared/review-pr.md).';

    expect(pipeline_brief(brief_manifest('review-pr', $round), 'review-pr', '/tmp/m.json', 'review'))->toContain($review)->not->toContain($resolve);
    expect(pipeline_brief(brief_manifest('review-pr', [...$round, 'gate_ledger' => [$open]]), 'review-pr', '/tmp/m.json', 'resolve'))->toContain($resolve)->not->toContain($review);
    expect(pipeline_brief(brief_manifest('review-pr', ['mode' => 'autoflow']), 'review-pr', '/tmp/m.json', 'review'))->not->toContain($review);
    expect(pipeline_brief(brief_manifest('review-pr', ['mode' => 'autoflow', 'gate_ledger' => [$open]]), 'review-pr', '/tmp/m.json', 'resolve'))->not->toContain($resolve);
    expect(pipeline_brief(brief_manifest('implement', $round), 'implement', '/tmp/m.json'))->not->toContain($review)->not->toContain($resolve);
});

it('tells every autoflow step where to work, that the run is authorised, and to return a structured result', function () {
    foreach (pipeline_legs() as $leg) {
        foreach (in_array($leg, ['review-plan', 'review-pr'], true) ? ['review', 'resolve'] : ['run'] as $step) {
            $autoflow = pipeline_brief(brief_manifest($leg, ['mode' => 'autoflow']), $leg, '/tmp/m.json', $step);
            $interactive = pipeline_brief(brief_manifest($leg, ['mode' => 'interactive']), $leg, '/tmp/m.json', $step);

            expect($autoflow)
                ->toContain('Run every command from `cd /tmp/wt`')
                ->toContain('The owner authorised this run, including pushing the branch and opening the draft PR; the pipeline never merges.')
                ->toContain('Return the `status` it printed as your structured `{status, reason}`.');
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
        ->toContain('A plan gap or a Bounded escalation found while resolving is a loop-back: return `looped-back` and name it in your actions')
        ->not->toContain('`plan-insufficient`');
    expect(pipeline_brief(brief_manifest('review-plan'), 'review-plan', '/tmp/m.json'))
        ->toContain('When you return `plan-insufficient`, write no review file: `record` adds no review entry.');
    expect(pipeline_brief(brief_manifest('implement'), 'implement', '/tmp/m.json'))
        ->toContain('run the escalation check first')
        ->not->toContain('write no review file');
});

it('runs format once per implement step, before the last suite run and the push, in both modes', function () {
    foreach (['autoflow', 'interactive'] as $mode) {
        expect(pipeline_brief(brief_manifest('implement', ['mode' => $mode]), 'implement', '/tmp/m.json'))
            ->toContain('Test-first; after each plan step the suite and `static-analysis`; `format` once, over the whole tree, when the code is complete: before the last suite run and the push, its changes committed, and again only after a later change (shared/checks.md, shared/suite.md). After every full run, record it: `php ' . realpath(__DIR__ . '/..') . '/dispatch_cli.php suite /tmp/m.json --outcome <green|red> --passed <n> --failed <n>`.')
            ->not->toContain('after each step the suite and the mechanical checks');
    }
});

it('tells every leg of a run on a base where its branch came from and where its PR goes', function (string $leg) {
    $brief = pipeline_brief(brief_manifest($leg, ['base' => 'feature/issue-2042-kleurenpaletten']), $leg, '/tmp/m.json');

    expect($brief)->toContain('- base: `feature/issue-2042-kleurenpaletten`: this branch was cut from `origin/feature/issue-2042-kleurenpaletten` and its PR goes into it, not into the default branch; diff with `git diff origin/feature/issue-2042-kleurenpaletten...HEAD`, and a merge into it closes no issue (session.md)');
})->with(['design', 'handoff', 'implement', 'review-pr']);

it('says nothing about a base on a run without one', function () {
    expect(pipeline_brief(brief_manifest('implement'), 'implement', '/tmp/m.json'))->not->toContain('- base:');
});

it('gives handoff no line about the base: its command opens the PR into it', function () {
    expect(pipeline_brief(brief_manifest('handoff', ['base' => 'feature/integration']), 'handoff', '/tmp/m.json'))
        ->toContain('- base: `feature/integration`')
        ->not->toContain('The PR must open into')
        ->not->toContain('gh pr edit <pr> --base');
});

it('tells a later step to report a plan gap only once it has found one, on either size', function (string $leg, string $step) {
    foreach (['autoflow', 'interactive'] as $mode) {
        expect(pipeline_brief(brief_manifest($leg, ['mode' => $mode]), $leg, '/tmp/m.json', $step))
            ->toContain('On a Bounded spec (its header says `**Design size:** Bounded`): run the escalation check first (shared/plan-falls-short.md), and only on escalation return `plan-insufficient` with `--reason` naming why the design must grow.')
            ->toContain('On an Architectural spec: only when the plan falls short of what this step needs (files or behaviour it does not name), return `plan-insufficient` with `--reason` naming what the plan lacks. The size alone is no gap: an Architectural plan needs no approval beyond `review-plan`\'s.')
            ->not->toContain('append a `plan-approval` entry');
    }
})->with([
    'handoff' => ['handoff', 'run'],
    'implement' => ['implement', 'run'],
    'verify-ui' => ['verify-ui', 'run'],
    'review-pr' => ['review-pr', 'review'],
]);

it('scopes review-pr\'s review step to what changed since the last completed review, naming its commit and not the review', function () {
    $dir = rereview_repo(['shared.php' => "a\nb\nc\nd\ne\nf\ng\n"]);
    $reviewed = rereview_commit($dir, ['shared.php' => "A\nb\nc\nd\ne\nf\ng\n"]);
    rereview_main_moves($dir, ['shared.php' => "a\nb\nc\nd\ne\nf\nG\n"]);
    rereview_merge($dir);
    rereview_commit($dir, ['fix.php' => "<?php\n"]);
    $manifest = brief_manifest('review-pr', ['mode' => 'autoflow', 'worktree' => $dir, 'gate_ledger' => [rereview_entry('continued', $reviewed)]]);

    expect(pipeline_brief($manifest, 'review-pr', '/tmp/m.json', 'review', fn (array $args) => pipeline_git_run($dir, $args)))
        ->toContain("Scoped re-review (steps/review-pr-review.md): a review of this PR completed at `{$reviewed}`, which HEAD contains, so your target is what changed since, not the whole PR:")
        ->toContain("the branch's own commits since (1), as patches, `git log -p --no-merges {$reviewed}..HEAD ^origin/main`, plus `git diff HEAD` (Stage 0 runs over both)")
        ->toContain("read whole at HEAD, the files where a merge since met this branch's changes: `shared.php`")
        ->toContain('Read beyond the target only where a finding needs it.')
        ->not->toContain('gate_ledger[0]')
        ->not->toContain('EARLIER REVIEW TEXT');
    expect(pipeline_brief($manifest, 'review-pr', '/tmp/m.json', 'review'))->not->toContain('Scoped re-review');
});

it('says when no merge met the branch, and when nothing was committed since the review', function () {
    $sha = str_repeat('a', 40);

    expect(pipeline_review_scope_line(['since' => $sha, 'base' => 'origin/main', 'commits' => 2, 'files' => []]))
        ->toContain("the branch's own commits since (2)")
        ->toContain("and no file more: no merge since met this branch's changes")
        ->toContain('what the settled decisions above ask of the PR stays in your target wherever it lies');
    expect(pipeline_review_scope_line(['since' => $sha, 'base' => 'origin/main', 'commits' => 0, 'files' => []]))
        ->toContain("nothing was committed on this branch since `{$sha}`: review only what the settled decisions above ask of the PR, and say so")
        ->not->toContain('git log');
});

it('asks git only on the steps that need it, and briefs the whole PR and no catch-up when git fails', function (string $leg, string $step, int $calls) {
    $asked = [];
    $git = function (array $args) use (&$asked) {
        $asked[] = $args;

        return [1, '', 'not a git repository'];
    };
    $manifest = brief_manifest($leg, ['mode' => 'autoflow', 'gate_ledger' => [rereview_entry('continued', str_repeat('a', 40))]]);

    expect(pipeline_brief($manifest, $leg, '/tmp/m.json', $step, $git))->not->toContain('Scoped re-review')->not->toContain('Catch up with the base');
    expect($asked)->toHaveCount($calls);
})->with([
    'review-pr review' => ['review-pr', 'review', 1],
    'review-pr resolve' => ['review-pr', 'resolve', 1],
    'review-plan review' => ['review-plan', 'review', 0],
    'implement' => ['implement', 'run', 1],
]);

/** A git that finds the branch 27 commits behind `origin/main`, with `$files` changed on both sides. */
function brief_git_behind(array $files = ['a.php', 'b.php'], string $behind = '27'): Closure
{
    return fn (array $args): array => match ($args[0]) {
        'symbolic-ref' => [0, 'origin/main', ''],
        'fetch' => [0, '', ''],
        'rev-list' => [0, $behind, ''],
        'diff' => [0, implode("\n", $files), ''],
        default => [1, '', 'unexpected'],
    };
}

it('puts the catch-up line first on every step that writes to the branch, with the merge command in its literal form', function (string $mode, string $leg, string $step) {
    $brief = pipeline_brief(brief_manifest($leg, ['mode' => $mode]), $leg, '/tmp/m.json', $step, brief_git_behind());

    expect($brief)->toContain("## Overrides\n\n- Catch up with the base first (shared/catch-up.md): `origin/main` is 27 commits ahead and changed files this branch changes too (`a.php`, `b.php`). Before any other work run `cd /tmp/wt && git merge --no-edit origin/main`, as its own command in exactly that form. On a conflict, resolve each file keeping both sides' intent, `cd /tmp/wt && git add <file>`, and conclude with `cd /tmp/wt && git commit --no-edit`. Only where both sides cannot be kept: `cd /tmp/wt && git merge --abort` and return `halted`, quoting the conflicting hunks. Never rebase, never force-push. A denied command is a halt naming it; do not reshape it. Record the merge as that file says.\n- ");
})->with([
    'design run' => ['interactive', 'design', 'run'],
    'design spec' => ['autoflow', 'design', 'spec'],
    'design plan' => ['autoflow', 'design', 'plan'],
    'review-plan resolve' => ['autoflow', 'review-plan', 'resolve'],
    'implement' => ['autoflow', 'implement', 'run'],
    'review-pr resolve' => ['interactive', 'review-pr', 'resolve'],
]);

it('gives no catch-up line to a step that does not write to the branch, or without git, or when the base state is null', function () {
    foreach ([['review-plan', 'review'], ['review-pr', 'review'], ['handoff', 'run'], ['verify-ui', 'run']] as [$leg, $step]) {
        expect(pipeline_brief(brief_manifest($leg, ['mode' => 'autoflow']), $leg, '/tmp/m.json', $step, brief_git_behind()))
            ->not->toContain('Catch up with the base');
    }
    expect(pipeline_brief(brief_manifest('implement'), 'implement', '/tmp/m.json', 'run'))->not->toContain('Catch up with the base');
    expect(pipeline_brief(brief_manifest('implement'), 'implement', '/tmp/m.json', 'run', brief_git_behind(['a.php'], '0')))->not->toContain('Catch up with the base');
});

it('words the design-only case and a single commit', function () {
    $manifest = ['worktree' => '/tmp/wt/'];

    expect(pipeline_catch_up_line($manifest, ['base' => 'origin/feature/integration', 'behind' => 1, 'shared' => []]))
        ->toStartWith('Catch up with the base first (shared/catch-up.md): `origin/feature/integration` is 1 commit ahead and this branch holds only its design. Before any other work run `cd /tmp/wt && git merge --no-edit origin/feature/integration`, as its own command in exactly that form.');
    expect(pipeline_brief(brief_manifest('design', ['mode' => 'autoflow']), 'design', '/tmp/m.json', 'spec', brief_git_behind(['docs/spec.md'])))
        ->toContain('is 27 commits ahead and this branch holds only its design.');
});

it('has handoff run its command and not the skill, in both modes', function (string $mode) {
    $command = 'cd ' . realpath(__DIR__ . '/..') . ' && php dispatch_cli.php handoff /tmp/m.json';

    expect(pipeline_brief(brief_manifest('handoff', ['mode' => $mode]), 'handoff', '/tmp/m.json', 'run'))
        ->toContain("- Run `{$command}` as its own command: it pushes the branch, opens the draft PR or adopts the one the branch has, and records this step. It is the whole step (steps/handoff.md).")
        ->toContain("the dispatcher's snapshot).\n\n- `{$command}` (records `continued`, or `halted` with its reason)\n")
        ->toContain('- The leg\'s name is not a skill to invoke: do not invoke the `handoff` skill (`/handoff`), which asks the owner a question and posts a prompt comment.')
        ->toContain('- Repair nothing it reports: no force-push, no `gh pr create` or `gh pr edit` by hand. A halt it recorded, a refusal, or a denied command is a halt with that reason.')
        ->not->toContain('handoff pr')
        ->not->toContain('--pr <number>');
})->with(['autoflow', 'interactive']);

it('prints no ruled command in the form whose allow rule needs a wildcard before the subcommand', function () {
    $briefs = array_map(
        fn (array $step) => pipeline_brief(brief_manifest($step[1], ['mode' => $step[0]]), $step[1], '/tmp/m.json', $step[2], brief_git_behind()),
        brief_steps(),
    );

    foreach ($briefs as $brief) {
        expect($brief)
            ->not->toContain('git -C /tmp/wt merge')
            ->not->toContain('git -C /tmp/wt add')
            ->not->toContain('git -C /tmp/wt commit')
            ->not->toContain('/dispatch_cli.php handoff');
    }
    expect(implode("\n", $briefs))
        ->toContain('`cd /tmp/wt && git merge --no-edit origin/main`')
        ->toContain('`cd ' . realpath(__DIR__ . '/..') . ' && php dispatch_cli.php handoff /tmp/m.json`');
});

/** @return list<array{0: string, 1: string, 2: string}> every step of both modes as `[mode, leg, step]` */
function brief_steps(): array
{
    $steps = [];
    foreach (['autoflow', 'interactive'] as $mode) {
        foreach (pipeline_legs() as $leg) {
            foreach (pipeline_steps($leg, $mode) as $step) {
                $steps[] = [$mode, $leg, $step];
            }
        }
    }

    return $steps;
}

it('ends every brief of both modes on the literal record command, one line per status the step may return', function () {
    $path = '/tmp/wt/.claude/pipeline/feature-x.json';
    $command = 'php ' . realpath(__DIR__ . '/..') . "/dispatch_cli.php record {$path}";

    foreach (brief_steps() as [$mode, $leg, $step]) {
        $return = explode("## Return\n\n", pipeline_brief(brief_manifest($leg, ['mode' => $mode]), $leg, $path, $step))[1];
        $own = PIPELINE_STEP_COMMANDS["{$leg}:{$step}"] ?? null;
        $statuses = array_column(LegStatus::allowedFor($leg, $step), 'value');

        expect($return)
            ->toContain($own === null
                ? "- `{$command} {$leg} {$step} --status continued"
                : '- `cd ' . realpath(__DIR__ . '/..') . " && php dispatch_cli.php {$own} {$path}` (records `continued`, or `halted` with its reason)")
            ->toContain('- `… --status halted --reason "<why>"`')
            ->toContain('`feature-x.before.json` beside it is the dispatcher\'s snapshot')
            ->toContain('never find it by a glob')
            ->not->toContain('Never move `cursor.leg`');
        expect(substr_count($return, "\n- `"))->toBe(count($statuses));
        foreach ($own === null ? $statuses : array_diff($statuses, ['continued']) as $status) {
            expect($return)->toContain("--status {$status}");
        }
    }
});

it('prints the return of a handoff step as its command, then record for the statuses it does not write', function () {
    $cli = 'php ' . realpath(__DIR__ . '/..') . '/dispatch_cli.php';
    $step = 'cd ' . realpath(__DIR__ . '/..') . ' && php dispatch_cli.php';

    expect(pipeline_brief_return('handoff', 'run', 'autoflow', '/tmp/m.json'))->toBe(
        "## Return\n\n"
        . "Your last act is the `handoff` command, or one `record` command for a status it does not write; only a read-only command your instructions name (`size`, `ui`) comes after it. They are the only way you write the manifest: do not edit the file, and never find it by a glob (`m.before.json` beside it is the dispatcher's snapshot).\n\n"
        . "- `{$step} handoff /tmp/m.json` (records `continued`, or `halted` with its reason)\n"
        . "- `{$cli} record /tmp/m.json handoff run --status plan-insufficient --reason \"<what the plan lacks>\"`\n"
        . "- `… --status halted --reason \"<why>\"`\n\n"
        . 'Write a `--reason` without double quotes. '
        . 'Each prints `{"action":"recorded",…}`, or `{"action":"refused","reason":…}` with exit 1 and the manifest untouched: a refused `record` names what to fix, then run it again; a refused `handoff` is a halt with its reason. '
        . 'A `record` run again in the same step replaces the earlier one, and its `replaced` then names what that one wrote (`last_sha`, `cursor.status`): that is expected. '
        . 'Return the `status` it printed as your structured `{status, reason}`. When it refuses a `halted`, return `halted` with its reason all the same.'
    );
    expect(pipeline_brief_return('handoff', 'run', 'interactive', '/tmp/m.json'))
        ->toEndWith('that is expected. Take the `status` it printed and reply with one line naming it.');
    expect(pipeline_brief_return('implement', 'run', 'autoflow', '/tmp/m.json'))
        ->toStartWith("## Return\n\nYour last act is one `record` command; only a read-only command")
        ->toContain('It is the only way you write the manifest')
        ->toContain('with exit 1 and the manifest untouched: fix what it names and run it again. ');
});

it('prints each step\'s flags from the record table: the two files by their path, an optional flag in brackets', function () {
    $commands = fn (string $leg, string $step) => pipeline_record_commands($leg, $step, '/tmp/m.json');

    expect($commands('review-plan', 'review')[0])->toEndWith('review-plan review --status continued --review-file /tmp/m.review.md');
    expect($commands('review-pr', 'resolve'))->toHaveCount(3);
    expect($commands('review-pr', 'resolve')[1])->toBe('… --status looped-back --actions-file /tmp/m.actions.json [--issue-link <issue>=<closes|stays-open|dropped-but-closes>]…');
    expect($commands('design', 'spec')[0])->toEndWith('design spec --status continued --spec <path> [--plan <path>]');
    expect($commands('implement', 'run')[0])->toEndWith('implement run --status continued');
    expect($commands('verify-ui', 'run')[1])->toBe('… --status looped-back [--proof <path>]');
});

it('says what each step passes to record, and describes no JSON', function () {
    $path = '/tmp/wt/.claude/pipeline/feature-x.json';
    $suite = 'php ' . realpath(__DIR__ . '/..') . "/dispatch_cli.php suite {$path} --outcome <green|red> --passed <n> --failed <n>";
    $brief = fn (string $mode, string $leg, string $step) => pipeline_brief(brief_manifest($leg, ['mode' => $mode]), $leg, $path, $step);

    foreach (brief_steps() as [$mode, $leg, $step]) {
        expect($brief($mode, $leg, $step))
            ->not->toContain('ledger entry with `gate`')
            ->not->toContain('Set `artifacts')
            ->not->toContain('set `artifacts.proof`')
            ->not->toContain('`cycle`')
            ->not->toContain('reviewed_sha')
            ->not->toContain('Complete the open entry');
    }
    expect($brief('autoflow', 'review-plan', 'review'))
        ->toContain('- Write its review verbatim to `/tmp/wt/.claude/pipeline/feature-x.review.md`; `record` appends it as the open `plan-approval` entry.')
        ->toContain('- Act on nothing. Read-only on the checkout: that file is the only one you write.');
    expect($brief('interactive', 'review-pr', 'review'))
        ->toContain('`record` appends it as the open `pr-review` entry, with the commit you reviewed.');
    expect($brief('autoflow', 'review-plan', 'resolve'))
        ->toContain('- Write what you did with each point to `/tmp/wt/.claude/pipeline/feature-x.actions.json` as a JSON list of `{claim, disposition, note}`, `disposition` one of `integrated`, `recorded`, `open-question`, plus `kind` on an `open-question`, one of `blocking`, `follow-up`, `remark` (shared/resolving.md), and `[]` when you acted on nothing; `record` completes the open entry from it.');
    expect($brief('autoflow', 'review-pr', 'resolve'))
        ->toContain("- Run the suite unless this tree is already green (shared/suite.md), and record the run: `{$suite}`.")
        ->toContain('- Reconcile the closing links (steps/finish.md): each related issue\'s outcome goes to `record` as an `--issue-link`.');
    expect($brief('autoflow', 'handoff', 'run'))
        ->toContain('- Run `cd ' . realpath(__DIR__ . '/..') . " && php dispatch_cli.php handoff {$path}` as its own command:");
    expect($brief('autoflow', 'verify-ui', 'run'))
        ->toContain('- Write the proof page (shared/proof-payload.md): `clientSummary` and `explainer` (a first version), and a `state` on every shot; before shots only when the spec names a before state to show, captured on the base, each immediately followed in `shots` by its after shot; `git switch <branch>` before any after shot and before returning, whatever the status, and `git rev-parse --abbrev-ref HEAD` names the branch before the page is written; a defect found is shot as `defect`, and a later pass carries the earlier defect shots forward beside its own. `repo`, `branch` and `pr` are the ones in the `run.json` beside `artifacts.proof`.')
        ->toContain('- Post the text-only record comment; the path `write` printed goes to `record` as `--proof`.')
        ->toContain('- Return `continued`, or `looped-back` when the check fails.');
    expect($brief('autoflow', 'review-pr', 'resolve'))
        ->toContain('- Write the proof page (shared/proof-payload.md): `clientSummary` and `explainer` as the finished work stands, the suite line under `checks`, the final open questions, each `{kind, question}`, and the ledger; `repo`, `branch` and `pr` from the `run.json` beside `artifacts.proof`, and a run without `artifacts.proof` gets its page from this write, with `repo` (the GitHub name), `branch` and `pr` from the PR.')
        ->not->toContain('proof_cli.php open')
        ->not->toContain('When `artifacts.proof` is set');
});

it('points implement at steps/implement.md in both modes', function (string $mode) {
    $brief = pipeline_brief(brief_manifest('implement', ['mode' => $mode]), 'implement', '/tmp/m.json');

    expect($brief)->toContain('Do this step as your step\'s reference describes (steps/implement.md), in this worktree: it is the whole procedure, and it claims no slot.');
    if ($mode === 'interactive') {
        expect($brief)->toContain('Add the `ci` label (`gh pr edit <pr> --add-label ci`) before the push whose CI you watch.');
    }
})->with(['autoflow', 'interactive']);

it('names work-on in no brief', function (string $mode) {
    $lines = array_merge(
        ...array_values(pipeline_leg_overrides($mode, '/tmp/m.json')),
        ...[pipeline_plan_gap_lines('run'), pipeline_plan_gap_lines('review'), pipeline_plan_gap_lines('resolve')],
    );

    $brief = pipeline_brief(brief_manifest('implement', ['mode' => $mode]), 'implement', '/tmp/m.json');

    expect(str_replace(realpath(__DIR__ . '/..'), '<checks>', implode("\n", $lines)))->not->toContain('work-on')
        ->and(str_replace(realpath(__DIR__ . '/..'), '<checks>', $brief))->not->toContain('work-on');
})->with(['autoflow', 'interactive']);

it('names no open in any step\'s return, in either mode', function () {
    foreach (['autoflow', 'interactive'] as $mode) {
        foreach (pipeline_legs() as $leg) {
            foreach (pipeline_steps($leg, $mode) as $step) {
                expect(pipeline_brief_return($leg, $step, $mode, '/tmp/m.json'))->not->toContain('`open`');
            }
        }
    }
});

it('maps every step of both modes to its reference file', function () {
    expect(pipeline_step_reference('design', 'run'))->toBe('steps/design.md');
    expect(pipeline_step_reference('design', 'spec'))->toBe('steps/design.md');
    expect(pipeline_step_reference('design', 'plan'))->toBe('steps/design.md');
    expect(pipeline_step_reference('review-plan', 'review'))->toBe('steps/review-plan-review.md');
    expect(pipeline_step_reference('review-plan', 'resolve'))->toBe('steps/review-plan-resolve.md');
    expect(pipeline_step_reference('handoff', 'run'))->toBe('steps/handoff.md');
    expect(pipeline_step_reference('implement', 'run'))->toBe('steps/implement.md');
    expect(pipeline_step_reference('verify-ui', 'run'))->toBe('steps/verify-ui.md');
    expect(pipeline_step_reference('review-pr', 'review'))->toBe('steps/review-pr-review.md');
    expect(pipeline_step_reference('review-pr', 'resolve'))->toBe('steps/finish.md');
});

it('names its step\'s reference, a file that exists, in every brief of both modes', function (string $mode) {
    $references = realpath(__DIR__ . '/../../references');
    foreach (pipeline_legs() as $leg) {
        foreach (pipeline_steps($leg, $mode) as $step) {
            $file = pipeline_step_reference($leg, $step);
            $manifest = brief_manifest($leg, ['mode' => $mode]);

            expect("{$references}/{$file}")->toBeFile();
            expect(pipeline_brief_role($manifest, $leg, $step))
                ->toContain("The references this brief names are in `{$references}/`; read your step's, `{$file}`, first, and the `shared/` files its `Read also` line names.")
                ->not->toContain('engine.md');
            expect(pipeline_brief_pointers($manifest, '/tmp/m.json', $leg, $step))
                ->toContain("- your step's reference: `{$references}/{$file}`");
        }
    }
})->with(['autoflow', 'interactive']);
