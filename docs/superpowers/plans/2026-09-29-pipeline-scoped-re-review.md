# A re-review of the PR reads what changed since the last completed review Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task by task. Steps use checkbox (`- [ ]`) syntax for tracking. In an `autoflow` implement step, execute inline, task by task, with no subagents.

**Goal:** `review-pr`'s review step records the commit it reviewed (`reviewed_sha`), the resolve step cannot change it, and a later `review-pr` review whose newest completed review is an ancestor of HEAD gets a brief scoped to the branch's own commits since plus the files where a merge since met the branch's changes; anything else stays a full review.

**Architecture:**
- `skills/pipeline/checks/pipeline.php`: `pipeline_reset_at()` extracted from `pipeline_done_legs()`.
- `skills/pipeline/checks/dispatch.php`: `reviewed_sha` joins `$kept`; `pipeline_review_entry_problem()` requires it on a `pr-review` review entry.
- `skills/pipeline/checks/brief.php`: the entry line asks for it; `pipeline_review_base()`, `pipeline_review_scope()`, `pipeline_git_lines()`, `pipeline_meeting_files()`, `pipeline_merge_files()`, `pipeline_review_scope_line()`; `pipeline_brief()` takes an optional `callable $git`.
- `skills/pipeline/checks/dispatch_cli.php`: `dispatch_cli_git()`; `dispatch_cli_emit()` and `dispatch_cli_brief()` pass it.
- Docs: engine.md (§Scoped re-review, new; §What a leg brief consists of; §`autoflow`'s `launch` bullet), manifest.md (`reviewed_sha`).

**Tech Stack:** PHP 8.4 on the host, Pest 4, git 2.33+ (throwaway repos in the tests), Markdown skill references.

**Spec:** `docs/superpowers/specs/2026-09-29-pipeline-scoped-re-review-design.md`

## Global Constraints

- Suite, from the worktree root on the host: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`. `vendor/` is missing in a fresh worktree: run `composer install --no-interaction --quiet` first; it must be a real directory, never a symlink to the primary's.
- The missing-sha halt reason, verbatim: `the review-pr review step must record reviewed_sha, the HEAD it reviewed (`git rev-parse HEAD`), on its entry`.
- `reviewed_sha` is valid when it is a string matching `/^[0-9a-f]{40}$/`.
- Every git call the scope makes that exits non-zero makes the scope null (a full review). `pipeline_brief()` asks git nothing unless `$git` is given and the step is `review-pr:review`.
- `pipeline-autoflow.js`, `launch`, `finish`, the CI gate, `run_audit.php` and the `critique` skill do not change.
- This repo has no `.changelog/` and no `CHANGELOG.md`: no changelog entry.

## Review Focus

1. **The scope is never narrower than git proves.** Own commits exclude only what is on `<base>` (`^<base>`, not `--first-parent`: Task 3's pull case); merge files are the intersection of both sides plus `diff-tree -c` (Task 3's clean, conflict, one-side-taken and edited-in-merge files); every git failure is a full review (Task 3's fallbacks).
2. **The base** (spec, *The base*): newest `continued` entry with a sha, newer than the latest escalation or plan gap; never a halted, looped-back or open one. Pinned in Task 3.
3. **The issue's Done-when through the real commands**: `launch --from review-pr`, then `brief review-pr review`, scoped with an ancestor base and full without one. Pinned in Task 4.
4. **Crafted context**: the scoped brief names the sha, never the base entry or its text. Pinned in Task 4.
5. **The enforcement**: a `review-pr` review entry without a 40-hex `reviewed_sha` halts; the resolve step cannot add, change or remove one. Pinned in Task 2.

---

## File Structure

- Modify `skills/pipeline/checks/pipeline.php`, `dispatch.php`, `brief.php`, `dispatch_cli.php`.
- Create test `skills/pipeline/checks/tests/ReviewScopeTest.php`.
- Modify tests `DoneLegsTest.php`, `ReturnedTest.php`, `BriefTest.php`, `DispatchCliTest.php`, `AutoflowScriptTest.php`, `LockStepTest.php` (all under `skills/pipeline/checks/tests/`).
- Modify docs `skills/pipeline/references/engine.md`, `skills/pipeline/references/manifest.md`.

---

### Task 1: `pipeline_reset_at()` — the cut `pipeline_done_legs()` makes, shared

**Files:**
- Modify: `skills/pipeline/checks/pipeline.php` (`pipeline_done_legs()`, ~66–86)
- Test: `skills/pipeline/checks/tests/DoneLegsTest.php`

**Interfaces:**
- Consumes: `pipeline_is_plan_gap(array $entry): bool`.
- Produces: `pipeline_reset_at(array $ledger): string`.

- [ ] **Step 1: Write the failing test.** Append to `DoneLegsTest.php`:

```php
it('names the newest escalation or plan gap as the point gate passes stop counting from', function () {
    $passed = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'at' => '2026-09-14T10:00:00Z', 'outcome' => 'continued'];
    $escalated = ['gate' => 'design-size', 'leg' => 'implement', 'at' => '2026-09-14T10:40:00Z', 'reason' => 'migration', 'outcome' => 'escalated'];
    $gap = ['gate' => 'plan-approval', 'leg' => 'implement', 'at' => '2026-09-14T11:40:00Z', 'reason' => 'needs a queue', 'outcome' => 'looped-back'];

    expect(pipeline_reset_at([]))->toBe('');
    expect(pipeline_reset_at([$passed]))->toBe('');
    expect(pipeline_reset_at([$passed, $escalated]))->toBe('2026-09-14T10:40:00Z');
    expect(pipeline_reset_at([$passed, $escalated, $gap]))->toBe('2026-09-14T11:40:00Z');
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter=DoneLegsTest`
Expected: FAIL, `Call to undefined function pipeline_reset_at()`.

- [ ] **Step 3: Implement.** In `pipeline.php`, add before `pipeline_done_legs()`:

```php
/** The newest `at` of a `design-size` escalation or a plan gap, `''` without one: gate passes before it no longer count (`../references/engine.md` §Design size, §Scoped re-review). */
function pipeline_reset_at(array $ledger): string
{
    $resetAt = array_column(
        array_filter($ledger, fn (array $entry) => ($entry['outcome'] ?? null) === 'escalated' || pipeline_is_plan_gap($entry)),
        'at',
    );

    return $resetAt === [] ? '' : max($resetAt);
}
```

and in `pipeline_done_legs()` replace the `$resetAt = array_column(...)` statement and the `$since = ...` line after it with:

```php
    $since = pipeline_reset_at($ledger);
```

- [ ] **Step 4: Run the tests**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter=DoneLegsTest`
Expected: PASS (the new test and every existing `pipeline_done_legs()` case).

- [ ] **Step 5: Commit** — `refactor(pipeline): pipeline_reset_at() names the cut pipeline_done_legs() makes (#88)`

---

### Task 2: the review step records `reviewed_sha`, and the resolve step keeps it

**Files:**
- Modify: `skills/pipeline/checks/brief.php` (`pipeline_leg_overrides()`, the `review-pr:review` entry line, ~64)
- Modify: `skills/pipeline/checks/dispatch.php` (`pipeline_ledger_problem()` ~247–281, a new function after it)
- Test: `skills/pipeline/checks/tests/ReturnedTest.php`, `BriefTest.php`, `AutoflowScriptTest.php`

**Interfaces:**
- Consumes: `pipeline_is_open()`, `pipeline_entry_change()`, `pipeline_halt()`, the test helpers `returned_before()`, `returned_after()`, `brief_manifest()`.
- Produces: `pipeline_review_entry_problem(array $added, string $gate): ?string`.

- [ ] **Step 1: Write the failing tests.** Append to `ReturnedTest.php`:

```php
it('makes a review-pr review step record the 40-character sha it reviewed, and a review-plan one nothing', function () use ($noUi, $open) {
    $prOpen = [...$open, 'gate' => 'pr-review', 'leg' => 'review-pr'];
    $before = returned_before('review-pr');
    $returns = fn (array $entry) => pipeline_returned($before, returned_after($before, 'continued', [$entry]), $noUi, DesignSize::Architectural);
    $missing = pipeline_halt('the review-pr review step must record reviewed_sha, the HEAD it reviewed (`git rev-parse HEAD`), on its entry');

    expect($returns($prOpen))->toBe($missing);
    expect($returns([...$prOpen, 'reviewed_sha' => 'abc1234']))->toBe($missing);
    expect($returns([...$prOpen, 'reviewed_sha' => str_repeat('a1', 20)]))->toBe(['action' => 'dispatch', 'leg' => 'review-pr']);

    $planBefore = returned_before('review-plan');
    expect(pipeline_returned($planBefore, returned_after($planBefore, 'continued', [$open]), $noUi, DesignSize::Architectural))
        ->toBe(['action' => 'dispatch', 'leg' => 'review-plan']);
});

it('halts a resolve step that adds, changes or removes reviewed_sha on the open entry', function () use ($noUi) {
    $entry = ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => '2026-09-25T10:00:00Z', 'review' => 'r'];
    [$sha, $other] = [str_repeat('a1', 20), str_repeat('b2', 20)];
    $resolve = function (array $open, array $left) use ($noUi) {
        $before = returned_before('review-pr', [$open]);

        return pipeline_returned($before, returned_after($before, 'continued', [[...$left, 'actions' => [], 'outcome' => 'continued']]), $noUi, DesignSize::Architectural);
    };

    expect($resolve([...$entry, 'reviewed_sha' => $sha], [...$entry, 'reviewed_sha' => $other]))->toBe(pipeline_halt('review-pr changed reviewed_sha on ledger entry 0 (pr-review)'));
    expect($resolve($entry, [...$entry, 'reviewed_sha' => $sha]))->toBe(pipeline_halt('review-pr added reviewed_sha to ledger entry 0 (pr-review)'));
    expect($resolve([...$entry, 'reviewed_sha' => $sha], $entry))->toBe(pipeline_halt('review-pr removed reviewed_sha from ledger entry 0 (pr-review)'));
    expect($resolve([...$entry, 'reviewed_sha' => $sha], [...$entry, 'reviewed_sha' => $sha]))->toBe(['action' => 'done']);
});
```

Append to `BriefTest.php`:

```php
it('has the review-pr review step record the commit it reviewed, and the review-plan one not', function (string $mode) {
    expect(pipeline_brief(brief_manifest('review-pr', ['mode' => $mode]), 'review-pr', '/tmp/m.json', 'review'))
        ->toContain('`review`, `annotations` and `reviewed_sha` (the output of `git rev-parse HEAD` in the worktree: the commit you reviewed), and no `outcome`');
    expect(pipeline_brief(brief_manifest('review-plan', ['mode' => $mode]), 'review-plan', '/tmp/m.json', 'review'))
        ->not->toContain('reviewed_sha');
})->with(['autoflow', 'interactive']);
```

In `AutoflowScriptTest.php`, the smoke pass (*walks stub steps that write what their briefs ask to done…*), give the stub review step's entry the sha its brief now asks for:

```php
    $pr = [...$plan, 'gate' => 'pr-review', 'leg' => 'review-pr', 'at' => '2026-09-25T12:00:00Z', 'reviewed_sha' => str_repeat('c', 40)];
```

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='ReturnedTest|BriefTest|AutoflowScriptTest'`
Expected: FAIL: the review-pr case continues instead of halting on a missing sha; the resolve cases answer `done` instead of halting (`reviewed_sha` is not kept); the brief line lacks `reviewed_sha`. `AutoflowScriptTest` passes (the extra key is not checked yet).

- [ ] **Step 3: Implement.** In `brief.php`, `pipeline_leg_overrides()`, the `review-pr:review` entry line becomes:

```php
            'Append its review verbatim as a new `pr-review` ledger entry with `gate`, `leg`, `cycle`, `at`, `review`, `annotations` and `reviewed_sha` (the output of `git rev-parse HEAD` in the worktree: the commit you reviewed), and no `outcome`.',
```

(`review-plan:review`'s line stays as it is.) In `dispatch.php`, `pipeline_ledger_problem()`:

```php
    $kept = ['gate', 'leg', 'cycle', 'at', 'review', 'annotations', 'reviewed_sha'];
```

and replace the `$step === 'review' => …` arm with:

```php
        $step === 'review' => pipeline_review_entry_problem($addedTo($gate), $gate),
```

Add after `pipeline_ledger_problem()`:

```php
/** A review step adds one open entry; on `pr-review` it records the commit it reviewed (`../references/engine.md` §Scoped re-review). */
function pipeline_review_entry_problem(array $added, string $gate): ?string
{
    $sha = $added[0]['reviewed_sha'] ?? null;

    return match (true) {
        count($added) !== 1 || ! pipeline_is_open($added[0]) => "the review step must add exactly one open {$gate} entry",
        $gate === 'pr-review' && ! (is_string($sha) && preg_match('/^[0-9a-f]{40}$/', $sha) === 1) => 'the review-pr review step must record reviewed_sha, the HEAD it reviewed (`git rev-parse HEAD`), on its entry',
        default => null,
    };
}
```

- [ ] **Step 4: Run the tests and the suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, 0 failed. (Without the `AutoflowScriptTest` fixture change the smoke pass would now halt at the brief after `review-pr:review` with the missing-sha reason.)

- [ ] **Step 5: Commit** — `feat(pipeline): review-pr's review step records reviewed_sha, and the resolve step cannot change it (#88)`

---

### Task 3: the base and the scope

**Files:**
- Modify: `skills/pipeline/checks/brief.php` (new functions after `pipeline_design_grows()`)
- Create test: `skills/pipeline/checks/tests/ReviewScopeTest.php`

**Interfaces:**
- Consumes: `pipeline_reset_at()` (Task 1), `pipeline_ledger()`, `pipeline_git_run()` / `pipeline_git()` (`suite.php`; `pipeline_git()` throws on a non-zero exit), the test helper `suite_repo()` (`SuiteTest.php`: a repo with one commit, `app.php`, user and `commit.gpgsign` configured).
- Produces: `pipeline_review_base(array $ledger): ?string`; `pipeline_review_scope(array $manifest, callable $git): ?array` returning `array{since: string, base: string, commits: int, files: list<string>}|null`; `pipeline_git_lines(callable $git, array $args): ?array`; `pipeline_meeting_files(array $merges, callable $git): ?array`; `pipeline_merge_files(string $merge, callable $git): ?array`; the test helpers `rereview_repo()`, `rereview_publish()`, `rereview_commit()`, `rereview_main_moves()`, `rereview_merge()`, `rereview_entry()`, `rereview_manifest()`, `rereview_scope()` (used again in Task 4).

- [ ] **Step 1: Write the failing tests.** Create `ReviewScopeTest.php`:

```php
<?php

/** A `feature` branch cut from `main`, which holds `$files`; `origin/main` and `origin/HEAD` as a clone has them. */
function rereview_repo(array $files = []): string
{
    $dir = suite_repo();
    pipeline_git($dir, ['branch', '-M', 'main']);
    if ($files !== []) {
        rereview_commit($dir, $files, 'base');
    }
    rereview_publish($dir);
    pipeline_git($dir, ['symbolic-ref', 'refs/remotes/origin/HEAD', 'refs/remotes/origin/main']);
    pipeline_git($dir, ['switch', '-q', '-c', 'feature']);

    return $dir;
}

/** `origin/main` catches up with the local `main`. */
function rereview_publish(string $dir): void
{
    pipeline_git($dir, ['update-ref', 'refs/remotes/origin/main', 'main']);
}

/** Write the files, commit them on the current branch, and return the new HEAD. */
function rereview_commit(string $dir, array $files, string $message = 'work'): string
{
    foreach ($files as $path => $content) {
        file_put_contents("{$dir}/{$path}", $content);
    }
    pipeline_git($dir, ['add', '-A']);
    pipeline_git($dir, ['commit', '-q', '-m', $message]);

    return pipeline_git($dir, ['rev-parse', 'HEAD']);
}

/** Main moves on and is published; the checkout goes back to `feature`. */
function rereview_main_moves(string $dir, array $files): void
{
    pipeline_git($dir, ['switch', '-q', 'main']);
    rereview_commit($dir, $files, 'main work');
    rereview_publish($dir);
    pipeline_git($dir, ['switch', '-q', 'feature']);
}

/** Merge `$ref` into `feature`; `$files` land in the merge commit, resolving a conflict or editing beside it. */
function rereview_merge(string $dir, array $files = [], string $ref = 'origin/main'): string
{
    pipeline_git_run($dir, ['merge', '-q', '--no-ff', '--no-commit', $ref]); // exits 1 on a conflict

    return rereview_commit($dir, $files, "merge {$ref}");
}

/** A `pr-review` entry; a null outcome is an open entry, a null sha none recorded. */
function rereview_entry(?string $outcome, ?string $sha, string $at = '2026-09-25T12:00:00Z'): array
{
    return array_filter([
        'gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => $at, 'review' => 'EARLIER REVIEW TEXT', 'annotations' => [],
        'reviewed_sha' => $sha, 'outcome' => $outcome,
    ], fn ($value) => $value !== null);
}

/** A run on `$dir` whose ledger holds one completed review of the PR at `$sha`. */
function rereview_manifest(string $dir, string $sha, array $extra = []): array
{
    return [
        'branch' => 'feature', 'worktree' => $dir, 'mode' => 'autoflow',
        'cursor' => ['leg' => 'review-pr', 'status' => 'pending'],
        'gate_ledger' => [rereview_entry('continued', $sha)],
        ...$extra,
    ];
}

function rereview_scope(string $dir, array $manifest): ?array
{
    return pipeline_review_scope($manifest, fn (array $args) => pipeline_git_run($dir, $args));
}

it('bases a re-review on the newest completed review of the PR that recorded its commit', function () {
    [$a, $b, $c] = [str_repeat('a', 40), str_repeat('b', 40), str_repeat('c', 40)];
    $planPass = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-25T13:00:00Z', 'reviewed_sha' => $b, 'outcome' => 'continued'];

    expect(pipeline_review_base([]))->toBeNull();
    expect(pipeline_review_base([rereview_entry('continued', $a)]))->toBe($a);
    expect(pipeline_review_base([rereview_entry('continued', $a), rereview_entry('continued', $b, '2026-09-25T13:00:00Z')]))->toBe($b);
    expect(pipeline_review_base([rereview_entry('continued', $a), rereview_entry('halted', $b), rereview_entry('looped-back', $c), rereview_entry(null, $c)]))->toBe($a);
    expect(pipeline_review_base([rereview_entry('continued', $a), rereview_entry('continued', null)]))->toBe($a);
    expect(pipeline_review_base([rereview_entry('halted', $a), $planPass]))->toBeNull();
});

it('does not base a re-review on a review older than the latest escalation or plan gap', function () {
    $sha = str_repeat('a', 40);
    $gap = ['gate' => 'plan-approval', 'leg' => 'review-pr', 'cycle' => 2, 'at' => '2026-09-25T13:00:00Z', 'reason' => 'needs a queue', 'outcome' => 'looped-back'];
    $escalated = ['gate' => 'design-size', 'leg' => 'review-pr', 'at' => '2026-09-25T13:00:00Z', 'reason' => 'migration', 'outcome' => 'escalated'];

    expect(pipeline_review_base([rereview_entry('continued', $sha), $gap]))->toBeNull();
    expect(pipeline_review_base([rereview_entry('continued', $sha), $escalated]))->toBeNull();
    expect(pipeline_review_base([rereview_entry('continued', $sha), $gap, rereview_entry('continued', $sha, '2026-09-25T14:00:00Z')]))->toBe($sha);
});

it('scopes to the branch\'s own commits since the review, leaving out what main brought in', function () {
    $dir = rereview_repo();
    $reviewed = rereview_commit($dir, ['feature.php' => "<?php // v1\n"]);
    rereview_main_moves($dir, ['main.php' => "<?php\n"]);
    rereview_commit($dir, ['feature.php' => "<?php // v2\n"]);
    rereview_merge($dir);
    rereview_commit($dir, ['more.php' => "<?php\n"]);

    expect(rereview_scope($dir, rereview_manifest($dir, $reviewed)))
        ->toBe(['since' => $reviewed, 'base' => 'origin/main', 'commits' => 2, 'files' => []]);
});

it('lists the files where a merge since the review met the branch: merged clean, conflicted, one side taken, or edited in the merge', function () {
    $lines = "a\nb\nc\nd\ne\nf\ng\n";
    $dir = rereview_repo(['clean.php' => $lines, 'conflict.php' => "x\n", 'ours.php' => "x\n", 'edited.php' => "x\n", 'main-only.php' => "x\n", 'branch-only.php' => "x\n"]);
    $reviewed = rereview_commit($dir, ['clean.php' => str_replace('a', 'A', $lines), 'conflict.php' => "feature\n", 'ours.php' => "feature\n", 'branch-only.php' => "feature\n"]);
    rereview_main_moves($dir, ['clean.php' => str_replace('g', 'G', $lines), 'conflict.php' => "main\n", 'ours.php' => "main\n", 'main-only.php' => "main\n"]);
    rereview_merge($dir, ['conflict.php' => "resolved\n", 'ours.php' => "feature\n", 'edited.php' => "edited in the merge\n"]);

    expect(rereview_scope($dir, rereview_manifest($dir, $reviewed)))
        ->toBe(['since' => $reviewed, 'base' => 'origin/main', 'commits' => 0, 'files' => ['clean.php', 'conflict.php', 'edited.php', 'ours.php']]);
});

it('counts a merged-in side\'s own commits that are not on main, as after a pull of the PR branch', function () {
    $dir = rereview_repo();
    $reviewed = rereview_commit($dir, ['feature.php' => "<?php\n"]);
    pipeline_git($dir, ['branch', 'pushed-elsewhere']);
    rereview_commit($dir, ['local.php' => "<?php\n"]);
    pipeline_git($dir, ['switch', '-q', 'pushed-elsewhere']);
    rereview_commit($dir, ['web-edit.php' => "<?php\n"]);
    pipeline_git($dir, ['switch', '-q', 'feature']);
    rereview_merge($dir, [], 'pushed-elsewhere');

    expect(rereview_scope($dir, rereview_manifest($dir, $reviewed)))
        ->toBe(['since' => $reviewed, 'base' => 'origin/main', 'commits' => 2, 'files' => []]);
});

it('scopes to nothing when the branch has not moved since the review', function () {
    $dir = rereview_repo();
    $reviewed = rereview_commit($dir, ['feature.php' => "<?php\n"]);

    expect(rereview_scope($dir, rereview_manifest($dir, $reviewed)))
        ->toBe(['since' => $reviewed, 'base' => 'origin/main', 'commits' => 0, 'files' => []]);
});

it('diffs against the run\'s base when it has one, and against origin/HEAD otherwise', function () {
    $dir = rereview_repo();
    $reviewed = rereview_commit($dir, ['feature.php' => "<?php\n"]);
    rereview_commit($dir, ['more.php' => "<?php\n"]);
    pipeline_git($dir, ['update-ref', 'refs/remotes/origin/integration', 'HEAD']);

    expect(rereview_scope($dir, rereview_manifest($dir, $reviewed, ['base' => 'integration'])))
        ->toBe(['since' => $reviewed, 'base' => 'origin/integration', 'commits' => 0, 'files' => []]);
    expect(rereview_scope($dir, rereview_manifest($dir, $reviewed)))
        ->toBe(['since' => $reviewed, 'base' => 'origin/main', 'commits' => 1, 'files' => []]);
});

it('reviews the whole PR without a completed review, on a sha HEAD does not contain or git does not know, and on a base it cannot resolve', function () {
    $dir = rereview_repo();
    $reviewed = rereview_commit($dir, ['feature.php' => "<?php\n"]);
    pipeline_git($dir, ['switch', '-q', '-c', 'elsewhere', 'main']);
    $elsewhere = rereview_commit($dir, ['other.php' => "<?php\n"]);
    pipeline_git($dir, ['switch', '-q', 'feature']);

    expect(rereview_scope($dir, rereview_manifest($dir, $reviewed, ['gate_ledger' => [rereview_entry('halted', $reviewed)]])))->toBeNull();
    expect(rereview_scope($dir, rereview_manifest($dir, $elsewhere)))->toBeNull();
    expect(rereview_scope($dir, rereview_manifest($dir, str_repeat('0', 40))))->toBeNull();
    expect(rereview_scope($dir, rereview_manifest($dir, $reviewed, ['base' => 'gone'])))->toBeNull();
    expect(rereview_scope($dir . '/missing', rereview_manifest($dir, $reviewed)))->toBeNull();
    pipeline_git($dir, ['symbolic-ref', '--delete', 'refs/remotes/origin/HEAD']);
    expect(rereview_scope($dir, rereview_manifest($dir, $reviewed)))->toBeNull();
});
```

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter=ReviewScopeTest`
Expected: FAIL, `Call to undefined function pipeline_review_base()` / `pipeline_review_scope()`.

- [ ] **Step 3: Implement.** In `brief.php`, after `pipeline_design_grows()`:

```php
/**
 * The commit the newest completed review of the PR saw (`../references/engine.md` §Scoped re-review): the
 * `reviewed_sha` of the newest `continued` `pr-review` entry that records one, newer than the latest
 * escalation or plan gap. A halted, looped-back or open review is never a base.
 */
function pipeline_review_base(array $ledger): ?string
{
    $since = pipeline_reset_at($ledger);
    $bases = array_filter($ledger, fn (array $entry) => ($entry['gate'] ?? null) === 'pr-review'
        && ($entry['outcome'] ?? null) === 'continued'
        && ($entry['at'] ?? '') > $since
        && is_string($entry['reviewed_sha'] ?? null));

    return $bases === [] ? null : end($bases)['reviewed_sha'];
}

/**
 * What a review of the PR after a completed one reads (`../references/engine.md` §Scoped re-review), or null
 * for the whole PR: no base, a base HEAD does not contain, a base ref that does not resolve, or any git call
 * that fails. `$git` runs git in the worktree, as `pipeline_git_run()` does.
 *
 * @return array{since: string, base: string, commits: int, files: list<string>}|null
 */
function pipeline_review_scope(array $manifest, callable $git): ?array
{
    $since = pipeline_review_base(pipeline_ledger($manifest));
    if ($since === null || $git(['merge-base', '--is-ancestor', $since, 'HEAD'])[0] !== 0) {
        return null;
    }
    $base = isset($manifest['base'])
        ? "origin/{$manifest['base']}"
        : (pipeline_git_lines($git, ['symbolic-ref', '-q', '--short', 'refs/remotes/origin/HEAD'])[0] ?? null);
    if ($base === null) {
        return null;
    }
    $range = ["{$since}..HEAD", "^{$base}"];
    $commits = pipeline_git_lines($git, ['rev-list', '--no-merges', ...$range]);
    $merges = pipeline_git_lines($git, ['rev-list', '--merges', ...$range]);
    $files = $merges === null ? null : pipeline_meeting_files($merges, $git);

    return $commits === null || $files === null ? null : ['since' => $since, 'base' => $base, 'commits' => count($commits), 'files' => $files];
}

/** The lines git printed, or null when it failed. */
function pipeline_git_lines(callable $git, array $args): ?array
{
    [$code, $out] = $git($args);

    return $code === 0 ? array_values(array_filter(explode("\n", $out), fn (string $line) => $line !== '')) : null;
}

/** The files where these merges met the branch's changes, sorted and unique, or null when git fails. */
function pipeline_meeting_files(array $merges, callable $git): ?array
{
    $files = [];
    foreach ($merges as $merge) {
        $merged = pipeline_merge_files($merge, $git);
        if ($merged === null) {
            return null;
        }
        $files = [...$files, ...$merged];
    }
    $files = array_values(array_unique($files));
    sort($files);

    return $files;
}

/**
 * One merge's meeting files: per parent after the first, what both sides changed since they last met
 * (a conflict, a clean merge of a shared file, a resolution that took one side), and what the merge
 * commit changed against every parent (an edit made in the merge itself).
 */
function pipeline_merge_files(string $merge, callable $git): ?array
{
    $parents = pipeline_git_lines($git, ['rev-parse', "{$merge}^@"]);
    $files = pipeline_git_lines($git, ['diff-tree', '-c', '--no-commit-id', '--name-only', $merge]);
    if ($parents === null || $files === null) {
        return null;
    }
    $first = array_shift($parents);
    foreach ($parents as $parent) {
        $theirs = pipeline_git_lines($git, ['diff', '--name-only', '--no-renames', "{$first}...{$parent}"]);
        $ours = pipeline_git_lines($git, ['diff', '--name-only', '--no-renames', "{$parent}...{$first}"]);
        if ($theirs === null || $ours === null) {
            return null;
        }
        $files = [...$files, ...array_intersect($theirs, $ours)];
    }

    return $files;
}
```

- [ ] **Step 4: Run the tests and the suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter=ReviewScopeTest`
Expected: PASS.

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, 0 failed.

- [ ] **Step 5: Commit** — `feat(pipeline): the base and the scope of a re-review of the PR (#88)`

---

### Task 4: the brief carries the scope, and both CLI paths hand it git

**Files:**
- Modify: `skills/pipeline/checks/brief.php` (`pipeline_brief()` ~84–95, `pipeline_brief_overrides()` ~174–205, a new `pipeline_review_scope_line()`)
- Modify: `skills/pipeline/checks/dispatch_cli.php` (`dispatch_cli_emit()` ~41–58, `dispatch_cli_brief()` ~255–278, a new `dispatch_cli_git()`)
- Test: `skills/pipeline/checks/tests/BriefTest.php`, `DispatchCliTest.php`

**Interfaces:**
- Consumes: `pipeline_review_scope()` and the `rereview_*` helpers (Task 3), `brief_manifest()`, `dispatch_fixture()`, `dispatch_cli()`, `pipeline_git_run()`.
- Produces: `pipeline_brief(array $manifest, string $leg, string $manifestPath, ?string $step = null, ?callable $git = null): string`; `pipeline_brief_overrides(array $manifest, string $leg, string $step, ?array $scope = null): string`; `pipeline_review_scope_line(array $scope): string`; `dispatch_cli_git(array $manifest): Closure`.

- [ ] **Step 1: Write the failing tests.** Append to `BriefTest.php`:

```php
it('scopes review-pr\'s review step to what changed since the last completed review, naming its commit and not the review', function () {
    $dir = rereview_repo(['shared.php' => "a\nb\nc\nd\ne\nf\ng\n"]);
    $reviewed = rereview_commit($dir, ['shared.php' => "A\nb\nc\nd\ne\nf\ng\n"]);
    rereview_main_moves($dir, ['shared.php' => "a\nb\nc\nd\ne\nf\nG\n"]);
    rereview_merge($dir);
    rereview_commit($dir, ['fix.php' => "<?php\n"]);
    $manifest = brief_manifest('review-pr', ['mode' => 'autoflow', 'worktree' => $dir, 'gate_ledger' => [rereview_entry('continued', $reviewed)]]);

    expect(pipeline_brief($manifest, 'review-pr', '/tmp/m.json', 'review', fn (array $args) => pipeline_git_run($dir, $args)))
        ->toContain("Scoped re-review (engine.md §Scoped re-review): a review of this PR completed at `{$reviewed}`, which HEAD contains, so your target is what changed since, not the whole PR:")
        ->toContain("the branch's own commits since (1), as patches, `git log -p --no-merges {$reviewed}..HEAD ^origin/main`, plus `git diff HEAD` (Stage 0 runs over both)")
        ->toContain("read whole at HEAD, the files where a merge since met this branch's changes: `shared.php`")
        ->toContain('Read beyond the target only where a finding needs it.')
        ->not->toContain('gate_ledger[0]')
        ->not->toContain('EARLIER REVIEW TEXT');
    expect(pipeline_brief($manifest, 'review-pr', '/tmp/m.json', 'review'))->not->toContain('Scoped re-review');
});

it('says when no merge met the branch, and when nothing was committed since the review', function () {
    $sha = str_repeat('a', 40);

    expect(pipeline_review_scope_line(['since' => $sha, 'base' => 'origin/main', 'commits' => 2, 'files' => []]))
        ->toContain("the branch's own commits since (2)")
        ->toContain("and no file more: no merge since met this branch's changes");
    expect(pipeline_review_scope_line(['since' => $sha, 'base' => 'origin/main', 'commits' => 0, 'files' => []]))
        ->toContain("nothing was committed on this branch since `{$sha}`: review only what the settled decisions above ask of the PR, and say so")
        ->not->toContain('git log');
});

it('asks git only on review-pr\'s review step, and briefs the whole PR when git cannot scope it', function (string $leg, string $step, int $calls) {
    $asked = [];
    $git = function (array $args) use (&$asked) {
        $asked[] = $args;

        return [1, '', 'not a git repository'];
    };
    $manifest = brief_manifest($leg, ['mode' => 'autoflow', 'gate_ledger' => [rereview_entry('continued', str_repeat('a', 40))]]);

    expect(pipeline_brief($manifest, $leg, '/tmp/m.json', $step, $git))->not->toContain('Scoped re-review');
    expect($asked)->toHaveCount($calls);
})->with([
    'review-pr review' => ['review-pr', 'review', 1],
    'review-pr resolve' => ['review-pr', 'resolve', 0],
    'review-plan review' => ['review-plan', 'review', 0],
    'implement' => ['implement', 'run', 0],
]);
```

Append to `DispatchCliTest.php`:

```php
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
```

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='BriefTest|DispatchCliTest'`
Expected: FAIL: `Call to undefined function pipeline_review_scope_line()`; the scoped brief lacks *Scoped re-review* (the fifth argument is ignored); the two `DispatchCliTest` cases print no scoped line; the *asks git only…* case records 0 calls on `review-pr review`.

- [ ] **Step 3: Implement.** In `brief.php`, `pipeline_brief()`:

```php
/**
 * `$step` is given in `autoflow` (the workflow script names it) and derived from the ledger in `interactive`.
 * `$git` runs git in the worktree; only `review-pr`'s review step asks it, for its scope (engine.md §Scoped re-review).
 */
function pipeline_brief(array $manifest, string $leg, string $manifestPath, ?string $step = null, ?callable $git = null): string
{
    $step ??= pipeline_step($manifest, $leg);
    $scope = $git !== null && "{$leg}:{$step}" === 'review-pr:review' ? pipeline_review_scope($manifest, $git) : null;

    return implode("\n\n", [
        pipeline_brief_role($manifest, $leg, $step),
        pipeline_brief_pointers($manifest, $manifestPath, $leg, $step),
        pipeline_brief_state($manifest, $leg),
        pipeline_brief_overrides($manifest, $leg, $step, $scope),
        pipeline_brief_return($leg, $step, (string) $manifest['mode']),
    ]) . "\n";
}
```

`pipeline_brief_overrides()` takes `?array $scope = null` as its fourth parameter and, right after the `pipeline_ci_rounds()` block, adds:

```php
    if ($scope !== null) {
        $lines[] = pipeline_review_scope_line($scope);
    }
```

Add after `pipeline_merge_files()`:

```php
/** The review-pr review step's target once a review of the PR has completed (`../references/engine.md` §Scoped re-review). */
function pipeline_review_scope_line(array $scope): string
{
    ['since' => $since, 'base' => $base, 'commits' => $commits, 'files' => $files] = $scope;
    $whole = $files === []
        ? "no file more: no merge since met this branch's changes"
        : "read whole at HEAD, the files where a merge since met this branch's changes: " . implode(', ', array_map(fn (string $file) => "`{$file}`", $files));
    $target = $commits === 0 && $files === []
        ? "nothing was committed on this branch since `{$since}`: review only what the settled decisions above ask of the PR, and say so"
        : "the branch's own commits since ({$commits}), as patches, `git log -p --no-merges {$since}..HEAD ^{$base}`, plus `git diff HEAD` (Stage 0 runs over both); and {$whole}";

    return "Scoped re-review (engine.md §Scoped re-review): a review of this PR completed at `{$since}`, which HEAD contains, so your target is what changed since, not the whole PR: {$target}. Read beyond the target only where a finding needs it.";
}
```

In `dispatch_cli.php`, add after `dispatch_cli_files()`:

```php
/** git in the run's worktree, for the scope of a re-review of the PR (`pipeline_review_scope()`). */
function dispatch_cli_git(array $manifest): Closure
{
    $worktree = rtrim($manifest['worktree'], '/');

    return fn (array $args): array => pipeline_git_run($worktree, $args);
}
```

and pass it as the fifth argument at both `pipeline_brief()` calls: in `dispatch_cli_emit()`, `pipeline_brief($manifest, $leg, $manifestPath, $step, dispatch_cli_git($manifest))`; in `dispatch_cli_brief()`, `return pipeline_brief($manifest, $leg, $manifestPath, $step, dispatch_cli_git($manifest));`.

- [ ] **Step 4: Run the tests and the suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='BriefTest|DispatchCliTest'`
Expected: PASS.

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, 0 failed. Every existing `dispatch_fixture()` brief stays full: its `worktree` is a plain temp dir, so `merge-base` fails there.

- [ ] **Step 5: Commit** — `feat(pipeline): a re-review of the PR is briefed on what changed since the last completed review (#88)`

---

### Task 5: engine.md and manifest.md

**Files:**
- Modify: `skills/pipeline/references/engine.md`, `skills/pipeline/references/manifest.md`
- Test: `skills/pipeline/checks/tests/LockStepTest.php`

**Interfaces:**
- Consumes: `pipeline_review_scope_line()` (Task 4).

- [ ] **Step 1: Write the failing test.** In `LockStepTest.php`, *keeps every engine.md section a brief names*, include the scoped line among the lines it reads: replace the `$lines = array_merge(...)` statement with

```php
    $lines = array_merge(
        ...array_values(pipeline_leg_overrides('autoflow')),
        ...array_values(pipeline_leg_overrides('interactive')),
        ...[[pipeline_review_scope_line(['since' => 'abc', 'base' => 'origin/main', 'commits' => 1, 'files' => []])]],
    );
```

- [ ] **Step 2: Run it to see it fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter=LockStepTest`
Expected: FAIL, `engine.md has no section 'Scoped re-review'`.

- [ ] **Step 3: Write the docs.** In `engine.md`, insert before `## Resolving a review — the resolve step acts on it`:

```markdown
## Scoped re-review — a review of the PR after a completed one reads what changed since

A run re-entered at `review-pr` (`launch --from review-pr`: an owner's request on a ready PR, the CI
gate's fix round) starts with a review step. Once the PR has passed a review, that step reviews what
changed since, not the whole PR again. Why (#88): on IT4WEBBV/Asimo PR #183 the change since the first
review was 4 files / 13 lines of a 16-file / 1493-line PR, and each of three relaunches' review steps
peaked at 156k–208k context. A cheaper model lowers the price per token; only the target lowers the tokens.

**The review step records what it saw.** Its entry carries `reviewed_sha`, the output of
`git rev-parse HEAD`. A `review-pr` review step whose entry lacks a 40-character one halts, and so does a
resolve step that adds, changes or removes it (`pipeline_ledger_problem()`, `manifest.md` §`gate_ledger`).

**The base** is `pipeline_review_base()`: the `reviewed_sha` of the newest `continued` `pr-review` entry
that has one, newer than the latest escalation or plan gap (`pipeline_reset_at()`, the cut
`pipeline_done_legs()` makes: code reviewed against a plan that grew is reviewed whole again). A halted,
looped-back or open review is never a base: its findings were not dispositioned there.

**The target** is `pipeline_review_scope()`, which `brief` (and `next` / `returned` in `interactive`)
computes with git in the worktree for `review-pr`'s review step only, and writes into its brief as one
override line:

- the branch's own commits since the base, as patches: `git log -p --no-merges <sha>..HEAD ^<base>`, plus
  `git diff HEAD`; Stage 0 runs over both. `<base>` is `origin/<manifest base>`, else `origin/HEAD`.
  `^<base>` leaves out what a merge of main brought in and keeps a merged-in side's commits that are not
  on main (a pull of the PR branch onto local commits), which `--first-parent` would drop;
- read whole at HEAD, the files where a merge since the base met the branch's changes: per merge not on
  the base, the files both sides changed since they last met (every conflict, a clean merge of a shared
  file, a resolution that took one side), and the files the merge commit changed against every parent
  (an edit made in the merge itself).

With nothing committed since the base the target is empty: the review checks what the settled decisions
ask of the PR and says the branch did not move; it does not widen to the whole PR.

**Otherwise the review is full, as before:** no `continued` entry with a sha, a sha HEAD does not contain
(a rebase, a force-push), a base ref git cannot resolve, or any git call that fails. The scope is never
narrower than git could prove.

**The earlier review is not carried:** the brief names its commit, never its entry (§What a leg brief
consists of). A chain of scoped reviews is as sound as the earliest full review in it; the base rule keeps
an undispositioned review out of the chain. Later, `git log --remerge-diff` (git 2.36+; one machine runs
2.33) could show a merge as only what its resolution changed, instead of the file read whole.
```

In §What a leg brief consists of, replace

```markdown
**A review step's brief is crafted context** (`../../critique/SKILL.md` §Reviewer contract): pointers,
decisions and overrides — never an earlier review, an earlier action, or another step's output.
```

with

```markdown
**A review step's brief is crafted context** (`../../critique/SKILL.md` §Reviewer contract): pointers,
decisions and overrides — never an earlier review, an earlier action, or another step's output. A
re-review of the PR names the commit the last completed review saw, never that review (§Scoped re-review).
```

In §`autoflow`'s `launch` bullet, replace

```markdown
  `pipeline_legs()`: a PR that needs new commits gets a new run with `--from review-pr`, without
  editing a file; `--decision <text>`, repeatable, appends that text to `decisions` verbatim in the same
```

with

```markdown
  `pipeline_legs()`: a PR that needs new commits gets a new run with `--from review-pr`, without
  editing a file, and its review reads what changed since the last completed one (§Scoped re-review);
  `--decision <text>`, repeatable, appends that text to `decisions` verbatim in the same
```

In `manifest.md`, the `gate_ledger` key table, add after the `issue_links` row:

```markdown
| `reviewed_sha` | **`pr-review` entries only** — the commit the review step reviewed, `git rev-parse HEAD`, 40 hex characters. Required on the entry a `review-pr` review step adds; never changed after (a resolve step that touches it halts). A later review of the PR is scoped to what changed since the newest `continued` one (`engine.md` §Scoped re-review) |
```

and in *What a leg writes*, replace `(the resolve step may only complete the open entry)` with `(the resolve step may only complete the open entry, leaving its `gate`, `leg`, `cycle`, `at`, `review`, `annotations` and `reviewed_sha` as they are)`.

- [ ] **Step 4: Run the tests and the suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter=LockStepTest`
Expected: PASS.

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, 0 failed.

- [ ] **Step 5: Commit** — `docs(pipeline): engine.md §Scoped re-review and reviewed_sha in manifest.md (#88)`
