# `autoflow`: a resume after an escalation keeps `full` — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task by task. Steps use checkbox (`- [ ]`) syntax for tracking. In an `autoflow` implement step, execute inline, task by task, with no subagents.

**Goal:** `launch` answers `escalated`, whether the ledger records a `design-size` escalation, and `pipeline-autoflow.js` seeds its one-way flag from it, so a resume after an escalation stays on `full` and counts a further Bounded `plan-insufficient` as one uninterrupted run does (#119).

**Architecture:**
- `skills/pipeline/checks/pipeline.php` gains `pipeline_escalated(array $ledger): bool`, beside `pipeline_reset_at()`; `pipeline_start_profile()` (`agents.php`) reads its first rule through it, and `dispatch_cli_launch()` answers it as `escalated` (Task 1).
- `skills/pipeline/workflow/pipeline-autoflow.js` renames `exempted` to `escalated`, seeds it from `args.escalated`, and halts before any agent when that is not a boolean; engine.md §`autoflow` and §Design size name the resume (Task 2).

**Tech Stack:** PHP 8.4 on the host, Pest 4, Node (the script replay), Markdown skill references.

**Spec:** `docs/superpowers/specs/2026-09-30-pipeline-autoflow-resume-keeps-escalation-design.md` (its *Assumptions* 7–10 were added by the plan step).

## Global Constraints

- Suite, from the worktree root on the host: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`. `vendor/` is missing in a fresh worktree: run `composer install --no-interaction --quiet` first. `AutoflowScriptTest` needs `node` on PATH.
- TDD: every test below is written and seen failing before the code it pins.
- The script's new halt reason, verbatim: `args carry no escalated flag: re-run launch from checks that answer it`. It is checked after the agents check, so a start answer missing both halts with the agents-table reason.
- A missing or non-boolean `escalated` halts; it is never defaulted to `false` (spec *Assumptions* 2).
- `launch`'s `start` answer carries `escalated` right after `tier`, before `agents`, always a boolean (spec *Assumptions* 8).
- The script (`pipeline-autoflow.js`) keeps every model and effort out of quoted literals (`LockStepTest`).
- Unchanged: `pipeline_start_profile()`'s three rules and their order; `AgentTier`; `PIPELINE_AGENTS`; `pipeline_loop_counts()`; `pipeline_reset_at()`; `interactive` routing (`pipeline_route()`); the briefs; the ledger format; every `dispatch_cli.php` command other than `launch`'s answer; `orchestrate`.
- engine.md `## ` headings do not change (`LockStepTest` resolves the briefs' `§` names against them); §Agents per step, *Which profile*, is not edited.
- This repo has no `.changelog/` and no `CHANGELOG.md`: no changelog entry. The PR closes #119.
- The PR body carries the spec's *A run in flight when this lands*: a running workflow keeps its script; the next `launch` comes from the same checkout and answers `escalated`; an answer printed by an older `launch` and fed to the new script halts with the new reason, and re-running `launch` fixes it.

## Review Focus

1. **A start answer printed by an older `launch`** (no `escalated` key), cached by a session or `orchestrate` and fed to the new script, must halt before any agent rather than run on a guessed `false`. Task 2's halt dataset, row *no escalated flag*.
2. **A hand-edited `escalated` that is truthy but not a boolean** (`'true'`, `1`) or JSON `null` must halt, not be read as JavaScript truthiness. Task 2's halt dataset rows *a string*, *a number*, *null*.
3. **An `escalated` entry that is not the newest ledger entry** — the run escalated, then `review-plan` looped back twice, then it was stopped — still seeds the flag, so a further Bounded gap is counted. Task 2's counting test builds exactly that ledger.
4. **A resume of a run that never escalated** answers `escalated: false` and behaves exactly as today. Task 1's *hands the script its agents…* case pins the `false`; every existing `AutoflowScriptTest` replay runs on it unchanged.
5. **A resume after an escalation on a run with no word** (`tier` absent, so `full`): the profile cannot drop, but the counting half still applies. Task 2's counting test uses no `tier`.

---

## File Structure

- Modify `skills/pipeline/checks/pipeline.php` (add `pipeline_escalated()` after `pipeline_reset_at()`), `skills/pipeline/checks/agents.php` (`pipeline_start_profile()`'s first rule), `skills/pipeline/checks/dispatch_cli.php` (`dispatch_cli_launch()`'s answer), `skills/pipeline/checks/tests/DispatchCliTest.php` (three launch cases) (Task 1).
- Modify `skills/pipeline/workflow/pipeline-autoflow.js` (header comment, the seed, the halt, the rename), `skills/pipeline/checks/tests/AutoflowScriptTest.php` (three new tests), `skills/pipeline/references/engine.md` (§`autoflow`'s code block, its `launch` and script bullets, §Design size's *Once, and one way*) (Task 2).

---

### Task 1: `launch` answers `escalated`

**Files:**
- Modify: `skills/pipeline/checks/pipeline.php` (after `pipeline_reset_at()`, which ends at line 69)
- Modify: `skills/pipeline/checks/agents.php:160` (`pipeline_start_profile()`'s first `match` arm)
- Modify: `skills/pipeline/checks/dispatch_cli.php:236` (after the `'tier'` line of `dispatch_cli_launch()`'s answer)
- Test: `skills/pipeline/checks/tests/DispatchCliTest.php` (cases at lines 164, 212 and 227)

**Interfaces:**
- Consumes: `pipeline_ledger(array $manifest): array` (`manifest.php`), already loaded by `dispatch_cli.php` and `tests/Pest.php`.
- Produces:
  - `pipeline_escalated(array $ledger): bool` in `pipeline.php`.
  - `launch`'s `start` answer key `escalated` (bool), after `tier`, before `agents`. Task 2's script reads it as `args.escalated`, and `autoflow_start()` in `AutoflowScriptTest` passes it through unchanged.

- [ ] **Step 1: Write the failing tests**

In `skills/pipeline/checks/tests/DispatchCliTest.php`:

a. In *launches from the cursor with the ledger's loop-backs, the design size and ui*, the expected answer gains `escalated` between `tier` and `agents`:

```php
        'profile' => 'medium',
        'tier' => 'medium',
        'escalated' => false,
        'agents' => pipeline_agent_table([]),
    ]);
```

b. In *hands the script its agents with the manifest's override laid over them, the profile to start on and the tier*, after `expect($start['tier'])->toBe('light');`:

```php
    expect($start['escalated'])->toBeFalse();
```

c. In *starts a run on full once its ledger records an escalation, whatever the spec and the tier say*, after `expect($start['tier'])->toBe('light');`:

```php
    expect($start['escalated'])->toBeTrue();
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='DispatchCliTest'`
Expected: FAIL in the three cases above — the answer has no `escalated` key (the `toBe()` comparison differs; `$start['escalated']` is an undefined array key). Every other `DispatchCliTest` case passes.

- [ ] **Step 3: Add `pipeline_escalated()`**

In `skills/pipeline/checks/pipeline.php`, directly after `pipeline_reset_at()`:

```php
/** Whether the ledger records a `design-size` escalation: the run is on `full` from there on, a resume included (`../references/engine.md` §Agents per step). */
function pipeline_escalated(array $ledger): bool
{
    return in_array('escalated', array_column($ledger, 'outcome'), true);
}
```

- [ ] **Step 4: Read the first profile rule through it**

In `skills/pipeline/checks/agents.php`, `pipeline_start_profile()`, replace

```php
        in_array('escalated', array_column(pipeline_ledger($manifest), 'outcome'), true) => AgentTier::Full->value,
```

with

```php
        pipeline_escalated(pipeline_ledger($manifest)) => AgentTier::Full->value,
```

(`pipeline_start_profile()`'s one caller is `dispatch_cli.php`, which requires `pipeline.php` at line 24; `kickoff.php` requires `agents.php` but never calls it. No require changes — spec *Assumptions* 5.)

- [ ] **Step 5: Answer it from `launch`**

In `skills/pipeline/checks/dispatch_cli.php`, `dispatch_cli_launch()`'s `start` answer, replace

```php
        'tier' => AgentTier::fromManifest($manifest)->value,
        'agents' => pipeline_agent_table($manifest['agents'] ?? []),
```

with

```php
        'tier' => AgentTier::fromManifest($manifest)->value,
        'escalated' => pipeline_escalated(pipeline_ledger($manifest)),
        'agents' => pipeline_agent_table($manifest['agents'] ?? []),
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php -l skills/pipeline/checks/pipeline.php && php -l skills/pipeline/checks/agents.php && php -l skills/pipeline/checks/dispatch_cli.php && ./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: no syntax errors, and the whole suite PASSES — `DispatchCliTest`'s three cases, `AgentsTest`'s `pipeline_start_profile()` dataset (the refactored first rule, unchanged rows), and every `AutoflowScriptTest` replay (the script does not read `escalated` yet).

- [ ] **Step 7: Commit**

```bash
git add skills/pipeline/checks/pipeline.php skills/pipeline/checks/agents.php skills/pipeline/checks/dispatch_cli.php skills/pipeline/checks/tests/DispatchCliTest.php
git commit -m "feat(pipeline): launch answers whether the ledger records an escalation (#119)"
```

---

### Task 2: the script seeds its escalation flag from `launch`

**Files:**
- Modify: `skills/pipeline/workflow/pipeline-autoflow.js:15-18` (header comment), `:147` (the halt, after it), `:155` (the seed), `:172`, `:186`, `:188` (the rename)
- Modify: `skills/pipeline/references/engine.md:86`, `:112-113`, `:121`, `:126`, `:677`
- Test: `skills/pipeline/checks/tests/AutoflowScriptTest.php`

**Interfaces:**
- Consumes: `launch`'s `escalated` (Task 1), through `autoflow_start()` (`AutoflowScriptTest.php:20`, which calls the real `launch`); `AUTOFLOW_C`, `AUTOFLOW_PI` (lines 31–33) and `AUTOFLOW_STOP` (line 211).
- Produces: nothing later tasks use.

- [ ] **Step 1: Write the failing tests**

In `skills/pipeline/checks/tests/AutoflowScriptTest.php`:

a. Directly after *exempts one Bounded escalation and then counts on from the ledger's loop-backs* (ends at line 100):

```php
it('counts a Bounded escalation toward review-plan\'s bound on a resume after an earlier one, as one uninterrupted run does', function () {
    $escalated = ['gate' => 'design-size', 'leg' => 'review-plan', 'at' => '2026-09-24T00:00:00Z', 'reason' => 'migration', 'outcome' => 'escalated'];
    $looped = fn (int $cycle) => ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => $cycle, 'at' => "2026-09-24T0{$cycle}:00:00Z", 'review' => 'r', 'outcome' => 'looped-back'];
    $start = autoflow_start('handoff', [$escalated, $looped(1), $looped(2)], "# x — design\n\n**Design size:** Bounded\n");
    $replay = autoflow_replay($start, ['handoff:run' => [AUTOFLOW_PI]]);

    expect($start['escalated'])->toBeTrue();
    expect($start['loops']['review-plan'])->toBe(2);
    expect($replay['labels'])->toBe(['handoff:run']);
    expect($replay['result'])->toBe(['action' => 'halt', 'leg' => 'handoff', 'reason' => 'review-plan: loop-back bound exhausted']);
});
```

b. Directly after the dataset of *moves a light run to full when its design turns out Architectural, and on a Bounded escalation* (its `])` closes after the row *a Bounded escalation whose grown spec still says Bounded*):

```php
it('keeps a light run on full when it resumes after an escalation and its rerun spec step still says Bounded', function () {
    $escalated = ['gate' => 'design-size', 'leg' => 'review-plan', 'at' => '2026-09-29T10:00:00Z', 'reason' => 'migration', 'outcome' => 'escalated'];
    $start = autoflow_start('design', [$escalated], "# x — design\n\n**Design size:** Bounded\n", plan: 'plan.md', tier: 'light');
    $replay = autoflow_replay($start, [
        'design:spec' => [[...AUTOFLOW_C, 'size' => 'Bounded']],
        'review-plan:review' => [AUTOFLOW_STOP],
    ]);

    expect($start['startStep'])->toBe('spec');
    expect($start['profile'])->toBe('full');
    expect($replay['labels'])->toBe(['design:spec', 'review-plan:review']);
    expect($replay['settings'])->toBe(['opus high', 'fable high']);
    expect($replay['result'])->toBe(['action' => 'halt', 'leg' => 'review-plan', 'reason' => 'stub stop']);
});
```

(`plan: 'plan.md'` makes `launch` answer `startStep: 'spec'`, as on a real resume after an escalation — spec *Assumptions* 7.)

c. Directly after the dataset of *halts before any agent when launch's agents, profile or tier are missing or incomplete* (the last test in the file's halt group):

```php
it('halts before any agent when launch\'s answer carries no boolean escalated flag', function (callable $break) {
    $replay = autoflow_replay($break(autoflow_start('handoff')), []);

    expect($replay)->toBe(['labels' => [], 'prompts' => [], 'settings' => [], 'result' => ['action' => 'halt', 'leg' => 'handoff', 'reason' => 'args carry no escalated flag: re-run launch from checks that answer it']]);
})->with([
    'no escalated flag' => [function (array $start) { unset($start['escalated']); return $start; }],
    'a string' => [function (array $start) { $start['escalated'] = 'true'; return $start; }],
    'a number' => [function (array $start) { $start['escalated'] = 1; return $start; }],
    'null' => [function (array $start) { $start['escalated'] = null; return $start; }],
]);
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='AutoflowScriptTest'`
Expected: FAIL in exactly the new cases:
- *counts a Bounded escalation…*: labels `['handoff:run', 'design:spec']` — the gap is exempt again and the run goes to `design`, whose unscripted agent halts (`the agent failed: no return scripted for design:spec`).
- *keeps a light run on full…*: settings `['opus high', 'opus medium']` — the Bounded return drops the profile to `light`, whose `review-plan:review` is opus medium.
- *halts before any agent… no boolean escalated flag*, all four rows: labels `['handoff:run']` and the halt `the agent failed: no return scripted for handoff:run` — the script runs an agent.
Every existing case passes.

- [ ] **Step 3: Seed, check and rename the flag in the script**

In `skills/pipeline/workflow/pipeline-autoflow.js`:

a. The header comment, replace

```js
// the functions interactive mode uses. So are the agents: `agents` (pipeline_agent_table()), the
// profile the run starts on (pipeline_start_profile()) and `tier`, the tier its invocation named, so
// the script names no model or effort, and no tier but `full` (engine.md §Agents per step).
```

with

```js
// the functions interactive mode uses. So are the agents: `agents` (pipeline_agent_table()), the
// profile the run starts on (pipeline_start_profile()) and `tier`, the tier its invocation named, so
// the script names no model or effort, and no tier but `full` (engine.md §Agents per step). And so is
// `escalated`, whether the ledger records an escalation (pipeline_escalated()): the script seeds its
// own from it, so a resume keeps `full` and the one exemption as a run does.
```

b. After the agents check (line 147, `if (!completeAgents(…)) return halt(…)`), add:

```js
if (typeof args.escalated !== 'boolean') return halt(args.startLeg, 'args carry no escalated flag: re-run launch from checks that answer it')
```

c. Replace `let exempted = false` with:

```js
let escalated = args.escalated
```

d. Replace

```js
      profile = size === 'Bounded' && !exempted ? tier : 'full' // up, never down: escalation is one way, in the run as on a resume
```

with

```js
      profile = size === 'Bounded' && !escalated ? tier : 'full' // up, never down: escalation is one way, in the run as on a resume
```

e. Replace

```js
  const counted = !(gap && size === 'Bounded' && !exempted) // a Bounded escalation is not a loop-back; escalation is one-way, so once per run
  if (!counted) {
    exempted = true
```

with

```js
  const counted = !(gap && size === 'Bounded' && !escalated) // a Bounded escalation is not a loop-back; escalation is one-way, so once per run
  if (!counted) {
    escalated = true
```

Afterwards `grep -n exempted skills/pipeline/workflow/pipeline-autoflow.js` prints nothing.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='AutoflowScriptTest|LockStepTest'`
Expected: PASS, the three new tests and every existing replay included; `LockStepTest`'s *keeps every model and effort out of the autoflow script* still passes. (The script is checked by the replay, not by `node --check`: its top-level `return` only parses inside the Workflow runtime's function body.)

- [ ] **Step 5: Name the resume in engine.md**

In `skills/pipeline/references/engine.md`:

a. §`autoflow`'s code block (line 86), replace

```
# → {"action":"start","startLeg":…,"startStep":…,"loops":{…},"ui":…,"size":…,"manifest":…,"worktree":…,"noOpen":…,"checks":…,"tables":{…},"profile":…,"tier":…,"agents":{…}}
```

with

```
# → {"action":"start","startLeg":…,"startStep":…,"loops":{…},"ui":…,"size":…,"manifest":…,"worktree":…,"noOpen":…,"checks":…,"tables":{…},"profile":…,"tier":…,"escalated":…,"agents":{…}}
```

b. The `launch` bullet (lines 112–113), replace

```
  `agents`, `profile` and `tier` are the step agents' models and efforts, the profile the run starts
  on and the tier its invocation named (§Agents per step); an invalid `agents` override in the
```

with

```
  `agents`, `profile` and `tier` are the step agents' models and efforts, the profile the run starts
  on and the tier its invocation named (§Agents per step); `escalated` is whether the ledger records
  an escalation (`pipeline_escalated()`), from which the script seeds its own, so a resume keeps
  `full` and the one exemption as a run does; an invalid `agents` override in the
```

c. The script bullet (line 121), replace

```
  size), so the script exempts one per run; every other `plan-insufficient` counts toward
```

with

```
  size), so the script exempts one per run, a resume included (`escalated`); every other `plan-insufficient` counts toward
```

d. The script bullet (line 126), replace

```
  and `agents`, `profile` or `tier` missing or incomplete halts before any agent; a review step that returns
```

with

```
  and `agents`, `profile` or `tier` missing or incomplete halts before any agent, and so does an
  `escalated` that is not a boolean; a review step that returns
```

e. §Design size, *Once, and one way* (line 677), replace

```
exempts one per run and counts the rest toward `review-plan`'s bound.
```

with

```
exempts one per run, a resume included, and counts the rest toward `review-plan`'s bound.
```

§Agents per step, *Which profile*, is not edited: after Step 3 its sentence ("escalation is one way, in the run as on a resume") holds as written.

- [ ] **Step 6: Run the whole suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests && grep -n "escalated" skills/pipeline/references/engine.md | sed -n 1,20p`
Expected: the suite PASSES, `LockStepTest`'s *keeps every engine.md section a brief names* included; `grep` shows the four new `escalated` mentions at §`autoflow` (code block, `launch` bullet, both script-bullet lines) beside the existing ones.

- [ ] **Step 7: Commit**

```bash
git add skills/pipeline/workflow/pipeline-autoflow.js skills/pipeline/checks/tests/AutoflowScriptTest.php skills/pipeline/references/engine.md
git commit -m "feat(pipeline): a resume after an escalation keeps full and the one exemption (#119)"
```

---

## Spec coverage

| Spec item | Task |
|---|---|
| `pipeline_escalated()` in `pipeline.php`; `pipeline_start_profile()`'s first rule through it | 1 (Steps 3–4) |
| `launch`'s answer carries `escalated`, always a boolean | 1 (Step 5; test 4 → Step 1) |
| Script: rename, seed from `args.escalated`, halt without a boolean | 2 (Step 3; tests 1–3 → Step 1) |
| engine.md: `launch` bullet, script bullet (twice), *Once, and one way*; *Which profile* unchanged | 2 (Step 5) |
| Assumptions 7–10 (fixture start step, key order, code block and header comment, halt rows) | 2 (Step 1b, 1c, 3a, 5a); 1 (Step 1a) |
| Done when: the suite passes | 2 (Step 6) |
