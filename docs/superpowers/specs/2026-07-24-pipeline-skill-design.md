# `pipeline` — walk the feature chain, interactive or unattended — design

**Date:** 2026-07-24
**Status:** draft (post-critique v1; rev. modes → `interactive`/`auto`, `/pipeline`-only navigation) → user review → implementation plan → PR
**Canonical home:** `IT4WEBBV/LaravelClaudeMd`, `skills/pipeline/` (global skill, symlinked into `~/.claude/skills/`).
**Source:** issue #14.
**Depends on (hardened in place, not a blocker):** `/critique` (`skills/critique/`) — the two review gates. It ships as-is and is improved as the pipeline exercises it.
**Reviewed:** an independent `/critique plan` pass on the pre-spec design produced 12 findings (2 Tier-1, 9 Tier-2, 1 Tier-3), verdict *rework*. Every finding is resolved below — folded into the design, or accepted as a documented limitation. The Decisions log records which and why.

## Summary

A `pipeline` skill that walks the full feature chain — **design → review-plan → handoff → implement → verify-ui\* → review-pr** — carrying state from one station to the next and running as much of it as the chosen mode — **`interactive`** or **`auto`** — allows. (\*`verify-ui` runs only when the change touches the UI.)

1. **It is the *spine*, not better station logic.** The stations already exist as skills. The pipeline carries state between them, decides how much runs without a human turn, and makes "did we skip review?" a structural property rather than a thing someone has to remember.
2. **A trampoline, not an orchestrator.** Read manifest → pick next leg → run it → write manifest → stop or continue. No long-lived brain accumulating the whole chain.
3. **Auto-continuation spans only autonomous legs.** A subagent leg returns to the loop; an interactive leg is a natural stop-and-re-invoke boundary. Nothing relies on regaining control mid-session.
4. **Two modes — `interactive` and `auto` — over a per-gate policy table.** The mode is the one choice you make; the table (rarely touched) holds the finer per-gate stop/report settings, so "run past the plan gate straight to a PR" is *data*, not a new mode.
5. **The manifest is a disposable local cursor.** Durable truth is git + gh + the committed spec/plan + the PR. A missing manifest is reconstructed, never fatal.
6. **The whole run stays in one worktree.** The pipeline claims no slot and tears none down.

## The chain today

```
brainstorming → writing-plans → [review plan] → handoff(pr) → work-on(pr) → [review pr]
```

| Station | Skill | Produces / does |
|---|---|---|
| brainstorming | `superpowers:brainstorming` | interactive; commits a spec to `docs/superpowers/specs/…`, then invokes writing-plans |
| writing-plans | `superpowers:writing-plans` | commits an implementation plan to `docs/superpowers/plans/…` |
| review plan | `/critique plan` | read-only reviewer scores the design/plan; stores nothing |
| handoff | `handoff pr` | pushes the branch, opens a draft PR, posts a next-session prompt as a PR comment |
| implement | `work-on <pr>` | reads the plan, executes it, marks the PR ready |
| review pr | `/critique pr` | read-only reviewer scores the whole change |

All six stations exist. What is missing is the spine.

## What this skill is for

Not better reasoning at any station — each station's skill already owns its own quality. The pipeline's value is four narrower things:

- **State survives the gaps between stations** without a human re-typing branch, spec path, PR number, and the chosen mode at each boundary.
- **The review gates become un-skippable by construction.** The failure the chain actually suffers is not a bad review; it is nobody remembering to ask for one. The pipeline cannot advance past a gate leg without the gate having run.
- **The mode is chosen once, explicitly.** Whether it runs unattended is one choice — `interactive` or `auto` — set at the top, not an ad-hoc decision re-made, differently, at every boundary.
- **Resume is free.** A chain that dies at station four resumes at station four, because the cursor is reconstructable from durable state.

Anything not justifiable against those four does not ship.

## Goals

- One entry point that walks the chain, invoking the existing station skills — never reimplementing them.
- Two modes (`interactive`/`auto`) over an explicit, inspectable per-gate policy; `auto` is the default working mode.
- **No path reaches a non-draft PR unless `review-plan` and `review-pr` each ran against the recorded artifact.** (The central falsifiable promise — see Validation.)
- Resume after any death or `/clear` without restarting from station one.
- Works unchanged across the many Laravel project repos these global skills run in.

## Non-goals

- **Replacing any station's judgment.** The pipeline sequences skills; it does not out-think them.
- **New review logic.** That is `/critique`. **New bug-hunting.** That is `/code-review`.
- **Claiming or tearing down worktree slots.** The pipeline runs in the worktree it was launched in (see §6).
- **Posting to GitHub beyond what `handoff`/`work-on` already do**, and nothing it writes ever addresses a person.
- **A findings store or any persistent state that is not reconstructable** from git + gh.

## Design

### §1 Execution model — trampoline

The skill is a loop, holding no long-lived state of its own:

```
read manifest (or reconstruct it) → run invariant checks → pick the next leg
  → run the leg → write the manifest → stop or continue
```

A **leg** is one station (with one exception: see the compound design station, §5). Each leg runs in one of two modes, chosen by the gate policy (§3):

- **Autonomous leg** → dispatched as a **fresh subagent** briefed from the manifest, which **returns a structured result to the loop**. Control return is guaranteed — a subagent returns to its caller — so the loop reliably advances and writes the manifest.
- **Interactive leg** → the pipeline **stops** and runs the station inline for the present human (a gate decision, an interactive brainstorm). It is **re-invoked** to continue — in a live session by just saying so ("next step", "go to step X"); after a `/clear`, by `/pipeline`, which reads the branch's manifest and resumes. An interactive leg is a natural `/clear`-able boundary.

**Auto-continuation ("run through") spans only autonomous legs.** The loop never tries to "become a skill inline and then regain control" — a skill that tail-calls its successor (as brainstorming invokes writing-plans) would never return, so an inline auto-continuation would silently walk past the next gate. Autonomous legs return; interactive legs stop. That is the whole control model, and it fails **closed** (a lost leg stops the chain; it never skips a gate).

### §2 The manifest

A local, gitignored file — `.claude/pipeline/<branch>.json` — that is a **disposable cursor**, not the source of truth. Durable truth is the committed spec + plan, the branch, and the PR (state + comments). It stores **pointers, never content**:

| Field | Purpose |
|---|---|
| `branch` / `pipeline_id` | identity |
| `mode` | `interactive` or `auto` |
| `gate_policy` | the resolved per-gate table (§3) |
| `cursor` | current leg + status |
| `artifacts` | spec path, plan path, PR number |
| `last_sha` | HEAD at the last completed leg |
| `gate_ledger` | which gates were approved / waived, when |
| `lease` | session id + timestamp (single-driver guard) |

Every leg **opens with an invariant check** — the recorded artifact exists at the recorded ref, the PR is in the expected state — and **halts on mismatch** rather than trusting the file. A field that is recomputable from git/gh is *derived at leg start, never trusted from the file*; storing it is a latent drift bug.

**Reconstruction.** A missing manifest (fresh checkout, or a torn-down and re-created worktree) is never fatal: the cursor is rebuilt by probing durable state — does a spec exist on the branch? a plan? a PR? is it draft or ready? Anything recorded only ephemerally (fine-grained review dispositions — `/critique` stores nothing by design) is re-established by re-running that leg (a re-review is cheap and stateless). The gitignored file only saves the probing when it is present.

Because the whole run stays in one worktree (§6), the manifest and its lease remain valid for the entire chain — there is no second worktree on the same branch for the lease to be blind to.

### §3 Autonomy — two modes over a per-gate policy table

Autonomy is **one choice — `interactive` or `auto`** — made once at pipeline start. Under it sits a **per-gate policy table**, derived from the mode and stored in the manifest, holding the finer per-gate stop/report settings. Keeping the two apart is deliberate: the *mode* answers "am I present?", the *table* answers "does this gate stop or just report?", and only the table is ever hand-edited. So "run past the plan gate straight to a PR" is not a third mode — it is `auto` with the plan-approval gate flipped to report-only, a visible one-line override for well-specified work.

There are two kinds of gate.

**Station-boundary gates** — the two irreducible human turns (plan approval, PR review):

| Mode | Behaviour |
|---|---|
| **`interactive`** | you are present; runs one leg, shows you, waits. Advance by just saying so (§7). (A "propose the command but don't run it" is a `--dry-run` flag, not a mode.) |
| **`auto`** *(default)* | runs the autonomous legs unattended and **parks at plan approval and PR review** with a report you verdict whenever you return; hard-stops at the content gates below and on failure. |

`auto` never blows through a review gate silently — it parks with a packaged parcel and waits. Flipping a specific gate to report-only (the old "run straight to an open PR" behaviour) is the one-line table override noted above.

**Content-triggered gates** — **non-skippable in both modes.** These are split honestly by how they are detected, because "reuse `/critique`'s detection" only covers part of the list:

| Gate | Trigger | Kind |
|---|---|---|
| touches an `it4web/*` package | `composer.json` `name` (the change is *in* a package repo, or bumps an `it4web/*` constraint) | **deterministic** |
| writes a DB migration | grep for writes under `database/migrations/` | **deterministic, but detective** — grep sees a migration *already written*, so this gate fires at the review-plan leg on the plan text, and again at review-pr on the diff; it is a checkpoint on written work, not a pre-emptive "about to touch" (see Known limitations) |
| touches authorization | grep for `authorize(` / `Gate::` / `Policy` / `can:` / auth middleware in the diff or plan text | **deterministic** (added for this design — `/critique` has no auth detector) |
| the project-vs-package call | none mechanical — a `/critique plan` **judgment** the reviewer must return an explicit verdict on | **judgment** — always stops, because no signal deterministically says "this should have been a package" |

A judgment gate stops the chain whenever the review-plan reviewer flags it, in both modes. Deterministic gates stop the chain when their trigger fires, in both modes. Neither is ever downgraded to report-only.

### §4 Failure policy — halt-and-demote

Two categorically different triggers, two responses:

- **Hard failure** (a station errors: tests will not go green, a tool dies, `work-on` hits a blocker) → **halt.** Write the failure to the manifest; a human resumes. **No silent retry** — a retry hides the failure and the machinery may be in an unknown state.
- **Blocking review finding** (`/critique` returns a Tier-1, or an overall *rework*) → **re-arm the next gate as a human stop** (a one-line gate-policy edit) and continue to a **packaged parcel** (branch pushed, PR open, review posted) so the human reads-and-verdicts rather than cold-boots-and-drives. **Exception:** a *rework* verdict on the **plan** does not demote-and-continue — it **loops back to revise the plan (or halts)**. Demote-and-continue is only valid when the next gate precedes further irreversible work; building the implementation on a plan already judged unshippable is not.
- **Advisory finding** (Tier-2) → **log to the manifest, continue.**

A `verify-ui` failure is a hard failure of that leg: a **broken UI loops back to implement**, and Playwright being genuinely unavailable **halts** (you cannot claim visual work without proof — §5).

The scary case — a Tier-1 on migrations, authorization, or a shared package — is already covered: those are non-skippable content gates (§3), so the chain has already stopped there in both modes, and re-arming decides nothing.

### §5 Stations — how the pipeline drives them

The pipeline **invokes** the existing skills; it does not reimplement them.

**Dev-stack readiness is the pipeline's job, not each leg's.** Several legs need the worktree's stack running — `implement` runs the test suite (`docker exec <slot>_web php artisan test`) after each step, and `verify-ui` drives a real browser. Before the first such leg the pipeline **ensures the stack is up** (starting it with the project's `restart.sh` if it isn't) and leaves it running afterwards; teardown stays the human's call, like the slot itself (§6). `work-on` deliberately leaves stack timing to its caller — under the pipeline, the pipeline *is* that caller.

- **Design station (compound).** `brainstorming` and `writing-plans` are **one leg**, because brainstorming already ends by invoking writing-plans. Modelling them as two legs with a cursor between would run writing-plans a second time. The design leg produces spec **and** plan and returns (or, interactively, hands off to the human-driven brainstorm which chains into writing-plans; the human re-invokes `/pipeline` to continue).
  - **`interactive`**: the human drives the brainstorm dialogue.
  - **`auto`**: a subagent turns a tight brief into a spec, **and must write the questions it would have asked plus its assumed answers into the spec**, so `/critique plan` audits exactly those assumptions. (Residual risk accepted — see Known limitations.)
- **review-plan** → `/critique plan`. Autonomous-capable (read-only reviewer subagent). Feeds the plan-approval gate and the judgment content-gate.
- **handoff** → `handoff pr`. Its PR comment is a **human-readable projection** of the manifest, not a second source of truth. Autonomous-capable.
- **implement** → `work-on <pr>`'s logic (read the item, validate against the code, execute the plan **test-first, running the suite after each step**, set closing-issue links, mark ready) **run in the current worktree** — the pipeline does not use `work-on`'s slot claim on the autonomous path (§6). Needs the stack up (tests). Autonomous-capable.
- **verify-ui** *(conditional — the one leg the pipeline adds beyond the hand-driven chain)* → when the diff touches UI (`*.blade.php`, Livewire components, `resources/css|js`, Alpine/Tailwind), a leg runs `browser-verification` for annotated screenshot proof and **attaches it to the PR**. **Non-skippable once the trigger fires** — the chain cannot reach review-pr without proof (the house rule made structural). Needs the stack up plus Playwright and the visual companion. Autonomous-capable; the skill's interactive *"show me"* hand-off is an `interactive`-mode nicety. Failure handling in §4.
- **review-pr** → `/critique pr`. Autonomous-capable. Feeds the PR-review gate.

### §6 Slots / worktree ownership

The pipeline **introduces no slot mechanism and claims no slot.** The entire run — pre-PR stations, implementation, and post-PR review — executes **in the worktree `/pipeline` was launched in.** Consequences:

- The manifest and lease stay valid for the whole run (one worktree, one lease). No cross-worktree boundary for the local manifest to fail to cross, and no second driver on the same branch.
- No per-run slot is claimed, so **nothing leaks** and there is nothing to tear down.
- **Autonomous work mutates wherever it runs**, so **launch `/pipeline` from a feature-branch worktree you have already claimed** (via `slots` / `worktree.sh`), never the primary checkout. This is the one operational rule the pipeline pushes onto the caller.

`work-on`'s slot-claiming remains available for the **human** picking up a PR cold in parallel — it is simply not part of the pipeline's autonomous path.

### §7 Invocation and navigation

```
/pipeline [interactive|auto] <idea | spec-path | pr#>   # start a run (mode defaults to auto)
/pipeline                                               # resume the current branch's run
```

**One slash entry point.** `/pipeline` starts a run, or — when a manifest, or reconstructable PR/branch state, for the current branch already exists — **resumes** it after the invariant checks. Mode defaults to **`auto`**.

**Navigation is natural language, not more commands.** Once the engine is loaded in a session it holds the manifest cursor, so you drive it by saying so — *"next step"*, *"go to step X"*, *"re-run review-plan"*, *"skip ahead to handoff"*. A slash command is only a guaranteed trigger to load the skill in a cold session; there is no separate `/next`.

**Jump direction has one guardrail.** Backward navigation is free — jump back to redo a review or revise the spec. Forward navigation **past a gate that has not run is refused** (you would skip a mandated review); the invariant checks and gate policy enforce this, the same mechanism behind the un-skippable-review promise.

- Global skill at `LaravelClaudeMd/skills/pipeline/`.

The standalone-skill choice (rather than folding the spine into `handoff`) is deliberate: `handoff` produces *one* cross-session artifact for *one* boundary; the pipeline sequences *all* boundaries and owns the autonomy policy and the manifest. Folding it into `handoff` would give `handoff` two unrelated jobs and still need the manifest. `handoff`'s PR comment stays a projection of the manifest (§5), so the two do not become rival state stores.

## Validation strategy

- [ ] **The central promise is falsifiable and holds:** no path reaches a non-draft PR unless `review-plan` and `review-pr` each ran against the recorded artifact SHA. Test by forcing a skip and confirming the chain cannot advance the cursor past the gate leg.
- [ ] **Control model fails closed:** a killed autonomous leg halts the chain; it never advances the cursor past an un-run gate. Verify on a two-station spike before building the rest.
- [ ] **Resume:** kill a run at the implement leg; re-invoke `/pipeline`; it resumes at implement, not at design. Then delete the manifest and confirm reconstruction from git + gh lands on the same cursor.
- [ ] **The compound design station runs writing-plans exactly once** — no duplicate/overwriting plan commit.
- [ ] **Each content gate fires:** a migration, an `authorize()`/`Gate::` change, an `it4web/*` package change, and a project-vs-package judgment each stop the chain even in `auto`.
- [ ] **verify-ui fires and blocks:** a UI-touching diff triggers `browser-verification`, the chain cannot reach review-pr without attached proof, and a broken-UI verify loops back to implement.
- [ ] **Stack readiness:** an `auto` run with the stack down brings it up before the implement leg; tests and verify-ui then run against it.
- [ ] **Failure policy:** a hard failure halts with no retry; a Tier-1 at review-plan loops back (does not build the implementation); a Tier-1 later demotes and packages to a PR; a Tier-2 logs and continues.
- [ ] **One-worktree invariant:** across a full `auto` run, no new slot is claimed and the manifest/lease never move worktrees.
- [ ] **`auto` (default)** stops at exactly the two gates and nowhere else on a clean run.
- [ ] **Navigation guardrail:** "go to step X" backward re-runs a station; forward past an un-run gate is refused.

## Known limitations (accepted)

- **Migration gate is detective, not pre-emptive.** grep sees a migration once it is written. The gate fires at review-plan (on the plan text) and review-pr (on the diff), not before `work-on` writes it. Accepted: a written-but-unmerged migration on a draft PR is still caught before merge, which is the point that matters.
- **`auto` brainstorm can build the wrong thing.** Turning a brief into a spec unattended invents requirements the user never confirmed, and no content gate catches "wrong feature." Mitigated — not eliminated — by writing the assumed answers into the spec for `/critique plan` to audit. Unattended design is for well-specified, low-risk work by definition; this is the price of that ambition.
- **Autonomous runs need the worktree's dev stack up.** `implement` (tests) and `verify-ui` (browser) both require it, so an `auto` run starts the stack (`restart.sh`) and leaves it running — extra resource use, and it assumes the worktree can bring its stack up unattended. Teardown stays manual.

## Open questions

- **Manifest schema and the exact reconstruction probes** — enumerated in the implementation plan, not here.
- **Whether the auth grep is precise enough** or floods on approved code — tune against real diffs, same bar as `/critique`'s stage-0 heuristics (a check that returns a long candidate list has moved noise, not removed it).
- **Whether the plan-gate report-only override gets used** in practice, or whether parked-`auto` (stopping at both gates) is where everything lives. Revisit after a month of real runs.

## Decisions log

| Decision | Rationale |
|---|---|
| Span the full chain; interactivity is a dial | The state machine's first node is the design station; autonomy decides whether it runs interactively or unattended |
| Manifest + fresh units, not a persistent orchestrator (Fable-confirmed) | An orchestrator holding interactive stations accumulates the whole chain in one context and, when it dies, must mirror to a file anyway — "model A with extra steps" |
| Manifest local + gitignored, treated as a disposable cursor | Durable truth is git + gh + committed artifacts; a lost manifest reconstructs. Keeps orchestration bookkeeping out of the reviewed diff |
| Auto-continuation spans only autonomous legs | Skills tail-call their successor rather than return; an inline auto-continuation would walk past a gate. Subagent legs return; interactive legs stop. Fails closed (review resolved finding #2) |
| brainstorming + writing-plans are one compound leg | brainstorming already invokes writing-plans; two legs would double-run it (review finding #6) |
| Two modes (`interactive`/`auto`) over a per-gate policy table | The scale did two jobs — present-or-not, and per-gate stop-or-report; the table already owns the second, so the mode need only own the first. "Run to a PR" becomes a one-line table override, not a level (Fable's table + user's collapse) |
| Content gates split into deterministic vs judgment triggers | "Reuse `/critique`'s detection" covered only migrations + package; auth needed a new grep and project-vs-package is irreducibly a judgment (review findings #1, #4) |
| Halt on hard failure; demote on blocking finding; loop-back on plan-rework (Fable-confirmed) | A hard failure means the machinery broke (unknown state → halt); a blocking finding means it worked (healthy signal → package and hand a parcel to the human). Building on a rework-verdict plan is never right (review finding #3) |
| In-place autonomous implementation; pipeline claims no slot | Dissolves the cross-slot manifest boundary, the cross-worktree lease blindness, and the slot leak in one move (review findings #7, #8, #9, #10). `work-on`'s slot claim is a human affordance |
| `auto` may brainstorm a tight brief unattended, with an assumptions-audit guardrail | Preserves the issue's "runs from a raw idea" ambition; the residual wrong-feature risk is documented and accepted (review finding #9) |
| Standalone skill, not folded into `handoff` | `handoff` owns one boundary; the pipeline owns all of them plus the autonomy policy and the manifest. The PR comment stays a projection, not rival state (review finding #12) |
| `/critique` is a live dependency hardened in place, not a blocker | Per the product owner: use it now, improve it as the pipeline exercises it |
| `verify-ui` conditional leg, non-skippable when the diff touches UI | Makes the house rule (no visual claim without proof) structural; folding it into implement would hide "did we verify?" again (user's call) |
| One slash entry point (`/pipeline`); navigation is natural language | A slash command only guarantees a cold-session trigger; once loaded, "next step" / "go to step X" drive the engine directly — a dedicated `/next` was surface without capability (user's call) |
| Forward-past-an-unrun-gate navigation refused; backward free | Arbitrary "go to step X" must not become a way to skip a mandated review; the invariant checks already enforce it |
| Dev-stack readiness is pipeline-owned | `implement` (tests) and `verify-ui` (browser) both need the stack; making the pipeline bring it up once beats each leg re-solving it |
