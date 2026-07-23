# `/critique` — one review skill, four modes — design

**Date:** 2026-07-23
**Status:** approved design, pending spec sign-off → implementation plan → PR
**Canonical home:** `IT4WEBBV/LaravelClaudeMd`, `skills/critique/` (the PR target).
**Related, out of scope:** a "pipeline" skill that chains brainstorm → plan → critique → handoff → work-on → critique via subagents. Deliberately deferred to its own design (see Non-goals).

## Summary

Encode the two review steps that are currently done from memory into one skill with four modes.

The existing chain — `brainstorming` → `writing-plans` → **review** → `handoff pr` → `work-on` → **review** — has skills for four of its six steps. The two unencoded steps are exactly the two quality gates, which is why review quality varies per run. `/critique` fills both, plus two review shapes that have no home today.

1. **Four modes, one skill:** `plan`, `pr`, `alternatives`, `missing`. Same engine, different rubric.
2. **Backwards compatibility is a conditional concern, not a mode** — it is added automatically when the target touches an `it4web/*` package.
3. **Engine is Claude subagents**, fanned out per concern, followed by an **adversarial verify pass** that tries to refute each candidate finding before the human sees it.
4. **Findings land in chat**, ranked, then get triaged into one of four dispositions: fix now / rework the plan / file an issue / drop.
5. **No generic bug hunting.** That layer belongs to the built-in `/code-review`; this skill owns the layer that is house-specific.

## Background — current state

Manual flow today, per feature: start a brainstorm, get a design + plan doc, ask Fable to review it, `/handoff pr`, `/work-on <PR>`, ask Fable to review the PR. The Fable reviews are ad-hoc prompts composed fresh each time — no fixed rubric, no false-positive filter, no defined disposition for what comes back.

Prior art examined:

- **`counselors`** (`skills/counselors/`, 283 lines) — Aaron Francis's tool; fans a prompt out to multiple external AI CLIs in parallel and synthesises. Its documented value is cross-model *disagreement* (its examples include agents proposing three distinct architectures for one problem). Cost: 10–20+ minutes per run.
- **`review-round`** (Retenium-local, 199 lines + `.claude/workflows/code-review.js`) — periodic whole-codebase review that files clustered GitHub issues. Its two quality levers are worth stealing: an **adversarial verify pass** to kill false positives, and a **data-only, re-runnable** engine that creates no GitHub objects by itself.
- **Built-in `/code-review`** — reviews the working diff or a PR, verifies findings, reports them ranked. Strong on generic defects; structurally unable to grade house conventions. `/code-review ultra` is the heavyweight branch/PR review; it is billed and can only be launched by the human.

Technique borrowed from research: asking one agent for "three approaches" yields three variants of its own first answer. Genuinely distinct alternatives require naming an **axis of variation** up front.

## Goals

- One entry point, four rubrics, so review quality stops depending on how the prompt was phrased that day.
- Findings that are worth acting on. A wall of nitpicks trains the reader to skim, which is worse than no review.
- Fast enough to run on every PR (target: 2–4 minutes), because a review that costs twenty minutes gets skipped under deadline pressure.
- Every finding ends in an explicit decision, including "drop it".
- Works unchanged in all ~20 project repos and the `it4web/*` package repos.

## Non-goals

- **Generic bug hunting.** `/code-review` already does it, is maintained upstream, and improves without our effort. `/critique pr` tells the human when a change (package edit, large diff) warrants running `ultra` themselves.
- **Whole-codebase sweeps.** That is `review-round`'s job; `/critique` always reviews a bounded target.
- **Posting to GitHub.** No comment, no review, no reaction, no issue is created without an explicit instruction in the session. Nothing this skill writes ever addresses a person.
- **A findings ledger file.** Considered and rejected: findings go to chat and are triaged there. Re-runs are cheap; a stale ledger is not.
- **The pipeline skill.** Chaining the whole chain via subagents is a separate design. The stations must be good before automating the sequence, and a pipeline over six human-gated steps is a different problem.

## Design

### Invocation

```
/critique plan [path]          # default: newest file in docs/superpowers/specs/
/critique pr [number]          # default: current branch's diff vs origin/main
/critique alternatives [path|number]
/critique missing [path|number]
/critique                      # infer mode, state which was chosen and why
```

Inference for the bare form: uncommitted or unpushed changes present → `pr`; otherwise a spec newer than the last commit → `plan`; otherwise ask. The chosen mode is always announced before work starts, so a wrong inference costs one sentence, not one run.

### Modes and rubrics

Each mode decomposes into concerns; one subagent per concern, run in parallel.

**`plan`** — is this the right thing, and is the plan honest about itself?
- Acceptance criteria are falsifiable, not aspirational.
- The test list actually covers the acceptance criteria; each criterion maps to at least one named test.
- The project-vs-package call is made explicitly (see Backwards compatibility).
- Assumptions stated as fact but not verified — each one named, with what would verify it.
- The smallest single fact that would invalidate the plan.
- Steps whose ordering is a hidden dependency.

**`pr`** — does this meet the house bar? Rubric drawn from the global CLAUDE.md:
- Null-safety band-aids: `?->`, `??`, `if (!$x)` guards that tolerate a bad state instead of fixing its cause, without a written justification.
- Raw `DB::` / query-builder writes where Eloquent belongs (skipped model events, casts, relationship cleanup).
- `->each()` on a query builder instead of `->get()->each()`.
- Conditionals that want to be enum behaviour, strategy objects, or polymorphism.
- `@php` in Blade; hand-rolled UI where a TallUI/Flux/TallFormbuilder component exists.
- Data manipulation inside schema migrations instead of a deploy operation.
- Tests that assert "doesn't throw" rather than correct behaviour; a bugfix without a test that fails before it.
- Missing changelog fragment; modified files under `vendor/it4web/`.

**`alternatives`** — is there a radically different approach?
- N subagents (default 3), each **assigned a different axis of variation** before seeing the proposal, e.g. "solve this without adding a table", "solve this in the package layer", "solve this with no new UI", "solve this by deleting something".
- Each returns a competing approach, its trade-offs, and — required — the condition under which it beats the current design. An alternative with no such condition is discarded.
- A final pass reports only alternatives that are genuinely distinct from the proposal, not restatements.

**`missing`** — what is absent rather than wrong?
- Edge cases with no test: empty states, deleted relations, unexpected enum values, concurrent access.
- A concept written a third time that wants an abstraction (never on the second — the house rule is repeat once, abstract on the third).
- A pattern used elsewhere in the portfolio but not here, where its absence looks unintentional.
- Error paths with no handling, and failures that would be silent in production.

### Backwards compatibility (conditional concern)

Triggered when the target touches `packages/it4web-*`, or a project PR changes a package constraint. Adds one reviewer to whichever mode is running:

- Changed or removed public method signatures, and constructor signatures of anything instantiable by consumers.
- Blade component slots, attributes, and published view paths.
- Config keys, published assets, and migration expectations.
- Behaviour changes that consumers depend on even though the signature is unchanged.

Its output names the risk to *other* apps explicitly, because those consumers are not in the diff and therefore not in any reviewer's context.

### Engine

Identical across modes:

1. **Resolve target** — spec path, `gh pr diff <n>`, or `git diff origin/main...HEAD`. Abort with a clear message if the target is empty.
2. **Fan out** — one subagent per concern, in parallel, each with only its own rubric. Narrow rubrics beat one broad reviewer: a reviewer asked for everything reports the easiest things.
3. **Adversarial verify** — every candidate finding is dispatched to a skeptic instructed to *refute* it, defaulting to refuted when uncertain. Only survivors are reported. This is the single lever that decides whether the skill produces signal or noise.
4. **Dedup and rank** — merge findings pointing at the same cause, order by severity.
5. **Report in chat** — ranked, each with `file:line`, a one-sentence claim, and a concrete failure scenario. Findings that survived verification are marked as such.
6. **Triage** — each finding is offered a disposition: **fix now** (do it in this session), **rework the plan** (back to the spec), **file an issue** (hand off to `work-on` later), **drop** (with the reason stated once). Nothing is filed or posted without the human choosing it.

### Model and effort

| Stage | Model / effort | Why |
|---|---|---|
| Concern reviewers | Opus, high | Finding real defects is the whole product; a cheap reviewer produces plausible noise the verify pass then has to clean up |
| Adversarial verify | Opus, high | Few findings, so the cost is small, and a weak skeptic defeats the mechanism |
| `alternatives` axis agents | Fable | Divergent design generation; the mode where a different voice is the point |
| Target resolution, diff gathering | inline, no subagent | Mechanical |

### Files

```
skills/critique/
├── SKILL.md              # entry point: arg parsing, mode inference, engine, triage
└── references/
    └── rubrics.md        # the four rubrics + the backwards-compatibility concern
```

Global skill, symlinked into `~/.claude/skills/critique` like the rest, so it works in every repo without per-project config.

## Validation strategy

- [ ] Run `/critique pr` against 2–3 **already-merged** PRs where the outcome is known. Success = it surfaces the issues that were found in review at the time, without more than one or two false positives per run.
- [ ] Run `/critique plan` against an existing spec in `docs/superpowers/specs/`. Success = it identifies at least one genuine unverified assumption.
- [ ] Run `/critique alternatives` on a design whose alternatives were already discussed. Success = the axes produce approaches that are actually distinct, not restatements.
- [ ] Run `/critique pr` on a package change. Success = the backwards-compatibility reviewer fires without being asked and names a consumer-facing risk.
- [ ] Confirm the run completes in 2–4 minutes; if it does not, cut concerns rather than accept a slower default.
- [ ] Confirm nothing is posted to GitHub in any run.

## Open questions

- **Name.** `/critique` avoids collision with the built-in `/review` and `/code-review`. Alternatives if it reads oddly: `/second-opinion`, `/scrutiny`.
- **Default number of axes** in `alternatives` mode — 3 assumed; may want 2 for small plans.
- **Whether `missing` earns its place** as a separate mode or should fold into `plan` and `pr`. Kept separate for now because "what is absent" is a genuinely different question from "what is wrong", and folding it in risks it being reported last and read least.

## Decisions log

| Decision | Rationale |
|---|---|
| One skill, four modes | Matches the `handoff [chat\|pr]` idiom already in use; one place to maintain rubrics |
| Chat output, no ledger file | Re-runs are cheap; a stale ledger is a liability. Dispositions carry the outcome |
| Claude subagents, not `counselors` | 2–4 minutes vs 10–20; house-rule violations are not a matter of opinion. Diversity in `alternatives` is recovered by assigning axes instead of varying models |
| Backwards compatibility as a trigger, not a mode | Fires automatically when relevant instead of relying on the human to select it |
| No generic bug hunting | `/code-review` owns it and improves upstream; duplicating it would age badly |
