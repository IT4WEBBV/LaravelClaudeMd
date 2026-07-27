# `pipeline` — mechanical checks in `implement` (PHPStan + Pint) — design

**Date:** 2026-07-27
**Status:** draft → user review → implementation plan → PR
**Canonical home:** `IT4WEBBV/LaravelClaudeMd`, `skills/pipeline/` (global skill, symlinked into `~/.claude/skills/`).
**Amends:** `2026-07-24-pipeline-skill-design.md` (the original pipeline design). This spec extends the `implement` leg. The gate model, the leg list and the navigation guardrail are **untouched**.
**Surfaced by:** a measurement spike on the Deploy project (2026-07-27), run specifically to answer *"could PHPStan give us insights we are currently missing about code quality?"* — numbers below.

## Summary

The chain's only mechanical ground truth is the test suite. Every other quality signal in the
pipeline is one LLM judging another LLM's output. This spec adds a **deterministic soundness
layer** — PHPStan (via Larastan) and Pint — to the `implement` leg, so that the tooling changes
what gets written rather than reporting on it afterwards.

The governing intent, from the skill's owner:

> my intention is that the tool helps you more than me in the end

That single sentence decides the placement. A CI check reports a mistake twenty minutes after it
was made, to a human who has moved on. A check inside the implementation loop is a **feedback
loop**: it changes the next line written. So this lands in `implement`, not in CI, and not as a
gate.

Adoption is **opt-in per repo**. A repo that has not adopted the tooling runs exactly the pipeline
it runs today.

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

**Level 6 is annotation tax, not insight** — 1,235 of its 1,263 additional findings are
`missingType.*` ("you did not write a docblock"). Level 5 is the knee of the curve.

**53% of level 5 is one convention gap, not bugs.** 158 of 299 findings trace to Eloquent relation
methods lacking return types — `DeploymentConfiguration::webhooks()` and
`Deployment::deploymentConfiguration()` both exist and work; Larastan simply cannot see they are
relations without `: MorphMany` / `: BelongsTo`. There are 117 such untyped methods in `app/Models`.

**The remaining 141 are real.** Five were verified by reading the source:

1. `ServiceTypeEnum::getImageName()` has no `CUSTOM` arm in its `match` → `\UnhandledMatchError`
   at runtime, on a feature that exists. 74 test files did not catch it.
2. `app/Livewire/RouterMiddleware.php` is unfinished scaffolding — `collect([$actions])` where
   `$actions` was never defined, and `baseQuery()` calls `App\Models\Model::query()` on a class
   that does not exist.
3. `EventServiceProvider::$listen` registers five classes belonging to a *different project*
   (`GenerateOrderPdfListener`, `TimeslotPickedEvent`, …) — copy-paste cruft.
4. `StopDeployCommand::$deployment` is a dynamic property: deprecated in PHP 8.2, **fatal in
   PHP 9**. 27 instances of this shape across the app.
5. `nullsafe.neverNull` ×3 — the CLAUDE.md rule *"avoid `?->` unless there is a good reason"* is
   currently enforced only by whoever happens to be reviewing. PHPStan makes it mechanical.

Pint, separately: `233 files, 191 style issues`. Essentially the whole codebase is unformatted.

## The problem

1. **Tests only cover paths someone thought to test.** PHPStan reads every path — error branches,
   admin-only code, the Livewire method behind one button. In an agent-heavy workflow that is
   precisely where the damage accumulates, because an agent will confidently call a method that
   does not exist on a branch nobody covers.
2. **`/critique` spends judgment budget on mechanics.** Every token it spends checking argument
   counts is a token it did not spend on whether the design is right.
3. **Formatting noise pollutes the diffs the review legs read.**

## What is *not* the problem

- **This is not a design or architecture detector, and must not be sold as one.** PHPStan reports
  *soundness*: things that cannot work. It says nothing about god classes, wrong abstractions,
  missing polymorphism, or whether code belongs in a project or an it4web package. That judgment
  stays with `/critique` and `improve-codebase-architecture`. The value here is that it **frees**
  their budget, not that it replaces them.
- **Not a CI gap.** CI-only placement would satisfy none of the stated intent, since it never
  reaches the loop that writes the code.
- **Blade templates and most of the Livewire `wire:model` seam stay invisible to it.** A large
  share of TALL-stack defects live exactly there and remain `verify-ui`'s job. The `ui` trigger and
  the `verify-ui` gate are unaffected by anything in this spec.

## Principle

**Deterministic checks are not gates.** A gate leg exists to force a *judgment* — that is why
`review-plan`, `review-pr` and `verify-ui` are gates, and why the navigation guardrail protects
them. PHPStan renders no judgment; it returns pass/fail. Wrapping a boolean in a navigation
guardrail is ceremony, and would mean amending `pipeline_legs()`, `pipeline_gate_legs()` and their
tests for no gain in guarantee.

So mechanical checks join the **definition of done for `implement`**, exactly where the test suite
already sits.

**The baseline is the interface between two tracks.** The pipeline's job is *never grow the
baseline*; the separate stricter-levels campaign's job is *shrink it, then raise the level*. One
file, two directions, no coordination needed between them.

## Design

### 1. Capability probe — opt-in, absent means unchanged

A repo has mechanical checks when its `.claude/work-on.config.md` declares them **and** the
declared tooling resolves. No declaration → the `implement` leg behaves exactly as it does today,
with no mention of static analysis anywhere in the run.

**A declared-but-broken check is a hard failure, not a silent skip.** If the config names a
static-analysis command and the binary is missing or the command errors, `implement` halts per the
existing hard-failure policy. Silently skipping would leave the run believing it has coverage it
does not have — the one outcome worse than no tooling.

### 2. Per-repo invocation, declared where invocations already live

The command differs per repo (`docker exec deploy_web ./vendor/bin/phpstan …` vs
`docker compose run --rm test vendor/bin/phpstan …`). `.claude/work-on.config.md` already carries
exactly this kind of thing (`restart: make test`), so the declaration goes there:

```markdown
## Checks
- static-analysis: docker exec deploy_web ./vendor/bin/phpstan analyse --no-progress --memory-limit=2G
- format: docker exec deploy_web ./vendor/bin/pint
```

Both keys are optional and independent. `static-analysis` is invoked with a file list appended for
the per-step run, and with no arguments for the whole-app backstop; `format` is invoked with
`--dirty`.

### 3. `implement` gains mechanical checks

The leg brief changes from *"execute the plan test-first, running the suite after each step"* to:

- **After each step** — run the suite, then static analysis **scoped to the files just touched**,
  then the formatter with `--dirty`. Scoping here buys **speed** (1–2s rather than ~11s), which is
  what makes it viable after every step; suppression of pre-existing findings is the baseline's
  job (§5), not the scoping's.
- **Before marking ready** — one **whole-app** static-analysis run as a backstop (~11s on Deploy).
  This catches the case where a later step reintroduced something in a file an earlier step owned
  and no longer looks at.
- **A new finding is a hard failure of that step**, in exactly the way a failing test is. There is
  no adjudication, because there is no judgment to adjudicate.

### 4. Level 5

Chosen from the measured curve, not from taste: level 5 captures the whole ramp of real findings
while excluding the `missingType.*` annotation tax that makes level 6 unusable in a loop. This is
the owner's *"a level that catches important findings without blocking on nitpicks"*, measured.

Packages may opt higher in their own `phpstan.neon` — they are small, and a published API benefits
from full type coverage. Projects stay at 5.

### 5. The baseline is what makes day-one adoption possible

Each adopting repo generates `phpstan-baseline.neon` once and commits it. From that moment **every
finding PHPStan reports is one the current change introduced** — there is never a judgment call
about whether a pre-existing problem is mine.

Regenerating the baseline wholesale is **not** an available move for the pipeline: it would erase
the guarantee in one command. The baseline shrinks only through the campaign track, in its own PRs.

### 6. Escape hatch — narrow, justified, visible

False positives are real; 53% of Deploy's output was one class of them. When a finding genuinely
cannot be resolved, `implement` may add a **narrowly scoped `@phpstan-ignore` with a justification
comment**. It lands in the diff, where `review-pr`'s `/critique` reads it.

Visible and reviewable rather than silent. This is a pressure valve, not a bypass: blanket
suppression and baseline regeneration remain unavailable.

### 7. Pint — a fixer, never a gate

Pint produces no findings, so gating on it is incoherent when the tool can simply fix the file. It
runs `--dirty` in the loop and never blocks.

**One-time blanket format per repo, scheduled per repo.** With 191 of 233 files unformatted,
`--dirty` alone means the first PR to touch any legacy file carries a whole-file reformat on top of
its real change — precisely the diff noise Pint is being adopted to remove. So each repo takes one
**formatting-only commit** at adoption, recorded in `.git-blame-ignore-revs` so `git blame` stays
useful. It is mechanical, and CI proves it broke nothing.

That commit conflicts with every open branch, so each repo does it **when its branch backlog is
low** — the owner's call per repo, not a synchronised flag day.

### 8. Evidence into `review-pr`

`implement` records the mechanical-check state in the manifest, and the `review-pr` brief is handed
the result. `/critique` therefore starts from *"0 new static-analysis findings; N scoped ignores
added"* as a **fact**, instead of spending budget rediscovering mechanics — the direct payout of
the "cheaper, sharper reviews" motivation.

Any `@phpstan-ignore` added during `implement` is called out explicitly in that brief, so a
suppression can never enter the codebase without a reviewer being pointed at it.

### 9. Code surface

| File | Change |
|---|---|
| `skills/pipeline/references/engine.md` | `implement` row; new *Mechanical checks* section; capability probe and hard-failure wording; `review-pr` brief gains the check result |
| `skills/pipeline/references/manifest.md` | record `checks: {static_analysis, format, ignores_added}` |
| `skills/pipeline/checks/checks.php` | **new** — `pipeline_repo_checks(string $configMarkdown): array` |
| `skills/pipeline/checks/tests/ChecksTest.php` | **new** — parser tests |
| `skills/pipeline/SKILL.md` | one line in the references list |

`pipeline_legs()`, `pipeline_gate_legs()`, `pipeline_can_navigate()`, `pipeline_resolve_policy()`
and `pipeline_triggers()` are **not touched**.

### 10. What does not change

The leg list, the two gates, the `ui` trigger, the adjudication model, the escalation rules, the
loop-back bounds, and the reconstruction story. This spec adds a step inside one leg.

## Decisions log

| Decision | Rationale | Rejected alternative |
|---|---|---|
| Inside `implement`, not a new leg | A gate forces a judgment; a deterministic boolean has none. Avoids amending three tested pure functions for zero added guarantee. | `verify-code` gate leg mirroring `verify-ui` |
| Level 5 | Measured: level 6 adds 1,263 findings of which 1,235 are missing docblocks | Level 6; level 8/9 "max strictness" |
| Baseline file | Makes any legacy repo adoptable on day one, and cleanly separates "never regress" from "improve" | Fix all 299 first; or analyse only changed *lines* |
| Diff-scoped per step, whole-app before ready | 1–2s in the loop keeps the feedback tight; the backstop closes the cross-step gap | Whole-app every step (too slow); per-step only (misses regressions) |
| Declared in `.claude/work-on.config.md` | The invocation genuinely differs per repo, and this file already carries `restart:` | Auto-detect by probing for `vendor/bin/phpstan` (guesses the container) |
| Declared-but-broken = hard failure | Believing you are covered when you are not is worse than not being covered | Silent skip |
| Pint fixes, never gates | Gating on something the tool can auto-fix is ceremony | `pint --test` as a blocking check |
| Blanket format per repo, backlog-timed | One-time noise in an isolated, blame-ignored commit beats permanent per-file noise | `--dirty` only; or a synchronised flag day across all repos |
| No campaign skill yet | The campaign is "PRs that shrink the baseline or bump the level", and `/pipeline` already runs work through | Build a `static-analysis-campaign` skill up front |

## Testing strategy

- **Pest tests** for `pipeline_repo_checks()` in `skills/pipeline/checks/tests/ChecksTest.php`,
  run by the existing command in `SKILL.md`. Cases: both keys present; neither; one of each;
  malformed line; commented-out line; a key whose value is empty (must not silently read as
  "configured"). The silent-parse-failure case is the one that matters — it is the failure mode
  that would disable the check while the run believes it is covered.
- **End-to-end validation by adoption**: TallFormbuilder is the first adopter, and a real
  `/pipeline` run on it is the acceptance test for the engine wiring.
- The existing pipeline check suite must stay green, proving the untouched pure functions were
  in fact untouched.

## Risks accepted

- **A scoped `@phpstan-ignore` is a way to turn red green.** Mitigated by visibility (it is in the
  diff, and `review-pr` is pointed at it), not eliminated. The alternative — no escape hatch —
  makes the first false positive halt a run with no way forward.
- **First adoption of any legacy project produces a large baseline.** Deploy's would be 299 lines.
  A large baseline is honest debt, but it can hide a real bug that was present at adoption time.
  Accepted: the campaign track exists precisely to burn it down.
- **Runtime on larger apps is unmeasured.** 11s on 13.7k LOC; Retenium is considerably bigger.
  Diff-scoping protects the per-step loop, but the whole-app backstop may be slow enough to matter.
  **Measure before adopting there** — it is a rollout gate, not a blocker for this design.
- **Larastan boots the application**, so the stack must be up. `implement` already requires this, so
  no new requirement — but it does mean static analysis cannot run in the cheap early legs.
- **Whole-file reformat noise persists** in any repo between adopting Pint and taking its blanket
  format commit.

## Out of scope

- **PHPat / Deptrac** architectural boundary rules. This is the one genuine "design flaw" tool for
  this codebase — Deploy has an implicit `Domain` / `Http` / `Tasks` / `Livewire` layering that
  nothing enforces, and it would make the pipeline's `architecture_judgment` partly mechanical.
  Strong phase 2; deliberately not now.
- **Rector.** Belongs to the campaign track, where its `TYPE_DECLARATION` set would auto-fix most
  of the 117 untyped relation methods and delete the 53% in one pass.
- **CI integration.** The pipeline is the enforcement point for now; a CI job is a separate,
  additive decision.
- **A skill for the stricter-levels campaign.** YAGNI until its shape is known.
- **Blade / Livewire template analysis.** No tool in scope reads them; `verify-ui` keeps that job.
- **`churn-php`, PHPMD, PhpMetrics.** Audit-time instruments, not loop instruments.

## Rollout

1. **TallFormbuilder** — small, active, has CI and a Makefile, near-zero baseline. First adopter and
   the end-to-end acceptance test.
2. **LaravelTemplate** — so every new project is born with the tooling.
3. **Existing projects, one at a time**, baseline-first, each taking its Pint blanket commit when
   its own branch backlog is low. Measure PHPStan runtime on the largest (Retenium) before
   committing to it.
