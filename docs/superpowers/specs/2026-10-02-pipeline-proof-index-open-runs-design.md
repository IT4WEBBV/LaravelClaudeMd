# The proof index shows open runs by default, sorts, filters and searches, and prunes sooner — design

**Design size:** Architectural

**Date:** 2026-10-02
**Issue:** IT4WEBBV/LaravelClaudeMd#151 (depends on #142, merged)
**Canonical home:** `skills/pipeline/checks/proof_render.php` (`proof_render_index()`, `proof_render_index_row()`,
`proof_render_index_filter()`, `proof_render_index_script()`, `proof_render_styles()`),
`skills/pipeline/checks/proof.php` (`ProofRunStatus`, `proof_should_prune()`), the tests beside them in
`skills/pipeline/checks/tests/`, pipeline `references/engine.md` §The proof store, and a note in
`docs/superpowers/specs/2026-08-25-pipeline-visual-proof-store-design.md` §7 and §8.
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. The issue body is the approved scope and
is not re-litigated here. Every question the brainstorm would have asked is answered in *Assumptions*, so
`/critique plan` audits exactly those. Nothing was built or run; what the design relies on was read (see *What was
read*), and one environment fact was probed (Assumption 9).

## Problem

#142 gave the store index (`~/GitProjects/_proofs/index.html`) a status, time and cost per run, a repo filter and
New/Updated markers. Over the real store (53 runs on 2026-10-02) most rows are merged runs waiting out a 14-day
grace, so the runs that need a look drown among finished ones; the Updated column shows only a date
(`substr($run['updatedAt'], 0, 10)`), and nothing can be searched or sorted. Retention keeps finished runs two weeks
and never removes a run that opened no PR: the index flags it `no PR — prune manually` and nobody prunes it.

## What the owner asked for (the issue)

1. **Open runs by default**: merged and closed rows hidden until a toggle shows them; the toggle remembered in
   `localStorage` like the repo filter.
2. **A status filter** beside the repo filter: running, halted, ready, merged, closed.
3. **Search**: free text over title, PR number, branch and client summary.
4. **Sorting**: a click on a column header sorts by it; the default stays #142's attention order.
5. **Time in Updated**: `01-10 20:29`, the full timestamp as a tooltip, never a relative time.
6. **Retention, sooner**: merged or closed runs pruned 7 days after their last update instead of 14; runs without a
   PR pruned 14 days after their last update instead of flagged. This reverses the 2026-08-25 spec §8 (*Runs with no
   PR are never auto-pruned*); engine.md §The proof store and that spec get a note.

## Approaches

### The index's filtering, search and sorting

1. **In the index's own script, over data the PHP renders into each row (chosen).** The index stays one static
   file that opens over `file://` (the store has no server), PHP stays the pure renderer it is, and the script #142
   already runs on `DOMContentLoaded` and `pageshow` gains the visibility, sort and time parts. Every value the
   script compares (status, finished, search text, sort keys) is computed in PHP, where it is tested; the script
   only compares strings and numbers.
2. **Several static pages** (`index.html` open runs, `all.html` every run). Covers the toggle only: no search, no
   sort, and the status and repo filters would multiply the pages. Rejected.
3. **A table library from a CDN** (List.js, DataTables). The page must work offline over `file://`, and every
   asset is inline by contract (`proof_render.php`'s header). Rejected; what is needed is about a hundred lines.

### Which state "merged or closed" is, for pruning

1. **The run's status, `ProofRunStatus::of($run)` (chosen).** It is what the index hides by default, so the rows
   the toggle hides are exactly the runs on the 7-day clock. The prune pass corrects the status from `gh` before
   it asks (`proof_cli_refresh()` sets `prState` and `status` together), so where `gh` answers the two agree; where
   it cannot, the stored status stands, and a `merged` written by the merge watch (§After the merge) is a session
   that saw the merge. A run filed before statuses existed reads as its `prState` says (`of()`), so the existing
   `prState`-only fixtures keep their answers.
2. **`prState` alone**, as today. Then a run the watch marked merged while `gh` could not answer is shown as
   finished (hidden) and not on the 7-day clock: the index and the prune pass would disagree. Rejected.

### What clock the 7 days run on

1. **`updatedAt`, the last filing (chosen)**, as the issue says ("7 days after their last update") and as the
   14-day rule already measures. A status or a cost amendment leaves `updatedAt` as it was (engine.md §The proof
   store), so a run that sat ready for review more than 7 days without a filing is pruned on the first prune pass
   after its merge. Recorded as Assumption 1 for the review to weigh.
2. **A `finishedAt` the store sets when the status turns merged or closed.** Gives every merged run its full 7
   days after the merge, at the price of a new store key and a write in `proof_cli_refresh()` and
   `proof_store_status()`. Not what the issue asks; named in Assumption 1 as the alternative.

## Design

### Retention — `proof.php`

`ProofRunStatus` gains `finished(): bool`, true for Merged and Closed. It is the one definition of "finished" the
index (hidden by default, the toggle's count) and the prune pass share.

`proof_should_prune(array $run, string $now): bool` stays pure (no filesystem, no `gh`, no clock) and loses its
`$graceDays` parameter (its one caller, `proof_cli_prune()`, passes none). Two named constants replace the 14:

```php
/** Days a merged or closed run is kept after its last filing. */
const PROOF_FINISHED_RETENTION_DAYS = 7;

/** Days a run that opened no PR is kept after its last filing. */
const PROOF_NO_PR_RETENTION_DAYS = 14;
```

The rule, in this order:

| the run | pruned when `updatedAt` is older than |
|---|---|
| no `pr` (missing, null, empty) | `PROOF_NO_PR_RETENTION_DAYS`, whatever its status |
| a `pr`, and `ProofRunStatus::of($run)->finished()` | `PROOF_FINISHED_RETENTION_DAYS` |
| a `pr`, any other status | never |

An unparseable `updatedAt` or `$now` still answers "do not prune". The docblock's two rules are rewritten to these:
the grace period stays (a run merged this morning is still worth a look this afternoon), and a run with no PR is
now pruned after two weeks without a filing, because flagging it made nobody prune it (#151).

`proof_cli_prune()` is unchanged: it refreshes each run from `gh` (a no-PR run is returned as stored, since
`proof_cli_pr_view()` has nothing to ask) and deletes what the predicate says.

### The index — `proof_render.php`

**The controls**, one row above the table, replacing `proof_render_index_filter()`'s single label
(`proof_render_index_controls(array $runs): string`):

- **Repo**: the `<select id="repo-filter">` as today (`All repos`, then the repos present, sorted).
- **Status**: `<select id="status-filter">` with `All statuses` (value `""`), then one option per
  `ProofRunStatus::cases()`, value the case's value, text its `label()` (`Running`, `Halted`, `Ready for review`,
  `Merged`, `Closed`). All five always, present in the store or not.
- **Search**: `<input type="search" id="search" placeholder="Title, PR, branch or summary" aria-label="Search runs">`.
- **The toggle**: `<label><input type="checkbox" id="show-finished"> Show merged and closed (<n>)</label>`, `<n>`
  the number of runs whose status is `finished()`, counted in PHP.

They sit in `<div class="controls">`, a wrapping flex row, so at 390 px they stack instead of widening the page.

**The table** sits in `<div class="table-wrap">` (`overflow-x:auto`): its nine columns scroll inside the wrapper
at 390 px, and the page itself gets no horizontal scroll. After the table, `<p id="no-match" class="meta"
hidden>No runs match.</p>`, which the script shows when every row is hidden.

**Each row** gains, beside #142's `data-run`, `data-repo`, `data-group`, `data-updated` and `data-revision`:

- `data-status`: the status value (`ProofRunStatus::of($run)->value`);
- `data-finished`: `1` or `0` (`finished()`);
- `data-search`: what search matches, lower-cased with `mb_strtolower()`, joined by single spaces: the title as
  the index shows it (`proof_run_title()`), `#<pr>` when there is a PR, the `branch`, and the `clientSummary`.
  Escaped by `proof_e()` like every attribute.

**Sortable headers.** Each header but *Summary* is `<th data-sort-type="text|number" data-sort-first="asc|desc"
[class="num"]><button type="button" class="sort">Label</button></th>`; *Summary* stays a plain `<th>`. Each
sortable cell carries its key in `data-sort`, so the script never parses what a cell shows:

| Column | `data-sort` on the cell | type | first click |
|---|---|---|---|
| Status | the attention order: halted 0, ready 1, running 2, merged 3, closed 4 (`ProofRunStatus::order()`) | number | asc |
| Repo | the repo | text | asc |
| PR | the PR number; empty without one | number | desc |
| Run | the title as shown | text | asc |
| Shots | the shot count | number | desc |
| Time | the summed seconds (`proof_cost_totals()`); empty without figures | number | desc |
| Cost | the summed cost; empty without figures | number | desc |
| Updated | `proof_updated_time()` (Unix seconds); empty when it does not parse | number | desc |

The headers come from one list in PHP (label, type, first direction, numeric alignment), which renders the `<th>`
row, so the column order is written once. The cells keep their order in `proof_render_index_row()`.

**Updated** shows `d-m H:i` and the full timestamp as a tooltip:

```html
<td data-sort="1790886553"><time datetime="2026-10-01T20:29:13+00:00" title="2026-10-01T20:29:13+00:00">01-10 20:29</time></td>
```

PHP formats the time in the timestamp's own offset (`DateTimeImmutable` from the stored string, `->format('d-m
H:i')`), never in PHP's default timezone, which is UTC here (Assumption 9). The script rewrites each `<time>` to
the browser's local time (`dd-mm HH:MM`, tooltip `YYYY-MM-DD HH:MM:SS`), so the owner sees Amsterdam time from a
store filed in UTC; without the script the PHP text stands. An `updatedAt` that does not parse renders an empty
cell. No relative time anywhere: the page is only rewritten on a filing.

**The no-PR cell** reads `<span class="reason">no PR</span>` (muted), no longer the red `no PR — prune manually`:
the prune pass now removes such a run.

### The index script — `proof_render_index_script()`

One IIFE, as today, run on `DOMContentLoaded` and on a `pageshow` from the back/forward cache. What it keeps from
#142: the New/Updated markers, the seen-Ready demotion (`rank`), the remembered repo filter, the copy code beside
it. What it adds:

1. **Local times**: each `time[datetime]` in the table gets its local text and tooltip.
2. **Remembered controls**: `proof:repo` (as today), `proof:status` (the status filter's value) and
   `proof:finished` (`1` when the toggle is on) are read on refresh and written on `change`. A stored value that is
   no longer an option is ignored. Without `localStorage` every control still works, unremembered, and the toggle
   starts off.
3. **Visibility**: a row shows when all three hold:
   - the repo filter is empty or equals `data-repo`;
   - the status filter equals `data-status`, or, when it is `All statuses`, the toggle is on or `data-finished` is
     `0`. An explicit `Merged` or `Closed` in the status filter shows those rows whatever the toggle says: the
     narrower, explicit choice wins;
   - every whitespace-separated term of the lower-cased search text occurs in `data-search`.
   `#no-match` shows when no row does. The search box filters on `input`; it is not remembered.
4. **Order**: with no column chosen, #142's order (rank, then `data-updated` newest first). A click on a header's
   button sorts by that column in its first direction; a second click on the same header reverses it; a click on
   another header starts that one in its first direction. Numbers compare as numbers, text with
   `localeCompare(…, {sensitivity: 'base'})`. An empty key sorts last in both directions. Ties keep the attention
   order (the sort runs over the rows in the default order and is stable). The sorted header gets `aria-sort`
   (`ascending` or `descending`), the others lose it; CSS shows `▲` or `▼` after the sorted button. The chosen
   column is not remembered: a reload shows the attention order again. A `pageshow` refresh re-applies whatever
   is chosen.

### Styles — `proof_render_styles()`

`.controls` (flex, wrap, gap, muted label text, the inputs styled as `.filter select` is today, which it replaces),
`.table-wrap { overflow-x:auto; }`, `th button.sort` (inherits font and colour, no border or background, pointer),
and the `aria-sort` arrows. Colours come from the existing tokens, which have their dark values, so light and dark
need no new token.

### The docs

- **engine.md §The proof store**: the *The index* paragraph names the open-runs default and its toggle, the status
  filter, search, header sorting and the `d-m H:i` time, and what each control remembers; a new **Retention**
  paragraph says merged or closed runs are pruned 7 days after their last filing, runs with no PR 14 days after it,
  an open PR never, and that the prune pass runs after every `proof_cli.php write` and on `prune`. The paragraph
  that says a run with no PR keeps its branch slug is unchanged.
- **2026-08-25 spec**: a dated note under §7 (the no-PR flag) and §8 (the 14 days and *never auto-pruned*):
  *Superseded by #151 (2026-10-02): merged or closed runs are pruned 7 days after their last update; a run with no
  PR 14 days after it; the index no longer flags it.* The original text stays as the record.
- `SKILL.md` *Visual proof* names the index without its features: unchanged.

## Testing

Pest, test first, in the files that already hold each concern.

**`ProofTest.php` (the predicate).** The three existing prune cases are rewritten to the new rules:

- a run with a PR, merged or closed (by `prState` on an old run, and by a stored `status`), 8 days after its last
  filing is pruned; 6 days after it is kept;
- a run with a PR whose status is running, halted or ready is never pruned, at any age (30 days);
- a run with no PR (key missing, `null`) is pruned 15 days after its last filing and kept at 13, whatever its
  status or `prState`;
- unusable timestamps never prune (as today, plus a no-PR run with no `updatedAt`).

**`ProofStatusTest.php` (the prune pass as a subprocess).** One case: a store holding a no-PR run filed 15 days ago
and one filed yesterday; `prune` with the fake `gh` removes the first directory, keeps the second, prints
`proof: pruned 1 run(s)`, and the rewritten index no longer links the first.

**`ProofRenderTest.php` (the index).**

- the controls: the repo select as today, the status select with `All statuses` and the five labels in case
  order, the search input, and the toggle with the count of finished runs (a store with one merged, one closed
  and one running run reads `(2)`);
- each row's `data-status`, `data-finished` and `data-search` (lower-cased title, `#<pr>`, branch and summary),
  and the escaping of `data-search` (extends the existing escaping case);
- the header row: eight sort buttons with their `data-sort-type` and `data-sort-first`, *Summary* without one
  (replaces #142's literal `<th>` assertion);
- each cell's `data-sort` per the table above, empty for a run without a PR, without figures, or with an
  unparseable `updatedAt`;
- Updated renders `<time datetime=… title=…>01-10 20:29</time>` for `2026-10-01T20:29:13+00:00`, and `01-10 22:29`
  for `2026-10-01T22:29:13+02:00` (its own offset, not UTC);
- the no-PR cell reads `no PR` and no longer `prune manually` (replaces the two flag assertions);
- the table sits inside `.table-wrap`, and `#no-match` is rendered hidden;
- the script carries the new keys and listeners (`proof:status`, `proof:finished`, the `input` listener,
  `aria-sort`), as #142's script case asserts its own;
- an empty store renders no controls and no script (as today).

**In the browser** (the issue's *Verify*; `verify-ui` and `implement` do it, never `design`). The Playwright MCP
refuses `file:` URLs, so: a copy of `~/GitProjects/_proofs` in a temp dir, its index re-rendered by the new code
from a `php -r` against the copy (`PIPELINE_PROOF_ROOT` set to the copy, so the real store is never written; a
render, not a `prune`, so no run is deleted and `gh` is not asked), served with `php -S 127.0.0.1:<port> -t <copy>`.
At 1440 and 390 px, light and dark (`browser_emulate_media`):

- by default no merged or closed row shows, and the toggle's count matches the hidden rows;
- the toggle and the status filter survive a reload;
- a search term narrows the rows (a PR number, a word of a client summary, a branch);
- a header click sorts, a second click reverses, and the arrow follows;
- Updated shows `dd-mm HH:MM` in local time with the full timestamp on hover;
- at 390 px the controls wrap and the page has no horizontal scroll (the table scrolls in its wrapper).

## Done when

- `proof_should_prune()` prunes merged or closed runs 7 days after their last filing, runs with no PR 14 days after
  it, and an open PR never; the proof suite covers each, and the prune pass removes a stale no-PR run.
- The index hides merged and closed runs until the toggle shows them, filters by status, searches title, PR,
  branch and summary, sorts by a header click and reverses on the second, and shows Updated as `d-m H:i` with the
  full timestamp as a tooltip; the toggle and both filters are remembered.
- The suite is green, and the browser check above passes over a served copy of the real store.
- engine.md §The proof store and the 2026-08-25 spec say the new retention.

## Assumptions

Each question the brainstorm would have asked the owner, and the answer assumed.

1. **From when do the 7 days run?** From `updatedAt`, the last filing, as the issue says and as the 14 days did.
   Consequence: a status amendment (ready, merged) does not move it, so a run that waited more than 7 days for its
   merge without a new filing is pruned by the first prune pass after the merge, and its page is gone the same
   day. The alternative, a store-owned `finishedAt` set when the status turns merged or closed, gives every merged
   run 7 days after its merge; it is not built, because the issue measures from the last update.
2. **Is "merged or closed" `prState` or the status?** The status (`ProofRunStatus::of()`), so the 7-day clock and
   the index's default hiding cover the same runs; the prune pass corrects the status from `gh` first, and an old
   run without a status reads its `prState`.
3. **Does a run with no PR prune whatever its status?** Yes. No PR means `gh` has nothing to say about it, and a
   run that has not been filed for 14 days is not running; a resumed run that is filed again gets a fresh
   `updatedAt`. In the real store this is one run today
   (`viewiemedia/chore-flux-editor-browser-verification`, last filed 2026-08-25), which the first prune pass after
   the merge removes. The hand-made pages under `_proofs/_adhoc/` hold no `run.json`, so `proof_scan_runs()`
   never sees them.
4. **What does the toggle do when the status filter names Merged or Closed?** The status filter wins: an explicit
   choice of a finished status shows those rows with the toggle off. The toggle governs only `All statuses`.
5. **Which statuses does the status filter offer?** All five, always, in case order with their labels, so the
   control does not change shape as the store changes; the repo filter keeps listing only the repos present.
6. **How does search match?** Case-insensitive; every whitespace-separated term must occur somewhere in the row's
   search text (title, `#<pr>`, branch, client summary). Not remembered: a search is a momentary question, while
   the filters are a standing view. `#412` and `412` both match PR 412.
7. **Is the chosen sort remembered?** No. The issue keeps the attention order as the default, and a remembered
   column would make a reload hide what needs attention. A reload restores it.
8. **Which direction does a first click sort?** Text A–Z; numbers and times largest or newest first; Status in the
   attention order (halted, ready, running, merged, closed). Empty keys last either way. Summary is not sortable:
   its cell is a copy button.
9. **Which timezone does Updated show?** The browser's: the script rewrites each `<time>` to local time. PHP's own
   output, which stands without the script, uses the timestamp's own offset, because PHP's default timezone here is
   UTC and the store is filed in UTC.
   Probed: PHP's default timezone on this machine is UTC and the store's `updatedAt` values are all `+00:00`: yes,
   `UTC UTC`, and the 57 runs' `updatedAt` values all end `+00:00` (`php -r 'echo date_default_timezone_get(), " ", ini_get("date.timezone");'`
   and a grep of `~/GitProjects/_proofs/*/*/run.json`).
10. **Why `d-m` and not `m-d`?** The issue's `01-10 20:29`, on a store rendered 2026-10-02, is the first of October:
    day first, as the owner writes dates.
11. **What happens to the `no PR — prune manually` flag?** It becomes a muted `no PR`: the prune pass now removes
    those runs, so there is nothing manual to flag.
12. **Does the toggle show a count?** Yes, `(n)` of finished runs, so an index that looks short says how much it
    hides. It is computed in PHP; it does not follow the repo filter.
13. **Does the empty store change?** No: `No runs recorded.`, no controls, no script.
14. **Is anything about the run page changed?** No. Only the index, the predicate and the docs.

## Relation to other work

- **#142** (merged) built the index this extends: its row data, markers, attention order and script stay.
- **#153** (merged) added the page's link back to the index; untouched here.
- **#149 / #158** (in flight) change the CI gate's plan and spec files; this run touches neither. engine.md is
  shared ground: whichever lands second merges the base (engine.md §Catching up with the base).

## What was read

The issue; `proof.php` (`ProofRunStatus` with `of()`, `group()`, `corrected()`, `proof_should_prune()`,
`proof_scan_runs()`, `proof_write_run()`, `proof_cost_totals()`); `proof_render.php` (`proof_render_index()`,
`proof_index_order()`, `proof_updated_time()`, `proof_render_index_filter()`, `proof_render_index_row()`,
`proof_render_index_script()`, `proof_render_styles()`, `proof_render_status()`); `proof_store.php`
(`proof_store_amend()`, `proof_store_status()`); `proof_cli.php` (`proof_cli_refresh()`, `proof_cli_pr_view()`,
`proof_cli_prune()`, the `write` path that prunes after filing); `ProofTest.php`'s prune cases,
`ProofStatusTest.php`'s prune-pass cases, `ProofRenderTest.php`'s index cases; engine.md §The proof store; the
2026-08-25 proof store spec §7, §8 and its testing strategy; the #142 and #153 specs; the real store's layout,
statuses and its one no-PR run.
