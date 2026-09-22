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

function dispatch_cli(array $arguments): array
{
    $process = proc_open([PHP_BINARY, __DIR__ . '/../dispatch_cli.php', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($process), 'json' => json_decode($stdout, true)];
}

/** Play a leg: change the manifest the way the leg would. */
function dispatch_leg_writes(string $path, callable $change): void
{
    manifest_write($path, $change(manifest_read($path)));
}

it('writes the brief and the snapshot and prints one dispatch line', function () {
    $fixture = dispatch_fixture();
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
    'Architectural halts' => ['**Design size:** Architectural', 'halt'],
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

it('refuses a manifest it cannot read, and a bad command', function () {
    expect(dispatch_cli(['next', '/nonexistent/manifest.json'])['json']['action'])->toBe('halt');
    expect(dispatch_cli(['sideways'])['code'])->toBe(1);
});
