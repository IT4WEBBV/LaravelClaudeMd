# Pipeline Visual Proof Store Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give every UI-touching `/pipeline` run a durable, self-contained HTML page at `~/GitProjects/_proofs/<repo>/<branch>/` showing problem, solution and annotated visual proof — and replace the `verify-ui` leg's structurally impossible "proof attached to the PR" promise with something true.

**Architecture:** Three plain-PHP files in the pipeline's existing tested-helper directory (`skills/pipeline/checks/`), plus a thin CLI the `verify-ui` leg shells out to. `proof.php` owns paths, `run.json` I/O and the prune predicate; `proof_render.php` turns a `run.json` into HTML; `proof_cli.php` is the entry point that ingests a payload, downscales screenshots, renders the page and the index, and prunes finished runs. Nothing in the store is ever read back by the engine to make a control-flow decision.

**Tech Stack:** PHP 8.4 (host, no container), Pest via the repo's existing `vendor/bin/pest`, macOS `sips` for image downscaling, `gh` for PR state. No new dependencies, no `package.json`, no new test command.

**Spec:** `docs/superpowers/specs/2026-08-25-pipeline-visual-proof-store-design.md`

## Global Constraints

- **Store root** is `~/GitProjects/_proofs`, overridable via the `PIPELINE_PROOF_ROOT` environment variable. **Every test must set that override** — a test that writes to the real store is a bug.
- **The store is never load-bearing for a gate.** Failing to *capture* proof halts a run (existing rule, unchanged); failing to *file* it logs and continues. No function in this plan may throw in a way that aborts a pipeline run.
- **The engine never reads the store to make a control-flow decision.** Deleting all of `_proofs/` must change no run's behaviour. The only reader is the prune pass, deciding only whether to delete a directory.
- **The page is an impersonal record.** It never addresses a person, never uses second person, never invites a reply. This applies to every string this code emits.
- **The page exists only when `pipeline_triggers(...)['ui']` is true.** Do not add a second predicate for "this change is visual" — reuse `pipeline_triggers()`.
- **Escape everything.** Every value interpolated into HTML goes through `proof_e()`. A screenshot title containing `<` must not break the page.
- **House code style:** plain prefixed functions (`proof_*`), no classes, docblocks that explain *why*, `declare` nothing, match the surrounding files in `skills/pipeline/checks/`.
- **Commit messages:** no `Co-Authored-By` line, no "Generated with Claude Code" or any AI attribution. Never commit to `main`.
- **Test invocation** (from the repo root, not the checks dir):
  ```
  ./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests
  ```
  `--test-directory` **must be relative** — Pest resolves it against the config's root path, so an absolute path produces a doubled, non-existent directory.

### Task 0: One-time worktree setup (do this before Task 1)

`/vendor` is gitignored and therefore absent in a worktree, but `phpunit.xml` bootstraps `../../../vendor/autoload.php`. Symlink the main checkout's vendor into the worktree root once:

```bash
ln -s /Users/jroelofs/GitProjects/LaravelClaudeMd/LaravelClaudeMd/vendor vendor
```

Verify it worked, and that git ignores it:

```bash
./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests
```

Expected: `Tests: 23 passed`. Then `git status --short` must show **no** `vendor` entry.

---

## File Structure

| File | Responsibility |
|---|---|
| `skills/pipeline/checks/proof.php` | Store location, branch/repo slugging, `run.json` read/write, the prune predicate, scanning the store. **No HTML, no shelling out.** |
| `skills/pipeline/checks/proof_render.php` | `run.json` → HTML, for both the run page and the index. **Pure string building — no filesystem.** |
| `skills/pipeline/checks/proof_cli.php` | The entry point `verify-ui` and `review-pr` call. Owns everything impure: reading the payload, `sips`, `gh`, writing files, deleting pruned directories. |
| `skills/pipeline/checks/tests/ProofTest.php` | Tests for `proof.php`. |
| `skills/pipeline/checks/tests/ProofRenderTest.php` | Tests for `proof_render.php`. |
| `skills/pipeline/checks/tests/Pest.php` *(modify)* | Add the two new files to its require list. |
| `skills/pipeline/references/engine.md` *(modify)* | Reword the `verify-ui` row; document the store. |
| `skills/pipeline/references/manifest.md` *(modify)* | Reword the `verifyUi` probe. |
| `skills/pipeline/SKILL.md` *(modify)* | Mention the store in the overview. |
| `skills/browser-verification/SKILL.md` *(modify)* | Point the proof path at the store; keep the companion for the interactive "show me". |

**Deviation from spec §9, flagged deliberately:** the spec enumerates three files. This plan adds a fourth, `proof_cli.php`, because the leg needs a shell entry point and folding CLI argument handling into `proof.php` would make that file untestable as a pure library. The split is *pure library / pure renderer / impure shell*, which is what makes Tasks 1–4 testable without touching `gh`, `sips` or the real store.

---

### Task 1: Store paths, slugging, and `run.json` round-trip

**Files:**
- Create: `skills/pipeline/checks/proof.php`
- Create: `skills/pipeline/checks/tests/ProofTest.php`
- Modify: `skills/pipeline/checks/tests/Pest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `proof_root(): string`, `proof_slug(string $branch): string`, `proof_run_dir(string $root, string $repo, string $branch): string`, `proof_read_run(string $dir): ?array`, `proof_write_run(string $dir, array $run, string $now): array`.

- [ ] **Step 1: Add the new files to the Pest bootstrap**

`skills/pipeline/checks/tests/Pest.php` currently requires a hardcoded list. Add both new filenames now, so later tasks fail with "undefined function" rather than a missing-file fatal:

```php
foreach (['triggers.php', 'pipeline.php', 'manifest.php', 'checks.php', 'proof.php', 'proof_render.php'] as $f) {
```

- [ ] **Step 2: Write the failing test**

Create `skills/pipeline/checks/tests/ProofTest.php`:

```php
<?php

it('slugs a branch into one safe path segment', function () {
    expect(proof_slug('feature/orders-export'))->toBe('feature-orders-export');
    expect(proof_slug('bugfix/ISSUE-42/retry'))->toBe('bugfix-ISSUE-42-retry');
});

it('refuses to let a branch name escape its own directory', function () {
    // A careless branch name must never write outside the run's folder.
    $slug = proof_slug('feature/../../etc/passwd');

    expect($slug)->not->toContain('..');
    expect($slug)->not->toContain('/');
});

it('builds a run directory keyed by repo and branch, never by branch alone', function () {
    // Branch alone collides across ~20 repos that all grow a `feature/fix-typo`.
    $a = proof_run_dir('/store', 'ViewieMedia', 'feature/fix-typo');
    $b = proof_run_dir('/store', 'Deploy', 'feature/fix-typo');

    expect($a)->toBe('/store/ViewieMedia/feature-fix-typo');
    expect($a)->not->toBe($b);
});

it('round-trips a run and preserves createdAt across the second write', function () {
    $dir = sys_get_temp_dir() . '/proof-' . uniqid() . '/ViewieMedia/feature-x';

    $first = proof_write_run($dir, ['repo' => 'ViewieMedia', 'pr' => 412], '2026-08-25T10:00:00+02:00');
    expect($first['createdAt'])->toBe('2026-08-25T10:00:00+02:00');
    expect($first['schema'])->toBe(1);

    $second = proof_write_run($dir, ['repo' => 'ViewieMedia', 'pr' => 412], '2026-08-25T15:30:00+02:00');
    expect($second['createdAt'])->toBe('2026-08-25T10:00:00+02:00');
    expect($second['updatedAt'])->toBe('2026-08-25T15:30:00+02:00');

    expect(proof_read_run($dir))->toBe($second);

    unlink($dir . '/run.json');
});

it('returns null for a directory holding no run', function () {
    expect(proof_read_run(sys_get_temp_dir() . '/proof-missing-' . uniqid()))->toBeNull();
});

it('honours the store-root override so tests never touch the real store', function () {
    putenv('PIPELINE_PROOF_ROOT=/tmp/proof-test-root');
    expect(proof_root())->toBe('/tmp/proof-test-root');

    putenv('PIPELINE_PROOF_ROOT');
    expect(proof_root())->toEndWith('/GitProjects/_proofs');
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter=slugs`
Expected: FAIL with `Call to undefined function proof_slug()`

- [ ] **Step 4: Write the implementation**

Create `skills/pipeline/checks/proof.php`:

```php
<?php

/**
 * The durable visual proof store (`../references/engine.md` §verify-ui).
 *
 * Everything here is a *rendering input*. The engine never reads this store to decide which
 * leg runs next, whether a gate passed, or whether to loop back — deleting the whole of
 * `_proofs/` changes no run's behaviour. That is what keeps a durable store compatible with
 * the skill's non-goal: "no persistent state not reconstructable from git + gh".
 */

/**
 * The store root. `PIPELINE_PROOF_ROOT` exists so tests never write to the real store —
 * a test that pollutes `~/GitProjects/_proofs` would be indistinguishable from a real run.
 */
function proof_root(): string
{
    $override = getenv('PIPELINE_PROOF_ROOT');
    if (is_string($override) && $override !== '') {
        return rtrim($override, '/');
    }

    return rtrim((string) getenv('HOME'), '/') . '/GitProjects/_proofs';
}

/**
 * Branch (or repo) name → exactly one safe path segment.
 *
 * `/` becomes `-`, matching the changelog-fragment convention in CLAUDE.md. Anything outside
 * `[A-Za-z0-9._-]` follows it, and any surviving run of dots is collapsed: `..` is the one
 * sequence that would let a careless branch name write outside its own directory.
 */
function proof_slug(string $name): string
{
    $slug = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
    $slug = preg_replace('/\.{2,}/', '-', $slug);
    $slug = preg_replace('/-{2,}/', '-', $slug);
    $slug = trim($slug, '-.');

    return $slug === '' ? 'unnamed' : $slug;
}

/**
 * Keyed `<repo>/<branch>`, never by branch alone — roughly twenty repos share this store and
 * every one of them eventually grows a `feature/fix-typo`.
 */
function proof_run_dir(string $root, string $repo, string $branch): string
{
    return rtrim($root, '/') . '/' . proof_slug($repo) . '/' . proof_slug($branch);
}

function proof_read_run(string $dir): ?array
{
    $path = rtrim($dir, '/') . '/run.json';
    if (! is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * Write `run.json`, preserving `createdAt` across the two write points a run has:
 * `verify-ui` builds the page, `review-pr` finalises it.
 *
 * `$now` is a parameter rather than a call to `time()` so the round-trip is testable without
 * a clock and a run's timestamps can be made to match the leg that produced them.
 *
 * @return array the run as written, including the fields this function fills in
 */
function proof_write_run(string $dir, array $run, string $now): array
{
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $existing = proof_read_run($dir);

    $run['schema'] = 1;
    $run['createdAt'] = $existing['createdAt'] ?? $now;
    $run['updatedAt'] = $now;

    file_put_contents(
        rtrim($dir, '/') . '/run.json',
        json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
    );

    return $run;
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS — 23 pre-existing tests plus 6 new ones.

- [ ] **Step 6: Commit**

```bash
git add skills/pipeline/checks/proof.php skills/pipeline/checks/tests/ProofTest.php skills/pipeline/checks/tests/Pest.php
git commit -m "feat(pipeline): proof store paths, slugging and run.json round-trip"
```

---

### Task 2: The prune predicate and store scan

**Files:**
- Modify: `skills/pipeline/checks/proof.php` (append)
- Modify: `skills/pipeline/checks/tests/ProofTest.php` (append)

**Interfaces:**
- Consumes: `proof_read_run()` from Task 1.
- Produces: `proof_should_prune(array $run, string $now, int $graceDays = 14): bool`, `proof_scan_runs(string $root): array` returning `list<array{dir: string, run: array}>` newest first.

- [ ] **Step 1: Write the failing test**

Append to `skills/pipeline/checks/tests/ProofTest.php`:

```php
it('prunes a run only once its PR is finished and has been finished a while', function () {
    $now = '2026-08-25T12:00:00+00:00';
    $old = '2026-08-01T12:00:00+00:00';   // 24 days before $now
    $recent = '2026-08-20T12:00:00+00:00'; // 5 days before $now

    expect(proof_should_prune(['pr' => 412, 'prState' => 'MERGED', 'updatedAt' => $old], $now))->toBeTrue();
    expect(proof_should_prune(['pr' => 412, 'prState' => 'CLOSED', 'updatedAt' => $old], $now))->toBeTrue();

    // A PR merged this morning is exactly the one still worth looking at this afternoon.
    expect(proof_should_prune(['pr' => 412, 'prState' => 'MERGED', 'updatedAt' => $recent], $now))->toBeFalse();
});

it('never prunes an open PR, and never prunes a run that opened none', function () {
    $now = '2026-08-25T12:00:00+00:00';
    $old = '2026-08-01T12:00:00+00:00';

    expect(proof_should_prune(['pr' => 412, 'prState' => 'OPEN', 'updatedAt' => $old], $now))->toBeFalse();

    // review-plan bound-exhaustion halts before handoff and opens no PR. Those runs are
    // flagged in the index for manual pruning, never deleted automatically.
    expect(proof_should_prune(['prState' => 'MERGED', 'updatedAt' => $old], $now))->toBeFalse();
    expect(proof_should_prune(['pr' => null, 'prState' => 'MERGED', 'updatedAt' => $old], $now))->toBeFalse();
});

it('never prunes on unusable timestamps', function () {
    $now = '2026-08-25T12:00:00+00:00';

    expect(proof_should_prune(['pr' => 1, 'prState' => 'MERGED', 'updatedAt' => 'not a date'], $now))->toBeFalse();
    expect(proof_should_prune(['pr' => 1, 'prState' => 'MERGED'], $now))->toBeFalse();
    expect(proof_should_prune(['pr' => 1, 'prState' => 'MERGED', 'updatedAt' => '2026-08-01T12:00:00+00:00'], 'nonsense'))->toBeFalse();
});

it('scans every run in the store, newest first', function () {
    $root = sys_get_temp_dir() . '/proof-scan-' . uniqid();

    proof_write_run($root . '/Deploy/feature-a', ['repo' => 'Deploy'], '2026-08-20T10:00:00+00:00');
    proof_write_run($root . '/ViewieMedia/feature-b', ['repo' => 'ViewieMedia'], '2026-08-24T10:00:00+00:00');

    $runs = proof_scan_runs($root);

    expect($runs)->toHaveCount(2);
    expect($runs[0]['run']['repo'])->toBe('ViewieMedia');
    expect($runs[1]['run']['repo'])->toBe('Deploy');
    expect($runs[0]['dir'])->toBe($root . '/ViewieMedia/feature-b');
});

it('scans an empty or missing store without failing', function () {
    expect(proof_scan_runs(sys_get_temp_dir() . '/proof-empty-' . uniqid()))->toBe([]);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter=prunes`
Expected: FAIL with `Call to undefined function proof_should_prune()`

- [ ] **Step 3: Write the implementation**

Append to `skills/pipeline/checks/proof.php`:

```php
/**
 * Pure predicate — no filesystem, no `gh`, no clock.
 *
 * Two rules the store depends on, both stated in the spec's §Retention:
 *  - a PR merged this morning is exactly the one still worth looking at this afternoon,
 *    hence the grace period rather than deleting the moment it closes;
 *  - a run that opened no PR is never auto-pruned. `review-plan` bound-exhaustion halts
 *    *before* `handoff` and deliberately opens none, so those runs exist; the index flags
 *    them for manual pruning rather than a silent cap deleting them.
 *
 * Anything unparseable answers "do not prune". Deleting proof is irreversible; keeping it
 * costs disk.
 */
function proof_should_prune(array $run, string $now, int $graceDays = 14): bool
{
    if (empty($run['pr'])) {
        return false;
    }
    if (! in_array($run['prState'] ?? '', ['MERGED', 'CLOSED'], true)) {
        return false;
    }

    $updated = strtotime((string) ($run['updatedAt'] ?? ''));
    $nowTs = strtotime($now);
    if ($updated === false || $nowTs === false) {
        return false;
    }

    return $updated < $nowTs - $graceDays * 86400;
}

/**
 * Every run in the store, newest first. Shape is fixed at `<root>/<repo>/<branch>/run.json`,
 * so one glob covers the whole store.
 *
 * @return list<array{dir: string, run: array}>
 */
function proof_scan_runs(string $root): array
{
    $runs = [];
    foreach (glob(rtrim($root, '/') . '/*/*/run.json') ?: [] as $path) {
        $dir = dirname($path);
        $run = proof_read_run($dir);
        if ($run !== null) {
            $runs[] = ['dir' => $dir, 'run' => $run];
        }
    }

    usort($runs, fn ($a, $b) => strcmp((string) ($b['run']['updatedAt'] ?? ''), (string) ($a['run']['updatedAt'] ?? '')));

    return $runs;
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS — 34 tests.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/proof.php skills/pipeline/checks/tests/ProofTest.php
git commit -m "feat(pipeline): prune predicate and store scan for the proof store"
```

---

### Task 3: Render the run page

**Files:**
- Create: `skills/pipeline/checks/proof_render.php`
- Create: `skills/pipeline/checks/tests/ProofRenderTest.php`

**Interfaces:**
- Consumes: a `run` array in the shape `proof_write_run()` persists.
- Produces: `proof_e(string $value): string`, `proof_render_run(array $run): string`.

The run array this consumes, in full:

```jsonc
{
  "schema": 1,
  "repo": "ViewieMedia",
  "nameWithOwner": "IT4WEBBV/ViewieMedia",
  "branch": "feature/orders-export",
  "pr": 412,
  "prState": "OPEN",
  "mode": "auto",
  "createdAt": "2026-08-25T10:00:00+02:00",
  "updatedAt": "2026-08-25T15:30:00+02:00",
  "headline": "Order rows gain a product summary grid",
  "problem": "Order rows showed no product detail…",
  "solution": "Added a summary grid to the row partial…",
  "checks": {
    "tests": "142 passed",
    "staticAnalysis": "0 new findings over app/",
    "format": "clean",
    "suppressions": ["app/Http/Livewire/Orders.php:88 — @phpstan-ignore argument.type (not yet judged)"]
  },
  "openQuestions": ["The empty-state copy was not reviewed."],
  "ledger": [{ "gate": "plan-approval", "outcome": "continued", "note": "restored Closes #1926" }],
  "shots": [
    {
      "file": "shots/01-orders-index.png",
      "title": "Orders index",
      "route": "/orders",
      "badges": [{ "num": 1, "topPct": 12.4, "leftPct": 58.0, "title": "Product summary grid", "note": "Was: no product detail in order rows" }]
    }
  ]
}
```

- [ ] **Step 1: Write the failing test**

Create `skills/pipeline/checks/tests/ProofRenderTest.php`:

```php
<?php

function proof_fixture_run(array $overrides = []): array
{
    return array_merge([
        'repo' => 'ViewieMedia',
        'branch' => 'feature/orders-export',
        'pr' => 412,
        'prState' => 'OPEN',
        'mode' => 'auto',
        'updatedAt' => '2026-08-25T15:30:00+02:00',
        'headline' => 'Order rows gain a product summary grid',
        'problem' => 'Order rows showed no product detail.',
        'solution' => 'Added a summary grid to the row partial.',
        'checks' => ['tests' => '142 passed', 'staticAnalysis' => '0 new findings over app/', 'format' => 'clean', 'suppressions' => []],
        'openQuestions' => [],
        'ledger' => [],
        'shots' => [],
    ], $overrides);
}

it('escapes every value it interpolates', function () {
    $html = proof_render_run(proof_fixture_run([
        'headline' => '<script>alert(1)</script>',
        'problem' => 'a & b < c',
    ]));

    expect($html)->not->toContain('<script>alert(1)</script>');
    expect($html)->toContain('&lt;script&gt;');
    expect($html)->toContain('a &amp; b &lt; c');
});

it('renders a self-contained page with only relative image paths', function () {
    $html = proof_render_run(proof_fixture_run([
        'shots' => [['file' => 'shots/01-orders.png', 'title' => 'Orders index', 'route' => '/orders', 'badges' => []]],
    ]));

    expect($html)->toStartWith('<!doctype html>');
    expect($html)->toContain('src="shots/01-orders.png"');
    // A page that reaches the network is not self-contained: it must open over file://
    expect($html)->not->toContain('http://');
    expect($html)->not->toContain('https://');
});

it('places numbered badges from percentage positions and lists them in a legend', function () {
    $html = proof_render_run(proof_fixture_run([
        'shots' => [[
            'file' => 'shots/01-orders.png',
            'title' => 'Orders index',
            'route' => '/orders',
            'badges' => [['num' => 1, 'topPct' => 12.4, 'leftPct' => 58.0, 'title' => 'Product summary grid', 'note' => 'Was: no product detail']],
        ]],
    ]));

    expect($html)->toContain('top:12.4%');
    expect($html)->toContain('left:58%');
    expect($html)->toContain('Product summary grid');
    expect($html)->toContain('Was: no product detail');
});

it('omits the visual section entirely when there are no shots', function () {
    $html = proof_render_run(proof_fixture_run());

    expect($html)->not->toContain('Visual result');
});

it('renders open questions verbatim and flags suppressions as not yet judged', function () {
    $html = proof_render_run(proof_fixture_run([
        'openQuestions' => ['The empty-state copy was not reviewed.'],
        'checks' => ['tests' => '142 passed', 'staticAnalysis' => '0 new findings over app/', 'format' => 'clean', 'suppressions' => ['Orders.php:88 — argument.type']],
    ]));

    expect($html)->toContain('The empty-state copy was not reviewed.');
    expect($html)->toContain('Orders.php:88 — argument.type');
    expect($html)->toContain('not yet judged');
});

it('states the analysed scope rather than an unqualified all-clear', function () {
    $html = proof_render_run(proof_fixture_run());

    // "0 new findings" without its scope reads as covering database/, routes/, config/ and tests/.
    expect($html)->toContain('0 new findings over app/');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter=escapes`
Expected: FAIL with `Call to undefined function proof_render_run()`

- [ ] **Step 3: Write the implementation**

Create `skills/pipeline/checks/proof_render.php`:

```php
<?php

/**
 * `run.json` → HTML. Pure string building: no filesystem, no network, no clock.
 *
 * The page must open over `file://`, so every asset reference is relative and every style
 * is inline. It is an impersonal record — it never addresses a person, never uses second
 * person, and never invites a reply.
 */

function proof_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function proof_render_styles(): string
{
    return <<<'CSS'
:root { --bg:#fff; --fg:#18181b; --muted:#71717a; --line:#e4e4e7; --card:#fafafa; --accent:#dc2626; }
@media (prefers-color-scheme: dark) {
  :root { --bg:#18181b; --fg:#f4f4f5; --muted:#a1a1aa; --line:#3f3f46; --card:#27272a; --accent:#ef4444; }
}
* { box-sizing:border-box; }
body { margin:0; padding:2rem 1.5rem 4rem; background:var(--bg); color:var(--fg);
  font:15px/1.6 ui-sans-serif,-apple-system,"Segoe UI",sans-serif; max-width:60rem; margin-inline:auto; }
h1 { font-size:1.5rem; margin:0 0 .25rem; }
h2 { font-size:1rem; text-transform:uppercase; letter-spacing:.05em; color:var(--muted);
  margin:2.5rem 0 .75rem; padding-bottom:.4rem; border-bottom:1px solid var(--line); }
.meta { color:var(--muted); font-size:.875rem; margin-bottom:.5rem; }
.meta code { background:var(--card); padding:.1rem .35rem; border-radius:.25rem; }
.shot { position:relative; display:block; margin:0 0 .5rem; border:1px solid var(--line); border-radius:.5rem; overflow:hidden; }
.shot img { width:100%; display:block; }
.badge { position:absolute; width:26px; height:26px; border-radius:50%; background:var(--accent);
  color:#fff; font-weight:700; font-size:13px; display:flex; align-items:center; justify-content:center;
  box-shadow:0 2px 6px rgba(0,0,0,.35); transform:translate(-50%,-50%); }
figure { margin:0 0 2rem; }
figcaption { color:var(--muted); font-size:.875rem; margin-bottom:.5rem; }
ol.legend { padding-left:1.25rem; }
ol.legend li { margin-bottom:.4rem; }
ul { padding-left:1.25rem; }
table { border-collapse:collapse; width:100%; font-size:.9rem; }
td, th { text-align:left; padding:.4rem .6rem; border-bottom:1px solid var(--line); vertical-align:top; }
.flag { color:var(--accent); font-weight:600; }
details { margin-top:1rem; }
summary { cursor:pointer; color:var(--muted); }
CSS;
}

/** A block of author-written prose: escaped, with blank lines becoming paragraphs. */
function proof_render_prose(string $text): string
{
    $paragraphs = preg_split('/\R{2,}/', trim($text)) ?: [];
    $out = '';
    foreach ($paragraphs as $p) {
        if (trim($p) === '') {
            continue;
        }
        $out .= '<p>' . nl2br(proof_e(trim($p))) . "</p>\n";
    }

    return $out;
}

function proof_render_shots(array $shots): string
{
    if ($shots === []) {
        return '';
    }

    $out = "<h2>Visual result</h2>\n";
    foreach ($shots as $shot) {
        $badges = '';
        $legend = '';
        foreach ($shot['badges'] ?? [] as $badge) {
            $badges .= sprintf(
                '<span class="badge" style="top:%s%%;left:%s%%">%s</span>',
                proof_e((string) (0 + ($badge['topPct'] ?? 0))),
                proof_e((string) (0 + ($badge['leftPct'] ?? 0))),
                proof_e((string) ($badge['num'] ?? '')),
            );
            $legend .= '<li><strong>' . proof_e((string) ($badge['title'] ?? '')) . '</strong> — '
                . proof_e((string) ($badge['note'] ?? '')) . "</li>\n";
        }

        $out .= "<figure>\n"
            . '<figcaption>' . proof_e((string) ($shot['title'] ?? '')) . ' — <code>'
            . proof_e((string) ($shot['route'] ?? '')) . "</code></figcaption>\n"
            . '<span class="shot"><img alt="' . proof_e((string) ($shot['title'] ?? '')) . '" src="'
            . proof_e((string) ($shot['file'] ?? '')) . '">' . $badges . "</span>\n"
            . ($legend === '' ? '' : "<ol class=\"legend\">\n{$legend}</ol>\n")
            . "</figure>\n";
    }

    return $out;
}

function proof_render_checks(array $checks): string
{
    $rows = '';
    foreach (['tests' => 'Test suite', 'staticAnalysis' => 'Static analysis', 'format' => 'Format'] as $key => $label) {
        if (! empty($checks[$key])) {
            $rows .= '<tr><th>' . proof_e($label) . '</th><td>' . proof_e((string) $checks[$key]) . "</td></tr>\n";
        }
    }

    // A suppression must never arrive reading as already resolved — the agent whose step was
    // blocked is otherwise judging its own excuse.
    foreach ($checks['suppressions'] ?? [] as $suppression) {
        $rows .= '<tr><th class="flag">Suppression</th><td>' . proof_e((string) $suppression)
            . ' <span class="flag">(not yet judged)</span></td></tr>' . "\n";
    }

    return $rows === '' ? '' : "<h2>Checks</h2>\n<table>\n{$rows}</table>\n";
}

function proof_render_list(string $heading, array $items): string
{
    if ($items === []) {
        return '';
    }
    $out = '<h2>' . proof_e($heading) . "</h2>\n<ul>\n";
    foreach ($items as $item) {
        $out .= '<li>' . proof_e((string) $item) . "</li>\n";
    }

    return $out . "</ul>\n";
}

function proof_render_ledger(array $ledger): string
{
    if ($ledger === []) {
        return '';
    }
    $rows = '';
    foreach ($ledger as $entry) {
        $rows .= '<tr><th>' . proof_e((string) ($entry['gate'] ?? '')) . '</th><td>'
            . proof_e((string) ($entry['outcome'] ?? '')) . ' — '
            . proof_e((string) ($entry['note'] ?? '')) . "</td></tr>\n";
    }

    return "<details><summary>Gate ledger</summary>\n<table>\n{$rows}</table>\n</details>\n";
}

function proof_render_run(array $run): string
{
    $title = (string) ($run['headline'] ?? ($run['branch'] ?? 'pipeline run'));
    $pr = empty($run['pr']) ? 'no PR' : '#' . (string) $run['pr'] . ' (' . (string) ($run['prState'] ?? '?') . ')';

    $meta = sprintf(
        '<code>%s</code> · %s · <code>%s</code> · %s mode · %s',
        proof_e((string) ($run['repo'] ?? '')),
        proof_e($pr),
        proof_e((string) ($run['branch'] ?? '')),
        proof_e((string) ($run['mode'] ?? '')),
        proof_e((string) ($run['updatedAt'] ?? '')),
    );

    $body = "<h1>" . proof_e($title) . "</h1>\n<p class=\"meta\">{$meta}</p>\n";

    if (! empty($run['problem'])) {
        $body .= "<h2>Problem</h2>\n" . proof_render_prose((string) $run['problem']);
    }
    if (! empty($run['solution'])) {
        $body .= "<h2>Solution</h2>\n" . proof_render_prose((string) $run['solution']);
    }

    $body .= proof_render_shots($run['shots'] ?? []);
    $body .= proof_render_checks($run['checks'] ?? []);
    $body .= proof_render_list('Open questions', $run['openQuestions'] ?? []);
    $body .= proof_render_ledger($run['ledger'] ?? []);

    $styles = proof_render_styles();

    return "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n"
        . "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n"
        . '<title>' . proof_e($title) . "</title>\n<style>\n{$styles}\n</style>\n</head>\n<body>\n"
        . $body
        . "</body>\n</html>\n";
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS — 40 tests.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/proof_render.php skills/pipeline/checks/tests/ProofRenderTest.php
git commit -m "feat(pipeline): render the proof run page"
```

---

### Task 4: Render the store index

**Files:**
- Modify: `skills/pipeline/checks/proof_render.php` (append)
- Modify: `skills/pipeline/checks/tests/ProofRenderTest.php` (append)

**Interfaces:**
- Consumes: `proof_scan_runs()` output shape from Task 2, `proof_e()` from Task 3.
- Produces: `proof_render_index(array $runs): string` where `$runs` is `list<array{dir: string, run: array}>`.

- [ ] **Step 1: Write the failing test**

Append to `skills/pipeline/checks/tests/ProofRenderTest.php`:

```php
it('links each run by its relative directory so the index works over file://', function () {
    $html = proof_render_index([
        ['dir' => '/store/ViewieMedia/feature-b', 'run' => proof_fixture_run(['repo' => 'ViewieMedia', 'branch' => 'feature/b'])],
    ]);

    expect($html)->toContain('href="ViewieMedia/feature-b/index.html"');
    expect($html)->not->toContain('/store/');
});

it('flags runs that opened no PR, because pruning can never reach them', function () {
    $html = proof_render_index([
        ['dir' => '/store/Deploy/feature-halted', 'run' => proof_fixture_run(['repo' => 'Deploy', 'pr' => null, 'prState' => null])],
    ]);

    expect($html)->toContain('no PR — prune manually');
});

it('renders an empty store without failing', function () {
    $html = proof_render_index([]);

    expect($html)->toStartWith('<!doctype html>');
    expect($html)->toContain('No runs recorded');
});

it('shows the PR number and state for a run that has one', function () {
    $html = proof_render_index([
        ['dir' => '/store/ViewieMedia/feature-b', 'run' => proof_fixture_run(['pr' => 412, 'prState' => 'MERGED'])],
    ]);

    expect($html)->toContain('412');
    expect($html)->toContain('MERGED');
    expect($html)->not->toContain('prune manually');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter="links each run"`
Expected: FAIL with `Call to undefined function proof_render_index()`

- [ ] **Step 3: Write the implementation**

Append to `skills/pipeline/checks/proof_render.php`:

```php
/**
 * The index is the join from a PR back to its page — the PR body deliberately carries no
 * local path, so this is how a run is found again.
 *
 * Links are relative to the store root, so the index works when opened over `file://`.
 *
 * @param list<array{dir: string, run: array}> $runs newest first, from `proof_scan_runs()`
 */
function proof_render_index(array $runs): string
{
    $rows = '';
    foreach ($runs as $entry) {
        $run = $entry['run'];
        $href = proof_slug((string) ($run['repo'] ?? '')) . '/' . proof_slug((string) ($run['branch'] ?? '')) . '/index.html';

        // A run that opened no PR is unreachable by the prune pass by design, so the index
        // is where its accumulation becomes visible rather than silent.
        $pr = empty($run['pr'])
            ? '<span class="flag">no PR — prune manually</span>'
            : proof_e('#' . (string) $run['pr'] . ' ' . (string) ($run['prState'] ?? ''));

        $rows .= '<tr><td><code>' . proof_e((string) ($run['repo'] ?? '')) . '</code></td>'
            . '<td>' . $pr . '</td>'
            . '<td><a href="' . proof_e($href) . '">' . proof_e((string) ($run['headline'] ?? ($run['branch'] ?? ''))) . '</a></td>'
            . '<td>' . proof_e((string) count($run['shots'] ?? [])) . '</td>'
            . '<td>' . proof_e(substr((string) ($run['updatedAt'] ?? ''), 0, 10)) . "</td></tr>\n";
    }

    $body = $rows === ''
        ? "<p class=\"meta\">No runs recorded.</p>\n"
        : "<table>\n<tr><th>Repo</th><th>PR</th><th>Run</th><th>Shots</th><th>Updated</th></tr>\n{$rows}</table>\n";

    $styles = proof_render_styles();

    return "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n"
        . "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n"
        . "<title>Pipeline proof store</title>\n<style>\n{$styles}\n</style>\n</head>\n<body>\n"
        . "<h1>Pipeline proof store</h1>\n"
        . $body
        . "</body>\n</html>\n";
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS — 44 tests.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/proof_render.php skills/pipeline/checks/tests/ProofRenderTest.php
git commit -m "feat(pipeline): render the proof store index"
```

---

### Task 5: The `write` CLI entry point

**Files:**
- Create: `skills/pipeline/checks/proof_cli.php`

**Interfaces:**
- Consumes: everything from Tasks 1–4.
- Produces: a shell contract —
  ```
  php skills/pipeline/checks/proof_cli.php write <payload.json>   # → prints the run page path
  php skills/pipeline/checks/proof_cli.php prune                  # → Task 6
  ```
  The payload is the run array from Task 3, plus a `shotSources` list of absolute PNG paths to ingest in order.

- [ ] **Step 1: Write the implementation**

This task has no unit test: it is the impure shell (filesystem, `sips`, `gh`) that Tasks 1–4 were factored to keep out of the tested library. It is verified end-to-end in Step 2.

Create `skills/pipeline/checks/proof_cli.php`:

```php
<?php

/**
 * Entry point for the proof store. Everything impure lives here — reading the payload,
 * `sips`, `gh`, writing files, deleting pruned directories — so `proof.php` and
 * `proof_render.php` stay testable without touching any of it.
 *
 *   php proof_cli.php write <payload.json>
 *   php proof_cli.php prune
 *
 * Never exits non-zero for a store problem. Failing to *file* proof must not halt a run;
 * only failing to *capture* it does, and that is the leg's decision, not this script's.
 */

require_once __DIR__ . '/proof.php';
require_once __DIR__ . '/proof_render.php';

/**
 * Downscale to at most 1600px wide. PNG is kept rather than JPEG: JPEG artefacts on UI text
 * are exactly the kind of difference a proof page must not introduce.
 */
function proof_cli_ingest_shot(string $source, string $destination): bool
{
    if (! is_file($source) || ! copy($source, $destination)) {
        return false;
    }

    $size = @getimagesize($destination);
    if (is_array($size) && $size[0] > 1600) {
        exec('sips --resampleWidth 1600 ' . escapeshellarg($destination) . ' 2>/dev/null', $out, $code);
    }

    return true;
}

function proof_cli_write(string $payloadPath): int
{
    $payload = json_decode((string) @file_get_contents($payloadPath), true);
    if (! is_array($payload)) {
        fwrite(STDERR, "proof: unreadable payload at {$payloadPath}\n");

        return 0;
    }

    $root = proof_root();
    $dir = proof_run_dir($root, (string) ($payload['repo'] ?? 'unknown'), (string) ($payload['branch'] ?? 'unknown'));

    if (! is_dir($dir . '/shots') && ! mkdir($dir . '/shots', 0777, true) && ! is_dir($dir . '/shots')) {
        fwrite(STDERR, "proof: cannot create {$dir}/shots\n");

        return 0;
    }

    $sources = $payload['shotSources'] ?? [];
    unset($payload['shotSources']);

    foreach (array_values($sources) as $i => $source) {
        $name = sprintf('%02d-%s.png', $i + 1, proof_slug((string) ($payload['shots'][$i]['route'] ?? 'state')));
        if (proof_cli_ingest_shot((string) $source, $dir . '/shots/' . $name)) {
            $payload['shots'][$i]['file'] = 'shots/' . $name;
        }
    }

    $run = proof_write_run($dir, $payload, date('c'));

    file_put_contents($dir . '/index.html', proof_render_run($run));
    file_put_contents($root . '/index.html', proof_render_index(proof_scan_runs($root)));

    echo $dir . "/index.html\n";

    return 0;
}

$command = $argv[1] ?? '';

if ($command === 'write') {
    exit(proof_cli_write($argv[2] ?? ''));
}

fwrite(STDERR, "usage: proof_cli.php write <payload.json> | prune\n");
exit(0);
```

- [ ] **Step 2: Verify end-to-end against a throwaway store**

Create a payload and run it against an overridden root, so the real store is untouched:

```bash
cat > /tmp/proof-payload.json <<'JSON'
{
  "repo": "ViewieMedia",
  "branch": "feature/orders-export",
  "pr": 412,
  "prState": "OPEN",
  "mode": "auto",
  "headline": "Order rows gain a product summary grid",
  "problem": "Order rows showed no product detail.",
  "solution": "Added a summary grid to the row partial.",
  "checks": { "tests": "142 passed", "staticAnalysis": "0 new findings over app/", "format": "clean", "suppressions": [] },
  "openQuestions": [],
  "ledger": [],
  "shots": [],
  "shotSources": []
}
JSON

PIPELINE_PROOF_ROOT=/tmp/proof-store php skills/pipeline/checks/proof_cli.php write /tmp/proof-payload.json
```

Expected: prints `/tmp/proof-store/ViewieMedia/feature-orders-export/index.html`.

Then confirm both pages exist and the index links resolve:

```bash
open /tmp/proof-store/index.html
```

Expected: the index lists one ViewieMedia run at PR 412; clicking through opens the run page showing Problem, Solution and Checks, and **no** Visual result section (no shots in this payload).

- [ ] **Step 3: Verify a second write preserves `createdAt`**

```bash
PIPELINE_PROOF_ROOT=/tmp/proof-store php skills/pipeline/checks/proof_cli.php write /tmp/proof-payload.json
grep -E 'createdAt|updatedAt' /tmp/proof-store/ViewieMedia/feature-orders-export/run.json
```

Expected: `createdAt` unchanged from the first run, `updatedAt` advanced.

- [ ] **Step 4: Clean up and commit**

```bash
rm -rf /tmp/proof-store /tmp/proof-payload.json
git add skills/pipeline/checks/proof_cli.php
git commit -m "feat(pipeline): proof store write CLI with screenshot ingestion"
```

---

### Task 6: The prune pass

**Files:**
- Modify: `skills/pipeline/checks/proof_cli.php` (append `proof_cli_prune()`, extend the command switch)

**Interfaces:**
- Consumes: `proof_scan_runs()`, `proof_should_prune()`, `proof_write_run()`.
- Produces: the `prune` subcommand, run automatically at the end of every `write`.

- [ ] **Step 1: Write the implementation**

Append to `skills/pipeline/checks/proof_cli.php`, above the command switch:

```php
/**
 * Refresh one run's PR state from `gh`. A failure returns null and the stored state is kept:
 * a stale `OPEN` simply means the run is not pruned this pass, which is the safe direction.
 */
function proof_cli_pr_state(array $run): ?string
{
    if (empty($run['pr']) || empty($run['nameWithOwner'])) {
        return null;
    }

    $command = sprintf(
        'gh pr view %s --repo %s --json state --jq .state 2>/dev/null',
        escapeshellarg((string) $run['pr']),
        escapeshellarg((string) $run['nameWithOwner']),
    );

    exec($command, $output, $code);
    $state = trim(implode('', $output));

    return ($code === 0 && $state !== '') ? $state : null;
}

function proof_cli_rmdir(string $dir): void
{
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $path) {
        $name = basename($path);
        if ($name === '.' || $name === '..') {
            continue;
        }
        is_dir($path) ? proof_cli_rmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}

/**
 * Housekeeping over the store's own contents — never a decision about a run.
 *
 * `gh` failures are non-fatal by design: no network, a rate limit or an auth problem skips
 * the pass rather than breaking a pipeline run.
 */
function proof_cli_prune(): int
{
    $root = proof_root();
    $now = date('c');
    $pruned = 0;

    foreach (proof_scan_runs($root) as $entry) {
        $run = $entry['run'];

        $state = proof_cli_pr_state($run);
        if ($state !== null && $state !== ($run['prState'] ?? null)) {
            $run['prState'] = $state;
            // Preserve updatedAt: the grace period measures age since the run was last
            // written, not since this housekeeping pass noticed the PR had closed.
            $run['updatedAt'] = $run['updatedAt'] ?? $now;
            file_put_contents(
                $entry['dir'] . '/run.json',
                json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            );
        }

        if (proof_should_prune($run, $now)) {
            proof_cli_rmdir($entry['dir']);
            $pruned++;
        }
    }

    file_put_contents($root . '/index.html', proof_render_index(proof_scan_runs($root)));
    echo "proof: pruned {$pruned} run(s)\n";

    return 0;
}
```

Extend the command switch at the bottom of the file:

```php
$command = $argv[1] ?? '';

if ($command === 'write') {
    $status = proof_cli_write($argv[2] ?? '');
    proof_cli_prune();
    exit($status);
}

if ($command === 'prune') {
    exit(proof_cli_prune());
}

fwrite(STDERR, "usage: proof_cli.php write <payload.json> | prune\n");
exit(0);
```

- [ ] **Step 2: Verify pruning deletes only finished, aged runs**

Build a store by hand with three runs — one merged and old, one merged and recent, one with no PR — then prune. `nameWithOwner` is omitted so no `gh` call is made and the stored states are used as-is.

**These timestamps are relative to today.** They assume the current date is close to 2026-08-25. If it is not, set `old` to roughly 30 days ago and `recent` to roughly 5 days ago — otherwise the "recent" run ages past the 14-day grace period and the expected counts below are wrong.

```bash
mkdir -p /tmp/proof-store/{A/old,A/recent,B/nopr}
echo '{"schema":1,"repo":"A","branch":"old","pr":1,"prState":"MERGED","updatedAt":"2026-07-01T00:00:00+00:00","shots":[]}'    > /tmp/proof-store/A/old/run.json
echo '{"schema":1,"repo":"A","branch":"recent","pr":2,"prState":"MERGED","updatedAt":"2026-08-24T00:00:00+00:00","shots":[]}' > /tmp/proof-store/A/recent/run.json
echo '{"schema":1,"repo":"B","branch":"nopr","prState":"MERGED","updatedAt":"2026-07-01T00:00:00+00:00","shots":[]}'          > /tmp/proof-store/B/nopr/run.json

PIPELINE_PROOF_ROOT=/tmp/proof-store php skills/pipeline/checks/proof_cli.php prune
ls /tmp/proof-store/A /tmp/proof-store/B
```

Expected: `proof: pruned 1 run(s)`; `A/` still contains `recent` but not `old`; `B/nopr` survives.

- [ ] **Step 3: Verify the index flags the surviving PR-less run**

```bash
grep -c "prune manually" /tmp/proof-store/index.html
```

Expected: `1`.

- [ ] **Step 4: Clean up and commit**

```bash
rm -rf /tmp/proof-store
git add skills/pipeline/checks/proof_cli.php
git commit -m "feat(pipeline): prune finished runs from the proof store"
```

---

### Task 7: Reword the false promise and wire the legs

**Files:**
- Modify: `skills/pipeline/references/engine.md:87` (the `verify-ui` table row) and the Stations section
- Modify: `skills/pipeline/references/manifest.md:120` (the `verifyUi` probe row)
- Modify: `skills/pipeline/SKILL.md` (overview bullet list)
- Modify: `skills/browser-verification/SKILL.md` (the proof-delivery section)

**Interfaces:**
- Consumes: the CLI contract from Tasks 5–6.
- Produces: prose only. No code.

This is the task that makes two currently-false statements true. It is deliberately last: until Tasks 1–6 land, the documents would describe machinery that does not exist.

- [ ] **Step 1: Reword the `verify-ui` row in `engine.md`**

Replace the autonomous-form cell of the `verify-ui` row (currently "runs the check, **attaches annotated proof to the PR**") with:

```
runs the check, writes the run's page to the **proof store** (`~/GitProjects/_proofs/<repo>/<branch>/`) via `checks/proof_cli.php write`, and posts a **text-only** record comment to the PR
```

- [ ] **Step 2: Wire the second write into the `review-pr` row in `engine.md`**

Spec §4 gives the page **two** write points; Step 1 covered only the first. Append to the Manifest-I/O cell of the `review-pr` row:

```
; when the run has a proof page (`ui` fired), re-runs `checks/proof_cli.php write` with the finalised open questions and gate ledger
```

Then add to the `review-pr` autonomous-form cell, so an executor does not have to infer it:

```
The second write is a full payload, not a patch — `proof_cli.php write` always replaces the page, and `proof_write_run()` preserves `createdAt` across it.
```

- [ ] **Step 3: Add a proof-store subsection to `engine.md`**

Insert after the Stations table:

```markdown
## The proof store — where the visual record actually lives

**GitHub has no public API for putting an image into a PR comment.** `gh` exposes none; comment
attachments exist only via drag-drop in the web UI. So a leg that claims to attach visual proof
to a PR cannot do it, and the claim previously made here was unimplementable.

`verify-ui` instead writes a self-contained page to `~/GitProjects/_proofs/<repo>/<branch>/`
— keyed by repo *and* branch, because branch alone collides across the repos that share this
store. The page carries Problem, Solution, the annotated screenshots, the scope-qualified check
result and any open questions. `review-pr` rewrites it once more to finalise open questions and
the ledger. A store-wide `index.html` is the join from a PR back to its page.

**The PR still gets a comment, and it is load-bearing.** The manifest is reconstructable from
git + gh (`manifest.md` §reconstruction), so the only durable evidence that this non-skippable
gate ran must live on the PR. The comment records *what* was verified — routes, states, outcome,
shot count — and no longer claims to carry the images themselves.

**The store is never load-bearing for a gate.** Failing to *capture* proof still halts the run;
failing to *file* it logs and continues. The engine never reads the store to decide anything:
deleting all of `_proofs/` changes no run's behaviour, which is what keeps a durable store
compatible with the non-goal "no persistent state not reconstructable from git + gh".
```

- [ ] **Step 4: Reword the `verifyUi` probe in `manifest.md`**

Replace the `verifyUi` probe row:

```
| `verifyUi` | a `browser-verification` **record comment** is attached to the PR (text-only — the images live in the proof store, `engine.md` §The proof store) |
```

- [ ] **Step 5: Add the store to the `SKILL.md` overview**

Append to the reference bullet list in `skills/pipeline/SKILL.md`:

```markdown
- **Visual proof** — when `pipeline_triggers(...)['ui']` fires, `verify-ui` writes a durable page to
  `~/GitProjects/_proofs/<repo>/<branch>/index.html` and the PR gets a text-only record comment
  (`references/engine.md` §The proof store). Backend-only runs are unaffected.
```

- [ ] **Step 6: Point `browser-verification` at the store**

In `skills/browser-verification/SKILL.md`, replace the `REQUIRED SUB-SKILL` paragraph with:

````markdown
**REQUIRED: proof must be durable.** Write the proof page to the **proof store** by building a
payload and running:

```
php ~/.claude/skills/pipeline/checks/proof_cli.php write <payload.json>
```

It prints the page path; open that. Pasting screenshots inline in the terminal is NOT a
substitute, and neither is the brainstorming visual companion — that server is session-scoped,
serves only its newest file, and exits after 4 hours idle, so nothing written there survives to
review time.

The companion is still the right tool for the interactive **"show me" hand-off** (§6), where the
user is present and wants the live page rather than a record.
````

Then update the two matching rationalization-table rows to name the store instead of the companion.

- [ ] **Step 7: Verify no stale claim survives**

```bash
grep -rn "attaches annotated proof\|proof comment is attached" skills/
```

Expected: no matches.

- [ ] **Step 8: Run the full suite one last time**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS — 44 tests.

- [ ] **Step 9: Commit**

```bash
git add skills/pipeline/references/engine.md skills/pipeline/references/manifest.md skills/pipeline/SKILL.md skills/browser-verification/SKILL.md
git commit -m "docs(pipeline): replace the unimplementable PR-proof promise with the proof store"
```

---

## Manual end-to-end verification (spec §Rollout step 4)

After Task 7, run one real UI-touching `/pipeline` run and confirm:

- [ ] The page appears at `~/GitProjects/_proofs/<repo>/<branch>/index.html` and opens over `file://` with screenshots visible.
- [ ] Badges land on the right elements and the legend matches them.
- [ ] `~/GitProjects/_proofs/index.html` lists the run and its link resolves.
- [ ] The PR carries a text-only `verify-ui` record comment, and that comment makes no claim to contain images.
- [ ] A backend-only run creates **nothing** under `_proofs/`.

## Notes for the reviewer

- **Spec decision 10 is deliberately unreversed here.** The PR body and the record comment carry no local path; the index is the join. The spec flags this as the most likely thing to be wrong and a one-line reversal. Do not "fix" it as part of this plan.
- **`proof_cli.php` is a fourth file the spec's §9 did not enumerate.** The reason is in the File Structure section above: it isolates everything impure so Tasks 1–4 are testable.
- **Nothing here is unit-tested against `gh`, `sips` or Playwright**, by design (spec §Testing strategy). Those are covered by the manual end-to-end run.
