# `pipeline` — a light chain, suite reuse, and review fixes in the engine — design

**Date:** 2026-09-14
**Status:** draft → user review → implementation plan → PR
**Canonical home:** `IT4WEBBV/LaravelClaudeMd`, `skills/pipeline/` (global skill, symlinked into `~/.claude/skills/`).
**Amends:** `2026-07-24-pipeline-skill-design.md` (the leg list and "mode is the only knob") and
`2026-07-27-pipeline-mechanical-checks-design.md` (when the suite runs). The gate model, the proof
store and the failure policy are otherwise untouched.
**Surfaced by:** a brainstorm on 2026-09-14, opening with: *"if it sometimes isn't total overkill to use
the pipeline as I do for simple tasks? … It's not even the token usage so much as how slow it goes
sometimes for simple changes."*

## Summary

1. **A second chain, `light`, opt-in per run.** No spec or plan documents and no `review-plan`: the
   engine writes a short plan into the draft PR body and the run goes straight to `implement`.
   `verify-ui` and `review-pr` are unchanged, so every PR still passes an independent review before it
   leaves draft. A light run **escalates to `full`** — one way, mechanically — when the change turns
   out not to be small.
2. **The full suite runs once per tree.** A green result is reused while the working tree's content is
   unchanged, the reviewer is handed it instead of re-running it, and there is no upfront baseline: a
   base-branch comparison runs only when the suite is red.
3. **Review fixes are applied by the engine session itself**, never by a subagent dispatched only to
   edit documents.

No model changes: every leg keeps the session model, and `/critique` keeps its own default.

## The evidence

Measured from Claude Code transcript timestamps: 70 pipeline runs between 2026-08-06 and 2026-09-11
(almost all BreinStraat2, plus Deploy and Asimo). **Every run was `auto`**, so interactive timing is unmeasured.
Time is *active* time — tool execution plus model turns — with waits on a human excluded. Legs were
delimited by hand from transcript markers (±1 min per leg); aggregate shares are heuristic (±3 points).

| Run | Code lines | Spec/plan lines | Active min | Design + reviews | Implement |
|---|---|---|---|---|---|
| BreinStraat2 #1020 — autofocus | 2 | 631 | 43 | 73% | 14% |
| BreinStraat2 #1047 — operator fix, backend | 2 | ~460 | 54 | 55% | 37% |
| BreinStraat2 #997 — dead-code removal | 38 | 459 | 36 | 81% | 14% |
| Asimo #173 — promo label | 16 | 681 | 60 | 63% | 16% |
| Deploy #420 — stop previous release | 136 | ~1,520 | 123 | 70% | 19% |
| BreinStraat2 #1021 — caption margin | 40 (comment only) | — | 68 | 67% | 12% |

Across all runs:

- **34 of 71 pipeline PRs changed ≤ 50 lines of code** (median 14), and still carried a median **773
  lines** of spec, plan and changelog.
- **Critique subagents take ~22% of active time**, design subagents writing documents ~11%, and the
  engine's own model turns (which include inline design and adjudication) ~28%.
- **The full suite runs a median 4 times per run** (median 91 s each, up to 600 s with parallel slots):
  a baseline before design, after implement, after review fixes, before ready — and sometimes the
  reviewer's own. ~9% of active time.
- **Not the problem:** stack and worktree setup (~2%), PHPStan/Pint (~0.1%).
- **Implement and verify subagents' model time is ~5%** of active time. A faster model at those legs
  cannot move the total much; the time is in documents, reviews and suites.
- Deploy #420 spent **13.5 min in a subagent dispatched only to apply review fixes** to the spec and
  plan; Asimo #173 made its ten review edits inline in 3.6 min.

What this does **not** establish: how often `review-plan` caught a real defect on a small change. The
ledgers that would show it live in local manifests. Dropping that gate in `light` is a judgement,
recorded in *Risks accepted*.

## Goals

- A ≤ 50-line change reaches a reviewed draft PR in roughly half its current active time.
- No path to a non-draft PR that skips `review-pr`, in either chain.
- A run that turns out bigger than expected ends up on the full chain without anyone having to notice.
- Fewer redundant full-suite runs in both chains, with no loss of what a suite run proves.

## Non-goals

- The engine choosing `light` by itself. Classification downward stays a human (or coordinator) call.
- Per-leg model selection.
- Changing `/critique`, `work-on`, `handoff` or `browser-verification`.
- The BreinStraat2 coordinator briefs — see *Open question*.

## Design

### 1. Two chains: `full` and `light`

| Leg | `full` (today) | `light` |
|---|---|---|
| **design** | `brainstorming` → `writing-plans`; spec + plan committed | the engine writes a short plan into the PR body (§2) |
| **review-plan** | `/critique plan` | not in the chain |
| **handoff** | `handoff pr` | the pipeline opens the draft PR itself (§3) |
| **implement** | `work-on` logic; suite after each step | `work-on` logic; filtered tests per step, one full suite at the end (§4) |
| **verify-ui** | when `ui` fires | unchanged |
| **review-pr** | `/critique pr` | unchanged; the plan it reviews against is the PR body's `## Plan` |

Gate legs: `full` → `review-plan`, `review-pr` (+ `verify-ui` when `ui`). `light` → `review-pr`
(+ `verify-ui` when `ui`). The guarantee in `gates.md` becomes: *no path to a non-draft PR that has not
passed `review-pr`, nor `review-plan` on the full chain.*

**Invocation:** `/pipeline [interactive|auto] [light] <idea | pr#>`. `full` is the default. `light` with
a spec-path is refused: a spec means the design was already judged worth writing.

**Who chooses:** the human at invocation, or a coordinator per issue. **Never the engine.** The engine
only ever moves a run from `light` to `full` (§5).

**Stored as** the manifest's optional `chain` field. Anything other than `light` — absent, mangled —
behaves as `full`, the same fail-strict rule `mode` already follows.

This makes `chain` a second knob beside `mode`, which `gates.md` currently rules out. The distinction
that justifies it: `mode` decides how a gate is resolved; `chain` decides which legs exist.

### 2. The light design leg

Run inline by the engine session: no subagent, and no `brainstorming` or `writing-plans`. Those two
produce exactly the documents this chain exists to skip, and `brainstorming` tail-calls
`writing-plans`.

1. **Validate the request against the code first**: read the files involved, and reproduce a bug
   before planning its fix. BreinStraat2 #1021 is the case: measuring showed the issue's premise was
   wrong.
2. **Write the plan**, ~10–30 lines, as the PR body's `## Plan` section:
   - **Problem**: as found in the code, not as the issue described it.
   - **Change**: the files and what changes in each.
   - **Test first**: the failing test, and why it can fail.
   - **Done when**: the observable result.
   - Under `auto`, also **Assumptions**: the questions it would have asked, and the answers it assumed.
     This is the same rule the full design leg follows.
3. **Escalate instead of writing the plan** when it cannot be written without a question whose answer
   changes what gets built, or when the change is plainly bigger than 50 lines of code (§5).

Under `interactive` the plan is shown in chat, and the human's go-ahead completes the leg.

### 3. Light handoff — the pipeline opens the PR itself

`git push -u` and `gh pr create --draft`, with a body holding the chain marker
`<!-- pipeline-chain: light -->` and the `## Plan`. There is no resume-prompt comment.

This is a deliberate exception to "the pipeline invokes stations, it never reimplements them".
`handoff pr` requires a spec path (its PR body is *"Implements design from `$SPEC_PATH`"*), and its
comment is a cold-session prompt derived from that spec. A light run has neither. That template also
ends with "take the PR out of draft", which is the trap `engine.md` §Who takes the PR out of draft
already warns about. The replacement is two commands with no station logic in them.

### 4. Light implement

The same `work-on` logic, in the same worktree, on the same model, with the same "leave the PR draft"
override. The difference is the suite: **filtered tests for each step, and one full suite at the end of
the leg**, subject to §7. A light change has one or two steps, so a full suite after each buys nothing
the final run does not.

### 5. Escalation to `full` — one way, mechanical

At the start of every leg after design, recomputed over `git diff origin/<base>...HEAD`:

- `pipeline_triggers()` fires `migration`, `auth` or `package`, **or**
- `pipeline_code_lines()` counts **more than 50** added lines outside `tests/`, `docs/`,
  `.changelog/`, `*.md` and lockfiles.

It also escalates on judgement: from design (§2), and from `implement` when the work needs files or
behaviour the plan did not name. The implement subagent returns "plan insufficient" rather than
improvising.

**On escalation:**

1. Append a ledger entry `{gate: 'chain', leg, at, reason, outcome: 'escalated'}`.
2. Set `chain: full` and replace the PR's marker line.
3. Move the cursor back to `design`, which navigation always allows.

The full design leg then writes a spec and plan covering the commits already made plus the remaining
work, and the run continues through `review-plan` and `handoff pr`. `handoff pr` updates an existing
PR rather than opening a second one.

**Never `full` → `light`**, and never by the engine.

**Why the three content triggers escalate here, when on the full chain they only annotate:** on the
full chain two reviews look at the change; on the light chain only one does. `gates.md` already records
that authorization and migration defects "are the ones most easily missed in a quick PR skim, precisely
because they look small".

### 6. Reconstruction

`manifest_infer_cursor()` gains a `chain` probe, read from the PR body's marker
(`gh pr view --json body`).

- **Marker present:**
  - not implemented → `implement`
  - `uiNeeded` and not verified → `verify-ui`
  - not reviewed → `review-pr`
  - otherwise → `done`
- **No marker, or no PR:** the existing full-chain inference.

A light run that loses its manifest before its PR exists therefore reconstructs as `full`. That costs
more time, but it errs toward strict.

### 7. Suite reuse — once per tree

- **Key:** the content of the working tree, not the commit. Copy the index to a temporary file, then
  `GIT_INDEX_FILE=<tmp> git add -A && git write-tree`. Untracked, non-ignored files count; the real
  index is untouched. A commit of already-tested content has the same key, so running the suite and
  then committing does not trigger a second run.
- **Record:** the manifest's optional `suite` field: `{tree, outcome: green|red, passed, failed, at}`.
- **Rule:** `pipeline_suite_needed($last, $tree)` returns `false` only for a `green` result on the same
  tree. Every place that runs the full suite asks first: end of implement, after review fixes, before
  ready.
- **Reviewer:** the `review-pr` brief states *"full suite green over tree `<tree>` at `<sha>`:
  N passed"*. Whether to re-run stays the reviewer's call; it simply no longer has to.
- **No upfront baseline.** When a full suite is red on a clean tree:
  1. Detach the worktree at the merge-base (`git switch --detach`).
  2. Run only the failing tests, filtered.
  3. Switch back.

  A test that also fails on the base is pre-existing: it becomes a PR annotation, not a step failure.
  This matches how a mechanical-check finding in an untouched file is already treated.

**Exception to the manifest rules:** `manifest.md` says recomputable fields are never trusted from the
file, and a suite result is recomputable. This field is kept anyway because it cannot go stale
silently: it is used only when the current tree hash matches, and losing it costs one re-run. Its entry
in `manifest.md` states this exception by name.

### 8. Review fixes stay in the engine session

`engine.md` §`auto` already says *"Apply the fixes to the spec, the plan or the code and commit them"*,
but not who does it. Add: **the engine applies review fixes itself; the only dispatch a review may
produce is a loop-back to `design` or `implement`.** A subagent started only to edit documents must
first re-read the spec, plan and review that the engine already holds.

### 9. Code surface

| Change | Where |
|---|---|
| `pipeline_legs(string $chain = 'full')`, `pipeline_gate_legs($triggers, $chain)`, `pipeline_next_leg(…, $chain)`, `pipeline_can_navigate(…, $chain)` | `checks/pipeline.php` |
| new `pipeline_chain(?string $value): string` — `light`, else `full` | `checks/pipeline.php` |
| new `pipeline_light_escalation(array $triggers, int $codeLines): ?string` — the reason, or null | `checks/pipeline.php` |
| new `pipeline_suite_needed(?array $last, string $tree): bool` | `checks/pipeline.php` |
| new `pipeline_code_lines(string $diff): int` | `checks/triggers.php` |
| `manifest_infer_cursor()` chain-aware | `checks/manifest.php` |
| stations table gains the light column; §Suite reuse; the §`auto` sentence; invocation | `references/engine.md` |
| `chain` as the second knob; light gate legs; escalation | `references/gates.md` |
| `chain` and `suite` fields; the `chain` ledger gate | `references/manifest.md` |
| invocation line | `SKILL.md` |

Parameters default to `full`, so every existing caller and test keeps its current behaviour.

### 10. What does not change

- The full chain's legs, gates and order.
- `verify-ui` and its proof store.
- The failure policy and loop bounds.
- The PR stays draft until `review-pr`.
- `mode` semantics.
- Mechanical checks.
- Every station skill.
- The session model at every leg.

## Decisions log

1. **`light` is opt-in, never chosen by the engine.** *Rejected:* auto-classification from the
   request. The classification would itself be unreviewed, and "this is too simple to need a design"
   is the rationalisation `brainstorming` names as its anti-pattern.
2. **Drop `review-plan`, keep `review-pr`.** *Rejected:* keeping both gates with shorter documents. On
   ≤ 50-line changes `review-plan` took 5–12 min, and the plan of a ≤ 50-line change can be read in its
   diff at `review-pr`.
3. **Content triggers escalate on `light`** instead of annotating (§5).
4. **No Sonnet for `implement`.** Proposed during the brainstorm as "Opus plans, Sonnet implements".
   *Rejected:* implement and verify model time is ~5% of active time, so the saving is small, and
   Sonnet is not the stronger coder. The owner's call: *"if it doesn't save time and sonnet is not a
   better coder then lets not use sonnet"*.
5. **The pipeline opens the light PR itself**, not via `handoff pr` (§3).
6. **Suite key is the tree, not HEAD.** *Rejected:* keying on the SHA, which re-runs when
   already-tested content is committed.
7. **Base-branch comparison only on red.** *Rejected:* base-branch CI status as the baseline. Local
   failures such as the `db_test` grant for parallel tests do not show up in CI.
8. **Threshold: 50 added code lines.** 34 of 71 PRs sat at or under it (median 14). Only added lines
   count, because `parse_diff()` tracks only added lines; a large deletion still reaches `review-pr`.
9. **Review fixes in the engine** is a rule, not a knob.

## Validation strategy

- [ ] Pest, `checks/tests`:
  - light legs, gate legs, `next_leg` and `can_navigate`, including a refused forward jump past an
    un-run `review-pr`
  - `pipeline_chain` on absent and mangled values
  - `pipeline_light_escalation` for each trigger and at the 50/51 boundary
  - `pipeline_code_lines` excluding tests, docs, markdown, changelog and lockfiles, including nested
    `code/www/` paths
  - `pipeline_suite_needed` for same tree + green, same tree + red, different tree, and null
  - `manifest_infer_cursor` for a marker-present PR at each stage, and for a light run with no PR
    falling back to full
- [ ] Existing tests pass unchanged; the `full` defaults keep today's behaviour.
- [ ] After ~10 light runs, repeat the timing method from *The evidence*:
  - **Target:** a ≤ 50-line change reaches a reviewed draft PR in ≤ 25 active minutes.
  - **Escalations:** if more than about 1 run in 3 escalates, the guidance for choosing `light` is
    wrong.
  - **Loop-backs:** compare `review-pr` loop-backs between light and full runs, as the quality signal.

## Open question — trimming coordinator briefs (undecided)

The BreinStraat2 coordinator writes ~6–8k-character briefs. Beyond what the skill asks, they add three
rules, found in 33 briefs and copied into 20+ BreinStraat2 plans:

- **"EVERY new assertion must be MUTATION-PROVEN"**, including "verify each mutation actually altered
  the file (hash before/after)". Proposal: a test written first has already been seen red, which is the
  mutation proof, so keep the rule only for tests added after the code.
- **"Measure your OWN suite baseline first."** Proposal: superseded by §7's base-branch comparison on
  red.
- **Status checks to running subagents** ("Status check only — no need to change what you are doing. Are you
  still working on the PR #964 review?"), roughly 30 of them. They probably follow the memory *never
  double-dispatch subagents — SendMessage's reply is the liveness check*. Proposal: no check before a
  reviewer has run past its usual upper end (~11 min); wait for the completion notification.

Decide separately: whether to adopt these, and whether they belong in `engine.md` or in the coordinator
guidance.

## Risks accepted

- **`review-plan`'s value on small changes is unmeasured**; dropping it in `light` is a judgement.
- **The base-branch comparison switches the run's worktree** while the slot stack is up. It only runs
  tests, on a clean tree, and switches straight back.
- **Environment drift outside git** (`.env`, a rebuilt container) can make a reused green result stale.
  CI on push is the backstop.
- **A light run that loses its manifest before its PR exists** reconstructs as `full`.
