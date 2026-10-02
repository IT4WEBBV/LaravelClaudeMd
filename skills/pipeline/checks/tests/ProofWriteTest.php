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

/** A payload an agent's `write` may file into `Deploy/pr-5-logs`. */
function proof_write_payload(array $overrides = []): array
{
    return [
        'repo' => 'Deploy', 'branch' => 'feature/logs', 'pr' => 5, 'title' => 'PR #5: logs that follow',
        'clientSummary' => 'De servicelogboeken lopen nu live mee.',
        'explainer' => ['problem' => 'The log stopped at its last line.', 'solution' => 'It keeps following now.'],
        ...$overrides,
    ];
}

function proof_write_stored(string $root): array
{
    return json_decode((string) file_get_contents("{$root}/Deploy/pr-5-logs/run.json"), true);
}

it('files a run named by a short title and prints the page path', function () {
    $root = sys_get_temp_dir() . '/proof-write-' . uniqid();

    $result = proof_write_cli(proof_write_payload(), $root);

    expect($result['code'])->toBe(0);
    expect($result['stdout'])->toContain($root . '/Deploy/pr-5-logs/index.html');
    expect(is_file($root . '/Deploy/pr-5-logs/run.json'))->toBeTrue();
});

it('files nothing and says why when the title is a summary rather than a name', function () {
    // The leg writing the page must see the rejection, fix its payload and write again. A page
    // filed anyway would carry the unreadable title into the store index for good.
    $root = sys_get_temp_dir() . '/proof-write-' . uniqid();
    mkdir($root);

    $result = proof_write_cli(proof_write_payload(['title' => str_repeat('verify-ui passed and everything it checked, ', 10)]), $root);

    expect($result['code'])->toBe(0);
    expect($result['stdout'])->not->toContain($root . '/Deploy/');
    expect($result['stderr'])->toContain('proof: payload rejected');
    expect($result['stderr'])->toContain('title');
    expect(is_dir($root . '/Deploy'))->toBeFalse();
});

it('keeps what an earlier write filed when a later one leaves it out', function () {
    $root = sys_get_temp_dir() . '/proof-write-' . uniqid();
    proof_write_cli(proof_write_payload(['headline' => 'Logs follow', 'openQuestions' => ['a', 'b']]), $root);

    $result = proof_write_cli(['repo' => 'Deploy', 'branch' => 'feature/logs', 'pr' => 5, 'openQuestions' => ['c']], $root);

    expect($result['stdout'])->toContain("{$root}/Deploy/pr-5-logs/index.html");
    expect(proof_write_stored($root))->toMatchArray([
        'title' => 'PR #5: logs that follow', 'headline' => 'Logs follow', 'openQuestions' => ['c'],
        'clientSummary' => 'De servicelogboeken lopen nu live mee.', 'schema' => 2,
    ]);
});

it('files nothing for a run without a client summary, and says so', function () {
    // The root exists, as in the title test: a `write` that files the run writes the store index into it.
    $root = sys_get_temp_dir() . '/proof-write-' . uniqid();
    mkdir($root);

    $result = proof_write_cli(proof_write_payload(['clientSummary' => null]), $root);

    expect($result['stdout'])->not->toContain("{$root}/Deploy/");
    expect($result['stderr'])->toContain('proof: payload rejected')->toContain('clientSummary is missing');
    expect(is_dir("{$root}/Deploy"))->toBeFalse();
});

it('stores the tests the run\'s branch adds, from git in its worktree, and ignores a payload\'s own list', function () {
    $root = sys_get_temp_dir() . '/proof-write-' . uniqid();
    $worktree = base_repo();
    mkdir("{$worktree}/tests/Feature", 0777, true);
    rereview_commit($worktree, ['tests/Feature/LogsTest.php' => "<?php\n\nit('follows the log', function () {\n    expect(true)->toBeTrue();\n});\n"]);

    proof_write_cli(proof_write_payload(['worktree' => $worktree, 'base' => 'main', 'addedTests' => [['file' => 'invented.php', 'cases' => []]]]), $root);

    expect(proof_write_stored($root)['addedTests'])->toBe([[
        'file' => 'tests/Feature/LogsTest.php',
        'cases' => [['name' => 'follows the log', 'change' => 'added']],
    ]]);
});

it('keeps the stored test list and says why when the worktree is gone', function () {
    $root = sys_get_temp_dir() . '/proof-write-' . uniqid();
    $worktree = base_repo();
    mkdir("{$worktree}/tests", 0777, true);
    rereview_commit($worktree, ['tests/LogsTest.php' => "<?php\n\nit('follows', function () {\n});\n"]);
    proof_write_cli(proof_write_payload(['worktree' => $worktree, 'base' => 'main']), $root);

    $result = proof_write_cli(proof_write_payload(['worktree' => "{$worktree}-removed"]), $root);

    expect($result['stderr'])->toContain('proof: tests not extracted: no worktree at');
    expect(proof_write_stored($root)['addedTests'][0]['file'])->toBe('tests/LogsTest.php');
});

it('keeps a carried shot\'s file next to a newly ingested one, named by its content', function () {
    $root = sys_get_temp_dir() . '/proof-write-' . uniqid();
    mkdir($root);
    file_put_contents("{$root}-one.png", 'first screenshot');
    file_put_contents("{$root}-two.png", 'second screenshot');
    $defect = ['title' => 'Log stops', 'route' => '/logs', 'state' => 'defect'];
    proof_write_cli(proof_write_payload(['shots' => [$defect], 'shotSources' => ["{$root}-one.png"]]), $root);
    $carried = proof_write_stored($root)['shots'][0]['file'];

    proof_write_cli(proof_write_payload([
        'shots' => [[...$defect, 'file' => $carried], ['title' => 'Log follows', 'route' => '/logs', 'state' => 'after']],
        'shotSources' => [null, "{$root}-two.png"],
    ]), $root);

    $shots = proof_write_stored($root)['shots'];
    expect($carried)->toBe('shots/01-logs-' . substr(sha1('first screenshot'), 0, 8) . '.png');
    expect($shots[0]['file'])->toBe($carried);
    expect($shots[1]['file'])->toBe('shots/02-logs-' . substr(sha1('second screenshot'), 0, 8) . '.png');
    expect(file_get_contents("{$root}/Deploy/pr-5-logs/{$carried}"))->toBe('first screenshot');
    expect(array_key_exists('shotSources', proof_write_stored($root)))->toBeFalse();
});

it('refuses a carried shot re-sent without its file, and keeps the stored one', function () {
    $root = sys_get_temp_dir() . '/proof-write-' . uniqid();
    mkdir($root);
    file_put_contents("{$root}-one.png", 'first screenshot');
    $defect = ['title' => 'Log stops', 'route' => '/logs', 'state' => 'defect'];
    proof_write_cli(proof_write_payload(['shots' => [$defect], 'shotSources' => ["{$root}-one.png"]]), $root);

    $result = proof_write_cli(proof_write_payload(['shots' => [$defect], 'shotSources' => [null]]), $root);

    expect($result['stdout'])->not->toContain("{$root}/Deploy/");
    expect($result['stderr'])->toContain('shot 1 has no file and no source');
    expect(proof_write_stored($root)['shots'][0]['file'])->toStartWith('shots/01-logs-');
});

it('refuses to finalise a run filed before shot states until its shots carry them, then renders the new page', function () {
    $root = sys_get_temp_dir() . '/proof-write-' . uniqid();
    mkdir("{$root}/Deploy/pr-5-logs", 0777, true);
    file_put_contents("{$root}/Deploy/pr-5-logs/run.json", json_encode([
        'repo' => 'Deploy', 'branch' => 'feature/logs', 'pr' => 5, 'title' => 'PR #5: logs that follow', 'schema' => 1,
        'shots' => [['title' => 'Log follows', 'route' => '/logs', 'file' => 'shots/01-logs.png']],
    ]));

    $refused = proof_write_cli(proof_write_payload(), $root);
    expect($refused['stderr'])->toContain('shot 1 has no state: before, after or defect');

    $filed = proof_write_cli(proof_write_payload(['shots' => [['title' => 'Log follows', 'route' => '/logs', 'file' => 'shots/01-logs.png', 'state' => 'after']]]), $root);
    expect($filed['stdout'])->toContain('index.html');
    expect(file_get_contents("{$root}/Deploy/pr-5-logs/index.html"))->toContain('id="client-summary"')->toContain('ribbon-after');
});

it('files revision 1 and Running first, then counts each write and never takes a payload\'s status or cost', function () {
    $root = sys_get_temp_dir() . '/proof-write-' . uniqid();
    proof_write_cli(proof_write_payload(), $root);
    expect(proof_write_stored($root))->toMatchArray(['revision' => 1, 'status' => ['state' => 'running']]);

    // A halt recorded since: a later agent write keeps it (spec Assumption 4).
    file_put_contents("{$root}/Deploy/pr-5-logs/run.json", proof_run_json([...proof_write_stored($root), 'status' => ['state' => 'halted', 'reason' => 'CI red']]));
    proof_write_cli(proof_write_payload(['revision' => 40, 'status' => ['state' => 'merged'], 'cost' => [['workflow' => 'wf_x']]]), $root);

    expect(proof_write_stored($root))->toMatchArray(['revision' => 2, 'status' => ['state' => 'halted', 'reason' => 'CI red']]);
    expect(proof_write_stored($root))->not->toHaveKey('cost');
});

it('leaves status.js beside the index at every write, naming the run, its status and its revision', function () {
    $root = sys_get_temp_dir() . '/proof-write-' . uniqid();

    proof_write_cli(proof_write_payload(), $root);
    $first = proof_test_status_runs(file_get_contents("{$root}/status.js"));
    proof_write_cli(proof_write_payload(), $root);
    $second = proof_test_status_runs(file_get_contents("{$root}/status.js"));

    expect($first)->toHaveCount(1);
    expect($first[0])->toMatchArray(['key' => 'Deploy/pr-5-logs', 'status' => 'running', 'revision' => 1]);
    expect($second[0])->toMatchArray(['key' => 'Deploy/pr-5-logs', 'status' => 'running', 'revision' => 2]);
    expect($second[0]['hash'])->not->toBe($first[0]['hash']);
    expect(file_get_contents("{$root}/index.html"))->toContain('data-hash="' . $second[0]['hash'] . '"');
    expect(glob("{$root}/*.tmp"))->toBe([]);
});

it('says which store file it cannot write, and leaves no temporary file', function () {
    $root = sys_get_temp_dir() . '/proof-store-' . uniqid();
    mkdir("{$root}/status.js", 0777, true); // a directory where the file goes: the rename over it fails

    expect(proof_store_index($root))->toBe("cannot write {$root}/status.js");
    expect(glob("{$root}/*.tmp"))->toBe([]);
    expect(is_file("{$root}/index.html"))->toBeTrue();

    $missing = sys_get_temp_dir() . '/proof-missing-' . uniqid();
    expect(proof_store_index($missing))->toBe("cannot write {$missing}/index.html");
    expect(is_dir($missing))->toBeFalse();
});
