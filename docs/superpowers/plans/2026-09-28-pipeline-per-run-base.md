# A per-run base for `pipeline` and `orchestrate` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task by task. Steps use checkbox (`- [ ]`) syntax for tracking. In an `autoflow` implement step, execute inline, task by task, with no subagents.

**Goal:** `dispatch_cli.php kickoff … --base <branch>` cuts a run from a long-lived integration branch on origin, routes its PR into it through `gh-merge-base`, records `base` in the manifest, and every brief and doc diffs against it; `orchestrate` runs a batch on a base and closes each issue after its merge.

**Architecture:**
- `skills/pipeline/checks/dispatch_cli.php`: `--base <branch>` in `dispatch_cli_kickoff_args()`.
- `skills/pipeline/checks/kickoff.php`: `pipeline_kickoff_base()` and `pipeline_kickoff_default_branch()` check the base before anything exists; `pipeline_kickoff_create_command()` appends `--base origin/<base>`; `pipeline_kickoff_on_base()` checks `HEAD` and sets `gh-merge-base` in `pipeline_kickoff_prepare()`; `pipeline_kickoff_manifest()` writes `base`.
- `skills/pipeline/checks/brief.php`: a base line in `pipeline_brief_state()`; a `baseRefName` check line in `pipeline_brief_overrides()` on the `handoff` leg of a run on a base.
- Docs: engine.md (§The loop, §`autoflow`, §Kickoff, §Closing links), manifest.md, pipeline `SKILL.md`, orchestrate `SKILL.md` and `references/commands.md`.

**Tech Stack:** PHP 8.4 on the host, Pest 4, git, `gh` (faked in tests), Markdown skill references.

**Spec:** `docs/superpowers/specs/2026-09-28-pipeline-per-run-base-design.md`

## Global Constraints

- Suite, from the worktree root on the host: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests` (354 green on main). `vendor/` must be a real directory in the worktree, never a symlink to the primary's (Pest would then load the primary checkout's code); if it is missing, `composer install --no-interaction --quiet`.
- `--base` is appended to the declared `worktree.create` as ` --base origin/<base>`; there is no `<base>` placeholder (settled decision 1).
- Kickoff keeps halting on open native blockers; only `orchestrate` closes an issue after a merge into the base (settled decision 2).
- The base's shell-safety regex is the branch's: `#^[A-Za-z0-9._/-]+$#`.
- Halt texts, verbatim: `the base '<b>' holds characters kickoff will not pass to a shell`; `the base <b> is not a branch on origin: <stderr>`; `the base <b> is origin's default branch: leave --base out`; `origin's default branch could not be read: <stderr>`; `the worktree's HEAD (<sha>) is not origin/<b> (<sha>): the declared worktree.create did not honour --base`.
- The brief line, verbatim: ``- base: `<b>`: this branch was cut from `origin/<b>` and its PR goes into it, not into the default branch; diff with `git diff origin/<b>...HEAD`, and a merge into it closes no issue (engine.md §Kickoff)``.
- The handoff check line, verbatim: ``The PR must open into `<b>`: after `handoff pr`, `gh pr view <pr> --json baseRefName --jq .baseRefName` prints `<b>`; otherwise `gh pr edit <pr> --base <b>` before setting `artifacts.pr` (engine.md §Kickoff).`` (rendered as an override bullet).
- `base` is **not** added to `pipeline_leg_writable_keys()`. `launch`, `finish`, `ci`, `dispatch.php`, `run_audit.php` and `pipeline-autoflow.js` do not change.
- This repo has no `.changelog/` and no `CHANGELOG.md`: no changelog entry.

## Review Focus

1. **A base origin has but the primary never fetched** (or fetched long ago): kickoff must cut from origin's tip, not fail or use a stale ref. Pinned in Task 2: `kickoff_integration_branch()` deletes the primary's `refs/remotes/origin/<base>` after the push, so only kickoff's own fetch can make the create succeed and `HEAD` equal the pushed sha.
2. **`--base main`**, the default branch named as a base: a halt, since the brief would otherwise say a merge into `main` closes no issue. Pinned in Task 1's halt dataset.
3. **A create that exits 0 but ignores `--base`**: a halt naming the worktree it left, never a run on the wrong code. Pinned in Task 2.
4. **A leg that rewrites `base`**: a return that does not hold. Existing behaviour through `pipeline_leg_writable_keys()`; pinned in Task 3 by a `ReturnedTest` case (written after the code, so seen red by a temporary mutation).
5. **A run without a base is unchanged**: no `gh-merge-base`, no `base` key (the existing exact-manifest test), no `- base:` brief line. Pinned in Tasks 2 and 3.
6. **A PR that opens into the default branch anyway** (a gh that ignores `gh-merge-base`, or handoff taking over an existing PR): `handoff`'s brief tells it to check `baseRefName` and retarget with `gh pr edit --base`. Pinned in Task 3.

---

## File Structure

- Modify `skills/pipeline/checks/dispatch_cli.php`, `skills/pipeline/checks/kickoff.php`, `skills/pipeline/checks/brief.php`.
- Modify tests `skills/pipeline/checks/tests/DispatchCliTest.php`, `skills/pipeline/checks/tests/BriefTest.php`, `skills/pipeline/checks/tests/ReturnedTest.php`.
- Modify docs `skills/pipeline/references/engine.md`, `skills/pipeline/references/manifest.md`, `skills/pipeline/SKILL.md`, `skills/orchestrate/SKILL.md`, `skills/orchestrate/references/commands.md`.

---

### Task 1: `kickoff --base` parses, and a bad base halts before anything exists

**Files:**
- Modify: `skills/pipeline/checks/dispatch_cli.php` (header docblock line 8, `dispatch_cli_kickoff_args()` ~437–477, usage string ~580)
- Modify: `skills/pipeline/checks/kickoff.php` (`pipeline_kickoff()` ~65–94, new functions after `pipeline_kickoff_slug()`)
- Test: `skills/pipeline/checks/tests/DispatchCliTest.php`

**Interfaces:**
- Consumes: `pipeline_git_run(string $worktree, array $args): array{0: int, 1: string, 2: string}` (`suite.php`), `PipelineKickoffHalt`, the test helpers `kickoff_fixture()`, `kickoff()`, `kickoff_left_nothing()`.
- Produces: `dispatch_cli_kickoff_args()` returns `array{repoRoot, item, mode, light, base: ?string, decisions}`; `pipeline_kickoff_base(string $repoRoot, ?string $base): ?string`; `pipeline_kickoff_default_branch(string $repoRoot): string`; test helper `kickoff_integration_branch(array $fixture, string $base = 'feature/integration'): string` (returns the pushed sha).

- [ ] **Step 1: Write the failing tests.** In `DispatchCliTest.php`, add a row to the dataset of `it('refuses a kickoff it cannot parse', …)`, after `'a flag without its value'`:

```php
    'a base without its value' => [['69', '--base']],
```

Then, directly after that test's dataset (before `function kickoff_board()`), add the helper and the halt test:

```php
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
```

- [ ] **Step 2: Run them to see them fail.**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='cannot parse|unsafe, not a branch'`
Expected: FAIL. The `a base without its value` row already exits 1 (an unknown `--` flag), so it passes; the three halt rows fail because kickoff exits 1 on `--base` and `json` is null.

- [ ] **Step 3: Parse `--base`.** In `dispatch_cli.php`, replace `dispatch_cli_kickoff_args()`'s docblock and body with:

```php
/**
 * `kickoff <repo-root> <number|idea> [--light] [--base <branch>] [--decision <text>]...`; null is a usage
 * error. `--mode autoflow` is accepted and changes nothing; `--mode auto` parses, so that
 * `dispatch_cli_kickoff()` can halt it by name. `interactive` keeps its session-driven kickoff.
 *
 * @return array{repoRoot: string, item: string, mode: string, light: bool, base: ?string, decisions: list<string>}|null
 */
function dispatch_cli_kickoff_args(array $arguments): ?array
{
    $options = ['mode' => 'autoflow', 'light' => false, 'base' => null, 'decisions' => []];
    $positional = [];
    while ($arguments !== []) {
        $argument = (string) array_shift($arguments);
        if ($argument === '--light') {
            $options['light'] = true;

            continue;
        }
        if (in_array($argument, ['--mode', '--base', '--decision'], true)) {
            $value = array_shift($arguments);
            if ($value === null) {
                return null;
            }
            if ($argument === '--decision') {
                $options['decisions'][] = (string) $value;
            } else {
                $options[substr($argument, 2)] = (string) $value;
            }

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
```

In the file's header docblock, line 8 becomes:

```php
 *   autoflow:     php dispatch_cli.php kickoff <repo-root> <number|idea> [--light] [--base <branch>] [--decision <text>]...
```

In the usage string at the bottom, `kickoff <repo-root> <number|idea> [--light] [--decision <text>]...` becomes `kickoff <repo-root> <number|idea> [--light] [--base <branch>] [--decision <text>]...`.

- [ ] **Step 4: Check the base.** In `kickoff.php`, `pipeline_kickoff()`'s `@param` becomes `array{mode: string, light: bool, base: ?string, decisions: list<string>}`, and its first `try` becomes:

```php
        $config = pipeline_kickoff_config($repoRoot);
        $board = pipeline_kickoff_board($config);
        $issue = pipeline_kickoff_issue($repoRoot, $config, $item);
        $branch = pipeline_kickoff_branch($config, $item, $issue);
        $base = pipeline_kickoff_base($repoRoot, $options['base']);
        $command = pipeline_kickoff_create_command($config, $branch);
        pipeline_kickoff_unclaimed($repoRoot, $config, $branch, $issue);
        $worktree = pipeline_kickoff_create($repoRoot, $command, $branch);
```

After `pipeline_kickoff_slug()`, add:

```php
/**
 * A per-run base (engine.md §Kickoff, *A run on a base*) is a branch on origin other than its default.
 * The fetch proves it is one and leaves `origin/<base>` current for the create and the check after it.
 */
function pipeline_kickoff_base(string $repoRoot, ?string $base): ?string
{
    if ($base === null) {
        return null;
    }
    if (! preg_match('#^[A-Za-z0-9._/-]+$#', $base)) {
        throw new PipelineKickoffHalt("the base '{$base}' holds characters kickoff will not pass to a shell");
    }
    [$code, , $err] = pipeline_git_run($repoRoot, ['fetch', '-q', 'origin', "+refs/heads/{$base}:refs/remotes/origin/{$base}"]);
    if ($code !== 0) {
        throw new PipelineKickoffHalt("the base {$base} is not a branch on origin: {$err}");
    }
    if ($base === pipeline_kickoff_default_branch($repoRoot)) {
        throw new PipelineKickoffHalt("the base {$base} is origin's default branch: leave --base out");
    }

    return $base;
}

/** origin's `HEAD` as origin itself names it; the local `origin/HEAD` is often stale. */
function pipeline_kickoff_default_branch(string $repoRoot): string
{
    [$code, $out, $err] = pipeline_git_run($repoRoot, ['ls-remote', '--symref', 'origin', 'HEAD']);
    if ($code !== 0 || ! preg_match('#^ref: refs/heads/(\S+)\tHEAD$#m', $out, $match)) {
        throw new PipelineKickoffHalt("origin's default branch could not be read: " . ($err === '' ? "git exited {$code}" : $err));
    }

    return $match[1];
}
```

(`$base` is computed but not yet used after the check; Task 2 uses it.)

- [ ] **Step 5: Run them to see them pass.**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='cannot parse|unsafe, not a branch'`
Expected: PASS, 22 tests (Pest's `--filter` matches descriptions across files: the 18 "cannot parse" rows it selects today in the kickoff, brief, launch and ci datasets, plus the new parse row and the 3 halt rows).

- [ ] **Step 6: Commit.**

```bash
git add skills/pipeline/checks/dispatch_cli.php skills/pipeline/checks/kickoff.php skills/pipeline/checks/tests/DispatchCliTest.php
git commit -m "feat(pipeline): kickoff --base: a base must be a non-default branch on origin, checked before anything exists (#102)"
```

### Task 2: the run is cut from the base, its PR routed into it, `base` in the manifest

**Files:**
- Modify: `skills/pipeline/checks/kickoff.php` (`pipeline_kickoff()`, `pipeline_kickoff_create_command()` ~195–206, `pipeline_kickoff_prepare()` ~252–263, `pipeline_kickoff_manifest()` ~265–277)
- Test: `skills/pipeline/checks/tests/DispatchCliTest.php`

**Interfaces:**
- Consumes: `pipeline_kickoff_base()` and `kickoff_integration_branch()` from Task 1; `pipeline_git()`, `pipeline_git_run()`, `manifest_read()`.
- Produces: `pipeline_kickoff_create_command(string $config, string $branch, ?string $base): string`; `pipeline_kickoff_prepare(string $worktree, string $branch, ?string $base, array $manifest): string`; `pipeline_kickoff_on_base(string $worktree, string $branch, string $base): void`; the manifest key `base` (string, absent without a base).

- [ ] **Step 1: Write the failing tests.** In `DispatchCliTest.php`, after the halt test from Task 1, add:

```php
it('kicks off on a per-run base: cut from it, gh-merge-base set, recorded in the manifest', function () {
    $fixture = kickoff_fixture('create-wt <branch> --no-start');
    $sha = kickoff_integration_branch($fixture);
    $branch = 'feature/issue-69-pipeline-kickoff-as-one-command';

    $ready = kickoff($fixture, ['69', '--base', 'feature/integration'])['json'];

    expect($ready)->toMatchArray(['action' => 'ready', 'branch' => $branch]);
    expect(pipeline_git($ready['worktree'], ['rev-parse', 'HEAD']))->toBe($sha);
    expect(pipeline_git($ready['worktree'], ['config', "branch.{$branch}.gh-merge-base"]))->toBe('feature/integration');
    expect(manifest_read($ready['manifest']))->toMatchArray(['base' => 'feature/integration', 'artifacts' => ['issue' => 69]]);
});

it('sets no gh-merge-base without a base', function () {
    $fixture = kickoff_fixture();

    $ready = kickoff($fixture, ['69'])['json'];

    expect(pipeline_git_run($ready['worktree'], ['config', "branch.{$ready['branch']}.gh-merge-base"])[0])->not->toBe(0);
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
```

The third fixture's `sh -c '…' <branch>` receives the appended `--base origin/feature/integration` as extra arguments it ignores, so it cuts from `origin/main`.

- [ ] **Step 2: Run them to see them fail.**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='per-run base|no gh-merge-base|does not honour'`
Expected: FAIL on the first (`create-wt` cuts from `origin/main` because nothing is appended, so `HEAD` differs) and the third (`ready` instead of a halt); `sets no gh-merge-base without a base` passes already (it pins that the base path stays opt-in).

- [ ] **Step 3: Append, check, route, record.** In `kickoff.php`:

`pipeline_kickoff()`: the create command line becomes `$command = pipeline_kickoff_create_command($config, $branch, $base);` and the second `try`'s line becomes:

```php
        $manifest = pipeline_kickoff_prepare($worktree, $branch, $base, pipeline_kickoff_manifest($branch, $worktree, $item, $issue, $options));
```

Replace `pipeline_kickoff_create_command()` with:

```php
/**
 * The declared `worktree.create` with `<branch>` filled in, its only substitution, and ` --base
 * origin/<base>` after it on a run with a base: the declared command is shared with `work-on` and
 * `orchestrate`, which substitute only `<branch>`, so a `<base>` placeholder would reach the script
 * literally from each of them.
 */
function pipeline_kickoff_create_command(string $config, string $branch, ?string $base): string
{
    $declared = pipeline_kickoff_required($config, 'Worktree', 'create');
    $command = str_replace('<branch>', $branch, $declared);
    $placeholder = pipeline_placeholder($command);
    if ($placeholder !== null) {
        throw new PipelineKickoffHalt("the declared worktree.create needs {$placeholder}, which kickoff does not compute: `- create: {$declared}`");
    }

    return $base === null ? $command : "{$command} --base origin/{$base}";
}
```

Replace `pipeline_kickoff_prepare()` with, and add `pipeline_kickoff_on_base()` after it:

```php
/** On its base, no upstream on the new branch, the manifest out of git, then the first write. @return string the manifest path */
function pipeline_kickoff_prepare(string $worktree, string $branch, ?string $base, array $manifest): string
{
    if ($base !== null) {
        pipeline_kickoff_on_base($worktree, $branch, $base);
    }
    if (pipeline_git_run($worktree, ['rev-parse', '--abbrev-ref', "{$branch}@{upstream}"])[0] === 0) {
        pipeline_git($worktree, ['branch', '--unset-upstream', $branch]);
    }
    pipeline_exclude_manifest($worktree);
    $path = rtrim($worktree, '/') . '/.claude/pipeline/' . str_replace('/', '-', $branch) . '.json';
    manifest_write($path, $manifest);

    return $path;
}

/** The create honoured the base, and `gh pr create` without `--base` opens the run's PR into it (gh reads `gh-merge-base`). */
function pipeline_kickoff_on_base(string $worktree, string $branch, string $base): void
{
    $head = pipeline_git($worktree, ['rev-parse', 'HEAD']);
    $tip = pipeline_git($worktree, ['rev-parse', "origin/{$base}"]);
    if ($head !== $tip) {
        throw new PipelineKickoffHalt("the worktree's HEAD ({$head}) is not origin/{$base} ({$tip}): the declared worktree.create did not honour --base");
    }
    pipeline_git($worktree, ['config', "branch.{$branch}.gh-merge-base", $base]);
}
```

In `pipeline_kickoff_manifest()`, after the `'mode' => $options['mode'],` line, add:

```php
        ...($options['base'] === null ? [] : ['base' => $options['base']]),
```

- [ ] **Step 4: Run them to see them pass, then the kickoff tests as a whole.**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='kick|base'`
Expected: PASS, including the existing `kicks off an issue: the declared create, no upstream, the manifest excluded and written` (its exact manifest has no `base`).

- [ ] **Step 5: Commit.**

```bash
git add skills/pipeline/checks/kickoff.php skills/pipeline/checks/tests/DispatchCliTest.php
git commit -m "feat(pipeline): kickoff --base cuts the worktree from origin/<base>, checks HEAD, sets gh-merge-base and records base (#102)"
```

### Task 3: every brief names the base; a leg cannot change it

**Files:**
- Modify: `skills/pipeline/checks/brief.php` (`pipeline_brief_state()` ~150–168)
- Test: `skills/pipeline/checks/tests/BriefTest.php`, `skills/pipeline/checks/tests/ReturnedTest.php`

**Interfaces:**
- Consumes: the manifest key `base` (Task 2); test helpers `brief_manifest(string $leg, array $extra = [])`, `returned_before()`, `returned_after()`.
- Produces: the brief line and the handoff check line in Global Constraints.

- [ ] **Step 1: Write the failing tests.** Append to `BriefTest.php`:

```php
it('tells every leg of a run on a base where its branch came from and where its PR goes', function (string $leg) {
    $brief = pipeline_brief(brief_manifest($leg, ['base' => 'feature/issue-2042-kleurenpaletten']), $leg, '/tmp/m.json');

    expect($brief)->toContain('- base: `feature/issue-2042-kleurenpaletten`: this branch was cut from `origin/feature/issue-2042-kleurenpaletten` and its PR goes into it, not into the default branch; diff with `git diff origin/feature/issue-2042-kleurenpaletten...HEAD`, and a merge into it closes no issue (engine.md §Kickoff)');
})->with(['design', 'handoff', 'implement', 'review-pr']);

it('says nothing about a base on a run without one', function () {
    expect(pipeline_brief(brief_manifest('implement'), 'implement', '/tmp/m.json'))->not->toContain('- base:');
});

it('makes handoff on a run on a base check that the PR opened into it', function () {
    expect(pipeline_brief(brief_manifest('handoff', ['base' => 'feature/integration']), 'handoff', '/tmp/m.json'))
        ->toContain('- The PR must open into `feature/integration`: after `handoff pr`, `gh pr view <pr> --json baseRefName --jq .baseRefName` prints `feature/integration`; otherwise `gh pr edit <pr> --base feature/integration` before setting `artifacts.pr` (engine.md §Kickoff).');
    expect(pipeline_brief(brief_manifest('handoff'), 'handoff', '/tmp/m.json'))->not->toContain('The PR must open into');
});
```

In `ReturnedTest.php`, after `it('reports a manifest problem before anything the step reported', …)`, add:

```php
it('does not let a leg change the run\'s base', function () {
    $before = [...returned_before('implement'), 'base' => 'feature/integration'];

    expect(pipeline_reported_problem($before, returned_after($before, 'continued', null, ['base' => 'main']), ['status' => 'continued'], DesignSize::Architectural))
        ->toBe('the leg changed base, which only the dispatcher writes');
});
```

- [ ] **Step 2: Run them to see them fail.**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='run on a base|without one|change the run'`
Expected: the four base-line rows and `makes handoff on a run on a base check …` FAIL (no such lines); `says nothing about a base`, `does not let a leg change the run's base` and the existing `halts with the leg's own reason, and refuses a halt without one` (`ReturnedTest`, matched by `without one`) PASS. See the `ReturnedTest` case red once: temporarily add `'base'` to `pipeline_leg_writable_keys()` in `dispatch.php`, run the filter, confirm it fails, and revert the line.

- [ ] **Step 3: Write the base line and the handoff check.** In `brief.php`, `pipeline_brief_state()`, after the `$lines = …` decisions line, add:

```php
    $base = $manifest['base'] ?? null;
    if ($base !== null) {
        $lines[] = "- base: `{$base}`: this branch was cut from `origin/{$base}` and its PR goes into it, not into the default branch; diff with `git diff origin/{$base}...HEAD`, and a merge into it closes no issue (engine.md §Kickoff)";
    }
```

In `pipeline_brief_overrides()`, after the `review-pr` CI-round `if`, add:

```php
    $base = $manifest['base'] ?? null;
    if ($leg === 'handoff' && $base !== null) {
        $lines[] = "The PR must open into `{$base}`: after `handoff pr`, `gh pr view <pr> --json baseRefName --jq .baseRefName` prints `{$base}`; otherwise `gh pr edit <pr> --base {$base}` before setting `artifacts.pr` (engine.md §Kickoff).";
    }
```

- [ ] **Step 4: Run them to see them pass.**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='run on a base|without one|change the run'`
Expected: PASS, 8 tests (4 base-line rows, `says nothing`, the handoff check, the new `ReturnedTest` case, and the existing `refuses a halt without one`).

- [ ] **Step 5: Commit.**

```bash
git add skills/pipeline/checks/brief.php skills/pipeline/checks/tests/BriefTest.php skills/pipeline/checks/tests/ReturnedTest.php
git commit -m "feat(pipeline): every brief of a run on a base names it, its diff and that a merge into it closes nothing (#102)"
```

### Task 4: the docs — `<base>` defined once, a run on a base, a batch on a base

**Files:**
- Modify: `skills/pipeline/references/engine.md`, `skills/pipeline/references/manifest.md`, `skills/pipeline/SKILL.md`, `skills/orchestrate/SKILL.md`, `skills/orchestrate/references/commands.md`

**Interfaces:**
- Consumes: the behaviour of Tasks 1–3; the heading `## Kickoff — resolve the worktree, then start the loop` (the brief line cites §Kickoff; `LockStepTest` checks override lines, and this heading must stay).
- Produces: nothing code reads.

- [ ] **Step 1: engine.md §The loop.** After the paragraph that starts ``` `<manifest stem>` is the manifest path without `.json` ```, append to that paragraph:

```markdown
`<base>` is the manifest's `base` on a run kicked off with one (§Kickoff, *A run on a base*), and the
repo's default branch otherwise: every `origin/<base>` in this skill and in `orchestrate` means that.
```

- [ ] **Step 2: engine.md §`autoflow` and §Kickoff command lines.** In both code blocks (§`autoflow` and §Kickoff), `kickoff <primary checkout> <number | "<idea>"> [--light] [--decision "<verbatim>"]…` becomes `kickoff <primary checkout> <number | "<idea>"> [--light] [--base <branch>] [--decision "<verbatim>"]…`.

- [ ] **Step 3: engine.md §Kickoff.** In the paragraph under the command, `with only `<branch>`\nsubstituted:` becomes ``with only `<branch>` substituted (and `--base` appended on a run with a base, below):``. After that paragraph (ending *"`interactive` follows the rest of this section by hand."*), insert:

```markdown
**A run on a base.** `--base <branch>` cuts the run from a long-lived integration branch instead of
the default branch, diffs against it and opens its PR into it: for work that must reach the default
branch in one go, such as a set of issues whose deploy operations may not run in production in
between. Where a repo mid-rewrite declares a `--base` in its `worktree.create` for every run (below),
this one is per run.

- **Before anything exists** kickoff fetches `+refs/heads/<base>` from origin into `origin/<base>`. A
  base that holds characters a shell would read, that is not a branch on origin, or that is origin's
  default branch (`git ls-remote --symref origin HEAD`) halts, leaving nothing behind.
- **The create gets `--base origin/<base>` appended** to the declared command; the repo's script
  resolves it (`scripts/worktree.sh create … --base <ref>`). Not a `<base>` placeholder in the declared
  command: that command is shared with `work-on` and `orchestrate`, which substitute only `<branch>`, so a
  placeholder would reach the script literally from each of them; and a base belongs to a run, not to
  the repo. A script that takes no `--base` fails, and its output is the halt. A create declared with a
  repo-level `--base` gets a second one; the script decides which wins, and the check below catches it.
- **After the create** kickoff checks that the worktree's `HEAD` equals `origin/<base>`: a script that
  ignored the flag halts there, naming the worktree it left, rather than handing every leg a branch cut
  from the wrong code. Then it sets `git config branch.<branch>.gh-merge-base <base>`, so `handoff`'s
  `gh pr create`, which passes no `--base`, opens the PR into the base (gh reads that config when
  `--base` is absent), and writes `base` into the first manifest. `handoff`'s brief then has it check the
  PR's `baseRefName` and retarget with `gh pr edit --base` when it differs: a gh that ignores the config,
  or a PR that already existed, would otherwise open into the default branch and look healthy on every
  leg after it.
- **Every leg after it** reads the base from its brief (`pipeline_brief_state()`), and every
  `origin/<base>` diff, `run_audit.php`'s diff and the CI gate's fix round use it (§The loop). `launch`
  needs no flag for it: the manifest carries it, and a leg that changes `base` is a return that does not
  hold (it is not in `pipeline_leg_writable_keys()`).
- **A merge into the base closes no issue**: GitHub closes issues only on merges into the default
  branch. The finish step still settles the closing links (§Closing links); the issue closes when
  someone closes it (`orchestrate` does that for its batch), or through the base's own PR into the
  default branch.
- **The pipeline never opens the base's own PR** into the default branch: that PR is the owner's.
```

In the bullet under *From an idea* that ends *"Dropping the flag cuts the run's branch from the wrong code, and every leg after it looks healthy."*, append after that sentence: `(For one run's own base, see *A run on a base* above.)`

In *The first `manifest_write` carries everything no step will look up.*, `` `branch`, `worktree`, `mode`, `cursor: {leg: design, status: pending}` `` becomes `` `branch`, `worktree`, `mode`, `base` when the invocation named one, `cursor: {leg: design, status: pending}` ``.

- [ ] **Step 4: engine.md §Closing links.** Before the paragraph starting *"**A run that never had an issue skips this section silently**"*, insert:

```markdown
**A run on a base** (§Kickoff) reconciles the same way, but its PR goes into the base, and a merge
there closes nothing: record each issue's outcome as it would be on the default branch, and say in
the PR body that the merge into `<base>` closes nothing and the issue closes when someone closes it, or
through the base's own PR (`orchestrate` closes it after the merge).
```

- [ ] **Step 5: manifest.md.** In §Fields, after the `light` row, add:

```markdown
| `base` | optional | the branch kickoff's `--base` cut the run from and its PR goes into (`engine.md` §Kickoff, *A run on a base*); absent means the default branch. Written once by kickoff; a leg that changes it halts the run. A reconstructed manifest recovers it from the PR's `baseRefName` once a PR exists, else from `git config branch.<branch>.gh-merge-base` (which disappears with the branch) |
```

- [ ] **Step 6: pipeline `SKILL.md`.** In §Invocation and navigation, the first grammar line becomes `/pipeline [interactive|autoflow] [light] [base <branch>] <idea | number | spec-path>` (realign the second line's `#` comment with it), and its comment becomes `# start a run (mode defaults to interactive; base <branch> becomes kickoff's --base)`. In §`autoflow`, step 1's command gains `[--base <branch>]` after `[--light]`, and after *"does §The work item and §Kickoff in one call (`references/engine.md` §Kickoff)."* add: *"`--base` cuts the run from that branch on origin instead of the default branch, records it as the manifest's `base`, and routes the PR into it (§Kickoff, *A run on a base*); the base's own PR into the default branch is never the run's."* Step 2's `git -C <worktree> diff origin/<base>...HEAD > "<manifest stem>.diff"` gains `(`<base>`: the manifest's `base`, else the default branch)` after it.

- [ ] **Step 7: orchestrate `SKILL.md`.** After the paragraph starting *"`/orchestrate <issue> …` runs each issue"*, insert:

```markdown
**A batch on a base.** `/orchestrate <issue> … base <branch>` (or a brief saying "with base <branch>") runs every issue of the batch on that long-lived integration branch: each kickoff gets `--base <branch>`, and every run is cut from it, diffed against it and opens its PR into it (pipeline `engine.md` §Kickoff, *A run on a base*). `launch` takes the base from the manifest. The branch must exist on origin; kickoff halts otherwise. A merge into the base closes no issue, so after each merge you close that issue yourself (Step 6), or its dependents halt at kickoff on an open blocker. The base's own PR into the default branch is not a run's: open or touch it only when your brief says so, and never merge it.
```

In Step 6, after *"**tear down without asking**, even under an ask-first brief and ahead of `slots`' confirm step."*, insert: *"In a batch on a base, close the merged run's issue (commands §Teardown)."*

- [ ] **Step 8: orchestrate `references/commands.md`.** After the preamble's second line (ending `` `<repo>` is `repo:` from `.claude/work-on.config.md`. ``), add the line: ``<base>` is the run manifest's `base` in a batch on a base, and the default branch otherwise.`` In §Launch's first bullet, `with one `--decision "<verbatim>"`` becomes ``with `--base <branch>` in a batch on a base, and one `--decision "<verbatim>"` ``. At the end of §Teardown, append:

````markdown
In a batch on a base, the merge closed nothing: close the issue, so the runs it blocks can kick off.
```bash
gh issue close N -R <repo> --reason completed --comment "Merged into <base> in #<P>; reaches the default branch with <base>."
```
````

- [ ] **Step 9: Check the docs against the code and each other.**

Run: `grep -n "\-\-base" skills/pipeline/references/engine.md skills/pipeline/SKILL.md skills/orchestrate/SKILL.md skills/orchestrate/references/commands.md skills/pipeline/checks/dispatch_cli.php`
Expected: every kickoff command line shows `[--base <branch>]` (engine.md twice, `SKILL.md` once, the `dispatch_cli.php` header and usage string), and no `<base>` placeholder inside a declared `worktree.create`.

Run the whole suite: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, 354 + the new tests (1 parse row, 3 halt rows, 3 kickoff tests, 4 + 1 + 1 brief tests, 1 returned test = 368), 0 failed. `LockStepTest` still finds §Kickoff.

- [ ] **Step 10: Commit.**

```bash
git add skills/pipeline/references/engine.md skills/pipeline/references/manifest.md skills/pipeline/SKILL.md skills/orchestrate/SKILL.md skills/orchestrate/references/commands.md
git commit -m "docs(pipeline, orchestrate): <base> defined once; a run on a base; a batch on a base closes each issue after its merge (#102)"
```
