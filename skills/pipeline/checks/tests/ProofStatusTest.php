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
    expect(proof_test_status_runs(file_get_contents(dirname($page, 3) . '/status.js'))[0]['status'])->toBe($status['state']);
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

it('prunes a run that opened no PR two weeks after its last filing, and drops it from the index', function () {
    $root = sys_get_temp_dir() . '/proof-store-' . uniqid();
    $run = ['repo' => 'Deploy', 'title' => 'Halted before handoff', 'schema' => 2, 'status' => ['state' => 'halted', 'reason' => 'review-plan bound']];
    proof_write_run("{$root}/Deploy/feature-stale", [...$run, 'branch' => 'feature/stale'], date('c', strtotime('-15 days')));
    proof_write_run("{$root}/Deploy/feature-fresh", [...$run, 'branch' => 'feature/fresh'], date('c', strtotime('-1 day')));

    $result = proof_status_cli(['prune'], [...proof_fake_gh(null), 'PIPELINE_PROOF_ROOT' => $root]);

    expect($result)->toBe(['code' => 0, 'stdout' => "proof: pruned 1 run(s)\n", 'stderr' => '']);
    expect(is_dir("{$root}/Deploy/feature-stale"))->toBeFalse();
    expect(is_dir("{$root}/Deploy/feature-fresh"))->toBeTrue();
    expect(file_get_contents("{$root}/index.html"))->not->toContain('feature-stale/index.html')->toContain('href="Deploy/feature-fresh/index.html"');
    expect(array_column(proof_test_status_runs(file_get_contents("{$root}/status.js")), 'key'))->toBe(['Deploy/feature-fresh']);
});

it('prunes a run gh now reports merged once its last filing is more than a week old', function () {
    $root = sys_get_temp_dir() . '/proof-store-' . uniqid();
    proof_write_run("{$root}/Deploy/pr-5-logs", [
        'repo' => 'Deploy', 'nameWithOwner' => 'IT4WEBBV/Deploy', 'branch' => 'feature/logs', 'pr' => 5, 'prState' => 'OPEN',
        'title' => 'PR #5: logs that follow', 'schema' => 2, 'status' => ['state' => 'ready'],
    ], date('c', strtotime('-8 days')));

    $result = proof_status_cli(['prune'], [...proof_fake_gh(['state' => 'MERGED', 'isDraft' => false]), 'PIPELINE_PROOF_ROOT' => $root]);

    expect($result['stdout'])->toBe("proof: pruned 1 run(s)\n");
    expect(is_dir("{$root}/Deploy/pr-5-logs"))->toBeFalse();
});

it('rewrites status.js in the store the page is in when a status is written', function () {
    $page = proof_test_page();
    $root = dirname($page, 3);

    proof_status_cli(['status', $page, 'halted', '--reason', 'CI red']);

    $runs = proof_test_status_runs(file_get_contents("{$root}/status.js"));
    expect($runs)->toHaveCount(1);
    expect($runs[0])->toMatchArray(['key' => 'Deploy/pr-5-logs', 'status' => 'halted', 'revision' => 1]);
    expect(glob("{$root}/*.tmp"))->toBe([]);
});

it('prints its count and names on stderr the index it cannot write', function () {
    $root = sys_get_temp_dir() . '/proof-missing-' . uniqid();

    expect(proof_status_cli(['prune'], [...proof_fake_gh(null), 'PIPELINE_PROOF_ROOT' => $root]))
        ->toBe(['code' => 0, 'stdout' => "proof: pruned 0 run(s)\n", 'stderr' => "proof: cannot write {$root}/index.html\n"]);
});
