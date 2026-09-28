# A CI gate on the PR's head commit before `gh pr ready` — design

**Design size:** Architectural

**Date:** 2026-09-28
**Issues:** IT4WEBBV/LaravelClaudeMd#85 and #77 (one run, one PR, closing both; owner, 2026-09-25)
**Canonical home:** `skills/pipeline/checks/ci.php` (the verdict), the `ci` command and `launch --decision`
in `skills/pipeline/checks/dispatch_cli.php`, the brief lines in `skills/pipeline/checks/brief.php`, and a
new section of pipeline `references/engine.md`, §The CI gate, that holds the rule and why.
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. Every question the brainstorm
would have asked is answered in *Assumptions*, so `/critique plan` audits exactly those. Nothing below was
built or run; the `gh` output shape it relies on was read from a real PR (see *What was read*).

## Problem

A `/pipeline autoflow` run can mark its PR ready while CI still runs on the PR's head commit, and a red
result afterwards goes unnoticed (#85: markverg/HeaderHarbor#194, head `398e4db` pushed by
`review-pr:resolve`, CI red at 18:16Z, found by reading the PR by hand).

- **The only CI wait is inherited.** `implement` waits on CI after its own push through `work-on`'s leg 8
  (`gh pr checks --watch`); nothing in `skills/pipeline` or `skills/orchestrate` prescribes a wait.
- **Later pushes are unwatched.** `verify-ui` and `review-pr:resolve` push after `implement`.
- **Going ready ignores CI.** The invoking session runs `gh pr ready` once `finish` prints `done`
  (pipeline `SKILL.md` §`autoflow`, step 5).
- **The merge watch sees no CI.** It polls `state` only.

And the one wait there is costs time for no reader (#77): in both viewiemedia `autoflow` runs `implement`
spent 6.8 and 7.6 of its ~21 minutes watching CI, while `review-pr:review` reads the diff, not CI.

## Settled direction (owner, 2026-09-25, on #85)

- `implement`'s `autoflow` brief no longer waits on CI after its push; `review-pr:review` runs while CI runs.
- One CI gate on the PR's head commit, just before `gh pr ready`, in the session that runs `gh pr ready`:
  a tested command prints a verdict for the head sha — green, red (failing checks and run link), pending,
  or no CI — and the session waits on it in a background shell, not in a step.
- Green, or no CI in the repo: `gh pr ready` as today.
- Red: one automatic fix round. The failure goes into the manifest's `decisions` verbatim (sha, failing
  check, run link), then `launch --from review-pr` and a new workflow; the round fixes it or shows it is
  unrelated. Red again after that round: the PR stays draft and the run halts with the failing check.
- The merge watch stays on `state`.

## Approaches

1. **A read-only `ci` command that answers what to do next, polled by a dumb shell loop (chosen).**
   `dispatch_cli.php ci <manifest> --poll <n>` reads the PR once and prints one JSON line whose `action`
   is `wait`, `ready`, `fix` or `halt`. Every rule — what counts as red, how long "no checks yet" may last,
   the hour's bound, whether the fix round is spent — is tested PHP; the shell only repeats the call while
   the answer says `wait`. The command writes nothing, so a repeated or interrupted poll changes nothing.
2. **A `ci` command that waits itself (`--wait`) and sleeps inside PHP.** One call, but the waiting and the
   `sleep` sit inside the tested code, which then needs an injected clock to test, and the owner asked for a
   command that prints a verdict and a session that waits on it.
3. **The gate inside `review-pr:resolve`** (#77's first proposal). The step would wait on CI on the critical
   path again, and a workflow agent is not the session that runs `gh pr ready`. The owner settled on the
   session (direction above).

## Design

### `ci.php`: the verdict and the answer (pure)

- `pipeline_ci_check(array $item): array{name, link, state}` classifies one `statusCheckRollup` item.
  A `CheckRun` (`__typename`) is `pending` until `status` is `COMPLETED`, then `green` for conclusion
  `SUCCESS`, `NEUTRAL` or `SKIPPED` and `red` for anything else (`FAILURE`, `TIMED_OUT`, `CANCELLED`,
  `ACTION_REQUIRED`, `STARTUP_FAILURE`, `STALE`). A `StatusContext` is `green` on state `SUCCESS`,
  `pending` on `PENDING` or `EXPECTED`, `red` otherwise (`FAILURE`, `ERROR`). The name is
  `<workflowName> / <name>` for a check run (`CI / ci`), the `context` for a status; the link is
  `detailsUrl` or `targetUrl`.
- `pipeline_ci_verdict(array $rollup): array{verdict, failing, pending}`: `none` when the rollup is empty,
  else `red` when any check is red (red wins over pending, as `gh pr checks --fail-fast`), else `pending`
  when any is pending, else `green`. `failing` lists `{name, link}`; `pending` lists names.
- `pipeline_ci_answer(array $manifest, ?array $view, bool $workflows, int $poll): array` — `$view` is
  `gh pr view <pr> --json headRefOid,statusCheckRollup` (null when gh failed), `$workflows` whether the
  worktree has `.github/workflows/*.yml|yaml`, `$poll` this read's number from 1:

  | Verdict | Answer |
  |---|---|
  | `green` | `ready` |
  | `none` | `ready` — at once without workflows; with workflows only from read 3 (`PIPELINE_CI_NONE_POLLS`), before that `wait`: GitHub registers a push's checks a few seconds after it |
  | `pending` | `wait`; `halt` at read 120 (`PIPELINE_CI_POLLS`, an hour at 30 s): *CI on `<sha>` has not finished after an hour: `<names>`* |
  | `red`, no CI decision yet | `fix`, with `decision`: `CI red on the PR's head commit <sha>: <name> failed (<link>)`, several joined by `; ` |
  | `red`, a CI decision already there | `halt`: *CI red again after the fix round, on `<sha>`: `<failures>`* |
  | gh could not read the PR (`unreadable`) | `wait`; `halt` at read 120: *CI on PR #`<pr>` had not settled after an hour, and gh could not read its checks at the last read* |

  Every answer carries `action` and `verdict`; a read answer carries `sha`; `fix` and a red `halt` carry
  `failing`. A `halt` carries `leg: review-pr` and `reason`, so it is `finish`'s input as it stands.
- `pipeline_ci_rounds(array $manifest): int` counts the `decisions` that start with
  `PIPELINE_CI_RED` (`CI red on the PR's head commit `). One is the round spent.

### `dispatch_cli.php`

- **`ci <manifest> [--poll <n>]`** (`n` a positive integer, default 1; anything else is a usage error,
  exit 1). Halts, touching nothing, on an unreadable or invalid manifest, the retired `auto` mode, or no
  `artifacts.pr`. Serves `autoflow` and `interactive` manifests. Reads the PR through
  `dispatch_cli_pr_view()`, which gains a `$fields` parameter (default `state,isDraft`, today's), and
  prints `pipeline_ci_answer()` as one JSON line. **It never writes the manifest.**
- **`launch … [--decision <text>]…`**: each `--decision` is appended verbatim to `decisions`, in the same
  write as `--from`'s re-arm, after `--from`'s checks (a refused `--from` writes nothing). The argument
  parsing moves from `array_search('--from', $argv)` into `dispatch_cli_launch_args()`, shaped like
  `dispatch_cli_kickoff_args()`. This also replaces `orchestrate`'s `php -r` one-liner for *commits
  wanted on a ready PR*.
- The usage string and the header docblock name both.

### Brief lines (`pipeline_leg_overrides()` and `pipeline_brief_overrides()`)

- **`implement:run`, `autoflow`:** ``Add the `ci` label (`gh pr edit <pr> --add-label ci`) before your
  first push, in a repo that has one, and do not wait on CI after it: this overrides `work-on`'s CI watch;
  the CI gate reads the PR's head commit before the PR goes ready (engine.md §The CI gate).``
  `interactive` keeps today's line (*before the push whose CI you watch*).
- **`review-pr:resolve`, `autoflow`**, the finish line becomes: ``Push your commits and leave the PR
  draft; the session that launched the run marks it ready after the CI gate (engine.md §The CI gate).``
  followed, as today, by *The last action is `proof_cli.php open` …*.
- **`review-pr:resolve`, `interactive`**: ``Run the CI gate (engine.md §The CI gate) and `gh pr ready`
  when it answers `ready`; show any other answer to the human.`` then the proof line.
- **The fix round** — on `review-pr` when `pipeline_ci_rounds()` is at least 1, `pipeline_brief_overrides()`
  adds one line per step:
  - `review`: ``The settled `CI red on the PR's head commit` decision is a finding of this review: read the
    failing job's log (`gh run view <run> --log-failed`, the run id from its link) and state the failure and
    its cause (engine.md §The CI gate).``
  - `resolve`: ``Fix the `CI red` finding, or show it is unrelated to this change (the same failure on the
    base branch, or a flake: start `gh run rerun <run> --failed` and do not wait on it), and say which in
    `actions`; the CI gate reads the head commit again (engine.md §The CI gate).``

### The session's side (docs)

The invoking session, after `finish` prints `done`:

```bash
poll=1; while answer=$(php "$CHECKS/dispatch_cli.php" ci <manifest> --poll $poll); echo "$answer" | grep -q '"action":"wait"'; do sleep 30; poll=$((poll + 1)); done; echo "$answer"
```

in one background Bash, waiting for its completion notice. Then: `ready` → `gh pr ready <pr>`;
`fix` → the diff, `launch <manifest> <diff> --from review-pr --decision "<its decision>"`, a new
`pipeline-autoflow` workflow, and on its return `finish` and the gate again; `halt` →
`finish <manifest> '<the answer>'`, which records it on `review-pr`, then §Failure policy's duties after
`handoff` (the reason into the PR body; the PR stays draft). An empty answer (a usage error) is a halt.

Docs that change: engine.md gains **§The CI gate — CI on the PR's head commit, before `gh pr ready`**
(after §Who takes the PR out of draft) and is updated in §`autoflow` (diagram and the `finish` bullet),
§Stations (the `implement` and `review-pr` rows), §Who takes the PR out of draft (the `ci` label paragraph,
the `autoflow` paragraph), §What a leg brief consists of (the `autoflow` paragraph) and §Failure policy (a
stop: red after the round, or not settled in an hour). Pipeline `SKILL.md` §`autoflow` step 5,
`orchestrate` `SKILL.md` step 5 and `references/commands.md` §Launch and §Finish, and `manifest.md`'s
`decisions` row follow.

### What does not change

`pipeline-autoflow.js` (the gate is the session's, not a step), `dispatch.php`'s routing, `finish`,
`run_audit.php`, the merge watch (`state` only), `work-on` (a colleague's repository: the brief overrides it).

## Tests

Written first, each seen red:

- `CiTest.php` (new): the classification per check kind and conclusion; red over pending; `none` on an
  empty rollup; every row of the answer table, the bounds at reads 2/3 and 119/120; the decision text and
  `pipeline_ci_rounds()`.
- `DispatchCliTest.php`: `ci` against a fake `gh` first on `PATH` (as the kickoff tests do) — ready on
  green, a fix answer carrying the verbatim decision, a halt after a recorded round, `wait` on no checks
  with workflows and `ready` without, the manifest byte-identical after every answer, halts without a PR,
  usage errors; `ci` joins the retired-`auto` dataset. `launch --decision` appends verbatim with
  `--from`, and a refused `--from` leaves `decisions` alone. `finish` with the gate's halt answer on a
  `done` manifest records `halted` on `review-pr`.
- `BriefTest.php`: the `implement` lines per mode (the `autoflow` brief no longer says *the push whose CI
  you watch*: #77's first Done-when line), the finish lines per mode, and the fix-round lines only when a CI
  decision is there. `LockStepTest` checks `§The CI gate` against engine.md's headings.

The whole pipeline suite passes. #77's second Done-when line (no CI wait in a run's `implement`, measured
by `run_cost_cli.php`) is measured on the next runs, not in this PR.

## What was read

- `gh pr view <n> --json headRefOid,statusCheckRollup` on IT4WEBBV/viewiemedia's latest merged PR: items
  with `__typename: "CheckRun"`, `name`, `workflowName`, `status`, `conclusion`, `detailsUrl`
  (`…/actions/runs/<run>/job/<job>`); one `SKIPPED` beside two `SUCCESS`. On this repository (no
  `.github/workflows`) the rollup is `[]`.
- `work-on`'s `engine/chain.md` leg 8 and `engine/wrapup.md` R4: the CI watch the `implement` brief
  overrides.

## Out of scope

- Whether the worktree pushed everything: the gate reads the PR's head as GitHub has it. The `autoflow`
  finish line now says to push; a check of `HEAD` against `headRefOid` is its own issue if it is needed.
- A `launch` that answers `done` on resume does not run the gate.
- `work-on` itself.

## Assumptions

Questions the brainstorm would have asked the owner, with the answer assumed.

1. **Does `interactive` get the gate?** Yes, without the automatic round: its finish step runs the same
   loop before `gh pr ready` and shows any other answer to the human. "In the session that runs `gh pr
   ready`" covers it, and #77 allows `interactive` changes that need no extra routing. Its `implement`
   keeps `work-on`'s CI watch.
2. **Where does the round count live?** In `decisions`, by the prefix `CI red on the PR's head commit `,
   as the owner put the failure there; no new manifest key. A run the owner resumes after a CI halt halts
   again on the next red: the round is spent per run.
3. **Who writes the decision?** `launch --decision`, not `ci`. `ci` stays read-only, so polling it twice,
   or re-running the loop after a dead session, cannot turn a first red into "red again".
4. **How does the gate tell "no CI" from "not registered yet"?** By `.github/workflows/*.yml|yaml` in the
   worktree: without it no Actions check can come, so `none` is final at once (this repository); with it
   `none` is final from the third read (~1 minute). A repo whose only CI is an external status and no
   workflow would be read as no CI when the status is not there yet; none of the owner's repos is such.
5. **Interval and bound?** 30 s between reads, 120 reads (an hour). viewiemedia's CI takes ~7 minutes.
6. **What is red?** Any conclusion but `SUCCESS`, `NEUTRAL` and `SKIPPED`, including `CANCELLED` (a
   cancelled check on the head commit tested nothing). A skipped check reads green, so the `ci` label must
   be on before the push; that stays the `implement` line's job (engine.md §Who takes the PR out of draft).
7. **Does the rollup show a rerun's latest attempt?** Assumed yes (`gh pr checks` does); not probed, as it
   needs a failing run. If it did not, the gate would read red again and halt: fail-closed, for the owner.
8. **Is the round's review a full `/critique pr` review?** Yes: the owner named `launch --from review-pr`,
   which starts at the review step. The fix-round lines make the failure a finding of that review.
9. **Replace `orchestrate`'s `php -r` one-liner with `launch --decision`?** Yes: the same write, now
   tested, and the gate needs the option anyway.
10. **Does a run waiting on its gate count toward `orchestrate`'s four working runs?** Yes: it may still
    need a fix round.
11. **#77's Done-when asks that `review-pr:resolve`'s brief waits on CI.** Superseded by the owner's
    direction: the gate is the session's. `BriefTest` pins that the finish line names the gate instead.
12. **Changelog?** This repository has no `.changelog/` and no `CHANGELOG.md`, so none is written.
