# Proof page GitHub links open a new tab, and the run page links the diff — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every GitHub link the proof renderer writes opens a new tab (`target="_blank" rel="noopener"`) while the
store's own links stay in the tab, and a run page's meta line gains a *Files changed* link to the PR's diff right
after the PR reference.

**Architecture:** `proof_render_ref()` is the one function every GitHub link goes through and is used for nothing
else, so it alone gains the two attributes. A new helper `proof_render_diff_ref(array $run): string` beside it
returns the *Files changed* link, or `''` when there is no PR or no URL, which the meta line's existing
`array_filter` drops. engine.md §The proof store names both.

**Tech Stack:** PHP 8.4 (no framework), Pest 4 in `skills/pipeline/checks/tests`, the Playwright MCP for the browser
check.

**Spec:** `docs/superpowers/specs/2026-10-02-pipeline-proof-github-links-new-tab-design.md`. Read it with this plan:
the plan argues from it, and its `## Assumptions` 11–13 are the answers this plan assumed.

## Global Constraints

- Run every command from the worktree root
  `/Users/jroelofs/GitProjects/LaravelClaudeMd/LaravelClaudeMd/.claude/worktrees/worktree-issue-169-pipeline-proof-page-links-open-github-in-a-new-tab`.
- Tests: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
  (append `--filter <pattern>` for a subset). This repo is not a Docker project: Pest runs on the host. The worktree
  has no `vendor/` yet: run `composer install` once first. The suite needs `node` on `PATH`.
- Test-first: write the test, see it fail, then write the code. `php -l` every PHP file you change.
- A GitHub link, verbatim, attribute order fixed: `<a href="<url>" target="_blank" rel="noopener"><label></a>`.
  `rel="noopener"` alone, not `noopener noreferrer`.
- The store's own links (`<nav class="back" aria-label="Proof store"><a href="../../index.html">← All proofs</a></nav>`
  and the index row's title link `<a href="<repo>/<run>/index.html">`) are not changed and carry no `target`.
- `proof_render_ref()` keeps its name and signature (sibling run #146 may edit `proof_render.php`).
- *Files changed* links `https://github.com/<nameWithOwner>/pull/<P>/files` (not `/changes`), on the run page only,
  never in the index, never as plain text.
- No backfill, no change to the index scripts or to `proof_index_row_hash()`.
- **No step writes to the real store** (`~/GitProjects/_proofs`). The browser check works on a copy.
- No changelog entry: the repo has no `.changelog/` and no `CHANGELOG.md`.
- Commits: no `Co-Authored-By`, no AI attribution; every message ends on `(#169)`.

## Review Focus

1. **The rows an open index swaps in** — `status.js` carries the row HTML that replaces a changed row in an open
   index; it must carry the new-tab PR link too, not only the static `index.html`. Pinned by the `status.js`
   assertion in Task 1's index case.
2. **A run whose `nameWithOwner` is blank but present** (`'   '`, as an old or hand-edited `run.json` may hold) —
   no *Files changed*, the PR stays plain text, since `proof_github_url()` trims. Pinned by the dataset on Task 1's
   no-repo case.
3. **A PR number stored as a string** (`'967'`) — the diff URL is `…/pull/967/files` like the PR's, via `(int)`.
   Pinned by the string-PR assertion in Task 1's diff case.
4. **A schema-2 run** (summary, explainer, tests) — gets *Files changed* exactly as a schema-1 run does: the meta line
   is rendered before the schema branch. Pinned by the `proof_current_run()` assertion in Task 1's diff case.
5. **A click on a GitHub link** — an ordinary navigation into a new tab, not swallowed by the zoom or copy handler,
   and *← All proofs* still navigates in the same tab. Checked by the clicks in Task 2.

## File Structure

- Modify `skills/pipeline/checks/proof_render.php`: `proof_render_ref()` (currently lines 467–473), a new
  `proof_render_diff_ref()` directly after it, and the `$meta` list in `proof_render_run()` (currently lines 520–528).
- Modify `skills/pipeline/checks/tests/ProofRenderTest.php`: four existing cases' exact strings (currently lines 143,
  149, 233, 680) and five new cases plus one helper appended at the end of the file.
- Modify `skills/pipeline/references/engine.md` §The proof store: the *← All proofs* paragraph (currently line
  921–922) and the `run.json` fields table row for `repo`, `nameWithOwner`, … (currently line 995).

---

### Task 1: GitHub links open a new tab, and the run page links the diff

**Files:**
- Modify: `skills/pipeline/checks/proof_render.php:467-473` (`proof_render_ref()`, plus the new helper after it),
  `:520-528` (`proof_render_run()`'s `$meta`)
- Modify: `skills/pipeline/references/engine.md:921-922`, `:995`
- Test: `skills/pipeline/checks/tests/ProofRenderTest.php` (lines 143, 149, 233, 680; append at end)

**Interfaces:**
- Consumes: `proof_github_url(array $run, string $path): ?string` (null when `nameWithOwner` is missing or blank
  after `trim`), `proof_e(string): string`, `proof_render_run(array $run): string`,
  `proof_render_index(array $runs): string` (list of `['dir' => string, 'run' => array]`),
  `proof_render_status_js(array $runs): string`; test helpers `proof_fixture_run(array $overrides = []): array`
  (no `schema` key, `pr` 412, `nameWithOwner` `IT4WEBBV/ViewieMedia`), `proof_current_run(array $overrides = []): array`
  (`schema: 2`), `proof_index_entry(string $name, array $run): array` (all in `ProofRenderTest.php`) and
  `proof_test_status_runs(string $js): array` (in `Pest.php`, each run has a `row` key).
- Produces: `proof_render_ref(?string $url, string $label): string` with the new attributes;
  `proof_render_diff_ref(array $run): string`; test helper
  `proof_expect_github_links_in_a_new_tab(string $html, int $atLeast): void`. Task 2 calls no new code.

- [ ] **Step 0: Install dependencies (once)**

Run: `composer install`
Expected: `vendor/bin/pest` exists.

- [ ] **Step 1: Update the four existing cases to the new link shape**

In `skills/pipeline/checks/tests/ProofRenderTest.php`:

*links the PR reference to GitHub with an absolute URL* — replace

```php
    expect($html)->toContain('<a href="https://github.com/IT4WEBBV/ViewieMedia/pull/967">#967 (MERGED)</a>');
```

with

```php
    expect($html)->toContain('<a href="https://github.com/IT4WEBBV/ViewieMedia/pull/967" target="_blank" rel="noopener">#967 (MERGED)</a>');
```

*links the issue the run is for, from the payload field* — replace

```php
    expect($html)->toContain('<a href="https://github.com/IT4WEBBV/ViewieMedia/issues/919">issue #919</a>');
```

with

```php
    expect($html)->toContain('<a href="https://github.com/IT4WEBBV/ViewieMedia/issues/919" target="_blank" rel="noopener">issue #919</a>');
```

*links the PR column of the index to the PR on GitHub* — replace

```php
    expect($html)->toContain('<td data-sort="412"><a href="https://github.com/IT4WEBBV/ViewieMedia/pull/412">#412 OPEN</a></td>');
```

with

```php
    expect($html)->toContain('<td data-sort="412"><a href="https://github.com/IT4WEBBV/ViewieMedia/pull/412" target="_blank" rel="noopener">#412 OPEN</a></td>');
```

*gives each sortable cell its key, and an empty key where there is nothing to sort by* — replace

```php
        . '<td data-sort="5"><a href="https://github.com/IT4WEBBV/ViewieMedia/pull/5">#5 OPEN</a></td>'
```

with

```php
        . '<td data-sort="5"><a href="https://github.com/IT4WEBBV/ViewieMedia/pull/5" target="_blank" rel="noopener">#5 OPEN</a></td>'
```

- [ ] **Step 2: Write the new failing tests**

Append to the end of `skills/pipeline/checks/tests/ProofRenderTest.php`:

```php
/** Every `<a>` opening tag: one to GitHub opens a new tab, any other (the store's own) stays in this one. */
function proof_expect_github_links_in_a_new_tab(string $html, int $atLeast): void
{
    preg_match_all('/<a [^>]*>/', $html, $tags);

    expect(count($tags[0]))->toBeGreaterThanOrEqual($atLeast);
    expect($tags[0])->each(fn ($tag) => str_starts_with($tag->value, '<a href="https://github.com/')
        ? $tag->toContain(' target="_blank" rel="noopener"')
        : $tag->not->toContain('target='));
}

it('opens every GitHub link on a run page in a new tab, and keeps the link back in this one', function () {
    // PR, Files changed, issue and ← All proofs: an empty match cannot pass.
    proof_expect_github_links_in_a_new_tab(proof_render_run(proof_fixture_run(['issue' => 919])), 4);
});

it('opens the PR link of the index in a new tab, and keeps the run link in this one, in status.js too', function () {
    $entry = ['dir' => '/store/ViewieMedia/pr-412-orders-export', 'run' => proof_fixture_run(['pr' => 412, 'prState' => 'OPEN'])];

    // The PR column and the title link.
    proof_expect_github_links_in_a_new_tab(proof_render_index([$entry]), 2);
    expect(proof_test_status_runs(proof_render_status_js([$entry]))[0]['row'])
        ->toContain('<a href="https://github.com/IT4WEBBV/ViewieMedia/pull/412" target="_blank" rel="noopener">#412 OPEN</a>')
        ->toContain('<a href="ViewieMedia/pr-412-orders-export/index.html">');
    expect(proof_render_index([$entry]))->not->toContain('Files changed');
});

it('links the PR\'s diff right after the PR reference on the run page', function () {
    $html = proof_render_run(proof_fixture_run(['pr' => 967, 'prState' => 'MERGED']));

    expect($html)->toContain(
        '<a href="https://github.com/IT4WEBBV/ViewieMedia/pull/967" target="_blank" rel="noopener">#967 (MERGED)</a>'
        . ' · <a href="https://github.com/IT4WEBBV/ViewieMedia/pull/967/files" target="_blank" rel="noopener">Files changed</a>'
    );
    expect(substr_count($html, 'Files changed'))->toBe(1);
    expect(proof_render_run(proof_fixture_run(['pr' => '967'])))
        ->toContain('<a href="https://github.com/IT4WEBBV/ViewieMedia/pull/967/files" target="_blank" rel="noopener">Files changed</a>');
    expect(proof_render_run(proof_current_run(['pr' => 967])))
        ->toContain('<a href="https://github.com/IT4WEBBV/ViewieMedia/pull/967/files" target="_blank" rel="noopener">Files changed</a>');
});

it('has no diff link for a run without a PR', function () {
    $html = proof_render_run(proof_fixture_run(['pr' => null, 'prState' => null]));

    expect($html)->not->toContain('Files changed')->not->toContain('/files');
    expect($html)->toContain('<code>ViewieMedia</code> · no PR · <code>feature/orders-export</code>');
});

it('has no diff link for a run that names no repo to link into', function (?string $nameWithOwner) {
    $html = proof_render_run(proof_fixture_run(['nameWithOwner' => $nameWithOwner]));

    expect($html)->not->toContain('Files changed');
    expect($html)->toContain('<code>ViewieMedia</code> · #412 (OPEN) · <code>feature/orders-export</code>');
})->with([
    'missing' => [null],
    'blank' => ['   '],
]);
```

The last two cases pass already and stay as guards: they pin that the meta line's separators stay right when the
helper returns `''`. The fixture's branch `feature/orders-export` yields no issue number, so no issue reference sits
between the PR and the branch.

- [ ] **Step 3: Run the tests to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter ProofRenderTest`
Expected: FAIL in exactly these 7: the four cases updated in Step 1 (each on its missing ` target="_blank" rel="noopener"`
string); *opens every GitHub link on a run page in a new tab…* (a GitHub tag without `target`, or 3 tags where 4 are
needed); *opens the PR link of the index in a new tab…* (a GitHub tag without `target`); *links the PR's diff right
after the PR reference…* (missing string). PASS: *has no diff link for a run without a PR* and both datasets of
*has no diff link for a run that names no repo…*, and every other case.

- [ ] **Step 4: Give every GitHub link a new tab**

In `skills/pipeline/checks/proof_render.php`, replace

```php
/** A reference that becomes a link when there is a URL for it, and stays plain text otherwise. */
function proof_render_ref(?string $url, string $label): string
{
    return $url === null
        ? proof_e($label)
        : '<a href="' . proof_e($url) . '">' . proof_e($label) . '</a>';
}
```

with

```php
/**
 * A reference to GitHub that becomes a link, opening in a new tab so the proof page stays where it was, when there
 * is a URL for it, and stays plain text otherwise.
 */
function proof_render_ref(?string $url, string $label): string
{
    return $url === null
        ? proof_e($label)
        : '<a href="' . proof_e($url) . '" target="_blank" rel="noopener">' . proof_e($label) . '</a>';
}

/**
 * The PR's diff, where the review happens. Never plain text: without a PR or a URL the words would point at nothing,
 * so it is left out (the meta line's array_filter drops the empty string).
 */
function proof_render_diff_ref(array $run): string
{
    $url = empty($run['pr']) ? null : proof_github_url($run, 'pull/' . (int) $run['pr'] . '/files');

    return $url === null ? '' : proof_render_ref($url, 'Files changed');
}
```

- [ ] **Step 5: Put the diff link in the meta line**

In `proof_render_run()`, replace

```php
    // array_filter drops the issue reference when the run has none, so the separators stay right.
    $meta = implode(' · ', array_filter([
        '<code>' . proof_e((string) ($run['repo'] ?? '')) . '</code>',
        $pr,
        $issueRef,
```

with

```php
    // array_filter drops the diff and issue references when the run has none, so the separators stay right.
    $meta = implode(' · ', array_filter([
        '<code>' . proof_e((string) ($run['repo'] ?? '')) . '</code>',
        $pr,
        proof_render_diff_ref($run),
        $issueRef,
```

Run: `php -l skills/pipeline/checks/proof_render.php && php -l skills/pipeline/checks/tests/ProofRenderTest.php`
Expected: `No syntax errors detected` twice.

- [ ] **Step 6: Run the render tests to verify they pass**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter ProofRenderTest`
Expected: PASS, every case, including the unchanged store-link guards (*opens the run page with one link back…*,
*gives a run filed before schema 2 the link back too*, *links each run by its relative directory…*) and
*keeps references as plain text when the run names no repo to link into*.

- [ ] **Step 7: Name both in engine.md**

In `skills/pipeline/references/engine.md` §The proof store, replace

```markdown
Above the heading, an *← All proofs* link goes to the store index (`../../index.html`, relative, so it works over
`file://`).
```

with

```markdown
Above the heading, an *← All proofs* link goes to the store index (`../../index.html`, relative, so it works over
`file://`). Every link to GitHub, on a run page and in the index, opens a new tab (`target="_blank" rel="noopener"`);
the store's own links stay in the tab. Next to the PR, *Files changed* links the PR's diff (`/pull/<P>/files`).
```

and in the `run.json` fields table replace

```markdown
| `repo`, `nameWithOwner`, `branch`, `pr`, `issue`, `prState`, `mode` | where the run belongs; `nameWithOwner` makes the PR and issue references links |
```

with

```markdown
| `repo`, `nameWithOwner`, `branch`, `pr`, `issue`, `prState`, `mode` | where the run belongs; `nameWithOwner` makes the PR, *Files changed* and issue references links |
```

`SKILL.md` *Visual proof* is not changed.

- [ ] **Step 8: Run the whole suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, 0 failures (the `LockStepTest` cases over engine.md included).

- [ ] **Step 9: Commit**

```bash
git add skills/pipeline/checks/proof_render.php skills/pipeline/checks/tests/ProofRenderTest.php skills/pipeline/references/engine.md
git commit -m "feat(pipeline): proof page GitHub links open a new tab, and the run page links the PR's diff (#169)"
```

---

### Task 2: Check the links in the browser, over a copy of the real store

No code and no commit: this proves Task 1 on real pages. Never write to `~/GitProjects/_proofs`.

**Files:** none changed. Works in a temp dir.

**Interfaces:**
- Consumes: Task 1's renderer; `proof_store_amend(string $page, callable $change): ?string` from
  `skills/pipeline/checks/proof_store.php` (re-renders `run.json`, the page and the index of the store the page is
  in, `dirname($page, 3)`; returns `null` on success, else why not).

- [ ] **Step 1: Copy the store and re-render one run with a PR in the copy**

Shell state does not persist between an agent's Bash calls: run this step as one command, and carry the printed
`$COPY` and `$PAGE` paths into the later steps literally.

```bash
COPY=$(mktemp -d)/_proofs && cp -R ~/GitProjects/_proofs "$COPY" && echo "$COPY"
PAGE=$(grep -l '"nameWithOwner": *"' "$COPY"/LaravelClaudeMd/pr-*/run.json | head -1 | xargs dirname)/index.html && echo "$PAGE"
php -r 'require "skills/pipeline/checks/proof_store.php"; var_dump(proof_store_amend($argv[1], fn (array $run): array => $run));' "$PAGE"
grep -c 'Files changed' "$PAGE"
grep -c 'target="_blank" rel="noopener"' "$COPY/index.html"
```

Expected: `NULL`, then `1`, then a count of at least `1`. (If `LaravelClaudeMd/` has no `pr-*` run with a
`nameWithOwner`, take the first `"$COPY"/*/pr-*/run.json` that has one, outside `_adhoc/`.)

- [ ] **Step 2: Serve the copy**

Run (in the background): `php -S 127.0.0.1:8169 -t "$COPY"`

- [ ] **Step 3: Click the PR link and Files changed on the run page**

With the Playwright MCP: `browser_navigate` to `http://127.0.0.1:8169/<repo>/<run>/index.html` (the `$PAGE` path
relative to `$COPY`). Screenshot the meta line at 1440×900 and at 390×844 (`browser_resize`).
`browser_click` the PR link, then `browser_tabs` `list`; select the first tab again (`browser_tabs` `select`
index 0), `browser_click` *Files changed*, `browser_tabs` `list`.
Expected: *Files changed* sits right after the PR reference in the meta line (wrapping at 390 px, no horizontal
scroll: `browser_evaluate` `() => document.documentElement.scrollWidth <= window.innerWidth` returns `true`); after
the first click two tabs, the new one at `https://github.com/<nameWithOwner>/pull/<P>`; after the second three tabs,
the new one at `…/pull/<P>/files`; tab 0 is still the proof page each time, and no zoom dialog opened.

- [ ] **Step 4: Click *← All proofs*, then the index's PR link**

On tab 0: `browser_click` *← All proofs*, `browser_tabs` `list`.
Expected: tab 0's URL becomes `http://127.0.0.1:8169/index.html` showing `Pipeline proof store`, and the tab count is
unchanged.
On the index, `browser_click` the PR link in the re-rendered run's row, `browser_tabs` `list`.
Expected: one more tab, at `…/pull/<P>`; tab 0 is still the index.

- [ ] **Step 5: Clean up**

Close the extra tabs, stop the `php -S` server and remove the `mktemp` dir itself, not only `_proofs` inside it:
`rm -rf "$(dirname "$COPY")"`. Confirm the live store was not written:
`grep -l 'Files changed' ~/GitProjects/_proofs/*/*/index.html` prints nothing (no filing has rendered with the new
code before the merge).
