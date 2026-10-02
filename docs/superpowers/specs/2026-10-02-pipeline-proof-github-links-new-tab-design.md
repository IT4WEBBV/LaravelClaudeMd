# Proof page links open GitHub in a new tab, and link the PR's diff directly — design

**Design size:** Architectural (the run requires the Architectural path; the change itself is one helper, one meta-line entry and their tests)

**Date:** 2026-10-02
**Issue:** IT4WEBBV/LaravelClaudeMd#169
**Canonical home:** `skills/pipeline/checks/proof_render.php` (`proof_render_ref()`, `proof_render_run()`),
`skills/pipeline/checks/tests/ProofRenderTest.php`, pipeline `references/engine.md` §The proof store (the page's
layout paragraph and the `run.json` fields table).
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. The issue body is the approved scope and
is not re-litigated here. Every question the brainstorm would have asked is answered in *Assumptions*, so
`/critique plan` audits exactly those. Nothing was built or run; what the design relies on was read (see *What was
read*). No probe was needed: no approach hinges on whether it works at all.

## Problem

The owner reviews a run's diff on GitHub, reached from its proof page or from the store index. Every GitHub link
the renderer writes goes through `proof_render_ref()`:

```php
return $url === null
    ? proof_e($label)
    : '<a href="' . proof_e($url) . '">' . proof_e($label) . '</a>';
```

so it opens in the same tab: the proof page, or the index it was opened from, is gone, and getting back means Back
or the Proofs app. The PR link also lands on the PR's conversation, one click away from *Files changed*, where the
review happens.

There are exactly three GitHub links today, all through `proof_render_ref()` with a URL from
`proof_github_url()`:

| Where | Call site | Target |
|---|---|---|
| run page, meta line | `proof_render_run()`, `$pr` | `https://github.com/<nameWithOwner>/pull/<P>` |
| run page, meta line | `proof_render_run()`, `$issueRef` | `https://github.com/<nameWithOwner>/issues/<N>` |
| index, PR column | `proof_render_index_row()`, `$pr` | `https://github.com/<nameWithOwner>/pull/<P>` |

The store's own links are written inline, never through `proof_render_ref()`: the run page's
`<nav class="back"><a href="../../index.html">← All proofs</a></nav>` and the index row's title link
`<a href="<repo>/<run>/index.html">`. Nothing else in the renderer emits an `<a`; the index scripts build no links
(they replace whole rows with the HTML `proof_render_index_row()` wrote into `status.js`).

## Approaches

1. **`proof_render_ref()` itself writes `target="_blank" rel="noopener"` (chosen).** It is the one function every
   GitHub link goes through and it is used for nothing else, while the store links never go through it. So "GitHub
   opens a new tab, the store stays in this tab" becomes a property of the structure rather than of each call site,
   and a GitHub link added later through it gets the behaviour for free. One line changes; its docblock says what a
   reference now is.
2. **A `bool $newTab` parameter on `proof_render_ref()`.** Every caller would pass `true`, and a boolean flag is the
   conditional the conventions steer away from. Rejected.
3. **`<base target="_blank">` in each page's `<head>`.** Inverts the default: the store links would then need
   `target="_self"` each, and a store link added later without it would silently open new tabs. Rejected.
4. **A click handler in the page scripts.** Behaviour for what HTML expresses statically; nothing in the scripts
   needs it. Rejected.

## Design

### Every GitHub link opens a new tab

`proof_render_ref()` returns, when it has a URL:

```html
<a href="<url>" target="_blank" rel="noopener"><label></a>
```

Attribute order fixed as shown (`href`, `target`, `rel`), so tests can assert the exact string. Without a URL it
stays plain escaped text, as today. The function keeps its name and signature (Assumption 3); its docblock becomes:
"A reference to GitHub that becomes a link, opening in a new tab so the proof page stays where it was, when there
is a URL for it, and stays plain text otherwise."

That covers all three links in the table above, on the run page and in the index (and so in the rows `status.js`
carries, which are the same `proof_render_index_row()` text). The store links (`← All proofs`, the index row's title
link) are untouched and keep opening in the same tab.

### The run page links the diff

The run page's meta line gains a *Files changed* reference right after the PR reference:

```
<code>ViewieMedia</code> · <a …/pull/412 …>#412 (OPEN)</a> · <a …/pull/412/files …>Files changed</a> · <a …/issues/919 …>issue #919</a> · <code>feature/…</code> · …
```

built by a small helper beside `proof_render_ref()`, say `proof_render_diff_ref(array $run): string`:

- the run has a PR and `proof_github_url($run, 'pull/<P>/files')` gives a URL → `proof_render_ref($url, 'Files
  changed')`, so it carries `target="_blank" rel="noopener"` like the PR link;
- the run has no PR (`empty($run['pr'])`), or no `nameWithOwner` to build a URL from → `''`, which the meta line's
  existing `array_filter` drops, so the separators stay right.

It is never plain text: without a URL, the words *Files changed* would point at nothing (Assumption 4). The PR
number is cast with `(int)` as the existing PR URL is.

The index gets no *Files changed* link (Assumption 5).

### When existing pages get it

A run page carries both from its next render: any filing (`proof_store_file()`) or amendment
(`proof_store_amend()`: `proof_cli.php status`, `run_cost_cli.php`, the prune pass). The index is re-rendered on
every filing, so its PR column switches at the next filing in the store. No backfill pass (the issue: older pages
need not be rewritten).

### The docs

engine.md §The proof store:

- the paragraph that lists what the page opens with gains, after the *← All proofs* sentence: "Every link to GitHub,
  on a run page and in the index, opens a new tab (`target="_blank" rel="noopener"`); the store's own links stay in
  the tab. Next to the PR, *Files changed* links the PR's diff (`/pull/<P>/files`)."
- the `run.json` fields table's row for `repo`, `nameWithOwner`, … says "`nameWithOwner` makes the PR and issue
  references links": it becomes "makes the PR, *Files changed* and issue references links".

`SKILL.md` *Visual proof* is unchanged: link behaviour is a detail of the page.

## Testing

Pest, in `skills/pipeline/checks/tests/ProofRenderTest.php`, test first. New cases:

- **every GitHub link on a run page opens a new tab, every store link stays**: render
  `proof_fixture_run(['issue' => 919])`; for every `<a …>` opening tag in the HTML (a `preg_match_all` over
  `/<a [^>]*>/`), a tag whose `href` starts with `https://github.com/` contains `target="_blank" rel="noopener"`, and
  any other tag contains no `target=`. The case also asserts it found at least four tags (PR, Files changed, issue,
  `← All proofs`), so an empty match cannot pass it.
- **the same over the index**: `proof_render_index()` over one run with a PR; at least two tags (PR column, title
  link), same rule.
- **the run page links the PR's diff**: for `proof_fixture_run(['pr' => 967, 'prState' => 'MERGED'])` the HTML
  contains `<a href="https://github.com/IT4WEBBV/ViewieMedia/pull/967" target="_blank" rel="noopener">#967 (MERGED)</a> · <a href="https://github.com/IT4WEBBV/ViewieMedia/pull/967/files" target="_blank" rel="noopener">Files changed</a>`
  (the PR reference, the separator, then the diff: pins the position).
- **no diff link without a PR**: `proof_fixture_run(['pr' => null])` contains neither `Files changed` nor `/files`.
- **no diff link without a repo to link into**: `proof_fixture_run(['nameWithOwner' => null])` contains no
  `Files changed` (the PR stays plain `#412 (OPEN)`).

Existing cases whose exact strings change, updated in the same task (they pin today's `<a href="…">` with no
`target`):

- *links the PR reference to GitHub with an absolute URL* (`…/pull/967">#967 (MERGED)</a>`);
- *links the issue the run is for, from the payload field* (`…/issues/919">issue #919</a>`);
- *links the PR column of the index to the PR on GitHub* (`<td data-sort="412"><a href="…/pull/412">#412 OPEN</a></td>`);
- the index row case asserting the whole `Deploy/pr-5-logs` row (`<td data-sort="5"><a href="…/pull/5">#5 OPEN</a></td>`).

Cases that keep passing unchanged and so guard the store links: the `← All proofs` cases (exact
`<nav class="back" aria-label="Proof store"><a href="../../index.html">← All proofs</a></nav>`), the title-link
cases (`<a href="Deploy/pr-5-logs/index.html">…`), *keeps references as plain text when the run names no repo*
(`not->toContain('<a href="https://github.com')` still holds), and the derived-issue case (substring of the URL).

**In the browser**, in `implement`. The Playwright MCP refuses `file:` URLs, so, as for #153: a copy of
`~/GitProjects/_proofs` in a temp dir, one run with a PR in it re-rendered by the new renderer
(`proof_store_amend($page, fn ($run) => $run)` from a `php -r` against the copy, which writes only the copy), the
copy served with `php -S 127.0.0.1:<port> -t <copy>`. On that page: click the PR link, then *Files changed*; each
opens a second tab (`browser_tabs` lists it, at `…/pull/<P>` and `…/pull/<P>/files`) and the first tab is still the
proof page. Click *← All proofs*: it navigates in the same tab, and the tab count does not grow. On the index
(re-rendered in the copy by the same filing), click the re-rendered row's PR link: a new tab, the index stays.
`target="_blank"` behaves the same over `file://` as over `http://` (it is not origin-dependent); the issue's Chrome
over `file://` check is the owner's to repeat on a live page after the merge. The real store is never written.

## Done when

- Every GitHub link `proof_render_ref()` writes carries `target="_blank" rel="noopener"`; `← All proofs` and the
  index's title links carry no `target`.
- A run page with a PR and a `nameWithOwner` shows *Files changed* after the PR reference, linking
  `https://github.com/<nameWithOwner>/pull/<P>/files` in a new tab; without a PR or without `nameWithOwner` it shows
  none.
- The new and updated tests pass, and the suite is green.
- In the browser, over a served copy of the store, the PR and *Files changed* links open new tabs and the proof page
  stays; *← All proofs* stays in the tab.
- engine.md §The proof store names both.

## Assumptions

Each question the brainstorm would have asked the owner, and the answer assumed.

1. **`rel="noopener"` alone, or `noopener noreferrer`?** `noopener`, as the issue names. GitHub gains nothing from a
   referrer of `file://` or `127.0.0.1`, but the issue's attribute is the spec; browsers already imply `noopener` for
   `_blank`, and spelling it out costs nothing.
2. **Is every GitHub link really one of the three?** Yes: `proof_render_ref()` has exactly three call sites, all with
   a `proof_github_url()` URL, and the renderer writes no other `<a` except the two store links. Prose fields
   (`problem`, `solution`, `explainer`, ledger) are escaped text, never linkified, so a GitHub URL inside them is not
   a link at all and is out of scope.
3. **Rename `proof_render_ref()` to something like `proof_render_github_ref()`?** No. Its only callers pass GitHub
   URLs, the docblock says so, and sibling runs (#146 and others in this batch) may edit `proof_render.php`: keeping
   the name keeps their call sites merging cleanly. The structural test (every `https://github.com/` href carries the
   target) guards the rule whatever the function is called.
4. **Plain-text *Files changed* when there is a PR but no URL, as the PR reference does?** No. The PR reference names
   something (`#412 (OPEN)`) even without a link; *Files changed* without a link names nothing, so it is left out.
5. **Does the index's PR column get a *Files changed* link too?** No. The issue asks it of the run page ("A run page
   links the diff directly"); the index row is already dense, and its PR link now opens a new tab, one click from the
   diff.
6. **Where on the run page?** In the meta line, directly after the PR reference, with the line's own ` · `
   separator: "next to the PR link", and nothing about the page's layout changes, so no style is added and the
   390 px layout is the meta line's as today (it already wraps).
7. **`/files` or GitHub's newer `/changes`?** `/files`, as the issue names; GitHub serves it for every PR.
8. **What about an index tab left open across the change?** Its rows are replaced from `status.js` only when a
   row's hash changes, and that hash is over the run's key and `run.json`, not over the rendered HTML
   (`proof_index_row_hash()`). So an index left open keeps same-tab PR links on unchanged rows until it is reloaded
   or the row's run is filed again. Accepted: the issue lets older pages stay as they are, and a reload, or the next
   filing of that run, brings the new link. The hash is not changed for this.
9. **Does any page script intercept link clicks?** No: the copy handler acts on `[data-copy]`, the zoom handler on
   `.shot` and `#zoom`, the index's dot handler on `button.dot`, the sort handler on header buttons. A click on a
   GitHub link is an ordinary navigation, which `target` sends to a new tab.
10. **Does the Proofs app change anything?** No: `Proofs.applescript` opens the index in the default browser, where
    `target="_blank"` opens a tab as anywhere else.

## Relation to other work

- **#153** (merged) added `← All proofs`; this spec keeps it a same-tab store link and adds a test that pins that.
- **#154** and **#160** (closed) built the polling index and its rows in `status.js`; this spec relies on those rows
  being `proof_render_index_row()`'s text and does not touch the scripts or the row hash.
- **#146** (open questions with a kind; in flight, no PR yet) may change `proof_render.php` around the open questions
  section; this spec touches `proof_render_ref()`, the meta line in `proof_render_run()` and engine.md §The proof
  store's layout paragraph and fields table. Whichever lands second merges (engine.md §Catching up with the base).
  #150 and #168 touch neither file's parts named here.

## What was read

The issue; `proof_render.php` (`proof_github_url()`, `proof_render_ref()`, `proof_issue_number()`,
`proof_render_run()`, `proof_render_index()`, `proof_render_index_row()`, `proof_index_row_hash()`, every `<a`, `href`
and click handler in the file); `ProofRenderTest.php` (its fixture and every case asserting a GitHub or store link);
`tests/Pest.php` (`proof_test_page()`); engine.md §The proof store (the page's layout paragraph and the `run.json`
fields table); the #153 spec (format, browser-check recipe); `skills/pipeline/apps/Proofs.applescript`'s design
(how the index is opened); the titles and states of #146, #150, #154, #160 and #168.
