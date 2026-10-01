# The proof store index shows each run's status, what changed since the last look, and its time and cost — design

**Design size:** Architectural

**Date:** 2026-10-01
**Issue:** IT4WEBBV/LaravelClaudeMd#142
**Canonical home:** `skills/pipeline/checks/proof.php` (the run's status enum, the store keys, the revision, the
cost merge), `skills/pipeline/checks/proof_store.php` (amending a filed run), `skills/pipeline/checks/proof_cli.php`
(`status`, the prune pass), `skills/pipeline/checks/proof_render.php` (the page and the index),
`skills/pipeline/checks/run_cost.php` and `run_cost_cli.php` (filing the figures), `skills/pipeline/checks/dispatch_cli.php`
(Halted on a halt, Running on a resume, `proof` on `done`), `skills/pipeline/checks/brief.php` (`review-pr:resolve`
in `interactive`), pipeline `references/engine.md` §The proof store, §`autoflow`, §The CI gate, §After the merge,
`SKILL.md` *Visual proof*, *Cost per run* and *`autoflow` — how a run starts and ends*, `orchestrate`'s `SKILL.md`
and `references/commands.md` §Finish, §Watch, §Teardown.
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. The issue body is the approved scope and
is not re-litigated here. Every question the brainstorm would have asked is answered in *Assumptions*, so
`/critique plan` audits exactly those. Nothing of the design was built or run; what it relies on was read (see
*What was read*). One throwaway probe was run, because the issue makes one approach conditional on it (below).

## Problem

As the issue states it, confirmed in the code:

- `proof_render_index()` renders one flat table from `proof_scan_runs()`, newest `updatedAt` first: repo, PR
  number and `prState`, title, shot count, date. There is no status: a halted run reads like any other row,
  and `prState` is whatever the last `write` or prune pass saw (`proof_cli_pr_state()` asks `gh` for `state`
  only, never `isDraft`).
- `proof_write_run()` keeps `createdAt` and sets `updatedAt`; nothing counts writes, so neither the page nor
  the index can tell a page rewritten by `verify-ui`, a loop-back or the finish step from the one read before.
- `run_cost_cli.php` prints its per-step lines and `run:` line to the invoking session only
  (`pipeline_run_cost_lines()`); nothing files them anywhere.
- The store holds 49 runs over nine repo folders; none is `schema: 2` yet (#141 merged today), and several
  (`Asimo/dashboard-before-after`, `Deploy/mockup-411-three-column`) have no PR.

## The probe: does Chrome share `localStorage` across `file://` pages?

The issue makes the seen-per-revision mechanism conditional on it. **It does.** Google Chrome 154.0.8037.58,
the installed binary, run with `--headless=new`, a fresh `--user-data-dir` and `--dump-dom`: a page at
`file:///tmp/ls-probe/repo/run-a/index.html` set `seen:repo/run-a` = `3` and replaced itself with
`file:///tmp/ls-probe/index.html`, which read `3`; its `location.origin` was `file://`. Recorded on the issue
(issuecomment-5937295264). So `localStorage` stays the mechanism, as the issue proposes. The Playwright MCP
refuses `file:` URLs (`Access to "file:" protocol is blocked`), which matters for the browser check below.

## Settled by the owner

- The issue body is the spec input, brainstormed and approved; its scope stands (the run's `decisions`).
- The probe comes first and its outcome goes on the issue: done, above.
- The issue leaves to the design: how each status is written (by a command or by a session's duty), how the
  seen state reaches the ordering, and where the cost figures are kept (Assumptions 1–6, 9–12).

## Approaches

**How a status reaches the page.**

1. **Commands write what they already know; sessions run one subcommand for the rest (chosen).** A halt
   always passes through `dispatch_cli_halt()` (`finish` in `autoflow`, `returned` in `interactive`, `launch`'s
   invariant check), which has the manifest and so `artifacts.proof`: it writes Halted with the reason, with
   no duty for any session to forget. A resume passes through `launch` (`autoflow`) or `next` (`interactive`):
   they write Running. Ready for review and Merged/Closed follow a `gh` call no command makes (`gh pr ready`
   stays visible to the permission rules; the merge watch is a shell loop), so the session that ran it
   follows with `proof_cli.php status <page> ready|merged|closed`.
2. **Every status by a session's duty.** Four duties in prose where one is enough; a halted run is the one
   most likely to be left by a session that is busy with the halt. Rejected.
3. **Derive every status from `gh` at render time.** The index renders on every `write` and prune; a halt has
   no trace on GitHub, and the prune pass already pays one `gh` call per run. Rejected for Halted; kept as the
   correction the issue asks for (*The prune pass*).

**Where the seen state decides the order.**

1. **The renderer orders by status; the index's script finishes the order (chosen).** The seen state lives in
   the browser only, so the final order (a Ready row that is seen drops to the rest) can only be settled there.
   PHP renders the rows already ordered by status and date, each with its group, revision and date as data
   attributes; the script re-ranks with the seen state. Without the script the order is still the status order.
2. **The index script sorts everything from data attributes, PHP renders unsorted.** The pure ordering would
   have no Pest test, which the issue asks for. Rejected.

**Where the cost figures live.**

1. **In `run.json`, per workflow, filed by `run_cost_cli.php` given the page (chosen).** The session already
   runs `run_cost_cli.php` after every `autoflow` run; a second argument files what it prints. A run has as
   many workflows as it had launches (a resume, a CI fix round), each with its own transcript dir, so the
   figures are kept per workflow, keyed by that dir's name, and summed for the run.
2. **A `proof_cli.php cost <page> <transcript dir>` subcommand.** The same filing, but a second command after
   the one that already computes the figures. Rejected.

## Design

### The run's status

`proof.php` gains a backed enum:

```php
enum ProofRunStatus: string
{
    case Running = 'running';
    case Halted = 'halted';
    case Ready = 'ready';
    case Merged = 'merged';
    case Closed = 'closed';

    public function label(): string;   // Running, Halted, Ready for review, Merged, Closed
    public function group(): int;      // Halted 0, Ready 1, every other 2: the index's attention order

    /** The stored status, else what an older run's prState implies: MERGED, CLOSED, else Running. */
    public static function of(array $run): self;

    /** What `gh` says the PR is, over the stored status (the prune pass). */
    public function corrected(string $prState, bool $isDraft): self;
}
```

`run.json` holds it as `status: {"state": "halted", "reason": "<why>"}`; `reason` only with `halted`.
`corrected()`: `MERGED` → Merged, `CLOSED` → Closed, `OPEN` and not draft → Ready, `OPEN` and draft → the
stored status when it is Running or Halted, else Running (a PR put back in draft by `gh pr ready --undo`).
Any other `prState` keeps the stored status.

**Who writes each status** (the issue's list; engine.md §The proof store gets this table):

| Status | Written by | When |
|---|---|---|
| Running | the store, as a default | `handoff` files the page (and any filing of a run that has no status) |
| Running | `dispatch_cli.php launch` (on `start`) and `next` (on a dispatch) | a run starts or resumes, so a resumed halt reads Running again |
| Halted, with the reason | `dispatch_cli_halt()`: `finish`, `returned`, `launch`'s invariant halt | the manifest records a halt |
| Ready for review | the session that ran `gh pr ready <pr>`: the invoking session in `autoflow`, the finish step in `interactive` | right after `gh pr ready` succeeded: `proof_cli.php status <page> ready` |
| Merged / Closed | the session holding the merge watch: §After the merge, `orchestrate` step 6 | the watch prints `MERGED` or `CLOSED`, before any teardown: `proof_cli.php status <page> merged` / `closed` |
| any | the prune pass, from `gh` | every `write` and `prune`: `corrected()` |

The commands' writes go through `proof_store_status()` (below) on `artifacts.proof`, only when the manifest
sets it. They never change the command's answer and never halt: a page that cannot be amended is one line on
stderr (`proof: status not written: <why>`). The engine still never *reads* the store to decide anything.

`dispatch_cli_done()`'s answer gains `proof`: `artifacts.proof`, or null. `finish` and `returned` print it with
`done`, so the session has the page for `status <page> ready` (and for the `open` it already does) without
reading the manifest.

### A revision per page

`revision` is a store key: `proof_write_run()` writes the stored `revision` plus one (`1` for a run that has
none), beside `createdAt` and `updatedAt`. Every filing bumps it: `handoff`'s and every agent `write`, a re-run
`handoff` included. A status or a cost amendment does not (Assumption 5), and neither does the prune pass.
`PROOF_STORE_KEYS` gains `revision`, `status` and `cost`: a payload's values for them are ignored, a stored
one survives every merge.

### Amending a filed run

`proof_store.php` gains:

```php
/** Applies $change to the run filed beside $page and re-renders the page and its store's index; null, or why not. */
function proof_store_amend(string $page, callable $change): ?string;
function proof_store_status(string $page, ProofRunStatus $status, string $reason = ''): ?string;
```

`proof_store_amend()` reads `run.json` in `dirname($page)`, applies `$change`, and writes it with
`updatedAt` and `revision` untouched (the prune pass's existing rule: the grace period measures the run's last
filing), then renders the page and the index of the store the page is in (`dirname($page, 3)`, so a test store
and the real one never mix). A page with no `run.json` beside it, or one that does not parse, is `no run at
<dir>`. The prune pass's per-run rewrite becomes a call to it.

### The CLI

- **`proof_cli.php status <page> <running|halted|ready|merged|closed> [--reason <text>]`**: one
  `proof_store_status()`. An unknown status, a missing page or a `halted` without `--reason` logs one line and
  exits 0, as every store path does.
- **The prune pass** asks `gh pr view <pr> --repo <nameWithOwner> --json state,isDraft`, and amends a run whose
  `prState` or `ProofRunStatus::of()->corrected()` changed. A `gh` failure keeps both, as today.
- **`run_cost_cli.php <transcript dir> [<page>]`** prints exactly what it prints today. Given a page it also
  files the figures: `proof_store_amend($page, fn ($run) => proof_add_cost($run, $record))`, where `$record` is
  `pipeline_run_cost_record(basename($dir), $steps)` (`run_cost.php`, pure):

```json
{"workflow": "wf_71c2e8b3-c2a", "span": 1243.5, "steps": [
  {"label": "implement:run", "models": ["sonnet"], "cost": 2310000.0, "calls": 41, "peak": 182000, "wall": 780.2, "waiting": 312.0}
]}
```

  `proof_add_cost()` (`proof.php`, pure) replaces the entry with the same `workflow` in `cost`, else appends
  it, so filing the same run twice changes nothing and a resume or a fix round adds its own. A run without
  step transcripts (`pipeline_run_cost_lines()`'s `not measured`) files nothing. A filing failure is one line
  on stderr; the exit code stays 0.

### The page

Under the `h1`, before the meta line, a **status line**: a pill with `ProofRunStatus::label()`, coloured by a
token per status (`--running` muted, `--halted` the accent red, `--ready` blue, `--merged` green, `--closed`
muted), and for Halted the reason beside it. The meta line gains `revision <n>` when the run has one.

A run with `cost` gets a **Time and cost** section after the open questions, before the ledger: one table, a
row per step (step, models, wall minutes, of which waiting on tools, weighted cost in M, peak context in k),
the workflows in filing order with a muted row naming each when there is more than one, and a total row: the
summed spans and the summed cost. A run without `cost` (every `interactive` run) has no section.

The page's script gains, before the copy and zoom code, the seen write:
`localStorage.setItem('seen:' + <repo>/<run>, <revision>)`, the key the last two directory segments of
`location.pathname`, the revision a `data-revision` on `<body>`, inside `try`/`catch`; a run without a
revision writes nothing. The pathname, not the run's fields, keys it: it is what the index links to, and it
stays right for a directory adopted from a branch slug.

### The index

`proof_index_order(array $runs): array` (`proof_render.php`, pure) sorts the scanned runs by
`ProofRunStatus::of($run)->group()`, then `updatedAt` newest first. `proof_render_index()` renders them in that
order, with:

- a **repo filter** above the table: a `<select>` of the repos present (`All repos` first), which hides the
  other rows; the choice is remembered in `localStorage` (`proof:repo`), inside `try`/`catch`;
- per row `data-run="<repo>/<run>"` (the href's two segments), `data-repo`, `data-group`, `data-updated`, and
  `data-revision` when the run has one;
- columns **Status** (the pill; Halted with its reason under it, muted), **Repo**, **PR** (as today, with
  `no PR — prune manually`), **Run** (the title link, then a slot the script fills with `New` or `Updated`),
  **Shots**, **Time** (summed spans in minutes), **Cost** (summed cost in M), **Updated**, and a **Copy**
  button when the run has a `clientSummary`, which copies it from a hidden `<span id="summary-<n>">`;
- the copy code shared with the run page: `proof_render_script()` splits into `proof_render_copy_script()`
  (the clipboard API, else the textarea and `execCommand('copy')`) and the page's zoom part.

**The index script** (`proof_render_index_script()`), on `DOMContentLoaded` and again on `pageshow` (a page
restored from the back/forward cache runs no script otherwise, and Back from a page is how the index is
reached again):

1. per row with a revision: `New` when `seen:<run>` is absent, `Updated` when it is lower than the revision,
   nothing when it is seen; a row without a revision gets nothing;
2. a Ready row that is seen moves to group 2; then the rows are re-appended by group, then `data-updated`
   newest first;
3. the filter is applied.

`localStorage` throwing (a private window, blocked site data) leaves every row unmarked and the PHP order
standing.

### The docs

- **engine.md §The proof store**: the *who writes each status* table, `revision`, `status` and `cost` in the
  payload table as store keys (never a payload's), the seen marker, and the index's order. **§`autoflow`**: the
  after-run report passes `artifacts.proof` to `run_cost_cli.php` when set. **§The CI gate** and **§Who takes
  the PR out of draft**: `proof_cli.php status <proof> ready` after `gh pr ready`. **§After the merge**: step 2
  writes `merged` before the teardown, step 3 writes `closed`. **§Failure policy**: a recorded halt marks the
  page Halted by itself.
- **`SKILL.md`**: *Visual proof* names the status and the index; *Cost per run* says the figures are filed into
  the page; *`autoflow` — how a run starts and ends* steps 5 and 6 carry the two commands.
- **`orchestrate`**: `SKILL.md` step 5 and `references/commands.md` §Finish (`status <proof> ready` after
  `gh pr ready`, `run_cost_cli.php <dir> <proof>`), §Teardown (`merged` before the removal), step 6's closed
  path (`closed`).
- **`brief.php`** `review-pr:resolve` in `interactive`: `gh pr ready` when it answers `ready`, then
  `proof_cli.php status <the path write printed> ready`.

## Testing

Pest, in `skills/pipeline/checks/tests`, test first. No test reaches the real store: every one that files
sets `PIPELINE_PROOF_ROOT` to a temp dir, and an amendment writes only in the store its page is in.

- **`ProofTest`**: `ProofRunStatus::of()` (stored, then `MERGED`, `CLOSED`, `OPEN` and none → Running); every
  `corrected()` row in a dataset; `group()`; `proof_add_cost()` appending and replacing by workflow; a payload's
  `revision`, `status` and `cost` ignored by `proof_merge_run()`.
- **`ProofWriteTest`**: the first write files `revision: 1` and `status: running`; a second write files `2` and
  keeps a stored Halted; a re-run `handoff` page bumps too.
- **`ProofStatusTest`** (new, the CLI as a subprocess): `status <page> halted --reason` writes status and
  reason, leaves `revision` and `updatedAt`, and re-renders the page and the index; `ready` drops the reason;
  an unknown status, a missing page and a `halted` without a reason log and exit 0, writing nothing.
- **`ProofRenderTest`**: the status pill per status and the Halted reason on the page; `revision` in the meta
  line and `data-revision` on the body; the seen write in the page script; the Time and cost section (one
  workflow, two workflows with their name rows, the totals) and none without `cost`; the index's order (a
  Halted run first, then Ready, then the rest newest first) through `proof_index_order()`; the row attributes,
  the filter's options, the Time and Cost cells (empty without `cost`), the copy button only with a summary;
  every new value escaped.
- **`RunCostTest`**: `pipeline_run_cost_record()`; `run_cost_cli.php <dir> <page>` prints the same lines and
  files the record, twice is once; without a page, or with a missing one, it prints as before and exits 0.
- **`DispatchCliTest`**: a `finish` halt on a manifest with `artifacts.proof` marks the page Halted with the
  reason; `launch` marks it Running; a manifest without `artifacts.proof` writes nothing; `done` carries
  `proof`. **`BriefTest`**: the `interactive` resolve line.

**In the browser**, before the PR leaves draft. The Playwright MCP refuses `file:` URLs, so `implement` files
fixture runs into a temp `PIPELINE_PROOF_ROOT` (a Halted run, a Ready run, a Merged run with cost, two repos)
and serves that root with `php -S 127.0.0.1:<port> -t <root>`: one origin, as `file://` is one origin in Chrome.
At 1440 px and in dark mode: Halted first with its reason; a Ready row New, then, after opening its page and
going Back, seen and dropped below; a page re-filed after opening shows Updated; the filter hides the other
repo and survives a reload; the copy button puts the summary on the clipboard. The issue's check *on the real
store* over `file://` in the owner's Chrome is the owner's, after the merge (*Done when*).

## Done when

- A halted run's page and index row say Halted with the reason, and the row sorts first; a resumed run reads
  Running again.
- `gh pr ready`, a merge and a close each leave the page saying so, by the duties named in engine.md §The proof
  store, and the prune pass corrects a stale status from `gh`.
- A page rewritten after it was opened shows Updated in the index, one never opened shows New.
- The localStorage probe and its outcome are on the issue (done in this step).
- An `autoflow` run's page shows its time and cost per step, and its index row the totals; an `interactive`
  run shows none.
- The tests above pass; engine.md §The proof store names who writes each status.
- After the merge, outside this PR's gates: the index of the real store, opened over `file://` in Chrome, shows
  the filter, the copy button, and New/Updated/seen after opening a page.

## Assumptions

Each question the brainstorm would have asked the owner, and the answer assumed.

1. **Who writes Halted?** `dispatch_cli_halt()`, the one function every recorded halt passes, when the
   manifest has `artifacts.proof`: "the session holding the run" writes it by running `finish` or `returned`,
   which it does anyway. A halt `handoff` records before its page exists has no page to mark.
2. **What clears Halted on a resume?** `launch` (on `start`) and `next` (on a dispatch) write Running. Without
   it a run resumed at `implement` would read Halted until its next `write`.
3. **Who writes Ready, Merged and Closed?** The session that made the change, by `proof_cli.php status`, right
   after `gh pr ready` or the watch's answer. `gh pr ready` is not wrapped in a command: the permission rules
   and auto mode must keep seeing it plainly.
4. **Does any filing set a status?** Only where the run has none (`handoff`'s first page, an old run's first
   new write): Running. A `write` never resets Halted or Ready; a resume does (Assumption 2).
5. **Does a status or a cost amendment bump the revision?** No. The issue counts `write`s; a status is shown in
   the index's own column, and a bump on every merge or cost filing would mark every finished run Updated.
   `updatedAt` stays the last filing's too, as the prune pass already keeps it.
6. **How does the prune pass map `gh`'s answer?** `corrected()`: merged, closed, open and ready are GitHub's to
   say; an open draft keeps Running or Halted, which GitHub cannot see, and turns a stale Ready back into
   Running.
7. **What is an older run's status?** `of()`: `prState` `MERGED` or `CLOSED`, else Running. Older runs that
   opened no PR (mockups, ad-hoc pages) read Running and keep their `no PR — prune manually` flag.
8. **Which runs get New/Updated?** Only runs with a `revision`, i.e. filed after this change; an older row
   gets no marker, rather than New on all 49 rows, until its next filing. Seen is no marker at all, so only
   what needs a look stands out.
9. **How is the seen key derived?** From the page's own path, its last two directory segments, which are the
   index's `data-run`: the same string by construction, and right for a directory adopted from a branch slug.
10. **Where does the final order happen?** PHP orders by status and date (tested); the index script moves a
    seen Ready row into the rest, which only the browser can know. Without the script the order still puts
    every Halted and Ready run first.
11. **How are a run's several workflows' figures kept?** One `cost` entry per transcript dir, replaced when the
    same dir is filed again; the page lists each workflow's steps, the index sums them. Time is the summed
    spans, not first start to last end: the idle hours between a halt and its resume are not the run's time.
12. **Which figures does the page show?** Those `run_cost_cli.php` prints per step: models, wall and waiting
    minutes, weighted cost, peak context. The weighted cost is the proxy `run_cost.php` defines, not money,
    and the page names it *weighted*.
13. **Is the repo filter remembered?** Yes, per browser (`proof:repo`); a convenience, wrapped in `try`/`catch`.
14. **How is the issue's browser check done when the Playwright MCP blocks `file:`?** Over `php -S` on a temp
    store in `implement`, one origin as `file://` is in Chrome; the check on the real store over `file://` is the
    owner's after the merge, beside #141's.
15. **Does the index keep its Shots column?** Yes; the issue adds columns and removes none.

## Relation to other work

- **#141** (merged) gives every run a page from `handoff`, the merge this spec's store keys rely on, and the
  `clientSummary` the index copies.
- **#134** (in flight, no PR yet) changes how `pipeline` and `orchestrate` start a workflow; it may touch
  `SKILL.md`'s *`autoflow`* section and `orchestrate`'s `SKILL.md`, which this spec edits at steps 5 and 6.
  Whichever lands second merges (engine.md §Catching up with the base).
- **#128** (split engine.md by reader) touches §The proof store. Whichever lands second merges.

## What was read

`proof.php`, `proof_store.php`, `proof_cli.php`, `proof_render.php`, `run_cost.php`, `run_cost_cli.php` (whole);
`dispatch_cli.php` (`dispatch_cli_halt()`, `_next()`, `_returned()`, `_done()`, `_launch()`, `_finish()`,
`_handoff()`, `_pr_view()`); `record.php` `PIPELINE_RECORD_FLAGS` and the `handoff:run` row; `brief.php`
`review-pr:resolve`; `workflow/pipeline-autoflow.js` (the `open` line); engine.md §`autoflow`, §After the merge,
§The proof store, §Who takes the PR out of draft, §The CI gate, §Failure policy; `SKILL.md` *Visual proof*,
*Cost per run*, *`autoflow`*; `orchestrate` `SKILL.md` step 5 and `references/commands.md` §Finish, §Watch,
§Proof page; the tests `ProofWriteTest` (its CLI helper), `RunCostTest`, `DispatchCliTest` (its
`PIPELINE_PROOF_ROOT` default); the #141 spec; the real store's layout (`~/GitProjects/_proofs`, 49 `run.json`).
