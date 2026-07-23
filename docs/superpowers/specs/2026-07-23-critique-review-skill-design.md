# `/critique` — one review skill, four modes — design

**Date:** 2026-07-23
**Status:** revised design (v3, cut down after review) → pending spec sign-off → implementation plan → PR
**Canonical home:** `IT4WEBBV/LaravelClaudeMd`, `skills/critique/` (the PR target).
**Related, out of scope:** the "pipeline" skill — issue #14.
**Previous revisions:** `f7007f0` (initial), `6117da4` (after independent plan-review). This version deliberately **removes** machinery those contained; see "What this skill is actually for".

## Summary

One skill, four modes, that encodes the two review steps currently done from memory: reviewing a design/plan before implementation, and reviewing a PR before merge.

1. **Four modes:** `plan`, `pr`, `alternatives`, `missing`. Same pipeline, different rubric.
2. **One reviewer by default.** A single agent gets the whole rubric. Fan-out is an exception that must earn its place, not the architecture.
3. **Compatibility is a standing `pr` concern**, not a package-only one — migrations, deploy operations, queued jobs and events break compatibility far more often than package APIs do.
4. **Findings land in chat**, ranked, each ending in an explicit disposition: fix now / rework the plan / file an issue / drop.
5. **No generic bug hunting.** That layer belongs to the built-in `/code-review`.

## What this skill is actually for

Worth stating plainly, because the previous revision of this spec got it wrong and grew an engine around it.

Asking an independent model "what do you think of this plan / this PR" already works. Tested during the design of this very spec: one agent, one prompt, no rubric fan-out, no verification pass — nine defensible findings, three of them serious, two of twelve off-target. That is a good result with none of the machinery.

So the skill's value is **not** better reasoning, and it should not spend tokens pretending otherwise. Its value is three narrower things:

- **The rubric is written down.** An ad-hoc prompt includes the house rules when it is composed carefully. The same prompt typed at 17:45 on a Friday does not — which is exactly when the review matters.
- **The step exists and can be named.** The failure this design actually suffered was not a bad review; it was that the spec was written and moved past, and a human had to ask whether it had been reviewed at all. A named step in a chain is harder to skip than a habit.
- **Target assembly is boring and easy to do incompletely.** Fetching the right diff, pulling in the plan the PR came from, running the repo search a rubric line depends on. Fiddly, mechanical, worth encoding.

Everything in this spec should be justifiable against those three. Anything that is only justifiable as "more agents ought to find more" does not ship.

## Background

Prior art: **`counselors`** (Aaron Francis; multi-CLI fan-out, cross-model disagreement, 10–20+ min per run), **`review-round`** (Retenium-local; periodic whole-codebase review that files issues; source of the adversarial-verify idea), and the built-in **`/code-review`** (generic defects, maintained upstream, structurally unable to grade house conventions).

Technique retained from research: asking one agent for "three approaches" yields three variants of its own first answer. Distinct alternatives require naming an **axis of variation** up front.

## Goals

- One entry point, four rubrics, so review quality stops depending on how the prompt was phrased that day.
- **Token-efficient by default.** A slower single agent is preferred over a faster fan-out when the output is comparable. More agents are used only where they demonstrably produce better output.
- Findings worth acting on. A wall of nitpicks trains the reader to skim.
- Every finding ends in an explicit decision, including "drop it".
- Works unchanged in project repos and in `it4web/*` package repos.

## Non-goals

- **Generic bug hunting** — `/code-review` owns it; `/critique pr` says when a change warrants `ultra`.
- **Whole-codebase sweeps** — that is `review-round`.
- **Posting to GitHub.** No comment, review, reaction, or issue without an explicit instruction. Nothing this skill writes ever addresses a person.
- **A findings ledger file.** Re-runs are cheap; a stale ledger is a liability.
- **The pipeline skill** (issue #14).

## Design

### Invocation

```
/critique plan [path]          # design, or design + implementation plan
/critique pr [number]          # default: current branch's diff vs origin/main
/critique alternatives [path|number]
/critique missing [number|path] # code targets only
/critique                      # infer mode, state which was chosen and why
```

Inference order for the bare form: an uncommitted or newer-than-HEAD spec in `docs/superpowers/specs/` → `plan`; otherwise uncommitted code or an open PR → `pr`; otherwise ask. Rule 1 must precede rule 2 because a brainstorm ends with an uncommitted spec. The chosen mode is always announced first.

### Pipeline

1. **Resolve and assemble the target.** This is the part worth encoding:
   - `plan` — the design doc, plus its implementation plan if one exists (see below).
   - `pr` — `gh pr diff <n>` or `git diff origin/main...HEAD`, **plus** the linked issue/plan when the PR references one.
   - `alternatives` — the design; when the target is a PR, the linked plan or issue is **required**, because a diff shows what changed and not why that approach was chosen.
   - `missing` — the diff or path, plus targeted repo searches for the rubric lines that need them.
   - Abort with a clear message if the target is empty.
2. **Review.** One agent, the whole rubric for that mode. Model configurable, **Fable by default**. Reasoning effort is session-level; there is no per-dispatch override, so this spec promises none.
3. **Filter.** One pass over all findings together: drop anything without a nameable failure scenario, merge duplicates, rank by severity. A single pass, not one agent per finding.
4. **Report in chat** — ranked, `file:line` where one exists, one-sentence claim, concrete failure scenario.
5. **Triage** — each finding gets a disposition: **fix now** / **rework the plan** / **file an issue** (hands off to `work-on`) / **drop** (reason stated once). Nothing is filed or posted unless chosen.

**When more agents are allowed.** Only two cases, both of which must survive the A/B in Validation:

- A rubric line needs *different evidence gathering* than the rest (searching the repo for prior instances is a different activity from reading a diff). Split by evidence source, never by topic.
- `alternatives`, where separate agents exist to hold *different assigned axes* — the diversity is the product, not a throughput trick.

**Escalation, on request only:** `--verify` runs an adversarial pass where a skeptic tries to refute each surviving finding. Worth it before a risky merge, wasteful on every PR. Note it must not be applied to `plan` or `missing` findings with refute-by-default semantics: those assert absence, have no positive evidence to defend, and would be wiped wholesale.

### Modes and rubrics

**`plan`** — reviews a design, or a design plus its implementation plan. It states which artifacts it found and applies only the applicable questions; complaining that a design has no test list is out of scope when no plan exists yet.

*Always:* Are acceptance criteria falsifiable rather than aspirational? What is stated as fact but never verified, and what would verify it? What is the smallest single fact that would invalidate this? Is the project-vs-package call made explicitly? **How would the proposed mechanism fail, and what does it cost?**

*Only when an implementation plan is present:* Does the plan implement *this* design, or a drifted version of it? Does each acceptance criterion map to at least one named test? Does each step end in something verifiable? Are there hidden ordering dependencies between steps?

**`pr`** — house bar, from the global CLAUDE.md: null-safety band-aids (`?->`, `??`, `if (!$x)`) that tolerate a bad state instead of fixing its cause without written justification; raw `DB::` where Eloquent belongs; `->each()` on a query builder; conditionals that want enum behaviour or polymorphism; `@php` in Blade; hand-rolled UI where a TallUI/Flux/TallFormbuilder component exists; data manipulation inside schema migrations; tests asserting "doesn't throw" rather than correct behaviour; a bugfix without a test that fails before it; missing changelog fragment; modified files under `vendor/it4web/`. Plus the compatibility concern below, always.

**`alternatives`** — 3 agents (2 for small designs), each **assigned a different axis of variation** before seeing the proposal: "without adding a table", "in the package layer", "with no new UI", "by deleting something". Each returns a competing approach, its trade-offs, and — required — the condition under which it beats the current design; an alternative without such a condition is discarded. A final pass reports only genuinely distinct approaches. No verify pass; distinctness filtering does that job.

*On a design this is a routine step. On a PR it is a deliberate escalation*, because the code already exists and a better alternative now costs throwing work away — so the bias against acting is strong, and a mode whose findings are routinely not acted on trains the reader to skim. Reach for it on a PR when the change is the first instance of a pattern that will be copied, when it touches an `it4web/*` package, or when the PR feels wrong and the reason will not surface.

**`missing`** — what is absent rather than wrong. **Code targets only**; on a design it duplicates `plan`, and running both produces the same findings twice in two ranked lists.
- Edge cases with no test: empty states, deleted relations, unexpected enum values, concurrent access.
- Error paths with no handling; failures that would be silent in production.
- A concept written a **third** time that wants an abstraction (never the second — repeat once, abstract on the third). *Requires a repo search for prior instances; the first two are outside the diff by definition. Without that search this line is theatre and must be cut.*
- A convention followed elsewhere **in this repository** but not here. *Same requirement.* Cross-repository comparison is out of scope: an agent cannot see the other nineteen repos and will invent "portfolio patterns" if asked.

### Compatibility (standing concern in `pr` mode)

Always on, because compatibility breaks most often in ordinary application code:

- **Migrations and deploy operations.** A schema migration dropping a column that an operation not yet run in production still reads; a schema change and its backfill in the same migration; an index or column rename that older running code still references mid-deploy.
- **Queued jobs and events.** A changed job constructor or payload shape while queued jobs from the previous release are still draining; renamed or removed events with existing listeners.
- **Contracts inside the app.** Config keys, `.env` keys, cached config; Blade components and their slots/attributes used across many views; traits and base classes; route names referenced elsewhere.
- **Outward-facing surfaces.** API endpoints and webhook payloads consumed by third parties.

**In an `it4web/*` package repo** (detected by `composer.json` having a `name` matching `it4web/*`, not by directory path — package repos are separate git repos and project `vendor/` is gitignored, so no path glob matches) this concern is weighted highest and adds: changed or removed public and constructor signatures, published view paths and component attributes, published config keys, and behaviour changes consumers depend on despite unchanged signatures. Its output names the risk to *other* apps explicitly, since those consumers appear in no diff. The same weighting applies to a project PR that bumps an `it4web/*` constraint in `composer.json` / `composer.lock`.

### Files

```
skills/critique/
├── SKILL.md              # arg parsing, mode inference, target assembly, pipeline, triage
└── references/
    └── rubrics.md        # the four rubrics + the compatibility concern
```

Global skill, symlinked into `~/.claude/skills/critique`; no per-project config.

## Validation strategy

- [ ] **The A/B is decisive, not informative.** For each mode, run one real target through a single reviewer and through a per-concern fan-out. Fan-out ships for that mode only if it finds materially more real defects. Default stays single-agent otherwise. Record the token cost of both.
- [ ] Run `/critique pr` against 2–3 **already-merged** PRs with known outcomes. Success = it surfaces what review found at the time, with no more than one or two false positives.
- [ ] Run `/critique plan` against a design-only spec **and** against a design + plan pair. Success = it states which artifacts it found and does not raise plan-only complaints against a design-only target.
- [ ] Run `/critique missing` on a recent feature branch. Success = at least one real untested edge case; the repetition and convention lines either performed a repo search or reported nothing — never an unsupported claim.
- [ ] Run `/critique alternatives` on a design whose alternatives were already discussed. Success = genuinely distinct approaches, not restatements.
- [ ] **Compatibility fires without a package.** Confirm it flags a migration/operation ordering hazard or a changed job payload in a plain project PR.
- [ ] Package weighting fires in both shapes: inside a package repo, and on a project PR bumping an `it4web/*` constraint.
- [ ] Confirm nothing is posted to GitHub in any run.

## Open questions

- **Name.** `/critique` avoids collision with `/review` and `/code-review`. Alternatives: `/second-opinion`, `/scrutiny`.
- **Whether `missing` survives** as a mode once it is code-only and its two search-dependent lines are proven. If the repo searches turn out weak, it reduces to two rubric lines and should fold into `pr`.
- **Whether `alternatives` gets used** on PRs in practice, given the escalation framing. Revisit after a month.

## Decisions log

| Decision | Rationale |
|---|---|
| Single reviewer by default | An unaided independent review already produced 9 defensible findings on this spec. Fan-out must beat that measurably or it is cost without benefit |
| Fan-out split by evidence source, never by topic | Topic splits are throughput theatre; different evidence gathering is a real reason for a second agent |
| Verify pass demoted to `--verify` | One skeptic per finding was the largest cost driver and the naive review's false-positive rate was already acceptable |
| Compatibility always on, packages weighted | Migrations, deploy operations and queued jobs break compatibility more often than package APIs |
| Package detection by `composer.json` name | Package repos are separate git repos; project `vendor/` is gitignored — a path glob matches nothing |
| `plan` distinguishes design from design+plan | Different artifacts, different questions; plan-only complaints against a design are noise |
| `missing` restricted to code targets | On a design it duplicates `plan` |
| `alternatives` kept, framed as PR-escalation | Its value appears when nobody is present to ask for options; on a PR sunk cost biases against acting |
| Chat output, no ledger file | Re-runs are cheap; a stale ledger is a liability |
| Fable default, model configurable, effort session-level | Already the model used for these reviews by hand; the spec promises only what the harness supports |
