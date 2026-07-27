# `pipeline` — mechanical checks in `implement` (PHPStan + Pint) — design

**Date:** 2026-07-27
**Status:** draft → **reworked after `/critique plan`** → user review → implementation plan → PR
**Canonical home:** `IT4WEBBV/LaravelClaudeMd`, `skills/pipeline/` (global skill, symlinked into `~/.claude/skills/`).
**Amends:** `2026-07-24-pipeline-skill-design.md`. This spec extends the `implement` leg. The gate model, the leg list and the navigation guardrail are **untouched**.
**Surfaced by:** a measurement spike on Deploy (2026-07-27), run to answer *"could PHPStan give us insights we are currently missing?"*
**Reworked by:** `/critique plan`, which returned 17 surviving findings including two Tier-1 defects verified against Deploy's real environment. The *Rework log* at the end records what changed and why.

## Summary

The chain's only mechanical ground truth is the test suite. Every other quality signal is one LLM
judging another LLM's output. This spec adds a **deterministic soundness layer** — PHPStan (via
Larastan) and Pint — to the `implement` leg, so the tooling changes what gets written rather than
reporting on it afterwards.

The governing intent, from the skill's owner:

> my intention is that the tool helps you more than me in the end

A CI check reports a mistake twenty minutes later to a human who has moved on. A check inside the
implementation loop changes the next line written. So this lands in `implement`, not CI, and not as
a gate.

Adoption is **opt-in per repo**. A repo that has not adopted runs exactly the pipeline it runs
today.

## The evidence

Larastan 3.10 / PHPStan 2.2.6 on Deploy (Laravel 13, PHP 8.4, `app/` = 233 files, ~13.7k LOC,
74 test files).

| Level | Errors | Δ |
|---|---|---|
| 0 | 77 | – |
| 1 | 106 | +29 |
| 2 | 254 | +148 |
| 3 | 276 | +22 |
| 4 | 292 | +16 |
| **5** | **299** | +7 |
| 6 | 1562 | **+1263** |

**Level 6 is annotation tax** — 1,235 of its 1,263 additional findings are `missingType.*`. Level 5
is the knee of the curve.

**53% of level 5 is one convention gap, not bugs.** 158 of 299 findings trace to Eloquent relation
methods lacking return types; `DeploymentConfiguration::webhooks()` and
`Deployment::deploymentConfiguration()` exist and work, but Larastan cannot see they are relations
without `: MorphMany` / `: BelongsTo`. 117 such methods in `app/Models`.

**The remaining 141 are real.** Five verified by reading the source: `ServiceTypeEnum::getImageName()`
missing its `CUSTOM` arm (runtime `\UnhandledMatchError`); `RouterMiddleware` unfinished scaffolding;
`EventServiceProvider::$listen` registering five classes from a different project;
`StopDeployCommand::$deployment` a dynamic property (fatal in PHP 9, 27 instances); three
`nullsafe.neverNull` — the CLAUDE.md "avoid `?->`" rule, currently enforced only by whoever reviews.

**Measured runtimes** (Deploy, level 5, inside the running container):

| Run | Time |
|---|---|
| Scoped to 2 files, cold cache | 7.2s |
| Scoped to 2 files, warm cache | 4.7s |
| Whole `app/`, warm cache | 11.1s |

The Larastan bootstrap is a fixed ~4.5s floor, so scoping to a handful of files saves only ~6s.
**That measurement changed the design** — see §3.

Pint, separately: `233 files, 191 style issues`. Essentially the whole codebase is unformatted.

### What this evidence does and does not establish

**Four of the five defects also appear in Deploy's issue #384**, the 2026-06-11 architecture audit.
That overlap is coincidental in the way that matters: #384 was a one-off commissioned full-sweep
audit, not a step in the routine chain, and the routine chain — tests plus `/critique` on a diff —
caught **none** of them. An LLM sweeping 233 files surfaces what it happens to notice on that pass;
#384 consolidated **18 findings** where PHPStan reports **~141 real signals**. The great majority of
what the analyser sees appears nowhere in the audit.

**But this is audit-mode yield, and the deployed design does not run in audit mode.** With a
baseline in place the loop reports only *newly introduced* findings, so none of the five defects
above would be reported by the mechanism this spec builds — they would sit in the baseline.
**The in-loop yield, the number of new findings caught per run, is unmeasured.** It is the number
that actually justifies the per-step cost, and it cannot be obtained from a static sweep. §11 makes
measuring it a precondition of rollout rather than an assumption.

The overlap validates the boundary from the other direction too: #384 additionally found committed
production secrets, feature envy, blocking work on queue workers, hardcoded tenant strings.
**PHPStan can never see any of those.** The instruments are complements.

## The problem

1. **Tests only cover paths someone thought to test.** PHPStan reads every path *within its declared
   scope* — error branches, admin-only code, the Livewire method behind one button.
2. **`/critique` spends judgment budget on mechanics.**
3. **Formatting noise pollutes the diffs the review legs read.**

## What is *not* the problem

- **Not a design or architecture detector.** PHPStan reports soundness: things that cannot work. It
  says nothing about god classes, wrong abstractions, or project-vs-package placement. That stays
  with `/critique` and `improve-codebase-architecture`. The value is that it **frees** their budget.
- **Not a CI gap.** CI-only placement never reaches the loop that writes the code.
- **Blade and most of the Livewire `wire:model` seam stay invisible.** Those remain `verify-ui`'s
  job; the `ui` trigger and gate are unaffected.

## Principle

**Deterministic checks are not gates.** A gate leg forces a *judgment* — that is why `review-plan`,
`review-pr` and `verify-ui` are gates and why the navigation guardrail protects them. PHPStan
returns pass/fail. Wrapping a boolean in a navigation guardrail is ceremony.

So mechanical checks join the **definition of done for `implement`**, where the test suite sits.

**The baseline is the interface between two tracks.** The pipeline never grows it; the separate
campaign shrinks it. §5 makes "never grows" mechanically checkable rather than aspirational.

## Design

### 1. Capability probe — tri-state, and it fails closed

`pipeline_repo_checks()` returns one of three states, never a bare array:

| State | When | Behaviour |
|---|---|---|
| **absent** | no `## Checks` section in `.claude/work-on.config.md` | not adopted — `implement` behaves exactly as today, no mention of checks anywhere |
| **valid** | section present, every declared key parses to a non-empty command | run the checks |
| **invalid** | section present but malformed: unknown key, empty value, unparseable line | **halt** as a machinery failure |

The middle column matters more than it looks. An earlier draft returned an array, which made a
typo'd heading (`## Check`), a mis-cased key, or `static_analysis:` with an underscore
indistinguishable from genuine non-adoption — a permanent silent skip with no feedback channel.
**Absent and invalid must be different states**, or the fail-closed principle is decoration.

**The `## Checks` block must be committed to the branch.** The probe reads the run's worktree, which
the pipeline creates fresh from git; a config written only in the primary checkout would be invisible
there and every run would read "not adopted" while the owner believed adoption was done. Adoption is
a committed change or it has not happened.

**Removing the block is a de-adoption, and de-adoption is a reviewable change.** Because the block is
committed, deleting it appears in the diff and `review-pr` sees it. Nothing else prevents a session
stuck on a broken check from unsticking itself permanently.

### 2. Declaration format — slot-aware, following the existing precedent

Deploy is slot-enabled. `scripts/slot-env.sh:49` sets `SUFFIX="-${SLOT}"`, so a run in slot 2 has
containers named `deploy-2_web`, and `engine.md`'s kickoff routes slot-enabled projects through
`worktree.sh`. **A hardcoded `docker exec deploy_web` would therefore exec the primary stack**, whose
`/var/www` mounts the primary checkout — analysing code the run never touched, finding nothing, and
passing green. That is precisely the "believing it has coverage it does not have" outcome this design
calls worse than no tooling, and it would have fired on the first acceptance run.

So the declaration carries the same `<N>` token that `work-on.config.template.md` already uses for
`slot-path` and `dev-url`. **`<N>` expands to the run's slot suffix — empty on the primary stack,
`-2`, `-3`, … in a slot** — and the engine substitutes it from the slot it has already resolved for
the worktree:

```markdown
## Checks
- static-analysis: docker exec deploy<N>_web ./vendor/bin/phpstan analyse --no-progress --memory-limit=2G
- format: docker exec deploy<N>_web ./vendor/bin/pint
```

Both keys are optional and independent. Checks always run **in the run's own already-running
container**; optimising around container startup is not a constraint this design accepts.

### 3. `implement` gains mechanical checks — whole-scope, every step

**After each step: run the suite, then static analysis over the whole declared scope, then the
formatter over the whole tree.** No file lists, no diff-scoping.

This is a direct consequence of the measured runtimes. Scoping to two files costs 4.7s against 11.1s
for all of `app/` — a 6.4s saving, because the Larastan bootstrap dominates. An earlier draft claimed
1–2s and built a scoped/whole-app split on it. That number was asserted, not measured, and it was
wrong by 3–5×.

Paying 6.4s per step **removes three separate mechanisms**:

- **host→container path mapping** — the engine's paths are repo-relative (`code/www/app/Foo.php`),
  the container's tree is `/var/www/app/…`. Passing a file list required a mapping rule that was
  never specified and never spiked.
- **touched-file tracking** across steps.
- **the pre-ready backstop**, which existed only to catch what a later step reintroduced in a file an
  earlier step owned. Whole-scope every step has no such gap.

Simplicity for six seconds is a good trade.

**Pint has no `--dirty`.** `--dirty` requires a git repository inside the analysed tree; Deploy's
container mounts `code/www` while `.git` lives at the repo root, **and `git` is not installed in the
container at all** (`sh: 1: git: not found`). So Pint runs over the whole tree — which is only
sensible *after* the blanket format commit has landed, because a formatter run on an unformatted repo
would rewrite hundreds of unrelated files.

**This makes the blanket format commit a hard prerequisite**, not a nice-to-have: a repo cannot adopt
the `format` key until it is fully formatted. Once it is, a whole-tree `pint` is a no-op on everything
except newly written code, so it produces exactly the intended diff. That property must be **verified
at adoption**, not assumed: run `pint`, then `pint --test`, and require a clean report.

### 4. Two failure kinds, deliberately named differently

`engine.md`'s "hard failure" means *halt the run for a human*. That is not what a new PHPStan finding
should do, and conflating them would make an auto run halt on the first fixable finding — or, worse,
invert and treat a broken toolchain as fix-and-retry.

| Kind | Trigger | Response |
|---|---|---|
| **Check failure** | the checks report a finding the change introduced | the **step is not done**. Fix and re-run, bounded to 2 attempts; on the third, escalate. Exactly how a failing test behaves. |
| **Machinery failure** | probe returns `invalid`, the container is missing, the command errors, the tool is not installed | **halt** per `engine.md`'s existing hard-failure policy. |

Only the second is `engine.md`'s "hard failure", and the implementation must use that term for only
that case.

### 5. Level 5, and a baseline that cannot silently grow

Level 5 is chosen from the measured curve, not taste. **The curve was swept on Deploy only**;
other projects adopt at 5 as the default but should sweep their own curve at adoption, since a
codebase whose false-positive mix differs may have its knee elsewhere.

Each adopting repo commits a `phpstan-baseline.neon`. From then on every reported finding is one the
current change introduced.

Two lifecycle holes an earlier draft left open:

- **Fixing a baselined error must not fail the run.** Baseline entries carry a `count`, and
  `reportUnmatchedIgnoredErrors` defaults to true — so a step that removes one of three occurrences
  (adds a return type, deletes dead code) fails with *"expected to occur 3 times, but occurred 2"*.
  A step that **improved** the code would be blocked with no permitted remedy. So adopting repos set
  **`reportUnmatchedIgnoredErrors: false`**.
- **Growing the baseline by hand is forbidden and mechanically checkable.** Appending one entry turns
  red green while bypassing every control in §6. The rule: **any diff to `phpstan-baseline.neon` that
  adds an entry or raises a count is a blocked change; reductions are always free.** That is a diff
  property, so it can be enforced rather than trusted, and it states "never grows" precisely.

### 6. Escape hatch — bounded, identified, visible

When a finding genuinely cannot be resolved, `implement` may suppress it, subject to all four:

1. **The identifier form is required** — `@phpstan-ignore <identifier>`, never bare. A bare
   `@phpstan-ignore` suppresses *every* error on the next line, including future real ones.
2. **A justification comment is required** on the same line.
3. **Bounded: more than two ignores in one run escalates.** Unbounded self-granted suppression is
   not an escape hatch, it is a bypass.
4. **Never in place of a fix the step could make.** The agent whose step is blocked is judging its
   own excuse, which is exactly the self-review bias `/critique` exists to avoid — hence the bound.

Suppressions land in the diff, where `review-pr` reads them. §8 requires the brief to surface them
as *unadjudicated*, not as resolved.

### 7. Pint — a fixer, never a gate

Pint produces no findings, so gating on it is incoherent when the tool can fix the file. It runs
after the suite and analysis, and never blocks.

**One blanket format commit per repo, and it is a prerequisite** (§3): `pint` across the repo,
nothing else in the commit, recorded in `.git-blame-ignore-revs`. It is mechanical and CI proves it
broke nothing. It conflicts with every open branch, so each repo takes it **when its branch backlog
is low** — per repo, not a synchronised flag day.

### 8. Evidence into `review-pr`, stated with its scope

The `review-pr` brief reports the check result **qualified by the analysed scope** — *"0 new findings
over `app/`"*, never an unqualified *"0 new findings"*. `paths` does not cover `database/`, `routes/`,
`config/` or `tests/`, and an unqualified claim would prime the reviewer that mechanics are covered
where they are not.

The brief also lists any `@phpstan-ignore` added during the run, **explicitly marked unadjudicated**,
so a suppression cannot enter reading as already resolved.

**The check result is recomputed at leg start, never stored.** `manifest.md` is explicit that
recomputable fields are derived per leg and that storing them is a latent drift bug — and both the
check result (a re-runnable command) and the ignore count (grep-able from the diff) are recomputable.
An earlier draft added a `checks:` field to the manifest; that field is **removed from this design**,
and `manifest.md` is consequently no longer touched at all.

### 9. Findings the change did not introduce

The declaration lives in `work-on`'s config, but plain `/work-on`, direct commits, and colleagues'
merges write to the same repo without running checks, and project CI enforcement is out of scope. So
the whole-scope run will sometimes report findings this change did not cause.

**Blocking is scoped to files the change touched.** A reported finding in a touched file fails the
step; a finding elsewhere is reported as an annotation on the PR and does not block. Otherwise a run
would hard-fail on a colleague's merge with no legal move — baseline growth being forbidden and an
`@phpstan-ignore` on unrelated code being diff pollution.

This is the one place the design still needs the set of touched files. It is used for
*attribution only*, not for constructing the analysis command, so it needs no path mapping into the
container.

### 10. Code surface

| File | Change |
|---|---|
| `skills/pipeline/references/engine.md` | `implement` row; new *Mechanical checks* section; tri-state probe; the two failure kinds; `review-pr` brief gains the scope-qualified result |
| `skills/pipeline/checks/checks.php` | **new** — `pipeline_repo_checks(string $configMarkdown): array` returning `['state' => 'absent'\|'valid'\|'invalid', 'commands' => [...], 'error' => ?string]` |
| `skills/pipeline/checks/tests/ChecksTest.php` | **new** — parser tests, tri-state boundaries |
| `skills/pipeline/SKILL.md` | one line in the references list |

`pipeline_legs()`, `pipeline_gate_legs()`, `pipeline_can_navigate()`, `pipeline_resolve_policy()` and
`pipeline_triggers()` are **not touched**. `manifest.md` is **not touched** (§8).

**Per-repo adoption artifacts** — the skill changes are inert until a repo adopts:

| Artifact | Projects | Packages |
|---|---|---|
| Dev deps | `composer require --dev larastan/larastan laravel/pint` | same, pending the research in TallFormbuilder#39 |
| `phpstan.neon` | `paths` declared explicitly, level 5, larastan extension, baseline include, `reportUnmatchedIgnoredErrors: false`, writable `tmpDir` | `paths: [src]` |
| `phpstan-baseline.neon` | required | target: none |
| `pint.json` | explicit `laravel` preset | same |
| Blanket format commit + `.git-blame-ignore-revs` | **prerequisite** for the `format` key | same |
| `## Checks` block, committed | in `.claude/work-on.config.md` | same |

The `tmpDir` entry is not incidental: the spike failed on Deploy until it was pointed away from
`storage/framework/`, which the container user cannot write.

Adoption is tracked per repo: Deploy is `IT4WEBBV/Deploy#389`; the package profile is
`IT4WEBBV/TallFormbuilder#39`.

### 11. Two adoption profiles, and a measured rollout gate

**Projects and packages are different profiles, not one policy with two command strings:**

| | Projects | it4web packages |
|---|---|---|
| Container | long-running `{project}<N>_web` | no long-running service in the package compose today; either mount into a project stack (`restart.sh -p`) or add one |
| Laravel app | a real app — Larastan boots natively (**proven** on Deploy) | none; testbench supplies one — Larastan bootstrapping is **unverified** |
| Analysed paths | declared per repo, `app` at minimum | `src` |
| Level | 5, sweep the curve at adoption | 5 floor, higher permitted |
| Baseline | required and large (299 on Deploy) | target **zero** — small enough to fix outright |
| CI enforcement | no lint CI today; out of scope | `run-tests.yml` exists, so nearly free |
| Blast radius | one application | **every consuming project** |

The last row drives the split: a package defect propagates to every project that installs it, so
packages get the **stricter** target despite being smaller. Blast radius outranks size.

**The rollout gate.** Because in-loop yield is unmeasured (see *The evidence*), Deploy's adoption is
run as a **measurement**, not just an adoption: record, across the first runs, how many *new* findings
the checks catch and how many are false positives requiring the §6 hatch. **If the loop catches
essentially nothing, the per-step cost and halt surface are not justified and the design should be
reduced to a pre-PR check rather than a per-step one.** Stating a kill criterion is the difference
between a measured decision and a rationalised one.

### 12. What does not change

The leg list, the two gates, the `ui` trigger, the adjudication model, the escalation rules, the
loop-back bounds, the reconstruction story, and `manifest.md`. This spec adds a step inside one leg.

## Decisions log

| Decision | Rationale | Rejected alternative |
|---|---|---|
| Inside `implement`, not a new leg | A gate forces a judgment; a deterministic boolean has none | `verify-code` gate leg mirroring `verify-ui` |
| Whole-scope every step, no file lists | Measured: 4.7s scoped vs 11.1s whole — the bootstrap dominates, so scoping buys 6.4s and costs three mechanisms | Diff-scoped per step + pre-ready backstop (the earlier draft, built on an unmeasured "1–2s") |
| Level 5 | Measured: level 6 adds 1,263 findings, 1,235 of them missing docblocks | Level 6; "max strictness" |
| `<N>` slot token in the declaration | Verified: `slot-env.sh:49` names slot containers `deploy-2_web`; a hardcoded name analyses the wrong tree and passes green | Hardcoded container name; auto-detecting the container |
| Whole-tree Pint, blanket format as prerequisite | Verified: no `git` binary and no `.git` in the container mount, so `--dirty` cannot work | `pint --dirty`; passing explicit file lists |
| Tri-state probe | Absent and invalid must differ, or a typo becomes a permanent silent skip | Array return, empty = not adopted |
| `## Checks` must be committed | The worktree is built from git; an uncommitted config is invisible to every run | Reading the primary checkout's config |
| `reportUnmatchedIgnoredErrors: false` | Otherwise fixing a baselined error fails the step that improved the code | Leaving the default; permitting regeneration |
| Baseline may shrink, never grow | Makes "never grows the baseline" a checkable diff property instead of a promise | Forbidding only wholesale regeneration |
| Ignores bounded at 2 per run | The agent judging its own excuse is the bias `/critique` exists to avoid | Unbounded, "narrowly scoped" by instruction |
| Blocking scoped to touched files | Otherwise a colleague's merge hard-fails an unrelated run with no legal move | Blocking on any reported finding |
| Check result recomputed, not stored | `manifest.md`: storing recomputable fields is a latent drift bug | A `checks:` manifest field |
| Packages and projects are separate profiles | Blast radius outranks codebase size | One policy, two command strings |
| Rollout gated on measured in-loop yield | The 141 findings are audit-mode yield; the deployed design reports only new findings | Assuming in-loop yield follows from the sweep |

## Testing strategy

- **Pest tests** for `pipeline_repo_checks()` in `skills/pipeline/checks/tests/ChecksTest.php`. Cases:
  both keys present; neither; one of each; **malformed heading → `invalid`, not `absent`**; unknown
  key → `invalid`; empty value → `invalid`; commented-out line; `<N>` present and absent. The
  absent-vs-invalid boundary is the one that matters — it is the failure mode that disables the check
  while the run believes it is covered.
- **End-to-end acceptance on Deploy, with falsifiable pass conditions.** "A real `/pipeline` run" is
  not a test. All four must hold:
  1. **Right tree** — a run in slot *N* analyses the slot's worktree. Verify by seeding a defect in
     the worktree and confirming the check reports it; a green result against the primary checkout is
     a **fail**, not a pass.
  2. **Seeded defect fails the step** — introduce a known level-5 error, confirm the step does not
     complete.
  3. **Broken declaration halts** — corrupt the `## Checks` block, confirm a machinery-failure halt
     rather than a silent skip.
  4. **Pint idempotence** — after the blanket format commit, `pint` followed by `pint --test` reports
     clean.
- The existing pipeline check suite must stay green, proving the untouched pure functions were in
  fact untouched.

## Risks accepted

- **The escape hatch can still turn red green.** Mitigated by the identifier form, the justification,
  the bound of two, and reviewer signposting — reduced, not eliminated. The alternative, no hatch,
  makes the first false positive halt a run with no way forward.
- **A large baseline is honest debt that can hide a bug present at adoption.** Deploy's would be 299
  entries; #389 sequences the known defects and the 117 relation types to be fixed *before* the
  baseline is generated, so it captures residual debt rather than known-open bugs.
- **~11s per step is a real cost** and it recurs on every step of every run. Justified only if the
  in-loop yield is non-trivial, which §11 makes a measured gate rather than a hope.
- **Runtime on larger apps is unmeasured.** 11.1s on 13.7k LOC; Retenium is considerably bigger, and
  whole-scope-every-step makes that cost matter more, not less. Measure before adopting there.
- **Larastan boots the application**, so the stack must be up. `implement` already requires this.
- **Level 5's curve is measured on one codebase.** Other projects sweep their own at adoption.

## Out of scope

- **PHPat / Deptrac** boundary rules — the one genuine design-flaw tool for this codebase, since
  Deploy has an implicit `Domain` / `Http` / `Tasks` / `Livewire` layering nothing enforces. Strong
  phase 2.
- **Rector** — belongs to the campaign track, where its `TYPE_DECLARATION` set would delete the 53%
  in one pass rather than baselining it.
- **CI integration** — additive and separate.
- **A skill for the stricter-levels campaign** — YAGNI until its shape is known.
- **Blade / Livewire template analysis** — `verify-ui` keeps that job.
- **`churn-php`, PHPMD, PhpMetrics** — audit instruments, not loop instruments.

## Rollout

**Projects track**

1. **Deploy** (`IT4WEBBV/Deploy#389`) — first adopter, end-to-end acceptance test, **and the in-loop
   yield measurement** (§11). Its issue sequences the #384 defects and the 117 untyped relation
   methods to be fixed *before* the baseline is generated.
2. **LaravelTemplate** — so new projects are born with the tooling.
3. **Remaining projects**, one at a time, each sweeping its own level curve and taking its blanket
   format commit when its branch backlog is low. Measure runtime on Retenium first.

**Packages track — research first, runs independently**

4. **Establish whether Larastan bootstraps in a testbench-only package** (`IT4WEBBV/TallFormbuilder#39`).
   Gates the whole package profile: if not, packages get plain `phpstan/phpstan` and a materially
   weaker guarantee.
5. **TallFormbuilder** as first package adopter, then the remaining three.

The tracks share the skill-side machinery and nothing else. Neither blocks the other.

## Rework log — what `/critique plan` changed

The review returned 17 surviving findings (2 Tier 1, 15 Tier 2) and a verdict of **rework**. Two were
verified against Deploy's real environment before being accepted.

| Finding | Change |
|---|---|
| **T1** Hardcoded `deploy_web` analyses the wrong tree in a slot | §2 `<N>` slot token, following the `slot-path`/`dev-url` precedent |
| **T1** `pint --dirty` cannot see git in the container | §3 whole-tree Pint; blanket format becomes a prerequisite |
| In-loop yield never measured, only audit yield | *Evidence* now separates the two; §11 adds a measured rollout gate with a kill criterion |
| Probe file may be absent from the worktree | §1 the block must be committed |
| Parse failure indistinguishable from non-adoption | §1 tri-state probe; absent ≠ invalid |
| Fixing a baselined error hard-fails the run | §5 `reportUnmatchedIgnoredErrors: false` |
| Host→container path mapping unspecified | §3 eliminated — no file lists at all |
| Baseline hand-edit an unpoliced suppression path | §5 shrink-only, as a checkable diff property |
| `paths: [app]` contradicts "reads every path" | §8 brief must qualify the result by scope |
| Deleting `## Checks` silently de-adopts | §1 committed block ⇒ de-adoption is reviewable |
| "Hard failure" means two things | §4 check failure vs machinery failure |
| Bare `@phpstan-ignore` unbounded | §6 identifier form, justification, bound of 2 |
| Non-pipeline writes bill the wrong change | §9 blocking scoped to touched files |
| Acceptance criteria not falsifiable | *Testing strategy* four explicit pass conditions |
| Manifest stores recomputable state | §8 field removed; `manifest.md` no longer touched |
| No formatter backstop | §3 moot — whole-tree every step |
| "1–2s" asserted, not measured | Measured at 4.7s; the design changed as a result |

Dropped: two findings with no nameable failure scenario, and two Tier 3 (level-curve generalisation,
now addressed in §5 anyway; and a misquote of `restart:` as a test command, corrected in §2's
precedent reference).
