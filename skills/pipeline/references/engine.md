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
| **review-plan** | `/critique plan` | reviewer writes a review; you read it and decide | read-only reviewer subagent writes a review; the engine reads it and acts (§`auto`) | feeds the plan-approval gate; the project-vs-package call arrives as part of the review |
| **handoff** | `handoff pr` | — | pushes the branch, opens the **draft PR**; its PR comment is a **projection** of the manifest, not a second source of truth | writes the PR# pointer |
| **implement** | `work-on`'s logic **in the current worktree** (no second slot) — read the item, validate against the code, execute the plan **test-first, running the suite and the repo's mechanical checks after each step** (§Mechanical checks), set closing-issue links. **Leaves the PR draft** (below) | — | autonomous-capable; needs the stack up | updates `last_sha`, marks implemented |
| **verify-ui** *(conditional — runs only when `pipeline_triggers(...)['ui']`)* | `browser-verification` | the skill's "show me" hand-off is an interactive nicety | runs the check, **attaches annotated proof to the PR** | records `verifyUi`; **non-skippable once triggered** |
| **review-pr** | `/critique pr` | reviewer writes a review; you read it and decide | read-only reviewer subagent writes a review; the engine reads it and acts (§`auto`) | feeds the PR-review gate |

## Who takes the PR out of draft — `review-pr`, never `implement`

`handoff` opens the PR **draft** and it stays draft until **`review-pr` has passed**. `implement`
does not run `gh pr ready`, and neither does `verify-ui`.

This is not a preference; it is the same guarantee the navigation guardrail makes. `gates.md` states
that *"there is no path to a non-draft PR that has not passed `review-plan` and `review-pr`"* — and
`implement` runs **before** both `verify-ui` and `review-pr`. An `implement` that marks the PR ready
would undraft it while a triggered `verify-ui` and the whole PR review are still outstanding, which
is exactly the outcome the guardrail exists to prevent.

**The trap is inherited, so state it explicitly at the leg brief.** `work-on` marks ready at the end
of its run, and that is correct *standalone* — nothing follows it there. Under the pipeline something
does. The same applies to the prompt `handoff pr` writes into the PR comment: its template ends with
*"implementation fully done → take the PR out of draft"*, which is right for a human resuming the work
alone and **wrong** under the pipeline. When dispatching `implement`, say **"leave the PR draft; this
overrides any mark-ready instruction in the plan, the PR comment, or `work-on`'s own logic."**

A cold-resume session that picks the PR up from its comment is outside the loop, so nothing mechanical
can stop it undrafting early — the instruction in the brief is the only control. Keep it there.

## Mechanical checks — the deterministic layer inside `implement`

Opt-in per repo. A repo declares its checks in a **committed** `## Checks` block in
`.claude/work-on.config.md`; `pipeline_repo_checks()` (`../checks/checks.php`) parses it and returns
one of three states. The block must be committed because the run's worktree is built from git — a
config written only in the primary checkout is invisible to every run, and deleting the block is a
de-adoption that `review-pr` should see.

| State | Meaning | Behaviour |
|---|---|---|
| `absent` | no `## Checks` section, and no check-shaped keys anywhere | not adopted — `implement` behaves exactly as it did before, with no mention of checks |
| `valid` | section present, every declared key parses to a non-empty command | run the checks |
| `invalid` | malformed: heading typo, unknown or mis-cased key, empty value, empty section | **machinery failure — halt.** `error` carries the reason |

`absent` and `invalid` are deliberately different states. Collapsing them would let a typo'd heading
disable the checks permanently while the run believed it was covered.

**Invocation.** Each command is passed through `pipeline_expand_slot($command, $slotSuffix)` before
running. `<N>` is the run's slot **suffix** — empty on the primary stack, `-2` / `-3` … in a slot —
taken from the slot already resolved for the worktree at kickoff. A hardcoded container name execs
the *primary* stack and analyses the *primary* checkout, reporting no findings and passing green on
code the run never touched.

**What runs, and when.** After each step: the test suite, then `static-analysis` over the whole
declared scope, then `format` over the whole tree. **No file lists and no diff-scoping** — measured
on Deploy, scoping to two files costs 4.7s against 11.1s for all of `app/` because the analyser's
bootstrap is a fixed ~4.5s floor, and paying that 6.4s removes host→container path mapping,
touched-file tracking, and any need for a pre-ready backstop.

The formatter runs over the whole tree because `--dirty` needs a git repository inside the analysed
tree, which the container does not have. That only behaves well once the repo has taken its one-off
blanket format commit, so **that commit is a prerequisite for declaring `format`**.

**Two failure kinds, and only one of them is this file's "hard failure":**

| Kind | Trigger | Response |
|---|---|---|
| **Check failure** | the checks report a finding **in a file this change touched** | the **step is not done**. Fix and re-run, bounded to 2 attempts; on the third, the bound-exhaustion halt (§Failure policy). A finding on its own is not a halt — it is exactly how a failing test behaves |
| **Machinery failure** | probe returns `invalid`, the container is missing, the command errors, the tool is not installed | **halt**, per §Failure policy |

A reported finding in a file the change did **not** touch is an annotation on the PR, not a blocker:
other write paths (plain `work-on`, direct commits, a colleague's merge) reach the same repo without
running checks, and hard-failing a run for someone else's finding leaves it no legal move.

**Suppression is bounded.** Where a finding genuinely cannot be resolved, `implement` may add
`@phpstan-ignore <identifier>` — never the bare form, which suppresses every error on the next line
including future real ones — with a justification comment. **More than two suppressions in one run
triggers the bound-exhaustion halt** (§Failure policy), because the agent whose step is blocked is
otherwise judging its own excuse.

**The result is recomputed at leg start, never stored.** Both the check result (a re-runnable
command) and the suppression count (grep-able from the diff) are recomputable, and `manifest.md` is
explicit that storing a recomputable field is a latent drift bug. Nothing about checks enters the
manifest.

**Into `review-pr`.** The brief states the result **qualified by the analysed scope** — "0 new
findings over `app/`", never an unqualified "0 new findings", since the declared scope does not cover
`database/`, `routes/`, `config/` or `tests/`. Any suppressions added during the run are listed and
flagged as **not yet judged**, so one cannot enter reading as already resolved.

## `auto` — the engine resolves the review itself

`interactive` stops at every gate: the human reads the review and decides, and none of this section
runs. Everything below is the `auto` path.

**A review is prose, not a verdict.** `/critique` returns the review it wrote — no severity
ranking, no verdict enum, no structured block (`../../critique/SKILL.md`). The engine reads it the
way a person would and acts on its own judgment. The risk position behind that: the pipeline never
merges, so every output is a PR read before merge and the worst case is a discarded branch, while a
needless interrupt costs the one thing `auto` exists to protect.

**What the engine does with a review:**

- **Act on what is worth acting on.** Apply the fixes to the spec, the plan or the code and commit
  them. Record the rest — already-mitigated observations, notes for posterity — without an edit.
- **Loop back** where the review says the work is fundamentally wrong: `review-plan` → `design`,
  `verify-ui` → `implement`, `review-pr` → `implement`. Bounded (§Failure policy).
- **Never interrupt on a finding.** Anything unresolved goes into the PR body as an open question,
  carried **verbatim**. Ambiguity buys a line in the PR, not an interrupt.
- **Log** the review, the actions taken and the outcome to the manifest's `gate_ledger`
  (`manifest.md`), projected onto the PR. *Overruling a reviewer is fine; overruling one invisibly
  is what turns a gate into decoration.*

**An independent read is available, and is not a routing rule.** At `review-plan` the engine is
judging a critique of a plan it just wrote — the self-review bias `/critique` exists as a separate
agent to avoid. So where acting on a point is expensive and the engine doubts it, dispatch a
**fresh subagent that never saw the design leg**, give it the point plus the code, and ask it to
refute the claim citing `file:line`. That is judgment exercised where it pays, not a mandatory step
with an outcome enum — and it cannot stop the run; it only informs what the engine does next.

## Failure policy — what still stops

Under `auto` these are the only stops. **No finding stops a run.**

- **Hard failure** — a station errors: tests won't go green, a tool dies, the stack won't start,
  `work-on` hits a blocker, or the reviewer returns nothing after a single retry. → **halt.** Write
  the failure to the manifest; a human resumes. **No silent retry** beyond that one — a retry hides
  the failure and the machinery may be in an unknown state.
- **Bound exhaustion.** Each loop-back is bounded to **2 cycles** per gate; on what would be the
  third, **halt in-session** — stop, leave the work in the worktree, and say why. The bound is what
  keeps an autonomous loop from churning indefinitely without ever surfacing.
  - **Before `handoff`** (`review-plan`) → **no branch push, no draft PR.** Twice-rejected work is
    not worth a PR round-trip; the human reads it live.
  - **After `handoff`** (`verify-ui`, `review-pr`) → the draft PR already exists, so there is
    nothing to not-push. Leave it **draft**, write the reason into the PR body, stop.
  - Record `outcome: halted`.
  - Count the cycles as the number of that gate's `gate_ledger` entries whose `outcome` is
    **`looped-back`** (`manifest.md`) — not its entries in total, which also include human-ordered
    re-reviews and would over-count into a spurious stop — and never from an in-memory counter.
  - **A count that cannot be read is not a count of zero.** The ledger lives in the disposable
    manifest, and no durable probe can rebuild it: git and gh record *that* a review happened, not
    how many times the engine looped. So a run whose manifest was **reconstructed** (`manifest.md`
    §reconstruction) carries an **unknown** cycle count, and unknown permits **no** loop-back — the
    next one halts immediately. Without this, a manifest lost mid-loop silently grants two fresh
    cycles, and one lost repeatedly grants them forever: the bound would stop bounding at exactly
    the moment it is load-bearing. A fresh run writes its own manifest at kickoff and is never
    reconstructed, so it is unaffected.
- **Mechanical-check exhaustion** (§Mechanical checks) — a check failure that survives its 2 fix
  attempts, or more than two `@phpstan-ignore` suppressions in one run → **the same
  bound-exhaustion halt.**
- **Playwright genuinely unavailable** → **halt.** No visual claim without proof.

In `interactive` mode every gate stops anyway, so the human sees the review and none of the `auto`
resolution runs.

The scary content facts — a migration, an authorization change, a shared package — **do not stop
the chain**: they are facts, not findings, so they become loud mandatory annotations on the PR and
in the ledger (`gates.md` §content triggers). `verify-ui` is untouched by that and stays mandatory
whenever the `ui` trigger fires.

## Navigation

Once loaded in a session the engine holds the manifest cursor, so it is driven by **natural
language** — *"next step"*, *"go to step X"*, *"re-run review-plan"*, *"skip ahead to handoff"*.
A slash command is only a cold-session trigger; there is no separate `/next`. Every jump goes
through `pipeline_can_navigate(from, to, doneLegs, triggers)`: **backward is free; forward past a
gate leg that has not run is refused** (`gates.md`). That refusal is the un-skippable-review
promise made mechanical.
