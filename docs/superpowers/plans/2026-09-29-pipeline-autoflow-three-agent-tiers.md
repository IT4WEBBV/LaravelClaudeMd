# `autoflow` picks one of three agent tiers — `full`, `medium`, `light` — from an explicit word — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task by task. Steps use checkbox (`- [ ]`) syntax for tracking. In an `autoflow` implement step, execute inline, task by task, with no subagents.

**Goal:** `PIPELINE_AGENTS` holds three tiers (`full`, `medium` = today's `light`, and a new cheaper `light`); the invocation word `medium` or `light` (kickoff `--medium` / `--light`, the manifest's `tier`) names the tier and permits a Bounded design; the design size moves a run up to `full`, never down; a legacy `light: true` reads as `medium` (#116).

**Architecture:**
- `skills/pipeline/checks/agents.php`: a new `AgentTier` enum holds the word's behaviour — `fromManifest()`, `permitsBounded()`, `forDesign()` (Task 1).
- `agents.php` again: the three tier tables, `pipeline_agent_table()` over every tier, `pipeline_start_profile()` on the tier; `DesignSize::profile()` removed; `launch` answers `tier`; `pipeline-autoflow.js` checks `full` and the run's tier and switches to the tier after a Bounded design; engine.md §Agents per step and §`autoflow` rewritten, pinned by `LockStepTest` (Task 2).
- The word: kickoff's `--medium` / `--light` write `tier`; the design brief's size line reads the tier; usage strings, engine.md §Kickoff / §Design size / the leg table, gates.md, manifest.md and SKILL.md (Task 3).

**Tech Stack:** PHP 8.4 on the host, Pest 4, Node (the script replay), Markdown skill references.

**Spec:** `docs/superpowers/specs/2026-09-29-pipeline-autoflow-three-agent-tiers-design.md` (its *Assumptions* 12–16 were added by the plan step).

## Global Constraints

- Suite, from the worktree root on the host: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`. `vendor/` is missing in a fresh worktree: run `composer install --no-interaction --quiet` first. `AutoflowScriptTest` needs `node` on PATH.
- The tier tables, verbatim from the spec (`model effort`):

  | Step | `full` | `medium` | `light` |
  |---|---|---|---|
  | `design:spec` | opus high | opus medium | opus medium |
  | `design:plan` | opus high | opus medium | opus medium |
  | `review-plan:review` | fable high | fable medium | opus medium |
  | `review-plan:resolve` | opus high | opus medium | sonnet medium |
  | `handoff:run` | sonnet low | sonnet low | sonnet low |
  | `implement:run` | opus high | opus high | sonnet high |
  | `verify-ui:run` | sonnet high | sonnet medium | sonnet medium |
  | `review-pr:review` | fable high | fable high | opus high |
  | `review-pr:resolve` | opus high | opus medium | sonnet high |

  `loopedBack` (`implement:run` opus xhigh), `retry` (opus xhigh) and `smoke` (sonnet low) do not change.
- The script (`pipeline-autoflow.js`) contains no model or effort as a quoted literal (`LockStepTest`), so never `'medium'`; after this change the only tier it names is `'full'`, and it reads the run's tier from `args.tier`.
- The agents-table halt, verbatim and unchanged: `args carry no complete agents table: re-run launch from checks that have pipeline_agent_table()`.
- The design brief's size lines, verbatim: `- design size: the Bounded path is permitted (`medium`)`, `- design size: the Bounded path is permitted (`light`)`, `- design size: the Architectural path is required (no `medium` or `light`)`.
- `launch`'s `start` answer carries `tier` right after `profile`, before `agents` (spec *Assumptions* 14).
- Routing, statuses, the bound, the ledger, `pipeline_leg_writable_keys()`, `interactive`, `loopedBack` / `retry` / `smoke`, the override's shape and validation, and `run_cost.php` do not change.
- engine.md `## ` headings do not change: `LockStepTest` resolves the briefs' `§` names against them.
- This repo has no `.changelog/` and no `CHANGELOG.md`: no changelog entry. The PR closes #116.
- The PR body carries the spec's *A run in flight when this lands*: a running workflow keeps its script and args; a relaunch of a `light: true` manifest starts on `medium`, today's `light` values; the new script over an old cached `launch` answer has no `tier` and halts before any agent with the agents-table reason.

## Review Focus

1. **A hand-edited `tier` that is not `medium` or `light`** (`true`, `["light"]`, `"heavy"`, `"loopedBack"`, `"full"`) must not fatal `launch` with a `TypeError`; `launch` halts naming the value (spec *Assumptions* 4, owner decision after the plan review). `DispatchCliTest`'s tier halt dataset pins it; behind the halt, Task 1's `fromManifest()` dataset still reads it as `full`.
2. **An unknown `tier` beside a legacy `light: true`** halts too, rather than reading as `medium`: a present `tier` decides alone (spec *Assumptions* 12). The halt dataset carries `light: true` in every case.
3. **A manifest in flight with `light: true` and a Bounded spec, relaunched after this lands**, starts on `medium` and answers `tier: medium`, so its agents do not change. Task 2's legacy launch case pins it.
4. **A `launch` answer whose `tier` names a non-tier entry** (`retry`) halts the script before any agent instead of reading one entry as a table. Task 2's halt dataset pins it.
5. **`--light` before the item on the kickoff command line**, an order a session may build, parses the same as after it. Task 3's kickoff dataset pins it.

---

## File Structure

- Modify `skills/pipeline/checks/agents.php` and `skills/pipeline/checks/tests/AgentsTest.php` (Tasks 1 and 2).
- Modify `skills/pipeline/checks/design_size.php`, `tests/DesignSizeTest.php`, `skills/pipeline/checks/dispatch_cli.php` (`launch`), `tests/DispatchCliTest.php` (launch cases), `skills/pipeline/workflow/pipeline-autoflow.js`, `tests/AutoflowScriptTest.php`, `tests/LockStepTest.php`, `skills/pipeline/references/engine.md` (§`autoflow`, §Agents per step) (Task 2).
- Modify `skills/pipeline/checks/dispatch_cli.php` (kickoff args, usage), `skills/pipeline/checks/kickoff.php`, `skills/pipeline/checks/brief.php`, `tests/DispatchCliTest.php` (kickoff cases), `tests/BriefTest.php`, `skills/pipeline/references/engine.md` (§`autoflow`'s code block, §Kickoff, the leg table, §Design size), `skills/pipeline/references/gates.md`, `skills/pipeline/references/manifest.md`, `skills/pipeline/SKILL.md` (Task 3).

---

### Task 1: `AgentTier` — the word as an enum

**Files:**
- Modify: `skills/pipeline/checks/agents.php` (add the enum after `PIPELINE_AGENT_EFFORTS`)
- Test: `skills/pipeline/checks/tests/AgentsTest.php`

**Interfaces:**
- Consumes: `DesignSize` (`design_size.php`, loaded before `agents.php` by `dispatch_cli.php` and `tests/Pest.php`).
- Produces:
  - `enum AgentTier: string { case Full = 'full'; case Medium = 'medium'; case Light = 'light'; }`
  - `AgentTier::fromManifest(array $manifest): AgentTier`
  - `AgentTier::permitsBounded(): bool`
  - `AgentTier::forDesign(DesignSize $size): AgentTier`

- [ ] **Step 1: Write the failing tests**

Append to `skills/pipeline/checks/tests/AgentsTest.php`:

```php
it('reads the tier the invocation named from the manifest, and the heavier side from anything else', function (array $manifest, AgentTier $tier) {
    expect(AgentTier::fromManifest($manifest))->toBe($tier);
})->with([
    'no word' => [[], AgentTier::Full],
    'tier: medium' => [['tier' => 'medium'], AgentTier::Medium],
    'tier: light' => [['tier' => 'light'], AgentTier::Light],
    'tier: full, written by hand' => [['tier' => 'full'], AgentTier::Full],
    'a legacy light: true' => [['light' => true], AgentTier::Medium],
    'tier beside a legacy light: true' => [['tier' => 'light', 'light' => true], AgentTier::Light],
    'an unknown tier' => [['tier' => 'heavy'], AgentTier::Full],
    'a tier naming another table entry' => [['tier' => 'loopedBack'], AgentTier::Full],
    'a tier that is not a string' => [['tier' => true], AgentTier::Full],
    'a tier that is a list' => [['tier' => ['light']], AgentTier::Full],
    'an unknown tier beside a legacy light: true' => [['tier' => 'heavy', 'light' => true], AgentTier::Full],
]);

it('permits a Bounded design on medium and light, not on full', function () {
    expect(AgentTier::Full->permitsBounded())->toBeFalse();
    expect(AgentTier::Medium->permitsBounded())->toBeTrue();
    expect(AgentTier::Light->permitsBounded())->toBeTrue();
});

it('keeps a Bounded design on the named tier and moves an Architectural one up to full', function (AgentTier $tier) {
    expect($tier->forDesign(DesignSize::Bounded))->toBe($tier);
    expect($tier->forDesign(DesignSize::Architectural))->toBe(AgentTier::Full);
})->with(AgentTier::cases());
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='AgentsTest'`
Expected: FAIL — `Class "AgentTier" not found`.

- [ ] **Step 3: Write the enum**

In `skills/pipeline/checks/agents.php`, after `const PIPELINE_AGENT_EFFORTS = [...];`, add:

```php
/**
 * The agents tier an `autoflow` run's invocation named (`../references/engine.md` §Agents per step):
 * `medium` or `light`, and `full` with no word. Its values are `PIPELINE_AGENTS`' tier keys.
 */
enum AgentTier: string
{
    case Full = 'full';
    case Medium = 'medium';
    case Light = 'light';

    /** The manifest's `tier`; else `medium` for a legacy `light: true`; else `full`. A `tier` that is not one of the three reads as `full`, the heavier side. */
    public static function fromManifest(array $manifest): self
    {
        if (array_key_exists('tier', $manifest)) {
            $tier = $manifest['tier'];

            return (is_string($tier) ? self::tryFrom($tier) : null) ?? self::Full;
        }

        return empty($manifest['light']) ? self::Full : self::Medium;
    }

    /** Whether the word permits a Bounded design (`../references/engine.md` §Design size). */
    public function permitsBounded(): bool
    {
        return $this !== self::Full;
    }

    /** The tier a design of this size runs on: up, never down. */
    public function forDesign(DesignSize $size): self
    {
        return $size === DesignSize::Bounded ? $this : self::Full;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php -l skills/pipeline/checks/agents.php && ./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='AgentsTest'`
Expected: PASS, the existing `AgentsTest` cases included (nothing uses the enum yet).

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/agents.php skills/pipeline/checks/tests/AgentsTest.php
git commit -m "feat(pipeline): AgentTier names the agents tier an autoflow invocation picked (#116)"
```

---

### Task 2: three tier tables, the start profile on the tier, `tier` in `launch`'s answer, the script, and §Agents per step

One task: the table's keys, `launch`'s answer, the script's check and the engine.md table change together, and the suite is red between any two of them.

**Files:**
- Modify: `skills/pipeline/checks/agents.php` (`PIPELINE_AGENTS`, `pipeline_agent_table()`, `pipeline_start_profile()`), `skills/pipeline/checks/design_size.php` (remove `profile()`), `skills/pipeline/checks/dispatch_cli.php` (`launch`'s answer, around line 224), `skills/pipeline/workflow/pipeline-autoflow.js`, `skills/pipeline/references/engine.md` (§`autoflow`, §Agents per step)
- Test: `tests/AgentsTest.php`, `tests/DesignSizeTest.php`, `tests/DispatchCliTest.php`, `tests/AutoflowScriptTest.php`, `tests/LockStepTest.php` (all under `skills/pipeline/checks/`)

**Interfaces:**
- Consumes: `AgentTier` and its three methods (Task 1); `pipeline_ledger()` (`manifest.php`); `dispatch_cli_design_size(array $manifest): DesignSize` (`dispatch_cli.php`).
- Produces:
  - `PIPELINE_AGENTS` keys, in order: `full`, `medium`, `light`, `loopedBack`, `retry`, `smoke`.
  - `pipeline_agent_table(array $override): array` — same signature; the override now lands in every tier and `loopedBack`.
  - `pipeline_start_profile(array $manifest, DesignSize $size): string` — `'full'`, `'medium'` or `'light'`.
  - `launch`'s `start` answer: `..., 'profile' => string, 'tier' => string, 'agents' => array`.
  - Script: `completeAgents(agents, profile, tier, steps)`; `args.tier`.
  - `DesignSize::profile()` no longer exists.

- [ ] **Step 1: Write the failing PHP tests**

`skills/pipeline/checks/tests/AgentsTest.php` — replace the first test (*holds an explicit model and effort for every autoflow step in both profiles*) with:

```php
it('holds an explicit model and effort for every autoflow step in every tier', function () {
    $table = pipeline_agent_table([]);

    expect(array_keys($table))->toBe(['full', 'medium', 'light', 'loopedBack', 'retry', 'smoke']);
    expect(agents_settings($table['full']))->toBe([
        'design:spec' => 'opus high',
        'design:plan' => 'opus high',
        'review-plan:review' => 'fable high',
        'review-plan:resolve' => 'opus high',
        'handoff:run' => 'sonnet low',
        'implement:run' => 'opus high',
        'verify-ui:run' => 'sonnet high',
        'review-pr:review' => 'fable high',
        'review-pr:resolve' => 'opus high',
    ]);
    expect(agents_settings($table['medium']))->toBe([
        'design:spec' => 'opus medium',
        'design:plan' => 'opus medium',
        'review-plan:review' => 'fable medium',
        'review-plan:resolve' => 'opus medium',
        'handoff:run' => 'sonnet low',
        'implement:run' => 'opus high',
        'verify-ui:run' => 'sonnet medium',
        'review-pr:review' => 'fable high',
        'review-pr:resolve' => 'opus medium',
    ]);
    expect(agents_settings($table['light']))->toBe([
        'design:spec' => 'opus medium',
        'design:plan' => 'opus medium',
        'review-plan:review' => 'opus medium',
        'review-plan:resolve' => 'sonnet medium',
        'handoff:run' => 'sonnet low',
        'implement:run' => 'sonnet high',
        'verify-ui:run' => 'sonnet medium',
        'review-pr:review' => 'opus high',
        'review-pr:resolve' => 'sonnet high',
    ]);
    expect(agents_settings($table['loopedBack']))->toBe(['implement:run' => 'opus xhigh']);
    expect($table['retry'])->toBe(['model' => 'opus', 'effort' => 'xhigh']);
    expect($table['smoke'])->toBe(['model' => 'sonnet', 'effort' => 'low']);
    foreach (AgentTier::cases() as $tier) {
        expect(array_keys($table[$tier->value]))->toBe(pipeline_agent_steps());
    }
});
```

Replace the override test (*lays an override over the step it names in both profiles and its loop-back entry, and leaves the rest*) with:

```php
it('lays an override over the step it names in every tier and its loop-back entry, and leaves the rest', function () {
    $override = ['implement:run' => ['model' => 'sonnet', 'effort' => 'max'], 'review-plan:review' => ['effort' => 'low']];
    $table = pipeline_agent_table($override);
    $default = pipeline_agent_table([]);
    $others = fn (array $profile) => array_diff_key($profile, $override);

    foreach (['full', 'medium', 'light', 'loopedBack'] as $profile) {
        expect($table[$profile]['implement:run'])->toBe(['model' => 'sonnet', 'effort' => 'max']);
    }
    expect($table['full']['review-plan:review'])->toBe(['model' => 'fable', 'effort' => 'low']);
    expect($table['medium']['review-plan:review'])->toBe(['model' => 'fable', 'effort' => 'low']);
    expect($table['light']['review-plan:review'])->toBe(['model' => 'opus', 'effort' => 'low']);
    foreach (['full', 'medium', 'light'] as $tier) {
        expect($others($table[$tier]))->toBe($others($default[$tier]));
    }
    expect(array_keys($table['loopedBack']))->toBe(['implement:run']);
    expect($table['retry'])->toBe($default['retry']);
    expect($table['smoke'])->toBe($default['smoke']);
});
```

Replace the start-profile test (*starts a run on full after an escalation, on the spec's size once a spec exists, and on the light flag before*) with:

```php
it('starts a run on full after an escalation, on the named tier moved up by the spec\'s size once a spec exists, and on the named tier before', function (array $manifest, DesignSize $size, string $profile) {
    expect(pipeline_start_profile($manifest, $size))->toBe($profile);
})->with(function () {
    $spec = ['artifacts' => ['spec' => 'spec.md']];
    $escalated = ['gate_ledger' => [['gate' => 'design-size', 'leg' => 'handoff', 'at' => '2026-09-29T10:00:00Z', 'reason' => 'migration', 'outcome' => 'escalated']]];

    return [
        'no spec, no word' => [[], DesignSize::Architectural, 'full'],
        'no spec, medium' => [['tier' => 'medium'], DesignSize::Architectural, 'medium'],
        'no spec, light' => [['tier' => 'light'], DesignSize::Architectural, 'light'],
        'no spec, a legacy light flag' => [['light' => true], DesignSize::Architectural, 'medium'],
        'a Bounded spec with no word' => [$spec, DesignSize::Bounded, 'full'],
        'a Bounded spec with medium' => [[...$spec, 'tier' => 'medium'], DesignSize::Bounded, 'medium'],
        'a Bounded spec with light' => [[...$spec, 'tier' => 'light'], DesignSize::Bounded, 'light'],
        'a Bounded spec with a legacy light flag' => [[...$spec, 'light' => true], DesignSize::Bounded, 'medium'],
        'an Architectural spec with light' => [[...$spec, 'tier' => 'light'], DesignSize::Architectural, 'full'],
        'an escalation over a Bounded spec with light' => [[...$spec, ...$escalated, 'tier' => 'light'], DesignSize::Bounded, 'full'],
        'an escalation before a spec, with light' => [[...$escalated, 'tier' => 'light'], DesignSize::Architectural, 'full'],
    ];
});
```

`skills/pipeline/checks/tests/DesignSizeTest.php` — delete the whole test *names the agents profile a size runs on* (lines 33–36).

`skills/pipeline/checks/tests/DispatchCliTest.php`:

1. In *launches from the cursor with the ledger's loop-backs, the design size and ui*, add `'tier' => 'medium'` to the `dispatch_fixture([...])` array (after `'mode' => 'autoflow'`), and in the expected answer replace `'profile' => 'light',` with:

   ```php
           'profile' => 'medium',
           'tier' => 'medium',
   ```

2. Replace *hands the script its agents with the manifest's override laid over them, and the profile to start on* with:

   ```php
   it('hands the script its agents with the manifest\'s override laid over them, the profile to start on and the tier', function () {
       $override = ['review-plan:review' => ['model' => 'opus', 'effort' => 'xhigh']];
       $fixture = dispatch_fixture(['mode' => 'autoflow', 'tier' => 'light', 'agents' => $override]);
       $start = dispatch_cli(['launch', $fixture['manifest'], $fixture['diff']])['json'];

       expect($start['agents'])->toBe(pipeline_agent_table($override));
       expect($start['agents']['full']['review-plan:review'])->toBe(['model' => 'opus', 'effort' => 'xhigh']);
       expect($start['agents']['light']['review-plan:review'])->toBe(['model' => 'opus', 'effort' => 'xhigh']);
       expect($start['profile'])->toBe('light');
       expect($start['tier'])->toBe('light');
   });
   ```

3. In *starts a run on full once its ledger records an escalation, whatever the spec and the light flag say*: rename it to *…, whatever the spec and the tier say*, replace `'light' => true` in its fixture with `'tier' => 'light'`, and add after `expect($start['profile'])->toBe('full');`:

   ```php
       expect($start['tier'])->toBe('light');
   ```

4. Add after that test:

   ```php
   it('starts a legacy light: true manifest with a Bounded spec on medium, today\'s light agents, and names medium as its tier', function () {
       $fixture = dispatch_fixture(['mode' => 'autoflow', 'light' => true, 'artifacts' => ['spec' => 'spec.md', 'plan' => null, 'pr' => null, 'issue' => null]]);
       file_put_contents($fixture['dir'] . '/spec.md', "# x — design\n\n**Design size:** Bounded\n");

       $start = dispatch_cli(['launch', $fixture['manifest'], $fixture['diff']])['json'];

       expect($start['profile'])->toBe('medium');
       expect($start['tier'])->toBe('medium');
   });
   ```

`skills/pipeline/checks/tests/LockStepTest.php` — replace *keeps engine.md's agents table in lock-step with pipeline_agent_table()* with:

```php
it('keeps engine.md\'s agents table in lock-step with pipeline_agent_table()', function () {
    $section = lockstep_section('engine.md', 'Agents per step');
    $table = pipeline_agent_table([]);
    $cell = fn (array $entry) => "{$entry['model']} {$entry['effort']}";
    $same = fn (array $entry) => implode(' | ', array_fill(0, count(AgentTier::cases()), $cell($entry)));
    $rows = [];
    foreach (pipeline_agent_steps() as $step) {
        $rows[] = "| `{$step}` | " . implode(' | ', array_map(fn (AgentTier $tier) => $cell($table[$tier->value][$step]), AgentTier::cases())) . ' |';
    }
    foreach ($table['loopedBack'] as $step => $entry) {
        $rows[] = "| `{$step}` after a loop-back | {$same($entry)} |";
    }
    $rows[] = "| a review that returned nothing, once | {$same($table['retry'])} |";
    $rows[] = "| a smoke run's stub step | {$same($table['smoke'])} |";

    expect($section)->toContain('| Step | `full` | `medium` | `light` | Why |');
    expect($rows)->toHaveCount(12);
    foreach ($rows as $row) {
        expect($section)->toContain($row);
    }
});
```

- [ ] **Step 2: Write the failing script tests**

`skills/pipeline/checks/tests/AutoflowScriptTest.php`:

1. Replace `autoflow_start()` and its docblock with:

   ```php
   /** `launch`'s start answer for an autoflow run whose cursor is on `$leg`; `$spec` is the committed spec's text, `$plan` the plan's recorded path, `$tier` the manifest's `tier`. */
   function autoflow_start(string $leg, array $ledger = [], ?string $spec = null, ?string $plan = null, ?string $tier = null): array
   {
       $artifacts = ['spec' => $spec === null ? null : 'spec.md', 'plan' => $plan, 'pr' => null, 'issue' => null];
       $fixture = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => $leg, 'status' => 'pending'], 'gate_ledger' => $ledger, 'artifacts' => $artifacts, ...($tier === null ? [] : ['tier' => $tier])]);
       if ($spec !== null) {
           file_put_contents($fixture['dir'] . '/spec.md', $spec);
       }

       return dispatch_cli(['launch', $fixture['manifest'], $fixture['diff']])['json'];
   }
   ```

2. Rename *runs every step on its full entry without light, and implement on xhigh once verify-ui or review-pr looped back* to *runs every step on its full entry without a word, and implement on xhigh once verify-ui or review-pr looped back*; its body stays.

3. Replace *runs a light run's Bounded design and every step after it on light, where implement and review-pr's review keep full's setting* with these two tests and the no-word test:

   ```php
   it('runs a medium run\'s Bounded design and every step after it on medium, where implement and review-pr\'s review keep full\'s setting', function () {
       $replay = autoflow_replay(autoflow_start('design', tier: 'medium'), [
           'design:spec' => [[...AUTOFLOW_C, 'size' => 'Bounded']],
           'review-plan:review' => [AUTOFLOW_C], 'review-plan:resolve' => [AUTOFLOW_C],
           'handoff:run' => [AUTOFLOW_C],
           'implement:run' => [[...AUTOFLOW_C, 'ui' => true]],
           'verify-ui:run' => [AUTOFLOW_C],
           'review-pr:review' => [AUTOFLOW_C], 'review-pr:resolve' => [AUTOFLOW_C],
       ]);

       expect($replay['labels'])->toBe(['design:spec', 'review-plan:review', 'review-plan:resolve', 'handoff:run', 'implement:run', 'verify-ui:run', 'review-pr:review', 'review-pr:resolve']);
       expect($replay['settings'])->toBe(['opus medium', 'fable medium', 'opus medium', 'sonnet low', 'opus high', 'sonnet medium', 'fable high', 'opus medium']);
       expect($replay['result'])->toBe(['action' => 'done']);
   });

   it('runs a light run\'s Bounded design and every step after it on light, and implement on xhigh after a review-pr loop-back', function () {
       $replay = autoflow_replay(autoflow_start('design', tier: 'light'), [
           'design:spec' => [[...AUTOFLOW_C, 'size' => 'Bounded']],
           'review-plan:review' => [AUTOFLOW_C], 'review-plan:resolve' => [AUTOFLOW_C],
           'handoff:run' => [AUTOFLOW_C],
           'implement:run' => [[...AUTOFLOW_C, 'ui' => true], [...AUTOFLOW_C, 'ui' => false]],
           'verify-ui:run' => [AUTOFLOW_C],
           'review-pr:review' => [AUTOFLOW_C, AUTOFLOW_C], 'review-pr:resolve' => [AUTOFLOW_LB, AUTOFLOW_C],
       ]);

       expect($replay['labels'])->toBe([
           'design:spec', 'review-plan:review', 'review-plan:resolve', 'handoff:run', 'implement:run', 'verify-ui:run',
           'review-pr:review', 'review-pr:resolve', 'implement:run', 'review-pr:review', 'review-pr:resolve',
       ]);
       expect($replay['settings'])->toBe([
           'opus medium', 'opus medium', 'sonnet medium', 'sonnet low', 'sonnet high', 'sonnet medium',
           'opus high', 'sonnet high', 'opus xhigh', 'opus high', 'sonnet high',
       ]);
       expect($replay['result'])->toBe(['action' => 'done']);
   });

   it('keeps a run with no word on full when its spec step returns Bounded', function () {
       $replay = autoflow_replay(autoflow_start('design'), [
           'design:spec' => [[...AUTOFLOW_C, 'size' => 'Bounded']],
           'review-plan:review' => [AUTOFLOW_STOP],
       ]);

       expect($replay['labels'])->toBe(['design:spec', 'review-plan:review']);
       expect($replay['settings'])->toBe(['opus high', 'fable high']);
   });
   ```

   `AUTOFLOW_STOP` is defined further up the file (above *runs a Bounded design as its spec step alone*), so these tests may use it.

4. In *moves a light run to full when its design turns out Architectural, and on a Bounded escalation*: replace `light: true` with `tier: 'light'` in the `autoflow_start()` call, and in the dataset replace the two escalation cases' first setting `'fable medium'` with `'opus medium'` (the new `light` tier's `review-plan:review`):

   ```php
       'a Bounded escalation' => ['review-plan', "# x — design\n\n**Design size:** Bounded\n", [
           'review-plan:review' => [AUTOFLOW_PI, AUTOFLOW_STOP],
           'design:spec' => [[...AUTOFLOW_C, 'size' => 'Architectural']],
           'design:plan' => [[...AUTOFLOW_C, 'size' => 'Architectural']],
       ], ['review-plan:review', 'design:spec', 'design:plan', 'review-plan:review'], ['opus medium', 'opus high', 'opus high', 'fable high']],
       'a Bounded escalation whose grown spec still says Bounded' => ['review-plan', "# x — design\n\n**Design size:** Bounded\n", [
           'review-plan:review' => [AUTOFLOW_PI, AUTOFLOW_STOP],
           'design:spec' => [[...AUTOFLOW_C, 'size' => 'Bounded']],
       ], ['review-plan:review', 'design:spec', 'review-plan:review'], ['opus medium', 'opus high', 'fable high']],
   ```

   The first case (*a spec step that writes an Architectural design*) keeps `['opus medium', 'opus high', 'fable high']`.

5. Replace *halts before any agent when launch's agents or profile are missing or incomplete* with:

   ```php
   it('halts before any agent when launch\'s agents, profile or tier are missing or incomplete', function (callable $break) {
       $replay = autoflow_replay($break(autoflow_start('handoff')), []);

       expect($replay['labels'])->toBe([]);
       expect($replay['result'])->toBe(['action' => 'halt', 'leg' => 'handoff', 'reason' => 'args carry no complete agents table: re-run launch from checks that have pipeline_agent_table()']);
   })->with([
       'no agents' => [function (array $start) { unset($start['agents']); return $start; }],
       'a step missing from the tier\'s table' => [function (array $start) { $start['tier'] = 'medium'; unset($start['agents']['medium']['design:plan']); return $start; }],
       'an entry without an effort' => [function (array $start) { unset($start['agents']['full']['handoff:run']['effort']); return $start; }],
       'an empty model' => [function (array $start) { $start['agents']['full']['implement:run']['model'] = ''; return $start; }],
       'no retry entry' => [function (array $start) { unset($start['agents']['retry']); return $start; }],
       'no smoke entry' => [function (array $start) { unset($start['agents']['smoke']); return $start; }],
       'a loop-back entry for a step the run does not have' => [function (array $start) { $start['agents']['loopedBack']['design:run'] = ['model' => 'opus', 'effort' => 'high']; return $start; }],
       'no profile' => [function (array $start) { unset($start['profile']); return $start; }],
       'no tier' => [function (array $start) { unset($start['tier']); return $start; }],
       'a tier that is not a table' => [function (array $start) { $start['tier'] = 'heavy'; return $start; }],
       'a tier that names the retry entry' => [function (array $start) { $start['tier'] = 'retry'; return $start; }],
       'a profile that is neither full nor the tier' => [function (array $start) { $start['tier'] = 'medium'; $start['profile'] = 'light'; return $start; }],
   ]);
   ```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='AgentsTest|DesignSizeTest|DispatchCliTest|AutoflowScriptTest|LockStepTest'`
Expected: FAIL in the cases Steps 1 and 2 added or changed — `AgentsTest` (no `medium` key; the start profile ignores `tier`), `DispatchCliTest`'s launch cases (no `tier` in the answer), `AutoflowScriptTest`'s tier cases (launch ignores `tier`, so the runs keep `full` or today's `light` settings, and the new halt cases run agents), `LockStepTest`'s table case (no four-column header). The cases this task does not touch pass.

- [ ] **Step 4: The tables and the start profile**

In `skills/pipeline/checks/agents.php`:

Replace the `PIPELINE_AGENTS` docblock and the `'light' => [...]` block so the constant reads, in this order:

```php
/** `full`, `medium` and `light` are the tiers (`AgentTier`) and hold every step; `loopedBack` replaces a step's entry once a gate looped back to its leg; `retry` reruns a review that returned nothing; `smoke` runs a smoke run's stubs. */
const PIPELINE_AGENTS = [
    'full' => [
        'design:spec' => ['model' => 'opus', 'effort' => 'high'],
        'design:plan' => ['model' => 'opus', 'effort' => 'high'],
        'review-plan:review' => ['model' => 'fable', 'effort' => 'high'],
        'review-plan:resolve' => ['model' => 'opus', 'effort' => 'high'],
        'handoff:run' => ['model' => 'sonnet', 'effort' => 'low'],
        'implement:run' => ['model' => 'opus', 'effort' => 'high'],
        'verify-ui:run' => ['model' => 'sonnet', 'effort' => 'high'],
        'review-pr:review' => ['model' => 'fable', 'effort' => 'high'],
        'review-pr:resolve' => ['model' => 'opus', 'effort' => 'high'],
    ],
    'medium' => [
        'design:spec' => ['model' => 'opus', 'effort' => 'medium'],
        'design:plan' => ['model' => 'opus', 'effort' => 'medium'],
        'review-plan:review' => ['model' => 'fable', 'effort' => 'medium'],
        'review-plan:resolve' => ['model' => 'opus', 'effort' => 'medium'],
        'handoff:run' => ['model' => 'sonnet', 'effort' => 'low'],
        'implement:run' => ['model' => 'opus', 'effort' => 'high'],
        'verify-ui:run' => ['model' => 'sonnet', 'effort' => 'medium'],
        'review-pr:review' => ['model' => 'fable', 'effort' => 'high'],
        'review-pr:resolve' => ['model' => 'opus', 'effort' => 'medium'],
    ],
    'light' => [
        'design:spec' => ['model' => 'opus', 'effort' => 'medium'],
        'design:plan' => ['model' => 'opus', 'effort' => 'medium'],
        'review-plan:review' => ['model' => 'opus', 'effort' => 'medium'],
        'review-plan:resolve' => ['model' => 'sonnet', 'effort' => 'medium'],
        'handoff:run' => ['model' => 'sonnet', 'effort' => 'low'],
        'implement:run' => ['model' => 'sonnet', 'effort' => 'high'],
        'verify-ui:run' => ['model' => 'sonnet', 'effort' => 'medium'],
        'review-pr:review' => ['model' => 'opus', 'effort' => 'high'],
        'review-pr:resolve' => ['model' => 'sonnet', 'effort' => 'high'],
    ],
    'loopedBack' => [
        'implement:run' => ['model' => 'opus', 'effort' => 'xhigh'],
    ],
    'retry' => ['model' => 'opus', 'effort' => 'xhigh'],
    'smoke' => ['model' => 'sonnet', 'effort' => 'low'],
];
```

(`full`, `loopedBack`, `retry` and `smoke` are unchanged; the `medium` block is today's `light` block, renamed.)

Replace `pipeline_agent_table()`'s docblock and loop header:

```php
/** The script's `agents`: the table, with each step the manifest's override names laid over its entry in every tier and its loop-back entry. */
function pipeline_agent_table(array $override): array
{
    $table = PIPELINE_AGENTS;
    foreach ($override as $step => $fields) {
        foreach ([...array_column(AgentTier::cases(), 'value'), 'loopedBack'] as $profile) {
```

(the loop body and the rest of the function stay).

Replace `pipeline_start_profile()` and its docblock:

```php
/**
 * The profile a run starts on, which the script keeps current from there: `full` once the ledger records
 * an escalation (one way, once per run); else the named tier moved up by the spec's size once a spec
 * exists (`AgentTier::forDesign()`); else the named tier. `$size` is `dispatch_cli_design_size($manifest)`,
 * which answers Architectural for no spec as well.
 */
function pipeline_start_profile(array $manifest, DesignSize $size): string
{
    $tier = AgentTier::fromManifest($manifest);

    return match (true) {
        in_array('escalated', array_column(pipeline_ledger($manifest), 'outcome'), true) => AgentTier::Full->value,
        ! empty($manifest['artifacts']['spec']) => $tier->forDesign($size)->value,
        default => $tier->value,
    };
}
```

In `skills/pipeline/checks/design_size.php`, delete `profile()` and its docblock (the method between `fromSpec()` and `escalation()`).

In `skills/pipeline/checks/dispatch_cli.php`'s `launch` answer, after `'profile' => pipeline_start_profile($manifest, $size),` add:

```php
        'tier' => AgentTier::fromManifest($manifest)->value,
```

- [ ] **Step 5: The script**

In `skills/pipeline/workflow/pipeline-autoflow.js`:

Replace the header comment's lines

```js
// the functions interactive mode uses. So are the agents: `agents` (pipeline_agent_table()) and the
// profile the run starts on (pipeline_start_profile()), so the script names no model or effort
// (engine.md §Agents per step). meta.phases repeats the legs as labels only (meta must be a pure
```

with

```js
// the functions interactive mode uses. So are the agents: `agents` (pipeline_agent_table()), the
// profile the run starts on (pipeline_start_profile()) and `tier`, the tier its invocation named, so
// the script names no model or effort, and no tier but `full` (engine.md §Agents per step).
// meta.phases repeats the legs as labels only (meta must be a pure
```

Replace `completeAgents()` and its comment with:

```js
// `full` and the run's tier each cover every step of the tables, a loop-back entry names one of those
// steps, the retry and smoke entries are there, and the run starts on `full` or its tier: the only
// profiles it can reach.
function completeAgents(agents, profile, tier, steps) {
  const keys = Object.entries(steps).flatMap(([leg, list]) => list.map(step => `${leg}:${step}`))
  const { full, loopedBack, retry, smoke } = agents ?? {}
  const covers = table => typeof table === 'object' && table !== null && keys.every(key => isSetting(table[key]))
  return covers(full) && covers(agents?.[tier])
    && typeof loopedBack === 'object' && loopedBack !== null && Object.entries(loopedBack).every(([key, entry]) => keys.includes(key) && isSetting(entry))
    && isSetting(retry) && isSetting(smoke)
    && ['full', tier].includes(profile)
}
```

Replace

```js
if (!completeAgents(args.agents, args.profile, steps)) return halt(args.startLeg, 'args carry no complete agents table: re-run launch from checks that have pipeline_agent_table()')
const agents = args.agents
```

with

```js
if (!completeAgents(args.agents, args.profile, args.tier, steps)) return halt(args.startLeg, 'args carry no complete agents table: re-run launch from checks that have pipeline_agent_table()')
const agents = args.agents
const tier = args.tier
```

Replace

```js
      profile = size === 'Bounded' && !exempted ? 'light' : 'full' // escalation is one way, in the run as on a resume
```

with

```js
      profile = size === 'Bounded' && !exempted ? tier : 'full' // up, never down: escalation is one way, in the run as on a resume
```

The escalation assignment (`profile = 'full'`) and `settingFor()` stay.

- [ ] **Step 6: engine.md §`autoflow` and §Agents per step**

In `skills/pipeline/references/engine.md` §`autoflow`:

- In the `launch` answer comment line, replace `"profile":…,"agents":{…}}` with `"profile":…,"tier":…,"agents":{…}}`.
- Replace

  ```markdown
    `agents` and `profile` are the step agents' models and efforts and the profile the run starts on
    (§Agents per step); an invalid `agents` override in the manifest halts `launch` with the other
    manifest checks, before anything is written.
  ```

  with

  ```markdown
    `agents`, `profile` and `tier` are the step agents' models and efforts, the profile the run starts
    on and the tier its invocation named (§Agents per step); an invalid `agents` override in the
    manifest halts `launch` with the other manifest checks, before anything is written.
  ```

- Replace `and `agents` or `profile` missing or incomplete halts before any agent;` with `and `agents`, `profile` or `tier` missing or incomplete halts before any agent;`.

Replace the body of `## Agents per step — one table, explicit model and effort` (everything from the heading's next line up to, not including, `## Interactive — the same loop, the human resolves`) with:

```markdown

Every `autoflow` step's agent runs on a model and an effort from one table, `PIPELINE_AGENTS` in
`../checks/agents.php`; no step inherits the session's `~/.claude/settings.json`, which differs per
machine and changes silently. `launch` hands the table to the script as `agents` in its `start` answer
(`pipeline_agent_table()`, the manifest's override laid over it) with `profile`, the profile the run
starts on (`pipeline_start_profile()`), and `tier`, the tier its invocation named
(`AgentTier::fromManifest()`); the script names no model or effort, and a missing or incomplete
`agents`, `profile` or `tier` halts it before any agent. Models are `agent()`'s aliases, efforts its
levels. The owner's constraints are tokens (plan limits), quality and speed, not price.

Three tiers, picked by the invocation's word: `full` with no word, `medium`, and `light` for a tiny
change.

| Step | `full` | `medium` | `light` | Why |
|---|---|---|---|---|
| `design:spec` | opus high | opus medium | opus medium | Full: a mistake surfaces only at `review-plan` and costs a loop (design, review, resolve). Medium: a ~25-line design, and escalation is the safety net. Light: a ~15-line Bounded spec; `review-plan` catches a mistake. |
| `design:plan` | opus high | opus medium | opus medium | As `design:spec`. The plan step runs only on an Architectural design, so on `full`; the `medium` and `light` entries keep every step in every tier. |
| `review-plan:review` | fable high | fable medium | opus medium | Full: independent of the author, Fable's documented starting point; xhigh added nothing measurable in two runs, and `low` answers from memory more. Medium: a short spec is flatter work. Light: spares Fable quota; the same model as the author, accepted on a ~15-line spec (owner decision), and the PR review stays independent. |
| `review-plan:resolve` | opus high | opus medium | sonnet medium | Full: it decides which findings to reject. Medium and light: few findings on a short spec. |
| `handoff:run` | sonnet low | sonnet low | sonnet low | Near-mechanical on every tier. Haiku 4.5 has no effort setting and writes `implement`'s prompt: rejected. |
| `implement:run` | opus high | opus high | sonnet high | Medium keeps high: TDD and the escalation check after every commit happen here, and its time goes to CI and Pint, not the model. Light: a tiny change; a `verify-ui` or `review-pr` loop-back still reruns it on the loop-back entry. |
| `verify-ui:run` | sonnet high | sonnet medium | sonnet medium | Mostly browser operation; full stays high because it is a gate that can send the run back to `implement`. Medium and light: few states to capture, and no lower, since it is a gate. |
| `review-pr:review` | fable high | fable high | opus high | The last gate before a human merges: high on every tier. Light: on the first round Opus is independent of Sonnet's code, and it spares Fable quota. |
| `review-pr:resolve` | opus high | opus medium | sonnet high | Full: nothing reviews it afterwards unless it loops back. Medium and light: targeted fixes on a small diff. |
| `implement:run` after a loop-back | opus xhigh | opus xhigh | opus xhigh | A `verify-ui` or `review-pr` loop-back is the failure signal to rerun with more effort. |
| a review that returned nothing, once | opus xhigh | opus xhigh | opus xhigh | Rare; it fires on `null`, not on a review with no findings, and compensates for reviewing with the author's model. |
| a smoke run's stub step | sonnet low | sonnet low | sonnet low | A stub does no real work. |

**Which profile.** The word names the tier (`AgentTier::fromManifest()`): the manifest's `tier`,
`medium` for a legacy `light: true`, else `full`; a `tier` that is not one of the three reads as `full`.
The design size moves a run up, never down (`AgentTier::forDesign()`): an Architectural design runs on
`full`, a Bounded one on the named tier, so a Bounded design with no word stays on `full`. `launch`
starts the run on `full` once the ledger records an `escalated` entry; else, once a spec exists, on the
tier `forDesign()` gives for its size; else on the named tier, the only signal before `design` runs.
The script then sets the profile after every `continued` design step from the size it returned (the
tier for Bounded, unless the run has escalated; `full` otherwise), and to `full` on a Bounded
escalation, so the grow-form design and every step after it run on `full`: escalation is one way, in
the run as on a resume. Every step, `design` included, runs on the current profile. The script checks
`full` and the run's tier, the only tables it can reach, and halts on a tier whose table misses a step.

**The loop-back entry.** A step whose leg a gate has looped back to in this run — `loops` counts on from
the ledger's, so a resume keeps it — takes its `loopedBack` entry when it has one: `implement:run` after
a `verify-ui` or `review-pr` loop-back, on every tier. A plan gap loops back to `design`, which has none.

**The override.** A manifest may set `agents: {"<leg>:<step>": {"model": …, "effort": …}}` by hand for a
one-off experiment; either field may be left out and keeps the table's. It replaces that step's entry
in every tier and its loop-back entry; the retry and smoke entries are not overridable. `launch`
halts on an override that is not an object of `autoflow` steps each naming a known model or effort
(*the manifest's agents override is invalid: …*), and a leg that writes `agents` halts at the next brief.

**Fable stays the reviewer on `full` and `medium`.** Reviews on Opus would be the largest token lever,
but give up an independent reviewer. `light` takes that lever for a tiny change (owner decision): its
PR review on Opus is still independent of Sonnet's code on the first round. `run_cost_cli.php` weighs
each call by its model (§`autoflow`), so a model swap shows in the figure; effort shows mostly as turns
and wall time.

```

(Keep one blank line before `## Interactive`.)

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php -l skills/pipeline/checks/agents.php && php -l skills/pipeline/checks/design_size.php && php -l skills/pipeline/checks/dispatch_cli.php && ./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: no syntax errors, and the whole suite PASSES, `LockStepTest`'s *keeps every model and effort out of the autoflow script* and *keeps every engine.md section a brief names* included. (The script is checked by `AutoflowScriptTest`'s replay, not by `node --check`: its top-level `return` only parses inside the Workflow runtime's function body.)

- [ ] **Step 8: Commit**

```bash
git add skills/pipeline/checks/agents.php skills/pipeline/checks/design_size.php skills/pipeline/checks/dispatch_cli.php skills/pipeline/workflow/pipeline-autoflow.js skills/pipeline/references/engine.md skills/pipeline/checks/tests/AgentsTest.php skills/pipeline/checks/tests/DesignSizeTest.php skills/pipeline/checks/tests/DispatchCliTest.php skills/pipeline/checks/tests/AutoflowScriptTest.php skills/pipeline/checks/tests/LockStepTest.php
git commit -m "feat(pipeline): autoflow runs on three agent tiers, the named one moved up by the design size (#116)"
```

---

### Task 3: the word — kickoff's `--medium` / `--light`, the design brief, and the docs

**Files:**
- Modify: `skills/pipeline/checks/dispatch_cli.php` (header docblock line 8, `dispatch_cli_kickoff_args()` and its docblock, the `usage:` line), `skills/pipeline/checks/kickoff.php` (`pipeline_kickoff()`'s `@param`, `pipeline_kickoff_manifest()`), `skills/pipeline/checks/brief.php` (the design size line), `skills/pipeline/references/engine.md`, `skills/pipeline/references/gates.md`, `skills/pipeline/references/manifest.md`, `skills/pipeline/SKILL.md`
- Test: `skills/pipeline/checks/tests/DispatchCliTest.php` (kickoff cases), `skills/pipeline/checks/tests/BriefTest.php`

**Interfaces:**
- Consumes: `AgentTier::fromManifest()`, `permitsBounded()`, `AgentTier::from()` (Task 1).
- Produces: `dispatch_cli_kickoff_args(array $arguments): ?array` returning `array{repoRoot: string, item: string, mode: string, tier: AgentTier, base: ?string, decisions: list<string>}|null`; the manifest field `tier` (`"medium"` | `"light"`, absent for `full`).

- [ ] **Step 1: Write the failing tests**

`skills/pipeline/checks/tests/DispatchCliTest.php` — replace *writes the mode, light and the decisions verbatim into the first manifest* with:

```php
it('writes the mode, the tier and the decisions verbatim into the first manifest', function (array $arguments, string $tier) {
    $fixture = kickoff_fixture();

    $ready = kickoff($fixture, [...$arguments, '--mode', 'autoflow', '--decision', 'Fold in #53: add pipeline_ledger()', '--decision', 'Keep the guard'])['json'];
    $manifest = manifest_read($ready['manifest']);

    expect($manifest)->toMatchArray([
        'mode' => 'autoflow',
        'tier' => $tier,
        'decisions' => ['Fold in #53: add pipeline_ledger()', 'Keep the guard'],
    ]);
    expect($manifest)->not->toHaveKey('light');
})->with([
    'medium' => [['#69', '--medium'], 'medium'],
    'light' => [['#69', '--light'], 'light'],
    'light, before the item' => [['--light', '#69'], 'light'],
]);
```

(*kicks off an issue: …* already compares a no-flag manifest with `toBe`, so it pins that no flag writes no `tier`.)

In *refuses a kickoff it cannot parse*, add to the dataset:

```php
    'two tier flags' => [['69', '--medium', '--light']],
    'a tier flag twice' => [['69', '--light', '--light']],
```

`skills/pipeline/checks/tests/BriefTest.php` — replace *permits the design size the invocation allowed* with:

```php
it('permits the design size the invocation allowed, naming the word', function (array $extra, string $line) {
    expect(pipeline_brief(brief_manifest('design', $extra), 'design', '/tmp/wt/.claude/pipeline/feature-x.json'))->toContain($line);
})->with([
    'no word' => [[], '- design size: the Architectural path is required (no `medium` or `light`)'],
    'medium' => [['tier' => 'medium'], '- design size: the Bounded path is permitted (`medium`)'],
    'light' => [['tier' => 'light'], '- design size: the Bounded path is permitted (`light`)'],
    'a legacy light: true' => [['light' => true], '- design size: the Bounded path is permitted (`medium`)'],
]);
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='DispatchCliTest|BriefTest'`
Expected: FAIL — the `--medium` kickoff exits 1 (an unknown flag), the `--light` kickoffs write `light: true` instead of `tier`, *a tier flag twice* kicks off instead of refusing; the brief cases find `(no `light`)` and `(`light`)`. (*two tier flags* already passes: `--medium` is unknown today.)

- [ ] **Step 3: Kickoff and the brief**

In `skills/pipeline/checks/dispatch_cli.php`, replace `dispatch_cli_kickoff_args()`'s docblock and its first lines through the `--light` branch:

```php
/**
 * `kickoff <repo-root> <number|idea> [--medium|--light] [--base <branch>] [--decision <text>]...`; null
 * is a usage error, and so is a second tier flag. `--mode autoflow` is accepted and changes nothing;
 * `--mode auto` parses, so that `dispatch_cli_kickoff()` can halt it by name. `interactive` keeps its
 * session-driven kickoff.
 *
 * @return array{repoRoot: string, item: string, mode: string, tier: AgentTier, base: ?string, decisions: list<string>}|null
 */
function dispatch_cli_kickoff_args(array $arguments): ?array
{
    $options = ['mode' => 'autoflow', 'tier' => AgentTier::Full, 'base' => null, 'decisions' => []];
    $positional = [];
    while ($arguments !== []) {
        $argument = (string) array_shift($arguments);
        if (in_array($argument, ['--medium', '--light'], true)) {
            if ($options['tier'] !== AgentTier::Full) {
                return null;
            }
            $options['tier'] = AgentTier::from(substr($argument, 2));

            continue;
        }
```

(the rest of the function stays). In the file's header docblock (line 8) and in the `usage:` line near the end, replace `[--light]` with `[--medium|--light]`.

In `skills/pipeline/checks/kickoff.php`, change `pipeline_kickoff()`'s `@param` to `array{mode: string, tier: AgentTier, base: ?string, decisions: list<string>}  $options`, and in `pipeline_kickoff_manifest()` replace

```php
        ...($options['light'] ? ['light' => true] : []),
```

with

```php
        ...($options['tier'] === AgentTier::Full ? [] : ['tier' => $options['tier']->value]),
```

In `skills/pipeline/checks/brief.php`, replace

```php
    if ($leg === 'design') {
        $lines[] = empty($manifest['light'])
            ? '- design size: the Architectural path is required (no `light`)'
            : '- design size: the Bounded path is permitted (`light`)';
    }
```

with

```php
    if ($leg === 'design') {
        $tier = AgentTier::fromManifest($manifest);
        $lines[] = $tier->permitsBounded()
            ? "- design size: the Bounded path is permitted (`{$tier->value}`)"
            : '- design size: the Architectural path is required (no `medium` or `light`)';
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php -l skills/pipeline/checks/dispatch_cli.php && php -l skills/pipeline/checks/kickoff.php && php -l skills/pipeline/checks/brief.php && ./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='DispatchCliTest|BriefTest|StatuslineTest'`
Expected: PASS (`StatuslineTest` loads `kickoff.php` too and never calls `pipeline_kickoff_manifest()`).

- [ ] **Step 5: The docs**

`skills/pipeline/references/engine.md`:

- Both kickoff usage lines (§`autoflow`'s code block and §Kickoff's): replace `[--light]` with `[--medium|--light]`.
- §Kickoff, *The first `manifest_write`*: replace `` `light: true` when the invocation said `light`, `` with `` `tier` when the invocation named `medium` or `light` (`tier: "medium"` / `tier: "light"`; nothing for no word, which is `full`), ``, and replace `a `light` run would get an Architectural design` with `a `medium` or `light` run would get an Architectural design`. In `interactive` the session writes `tier` the same way (spec *Assumptions* 9); that paragraph already covers every kickoff session.
- The `design` row of the leg table: replace `if brainstorming classifies Bounded without `light`, the pipeline asks` with `if brainstorming classifies Bounded without `medium` or `light`, the pipeline asks`, and `Bounded only with `light`, otherwise Architectural` with `Bounded only with `medium` or `light`, otherwise Architectural`.
- §Design size, *Who picks the size*: replace

  ```markdown
  `/pipeline [interactive|autoflow] [light] <idea | number | spec-path>`. The word `light` **permits** Bounded.
  It matters only while `design` has not run; on a resume the size comes from the spec and `light` is
  ignored, with a note saying so.

  | | with `light` | without `light` |
  ```

  with

  ```markdown
  `/pipeline [interactive|autoflow] [medium|light] <idea | number | spec-path>`. The word `medium` or
  `light` **permits** Bounded, and in `autoflow` names the agents tier (§Agents per step); no word means
  `full`. The permit matters only while `design` has not run; on a resume the size comes from the spec
  and the word permits nothing more, with a note saying so (the tier kickoff recorded still picks a
  Bounded design's agents).

  | | with `medium` or `light` | with neither |
  ```

`skills/pipeline/references/gates.md` — replace

```markdown
**`light` is not a mode, and not a second chain.** It permits a **Bounded** design (`engine.md`
§Design size): a ~15-line spec and a ~10-line plan instead of a full design. Legs, gates and
```

with

```markdown
**`medium` and `light` are not modes, and not a second chain.** They permit a **Bounded** design
(`engine.md` §Design size): a ~15-line spec and a ~10-line plan instead of a full design, and in
`autoflow` they pick the agents tier (`engine.md` §Agents per step). Legs, gates and
```

`skills/pipeline/references/manifest.md` — replace the `light` row with:

```markdown
| `tier` | optional | the invocation's word: `"medium"` or `"light"`; absent means `full`. Both permit a Bounded design, read only while `design` has not run; in `autoflow` the tier also picks the agents (`AgentTier::fromManifest()`, `engine.md` §Agents per step). Written once by kickoff; a leg that changes it halts the run. A manifest with the legacy `light: true` and no `tier` reads as `medium`, today's permit and agents; nothing writes `light` any more |
```

and in the `agents` row replace `laid over that step's entry in both profiles` with `laid over that step's entry in every tier`.

`skills/pipeline/SKILL.md`:

- The invocation line: replace `/pipeline [interactive|autoflow] [light] [base <branch>]` with `/pipeline [interactive|autoflow] [medium|light] [base <branch>]`, and pad the next line (`/pipeline   … # resume the current branch's run`) by the same 7 spaces so the two `#` comments stay aligned.
- Replace the bullet

  ```markdown
  - **`light` permits a small design.** A Bounded design is a ~15-line spec and a ~10-line plan
    instead of a full design; every leg and both reviews still run. In `autoflow` a small change also
    runs on lighter agents on the design, review-plan, verify-ui and resolve steps
    (`references/engine.md` §Agents per step). Without `light`, `interactive`
    asks when brainstorming finds the change small, and `autoflow` always writes the full design. A
  ```

  with

  ```markdown
  - **`medium` or `light` permits a small design.** A Bounded design is a ~15-line spec and a ~10-line
    plan instead of a full design; every leg and both reviews still run. In `autoflow` the word also
    names the agents tier: `medium` runs lighter agents on the design, review-plan, verify-ui and
    resolve steps, and `light` also runs cheaper models on most steps (`references/engine.md` §Agents
    per step); an Architectural design or an escalation runs on `full` whatever the word. With neither,
    `interactive` asks when brainstorming finds the change small, and `autoflow` always writes the full design. A
  ```

- The kickoff command: replace `[--light]` with `[--medium|--light]`.

- [ ] **Step 6: Run the whole suite and sweep the old word**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests && grep -rn "\[light\]\|\[--light\]\|light: true\|both profiles\|without \`light\`\|with \`light\`" skills/pipeline`
Expected: the suite PASSES; `grep` prints only lines that name the legacy `light: true` (manifest.md's `tier` row, engine.md §Agents per step's *Which profile*) and test fixtures under `checks/tests/` that exercise it.

- [ ] **Step 7: Commit**

```bash
git add skills/pipeline/checks/dispatch_cli.php skills/pipeline/checks/kickoff.php skills/pipeline/checks/brief.php skills/pipeline/checks/tests/DispatchCliTest.php skills/pipeline/checks/tests/BriefTest.php skills/pipeline/references/engine.md skills/pipeline/references/gates.md skills/pipeline/references/manifest.md skills/pipeline/SKILL.md
git commit -m "feat(pipeline): the word medium or light names the tier and permits a Bounded design (#116)"
```
