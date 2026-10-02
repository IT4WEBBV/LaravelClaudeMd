# The prune pass asks GitHub about old-scheme runs by their `repo` — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A run filed under the earlier naming scheme (no `nameWithOwner`, `repo` holding `owner/name`) has its PR
state asked of `gh` by the prune pass, so its status is corrected and, once merged or closed and more than 7 days
past its last filing, it is pruned.

**Architecture:** One pure function in `proof.php`, `proof_run_name_with_owner()`, names the `owner/name` a run's PR
lives in: `nameWithOwner`, else a `repo` of the `owner/name` form, else null. `proof_cli_pr_view()` in `proof_cli.php`
takes its `--repo` from it and still returns null, with no `gh` call, when it is null. Nothing else changes: the
refresh, the amend and the prune rule already do the rest.

**Tech Stack:** PHP 8.4 (no framework), Pest 4 in `skills/pipeline/checks/tests`, a fake `gh` shell script on `PATH`
for the CLI cases.

**Spec:** `docs/superpowers/specs/2026-10-02-pipeline-prune-old-scheme-repo-design.md`. Read it with this plan: the
plan argues from it, and its `## Assumptions` 1–11 are the answers this plan builds on (11 was added by the plan step).

## Global Constraints

- Run every command from the worktree root
  `/Users/jroelofs/GitProjects/LaravelClaudeMd/LaravelClaudeMd/.claude/worktrees/worktree-issue-161-pipeline-the-prune-pass-reads-old-scheme-runs-repo`.
- Tests: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
  (append `--filter '<pattern>'` for a subset). This repo is not a Docker project: Pest runs on the host. The worktree
  has no `vendor/` yet: run `composer install` once first.
- Test-first: write the test, see it fail for the reason given, then write the code. `php -l` every PHP file you
  change.
- The `owner/name` pattern, exactly: `~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~`, matched against the trimmed `repo`.
- `nameWithOwner`, trimmed, wins whenever it is not empty.
- The `gh` call stays an argv array, never a shell string:
  `['gh', 'pr', 'view', <pr>, '--repo', <owner/name>, '--json', 'state,isDraft']`.
- The refresh's amend stays `prState` and `status` only: nothing writes `nameWithOwner` into a stored run.
- **No step writes to the real store** (`~/GitProjects/_proofs`) or runs `prune` or `write` against it. Every case
  sets `PIPELINE_PROOF_ROOT` to a temp store and puts a fake `gh` first on `PATH`.
- No changelog entry: the repo has no `.changelog/` and no `CHANGELOG.md`.
- Commits: no `Co-Authored-By`, no AI attribution; every message ends on `(#161)`.

## Review Focus

1. **A `repo` with whitespace around `owner/name`** (`' IT4WEBBV/Deploy '`, a hand-edited `run.json`) — asked about
   as `IT4WEBBV/Deploy`. Pinned in Task 1's dataset (*whitespace around the old-scheme repo*).
2. **A `repo` that is not a GitHub name** (`IT4WEBBV/Deploy;rm`, `IT4WEBBV/My Deploy`) — never reaches `gh`, the run
   keeps its stored status. Pinned in Task 1's dataset (*a character GitHub rejects*, *whitespace inside*).
3. **`gh` cannot answer for an old-scheme run** (no network, a deleted repo) — the stored state stands and the run is
   not pruned, as for any run. Pinned in Task 2's case *keeps an old-scheme run as it is when gh cannot answer*.
4. **Every run filed today** (`nameWithOwner` set, `repo` a bare name) — asked about exactly as before. Pinned in
   Task 1's dataset (*today's scheme*) and by the existing prune cases, which all set `nameWithOwner` and must keep
   passing unchanged in Task 2.
5. **A bare `repo` and no `nameWithOwner`** (a run whose filing lost both) — no `gh` call, `run.json` byte-for-byte
   unchanged, not pruned. Pinned in Task 2's case *leaves a run with a bare repo and no nameWithOwner alone*.

---

### Task 1: `proof_run_name_with_owner()`, the pure rule

**Files:**
- Modify: `skills/pipeline/checks/proof.php` (add the function directly above the `PROOF_FINISHED_RETENTION_DAYS`
  constant, i.e. beside `proof_should_prune()`)
- Test: `skills/pipeline/checks/tests/ProofTest.php` (add the case directly after
  `it('never prunes on unusable timestamps', …)`)

**Interfaces:**
- Consumes: nothing.
- Produces: `proof_run_name_with_owner(array $run): ?string` — the trimmed `owner/name`, or null. Loaded by
  `tests/Pest.php` (it requires `proof.php`) and by `proof_cli.php` (through `proof_store.php`, which requires
  `proof.php`).

- [ ] **Step 1: Install dependencies (once per worktree)**

Run: `composer install`
Expected: `vendor/bin/pest` exists.

- [ ] **Step 2: Write the failing test**

In `skills/pipeline/checks/tests/ProofTest.php`, after the `'never prunes on unusable timestamps'` case:

```php
it('names the repo a run\'s PR lives in by its nameWithOwner, else by an old-scheme owner/name repo', function (array $run, ?string $nameWithOwner) {
    expect(proof_run_name_with_owner($run))->toBe($nameWithOwner);
})->with([
    'today\'s scheme' => [['nameWithOwner' => 'IT4WEBBV/Deploy', 'repo' => 'Deploy'], 'IT4WEBBV/Deploy'],
    'nameWithOwner wins over an owner/name repo' => [['nameWithOwner' => 'IT4WEBBV/Deploy', 'repo' => 'acme/Other'], 'IT4WEBBV/Deploy'],
    'the old scheme' => [['repo' => 'IT4WEBBV/Deploy'], 'IT4WEBBV/Deploy'],
    'a null nameWithOwner' => [['nameWithOwner' => null, 'repo' => 'IT4WEBBV/Deploy'], 'IT4WEBBV/Deploy'],
    'a blank nameWithOwner' => [['nameWithOwner' => '  ', 'repo' => 'IT4WEBBV/Deploy'], 'IT4WEBBV/Deploy'],
    'whitespace around the old-scheme repo' => [['repo' => ' IT4WEBBV/Deploy '], 'IT4WEBBV/Deploy'],
    'dots, dashes and underscores' => [['repo' => 'it4web-bv/Laravel_Claude.md'], 'it4web-bv/Laravel_Claude.md'],
    'a bare repo' => [['repo' => 'Deploy'], null],
    'two slashes' => [['repo' => 'IT4WEBBV/Deploy/extra'], null],
    'no owner' => [['repo' => '/Deploy'], null],
    'no name' => [['repo' => 'IT4WEBBV/'], null],
    'a character GitHub rejects' => [['repo' => 'IT4WEBBV/Deploy;rm'], null],
    'whitespace inside' => [['repo' => 'IT4WEBBV/My Deploy'], null],
    'neither key' => [[], null],
]);
```

- [ ] **Step 3: Run it to see it fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter 'names the repo a run'`
Expected: FAIL, every dataset row, with `Call to undefined function proof_run_name_with_owner()`.

- [ ] **Step 4: Write the implementation**

In `skills/pipeline/checks/proof.php`, directly above `/** Days a merged or closed run is kept after its last filing. */`:

```php
/**
 * The `owner/name` a run's PR lives in: its `nameWithOwner`, else a `repo` filed under the earlier naming scheme,
 * which held `owner/name` itself (#161). Null for a bare repo name, or anything that is not a GitHub name: there is
 * no PR to ask GitHub about.
 */
function proof_run_name_with_owner(array $run): ?string
{
    $nameWithOwner = trim((string) ($run['nameWithOwner'] ?? ''));
    if ($nameWithOwner !== '') {
        return $nameWithOwner;
    }
    $repo = trim((string) ($run['repo'] ?? ''));

    return preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~', $repo) === 1 ? $repo : null;
}
```

Run: `php -l skills/pipeline/checks/proof.php`
Expected: `No syntax errors detected in skills/pipeline/checks/proof.php`

- [ ] **Step 5: Run it to see it pass**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter 'names the repo a run'`
Expected: PASS, 14 dataset rows.

- [ ] **Step 6: Commit**

```bash
git add skills/pipeline/checks/proof.php skills/pipeline/checks/tests/ProofTest.php
git commit -m "feat(pipeline): name a run's GitHub repo by nameWithOwner, else an old-scheme owner/name repo (#161)"
```

---

### Task 2: the prune pass asks `gh` with it, and engine.md says so

**Files:**
- Modify: `skills/pipeline/checks/proof_cli.php` (`proof_cli_pr_view()` and its docblock)
- Modify: `skills/pipeline/checks/tests/ProofStatusTest.php` (`proof_fake_gh()`, a new fixture helper, four cases)
- Modify: `skills/pipeline/references/engine.md` (§The proof store, the status table's prune-pass row)

**Interfaces:**
- Consumes: `proof_run_name_with_owner(array $run): ?string` from Task 1.
- Produces: `proof_fake_gh(?array $view): array` now returns `['PATH' => …, 'PROOF_GH_LOG' => "{$bin}/argv"]`; the
  fake appends each call's arguments, one per line, to that file. `proof_old_scheme_run(string $root, string
  $updatedAt, array $overrides = []): string` returns the run's directory.

- [ ] **Step 1: Make the fake `gh` log its arguments**

In `skills/pipeline/checks/tests/ProofStatusTest.php`, replace `proof_fake_gh()` whole:

```php
/**
 * A fake `gh` first on PATH that answers `pr view` with `$view`, or fails when it is null. Each call appends its
 * arguments, one per line, to the file `PROOF_GH_LOG` names, so a case reads the exact argv and a missing log proves
 * no call.
 */
function proof_fake_gh(?array $view): array
{
    $bin = sys_get_temp_dir() . '/proof-gh-' . uniqid();
    mkdir($bin);
    file_put_contents("{$bin}/gh", "#!/bin/sh\n" . 'printf \'%s\n\' "$@" >> "$PROOF_GH_LOG"' . "\n" . ($view === null
        ? "echo 'HTTP 502: Bad Gateway' >&2\nexit 1\n"
        : "echo '" . json_encode($view) . "'\n"));
    chmod("{$bin}/gh", 0755);

    return ['PATH' => "{$bin}:" . getenv('PATH'), 'PROOF_GH_LOG' => "{$bin}/argv"];
}
```

The single-quoted PHP string keeps `\n` literal, so the script's line reads `printf '%s\n' "$@" >> "$PROOF_GH_LOG"`.
`proof_status_cli()` hands the env to the `php proof_cli.php` subprocess, and `proc_open()` in `proof_cli_pr_view()`
passes no env of its own, so `gh` inherits `PROOF_GH_LOG` the way it already inherits `PATH`.

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests skills/pipeline/checks/tests/ProofStatusTest.php`
Expected: PASS, every existing case in the file, those that use the fake among them (the refresh dataset, *keeps the
stored status and PR state when gh cannot answer*, both prune cases, *prints its count*): the extra env key and the
log line change nothing for them.

- [ ] **Step 2: Write the fixture helper**

In the same file, directly below `proof_fake_gh()`:

```php
/**
 * A run filed under the earlier naming scheme, as it sits in the store: `repo` holds `owner/name`, no `nameWithOwner`,
 * no stored `status` (#161). Written directly, not through `proof_write_run()`, which would fill in a status.
 */
function proof_old_scheme_run(string $root, string $updatedAt, array $overrides = []): string
{
    $run = [
        'repo' => 'IT4WEBBV/Deploy', 'branch' => 'feature/reverb-service-type', 'pr' => 404, 'prState' => 'OPEN',
        'title' => 'PR #404: reverb service type', 'schema' => 2, 'revision' => 1,
        'createdAt' => $updatedAt, 'updatedAt' => $updatedAt,
        ...$overrides,
    ];
    $dir = "{$root}/" . proof_slug($run['repo']) . '/pr-404-reverb-service-type';
    mkdir($dir, 0777, true);
    file_put_contents("{$dir}/run.json", proof_run_json($run));

    return $dir;
}
```

`proof_slug('IT4WEBBV/Deploy')` is `IT4WEBBV-Deploy`, as the store filed these runs.

- [ ] **Step 3: Write the failing cases**

In the same file, directly after `it('prunes a run gh now reports merged once its last filing is more than a week old', …)`:

```php
it('asks gh about an old-scheme run by its repo, and stores the merge it reports', function () {
    $root = sys_get_temp_dir() . '/proof-store-' . uniqid();
    $dir = proof_old_scheme_run($root, date('c', strtotime('-1 day')));
    $gh = proof_fake_gh(['state' => 'MERGED', 'isDraft' => false]);

    $result = proof_status_cli(['prune'], [...$gh, 'PIPELINE_PROOF_ROOT' => $root]);

    expect(is_file($gh['PROOF_GH_LOG']))->toBeTrue();
    expect(file($gh['PROOF_GH_LOG'], FILE_IGNORE_NEW_LINES))->toBe(['pr', 'view', '404', '--repo', 'IT4WEBBV/Deploy', '--json', 'state,isDraft']);
    expect($result)->toBe(['code' => 0, 'stdout' => "proof: pruned 0 run(s)\n", 'stderr' => '']);
    expect(proof_read_run($dir))->toMatchArray(['prState' => 'MERGED', 'status' => ['state' => 'merged']]);
    expect(file_get_contents("{$dir}/index.html"))->toContain('pill-merged');
    expect(proof_test_status_runs(file_get_contents("{$root}/status.js"))[0])
        ->toMatchArray(['key' => 'IT4WEBBV-Deploy/pr-404-reverb-service-type', 'status' => 'merged']);
});

it('prunes an old-scheme run gh reports merged once its last filing is more than a week old', function () {
    $root = sys_get_temp_dir() . '/proof-store-' . uniqid();
    $dir = proof_old_scheme_run($root, date('c', strtotime('-8 days')));

    $result = proof_status_cli(['prune'], [...proof_fake_gh(['state' => 'MERGED', 'isDraft' => false]), 'PIPELINE_PROOF_ROOT' => $root]);

    expect($result)->toBe(['code' => 0, 'stdout' => "proof: pruned 1 run(s)\n", 'stderr' => '']);
    expect(is_dir($dir))->toBeFalse();
    expect(file_get_contents("{$root}/index.html"))->not->toContain('IT4WEBBV-Deploy/pr-404-reverb-service-type/index.html');
    expect(proof_test_status_runs(file_get_contents("{$root}/status.js")))->toBe([]);
});

it('keeps an old-scheme run as it is when gh cannot answer', function () {
    $root = sys_get_temp_dir() . '/proof-store-' . uniqid();
    $dir = proof_old_scheme_run($root, date('c', strtotime('-8 days')));
    $before = file_get_contents("{$dir}/run.json");
    $gh = proof_fake_gh(null);

    $result = proof_status_cli(['prune'], [...$gh, 'PIPELINE_PROOF_ROOT' => $root]);

    expect(is_file($gh['PROOF_GH_LOG']))->toBeTrue();
    expect(file($gh['PROOF_GH_LOG'], FILE_IGNORE_NEW_LINES))->toBe(['pr', 'view', '404', '--repo', 'IT4WEBBV/Deploy', '--json', 'state,isDraft']);
    expect($result['stdout'])->toBe("proof: pruned 0 run(s)\n");
    expect(file_get_contents("{$dir}/run.json"))->toBe($before);
});

it('leaves a run with a bare repo and no nameWithOwner alone, without asking gh', function () {
    $root = sys_get_temp_dir() . '/proof-store-' . uniqid();
    $dir = proof_old_scheme_run($root, date('c', strtotime('-8 days')), ['repo' => 'Deploy']);
    $before = file_get_contents("{$dir}/run.json");
    $gh = proof_fake_gh(['state' => 'MERGED', 'isDraft' => false]);

    $result = proof_status_cli(['prune'], [...$gh, 'PIPELINE_PROOF_ROOT' => $root]);

    expect(file_exists($gh['PROOF_GH_LOG']))->toBeFalse();
    expect($result['stdout'])->toBe("proof: pruned 0 run(s)\n");
    expect(is_dir($dir))->toBeTrue();
    expect(file_get_contents("{$dir}/run.json"))->toBe($before);
});
```

The bare-repo fixture lands at `{$root}/Deploy/pr-404-reverb-service-type`.

- [ ] **Step 4: Run them to see three fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter 'old-scheme run|bare repo'`
Expected:
- *asks gh about an old-scheme run by its repo*: FAIL at `is_file($gh['PROOF_GH_LOG'])` (false: today's
  `proof_cli_pr_view()` returns null before calling `gh`).
- *prunes an old-scheme run gh reports merged*: FAIL, stdout `proof: pruned 0 run(s)` where `pruned 1` is expected.
- *keeps an old-scheme run as it is when gh cannot answer*: FAIL at `is_file($gh['PROOF_GH_LOG'])`.
- *leaves a run with a bare repo … alone*: PASS already; it guards the null branch through the change.

- [ ] **Step 5: Take the repo from the helper**

In `skills/pipeline/checks/proof_cli.php`, replace the docblock and the head of `proof_cli_pr_view()`, up to and
including the `$argv = …` line:

```php
/**
 * `gh`'s answer on the run's PR, or null when there is none to ask about or `gh` cannot answer: the stored state then
 * stands, and a stale `OPEN` only means the run is not pruned this pass, which is the safe direction. The repo comes
 * from `proof_run_name_with_owner()`, so a run filed before `nameWithOwner` existed is asked about like any other. An
 * argv array, never a shell string.
 *
 * @return array{state: string, isDraft: bool}|null
 */
function proof_cli_pr_view(array $run): ?array
{
    $nameWithOwner = proof_run_name_with_owner($run);
    if (empty($run['pr']) || $nameWithOwner === null) {
        return null;
    }
    $argv = ['gh', 'pr', 'view', (string) $run['pr'], '--repo', $nameWithOwner, '--json', 'state,isDraft'];
```

The rest of the function (the `proc_open()` call onward) stays as it is.

Run: `php -l skills/pipeline/checks/proof_cli.php`
Expected: `No syntax errors detected in skills/pipeline/checks/proof_cli.php`

- [ ] **Step 6: Run them to see all four pass**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter 'old-scheme run|bare repo'`
Expected: PASS, 4 tests.

- [ ] **Step 7: Name the fallback in engine.md**

In `skills/pipeline/references/engine.md`, §The proof store, the status table's prune-pass row, replace:

```markdown
| any | the prune pass, on `prune` | `gh pr view --json state,isDraft`: merged, closed and an open ready PR are GitHub's to say; an open draft keeps `running` or `halted`, and turns a stale `ready` back into `running` |
```

with:

```markdown
| any | the prune pass, on `prune` | `gh pr view --json state,isDraft`: merged, closed and an open ready PR are GitHub's to say; an open draft keeps `running` or `halted`, and turns a stale `ready` back into `running`. A run filed before `nameWithOwner` existed is asked about by its `repo` when that holds `owner/name`; a run with neither keeps its stored status |
```

*Retention* stays as it is: it already says the pass corrects each run's status first.

- [ ] **Step 8: Run the whole suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, no failures, the new dataset case and the four new cases among them.

- [ ] **Step 9: Commit**

```bash
git add skills/pipeline/checks/proof_cli.php skills/pipeline/checks/tests/ProofStatusTest.php skills/pipeline/references/engine.md
git commit -m "fix(pipeline): the prune pass asks gh about old-scheme runs by their owner/name repo (#161)"
```

---

## No browser check

No renderer, style or script changes. An amended old-scheme page is rendered by the same `proof_render_run()` every
amended page goes through, and Task 2's first case asserts its `pill-merged` and its `status.js` entry.
