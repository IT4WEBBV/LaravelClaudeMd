<?php

/**
 * A throwaway repo on `feature` (with `origin/main` and `origin/HEAD`) that holds a committed spec and
 * plan, an autoflow manifest on `$leg`, and that step's `brief` already run, so its snapshot exists.
 * `$dir` is another repo to build it in: `base_repo()` (`BaseStateTest.php`) has a real `origin`.
 */
function record_fixture(string $leg, string $step, array $manifest = [], string $size = 'Architectural', ?string $dir = null): array
{
    $dir ??= rereview_repo();
    rereview_commit($dir, ['spec.md' => "# x — design\n\n**Design size:** {$size}\n", 'plan.md' => "# x Implementation Plan\n"]);
    $fixture = dispatch_fixture([
        'mode' => 'autoflow', 'worktree' => $dir, 'cursor' => ['leg' => $leg, 'status' => 'pending'],
        'artifacts' => ['spec' => 'spec.md', 'plan' => 'plan.md', 'pr' => null, 'issue' => null],
        ...$manifest,
    ]);
    $files = manifest_files($fixture['manifest']);
    expect(dispatch_cli(['brief', $fixture['manifest'], $leg, $step])['stdout'])->toContain("`{$leg}` leg, `{$step}` step");

    return [...$fixture, 'repo' => $dir, 'review' => $files['review'], 'actions' => $files['actions']];
}

function record_cli(array $fixture, string $leg, string $step, array $flags): array
{
    return dispatch_cli(['record', $fixture['manifest'], $leg, $step, ...$flags]);
}

it('records a step\'s return, exits 0, and leaves a manifest the next brief accepts', function () {
    $fixture = record_fixture('handoff', 'run');
    $head = pipeline_git($fixture['repo'], ['rev-parse', 'HEAD']);

    $result = record_cli($fixture, 'handoff', 'run', ['--status', 'continued', '--pr', '7']);

    expect($result['code'])->toBe(0);
    expect($result['json'])->toBe(['action' => 'recorded', 'leg' => 'handoff', 'step' => 'run', 'status' => 'continued', 'last_sha' => $head, 'entry' => null, 'replaced' => []]);
    expect(manifest_read($fixture['manifest']))->toMatchArray(['last_sha' => $head, 'cursor' => ['leg' => 'handoff', 'status' => 'continued']]);
    expect(manifest_read($fixture['manifest'])['artifacts']['pr'])->toBe(7);
    expect(boundary_brief($fixture, 'implement', 'run', 'handoff:run', ['--status', 'continued'])['stdout'])->toContain('`implement` leg, `run` step');
});

it('refuses with exit 1 and leaves the manifest byte-identical', function () {
    $fixture = record_fixture('handoff', 'run');
    $bytes = file_get_contents($fixture['manifest']);

    $result = record_cli($fixture, 'handoff', 'run', ['--status', 'continued']);

    expect($result['code'])->toBe(1);
    expect($result['json'])->toBe(['action' => 'refused', 'reason' => 'handoff run with --status continued needs --pr']);
    expect(file_get_contents($fixture['manifest']))->toBe($bytes);
});

it('refuses the dispatcher\'s snapshot by name, and says where the manifest is', function () {
    $fixture = record_fixture('handoff', 'run');
    $bytes = file_get_contents($fixture['before']);

    $result = dispatch_cli(['record', $fixture['before'], 'handoff', 'run', '--status', 'continued', '--pr', '7']);

    expect($result['code'])->toBe(1);
    expect($result['json']['reason'])->toBe("{$fixture['before']} is the dispatcher's snapshot; the manifest is {$fixture['manifest']}");
    expect(file_get_contents($fixture['before']))->toBe($bytes);
});

it('refuses without a snapshot, on a snapshot of another step, and on a manifest it cannot read', function () {
    $bare = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'handoff', 'status' => 'pending']]);
    expect(record_cli($bare, 'handoff', 'run', ['--status', 'continued', '--pr', '7'])['json'])
        ->toBe(['action' => 'refused', 'reason' => "no snapshot at {$bare['before']}: record follows this step's brief (next in interactive)"]);

    $fixture = record_fixture('handoff', 'run');
    expect(record_cli($fixture, 'implement', 'run', ['--status', 'continued'])['json'])
        ->toBe(['action' => 'refused', 'reason' => 'the snapshot is of the handoff run step, not implement run']);

    $missing = dispatch_cli(['record', '/nonexistent/manifest.json', 'handoff', 'run', '--status', 'continued', '--pr', '7']);
    expect($missing['code'])->toBe(1);
    expect($missing['json'])->toBe(['action' => 'refused', 'reason' => 'no readable manifest at /nonexistent/manifest.json']);
});

it('appends a review from its file, stamped with the HEAD it reviewed and the triggers of the diff', function () {
    $fixture = record_fixture('review-pr', 'review');
    mkdir($fixture['repo'] . '/database/migrations', 0777, true);
    $head = rereview_commit($fixture['repo'], ['database/migrations/2026_09_30_000000_add_x.php' => "<?php\n"]);
    file_put_contents($fixture['review'], "The migration has no down().\n\n");

    $result = record_cli($fixture, 'review-pr', 'review', ['--status', 'continued', '--review-file', $fixture['review']]);

    expect($result['json'])->toMatchArray(['action' => 'recorded', 'entry' => 0, 'last_sha' => $head]);
    $entry = manifest_read($fixture['manifest'])['gate_ledger'][0];
    expect($entry)->toMatchArray(['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'review' => 'The migration has no down().', 'annotations' => ['migration'], 'reviewed_sha' => $head]);
    expect($entry['at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
    expect($entry)->not->toHaveKey('outcome');
    expect(boundary_brief($fixture, 'review-pr', 'resolve', 'review-pr:review', ['--status', 'continued'])['stdout'])->toContain('`review-pr` leg, `resolve` step');
});

it('records a review in a checkout without origin/HEAD, against the default branch origin itself names', function () {
    $dir = base_repo();
    pipeline_git($dir, ['symbolic-ref', '-d', 'refs/remotes/origin/HEAD']);
    $fixture = record_fixture('review-pr', 'review', dir: $dir);
    mkdir($dir . '/database/migrations', 0777, true);
    rereview_commit($dir, ['database/migrations/2026_09_30_000000_add_x.php' => "<?php\n"]);
    file_put_contents($fixture['review'], 'The migration has no down().');

    $result = record_cli($fixture, 'review-pr', 'review', ['--status', 'continued', '--review-file', $fixture['review']]);

    expect($result['json'])->toMatchArray(['action' => 'recorded', 'entry' => 0]);
    expect(manifest_read($fixture['manifest'])['gate_ledger'][0]['annotations'])->toBe(['migration']);
});

it('refuses a review where neither origin/HEAD nor origin gives a base, and still records the halt', function () {
    $fixture = record_fixture('review-plan', 'review');
    pipeline_git($fixture['repo'], ['symbolic-ref', '-d', 'refs/remotes/origin/HEAD']);
    $bytes = file_get_contents($fixture['manifest']);
    file_put_contents($fixture['review'], 'Step 4 drops the link.');

    $result = record_cli($fixture, 'review-plan', 'review', ['--status', 'continued', '--review-file', $fixture['review']]);

    expect($result['code'])->toBe(1);
    expect($result['json'])->toBe(['action' => 'refused', 'reason' => 'record needs the branch\'s diff for the entry\'s annotations, and `git diff <base>...HEAD` failed']);
    expect(file_get_contents($fixture['manifest']))->toBe($bytes);
    expect(record_cli($fixture, 'review-plan', 'review', ['--status', 'halted', '--reason', 'the base does not resolve'])['json'])
        ->toMatchArray(['action' => 'recorded', 'status' => 'halted']);
});

it('refuses a review or actions file that is missing or older than the step\'s snapshot', function () {
    $fixture = record_fixture('review-plan', 'review');
    $record = fn () => record_cli($fixture, 'review-plan', 'review', ['--status', 'continued', '--review-file', $fixture['review']]);

    expect($record()['json'])->toBe(['action' => 'refused', 'reason' => "--review-file {$fixture['review']} is not a file"]);

    file_put_contents($fixture['review'], 'The review of an earlier cycle.');
    touch($fixture['review'], time() - 60);
    expect($record()['json'])->toBe(['action' => 'refused', 'reason' => "--review-file {$fixture['review']} is older than this step's snapshot: write this step's review to it first"]);

    touch($fixture['review']);
    expect($record()['json']['action'])->toBe('recorded');
});

it('completes the open review from the actions file, and finish accepts the run', function () {
    $open = ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => '2026-09-30T10:00:00Z', 'review' => 'r', 'annotations' => [], 'reviewed_sha' => str_repeat('a', 40)];
    $fixture = record_fixture('review-pr', 'resolve', ['gate_ledger' => [$open]]);
    file_put_contents($fixture['actions'], json_encode([['claim' => 'x', 'disposition' => 'recorded', 'note' => 'no edit']]));

    $result = record_cli($fixture, 'review-pr', 'resolve', ['--status', 'continued', '--actions-file', $fixture['actions'], '--issue-link', '52=closes', '--issue-link', '122=closes']);

    expect($result['json'])->toMatchArray(['action' => 'recorded', 'entry' => 0]);
    expect(manifest_read($fixture['manifest'])['gate_ledger'][0])->toBe([
        ...$open,
        'actions' => [['claim' => 'x', 'disposition' => 'recorded', 'note' => 'no edit']],
        'issue_links' => [['issue' => 52, 'outcome' => 'closes'], ['issue' => 122, 'outcome' => 'closes']],
        'outcome' => 'continued',
    ]);
    expect(dispatch_cli(['finish', $fixture['manifest'], '{"action":"done"}'])['json'])->toBe(['action' => 'done']);
});

it('sets the spec and removes the plan for an Architectural spec step, and refuses a spec that is not committed', function () {
    $fixture = record_fixture('design', 'spec');
    file_put_contents($fixture['repo'] . '/draft.md', "# draft — design\n");

    expect(record_cli($fixture, 'design', 'spec', ['--status', 'continued', '--spec', 'draft.md'])['json'])
        ->toBe(['action' => 'refused', 'reason' => 'the spec draft.md does not exist at HEAD (`git cat-file -e HEAD:draft.md` failed): commit it first']);

    expect(record_cli($fixture, 'design', 'spec', ['--status', 'continued', '--spec', $fixture['repo'] . '/spec.md'])['json']['action'])->toBe('recorded');
    expect(manifest_read($fixture['manifest'])['artifacts'])->toBe(['spec' => 'spec.md', 'pr' => null, 'issue' => null]);
    expect(boundary_brief($fixture, 'design', 'plan', 'design:spec', ['--status', 'continued', '--size', 'Architectural'])['stdout'])->toContain('`design` leg, `plan` step');
});

it('requires the plan of a Bounded spec step, by the size of the spec it is given', function () {
    $fixture = record_fixture('design', 'spec', size: 'Bounded');

    expect(record_cli($fixture, 'design', 'spec', ['--status', 'continued', '--spec', 'spec.md'])['json'])
        ->toBe(['action' => 'refused', 'reason' => 'a Bounded spec needs --plan: its spec step commits the plan too']);
    expect(record_cli($fixture, 'design', 'spec', ['--status', 'continued', '--spec', 'spec.md', '--plan', 'plan.md'])['json']['action'])->toBe('recorded');
});

it('records a plan gap on an Architectural design and an escalation on a Bounded one', function (string $size, array $entry) {
    $fixture = record_fixture('implement', 'run', size: $size);

    $result = record_cli($fixture, 'implement', 'run', ['--status', 'plan-insufficient', '--reason', '--force is missing from the plan']);

    expect($result['json'])->toMatchArray(['action' => 'recorded', 'status' => 'plan-insufficient', 'entry' => 0]);
    expect(manifest_read($fixture['manifest'])['gate_ledger'][0])->toMatchArray([...$entry, 'leg' => 'implement', 'reason' => '--force is missing from the plan']);
})->with([
    'Architectural' => ['Architectural', ['gate' => 'plan-approval', 'cycle' => 1, 'outcome' => 'looped-back']],
    'Bounded' => ['Bounded', ['gate' => 'design-size', 'outcome' => 'escalated']],
]);

it('records a halt outside a git repository, and refuses any other status there by naming the git call', function () {
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'handoff', 'status' => 'pending']]);
    dispatch_cli(['brief', $fixture['manifest'], 'handoff', 'run']);

    expect(record_cli($fixture, 'handoff', 'run', ['--status', 'continued', '--pr', '7'])['json'])
        ->toBe(['action' => 'refused', 'reason' => 'record needs the worktree\'s HEAD for last_sha, and `git rev-parse HEAD` failed']);

    $halt = record_cli($fixture, 'handoff', 'run', ['--status', 'halted', '--reason', 'gh is not logged in']);
    expect($halt['code'])->toBe(0);
    expect($halt['json'])->toMatchArray(['action' => 'recorded', 'status' => 'halted']);
    expect(manifest_read($fixture['manifest'])['cursor'])->toBe(['leg' => 'handoff', 'status' => 'halted', 'reason' => 'gh is not logged in']);
});

it('refuses a proof page that is not a file', function () {
    $fixture = record_fixture('verify-ui', 'run');

    expect(record_cli($fixture, 'verify-ui', 'run', ['--status', 'continued', '--proof', '/nonexistent/index.html'])['json'])
        ->toBe(['action' => 'refused', 'reason' => '--proof /nonexistent/index.html is not a file']);

    file_put_contents($fixture['dir'] . '/index.html', '<html>');
    expect(record_cli($fixture, 'verify-ui', 'run', ['--status', 'continued', '--proof', $fixture['dir'] . '/index.html'])['json'])->toMatchArray(['action' => 'recorded', 'entry' => 0]);
    expect(manifest_read($fixture['manifest'])['artifacts']['proof'])->toBe($fixture['dir'] . '/index.html');
});

it('replaces a hand edit and an earlier record, and names what it did not keep', function () {
    $fixture = record_fixture('handoff', 'run');
    dispatch_leg_writes($fixture['manifest'], fn (array $m) => [...$m, 'branch' => 'other', 'artifacts' => [...$m['artifacts'], 'pr' => 99]]);

    $result = record_cli($fixture, 'handoff', 'run', ['--status', 'continued', '--pr', '7']);

    expect($result['json']['replaced'])->toEqualCanonicalizing(['branch', 'artifacts']);
    expect(manifest_read($fixture['manifest']))->toMatchArray(['branch' => 'feature/x']);
    expect(manifest_read($fixture['manifest'])['artifacts']['pr'])->toBe(7);
    expect(record_cli($fixture, 'handoff', 'run', ['--status', 'continued', '--pr', '8'])['json']['replaced'])->toEqualCanonicalizing(['artifacts', 'last_sha', 'cursor.status']);
    expect(manifest_read($fixture['manifest'])['artifacts']['pr'])->toBe(8);
});

it('refuses a review that cannot be written as JSON, and leaves the manifest as it was', function () {
    $fixture = record_fixture('review-plan', 'review');
    $bytes = file_get_contents($fixture['manifest']);
    file_put_contents($fixture['review'], "Not UTF-8: \xB1\x31");

    $result = record_cli($fixture, 'review-plan', 'review', ['--status', 'continued', '--review-file', $fixture['review']]);

    expect($result['code'])->toBe(1);
    expect($result['json']['reason'])->toStartWith('the result cannot be written as JSON: ');
    expect(file_get_contents($fixture['manifest']))->toBe($bytes);
});

it('refuses when the write does not land, and prints only the JSON line', function () {
    $fixture = record_fixture('handoff', 'run');
    chmod($fixture['manifest'], 0444);

    $result = record_cli($fixture, 'handoff', 'run', ['--status', 'continued', '--pr', '7']);
    chmod($fixture['manifest'], 0644);

    expect($result['code'])->toBe(1);
    expect($result['json'])->toBe(['action' => 'refused', 'reason' => "the write did not land at {$fixture['manifest']}"]);
    expect(manifest_read($fixture['manifest'])['cursor'])->toBe(['leg' => 'handoff', 'status' => 'pending']);
});

it('records an interactive step, and returned accepts it', function () {
    $dir = rereview_repo();
    $fixture = dispatch_fixture(['mode' => 'interactive', 'worktree' => $dir]);
    $files = manifest_files($fixture['manifest']);
    expect(dispatch_cli(['next', $fixture['manifest']])['json'])->toMatchArray(['action' => 'dispatch', 'leg' => 'review-plan', 'step' => 'review']);
    file_put_contents($files['review'], 'Step 4 drops the link.');

    expect(record_cli($fixture, 'review-plan', 'review', ['--status', 'continued', '--review-file', $files['review']])['json']['action'])->toBe('recorded');
    expect(dispatch_cli(['returned', $fixture['manifest'], $fixture['diff']])['json'])->toMatchArray(['action' => 'dispatch', 'leg' => 'review-plan', 'step' => 'resolve']);
});

it('refuses a record it cannot parse as a usage error', function (array $arguments) {
    $result = dispatch_cli(['record', ...$arguments]);

    expect($result['code'])->toBe(1);
    expect($result['stdout'])->toBe('');
})->with([
    'no status' => [['/tmp/m.json', 'handoff', 'run', '--pr', '7']],
    'no step' => [['/tmp/m.json', 'handoff', '--status', 'continued']],
    'a flag without a value' => [['/tmp/m.json', 'handoff', 'run', '--status', 'continued', '--pr']],
    'a flag record does not know' => [['/tmp/m.json', 'handoff', 'run', '--status', 'continued', '--number', '7']],
    'a flag given twice' => [['/tmp/m.json', 'handoff', 'run', '--status', 'continued', '--pr', '7', '--pr', '8']],
]);

it('writes the suite with the worktree\'s tree key and changes nothing else', function () {
    $fixture = record_fixture('implement', 'run');
    $before = manifest_read($fixture['manifest']);

    $result = dispatch_cli(['suite', $fixture['manifest'], '--outcome', 'green', '--passed', '104', '--failed', '0']);

    expect($result['code'])->toBe(0);
    $suite = manifest_read($fixture['manifest'])['suite'];
    expect($result['json'])->toBe(['action' => 'recorded', 'suite' => $suite]);
    expect($suite)->toMatchArray(['tree' => pipeline_tree_key($fixture['repo']), 'outcome' => 'green', 'passed' => 104, 'failed' => 0]);
    expect($suite['at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
    expect(array_diff_key(manifest_read($fixture['manifest']), ['suite' => true]))->toBe($before);
});

it('keeps the suite a step recorded in the record that follows, and the next brief accepts both', function () {
    $fixture = record_fixture('implement', 'run');
    dispatch_cli(['suite', $fixture['manifest'], '--outcome', 'red', '--passed', '100', '--failed', '4']);
    $suite = manifest_read($fixture['manifest'])['suite'];
    file_put_contents($fixture['stepDiff'], '');

    expect(record_cli($fixture, 'implement', 'run', ['--status', 'continued'])['json'])->toMatchArray(['action' => 'recorded', 'replaced' => []]);
    expect(manifest_read($fixture['manifest'])['suite'])->toBe($suite);
    expect(boundary_brief($fixture, 'review-pr', 'review', 'implement:run', ['--status', 'continued', '--ui', 'false'])['stdout'])->toContain('`review-pr` leg, `review` step');
});

it('refuses a green suite with failures, the snapshot\'s path, and a tree key it cannot compute', function () {
    $fixture = record_fixture('implement', 'run');
    $bytes = file_get_contents($fixture['manifest']);

    $green = dispatch_cli(['suite', $fixture['manifest'], '--outcome', 'green', '--passed', '100', '--failed', '4']);
    expect($green['code'])->toBe(1);
    expect($green['json'])->toBe(['action' => 'refused', 'reason' => 'a green suite has no failures: --failed is 4']);

    expect(dispatch_cli(['suite', $fixture['before'], '--outcome', 'green', '--passed', '104', '--failed', '0'])['json']['reason'])
        ->toBe("{$fixture['before']} is the dispatcher's snapshot; the manifest is {$fixture['manifest']}");
    expect(file_get_contents($fixture['manifest']))->toBe($bytes);

    $noRepo = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'implement', 'status' => 'pending']]);
    $result = dispatch_cli(['suite', $noRepo['manifest'], '--outcome', 'green', '--passed', '104', '--failed', '0']);
    expect($result['code'])->toBe(1);
    expect($result['json']['reason'])->toStartWith('cannot compute the tree key, so this run is not reusable: run the suite again next time (');
    expect(manifest_read($noRepo['manifest']))->not->toHaveKey('suite');
});

it('refuses a suite it cannot parse as a usage error', function (array $arguments) {
    $result = dispatch_cli(['suite', ...$arguments]);

    expect($result['code'])->toBe(1);
    expect($result['stdout'])->toBe('');
})->with([
    'no manifest' => [[]],
    'no counts' => [['/tmp/m.json', '--outcome', 'green']],
    'an outcome that is neither' => [['/tmp/m.json', '--outcome', 'yellow', '--passed', '1', '--failed', '0']],
    'a count that is no number' => [['/tmp/m.json', '--outcome', 'green', '--passed', 'all', '--failed', '0']],
    'a flag given twice' => [['/tmp/m.json', '--outcome', 'green', '--outcome', 'red', '--passed', '1', '--failed', '0']],
]);
