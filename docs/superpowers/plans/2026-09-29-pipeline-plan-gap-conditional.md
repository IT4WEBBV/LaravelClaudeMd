# The plan-gap line applies only to a gap, and a plan-gap entry is read-only Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task by task. Steps use checkbox (`- [ ]`) syntax for tracking. In an `autoflow` implement step, execute inline, task by task, with no subagents.

**Goal:** A step after `design` reports a plan gap only once it has found one (#96); `design` leaves the plan-gap entry it answers unchanged, and a step that rewrites an earlier ledger entry halts with a reason naming the leg, the key and the entry (#104).

**Architecture:**
- `skills/pipeline/checks/brief.php`: the two non-resolve lines of `pipeline_plan_gap_lines()` become a parallel, conditional pair; the design plan-gap line in `pipeline_brief_overrides()` gains a read-only sentence.
- `skills/pipeline/checks/dispatch.php`: `pipeline_ledger_problem()` names what changed through two new functions, `pipeline_entry_change()` and `pipeline_entry_kind()`.
- Docs in the task whose change they describe: engine.md §Design size (*A plan gap on an Architectural design*, steps 1 and 3) and §Failure policy; manifest.md *A plan gap*.

**Tech Stack:** PHP 8.4 on the host, Pest 4, Markdown skill references.

**Spec:** `docs/superpowers/specs/2026-09-29-pipeline-plan-gap-conditional-design.md`

## Global Constraints

- Suite, from the worktree root on the host: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`. `vendor/` is missing in a fresh worktree: run `composer install --no-interaction --quiet` first.
- The new Bounded line, verbatim: ``On a Bounded spec (its header says `**Design size:** Bounded`): run the escalation check first (engine.md §Design size), and only on escalation append the `design-size` entry and return `plan-insufficient`.``
- The new Architectural line, verbatim: ``On an Architectural spec: only when the plan falls short of what this step needs (files or behaviour it does not name), append a `plan-approval` entry with `leg`, `cycle`, `at`, a `reason` naming what the plan lacks, and outcome `looped-back`, then return `plan-insufficient`. The size alone is no gap: an Architectural plan needs no approval beyond `review-plan`'s.``
- The design plan-gap line, verbatim: ``Plan gap: extend the plan (and the spec where it must say more) to cover the entry's `reason`; describe what is already built as state, do not re-design it (engine.md §Design size). Leave that entry as it is, with no `actions`: what you did goes in the spec, the plan and the reason you return.``
- The halt reason's form: `{leg} {change} ledger entry {index} ({kind})`, e.g. `design added actions to ledger entry 1 (plan gap)`.
- No check on the content of a plan-gap `reason` (owner, direction 5). `LegStatus`, `pipeline_route()`, `pipeline-autoflow.js`, `run_audit.php`, the resolve step's plan-gap line and the review step's *append no review entry* line do not change.
- engine.md headings do not change: `LockStepTest` resolves the brief's `§` names against them.
- This repo has no `.changelog/` and no `CHANGELOG.md`: no changelog entry. The PR closes #96 and #104.

## Review Focus

1. **Key order is not a change.** Legs rewrite JSON with whatever tool they hold; `pipeline_ledger_problem()` runs on `pipeline_normalized()` copies (both callers normalize first), so an entry whose keys come back reordered must not halt. The existing `ReturnedTest` *tolerates a leg that reorders keys* case covers it; Task 3's step 4 runs it.
2. **A resolve step completing its own open entry still passes.** Adding `actions`, `outcome` and `issue_links` to the open entry is not a change; only the kept keys count there. The existing resolve-step tests in `ReturnedTest` and `DispatchCliTest` cover it.
3. **A nested change reads as a change of its top-level key.** An edited `actions[0].note` on a closed entry halts as `changed actions on`; `pipeline_entry_change()` compares top-level values strictly, arrays included.
4. **An entry that is gone, or is no longer an object**, halts as `removed ledger entry {i}` / `replaced ledger entry {i}` instead of a PHP `TypeError` from a typed parameter: `$new` is `mixed`. Task 3's `removed` case pins the first.
5. **The old Architectural wording is gone everywhere it lived** (`brief.php`, `BriefTest`'s positive assertion); Task 1's grep pins it.

---

## File Structure

- Modify `skills/pipeline/checks/brief.php` (Tasks 1, 2), `skills/pipeline/checks/dispatch.php` (Task 3).
- Modify `skills/pipeline/checks/tests/BriefTest.php` (Tasks 1, 2), `skills/pipeline/checks/tests/ReturnedTest.php` and `skills/pipeline/checks/tests/DispatchCliTest.php` (Task 3).
- Modify `skills/pipeline/references/engine.md` (Tasks 1, 2, 3), `skills/pipeline/references/manifest.md` (Task 2).

---

### Task 1: a later step reports a plan gap only once it has found one (#96)

**Files:**
- Modify: `skills/pipeline/checks/brief.php:220-222` (`pipeline_plan_gap_lines()`)
- Modify: `skills/pipeline/references/engine.md` (§Design size, *A plan gap on an Architectural design*, step 1)
- Test: `skills/pipeline/checks/tests/BriefTest.php`

**Interfaces:**
- Consumes: `pipeline_brief(array $manifest, string $leg, string $manifestPath, ?string $step = null): string` and the test helper `brief_manifest(string $leg, array $extra = []): array`, both existing.
- Produces: the two lines in *Global Constraints*; nothing later tasks call.

- [ ] **Step 1: Write the failing test.** Append to `BriefTest.php`:

```php
it('tells a later step to report a plan gap only once it has found one, on either size', function (string $leg, string $step) {
    foreach (['autoflow', 'interactive'] as $mode) {
        expect(pipeline_brief(brief_manifest($leg, ['mode' => $mode]), $leg, '/tmp/m.json', $step))
            ->toContain('On a Bounded spec (its header says `**Design size:** Bounded`): run the escalation check first (engine.md §Design size), and only on escalation append the `design-size` entry and return `plan-insufficient`.')
            ->toContain('On an Architectural spec: only when the plan falls short of what this step needs (files or behaviour it does not name), append a `plan-approval` entry with `leg`, `cycle`, `at`, a `reason` naming what the plan lacks, and outcome `looped-back`, then return `plan-insufficient`. The size alone is no gap: an Architectural plan needs no approval beyond `review-plan`\'s.')
            ->not->toContain('On an Architectural spec, append a `plan-approval` entry');
    }
})->with([
    'handoff' => ['handoff', 'run'],
    'implement' => ['implement', 'run'],
    'verify-ui' => ['verify-ui', 'run'],
    'review-pr' => ['review-pr', 'review'],
]);
```

In the existing test *tells a later leg how to report a plan gap, and design to extend the plan for it*, replace

```php
        ->toContain('On an Architectural spec, append a `plan-approval` entry with `leg`, `cycle`, `at`, `reason` and outcome `looped-back`');
```

with

```php
        ->toContain('On an Architectural spec: only when the plan falls short of what this step needs');
```

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='plan gap'`
Expected: FAIL: the four dataset cases on the Bounded `toContain` (the brief still says *While the spec's header says*), and *tells a later leg how to report a plan gap* on the new Architectural wording. The other tests the filter matches (in `BriefTest`, `RunAuditTest`, `ReturnedTest`, `AutoflowScriptTest`) pass.

- [ ] **Step 3: Change the lines.** In `brief.php`, `pipeline_plan_gap_lines()`, replace

```php
        'While the spec\'s header says `**Design size:** Bounded`, run the escalation check first (engine.md §Design size); on escalation append the `design-size` entry and return `plan-insufficient`.',
        'On an Architectural spec, append a `plan-approval` entry with `leg`, `cycle`, `at`, `reason` and outcome `looped-back` before returning `plan-insufficient`.',
```

with

```php
        'On a Bounded spec (its header says `**Design size:** Bounded`): run the escalation check first (engine.md §Design size), and only on escalation append the `design-size` entry and return `plan-insufficient`.',
        'On an Architectural spec: only when the plan falls short of what this step needs (files or behaviour it does not name), append a `plan-approval` entry with `leg`, `cycle`, `at`, a `reason` naming what the plan lacks, and outcome `looped-back`, then return `plan-insufficient`. The size alone is no gap: an Architectural plan needs no approval beyond `review-plan`\'s.',
```

- [ ] **Step 4: engine.md, step 1.** In §Design size, *A plan gap on an Architectural design*, replace

```markdown
1. The step appends `{gate: 'plan-approval', leg: <its leg>, cycle, at, reason, outcome: 'looped-back'}`
   and returns `plan-insufficient`. A return without that entry halts.
```

with

```markdown
1. The step appends `{gate: 'plan-approval', leg: <its leg>, cycle, at, reason, outcome: 'looped-back'}`
   and returns `plan-insufficient`. A return without that entry halts. The `reason` names what the plan
   lacks. The size alone is never a gap: an Architectural plan needs no approval beyond `review-plan`'s
   (#96: a `handoff` that read the brief's plan-gap line as a rule for every Architectural spec looped a
   covered plan back for "the owner's plan approval").
```

- [ ] **Step 5: Run the tests, the grep and the suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='plan gap'`
Expected: PASS.

Run: `grep -rn "On an Architectural spec, append\|While the spec's header says" skills/pipeline --exclude-dir=tests`
Expected: no output.

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, 0 failed (the existing *tells a resolve step to loop back on a plan gap* test still finds `run the escalation check first` in the implement brief).

- [ ] **Step 6: Commit**

```bash
git add skills/pipeline/checks/brief.php skills/pipeline/checks/tests/BriefTest.php skills/pipeline/references/engine.md
git commit -m "fix(pipeline): the plan-gap lines apply only once a step has found a gap; the size alone is none (#96)"
```

---

### Task 2: design leaves the plan-gap entry it answers unchanged (#104)

**Files:**
- Modify: `skills/pipeline/checks/brief.php:183` (`pipeline_brief_overrides()`, the design plan-gap line)
- Modify: `skills/pipeline/references/manifest.md:100-103` (*A plan gap*)
- Modify: `skills/pipeline/references/engine.md` (§Design size, *A plan gap on an Architectural design*, step 3)
- Test: `skills/pipeline/checks/tests/BriefTest.php`

**Interfaces:**
- Consumes: `pipeline_brief()` and `brief_manifest()` as in Task 1.
- Produces: the design plan-gap line in *Global Constraints*.

- [ ] **Step 1: Write the failing test.** In the existing test *tells a later leg how to report a plan gap, and design to extend the plan for it*, replace

```php
        ->toContain('Plan gap: extend the plan');
```

with

```php
        ->toContain('Plan gap: extend the plan')
        ->toContain('Leave that entry as it is, with no `actions`: what you did goes in the spec, the plan and the reason you return.');
```

- [ ] **Step 2: Run it to see it fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='tells a later leg how to report a plan gap'`
Expected: FAIL on the new `toContain`.

- [ ] **Step 3: Change the line.** In `brief.php`, `pipeline_brief_overrides()`, replace

```php
        $lines[] = 'Plan gap: extend the plan (and the spec where it must say more) to cover the entry\'s `reason`; describe what is already built as state, do not re-design it (engine.md §Design size).';
```

with

```php
        $lines[] = 'Plan gap: extend the plan (and the spec where it must say more) to cover the entry\'s `reason`; describe what is already built as state, do not re-design it (engine.md §Design size). Leave that entry as it is, with no `actions`: what you did goes in the spec, the plan and the reason you return.';
```

- [ ] **Step 4: manifest.md.** Replace

```markdown
**A plan gap** is a `plan-approval` entry written by a leg after `review-plan` on an Architectural
design (`engine.md` §Design size): `gate`, `leg` (the leg that found the gap), `cycle`, `at`, `reason`
and `outcome: looped-back`, and no `review`. Unlike an escalation it **is** a loop-back and counts
toward `review-plan`'s bound; like one, it resets `pipeline_done_legs()`.
```

with

```markdown
**A plan gap** is a `plan-approval` entry written by a leg after `review-plan` on an Architectural
design (`engine.md` §Design size): `gate`, `leg` (the leg that found the gap), `cycle`, `at`, `reason`
(what the plan lacks) and `outcome: looped-back`, **and nothing else**: no `review` and no `actions`,
because nothing reviews it and no step completes it. The `design` step that answers it leaves it
unchanged; what design did goes in the spec, the plan and the reason it returns, and a step that
changes the entry halts the run (*What a leg writes*). Unlike an escalation it **is** a loop-back and
counts toward `review-plan`'s bound; like one, it resets `pipeline_done_legs()`.
```

- [ ] **Step 5: engine.md, step 3.** In §Design size, *A plan gap on an Architectural design*, replace

```markdown
3. `design` extends the plan, and the spec where it must say more, to cover the entry's `reason`;
   what is already built is described as state, not re-designed. Then `review-plan`, `handoff pr`
   (updating the existing PR) and `implement` run again, as after an escalation.
```

with

```markdown
3. `design` extends the plan, and the spec where it must say more, to cover the entry's `reason`;
   what is already built is described as state, not re-designed. It leaves the entry unchanged, with
   no `actions`: what it did goes in the spec, the plan and the reason it returns (#104: a design that
   recorded its answer on the entry halted the run at the next brief). Then `review-plan`, `handoff pr`
   (updating the existing PR) and `implement` run again, as after an escalation.
```

- [ ] **Step 6: Run the test and the suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='tells a later leg how to report a plan gap'`
Expected: PASS.

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, 0 failed.

- [ ] **Step 7: Commit**

```bash
git add skills/pipeline/checks/brief.php skills/pipeline/checks/tests/BriefTest.php skills/pipeline/references/manifest.md skills/pipeline/references/engine.md
git commit -m "fix(pipeline): design leaves the plan-gap entry it answers unchanged; its fields are closed (#104)"
```

---

### Task 3: the halt names the leg, the key and the entry a step rewrote (#104)

**Files:**
- Modify: `skills/pipeline/checks/dispatch.php:246-260` (`pipeline_ledger_problem()`, and two new functions after `pipeline_pick()`)
- Modify: `skills/pipeline/references/engine.md` (§Failure policy, *A halted manifest is the one the check rejected*)
- Test: `skills/pipeline/checks/tests/ReturnedTest.php`, `skills/pipeline/checks/tests/DispatchCliTest.php`

**Interfaces:**
- Consumes: `pipeline_is_plan_gap(array $entry): bool` (`pipeline.php`), `pipeline_open_entry()`, `pipeline_gate_of()`, `pipeline_returned()`, `pipeline_normalized()` (`dispatch.php`), all existing. Test helpers `returned_before()`, `returned_after()` (`ReturnedTest.php`), `boundary_fixture()`, `boundary_brief()`, `boundary_open()`, `dispatch_leg_writes()`, `manifest_read()` (`DispatchCliTest.php` / `manifest.php`), all existing.
- Produces: `pipeline_entry_change(array $old, mixed $new, ?array $only = null): ?string` and `pipeline_entry_kind(array $entry): string`; the halt reason `"{$leg} {$change} ledger entry {$index} ({$kind})"`.

- [ ] **Step 1: Write the failing tests.** Append to `ReturnedTest.php`:

```php
it('names the leg, what changed and which entry when a step rewrites an earlier ledger entry', function (string $leg, array $ledger, array $rewritten, string $reason) use ($noUi) {
    $before = returned_before($leg, $ledger);

    expect(pipeline_returned($before, returned_after($before, 'continued', $rewritten), $noUi, DesignSize::Architectural))
        ->toBe(['action' => 'halt', 'reason' => $reason]);
})->with(function () {
    $approved = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r', 'outcome' => 'continued'];
    $gap = ['gate' => 'plan-approval', 'leg' => 'implement', 'cycle' => 2, 'at' => '2026-09-22T12:00:00Z', 'reason' => 'needs a queue', 'outcome' => 'looped-back'];
    $review = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r', 'annotations' => ['migration']];
    $answered = [...$gap, 'actions' => [['claim' => 'needs a queue', 'disposition' => 'integrated', 'note' => 'plan task 4']]];

    return [
        'design writes actions onto the plan gap it answers (#104)' => ['design', [$approved, $gap], [$approved, $answered], 'design added actions to ledger entry 1 (plan gap)'],
        'a resolve step edits the annotations of its open review' => ['review-plan', [$review], [[...$review, 'annotations' => [], 'outcome' => 'continued']], 'review-plan changed annotations on ledger entry 0 (plan-approval)'],
        'a step drops an entry' => ['handoff', [$approved], [], 'handoff removed ledger entry 0 (plan-approval)'],
        'two kinds of change at once' => ['implement', [$approved, $gap], [$approved, [...$answered, 'reason' => 'edited']], 'implement added actions to and changed reason on ledger entry 1 (plan gap)'],
        'a nested edit on a closed entry' => ['handoff', [[...$approved, 'actions' => [['claim' => 'x', 'disposition' => 'integrated', 'note' => 'a']]]], [[...$approved, 'actions' => [['claim' => 'x', 'disposition' => 'integrated', 'note' => 'b']]]], 'handoff changed actions on ledger entry 0 (plan-approval)'],
    ];
});
```

Append to `DispatchCliTest.php`, after *halts the next brief when a step changed what only the engine writes*:

```php
it('halts the next brief naming the leg, the key and the entry when design writes onto the plan gap it answers (#104)', function () {
    $approved = [...boundary_open(), 'actions' => [], 'outcome' => 'continued'];
    $gap = ['gate' => 'plan-approval', 'leg' => 'implement', 'cycle' => 2, 'at' => '2026-09-25T11:00:00Z', 'reason' => 'needs a queue', 'outcome' => 'looped-back'];
    $fixture = boundary_fixture('design', 'run', ['gate_ledger' => [$approved, $gap]]);
    dispatch_leg_writes($fixture['manifest'], fn (array $m) => [
        ...$m,
        'cursor' => [...$m['cursor'], 'status' => 'continued'],
        'gate_ledger' => [$approved, [...$gap, 'actions' => [['claim' => 'needs a queue', 'disposition' => 'integrated', 'note' => 'plan task 4']]]],
    ]);

    $reason = 'design added actions to ledger entry 1 (plan gap)';
    expect(boundary_brief($fixture, 'review-plan', 'review', 'design:run', ['--status', 'continued', '--size', 'Architectural'])['json'])
        ->toBe(['action' => 'halt', 'reason' => $reason]);
    expect(manifest_read($fixture['manifest'])['cursor'])->toMatchArray(['leg' => 'design', 'status' => 'halted', 'reason' => $reason]);
});
```

In the dataset of *halts the next brief when a step changed what only the engine writes*, replace the expected reason of `'a rewritten ledger entry'`

```php
'the leg rewrote ledger entry 0'],
```

with

```php
'handoff changed outcome on ledger entry 0 (plan-approval)'],
```

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='names the leg|halts the next brief'`
Expected: FAIL: the five `ReturnedTest` cases, the new `DispatchCliTest` test and the `a rewritten ledger entry` case, each with the reason `the leg rewrote ledger entry <i>`. The other *halts the next brief* tests pass.

- [ ] **Step 3: Name the change.** In `dispatch.php`, `pipeline_ledger_problem()`, replace

```php
    $kept = ['gate', 'leg', 'cycle', 'at', 'review', 'annotations'];

    foreach ($old as $index => $entry) {
        $same = $index === $open
            ? pipeline_pick($new[$index] ?? [], $kept) === pipeline_pick($entry, $kept)
            : ($new[$index] ?? null) === $entry;
        if (! $same) {
            return "the leg rewrote ledger entry {$index}";
        }
    }
```

with

```php
    $kept = ['gate', 'leg', 'cycle', 'at', 'review', 'annotations'];

    foreach ($old as $index => $entry) {
        $change = pipeline_entry_change($entry, $new[$index] ?? null, $index === $open ? $kept : null);
        if ($change !== null) {
            return "{$leg} {$change} ledger entry {$index} (" . pipeline_entry_kind($entry) . ')';
        }
    }
```

Then, after `pipeline_pick()`, add:

```php
/**
 * What a step did to an earlier ledger entry, as the words before "ledger entry" in its halt
 * (`added actions to`, `changed outcome on`, `removed`), or null when it left the entry as it was.
 * `$only` limits the comparison to those keys: the open entry a resolve step completes. Top-level keys
 * only; both sides come normalized, so key order is not a change.
 */
function pipeline_entry_change(array $old, mixed $new, ?array $only = null): ?string
{
    if ($new === null) {
        return 'removed';
    }
    if (! is_array($new)) {
        return 'replaced';
    }
    if ($only !== null) {
        [$old, $new] = [array_intersect_key($old, array_flip($only)), array_intersect_key($new, array_flip($only))];
    }
    $changes = array_filter([
        'added' => array_keys(array_diff_key($new, $old)),
        'changed' => array_keys(array_filter(array_intersect_key($new, $old), fn ($value, $key) => $value !== $old[$key], ARRAY_FILTER_USE_BOTH)),
        'removed' => array_keys(array_diff_key($old, $new)),
    ]);
    $preposition = ['added' => 'to', 'changed' => 'on', 'removed' => 'from'];

    return $changes === [] ? null : implode(' and ', array_map(
        fn (string $verb, array $keys) => "{$verb} " . implode(', ', $keys) . " {$preposition[$verb]}",
        array_keys($changes),
        $changes,
    ));
}

/** How a halt names a ledger entry: `plan gap` for one (`pipeline_is_plan_gap()`), else its gate. */
function pipeline_entry_kind(array $entry): string
{
    return pipeline_is_plan_gap($entry) ? 'plan gap' : (string) ($entry['gate'] ?? 'no gate');
}
```

`pipeline_pick()` loses its last caller: remove it (`grep -rn "pipeline_pick" skills` must print nothing after).

- [ ] **Step 4: Run the tests**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='names the leg|halts the next brief|tolerates a leg that reorders keys|rewrites the annotations'`
Expected: PASS.

- [ ] **Step 5: engine.md, §Failure policy.** Replace

```markdown
  - **A halted manifest is the one the check rejected.** When the reason names a key the leg was not
    allowed to change, repair it from `<manifest stem>.before.json`, the snapshot taken at dispatch,
    before the next `next` or `launch`; otherwise the run resumes with the leg's change in place.
```

with

```markdown
  - **A halted manifest is the one the check rejected.** When the reason names a key the leg was not
    allowed to change, or a ledger entry it rewrote (*design added actions to ledger entry 1 (plan
    gap)*: the leg, what changed and the entry), repair it from `<manifest stem>.before.json`, the
    snapshot taken at dispatch, before the next `next` or `launch`; otherwise the run resumes with the
    leg's change in place.
```

- [ ] **Step 6: Check and run the suite**

Run: `grep -rn "the leg rewrote\|pipeline_pick" skills`
Expected: no output.

Run: `php -l skills/pipeline/checks/dispatch.php`
Expected: `No syntax errors detected`.

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, 0 failed (`ReturnedTest`'s *halts on every return it cannot account for* still finds `ledger entry 0` in `review-plan changed review on ledger entry 0 (plan-approval)`).

- [ ] **Step 7: Commit**

```bash
git add skills/pipeline/checks/dispatch.php skills/pipeline/checks/tests/ReturnedTest.php skills/pipeline/checks/tests/DispatchCliTest.php skills/pipeline/references/engine.md
git commit -m "feat(pipeline): a rewritten ledger entry halts naming the leg, the key and the entry (#104)"
```
