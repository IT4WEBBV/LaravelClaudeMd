# `/critique` — one review skill, four modes — design

**Date:** 2026-07-23
**Status:** revised design (v4) → pending spec sign-off → implementation plan → PR
**Canonical home:** `IT4WEBBV/LaravelClaudeMd`, `skills/critique/` (the PR target).
**Related, out of scope:** the "pipeline" skill — issue #14.
**Previous revisions:** `f7007f0` initial · `6117da4` after independent plan-review · `892f6a5` cut down for token efficiency. v4 replaces the enumerated `pr` checklist with principles + invariants + hazard classes.

## Summary

One skill, four modes, encoding the two review steps currently done from memory: reviewing a design/plan before implementation, and reviewing a PR before merge.

1. **Four modes:** `plan`, `pr`, `alternatives`, `missing`. Same pipeline, different rubric.
2. **One reviewer by default.** A single agent gets the whole rubric. Fan-out must earn its place.
3. **Rubrics are principles + invariants + hazard classes**, not an enumerated checklist — a checklist can only find what was thought of in advance, and a long one causes the attention decay it is meant to prevent.
4. **Findings land in chat**, ranked, each ending in an explicit disposition: fix now / rework the plan / file an issue / drop.
5. **No generic bug hunting.** That layer belongs to the built-in `/code-review`.

## What this skill is actually for

Worth stating plainly, because an earlier revision of this spec got it wrong and grew an engine around it.

Asking an independent model "what do you think of this plan / this PR" already works. Tested during the design of this spec: one agent, one prompt, no fan-out, no verification pass — nine defensible findings, three serious, two of twelve off-target. A good result with none of the machinery.

So the skill's value is **not** better reasoning. It is four narrower things:

- **The rubric is written down.** An ad-hoc prompt includes the house rules when composed carefully. The same prompt typed at 17:45 on a Friday does not — exactly when the review matters.
- **The step exists and can be named.** The failure this design actually suffered was not a bad review; it was that the spec was written, moved past, and a human had to ask whether it had been reviewed at all.
- **Target assembly is boring and easy to do incompletely.** The right diff, the plan the PR came from, the searches a rubric line depends on.
- **Divergent generation on demand.** `alternatives` exists because competing designs are worth having precisely when nobody is sitting there to ask for options — a different value from the three above, and the one that justifies that mode.

Anything not justifiable against those four does not ship.

## Background

Prior art: **`counselors`** (Aaron Francis; multi-CLI fan-out, cross-model disagreement, 10–20+ min per run), **`review-round`** (Retenium-local; periodic whole-codebase review that files issues), and the built-in **`/code-review`** (generic defects, maintained upstream, structurally unable to grade house conventions).

Retained from research: asking one agent for "three approaches" yields three variants of its own first answer. Distinct alternatives require naming an **axis of variation** up front.

## Goals

- One entry point, four rubrics, so review quality stops depending on how the prompt was phrased that day.
- **Token-efficient by default.** A slower single agent beats a faster fan-out when output is comparable. More agents only where they demonstrably produce better output.
- Findings worth acting on. A wall of nitpicks trains the reader to skim.
- Every finding ends in an explicit decision, including "drop it".
- Works unchanged in project repos and in `it4web/*` package repos.

## Non-goals

- **Generic bug hunting** — `/code-review` owns it; `/critique pr` says when a change warrants `ultra`.
- **Whole-codebase sweeps** — that is `review-round`.
- **Posting to GitHub.** No comment, review, reaction, or issue without an explicit instruction. Nothing this skill writes ever addresses a person.
- **A findings ledger file.** Re-runs are cheap; a stale ledger is a liability.
- **Restating CLAUDE.md.** The rubric points at it. Two copies of the house style drift.
- **The pipeline skill** (issue #14).

## Design

### Invocation

```
/critique plan [path]           # design, or design + implementation plan
/critique pr [number]           # default: current branch's diff vs origin/main
/critique alternatives [path|number]
/critique missing [number|path] # code targets only
/critique                       # infer mode, state which was chosen and why
/critique pr --verify           # opt-in adversarial pass (code-defect findings only)
```

Inference order for the bare form: an uncommitted or newer-than-HEAD spec in `docs/superpowers/specs/` → `plan`; otherwise uncommitted code or an open PR → `pr`; otherwise ask. Rule 1 precedes rule 2 because a brainstorm ends with an uncommitted spec. The chosen mode is always announced first.

### Pipeline

1. **Resolve and assemble the target** — the part most worth encoding:
   - `plan` — the design doc, plus its implementation plan if one exists.
   - `pr` — `gh pr diff <n>` or `git diff origin/main...HEAD`, **plus** the linked issue/plan when referenced.
   - `alternatives` — the design; on a PR target the linked plan or issue is **required**, since a diff shows what changed and not why that approach was chosen.
   - `missing` — the diff or path, plus the searches its rubric lines require.
   - Abort with a clear message if the target is empty.
2. **Review.** One agent, the whole rubric for that mode. Model configurable, **Fable by default**. Effort is session-level; there is no per-dispatch override and this spec promises none.
3. **Filter.** One pass over all findings: drop anything without a nameable failure scenario, merge duplicates, rank by severity.
4. **Report in chat** — ranked, `file:line` where one exists, one-sentence claim, concrete failure scenario.
5. **Triage** — each finding gets a disposition: **fix now** / **rework the plan** / **file an issue** (hands off to `work-on`) / **drop** (reason stated once). Nothing is filed or posted unless chosen.

**When more agents are allowed** — only two cases, both of which must survive the A/B in Validation:
- A rubric line needs *different evidence gathering* than the rest. Split by evidence source, never by topic.
- `alternatives`, where separate agents hold *different assigned axes* — the diversity is the product.

**`--verify`** runs an adversarial pass where a skeptic tries to refute each surviving finding, defaulting to refuted when uncertain. **It applies only to code-defect findings** — claims that something in the code is wrong at a location. It is not available for `plan` or `missing` findings, which assert absence, have no positive evidence to defend, and would be wiped wholesale.

### Rubric structure

Every mode's rubric has the same three layers, ordered **most severe first**, because attention decays down a prompt:

1. **Hazard classes** — generative questions, each requiring an explicit verdict: *hazard found* / *checked, clean* / *not applicable*. The verdict is mandatory; silence is not an answer.
2. **House invariants** — the short enumerated list of arbitrary conventions no amount of good taste would infer.
3. **Principles** — a pointer to `CLAUDE.md`, not a copy: root cause over symptom, polymorphism over conditionals, prefer the framework and existing components, small objects, DRY on the third repetition. Findings here must still name a concrete failure scenario, which is what keeps taste-based observations honest.

### Modes

**`plan`** — reviews a design, or a design plus its implementation plan. It states which artifacts it found and asks only the applicable questions; complaining that a design has no test list is out of scope when no plan exists yet.

*Always:* Are acceptance criteria falsifiable rather than aspirational? What is stated as fact but never verified, and what would verify it? What is the smallest single fact that would invalidate this? Is the project-vs-package call made explicitly? **How would the proposed mechanism fail, and what does it cost?**

*Only with an implementation plan present:* Does the plan implement *this* design or a drifted version? Does each acceptance criterion map to a named test? Does each step end in something verifiable? Are there hidden ordering dependencies?

**`pr`** — three layers as above.

*Hazard classes (verdict required for each):* does this change a contract something **already running** depends on —
- **in the database** — a migration dropping or renaming what a deploy operation not yet run in production still reads; a schema change and its backfill in the same migration;
- **in flight** — changed job constructors or payload shapes while jobs from the previous release are still draining; renamed or removed events with live listeners;
- **inside the app** — config and `.env` keys, cached config, Blade components and their slots, traits, base classes, route names referenced elsewhere;
- **outward-facing** — API endpoints and webhook payloads consumed by third parties.

Production state is not knowable from a diff, so these are reported as **conditional hazards** — "if operation X has not run in production, this drops a column it reads" — never as certainties. In an `it4web/*` package repo (detected by `composer.json` `name`, not by directory path — package repos are separate git repos and project `vendor/` is gitignored, so no path glob matches) the *inside the app* class extends to every consumer: public and constructor signatures, published views and config, and behaviour changes despite unchanged signatures. Same weighting for a project PR bumping an `it4web/*` constraint.

*House invariants:* changelog fragment present; no modified files under `vendor/it4web/`; data manipulation lives in a deploy operation, not a schema migration; `->get()->each()` rather than `->each()` on a builder; TallUI/Flux/TallFormbuilder before hand-rolled UI; no `@php` in Blade; a bugfix has a test that fails before it.

*Principles:* graded against `CLAUDE.md`. The violations seen most often are null-safety band-aids tolerating a bad state instead of fixing its cause, raw `DB::` where Eloquent belongs, conditionals that want enum behaviour or polymorphism, and tests asserting "doesn't throw" rather than correct behaviour.

**`alternatives`** — 3 agents (2 for small designs), each **assigned a different axis of variation** before seeing the proposal: "without adding a table", "in the package layer", "with no new UI", "by deleting something". Each returns a competing approach, its trade-offs, and — required — the condition under which it beats the current design; an alternative without such a condition is discarded. A final pass reports only genuinely distinct approaches. No verify pass; distinctness filtering does that job.

*On a design this is routine. On a PR it is a deliberate escalation*, because the code exists and a better alternative now costs throwing work away, so the bias against acting is strong — and a mode whose findings are routinely not acted on trains the reader to skim. Reach for it on a PR when the change is the first instance of a pattern that will be copied, when it touches an `it4web/*` package, or when the PR feels wrong and the reason will not surface.

**`missing`** — what is absent rather than wrong. **Code targets only**; on a design it duplicates `plan`.

- Edge cases with no test: empty states, deleted relations, unexpected enum values, concurrent access.
- Error paths with no handling; failures that would be silent in production.
- A concept written a **third** time that wants an abstraction (never the second — repeat once, abstract on the third).
- A convention followed elsewhere **in this repository** but not here, where its absence looks unintentional.

The last two cannot be answered from the diff — the prior instances are outside it by definition — and "written a third time" is a semantic judgment, not a grep, so the search cannot be specified as a fixed command. The requirement is therefore on the **evidence, not the method**: such a finding must cite **at least two paths in this repository** showing the prior instances. A claim without cited paths is dropped by the filter, not reported. Cross-repository comparison is out of scope: an agent cannot see the other nineteen repos and will invent "portfolio patterns" if asked.

### Files

```
skills/critique/
├── SKILL.md              # arg parsing, mode inference, target assembly, pipeline, triage
└── references/
    └── rubrics.md        # the three layers per mode; hazard-class examples
```

Global skill, symlinked into `~/.claude/skills/critique`; no per-project config.

## Validation strategy

- [ ] **Fan-out A/B, 2–3 targets per mode.** One target is two stochastic samples and decides nothing. Fan-out ships for a mode only if it finds materially more real defects across several targets; otherwise single-agent stands. Record token cost for both.
- [ ] Run `/critique pr` against 2–3 **already-merged** PRs with known outcomes. Success = it surfaces what review found at the time, with no more than one or two false positives. **If that ceiling is breached**, the remedy is a tighter filter-pass instruction, and only then `--verify` on by default for that mode.
- [ ] **Every hazard class returns a verdict** on every `pr` run — found / clean / not applicable. A run that silently omits a class is a failure of the rubric structure, not of the reviewer.
- [ ] Run `/critique plan` against a design-only spec **and** a design + plan pair. Success = it states which artifacts it found and raises no plan-only complaints against a design-only target.
- [ ] Run `/critique missing` on a recent feature branch. Success = at least one real untested edge case, and the repetition/convention lines either cite ≥2 real paths or report nothing. A claim with no cited paths is a failure, not a near miss.
- [ ] Run `/critique alternatives` on a design whose alternatives were already discussed. Success = genuinely distinct approaches, not restatements.
- [ ] **Compatibility fires without a package** — a migration/operation ordering hazard or a changed job payload in a plain project PR, phrased conditionally.
- [ ] Package weighting fires in both shapes: inside a package repo, and on a project PR bumping an `it4web/*` constraint.
- [ ] Confirm nothing is posted to GitHub in any run.

## Open questions

- **Name.** `/critique` avoids collision with `/review` and `/code-review`. Alternatives: `/second-opinion`, `/scrutiny`.
- **Whether `missing` survives** as a mode once its two evidence-dependent lines are proven. If cited-path evidence turns out rare, it reduces to two lines and should fold into `pr`.
- **Whether `alternatives` gets used** on PRs in practice, given the escalation framing. Revisit after a month.

## Decisions log

| Decision | Rationale |
|---|---|
| Principles + invariants + hazard classes, not a checklist | A checklist finds only what was thought of in advance, and a 25-item one causes the attention decay it aims to prevent |
| Principles point at CLAUDE.md rather than restating it | Two copies of the house style drift; the pointer updates itself |
| Invariants stay enumerated | Changelog fragments and deploy-operation rules are arbitrary conventions, not derivable from good taste |
| Hazard classes require an explicit verdict | Forces coverage of the highest-severity class without a long list; silence is not an answer |
| Hazards reported conditionally | Production state is unknowable from a diff |
| Single reviewer by default | An unaided review produced nine defensible findings on this spec; fan-out must beat that measurably |
| Fan-out split by evidence source, never by topic | Topic splits are throughput theatre |
| `--verify` opt-in, code-defect findings only | One skeptic per finding was the largest cost driver, and refute-by-default wipes absence claims |
| A/B over 2–3 targets | One target compares two stochastic samples and locks in a decision by luck |
| `missing` demands cited paths, not a specified search | "Written a third time" is semantic; the honest requirement is on evidence, not method |
| Compatibility always on, packages weighted | Migrations, deploy operations and queued jobs break compatibility more often than package APIs |
| `plan` distinguishes design from design+plan | Different artifacts, different questions |
| `alternatives` kept, framed as PR-escalation | Justified by the fourth value: divergent generation when nobody is present to ask |
| Chat output, no ledger file | Re-runs are cheap; a stale ledger is a liability |
