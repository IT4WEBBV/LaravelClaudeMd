# The proof index reads like an inbox, and a run that halts or turns ready is unread again: design

**Design size:** Architectural

**Date:** 2026-10-02
**Issue:** IT4WEBBV/LaravelClaudeMd#160 (depends on #151, merged; #154, merged)
**Canonical home:** `skills/pipeline/checks/proof.php` (`ProofRunStatus`, `PROOF_STORE_KEYS`, two new functions),
`skills/pipeline/checks/proof_store.php` (`proof_store_amend()`), `skills/pipeline/checks/proof_render.php` (the
run page's `<body>` and seen script, the index heading and row, the styles, `status.js`, the index script), the tests
beside them in `skills/pipeline/checks/tests/`, and pipeline `references/engine.md` §The proof store.
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. The issue body is the approved scope and is
not re-litigated here. Every question the brainstorm would have asked is answered in *Assumptions*, so
`/critique plan` audits exactly those. Nothing of the design was built or run; what it relies on was read (see
*What was read*).

## Problem

The store index (`~/GitProjects/_proofs/index.html`) already knows per browser which runs were opened: a run page
stores its `revision` under `seen:<repo>/<run>` in `localStorage` when it is opened (`proof_render_seen_script()`),
and the index script's `unread(row)` marks a row *New* (never opened) or *Updated* (filed again since). #154 put the
same rule into the tab: the title counts the unread runs that are not merged or closed, and the favicon dot is red,
green or blue by the unread runs' statuses. Two gaps keep it from reading as an inbox:

- **It is hard to see.** The marker is a small uppercase word after the title (`.marker`); read and unread rows
  otherwise look the same.
- **A status change never makes a run unread.** `proof_store_amend()` leaves `revision` alone (on purpose: it counts
  filings, and the page shows it as *revision N*), so `proof_cli.php status <page> halted|ready`, the dispatcher's
  halt and the prune pass's `gh` correction change nothing for a run opened earlier. A run opened while Running that
  then halts, or turns Ready for review, only moves up the list, and the tab's red or green dot never lights for it.

## What the owner asked for (the issue)

1. **The unread rule.** A run is unread when this browser never opened it, or when, since it was last opened, its
   proof was filed again (today's `revision`), or its status turned **Halted** or **Ready for review**. Merged,
   Closed and Running never make a run unread.
2. **Inbox look.** Unread rows bold with a dot before the title; read rows normal weight and muted. The marker word
   stays as the hint of why: `New`, `Updated`, `Halted`, `Ready`.
3. **Mark as unread / read** per row, stored in the same `localStorage` key.
4. **An unread count in the page heading.**
5. **The tab** (#154, merged) reads the same rule for its dot and title count; `status.js` carries whatever field the
   rule needs.

## Approaches

### Where "its status turned Halted or Ready" is remembered

1. **A counter in `run.json`, `attention`, raised by the store when a run's status changes to Halted or Ready
   (chosen, the issue's suggestion).** Every status write already goes through `proof_store_amend()` and re-renders
   the run's page and the index, so the page a browser opens next carries the raised count, and the index compares
   it the way it compares `revision`. A run that halts, is resumed and halts again between two looks counts twice
   and is unread.
2. **The browser remembers the status it saw when the page was opened**, and the index calls a row unread when its
   status is now Halted or Ready and differs. No store change, but it misses every round trip between two looks:
   Halted, resumed to Running, Halted again reads as already seen; so does Ready, back in draft, Ready again.
   Rejected.
3. **Raise `revision` on such a status change.** One counter, but `revision` counts filings: the page's *revision N*,
   engine.md's `run.json` table and the tests that pin *a status is no filing* all say so. Rejected.

### Where the counter is raised

1. **In `proof_store_amend()`, through one pure function over the run before and after the change (chosen).** Every
   path that changes a status amends: `proof_store_status()` (`proof_cli.php status`, `dispatch_cli_proof_status()`
   on a halt, a launch and a dispatch) and `proof_cli_refresh()` (the prune pass correcting from `gh`, which turns
   an open PR that left draft outside the pipeline into Ready). A cost amend changes no status and raises nothing.
2. **In `proof_store_status()` only**, as the issue's sketch names it. The prune pass's `gh` correction to Ready
   writes its own closure through `proof_store_amend()` and would never raise it, though the run turned Ready for
   review all the same. Rejected.

### What the browser stores under `seen:<repo>/<run>`

1. **One number, the run's `revision + attention` (chosen).** Both counters only grow, so their sum grows whenever
   either does: a run is unread exactly when the stored number is below the run's. Every value stored today is a
   revision, and every run filed today has no `attention` (it counts as 0), so its number equals its revision: no
   run turns unread, and no run turns read, the day this ships. The hint word is read from what the row shows: its
   status, and whether it was ever opened (*The unread rule*).
2. **A pair, `<revision>:<attention>`.** It knows which counter moved, so the hint could say *Updated* for a ready
   run that was re-filed. But it needs parsing and a rule for today's bare numbers, and its *Halted* hint would name
   a status the run may have left again (halted, then resumed to Running). Rejected: the hint should say what the
   row needs now.

### How the unread rule is tested

1. **A pure JavaScript function, `proofUnread(seen, stored, status)`, rendered before the index script and run under
   `node` by the tests (chosen).** The rule is the heart of the issue; asserting its source text proves nothing about
   its answers, and `node` is already on the test path (`node --check`).
2. **String assertions on the script, as #142 and #154 did.** Kept for the wiring (the toggle, the classes, the
   count), not for the rule. Rejected for the rule.

## Design

### The counter: `proof.php`

- `ProofRunStatus::callsOwner(): bool`: true for Halted and Ready, false for Running, Merged and Closed. Today that
  is the same partition as `group() < 2`, so it is written as `group() < 2` rather than as a second list of cases. Its
  docblock: the statuses that make an opened run unread again (`proof_count_attention()`), the ones `group()` puts
  first.
- `proof_count_attention(array $before, array $after): array` returns `$after`, with `attention` one above
  `$before`'s (`(int) ($before['attention'] ?? 0) + 1`) when `ProofRunStatus::of($after)` differs from
  `ProofRunStatus::of($before)` and `callsOwner()`; otherwise `$after` unchanged. Halted to Halted (a second halt
  without a resume between, or a new reason) is no change and raises nothing; neither does any change to Running,
  Merged or Closed.
- `proof_run_seen(array $run): ?int`: `revision + attention` (attention 0 when absent), or null for a run filed before
  revisions existed (which gets no marker, as today). The number a page stores when it is opened and the index
  compares with: the run page's `<body data-seen>`, the index row's `data-seen` and `status.js`'s `seen` all come
  from it.
- `PROOF_STORE_KEYS` gains `attention`, so a payload can never set it and a filing keeps the stored one
  (`proof_merge_run()`). `proof_write_run()` does not touch it: a filing raises `revision`, never `attention`.
- The `PROOF_STORE_KEYS` docblock names it: `attention` counts the times the run's status turned Halted or Ready.

### Raising it: `proof_store.php`

`proof_store_amend()` applies the change through it: `$run = proof_count_attention($run, $change($run));`, then
writes `run.json`, the page and the index as today. Its docblock: a change to Halted or Ready raises `attention`,
which the index compares; `revision` and `updatedAt` still stay as they are. `proof_store_status()` and
`proof_cli_refresh()` change nothing.

### The run page: `proof_render.php`

- `proof_render_run()`: the `<body>` carries `data-seen="<proof_run_seen($run)>"` in place of `data-revision`, and
  nothing for a run without a revision, as today. The meta line keeps *revision N*.
- `proof_render_seen_script()` stores `document.body.dataset.seen` under `seen:<repo>/<run>`; the key and the path
  logic are unchanged. A page rendered before this ships stores its revision, which equals its run's number until
  the run is amended, and an amend re-renders the page with the new script.

### The index page: `proof_render.php`

- **Heading:** with a table, `<h1>Pipeline proof store <span id="unread-count" class="unread-count"></span></h1>`; the
  script writes `3 unread`, or nothing at 0. An empty store's heading is unchanged.
- **Row** (`proof_render_index_row()`): `data-seen` in place of `data-revision` (still only for a run with a
  revision), and in the Run cell, before the link, `<button type="button" class="dot"></button>` for such a run. The
  script gives it its label (`aria-label` and `title`: `Mark as read` on an unread row, `Mark as unread` on a read
  one). A run without a revision gets no button and no marker.
- **Styles** (`proof_render_styles()`):
  - `tr.unread td` bold (`font-weight:700`); `tr.read td` muted (`color:var(--muted)`), the status pill keeping its
    own colour.
  - `.dot`: hidden by default, so without `localStorage` (no row marked read or unread) the rows look as today;
    shown in `tr.unread` and `tr.read`. A round button with no border or background of its own, about 1rem across
    for the hit area, drawing a `.55rem` circle (`::before`): filled `var(--ready)` on an unread row, an outline in
    `var(--muted)` on a read one (the row is itself muted, so a `var(--line)` outline would not be seen), `var(--fg)` on
    hover and focus. A visible focus ring.
  - `.unread-count`: `var(--ready)`, the size and weight of `.marker`, a little space after the heading text.
- **`status.js`** (`proof_render_status_js()`): each entry gains `seen` (`proof_run_seen()`, null without a revision)
  beside `revision`, which stays. The row it carries is the new row, so a poll brings the dot and `data-seen` along.

### The unread rule: `proof_render.php`

`proof_render_unread_script()` renders one global function, placed before the index script in the index page's
`<script>` (`proof_render_index()`):

```js
function proofUnread(seen, stored, status) {
  if (!seen) { return ''; }
  if (stored === null) { return 'New'; }
  if (Number(stored) >= seen) { return ''; }
  return status === 'halted' ? 'Halted' : status === 'ready' ? 'Ready' : stored === '0' ? 'Unread' : 'Updated';
}
```

- `seen`: the row's number (`data-seen`), 0 or absent for a run without a revision: never unread.
- `stored`: what `localStorage` holds under the run's key, null when this browser never opened it: *New*.
- At or above the run's number: read. Below it: unread, and the hint is what the row needs now: *Halted* or *Ready*
  by its current status, else *Unread* when the owner marked it unread by hand (`'0'`, which opening a page can
  never store, since every revision is at least 1), else *Updated*.

### The index script: `proof_render_index_script()`

- `unread(row)` becomes `storage ? proofUnread(Number(row.dataset.seen || 0), storage.getItem('seen:' +
  row.dataset.run), row.dataset.status) : ''`: same signature, same `''` for read, so `tab()` reads the new rule
  unchanged, as #154 planned.
- `mark(row)` writes the marker word as today, and also toggles the row's `unread` and `read` classes (both only on a
  row with `data-seen`) and sets the dot's `aria-label` and `title`. The rank's *a seen Ready row drops among the
  rest* reads `data-seen` where it read `data-revision`.
- **The toggle:** one delegated `click` listener on the table body for `button.dot` (rows the poll inserts or
  replaces work unchanged). On an unread row it stores the row's `data-seen` under its key (read); on a read row it
  stores `'0'` (unread). Then that row is marked, the rows ordered and filtered, and `tab()` runs, so the heading
  count, the title and the dot follow at once. Without `localStorage` the dots are hidden and there is nothing to
  click; a write that throws changes nothing.
- **The heading count:** `tab()` also writes the same count it puts in the title into `#unread-count`: `<n> unread`,
  or empty at 0. One number, one rule: the unread runs that are not merged or closed, whatever the filters show.
- Opening a run from the index stores its number through the run page, as today; the next poll, or the `focus` and
  `pageshow` that coming back from the page fires, marks the row read.

### The docs: engine.md §The proof store

- The status table's introduction (or the sentence after it): a change of status to `halted` or `ready` raises the
  run's `attention`; a status or a cost is still no filing.
- The index paragraph's *What changed since the last look*: opening a page stores the run's `revision + attention`
  under `seen:<repo>/<run>`; a run is unread when this browser never opened it (*New*), or it was filed again
  (*Updated*) or turned halted (*Halted*) or ready (*Ready*) since; merged, closed and running never make it unread.
  Unread rows are bold with a dot, read rows muted; the dot marks a run read or unread by hand (*Unread*, stored as
  `0`); the heading counts the unread runs that are not merged or closed.
- *The open index tab*: the title counts the unread runs by that rule (no longer *New or Updated*); `status.js`
  carries each run's `seen` number too.
- The `run.json` table's store-owned rows: `attention` beside `revision` (*the store's, never a payload's*; counts
  the times the status turned `halted` or `ready`), and the list of a payload's ignored keys gains it.

## Testing

Pest, beside the existing tests in `skills/pipeline/checks/tests/`. Each fails today because the function, the field,
the attribute or the markup does not exist yet.

**`ProofTest.php` (pure)**
- `callsOwner()` is true for Halted and Ready, false for Running, Merged and Closed, the same partition as
  `group() < 2` (asserted against it, not as an unrelated fact).
- `proof_count_attention()`: Running to Halted, Running to Ready and Halted to Ready raise `attention` by one (from
  absent to 1, and from 1 to 2); Halted to Halted, Ready to Ready, anything to Running, Merged or Closed, and a change
  that leaves the status alone (a cost) return the run unchanged. A run without a stored status reads as its
  `prState` (`ProofRunStatus::of()`), so a `prState`-only run turning Ready raises it.
- `proof_run_seen()`: `revision + attention`, the revision alone without `attention`, null without a revision.
- `proof_merge_run()` ignores a payload's `attention` and keeps the stored one.

**`ProofStatusTest.php`, `ProofWriteTest.php`, `RunCostTest.php` (the store paths, as subprocesses)**
- `proof_cli.php status <page> halted --reason …` on a filed run writes `attention: 1`, leaves `revision` and
  `updatedAt`, renders the page with `data-seen="2"`, and `status.js` names the run with `seen: 2`; `ready` likewise.
- `merged`, `closed` and `running` leave `attention` absent and `seen` at the revision.
- Halted, then `running`, then halted again: `attention: 2`. Halted twice in a row: `attention: 1`.
- The prune pass's `gh` correction of a Running run to Ready (an open PR out of draft) raises it; to Merged does not.
- A second `proof_cli.php write` keeps the stored `attention` and raises `revision`, so `seen` rises by one; a payload
  carrying `attention` changes nothing.
- `run_cost_cli.php` filing a cost leaves `attention` as it was.

**`ProofRenderTest.php`**
- The unread rule under `node`: the rendered `proof_render_unread_script()` plus a table of calls, run with
  `node -e`, prints the expected words: no number gives `''`; never stored gives `New`; stored at or above the number
  gives `''`; below it, status `halted` gives `Halted`, `ready` gives `Ready`, `running` gives `Updated`, and a stored
  `'0'` gives `Unread` for a running row and `Halted` for a halted one; `merged` below gives `Updated`.
- The run page's `<body>` carries `data-seen` (revision 3 with attention 2: `data-seen="5"`; without a revision,
  plain `<body>`), the meta line still says *revision 3*, and the seen script stores `document.body.dataset.seen`
  (the two existing assertions on `data-revision` and `setItem('seen:' + run, revision)` change with it).
- The index row carries `data-seen` and the `button.dot` before the link; a run without a revision has neither (the
  two existing full-row assertions change with it).
- The index heading carries `#unread-count` with a table, and an empty store's heading does not.
- `status.js` entries carry `seen` (null without a revision) beside `revision`.
- The index script calls `proofUnread(`, toggles the `unread` and `read` classes, labels the dot `Mark as read` and
  `Mark as unread`, stores `'0'` and `row.dataset.seen` from the dot's click, writes `#unread-count`, and still passes
  `node --check` together with the unread script; the styles carry `tr.unread`, `tr.read` and `.dot`.

**In Chrome over `file://`** (the issue's Verify), on a copy of the real store under `PIPELINE_PROOF_ROOT`: open a
run, set it halted with `proof_cli.php status`, reload the index: the row is bold with its dot and *Halted*, the
heading and the title count it; open it: read again (normal weight, muted, an outline dot); mark it unread from the
index: bold again after a reload, with *Halted* (still halted) or *Unread* (a running run). A run opened earlier and
set `merged` stays read. If the Playwright MCP refuses `file:` URLs, the interactions run over a local `php -S`
server on the same copy, as #154's verification did, and the report says which.

## Done when

- A change of status to Halted or Ready, by any store path, raises the run's `attention`; to Running, Merged or
  Closed, and a cost, it does not; a filing still raises `revision`.
- The run page stores, and the index and `status.js` compare, `revision + attention`; no run's read state changes
  the day this ships.
- Unread rows are bold with a filled dot and the hint word; read rows muted with an outline dot; the dot marks a row
  read or unread; the heading and the tab count the same unread runs.
- The suite is green, and the Chrome check above holds.

## Assumptions

Each question the brainstorm would have asked the owner, and the answer assumed.

1. **Does a second halt without a resume between count again?** No. Only a change of status raises `attention`;
   Halted to Halted, with the same reason or a new one, is not one. The dispatcher can write Halted twice for one
   halt (a step's `brief` and then `finish`), and counting both would only matter if the owner opened the page in
   between, where it would wrongly turn the run unread again.
2. **Does a status turning Ready from the prune pass's `gh` correction count, not only `proof_cli.php status ready`?**
   Yes. The rule is about the status the owner sees, not who wrote it: an open PR taken out of draft outside the
   pipeline is ready for review all the same.
3. **What hint does an unread row show when several reasons apply?** What it needs now, in this order: *New* when
   never opened, else *Halted* or *Ready* by its current status, else *Unread* when marked by hand, else *Updated*.
   A ready run re-filed since it was opened therefore says *Ready*, not *Updated*; a run that halted and was resumed
   to Running before the owner looked is unread (its number rose) and says *Updated*, since it no longer needs the
   owner as a halt. The word is a hint; the bold row and the dot are the signal.
4. **What does marking unread by hand store, and what does it say?** `'0'` under the same key, below every run's
   number, so the run stays unread until it is opened or marked read; a page opened never stores 0. A fifth hint
   word, *Unread*, shows on a hand-marked row that is neither halted nor ready, since *Updated* would claim a
   re-filing that did not happen.
5. **What does marking read store?** The row's current number (`data-seen`), as opening the page would. The run turns
   unread again on its next filing or status change to Halted or Ready.
6. **Are merged and closed runs bold when never opened?** Yes: the issue's rule has no exception for them, and today
   they already show *New*. They are hidden by default, and neither the heading nor the tab counts them (#154's rule),
   so the count and the bold rows agree under the default filters.
7. **Does the heading count follow the filters?** No. It is the tab's number, every unread run that is not merged or
   closed whatever the filters and the search show, so the heading and the title never disagree. Its text is
   `<n> unread` beside the heading, nothing at 0.
8. **Does a row move when it is marked read or unread?** Yes, as after a poll: marking a Ready row read drops it among
   the rest (the existing seen-Ready rank), marking it unread brings it back. Rows are only reordered, never reloaded.
9. **Where is the control?** The dot before the title is the control, on every row with a revision: filled on an
   unread row (*Mark as read*), an outline on a read one (*Mark as unread*). A separate column or a text button would
   widen a table that already scrolls on a phone.
10. **Does the run page show its unread state, or a control?** No. Opening a page is reading it; the index is the
    inbox.
11. **Does the marker word change colour by its reason?** No. It stays `var(--ready)`; the status pill beside it
    already carries the status colour.
12. **What about a run directory filed again from scratch after a prune** (its `revision` and `attention` restart
    while the browser still holds a higher number)? It reads as read, as it does today with revisions alone. The
    prune pass only removes finished or PR-less runs, so this needs a new run on the same branch and PR; not
    addressed here.

Added by the plan step:

13. **Does any doc outside engine.md name the old markers?** `README.md` §Proofs app says Chrome is needed *because
    the index's New/Updated markers read what a run page stored in `localStorage`*. It changes to *the index's unread
    marks*; the reason for Chrome stays. Nothing else outside the earlier specs and plans names them, and those stay
    as records.
14. **Does `proof_cli_refresh()`'s return value carry the raised `attention`?** No. It returns the refreshed run for
    the prune decision only (`proof_should_prune()` reads `pr`, the status and `updatedAt`), and the amend it makes
    raises the stored count through `proof_store_amend()`. Applying `proof_count_attention()` there as well would be
    a second call site with no reader.
15. **When does `engine.md` gain the `attention` row?** With the store change, in the same task: `LockStepTest`
    requires every key in `PROOF_STORE_KEYS` to appear in §The proof store, so adding the key without the row turns
    the suite red. The index and tab paragraphs follow in the docs task.

## Relation to other work

- **#142, #151, #154** (merged): the seen marks, the markers, the attention order, the filters, the polled
  `status.js` and the tab this builds on. `tab()` is unchanged; it reads `unread()`.
- **#152** (the Proofs app): opens the index this page renders; no file overlap.

## What was read

`proof.php` (`PROOF_STORE_KEYS`, `ProofRunStatus`, `proof_merge_run()`, `proof_write_run()`, `proof_scan_runs()`),
`proof_store.php` (all), `proof_cli.php` (all, `proof_cli_status()` and `proof_cli_refresh()` in particular),
`dispatch_cli.php` (`dispatch_cli_proof_status()` and its callers: halt, launch, next), `run_cost_cli.php` (its
amend), `proof_render.php` (the styles, the seen script, `proof_render_run()`'s `<body>`, `proof_render_index()`
through `proof_render_index_script()`), `ProofRenderTest.php` and `ProofStatusTest.php` (the assertions on
`data-revision`, the seen script, the rows and `status.js`), engine.md §The proof store, the #154 design, and issues
#160 and #154.
