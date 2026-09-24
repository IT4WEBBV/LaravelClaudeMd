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

it('serves brief and finish on autoflow runs only, and leaves the manifest alone', function (array $arguments, string $reason) {
    $fixture = dispatch_fixture(['cursor' => ['leg' => 'implement', 'status' => 'pending']]);
    $before = file_get_contents($fixture['manifest']);

    expect(dispatch_cli([$arguments[0], $fixture['manifest'], ...array_slice($arguments, 1)])['json'])
        ->toBe(['action' => 'halt', 'reason' => $reason]);
    expect(file_get_contents($fixture['manifest']))->toBe($before);
})->with([
    'brief' => [['brief', 'implement', 'run'], "brief serves autoflow steps; this run's mode is auto (resume it with /pipeline, which uses next)"],
    'finish' => [['finish', '{"action":"done"}'], "finish records autoflow runs; this run's mode is auto (resume it with /pipeline, which uses next)"],
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

it('writes the mode, light and the decisions verbatim into the first manifest', function () {
    $fixture = kickoff_fixture();

    $ready = kickoff($fixture, ['#69', '--light', '--mode', 'auto', '--decision', 'Fold in #53: add pipeline_ledger()', '--decision', 'Keep the guard'])['json'];

    expect(manifest_read($ready['manifest']))->toMatchArray([
        'mode' => 'auto',
        'light' => true,
        'decisions' => ['Fold in #53: add pipeline_ledger()', 'Keep the guard'],
    ]);
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

it('refuses a kickoff it cannot parse', function (array $arguments) {
    $fixture = kickoff_fixture();

    expect(kickoff($fixture, $arguments)['code'])->toBe(1);
    expect(dispatch_cli(['kickoff'])['code'])->toBe(1);
})->with([
    'no item' => [[]],
    'interactive' => [['69', '--mode', 'interactive']],
    'an unknown flag' => [['69', '--sideways']],
    'a flag without its value' => [['69', '--decision']],
    'two items' => [['69', '70']],
]);
