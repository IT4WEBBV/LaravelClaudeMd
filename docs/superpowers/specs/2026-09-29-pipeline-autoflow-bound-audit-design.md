# `autoflow`: catch a transcribed bound and a missing `review-plan` loop target — design

**Design size:** Architectural

**Date:** 2026-09-29
**Issue:** IT4WEBBV/LaravelClaudeMd#86
**Canonical home:** `skills/pipeline/checks/run_audit.php` (the `bound:` line) and
`skills/pipeline/workflow/pipeline-autoflow.js` (the `args` guard); pipeline `references/engine.md`
§`autoflow` describes both.
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. Every question the brainstorm
would have asked is answered in *Assumptions*, so `/critique plan` audits exactly those. Nothing below was
built or run.

## Problem

Since #71 (PR #82) `pipeline-autoflow` routes by the tables in `launch`'s `start` answer
(`pipeline_routing_tables()`), and it counts loop-backs from that answer's `loops`
(`pipeline_loop_counts()`). Both reach the script because the invoking session copies the printed JSON
into the `Workflow` call. `DispatchCliTest` pins what `launch` prints, not what the script receives.

Most transcription damage shows as a halt or a refused return. Two do not:

1. **A changed `bound`** (or a changed `loops`): the run loops more times than `PIPELINE_LOOP_BOUND`
   allows and still finishes, or halts on the bound, as if nothing were wrong. No boundary check sees
   it: `brief`'s check (`dispatch_cli_boundary_problem()`) compares a step's return with its snapshot and
   never counts loop-backs; `pipeline_loop_back()` counts them only in `interactive`.
2. **`tables.loopTarget` without `review-plan`**: `complete()` checks that every `loopTarget` entry
   joins two legs, not that `review-plan` is one of its keys. The script charges every
   `plan-insufficient` to the literal `loops['review-plan']`, a gate the tables then do not route. The
   script seeds `loops` from `loopTarget`'s keys and then `args.loops`; when `args.loops` lacks the key
   too, `++loops['review-plan']` is `NaN`, which is never above the bound, so plan gaps loop without end.

PR #82 recorded both without an edit.

## Approaches

1. **The ledger's count, for the gates the run looped back at, against `PIPELINE_LOOP_BOUND`, with the
   run's journal saying which loop-back the run halted on (chosen).** The ledger is what `launch` counts
   from, so a script that honoured the bound leaves at most `PIPELINE_LOOP_BOUND` looped-back entries at
   a gate it routed a loop-back at, plus the one it refused. The journal (`pipeline_run_journal()`),
   which `run_audit.php` already reads, says both: which gates the run looped back at, and whether the
   run's last step returned a loop-back (a routed loop-back always starts another step, so a last one
   is the one the script halted on).
2. **The ledger's count against the bound, nothing else** (the issue's wording, literally). Rejected: a
   run that halts on the bound leaves `PIPELINE_LOOP_BOUND + 1` looped-back entries, because the resolve
   step records `outcome: looped-back` before the script refuses it (engine.md §Failure policy), so every
   correct bound halt would print `MISMATCH`; and so would every later run on that manifest after the
   owner resumes it, which loops back at that gate no more.
3. **Approach 2, excusing one extra entry when `cursor.reason` reads `<leg>: loop-back bound
   exhausted`.** Rejected: it ties PHP to a string that lives in the JavaScript, and still flags the
   resumed run.
4. **Catch it at the source instead of after the run.** `brief` is not told the script's counters, the
   journal records no `log()` line (it holds `launched`, `started`, `result` and `failed` records only),
   and a field in the script's result reaches `finish` by the same copy. Rejected: nothing on that path
   is not transcribed.

For the loop target, the issue settles it: the same halt as `args` that are not a `launch` `start`
answer.

## Design

### `run_audit.php`: the `bound:` line

A sixth line, after the gate lines:

```
bound: the ledger's loop-backs where the run looped back [<leg> <count>, …], <PIPELINE_LOOP_BOUND> allowed[, not counting the <leg> loop-back the run halted on] — agree | MISMATCH
```

- **What a step charges.** `run_audit_charged(array $step): ?string`, over one journal step: the
  looping leg its return charges, as the script charges it. `plan-insufficient` from any leg charges
  `review-plan`; `looped-back` from a resolve step or from `verify-ui` charges that leg; anything else,
  or a step that never returned, charges nothing.
- **The refused loop-back.** When the journal's last step charges a leg, the script halted on it: a
  loop-back it routes always starts the next step. That entry is in the ledger and is not counted.
- **Which gates are judged.** Only the legs the run routed at least one loop-back at (charged minus the
  refused one). A gate the run did not loop back at says nothing about the bound the script ran with.
- **The count.** `pipeline_loop_counts($ledger)`, the count `launch` gave the script, minus the refused
  entry at its gate. `MISMATCH` when any judged count exceeds `PIPELINE_LOOP_BOUND`; `agree` otherwise,
  and with no judged gate (`[]`).
- A signal, never a halt, like the other lines; the script still exits 0.

`run_audit_bound(array $steps, array $ledger): string` builds the line. The CLI reads the journal once
and passes the steps to `run_audit_gates()` and `run_audit_bound()`. The file's docblock names the bound
beside the two reported facts.

Examples, `PIPELINE_LOOP_BOUND` 2:

| The run | Ledger (`pr-review` looped-back) | Line |
|---|---|---|
| two `review-pr` loop-backs, then passes | 2 | `[review-pr 2], 2 allowed — agree` |
| three `review-pr` loop-backs, then passes (a bound of 3 reached the script) | 3 | `[review-pr 3], 2 allowed — MISMATCH` |
| two loop-backs, the third refused: the run halts | 3 | `[review-pr 2], 2 allowed, not counting the review-pr loop-back the run halted on — agree` |
| the owner resumes it and `review-pr` passes | 3 | `[], 2 allowed — agree` |

A changed `loops` (a count copied lower than the ledger's) shows the same way: the script routes more
loop-backs than the ledger leaves room for.

### `pipeline-autoflow.js`: `review-plan` in `loopTarget`

The guard after the destructuring,

```js
if (!legs.includes(args.startLeg)) return halt(args.startLeg ?? 'launch', 'args are not a launch start answer')
```

becomes

```js
if (!legs.includes(args.startLeg) || !('review-plan' in loopTarget)) return halt(args.startLeg ?? 'launch', 'args are not a launch start answer') // a plan gap is charged to loops['review-plan']
```

The halt comes before any agent. `complete()` stays as it is.

### Docs

- engine.md §`autoflow`, *The script* bullet: `args` that are not a `launch` `start` answer halt, and so
  do `args` whose `tables.loopTarget` has no `review-plan`, the gate every `plan-insufficient` is charged
  to.
- engine.md §`autoflow`, the paragraph after the three after-run commands: `run_audit.php` also prints
  whether the gates the run looped back at stay within `PIPELINE_LOOP_BOUND` in the ledger, the loop-back
  the run halted on not counted, because `tables.bound` and `loops` reach the script through a copy.
- Pipeline `SKILL.md`, the *Cost per run* bullet: `run_audit.php` also says whether the ledger's
  loop-backs stay within the bound.

### What does not change

`dispatch.php`, `dispatch_cli.php` (`launch`, `brief`, `finish`), `complete()`, the script's routing and
counting, `interactive`, `run_audit_gates()` and `run_audit_ui()`, `gates.md`, `manifest.md`.

## Tests

Written first, seen red:

- `RunAuditTest.php`:
  - a run that looped back three times at `review-pr` over a ledger of three → `MISMATCH`; two over two
    → `agree` (the issue's *Done when*);
  - a run that halted on its third loop-back, ledger three → `agree`, the refused one named and not
    counted; a resumed run that loops back nowhere over that ledger → `[]`, `agree`;
  - the full output of *reports a run whose ledger agrees with every step* gains the line
    `bound: the ledger's loop-backs where the run looped back [review-plan 1], 2 allowed — agree`;
    *counts a review-plan step's plan gap or escalation* compares lines 1–4 only.
- `AutoflowScriptTest.php` (replayed by `autoflow_replay.mjs`): `launch`'s answer with
  `tables.loopTarget['review-plan']` removed → no agent, `{action: halt, leg: <start leg>, reason: args
  are not a launch start answer}`. Against today's script it starts the start leg's agent, which the
  replay has no return for.

The whole pipeline suite passes.

## Out of scope

- A bound copied **lower** than `PIPELINE_LOOP_BOUND`: the run halts early with *loop-back bound
  exhausted*, and the owner is asked about that halt (orchestrate); the audit does not judge it.
- Moving the tables off the transcription path (e.g. the script reading them from a file `launch`
  writes): the issue asks for a check, and #71 settled the transfer.
- `run_audit_gates()` and `run_audit_charged()` both split a step's label and status; one shared helper
  is left for a third use.
- Three blind spots of the `bound:` line, each a missed signal, never a false alarm, and each in a run
  rare on two axes at once; none justifies more machinery:
  - **A next agent that never started.** The refused loop-back is inferred from the journal's last step
    charging a leg, which holds because a routed loop-back's next step leaves a `started` record. When
    the next agent is rejected before it starts (a narrowed `tables.allowed`, an unsatisfiable schema),
    the journal holds a `failed` record with an empty `agentId` and no `started`, which
    `pipeline_run_journal()` drops; the routed loop-back then sits last, is read as refused, and the line
    under-counts both sides by one. The run has halted on the agent failure anyway.
  - **An `unknown` cycle under a transcribed bound.** Assumption 7 holds only under an honoured bound;
    when the bound was transcribed, `pipeline_loop_counts()` hands the audit the bound itself at that
    gate rather than a count, so a reconstructed manifest can never read `MISMATCH` there.
  - **A Bounded escalation refused at the bound.** The refused decrement assumes the refused entry is a
    counted `looped-back` one; a second Bounded `plan-insufficient` refused at the bound wrote a
    `design-size` entry instead (engine.md §Escalation), so the ledger side is one lower than the
    decrement assumes.

## Assumptions

Questions the brainstorm would have asked the owner, with the answer assumed.

1. **Which ledger count is compared: this run's loop-backs or the manifest's?** The manifest's, per gate,
   as `pipeline_loop_counts()` counts it, since that is what `launch` gives the script and what the bound
   is defined over (manifest.md §gate_ledger, *The loop bound is read from here*).
2. **Is the loop-back a run halted on "looping back more often than the bound permits"?** No: it was
   refused, so a correct bound halt must read `agree`. It is recognised from the journal (the run's last
   step charges a leg), not from `cursor.reason`.
3. **Should a gate the run did not loop back at be judged?** No. After a bound halt the owner may resume;
   the ledger then holds `PIPELINE_LOOP_BOUND + 1` entries at that gate for good, and a run that passes it
   says nothing about the bound it ran with.
4. **Only "more often", or also "fewer"?** Only more, as the issue's *Change* says. A lower bound surfaces
   as a halt the owner is asked about.
5. **One line or one per gate?** One, appended after the four gate lines, ending in `— agree` or
   `— MISMATCH` like them; the judged gates in brackets.
6. **A Bounded escalation** (`plan-insufficient`, a `design-size` entry, no looped-back entry) charges
   `review-plan` in the journal, so `review-plan` is judged on the ledger's plan-approval count. That can
   only be lower than the script's own count, never a false `MISMATCH`.
7. **An `unknown` cycle** (a reconstructed manifest): `pipeline_loop_counts()` gives the bound, the script
   refuses the gate's first loop-back, the run routes none there, and the gate is not judged.
8. **Where does the `review-plan` guard go?** In the existing *not a launch start answer* line after the
   destructuring, with that reason, as the issue says; not in `complete()`, whose halt reason names
   incomplete tables.
9. **Does the PR close #86?** Yes: both *Change* points and both *Done when* tests. `review-pr` settles the
   closing link (engine.md §Closing links).
10. **Changelog?** This repository has no `.changelog/` and no `CHANGELOG.md`, so none is written.
