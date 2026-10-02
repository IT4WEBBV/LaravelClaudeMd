# A run's proof page links back to the store index — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every run page rendered by `proof_render_run()` opens with a muted *← All proofs* link to the store index
(`../../index.html`), and engine.md §The proof store names it.

**Architecture:** One fixed `<nav>` line prepended to the run page's body in `proof_render_run()`, one CSS rule pair
in `proof_render_styles()` reusing the `--muted` token, and one sentence in engine.md. The renderer stays pure: the
href is relative, because every run page sits exactly two directories below the store root
(`<root>/<repo>/<run>/index.html`). The index (`proof_render_index()`) is unchanged.

**Tech Stack:** PHP 8.4 (no framework), Pest 4 in `skills/pipeline/checks/tests`, inline CSS in the rendered page,
the Playwright MCP for the browser check.

**Spec:** `docs/superpowers/specs/2026-10-02-pipeline-proof-page-links-to-index-design.md`. Read it with this plan:
the plan argues from it, and its `## Assumptions` 8–9 are the answers this plan assumed.

## Global Constraints

- Run every command from the worktree root
  `/Users/jroelofs/GitProjects/LaravelClaudeMd/LaravelClaudeMd/.claude/worktrees/worktree-issue-153-pipeline-a-run-s-proof-page-links-back-to-the`.
- Tests: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
  (append `--filter <pattern>` for a subset). This repo is not a Docker project: Pest runs on the host. The worktree
  has no `vendor/` yet: run `composer install` once first. The suite needs `node` on `PATH`.
- Test-first: write the test, see it fail, then write the code. `php -l` every PHP file you change.
- The link, verbatim, one line, the body's first element, before `<h1>`:
  `<nav class="back" aria-label="Proof store"><a href="../../index.html">← All proofs</a></nav>`
  The arrow is the literal `←` (U+2190) in the PHP source, never `&larr;`.
- The style, verbatim, beside `.meta` in `proof_render_styles()`:
  `.back { margin:0 0 .75rem; font-size:.875rem; }` and `.back a { color:var(--muted); }`. No new colour token.
- The index gets no link back: `proof_render_index()` is not changed.
- No backfill: existing pages get the link from their next filing or amendment. No new CLI command.
- **No step writes to the real store** (`~/GitProjects/_proofs`). The browser check works on a copy.
- No changelog entry: the repo has no `.changelog/` and no `CHANGELOG.md`.
- Commits: no `Co-Authored-By`, no AI attribution; every message ends on `(#153)`.

## Review Focus

1. **A page opened outside the index** (by `proof_cli.php open`, from a PR body's path, from chat) — the link lands
   on the index of the store the page is in, over `file://` and over `php -S` alike. Pinned by the relative-href
   assertion in Task 1 and the click in Task 2.
2. **A run filed before schema 2** — it renders exactly as before, plus the link; no pending lines appear. Pinned by
   Task 1's second test and the existing "renders a run filed before this change as before" case.
3. **The link rendered once** — a page carries exactly one `class="back"`, never a second from a future refactor
   that renders the header twice. Pinned by the `substr_count` assertion in Task 1's first test.
4. **Dark mode and a 390 px viewport** — the link stays legible (the `--muted` token has its dark value) and causes
   no horizontal scroll. Checked in Task 2 at 1440/390 px, light and dark.
5. **A click on the link** — it is an ordinary navigation, not swallowed by the zoom handler (which acts only on
   `.shot`) and not blocked by the seen script. Checked by the click in Task 2.

## File Structure

- Modify `skills/pipeline/checks/proof_render.php`: `proof_render_styles()` (the `.back` rules after `.meta code`),
  `proof_render_run()` (the `$body` assignment that starts with `'<h1>'`, currently line 512).
- Modify `skills/pipeline/checks/tests/ProofRenderTest.php`: three new cases appended at the end of the file (where
  `proof_current_run()`, defined at line 271, is already in scope).
- Modify `skills/pipeline/references/engine.md` §The proof store: the paragraph starting
  `The page opens with the **client summary**` (currently line 916).

---

### Task 1: The run page links back to the store index

**Files:**
- Modify: `skills/pipeline/checks/proof_render.php:39-40` (styles), `:512` (`proof_render_run()` body)
- Modify: `skills/pipeline/references/engine.md:916-919`
- Test: `skills/pipeline/checks/tests/ProofRenderTest.php` (append at end)

**Interfaces:**
- Consumes: `proof_render_run(array $run): string`, `proof_render_index(array $runs): string` (list of
  `['dir' => string, 'run' => array]`), test helpers `proof_fixture_run(array $overrides = []): array` (no `schema`
  key) and `proof_current_run(array $overrides = []): array` (`schema: 2`), both in `ProofRenderTest.php`.
- Produces: the run page's first body element `<nav class="back" aria-label="Proof store">…</nav>` and the CSS class
  `.back`. Nothing later in this plan calls new code.

- [ ] **Step 0: Install dependencies (once)**

Run: `composer install`
Expected: `vendor/bin/pest` exists.

- [ ] **Step 1: Write the failing tests**

Append to `skills/pipeline/checks/tests/ProofRenderTest.php`:

```php
it('opens the run page with one link back to the store index, relative, above the title', function () {
    $html = proof_render_run(proof_current_run());
    $link = '<nav class="back" aria-label="Proof store"><a href="../../index.html">← All proofs</a></nav>';

    expect($html)->toContain($link);
    expect(substr_count($html, 'class="back"'))->toBe(1);
    expect(strpos($html, $link))->toBeGreaterThan(strpos($html, '<body'))->toBeLessThan(strpos($html, '<h1>'));
});

it('gives a run filed before schema 2 the link back too', function () {
    $html = proof_render_run(proof_fixture_run(['schema' => 1]));
    $link = '<nav class="back" aria-label="Proof store"><a href="../../index.html">← All proofs</a></nav>';

    expect($html)->toContain($link);
    expect(strpos($html, $link))->toBeLessThan(strpos($html, '<h1>'));
    expect($html)->not->toContain('Pending:');
});

it('gives the store index no link back, since it is the root', function () {
    $html = proof_render_index([
        ['dir' => '/store/ViewieMedia/pr-412-orders-export', 'run' => proof_fixture_run()],
    ]);

    expect($html)->not->toContain('class="back"');
    expect($html)->not->toContain('All proofs');
});
```

- [ ] **Step 2: Run the tests to verify the first two fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "link back"`
Expected: 2 FAIL (`opens the run page with one link back…` and `gives a run filed before schema 2…`, each on the
missing `<nav class="back"…>` string); `gives the store index no link back` PASSES already (it guards the index
against the change).

- [ ] **Step 3: Add the style**

In `skills/pipeline/checks/proof_render.php`, `proof_render_styles()`, directly after the line
`.meta code { background:var(--card); padding:.1rem .35rem; border-radius:.25rem; }` insert:

```css
.back { margin:0 0 .75rem; font-size:.875rem; }
.back a { color:var(--muted); }
```

- [ ] **Step 4: Render the link**

In `proof_render_run()`, replace

```php
    $body = '<h1>' . proof_e($title) . "</h1>\n<p class=\"status\">" . proof_render_status($run) . "</p>\n<p class=\"meta\">{$meta}</p>\n";
```

with

```php
    // Every run page sits at <root>/<repo>/<run>/index.html, so the store index is always two levels up.
    $body = "<nav class=\"back\" aria-label=\"Proof store\"><a href=\"../../index.html\">← All proofs</a></nav>\n"
        . '<h1>' . proof_e($title) . "</h1>\n<p class=\"status\">" . proof_render_status($run) . "</p>\n<p class=\"meta\">{$meta}</p>\n";
```

Run: `php -l skills/pipeline/checks/proof_render.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Run the new tests and the render file to verify they pass**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter ProofRenderTest`
Expected: PASS, every case, including the existing `renders a self-contained page with only relative image paths`,
`renders a run filed before this change as before…` and `names the revision in the meta line and on the body…`
(spec Assumption 9: `<body data-revision="3">\n` and `<body>\n` still hold, the link starts on the next line).

- [ ] **Step 6: Name the link in engine.md**

In `skills/pipeline/references/engine.md` §The proof store, in the paragraph that starts
`The page opens with the **client summary**`, after its last sentence
`A store-wide \`index.html\` is the join from a PR back to its page.` append (same paragraph):

```markdown
Above the heading, an *← All proofs* link goes to the store index (`../../index.html`, relative, so it works over
`file://`).
```

`SKILL.md` *Visual proof* is not changed.

- [ ] **Step 7: Run the whole suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, 0 failures (the `LockStepTest` cases over engine.md included).

- [ ] **Step 8: Commit**

```bash
git add skills/pipeline/checks/proof_render.php skills/pipeline/checks/tests/ProofRenderTest.php skills/pipeline/references/engine.md
git commit -m "feat(pipeline): a run's proof page links back to the store index (#153)"
```

---

### Task 2: Check the link in the browser, over a copy of the real store

No code and no commit: this proves Task 1 on real pages. Never write to `~/GitProjects/_proofs`.

**Files:** none changed. Works in a temp dir.

**Interfaces:**
- Consumes: Task 1's renderer; `proof_store_amend(string $page, callable $change): ?string` from
  `skills/pipeline/checks/proof_store.php` (re-renders `run.json`, the page and the index of the store the page is
  in, `dirname($page, 3)`; returns `null` on success, else why not).

- [ ] **Step 1: Copy the store and re-render one run in the copy**

Shell state does not persist between an agent's Bash calls: run this step as one command, and carry the printed
`$COPY` and `$PAGE` paths into the later steps literally.

```bash
COPY=$(mktemp -d)/_proofs && cp -R ~/GitProjects/_proofs "$COPY" && echo "$COPY"
PAGE=$(ls "$COPY"/LaravelClaudeMd/*/index.html | head -1) && echo "$PAGE"
php -r 'require "skills/pipeline/checks/proof_store.php"; var_dump(proof_store_amend($argv[1], fn (array $run): array => $run));' "$PAGE"
grep -c 'class="back"' "$PAGE"
```

Expected: `NULL`, then `1`. (If `LaravelClaudeMd/` has no run, take the first `"$COPY"/*/*/index.html` outside
`_adhoc/`.)

- [ ] **Step 2: Serve the copy**

Run (in the background): `php -S 127.0.0.1:8153 -t "$COPY"`

- [ ] **Step 3: Look at it at 1440 px and 390 px, light and dark**

With the Playwright MCP: `browser_navigate` to `http://127.0.0.1:8153/<repo>/<run>/index.html` (the `$PAGE` path
relative to `$COPY`). For each of 1440×900 and 390×844 (`browser_resize`) and each of `light` and `dark`
(`browser_emulate_media` `colorScheme`): screenshot the top of the page.
Expected: *← All proofs* sits above the title, small and muted, legible on both backgrounds;
`browser_evaluate` `() => document.documentElement.scrollWidth <= window.innerWidth` returns `true` at 390 px.

- [ ] **Step 4: Click it**

`browser_click` on the *← All proofs* link.
Expected: the URL becomes `http://127.0.0.1:8153/index.html` and the page shows `Pipeline proof store`; no zoom
dialog opened.

- [ ] **Step 5: Clean up**

Stop the `php -S` server and `rm -rf` the temp dir. Confirm the live store was not written:
`grep -l 'class="back"' ~/GitProjects/_proofs/*/*/index.html` prints nothing (no filing has rendered with the new
code before the merge).
