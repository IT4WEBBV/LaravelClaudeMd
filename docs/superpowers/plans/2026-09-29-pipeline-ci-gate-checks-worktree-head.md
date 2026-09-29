# The CI gate checks that GitHub's head is the worktree's `HEAD` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task by task. Steps use checkbox (`- [ ]`) syntax for tracking. In an `autoflow` implement step, execute inline, task by task, with no subagents.

**Goal:** `dispatch_cli.php ci` reads the worktree's `HEAD` beside the PR and, while GitHub's `headRefOid` differs from it, answers `wait` (verdict `mismatch`) and from the third read halts on `review-pr` with both shas, before any CI verdict counts.

**Architecture:**
- `skills/pipeline/checks/ci.php`: `pipeline_ci_answer()` gains `string $head`; a new `pipeline_ci_mismatch()`; `PIPELINE_CI_NONE_POLLS` renamed `PIPELINE_CI_PUSH_POLLS` and shared by the `none` and `mismatch` rules.
- `skills/pipeline/checks/dispatch_cli.php`: `dispatch_cli_ci()` reads `HEAD` with `pipeline_git_run()` and halts at once when git cannot.
- Docs: engine.md §The CI gate and §Failure policy; pipeline `SKILL.md` §`autoflow` step 5.

**Tech Stack:** PHP 8.4 on the host, Pest 4, `gh` and `git` faked in the `ci` tests, Markdown skill references.

**Spec:** `docs/superpowers/specs/2026-09-29-pipeline-ci-gate-checks-worktree-head-design.md`

## Global Constraints

- Suite, from the worktree root on the host: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`. `vendor/` is missing in a fresh worktree: run `composer install --no-interaction --quiet` first; it must be a real directory, never a symlink to the primary's.
- The mismatch halt reason, verbatim: `PR #<pr>'s head on GitHub is <sha>, but the worktree's HEAD is <head>: the two must match before its checks count; push the branch, or reconcile it when GitHub is ahead, and run the CI gate again`.
- The unreadable-`HEAD` halt reason, verbatim: `the CI gate cannot read the worktree's HEAD at <worktree>: <git's stderr>`.
- A `mismatch` answer is `{action, verdict: 'mismatch', sha, head}` (`wait`) or `{action: 'halt', leg: 'review-pr', reason, verdict: 'mismatch', sha, head}`. Every other answer keeps its exact current shape, with no `head` key.
- `ci` stays read-only. `finish`, `brief.php`, `pipeline-autoflow.js`, the shell loop, `orchestrate`'s docs and the merge watch do not change.
- This repo has no `.changelog/` and no `CHANGELOG.md`: no changelog entry.

## Review Focus

1. **The order of the rules.** `unreadable` (no view) first, then `mismatch`, then the CI verdict: a red on an older commit must not answer `fix`, and a green or a `none` on it must not answer `ready`. Pinned in Task 1 (a red view, and an empty rollup without workflows, both answer `mismatch`).
2. **The bound.** `wait` at reads 1 and 2, `halt` at read 3, by the same constant as the `none` rule (spec, *Assumptions* 1, 2 and 6).
3. **The issue's Done-when case**, in `DispatchCliTest`, through the real `ci` command: a green PR whose head is not the worktree's `HEAD` is not `ready`. Pinned in Task 2, with `finish` recording the halt on `review-pr` and the manifest untouched.
4. **git failing is a halt before gh is asked**, not a wait. Pinned in Task 2 (no `calls` file).

---

## File Structure

- Modify `skills/pipeline/checks/ci.php`, `skills/pipeline/checks/dispatch_cli.php`.
- Modify tests `skills/pipeline/checks/tests/CiTest.php`, `skills/pipeline/checks/tests/DispatchCliTest.php`.
- Modify docs `skills/pipeline/references/engine.md`, `skills/pipeline/SKILL.md`.

---

### Task 1: `pipeline_ci_answer()` compares the heads first

**Files:**
- Modify: `skills/pipeline/checks/ci.php` (the constant at line 12, `pipeline_ci_answer()` ~73–98, a new function after it)
- Modify: `skills/pipeline/checks/dispatch_cli.php` (`dispatch_cli_ci()` ~401–423, only so the suite keeps running: the head passed in here is read properly in Task 2)
- Test: `skills/pipeline/checks/tests/CiTest.php`

**Interfaces:**
- Consumes: `pipeline_ci_halt(string $reason, array $read): array`, `pipeline_ci_verdict()`, the test helpers `ci_manifest()`, `ci_view()` (head `abc123`), `ci_run()`.
- Produces: `pipeline_ci_answer(array $manifest, ?array $view, string $head, bool $workflows, int $poll): array`; `pipeline_ci_mismatch(array $manifest, string $sha, string $head, int $poll): array`; `PIPELINE_CI_PUSH_POLLS`.

- [ ] **Step 1: Write the failing test and pass a matching head in the existing calls.** In `CiTest.php`, in the three tests that call `pipeline_ci_answer()` (*answers ready on green…*, *waits on pending checks…*, *answers one fix round on red…*), insert `'abc123', ` as the third argument of every call, e.g.

```php
    expect(pipeline_ci_answer(ci_manifest(), ci_view([]), 'abc123', true, 2))->toBe(['action' => 'wait', 'verdict' => 'none', 'sha' => 'abc123']);
```

leaving every expectation as it is. Then add, before *counts the recorded CI failures…*:

```php
it('answers mismatch while GitHub\'s head is not the worktree\'s HEAD, before any verdict, and halts with both shas at the third read', function () {
    $mismatch = ['action' => 'wait', 'verdict' => 'mismatch', 'sha' => 'abc123', 'head' => 'def456'];
    $reason = "PR #7's head on GitHub is abc123, but the worktree's HEAD is def456: the two must match before its checks count; push the branch, or reconcile it when GitHub is ahead, and run the CI gate again";
    $green = ci_view([ci_run('ci', 'COMPLETED', 'SUCCESS')]);

    expect(pipeline_ci_answer(ci_manifest(), $green, 'def456', true, 1))->toBe($mismatch);
    expect(pipeline_ci_answer(ci_manifest(), $green, 'def456', true, 2))->toBe($mismatch);
    expect(pipeline_ci_answer(ci_manifest(), $green, 'def456', true, 3))
        ->toBe(['action' => 'halt', 'leg' => 'review-pr', 'reason' => $reason, 'verdict' => 'mismatch', 'sha' => 'abc123', 'head' => 'def456']);
    expect(pipeline_ci_answer(ci_manifest(), ci_view([ci_run('ci', 'COMPLETED', 'FAILURE')]), 'def456', true, 1))->toBe($mismatch);
    expect(pipeline_ci_answer(ci_manifest(), ci_view([]), 'def456', false, 1))->toBe($mismatch);
    expect(pipeline_ci_answer(ci_manifest(), null, 'def456', true, 1))->toBe(['action' => 'wait', 'verdict' => 'unreadable']);
});
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter=CiTest`
Expected: FAIL. The new test fails on its first expectation (the old four-parameter function takes `'def456'` as `$workflows` and answers `ready`). The existing answer tests whose read number is not 1 fail as well, since the extra argument shifts `$poll`; the classification tests pass.

- [ ] **Step 3: Implement.** In `ci.php`, replace the `PIPELINE_CI_NONE_POLLS` constant and its docblock with:

```php
/** Reads GitHub gets to catch up with a push: its head moved to the pushed commit, its checks registered. */
const PIPELINE_CI_PUSH_POLLS = 3;
```

Replace `pipeline_ci_answer()`'s docblock and signature, add the head check after the `$view === null` block, and use the new constant in the `none` arm:

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
    if ($view === null) {
        return $last
            ? pipeline_ci_halt("CI on PR #{$manifest['artifacts']['pr']} had not settled after an hour, and gh could not read its checks at the last read", ['verdict' => 'unreadable'])
            : ['action' => 'wait', 'verdict' => 'unreadable'];
    }
    if ($view['headRefOid'] !== $head) {
        return pipeline_ci_mismatch($manifest, $view['headRefOid'], $head, $poll);
    }
    $ci = pipeline_ci_verdict($view['statusCheckRollup']);
    $read = ['verdict' => $ci['verdict'], 'sha' => $view['headRefOid']];

    return match ($ci['verdict']) {
        'green' => ['action' => 'ready', ...$read],
        'none' => ['action' => $workflows && $poll < PIPELINE_CI_PUSH_POLLS ? 'wait' : 'ready', ...$read],
        'pending' => $last
            ? pipeline_ci_halt("CI on {$read['sha']} has not finished after an hour: " . implode(', ', $ci['pending']), $read)
            : ['action' => 'wait', ...$read],
        'red' => pipeline_ci_red($manifest, [...$read, 'failing' => $ci['failing']]),
    };
}

/** GitHub's head is not the worktree's: a push still showing is waited for, one that did not land halts. */
function pipeline_ci_mismatch(array $manifest, string $sha, string $head, int $poll): array
{
    $read = ['verdict' => 'mismatch', 'sha' => $sha, 'head' => $head];

    return $poll < PIPELINE_CI_PUSH_POLLS
        ? ['action' => 'wait', ...$read]
        : pipeline_ci_halt("PR #{$manifest['artifacts']['pr']}'s head on GitHub is {$sha}, but the worktree's HEAD is {$head}: the two must match before its checks count; push the branch, or reconcile it when GitHub is ahead, and run the CI gate again", $read);
}
```

In `dispatch_cli.php`, `dispatch_cli_ci()`, pass a placeholder head so the file keeps parsing and the old `ci` tests keep their meaning until Task 2 reads it: add, after `$worktree = rtrim(...)`,

```php
    $view = dispatch_cli_pr_view($worktree, $pr, 'headRefOid,statusCheckRollup');
```

and call `pipeline_ci_answer($manifest, $view, (string) ($view['headRefOid'] ?? ''), glob(...) !== [], $poll)`. Task 2 replaces this with the real read.

- [ ] **Step 4: Run the tests and the suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter=CiTest`
Expected: PASS.

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, 0 failed. `grep -rn PIPELINE_CI_NONE_POLLS skills/` prints nothing.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/ci.php skills/pipeline/checks/dispatch_cli.php skills/pipeline/checks/tests/CiTest.php
git commit -m "feat(pipeline): the CI answer waits while GitHub's head is not the worktree's HEAD, then halts with both shas (#99)"
```

---

### Task 2: `ci` reads the worktree's `HEAD`

**Files:**
- Modify: `skills/pipeline/checks/dispatch_cli.php` (`dispatch_cli_ci()` and its docblock)
- Test: `skills/pipeline/checks/tests/DispatchCliTest.php` (`ci_fixture()` ~597, new tests after *gates an interactive run as well…*)

**Interfaces:**
- Consumes: `pipeline_git_run(string $worktree, array $args, array $env = []): array{0: int, 1: string, 2: string}` (`suite.php`, required by `dispatch_cli.php`), `pipeline_halt()`, Task 1's `pipeline_ci_answer()`, the test helpers `ci_gate()`, `ci_head()` (head `abc123`), `dispatch_cli()`.
- Produces: `ci_fixture(?array $view, bool $workflows = true, array $decisions = [], ?string $head = 'abc123'): array`, with a fake `git` first on `PATH` that prints the fixture's `head` file and fails without one.

- [ ] **Step 1: Write the failing tests.** Change `ci_fixture()`'s docblock, signature and body: after the fake `gh` is written and made executable, add a fake `git`, and write the head file:

```php
/**
 * A finished autoflow run on PR 7, with a fake gh first on PATH that answers `pr view` from pr.json (none:
 * gh fails) and a fake git that answers `rev-parse HEAD` from head (none: git fails).
 */
function ci_fixture(?array $view, bool $workflows = true, array $decisions = [], ?string $head = 'abc123'): array
```

```php
    file_put_contents($fixture['dir'] . '/bin/git', <<<'SH'
#!/bin/sh
[ -f "$GH_FAKE/head" ] || { echo 'fatal: not a git repository' >&2; exit 128; }
cat "$GH_FAKE/head"
SH);
    chmod($fixture['dir'] . '/bin/git', 0755);
    if ($head !== null) {
        file_put_contents($fixture['dir'] . '/head', $head);
    }
```

Then add after *gates an interactive run as well, since its finish step runs the same loop*:

```php
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
```

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter="worktree's HEAD"`
Expected: FAIL, both. Task 1's placeholder compares GitHub's head with itself, so the first answers `ready` instead of `mismatch`, and the second never calls git and answers `ready`.

- [ ] **Step 3: Read `HEAD`.** In `dispatch_cli_ci()`, replace Task 1's placeholder (from `$worktree = rtrim(...)` to the end of the function) with:

```php
    $worktree = rtrim($manifest['worktree'], '/');
    [$code, $head, $error] = pipeline_git_run($worktree, ['rev-parse', 'HEAD']);
    if ($code !== 0) {
        return pipeline_halt("the CI gate cannot read the worktree's HEAD at {$worktree}: {$error}");
    }

    return pipeline_ci_answer(
        $manifest,
        dispatch_cli_pr_view($worktree, $pr, 'headRefOid,statusCheckRollup'),
        $head,
        glob("{$worktree}/.github/workflows/*.y*ml") !== [],
        $poll,
    );
```

and its docblock with:

```php
/**
 * The CI gate's reads (`../references/engine.md` §The CI gate): the worktree's `HEAD`, then the PR's head
 * commit and its checks in one gh call, and what the session does next. It never writes the manifest, so
 * polling it changes nothing.
 */
```

- [ ] **Step 4: Run the tests and the suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter="worktree's HEAD|gates the PR|red head|no checks|cannot read the PR|interactive run as well|without a PR|cannot parse"`
Expected: PASS: the new tests, and the existing `ci` tests, whose fixture now answers `HEAD` with `abc123`.

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, 0 failed.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/dispatch_cli.php skills/pipeline/checks/tests/DispatchCliTest.php
git commit -m "feat(pipeline): ci reads the worktree's HEAD and halts at once when git cannot (#99)"
```

---

### Task 3: engine.md and `SKILL.md` name the check

**Files:**
- Modify: `skills/pipeline/references/engine.md` (§The CI gate ~742–776, §Failure policy ~1110)
- Modify: `skills/pipeline/SKILL.md` (§`autoflow` step 5, ~103–112)

**Interfaces:**
- Consumes: Tasks 1–2's rule, in the same terms.
- Produces: nothing code reads. The heading `## The CI gate — CI on the PR's head commit, before \`gh pr ready\`` does not change: `LockStepTest` resolves the brief's `§The CI gate` against it.

- [ ] **Step 1: §The CI gate, the read.** Replace

```markdown
  (`../checks/ci.php`) reads the PR's head commit and its checks once
  (`gh pr view <pr> --json headRefOid,statusCheckRollup`), writes nothing, and prints one JSON line:
```

with

```markdown
  (`../checks/ci.php`) reads the worktree's `HEAD` (`git rev-parse HEAD`; a git error halts at once), then
  the PR's head commit and its checks once (`gh pr view <pr> --json headRefOid,statusCheckRollup`), writes
  nothing, and prints one JSON line. GitHub's head has to be the worktree's `HEAD` before its checks count
  (#99): a push that failed or was skipped leaves an older head whose CI can be green, and the PR would go
  ready without the last fix. That comparison comes first, so neither a green nor a red on an older
  commit counts:
```

- [ ] **Step 2: §The CI gate, the table.** Insert as the table's first row, directly under `|---|---|`:

```markdown
| `mismatch`: GitHub's head is not the worktree's `HEAD` | `wait`; `halt` at the third read, naming both shas: a push GitHub shows within seconds, and one it does not show by then did not land |
```

- [ ] **Step 3: §The CI gate, the `halt` bullet.** Replace

```markdown
  is a halt as well. After a halt on the hour nothing needs re-reviewing: run the loop again by hand on
  the halted manifest (`ci` is read-only and refuses only a retired mode or a missing PR) rather than
  `launch`, which would re-run `review-pr:review`.
```

with

```markdown
  is a halt as well. After a halt on the hour, or on a `mismatch` once the heads match, nothing needs
  re-reviewing: run the loop again by hand on the halted manifest (`ci` is read-only and refuses only a
  retired mode, a missing PR or a worktree whose `HEAD` git cannot read) rather than `launch`, which would
  re-run `review-pr:review`.
```

- [ ] **Step 4: §Failure policy.** Replace

```markdown
- **CI on the PR's head commit red after the fix round, or not settled in an hour** (§The CI gate) →
```

with

```markdown
- **CI on the PR's head commit red after the fix round, or not settled in an hour, or GitHub's head still
  not the worktree's `HEAD` at the third read** (§The CI gate) →
```

- [ ] **Step 5: `SKILL.md` step 5.** Replace

```markdown
5. **`finish` printed `done`: the CI gate** on the PR's head commit (`references/engine.md` §The CI
   gate), polled in one background Bash; wait for its completion notice:
```

with

```markdown
5. **`finish` printed `done`: the CI gate** on the PR's head commit, which must be the worktree's `HEAD`
   (`references/engine.md` §The CI gate), polled in one background Bash; wait for its completion notice:
```

and in the same step replace `` **`halt`:** `finish <manifest> '<the answer>'`, then as any halt. `` with
`` **`halt`:** `finish <manifest> '<the answer>'`, then as any halt; on a `mismatch` GitHub's head and the worktree's `HEAD` differ: once they match (push the branch, or reconcile it when GitHub is ahead), the loop runs again by hand. ``

- [ ] **Step 6: Check and run the suite**

Run: `grep -n "mismatch" skills/pipeline/references/engine.md skills/pipeline/SKILL.md`
Expected: the table row, the `halt` bullet, and the `SKILL.md` step 5 line.

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, 0 failed (`LockStepTest` included).

- [ ] **Step 7: Commit**

```bash
git add skills/pipeline/references/engine.md skills/pipeline/SKILL.md
git commit -m "docs(pipeline): the CI gate compares GitHub's head with the worktree's HEAD before its checks count (#99)"
```
