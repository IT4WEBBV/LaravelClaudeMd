# Delete the `auto` engine; `autoflow` is the unattended mode — design

**Design size:** Architectural

Issue #87. Builds on `2026-09-23-pipeline-auto-workflow-design.md` (PR #50, the side-by-side trial and its
keep/revert criteria) and on `2026-09-25-pipeline-autoflow-return-checks-design.md` (#70, merged: it
gave `autoflow` the return check `auto` had at `returned`).

## Problem

Two unattended engines exist side by side:

- `auto`: one background dispatcher agent loops over `dispatch_cli.php next` / `returned`;
- `autoflow`: the saved workflow `pipeline-autoflow` loops, and each step checks the one before it
  through `dispatch_cli.php brief` and `finish`.

The trial is over. PR #50's six `autoflow` runs meet every criterion: a median cost 77 percent below the
baseline (at least 30 required), every `run_audit.php` agrees, no run stalled on a permission, and the
largest `implement` peak is 137k (the revert line is 250k). The owner decided on 2026-09-25 to keep
`autoflow` and delete `auto`. Until `auto` goes, every change to the unattended path is made or checked
twice: the docs describe both, `brief.php` branches for both, and `orchestrate` carries two dispatch,
stall and recovery procedures.

`interactive` stays and keeps `next` / `returned`: the session holds its loop, dispatches the steps
that are not inline, and the human resolves each review.

## The change

### What goes

**The engine itself.** Nothing starts a dispatcher agent any more:

- `engine.md` §The dispatcher — what it does, and never does, and with it the 150k invariant;
- `checks/engine_peak.php`, `checks/engine_peak_cli.php`, `checks/tests/EnginePeakTest.php`, and
  `engine_peak.php` in `tests/Pest.php`'s load list. `run_cost.php` measures peaks for `autoflow` on its
  own and does not use them;
- `/pipeline auto` from `SKILL.md`'s invocation line and from `engine.md` §Design size's;
- `--mode autoflow|auto` from `kickoff`'s documented usage (`engine.md` §Kickoff, `dispatch_cli.php`'s
  docblock and usage line).

**The `auto` branches in code.**

- `pipeline_runs_inline()` (`dispatch.php`): `! in_array($mode, ['auto', 'autoflow'])` becomes
  `$mode !== 'autoflow'`. Anything that is not `autoflow` behaves as `interactive`, which is the
  fail-safe `gates.md` §Modes asks for (a mangled mode is the stricter one).
- `pipeline_leg_overrides()` (`brief.php`): the local `$auto` already means `autoflow`; it is renamed
  `$autoflow`. The two lines that point at `engine.md` §`auto` point at the renamed section (below).
  No brief changes for either remaining mode except that pointer.

**The `auto` half of the docs.**

- `engine.md`: the mode table loses its `auto` row; every "in `auto` and `interactive`" becomes "in
  `interactive`", and every "`auto` and `autoflow`" becomes "`autoflow`" (§The loop, §`autoflow`,
  §Interactive, §Kickoff, §Stations, §Design size, §What a leg brief consists of, §Failure policy).
  "The dispatcher" as the name of an agent goes: the proof-store halt rule names the session that holds
  the run, §Failure policy names `finish` (and `returned` in `interactive`), and *A return the dispatcher
  cannot account for* becomes *A return the checks cannot account for*.
- `engine.md` §`auto` — a fresh agent resolves the review becomes **§Resolving a review — the resolve step
  acts on it**. It keeps what `autoflow`'s resolve steps rely on (a review is prose; act, loop back,
  never interrupt, log) and what `interactive`'s brief points at (the edit/rework boundary, the
  independent read). It drops *Why a fresh agent, not the dispatcher*: in `autoflow` every step is a
  fresh agent by construction, so the paragraph argues against an engine that no longer exists.
- `gates.md` §Modes: two modes, `autoflow` described on its own rather than "as `auto`, but …";
  §Loop-backs and §How a run calls Phase A name `interactive` alone for `pipeline_returned()`.
- `manifest.md`: `mode` is `interactive` or `autoflow`; `cursor.retried` exists only in `interactive`;
  the ledger, *What a leg writes*, *Invariant check* and *Reconstruction* rows lose their `auto`
  wording.
- `SKILL.md`: the core principle describes `autoflow` and `interactive`; the 150k-invariant bullet goes;
  §`autoflow`'s side-by-side paragraph becomes one saying the trial kept `autoflow` and #87 removed
  `auto`.
- `orchestrate`: `SKILL.md`'s description, the `[auto|autoflow]` argument, Steps 3, 5 and 7, the rules
  and the common-mistakes table keep only `autoflow`'s procedure (one workflow per run, `TaskStop` on a
  stall, `launch --from review-pr` for commits on a ready PR). `references/commands.md` loses §Brief
  (the `auto` dispatch) and the agent-id half of §Owner; §Launch and §Finish lose their "`autoflow`,"
  qualifiers. `SendMessage` stays where it serves adopted sessions (§Watch, *Adopted sessions*).
- `browser-verification/SKILL.md`: "an `auto` run's subagent output is never read" becomes "an
  unattended run's step output is never read".

### What stays

- `next` and `returned`, `pipeline_returned()`, `pipeline_route()`, `pipeline_loop_back()`, the retry of
  an empty review and `cursor.retried`: `interactive` runs on them, and `autoflow`'s `tables` are built
  from the same functions (`pipeline_routing_tables()`).
- The word "dispatcher" in `dispatch.php`'s and `dispatch_cli.php`'s comments and in the halt message
  *"…, which only the dispatcher writes"*. There it names `dispatch_cli.php`, the messages are pinned by
  the `interactive` tests that must not change, and renaming them buys nothing.
- The historical specs and plans under `docs/superpowers/` (records of what was built).

### A manifest or a kickoff that still says `auto`

`mode: auto` must not silently become `interactive` through the fail-safe: a run started unattended
would suddenly wait for a human, inline, in whatever session picked it up. So it is refused, by name.

`pipeline_retired_mode(string $mode): ?string` in `dispatch.php`, pure, returns for `auto`:

> mode auto was removed; autoflow is the unattended mode: kick off without --mode, and resume an auto
> manifest by setting its mode to autoflow and running launch

and null for every other mode. Every command answers it as `{"action":"halt","reason":…}` and writes
nothing:

| Command | Where the check sits |
|---|---|
| `kickoff … --mode auto` | `dispatch_cli_kickoff()`, after parsing and before `pipeline_kickoff()`: no gh call, no worktree, no branch |
| `next` | first, beside today's *"an autoflow run resumes with launch, not next"* |
| `returned` | after the snapshot, the manifest and the diff are read, on the snapshot's `mode` |
| `launch`, `brief`, `finish` | `dispatch_cli_mode_problem()`, which already refuses every mode but `autoflow`; the retired check comes first, so `auto` gets its own message instead of *"resume it with /pipeline, which uses next"* |

The kickoff parser keeps accepting `--mode autoflow` (it changes nothing) and `--mode auto` (so that
it can be halted by name rather than failing as a usage error). `--mode interactive` stays a usage
error. The first manifest's `mode` is therefore always `autoflow`.

`manifest_validate()` is unchanged: it checks key *presence*, and `manifest.md` pins it to the four
required keys. The refusal is about a value, and it lives beside the other mode checks.

### `LockStepTest`: the sections a brief names

The rename of §`auto` is the kind of drift nothing catches today: a brief that points at a section which
no longer exists reads fine and misleads the step. `LockStepTest` gains *"keeps every engine.md section a
brief names"*: every `§<name>` in `pipeline_leg_overrides()` for both modes must start with the name
part (before ` — `) of an `engine.md` `## ` heading. `§Suite reuse finds this tree green` matches
`## Suite reuse — once per tree` by that prefix rule.

### Follow-ups

- **#51** (explicit model and effort per step): its *Later, not in this change* bullet — "`auto` keeps its
  agent-type route (effort from `.claude/agents/*.md`) until the #50 keep/revert decision" — goes with
  `auto`. No `.claude/agents/` exists in this repo or in `~/.claude`, so there is no code to remove; the
  rest of #51 is about `autoflow` and is unaffected. Its body also says "`auto`/`autoflow` always write an
  Architectural design", which now reads `autoflow`.
- **#52** (write verbs for legs): its scope is unchanged. It names `returned` as the gate; `interactive`
  still runs `returned`, and `autoflow`'s `brief --after` / `finish` run the same
  `pipeline_return_problem()`. The verbs would feed both.

The run does not edit either issue (the pipeline posts nothing beyond `handoff` and `work-on`); the PR
body states both conclusions.

## Tests

- **`DispatchTest`**: `pipeline_retired_mode()` returns the message for `auto` and null for `autoflow`,
  `interactive` and a mangled mode; `pipeline_runs_inline()` loses its two `auto` rows (the rest,
  `mangled` included, unchanged). The fixture's `mode` becomes `interactive`.
- **`DispatchCliTest`**:
  - new, a dataset over `next`, `returned`, `launch`, `brief` and `finish` on an `auto` manifest with a
    snapshot: each prints the halt with `pipeline_retired_mode('auto')`, the manifest is byte-identical,
    and no brief is written;
  - new: `kickoff … --mode auto` prints that halt, exits 0, calls gh zero times and leaves nothing
    (`kickoff_left_nothing()`);
  - `dispatch_fixture()`'s default `mode` becomes `interactive` (it was `auto`). Every `next` / `returned`
    test runs unchanged on it: `auto` and `interactive` briefs were identical, and none of those tests
    asserts `inline` for a non-inline step it would change;
  - the refusal messages that quoted the default mode now say `interactive`: *"launches only autoflow
    runs, and next refuses one"* and the `brief` / `finish` rows of *"serves brief and finish on
    autoflow runs only"*;
  - *"writes the mode, light and the decisions verbatim"* kicks off with `--mode autoflow` and expects
    `mode: autoflow`;
  - *"runs design inline outside auto"* is renamed *"runs design inline in an interactive run"*.
- **`BriefTest`**: the fixture's `mode` becomes `interactive`; *"briefs an auto run exactly as an
  interactive one"* keeps only its last assertion (an `interactive` brief has none of `autoflow`'s lines
  and replies with one line) and is renamed for it; the role-line test expects `/pipeline interactive`.
- **`ReturnedTest`**: the fixture's `mode` becomes `interactive`, so the *"a dispatcher key"* row changes
  `mode` to `autoflow` (a change must still be a change) and *"reports a manifest problem before
  anything the step reported"* keeps `mode` equal to the fixture's.
- **`ProofRenderTest`**: its payload's `mode` becomes `autoflow` (display data only).
- **`LockStepTest`**: the new section test above. Its two existing tests are unchanged and still hold.
- **Removed**: `EnginePeakTest` (3 tests).
- The orchestrate test (`skills/orchestrate/tests/owners_test.sh`) and the critique suite are untouched
  and still pass.

## Alternatives considered

- **Let `auto` fall through to `interactive`.** Rejected: a run started unattended would stop at its
  next design or resolve step and wait, inline, for a human who is not there. The issue asks for a halt
  that names `autoflow`.
- **Migrate an `auto` manifest to `autoflow` automatically** (in `launch` or `next`). Rejected: a mode is
  the owner's choice of who resolves reviews; the halt tells the owner the one-field change and leaves the
  choice (`autoflow`, or `interactive` by setting that instead) with them.
- **Value-check `mode` in `manifest_validate()`.** Rejected: its contract is missing keys, pinned by
  `manifest.md`, and `pipeline_return_problem()` reports its result as *"the manifest lost …"*.
- **Drop `--mode` from `kickoff` entirely.** Then `--mode auto` would be a usage error (exit 1, the usage
  line) instead of a halt that names `autoflow`, which the issue asks for.
- **A `PipelineMode` enum** with the retired case. Rejected for this change: the mode is asked four
  different questions in four places (inline or not, which brief lines, which commands serve it, and
  now retired or not), and a case kept only to be refused keeps `auto` in the type.
- **Keep §`auto`'s heading and only reword its body.** Rejected: the brief points at it by name, and a
  section called `auto` in a pipeline without `auto` sends every reader looking for the engine.

## Assumptions

These are the questions brainstorming would have asked, with the answer assumed.

1. *Is an `auto` run in flight when this merges?* Assumed not. The only `auto` manifests found on this
   machine (two in `it4web-tallformbuilder`, one in `it4web-tallui`) are finished. If one is in flight,
   its dispatcher's next `returned` halts with the message, without checking the step that just
   returned; the owner switches the manifest to `autoflow` and `launch`es, and the next gate still
   reviews that step's work.
2. *Should a finished `auto` manifest still answer `done` from `next`?* No: the retired check comes
   before the finished check, as the `autoflow` refusal does today. A finished run needs no engine,
   and `engine.md` §After the merge does not go through `next`.
3. *Should `returned` refuse too, or only `next`?* Both. `returned` is the dispatcher's other command;
   refusing only `next` would let an `auto` run keep going through `returned`'s own dispatch answers.
4. *What becomes of `/orchestrate auto 429`?* The argument goes: every run is `autoflow`. A leading `auto`
   or `autoflow` from an older command line changes nothing; when it was `auto`, the plan report says once
   that `auto` was removed and the batch runs as `autoflow`. The orchestrator never passes `--mode`, so
   `kickoff`'s halt is the guard below it.
5. *Keep `--mode autoflow` working?* Yes, silently: any saved command line that spells it out keeps
   working, and `engine.md` stops documenting it.
6. *The section name for the resolve rules?* §Resolving a review — the resolve step acts on it. It is what
   both modes do at a resolve step, and the brief's pointer reads as prose: *"with the edit/rework
   boundary (engine.md §Resolving a review)"*.
7. *Keep the in-`interactive` independent read?* Yes. `interactive`'s `review-plan:resolve` brief points at
   it; only its heading changes from *Outside `autoflow`* to *In `interactive`*.
8. *Does `run_cost.php` or `run_audit.php` depend on `engine_peak.php`?* No: only `tests/Pest.php` loads
   it, and `engine_peak_cli.php` is its only other user.
9. *#51 and #52?* Conclusions in the spec and the PR body, no issue edits (§Follow-ups).
10. *Closing links?* The PR closes #87, settled at `review-pr` (§Closing links).
11. *Changelog?* None. This repo has no `.changelog/` and no `CHANGELOG.md`.

## Out of scope

- #51's model and effort table, #52's write verbs.
- Renaming `dispatch_cli.php`, `dispatch.php` or their halt messages.
- Editing the 2026-09-22 and 2026-09-23 specs and plans, which describe `auto` as it was built.
