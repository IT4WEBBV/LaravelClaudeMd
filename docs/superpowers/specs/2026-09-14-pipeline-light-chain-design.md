# `pipeline` — a light chain, suite reuse, review fixes in the engine, brief composition — design

**Date:** 2026-09-14
**Status:** draft v2 (post-critique, owner decisions made) → owner read → implementation plan → PR
**Canonical home:** `IT4WEBBV/LaravelClaudeMd`, `skills/pipeline/` (global skill, symlinked into `~/.claude/skills/`).
**Amends:** `2026-07-24-pipeline-skill-design.md` (the leg list, "mode is the only knob", the pinned
navigation signature) and `2026-07-27-pipeline-mechanical-checks-design.md` (when the suite runs).
**Surfaced by:** a brainstorm on 2026-09-14, opening with: *"if it sometimes isn't total overkill to use
the pipeline as I do for simple tasks? … It's not even the token usage so much as how slow it goes
sometimes for simple changes."*
**Reviewed:** `/critique plan` on v1 returned *needs rework*. v1 had four defects:
- a light PR could not be opened with zero commits;
- the suite key never matched in repos that don't ignore `.claude/`;
- the hashing snippet was mis-scoped;
- the size threshold was calibrated on one metric and enforced on another.

The reviewer also showed that v1's one unmeasured risk could in fact be measured. Every point is folded
in below; the *Rework log* records how.

## Summary

1. **A second chain, `light`, opt-in per run.**
   - **Design:** brainstorming on its **Bounded** path, a short design with no spec or plan document,
     captured as the draft PR's `## Plan`.
   - **A short `review-plan`** over that ~20-line design, instead of a 450–1,500-line spec and plan.
   - **The PR opens after implement's first commit.**
   - **Unchanged:** `verify-ui` and `review-pr`. The gate set is the same as the full chain's.
   - **Escalation:** a light run moves to `full`, one way and mechanically, as soon as the change stops
     being small. This is the same one-way ratchet brainstorming already has.
2. **The full suite runs once per tree.** A green result is reused while the working tree's content is
   unchanged, and the reviewer is handed it. There is no upfront baseline, and no base-branch switching:
   a red suite is a failing step.
3. **Review fixes are applied by the engine session**, bounded to edits of documents it already holds.
4. **What a leg brief consists of is written down**, which retires three rules coordinators invented:
   mutation proofs for tests already seen red, an upfront suite baseline, and routine status checks to
   running subagents.

No model changes: every leg keeps the session model, and `/critique` keeps its own default.

## The evidence

Measured from Claude Code transcript timestamps: **70 pipeline runs producing 71 PRs**, 2026-08-06 to
2026-09-11, almost all BreinStraat2, plus Deploy and Asimo. **Every run was `auto`**, so interactive
timing is unmeasured.
- **Active time** means tool execution plus model turns; waits on a human are excluded.
- Legs were delimited by hand (±1 min each); aggregate shares are heuristic (±3 points).
- **Code lines** means added + deleted lines outside `tests/`, `docs/`, `.changelog/`, `*.md` and
  lockfiles.

| Run | Code lines | Spec/plan lines | Active min | Design + reviews | Implement |
|---|---|---|---|---|---|
| BreinStraat2 #1020 — autofocus | 2 | 631 | 43 | 73% | 14% |
| BreinStraat2 #1047 — operator fix, backend | 2 | ~460 | 54 | 55% | 37% |
| BreinStraat2 #997 — dead-code removal | 38 (5 added, 33 deleted) | 459 | 36 | 81% | 14% |
| Asimo #173 — promo label | 16 | 681 | 60 | 63% | 16% |
| Deploy #420 — stop previous release | 136 | ~1,520 | 123 | 70% | 19% |
| BreinStraat2 #1021 — caption margin | 40 (comment only) | — | 68 | 67% | 12% |

Code-size distribution over the 71 PRs:

| Code lines | ≤ 20 | ≤ 30 | ≤ 50 | ≤ 80 | ≤ 100 | ≤ 150 |
|---|---|---|---|---|---|---|
| added + deleted | 21 | 25 | 34 | 43 | 50 | 58 |
| added only | 24 | 31 | 40 | 53 | 55 | 63 |

The 34 PRs at ≤ 50 lines (added + deleted) have a median of 14.5 code lines, yet a median of 773 lines of
spec, plan and changelog.

Across all runs:
- **Critique subagents: ~22% of active time.**
- **Design subagents writing documents: ~11%.**
- **The engine's own model turns: ~28%**, which includes inline design and adjudication.
- **Full suite: median 5 runs per PR** (mean 5.5), median 91 s each and up to 600 s with parallel slots;
  ~9% of active time.
- **Not the problem:** stack setup (~2%) and PHPStan/Pint (~0.1%).
- **Implement and verify subagents' model time: ~5%**, so a faster model at those legs cannot move the
  total much.

**What `review-plan` caught on small PRs:** see *Review-plan on small changes* below. It was measured
from the gate ledgers projected onto the PR bodies.

## Goals

- **Speed.** A ≤ 50-line change reaches a reviewed draft PR in **≤ 25 active minutes**; today the small
  runs above take 36–60. Across all runs, the expected saving is ~25–30% of active time, not more: the
  engine's own turns remain, and design moves inline.
- **Review.** No path to a non-draft PR that skips `review-pr`, in either chain.
- **Escalation.** A run that turns out bigger than expected lands on the full chain at the first commit
  that shows it, without anyone having to notice.
- **Suites.** Fewer redundant full-suite runs in both chains, with no loss of what a suite run proves.

## Non-goals

- The engine choosing `light` by itself, in either mode. Under `interactive` it may *ask* (§2).
- Per-leg model selection.
- Changing `/critique`, `work-on`, `handoff`, `brainstorming` or `browser-verification`.
- Rewriting existing coordinator briefs, or the plans and specs already committed in BreinStraat2.

## Design

### 1. Chains as an enum: `PipelineChain::{Full, Light}`

Chain-specific facts live in one backed enum, not in `$chain === 'light'` branches spread over several
functions.

- `legs()`
- `escalation(array $triggers, int $codeLines): ?string` (always `null` on `Full`)

`PipelineChain::tryFrom($value) ?? PipelineChain::Full` carries the fail-strict default: anything that
is not exactly `light` behaves as `full`, the same rule `mode` follows.

| Leg | `Full` (today) | `Light` |
|---|---|---|
| **design** | `brainstorming` → `writing-plans`; spec + plan committed | `brainstorming` on its Bounded path; the design becomes the PR's `## Plan` (§2) |
| **review-plan** | `/critique plan` over spec + plan | `/critique plan` over the Bounded design (§4) |
| **handoff** | `handoff pr` | not in the chain; `implement` opens the draft PR after its first commit (§3) |
| **implement** | `work-on` logic; suite after each step | `work-on` logic; filtered tests per step, one full suite at the end, escalation check after every commit (§3, §5) |
| **verify-ui** | when `ui` fires | unchanged |
| **review-pr** | `/critique pr` | unchanged; the plan it reviews against is the PR's `## Plan` |

**Gate legs are identical on both chains:** `review-plan` and `review-pr`, plus `verify-ui` when `ui`
fires. The guarantee in `gates.md` stands unchanged: *no path to a non-draft PR that has not passed
`review-plan` and `review-pr`.*

**Navigation does not read the chain.** Light differs only by a non-gate leg: it has no `handoff`, and
`pipeline_next_leg` steps over that leg the way it already steps over an untriggered `verify-ui`. So
`pipeline_can_navigate($from, $to, $doneLegs, $triggers)` keeps the signature `PipelineTest.php:26–34`
pins, and its invariant holds: navigation reads which legs ran, never a human decision.

`gates.md`'s "no third mode and no per-gate override" gains a paragraph: *`mode` decides how a gate is
resolved; `chain` decides how heavy the design leg's output is and whether `handoff` runs. It never
changes which gates exist.*

**Invocation:** `/pipeline [interactive|auto] [light] <idea | pr#>`. `full` is the default.
- `light <spec-path>` is refused: a spec means the design was already judged worth writing.
- `light <pr#>` is accepted only as a resume of a PR carrying the light marker (§6). Any other PR is
  refused.

**Light is project-only.** In an `it4web/*` package repo, `pipeline_triggers` always sets `package`
(`triggers.php:27`), so a light run escalates at its first check. Invoking `light` in a package repo is
refused up front, rather than started and escalated.

**The chain is stored** as the manifest's optional `chain` field.

### 2. The light design leg — brainstorming's Bounded path

The station already exists. `brainstorming` classifies a task as **Bounded** — *"a well-scoped change to
code that already exists in this repo"* — and then presents *"a short design IN CHAT … and STOP … No
spec file, no implementation plan document"*, with a one-way ratchet: *"hidden complexity discovered
mid-task upgrades the path."* The light design leg invokes it; it does not reimplement it.

- **`interactive`:** `brainstorming`, told the chain is light. The human's approval of the Bounded
  design completes the leg. If brainstorming classifies the task Architectural, that is an escalation
  (§5) before anything was built.
- **`auto`:** the same brief shape the full chain's autonomous design leg uses. The engine follows the
  Bounded checklist, and instead of waiting for approval writes an **Assumptions** list: the questions
  it would have asked, and the answers it assumed. The run escalates when one of those answers would
  change what gets built.
- **The design is captured as `## Plan`** in `.claude/pipeline/<branch>.plan.md`, which is excluded from
  git (§7). `review-plan` reads it there (§4), and §3 copies it into the PR body. It holds:
  - **Problem**: as found in the code; bugs are reproduced first.
  - **Change**: the files involved.
  - **Test first**: the failing test, and why it can fail.
  - **Done when**: the observable result.
  - **Assumptions**: under `auto` only.

**The full chain's mirror-image bug is fixed here too.** Today, if brainstorming classifies a full-chain
task as Bounded, no spec is written, and `manifest_infer_cursor` reads `design` forever
(`manifest.php:33`). The full chain now handles that classification explicitly:
- **`interactive`:** the pipeline **asks the human** whether light is acceptable, as one explicit
  multiple-choice question.
  - **Yes** → `chain: light`: the Bounded design becomes the `## Plan`, and the run continues to the
    short `review-plan`.
  - **No** → brainstorming is told to take the Architectural path and write the spec and plan.
- **`auto`:** nobody can be asked, so brainstorming is told to take the Architectural path. Only the
  explicit `light` word selects light.

A Bounded classification never switches the chain on its own; a human choosing light is the only way
in.

### 3. Light implement opens the PR

The same `work-on` logic, in the same worktree and on the same model, with the same "leave the PR draft"
override.

- **The PR opens after the first commit.** `git push -u` and `gh pr create --draft` go after the first
  commit, normally the failing test. GitHub refuses a PR with no commits beyond the base, so the PR
  cannot open earlier. The body carries:
  - the marker `<!-- pipeline-chain: light -->`;
  - the `## Plan`;
  - the issue-closing links, which `implement` already sets.
- **The board Component step** runs as `handoff` documents it (`handoff/SKILL.md` §E.2.c *Component op het board
  zetten*). The
  pipeline points at that step; it does not restate it.
- **Suite:** filtered tests per step, and one full suite at the end of the leg, subject to §7.
- **Escalation check after every commit** (§5), not only at leg start. Mechanical escalation therefore
  fires at the commit that crosses the line, not after the whole change is written.
- **Implemented marker:** at the end of the leg, `implement` writes `<!-- pipeline-implemented: <sha> -->`
  into the PR body. That is the durable signal §6 reads.

### 4. The gates on light

**`review-plan`**
- `/critique plan .claude/pipeline/<branch>.plan.md`: the Bounded design, written there by §2. The path
  is excluded from git (§7), so the diff stays clean.
- The rubric is unchanged. The target is ~20 lines of design plus the code it names, not a spec, so the
  review is short.
- On the 28 small PRs measured, three quarters of what this gate integrated was process that §9
  retires. What remains is the ~1-in-4 real code catch (*Review-plan on small changes*).
- The pre-handoff failure rule applies unchanged. No PR exists yet, so bound exhaustion means no push
  and no PR; a loop-back goes to `design`.

**`verify-ui` and `review-pr`** are unchanged. The `review-pr` brief names the PR's `## Plan` as the plan
to review against, and carries §7's suite result.

### 5. Escalation to `full` — one way, mechanical

**Triggers.** Checked after every commit in `implement` and at the start of every later leg, over
`git diff origin/<base>...HEAD`:
- `pipeline_triggers()` fires `migration`, `auth` or `package`, **or**
- `pipeline_code_lines()` exceeds the threshold, counting **added + deleted** lines outside `tests/`,
  `docs/`, `.changelog/`, `*.md` and lockfiles. Deletions count because an over-deletion is exactly the
  risk `review-plan` caught on #997. `parse_diff()` gains a `removed` count, an additive change that
  leaves its existing callers untouched.

**Threshold: 100** code lines (owner decision). That covers 50 of the 71 PRs,
while the guidance for *choosing* light stays "about 50 lines".
- **Why the backstop sits higher than the guidance:** an escalation after commits exist costs a full run
  on top of a light one. So the backstop should catch changes that are clearly not small, not ones near
  the edge.
- **What the band would have cost:** at 50, the nine PRs between 51 and 80 lines would all have
  escalated, on the metric the evidence uses.

**The auth grep gets stricter for this purpose.** On the full chain a false `auth` positive is an
annotation; on light it forces a full run. `pipeline_triggers` ignores comment and docblock lines
(`//`, `#`, `*`, `/**`) when matching `authorize(` / `Gate::` / `Policy` / `can:`.

**Judgement escalations:**
- brainstorming classifies Architectural, or its ratchet upgrades the path (§2);
- an `auto` assumption would change what gets built (§2);
- `implement` finds that the work needs files or behaviour the plan did not name; the implement
  subagent returns "plan insufficient" instead of improvising.

**On escalation:**
1. Append a ledger entry `{gate: 'chain', leg, at, reason, outcome: 'escalated'}`.
2. Set `chain: full`.
3. Replace the PR's marker line, if the PR exists.
4. Move the cursor back to `design`; backward navigation is always allowed.

The full design leg writes a spec and plan covering the commits already made plus the remaining work,
and the run continues through `review-plan` and `handoff pr`. `handoff pr` updates an existing PR rather
than opening a second one.

**Failure policy after escalation:**
- Once a PR exists, `review-plan` bound exhaustion follows the *after-handoff* rule: leave the PR draft,
  write the reason into its body, stop.
- An escalation is not a loop-back. `review-plan`'s cycle count starts at 1 and is known, because the
  manifest is live. The existing unknown-count rule still covers a reconstructed run.

**Never `full` → `light`, and never by the engine.**

**Why the three content triggers escalate here, when on the full chain they only annotate:** on the full
chain `review-plan` reads a design that names the migration or the policy; on light it reads a few lines
that may not. `gates.md` already records that
authorization and migration defects "are the ones most easily missed in a quick PR skim, precisely
because they look small".

### 6. Reconstruction

`manifest_infer_cursor()` gains a `chain` probe and an `implementedMarker` probe, both read from the PR
body (`gh pr view --json body`).

**Light marker present:**
- no implemented marker → `implement`
- `uiNeeded` and no `verify-ui` record → `verify-ui`
- no `pr-review` ledger entry with `outcome: continued` → `review-pr`
- otherwise → `done`

Using the implemented marker instead of "implementation commits present" means a half-finished light
implementation resumes at `implement`, not `review-pr`.

**No marker, or no PR:** the existing full-chain inference. Two consequences:
- A light run that loses its manifest before its PR opens restarts `design`. On light that is cheap.
- A branch with commits but no PR reconstructs as `full`. That costs more time but errs toward strict.

### 7. Suite reuse — once per tree

**Key:** the content of the working tree, not the commit. A commit of already-tested content has the
same key. Computed as `pipeline_tree_key(string $worktree): string` in `checks/`, which runs:

```bash
tmp=$(mktemp)
cp "$(git rev-parse --git-path index)" "$tmp"
GIT_INDEX_FILE="$tmp" git add -A
GIT_INDEX_FILE="$tmp" git write-tree      # both commands need the variable
rm "$tmp"
```

Untracked, non-ignored files count; the real index is untouched.

**The manifest must not feed its own key.** Deploy and Asimo do not ignore `.claude/`, so a manifest
write would change the tree and reuse would never fire. At kickoff, the pipeline appends
`.claude/pipeline/` to the repo's shared `info/exclude` (`git rev-parse --git-common-dir`) unless
`git check-ignore` already matches it. It uses `info/exclude` rather than a committed `.gitignore` line,
so the PR diff stays clean. This also stops a stray `git add -A` from committing the manifest.

**Record:** the manifest's optional `suite` field, `{tree, outcome: green|red, passed, failed, at}`.

**Rule:** `pipeline_suite_needed(?array $last, string $tree): bool` returns `false` only for a `green`
result on the same tree. Every place that runs the full suite asks first: end of implement, after review
fixes, before ready.

**Reviewer:** the `review-pr` brief states *"full suite green over tree `<tree>` at `<sha>`:
N passed"*. Whether to re-run stays the reviewer's call.

**No baseline, and no base-branch switching.**
- A red full suite is a failing step, fixed and bounded like any other.
- When the engine believes a failure predates the change, it **halts** with the evidence
  (`engine.md` §Failure policy, machinery failure); it never files the failure as an annotation.
- Switching the worktree to the base commit under a running stack was rejected: branch switches desync
  vendor, migrations and assets, and a wrong red there would have been filed as "pre-existing", which is
  the fail-open direction.
- A broken base branch is a human problem, not something a run should route around.

**Exception to the manifest rules:** `manifest.md` says recomputable fields are never trusted from the
file. `suite` is kept anyway because it cannot go stale silently: it is used only when the current tree
key matches, and losing it costs one re-run. Its `manifest.md` entry says so by name.

### 8. Review fixes stay in the engine session — bounded

`engine.md` §`auto` gains: **the engine applies review fixes that are edits to documents it already
holds** (spec, plan, PR text) and small code fixes. A review that says the work is fundamentally wrong
still loops back to `design` or `implement`. No subagent is dispatched only to edit documents.

The evidence is thin and says so: n = 2 and confounded. Deploy #420's subagent spent 13.5 min on a
~1,520-line spec and plan, and Asimo #173 made its ten edits inline in 3.6 min on 681 lines. The rule
rests on the mechanism: a fresh subagent must re-read what the engine holds. The measured difference is
not the justification.

The bound answers `work-on`'s opposite rule (*"you do not do a leg's work yourself … the run then dies
of its own bookkeeping"*, `engine/chain.md:44–47`). Inline covers edits, not rework.

### 9. What a leg brief consists of

Coordinator briefs are free-form today, so they invent policy. The root fix is a positive rule, in the
shape `work-on`'s `engine/chain.md:62–73` already uses. `engine.md` gains **§What a leg brief consists
of**:
- pointers to the artifacts (spec, plan, PR, issue);
- the settled decisions and the manifest state the leg needs;
- the leg's overrides that `engine.md` itself prescribes (e.g. "leave the PR draft");
- **nothing a station does not ask for.**

**Plans and specs committed before 2026-09-14 are not exemplars** for test or proof policy. Many carry
rules this section retires, and a design subagent reading them would copy the rules forward.

The three rules this retires, as worked examples in that section:

| Rule found in 33 BreinStraat2 briefs (copied into 22 plans and 10 specs) | Cost measured | Instead |
|---|---|---|
| *"EVERY new assertion must be MUTATION-PROVEN"*, including a hash check that the mutation altered the file, often written up as a `*.proof.md` (37 exist; PR #1048's is 387 lines) | 4–9 filtered test runs per run plus the write-up; three of four `review-plan` fixes on #1047 policed this ceremony | Only for a test written **after** the code, such as a test on existing behaviour that could not fail (#1047's actual job) or one added during review fixes. A test written first has been seen red, and that is the proof. No proof documents; a few lines in the PR body |
| *"Measure your OWN suite baseline first"* | a full suite before any change; #1047: 531 s + 179 s | §7: no baseline; a red is a failing step |
| Status checks to running subagents (*"Status check only — no need to change what you are doing. Are you still working on the PR #964 review?"*), roughly 30, sent 1–3 min after dispatch | no reviewer finished sooner; each message interrupts a turn | Wait for the completion notification. A liveness check is for a suspected stall only: an agent past its usual upper end (~11 min for a `/critique` reviewer). Never dispatch a second agent for the same task |

**One home:** `engine.md` is the source for pipeline briefs. The memory
`feedback_never_double_dispatch_subagents` covers subagent liveness in any context and points here for
the pipeline case, so the two do not compete.

### 10. Code surface

| Change | Where |
|---|---|
| new backed enum `PipelineChain::{Full, Light}` with `legs()`, `gateLegs()`, `escalation()` | `checks/chain.php` |
| `pipeline_legs` and `pipeline_next_leg` take a `PipelineChain`, defaulting to `Full`; `pipeline_gate_legs` and `pipeline_can_navigate` unchanged | `checks/pipeline.php` |
| new `pipeline_suite_needed(?array $last, string $tree): bool`, `pipeline_tree_key(string $worktree): string` | `checks/pipeline.php` |
| new `pipeline_code_lines(string $diff): int`; comment lines ignored in the auth match | `checks/triggers.php` |
| `parse_diff()` gains a `removed` count per file | `skills/critique/checks/diff_parse.php` |
| `manifest_infer_cursor()` reads `chain` and `implementedMarker` | `checks/manifest.php` |
| stations table light column; §Suite reuse; the §`auto` bound; §What a leg brief consists of; kickoff `info/exclude`; invocation | `references/engine.md` |
| `chain` as the second knob that never changes which gates exist; escalation | `references/gates.md` |
| `chain`, `suite` fields; ledger gate `chain`; outcome `escalated` | `references/manifest.md` |
| invocation line | `SKILL.md` |
| memory points at `engine.md` for the pipeline case | `feedback_never_double_dispatch_subagents.md` |

### 11. What does not change

- The full chain's legs, gates and order.
- `verify-ui` and its proof store.
- Loop bounds.
- The PR stays draft until `review-pr`.
- `mode` semantics.
- Mechanical checks.
- Every station skill.
- The session model at every leg.

## Review-plan on small changes

Measured over the 42 pipeline PRs with ≤ 80 code lines.

**Where the record lives.** The `review-plan` ledger is projected onto the PR body in only ~14 of them.
The rest was recovered from the proof store's `run.json` and from the committed spec or plan (a section
headed *"What the `review-plan` gate changed"*). No record exists for 9 PRs; one ran no gate; one
records counts only. That leaves **28 classifiable PRs**.

**What got integrated.** Each item was classified as:
- **code-risk:** a concrete defect would have shipped;
- **test-quality:** the code was right, the test would not have proved it;
- **process:** proof format, counts, doc wording, mutation mechanics.

| Code lines | Classifiable PRs | Items | Code-risk | Test-quality | Process | PRs with a code-risk catch |
|---|---|---|---|---|---|---|
| ≤ 20 | 12 | 86 | 2 | 11 | 66 (+7 runbook) | 2 |
| 21–50 | 8 | 54 | 3 | 12 | 39 | 3 |
| 51–80 | 8 | 64 | 7 | 12 | 45 | 4 |
| **All** | **28** | **204** | **12 (6%)** | **35 (17%)** | **150 (74%)** | **9** |

The code-risk catches were concrete:
- **Asimo #173:** a literal left in the Blade would have rendered *"Promo Promo until 30-09-2026"*, with
  every planned assertion green.
- **BreinStraat2 #935:** making `complete()` private would have left the PDF 500 reachable through
  public `submitThemes()`.
- **#937:** a full-screen dialog would have had no way to close on a phone.
- **#1046:** `validate()` in an update hook would have wiped the whole error bag.
- **#942:** three legacy defects would have been reinstated.

**Reading it honestly:**
- **Share.** In **8 of 28** classifiable PRs (29%; at least 19% of all 42), `review-plan` caught a code
  defect that `review-pr` never mentioned. That is 2 of 12 at ≤ 20 lines, and 6 of 16 above that.
- **Counterfactual.** "Never mentioned by `review-pr`" is weaker than it sounds: `review-pr` only ever saw
  the code *after* the fix. Nothing measures whether it, or `verify-ui`, would have caught these later.
  Some are visible enough that `verify-ui` plausibly would have (the doubled label, the dialog).
- **Alternative count.** If a planned test that would pass with the defect present counts as code-risk,
  the count rises to 14 of 28.
- **Precision.** 74% of what the gate integrated was process, much of it policing the ceremony §9
  retires, and it was itself wrong at least four times (#1018, #975, #1046, #1002).

So the gate is mostly ceremony by volume, but a real catch lands in roughly one small PR in four. That
share grows above 20 lines. Light therefore keeps a short `review-plan` (§4, decision 2).

## Decisions log

1. **`light` is always a human choice** (owner decision): the explicit word, or a yes to the question
   §2 asks under `interactive` after a Bounded classification. *Rejected:* letting that classification
   switch the chain by itself; the owner wants to be asked. *Rejected:* auto-classification under
   `auto`, where nobody reviews the classification.
2. **Light keeps a short `review-plan`** (owner decision). *Rejected:* dropping it, as v1 and v2
   drafted. On small PRs the gate caught a real code defect in 8 of 28 classifiable cases, before the
   code existed. Once §9 removes the ceremony it mostly policed, and its target shrinks to ~20 lines,
   what it still costs is small.
3. **Content triggers escalate on light** instead of annotating (§5).
4. **No Sonnet for `implement`.** Proposed as "Opus plans, Sonnet implements". *Rejected:* implement and
   verify model time is ~5% of active time, and Sonnet is not the stronger coder. The owner's call:
   *"if it doesn't save time and sonnet is not a better coder then lets not use sonnet"*.
5. **Light has no `handoff` leg;** `implement` opens the PR after its first commit (§3).
   - *Rejected:* committing the failing test during design, which would blur design and TDD ownership.
   - *Rejected:* an `--allow-empty` commit.
   - *Rejected:* abstracting "open-or-update a draft PR", despite this being its third copy. Two of the
     three copies (`handoff` E.2, `work-on` leg 7) live in `DevOps-Claude-Config`, a colleague's repo,
     which this change does not edit. The pipeline copy points at `handoff`'s steps instead.
6. **Light design invokes brainstorming's Bounded path.** v1 reimplemented it while claiming it didn't
   exist.
7. **Suite key is the tree, not HEAD,** and the manifest is excluded via `info/exclude`.
   *Rejected:* a pathspec exclusion inside the key, which would leave the manifest committable.
8. **No base-branch comparison.** *Rejected:* v1's worktree switch to the merge-base, which fails open
   under a running stack. *Rejected:* base-branch CI as the baseline, since local-only failures never
   appear there; a local failure that also fails on base is a machinery halt anyway.
9. **Escalation threshold counts added + deleted,** matching the evidence. v1 calibrated on one metric
   and enforced another.
10. **The `PipelineChain` enum** carries chain facts, per the polymorphism house rule. Navigation keeps
    its pinned signature, because both chains have the same gates (§1).
11. **The brief composition rule lives in `engine.md`;** the memory points at it. Mutation proofs are
    kept for test-after, where the mutation is the point.
12. **Why the pipeline gets a light chain when `work-on` says "there is no 'small enough to skip the
    chain'"** (`work-on/SKILL.md:204`): light skips documents and the `handoff` leg, not the chain. It
    keeps design, both reviews, TDD and `verify-ui`.

## Validation strategy

- [ ] **Pest, `checks/tests`:**
  - `PipelineChain` legs (light has no `handoff`);
  - `tryFrom` on absent and mangled values;
  - escalation for each trigger, at the 100/101 boundary, and on deletions;
  - the auth match ignoring comment lines;
  - `pipeline_next_leg` on light stepping from `review-plan` to `implement`;
  - `pipeline_can_navigate` refusing a forward jump past an un-run `review-plan` or `review-pr` on a
    light run, with the pinned-signature test untouched;
  - `pipeline_code_lines` with nested `code/www/` paths and the exclusions;
  - `pipeline_suite_needed` for same tree + green, same tree + red, different tree, and null;
  - `pipeline_tree_key` in a throwaway repo: an untracked file changes the key, writing
    `.claude/pipeline/x.json` after `info/exclude` does not, and committing tested content does not;
  - `manifest_infer_cursor` for light at each stage, a half-implemented light PR (no marker) resuming at
    `implement`, and no-PR falling back to full.
- [ ] **Existing tests pass unchanged.**
- [ ] **After ~10 light runs, repeat the timing method:**
  - **Target:** a ≤ 50-line change reaches a reviewed draft PR in ≤ 25 active min.
  - **Escalations:** more than about 1 in 3 light runs escalating means the guidance for choosing
    `light` or the threshold is wrong.
  - **Quality:** compare `review-pr` loop-backs between light and full runs.
  - **Brief rules:** full suites per PR, filtered mutation runs and status messages to running subagents
    should all drop.

## Risks accepted

- **Light's `review-plan` reads a short design, not a spec.** It sees less than the full chain's review
  does. The content triggers escalate for exactly that reason (§5), and `review-pr` still reads the
  whole change.
- **Under `auto`, the Assumptions list is what `review-plan` audits;** no human approves the design.
- **Environment drift outside git** (`.env`, a rebuilt container) can make a reused green result stale.
  CI on push is the backstop.
- **A branch with commits but no PR reconstructs as `full`.**

## Owner decisions (all decided, 2026-09-14)

- **Who chooses light:** always a human. Under `interactive`, a Bounded classification makes the
  pipeline *ask*; it never switches on its own (§2, decision 1).
- **Threshold:** 100 code lines (§5).
- **Light keeps a short `review-plan`** (§4, decision 2).
- **No Sonnet** (decision 4).
- **Brief rules:** all three retired, written down in `engine.md` (§9).

## Rework log (v1 → v2)

| Review point | Change |
|---|---|
| Light PR with zero commits | handoff leg removed from light; PR opens after implement's first commit (§3) |
| Escalation never precedes code | check after every commit (§3, §5) |
| Suite key self-invalidates in Deploy/Asimo | `info/exclude` at kickoff, with a test (§7) |
| Mis-scoped `GIT_INDEX_FILE` | variable on both commands; `pipeline_tree_key` with a test (§7) |
| Threshold metric mismatch | added + deleted everywhere; distribution table; threshold as owner decision (§5) |
| review-plan value "unmeasurable" | measured from PR-body ledgers (*Review-plan on small changes*) |
| Deletions invisible | `parse_diff` counts removed lines (§5) |
| Light impossible in package repos | refused up front (§1) |
| Auth grep precision now load-bearing | comment lines ignored (§5) |
| Median suites 4 vs 5; 70 vs 71 | corrected (*The evidence*) |
| "Roughly half" not derived | goal restated as the ≤ 25-min target and ~25–30% overall (*Goals*) |
| §8 evidence n = 2 | stated as thin; rule rests on mechanism, bounded (§8) |
| brainstorming Bounded path reimplemented | light design invokes it; full chain's latent Bounded bug addressed (§2) |
| `work-on`'s "no small enough" not discussed | decision 12 |
| Pinned navigation signature | no longer bent: light keeps `review-plan`, so both chains share one gate set (§1) |
| §3 skipped board Component and closing links | both named (§3) |
| Failure policy after escalation | after-handoff rule; cycle count known (§5) |
| Unlisted ledger values | `chain` gate and `escalated` outcome added to `manifest.md` (§10) |
| `light <pr#>` undefined | resume-only, else refused (§1) |
| §9 vs memory home; old specs leak the rules | one home plus pointer; old plans/specs declared non-exemplars (§9) |
| Base comparison fails open under a running stack | removed; red is red, pre-existing is a halt (§7) |
| Light reconstruction over half a change | implemented marker in PR body (§3, §6) |
| §8 vs `work-on`'s context rule | bounded to edits (§8) |
| Polymorphism | `PipelineChain` enum (§1) |
| DRY third repetition | rejected with reason (decision 5) |
| Root cause of brief rules | positive composition rule (§9) |
