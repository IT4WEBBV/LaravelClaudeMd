# `autoflow`'s `brief` and `finish` check the step before them — design

**Design size:** Architectural

Issue #70, with #53 folded in. Builds on `2026-09-23-pipeline-auto-workflow-design.md` (PR #50) and on
`2026-09-24-pipeline-autoflow-routing-tables-design.md` (#71, merged).

## Problem

`autoflow` trusts each step's return (`engine.md` §`autoflow`, *No check on what a step reports*). The
workflow script routes on the `status` a step returns, takes `ui` from `implement` and `size` from
`design` as reported, and nothing compares the manifest with what the step was given. So:

- a resolve step can return `continued` without completing its open ledger entry, and the run moves on;
- `implement` can return `ui: false` on a diff that touches the UI, and the mandatory `verify-ui` gate
  is skipped;
- any step can move the cursor, rewrite a ledger entry or write a key only the engine writes;
- a step can write one status into the manifest and return another to the script, which routes on the
  one it was returned.

`auto` and `interactive` catch the first three at `returned`: `pipeline_returned()` and
`pipeline_return_problem()` in `checks/dispatch.php` compare the manifest with the `<stem>.before.json`
snapshot and halt with a named reason. `autoflow` notices only after the run, in `run_audit.php`, and
only for `ui` and the gates' outcomes.

## The change

The two tested commands `autoflow` already runs at each step boundary take over `auto`'s check. There
is no extra agent, and the checker stays a separate process from the worker: `brief` checks the step
before it, and `finish` checks the last step. The status a step returns still routes the script. What
this adds is the fail-closed check `auto` has, one boundary later.

### What the script tells `brief`

A `brief` cannot tell by itself whether a step ran before it, or which one. The script knows, so it says
so in the brief command it puts in each step's prompt, as flags built from the previous step's
structured result:

```
php <checks>/dispatch_cli.php brief <manifest> <leg> <step> [--after <leg>:<step> --status <status> [--ui true|false] [--size Bounded|Architectural]]
```

- The first step of a workflow run gets no flags: nothing ran before it in this run.
- Every later step gets `--after` and `--status`, the previous step's `<leg>:<step>` and the `status` it
  returned.
- `--ui` is added after an `implement` step, and `--size` after a `design` step: the values the script
  took from them.
- `reason` is never passed. It is free text, and would need shell quoting in a command an agent copies.
- A retried review step (Fable returned nothing, so the script runs it again on Opus) gets the same
  prompt, and so the same flags, as its first attempt.

`pipeline-autoflow.js` keeps the last step's result in one variable, `last`, and `briefCommand()`
appends the flags from it. Stub prompts use the same `briefCommand()`, so a smoke run passes them too.

### What `brief` does with them

`brief` still validates the manifest and the mode first, and touches nothing when either fails. Then,
before the step check (`pipeline_step_problem()`):

1. **No `--after` and no `<stem>.before.json`**: this is the run's first step. Nothing is checked.
   `launch` removes the snapshot an earlier run left (below), so a run's first `brief` finds none.
2. **`--after` and no `<stem>.before.json`**: halt with *"cannot check the `<after>` step's return: no
   snapshot at `<path>`"*.
3. **The snapshot is the requested step's own**: the step the snapshot was taken for is `<leg>:<step>`
   itself, with or without `--after`. This is the script's retry of a review step that returned
   nothing, the run's first step included:
   - the manifest is unchanged since the snapshot: brief the step again, with no check;
   - it changed: halt with *"the `<leg>` `<step>` step returned nothing and changed the manifest"*.
4. **No `--after`, and a snapshot of another step**: halt with *"a snapshot of the `<snap>` step
   exists, but the script names no step before `<leg> <step>`"*. The script names the step before on
   every step but the run's first, so a brief without `--after` over another step's snapshot is a step
   agent that dropped the flags from the command it copied. Without this halt the worker would decide
   whether the checker runs, and a skipped check would leave no trace.
5. **The snapshot is not `--after`'s**: halt with *"the snapshot is of the `<snap>` step, but the script
   says `<after>` returned: it did not run brief"*.
6. **Otherwise**, the check below. A problem halts.

The step a snapshot was taken for is read from the snapshot itself, as `returned` reads it: the leg is
its `cursor.leg`, the step is `pipeline_step()` over its ledger. `brief`'s own step check guarantees that
this is the step it briefed (a `resolve` needs an open entry, a `review` needs none). So the snapshot
stays the plain manifest that `next` writes too, and §Failure policy's *repair it from
`<stem>.before.json`* reads the same in every mode.

A `brief` that passes writes `cursor: {leg, status: pending}` as today, then the snapshot of that
manifest, then prints the brief.

`launch` changes in one way: when it answers `start`, it removes `<stem>.before.json` (only
`dispatch_cli.php` reads it), so a snapshot on disk is always the current run's. §Failure policy's
repair from the snapshot happens before the next `launch`, so nothing it needs is lost, and a `launch`
that halts or answers `done` leaves the snapshot alone. Without the removal, a relaunch after a halt
would halt at once: its first `brief` is for the step that failed, whose own snapshot is still there,
over a manifest that now says `halted` (case 3).

### The check

`pipeline_reported_problem($before, $after, $reported, $size, $diffUi)` in `dispatch.php`, pure. Both
manifests are compared key-sorted (`pipeline_normalized()`), and the leg and step come from `$before`.
Messages name a step as *"the `<leg>` `<step>` step"*, as `returned`'s do:

1. The manifest is unchanged since the snapshot → *"the `<leg>` `<step>` step returned without writing
   the manifest"*. This is `returned`'s message; a step that returns `continued` and writes nothing does
   not pass.
2. `pipeline_return_problem($before, $after, $leg, $step, $size)`, unchanged: the manifest still
   validates, no key outside `pipeline_leg_writable_keys()` changed (a moved cursor included), the
   status is one the step may return, a halt has a reason, and the ledger only grew in the way the
   step and status require (a resolve step completes its open entry with `outcome` equal to the
   status).
3. `$reported['status']` equals the manifest's `cursor.status` → else *"the `<leg>` `<step>` step
   returned `<reported>` to the script but wrote `<status>` into the manifest"*. A missing `--status`
   reads as `nothing`.
4. After `implement`: `$reported['ui']` equals `$diffUi`, both as `true`/`false` → else *"the implement
   step returned ui: `<reported>`, but its diff says `<diffUi>`"*.
5. After `design`: `$reported['size']` equals `$size->value` → else *"the design step returned size
   `<reported>`, but the spec says `<size>`"*.

`$size` is `dispatch_cli_design_size($after)`, as `returned` reads it. `$diffUi` is
`pipeline_triggers()['ui']` over `<stem>.diff`, which the implement prompt already has the step write.
The file has to be the step's own: `brief` halts with *"the implement step did not write
`<stem>.diff`"* when it is missing or older than the snapshot, because the invoking session writes
the same file before `launch`, and a stale copy would make the check compare the wrong diff.

So the step must write the diff on every return it makes, not only after a commit. The script takes
`ui` from `implement` on every status, so the check runs after a `plan-insufficient` too, and an
implement step that finds an Architectural plan gap before its first commit would otherwise halt the
next `brief` falsely. The implement prompt's step 4 says *"After the last commit, run …"* today; it
becomes *"Before returning (after the last commit, when there is one), run …"*.

### Where a halt lands

A halt from the check must leave the cursor on the step that failed it, as `returned` does. If the cursor
named the next leg, a resume would start there and walk past what failed. Suppose a resolve step left
its `plan-approval` entry open and `handoff`'s brief halted: with the cursor on `handoff`, `launch`
starts at `handoff` and the open entry is never resolved.

- `brief` writes `cursor: {leg: <the snapshot's leg>, status: halted, reason}` over the manifest as the
  step left it, as `dispatch_cli_halt()` does for `returned`, and prints the halt. The step agent
  returns `halted` with that reason, and the script returns `{action: halt, leg: <the new leg>, reason}`.
- `finish` records a halt as today, with one change: **when the manifest's cursor already says
  `halted`, `finish` keeps its leg** and takes only the reason from the return. Every other halt keeps
  today's rule: the leg the return names when it is one of the pipeline's legs, otherwise the cursor's
  leg. The cursor says `halted` only when `brief` or the step itself wrote it. A step that halts leaves
  the cursor on its own leg, which is the leg the script names anyway. So the new rule changes the
  outcome only for `brief`'s halts. A cursor left `halted` by an earlier run is rewritten by the first
  `brief` of a relaunch, so the new rule can see it only when the script halts before any agent. That
  halt names the start leg, which `launch` took from that cursor.
- The step's work stays in place. §Failure policy's repair from the snapshot applies before the next
  `launch`, as it does before `next`.

### `finish`

A `done` return must now also get through the check of the last step, `review-pr:resolve`, before the
cursor says `done`:

1. The cursor is on `review-pr`. This is today's check, and it still halts with *"the workflow returned
   done at `<leg>`"*.
2. The snapshot exists and is `review-pr`'s `resolve` step's → else halt with *"the workflow returned
   done, but the last snapshot is of the `<snap>` step"* (or *"… but there is no snapshot at `<path>`"*).
3. `pipeline_reported_problem()` with `['status' => 'continued']`, which is the only return after which
   the script answers `done`.

A problem halts through `dispatch_cli_halt()` on `review-pr`, so an open `pr-review` entry halts the run
instead of finishing it. `finish` does not check a halt return: that run stops anyway, and its reason is
the one to record.

### `pipeline_ledger()` (#53)

`pipeline_ledger(array $manifest): array` in `manifest.php` returns `$manifest['gate_ledger'] ?? []`: a
manifest without a ledger has an empty one. It replaces every such read in `skills/pipeline/checks/`:
five in `dispatch.php`, two in `brief.php`, two in `dispatch_cli.php` and one in `run_audit.php`. The
behaviour does not change. The new code reads the ledger through it too.

### Tests

- **`DispatchCliTest`**, on `dispatch_fixture()` manifests whose steps are played with
  `dispatch_leg_writes()`:
  - `brief` after a resolve step that left its entry open → halt, with the cursor on `review-plan`,
    `halted`, and the reason in the manifest;
  - `brief` after an `implement` that reported `--ui false` on a UI diff → halt; the same with a diff
    older than the snapshot → halt *"did not write"*;
  - `brief` after a step that moved the cursor, and after one that rewrote a ledger entry → halt;
  - `brief` after a clean return → the brief as today, with the cursor and the snapshot now on the new
    step;
  - `--status` disagreeing with the manifest, and `--size` disagreeing with the spec → halt;
  - `launch` removes an earlier run's snapshot, and the flagless `brief` after it checks nothing, over
    a `halted` cursor too; `--after` with no snapshot → halt; `--after` naming a step the snapshot is
    not of → halt; no `--after` over a snapshot of another step → halt;
  - the retry, with the flags and as the run's first step without them: the same step with an
    unchanged manifest → the brief; with a changed one → halt;
  - `finish` `done` with an open `pr-review` entry → halt, not `done`; `finish` `done` over a snapshot
    that is not `review-pr:resolve`'s → halt; the existing `done` case gains a clean resolve;
  - `finish` with a halt return keeps a `halted` cursor's leg.
- **`ReturnedTest`**: `pipeline_reported_problem()` directly, for a clean return, an unwritten
  manifest, and the status, `ui` and `size` mismatches.
- **`ManifestTest`**: `pipeline_ledger()` with and without a ledger.
- **`AutoflowScriptTest`** and `tests/autoflow_replay.mjs`:
  - the harness also returns each call's `prompts`, and a test checks the brief command in them: no
    flags on the first step, then `--after`/`--status` from the previous return, `--ui` after
    `implement`, `--size` after `design`, and the same command on the Opus retry;
  - **the replay smoke pass.** With `steps: true` in its input, the harness's fake `agent()` acts
    like a stub step against the real checks. It runs the brief command from the prompt. When that
    prints a halt, it returns `{status: halted, reason}`. Otherwise it applies the scripted return's
    `write` to the manifest, writes its `diff` to `<stem>.diff`, and returns the rest. A scripted
    `null` runs the brief and returns nothing. `write` is merged into the manifest at the top level,
    with `cursor` one level down, and a `gate_ledger` in it replaces the ledger. Two cases:
    - the issue's scenario: from `review-plan`, a review that adds its open entry, then a resolve that
      returns `continued` and leaves it open. The run halts at `handoff`'s brief, and `finish` with
      that return leaves the cursor on `review-plan`, `halted`;
    - a clean walk from `design` to `done`. Every step writes what its brief asks for, `implement`
      writes an empty diff and reports `ui: false`, and `review-pr:review` returns nothing once and
      runs again. The walk ends with `{action: done}`, and `finish` answers `done`.
- Nothing in the existing suite changes behaviour except the finish case that goes through the new
  check: its fixture gains the resolve step it now needs.

### The Workflow smoke pass

The issue asks for a smoke-pass scenario in which a stub resolve step returns `continued`, leaves its
entry open, and halts at the next `brief`. The replay smoke pass above runs that scenario on every
suite run, through the unchanged script and the real `brief`. It does not use the Workflow runtime or
a model. An `autoflow` implement step cannot start a Workflow, so this follows #71's rule: if the
implementing session has the Workflow tool, it also runs that scenario with `args.stub` against the
saved script. If it does not, the PR body says the Workflow smoke pass was not run and names the
scenario, so the owner can run it before merging.

### Docs that change

- `references/engine.md` §`autoflow`:
  - the step-agent line of the diagram and the *A step* bullet: the flags, and what `brief` checks;
  - the *`finish`* bullet: the check before `done`, and the rule that a `halted` cursor keeps its leg;
  - *No check on what a step reports* is rewritten as *The check at the next boundary*: what is checked,
    by which command, that `launch` removes an earlier run's snapshot so a flagless `brief` over another
    step's snapshot can halt, and that `run_audit.php` stays as the after-run report. Its last sentence, which
    says a `MISMATCH` is the trigger for adding a check, becomes a statement that a `MISMATCH` now
    means a check has a hole.
- `references/engine.md` §Failure policy: *A return the dispatcher cannot account for* names `brief`
  and `finish` for `autoflow`; *A halted manifest is the one the check rejected* says *before the next
  `next` or `launch`*.
- `references/manifest.md` §What a leg writes: *In `autoflow` nothing compares* is replaced by the
  boundary check.
- `dispatch_cli.php`'s usage docblock and its usage line: `brief`'s flags.
- `pipeline-autoflow.js`'s implement prompt, step 4: *"Before returning (after the last commit, when
  there is one), run …"* instead of *"After the last commit, run …"* (§The check).
- `skills/orchestrate/references/commands.md` §Finish: `finish` refuses a `done` whose last step's
  return does not hold, as well as one whose cursor is not on `review-pr`.
- `run_audit.php`'s docblock: it reports after the run; the check at each boundary is `brief`'s.

The 2026-09-23 and 2026-09-24 specs and plans are records of what was built, and they stay as they are.

## Alternatives considered

- **A checker agent between steps.** Rejected: the issue asks for no extra agent, a model is not a
  fail-closed check, and each check would add an agent's cost.
- **The script runs the check itself.** Rejected: a Workflow script has no filesystem or process
  access.
- **Halt in `run_audit.php`, or only in `finish`.** Rejected: by then the skipped gate is already
  skipped, and the run has built on a bad return.
- **Store `{leg, step, manifest}` in the snapshot, as the issue words it.** Rejected: the leg and step
  can be derived from the snapshot and are guaranteed to match. A second snapshot shape would make
  `next` and `brief` write different files under the same name. See *Assumptions* 1.
- **`brief` recomputes the route (`pipeline_route()`) from the manifest's status and compares it with
  the step it was asked for,** instead of taking `--status`. This is stronger in principle: it would
  also catch a script routing bug. Rejected: `AutoflowScriptTest` already pins the routing, and the
  script's bound and its Bounded exemption count differently from `pipeline_route()` by design
  (§Design size), so the two would disagree on legitimate runs.

## Assumptions

These are the questions brainstorming would have asked, with the answer assumed.

1. *Snapshot format: the manifest plus the leg and step, as the issue says, or the plain manifest?* The
   plain manifest. Its `cursor.leg` is the leg, and `pipeline_step()` over its ledger is the step.
   `brief`'s step check makes that the step it briefed. This departs from the issue's wording for the
   reason in *Alternatives considered*.
2. *How does `brief` know which step just ended, and whether one did?* The script tells it:
   `--after <leg>:<step>` plus the reported values. No `--after` means the first step of the run, which
   is checked by nothing. The step agent copies that command, so it could drop the flags and skip the
   check without a trace. So `launch` removes an earlier run's snapshot, and a `brief` without
   `--after` halts over a snapshot of another step. The only snapshot a flagless `brief` can then find
   is its own step's: the Opus retry of a run's first step.
3. *Should the status be cross-checked, beyond `ui` and `size`?* Yes. Otherwise a resolve step that
   records `looped-back` and returns `continued` passes the ledger check while the script walks on,
   which is the kind of gap this issue closes.
4. *What about the script's Opus retry of a review step?* Its `brief` sees a snapshot of its own step.
   An unchanged manifest means the first attempt did nothing, so the step is briefed again. A changed
   manifest means the first attempt wrote something and still returned nothing, and that halts.
   Consecutive runs of the same `<leg>:<step>` happen only on this retry: no route sends a step to
   itself.
5. *Which leg does a halted cursor name?* The failing step's, so that a resume re-runs it. `brief` writes
   it, and `finish` keeps a cursor that already says `halted`. This is the only change to `finish`'s
   halt rule.
6. *Can the check trust `<stem>.diff`?* Only if the implement step wrote it. A diff older than the
   implement step's snapshot halts. Modification times are compared with `>=` at one-second
   granularity, so a stale diff written in the same second as the snapshot would pass. `launch` and
   the implement step's `brief` are an agent start apart, so that does not happen in practice.
7. *Does `finish` check a halt return?* No, only `done`. A halted run stops either way, and the reason
   to record is its own.
8. *Malformed flags?* A usage error (exit 1), the same as a `kickoff` that `dispatch_cli.php` cannot
   parse. The script builds the flags and `AutoflowScriptTest` pins them. Values are not parsed: `--ui`
   is compared as the string `true` or `false`, which is what the `ui` command prints.
9. *Runs launched before the merge?* The script and `checks` both come from the primary checkout.
   An in-flight run whose script predates this change passes no flags, so its `brief`s check nothing,
   which is today's behaviour. There is no newer script paired with older checks.
10. *How is the smoke pass shown?* By the replay smoke pass in the suite, and by a Workflow run when the
    tool is there (*The Workflow smoke pass*).
11. *`pipeline_ledger()`: where, and which reads?* In `manifest.php`, next to the other manifest
    accessors, which every checks entry point loads. All ten reads in `checks/`, not only the ones in
    `dispatch.php` and `brief.php`: the accessor exists to state the rule once.
12. *Should `LockStepTest` pin the script, as the issue says?* Since #71 `AutoflowScriptTest` pins the
    script, so the flags are pinned there. `LockStepTest` is unchanged.
13. *Closing links?* The PR closes #70 and #53, which is settled at `review-pr` (§Closing links).
14. *Changelog?* None. This repo has no `.changelog/` and no `CHANGELOG.md`.

## Out of scope

- Checking a step's claims about work outside the manifest (that the spec says what the plan does, that
  the code passes review). The gates do that.
- Leg-side write commands for the manifest (#52).
- A Workflow-runtime smoke harness in the suite.
