# `dispatch_cli.php handoff` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The `handoff` leg runs one tested command, `dispatch_cli.php handoff <manifest>`, which pushes
the branch, opens the draft PR or adopts the one the branch already has, sets the board Component and
records the step itself; the `handoff` skill is no longer invoked by a run, in either mode.

**Architecture:** A new file, `handoff.php`, holds the pure decisions (title, body, what an existing body
gains, the Component target, the choice among listed PRs) and the step itself, which takes the manifest
and two runners (git and gh, each `array $args → [code, out, err]`) and returns the PR or throws a halt.
`dispatch_cli.php` wraps it: `record`'s opening checks first, then the step, then the write through
`dispatch_cli_record()`, for `continued` and for a halt alike. `brief.php` prints that command in place of
`record --status continued` for `handoff:run`. The gh runner moves out of `kickoff.php` into `gh.php`, and
the two board calls kickoff's claim makes become one function in `board.php` that the Component uses too.

**Tech Stack:** PHP 8.4, no framework; Pest 4 (`skills/pipeline/checks/tests`); git and a fake `gh` on
`PATH` in the CLI tests.

**Spec:** `docs/superpowers/specs/2026-09-30-pipeline-handoff-command-design.md`. Read it with this plan:
the plan argues from it, and its `## Assumptions` 21–26 are the answers this plan assumed.

## Global Constraints

- Everything lives under `skills/pipeline/`, plus one paragraph in `README.md`. Run every command from the
  worktree root.
- Tests: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
  (append `--filter <Name>` for one file). This repo is not a Docker project: Pest runs on the host. A fresh
  worktree has no `vendor/`: run `composer install` once first. The suite needs `node` on `PATH`.
- Test-first: every task writes its test, sees it fail, then writes the code.
- The command is `php dispatch_cli.php handoff <manifest>`. It serves both modes and refuses the retired
  `auto`.
- Its answer is one JSON line: `{"action":"recorded",…}` with exit 0, a halt included, or
  `{"action":"refused","reason":…}` with **exit 1**. A usage error is exit 1 with the usage on stderr.
- Arguments go to gh and git as an argv list (`proc_open`), never through a shell.
- The push is never forced. No gh or git call is retried inside the command.
- Nothing leaves the machine before the preflight and the PR lookup have passed: a halt there pushes
  nothing.
- An existing PR's title is never changed; its body is only ever added to.
- No comment is posted on the PR.
- A Component failure is a note, never a halt. An `invalid` `## Board` section is a halt, before the push.
- Title: `Implement: <heading> (issue: #<n>)`. Body of a new PR:
  ``Implements the design in `<spec>`.`` / ``Plan: `<plan>`.`` / blank / `Part of #<n>.`
- `record.php`, `dispatch.php`, `agents.php` and `workflow/pipeline-autoflow.js` do not change. The
  manifest's shape does not change. `record --status continued --pr <n>` stays valid for `handoff:run`.
- The `handoff` skill itself is not touched.
- Native backed enums, never string constants. Guard clauses over nesting; full type hints.
- No changelog entry: the repo has no `.changelog/` and no `CHANGELOG.md`.
- Commits: no `Co-Authored-By`, no AI attribution; every message ends on `(#125)`.

## File Structure

| File | Responsibility |
|---|---|
| `checks/gh.php` (create) | `pipeline_gh_run()`: gh from a directory, without a shell (moved from `kickoff.php`) |
| `checks/board.php` (modify) | gains `pipeline_board_set()`: one single-select field on the board item of a URL |
| `checks/kickoff.php` (modify) | uses both; the `gh-merge-base` config goes |
| `checks/handoff.php` (create) | the pure decisions and the step, `pipeline_handoff()` |
| `checks/dispatch_cli.php` (modify) | the `handoff` command; `record`'s opening checks as one function |
| `checks/brief.php` (modify) | `handoff:run`'s overrides and its `## Return`; the base line goes |
| `checks/tests/Pest.php` (modify) | loads `gh.php` and `handoff.php` |
| `checks/tests/HandoffTest.php` (create) | the pure functions, no git, no gh |
| `checks/tests/HandoffCliTest.php` (create) | the command against a clone of a bare origin and a fake gh |
| `checks/tests/BoardTest.php`, `DispatchCliTest.php`, `BriefTest.php` (modify) | the shared board call; no `gh-merge-base`; the new brief lines |
| `references/engine.md`, `manifest.md`, `gates.md`, `SKILL.md`, `README.md` (modify) | `handoff` is a command |

## Review Focus

Inputs the spec implies and its test list does not name, most likely first. Each has its test in the task
that owns the code.

1. **gh's or git's error text that is not valid UTF-8.** A halt whose reason cannot be JSON could not be
   recorded or printed. Expected: the halt is recorded, with the bad bytes replaced. Test in Task 4
   (*records a halt before anything is pushed*, the `not UTF-8` row).
2. **`gh pr create` succeeds and the listing that follows shows no PR.** Expected: a halt that says so and
   that the next run adopts it; no number guessed from `create`'s output. Test in Task 4.
3. **An existing body that holds `#1250` on a run for issue 125.** Expected: it does not count as naming
   the issue, and `Part of #125.` is added. Test in Task 3.
4. **`artifacts.pr` names a PR whose head is another branch.** Expected: a halt before the push, naming
   both branches. Test in Task 4 (the `another branch's PR` row).
5. **The worktree is on another branch than the manifest's, or on a detached `HEAD`.** Expected: a halt
   before the push. Test in Task 4 (the `another branch checked out` and `a detached HEAD` rows).

---

### Task 1: `gh.php` and one board call for kickoff's claim and the Component

**Files:**
- Create: `skills/pipeline/checks/gh.php`
- Modify: `skills/pipeline/checks/board.php` (append `pipeline_board_set()`)
- Modify: `skills/pipeline/checks/kickoff.php:9-13` (requires), `:140-167` (`pipeline_kickoff_gh()` goes),
  `:346-362` (`pipeline_kickoff_claim()`)
- Modify: `skills/pipeline/checks/tests/Pest.php` (the load list)
- Test: `skills/pipeline/checks/tests/BoardTest.php`, `skills/pipeline/checks/tests/DispatchCliTest.php`
  (its kickoff cases, unchanged, stay green)

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `pipeline_gh_run(string $cwd, array $args): array` → `[int $code, string $out, string $err]`, both
    trimmed.
  - `pipeline_board_set(callable $gh, array $board, string $url, string $fieldId, string $optionId): ?string`
    → null when the field is set, else gh's words. `$gh` is `fn (array $args): array` returning
    `[code, out, err]`; `$board` is `pipeline_repo_board()['board']`.

- [ ] **Step 1: Write the failing test**

Append to `skills/pipeline/checks/tests/BoardTest.php`:

```php
it('sets a single-select field on the board item of a URL with two gh calls, and returns gh\'s words when either fails', function () {
    $board = ['org' => 'acme', 'number' => '7', 'project-id' => 'PVT_1'];
    $url = 'https://github.com/acme/app/pull/7';
    $calls = [];
    $gh = function (array $answers) use (&$calls): Closure {
        return function (array $args) use (&$calls, &$answers): array {
            $calls[] = implode(' ', $args);

            return array_shift($answers);
        };
    };

    expect(pipeline_board_set($gh([[0, '{"id":"ITEM_1"}', ''], [0, '', '']]), $board, $url, 'F_1', 'O_1'))->toBeNull();
    expect($calls)->toBe([
        'project item-add 7 --owner acme --url https://github.com/acme/app/pull/7 --format json',
        'project item-edit --id ITEM_1 --project-id PVT_1 --field-id F_1 --single-select-option-id O_1',
    ]);
    expect(pipeline_board_set($gh([[1, '', 'HTTP 401: Bad credentials']]), $board, $url, 'F_1', 'O_1'))->toBe('HTTP 401: Bad credentials');
    expect(pipeline_board_set($gh([[1, '', '']]), $board, $url, 'F_1', 'O_1'))->toBe('gh project item-add exited 1');
    expect(pipeline_board_set($gh([[0, '{}', '']]), $board, $url, 'F_1', 'O_1'))->toBe('gh project item-add returned no item id');
    expect(pipeline_board_set($gh([[0, '{"id":"ITEM_1"}', ''], [1, '', '']]), $board, $url, 'F_1', 'O_1'))->toBe('gh project item-edit exited 1');
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter BoardTest`
Expected: FAIL, `Call to undefined function pipeline_board_set()`.

- [ ] **Step 3: Create `gh.php`**

```php
<?php

/**
 * gh from `$cwd`, without a shell and with no stdin; stdout and stderr apart, so JSON stays JSON.
 *
 * @return array{0: int, 1: string, 2: string}
 */
function pipeline_gh_run(string $cwd, array $args): array
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
```

- [ ] **Step 4: Append `pipeline_board_set()` to `board.php`**

```php

/**
 * Sets one single-select field on the board item of `$url` (an issue or a PR): `item-add`, which returns
 * the existing item when the URL is already on the board, then `item-edit`. Both calls are idempotent.
 * A call that fails answers gh's words, or `exited <code>` when gh said nothing, the same for both.
 * `$gh` runs gh and returns `[code, out, err]`; `$board` is `pipeline_repo_board()`'s `board`.
 *
 * @return ?string null when the field is set, else why not
 */
function pipeline_board_set(callable $gh, array $board, string $url, string $fieldId, string $optionId): ?string
{
    [$code, $out, $err] = $gh(['project', 'item-add', $board['number'], '--owner', $board['org'], '--url', $url, '--format', 'json']);
    if ($code !== 0) {
        return $err === '' ? "gh project item-add exited {$code}" : $err;
    }
    $item = json_decode($out, true)['id'] ?? null;
    if (! is_string($item)) {
        return 'gh project item-add returned no item id';
    }
    [$code, , $err] = $gh(['project', 'item-edit', '--id', $item, '--project-id', $board['project-id'], '--field-id', $fieldId, '--single-select-option-id', $optionId]);

    return match (true) {
        $code === 0 => null,
        $err === '' => "gh project item-edit exited {$code}",
        default => $err,
    };
}
```

- [ ] **Step 5: `kickoff.php` uses both**

Add `require_once __DIR__ . '/gh.php';` after the `board.php` require. Delete `pipeline_kickoff_gh()`
with its docblock. In `pipeline_kickoff_gh_json()` the call becomes
`[$code, $out, $err] = pipeline_gh_run($cwd, $args);`. Replace the body of `pipeline_kickoff_claim()`
(its docblock and signature stay):

```php
function pipeline_kickoff_claim(string $repoRoot, array $board, array $issue): array
{
    $error = pipeline_board_set(
        fn (array $args): array => pipeline_gh_run($repoRoot, $args),
        $board,
        $issue['url'],
        $board['status-field-id'],
        $board['in-progress-option-id'],
    );

    return [$error === null ? "#{$issue['number']} is In Progress on board {$board['number']}" : "the board claim was not recorded: {$error}"];
}
```

In `tests/Pest.php` add `'gh.php'` before `'kickoff.php'` in the list of files.

- [ ] **Step 6: Run the tests**

Run: `grep -rn "pipeline_kickoff_gh(" skills/`
Expected: no output.

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "BoardTest|DispatchCliTest|KickoffTest"`
Expected: PASS, the three existing claim cases included (*claims the issue on a valid board…*, *reports a
board claim that could not be recorded…*, *claims nothing when the create fails…*).

- [ ] **Step 7: Commit**

```bash
git add skills/pipeline/checks/gh.php skills/pipeline/checks/board.php skills/pipeline/checks/kickoff.php skills/pipeline/checks/tests/Pest.php skills/pipeline/checks/tests/BoardTest.php
git commit -m "refactor(pipeline): gh.php runs gh, and one board call sets a single-select field for kickoff's claim (#125)"
```

---

### Task 2: Kickoff sets no `gh-merge-base`

**Files:**
- Modify: `skills/pipeline/checks/kickoff.php:293-318` (`pipeline_kickoff_prepare()`, `pipeline_kickoff_on_base()`)
- Test: `skills/pipeline/checks/tests/DispatchCliTest.php:1172-1191`

**Interfaces:**
- Consumes: nothing.
- Produces: `pipeline_kickoff_on_base(string $worktree, string $base): void` (the `$branch` parameter
  goes). Task 4's command passes `--base` from the manifest instead.

- [ ] **Step 1: Rewrite the two tests as one**

In `DispatchCliTest.php` replace the tests *kicks off on a per-run base: cut from it, gh-merge-base set,
recorded in the manifest* and *sets no gh-merge-base without a base* with:

```php
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
```

- [ ] **Step 2: Run it to see it fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "no gh-merge-base on the branch"`
Expected: FAIL on the `gh-merge-base` line: `git config` exits 0.

- [ ] **Step 3: Remove the config**

In `pipeline_kickoff_prepare()` the call becomes `pipeline_kickoff_on_base($worktree, $base);`. Replace
`pipeline_kickoff_on_base()` and its docblock:

```php
/** The create honoured the base: the worktree's `HEAD` is `origin/<base>`. `dispatch_cli.php handoff` opens the PR into it with `--base`. */
function pipeline_kickoff_on_base(string $worktree, string $base): void
{
    $head = pipeline_git($worktree, ['rev-parse', 'HEAD']);
    $tip = pipeline_git($worktree, ['rev-parse', "origin/{$base}"]);
    if ($head !== $tip) {
        throw new PipelineKickoffHalt("the worktree's HEAD ({$head}) is not origin/{$base} ({$tip}): the declared worktree.create did not honour --base");
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter DispatchCliTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/kickoff.php skills/pipeline/checks/tests/DispatchCliTest.php
git commit -m "feat(pipeline): kickoff sets no gh-merge-base; the base reaches the PR from the manifest (#125)"
```

---

### Task 3: `handoff.php`, the pure decisions

**Files:**
- Create: `skills/pipeline/checks/handoff.php`
- Modify: `skills/pipeline/checks/tests/Pest.php` (the load list)
- Test: `skills/pipeline/checks/tests/HandoffTest.php` (create)

**Interfaces:**
- Consumes: `pipeline_pr_problem(int|string $pr, ?array $view): ?string` (`dispatch.php`),
  `pipeline_repo_board()`'s return shape (`board.php`).
- Produces:
  - `const PIPELINE_HANDOFF_PR_FIELDS = 'number,url,state,isDraft,baseRefName,headRefName,body'`
  - `final class PipelineHandoffHalt extends RuntimeException`
  - `pipeline_handoff_title(string $spec, string $branch, ?int $issue): string`; `$spec` is the spec's text.
  - `pipeline_handoff_body(string $spec, string $plan, ?int $issue): string`; `$spec` and `$plan` are paths.
  - `pipeline_handoff_body_update(string $body, string $spec, string $plan, ?int $issue): ?string`; null
    means no edit.
  - `pipeline_handoff_component(array $board): array|string|null`; the array is
    `{name: string, field: string, option: string}`, the string a note, null nothing to set. `$board` is
    `pipeline_repo_board()`'s whole answer.
  - `pipeline_handoff_choice(array $prs): array|string|null`; null to create, the PR to adopt, or the
    reason to halt.
  - `pipeline_handoff_words(string $words, int $code): string`

- [ ] **Step 1: Write the failing tests**

Create `skills/pipeline/checks/tests/HandoffTest.php`:

```php
<?php

function handoff_listed(array $overrides = []): array
{
    return ['number' => 7, 'url' => 'https://github.com/acme/app/pull/7', 'state' => 'OPEN', 'isDraft' => true, 'baseRefName' => 'main', 'headRefName' => 'feature', 'body' => '', ...$overrides];
}

it('titles the PR from the spec\'s heading, less its design suffix, with the issue when there is one', function (string $spec, ?int $issue, string $title) {
    expect(pipeline_handoff_title($spec, 'feature/x', $issue))->toBe($title);
})->with([
    'an issue' => ["# The handoff leg runs a command — design\n\n**Design size:** Architectural\n", 125, 'Implement: The handoff leg runs a command (issue: #125)'],
    'no issue' => ["# The handoff leg runs a command — design\n", null, 'Implement: The handoff leg runs a command'],
    'a hyphen before design' => ["# Palettes - design\n", 7, 'Implement: Palettes (issue: #7)'],
    'no design suffix' => ["# Palettes\n", 7, 'Implement: Palettes (issue: #7)'],
    'the first H1, not an H2 before it' => ["## Problem\n\n# Palettes — design\n", null, 'Implement: Palettes'],
    'CRLF line ends' => ["# Palettes — design\r\n\r\ntext\r\n", null, 'Implement: Palettes'],
    'no H1: the branch' => ["## Problem\n\ntext\n", 7, 'Implement: feature/x (issue: #7)'],
    'an empty spec: the branch' => ['', null, 'Implement: feature/x'],
]);

it('writes a new body that names the spec and the plan, and the issue without a closing keyword', function () {
    expect(pipeline_handoff_body('docs/spec.md', 'docs/plan.md', 125))
        ->toBe("Implements the design in `docs/spec.md`.\nPlan: `docs/plan.md`.\n\nPart of #125.");
    expect(pipeline_handoff_body('docs/spec.md', 'docs/plan.md', null))
        ->toBe("Implements the design in `docs/spec.md`.\nPlan: `docs/plan.md`.");
});

it('only ever adds to an existing body, and leaves one that names the spec, the plan and the issue alone', function (string $body, ?int $issue, ?string $updated) {
    expect(pipeline_handoff_body_update($body, 'docs/spec.md', 'docs/plan.md', $issue))->toBe($updated);
})->with([
    'names all three' => ["Implements the design in `docs/spec.md`.\nPlan: `docs/plan.md`.\n\nPart of #125.", 125, null],
    'a Closes written by a later leg gains no Part of' => ["Spec docs/spec.md, plan docs/plan.md.\n\nCloses #125.\n\nHalted: CI red.", 125, null],
    'names neither path' => ['Opened by hand.', 125, "Implements the design in `docs/spec.md`.\nPlan: `docs/plan.md`.\n\nPart of #125.\n\nOpened by hand."],
    'names one path' => ['See docs/spec.md. Part of #125.', 125, "Implements the design in `docs/spec.md`.\nPlan: `docs/plan.md`.\n\nSee docs/spec.md. Part of #125."],
    'names the paths, not the issue' => ['docs/spec.md and docs/plan.md', 125, "Part of #125.\n\ndocs/spec.md and docs/plan.md"],
    'another number that starts the same' => ['docs/spec.md and docs/plan.md, after #1250', 125, "Part of #125.\n\ndocs/spec.md and docs/plan.md, after #1250"],
    'no issue on the run' => ['docs/spec.md and docs/plan.md', null, null],
    'an empty body' => ['', 125, "Implements the design in `docs/spec.md`.\nPlan: `docs/plan.md`.\n\nPart of #125."],
]);

it('finds the Component target on a valid board with a default, a note where the default cannot be used, and nothing otherwise', function (array $board, array|string|null $target) {
    expect(pipeline_handoff_component($board))->toBe($target);
})->with([
    'a default and a field' => [
        ['state' => 'valid', 'board' => ['number' => '7', 'component-field-id' => 'CF_1', 'component-default' => 'Deploy=OPT_1'], 'error' => null],
        ['name' => 'Deploy', 'field' => 'CF_1', 'option' => 'OPT_1'],
    ],
    'no default' => [['state' => 'valid', 'board' => ['number' => '7', 'component-field-id' => 'CF_1'], 'error' => null], null],
    'a default without =' => [
        ['state' => 'valid', 'board' => ['number' => '7', 'component-field-id' => 'CF_1', 'component-default' => 'Deploy'], 'error' => null],
        'the Component was not set: `component-default` is not `<Name>=<option id>`',
    ],
    'a default without a field id' => [
        ['state' => 'valid', 'board' => ['number' => '7', 'component-default' => 'Deploy=OPT_1'], 'error' => null],
        'the Component was not set: `component-default` needs a `component-field-id`',
    ],
    'an absent board' => [['state' => 'absent', 'board' => [], 'error' => null], null],
]);

it('creates when the branch has no open PR, adopts its one draft, and halts on a ready one or on several', function () {
    expect(pipeline_handoff_choice([]))->toBeNull();
    expect(pipeline_handoff_choice([handoff_listed()]))->toBe(handoff_listed());
    expect(pipeline_handoff_choice([handoff_listed(['isDraft' => false])]))
        ->toBe('PR #7 is not a draft; a run only works on a draft PR (`gh pr ready --undo 7` first)');
    expect(pipeline_handoff_choice([handoff_listed(), handoff_listed(['number' => 8, 'baseRefName' => 'feature/integration'])]))
        ->toBe('the branch has more than one open PR (#7 into main, #8 into feature/integration): close all but one');
});

it('keeps git\'s and gh\'s words JSON-safe, and names the exit code when they said nothing', function () {
    expect(pipeline_handoff_words("  HTTP 502: Bad Gateway\n", 1))->toBe('HTTP 502: Bad Gateway');
    expect(pipeline_handoff_words('', 128))->toBe('exit 128');
    expect(json_encode(pipeline_handoff_words("HTTP 502 \xff", 1)))->not->toBeFalse();
});
```

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter HandoffTest`
Expected: FAIL, `Call to undefined function pipeline_handoff_title()` (and the others).

- [ ] **Step 3: Create `handoff.php` with the pure functions**

```php
<?php

/**
 * The `handoff` step (`../references/engine.md` §Stations): push the branch, open the draft PR or adopt
 * the one the branch has, set the board Component. The decisions are pure; `pipeline_handoff()` runs them
 * over two runners, and `dispatch_cli.php handoff` records what it returns or the halt it throws.
 */

require_once __DIR__ . '/board.php';
require_once __DIR__ . '/dispatch.php';
require_once __DIR__ . '/manifest.php';

/** What every read of the run's PR asks gh for. */
const PIPELINE_HANDOFF_PR_FIELDS = 'number,url,state,isDraft,baseRefName,headRefName,body';

/** Why the step stops; `dispatch_cli_handoff()` records it as the halt's reason. */
final class PipelineHandoffHalt extends RuntimeException
{
}

/** git's or gh's words for a reason or a note: trimmed and valid UTF-8, so the manifest and the answer stay JSON. */
function pipeline_handoff_words(string $words, int $code): string
{
    $words = mb_scrub(trim($words));

    return $words === '' ? "exit {$code}" : $words;
}

/** `Implement: <the spec's first H1, less its design suffix> (issue: #<n>)`; a spec without an H1 takes the branch. */
function pipeline_handoff_title(string $spec, string $branch, ?int $issue): string
{
    $heading = preg_match('/^#[ \t]+(.+?)\s*$/m', $spec, $match) === 1
        ? preg_replace('/\s+[—-]\s+design$/u', '', $match[1])
        : $branch;

    return "Implement: {$heading}" . ($issue === null ? '' : " (issue: #{$issue})");
}

/** A new PR's body: what an empty one gains. */
function pipeline_handoff_body(string $spec, string $plan, ?int $issue): string
{
    return (string) pipeline_handoff_body_update('', $spec, $plan, $issue);
}

/**
 * An existing body with the lines it lacks put in front, or null when it names the spec, the plan and the
 * issue: a body is only ever added to, so what `implement` or the finish step wrote stays. `Part of` is
 * the non-closing form (`../references/engine.md` §Closing links).
 */
function pipeline_handoff_body_update(string $body, string $spec, string $plan, ?int $issue): ?string
{
    $design = str_contains($body, $spec) && str_contains($body, $plan)
        ? null
        : "Implements the design in `{$spec}`.\nPlan: `{$plan}`.";
    $link = $issue === null || preg_match("/#{$issue}(?!\d)/", $body) === 1 ? null : "Part of #{$issue}.";
    $added = array_filter([$design, $link]);

    return $added === [] ? null : trim(implode("\n\n", [...$added, trim($body)]));
}

/**
 * Where the PR's Component goes, from `pipeline_repo_board()`'s answer: the field and the option, a note
 * (a string) when the section names a default it cannot use, or null when the repo sets none.
 *
 * @return array{name: string, field: string, option: string}|string|null
 */
function pipeline_handoff_component(array $board): array|string|null
{
    $default = $board['board']['component-default'] ?? null;
    if ($board['state'] !== 'valid' || $default === null) {
        return null;
    }
    [$name, $option] = array_map(trim(...), explode('=', $default, 2) + [1 => '']);
    if ($name === '' || $option === '') {
        return 'the Component was not set: `component-default` is not `<Name>=<option id>`';
    }
    $field = $board['board']['component-field-id'] ?? null;

    return $field === null
        ? 'the Component was not set: `component-default` needs a `component-field-id`'
        : ['name' => $name, 'field' => $field, 'option' => $option];
}

/**
 * What to do with the open PRs gh lists for the branch: null to create one, the PR to adopt, or the
 * reason to halt (a string): one that is not a draft, in the invariant check's words, or more than one.
 */
function pipeline_handoff_choice(array $prs): array|string|null
{
    if ($prs === []) {
        return null;
    }
    if (count($prs) > 1) {
        $named = array_map(fn (array $pr) => "#{$pr['number']} into {$pr['baseRefName']}", $prs);

        return 'the branch has more than one open PR (' . implode(', ', $named) . '): close all but one';
    }

    return pipeline_pr_problem($prs[0]['number'], $prs[0]) ?? $prs[0];
}
```

In `tests/Pest.php` add `'handoff.php'` after `'kickoff.php'` in the list of files.

- [ ] **Step 4: Run the tests**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter HandoffTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/handoff.php skills/pipeline/checks/tests/HandoffTest.php skills/pipeline/checks/tests/Pest.php
git commit -m "feat(pipeline): handoff.php decides the PR's title, its body, the Component target and which PR to adopt (#125)"
```

---

### Task 4: The step and the `handoff` command

**Files:**
- Modify: `skills/pipeline/checks/handoff.php` (append the step)
- Modify: `skills/pipeline/checks/dispatch_cli.php:1-36` (header, requires), `:735-778`
  (`dispatch_cli_record()`), `:869-890` (the `match`, the usage line)
- Test: `skills/pipeline/checks/tests/HandoffCliTest.php` (create),
  `skills/pipeline/checks/tests/RecordCliTest.php` (unchanged, stays green)

**Interfaces:**
- Consumes: Task 1's `pipeline_gh_run()` and `pipeline_board_set()`; Task 3's functions;
  `dispatch_cli_record(string $manifestPath, string $leg, string $step, array $given): array`,
  `dispatch_cli_write_problem()`, `dispatch_cli_snapshot_step()`, `dispatch_cli_git(array $manifest): Closure`,
  `dispatch_cli_refuse()`, `pipeline_retired_mode()`; the test helpers `base_repo()`, `base_moves()`,
  `record_fixture()`, `dispatch_fixture()`, `dispatch_cli()`, `boundary_brief()`, `rereview_commit()`.
- Produces:
  - `pipeline_handoff(array $manifest, callable $git, callable $gh): array` →
    `['pr' => int, 'url' => string, 'created' => bool, 'notes' => list<string>]`, or throws
    `PipelineHandoffHalt`.
  - `dispatch_cli_step_problem(string $manifestPath, string $leg, string $step): ?string`
  - `dispatch_cli_handoff(string $manifestPath): array`, reached as `dispatch_cli.php handoff <manifest>`.

- [ ] **Step 1: `record`'s opening checks become one function (a refactor, tests stay green)**

In `dispatch_cli.php`, above `dispatch_cli_record()`:

```php
/** Why `$leg` `$step` may not write at `$manifestPath`, or null: `dispatch_cli_write_problem()`, and the snapshot beside it is this step's. */
function dispatch_cli_step_problem(string $manifestPath, string $leg, string $step): ?string
{
    $problem = dispatch_cli_write_problem($manifestPath);
    if ($problem !== null) {
        return $problem;
    }
    $snapshotPath = manifest_files($manifestPath)['before'];
    $snapshot = dispatch_cli_snapshot_step(manifest_read($snapshotPath));

    return match (true) {
        $snapshot === "{$leg}:{$step}" => null,
        $snapshot === null => "no snapshot at {$snapshotPath}: record follows this step's brief (next in interactive)",
        default => 'the snapshot is of the ' . str_replace(':', ' ', $snapshot) . " step, not {$leg} {$step}",
    };
}
```

`dispatch_cli_record()` opens with it; everything from `$given = dispatch_cli_record_files(…)` on stays:

```php
    $problem = dispatch_cli_step_problem($manifestPath, $leg, $step);
    if ($problem !== null) {
        return dispatch_cli_refuse($problem);
    }
    $snapshotPath = manifest_files($manifestPath)['before'];
    $before = (array) manifest_read($snapshotPath);
```

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter RecordCliTest`
Expected: PASS, unchanged.

- [ ] **Step 2: The fixture and the first failing test**

Create `skills/pipeline/checks/tests/HandoffCliTest.php`:

```php
<?php

/**
 * A fake gh in `{$root}/bin`, written in PHP so every call's argv is logged as it was given (one JSON line
 * per call in `{$root}/calls`). It keeps the repo's PRs in `{$root}/prs.json`: `pr list` and `pr view`
 * read it, `pr create` and `pr edit` write it. A file `{$root}/<first two words, dashed>-fails` makes
 * that subcommand fail with the file's content on stderr; `pr-create-vanishes` makes `pr create` succeed
 * without the PR showing up.
 *
 * @return array{PATH: string, GH_FAKE: string} the environment that puts it first on PATH
 */
function handoff_gh(string $root, array $prs = []): array
{
    mkdir("{$root}/bin");
    file_put_contents("{$root}/prs.json", json_encode($prs));
    file_put_contents("{$root}/bin/gh", '#!' . PHP_BINARY . "\n" . <<<'PHP'
<?php
$root = getenv('GH_FAKE');
$args = array_slice($argv, 1);
file_put_contents("{$root}/calls", json_encode($args) . "\n", FILE_APPEND);
$command = implode(' ', array_slice($args, 0, 2));
$fails = "{$root}/" . str_replace(' ', '-', $command) . '-fails';
if (is_file($fails)) {
    fwrite(STDERR, file_get_contents($fails));
    exit(1);
}
$flag = fn (string $name) => in_array($name, $args, true) ? $args[array_search($name, $args, true) + 1] : null;
$prs = json_decode(file_get_contents("{$root}/prs.json"), true);
$save = fn (array $prs) => file_put_contents("{$root}/prs.json", json_encode($prs));
switch ($command) {
    case 'pr list':
        echo json_encode(array_values(array_filter($prs, fn (array $pr) => $pr['state'] === 'OPEN' && $pr['headRefName'] === $flag('--head'))));
        break;
    case 'pr view':
        $found = array_values(array_filter($prs, fn (array $pr) => (string) $pr['number'] === $args[2]));
        if ($found === []) {
            fwrite(STDERR, 'no pull requests found');
            exit(1);
        }
        echo json_encode($found[0]);
        break;
    case 'pr create':
        $number = count($prs) + 7;
        if (! is_file("{$root}/pr-create-vanishes")) {
            $save([...$prs, ['number' => $number, 'url' => "https://github.com/acme/app/pull/{$number}", 'state' => 'OPEN', 'isDraft' => in_array('--draft', $args, true), 'baseRefName' => $flag('--base') ?? 'main', 'headRefName' => $flag('--head'), 'body' => $flag('--body')]]);
        }
        echo "https://github.com/acme/app/pull/{$number}\n";
        break;
    case 'pr edit':
        $change = array_filter(['baseRefName' => $flag('--base'), 'body' => $flag('--body')], fn ($value) => $value !== null);
        $save(array_map(fn (array $pr) => (string) $pr['number'] === $args[2] ? [...$pr, ...$change] : $pr, $prs));
        break;
    case 'project item-add':
        echo '{"id":"ITEM_1"}';
        break;
    case 'project item-edit':
        break;
    default:
        fwrite(STDERR, 'unexpected gh ' . implode(' ', $args));
        exit(1);
}
PHP);
    chmod("{$root}/bin/gh", 0755);

    return ['PATH' => "{$root}/bin:" . getenv('PATH'), 'GH_FAKE' => $root];
}

/** A PR as gh lists it: open, draft, `feature` into `main`. */
function handoff_pr(array $overrides = []): array
{
    return ['number' => 7, 'url' => 'https://github.com/acme/app/pull/7', 'state' => 'OPEN', 'isDraft' => true, 'baseRefName' => 'main', 'headRefName' => 'feature', 'body' => '', ...$overrides];
}

/**
 * An autoflow run on `handoff:run`, briefed, for issue 125: `base_repo()`'s clone of a bare origin on
 * `feature` with a committed spec and plan, `$config` as its `.claude/work-on.config.md` when given, and
 * the fake gh holding `$prs`. The config is written before `record_fixture()` commits, so it is committed
 * and pushed with the branch: the command reads it from the worktree either way.
 */
function handoff_fixture(array $manifest = [], string $config = '', array $prs = []): array
{
    $dir = base_repo();
    $env = handoff_gh(dirname($dir), $prs);
    if ($config !== '') {
        mkdir("{$dir}/.claude");
        file_put_contents("{$dir}/.claude/work-on.config.md", $config);
    }
    $fixture = record_fixture('handoff', 'run', [
        'branch' => 'feature',
        'artifacts' => ['spec' => 'spec.md', 'plan' => 'plan.md', 'pr' => null, 'issue' => 125],
        ...$manifest,
    ], dir: $dir);

    return [...$fixture, 'root' => dirname($dir), 'env' => $env];
}

function handoff(array $fixture): array
{
    return dispatch_cli(['handoff', $fixture['manifest']], $fixture['env']);
}

/** @return list<list<string>> the argv of each call of the fake gh whose first two words are `$command`, in order */
function handoff_calls(array $fixture, string $command): array
{
    $path = "{$fixture['root']}/calls";
    $calls = is_file($path) ? array_map(fn (string $line) => json_decode($line, true), file($path, FILE_IGNORE_NEW_LINES)) : [];

    return array_values(array_filter($calls, fn (array $args) => implode(' ', array_slice($args, 0, 2)) === $command));
}

function handoff_pushed(array $fixture): bool
{
    return pipeline_git_run($fixture['repo'], ['ls-remote', '--exit-code', '--heads', 'origin', 'feature'])[0] === 0;
}

function handoff_board(): string
{
    return implode("\n", ['## Board', '- org: acme', '- number: 7', '- project-id: PVT_1', '- status-field-id: F_1', '- in-progress-option-id: O_1', '- component-field-id: CF_1', '- component-default: Deploy=OPT_1', '']);
}

const HANDOFF_BODY = "Implements the design in `spec.md`.\nPlan: `plan.md`.\n\nPart of #125.";

it('pushes the branch, opens the draft PR and records it, and the next brief accepts the run', function () {
    $fixture = handoff_fixture();
    $head = pipeline_git($fixture['repo'], ['rev-parse', 'HEAD']);

    $result = handoff($fixture);

    expect($result['code'])->toBe(0);
    expect($result['json'])->toBe([
        'action' => 'recorded', 'leg' => 'handoff', 'step' => 'run', 'status' => 'continued', 'last_sha' => $head, 'entry' => null, 'replaced' => [],
        'pr' => 7, 'url' => 'https://github.com/acme/app/pull/7', 'created' => true, 'notes' => [],
    ]);
    expect(pipeline_git($fixture['repo'], ['rev-parse', 'origin/feature']))->toBe($head);
    expect(handoff_calls($fixture, 'pr create'))->toBe([['pr', 'create', '--draft', '--head', 'feature', '--title', 'Implement: x (issue: #125)', '--body', HANDOFF_BODY]]);
    expect(handoff_calls($fixture, 'project item-add'))->toBe([]);
    expect(manifest_read($fixture['manifest']))->toMatchArray(['last_sha' => $head, 'cursor' => ['leg' => 'handoff', 'status' => 'continued']]);
    expect(manifest_read($fixture['manifest'])['artifacts']['pr'])->toBe(7);
    expect(boundary_brief($fixture, 'implement', 'run', 'handoff:run', ['--status', 'continued'])['stdout'])->toContain('`implement` leg, `run` step');
});
```

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter HandoffCliTest`
Expected: FAIL: exit code 1 where 0 is expected (`handoff` is not a command yet, so the usage line is
printed).

- [ ] **Step 3: Append the step to `handoff.php`**

```php

/**
 * The step, in the order that pushes nothing a halt would leave behind: the preflight and the PR lookup
 * read only, then the push, then the PR, then the Component. `$git` and `$gh` run git in the worktree and
 * gh from it, each `array $args → [code, out, err]`. Every outward act is idempotent, so the step can run
 * again after any failure.
 *
 * @return array{pr: int, url: string, created: bool, notes: list<string>}
 *
 * @throws PipelineHandoffHalt
 */
function pipeline_handoff(array $manifest, callable $git, callable $gh): array
{
    $branch = (string) $manifest['branch'];
    [$spec, $plan] = pipeline_handoff_design($manifest, $git);
    pipeline_handoff_on_branch($branch, $git);
    $board = pipeline_handoff_board($manifest);
    $existing = pipeline_handoff_find($manifest, $gh);
    pipeline_handoff_push($branch, $git);

    $issue = isset($manifest['artifacts']['issue']) ? (int) $manifest['artifacts']['issue'] : null;
    $base = $manifest['base'] ?? null;
    $title = pipeline_handoff_title($git(['show', "HEAD:{$spec}"])[1], $branch, $issue);
    $pr = $existing ?? pipeline_handoff_create($branch, $base, $title, pipeline_handoff_body($spec, $plan, $issue), $gh);
    $aligned = $existing === null ? [] : pipeline_handoff_align($existing, $base, $spec, $plan, $issue, $gh);

    return [
        'pr' => (int) $pr['number'],
        'url' => (string) $pr['url'],
        'created' => $existing === null,
        'notes' => [...$aligned, ...pipeline_handoff_component_notes($board, $pr, $gh)],
    ];
}

/** @return array{0: string, 1: string} the spec and the plan as git names them: both set, both at `HEAD` */
function pipeline_handoff_design(array $manifest, callable $git): array
{
    $paths = [];
    foreach (['spec', 'plan'] as $name) {
        $path = $manifest['artifacts'][$name] ?? null;
        if (! is_string($path) || $path === '') {
            throw new PipelineHandoffHalt("artifacts.{$name} is not set: handoff names the spec and the plan in the PR");
        }
        $path = pipeline_relative_path((string) $manifest['worktree'], $path);
        if ($git(['cat-file', '-e', "HEAD:{$path}"])[0] !== 0) {
            throw new PipelineHandoffHalt("the {$name} {$path} does not exist at HEAD: commit it first");
        }
        $paths[] = $path;
    }

    return $paths;
}

function pipeline_handoff_on_branch(string $branch, callable $git): void
{
    [$code, $head, $err] = $git(['rev-parse', '--abbrev-ref', 'HEAD']);
    if ($code !== 0) {
        throw new PipelineHandoffHalt("the worktree's branch cannot be read: " . pipeline_handoff_words($err, $code));
    }
    if ($head !== $branch) {
        throw new PipelineHandoffHalt("the worktree is on {$head}, not on the run's branch {$branch}");
    }
}

/** `pipeline_repo_board()` over the worktree's config; a repo without the file has no board, and an `invalid` section halts as it does at kickoff. */
function pipeline_handoff_board(array $manifest): array
{
    $config = rtrim((string) $manifest['worktree'], '/') . '/.claude/work-on.config.md';
    $board = pipeline_repo_board(is_file($config) ? (string) file_get_contents($config) : '');
    if ($board['state'] === 'invalid') {
        throw new PipelineHandoffHalt("the ## Board section is invalid: {$board['error']}");
    }

    return $board;
}

/**
 * The run's PR when it has one, or null when one is to be created: the recorded PR, which must be an open
 * draft on this branch, else the one open PR of the branch (#118), which must be a draft.
 */
function pipeline_handoff_find(array $manifest, callable $gh): ?array
{
    $branch = (string) $manifest['branch'];
    $known = $manifest['artifacts']['pr'] ?? null;
    if ($known === null) {
        $choice = pipeline_handoff_choice(pipeline_handoff_open_prs($branch, $gh));

        return is_string($choice) ? throw new PipelineHandoffHalt($choice) : $choice;
    }
    [$code, $out] = $gh(['pr', 'view', (string) $known, '--json', PIPELINE_HANDOFF_PR_FIELDS]);
    $view = $code === 0 ? json_decode($out, true) : null;
    $view = is_array($view) ? $view : null;
    $problem = pipeline_pr_problem($known, $view);
    if ($problem !== null) {
        throw new PipelineHandoffHalt($problem);
    }
    $head = (string) ($view['headRefName'] ?? '');
    if ($head !== $branch) {
        throw new PipelineHandoffHalt("PR #{$known} is for the branch {$head}, not the run's branch {$branch}");
    }

    return $view;
}

/** The open PRs whose head is the branch. A gh that cannot list halts: a run that cannot tell whether a PR exists never creates one. */
function pipeline_handoff_open_prs(string $branch, callable $gh): array
{
    [$code, $out, $err] = $gh(['pr', 'list', '--head', $branch, '--state', 'open', '--json', PIPELINE_HANDOFF_PR_FIELDS]);
    $prs = $code === 0 ? json_decode($out, true) : null;
    if (! is_array($prs) || ! array_is_list($prs)) {
        throw new PipelineHandoffHalt("gh could not list the open PRs of {$branch}: " . pipeline_handoff_words($err, $code));
    }

    return $prs;
}

/** The branch is named because kickoff leaves it without an upstream. Never forced. */
function pipeline_handoff_push(string $branch, callable $git): void
{
    [$code, , $err] = $git(['push', '-u', 'origin', $branch]);
    if ($code !== 0) {
        throw new PipelineHandoffHalt("git refused the push of {$branch}: " . pipeline_handoff_words($err, $code));
    }
}

/** Opens the draft PR and reads it back: its number, URL and base come from gh's listing, never from what `create` printed. */
function pipeline_handoff_create(string $branch, ?string $base, string $title, string $body, callable $gh): array
{
    [$code, , $err] = $gh(['pr', 'create', '--draft', '--head', $branch, ...($base === null ? [] : ['--base', $base]), '--title', $title, '--body', $body]);
    if ($code !== 0) {
        throw new PipelineHandoffHalt('gh could not open the PR: ' . pipeline_handoff_words($err, $code) . '; the branch is pushed, and the next run of this step opens or adopts it');
    }
    $pr = pipeline_handoff_choice(pipeline_handoff_open_prs($branch, $gh));

    return match (true) {
        is_array($pr) => $pr,
        $pr === null => throw new PipelineHandoffHalt("gh opened a PR for {$branch} but does not list it yet; the next run of this step adopts it"),
        default => throw new PipelineHandoffHalt($pr),
    };
}

/**
 * Brings an adopted or re-used PR in line: into the run's base, and naming the spec, the plan and the
 * issue. Its title stays as it is.
 *
 * @return list<string> notes
 */
function pipeline_handoff_align(array $pr, ?string $base, string $spec, string $plan, ?int $issue, callable $gh): array
{
    $number = (string) $pr['number'];
    $notes = [];
    if ($base !== null && ($pr['baseRefName'] ?? null) !== $base) {
        pipeline_handoff_edit($number, ['--base', $base], $gh);
        $notes[] = "PR #{$number} retargeted to {$base}";
    }
    $body = pipeline_handoff_body_update((string) ($pr['body'] ?? ''), $spec, $plan, $issue);
    if ($body !== null) {
        pipeline_handoff_edit($number, ['--body', $body], $gh);
    }

    return $notes;
}

function pipeline_handoff_edit(string $number, array $change, callable $gh): void
{
    [$code, , $err] = $gh(['pr', 'edit', $number, ...$change]);
    if ($code !== 0) {
        throw new PipelineHandoffHalt("gh could not edit PR #{$number} ({$change[0]}): " . pipeline_handoff_words($err, $code));
    }
}

/**
 * The Component on the PR's board item. Whatever goes wrong is a note, never a halt, as kickoff's claim
 * is: a PR without a Component is a finished handoff.
 *
 * @return list<string>
 */
function pipeline_handoff_component_notes(array $board, array $pr, callable $gh): array
{
    $target = pipeline_handoff_component($board);
    if ($target === null) {
        return [];
    }
    if (is_string($target)) {
        return [$target];
    }
    $error = pipeline_board_set($gh, $board['board'], (string) $pr['url'], $target['field'], $target['option']);

    return [$error === null
        ? "Component {$target['name']} set on board {$board['board']['number']}"
        : 'the Component was not set: ' . mb_scrub($error)];
}
```

- [ ] **Step 4: The command in `dispatch_cli.php`**

Requires, after `require_once __DIR__ . '/kickoff.php';`:

```php
require_once __DIR__ . '/gh.php';
require_once __DIR__ . '/handoff.php';
```

Below `dispatch_cli_record_command()`:

```php
/**
 * The whole `handoff` step (`../references/engine.md` §Stations): `record`'s checks before anything
 * leaves the machine, then `pipeline_handoff()` over the snapshot `record` builds on, then the step's one
 * write through `dispatch_cli_record()`, for a halt as for the PR. A record refused once the PR is open
 * names the PR: the next run adopts it (#118).
 */
function dispatch_cli_handoff(string $manifestPath): array
{
    $problem = dispatch_cli_step_problem($manifestPath, 'handoff', 'run');
    if ($problem !== null) {
        return dispatch_cli_refuse($problem);
    }
    $manifest = (array) manifest_read(manifest_files($manifestPath)['before']);
    $retired = pipeline_retired_mode((string) $manifest['mode']);
    if ($retired !== null) {
        return dispatch_cli_refuse($retired);
    }
    $worktree = rtrim((string) $manifest['worktree'], '/');
    $record = fn (array $given): array => dispatch_cli_record($manifestPath, 'handoff', 'run', $given);

    try {
        $done = pipeline_handoff($manifest, dispatch_cli_git($manifest), fn (array $args): array => pipeline_gh_run($worktree, $args));
    } catch (PipelineHandoffHalt $halt) {
        $reason = $halt->getMessage();
        $recorded = $record(['status' => LegStatus::Halted->value, 'reason' => $reason]);

        return $recorded['action'] === 'recorded'
            ? [...$recorded, 'reason' => $reason]
            : dispatch_cli_refuse("{$recorded['reason']}; the step halted on: {$reason}");
    }
    $recorded = $record(['status' => LegStatus::Continued->value, 'pr' => (string) $done['pr']]);

    return $recorded['action'] === 'recorded'
        ? [...$recorded, ...$done]
        : dispatch_cli_refuse("{$recorded['reason']}; PR #{$done['pr']} is open and the next run of this step adopts it");
}

/** `handoff <manifest>`; null is a usage error. */
function dispatch_cli_handoff_command(array $arguments): ?array
{
    return count($arguments) === 1 ? dispatch_cli_handoff((string) $arguments[0]) : null;
}
```

In the final `match`, after the `'suite'` arm:

```php
    'handoff' => dispatch_cli_handoff_command(array_slice($argv, 2)),
```

In the usage string, after `suite <manifest> --outcome green|red --passed <n> --failed <n>`, add
` | handoff <manifest>` (before the parenthesis).

In the file's header comment, under `both:`, add the line
` *                 php dispatch_cli.php handoff <manifest>` and change *"`record` and `suite` exit 1 on
a refusal"* to *"`record`, `suite` and `handoff` exit 1 on a refusal"*.

- [ ] **Step 5: Run the first test**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter HandoffCliTest`
Expected: PASS.

- [ ] **Step 6: The remaining cases**

Append to `HandoffCliTest.php`. They exercise code Step 3 and 4 already hold, so each is expected to pass
as written; a failure is a defect in that code, to fix there. The halt dataset hands each case a closure
that builds its fixture; the test's parameter is typed `Closure` so that Pest passes it on instead of
calling it as a bound dataset.

```php
it('adopts the open draft PR the branch already has, instead of opening a second one (#118)', function () {
    $fixture = handoff_fixture(prs: [handoff_pr(['body' => 'Opened by hand.'])]);

    $result = handoff($fixture);

    expect($result['json'])->toMatchArray(['action' => 'recorded', 'status' => 'continued', 'pr' => 7, 'created' => false, 'notes' => []]);
    expect(handoff_calls($fixture, 'pr create'))->toBe([]);
    expect(handoff_calls($fixture, 'pr edit'))->toBe([['pr', 'edit', '7', '--body', HANDOFF_BODY . "\n\nOpened by hand."]]);
    expect(handoff_pushed($fixture))->toBeTrue();
    expect(manifest_read($fixture['manifest'])['artifacts']['pr'])->toBe(7);
});

it('runs again over the PR the manifest knows: pushed, its body left as it is, recorded again', function () {
    $body = "Implements the design in `spec.md`.\nPlan: `plan.md`.\n\nCloses #125.\n\nHalted: CI red.";
    $fixture = handoff_fixture(['artifacts' => ['spec' => 'spec.md', 'plan' => 'plan.md', 'pr' => 7, 'issue' => 125]], prs: [handoff_pr(['body' => $body])]);

    $result = handoff($fixture);

    expect($result['json'])->toMatchArray(['action' => 'recorded', 'status' => 'continued', 'pr' => 7, 'created' => false]);
    expect(handoff_calls($fixture, 'pr view'))->toBe([['pr', 'view', '7', '--json', PIPELINE_HANDOFF_PR_FIELDS]]);
    expect(handoff_calls($fixture, 'pr list'))->toBe([]);
    expect(handoff_calls($fixture, 'pr create'))->toBe([]);
    expect(handoff_calls($fixture, 'pr edit'))->toBe([]);
    expect(handoff_pushed($fixture))->toBeTrue();
});

it('opens the PR of a run on a base into that base, and retargets one that exists', function () {
    $created = handoff_fixture(['base' => 'feature/integration']);
    expect(handoff($created)['json'])->toMatchArray(['status' => 'continued', 'created' => true, 'notes' => []]);
    expect(handoff_calls($created, 'pr create')[0])->toBe(['pr', 'create', '--draft', '--head', 'feature', '--base', 'feature/integration', '--title', 'Implement: x (issue: #125)', '--body', HANDOFF_BODY]);

    $existing = handoff_fixture(['base' => 'feature/integration'], prs: [handoff_pr(['body' => HANDOFF_BODY])]);
    expect(handoff($existing)['json'])->toMatchArray(['status' => 'continued', 'created' => false, 'notes' => ['PR #7 retargeted to feature/integration']]);
    expect(handoff_calls($existing, 'pr edit'))->toBe([['pr', 'edit', '7', '--base', 'feature/integration']]);
});

it('sets the Component on a board that names a default, and carries on with a note when the board does not answer', function () {
    $fixture = handoff_fixture(config: handoff_board());
    expect(handoff($fixture)['json'])->toMatchArray(['status' => 'continued', 'notes' => ['Component Deploy set on board 7']]);
    expect(handoff_calls($fixture, 'project item-add'))->toBe([['project', 'item-add', '7', '--owner', 'acme', '--url', 'https://github.com/acme/app/pull/7', '--format', 'json']]);
    expect(handoff_calls($fixture, 'project item-edit'))->toBe([['project', 'item-edit', '--id', 'ITEM_1', '--project-id', 'PVT_1', '--field-id', 'CF_1', '--single-select-option-id', 'OPT_1']]);

    $failing = handoff_fixture(config: handoff_board());
    file_put_contents("{$failing['root']}/project-item-add-fails", 'HTTP 401: Bad credentials');
    expect(handoff($failing)['json'])->toMatchArray(['action' => 'recorded', 'status' => 'continued', 'pr' => 7, 'notes' => ['the Component was not set: HTTP 401: Bad credentials']]);
});

it('records a halt before anything is pushed', function (Closure $arrange, string $reason) {
    $fixture = $arrange();

    $result = handoff($fixture);

    expect($result['code'])->toBe(0);
    expect($result['json'])->toMatchArray(['action' => 'recorded', 'leg' => 'handoff', 'step' => 'run', 'status' => 'halted']);
    expect($result['json']['reason'])->toStartWith($reason);
    expect(manifest_read($fixture['manifest'])['cursor'])->toMatchArray(['leg' => 'handoff', 'status' => 'halted']);
    expect(manifest_read($fixture['manifest'])['cursor']['reason'])->toStartWith($reason);
    expect(handoff_pushed($fixture))->toBeFalse();
    expect(handoff_calls($fixture, 'pr create'))->toBe([]);
})->with([
    'an invalid board' => [
        fn () => handoff_fixture(config: "## Board\n- org: acme\n"),
        "the ## Board section is invalid: '## Board' is all-or-nothing; missing: number, project-id, status-field-id, in-progress-option-id",
    ],
    'a spec that is not committed' => [
        fn () => handoff_fixture(['artifacts' => ['spec' => 'missing.md', 'plan' => 'plan.md', 'pr' => null, 'issue' => 125]]),
        'the spec missing.md does not exist at HEAD: commit it first',
    ],
    'no plan in the manifest' => [
        fn () => handoff_fixture(['artifacts' => ['spec' => 'spec.md', 'plan' => null, 'pr' => null, 'issue' => 125]]),
        'artifacts.plan is not set: handoff names the spec and the plan in the PR',
    ],
    'a PR that is not a draft' => [
        fn () => handoff_fixture(prs: [handoff_pr(['isDraft' => false])]),
        'PR #7 is not a draft; a run only works on a draft PR (`gh pr ready --undo 7` first)',
    ],
    'two open PRs' => [
        fn () => handoff_fixture(prs: [handoff_pr(), handoff_pr(['number' => 8, 'baseRefName' => 'feature/integration'])]),
        'the branch has more than one open PR (#7 into main, #8 into feature/integration): close all but one',
    ],
    'gh unable to list' => [
        function () {
            $fixture = handoff_fixture();
            file_put_contents("{$fixture['root']}/pr-list-fails", 'HTTP 502: Bad Gateway');

            return $fixture;
        },
        'gh could not list the open PRs of feature: HTTP 502: Bad Gateway',
    ],
    'gh words that are not UTF-8' => [
        function () {
            $fixture = handoff_fixture();
            file_put_contents("{$fixture['root']}/pr-list-fails", "HTTP 502 \xff");

            return $fixture;
        },
        'gh could not list the open PRs of feature: HTTP 502 ',
    ],
    'a known PR that is closed' => [
        fn () => handoff_fixture(['artifacts' => ['spec' => 'spec.md', 'plan' => 'plan.md', 'pr' => 7, 'issue' => 125]], prs: [handoff_pr(['state' => 'CLOSED'])]),
        'PR #7 is closed',
    ],
    'another branch\'s PR' => [
        fn () => handoff_fixture(['artifacts' => ['spec' => 'spec.md', 'plan' => 'plan.md', 'pr' => 7, 'issue' => 125]], prs: [handoff_pr(['headRefName' => 'feature/other'])]),
        "PR #7 is for the branch feature/other, not the run's branch feature",
    ],
    'another branch checked out' => [
        function () {
            $fixture = handoff_fixture();
            pipeline_git($fixture['repo'], ['switch', '-q', '-c', 'other']);

            return $fixture;
        },
        "the worktree is on other, not on the run's branch feature",
    ],
    'a detached HEAD' => [
        function () {
            $fixture = handoff_fixture();
            pipeline_git($fixture['repo'], ['switch', '-q', '--detach']);

            return $fixture;
        },
        "the worktree is on HEAD, not on the run's branch feature",
    ],
]);

it('records a halt with git\'s words when the push is refused, and opens no PR', function () {
    $fixture = handoff_fixture();
    pipeline_git($fixture['repo'], ['push', '-q', 'origin', 'feature']);
    base_moves($fixture['repo'], ['elsewhere.php' => "<?php\n"], 'feature');

    $result = handoff($fixture);

    expect($result['json'])->toMatchArray(['action' => 'recorded', 'status' => 'halted']);
    expect($result['json']['reason'])->toStartWith('git refused the push of feature: ')->toContain('rejected');
    expect(handoff_calls($fixture, 'pr create'))->toBe([]);
});

it('halts when gh opened a PR it does not list yet, with the branch pushed', function () {
    $fixture = handoff_fixture();
    touch("{$fixture['root']}/pr-create-vanishes");

    $result = handoff($fixture);

    expect($result['json'])->toMatchArray(['action' => 'recorded', 'status' => 'halted', 'reason' => 'gh opened a PR for feature but does not list it yet; the next run of this step adopts it']);
    expect(handoff_pushed($fixture))->toBeTrue();
});

it('refuses when the write does not land, names the PR, and adopts it on the next run (#118)', function () {
    $fixture = handoff_fixture();
    chmod($fixture['manifest'], 0444);

    $refused = handoff($fixture);
    chmod($fixture['manifest'], 0644);

    expect($refused['code'])->toBe(1);
    expect($refused['json'])->toBe(['action' => 'refused', 'reason' => "the write did not land at {$fixture['manifest']}; PR #7 is open and the next run of this step adopts it"]);
    expect(manifest_read($fixture['manifest'])['cursor'])->toBe(['leg' => 'handoff', 'status' => 'pending']);

    expect(handoff($fixture)['json'])->toMatchArray(['action' => 'recorded', 'status' => 'continued', 'pr' => 7, 'created' => false]);
    expect(handoff_calls($fixture, 'pr create'))->toHaveCount(1);
    expect(handoff_calls($fixture, 'pr edit'))->toBe([]);
    expect(manifest_read($fixture['manifest'])['artifacts']['pr'])->toBe(7);
});

it('refuses without this step\'s snapshot or on the retired mode, before gh is called', function () {
    $bare = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'handoff', 'status' => 'pending']]);
    $bare = [...$bare, 'root' => $bare['dir'], 'env' => handoff_gh($bare['dir'])];
    $result = handoff($bare);
    expect($result['code'])->toBe(1);
    expect($result['json'])->toBe(['action' => 'refused', 'reason' => "no snapshot at {$bare['before']}: record follows this step's brief (next in interactive)"]);

    $other = record_fixture('implement', 'run');
    $other = [...$other, 'root' => $other['dir'], 'env' => handoff_gh($other['dir'])];
    expect(handoff($other)['json'])->toBe(['action' => 'refused', 'reason' => 'the snapshot is of the implement run step, not handoff run']);

    $retired = dispatch_fixture(['mode' => 'auto', 'cursor' => ['leg' => 'handoff', 'status' => 'pending']]);
    copy($retired['manifest'], $retired['before']);
    $retired = [...$retired, 'root' => $retired['dir'], 'env' => handoff_gh($retired['dir'])];
    expect(handoff($retired)['json']['reason'])->toStartWith('mode auto was removed');

    foreach ([$bare, $other, $retired] as $fixture) {
        expect(is_file("{$fixture['root']}/calls"))->toBeFalse();
    }
    expect(dispatch_cli(['handoff'])['code'])->toBe(1);
    expect(dispatch_cli(['handoff', $bare['manifest'], 'extra'])['code'])->toBe(1);
});

it('records an interactive handoff without an issue, and returned routes to implement', function () {
    $dir = base_repo();
    $env = handoff_gh(dirname($dir));
    rereview_commit($dir, ['spec.md' => "# x — design\n\n**Design size:** Architectural\n", 'plan.md' => "# x Implementation Plan\n"]);
    $fixture = dispatch_fixture([
        'mode' => 'interactive', 'branch' => 'feature', 'worktree' => $dir,
        'cursor' => ['leg' => 'handoff', 'status' => 'pending'],
        'artifacts' => ['spec' => 'spec.md', 'plan' => 'plan.md', 'pr' => null, 'issue' => null],
    ]);
    $fixture = [...$fixture, 'root' => dirname($dir), 'env' => $env];
    expect(dispatch_cli(['next', $fixture['manifest']])['json'])->toMatchArray(['action' => 'dispatch', 'leg' => 'handoff', 'step' => 'run']);

    expect(handoff($fixture)['json'])->toMatchArray(['action' => 'recorded', 'status' => 'continued', 'pr' => 7, 'created' => true]);
    expect(handoff_calls($fixture, 'pr create'))->toBe([['pr', 'create', '--draft', '--head', 'feature', '--title', 'Implement: x', '--body', "Implements the design in `spec.md`.\nPlan: `plan.md`."]]);
    expect(dispatch_cli(['returned', $fixture['manifest'], $fixture['diff']])['json'])->toMatchArray(['action' => 'dispatch', 'leg' => 'implement', 'step' => 'run']);
});
```

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "HandoffCliTest|RecordCliTest|HandoffTest"`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add skills/pipeline/checks/handoff.php skills/pipeline/checks/dispatch_cli.php skills/pipeline/checks/tests/HandoffCliTest.php
git commit -m "feat(pipeline): dispatch_cli handoff pushes, opens or adopts the draft PR, and records the step itself (#125, #118)"
```

---

### Task 5: The brief names the command

**Files:**
- Modify: `skills/pipeline/checks/brief.php:72-75` (`handoff:run`'s overrides), `:234-237` (the base
  block), `:484-538` (`pipeline_record_commands()`, `pipeline_brief_return()`)
- Test: `skills/pipeline/checks/tests/BriefTest.php:370-374`, `:481-484`, `:500-519`, `:521-537`, `:574-575`

**Interfaces:**
- Consumes: `pipeline_cli(string $command, string $manifestPath): string`, `pipeline_record_table()`.
- Produces: `const PIPELINE_STEP_COMMANDS = ['handoff:run' => 'handoff']`; `pipeline_record_commands()`
  returns the `handoff` command first for that step, then `record` for `plan-insufficient` and `halted`.

- [ ] **Step 1: Rewrite the tests that pin the old lines**

In `BriefTest.php`:

Replace the test *makes handoff on a run on a base check that the PR opened into it* with:

```php
it('gives handoff no line about the base: its command opens the PR into it', function () {
    expect(pipeline_brief(brief_manifest('handoff', ['base' => 'feature/integration']), 'handoff', '/tmp/m.json'))
        ->toContain('- base: `feature/integration`')
        ->not->toContain('The PR must open into')
        ->not->toContain('gh pr edit <pr> --base');
});
```

Replace the test *names the spec and the plan to handoff from the manifest, in both modes* with:

```php
it('has handoff run its command and not the skill, in both modes', function (string $mode) {
    $command = 'php ' . realpath(__DIR__ . '/..') . '/dispatch_cli.php handoff /tmp/m.json';

    expect(pipeline_brief(brief_manifest('handoff', ['mode' => $mode]), 'handoff', '/tmp/m.json', 'run'))
        ->toContain("- Run `{$command}` as its own command: it pushes the branch, opens the draft PR or adopts the one the branch has, and records this step. It is the whole step (engine.md §Stations).")
        ->toContain('- The leg\'s name is not a skill to invoke: do not invoke the `handoff` skill (`/handoff`), which asks the owner a question and posts a prompt comment.')
        ->toContain('- Repair nothing it reports: no force-push, no `gh pr create` or `gh pr edit` by hand. A halt it recorded, a refusal, or a denied command is a halt with that reason.')
        ->not->toContain('handoff pr')
        ->not->toContain('--pr <number>');
})->with(['autoflow', 'interactive']);
```

In the test *ends every brief of both modes on the literal record command, one line per status the step
may return*, replace the body of the `foreach` with:

```php
        $return = explode("## Return\n\n", pipeline_brief(brief_manifest($leg, ['mode' => $mode]), $leg, $path, $step))[1];
        $own = PIPELINE_STEP_COMMANDS["{$leg}:{$step}"] ?? null;
        $statuses = array_column(LegStatus::allowedFor($leg, $step), 'value');

        expect($return)
            ->toContain($own === null
                ? "- `{$command} {$leg} {$step} --status continued"
                : '- `php ' . realpath(__DIR__ . '/..') . "/dispatch_cli.php {$own} {$path}` (records `continued`, or `halted` with its reason)")
            ->toContain('- `… --status halted --reason "<why>"`')
            ->toContain('`feature-x.before.json` beside it is the dispatcher\'s snapshot')
            ->toContain('never find it by a glob')
            ->not->toContain('Never move `cursor.leg`');
        expect(substr_count($return, "\n- `"))->toBe(count($statuses));
        foreach ($own === null ? $statuses : array_diff($statuses, ['continued']) as $status) {
            expect($return)->toContain("--status {$status}");
        }
```

Replace the test *prints the return of a handoff step as its commands, and tells an autoflow step what to
return* with:

```php
it('prints the return of a handoff step as its command, then record for the statuses it does not write', function () {
    $cli = 'php ' . realpath(__DIR__ . '/..') . '/dispatch_cli.php';

    expect(pipeline_brief_return('handoff', 'run', 'autoflow', '/tmp/m.json'))->toBe(
        "## Return\n\n"
        . "Your last act is the `handoff` command, or one `record` command for a status it does not write; only a read-only command your instructions name (`size`, `ui`, the proof page's `open`) comes after it. They are the only way you write the manifest: do not edit the file, and never find it by a glob (`m.before.json` beside it is the dispatcher's snapshot).\n\n"
        . "- `{$cli} handoff /tmp/m.json` (records `continued`, or `halted` with its reason)\n"
        . "- `{$cli} record /tmp/m.json handoff run --status plan-insufficient --reason \"<what the plan lacks>\"`\n"
        . "- `… --status halted --reason \"<why>\"`\n\n"
        . 'Write a `--reason` without double quotes. '
        . 'Each prints `{"action":"recorded",…}`, or `{"action":"refused","reason":…}` with exit 1 and the manifest untouched: a refused `record` names what to fix, then run it again; a refused `handoff` is a halt with its reason. '
        . 'A `record` run again in the same step replaces the earlier one, and its `replaced` then names what that one wrote (`last_sha`, `cursor.status`): that is expected. '
        . 'Return the `status` it printed as your structured `{status, reason}`. When it refuses a `halted`, return `halted` with its reason all the same.'
    );
    expect(pipeline_brief_return('handoff', 'run', 'interactive', '/tmp/m.json'))
        ->toEndWith('that is expected. Take the `status` it printed and reply with one line naming it.');
    expect(pipeline_brief_return('implement', 'run', 'autoflow', '/tmp/m.json'))
        ->toStartWith("## Return\n\nYour last act is one `record` command; only a read-only command")
        ->toContain('It is the only way you write the manifest')
        ->toContain('with exit 1 and the manifest untouched: fix what it names and run it again. ');
});
```

In the test *says what each step passes to record, and describes no JSON*, replace the `handoff`
expectation (the `->toContain('- Invoke `handoff pr`. …')` on `$brief('autoflow', 'handoff', 'run')`) with:

```php
    expect($brief('autoflow', 'handoff', 'run'))
        ->toContain('- Run `php ' . realpath(__DIR__ . '/..') . "/dispatch_cli.php handoff {$path}` as its own command:");
```

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter BriefTest`
Expected: FAIL: `Undefined constant "PIPELINE_STEP_COMMANDS"`, and the brief still holds
`Invoke `handoff pr`` and `The PR must open into`.

- [ ] **Step 3: The overrides**

In `pipeline_leg_overrides()` replace the `'handoff:run'` entry:

```php
        'handoff:run' => [
            'Run `' . pipeline_cli('handoff', $manifestPath) . '` as its own command: it pushes the branch, opens the draft PR or adopts the one the branch has, and records this step. It is the whole step (engine.md §Stations).',
            'The leg\'s name is not a skill to invoke: do not invoke the `handoff` skill (`/handoff`), which asks the owner a question and posts a prompt comment.',
            'Repair nothing it reports: no force-push, no `gh pr create` or `gh pr edit` by hand. A halt it recorded, a refusal, or a denied command is a halt with that reason.',
        ],
```

In `pipeline_brief_overrides()` delete the block

```php
    $base = $manifest['base'] ?? null;
    if ($leg === 'handoff' && $base !== null) {
        $lines[] = "The PR must open into `{$base}`: …";
    }
```

(all four lines; `$base` is used nowhere else in that function).

- [ ] **Step 4: The return**

Above `pipeline_cli()`:

```php
/** The steps a command of their own performs and records: it stands in `## Return` where `record --status continued` would (`../references/engine.md` §Stations). */
const PIPELINE_STEP_COMMANDS = ['handoff:run' => 'handoff'];
```

In `pipeline_record_commands()`, replace the `foreach` line and the `return`:

```php
    $own = PIPELINE_STEP_COMMANDS["{$leg}:{$step}"] ?? null;
    $rows = pipeline_record_table()["{$leg}:{$step}"];
    $lines = [];
    foreach ($own === null ? $rows : array_diff_key($rows, ['continued' => true]) as $status => $row) {
        $flags = [
            ...array_map(fn (string $flag) => $flag === 'reason' ? $reason[$status] : $shown[$flag], $row['required']),
            ...array_map(fn (string $flag) => "[{$shown[$flag]}]" . ($flag === 'issue-link' ? '…' : ''), $row['optional']),
        ];
        $lines[] = ($lines === [] ? pipeline_cli('record', $manifestPath) . " {$leg} {$step}" : '…')
            . rtrim(" --status {$status} " . implode(' ', $flags));
    }

    return [...($own === null ? [] : [pipeline_cli($own, $manifestPath)]), ...$lines];
```

Its docblock's first sentence becomes: *"The literal command for each status the step may return: `record`
from `pipeline_record_table()`, the first in full and the others by what differs, after the step's own
command where it has one (`PIPELINE_STEP_COMMANDS`), which records `continued` itself."*

Replace `pipeline_brief_return()`:

```php
/** The return contract is the commands (`../references/manifest.md` §What a leg writes): `record` writes, the step only passes what it made. */
function pipeline_brief_return(string $leg, string $step, string $mode, string $manifestPath): string
{
    $snapshot = basename(manifest_files($manifestPath)['before']);
    $own = PIPELINE_STEP_COMMANDS["{$leg}:{$step}"] ?? null;
    $commands = array_map(fn (string $command) => "- `{$command}`", pipeline_record_commands($leg, $step, $manifestPath));
    if ($own !== null) {
        $commands[0] .= ' (records `continued`, or `halted` with its reason)';
    }
    [$last, $only, $prints, $refused] = $own === null
        ? ['one `record` command', 'It is', 'It prints', 'fix what it names and run it again']
        : ["the `{$own}` command, or one `record` command for a status it does not write", 'They are', 'Each prints', "a refused `record` names what to fix, then run it again; a refused `{$own}` is a halt with its reason"];
    $reply = $mode === 'autoflow'
        ? 'Return the `status` it printed as your structured `{status, reason}`. When it refuses a `halted`, return `halted` with its reason all the same.'
        : 'Take the `status` it printed and reply with one line naming it.';

    return "## Return\n\n"
        . "Your last act is {$last}; only a read-only command your instructions name (`size`, `ui`, the proof page's `open`) comes after it. {$only} the only way you write the manifest: do not edit the file, and never find it by a glob (`{$snapshot}` beside it is the dispatcher's snapshot).\n\n"
        . implode("\n", $commands) . "\n\n"
        . 'Write a `--reason` without double quotes. '
        . $prints . ' `{"action":"recorded",…}`, or `{"action":"refused","reason":…}` with exit 1 and the manifest untouched: ' . $refused . '. '
        . 'A `record` run again in the same step replaces the earlier one, and its `replaced` then names what that one wrote (`last_sha`, `cursor.status`): that is expected. '
        . $reply;
}
```

- [ ] **Step 5: Run the tests**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "BriefTest|DispatchCliTest|AutoflowScriptTest"`
Expected: PASS. `AutoflowScriptTest`'s replay that records `handoff:run` with `--pr 7` is unchanged:
`record --pr` stays valid.

Run: `grep -n "handoff pr" skills/pipeline/checks/*.php`
Expected: no output.

- [ ] **Step 6: Commit**

```bash
git add skills/pipeline/checks/brief.php skills/pipeline/checks/tests/BriefTest.php
git commit -m "feat(pipeline): handoff's brief names its command, not the skill, and its return prints no --pr (#125)"
```

---

### Task 6: The documents say *command*

**Files:**
- Modify: `skills/pipeline/references/engine.md` (§`autoflow`, §Agents per step, §The work item, §Kickoff,
  §Stations, §What a Bounded design commits, §Escalation, §A plan gap, §Who takes the PR out of draft,
  §What a leg brief consists of, §Catching up with the base, §Failure policy)
- Modify: `skills/pipeline/references/manifest.md` (§What a leg writes)
- Modify: `skills/pipeline/references/gates.md` (§How a run calls Phase A)
- Modify: `skills/pipeline/SKILL.md:13`, `:135`
- Modify: `README.md` (*Permissions for unattended runs*)
- Test: `skills/pipeline/checks/tests/LockStepTest.php` (unchanged; it must stay green)

**Interfaces:**
- Consumes: the command of Task 4, the brief of Task 5. Produces nothing code relies on.

Keep every line within the file's wrap (110 columns in `engine.md` and `manifest.md`, table rows
excepted); rewrap the paragraph you touch. Each step names the text to find by its opening words.

- [ ] **Step 1: `engine.md` §Stations**

Replace the sentence *"The pipeline **invokes** the existing skills; it never reimplements them."* with:

```markdown
The pipeline **invokes** the existing skills; it never reimplements their judgment. One leg is no skill:
`handoff` is a command, `dispatch_cli.php handoff` (`../checks/handoff.php`). Pushing a branch and opening
a draft PR is mechanical, and the `handoff` skill, written for a person who closes a plan cycle, asks a
question and posts a prompt that a run cannot use.
```

Replace the table's `handoff` row with:

```markdown
| **handoff** | `dispatch_cli.php handoff <manifest>` | the same command, run by the dispatched agent | pushes the branch (never forced), opens the **draft PR** or adopts the open draft the branch already has, with `--base` on a run on a base; English title `Implement: <the spec's heading> (issue: #N)` and a body that names the spec and the plan; references the issue **without a closing keyword**, `Part of #N` (§Closing links) — this PR carries no implementation yet; sets the board Component where the repo's `## Board` names a `component-default`; posts no comment | the command records the PR# pointer itself, through `record`'s code |
```

After the table, add:

```markdown
**`handoff` in order.** `record`'s own checks first (the manifest, this step's snapshot), so a refusal
pushes nothing. Then, read-only: `artifacts.spec` and `artifacts.plan` exist at `HEAD`, the worktree is on
the run's branch, the `## Board` section is not `invalid`, and the PR lookup — `artifacts.pr` when set,
else `gh pr list --head <branch> --state open`. A PR that is not an open draft, one on another branch,
more than one, or a gh that cannot answer is a halt, still with nothing pushed. Then the push, then
`gh pr create --draft --head <branch> [--base <base>]` read back through the same listing, or, on an
existing PR, `gh pr edit --base` when its base differs and `gh pr edit --body` when its body lacks the
spec, the plan or the issue: a body is only added to and a title never changed. The Component is two
idempotent board calls, and its failure is a note. The command records `continued` with the PR, or
`halted` with the reason, through `record`'s code; a record refused once the PR is open names the PR, and
the next run adopts it instead of opening a second one (#118). Every outward act is idempotent: after any
failure, running the step again is the repair.
```

- [ ] **Step 2: `engine.md` §Kickoff, *A run on a base***

In the bullet that opens *"**After the create** kickoff checks that the worktree's `HEAD` equals
`origin/<base>`"*, replace everything from *"Then it sets `git config branch.<branch>.gh-merge-base
<base>`"* to the bullet's end with the text below. The bullet ends at `engine.md:401`, where *"leg after
it."* stands on a line of its own (the sentence *"…and look healthy on every leg after it."* wraps):
that line goes too.

```markdown
  Then it writes `base` into the first manifest. `dispatch_cli.php handoff` opens the PR with
  `--base <base>` and retargets a PR the branch already had (`gh pr edit --base`, §Stations): kickoff sets
  no `gh-merge-base` config, and no brief asks a step to check the PR's base.
```

- [ ] **Step 3: `engine.md`, the remaining mentions**

Each is one replacement:

- §`autoflow`, the **A step** bullet: after *"The step records its results with `dispatch_cli.php record`
  (`manifest.md` §What a leg writes)"* insert *" — `handoff:run` excepted, whose command
  `dispatch_cli.php handoff` does the step and records `continued` or `halted` itself (§Stations) —"*
  before *", then returns the status it recorded"*.
- §Agents per step, the `handoff:run` row's *Why*: *"Runs one command (`dispatch_cli.php handoff`) and
  returns the status it printed. Haiku 4.5 has no effort setting: rejected."*
- §The work item: *"the same single source `work-on` and `handoff` read"* becomes *"the same single source
  `work-on` and the pipeline's `handoff` command read"*.
- §What a Bounded design commits: *"`handoff`'s brief names both from the manifest"* becomes *"`handoff`
  reads both from the manifest"*.
- §Escalation, step 4: *"`handoff` re-runs: it pushes and keeps the existing PR."*
- §A plan gap, step 3: *"Then `review-plan`, `handoff pr` (updating the existing PR) and `implement` run
  again"* becomes *"Then `review-plan`, `handoff` (it pushes and keeps the existing PR) and `implement`
  run again"*.
- §Who takes the PR out of draft: the sentence *"The same applies to the prompt `handoff pr` writes into
  the PR comment: its template ends with *"implementation fully done → take the PR out of draft"*, which
  is right for a human resuming the work alone and **wrong** under the pipeline."* becomes *"The same
  applies to the prompt comment a PR opened before `dispatch_cli.php handoff` existed may carry, from the
  `handoff` skill: its template ends with *"implementation fully done → take the PR out of draft"*, which
  is right for a human resuming the work alone and **wrong** under the pipeline. The command posts no
  comment."* The `implement` line that follows stays verbatim.
- §What a leg brief consists of, the **return contract** bullet: after *"printed from
  `pipeline_record_table()` (`../checks/record.php`)"* insert *", with `handoff:run`'s own command in the
  place of its `record --status continued` (`PIPELINE_STEP_COMMANDS`)"*.
- §Catching up with the base: replace the paragraph that opens *"**`handoff` takes the spec and the plan
  from the manifest.**"* with: *"**`handoff` takes the spec and the plan from the manifest.** The command
  reads `artifacts.spec` and `artifacts.plan`; it detects nothing from the last commits or the newest
  files, which a merge of the base empties or crowds."*
- §Failure policy, under **Hard failure**, a new sub-bullet after *"**In `autoflow`** a review step…"*:
  *"- **A halt `dispatch_cli.php handoff` recorded** (a refused push, a PR that is not a draft, a gh that
  cannot answer) is a hard failure like any other. A resume runs the command again, and it adopts the PR
  the branch has."*

Run: `grep -n "handoff pr\|gh-merge-base" skills/pipeline/references/engine.md`
Expected: only the two lines written in Step 2 and Step 3 that say no `gh-merge-base` config is set and
that name the old prompt comment; no `handoff pr`.

- [ ] **Step 4: `manifest.md`, `gates.md`, `SKILL.md`**

`manifest.md` §What a leg writes: after the sentence *"The brief's `## Return` prints the step's commands,
and `pipeline_record_table()` holds what each step passes."* add:

```markdown
`handoff`'s write is made by its command, `dispatch_cli.php handoff` (`../checks/handoff.php`), through
`record`'s own code: the same candidate, the same check, the same read-back.
```

`gates.md` §How a run calls Phase A, in the `autoflow` block after the `suite` lines:

```bash
php "$CHECKS/dispatch_cli.php" handoff <manifest>
#   the whole handoff step, in both modes: push, the draft PR (opened or adopted), the Component, its record
# → {"action":"recorded",…,"pr":…,"url":…,"created":…,"notes":[…]} | {"action":"recorded","status":"halted","reason":…,…}
#   | {"action":"refused","reason":…} (exit 1)
```

`SKILL.md:13`: the list becomes *"(`brainstorming`, `writing-plans`, `/critique`, `work-on`,
`browser-verification`)"*. `SKILL.md:135`: *"**Posting to GitHub beyond what the `handoff` command and
`work-on` already do**, and nothing it writes ever addresses a person."*

- [ ] **Step 5: `README.md`, *Permissions for unattended runs***

In the `permissions.allow` block, add a fourth rule after the three merge rules (a comma after
`"Bash(git -C * commit --no-edit)"`):

```json
  "Bash(php * dispatch_cli.php handoff *)"
```

In the section's first paragraph, *"so allow the three commands in `~/.claude/settings.json`"* becomes
*"so allow them, and the `handoff` command below, in `~/.claude/settings.json`"*. Before the paragraph
that opens *"The pipeline skill's suite needs `node` on PATH"*, add:

```markdown
The `handoff` step pushes the branch and calls gh from inside one command,
`php <checks>/dispatch_cli.php handoff <manifest>`, as `kickoff` creates the worktree and edits the board
from inside one `php` call. The push is a run's first outward write, so its rule is listed above with
the merge rules: `Bash(php * dispatch_cli.php handoff *)`. The brief prints the command bare, with no
`cd … &&` in front, which is the form the rule matches. Without the rule a denial halts the step with
the command named; nothing is pushed by then, and a resume after the rule is added runs the command
again.
```

- [ ] **Step 6: Run the whole suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, `LockStepTest` included.

- [ ] **Step 7: Commit**

```bash
git add skills/pipeline/references/engine.md skills/pipeline/references/manifest.md skills/pipeline/references/gates.md skills/pipeline/SKILL.md README.md
git commit -m "docs(pipeline): handoff is a command; engine.md, manifest.md, gates.md, SKILL.md and the README say so (#125)"
```

---

## Self-review notes

- **Spec coverage.** The command and its eight steps: Task 4 (`pipeline_handoff()` and
  `dispatch_cli_handoff()`). The answer and the refusal that names the PR: Task 4. Title and body: Task 3.
  The base, `gh-merge-base` gone: Tasks 2 and 4. The brief and `## Return`: Task 5. Where the code goes
  (`handoff.php`, `gh.php`, the shared board call, `record`'s opening checks as one function): Tasks 1, 3
  and 4. The documents: Task 6. The spec's test list: `HandoffTest` in Task 3, `HandoffCliTest` in Task 4,
  `BriefTest` in Task 5, `DispatchCliTest` in Tasks 1–2; `AutoflowScriptTest`, `RecordTest` and
  `LockStepTest` are untouched and run in Tasks 5–6.
- **Where the plan reads the spec's words narrowly.** `handoff.php` asks its git runner whether the spec
  and the plan exist at `HEAD` instead of calling `dispatch_cli_exists_at()`, and reads the snapshot
  (Assumption 23). A recorded halt answers `record`'s whole line plus `reason` (Assumption 22).
- **Not in this plan, as the spec's *Out of scope* says:** the `handoff` skill and `work-on`'s use of it;
  `plan-insufficient` on `handoff:run`; running the step without an agent; an existing PR's title;
  converting a ready PR to draft; a retry inside the command.
