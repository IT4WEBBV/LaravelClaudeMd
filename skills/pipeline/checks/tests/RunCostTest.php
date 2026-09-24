<?php

function cost_call(string $id, int $input, int $write, int $read, int $output = 50, ?array $split = null): string
{
    $usage = ['input_tokens' => $input, 'cache_creation_input_tokens' => $write, 'cache_read_input_tokens' => $read, 'output_tokens' => $output];
    if ($split !== null) {
        $usage['cache_creation'] = $split;
    }

    return json_encode(['type' => 'assistant', 'message' => ['id' => $id, 'role' => 'assistant', 'usage' => $usage]]);
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
    expect(pipeline_transcript_cost(''))->toBe(['calls' => 0, 'cost' => 0.0, 'peak' => 0]);
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

it('prints the cost per step and the largest step peak, always exiting 0', function () {
    $dir = cost_run([
        'a1' => ['review-plan:review', implode("\n", [cost_call('m1', 3, 20000, 0), cost_call('m2', 1, 1000, 140000, 50, ['ephemeral_5m_input_tokens' => 0, 'ephemeral_1h_input_tokens' => 1000])]), ['status' => 'continued']],
        'a2' => ['implement:run', cost_call('m3', 0, 0, 300000, 2000), ['status' => 'continued', 'ui' => false]],
    ]);

    expect(checks_cli('run_cost_cli.php', [$dir]))->toBe(['code' => 0, 'stdout' => implode("\n", [
        'review-plan:review: 0.04M over 2 calls, peak 141k',
        'implement:run: 0.04M over 1 calls, peak 300k',
        'run: 0.08M weighted over 2 steps; largest step peak 300k (implement:run)',
    ])]);
    expect(checks_cli('run_cost_cli.php', ['/nonexistent']))->toBe(['code' => 0, 'stdout' => 'run: not measured (no step transcripts)']);
    expect(checks_cli('run_cost_cli.php', [])['code'])->toBe(0);
});
