<?php

/** The autoflow script run on `$args` with agent() faked (`autoflow_replay.mjs`): `{labels, prompts, settings, result}`; with `$steps` each agent is a stub step against the real `brief`. */
function autoflow_replay(array $args, array $returns, bool $steps = false): array
{
    $process = proc_open(['node', __DIR__ . '/autoflow_replay.mjs'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect($process)->toBeResource('AutoflowScriptTest needs node on PATH');
    fwrite($pipes[0], json_encode(['script' => __DIR__ . '/../../workflow/pipeline-autoflow.js', 'args' => $args, 'returns' => (object) $returns, 'steps' => $steps], JSON_UNESCAPED_SLASHES));
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0, "node failed: {$stderr}");

    return json_decode($stdout, true);
}

/** `launch`'s start answer for an autoflow run whose cursor is on `$leg`; `$spec` is the committed spec's text, `$plan` the plan's recorded path, `$light` the manifest's `light` flag. */
function autoflow_start(string $leg, array $ledger = [], ?string $spec = null, ?string $plan = null, bool $light = false): array
{
    $artifacts = ['spec' => $spec === null ? null : 'spec.md', 'plan' => $plan, 'pr' => null, 'issue' => null];
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => $leg, 'status' => 'pending'], 'gate_ledger' => $ledger, 'artifacts' => $artifacts, ...($light ? ['light' => true] : [])]);
    if ($spec !== null) {
        file_put_contents($fixture['dir'] . '/spec.md', $spec);
    }

    return dispatch_cli(['launch', $fixture['manifest'], $fixture['diff']])['json'];
}

const AUTOFLOW_C = ['status' => 'continued'];
const AUTOFLOW_LB = ['status' => 'looped-back'];
const AUTOFLOW_PI = ['status' => 'plan-insufficient', 'reason' => 'stub gap'];

it('walks to done on launch\'s tables, through a loop-back at each gate', function () {
    $design = [...AUTOFLOW_C, 'size' => 'Architectural'];
    $replay = autoflow_replay(autoflow_start('design'), [
        'design:spec' => [$design, $design], 'design:plan' => [$design, $design],
        'review-plan:review' => [AUTOFLOW_C, AUTOFLOW_C],
        'review-plan:resolve' => [AUTOFLOW_LB, AUTOFLOW_C],
        'handoff:run' => [AUTOFLOW_C],
        'implement:run' => [[...AUTOFLOW_C, 'ui' => true], [...AUTOFLOW_C, 'ui' => true], [...AUTOFLOW_C, 'ui' => false]],
        'verify-ui:run' => [AUTOFLOW_LB, AUTOFLOW_C],
        'review-pr:review' => [AUTOFLOW_C, AUTOFLOW_C],
        'review-pr:resolve' => [AUTOFLOW_LB, AUTOFLOW_C],
    ]);

    expect($replay['labels'])->toBe([
        'design:spec', 'design:plan', 'review-plan:review', 'review-plan:resolve', 'design:spec', 'design:plan', 'review-plan:review', 'review-plan:resolve',
        'handoff:run', 'implement:run', 'verify-ui:run', 'implement:run', 'verify-ui:run',
        'review-pr:review', 'review-pr:resolve', 'implement:run', 'review-pr:review', 'review-pr:resolve',
    ]);
    expect($replay['result'])->toBe(['action' => 'done']);
});

it('halts on launch\'s bound when plan gaps keep looping back to design', function () {
    $design = [...AUTOFLOW_C, 'size' => 'Architectural'];
    $replay = autoflow_replay(autoflow_start('implement'), [
        'implement:run' => [[...AUTOFLOW_PI, 'ui' => false], [...AUTOFLOW_PI, 'ui' => false]],
        'design:spec' => [$design], 'design:plan' => [$design, $design],
        'review-plan:review' => [AUTOFLOW_C, AUTOFLOW_C],
        'review-plan:resolve' => [AUTOFLOW_LB, AUTOFLOW_C],
        'handoff:run' => [AUTOFLOW_C],
    ]);

    expect($replay['labels'])->toBe([
        'implement:run', 'design:plan', 'review-plan:review', 'review-plan:resolve', 'design:spec', 'design:plan',
        'review-plan:review', 'review-plan:resolve', 'handoff:run', 'implement:run',
    ]);
    expect($replay['result'])->toBe(['action' => 'halt', 'leg' => 'implement', 'reason' => 'review-plan: loop-back bound exhausted']);
});

it('routes by the tables it is given, not by a copy of its own', function () {
    $start = autoflow_start('implement');
    $start['tables']['bound'] = 0;
    unset($start['loops']['verify-ui']);
    $unbounded = autoflow_replay($start, ['implement:run' => [[...AUTOFLOW_C, 'ui' => true]], 'verify-ui:run' => [AUTOFLOW_LB]]);

    expect($unbounded['result'])->toBe(['action' => 'halt', 'leg' => 'verify-ui', 'reason' => 'verify-ui: loop-back bound exhausted']);

    $start = autoflow_start('review-pr');
    $start['tables']['allowed']['review-pr:resolve'] = ['continued', 'halted'];
    $narrowed = autoflow_replay($start, ['review-pr:review' => [AUTOFLOW_C], 'review-pr:resolve' => [AUTOFLOW_LB]]);

    expect($narrowed['result'])->toBe(['action' => 'halt', 'leg' => 'review-pr', 'reason' => 'the agent failed: review-pr:resolve may not return looped-back']);
});

it('exempts one Bounded escalation and then counts on from the ledger\'s loop-backs', function () {
    $looped = fn (int $cycle) => ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => $cycle, 'at' => "2026-09-24T0{$cycle}:00:00Z", 'review' => 'r', 'outcome' => 'looped-back'];
    $start = autoflow_start('handoff', [$looped(1), $looped(2)], "# x — design\n\n**Design size:** Bounded\n");
    $replay = autoflow_replay($start, [
        'handoff:run' => [AUTOFLOW_PI],
        'design:spec' => [[...AUTOFLOW_C, 'size' => 'Bounded']],
        'review-plan:review' => [AUTOFLOW_PI],
    ]);

    expect($start['loops']['review-plan'])->toBe(2);
    expect($replay['labels'])->toBe(['handoff:run', 'design:spec', 'review-plan:review']);
    expect($replay['result'])->toBe(['action' => 'halt', 'leg' => 'review-plan', 'reason' => 'review-plan: loop-back bound exhausted']);
});

it('halts a start step its leg does not have, before any agent', function () {
    $replay = autoflow_replay([...autoflow_start('handoff'), 'startStep' => 'resolve'], []);

    expect($replay)->toBe(['labels' => [], 'prompts' => [], 'settings' => [], 'result' => ['action' => 'halt', 'leg' => 'handoff', 'reason' => 'handoff has no resolve step']]);
});

it('halts before any agent when launch\'s tables are missing or incomplete', function (callable $break) {
    $replay = autoflow_replay($break(autoflow_start('handoff')), []);

    expect($replay['labels'])->toBe([]);
    expect($replay['result'])->toBe(['action' => 'halt', 'leg' => 'handoff', 'reason' => 'args carry no complete tables: re-run launch from checks that have pipeline_routing_tables()']);
})->with([
    'no tables' => [function (array $start) { unset($start['tables']); return $start; }],
    'a step without statuses' => [function (array $start) { unset($start['tables']['allowed']['handoff:run']); return $start; }],
    'a step with an empty status list' => [function (array $start) { $start['tables']['allowed']['handoff:run'] = []; return $start; }],
    'a leg without steps' => [function (array $start) { unset($start['tables']['steps']['verify-ui']); return $start; }],
    'a loop-back to no leg' => [function (array $start) { $start['tables']['loopTarget']['review-pr'] = 'nowhere'; return $start; }],
    'a bound that is not a number' => [function (array $start) { $start['tables']['bound'] = '2'; return $start; }],
    'no bounded table' => [function (array $start) { unset($start['tables']['bounded']); return $start; }],
    'a bounded step its leg does not have' => [function (array $start) { $start['tables']['bounded']['design'] = ['run']; return $start; }],
]);

it('halts before any agent when launch\'s tables have no review-plan loop-back, the gate plan gaps are charged to', function () {
    $start = autoflow_start('implement');
    unset($start['tables']['loopTarget']['review-plan']);

    expect(autoflow_replay($start, []))->toBe(['labels' => [], 'prompts' => [], 'settings' => [], 'result' => ['action' => 'halt', 'leg' => 'implement', 'reason' => 'args are not a launch start answer']]);
});

/** The brief command in each prompt, after the manifest path. */
function autoflow_briefs(array $prompts): array
{
    return array_map(fn (string $prompt) => preg_match('/dispatch_cli\.php brief \S+ ([^`]+)`/', $prompt, $match) ? $match[1] : null, $prompts);
}

it('tells each brief what the step before it returned, and a retry the same', function () {
    $design = [...AUTOFLOW_C, 'size' => 'Architectural'];
    $replay = autoflow_replay(autoflow_start('design'), [
        'design:spec' => [$design, $design], 'design:plan' => [$design, $design],
        'review-plan:review' => [null, AUTOFLOW_C, AUTOFLOW_C],
        'review-plan:resolve' => [AUTOFLOW_LB, AUTOFLOW_C],
        'handoff:run' => [AUTOFLOW_C],
        'implement:run' => [[...AUTOFLOW_C, 'ui' => true]],
        'verify-ui:run' => [['status' => 'halted', 'reason' => 'stub stop']],
    ]);

    expect(autoflow_briefs($replay['prompts']))->toBe([
        'design spec',
        'design plan --after design:spec --status continued --size Architectural',
        'review-plan review --after design:plan --status continued --size Architectural',
        'review-plan review --after design:plan --status continued --size Architectural',
        'review-plan resolve --after review-plan:review --status continued',
        'design spec --after review-plan:resolve --status looped-back',
        'design plan --after design:spec --status continued --size Architectural',
        'review-plan review --after design:plan --status continued --size Architectural',
        'review-plan resolve --after review-plan:review --status continued',
        'handoff run --after review-plan:resolve --status continued',
        'implement run --after handoff:run --status continued',
        'verify-ui run --after implement:run --status continued --ui true',
    ]);
    expect($replay['result'])->toBe(['action' => 'halt', 'leg' => 'verify-ui', 'reason' => 'stub stop']);
});

/** A stub step's manifest write: its status, and whatever else it changes. */
function autoflow_writes(string $status, array $changes = []): array
{
    return ['status' => $status, 'write' => ['cursor' => ['status' => $status], ...$changes]];
}

it('halts at the next brief when a stub resolve step leaves its entry open (the replay smoke pass)', function () {
    $start = autoflow_start('review-plan');
    $open = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-25T10:00:00Z', 'review' => 'r', 'annotations' => []];

    $replay = autoflow_replay($start, [
        'review-plan:review' => [autoflow_writes('continued', ['gate_ledger' => [$open]])],
        'review-plan:resolve' => [autoflow_writes('continued')],
        'handoff:run' => [autoflow_writes('continued')],
    ], steps: true);

    $reason = "the resolve step must set the open plan-approval entry's outcome to continued";
    expect($replay['labels'])->toBe(['review-plan:review', 'review-plan:resolve', 'handoff:run']);
    expect($replay['result'])->toBe(['action' => 'halt', 'leg' => 'handoff', 'reason' => $reason]);

    dispatch_cli(['finish', $start['manifest'], json_encode($replay['result'])]);
    expect(manifest_read($start['manifest'])['cursor'])->toBe(['leg' => 'review-plan', 'status' => 'halted', 'reason' => $reason]);
});

it('walks stub steps that write what their briefs ask to done, through a retried review (the replay smoke pass)', function () {
    $start = autoflow_start('design', spec: "# x — design\n\n**Design size:** Architectural\n");
    expect($start['startStep'])->toBe('plan');
    $plan = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-25T10:00:00Z', 'review' => 'r', 'annotations' => []];
    $pr = [...$plan, 'gate' => 'pr-review', 'leg' => 'review-pr', 'at' => '2026-09-25T12:00:00Z', 'reviewed_sha' => str_repeat('c', 40)];
    $done = fn (array $entry) => [...$entry, 'actions' => [], 'outcome' => 'continued'];

    $replay = autoflow_replay($start, [
        'design:plan' => [[...autoflow_writes('continued', ['last_sha' => 'aaa1111', 'artifacts' => ['spec' => 'spec.md', 'plan' => 'plan.md', 'pr' => null, 'issue' => null]]), 'size' => 'Architectural']],
        'review-plan:review' => [autoflow_writes('continued', ['gate_ledger' => [$plan]])],
        'review-plan:resolve' => [autoflow_writes('continued', ['gate_ledger' => [$done($plan)]])],
        'handoff:run' => [autoflow_writes('continued', ['artifacts' => ['spec' => 'spec.md', 'plan' => 'plan.md', 'pr' => 7, 'issue' => null]])],
        'implement:run' => [[...autoflow_writes('continued', ['last_sha' => 'bbb2222']), 'diff' => '', 'ui' => false]],
        'review-pr:review' => [null, autoflow_writes('continued', ['gate_ledger' => [$done($plan), $pr]])],
        'review-pr:resolve' => [autoflow_writes('continued', ['gate_ledger' => [$done($plan), $done($pr)]])],
    ], steps: true);

    expect($replay['labels'])->toBe(['design:plan', 'review-plan:review', 'review-plan:resolve', 'handoff:run', 'implement:run', 'review-pr:review', 'review-pr:review', 'review-pr:resolve']);
    expect($replay['result'])->toBe(['action' => 'done']);
    expect(dispatch_cli(['finish', $start['manifest'], '{"action":"done"}'])['json'])->toBe(['action' => 'done']);
});

const AUTOFLOW_STOP = ['status' => 'halted', 'reason' => 'stub stop'];

it('runs a Bounded design as its spec step alone', function () {
    $replay = autoflow_replay(autoflow_start('design'), [
        'design:spec' => [[...AUTOFLOW_C, 'size' => 'Bounded']],
        'review-plan:review' => [AUTOFLOW_STOP],
    ]);

    expect($replay['labels'])->toBe(['design:spec', 'review-plan:review']);
    expect(autoflow_briefs($replay['prompts'])[1])->toBe('review-plan review --after design:spec --status continued --size Bounded');
    expect($replay['result'])->toBe(['action' => 'halt', 'leg' => 'review-plan', 'reason' => 'stub stop']);
});

it('reruns only the plan step on a plan gap, and the spec step first on an escalation', function (string $leg, ?string $spec, array $returns, array $labels) {
    $replay = autoflow_replay(autoflow_start($leg, spec: $spec), [...$returns, 'review-plan:review' => [...($returns['review-plan:review'] ?? []), AUTOFLOW_STOP]]);

    expect($replay['labels'])->toBe($labels);
    expect($replay['result'])->toBe(['action' => 'halt', 'leg' => 'review-plan', 'reason' => 'stub stop']);
})->with([
    'a gap found by implement' => ['implement', null, [
        'implement:run' => [[...AUTOFLOW_PI, 'ui' => false]],
        'design:plan' => [[...AUTOFLOW_C, 'size' => 'Architectural']],
    ], ['implement:run', 'design:plan', 'review-plan:review']],
    'a gap found by review-plan\'s review' => ['review-plan', null, [
        'review-plan:review' => [AUTOFLOW_PI],
        'design:plan' => [[...AUTOFLOW_C, 'size' => 'Architectural']],
    ], ['review-plan:review', 'design:plan', 'review-plan:review']],
    'a Bounded escalation, grown to Architectural' => ['handoff', "# x — design\n\n**Design size:** Bounded\n", [
        'handoff:run' => [AUTOFLOW_PI],
        'design:spec' => [[...AUTOFLOW_C, 'size' => 'Architectural']],
        'design:plan' => [[...AUTOFLOW_C, 'size' => 'Architectural']],
    ], ['handoff:run', 'design:spec', 'design:plan', 'review-plan:review']],
]);

it('walks a plan gap through the real briefs to the plan step alone (the replay smoke pass)', function () {
    $start = autoflow_start('implement', spec: "# x — design\n\n**Design size:** Architectural\n", plan: 'plan.md');
    $gap = ['gate' => 'plan-approval', 'leg' => 'implement', 'cycle' => 1, 'at' => '2026-09-29T10:00:00Z', 'reason' => 'needs a queue', 'outcome' => 'looped-back'];

    $replay = autoflow_replay($start, [
        'implement:run' => [[...autoflow_writes('plan-insufficient', ['gate_ledger' => [$gap]]), 'reason' => 'needs a queue', 'diff' => '', 'ui' => false]],
        'design:plan' => [[...autoflow_writes('continued', ['last_sha' => 'aaa1111']), 'size' => 'Architectural']],
        'review-plan:review' => [[...AUTOFLOW_STOP, 'write' => ['cursor' => AUTOFLOW_STOP]]],
    ], steps: true);

    expect($replay['labels'])->toBe(['implement:run', 'design:plan', 'review-plan:review']);
    expect($replay['result'])->toBe(['action' => 'halt', 'leg' => 'review-plan', 'reason' => 'stub stop']);
});

it('reruns the spec step and then the plan step on a review loop-back, whose brief refuses a kept plan (the replay smoke pass)', function (bool $removes, array $labels, array $result) {
    $start = autoflow_start('review-plan', spec: "# x — design\n\n**Design size:** Architectural\n", plan: 'plan.md');
    $open = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-29T10:00:00Z', 'review' => 'r', 'annotations' => []];
    $artifacts = ['spec' => 'spec.md', 'plan' => $removes ? null : 'plan.md', 'pr' => null, 'issue' => null];

    $replay = autoflow_replay($start, [
        'review-plan:review' => [autoflow_writes('continued', ['gate_ledger' => [$open]]), [...AUTOFLOW_STOP, 'write' => ['cursor' => AUTOFLOW_STOP]]],
        'review-plan:resolve' => [autoflow_writes('looped-back', ['gate_ledger' => [[...$open, 'actions' => [], 'outcome' => 'looped-back']]])],
        'design:spec' => [[...autoflow_writes('continued', ['artifacts' => $artifacts]), 'size' => 'Architectural']],
        'design:plan' => [[...autoflow_writes('continued', ['artifacts' => [...$artifacts, 'plan' => 'plan.md']]), 'size' => 'Architectural']],
    ], steps: true);

    expect($replay['labels'])->toBe($labels);
    expect($replay['result'])->toBe($result);
})->with([
    'the spec step removed artifacts.plan' => [
        true,
        ['review-plan:review', 'review-plan:resolve', 'design:spec', 'design:plan', 'review-plan:review'],
        ['action' => 'halt', 'leg' => 'review-plan', 'reason' => 'stub stop'],
    ],
    'the spec step kept the old plan' => [
        false,
        ['review-plan:review', 'review-plan:resolve', 'design:spec', 'design:plan'],
        ['action' => 'halt', 'leg' => 'design', 'reason' => 'the manifest calls for the design spec step, not plan: the plan step follows a spec step that set artifacts.spec and removed artifacts.plan, or a plan-insufficient on an Architectural design'],
    ],
]);

it('runs every step on its full entry without light, and implement on xhigh once verify-ui or review-pr looped back', function () {
    $design = [...AUTOFLOW_C, 'size' => 'Architectural'];
    $replay = autoflow_replay(autoflow_start('design'), [
        'design:spec' => [$design, $design], 'design:plan' => [$design, $design],
        'review-plan:review' => [AUTOFLOW_C, AUTOFLOW_C],
        'review-plan:resolve' => [AUTOFLOW_LB, AUTOFLOW_C],
        'handoff:run' => [AUTOFLOW_C],
        'implement:run' => [[...AUTOFLOW_C, 'ui' => true], [...AUTOFLOW_C, 'ui' => true], [...AUTOFLOW_C, 'ui' => false]],
        'verify-ui:run' => [AUTOFLOW_LB, AUTOFLOW_C],
        'review-pr:review' => [AUTOFLOW_C, AUTOFLOW_C],
        'review-pr:resolve' => [AUTOFLOW_LB, AUTOFLOW_C],
    ]);

    expect($replay['settings'])->toBe([
        'opus high', 'opus high', 'fable high', 'opus high', 'opus high', 'opus high', 'fable high', 'opus high',
        'sonnet low', 'opus high', 'sonnet high', 'opus xhigh', 'sonnet high',
        'fable high', 'opus high', 'opus xhigh', 'fable high', 'opus high',
    ]);
    expect($replay['result'])->toBe(['action' => 'done']);
});

it('runs a light run\'s Bounded design and every step after it on light, where implement and review-pr\'s review keep full\'s setting', function () {
    $replay = autoflow_replay(autoflow_start('design', light: true), [
        'design:spec' => [[...AUTOFLOW_C, 'size' => 'Bounded']],
        'review-plan:review' => [AUTOFLOW_C], 'review-plan:resolve' => [AUTOFLOW_C],
        'handoff:run' => [AUTOFLOW_C],
        'implement:run' => [[...AUTOFLOW_C, 'ui' => true]],
        'verify-ui:run' => [AUTOFLOW_C],
        'review-pr:review' => [AUTOFLOW_C], 'review-pr:resolve' => [AUTOFLOW_C],
    ]);

    expect($replay['labels'])->toBe(['design:spec', 'review-plan:review', 'review-plan:resolve', 'handoff:run', 'implement:run', 'verify-ui:run', 'review-pr:review', 'review-pr:resolve']);
    expect($replay['settings'])->toBe(['opus medium', 'fable medium', 'opus medium', 'sonnet low', 'opus high', 'sonnet medium', 'fable high', 'opus medium']);
    expect($replay['result'])->toBe(['action' => 'done']);
});

it('moves a light run to full when its design turns out Architectural, and on a Bounded escalation', function (string $leg, ?string $spec, array $returns, array $labels, array $settings) {
    $replay = autoflow_replay(autoflow_start($leg, spec: $spec, light: true), $returns);

    expect($replay['labels'])->toBe($labels);
    expect($replay['settings'])->toBe($settings);
    expect($replay['result'])->toBe(['action' => 'halt', 'leg' => 'review-plan', 'reason' => 'stub stop']);
})->with([
    'a spec step that writes an Architectural design' => ['design', null, [
        'design:spec' => [[...AUTOFLOW_C, 'size' => 'Architectural']],
        'design:plan' => [[...AUTOFLOW_C, 'size' => 'Architectural']],
        'review-plan:review' => [AUTOFLOW_STOP],
    ], ['design:spec', 'design:plan', 'review-plan:review'], ['opus medium', 'opus high', 'fable high']],
    'a Bounded escalation' => ['review-plan', "# x — design\n\n**Design size:** Bounded\n", [
        'review-plan:review' => [AUTOFLOW_PI, AUTOFLOW_STOP],
        'design:spec' => [[...AUTOFLOW_C, 'size' => 'Architectural']],
        'design:plan' => [[...AUTOFLOW_C, 'size' => 'Architectural']],
    ], ['review-plan:review', 'design:spec', 'design:plan', 'review-plan:review'], ['fable medium', 'opus high', 'opus high', 'fable high']],
    'a Bounded escalation whose grown spec still says Bounded' => ['review-plan', "# x — design\n\n**Design size:** Bounded\n", [
        'review-plan:review' => [AUTOFLOW_PI, AUTOFLOW_STOP],
        'design:spec' => [[...AUTOFLOW_C, 'size' => 'Bounded']],
    ], ['review-plan:review', 'design:spec', 'review-plan:review'], ['fable medium', 'opus high', 'fable high']],
]);

it('starts implement on its loop-back entry when the ledger already counts a verify-ui or review-pr loop-back, and not after a plan gap', function (array $entry, string $setting) {
    $replay = autoflow_replay(autoflow_start('implement', [$entry]), ['implement:run' => [[...AUTOFLOW_STOP, 'ui' => false]]]);

    expect($replay['settings'])->toBe([$setting]);
})->with([
    'a review-pr loop-back' => [['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => '2026-09-29T10:00:00Z', 'review' => 'r', 'outcome' => 'looped-back'], 'opus xhigh'],
    'a verify-ui loop-back' => [['gate' => 'verify-ui', 'leg' => 'verify-ui', 'cycle' => 1, 'at' => '2026-09-29T10:00:00Z', 'outcome' => 'looped-back'], 'opus xhigh'],
    'a plan gap' => [['gate' => 'plan-approval', 'leg' => 'implement', 'cycle' => 1, 'at' => '2026-09-29T10:00:00Z', 'reason' => 'needs a queue', 'outcome' => 'looped-back'], 'opus high'],
]);

it('reruns a review that returned nothing once, on the retry entry, and no other step', function () {
    $review = autoflow_replay(autoflow_start('review-plan'), ['review-plan:review' => [null, AUTOFLOW_STOP]]);

    expect($review['labels'])->toBe(['review-plan:review', 'review-plan:review']);
    expect($review['settings'])->toBe(['fable high', 'opus xhigh']);
    expect($review['result'])->toBe(['action' => 'halt', 'leg' => 'review-plan', 'reason' => 'stub stop']);

    $handoff = autoflow_replay(autoflow_start('handoff'), ['handoff:run' => [null]]);

    expect($handoff['labels'])->toBe(['handoff:run']);
    expect($handoff['result'])->toBe(['action' => 'halt', 'leg' => 'handoff', 'reason' => 'the agent returned nothing']);
});

it('follows whatever agents launch hands it, and retries a review that runs on another model', function () {
    $start = autoflow_start('review-plan');
    $start['agents']['full']['review-plan:review'] = ['model' => 'opus', 'effort' => 'max'];
    $start['agents']['retry'] = ['model' => 'sonnet', 'effort' => 'medium'];

    expect(autoflow_replay($start, ['review-plan:review' => [null, AUTOFLOW_STOP]])['settings'])->toBe(['opus max', 'sonnet medium']);
});

it('runs a smoke run\'s stub steps on the smoke entry and never retries one', function () {
    $start = [...autoflow_start('review-plan'), 'stub' => ['prompt' => 'Return it.', 'steps' => ['review-plan:review' => [AUTOFLOW_C]]]];
    $replay = autoflow_replay($start, ['review-plan:review' => [null]]);

    expect($replay['settings'])->toBe(['sonnet low']);
    expect($replay['result'])->toBe(['action' => 'halt', 'leg' => 'review-plan', 'reason' => 'the agent returned nothing']);
});

it('halts before any agent when launch\'s agents or profile are missing or incomplete', function (callable $break) {
    $replay = autoflow_replay($break(autoflow_start('handoff')), []);

    expect($replay['labels'])->toBe([]);
    expect($replay['result'])->toBe(['action' => 'halt', 'leg' => 'handoff', 'reason' => 'args carry no complete agents table: re-run launch from checks that have pipeline_agent_table()']);
})->with([
    'no agents' => [function (array $start) { unset($start['agents']); return $start; }],
    'a step missing from light' => [function (array $start) { unset($start['agents']['light']['design:plan']); return $start; }],
    'an entry without an effort' => [function (array $start) { unset($start['agents']['full']['handoff:run']['effort']); return $start; }],
    'an empty model' => [function (array $start) { $start['agents']['full']['implement:run']['model'] = ''; return $start; }],
    'no retry entry' => [function (array $start) { unset($start['agents']['retry']); return $start; }],
    'no smoke entry' => [function (array $start) { unset($start['agents']['smoke']); return $start; }],
    'a loop-back entry for a step the run does not have' => [function (array $start) { $start['agents']['loopedBack']['design:run'] = ['model' => 'opus', 'effort' => 'high']; return $start; }],
    'no profile' => [function (array $start) { unset($start['profile']); return $start; }],
    'a profile that is neither full nor light' => [function (array $start) { $start['profile'] = 'medium'; return $start; }],
]);
