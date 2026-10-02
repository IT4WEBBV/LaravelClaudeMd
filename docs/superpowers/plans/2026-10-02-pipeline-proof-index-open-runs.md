# The proof index shows open runs by default, sorts, filters and searches, and prunes sooner — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The store index hides merged and closed runs until a toggle shows them, filters by status beside repo,
searches title, PR, branch and client summary, sorts by a header click, shows Updated as `d-m H:i`; the prune pass
removes merged or closed runs 7 days after their last filing and runs without a PR 14 days after it.

**Architecture:** Retention is the pure predicate `proof_should_prune()` in `proof.php`, keyed on
`ProofRunStatus::of($run)` and two named constants. The index stays one static, self-contained page rendered by
`proof_render.php`: PHP computes every value the script compares (status, finished, search text, a `data-sort` key
per cell) and the inline script only compares strings and numbers, so the page still works over `file://` and every
key is tested in PHP.

**Tech Stack:** PHP 8.4 (no framework), Pest 4 in `skills/pipeline/checks/tests`, inline CSS and vanilla JS in the
rendered page, `node --check` for the script's syntax, the Playwright MCP for the browser check.

**Spec:** `docs/superpowers/specs/2026-10-02-pipeline-proof-index-open-runs-design.md`. Read it with this plan: the
plan argues from it, and its `## Assumptions` 1–15 are the answers this plan builds on (15 was added by the plan
step).

## Global Constraints

- Run every command from the worktree root
  `/Users/jroelofs/GitProjects/LaravelClaudeMd/LaravelClaudeMd/.claude/worktrees/worktree-issue-151-pipeline-the-proof-index-shows-open-runs-by`.
- Tests: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
  (append `--filter <pattern>` for a subset). This repo is not a Docker project: Pest runs on the host. The worktree
  has no `vendor/` yet: run `composer install` once first. The suite needs `node` on `PATH`.
- Test-first: write the test, see it fail, then write the code. `php -l` every PHP file you change.
- Retention: `PROOF_FINISHED_RETENTION_DAYS = 7`, `PROOF_NO_PR_RETENTION_DAYS = 14`, measured from `updatedAt`,
  strictly older (`<`). No `pr` → 14 days whatever the status; a `pr` and `ProofRunStatus::of($run)->finished()` →
  7 days; a `pr` and any other status → never. Unparseable `updatedAt` or `$now` → never.
- The index stays one file that opens over `file://`: no CDN, no external asset, every style and script inline.
- Remembered in `localStorage`: `proof:repo`, `proof:status`, `proof:finished` (`1` when the toggle is on). Not
  remembered: the search and the chosen sort column.
- Updated renders `d-m H:i` (day first) in the timestamp's own offset; the script rewrites it to the browser's local
  time `dd-mm HH:MM`, tooltip `YYYY-MM-DD HH:MM:SS`. Never a relative time.
- The empty store renders as today: `No runs recorded.`, no controls, no script.
- The run page (`proof_render_run()`) does not change.
- **No step writes to the real store** (`~/GitProjects/_proofs`) and no step runs `prune` against it. The browser
  check works on a copy.
- No changelog entry: the repo has no `.changelog/` and no `CHANGELOG.md`.
- Commits: no `Co-Authored-By`, no AI attribution; every message ends on `(#151)`.

## Review Focus

1. **A stored status that disagrees with `prState`** (the merge watch wrote `merged` while `gh` could not answer; a
   stored `running` over an old `MERGED`) — the predicate follows the status, as the index does, so the index and the
   prune pass never disagree. Pinned in Task 1's "finished" and "never prunes an open run" datasets.
2. **A run with no PR whose status or `prState` still says something** (halted at `review-plan`, a stale `MERGED`) —
   the 14-day rule applies whatever they say, and the prune pass reaches it without asking `gh`. Pinned in Task 1's
   no-PR dataset and its subprocess case.
3. **An `updatedAt` in another offset than UTC, or one that does not parse** — the cell shows the time in the
   stamp's own offset (not PHP's UTC), and an unparseable one renders an empty cell and an empty `data-sort`, which
   the script sorts last in both directions. Pinned in Task 2's time and `data-sort` cases; the empty-last rule in
   Task 4.
4. **Status filter `Merged` or `Closed` with the toggle off, and a remembered value no longer offered** — the explicit
   status wins and shows those rows; a stale stored value is ignored and `All` stands. Checked in Task 4 (the script
   has no DOM test harness; Task 3 pins the branch in the source and its syntax with `node --check`).
5. **Search text with markup, `#`, capitals and several words** (`#412`, `412`, `Logs follow`) — `data-search` is
   lower-cased and escaped in PHP, every term must occur. Escaping pinned in Task 2's escaping case; matching checked
   in Task 4.

## File Structure

- Modify `skills/pipeline/checks/proof.php`: `ProofRunStatus` gains `finished()` (Task 1) and `order()` (Task 2);
  two constants and `proof_should_prune()` (lines 410–439) are rewritten (Task 1).
- Modify `skills/pipeline/checks/proof_render.php`: `proof_render_styles()` (the `.filter` lines 98–99),
  `proof_render_index()` (564–583), `proof_render_index_filter()` (606–614, replaced), `proof_render_index_row()`
  (616–658), new helpers beside them (Task 2); `proof_render_index_script()` (660–715) rewritten (Task 3).
- Modify `skills/pipeline/checks/tests/ProofTest.php`: the three prune cases (lines 74–104) replaced, two status cases
  added (Tasks 1, 2).
- Modify `skills/pipeline/checks/tests/ProofStatusTest.php`: two prune-pass cases appended (Task 1).
- Modify `skills/pipeline/checks/tests/ProofRenderTest.php`: index cases rewritten and added (Tasks 2, 3).
- Modify `skills/pipeline/references/engine.md` §The proof store: a *Retention* paragraph (Task 1), the *The index*
  paragraph at line 943 (Task 3).
- Modify `docs/superpowers/specs/2026-08-25-pipeline-visual-proof-store-design.md` §7 and §8: a dated note (Task 1).

---

### Task 1: Retention — merged or closed runs after 7 days, runs without a PR after 14

**Files:**
- Modify: `skills/pipeline/checks/proof.php:83-91` (`ProofRunStatus`), `:410-439` (`proof_should_prune()`)
- Modify: `skills/pipeline/references/engine.md:943-948` (a paragraph after *The index*)
- Modify: `docs/superpowers/specs/2026-08-25-pipeline-visual-proof-store-design.md:235`, `:248-251`
- Test: `skills/pipeline/checks/tests/ProofTest.php:74-104`, `skills/pipeline/checks/tests/ProofStatusTest.php` (append)

**Interfaces:**
- Consumes: `ProofRunStatus::of(array $run): ProofRunStatus`; `proof_write_run(string $dir, array $run, string $now): array`
  (sets `updatedAt` to `$now`); test helpers `proof_status_cli(array $arguments, array $env = []): array` and
  `proof_fake_gh(?array $view): array` in `ProofStatusTest.php`.
- Produces: `ProofRunStatus::finished(): bool` (true for Merged and Closed; Task 2 uses it);
  `const PROOF_FINISHED_RETENTION_DAYS = 7; const PROOF_NO_PR_RETENTION_DAYS = 14;`;
  `proof_should_prune(array $run, string $now): bool` (the `$graceDays` parameter is gone; its one caller,
  `proof_cli_prune()`, passes none and is unchanged).

- [ ] **Step 0: Install dependencies (once)**

Run: `composer install`
Expected: `vendor/bin/pest` exists.

- [ ] **Step 1: Write the failing predicate tests**

In `skills/pipeline/checks/tests/ProofTest.php`, delete the three cases
`prunes a run only once its PR is finished and has been finished a while`,
`never prunes an open PR, and never prunes a run that opened none` and `never prunes on unusable timestamps`
(lines 74–104), and put in their place:

```php
it('prunes a finished run with a PR a week after its last filing, by its status or an older run\'s PR state', function (array $run) {
    $now = '2026-10-02T12:00:00+00:00';

    expect(proof_should_prune([...$run, 'pr' => 412, 'updatedAt' => '2026-09-24T12:00:00+00:00'], $now))->toBeTrue();  // 8 days
    // A PR merged this morning is exactly the one still worth looking at this afternoon.
    expect(proof_should_prune([...$run, 'pr' => 412, 'updatedAt' => '2026-09-26T12:00:00+00:00'], $now))->toBeFalse(); // 6 days
    expect(proof_should_prune([...$run, 'pr' => 412, 'updatedAt' => '2026-09-25T12:00:00+00:00'], $now))->toBeFalse(); // exactly 7
})->with([
    'an older merged run' => [['prState' => 'MERGED']],
    'an older closed run' => [['prState' => 'CLOSED']],
    'a merge the watch wrote while gh could not answer' => [['prState' => 'OPEN', 'status' => ['state' => 'merged']]],
    'a stored closed status' => [['status' => ['state' => 'closed']]],
]);

it('never prunes a run with a PR that is not finished, at any age', function (array $run) {
    expect(proof_should_prune([...$run, 'pr' => 412, 'updatedAt' => '2026-09-02T12:00:00+00:00'], '2026-10-02T12:00:00+00:00'))->toBeFalse();
})->with([
    'running' => [['prState' => 'OPEN', 'status' => ['state' => 'running']]],
    'halted' => [['prState' => 'OPEN', 'status' => ['state' => 'halted', 'reason' => 'CI red']]],
    'ready' => [['prState' => 'OPEN', 'status' => ['state' => 'ready']]],
    'an older open run' => [['prState' => 'OPEN']],
    'a stored running status over a merged PR state' => [['prState' => 'MERGED', 'status' => ['state' => 'running']]],
]);

it('prunes a run that opened no PR two weeks after its last filing, whatever its status', function (array $run) {
    $now = '2026-10-02T12:00:00+00:00';

    expect(proof_should_prune([...$run, 'updatedAt' => '2026-09-17T12:00:00+00:00'], $now))->toBeTrue();  // 15 days
    expect(proof_should_prune([...$run, 'updatedAt' => '2026-09-19T12:00:00+00:00'], $now))->toBeFalse(); // 13 days
})->with([
    'no pr key' => [[]],
    'a null pr' => [['pr' => null, 'prState' => null]],
    'an empty pr' => [['pr' => '']],
    'halted before handoff' => [['status' => ['state' => 'halted', 'reason' => 'review-plan bound']]],
    'a stale merged PR state' => [['pr' => null, 'prState' => 'MERGED']],
]);

it('never prunes on unusable timestamps', function () {
    $now = '2026-10-02T12:00:00+00:00';

    expect(proof_should_prune(['pr' => 1, 'prState' => 'MERGED', 'updatedAt' => 'not a date'], $now))->toBeFalse();
    expect(proof_should_prune(['pr' => 1, 'prState' => 'MERGED'], $now))->toBeFalse();
    expect(proof_should_prune(['pr' => 1, 'prState' => 'MERGED', 'updatedAt' => '2026-08-01T12:00:00+00:00'], 'nonsense'))->toBeFalse();
    expect(proof_should_prune(['pr' => null], $now))->toBeFalse();
    expect(proof_should_prune(['updatedAt' => 'not a date'], $now))->toBeFalse();
    expect(proof_should_prune(['updatedAt' => '2026-08-01T12:00:00+00:00'], 'nonsense'))->toBeFalse();
});

it('tells the finished statuses from the open ones', function () {
    expect(array_map(fn (ProofRunStatus $status) => $status->finished(), ProofRunStatus::cases()))->toBe([false, false, false, true, true]);
});
```

(`ProofRunStatus::cases()` is Running, Halted, Ready, Merged, Closed.)

- [ ] **Step 2: Write the failing prune-pass tests**

Append to `skills/pipeline/checks/tests/ProofStatusTest.php`:

```php
it('prunes a run that opened no PR two weeks after its last filing, and drops it from the index', function () {
    $root = sys_get_temp_dir() . '/proof-store-' . uniqid();
    $run = ['repo' => 'Deploy', 'title' => 'Halted before handoff', 'schema' => 2, 'status' => ['state' => 'halted', 'reason' => 'review-plan bound']];
    proof_write_run("{$root}/Deploy/feature-stale", [...$run, 'branch' => 'feature/stale'], date('c', strtotime('-15 days')));
    proof_write_run("{$root}/Deploy/feature-fresh", [...$run, 'branch' => 'feature/fresh'], date('c', strtotime('-1 day')));

    $result = proof_status_cli(['prune'], [...proof_fake_gh(null), 'PIPELINE_PROOF_ROOT' => $root]);

    expect($result)->toBe(['code' => 0, 'stdout' => "proof: pruned 1 run(s)\n", 'stderr' => '']);
    expect(is_dir("{$root}/Deploy/feature-stale"))->toBeFalse();
    expect(is_dir("{$root}/Deploy/feature-fresh"))->toBeTrue();
    expect(file_get_contents("{$root}/index.html"))->not->toContain('feature-stale/index.html')->toContain('href="Deploy/feature-fresh/index.html"');
});

it('prunes a run gh now reports merged once its last filing is more than a week old', function () {
    $root = sys_get_temp_dir() . '/proof-store-' . uniqid();
    proof_write_run("{$root}/Deploy/pr-5-logs", [
        'repo' => 'Deploy', 'nameWithOwner' => 'IT4WEBBV/Deploy', 'branch' => 'feature/logs', 'pr' => 5, 'prState' => 'OPEN',
        'title' => 'PR #5: logs that follow', 'schema' => 2, 'status' => ['state' => 'ready'],
    ], date('c', strtotime('-8 days')));

    $result = proof_status_cli(['prune'], [...proof_fake_gh(['state' => 'MERGED', 'isDraft' => false]), 'PIPELINE_PROOF_ROOT' => $root]);

    expect($result['stdout'])->toBe("proof: pruned 1 run(s)\n");
    expect(is_dir("{$root}/Deploy/pr-5-logs"))->toBeFalse();
});
```

The first case's fake `gh` fails on any call: a run without a PR must be pruned without asking it
(`proof_cli_pr_view()` returns null for it). The second is spec Assumption 1's consequence: a run ready for more
than 7 days without a filing goes on the first pass after its merge.

- [ ] **Step 3: Run the tests to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "prune|finished statuses"`
Expected: FAIL —
- `tells the finished statuses…` on the undefined method `finished()`;
- every row of `prunes a finished run with a PR a week after…` on its 8-day assertion (today's grace is 14 days);
- the row `a stored running status over a merged PR state` of `never prunes a run with a PR that is not finished`
  (today's rule reads `prState` MERGED and prunes it at 30 days);
- every row of `prunes a run that opened no PR two weeks after…`, and both new `ProofStatusTest` cases (today a run
  without a PR is never pruned, and the merged run is 8 days old).

The other rows of `never prunes a run with a PR that is not finished` and `never prunes on unusable timestamps` PASS
already.

- [ ] **Step 4: Add `finished()` to `ProofRunStatus`**

In `skills/pipeline/checks/proof.php`, directly after `group()` (ends line 91), insert:

```php

    /** Merged or closed: hidden on the index by default, and on the shorter retention clock (`proof_should_prune()`). */
    public function finished(): bool
    {
        return in_array($this, [self::Merged, self::Closed], true);
    }
```

- [ ] **Step 5: Rewrite the predicate**

In `skills/pipeline/checks/proof.php`, replace the docblock and body of `proof_should_prune()` (lines 410–439) with:

```php
/** Days a merged or closed run is kept after its last filing. */
const PROOF_FINISHED_RETENTION_DAYS = 7;

/** Days a run that opened no PR is kept after its last filing. */
const PROOF_NO_PR_RETENTION_DAYS = 14;

/**
 * Pure predicate — no filesystem, no `gh`, no clock.
 *
 * Two rules the store depends on (`../references/engine.md` §The proof store, *Retention*):
 *  - a merged or closed run is kept `PROOF_FINISHED_RETENTION_DAYS` after its last filing: a PR merged this morning
 *    is exactly the one still worth looking at this afternoon. "Finished" is the run's status, the one the index
 *    hides by default, which the prune pass has corrected from `gh` before it asks;
 *  - a run that opened no PR is kept `PROOF_NO_PR_RETENTION_DAYS` after its last filing, whatever its status:
 *    `review-plan` bound-exhaustion halts before `handoff` and opens none, and flagging those runs for manual
 *    pruning made nobody prune them (#151). A resumed run that files again gets a fresh `updatedAt`.
 *
 * A run with an open PR is never pruned. Anything unparseable answers "do not prune". Deleting proof is
 * irreversible; keeping it costs disk.
 */
function proof_should_prune(array $run, string $now): bool
{
    $days = match (true) {
        empty($run['pr']) => PROOF_NO_PR_RETENTION_DAYS,
        ProofRunStatus::of($run)->finished() => PROOF_FINISHED_RETENTION_DAYS,
        default => null,
    };
    $updated = strtotime((string) ($run['updatedAt'] ?? ''));
    $nowTs = strtotime($now);
    if ($days === null || $updated === false || $nowTs === false) {
        return false;
    }

    return $updated < $nowTs - $days * 86400;
}
```

Run: `php -l skills/pipeline/checks/proof.php`
Expected: `No syntax errors detected`

- [ ] **Step 6: Run the tests to verify they pass**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "ProofTest|ProofStatusTest"`
Expected: PASS, every case, the existing `corrects a stale status from gh in the prune pass…` included (its pages are
filed now, so no rule reaches them).

- [ ] **Step 7: Write the retention down in engine.md and the 2026-08-25 spec**

In `skills/pipeline/references/engine.md` §The proof store, directly after the paragraph that starts
`**The index** lists the runs by attention` (ends `Without \`localStorage\` nothing is marked and the order is the
status order.`) and before `**Time and cost.**`, insert a paragraph:

```markdown
**Retention.** The prune pass runs after every `proof_cli.php write` and on `proof_cli.php prune`. It corrects each
run's status from `gh` first (above), then removes a run whose status is `merged` or `closed` 7 days after its last
filing (`updatedAt`), and a run that opened no PR 14 days after its last filing, whatever its status; a run whose PR
is still open is never removed. A status or cost amendment is no filing, so a run that waited longer than 7 days for
its merge goes on the first pass after it.
```

In `docs/superpowers/specs/2026-08-25-pipeline-visual-proof-store-design.md`:

- under §7, after the line `Rows whose run has **no PR number** are flagged **"no PR — prune manually"** (see *Retention*).`, add a blank line and:

```markdown
> *Superseded by #151 (2026-10-02): merged or closed runs are pruned 7 days after their last update; a run with no
> PR 14 days after it; the index no longer flags it.*
```

- under §8, after the bullet that starts `- **Runs with no PR are never auto-pruned.**` (its last line ends
  `not invisible accumulation.`), add a blank line and the same note.

The original text stays as the record. `SKILL.md` *Visual proof* is not changed.

- [ ] **Step 8: Run the whole suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, 0 failures (the `LockStepTest` cases over engine.md included; the index, and with it
`flags runs that opened no PR…` in `ProofRenderTest.php`, is untouched until Task 2).

- [ ] **Step 9: Commit**

```bash
git add skills/pipeline/checks/proof.php skills/pipeline/checks/tests/ProofTest.php skills/pipeline/checks/tests/ProofStatusTest.php skills/pipeline/references/engine.md docs/superpowers/specs/2026-08-25-pipeline-visual-proof-store-design.md
git commit -m "feat(pipeline): the proof store prunes finished runs after 7 days and runs without a PR after 14 (#151)"
```

---

### Task 2: The index's markup — controls, row data, sortable headers, the time, the wrapper

Probed: PHP renders the `data-sort` floats and the Updated text as the expected strings below: `(string) 1200.0` is
`1200`, `(string) 2310000.0` is `2310000`, `DateTimeImmutable('2026-10-01T22:29:13+02:00')->format('d-m H:i')` is
`01-10 22:29` (its own offset), `DATE_ATOM` of `…02:00:00Z` is `…02:00:00+00:00`, `strtotime('')` is `false`, and
`2026-10-01T20:29:13+00:00` is `1790886553` (`php -r 'echo (string) 1200.0, "|", (string) 2310000.0, "|", (new DateTimeImmutable("2026-10-01T22:29:13+02:00"))->format("d-m H:i"), …;'`).

**Files:**
- Modify: `skills/pipeline/checks/proof.php` (`ProofRunStatus`, after `finished()`)
- Modify: `skills/pipeline/checks/proof_render.php:98-99` (styles), `:564-583` (`proof_render_index()`),
  `:606-614` (`proof_render_index_filter()`, replaced), `:616-658` (`proof_render_index_row()`)
- Test: `skills/pipeline/checks/tests/ProofTest.php` (append), `skills/pipeline/checks/tests/ProofRenderTest.php`

**Interfaces:**
- Consumes: `ProofRunStatus::finished(): bool` (Task 1), `ProofRunStatus::of()`, `->group()`, `->label()`;
  `proof_run_title(array $run): string`, `proof_updated_time(array $run): int` (0 when unparseable),
  `proof_cost_totals(array $cost): array{seconds: float, cost: float}`, `proof_minutes()`, `proof_millions()`,
  `proof_render_status()`, `proof_render_ref()`, `proof_github_url()`, `proof_e()`; test helpers in
  `ProofRenderTest.php`: `proof_fixture_run(array $overrides = []): array` (repo ViewieMedia, nameWithOwner
  IT4WEBBV/ViewieMedia, branch feature/orders-export, pr 412, prState OPEN, title `PR #412: product summary grid`, no
  `status`, no `clientSummary`), `proof_index_entry(string $name, array $run): array` (`/store/Deploy/<name>`, repo
  Deploy), `proof_cost_step(string $label, float $cost, …): array`.
- Produces (Task 3's script reads these; the ids and attribute names are the contract):
  - `ProofRunStatus::order(): int` — halted 0, ready 1, running 2, merged 3, closed 4;
  - controls `#repo-filter`, `#status-filter` (values `""` and each status value), `#search`, `#show-finished`
    (checkbox), inside `<div class="controls">`;
  - `<table id="runs">` inside `<div class="table-wrap">`, followed by `<p id="no-match" class="meta" hidden>`;
  - per `<tr>`: `data-run`, `data-repo`, `data-group`, `data-status`, `data-finished` (`1`/`0`), `data-updated`,
    `data-revision` (when the run has one), `data-search` (lower-cased);
  - per sortable `<th>`: `data-sort-type` (`text`|`number`), `data-sort-first` (`asc`|`desc`) and a
    `<button type="button" class="sort">`; per cell of a sortable column: `data-sort` (empty when there is no key);
  - the Updated cell: `<time datetime="<ATOM>" title="<ATOM>">d-m H:i</time>`, nothing when it does not parse;
  - new functions `proof_render_index_controls(array $runs): string`, `proof_render_options(array $options): string`,
    `proof_index_columns(): array`, `proof_render_index_head(): string`, `proof_render_index_cell(int|float|string $sort, string $content, string $class = ''): string`,
    `proof_render_updated(array $run): string`, `proof_index_search(array $run): string`.

- [ ] **Step 1: Write the failing tests**

Append to `skills/pipeline/checks/tests/ProofTest.php`:

```php
it('orders all five statuses for a sort on the Status column: halted, ready, running, merged, closed', function () {
    expect(array_map(fn (ProofRunStatus $status) => $status->order(), ProofRunStatus::cases()))->toBe([2, 0, 1, 3, 4]);
});
```

In `skills/pipeline/checks/tests/ProofRenderTest.php`:

(a) Replace the case `flags runs that opened no PR, because pruning can never reach them` with:

```php
it('names a run that opened no PR plainly, since the prune pass now removes it', function () {
    $html = proof_render_index([
        ['dir' => '/store/Deploy/feature-halted', 'run' => proof_fixture_run(['repo' => 'Deploy', 'pr' => null, 'prState' => null])],
    ]);

    expect($html)->toContain('<td data-sort=""><span class="reason">no PR</span></td>');
    expect($html)->not->toContain('prune manually');
});
```

(b) In `links the PR column of the index to the PR on GitHub`, change the expected string to
`'<td data-sort="412"><a href="https://github.com/IT4WEBBV/ViewieMedia/pull/412">#412 OPEN</a></td>'`; in
`keeps the PR column as plain text for a run that names no repo to link into`, to
`'<td data-sort="404">#404 MERGED</td>'`.

(c) Replace the body of `gives each row what the index script needs, its status, its figures, and a copy button only with a summary`
(keep its `$html = proof_render_index([...]);` fixture as it is) with these expectations:

```php
    expect($html)->toContain('<tr data-run="Deploy/pr-5-logs" data-repo="Deploy" data-group="1" data-status="ready" data-finished="0" data-updated="2026-10-01T10:00:00+02:00" data-revision="3" data-search="pr #412: product summary grid #412 feature/orders-export de logboeken lopen mee.">');
    expect($html)->toContain('<tr data-run="Asimo/feature-old" data-repo="Asimo" data-group="0" data-status="halted" data-finished="0" data-updated="2026-09-01T10:00:00+02:00" data-search="pr #412: product summary grid #412 feature/orders-export">');
    expect($html)->toContain('<td data-sort="0"><span class="pill pill-halted">Halted</span> <span class="reason">CI red</span></td>');
    expect($html)->toContain('index.html">PR #412: product summary grid</a><span class="marker"></span></td>');
    expect($html)->toContain('<td class="num" data-sort="1200">20.0 min</td><td class="num" data-sort="2310000">2.31M</td>');
    expect($html)->toContain('<td class="num" data-sort=""></td><td class="num" data-sort=""></td>');
    expect($html)->toContain('<button type="button" class="copy" data-copy="summary-1">Copy</button><span id="summary-1" lang="nl" hidden>De logboeken lopen mee.</span>');
    expect(substr_count($html, 'class="copy"'))->toBe(1);
    expect($html)->toContain('<select id="repo-filter"><option value="">All repos</option><option value="Asimo">Asimo</option><option value="Deploy">Deploy</option></select>');
```

(d) In `escapes the repo, the title, the reason and the summary in the index`, add before its last line:

```php
    expect($html)->toContain('data-search="&lt;i&gt;t&lt;/i&gt; #412 feature/orders-export klant &lt;b&gt;&quot;blij&quot;&lt;/b&gt;"');
    expect($html)->toContain('<td data-sort="&lt;b&gt;R&lt;/b&gt;"><code>&lt;b&gt;R&lt;/b&gt;</code></td>');
    expect($html)->toContain('<td data-sort=""></td><td>');
```

(e) Append after `escapes the repo, the title, the reason and the summary in the index`:

```php
it('puts the repo and status filters, the search and the toggle with its count above the table', function () {
    $html = proof_render_index([
        proof_index_entry('pr-1-merged', ['status' => ['state' => 'merged']]),
        proof_index_entry('pr-2-closed', ['prState' => 'CLOSED']),
        ['dir' => '/store/Asimo/pr-3-running', 'run' => proof_fixture_run(['repo' => 'Asimo', 'status' => ['state' => 'running']])],
    ]);

    expect($html)->toContain("<div class=\"controls\">\n"
        . "<label>Repo <select id=\"repo-filter\"><option value=\"\">All repos</option><option value=\"Asimo\">Asimo</option><option value=\"Deploy\">Deploy</option></select></label>\n"
        . "<label>Status <select id=\"status-filter\"><option value=\"\">All statuses</option><option value=\"running\">Running</option><option value=\"halted\">Halted</option><option value=\"ready\">Ready for review</option><option value=\"merged\">Merged</option><option value=\"closed\">Closed</option></select></label>\n"
        . "<input type=\"search\" id=\"search\" placeholder=\"Title, PR, branch or summary\" aria-label=\"Search runs\">\n"
        . "<label><input type=\"checkbox\" id=\"show-finished\"> Show merged and closed (2)</label>\n"
        . "</div>\n");
    expect(strpos($html, 'class="controls"'))->toBeLessThan(strpos($html, '<table id="runs">'));
});

it('gives each row its status, whether it is finished, and the lower-cased text the search matches', function () {
    $html = proof_render_index([
        proof_index_entry('pr-5-logs', [
            'title' => 'PR #5: Logs That Follow', 'pr' => 5, 'branch' => 'feature/Logs', 'status' => ['state' => 'merged'],
            'clientSummary' => 'De Logboeken lopen mee.', 'updatedAt' => '2026-10-01T10:00:00+02:00',
        ]),
        proof_index_entry('feature-halted', [
            'title' => null, 'pr' => null, 'prState' => null, 'branch' => 'feature/halted',
            'status' => ['state' => 'halted', 'reason' => 'r'], 'updatedAt' => '2026-09-01T10:00:00+02:00',
        ]),
    ]);

    expect($html)->toContain('<tr data-run="Deploy/pr-5-logs" data-repo="Deploy" data-group="2" data-status="merged" data-finished="1" data-updated="2026-10-01T10:00:00+02:00" data-search="pr #5: logs that follow #5 feature/logs de logboeken lopen mee.">');
    // Without a title the run is named by its branch, which the search text then holds once.
    expect($html)->toContain('<tr data-run="Deploy/feature-halted" data-repo="Deploy" data-group="0" data-status="halted" data-finished="0" data-updated="2026-09-01T10:00:00+02:00" data-search="feature/halted">');
});

it('makes every column but Summary sortable, each with its type and the direction of a first click', function () {
    $html = proof_render_index([proof_index_entry('pr-5-logs', [])]);

    expect($html)->toContain('<thead><tr>'
        . '<th data-sort-type="number" data-sort-first="asc"><button type="button" class="sort">Status</button></th>'
        . '<th data-sort-type="text" data-sort-first="asc"><button type="button" class="sort">Repo</button></th>'
        . '<th data-sort-type="number" data-sort-first="desc"><button type="button" class="sort">PR</button></th>'
        . '<th data-sort-type="text" data-sort-first="asc"><button type="button" class="sort">Run</button></th>'
        . '<th data-sort-type="number" data-sort-first="desc" class="num"><button type="button" class="sort">Shots</button></th>'
        . '<th data-sort-type="number" data-sort-first="desc" class="num"><button type="button" class="sort">Time</button></th>'
        . '<th data-sort-type="number" data-sort-first="desc" class="num"><button type="button" class="sort">Cost</button></th>'
        . '<th data-sort-type="number" data-sort-first="desc"><button type="button" class="sort">Updated</button></th>'
        . "<th>Summary</th></tr></thead>\n");
    expect(substr_count($html, 'class="sort"'))->toBe(8);
});

it('gives each sortable cell its key, and an empty key where there is nothing to sort by', function () {
    $html = proof_render_index([
        proof_index_entry('pr-5-logs', [
            'pr' => 5, 'title' => 'PR #5: logs', 'status' => ['state' => 'closed'], 'updatedAt' => '2026-10-01T20:29:13+00:00',
            'shots' => [['title' => 'a'], ['title' => 'b']],
            'cost' => [['workflow' => 'wf_a', 'span' => 1200.0, 'steps' => [proof_cost_step('implement:run', 2310000.0)]]],
        ]),
        proof_index_entry('feature-halted', ['pr' => null, 'prState' => null, 'status' => ['state' => 'halted', 'reason' => 'r'], 'updatedAt' => 'not a date']),
    ]);

    expect($html)->toContain('<td data-sort="4"><span class="pill pill-closed">Closed</span></td>'
        . '<td data-sort="Deploy"><code>Deploy</code></td>'
        . '<td data-sort="5"><a href="https://github.com/IT4WEBBV/ViewieMedia/pull/5">#5 OPEN</a></td>'
        . '<td data-sort="PR #5: logs"><a href="Deploy/pr-5-logs/index.html">PR #5: logs</a><span class="marker"></span></td>'
        . '<td class="num" data-sort="2">2</td><td class="num" data-sort="1200">20.0 min</td><td class="num" data-sort="2310000">2.31M</td>'
        . '<td data-sort="1790886553"><time datetime="2026-10-01T20:29:13+00:00" title="2026-10-01T20:29:13+00:00">01-10 20:29</time></td>');
    expect($html)->toContain('<td data-sort="0"><span class="pill pill-halted">Halted</span> <span class="reason">r</span></td>');
    expect($html)->toContain('<td data-sort=""><span class="reason">no PR</span></td>');
    expect($html)->toContain('<td class="num" data-sort="0">0</td><td class="num" data-sort=""></td><td class="num" data-sort=""></td><td data-sort=""></td><td></td></tr>');
});

it('shows Updated as day-month and time in the timestamp\'s own offset, with the full timestamp on hover', function () {
    $html = proof_render_index([proof_index_entry('pr-5-logs', ['updatedAt' => '2026-10-01T22:29:13+02:00'])]);

    expect($html)->toContain('<td data-sort="1790886553"><time datetime="2026-10-01T22:29:13+02:00" title="2026-10-01T22:29:13+02:00">01-10 22:29</time></td>');
    expect($html)->not->toContain('>01-10 20:29<')->not->toContain('>2026-10-01<');
});

it('wraps the table so it scrolls on its own, and renders the no-match line hidden', function () {
    $html = proof_render_index([proof_index_entry('pr-5-logs', [])]);

    expect($html)->toContain("<div class=\"table-wrap\">\n<table id=\"runs\">\n<thead>");
    expect($html)->toContain("</table>\n</div>\n<p id=\"no-match\" class=\"meta\" hidden>No runs match.</p>\n<script>");
    expect($html)->toContain('.table-wrap { overflow-x:auto; }')->toContain('.controls {')->not->toContain('.filter {');
    expect(proof_render_index([]))->not->toContain('class="controls"')->not->toContain('no-match')->not->toContain('<script');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "ProofRenderTest|orders all five statuses"`
Expected: FAIL — `orders all five statuses…` on the undefined method `order()`; every new and changed index case
on its missing markup (`data-sort`, `data-status`, `class="controls"`, `<time`, `table-wrap`, `no PR</span>`). The
run-page cases and the ordering case PASS.

- [ ] **Step 3: Add `order()` to `ProofRunStatus`**

In `skills/pipeline/checks/proof.php`, directly after `finished()`, insert:

```php

    /** The Status column's sort key: the attention order, then merged before closed. */
    public function order(): int
    {
        return match ($this) {
            self::Halted => 0,
            self::Ready => 1,
            self::Running => 2,
            self::Merged => 3,
            self::Closed => 4,
        };
    }
```

- [ ] **Step 4: Replace the styles for the filter**

In `skills/pipeline/checks/proof_render.php`, `proof_render_styles()`, replace the two lines

```css
.filter { display:flex; align-items:center; gap:.5rem; margin:1rem 0; color:var(--muted); font-size:.875rem; }
.filter select { font:inherit; color:var(--fg); background:var(--card); border:1px solid var(--line); border-radius:.35rem; padding:.2rem .5rem; }
```

with

```css
.controls { display:flex; flex-wrap:wrap; align-items:center; gap:.5rem 1rem; margin:1rem 0; color:var(--muted); font-size:.875rem; }
.controls label { display:flex; align-items:center; gap:.4rem; }
.controls select, .controls input[type=search] { font:inherit; color:var(--fg); background:var(--card); border:1px solid var(--line); border-radius:.35rem; padding:.2rem .5rem; }
.controls input[type=search] { flex:1 1 14rem; max-width:24rem; }
.table-wrap { overflow-x:auto; }
th button.sort { font:inherit; color:inherit; background:none; border:0; padding:0; cursor:pointer; }
th[aria-sort=ascending] button.sort::after { content:" ▲"; font-size:.7em; }
th[aria-sort=descending] button.sort::after { content:" ▼"; font-size:.7em; }
```

No new colour token: `--muted`, `--fg`, `--card` and `--line` have their dark values.

- [ ] **Step 5: Render the controls, the head, the wrapper and the no-match line**

In `proof_render_index()`, replace

```php
        : proof_render_index_filter($runs)
            . "<table id=\"runs\">\n<thead><tr><th>Status</th><th>Repo</th><th>PR</th><th>Run</th><th class=\"num\">Shots</th>"
            . "<th class=\"num\">Time</th><th class=\"num\">Cost</th><th>Updated</th><th>Summary</th></tr></thead>\n<tbody>\n"
            . implode('', array_map(proof_render_index_row(...), array_keys($runs), $runs))
            . "</tbody>\n</table>\n<script>\n" . proof_render_copy_script() . "\n" . proof_render_index_script() . "\n</script>\n";
```

with

```php
        : proof_render_index_controls($runs)
            . "<div class=\"table-wrap\">\n<table id=\"runs\">\n" . proof_render_index_head() . "<tbody>\n"
            . implode('', array_map(proof_render_index_row(...), array_keys($runs), $runs))
            . "</tbody>\n</table>\n</div>\n<p id=\"no-match\" class=\"meta\" hidden>No runs match.</p>\n"
            . "<script>\n" . proof_render_copy_script() . "\n" . proof_render_index_script() . "\n</script>\n";
```

Replace `proof_render_index_filter()` (its docblock and body) with:

```php
/**
 * Above the table: the repo filter (the repos present), the status filter (all five, always), the search, and the
 * toggle that shows the finished runs, with how many there are. The index script applies and remembers them.
 */
function proof_render_index_controls(array $runs): string
{
    $repos = array_values(array_unique(array_filter(array_map(fn (array $entry): string => (string) ($entry['run']['repo'] ?? ''), $runs))));
    sort($repos, SORT_STRING | SORT_FLAG_CASE);
    $statuses = array_combine(
        array_column(ProofRunStatus::cases(), 'value'),
        array_map(fn (ProofRunStatus $status): string => $status->label(), ProofRunStatus::cases()),
    );
    $finished = count(array_filter($runs, fn (array $entry): bool => ProofRunStatus::of($entry['run'])->finished()));

    return "<div class=\"controls\">\n"
        . '<label>Repo <select id="repo-filter"><option value="">All repos</option>' . proof_render_options(array_combine($repos, $repos)) . "</select></label>\n"
        . '<label>Status <select id="status-filter"><option value="">All statuses</option>' . proof_render_options($statuses) . "</select></label>\n"
        . "<input type=\"search\" id=\"search\" placeholder=\"Title, PR, branch or summary\" aria-label=\"Search runs\">\n"
        . "<label><input type=\"checkbox\" id=\"show-finished\"> Show merged and closed ({$finished})</label>\n"
        . "</div>\n";
}

/** `<option>`s from value => text, both escaped. A numeric repo name arrives as an int key. */
function proof_render_options(array $options): string
{
    return implode('', array_map(
        fn (int|string $value, string $text): string => '<option value="' . proof_e((string) $value) . '">' . proof_e($text) . '</option>',
        array_keys($options),
        $options,
    ));
}

/**
 * The index's columns in order: the label, how the script compares the column (`text` or `number`; null, not
 * sortable), which way a first click sorts it, and whether it is right-aligned. `proof_render_index_row()` renders
 * its cells in this order.
 *
 * @return list<array{label: string, type: ?string, first: ?string, num: bool}>
 */
function proof_index_columns(): array
{
    return [
        ['label' => 'Status', 'type' => 'number', 'first' => 'asc', 'num' => false],
        ['label' => 'Repo', 'type' => 'text', 'first' => 'asc', 'num' => false],
        ['label' => 'PR', 'type' => 'number', 'first' => 'desc', 'num' => false],
        ['label' => 'Run', 'type' => 'text', 'first' => 'asc', 'num' => false],
        ['label' => 'Shots', 'type' => 'number', 'first' => 'desc', 'num' => true],
        ['label' => 'Time', 'type' => 'number', 'first' => 'desc', 'num' => true],
        ['label' => 'Cost', 'type' => 'number', 'first' => 'desc', 'num' => true],
        ['label' => 'Updated', 'type' => 'number', 'first' => 'desc', 'num' => false],
        ['label' => 'Summary', 'type' => null, 'first' => null, 'num' => false],
    ];
}

/** The header row: a sort button in every sortable column's header. */
function proof_render_index_head(): string
{
    $cells = array_map(fn (array $column): string => $column['type'] === null
        ? "<th>{$column['label']}</th>"
        : "<th data-sort-type=\"{$column['type']}\" data-sort-first=\"{$column['first']}\"" . ($column['num'] ? ' class="num"' : '')
            . "><button type=\"button\" class=\"sort\">{$column['label']}</button></th>",
        proof_index_columns());

    return '<thead><tr>' . implode('', $cells) . "</tr></thead>\n";
}
```

- [ ] **Step 6: Render the row's data, its sort keys, the time and the no-PR cell**

Replace `proof_render_index_row()` (its docblock and body) with:

```php
/** One run: what the index script reads, its status, where it lives, its page, its figures, and its summary to copy. */
function proof_render_index_row(int $number, array $entry): string
{
    $run = $entry['run'];
    // The link comes from the directory the run was found in, never from re-deriving a name out of the run: a run
    // filed under an earlier naming scheme has to stay reachable. The page keys its seen marker on the same segments.
    $key = implode('/', array_slice(explode('/', trim((string) $entry['dir'], '/')), -2));
    $status = ProofRunStatus::of($run);
    $repo = (string) ($run['repo'] ?? '');
    $title = proof_run_title($run);
    $shots = count($run['shots'] ?? []);
    $cost = $run['cost'] ?? [];
    $totals = proof_cost_totals($cost);
    $updated = proof_updated_time($run);
    $summary = trim((string) ($run['clientSummary'] ?? ''));

    // The prune pass removes a run that opened no PR two weeks after its last filing, so the index only names it.
    $pr = empty($run['pr'])
        ? '<span class="reason">no PR</span>'
        : proof_render_ref(
            proof_github_url($run, 'pull/' . (int) $run['pr']),
            '#' . (string) $run['pr'] . ' ' . (string) ($run['prState'] ?? ''),
        );

    $data = [
        'run' => $key,
        'repo' => $repo,
        'group' => (string) $status->group(),
        'status' => $status->value,
        'finished' => $status->finished() ? '1' : '0',
        'updated' => (string) ($run['updatedAt'] ?? ''),
        ...(isset($run['revision']) ? ['revision' => (string) (int) $run['revision']] : []),
        'search' => proof_index_search($run),
    ];
    $attributes = implode('', array_map(fn (string $name, string $value): string => " data-{$name}=\"" . proof_e($value) . '"', array_keys($data), $data));
    $copy = $summary === ''
        ? ''
        : "<button type=\"button\" class=\"copy\" data-copy=\"summary-{$number}\">Copy</button><span id=\"summary-{$number}\" lang=\"nl\" hidden>" . proof_e($summary) . '</span>';

    return "<tr{$attributes}>"
        . proof_render_index_cell($status->order(), proof_render_status($run))
        . proof_render_index_cell($repo, '<code>' . proof_e($repo) . '</code>')
        . proof_render_index_cell(empty($run['pr']) ? '' : (int) $run['pr'], $pr)
        . proof_render_index_cell($title, '<a href="' . proof_e("{$key}/index.html") . '">' . proof_e($title) . '</a><span class="marker"></span>')
        . proof_render_index_cell($shots, (string) $shots, 'num')
        . proof_render_index_cell($cost === [] ? '' : $totals['seconds'], $cost === [] ? '' : proof_minutes($totals['seconds']), 'num')
        . proof_render_index_cell($cost === [] ? '' : $totals['cost'], $cost === [] ? '' : proof_millions($totals['cost']), 'num')
        . proof_render_index_cell($updated === 0 ? '' : $updated, proof_render_updated($run))
        . "<td>{$copy}</td></tr>\n";
}

/** A sortable cell: its key in `data-sort`, which the index script compares instead of the text the cell shows. */
function proof_render_index_cell(int|float|string $sort, string $content, string $class = ''): string
{
    $attribute = $class === '' ? '' : " class=\"{$class}\"";

    return "<td{$attribute} data-sort=\"" . proof_e((string) $sort) . "\">{$content}</td>";
}

/**
 * When the run was last filed: `d-m H:i` in the timestamp's own offset (PHP's default timezone is UTC here), the
 * full timestamp on hover; the index script rewrites both to the browser's time. Nothing when it does not parse.
 */
function proof_render_updated(array $run): string
{
    if (proof_updated_time($run) === 0) {
        return '';
    }
    $time = new DateTimeImmutable((string) $run['updatedAt']);
    $full = proof_e($time->format(DATE_ATOM));

    return "<time datetime=\"{$full}\" title=\"{$full}\">" . $time->format('d-m H:i') . '</time>';
}

/** What the search matches, lower-cased: the title as the index shows it, `#<pr>`, the branch and the client summary. */
function proof_index_search(array $run): string
{
    $parts = [
        proof_run_title($run),
        empty($run['pr']) ? '' : '#' . (int) $run['pr'],
        (string) ($run['branch'] ?? ''),
        trim((string) ($run['clientSummary'] ?? '')),
    ];

    return mb_strtolower(implode(' ', array_unique(array_filter($parts, fn (string $part): bool => $part !== ''))));
}
```

Run: `php -l skills/pipeline/checks/proof.php && php -l skills/pipeline/checks/proof_render.php`
Expected: `No syntax errors detected` twice.

- [ ] **Step 7: Run the render and status tests to verify they pass**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "ProofRenderTest|ProofTest|ProofStatusTest"`
Expected: PASS, every case. The existing `carries the index script…` case still passes: the script is unchanged in
this task and still finds `#repo-filter`.

- [ ] **Step 8: Run the whole suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, 0 failures.

- [ ] **Step 9: Commit**

```bash
git add skills/pipeline/checks/proof.php skills/pipeline/checks/proof_render.php skills/pipeline/checks/tests/ProofTest.php skills/pipeline/checks/tests/ProofRenderTest.php
git commit -m "feat(pipeline): the proof index renders status and search data, sort keys, the controls and the time (#151)"
```

---

### Task 3: The index script — hides finished runs, filters, searches, sorts, shows local times

**Files:**
- Modify: `skills/pipeline/checks/proof_render.php:660-715` (`proof_render_index_script()`)
- Modify: `skills/pipeline/references/engine.md:943-948` (*The index* paragraph)
- Test: `skills/pipeline/checks/tests/ProofRenderTest.php` (`carries the index script…`, rewritten; one case appended)

**Interfaces:**
- Consumes: Task 2's markup contract (ids, `data-*` attributes, `button.sort`, `time[datetime]`, `#no-match`); the
  seen key `seen:<repo>/<run>` the run page writes (`proof_render_seen_script()`).
- Produces: `proof_render_index_script(): string` (same name and signature; the body is replaced). The
  `localStorage` keys `proof:repo`, `proof:status`, `proof:finished`.

- [ ] **Step 1: Write the failing tests**

In `skills/pipeline/checks/tests/ProofRenderTest.php`, replace the case
`carries the index script: seen markers, the attention order, the remembered filter and the copy code` with:

```php
it('carries the index script: seen markers, the remembered filters and toggle, search, header sorting, local times and the copy code', function () {
    $html = proof_render_index([proof_index_entry('pr-5-logs', [])]);

    expect($html)->toContain('<body class="index">');
    expect($html)->toContain("storage.getItem('seen:' + row.dataset.run)");
    expect($html)->toContain("remember('proof:repo', repo.value)")
        ->toContain("remember('proof:status', status.value)")
        ->toContain("remember('proof:finished', finished.checked ? '1' : '0')")
        ->toContain("restore(repo, 'proof:repo')")
        ->toContain("restore(status, 'proof:status')");
    // The toggle governs only All statuses: an explicit Merged or Closed shows those rows whatever it says.
    expect($html)->toContain("status.value === '' ? finished.checked || row.dataset.finished === '0' : row.dataset.status === status.value");
    expect($html)->toContain("search.addEventListener('input', show)");
    expect($html)->toContain("header.setAttribute('aria-sort'");
    expect($html)->toContain("querySelectorAll('time[datetime]')");
    expect($html)->toContain("window.addEventListener('pageshow'");
    expect($html)->toContain('navigator.clipboard.writeText');
    expect($html)->not->toContain('showModal()');
    expect(proof_render_index([]))->not->toContain('<script')->not->toContain('repo-filter')->toContain('No runs recorded');
});

it('renders an index script that parses as JavaScript', function () {
    $file = sys_get_temp_dir() . '/proof-index-' . uniqid() . '.js';
    file_put_contents($file, proof_render_index_script());
    exec('node --check ' . escapeshellarg($file) . ' 2>&1', $output, $code);
    unlink($file);

    expect($code)->toBe(0, implode("\n", $output));
});
```

- [ ] **Step 2: Run the tests to verify the first fails**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "index script"`
Expected: `carries the index script…` FAILS on `remember('proof:repo', repo.value)`; `renders an index script that
parses as JavaScript` PASSES already (it guards the rewrite).

- [ ] **Step 3: Rewrite the script**

Replace `proof_render_index_script()` (its docblock and body) with:

```php
/**
 * The index's script, on `DOMContentLoaded` and again on a `pageshow` from the back/forward cache (Back from a page is
 * how the index is reached again):
 *  - each Updated `<time>` in the browser's time, `dd-mm HH:MM`, the full `YYYY-MM-DD HH:MM:SS` on hover;
 *  - per row with a revision `New` when this browser never opened it, `Updated` when it was filed again since; a seen
 *    Ready row drops among the rest (its rank);
 *  - the rows in the attention order (rank, then newest first), or by the column whose header was clicked: its first
 *    direction, reversed by a second click, empty keys last either way, ties in the attention order;
 *  - a row shows when the repo filter, the status filter (or, under All statuses, the toggle) and every search term
 *    let it; `#no-match` when none does.
 * The repo filter, the status filter and the toggle are remembered (`proof:repo`, `proof:status`, `proof:finished`);
 * the search and the sort are not. Without `localStorage` (a private window, blocked site data) no row is marked and
 * every control works unremembered.
 */
function proof_render_index_script(): string
{
    return <<<'JS'
(function () {
  var table = document.getElementById('runs');
  var body = table.tBodies[0];
  var headers = Array.prototype.slice.call(table.tHead.rows[0].cells);
  var rows = Array.prototype.slice.call(body.rows);
  var repo = document.getElementById('repo-filter');
  var status = document.getElementById('status-filter');
  var search = document.getElementById('search');
  var finished = document.getElementById('show-finished');
  var empty = document.getElementById('no-match');
  var sorted = null;
  var storage = null;
  try {
    storage = window.localStorage;
    storage.getItem('proof:repo');
  } catch (error) {
    storage = null;
  }
  function remember(key, value) {
    try { if (storage) { storage.setItem(key, value); } } catch (error) {}
  }
  function restore(select, key) {
    var saved = storage.getItem(key);
    if (Array.prototype.some.call(select.options, function (option) { return option.value === saved; })) { select.value = saved; }
  }
  function pad(number) { return String(number).padStart(2, '0'); }
  function local(time) {
    var date = new Date(time.getAttribute('datetime'));
    if (isNaN(date.getTime())) { return; }
    var clock = pad(date.getHours()) + ':' + pad(date.getMinutes());
    time.textContent = pad(date.getDate()) + '-' + pad(date.getMonth() + 1) + ' ' + clock;
    time.title = date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) + ' ' + clock + ':' + pad(date.getSeconds());
  }
  function mark(row) {
    var revision = Number(row.dataset.revision || 0);
    var seen = revision ? storage.getItem('seen:' + row.dataset.run) : null;
    var state = !revision ? '' : seen === null ? 'New' : Number(seen) < revision ? 'Updated' : 'seen';
    row.querySelector('.marker').textContent = state === 'seen' ? '' : state;
    row.dataset.rank = state === 'seen' && row.dataset.group === '1' ? '2' : row.dataset.group;
  }
  function attention(a, b) {
    return (Number(a.dataset.rank || a.dataset.group) - Number(b.dataset.rank || b.dataset.group))
      || ((Date.parse(b.dataset.updated) || 0) - (Date.parse(a.dataset.updated) || 0));
  }
  function byColumn(a, b) {
    var x = a.cells[sorted.index].dataset.sort;
    var y = b.cells[sorted.index].dataset.sort;
    if (x === '' || y === '') { return (x === '') - (y === ''); }
    var difference = sorted.type === 'number' ? Number(x) - Number(y) : x.localeCompare(y, undefined, { sensitivity: 'base' });
    return sorted.direction === 'asc' ? difference : -difference;
  }
  function order() {
    rows.sort(attention);
    if (sorted) { rows.sort(byColumn); }
    rows.forEach(function (row) { body.appendChild(row); });
    headers.forEach(function (header, index) {
      if (sorted && sorted.index === index) {
        header.setAttribute('aria-sort', sorted.direction === 'asc' ? 'ascending' : 'descending');
      } else {
        header.removeAttribute('aria-sort');
      }
    });
  }
  function visible(row, terms) {
    return (repo.value === '' || row.dataset.repo === repo.value)
      && (status.value === '' ? finished.checked || row.dataset.finished === '0' : row.dataset.status === status.value)
      && terms.every(function (term) { return row.dataset.search.indexOf(term) !== -1; });
  }
  function show() {
    var terms = search.value.toLowerCase().split(/\s+/).filter(Boolean);
    var shown = 0;
    rows.forEach(function (row) {
      row.hidden = !visible(row, terms);
      shown += row.hidden ? 0 : 1;
    });
    empty.hidden = shown > 0;
  }
  function refresh() {
    table.querySelectorAll('time[datetime]').forEach(local);
    if (storage) {
      rows.forEach(mark);
      restore(repo, 'proof:repo');
      restore(status, 'proof:status');
      finished.checked = storage.getItem('proof:finished') === '1';
    }
    order();
    show();
  }
  headers.forEach(function (header, index) {
    var button = header.querySelector('button.sort');
    if (!button) { return; }
    button.addEventListener('click', function () {
      var again = sorted && sorted.index === index;
      var direction = again ? (sorted.direction === 'asc' ? 'desc' : 'asc') : header.dataset.sortFirst;
      sorted = { index: index, type: header.dataset.sortType, direction: direction };
      order();
    });
  });
  repo.addEventListener('change', function () { remember('proof:repo', repo.value); show(); });
  status.addEventListener('change', function () { remember('proof:status', status.value); show(); });
  finished.addEventListener('change', function () { remember('proof:finished', finished.checked ? '1' : '0'); show(); });
  search.addEventListener('input', show);
  document.addEventListener('DOMContentLoaded', refresh);
  window.addEventListener('pageshow', function (event) { if (event.persisted) { refresh(); } });
})();
JS;
}
```

`Array.prototype.sort` is stable, so the column sort over rows already in the attention order keeps that order for
ties. Without `localStorage`, `rank` is never set and `attention()` falls back to `group`, which is PHP's order.

Run: `php -l skills/pipeline/checks/proof_render.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter ProofRenderTest`
Expected: PASS, every case, both script cases included.

- [ ] **Step 5: Rewrite *The index* paragraph in engine.md**

In `skills/pipeline/references/engine.md` §The proof store, replace the first two sentences of the paragraph that
starts `**The index** lists the runs by attention` — from `**The index** lists` up to and including
`and copies its client summary.` — with:

```markdown
**The index** shows the open runs by attention: `halted` first, then `ready`, then the rest, each newest first;
merged and closed runs are hidden until *Show merged and closed (n)* is ticked. It filters by repo and by status (a
chosen `merged` or `closed` shows those runs whatever the toggle says), searches title, PR number, branch and client
summary, and sorts by a click on a column header (a second click reverses; a reload restores the attention order).
The repo filter, the status filter and the toggle are remembered per browser (`proof:repo`, `proof:status`,
`proof:finished` in `localStorage`); the search and the sort are not. Per run it shows the status, PR, page, shots,
time and cost, and its last filing as `d-m H:i` in the browser's time with the full timestamp on hover, and copies
its client summary.
```

The rest of the paragraph (from `**What changed since the last look** is per browser` to the end) stays word for
word: `LockStepTest` requires its `` `seen:<repo>/<run>` ``.

- [ ] **Step 6: Run the whole suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, 0 failures (the `LockStepTest` cases over engine.md included).

- [ ] **Step 7: Commit**

```bash
git add skills/pipeline/checks/proof_render.php skills/pipeline/checks/tests/ProofRenderTest.php skills/pipeline/references/engine.md
git commit -m "feat(pipeline): the proof index hides finished runs, filters by status, searches and sorts (#151)"
```

---

### Task 4: Check the index in the browser, over a copy of the real store

No code and no commit: this proves Tasks 2 and 3 on the real store's runs. Never write to `~/GitProjects/_proofs`,
and never run `prune` or `write` against the copy either (a `prune` would delete runs and ask `gh`).

**Files:** none changed. Works in a temp dir.

**Interfaces:**
- Consumes: `proof_render_index(array $runs): string`, `proof_scan_runs(string $root): array`, `proof_root(): string`
  (honours `PIPELINE_PROOF_ROOT`), all reachable through `skills/pipeline/checks/proof_render.php`.

- [ ] **Step 1: Copy the store and render its index with the new code**

Shell state does not persist between an agent's Bash calls: run this step as one command, and carry the printed
`$COPY` path into the later steps literally.

```bash
COPY=$(mktemp -d)/_proofs && cp -R ~/GitProjects/_proofs "$COPY" && echo "$COPY"
PIPELINE_PROOF_ROOT="$COPY" php -r 'require "skills/pipeline/checks/proof_render.php"; file_put_contents(proof_root() . "/index.html", proof_render_index(proof_scan_runs(proof_root())));'
grep -c 'data-finished="1"' "$COPY/index.html"; grep -o 'Show merged and closed ([0-9]*)' "$COPY/index.html"
```

Expected: the two numbers agree.

- [ ] **Step 2: Serve the copy**

Run (in the background): `php -S 127.0.0.1:8151 -t "$COPY"`

- [ ] **Step 3: The default view, the toggle and the status filter**

With the Playwright MCP, `browser_navigate` to `http://127.0.0.1:8151/index.html`, `browser_evaluate`
`() => { localStorage.clear(); location.reload(); }`, then:

- `() => [...document.querySelectorAll('#runs tbody tr')].filter(r => !r.hidden && r.dataset.finished === '1').length`
  → `0` (no merged or closed row by default);
- tick *Show merged and closed* (`browser_click`), reload (`browser_navigate` to the same URL): the box is still
  ticked and the finished rows show; untick it again;
- choose `Merged` in the status filter (`browser_select_option`) with the toggle off: every visible row has
  `data-status="merged"` and at least one shows; reload: `Merged` is still chosen; choose `All statuses` again;
- `() => { localStorage.setItem('proof:status', 'paused'); location.reload(); }`, then
  `() => document.getElementById('status-filter').value` → `""` (a stale value is ignored).

- [ ] **Step 4: Search**

Type into the search box (`browser_type`), clearing it between terms, with the toggle on:

- a PR number with and without `#` (take one from the table): only that run's rows show;
- a word from one row's client summary in capitals: that row shows;
- a branch fragment and a title word together: only rows holding both show;
- `zzzz-no-such-run`: no row shows and *No runs match.* is visible.

- [ ] **Step 5: Sorting**

Clear the search. Click the *PR* header button: the first visible row has the highest PR number, the rows without a
PR come last, and the header has `aria-sort="descending"` and shows `▼`. Click it again: lowest first, rows without a
PR still last, `▲`. Click *Run*: A–Z, `aria-sort="ascending"`, and *PR* lost its `aria-sort`. Reload: the attention
order again (halted, then ready, then the rest) and no header has `aria-sort`.

- [ ] **Step 6: Updated in local time**

`() => [...document.querySelectorAll('#runs time')].every(t => /^\d\d-\d\d \d\d:\d\d$/.test(t.textContent) && /^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/.test(t.title))`
→ `true`. For one row, compare its `datetime` (UTC) with its text: the text is that time in the browser's timezone
(Amsterdam in October is UTC+2). Hover a time cell (`browser_hover`): the tooltip shows the full timestamp.

- [ ] **Step 7: Widths and themes**

For each of 1440×900 and 390×844 (`browser_resize`) and each of `light` and `dark` (`browser_emulate_media`
`colorScheme`): screenshot the top of the page with the controls and the first rows. Expected: the controls are
legible on both backgrounds and wrap onto several lines at 390 px; at 390 px
`() => document.documentElement.scrollWidth <= window.innerWidth` returns `true` while
`() => document.querySelector('.table-wrap').scrollWidth > document.querySelector('.table-wrap').clientWidth` returns
`true` (the table scrolls inside its wrapper, not the page). `browser_console_messages` shows no error.

- [ ] **Step 8: Clean up**

Stop the `php -S` server and remove the `mktemp` dir itself, not only `_proofs` inside it:
`rm -rf "$(dirname "$COPY")"`. Confirm the live store was not written:
`grep -c 'class="controls"' ~/GitProjects/_proofs/index.html` prints `0` (no filing has rendered with the new code
before the merge).
