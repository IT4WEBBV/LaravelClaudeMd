# `format` once per `implement` step, and Pint's cache measured — design

**Design size:** Architectural

**Date:** 2026-09-28
**Issue:** IT4WEBBV/LaravelClaudeMd#79
**Canonical home:** the `implement:run` line in `skills/pipeline/checks/brief.php`, and pipeline
`references/engine.md` §Mechanical checks, which holds the rule and the measurement behind it.
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. Every question the brainstorm
would have asked is answered in *Assumptions*, so `/critique plan` audits exactly those. Nothing below was
built or run; the Pint timings come from a throwaway probe in viewiemedia's slot-2 container and from the
viewiemedia run transcripts (*What was measured*).

## Problem

`implement` runs a repo's `format` check after every plan step (`brief.php`: *"after each step the suite
and the mechanical checks"*; engine.md §Mechanical checks, *What runs, and when*). viewiemedia declares
`format: docker exec viewiemedia<N>_web ./vendor/bin/pint`, whole-tree, because git is not in the
container and `--dirty` cannot work there. The issue reads 1.5–1.7 minutes per call from the #2077 run,
three calls in its `implement`.

## Settled direction (owner, 2026-09-25, on #79)

1. `format` runs once per `implement` step, before the push, over the whole tree as today.
2. The design measures Pint's cache inside viewiemedia's container: whether a cache on a mounted path
   makes a whole-tree run near-instant after the first. If it does, viewiemedia declares it in its
   `format` line.
3. The changed-files placeholder only if 1 and 2 together still leave `format` a noticeable share of
   `implement` (~21 minutes on viewiemedia, #78).

## What was measured

**The probe** (2026-09-28, `viewiemedia-2_web`: Pint 1.32.1 on PHP-CS-Fixer 3.95.25, PHP 8.3.30, 4 CPUs,
1299 files), `pint --test`, which reads and never writes the tree:

| Run | Time |
|---|---|
| `--cache-file=/tmp/probe79.cache`, file absent (cold) | 38.8 s |
| the same, again (warm) | 1.3 s |
| the same, a third time | 1.5 s |
| no `--cache-file` (Pint's default cache, written by an earlier run in that container) | ~1–2 s, twice |

- Pint already caches by default: a file in the container's temp dir (`/tmp/<md5>`), keyed by path
  relative to `/var/www` with a content hash per file and a signature of PHP, fixer and rule versions.
  The default file there held 1304 entries. Nothing has to be declared for it.
- viewiemedia's `web` service mounts `/tmp` on the named volume `datavolume`
  (`container/docker-compose.dev.yml`, and `docker-compose.pgk.yml` for `-p`). The cache therefore
  survives a container restart (`restart.sh`) within one stack, and goes with the slot's volumes when
  the slot is removed. It is already on a mounted path.

**The transcripts.** The calls the issue timed chained Pint with PHPStan and the full suite in one Bash
call, so the 1.5–1.7 minutes were mostly the suite:

| Run | Call | Bash call | Of which |
|---|---|---|---|
| #2077 `implement` (2026-09-24, slot 3) | 1 | 92.8 s | Pint + PHPStan, first calls on the fresh stack |
| | 2 | 102.3 s | Pint + PHPStan + suite (suite 91.55 s) |
| | 3 | 96.5 s | Pint + suite (suite 90.49 s) |
| | 4 | 5.7 s | Pint + PHPStan |
| #2116 `implement` (2026-09-28, slot 3) | 1 | 122.2 s | Pint + PHPStan, first calls on the fresh stack |
| | 2, 3 | 16.8 s, 11.7 s | Pint + PHPStan (+ git) |

**What it means.** On viewiemedia `format` costs one cold call per stack (~40 s, ~3% of a 21-minute
`implement`) and about a second per call after it. Moving it to once per `implement` removes the warm
calls, a few seconds a run; it does not remove the cold call, because that one call is the first. A
changed-files list would cut the cold call to ~2 s (a 9-file `pint --test` later in the #2077 run took
2.1 s), at the cost of the host→container path mapping and touched-file tracking engine.md already
declined for `static-analysis`. By direction 3 the placeholder is not built.

## Approaches

1. **The rule in the brief and in engine.md (chosen).** `implement`'s brief line says when `format` runs;
   engine.md §Mechanical checks says it once, with the measurement. When a step runs its checks is a
   brief's instruction today, and the step is the only agent that knows when its code is complete.
2. **The workflow script prompts `format` before the push**, as it prompts `size` and `ui` (step 4 in
   `pipeline-autoflow.js`). Rejected: `autoflow` only, while `interactive` runs the same `implement`; and
   the script would name a check that briefs and engine.md already own (engine.md §What a leg brief
   consists of).
3. **No change: leave `format` after every step, since Pint's cache makes the later calls ~1 s.**
   Rejected by direction 1, which is settled. What once-per-step still buys: one call instead of one per
   plan step, and the style changes land in one place just before the push.

## Design

### When `format` runs

After each plan step: the suite (skipped when §Suite reuse finds the tree green), then `static-analysis`,
as today. `format` runs **once per `implement` step**, over the whole tree, when the step's code is
complete:

- **before the last suite run**, so the `suite` the step records covers the formatted tree. Were Pint to
  reformat after it, the tree key would change and §Suite reuse would run the full suite (~110 s on
  viewiemedia) again at `review-pr`;
- **before the push**, with what it changed committed;
- **once more only after a later change**: a fix for a red suite or a `static-analysis` finding written
  after it.

Everything else in §Mechanical checks stays: the invocation through `pipeline_expand_slot()`, the
whole tree, the two failure kinds, suppressions, nothing in the manifest.

### `brief.php`

`implement:run`, both modes. The line

``Test-first; after each step the suite and the mechanical checks (engine.md §Mechanical checks, §Suite reuse). Record `suite` after every full run.``

becomes

``Test-first; after each plan step the suite and `static-analysis`; `format` once, over the whole tree, when the code is complete: before the last suite run and the push, its changes committed, and again only after a later change (engine.md §Mechanical checks, §Suite reuse). Record `suite` after every full run.``

### Docs

- engine.md §Mechanical checks, *What runs, and when*: the timing above. After the Deploy measurement, a
  paragraph with the Pint measurement: cold ~39 s against warm ~1.3 s over 1299 files, the default cache
  in the container's temp dir on viewiemedia's `datavolume`, so only the first call in a fresh stack
  pays, and a file list would save that one call and nothing after it.
- engine.md §Stations, the `implement` row: *running the suite and the repo's mechanical checks after
  each step* becomes *running the suite and the repo's `static-analysis` after each step and its
  `format` once before the push*.
- Pipeline `SKILL.md`, the *Mechanical checks* bullet: *PHPStan/Pint checks after each step* becomes
  *PHPStan check after each step and its Pint check once before the push*.

### What does not change

`checks.php` (no placeholder, no prefix), `pipeline-autoflow.js`, `dispatch.php`, the `review-pr` lines,
viewiemedia's `## Checks` block, `work-on`.

## Tests

Written first, seen red:

- `BriefTest.php`: the `implement` brief, in `autoflow` and in `interactive`, carries the new line and no
  longer says *after each step the suite and the mechanical checks*.
- `LockStepTest` already checks that §Mechanical checks and §Suite reuse, which the line names, are
  engine.md headings.

The whole pipeline suite passes. The saving is measured on the next viewiemedia runs with
`run_cost_cli.php` (#78), not in this PR.

## Out of scope

- **`review-pr:review` runs the declared checks** (*"run its checks first"*) while its brief says
  *read-only on the checkout*; viewiemedia's `format` line is Pint in fix mode, which writes. Later
  steps of the #2116 run chose `pint --test` themselves. A separate issue if it needs a rule.
- **Commits after `implement`** (`verify-ui`, the resolve steps) run no checks, as today. viewiemedia's CI
  runs `pint --test` on every PR head, so a style slip there reaches the CI gate.
- **A cache shared across slots**, which would make the first call warm too (~39 s a run): a compose
  mount in viewiemedia, with concurrent slots writing one cache file. Not probed; not proposed.

## Assumptions

Questions the brainstorm would have asked the owner, with the answer assumed.

1. **Does `interactive` get the same timing?** Yes. The line is shared by both modes today, and the
   direction names the `implement` step, not a mode.
2. **Where exactly is "before the push"?** After the last code change and before the last suite run,
   so the recorded `suite` stays reusable at `review-pr`. The direction's "before the push" holds, and
   a Pint change after the suite would cost a second full suite.
3. **What if code changes after `format` ran?** It runs once more before the push. "Once" is the normal
   path, not a bound; the fix bound of §Mechanical checks is unchanged.
4. **Does the direction's "a cache on a mounted path" ask for a new mount?** No. The probe shows Pint's
   default cache already works and already sits on a mounted path (`datavolume` on `/tmp`), so
   viewiemedia's `format` line needs no `--cache-file`, and no follow-up is filed in
   IT4WEBBV/viewiemedia.
5. **Is ~40 s once per run (~3% of `implement`) "a noticeable share"?** Assumed no: after the first call
   `format` is about a second, and the one cold call is what the placeholder would shave. The placeholder
   stays unbuilt; the issue's *Done when* lines about it are superseded by the direction.
6. **Is `pint --test` a fair stand-in for `pint`?** Yes for the cache: both walk the same file list
   against the same cache file, and fix mode on an already formatted tree writes nothing. The probe used
   `--test` because slot 2 was another session's checkout.
7. **Does this PR close #79?** Yes: direction 1 is built, 2 is measured and needs no declaration, 3's
   condition is not met. `review-pr` settles the closing link (engine.md §Closing links).
8. **Changelog?** This repository has no `.changelog/` and no `CHANGELOG.md`, so none is written.
