# /critique — rubrics

Reference for the four `/critique` modes: hazard classes, principles pointer, tier
definitions, category slugs, and the drop tally. The SKILL.md pipeline reads this
file; it is not loaded until a run needs it.

What reaches the reviewer **after** stage 0's deterministic pre-pass is two layers,
ordered **most severe first** (attention decays down a prompt):

1. **Hazard classes** — generative questions, each requiring an explicit verdict:
   **hazard found** / **checked, clean** / **not applicable**. Silence is not an answer.
2. **Principles** — a pointer to `CLAUDE.md` (below), not a copy.

## Tiers

- **Tier 1** — runtime error, breaking change, data loss, security.
- **Tier 2** — architectural inconsistency, measurable performance cost,
  maintainability risk, or a house-rule violation **with a named consequence**.
- **Tier 3** — subjective preference with **no named consequence**: naming,
  formatting, micro-optimisation, "I'd have done it differently". **Dropped** from
  the report and disclosed only as a per-category count.

The Tier 2 / Tier 3 boundary is the *consequence*: a hardcoded URL instead of a
named route is Tier 2 — it breaks when the route is renamed. If a finding has a real
consequence it is not Tier 3, whatever it looks like.

Every finding — any tier, any mode — must name a concrete **failure scenario**
(inputs or state → wrong output, crash, corrupted data). No nameable scenario → the
filter drops it, unreported.

## Verdicts (`pr` mode only)

- **CONFIRMED** — checked against the code and it holds.
- **PLAUSIBLE** — reasoned, not positively verifiable.

Shown **only in `pr` mode**. In `plan` and `missing` every finding is PLAUSIBLE by
construction (absence and judgment claims have no positive evidence to defend), so a
constant column is omitted rather than trained into the reader as ignorable.

## Mode: `pr`

Hazard classes over the whole change plus the stage-0 candidates. **Each class
returns a verdict.** Production state is not knowable from a diff, so hazards are
reported as **conditional** — "if operation X has not run in production, this drops a
column it reads" — never as certainties.

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
the prior instances. A claim without cited paths is dropped by the filter, not
reported. Cross-repository comparison is out of scope: an agent cannot see the other
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

Findings in the principles layer must still name a concrete failure scenario — that
is what stops taste from becoming a Tier 3 preference.

## Category slugs

Findings carry a kebab-case category slug so recurring noise sources become
countable. Stage-0 slugs (emitted by `checks/run-checks.php`):

| Slug | Source |
|---|---|
| `blade-php` | `@php` in a Blade file (exact) |
| `vendor-hack` | file under `vendor/it4web/` newer than `installed.json` (exact) |
| `null-safe-op` | `?->` introduced by the diff (heuristic) |
| `null-coalesce` | `??` in added lines outside `config/` (heuristic) |
| `each-on-builder` | `->each(` on a possible builder (heuristic) |
| `migration-write` | data write inside `database/migrations/` (heuristic) |
| `changelog-fragment` | code changed, no `.changelog/unreleased/` fragment (heuristic question) |

Reviewer findings use their own descriptive slugs (e.g. `hardcoded-url`,
`missing-edge-case`, `plan-drift`, `contract-break`).

## Drop tally

A drop with a reason is a labelled false positive. One drop is an anecdote; **three
drops in the same slug** means the rubric is mis-scoped there. Increment on each drop:

| Category slug | Drops |
|---|---|
| _(none yet)_ | 0 |

**At 3 drops in a slug**, propose a scoping amendment to this file. **Amendments
require approval and are never applied automatically** — a reviewer that silently
learns to suppress is a reviewer that quietly stops working. This tally is the only
state `/critique` keeps between runs; there is no findings store.
