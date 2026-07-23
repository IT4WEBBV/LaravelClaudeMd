# `/critique` — one review skill, four modes — design

**Date:** 2026-07-23
**Status:** approved design, revised after an independent plan-review → pending spec sign-off → implementation plan → PR
**Canonical home:** `IT4WEBBV/LaravelClaudeMd`, `skills/critique/` (the PR target).
**Related, out of scope:** a "pipeline" skill that chains brainstorm → plan → critique → handoff → work-on → critique via subagents. Deferred to its own design — see issue #14 and Non-goals.

## Summary

Encode the two review steps that are currently done from memory into one skill with four modes.

The existing chain — `brainstorming` → `writing-plans` → **review** → `handoff pr` → `work-on` → **review** — has skills for four of its six steps. The two unencoded steps are exactly the two quality gates, which is why review quality varies per run. `/critique` fills both, plus two review shapes that have no home today.

1. **Four modes, one skill:** `plan`, `pr`, `alternatives`, `missing`. Same engine, different rubric.
2. **Backwards compatibility is a conditional concern, not a mode** — added automatically when the review targets an `it4web/*` package or a change to one's version constraint.
3. **Engine is Claude subagents**, fanned out per concern, deduplicated, then filtered by a **verify pass whose strictness depends on the mode**.
4. **Findings land in chat**, ranked, then get triaged into one of four dispositions: fix now / rework the plan / file an issue / drop.
5. **No generic bug hunting.** That layer belongs to the built-in `/code-review`; this skill owns the layer that is house-specific.

## Background — current state

Manual flow today, per feature: start a brainstorm, get a design + plan doc, ask Fable to review it, `/handoff pr`, `/work-on <PR>`, ask Fable to review the PR. The Fable reviews are ad-hoc prompts composed fresh each time — no fixed rubric, no false-positive filter, no defined disposition for what comes back.

Prior art examined:

- **`counselors`** (`skills/counselors/`, 283 lines) — Aaron Francis's tool; fans a prompt out to multiple external AI CLIs in parallel and synthesises. Its documented value is cross-model *disagreement* (its examples include agents proposing three distinct architectures for one problem). Cost: 10–20+ minutes per run.
- **`review-round`** (Retenium-local, 199 lines + `.claude/workflows/code-review.js`) — periodic whole-codebase review that files clustered GitHub issues. Two levers worth stealing: an **adversarial verify pass** to kill false positives, and a **data-only, re-runnable** engine that creates no GitHub objects by itself.
- **Built-in `/code-review`** — reviews the working diff or a PR, verifies findings, reports them ranked. Strong on generic defects; structurally unable to grade house conventions. `/code-review ultra` is the heavyweight branch/PR review; it is billed and can only be launched by the human.

Technique borrowed from research: asking one agent for "three approaches" yields three variants of its own first answer. Genuinely distinct alternatives require naming an **axis of variation** up front.

## Goals

- One entry point, four rubrics, so review quality stops depending on how the prompt was phrased that day.
- Findings that are worth acting on. A wall of nitpicks trains the reader to skim, which is worse than no review.
- Fast enough to run on every PR — target **4–8 minutes** for a `pr` run. Slower than that and it gets skipped under deadline pressure.
- Every finding ends in an explicit decision, including "drop it".
- Works unchanged in all ~20 project repos **and** in the `it4web/*` package repos, which have a different directory shape.

## Non-goals

- **Generic bug hunting.** `/code-review` already does it, is maintained upstream, and improves without our effort. `/critique pr` tells the human when a change (package edit, large diff) warrants running `ultra` themselves.
- **Whole-codebase sweeps.** That is `review-round`'s job; `/critique` always reviews a bounded target.
- **Posting to GitHub.** No comment, review, reaction, or issue is created without an explicit instruction in the session. Nothing this skill writes ever addresses a person.
- **A findings ledger file.** Considered and rejected: findings go to chat and are triaged there. Re-runs are cheap; a stale ledger is not.
- **The pipeline skill** (issue #14). The stations must be good before automating the sequence.

## Design

### Invocation

```
/critique plan [path]          # default: newest file in docs/superpowers/specs/
/critique pr [number]          # default: current branch's diff vs origin/main
/critique alternatives [path|number]
/critique missing [path|number]
/critique                      # infer mode, state which was chosen and why
```

Inference for the bare form, **in this order**:

1. A spec in `docs/superpowers/specs/` that is uncommitted or newer than the last commit → `plan`.
2. Otherwise, uncommitted/unpushed code changes or an open PR for the branch → `pr`.
3. Otherwise ask.

Rule 1 must precede rule 2: a brainstorm ends with an uncommitted spec, so the most common bare invocation would otherwise be inferred as `pr`. The chosen mode is always announced before work starts, so a wrong inference costs one sentence, not one run.

### Modes and rubrics

Each mode decomposes into concerns; one subagent per concern, run in parallel.

**`plan`** — is this the right thing, and is the plan honest about itself?
- Acceptance criteria are falsifiable, not aspirational.
- The test list actually covers the acceptance criteria; each criterion maps to at least one named test.
- The project-vs-package call is made explicitly.
- Assumptions stated as fact but not verified — each one named, with what would verify it.
- The smallest single fact that would invalidate the plan.
- Steps whose ordering is a hidden dependency.
- **How the proposed mechanism fails, and what it costs.** Added after the rubric's own review found that everything above audits the *plan's honesty* while nothing interrogates whether the thing being planned actually works, or what it spends — which is where the most serious findings turned out to live.

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
- A final pass reports only alternatives genuinely distinct from the proposal, not restatements. **This distinctness pass replaces the verify pass for this mode** (see Engine).

**`missing`** — what is absent rather than wrong?
- Edge cases with no test: empty states, deleted relations, unexpected enum values, concurrent access.
- A concept written a third time that wants an abstraction (never on the second — the house rule is repeat once, abstract on the third).
- A convention followed elsewhere **in this repository** but not here, where its absence looks unintentional. Cross-repository comparison is explicitly out of scope: a subagent cannot see the other nineteen repos, and asking for it invites invented "portfolio patterns".
- Error paths with no handling, and failures that would be silent in production.

### Backwards compatibility (conditional concern)

**Trigger** — the original path-glob (`packages/it4web-*`) was wrong: package repos are their own git repositories, so their diffs are `src/...`, and in project repos `vendor/` is gitignored so package code never appears in a diff at all. It would have fired nowhere, least of all in the package repos where the risk is highest. Correct detection:

1. The repository's own `composer.json` has a `name` matching `it4web/*` → every review in that repo gets this concern.
2. A project's diff changes an `it4web/*` constraint in `composer.json` / `composer.lock` → the concern is added, targeted at the version delta.

**What it reviews:**
- Changed or removed public method signatures, and constructor signatures of anything instantiable by consumers.
- Blade component slots, attributes, and published view paths.
- Config keys, published assets, and migration expectations.
- Behaviour changes consumers depend on even though the signature is unchanged.

Its output names the risk to *other* apps explicitly, because those consumers are not in the diff and therefore not in any reviewer's context.

### Engine

1. **Resolve target** — spec path, `gh pr diff <n>`, or `git diff origin/main...HEAD`. Abort with a clear message if the target is empty.
2. **Fan out** — one subagent per concern, in parallel, each with only its own rubric.
3. **Dedup** — merge findings pointing at the same cause *before* verification. Verifying first would waste skeptic runs on duplicates and, worse, a stochastic skeptic can refute one copy of a duplicated finding while passing the other, leaving the merged finding in a contradictory state.
4. **Verify — strictness depends on the mode.** This is the correction to the biggest flaw found in review: a single refute-by-default skeptic is right for defect claims and destroys absence claims.
   - `pr`, and the backwards-compatibility concern: **refute-by-default.** The finding asserts a defect at a location, so it can be checked against the code; when the skeptic is uncertain, the finding dies. This is the noise filter.
   - `plan` and `missing`: **actionability check, not refutation.** These findings assert that something is *absent* or *unverified*, which by construction has no positive evidence to defend. The skeptic instead asks: is the claim true as stated, is it already handled elsewhere in the target, and is it worth the reader's attention? Uncertainty does not kill it; irrelevance does.
   - `alternatives`: **no verify pass.** The distinctness pass in the mode definition does this job; running a skeptic over design proposals would refute them all.
5. **Report in chat** — ranked by severity, each with `file:line` where one exists, a one-sentence claim, and a concrete failure scenario. A finding without a nameable failure scenario is dropped before reporting.
6. **Triage** — each finding is offered a disposition: **fix now** (do it in this session), **rework the plan** (back to the spec), **file an issue** (hand off to `work-on` later), **drop** (with the reason stated once). Nothing is filed or posted without the human choosing it.

**If a run is too slow**, concerns are cut in this order — stated here so the trade is explicit rather than silent: `missing`'s abstraction check first, then the portfolio-convention check, then `pr`'s changelog/vendor-hack checks (both cheaply verifiable by hand). The house-rule and backwards-compatibility concerns are never cut.

### Model

Subagent model is **configurable, defaulting to Fable** for every stage — reviewers, skeptics, and axis agents alike. Fable is the default because it is the model already used for these reviews by hand, and because the divergent-thinking bias that suits `alternatives` does no harm to the other modes.

Reasoning **effort is session-level**, not per-dispatch: there is no per-subagent effort override, so the spec makes no promises about it. The session's effort applies to whatever the skill spawns.

**Cost note:** a `pr` run dispatches roughly 6–10 subagents plus one skeptic per surviving finding. That is real spend on every PR. If cost proves out of line with value, the lever is fewer concerns per mode, not a cheaper model — a weak reviewer produces plausible noise that the skeptic then has to clean up.

### Files

```
skills/critique/
├── SKILL.md              # entry point: arg parsing, mode inference, engine, triage
└── references/
    └── rubrics.md        # the four rubrics + the backwards-compatibility concern
```

Global skill, symlinked into `~/.claude/skills/critique` like the rest, so it works in every repo without per-project config.

## Validation strategy

- [ ] **Architecture A/B.** Run one `pr` target twice: once through the per-concern fan-out, once with a single reviewer given the whole rubric. Success = the fan-out finds materially more real defects. If it does not, the engine is latency and cost for nothing and the skill collapses to four saved prompts. This tests the design's central unproven belief.
- [ ] Run `/critique pr` against 2–3 **already-merged** PRs where the outcome is known. Success = it surfaces the issues found in review at the time, with no more than one or two false positives per run.
- [ ] **Audit the skeptic, not just the survivors.** During validation runs, have the verify pass emit what it refused and why. Success = no absence/judgment finding was killed for being unprovable. Without this, the failure mode that motivated the per-mode verify split is invisible by construction.
- [ ] Run `/critique plan` against an existing spec in `docs/superpowers/specs/`. Success = at least one genuine unverified assumption identified.
- [ ] Run `/critique missing` on a recent feature branch. Success = at least one real untested edge case, and no invented cross-repository "portfolio pattern".
- [ ] Run `/critique alternatives` on a design whose alternatives were already discussed. Success = the axes produce approaches that are actually distinct, not restatements.
- [ ] **Package trigger, both shapes.** Confirm the backwards-compatibility concern fires (a) on a change inside an `it4web/*` package repo, and (b) on a project PR that bumps an `it4web/*` constraint.
- [ ] Confirm a `pr` run completes in 4–8 minutes; if not, cut concerns in the documented order.
- [ ] Confirm nothing is posted to GitHub in any run.

## Open questions

- **Name.** `/critique` avoids collision with the built-in `/review` and `/code-review`. Alternatives if it reads oddly: `/second-opinion`, `/scrutiny`.
- **Default number of axes** in `alternatives` mode — 3 assumed; may want 2 for small plans.
- **Two modes are on probation.** `missing` may fold into `plan` and `pr`; `alternatives` is the mode most at risk of being built and then unused. Both are kept for now — `missing` because "what is absent" is a genuinely different question from "what is wrong", and `alternatives` because its value appears exactly when the human is *not* present to ask for options. Revisit both after a month of real use.

## Decisions log

| Decision | Rationale |
|---|---|
| One skill, four modes | Matches the `handoff [chat\|pr]` idiom already in use; one place to maintain rubrics |
| Chat output, no ledger file | Re-runs are cheap; a stale ledger is a liability. Dispositions carry the outcome |
| Claude subagents, not `counselors` | 4–8 minutes vs 10–20; house-rule violations are not a matter of opinion. Diversity in `alternatives` is recovered by assigning axes instead of varying models |
| Verify strictness varies by mode | Refute-by-default is the noise filter for defect claims and silently destroys absence claims. One skeptic policy cannot serve both |
| Dedup before verify | Avoids contradictory statuses on duplicated findings and wasted skeptic runs |
| Package detection by `composer.json` name, not path glob | Package repos are separate git repos (`src/...` diffs) and project `vendor/` is gitignored, so a path glob fires nowhere |
| Fable by default, model configurable | Already the model used for these reviews by hand; configurable so it can be raised for a hard review |
| Effort is session-level | No per-subagent effort override exists; the spec promises only what the harness supports |
| `alternatives` kept despite being probation-worthy | Its value is precisely when the human is not at the keyboard to ask for options; folding it into a brainstorm assumes presence |
| No generic bug hunting | `/code-review` owns it and improves upstream; duplicating it would age badly |
