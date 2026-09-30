<?php

const RECORD_HEAD = 'cccccccccccccccccccccccccccccccccccccccc';

function record_before(string $leg, array $extra = []): array
{
    return [
        'branch' => 'feature/x', 'worktree' => '/tmp/wt', 'mode' => 'autoflow',
        'cursor' => ['leg' => $leg, 'status' => 'pending'],
        'artifacts' => ['spec' => 'docs/spec.md', 'plan' => 'docs/plan.md', 'pr' => null, 'issue' => 52],
        'last_sha' => 'aaa1111', 'gate_ledger' => [],
        ...$extra,
    ];
}

function record_facts(array $extra = []): array
{
    return [
        'head' => RECORD_HEAD, 'now' => '2026-09-30T12:00:00Z', 'annotations' => ['migration'],
        'size' => DesignSize::Architectural, 'committed' => ['docs/spec.md', 'docs/plan.md'],
        ...$extra,
    ];
}

/** Valid flags for a step and status, as `dispatch_cli.php record` hands them over: the two files already read. */
function record_given(string $leg, string $step, string $status, array $extra = []): array
{
    $values = [
        'spec' => 'docs/spec.md', 'plan' => 'docs/plan.md', 'pr' => '7', 'proof' => '/proofs/pr-7/index.html',
        'review-file' => "Step 4 drops the link.\n",
        'actions-file' => '[{"claim":"step 4 drops the link","disposition":"integrated","note":"restored it"}]',
        'reason' => 'needs a queue',
    ];
    $row = pipeline_record_table()["{$leg}:{$step}"][$status];

    return ['status' => $status, ...array_intersect_key($values, array_flip($row['required'])), ...$extra];
}

function record_open(string $gate): array
{
    return [
        'gate' => $gate, 'leg' => pipeline_leg_of_gate($gate), 'cycle' => 1, 'at' => '2026-09-30T10:00:00Z',
        'review' => 'r', 'annotations' => [],
        ...($gate === 'pr-review' ? ['reviewed_sha' => str_repeat('a', 40)] : []),
    ];
}

/** @return array<string, array{0: string, 1: string, 2: string}> every `<leg>:<step>` of both modes, with a mode that has it */
function record_steps(): array
{
    $steps = [];
    foreach (['autoflow', 'interactive'] as $mode) {
        foreach (pipeline_legs() as $leg) {
            foreach (pipeline_steps($leg, $mode) as $step) {
                $steps["{$leg}:{$step}"] = [$leg, $step, $mode];
            }
        }
    }

    return $steps;
}

/** A snapshot for the step: a resolve step finds its open review. */
function record_snapshot(string $leg, string $step, string $mode = 'autoflow'): array
{
    return record_before($leg, ['mode' => $mode, 'gate_ledger' => $step === 'resolve' ? [record_open(pipeline_gate_of($leg))] : []]);
}

it('has a row for exactly the steps of both modes, and in each the statuses that step may return', function () {
    $steps = record_steps();

    expect(array_keys(pipeline_record_table()))->toEqualCanonicalizing(array_keys($steps));
    foreach ($steps as $key => [$leg, $step]) {
        expect(array_keys(pipeline_record_table()[$key]))
            ->toEqualCanonicalizing(array_map(fn (LegStatus $status) => $status->value, LegStatus::allowedFor($leg, $step)));
    }
});

it('lists only flags record knows, and asks every halt for a reason and nothing else', function () {
    foreach (pipeline_record_table() as $rows) {
        foreach ($rows as $row) {
            expect(array_diff([...$row['required'], ...$row['optional']], PIPELINE_RECORD_FLAGS))->toBe([]);
        }
        expect($rows['halted'])->toBe(['required' => ['reason'], 'optional' => []]);
    }
});

it('builds, for every step and every status it may return, a manifest the boundary check accepts', function () {
    foreach (record_steps() as [$leg, $step, $mode]) {
        foreach (LegStatus::allowedFor($leg, $step) as $status) {
            $before = record_snapshot($leg, $step, $mode);
            $candidate = pipeline_record($before, $before, $leg, $step, record_given($leg, $step, $status->value), record_facts());

            expect($candidate)->toBeArray("{$leg}:{$step} {$status->value} was refused: " . json_encode($candidate));
            expect(pipeline_return_problem(pipeline_normalized($before), pipeline_normalized($candidate), $leg, $step, DesignSize::Architectural))
                ->toBeNull("{$leg}:{$step} {$status->value}");
            expect($candidate['cursor']['status'])->toBe($status->value);
        }
    }
});

it('stamps last_sha on every status but a halt, and gives only a halt a cursor reason', function () {
    $before = record_snapshot('implement', 'run');
    $record = fn (string $status, array $facts = []) => pipeline_record($before, $before, 'implement', 'run', record_given('implement', 'run', $status), record_facts($facts));

    expect($record('continued'))->toMatchArray(['last_sha' => RECORD_HEAD, 'cursor' => ['leg' => 'implement', 'status' => 'continued']]);
    expect($record('plan-insufficient'))->toMatchArray(['last_sha' => RECORD_HEAD, 'cursor' => ['leg' => 'implement', 'status' => 'plan-insufficient']]);
    expect($record('halted', ['head' => null]))->toMatchArray(['last_sha' => 'aaa1111', 'cursor' => ['leg' => 'implement', 'status' => 'halted', 'reason' => 'needs a queue']]);
    expect($record('continued', ['head' => null]))->toBe('record needs the worktree\'s HEAD for last_sha, and `git rev-parse HEAD` failed');
});

it('appends the open review entry, with reviewed_sha on pr-review only', function () {
    $plan = pipeline_record(record_before('review-plan'), record_before('review-plan'), 'review-plan', 'review', record_given('review-plan', 'review', 'continued'), record_facts());
    $pr = pipeline_record(record_before('review-pr'), record_before('review-pr'), 'review-pr', 'review', record_given('review-pr', 'review', 'continued'), record_facts());

    expect($plan['gate_ledger'])->toBe([[
        'gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-30T12:00:00Z',
        'review' => 'Step 4 drops the link.', 'annotations' => ['migration'],
    ]]);
    expect($pr['gate_ledger'])->toBe([[
        'gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => '2026-09-30T12:00:00Z',
        'review' => 'Step 4 drops the link.', 'annotations' => ['migration'], 'reviewed_sha' => RECORD_HEAD,
    ]]);
    expect(pipeline_record_entry(record_before('review-pr'), $pr, 'review-pr', 'review'))->toBe(0);
});

it('counts the cycle per gate, and keeps an unknown count unknown', function () {
    $looped = [...record_open('plan-approval'), 'actions' => [], 'outcome' => 'looped-back'];
    $second = pipeline_record(record_before('review-plan', ['gate_ledger' => [$looped]]), [], 'review-plan', 'review', record_given('review-plan', 'review', 'continued'), record_facts());
    $unknown = pipeline_record(record_before('review-plan', ['gate_ledger' => [[...$looped, 'cycle' => 'unknown']]]), [], 'review-plan', 'review', record_given('review-plan', 'review', 'continued'), record_facts());

    expect($second['gate_ledger'][1]['cycle'])->toBe(2);
    expect($unknown['gate_ledger'][1]['cycle'])->toBe('unknown');
});

it('refuses a review step whose review is empty or whose diff could not be read', function () {
    $before = record_before('review-plan');
    $given = record_given('review-plan', 'review', 'continued');

    expect(pipeline_record($before, $before, 'review-plan', 'review', [...$given, 'review-file' => " \n\n"], record_facts()))
        ->toBe('the review file is empty');
    expect(pipeline_record($before, $before, 'review-plan', 'review', $given, record_facts(['annotations' => null])))
        ->toBe('record needs the branch\'s diff for the entry\'s annotations, and `git diff <base>...HEAD` failed');
});

it('completes the open entry with actions and outcome, and keeps its seven kept keys', function () {
    $before = record_snapshot('review-pr', 'resolve');
    $given = record_given('review-pr', 'resolve', 'looped-back', ['issue-link' => ['52=closes', '122=stays-open']]);
    $candidate = pipeline_record($before, $before, 'review-pr', 'resolve', $given, record_facts());

    expect($candidate['gate_ledger'])->toBe([[
        ...record_open('pr-review'),
        'actions' => [['claim' => 'step 4 drops the link', 'disposition' => 'integrated', 'note' => 'restored it']],
        'issue_links' => [['issue' => 52, 'outcome' => 'closes'], ['issue' => 122, 'outcome' => 'stays-open']],
        'outcome' => 'looped-back',
    ]]);
    expect(pipeline_record_entry($before, $candidate, 'review-pr', 'resolve'))->toBe(0);

    $plain = pipeline_record($before, $before, 'review-pr', 'resolve', record_given('review-pr', 'resolve', 'continued', ['actions-file' => '[]']), record_facts());
    expect($plain['gate_ledger'][0])->toBe([...record_open('pr-review'), 'actions' => [], 'outcome' => 'continued']);
});

it('refuses an actions file that is not a list of {claim, disposition, note}, naming the item and the key', function (string $json, string $reason) {
    $before = record_snapshot('review-plan', 'resolve');

    expect(pipeline_record($before, $before, 'review-plan', 'resolve', record_given('review-plan', 'resolve', 'continued', ['actions-file' => $json]), record_facts()))
        ->toBe($reason);
})->with([
    'not JSON' => ['claim: x', 'the actions file is not a JSON list of {claim, disposition, note}'],
    'one object, not a list' => ['{"claim":"x","disposition":"integrated","note":"n"}', 'the actions file is not a JSON list of {claim, disposition, note}'],
    'an item that is no object' => ['["x"]', 'actions[0] is not an object'],
    'a missing key' => ['[{"claim":"x","disposition":"integrated"}]', 'actions[0] has no `note`'],
    'an unknown key' => ['[{"claim":"x","disposition":"integrated","note":"n","sha":"abc"}]', 'actions[0] has an unknown key `sha`'],
    'an empty claim' => ['[{"claim":" ","disposition":"integrated","note":"n"}]', 'actions[0]: `claim` is not a non-empty string'],
    'an unknown disposition' => ['[{"claim":"a","disposition":"integrated","note":"n"},{"claim":"x","disposition":"done","note":"n"}]', 'actions[1]: `disposition` is not one of integrated, recorded, open-question'],
    'a note that is no string' => ['[{"claim":"x","disposition":"recorded","note":null}]', 'actions[0]: `note` is not a string'],
]);

it('refuses an issue link that is not <number>=<outcome>', function (string $link) {
    $before = record_snapshot('review-pr', 'resolve');

    expect(pipeline_record($before, $before, 'review-pr', 'resolve', record_given('review-pr', 'resolve', 'continued', ['issue-link' => [$link]]), record_facts()))
        ->toBe("--issue-link {$link} is not <issue number>=<outcome>, the outcome one of closes, stays-open, dropped-but-closes");
})->with(['52', '#52=closes', '52=closed', '=closes']);

it('sets the PR as an integer, and refuses anything but digits', function () {
    $before = record_before('handoff');

    expect(pipeline_record($before, $before, 'handoff', 'run', record_given('handoff', 'run', 'continued'), record_facts())['artifacts']['pr'])->toBe(7);
    expect(pipeline_record($before, $before, 'handoff', 'run', ['status' => 'continued', 'pr' => '#7'], record_facts()))->toBe('--pr #7 is not a PR number');
});

it('adds the thin verify-ui entry with the status as its outcome, and the proof when given', function () {
    $before = record_before('verify-ui');
    $continued = pipeline_record($before, $before, 'verify-ui', 'run', record_given('verify-ui', 'run', 'continued'), record_facts());
    $looped = pipeline_record($before, $before, 'verify-ui', 'run', record_given('verify-ui', 'run', 'looped-back'), record_facts());

    expect($continued['gate_ledger'])->toBe([['gate' => 'verify-ui', 'cycle' => 1, 'at' => '2026-09-30T12:00:00Z', 'outcome' => 'continued']]);
    expect($continued['artifacts']['proof'])->toBe('/proofs/pr-7/index.html');
    expect($looped['gate_ledger'])->toBe([['gate' => 'verify-ui', 'cycle' => 1, 'at' => '2026-09-30T12:00:00Z', 'outcome' => 'looped-back']]);
    expect($looped['artifacts'])->not->toHaveKey('proof');
});

it('answers plan-insufficient with the entry the design size calls for, and no review entry', function () {
    $before = record_before('review-plan');
    $given = record_given('review-plan', 'review', 'plan-insufficient');
    $gap = pipeline_record($before, $before, 'review-plan', 'review', $given, record_facts());
    $escalation = pipeline_record($before, $before, 'review-plan', 'review', $given, record_facts(['size' => DesignSize::Bounded]));

    expect($gap['gate_ledger'])->toBe([['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-30T12:00:00Z', 'reason' => 'needs a queue', 'outcome' => 'looped-back']]);
    expect($escalation['gate_ledger'])->toBe([['gate' => 'design-size', 'leg' => 'review-plan', 'at' => '2026-09-30T12:00:00Z', 'reason' => 'needs a queue', 'outcome' => 'escalated']]);
    expect($gap['cursor'])->toBe(['leg' => 'review-plan', 'status' => 'plan-insufficient']);
    expect(pipeline_return_problem(pipeline_normalized($before), pipeline_normalized($escalation), 'review-plan', 'review', DesignSize::Bounded))->toBeNull();
});

it('lets the design spec step set the spec and remove the plan on Architectural, and require the plan on Bounded', function () {
    $before = record_before('design');
    $spec = ['status' => 'continued', 'spec' => 'docs/spec.md'];
    $both = [...$spec, 'plan' => 'docs/plan.md'];
    $bounded = record_facts(['size' => DesignSize::Bounded]);

    $architectural = pipeline_record($before, $before, 'design', 'spec', $spec, record_facts());
    expect($architectural['artifacts'])->toBe(['spec' => 'docs/spec.md', 'pr' => null, 'issue' => 52]);
    expect(pipeline_record($before, $before, 'design', 'spec', $both, record_facts()))
        ->toBe('an Architectural spec takes no --plan: the plan step writes the plan');
    expect(pipeline_record($before, $before, 'design', 'spec', $both, $bounded)['artifacts'])
        ->toMatchArray(['spec' => 'docs/spec.md', 'plan' => 'docs/plan.md']);
    expect(pipeline_record($before, $before, 'design', 'spec', $spec, $bounded))
        ->toBe('a Bounded spec needs --plan: its spec step commits the plan too');
});

it('stores a spec or plan path relative to the worktree, and refuses one that is not committed', function () {
    $before = record_before('design', ['artifacts' => ['spec' => 'docs/spec.md', 'pr' => null, 'issue' => 52]]);

    expect(pipeline_record($before, $before, 'design', 'plan', ['status' => 'continued', 'plan' => '/tmp/wt/docs/plan.md'], record_facts())['artifacts']['plan'])
        ->toBe('docs/plan.md');
    expect(pipeline_record($before, $before, 'design', 'plan', ['status' => 'continued', 'plan' => 'docs/plan.md'], record_facts(['committed' => ['docs/spec.md']])))
        ->toBe('the plan docs/plan.md does not exist at HEAD (`git cat-file -e HEAD:docs/plan.md` failed): commit it first');
});

it('refuses a flag the step\'s row does not list, naming the ones it does, and a missing required flag', function () {
    $before = record_before('handoff');

    expect(pipeline_record($before, $before, 'handoff', 'run', ['status' => 'continued', 'pr' => '7', 'plan' => 'docs/plan.md'], record_facts()))
        ->toBe('--plan is not a flag of handoff run with --status continued, which takes --pr');
    expect(pipeline_record($before, $before, 'handoff', 'run', ['status' => 'continued'], record_facts()))
        ->toBe('handoff run with --status continued needs --pr');
    expect(pipeline_record($before, $before, 'implement', 'run', ['status' => 'continued', 'pr' => '7'], record_facts()))
        ->toBe('--pr is not a flag of implement run with --status continued, which takes no flag');
    expect(pipeline_record($before, $before, 'handoff', 'run', ['status' => 'halted', 'reason' => 'x', 'pr' => '7'], record_facts()))
        ->toBe('--pr is not a flag of handoff run with --status halted, which takes --reason');
});

it('refuses a status the step may not return, a step its leg does not have, and a reason that is blank', function () {
    $before = record_before('design');

    expect(pipeline_record($before, $before, 'design', 'plan', ['status' => 'looped-back'], record_facts()))
        ->toBe('the design plan step cannot return looped-back; it may return continued, halted');
    expect(pipeline_record($before, $before, 'design', 'resolve', ['status' => 'continued'], record_facts()))
        ->toBe('design has no resolve step');
    expect(pipeline_record($before, $before, 'design', 'plan', ['status' => 'halted', 'reason' => '  '], record_facts()))
        ->toBe('--reason is empty: say why');
    expect(pipeline_record(record_before('implement'), [], 'implement', 'run', ['status' => 'plan-insufficient', 'reason' => ''], record_facts()))
        ->toBe('--reason is empty: say why');
});

it('starts from the snapshot: a second record replaces the first, the suite is kept, a hand edit is dropped and named', function () {
    $gap = ['gate' => 'plan-approval', 'leg' => 'implement', 'cycle' => 1, 'at' => '2026-09-30T09:00:00Z', 'reason' => 'needs a queue', 'outcome' => 'looped-back'];
    $suite = ['tree' => 't1', 'outcome' => 'green', 'passed' => 104, 'failed' => 0, 'at' => '2026-09-30T11:00:00Z'];
    $before = record_before('design', ['gate_ledger' => [$gap]]);
    $edited = [...$before, 'suite' => $suite, 'cursor' => ['leg' => 'design', 'status' => 'continued'], 'gate_ledger' => [[...$gap, 'actions' => []]]];

    $candidate = pipeline_record($before, $edited, 'design', 'plan', record_given('design', 'plan', 'continued'), record_facts());

    expect($candidate['gate_ledger'])->toBe([$gap]);
    expect($candidate['suite'])->toBe($suite);
    expect(pipeline_record_replaced($before, $edited))->toEqualCanonicalizing(['gate_ledger', 'cursor.status']);
    expect(pipeline_record_replaced($before, $before))->toBe([]);

    $review = record_before('review-plan');
    $given = record_given('review-plan', 'review', 'continued');
    $first = pipeline_record($review, $review, 'review-plan', 'review', $given, record_facts());
    $second = pipeline_record($review, $first, 'review-plan', 'review', $given, record_facts());

    expect($second['gate_ledger'])->toHaveCount(1);
    expect(pipeline_record_entry($review, record_before('review-plan', ['cursor' => ['leg' => 'review-plan', 'status' => 'halted', 'reason' => 'x']]), 'review-plan', 'review'))->toBeNull();
});

it('names the content triggers that fired, and never ui', function () {
    expect(pipeline_annotations(['package' => true, 'migration' => false, 'auth' => true, 'ui' => true]))->toBe(['package', 'auth']);
    expect(pipeline_annotations(['package' => false, 'migration' => false, 'auth' => false, 'ui' => true]))->toBe([]);
});
