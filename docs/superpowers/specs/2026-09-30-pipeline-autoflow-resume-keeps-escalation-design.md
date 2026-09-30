# `autoflow`: a resume after an escalation keeps `full` — design

**Design size:** Architectural

**Date:** 2026-09-30
**Issue:** IT4WEBBV/LaravelClaudeMd#119 (follow-up to #116, PR #117)
**Canonical home:** `skills/pipeline/workflow/pipeline-autoflow.js` (the seed); `skills/pipeline/checks/pipeline.php`
(the ledger question), `agents.php` (`pipeline_start_profile()`) and `dispatch_cli.php` (`launch`'s `start`
answer); pipeline `references/engine.md` (§`autoflow` — a program that calls agents, §Design size).
**Written unattended** by the `design:spec` step of a `/pipeline autoflow` run. Every question the
brainstorm would have asked is answered in *Assumptions*, so `/critique plan` audits exactly those.
Nothing below was built or run.

## Problem

Escalation is one way, "in the run as on a resume" (engine.md §Agents per step, *Which profile*; the
comment on the script's design-step profile switch). The script keeps that only within one run:

- `pipeline-autoflow.js` starts every launch with `let exempted = false` (line 155).
- On a resume after an escalation, `launch` starts the run on `full`: `pipeline_start_profile()`'s first
  rule reads the ledger's `escalated` entry.
- The rerun `design:spec` step then returns `size`. When it returns Bounded — the grow form failed to
  change the `**Design size:**` header, the case the in-run dataset *a Bounded escalation whose grown
  spec still says Bounded* (`AutoflowScriptTest`) pins as `full` — line 172,
  `profile = size === 'Bounded' && !exempted ? tier : 'full'`, drops the run back to its tier, because
  `exempted` is `false`.

Since #116 a `light` run then has Sonnet implementing a change that escalated on a migration or on auth.

The same flag also decides counting (line 186): a Bounded `plan-insufficient` is exempt from
`review-plan`'s bound once per run. A resume forgets that too, so a resumed run grants a second free
escalation that one uninterrupted run would have counted.

## Change

### The ledger question — `pipeline_escalated()` in `checks/pipeline.php`

```php
/** Whether the ledger records a `design-size` escalation: the run is on `full` from there on, a resume included (`../references/engine.md` §Agents per step). */
function pipeline_escalated(array $ledger): bool
{
    return in_array('escalated', array_column($ledger, 'outcome'), true);
}
```

It sits beside `pipeline_reset_at()`, the other reader of `escalated` entries. `pipeline_start_profile()`'s
first rule becomes `pipeline_escalated(pipeline_ledger($manifest)) => AgentTier::Full->value`, so the rule
and the new answer field read the ledger one way. `pipeline_start_profile()`'s one caller,
`dispatch_cli.php`, requires `pipeline.php` (line 24) before `agents.php`, and the Pest bootstrap
(`tests/Pest.php`) loads both, so no require changes (Assumption 5).

### `launch`'s `start` answer — `escalated`

`dispatch_cli_launch()` adds one key beside `profile` and `tier`:

```php
'escalated' => pipeline_escalated(pipeline_ledger($manifest)),
```

Always present, always a boolean.

### The script — seed the flag, rename it

In `pipeline-autoflow.js`:

- The flag is renamed `exempted` → `escalated`: it now means "the run has escalated, in this launch or
  an earlier one", which is what engine.md's *Which profile* says ("unless the run has escalated"), and
  it no longer only means "the one exemption is used".
- It is seeded from launch: `let escalated = args.escalated` in place of `let exempted = false`.
- A start answer without a boolean `escalated` halts before any agent, after the agents check:
  `if (typeof args.escalated !== 'boolean') return halt(args.startLeg, 'args carry no escalated flag: re-run launch from checks that answer it')`.
  It is not defaulted to `false`: a missing flag is a stale or hand-made answer, and `false` is the side
  that drops a run to Sonnet (Assumption 2).
- Lines 172, 186 and 188 use the new name; their logic is unchanged. The comments on lines 172 and 189
  already hold as written; line 186's comment stays true ("once per run", a resume now included).

The effect on a resume after an escalation:

| Rerun step returns | Before | After |
|---|---|---|
| `design:spec` Bounded (grow form left the header) | profile drops to the tier | stays `full` |
| `design:spec` Architectural | `full` | `full` (unchanged) |
| a later Bounded `plan-insufficient` | exempt again (second free escalation) | counted toward `review-plan`'s bound, as in one uninterrupted run |

A launch with no escalation in the ledger answers `escalated: false`, which is today's seed, so every
run that never escalated behaves exactly as today.

### engine.md

- §`autoflow` — a program that calls agents, the `launch` bullet's sentence on `agents`, `profile` and
  `tier`: add `escalated`, whether the ledger records an escalation (`pipeline_escalated()`), from which
  the script seeds its own, so a resume keeps `full` and the one exemption as a run does.
- The *script* bullet: "the script exempts one per run" becomes "the script exempts one per run, a resume
  included (`escalated`)"; "`agents`, `profile` or `tier` missing or incomplete halts before any agent"
  gains "and so does an `escalated` that is not a boolean".
- §Agents per step, *Which profile*: unchanged — the issue's done-when is that its sentence holds as
  written, and after this change it does.
- §Design size, *Once, and one way*: "`autoflow` exempts one per run" gains ", a resume included".

## Testing

All in the existing Pest suite (`skills/pipeline/checks`, `vendor/bin/pest -c skills/pipeline/checks/phpunit.xml`
from the repo root), TDD: each test is written and seen failing before the code.

1. **`AutoflowScriptTest` — the resume, beside the in-run dataset.** A new `it(...)` directly after
   *moves a light run to full when its design turns out Architectural, and on a Bounded escalation*:
   `autoflow_start('design', [$escalated], "# x — design\n\n**Design size:** Bounded\n", plan: 'plan.md', tier: 'light')`
   (Assumption 7)
   with `$escalated = ['gate' => 'design-size', 'leg' => 'review-plan', 'at' => …, 'reason' => 'migration', 'outcome' => 'escalated']`;
   returns `design:spec` Bounded, `review-plan:review` `AUTOFLOW_STOP`. Expected: labels
   `['design:spec', 'review-plan:review']`, settings `['opus high', 'fable high']` (both `full`). Today it
   gives `['opus high', 'opus medium']` — the red.
2. **`AutoflowScriptTest` — the counting consequence**, beside *exempts one Bounded escalation and then
   counts on from the ledger's loop-backs*: `autoflow_start('handoff', [$escalated, $looped(1), $looped(2)], <Bounded spec>)`,
   `handoff:run` returns `AUTOFLOW_PI`. Expected: labels `['handoff:run']` and the halt
   `review-plan: loop-back bound exhausted` on leg `handoff`. Today the gap is exempt and `design:spec`
   runs — the red.
3. **`AutoflowScriptTest` — the halt.** A new test (or two rows in a dataset): `escalated` unset, and
   `escalated` set to `'true'` (a string), each halts before any agent with
   `args carry no escalated flag: re-run launch from checks that answer it` on leg `handoff`.
4. **`DispatchCliTest`.** *starts a run on full once its ledger records an escalation…* also expects
   `$start['escalated']` to be `true`; *hands the script its agents…* (no ledger) expects `false`.
5. The existing `pipeline_start_profile()` dataset in `AgentsTest` covers the refactored first rule
   unchanged; no new rows.

The replay tests in `AutoflowScriptTest` build their args with `autoflow_start()`, which calls the real
`launch`, so every existing replay picks up `escalated: false` and keeps passing without edits. Tests
that hand-build args would need the key; grep finds none (every `$start` comes from `autoflow_start()`).

## Approaches considered

1. **`launch` answers `escalated`; the script seeds its flag from it** (chosen; the issue's direction).
   The ledger stays launch's to read, the script stays a router over what launch computed, and the seed
   fixes both roles of the flag (profile and counting) in one place.
2. **The script infers it from `profile`, `tier` and `size`** (`profile === 'full' && tier !== 'full' && size === 'Bounded'`).
   Rejected: `full` at start can also come from an Architectural spec, and with no word (`tier: 'full'`)
   the inference says nothing, so the counting half would still be lost.
3. **Hand the script the ledger.** Rejected: the script would parse manifest data that launch already
   interprets (`loops`, `profile`), a second reader of the ledger format.
4. **Split the flag in two** — `escalated` for the profile, `exempted` for counting, seeding only the
   first. Rejected: engine.md says the exemption is once per run and escalation one way; a resume is the
   same run, so both halves follow the ledger, and one flag keeps them from drifting apart.

## What does not change

- `pipeline_start_profile()`'s three rules and their order; `AgentTier`; `PIPELINE_AGENTS`.
- `pipeline_loop_counts()`: an `escalated` entry is still not a loop-back.
- `interactive` routing (`pipeline_route()`), which never counted Bounded escalations.
- The briefs, the ledger format and every `dispatch_cli.php` command other than `launch`'s answer.
- `orchestrate`, which passes launch's answer through as `args` unread.

## A run in flight when this lands

A workflow already started keeps the script it loaded. The next `launch` (a resume) comes from the same
checkout as the script, so it answers `escalated` and the new script reads it. An answer printed by an
older `launch` and fed to the new script halts with the new reason; re-running `launch` fixes it.

## Done when

- A resume after an escalation stays on `full` when the rerun design step returns Bounded (test 1).
- A resume after an escalation counts a further Bounded `plan-insufficient` toward `review-plan`'s bound,
  as one uninterrupted run does (test 2).
- `launch`'s answer carries `escalated`; the script halts before any agent without it (tests 3, 4).
- engine.md's *Which profile* sentence and the script comment on the profile switch hold as written;
  engine.md's `launch`, script and *Once, and one way* passages name the resume.
- The pipeline checks suite passes.

## Assumptions

1. **Q: Should the resume also carry the counting half — a later Bounded gap counted instead of exempt?**
   A (assumed): yes. The issue's direction is to seed `exempted`, which drives both; engine.md calls the
   exemption "once per run", and a resume continues the same run (the ledger, `loops`, the profile all
   carry over). Test 2 pins it.
2. **Q: A start answer without `escalated` — default to `false` or halt?** A (assumed): halt before any
   agent, as a missing `profile` or `tier` does. Launch and script ship in one checkout, so a missing key
   means a stale or hand-built answer; `false` is the cheaper, riskier side.
3. **Q: Rename `exempted`?** A (assumed): yes, to `escalated`, matching the arg and engine.md's wording.
   Four lines; no behaviour hangs on the name.
4. **Q: Also check that `escalated: true` comes with `profile: 'full'`?** A (assumed): no. Launch derives
   both from the same ledger read, and the first design step sets the profile from `escalated` anyway;
   another cross-check adds a halt no real answer can hit.
5. **Q: Where does `pipeline_escalated()` live?** A (assumed): `pipeline.php`, beside `pipeline_reset_at()`,
   the other ledger reader of `escalated`: it is a fact about the ledger, used for the profile and for
   counting alike. Confirmed by reading: `dispatch_cli.php` requires `pipeline.php`, and `tests/Pest.php`
   loads every checks file.
6. **Q: New dataset row or a separate test for the resume?** A (assumed): a separate `it(...)` placed
   directly after the in-run dataset. The dataset's closure builds its start without a ledger, and adding
   a ledger parameter to every row for one case is noisier than a sibling test.
7. **Q: Which step does test 1's resume start on?** (added by the plan step) A (assumed): `design:spec`,
   so the fixture carries `plan: 'plan.md'`. Without a recorded plan, `pipeline_design_step()` answers
   `plan` for a manifest with a spec, and the run would start on `design:plan`. A real resume after an
   escalation has `artifacts.plan` from the Bounded design, and its ledger ends on the `escalated` entry
   (not a plan return), so launch answers `startStep: 'spec'`, as `DispatchCliTest`'s escalation case
   already builds it.
8. **Q: Where does `escalated` sit in `launch`'s answer?** (added by the plan step) A (assumed): right
   after `tier`, before `agents`, beside the other two agent fields. `DispatchCliTest`'s *launches from the
   cursor…* compares the whole answer with `toBe()`, key order included, so that case gains
   `'escalated' => false` in that place: the one test that spells the answer out by hand.
9. **Q: Which other passages name `launch`'s answer fields?** (added by the plan step) A (assumed): two,
   both updated with the rest: engine.md §`autoflow`'s code block (`# → {"action":"start",…,"tier":…,"agents":{…}}`)
   gains `"escalated":…` after `"tier":…`, and the script's header comment (the fields launch hands it)
   names `escalated`. Neither is pinned by a test.
10. **Q: Which non-boolean values does test 3 cover?** (added by the plan step) A (assumed): the key
    unset, the string `'true'`, `1` and `null` — the shapes a hand edit or an older answer can take;
    `typeof … !== 'boolean'` catches all four.
