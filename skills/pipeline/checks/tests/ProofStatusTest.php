<?php

/**
 * `proof_cli.php status` and `prune` as a session runs them, as subprocesses: stdout, stderr, the exit code and the
 * files. Every page is filed into its own temp store by `proof_test_page()`.
 */
function proof_status_cli(array $arguments, array $env = []): array
{
    $process = proc_open(
        [PHP_BINARY, __DIR__ . '/../proof_cli.php', ...$arguments],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        sys_get_temp_dir(),
        [...getenv(), 'PIPELINE_PROOF_ROOT' => sys_get_temp_dir() . '/proof-status-' . uniqid(), ...$env],
    );
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

/** A fake `gh` first on PATH that answers `pr view` with `$view`, or fails when it is null. */
function proof_fake_gh(?array $view): array
{
    $bin = sys_get_temp_dir() . '/proof-gh-' . uniqid();
    mkdir($bin);
    file_put_contents("{$bin}/gh", $view === null
        ? "#!/bin/sh\necho 'HTTP 502: Bad Gateway' >&2\nexit 1\n"
        : "#!/bin/sh\necho '" . json_encode($view) . "'\n");
    chmod("{$bin}/gh", 0755);

    return ['PATH' => "{$bin}:" . getenv('PATH')];
}

it('marks a page halted with its reason, leaves revision and updatedAt, and re-renders the page and its store\'s index', function () {
    $page = proof_test_page();
    $before = proof_read_run(dirname($page));

    $result = proof_status_cli(['status', $page, 'halted', '--reason', 'CI red on the head commit']);

    $run = proof_read_run(dirname($page));
    expect($result)->toMatchArray(['code' => 0, 'stdout' => '', 'stderr' => '']);
    expect($run['status'])->toBe(['state' => 'halted', 'reason' => 'CI red on the head commit']);
    expect($run)->toMatchArray(['revision' => $before['revision'], 'updatedAt' => $before['updatedAt']]);
    expect(file_get_contents($page))->toContain('<span class="pill pill-halted">Halted</span> <span class="reason">CI red on the head commit</span>');
    expect(file_get_contents(dirname($page, 3) . '/index.html'))->toContain('pill-halted')->toContain('href="Deploy/pr-5-logs/index.html"');
});

it('drops the reason when a page goes ready, and ignores a reason given with it', function () {
    $page = proof_test_page(['status' => ['state' => 'halted', 'reason' => 'CI red']]);

    proof_status_cli(['status', $page, 'ready', '--reason', 'ignored']);

    expect(proof_read_run(dirname($page))['status'])->toBe(['state' => 'ready']);
});

it('writes nothing, says why and exits 0 for a status it cannot write', function (array $arguments, string $why) {
    $page = proof_test_page();
    $before = file_get_contents(dirname($page) . '/run.json');

    $result = proof_status_cli(array_map(fn (string $argument) => $argument === '<page>' ? $page : $argument, $arguments));

    expect($result)->toBe(['code' => 0, 'stdout' => '', 'stderr' => "proof: status not written: {$why}\n"]);
    expect(file_get_contents(dirname($page) . '/run.json'))->toBe($before);
})->with([
    'an unknown status' => [['status', '<page>', 'paused'], "unknown status 'paused': running, halted, ready, merged or closed"],
    'halted without a reason' => [['status', '<page>', 'halted'], 'halted needs --reason <text>'],
    'halted with a blank reason' => [['status', '<page>', 'halted', '--reason', '  '], 'halted needs --reason <text>'],
    'no page' => [['status', '', 'ready'], 'no page given'],
]);

it('says there is no run beside a page that has none, and creates nothing', function () {
    $missing = sys_get_temp_dir() . '/proof-missing-' . uniqid() . '/Deploy/pr-9-x/index.html';

    expect(proof_status_cli(['status', $missing, 'ready']))
        ->toBe(['code' => 0, 'stdout' => '', 'stderr' => 'proof: status not written: no run at ' . dirname($missing) . "\n"]);
    expect(is_dir(dirname($missing)))->toBeFalse();
});

it('says it cannot write a run whose file is read-only, and exits 0', function () {
    $page = proof_test_page();
    chmod(dirname($page) . '/run.json', 0444);

    $result = proof_status_cli(['status', $page, 'ready']);
    chmod(dirname($page) . '/run.json', 0644);

    expect($result)->toBe(['code' => 0, 'stdout' => '', 'stderr' => 'proof: status not written: cannot write ' . dirname($page) . "/run.json\n"]);
    expect(proof_read_run(dirname($page))['status'])->toBe(['state' => 'running']);
});

it('corrects a stale status from gh in the prune pass, keeping updatedAt and the revision', function (array $stored, array $view, array $status) {
    $page = proof_test_page(['nameWithOwner' => 'IT4WEBBV/Deploy', ...$stored]);
    $before = proof_read_run(dirname($page));

    expect(proof_status_cli(['prune'], [...proof_fake_gh($view), 'PIPELINE_PROOF_ROOT' => dirname($page, 3)])['code'])->toBe(0);

    expect(proof_read_run(dirname($page)))->toMatchArray([
        'prState' => $view['state'], 'status' => $status, 'updatedAt' => $before['updatedAt'], 'revision' => $before['revision'],
    ]);
    expect(file_get_contents($page))->toContain('pill-' . $status['state']);
})->with([
    'a draft that went ready' => [['status' => ['state' => 'running']], ['state' => 'OPEN', 'isDraft' => false], ['state' => 'ready']],
    'a stale Ready put back in draft' => [['status' => ['state' => 'ready']], ['state' => 'OPEN', 'isDraft' => true], ['state' => 'running']],
    'a halted draft keeps its reason' => [['status' => ['state' => 'halted', 'reason' => 'CI red']], ['state' => 'OPEN', 'isDraft' => true], ['state' => 'halted', 'reason' => 'CI red']],
    'a merge no session wrote' => [['status' => ['state' => 'ready']], ['state' => 'MERGED', 'isDraft' => false], ['state' => 'merged']],
    'an old run without a status' => [['status' => null], ['state' => 'CLOSED', 'isDraft' => false], ['state' => 'closed']],
]);

it('keeps the stored status and PR state when gh cannot answer', function () {
    $page = proof_test_page(['nameWithOwner' => 'IT4WEBBV/Deploy', 'status' => ['state' => 'ready']]);
    $before = file_get_contents(dirname($page) . '/run.json');

    proof_status_cli(['prune'], [...proof_fake_gh(null), 'PIPELINE_PROOF_ROOT' => dirname($page, 3)]);

    expect(file_get_contents(dirname($page) . '/run.json'))->toBe($before);
});
