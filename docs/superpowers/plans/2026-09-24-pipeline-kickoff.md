# `dispatch_cli.php kickoff` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. In an `autoflow` implement step: execute inline, task by task, no subagents.

**Goal:** One tested command, `dispatch_cli.php kickoff`, does engine.md §The work item and §Kickoff for the unattended modes and prints `ready` (the manifest `launch` takes) or a halt, so no session judgement precedes `launch`.

**Architecture:** A new `skills/pipeline/checks/kickoff.php` holds the pure parts (config lookup, slug, placeholder) and `pipeline_kickoff()`, which walks the spec's eight steps and turns every refusal into a `PipelineKickoffHalt` caught once. `dispatch_cli.php` gains the `kickoff` arm and its argument parser. The tests drive the real CLI against a throwaway bare origin and clone, with a fake `gh` first on `PATH`.

**Tech Stack:** PHP 8.4 on the host, Pest 4 (`./vendor/bin/pest`), git, Markdown skill references.

**Spec:** `docs/superpowers/specs/2026-09-24-pipeline-kickoff-design.md`

**Verified before writing (2026-09-24):** the code in Tasks 1–3, assembled into a scratch copy of this branch, passes the whole pipeline suite (244 tests: 211 on main and the 33 new ones), and the upstream expectation fails when the `--unset-upstream` line is removed. `git worktree add -b <b> origin/main` sets `origin/main` as upstream, and `git worktree list --porcelain` prints resolved paths (`/private/var/…` on macOS). Added at review-plan and not in that scratch run: `pipeline_branch_prefix()` with its dataset (Task 1) and the prefix guard in `pipeline_kickoff_unclaimed()` with its case (Task 2); the helper and `git for-each-ref --format=%(refname:lstrip=2) refs/heads/` were tried on a scratch repo (git 2.33), and their own tests verify them at implement.

## Global Constraints

- Command: `php dispatch_cli.php kickoff <repo-root> <number|idea> [--light] [--mode autoflow|auto] [--decision "<verbatim>"]...`; `--mode` defaults to `autoflow`; `interactive`, an unknown flag, a flag without its value, or a missing positional is a usage error (exit 1).
- Answers: `{"action":"ready","manifest":…,"worktree":…,"branch":…,"notes":[…]}` or `{"action":"halt","reason":…}`, one JSON line, exit 0.
- The declared `worktree.create` runs as declared, through `sh -c` from `<repo-root>`, stdin `/dev/null`, stdout and stderr together; `<branch>` is its only substitution. A remaining `<placeholder>` (`/<[A-Za-z][A-Za-z0-9_-]*>/`) halts. A failed create halts with its exit code and the last 40 lines of output. Nothing is retried.
- The worktree path comes from `git worktree list --porcelain` (the entry with `branch refs/heads/<branch>`), never from the command or its output.
- After the create: unset the new branch's upstream when it has one, `pipeline_exclude_manifest()`, then the first `manifest_write` at `<worktree>/.claude/pipeline/<branch, / → ->.json` with exactly `branch`, `worktree`, `mode`, `cursor: {leg: design, status: pending}`, `artifacts.issue` or `artifacts.idea`, plus `light: true` and `decisions` only when given.
- The board claim runs last and only after a successful create; its failure is a note, never a halt. An `invalid` `## Board` halts before anything is created. `absent` says nothing.
- Already started: a local branch of that name halts; for an issue so does any local branch starting with `branch.issue` cut at `<slug>` with `<number>` filled in (`feature/issue-69-`), named in the halt. A pattern with no `<slug>`, or no `<number>` before it, checks the exact name only.
- Slug: lowercase, `[^a-z0-9]+` → `-`, trim `-`, over 50 characters cut at the last `-` within the first 51 (hard cut at 50 when there is none).
- Suites (from the worktree root, on the host): `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`, `./vendor/bin/pest -c skills/critique/checks/phpunit.xml --test-directory=skills/critique/checks/tests`, `bash skills/orchestrate/tests/owners_test.sh`.
- This repo has no `.changelog/` and no `CHANGELOG.md`: no changelog entry.

## Review Focus

1. **A declared create that waits on stdin** (a script asking "continue? [y/N]") must not hang an unattended run: stdin is `/dev/null`, so it fails and halts. Pinned in Task 2 (`read answer || exit 4`).
2. **`gh` not authenticated, or the issue does not exist** — the issue probe fails and the run halts before anything is created. Pinned in Task 2 (no `issue.json` for the fake).
3. **The blocker probe itself failing** (a repo or `gh` without the dependencies endpoint) is not a pass: halt. Pinned in Task 2 (no `blocked_by.json`).
4. **A create that exits 0 but makes no worktree for the branch** (a script that ignores its argument, a `true` placeholder) halts instead of writing a manifest into nowhere. Pinned in Task 2 (`create: true`).
5. **Titles with punctuation or accents** (`UserForm: e-mailadres moet uniek zijn per tenant`, `Überschrift`) give a branch git and `sh` both accept. Pinned in Task 1's slug dataset.

---

## File Structure

- Create `skills/pipeline/checks/kickoff.php` — `PipelineKickoffHalt`, the pure helpers, the probes, `pipeline_kickoff()`.
- Modify `skills/pipeline/checks/dispatch_cli.php` — require `kickoff.php`, `dispatch_cli_kickoff_args()`, `dispatch_cli_kickoff()`, the match arm, the docblock and the usage line.
- Modify `skills/pipeline/checks/tests/Pest.php` — load `kickoff.php`.
- Create `skills/pipeline/checks/tests/KickoffTest.php` — the pure helpers.
- Modify `skills/pipeline/checks/tests/DispatchCliTest.php` — the fixture, the fake `gh`, the command cases.
- Modify `skills/pipeline/SKILL.md`, `skills/pipeline/references/engine.md`, `skills/orchestrate/references/commands.md` — the docs.

---

### Task 1: The pure parts — config lookup, slug, placeholder

**Files:**
- Create: `skills/pipeline/checks/kickoff.php`
- Modify: `skills/pipeline/checks/tests/Pest.php` (the loader list)
- Test: `skills/pipeline/checks/tests/KickoffTest.php`

**Interfaces:**
- Produces: `pipeline_repo_config_value(string $configMarkdown, string $section, string $key): ?string`, `pipeline_slug(string $text): string`, `pipeline_placeholder(string $text): ?string` (the first `<name>` token, or null), `pipeline_branch_prefix(string $pattern, int $number): ?string` (every branch for the issue starts with it, or null).

- [ ] **Step 1: Install the dev dependencies in the worktree** (the worktree has no `vendor/`; it is gitignored)

A symlinked `vendor/` does not work: Pest cannot name the test classes. Run: `composer install --no-interaction --quiet && ./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: the suite is green before any change. Note the count of passing tests.

- [ ] **Step 2: Write the failing test** — `skills/pipeline/checks/tests/KickoffTest.php`

```php
<?php

it('reads a key from its own section only, without its trailing comment', function () {
    $config = implode("\n", [
        '# work-on — per-repo config',
        '## Repo',
        '- repo: acme/app    # the GitHub repo',
        '## Worktree',
        '# - create: commented out',
        '- create: ./scripts/worktree.sh create <branch> --no-start',
        '## Branch convention',
        '- issue: feature/issue-<number>-<slug>   # issue pickup',
        '- pr: use head.ref',
        '### Worktree notes',
        '- empty:',
    ]);

    expect(pipeline_repo_config_value($config, 'Repo', 'repo'))->toBe('acme/app');
    expect(pipeline_repo_config_value($config, 'Worktree', 'create'))->toBe('./scripts/worktree.sh create <branch> --no-start');
    expect(pipeline_repo_config_value($config, 'Branch convention', 'issue'))->toBe('feature/issue-<number>-<slug>');
    expect(pipeline_repo_config_value($config, 'Repo', 'issue'))->toBeNull();
    expect(pipeline_repo_config_value($config, 'Board', 'org'))->toBeNull();
    expect(pipeline_repo_config_value($config, 'Branch convention', 'empty'))->toBeNull();
});

it('slugs a title into a branch-safe name of at most 50 characters', function (string $title, string $slug) {
    expect(pipeline_slug($title))->toBe($slug);
})->with([
    'punctuation' => ['Pipeline: kickoff as one command', 'pipeline-kickoff-as-one-command'],
    'edges and runs' => ['  --Hello,   World!--  ', 'hello-world'],
    'a dash inside a word' => ['UserForm: e-mailadres moet uniek zijn per tenant', 'userform-e-mailadres-moet-uniek-zijn-per-tenant'],
    'accents' => ['Überschrift ändern', 'berschrift-ndern'],
    'cut at a word' => ['pipeline: kickoff as one tested dispatch_cli command, so no session judgement precedes launch', 'pipeline-kickoff-as-one-tested-dispatch-cli'],
    'a word ending at 50' => [str_repeat('a', 50) . ' b', str_repeat('a', 50)],
    'no word boundary' => [str_repeat('a', 60), str_repeat('a', 50)],
    'nothing to slug' => ['!!!', ''],
]);

it('finds a placeholder, and does not mistake a shell redirection for one', function (string $text, ?string $placeholder) {
    expect(pipeline_placeholder($text))->toBe($placeholder);
})->with([
    'a slot to compute' => ['./scripts/worktree.sh create feature/x --slot <next-free-N>', '<next-free-N>'],
    'redirections' => ['make create 2>&1 < input.txt', null],
    'nothing left' => ['git worktree add .claude/worktrees/feature/x -b feature/x origin/main', null],
]);

it('cuts a branch pattern at the slug, so every branch for the issue shares the prefix', function (string $pattern, ?string $prefix) {
    expect(pipeline_branch_prefix($pattern, 69))->toBe($prefix);
})->with([
    'feature branches' => ['feature/issue-<number>-<slug>', 'feature/issue-69-'],
    'worktree branches' => ['worktree-issue-<number>-<slug>', 'worktree-issue-69-'],
    'no slug' => ['feature/issue-<number>', null],
    'the number after the slug' => ['<slug>-<number>', null],
]);
```

Add `'kickoff.php'` to the end of the file list in `skills/pipeline/checks/tests/Pest.php`:

```php
foreach (['triggers.php', 'pipeline.php', 'manifest.php', 'checks.php', 'board.php', 'proof.php', 'proof_render.php', 'design_size.php', 'suite.php', 'dispatch.php', 'brief.php', 'run_cost.php', 'engine_peak.php', 'kickoff.php'] as $f) {
```

- [ ] **Step 3: Run it to see it fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='own section only|branch-safe name|shell redirection|cuts a branch pattern'`
Expected: FAIL, `Call to undefined function pipeline_repo_config_value()` (and the slug, placeholder and prefix functions).

- [ ] **Step 4: Write the implementation** — `skills/pipeline/checks/kickoff.php`

```php
<?php

/**
 * `dispatch_cli.php kickoff` (`../references/engine.md` §Kickoff): §The work item and §Kickoff in one
 * call for the unattended modes. Every value comes from the repo's config, gh or git; a value kickoff
 * would have to compute is a halt.
 */

require_once __DIR__ . '/board.php';
require_once __DIR__ . '/dispatch.php';
require_once __DIR__ . '/manifest.php';
require_once __DIR__ . '/suite.php';

/** A `- key: value` line under `## <section>` of `.claude/work-on.config.md`, its trailing `# …` stripped; null when absent or empty. */
function pipeline_repo_config_value(string $configMarkdown, string $section, string $key): ?string
{
    $inSection = false;
    foreach (preg_split('/\R/', $configMarkdown) as $line) {
        if (preg_match('/^##\s+(.+?)\s*$/', $line, $heading)) {
            $inSection = $heading[1] === $section;

            continue;
        }
        if ($inSection && preg_match('/^\s*-\s*' . preg_quote($key, '/') . '\s*:\s*(.*)$/', $line, $match)) {
            $value = trim(preg_replace('/\s+#.*$/', '', $match[1]));

            return $value === '' ? null : $value;
        }
    }

    return null;
}

/** `work-on`'s slug rule: lowercase, runs outside `[a-z0-9]` become `-`, at most 50 characters, cut at a word. */
function pipeline_slug(string $text): string
{
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
    if (strlen($slug) <= 50) {
        return $slug;
    }
    $cut = strrpos(substr($slug, 0, 51), '-');

    return substr($slug, 0, $cut === false ? 50 : $cut);
}

/** The first `<name>` a caller would still have to fill in; shell redirections are not names. */
function pipeline_placeholder(string $text): ?string
{
    return preg_match('/<[A-Za-z][A-Za-z0-9_-]*>/', $text, $match) ? $match[0] : null;
}

/** `branch.issue` up to `<slug>`, the number filled in: every branch for the issue starts with it, whoever slugged the title. Null without a `<slug>`, or without a `<number>` before it. */
function pipeline_branch_prefix(string $pattern, int $number): ?string
{
    $head = strstr($pattern, '<slug>', true);

    return $head === false || ! str_contains($head, '<number>') ? null : str_replace('<number>', (string) $number, $head);
}
```

- [ ] **Step 5: Run it to see it pass, then the whole suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, the Step 1 count plus the new cases.

- [ ] **Step 6: Commit**

```bash
git add skills/pipeline/checks/kickoff.php skills/pipeline/checks/tests/KickoffTest.php skills/pipeline/checks/tests/Pest.php
git commit -m "pipeline: kickoff's config lookup, slug and placeholder rule (#69)"
```

---

### Task 2: `dispatch_cli.php kickoff` — the item, the branch, the declared create, the first manifest

**Files:**
- Modify: `skills/pipeline/checks/kickoff.php` (append)
- Modify: `skills/pipeline/checks/dispatch_cli.php` (requires, two functions, match arm, docblock, usage)
- Test: `skills/pipeline/checks/tests/DispatchCliTest.php` (append)

**Interfaces:**
- Consumes: Task 1's three functions; `pipeline_repo_board()` (`board.php`); `pipeline_git_run()`, `pipeline_git()`, `pipeline_exclude_manifest()` (`suite.php`); `manifest_write()` (`manifest.php`); `pipeline_halt()` (`dispatch.php`, required by `kickoff.php`); `suite_repo()` (`tests/SuiteTest.php`, already used by `DispatchCliTest.php`); `dispatch_cli(array $arguments, array $env = [])` (the test's own helper).
- Produces: `pipeline_kickoff(string $repoRoot, string $item, array $options): array` with `$options = ['mode' => string, 'light' => bool, 'decisions' => list<string>]`; `pipeline_worktree_of(string $repoRoot, string $branch): ?string`; `pipeline_kickoff_gh(string $cwd, array $args): array{0: int, 1: string, 2: string}`; `dispatch_cli_kickoff_args(array $arguments): ?array`. Task 3 adds the board claim into `pipeline_kickoff()`.

- [ ] **Step 1: Write the failing tests** — append to `skills/pipeline/checks/tests/DispatchCliTest.php`

```php
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
```

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='kicks off|first manifest|open blocker|work item cannot|value kickoff|create that fails|exits 0 without|already exists|another branch for the issue|cannot parse'`
Expected: FAIL. `dispatch_cli.php` answers `kickoff` with its usage line and exit 1, so `json` is null (the parse case passes by accident; that is fine, it pins the refusal).

- [ ] **Step 3: Write the implementation** — append to `skills/pipeline/checks/kickoff.php`

```php
/** A refusal at kickoff; `pipeline_kickoff()` turns it into the halt it prints. */
final class PipelineKickoffHalt extends RuntimeException
{
}

/**
 * The spec's steps in order (`docs/superpowers/specs/2026-09-24-pipeline-kickoff-design.md`). Up to the
 * create nothing exists, so a halt there leaves nothing behind.
 *
 * @param  array{mode: string, light: bool, decisions: list<string>}  $options
 */
function pipeline_kickoff(string $repoRoot, string $item, array $options): array
{
    try {
        $config = pipeline_kickoff_config($repoRoot);
        $issue = pipeline_kickoff_issue($repoRoot, $config, $item);
        $branch = pipeline_kickoff_branch($config, $item, $issue);
        $command = pipeline_kickoff_create_command($config, $branch);
        pipeline_kickoff_unclaimed($repoRoot, $config, $branch, $issue);
        $worktree = pipeline_kickoff_create($repoRoot, $command, $branch);
    } catch (PipelineKickoffHalt $halt) {
        return pipeline_halt($halt->getMessage());
    }

    try {
        $manifest = pipeline_kickoff_prepare($worktree, $branch, pipeline_kickoff_manifest($branch, $worktree, $item, $issue, $options));
    } catch (RuntimeException $failure) {
        return pipeline_halt("kickoff created {$worktree} but could not finish it, and wrote no manifest: {$failure->getMessage()}; remove the worktree and its branch before kicking off again");
    }

    return ['action' => 'ready', 'manifest' => $manifest, 'worktree' => $worktree, 'branch' => $branch, 'notes' => []];
}

function pipeline_kickoff_config(string $repoRoot): string
{
    $path = rtrim($repoRoot, '/') . '/.claude/work-on.config.md';
    if (! is_file($path)) {
        throw new PipelineKickoffHalt("no {$path}: kickoff reads repo, worktree.create and branch.issue from it");
    }

    return (string) file_get_contents($path);
}

function pipeline_kickoff_required(string $config, string $section, string $key): string
{
    return pipeline_repo_config_value($config, $section, $key)
        ?? throw new PipelineKickoffHalt(".claude/work-on.config.md declares no `- {$key}:` under `## {$section}`");
}

/**
 * §The work item for a number: the issue itself, never a PR, and no open blocker. An idea has no work
 * item and asks gh nothing.
 *
 * @return array{number: int, title: string, url: string}|null
 */
function pipeline_kickoff_issue(string $repoRoot, string $config, string $item): ?array
{
    if (! preg_match('/^#?(\d+)$/', $item, $match)) {
        return null;
    }
    $number = (int) $match[1];
    $repo = pipeline_kickoff_required($config, 'Repo', 'repo');
    $issue = pipeline_kickoff_gh_json($repoRoot, ['api', "repos/{$repo}/issues/{$number}"], "#{$number} does not resolve in {$repo}");
    if (($issue['pull_request'] ?? null) !== null) {
        throw new PipelineKickoffHalt("#{$number} is a pull request: kickoff starts runs for issues and ideas; a PR's run resumes with launch --from");
    }
    $blockers = pipeline_kickoff_gh_json($repoRoot, ['api', "/repos/{$repo}/issues/{$number}/dependencies/blocked_by"], "the blockers of #{$number} could not be read");
    $open = array_filter($blockers, fn (mixed $blocker) => is_array($blocker) && ($blocker['state'] ?? null) === 'open');
    if ($open !== []) {
        throw new PipelineKickoffHalt("#{$number} is blocked by " . implode(', ', array_map(fn (array $blocker) => "#{$blocker['number']} {$blocker['title']}", $open)));
    }

    return ['number' => $number, 'title' => (string) ($issue['title'] ?? ''), 'url' => (string) ($issue['html_url'] ?? '')];
}

/** gh from $cwd, stdout and stderr apart so JSON stays JSON. @return array{0: int, 1: string, 2: string} */
function pipeline_kickoff_gh(string $cwd, array $args): array
{
    $stderr = tmpfile();
    $process = proc_open(['gh', ...$args], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => $stderr], $pipes, $cwd);
    if (! is_resource($process)) {
        return [127, '', 'gh could not be started'];
    }
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $code = proc_close($process);
    rewind($stderr);
    $err = trim((string) stream_get_contents($stderr));
    fclose($stderr);

    return [$code, trim((string) $out), $err];
}

function pipeline_kickoff_gh_json(string $cwd, array $args, string $failure): array
{
    [$code, $out, $err] = pipeline_kickoff_gh($cwd, $args);
    $decoded = json_decode($out, true);
    if ($code !== 0 || ! is_array($decoded)) {
        throw new PipelineKickoffHalt("{$failure}: " . ($err === '' ? "gh exited {$code}" : $err));
    }

    return $decoded;
}

/** `branch.issue` for an issue, `feature/<slug>` for an idea; anything left to compute, or unsafe for sh, halts. */
function pipeline_kickoff_branch(string $config, string $item, ?array $issue): string
{
    $branch = $issue === null
        ? 'feature/' . pipeline_kickoff_slug($item)
        : str_replace(['<number>', '<slug>'], [(string) $issue['number'], pipeline_kickoff_slug($issue['title'])], pipeline_kickoff_required($config, 'Branch convention', 'issue'));

    $placeholder = pipeline_placeholder($branch);
    if ($placeholder !== null) {
        throw new PipelineKickoffHalt("the declared branch.issue needs {$placeholder}, which kickoff does not compute (`- issue:` under `## Branch convention`)");
    }
    if (! preg_match('#^[A-Za-z0-9._/-]+$#', $branch)) {
        throw new PipelineKickoffHalt("the branch '{$branch}' holds characters kickoff will not pass to a shell");
    }

    return $branch;
}

function pipeline_kickoff_slug(string $text): string
{
    $slug = pipeline_slug($text);
    if ($slug === '') {
        throw new PipelineKickoffHalt("'{$text}' has nothing to make a branch name from");
    }

    return $slug;
}

/** The declared `worktree.create` with `<branch>` filled in, its only substitution. */
function pipeline_kickoff_create_command(string $config, string $branch): string
{
    $declared = pipeline_kickoff_required($config, 'Worktree', 'create');
    $command = str_replace('<branch>', $branch, $declared);
    $placeholder = pipeline_placeholder($command);
    if ($placeholder !== null) {
        throw new PipelineKickoffHalt("the declared worktree.create needs {$placeholder}, which kickoff does not compute: `- create: {$declared}`");
    }

    return $command;
}

/**
 * An existing branch for the item belongs to a run or session kickoff cannot see: never reuse it. For an
 * issue that is any branch under `pipeline_branch_prefix()`, so a hand-made or work-on branch counts.
 */
function pipeline_kickoff_unclaimed(string $repoRoot, string $config, string $branch, ?array $issue): void
{
    $prefix = $issue === null ? null : pipeline_branch_prefix(pipeline_kickoff_required($config, 'Branch convention', 'issue'), $issue['number']);
    $refs = preg_split('/\R/', pipeline_git($repoRoot, ['for-each-ref', '--format=%(refname:lstrip=2)', 'refs/heads/']), -1, PREG_SPLIT_NO_EMPTY);
    foreach ($refs as $ref) {
        if ($ref === $branch || ($prefix !== null && str_starts_with($ref, $prefix))) {
            throw new PipelineKickoffHalt("branch {$ref} already exists: a run or session has it; resume it with launch");
        }
    }
}

/** Runs the declared create as declared: from the primary checkout, no stdin, one output stream. @return string the worktree path */
function pipeline_kickoff_create(string $repoRoot, string $command, string $branch): string
{
    $process = proc_open(['sh', '-c', $command], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $repoRoot);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $code = proc_close($process);
    $tail = implode("\n", array_slice(preg_split('/\R/', trim((string) $output)), -40));

    if ($code !== 0) {
        throw new PipelineKickoffHalt("the declared worktree.create failed (exit {$code}): `{$command}`\n{$tail}");
    }

    return pipeline_worktree_of($repoRoot, $branch)
        ?? throw new PipelineKickoffHalt("the declared worktree.create exited 0 but no worktree has {$branch}: `{$command}`\n{$tail}\ncheck git worktree list");
}

/** The worktree git lists for the branch, whichever command made it. */
function pipeline_worktree_of(string $repoRoot, string $branch): ?string
{
    foreach (preg_split('/\n\n+/', pipeline_git($repoRoot, ['worktree', 'list', '--porcelain'])) as $entry) {
        if (preg_match('/^worktree (.+)$/m', $entry, $path) && preg_match('/^branch ' . preg_quote("refs/heads/{$branch}", '/') . '$/m', $entry)) {
            return $path[1];
        }
    }

    return null;
}

/** No upstream on the new branch, the manifest out of git, then the first write. @return string the manifest path */
function pipeline_kickoff_prepare(string $worktree, string $branch, array $manifest): string
{
    if (pipeline_git_run($worktree, ['rev-parse', '--abbrev-ref', "{$branch}@{upstream}"])[0] === 0) {
        pipeline_git($worktree, ['branch', '--unset-upstream', $branch]);
    }
    pipeline_exclude_manifest($worktree);
    $path = rtrim($worktree, '/') . '/.claude/pipeline/' . str_replace('/', '-', $branch) . '.json';
    manifest_write($path, $manifest);

    return $path;
}

/** Everything no step will look up (engine.md §Kickoff); a key with nothing to say is absent. */
function pipeline_kickoff_manifest(string $branch, string $worktree, string $item, ?array $issue, array $options): array
{
    return [
        'branch' => $branch,
        'worktree' => $worktree,
        'mode' => $options['mode'],
        'cursor' => ['leg' => 'design', 'status' => 'pending'],
        'artifacts' => $issue === null ? ['idea' => $item] : ['issue' => $issue['number']],
        ...($options['light'] ? ['light' => true] : []),
        ...($options['decisions'] === [] ? [] : ['decisions' => $options['decisions']]),
    ];
}
```

In `skills/pipeline/checks/dispatch_cli.php`:

1. Add to the requires, after `require_once __DIR__ . '/suite.php';` (`kickoff.php` requires `board.php` itself):

```php
require_once __DIR__ . '/kickoff.php';
```

2. In the docblock, add the first `autoflow` line, so the block reads:

```php
 *   autoflow:     php dispatch_cli.php kickoff <repo-root> <number|idea> [--light] [--mode autoflow|auto] [--decision <text>]...
 *                 php dispatch_cli.php launch <manifest> <diff-file> [--from <leg>]
```

and replace the docblock's last sentence (`Exits 1 on a usage error, and when` … `no diff file.`) with:

```php
 * one JSON line. Exits 0 on every decision, a halt included. Exits 1 on a usage error (a `kickoff`
 * it cannot parse included), and when `size` has no readable manifest or `ui` no diff file.
```

3. Before the `$flag = array_search(...)` line, add:

```php
/**
 * `kickoff <repo-root> <number|idea> [--light] [--mode autoflow|auto] [--decision <text>]...`; null is a
 * usage error. `interactive` keeps its session-driven kickoff.
 *
 * @return array{repoRoot: string, item: string, mode: string, light: bool, decisions: list<string>}|null
 */
function dispatch_cli_kickoff_args(array $arguments): ?array
{
    $options = ['mode' => 'autoflow', 'light' => false, 'decisions' => []];
    $positional = [];
    while ($arguments !== []) {
        $argument = (string) array_shift($arguments);
        if ($argument === '--light') {
            $options['light'] = true;

            continue;
        }
        if ($argument === '--mode' || $argument === '--decision') {
            $value = array_shift($arguments);
            if ($value === null) {
                return null;
            }
            match ($argument) {
                '--mode' => $options['mode'] = (string) $value,
                '--decision' => $options['decisions'][] = (string) $value,
            };

            continue;
        }
        if (str_starts_with($argument, '--')) {
            return null;
        }
        $positional[] = $argument;
    }

    return count($positional) === 2 && in_array($options['mode'], ['autoflow', 'auto'], true)
        ? ['repoRoot' => $positional[0], 'item' => $positional[1], ...$options]
        : null;
}

function dispatch_cli_kickoff(array $arguments): ?array
{
    $parsed = dispatch_cli_kickoff_args($arguments);

    return $parsed === null ? null : pipeline_kickoff($parsed['repoRoot'], $parsed['item'], $parsed);
}
```

4. Add the arm as the first one of the `match`:

```php
    'kickoff' => dispatch_cli_kickoff(array_slice($argv, 2)),
```

5. Start the usage line with the new command:

```php
    fwrite(STDERR, "usage: dispatch_cli.php kickoff <repo-root> <number|idea> [--light] [--mode autoflow|auto] [--decision <text>]... | next <manifest> | returned <manifest> <diff-file> | launch <manifest> <diff-file> [--from <leg>] | brief <manifest> <leg> <step> | finish <manifest> <decision-json> | size <manifest> | ui <diff-file> (size needs a readable manifest, ui an existing diff file)\n");
```

- [ ] **Step 4: Run them to see them pass**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='kicks off|first manifest|open blocker|work item cannot|value kickoff|create that fails|exits 0 without|already exists|another branch for the issue|cannot parse'`
Expected: PASS.

- [ ] **Step 5: Check the upstream test can fail**

Temporarily replace the body of the `if` in `pipeline_kickoff_prepare()` with nothing, run `--filter='kicks off an issue'`, and see it FAIL on the `@{upstream}` expectation (the fixture's create leaves `origin/main` as upstream). Restore the line and see it PASS.

- [ ] **Step 6: The whole suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add skills/pipeline/checks/kickoff.php skills/pipeline/checks/dispatch_cli.php skills/pipeline/checks/tests/DispatchCliTest.php
git commit -m "pipeline: dispatch_cli.php kickoff runs the declared create and writes the first manifest (#69)"
```

---

### Task 3: The board — halt on `invalid` before the create, claim last

**Files:**
- Modify: `skills/pipeline/checks/kickoff.php` (`pipeline_kickoff()`, two new functions)
- Test: `skills/pipeline/checks/tests/DispatchCliTest.php` (append)

**Interfaces:**
- Consumes: Task 2's `pipeline_kickoff()`, `pipeline_kickoff_gh()`, `kickoff_fixture()`, `kickoff()`, `kickoff_calls()`, `kickoff_left_nothing()`; `pipeline_repo_board(string): array{state, board, error}`.
- Produces: `pipeline_kickoff_board(string $config): array` (halts on `invalid`), `pipeline_kickoff_claim(string $repoRoot, array $board, array $issue): list<string>` (the notes).

- [ ] **Step 1: Write the failing tests** — append to `skills/pipeline/checks/tests/DispatchCliTest.php`

```php
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
```

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='valid board|could not be recorded|claims nothing|invalid board'`
Expected: FAIL: no notes, no `project` calls, and the invalid board kicks off.

- [ ] **Step 3: Write the implementation** — in `skills/pipeline/checks/kickoff.php`

In `pipeline_kickoff()`, read the board first thing inside the first `try`, right after the config:

```php
        $config = pipeline_kickoff_config($repoRoot);
        $board = pipeline_kickoff_board($config);
```

and replace the final `return` with:

```php
    $notes = $issue !== null && $board['state'] === 'valid' ? pipeline_kickoff_claim($repoRoot, $board['board'], $issue) : [];

    return ['action' => 'ready', 'manifest' => $manifest, 'worktree' => $worktree, 'branch' => $branch, 'notes' => $notes];
```

Append:

```php
/** `pipeline_repo_board()`, with `invalid` a machinery failure that halts before anything exists. */
function pipeline_kickoff_board(string $config): array
{
    $board = pipeline_repo_board($config);
    if ($board['state'] === 'invalid') {
        throw new PipelineKickoffHalt("the ## Board section is invalid: {$board['error']}");
    }

    return $board;
}

/**
 * engine.md §The work item's two calls. Failing to record the claim is a note, never a halt.
 *
 * @param  array{number: int, title: string, url: string}  $issue
 * @return list<string>
 */
function pipeline_kickoff_claim(string $repoRoot, array $board, array $issue): array
{
    [$code, $out, $err] = pipeline_kickoff_gh($repoRoot, ['project', 'item-add', $board['number'], '--owner', $board['org'], '--url', $issue['url'], '--format', 'json']);
    $item = json_decode($out, true)['id'] ?? null;
    if ($code !== 0 || ! is_string($item)) {
        return ["the board claim was not recorded: {$err}"];
    }
    [$code, , $err] = pipeline_kickoff_gh($repoRoot, ['project', 'item-edit', '--id', $item, '--project-id', $board['project-id'], '--field-id', $board['status-field-id'], '--single-select-option-id', $board['in-progress-option-id']]);

    return [$code === 0 ? "#{$issue['number']} is In Progress on board {$board['number']}" : "the board claim was not recorded: {$err}"];
}
```

- [ ] **Step 4: Run them to see them pass, then the whole suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/kickoff.php skills/pipeline/checks/tests/DispatchCliTest.php
git commit -m "pipeline: kickoff halts on an invalid board and claims a valid one last (#69)"
```

---

### Task 4: The docs — `kickoff` then `launch`

**Files:**
- Modify: `skills/pipeline/SKILL.md` (§`autoflow` — how a run starts and ends, steps 1–2)
- Modify: `skills/pipeline/references/engine.md` (§The dispatcher, §`autoflow` diagram and commands, §Kickoff)
- Modify: `skills/orchestrate/references/commands.md` (§Brief, §Launch)

- [ ] **Step 1: `skills/pipeline/SKILL.md`** — replace steps 1 and 2 of §`autoflow` — how a run starts and ends (from `1. **Kickoff** (` through the line ending `A resume starts here: \`launch\` starts from the cursor.`) with:

```markdown
1. **Kickoff.** With `CHECKS="$HOME/.claude/skills/pipeline/checks"`:
   `php "$CHECKS/dispatch_cli.php" kickoff <primary checkout> <number | idea> [--light] [--decision "<verbatim>"]…`
   does §The work item and §Kickoff in one call (`references/engine.md` §Kickoff). `ready`: its
   `manifest` is the run's, and its `notes` go into the report. A halt: report it and stop; never
   create the worktree another way. A denied kickoff call is reported like a halt: nothing is
   retried in another form. A resume skips this step.
2. **Launch.** `git -C <worktree> diff origin/<base>...HEAD > "<manifest stem>.diff"`, then
   `PIPELINE_NO_OPEN=<1 unattended, else 0> php "$CHECKS/dispatch_cli.php" launch <manifest> "<manifest stem>.diff"`.
   `done` or a halt: report it and stop. A resume starts here: `launch` starts from the cursor.
```

- [ ] **Step 2: `skills/pipeline/references/engine.md`, §The dispatcher** — replace

```markdown
**It keeps:** kickoff (§The work item, §Kickoff, the manifest exclusion, writing `decisions` and
`light` from the invocation), the invariant check
```

with

```markdown
**It keeps:** kickoff (`dispatch_cli.php kickoff --mode auto`, §Kickoff), the invariant check
```

- [ ] **Step 3: engine.md, §`autoflow`** — in the diagram replace `invoking session   kickoff → dispatch_cli.php launch →` with `invoking session   dispatch_cli.php kickoff → dispatch_cli.php launch →`, and in the bash block below it add, directly after the `CHECKS=` line:

```bash
php "$CHECKS/dispatch_cli.php" kickoff <primary checkout> <number | idea> [--light] [--decision "<verbatim>"]…
# → {"action":"ready","manifest":…,"worktree":…,"branch":…,"notes":[…]} | {"action":"halt","reason":…}
```

- [ ] **Step 4: engine.md, §Kickoff** — directly after the heading `## Kickoff — resolve the worktree, then start the loop`, before `The whole run lives in **one worktree**`, insert (the outer fence here is `~~~` because the inserted text holds a bash block):

~~~markdown
**In `auto` and `autoflow`, kickoff is one tested command** that does §The work item and this
section in one call and leaves the session nothing to judge:

```bash
php "$CHECKS/dispatch_cli.php" kickoff <primary checkout> <number | idea> [--light] [--mode autoflow|auto] [--decision "<verbatim>"]…
```

It runs the declared `worktree.create` as declared, from the primary checkout, with only `<branch>`
substituted: slot choice belongs to the repo's script, and a declared command that still needs a
value computed (`<next-free-N>`) is a halt naming the config line. A create that fails — a sandbox
refusal, a script that asks a question, a stale path — halts with the command's output, and nothing
is retried in another form. A classifier only ever sees the kickoff call itself: a denial of it
reaches the session before any PHP runs, and is reported like a halt. An existing branch for the
item halts too (for an issue, any branch under `branch.issue` cut at `<slug>`): resume that run with
`launch`. After the create it unsets the new branch's upstream, keeps the manifest out of git, writes
the first manifest (below) and claims the board last. The create runs through `sh -c` behind the one
kickoff call, so an allow rule for `dispatch_cli.php kickoff` is an allow rule for whatever
`worktree.create` declares. `interactive` follows the rest of this section by hand.
~~~

- [ ] **Step 5: `skills/orchestrate/references/commands.md`, §Launch** — replace the two bullets

```markdown
- Kickoff creates the worktree with the declared `worktree.create`; never switch branches in the
  primary checkout, other runs share it.
- The owner's settled decisions for N go into the manifest's `decisions`, verbatim; `artifacts.issue`
  is N; `mode` is `autoflow`.
```

with

```markdown
- Kickoff is `php ~/.claude/skills/pipeline/checks/dispatch_cli.php kickoff <primary checkout> N`,
  with one `--decision "<verbatim>"` per settled decision of the owner's for N. `ready` names the
  manifest (`mode: autoflow`, `artifacts.issue` N); a halt: report it and start nothing. Never
  create the worktree another way or switch branches in the primary checkout; other runs share it.
```

- [ ] **Step 6: commands.md, §Brief** — replace the line

```
<only in a repo without scripts/worktree.sh:> Worktree: create it with the declared worktree.create (<command>). Never switch branches in <path>; other runs share it.
```

with

```
Kickoff: php ~/.claude/skills/pipeline/checks/dispatch_cli.php kickoff <path> <N> --mode auto, with one --decision per settled decision below; a halt ends the run. Never switch branches in <path>; other runs share it.
```

- [ ] **Step 7: Check nothing else describes kickoff by hand for the unattended modes**

Run: `grep -rn "worktree.create\|Kickoff\|kickoff" skills/pipeline/SKILL.md skills/pipeline/references skills/orchestrate`
Expected: every unattended-mode mention names `dispatch_cli.php kickoff` or points at §Kickoff; the §Kickoff prose after the new paragraph is the `interactive` procedure and stays.

- [ ] **Step 8: Commit**

```bash
git add skills/pipeline/SKILL.md skills/pipeline/references/engine.md skills/orchestrate/references/commands.md
git commit -m "pipeline, orchestrate: unattended runs start with dispatch_cli.php kickoff, then launch (#69)"
```

---

### Task 5: All suites, and one kickoff by hand

- [ ] **Step 1: The suites**

Run:
```bash
./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests
./vendor/bin/pest -c skills/critique/checks/phpunit.xml --test-directory=skills/critique/checks/tests
bash skills/orchestrate/tests/owners_test.sh
```
Expected: all green. Record the pipeline suite in the manifest's `suite`.

- [ ] **Step 2: One kickoff outside the tests, on a throwaway clone** (never on this repo's own primary checkout: an issue kickoff there would create a real branch)

```bash
TMP=$(mktemp -d) && git clone -q "$(git rev-parse --show-toplevel)" "$TMP/clone" && mkdir -p "$TMP/clone/.claude" \
  && cp .claude/work-on.config.md "$TMP/clone/.claude/" \
  && php skills/pipeline/checks/dispatch_cli.php kickoff "$TMP/clone" "Try the kickoff command" --light
```
Expected: `{"action":"ready",…,"branch":"feature/try-the-kickoff-command",…}`; `git -C "$TMP/clone" rev-parse --abbrev-ref 'feature/try-the-kickoff-command@{upstream}'` fails (no upstream); the printed manifest holds `light: true` and `artifacts.idea`. Then `rm -rf "$TMP"`.
