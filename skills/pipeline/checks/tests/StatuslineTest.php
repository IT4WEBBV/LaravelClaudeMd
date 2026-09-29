<?php

/** A manifest as an `autoflow` run leaves it mid-run; $extra replaces top-level keys, `cursor` and `artifacts` whole. */
function status_manifest(array $extra = []): array
{
    return [
        'branch' => 'feature/issue-415-x', 'worktree' => '/tmp/wt', 'mode' => 'autoflow',
        'cursor' => ['leg' => 'implement', 'status' => 'pending'],
        'artifacts' => ['issue' => 415, 'pr' => 419],
        ...$extra,
    ];
}

/** A run whose manifest was written $minutes before 1_000_000, the `now` every test passes. */
function status_run(array $extra = [], int $minutes = 47): array
{
    return ['manifest' => status_manifest($extra), 'mtime' => 1_000_000 - $minutes * 60];
}

it('shows a running run as issue, leg, status, age and PR', function () {
    expect(pipeline_status_lines([status_run()], 1_000_000, null))->toBe(['#415  implement  pending  47m  PR#419']);
});

it('links the issue and the PR when the repo is known', function () {
    expect(pipeline_status_lines([status_run()], 1_000_000, 'acme/app'))->toBe([
        "\033]8;;https://github.com/acme/app/issues/415\007#415\033]8;;\007  implement  pending  47m  \033]8;;https://github.com/acme/app/pull/419\007PR#419\033]8;;\007",
    ]);
});

it('leaves the PR out before the run has one, and names a run without an issue by its branch', function () {
    expect(pipeline_status_lines([status_run(['artifacts' => ['issue' => 415]])], 1_000_000, 'acme/app'))
        ->toBe(["\033]8;;https://github.com/acme/app/issues/415\007#415\033]8;;\007  implement  pending  47m"]);
    expect(pipeline_status_lines([status_run(['artifacts' => ['pr' => null]])], 1_000_000, 'acme/app'))
        ->toBe(['feature/issue-415-x  implement  pending  47m']);
});

it('reads a PR recorded as its URL by its number', function () {
    expect(pipeline_status_lines([status_run(['artifacts' => ['issue' => 415, 'pr' => 'https://github.com/acme/app/pull/419']])], 1_000_000, null))
        ->toBe(['#415  implement  pending  47m  PR#419']);
});

it('shows a halted run in red with its reason on one line, cut at 60 columns', function () {
    $halted = fn (?string $reason) => status_run(['cursor' => array_filter(['leg' => 'design', 'status' => 'halted', 'reason' => $reason])], 12);

    expect(pipeline_status_lines([$halted("the diff file\n  is missing: " . str_repeat('x', 100))], 1_000_000, null))
        ->toBe(["#415  design  \033[31mhalted\033[0m  12m  PR#419  \033[31mthe diff file is missing: " . str_repeat('x', 33) . "…\033[0m"]);
    expect(pipeline_status_lines([$halted("boom\033]8;;x\007\t now")], 1_000_000, null))
        ->toBe(["#415  design  \033[31mhalted\033[0m  12m  PR#419  \033[31mboom]8;;x now\033[0m"]);
    expect(pipeline_status_lines([$halted(null)], 1_000_000, null))
        ->toBe(["#415  design  \033[31mhalted\033[0m  12m  PR#419"]);
});

it('colors the age yellow past 90 minutes, not at 90', function () {
    expect(pipeline_status_lines([status_run([], 91)], 1_000_000, null))->toBe(["#415  implement  pending  \033[33m1h31m\033[0m  PR#419"]);
    expect(pipeline_status_lines([status_run([], 90)], 1_000_000, null))->toBe(['#415  implement  pending  1h30m  PR#419']);
});

it('shows nothing for a finished run or a run in another mode', function (array $extra) {
    expect(pipeline_status_lines([status_run($extra)], 1_000_000, null))->toBe([]);
})->with([
    'status done' => [['cursor' => ['leg' => 'review-pr', 'status' => 'done']]],
    'leg done' => [['cursor' => ['leg' => 'done', 'status' => 'continued']]],
    'auto' => [['mode' => 'auto']],
    'interactive' => [['mode' => 'interactive']],
]);

it('shows nothing for a manifest without a mode', function () {
    $run = status_run();
    unset($run['manifest']['mode']);

    expect(pipeline_status_lines([$run], 1_000_000, null))->toBe([]);
});

it('writes the age in minutes, hours and minutes, or days', function (int $seconds, string $age) {
    expect(pipeline_status_age($seconds))->toBe($age);
})->with([[0, '0m'], [59, '0m'], [59 * 60, '59m'], [65 * 60, '1h05m'], [49 * 3600, '2d']]);

it('lists halted runs first, then by issue, at most four with a count of the rest', function () {
    $runs = array_map(fn (int $issue) => status_run(['artifacts' => ['issue' => $issue]]), [9, 3, 7, 5]);
    $runs[] = status_run(['artifacts' => ['issue' => 8], 'cursor' => ['leg' => 'design', 'status' => 'halted']]);
    $runs[] = status_run(['artifacts' => [], 'branch' => 'feature/idea']);

    expect(pipeline_status_lines($runs, 1_000_000, null))->toBe([
        "#8  design  \033[31mhalted\033[0m  47m",
        '#3  implement  pending  47m',
        '#5  implement  pending  47m',
        '#7  implement  pending  47m  +2 more',
    ]);
});

it('finds each worktree\'s manifest by its branch, never the snapshot beside it, and links from the primary\'s config', function () {
    $primary = suite_repo();
    mkdir($primary . '/.claude');
    file_put_contents($primary . '/.claude/work-on.config.md', "## Repo\n- repo: acme/app   # gh --repo\n");
    [$run, $other] = [$primary . '-run', $primary . '-other'];
    pipeline_git($primary, ['worktree', 'add', '-q', $run, '-b', 'feature/issue-7-x']);
    pipeline_git($primary, ['worktree', 'add', '-q', $other, '-b', 'feature/issue-8-y']);
    pipeline_git($primary, ['worktree', 'add', '-q', '--detach', $primary . '-detached']);
    $manifest = [
        'branch' => 'feature/issue-7-x', 'worktree' => $run, 'mode' => 'autoflow',
        'cursor' => ['leg' => 'implement', 'status' => 'pending'], 'artifacts' => ['issue' => 7, 'pr' => 9],
    ];
    manifest_write(manifest_path($run, 'feature/issue-7-x'), $manifest);
    manifest_write($run . '/.claude/pipeline/feature-issue-7-x.before.json', $manifest);
    manifest_write(manifest_path($other, 'feature/issue-8-y'), [...$manifest, 'branch' => 'feature/issue-8-y', 'mode' => 'interactive', 'artifacts' => ['issue' => 8]]);
    mkdir($run . '/sub');

    $scan = pipeline_status_scan($run . '/sub');
    expect($scan['repo'])->toBe('acme/app')
        ->and($scan['runs'])->toHaveCount(2);

    expect(checks_cli('statusline_cli.php', [$run . '/sub']))->toBe([
        'code' => 0,
        'stdout' => "\033]8;;https://github.com/acme/app/issues/7\007#7\033]8;;\007  implement  pending  0m  \033]8;;https://github.com/acme/app/pull/9\007PR#9\033]8;;\007",
    ]);
});

it('prints nothing outside a git repo, for a missing directory, or without an argument', function () {
    $dir = sys_get_temp_dir() . '/pipeline-status-' . uniqid();
    mkdir($dir);

    expect(checks_cli('statusline_cli.php', [$dir]))->toBe(['code' => 0, 'stdout' => '']);
    expect(checks_cli('statusline_cli.php', [$dir . '/gone']))->toBe(['code' => 0, 'stdout' => '']);
    expect(checks_cli('statusline_cli.php', []))->toBe(['code' => 0, 'stdout' => '']);
    expect(pipeline_status_scan(''))->toBe(['repo' => null, 'runs' => []]);
});

it('keeps a PHP warning off stdout, where the status line would print it as a row', function () {
    $repo = suite_repo();
    $branch = pipeline_git($repo, ['branch', '--show-current']);
    manifest_write(manifest_path($repo, $branch), ['mode' => 'autoflow', 'branch' => 'x', 'cursor' => [], 'artifacts' => []]);

    expect(checks_cli('statusline_cli.php', [$repo]))->toBe(['code' => 0, 'stdout' => 'x  0m']);
});
