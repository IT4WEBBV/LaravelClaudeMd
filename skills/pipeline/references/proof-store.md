# The proof store — where the visual record lives

GitHub has no API for an image in a PR comment, so the visual record lives here and the PR gets a
text-only comment (`steps/verify-ui.md` §The record comment). Reader: the maintainer and the owner; the
session and the steps link here. What a write carries is `shared/proof-payload.md`.

## Where a page lives and who writes it

**Every run that reaches `handoff` has a page**, a self-contained one at
`~/GitProjects/_proofs/<repo>/pr-<n>-<topic>/` — keyed by repo *and* run, because a PR number collides
across the repos that share this store as readily as a branch name ever did. The run segment is the PR
number, which is what a reader has in hand when they come looking, plus the branch's topic so the
directory still names something; a run that opened no PR keeps its branch slug, and filing adopts that
directory once a PR appears. Three steps write the page, and every write is **merged** over the run as
filed, key by key at the top level: a key the payload carries replaces the stored one whole (a list is
replaced, never appended to; `[]` empties one), and a key it leaves out is kept.

1. **`handoff` files the page** from what it knows: `nameWithOwner` and `repo` from the PR's URL,
   `branch`, `mode`, `worktree`, `pr`, `prState`, `issue`, the `base` the PR goes into, and the title
   `PR #<n>: <the spec's heading>` cut to 70 characters, which fills only a run that has no title. It
   records the page as `artifacts.proof`. A page it cannot file is a note in its answer, never a halt.
   Its page shows the client summary and the explainer as *Pending*.
2. **`verify-ui`** (a UI run) adds the shots and a first client summary and explainer.
3. **The finish step** (`review-pr`'s resolve step, every run) writes the client summary and the
   explainer as the finished work stands, the suite line under `checks`, and the final open questions,
   each with its kind, and the ledger.

## The page

The page opens with the **client summary** (Dutch, for the hour registration, with a copy button), then
**In plain language** (the problem and the solution for a reader who knows nothing about the issue), the
headline and the technical Problem and Solution, **Tests this PR adds**, the shots, the checks, the open
questions and the ledger. A store-wide `index.html` is the join from a PR back to its page.
Above the heading, an *← All proofs* link goes to the store index (`../../index.html`, relative, so it works over
`file://`). Every link to GitHub, on a run page and in the index, opens a new tab (`target="_blank" rel="noopener"`);
the store's own links stay in the tab. Next to the PR, *Files changed* links the PR's diff (`/pull/<P>/files`).
A run with no `title` is named by its branch; a run at `schema` 1, filed without a client summary, renders without
the summary, explainer and tests sections.

## Statuses

**Each run has a status**, on its page under the heading and in the index's first column: `running`, `halted`
(with the reason), `ready` (*Ready for review*), `merged`, `closed`. A command writes what it already knows; a
session writes what only it knows, with `proof_cli.php status <page> <status> [--reason <text>]`, right after the
command that made it so:

| Status | Written by | When |
|---|---|---|
| `running` | the store | a filing of a run that has none (`handoff`'s first page): the status its `prState` implies |
| `running` | `dispatch_cli.php launch` (on `start`) and `next` (on a dispatch) | a run starts or resumes, so a resumed halt reads Running again |
| `halted`, with the reason | `dispatch_cli_halt()`: `finish`, `returned`, `brief`'s boundary check, `launch`'s invariant check | the manifest records a halt |
| `ready` | the session that ran `gh pr ready`: the invoking session in `autoflow` (`session.md` §The CI gate), the finish step in `interactive` | right after `gh pr ready` succeeded: `proof_cli.php status <page> ready` |
| `merged`, `closed` | `orchestrate/teardown.py`, run by the session holding the merge watch (`session.md` §After the merge; `orchestrate` step 6) | the watch prints `MERGED` or `CLOSED`, before any teardown |
| any | the prune pass, on `prune` | `gh pr view --json state,isDraft`: merged, closed and an open ready PR are GitHub's to say; an open draft keeps `running` or `halted`, and turns a stale `ready` back into `running`. A run filed without `nameWithOwner` is asked about by its `repo` when that holds `owner/name`; a run with neither keeps its stored status |

A command writes to `artifacts.proof` only when the manifest sets it, and never changes its answer or halts over it:
a page that cannot be amended is one line on stderr. `finish`'s and `returned`'s `done` carries `proof`
(`artifacts.proof`, or null), the page the session marks ready. A status or a cost written into a filed run is no
filing: `revision` and `updatedAt` stay as they were. A change of status to `halted` or `ready`, by any writer above,
raises the run's `attention` instead, which the index compares (*What changed since the last look*); `halted` again,
any other status and a cost raise nothing. A run filed with no status reads as its `prState` says: `MERGED`
Merged, `CLOSED` Closed, else Running.

## The index

**The index** shows the open runs by attention: `halted` first, then `ready`, then the rest, each newest first;
merged and closed runs are hidden until *Show merged and closed (n)* is ticked. It filters by repo and by status (a
chosen `merged` or `closed` shows those runs whatever the toggle says), searches title, PR number, branch and client
summary, and sorts by a click on a column header (a second click reverses; a reload restores the attention order).
The repo filter, the status filter and the toggle are remembered per browser (`proof:repo`, `proof:status`,
`proof:finished` in `localStorage`); the search and the sort are not. Per run it shows the status, PR, page, shots,
time and cost, and its last filing as `d-m H:i` in the browser's time with the full timestamp on hover, and copies
its client summary.

**What changed since the last look** is per browser: opening a page stores the run's `revision + attention` under
`seen:<repo>/<run>` in `localStorage` (`file://` is one origin in Chrome). The index reads a run as unread when this
browser never opened it (*New*), or when since it was opened it was filed again (*Updated*) or its status turned
`halted` (*Halted*) or `ready` (*Ready*); `merged`, `closed` and `running` never make a run unread. The word names
what the row needs now: *Halted* or *Ready* by the run's current status before *Updated*. Unread rows are bold with a
filled dot before the title, read rows muted with an outline dot. The dot marks a run read (it stores the run's
number, as opening the page does) or unread by hand (it stores `0`, shown as *Unread* on a run that is neither
halted nor ready), and a seen `ready` run drops among the rest. The heading counts the unread runs that are not
merged or closed (`3 unread`), whatever the filters show. A run filed without a `revision` gets no dot and no
marker. Without `localStorage` nothing is marked, no dot shows, and the order is the status order. Open it with
`~/Applications/Proofs.app` (Alfred, Spotlight or the Dock; `README.md` §Proofs app).

## The open index tab

Every store write (a filing, `handoff`'s included, a status, the prune pass, a cost) writes
`status.js` beside `index.html` from the same scan (`proof_store_index()`): per run its key (`<repo>/<run>`), status,
revision, seen number (`revision + attention`), a hash of the run as stored, and its row as the index renders it. The
index, opened by hand and left open in a tab, loads it every 30 seconds, and at once when the tab becomes visible or
the window gains focus, through a `<script src="status.js?t=<now>">` (`fetch()` is refused over `file://`). It
replaces the rows whose hash changed, inserts new runs and removes pruned ones in place, without a reload or a lost
scroll position, then marks, orders and filters every row again. Its title counts the unread runs (§The index)
that are not merged or closed, the number the heading shows, whatever the filters show (`(2) Proofs`, else
`Proofs`), and its favicon is a dot: red when one of them is halted, else green when one is ready, else blue, else a
grey ring. A run leaves both once its page is opened and the index is looked at again, or once it is marked read on
the index. An empty store's index reloads itself once a run appears. Two limits: the signal exists only while the index
tab is open, and Chrome throttles timers in background tabs, so a change can take a minute or so to show.

## Retention

The prune pass runs after every `proof_cli.php write` and on `proof_cli.php prune`. It corrects each
run's status from `gh` first (§Statuses), then removes a run whose status is `merged` or `closed` 7 days after its last
filing (`updatedAt`), and a run that opened no PR 14 days after its last filing, whatever its status; a run whose PR
is still open is never removed. A status or cost amendment is no filing, so a run that waited longer than 7 days for
its merge goes on the first pass after it.

## Time and cost

After an `autoflow` run, `run_cost_cli.php <transcript dir> <page>` files its figures into
`cost`, one entry per workflow keyed by the transcript dir's name: filing it again changes nothing, and a resume or a
CI fix round adds its own. The page shows them per step under *Time and cost*, the index the summed spans and cost.
An `interactive` run has none.

## The store is never load-bearing

**The store is never load-bearing for a gate.** Failing to *capture* proof still halts the run;
failing to *file* it logs and continues. The pipeline never reads the store to decide anything:
deleting all of `_proofs/` changes no run's behaviour, which is what keeps a durable store
compatible with the non-goal "no persistent state not reconstructable from git + gh".

## No page opens by itself

A run's report names its page: the finish step's reply in `interactive`, the invoking
session's report after `finish` in `autoflow` (the `proof` that `finish`'s `done` carries, else `artifacts.proof`),
and a halt's report the same. The store index, opened by hand and left open, shows what changed
(§The open index tab), so a day of runs is one tab, not a tab per run. `php checks/proof_cli.php open <page>` opens a page by
hand. It is cosmetic, weaker than every other proof policy: failing to *capture* proof halts a run, failing to
*file* it logs and continues, and `open` returns 0 on every path (no page, a platform with no opener — `open` on
macOS, `xdg-open` on Linux, nothing anywhere else —, an opener that fails). The page path reaches the store from a
JSON payload, so `open` never builds a shell string from it: it hands `proc_open()` an argv **array**, which runs
without a shell. **`PIPELINE_OPEN_CMD`** replaces the platform default with an executable that receives the page
path as its single argument.
