<?php

/** The autoflow script run on `$args` with agent() faked (`autoflow_replay.mjs`): `{labels, prompts, result}`; with `$steps` each agent is a stub step against the real `brief`. */
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

/** `launch`'s start answer for an autoflow run whose cursor is on `$leg`; `$spec` is the committed spec's text. */
function autoflow_start(string $leg, array $ledger = [], ?string $spec = null): array
{
    $artifacts = ['spec' => $spec === null ? null : 'spec.md', 'plan' => null, 'pr' => null, 'issue' => null];
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => $leg, 'status' => 'pending'], 'gate_ledger' => $ledger, 'artifacts' => $artifacts]);
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
        'design:run' => [$design, $design],
        'review-plan:review' => [AUTOFLOW_C, AUTOFLOW_C],
        'review-plan:resolve' => [AUTOFLOW_LB, AUTOFLOW_C],
        'handoff:run' => [AUTOFLOW_C],
        'implement:run' => [[...AUTOFLOW_C, 'ui' => true], [...AUTOFLOW_C, 'ui' => true], [...AUTOFLOW_C, 'ui' => false]],
        'verify-ui:run' => [AUTOFLOW_LB, AUTOFLOW_C],
        'review-pr:review' => [AUTOFLOW_C, AUTOFLOW_C],
        'review-pr:resolve' => [AUTOFLOW_LB, AUTOFLOW_C],
    ]);

    expect($replay['labels'])->toBe([
        'design:run', 'review-plan:review', 'review-plan:resolve', 'design:run', 'review-plan:review', 'review-plan:resolve',
        'handoff:run', 'implement:run', 'verify-ui:run', 'implement:run', 'verify-ui:run',
        'review-pr:review', 'review-pr:resolve', 'implement:run', 'review-pr:review', 'review-pr:resolve',
    ]);
    expect($replay['result'])->toBe(['action' => 'done']);
});

it('halts on launch\'s bound when plan gaps keep looping back to design', function () {
    $design = [...AUTOFLOW_C, 'size' => 'Architectural'];
    $replay = autoflow_replay(autoflow_start('implement'), [
        'implement:run' => [[...AUTOFLOW_PI, 'ui' => false], [...AUTOFLOW_PI, 'ui' => false]],
        'design:run' => [$design, $design],
        'review-plan:review' => [AUTOFLOW_C, AUTOFLOW_C],
        'review-plan:resolve' => [AUTOFLOW_LB, AUTOFLOW_C],
        'handoff:run' => [AUTOFLOW_C],
    ]);

    expect($replay['labels'])->toBe([
        'implement:run', 'design:run', 'review-plan:review', 'review-plan:resolve', 'design:run',
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
        'design:run' => [[...AUTOFLOW_C, 'size' => 'Bounded']],
        'review-plan:review' => [AUTOFLOW_PI],
    ]);

    expect($start['loops']['review-plan'])->toBe(2);
    expect($replay['labels'])->toBe(['handoff:run', 'design:run', 'review-plan:review']);
    expect($replay['result'])->toBe(['action' => 'halt', 'leg' => 'review-plan', 'reason' => 'review-plan: loop-back bound exhausted']);
});

it('halts a start step its leg does not have, before any agent', function () {
    $replay = autoflow_replay([...autoflow_start('handoff'), 'startStep' => 'resolve'], []);

    expect($replay)->toBe(['labels' => [], 'prompts' => [], 'result' => ['action' => 'halt', 'leg' => 'handoff', 'reason' => 'handoff has no resolve step']]);
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
]);

/** The brief command in each prompt, after the manifest path. */
function autoflow_briefs(array $prompts): array
{
    return array_map(fn (string $prompt) => preg_match('/dispatch_cli\.php brief \S+ ([^`]+)`/', $prompt, $match) ? $match[1] : null, $prompts);
}

it('tells each brief what the step before it returned, and a retry the same', function () {
    $design = [...AUTOFLOW_C, 'size' => 'Architectural'];
    $replay = autoflow_replay(autoflow_start('design'), [
        'design:run' => [$design, $design],
        'review-plan:review' => [null, AUTOFLOW_C, AUTOFLOW_C],
        'review-plan:resolve' => [AUTOFLOW_LB, AUTOFLOW_C],
        'handoff:run' => [AUTOFLOW_C],
        'implement:run' => [[...AUTOFLOW_C, 'ui' => true]],
        'verify-ui:run' => [['status' => 'halted', 'reason' => 'stub stop']],
    ]);

    expect(autoflow_briefs($replay['prompts']))->toBe([
        'design run',
        'review-plan review --after design:run --status continued --size Architectural',
        'review-plan review --after design:run --status continued --size Architectural',
        'review-plan resolve --after review-plan:review --status continued',
        'design run --after review-plan:resolve --status looped-back',
        'review-plan review --after design:run --status continued --size Architectural',
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
    $plan = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-25T10:00:00Z', 'review' => 'r', 'annotations' => []];
    $pr = [...$plan, 'gate' => 'pr-review', 'leg' => 'review-pr', 'at' => '2026-09-25T12:00:00Z'];
    $done = fn (array $entry) => [...$entry, 'actions' => [], 'outcome' => 'continued'];

    $replay = autoflow_replay($start, [
        'design:run' => [[...autoflow_writes('continued', ['last_sha' => 'aaa1111']), 'size' => 'Architectural']],
        'review-plan:review' => [autoflow_writes('continued', ['gate_ledger' => [$plan]])],
        'review-plan:resolve' => [autoflow_writes('continued', ['gate_ledger' => [$done($plan)]])],
        'handoff:run' => [autoflow_writes('continued', ['artifacts' => ['spec' => 'spec.md', 'plan' => null, 'pr' => 7, 'issue' => null]])],
        'implement:run' => [[...autoflow_writes('continued', ['last_sha' => 'bbb2222']), 'diff' => '', 'ui' => false]],
        'review-pr:review' => [null, autoflow_writes('continued', ['gate_ledger' => [$done($plan), $pr]])],
        'review-pr:resolve' => [autoflow_writes('continued', ['gate_ledger' => [$done($plan), $done($pr)]])],
    ], steps: true);

    expect($replay['labels'])->toBe(['design:run', 'review-plan:review', 'review-plan:resolve', 'handoff:run', 'implement:run', 'review-pr:review', 'review-pr:review', 'review-pr:resolve']);
    expect($replay['result'])->toBe(['action' => 'done']);
    expect(dispatch_cli(['finish', $start['manifest'], '{"action":"done"}'])['json'])->toBe(['action' => 'done']);
});
