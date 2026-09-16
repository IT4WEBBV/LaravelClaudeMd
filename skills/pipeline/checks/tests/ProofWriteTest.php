<?php

/**
 * Filing a run (`../proof_cli.php write`), run as a real subprocess against a throwaway store root,
 * so what is under test is what a leg actually executes: stdout, stderr, the exit code and the files.
 */

function proof_write_cli(array $payload, string $root): array
{
    $payloadPath = $root . '-payload.json';
    file_put_contents($payloadPath, json_encode($payload));

    $process = proc_open(
        [PHP_BINARY, __DIR__ . '/../proof_cli.php', 'write', $payloadPath],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        sys_get_temp_dir(),
        array_merge(getenv(), ['PIPELINE_PROOF_ROOT' => $root]),
    );

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

it('files a run named by a short title and prints the page path', function () {
    $root = sys_get_temp_dir() . '/proof-write-' . uniqid();

    $result = proof_write_cli(['repo' => 'Deploy', 'branch' => 'feature/logs', 'pr' => 5, 'title' => 'PR #5: logs that follow'], $root);

    expect($result['code'])->toBe(0);
    expect($result['stdout'])->toContain($root . '/Deploy/pr-5-logs/index.html');
    expect(is_file($root . '/Deploy/pr-5-logs/run.json'))->toBeTrue();
});

it('files nothing and says why when the title is a summary rather than a name', function () {
    // The leg writing the page must see the rejection, fix its payload and write again. A page
    // filed anyway would carry the unreadable title into the store index for good.
    $root = sys_get_temp_dir() . '/proof-write-' . uniqid();
    mkdir($root);

    $result = proof_write_cli([
        'repo' => 'Deploy',
        'branch' => 'feature/logs',
        'pr' => 5,
        'title' => str_repeat('verify-ui passed and everything it checked, ', 10),
    ], $root);

    expect($result['code'])->toBe(0);
    expect($result['stdout'])->not->toContain($root . '/Deploy/');
    expect($result['stderr'])->toContain('proof: payload rejected');
    expect($result['stderr'])->toContain('title');
    expect(is_dir($root . '/Deploy'))->toBeFalse();
});
