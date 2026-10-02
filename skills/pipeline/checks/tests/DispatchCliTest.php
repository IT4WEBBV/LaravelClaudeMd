<?php

/** A worktree-shaped temp dir with a manifest and an empty diff; nothing touches a real checkout. */
function dispatch_fixture(array $manifest = []): array
{
    $dir = sys_get_temp_dir() . '/pipeline-dispatch-' . uniqid();
    mkdir($dir . '/.claude/pipeline', 0777, true);
    $path = $dir . '/.claude/pipeline/feature-x.json';
    manifest_write($path, [
        'branch' => 'feature/x', 'worktree' => $dir, 'mode' => 'interactive',
        'cursor' => ['leg' => 'review-plan', 'status' => 'pending'],
        'artifacts' => ['spec' => null, 'plan' => null, 'pr' => null, 'issue' => null],
        'gate_ledger' => [],
        ...$manifest,
    ]);
    file_put_contents($dir . '/pipeline.diff', '');

    return ['dir' => $dir, 'manifest' => $path, 'diff' => $dir . '/pipeline.diff', 'brief' => $dir . '/.claude/pipeline/feature-x.brief.md', 'before' => $dir . '/.claude/pipeline/feature-x.before.json', 'stepDiff' => $dir . '/.claude/pipeline/feature-x.diff'];
}

function dispatch_cli(array $arguments, array $env = []): array
{
    $process = proc_open(
        [PHP_BINARY, __DIR__ . '/../dispatch_cli.php', ...$arguments],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        [...getenv(), 'PIPELINE_PROOF_ROOT' => sys_get_temp_dir() . '/pipeline-proofs-' . uniqid(), ...$env],
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

it('runs design inline in an interactive run', function () {
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

    expect(dispatch_cli(['returned', $fixture['manifest'], $fixture['diff']])['json'])->toBe(['action' => 'done', 'proof' => null]);
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
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'tier' => 'medium', 'gate_ledger' => [$looped, $open], 'artifacts' => ['spec' => 'spec.md', 'plan' => null, 'pr' => null, 'issue' => null]]);
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
        'checks' => realpath(__DIR__ . '/..'),
        'tables' => pipeline_routing_tables(),
        'profile' => 'medium',
        'tier' => 'medium',
        'escalated' => false,
        'agents' => pipeline_agent_table([]),
    ]);
    expect(manifest_read($fixture['manifest'])['cursor'])->toBe(['leg' => 'review-plan', 'status' => 'pending']);
});

it('hands the autoflow script its routing tables, from the functions interactive mode routes by', function () {
    $fixture = dispatch_fixture(['mode' => 'autoflow']);
    $tables = dispatch_cli(['launch', $fixture['manifest'], $fixture['diff']])['json']['tables'];
    $values = fn (array $statuses) => array_map(fn (LegStatus $status) => $status->value, $statuses);

    expect($tables['legs'])->toBe(pipeline_legs());
    expect($tables['bound'])->toBe(PIPELINE_LOOP_BOUND);
    expect(array_keys($tables['steps']))->toBe(pipeline_legs());
    expect(array_keys($tables['loopTarget']))->toBe(array_values(array_filter(pipeline_legs(), fn (string $leg) => pipeline_loop_target($leg) !== null)));
    $pairs = [];
    foreach (pipeline_legs() as $leg) {
        expect($tables['steps'][$leg])->toBe(pipeline_steps($leg, 'autoflow'));
        expect($tables['loopTarget'][$leg] ?? null)->toBe(pipeline_loop_target($leg));
        foreach (pipeline_steps($leg, 'autoflow') as $step) {
            $pairs[] = "{$leg}:{$step}";
            expect($tables['allowed']["{$leg}:{$step}"])->toBe($values(LegStatus::allowedFor($leg, $step)));
        }
    }
    expect($tables['bounded'])->toBe(PIPELINE_BOUNDED_STEPS);
    foreach ($tables['bounded'] as $leg => $bounded) {
        expect(array_diff($bounded, $tables['steps'][$leg]))->toBe([]);
    }
    expect(array_keys($tables['allowed']))->toBe($pairs);
});

it('hands the script its agents with the manifest\'s override laid over them, the profile to start on and the tier', function () {
    $override = ['review-plan:review' => ['model' => 'opus', 'effort' => 'xhigh']];
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'tier' => 'light', 'agents' => $override]);
    $start = dispatch_cli(['launch', $fixture['manifest'], $fixture['diff']])['json'];

    expect($start['agents'])->toBe(pipeline_agent_table($override));
    expect($start['agents']['full']['review-plan:review'])->toBe(['model' => 'opus', 'effort' => 'xhigh']);
    expect($start['agents']['light']['review-plan:review'])->toBe(['model' => 'opus', 'effort' => 'xhigh']);
    expect($start['profile'])->toBe('light');
    expect($start['tier'])->toBe('light');
    expect($start['escalated'])->toBeFalse();
});

it('starts a run on full once its ledger records an escalation, whatever the spec and the tier say', function () {
    $escalated = ['gate' => 'design-size', 'leg' => 'handoff', 'at' => '2026-09-29T10:00:00Z', 'reason' => 'migration', 'outcome' => 'escalated'];
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'tier' => 'light', 'cursor' => ['leg' => 'design', 'status' => 'pending'], 'gate_ledger' => [$escalated], 'artifacts' => ['spec' => 'spec.md', 'plan' => 'plan.md', 'pr' => null, 'issue' => null]]);
    file_put_contents($fixture['dir'] . '/spec.md', "# x — design\n\n**Design size:** Bounded\n");

    $start = dispatch_cli(['launch', $fixture['manifest'], $fixture['diff']])['json'];

    expect($start['size'])->toBe('Bounded');
    expect($start['profile'])->toBe('full');
    expect($start['tier'])->toBe('light');
    expect($start['escalated'])->toBeTrue();
});

it('starts a legacy light: true manifest with a Bounded spec on medium, the former light agents, and names medium as its tier', function () {
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'light' => true, 'artifacts' => ['spec' => 'spec.md', 'plan' => null, 'pr' => null, 'issue' => null]]);
    file_put_contents($fixture['dir'] . '/spec.md', "# x — design\n\n**Design size:** Bounded\n");

    $start = dispatch_cli(['launch', $fixture['manifest'], $fixture['diff']])['json'];

    expect($start['profile'])->toBe('medium');
    expect($start['tier'])->toBe('medium');
});

it('halts a launch whose agents override is invalid, and leaves the manifest as it was', function (array $manifest, mixed $agents, string $what) {
    $fixture = dispatch_fixture(['mode' => 'autoflow', ...$manifest, 'agents' => $agents]);
    $before = file_get_contents($fixture['manifest']);

    expect(dispatch_cli(['launch', $fixture['manifest'], $fixture['diff'], '--decision', 'Keep the guard'])['json'])
        ->toBe(['action' => 'halt', 'reason' => "the manifest's agents override is invalid: {$what}"]);
    expect(file_get_contents($fixture['manifest']))->toBe($before);
})->with([
    'a step autoflow does not have' => [[], ['design:run' => ['effort' => 'low']], '`design:run` is not an autoflow step'],
    'a model outside the three' => [[], ['handoff:run' => ['model' => 'haiku']], '`handoff:run` names model "haiku", not one of opus, sonnet, fable'],
    'a list, on a finished run' => [['cursor' => ['leg' => 'review-pr', 'status' => 'done']], [['model' => 'opus']], 'it is not an object'],
]);

it('halts a launch whose tier is not medium or light, and leaves the manifest as it was', function (mixed $tier, string $what) {
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'tier' => $tier, 'light' => true]);
    $before = file_get_contents($fixture['manifest']);

    expect(dispatch_cli(['launch', $fixture['manifest'], $fixture['diff'], '--decision', 'Keep the guard'])['json'])
        ->toBe(['action' => 'halt', 'reason' => "the manifest's tier is invalid: {$what} is not medium or light"]);
    expect(file_get_contents($fixture['manifest']))->toBe($before);
})->with([
    'an unknown word' => ['lite', '"lite"'],
    'full, written by hand' => ['full', '"full"'],
    'another table entry' => ['loopedBack', '"loopedBack"'],
    'a boolean' => [true, 'true'],
    'a number' => [3, '3'],
    'a list' => [['light'], '["light"]'],
    'null' => [null, 'null'],
]);

it('launches a manifest whose tier is medium, light or absent', function (array $manifest, string $tier) {
    $fixture = dispatch_fixture(['mode' => 'autoflow', ...$manifest]);

    expect(dispatch_cli(['launch', $fixture['manifest'], $fixture['diff']])['json']['tier'])->toBe($tier);
})->with([
    'medium' => [['tier' => 'medium'], 'medium'],
    'light' => [['tier' => 'light'], 'light'],
    'no tier' => [[], 'full'],
    'a legacy light: true' => [['light' => true], 'medium'],
]);

it('launches a design at the step the manifest calls for', function (?string $spec, string $step) {
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'design', 'status' => 'halted', 'reason' => 'x'], 'artifacts' => ['spec' => $spec, 'plan' => null, 'pr' => null, 'issue' => null]]);
    file_put_contents($fixture['dir'] . '/spec.md', "# x — design\n\n**Design size:** Architectural\n");

    expect(dispatch_cli(['launch', $fixture['manifest'], $fixture['diff']])['json']['startStep'])->toBe($step);
})->with([
    'no spec yet' => [null, 'spec'],
    'a spec whose plan is not written' => ['spec.md', 'plan'],
]);

it('gives the workflow no noOpen flag, whatever PIPELINE_NO_OPEN says', function () {
    $fixture = dispatch_fixture(['mode' => 'autoflow']);

    expect(dispatch_cli(['launch', $fixture['manifest'], $fixture['diff']], ['PIPELINE_NO_OPEN' => '1'])['json'])->not->toHaveKey('noOpen');
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
    $interactive = dispatch_fixture();
    expect(dispatch_cli(['launch', $interactive['manifest'], $interactive['diff']])['json'])
        ->toBe(['action' => 'halt', 'reason' => "launch starts autoflow runs; this run's mode is interactive (resume it with /pipeline, which uses next)"]);
    expect(manifest_read($interactive['manifest'])['cursor'])->toBe(['leg' => 'review-plan', 'status' => 'pending']);

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
        ->toContain('as your structured `{status, reason}`');
    expect(manifest_read($fixture['manifest'])['cursor'])->toBe(['leg' => 'review-plan', 'status' => 'pending']);
});

it('halts a step the ledger does not support, and leaves the manifest alone', function () {
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'review-plan', 'status' => 'continued']]);

    expect(dispatch_cli(['brief', $fixture['manifest'], 'review-plan', 'resolve'])['json'])
        ->toBe(['action' => 'halt', 'reason' => 'no open plan-approval review to resolve']);
    expect(manifest_read($fixture['manifest'])['cursor'])->toBe(['leg' => 'review-plan', 'status' => 'continued']);
});

it('halts the brief of a design step the manifest does not call for, and leaves the manifest alone', function () {
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'design', 'status' => 'pending']]);

    expect(dispatch_cli(['brief', $fixture['manifest'], 'design', 'plan'])['json'])->toBe(['action' => 'halt', 'reason' => 'the manifest calls for the design spec step, not plan: the plan step follows a spec step that set artifacts.spec and removed artifacts.plan, or a plan-insufficient on an Architectural design']);
    expect(manifest_read($fixture['manifest'])['cursor'])->toBe(['leg' => 'design', 'status' => 'pending']);
});

it('briefs the plan step after a spec step that committed its spec', function () {
    $fixture = boundary_fixture('design', 'spec', ['cursor' => ['leg' => 'design', 'status' => 'pending']]);
    file_put_contents($fixture['dir'] . '/spec.md', "# x — design\n\n**Design size:** Architectural\n");
    dispatch_leg_writes($fixture['manifest'], fn (array $m) => [...$m, 'cursor' => [...$m['cursor'], 'status' => 'continued'], 'artifacts' => [...$m['artifacts'], 'spec' => 'spec.md']]);

    expect(boundary_brief($fixture, 'design', 'plan', 'design:spec', ['--status', 'continued', '--size', 'Architectural'])['stdout'])
        ->toContain('`design` leg, `plan` step')
        ->toContain('- spec: `spec.md`');
});

it('records the workflow\'s return with finish', function (string $leg, string $decision, array $cursor) {
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => $leg, 'status' => 'pending']]);

    expect(dispatch_cli(['finish', $fixture['manifest'], $decision])['code'])->toBe(0);
    expect(manifest_read($fixture['manifest'])['cursor'])->toBe($cursor);
})->with([
    'done before review-pr' => ['implement', '{"action":"done"}', ['leg' => 'implement', 'status' => 'halted', 'reason' => 'the workflow returned done at implement']],
    'a halt naming its leg' => ['implement', '{"action":"halt","leg":"verify-ui","reason":"stub halt"}', ['leg' => 'verify-ui', 'status' => 'halted', 'reason' => 'stub halt']],
    'a halt naming no leg of the pipeline' => ['implement', '{"action":"halt","leg":"launch","reason":"args are not a launch start answer"}', ['leg' => 'implement', 'status' => 'halted', 'reason' => 'args are not a launch start answer']],
    'a halt from the invoking session' => ['implement', '{"action":"halt","reason":"the workflow errored"}', ['leg' => 'implement', 'status' => 'halted', 'reason' => 'the workflow errored']],
    'no decision' => ['implement', 'not json', ['leg' => 'implement', 'status' => 'halted', 'reason' => 'the workflow returned no decision: not json']],
]);

it('answers a relay halt with one relaunch, counted from the cursor it overwrites (#134)', function (array $cursor, string $decision, array $answer, array $recorded) {
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'cursor' => $cursor]);

    expect(dispatch_cli(['finish', $fixture['manifest'], $decision])['json'])->toBe($answer);
    expect(manifest_read($fixture['manifest'])['cursor'])->toBe($recorded);
})->with([
    'the first relay halt' => [
        ['leg' => 'implement', 'status' => 'pending'],
        '{"action":"halt","leg":"implement","reason":"relay: x"}',
        ['action' => 'halt', 'reason' => 'relay: x', 'relaunch' => true],
        ['leg' => 'implement', 'status' => 'halted', 'reason' => 'relay: x'],
    ],
    'a relay halt after a relay halt' => [
        ['leg' => 'implement', 'status' => 'halted', 'reason' => 'relay: x'],
        '{"action":"halt","leg":"implement","reason":"relay: y"}',
        ['action' => 'halt', 'reason' => 'relay: y'],
        ['leg' => 'implement', 'status' => 'halted', 'reason' => 'relay: y'],
    ],
    'a relay halt over another halt' => [
        ['leg' => 'implement', 'status' => 'halted', 'reason' => 'tests stayed red'],
        '{"action":"halt","leg":"implement","reason":" relay: y "}',
        ['action' => 'halt', 'reason' => 'relay: y', 'relaunch' => true],
        ['leg' => 'implement', 'status' => 'halted', 'reason' => 'relay: y'],
    ],
    'any other halt' => [
        ['leg' => 'implement', 'status' => 'pending'],
        '{"action":"halt","leg":"implement","reason":"the relay check failed: x"}',
        ['action' => 'halt', 'reason' => 'the relay check failed: x'],
        ['leg' => 'implement', 'status' => 'halted', 'reason' => 'the relay check failed: x'],
    ],
]);

/** An `autoflow` run whose `$leg` `$step` was briefed as a run's first step: its snapshot is on disk. */
function boundary_fixture(string $leg, string $step, array $manifest = []): array
{
    $fixture = dispatch_fixture(['mode' => 'autoflow', ...$manifest]);
    expect(dispatch_cli(['brief', $fixture['manifest'], $leg, $step])['stdout'])->toContain("`{$leg}` leg, `{$step}` step");

    return $fixture;
}

/** The step the script briefs next, told what the step before it returned. */
function boundary_brief(array $fixture, string $leg, string $step, string $after, array $reported): array
{
    return dispatch_cli(['brief', $fixture['manifest'], $leg, $step, '--after', $after, ...$reported]);
}

function boundary_open(string $gate = 'plan-approval'): array
{
    return ['gate' => $gate, 'leg' => pipeline_leg_of_gate($gate), 'cycle' => 1, 'at' => '2026-09-25T10:00:00Z', 'review' => 'r', 'annotations' => []];
}

it('halts the next brief when a resolve step left its entry open, with the cursor on that step', function () {
    $fixture = boundary_fixture('review-plan', 'resolve', ['gate_ledger' => [boundary_open()]]);
    dispatch_leg_writes($fixture['manifest'], fn (array $m) => [...$m, 'cursor' => [...$m['cursor'], 'status' => 'continued']]);

    $halt = boundary_brief($fixture, 'handoff', 'run', 'review-plan:resolve', ['--status', 'continued'])['json'];

    $reason = "the resolve step must set the open plan-approval entry's outcome to continued";
    expect($halt)->toBe(['action' => 'halt', 'reason' => $reason]);
    expect(manifest_read($fixture['manifest'])['cursor'])->toBe(['leg' => 'review-plan', 'status' => 'halted', 'reason' => $reason]);
});

it('halts the next brief when implement reported ui false on a UI diff, or wrote no diff of its own', function (bool $stale, string $reason) {
    $fixture = boundary_fixture('implement', 'run');
    dispatch_leg_writes($fixture['manifest'], fn (array $m) => [...$m, 'cursor' => [...$m['cursor'], 'status' => 'continued'], 'last_sha' => 'bbb2222']);
    file_put_contents($fixture['stepDiff'], dispatch_ui_diff());
    if ($stale) {
        touch($fixture['stepDiff'], time() - 60);
    }

    $halt = boundary_brief($fixture, 'review-pr', 'review', 'implement:run', ['--status', 'continued', '--ui', 'false'])['json'];

    expect($halt)->toBe(['action' => 'halt', 'reason' => str_replace('<diff>', $fixture['stepDiff'], $reason)]);
    expect(manifest_read($fixture['manifest'])['cursor'])->toMatchArray(['leg' => 'implement', 'status' => 'halted']);
})->with([
    'ui false on a UI diff' => [false, 'the implement step returned ui: false, but its diff says true'],
    'a diff older than the step' => [true, 'the implement step did not write <diff>'],
]);

it('halts the next brief when a step changed what only the engine writes', function (callable $change, string $reason) {
    $passed = [...boundary_open(), 'actions' => [], 'outcome' => 'continued'];
    $fixture = boundary_fixture('handoff', 'run', ['gate_ledger' => [$passed]]);
    dispatch_leg_writes($fixture['manifest'], $change);

    expect(boundary_brief($fixture, 'implement', 'run', 'handoff:run', ['--status', 'continued'])['json'])->toBe(['action' => 'halt', 'reason' => $reason]);
    expect(manifest_read($fixture['manifest'])['cursor'])->toMatchArray(['leg' => 'handoff', 'status' => 'halted']);
})->with([
    'a moved cursor' => [fn (array $m) => [...$m, 'cursor' => ['leg' => 'implement', 'status' => 'continued']], 'the leg changed cursor.leg, which only the dispatcher writes'],
    'a rewritten ledger entry' => [fn (array $m) => [...$m, 'cursor' => [...$m['cursor'], 'status' => 'continued'], 'gate_ledger' => [[...$m['gate_ledger'][0], 'outcome' => 'looped-back']]], 'handoff changed outcome on ledger entry 0 (plan-approval)'],
]);

it('halts the next brief naming the leg, the key and the entry when design writes onto the plan gap it answers (#104)', function () {
    $approved = [...boundary_open(), 'actions' => [], 'outcome' => 'continued'];
    $gap = ['gate' => 'plan-approval', 'leg' => 'implement', 'cycle' => 2, 'at' => '2026-09-25T11:00:00Z', 'reason' => 'needs a queue', 'outcome' => 'looped-back'];
    $fixture = boundary_fixture('design', 'plan', ['gate_ledger' => [$approved, $gap], 'artifacts' => ['spec' => 'spec.md', 'plan' => 'plan.md', 'pr' => null, 'issue' => null]]);
    dispatch_leg_writes($fixture['manifest'], fn (array $m) => [
        ...$m,
        'cursor' => [...$m['cursor'], 'status' => 'continued'],
        'gate_ledger' => [$approved, [...$gap, 'actions' => [['claim' => 'needs a queue', 'disposition' => 'integrated', 'note' => 'plan task 4']]]],
    ]);

    $reason = 'design added actions to ledger entry 1 (plan gap)';
    expect(boundary_brief($fixture, 'review-plan', 'review', 'design:plan', ['--status', 'continued', '--size', 'Architectural'])['json'])
        ->toBe(['action' => 'halt', 'reason' => $reason]);
    expect(manifest_read($fixture['manifest'])['cursor'])->toMatchArray(['leg' => 'design', 'status' => 'halted', 'reason' => $reason]);
});

it('briefs the next step after a clean return, and takes its snapshot', function () {
    $fixture = boundary_fixture('handoff', 'run');
    dispatch_leg_writes($fixture['manifest'], fn (array $m) => [...$m, 'cursor' => [...$m['cursor'], 'status' => 'continued'], 'artifacts' => [...$m['artifacts'], 'pr' => 7]]);

    $result = boundary_brief($fixture, 'implement', 'run', 'handoff:run', ['--status', 'continued']);

    expect($result['stdout'])->toContain('`implement` leg, `run` step')->toContain('- pr: `7`');
    expect(manifest_read($fixture['manifest'])['cursor'])->toBe(['leg' => 'implement', 'status' => 'pending']);
    expect(manifest_read($fixture['before']))->toBe(manifest_read($fixture['manifest']));
});

it('halts the next brief when the script was told another status or size than the step wrote', function (array $reported, string $reason) {
    $fixture = boundary_fixture('design', 'plan', ['cursor' => ['leg' => 'design', 'status' => 'pending'], 'artifacts' => ['spec' => 'spec.md', 'plan' => null, 'pr' => null, 'issue' => null]]);
    file_put_contents($fixture['dir'] . '/spec.md', "# x — design\n\n**Design size:** Architectural\n");
    dispatch_leg_writes($fixture['manifest'], fn (array $m) => [...$m, 'cursor' => [...$m['cursor'], 'status' => 'continued'], 'last_sha' => 'aaa1111']);

    expect(boundary_brief($fixture, 'review-plan', 'review', 'design:plan', $reported)['json'])->toBe(['action' => 'halt', 'reason' => $reason]);
})->with([
    'another status' => [['--status', 'halted', '--size', 'Architectural'], 'the design plan step returned halted to the script but wrote continued into the manifest'],
    'another size' => [['--status', 'continued', '--size', 'Bounded'], 'the design step returned size Bounded, but the spec says Architectural'],
    'no size' => [['--status', 'continued'], 'the design step returned size nothing, but the spec says Architectural'],
]);

it('launch removes an earlier run\'s snapshot, so the first brief of a run checks nothing', function () {
    $fixture = boundary_fixture('handoff', 'run');
    dispatch_leg_writes($fixture['manifest'], fn (array $m) => [...$m, 'cursor' => ['leg' => 'handoff', 'status' => 'halted', 'reason' => 'an earlier run']]);

    expect(dispatch_cli(['launch', $fixture['manifest'], $fixture['diff']])['json']['action'])->toBe('start');
    expect(is_file($fixture['before']))->toBeFalse();
    expect(dispatch_cli(['brief', $fixture['manifest'], 'handoff', 'run'])['stdout'])->toContain('`handoff` leg, `run` step');
});

it('halts a brief told of a step that left no snapshot, not its own, or none', function () {
    $bare = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'handoff', 'status' => 'continued']]);
    expect(boundary_brief($bare, 'implement', 'run', 'handoff:run', ['--status', 'continued'])['json'])
        ->toBe(['action' => 'halt', 'reason' => "cannot check the handoff run step's return: no snapshot at {$bare['before']}"]);
    expect(manifest_read($bare['manifest'])['cursor'])->toMatchArray(['leg' => 'handoff', 'status' => 'halted']);

    $other = boundary_fixture('design', 'spec', ['cursor' => ['leg' => 'design', 'status' => 'pending']]);
    dispatch_leg_writes($other['manifest'], fn (array $m) => [...$m, 'cursor' => [...$m['cursor'], 'status' => 'continued']]);
    expect(boundary_brief($other, 'implement', 'run', 'handoff:run', ['--status', 'continued'])['json'])
        ->toBe(['action' => 'halt', 'reason' => 'the snapshot is of the design spec step, but the script says handoff run returned: it did not run brief']);

    $dropped = boundary_fixture('handoff', 'run');
    dispatch_leg_writes($dropped['manifest'], fn (array $m) => [...$m, 'cursor' => [...$m['cursor'], 'status' => 'continued'], 'artifacts' => [...$m['artifacts'], 'pr' => 7]]);
    expect(dispatch_cli(['brief', $dropped['manifest'], 'implement', 'run'])['json'])
        ->toBe(['action' => 'halt', 'reason' => 'a snapshot of the handoff run step exists, but the script names no step before implement run']);
    expect(manifest_read($dropped['manifest'])['cursor'])->toMatchArray(['leg' => 'handoff', 'status' => 'halted']);
});

it('briefs a review step again after an attempt that returned nothing, unless that attempt wrote', function (array $flags) {
    $fixture = boundary_fixture('review-pr', 'review', ['cursor' => ['leg' => 'review-pr', 'status' => 'pending']]);
    $again = fn () => dispatch_cli(['brief', $fixture['manifest'], 'review-pr', 'review', ...$flags]);

    expect($again()['stdout'])->toContain('`review-pr` leg, `review` step');

    dispatch_leg_writes($fixture['manifest'], fn (array $m) => [...$m, 'last_sha' => 'ccc3333']);
    expect($again()['json'])->toBe(['action' => 'halt', 'reason' => 'the review-pr review step returned nothing and changed the manifest']);
    expect(manifest_read($fixture['manifest'])['cursor'])->toMatchArray(['leg' => 'review-pr', 'status' => 'halted']);
})->with([
    'after the step before it' => [['--after', 'implement:run', '--status', 'continued', '--ui', 'false']],
    'as the run\'s first step, without flags' => [[]],
]);

it('refuses a brief it cannot parse', function (array $arguments) {
    $fixture = dispatch_fixture(['mode' => 'autoflow']);

    expect(dispatch_cli(['brief', $fixture['manifest'], ...$arguments])['code'])->toBe(1);
})->with([
    'no step' => [['handoff']],
    'an unknown flag' => [['handoff', 'run', '--sideways', 'x']],
    'a flag without its value' => [['handoff', 'run', '--after']],
    'an --after that is no step' => [['handoff', 'run', '--after', 'handoff:review', '--status', 'continued']],
    'a status without --after' => [['handoff', 'run', '--status', 'continued']],
    'a flag given twice' => [['handoff', 'run', '--after', 'design:spec', '--status', 'continued', '--status', 'halted']],
    'an --after naming interactive\'s design step' => [['review-plan', 'review', '--after', 'design:run', '--status', 'continued']],
]);

it('finishes only after a review-pr resolve step whose return holds', function () {
    $open = boundary_open('pr-review');
    $fixture = boundary_fixture('review-pr', 'resolve', ['cursor' => ['leg' => 'review-pr', 'status' => 'pending'], 'gate_ledger' => [$open]]);
    $finish = fn () => dispatch_cli(['finish', $fixture['manifest'], '{"action":"done"}'])['json'];

    dispatch_leg_writes($fixture['manifest'], fn (array $m) => [...$m, 'cursor' => [...$m['cursor'], 'status' => 'continued']]);
    expect($finish())->toBe(['action' => 'halt', 'reason' => "the resolve step must set the open pr-review entry's outcome to continued"]);
    expect(manifest_read($fixture['manifest'])['cursor'])->toMatchArray(['leg' => 'review-pr', 'status' => 'halted']);

    dispatch_leg_writes($fixture['manifest'], fn (array $m) => [...$m, 'cursor' => ['leg' => 'review-pr', 'status' => 'continued'], 'gate_ledger' => [[...$open, 'actions' => [], 'outcome' => 'continued']]]);
    expect($finish())->toBe(['action' => 'done', 'proof' => null, 'followUps' => []]);
    expect(manifest_read($fixture['manifest'])['cursor'])->toBe(['leg' => 'review-pr', 'status' => 'done']);
});

/** A finished `autoflow` run's review-pr resolve step that completed its entry with `$actions`; its `finish` answer. */
function finish_with_actions(array $actions): array
{
    $open = boundary_open('pr-review');
    $fixture = boundary_fixture('review-pr', 'resolve', ['cursor' => ['leg' => 'review-pr', 'status' => 'pending'], 'gate_ledger' => [$open]]);
    dispatch_leg_writes($fixture['manifest'], fn (array $m) => [...$m, 'cursor' => ['leg' => 'review-pr', 'status' => 'continued'], 'gate_ledger' => [[...$open, 'actions' => $actions, 'outcome' => 'continued']]]);

    return [...$fixture, 'answer' => dispatch_cli(['finish', $fixture['manifest'], '{"action":"done"}'])['json']];
}

it('answers ask on a done run with an unanswered blocking question, the cursor done all the same (#146)', function () {
    $finished = finish_with_actions([
        ['claim' => 'Keep the <x-time> tag?', 'disposition' => 'open-question', 'note' => '<x-time> (built), a plain div', 'kind' => 'blocking'],
        ['claim' => 'File the date-format cleanup?', 'disposition' => 'open-question', 'note' => 'outside this PR', 'kind' => 'follow-up'],
        ['claim' => 'self-end alignment', 'disposition' => 'open-question', 'note' => 'kept', 'kind' => 'remark'],
    ]);

    expect($finished['answer'])->toBe([
        'action' => 'ask', 'proof' => null,
        'questions' => [[
            'id' => 'gate_ledger[0].actions[0]', 'gate' => 'pr-review', 'kind' => 'blocking', 'question' => 'Keep the <x-time> tag?', 'note' => '<x-time> (built), a plain div',
            'decision' => 'Answer to open question gate_ledger[0].actions[0] ("Keep the <x-time> tag?"): ',
        ]],
        'followUps' => [['question' => 'File the date-format cleanup?', 'note' => 'outside this PR']],
    ]);
    expect(manifest_read($finished['manifest'])['cursor'])->toBe(['leg' => 'review-pr', 'status' => 'done']);
});

it('answers done with the follow-ups on a run with only follow-up and remark questions, and done after an answer launch recorded (#146)', function () {
    $followUp = ['claim' => 'File the date-format cleanup?', 'disposition' => 'open-question', 'note' => 'outside this PR', 'kind' => 'follow-up'];
    $remark = ['claim' => 'self-end alignment', 'disposition' => 'open-question', 'note' => 'kept', 'kind' => 'remark'];

    expect(finish_with_actions([$followUp, $remark])['answer'])
        ->toBe(['action' => 'done', 'proof' => null, 'followUps' => [['question' => 'File the date-format cleanup?', 'note' => 'outside this PR']]]);

    $asked = finish_with_actions([['claim' => 'Queue or cron?', 'disposition' => 'open-question', 'note' => 'cron (built), queue', 'kind' => 'blocking']]);
    $decision = $asked['answer']['questions'][0]['decision'] . 'cron, as built';
    expect(dispatch_cli(['launch', $asked['manifest'], $asked['diff'], '--decision', $decision])['json'])->toBe(['action' => 'done']);
    expect(manifest_read($asked['manifest']))->toMatchArray(['cursor' => ['leg' => 'review-pr', 'status' => 'done'], 'decisions' => [$decision]]);
    expect(pipeline_unanswered(manifest_read($asked['manifest'])))->toBe([]);
});

it('refuses done when the last snapshot is not review-pr\'s resolve step, or there is none', function () {
    $review = boundary_fixture('review-pr', 'review', ['cursor' => ['leg' => 'review-pr', 'status' => 'pending']]);
    dispatch_leg_writes($review['manifest'], fn (array $m) => [...$m, 'cursor' => [...$m['cursor'], 'status' => 'continued'], 'gate_ledger' => [boundary_open('pr-review')]]);
    expect(dispatch_cli(['finish', $review['manifest'], '{"action":"done"}'])['json'])
        ->toBe(['action' => 'halt', 'reason' => 'the workflow returned done, but the last snapshot is of the review-pr review step']);

    $bare = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'review-pr', 'status' => 'pending']]);
    expect(dispatch_cli(['finish', $bare['manifest'], '{"action":"done"}'])['json'])
        ->toBe(['action' => 'halt', 'reason' => "the workflow returned done, but there is no snapshot at {$bare['before']}"]);
});

it('keeps the leg of a halt the manifest already records', function () {
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'review-plan', 'status' => 'halted', 'reason' => 'from brief']]);

    dispatch_cli(['finish', $fixture['manifest'], '{"action":"halt","leg":"handoff","reason":"from brief"}']);

    expect(manifest_read($fixture['manifest'])['cursor'])->toBe(['leg' => 'review-plan', 'status' => 'halted', 'reason' => 'from brief']);
});

it('halts a launch on a manifest without a mode, printing only the JSON line', function () {
    $fixture = dispatch_fixture();
    $manifest = manifest_read($fixture['manifest']);
    unset($manifest['mode']);
    manifest_write($fixture['manifest'], $manifest);

    expect(dispatch_cli(['launch', $fixture['manifest'], $fixture['diff']])['stdout'])
        ->toBe(json_encode(['action' => 'halt', 'reason' => 'the manifest is invalid: missing mode']) . "\n");
});

it('refuses --from with a leg that is not one, and leaves the manifest alone', function (string $from) {
    $fixture = dispatch_fixture(['mode' => 'autoflow']);
    $before = file_get_contents($fixture['manifest']);

    expect(dispatch_cli(['launch', $fixture['manifest'], $fixture['diff'], '--from', $from])['json'])
        ->toBe(['action' => 'halt', 'reason' => "cannot re-arm the run at '{$from}': not a leg"]);
    expect(file_get_contents($fixture['manifest']))->toBe($before);
})->with(['an unknown leg' => ['reveiw-pr'], 'no leg' => ['']]);

it('re-arms nothing on a manifest that is not an autoflow run\'s', function () {
    $fixture = dispatch_fixture(['cursor' => ['leg' => 'review-pr', 'status' => 'done']]);
    $before = file_get_contents($fixture['manifest']);

    expect(dispatch_cli(['launch', $fixture['manifest'], $fixture['diff'], '--from', 'design'])['json']['action'])->toBe('halt');
    expect(file_get_contents($fixture['manifest']))->toBe($before);
});

it('appends each --decision verbatim as it re-arms the run, and nothing when --from is refused', function () {
    $reviewed = ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => '2026-09-22T11:00:00Z', 'review' => 'r', 'outcome' => 'continued'];
    $passed = [...$reviewed, 'gate' => 'plan-approval', 'leg' => 'review-plan', 'at' => '2026-09-22T10:00:00Z'];
    $decision = "CI red on the PR's head commit abc123: CI / ci failed (https://github.com/acme/app/actions/runs/11/job/12)";
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'review-pr', 'status' => 'done'], 'decisions' => ['Keep the guard'], 'gate_ledger' => [$passed, $reviewed]]);

    expect(dispatch_cli(['launch', $fixture['manifest'], $fixture['diff'], '--from', 'review-pr', '--decision', $decision])['json'])
        ->toMatchArray(['action' => 'start', 'startLeg' => 'review-pr', 'startStep' => 'review']);
    expect(manifest_read($fixture['manifest']))->toMatchArray(['cursor' => ['leg' => 'review-pr', 'status' => 'pending'], 'decisions' => ['Keep the guard', $decision]]);

    $refused = dispatch_fixture(['mode' => 'autoflow', 'decisions' => ['Keep the guard']]);
    $before = file_get_contents($refused['manifest']);
    expect(dispatch_cli(['launch', $refused['manifest'], $refused['diff'], '--from', 'reveiw-pr', '--decision', $decision])['json']['action'])->toBe('halt');
    expect(file_get_contents($refused['manifest']))->toBe($before);
});

it('appends a --decision without --from and leaves the cursor where it is', function () {
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'review-pr', 'status' => 'done'], 'decisions' => ['Keep the guard']]);

    expect(dispatch_cli(['launch', $fixture['manifest'], $fixture['diff'], '--decision', 'Fold in #53'])['json'])->toBe(['action' => 'done']);
    expect(manifest_read($fixture['manifest']))->toMatchArray(['cursor' => ['leg' => 'review-pr', 'status' => 'done'], 'decisions' => ['Keep the guard', 'Fold in #53']]);
});

it('refuses a launch it cannot parse', function (array $arguments) {
    expect(dispatch_cli(['launch', ...$arguments])['code'])->toBe(1);
})->with([
    'no diff file' => [['/tmp/m.json']],
    'a decision without its text' => [['/tmp/m.json', '/tmp/d.diff', '--decision']],
    'a from without its leg' => [['/tmp/m.json', '/tmp/d.diff', '--from']],
]);

it('serves brief and finish on autoflow runs only, and leaves the manifest alone', function (array $arguments, string $reason) {
    $fixture = dispatch_fixture(['cursor' => ['leg' => 'implement', 'status' => 'pending']]);
    $before = file_get_contents($fixture['manifest']);

    expect(dispatch_cli([$arguments[0], $fixture['manifest'], ...array_slice($arguments, 1)])['json'])
        ->toBe(['action' => 'halt', 'reason' => $reason]);
    expect(file_get_contents($fixture['manifest']))->toBe($before);
})->with([
    'brief' => [['brief', 'implement', 'run'], "brief serves autoflow steps; this run's mode is interactive (resume it with /pipeline, which uses next)"],
    'finish' => [['finish', '{"action":"done"}'], "finish records autoflow runs; this run's mode is interactive (resume it with /pipeline, which uses next)"],
]);

it('refuses a manifest that still says auto in every command, naming autoflow, and leaves it alone', function (array $arguments) {
    $fixture = dispatch_fixture(['mode' => 'auto']);
    manifest_write($fixture['before'], manifest_read($fixture['manifest']));
    $before = file_get_contents($fixture['manifest']);
    $files = ['<manifest>' => $fixture['manifest'], '<diff>' => $fixture['diff']];

    expect(dispatch_cli(array_map(fn (string $argument) => $files[$argument] ?? $argument, $arguments))['json'])
        ->toBe(['action' => 'halt', 'reason' => pipeline_retired_mode('auto')]);
    expect(file_get_contents($fixture['manifest']))->toBe($before);
    expect(is_file($fixture['brief']))->toBeFalse();
})->with([
    'next' => [['next', '<manifest>']],
    'returned' => [['returned', '<manifest>', '<diff>']],
    'launch' => [['launch', '<manifest>', '<diff>']],
    'brief' => [['brief', '<manifest>', 'review-plan', 'review']],
    'finish' => [['finish', '<manifest>', '{"action":"done"}']],
    'ci' => [['ci', '<manifest>']],
]);

it('prints the committed spec\'s design size, bare', function (string $spec, string $size) {
    $fixture = dispatch_fixture(['artifacts' => ['spec' => 'spec.md', 'plan' => null, 'pr' => null, 'issue' => null]]);
    file_put_contents($fixture['dir'] . '/spec.md', $spec);

    expect(dispatch_cli(['size', $fixture['manifest']]))->toMatchArray(['code' => 0, 'stdout' => "{$size}\n"]);
})->with([
    'Bounded' => ["# x — design\n\n**Design size:** Bounded\n", 'Bounded'],
    'Architectural' => ["# x — design\n\n**Design size:** Architectural\n", 'Architectural'],
    'no header' => ["# x — design\n", 'Architectural'],
]);

it('prints whether a diff touches UI, bare', function (string $diff, string $ui) {
    $fixture = dispatch_fixture();
    file_put_contents($fixture['diff'], $diff);

    expect(dispatch_cli(['ui', $fixture['diff']]))->toMatchArray(['code' => 0, 'stdout' => "{$ui}\n"]);
})->with([
    'a view' => [dispatch_ui_diff(), 'true'],
    'no view' => ["+++ b/app/X.php\n@@ -1,0 +1,1 @@\n+<?php\n", 'false'],
]);

it('refuses size without a readable manifest and ui without a diff file', function () {
    expect(dispatch_cli(['size', '/nonexistent/m.json']))->toMatchArray(['code' => 1, 'stdout' => '']);
    expect(dispatch_cli(['ui', '/nonexistent/x.diff']))->toMatchArray(['code' => 1, 'stdout' => '']);
});

/**
 * A finished autoflow run on PR 7, with a fake gh first on PATH that answers `pr view` from pr.json (none:
 * gh fails) and a fake git that answers `rev-parse HEAD` from head (none: git fails).
 */
function ci_fixture(?array $view, bool $workflows = true, array $decisions = [], ?string $head = 'abc123', array $extra = []): array
{
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'review-pr', 'status' => 'done'], 'artifacts' => ['spec' => null, 'plan' => null, 'pr' => 7, 'issue' => null], 'decisions' => $decisions, ...$extra]);
    mkdir($fixture['dir'] . '/bin');
    file_put_contents($fixture['dir'] . '/bin/gh', <<<'SH'
#!/bin/sh
echo "$*" >> "$GH_FAKE/calls"
[ -f "$GH_FAKE/pr.json" ] || { echo 'HTTP 502: Bad Gateway' >&2; exit 1; }
cat "$GH_FAKE/pr.json"
SH);
    chmod($fixture['dir'] . '/bin/gh', 0755);
    file_put_contents($fixture['dir'] . '/bin/git', <<<'SH'
#!/bin/sh
[ -f "$GH_FAKE/head" ] || { echo 'fatal: not a git repository' >&2; exit 128; }
cat "$GH_FAKE/head"
SH);
    chmod($fixture['dir'] . '/bin/git', 0755);
    if ($head !== null) {
        file_put_contents($fixture['dir'] . '/head', $head);
    }
    if ($view !== null) {
        file_put_contents($fixture['dir'] . '/pr.json', json_encode($view));
    }
    if ($workflows) {
        mkdir($fixture['dir'] . '/.github/workflows', 0777, true);
        file_put_contents($fixture['dir'] . '/.github/workflows/ci.yml', "on: pull_request\n");
    }

    return [...$fixture, 'env' => ['PATH' => $fixture['dir'] . '/bin:' . getenv('PATH'), 'GH_FAKE' => $fixture['dir']]];
}

function ci_gate(array $fixture, array $arguments = []): array
{
    return dispatch_cli(['ci', $fixture['manifest'], ...$arguments], $fixture['env']);
}

function ci_head(string $conclusion, string $mergeable = 'MERGEABLE'): array
{
    return ['headRefOid' => 'abc123', 'mergeable' => $mergeable, 'statusCheckRollup' => [['__typename' => 'CheckRun', 'name' => 'ci', 'workflowName' => 'CI', 'status' => 'COMPLETED', 'conclusion' => $conclusion, 'detailsUrl' => 'https://github.com/acme/app/actions/runs/11/job/12']]];
}

it('gates the PR\'s head commit with one gh read, and never writes the manifest', function () {
    $fixture = ci_fixture(ci_head('SUCCESS'));
    $before = file_get_contents($fixture['manifest']);

    $result = ci_gate($fixture);

    expect($result['code'])->toBe(0);
    expect($result['json'])->toBe(['action' => 'ready', 'verdict' => 'green', 'sha' => 'abc123']);
    expect(file($fixture['dir'] . '/calls', FILE_IGNORE_NEW_LINES))->toBe(['pr view 7 --json headRefOid,mergeable,statusCheckRollup']);
    expect(file_get_contents($fixture['manifest']))->toBe($before);
});

it('answers a red head with a fix round the first time, and with a halt finish records once the round is spent', function () {
    $decision = "CI red on the PR's head commit abc123: CI / ci failed (https://github.com/acme/app/actions/runs/11/job/12)";
    $first = ci_fixture(ci_head('FAILURE'));
    $before = file_get_contents($first['manifest']);

    expect(ci_gate($first)['json'])->toMatchArray(['action' => 'fix', 'verdict' => 'red', 'decision' => $decision]);
    expect(file_get_contents($first['manifest']))->toBe($before);

    $spent = ci_fixture(ci_head('FAILURE'), true, [$decision]);
    $halt = ci_gate($spent)['stdout'];
    $reason = 'CI red again after the fix round, on abc123: CI / ci failed (https://github.com/acme/app/actions/runs/11/job/12)';
    expect(json_decode($halt, true))->toMatchArray(['action' => 'halt', 'leg' => 'review-pr', 'reason' => $reason]);

    dispatch_cli(['finish', $spent['manifest'], trim($halt)]);
    expect(manifest_read($spent['manifest'])['cursor'])->toBe(['leg' => 'review-pr', 'status' => 'halted', 'reason' => $reason]);
});

it('waits on no checks while the worktree has workflows, and answers ready at once without them', function () {
    $none = ['headRefOid' => 'abc123', 'mergeable' => 'MERGEABLE', 'statusCheckRollup' => []];

    expect(ci_gate(ci_fixture($none))['json'])->toBe(['action' => 'wait', 'verdict' => 'none', 'sha' => 'abc123']);
    expect(ci_gate(ci_fixture($none), ['--poll', '3'])['json'])->toBe(['action' => 'ready', 'verdict' => 'none', 'sha' => 'abc123']);
    expect(ci_gate(ci_fixture($none, false))['json'])->toBe(['action' => 'ready', 'verdict' => 'none', 'sha' => 'abc123']);
});

it('waits while gh cannot read the PR, and halts at the last read', function () {
    expect(ci_gate(ci_fixture(null))['json'])->toBe(['action' => 'wait', 'verdict' => 'unreadable']);
    expect(ci_gate(ci_fixture(null), ['--poll', '120'])['json'])->toMatchArray(['action' => 'halt', 'leg' => 'review-pr']);
});

it('answers the issue\'s three through the CLI: a conflicting PR fix, an unknown one wait, a clean one ready (#149)', function () {
    $view = fn (string $mergeable) => ['headRefOid' => 'abc123', 'mergeable' => $mergeable, 'statusCheckRollup' => []];
    $decision = "Conflict with the base on the PR's head commit abc123: GitHub reports PR #7 CONFLICTING with its base; review-pr's resolve step merges the base (engine.md §Catching up with the base)";
    $conflicting = ci_fixture($view('CONFLICTING'), false);
    $before = file_get_contents($conflicting['manifest']);

    expect(ci_gate($conflicting)['json'])->toBe(['action' => 'fix', 'verdict' => 'conflicting', 'sha' => 'abc123', 'decision' => $decision]);
    expect(file_get_contents($conflicting['manifest']))->toBe($before);
    expect(ci_gate(ci_fixture($view('UNKNOWN'), false))['json'])->toBe(['action' => 'wait', 'verdict' => 'unknown', 'sha' => 'abc123']);
    expect(ci_gate(ci_fixture($view('MERGEABLE'), false))['json'])->toBe(['action' => 'ready', 'verdict' => 'none', 'sha' => 'abc123']);
});

it('halts a conflict once the conflict round is spent, and finish records it on review-pr (#149)', function () {
    $decision = "Conflict with the base on the PR's head commit abc123: GitHub reports PR #7 CONFLICTING with its base; review-pr's resolve step merges the base (engine.md §Catching up with the base)";
    $reason = 'PR #7 conflicts with its base again after the conflict round, on abc123: merge the base into the branch (engine.md §Catching up with the base), push, and run the CI gate again';
    $spent = ci_fixture(ci_head('SUCCESS', 'CONFLICTING'), true, [$decision]);

    $halt = ci_gate($spent)['stdout'];
    expect(json_decode($halt, true))->toBe(['action' => 'halt', 'leg' => 'review-pr', 'reason' => $reason, 'verdict' => 'conflicting', 'sha' => 'abc123']);

    dispatch_cli(['finish', $spent['manifest'], trim($halt)]);
    expect(manifest_read($spent['manifest'])['cursor'])->toBe(['leg' => 'review-pr', 'status' => 'halted', 'reason' => $reason]);
});

it('gates an interactive run as well, since its finish step runs the same loop', function () {
    $fixture = ci_fixture(ci_head('SUCCESS'));
    manifest_write($fixture['manifest'], [...manifest_read($fixture['manifest']), 'mode' => 'interactive']);

    expect(ci_gate($fixture)['json'])->toBe(['action' => 'ready', 'verdict' => 'green', 'sha' => 'abc123']);
});

it('does not answer ready while GitHub\'s head is not the worktree\'s HEAD, and halts on it at the third read', function () {
    $fixture = ci_fixture(ci_head('SUCCESS'), true, [], 'def456');
    $before = file_get_contents($fixture['manifest']);
    $reason = "PR #7's head on GitHub is abc123, but the worktree's HEAD is def456: the two must match before its checks count; push the branch, or reconcile it when GitHub is ahead, and run the CI gate again";

    expect(ci_gate($fixture)['json'])->toBe(['action' => 'wait', 'verdict' => 'mismatch', 'sha' => 'abc123', 'head' => 'def456']);
    $halt = ci_gate($fixture, ['--poll', '3'])['stdout'];
    expect(json_decode($halt, true))->toBe(['action' => 'halt', 'leg' => 'review-pr', 'reason' => $reason, 'verdict' => 'mismatch', 'sha' => 'abc123', 'head' => 'def456']);
    expect(file_get_contents($fixture['manifest']))->toBe($before);

    dispatch_cli(['finish', $fixture['manifest'], trim($halt)]);
    expect(manifest_read($fixture['manifest'])['cursor'])->toBe(['leg' => 'review-pr', 'status' => 'halted', 'reason' => $reason]);
});

it('halts the gate at once when git cannot read the worktree\'s HEAD, before gh is asked', function () {
    $fixture = ci_fixture(ci_head('SUCCESS'), true, [], null);
    $before = file_get_contents($fixture['manifest']);

    expect(ci_gate($fixture)['json'])->toBe(['action' => 'halt', 'reason' => "the CI gate cannot read the worktree's HEAD at {$fixture['dir']}: fatal: not a git repository"]);
    expect(is_file($fixture['dir'] . '/calls'))->toBeFalse();
    expect(file_get_contents($fixture['manifest']))->toBe($before);
});

it('halts the gate on a run without a PR or a readable manifest, and leaves the manifest alone', function () {
    $fixture = dispatch_fixture(['mode' => 'autoflow']);
    $before = file_get_contents($fixture['manifest']);

    expect(dispatch_cli(['ci', $fixture['manifest']])['json'])->toBe(['action' => 'halt', 'reason' => 'the CI gate needs a PR: artifacts.pr is not set']);
    expect(file_get_contents($fixture['manifest']))->toBe($before);
    expect(dispatch_cli(['ci', '/nonexistent/m.json'])['json'])->toBe(['action' => 'halt', 'reason' => 'no readable manifest at /nonexistent/m.json']);
});

it('refuses a ci it cannot parse', function (array $arguments) {
    expect(ci_gate(ci_fixture(ci_head('SUCCESS')), $arguments)['code'])->toBe(1);
})->with([
    'a poll without its value' => [['--poll']],
    'a poll of zero' => [['--poll', '0']],
    'a poll that is no number' => [['--poll', 'soon']],
    'an unknown flag' => [['--wait', '3']],
]);

/**
 * A finished run on PR 7 in a real repo: after the review at its recorded commit, the finish step merged a
 * main that changed a file the branch changes. gh is a fake that cannot read the PR.
 */
function ci_merge_fixture(string $mode, array $decisions = []): array
{
    $dir = rereview_repo(['shared.php' => "a\nb\nc\nd\ne\nf\ng\n"]);
    $reviewed = rereview_commit($dir, ['shared.php' => "A\nb\nc\nd\ne\nf\ng\n"]);
    rereview_main_moves($dir, ['shared.php' => "a\nb\nc\nd\ne\nf\nG\n"]);
    $head = rereview_merge($dir);
    $fixture = dispatch_fixture([
        'mode' => $mode, 'worktree' => $dir, 'cursor' => ['leg' => 'review-pr', 'status' => 'done'],
        'artifacts' => ['spec' => null, 'plan' => null, 'pr' => 7, 'issue' => null],
        'gate_ledger' => [rereview_entry('continued', $reviewed)], 'decisions' => $decisions,
    ]);
    mkdir($fixture['dir'] . '/bin');
    file_put_contents($fixture['dir'] . '/bin/gh', "#!/bin/sh\necho 'HTTP 502: Bad Gateway' >&2\nexit 1\n");
    chmod($fixture['dir'] . '/bin/gh', 0755);

    return [...$fixture, 'head' => $head, 'env' => ['PATH' => $fixture['dir'] . '/bin:' . getenv('PATH')]];
}

it('answers the merge round for an autoflow run whose finish step merged a shared file, once, and never for an interactive run', function () {
    $flow = ci_merge_fixture('autoflow');
    $before = file_get_contents($flow['manifest']);
    $decision = "Unreviewed merge on the PR's head commit {$flow['head']}: a merge since the last completed review met this branch's changes in shared.php";

    expect(ci_gate($flow)['json'])->toBe(['action' => 'fix', 'verdict' => 'merge', 'files' => ['shared.php'], 'decision' => $decision]);
    expect(file_get_contents($flow['manifest']))->toBe($before);

    expect(ci_gate(ci_merge_fixture('autoflow', [$decision]))['json'])->toBe(['action' => 'wait', 'verdict' => 'unreadable']);
    expect(ci_gate(ci_merge_fixture('interactive'))['json'])->toBe(['action' => 'wait', 'verdict' => 'unreadable']);
});

/**
 * A completed pr-review entry whose resolve step left one blocking open question, `gate_ledger[0].actions[0]`. It has
 * no `reviewed_sha`, so the merge round finds no review to scope from (`pipeline_review_base()`) and stays out of it.
 */
function ci_asking_ledger(): array
{
    return ['gate_ledger' => [[
        'gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => '2026-10-02T10:00:00Z', 'review' => 'r',
        'actions' => [['claim' => 'Queue or cron?', 'disposition' => 'open-question', 'note' => 'cron (built), queue', 'kind' => 'blocking']],
        'outcome' => 'continued',
    ]]];
}

it('answers ask on an unanswered autoflow run without asking git or gh, and ready once launch recorded the answer (#146)', function () {
    $fixture = ci_fixture(ci_head('SUCCESS'), true, [], null, ci_asking_ledger());
    $decision = 'Answer to open question gate_ledger[0].actions[0] ("Queue or cron?"): ';

    $asked = ci_gate($fixture)['json'];
    expect($asked['action'])->toBe('ask');
    expect(array_column($asked['questions'], 'decision'))->toBe([$decision]);
    expect(is_file($fixture['dir'] . '/calls'))->toBeFalse();

    file_put_contents($fixture['dir'] . '/head', 'abc123');
    expect(dispatch_cli(['launch', $fixture['manifest'], $fixture['diff'], '--decision', "{$decision}cron, as built"])['json'])->toBe(['action' => 'done']);
    expect(ci_gate($fixture)['json'])->toBe(['action' => 'ready', 'verdict' => 'green', 'sha' => 'abc123']);
});

it('asks again after an answer recorded under a mistyped prefix, and never in an interactive run (#146)', function () {
    $typo = ci_fixture(ci_head('SUCCESS'), true, ['Answer to open question gate_ledger[0].action[0] ("Queue or cron?"): cron'], 'abc123', ci_asking_ledger());
    expect(ci_gate($typo)['json']['action'])->toBe('ask');

    $interactive = ci_fixture(ci_head('SUCCESS'), true, [], 'abc123', ['mode' => 'interactive', ...ci_asking_ledger()]);
    expect(ci_gate($interactive)['json'])->toBe(['action' => 'ready', 'verdict' => 'green', 'sha' => 'abc123']);
});

function kickoff_issue(array $overrides = []): array
{
    return ['number' => 69, 'title' => 'Pipeline: kickoff as one command', 'html_url' => 'https://github.com/acme/app/issues/69', 'pull_request' => null, ...$overrides];
}

/** What the fake gh answers: the issue and its blockers; a null leaves the file out, so that call fails. */
function kickoff_gh(array $fixture, ?array $issue, ?array $blockers = []): void
{
    foreach (['issue.json' => $issue, 'blocked_by.json' => $blockers] as $file => $answer) {
        $path = $fixture['dir'] . '/' . $file;
        $answer === null ? @unlink($path) : file_put_contents($path, json_encode($answer));
    }
}

/**
 * A bare origin with one commit on main, a clone of it as the primary checkout with a work-on config,
 * and a fake gh first on PATH that logs each call. The default create is this repo's own: it leaves an
 * upstream on the new branch.
 */
function kickoff_fixture(string $create = 'git worktree add .claude/worktrees/<branch> -b <branch> origin/main', string $extraConfig = ''): array
{
    $dir = sys_get_temp_dir() . '/pipeline-kickoff-' . uniqid();
    mkdir($dir . '/bin', 0777, true);
    $seed = suite_repo();
    pipeline_git($seed, ['branch', '-M', 'main']);
    pipeline_git($dir, ['clone', '-q', '--bare', $seed, $dir . '/origin.git']);
    pipeline_git($dir, ['clone', '-q', $dir . '/origin.git', $dir . '/primary']);
    mkdir($dir . '/primary/.claude');
    file_put_contents($dir . '/primary/.claude/work-on.config.md', implode("\n", [
        '# work-on — per-repo config', '',
        '## Repo', '- repo: acme/app', '',
        '## Worktree', "- create: {$create}", '',
        '## Branch convention', '- issue: feature/issue-<number>-<slug>   # issue pickup', '',
        $extraConfig,
    ]));
    file_put_contents($dir . '/bin/gh', <<<'SH'
#!/bin/sh
echo "$*" >> "$GH_FAKE/calls"
case "$*" in
  *dependencies/blocked_by) cat "$GH_FAKE/blocked_by.json" ;;
  "api repos/"*) cat "$GH_FAKE/issue.json" ;;
  "project item-add"*) [ -f "$GH_FAKE/board-fails" ] && { echo 'HTTP 401: Bad credentials' >&2; exit 1; }; echo '{"id":"ITEM_1"}' ;;
  "project item-edit"*) ;;
  *) echo "unexpected gh $*" >&2; exit 1 ;;
esac
SH);
    chmod($dir . '/bin/gh', 0755);
    $fixture = ['dir' => $dir, 'primary' => $dir . '/primary', 'env' => ['PATH' => $dir . '/bin:' . getenv('PATH'), 'GH_FAKE' => $dir]];
    kickoff_gh($fixture, kickoff_issue());

    return $fixture;
}

function kickoff(array $fixture, array $arguments): array
{
    return dispatch_cli(['kickoff', $fixture['primary'], ...$arguments], $fixture['env']);
}

/** @return list<string> the fake gh's calls, in order */
function kickoff_calls(array $fixture): array
{
    return is_file($fixture['dir'] . '/calls') ? file($fixture['dir'] . '/calls', FILE_IGNORE_NEW_LINES) : [];
}

/** Nothing was created: the primary checkout is still the only worktree and the branch does not exist. */
function kickoff_left_nothing(array $fixture, string $branch = 'feature/issue-69-pipeline-kickoff-as-one-command'): void
{
    expect(preg_match_all('/^worktree /m', pipeline_git($fixture['primary'], ['worktree', 'list', '--porcelain'])))->toBe(1);
    expect(pipeline_git_run($fixture['primary'], ['rev-parse', '--verify', '--quiet', "refs/heads/{$branch}"])[0])->not->toBe(0);
}

it('kicks off an issue: the declared create, no upstream, the manifest excluded and written', function () {
    $fixture = kickoff_fixture();
    $branch = 'feature/issue-69-pipeline-kickoff-as-one-command';

    $ready = kickoff($fixture, ['69'])['json'];

    expect($ready)->toMatchArray(['action' => 'ready', 'branch' => $branch, 'notes' => []]);
    $worktree = $ready['worktree'];
    expect($worktree)->toBe(realpath($fixture['primary'] . '/.claude/worktrees/' . $branch));
    expect($ready['manifest'])->toBe($worktree . '/.claude/pipeline/feature-issue-69-pipeline-kickoff-as-one-command.json');
    expect(manifest_read($ready['manifest']))->toBe([
        'branch' => $branch,
        'worktree' => $worktree,
        'mode' => 'autoflow',
        'cursor' => ['leg' => 'design', 'status' => 'pending'],
        'artifacts' => ['issue' => 69],
    ]);
    expect(pipeline_git_run($worktree, ['rev-parse', '--abbrev-ref', "{$branch}@{upstream}"])[0])->not->toBe(0);
    expect(pipeline_git_run($worktree, ['check-ignore', '-q', '.claude/pipeline/any.json'])[0])->toBe(0);
    expect(kickoff_calls($fixture))->toBe(['api repos/acme/app/issues/69', 'api /repos/acme/app/issues/69/dependencies/blocked_by']);
});

it('writes the mode, the tier and the decisions verbatim into the first manifest', function (array $arguments, string $tier) {
    $fixture = kickoff_fixture();

    $ready = kickoff($fixture, [...$arguments, '--mode', 'autoflow', '--decision', 'Fold in #53: add pipeline_ledger()', '--decision', 'Keep the guard'])['json'];
    $manifest = manifest_read($ready['manifest']);

    expect($manifest)->toMatchArray([
        'mode' => 'autoflow',
        'tier' => $tier,
        'decisions' => ['Fold in #53: add pipeline_ledger()', 'Keep the guard'],
    ]);
    expect($manifest)->not->toHaveKey('light');
})->with([
    'medium' => [['#69', '--medium'], 'medium'],
    'light' => [['#69', '--light'], 'light'],
    'light, before the item' => [['--light', '#69'], 'light'],
]);

it('halts a kickoff for the removed auto mode before anything is created, naming autoflow', function () {
    $fixture = kickoff_fixture();

    expect(kickoff($fixture, ['69', '--mode', 'auto']))->toMatchArray(['code' => 0, 'json' => ['action' => 'halt', 'reason' => pipeline_retired_mode('auto')]]);
    kickoff_left_nothing($fixture);
    expect(kickoff_calls($fixture))->toBe([]);
});

it('kicks off an idea on a feature branch without asking gh', function () {
    $fixture = kickoff_fixture();

    $ready = kickoff($fixture, ['Add a dark-mode toggle!'])['json'];

    expect($ready)->toMatchArray(['action' => 'ready', 'branch' => 'feature/add-a-dark-mode-toggle']);
    expect(manifest_read($ready['manifest'])['artifacts'])->toBe(['idea' => 'Add a dark-mode toggle!']);
    expect(kickoff_calls($fixture))->toBe([]);
});

it('halts on an open blocker and leaves nothing behind', function () {
    $fixture = kickoff_fixture();
    kickoff_gh($fixture, kickoff_issue(), [
        ['number' => 65, 'title' => 'owners.py reads the wrong column', 'state' => 'open'],
        ['number' => 60, 'title' => 'already done', 'state' => 'closed'],
    ]);

    expect(kickoff($fixture, ['69'])['json'])->toBe(['action' => 'halt', 'reason' => '#69 is blocked by #65 owners.py reads the wrong column']);
    kickoff_left_nothing($fixture);
});

it('halts before anything is created when the work item cannot be read or started', function (?array $issue, ?array $blockers, string $reason) {
    $fixture = kickoff_fixture();
    kickoff_gh($fixture, $issue, $blockers);

    $halt = kickoff($fixture, ['69'])['json'];

    expect($halt['action'])->toBe('halt');
    expect($halt['reason'])->toContain($reason);
    kickoff_left_nothing($fixture);
})->with([
    'gh cannot read the issue' => [null, [], '#69 does not resolve in acme/app'],
    'gh cannot read the blockers' => [kickoff_issue(), null, 'the blockers of #69 could not be read'],
    'a pull request' => [kickoff_issue(['pull_request' => ['url' => 'x']]), [], '#69 is a pull request'],
]);

it('halts on a declared create that needs a value kickoff does not compute', function () {
    $fixture = kickoff_fixture('./scripts/worktree.sh create <branch> --slot <next-free-N>');

    expect(kickoff($fixture, ['69'])['json'])->toBe([
        'action' => 'halt',
        'reason' => 'the declared worktree.create needs <next-free-N>, which kickoff does not compute: `- create: ./scripts/worktree.sh create <branch> --slot <next-free-N>`',
    ]);
    kickoff_left_nothing($fixture);
});

it('halts with the output of a create that fails, and retries nothing', function (string $create, string $exit, string $output) {
    $fixture = kickoff_fixture($create);

    $halt = kickoff($fixture, ['69'])['json'];

    expect($halt['action'])->toBe('halt');
    expect($halt['reason'])->toContain("the declared worktree.create failed ({$exit})")->toContain($output);
    kickoff_left_nothing($fixture);
})->with([
    'denied' => ["echo 'Permission for this action was denied' >&2; exit 3", 'exit 3', 'Permission for this action was denied'],
    'waits on stdin' => ["read answer || { echo 'no answer'; exit 4; }", 'exit 4', 'no answer'],
]);

it('halts when a create exits 0 without a worktree for the branch', function () {
    $fixture = kickoff_fixture('true');

    expect(kickoff($fixture, ['69'])['json']['reason'])->toContain('the declared worktree.create exited 0 but no worktree has feature/issue-69-pipeline-kickoff-as-one-command');
});

it('halts when the branch already exists, before the create runs', function () {
    $fixture = kickoff_fixture("touch created; git worktree add .claude/worktrees/<branch> <branch>");
    pipeline_git($fixture['primary'], ['branch', 'feature/issue-69-pipeline-kickoff-as-one-command', 'origin/main']);

    expect(kickoff($fixture, ['69'])['json'])->toBe([
        'action' => 'halt',
        'reason' => 'branch feature/issue-69-pipeline-kickoff-as-one-command already exists: a run or session has it; resume it with launch',
    ]);
    expect(is_file($fixture['primary'] . '/created'))->toBeFalse();
});

it('halts on another branch for the issue, whoever named it, and on no other issue\'s', function () {
    $fixture = kickoff_fixture("touch created; git worktree add .claude/worktrees/<branch> -b <branch> origin/main");
    pipeline_git($fixture['primary'], ['branch', 'feature/issue-690-another-issue', 'origin/main']);
    pipeline_git($fixture['primary'], ['branch', 'feature/issue-69-hand-made', 'origin/main']);

    expect(kickoff($fixture, ['69'])['json'])->toBe([
        'action' => 'halt',
        'reason' => 'branch feature/issue-69-hand-made already exists: a run or session has it; resume it with launch',
    ]);
    expect(is_file($fixture['primary'] . '/created'))->toBeFalse();

    pipeline_git($fixture['primary'], ['branch', '-D', 'feature/issue-69-hand-made']);
    expect(kickoff($fixture, ['69'])['json']['action'])->toBe('ready');
});

it('halts with git\'s message, as one JSON line, when git fails in the primary checkout', function () {
    $fixture = kickoff_fixture();
    exec('rm -rf ' . escapeshellarg($fixture['primary'] . '/.git'));

    $answer = dispatch_cli(['kickoff', $fixture['primary'], '69'], [...$fixture['env'], 'GIT_CEILING_DIRECTORIES' => $fixture['dir']]);

    expect($answer['code'])->toBe(0);
    expect($answer['json']['action'])->toBe('halt');
    expect($answer['json']['reason'])->toContain('not a git repository');
});

it('refuses a kickoff it cannot parse', function (array $arguments) {
    $fixture = kickoff_fixture();

    expect(kickoff($fixture, $arguments)['code'])->toBe(1);
    expect(dispatch_cli(['kickoff'])['code'])->toBe(1);
})->with([
    'no item' => [[]],
    'interactive' => [['69', '--mode', 'interactive']],
    'an unknown flag' => [['69', '--sideways']],
    'a flag without its value' => [['69', '--decision']],
    'a base without its value' => [['69', '--base']],
    'two items' => [['69', '70']],
    'two tier flags' => [['69', '--medium', '--light']],
    'a tier flag twice' => [['69', '--light', '--light']],
]);

/**
 * An integration branch on origin, one commit ahead of main, that the primary has no ref for (so only
 * kickoff's fetch can bring it back), and a `create-wt` on PATH that honours `--base`. @return string its sha
 */
function kickoff_integration_branch(array $fixture, string $base = 'feature/integration'): string
{
    $git = fn (array $args) => pipeline_git($fixture['primary'], ['-c', 'user.email=t@example.com', '-c', 'user.name=T', '-c', 'commit.gpgsign=false', ...$args]);
    $git(['switch', '-q', '-c', $base]);
    $git(['commit', '-q', '--allow-empty', '-m', 'integration work']);
    $sha = $git(['rev-parse', 'HEAD']);
    $git(['push', '-q', 'origin', $base]);
    $git(['switch', '-q', 'main']);
    $git(['branch', '-q', '-D', $base]);
    $git(['update-ref', '-d', "refs/remotes/origin/{$base}"]);
    file_put_contents($fixture['dir'] . '/bin/create-wt', <<<'SH'
#!/bin/sh
branch=$1; shift; base=origin/main
while [ $# -gt 0 ]; do case $1 in --base) base=$2; shift 2 ;; *) shift ;; esac; done
git worktree add -q ".claude/worktrees/$branch" -b "$branch" "$base"
SH);
    chmod($fixture['dir'] . '/bin/create-wt', 0755);

    return $sha;
}

it('halts before anything is created on a base that is unsafe, not a branch on origin, or the default branch', function (string $base, string $reason) {
    $fixture = kickoff_fixture('touch created; create-wt <branch>');
    kickoff_integration_branch($fixture);

    $halt = kickoff($fixture, ['69', '--base', $base])['json'];

    expect($halt['action'])->toBe('halt');
    expect($halt['reason'])->toContain($reason);
    expect(is_file($fixture['primary'] . '/created'))->toBeFalse();
    kickoff_left_nothing($fixture);
})->with([
    'missing on origin' => ['feature/nope', 'the base feature/nope is not a branch on origin'],
    'unsafe for sh' => ['main; rm -rf /', "the base 'main; rm -rf /' holds characters kickoff will not pass to a shell"],
    'the default branch' => ['main', "the base main is origin's default branch: leave --base out"],
]);

it('kicks off on a per-run base: cut from it, recorded in the manifest, and no gh-merge-base on the branch', function () {
    $fixture = kickoff_fixture('create-wt <branch> --no-start');
    $sha = kickoff_integration_branch($fixture);
    $branch = 'feature/issue-69-pipeline-kickoff-as-one-command';

    $ready = kickoff($fixture, ['69', '--base', 'feature/integration'])['json'];

    expect($ready)->toMatchArray(['action' => 'ready', 'branch' => $branch]);
    expect(pipeline_git($ready['worktree'], ['rev-parse', 'HEAD']))->toBe($sha);
    expect(pipeline_git_run($ready['worktree'], ['config', "branch.{$branch}.gh-merge-base"])[0])->not->toBe(0);
    expect(manifest_read($ready['manifest']))->toMatchArray(['base' => 'feature/integration', 'artifacts' => ['issue' => 69]]);
});

it('halts when the declared create does not honour the base, naming the worktree it left', function () {
    $fixture = kickoff_fixture("sh -c 'git worktree add -q .claude/worktrees/\$0 -b \$0 origin/main' <branch>");
    $sha = kickoff_integration_branch($fixture);
    $main = pipeline_git($fixture['primary'], ['rev-parse', 'origin/main']);

    $halt = kickoff($fixture, ['69', '--base', 'feature/integration'])['json'];

    expect($halt['action'])->toBe('halt');
    expect($halt['reason'])
        ->toContain('kickoff created')
        ->toContain("the worktree's HEAD ({$main}) is not origin/feature/integration ({$sha}): the declared worktree.create did not honour --base")
        ->toContain('remove the worktree and its branch before kicking off again');
});

function kickoff_board(): string
{
    return implode("\n", ['## Board', '- org: acme', '- number: 7', '- project-id: PVT_1', '- status-field-id: F_1', '- in-progress-option-id: O_1', '']);
}

it('claims the issue on a valid board after the create, and says so', function () {
    $fixture = kickoff_fixture(extraConfig: kickoff_board());

    expect(kickoff($fixture, ['69'])['json'])->toMatchArray(['action' => 'ready', 'notes' => ['#69 is In Progress on board 7']]);
    expect(array_slice(kickoff_calls($fixture), 2))->toBe([
        'project item-add 7 --owner acme --url https://github.com/acme/app/issues/69 --format json',
        'project item-edit --id ITEM_1 --project-id PVT_1 --field-id F_1 --single-select-option-id O_1',
    ]);
});

it('reports a board claim that could not be recorded, and still kicks off', function () {
    $fixture = kickoff_fixture(extraConfig: kickoff_board());
    touch($fixture['dir'] . '/board-fails');

    $ready = kickoff($fixture, ['69'])['json'];

    expect($ready['action'])->toBe('ready');
    expect($ready['notes'])->toBe(['the board claim was not recorded: HTTP 401: Bad credentials']);
});

it('claims nothing when the create fails, and nothing for an idea', function () {
    $failing = kickoff_fixture("echo denied >&2; exit 1", kickoff_board());
    expect(kickoff($failing, ['69'])['json']['action'])->toBe('halt');
    expect(array_filter(kickoff_calls($failing), fn (string $call) => str_starts_with($call, 'project')))->toBe([]);

    $idea = kickoff_fixture(extraConfig: kickoff_board());
    expect(kickoff($idea, ['Add a toggle'])['json']['notes'])->toBe([]);
    expect(kickoff_calls($idea))->toBe([]);
});

it('halts on an invalid board before anything is created', function () {
    $fixture = kickoff_fixture(extraConfig: "## Board\n- org: acme\n");

    $halt = kickoff($fixture, ['69'])['json'];

    expect($halt['action'])->toBe('halt');
    expect($halt['reason'])->toContain('the ## Board section is invalid')->toContain('missing: number');
    expect(kickoff_calls($fixture))->toBe([]);
    kickoff_left_nothing($fixture);
});

it('scopes the review a launch --from review-pr starts with to what changed since the last completed review (#88)', function () {
    $dir = rereview_repo();
    $reviewed = rereview_commit($dir, ['feature.php' => "<?php // v1\n"]);
    rereview_commit($dir, ['feature.php' => "<?php // v2\n"]);
    pipeline_git($dir, ['switch', '-q', '-c', 'elsewhere', 'main']);
    $elsewhere = rereview_commit($dir, ['other.php' => "<?php\n"]);
    pipeline_git($dir, ['switch', '-q', 'feature']);
    $passed = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r', 'outcome' => 'continued'];
    $rereview = function (array $review) use ($dir, $passed): string {
        $fixture = dispatch_fixture(['mode' => 'autoflow', 'worktree' => $dir, 'cursor' => ['leg' => 'review-pr', 'status' => 'done'], 'gate_ledger' => [$passed, $review]]);
        expect(dispatch_cli(['launch', $fixture['manifest'], $fixture['diff'], '--from', 'review-pr'])['json'])
            ->toMatchArray(['action' => 'start', 'startLeg' => 'review-pr', 'startStep' => 'review']);

        return dispatch_cli(['brief', $fixture['manifest'], 'review-pr', 'review'])['stdout'];
    };

    expect($rereview(rereview_entry('continued', $reviewed)))
        ->toContain("a review of this PR completed at `{$reviewed}`")
        ->toContain("`git log -p --no-merges {$reviewed}..HEAD ^origin/main`");
    expect($rereview(rereview_entry('continued', $elsewhere)))->toContain('`review-pr` leg, `review` step')->not->toContain('Scoped re-review');
    expect($rereview(rereview_entry('halted', $reviewed)))->toContain('`review-pr` leg, `review` step')->not->toContain('Scoped re-review');
});

it('scopes an interactive run\'s re-review the same way', function () {
    $dir = rereview_repo();
    $reviewed = rereview_commit($dir, ['feature.php' => "<?php\n"]);
    $fixture = dispatch_fixture(['mode' => 'interactive', 'worktree' => $dir, 'cursor' => ['leg' => 'review-pr', 'status' => 'pending'], 'gate_ledger' => [rereview_entry('continued', $reviewed)]]);

    expect(dispatch_cli(['next', $fixture['manifest']])['json'])->toMatchArray(['action' => 'dispatch', 'leg' => 'review-pr', 'step' => 'review']);
    expect(file_get_contents($fixture['brief']))->toContain("a review of this PR completed at `{$reviewed}`");
});

it('prints the catch-up line first in the brief of a writing step behind its base, in both modes, and briefs without it when the fetch fails', function () {
    $behind = function (): string {
        $dir = base_repo(['shared.php' => "base\n"]);
        rereview_commit($dir, ['shared.php' => "feature\n"]);
        base_moves($dir, ['shared.php' => "main\n"]);

        return $dir;
    };
    $line = fn (string $dir) => "## Overrides\n\n- Catch up with the base first (engine.md §Catching up with the base): `origin/main` is 1 commit ahead and changed files this branch changes too (`shared.php`). Before any other work run `git -C {$dir} merge --no-edit origin/main`, as its own command in exactly that form.";

    $dir = $behind();
    $flow = dispatch_fixture(['mode' => 'autoflow', 'worktree' => $dir, 'cursor' => ['leg' => 'implement', 'status' => 'pending']]);
    expect(dispatch_cli(['brief', $flow['manifest'], 'implement', 'run'])['stdout'])->toContain($line($dir));

    $dir = $behind();
    $interactive = dispatch_fixture(['mode' => 'interactive', 'worktree' => $dir, 'cursor' => ['leg' => 'implement', 'status' => 'pending']]);
    expect(dispatch_cli(['next', $interactive['manifest']])['json'])->toMatchArray(['action' => 'dispatch', 'leg' => 'implement', 'step' => 'run']);
    expect(file_get_contents($interactive['brief']))->toContain($line($dir));

    $dir = $behind();
    pipeline_git($dir, ['remote', 'set-url', 'origin', dirname($dir) . '/moved.git']);
    $offline = dispatch_fixture(['mode' => 'autoflow', 'worktree' => $dir, 'cursor' => ['leg' => 'implement', 'status' => 'pending']]);
    expect(dispatch_cli(['brief', $offline['manifest'], 'implement', 'run'])['stdout'])
        ->toContain('`implement` leg, `run` step')
        ->not->toContain('Catch up with the base');
});

/** `dispatch_fixture()`'s artifacts with the run's proof page. */
function dispatch_proof_artifacts(string $page): array
{
    return ['artifacts' => ['spec' => null, 'plan' => null, 'pr' => null, 'issue' => null, 'proof' => $page]];
}

it('marks the proof page halted with the reason when finish records a halt', function () {
    $page = proof_test_page();
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'implement', 'status' => 'pending'], ...dispatch_proof_artifacts($page)]);

    $result = dispatch_cli(['finish', $fixture['manifest'], '{"action":"halt","leg":"implement","reason":"the suite stayed red"}']);

    expect($result['stdout'])->toBe("{\"action\":\"halt\",\"reason\":\"the suite stayed red\"}\n");
    expect(proof_read_run(dirname($page))['status'])->toBe(['state' => 'halted', 'reason' => 'the suite stayed red']);
    expect(file_get_contents($page))->toContain('pill-halted');
});

it('marks a resumed run\'s proof page running again when launch starts it', function () {
    $page = proof_test_page(['status' => ['state' => 'halted', 'reason' => 'CI red']]);
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'implement', 'status' => 'halted', 'reason' => 'CI red'], ...dispatch_proof_artifacts($page)]);

    expect(dispatch_cli(['launch', $fixture['manifest'], $fixture['diff']])['json']['action'])->toBe('start');
    expect(proof_read_run(dirname($page))['status'])->toBe(['state' => 'running']);
});

it('marks the proof page running when next dispatches a step', function () {
    $page = proof_test_page(['status' => ['state' => 'halted', 'reason' => 'stub']]);
    $fixture = dispatch_fixture(dispatch_proof_artifacts($page));

    expect(dispatch_cli(['next', $fixture['manifest']])['json']['action'])->toBe('dispatch');
    expect(proof_read_run(dirname($page))['status'])->toBe(['state' => 'running']);
});

it('leaves every page alone when the manifest names none', function () {
    $page = proof_test_page(['status' => ['state' => 'ready']]);
    $before = file_get_contents(dirname($page) . '/run.json');
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'implement', 'status' => 'pending']]);

    expect(dispatch_cli(['finish', $fixture['manifest'], '{"action":"halt","reason":"x"}'])['stdout'])->toBe("{\"action\":\"halt\",\"reason\":\"x\"}\n");
    expect(file_get_contents(dirname($page) . '/run.json'))->toBe($before);
});

it('records the halt and keeps its answer one JSON line when the page it names cannot be amended', function () {
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'implement', 'status' => 'pending'], ...dispatch_proof_artifacts('/nonexistent/Deploy/pr-5-logs/index.html')]);

    expect(dispatch_cli(['finish', $fixture['manifest'], '{"action":"halt","reason":"x"}']))->toMatchArray(['code' => 0, 'stdout' => "{\"action\":\"halt\",\"reason\":\"x\"}\n"]);
    expect(manifest_read($fixture['manifest'])['cursor'])->toBe(['leg' => 'implement', 'status' => 'halted', 'reason' => 'x']);
});

it('names the proof page in the done answer, so the session can mark it ready', function () {
    $page = proof_test_page();
    $open = ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r'];
    $fixture = dispatch_fixture(['cursor' => ['leg' => 'review-pr', 'status' => 'pending'], 'gate_ledger' => [$open], ...dispatch_proof_artifacts($page)]);
    dispatch_cli(['next', $fixture['manifest']]);
    dispatch_leg_writes($fixture['manifest'], fn (array $m) => [
        ...$m,
        'cursor' => [...$m['cursor'], 'status' => 'continued'],
        'gate_ledger' => [[...$open, 'actions' => [], 'outcome' => 'continued']],
    ]);

    expect(dispatch_cli(['returned', $fixture['manifest'], $fixture['diff']])['json'])->toBe(['action' => 'done', 'proof' => $page]);
});
