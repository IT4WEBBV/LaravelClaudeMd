# Engine — the trampoline loop every `/pipeline` runs

The pipeline holds **no long-lived state of its own**. Each invocation runs one turn of a loop
whose only memory is the manifest (`manifest.md`) and durable git/gh state. Gates and triggers
are `gates.md`. This file is the operational procedure.

## The loop

```
read manifest (or reconstruct it)         # manifest_read / manifest_infer_cursor
  → run the invariant check                # recorded artifact at recorded ref; PR as expected
  → pick the next leg                       # pipeline_next_leg(cursor, triggers)
  → run the leg                             # autonomous (subagent) or interactive (stop)
  → write the manifest                      # manifest_write
  → stop or continue                        # per mode / gate policy
```

**Control rule — the whole model, and it fails closed:**

- **Autonomous leg** → dispatched as a **fresh subagent** briefed from the manifest, which
  **returns a structured result to the loop**. A subagent returns to its caller, so the loop
  reliably advances and writes the manifest.
- **Interactive leg** → the pipeline **stops** and runs the station inline for the present human,
  then is **re-invoked** to continue (in a live session by saying so — see *Navigation*; after a
  `/clear`, by `/pipeline`, which reads the branch's manifest and resumes).
- **Auto-continuation ("run through") spans only autonomous legs.** The loop never tries to
  "become a skill inline and then regain control": a skill that tail-calls its successor (as
  `brainstorming` invokes `writing-plans`) would never return, so an inline auto-continuation
  would silently walk past the next gate. A lost leg **halts the chain; it never skips a gate.**

## Kickoff — resolve the worktree, then start the loop

The whole run lives in **one worktree**; the pipeline ensures one exists, creating it if the
current checkout isn't already it. Derive the starting point from the invocation:

- **From an idea** (no branch yet) → slugify the idea into a branch name — lowercase, replace
  each non-`[a-z0-9]` run with `-`, strip leading/trailing `-`, truncate ~50 chars at a word
  boundary, prefix `feature/` — then create the worktree for it:
  - **slot-enabled project** (has `scripts/worktree.sh`): `scripts/worktree.sh create <branch>`.
  - **otherwise** (e.g. this config repo): a plain feature branch in place — `git switch -c
    <branch>` or a harness-native worktree under `.claude/worktrees/<branch>`.
  - **headless with no such machinery and no consent** → stop and report; never mutate the
    primary checkout unattended.
- **From a spec-path or `pr#`** → the branch is known (the spec's branch; the PR's `head.ref`) →
  create the worktree for it, or use the current checkout if you are already on it.
- **Already launched inside a claimed feature worktree** → use it; create nothing.

Record the `worktree` absolute path in the manifest so every leg and every resume operates in
the right place. A resume locates the run's worktree via `git worktree list` for the branch.
**One worktree for the entire run** — `implement` reuses `work-on`'s logic but **not** its slot
claim, so no *second* slot ever appears mid-chain. **Created, never torn down**: teardown is
destructive and stays the human's call.

## Dev-stack readiness — pipeline-owned, no hesitation

Several legs need the worktree's stack: `implement` runs the suite after each step, and
`verify-ui` drives a real browser. **Before the first such leg (`implement`), the pipeline brings
the stack up itself, without asking** (`restart.sh`; non-destructive) and leaves it running
afterwards. Starting the stack is a routine owned action, never a "shall I start docker?" prompt
— `work-on` deliberately leaves stack *timing* to its caller, and under the pipeline the pipeline
*is* that caller. This is the house preference [[docker-stack-no-hesitation]]. If the stack
genuinely cannot start, that is a **hard failure** (below), not a reason to hesitate.

*Worktree now, stack later:* creating the worktree is cheap (git); the stack starts lazily, only
before `implement` — nothing is spun up merely to brainstorm.

## Stations — what each leg invokes

The pipeline **invokes** the existing skills; it never reimplements them. Leg names are exactly
`pipeline_legs()`: `design, review-plan, handoff, implement, verify-ui, review-pr`.

| Leg | Invokes | Interactive form | Autonomous form | Manifest I/O |
|---|---|---|---|---|
| **design** *(compound)* | `superpowers:brainstorming` → `superpowers:writing-plans` (one leg — brainstorming already tail-calls writing-plans; two legs would double-run it) | human drives the brainstorm dialogue, which chains into writing-plans; re-invoke `/pipeline` to continue | a subagent turns a tight brief into a spec **and must write the questions it would have asked plus its assumed answers into the spec**, so `/critique plan` audits exactly those assumptions | writes spec + plan pointers |
| **review-plan** | `/critique plan` | reviewer scores the plan; you verdict | read-only reviewer subagent returning the **verdict block** (below) | feeds the plan-approval gate **and** the project-vs-package judgment |
| **handoff** | `handoff pr` | — | pushes the branch, opens the **draft PR**; its PR comment is a **projection** of the manifest, not a second source of truth | writes the PR# pointer |
| **implement** | `work-on`'s logic **in the current worktree** (no second slot) — read the item, validate against the code, execute the plan **test-first, running the suite after each step**, set closing-issue links, mark ready | — | autonomous-capable; needs the stack up | updates `last_sha`, marks implemented |
| **verify-ui** *(conditional — runs only when `pipeline_triggers(...)['ui']`)* | `browser-verification` | the skill's "show me" hand-off is an interactive nicety | runs the check, **attaches annotated proof to the PR** | records `verifyUi`; **non-skippable once triggered** |
| **review-pr** | `/critique pr` | reviewer scores the whole change; you verdict | read-only reviewer subagent returning the **verdict block** (below) | feeds the PR-review gate |

## The verdict block — a review leg's return contract

`review-plan` and `review-pr` return a structured block. This is a **contract**, not a per-run
prompt convention: every decision below reads it.

| Field | Value |
|---|---|
| `verdict` | `approve` \| `approve-with-nits` \| `rework` |
| `findings[]` | each `{tier: 1\|2, claim, evidence, suggested_action}` |
| `architecture_judgment` | `none`, or the project-vs-package (or comparable) concern |

**A malformed or missing block is a machinery failure, not a finding.** Retry the reviewer
**once**; if it is still malformed, **halt**. Fail-closed is retained exactly where it belongs —
broken tooling — without being spent on findings.

## Gate policy `adjudicate` — reviews are proposals, not verdicts

`pipeline_resolve_policy('auto')` resolves both gates to `adjudicate`; `interactive` keeps `stop`
and none of this section runs (`gates.md`). Under `adjudicate` the reviews still run, unchanged —
what changes is who resolves their findings. **The engine continues by default and escalates only
what an independent check confirms.** The risk position behind that: the pipeline never merges, so
every output is a PR read before merge and the worst case is a discarded branch, while a needless
interrupt costs the one thing `auto` exists to protect.

**Triage** — route each finding exactly once:

| Finding | Route |
|---|---|
| Tier-2 | integrate directly; no adjudication (record `adjudication: none`) |
| Tier-1, an overall `rework`, or a non-`none` `architecture_judgment` | adjudicate |

**Adjudicate** — dispatch a **fresh subagent that never saw the design leg**, give it the finding
plus the code, and ask it to **refute** the claim, citing `file:line`. Independence is the point:
at `review-plan` the engine would otherwise be adjudicating a critique of a plan it just wrote —
the self-review bias `/critique` exists as a separate agent to avoid. Synthesise a non-`none`
`architecture_judgment` into a finding of its own (`{kind: 'architecture', claim: …}`) so it
travels the same path.

| Adjudication | Disposition |
|---|---|
| **refuted** (with cited evidence) | downgrade to advisory, log, continue |
| **confirmed** | **escalate** — stop with a packaged parcel |
| **uncertain** | continue; carry the finding **verbatim** into the PR body as an open question |

`pipeline_should_escalate($finding, $adjudication)` in `../checks/pipeline.php` is that table made
mechanical, and is total over every finding the triage produces. `uncertain` continuing is a
deliberate choice of the owner's risk position over the reviewer's caution: ambiguity does not buy
an interrupt, it buys a line in the PR.

**Integrate** — apply actionable Tier-2s and refuted Tier-1s to the spec/plan and commit.
Non-actionable ones (already-mitigated observations, notes for posterity) are recorded without an
edit.

**Log** — every finding, its adjudication, the cited evidence and the disposition go to the
manifest's `gate_ledger` (`manifest.md`) and are projected onto the PR. *Overruling a reviewer is
fine; overruling one invisibly is what turns a gate into decoration.*

## Failure policy — what still stops

Under `adjudicate` these are the only stops; everything else continues, with a record.

- **Hard failure** (a station errors: tests won't go green, a tool dies, the stack won't start,
  `work-on` hits a blocker, or a verdict block is still malformed after its one retry) → **halt.**
  Write the failure to the manifest; a human resumes. **No silent retry** beyond the single
  documented reviewer retry — a retry hides the failure and the machinery may be in an unknown
  state.
- **A confirmed Tier-1 or architecture concern** (`pipeline_should_escalate` → `true`) → **re-arm
  the next gate as a human stop** (a one-line `gate_policy` edit) and continue to a **packaged
  parcel** (branch pushed, PR open, review posted) so the human reads-and-verdicts.
- **Bound exhaustion.** A confirmed `rework` on the *plan* loops back to `design` **autonomously**
  — it never builds an implementation on a plan judged unshippable — and a `verify-ui` failure
  loops back to `implement`. Each loop is bounded to **2 cycles**; on what would be the third,
  **escalate** instead. The bound is what keeps an autonomous loop from churning indefinitely
  without ever surfacing. Count the cycles from that gate's `gate_ledger` entries
  (`manifest.md`), never from an in-memory counter.
- **Advisory finding** (a Tier-2, or a refuted Tier-1) → **log to the manifest, continue.**
- **Playwright genuinely unavailable** → **halt.** No visual claim without proof.

In `interactive` mode both gates are `stop`, so every finding reaches the present human and none
of this triage runs.

The scary content facts — a migration, an authorization change, a shared package — **no longer
stop the chain**: they are facts, not findings, so they become loud mandatory annotations on the
PR and in the ledger (`gates.md` §content triggers). `verify-ui` is untouched by that and stays
mandatory whenever the `ui` trigger fires.

## Navigation

Once loaded in a session the engine holds the manifest cursor, so it is driven by **natural
language** — *"next step"*, *"go to step X"*, *"re-run review-plan"*, *"skip ahead to handoff"*.
A slash command is only a cold-session trigger; there is no separate `/next`. Every jump goes
through `pipeline_can_navigate(from, to, doneLegs, triggers)`: **backward is free; forward past a
gate leg that has not run is refused** (`gates.md`). That refusal is the un-skippable-review
promise made mechanical.
