<?php

function peak_call(string $id, int $input, int $write, int $read): string
{
    return json_encode(['type' => 'assistant', 'message' => ['id' => $id, 'role' => 'assistant', 'usage' => [
        'input_tokens' => $input, 'cache_creation_input_tokens' => $write, 'cache_read_input_tokens' => $read, 'output_tokens' => 50,
    ]]]);
}

function peak_projects(string $agentId, string $jsonl): string
{
    $root = sys_get_temp_dir() . '/pipeline-peak-' . uniqid();
    mkdir("{$root}/-Users-x-repo/session-1/subagents", 0777, true);
    file_put_contents("{$root}/-Users-x-repo/session-1/subagents/agent-{$agentId}.jsonl", $jsonl);

    return $root;
}

function peak_cli(array $arguments): array
{
    $process = proc_open([PHP_BINARY, __DIR__ . '/../engine_peak_cli.php', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($process), 'stdout' => trim($stdout)];
}

it('takes the peak over deduplicated assistant calls, as the audit does', function () {
    $jsonl = implode("\n", [
        peak_call('m1', 3, 20000, 0),
        peak_call('m1', 3, 20000, 0),
        peak_call('m2', 1, 1000, 140000),
        '{"type":"user","message":{"role":"user","content":"hi"}}',
        'not json',
        '',
    ]);

    expect(pipeline_transcript_peak($jsonl))->toBe(['calls' => 2, 'peak' => 141001]);
    expect(pipeline_transcript_peak(peak_call('m1', 0, 1000, 0) . "\n" . peak_call('m1', 0, 5000, 0)))->toBe(['calls' => 1, 'peak' => 5000]);
    expect(pipeline_transcript_peak(''))->toBe(['calls' => 0, 'peak' => 0]);
});

it('judges the peak against 150k as an annotation', function () {
    expect(pipeline_engine_peak_line('abc', ['calls' => 3, 'peak' => 149999]))->toBe('engine abc: peak 149k over 3 calls, within the 150k invariant');
    expect(pipeline_engine_peak_line('abc', ['calls' => 9, 'peak' => 150000]))->toBe('engine abc: peak 150k over 9 calls, over the 150k invariant (annotation, not a halt)');
    expect(pipeline_engine_peak_line('abc', null))->toBe('engine abc: not measured (no transcript)');
    expect(pipeline_engine_peak_line('abc', ['calls' => 0, 'peak' => 0]))->toBe('engine abc: not measured (no transcript)');
});

it('finds a dispatcher transcript by agent id and reports it, always exiting 0', function () {
    $root = peak_projects('a3a2b25333125d540', implode("\n", [peak_call('m1', 3, 20000, 0), peak_call('m2', 1, 1000, 140000)]));

    expect(peak_cli(['a3a2b25333125d540', '--projects-dir', $root]))
        ->toBe(['code' => 0, 'stdout' => 'engine a3a2b25333125d540: peak 141k over 2 calls, within the 150k invariant']);
    expect(peak_cli(['agent-a3a2b25333125d540', '--projects-dir', $root])['stdout'])->toContain('peak 141k');
    expect(peak_cli(['ffff', '--projects-dir', $root]))->toBe(['code' => 0, 'stdout' => 'engine ffff: not measured (no transcript)']);
    expect(peak_cli(['../x', '--projects-dir', $root])['stdout'])->toContain('not measured');
    expect(peak_cli([])['code'])->toBe(0);
});
