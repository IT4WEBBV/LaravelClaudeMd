# `autoflow` picks one of three agent tiers — `full`, `medium`, `light` — from an explicit word — design

**Design size:** Architectural

**Date:** 2026-09-29
**Issue:** IT4WEBBV/LaravelClaudeMd#116 (follow-up to #51, PR #115)
**Canonical home:** `skills/pipeline/checks/agents.php` (the table, a new `AgentTier` enum, the start
profile); `design_size.php`; `kickoff.php` and `dispatch_cli.php` (the kickoff flags, `launch`'s start
answer); `brief.php`; `skills/pipeline/workflow/pipeline-autoflow.js`; pipeline `references/engine.md`
(§Agents per step, §Design size, §Kickoff), `references/manifest.md`, `references/gates.md` and `SKILL.md`.
**Written unattended** by the `design:spec` step of a `/pipeline autoflow` run. Every question the
brainstorm would have asked is answered in *Assumptions*, so `/critique plan` audits exactly those.
Nothing below was built or run.

## Problem

#51 put every `autoflow` step on an explicit model and effort from `PIPELINE_AGENTS`, with two profiles.
The word `light` means two things at once:

- as an invocation word (`/pipeline … light`, kickoff `--light`, the manifest's `light: true`) it
  **permits** a Bounded design;
- as a profile it is the agents a Bounded design runs on (`DesignSize::Bounded->profile()`), whether
  or not the word was said: a Bounded spec with no `light` in the manifest still runs on `light` today
  (`AgentsTest`, *a Bounded spec without the light flag*).

The owner wants a third, cheaper tier below today's `light` for tiny changes, and one word per tier, so
the word says which tier and the permission follows from it.

## Change

### Three tiers — `PIPELINE_AGENTS` in `checks/agents.php`

Today's `light` becomes `medium` unchanged; a new `light` is added with the issue's values:

| Step | `full` | `medium` | `light` |
|---|---|---|---|
| `design:spec` | opus high | opus medium | opus medium |
| `design:plan` | opus high | opus medium | opus medium |
| `review-plan:review` | fable high | fable medium | opus medium |
| `review-plan:resolve` | opus high | opus medium | sonnet medium |
| `handoff:run` | sonnet low | sonnet low | sonnet low |
| `implement:run` | opus high | opus high | sonnet high |
| `verify-ui:run` | sonnet high | sonnet medium | sonnet medium |
| `review-pr:review` | fable high | fable high | opus high |
| `review-pr:resolve` | opus high | opus medium | sonnet high |

`loopedBack` (`implement:run` opus xhigh), `retry` (opus xhigh) and `smoke` (sonnet low) stay as they
are and apply on every tier.

### `AgentTier` — the word, as an enum in `agents.php`

```php
enum AgentTier: string
{
    case Full = 'full';
    case Medium = 'medium';
    case Light = 'light';

    public static function fromManifest(array $manifest): self;   // `tier`, else a legacy `light: true` → Medium, else Full
    public function permitsBounded(): bool;                        // Medium and Light
    public function forDesign(DesignSize $size): self;             // Bounded → $this, Architectural → Full
}
```

- `fromManifest()`: `self::tryFrom($manifest['tier'])` when `tier` is set, else `Medium` when the
  manifest has a legacy `light: true`, else `Full`. A `tier` that is not one of the three reads as
  `Full`, as a mangled `**Design size:**` header reads as Architectural: the heavier side.
- `forDesign()` is the "up, never down" rule in one place: an Architectural design always runs on
  `full`; a Bounded design runs on the tier the word named, so a Bounded design with no word stays on
  `full`.
- `DesignSize::profile()` is removed; its one caller, `pipeline_start_profile()`, uses `forDesign()`.

The enum's values are the table's profile keys, so a profile *is* a tier's value; the loop-back,
retry and smoke entries are not tiers.

### Which profile — `pipeline_start_profile()` and the script

`pipeline_start_profile(array $manifest, DesignSize $size): string` keeps its three rules with the tier
in place of the flag:

1. `full` when the ledger has an `escalated` entry (one way, once per run, a resume included);
2. else, when `artifacts.spec` is set: `AgentTier::fromManifest($manifest)->forDesign($size)->value`;
3. else (no spec yet): `AgentTier::fromManifest($manifest)->value` — the named tier, `full` with no word.

`launch`'s `start` answer gains `tier: AgentTier::fromManifest($manifest)->value` beside `profile`, so the
script can take the named tier after a Bounded design step. The script's one design assignment becomes:

```js
profile = size === 'Bounded' && !exempted ? tier : 'full'
```

and the escalation assignment (`profile = 'full'`) stays. Every step, `design` included, runs on
`profile`; `settingFor()` is unchanged.

`completeAgents(agents, profile, tier, steps)` checks what the run can reach, by data rather than by
name: `agents.full` and `agents[tier]` each cover every step of the tables, `profile` is `'full'` or
`tier`, and `loopedBack`, `retry` and `smoke` as today. The profile a run can take is always `full` or
its tier (rules 1–3 and the two assignments), so this is the whole set. It is also forced: the script
may not contain the literal `'medium'`, since `LockStepTest` refuses every effort name in the script, and
`medium` is one. A missing or unknown `tier`, or one whose table misses a step, halts before any agent
with today's reason (`args carry no complete agents table: …`).

`pipeline_agent_table($override)` lays an override over the step in every tier and its loop-back entry:
it iterates `AgentTier::cases()` plus `loopedBack` instead of the literal `['full', 'light', 'loopedBack']`.

### The word, the flag and the manifest field

- **Invocation:** `/pipeline [interactive|autoflow] [medium|light] [base <branch>] <idea | number |
  spec-path>`. No word means `full`. `medium` or `light` permits a Bounded design, as `light` does today;
  no word requires Architectural in `autoflow` and asks in `interactive` (§Design size's table, its
  columns renamed *with `medium` or `light`* / *with neither*).
- **Kickoff:** `--medium` and `--light` replace `--light`. `dispatch_cli_kickoff_args()` parses them into
  `tier` (an `AgentTier`, `Full` by default); a second tier flag, the same or the other, is a usage
  error (`null`). `pipeline_kickoff_manifest()` writes `tier: "medium"` or `tier: "light"` when a flag
  named one, and nothing for `full`, as `light: true` is written today.
- **Manifest:** a `tier` field (`"medium"` | `"light"`; absent means `full`) replaces `light`. A manifest
  in flight with `light: true` and no `tier` reads as `medium` (`fromManifest()`), and manifest.md says
  so in the `tier` row. Nothing writes `light` any more.
- **Brief:** the design brief's size line reads the tier: `the Bounded path is permitted (`medium`)` /
  `(`light`)` when `permitsBounded()`, else `the Architectural path is required (no `medium` or
  `light`)`. A legacy `light: true` manifest gets the `medium` line.
- **Usage strings** in `dispatch_cli.php` (the header, `dispatch_cli_kickoff_args()`'s docblock, the
  `usage:` line) say `[--medium|--light]`.

### The docs, pinned by `LockStepTest`

- engine.md §Agents per step: the table gets a `medium` and a `light` column (`| Step | `full` |
  `medium` | `light` | Why |`), each *Why* cell covering the two lower tiers with the issue's reasons;
  the loop-back, retry and smoke rows repeat their cell three times. *Which profile* is rewritten for the
  tier and `forDesign()`; it names `tier` in `launch`'s answer.
- engine.md §`autoflow`: the `start` answer's example line and its key list gain `tier`.
- engine.md §Design size (*Who picks the size*), §Kickoff (the `tier` field in the first
  `manifest_write`, the `--medium|--light` usage lines), the `design` row of the leg table: `medium` or
  `light` where `light` stands today.
- gates.md: *`medium` and `light` are not modes* — they permit a Bounded design and pick the agents tier.
- manifest.md: the `tier` row replaces `light`, with the legacy `light: true` reading; the `agents` row
  says *every tier* instead of *both profiles*.
- SKILL.md: the invocation line and the bullet (`medium` or `light` permits a small design; `light` also
  runs cheaper models on most steps).
- `LockStepTest`'s engine.md table case renders four columns from `pipeline_agent_table([])`, still 12
  rows.

## Approaches considered

1. **A `tier` string field, an `AgentTier` enum, and `tier` in `launch`'s answer (chosen).** One field
   cannot collide with the old meaning of `light: true`; the enum holds the permit and the up-only rule
   as behaviour, not as conditionals in `brief.php`, `agents.php` and the script.
2. **Two booleans, `medium: true` and `light: true`.** The literal reading of "rename the field and add
   the new word", but a legacy `light: true` would then mean the new, cheaper `light`, the opposite of
   the issue's *Old manifests* rule, and both booleans set at once would need a tie rule.
3. **No `tier` in the answer: launch sends a `bounded` profile name instead.** Equivalent, but it hands
   the script a derived value where the named tier is the fact the rule is stated in.
4. **Keep `DesignSize::profile()` and pass it the tier.** The size would learn about agents; the
   up-only rule belongs to the tier, whose word it limits.

## What does not change

- Routing, statuses, the bound, the ledger, `interactive` (its agents are the session's; the word only
  permits Bounded there), `loopedBack` / `retry` / `smoke`, the override's shape and validation, and
  `run_cost.php` (`opus`, `sonnet` and `fable` already have factors).
- Measurement: the first runs on each tier show whether every pair is accepted and where the table needs
  tuning (the issue's *Open*); no run is part of this change.

## A run in flight when this lands

A running workflow keeps its script and args. A relaunch of a manifest with `light: true` starts on
`medium`, today's `light` values, so its agents do not change. The new script over an old cached `launch`
answer has no `tier` and halts before any agent with the agents-table reason.

## Done when

- `AgentsTest`: `pipeline_agent_table([])` holds `full`, `medium` and `light`, each with every `autoflow`
  step and the table's values; an override replaces its step in all three tiers and `loopedBack`;
  `AgentTier::fromManifest()` for `tier: medium`, `tier: light`, no field, a legacy `light: true`, `tier`
  beside a legacy `light: true` (the tier wins), and an unknown `tier` (`Full`); `permitsBounded()` per
  case; `forDesign()` per case and size; `pipeline_start_profile()` for: no spec and each word (and none),
  a legacy `light: true`, a Bounded spec with each word and with none (`full`), an Architectural spec
  with `light` (`full`), an escalation over a Bounded spec with `light` (`full`).
- `DesignSizeTest`: the `profile()` case is removed.
- `DispatchCliTest`: kickoff `--medium` and `--light` write `tier`, no flag writes none, two tier flags are
  a usage error; `launch`'s `start` answer carries `tier` beside `profile`; the existing profile cases use
  `tier` (the Bounded-spec case whose answer said `light` names `tier: medium` in its fixture).
- `BriefTest`: the design size line for `tier: medium`, `tier: light`, a legacy `light: true` and none.
- `AutoflowScriptTest`: `autoflow_start()` takes a `tier`; a `medium` run's Bounded design runs today's
  `light` settings; a `light` run's Bounded design runs `opus medium` on `design:spec`, then `opus medium`,
  `sonnet medium`, `sonnet low`, `sonnet high`, `sonnet medium`, `opus high`, `sonnet high`, with
  `implement:run` on `opus xhigh` after a `review-pr` loop-back; a `light` run whose spec returns
  Architectural and a Bounded escalation both move to `full`; a run with no word whose spec returns
  Bounded stays on `full`; the halt cases gain *no tier*, *a tier that is not a table* and *a step
  missing from the tier's table*, and *a profile that is neither full nor the tier* replaces the
  `medium` case.
- `LockStepTest`: the four-column table case.

## Assumptions

1. *Why a `tier` string and not `medium` / `light` booleans?* The issue asks to rename the field and add
   the new word, and separately that a legacy `light: true` reads as `medium`. With booleans the new
   `light: true` and the legacy one are the same bytes and mean different tiers; a `tier` field keeps
   `light: true` unambiguous as the legacy form.
2. *What does a Bounded spec with no word run on?* `full`. The issue says no word means `full` and the
   size moves a run up, never down. Today such a spec runs on `light`; in `autoflow` it can only arise
   from a spec-path invocation of a Bounded spec, since the brief requires Architectural without a word.
3. *Which tier wins when a manifest has both `tier` and a legacy `light: true`?* `tier`: it is the new
   field, and nothing writes both.
4. *What does an unknown `tier` value do?* It reads as `full` rather than halting `launch`, the same
   heavier-side fallback `DesignSize::fromSpec()` uses for a mangled header. The field is written only by
   kickoff, so an unknown value means a hand edit.
5. *Are `--medium --light` or a repeated flag allowed?* No: a usage error, so a typo never picks a tier
   silently.
6. *Must `completeAgents()` check all three tier tables?* No: it checks `full` and the run's own tier,
   the only tables the run can reach, and a check by name is not possible because the script may not
   contain `'medium'` (`LockStepTest`). The issue's "accepts the three" holds: each of them is accepted
   as `tier`.
7. *Does `light` change the design brief beyond the permit?* No. Both lower tiers permit Bounded; the
   tier changes only which agents run. The escalation triggers (`DesignSize::escalation()`) are the same
   on every tier.
8. *Does `retry` stay `opus xhigh` on `light`, where `review-plan:review` already runs on `opus`?* Yes:
   the issue keeps `retry` as it is; it fires on a review that returned `null`, and a rerun at a higher
   effort is still the compensation.
9. *Where does `interactive` record the word?* Its kickoff is session-driven (engine.md §Kickoff): the
   session writes `tier` exactly as `dispatch_cli.php kickoff` does. The tier has no agents effect in
   `interactive`.
10. *Why the enum in `agents.php` and not its own file?* The tier's only consumers are the agents table's
    profile rule and the brief's permit line; `agents.php` is already loaded wherever `brief.php` is
    (`dispatch_cli.php`, `tests/Pest.php`, both in that order).
11. No probe was needed: the change adds a table and a string to data the script already reads, and
    `agent()` already accepts `sonnet` and `opus` at `medium` and `high` in today's table.
