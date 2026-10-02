# The proof index reads like an inbox, and a run that halts or turns ready is unread again — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A change of a run's status to Halted or Ready raises a stored `attention` count; a run page stores, and the
index and `status.js` compare, `revision + attention`; unread rows are bold with a filled dot and a hint word, read
rows muted with an outline dot that marks a row unread again, and the heading counts what the tab counts.

**Architecture:** One pure function in `proof.php` (`proof_count_attention()`) raises the count over the run before
and after a change, and `proof_store_amend()`, the one path every status write takes, applies it. `proof_run_seen()`
is the single source of the number the page stores and the index compares (`<body data-seen>`, the row's
`data-seen`, `status.js`'s `seen`). The unread rule is one global JavaScript function (`proofUnread()`), rendered
before the index script and run under `node` by the tests; the index script calls it, styles the rows from it and
wires the dot.

**Tech Stack:** PHP 8.4 (no framework), Pest 4 in `skills/pipeline/checks/tests`, vanilla JS inline in the rendered
page, `node` for `--check` and for running the unread rule, the Playwright MCP for the browser check.

**Spec:** `docs/superpowers/specs/2026-10-02-pipeline-proof-index-inbox-design.md`. Read it with this plan: the plan
argues from it, and its `## Assumptions` 1–15 are the answers this plan builds on (13–15 were added by the plan step).

## Global Constraints

- Run every command from the worktree root
  `/Users/jroelofs/GitProjects/LaravelClaudeMd/LaravelClaudeMd/.claude/worktrees/worktree-issue-160-pipeline-the-proof-index-reads-like-an-inbox-and-a`.
- Tests: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
  (append `--filter '<pattern>'` for a subset). This repo is not a Docker project: Pest runs on the host. The worktree
  has no `vendor/` yet: run `composer install` once first. The suite needs `node` on `PATH`.
- Test-first: write the test, see it fail for the reason given, then write the code. `php -l` every PHP file you
  change.
- The number a page stores and the index compares is `revision + attention`, `attention` 0 when absent, and none
  (no `data-seen`, no `seen`, no dot, no marker) for a run without a `revision`.
- `attention` rises by exactly one when a change turns `ProofRunStatus::of()` into Halted or Ready from any other
  status; Halted to Halted, Ready to Ready, any change to Running, Merged or Closed, and a change that leaves the status
  alone raise nothing. A filing never touches it; `revision` and `updatedAt` keep their meaning.
- `localStorage` key `seen:<repo>/<run>`, unchanged. Marking read stores the row's `data-seen`; marking unread stores
  the string `'0'`.
- The hint words, exactly: `New`, `Halted`, `Ready`, `Unread`, `Updated`. The dot's labels, exactly: `Mark as read`
  (on an unread row), `Mark as unread` (on a read row). The heading count's text: `<n> unread`, empty at 0.
- **No step writes to the real store** (`~/GitProjects/_proofs`) or runs `prune` or `write` against it. The browser
  check works on a copy, with a fake `gh` first on `PATH` that always fails.
- Earlier specs and plans that describe `data-revision` or the *New*/*Updated* markers stay as they are.
- No changelog entry: the repo has no `.changelog/` and no `CHANGELOG.md`.
- Commits: no `Co-Authored-By`, no AI attribution; every message ends on `(#160)`.

## Review Focus

1. **A browser without `localStorage`** (a private window, blocked site data) — nothing is unread, no row is bold or
   muted, no dot shows, and nothing throws. Pinned in Task 3: `.dot` is `display:none` unless the row carries
   `unread` or `read`, `unread()` returns `''` without storage, and `mark()`, the only writer of those classes, runs
   only with storage (*carries the inbox wiring* case).
2. **A halt written twice for one halt** (the dispatcher's `brief` and then its `finish`, spec Assumption 1) — counts
   once, so a page opened between the two writes is not turned unread by the second. Pinned in Task 1's
   *halted twice in a row* dataset row.
3. **The day this ships** — every value a browser stores today is a revision and no run has `attention`, so no row
   changes read state. Pinned in Task 2 (`proof_run_seen()` of a run without `attention` is its revision, and the row
   and the page carry that number) and Task 3's rule (`'3'` stored against 3 is read).
4. **A run filed before revisions existed** — no dot, no class, never unread, never counted. Pinned in Task 3's rule
   (`seen` 0 gives `''`) and its row case (no `button.dot` without a revision).
5. **A hand-marked row whose run is then halted, resumed and filed** — it stays unread with the right word
   (`Halted` while halted, `Unread` once running again), and opening the page clears it. Pinned in Task 3's rule
   (`'0'` against a halted and a running row) and checked in Task 5, Steps 6–7.

## File Structure

- Modify `skills/pipeline/checks/proof.php`: `PROOF_STORE_KEYS` (21–26), `ProofRunStatus` (a method after
  `finished()`, 93–97), new `proof_count_attention()` after `proof_merge_run()` (258–263) (Task 1); new
  `proof_run_seen()` after `proof_count_attention()` (Task 2).
- Modify `skills/pipeline/checks/proof_store.php`: `proof_store_amend()` (190–222) (Task 1).
- Modify `skills/pipeline/checks/proof_render.php`: `proof_render_seen_script()` (179–193), `proof_render_run()`'s
  `<body>` (552–557), `proof_render_status_js()` (725–743), `proof_render_index_row()` (745–798),
  `proof_render_index_script()`'s `unread()` and `mark()` (919–929) (Task 2); `proof_render_styles()` (21–108),
  `proof_render_index()` (562–591), new `proof_render_unread_script()`, `proof_render_index_row()`'s Run cell,
  `proof_render_index_script()` (836–1041) (Task 3).
- Modify tests: `tests/ProofTest.php` (Tasks 1, 2), `tests/ProofStatusTest.php` (Tasks 1, 2),
  `tests/ProofWriteTest.php` (Tasks 1, 2), `tests/RunCostTest.php` (Task 1), `tests/ProofRenderTest.php` (Tasks 2, 3).
- Modify docs: `skills/pipeline/references/engine.md` §The proof store, the status paragraph and the `run.json` table
  (Task 1, spec Assumption 15), the index paragraph and *The open index tab* (Task 4); `README.md` §Proofs app
  (Task 4).

---

### Task 1: `attention` — a status turning Halted or Ready is counted

**Files:**
- Modify: `skills/pipeline/checks/proof.php:21-26`, `:93-97`, after `:263`
- Modify: `skills/pipeline/checks/proof_store.php:190-222`
- Modify: `skills/pipeline/references/engine.md` (lines 940–942 and 997–998)
- Test: `skills/pipeline/checks/tests/ProofTest.php`, `tests/ProofStatusTest.php`, `tests/ProofWriteTest.php`,
  `tests/RunCostTest.php`

**Interfaces:**
- Consumes: `ProofRunStatus::of(array $run): ProofRunStatus`, `proof_merge_run()`, `proof_store_amend(string $page,
  callable $change): ?string`; test helpers `proof_test_page(array $run = []): string` (`tests/Pest.php`),
  `proof_status_cli(array $arguments, array $env = []): array` and `proof_fake_gh(?array $view): array`
  (`ProofStatusTest.php`), `proof_write_cli()`, `proof_write_payload()`, `proof_write_stored()`
  (`ProofWriteTest.php`), `cost_run()`, `cost_call()`, `cost_tool_use()`, `cost_tool_result()`, `checks_cli()`
  (`RunCostTest.php`).
- Produces: `ProofRunStatus::callsOwner(): bool`; `proof_count_attention(array $before, array $after): array`;
  `attention` in `PROOF_STORE_KEYS`; `run.json`'s `attention` (int, absent until the first raise).

- [ ] **Step 0: Install dependencies (once)**

Run: `composer install`
Expected: `vendor/bin/pest` exists.

- [ ] **Step 1: Write the failing pure tests**

In `skills/pipeline/checks/tests/ProofTest.php`, replace the case
`keeps the store's revision, status and cost over a payload's` (line 304) whole with:

```php
it('keeps the store\'s revision, attention, status and cost over a payload\'s', function () {
    $stored = ['title' => 'x', 'revision' => 3, 'attention' => 2, 'status' => ['state' => 'halted', 'reason' => 'r'], 'cost' => [['workflow' => 'wf_a']]];

    expect(proof_merge_run($stored, ['revision' => 99, 'attention' => 40, 'status' => ['state' => 'merged'], 'cost' => [], 'title' => 'y']))
        ->toBe(['title' => 'y', 'revision' => 3, 'attention' => 2, 'status' => ['state' => 'halted', 'reason' => 'r'], 'cost' => [['workflow' => 'wf_a']]]);
    expect(proof_merge_run(['title' => 'x'], ['attention' => 40]))->toBe(['title' => 'x']);
});
```

Append to the same file:

```php
it('calls the owner for a halted or ready run, never for a running, merged or closed one: the statuses group() puts first', function () {
    expect(array_map(fn (ProofRunStatus $status) => $status->callsOwner(), ProofRunStatus::cases()))->toBe([false, true, true, false, false])
        ->toBe(array_map(fn (ProofRunStatus $status) => $status->group() < 2, ProofRunStatus::cases()));
});

it('raises attention by one when a change turns the status halted or ready, and only then', function (array $before, array $after, array $counted) {
    expect(proof_count_attention($before, $after))->toBe($counted);
})->with([
    'running to halted' => [
        ['status' => ['state' => 'running']],
        ['status' => ['state' => 'halted', 'reason' => 'CI red']],
        ['status' => ['state' => 'halted', 'reason' => 'CI red'], 'attention' => 1],
    ],
    'running to ready, counted once before' => [
        ['status' => ['state' => 'running'], 'attention' => 1],
        ['status' => ['state' => 'ready'], 'attention' => 1],
        ['status' => ['state' => 'ready'], 'attention' => 2],
    ],
    'halted to ready' => [
        ['status' => ['state' => 'halted', 'reason' => 'r'], 'attention' => 1],
        ['status' => ['state' => 'ready'], 'attention' => 1],
        ['status' => ['state' => 'ready'], 'attention' => 2],
    ],
    'an older open run turning ready' => [
        ['prState' => 'OPEN'],
        ['prState' => 'OPEN', 'status' => ['state' => 'ready']],
        ['prState' => 'OPEN', 'status' => ['state' => 'ready'], 'attention' => 1],
    ],
    'halted again with a new reason' => [
        ['status' => ['state' => 'halted', 'reason' => 'CI red']],
        ['status' => ['state' => 'halted', 'reason' => 'CI still red']],
        ['status' => ['state' => 'halted', 'reason' => 'CI still red']],
    ],
    'ready again' => [['status' => ['state' => 'ready']], ['status' => ['state' => 'ready']], ['status' => ['state' => 'ready']]],
    'halted to running' => [['status' => ['state' => 'halted', 'reason' => 'r']], ['status' => ['state' => 'running']], ['status' => ['state' => 'running']]],
    'ready to merged' => [['status' => ['state' => 'ready']], ['status' => ['state' => 'merged']], ['status' => ['state' => 'merged']]],
    'running to closed' => [['status' => ['state' => 'running']], ['status' => ['state' => 'closed']], ['status' => ['state' => 'closed']]],
    'a cost on a halted run' => [
        ['status' => ['state' => 'halted', 'reason' => 'r']],
        ['status' => ['state' => 'halted', 'reason' => 'r'], 'cost' => [['workflow' => 'wf_a']]],
        ['status' => ['state' => 'halted', 'reason' => 'r'], 'cost' => [['workflow' => 'wf_a']]],
    ],
]);
```

`toBe` on arrays compares key order too: a raised count is appended when the run had none and replaced in place when
it had one, which is what `[...$after, 'attention' => …]` does.

- [ ] **Step 2: Run them to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter 'attention|calls the owner'`
Expected: FAIL — `Call to undefined method ProofRunStatus::callsOwner()`, `Call to undefined function
proof_count_attention()`, and the merge case gets `'attention' => 40` where it expects `2`.

- [ ] **Step 3: Write the pure code**

In `skills/pipeline/checks/proof.php`, replace the `PROOF_STORE_KEYS` docblock and constant (lines 21–26) with:

```php
/**
 * The keys the store owns. A payload's values for them are ignored, and `shotSources` is consumed, never stored.
 * `revision` counts the run's filings, `attention` the times its status turned Halted or Ready
 * (`proof_count_attention()`), `status` is where the run stands (`ProofRunStatus`), `cost` its time and cost per
 * workflow (`proof_add_cost()`).
 */
const PROOF_STORE_KEYS = ['addedTests', 'schema', 'createdAt', 'updatedAt', 'shotSources', 'revision', 'attention', 'status', 'cost'];
```

In `ProofRunStatus`, after `finished()` (line 97), add:

```php
    /** Halted or Ready, the statuses `group()` puts first: they make an opened run unread again (`proof_count_attention()`). */
    public function callsOwner(): bool
    {
        return $this->group() < 2;
    }
```

After `proof_merge_run()` (line 263), add:

```php
/**
 * `$after` with `attention` one above `$before`'s when the change turned the run's status into one that calls its
 * owner (`ProofRunStatus::callsOwner()`), so an opened run that halts or turns ready is unread again. The same status
 * again, any other status and a change that leaves the status alone return `$after` unchanged.
 */
function proof_count_attention(array $before, array $after): array
{
    $status = ProofRunStatus::of($after);
    if ($status === ProofRunStatus::of($before) || ! $status->callsOwner()) {
        return $after;
    }

    return [...$after, 'attention' => (int) ($before['attention'] ?? 0) + 1];
}
```

`proof_write_run()` does not change: a filing raises `revision`, never `attention`, and `proof_merge_run()` now keeps
the stored `attention` over a payload's.

Run: `php -l skills/pipeline/checks/proof.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Run them to verify they pass**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter 'attention|calls the owner'`
Expected: PASS.

- [ ] **Step 5: Write the failing store tests**

In `skills/pipeline/checks/tests/ProofStatusTest.php`, in the case
`marks a page halted with its reason, leaves revision and updatedAt, …` (line 37), replace line 46 with:

```php
    expect($run)->toMatchArray(['revision' => $before['revision'], 'updatedAt' => $before['updatedAt'], 'attention' => 1]);
```

Replace the case `corrects a stale status from gh in the prune pass, keeping updatedAt and the revision` (lines
93–110) whole with:

```php
it('corrects a stale status from gh in the prune pass, keeping updatedAt and the revision', function (array $stored, array $view, array $status, ?int $attention) {
    $page = proof_test_page(['nameWithOwner' => 'IT4WEBBV/Deploy', ...$stored]);
    $before = proof_read_run(dirname($page));

    expect(proof_status_cli(['prune'], [...proof_fake_gh($view), 'PIPELINE_PROOF_ROOT' => dirname($page, 3)])['code'])->toBe(0);

    $run = proof_read_run(dirname($page));
    expect($run)->toMatchArray([
        'prState' => $view['state'], 'status' => $status, 'updatedAt' => $before['updatedAt'], 'revision' => $before['revision'],
    ]);
    // An open PR taken out of draft outside the pipeline is ready for review all the same (spec Assumption 2).
    expect($run['attention'] ?? null)->toBe($attention);
    expect(file_get_contents($page))->toContain('pill-' . $status['state']);
    expect(proof_test_status_runs(file_get_contents(dirname($page, 3) . '/status.js'))[0]['status'])->toBe($status['state']);
})->with([
    'a draft that went ready' => [['status' => ['state' => 'running']], ['state' => 'OPEN', 'isDraft' => false], ['state' => 'ready'], 1],
    'a stale Ready put back in draft' => [['status' => ['state' => 'ready']], ['state' => 'OPEN', 'isDraft' => true], ['state' => 'running'], null],
    'a halted draft keeps its reason' => [['status' => ['state' => 'halted', 'reason' => 'CI red']], ['state' => 'OPEN', 'isDraft' => true], ['state' => 'halted', 'reason' => 'CI red'], null],
    'a merge no session wrote' => [['status' => ['state' => 'ready']], ['state' => 'MERGED', 'isDraft' => false], ['state' => 'merged'], null],
    'an old run without a status' => [['status' => null], ['state' => 'CLOSED', 'isDraft' => false], ['state' => 'closed'], null],
]);
```

Append to the same file:

```php
it('raises attention when a status turns halted or ready, and not for merged, closed, running or the same status again', function (array $writes, ?int $attention) {
    $page = proof_test_page();

    foreach ($writes as $write) {
        proof_status_cli(['status', $page, ...$write]);
    }

    $run = proof_read_run(dirname($page));
    expect($run['attention'] ?? null)->toBe($attention);
    expect($run['revision'])->toBe(1);
})->with([
    'halted' => [[['halted', '--reason', 'CI red']], 1],
    'ready' => [[['ready']], 1],
    'merged' => [[['merged']], null],
    'closed' => [[['closed']], null],
    'running' => [[['running']], null],
    'halted twice in a row' => [[['halted', '--reason', 'CI red'], ['halted', '--reason', 'CI still red']], 1],
    'halted, resumed, halted again' => [[['halted', '--reason', 'CI red'], ['running'], ['halted', '--reason', 'CI red']], 2],
    'halted, then ready' => [[['halted', '--reason', 'CI red'], ['ready']], 2],
]);
```

`proof_test_page()` files the run Running (`prState` OPEN, revision 1), so `running` is the same status again.

In `skills/pipeline/checks/tests/ProofWriteTest.php`, append:

```php
it('keeps the stored attention through a filing, and never takes a payload\'s', function () {
    $root = sys_get_temp_dir() . '/proof-write-' . uniqid();
    proof_write_cli(proof_write_payload(), $root);
    file_put_contents("{$root}/Deploy/pr-5-logs/run.json", proof_run_json([...proof_write_stored($root), 'attention' => 1]));

    proof_write_cli(proof_write_payload(['attention' => 40]), $root);

    expect(proof_write_stored($root))->toMatchArray(['revision' => 2, 'attention' => 1]);
});
```

In `skills/pipeline/checks/tests/RunCostTest.php`, append:

```php
it('leaves attention as it was when it files a cost', function () {
    $dir = cost_run([
        'a1' => ['implement:run', implode("\n", [
            cost_call('m1', 0, 0, 300000, 2000, null, '10:07:00.000'),
            cost_tool_use('t1', '10:08:00.000'),
            cost_tool_result('t1', '10:20:00.000'),
        ]), ['status' => 'continued']],
    ]);
    $page = proof_test_page(['status' => ['state' => 'halted', 'reason' => 'CI red'], 'attention' => 1]);

    checks_cli('run_cost_cli.php', [$dir, $page]);

    expect(proof_read_run(dirname($page)))->toMatchArray(['attention' => 1, 'revision' => 1])->toHaveKey('cost');
});
```

- [ ] **Step 6: Run them to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter 'ProofStatusTest|stored attention|attention as it was'`
Expected: FAIL — the halted page and the *a draft that went ready* row have no `attention` (`null` where `1` is
expected), the *raises attention* rows `halted`, `ready`, `halted twice in a row`, `halted, resumed, halted again` and
`halted, then ready` fail the same way. The write case and the cost case pass already after Step 3 (the merge keeps
the key, and a cost amend never raised anything): they pin that neither path starts raising it.

- [ ] **Step 7: Apply the count in the amend**

In `skills/pipeline/checks/proof_store.php`, replace `proof_store_amend()`'s docblock and its `$run = $change($run);`
line (190–209) so the function reads:

```php
/**
 * Applies `$change` to the run filed beside `$page`, then re-renders the page, and the index and `status.js` of the
 * store the page is in (`dirname($page, 3)`, never `proof_root()`, so a test store and the real one never mix). Not a
 * filing: `updatedAt` and `revision` stay as they are, since the index's Updated counts filings and the prune pass's
 * grace period measures the last one. A change that turns the status Halted or Ready raises `attention`
 * (`proof_count_attention()`), which the index compares, so an opened run is unread again. Never a warning on stdout:
 * a command that amends still prints one answer.
 *
 * @param callable(array): array $change
 * @return ?string null, or why nothing was written
 */
function proof_store_amend(string $page, callable $change): ?string
{
    if ($page === '') {
        return 'no page given';
    }
    $dir = dirname($page);
    $run = proof_read_run($dir);
    if ($run === null) {
        return "no run at {$dir}";
    }
    $run = proof_count_attention($run, $change($run));
```

The rest of the function is unchanged. `proof_store_status()` and `proof_cli_refresh()` change nothing (spec
Assumption 14).

Run: `php -l skills/pipeline/checks/proof_store.php`
Expected: `No syntax errors detected`.

- [ ] **Step 8: Name `attention` in engine.md**

`LockStepTest`'s *keeps engine.md §The proof store in lock-step* requires every `PROOF_STORE_KEYS` key in the
section (spec Assumption 15). In `skills/pipeline/references/engine.md`:

Replace (lines 940–942)

```
(`artifacts.proof`, or null), the page the session marks ready. A status or a cost written into a filed run is no
filing: `revision` and `updatedAt` stay as they were. A run filed before statuses existed reads as its `prState`
says: `MERGED` Merged, `CLOSED` Closed, else Running.
```

with

```
(`artifacts.proof`, or null), the page the session marks ready. A status or a cost written into a filed run is no
filing: `revision` and `updatedAt` stay as they were. A change of status to `halted` or `ready`, by any writer above,
raises the run's `attention` instead, which the index compares (*What changed since the last look*); `halted` again,
any other status and a cost raise nothing. A run filed before statuses existed reads as its `prState` says: `MERGED`
Merged, `CLOSED` Closed, else Running.
```

In the payload table, in the `addedTests` row (line 997), replace
``A payload's `addedTests`, `schema`, `createdAt`, `updatedAt`, `revision`, `status` and `cost` are ignored``
with
``A payload's `addedTests`, `schema`, `createdAt`, `updatedAt`, `revision`, `attention`, `status` and `cost` are ignored``,
and replace the row (line 998)

```
| `revision`, `status`, `cost` | **the store's, never a payload's**: `revision` counts the run's filings (`handoff`'s and every `write`); `status` is `{state, reason}`, the reason only with `halted` (above); `cost` is the figures `run_cost_cli.php` files, per workflow `{workflow, span, steps}` |
```

with

```
| `revision`, `attention`, `status`, `cost` | **the store's, never a payload's**: `revision` counts the run's filings (`handoff`'s and every `write`); `attention` counts the times its status turned `halted` or `ready` (absent until the first); `status` is `{state, reason}`, the reason only with `halted` (above); `cost` is the figures `run_cost_cli.php` files, per workflow `{workflow, span, steps}` |
```

- [ ] **Step 9: Run them to verify they pass**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter 'ProofTest|ProofStatusTest|ProofWriteTest|RunCostTest|LockStepTest'`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add skills/pipeline/checks/proof.php skills/pipeline/checks/proof_store.php skills/pipeline/references/engine.md skills/pipeline/checks/tests/ProofTest.php skills/pipeline/checks/tests/ProofStatusTest.php skills/pipeline/checks/tests/ProofWriteTest.php skills/pipeline/checks/tests/RunCostTest.php
git commit -m "feat(pipeline): a run's status turning halted or ready raises its attention count (#160)"
```

---

### Task 2: The seen number — `revision + attention` on the page, the row and `status.js`

**Files:**
- Modify: `skills/pipeline/checks/proof.php` (after `proof_count_attention()`)
- Modify: `skills/pipeline/checks/proof_render.php:179-193`, `:552-557`, `:725-743`, `:745-798`, `:919-929`
- Test: `skills/pipeline/checks/tests/ProofTest.php`, `tests/ProofRenderTest.php`, `tests/ProofStatusTest.php`,
  `tests/ProofWriteTest.php`

**Interfaces:**
- Consumes: `proof_count_attention()` and `attention` (Task 1); test helpers `proof_current_run(array $overrides =
  []): array`, `proof_index_entry(string $name, array $run): array`, `proof_test_status_runs(string $js): array`.
- Produces: `proof_run_seen(array $run): ?int`; the run page's `<body data-seen="<n>">`; the index row's
  `data-seen="<n>"` (in `data-revision`'s place, between `data-updated` and `data-search`); `status.js` entries
  `{key, status, revision, seen, hash, row}`; the index script's `unread(row)` and `mark(row)` reading `data-seen`.

- [ ] **Step 1: Write the failing tests**

In `skills/pipeline/checks/tests/ProofTest.php`, append:

```php
it('gives a run the number its page stores when opened: its revision plus its attention', function () {
    expect(proof_run_seen(['revision' => 3, 'attention' => 2]))->toBe(5);
    expect(proof_run_seen(['revision' => 3]))->toBe(3);
    expect(proof_run_seen(['attention' => 2]))->toBeNull();
});
```

In `skills/pipeline/checks/tests/ProofRenderTest.php`, replace the two cases at lines 401–415
(`names the revision in the meta line and on the body, …` and `records the page as seen at its revision, …`) whole
with:

```php
it('names the revision in the meta line, puts the seen number on the body, and leaves both out for a run without a revision', function () {
    $html = proof_render_run(proof_current_run(['revision' => 3, 'attention' => 2]));
    expect($html)->toContain(' · revision 3</p>')->toContain("<body data-seen=\"5\">\n")->not->toContain('data-revision');

    expect(proof_render_run(proof_current_run(['revision' => 3])))->toContain("<body data-seen=\"3\">\n");
    expect(proof_render_run(proof_current_run()))->not->toContain(' · revision')->toContain("<body>\n");
});

it('records the page as seen at its number, keyed by its repo and run directories, before the copy and zoom code', function () {
    $html = proof_render_run(proof_current_run(['revision' => 3]));

    expect($html)->toContain('var seen = document.body.dataset.seen;');
    expect($html)->toContain("localStorage.setItem('seen:' + run, seen)");
    expect($html)->toContain(".split('/').filter(Boolean).slice(-2)");
    expect(strpos($html, "localStorage.setItem('seen:'"))->toBeLessThan(strpos($html, 'navigator.clipboard.writeText'));
    expect(strpos($html, 'navigator.clipboard.writeText'))->toBeLessThan(strpos($html, 'showModal()'));
});
```

In the case `gives each row what the index script needs, …` (line 490), replace `data-revision="3"` in the first
`<tr …>` assertion (line 500) with `data-seen="3"`, and add after line 501:

```php
    expect(proof_render_index_row(proof_index_entry('pr-7-x', ['revision' => 3, 'attention' => 2])))
        ->toContain(' data-updated="2026-08-25T15:30:00+02:00" data-seen="5" data-search=');
    expect($html)->not->toContain('data-revision');
```

In the case `renders status.js with each run's key, status, revision, hash and row, …` (line 564), rename it to
`renders status.js with each run's key, status, revision, seen number, hash and row, in the attention order`, add
`'seen' => 3,` after `'revision' => 3,` in the `toBe([...])` (line 574), replace line 579 with

```php
    expect($runs[0])->toMatchArray(['status' => 'halted', 'revision' => null, 'seen' => null]);
```

and add before the last line of the case:

```php
    expect(proof_test_status_runs(proof_render_status_js([proof_index_entry('pr-7-x', ['revision' => 3, 'attention' => 2])]))[0])
        ->toMatchArray(['revision' => 3, 'seen' => 5]);
```

In the case `changes a run's hash when its status, revision or cost changes, and only then` (line 585), rename it to
`changes a run's hash when its status, revision, attention or cost changes, and only then` and add after line 592:

```php
    expect($with(['attention' => 1]))->not->toBe($hash);
```

In the case `carries the poll and the tab signal in the index script` (line 757), replace
`->toContain("if (!storage || !revision) { return ''; }")` (line 771) with
`->toContain("if (!storage || !seen) { return ''; }")`.

In `skills/pipeline/checks/tests/ProofStatusTest.php`, in the case
`marks a page halted with its reason, …` (line 37), add after the `attention` line from Task 1:

```php
    expect(file_get_contents($page))->toContain("<body data-seen=\"2\">\n");
```

and in the case `rewrites status.js in the store the page is in when a status is written` (line 149), replace
`['key' => 'Deploy/pr-5-logs', 'status' => 'halted', 'revision' => 1]` with
`['key' => 'Deploy/pr-5-logs', 'status' => 'halted', 'revision' => 1, 'seen' => 2]`. Append:

```php
it('leaves the seen number at the revision when a status turns merged, closed or running', function (string $status) {
    $page = proof_test_page();

    proof_status_cli(['status', $page, $status]);

    expect(proof_test_status_runs(file_get_contents(dirname($page, 3) . '/status.js'))[0])->toMatchArray(['revision' => 1, 'seen' => 1]);
    expect(file_get_contents($page))->toContain("<body data-seen=\"1\">\n");
})->with(['merged', 'closed', 'running']);
```

In `skills/pipeline/checks/tests/ProofWriteTest.php`, in the case
`leaves status.js beside the index at every write, …` (line 187), add `'seen' => 1` and `'seen' => 2` to the two
`toMatchArray([...])` lines (196, 197). In the case `keeps the stored attention through a filing, …` (Task 1), add
before its closing `});`:

```php
    expect(proof_test_status_runs(file_get_contents("{$root}/status.js"))[0])->toMatchArray(['revision' => 2, 'seen' => 3]);
    expect(file_get_contents("{$root}/Deploy/pr-5-logs/index.html"))->toContain("<body data-seen=\"3\">\n");
```

- [ ] **Step 2: Run them to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter 'ProofTest|ProofRenderTest|ProofStatusTest|ProofWriteTest'`
Expected: FAIL — `Call to undefined function proof_run_seen()`, `data-seen` missing from the page and the rows,
`seen` missing from the `status.js` entries, the seen script still reads `dataset.revision`.

- [ ] **Step 3: Write the code**

In `skills/pipeline/checks/proof.php`, after `proof_count_attention()`, add:

```php
/**
 * The number a run page stores under `seen:<repo>/<run>` when it is opened, and the index compares with: `revision +
 * attention`. Both only grow, so the sum grows whenever either does; a run without `attention` gets its revision, which
 * is what every browser stored before `attention` existed. Null for a run filed before revisions existed.
 */
function proof_run_seen(array $run): ?int
{
    return isset($run['revision']) ? (int) $run['revision'] + (int) ($run['attention'] ?? 0) : null;
}
```

In `skills/pipeline/checks/proof_render.php`, replace `proof_render_seen_script()` with its docblock (179–193):

```php
/**
 * Opening a page stores its run's seen number (`proof_run_seen()`, the body's `data-seen`) under `seen:<repo>/<run>`,
 * the last two directories of its own path (a trailing `index.html` dropped), which are what the index links to:
 * `file://` is one origin in Chrome, so the index reads it.
 */
function proof_render_seen_script(): string
{
    return <<<'JS'
(function () {
  var seen = document.body.dataset.seen;
  if (!seen) { return; }
  var run = location.pathname.replace(/\/index\.html$/, '').split('/').filter(Boolean).slice(-2).map(decodeURIComponent).join('/');
  try { localStorage.setItem('seen:' + run, seen); } catch (error) {}
})();
JS;
}
```

In `proof_render_run()`, replace

```php
    $revision = isset($run['revision']) ? ' data-revision="' . (int) $run['revision'] . '"' : '';
```

with

```php
    $seen = proof_run_seen($run);
    $seenAttribute = $seen === null ? '' : " data-seen=\"{$seen}\"";
```

and `<body{$revision}>` with `<body{$seenAttribute}>` in the return.

In `proof_render_status_js()`, replace the docblock's `status, revision (null before revisions existed), hash and
row` with `status, revision and seen number (`proof_run_seen()`; both null before revisions existed), hash and row`,
and add after the `'revision' => …` line:

```php
        'seen' => proof_run_seen($entry['run']),
```

In `proof_render_index_row()`, add `$seen = proof_run_seen($run);` after `$summary = …;` and replace

```php
        ...(isset($run['revision']) ? ['revision' => (string) (int) $run['revision']] : []),
```

with

```php
        ...($seen === null ? [] : ['seen' => (string) $seen]),
```

In `proof_render_index_script()`, replace `unread()` and the rank line of `mark()` (919–929):

```js
  function unread(row) {
    var seen = Number(row.dataset.seen || 0);
    if (!storage || !seen) { return ''; }
    var stored = storage.getItem('seen:' + row.dataset.run);
    return stored === null ? 'New' : Number(stored) < seen ? 'Updated' : '';
  }
  function mark(row) {
    var state = unread(row);
    row.querySelector('.marker').textContent = state;
    row.dataset.rank = state === '' && row.dataset.seen && row.dataset.group === '1' ? '2' : row.dataset.group;
  }
```

Task 3 replaces this `unread()` with the full rule; this step only keeps the index working on the renamed attribute.

Run: `php -l skills/pipeline/checks/proof.php && php -l skills/pipeline/checks/proof_render.php`
Expected: `No syntax errors detected` twice.

- [ ] **Step 4: Run them to verify they pass**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter 'ProofTest|ProofRenderTest|ProofStatusTest|ProofWriteTest|RunCostTest|LockStepTest'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/proof.php skills/pipeline/checks/proof_render.php skills/pipeline/checks/tests/ProofTest.php skills/pipeline/checks/tests/ProofRenderTest.php skills/pipeline/checks/tests/ProofStatusTest.php skills/pipeline/checks/tests/ProofWriteTest.php
git commit -m "feat(pipeline): a run page stores, and the index compares, revision plus attention (#160)"
```

---

### Task 3: The inbox — the unread rule, bold and muted rows, the dot, the heading count

**Files:**
- Modify: `skills/pipeline/checks/proof_render.php` — `proof_render_styles()` (21–108), `proof_render_index()`
  (562–591), new `proof_render_unread_script()` before `proof_render_index_script()`, `proof_render_index_row()`'s Run
  cell, `proof_render_index_script()` (836–1041)
- Test: `skills/pipeline/checks/tests/ProofRenderTest.php`

**Interfaces:**
- Consumes: `proof_run_seen()`, the row's `data-seen` and the index script's `unread()`/`mark()` (Task 2).
- Produces: `proof_render_unread_script(): string` (the global JS `proofUnread(seen, stored, status)`); the row's
  `<button type="button" class="dot"></button>` before the link; `<h1>Pipeline proof store <span id="unread-count"
  class="unread-count"></span></h1>` with a table; the row classes `unread` / `read`.

- [ ] **Step 1: Write the failing tests**

In `skills/pipeline/checks/tests/ProofRenderTest.php`, append:

```php
it('reads a row as unread by the rule, and names what it needs now', function () {
    $calls = [
        'no number' => [0, null, 'halted', ''],
        'never opened' => [3, null, 'running', 'New'],
        'opened at its number' => [3, '3', 'halted', ''],
        'opened above its number' => [3, '4', 'ready', ''],
        'halted since' => [5, '3', 'halted', 'Halted'],
        'ready since' => [5, '3', 'ready', 'Ready'],
        'filed again since' => [5, '3', 'running', 'Updated'],
        'marked unread, running' => [5, '0', 'running', 'Unread'],
        'marked unread, halted' => [5, '0', 'halted', 'Halted'],
        'merged below its number' => [5, '3', 'merged', 'Updated'],
    ];
    $arguments = json_encode(array_values(array_map(fn (array $call): array => array_slice($call, 0, 3), $calls)));
    $script = proof_render_unread_script()
        . "\nconsole.log(JSON.stringify({$arguments}.map(function (call) { return proofUnread(call[0], call[1], call[2]); })));";

    exec('node -e ' . escapeshellarg($script) . ' 2>&1', $output, $code);

    expect($code)->toBe(0, implode("\n", $output));
    expect(array_combine(array_keys($calls), json_decode(implode('', $output), true)))
        ->toBe(array_map(fn (array $call): string => $call[3], $calls));
});

it('puts the dot before the title of a run with a seen number, and none on a run without one', function () {
    $row = proof_render_index_row(proof_index_entry('pr-5-logs', ['revision' => 3]));

    expect($row)->toContain('<td data-sort="PR #412: product summary grid"><button type="button" class="dot"></button><a href="Deploy/pr-5-logs/index.html">PR #412: product summary grid</a><span class="marker"></span></td>');
    expect(proof_render_index_row(proof_index_entry('pr-6-old', [])))->not->toContain('class="dot"');
});

it('counts the unread runs beside the heading of a store with runs, and leaves an empty store\'s heading alone', function () {
    expect(proof_render_index([proof_index_entry('pr-5-logs', ['revision' => 1])]))
        ->toContain('<h1>Pipeline proof store <span id="unread-count" class="unread-count"></span></h1>');
    expect(proof_render_index([]))->toContain("<h1>Pipeline proof store</h1>\n")->not->toContain('unread-count');
});

it('renders the unread rule before the index script, and both parse as JavaScript', function () {
    $html = proof_render_index([proof_index_entry('pr-5-logs', ['revision' => 1])]);
    $file = sys_get_temp_dir() . '/proof-unread-' . uniqid() . '.js';
    file_put_contents($file, proof_render_unread_script() . "\n" . proof_render_index_script());
    exec('node --check ' . escapeshellarg($file) . ' 2>&1', $output, $code);
    unlink($file);

    expect($code)->toBe(0, implode("\n", $output));
    expect(strpos($html, 'function proofUnread(seen, stored, status)'))->toBeGreaterThan(0)
        ->toBeLessThan(strpos($html, 'function unread(row)'));
});

it('carries the inbox wiring in the index script, and styles a row only once the script marked it', function () {
    $script = proof_render_index_script();
    $styles = proof_render_styles();

    expect($script)->toContain("return storage ? proofUnread(Number(row.dataset.seen || 0), storage.getItem('seen:' + row.dataset.run), row.dataset.status) : '';")
        ->toContain("row.classList.toggle('unread', state !== '')")
        ->toContain("row.classList.toggle('read', state === '')")
        ->toContain("var label = state === '' ? 'Mark as unread' : 'Mark as read';")
        ->toContain("dot.setAttribute('aria-label', label)")
        ->toContain("body.addEventListener('click'")
        ->toContain("event.target.closest('button.dot')")
        ->toContain("storage.setItem('seen:' + row.dataset.run, unread(row) === '' ? '0' : row.dataset.seen)")
        ->toContain("getElementById('unread-count')")
        ->toContain("count.textContent = unseen.length ? unseen.length + ' unread' : ''")
        ->toContain("row.dataset.rank = state === '' && row.dataset.seen && row.dataset.group === '1' ? '2' : row.dataset.group")
        ->not->toContain('dataset.revision');
    // Without localStorage `mark()` never runs, so no row is unread or read and every dot stays hidden.
    expect($script)->toContain('if (storage) { rows.forEach(mark); }');
    expect($styles)->toContain('tr.unread td { font-weight:700; }')
        ->toContain('tr.read td { color:var(--muted); }')
        ->toContain('.dot { display:none;')
        ->toContain('tr.unread .dot, tr.read .dot { display:inline-flex;')
        ->toContain('border:1.5px solid var(--muted);')
        ->toContain('tr.unread .dot::before { background:var(--ready); border-color:var(--ready); }')
        ->toContain('.dot:hover::before, .dot:focus-visible::before { border-color:var(--fg); }')
        ->toContain('.dot:focus-visible { outline:')
        ->toContain('.unread-count {');
});
```

Change the existing cases:
- `carries the poll and the tab signal in the index script` (line 757): replace
  `->toContain("if (!storage || !seen) { return ''; }")` (Task 2's line) with
  `->toContain("proofUnread(Number(row.dataset.seen || 0)")`.
- `names the index tab Proofs and gives it a favicon link …` (line 731): replace
  `->toContain('<h1>Pipeline proof store</h1>')` (line 743) with
  `->toContain('<h1>Pipeline proof store <span id="unread-count" class="unread-count"></span></h1>')`.
- `gives each row what the index script needs, …` (line 490): add
  `expect(substr_count($html, 'class="dot"'))->toBe(1);` after the `class="copy"` count (only `$ready` has a
  revision).

- [ ] **Step 2: Run them to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter ProofRenderTest`
Expected: FAIL — `Call to undefined function proof_render_unread_script()`, no `class="dot"` in the rows, no
`unread-count` in the heading, the script and styles strings missing.

- [ ] **Step 3: Write the unread rule**

In `skills/pipeline/checks/proof_render.php`, before `proof_render_index_script()`'s docblock, add:

```php
/**
 * The unread rule (`../references/engine.md` §The proof store, *What changed since the last look*): one global function
 * the index script calls and the tests run under `node`. `seen` is the row's number (`proof_run_seen()`, 0 without a
 * revision: never unread), `stored` what this browser holds under the run's `seen:` key (null when it never opened the
 * page, `'0'` when the owner marked it unread, which opening a page never stores), `status` the row's status. Returns
 * `''` for a read row, else the hint of what the row needs now: New, then Halted or Ready by its status, then Unread
 * for a row marked by hand, else Updated.
 */
function proof_render_unread_script(): string
{
    return <<<'JS'
function proofUnread(seen, stored, status) {
  if (!seen) { return ''; }
  if (stored === null) { return 'New'; }
  if (Number(stored) >= seen) { return ''; }
  return status === 'halted' ? 'Halted' : status === 'ready' ? 'Ready' : stored === '0' ? 'Unread' : 'Updated';
}
JS;
}
```

In `proof_render_index()`, replace

```php
    $script = "<script>\n" . proof_render_copy_script() . "\n" . proof_render_index_script() . "\n</script>\n";
```

with

```php
    $script = "<script>\n" . proof_render_copy_script() . "\n" . proof_render_unread_script() . "\n" . proof_render_index_script() . "\n</script>\n";
    $heading = $runs === [] ? 'Pipeline proof store' : 'Pipeline proof store <span id="unread-count" class="unread-count"></span>';
```

and `. "<h1>Pipeline proof store</h1>\n"` with `. "<h1>{$heading}</h1>\n"`.

- [ ] **Step 4: The dot in the row**

In `proof_render_index_row()`, add after `$copy = …;`:

```php
    $dot = $seen === null ? '' : '<button type="button" class="dot"></button>';
```

and replace the Run cell line with:

```php
        . proof_render_index_cell($title, $dot . '<a href="' . proof_e("{$key}/index.html") . '">' . proof_e($title) . '</a><span class="marker"></span>')
```

Extend the row's docblock: `… its status, where it lives, its page (after the dot that marks it read or unread, for a
run with a seen number), its figures, …`.

- [ ] **Step 5: The styles**

In `proof_render_styles()`, after the `.marker { … }` line (97), add:

```css
.unread-count { margin-left:.25rem; color:var(--ready); font-size:.7rem; font-weight:700; letter-spacing:.04em; text-transform:uppercase; vertical-align:middle; }
tr.unread td { font-weight:700; }
tr.read td { color:var(--muted); }
.dot { display:none; width:1rem; height:1rem; margin:0 .3rem 0 0; padding:0; border:0; border-radius:50%; background:none; color:inherit; cursor:pointer; vertical-align:-.15rem; }
tr.unread .dot, tr.read .dot { display:inline-flex; align-items:center; justify-content:center; }
.dot::before { content:""; width:.55rem; height:.55rem; border-radius:50%; border:1.5px solid var(--muted); box-sizing:border-box; }
tr.unread .dot::before { background:var(--ready); border-color:var(--ready); }
.dot:hover::before, .dot:focus-visible::before { border-color:var(--fg); }
.dot:focus-visible { outline:2px solid var(--ready); outline-offset:1px; }
```

The status pill keeps its own colour on a muted row: `.pill-*` set `color` on the pill itself. The read row's outline is
`var(--muted)`, not `var(--line)`: the row is itself muted, and a `var(--line)` circle (`#e4e4e7` on white) would not
be seen, while the dot is the only way to mark a row unread. Hover and focus turn the circle's edge `var(--fg)` on
either row (the spec's *`var(--fg)` on hover and focus*); the filled circle stays filled.

- [ ] **Step 6: The index script**

In `proof_render_index_script()`:

Add after `var empty = document.getElementById('no-match');`:

```js
  var count = document.getElementById('unread-count');
```

Replace Task 2's `unread()` and `mark()` with:

```js
  function unread(row) {
    return storage ? proofUnread(Number(row.dataset.seen || 0), storage.getItem('seen:' + row.dataset.run), row.dataset.status) : '';
  }
  // A row without a dot (a run filed before revisions) returns before the class toggles, so it is never styled read.
  function mark(row) {
    var state = unread(row);
    var dot = row.querySelector('button.dot');
    row.querySelector('.marker').textContent = state;
    row.dataset.rank = state === '' && row.dataset.seen && row.dataset.group === '1' ? '2' : row.dataset.group;
    if (!dot) { return; }
    var label = state === '' ? 'Mark as unread' : 'Mark as read';
    row.classList.toggle('unread', state !== '');
    row.classList.toggle('read', state === '');
    dot.setAttribute('aria-label', label);
    dot.title = label;
  }
```

In `tab()`, add before the `document.title = …` line:

```js
    count.textContent = unseen.length ? unseen.length + ' unread' : '';
```

Add before `document.addEventListener('DOMContentLoaded', refresh);`:

```js
  body.addEventListener('click', function (event) {
    var dot = event.target.closest('button.dot');
    if (!dot || !storage) { return; }
    var row = dot.closest('tr');
    try {
      storage.setItem('seen:' + row.dataset.run, unread(row) === '' ? '0' : row.dataset.seen);
    } catch (error) {
      return;
    }
    mark(row);
    order();
    show();
    tab();
  });
```

The listener sits on the table body, so a row the poll inserts or replaces needs no wiring of its own. In the
function's docblock, replace the two lines

```
 *  - per row with a revision `New` when this browser never opened it, `Updated` when it was filed again since
 *    (`unread()`, the one rule #160 replaces); a seen Ready row drops among the rest (its rank);
```

with

```
 *  - per row with a seen number the hint `proofUnread()` gives (`unread()`), the row `unread` (bold, a filled dot) or
 *    `read` (muted, an outline dot) and the dot's label; a seen Ready row drops among the rest (its rank). A click on
 *    the dot stores the row's number (read) or `'0'` (unread) and marks, orders and filters again at once;
```

replace

```
 *  - the tab (`tab()`): the title counts the unread runs that are not merged or closed, whatever the filters show
```

with

```
 *  - the tab (`tab()`): the title and the heading's `#unread-count` count the unread runs that are not merged or
 *    closed, whatever the filters show
```

and replace

```
 * the search and the sort are not. Without `localStorage` (a private window, blocked site data) no row is marked,
```

with

```
 * the search and the sort are not. Without `localStorage` (a private window, blocked site data) no row is marked or
 * styled, no dot shows,
```

Run: `php -l skills/pipeline/checks/proof_render.php`
Expected: `No syntax errors detected`.

- [ ] **Step 7: Run them to verify they pass**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter 'ProofRenderTest|ProofStatusTest|ProofWriteTest'`
Expected: PASS, the existing *renders an index script that parses as JavaScript* case included (`node --check` reads
syntax only, so the call to the global `proofUnread` is fine on its own).

- [ ] **Step 8: Commit**

```bash
git add skills/pipeline/checks/proof_render.php skills/pipeline/checks/tests/ProofRenderTest.php
git commit -m "feat(pipeline): the proof index reads like an inbox: bold unread rows, a dot to mark them, a heading count (#160)"
```

---

### Task 4: The docs — the unread rule in engine.md and the README

**Files:**
- Modify: `skills/pipeline/references/engine.md` (lines 951–954, 957–965 after Task 1's edit shifts them by two)
- Modify: `README.md` §Proofs app (lines 174–177)

**Interfaces:**
- Consumes: the behaviour of Tasks 1–3. `LockStepTest` still requires `` `seen:<repo>/<run>` `` in §The proof store.

- [ ] **Step 1: The index paragraph**

In `skills/pipeline/references/engine.md` §The proof store, the index paragraph, replace the text from
**What changed since the last look** up to and including the sentence that ends *nothing is marked and the order is
the status order.* (the sentence after it, *Open it with `~/Applications/Proofs.app` …*, stays) with:

```
**What changed since the last look** is per browser: opening a page stores the run's `revision + attention` under
`seen:<repo>/<run>` in `localStorage` (`file://` is one origin in Chrome). The index reads a run as unread when this
browser never opened it (*New*), or when since it was opened it was filed again (*Updated*) or its status turned
`halted` (*Halted*) or `ready` (*Ready*); `merged`, `closed` and `running` never make a run unread. The word names
what the row needs now: *Halted* or *Ready* by the run's current status before *Updated*. Unread rows are bold with a
filled dot before the title, read rows muted with an outline dot. The dot marks a run read (it stores the run's
number, as opening the page does) or unread by hand (it stores `0`, shown as *Unread* on a run that is neither
halted nor ready), and a seen `ready` run drops among the rest. The heading counts the unread runs that are not
merged or closed (`3 unread`), whatever the filters show. A run filed before `revision` existed gets no dot and no
marker. Without `localStorage` nothing is marked, no dot shows, and the order is the status order.
```

- [ ] **Step 2: The open index tab**

In *The open index tab* paragraph, make three replacements (the old text wraps across lines in the file; match it
by its words), then re-wrap the paragraph to the file's ~120-column lines:

```
per run its key (`<repo>/<run>`), status, revision, a hash of the run as stored,
```

becomes

```
per run its key (`<repo>/<run>`), status, revision, seen number (`revision + attention`), a hash of the run as stored,
```

```
Its title counts the unread runs (*New* or *Updated*) that are not merged or closed, whatever the filters show (`(2) Proofs`, else `Proofs`),
```

becomes

```
Its title counts the unread runs (the rule above) that are not merged or closed, the number the heading shows, whatever the filters show (`(2) Proofs`, else `Proofs`),
```

```
A run leaves both once its page is opened and the index is looked at again.
```

becomes

```
A run leaves both once its page is opened and the index is looked at again, or once it is marked read on the index.
```

- [ ] **Step 3: The README**

In `README.md` §Proofs app (spec Assumption 13), the sentence

```
Chrome, because the index's New/Updated markers read what a run page stored in `localStorage`, and
```

becomes

```
Chrome, because the index's unread marks read what a run page stored in `localStorage`, and
```

- [ ] **Step 4: Check the docs**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter LockStepTest`
Expected: PASS.
Run: ``grep -nF -e '(*New* or *Updated*)' -e 'New/Updated' -e 'stores its `revision`' skills/pipeline/references/engine.md README.md``
Expected: no output.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/references/engine.md README.md
git commit -m "docs(pipeline): the proof index's unread rule, its dot and its heading count (#160)"
```

---

### Task 5: Check the inbox in Chrome, over a copy of the real store

No code and no commit: this proves Tasks 1–3 in the browser (the spec's *In Chrome over `file://`*). Never write to
`~/GitProjects/_proofs`. Every `proof_cli.php` command here runs with `PIPELINE_PROOF_ROOT="$COPY"` and a fake `gh`
first on `PATH` that always fails, so the prune pass after each write never asks GitHub and touches only the copy.
Shell state does not persist between an agent's Bash calls: carry the printed `$COPY` path into later steps
literally. Invoke the `browser-verification` skill for the screenshots.

**Files:** none changed. Works in a `mktemp` dir.

**Interfaces:**
- Consumes: `proof_store_index(string $root): ?string`, `proof_cli.php write|status`, the index page of Task 3.

- [ ] **Step 1: Copy the store, a fake `gh`, a payload, and render with the new code**

```bash
COPY=$(mktemp -d)/_proofs && cp -R ~/GitProjects/_proofs "$COPY" && echo "$COPY"
mkdir -p "$(dirname "$COPY")/bin" && printf '#!/bin/sh\nexit 1\n' > "$(dirname "$COPY")/bin/gh" && chmod +x "$(dirname "$COPY")/bin/gh"
printf '{"repo":"InboxCheck","branch":"feature/inbox-check","pr":99160,"title":"PR #99160: inbox check","clientSummary":"Controle van de inbox.","explainer":{"problem":"A check.","solution":"A check."}}' > "$(dirname "$COPY")/n.json"
php -r 'require "skills/pipeline/checks/proof_store.php"; var_dump(proof_store_index($argv[1]));' "$COPY"
grep -c 'function proofUnread' "$COPY/index.html"
```

Expected: `NULL`, then `1`. Below, `W` stands for
`PATH="$(dirname "$COPY")/bin:$PATH" PIPELINE_PROOF_ROOT="$COPY" php skills/pipeline/checks/proof_cli.php write "$(dirname "$COPY")/n.json"`,
which prints the page `$COPY/InboxCheck/pr-99160-inbox-check/index.html`, and `S <status> […]` for
`PATH="$(dirname "$COPY")/bin:$PATH" PIPELINE_PROOF_ROOT="$COPY" php skills/pipeline/checks/proof_cli.php status "$COPY/InboxCheck/pr-99160-inbox-check/index.html" <status> […]`.

- [ ] **Step 2: Open the index and mark everything read**

`browser_navigate` to `file://$COPY/index.html`. If the Playwright MCP refuses `file:` URLs, serve the copy in the
background (`php -S 127.0.0.1:8160 -t "$COPY"`) and use `http://127.0.0.1:8160/index.html` for every step below; the
run pages then open from the same origin, so the seen marks still meet. The report says which was used.

Mark every run read and reload:
`() => { document.querySelectorAll('#runs tbody tr[data-seen]').forEach(r => localStorage.setItem('seen:' + r.dataset.run, r.dataset.seen)); localStorage.setItem('proof:finished', '1'); location.reload(); }`.
Then define the probe:
`() => { window.probe = () => { const r = document.querySelector('tr[data-run="InboxCheck/pr-99160-inbox-check"]'); const d = r && r.querySelector('button.dot'); return { title: document.title, count: document.getElementById('unread-count').textContent, marker: r && r.querySelector('.marker').textContent, cls: r && r.className, weight: r && getComputedStyle(r.cells[3]).fontWeight, dot: d && d.getAttribute('aria-label'), dotShown: d && getComputedStyle(d).display !== 'none' }; }; return document.title; }`
Expected: `"Proofs"`, and `() => document.getElementById('unread-count').textContent` is `""`.

"Poll now" below means `() => window.dispatchEvent(new Event('focus'))`, then `browser_wait_for` 2 seconds.
"Re-probe" means re-running the probe definition after a navigation.

- [ ] **Step 3: A new run is unread: bold, a filled dot, New, counted**

`W`, poll now, `() => probe()`.
Expected: `title: "(1) Proofs"`, `count: "1 unread"`, `marker: "New"`, `cls: "unread"`, `weight: "700"`,
`dot: "Mark as read"`, `dotShown: true`. Take a screenshot of the table's first rows.

- [ ] **Step 4: Opening it reads it: muted, an outline dot**

Click the run's link (`browser_click` on `PR #99160: inbox check`), `browser_navigate_back`, re-probe, poll now,
`() => probe()`.
Expected: `title: "Proofs"`, `count: ""`, `marker: ""`, `cls: "read"`, `weight: "400"`, `dot: "Mark as unread"`.

- [ ] **Step 5: A halt makes the opened run unread again**

`S halted --reason "inbox check"`, poll now, `() => probe()`.
Expected: `marker: "Halted"`, `cls: "unread"`, `title: "(1) Proofs"`, `count: "1 unread"`, and the favicon is the
halted icon (`() => document.getElementById('favicon').getAttribute('href') === document.getElementById('favicon').dataset.halted`
→ `true`). `cat "$COPY/InboxCheck/pr-99160-inbox-check/run.json"` shows `"attention": 1` and `"revision": 1`.
Take a screenshot.

- [ ] **Step 6: Opened, then marked unread by hand: Halted while halted, Unread once running**

Open the page and come back as in Step 4 (re-probe, poll now): `cls: "read"`. Click the row's dot (`browser_click`
on the button labelled `Mark as unread`), then `() => probe()` → `cls: "unread"`, `marker: "Halted"`,
`count: "1 unread"`, at once (no poll). `() => location.reload()`, re-probe, `() => probe()` → still `cls: "unread"`,
`marker: "Halted"`.
`S running`, poll now, `() => probe()` → `marker: "Unread"`, `cls: "unread"`.

- [ ] **Step 7: Marked read by the dot; a later Ready makes it unread; Merged does not**

Click the dot (`Mark as read`), `() => probe()` → `cls: "read"`, `marker: ""`, `count: ""`.
`S ready`, poll now → `marker: "Ready"`, `cls: "unread"`, the favicon is the ready icon. Open the page and come back
(re-probe, poll now) → `cls: "read"`. `S merged`, poll now → `cls: "read"`, `marker: ""`, `title: "Proofs"`.

- [ ] **Step 8: Phone width, light mode and dark mode**

`browser_resize` to 390×844: `() => document.documentElement.scrollWidth <= window.innerWidth` → `true` (the table
scrolls inside `.table-wrap`), and a screenshot shows the heading count and the dots. Back at 1280×900 in light
mode, with at least one read row (open a run and come back as in Step 4), a screenshot close enough to see the read
row's outline dot: it must be plainly visible on the white background beside the muted text, or the look is not
done. `browser_emulate_media` with
`colorScheme: "dark"`, `browser_resize` back to 1280×900, and a screenshot: the filled dot, the outline dot and the
muted rows are all visible on the dark background. Reset the colour scheme.

- [ ] **Step 9: Console and clean up**

`browser_console_messages`: no errors. Stop the `php -S` server if one ran. Remove the temp dir:
`rm -rf "$(dirname "$COPY")"`. Confirm the live store was not written:
`ls ~/GitProjects/_proofs/InboxCheck` → `No such file or directory`, and
`grep -c 'function proofUnread' ~/GitProjects/_proofs/index.html` → `0` (nothing has rendered the live index with
the new code before the merge).

---

### Task 6: The whole suite

- [ ] **Step 1: Run it**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: every test passes; the count is the base's plus the cases and dataset rows these tasks added.

- [ ] **Step 2: Hooks untouched**

`git diff origin/main...HEAD --stat -- hooks` prints nothing, so `hooks/tests/` need not run.
