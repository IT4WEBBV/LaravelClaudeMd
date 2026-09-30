# `design` may probe a behavioural claim the plan relies on Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task by task. Steps use checkbox (`- [ ]`) syntax for tracking. In an `autoflow` implement step, execute inline, task by task, with no subagents.

**Goal:** Every design step's brief carries a second probe line, `$claim`: one throwaway command per yes/no question about what existing code does, recorded as a `Probed:` line; engine.md describes the rule (#105).

**Architecture:**
- `skills/pipeline/checks/brief.php`, `pipeline_leg_overrides()`: `$probe` gets new first words, a new `$claim` line is added, and `design:run`, `design:spec` and `design:plan` carry `$claim` (the first two directly after `$probe`, `design:plan` directly after `$reads`).
- `skills/pipeline/checks/tests/BriefTest.php` pins the lines verbatim and their order.
- `skills/pipeline/references/engine.md`: §What design proves, §Dev-stack readiness and §Design size (*`autoflow`'s design*) say the same in prose.

**Tech Stack:** PHP 8.4 on the host, Pest 4, Markdown skill references.

**Spec:** `docs/superpowers/specs/2026-09-30-pipeline-design-probes-behavioural-claim-design.md`

## Global Constraints

- Suite, from the worktree root on the host: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`. `vendor/` is missing in a fresh worktree: run `composer install --no-interaction --quiet` first.
- The reworded `$probe`, verbatim: ``An exception, to choose an approach: when the choice between approaches hinges on whether one of them works at all, answer that question with a throwaway probe (a few lines run on their own, never the plan's code, never the suite) and write the question and what the probe showed into the spec.``
- The new `$claim`, verbatim: ``An exception, to check a claim: when the spec or the plan relies on what existing code does, which reading cannot show, answer that one yes/no question with one throwaway command (a `php -r` or tinker one-liner, or one existing test by filter; never the suite, never the plan's code, no new file), bringing the dev stack up first when the command needs it (engine.md §Dev-stack readiness), and write `Probed: <claim>: <what it showed> (<command>)` on one line beside the task that relies on it, or beside the claim in the spec when this step writes no plan.``
- `$reads`, `$exemplars` and `$assumptions` do not change. `design:plan` does not get `$probe`.
- Unchanged: `pipeline-autoflow.js`, `dispatch.php`, `manifest.md`, `gates.md`, `SKILL.md`, the `/critique` rubrics, the `review-plan` and `implement` overrides, `run_cost.php`, `run_audit.php`, `LockStepTest.php`, engine.md's `##` and `###` headings, and the Bounded spec and plan templates in engine.md.
- engine.md prose wraps at 110 columns.
- This repo has no `.changelog/` and no `CHANGELOG.md`: no changelog entry.
- Sibling run #52 may change `brief.php`, `BriefTest.php` or engine.md first: a step whose brief orders it merges the base (engine.md §Catching up with the base), and the `old` texts below are then matched by their content, not by line number.

## Review Focus

1. **`design:plan` must not be told to choose an approach.** It gets `$claim` only; Task 1 pins `not->toContain('to choose an approach')` on the plan brief.
2. **`$claim` sits next to the #92 line, not at the end of the list.** Task 1 asserts the joined `- ` lines: `$reads`, `$probe`, `$claim`, `$exemplars` for `design:spec` and `design:run`; `$reads`, `$claim`, `$exemplars` for `design:plan`.
3. **`interactive`'s `design:run` gets both lines too.** Task 1's first test loops over `autoflow` `spec` and `interactive` `run`.
4. **The `§Dev-stack readiness` pointer resolves.** `LockStepTest`'s *keeps every engine.md section a brief names* reads the name up to `)`; it is an existing `##` heading. Task 1 runs the whole suite, which includes it.
5. **§Dev-stack readiness no longer contradicts itself.** Its first paragraph calls a stack that cannot start a hard failure; for a design probe it is not one. Task 2 writes that sentence into the same section and greps for it, and for the absence of `The one exception is a probe`.

---

## File Structure

- Modify `skills/pipeline/checks/brief.php` (three override lists, two line variables).
- Modify `skills/pipeline/checks/tests/BriefTest.php` (two existing tests).
- Modify `skills/pipeline/references/engine.md` (three sections).

---

### Task 1: the design briefs carry the claim probe

**Files:**
- Modify: `skills/pipeline/checks/brief.php:25-51` (`pipeline_leg_overrides()`)
- Test: `skills/pipeline/checks/tests/BriefTest.php:74-82` and `:84-108`

**Interfaces:**
- Consumes, all existing: `pipeline_brief(array $manifest, string $leg, string $manifestPath, ?string $step = null, ?callable $git = null): string`, which renders the overrides as `"- {$line}"` joined by `"\n"` under `## Overrides`; the test helper `brief_manifest(string $leg, array $extra = []): array` (mode `interactive` unless overridden); `pipeline_leg_overrides(string $mode): array`.
- Produces: nothing a later task calls. Task 2's prose repeats the rule the `$claim` line states.

- [ ] **Step 1: Write the failing tests.** In `BriefTest.php`, replace the whole test *tells design to confirm by reading, probe only to choose, and leave the Expected lines to implement* with

```php
it('tells design to confirm by reading, probe to choose or to check a claim, and leave the Expected lines to implement', function () {
    $reads = 'Do not build or run the plan\'s code, in a scratch copy or anywhere else: confirm the signatures and APIs it relies on by reading, `php -l` or grep; `implement` proves the plan\'s Expected lines (engine.md §What design proves).';
    $probe = 'An exception, to choose an approach: when the choice between approaches hinges on whether one of them works at all, answer that question with a throwaway probe (a few lines run on their own, never the plan\'s code, never the suite) and write the question and what the probe showed into the spec.';
    $claim = 'An exception, to check a claim: when the spec or the plan relies on what existing code does, which reading cannot show, answer that one yes/no question with one throwaway command (a `php -r` or tinker one-liner, or one existing test by filter; never the suite, never the plan\'s code, no new file), bringing the dev stack up first when the command needs it (engine.md §Dev-stack readiness), and write `Probed: <claim>: <what it showed> (<command>)` on one line beside the task that relies on it, or beside the claim in the spec when this step writes no plan.';
    $exemplars = 'Plans and specs committed before 2026-09-14 are not exemplars for test or proof policy, and no plan\'s `Verified before writing` header is part of the format.';

    foreach ([['autoflow', 'spec'], ['interactive', 'run']] as [$mode, $step]) {
        expect(pipeline_brief(brief_manifest('design', ['mode' => $mode]), 'design', '/tmp/m.json', $step))
            ->toContain("- {$reads}\n- {$probe}\n- {$claim}\n- {$exemplars}\n")
            ->not->toContain('The one exception')
            ->not->toContain('for test or proof policy.');
    }
});
```

In the test *splits autoflow's design into a spec step that stops at the spec and a plan step that reads it cold*, replace the four lines

```php
        ->toContain('Do not build or run the plan\'s code')
        ->toContain('Plans and specs committed before 2026-09-14 are not exemplars')
        ->toContain('- Commit the plan. Set `artifacts.plan`.')
        ->not->toContain('throwaway probe')
```

with

```php
        ->toContain(
            '- Do not build or run the plan\'s code, in a scratch copy or anywhere else: confirm the signatures and APIs it relies on by reading, `php -l` or grep; `implement` proves the plan\'s Expected lines (engine.md §What design proves).' . "\n"
            . '- An exception, to check a claim: when the spec or the plan relies on what existing code does, which reading cannot show, answer that one yes/no question with one throwaway command (a `php -r` or tinker one-liner, or one existing test by filter; never the suite, never the plan\'s code, no new file), bringing the dev stack up first when the command needs it (engine.md §Dev-stack readiness), and write `Probed: <claim>: <what it showed> (<command>)` on one line beside the task that relies on it, or beside the claim in the spec when this step writes no plan.' . "\n"
            . '- Plans and specs committed before 2026-09-14 are not exemplars'
        )
        ->toContain('- Commit the plan. Set `artifacts.plan`.')
        ->not->toContain('to choose an approach')
```

`->not->toContain('throwaway probe')` goes: the claim line says "throwaway command", so the old needle names nothing any more.

- [ ] **Step 2: Run the two tests to see them fail**

Run:

```bash
./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='probe to choose or to check a claim|reads it cold'
```

Expected: 2 failed. Both fail on a `toContain`: the first on the joined `$reads` / `$probe` / `$claim` / `$exemplars` block (today's brief still says `The one exception:` and has no claim line), the second on the joined `$reads` / `$claim` block.

- [ ] **Step 3: Change `pipeline_leg_overrides()`.** In `brief.php`, replace the `$probe` line

```php
    $probe = 'The one exception: when the choice between approaches hinges on whether one of them works at all, answer that question with a throwaway probe (a few lines run on their own, never the plan\'s code, never the suite) and write the question and what the probe showed into the spec.';
```

with

```php
    $probe = 'An exception, to choose an approach: when the choice between approaches hinges on whether one of them works at all, answer that question with a throwaway probe (a few lines run on their own, never the plan\'s code, never the suite) and write the question and what the probe showed into the spec.';
    $claim = 'An exception, to check a claim: when the spec or the plan relies on what existing code does, which reading cannot show, answer that one yes/no question with one throwaway command (a `php -r` or tinker one-liner, or one existing test by filter; never the suite, never the plan\'s code, no new file), bringing the dev stack up first when the command needs it (engine.md §Dev-stack readiness), and write `Probed: <claim>: <what it showed> (<command>)` on one line beside the task that relies on it, or beside the claim in the spec when this step writes no plan.';
```

In the `'design:run'` list and in the `'design:spec'` list, replace

```php
            $probe,
            $exemplars,
```

with

```php
            $probe,
            $claim,
            $exemplars,
```

(two places). In the `'design:plan'` list, replace

```php
            $reads,
            $exemplars,
            'Commit the plan. Set `artifacts.plan`.',
```

with

```php
            $reads,
            $claim,
            $exemplars,
            'Commit the plan. Set `artifacts.plan`.',
```

- [ ] **Step 4: Run the two tests to see them pass**

Run the command of Step 2.
Expected: 2 passed.

- [ ] **Step 5: Run the whole pipeline suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: all green, including `LockStepTest`'s *keeps every engine.md section a brief names* (the new `§Dev-stack readiness` pointer).

- [ ] **Step 6: Commit**

```bash
git add skills/pipeline/checks/brief.php skills/pipeline/checks/tests/BriefTest.php
git commit -m "feat(pipeline): every design brief may probe a behavioural claim with one throwaway command (#105)"
```

---

### Task 2: engine.md describes the claim probe

**Files:**
- Modify: `skills/pipeline/references/engine.md:730-736` (§What design proves, the paragraph *The one exception is a probe*)
- Modify: `skills/pipeline/references/engine.md:500-501` (§Dev-stack readiness, *Worktree now, stack later*)
- Modify: `skills/pipeline/references/engine.md:561-565` (§Design size, *`autoflow`'s design*, the `design:plan` bullet)

**Interfaces:**
- Consumes: the `$claim` line of Task 1, which points at `§Dev-stack readiness` and sits beside the `$reads` line pointing at `§What design proves`.
- Produces: nothing code reads. No heading changes.

- [ ] **Step 1: §What design proves.** Replace the paragraph that starts `**The one exception is a probe.**` and ends `The probe's terminal state is its sentence in the spec.` with

````markdown
**Two exceptions, both probes.**

*To choose an approach.* When the choice between approaches hinges on whether one of them works at all,
`design` answers that one question with throwaway code: a few lines run on their own, never the plan's
code, never the suite. The question and what the probe showed go into the spec, beside the approach they
decided (owner, #92). The probe is brainstorming's *Spike* steps used as one step inside an
Architectural design, not a third design size: a Spike ends in a reported recommendation with no spec
and no plan, which a run cannot finish on, so the pipeline never classifies a work item as Spike
(§Design size). The probe's terminal state is its sentence in the spec. `design:plan` has no such probe:
it chooses no approach.

*To check a claim* (#105). When the spec or the plan relies on what existing code *does*, which reading
cannot show, a design step answers that one yes/no question with **one throwaway command**: a `php -r`
or tinker one-liner (`php artisan tinker --execute="…"`), or one existing test selected by filter, run
the repo's own way (in a project, inside the `web` container; §Dev-stack readiness). Still excluded: the
suite, or a filter wide enough to be one; the plan's code, in a scratch copy or anywhere else; a new
file of any kind (a probe test, a script). One command per claim: a command that fails for its own
reasons (a typo, a wrong namespace) may be corrected, and stays one question. There is no cap on the
claims probed in a pass. A signature, a path, a config key or a column is still confirmed by reading,
`php -l` or grep.

Why: reading confirms what code declares, not what it does. After #92, 3 of the 11 runs that reached
`implement` came back `plan-insufficient`, against 0 of the 7 before; two of the three were a claim
about existing behaviour that nobody ran (IT4WEBBV/TallUi#429: a bound `null` was said to throw a
`TypeError`; IT4WEBBV/viewiemedia#2116: a `SocialFactory` row that did not hold). Each cost about
0.9–1.3M weighted tokens: `design`, `review-plan` and `handoff` again.

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
````

The section's first two paragraphs and the paragraph `**A plan carries no *Verified before writing* header.**` stay as they are: a `Probed:` line sits beside one task, it is not a header.

- [ ] **Step 2: §Dev-stack readiness.** Replace

```markdown
*Worktree now, stack later:* creating the worktree is cheap (git); the stack starts lazily, only
before `implement` — nothing is spun up merely to brainstorm.
```

with

```markdown
*Worktree now, stack later:* creating the worktree is cheap (git); the stack starts lazily, only
before `implement` — nothing is spun up merely to brainstorm. One exception: a design step that probes
a claim whose command needs the stack brings it up then, without asking, and leaves it running for
`implement` (§What design proves). There a stack that cannot start is no hard failure: the claim stays
unprobed, with no `Probed:` line, and the step carries on.
```

- [ ] **Step 3: §Design size, *`autoflow`'s design*.** In the `design:plan` bullet, replace its last sentence

```markdown
  earlier pass or the Bounded plan the design grew from, the step updates it in place. An answer the plan
  needs and the spec does not give goes into the spec's `## Assumptions`, committed before the plan.
```

with

```markdown
  earlier pass or the Bounded plan the design grew from, the step updates it in place. An answer the plan
  needs and the spec does not give goes into the spec's `## Assumptions`, committed before the plan. It
  may probe a behavioural claim the plan relies on, not an approach (§What design proves).
```

- [ ] **Step 4: Check the prose**

Run:

```bash
grep -c 'The one exception is a probe' skills/pipeline/references/engine.md
grep -c 'Two exceptions, both probes' skills/pipeline/references/engine.md
grep -c 'There a stack that cannot start is no hard failure' skills/pipeline/references/engine.md
grep -c 'may probe a behavioural claim the plan relies on, not an approach' skills/pipeline/references/engine.md
git diff -U0 skills/pipeline/references/engine.md | grep '^+' | awk 'length > 111' | wc -l
```

Expected: `0`, `1`, `1`, `1`, and `0` (no added line over 110 columns; the `+` is the 111th).

- [ ] **Step 5: Run the whole pipeline suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: all green; `LockStepTest` still finds `## Dev-stack readiness`, `## What design proves` and `## Design size`.

- [ ] **Step 6: Commit**

```bash
git add skills/pipeline/references/engine.md
git commit -m "docs(pipeline): engine.md describes the claim probe, its record and the stack it may start (#105)"
```
