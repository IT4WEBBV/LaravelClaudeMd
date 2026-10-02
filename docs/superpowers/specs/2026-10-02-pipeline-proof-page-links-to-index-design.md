# A run's proof page links back to the store index — design

**Design size:** Architectural (the run requires the Architectural path; the change itself is one renderer)

**Date:** 2026-10-02
**Issue:** IT4WEBBV/LaravelClaudeMd#153
**Canonical home:** `skills/pipeline/checks/proof_render.php` (`proof_render_run()`, `proof_render_styles()`),
`skills/pipeline/checks/tests/ProofRenderTest.php`, pipeline `references/engine.md` §The proof store (the page's
layout paragraph).
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. The issue body is the approved scope and
is not re-litigated here. Every question the brainstorm would have asked is answered in *Assumptions*, so
`/critique plan` audits exactly those. Nothing was built or run; what the design relies on was read (see *What was
read*). No probe was needed: no approach hinges on whether it works at all.

## Problem

The store index links to every run (`proof_render_index_row()`: `<a href="<repo>/<run>/index.html">`), but no run
page links back. `proof_render_run()` opens the body with the `h1`, then the status line and the meta line; nothing
before or after names `~/GitProjects/_proofs/index.html`. A page reached from the index has the browser's Back; a
page opened by `proof_cli.php open` at the end of a run, from a PR body's path or from a chat link has nothing to
go back to.

Every run page sits exactly two directories below the store root: `proof_run_dir()` builds
`<root>/<proof_slug(repo)>/<run slug>`, `proof_scan_runs()` globs `<root>/*/*/run.json`, and `proof_store_amend()`
already relies on it (`dirname($page, 3)` is the store root). So `../../index.html` from any page is the index of
the store the page is in, the real store or a test one.

## Approaches

1. **A static relative link rendered by `proof_render_run()` (chosen).** `<nav class="back"><a
   href="../../index.html">← All proofs</a></nav>` as the body's first element. Relative, so it works over
   `file://` and over `php -S` alike and on both machines; the renderer stays pure (no path, no root passed in).
2. **An absolute `file://` href built from `proof_root()`.** Breaks over `php -S`, differs per machine (`$HOME`), and
   drags a filesystem fact into a renderer whose contract is "no filesystem". Rejected.
3. **A script that goes `history.back()` when there is history, else the index.** Adds behaviour for no gain: the
   relative link already lands on the index, and Back is still the browser's. Rejected.

## Design

### The link

`proof_render_run()` puts one line at the top of the body, before the `<h1>`:

```html
<nav class="back" aria-label="Proof store"><a href="../../index.html">← All proofs</a></nav>
```

It is a fixed string: nothing in it comes from the run, so nothing needs escaping. Every run page gets it,
whatever its `schema` (a schema-1 page renders as before plus the link): the link describes where the page lives,
not what the run reports. The index (`proof_render_index()`) gets nothing: it is the root.

The arrow is the literal `←` (U+2190) in the PHP source, as the renderer already writes `—` literally; the page
declares `<meta charset="utf-8">`.

### The style

`proof_render_styles()` gains one rule beside `.meta`:

```css
.back { margin:0 0 .75rem; font-size:.875rem; }
.back a { color:var(--muted); }
```

Muted, small, above the title, so it reads as navigation and not as content; colour from the existing
`--muted` token, which already has its dark value, so light and dark both work with no new token. The underline
comes from the existing `a` rule. Nothing about it changes at 390 px: one short line, no horizontal overflow.

### When existing pages get it

A page carries the link from its next render: any filing (`proof_store_file()`: `handoff`, an agent `write`) or any
amendment (`proof_store_amend()`: `proof_cli.php status`, `run_cost_cli.php <dir> <page>`, the prune pass when
`gh` changes its PR state or status). No backfill pass re-renders the whole store (Assumption 2).

### The docs

engine.md §The proof store, the paragraph that lists what the page opens with, gains: "Above the heading, an
*← All proofs* link goes to the store index (`../../index.html`, relative, so it works over `file://`)." `SKILL.md`
*Visual proof* is unchanged: it names the page and the index already, and the link is a detail of the page.

## Testing

Pest, in `skills/pipeline/checks/tests/ProofRenderTest.php`, test first:

- **the run page carries the link back, relative, above the title**: for `proof_fixture_run()` the HTML contains
  `<nav class="back" aria-label="Proof store"><a href="../../index.html">← All proofs</a></nav>`, and its position is
  after `<body` and before `<h1>`;
- **a run filed before schema 2 carries it too**: the same assertion on a fixture without `schema` (the existing
  "renders a run filed before this change as before" case keeps passing: the link is no pending line);
- **the index has no link back**: `proof_render_index()` over one run does not contain `class="back"`.

The existing "renders a self-contained page with only relative image paths" case keeps passing: the new href is
a hyperlink, not a fetch, and relative besides.

**In the browser**, in `implement`. The Playwright MCP refuses `file:` URLs, so: a copy of `~/GitProjects/_proofs`
in a temp dir, one run in it re-rendered by the new renderer (`proof_store_amend($page, fn ($run) => $run)` from a
`php -r` against the copy, which writes only the copy), the copy served with `php -S 127.0.0.1:<port> -t <copy>`.
On that run's page, at 1440 px and 390 px, light and dark (`browser_emulate_media`): the link sits above the title,
muted, with no horizontal scroll, and a click opens the copy's index. The real store is never written; the live
store's pages get the link from their next filing after the merge.

## Done when

- Every run page rendered by `proof_render_run()` opens with *← All proofs* linking to `../../index.html`.
- The three tests above pass, and the suite is green.
- In the browser, over a served copy of the real store, the link on a re-rendered run page opens the index, at
  1440 and 390 px, light and dark.
- engine.md §The proof store names the link.

## Assumptions

Each question the brainstorm would have asked the owner, and the answer assumed.

1. **Is `../../index.html` right for every page the renderer writes?** Yes: every run directory is
   `<root>/<repo>/<run>` (`proof_run_dir()`, `proof_scan_runs()`'s `*/*/run.json`, `proof_store_amend()`'s
   `dirname($page, 3)`). The hand-made pages under `_proofs/_adhoc/` are not rendered by `proof_render_run()` and are
   out of scope.
2. **Should a backfill re-render every existing page now?** No. The issue accepts "pages filed before the change get
   it when they are re-rendered"; a finished old run may never be re-rendered, and its page is still reachable from
   the index. A `proof_cli.php render` pass would be new surface for a one-off.
3. **Does the index get a link too?** No; it is the store's root.
4. **`<nav>` or `<p>`?** `<nav aria-label="Proof store">`: it is navigation, and the label tells a screen reader
   where it goes. No other landmark on the page competes with it.
5. **Literal `←` or `&larr;`?** Literal, as the renderer writes `—` literally and the page is UTF-8; the test asserts
   the literal string.
6. **Does the seen script or the zoom script care?** No: the seen key comes from `location.pathname`, and the zoom
   handler acts only on `.shot`; a click on the link is an ordinary navigation.
7. **Does the browser check need the real store?** A copy of it, served over `php -S` (the Playwright MCP blocks
   `file:`), with one page re-rendered: real data, no write to the live store. Over `file://` the relative href
   resolves the same way, since the directory depth is the same.
8. **Which fixtures do the two page tests use?** (Added by the `plan` step.) `proof_fixture_run()` carries no
   `schema` key, so it already *is* a run filed before schema 2. The current-schema case therefore uses
   `proof_current_run()` (`schema: 2`, defined beside the other schema-2 tests in `ProofRenderTest.php`), and the
   pre-schema-2 case uses `proof_fixture_run(['schema' => 1])`, shaped like the existing "renders a run filed before
   this change as before" case. Both assert the same literal `<nav>` line and its position.
9. **Does any existing test pin what follows `<body>`?** (Added by the `plan` step.) Only the revision case, which
   asserts `<body data-revision="3">\n` and `<body>\n`: the link starts on the next line, so both keep passing.
   `Pest.php`'s `proof_test_page()` renders through `proof_render_run()` and asserts nothing about the layout.

## Relation to other work

- **#142** (merged, d5f309b) is the index this page links back to; the issue depends on it.
- **#149** (in flight, no PR yet) changes the CI gate in `dispatch_cli.php`/engine.md §The CI gate; this spec touches
  neither, only engine.md §The proof store. Whichever lands second merges (engine.md §Catching up with the base).

## What was read

The issue; `proof_render.php` (whole: `proof_render_run()`, `proof_render_index()`, `proof_render_index_row()`,
the styles and the scripts); `proof_store.php` (`proof_store_file()`, `proof_store_dirs()`, `proof_store_amend()`);
`proof.php` (`proof_run_dir()`, `proof_run_slug()`, `proof_slug()`, `proof_scan_runs()`, `proof_root()`);
`proof_cli.php` (`proof_cli_refresh()`, `proof_cli_prune()`); `ProofRenderTest.php` (its fixture and case list);
engine.md §The proof store and `SKILL.md` *Visual proof*; the real store's layout (`~/GitProjects/_proofs`, repo
folders and `_adhoc/`); the #142 spec.
