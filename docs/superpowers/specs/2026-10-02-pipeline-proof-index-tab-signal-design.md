# The open proof index shows what changed in its tab, and no run page opens by itself — design

**Design size:** Architectural

**Date:** 2026-10-02
**Issue:** IT4WEBBV/LaravelClaudeMd#154 (depends on #142, merged)
**Canonical home:** `skills/pipeline/checks/proof_render.php` (`proof_render_index()`, `proof_render_index_row()`,
`proof_render_index_script()`, a new `proof_render_status_js()`), `skills/pipeline/checks/proof_store.php` (a new
`proof_store_index()` behind every index write), `skills/pipeline/checks/proof_cli.php` (`prune`, `open`),
`skills/pipeline/checks/proof.php` (`proof_open_argv()`), the auto-open call sites (`brief.php`, `dispatch_cli.php`,
`workflow/pipeline-autoflow.js`), the tests beside them in `skills/pipeline/checks/tests/`, pipeline `SKILL.md` and
`references/engine.md` §The proof store, and orchestrate `SKILL.md` and `references/commands.md`.
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. The issue body is the approved scope and is
not re-litigated here. Every question the brainstorm would have asked is answered in *Assumptions*, so
`/critique plan` audits exactly those. Nothing of the design was built or run; what it relies on was read (see
*What was read*), and two browser facts were probed (*Approaches*).

## Problem

A run opens its own proof page in a new browser tab at its end: the finish step's last action is
`proof_cli.php open <page>` (`brief.php`'s `review-pr:resolve` brief; in `autoflow` the workflow script's prompt
line 4 for that step, `pipeline-autoflow.js:135`), and the session that holds a halted run after `handoff` opens it
too (engine.md §The proof store, *The finished page opens itself*; §Failure policy; pipeline `SKILL.md` step 5;
orchestrate commands §Proof page). With several runs a day that is a tab per run.

Since #142 and #151 the store index (`~/GitProjects/_proofs/index.html`) carries every run's status and a per-browser
New/Updated marker, so one index tab, left open, can carry that signal instead. Today it cannot: it is a static page
rendered at each store write, so an open tab never learns that the store changed, and nothing about it shows in the
tab strip.

## What the owner asked for (the issue)

1. **No page opens automatically.** `proof_cli.php open <page>` stays for opening one by hand; the report names the
   run's page. The index is opened by hand (#152's Proofs app) and left open.
2. **`status.js` beside `index.html`**, written on every write and prune, setting a global with each run's key,
   status and revision.
3. **The open index polls it** about every 30 seconds by appending `<script src="status.js?t=<now>">`, and compares
   it with the seen revisions in `localStorage`.
4. **The tab shows it:** a favicon dot as an SVG data URI (red: a run halted; green: a PR ready to merge; blue: a run
   new or updated; none when nothing is unseen), the count in the title (`(2) Proofs`), and the changed rows
   updated in place without a reload or a lost scroll position.
5. **Opening a run, or the index with the tab focused**, clears its part of the dot.
6. **Limits, stated:** the signal exists only while the index tab is open; Chrome throttles timers in background
   tabs, so a change can take a minute or so to show.

## Approaches

### How the open index learns that the store changed

1. **Poll a `status.js` through a script tag (chosen, as the issue says).** A `<script>` element loads over
   `file://` in Chrome where `fetch()` does not, and a query string after the file name is ignored by the file
   loader, so `?t=<now>` makes every poll a fresh load.
   Probed: a dynamically appended `<script src="status.js?t=…">` loads over `file://` in Chrome and its global is
   readable in `onload`: yes, the page printed `loaded:42` (headless Chrome `--dump-dom` on a temporary
   `index.html` + `status.js`, then removed).
   Probed: `fetch('status.js')` over `file://` in Chrome: no, it rejects with a `TypeError` (the same headless
   Chrome probe).
2. **`fetch()` of `run.json` files or of `index.html` itself.** Refused over `file://` (the probe above). Rejected.
3. **Reload the whole index on a timer** (`<meta http-equiv="refresh">` or `location.reload()`). Loses the search
   text, the clicked sort and, depending on the moment, the scroll position, which the issue rules out. Rejected for
   a store with runs; kept only for an empty store, which has nothing to lose (*The index script*).

### What `status.js` carries per run

1. **The key, status and revision the issue names, plus the row as the index renders it and a hash of the run
   (chosen).** The script replaces a row whose hash changed with the rendered row, inserts a row for a run the
   page does not have, and removes a row for a run the store no longer has. Every column (status and reason,
   title, shots, time, cost, Updated, summary) stays rendered by the one PHP function that renders it today
   (`proof_render_index_row()`), where it is tested; a new run appears without a reload.
2. **Only key, status and revision, with the script patching the status cell.** The script would need the labels,
   the pill classes, the halted reason, the attention group and the finished flag, duplicating `ProofRunStatus` in
   JavaScript; a re-filed run's title, shots and cost would stay stale; a new run could not appear without a
   reload. Rejected.

### Where `status.js` is written

1. **One `proof_store_index(string $root): ?string` that writes `index.html` and `status.js` from one scan
   (chosen).** Today three places render the index, each with its own `file_put_contents()`:
   `proof_store_file()` (a write, and `dispatch_cli.php handoff`), `proof_store_amend()` (`status`, a status the
   dispatcher writes, the prune pass's correction, `run_cost_cli.php`'s cost) and `proof_cli_prune()`. Each calls the
   helper instead, so no write path can leave `status.js` behind the index.
2. **A `status.js` write added at each of the three sites.** Three places to keep in step for a file the tab
   depends on. Rejected.

## Design

### `status.js` — `proof_render.php`

`proof_render_status_js(array $runs): string` renders, from the same `{dir, run}` entries `proof_render_index()`
takes and in the index's attention order (`proof_index_order()`):

```js
window.proofStatus = {"runs":[{"key":"Deploy/pr-5-logs","status":"ready","revision":3,"hash":"…","row":"<tr …>…</tr>"}, …]};
```

- `key`: `<repo>/<run>`, the last two segments of the run's directory, as the row's `data-run` and the run page's
  `seen:` key already are. It moves out of `proof_render_index_row()` into `proof_index_key(array $entry): string`,
  which both use.
- `status`: `ProofRunStatus::of($run)->value`. `revision`: the stored revision as an int, `null` for a run filed
  before revisions existed (which gets no marker, as today).
- `hash`: `proof_index_row_hash(array $entry): string`, the first 12 hex digits of `sha1` over the key and
  `proof_run_json($run)`: any change to the stored run (a filing, a status, a cost, a prune correction) changes it.
  The row carries it as `data-hash`.
- `row`: `proof_render_index_row($entry)`.
- Encoded with `json_encode()`'s default escaping (` ` and ` ` escaped, `/` escaped), so the file is valid
  JavaScript whatever a title or summary holds. It is a separate file, not inline in the page, so a `</script>` in a
  value cannot end anything.

`proof_render_index_row(array $entry): string` loses its `int $number` parameter: the summary's copy target
becomes `summary-<the first 8 hex digits of sha1(key)>`, stable across polls and unique per run, so a row inserted
or replaced by the script never collides with one rendered by PHP. The row gains `data-hash` as its last data
attribute.

`proof_render_status_js()` lives beside `proof_render_index()` because it renders the same rows; it reads no file.

### Writing it — `proof_store.php` and `proof_cli.php`

`proof_store_index(string $root): ?string` scans the store once (`proof_scan_runs($root)`), writes
`{$root}/index.html` (`proof_render_index()`) and then `{$root}/status.js` (`proof_render_status_js()`), and returns
null, or `cannot write <file>`. `status.js` is written to `status.js.tmp` and renamed over `status.js`, so a poll
never loads a half-written file; the index keeps its plain write (it is read only on a load the owner starts).

- `proof_store_file()` calls it after the page; a problem goes to stderr as `proof: <problem>` and the filing stands
  (today the index write is unchecked).
- `proof_store_amend()` writes `run.json` and the page as today and returns the helper's problem.
- `proof_cli_prune()` calls it in place of its `file_put_contents($root . '/index.html', …)`, logging a problem to
  stderr; `proof: pruned <n> run(s)` stays its stdout.

`proof_store_amend()` keeps its root as `dirname($page, 3)`, never `proof_root()`, so a test store and the real one
never mix; `status.js` follows the index it sits beside.

### The index page — `proof_render_index()`

- `<title>Proofs</title>` (was `Pipeline proof store`); the `<h1>` keeps `Pipeline proof store`. The script prefixes
  the count: `(2) Proofs`.
- In `<head>`: `<link rel="icon" id="favicon" href="<none>" data-none="…" data-halted="…" data-ready="…"
  data-unread="…">`. The four icons are SVG data URIs from `proof_index_icons(): array` (keys `none`, `halted`,
  `ready`, `unread`), defined once in PHP and only picked by the script: a 16×16 `viewBox` with one circle, filled
  `#dc2626` (halted, the page's `--accent`), `#16a34a` (ready to merge, `--after`) or `#2563eb` (new or updated,
  `--ready`), and for `none` an unfilled `#71717a` ring, so a pinned tab always has an icon and never keeps a stale
  dot.
- The toggle's count is wrapped, `Show merged and closed (<span id="finished-count">n</span>)`, so the script can
  recount it after rows change.
- The script is rendered for an empty store too: *No runs recorded.* and no table, with the copy code and the
  index script, which then only polls (below).

### The index script — `proof_render_index_script()`

Everything #142 and #151 put in it stays: the local times, the New/Updated markers, the seen-Ready rank, the
attention order, the clicked sort, the filters, the toggle, the search and their remembered values. It gains:

- **`unread(row)`**, returning `'New'`, `'Updated'` or `''`: today's rule, lifted out of `mark()`, which now shows
  `unread(row)` and sets the rank from it. It is the one place #160 replaces with the owner's settled rule (a status
  turning Halted or Ready makes an opened run unread again); `status.js` entries are objects, so #160 adds its field
  beside `status` and `revision`.
- **`tab()`**: over every row that is not finished (`data-finished="0"`), whatever the filters and the search show,
  the unread ones are counted. The title becomes `(<count>) Proofs`, or `Proofs` at 0. The favicon's `href` becomes
  the link's `data-halted` when an unread row's `data-status` is `halted`, else `data-ready` when one is `ready`,
  else `data-unread` when there is any, else `data-none`. Without `localStorage` nothing is unread (as today no row
  is marked), so the tab shows `Proofs` and the ring.
- **`poll()`**: sets `window.proofStatus = undefined`, appends `<script src="status.js?t=<Date.now()>">` to `<head>`;
  its `onload` removes the element and hands `window.proofStatus` to `apply()` when it is an object with a `runs`
  array; its `onerror` removes the element and changes nothing (a store being pruned or written keeps the page as
  it is until the next poll).
- **`apply(status)`**: for each entry, the row with that `data-run` is replaced when its `data-hash` differs from
  the entry's `hash`, and a row is inserted when there is none (both parsed from `row` through a `<template>`
  element, their `<time>` elements localised); a row whose key the entry list no longer has is removed. Then every
  row is marked (re-reading `seen:` from `localStorage`), the finished count recounted, the rows ordered (the
  attention order, or the clicked column) and filtered, and `tab()` runs. The `rows` array the sort and the filters
  use is kept in step. The page is never reloaded and the scroll position is the browser's own: only rows move.
- **When it polls**: every 30 seconds (`setInterval`), at once when the tab becomes visible (`visibilitychange`) or
  the window gains focus (`focus`), and on a `pageshow` from the back/forward cache after `refresh()`. Since every
  poll re-marks every row, a run opened in another tab, or opened from this one and come back from, clears from the
  dot when the index is looked at, without waiting 30 seconds.
- `refresh()` (on `DOMContentLoaded` and a persisted `pageshow`) also runs `tab()`, so the tab is right before the
  first poll.
- **With no table** (an empty store), only `poll()` runs: when `status.js` carries any run, the page reloads itself
  once, to render the table it does not have.
- The copy code is delegated (`document.addEventListener('click', …)` with `closest('[data-copy]')`), so the copy
  buttons of inserted and replaced rows work unchanged.

### No page opens by itself

- `brief.php`, the `review-pr:resolve` brief: the sentence *After `record`, the last action is `proof_cli.php open`
  on the path `write` printed* goes; in `interactive` the finish step's report names the page path `write` printed.
  The `## Return` line's *only a read-only command your instructions name (`size`, `ui`, the proof page's `open`)*
  loses *the proof page's `open`*.
- `workflow/pipeline-autoflow.js`: the `review-pr:resolve` prompt's line 4 (`Run the proof page's open as
  PIPELINE_NO_OPEN=… proof_cli.php open …`) goes.
- `dispatch_cli.php launch`: the `noOpen` key goes from its `start` JSON; nothing reads it after the line above.
- `proof_open_argv()` no longer reads `PIPELINE_NO_OPEN`: with no automatic caller left, the variable has nothing to
  suppress. `PIPELINE_OPEN_CMD` stays: it is the portability seam and the one the tests stub, so no suite opens a
  browser. `proof_cli.php open <page>` keeps every other behaviour (an argv array, never a shell; exit 0 on every
  path), and its docblocks say it is for opening a page by hand.
- The invoking session names the page: pipeline `SKILL.md` step 5 (*A halt after `handoff`*: the reason into the PR
  body, and the report names the page) and step 6 (the report names `proof` from `finish`'s `done`, the page
  `run_cost_cli.php` already takes); orchestrate `SKILL.md` step 5 and commands §Proof page (a ready PR is announced
  with its page path, nothing opened); the launch commands lose `PIPELINE_NO_OPEN=…` (pipeline `SKILL.md` step 2,
  engine.md §`autoflow`, orchestrate commands §Launch and the *commits wanted* block).

### The docs

- engine.md §The proof store: *The finished page opens itself — once, at the end*, *A run with no page opens
  nothing*, *Opening is cosmetic…* and *Concurrent finishes are left undamped* become one short paragraph, **No page
  opens by itself**: the report names the page; `proof_cli.php open <page>` opens one by hand, cosmetic and exit 0
  on every path; `PIPELINE_OPEN_CMD` replaces the platform opener. The index paragraph gains **The open index tab**:
  `status.js`, the poll, the dot, the count, the in-place rows, when the dot clears, and the two limits from the
  issue. engine.md §Stations (the `review-pr` row's *opens the page last*), §Failure policy (*opens the proof page
  once*) and the `launch` lines of §`autoflow` follow.
- pipeline `SKILL.md` *Visual proof*: *The finished page opens in the browser once… `PIPELINE_NO_OPEN=1`
  suppresses that* becomes: no page opens by itself; the open index tab shows what changed (engine.md §The proof
  store).
- orchestrate commands: the halt paragraph's *No proof page opens on a halt in an unattended batch, unlike pipeline
  `SKILL.md`'s attended "opened once"* goes, since neither opens one.
- Older specs that describe opening (2026-09-16, 09-22, 09-23, 09-28, 10-02 *links to index*) are records of their
  runs and stay as they are; engine.md is the canonical home.

## Testing

Pest, beside the existing tests in `skills/pipeline/checks/tests/`. Each fails today because the file, the field,
the markup or the removal does not exist yet.

**`ProofRenderTest.php`**
- `proof_render_status_js()` assigns `window.proofStatus`, and its object (the text between `= ` and the final `;`,
  through `json_decode`) lists each run in the attention order with `key`, `status`, `revision` (null for a run
  without one), `hash` and `row`; the row equals `proof_render_index_row()` for that entry and holds `data-hash`
  with the same hash.
- The hash changes when the run's status, revision or cost changes, and not when nothing does.
- A title or summary holding `</script>`, a quote, ` ` stays one valid string: the file passes `node --check`.
- The index has `<title>Proofs</title>`, the favicon link with its four data URIs (each an `image/svg+xml` data URI;
  `halted` holds `#dc2626`, `ready` `#16a34a`, `unread` `#2563eb`), and the `finished-count` span.
- The row's copy target is `summary-<8 hex>` from the key, the same on every render (the existing row assertion,
  `summary-1`, moves to it).
- The index script carries the poll and the tab: `status.js?t=`, `30000`, `visibilitychange`, `focus`,
  `document.title`, `favicon`, `data-hash`, `<template>`/`template`, `unread(`, `location.reload`; still passes
  `node --check`.
- An empty store's index carries the poll script and the favicon link, and still says *No runs recorded.* (the
  existing *renders an empty store* test, which asserts no `<script`, changes with it).

**`ProofWriteTest.php`, `ProofStatusTest.php`, `RunCostTest.php` (the store paths)**
- `proof_cli.php write` leaves `status.js` beside `index.html` naming the run, its status and revision 1; a second
  write, revision 2.
- `proof_cli.php status <page> halted --reason …` rewrites `status.js` with `halted` and the revision unchanged, in
  the store the page is in.
- The prune pass drops a pruned run from `status.js`, and its `gh` correction shows in it.
- `run_cost_cli.php` filing a cost changes the run's hash in `status.js`.
- No `status.js.tmp` is left behind.

**`ProofOpenTest.php`, `DispatchCliTest.php`, `BriefTest.php`**
- The two `PIPELINE_NO_OPEN` cases go; `PIPELINE_NO_OPEN=1` set while `PIPELINE_OPEN_CMD` points at the recorder
  still opens (the recorder sees the page): the variable is ignored.
- `launch`'s `start` JSON has no `noOpen` key (the `marks noOpen…` case is replaced by that).
- The `review-pr:resolve` briefs, `interactive` and `autoflow`, no longer contain `proof_cli.php open`; the five
  existing assertions that look for it change with them, and no brief's `## Return` mentions `open`.
- `AutoflowScriptTest.php`: the `review-pr:resolve` step prompt has no `proof_cli.php open` line.

**In Chrome over `file://`** (the issue's Verify), on a copy of the real store under `PIPELINE_PROOF_ROOT`: the index
open in a background tab; a run filed again with `proof_cli.php write` turns the dot blue and the title to `(1)
Proofs` within a couple of minutes, its row updated in place; opening that run and returning clears both; a run
never opened that is set `halted` with `proof_cli.php status` shows red, one set `ready` green.

## Done when

- Every store write path (`write`, `handoff`'s filing, `status`, the dispatcher's status, the prune pass, a cost)
  leaves `status.js` beside `index.html` with each run's key, status, revision, hash and row.
- The open index polls it every ~30 s and on focus, updates, inserts and removes rows in place, and shows the unread
  count in its title and a red, green, blue or neutral favicon by the rules above.
- No step, brief, workflow prompt or skill text opens a proof page; the reports name it; `proof_cli.php open` still
  opens one by hand.
- The suite is green, and the Chrome check above holds.

## Assumptions

Each question the brainstorm would have asked the owner, and the answer assumed.

1. **What does "or the index with the tab focused" do?** Focusing the index tab re-reads the seen marks at once (a
   poll on `visibilitychange` and `focus`), so a run opened elsewhere clears from the dot the moment the index is
   looked at. It does **not** mark every run seen: the owner settled (2026-10-02, #160) that a run is unread until
   it is opened, and that the tab's dot and count read the same rule as the rows; a focus that cleared the dot while
   the rows stayed bold would break that.
2. **Which runs does the tab count?** Every unread run that is not merged or closed, whatever the repo filter, the
   status filter and the search show. Finished runs need nothing and are hidden by default; counting them would put
   every merged run this browser never opened into the title (the real store has 59 run directories). A filter
   hides rows from the view, not from the signal, so a halted run in another repo still turns the dot red.
3. **Which colour wins when several apply?** Red over green over blue, by what needs the owner most: a halted run,
   then a PR ready to merge, then anything new or updated. Under today's rule a status change does not make an
   opened run unread, so red and green show for unread runs that are halted or ready; #160 makes the status change
   itself unread.
4. **"None when nothing is unseen": no icon at all?** A neutral grey ring. Removing the `<link>` leaves Chrome
   showing the last icon it had, and a pinned tab without an icon is hard to find.
5. **What is the page's title?** `Proofs`, as the issue's example and #152's app name; the heading stays
   *Pipeline proof store*.
6. **Do rows move while the owner looks?** Yes, as on a reload: after a poll the rows are put back in the attention
   order (or the clicked column's), so a run that halts rises to the top. Only rows move; the page is not reloaded.
   The poll changes nothing when nothing changed.
7. **What about a run that appears or disappears between polls?** A new run's row is inserted; a pruned run's row
   is removed. The repo filter's options are not re-rendered: a run from a repo the page did not list shows under
   *All repos*, and its repo is offered after the next reload.
8. **Does `PIPELINE_NO_OPEN` stay?** No. It suppressed the automatic open, which is gone; a by-hand
   `proof_cli.php open` that silently opened nothing because of a variable set for unattended runs would surprise.
   `PIPELINE_OPEN_CMD` stays. A shell or settings file that still sets `PIPELINE_NO_OPEN` is unaffected (nothing
   reads it).
9. **Should the run page poll too?** No. The issue makes the index the one tab that carries the signal; a run page
   is opened to read and closed.
10. **How often, exactly?** Every 30 seconds while the page is open, as the issue says; Chrome's throttling of
    background tabs stretches that to about a minute, which the docs state as a limit.

Added by the plan step (`docs/superpowers/plans/2026-10-02-pipeline-proof-index-tab-signal.md`), where the plan needed
an answer this design did not give:

11. **What does a store write do when it cannot write `index.html` or `status.js`?** `proof_store_index()` returns
    `cannot write <file>`; `proof_store_file()` and the prune pass put it on stderr as `proof: <problem>` and their
    filing, pruning and stdout stand, and `proof_store_amend()` returns it as its problem. A store root that does not
    exist (a `prune` before any run was filed) is such a case: today PHP prints a warning there, after this one
    stderr line. The helper creates no directory.
12. **How does the script change the favicon?** It replaces the `<link>` with a clone carrying the new `href`, and
    only when the icon changes. A browser may keep the icon it has when only an existing link's `href` changes; a new
    element is the form every browser repaints.
13. **What does `status.js` do with a byte that is not UTF-8?** It is substituted (`JSON_INVALID_UTF8_SUBSTITUTE`
    beside `json_encode()`'s default escaping), so a store write never fails over one byte in a title. `run.json`
    is written by `json_encode()` too, so this is a guard, not a case the store produces.
14. **What does the open index show when every run it showed is pruned?** The table stays, empty, with *No runs
    match.*; the next reload renders *No runs recorded.* The prune pass never removes a run with an open PR, so this
    is rare, and a reload is the owner's.
15. **How is the Chrome check run over `file://` when the Playwright MCP refuses `file:` URLs?** The `file://`
    mechanism (a stale index picking up a newer `status.js`, an empty store's index reloading itself) is proved with
    headless Chrome over `file://` (`--virtual-time-budget`, `--dump-dom`), as the design's probes were. The
    interactions (the dot, the count, rows in place, clearing on open, a background tab) run in the Playwright MCP
    over `file://` when it allows that, else over a local `php -S` server, where the poll is the same script tag and
    the seen marks live in that origin.

## Relation to other work

- **#142, #151** (merged): the seen marks, the markers, the attention order, the filters and the search this builds
  on; nothing of them changes behaviour.
- **#160** (after this run): replaces `unread()` with the owner's rule and adds the field it needs to each
  `status.js` entry; `tab()` reads `unread()` and needs no change.
- **#152** (in flight, no PR yet): the Proofs app that opens the index this page now expects to be left open. No
  file overlap expected; the app is under `apps/` or `skills/pipeline/` and `hooks/git-freshness.sh`.

## What was read

`proof_store.php` (all), `proof_cli.php` (all), `proof.php` (`ProofRunStatus`, `proof_root()`, `proof_write_run()`,
`proof_run_json()`, `proof_scan_runs()`, `proof_open_argv()`), `proof_render.php` (`proof_render_styles()`'s
palette, the seen and copy scripts, `proof_render_status()`, `proof_render_index()` through
`proof_render_index_script()`), `dispatch_cli.php` (`launch`'s `start` JSON), `brief.php` (`review-pr:resolve`, the
`## Return` line), `workflow/pipeline-autoflow.js` (`stepPrompt()`), the tests that name `open`, `noOpen` or the
index (`ProofOpenTest`, `DispatchCliTest`, `BriefTest`, `ProofRenderTest`), pipeline `SKILL.md`, engine.md §The proof
store, §Stations and §Failure policy, orchestrate `SKILL.md` step 5 and `references/commands.md` §Launch, §Finish
and §Proof page, and issues #154, #160 and #152.
