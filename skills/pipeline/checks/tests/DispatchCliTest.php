<?php

/** A worktree-shaped temp dir with a manifest and an empty diff; nothing touches a real checkout. */
function dispatch_fixture(array $manifest = []): array
{
    $dir = sys_get_temp_dir() . '/pipeline-dispatch-' . uniqid();
    mkdir($dir . '/.claude/pipeline', 0777, true);
    $path = $dir . '/.claude/pipeline/feature-x.json';
    manifest_write($path, [
        'branch' => 'feature/x', 'worktree' => $dir, 'mode' => 'auto',
        'cursor' => ['leg' => 'review-plan', 'status' => 'pending'],
        'artifacts' => ['spec' => null, 'plan' => null, 'pr' => null, 'issue' => null],
        'gate_ledger' => [],
        ...$manifest,
    ]);
    file_put_contents($dir . '/pipeline.diff', '');

    return ['dir' => $dir, 'manifest' => $path, 'diff' => $dir . '/pipeline.diff', 'brief' => $dir . '/.claude/pipeline/feature-x.brief.md', 'before' => $dir . '/.claude/pipeline/feature-x.before.json'];
}

function dispatch_cli(array $arguments, array $env = []): array
{
    $process = proc_open(
        [PHP_BINARY, __DIR__ . '/../dispatch_cli.php', ...$arguments],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        [...getenv(), 'PIPELINE_NO_OPEN' => '0', ...$env],
    );
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($process), 'json' => json_decode($stdout, true), 'stdout' => $stdout];
}

function dispatch_ui_diff(): string
{
    return "+++ b/resources/views/x.blade.php\n@@ -1,0 +1,1 @@\n+<div>hi</div>\n";
}

/** Play a leg: change the manifest the way the leg would. */
function dispatch_leg_writes(string $path, callable $change): void
{
    manifest_write($path, $change(manifest_read($path)));
}

it('writes the brief and the snapshot and prints one dispatch line', function () {
    $fixture = dispatch_fixture(['mode' => 'interactive']);
    $result = dispatch_cli(['next', $fixture['manifest']]);

    expect($result['code'])->toBe(0);
    expect($result['json'])->toMatchArray(['action' => 'dispatch', 'leg' => 'review-plan', 'step' => 'review', 'inline' => false]);
    expect($result['json']['prompt'])->toContain($fixture['brief']);
    expect(file_get_contents($fixture['brief']))->toContain('/critique plan');
    expect(manifest_read($fixture['before']))->toBe(manifest_read($fixture['manifest']));
});

it('routes a review step to its resolve step and briefs it', function () {
    $fixture = dispatch_fixture();
    dispatch_cli(['next', $fixture['manifest']]);
    dispatch_leg_writes($fixture['manifest'], fn (array $m) => [
        ...$m,
        'cursor' => [...$m['cursor'], 'status' => 'continued'],
        'gate_ledger' => [['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r']],
    ]);

    $result = dispatch_cli(['returned', $fixture['manifest'], $fixture['diff']]);

    expect($result['json'])->toMatchArray(['action' => 'dispatch', 'leg' => 'review-plan', 'step' => 'resolve']);
    expect(manifest_read($fixture['manifest'])['cursor'])->toBe(['leg' => 'review-plan', 'status' => 'pending']);
    expect(file_get_contents($fixture['brief']))->toContain('the open review: `gate_ledger[0]`');
});

it('halts and records the reason when a leg moves the cursor', function () {
    $fixture = dispatch_fixture();
    dispatch_cli(['next', $fixture['manifest']]);
    dispatch_leg_writes($fixture['manifest'], fn (array $m) => [...$m, 'cursor' => ['leg' => 'handoff', 'status' => 'continued']]);

    $result = dispatch_cli(['returned', $fixture['manifest'], $fixture['diff']]);

    expect($result['json']['action'])->toBe('halt');
    expect($result['json']['reason'])->toContain('cursor.leg');
    expect(manifest_read($fixture['manifest'])['cursor'])->toMatchArray(['leg' => 'review-plan', 'status' => 'halted']);
});

it('retries an empty review once, then halts', function () {
    $fixture = dispatch_fixture();
    dispatch_cli(['next', $fixture['manifest']]);

    $retry = dispatch_cli(['returned', $fixture['manifest'], $fixture['diff']]);
    expect($retry['json'])->toMatchArray(['action' => 'retry', 'leg' => 'review-plan', 'step' => 'review']);
    expect(manifest_read($fixture['manifest'])['cursor']['retried'])->toBeTrue();

    expect(dispatch_cli(['returned', $fixture['manifest'], $fixture['diff']])['json']['action'])->toBe('halt');
});

it('halts when the diff file is missing', function () {
    $fixture = dispatch_fixture();
    dispatch_cli(['next', $fixture['manifest']]);

    expect(dispatch_cli(['returned', $fixture['manifest'], $fixture['dir'] . '/missing.diff'])['json']['action'])->toBe('halt');
});

it('runs design inline outside auto', function () {
    $fixture = dispatch_fixture(['mode' => 'interactive', 'cursor' => ['leg' => 'design', 'status' => 'pending']]);

    expect(dispatch_cli(['next', $fixture['manifest']])['json'])->toMatchArray(['leg' => 'design', 'inline' => true]);
});

it('reads the design size from the spec to route plan-insufficient', function (string $header, string $action) {
    $fixture = dispatch_fixture(['cursor' => ['leg' => 'implement', 'status' => 'pending'], 'artifacts' => ['spec' => 'spec.md', 'plan' => null, 'pr' => 7, 'issue' => null]]);
    file_put_contents($fixture['dir'] . '/spec.md', "# x — design\n\n{$header}\n");
    dispatch_cli(['next', $fixture['manifest']]);
    dispatch_leg_writes($fixture['manifest'], fn (array $m) => [
        ...$m,
        'cursor' => [...$m['cursor'], 'status' => 'plan-insufficient', 'reason' => 'migration'],
        'gate_ledger' => [['gate' => 'design-size', 'leg' => 'implement', 'at' => '2026-09-22T12:00:00Z', 'reason' => 'migration', 'outcome' => 'escalated']],
    ]);

    expect(dispatch_cli(['returned', $fixture['manifest'], $fixture['diff']])['json']['action'])->toBe($action);
})->with([
    'Bounded grows' => ['**Design size:** Bounded', 'dispatch'],
    'Architectural needs a plan-approval loop-back, not a design-size entry' => ['**Design size:** Architectural', 'halt'],
]);

it('records a finished run as done and does not re-dispatch it', function () {
    $open = ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r'];
    $fixture = dispatch_fixture(['cursor' => ['leg' => 'review-pr', 'status' => 'pending'], 'gate_ledger' => [$open]]);
    dispatch_cli(['next', $fixture['manifest']]);
    dispatch_leg_writes($fixture['manifest'], fn (array $m) => [
        ...$m,
        'cursor' => [...$m['cursor'], 'status' => 'continued'],
        'gate_ledger' => [[...$open, 'actions' => [], 'outcome' => 'continued']],
    ]);

    expect(dispatch_cli(['returned', $fixture['manifest'], $fixture['diff']])['json'])->toBe(['action' => 'done']);
    expect(manifest_read($fixture['manifest'])['cursor']['status'])->toBe('done');
    expect(dispatch_cli(['next', $fixture['manifest']])['json'])->toBe(['action' => 'done']);
});

it('answers done for a run the old engine finished', function (string $status) {
    $fixture = dispatch_fixture(['cursor' => ['leg' => 'done', 'status' => $status]]);

    expect(dispatch_cli(['next', $fixture['manifest']])['json'])->toBe(['action' => 'done']);
})->with(['done', 'complete']);

it('briefs the leg with the manifest path it was given, nested or flat', function () {
    $fixture = dispatch_fixture();
    $nested = $fixture['dir'] . '/.claude/pipeline/feature/x.json';
    mkdir(dirname($nested), 0777, true);
    rename($fixture['manifest'], $nested);

    dispatch_cli(['next', $nested]);

    expect(file_get_contents($fixture['dir'] . '/.claude/pipeline/feature/x.brief.md'))->toContain("- manifest: `{$nested}`");
});

it('refuses a manifest it cannot read, and a bad command', function () {
    expect(dispatch_cli(['next', '/nonexistent/manifest.json'])['json']['action'])->toBe('halt');
    expect(dispatch_cli(['sideways'])['code'])->toBe(1);
});

it('launches from the cursor with the ledger\'s loop-backs, the design size and ui', function () {
    $looped = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r', 'outcome' => 'looped-back'];
    $open = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 2, 'at' => '2026-09-22T11:00:00Z', 'review' => 'r'];
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'gate_ledger' => [$looped, $open], 'artifacts' => ['spec' => 'spec.md', 'plan' => null, 'pr' => null, 'issue' => null]]);
    file_put_contents($fixture['dir'] . '/spec.md', "# x — design\n\n**Design size:** Bounded\n");
    file_put_contents($fixture['diff'], dispatch_ui_diff());

    expect(dispatch_cli(['launch', $fixture['manifest'], $fixture['diff']])['json'])->toBe([
        'action' => 'start',
        'startLeg' => 'review-plan',
        'startStep' => 'resolve',
        'loops' => ['review-plan' => 1, 'review-pr' => 0, 'verify-ui' => 0],
        'ui' => true,
        'size' => 'Bounded',
        'manifest' => $fixture['manifest'],
        'worktree' => $fixture['dir'],
        'noOpen' => false,
        'checks' => realpath(__DIR__ . '/..'),
    ]);
    expect(manifest_read($fixture['manifest'])['cursor'])->toBe(['leg' => 'review-plan', 'status' => 'pending']);
});

it('marks noOpen when the launch runs unattended', function () {
    $fixture = dispatch_fixture(['mode' => 'autoflow']);

    expect(dispatch_cli(['launch', $fixture['manifest'], $fixture['diff']], ['PIPELINE_NO_OPEN' => '1'])['json']['noOpen'])->toBeTrue();
});

it('answers done for a finished run, and re-arms it with --from through the navigation guardrail', function () {
    $passed = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r', 'outcome' => 'continued'];
    $reviewed = [...$passed, 'gate' => 'pr-review', 'leg' => 'review-pr', 'at' => '2026-09-22T11:00:00Z'];
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'review-pr', 'status' => 'done'], 'gate_ledger' => [$passed, $reviewed]]);

    expect(dispatch_cli(['launch', $fixture['manifest'], $fixture['diff']])['json'])->toBe(['action' => 'done']);

    expect(dispatch_cli(['launch', $fixture['manifest'], $fixture['diff'], '--from', 'review-pr'])['json'])
        ->toMatchArray(['action' => 'start', 'startLeg' => 'review-pr', 'startStep' => 'review']);
    expect(manifest_read($fixture['manifest'])['cursor'])->toBe(['leg' => 'review-pr', 'status' => 'pending']);

    $fresh = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'design', 'status' => 'pending']]);
    $refused = dispatch_cli(['launch', $fresh['manifest'], $fresh['diff'], '--from', 'implement'])['json'];
    expect($refused['action'])->toBe('halt');
    expect($refused['reason'])->toContain('cannot re-arm the run at implement');
    expect(manifest_read($fresh['manifest'])['cursor'])->toBe(['leg' => 'design', 'status' => 'pending']);
});

it('halts a launch whose recorded spec is missing at last_sha, and records it', function () {
    $repo = suite_repo();
    $manifest = $repo . '/.claude/pipeline/feature-x.json';
    manifest_write($manifest, [
        'branch' => 'feature/x', 'worktree' => $repo, 'mode' => 'autoflow',
        'cursor' => ['leg' => 'review-plan', 'status' => 'pending'],
        'artifacts' => ['spec' => 'docs/spec.md', 'plan' => null, 'pr' => null, 'issue' => null],
        'last_sha' => pipeline_git($repo, ['rev-parse', 'HEAD']), 'gate_ledger' => [],
    ]);
    file_put_contents($repo . '/pipeline.diff', '');

    $halted = dispatch_cli(['launch', $manifest, $repo . '/pipeline.diff'])['json'];
    expect($halted['action'])->toBe('halt');
    expect($halted['reason'])->toContain('the recorded spec docs/spec.md does not exist at');
    expect(manifest_read($manifest)['cursor'])->toMatchArray(['leg' => 'review-plan', 'status' => 'halted']);

    mkdir($repo . '/docs');
    file_put_contents($repo . '/docs/spec.md', "# x — design\n");
    pipeline_git($repo, ['add', 'docs/spec.md']);
    pipeline_git($repo, ['commit', '-qm', 'spec']);
    manifest_write($manifest, [...manifest_read($manifest), 'cursor' => ['leg' => 'review-plan', 'status' => 'pending'], 'last_sha' => pipeline_git($repo, ['rev-parse', 'HEAD'])]);

    expect(dispatch_cli(['launch', $manifest, $repo . '/pipeline.diff'])['json']['action'])->toBe('start');
});

it('halts a launch without a manifest or a diff file', function () {
    $fixture = dispatch_fixture(['mode' => 'autoflow']);

    expect(dispatch_cli(['launch', '/nonexistent/m.json', $fixture['diff']])['json']['action'])->toBe('halt');
    expect(dispatch_cli(['launch', $fixture['manifest'], $fixture['dir'] . '/missing.diff'])['json']['action'])->toBe('halt');
});

it('launches only autoflow runs, and next refuses one', function () {
    $auto = dispatch_fixture();
    expect(dispatch_cli(['launch', $auto['manifest'], $auto['diff']])['json'])
        ->toBe(['action' => 'halt', 'reason' => "launch starts autoflow runs; this run's mode is auto (resume it with /pipeline, which uses next)"]);
    expect(manifest_read($auto['manifest'])['cursor'])->toBe(['leg' => 'review-plan', 'status' => 'pending']);

    $flow = dispatch_fixture(['mode' => 'autoflow']);
    expect(dispatch_cli(['next', $flow['manifest']])['json'])
        ->toBe(['action' => 'halt', 'reason' => 'an autoflow run resumes with launch, not next']);
    expect(is_file($flow['brief']))->toBeFalse();
});

it('prints the brief for the step it is given and records that step as running', function () {
    $open = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r'];
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'design', 'status' => 'halted', 'reason' => 'x'], 'gate_ledger' => [$open]]);

    $result = dispatch_cli(['brief', $fixture['manifest'], 'review-plan', 'resolve']);

    expect($result['code'])->toBe(0);
    expect($result['stdout'])
        ->toContain('`review-plan` leg, `resolve` step')
        ->toContain('the open review: `gate_ledger[0]`')
        ->toContain('as your structured result');
    expect(manifest_read($fixture['manifest'])['cursor'])->toBe(['leg' => 'review-plan', 'status' => 'pending']);
});

it('halts a step the ledger does not support, and leaves the manifest alone', function () {
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'review-plan', 'status' => 'continued']]);

    expect(dispatch_cli(['brief', $fixture['manifest'], 'review-plan', 'resolve'])['json'])
        ->toBe(['action' => 'halt', 'reason' => 'no open plan-approval review to resolve']);
    expect(manifest_read($fixture['manifest'])['cursor'])->toBe(['leg' => 'review-plan', 'status' => 'continued']);
});

it('records the workflow\'s return with finish', function (string $leg, string $decision, array $cursor) {
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => $leg, 'status' => 'pending']]);

    expect(dispatch_cli(['finish', $fixture['manifest'], $decision])['code'])->toBe(0);
    expect(manifest_read($fixture['manifest'])['cursor'])->toBe($cursor);
})->with([
    'done' => ['review-pr', '{"action":"done"}', ['leg' => 'review-pr', 'status' => 'done']],
    'done before review-pr' => ['implement', '{"action":"done"}', ['leg' => 'implement', 'status' => 'halted', 'reason' => 'the workflow returned done at implement']],
    'a halt naming its leg' => ['implement', '{"action":"halt","leg":"verify-ui","reason":"stub halt"}', ['leg' => 'verify-ui', 'status' => 'halted', 'reason' => 'stub halt']],
    'a halt naming no leg of the pipeline' => ['implement', '{"action":"halt","leg":"launch","reason":"args are not a launch start answer"}', ['leg' => 'implement', 'status' => 'halted', 'reason' => 'args are not a launch start answer']],
    'a halt from the invoking session' => ['implement', '{"action":"halt","reason":"the workflow errored"}', ['leg' => 'implement', 'status' => 'halted', 'reason' => 'the workflow errored']],
    'no decision' => ['implement', 'not json', ['leg' => 'implement', 'status' => 'halted', 'reason' => 'the workflow returned no decision: not json']],
]);
