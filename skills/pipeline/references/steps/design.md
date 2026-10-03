# The `design` step

Read also: `shared/catch-up.md`, `shared/dev-stack.md`

`design` invokes `superpowers:brainstorming`, then `superpowers:writing-plans` for an **Architectural**
design — one leg, because brainstorming already tail-calls writing-plans and two legs would double-run
it; for a **Bounded** design, brainstorming's Bounded path with no `writing-plans` (§Design size). In
`interactive` the human drives the brainstorm in the session, one `design:run` step. In `autoflow` it is
two steps (§`autoflow`'s design): a **spec** agent turns a tight brief into a spec **and writes the
questions it would have asked plus its assumed answers into the spec**, so `/critique plan` audits
exactly those assumptions; a fresh **plan** agent reads the committed spec cold and writes the plan. The
step records the spec and plan pointers; the size is the spec's `**Design size:**` header, never stored.
The work goes to `review-plan` next.

## Design size — Bounded or Architectural

One chain, one set of legs and gates; only what the design leg writes is proportional to the change.
Every leg after `design` runs unchanged on either size.

| | **Architectural** | **Bounded** |
|---|---|---|
| Station | `brainstorming` → `writing-plans` | `brainstorming` on its Bounded path; `writing-plans` is not invoked |
| Spec | full design | `docs/superpowers/specs/<date>-<slug>-design.md`, ~15 lines |
| Plan | bite-sized TDD plan | `docs/superpowers/plans/<date>-<slug>.md`, ~10 lines |
| Header | none, or `**Design size:** Architectural` | `**Design size:** Bounded` |

**The size is read, never stored.** `DesignSize::fromSpec(<spec markdown>)` returns `Bounded` only for
the exact header line and `Architectural` for anything else, so every older spec keeps the full chain.

### Who picks the size — always a human

The invocation's `medium` or `light` **permits** Bounded; with neither, the Architectural path is
required (`session.md` §Invocation). The brief's state says which path is permitted.

| | with `medium` or `light` | with neither |
|---|---|---|
| `interactive` | brainstorming runs normally; Bounded when it classifies Bounded | when brainstorming classifies Bounded, **ask** as one multiple-choice question: *"This looks like a small change: continue with a short design (Bounded), or write the full spec and plan?"* Yes → Bounded. No → tell brainstorming to take the Architectural path |
| `autoflow` | the design brief permits the Bounded path | the design brief requires the Architectural path |

brainstorming's own rule applies in every cell: *when in doubt between two paths, take the heavier
one.* A classification never selects Bounded on its own authority.

**Refuse Bounded in a package repo.** When the repo's `composer.json` `name` starts with `it4web/`,
say so and take the Architectural path. A shared package is never small.

## `autoflow`'s design — a spec step and a plan step

In `autoflow` the `design` leg is two steps (`pipeline_steps()`), so the agent that writes the plan reads
the committed spec cold, and the spec agent's exploration does not ride along into the plan (#73):

- **`design:spec`** brainstorms, commits the spec, sets `artifacts.spec` and removes `artifacts.plan`: a
  plan written for an earlier spec is not this spec's plan. It stops where brainstorming hands over to
  `writing-plans`, and commits no plan on the Architectural path. On the Bounded path it commits the
  plan as well, beside the spec where `pipeline_plan_path()` puts it, and sets `artifacts.plan`: a Bounded design is this step alone (`PIPELINE_BOUNDED_STEPS`),
  and the script skips `design:plan` on the `size` the spec step returned. It writes `artifacts` once,
  after its last commit, so a halt before that leaves the manifest calling for the spec step again.
- **`design:plan`** reads the spec and the code it points at, invokes `writing-plans`, commits the plan
  and sets `artifacts.plan`. The plan goes beside the spec (`pipeline_plan_path()`:
  `…/specs/<date>-<slug>-design.md` → `…/plans/<date>-<slug>.md`); when that file exists, from an
  earlier pass or the Bounded plan the design grew from, the step updates it in place. An answer the plan
  needs and the spec does not give goes into the spec's `## Assumptions`, committed before the plan. It
  may probe a behavioural claim the plan relies on, not an approach (§What design proves).

Both return `size`. The next design step is read from the manifest, as `review` / `resolve` is
(`pipeline_design_step()`): `plan` when the spec is set and the plan is not, or when the newest ledger
entry is a plan return (a `plan-approval` loop-back with no `review`); `spec` otherwise. `launch` starts
there, and `brief` refuses the other step. On a loop-back the script reruns:

| what sent the run back | the script reruns |
|---|---|
| `plan-insufficient` on an Architectural design (a plan gap; `review-plan:review`'s counts too) | `design:plan` only |
| `looped-back` from `review-plan:resolve` | `design:spec`, then `design:plan` |
| `plan-insufficient` on a Bounded design (an escalation) | `design:spec`, which grows the spec, then `design:plan` |

`interactive` keeps one `design:run` step: the human designs inline, in one session.

## What a Bounded design commits

Two commits, spec then plan, as an Architectural design makes them. `handoff` reads both from
the manifest, so a merge commit between or after them hides neither (`shared/catch-up.md` §Catching up with the base).
They are named as `writing-plans` names them, the spec at
`docs/superpowers/specs/<date>-<slug>-design.md` and the plan beside it at
`docs/superpowers/plans/<date>-<slug>.md` (`pipeline_plan_path()`), so a design that grows finds its plan.

The spec:

```markdown
# <title> — design

**Design size:** Bounded

## Problem
<as found in the code; a bug is reproduced first>

## Change
<the files, and what changes in each>

## Done when
<the observable result>

## Assumptions
<autoflow only: each question that would have been asked, and the answer assumed>
```

The plan:

```markdown
# <title> Implementation Plan

**Spec:** docs/superpowers/specs/<date>-<slug>-design.md

## Test first
<the failing test, and why it can fail on the defect>

## Steps
1. <step, ending in something verifiable>
```

`review-plan` reviews both with the unchanged `/critique plan` rubric. The target is ~25 lines plus
the code they name.

## The grow form — after an escalation

An escalation (`shared/plan-falls-short.md` §On a Bounded spec) sends the run back to `design`; backward
navigation is always allowed. **Grow the design; do not re-design it.** Escalation is one way: an
Architectural spec never shrinks. In grow form:

- change the spec header to `**Design size:** Architectural`;
- add a `## Grown from Bounded` section: what changed, why it grew, what already exists (described
  as state, not re-designed), what remains;
- add the remaining steps to the plan;
- commit the spec, then the plan.

In `autoflow` the spec step grows the spec and the plan step adds the remaining steps
(§`autoflow`'s design). Then `review-plan` re-runs over the grown spec and plan **plus
`git diff origin/<base>...HEAD`**, `handoff` re-runs (it pushes and keeps the existing PR), and
`implement` continues.

## Answering a plan gap

A plan gap (`shared/plan-falls-short.md` §On an Architectural spec) sends the run back to `design`.
`design` extends the plan, and the spec where it must say more, to cover the entry's `reason`;
what is already built is described as state, not re-designed. It leaves the entry unchanged, with
no `actions`: what it did goes in the spec, the plan and the reason it returns (#104). Then `review-plan`, `handoff` (it
pushes and keeps the existing PR) and `implement` run again, as after an escalation. In `autoflow` only
`design:plan` reruns (§`autoflow`'s design). `review-plan:review`'s own `plan-insufficient` is
answered the same way, and its `design` brief carries the same plan-gap line: it is a plan return
(`pipeline_is_plan_return()`), though no plan gap for `pipeline_done_legs()` or `run_audit.php` (#113).

## What design proves — reading, not running

`design` writes a spec and a plan; **it does not build or run the plan's code**, in a scratch copy or
anywhere else (#92: built in design, the plan becomes a diff in prose and the code is typed twice). It
confirms the signatures, APIs and paths the plan relies on by reading them, `php -l`
or grep. The plan's `Expected:` lines are predictions: `implement` proves them, test-first (`steps/implement.md`),
and a plan that falls short comes back as a plan gap (`shared/plan-falls-short.md`).

**Two exceptions, both probes.**

*To choose an approach.* When the choice between approaches hinges on whether one of them works at all,
`design` answers that one question with throwaway code: a few lines run on their own, never the plan's
code, never the suite. The question and what the probe showed go into the spec, beside the approach they
decided (owner, #92). The probe is brainstorming's *Spike* steps used as one step inside an
Architectural design, not a third design size: a Spike ends in a reported recommendation with no spec
and no plan, which a run cannot finish on, so the pipeline never classifies a work item as Spike
(§Design size). The probe's terminal state is its sentence in the spec. `design:plan` has no such probe:
it chooses no approach.

*To check a claim* (#105: reading confirms what code declares, not what it does). When the spec or the plan relies on what existing code *does*, which reading
cannot show, a design step answers that one yes/no question with **one throwaway command**: a `php -r`
or tinker one-liner (`php artisan tinker --execute="…"`), or one existing test selected by filter, run
the repo's own way (in a project, inside the `web` container; `shared/dev-stack.md` §Dev-stack readiness). Still excluded: the
suite, or a filter wide enough to be one; the plan's code, in a scratch copy or anywhere else; a new
file of any kind (a probe test, a script). One command per claim: a command that fails for its own
reasons (a typo, a wrong namespace) may be corrected, and stays one question. There is no cap on the
claims probed in a pass. A signature, a path, a config key or a column is still confirmed by reading,
`php -l` or grep.

The record is one line beside the task that relies on the claim, or beside the claim in the spec when
the step writes no plan:

```markdown
Probed: a bound `null` throws a `TypeError`: no, nothing is thrown (`php -r '…'`)
```

`Probed:`, the claim, what the command showed, the command. A claim with no such line was not run:
`review-plan` reads it as assumed.

A refuted claim changes what is written: the step designs or plans on what the probe showed, and the
line records the refutation. In `design:plan`, where the refuted claim is one the spec's design rests
on, the step corrects the spec's sentence and adds the `Probed:` line there, committed before the plan
as an `## Assumptions` addition is; where the refutation overturns the chosen approach it returns
`halted` with the probe as the reason, because it may not re-design. A claim the spec records as probed
is not probed again by `design:plan`: the plan's task cites it.

**A plan carries no *Verified before writing* header.** The plans that have one are records and stay as
they are; they are not exemplars for it (§Exemplars).

## Exemplars

**Plans and specs committed before 2026-09-14 are not exemplars** for test or proof policy. Many carry
rules the pipeline does not ask for, and a design step that reads them as examples copies the rules
forward. No plan is an exemplar for a *Verified before writing* header either (§What design proves).
