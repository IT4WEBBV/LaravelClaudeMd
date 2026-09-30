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

it('completes the open entry before the finish step\'s last action', function () {
    $open = ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r'];
    $brief = pipeline_brief(brief_manifest('review-pr', ['gate_ledger' => [$open]]), 'review-pr', '/tmp/m.json');

    expect(strpos($brief, 'Complete the open entry'))->toBeLessThan(strpos($brief, 'The last action is `proof_cli.php open`'));
});

it('has overrides for every leg and step, in autoflow and interactive', function () {
    foreach (['autoflow', 'interactive'] as $mode) {
        foreach (pipeline_legs() as $leg) {
            foreach (pipeline_steps($leg, $mode) as $step) {
                expect(pipeline_leg_overrides($mode))->toHaveKey("{$leg}:{$step}");
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
        ->toContain('Leave the PR draft; this overrides any mark-ready instruction')
        ->toContain('`plan-insufficient`')
        ->toContain('Never move `cursor.leg`');
});

it('permits the design size the invocation allowed, naming the word', function (array $extra, string $line) {
    expect(pipeline_brief(brief_manifest('design', $extra), 'design', '/tmp/wt/.claude/pipeline/feature-x.json'))->toContain($line);
})->with([
    'no word' => [[], '- design size: the Architectural path is required (no `medium` or `light`)'],
    'medium' => [['tier' => 'medium'], '- design size: the Bounded path is permitted (`medium`)'],
    'light' => [['tier' => 'light'], '- design size: the Bounded path is permitted (`light`)'],
    'a legacy light: true' => [['light' => true], '- design size: the Bounded path is permitted (`medium`)'],
]);

it('tells design to confirm by reading, probe only to choose, and leave the Expected lines to implement', function () {
    foreach ([['autoflow', 'spec'], ['interactive', 'run']] as [$mode, $step]) {
        expect(pipeline_brief(brief_manifest('design', ['mode' => $mode]), 'design', '/tmp/m.json', $step))
            ->toContain('Do not build or run the plan\'s code, in a scratch copy or anywhere else: confirm the signatures and APIs it relies on by reading, `php -l` or grep; `implement` proves the plan\'s Expected lines (engine.md §What design proves).')
            ->toContain('The one exception: when the choice between approaches hinges on whether one of them works at all, answer that question with a throwaway probe (a few lines run on their own, never the plan\'s code, never the suite) and write the question and what the probe showed into the spec.')
            ->toContain('Plans and specs committed before 2026-09-14 are not exemplars for test or proof policy, and no plan\'s `Verified before writing` header is part of the format.')
            ->not->toContain('for test or proof policy.');
    }
});

it('splits autoflow\'s design into a spec step that stops at the spec and a plan step that reads it cold', function () {
    $manifest = brief_manifest('design', ['mode' => 'autoflow']);
    $spec = pipeline_brief($manifest, 'design', '/tmp/m.json', 'spec');
    $plan = pipeline_brief($manifest, 'design', '/tmp/m.json', 'plan');

    expect($spec)
        ->toContain('`design` leg, `spec` step')
        ->toContain('- Invoke `superpowers:brainstorming` and stop at the spec: on the Architectural path, where brainstorming hands over to `superpowers:writing-plans`, the plan is the next step\'s, `design:plan`, so do not invoke `writing-plans` and commit no plan (engine.md §Design size).')
        ->toContain('write each question and the answer you assumed into the spec\'s `## Assumptions` section')
        ->toContain('- Commit the spec; on the Bounded path, commit the plan as well, a second commit, at `docs/superpowers/plans/<date>-<slug>.md` beside the spec `docs/superpowers/specs/<date>-<slug>-design.md` (`pipeline_plan_path()`): a Bounded design has no plan step, and a grown design\'s plan step extends the plan it finds there. Then, after your last commit and in one manifest write, set `artifacts.spec` and remove `artifacts.plan` (the plan step writes this spec\'s plan and sets it), or on the Bounded path set `artifacts.plan` to the plan: a halt before that write leaves the manifest calling for this step again.')
        ->not->toContain('Commit the spec, then the plan: two commits.')
        ->not->toContain('The plan goes at');
    expect($plan)
        ->toContain('`design` leg, `plan` step')
        ->toContain('- Read the committed spec (`artifacts.spec`) cold, and the code it points at, and invoke `superpowers:writing-plans` on it; do not re-design what the spec settles (engine.md §Design size).')
        ->toContain('- Where the plan needs an answer the spec does not give, add the question and the answer you assumed to the spec\'s `## Assumptions` and commit that before the plan, so `/critique plan` audits it.')
        ->toContain('Do not build or run the plan\'s code')
        ->toContain('Plans and specs committed before 2026-09-14 are not exemplars')
        ->toContain('- Commit the plan. Set `artifacts.plan`.')
        ->not->toContain('throwaway probe')
        ->not->toContain('Invoke `superpowers:brainstorming`');
    expect(pipeline_brief(brief_manifest('design'), 'design', '/tmp/m.json', 'run'))
        ->toContain('- Commit the spec, then the plan: two commits. Set `artifacts.spec` and `artifacts.plan`.');
});

it('asks for grow form only after an escalation no plan approval has answered, split over autoflow\'s two design steps', function () {
    $escalated = ['gate' => 'design-size', 'leg' => 'implement', 'at' => '2026-09-22T12:00:00Z', 'reason' => 'migration', 'outcome' => 'escalated'];
    $approved = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'at' => '2026-09-22T13:00:00Z', 'review' => 'ok', 'outcome' => 'continued'];
    $grown = fn (string $mode, string $step, array $ledger) => pipeline_brief(brief_manifest('design', ['mode' => $mode, 'gate_ledger' => $ledger]), 'design', '/tmp/wt/.claude/pipeline/feature-x.json', $step);

    expect($grown('interactive', 'run', [$escalated]))->toContain('- Grow form: the design escalated from Bounded (engine.md §Design size). Grow the spec and the plan; do not re-design them.');
    expect($grown('interactive', 'run', [$escalated, $approved]))->not->toContain('Grow form');
    expect($grown('autoflow', 'spec', [$escalated]))->toContain('- Grow form: the design escalated from Bounded (engine.md §Design size). Grow the spec: its header says `**Design size:** Architectural` and a `## Grown from Bounded` section says what changed, why it grew, what already exists and what remains; do not re-design it. The plan step adds the remaining steps to the plan.');
    expect($grown('autoflow', 'plan', [$escalated]))
        ->toContain('- Grow form: the design escalated from Bounded (engine.md §Design size). Add the remaining steps to the plan, as the spec\'s `## Grown from Bounded` section names them; do not re-design it.')
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
        ->toContain('Add the `ci` label (`gh pr edit <pr> --add-label ci`) before your first push, in a repo that has one, and do not wait on CI after it: this overrides `work-on`\'s CI watch; the CI gate reads the PR\'s head commit before the PR goes ready (engine.md §The CI gate).')
        ->not->toContain('the push whose CI you watch');
    expect(pipeline_brief(brief_manifest('implement'), 'implement', '/tmp/m.json'))
        ->toContain('Add the `ci` label (`gh pr edit <pr> --add-label ci`) before the push whose CI you watch.');
});

it('leaves the PR draft at the autoflow finish step for the session that launched the run', function () {
    $open = ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r'];
    $brief = pipeline_brief(brief_manifest('review-pr', ['mode' => 'autoflow', 'gate_ledger' => [$open]]), 'review-pr', '/tmp/m.json');

    expect($brief)
        ->toContain('Push your commits and leave the PR draft; the session that launched the run marks it ready after the CI gate (engine.md §The CI gate).')
        ->toContain('On a loop-back, stop there: no suite.')
        ->not->toContain('gh pr ready');
    expect(strpos($brief, 'Complete the open entry'))->toBeLessThan(strpos($brief, 'The last action is `proof_cli.php open`'));
});

it('has the interactive finish step run the CI gate before gh pr ready', function () {
    $open = ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r'];

    expect(pipeline_brief(brief_manifest('review-pr', ['gate_ledger' => [$open]]), 'review-pr', '/tmp/m.json'))
        ->toContain('Run the CI gate (engine.md §The CI gate) and `gh pr ready` when it answers `ready`; show any other answer to the human. The last action is `proof_cli.php open`');
});

it('makes a recorded red CI a finding of review-pr\'s review and resolve steps, and only then', function () {
    $open = ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 2, 'at' => '2026-09-22T12:00:00Z', 'review' => 'r'];
    $round = ['mode' => 'autoflow', 'decisions' => ['The engine never edits.', "CI red on the PR's head commit abc123: CI / ci failed (https://github.com/acme/app/actions/runs/11/job/12)"]];
    $review = 'The settled `CI red on the PR\'s head commit` decision is a finding of this review: read the failing job\'s log (`gh run view <run> --log-failed`, the run id from its link) and state the failure and its cause (engine.md §The CI gate).';
    $resolve = 'Fix the `CI red` finding, or show it is unrelated to this change (the same failure on the base branch, or a flake: start `gh run rerun <run> --failed` and do not wait on it), and say which in `actions`; the CI gate reads the head commit again (engine.md §The CI gate).';

    expect(pipeline_brief(brief_manifest('review-pr', $round), 'review-pr', '/tmp/m.json', 'review'))->toContain($review)->not->toContain($resolve);
    expect(pipeline_brief(brief_manifest('review-pr', [...$round, 'gate_ledger' => [$open]]), 'review-pr', '/tmp/m.json', 'resolve'))->toContain($resolve)->not->toContain($review);
    expect(pipeline_brief(brief_manifest('review-pr', ['mode' => 'autoflow']), 'review-pr', '/tmp/m.json', 'review'))->not->toContain('finding of this review: read the failing job');
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

it('runs format once per implement step, before the last suite run and the push, in both modes', function () {
    foreach (['autoflow', 'interactive'] as $mode) {
        expect(pipeline_brief(brief_manifest('implement', ['mode' => $mode]), 'implement', '/tmp/m.json'))
            ->toContain('Test-first; after each plan step the suite and `static-analysis`; `format` once, over the whole tree, when the code is complete: before the last suite run and the push, its changes committed, and again only after a later change (engine.md §Mechanical checks, §Suite reuse). Record `suite` after every full run.')
            ->not->toContain('after each step the suite and the mechanical checks');
    }
});

it('tells every leg of a run on a base where its branch came from and where its PR goes', function (string $leg) {
    $brief = pipeline_brief(brief_manifest($leg, ['base' => 'feature/issue-2042-kleurenpaletten']), $leg, '/tmp/m.json');

    expect($brief)->toContain('- base: `feature/issue-2042-kleurenpaletten`: this branch was cut from `origin/feature/issue-2042-kleurenpaletten` and its PR goes into it, not into the default branch; diff with `git diff origin/feature/issue-2042-kleurenpaletten...HEAD`, and a merge into it closes no issue (engine.md §Kickoff)');
})->with(['design', 'handoff', 'implement', 'review-pr']);

it('says nothing about a base on a run without one', function () {
    expect(pipeline_brief(brief_manifest('implement'), 'implement', '/tmp/m.json'))->not->toContain('- base:');
});

it('makes handoff on a run on a base check that the PR opened into it', function () {
    expect(pipeline_brief(brief_manifest('handoff', ['base' => 'feature/integration']), 'handoff', '/tmp/m.json'))
        ->toContain('- The PR must open into `feature/integration`: after `handoff pr`, `gh pr view <pr> --json baseRefName --jq .baseRefName` prints `feature/integration`; otherwise `gh pr edit <pr> --base feature/integration` before setting `artifacts.pr` (engine.md §Kickoff).');
    expect(pipeline_brief(brief_manifest('handoff'), 'handoff', '/tmp/m.json'))->not->toContain('The PR must open into');
});

it('tells a later step to report a plan gap only once it has found one, on either size', function (string $leg, string $step) {
    foreach (['autoflow', 'interactive'] as $mode) {
        expect(pipeline_brief(brief_manifest($leg, ['mode' => $mode]), $leg, '/tmp/m.json', $step))
            ->toContain('On a Bounded spec (its header says `**Design size:** Bounded`): run the escalation check first (engine.md §Design size), and only on escalation append the `design-size` entry and return `plan-insufficient`.')
            ->toContain('On an Architectural spec: only when the plan falls short of what this step needs (files or behaviour it does not name), append a `plan-approval` entry with `leg`, `cycle`, `at`, a `reason` naming what the plan lacks, and outcome `looped-back`, then return `plan-insufficient`. The size alone is no gap: an Architectural plan needs no approval beyond `review-plan`\'s.')
            ->not->toContain('On an Architectural spec, append a `plan-approval` entry');
    }
})->with([
    'handoff' => ['handoff', 'run'],
    'implement' => ['implement', 'run'],
    'verify-ui' => ['verify-ui', 'run'],
    'review-pr' => ['review-pr', 'review'],
]);

it('has the review-pr review step record the commit it reviewed, and the review-plan one not', function (string $mode) {
    expect(pipeline_brief(brief_manifest('review-pr', ['mode' => $mode]), 'review-pr', '/tmp/m.json', 'review'))
        ->toContain('`review`, `annotations` and `reviewed_sha` (the output of `git rev-parse HEAD` in the worktree: the commit you reviewed), and no `outcome`');
    expect(pipeline_brief(brief_manifest('review-plan', ['mode' => $mode]), 'review-plan', '/tmp/m.json', 'review'))
        ->not->toContain('reviewed_sha');
})->with(['autoflow', 'interactive']);

it('scopes review-pr\'s review step to what changed since the last completed review, naming its commit and not the review', function () {
    $dir = rereview_repo(['shared.php' => "a\nb\nc\nd\ne\nf\ng\n"]);
    $reviewed = rereview_commit($dir, ['shared.php' => "A\nb\nc\nd\ne\nf\ng\n"]);
    rereview_main_moves($dir, ['shared.php' => "a\nb\nc\nd\ne\nf\nG\n"]);
    rereview_merge($dir);
    rereview_commit($dir, ['fix.php' => "<?php\n"]);
    $manifest = brief_manifest('review-pr', ['mode' => 'autoflow', 'worktree' => $dir, 'gate_ledger' => [rereview_entry('continued', $reviewed)]]);

    expect(pipeline_brief($manifest, 'review-pr', '/tmp/m.json', 'review', fn (array $args) => pipeline_git_run($dir, $args)))
        ->toContain("Scoped re-review (engine.md §Scoped re-review): a review of this PR completed at `{$reviewed}`, which HEAD contains, so your target is what changed since, not the whole PR:")
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

it('asks git only on review-pr\'s review step, and briefs the whole PR when git cannot scope it', function (string $leg, string $step, int $calls) {
    $asked = [];
    $git = function (array $args) use (&$asked) {
        $asked[] = $args;

        return [1, '', 'not a git repository'];
    };
    $manifest = brief_manifest($leg, ['mode' => 'autoflow', 'gate_ledger' => [rereview_entry('continued', str_repeat('a', 40))]]);

    expect(pipeline_brief($manifest, $leg, '/tmp/m.json', $step, $git))->not->toContain('Scoped re-review');
    expect($asked)->toHaveCount($calls);
})->with([
    'review-pr review' => ['review-pr', 'review', 1],
    'review-pr resolve' => ['review-pr', 'resolve', 0],
    'review-plan review' => ['review-plan', 'review', 0],
    'implement' => ['implement', 'run', 0],
]);
