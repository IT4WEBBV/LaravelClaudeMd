<?php

function cost_call(string $id, int $input, int $write, int $read, int $output = 50, ?array $split = null, ?string $at = null, ?string $model = null): string
{
    $usage = ['input_tokens' => $input, 'cache_creation_input_tokens' => $write, 'cache_read_input_tokens' => $read, 'output_tokens' => $output];
    if ($split !== null) {
        $usage['cache_creation'] = $split;
    }
    $record = ['type' => 'assistant', 'message' => ['id' => $id, 'role' => 'assistant', 'usage' => $usage, ...($model === null ? [] : ['model' => $model])]];

    return json_encode($at === null ? $record : [...$record, 'timestamp' => "2026-09-25T{$at}Z"]);
}

/** An assistant record calling tool $id at $at (`HH:MM:SS.mmm`, 2026-09-25 UTC). */
function cost_tool_use(string $id, string $at): string
{
    return json_encode(['type' => 'assistant', 'timestamp' => "2026-09-25T{$at}Z", 'message' => ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => $id, 'name' => 'Bash', 'input' => []]]]]);
}

/** A user record answering tool $id at $at. */
function cost_tool_result(string $id, string $at): string
{
    return json_encode(['type' => 'user', 'timestamp' => "2026-09-25T{$at}Z", 'message' => ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => $id, 'content' => 'ok']]]]);
}

/** A workflow run's transcript dir: a journal naming each agent's label and result, and each agent's transcript. */
function cost_run(array $agents): string
{
    $dir = sys_get_temp_dir() . '/pipeline-run-' . uniqid() . '/wf_abc-123';
    mkdir($dir, 0777, true);
    $journal = ['{"type":"launched"}'];
    foreach ($agents as $id => [$label, $jsonl, $result]) {
        $journal[] = json_encode(['type' => 'started', 'key' => "k{$id}", 'agentId' => $id, 'label' => $label, 'phase' => explode(':', $label)[0]]);
        if ($result !== null) {
            $journal[] = json_encode(['type' => 'result', 'key' => "k{$id}", 'agentId' => $id, 'result' => $result]);
        }
        file_put_contents("{$dir}/agent-{$id}.jsonl", $jsonl);
    }
    file_put_contents("{$dir}/journal.jsonl", implode("\n", $journal) . "\n");

    return $dir;
}

function checks_cli(string $script, array $arguments): array
{
    $process = proc_open([PHP_BINARY, __DIR__ . "/../{$script}", ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($process), 'stdout' => trim($stdout)];
}

it('weighs each call as the token audit does, once per message id', function () {
    $cost = pipeline_transcript_cost(implode("\n", [
        cost_call('m1', 3, 20000, 0),
        cost_call('m1', 3, 20000, 0),
        cost_call('m2', 1, 1000, 140000, 50, ['ephemeral_5m_input_tokens' => 0, 'ephemeral_1h_input_tokens' => 1000]),
        '{"type":"user","message":{"role":"user","content":"hi"}}',
        'not json',
        '',
    ]));

    // m1: 3 + 20000 × 1.25 (no split: all 5m) + 50 × 5 = 25253; m2: 1 + 1000 × 2 + 140000 × 0.1 + 50 × 5 = 16251
    expect($cost['calls'])->toBe(2);
    expect($cost['cost'])->toEqualWithDelta(41504, 0.001);
    expect($cost['peak'])->toBe(141001);
    expect($cost['models'])->toBe([]);
    expect(pipeline_transcript_cost(''))->toBe(['calls' => 0, 'cost' => 0.0, 'peak' => 0, 'models' => []]);
});

it('times a transcript from its earliest to its latest record, counting overlapping tool waits once', function () {
    $time = pipeline_transcript_time(implode("\n", [
        cost_call('m1', 1, 0, 0, 50, null, '10:00:00.000'),
        '{"type":"user","timestamp":"2026-09-25T10:00:10.000Z","message":{"role":"user","content":"the brief"}}',
        cost_tool_use('t1', '10:01:00.000'),
        cost_tool_use('t2', '10:01:00.500'),
        cost_tool_result('t2', '10:02:00.000'),
        cost_tool_result('t1', '10:03:00.000'),
        cost_tool_use('t3', '10:05:00.000'),
        cost_tool_result('t3', '10:06:30.000'),
        cost_tool_use('t4', '10:07:00.000'),
        cost_tool_result('t9', '10:07:30.000'),
        cost_tool_use('t5', '10:04:00.000'),
        cost_tool_result('t5', '10:03:30.000'),
        cost_call('m2', 1, 0, 0),
        '{"type":"user","timestamp":"not a time","message":{"role":"user","content":"hi"}}',
        '{"type":"user","timestamp":"","message":{"role":"user","content":"hi"}}',
        'not json',
        '',
        cost_call('m0', 1, 0, 0, 50, null, '09:59:00.250'),
    ]));

    // t1 and t2 overlap: 10:01:00 to 10:03:00 is 120 s; t3 adds 90 s; t4 (no result), t9 (no call) and t5 (result before call) add nothing
    expect($time['start'])->toEqualWithDelta((float) strtotime('2026-09-25T09:59:00Z') + 0.25, 0.001);
    expect($time['end'])->toEqualWithDelta((float) strtotime('2026-09-25T10:07:30Z'), 0.001);
    expect($time['wall'])->toEqualWithDelta(509.75, 0.001);
    expect($time['waiting'])->toEqualWithDelta(210.0, 0.001);
    expect(pipeline_transcript_time("not json\n" . cost_call('m1', 1, 0, 0)))->toBe(['start' => null, 'end' => null, 'wall' => 0.0, 'waiting' => 0.0]);
    expect(pipeline_transcript_time(''))->toBe(['start' => null, 'end' => null, 'wall' => 0.0, 'waiting' => 0.0]);
});

it('reads a run\'s steps from its journal, in the order they started', function () {
    $journal = implode("\n", [
        '{"type":"launched"}',
        '{"type":"started","key":"k1","agentId":"a1","label":"review-plan:review","phase":"review-plan"}',
        '{"type":"started","key":"k2","agentId":"a2","label":"review-plan:review","phase":"review-plan"}',
        '{"type":"result","key":"k2","agentId":"a2","result":{"status":"continued"}}',
        'not json',
    ]);

    expect(pipeline_run_journal($journal))->toBe([
        ['agent' => 'a1', 'label' => 'review-plan:review', 'result' => null],
        ['agent' => 'a2', 'label' => 'review-plan:review', 'result' => ['status' => 'continued']],
    ]);
});

it('prints the cost and minutes per step, the run\'s span and the largest step peak, always exiting 0', function () {
    $dir = cost_run([
        'a1' => ['review-plan:review', implode("\n", [
            cost_call('m1', 3, 20000, 0, 50, null, '10:00:00.000'),
            cost_tool_use('t1', '10:00:30.000'),
            cost_tool_result('t1', '10:02:00.000'),
            cost_call('m2', 1, 1000, 140000, 50, ['ephemeral_5m_input_tokens' => 0, 'ephemeral_1h_input_tokens' => 1000], '10:06:00.000'),
        ]), ['status' => 'continued']],
        'a2' => ['implement:run', implode("\n", [
            cost_call('m3', 0, 0, 300000, 2000, null, '10:07:00.000'),
            cost_tool_use('t2', '10:08:00.000'),
            cost_tool_result('t2', '10:20:00.000'),
        ]), ['status' => 'continued', 'ui' => false]],
    ]);

    // the run spans 10:00 to 10:20, a minute longer than its steps' 6.0 + 13.0: the gap between them counts
    expect(checks_cli('run_cost_cli.php', [$dir]))->toBe(['code' => 0, 'stdout' => implode("\n", [
        'review-plan:review: 0.04M over 2 calls, peak 141k, 6.0 min (1.5 waiting on tools)',
        'implement:run: 0.04M over 1 calls, peak 300k, 13.0 min (12.0 waiting on tools)',
        'run: 0.08M weighted over 2 steps in 20.0 min; largest step peak 300k (implement:run)',
    ])]);
    expect(checks_cli('run_cost_cli.php', ['/nonexistent']))->toBe(['code' => 0, 'stdout' => 'run: not measured (no step transcripts)']);
    expect(checks_cli('run_cost_cli.php', [])['code'])->toBe(0);
});

it('prints a step without timestamps as 0.0 min and spans the run over the timed steps only', function () {
    $dir = cost_run([
        'a1' => ['design:run', implode("\n", [cost_call('m1', 3, 20000, 0, 50, null, '10:00:00.000'), cost_call('m2', 3, 20000, 0, 50, null, '10:06:30.000')]), ['status' => 'continued']],
        'a2' => ['handoff:run', cost_call('m3', 0, 0, 300000, 2000), ['status' => 'continued']],
    ]);

    expect(checks_cli('run_cost_cli.php', [$dir])['stdout'])->toBe(implode("\n", [
        'design:run: 0.05M over 2 calls, peak 20k, 6.5 min (0.0 waiting on tools)',
        'handoff:run: 0.04M over 1 calls, peak 300k, 0.0 min (0.0 waiting on tools)',
        'run: 0.09M weighted over 2 steps in 6.5 min; largest step peak 300k (handoff:run)',
    ]));
});

it('weighs each token type by its model\'s factor, and a call of no known family as Opus', function () {
    $usage = ['input_tokens' => 1000, 'cache_creation_input_tokens' => 2000, 'cache_read_input_tokens' => 10000, 'output_tokens' => 100];

    // Opus: 1000 + 2000 × 1.25 + 10000 × 0.1 + 100 × 5 = 5000
    expect(pipeline_call_cost($usage, 'claude-opus-5-5'))->toEqualWithDelta(5000, 0.001);
    // Fable: 1000 × 2.5 + 2500 × 2.5 + 1000 × 1.25 + 500 × 2.5 = 11250
    expect(pipeline_call_cost($usage, 'claude-fable-5-1'))->toEqualWithDelta(11250, 0.001);
    // Sonnet: 1000 × 0.5 + 2500 × 0.5 + 1000 × 1.0 + 500 × 0.5 = 3000
    expect(pipeline_call_cost($usage, 'claude-sonnet-5-5'))->toEqualWithDelta(3000, 0.001);
    // Haiku: 1000 × 0.25 + 2500 × 0.25 + 1000 × 0.5 + 500 × 0.25 = 1500
    expect(pipeline_call_cost($usage, 'claude-haiku-4-5-20251001'))->toEqualWithDelta(1500, 0.001);
    foreach (['<synthetic>', '', 'claude-mythos-1', 'gpt-5'] as $other) {
        expect(pipeline_call_cost($usage, $other))->toEqualWithDelta(5000, 0.001);
    }
    expect(pipeline_call_cost($usage))->toEqualWithDelta(5000, 0.001);
    // a 1h write on Fable: 1000 × 2.5 + 2000 × 2 × 2.5 + 1000 × 1.25 + 500 × 2.5 = 15000
    $hour = [...$usage, 'cache_creation' => ['ephemeral_5m_input_tokens' => 0, 'ephemeral_1h_input_tokens' => 2000]];
    expect(pipeline_call_cost($hour, 'claude-fable-5-1'))->toEqualWithDelta(15000, 0.001);
});

it('reads each call\'s family from its model id, and none from a model that is not a claude- one', function () {
    expect(pipeline_model_family('claude-fable-5-1'))->toBe('fable');
    expect(pipeline_model_family('claude-haiku-4-5-20251001'))->toBe('haiku');
    expect(pipeline_model_family('claude-mythos-1'))->toBe('mythos');
    expect(pipeline_model_family('<synthetic>'))->toBeNull();
    expect(pipeline_model_family(''))->toBeNull();
});

it('names the families a step\'s calls ran on, in first-seen order, and none for a step without a model', function () {
    $dir = cost_run([
        'a1' => ['review-plan:review', implode("\n", [
            cost_call('m1', 10000, 20000, 100000, 1000, null, '10:00:00.000', 'claude-opus-5-5'),
            cost_call('m2', 0, 0, 0, 0, null, '10:01:00.000', '<synthetic>'),
            cost_call('m3', 10000, 20000, 100000, 1000, null, '10:02:00.000', 'claude-fable-5-1'),
            cost_call('m4', 10000, 20000, 100000, 1800, null, '10:03:00.000', 'claude-opus-5-5'),
        ]), ['status' => 'continued']],
        'a2' => ['handoff:run', cost_call('m5', 10000, 20000, 100000, 1000, null, '10:04:00.000'), ['status' => 'continued']],
    ]);

    // a1: 50000 (Opus) + 0 + 112500 (Fable) + 54000 = 216500; a2 (no model, as Opus): 50000; run 266500
    // (neither total sits on a .xx5 boundary, so %.2f rounding does not depend on float storage)
    expect(checks_cli('run_cost_cli.php', [$dir])['stdout'])->toBe(implode("\n", [
        'review-plan:review (opus+fable): 0.22M over 4 calls, peak 130k, 3.0 min (0.0 waiting on tools)',
        'handoff:run: 0.05M over 1 calls, peak 130k, 0.0 min (0.0 waiting on tools)',
        'run: 0.27M weighted over 2 steps in 4.0 min; largest step peak 130k (review-plan:review)',
    ]));
});

it('records a workflow\'s figures for the proof page: its dir name, its span and per step what it prints', function () {
    $steps = [
        ['label' => 'implement:run', 'calls' => 41, 'cost' => 2310000.0, 'peak' => 182000, 'models' => ['sonnet'], 'start' => 100.0, 'end' => 880.0, 'wall' => 780.0, 'waiting' => 312.0],
        ['label' => 'review-pr:review', 'calls' => 3, 'cost' => 10.0, 'peak' => 5, 'models' => [], 'start' => 900.0, 'end' => 1000.0, 'wall' => 100.0, 'waiting' => 0.0],
    ];

    expect(pipeline_run_cost_record('wf_71c2e8b3-c2a', $steps))->toBe(['workflow' => 'wf_71c2e8b3-c2a', 'span' => 900.0, 'steps' => [
        ['label' => 'implement:run', 'models' => ['sonnet'], 'cost' => 2310000.0, 'calls' => 41, 'peak' => 182000, 'wall' => 780.0, 'waiting' => 312.0],
        ['label' => 'review-pr:review', 'models' => [], 'cost' => 10.0, 'calls' => 3, 'peak' => 5, 'wall' => 100.0, 'waiting' => 0.0],
    ]]);
});

it('files the figures into the page it is given, once per workflow, and prints exactly what it printed before', function () {
    $dir = cost_run([
        'a1' => ['implement:run', implode("\n", [
            cost_call('m1', 0, 0, 300000, 2000, null, '10:07:00.000'),
            cost_tool_use('t1', '10:08:00.000'),
            cost_tool_result('t1', '10:20:00.000'),
        ]), ['status' => 'continued']],
    ]);
    $page = proof_test_page();
    $printed = checks_cli('run_cost_cli.php', [$dir])['stdout'];

    expect(checks_cli('run_cost_cli.php', [$dir, $page]))->toBe(['code' => 0, 'stdout' => $printed]);
    checks_cli('run_cost_cli.php', [$dir, $page]);

    $run = proof_read_run(dirname($page));
    expect($run['cost'])->toBe([['workflow' => 'wf_abc-123', 'span' => 780.0, 'steps' => [
        ['label' => 'implement:run', 'models' => [], 'cost' => 40000.0, 'calls' => 1, 'peak' => 300000, 'wall' => 780.0, 'waiting' => 720.0],
    ]]]);
    expect($run['revision'])->toBe(1);
    expect(file_get_contents($page))->toContain('<h2>Time and cost</h2>');
});

it('prints as before and exits 0 given a page with no run beside it, and files nothing for a run it could not measure', function () {
    $dir = cost_run(['a1' => ['implement:run', cost_call('m1', 0, 0, 1000, 10, null, '10:00:00.000'), ['status' => 'continued']]]);
    $missing = sys_get_temp_dir() . '/proof-missing-' . uniqid() . '/Deploy/pr-9-x/index.html';

    expect(checks_cli('run_cost_cli.php', [$dir, $missing]))->toBe(['code' => 0, 'stdout' => checks_cli('run_cost_cli.php', [$dir])['stdout']]);
    expect(is_dir(dirname($missing)))->toBeFalse();

    $page = proof_test_page();
    expect(checks_cli('run_cost_cli.php', ['/nonexistent', $page]))->toBe(['code' => 0, 'stdout' => 'run: not measured (no step transcripts)']);
    expect(proof_read_run(dirname($page)))->not->toHaveKey('cost');
});
