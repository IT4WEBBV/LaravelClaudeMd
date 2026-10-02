# The open proof index shows what changed in its tab, and no run page opens by itself — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every store write leaves a `status.js` beside the store index; the index, left open in a tab, polls it every
30 seconds and on focus, updates its rows in place and shows the unread count in its title and a red, green, blue or
neutral favicon dot; no step, brief, workflow prompt or skill text opens a proof page any more.

**Architecture:** `proof_render.php` renders `status.js` from the same `{dir, run}` entries and the same row function
as the index (`proof_render_status_js()`), so every column stays rendered and tested in PHP; the script only swaps
whole rows by a hash. One `proof_store_index()` in `proof_store.php` writes `index.html` and `status.js` from one scan
and replaces the three places that write the index today. The automatic open goes from `brief.php`, the workflow
script and `dispatch_cli.php launch`; `proof_cli.php open` stays for opening a page by hand.

**Tech Stack:** PHP 8.4 (no framework), Pest 4 in `skills/pipeline/checks/tests`, vanilla JS inline in the rendered
page, `node --check` for syntax, Node 24 for the workflow script's replay, headless Chrome and the Playwright MCP for
the browser check.

**Spec:** `docs/superpowers/specs/2026-10-02-pipeline-proof-index-tab-signal-design.md`. Read it with this plan: the
plan argues from it, and its `## Assumptions` 1–15 are the answers this plan builds on (11–15 were added by the plan
step).

## Global Constraints

- Run every command from the worktree root
  `/Users/jroelofs/GitProjects/LaravelClaudeMd/LaravelClaudeMd/.claude/worktrees/worktree-issue-154-pipeline-the-open-proof-index-shows-what-changed`.
- Tests: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
  (append `--filter '<pattern>'` for a subset). This repo is not a Docker project: Pest runs on the host. The worktree
  has no `vendor/` yet: run `composer install` once first. The suite needs `node` on `PATH`.
- Test-first: write the test, see it fail for the reason given, then write the code. `php -l` every PHP file you
  change; `node --check` the workflow script after Task 4.
- `status.js` is exactly `window.proofStatus = <json>;\n`, the JSON `{"runs":[{key, status, revision, hash, row}, …]}`
  in the index's attention order (`proof_index_order()`). `key` is `<repo>/<run>` (the last two directories),
  `revision` an int or `null`, `hash` 12 lowercase hex digits, `row` exactly `proof_render_index_row()`'s output.
  Encoded with `json_encode()`'s default escaping plus `JSON_INVALID_UTF8_SUBSTITUTE` (spec Assumption 13).
- `status.js` is written to `status.js.tmp` and renamed over `status.js`; `index.html` keeps its plain write.
- The poll interval is `30000` ms. The index's `<title>` is `Proofs`, `(<n>) Proofs` with unread runs; its `<h1>`
  stays `Pipeline proof store`.
- The favicon colours: halted `#dc2626`, ready `#16a34a`, unread `#2563eb`, none an unfilled `#71717a` ring.
- The run page (`proof_render_run()`) does not change, and neither does the seen write on it.
- `PIPELINE_OPEN_CMD` stays and keeps every test from opening a browser. Nothing reads `PIPELINE_NO_OPEN` afterwards.
- **No step writes to the real store** (`~/GitProjects/_proofs`) or runs `prune` or `write` against it. The browser
  check works on a copy, with a fake `gh` first on `PATH` that always fails.
- Older specs that describe opening (2026-09-16, 09-22, 09-23, 09-28, 10-02 *links to index*) stay as they are.
- No changelog entry: the repo has no `.changelog/` and no `CHANGELOG.md`.
- Commits: no `Co-Authored-By`, no AI attribution; every message ends on `(#154)`.
- Sibling run #152 (the Proofs app) had no PR when this plan was written. Merge the base only when a step's brief says
  so (pipeline `engine.md` §Catching up with the base).

## Review Focus

1. **A title or summary holding `</script>`, a quote or U+2028** — `status.js` stays one valid script and the row
   inside it stays escaped. Pinned in Task 1's *keeps status.js one valid script* case (`node --check`).
2. **A poll that lands while `status.js` is being written, or finds none** — the rename makes a half-written file
   impossible, and a failed load leaves the page as it was. The rename and the absent `.tmp` are pinned in Task 3; a
   missing `status.js` is checked in the browser in Task 6, Step 9.
3. **A browser without `localStorage`** (a private window, blocked site data) — nothing is unread: the title is
   `Proofs`, the icon the ring, and nothing throws. Pinned in Task 2's script case (`unread()` returns `''` without
   storage, and `tab()` reads only `unread()`).
4. **A filter or search hiding an unread halted run** — it still counts and still turns the dot red (spec Assumption
   2). Pinned in Task 2's script case (`tab()` filters on `data-finished` and `unread()` only, never on `hidden`) and
   checked in Task 6, Step 7.
5. **An unread run that turns merged or closed** — it leaves the count, and *Show merged and closed (n)* counts it.
   Checked in Task 6, Step 8; the `finished-count` span is pinned in Task 2.

## File Structure

- Modify `skills/pipeline/checks/proof_render.php`: `proof_render_index()` (570–596), `proof_render_index_controls()`
  (616–633), `proof_render_index_row()` (679–728) and new `proof_index_key()`, `proof_index_row_hash()`,
  `proof_render_status_js()` (Task 1); new `proof_index_icons()`, `proof_render_favicon()`, and
  `proof_render_index_script()` (779–888) rewritten (Task 2).
- Modify `skills/pipeline/checks/proof_store.php`: new `proof_store_index()`; `proof_store_file()` (47–51) and
  `proof_store_amend()` (160–193) call it (Task 3).
- Modify `skills/pipeline/checks/proof_cli.php`: `proof_cli_prune()` (193) calls it (Task 3); `proof_cli_open()`'s
  docblock (43–58) (Task 4).
- Modify `skills/pipeline/checks/proof.php`: `proof_open_argv()` (487–528) (Task 4).
- Modify `skills/pipeline/checks/brief.php` (109–112, 555), `skills/pipeline/checks/dispatch_cli.php` (256),
  `skills/pipeline/workflow/pipeline-autoflow.js` (134–136) (Task 4).
- Modify tests: `tests/Pest.php` (a helper, Task 1), `tests/ProofRenderTest.php` (Tasks 1, 2),
  `tests/ProofWriteTest.php`, `tests/ProofStatusTest.php`, `tests/RunCostTest.php` (Task 3), `tests/ProofOpenTest.php`,
  `tests/BriefTest.php`, `tests/DispatchCliTest.php`, `tests/AutoflowScriptTest.php` (Task 4).
- Modify docs: `skills/pipeline/references/engine.md` §The proof store, *The open index tab* (Task 3); engine.md's
  other mentions, `skills/pipeline/SKILL.md`, `skills/orchestrate/SKILL.md`, `skills/orchestrate/references/commands.md`
  (Task 5).

---

### Task 1: `status.js` — each run's key, status, revision, hash and row

**Files:**
- Modify: `skills/pipeline/checks/proof_render.php:570-596` (`proof_render_index()`), `:679-728`
  (`proof_render_index_row()`), new functions beside the row
- Modify: `skills/pipeline/checks/tests/Pest.php` (append a helper)
- Test: `skills/pipeline/checks/tests/ProofRenderTest.php` (two existing cases rewritten, four added)

**Interfaces:**
- Consumes: `proof_run_json(array $run): string` and `ProofRunStatus::of(array $run)` (`proof.php`);
  `proof_index_order(array $runs): array`; test helpers `proof_index_entry(string $name, array $run): array`
  (`/store/Deploy/<name>`), `proof_fixture_run()`, `proof_cost_step()` in `ProofRenderTest.php`.
- Produces: `proof_index_key(array $entry): string`; `proof_index_row_hash(array $entry): string`;
  `proof_render_index_row(array $entry): string` (the `int $number` parameter is gone); `proof_render_status_js(array $runs): string`;
  test helper `proof_test_status_runs(string $js): array` in `Pest.php` (the `runs` list of a `status.js` text).

- [ ] **Step 0: Install dependencies (once)**

Run: `composer install`
Expected: `vendor/bin/pest` exists.

- [ ] **Step 1: Add the test helper**

Append to `skills/pipeline/checks/tests/Pest.php`:

```php
/** The runs a `status.js` text carries: the JSON between `window.proofStatus = ` and the closing `;`. */
function proof_test_status_runs(string $js): array
{
    expect($js)->toStartWith('window.proofStatus = ')->toEndWith(";\n");

    return json_decode(substr($js, strlen('window.proofStatus = '), -strlen(";\n")), true, 512, JSON_THROW_ON_ERROR)['runs'];
}
```

- [ ] **Step 2: Write the failing tests**

In `skills/pipeline/checks/tests/ProofRenderTest.php`, replace the case
`gives each row what the index script needs, its status, its figures, and a copy button only with a summary`
(line 491) whole with:

```php
it('gives each row what the index script needs, its status, its figures, and a copy button only with a summary', function () {
    $ready = proof_index_entry('pr-5-logs', [
        'revision' => 3, 'status' => ['state' => 'ready'], 'updatedAt' => '2026-10-01T10:00:00+02:00',
        'clientSummary' => 'De logboeken lopen mee.',
        'cost' => [['workflow' => 'wf_a', 'span' => 1200.0, 'steps' => [proof_cost_step('implement:run', 2310000.0)]]],
    ]);
    $halted = ['dir' => '/store/Asimo/feature-old', 'run' => proof_fixture_run(['repo' => 'Asimo', 'updatedAt' => '2026-09-01T10:00:00+02:00', 'status' => ['state' => 'halted', 'reason' => 'CI red']])];
    $html = proof_render_index([$ready, $halted]);
    $copy = 'summary-' . substr(sha1('Deploy/pr-5-logs'), 0, 8);

    expect($html)->toContain('<tr data-run="Deploy/pr-5-logs" data-repo="Deploy" data-group="1" data-status="ready" data-finished="0" data-updated="2026-10-01T10:00:00+02:00" data-revision="3" data-search="pr #412: product summary grid #412 feature/orders-export de logboeken lopen mee." data-hash="' . proof_index_row_hash($ready) . '">');
    expect($html)->toContain('<tr data-run="Asimo/feature-old" data-repo="Asimo" data-group="0" data-status="halted" data-finished="0" data-updated="2026-09-01T10:00:00+02:00" data-search="pr #412: product summary grid #412 feature/orders-export" data-hash="' . proof_index_row_hash($halted) . '">');
    expect($html)->toContain('<td data-sort="0"><span class="pill pill-halted">Halted</span> <span class="reason">CI red</span></td>');
    expect($html)->toContain('index.html">PR #412: product summary grid</a><span class="marker"></span></td>');
    expect($html)->toContain('<td class="num" data-sort="1200">20.0 min</td><td class="num" data-sort="2310000">2.31M</td>');
    expect($html)->toContain('<td class="num" data-sort=""></td><td class="num" data-sort=""></td>');
    expect($html)->toContain("<button type=\"button\" class=\"copy\" data-copy=\"{$copy}\">Copy</button><span id=\"{$copy}\" lang=\"nl\" hidden>De logboeken lopen mee.</span>");
    expect(substr_count($html, 'class="copy"'))->toBe(1);
    expect($html)->toContain('<select id="repo-filter"><option value="">All repos</option><option value="Asimo">Asimo</option><option value="Deploy">Deploy</option></select>');
});
```

Replace the case `gives each row its status, whether it is finished, and the lower-cased text the search matches`
(line 572) whole with:

```php
it('gives each row its status, whether it is finished, and the lower-cased text the search matches', function () {
    $merged = proof_index_entry('pr-5-logs', [
        'title' => 'PR #5: Logs That Follow', 'pr' => 5, 'branch' => 'feature/Logs', 'status' => ['state' => 'merged'],
        'clientSummary' => 'De Logboeken lopen mee.', 'updatedAt' => '2026-10-01T10:00:00+02:00',
    ]);
    $halted = proof_index_entry('feature-halted', [
        'title' => null, 'pr' => null, 'prState' => null, 'branch' => 'feature/halted',
        'status' => ['state' => 'halted', 'reason' => 'r'], 'updatedAt' => '2026-09-01T10:00:00+02:00',
    ]);
    $html = proof_render_index([$merged, $halted]);

    expect($html)->toContain('<tr data-run="Deploy/pr-5-logs" data-repo="Deploy" data-group="2" data-status="merged" data-finished="1" data-updated="2026-10-01T10:00:00+02:00" data-search="pr #5: logs that follow #5 feature/logs de logboeken lopen mee." data-hash="' . proof_index_row_hash($merged) . '">');
    // Without a title the run is named by its branch, which the search text then holds once.
    expect($html)->toContain('<tr data-run="Deploy/feature-halted" data-repo="Deploy" data-group="0" data-status="halted" data-finished="0" data-updated="2026-09-01T10:00:00+02:00" data-search="feature/halted" data-hash="' . proof_index_row_hash($halted) . '">');
});
```

Append after the case `escapes the repo, the title, the reason and the summary in the index`:

```php
it('names a row\'s copy target by its run, the same on every render', function () {
    $entry = proof_index_entry('pr-5-logs', ['clientSummary' => 'De logboeken lopen mee.']);
    $id = 'summary-' . substr(sha1('Deploy/pr-5-logs'), 0, 8);

    expect(proof_render_index_row($entry))->toBe(proof_render_index_row($entry))
        ->toContain("data-copy=\"{$id}\"")->toContain("<span id=\"{$id}\" lang=\"nl\" hidden>");
    expect(proof_render_index_row(proof_index_entry('pr-6-other', ['clientSummary' => 'x'])))->not->toContain($id);
});

it('renders status.js with each run\'s key, status, revision, hash and row, in the attention order', function () {
    $ready = proof_index_entry('pr-5-logs', ['revision' => 3, 'status' => ['state' => 'ready'], 'updatedAt' => '2026-10-01T10:00:00+02:00']);
    $halted = proof_index_entry('pr-6-old', ['status' => ['state' => 'halted', 'reason' => 'CI red'], 'updatedAt' => '2026-09-01T10:00:00+02:00']);

    $runs = proof_test_status_runs(proof_render_status_js([$ready, $halted]));

    expect(array_column($runs, 'key'))->toBe(['Deploy/pr-6-old', 'Deploy/pr-5-logs']);
    expect($runs[1])->toBe([
        'key' => 'Deploy/pr-5-logs',
        'status' => 'ready',
        'revision' => 3,
        'hash' => proof_index_row_hash($ready),
        'row' => proof_render_index_row($ready),
    ]);
    // A run filed before revisions existed has none, and gets no marker.
    expect($runs[0])->toMatchArray(['status' => 'halted', 'revision' => null]);
    expect(proof_index_row_hash($ready))->toMatch('/^[0-9a-f]{12}$/');
    expect($runs[1]['row'])->toContain(' data-hash="' . proof_index_row_hash($ready) . '">');
    expect(proof_test_status_runs(proof_render_status_js([])))->toBe([]);
});

it('changes a run\'s hash when its status, revision or cost changes, and only then', function () {
    $entry = proof_index_entry('pr-5-logs', ['revision' => 1, 'status' => ['state' => 'running']]);
    $hash = proof_index_row_hash($entry);
    $with = fn (array $changes): string => proof_index_row_hash(['dir' => $entry['dir'], 'run' => [...$entry['run'], ...$changes]]);

    expect(proof_index_row_hash($entry))->toBe($hash);
    expect($with(['status' => ['state' => 'halted', 'reason' => 'CI red']]))->not->toBe($hash);
    expect($with(['revision' => 2]))->not->toBe($hash);
    expect($with(['cost' => [['workflow' => 'wf_a', 'span' => 60.0, 'steps' => []]]]))->not->toBe($hash);
    expect(proof_index_row_hash(['dir' => '/store/Asimo/pr-5-logs', 'run' => $entry['run']]))->not->toBe($hash);
});

it('keeps status.js one valid script whatever a title or summary holds', function () {
    $nasty = "</script><script>alert(1)</script> \"quoted\" it's \u{2028}line\u{2029}para";
    $js = proof_render_status_js([proof_index_entry('pr-5-logs', ['title' => $nasty, 'clientSummary' => $nasty])]);
    $file = sys_get_temp_dir() . '/proof-status-' . uniqid() . '.js';
    file_put_contents($file, $js);
    exec('node --check ' . escapeshellarg($file) . ' 2>&1', $output, $code);
    unlink($file);

    expect($code)->toBe(0, implode("\n", $output));
    expect($js)->not->toContain('</script>')->not->toContain("\u{2028}")->not->toContain("\u{2029}");
    expect(proof_test_status_runs($js)[0]['row'])->toContain('&lt;/script&gt;')->not->toContain('<script>');
});
```

- [ ] **Step 3: Run them to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter 'gives each row|copy target|status.js|hash when'`
Expected: FAIL — `Call to undefined function proof_index_row_hash()` and `proof_render_status_js()`, and
`proof_render_index_row(): Argument #1 ($number) must be of type int, array given`.

- [ ] **Step 4: Implement**

In `skills/pipeline/checks/proof_render.php`, in `proof_render_index()` replace

```php
            . implode('', array_map(proof_render_index_row(...), array_keys($runs), $runs))
```

with

```php
            . implode('', array_map(proof_render_index_row(...), $runs))
```

Replace the head of `proof_render_index_row()` — its docblock, signature and the `$key` line with its comment:

```php
/** One run: what the index script reads, its status, where it lives, its page, its figures, and its summary to copy. */
function proof_render_index_row(int $number, array $entry): string
{
    $run = $entry['run'];
    // The link comes from the directory the run was found in, never from re-deriving a name out of the run: a run
    // filed under an earlier naming scheme has to stay reachable. The page keys its seen marker on the same segments.
    $key = implode('/', array_slice(explode('/', trim((string) $entry['dir'], '/')), -2));
```

with

```php
/**
 * One run: what the index script reads (its hash last), its status, where it lives, its page, its figures, and its
 * summary to copy. `status.js` carries the same text, so a row the script inserts or replaces is this one; its copy
 * target is named by the run, so it never collides with another row's.
 */
function proof_render_index_row(array $entry): string
{
    $run = $entry['run'];
    $key = proof_index_key($entry);
```

In the same function, after `'search' => proof_index_search($run),` in `$data` add the line

```php
        'hash' => proof_index_row_hash($entry),
```

and replace the `$copy` assignment with

```php
    $target = 'summary-' . substr(sha1($key), 0, 8);
    $copy = $summary === ''
        ? ''
        : "<button type=\"button\" class=\"copy\" data-copy=\"{$target}\">Copy</button><span id=\"{$target}\" lang=\"nl\" hidden>" . proof_e($summary) . '</span>';
```

Add before `proof_render_index_row()`:

```php
/**
 * `<repo>/<run>`, the last two directories the run was found in: its row's `data-run`, its link, its page's `seen:`
 * key and its `status.js` key. Never re-derived from the run: a run filed under an earlier naming scheme has to stay
 * reachable.
 */
function proof_index_key(array $entry): string
{
    return implode('/', array_slice(explode('/', trim((string) $entry['dir'], '/')), -2));
}

/** The first 12 hex digits of a sha1 over the key and the run as stored: any change to the filed run changes it. */
function proof_index_row_hash(array $entry): string
{
    return substr(sha1(proof_index_key($entry) . "\n" . proof_run_json($entry['run'])), 0, 12);
}

/**
 * `status.js`, which the open index polls (`proof_render_index_script()`): per run in the attention order its key,
 * status, revision (null before revisions existed), hash and row. JSON's default escaping keeps it one valid script
 * whatever a title holds (`/`, U+2028 and U+2029 escaped); it is a file of its own, so no value can end a tag.
 *
 * @param list<array{dir: string, run: array}> $runs
 */
function proof_render_status_js(array $runs): string
{
    $entries = array_map(fn (array $entry): array => [
        'key' => proof_index_key($entry),
        'status' => ProofRunStatus::of($entry['run'])->value,
        'revision' => isset($entry['run']['revision']) ? (int) $entry['run']['revision'] : null,
        'hash' => proof_index_row_hash($entry),
        'row' => proof_render_index_row($entry),
    ], proof_index_order($runs));

    return 'window.proofStatus = ' . json_encode(['runs' => $entries], JSON_INVALID_UTF8_SUBSTITUTE) . ";\n";
}
```

- [ ] **Step 5: Run the tests to see them pass**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter 'Proof'`
Expected: PASS, every Proof* file. `php -l skills/pipeline/checks/proof_render.php` → `No syntax errors detected`.

- [ ] **Step 6: Commit**

```bash
git add skills/pipeline/checks/proof_render.php skills/pipeline/checks/tests/Pest.php skills/pipeline/checks/tests/ProofRenderTest.php
git commit -m "feat(pipeline): status.js renders each run's key, status, revision, hash and index row (#154)"
```

---

### Task 2: The index page — `Proofs`, the favicon, the finished count, the poll and the tab

**Files:**
- Modify: `skills/pipeline/checks/proof_render.php` — `proof_render_index()`, `proof_render_index_controls()`
  (the toggle's label), `proof_render_index_script()` (779–888, rewritten), new `proof_index_icons()` and
  `proof_render_favicon()`
- Test: `skills/pipeline/checks/tests/ProofRenderTest.php` (three existing assertions changed, three cases added)

**Interfaces:**
- Consumes: `proof_render_index_row(array $entry): string` and the row's `data-hash` (Task 1);
  `proof_render_copy_script(): string` (delegated already: `document.addEventListener('click', …)` with
  `closest('[data-copy]')`, so inserted rows' buttons work unchanged).
- Produces: `proof_index_icons(): array` (keys `none`, `halted`, `ready`, `unread`, each a
  `data:image/svg+xml,` URI); `proof_render_favicon(): string`; the page's `<link rel="icon" id="favicon" …>`,
  `<span id="finished-count">`, and the script's poll of `status.js?t=<now>` (relative to the page, so it reads the
  `status.js` Task 3 writes beside `index.html`).

- [ ] **Step 1: Write the failing tests**

In `ProofRenderTest.php`:

- in `carries the zoom dialog and the copy script inline` (line 358), delete the line
  `expect(proof_render_index([]))->not->toContain('<script');` (an empty store now carries the poll);
- in `carries the index script: …` (line 512), replace
  `expect(proof_render_index([]))->not->toContain('<script')->not->toContain('repo-filter')->toContain('No runs recorded');`
  with `expect(proof_render_index([]))->not->toContain('repo-filter')->toContain('No runs recorded');`;
- in `wraps the table so it scrolls on its own, …` (line 633), replace
  `expect(proof_render_index([]))->not->toContain('class="controls"')->not->toContain('no-match')->not->toContain('<script');`
  with `expect(proof_render_index([]))->not->toContain('class="controls"')->not->toContain('no-match');`;
- in `puts the repo and status filters, the search and the toggle with its count above the table` (line 556), replace
  `. "<label><input type=\"checkbox\" id=\"show-finished\"> Show merged and closed (2)</label>\n"` with
  `. "<label><input type=\"checkbox\" id=\"show-finished\"> Show merged and closed (<span id=\"finished-count\">2</span>)</label>\n"`.

Append:

```php
it('names the index tab Proofs and gives it a favicon link with the four icons to pick from', function () {
    $html = proof_render_index([proof_index_entry('pr-5-logs', [])]);
    $icons = proof_index_icons();

    expect(array_keys($icons))->toBe(['none', 'halted', 'ready', 'unread']);
    foreach ($icons as $icon) {
        expect($icon)->toStartWith('data:image/svg+xml,')->not->toContain('"');
    }
    expect(rawurldecode($icons['halted']))->toContain("fill='#dc2626'");
    expect(rawurldecode($icons['ready']))->toContain("fill='#16a34a'");
    expect(rawurldecode($icons['unread']))->toContain("fill='#2563eb'");
    expect(rawurldecode($icons['none']))->toContain("fill='none'")->toContain("stroke='#71717a'");
    expect($html)->toContain("<title>Proofs</title>\n")->toContain('<h1>Pipeline proof store</h1>');
    expect($html)->toContain('<link rel="icon" id="favicon" href="' . proof_e($icons['none']) . '" data-none="' . proof_e($icons['none'])
        . '" data-halted="' . proof_e($icons['halted']) . '" data-ready="' . proof_e($icons['ready']) . '" data-unread="' . proof_e($icons['unread']) . "\">\n");
    expect(strpos($html, 'id="favicon"'))->toBeLessThan(strpos($html, '</head>'));
});

it('renders an empty store with the favicon and a script that only polls', function () {
    $html = proof_render_index([]);

    expect($html)->toContain('No runs recorded')->toContain('id="favicon"')->toContain("<title>Proofs</title>")
        ->toContain("<script>\n")->toContain("'status.js?t=' + Date.now()");
    expect($html)->not->toContain('class="controls"')->not->toContain('no-match')->not->toContain('<table');
});

it('carries the poll and the tab signal in the index script', function () {
    $script = proof_render_index_script();

    expect($script)->toContain("'status.js?t=' + Date.now()")
        ->toContain('setInterval(check, 30000)')
        ->toContain("document.addEventListener('visibilitychange'")
        ->toContain("window.addEventListener('focus', check)")
        ->toContain('refresh(); check();')
        ->toContain("document.title = unseen.length ? '(' + unseen.length + ') Proofs' : 'Proofs'")
        ->toContain("getElementById('favicon')")
        ->toContain("has('halted') ? 'halted' : has('ready') ? 'ready' : unseen.length ? 'unread' : 'none'")
        ->toContain('current.dataset.hash === entry.hash')
        ->toContain("createElement('template')")
        ->toContain('function unread(row)')
        ->toContain("if (!storage || !revision) { return ''; }")
        ->toContain("row.dataset.finished === '0' && unread(row) !== ''")
        ->toContain("getElementById('finished-count')")
        ->toContain('location.reload()');
});
```

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter 'ProofRender'`
Expected: FAIL — `Call to undefined function proof_index_icons()`, the controls case (no `finished-count`), the empty
store case (no `<script>`) and the script case (no `status.js?t=`).

- [ ] **Step 3: Implement the page**

In `proof_render_index_controls()`, replace

```php
        . "<label><input type=\"checkbox\" id=\"show-finished\"> Show merged and closed ({$finished})</label>\n"
```

with

```php
        . "<label><input type=\"checkbox\" id=\"show-finished\"> Show merged and closed (<span id=\"finished-count\">{$finished}</span>)</label>\n"
```

Replace `proof_render_index()` whole (its docblock stays as it is, with one sentence appended — shown here) with:

```php
/**
 * The index is the join from a PR back to its page — the PR body deliberately carries no
 * local path, so this is how a run is found again.
 *
 * Links are relative to the store root, so the index works when opened over `file://`. Left open, it shows in its
 * tab what changed, from the `status.js` beside it (`proof_render_index_script()`); an empty store's page polls too.
 *
 * @param list<array{dir: string, run: array}> $runs in any order: it is ordered here
 */
function proof_render_index(array $runs): string
{
    $runs = proof_index_order($runs);
    $body = $runs === []
        ? "<p class=\"meta\">No runs recorded.</p>\n"
        : proof_render_index_controls($runs)
            . "<div class=\"table-wrap\">\n<table id=\"runs\">\n" . proof_render_index_head() . "<tbody>\n"
            . implode('', array_map(proof_render_index_row(...), $runs))
            . "</tbody>\n</table>\n</div>\n<p id=\"no-match\" class=\"meta\" hidden>No runs match.</p>\n";
    $script = "<script>\n" . proof_render_copy_script() . "\n" . proof_render_index_script() . "\n</script>\n";

    $styles = proof_render_styles();

    return "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n"
        . "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n"
        . "<title>Proofs</title>\n" . proof_render_favicon() . "<style>\n{$styles}\n</style>\n</head>\n<body class=\"index\">\n"
        . "<h1>Pipeline proof store</h1>\n"
        . $body
        . $script
        . "</body>\n</html>\n";
}

/**
 * The tab's icons, one circle each in a 16×16 SVG data URI: red when an unread run is halted, green when one is ready
 * to merge, blue when one is new or updated, and a grey ring when nothing is unread, so a pinned tab always has an
 * icon and never keeps a stale dot. Defined here, only picked by the script.
 *
 * @return array{none: string, halted: string, ready: string, unread: string}
 */
function proof_index_icons(): array
{
    $svg = fn (string $circle): string => 'data:image/svg+xml,' . rawurlencode("<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'>{$circle}</svg>");
    $dot = fn (string $fill): string => $svg("<circle cx='8' cy='8' r='7' fill='{$fill}'/>");

    return [
        'none' => $svg("<circle cx='8' cy='8' r='5.5' fill='none' stroke='#71717a' stroke-width='2'/>"),
        'halted' => $dot('#dc2626'),
        'ready' => $dot('#16a34a'),
        'unread' => $dot('#2563eb'),
    ];
}

/** The favicon link: the ring first, every icon in a `data-` attribute for the script to pick. */
function proof_render_favicon(): string
{
    $icons = proof_index_icons();
    $data = implode('', array_map(fn (string $name, string $uri): string => " data-{$name}=\"" . proof_e($uri) . '"', array_keys($icons), $icons));

    return '<link rel="icon" id="favicon" href="' . proof_e($icons['none']) . "\"{$data}>\n";
}
```

- [ ] **Step 4: Implement the script**

Replace `proof_render_index_script()` whole, docblock included, with:

```php
/**
 * The index's script. With a table, on `DOMContentLoaded` and again on a `pageshow` from the back/forward cache (Back
 * from a page is how the index is reached again):
 *  - each Updated `<time>` in the browser's time, `dd-mm HH:MM`, the full `YYYY-MM-DD HH:MM:SS` on hover;
 *  - per row with a revision `New` when this browser never opened it, `Updated` when it was filed again since
 *    (`unread()`, the one rule #160 replaces); a seen Ready row drops among the rest (its rank);
 *  - the rows in the attention order (rank, then newest first), or by the column whose header was clicked: its first
 *    direction, reversed by a second click, empty keys last either way, ties in the attention order;
 *  - a row shows when the repo filter, the status filter (or, under All statuses, the toggle) and every search term
 *    let it; `#no-match` when none does;
 *  - the tab (`tab()`): the title counts the unread runs that are not merged or closed, whatever the filters show
 *    (`(2) Proofs`, else `Proofs`), and the favicon is the link's halted, ready, unread or none icon, in that order.
 * It polls `status.js` every 30 seconds, when the tab becomes visible, when the window gains focus and after a
 * `pageshow` from the cache, through a script tag, since `fetch()` is refused over `file://` (`poll()`): a row whose
 * hash changed is replaced, a new run's row inserted and a pruned run's removed, then every row is marked, ordered and
 * filtered again and the tab updated (`apply()`); the page is never reloaded. A failed load changes nothing. Without a
 * table (an empty store) it only polls, and reloads once `status.js` names a run.
 * The repo filter, the status filter and the toggle are remembered (`proof:repo`, `proof:status`, `proof:finished`);
 * the search and the sort are not. Without `localStorage` (a private window, blocked site data) no row is marked,
 * nothing is unread, and every control works unremembered.
 */
function proof_render_index_script(): string
{
    return <<<'JS'
(function () {
  var favicon = document.getElementById('favicon');
  var table = document.getElementById('runs');
  var storage = null;
  try {
    storage = window.localStorage;
    storage.getItem('proof:repo');
  } catch (error) {
    storage = null;
  }
  function poll(apply) {
    window.proofStatus = undefined;
    var script = document.createElement('script');
    script.onload = function () {
      script.remove();
      var answer = window.proofStatus;
      if (answer && Array.isArray(answer.runs)) { apply(answer); }
    };
    script.onerror = function () { script.remove(); };
    script.src = 'status.js?t=' + Date.now();
    document.head.appendChild(script);
  }
  function watch(apply) {
    function check() { poll(apply); }
    setInterval(check, 30000);
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') { check(); } });
    window.addEventListener('focus', check);
    return check;
  }
  if (!table) {
    watch(function (answer) { if (answer.runs.length) { location.reload(); } });
    return;
  }
  var body = table.tBodies[0];
  var headers = Array.prototype.slice.call(table.tHead.rows[0].cells);
  var rows = Array.prototype.slice.call(body.rows);
  var repo = document.getElementById('repo-filter');
  var status = document.getElementById('status-filter');
  var search = document.getElementById('search');
  var finished = document.getElementById('show-finished');
  var finishedCount = document.getElementById('finished-count');
  var empty = document.getElementById('no-match');
  var template = document.createElement('template');
  var sorted = null;
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
  function unread(row) {
    var revision = Number(row.dataset.revision || 0);
    if (!storage || !revision) { return ''; }
    var seen = storage.getItem('seen:' + row.dataset.run);
    return seen === null ? 'New' : Number(seen) < revision ? 'Updated' : '';
  }
  function mark(row) {
    var state = unread(row);
    row.querySelector('.marker').textContent = state;
    row.dataset.rank = state === '' && row.dataset.revision && row.dataset.group === '1' ? '2' : row.dataset.group;
  }
  function attention(a, b) {
    return (Number(a.dataset.rank || a.dataset.group) - Number(b.dataset.rank || b.dataset.group))
      || ((Date.parse(b.dataset.updated) || 0) - (Date.parse(a.dataset.updated) || 0));
  }
  function byColumn(a, b) {
    var x = a.cells[sorted.index].dataset.sort;
    var y = b.cells[sorted.index].dataset.sort;
    if (x === '' || y === '') { return (x === '') - (y === ''); }
    var difference = sorted.type === 'number' ? Number(x) - Number(y) : x.localeCompare(y, undefined, { sensitivity: 'base', numeric: true });
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
  function tab() {
    var unseen = rows.filter(function (row) { return row.dataset.finished === '0' && unread(row) !== ''; });
    function has(state) { return unseen.some(function (row) { return row.dataset.status === state; }); }
    var icon = favicon.dataset[has('halted') ? 'halted' : has('ready') ? 'ready' : unseen.length ? 'unread' : 'none'];
    document.title = unseen.length ? '(' + unseen.length + ') Proofs' : 'Proofs';
    if (favicon.getAttribute('href') === icon) { return; }
    var next = favicon.cloneNode();
    next.setAttribute('href', icon);
    favicon.replaceWith(next);
    favicon = next;
  }
  function adopt(html) {
    template.innerHTML = html;
    var row = template.content.firstElementChild;
    row.querySelectorAll('time[datetime]').forEach(local);
    return row;
  }
  function apply(answer) {
    var present = {};
    answer.runs.forEach(function (entry) {
      present[entry.key] = true;
      var current = rows.filter(function (row) { return row.dataset.run === entry.key; })[0];
      if (current && current.dataset.hash === entry.hash) { return; }
      var row = adopt(entry.row);
      if (current) {
        current.replaceWith(row);
        rows[rows.indexOf(current)] = row;
      } else {
        body.appendChild(row);
        rows.push(row);
      }
    });
    rows = rows.filter(function (row) {
      if (present[row.dataset.run]) { return true; }
      row.remove();
      return false;
    });
    if (storage) { rows.forEach(mark); }
    finishedCount.textContent = rows.filter(function (row) { return row.dataset.finished === '1'; }).length;
    order();
    show();
    tab();
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
    tab();
  }
  var check = watch(apply);
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
  window.addEventListener('pageshow', function (event) { if (event.persisted) { refresh(); check(); } });
})();
JS;
}
```

Notes for the implementer: function declarations are hoisted within the IIFE, so `watch(apply)` and the empty-store
`return` both work; `mark()` keeps today's markers and rank (a row without a revision keeps its group rank), it only
reads them from `unread()`.

- [ ] **Step 5: Run the tests to see them pass**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter 'Proof'`
Expected: PASS, including `renders an index script that parses as JavaScript` (`node --check`). `php -l
skills/pipeline/checks/proof_render.php` → `No syntax errors detected`.

- [ ] **Step 6: Commit**

```bash
git add skills/pipeline/checks/proof_render.php skills/pipeline/checks/tests/ProofRenderTest.php
git commit -m "feat(pipeline): the open proof index polls status.js and shows unread runs in its title and favicon (#154)"
```

---

### Task 3: Every store write leaves `status.js` beside the index

**Files:**
- Modify: `skills/pipeline/checks/proof_store.php:47-51` (`proof_store_file()`), `:160-193` (`proof_store_amend()`),
  a new `proof_store_index()` after `proof_store_file()`
- Modify: `skills/pipeline/checks/proof_cli.php:193` (`proof_cli_prune()`)
- Modify: `skills/pipeline/references/engine.md` §The proof store (the index paragraph gains *The open index tab*)
- Test: `skills/pipeline/checks/tests/ProofWriteTest.php`, `ProofStatusTest.php`, `RunCostTest.php`

**Interfaces:**
- Consumes: `proof_render_status_js(array $runs): string` (Task 1), `proof_render_index(array $runs): string`
  (Task 2), `proof_scan_runs(string $root): array`; test helpers `proof_test_status_runs(string $js): array`
  (`Pest.php`), `proof_test_page(array $run = []): string` (`Pest.php`), `proof_write_cli()`, `proof_write_payload()`
  (`ProofWriteTest.php`), `proof_status_cli()`, `proof_fake_gh()` (`ProofStatusTest.php`), `cost_run()`, `cost_call()`,
  `cost_tool_use()`, `cost_tool_result()`, `checks_cli()` (`RunCostTest.php`).
- Produces: `proof_store_index(string $root): ?string` — writes `{$root}/index.html` then `{$root}/status.js` (via
  `status.js.tmp` and a rename) from one scan; null, or `cannot write <file>`.

- [ ] **Step 1: Write the failing tests**

Append to `skills/pipeline/checks/tests/ProofWriteTest.php`:

```php
it('leaves status.js beside the index at every write, naming the run, its status and its revision', function () {
    $root = sys_get_temp_dir() . '/proof-write-' . uniqid();

    proof_write_cli(proof_write_payload(), $root);
    $first = proof_test_status_runs(file_get_contents("{$root}/status.js"));
    proof_write_cli(proof_write_payload(), $root);
    $second = proof_test_status_runs(file_get_contents("{$root}/status.js"));

    expect($first)->toHaveCount(1);
    expect($first[0])->toMatchArray(['key' => 'Deploy/pr-5-logs', 'status' => 'running', 'revision' => 1]);
    expect($second[0])->toMatchArray(['key' => 'Deploy/pr-5-logs', 'status' => 'running', 'revision' => 2]);
    expect($second[0]['hash'])->not->toBe($first[0]['hash']);
    expect(file_get_contents("{$root}/index.html"))->toContain('data-hash="' . $second[0]['hash'] . '"');
    expect(glob("{$root}/*.tmp"))->toBe([]);
});

it('says which store file it cannot write, and leaves no temporary file', function () {
    $root = sys_get_temp_dir() . '/proof-store-' . uniqid();
    mkdir("{$root}/status.js", 0777, true); // a directory where the file goes: the rename over it fails

    expect(proof_store_index($root))->toBe("cannot write {$root}/status.js");
    expect(file_exists("{$root}/status.js.tmp"))->toBeFalse();
    expect(is_file("{$root}/index.html"))->toBeTrue();

    $missing = sys_get_temp_dir() . '/proof-missing-' . uniqid();
    expect(proof_store_index($missing))->toBe("cannot write {$missing}/index.html");
    expect(is_dir($missing))->toBeFalse();
});
```

Append to `skills/pipeline/checks/tests/ProofStatusTest.php`:

```php
it('rewrites status.js in the store the page is in when a status is written', function () {
    $page = proof_test_page();
    $root = dirname($page, 3);

    proof_status_cli(['status', $page, 'halted', '--reason', 'CI red']);

    $runs = proof_test_status_runs(file_get_contents("{$root}/status.js"));
    expect($runs)->toHaveCount(1);
    expect($runs[0])->toMatchArray(['key' => 'Deploy/pr-5-logs', 'status' => 'halted', 'revision' => 1]);
    expect(file_exists("{$root}/status.js.tmp"))->toBeFalse();
});

it('prints its count and names on stderr the index it cannot write', function () {
    $root = sys_get_temp_dir() . '/proof-missing-' . uniqid();

    expect(proof_status_cli(['prune'], [...proof_fake_gh(null), 'PIPELINE_PROOF_ROOT' => $root]))
        ->toBe(['code' => 0, 'stdout' => "proof: pruned 0 run(s)\n", 'stderr' => "proof: cannot write {$root}/index.html\n"]);
});
```

In the same file, in `corrects a stale status from gh in the prune pass, keeping updatedAt and the revision`, add as
the case's last line:

```php
    expect(proof_test_status_runs(file_get_contents(dirname($page, 3) . '/status.js'))[0]['status'])->toBe($status['state']);
```

and in `prunes a run that opened no PR two weeks after its last filing, and drops it from the index`, add as the
case's last line:

```php
    expect(array_column(proof_test_status_runs(file_get_contents("{$root}/status.js")), 'key'))->toBe(['Deploy/feature-fresh']);
```

Append to `skills/pipeline/checks/tests/RunCostTest.php`:

```php
it('changes the run\'s hash in status.js when it files a cost', function () {
    $dir = cost_run([
        'a1' => ['implement:run', implode("\n", [
            cost_call('m1', 0, 0, 300000, 2000, null, '10:07:00.000'),
            cost_tool_use('t1', '10:08:00.000'),
            cost_tool_result('t1', '10:20:00.000'),
        ]), ['status' => 'continued']],
    ]);
    $page = proof_test_page();
    $root = dirname($page, 3);
    expect(proof_store_index($root))->toBeNull();
    $before = proof_test_status_runs(file_get_contents("{$root}/status.js"))[0];

    checks_cli('run_cost_cli.php', [$dir, $page]);

    $after = proof_test_status_runs(file_get_contents("{$root}/status.js"))[0];
    expect($after['hash'])->not->toBe($before['hash']);
    expect($after['revision'])->toBe($before['revision']);
    // The Time column now shows the filed span (780 s, as the case above files it).
    expect($after['row'])->toContain('>' . proof_minutes(780.0) . '</td>');
});
```

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter 'status.js|cannot write|stale status|opened no PR two weeks'`
Expected: FAIL — `status.js` does not exist (`file_get_contents(...): Failed to open stream`), `Call to undefined
function proof_store_index()`, and the prune case's stderr is not `proof: cannot write …`.

- [ ] **Step 3: Implement**

In `skills/pipeline/checks/proof_store.php`, in `proof_store_file()` replace

```php
    file_put_contents("{$root}/index.html", proof_render_index(proof_scan_runs($root)));
```

with

```php
    $problem = proof_store_index($root);
    if ($problem !== null) {
        fwrite(STDERR, "proof: {$problem}\n");
    }
```

and change its docblock's second sentence to: *Then the shots are ingested, `addedTests` extracted, `run.json` written,
the page rendered and the store index and `status.js` written (`proof_store_index()`).*

Add after `proof_store_file()`:

```php
/**
 * The store index and the `status.js` beside it, rendered from one scan: every write path ends here, so the open
 * index never polls a `status.js` behind the index (`../references/engine.md` §The proof store, *The open index
 * tab*). `status.js` goes through `status.js.tmp` and a rename, so a poll never loads half a file; the index is read
 * only on a load the owner starts. Creates no directory.
 *
 * @return ?string null, or `cannot write <file>`
 */
function proof_store_index(string $root): ?string
{
    $runs = proof_scan_runs($root);
    if (@file_put_contents("{$root}/index.html", proof_render_index($runs)) === false) {
        return "cannot write {$root}/index.html";
    }
    $status = "{$root}/status.js";
    if (@file_put_contents("{$status}.tmp", proof_render_status_js($runs)) === false || ! @rename("{$status}.tmp", $status)) {
        @unlink("{$status}.tmp");

        return "cannot write {$status}";
    }

    return null;
}
```

In `proof_store_amend()`, replace

```php
    $files = [
        "{$dir}/run.json" => fn (): string => proof_run_json($run),
        "{$dir}/index.html" => fn (): string => proof_render_run($run),
        "{$root}/index.html" => fn (): string => proof_render_index(proof_scan_runs($root)),
    ];
    foreach ($files as $file => $contents) {
        if (@file_put_contents($file, $contents()) === false) {
            return "cannot write {$file}";
        }
    }

    return null;
```

with

```php
    $files = [
        "{$dir}/run.json" => fn (): string => proof_run_json($run),
        "{$dir}/index.html" => fn (): string => proof_render_run($run),
    ];
    foreach ($files as $file => $contents) {
        if (@file_put_contents($file, $contents()) === false) {
            return "cannot write {$file}";
        }
    }

    return proof_store_index($root);
```

and in its docblock replace *re-renders the page and the index of the store the page is in* with *re-renders the page,
and the index and `status.js` of the store the page is in*.

In `skills/pipeline/checks/proof_cli.php`, in `proof_cli_prune()` replace

```php
    file_put_contents($root . '/index.html', proof_render_index(proof_scan_runs($root)));
```

with

```php
    $problem = proof_store_index($root);
    if ($problem !== null) {
        fwrite(STDERR, "proof: {$problem}\n");
    }
```

- [ ] **Step 4: Document the open index tab**

In `skills/pipeline/references/engine.md` §The proof store, after the paragraph that starts `**The index** shows the
open runs by attention` and ends `Without \`localStorage\` nothing is marked and the order is the status order.`, add
this paragraph (a blank line before and after):

```markdown
**The open index tab.** Every store write (a filing, `handoff`'s included, a status, the prune pass, a cost) writes
`status.js` beside `index.html` from the same scan (`proof_store_index()`): per run its key (`<repo>/<run>`), status,
revision, a hash of the run as stored, and its row as the index renders it. The index, opened by hand and left open
in a tab, loads it every 30 seconds, and at once when the tab becomes visible or the window gains focus, through a
`<script src="status.js?t=<now>">` (`fetch()` is refused over `file://`). It replaces the rows whose hash changed,
inserts new runs and removes pruned ones in place, without a reload or a lost scroll position, then marks, orders and
filters every row again. Its title counts the unread runs (*New* or *Updated*) that are not merged or closed, whatever
the filters show (`(2) Proofs`, else `Proofs`), and its favicon is a dot: red when one of them is halted, else green
when one is ready, else blue, else a grey ring. A run leaves both once its page is opened and the index is looked at
again. An empty store's index reloads itself once a run appears. Two limits: the signal exists only while the index
tab is open, and Chrome throttles timers in background tabs, so a change can take a minute or so to show.
```

- [ ] **Step 5: Run the tests to see them pass**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter 'Proof|RunCost|DispatchCli|HandoffCli'`
Expected: PASS. `php -l` on `proof_store.php` and `proof_cli.php` → `No syntax errors detected`.

- [ ] **Step 6: Commit**

```bash
git add skills/pipeline/checks/proof_store.php skills/pipeline/checks/proof_cli.php skills/pipeline/references/engine.md skills/pipeline/checks/tests/ProofWriteTest.php skills/pipeline/checks/tests/ProofStatusTest.php skills/pipeline/checks/tests/RunCostTest.php
git commit -m "feat(pipeline): every proof store write leaves status.js beside the index (#154)"
```

---

### Task 4: No page opens by itself

**Files:**
- Modify: `skills/pipeline/checks/proof.php:487-528` (`proof_open_argv()` and its docblock)
- Modify: `skills/pipeline/checks/proof_cli.php:1-16` (header), `:43-58` (`proof_cli_open()`'s docblock)
- Modify: `skills/pipeline/checks/brief.php:109-112` (`review-pr:resolve`), `:555` (the `## Return` line)
- Modify: `skills/pipeline/checks/dispatch_cli.php:256` (`launch`'s `start` JSON)
- Modify: `skills/pipeline/workflow/pipeline-autoflow.js:134-136` (`stepPrompt()`)
- Test: `skills/pipeline/checks/tests/ProofOpenTest.php`, `BriefTest.php`, `DispatchCliTest.php`,
  `AutoflowScriptTest.php`

**Interfaces:**
- Consumes: `pipeline_brief(array $manifest, string $leg, string $manifestPath, ?string $step = null, ?callable $git = null): string`,
  `pipeline_brief_return(string $leg, string $step, string $mode, string $manifestPath): string`,
  `pipeline_legs(): array`, `pipeline_steps(string $leg, string $mode): array`; test helpers `brief_manifest()`,
  `dispatch_fixture()`, `dispatch_cli()`, `autoflow_start()`, `autoflow_replay()`, `proof_open_fixture()`,
  `proof_open_cli()`.
- Produces: `proof_open_argv(?string $path, string $platform = PHP_OS_FAMILY): ?array` — same signature, no longer
  reads `PIPELINE_NO_OPEN`; `launch`'s `start` JSON without `noOpen`; the interactive finish brief's sentence
  ``Your reply names the page path `write` printed: no page opens by itself (engine.md §The proof store).``

- [ ] **Step 1: Write the failing tests**

`skills/pipeline/checks/tests/ProofOpenTest.php`:
- header docblock: replace `Opening a finished run's page` with `Opening a run's page by hand`;
- delete `putenv('PIPELINE_NO_OPEN');` from `beforeEach`, and `'PIPELINE_NO_OPEN' => '',` from `proof_open_cli()`'s
  `$env`;
- delete the cases `honours PIPELINE_NO_OPEN so headless and unattended runs stay silent` and
  `opens nothing through the CLI when PIPELINE_NO_OPEN is set`, and put in the first one's place:

```php
it('ignores PIPELINE_NO_OPEN: a page opened by hand opens', function () {
    $fixture = proof_open_fixture();

    putenv('PIPELINE_NO_OPEN=1');
    expect(proof_open_argv($fixture['page'], 'Darwin'))->toBe(['open', $fixture['page']]);
    putenv('PIPELINE_NO_OPEN');

    $result = proof_open_cli(['open', $fixture['page']], $fixture, ['PIPELINE_NO_OPEN' => '1']);
    expect($result['code'])->toBe(0);
    expect($result['recorded'])->toBe("argc=1\n" . $fixture['page'] . "\n");
});
```

`skills/pipeline/checks/tests/BriefTest.php`:
- replace the case `has the finish step write its actions before its last action` (line 25) whole with:

```php
it('has the finish step write its actions, and open no page', function () {
    $open = ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r'];
    $brief = pipeline_brief(brief_manifest('review-pr', ['gate_ledger' => [$open]]), 'review-pr', '/tmp/m.json');

    expect($brief)->toContain('m.actions.json')->not->toContain('proof_cli.php open');
});
```

- in `makes the review-pr resolve step the finish step` (line 222), replace
  ``->toContain('proof_cli.php open');`` with
  ``->toContain('Your reply names the page path `write` printed')->not->toContain('proof_cli.php open');``
- in `leaves the PR draft at the autoflow finish step for the session that launched the run` (line 293), replace
  ``expect(strpos($brief, 'm.actions.json'))->toBeLessThan(strpos($brief, 'After `record`, the last action is `proof_cli.php open`'));``
  with ``expect($brief)->toContain('m.actions.json')->not->toContain('proof_cli.php open')->not->toContain('Your reply names the page path');``
- in `has the interactive finish step run the CI gate before gh pr ready` (line 303), replace the expected text's tail
  `` show any other answer to the human. After `record`, the last action is `proof_cli.php open`'`` with
  `` show any other answer to the human. Your reply names the page path `write` printed: no page opens by itself (engine.md §The proof store).'``
- in `prints the return of a handoff step as its command, …` (line 551), replace
  ``(`size`, `ui`, the proof page's `open`)`` with ``(`size`, `ui`)``;
- in the case around line 616, replace
  ``->toContain('After `record`, the last action is `proof_cli.php open` on the path `write` printed (engine.md §The proof store).')``
  with ``->not->toContain('proof_cli.php open')``;
- append:

```php
it('names no open in any step\'s return, in either mode', function () {
    foreach (['autoflow', 'interactive'] as $mode) {
        foreach (pipeline_legs() as $leg) {
            foreach (pipeline_steps($leg, $mode) as $step) {
                expect(pipeline_brief_return($leg, $step, $mode, '/tmp/m.json'))->not->toContain('`open`');
            }
        }
    }
});
```

`skills/pipeline/checks/tests/DispatchCliTest.php`:
- in `dispatch_cli()` (line 28), drop `'PIPELINE_NO_OPEN' => '0', ` from the environment;
- in `launches from the cursor with the ledger's loop-backs, the design size and ui` (line 162), delete the line
  `'noOpen' => false,`;
- replace the case `marks noOpen when the launch runs unattended` (line 303) whole with:

```php
it('gives the workflow no noOpen flag, whatever PIPELINE_NO_OPEN says', function () {
    $fixture = dispatch_fixture(['mode' => 'autoflow']);

    expect(dispatch_cli(['launch', $fixture['manifest'], $fixture['diff']], ['PIPELINE_NO_OPEN' => '1'])['json'])->not->toHaveKey('noOpen');
});
```

`skills/pipeline/checks/tests/AutoflowScriptTest.php`, append:

```php
it('opens no proof page at the finish step', function () {
    $replay = autoflow_replay(autoflow_start('review-pr'), ['review-pr:review' => [AUTOFLOW_C], 'review-pr:resolve' => [AUTOFLOW_C]]);
    $resolve = $replay['prompts'][array_search('review-pr:resolve', $replay['labels'], true)];

    expect($resolve)->toContain('review-pr resolve')->not->toContain('proof_cli.php open')->not->toContain('PIPELINE_NO_OPEN');
});
```

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter 'ProofOpen|Brief|DispatchCli|AutoflowScript'`
Expected: FAIL — `ignores PIPELINE_NO_OPEN` (`null` where the argv was expected, `recorded` empty), the brief cases
(they still contain `proof_cli.php open` and the return's `open`), `launches from the cursor` and `gives the workflow
no noOpen flag` (the key is there), `opens no proof page at the finish step` (the prompt's line 4).

- [ ] **Step 3: Implement**

`skills/pipeline/checks/proof.php` — replace `proof_open_argv()`'s docblock and body with:

```php
/**
 * The argv for opening a run's page in the desktop browser, by hand (`proof_cli.php open`): no step opens one
 * (`../references/engine.md` §The proof store, *No page opens by itself*).
 *
 * An **array**, never a shell string. The page path is derived from repo/branch/PR values that
 * reach this store from a JSON payload, so it is untrusted input: `proof_cli.php` hands this
 * array straight to `proc_open()`, which executes an array form *without a shell*, and a path
 * holding a space, a quote or a `;` therefore stays one argument and can never become a second
 * command. Nothing here escapes or interpolates, because nothing here builds a command line.
 *
 * Returns null whenever there is nothing to do, which is never an error — opening is cosmetic:
 *  - **no page** — a run that halted before `handoff` has none;
 *  - **no opener** — a platform this does not know how to open on.
 *
 * `PIPELINE_OPEN_CMD` overrides the platform default with an executable that receives the page
 * path as its single argument. It is the portability escape hatch (an unknown platform, a
 * specific browser via a one-line wrapper) and the seam the tests stub, so no suite ever pops
 * a browser window.
 */
function proof_open_argv(?string $path, string $platform = PHP_OS_FAMILY): ?array
{
    if ($path === null || $path === '' || ! is_file($path)) {
        return null;
    }

    $binary = (string) getenv('PIPELINE_OPEN_CMD');
    if ($binary === '') {
        $binary = match ($platform) {
            'Darwin' => 'open',
            'Linux' => 'xdg-open',
            default => '',
        };
    }

    return $binary === '' ? null : [$binary, $path];
}
```

`skills/pipeline/checks/proof_cli.php` — replace `proof_cli_open()`'s docblock with:

```php
/**
 * Open a run's page in the desktop browser, by hand: no step runs this, a run's report names its page instead
 * (`../references/engine.md` §The proof store, *No page opens by itself*).
 *
 * Cosmetic, and weaker than every other policy in this file: failing to *capture* proof halts a
 * run and failing to *file* it logs and continues, but failing to *open* it does not even rate a
 * distinct outcome. Every path below returns 0, including "there is no page", which is the
 * state of a run that halted before `handoff`.
 *
 * `proof_open_argv()` returns an argv **array** and `proc_open()` runs an array form without a
 * shell, so the page path — which reaches this store from a JSON payload — is passed to the opener
 * as one literal argument. There is no command line for a quote or a `;` in it to escape from.
 *
 * The opener is expected to return immediately (`open` launches and exits; a desktop `xdg-open`
 * delegates and exits); `PIPELINE_OPEN_CMD` names another one.
 */
```

`skills/pipeline/checks/brief.php` — in the `review-pr:resolve` overrides replace

```php
            ($autoflow
                ? 'Push your commits and leave the PR draft; the session that launched the run marks it ready after the CI gate (engine.md §The CI gate).'
                : 'Run the CI gate (engine.md §The CI gate) and `gh pr ready` when it answers `ready`, then `proof_cli.php status <the path write printed> ready`; show any other answer to the human.')
            . ' After `record`, the last action is `proof_cli.php open` on the path `write` printed (engine.md §The proof store).',
```

with

```php
            $autoflow
                ? 'Push your commits and leave the PR draft; the session that launched the run marks it ready after the CI gate (engine.md §The CI gate).'
                : 'Run the CI gate (engine.md §The CI gate) and `gh pr ready` when it answers `ready`, then `proof_cli.php status <the path write printed> ready`; show any other answer to the human. Your reply names the page path `write` printed: no page opens by itself (engine.md §The proof store).',
```

and in `pipeline_brief_return()` replace ``(`size`, `ui`, the proof page's `open`)`` with ``(`size`, `ui`)``.

`skills/pipeline/checks/dispatch_cli.php` — in `launch`'s `start` array delete the line

```php
        'noOpen' => ! in_array((string) getenv('PIPELINE_NO_OPEN'), ['', '0'], true),
```

`skills/pipeline/workflow/pipeline-autoflow.js` — in `stepPrompt()` delete

```js
  if (leg === 'review-pr' && step === 'resolve') {
    lines.push(`4. Run the proof page's \`open\` as \`PIPELINE_NO_OPEN=${args.noOpen ? 1 : 0} php ${args.checks}/proof_cli.php open …\`.`)
  }
```

- [ ] **Step 4: Run the tests to see them pass**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter 'ProofOpen|Brief|DispatchCli|AutoflowScript'`
Expected: PASS. `php -l` on `proof.php`, `proof_cli.php`, `brief.php`, `dispatch_cli.php`; `node --check
skills/pipeline/workflow/pipeline-autoflow.js` exits 0.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/proof.php skills/pipeline/checks/proof_cli.php skills/pipeline/checks/brief.php skills/pipeline/checks/dispatch_cli.php skills/pipeline/workflow/pipeline-autoflow.js skills/pipeline/checks/tests/ProofOpenTest.php skills/pipeline/checks/tests/BriefTest.php skills/pipeline/checks/tests/DispatchCliTest.php skills/pipeline/checks/tests/AutoflowScriptTest.php
git commit -m "feat(pipeline): no run opens its proof page by itself; proof_cli.php open stays for by hand (#154)"
```

---

### Task 5: The docs say no page opens, and the reports name it

No code: the skill text the sessions follow. Exact replacements; keep each paragraph's line wrapping near 120
columns.

**Files:**
- Modify: `skills/pipeline/references/engine.md` (lines 87–88, 567, 1016–1043, 1610)
- Modify: `skills/pipeline/SKILL.md` (lines 42–43, 106, 145–146, step 6)
- Modify: `skills/orchestrate/SKILL.md` (step 5), `skills/orchestrate/references/commands.md` (lines 94–95, 114,
  138–141, §Proof page)

**Interfaces:**
- Consumes: the *The open index tab* paragraph Task 3 added to engine.md §The proof store; the brief text of Task 4.

- [ ] **Step 1: engine.md**

- §`autoflow` (line 87): `PIPELINE_NO_OPEN=<1 unattended, else 0> php "$CHECKS/dispatch_cli.php" launch …` becomes
  `php "$CHECKS/dispatch_cli.php" launch …` (the rest of the line as it is); line 88: delete `"noOpen":…,`.
- §Stations, the `review-pr` row (line 567): `rewrites the proof page, runs \`gh pr ready\`, and opens the page last
  (§The proof store).` becomes `rewrites the proof page and runs \`gh pr ready\`; no page opens by itself (§The proof
  store).`
- §The proof store: replace the four paragraphs from `**The finished page opens itself — once, at the end.**` through
  the end of `**Concurrent finishes are left undamped, deliberately.** …` (lines 1016–1043, up to the blank line
  before `## Who takes the PR out of draft`) with:

```markdown
**No page opens by itself.** A run's report names its page: the finish step's reply in `interactive`, the invoking
session's report after `finish` in `autoflow` (the `proof` that `finish`'s `done` carries, else `artifacts.proof`),
and a halt's report the same. The store index, opened by hand and left open, shows what changed (*The open index
tab*, above), so a day of runs is one tab, not a tab per run. `php checks/proof_cli.php open <page>` opens a page by
hand. It is cosmetic, weaker than every other proof policy: failing to *capture* proof halts a run, failing to
*file* it logs and continues, and `open` returns 0 on every path (no page, a platform with no opener — `open` on
macOS, `xdg-open` on Linux, nothing anywhere else —, an opener that fails). The page path reaches the store from a
JSON payload, so `open` never builds a shell string from it: it hands `proc_open()` an argv **array**, which runs
without a shell. **`PIPELINE_OPEN_CMD`** replaces the platform default with an executable that receives the page
path as its single argument.
```

- §Failure policy (line 1610): `In \`autoflow\` the invoking session does this after \`finish\`, and opens the proof
  page once when \`artifacts.proof\` is set (§The proof store).` becomes `In \`autoflow\` the invoking session does
  this after \`finish\`, and its report names the proof page when \`artifacts.proof\` is set (§The proof store).`

- [ ] **Step 2: pipeline SKILL.md**

- *Visual proof* (lines 42–43): `The finished page **opens in the browser once**, as the run's last action;
  \`PIPELINE_NO_OPEN=1\` suppresses that for headless and unattended runs.` becomes `No page opens by itself: the
  report names it, and the store index, left open in a tab, shows what changed with a dot and a count in that tab
  (\`references/engine.md\` §The proof store).`
- step 2 (line 106): drop the `PIPELINE_NO_OPEN=<1 unattended, else 0> ` prefix from the `launch` command.
- step 5 (lines 145–146): `**A halt after \`handoff\`:** the reason into the PR body and the proof page opened once
  (\`references/engine.md\` §Failure policy).` becomes `**A halt after \`handoff\`:** the reason into the PR body,
  and the report names the proof page (\`references/engine.md\` §Failure policy).`
- step 6: `6. **Report** the result with the two cost-per-run outputs above` becomes `6. **Report** the result,
  naming the proof page (the \`proof\` \`finish\` printed), with the two cost-per-run outputs above`.

- [ ] **Step 3: orchestrate**

- `skills/orchestrate/SKILL.md` step 5: `Ready PR: tell the owner in two lines, open its proof page once, arm the
  merge watch` becomes `Ready PR: tell the owner in two lines with its proof page's path (commands §Proof page), arm
  the merge watch`.
- `skills/orchestrate/references/commands.md` §Launch (lines 94–95): `- \`launch\` runs with \`PIPELINE_NO_OPEN=1\`:
  the run is unattended. \`done\` or a halt: report it and` / `start no workflow.` becomes
  `- \`launch\` answers \`done\` or a halt: report it and start no workflow.`
- the *commits wanted* block (line 114): drop the `PIPELINE_NO_OPEN=1 ` prefix.
- §Finish (lines 138–141): `… stops (*Bound exhaustion*) says. No proof page opens on a halt in an unattended batch,
  unlike pipeline \`SKILL.md\`'s attended "opened once": it opens only on a ready PR (§Proof page).` becomes `…
  stops (*Bound exhaustion*) says, and the report names the proof page.`
- §Proof page, its body (the sentence and the code block) becomes:

~~~markdown
No page opens by itself (pipeline `engine.md` §The proof store): the owner keeps the store index open, and its tab
shows what changed. When announcing a ready PR, and only if the run made a page, name its path, the file and not the
directory: the `proof` `finish` printed, `~/GitProjects/_proofs/<repo>/pr-<P>-<topic>/index.html`. To open one by
hand:
```bash
php ~/.claude/skills/pipeline/checks/proof_cli.php open <that path>
```
~~~

- [ ] **Step 4: Check nothing still opens or suppresses**

Run: `grep -rnE "PIPELINE_NO_OPEN|noOpen|opened once|opens itself|opens the page last|open its proof page|page's .open" skills --exclude-dir=tests`
Expected: no output (exit 1).

Run: `grep -rn "PIPELINE_NO_OPEN\|noOpen" skills/pipeline/checks/tests`
Expected: only the two new cases (`ignores PIPELINE_NO_OPEN: …` in `ProofOpenTest.php`, `gives the workflow no
noOpen flag …` in `DispatchCliTest.php`).

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/references/engine.md skills/pipeline/SKILL.md skills/orchestrate/SKILL.md skills/orchestrate/references/commands.md
git commit -m "docs(pipeline): no proof page opens by itself; reports name it and the open index tab shows what changed (#154)"
```

---

### Task 6: Check the open index in Chrome, over a copy of the real store

No code and no commit: this proves Tasks 1–3 in the browser (the issue's Verify). Never write to
`~/GitProjects/_proofs`. Every `proof_cli.php` command here runs with `PIPELINE_PROOF_ROOT="$COPY"` and a fake `gh`
first on `PATH` that always fails, so the prune pass after each write never asks GitHub and touches only the copy.
Shell state does not persist between an agent's Bash calls: carry the printed `$COPY` path into later steps
literally.

**Files:** none changed. Works in a `mktemp` dir.

**Interfaces:**
- Consumes: `proof_store_index(string $root): ?string` (Task 3), `proof_cli.php write|status|prune`.

- [ ] **Step 1: Copy the store, a fake `gh`, payloads, and render with the new code**

```bash
COPY=$(mktemp -d)/_proofs && cp -R ~/GitProjects/_proofs "$COPY" && echo "$COPY"
mkdir -p "$(dirname "$COPY")/bin" && printf '#!/bin/sh\nexit 1\n' > "$(dirname "$COPY")/bin/gh" && chmod +x "$(dirname "$COPY")/bin/gh"
for n in 1 2 3 4 5; do printf '{"repo":"TabCheck","branch":"feature/tab-check-%s","pr":9900%s,"title":"PR #9900%s: tab check %s","clientSummary":"Controle van het tabblad.","explainer":{"problem":"A check.","solution":"A check."}}' $n $n $n $n > "$(dirname "$COPY")/n$n.json"; done
php -r 'require "skills/pipeline/checks/proof_store.php"; var_dump(proof_store_index($argv[1]));' "$COPY"
node --check "$COPY/status.js" && grep -c 'id="favicon"' "$COPY/index.html"
```

Expected: `NULL`, then `1`. Below, `W n` stands for
`PATH="$(dirname "$COPY")/bin:$PATH" PIPELINE_PROOF_ROOT="$COPY" php skills/pipeline/checks/proof_cli.php write "$(dirname "$COPY")/n<n>.json"`,
which prints the page `$COPY/TabCheck/pr-9900<n>-tab-check-<n>/index.html`, and `S <n> <status> […]` for the same
prefix with `status "$COPY/TabCheck/pr-9900<n>-tab-check-<n>/index.html" <status> […]`.

- [ ] **Step 2: Over `file://` in headless Chrome: a stale index picks up a newer `status.js`**

Run `cp "$COPY/index.html" "$COPY/stale.html"`, then `W 1` (expanded as above), then:

```bash
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless=new --disable-gpu --virtual-time-budget=45000 --dump-dom "file://$COPY/stale.html" > "$(dirname "$COPY")/dom.html"
grep -c 'data-run="TabCheck/pr-99001-tab-check-1"' "$(dirname "$COPY")/dom.html"; grep -o '<title>[^<]*</title>' "$(dirname "$COPY")/dom.html"
rm "$COPY/stale.html"
```

Expected: `1` (the row the stale page did not have, inserted by a poll over `file://`), and `<title>(<n>) Proofs</title>`
with `n` ≥ 1 (a fresh profile has seen nothing). If the virtual time budget does not reach the 30-second poll, the
dumped title is still the stale page's: then raise the budget to `90000` once; if it still does not, record that in
the report and rely on Step 3's `file://` run (or, when the Playwright MCP refuses `file:`, on Steps 4–9 over HTTP).

Then the empty store: `E=$(mktemp -d)/_proofs && mkdir -p "$E" && php -r 'require "skills/pipeline/checks/proof_store.php"; proof_store_index($argv[1]);' "$E"`,
file run 1 into it (`W 1` with `PIPELINE_PROOF_ROOT="$E"`) after copying its `index.html` to `stale.html`, and dump
`file://$E/stale.html` as above. Expected: the dump holds `<table id="runs">` (the page reloaded itself into the
table). Remove `$(dirname "$E")` afterwards.

- [ ] **Step 3: Open the index in the Playwright MCP**

`browser_navigate` to `file://$COPY/index.html`. If the MCP refuses `file:` URLs, serve the copy in the background
(`php -S 127.0.0.1:8154 -t "$COPY"`) and use `http://127.0.0.1:8154/index.html` for every step below; the run pages
then open from the same origin, so the seen marks still meet (spec Assumption 15).

Mark every run seen and reload:
`() => { document.querySelectorAll('#runs tbody tr[data-revision]').forEach(r => localStorage.setItem('seen:' + r.dataset.run, r.dataset.revision)); localStorage.setItem('proof:finished', '1'); location.reload(); }`.
Then define the probe used below:
`() => { const f = document.getElementById('favicon'); window.probe = (key) => { const r = document.querySelector('tr[data-run="' + key + '"]'); return { title: document.title, icon: Object.keys(f.dataset).find(k => f.dataset[k] === document.getElementById('favicon').getAttribute('href')), marker: r && r.querySelector('.marker').textContent, status: r && r.dataset.status, kept: window.kept === true, scrolled: window.scrollY > 0 }; }; window.kept = true; window.scrollTo(0, 600); return probe('TabCheck/pr-99001-tab-check-1'); }`
Expected: `title: "Proofs"`, `icon: "none"`, `marker: ""`.

"Poll now" below means `() => window.dispatchEvent(new Event('focus'))`, then `browser_wait_for` 2 seconds.

- [ ] **Step 4: A new run turns the dot blue, in place**

`W 2`, poll now, then `() => probe('TabCheck/pr-99002-tab-check-2')`.
Expected: `title: "(1) Proofs"`, `icon: "unread"`, `marker: "New"`, `kept: true` (no reload), `scrolled: true`.
Click the new row's *Copy* button (`browser_click`): its text reads `Copied` (the delegated copy code works on an
inserted row).

- [ ] **Step 5: Opening the run clears it; filing it again brings it back as Updated**

Click the run's link (`browser_click` on `PR #99002: tab check 2`), then `browser_navigate_back`, re-run the probe
definition from Step 3 (a back navigation without the cache reloads the page), poll now, and
`() => probe('TabCheck/pr-99002-tab-check-2')` → `title: "Proofs"`, `icon: "none"`, `marker: ""`.
`W 2` again, poll now → `title: "(1) Proofs"`, `icon: "unread"`, `marker: "Updated"`.

- [ ] **Step 6: Red for a halted run, green for a ready one**

`W 3`, then `S 3 halted --reason "tab check"`, poll now, `() => probe('TabCheck/pr-99003-tab-check-3')` →
`title: "(2) Proofs"`, `icon: "halted"`, `status: "halted"`; the row is the first in the table
(`() => document.querySelector('#runs tbody tr').dataset.run`).
Open and come back from run 3's page and run 2's page (as in Step 5), then `W 4`, `S 4 ready`, poll now,
`() => probe('TabCheck/pr-99004-tab-check-4')` → `title: "(1) Proofs"`, `icon: "ready"`.

- [ ] **Step 7: A filter hides the row, not the signal**

Choose a repo other than `TabCheck` in the repo filter (`browser_select_option`) and type `zzzz` in the search, poll
now: run 4's row is hidden and the probe still says `title: "(1) Proofs"`, `icon: "ready"`. Choose *All repos* and
clear the search again.

- [ ] **Step 8: A run that merges leaves the count; a pruned run's row goes**

`() => document.getElementById('finished-count').textContent` → note it. `S 4 merged`, poll now → the probe for run 4
says `title: "Proofs"`, `icon: "none"`, `status: "merged"`, and `finished-count` is one more.
`rm -rf "$COPY/TabCheck/pr-99001-tab-check-1"`, then prune
(`PATH="$(dirname "$COPY")/bin:$PATH" PIPELINE_PROOF_ROOT="$COPY" php skills/pipeline/checks/proof_cli.php prune`),
poll now → `() => document.querySelector('tr[data-run="TabCheck/pr-99001-tab-check-1"]')` is `null`.

- [ ] **Step 9: A missing `status.js` changes nothing; a background tab catches up**

`mv "$COPY/status.js" "$COPY/status.js.away"`, poll now: the row count
(`() => document.querySelectorAll('#runs tbody tr').length`) and the title are as before, and the page is not reloaded
(`kept: true`); `mv "$COPY/status.js.away" "$COPY/status.js"`.
Open a new tab (`browser_tabs` action `new`), so the index is in the background. `W 5`. `browser_wait_for` 90
seconds, then `browser_tabs` action `list`: the index tab's title is `(1) Proofs`, without having been selected.
Select it again.

- [ ] **Step 10: Console and clean up**

`browser_console_messages`: no error but the one failed `status.js` load from Step 9. Stop the `php -S` server if
one ran. Remove the temp dir itself: `rm -rf "$(dirname "$COPY")"`. Confirm the live store was not written:
`ls ~/GitProjects/_proofs/status.js` → `No such file or directory` (no filing has rendered with the new code before
the merge), and `ls ~/GitProjects/_proofs/TabCheck` → `No such file or directory`.

---

### Task 7: The whole suite

- [ ] **Step 1: Run it**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: every test passes; the count is the base's plus the cases these tasks added, less the two `PIPELINE_NO_OPEN`
cases removed.

- [ ] **Step 2: Hooks untouched**

`git diff origin/main...HEAD --stat -- hooks` prints nothing, so `hooks/tests/` need not run.
