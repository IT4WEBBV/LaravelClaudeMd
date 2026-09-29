# A live status line of the `autoflow` runs Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task by task. Steps use checkbox (`- [ ]`) syntax for tracking. In an `autoflow` implement step, execute inline, task by task, with no subagents.

**Goal:** Below the existing status line, one row per unfinished `autoflow` run of the session's repo (issue, leg, status, age, PR), read from the pipeline manifests, with the status line script moved into this repo so both machines get it.

**Architecture:**
- `skills/pipeline/checks/statusline.php`: discovery (`git worktree list --porcelain` → each branch's manifest by kickoff's path rule) and pure rendering; `statusline_cli.php <cwd>` prints the rows.
- `manifest.php` gains `manifest_path()` (kickoff's path rule, now shared) and `manifest_finished()` (moved from `dispatch_cli_finished()`).
- `statusline/statusline-command.sh`: the machine-local `~/.claude/statusline-command.sh` moved in verbatim, plus a call to the CLI whose output it appends.
- `hooks/git-freshness.sh` links `~/.claude/statusline-command.sh` to it when nothing is there; README documents the setup and `refreshInterval: 5`.

**Tech Stack:** PHP 8.4 on the host, Pest 4, Python 3 (the status line script), bash tests.

**Spec:** `docs/superpowers/specs/2026-09-29-orchestrate-run-status-line-design.md`

## Global Constraints

- Pipeline suite, from the worktree root on the host: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`. `vendor/` is missing in a fresh worktree: run `composer install --no-interaction --quiet` first.
- Hook tests: `bash hooks/tests/git-freshness-sync.test.sh`. Status line tests: `bash statusline/tests/statusline.test.sh`.
- Row format, fields joined by two spaces, no padding: `<issue>  <leg>  <status>  <age>[  <PR>][  <reason>]`.
- Colors: yellow `\033[33m`, red `\033[31m`, reset `\033[0m`. Links: `\033]8;;<url>\a<text>\033]8;;\a`; URLs `https://github.com/<repo>/issues/<n>` and `https://github.com/<repo>/pull/<n>`.
- Stale is `age > 5400` seconds. At most 4 rows; more runs → the fourth ends in `  +<n> more`. Reason cut with `mb_strimwidth(…, 0, 60, '…')`.
- The run reads `~/.claude/statusline-command.sh` (to copy it) and never replaces it, and never edits `~/.claude/settings.json`. The PR body says: after merge, on each machine, the owner runs README §Status line (`diff`, then `ln -sfn`) and adds `"refreshInterval": 5` to `statusLine`, and confirms the rows refresh while the session is idle.
- This repo has no `.changelog/` and no `CHANGELOG.md`: no changelog entry.

## Review Focus

1. **The `.before.json` snapshot beside each manifest.** A glob would show every run twice; discovery reads only `manifest_path(<worktree>, <branch>)`. Pinned in Task 3's discovery test.
2. **An empty or vanished `cwd`.** `git -C ''` runs in the current directory, which would scan whatever repo PHP was started in. `pipeline_status_scan()` returns nothing unless `is_dir($cwd)`. Pinned in Task 3.
3. **A PHP failure.** CLI `display_errors` writes a fatal to stdout; the Python side uses stdout only on exit 0, so the first row never gains an error text. Pinned in Task 4, case 4. A warning or a deprecation also lands on stdout but exits 0 (a manifest missing `cursor.leg`, or a `cursor.reason` with invalid UTF-8 making `preg_replace` return null for `trim()`), so `statusline_cli.php` sets `display_errors` to `stderr` before the scan. Pinned in Task 3's warning test.
4. **A detached worktree** (no `branch` line in the porcelain) is skipped, not an error. Pinned in Task 3.
5. **A reason carrying escape sequences or newlines** would break the status line's rows; control characters are dropped and whitespace collapsed. Pinned in Task 2.

---

## File Structure

- Modify `skills/pipeline/checks/manifest.php`, `kickoff.php`, `dispatch_cli.php`, `tests/ManifestTest.php`, `tests/Pest.php`.
- Create `skills/pipeline/checks/statusline.php`, `statusline_cli.php`, `tests/StatuslineTest.php`.
- Create `statusline/statusline-command.sh` (moved in), `statusline/tests/statusline.test.sh`.
- Modify `hooks/git-freshness.sh`, `hooks/tests/git-freshness-sync.test.sh`.
- Modify `README.md`, `skills/pipeline/SKILL.md`, `skills/pipeline/references/manifest.md`, `skills/orchestrate/SKILL.md`.

---

### Task 1: `manifest_path()` and `manifest_finished()`

**Files:**
- Modify: `skills/pipeline/checks/manifest.php`, `skills/pipeline/checks/kickoff.php:302`, `skills/pipeline/checks/dispatch_cli.php:78,107-111,184`
- Test: `skills/pipeline/checks/tests/ManifestTest.php`

**Interfaces:**
- Produces: `manifest_path(string $worktree, string $branch): string`, `manifest_finished(array $manifest): bool` (Task 2 and 3 use both).

- [ ] **Step 1: Write the failing tests.** Append to `ManifestTest.php`:

```php
it('puts a branch\'s manifest where kickoff writes it', function () {
    expect(manifest_path('/w/', 'feature/issue-7-x'))->toBe('/w/.claude/pipeline/feature-issue-7-x.json');
    expect(manifest_path('/w', 'main'))->toBe('/w/.claude/pipeline/main.json');
});

it('calls a run finished on status done, or on the old engine\'s leg done', function () {
    expect(manifest_finished(['cursor' => ['leg' => 'review-pr', 'status' => 'done']]))->toBeTrue();
    expect(manifest_finished(['cursor' => ['leg' => 'done', 'status' => 'continued']]))->toBeTrue();
    expect(manifest_finished(['cursor' => ['leg' => 'implement', 'status' => 'pending']]))->toBeFalse();
});
```

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='where kickoff writes it|calls a run finished'`
Expected: FAIL, `Call to undefined function manifest_path()` and `manifest_finished()`.

- [ ] **Step 3: Add the two functions** to `manifest.php`, after `manifest_write()`:

```php
/** Where a branch's manifest lives in its worktree: kickoff writes it there, the status line reads it there. */
function manifest_path(string $worktree, string $branch): string
{
    return rtrim($worktree, '/') . '/.claude/pipeline/' . str_replace('/', '-', $branch) . '.json';
}

/** Finished: `status: done` as the dispatcher writes it, or the old engine's `leg: done`. */
function manifest_finished(array $manifest): bool
{
    return ($manifest['cursor']['status'] ?? null) === 'done' || ($manifest['cursor']['leg'] ?? null) === 'done';
}
```

- [ ] **Step 4: Use them.** In `kickoff.php`, `pipeline_kickoff_prepare()`, replace

```php
    $path = rtrim($worktree, '/') . '/.claude/pipeline/' . str_replace('/', '-', $branch) . '.json';
```

with

```php
    $path = manifest_path($worktree, $branch);
```

In `dispatch_cli.php`, delete `dispatch_cli_finished()` with its docblock (lines 107–111), and replace both `dispatch_cli_finished($manifest)` calls (lines 78 and 184) with `manifest_finished($manifest)`. Then `grep -rn dispatch_cli_finished skills/` prints nothing.

- [ ] **Step 5: Run the tests and the suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='where kickoff writes it|calls a run finished'`
Expected: PASS.

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, 0 failed (the kickoff and dispatch tests cover the two call sites).

- [ ] **Step 6: Commit**

```bash
git add skills/pipeline/checks/manifest.php skills/pipeline/checks/kickoff.php skills/pipeline/checks/dispatch_cli.php skills/pipeline/checks/tests/ManifestTest.php
git commit -m "refactor(pipeline): manifest_path and manifest_finished in manifest.php, for kickoff, dispatch and the status line (#91)"
```

---

### Task 2: rendering the rows

**Files:**
- Create: `skills/pipeline/checks/statusline.php`
- Modify: `skills/pipeline/checks/tests/Pest.php` (load `statusline.php`)
- Test: `skills/pipeline/checks/tests/StatuslineTest.php`

**Interfaces:**
- Consumes: `manifest_finished(array $manifest): bool` (Task 1).
- Produces: `pipeline_status_lines(array $runs, int $now, ?string $repo): array` (list of rows; `$runs` is a list of `['manifest' => array, 'mtime' => int]`), `pipeline_status_line(array $manifest, int $age, ?string $repo): string`, `pipeline_status_age(int $seconds): string`. Task 3 adds discovery to the same file.

- [ ] **Step 1: Load the file in the suite.** In `tests/Pest.php`, append `'statusline.php'` to the list after `'ci.php'`.

- [ ] **Step 2: Write the failing tests.** Create `tests/StatuslineTest.php`:

```php
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
        "\033]8;;https://github.com/acme/app/issues/415\a#415\033]8;;\a  implement  pending  47m  \033]8;;https://github.com/acme/app/pull/419\aPR#419\033]8;;\a",
    ]);
});

it('leaves the PR out before the run has one, and names a run without an issue by its branch', function () {
    expect(pipeline_status_lines([status_run(['artifacts' => ['issue' => 415]])], 1_000_000, 'acme/app'))
        ->toBe(["\033]8;;https://github.com/acme/app/issues/415\a#415\033]8;;\a  implement  pending  47m"]);
    expect(pipeline_status_lines([status_run(['artifacts' => ['pr' => null]])], 1_000_000, 'acme/app'))
        ->toBe(['feature/issue-415-x  implement  pending  47m']);
});

it('shows a halted run in red with its reason on one line, cut at 60 columns', function () {
    $halted = fn (?string $reason) => status_run(['cursor' => array_filter(['leg' => 'design', 'status' => 'halted', 'reason' => $reason])], 12);

    expect(pipeline_status_lines([$halted("the diff file\n  is missing: " . str_repeat('x', 100))], 1_000_000, null))
        ->toBe(["#415  design  \033[31mhalted\033[0m  12m  PR#419  \033[31mthe diff file is missing: " . str_repeat('x', 33) . "…\033[0m"]);
    expect(pipeline_status_lines([$halted("boom\033]8;;x\a\t now")], 1_000_000, null))
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
```

- [ ] **Step 3: Run them to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter=Statusline`
Expected: FAIL, `Call to undefined function pipeline_status_lines()` (and `pipeline_status_age()`).

- [ ] **Step 4: Write `statusline.php`**

```php
<?php

/**
 * The status line's section of `autoflow` runs (README §Status line): one row per unfinished
 * `autoflow` run of the repo, read from the manifests the pipeline already writes. It reads, never
 * writes, and calls no `gh`. `statusline_cli.php` prints it.
 */

require_once __DIR__ . '/manifest.php';

const PIPELINE_STATUS_MAX_LINES = 4;
const PIPELINE_STATUS_STALE_SECONDS = 90 * 60;
const PIPELINE_STATUS_RED = "\033[31m";
const PIPELINE_STATUS_YELLOW = "\033[33m";

/** @param list<array{manifest: array, mtime: int}> $runs @return list<string> */
function pipeline_status_lines(array $runs, int $now, ?string $repo): array
{
    $open = array_values(array_filter(
        $runs,
        fn (array $run) => ($run['manifest']['mode'] ?? null) === 'autoflow' && ! manifest_finished($run['manifest']),
    ));
    usort($open, fn (array $a, array $b) => pipeline_status_order($a['manifest']) <=> pipeline_status_order($b['manifest']));

    $lines = array_map(
        fn (array $run) => pipeline_status_line($run['manifest'], $now - $run['mtime'], $repo),
        array_slice($open, 0, PIPELINE_STATUS_MAX_LINES),
    );
    $hidden = count($open) - count($lines);
    if ($hidden > 0) {
        $lines[count($lines) - 1] .= "  +{$hidden} more";
    }

    return $lines;
}

/** Halted first, the runs the owner has to act on; then by issue, runs without one last. */
function pipeline_status_order(array $manifest): array
{
    $issue = $manifest['artifacts']['issue'] ?? null;

    return [
        ($manifest['cursor']['status'] ?? null) === 'halted' ? 0 : 1,
        $issue === null ? PHP_INT_MAX : (int) $issue,
        (string) ($manifest['branch'] ?? ''),
    ];
}

function pipeline_status_line(array $manifest, int $age, ?string $repo): string
{
    $cursor = $manifest['cursor'];
    $issue = isset($manifest['artifacts']['issue']) ? (int) $manifest['artifacts']['issue'] : null;
    $pr = isset($manifest['artifacts']['pr']) ? (int) $manifest['artifacts']['pr'] : null;
    $halted = ($cursor['status'] ?? null) === 'halted';
    $reason = $halted ? pipeline_status_reason((string) ($cursor['reason'] ?? '')) : '';

    return implode('  ', array_filter([
        $issue === null ? (string) $manifest['branch'] : pipeline_status_link("#{$issue}", pipeline_status_url($repo, "issues/{$issue}")),
        (string) $cursor['leg'],
        $halted ? pipeline_status_color('halted', PIPELINE_STATUS_RED) : (string) $cursor['status'],
        $age > PIPELINE_STATUS_STALE_SECONDS ? pipeline_status_color(pipeline_status_age($age), PIPELINE_STATUS_YELLOW) : pipeline_status_age($age),
        $pr === null ? '' : pipeline_status_link("PR#{$pr}", pipeline_status_url($repo, "pull/{$pr}")),
        $reason === '' ? '' : pipeline_status_color($reason, PIPELINE_STATUS_RED),
    ], fn (string $part) => $part !== ''));
}

function pipeline_status_age(int $seconds): string
{
    $minutes = intdiv(max(0, $seconds), 60);

    return match (true) {
        $minutes < 60 => "{$minutes}m",
        $minutes < 24 * 60 => sprintf('%dh%02dm', intdiv($minutes, 60), $minutes % 60),
        default => intdiv($minutes, 24 * 60) . 'd',
    };
}

/** One row, whatever the halt wrote: control characters (escape sequences included) out, whitespace collapsed, 60 columns. */
function pipeline_status_reason(string $reason): string
{
    $printable = preg_replace('/[\x00-\x08\x0E-\x1F\x7F]/', '', $reason);

    return mb_strimwidth(trim(preg_replace('/\s+/u', ' ', $printable)), 0, 60, '…');
}

function pipeline_status_url(?string $repo, string $path): ?string
{
    return $repo === null ? null : "https://github.com/{$repo}/{$path}";
}

function pipeline_status_link(string $text, ?string $url): string
{
    return $url === null ? $text : "\033]8;;{$url}\a{$text}\033]8;;\a";
}

function pipeline_status_color(string $text, string $color): string
{
    return "{$color}{$text}\033[0m";
}
```

- [ ] **Step 5: Run the tests and the suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter=Statusline`
Expected: PASS.

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, 0 failed.

- [ ] **Step 6: Commit**

```bash
git add skills/pipeline/checks/statusline.php skills/pipeline/checks/tests/StatuslineTest.php skills/pipeline/checks/tests/Pest.php
git commit -m "feat(pipeline): render one status row per unfinished autoflow run from its manifest (#91)"
```

---

### Task 3: discovery and the CLI

**Files:**
- Modify: `skills/pipeline/checks/statusline.php`
- Create: `skills/pipeline/checks/statusline_cli.php`
- Test: `skills/pipeline/checks/tests/StatuslineTest.php`

**Interfaces:**
- Consumes: `manifest_path()`, `manifest_read()` (`manifest.php`), `pipeline_git_run(string $worktree, array $args, array $env = []): array{0: int, 1: string, 2: string}` (`suite.php`, stdout trimmed), `pipeline_repo_config_value(string $configMarkdown, string $section, string $key): ?string` (`kickoff.php`); test helpers `suite_repo(): string` (`SuiteTest.php`) and `checks_cli(string $script, array $arguments): array{code: int, stdout: string}` (`RunCostTest.php`, stdout trimmed).
- Produces: `pipeline_status_scan(string $cwd): array{repo: ?string, runs: list<array{manifest: array, mtime: int}>}`; `php statusline_cli.php <cwd>` prints the rows joined by `\n`, nothing when there are none, exit 0 (Task 4 calls it).

- [ ] **Step 1: Write the failing tests.** Append to `StatuslineTest.php`:

```php
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
        'stdout' => "\033]8;;https://github.com/acme/app/issues/7\a#7\033]8;;\a  implement  pending  0m  \033]8;;https://github.com/acme/app/pull/9\aPR#9\033]8;;\a",
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
```

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='finds each worktree|prints nothing outside|keeps a PHP warning'`
Expected: FAIL, `Call to undefined function pipeline_status_scan()`, and the CLI fails to open `statusline_cli.php` (non-zero code).

- [ ] **Step 3: Add discovery to `statusline.php`.** Below the existing `require_once`, add:

```php
require_once __DIR__ . '/suite.php';
require_once __DIR__ . '/kickoff.php';
```

and after the constants:

```php
/**
 * Every worktree's manifest, by the branch git lists for it (never a glob: `<stem>.before.json`
 * sits beside each manifest), and the repo named in the primary checkout's work-on config.
 *
 * @return array{repo: ?string, runs: list<array{manifest: array, mtime: int}>}
 */
function pipeline_status_scan(string $cwd): array
{
    // `git -C ''` would run wherever PHP was started
    if (! is_dir($cwd)) {
        return ['repo' => null, 'runs' => []];
    }
    [$code, $porcelain] = pipeline_git_run($cwd, ['worktree', 'list', '--porcelain']);
    if ($code !== 0) {
        return ['repo' => null, 'runs' => []];
    }
    $worktrees = pipeline_status_worktrees($porcelain);

    $runs = [];
    foreach ($worktrees as $worktree) {
        if ($worktree['branch'] === null) {
            continue;
        }
        $path = manifest_path($worktree['path'], $worktree['branch']);
        $manifest = manifest_read($path);
        if ($manifest !== null) {
            $runs[] = ['manifest' => $manifest, 'mtime' => (int) filemtime($path)];
        }
    }

    return ['repo' => pipeline_status_repo($worktrees[0]['path']), 'runs' => $runs];
}

/** @return list<array{path: string, branch: ?string}> the primary checkout first, as git lists it */
function pipeline_status_worktrees(string $porcelain): array
{
    return array_map(fn (string $entry) => [
        'path' => preg_match('/^worktree (.+)$/m', $entry, $path) ? $path[1] : '',
        'branch' => preg_match('#^branch refs/heads/(.+)$#m', $entry, $branch) ? $branch[1] : null,
    ], preg_split('/\n\n+/', $porcelain));
}

function pipeline_status_repo(string $primary): ?string
{
    $config = $primary . '/.claude/work-on.config.md';

    return is_file($config) ? pipeline_repo_config_value((string) file_get_contents($config), 'Repo', 'repo') : null;
}
```

- [ ] **Step 4: Create `statusline_cli.php`**

```php
<?php

/**
 * `php statusline_cli.php <cwd>`: one row per unfinished `autoflow` run of the repo holding <cwd>
 * (`statusline.php`), joined by newlines, without a trailing one; nothing outside a repo or without
 * runs. `statusline/statusline-command.sh` appends it below the status line and drops it on any exit
 * but 0.
 */

// a warning exits 0 on stdout, which the status line would print as a row; a fatal still exits 255
ini_set('display_errors', 'stderr');

require_once __DIR__ . '/statusline.php';

$scan = pipeline_status_scan((string) ($argv[1] ?? ''));

echo implode("\n", pipeline_status_lines($scan['runs'], time(), $scan['repo']));
```

- [ ] **Step 5: Run the tests and the suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='finds each worktree|prints nothing outside|keeps a PHP warning'`
Expected: PASS. Without the `ini_set` line the warning test fails on `Warning: Undefined array key "leg"` in stdout.

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, 0 failed.

Run, from the worktree root: `php skills/pipeline/checks/statusline_cli.php "$PWD"; echo " [exit $?]"`
Expected: this repo's live `autoflow` runs, one row each (this run's own row among them, `#91  implement  pending …`), then ` [exit 0]`.

- [ ] **Step 6: Commit**

```bash
git add skills/pipeline/checks/statusline.php skills/pipeline/checks/statusline_cli.php skills/pipeline/checks/tests/StatuslineTest.php
git commit -m "feat(pipeline): statusline_cli.php finds each worktree's manifest by its branch and prints the run rows (#91)"
```

---

### Task 4: the status line script moves into the repo

**Files:**
- Create: `statusline/statusline-command.sh` (copied from `~/.claude/statusline-command.sh`, then edited)
- Test: `statusline/tests/statusline.test.sh`

**Interfaces:**
- Consumes: `php skills/pipeline/checks/statusline_cli.php <cwd>` (Task 3).
- Produces: the script README §Status line and the hook (Task 5) link to.

- [ ] **Step 1: Copy the script in verbatim, as its own commit**

```bash
mkdir -p statusline
cp -p ~/.claude/statusline-command.sh statusline/statusline-command.sh
shasum -a 256 statusline/statusline-command.sh
git add statusline/statusline-command.sh
git ls-files -s statusline/statusline-command.sh
```
Expected: sha256 `d99c4916b544907a8a5c9cb04d38e67cca252b16bde1f836ec7c60cc0979f30e` and mode `100755`. A different sha means the local file changed after the design: keep the copy as it is and put the new sha in the PR body. Mode not `100755`: `git update-index --chmod=+x statusline/statusline-command.sh`.

```bash
git commit -m "chore(statusline): move the machine-local status line script into the repo, unchanged (#91)"
```

- [ ] **Step 2: Write the failing test.** Create `statusline/tests/statusline.test.sh`:

```bash
#!/usr/bin/env bash
#
# Tests for statusline/statusline-command.sh: the autoflow run rows below the status line, and a
# status line exactly as before without them.
#
#   bash statusline/tests/statusline.test.sh
#
# Every case builds a throwaway repo under $TMPDIR; nothing in ~/GitProjects is touched.

set -uo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
script="$HERE/../statusline-command.sh"
root="$(cd "$(mktemp -d)" && pwd -P)"
trap 'rm -rf "$root"' EXIT
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1

passed=0
failed=0
ok()   { passed=$((passed + 1)); printf '  ok    %s\n' "$1"; }
fail() { failed=$((failed + 1)); printf '  FAIL  %s\n' "$1"; [ $# -gt 1 ] && printf '        %s\n' "$2"; return 0; }
is()       { if [ "$1" = "$2" ]; then ok "$3"; else fail "$3" "expected [$2], got [$1]"; fi; }
contains() { case "$1" in *"$2"*) ok "$3" ;; *) fail "$3" "[$2] not in [$1]" ;; esac; }

# run <script> <cwd>: the status line for a session in <cwd>
run()   { printf '{"workspace":{"current_dir":"%s"}}' "$2" | "$1"; }
# grep -c '' counts a last line without a newline, which the status line never prints
lines() { printf '%s' "$1" | grep -c ''; }
row()   { printf '%s' "$1" | sed -n "${2}p"; }

repo="$root/Shop"
git init -q "$repo"
git -C "$repo" -c user.name=t -c user.email=t@t commit -q --allow-empty -m init
git -C "$repo" worktree add -q "$root/Shop-7" -b feature/issue-7-x
mkdir -p "$root/Shop-7/.claude/pipeline" "$root/plain"
printf '{"branch":"feature/issue-7-x","worktree":"%s","mode":"autoflow","cursor":{"leg":"implement","status":"pending"},"artifacts":{"issue":7}}\n' \
    "$root/Shop-7" > "$root/Shop-7/.claude/pipeline/feature-issue-7-x.json"

echo "case 1: outside a repo, the status line alone"
out=$(run "$script" "$root/plain")
is "$(lines "$out")" "1" "one row"
echo

echo "case 2: a repo with an autoflow run gets its row below the status line"
out=$(run "$script" "$repo")
is "$(lines "$out")" "2" "two rows"
contains "$(row "$out" 2)" "#7  implement  pending" "the run's row"
echo

echo "case 3: run through a symlink, as ~/.claude/statusline-command.sh"
ln -s "$script" "$root/statusline-command.sh"
out=$(run "$root/statusline-command.sh" "$repo")
contains "$(row "$out" 2)" "#7  implement  pending" "the CLI is found through the link"
echo

echo "case 4: a php that fails adds nothing, not even its error text"
mkdir -p "$root/bin"
printf '#!/bin/sh\necho "PHP Fatal error: boom"\nexit 255\n' > "$root/bin/php"
chmod +x "$root/bin/php"
out=$(PATH="$root/bin:$PATH" run "$script" "$repo")
is "$(lines "$out")" "1" "one row"
case "$out" in *"Fatal"*) fail "no error text" "$out" ;; *) ok "no error text" ;; esac
echo

echo "----------------------------------------"
printf '%d passed, %d failed\n' "$passed" "$failed"
[ "$failed" -eq 0 ]
```

- [ ] **Step 3: Run it to see it fail**

Run: `bash statusline/tests/statusline.test.sh`
Expected: case 1 and case 4 ok; case 2 FAIL `expected [2], got [1]` and the row checks fail; exit 1.

- [ ] **Step 4: Add the runs section.** In `statusline/statusline-command.sh`, replace the last block

```python
# Get current time
time_str = datetime.now().strftime('%H:%M:%S')

# Output the status line
print(f"{writable}{short_dir}{git_info}{model_seg} | {ctx_seg}{limit_seg} | {time_str}", end='')
```

with

```python
# Get current time
time_str = datetime.now().strftime('%H:%M:%S')

# One row per unfinished autoflow run of this repo (README §Status line). realpath follows the
# ~/.claude/statusline-command.sh symlink into the repo; any failure leaves the first row alone.
RUNS_CLI = os.path.join(os.path.dirname(os.path.realpath(__file__)), '..', 'skills', 'pipeline', 'checks', 'statusline_cli.php')


def autoflow_runs(cwd):
    if not cwd or not os.path.isfile(RUNS_CLI):
        return ''
    try:
        result = subprocess.run(['php', RUNS_CLI, cwd], capture_output=True, text=True, timeout=2)
    except (OSError, subprocess.TimeoutExpired):
        return ''
    return result.stdout.rstrip('\n') if result.returncode == 0 else ''


runs = autoflow_runs(cwd)
runs_seg = f"\n{runs}" if runs else ""

# Output the status line
print(f"{writable}{short_dir}{git_info}{model_seg} | {ctx_seg}{limit_seg} | {time_str}{runs_seg}", end='')
```

- [ ] **Step 5: Run the test**

Run: `bash statusline/tests/statusline.test.sh`
Expected: `6 passed, 0 failed`, exit 0.

Run: `git diff -- statusline/statusline-command.sh`
Expected: only the block above changed against the verbatim copy of Step 1.

- [ ] **Step 6: Commit**

```bash
git add statusline/statusline-command.sh statusline/tests/statusline.test.sh
git commit -m "feat(statusline): the repo's unfinished autoflow runs, one row each, below the status line (#91)"
```

---

### Task 5: the hook links the status line when there is none

**Files:**
- Modify: `hooks/git-freshness.sh` (header comment at 45–48, the path variables at 66–68, a new `link_statusline` after `link_new_workflows`, its call in `sync_config_repos` at 494–495)
- Test: `hooks/tests/git-freshness-sync.test.sh`

**Interfaces:**
- Consumes: `statusline/statusline-command.sh` in a config repo (Task 4).
- Produces: env `GIT_FRESHNESS_STATUSLINE` (default `$HOME/.claude/statusline-command.sh`), the tag `linked the status line`.

- [ ] **Step 1: Isolate the tests from the real path.** In `git-freshness-sync.test.sh`, after `export GIT_FRESHNESS_WORKFLOWS_DIR="$root/no-workflows-dir"`, add:

```bash
export GIT_FRESHNESS_STATUSLINE="$root/no-statusline/statusline-command.sh"
```

- [ ] **Step 2: Write the failing test.** Before the final `echo "----------------------------------------"`, add:

```bash
echo "case 18: session start links the status line when there is none, and leaves a local one alone"
cfg=$(fixture config6 1 statusline/statusline-command.sh)
mkdir -p "$root/config6/fresh" "$root/config6/local"
fresh="$root/config6/fresh/statusline-command.sh"
out=$(printf '%s' "{\"session_id\":\"test-config6a\",\"cwd\":\"$root/config6\"}" \
    | GIT_FRESHNESS_CONFIG_REPOS="$cfg" GIT_FRESHNESS_SKILLS_DIR="$root/config6/none" GIT_FRESHNESS_STATUSLINE="$fresh" bash "$hook" session 2>/dev/null)
is "$(readlink "$fresh")" "$cfg/statusline/statusline-command.sh" "the status line is linked"
contains "$out" "linked the status line" "the new link is reported"
mine="$root/config6/local/statusline-command.sh"
printf 'mine\n' > "$mine"
out=$(printf '%s' "{\"session_id\":\"test-config6b\",\"cwd\":\"$root/config6\"}" \
    | GIT_FRESHNESS_CONFIG_REPOS="$cfg" GIT_FRESHNESS_SKILLS_DIR="$root/config6/none" GIT_FRESHNESS_STATUSLINE="$mine" bash "$hook" session 2>/dev/null)
if [ -L "$mine" ]; then fail "a machine-local file is not replaced"; else ok "a machine-local file is not replaced"; fi
is "$(cat "$mine")" "mine" "its content is untouched"
lacks "$out" "linked the status line" "nothing is reported"
echo
```

- [ ] **Step 3: Run it to see it fail**

Run: `bash hooks/tests/git-freshness-sync.test.sh`
Expected: case 18 FAIL on *the status line is linked* and *the new link is reported*; every earlier case ok.

- [ ] **Step 4: Implement.** In `git-freshness.sh`:

After `workflows_dir="${GIT_FRESHNESS_WORKFLOWS_DIR-$HOME/.claude/workflows}"` add:

```bash
statusline="${GIT_FRESHNESS_STATUSLINE-$HOME/.claude/statusline-command.sh}"
```

After `link_new_workflows()` add:

```bash
# Link the status line script a config repo ships (statusline/statusline-command.sh) when the
# machine has nothing at the status line path yet. An existing file or link is never replaced:
# swapping a machine-local copy for the link is the README's one-time setup step.
link_statusline() {
    local script="$1/statusline/statusline-command.sh"

    [ -f "$script" ] || return 0
    { [ -e "$statusline" ] || [ -L "$statusline" ]; } && return 0
    ln -s "$script" "$statusline" 2>/dev/null \
        && config_tags="${config_tags}${config_tags:+, }linked the status line"
}
```

In `sync_config_repos`, after `link_new_workflows "$repo"` add `link_statusline "$repo"`.

In the header comment, replace

```bash
# The config repos get one more: a skill that has no symlink in ~/.claude/skills
# yet is linked, and so is a skill's workflow script (skills/<skill>/workflow/*.js)
# that has none in ~/.claude/workflows, so both reach every machine with its next
# session instead of waiting for a manual relink. An existing entry is never replaced.
```

with

```bash
# The config repos get one more: a skill that has no symlink in ~/.claude/skills
# yet is linked, and so is a skill's workflow script (skills/<skill>/workflow/*.js)
# that has none in ~/.claude/workflows, and the status line script when
# ~/.claude/statusline-command.sh does not exist, so each reaches every machine with
# its next session instead of waiting for a manual relink. An existing entry is never replaced.
```

- [ ] **Step 5: Run the tests**

Run: `bash hooks/tests/git-freshness-sync.test.sh`
Expected: `0 failed`, exit 0.

- [ ] **Step 6: Commit**

```bash
git add hooks/git-freshness.sh hooks/tests/git-freshness-sync.test.sh
git commit -m "feat(hooks): link the repo's status line at session start when the machine has none (#91)"
```

---

### Task 6: docs

**Files:**
- Modify: `README.md`, `skills/pipeline/SKILL.md`, `skills/pipeline/references/manifest.md`, `skills/orchestrate/SKILL.md`

**Interfaces:**
- Consumes: the names from Tasks 1–5: `statusline/statusline-command.sh`, `statusline_cli.php`, `manifest_path`, `manifest_finished`, `refreshInterval: 5`.
- Produces: nothing code reads.

- [ ] **Step 1: README bootstrap.** After step 4 in the bootstrap block (`chmod +x …/hooks/git-freshness.sh`), inside the same code block, add:

```bash

# 5. Link the status line script (settings.json's statusLine runs ~/.claude/statusline-command.sh)
ln -sfn ~/GitProjects/LaravelClaudeMd/LaravelClaudeMd/statusline/statusline-command.sh ~/.claude/statusline-command.sh
```

Replace `Then wire the hooks in \`~/.claude/settings.json\`.` with `Then wire the hooks and the status line in \`~/.claude/settings.json\`.` and, after the hooks JSON block, add:

````markdown
```json
"statusLine": { "type": "command", "command": "$HOME/.claude/statusline-command.sh", "refreshInterval": 5 }
```
````

In the `session` bullet, replace `` `~/.claude/workflows/`), then checks the launch directory. `` with `` `~/.claude/workflows/`, and the status line script when `~/.claude/statusline-command.sh` does not exist), then checks the launch directory. ``

- [ ] **Step 2: README §Status line.** Before `## Hook tests`, add:

````markdown
## Status line

`statusline/statusline-command.sh` is the status line: directory, branch, model, context and rate
limits on the first row, and below it one row per unfinished `autoflow` run of the session's repo,
read from the run manifests by `skills/pipeline/checks/statusline_cli.php`:

```
#415  implement  pending  47m  PR#419
```

The age is the time since the manifest last changed and turns yellow past 90 minutes without a step
boundary (commits do not touch the manifest, so this is not orchestrate's stall rule); a halted run shows its reason in red; the issue and the PR are links. At most four
rows. `refreshInterval: 5` re-runs the script every five seconds, so the rows move while the session
waits on its workflows; it costs no tokens. Without runs, or without `php`, the first row is all there is.

`~/.claude/statusline-command.sh` is a symlink to it (step 5). The `session` hook creates that link when
nothing is there, never over an existing file. A machine that still has its own copy: compare it, then
replace it, and add `"refreshInterval": 5` to `statusLine` in `~/.claude/settings.json`:

```bash
diff ~/.claude/statusline-command.sh ~/GitProjects/LaravelClaudeMd/LaravelClaudeMd/statusline/statusline-command.sh
ln -sfn ~/GitProjects/LaravelClaudeMd/LaravelClaudeMd/statusline/statusline-command.sh ~/.claude/statusline-command.sh
```

Its tests: `bash statusline/tests/statusline.test.sh`; the rows' rendering is in the pipeline suite
(`StatuslineTest.php`).
````

- [ ] **Step 3: Pipeline `SKILL.md`.** After the **Cost per run** bullet, add:

```markdown
- **Run status line** — the status line shows each unfinished `autoflow` run of the session's repo,
  one row each (issue, leg, status, age, PR), read from the manifests by `checks/statusline_cli.php`;
  no writes, no `gh`. Setup: the repo's README §Status line.
```

- [ ] **Step 4: `manifest.md`.** Replace

```markdown
Read/written by the Phase A helpers in `../checks/manifest.php`:
`manifest_read`, `manifest_write`, `manifest_validate`, `manifest_infer_cursor`.
```

with

```markdown
Read/written by the Phase A helpers in `../checks/manifest.php`:
`manifest_read`, `manifest_write`, `manifest_validate`, `manifest_infer_cursor`, and
`manifest_path` (where a branch's manifest lives) and `manifest_finished`. The status line
(`../checks/statusline.php`) reads every worktree's manifest and never writes one.
```

- [ ] **Step 5: Orchestrate `SKILL.md`.** At the end of the §Where it runs paragraph that starts `A \`claude --bg\` session in the primary checkout`, append: ` The owner watches the runs on the status line (pipeline \`SKILL.md\`, *Run status line*); you never read it.`

- [ ] **Step 6: Check and run everything**

Run: `grep -n "statusline" README.md skills/pipeline/SKILL.md skills/pipeline/references/manifest.md skills/orchestrate/SKILL.md`
Expected: the lines added above, each naming `statusline/statusline-command.sh`, `statusline_cli.php` or `statusline.php`.

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, 0 failed.

Run: `bash hooks/tests/git-freshness-sync.test.sh && bash statusline/tests/statusline.test.sh`
Expected: both `0 failed`.

- [ ] **Step 7: Commit**

```bash
git add README.md skills/pipeline/SKILL.md skills/pipeline/references/manifest.md skills/orchestrate/SKILL.md
git commit -m "docs: the run status line, its setup and refreshInterval (#91)"
```
