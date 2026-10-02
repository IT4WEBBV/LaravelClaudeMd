# The prune pass asks GitHub about old-scheme runs by their `repo` — design

**Design size:** Architectural (the run requires the Architectural path; the change itself is one helper and one call site)

**Date:** 2026-10-02
**Issue:** IT4WEBBV/LaravelClaudeMd#161
**Canonical home:** `skills/pipeline/checks/proof.php` (a new `proof_run_name_with_owner()`),
`skills/pipeline/checks/proof_cli.php` (`proof_cli_pr_view()`), `skills/pipeline/checks/tests/ProofTest.php`,
`skills/pipeline/checks/tests/ProofStatusTest.php`, pipeline `references/engine.md` §The proof store (the status
table's prune-pass row and *Retention*).
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. The issue body is the approved scope and
is not re-litigated here. Every question the brainstorm would have asked is answered in *Assumptions*, so
`/critique plan` audits exactly those. Nothing was built or run; what the design relies on was read (see *What was
read*). No probe was needed: no approach hinges on whether it works at all, and every behavioural claim below is
visible in the code read.

## Problem

The prune pass (`proof_cli_prune()`) corrects every run's status from GitHub before it decides to prune it:
`proof_cli_refresh()` asks `proof_cli_pr_view()`, which returns null, and so skips the run, when the run has no
`nameWithOwner`:

```php
if (empty($run['pr']) || empty($run['nameWithOwner'])) {
    return null;
}
```

Runs filed under the earlier naming scheme have no `nameWithOwner`; their `repo` holds `owner/name` itself
(`"repo": "IT4WEBBV/Deploy"`, filed under `_proofs/IT4WEBBV-Deploy/`, since `proof_slug()` turns the slash into a
hyphen). For such a run nothing ever corrects the status:

- with no stored `status`, `ProofRunStatus::of()` falls back to `prState`, which was `OPEN` when it was filed, so
  the page and the index say **Running** forever, even after the PR merged;
- `proof_should_prune()` gives an open-PR run no retention (`default => null`), so it is never pruned either.

Two such runs (`IT4WEBBV-Deploy/pr-404-reverb-service-type`, merged 2026-08-28, and
`IT4WEBBV-Deploy/pr-434-keep-service-form-open-after-create`, merged 2026-09-17) sat at the top of the store index as
Running on 2026-10-02. The owner deleted both by hand the same day (manifest decision), so the fix is verified on a
fixture.

## Approaches

1. **A pure helper that names the run's GitHub repo, used by `proof_cli_pr_view()` (chosen).**
   `proof_run_name_with_owner(array $run): ?string` in `proof.php`: `nameWithOwner` when it is set, else `repo` when
   it has the `owner/name` form, else null. `proof_cli_pr_view()` asks `gh` with whatever it returns and returns null
   when it returns null. The rule is one pure function with its own dataset test beside `proof_should_prune()`'s;
   the impure CLI only calls it. Nothing is written that was not written before.
2. **Inline the fallback in `proof_cli_pr_view()`.** Same behaviour, but the `owner/name` rule then lives in the
   impure file, testable only through a subprocess and a fake `gh`, one case per shape. Rejected: `proof.php` exists
   so rules like this are testable without touching anything (`proof_cli.php`'s header says so).
3. **Backfill `nameWithOwner` into old-scheme `run.json` files** (in the refresh's amend, or a one-off migration).
   Changes stored data to fix a read, and every old-scheme run is a finished one on its way out of the store: once
   the refresh stores `merged` or `closed`, retention removes it within 7 days of its last filing, which for these
   runs is already past. Rejected as surface for a one-off.

## Design

### The helper

In `proof.php`, beside `proof_should_prune()`:

```php
/**
 * The `owner/name` a run's PR lives in: its `nameWithOwner`, else a `repo` filed under the earlier naming scheme,
 * which held `owner/name` itself (#161). Null for a bare repo name: there is no PR to ask GitHub about.
 */
function proof_run_name_with_owner(array $run): ?string
```

- `nameWithOwner`, trimmed, when it is not empty: every run `handoff` files today has it (`handoff.php`), so their
  behaviour is unchanged.
- else `repo`, trimmed, when it matches `~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~`: exactly one slash with a non-empty
  owner and name on either side, and none of the characters GitHub rejects in either.
- else null.

### The call site

`proof_cli_pr_view()` takes the repo from the helper:

- `empty($run['pr'])` or a null helper result: return null, no `gh` call, as today;
- otherwise the argv is `['gh', 'pr', 'view', <pr>, '--repo', <helper result>, '--json', 'state,isDraft']`, still an
  argv array, never a shell string.

Its docblock gains one clause: the repo comes from `proof_run_name_with_owner()`, so an old-scheme run is asked
about like any other.

Nothing else changes. `proof_cli_refresh()` already amends `prState` and `status` for whatever `gh` says and
returns the corrected run; `proof_cli_prune()` already hands that run to `proof_should_prune()`, which prunes a
`merged` or `closed` run whose `updatedAt` is more than 7 days old. `proof_store_amend()` derives every path from the
page's directory (`dirname($page)`, `dirname($page, 3)`), never from `repo`, so a run under `IT4WEBBV-Deploy/` is
amended in place.

### The doc

engine.md §The proof store:

- the status table's prune-pass row (`any | the prune pass, on prune | gh pr view …`) gains: *a run filed before
  `nameWithOwner` existed is asked about by its `repo` when that holds `owner/name`; a run with neither keeps its
  stored status*;
- *Retention* needs no change: it already says the pass corrects each run's status first.

## Testing

Pest, test first.

**`ProofTest.php`**, a dataset case for `proof_run_name_with_owner()`:

| run | returns |
|---|---|
| `nameWithOwner: IT4WEBBV/Deploy`, `repo: Deploy` (today's scheme) | `IT4WEBBV/Deploy` |
| `nameWithOwner: IT4WEBBV/Deploy`, `repo: acme/Other` (nameWithOwner wins) | `IT4WEBBV/Deploy` |
| no `nameWithOwner`, `repo: IT4WEBBV/Deploy` (old scheme) | `IT4WEBBV/Deploy` |
| `nameWithOwner: null`, `repo: IT4WEBBV/Deploy` | `IT4WEBBV/Deploy` |
| `nameWithOwner: '  '`, `repo: IT4WEBBV/Deploy` | `IT4WEBBV/Deploy` |
| no `nameWithOwner`, `repo: Deploy` | null |
| no `nameWithOwner`, `repo: IT4WEBBV/Deploy/extra` | null |
| no `nameWithOwner`, `repo: /Deploy` | null |
| neither key | null |

**`ProofStatusTest.php`**, as subprocesses through `proof_status_cli(['prune'], …)`:

- `proof_fake_gh()` also appends its arguments, one per line, to the file named by a `PROOF_GH_LOG` key it adds to
  the env it returns (`{$bin}/argv`); every existing caller keeps working, since the extra env key is ignored by
  everything else.
- The old-scheme fixture is a `run.json` written directly (`proof_run_json()` into
  `{$root}/IT4WEBBV-Deploy/pr-404-reverb-service-type/run.json`), not through `proof_write_run()`, which would fill in
  a `status` (`$run['status'] ??= …`): `repo: IT4WEBBV/Deploy`, no `nameWithOwner`, no `status`, `pr: 404`,
  `prState: OPEN`, `branch`, `title`, `schema: 2`, `revision: 1`, `createdAt` and `updatedAt` as the case sets them.
  A helper local to the file writes it, taking `updatedAt` and any overrides.

Cases:

1. **an old-scheme run is asked about by its repo, and gh's merge is stored** (`updatedAt` one day ago, `gh` answers
   `MERGED`): the log is exactly `pr`, `view`, `404`, `--repo`, `IT4WEBBV/Deploy`, `--json`, `state,isDraft`;
   `run.json` holds `prState: MERGED` and `status: {state: merged}`; the run's `index.html` exists and carries
   `pill-merged`; `status.js` reports it `merged`; stdout is `proof: pruned 0 run(s)`.
2. **an old-scheme run gh reports merged is pruned once its last filing is more than a week old** (`updatedAt` eight
   days ago, `gh` answers `MERGED`): stdout is `proof: pruned 1 run(s)`, the directory is gone, and the store index no
   longer links `IT4WEBBV-Deploy/pr-404-reverb-service-type/index.html`.
3. **a run with a bare repo and no nameWithOwner is left alone** (the fixture with `repo: Deploy` under
   `{$root}/Deploy/pr-404-…`, `updatedAt` eight days ago, the logging fake `gh` answering `MERGED`): no log file
   exists (no `gh` call), `run.json` is byte-for-byte what was written, the directory stays, stdout is
   `proof: pruned 0 run(s)`.

The existing cases keep passing unchanged: each that reaches `gh` sets `nameWithOwner`, which still wins.

No browser check: no renderer, style or script changes; an amended old-scheme page is rendered by the same
`proof_render_run()` every amended page goes through, and case 1 asserts its pill.

## Done when

- `proof_cli_pr_view()` asks `gh` with `--repo <owner/name>` for a run whose `repo` holds `owner/name` and which has
  no `nameWithOwner`, and stores what GitHub says through the existing refresh.
- Such a run, merged or closed and last filed more than 7 days ago, is pruned on that same pass.
- A run with neither is untouched and causes no `gh` call.
- The dataset case and the three CLI cases pass, and the suite is green.
- engine.md's prune-pass row names the fallback.

## Assumptions

Each question the brainstorm would have asked the owner, and the answer assumed.

1. **Fix the read or migrate the data?** Fix the read (Approach 1). The issue asks for a fallback in
   `proof_cli_pr_view()` "or the run it is given"; a backfill writes stored data for runs that leave the store within
   a pass of the fix.
2. **What counts as the `owner/name` form?** Exactly one slash, a non-empty owner and name, each made of letters,
   digits, `-`, `_` and `.` (GitHub's own character set for owners and repositories). Anything else, including a
   bare name, two slashes or whitespace inside, is treated as having no owner. The argv form already rules out shell
   injection; the pattern exists so a `repo` that is not a GitHub name never reaches `gh`.
3. **Does `nameWithOwner` still win when both are set?** Yes: it is what `handoff` files from the PR's URL, and
   today's `repo` is a bare name anyway. A whitespace-only `nameWithOwner` counts as missing (trimmed), where today it
   reached `gh` and failed there; no filing writes one, so this changes nothing seen.
4. **Should the page and the index link old-scheme runs to GitHub too (`proof_github_url()` reads only
   `nameWithOwner`)?** No. The issue is about the status and the prune; the runs it names are finished ones the fixed
   pass removes. Using the helper in `proof_github_url()` is a one-line follow-up if an old-scheme run ever stays.
5. **Should the refresh write `nameWithOwner` into the run it amends?** No: the amend stays `prState` and `status`
   only, as for every other run (Assumption 1).
6. **Where does the helper live?** `proof.php`, with the other pure rules over a run (`proof_should_prune()`,
   `proof_run_seen()`), so it is loaded by `Pest.php` and by `proof_cli.php` (through `proof_store.php`, which
   requires `proof.php`) alike. Named for what it returns, as `gh` names the field.
7. **How does a test see the `gh` arguments?** The fake `gh` appends `"$@"` to a log the env names, so a case reads
   the exact argv and a missing log proves no call. A separate fake for logging would duplicate the existing one.
8. **Why write the fixture's `run.json` directly?** `proof_write_run()` fills a missing `status` from `prState`, so
   it cannot produce the shape the issue describes (no stored `status`). The existing "an old run without a status"
   case has the same blind spot: it files `status: null` through `proof_write_run()`, which stores `running`.
9. **Is a changelog entry needed?** No: this repo has neither `.changelog/` nor `CHANGELOG.md`.
10. **Should the two deleted runs be restored to verify on them?** No: owner decision, 2026-10-02, verify on a
    fixture.

## Relation to other work

- **#160** (merged, 48a9f7d) reworked the index's unread rule; this change touches no renderer and no script.
- The earlier store rename (repo *and* run in the directory key) is what left old-scheme runs behind; this spec does
  not rename or move any directory.

## What was read

The issue and the manifest's decision; `proof_cli.php` (whole: `proof_cli_pr_view()`, `proof_cli_refresh()`,
`proof_cli_prune()`, `proof_cli_rmdir()`); `proof.php` (`ProofRunStatus::of()`, `corrected()`, `stored()`,
`proof_should_prune()`, `proof_scan_runs()`, `proof_run_dir()`, `proof_read_run()`, `proof_write_run()`);
`proof_store.php` (`proof_store_amend()`, `proof_store_status()`); `proof_render.php` (`proof_github_url()`);
`handoff.php` (where `nameWithOwner` is filed); `tests/Pest.php` (`proof_test_page()`); `tests/ProofStatusTest.php`
(whole: `proof_status_cli()`, `proof_fake_gh()`, the refresh and prune cases); `tests/ProofTest.php`
(`proof_should_prune()` cases); engine.md §The proof store (status table, *Retention*, the payload schema).
