# `/critique` — one review skill, four modes — design

**Date:** 2026-07-23
**Status:** revised design (v5) → pending spec sign-off → implementation plan → PR
**Canonical home:** `IT4WEBBV/LaravelClaudeMd`, `skills/critique/` (the PR target).
**Related, out of scope:** the "pipeline" skill — issue #14.
**Previous revisions:** `f7007f0` initial · `6117da4` after independent plan-review · `892f6a5` cut down for token efficiency · `fecc0d8` principles + invariants + hazard classes. v5 adds a deterministic pre-pass, tiered findings with a signal-ratio target, and two-state verdicts — all borrowed from how existing reviewers actually work.

## Summary

One skill, four modes, encoding the two review steps currently done from memory: reviewing a design/plan before implementation, and reviewing a PR before merge.

1. **Four modes:** `plan`, `pr`, `alternatives`, `missing`. Same pipeline, different rubric.
2. **Deterministic checks run first, the LLM never sees them.** Mechanical rules are grep and static analysis; only judgment reaches the model. This is the architecture behind the best-measured false-positive rates in the field.
3. **One reviewer by default.** Fan-out must earn its place.
4. **Findings are tiered and verdict-labelled**, capped in number, and each ends in an explicit disposition.
5. **No generic bug hunting.** That layer belongs to the built-in `/code-review`.

## What this skill is actually for

Worth stating plainly, because an earlier revision of this spec grew an engine around the wrong premise.

Asking an independent model "what do you think of this plan / this PR" already works. Tested during the design of this spec: one agent, one prompt, no fan-out, no verification — nine defensible findings, three serious, two of twelve off-target. A good result with none of the machinery.

So the skill's value is **not** better reasoning. It is four narrower things:

- **The rubric is written down.** An ad-hoc prompt includes the house rules when composed carefully. The same prompt typed at 17:45 on a Friday does not — exactly when the review matters.
- **The step exists and can be named.** The failure this design actually suffered was not a bad review; it was that the spec was written, moved past, and a human had to ask whether it had been reviewed at all.
- **Target assembly is boring and easy to do incompletely.** The right diff range, the plan the PR came from, the searches a rubric line depends on.
- **Divergent generation on demand.** `alternatives` exists because competing designs are worth having precisely when nobody is sitting there to ask for options.

Anything not justifiable against those four does not ship.

## Background

**Prior art in-house:** `counselors` (Aaron Francis; multi-CLI fan-out, cross-model disagreement, 10–20+ min per run), `review-round` (Retenium-local; periodic whole-codebase review that files issues), the built-in `/code-review` (generic defects, maintained upstream, structurally unable to grade house conventions), and superpowers' `requesting-code-review` / `receiving-code-review`.

**Measured elsewhere.** On 50 real PRs from Sentry, Cal.com and Grafana: Greptile catches 82% of bugs with 11 false positives per run; CodeRabbit catches 44% with 2. The difference is architectural — CodeRabbit runs 40+ deterministic linters beside the model, and linters do not hallucinate; Greptile indexes the whole codebase, buying catch rate and noise together. Published thresholds: signal ratio above 60% is a usable tool, above 80% a good one; false-positive rates above 25% make reviewers stop reading. Estimates put 60–80% of AI review comments in the noise bucket. A 22,000-comment study across 178 repositories found concise, focused comments far likelier to produce an actual code change.

**Borrowed from Claude Code's own `ReportFindings` contract:** a required concrete failure scenario, a two-state `CONFIRMED | PLAUSIBLE` verdict, a very short claim summary, a category slug per finding, an outcome field for re-review, and a hard cap on findings per run.

**Retained from research:** asking one agent for "three approaches" yields three variants of its own first answer. Distinct alternatives require naming an **axis of variation** up front.

## Goals

- One entry point, four rubrics, so review quality stops depending on how the prompt was phrased that day.
- **Token-efficient by default.** A slower single agent beats a faster fan-out when output is comparable. Mechanical checks never consume model tokens at all.
- **Signal ratio above 60%, aiming at 80%.** Tier 3 findings are dropped, not ranked last.
- Every finding ends in an explicit decision, including "drop it" — and a dropped finding teaches the rubric something.
- Works unchanged in project repos and in `it4web/*` package repos.

## Non-goals

- **Generic bug hunting** — `/code-review` owns it; `/critique pr` says when a change warrants `ultra`.
- **Whole-codebase sweeps** — that is `review-round`.
- **Posting to GitHub.** No comment, review, reaction, or issue without an explicit instruction. Nothing this skill writes ever addresses a person.
- **A findings ledger file.** Re-runs are cheap; a stale ledger is a liability.
- **Restating CLAUDE.md**, or restating `receiving-code-review`. The skill points at both.
- **The pipeline skill** (issue #14).

## Design

### Invocation

```
/critique plan [path]              # design, or design + implementation plan
/critique pr [number|BASE..HEAD]   # default: origin/main..HEAD
/critique alternatives [path|number]
/critique missing [number|path]    # code targets only
/critique                          # infer mode, state which was chosen and why
/critique pr --verify              # upgrade pass: promote or refute code-defect findings
```

Inference order for the bare form: an uncommitted or newer-than-HEAD spec in `docs/superpowers/specs/` → `plan`; otherwise uncommitted code or an open PR → `pr`; otherwise ask. Rule 1 precedes rule 2 because a brainstorm ends with an uncommitted spec. The chosen mode is always announced first.

**Targets are SHA ranges, not "the current diff".** A range is reproducible, survives further commits, and makes re-review of only what changed since the last run possible.

### Reviewer contract

Two rules that apply to every dispatched reviewer:

- **Crafted context, never session history.** The reviewer receives the target and the rubric — not the conversation that produced the work. A reviewer holding the author's context inherits the author's blind spots, which is the entire point of asking someone else.
- **Read-only on this checkout.** No mutation of the working tree, index, `HEAD`, or branch state. Inspection via `git show` / `git diff` / `git log`; if another revision must be materialised, `git worktree add` into a temporary directory. A reviewer that moves `HEAD` in an active slot destroys work in progress.

### Pipeline

**Stage 0 — deterministic pre-pass (no model tokens).** Mechanically decidable rules run as shell checks before any agent is dispatched. Two kinds:

- **Exact** — reported directly as findings, no adjudication needed: a missing `.changelog/unreleased/` fragment on a branch with user-visible changes; files under `vendor/it4web/` newer than `vendor/composer/installed.json`; `@php` in Blade.
- **Heuristic** — grep produces candidates, and only the candidates (with surrounding context) are handed to the model to adjudicate: `?->` / `??` / `if (!$x)` introduced by this diff; `->each(` on something that may be a builder; write calls inside `database/migrations/`; hand-rolled markup where a TallUI/Flux component exists.

This replaces the "house invariants" rubric layer of v4. Exact checks cannot hallucinate and cost nothing; heuristic checks arrive at the model as a short list of specific lines rather than an instruction to go looking. Both shorten the prompt the reviewer must hold, which is a better answer to rubric overload than a longer checklist.

**Stage 1 — assemble the target.** `plan`: the design doc plus its implementation plan if one exists. `pr`: the SHA-range diff **plus** the linked issue/plan when referenced. `alternatives`: the design; on a PR target the linked plan or issue is **required**, since a diff shows what changed and not why that approach was chosen. `missing`: the diff or path plus the searches its rubric lines require. Abort clearly if the target is empty.

**Stage 2 — review.** One agent, the mode's rubric, plus the heuristic candidates from stage 0. Model configurable, **Fable by default**. Effort is session-level; there is no per-dispatch override and this spec promises none.

**Stage 3 — filter and shape.** Drop anything without a nameable failure scenario. Drop Tier 3 entirely. Merge duplicates. Rank by tier, then severity. **Cap at 15 findings**; if more survive, report the top 15 and state how many were suppressed — a cap is a forcing function against the wall-of-nitpicks failure that makes readers skim.

**Stage 4 — report in chat.** Each finding carries:

| Field | Content |
|---|---|
| claim | ≤60 characters, the claim alone — no rationale, no consequence clause |
| tier | 1 runtime error / breaking change / security · 2 architectural inconsistency, measurable performance, maintainability · (3 is dropped, never shown) |
| verdict | **CONFIRMED** — checked against the code and it holds · **PLAUSIBLE** — reasoned but not positively verifiable |
| category | kebab-case slug, so recurring noise sources become countable |
| location | `file:line` where one exists |
| failure scenario | concrete inputs or state → wrong output, crash, or corrupted data |

Absence and judgment claims — everything `plan` and `missing` produce — are **PLAUSIBLE** by construction, and that is a label rather than a defect. This replaces v4's mode-gated verify: nothing has to be barred from verification, because verification changes a label instead of deciding life or death.

Then an **overall verdict**: *ship* / *ship with fixes* / *rework*, with one sentence of reasoning.

**Stage 5 — triage.** Each finding gets a disposition: **fix now** / **rework the plan** / **file an issue** (hands off to `work-on`) / **drop**. Nothing is filed or posted unless chosen. How to evaluate the findings themselves — verify before implementing, no performative agreement, push back with reasoning, stop if items are unclear because they may be related — is `receiving-code-review`'s job; this skill points at it rather than restating it.

**Stage 6 — feed the rubric.** A **drop** with a reason is a labelled false positive. The skill proposes an amendment to `references/rubrics.md` — usually a scoping clause on the category that produced it. **Amendments require approval; they are never applied automatically**, because a reviewer that silently learns to suppress is a reviewer that quietly stops working. Category slugs make the pattern visible: three drops in one category is a rubric problem, not three unlucky findings.

**`--verify`** dispatches a skeptic over CONFIRMED-eligible findings only — claims that something in the code is wrong at a location. It promotes what survives and refutes what does not. It has no effect on PLAUSIBLE findings, which have no positive evidence to defend.

**When more agents are allowed** — two cases, both of which must survive the A/B in Validation: a rubric line needing genuinely *different evidence gathering* (split by evidence source, never by topic), and `alternatives`, where separate agents hold different assigned axes and the diversity is the product.

### Rubric structure

After the pre-pass, what reaches the model is two layers, ordered **most severe first**, because attention decays down a prompt:

1. **Hazard classes** — generative questions, each requiring an explicit verdict: *hazard found* / *checked, clean* / *not applicable*. Silence is not an answer.
2. **Principles** — a pointer to `CLAUDE.md`, not a copy: root cause over symptom, polymorphism over conditionals, prefer the framework and existing components, small objects, DRY on the third repetition. Findings here must still name a concrete failure scenario, which is what stops taste from becoming Tier 3 noise.

### Modes

**`plan`** — reviews a design, or a design plus its implementation plan. States which artifacts it found and asks only the applicable questions; complaining that a design has no test list is out of scope when no plan exists yet.

*Always:* Are acceptance criteria falsifiable rather than aspirational? What is stated as fact but never verified, and what would verify it? What is the smallest single fact that would invalidate this? Is the project-vs-package call made explicitly? **How would the proposed mechanism fail, and what does it cost?**

*Only with an implementation plan present:* Does the plan implement *this* design or a drifted version? Does each acceptance criterion map to a named test? Does each step end in something verifiable? Are there hidden ordering dependencies?

**`pr`** — hazard classes, then principles, over the SHA range plus stage-0 candidates.

*Hazard classes (verdict required for each):* does this change a contract something **already running** depends on —
- **in the database** — a migration dropping or renaming what a deploy operation not yet run in production still reads; a schema change and its backfill in the same migration;
- **in flight** — changed job constructors or payload shapes while jobs from the previous release are still draining; renamed or removed events with live listeners;
- **inside the app** — config and `.env` keys, cached config, Blade components and their slots, traits, base classes, route names referenced elsewhere;
- **outward-facing** — API endpoints and webhook payloads consumed by third parties.

Production state is not knowable from a diff, so these are reported as **conditional hazards** — "if operation X has not run in production, this drops a column it reads" — never as certainties. In an `it4web/*` package repo (detected by `composer.json` `name`, not directory path — package repos are separate git repos and project `vendor/` is gitignored, so no path glob matches) the *inside the app* class extends to every consumer: public and constructor signatures, published views and config, behaviour changes despite unchanged signatures. Same weighting for a project PR bumping an `it4web/*` constraint.

*Plan conformance, when a linked plan was assembled:* does this implement the plan, and are deviations intentional improvements or unremarked drift? Issues with the plan itself, rather than the implementation, are reported as such.

**`alternatives`** — 3 agents (2 for small designs), each **assigned a different axis of variation** before seeing the proposal: "without adding a table", "in the package layer", "with no new UI", "by deleting something". Each returns a competing approach, its trade-offs, and — required — the condition under which it beats the current design; an alternative without such a condition is discarded. A final pass reports only genuinely distinct approaches. No verify pass; distinctness filtering does that job.

*On a design this is routine. On a PR it is a deliberate escalation*, because the code exists and a better alternative now costs throwing work away, so the bias against acting is strong — and a mode whose findings are routinely not acted on trains the reader to skim. Reach for it on a PR when the change is the first instance of a pattern that will be copied, when it touches an `it4web/*` package, or when the PR feels wrong and the reason will not surface.

**`missing`** — what is absent rather than wrong. **Code targets only**; on a design it duplicates `plan`.

- Edge cases with no test: empty states, deleted relations, unexpected enum values, concurrent access.
- Error paths with no handling; failures that would be silent in production.
- A concept written a **third** time that wants an abstraction (never the second — repeat once, abstract on the third).
- A convention followed elsewhere **in this repository** but not here, where its absence looks unintentional.

The last two cannot be answered from the diff — the prior instances are outside it by definition — and "written a third time" is a semantic judgment, not a grep, so the search cannot be specified as a fixed command. The requirement is therefore on the **evidence, not the method**: such a finding must cite **at least two paths in this repository** showing the prior instances. A claim without cited paths is dropped by the filter, not reported. Cross-repository comparison is out of scope: an agent cannot see the other nineteen repos and will invent "portfolio patterns" if asked.

### Re-review

Re-running on a SHA range after fixes reports each prior finding's **outcome** — *fixed* / *skipped* / *no change needed* — so a second pass is a short delta rather than a fresh wall of text.

### Files

```
skills/critique/
├── SKILL.md              # arg parsing, mode inference, target assembly, pipeline, triage
├── checks/               # stage-0 deterministic checks (shell)
└── references/
    └── rubrics.md        # hazard classes, principles pointer, category slugs, amendments
```

Global skill, symlinked into `~/.claude/skills/critique`; no per-project config.

## Validation strategy

- [ ] **Signal ratio above 60% across the first ten real runs**, counted as (Tier 1 + Tier 2 findings kept) ÷ total reported. Below 60%, tighten the filter before adding anything.
- [ ] **Stage 0 finds its exact checks with zero false positives** on 3 real branches. An exact check that misfires once is demoted to heuristic or deleted — it has no licence to be wrong.
- [ ] **Fan-out A/B, 2–3 targets per mode.** One target is two stochastic samples and decides nothing. Fan-out ships for a mode only if it finds materially more real defects across several targets. Record token cost for both.
- [ ] Run `/critique pr` against 2–3 **already-merged** PRs with known outcomes. Success = it surfaces what review found at the time, within the signal ratio above.
- [ ] **Every hazard class returns a verdict** on every `pr` run. A run that silently omits a class is a rubric-structure failure, not a reviewer failure.
- [ ] Run `/critique plan` against a design-only spec **and** a design + plan pair. Success = it states which artifacts it found and raises no plan-only complaints against a design-only target.
- [ ] Run `/critique missing` on a recent feature branch. Success = at least one real untested edge case, and the repetition/convention lines either cite ≥2 real paths or report nothing. An uncited claim is a failure, not a near miss.
- [ ] Run `/critique alternatives` on a design whose alternatives were already discussed. Success = genuinely distinct approaches, not restatements.
- [ ] **Compatibility fires without a package** — a migration/operation ordering hazard or a changed job payload in a plain project PR, phrased conditionally.
- [ ] Package weighting fires in both shapes: inside a package repo, and on a project PR bumping an `it4web/*` constraint.
- [ ] **A dropped finding produces an amendment proposal**, and no amendment is ever applied without approval.
- [ ] Confirm nothing is posted to GitHub in any run.

## Open questions

- **Name.** `/critique` avoids collision with `/review` and `/code-review`. Alternatives: `/second-opinion`, `/scrutiny`.
- **The finding cap of 15** is a guess. It should be tuned once the signal ratio is measured — a cap that bites regularly means the filter is too loose, not that the cap is too low.
- **Whether `missing` survives** once its two evidence-dependent lines are proven. If cited-path evidence turns out rare, it reduces to two lines and folds into `pr`.
- **Whether `alternatives` gets used** on PRs in practice, given the escalation framing. Revisit after a month.

## Decisions log

| Decision | Rationale |
|---|---|
| Deterministic pre-pass before any model call | Linters cannot hallucinate; this is the measured difference between 2 and 11 false positives per run. Also shortens the prompt, which attacks rubric overload at the source |
| Exact vs heuristic split in stage 0 | Only rules that are genuinely decidable get to report directly; the rest arrive as specific candidate lines for adjudication |
| CONFIRMED / PLAUSIBLE instead of kill-or-keep | Verification labels confidence rather than deciding existence, so absence claims need no special-casing and no mode needs to be barred from verifying |
| Tier 3 dropped, not ranked last | Style and preference findings are the documented cause of reviewers skimming |
| Findings capped at 15 | A forcing function against the wall-of-nitpicks failure |
| ≤60-character claims | Concise comments are measurably likelier to produce a change |
| SHA ranges, not the working diff | Reproducible, survives further commits, enables delta re-review |
| Read-only reviewer contract | A reviewer that moves `HEAD` in an active slot destroys work in progress |
| Crafted context, never session history | A reviewer holding the author's context inherits the author's blind spots |
| Dropped findings propose rubric amendments, approval required | Every serious reviewer learns from dismissals; automatic suppression is how one quietly stops working |
| Overall verdict line | Per-finding dispositions do not answer "can this merge" |
| Points at CLAUDE.md and `receiving-code-review` | Two copies of anything drift |
| Single reviewer by default | An unaided review produced nine defensible findings on this spec; fan-out must beat that measurably |
| Compatibility always on, packages weighted | Migrations, deploy operations and queued jobs break compatibility more often than package APIs |
| `alternatives` kept, framed as PR-escalation | Justified by divergent generation when nobody is present to ask |
