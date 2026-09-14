# `pipeline` — proportional design, suite reuse, review fixes in the engine, brief composition — design

**Date:** 2026-09-14
**Status:** draft v3 (one chain, owner decisions made) → owner read → implementation plan → one PR
**Canonical home:** `IT4WEBBV/LaravelClaudeMd`, `skills/pipeline/` (global skill, symlinked into `~/.claude/skills/`).
**Amends:** `2026-07-24-pipeline-skill-design.md` (what the design leg produces) and
`2026-07-27-pipeline-mechanical-checks-design.md` (when the suite runs).
**Surfaced by:** a brainstorm on 2026-09-14, opening with: *"if it sometimes isn't total overkill to use
the pipeline as I do for simple tasks? … It's not even the token usage so much as how slow it goes
sometimes for simple changes."*
**Reviewed:**
- **v1:** `/critique plan` returned *needs rework*.
- **v2:** a separate Fable opinion found the idea right but the shape wrong: *"'light' should be a size of
  the design leg on the one chain, not a second chain"*. The owner agreed.

See the *Rework log*.

## Summary

1. **One chain, with a design leg sized to the change.**
   - **Architectural** (today): brainstorming → writing-plans, with a full spec and plan.
   - **Bounded:** brainstorming's existing short path, committed as a **~15-line spec and a ~10-line
     plan**.

   Every leg after design runs unchanged on either size: `review-plan`, `handoff`, `implement`,
   `verify-ui`, `review-pr`. Gates, navigation, reconstruction and `handoff` need no new machinery.
   - **Who picks:** the size is always a human's choice. The word `light` permits Bounded; without it,
     under `interactive`, a Bounded classification makes the pipeline ask.
   - **Escalation:** a Bounded run **grows its plan**, one way and mechanically, as soon as the change
     stops being small.
2. **The full suite runs once per tree.** A green result is reused while the working tree's content is
   unchanged, and the reviewer is handed it. There is no upfront baseline and no base-branch switching.
3. **Review fixes are applied by the engine session**, bounded to edits.
4. **What a leg brief consists of is written down**, which retires three rules coordinators invented:
   mutation proofs for tests already seen red, an upfront suite baseline, and routine status checks to
   running subagents.

No model changes. Everything ships in one PR.

## The evidence

Measured from Claude Code transcript timestamps: **70 pipeline runs producing 71 PRs**, 2026-08-06 to
2026-09-11, almost all BreinStraat2, plus Deploy and Asimo. **Every run was `auto`**, so interactive
timing is unmeasured.
- **Active time** means tool execution plus model turns; waits on a human are excluded.
- Legs were delimited by hand (±1 min each); shares are heuristic (±3 points).
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

**Code-size distribution over the 71 PRs:**

| Code lines | ≤ 20 | ≤ 30 | ≤ 50 | ≤ 80 | ≤ 100 | ≤ 150 |
|---|---|---|---|---|---|---|
| added + deleted | 21 | 25 | 34 | 43 | 50 | 58 |
| added only | 24 | 31 | 40 | 53 | 55 | 63 |

The 34 PRs at ≤ 50 lines have a median of 14.5 code lines, yet a median of 773 lines of spec, plan and
changelog.

**Where active time goes, across all runs:**

| Share | Where |
|---|---|
| ~28% | engine model turns (inline design, adjudication, PR and proof prose) |
| ~22% | critique subagents |
| ~11% | design subagents writing documents |
| ~9% | full suites (median 5 per PR, 91 s each, up to 600 s with parallel slots) |
| ~8.5% | other bash, file and proof plumbing |
| ~6% | browser |
| ~6% | CI polling and sleeps |
| ~5% | implement and verify subagents' model time |
| ~3% | filtered test runs, mostly mutation proofs |
| ~2% | stack setup |
| ~0.1% | PHPStan/Pint |

**Why the documents are so large:** the pipeline forces every change through brainstorming's
Architectural path. Its autonomous design brief says *"turn a tight brief into a spec"*
(`engine.md:83`). If brainstorming does classify a task Bounded, which writes no spec, the run hangs at
`design` forever (`manifest.php:33`). The 631-line spec for a 2-line autofocus is the pipeline
overriding a station that already knew the task was small.

## Review-plan on small changes

Measured over the 42 pipeline PRs with ≤ 80 code lines.

**Where the record lives.** The `review-plan` ledger is projected onto the PR body in only ~14 of them.
The rest was recovered from the proof store's `run.json` and from the committed spec or plan. There is
no record for 9 PRs; one ran no gate; one records counts only. That leaves **28 classifiable PRs**.

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
  defect that `review-pr` never mentioned.
- **Counterfactual.** `review-pr` only ever saw the code *after* the fix, so nothing measures whether
  it, or `verify-ui`, would have caught these later.
- **Alternative count.** If a planned test that would pass with the defect present counts as code-risk,
  the count rises to 14 of 28.
- **Precision.** 74% of what the gate integrated was process, much of it policing the ceremony §7
  retires, and the gate was itself wrong at least four times (#1018, #975, #1046, #1002).
- **By size.** The catch rate was highest in the 51–80 band (4 of 8 PRs).

So the gate stays on every design size.

## Goals

- **Speed.** A ≤ 50-line change reaches a reviewed draft PR in **≤ 25 active minutes**; today the small
  runs above take 36–60. Across all runs, the expected saving is ~25–30% of active time.
- **Review.** No path to a non-draft PR that skips `review-plan` or `review-pr`, for any design size.
- **Escalation.** A change that turns out bigger than expected gets a grown plan and a fresh
  `review-plan` at the first commit that shows it, without anyone having to notice.
- **Suites.** Fewer redundant full-suite runs, with no loss of what a suite run proves.

## Non-goals

- The engine choosing the Bounded size by itself, in either mode.
- Per-leg model selection.
- Changing `/critique`, `work-on`, `handoff`, `brainstorming` or `browser-verification`.
- Rewriting existing coordinator briefs, or plans and specs already committed in BreinStraat2.

## Design

### 1. One chain, two design sizes

The legs, their order, the gate set, `pipeline_legs()`, `pipeline_next_leg()`,
`pipeline_can_navigate()` (with its pinned signature) and `manifest_infer_cursor()` are all unchanged.
Only what the design leg writes differs:

| | **Architectural** (today) | **Bounded** |
|---|---|---|
| Station | `brainstorming` → `writing-plans` | `brainstorming` on its Bounded path; `writing-plans` is not invoked |
| Spec | full design | `docs/superpowers/specs/<date>-<slug>-design.md`, ~15 lines |
| Plan | bite-sized TDD plan | `docs/superpowers/plans/<date>-<slug>.md`, ~10 lines |
| Size recorded | no header (absent = Architectural) | spec header `**Design size:** Bounded` |

The size is **read from the committed spec**, not stored. `DesignSize::fromSpec($markdown)` returns
`Bounded` only for that exact header and `Architectural` otherwise, so every existing spec keeps today's
behaviour. This fails strict, the same way `mode` does.

**Why two short files and not one:** `handoff pr` finds the spec and plan in the last two commits
(A.4.a), validates that both exist (A.5), and otherwise falls back to the newest spec in the whole
directory (A.4.b), which on a busy repo is an unrelated one. Two short files keep `handoff` unchanged.

### 2. The design leg — who picks the size

**Invocation:** `/pipeline [interactive|auto] [light] <idea | spec-path | pr#>`. The word `light`
**permits** the Bounded size. It only matters while the design leg has not run; on a resume, the size is
read from the spec and `light` is ignored, with a note.

| | with `light` | without `light` |
|---|---|---|
| **`interactive`** | brainstorming runs normally; Bounded if it classifies Bounded | if brainstorming classifies Bounded, the pipeline **asks** as one multiple-choice question: *"This looks like a small change: continue with a short design (Bounded), or write the full spec and plan?"* A yes means Bounded; a no tells brainstorming to take the Architectural path |
| **`auto`** | the autonomous design leg is briefed that the Bounded path is permitted | briefed to take the Architectural path, which fixes today's hang |

Brainstorming's own rule stands in every cell: *"when in doubt between two paths, take the heavier one"*.
A classification never selects Bounded by itself; a human always has.

**Light is refused in `it4web/*` package repos.** The `package` trigger fires on repo identity
(`triggers.php:27`), and a shared package's blast radius is the case the pipeline treats as never small.
Bounded runs in project repos only.

**What the Bounded spec holds:**
- the `**Design size:** Bounded` header;
- **Problem**: as found in the code; bugs are reproduced first;
- **Change**: the files involved;
- **Done when**: the observable result;
- under `auto`, **Assumptions**: the questions it would have asked, and the answers it assumed.

**What the Bounded plan holds:**
- **Test first**: the failing test, and why it can fail;
- one to three **steps**, each ending in something verifiable.

The engine, or the autonomous design subagent, writes both files from brainstorming's in-chat design and
commits them as two commits, spec then plan, exactly as the full chain does. `review-plan` then reviews
them with the unchanged rubric; the target is ~25 lines plus the code they name.

### 3. Escalation — grow the plan

Checked **only when the spec says Bounded**:
- after every commit in `implement`, and
- at the start of every later leg,

over `git diff origin/<base>...HEAD`:
- `pipeline_triggers()` fires `migration` or `auth`, **or**
- `pipeline_code_lines()` exceeds **100**, counting **added + deleted** lines outside `tests/`, `docs/`,
  `.changelog/`, `*.md` and lockfiles.
  - Deletions count because an over-deletion is exactly what `review-plan` caught on #997.
  - `parse_diff()` gains a `removed` count, additively.

**Why 100:** it covers 50 of the 71 PRs, while the guidance for choosing `light` stays "about 50 lines".
An escalation after commits exist costs a grown plan plus a re-review, so the backstop catches changes
that are clearly not small. The 51–80 band is where `review-plan` caught the most; `review-plan` still
runs on Bounded runs, and the threshold is revisited against that band after measuring (see
*Validation strategy*).

**The auth match ignores comment and docblock lines** (`//`, `#`, `*`, `/**`). On an Architectural run a
false positive is an annotation; on a Bounded run it forces an escalation.

**Judgement escalations:**
- brainstorming's ratchet upgrades the path;
- an `auto` assumption would change what gets built;
- `implement` needs files or behaviour the plan did not name; the implement subagent returns "plan
  insufficient" instead of improvising.

**On escalation**, the run **grows its design instead of re-designing:**
1. Append a ledger entry `{gate: 'design-size', leg, at, reason, outcome: 'escalated'}`.
2. Cursor → `design`, in grow form, which backward navigation always allows:
   - the spec header becomes `**Design size:** Architectural`;
   - the spec gains a *Grown from Bounded* section: what changed, why it grew, what already exists (as
     state, not as design), what remains;
   - the plan gains the remaining steps;
   - both are committed.
3. `review-plan` re-runs over the grown spec and plan **plus the diff so far**.
4. `handoff pr` re-runs and updates the existing PR (its Scenario B), so the PR's resume prompt matches
   the grown plan.
5. `implement` continues.

**Gates count again after an escalation.** When `$doneLegs` is derived from the ledger, a gate leg counts
as done only if its entry is newer than the latest `design-size` escalation. A new pure function,
`pipeline_done_legs(array $ledger): array`, owns that rule. Otherwise a stale `review-plan` pass would
let navigation skip the re-review of the grown plan.

**Once per run and one way.** An Architectural spec never shrinks. Once a PR exists, `review-plan` bound
exhaustion follows the after-handoff rule, and an escalation is not a loop-back for the cycle count.

**Why `migration` and `auth` escalate** instead of only annotating: a 15-line Bounded spec may not name
the migration or the policy that the diff contains, so `review-plan` has not really seen it.

**Why `package` does not escalate:** in a package repo, Bounded is already refused (§2). In a project,
`package` fires on an added `it4web/*` constraint, which is typically a version bump; the bumped code
was reviewed in the package's own PR. It keeps its mandatory annotation.

### 4. Suite reuse — once per tree

**Key:** the content of the working tree, not the commit, so committing already-tested content does not
trigger a re-run. `pipeline_tree_key(string $worktree): string` runs:

```bash
tmp=$(mktemp)
cp "$(git rev-parse --git-path index)" "$tmp"
GIT_INDEX_FILE="$tmp" git add -A
GIT_INDEX_FILE="$tmp" git write-tree      # both commands need the variable
rm "$tmp"
```

**The manifest must not feed its own key.** Deploy and Asimo do not ignore `.claude/`. At kickoff, the
pipeline appends `.claude/pipeline/` to the shared `info/exclude` (`git rev-parse --git-common-dir`)
unless `git check-ignore` already matches it. This is local, so it adds nothing to the PR diff, and it
also stops a stray `git add -A` from committing the manifest.

**Record and rule:**
- The manifest's optional `suite` field holds `{tree, outcome: green|red, passed, failed, at}`.
- `pipeline_suite_needed(?array $last, string $tree): bool` returns `false` only for a `green` result on
  the same tree.
- Every full-suite point asks first: after each implement step, after review fixes, before ready.

**Reviewer:** the `review-pr` brief states *"full suite green over tree `<tree>` at `<sha>`:
N passed"*. Whether to re-run stays the reviewer's call.

**No baseline, and no base-branch switching.**
- A red full suite is a failing step.
- A failure the engine believes predates the change is a **halt** with evidence (machinery failure),
  never an annotation.
- Switching the worktree to the merge-base under a running stack desyncs vendor, migrations and assets,
  and would file a wrong red as "pre-existing", which is the fail-open direction.

**Exception to the manifest rules, by name:** a suite result is recomputable, but `suite` is kept
because it cannot go stale silently. It is used only when the current tree key matches, and losing it
costs one re-run.

### 5. Review fixes stay in the engine session — bounded

`engine.md` §`auto` gains: **the engine applies review fixes that are edits to documents it already
holds** (spec, plan, PR text) and small code fixes. A review that says the work is fundamentally wrong
still loops back to `design` or `implement`. No subagent is dispatched only to edit documents.

The evidence is thin and stated as such (n = 2, confounded): Deploy #420's doc-fix subagent ran 13.5 min
on ~1,520 lines, and Asimo #173 made ten edits inline in 3.6 min on 681. The rule rests on the mechanism:
a fresh subagent must re-read what the engine holds. The bound answers `work-on`'s opposite rule
(`engine/chain.md:44–47`): inline covers edits, not rework.

### 6. What a leg brief consists of

Coordinator briefs are free-form today, so they invent policy. `engine.md` gains **§What a leg brief
consists of**, in the shape of `work-on`'s `engine/chain.md:62–73`:
- pointers to the artifacts (spec, plan, PR, issue);
- the settled decisions and the manifest state the leg needs;
- the leg's overrides that `engine.md` itself prescribes (e.g. "leave the PR draft");
- **nothing a station does not ask for.**

**Plans and specs committed before 2026-09-14 are not exemplars** for test or proof policy.

The three rules this retires, as worked examples:

| Rule found in 33 BreinStraat2 briefs (copied into 22 plans and 10 specs) | Cost measured | Instead |
|---|---|---|
| *"EVERY new assertion must be MUTATION-PROVEN"*, including a hash check that the mutation altered the file, often written up as a `*.proof.md` (37 exist; PR #1048's is 387 lines) | 4–9 filtered runs per run plus the write-up; three of four `review-plan` fixes on #1047 policed it | Only for a test written **after** the code, such as a test on existing behaviour that could not fail (#1047's actual job) or one added during review fixes. A test written first has been seen red. No proof documents |
| *"Measure your OWN suite baseline first"* | #1047: 531 s + 179 s before any change | §4: no baseline; a red is a failing step |
| Status checks to running subagents (*"Status check only — no need to change what you are doing. Are you still working on the PR #964 review?"*), roughly 30, sent 1–3 min after dispatch | no reviewer finished sooner; each message interrupts a turn | Wait for the completion notification. A liveness check is for a suspected stall only: past ~11 min for a `/critique` reviewer. Never dispatch a second agent for the same task |

**One home:** `engine.md`. The memory `feedback_never_double_dispatch_subagents` covers liveness in any
context and points here for the pipeline case.

### 7. Code surface

| Change | Where |
|---|---|
| new backed enum `DesignSize::{Bounded, Architectural}`: `fromSpec(string $markdown)` (fail-strict) and `escalation(array $triggers, int $codeLines): ?string` (always `null` on `Architectural`) | `checks/design_size.php` |
| new `pipeline_done_legs(array $ledger): array` (gate entries older than the latest escalation do not count) | `checks/pipeline.php` |
| new `pipeline_suite_needed(?array $last, string $tree): bool`, `pipeline_tree_key(string $worktree): string` | `checks/pipeline.php` |
| new `pipeline_code_lines(string $diff): int`; comment lines ignored in the auth match | `checks/triggers.php` |
| `parse_diff()` gains a `removed` count per file | `skills/critique/checks/diff_parse.php` |
| design-leg sizes, the `light` word and the interactive question; grow-the-plan escalation; suite reuse; kickoff `info/exclude`; the §`auto` bound; §What a leg brief consists of | `references/engine.md` |
| `light` permits a design size, not a mode and not a chain; gates unchanged; escalation re-arms gates | `references/gates.md` |
| `suite` field and its exception; ledger gate `design-size`, outcome `escalated`; the done-legs rule | `references/manifest.md` |
| invocation line | `SKILL.md` |
| memory points at `engine.md` for the pipeline case | `feedback_never_double_dispatch_subagents.md` |

**Unchanged:** `pipeline_legs`, `pipeline_gate_legs`, `pipeline_next_leg`, `pipeline_can_navigate`,
`manifest_infer_cursor`, `manifest_validate`, and every existing test.

### 8. What does not change

- The legs, gates, order and navigation.
- Reconstruction.
- `handoff`, `verify-ui` and the proof store.
- Failure policy and loop bounds.
- The PR stays draft until `review-pr`.
- `mode` semantics.
- Mechanical checks.
- Every station skill.
- The session model at every leg.

## Decisions log

1. **Bounded is always a human's choice:** the `light` word, or a yes to the interactive question.
   *Rejected:* a Bounded classification selecting the size by itself (the owner wants to be asked).
   *Rejected:* auto-classification under `auto`.
2. **`review-plan` runs on every design size.** *Rejected:* dropping it for small changes. It caught a
   real code defect before the code existed in 8 of 28 small PRs.
3. **One chain, two design sizes** (Fable's opinion; owner decision). *Rejected:* v2's second chain.
   - **Machinery:** the design kept out of git, the PR opened by `implement`, chain and implemented
     markers in the PR body, a `chain` manifest field, extra reconstruction probes.
   - **A broken promise:** a reviewed plan with no SHA.
   - **Precedent:** this skill already added and then deleted a stored second knob (`gates.md:24–29`).
4. **A Bounded design commits a short spec and a short plan.** *Rejected:* a plan alone (Fable's
   suggestion), because `handoff pr` validates both paths and its fallback picks an unrelated spec.
   *Rejected:* changing `handoff`, which lives in a colleague's repo.
5. **Escalation grows the plan** and re-reviews it with the diff so far (Fable). *Rejected:* v2's
   "write a spec for the commits already made", a reverse-engineered design.
6. **`migration` and `auth` escalate a Bounded run** instead of only annotating; `package` (a constraint
   bump in a project) only annotates (§3).
7. **Threshold 100 code lines, added + deleted** (owner decision), revisited against the 51–80 band
   after measuring.
8. **Bounded is refused in `it4web/*` package repos** (blast radius). Fable's point, that this excludes a
   category where small fixes are common, is recorded; revisit once project runs are measured.
9. **No Sonnet for `implement`** (owner: *"if it doesn't save time and sonnet is not a better coder then
   lets not use sonnet"*).
10. **Suite key is the tree,** with the manifest excluded via `info/exclude`.
11. **No base-branch comparison.** *Rejected:* a worktree switch under a running stack (fails open).
    *Rejected:* base-branch CI as the baseline.
12. **The `DesignSize` enum** carries size-specific behaviour, per the polymorphism house rule.
13. **The brief composition rule lives in `engine.md`;** the memory points at it. Mutation proofs stay
    for test-after.
14. **Why proportionality, when `work-on` says "there is no 'small enough to skip the chain'"**
    (`work-on/SKILL.md:204`): nothing is skipped. Every leg and both reviews run; only the documents get
    smaller.
15. **One PR** (owner decision). *Rejected:* two PRs with a measurement in between (Fable). As a
    consequence, the combined saving is measured, but not attributed to individual changes.

## Validation strategy

- [ ] **Pest, `checks/tests`:**
  - `DesignSize::fromSpec`: the exact header, a missing header, and a mangled header;
  - `escalation`: per trigger, at the 100/101 boundary, on deletions, and always `null` on
    `Architectural`;
  - the auth match ignoring comment lines;
  - `pipeline_code_lines` with nested `code/www/` paths and the exclusions;
  - `pipeline_done_legs`: a `review-plan` pass older than the latest escalation does not count; a newer
    one does;
  - `pipeline_can_navigate` fed by `pipeline_done_legs` refusing a forward jump past the re-review;
  - `pipeline_suite_needed` for same tree + green, same tree + red, different tree, and null;
  - `pipeline_tree_key` in a throwaway repo: an untracked file changes the key, writing
    `.claude/pipeline/x.json` after `info/exclude` does not, and committing tested content does not.
- [ ] **Existing tests pass unchanged**, including the pinned navigation signature.
- [ ] **After ~10 Bounded runs, repeat the timing method:**
  - **Target:** a ≤ 50-line change reaches a reviewed draft PR in ≤ 25 active min.
  - **Escalations:** more than about 1 in 3 means the guidance for choosing `light` or the threshold is
    wrong.
  - **Quality:** `review-pr` loop-backs on Bounded versus Architectural runs.
  - **Threshold:** `review-plan` code-risk catches on Bounded runs in the 51–100 band. If they recur,
    lower the threshold.
  - **Brief rules:** full suites per PR, filtered mutation runs and status messages to running subagents
    should all drop.

## Risks accepted

- **A Bounded `review-plan` reads ~25 lines, not a full design.** It sees less; the escalating triggers
  and `review-pr` over the whole change are the counterweight.
- **Under `auto`, the Assumptions list is what `review-plan` audits;** no human approves the design.
- **Environment drift outside git** (`.env`, a rebuilt container) can make a reused green result stale.
  CI on push is the backstop.
- **Inherited from the full chain:**
  - reconstruction treats "implementation commits present" as implemented, so a half-finished run
    resumes at a later leg;
  - `handoff`'s A.4.a can miss a spec when review-fix commits touch only the plan.
- **One PR:** no per-change attribution of the saving.
- **Package repos cannot run Bounded.**

## Owner decisions (all decided, 2026-09-14)

- **Proportionality as one chain with two design sizes**, not a second chain.
- **Who picks the size:** always a human, via the `light` word or the interactive question.
- **`review-plan` on every size.**
- **Threshold:** 100 code lines.
- **No Sonnet.**
- **Brief rules:** all three retired, written down in `engine.md`.
- **Delivery:** one PR.

## Rework log

**v1 → v2** (`/critique plan`, *needs rework*):
- **Defects fixed:**
  - an empty PR that could not be opened;
  - a suite key that never matched in repos not ignoring `.claude/`, and a mis-scoped `GIT_INDEX_FILE`;
  - a threshold calibrated on added + deleted but enforced on added only;
  - deletions invisible to escalation;
  - an auth grep whose false positives had become costly.
- **Measured:** `review-plan`'s value, which v1 called unmeasurable.
- **Replaced:** a reimplementation of brainstorming's Bounded path, which already exists.
- **Removed:** a base-branch switch that failed open.
- **Also:** bounded the inline review fixes, and replaced the brief blacklist with a composition rule.

**v2 → v3** (Fable's opinion, owner decisions):

| Point | Change |
|---|---|
| A second chain is the wrong shape; the difference is only the design artifact's size | one chain, `DesignSize` read from the spec header (§1) |
| The uncommitted light design broke review-at-a-SHA and caused all the marker and probe machinery | the Bounded design is committed; markers, `chain` field, PR opening in `implement` and new reconstruction probes removed (§1, §2) |
| Plan-only commit | two short files instead, because `handoff` requires both (§1, decision 4) |
| Escalation wrote a reverse-engineered spec | grow the plan, re-review with the diff so far, re-run `handoff` to update the PR (§3) |
| A stale `review-plan` pass could survive an escalation | `pipeline_done_legs` ignores gate entries older than the latest escalation (§3) |
| "A quarter of active time unattributed" | the full attribution table is now in *The evidence* (the shares were measured; v2 listed only some) |
| The 51–80 band has the highest catch rate, yet the threshold is 100 | recorded; `review-plan` stays on Bounded runs; threshold revisited after measuring (§3, *Validation strategy*) |
| Package repos excluded | kept, with the objection recorded (decision 8) |
| Ship §4–§6 first and measure | owner chose one PR; attribution loss accepted (decision 15) |
