# /critique — rubrics

Reference for the four `/critique` modes: hazard classes, the principles pointer, the
evidence standards, and the stage-0 category slugs. The SKILL.md pipeline reads this
file; it is not loaded until a run needs it.

**This file says what to look for. It does not say what to say** — what is worth
reporting, and how much of it, is the reviewer's own call.

What reaches the reviewer **after** stage 0's deterministic pre-pass is two layers,
ordered **most severe first** (attention decays down a prompt):

1. **Hazard classes** — generative questions. Consider every one of them; mention the
   ones where there is something to say, including "checked the in-flight jobs,
   nothing" where that is informative. What is required is the *coverage*, not a
   recital of a verdict per class.
2. **Principles** — a pointer to `CLAUDE.md` (below), not a copy.

## Mode: `pr`

Hazard classes over the whole change plus the stage-0 candidates. Production state is
not knowable from a diff, so hazards are reported as **conditional** — "if operation X
has not run in production, this drops a column it reads" — never as certainties.

### Compatibility hazard classes

Does this change a contract something **already running** depends on —

- **In the database** — a migration dropping or renaming what a deploy operation not
  yet run in production still reads; a schema change and its backfill in the same
  migration.
- **In flight** — changed job constructors or payload shapes while jobs from the
  previous release are still draining; renamed or removed events with live listeners.
- **Inside the app** — config and `.env` keys, cached config, Blade components and
  their slots, traits, base classes, route names referenced elsewhere.
- **Outward-facing** — API endpoints and webhook payloads consumed by third parties.

### Package weighting

In an `it4web/*` package repo — detected by `composer.json` `name`, **not** directory
path (package repos are separate git repos and a project's `vendor/` is gitignored,
so no path glob matches) — the **inside the app** class extends to every consumer:
public and constructor signatures, published views and config, behaviour changes
despite unchanged signatures. Same weighting for a project PR that bumps an
`it4web/*` constraint.

### Plan conformance (only when a linked plan was assembled)

Does this implement the plan, and are deviations intentional improvements or
unremarked drift? Problems with the plan itself, rather than the implementation, are
reported as such.

## Mode: `plan`

Reviews a design, or a design plus its implementation plan. State which artifacts
were found; ask only the applicable questions. Complaining that a design has no test
list is out of scope when no plan exists yet.

**Always:**
- Are acceptance criteria falsifiable rather than aspirational?
- What is stated as fact but never verified, and what would verify it?
- What is the smallest single fact that would invalidate this?
- Is the project-vs-package call made explicitly?
- How would the proposed mechanism fail, and what does it cost?

**Only with an implementation plan present:**
- Does the plan implement *this* design, or a drifted version?
- Does each acceptance criterion map to a named test?
- Does each step end in something verifiable?
- Are there hidden ordering dependencies?

## Mode: `alternatives`

3 agents (2 for small designs), each **assigned a different axis of variation**
before seeing the proposal: "without adding a table", "in the package layer", "with
no new UI", "by deleting something". Each returns a competing approach, its
trade-offs, and — **required** — the condition under which it beats the current
design; an alternative without such a condition is discarded. A final pass reports
only genuinely distinct approaches.

On a design this is routine. **On a PR it is a deliberate escalation** — the code
exists, so a better alternative now costs throwing work away. Reach for it on a PR
when the change is the first instance of a pattern that will be copied, when it
touches an `it4web/*` package, or when the PR feels wrong and the reason won't surface.

## Mode: `missing`

What is absent rather than wrong. **Code targets only** — on a design it duplicates
`plan`.

- Edge cases with no test: empty states, deleted relations, unexpected enum values,
  concurrent access.
- Error paths with no handling; failures that would be silent in production.
- A concept written a **third** time that wants an abstraction (never the second —
  repeat once, abstract on the third).
- A convention followed elsewhere **in this repository** but not here, where its
  absence looks unintentional.

The last two cannot be answered from the change alone — the prior instances are
outside it by definition. The requirement is therefore on the **evidence, not the
method**: such a finding must cite **at least two paths in this repository** showing
the prior instances. A claim without cited paths has not been established — do not
make it. Cross-repository comparison is out of scope: an agent cannot see the other
repos and will invent "portfolio patterns" if asked.

## Principles (pointer, not a copy)

After the hazard classes, judgment is graded against the house rules in
`~/GitProjects/LaravelClaudeMd/LaravelClaudeMd/CLAUDE.md`. Read them there — do not
rely on a paraphrase. The recurring ones:

- Root cause over symptom; avoid null-safety guards (`?->`, `?:`, `if (! $x)`) as a
  fix when the root cause can be fixed instead.
- Polymorphism over conditionals (enums with behaviour, strategy patterns).
- Prefer the framework and existing components — Eloquent over raw `DB::`,
  TallUi/Flux and the form builder over hand-rolled markup.
- Small objects, small methods (Sandi Metz).
- DRY on the **third** repetition, not the first.

## Category slugs

The stage-0 checks emit a kebab-case category slug per candidate, so the deterministic
layer's output is countable. These are the checks' own labels; the reviewer is not
required to use them or to invent any of its own.

| Slug | Source |
|---|---|
| `blade-php` | `@php` in a Blade file (exact) |
| `vendor-hack` | file under `vendor/it4web/` newer than `installed.json` (exact) |
| `null-safe-op` | `?->` introduced by the diff (heuristic) |
| `null-coalesce` | `??` in added lines outside `config/` (heuristic) |
| `each-on-builder` | `->each(` on a possible builder (heuristic) |
| `migration-write` | data write inside `database/migrations/` (heuristic) |
| `changelog-fragment` | code changed, no `.changelog/unreleased/` fragment (heuristic question) |

## Amending this rubric

There is **no state between runs.** When the same issue keeps recurring — within a
session, or against a pasted prior report — that is a signal the rubric is mis-scoped
there. Propose a **deliberate, hand-authored amendment**
to this file; it requires approval and is never applied automatically. A reviewer that
silently learns to suppress is a reviewer that quietly stops working.
