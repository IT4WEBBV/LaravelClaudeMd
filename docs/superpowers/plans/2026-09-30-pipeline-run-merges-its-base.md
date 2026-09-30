# A run merges its base into its own branch Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task by task. Steps use checkbox (`- [ ]`) syntax for tracking. In an `autoflow` implement step, execute inline, task by task, with no subagents.

**Goal:** A `/pipeline` run keeps its own branch current with its base by a plain merge the brief orders, instead of halting on "behind", and a merge the last review did not see gets one review round at the CI gate (#124).

**Architecture:**
- `skills/pipeline/checks/brief.php` computes the base state (`pipeline_base_state()`: fetch, compare, overlap) on the six steps that write to the branch and puts one override line first in the brief, carrying the literal `git -C <worktree> merge --no-edit origin/<base>` command. The step only runs it and resolves conflicts.
- `skills/pipeline/checks/ci.php` answers `fix` with verdict `merge`, once per run, when `dispatch_cli.php ci` hands it the files a merge since the last completed review met the branch in (`pipeline_review_scope()`'s `files`, `autoflow` only).
- The rule and its why live in a new `engine.md` section, §Catching up with the base. `CLAUDE.md` gets the exception to the stale-checkout rule, `README.md` the allow rules, `orchestrate` the sibling note.

**Tech Stack:** PHP 8.4 on the host, Pest 4, git 2.33 (no `merge-tree --write-tree`), Markdown skill references.

**Spec:** `docs/superpowers/specs/2026-09-30-pipeline-run-merges-its-base-design.md`

## Global Constraints

- Suite, from the worktree root on the host: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`. `vendor/` is missing in a fresh worktree: run `composer install --no-interaction --quiet` first.
- The merge command is the contract with the allow rules and stays in exactly this form: `git -C <worktree> merge --no-edit origin/<base>`. The conflict conclusion is `git -C <worktree> commit --no-edit` (not `merge --continue`: it needs an editor, spec *Probe*); the abort is `git -C <worktree> merge --abort`.
- Never a rebase, never a force-push, in code or in any text this plan writes.
- The steps that catch up are exactly `design:run`, `design:spec`, `design:plan`, `review-plan:resolve`, `implement:run`, `review-pr:resolve`.
- Any git call that fails in `pipeline_base_state()` gives null: no line, the run carries on. It never halts.
- Unchanged: the manifest's shape and `pipeline_leg_writable_keys()`; `workflow/pipeline-autoflow.js`; the routing tables and statuses; `pipeline_review_scope()`'s result; `hooks/git-freshness.sh`; every settings file.
- The new `engine.md` heading is `## Catching up with the base — a run merges its base into its own branch`: `LockStepTest` resolves a brief's `§` names against the heading text before ` — `.
- Text written to GitHub or into docs addresses nobody (global `CLAUDE.md`, *Never address a human*).
- This repo has no `.changelog/` and no `CHANGELOG.md`: no changelog entry. The PR closes #124.

## Review Focus

1. **A fetch that fails (offline, a remote that moved) must not stop or change a brief.** A reasonable owner expects the run to carry on as today. Task 1 pins null; Task 2 pins that `brief` still prints the step's brief without the line.
2. **A spec or plan recorded as an absolute path.** The branch must still count as design-only, or a design step never catches up. Task 1 pins it.
3. **A branch that renamed or deleted a file the base changed.** The old path must count as shared (`--no-renames` lists it on both sides), or the merge that conflicts is skipped. Task 1 pins it.
4. **The merge round when gh cannot read the PR.** The gate must answer `fix` at once, not `wait` for an hour on `unreadable`. Task 3 pins it with a null view, in the pure function and through the CLI.
5. **A run that spent its merge round and then goes red, and the reverse.** Each round is its own: a spent merge round must leave the CI fix round available, and a spent CI round must leave the merge round. Task 3 pins both.

---

## File Structure

- Modify `skills/pipeline/checks/brief.php`: `PIPELINE_CATCH_UP_STEPS`, `pipeline_base_ref()`, `pipeline_design_files()`, `pipeline_base_state()`, `pipeline_catch_up_line()`; `pipeline_brief()` and `pipeline_brief_overrides()` take the state; `pipeline_review_scope()` calls `pipeline_base_ref()`.
- Modify `skills/pipeline/checks/ci.php`: `PIPELINE_MERGE_UNREVIEWED`, `pipeline_ci_unreviewed()`, `pipeline_merge_rounds()`, `pipeline_decisions_starting()`; `pipeline_ci_answer()` takes `$unreviewed`.
- Modify `skills/pipeline/checks/dispatch_cli.php`: `dispatch_cli_unreviewed()`, called by `dispatch_cli_ci()`.
- Create `skills/pipeline/checks/tests/BaseStateTest.php`. Modify `BriefTest.php`, `CiTest.php`, `DispatchCliTest.php`, `LockStepTest.php`.
- Modify `skills/pipeline/references/engine.md` (new section; §What a leg brief consists of; §The CI gate), `skills/pipeline/references/manifest.md` (the `decisions` row), `skills/pipeline/SKILL.md` (steps 3 and 5).
- Modify `CLAUDE.md`, `README.md`, `skills/orchestrate/SKILL.md`, `skills/orchestrate/references/commands.md`.

Test helpers are shared across test files, as they already are: `suite_repo()` (`SuiteTest.php`), `rereview_repo()`, `rereview_commit()`, `rereview_main_moves()`, `rereview_merge()`, `rereview_entry()` (`ReviewScopeTest.php`), `brief_manifest()` (`BriefTest.php`), `dispatch_fixture()`, `dispatch_cli()` (`DispatchCliTest.php`). `ReviewScopeTest`'s repos have no `origin` remote (they set `origin/main` with `update-ref`), so the fetch fails there and the base state is null: every existing test on those repos keeps its result.

---

### Task 1: the base state

**Files:**
- Modify: `skills/pipeline/checks/brief.php` (after `pipeline_review_base()`, and inside `pipeline_review_scope()`)
- Create: `skills/pipeline/checks/tests/BaseStateTest.php`
- Test: `skills/pipeline/checks/tests/BaseStateTest.php`, `skills/pipeline/checks/tests/ReviewScopeTest.php` (unchanged, must stay green)

**Interfaces:**
- Consumes, all existing: `pipeline_git_lines(callable $git, array $args): ?array` (the lines git printed, null on a non-zero exit); `pipeline_git_run(string $worktree, array $args): array{0: int, 1: string, 2: string}`; `pipeline_git(string $worktree, array $args): string` (throws on failure); the test helpers `suite_repo(): string` and `rereview_commit(string $dir, array $files, string $message = 'work'): string`.
- Produces:
  - `pipeline_base_ref(array $manifest, callable $git): ?string`: `origin/<manifest base>`, else what `origin/HEAD` names, else null.
  - `pipeline_base_state(array $manifest, callable $git): ?array`: `['base' => string, 'behind' => int, 'shared' => list<string>]`, or null for "no merge".
  - `PIPELINE_CATCH_UP_STEPS`: `list<string>` of `<leg>:<step>`.
  - Test helpers for Tasks 2 and 3: `base_repo(array $files = []): string`, `base_moves(string $dir, array $files, string $branch = 'main'): void`, `base_state(string $dir, array $extra = []): ?array`.

- [ ] **Step 1: Write the failing tests.** Create `skills/pipeline/checks/tests/BaseStateTest.php`:

```php
<?php

function base_identity(string $dir): void
{
    foreach ([['config', 'user.email', 'test@example.com'], ['config', 'user.name', 'Test'], ['config', 'commit.gpgsign', 'false']] as $args) {
        pipeline_git($dir, $args);
    }
}

/**
 * A clone of a bare `origin` whose `main` holds `$files`, on a `feature` branch cut from it, with
 * `origin/HEAD` as a clone sets it. Unlike `rereview_repo()` it has a remote, so a fetch works.
 */
function base_repo(array $files = []): string
{
    $seed = suite_repo();
    pipeline_git($seed, ['branch', '-M', 'main']);
    if ($files !== []) {
        rereview_commit($seed, $files, 'base');
    }
    $root = sys_get_temp_dir() . '/pipeline-base-' . uniqid();
    mkdir($root);
    pipeline_git($root, ['clone', '-q', '--bare', $seed, "{$root}/origin.git"]);
    pipeline_git($root, ['clone', '-q', "{$root}/origin.git", "{$root}/work"]);
    base_identity("{$root}/work");
    pipeline_git("{$root}/work", ['switch', '-q', '-c', 'feature']);

    return "{$root}/work";
}

/** Another clone commits `$files` on `$branch` and pushes: origin moves, and the run's checkout has not fetched it. */
function base_moves(string $dir, array $files, string $branch = 'main'): void
{
    $root = dirname($dir);
    $other = "{$root}/other-" . uniqid();
    pipeline_git($root, ['clone', '-q', "{$root}/origin.git", $other]);
    base_identity($other);
    pipeline_git($other, ['switch', '-q', $branch]);
    rereview_commit($other, $files, 'base work');
    pipeline_git($other, ['push', '-q', 'origin', $branch]);
}

function base_state(string $dir, array $extra = []): ?array
{
    $manifest = ['branch' => 'feature', 'worktree' => $dir, 'mode' => 'autoflow', 'cursor' => ['leg' => 'implement', 'status' => 'pending'], ...$extra];

    return pipeline_base_state($manifest, fn (array $args) => pipeline_git_run($dir, $args));
}

it('names the six steps that write to the branch as the ones that catch up', function () {
    expect(PIPELINE_CATCH_UP_STEPS)->toBe(['design:run', 'design:spec', 'design:plan', 'review-plan:resolve', 'implement:run', 'review-pr:resolve']);
});

it('asks for no merge while the branch is not behind its base', function () {
    $dir = base_repo();

    expect(base_state($dir))->toBeNull();
    rereview_commit($dir, ['feature.php' => "<?php\n"]);
    expect(base_state($dir))->toBeNull();
});

it('asks for no merge when the base moved only in files the branch\'s code does not touch', function () {
    $dir = base_repo(['shared.php' => "base\n"]);
    rereview_commit($dir, ['feature.php' => "<?php\n"]);
    base_moves($dir, ['shared.php' => "main\n", 'main-only.php' => "<?php\n"]);

    expect(base_state($dir))->toBeNull();
});

it('asks for a merge, naming the shared files, when the base moved in a file the branch changes, after fetching the base', function () {
    $dir = base_repo(['shared.php' => "base\n", 'other.php' => "base\n"]);
    rereview_commit($dir, ['shared.php' => "feature\n", 'feature.php' => "<?php\n"]);
    base_moves($dir, ['shared.php' => "main\n"]);
    base_moves($dir, ['other.php' => "main\n"]);

    expect(pipeline_git($dir, ['rev-list', '--count', 'HEAD..origin/main']))->toBe('0');
    expect(base_state($dir))->toBe(['base' => 'origin/main', 'behind' => 2, 'shared' => ['shared.php']]);
    expect(pipeline_git($dir, ['rev-list', '--count', 'HEAD..origin/main']))->toBe('2');
});

it('counts a file the branch renamed or deleted as shared when the base changed it', function () {
    $dir = base_repo(['old.php' => "base\n", 'gone.php' => "base\n"]);
    pipeline_git($dir, ['mv', 'old.php', 'new.php']);
    pipeline_git($dir, ['rm', '-q', 'gone.php']);
    pipeline_git($dir, ['commit', '-q', '-m', 'rename and delete']);
    base_moves($dir, ['old.php' => "main\n", 'gone.php' => "main\n"]);

    expect(base_state($dir))->toBe(['base' => 'origin/main', 'behind' => 1, 'shared' => ['gone.php', 'old.php']]);
});

it('asks for a merge on any base movement while the branch holds nothing, or only its spec and plan', function () {
    $artifacts = ['artifacts' => ['spec' => 'docs/spec.md', 'plan' => 'docs/plan.md']];

    $empty = base_repo();
    base_moves($empty, ['main-only.php' => "<?php\n"]);
    expect(base_state($empty))->toBe(['base' => 'origin/main', 'behind' => 1, 'shared' => []]);

    $design = base_repo();
    mkdir("{$design}/docs");
    rereview_commit($design, ['docs/spec.md' => "# spec\n", 'docs/plan.md' => "# plan\n"]);
    base_moves($design, ['main-only.php' => "<?php\n"]);
    expect(base_state($design, $artifacts))->toBe(['base' => 'origin/main', 'behind' => 1, 'shared' => []]);
    expect(base_state($design, ['artifacts' => ['spec' => "{$design}/docs/spec.md", 'plan' => "{$design}/docs/plan.md"]]))
        ->toBe(['base' => 'origin/main', 'behind' => 1, 'shared' => []]);

    rereview_commit($design, ['feature.php' => "<?php\n"]);
    expect(base_state($design, $artifacts))->toBeNull();
});

it('measures against the run\'s base when the manifest has one, and against origin/HEAD otherwise', function () {
    $dir = base_repo();
    pipeline_git($dir, ['push', '-q', 'origin', 'HEAD:refs/heads/integration']);
    base_moves($dir, ['integration-only.php' => "<?php\n"], 'integration');

    expect(base_state($dir, ['base' => 'integration']))->toBe(['base' => 'origin/integration', 'behind' => 1, 'shared' => []]);
    expect(base_state($dir))->toBeNull();
});

it('asks for no merge when the base does not resolve, the fetch fails, or git cannot run', function () {
    $dir = base_repo();
    base_moves($dir, ['main-only.php' => "<?php\n"]);

    expect(base_state($dir, ['base' => 'gone']))->toBeNull();
    expect(pipeline_base_state(['worktree' => "{$dir}/missing"], fn (array $args) => pipeline_git_run("{$dir}/missing", $args)))->toBeNull();

    $offline = base_repo();
    base_moves($offline, ['main-only.php' => "<?php\n"]);
    pipeline_git($offline, ['remote', 'set-url', 'origin', dirname($offline) . '/moved.git']);
    expect(base_state($offline))->toBeNull();

    pipeline_git($dir, ['symbolic-ref', '--delete', 'refs/remotes/origin/HEAD']);
    expect(base_state($dir))->toBeNull();
});
```

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests skills/pipeline/checks/tests/BaseStateTest.php`
Expected: FAIL, every test: `Undefined constant "PIPELINE_CATCH_UP_STEPS"` in the first, `Call to undefined function pipeline_base_state()` in the others.

- [ ] **Step 3: Extract the base ref.** In `brief.php`, `pipeline_review_scope()`, replace

```php
    $base = isset($manifest['base'])
        ? "origin/{$manifest['base']}"
        : (pipeline_git_lines($git, ['symbolic-ref', '-q', '--short', 'refs/remotes/origin/HEAD'])[0] ?? null);
    if ($base === null) {
```

with

```php
    $base = pipeline_base_ref($manifest, $git);
    if ($base === null) {
```

and add, directly above `pipeline_review_scope()`'s docblock:

```php
/** The run's base as a ref: `origin/<manifest base>`, else what `origin/HEAD` names, or null when neither resolves. */
function pipeline_base_ref(array $manifest, callable $git): ?string
{
    return isset($manifest['base'])
        ? "origin/{$manifest['base']}"
        : (pipeline_git_lines($git, ['symbolic-ref', '-q', '--short', 'refs/remotes/origin/HEAD'])[0] ?? null);
}
```

- [ ] **Step 4: Write the base state.** In `brief.php`, directly below `pipeline_base_ref()`:

```php
/** The steps that write to the branch, and so catch up with its base first (`../references/engine.md` §Catching up with the base). */
const PIPELINE_CATCH_UP_STEPS = ['design:run', 'design:spec', 'design:plan', 'review-plan:resolve', 'implement:run', 'review-pr:resolve'];

/** The run's spec and plan as git names them: relative to the worktree. */
function pipeline_design_files(array $manifest): array
{
    $worktree = rtrim((string) $manifest['worktree'], '/') . '/';
    $paths = array_filter([$manifest['artifacts']['spec'] ?? null, $manifest['artifacts']['plan'] ?? null]);

    return array_map(fn (string $path) => str_starts_with($path, $worktree) ? substr($path, strlen($worktree)) : $path, array_values($paths));
}

/**
 * Whether a step must merge the base first (`../references/engine.md` §Catching up with the base), or null
 * for no: the branch is not behind, the base moved only in files the branch's code does not touch, or any
 * git call failed (a run that cannot tell carries on). A branch that holds no code yet, nothing or only its
 * spec and plan, merges on any movement: a design reads current code. `$git` runs git in the worktree, as
 * `pipeline_git_run()` does; the base is fetched first.
 *
 * @return array{base: string, behind: int, shared: list<string>}|null
 */
function pipeline_base_state(array $manifest, callable $git): ?array
{
    $base = pipeline_base_ref($manifest, $git);
    if ($base === null) {
        return null;
    }
    $name = substr($base, strlen('origin/'));
    if ($git(['fetch', '-q', 'origin', "+refs/heads/{$name}:refs/remotes/origin/{$name}"])[0] !== 0) {
        return null;
    }
    $behind = pipeline_git_lines($git, ['rev-list', '--count', "HEAD..{$base}"]);
    $ours = pipeline_git_lines($git, ['diff', '--name-only', '--no-renames', "{$base}...HEAD"]);
    $theirs = pipeline_git_lines($git, ['diff', '--name-only', '--no-renames', "HEAD...{$base}"]);
    if ($behind === null || $ours === null || $theirs === null || (int) ($behind[0] ?? 0) === 0) {
        return null;
    }
    $own = array_diff($ours, pipeline_design_files($manifest));
    $shared = array_values(array_intersect($own, $theirs));

    return $own === [] || $shared !== [] ? ['base' => $base, 'behind' => (int) $behind[0], 'shared' => $shared] : null;
}
```

- [ ] **Step 5: Run them to see them pass**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests skills/pipeline/checks/tests/BaseStateTest.php skills/pipeline/checks/tests/ReviewScopeTest.php`
Expected: PASS, both files (`ReviewScopeTest.php` has no new case: it proves the extraction changed nothing).

- [ ] **Step 6: Commit**

```bash
git add skills/pipeline/checks/brief.php skills/pipeline/checks/tests/BaseStateTest.php
git commit -m "feat(pipeline): the base state says whether a step must merge its base first (#124)"
```

---

### Task 2: the brief line, and the section it names

**Files:**
- Modify: `skills/pipeline/checks/brief.php` (`pipeline_brief()`, `pipeline_brief_overrides()`, new `pipeline_catch_up_line()`)
- Modify: `skills/pipeline/references/engine.md` (new section before `## Scoped re-review`; one bullet in §What a leg brief consists of)
- Test: `skills/pipeline/checks/tests/BriefTest.php`, `skills/pipeline/checks/tests/LockStepTest.php`, `skills/pipeline/checks/tests/DispatchCliTest.php`

**Interfaces:**
- Consumes from Task 1: `pipeline_base_state(array $manifest, callable $git): ?array` returning `['base' => string, 'behind' => int, 'shared' => list<string>]`; `PIPELINE_CATCH_UP_STEPS`; the test helpers `base_repo()`, `base_moves()`. Existing: `brief_manifest(string $leg, array $extra = []): array` (worktree `/tmp/wt`, spec `docs/spec.md`, mode `interactive` unless overridden), `dispatch_fixture(array $manifest = []): array`, `dispatch_cli(array $arguments, array $env = []): array`.
- Produces:
  - `pipeline_catch_up_line(array $manifest, array $state): string`.
  - `pipeline_brief_overrides(array $manifest, string $leg, string $step, ?array $scope = null, ?array $catchUp = null): string`.
  - `pipeline_brief()` keeps its signature.

- [ ] **Step 1: Write the failing tests.** In `BriefTest.php`, replace the last test of the file (*asks git only on review-pr's review step, and briefs the whole PR when git cannot scope it*) with

```php
it('asks git only on the steps that need it, and briefs the whole PR and no catch-up when git fails', function (string $leg, string $step, int $calls) {
    $asked = [];
    $git = function (array $args) use (&$asked) {
        $asked[] = $args;

        return [1, '', 'not a git repository'];
    };
    $manifest = brief_manifest($leg, ['mode' => 'autoflow', 'gate_ledger' => [rereview_entry('continued', str_repeat('a', 40))]]);

    expect(pipeline_brief($manifest, $leg, '/tmp/m.json', $step, $git))->not->toContain('Scoped re-review')->not->toContain('Catch up with the base');
    expect($asked)->toHaveCount($calls);
})->with([
    'review-pr review' => ['review-pr', 'review', 1],
    'review-pr resolve' => ['review-pr', 'resolve', 1],
    'review-plan review' => ['review-plan', 'review', 0],
    'implement' => ['implement', 'run', 1],
]);
```

and append:

```php
/** A git that finds the branch 27 commits behind `origin/main`, with `$files` changed on both sides. */
function brief_git_behind(array $files = ['a.php', 'b.php'], string $behind = '27'): Closure
{
    return fn (array $args): array => match ($args[0]) {
        'symbolic-ref' => [0, 'origin/main', ''],
        'fetch' => [0, '', ''],
        'rev-list' => [0, $behind, ''],
        'diff' => [0, implode("\n", $files), ''],
        default => [1, '', 'unexpected'],
    };
}

it('puts the catch-up line first on every step that writes to the branch, with the merge command in its literal form', function (string $mode, string $leg, string $step) {
    $brief = pipeline_brief(brief_manifest($leg, ['mode' => $mode]), $leg, '/tmp/m.json', $step, brief_git_behind());

    expect($brief)->toContain("## Overrides\n\n- Catch up with the base first (engine.md §Catching up with the base): `origin/main` is 27 commits ahead and changed files this branch changes too (`a.php`, `b.php`). Before any other work run `git -C /tmp/wt merge --no-edit origin/main`, as its own command in exactly that form. On a conflict, resolve each file keeping both sides' intent, `git -C /tmp/wt add <file>`, and conclude with `git -C /tmp/wt commit --no-edit`. Only where both sides cannot be kept: `git -C /tmp/wt merge --abort` and return `halted`, quoting the conflicting hunks. Never rebase, never force-push. A denied command is a halt naming it; do not reshape it. Record the merge as that section says.\n- ");
})->with([
    'design run' => ['interactive', 'design', 'run'],
    'design spec' => ['autoflow', 'design', 'spec'],
    'design plan' => ['autoflow', 'design', 'plan'],
    'review-plan resolve' => ['autoflow', 'review-plan', 'resolve'],
    'implement' => ['autoflow', 'implement', 'run'],
    'review-pr resolve' => ['interactive', 'review-pr', 'resolve'],
]);

it('gives no catch-up line to a step that does not write to the branch, or without git, or when the base state is null', function () {
    foreach ([['review-plan', 'review'], ['review-pr', 'review'], ['handoff', 'run'], ['verify-ui', 'run']] as [$leg, $step]) {
        expect(pipeline_brief(brief_manifest($leg, ['mode' => 'autoflow']), $leg, '/tmp/m.json', $step, brief_git_behind()))
            ->not->toContain('Catch up with the base');
    }
    expect(pipeline_brief(brief_manifest('implement'), 'implement', '/tmp/m.json', 'run'))->not->toContain('Catch up with the base');
    expect(pipeline_brief(brief_manifest('implement'), 'implement', '/tmp/m.json', 'run', brief_git_behind(['a.php'], '0')))->not->toContain('Catch up with the base');
});

it('words the design-only case and a single commit', function () {
    $manifest = ['worktree' => '/tmp/wt/'];

    expect(pipeline_catch_up_line($manifest, ['base' => 'origin/feature/integration', 'behind' => 1, 'shared' => []]))
        ->toStartWith('Catch up with the base first (engine.md §Catching up with the base): `origin/feature/integration` is 1 commit ahead and this branch holds only its design. Before any other work run `git -C /tmp/wt merge --no-edit origin/feature/integration`, as its own command in exactly that form.');
    expect(pipeline_brief(brief_manifest('design', ['mode' => 'autoflow']), 'design', '/tmp/m.json', 'spec', brief_git_behind(['docs/spec.md'])))
        ->toContain('is 27 commits ahead and this branch holds only its design.');
});
```

In `LockStepTest.php`, the test *keeps every engine.md section a brief names*, replace

```php
        ...[[pipeline_review_scope_line(['since' => 'abc', 'base' => 'origin/main', 'commits' => 1, 'files' => []])]],
```

with

```php
        ...[[
            pipeline_review_scope_line(['since' => 'abc', 'base' => 'origin/main', 'commits' => 1, 'files' => []]),
            pipeline_catch_up_line(['worktree' => '/tmp/wt'], ['base' => 'origin/main', 'behind' => 1, 'shared' => []]),
        ]],
```

In `DispatchCliTest.php`, append after the test *scopes an interactive run's re-review the same way*:

```php
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
```

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='asks git only on the steps|catch-up|engine.md section a brief names|design-only case'`
Expected: FAIL. *asks git only on the steps that need it* on `toHaveCount(1)` for `review-pr resolve` and `implement` (0 calls today); *puts the catch-up line first* on `toContain` for all six; *words the design-only case* and *keeps every engine.md section a brief names* with `Call to undefined function pipeline_catch_up_line()`; *prints the catch-up line first in the brief of a writing step* on its first `toContain`. *gives no catch-up line to a step that does not write* passes already.

- [ ] **Step 3: The line.** In `brief.php`, directly above `pipeline_review_scope_line()`'s docblock:

```php
/**
 * A writing step's first override when `pipeline_base_state()` asks for a merge (`../references/engine.md`
 * §Catching up with the base). The commands are literal: the allow rules in `README.md` match this form.
 */
function pipeline_catch_up_line(array $manifest, array $state): string
{
    ['base' => $base, 'behind' => $behind, 'shared' => $shared] = $state;
    $git = 'git -C ' . rtrim((string) $manifest['worktree'], '/');
    $ahead = $behind === 1 ? '1 commit ahead' : "{$behind} commits ahead";
    $why = $shared === []
        ? 'and this branch holds only its design'
        : 'and changed files this branch changes too (' . implode(', ', array_map(fn (string $file) => "`{$file}`", $shared)) . ')';

    return "Catch up with the base first (engine.md §Catching up with the base): `{$base}` is {$ahead} {$why}. "
        . "Before any other work run `{$git} merge --no-edit {$base}`, as its own command in exactly that form. "
        . "On a conflict, resolve each file keeping both sides' intent, `{$git} add <file>`, and conclude with `{$git} commit --no-edit`. "
        . "Only where both sides cannot be kept: `{$git} merge --abort` and return `halted`, quoting the conflicting hunks. "
        . 'Never rebase, never force-push. A denied command is a halt naming it; do not reshape it. Record the merge as that section says.';
}
```

- [ ] **Step 4: Hand it to the brief.** In `brief.php`, replace `pipeline_brief()`'s docblock and its first two statements

```php
/**
 * `$step` is given in `autoflow` (the workflow script names it) and derived from the ledger in `interactive`.
 * `$git` runs git in the worktree; only `review-pr`'s review step asks it, for its scope (engine.md §Scoped re-review).
 */
function pipeline_brief(array $manifest, string $leg, string $manifestPath, ?string $step = null, ?callable $git = null): string
{
    $step ??= pipeline_step($manifest, $leg);
    $scope = $git !== null && "{$leg}:{$step}" === 'review-pr:review' ? pipeline_review_scope($manifest, $git) : null;
```

with

```php
/**
 * `$step` is given in `autoflow` (the workflow script names it) and derived from the ledger in `interactive`.
 * `$git` runs git in the worktree: `review-pr`'s review step asks it for its scope (engine.md §Scoped
 * re-review), a step that writes to the branch for the base state (engine.md §Catching up with the base).
 */
function pipeline_brief(array $manifest, string $leg, string $manifestPath, ?string $step = null, ?callable $git = null): string
{
    $step ??= pipeline_step($manifest, $leg);
    $scope = $git !== null && "{$leg}:{$step}" === 'review-pr:review' ? pipeline_review_scope($manifest, $git) : null;
    $catchUp = $git !== null && in_array("{$leg}:{$step}", PIPELINE_CATCH_UP_STEPS, true) ? pipeline_base_state($manifest, $git) : null;
```

and in the same function replace

```php
        pipeline_brief_overrides($manifest, $leg, $step, $scope),
```

with

```php
        pipeline_brief_overrides($manifest, $leg, $step, $scope, $catchUp),
```

In `pipeline_brief_overrides()`, replace

```php
function pipeline_brief_overrides(array $manifest, string $leg, string $step, ?array $scope = null): string
{
    $lines = pipeline_leg_overrides((string) $manifest['mode'])["{$leg}:{$step}"];
```

with

```php
function pipeline_brief_overrides(array $manifest, string $leg, string $step, ?array $scope = null, ?array $catchUp = null): string
{
    $lines = [
        ...($catchUp === null ? [] : [pipeline_catch_up_line($manifest, $catchUp)]),
        ...pipeline_leg_overrides((string) $manifest['mode'])["{$leg}:{$step}"],
    ];
```

- [ ] **Step 5: engine.md, the section.** In `skills/pipeline/references/engine.md`, insert directly above the line `## Scoped re-review — a review of the PR after a completed one reads what changed since`:

```markdown
## Catching up with the base — a run merges its base into its own branch

A run keeps its own branch current with its base by a plain merge, and does not halt on "behind". Why
(#124): in one `/orchestrate` batch on IT4WEBBV/Deploy (#456–#461, 2026-09-29/30) a run fell behind its
base four times, and each time it halted for an owner answer and a relaunch. The global `CLAUDE.md` told
every step not to merge on its own initiative, no brief said who merges or when, and a step that noticed
improvised: a rebase in one run, a halt in the next.

**Code decides whether, the step merges.** On a step that writes to the branch (`PIPELINE_CATCH_UP_STEPS`
in `../checks/brief.php`: `design`'s steps, both resolve steps and `implement`), `pipeline_base_state()`
fetches the base (`origin/<manifest base>`, else `origin/HEAD`) and compares:

| The branch | The base | The brief |
|---|---|---|
| is not behind | | no line |
| holds code | moved only in files the branch does not change | no line: CI tests the PR's merge ref |
| holds code | moved in a file the branch changes | the line, naming the shared files |
| holds nothing, or only its spec and plan | moved at all | the line: a design is written by reading the code, so it reads current code |

Any git call that fails gives no line: a run that cannot tell carries on, and an offline fetch is no
reason to stop. The review steps do not merge (a reviewer that resolves a conflict reviews its own work),
nor does `handoff` or `verify-ui`. Both modes get the line.

**The line is the step's first override** and carries the command:
`git -C <worktree> merge --no-edit origin/<base>`, run as its own command in exactly that form, because a
permission rule matches a command as typed (`README.md`, *Permissions for unattended runs*). A denied
command is a halt naming it, never a reshaped command.

- **The merge comes first**, on the clean tree the previous step left, then the step's own work.
- **Conflicts.** Resolve each file keeping both sides' intent, leave no conflict marker behind,
  `git -C <worktree> add <file>`, and conclude with `git -C <worktree> commit --no-edit`
  (`git merge --continue` needs an editor, which a step does not have). Where keeping both sides is a
  product decision, the two changes wanting opposite behaviour: `git -C <worktree> merge --abort` and
  return `halted`, quoting the conflicting hunks. After `handoff` the reason goes into the PR body as
  for any halt (§Failure policy). The owner's answer comes back as a `--decision` on the relaunch, and
  that step's brief asks for the merge again.
- **The suite.** Nothing new: a merge changes the tree, so §Suite reuse finds no green run for it and
  the step's next full run covers the merged tree. `implement` merges before its first plan step;
  `review-pr`'s finish step runs the suite after its merge. A design step's merge runs none: the branch
  has no code of its own to test.
- **The record.** When `artifacts.pr` is set, add one line per merge to the PR body, under a
  `## Base merges` heading created once: the base and its sha, the commit count, and per conflicted file
  how it was resolved (*clean* when there was none). Edit the body as §Failure policy does
  (`gh pr view --json body` into a file, append, `gh pr edit --body-file`), never blanking it. A resolve
  step also names the merge in its entry's `actions`. Before a PR exists the merge commit is the record.
  A branch without a commit of its own fast-forwards: no merge commit, nothing to record.
- **No rebase and no force-push, anywhere in a run.** §Scoped re-review treats a rewritten history as
  "review everything again".

**No boundary check verifies that a step merged.** The state is recomputed at every writing step's
brief, so a step that skipped the merge leaves the next one the same line. A merge made by `implement` or
a design step is reviewed with the rest of the diff; one made by the finish step gets its own review
round at the gate (§The CI gate, *A merge the review did not see*).

**What this does not catch.** A base that changed only files the branch does not touch is not merged,
even where the branch's code depends on them: the blind spot §Scoped re-review names, covered by CI on
the merge ref. A design grown after code exists (a plan gap) is measured by the branch's own files, not
by the files the grown plan names; the step that writes that code catches up at its next brief.

```

In §What a leg brief consists of, replace

```markdown
- **the overrides for that leg and step** from `pipeline_leg_overrides()`, pointing at the section of
  this file that holds each rule, e.g. *"leave the PR draft"* (§Who takes the PR out of draft);
```

with

```markdown
- **the overrides for that leg and step** from `pipeline_leg_overrides()`, pointing at the section of
  this file that holds each rule, e.g. *"leave the PR draft"* (§Who takes the PR out of draft); on a
  step that writes to the branch, first among them the order to merge the base when the branch fell
  behind it (§Catching up with the base);
```

- [ ] **Step 6: Run the suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, the whole suite (the four filtered tests of Step 2 included).

- [ ] **Step 7: Commit**

```bash
git add skills/pipeline/checks/brief.php skills/pipeline/checks/tests/BriefTest.php skills/pipeline/checks/tests/LockStepTest.php skills/pipeline/checks/tests/DispatchCliTest.php skills/pipeline/references/engine.md
git commit -m "feat(pipeline): a writing step's brief orders the merge of its base, first (#124)"
```

---

### Task 3: one review round at the CI gate for a merge the review did not see

**Files:**
- Modify: `skills/pipeline/checks/ci.php` (`pipeline_ci_answer()`, `pipeline_ci_rounds()`, new constant and functions)
- Modify: `skills/pipeline/checks/dispatch_cli.php` (`dispatch_cli_ci()`, new `dispatch_cli_unreviewed()`)
- Modify: `skills/pipeline/references/engine.md` (§The CI gate), `skills/pipeline/references/manifest.md` (the `decisions` row), `skills/pipeline/SKILL.md` (step 5)
- Test: `skills/pipeline/checks/tests/CiTest.php`, `skills/pipeline/checks/tests/DispatchCliTest.php`

**Interfaces:**
- Consumes, all existing: `pipeline_review_scope(array $manifest, callable $git): ?array` (its `files`: the files where a merge since the last completed review met the branch's changes); `dispatch_cli_git(array $manifest): Closure`; `PIPELINE_CI_RED`; the test helpers `ci_manifest(array $decisions = []): array`, `ci_view(array $rollup): array`, `ci_run()`, `rereview_repo()`, `rereview_commit()`, `rereview_main_moves()`, `rereview_merge(string $dir, array $files = [], string $ref = 'origin/main'): string` (returns the merge commit), `rereview_entry(?string $outcome, ?string $sha, string $at = …): array`, `dispatch_fixture()`, `dispatch_cli()`.
- Produces:
  - `const PIPELINE_MERGE_UNREVIEWED = "Unreviewed merge on the PR's head commit ";`
  - `pipeline_ci_answer(array $manifest, ?array $view, string $head, bool $workflows, int $poll, array $unreviewed = []): array`; its new answer is `['action' => 'fix', 'verdict' => 'merge', 'files' => list<string>, 'decision' => string]`.
  - `pipeline_merge_rounds(array $manifest): int`.
  - `dispatch_cli_unreviewed(array $manifest): array` (`list<string>`).

- [ ] **Step 1: Write the failing tests.** Append to `CiTest.php`:

```php
it('answers one review round for a merge the last review did not see, before the head or the checks are read', function () {
    $decision = "Unreviewed merge on the PR's head commit def456: a merge since the last completed review met this branch's changes in a.php, b.php";
    $fix = ['action' => 'fix', 'verdict' => 'merge', 'files' => ['a.php', 'b.php'], 'decision' => $decision];
    $green = ci_view([ci_run('ci', 'COMPLETED', 'SUCCESS')]);

    expect(pipeline_ci_answer(ci_manifest(), $green, 'def456', true, 1, ['a.php', 'b.php']))->toBe($fix);
    expect(pipeline_ci_answer(ci_manifest(), null, 'def456', true, 120, ['a.php', 'b.php']))->toBe($fix);
    expect(pipeline_ci_answer(ci_manifest(), $green, 'abc123', true, 1, []))->toBe(['action' => 'ready', 'verdict' => 'green', 'sha' => 'abc123']);
    expect(pipeline_ci_answer(ci_manifest([$decision]), $green, 'abc123', true, 1, ['a.php']))->toBe(['action' => 'ready', 'verdict' => 'green', 'sha' => 'abc123']);
});

it('counts the merge round and the CI fix round apart', function () {
    $merge = "Unreviewed merge on the PR's head commit def456: a merge since the last completed review met this branch's changes in a.php";
    $ci = "CI red on the PR's head commit abc123: CI / ci failed (https://github.com/acme/app/actions/runs/11/job/ci)";
    $red = ci_view([ci_run('ci', 'COMPLETED', 'FAILURE')]);

    expect(pipeline_merge_rounds(ci_manifest(['Keep the guard'])))->toBe(0);
    expect(pipeline_merge_rounds(ci_manifest([$ci])))->toBe(0);
    expect(pipeline_merge_rounds(ci_manifest([$merge])))->toBe(1);
    expect(pipeline_merge_rounds(['branch' => 'feature/x']))->toBe(0);
    expect(pipeline_ci_rounds(ci_manifest([$merge])))->toBe(0);

    expect(pipeline_ci_answer(ci_manifest([$merge]), $red, 'abc123', true, 1, ['a.php']))->toMatchArray(['action' => 'fix', 'verdict' => 'red', 'decision' => $ci]);
    expect(pipeline_ci_answer(ci_manifest([$ci]), $red, 'abc123', true, 1, ['a.php']))->toMatchArray(['action' => 'fix', 'verdict' => 'merge']);
});
```

In `DispatchCliTest.php`, insert directly above `function kickoff_issue(`:

```php
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
```

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='merge the last review did not see|merge round'`
Expected: FAIL. *answers one review round for a merge the last review did not see* on its first `toBe($fix)` (the answer is `wait` with verdict `mismatch`); *counts the merge round and the CI fix round apart* with `Call to undefined function pipeline_merge_rounds()`; *answers the merge round for an autoflow run* on its first `toBe` (the answer is `wait`, `unreadable`).

- [ ] **Step 3: The gate's answer.** In `ci.php`, below the `PIPELINE_CI_RED` constant add

```php
/** How the gate's unreviewed-merge record starts in `decisions`; one such decision is the run's merge round spent. */
const PIPELINE_MERGE_UNREVIEWED = "Unreviewed merge on the PR's head commit ";
```

Replace `pipeline_ci_answer()`'s docblock, signature and first statement

```php
/**
 * What the session does next: `wait` and read again, `ready` (`gh pr ready`), `fix` (the decision into
 * `decisions` through `launch --from review-pr --decision`), or `halt` (`finish`'s input). `$view` is
 * `gh pr view <pr> --json headRefOid,statusCheckRollup`, null when gh could not read it; `$head` the
 * worktree's `HEAD`, which GitHub's head must be before its checks count; `$workflows` whether the
 * worktree has GitHub Actions workflows; `$poll` this read's number, from 1.
 */
function pipeline_ci_answer(array $manifest, ?array $view, string $head, bool $workflows, int $poll): array
{
    $last = $poll >= PIPELINE_CI_POLLS;
```

with

```php
/**
 * What the session does next: `wait` and read again, `ready` (`gh pr ready`), `fix` (the decision into
 * `decisions` through `launch --from review-pr --decision`), or `halt` (`finish`'s input). `$view` is
 * `gh pr view <pr> --json headRefOid,statusCheckRollup`, null when gh could not read it; `$head` the
 * worktree's `HEAD`, which GitHub's head must be before its checks count; `$workflows` whether the
 * worktree has GitHub Actions workflows; `$poll` this read's number, from 1; `$unreviewed` the files where
 * a merge since the last completed review met the branch's changes, which get one review round before
 * the PR or its checks are read.
 */
function pipeline_ci_answer(array $manifest, ?array $view, string $head, bool $workflows, int $poll, array $unreviewed = []): array
{
    if ($unreviewed !== [] && pipeline_merge_rounds($manifest) === 0) {
        return pipeline_ci_unreviewed($head, $unreviewed);
    }
    $last = $poll >= PIPELINE_CI_POLLS;
```

Replace the last function of the file

```php
/** The fix rounds this run has had: the decisions the gate's failure record starts. */
function pipeline_ci_rounds(array $manifest): int
{
    return count(array_filter($manifest['decisions'] ?? [], fn (string $decision) => str_starts_with($decision, PIPELINE_CI_RED)));
}
```

with

```php
/** A merge the last review did not see is a `fix` of its own: a scoped `review-pr` round, once per run. */
function pipeline_ci_unreviewed(string $head, array $files): array
{
    return [
        'action' => 'fix',
        'verdict' => 'merge',
        'files' => $files,
        'decision' => PIPELINE_MERGE_UNREVIEWED . "{$head}: a merge since the last completed review met this branch's changes in " . implode(', ', $files),
    ];
}

/** The fix rounds this run has had: the decisions the gate's failure record starts. */
function pipeline_ci_rounds(array $manifest): int
{
    return pipeline_decisions_starting($manifest, PIPELINE_CI_RED);
}

/** The merge rounds this run has had: the decisions the gate's unreviewed-merge record starts. */
function pipeline_merge_rounds(array $manifest): int
{
    return pipeline_decisions_starting($manifest, PIPELINE_MERGE_UNREVIEWED);
}

function pipeline_decisions_starting(array $manifest, string $prefix): int
{
    return count(array_filter($manifest['decisions'] ?? [], fn (string $decision) => str_starts_with($decision, $prefix)));
}
```

- [ ] **Step 4: The CLI hands the files in.** In `dispatch_cli.php`, `dispatch_cli_ci()`, replace

```php
        glob("{$worktree}/.github/workflows/*.y*ml") !== [],
        $poll,
    );
}
```

with

```php
        glob("{$worktree}/.github/workflows/*.y*ml") !== [],
        $poll,
        dispatch_cli_unreviewed($manifest),
    );
}

/**
 * The files where a merge since the last completed review met the branch's changes (`../references/engine.md`
 * §The CI gate). `autoflow` only: there `finish` closed that review before the gate runs; an `interactive`
 * finish step runs the gate while its own review entry is still open.
 */
function dispatch_cli_unreviewed(array $manifest): array
{
    return $manifest['mode'] === 'autoflow' ? pipeline_review_scope($manifest, dispatch_cli_git($manifest))['files'] ?? [] : [];
}
```

and in the docblock above `dispatch_cli_ci()` replace

```php
 * The CI gate's reads (`../references/engine.md` §The CI gate): the worktree's `HEAD`, then the PR's head
 * commit and its checks in one gh call, and what the session does next. It never writes the manifest, so
 * polling it changes nothing.
```

with

```php
 * The CI gate's reads (`../references/engine.md` §The CI gate): the worktree's `HEAD`, then the PR's head
 * commit and its checks in one gh call, the merges since the last completed review in an `autoflow` run,
 * and what the session does next. It never writes the manifest, so polling it changes nothing.
```

- [ ] **Step 5: Run them to see them pass**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='merge the last review did not see|merge round'`
Expected: PASS.

- [ ] **Step 6: engine.md §The CI gate.** In the table, above the row that starts ``| `mismatch`: GitHub's head is not the worktree's `HEAD` |``, insert

```markdown
| `merge`: a merge since the last completed review met the branch's changes (`autoflow`) | `fix` the first time in a run, before the PR is read; after that round the gate goes on to the rows below |
```

Then replace

```markdown
- **In `interactive`** the finish step runs the same loop, `gh pr ready` on `ready`, and shows any other
  answer to the human; there is no automatic round.
```

with

```markdown
- **A merge the review did not see** (#124). The finish step is a run's last, so a merge it makes
  (§Catching up with the base), conflict resolutions included, would reach a ready PR unreviewed. In
  `autoflow`, `ci` computes `pipeline_review_scope()` and hands its `files` to the gate: after `finish`
  recorded `done` the scope's base is the review that just completed, so the files are exactly what a
  merge since that review met. When there are any, the gate answers `fix` with verdict `merge`, the files
  and the decision `Unreviewed merge on the PR's head commit <sha>: a merge since the last completed
  review met this branch's changes in <files>` (`<sha>` is the worktree's `HEAD`), before it reads the PR
  or its checks. The session does what it does for a red: the same `launch --from review-pr --decision
  "<its decision>"` and a new workflow. That review is scoped, reads those files whole (§Scoped
  re-review), and records a `reviewed_sha` that contains the merge, so the gate's next read finds nothing
  unreviewed. Once per run, counted as the CI round is and apart from it: a run may have one of each. A
  further unreviewed merge neither halts nor loops; the gate goes on to CI, and the PR body's
  `## Base merges` line is its record. The round fires on a clean merge of a shared file as on a
  conflict: git 2.33 cannot tell the two apart afterwards, and a textual merge of a file both sides
  changed is what a review is for.
- **In `interactive`** the finish step runs the same loop, `gh pr ready` on `ready`, and shows any other
  answer to the human; there is no automatic round, and no merge round: the human resolves the review
  and sees the merge as it is made.
```

- [ ] **Step 7: manifest.md and SKILL.md.** In `skills/pipeline/references/manifest.md`, the `decisions` row, replace

```markdown
an owner's request on a ready PR, or the CI gate's failure record (`engine.md` §The CI gate). Every brief carries them
```

with

```markdown
an owner's request on a ready PR, or one of the CI gate's two records, a red CI or an unreviewed merge (`engine.md` §The CI gate). `orchestrate` adds its sibling note at kickoff, marked as a note and not an owner decision (`engine.md` §Catching up with the base). Every brief carries them
```

In `skills/pipeline/SKILL.md`, step 5, replace

```markdown
   `gh pr ready` by hand. **`fix`:** the diff as in step 2, then
```

with

```markdown
   `gh pr ready` by hand. **`fix`** (a red CI, or a merge the last review did not see): the diff as in step 2, then
```

- [ ] **Step 8: Run the suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, the whole suite. The existing `ci` tests keep their answers: their manifests have no completed review, so `pipeline_review_scope()` returns null before it asks git.

- [ ] **Step 9: Commit**

```bash
git add skills/pipeline/checks/ci.php skills/pipeline/checks/dispatch_cli.php skills/pipeline/checks/tests/CiTest.php skills/pipeline/checks/tests/DispatchCliTest.php skills/pipeline/references/engine.md skills/pipeline/references/manifest.md skills/pipeline/SKILL.md
git commit -m "feat(pipeline): the CI gate sends a merge the review did not see back for one review round (#124)"
```

---

### Task 4: the exception in `CLAUDE.md`, the allow rules, and orchestrate's sibling note

**Files:**
- Modify: `CLAUDE.md` (§Git Workflow, *Never work against a stale checkout*)
- Modify: `README.md` (after the `git-freshness.sh` modes list)
- Modify: `skills/pipeline/SKILL.md` (step 3)
- Modify: `skills/orchestrate/references/commands.md` (§Launch), `skills/orchestrate/SKILL.md` (step 3)
- Test: none of its own; `LockStepTest.php` covers none of these lines (it reads `manifest.md` §What a leg writes, `gates.md` §Loop-backs, `engine.md`'s headings and agents table, and the workflow script). The suite runs once at the end to show nothing else moved.

**Interfaces:**
- Consumes: the command forms Task 2 pinned in `pipeline_catch_up_line()`: `git -C <worktree> merge --no-edit origin/<base>`, `git -C <worktree> merge --abort`, `git -C <worktree> commit --no-edit`.
- Produces: nothing a later task calls.

- [ ] **Step 1: `CLAUDE.md`.** Replace

```markdown
  `git status` cannot see this (it only compares against the tracking branch), and `origin/HEAD` is
  often stale: run `git remote set-head origin --auto` before trusting it.
```

with

```markdown
  `git status` cannot see this (it only compares against the tracking branch), and `origin/HEAD` is
  often stale: run `git remote set-head origin --auto` before trusting it.

  **One exception: a `/pipeline` run's own branch.** A step of a run merges the base into the run's branch
  when its brief says so, with `git -C <worktree> merge --no-edit origin/<base>`, and resolves the
  conflicts itself (pipeline `engine.md` §Catching up with the base): never a rebase, never a force-push.
  There the brief answers the hook's warning. Every other checkout keeps raise-and-wait.
```

- [ ] **Step 2: `README.md`.** Replace

```markdown
- `checkout` — drops cached verdicts after a branch switch.
```

with

````markdown
- `checkout` — drops cached verdicts after a branch switch.

### Permissions for unattended runs

A `/pipeline` run merges its base into its own branch when its brief says so (pipeline `engine.md`
§Catching up with the base). A denial in a background step is final, so allow the three commands in
`~/.claude/settings.json`: user level, so they reach every project on the machine, and a per-machine
step like the hooks:

```json
"permissions": { "allow": [
  "Bash(git -C * merge --no-edit origin/*)",
  "Bash(git -C * merge --abort)",
  "Bash(git -C * commit --no-edit)"
] }
```

The form matters: a rule matches the command as typed, so a project's `Bash(git merge:*)` does not cover
`git -C <worktree> merge`, and a chained command is matched part by part. The brief prints these
commands in exactly this form and tells the step to run each as its own command. Whether an allow rule
also keeps the auto-mode classifier from denying the merge is assumed, not measured: the first batch
after this lands shows it, and a denial still halts the step with the command named.
````

- [ ] **Step 3: pipeline `SKILL.md`, step 3.** Replace

```markdown
   for its completion notice. Starting it from this skill is the owner's opt-in; unattended runs need
   auto permission mode or allow rules for `git push`, `gh` and `docker`.
```

with

```markdown
   for its completion notice. Starting it from this skill is the owner's opt-in; unattended runs need
   auto permission mode or allow rules for `git push`, `gh` and `docker`, and the allow rules for the
   merge of the base in its `git -C <worktree>` form (`README.md`, *Permissions for unattended runs*).
```

- [ ] **Step 4: orchestrate commands §Launch.** In `skills/orchestrate/references/commands.md`, replace

```markdown
  N); a halt: report it and start nothing. Never create the worktree another way or switch
  branches in the primary checkout; other runs share it.
```

with

```markdown
  N); a halt: report it and start nothing. Never create the worktree another way or switch
  branches in the primary checkout; other runs share it.
- **The sibling note.** Before kickoff, list the batch's other runs in flight from §Map's open PRs,
  and per PR its files:
  `gh pr view <P> -R <repo> --json files --jq '[.files[].path] | join(", ")'`.
  With at least one, kickoff gets one more `--decision`, in this form:
  `Sibling runs in flight in this batch (orchestrate's note, not an owner decision): #<P> (issue #<M>) changes <files>; issue #<K> has no PR yet. A plan that changes these files expects a merge of the base (pipeline engine.md §Catching up with the base).`
  A sibling still in design shows only its spec and plan; merged siblings are not listed, since the run
  is cut from a base that holds them. The note holds nothing back and orders nothing: the run merges
  its base when its brief says so.
```

- [ ] **Step 5: orchestrate `SKILL.md`, step 3.** Replace

```markdown
3. **Dispatch.** From the primary checkout, pipeline `SKILL.md` §`autoflow` — how a run starts and ends steps 1–3, the workflow `pipeline-autoflow` in the background (commands §Launch); the worktree travels in `launch`'s args and the step briefs, never in this session's directory.
```

with

```markdown
3. **Dispatch.** From the primary checkout, pipeline `SKILL.md` §`autoflow` — how a run starts and ends steps 1–3, the workflow `pipeline-autoflow` in the background (commands §Launch); the worktree travels in `launch`'s args and the step briefs, never in this session's directory. Kickoff carries the sibling note when other runs of the batch are in flight (commands §Launch).
```

- [ ] **Step 6: Check the texts agree with the code.** Each of these prints at least one line:

```bash
grep -n "merge --no-edit origin/" CLAUDE.md README.md skills/pipeline/checks/brief.php skills/pipeline/references/engine.md
grep -n "Catching up with the base" CLAUDE.md README.md skills/pipeline/references/engine.md skills/pipeline/references/manifest.md skills/orchestrate/references/commands.md
grep -n "rebase\|force-push" CLAUDE.md skills/pipeline/checks/brief.php | grep -v "never\|Never\|on your own initiative"
```

Expected: the first two print a line per file named; the third prints nothing (every mention of a rebase or a force-push forbids it: the stale-checkout rule's own *"rebase or merge on your own initiative"*, the exception's *"never a rebase, never a force-push"*, and the brief line's *"Never rebase, never force-push"*).

- [ ] **Step 7: Run the suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, the whole suite.

- [ ] **Step 8: Commit**

```bash
git add CLAUDE.md README.md skills/pipeline/SKILL.md skills/orchestrate/SKILL.md skills/orchestrate/references/commands.md
git commit -m "docs: a pipeline run's branch is the exception to the stale-checkout rule, with its allow rules and orchestrate's sibling note (#124)"
```

---

## Self-review against the spec

| Spec section | Task |
|---|---|
| The base state, `pipeline_base_ref()` shared with `pipeline_review_scope()` | 1 |
| Which steps catch up (`PIPELINE_CATCH_UP_STEPS`), both modes | 1 (constant), 2 (brief, `next`) |
| The brief line, first override, literal command, design-only wording | 2 |
| What the step does and records (engine.md §Catching up with the base) | 2 |
| A merge the review did not see: one round at the CI gate, `autoflow` only, once per run | 3 |
| `CLAUDE.md` exception | 4 |
| Permissions: README allow rules, pipeline `SKILL.md` step 3 | 4 |
| `orchestrate` sibling note, `manifest.md` `decisions` row | 4, 3 |
| Tests: `BaseStateTest`, `BriefTest`, `ReviewScopeTest` unchanged, `CiTest`, `DispatchCliTest`, lock-step | 1, 2, 3 |
| *Assumptions* 14–19 (asked by this plan) | 1 (14, 19), 2 (15, 17, 18), 3 (16) |
