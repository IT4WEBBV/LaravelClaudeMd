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
| **review-plan** | `/critique plan` | reviewer scores the plan; you verdict | read-only reviewer subagent | feeds the plan-approval gate **and** the project-vs-package judgment gate |
| **handoff** | `handoff pr` | — | pushes the branch, opens the **draft PR**; its PR comment is a **projection** of the manifest, not a second source of truth | writes the PR# pointer |
| **implement** | `work-on`'s logic **in the current worktree** (no second slot) — read the item, validate against the code, execute the plan **test-first, running the suite after each step**, set closing-issue links, mark ready | — | autonomous-capable; needs the stack up | updates `last_sha`, marks implemented |
| **verify-ui** *(conditional — runs only when `pipeline_triggers(...)['ui']`)* | `browser-verification` | the skill's "show me" hand-off is an interactive nicety | runs the check, **attaches annotated proof to the PR** | records `verifyUi`; **non-skippable once triggered** |
| **review-pr** | `/critique pr` | reviewer scores the whole change; you verdict | read-only reviewer subagent | feeds the PR-review gate |

## Failure policy — halt, or demote-and-package

- **Hard failure** (a station errors: tests won't go green, a tool dies, the stack won't start,
  `work-on` hits a blocker) → **halt.** Write the failure to the manifest; a human resumes.
  **No silent retry** — a retry hides the failure and the machinery may be in an unknown state.
- **Blocking review finding** (`/critique` returns a Tier-1, or an overall *rework*) → **re-arm
  the next gate as a human stop** (a one-line `gate_policy` edit) and continue to a **packaged
  parcel** (branch pushed, PR open, review posted) so the human reads-and-verdicts.
  - **Exception — a *rework* verdict on the *plan* loops back to `design`** to revise the plan
    (or halts); it never builds the implementation on a plan already judged unshippable.
- **Advisory finding** (Tier-2) → **log to the manifest, continue.**
- **`verify-ui` failure** is a hard failure of that leg: a **broken UI loops back to `implement`**;
  Playwright genuinely unavailable **halts** (no visual claim without proof).

The scary Tier-1s — migrations, authorization, a shared package — are already covered: those are
non-skippable content gates (`gates.md`), so the chain has **already stopped** there in both
modes, and re-arming decides nothing.

## Navigation

Once loaded in a session the engine holds the manifest cursor, so it is driven by **natural
language** — *"next step"*, *"go to step X"*, *"re-run review-plan"*, *"skip ahead to handoff"*.
A slash command is only a cold-session trigger; there is no separate `/next`. Every jump goes
through `pipeline_can_navigate(from, to, doneLegs, triggers)`: **backward is free; forward past a
gate leg that has not run is refused** (`gates.md`). That refusal is the un-skippable-review
promise made mechanical.
