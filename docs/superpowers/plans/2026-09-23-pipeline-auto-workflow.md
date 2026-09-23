# `/pipeline auto` as a Workflow script Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the LLM dispatcher of `/pipeline auto` with a saved Workflow script that calls one fresh agent per step, while interactive mode keeps `next` / `returned` unchanged.

**Architecture:** The invoking session runs two tested commands at the edges of a run (`dispatch_cli.php launch` before, `finish` after) and starts the saved workflow `pipeline-auto` in between. The script (`skills/pipeline/workflow/pipeline-auto.js`) holds the step order, loop-backs, bounds and halts; each step agent's first command is `dispatch_cli.php brief`, which records the step in the cursor and prints the brief. Cost and trust are measured after each run by `run_cost_cli.php` and `run_audit.php`; the old 150k invariant and `engine_peak*` go.

**Tech Stack:** PHP 8.4 on the host, Pest 4 (`./vendor/bin/pest`), plain JavaScript for Claude Code's Workflow tool, bash for the hook, Markdown skill references, Python 3 for the one-off baseline.

**Spec:** `docs/superpowers/specs/2026-09-23-pipeline-auto-workflow-design.md` (commit e0fca3e). Its step 1 (verification) is done; this plan covers steps 2–10. Read the spec before any task: it is the source of truth, and this plan argues from it.

## Global Constraints

- Work only in `/Users/jroelofs/GitProjects/LaravelClaudeMd/LaravelClaudeMd/.claude/worktrees/pipeline-dispatcher-loop`, branch `feature/redesign-the-pipeline-auto-engine-as-a-pure` (PR #50). `~/.claude/skills/pipeline` is a symlink to the MAIN checkout: never edit through it, and never assume it runs this branch's code (Tasks 6 and 11 route around it).
- Pipeline suite: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`. Critique suite: `./vendor/bin/pest -c skills/critique/checks/phpunit.xml --test-directory=skills/critique/checks/tests`. Hook tests: `bash hooks/tests/git-freshness-sync.test.sh` and `bash hooks/tests/vault-sync.test.sh`. All run on the host from the worktree root.
- Test-first for every PHP and hook change: the test is written first and seen red. A test written first has been seen red; that is its proof, and there is no other test policy.
- PHP checks are procedural functions in `skills/pipeline/checks/`, in the style of the existing files (`match`, arrow functions, a docblock where the why is not obvious, no classes beyond the existing enums). Files that run on require (`dispatch_cli.php`, `*_cli.php`, `run_audit.php`) are never loaded by `tests/Pest.php`; they are tested through `proc_open`.
- Interactive mode is unchanged: `next` and `returned` keep their behaviour, and their existing tests stay green.
- The workflow script is plain JavaScript. It begins with a pure-literal `export const meta = {name: 'pipeline-auto', description, …}`, uses only the globals `agent`, `args`, `log` and `phase`, and has no filesystem or shell access, no `Date.now()`, `Math.random()`, argless `new Date()` and no TypeScript.
- Values copied from the spec: loop-back bound `2` per gate; review steps run with `model: 'fable'` and once more with `model: 'opus'` when `agent()` returns null; `handoff` runs with `effort: 'low'`; cost weights cache read `0.1`, 5m cache write `1.25`, 1h cache write `2`, output `5` (input `1`, as the audit's `usage.py`); baseline = old engine runs since `2026-09-14`, the engine plus every agent it dispatched; keep when after `6` runs the median cost per run is at least `30` percent below the baseline median; revert when `implement` peaks above `250k` on a plan under `300` code lines.
- The spec is not modified. `~/.claude/token-audit/2026-09-22/*` is read, never modified.
- The repo has no changelog; none is added.
- Commits: no `Co-Authored-By`, no AI attribution. PR text is an impersonal record of the work; nothing addressed to a person.
- Nothing is pushed before Task 11.
- `main` moved after this plan was written: PRs #54 and #57 add a `ci`-label paragraph to `engine.md` (after §Who takes the PR out of draft's cold-resume paragraph) whose last sentence asks the `implement` brief to say **"add the `ci` label (`gh pr edit <pr> --add-label ci`) before the push whose CI you watch."** Catching the branch up conflicts in `engine.md` and is the owner's call: raise it before Task 1, and do not pull, rebase or merge on your own initiative. If the owner has `main` merged first, Task 1 also adds that quoted sentence to `pipeline_leg_overrides()`' `implement:run` list (`brief.php` exists only on this branch), and Task 8's `engine.md` edits apply around main's paragraph.

## Amendment A — `autoflow` beside `auto` (owner decision, 2026-09-23, after Task 5)

The new engine does not replace the old one yet. Until the keep / revert decision after 6 runs, both
run side by side as modes of the one `/pipeline` skill, recorded in the manifest's `mode` at kickoff
(so a resumed run stays on the engine it started with):

- **`auto`** — the LLM dispatcher, exactly as on `main`: `next` / `returned`, the 150k invariant and
  `engine_peak*`, its briefs as before Task 1 (identical override lines to `interactive`, one-line reply).
- **`autoflow`** — this plan's engine: `launch` → the saved workflow **`pipeline-autoflow`**
  (`skills/pipeline/workflow/pipeline-autoflow.js`) → `finish`. Every "`auto`-only" brief line of Tasks 1–2
  is an `autoflow`-only line.
- **`interactive`** — unchanged.
- **`/orchestrate [auto|autoflow] <issues>`** — the engine per batch; without the argument, `auto`.
- After the decision, the losing mode is deleted: keep → `auto` and the dispatcher go and `autoflow`
  may be renamed `auto`; revert → `autoflow`, the workflow, `launch` / `brief` / `finish`, `run_cost*`
  and `run_audit` go.

What this changes in the tasks (the spec is not modified; this amendment is the record):

- **Task 5A (new, below)**: the mode split in code.
- **Task 6**: the file is `skills/pipeline/workflow/pipeline-autoflow.js`, `meta.name` is
  `pipeline-autoflow`; the smoke manifests carry `"mode":"autoflow"`.
- **Task 7**: unchanged (the hook links whatever `skills/*/workflow/*.js` exists).
- **Task 8**: `engine.md` keeps main's §The loop and §The dispatcher for `auto` and gains
  `### autoflow — a program that calls agents` beside it; wherever Task 8's text replaces dispatcher
  wording with the workflow's, it applies to `autoflow` only and the `auto` wording stays. Step 8's grep
  becomes: no text describing `autoflow` mentions the dispatcher, `engine_peak` or 150k; the `auto` text
  keeps them. `SKILL.md`: the mode list is `[interactive|auto|autoflow]`, the 150k invariant bullet stays
  for `auto`, the new section is `## autoflow — how a run starts and ends`, the cost-per-run bullet is
  for `autoflow`. `gates.md` §Modes names three modes.
- **Task 9**: `orchestrate` takes `[auto|autoflow]`, default `auto`; its current dispatch path stays for
  `auto` and Task 9's workflow path is added for `autoflow` (with "one workflow per run at a time"
  beside "one agent per run, ever").
- **Task 10**: the criteria read "keep `autoflow` (and delete `auto`)" / "revert: delete `autoflow`".
- **Task 11**: the real run is `/pipeline autoflow`; the temporary link is
  `~/.claude/workflows/pipeline-autoflow.js`.

---

## File Structure

| File | Responsibility |
|---|---|
| `skills/pipeline/checks/brief.php` (modify) | `pipeline_brief()` takes an optional step; `pipeline_leg_overrides($mode)` carries the `auto`-only lines; `pipeline_brief_return()` asks an `auto` step for a structured result; resolve steps get loop-back wording for plan gaps |
| `skills/pipeline/checks/dispatch.php` (modify) | the two gate-skip arms; `PIPELINE_LOOP_BOUND`, `pipeline_steps()`, `pipeline_loop_counts()`, `pipeline_step_problem()`, `pipeline_pr_problem()`. Pure |
| `skills/pipeline/checks/dispatch_cli.php` (modify) | adds `launch`, `brief`, `finish`; `next` / `returned` unchanged |
| `skills/pipeline/checks/run_cost.php` (new) | weighted cost per transcript, the workflow journal parser. Pure |
| `skills/pipeline/checks/run_cost_cli.php` (new) | `<run transcript dir>` → cost per step and the largest step peak, exit 0 |
| `skills/pipeline/checks/run_audit.php` (new, CLI) | `<manifest> <final diff> <run transcript dir>` → the `ui` fact and the per-gate reported-vs-ledger facts, exit 0 |
| `skills/pipeline/checks/engine_peak.php`, `engine_peak_cli.php`, `tests/EnginePeakTest.php` | deleted |
| `skills/pipeline/workflow/pipeline-auto.js` (new) | the `auto` loop |
| `skills/pipeline/checks/tests/{BriefTest,DispatchTest,ReturnedTest,DispatchCliTest,RunCostTest,RunAuditTest,Pest}.php` | tests per unit above |
| `hooks/git-freshness.sh`, `hooks/tests/git-freshness-sync.test.sh` | link `skills/*/workflow/*.js` into `~/.claude/workflows/` |
| `skills/pipeline/references/{engine,manifest,gates}.md`, `skills/pipeline/SKILL.md` | the pipeline docs |
| `skills/orchestrate/SKILL.md`, `skills/orchestrate/references/commands.md` | the orchestrate docs |
| `README.md`, `CLAUDE.md` | one line each on the hook linking workflow scripts |

Test helpers already in the suite that tasks below reuse: `brief_manifest()` (BriefTest.php), `dispatch_manifest()` (DispatchTest.php), `returned_before()` / `returned_after()` and the file-level `$noUi`, `$open` (ReturnedTest.php), `dispatch_fixture()`, `dispatch_cli()`, `dispatch_leg_writes()` (DispatchCliTest.php), `suite_repo()` (SuiteTest.php), `lockstep_section()` (LockStepTest.php). Pest loads every test file before running any test, so a helper defined in one file is callable from another when the suite runs as a directory (always run it that way, with `--filter` to narrow).

---

### Task 1: The brief takes its step and carries the `auto`-only lines (spec step 2)

**Files:**
- Modify: `skills/pipeline/checks/brief.php` (`pipeline_leg_overrides`, `pipeline_brief`, `pipeline_brief_overrides`, `pipeline_brief_return`)
- Modify: `skills/pipeline/checks/dispatch_cli.php:36` (pass the step it already computed)
- Test: `skills/pipeline/checks/tests/BriefTest.php`

**Interfaces:**
- Consumes: `pipeline_step(array $manifest, string $leg): string`, `LegStatus::allowedFor()`, `pipeline_leg_writable_keys()` (dispatch.php).
- Produces: `pipeline_brief(array $manifest, string $leg, string $manifestPath, ?string $step = null): string` — `$step` null means derived with `pipeline_step()` (interactive); `auto` passes it. `pipeline_leg_overrides(string $mode): array<string, list<string>>` keyed `<leg>:<step>`. `pipeline_brief_return(string $leg, string $step, string $mode): string`. In `auto` a brief contains the literal phrases `Run every command from \`cd <worktree>\``, `The owner authorised this run`, `as your structured result`, `Apply \`/critique\`'s \`plan\` procedure` / `` `pr` procedure``, `Execute the plan inline, task by task; no subagents.`, `Leave the PR draft; the session that launched the run marks it ready.`

- [ ] **Step 1: Write the failing tests**

In `BriefTest.php`, replace the test `has overrides for every leg and step` with:

```php
it('has overrides for every leg and step, in both modes', function () {
    foreach (['auto', 'interactive'] as $mode) {
        foreach (pipeline_legs() as $leg) {
            foreach (in_array($leg, ['review-plan', 'review-pr'], true) ? ['review', 'resolve'] : ['run'] as $step) {
                expect(pipeline_leg_overrides($mode))->toHaveKey("{$leg}:{$step}");
            }
        }
    }
});
```

Replace the test `makes the review-pr resolve step the finish step` with (the `gh pr ready` line is interactive-only now):

```php
it('makes the review-pr resolve step the finish step', function () {
    $open = ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r'];
    $brief = pipeline_brief(brief_manifest('review-pr', ['mode' => 'interactive', 'gate_ledger' => [$open]]), 'review-pr', '/tmp/wt/.claude/pipeline/feature-x.json');

    expect($brief)->toContain('the finish step')->toContain('gh pr ready')->toContain('proof_cli.php open');
});
```

Append:

```php
it('takes the step from its caller when given one, and derives it otherwise', function () {
    expect(pipeline_brief(brief_manifest('review-plan'), 'review-plan', '/tmp/m.json', 'resolve'))->toContain('`review-plan` leg, `resolve` step');
    expect(pipeline_brief(brief_manifest('review-plan'), 'review-plan', '/tmp/m.json'))->toContain('`review-plan` leg, `review` step');
});

it('has an auto reviewer apply /critique itself, and an interactive one invoke it', function (string $leg, string $procedure) {
    $auto = pipeline_brief(brief_manifest($leg), $leg, '/tmp/m.json', 'review');
    $interactive = pipeline_brief(brief_manifest($leg, ['mode' => 'interactive']), $leg, '/tmp/m.json', 'review');

    expect($auto)
        ->toContain("Apply `/critique`'s `{$procedure}` procedure")
        ->toContain('rubric in `~/.claude/skills/critique/references/rubrics.md`')
        ->toContain('You are the reviewer; do not dispatch one')
        ->not->toContain("Invoke `/critique {$procedure}`");
    expect($interactive)->toContain("Invoke `/critique {$procedure}`")->not->toContain('do not dispatch one');
})->with([['review-plan', 'plan'], ['review-pr', 'pr']]);

it('drops the independent read and the subagents in auto', function () {
    $open = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r'];

    expect(pipeline_brief(brief_manifest('review-plan', ['gate_ledger' => [$open]]), 'review-plan', '/tmp/m.json'))->not->toContain('independent read');
    expect(pipeline_brief(brief_manifest('review-plan', ['mode' => 'interactive', 'gate_ledger' => [$open]]), 'review-plan', '/tmp/m.json'))->toContain('independent read');
    expect(pipeline_brief(brief_manifest('implement'), 'implement', '/tmp/m.json'))->toContain('Execute the plan inline, task by task; no subagents.');
    expect(pipeline_brief(brief_manifest('implement', ['mode' => 'interactive']), 'implement', '/tmp/m.json'))->not->toContain('no subagents');
});

it('leaves the PR draft at the auto finish step for the session that launched the run', function () {
    $open = ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r'];
    $brief = pipeline_brief(brief_manifest('review-pr', ['gate_ledger' => [$open]]), 'review-pr', '/tmp/m.json');

    expect($brief)
        ->toContain('Leave the PR draft; the session that launched the run marks it ready.')
        ->toContain('On a loop-back, stop there: no suite.')
        ->not->toContain('gh pr ready');
    expect(strpos($brief, 'Complete the open entry'))->toBeLessThan(strpos($brief, 'The last action is `proof_cli.php open`'));
});

it('tells every auto step where to work, that the run is authorised, and to return a structured result', function () {
    foreach (pipeline_legs() as $leg) {
        foreach (in_array($leg, ['review-plan', 'review-pr'], true) ? ['review', 'resolve'] : ['run'] as $step) {
            $auto = pipeline_brief(brief_manifest($leg), $leg, '/tmp/m.json', $step);
            $interactive = pipeline_brief(brief_manifest($leg, ['mode' => 'interactive']), $leg, '/tmp/m.json', $step);

            expect($auto)
                ->toContain('Run every command from `cd /tmp/wt`')
                ->toContain('The owner authorised this run, including pushing the branch and opening the draft PR; the pipeline never merges.')
                ->toContain('then return `{status, reason}` as your structured result');
            expect($interactive)
                ->not->toContain('The owner authorised this run')
                ->not->toContain('`cd /tmp/wt`')
                ->toContain('and reply with one line naming it');
        }
    }
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='takes the step|reviewer apply|independent read and the subagents|auto finish step|every auto step'`
Expected: FAIL — `takes the step` gets a `review` step brief (the fourth argument is ignored), the others miss the `auto` phrases.

- [ ] **Step 3: Implement**

In `brief.php`, replace `pipeline_leg_overrides()` with:

```php
/**
 * @return array<string, list<string>> keyed `<leg>:<step>`. An `auto` step is a workflow agent: it
 * cannot start agents, so where a station would dispatch one it does that work itself.
 */
function pipeline_leg_overrides(string $mode): array
{
    $auto = $mode === 'auto';
    $actOnReview = [
        'Act on the open review with the edit/rework boundary (engine.md §`auto`): integrate and commit edits and small fixes; where the review says the work is fundamentally wrong, loop back.',
        'Change nothing the review did not name.',
        'Carry anything unresolved verbatim as an open question.',
    ];
    $completeEntry = 'Complete the open entry: `actions`, then `outcome`, equal to the status you return.';
    $yourself = fn (string $procedure, string $subject) => "Apply `/critique`'s `{$procedure}` procedure to {$subject} yourself: Stage 0, Stage 1 and the rubric in `~/.claude/skills/critique/references/rubrics.md`. You are the reviewer; do not dispatch one, so `--verify` and `alternatives` are not available.";
    $checks = 'When the repo declares a `## Checks` block, run its checks first and state their result qualified by its scope (engine.md §Mechanical checks); a repo that declares none says nothing about checks.';

    return [
        'design:run' => [
            'Invoke `superpowers:brainstorming`; on the Architectural path it hands over to `superpowers:writing-plans` (engine.md §Design size).',
            'Where brainstorming would ask the human, write each question and the answer you assumed into the spec\'s `## Assumptions` section, so `/critique plan` audits exactly those.',
            'Plans and specs committed before 2026-09-14 are not exemplars for test or proof policy.',
            'Commit the spec, then the plan: two commits. Set `artifacts.spec` and `artifacts.plan`.',
        ],
        'review-plan:review' => [
            $auto ? $yourself('plan', 'the spec and the plan') : 'Invoke `/critique plan` on the spec and the plan.',
            'Append its review verbatim as a new `plan-approval` ledger entry with `gate`, `leg`, `cycle`, `at`, `review` and `annotations`, and no `outcome`.',
            'Act on nothing. Read-only on the checkout; the manifest is the only file you write.',
        ],
        'review-plan:resolve' => [
            ...$actOnReview,
            ...($auto ? [] : ['The independent read (engine.md §`auto`) is available.']),
            $completeEntry,
        ],
        'handoff:run' => [
            'Invoke `handoff pr`. The PR opens draft and references the issue without a closing keyword (engine.md §Closing links). Set `artifacts.pr`.',
        ],
        'implement:run' => [
            'Bring the dev stack up first, without asking (engine.md §Dev-stack readiness).',
            'Follow `work-on`\'s logic in this worktree; claim no second slot.',
            'Test-first; after each step the suite and the mechanical checks (engine.md §Mechanical checks, §Suite reuse). Record `suite` after every full run.',
            'Leave the PR draft; this overrides any mark-ready instruction in the plan, the PR comment, or `work-on`\'s own logic.',
            'Files or behaviour the plan does not name: return `plan-insufficient` with the reason instead of improvising.',
            ...($auto ? ['Execute the plan inline, task by task; no subagents.'] : []),
        ],
        'verify-ui:run' => [
            'Bring the dev stack up if it is down. Invoke `browser-verification`.',
            'Write the proof page (engine.md §The proof store), set `artifacts.proof` to the path `write` printed, and post the text-only record comment.',
            'Append the thin `verify-ui` entry with outcome `continued`, or `looped-back` when the check fails.',
        ],
        'review-pr:review' => [
            ($auto ? $yourself('pr', 'the PR') . ' State the suite line above.' : 'Invoke `/critique pr`, stating the suite line above.') . ' ' . $checks,
            'Append its review verbatim as a new `pr-review` ledger entry with `gate`, `leg`, `cycle`, `at`, `review` and `annotations`, and no `outcome`.',
            'Act on nothing. Read-only on the checkout; the manifest is the only file you write.',
        ],
        'review-pr:resolve' => [
            'You are the finish step.',
            ...$actOnReview,
            $auto ? 'On a loop-back, stop there: no suite.' : 'On a loop-back, stop there: no suite, no `gh pr ready`.',
            'Run the suite unless engine.md §Suite reuse finds this tree green; record `suite`.',
            'Reconcile the closing links (engine.md §Closing links) and write `issue_links` on the entry.',
            'When `artifacts.proof` is set, rewrite the proof page with the final open questions and ledger.',
            $completeEntry,
            ($auto ? 'Leave the PR draft; the session that launched the run marks it ready.' : 'Run `gh pr ready`.') . ' The last action is `proof_cli.php open` on `artifacts.proof` (engine.md §The proof store).',
        ],
    ];
}
```

Replace `pipeline_brief()` with:

```php
/** `$step` is given in `auto` (the workflow script names it) and derived from the ledger in `interactive`. */
function pipeline_brief(array $manifest, string $leg, string $manifestPath, ?string $step = null): string
{
    $step ??= pipeline_step($manifest, $leg);

    return implode("\n\n", [
        pipeline_brief_role($manifest, $leg, $step),
        pipeline_brief_pointers($manifest, $manifestPath, $leg, $step),
        pipeline_brief_state($manifest, $leg),
        pipeline_brief_overrides($manifest, $leg, $step),
        pipeline_brief_return($leg, $step, (string) $manifest['mode']),
    ]) . "\n";
}
```

In `pipeline_brief_overrides()`, change its first line to

```php
    $lines = pipeline_leg_overrides((string) $manifest['mode'])["{$leg}:{$step}"];
```

and insert, directly before its `return`:

```php
    if ($manifest['mode'] === 'auto') {
        $lines[] = "Run every command from `cd {$manifest['worktree']}` or with `git -C {$manifest['worktree']}`: the session that started this run may sit in another checkout.";
        $lines[] = 'The owner authorised this run, including pushing the branch and opening the draft PR; the pipeline never merges.';
    }
```

Replace `pipeline_brief_return()` with:

```php
function pipeline_brief_return(string $leg, string $step, string $mode): string
{
    $keys = implode(', ', array_map(fn (string $key) => "`{$key}`", pipeline_leg_writable_keys()));
    $statuses = implode(', ', array_map(fn (LegStatus $status) => "`{$status->value}`", LegStatus::allowedFor($leg, $step)));
    $reply = $mode === 'auto'
        ? 'then return `{status, reason}` as your structured result (with `reason` whenever you have one) instead of replying with a line'
        : 'and reply with one line naming it';

    return "## Return\n\n"
        . "Write your results into the manifest ({$keys}; `cursor.reason` only when you halt) and nothing else. Never move `cursor.leg`.\n"
        . "Set `cursor.status` to one of {$statuses}, {$reply}.";
}
```

In `dispatch_cli.php`, `dispatch_cli_emit()`, change

```php
    file_put_contents($files['brief'], pipeline_brief($manifest, $leg, $manifestPath));
```

to

```php
    file_put_contents($files['brief'], pipeline_brief($manifest, $leg, $manifestPath, $step));
```

- [ ] **Step 4: Run the pipeline suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, every test, including the existing DispatchCliTest (interactive `next` / `returned` unchanged).

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/brief.php skills/pipeline/checks/dispatch_cli.php skills/pipeline/checks/tests/BriefTest.php
git commit -m "pipeline: the brief takes its step and carries the auto-only lines"
```

---

### Task 2: The two gate-skip arms (spec step 3)

**Files:**
- Modify: `skills/pipeline/checks/dispatch.php` (`LegStatus::allowedFor`, `pipeline_ledger_problem`)
- Modify: `skills/pipeline/checks/brief.php` (`pipeline_brief_overrides` → new `pipeline_plan_gap_lines`)
- Test: `skills/pipeline/checks/tests/DispatchTest.php`, `ReturnedTest.php`, `BriefTest.php`

**Interfaces:**
- Consumes: `pipeline_brief_overrides()` as left by Task 1.
- Produces: `LegStatus::allowedFor($leg, 'resolve')` returns `[Continued, LoopedBack, Halted]` (Task 6's `ALLOWED.resolve` mirrors it). `pipeline_returned()` halts a review step that returns `plan-insufficient` and adds an open entry for its gate, with a reason containing `may add no open <gate> entry`. `pipeline_plan_gap_lines(string $step): list<string>`.

Spec gap handled here (listed in the hand-off): the brief gives every leg after `design` — resolve steps included — the "run the escalation check and return `plan-insufficient`" lines. With the arm below a resolve step can no longer return it, so resolve steps get one loop-back line instead.

- [ ] **Step 1: Write the failing tests**

In `DispatchTest.php`, in `allows each step only the statuses it can honestly return`, replace

```php
    expect($values('review-pr', 'resolve'))->toBe(['continued', 'looped-back', 'halted', 'plan-insufficient']);
```

with

```php
    expect($values('review-pr', 'resolve'))->toBe(['continued', 'looped-back', 'halted']);
    expect($values('review-plan', 'resolve'))->toBe(['continued', 'looped-back', 'halted']);
```

Append to `ReturnedTest.php`:

```php
it('refuses plan-insufficient from a resolve step: a plan gap found there is a loop-back', function () use ($noUi, $open) {
    $before = returned_before('review-plan', [$open]);
    $gap = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 2, 'at' => '2026-09-22T12:00:00Z', 'reason' => 'needs a queue', 'outcome' => 'looped-back'];
    $decision = pipeline_returned($before, returned_after($before, 'plan-insufficient', [[...$open, 'outcome' => 'looped-back'], $gap], [], 'needs a queue'), $noUi, DesignSize::Architectural);

    expect($decision['action'])->toBe('halt');
    expect($decision['reason'])->toContain('cannot return plan-insufficient');
});

it('refuses a review step that returns plan-insufficient and leaves an open review behind', function () use ($noUi, $open) {
    $before = returned_before('review-plan');
    $escalated = ['gate' => 'design-size', 'leg' => 'review-plan', 'at' => '2026-09-22T12:00:00Z', 'reason' => 'migration', 'outcome' => 'escalated'];

    $decision = pipeline_returned($before, returned_after($before, 'plan-insufficient', [$open, $escalated], [], 'migration'), $noUi, DesignSize::Bounded);
    expect($decision['action'])->toBe('halt');
    expect($decision['reason'])->toContain('may add no open plan-approval entry');

    expect(pipeline_returned($before, returned_after($before, 'plan-insufficient', [$escalated], [], 'migration'), $noUi, DesignSize::Bounded))
        ->toBe(['action' => 'dispatch', 'leg' => 'design']);
});
```

Append to `BriefTest.php`:

```php
it('tells a resolve step to loop back on a plan gap, and a review step to leave no review behind', function () {
    $open = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r'];

    expect(pipeline_brief(brief_manifest('review-plan', ['gate_ledger' => [$open]]), 'review-plan', '/tmp/m.json'))
        ->toContain('A plan gap or a Bounded escalation found while resolving is a loop-back: return `looped-back`')
        ->not->toContain('`plan-insufficient`');
    expect(pipeline_brief(brief_manifest('review-plan'), 'review-plan', '/tmp/m.json'))
        ->toContain('When you return `plan-insufficient`, append no review entry.');
    expect(pipeline_brief(brief_manifest('implement'), 'implement', '/tmp/m.json'))
        ->toContain('run the escalation check first')
        ->not->toContain('append no review entry');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='honestly return|from a resolve step|open review behind|loop back on a plan gap'`
Expected: FAIL — `allowedFor` still offers `plan-insufficient` to resolve steps; both returns route to `design` instead of halting; the brief has no loop-back line.

- [ ] **Step 3: Implement**

In `dispatch.php`, replace the body of `LegStatus::allowedFor()` with:

```php
        return match (true) {
            $leg === 'design' => [self::Continued, self::Halted],
            $step === 'resolve' => [self::Continued, self::LoopedBack, self::Halted],
            $leg === 'verify-ui' => [self::Continued, self::LoopedBack, self::Halted, self::PlanInsufficient],
            default => [self::Continued, self::Halted, self::PlanInsufficient],
        };
```

In `pipeline_ledger_problem()`, add this arm to the `match (true)` directly after `$status === LegStatus::Halted => null,`:

```php
        $status === LegStatus::PlanInsufficient && $step === 'review' && array_filter($addedTo($gate), 'pipeline_is_open') !== []
            => "a review step that returns plan-insufficient may add no open {$gate} entry",
```

In `brief.php`, in `pipeline_brief_overrides()`, replace

```php
    if ($leg !== 'design') {
        $lines[] = 'While the spec\'s header says `**Design size:** Bounded`, run the escalation check first (engine.md §Design size); on escalation append the `design-size` entry and return `plan-insufficient`.';
        $lines[] = 'On an Architectural spec, append a `plan-approval` entry with `leg`, `cycle`, `at`, `reason` and outcome `looped-back` before returning `plan-insufficient`.';
    }
```

with

```php
    if ($leg !== 'design') {
        $lines = [...$lines, ...pipeline_plan_gap_lines($step)];
    }
```

and add below `pipeline_brief_overrides()`:

```php
/** How a step after `design` reports a plan that falls short (engine.md §Design size). A resolve step completes its open entry, so it loops back instead. */
function pipeline_plan_gap_lines(string $step): array
{
    if ($step === 'resolve') {
        return ['A plan gap or a Bounded escalation found while resolving is a loop-back: return `looped-back` and name it in the entry\'s `actions`; the leg the run goes back to handles it.'];
    }

    return [
        'While the spec\'s header says `**Design size:** Bounded`, run the escalation check first (engine.md §Design size); on escalation append the `design-size` entry and return `plan-insufficient`.',
        'On an Architectural spec, append a `plan-approval` entry with `leg`, `cycle`, `at`, `reason` and outcome `looped-back` before returning `plan-insufficient`.',
        ...($step === 'review' ? ['When you return `plan-insufficient`, append no review entry.'] : []),
    ];
}
```

- [ ] **Step 4: Run the pipeline suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS. (`LockStepTest` still finds every status in `manifest.md`; Task 8 corrects that section's prose.)

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/dispatch.php skills/pipeline/checks/brief.php skills/pipeline/checks/tests/DispatchTest.php skills/pipeline/checks/tests/ReturnedTest.php skills/pipeline/checks/tests/BriefTest.php
git commit -m "pipeline: a resolve step loops back on a plan gap; a review step that finds one leaves no open review"
```

---

### Task 3: `dispatch_cli.php launch`, `brief` and `finish` (spec step 4)

**Files:**
- Modify: `skills/pipeline/checks/dispatch.php` (new pure functions; `pipeline_step` and `pipeline_loop_back` use them)
- Modify: `skills/pipeline/checks/dispatch_cli.php` (three commands, shared validation, output)
- Test: `skills/pipeline/checks/tests/DispatchTest.php`, `skills/pipeline/checks/tests/DispatchCliTest.php`

**Interfaces:**
- Consumes: `pipeline_brief(…, ?string $step)` (Task 1); `pipeline_triggers()`, `pipeline_can_navigate()`, `pipeline_done_legs()`, `pipeline_git_run()` (suite.php), `dispatch_cli_design_size()`, `dispatch_cli_halt()`, `dispatch_cli_done()`, `dispatch_cli_finished()`.
- Produces, pure (dispatch.php): `const PIPELINE_LOOP_BOUND = 2`; `pipeline_steps(string $leg): list<string>`; `pipeline_loop_counts(array $ledger): array{review-plan: int, review-pr: int, verify-ui: int}` (keys in `PIPELINE_GATE_OF` order; an `unknown` cycle gives that leg `PIPELINE_LOOP_BOUND`); `pipeline_step_problem(array $manifest, string $leg, string $step): ?string`; `pipeline_pr_problem(int|string $pr, ?array $view): ?string`.
- Produces, CLI (Task 6, Task 8 and the invoking session rely on these exact shapes):
  - `php dispatch_cli.php launch <manifest> <diff> [--from <leg>]` → `{"action":"start","startLeg":…,"startStep":…,"loops":{"review-plan":n,"review-pr":n,"verify-ui":n},"ui":bool,"size":"Bounded"|"Architectural","manifest":<path as given>,"worktree":…,"noOpen":bool,"checks":<this checks dir>}` | `{"action":"done"}` | `{"action":"halt","reason":…}`. `noOpen` is true when `PIPELINE_NO_OPEN` is set to anything but `0`. `checks` is `__DIR__`, so a launch from this worktree points every step's `brief` at this worktree's code.
  - `php dispatch_cli.php brief <manifest> <leg> <step>` → the brief as Markdown, after writing `cursor: {leg, status: pending}`; or `{"action":"halt","reason":…}` with the manifest untouched.
  - `php dispatch_cli.php finish <manifest> <decision-json>` → writes `cursor: {leg, status: done}` for `{"action":"done"}`, else `cursor: {leg: decision.leg ?? cursor.leg, status: halted, reason}` and prints the decision it recorded.

Spec gap handled here (listed in the hand-off): the spec does not define "the PR in the expected state". This plan expects an open draft: a run never works on a ready PR (`gh pr ready --undo` comes first, as `/orchestrate` already does).

- [ ] **Step 1: Write the failing pure tests**

Append to `DispatchTest.php`:

```php
it('lists each leg\'s steps', function () {
    expect(pipeline_steps('review-plan'))->toBe(['review', 'resolve']);
    expect(pipeline_steps('review-pr'))->toBe(['review', 'resolve']);
    expect(pipeline_steps('implement'))->toBe(['run']);
});

it('counts the loop-backs so far per looping leg, and gives an unknown count the bound', function () {
    $ledger = [
        ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'outcome' => 'looped-back'],
        ['gate' => 'plan-approval', 'leg' => 'implement', 'cycle' => 2, 'outcome' => 'looped-back'],
        ['gate' => 'design-size', 'leg' => 'implement', 'outcome' => 'escalated'],
        ['gate' => 'verify-ui', 'cycle' => 1, 'outcome' => 'continued'],
        ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 'unknown', 'review' => 'r'],
    ];

    expect(pipeline_loop_counts($ledger))->toBe(['review-plan' => 2, 'review-pr' => 2, 'verify-ui' => 0]);
    expect(pipeline_loop_counts([]))->toBe(['review-plan' => 0, 'review-pr' => 0, 'verify-ui' => 0]);
});

it('refuses a step the ledger does not support', function () {
    $open = ['gate' => 'plan-approval', 'review' => 'r'];

    expect(pipeline_step_problem(dispatch_manifest('review-plan'), 'review-plan', 'review'))->toBeNull();
    expect(pipeline_step_problem(dispatch_manifest('review-plan', [$open]), 'review-plan', 'resolve'))->toBeNull();
    expect(pipeline_step_problem(dispatch_manifest('implement'), 'implement', 'run'))->toBeNull();
    expect(pipeline_step_problem(dispatch_manifest('review-plan'), 'review-plan', 'resolve'))->toBe('no open plan-approval review to resolve');
    expect(pipeline_step_problem(dispatch_manifest('review-plan', [$open]), 'review-plan', 'review'))->toBe('gate_ledger[0] is an open plan-approval review; resolve it first');
    expect(pipeline_step_problem(dispatch_manifest('implement'), 'implement', 'review'))->toBe('implement has no review step');
    expect(pipeline_step_problem(dispatch_manifest('implement'), 'sideways', 'run'))->toBe('sideways has no run step');
});

it('expects the run\'s PR to be an open draft', function () {
    expect(pipeline_pr_problem(7, ['state' => 'OPEN', 'isDraft' => true]))->toBeNull();
    expect(pipeline_pr_problem(7, ['state' => 'OPEN', 'isDraft' => false]))->toBe('PR #7 is not a draft; a run only works on a draft PR (`gh pr ready --undo 7` first)');
    expect(pipeline_pr_problem(7, ['state' => 'MERGED', 'isDraft' => false]))->toBe('PR #7 is merged');
    expect(pipeline_pr_problem(7, null))->toBe('PR #7 cannot be read');
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter="each leg's steps|loop-backs so far|does not support|open draft"`
Expected: FAIL with `Call to undefined function pipeline_steps()` (and the other three).

- [ ] **Step 3: Implement the pure functions**

In `dispatch.php`, add below `const PIPELINE_GATE_OF = …;`:

```php
const PIPELINE_LOOP_BOUND = 2;

/** @return list<string> */
function pipeline_steps(string $leg): array
{
    return in_array($leg, ['review-plan', 'review-pr'], true) ? ['review', 'resolve'] : ['run'];
}
```

Replace the body of `pipeline_step()` with:

```php
    if (pipeline_steps($leg) === ['run']) {
        return 'run';
    }

    return pipeline_open_entry($manifest['gate_ledger'] ?? [], pipeline_gate_of($leg)) === null ? 'review' : 'resolve';
```

In `pipeline_loop_back()`, replace

```php
    if ($loops > 2) {
        return pipeline_halt("{$gate}: loop-back bound exhausted, {$loops} loop-backs where 2 are allowed");
    }
```

with

```php
    if ($loops > PIPELINE_LOOP_BOUND) {
        return pipeline_halt("{$gate}: loop-back bound exhausted, {$loops} loop-backs where " . PIPELINE_LOOP_BOUND . ' are allowed');
    }
```

Append to `dispatch.php`:

```php
/**
 * Loop-backs so far per looping leg, which the workflow script counts on from (`launch`). An
 * `unknown` cycle gives that leg the bound: after a reconstruction no loop-back is allowed.
 *
 * @return array<string, int>
 */
function pipeline_loop_counts(array $ledger): array
{
    $counts = [];
    foreach (PIPELINE_GATE_OF as $leg => $gate) {
        $entries = array_filter($ledger, fn ($entry) => ($entry['gate'] ?? null) === $gate);
        $counts[$leg] = in_array('unknown', array_column($entries, 'cycle'), true)
            ? PIPELINE_LOOP_BOUND
            : count(array_filter($entries, fn ($entry) => ($entry['outcome'] ?? null) === 'looped-back'));
    }

    return $counts;
}

/** Why the ledger does not support running this step now (`brief`'s check), or null. */
function pipeline_step_problem(array $manifest, string $leg, string $step): ?string
{
    if (! in_array($leg, pipeline_legs(), true) || ! in_array($step, pipeline_steps($leg), true)) {
        return "{$leg} has no {$step} step";
    }
    $gate = pipeline_gate_of($leg);
    $open = pipeline_open_entry($manifest['gate_ledger'] ?? [], $gate);

    return match (true) {
        $step === 'resolve' && $open === null => "no open {$gate} review to resolve",
        $step === 'review' && $open !== null => "gate_ledger[{$open}] is an open {$gate} review; resolve it first",
        default => null,
    };
}

/** `manifest.md` §Invariant check: once a run has a PR, it is an open draft. `$view` is `gh pr view --json state,isDraft`. */
function pipeline_pr_problem(int|string $pr, ?array $view): ?string
{
    return match (true) {
        $view === null => "PR #{$pr} cannot be read",
        ($view['state'] ?? null) !== 'OPEN' => "PR #{$pr} is " . strtolower((string) ($view['state'] ?? 'unknown')),
        empty($view['isDraft']) => "PR #{$pr} is not a draft; a run only works on a draft PR (`gh pr ready --undo {$pr}` first)",
        default => null,
    };
}
```

- [ ] **Step 4: Run the pipeline suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS.

- [ ] **Step 5: Write the failing CLI tests**

In `DispatchCliTest.php`, replace `dispatch_cli()` with (a fixed `PIPELINE_NO_OPEN` so the runner's environment cannot leak into `launch`, and the raw stdout for `brief`):

```php
function dispatch_cli(array $arguments, array $env = []): array
{
    $process = proc_open(
        [PHP_BINARY, __DIR__ . '/../dispatch_cli.php', ...$arguments],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        [...getenv(), 'PIPELINE_NO_OPEN' => '0', ...$env],
    );
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($process), 'json' => json_decode($stdout, true), 'stdout' => $stdout];
}

function dispatch_ui_diff(): string
{
    return "+++ b/resources/views/x.blade.php\n@@ -1,0 +1,1 @@\n+<div>hi</div>\n";
}
```

Append:

```php
it('launches from the cursor with the ledger\'s loop-backs, the design size and ui', function () {
    $looped = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r', 'outcome' => 'looped-back'];
    $open = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 2, 'at' => '2026-09-22T11:00:00Z', 'review' => 'r'];
    $fixture = dispatch_fixture(['gate_ledger' => [$looped, $open], 'artifacts' => ['spec' => 'spec.md', 'plan' => null, 'pr' => null, 'issue' => null]]);
    file_put_contents($fixture['dir'] . '/spec.md', "# x — design\n\n**Design size:** Bounded\n");
    file_put_contents($fixture['diff'], dispatch_ui_diff());

    expect(dispatch_cli(['launch', $fixture['manifest'], $fixture['diff']])['json'])->toBe([
        'action' => 'start',
        'startLeg' => 'review-plan',
        'startStep' => 'resolve',
        'loops' => ['review-plan' => 1, 'review-pr' => 0, 'verify-ui' => 0],
        'ui' => true,
        'size' => 'Bounded',
        'manifest' => $fixture['manifest'],
        'worktree' => $fixture['dir'],
        'noOpen' => false,
        'checks' => realpath(__DIR__ . '/..'),
    ]);
    expect(manifest_read($fixture['manifest'])['cursor'])->toBe(['leg' => 'review-plan', 'status' => 'pending']);
});

it('marks noOpen when the launch runs unattended', function () {
    $fixture = dispatch_fixture();

    expect(dispatch_cli(['launch', $fixture['manifest'], $fixture['diff']], ['PIPELINE_NO_OPEN' => '1'])['json']['noOpen'])->toBeTrue();
});

it('answers done for a finished run, and re-arms it with --from through the navigation guardrail', function () {
    $passed = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r', 'outcome' => 'continued'];
    $reviewed = [...$passed, 'gate' => 'pr-review', 'leg' => 'review-pr', 'at' => '2026-09-22T11:00:00Z'];
    $fixture = dispatch_fixture(['cursor' => ['leg' => 'review-pr', 'status' => 'done'], 'gate_ledger' => [$passed, $reviewed]]);

    expect(dispatch_cli(['launch', $fixture['manifest'], $fixture['diff']])['json'])->toBe(['action' => 'done']);

    expect(dispatch_cli(['launch', $fixture['manifest'], $fixture['diff'], '--from', 'review-pr'])['json'])
        ->toMatchArray(['action' => 'start', 'startLeg' => 'review-pr', 'startStep' => 'review']);
    expect(manifest_read($fixture['manifest'])['cursor'])->toBe(['leg' => 'review-pr', 'status' => 'pending']);

    $fresh = dispatch_fixture(['cursor' => ['leg' => 'design', 'status' => 'pending']]);
    $refused = dispatch_cli(['launch', $fresh['manifest'], $fresh['diff'], '--from', 'implement'])['json'];
    expect($refused['action'])->toBe('halt');
    expect($refused['reason'])->toContain('cannot re-arm the run at implement');
    expect(manifest_read($fresh['manifest'])['cursor'])->toBe(['leg' => 'design', 'status' => 'pending']);
});

it('halts a launch whose recorded spec is missing at last_sha, and records it', function () {
    $repo = suite_repo();
    $manifest = $repo . '/.claude/pipeline/feature-x.json';
    manifest_write($manifest, [
        'branch' => 'feature/x', 'worktree' => $repo, 'mode' => 'auto',
        'cursor' => ['leg' => 'review-plan', 'status' => 'pending'],
        'artifacts' => ['spec' => 'docs/spec.md', 'plan' => null, 'pr' => null, 'issue' => null],
        'last_sha' => pipeline_git($repo, ['rev-parse', 'HEAD']), 'gate_ledger' => [],
    ]);
    file_put_contents($repo . '/pipeline.diff', '');

    $halted = dispatch_cli(['launch', $manifest, $repo . '/pipeline.diff'])['json'];
    expect($halted['action'])->toBe('halt');
    expect($halted['reason'])->toContain('the recorded spec docs/spec.md does not exist at');
    expect(manifest_read($manifest)['cursor'])->toMatchArray(['leg' => 'review-plan', 'status' => 'halted']);

    mkdir($repo . '/docs');
    file_put_contents($repo . '/docs/spec.md', "# x — design\n");
    pipeline_git($repo, ['add', 'docs/spec.md']);
    pipeline_git($repo, ['commit', '-qm', 'spec']);
    manifest_write($manifest, [...manifest_read($manifest), 'cursor' => ['leg' => 'review-plan', 'status' => 'pending'], 'last_sha' => pipeline_git($repo, ['rev-parse', 'HEAD'])]);

    expect(dispatch_cli(['launch', $manifest, $repo . '/pipeline.diff'])['json']['action'])->toBe('start');
});

it('halts a launch without a manifest or a diff file', function () {
    $fixture = dispatch_fixture();

    expect(dispatch_cli(['launch', '/nonexistent/m.json', $fixture['diff']])['json']['action'])->toBe('halt');
    expect(dispatch_cli(['launch', $fixture['manifest'], $fixture['dir'] . '/missing.diff'])['json']['action'])->toBe('halt');
});

it('prints the brief for the step it is given and records that step as running', function () {
    $open = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r'];
    $fixture = dispatch_fixture(['cursor' => ['leg' => 'design', 'status' => 'halted', 'reason' => 'x'], 'gate_ledger' => [$open]]);

    $result = dispatch_cli(['brief', $fixture['manifest'], 'review-plan', 'resolve']);

    expect($result['code'])->toBe(0);
    expect($result['stdout'])
        ->toContain('`review-plan` leg, `resolve` step')
        ->toContain('the open review: `gate_ledger[0]`')
        ->toContain('as your structured result');
    expect(manifest_read($fixture['manifest'])['cursor'])->toBe(['leg' => 'review-plan', 'status' => 'pending']);
});

it('halts a step the ledger does not support, and leaves the manifest alone', function () {
    $fixture = dispatch_fixture(['cursor' => ['leg' => 'review-plan', 'status' => 'continued']]);

    expect(dispatch_cli(['brief', $fixture['manifest'], 'review-plan', 'resolve'])['json'])
        ->toBe(['action' => 'halt', 'reason' => 'no open plan-approval review to resolve']);
    expect(manifest_read($fixture['manifest'])['cursor'])->toBe(['leg' => 'review-plan', 'status' => 'continued']);
});

it('records the workflow\'s return with finish', function (string $decision, array $cursor) {
    $fixture = dispatch_fixture(['cursor' => ['leg' => 'implement', 'status' => 'pending']]);

    expect(dispatch_cli(['finish', $fixture['manifest'], $decision])['code'])->toBe(0);
    expect(manifest_read($fixture['manifest'])['cursor'])->toBe($cursor);
})->with([
    'done' => ['{"action":"done"}', ['leg' => 'implement', 'status' => 'done']],
    'a halt naming its leg' => ['{"action":"halt","leg":"verify-ui","reason":"stub halt"}', ['leg' => 'verify-ui', 'status' => 'halted', 'reason' => 'stub halt']],
    'a halt from the invoking session' => ['{"action":"halt","reason":"the workflow errored"}', ['leg' => 'implement', 'status' => 'halted', 'reason' => 'the workflow errored']],
    'no decision' => ['not json', ['leg' => 'implement', 'status' => 'halted', 'reason' => 'the workflow returned no decision: not json']],
]);
```

(`suite_repo()` is defined in `SuiteTest.php`.)

- [ ] **Step 6: Run them to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='launch|noOpen|re-arms|prints the brief|does not support, and leaves|finish'`
Expected: FAIL — every new command is a usage error (exit 1, `json` null).

- [ ] **Step 7: Implement the commands**

In `dispatch_cli.php`, replace the file docblock with:

```php
/**
 * The pipeline's commands (`../references/engine.md` §The loop).
 *
 *   interactive:  php dispatch_cli.php next <manifest>
 *                 php dispatch_cli.php returned <manifest> <diff-file>
 *   auto:         php dispatch_cli.php launch <manifest> <diff-file> [--from <leg>]
 *                 php dispatch_cli.php brief <manifest> <leg> <step>
 *                 php dispatch_cli.php finish <manifest> <decision-json>
 *
 * `brief` prints the brief as Markdown; every other answer, and a `brief` that halts, is one JSON
 * line. Exits 0 on every decision, a halt included. Exits 1 on a usage error.
 */
```

Add `require_once __DIR__ . '/suite.php';` after the other `require_once` lines.

Replace `dispatch_cli_next()` with:

```php
function dispatch_cli_next(string $manifestPath): array
{
    $manifest = manifest_read($manifestPath);
    if ($manifest === null) {
        return pipeline_halt("no readable manifest at {$manifestPath}");
    }
    if (dispatch_cli_finished($manifest)) {
        return ['action' => 'done'];
    }
    $invalid = dispatch_cli_invalid($manifest);
    if ($invalid !== null) {
        return pipeline_halt($invalid);
    }

    return dispatch_cli_emit($manifestPath, [...$manifest, 'cursor' => ['leg' => $manifest['cursor']['leg'], 'status' => 'pending']]);
}

function dispatch_cli_invalid(array $manifest): ?string
{
    $missing = manifest_validate($manifest);
    if ($missing !== []) {
        return 'the manifest is invalid: missing ' . implode(', ', $missing);
    }

    return in_array($manifest['cursor']['leg'] ?? null, pipeline_legs(), true) ? null : 'the manifest is invalid: cursor.leg is not a leg';
}
```

Add, after `dispatch_cli_design_size()`:

```php
/** What the run needs once, at its start (`../references/engine.md` §The loop): the workflow script's `args`. */
function dispatch_cli_launch(string $manifestPath, string $diffPath, ?string $from): array
{
    $manifest = manifest_read($manifestPath);
    if ($manifest === null || ! is_file($diffPath)) {
        return pipeline_halt("cannot launch: the manifest {$manifestPath} or the diff file {$diffPath} is missing");
    }
    $triggers = pipeline_triggers((string) file_get_contents($diffPath));

    if ($from !== null) {
        if (! pipeline_can_navigate((string) ($manifest['cursor']['leg'] ?? ''), $from, pipeline_done_legs($manifest['gate_ledger'] ?? []), $triggers)) {
            return pipeline_halt("cannot re-arm the run at {$from}: a gate before it has not run");
        }
        $manifest = [...$manifest, 'cursor' => ['leg' => $from, 'status' => 'pending']];
        manifest_write($manifestPath, $manifest);
    }
    if (dispatch_cli_finished($manifest)) {
        return ['action' => 'done'];
    }
    $invalid = dispatch_cli_invalid($manifest);
    if ($invalid !== null) {
        return pipeline_halt($invalid);
    }
    $leg = $manifest['cursor']['leg'];
    $problem = dispatch_cli_invariant_problem($manifest);
    if ($problem !== null) {
        return dispatch_cli_halt($manifestPath, $manifest, $leg, $problem);
    }

    return [
        'action' => 'start',
        'startLeg' => $leg,
        'startStep' => pipeline_step($manifest, $leg),
        'loops' => pipeline_loop_counts($manifest['gate_ledger'] ?? []),
        'ui' => $triggers['ui'],
        'size' => dispatch_cli_design_size($manifest)->value,
        'manifest' => $manifestPath,
        'worktree' => $manifest['worktree'],
        'noOpen' => ! in_array((string) getenv('PIPELINE_NO_OPEN'), ['', '0'], true),
        'checks' => __DIR__,
    ];
}

/** `../references/manifest.md` §Invariant check, once per launch. */
function dispatch_cli_invariant_problem(array $manifest): ?string
{
    $worktree = rtrim($manifest['worktree'], '/');
    $sha = $manifest['last_sha'] ?? null;
    foreach (['spec', 'plan'] as $name) {
        $path = $manifest['artifacts'][$name] ?? null;
        if ($sha === null || $path === null) {
            continue;
        }
        $relative = str_starts_with($path, "{$worktree}/") ? substr($path, strlen($worktree) + 1) : $path;
        if (pipeline_git_run($worktree, ['cat-file', '-e', "{$sha}:{$relative}"])[0] !== 0) {
            return "the recorded {$name} {$path} does not exist at {$sha}";
        }
    }
    $pr = $manifest['artifacts']['pr'] ?? null;

    return $pr === null ? null : pipeline_pr_problem($pr, dispatch_cli_pr_view($worktree, $pr));
}

/** `gh pr view` from the worktree, or null when gh cannot read the PR. */
function dispatch_cli_pr_view(string $worktree, int|string $pr): ?array
{
    $process = proc_open(['gh', 'pr', 'view', (string) $pr, '--json', 'state,isDraft'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $worktree);
    if (! is_resource($process)) {
        return null;
    }
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $view = proc_close($process) === 0 ? json_decode($out, true) : null;

    return is_array($view) ? $view : null;
}

/** An `auto` step's first command: the step the script chose becomes the cursor, then its brief. */
function dispatch_cli_brief(string $manifestPath, string $leg, string $step): array|string
{
    $manifest = manifest_read($manifestPath);
    if ($manifest === null) {
        return pipeline_halt("no readable manifest at {$manifestPath}");
    }
    $problem = dispatch_cli_invalid($manifest) ?? pipeline_step_problem($manifest, $leg, $step);
    if ($problem !== null) {
        return pipeline_halt($problem);
    }
    $manifest = [...$manifest, 'cursor' => ['leg' => $leg, 'status' => 'pending']];
    manifest_write($manifestPath, $manifest);

    return pipeline_brief($manifest, $leg, $manifestPath, $step);
}

/** Records the workflow's return; anything that is not `done` is a halt, and a halt with no reason says so. */
function dispatch_cli_finish(string $manifestPath, string $decisionJson): array
{
    $manifest = manifest_read($manifestPath);
    if ($manifest === null) {
        return pipeline_halt("no readable manifest at {$manifestPath}");
    }
    $decision = json_decode($decisionJson, true);
    $decision = is_array($decision) ? $decision : [];
    if (($decision['action'] ?? null) === 'done') {
        return dispatch_cli_done($manifestPath, $manifest);
    }
    $reason = trim((string) ($decision['reason'] ?? ''));

    return dispatch_cli_halt(
        $manifestPath,
        $manifest,
        (string) ($decision['leg'] ?? $manifest['cursor']['leg']),
        $reason === '' ? "the workflow returned no decision: {$decisionJson}" : $reason,
    );
}
```

Replace the tail of the file (from `$result = match` to the end) with:

```php
$flag = array_search('--from', $argv, true);
$result = match ($argv[1] ?? '') {
    'next' => dispatch_cli_next((string) ($argv[2] ?? '')),
    'returned' => dispatch_cli_returned((string) ($argv[2] ?? ''), (string) ($argv[3] ?? '')),
    'launch' => dispatch_cli_launch((string) ($argv[2] ?? ''), (string) ($argv[3] ?? ''), $flag === false ? null : (string) ($argv[$flag + 1] ?? '')),
    'brief' => dispatch_cli_brief((string) ($argv[2] ?? ''), (string) ($argv[3] ?? ''), (string) ($argv[4] ?? '')),
    'finish' => dispatch_cli_finish((string) ($argv[2] ?? ''), (string) ($argv[3] ?? '')),
    default => null,
};

if ($result === null) {
    fwrite(STDERR, "usage: dispatch_cli.php next <manifest> | returned <manifest> <diff-file> | launch <manifest> <diff-file> [--from <leg>] | brief <manifest> <leg> <step> | finish <manifest> <decision-json>\n");
    exit(1);
}

echo is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
exit(0);
```

- [ ] **Step 8: Run the pipeline suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, the existing `next` / `returned` tests included.

- [ ] **Step 9: Commit**

```bash
git add skills/pipeline/checks/dispatch.php skills/pipeline/checks/dispatch_cli.php skills/pipeline/checks/tests/DispatchTest.php skills/pipeline/checks/tests/DispatchCliTest.php
git commit -m "pipeline: dispatch_cli launch, brief and finish for the auto workflow"
```

---

### Task 4: `run_cost.php` + `run_cost_cli.php`; `engine_peak*` goes (spec step 5, first half)

**Files:**
- Create: `skills/pipeline/checks/run_cost.php`, `skills/pipeline/checks/run_cost_cli.php`
- Create: `skills/pipeline/checks/tests/RunCostTest.php`
- Delete: `skills/pipeline/checks/engine_peak.php`, `skills/pipeline/checks/engine_peak_cli.php`, `skills/pipeline/checks/tests/EnginePeakTest.php`
- Modify: `skills/pipeline/checks/tests/Pest.php` (load `run_cost.php` instead of `engine_peak.php`)

**Interfaces:**
- Produces (run_cost.php, pure): `PIPELINE_COST_WEIGHTS`; `pipeline_transcript_usage(string $jsonl): list<array>`; `pipeline_call_cost(array $usage): float`; `pipeline_call_context(array $usage): int`; `pipeline_transcript_cost(string $jsonl): array{calls: int, cost: float, peak: int}`; `pipeline_run_journal(string $jsonl): list<array{agent: string, label: string, result: mixed}>` in the order the steps started; `pipeline_run_cost_lines(list<array{label: string, calls: int, cost: float, peak: int}> $steps): list<string>`.
- Produces (CLI): `php run_cost_cli.php <run transcript dir>` → one line per step, then `run: <M> weighted over <n> steps; largest step peak <k> (<label>)`; exit 0 always. The transcript dir is `~/.claude/projects/<project>/<session>/subagents/workflows/wf_<id>/` (it holds `journal.jsonl` and `agent-<id>.jsonl` per step).
- Produces (tests, reused by Task 5): `cost_call()`, `cost_run(array $agents): string` (a transcript dir), `checks_cli(string $script, array $arguments): array{code: int, stdout: string}` in `RunCostTest.php`.

Spec gap handled here (listed in the hand-off): the spec names no input for `run_cost_cli.php`; it takes the run's transcript directory, which the Workflow result names.

- [ ] **Step 1: Write the failing tests**

Create `skills/pipeline/checks/tests/RunCostTest.php`:

```php
<?php

function cost_call(string $id, int $input, int $write, int $read, int $output = 50, ?array $split = null): string
{
    $usage = ['input_tokens' => $input, 'cache_creation_input_tokens' => $write, 'cache_read_input_tokens' => $read, 'output_tokens' => $output];
    if ($split !== null) {
        $usage['cache_creation'] = $split;
    }

    return json_encode(['type' => 'assistant', 'message' => ['id' => $id, 'role' => 'assistant', 'usage' => $usage]]);
}

/** A workflow run's transcript dir: a journal naming each agent's label and result, and each agent's transcript. */
function cost_run(array $agents): string
{
    $dir = sys_get_temp_dir() . '/pipeline-run-' . uniqid() . '/wf_abc-123';
    mkdir($dir, 0777, true);
    $journal = ['{"type":"launched"}'];
    foreach ($agents as $id => [$label, $jsonl, $result]) {
        $journal[] = json_encode(['type' => 'started', 'key' => "k{$id}", 'agentId' => $id, 'label' => $label, 'phase' => explode(':', $label)[0]]);
        if ($result !== null) {
            $journal[] = json_encode(['type' => 'result', 'key' => "k{$id}", 'agentId' => $id, 'result' => $result]);
        }
        file_put_contents("{$dir}/agent-{$id}.jsonl", $jsonl);
    }
    file_put_contents("{$dir}/journal.jsonl", implode("\n", $journal) . "\n");

    return $dir;
}

function checks_cli(string $script, array $arguments): array
{
    $process = proc_open([PHP_BINARY, __DIR__ . "/../{$script}", ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($process), 'stdout' => trim($stdout)];
}

it('weighs each call as the token audit does, once per message id', function () {
    $cost = pipeline_transcript_cost(implode("\n", [
        cost_call('m1', 3, 20000, 0),
        cost_call('m1', 3, 20000, 0),
        cost_call('m2', 1, 1000, 140000, 50, ['ephemeral_5m_input_tokens' => 0, 'ephemeral_1h_input_tokens' => 1000]),
        '{"type":"user","message":{"role":"user","content":"hi"}}',
        'not json',
        '',
    ]));

    // m1: 3 + 20000 × 1.25 (no split: all 5m) + 50 × 5 = 25253; m2: 1 + 1000 × 2 + 140000 × 0.1 + 50 × 5 = 16251
    expect($cost['calls'])->toBe(2);
    expect($cost['cost'])->toEqualWithDelta(41504, 0.001);
    expect($cost['peak'])->toBe(141001);
    expect(pipeline_transcript_cost(''))->toBe(['calls' => 0, 'cost' => 0.0, 'peak' => 0]);
});

it('reads a run\'s steps from its journal, in the order they started', function () {
    $journal = implode("\n", [
        '{"type":"launched"}',
        '{"type":"started","key":"k1","agentId":"a1","label":"review-plan:review","phase":"review-plan"}',
        '{"type":"started","key":"k2","agentId":"a2","label":"review-plan:review","phase":"review-plan"}',
        '{"type":"result","key":"k2","agentId":"a2","result":{"status":"continued"}}',
        'not json',
    ]);

    expect(pipeline_run_journal($journal))->toBe([
        ['agent' => 'a1', 'label' => 'review-plan:review', 'result' => null],
        ['agent' => 'a2', 'label' => 'review-plan:review', 'result' => ['status' => 'continued']],
    ]);
});

it('prints the cost per step and the largest step peak, always exiting 0', function () {
    $dir = cost_run([
        'a1' => ['review-plan:review', implode("\n", [cost_call('m1', 3, 20000, 0), cost_call('m2', 1, 1000, 140000, 50, ['ephemeral_5m_input_tokens' => 0, 'ephemeral_1h_input_tokens' => 1000])]), ['status' => 'continued']],
        'a2' => ['implement:run', cost_call('m3', 0, 0, 300000, 2000), ['status' => 'continued', 'ui' => false]],
    ]);

    expect(checks_cli('run_cost_cli.php', [$dir]))->toBe(['code' => 0, 'stdout' => implode("\n", [
        'review-plan:review: 0.04M over 2 calls, peak 141k',
        'implement:run: 0.04M over 1 calls, peak 300k',
        'run: 0.08M weighted over 2 steps; largest step peak 300k (implement:run)',
    ])]);
    expect(checks_cli('run_cost_cli.php', ['/nonexistent']))->toBe(['code' => 0, 'stdout' => 'run: not measured (no step transcripts)']);
    expect(checks_cli('run_cost_cli.php', [])['code'])->toBe(0);
});
```

In `tests/Pest.php`, replace `'engine_peak.php'` in the file list with `'run_cost.php'`.

- [ ] **Step 2: Run them to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='token audit does|from its journal|cost per step'`
Expected: FAIL with `Call to undefined function pipeline_transcript_cost()` / `pipeline_run_journal()`, and a non-matching CLI result (no such file).

- [ ] **Step 3: Implement**

Create `skills/pipeline/checks/run_cost.php`:

```php
<?php

/**
 * What one `/pipeline auto` run cost (spec 2026-09-23 §Measurement), weighted as the 2026-09-22 token
 * audit weighs usage (`usage.py`). Assistant messages are deduplicated by `message.id`, the last
 * occurrence winning; context per call = input + cache writes + cache reads.
 */

const PIPELINE_COST_WEIGHTS = ['input' => 1.0, 'write5m' => 1.25, 'write1h' => 2.0, 'read' => 0.1, 'output' => 5.0];

/** @return list<array> one usage block per API call */
function pipeline_transcript_usage(string $jsonl): array
{
    $usage = [];
    foreach (explode("\n", $jsonl) as $line) {
        $entry = json_decode($line, true);
        $message = is_array($entry) ? ($entry['message'] ?? null) : null;
        if (! is_array($message) || ($message['role'] ?? null) !== 'assistant' || empty($message['usage'])) {
            continue;
        }
        $usage[$message['id'] ?? $entry['uuid'] ?? count($usage)] = $message['usage'];
    }

    return array_values($usage);
}

/** Writes without a 5m/1h split count as 5m writes, as `usage.py` counts them. */
function pipeline_call_cost(array $usage): float
{
    $split = $usage['cache_creation'] ?? [];
    $weights = PIPELINE_COST_WEIGHTS;

    return ($usage['input_tokens'] ?? 0) * $weights['input']
        + ($split === [] ? ($usage['cache_creation_input_tokens'] ?? 0) : ($split['ephemeral_5m_input_tokens'] ?? 0)) * $weights['write5m']
        + ($split['ephemeral_1h_input_tokens'] ?? 0) * $weights['write1h']
        + ($usage['cache_read_input_tokens'] ?? 0) * $weights['read']
        + ($usage['output_tokens'] ?? 0) * $weights['output'];
}

function pipeline_call_context(array $usage): int
{
    return ($usage['input_tokens'] ?? 0) + ($usage['cache_creation_input_tokens'] ?? 0) + ($usage['cache_read_input_tokens'] ?? 0);
}

/** @return array{calls: int, cost: float, peak: int} */
function pipeline_transcript_cost(string $jsonl): array
{
    $usage = pipeline_transcript_usage($jsonl);

    return [
        'calls' => count($usage),
        'cost' => (float) array_sum(array_map('pipeline_call_cost', $usage)),
        'peak' => $usage === [] ? 0 : max(array_map('pipeline_call_context', $usage)),
    ];
}

/**
 * The steps a workflow run started, in order, from its `journal.jsonl`: `started` names an agent's
 * label, `result` its return value. A step that never returned has a null result.
 *
 * @return list<array{agent: string, label: string, result: mixed}>
 */
function pipeline_run_journal(string $jsonl): array
{
    $steps = [];
    foreach (explode("\n", $jsonl) as $line) {
        $entry = json_decode($line, true);
        $agent = is_array($entry) ? ($entry['agentId'] ?? null) : null;
        if ($agent === null) {
            continue;
        }
        if (($entry['type'] ?? null) === 'started') {
            $steps[$agent] = ['agent' => $agent, 'label' => (string) ($entry['label'] ?? $agent), 'result' => null];
        }
        if (($entry['type'] ?? null) === 'result' && isset($steps[$agent])) {
            $steps[$agent]['result'] = $entry['result'] ?? null;
        }
    }

    return array_values($steps);
}

/** @param list<array{label: string, calls: int, cost: float, peak: int}> $steps */
function pipeline_run_cost_lines(array $steps): array
{
    if ($steps === []) {
        return ['run: not measured (no step transcripts)'];
    }
    $largest = array_reduce($steps, fn (?array $carry, array $step) => $carry === null || $step['peak'] > $carry['peak'] ? $step : $carry);

    return [
        ...array_map(fn (array $step) => sprintf('%s: %.2fM over %d calls, peak %dk', $step['label'], $step['cost'] / 1e6, $step['calls'], intdiv($step['peak'], 1000)), $steps),
        sprintf('run: %.2fM weighted over %d steps; largest step peak %dk (%s)', array_sum(array_column($steps, 'cost')) / 1e6, count($steps), intdiv($largest['peak'], 1000), $largest['label']),
    ];
}
```

Create `skills/pipeline/checks/run_cost_cli.php`:

```php
<?php

/**
 * After a `/pipeline auto` run: its weighted cost per step and the largest step peak.
 *
 *   php run_cost_cli.php <run transcript dir>
 *
 * The dir is `~/.claude/projects/<project>/<session>/subagents/workflows/wf_<id>/`, named in the
 * Workflow result. Always exits 0: cost is reported, never a halt.
 */

require_once __DIR__ . '/run_cost.php';

$dir = rtrim((string) ($argv[1] ?? ''), '/');
$read = fn (string $path) => is_file($path) ? (string) file_get_contents($path) : '';

$steps = array_map(
    fn (array $step) => ['label' => $step['label'], ...pipeline_transcript_cost($read("{$dir}/agent-{$step['agent']}.jsonl"))],
    pipeline_run_journal($read("{$dir}/journal.jsonl")),
);

echo implode("\n", pipeline_run_cost_lines($steps)), "\n";
exit(0);
```

Delete the old invariant:

```bash
git rm skills/pipeline/checks/engine_peak.php skills/pipeline/checks/engine_peak_cli.php skills/pipeline/checks/tests/EnginePeakTest.php
```

- [ ] **Step 4: Run the pipeline suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS (EnginePeakTest is gone; RunCostTest passes).

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/run_cost.php skills/pipeline/checks/run_cost_cli.php skills/pipeline/checks/tests/RunCostTest.php skills/pipeline/checks/tests/Pest.php
git commit -m "pipeline: run_cost measures a workflow run per step; the engine peak invariant goes"
```

---

### Task 5: `run_audit.php` (spec step 5, second half)

**Files:**
- Create: `skills/pipeline/checks/run_audit.php` (a CLI with its functions, like `dispatch_cli.php`; not loaded by `Pest.php`)
- Create: `skills/pipeline/checks/tests/RunAuditTest.php`

**Interfaces:**
- Consumes: `pipeline_run_journal()` (Task 4), `pipeline_triggers()`, `pipeline_leg_of_gate()`, `pipeline_is_plan_gap()`, `manifest_read()`; test helpers `cost_run()` and `checks_cli()` from `RunCostTest.php`.
- Produces: `php run_audit.php <manifest> <final PR diff> <run transcript dir>` → five lines, exit 0 always:
  - `ui: the final diff touches|does not touch the UI, the ledger has a|no verify-ui entry — agree|MISMATCH`
  - one line each for `review-plan`, `verify-ui`, `review-pr`, `plan gaps`: `<name>: the steps reported [<statuses or legs>], the ledger's newest entries say [<…>] — agree|MISMATCH`. For a gate, the run's resolve (or `verify-ui`) statuses `continued` / `looped-back` are compared with that many of the gate's newest ledger outcomes; for `plan gaps`, the legs that returned `plan-insufficient` with the legs of the newest `design-size` escalations and plan gaps.
  - With a missing input: `run audit: not performed (usage: run_audit.php <manifest> <final PR diff> <run transcript dir>; each must exist)`.

Spec gap handled here (listed in the hand-off): the spec's `run_audit.php <manifest> <diff>` cannot compare "each step's reported status against the ledger it left" without the statuses; they live in the run's `journal.jsonl`, so the audit takes the transcript dir as a third argument.

- [ ] **Step 1: Write the failing tests**

Create `skills/pipeline/checks/tests/RunAuditTest.php`:

```php
<?php

/** A finished run: its manifest with this ledger, the final diff, and a transcript dir whose journal holds these step returns. */
function audit_run(array $ledger, string $diff, array $reports): array
{
    $agents = [];
    foreach ($reports as $index => [$label, $result]) {
        $agents["a{$index}"] = [$label, '', $result];
    }
    $dir = cost_run($agents);
    $manifest = dirname($dir) . '/feature-x.json';
    manifest_write($manifest, ['branch' => 'feature/x', 'worktree' => dirname($dir), 'mode' => 'auto', 'cursor' => ['leg' => 'review-pr', 'status' => 'done'], 'gate_ledger' => $ledger]);
    file_put_contents(dirname($dir) . '/final.diff', $diff);

    return checks_cli('run_audit.php', [$manifest, dirname($dir) . '/final.diff', $dir]);
}

$entry = fn (string $gate, string $leg, int $cycle, string $outcome) => ['gate' => $gate, 'leg' => $leg, 'cycle' => $cycle, 'at' => "2026-09-23T0{$cycle}:00:00Z", 'review' => 'r', 'outcome' => $outcome];
$chain = [
    ['design:run', ['status' => 'continued']],
    ['review-plan:review', ['status' => 'continued']],
    ['review-plan:resolve', ['status' => 'looped-back']],
    ['design:run', ['status' => 'continued']],
    ['review-plan:review', ['status' => 'continued']],
    ['review-plan:resolve', ['status' => 'continued']],
    ['handoff:run', ['status' => 'continued']],
    ['implement:run', ['status' => 'continued', 'ui' => false]],
    ['review-pr:review', ['status' => 'continued']],
    ['review-pr:resolve', ['status' => 'continued']],
];
$uiDiff = "+++ b/resources/views/x.blade.php\n@@ -1,0 +1,1 @@\n+<div>hi</div>\n";

it('reports a run whose ledger agrees with every step', function () use ($entry, $chain) {
    $ledger = [$entry('plan-approval', 'review-plan', 1, 'looped-back'), $entry('plan-approval', 'review-plan', 2, 'continued'), $entry('pr-review', 'review-pr', 1, 'continued')];

    expect(audit_run($ledger, '', $chain))->toBe(['code' => 0, 'stdout' => implode("\n", [
        'ui: the final diff does not touch the UI, the ledger has no verify-ui entry — agree',
        "review-plan: the steps reported [looped-back, continued], the ledger's newest entries say [looped-back, continued] — agree",
        "verify-ui: the steps reported [], the ledger's newest entries say [] — agree",
        "review-pr: the steps reported [continued], the ledger's newest entries say [continued] — agree",
        "plan gaps: the steps reported [], the ledger's newest entries say [] — agree",
    ])]);
});

it('flags a UI diff without a verify-ui entry, and a gate whose ledger says otherwise', function () use ($entry, $chain, $uiDiff) {
    $ledger = [$entry('plan-approval', 'review-plan', 1, 'looped-back'), $entry('plan-approval', 'review-plan', 2, 'continued'), $entry('pr-review', 'review-pr', 1, 'looped-back')];
    $lines = explode("\n", audit_run($ledger, $uiDiff, $chain)['stdout']);

    expect($lines[0])->toBe('ui: the final diff touches the UI, the ledger has no verify-ui entry — MISMATCH');
    expect($lines[3])->toBe("review-pr: the steps reported [continued], the ledger's newest entries say [looped-back] — MISMATCH");
});

it('compares a reported plan gap with the ledger\'s newest gap', function () use ($entry) {
    $reports = [['implement:run', ['status' => 'plan-insufficient', 'reason' => 'needs a queue', 'ui' => false]], ['design:run', null]];
    $gap = ['gate' => 'plan-approval', 'leg' => 'implement', 'cycle' => 2, 'at' => '2026-09-23T05:00:00Z', 'reason' => 'needs a queue', 'outcome' => 'looped-back'];

    expect(explode("\n", audit_run([$entry('plan-approval', 'review-plan', 1, 'continued'), $gap], '', $reports)['stdout'])[4])
        ->toBe("plan gaps: the steps reported [implement], the ledger's newest entries say [implement] — agree");
    expect(explode("\n", audit_run([$entry('plan-approval', 'review-plan', 1, 'continued')], '', $reports)['stdout'])[4])
        ->toBe("plan gaps: the steps reported [implement], the ledger's newest entries say [] — MISMATCH");
});

it('says so when an input is missing, and exits 0', function () {
    expect(checks_cli('run_audit.php', ['/nonexistent/m.json', '/nonexistent/d.diff', '/nonexistent/wf_x']))
        ->toBe(['code' => 0, 'stdout' => 'run audit: not performed (usage: run_audit.php <manifest> <final PR diff> <run transcript dir>; each must exist)']);
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='ledger agrees|verify-ui entry, and a gate|reported plan gap|input is missing'`
Expected: FAIL — `run_audit.php` does not exist (non-zero exit, empty stdout).

- [ ] **Step 3: Implement**

Create `skills/pipeline/checks/run_audit.php`:

```php
<?php

/**
 * After a `/pipeline auto` run: the two things the workflow takes on report (spec 2026-09-23 §No check
 * on what a step reports), as facts. A MISMATCH is the trigger for adding a check, never a halt.
 *
 *   php run_audit.php <manifest> <final PR diff> <run transcript dir>
 *
 * Always exits 0.
 */

require_once __DIR__ . '/triggers.php';
require_once __DIR__ . '/pipeline.php';
require_once __DIR__ . '/manifest.php';
require_once __DIR__ . '/dispatch.php';
require_once __DIR__ . '/run_cost.php';

/** `implement` reported `ui`; the final diff and the ledger say whether `verify-ui` should have run and did. */
function run_audit_ui(array $triggers, array $ledger): string
{
    $ran = array_filter($ledger, fn ($entry) => ($entry['gate'] ?? null) === 'verify-ui') !== [];

    return sprintf(
        'ui: the final diff %s the UI, the ledger has %s verify-ui entry — %s',
        $triggers['ui'] ? 'touches' : 'does not touch',
        $ran ? 'a' : 'no',
        $triggers['ui'] === $ran ? 'agree' : 'MISMATCH',
    );
}

/**
 * Per gate, what the run's steps reported against that many of the gate's newest ledger entries, so
 * the entries of an earlier run on the same manifest are never compared.
 *
 * @param list<array{label: string, result: mixed}> $steps
 * @return list<string>
 */
function run_audit_gates(array $steps, array $ledger): array
{
    $reported = ['review-plan' => [], 'verify-ui' => [], 'review-pr' => [], 'plan gaps' => []];
    foreach ($steps as $step) {
        [$leg, $name] = explode(':', $step['label'], 2) + [1 => ''];
        $status = is_array($step['result']) ? ($step['result']['status'] ?? null) : null;
        if ($status === 'plan-insufficient') {
            $reported['plan gaps'][] = $leg;
        } elseif (in_array($status, ['continued', 'looped-back'], true) && ($name === 'resolve' || $leg === 'verify-ui')) {
            $reported[$leg][] = $status;
        }
    }

    $recorded = ['review-plan' => [], 'verify-ui' => [], 'review-pr' => [], 'plan gaps' => []];
    foreach ($ledger as $entry) {
        $outcome = $entry['outcome'] ?? null;
        $leg = pipeline_leg_of_gate((string) ($entry['gate'] ?? ''));
        if ($outcome === 'escalated' || pipeline_is_plan_gap($entry)) {
            $recorded['plan gaps'][] = (string) ($entry['leg'] ?? '');
        } elseif ($leg !== null && in_array($outcome, ['continued', 'looped-back'], true)) {
            $recorded[$leg][] = $outcome;
        }
    }

    return array_map(
        fn (string $name) => run_audit_line($name, $reported[$name], $reported[$name] === [] ? [] : array_slice($recorded[$name], -count($reported[$name]))),
        array_keys($reported),
    );
}

function run_audit_line(string $name, array $reported, array $recorded): string
{
    return sprintf(
        "%s: the steps reported [%s], the ledger's newest entries say [%s] — %s",
        $name,
        implode(', ', $reported),
        implode(', ', $recorded),
        $reported === $recorded ? 'agree' : 'MISMATCH',
    );
}

$read = fn (string $path) => is_file($path) ? (string) file_get_contents($path) : null;
$manifest = manifest_read((string) ($argv[1] ?? ''));
$diff = $read((string) ($argv[2] ?? ''));
$journal = $read(rtrim((string) ($argv[3] ?? ''), '/') . '/journal.jsonl');

if ($manifest === null || $diff === null || $journal === null) {
    echo "run audit: not performed (usage: run_audit.php <manifest> <final PR diff> <run transcript dir>; each must exist)\n";
    exit(0);
}

$ledger = $manifest['gate_ledger'] ?? [];
echo implode("\n", [run_audit_ui(pipeline_triggers($diff), $ledger), ...run_audit_gates(pipeline_run_journal($journal), $ledger)]), "\n";
exit(0);
```

- [ ] **Step 4: Run the pipeline suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/run_audit.php skills/pipeline/checks/tests/RunAuditTest.php
git commit -m "pipeline: run_audit reports what an auto run's steps said against the ledger they left"
```

---

### Task 5A: `autoflow` beside `auto` in code (Amendment A)

**Files:**
- Modify: `skills/pipeline/checks/brief.php` (the three `=== 'auto'` checks become `=== 'autoflow'`; docblocks say `autoflow`)
- Modify: `skills/pipeline/checks/dispatch_cli.php` (`launch` halts unless the manifest's mode is `autoflow`; `next` halts on an `autoflow` manifest)
- Restore from `2524cf5`: `skills/pipeline/checks/engine_peak.php`, `engine_peak_cli.php`, `tests/EnginePeakTest.php`; `tests/Pest.php` loads `engine_peak.php` again beside `run_cost.php`
- Test: `BriefTest.php`, `DispatchCliTest.php`, `RunAuditTest.php` (fixture mode only)

**Interfaces:**
- Produces: `pipeline_leg_overrides('autoflow')` carries the lines Task 1 gave `'auto'`; `pipeline_leg_overrides('auto') === pipeline_leg_overrides('interactive')`. `pipeline_brief_return(…, 'autoflow')` asks for the structured result; `'auto'` and `'interactive'` ask for one line. `launch` on a non-`autoflow` manifest → `{"action":"halt","reason":"launch starts autoflow runs; this run's mode is <mode> (resume it with /pipeline, which uses next)"}`, manifest untouched. `next` on an `autoflow` manifest → `{"action":"halt","reason":"an autoflow run resumes with launch, not next"}`, manifest untouched. `brief` and `finish` accept any mode (the script and the invoking session call them only for `autoflow`).

- [ ] **Step 1: Tests first.** In `BriefTest.php`, every test that expects the lines Task 1 added for `auto` (`has an auto reviewer apply /critique itself…`, `drops the independent read and the subagents in auto`, `leaves the PR draft at the auto finish step…`, `tells every auto step where to work…`, `tells a resolve step to loop back…` where it asserts an auto-only line, `has overrides for every leg and step`) builds its fixture with `'mode' => 'autoflow'` instead of relying on `brief_manifest()`'s default, and says `autoflow` in its name. Add:

```php
it('briefs an auto run exactly as an interactive one: the dispatcher's steps can dispatch', function () {
    expect(pipeline_leg_overrides('auto'))->toBe(pipeline_leg_overrides('interactive'));
    expect(pipeline_brief_return('implement', 'run', 'auto'))->toBe(pipeline_brief_return('implement', 'run', 'interactive'));
    expect(pipeline_brief(brief_manifest('implement'), 'implement', '/tmp/m.json'))
        ->not->toContain('The owner authorised this run')
        ->not->toContain('`cd /tmp/wt`')
        ->toContain('and reply with one line naming it');
});
```

In `DispatchCliTest.php`, the `launch` / `brief` / `finish` tests build fixtures with `'mode' => 'autoflow'` (the `brief` test asserts `as your structured result`, which only `autoflow` prints), and add:

```php
it('launches only autoflow runs, and next refuses one', function () {
    $auto = dispatch_fixture();
    expect(dispatch_cli(['launch', $auto['manifest'], $auto['diff']])['json'])
        ->toBe(['action' => 'halt', 'reason' => "launch starts autoflow runs; this run's mode is auto (resume it with /pipeline, which uses next)"]);
    expect(manifest_read($auto['manifest'])['cursor'])->toBe(['leg' => 'review-plan', 'status' => 'pending']);

    $flow = dispatch_fixture(['mode' => 'autoflow']);
    expect(dispatch_cli(['next', $flow['manifest']])['json'])
        ->toBe(['action' => 'halt', 'reason' => 'an autoflow run resumes with launch, not next']);
    expect(is_file($flow['brief']))->toBeFalse();
});
```

In `RunAuditTest.php`, `audit_run()` writes `'mode' => 'autoflow'`.

Restore `EnginePeakTest.php` from `2524cf5` (`git checkout 2524cf5 -- skills/pipeline/checks/tests/EnginePeakTest.php`). Run the pipeline suite: expect the new and renamed tests red (and EnginePeakTest erroring on the missing functions).

- [ ] **Step 2: Implement.** `git checkout 2524cf5 -- skills/pipeline/checks/engine_peak.php skills/pipeline/checks/engine_peak_cli.php`; add `'engine_peak.php'` back to `tests/Pest.php`'s list (keep `'run_cost.php'`). In `brief.php` replace the three `'auto'` comparisons with `'autoflow'` and the docblock's "An `auto` step" with "An `autoflow` step". In `dispatch_cli.php`: in `dispatch_cli_launch()`, directly after the manifest/diff readability check and before `--from`, return `pipeline_halt("launch starts autoflow runs; this run's mode is {$manifest['mode']} (resume it with /pipeline, which uses next)")` when `($manifest['mode'] ?? null) !== 'autoflow'`; in `dispatch_cli_next()`, after the readability check, return `pipeline_halt('an autoflow run resumes with launch, not next')` for an `autoflow` manifest. Update the file docblock's `auto:` label to `autoflow:`.

- [ ] **Step 3: Run the pipeline suite.** All green, EnginePeakTest included.

- [ ] **Step 4: Commit**

```bash
git add skills/pipeline/checks/brief.php skills/pipeline/checks/dispatch_cli.php skills/pipeline/checks/engine_peak.php skills/pipeline/checks/engine_peak_cli.php skills/pipeline/checks/tests/EnginePeakTest.php skills/pipeline/checks/tests/Pest.php skills/pipeline/checks/tests/BriefTest.php skills/pipeline/checks/tests/DispatchCliTest.php skills/pipeline/checks/tests/RunAuditTest.php
git commit -m "pipeline: autoflow runs beside auto; the dispatcher and its invariant stay for auto"
```

---

### Task 6: `workflow/pipeline-auto.js` and its smoke run (spec step 6)

**Files:**
- Create: `skills/pipeline/workflow/pipeline-auto.js`
- Scratch (not committed): `$TMPDIR/pipeline-smoke.sh` and the smoke dirs it creates

**Interfaces:**
- Consumes: `launch`'s JSON as `args` (Task 3): `startLeg`, `startStep`, `loops`, `ui`, `size`, `manifest`, `worktree`, `noOpen`, `checks`; `brief` (Task 3); `LegStatus::allowedFor` after Task 2.
- Produces: the saved workflow `pipeline-auto`; returns `{action: 'done'}` or `{action: 'halt', leg, reason}` (what `finish` records). Agent labels are `<leg>:<step>` (what `run_cost_cli.php` and `run_audit.php` read). `args.stub` — `{prompt: string, steps: {'<leg>:<step>': [<return object> | {throw: true}, …]}}` — is set by the smoke run only.

**Why stubs through `args.stub`:** about a dozen lines keep the script under test byte-identical to the one that ships and still run the real `brief` (cursor writes, ledger halts) in every step, which trivial stub legs against real `brief` output would need anyway; a missing stub halts, so a smoke run can never fall through to a real leg.

Spec points decided here (listed in the hand-off): `ui` is required in `implement`'s schema, not optional — `launch` computes `ui` from the diff before `implement` has run (false on a fresh run), so an `implement` that omitted it would skip `verify-ui`. And the smoke run's "thrown schema error" is provoked with an unsatisfiable schema (`required` outside `properties`, which `agent()` rejects by throwing), because step 1 found a five-times schema failure could not be provoked; it proves the `catch`, not the five-failure path.

- [ ] **Step 1: Write the script**

Create `skills/pipeline/workflow/pipeline-auto.js`:

```js
export const meta = {
  name: 'pipeline-auto',
  description: 'Drive a /pipeline auto run from its cursor: one fresh agent per step; the order, loop-backs, bounds and halts in code',
  whenToUse: 'Started by the pipeline skill (and orchestrate) with the JSON that dispatch_cli.php launch printed as args',
  phases: [
    { title: 'design' },
    { title: 'review-plan' },
    { title: 'handoff' },
    { title: 'implement' },
    { title: 'verify-ui' },
    { title: 'review-pr' },
  ],
}

// Repeated from pipeline.php (pipeline_legs, pipeline_next_leg) and dispatch.php (pipeline_loop_target,
// LegStatus::allowedFor, PIPELINE_LOOP_BOUND), which interactive mode uses. The smoke run in
// docs/superpowers/plans/2026-09-23-pipeline-auto-workflow.md (Task 6) is this script's test.
const LEGS = ['design', 'review-plan', 'handoff', 'implement', 'verify-ui', 'review-pr']
const STEPS = { 'review-plan': ['review', 'resolve'], 'review-pr': ['review', 'resolve'] }
const LOOP_TARGET = { 'review-plan': 'design', 'verify-ui': 'implement', 'review-pr': 'implement' }
const ALLOWED = {
  'design:run': ['continued', 'halted'],
  review: ['continued', 'halted', 'plan-insufficient'],
  resolve: ['continued', 'looped-back', 'halted'],
  'verify-ui:run': ['continued', 'looped-back', 'halted', 'plan-insufficient'],
  run: ['continued', 'halted', 'plan-insufficient'],
}
const BOUND = 2
const UNSATISFIABLE = { type: 'object', properties: {}, required: ['status'] } // the smoke run's thrown error

const loops = { 'review-plan': 0, 'verify-ui': 0, 'review-pr': 0, ...args.loops }
let ui = args.ui

function nextLeg(leg) {
  return LEGS.slice(LEGS.indexOf(leg) + 1).find(next => next !== 'verify-ui' || ui)
}

function halt(leg, reason) {
  return { action: 'halt', leg, reason: reason || `the ${leg} step halted without a reason` }
}

function briefCommand(leg, step) {
  return `php ${args.checks}/dispatch_cli.php brief ${args.manifest} ${leg} ${step}`
}

function schemaFor(leg, step) {
  const properties = {
    status: { type: 'string', enum: ALLOWED[`${leg}:${step}`] ?? ALLOWED[step] },
    reason: { type: 'string' },
  }
  if (leg === 'implement') properties.ui = { type: 'boolean' }
  return { type: 'object', properties, required: leg === 'implement' ? ['status', 'ui'] : ['status'] }
}

function stepPrompt(leg, step) {
  const diff = args.manifest.replace(/\.json$/, '.diff')
  const lines = [
    `You are the \`${leg}\` leg, \`${step}\` step, of a /pipeline auto run.`,
    `1. Run \`${briefCommand(leg, step)}\` and follow the brief it prints; it is complete. If it prints {"action":"halt",…} instead, return status \`halted\` with its reason.`,
    '2. You cannot start agents. Where a skill or the brief would dispatch one, do that work yourself; where that is impossible, return `halted` with the reason.',
    "3. Finish as the brief's `## Return` says.",
  ]
  if (leg === 'implement') {
    lines.push(`4. After the last commit, run \`git -C ${args.worktree} diff origin/<base>...HEAD > ${diff}\` (<base>: the PR's base branch), then \`php -r 'require $argv[1]; echo json_encode(pipeline_triggers(file_get_contents($argv[2]))["ui"]);' ${args.checks}/triggers.php ${diff}\`, and return what it prints as \`ui\`: copy it, do not judge it.`)
  }
  if (leg === 'review-pr' && step === 'resolve') {
    lines.push(`4. Run the proof page's \`open\` as \`PIPELINE_NO_OPEN=${args.noOpen ? 1 : 0} php ${args.checks}/proof_cli.php open …\`.`)
  }
  return lines.join('\n')
}

function stubPrompt(leg, step, returns) {
  return [
    `SMOKE TEST: you stand in for the \`${leg}\` leg, \`${step}\` step, of a /pipeline auto run. Do no real work.`,
    `1. Run \`${briefCommand(leg, step)}\`. If it prints {"action":"halt",…}, return {"status":"halted","reason":<its reason>} and stop.`,
    args.stub.prompt,
    JSON.stringify(returns),
  ].join('\n')
}

async function runStep(leg, step) {
  const returns = args.stub?.steps[`${leg}:${step}`]?.shift()
  if (args.stub && !returns) return { status: 'halted', reason: `the smoke run has no stub for ${leg}:${step}` }
  const model = returns ? 'haiku' : step === 'review' ? 'fable' : undefined
  const opts = {
    label: `${leg}:${step}`,
    phase: leg,
    schema: returns?.throw ? UNSATISFIABLE : schemaFor(leg, step),
    ...(model ? { model } : {}),
    ...(leg === 'handoff' ? { effort: 'low' } : {}),
  }
  const prompt = returns ? stubPrompt(leg, step, returns) : stepPrompt(leg, step)
  try {
    const result = await agent(prompt, opts)
    const retried = result === null && model === 'fable' ? await agent(prompt, { ...opts, model: 'opus' }) : result
    return retried ?? { status: 'halted', reason: 'the agent returned nothing' }
  } catch (error) {
    return { status: 'halted', reason: `the agent failed: ${error?.message ?? error}` }
  }
}

let leg = args.startLeg
let from = args.startStep
while (leg) {
  const all = STEPS[leg] ?? ['run']
  const steps = from ? all.slice(all.indexOf(from)) : all
  from = undefined
  let result
  for (const step of steps) {
    result = await runStep(leg, step)
    log(`${leg}:${step} ${result.status}${result.reason ? `: ${result.reason}` : ''}`)
    if (result.status !== 'continued') break
  }
  if (result.status === 'halted') return halt(leg, result.reason)
  if (result.ui !== undefined) ui = result.ui
  if (result.status === 'continued') {
    leg = nextLeg(leg)
    continue
  }

  const gap = result.status === 'plan-insufficient'
  const target = gap ? 'design' : LOOP_TARGET[leg]
  if (!target) return halt(leg, `no loop-back from ${leg}`)
  const counted = !(gap && args.size === 'Bounded') // a Bounded escalation is not a loop-back
  const gate = gap ? 'review-plan' : leg
  if (counted && ++loops[gate] > BOUND) return halt(leg, `${gate}: loop-back bound exhausted`)
  leg = target
}
return { action: 'done' }
```

- [ ] **Step 2: Check the script's syntax**

The Workflow tool runs the body as an async function, so check it wrapped in one (skip when `node` is not installed; the first smoke run then fails fast on a syntax error):

```bash
{ echo 'async function workflow() {'; sed 's/^export const meta/const meta/' skills/pipeline/workflow/pipeline-auto.js; echo '}'; } > "$TMPDIR/pipeline-auto.check.js"
node --check "$TMPDIR/pipeline-auto.check.js"
```

Expected: no output.

- [ ] **Step 3: Create the throwaway manifests and launch each**

Write this to `$TMPDIR/pipeline-smoke.sh` and run `bash "$TMPDIR/pipeline-smoke.sh"` from the worktree root:

```bash
#!/usr/bin/env bash
set -euo pipefail
CHECKS="$PWD/skills/pipeline/checks"
SMOKE=$(cd "$(mktemp -d "${TMPDIR:-/tmp}/pipeline-smoke.XXXXXX")" && pwd -P)
looped1='{"gate":"plan-approval","leg":"review-plan","cycle":1,"at":"2026-09-23T00:00:00Z","review":"r","outcome":"looped-back"}'
looped2='{"gate":"plan-approval","leg":"review-plan","cycle":2,"at":"2026-09-23T00:01:00Z","review":"r","outcome":"looped-back"}'
openpr='{"gate":"pr-review","leg":"review-pr","cycle":1,"at":"2026-09-23T00:00:00Z","review":"r"}'

scenario() { # scenario <name> <cursor leg> <ledger JSON> <spec header, or empty>
  local dir="$SMOKE/$1" spec=null
  mkdir -p "$dir/.claude/pipeline"
  : > "$dir/.claude/pipeline/smoke.diff"
  if [ -n "$4" ]; then printf '# smoke — design\n\n%s\n' "$4" > "$dir/spec.md"; spec='"spec.md"'; fi
  printf '{"branch":"smoke/%s","worktree":"%s","mode":"auto","cursor":{"leg":"%s","status":"pending"},"artifacts":{"spec":%s,"plan":null,"pr":null,"issue":null},"gate_ledger":%s}\n' \
    "$1" "$dir" "$2" "$spec" "$3" > "$dir/.claude/pipeline/smoke.json"
  printf '== %s\n' "$1"
  PIPELINE_NO_OPEN=1 php "$CHECKS/dispatch_cli.php" launch "$dir/.claude/pipeline/smoke.json" "$dir/.claude/pipeline/smoke.diff"
}

scenario a-done design '[]' ''
scenario b-gap-bound implement '[]' ''
scenario c-bounded handoff "[$looped1,$looped2]" '**Design size:** Bounded'
scenario d-verify-gap verify-ui '[]' ''
scenario e-review-gap review-pr '[]' ''
scenario f1-resolve-halt review-pr "[$openpr]" ''
scenario f2-review-halt review-plan '[]' ''
scenario f3-implement-halt implement '[]' ''
scenario f4-verify-halt verify-ui '[]' ''
scenario f5-pr-review-halt review-pr '[]' ''
scenario g-throw handoff '[]' ''
scenario h-brief-halt review-plan '[]' ''
printf 'SMOKE=%s\n' "$SMOKE"
```

Expected: twelve `{"action":"start",…}` lines, each with `"checks"` = this worktree's `skills/pipeline/checks`, `"noOpen":true`; `c-bounded` has `"size":"Bounded"` and `"loops":{"review-plan":2,…}`; `f1-resolve-halt` has `"startStep":"resolve"`; every other `startStep` is `review` or `run`. Note `SMOKE`.

- [ ] **Step 4: Start the twelve smoke runs**

For each scenario, call the Workflow tool with `scriptPath` = `/Users/jroelofs/GitProjects/LaravelClaudeMd/LaravelClaudeMd/.claude/worktrees/pipeline-dispatcher-loop/skills/pipeline/workflow/pipeline-auto.js` and `args` = that scenario's `launch` JSON (as a JSON object, not a string) plus a `stub` key. For `h-brief-halt`, also set `startStep` to `"resolve"` (a resolve step with no open review: `brief` must halt it). The runs are independent; start them in the background and in parallel.

`stub.prompt` is the same for every scenario:

```text
2. Do not follow the brief. Edit only the manifest file the brief names (JSON; keep every other key as it is):
   - a `review` step returning `continued`: append to `gate_ledger` {"gate": G, "leg": "<your leg>", "cycle": <the cycle the brief's Pointers name>, "at": "2026-09-23T00:00:00Z", "review": "stub"} with no `outcome`, where G is `plan-approval` on `review-plan` and `pr-review` on `review-pr`;
   - a `resolve` step returning `continued` or `looped-back`: on the entry the brief's Pointers call the open review, add "actions": [] and "outcome" equal to that status;
   - `verify-ui` returning `continued` or `looped-back`: append {"gate": "verify-ui", "cycle": 1, "at": "2026-09-23T00:00:00Z", "outcome": <that status>};
   - any step returning `plan-insufficient`: append {"gate": "plan-approval", "leg": "<your leg>", "cycle": 1, "at": "2026-09-23T00:00:00Z", "reason": "stub gap", "outcome": "looped-back"};
   - anything else: leave `gate_ledger` as it is.
   Then set `cursor.status` to the status below, and `cursor.reason` to its reason when it is `halted`. No git, no gh, no skills, no other file.
3. Your structured result is exactly this object:
```

`stub.steps` per scenario (`c` = `{"status":"continued"}`, `lb` = `{"status":"looped-back"}`, `h` = `{"status":"halted","reason":"stub halt"}`, `pi` = `{"status":"plan-insufficient","reason":"stub gap"}`; on `implement:run` every return also carries `"ui"`):

| Scenario | `stub.steps` | Expected labels, in order | Expected return |
|---|---|---|---|
| `a-done` | `design:run` [c, c]; `review-plan:review` [c, c]; `review-plan:resolve` [lb, c]; `handoff:run` [c]; `implement:run` [c+`"ui":true`, c+`"ui":true`, c+`"ui":false`]; `verify-ui:run` [lb, c]; `review-pr:review` [c, c]; `review-pr:resolve` [lb, c] | design:run, review-plan:review, review-plan:resolve, design:run, review-plan:review, review-plan:resolve, handoff:run, implement:run, verify-ui:run, implement:run, verify-ui:run, review-pr:review, review-pr:resolve, implement:run, review-pr:review, review-pr:resolve | `{"action":"done"}` |
| `b-gap-bound` | `implement:run` [pi+`"ui":false`, pi+`"ui":false`]; `design:run` [c, c]; `review-plan:review` [c, c]; `review-plan:resolve` [lb, c]; `handoff:run` [c] | implement:run, design:run, review-plan:review, review-plan:resolve, design:run, review-plan:review, review-plan:resolve, handoff:run, implement:run | `{"action":"halt","leg":"implement","reason":"review-plan: loop-back bound exhausted"}` |
| `c-bounded` | `handoff:run` [pi]; `design:run` [c, c]; `review-plan:review` [pi, c]; `review-plan:resolve` [h] | handoff:run, design:run, review-plan:review, design:run, review-plan:review, review-plan:resolve | `{"action":"halt","leg":"review-plan","reason":"stub halt"}` (without the exemption it would halt at `handoff:run` on the bound) |
| `d-verify-gap` | `verify-ui:run` [pi]; `design:run` [h] | verify-ui:run, design:run | `{"action":"halt","leg":"design","reason":"stub halt"}` |
| `e-review-gap` | `review-pr:review` [pi]; `design:run` [c]; `review-plan:review` [c]; `review-plan:resolve` [c]; `handoff:run` [h] | review-pr:review, design:run, review-plan:review, review-plan:resolve, handoff:run | `{"action":"halt","leg":"handoff","reason":"stub halt"}` |
| `f1-resolve-halt` | `review-pr:resolve` [h] | review-pr:resolve | `{"action":"halt","leg":"review-pr","reason":"stub halt"}` |
| `f2-review-halt` | `review-plan:review` [h] | review-plan:review | `{"action":"halt","leg":"review-plan","reason":"stub halt"}` |
| `f3-implement-halt` | `implement:run` [h+`"ui":false`] | implement:run | `{"action":"halt","leg":"implement","reason":"stub halt"}` |
| `f4-verify-halt` | `verify-ui:run` [h] | verify-ui:run | `{"action":"halt","leg":"verify-ui","reason":"stub halt"}` |
| `f5-pr-review-halt` | `review-pr:review` [h] | review-pr:review | `{"action":"halt","leg":"review-pr","reason":"stub halt"}` |
| `g-throw` | `handoff:run` [`{"throw":true}`] | handoff:run (or none, if `agent()` throws before starting one) | `{"action":"halt","leg":"handoff","reason":"the agent failed: …"}` |
| `h-brief-halt` | `review-plan:resolve` [c] | review-plan:resolve | `{"action":"halt","leg":"review-plan","reason":"no open plan-approval review to resolve"}` (the stub returns `brief`'s halt, not its scripted `continued`) |

Together these cover every `(leg, step, status)` pair the schemas allow, every loop-back target, `ui` on and off, the bound on Architectural plan gaps, the Bounded exemption at the bound, a thrown error, `brief`'s ledger halt, and `done`.

- [ ] **Step 5: Check each run against the table**

For each run, with `<dir>` the run's transcript dir from its Workflow result and `<scenario>` its directory under `$SMOKE`:

```bash
php -r 'require $argv[1] . "/run_cost.php"; foreach (pipeline_run_journal(file_get_contents($argv[2] . "/journal.jsonl")) as $s) { echo $s["label"], " ", json_encode($s["result"]), "\n"; }' skills/pipeline/checks "<dir>"
php skills/pipeline/checks/dispatch_cli.php finish "$SMOKE/<scenario>/.claude/pipeline/smoke.json" '<the run'"'"'s return as JSON>'
php -r 'echo json_encode(json_decode(file_get_contents($argv[1]), true)["cursor"]), "\n";' "$SMOKE/<scenario>/.claude/pipeline/smoke.json"
```

Expected: the labels in the table's order, the table's return, and after `finish` the cursor `{"leg":"review-pr","status":"done"}` for `a-done` and `{"leg":<the return's leg>,"status":"halted","reason":<its reason>}` for every other run.

For `a-done` also:

```bash
php -r '$l = json_decode(file_get_contents($argv[1]), true)["gate_ledger"]; foreach ($l as $e) { echo $e["gate"], " ", $e["outcome"] ?? "open", "\n"; }' "$SMOKE/a-done/.claude/pipeline/smoke.json"
php skills/pipeline/checks/run_cost_cli.php "<a-done's dir>"
php skills/pipeline/checks/run_audit.php "$SMOKE/a-done/.claude/pipeline/smoke.json" "$SMOKE/a-done/.claude/pipeline/smoke.diff" "<a-done's dir>"
```

Expected: ledger outcomes `plan-approval looped-back, plan-approval continued, verify-ui looped-back, verify-ui continued, pr-review looped-back, pr-review continued` (in that per-gate order, interleaved by time); `run_cost_cli` prints 16 step lines and a `run:` line; `run_audit` prints `ui: … does not touch the UI, the ledger has a verify-ui entry — MISMATCH` (expected: the smoke diff is empty while the stubs reported `ui: true`, which is exactly what the audit exists to show) and `agree` on the four gate lines.

Any deviation is a bug in the script (or in `brief`): fix it, rerun the affected scenario with a fresh `bash "$TMPDIR/pipeline-smoke.sh"`, and do not commit until all twelve match.

- [ ] **Step 6: Commit**

```bash
git add skills/pipeline/workflow/pipeline-auto.js
git commit -m "pipeline: the auto loop as the workflow script pipeline-auto"
```

Record the twelve outcomes (scenario, labels match yes/no, return, cursor after `finish`) for the PR body in Task 11.

---

### Task 7: Link skill workflow scripts into `~/.claude/workflows/` (spec step 7)

**Files:**
- Modify: `hooks/git-freshness.sh` (header comment, a `workflows_dir` setting, `link_new_workflows`, its call in `sync_config_repos`)
- Test: `hooks/tests/git-freshness-sync.test.sh`
- Modify: `README.md:70`, `CLAUDE.md` §Skills and hooks (one line each)

**Interfaces:**
- Produces: at session start, each `<config repo>/skills/*/workflow/*.js` with no entry in `${GIT_FRESHNESS_WORKFLOWS_DIR-$HOME/.claude/workflows}` is symlinked there under its file name (`pipeline-auto.js`); the dir is created when missing, never written through when it is itself a symlink, and an existing entry is never replaced. The tag is `linked new workflow <name without .js>`.

- [ ] **Step 1: Write the failing tests**

In `hooks/tests/git-freshness-sync.test.sh`, after `export GIT_FRESHNESS_SKILLS_DIR="$root/no-skills-dir"`, add:

```bash
export GIT_FRESHNESS_WORKFLOWS_DIR="$root/no-workflows-dir"
```

Before the final `echo "----------------------------------------"`, add:

```bash
echo "case 16: session start links a skill's workflow script into the workflows dir"
cfg=$(fixture config4 1 skills/flow/SKILL.md)
push_upstream config4 skills/flow/workflow/flow-auto.js "export const meta = {name: 'flow-auto', description: 'x'}"
push_upstream config4 skills/flow/workflow/taken.js "taken"
workflows="$root/config4/workflows"
mkdir -p "$workflows" "$root/config4/elsewhere"
ln -s "$root/config4/elsewhere/taken.js" "$workflows/taken.js"
payload="{\"session_id\":\"test-config4\",\"cwd\":\"$root/config4\"}"
out=$(printf '%s' "$payload" \
    | GIT_FRESHNESS_CONFIG_REPOS="$cfg" GIT_FRESHNESS_SKILLS_DIR="$root/config4/none" GIT_FRESHNESS_WORKFLOWS_DIR="$workflows" bash "$hook" session 2>/dev/null)
is "$(readlink "$workflows/flow-auto.js")" "$cfg/skills/flow/workflow/flow-auto.js" "the workflow script is linked under its file name"
is "$(readlink "$workflows/taken.js")" "$root/config4/elsewhere/taken.js" "an existing entry with the same name is left alone"
contains "$out" "linked new workflow flow-auto" "the new link is reported"
lacks "$out" "linked new workflow taken" "the collision is not reported as linked"
echo

echo "case 17: a missing workflows dir is created; one that is a symlink gets nothing"
cfg=$(fixture config5 1 skills/flow/workflow/flow-auto.js)
missing="$root/config5/new/workflows"
printf '%s' "{\"session_id\":\"test-config5a\",\"cwd\":\"$root/config5\"}" \
    | GIT_FRESHNESS_CONFIG_REPOS="$cfg" GIT_FRESHNESS_SKILLS_DIR="$root/config5/none" GIT_FRESHNESS_WORKFLOWS_DIR="$missing" bash "$hook" session >/dev/null 2>&1
is "$(readlink "$missing/flow-auto.js")" "$cfg/skills/flow/workflow/flow-auto.js" "the missing dir is created and the script linked"
mkdir -p "$root/config5/realflows"
ln -s "$root/config5/realflows" "$root/config5/flows-link"
printf '%s' "{\"session_id\":\"test-config5b\",\"cwd\":\"$root/config5\"}" \
    | GIT_FRESHNESS_CONFIG_REPOS="$cfg" GIT_FRESHNESS_SKILLS_DIR="$root/config5/none" GIT_FRESHNESS_WORKFLOWS_DIR="$root/config5/flows-link" bash "$hook" session >/dev/null 2>&1
if [ -e "$root/config5/realflows/flow-auto.js" ]; then fail "nothing written through a symlinked workflows dir"; else ok "nothing written through a symlinked workflows dir"; fi
echo
```

- [ ] **Step 2: Run the hook tests to verify they fail**

Run: `bash hooks/tests/git-freshness-sync.test.sh`
Expected: the cases 1–15 pass; cases 16 and 17 FAIL (no link, no tag), and the run ends with a non-zero failed count.

- [ ] **Step 3: Implement**

In `hooks/git-freshness.sh`, replace the header lines

```bash
# The config repos get one more: a skill that has no symlink in ~/.claude/skills
# yet is linked, so a new skill reaches every machine with its next session
# instead of waiting for a manual relink. An existing entry is never replaced.
```

with

```bash
# The config repos get one more: a skill that has no symlink in ~/.claude/skills
# yet is linked, and so is a skill's workflow script (skills/<skill>/workflow/*.js)
# that has none in ~/.claude/workflows, so both reach every machine with its next
# session instead of waiting for a manual relink. An existing entry is never replaced.
```

After `skills_dir="${GIT_FRESHNESS_SKILLS_DIR-$HOME/.claude/skills}"`, add:

```bash
workflows_dir="${GIT_FRESHNESS_WORKFLOWS_DIR-$HOME/.claude/workflows}"
```

After `link_new_skills()`, add:

```bash
# Link each workflow script a skill in repo $1 ships (skills/<skill>/workflow/*.js)
# that has no entry in the workflows dir yet, so a saved workflow loads by name in
# every project. Same rules as skills, except that a missing dir is created: it is
# ours alone, where the skills dir is set up by hand once per machine.
link_new_workflows() {
    local repo=$1 script name

    [ ! -L "$workflows_dir" ] && mkdir -p "$workflows_dir" 2>/dev/null || return 0

    for script in "$repo"/skills/*/workflow/*.js; do
        [ -f "$script" ] || continue
        name=$(basename "$script")
        { [ -e "$workflows_dir/$name" ] || [ -L "$workflows_dir/$name" ]; } && continue
        ln -s "$script" "$workflows_dir/$name" 2>/dev/null \
            && config_tags="${config_tags}${config_tags:+, }linked new workflow ${name%.js}"
    done
}
```

In `sync_config_repos()`, after `link_new_skills "$repo"`, add:

```bash
        link_new_workflows "$repo"
```

In `README.md`, replace

```
  links any skill that has no symlink yet, then checks the launch directory.
```

with

```
  links any skill that has no symlink yet (and any skill's `workflow/*.js` into
  `~/.claude/workflows/`), then checks the launch directory.
```

In `CLAUDE.md` §Skills and hooks, replace

```
in `~/.claude/skills/`. At session start `hooks/git-freshness.sh` fast-forwards both repos and links
any new skill, on each machine. Skill names must be unique across the two repos.
```

with

```
in `~/.claude/skills/`. At session start `hooks/git-freshness.sh` fast-forwards both repos and links
any new skill, and any skill's workflow script into `~/.claude/workflows/`, on each machine. Skill
names must be unique across the two repos.
```

- [ ] **Step 4: Run the hook tests**

Run: `bash hooks/tests/git-freshness-sync.test.sh && bash hooks/tests/vault-sync.test.sh`
Expected: both end with `N passed, 0 failed`.

- [ ] **Step 5: Commit**

```bash
git add hooks/git-freshness.sh hooks/tests/git-freshness-sync.test.sh README.md CLAUDE.md
git commit -m "git-freshness: link skill workflow scripts into ~/.claude/workflows"
```

---

### Task 8: Pipeline docs (spec step 8, pipeline half)

**Files:**
- Modify: `skills/pipeline/references/engine.md`, `skills/pipeline/references/manifest.md`, `skills/pipeline/references/gates.md`, `skills/pipeline/SKILL.md`

**Interfaces:**
- Consumes: the command shapes of Tasks 3–6 (quoted verbatim below).
- Produces: `LockStepTest` stays green (`manifest.md` §What a leg writes keeps every status and writable key; `gates.md` §Loop-backs keeps the three `` `leg` → `target` `` lines). No reference doc mentions the dispatcher agent, the 150k invariant or `engine_peak`.

Spec gap handled here (listed in the hand-off): spec step 8 names `engine.md` §The loop, §The dispatcher, §Failure policy and §Design size's plan gap, but these other `engine.md` passages also contradict the design and are corrected too: §Kickoff (what "the dispatcher" never looks up), §Stations (the review and finish rows), §Escalation (the resolve-step exception, "the dispatcher moves the cursor"), §The proof store (who opens the page after a halt), §Who takes the PR out of draft, §What a leg brief consists of, §Suite reuse (`getcwd()`), §`auto` (the independent read); and `manifest.md`'s `cursor` row.

- [ ] **Step 1: `engine.md` §The loop and §The dispatcher**

Replace everything from `## The loop` up to (not including) `## Interactive — the same loop, the human resolves` — that is §The loop and all of §The dispatcher — with:

~~~markdown
## The loop

`auto` and `interactive` walk the same legs with the same briefs. They differ in who holds the loop.

### `auto` — a program that calls agents

The loop is `../workflow/pipeline-auto.js`, the saved workflow `pipeline-auto`: the order of steps,
the loop-backs, their bounds and the halts are JavaScript, and agents exist only inside steps.

```
invoking session   kickoff → dispatch_cli.php launch → Workflow pipeline-auto (args: launch's JSON)
                   … on its return: dispatch_cli.php finish → gh pr ready | halt duties → report
workflow script    per step: agent(prompt, {schema}) → {status, reason, ui} → next step, loop-back or return
step agent         dispatch_cli.php brief <manifest> <leg> <step> → the leg's work → the manifest → {status, reason}
```

```bash
CHECKS="$HOME/.claude/skills/pipeline/checks"
git -C <worktree> diff origin/<base>...HEAD > "<manifest stem>.diff"
PIPELINE_NO_OPEN=<1 unattended, else 0> php "$CHECKS/dispatch_cli.php" launch <manifest> "<manifest stem>.diff" [--from <leg>]
# → {"action":"start","startLeg":…,"startStep":…,"loops":{…},"ui":…,"size":…,"manifest":…,"worktree":…,"noOpen":…,"checks":…}
#   | {"action":"done"} | {"action":"halt","reason":…}
# start: the workflow pipeline-auto with that JSON as args, in the background; wait for its completion notice
php "$CHECKS/dispatch_cli.php" finish <manifest> '<the workflow return, as JSON>'
```

The invoking session — the main session or `orchestrate` — is an agent only at the two edges, and runs
one tested command at each. Nothing reads a step's work in between; a halt lands in the session that
launched the run, with its reason.

- **`launch`** does once what the run needs at its start: `manifest_validate`, the finished rules (a
  cursor whose status is `done`, or the old engine's `leg: done`, answers `done`), the invariant check
  (`manifest.md` §Invariant check), the step to start at (`pipeline_step()`), the loop-backs so far
  per looping leg (`pipeline_loop_counts()`; an `unknown` cycle gives that gate the bound, which
  permits no loop-back), `ui` from the diff and the design size from the spec's header. `--from <leg>`
  re-arms a run at that leg through `pipeline_can_navigate()`: a PR that needs new commits gets a new
  run with `--from review-pr`, without editing a file. `checks` is the directory `launch` ran from, so
  every step's `brief` runs the same code.
- **The script** gives each step a schema whose `status` allows only what that step may return
  (`LegStatus::allowedFor()`), continues, loops back or returns on that status, counts each loop-back
  against the bound of 2 per gate (`gates.md` §Loop-backs), and returns `{action: done}` or
  `{action: halt, leg, reason}`. Nothing ends a run as `done` except `review-pr`'s resolve step
  continuing. A Bounded escalation is not a loop-back; on an Architectural spec every
  `plan-insufficient` counts toward `review-plan`'s bound. A review step runs on Fable, and once more on
  Opus when it returns nothing; `handoff` runs at low effort; a step that throws or returns nothing
  halts the run.
- **A step** first runs `dispatch_cli.php brief <manifest> <leg> <step>`. It writes
  `cursor: {leg, status: pending}` — so after a `TaskStop` or a dead session the cursor still names the
  step that was running — and prints the brief, or prints a halt when the ledger does not support the
  step (`resolve` with no open review, `review` with one already open). The step writes its results
  into the manifest (`manifest.md` §What a leg writes) and returns `{status, reason}`; `implement`
  also returns `ui`, copied from `pipeline_triggers()` over its diff.
- **`finish`** records the return: `done` sets `cursor.status: done`; a halt sets
  `cursor: {leg, status: halted, reason}`. When the workflow itself errored, pass
  `{"action":"halt","reason":"<the error>"}`: the cursor keeps the step that was running. On `done` the
  invoking session then runs **`gh pr ready <pr>`** (§Who takes the PR out of draft); on a halt after
  `handoff`, §Failure policy's duties.
- **Resume** is `/pipeline` as always: `launch` starts from the cursor, and the step it names runs
  again.

**No check on what a step reports.** The script trusts each step's `status`. A step that claims work it
did not do is caught by the next gate (`review-plan` reads the spec and plan, `review-pr` the code), and
`implement`'s `ui` is a copy of `pipeline_triggers()`' output, not a judgement. After every run the
invoking session reports two facts with the result:

```bash
php "$CHECKS/run_cost_cli.php" <the run's transcript dir>
git -C <worktree> diff origin/<base>...HEAD > "<manifest stem>.diff"
php "$CHECKS/run_audit.php" <manifest> "<manifest stem>.diff" <the run's transcript dir>
```

The transcript dir is `~/.claude/projects/<project>/<session>/subagents/workflows/wf_<id>/`, named in
the Workflow result. `run_cost_cli.php` prints the weighted cost per step and the largest step peak.
`run_audit.php` prints whether `ui` over the final diff agrees with a `verify-ui` entry, and whether
each gate's newest ledger entries agree with what the steps reported. A `MISMATCH` is the trigger for
adding a check on step returns, never a halt.

**Where a step works.** The worktree travels in the brief (*"Work only in `<worktree>`"*) and in
absolute paths, never in the launch directory: `orchestrate` launches up to four runs from its primary
checkout. Commands that key on the working directory (§Suite reuse's tree key) run from `cd <worktree>`
or with `git -C <worktree>`, as the `auto` brief says.

**What a workflow agent cannot do.** It cannot start agents, so no step dispatches one; its brief says
how each station's dispatch is done by the step itself. And the auto-mode classifier denies it
`gh pr ready`, so that write stays with the invoking session.

### `interactive` — `next` and `returned`

```
read manifest (or reconstruct it)          # manifest_read / manifest_infer_cursor
  → invariant check                         # recorded artifact at last_sha; PR as expected
  → dispatch_cli.php next                   # brief + snapshot → one dispatch line
  → the step                                # inline, or a fresh background agent; wait for its notice
  → dispatch_cli.php returned               # validate the manifest, route, write it
  → dispatch | retry | halt | done
```

```bash
CHECKS="$HOME/.claude/skills/pipeline/checks"
php "$CHECKS/dispatch_cli.php" next <manifest>                        # start or resume
git -C <worktree> diff origin/<base>...HEAD > "<manifest stem>.diff"
php "$CHECKS/dispatch_cli.php" returned <manifest> "<manifest stem>.diff"   # after every return
```

`<manifest stem>` is the manifest path without `.json`: the diff, the brief (`.brief.md`) and the
dispatch snapshot (`.before.json`) sit next to the manifest, one set per run, so concurrent runs
never share a diff file, and `.claude/pipeline/` keeps them out of git and out of §Suite reuse's key.

Each prints one JSON line. On `dispatch` or `retry`, pass its `prompt` — one line naming the brief
file `pipeline_brief()` wrote — to a background agent; when `inline` is true, run the step in this
session instead (§Interactive). On `halt`, stop (§Failure policy). On `done`, return.

**Wait for the completion notice.** No `sleep`, `date`, file-mtime or `ListAgents` polling while a
step runs.

**Control rule — fails closed:**

- **Every dispatched step is a fresh agent** briefed by `pipeline_brief()` (`../checks/brief.php`). It
  writes its results and a status into the manifest (`manifest.md` §What a leg writes) and replies with
  one line. The session never reads that reply for content: `returned` compares the manifest with the
  snapshot taken at dispatch and **halts** on anything it cannot account for.
- **Legs never pick the next leg and never write a brief.** `pipeline_returned()`
  (`../checks/dispatch.php`) routes: `continued` → the next step or leg (`pipeline_next_leg`),
  `looped-back` → `gates.md` §Loop-backs within the bound, `halted` → stop, `plan-insufficient` →
  grow a Bounded design, or loop an Architectural one back to `design` within `review-plan`'s bound
  (§Design size, *A plan gap on an Architectural design*).
- **Auto-continuation spans only dispatched steps.** The loop never tries to "become a skill inline
  and then regain control": a skill that tail-calls its successor (as `brainstorming` invokes
  `writing-plans`) would never return, so an inline auto-continuation would silently walk past the
  next gate. A lost step **halts the chain; it never skips a gate.**
~~~

In §Interactive (the section that follows), replace `Every other step is\ndispatched as in \`auto\`.` (the sentence "Every other step is dispatched as in `auto`.") with "Every other step is a fresh background agent given `next`'s prompt."

- [ ] **Step 2: `engine.md` §Kickoff, §Stations, §Design size**

§Kickoff: replace `**The first \`manifest_write\` carries everything the dispatcher will never look up.**` with `**The first \`manifest_write\` carries everything no step will look up.**`, and replace `The\ndispatcher never reads an artifact to recover any of these` (the sentence start "The dispatcher never reads an artifact to recover any of these") with "Neither `launch` nor a brief reads an artifact to recover any of these".

§Stations table, `review-plan` row, autonomous column: replace `two steps (\`pipeline_step\`): a **review** agent invokes \`/critique plan\` and appends the review verbatim as an open \`plan-approval\` entry; a fresh **resolve** agent acts on it (§\`auto\`)` with `two steps: a **review** agent applies \`/critique plan\`'s procedure itself (it cannot start a reviewer) and appends the review verbatim as an open \`plan-approval\` entry; a fresh **resolve** agent acts on it (§\`auto\`)`.

§Stations table, `review-pr` row, autonomous column: replace `a **review** agent invokes \`/critique pr\` and appends an open \`pr-review\` entry; the **finish** step (its resolve step) acts on it, runs the suite unless reused, reconciles closing links (§Closing links), rewrites the proof page, runs \`gh pr ready\`, and opens the page last (§The proof store)` with `a **review** agent applies \`/critique pr\`'s procedure itself and appends an open \`pr-review\` entry; the **finish** step (its resolve step) acts on it, runs the suite unless reused, reconciles closing links (§Closing links), rewrites the proof page and opens it last (§The proof store); after \`finish\` the invoking session runs \`gh pr ready\` (§Who takes the PR out of draft)`.

§Escalation: replace

```
- first thing in every later step.
```

with

```
- first thing in every later step, except a resolve step, which loops back instead (below).
```

replace

```
On escalation the step appends the `design-size` entry and returns `plan-insufficient`; the dispatcher
moves the cursor to `design` (`pipeline_returned()`), whose brief asks for the grow form.
```

with

```
On escalation the step appends the `design-size` entry and returns `plan-insufficient`; the run goes
back to `design` (the workflow script in `auto`, `pipeline_returned()` in `interactive`), whose brief
asks for the grow form.
```

and replace `2. The dispatcher moves the cursor back to \`design\`; backward navigation is always allowed.` with `2. The run goes back to \`design\`; backward navigation is always allowed.`

§A plan gap on an Architectural design: replace

```
2. The dispatcher sends the cursor to `design` through the same bound as a `review-plan` loop-back
   (`pipeline_loop_back()`): the entry counts toward the 2 cycles, and the third halts — before
```

with

```
2. The run goes back to `design` through the same bound as a `review-plan` loop-back (the script's
   `BOUND` in `auto`, `pipeline_loop_back()` in `interactive`): the entry counts toward the 2 cycles,
   and the third halts — before
```

and append at the end of that subsection (after the paragraph ending "…past the re-review."):

```markdown
**A resolve step never returns `plan-insufficient`.** A resolve step that finds a plan gap or a Bounded
escalation returns `looped-back`: its open entry is completed and no bound is charged twice. If
`implement` then finds the plan short, it reports the gap itself. **A review step that returns
`plan-insufficient` appends no review entry**, so an escalation found after the review was written
cannot leave a stale open review behind. In `auto` a resolve step's schema has no
`plan-insufficient`; in `interactive` `pipeline_returned()` halts on either.
```

- [ ] **Step 3: `engine.md` §The proof store, §Who takes the PR out of draft, §What a leg brief consists of, §Suite reuse, §`auto`**

§The proof store: replace `on the same rule: the dispatcher runs \`proof_cli.php open <artifacts.proof>\` when that pointer is set` with `on the same rule: after \`finish\`, the invoking session runs \`proof_cli.php open <artifacts.proof>\` when that pointer is set`.

§Who takes the PR out of draft: after its first paragraph (ending "…and neither does `verify-ui`."), insert:

```markdown
**In `auto` the finish step leaves it draft too.** The auto-mode classifier denies a workflow agent
`gh pr ready`, and it is the most consequential outward write a run makes, so it stays with the
session that answers to the owner: after the workflow returns `done` and `finish` records it, the
invoking session runs `gh pr ready <pr>`. The guarantee is unchanged: nothing marks the PR ready
before `review-pr`'s finish step has run.
```

§What a leg brief consists of: replace its first paragraph (from "Every brief is generated by" to "A brief consists of:") with:

```markdown
Every brief is generated by `pipeline_brief($manifest, $leg, $manifestPath, $step)`
(`../checks/brief.php`); nobody writes one by hand — not the invoking session, not a leg, not a
coordinator. In `auto` the step prints its own with `dispatch_cli.php brief`; in `interactive` `next`
writes it to `<manifest stem>.brief.md` and the prompt is one line naming that file. A brief consists of:
```

and insert after the bullet list (before "**A review step's brief is crafted context**"):

```markdown
**An `auto` brief adds what a workflow agent needs** (`pipeline_leg_overrides('auto')`): a review step
applies `/critique`'s procedure itself — Stage 0, Stage 1 and the rubric — because it cannot start the
reviewer, so `--verify` and `alternatives` are unavailable; `review-plan`'s resolve step has no
independent read; `implement` executes the plan inline, with no subagents; the finish step leaves the
PR draft; every step works from `cd <worktree>` and is told the owner authorised the run; and
`## Return` asks for a structured `{status, reason}` instead of a line. Interactive briefs are as they
were.
```

§Suite reuse: in the bash block, replace

```
MANIFEST=".claude/pipeline/<branch>.json"
TREE=$(php -r 'require $argv[1] . "/suite.php"; echo pipeline_tree_key(getcwd());' "$CHECKS")
```

with

```
MANIFEST="<the manifest path the brief names>"
TREE=$(php -r 'require $argv[1] . "/suite.php"; echo pipeline_tree_key($argv[2]);' "$CHECKS" "<worktree>")
```

§`auto`: replace `**Why a fresh agent, not the dispatcher.**` with `**Why a fresh agent, not the engine.**`; replace `**An independent read is available, and is not a routing rule.**` with `**In \`interactive\` an independent read is available, and is not a routing rule.**`; and append to that paragraph (after "…it only informs what the resolve step does next."): ` In \`auto\` there is none: a workflow agent cannot start one, and its brief says so.`

- [ ] **Step 4: `engine.md` §Failure policy**

Replace the section from `## Failure policy — what still stops` up to (not including) `## Navigation` with:

~~~markdown
## Failure policy — what still stops

Under `auto` these are the only stops. **No finding stops a run.** A halt reaches the invoking session
as the workflow's return; `finish` writes it to the cursor (`cursor.status: halted`, `cursor.reason`),
and a human resumes.

- **Hard failure** — a station errors: tests won't go green, a tool dies, the stack won't start,
  `work-on` hits a blocker, a step returns nothing, or a station would need an agent the step cannot
  start. → **halt.** **No silent retry** beyond the single ones below — a retry hides the failure and
  the machinery may be in an unknown state.
  - `auto`: a review step that returns nothing runs once more, on Opus; any other step that returns
    nothing, or throws, halts at once.
  - `interactive`: `returned` answers `retry` once for a review step that wrote nothing, then `halt`.
    A halted manifest there is the one the check rejected: when the reason names a key the leg was not
    allowed to change, repair it from `<manifest stem>.before.json`, the snapshot taken at dispatch,
    before the next `next`; otherwise the run resumes with the leg's change in place.
- **A Fable usage limit is not a hard failure.** In `auto` the review step runs on Fable; when it
  returns nothing — a usage limit in a background session — the script runs it once more on Opus (in an
  interactive session a usage limit pauses the workflow, which continues by itself). In `interactive`,
  `/critique` moves the reviewer to Opus itself (`../../critique/SKILL.md` §Stage 2); that switch is
  not the single retry above. A usage limit on the Opus run too is the hard failure: halt, with the
  reset time in the reason so the human knows when a resume can work.
- **Kickoff halts** (§The work item) — these fire *before* the worktree exists, so they leave
  nothing behind and there is no manifest yet to write to; report and stop.
  - **An open blocker** on the run's issue → halt in both modes. Which of wait / work around /
    pick the blocker up first applies is the human's call, not a finding to resolve.
  - **`pipeline_repo_board()` returns `invalid`** → machinery failure, same treatment as an
    `invalid` `## Checks` block. A board-less repo returns `absent` and is unaffected.
- **Bound exhaustion.** Each loop-back is bounded to **2 cycles** per gate; on what would be the
  third, **halt** — stop, leave the work in the worktree, and say why. The bound is what keeps an
  autonomous loop from churning indefinitely without ever surfacing.
  - **Before `handoff`** (`review-plan`) → **no branch push, no draft PR.** Twice-rejected work is
    not worth a PR round-trip; the human reads it live.
  - **After `handoff`** (`verify-ui`, `review-pr`) → the draft PR already exists, so there is
    nothing to not-push. After `finish`, the invoking session leaves it **draft**, appends the reason
    to the PR body without reading it (`gh pr view <pr> --json body --jq .body > "$TMPDIR/body.md"`,
    append the reason, `gh pr edit <pr> --body-file "$TMPDIR/body.md"`), opens the proof page once
    when `artifacts.proof` is set (§The proof store), and stops.
  - The entry is not marked halted: the resolve step records `outcome: looped-back` as it returns,
    and the run halts through the cursor.
  - The count is never an in-memory guess. In `auto` the script starts from `launch`'s per-gate counts
    and adds each loop-back it routes; in `interactive` `pipeline_returned()` counts. Both count a
    gate's `gate_ledger` entries whose `outcome` is **`looped-back`** (`manifest.md`) — not its entries
    in total, which also include human-ordered re-reviews and would over-count into a spurious stop.
  - **A count that cannot be read is not a count of zero.** The ledger lives in the disposable
    manifest, and no durable probe can rebuild it: git and gh record *that* a review happened, not
    how many times the run looped. So a run whose manifest was **reconstructed** (`manifest.md`
    §reconstruction) carries an **unknown** cycle count, and unknown permits **no** loop-back — the
    next one halts immediately (`launch` gives such a gate the bound). Without this, a manifest lost
    mid-loop silently grants two fresh cycles, and one lost repeatedly grants them forever: the bound
    would stop bounding at exactly the moment it is load-bearing. A fresh run writes its own manifest
    at kickoff and is never reconstructed, so it is unaffected.
- **Mechanical-check exhaustion** (§Mechanical checks) — a check failure that survives its 2 fix
  attempts, or more than two `@phpstan-ignore` suppressions in one run → **the same
  bound-exhaustion halt.**
- **A return that cannot be accounted for.** `auto`: a status the step may not return fails the
  step's schema, and `brief` halts a step the ledger does not support. `interactive`: a moved cursor,
  a key only `returned` writes, a rewritten ledger entry, a status the ledger does not support
  (`manifest.md` §What a leg writes) → **halt**, with `returned`'s reason.
- **A stopped workflow** — `TaskStop`, a dead session, a workflow error: `finish` with
  `{"action":"halt","reason":…}` records it; the cursor names the step that was running.
- **Playwright genuinely unavailable** → **halt.** No visual claim without proof.

In `interactive` mode every gate stops anyway, so the human sees the review and none of the `auto`
resolution runs.

The scary content facts — a migration, an authorization change, a shared package — **do not stop
the chain**: they are facts, not findings, so they become loud mandatory annotations on the PR and
in the ledger (`gates.md` §content triggers). `verify-ui` is untouched by that and stays mandatory
whenever the `ui` trigger fires.
~~~

- [ ] **Step 5: `manifest.md`**

In §Fields, replace the `cursor` row's purpose cell with:

```
`{leg, status, reason?, retried?}` — the current leg; `status` is `pending` (written by `next`, or by `brief` as an `auto` step starts), the status the leg returned, `halted` (with `reason`), or `done` (written by `returned` or `finish` on a finished run; `next` and `launch` then answer `done`); `retried` only after a review step's single retry in `interactive`
```

In §What a leg writes, replace the paragraph from "A leg writes only its results" to "…when the status does not agree with the ledger." with:

```markdown
A leg writes only its results: `artifacts`, `last_sha`, `suite`, its `gate_ledger` entry, and
`cursor.status` — plus `cursor.reason` when it halts. It never moves `cursor.leg` and never writes a
brief. In `interactive`, after every return `returned` compares the manifest with its snapshot
(`pipeline_returned()`, `../checks/dispatch.php`) and **halts** when any other key changed, when an
existing ledger entry was rewritten (the resolve step may only complete the open entry), or when the
status does not agree with the ledger. In `auto` nothing compares: the workflow script trusts the
status the step returns, `brief` halts a step the ledger does not support, and `run_audit.php` reports
after the run whether the ledger agrees with what the steps reported (`engine.md` §The loop).
```

and replace the `plan-insufficient` row's meaning cell with:

```
the plan does not cover what the change needs. Bounded: a `design-size` entry with `outcome: escalated` is appended and the design grows. Architectural: a `plan-approval` entry with `outcome: looped-back` is appended and the run loops back to `design` within `review-plan`'s bound (`engine.md` §Design size). Never from a resolve step, which returns `looped-back` instead; a review step that returns it appends no review entry
```

Replace §Invariant check (heading through "…not a silent retry.") with:

```markdown
## Invariant check — once per launch

Before a run continues, confirm the file still matches reality:

- the recorded artifact (spec/plan) exists at the recorded ref (`last_sha`),
- the PR, once there is one, is an open draft.

In `auto`, `dispatch_cli.php launch` runs it once per launch, not per leg, and halts with the mismatch
in `cursor.reason`. In `interactive` the session checks it when it resumes a run. **Mismatch → halt**,
do not trust the file. A halt is a human resume point, not a silent retry.
```

- [ ] **Step 6: `gates.md`**

In §Loop-backs, replace

```
and so does any loop-back once the count is `unknown` (`manifest.md` §Reconstruction). The dispatcher
evaluates both in `pipeline_returned()`. Keep this list in lock-step with the function; `LockStepTest`
fails when they drift.
```

with

```
and so does any loop-back once the count is `unknown` (`manifest.md` §Reconstruction). In `auto` the
workflow script evaluates both (`LOOP_TARGET` and `BOUND` in `../workflow/pipeline-auto.js`, starting
from `launch`'s ledger counts); in `interactive`, `pipeline_returned()`. Keep this list in lock-step
with the function and the script: `LockStepTest` fails when the function drifts, the smoke run when the
script does.
```

Replace the heading `## How the dispatcher calls Phase A` and its first paragraph and bash block (up to, not including, "A leg that needs the triggers itself") with:

~~~markdown
## How a run calls Phase A

`auto` calls it at the two edges of a run and once per step (`engine.md` §The loop):

```bash
CHECKS="$HOME/.claude/skills/pipeline/checks"
git -C <worktree> diff origin/<base>...HEAD > "<manifest stem>.diff"
php "$CHECKS/dispatch_cli.php" launch <manifest> "<manifest stem>.diff" [--from <leg>]
# → {"action":"start",…} | {"action":"done"} | {"action":"halt","reason":…}
php "$CHECKS/dispatch_cli.php" brief <manifest> <leg> <step>      # each step's first command
# → the brief, or {"action":"halt","reason":…}
php "$CHECKS/dispatch_cli.php" finish <manifest> '<the workflow return, as JSON>'
```

`interactive` calls it once per step, over a diff file the session writes but never reads:

```bash
php "$CHECKS/dispatch_cli.php" returned <manifest> "<manifest stem>.diff"
# → {"action":"dispatch","leg":…,"step":…,"inline":…,"prompt":…} | {"action":"retry",…}
#   | {"action":"halt","reason":…} | {"action":"done"}
```
~~~

- [ ] **Step 7: `SKILL.md`**

Replace the paragraph from "Core principle: **a dispatcher that only loops**" to "…not in this summary:" with:

```markdown
Core principle: **in `auto` the pipeline is a program that calls agents.** The saved workflow
`pipeline-auto` (`workflow/pipeline-auto.js`) holds the order of steps, the loop-backs, their bounds
and the halts; each step is a fresh agent that runs `dispatch_cli.php brief`, does its leg, writes the
manifest and returns a status. `interactive` walks the same legs through `dispatch_cli.php next` /
`returned`, with the human resolving each review. No long-lived brain; a lost run reconstructs from
git + gh. See the references before driving a run — the enforcement lives there, not in this summary:
```

Replace the bullet "- **The 150k invariant** — …(`references/engine.md` §The dispatcher)." (four lines) with:

```markdown
- **Cost per run** — after every `auto` run the invoking session reports two outputs with the result:
  `checks/run_cost_cli.php` (weighted cost per step, the largest step peak) and `checks/run_audit.php`
  (whether `ui` and each gate's ledger agree with what the steps reported). A `MISMATCH` is a signal,
  never a halt (`references/engine.md` §The loop).
```

Insert before `## Non-goals`:

```markdown
## `auto` — how a run starts and ends

The invoking session (this one, or `orchestrate`) holds only the two edges of an `auto` run
(`references/engine.md` §The loop):

1. **Kickoff** (`references/engine.md` §The work item, §Kickoff): the worktree, the manifest
   exclusion, the first `manifest_write`.
2. **Launch.** With `CHECKS="$HOME/.claude/skills/pipeline/checks"`:
   `git -C <worktree> diff origin/<base>...HEAD > "<manifest stem>.diff"`, then
   `PIPELINE_NO_OPEN=<1 unattended, else 0> php "$CHECKS/dispatch_cli.php" launch <manifest> "<manifest stem>.diff"`.
   `done` or a halt: report it and stop.
3. **Start the saved workflow `pipeline-auto`** by name, with `launch`'s JSON as `args`, and wait for
   its completion notice. Starting it from this skill is the owner's opt-in; unattended runs need auto
   permission mode or allow rules for `git push`, `gh` and `docker`.
4. **Finish.** `php "$CHECKS/dispatch_cli.php" finish <manifest> '<its return as JSON>'`, or
   `'{"action":"halt","reason":"<the error>"}'` when the workflow errored.
5. **`done`:** `gh pr ready <pr>`. **A halt after `handoff`:** the reason into the PR body and the
   proof page opened once (`references/engine.md` §Failure policy).
6. **Report** the result with the two cost-per-run outputs above.

`~/.claude/workflows/pipeline-auto.js` is a symlink to `workflow/pipeline-auto.js`, linked by
`hooks/git-freshness.sh` as it links the skills.
```

- [ ] **Step 8: Check nothing stale is left and the lock-step holds**

Run: `grep -n -e 'dispatcher' -e 'engine_peak' -e '150k' skills/pipeline/SKILL.md skills/pipeline/references/*.md`
Expected: no output. (A hit is a sentence this task missed: rewrite it to name `launch`, the workflow script, the invoking session or `returned`, whichever it means.)

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='lock-step'`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add skills/pipeline/SKILL.md skills/pipeline/references/engine.md skills/pipeline/references/manifest.md skills/pipeline/references/gates.md
git commit -m "pipeline docs: auto runs as the pipeline-auto workflow; the dispatcher and its invariant go"
```

---

### Task 9: Orchestrate docs (spec step 8, orchestrate half)

**Files:**
- Modify: `skills/orchestrate/SKILL.md`, `skills/orchestrate/references/commands.md`

**Interfaces:**
- Consumes: pipeline `SKILL.md` §`auto` — how a run starts and ends (Task 8); `launch --from`, `finish` (Task 3); `run_cost_cli.php`, `run_audit.php` (Tasks 4–5).
- Produces: orchestrate starts runs as workflows, stops a stalled one with `TaskStop` + `finish`, and marks ready PRs itself.

Spec gaps handled here (listed in the hand-off): the spec's `/orchestrate` section starts a run with "`launch`, then the workflow" but not kickoff; this plan has the orchestrator run pipeline `SKILL.md` §`auto` steps 1–3 (kickoff included) per issue. And "commits wanted on a ready PR: `launch --from review-pr` and a new workflow" does not say how the wanted change reaches the run; this plan adds the owner's request to the manifest's `decisions` (every brief carries them) before the relaunch, and keeps `gh pr ready --undo` first, since `launch` expects a draft PR.

- [ ] **Step 1: `orchestrate/SKILL.md`**

Replace step 3:

```
3. **Dispatch**: Agent tool, `run_in_background: true`, no `isolation` or model override, the brief (commands §Brief).
```

with

```
3. **Launch** each run from the primary checkout (commands §Launch): pipeline `SKILL.md` §`auto` steps 1–3, the workflow `pipeline-auto` in the background. The worktree travels in the brief, never in this session's directory.
```

In step 5, replace `Run \`php ~/.claude/skills/pipeline/checks/engine_peak_cli.php <its agent id>\` and put the line in whichever report follows (pipeline \`engine.md\` §The dispatcher).` with `\`finish\` it, then on \`done\` run \`gh pr ready <P>\` yourself; a denial is a halt the owner sees (commands §Finish). Put \`run_cost_cli.php\` and \`run_audit.php\`'s lines in whichever report follows (pipeline \`engine.md\` §The loop).`

In step 7, replace `After a compaction, message your agent ids.` with `After a compaction, your dispatch record (workflow run id → issue) still names each run; its completion notice still arrives.`

In §The rules that slip, replace the first two bullets and the "Commits wanted" bullet:

```
- **One agent per run, ever.** Never dispatch for an issue with a dispatched or adopted run: no fresh run, resume agent, backup or restart. Wait for the completion notice.
- **Suspected stall** (no notice, no commit or PR change for 90 minutes): one `SendMessage`. "Queued for delivery" means alive; "resumed it in the background" means that send was the recovery. Replace only after a completion notice **and** demonstrably unfinished work, with the original stood down.
```

```
- **Commits wanted on a ready PR:** `gh pr ready --undo <P>` **first**. Then `SendMessage` the run that built it, even when finished: back in draft, mark ready when done, delete no remote branch. Never commit yourself or start a new agent for it.
```

with

```
- **One workflow per run at a time.** Never launch for an issue with a running or adopted run: no fresh run, backup or restart beside it. Wait for the completion notice.
- **Suspected stall** (no notice, no commit or PR change for 90 minutes): stop it with `TaskStop` on its workflow, which kills the running step's command and starts no further step; record it with `finish` as a halt (commands §Finish); ask *resume* / *leave it out*. *Resume* is a new `launch` and workflow.
```

```
- **Commits wanted on a ready PR:** `gh pr ready --undo <P>` **first**. Then the request into the manifest's `decisions`, `launch --from review-pr` and a new workflow (commands §Launch). Never commit yourself.
```

In §Common mistakes, replace the rows

```
| A fresh run, or "stop it, resume in a new agent", for a quiet run | A live suite looks identical; two agents in one worktree revert each other. |
```

```
| "The run finished, so a new agent isn't a second one" | `SendMessage` resumes it; a new agent is the double dispatch. |
```

with

```
| A second workflow beside a quiet run | A live suite looks identical; two runs in one worktree revert each other. |
```

```
| Relaunching a stopped run without `finish` | The cursor still says the stopped step is pending; `finish` records why it stopped, so the next `launch` resumes from a reason. |
```

In §Red flags, replace `- "Obviously dead"; "the owner said restart"; "still undelivered".` with `- "Obviously dead"; "the owner said restart"; "just start another workflow".`

- [ ] **Step 2: `orchestrate/references/commands.md`**

In §Owner of in-flight work, replace `the dispatch record (agent id → issue) is the owner` with `the dispatch record (workflow run id → issue) is the owner`.

Replace §Brief (from `## Brief` up to, not including, `## Watch`) with:

~~~markdown
## Launch

Per issue N, from the primary checkout: pipeline `SKILL.md` §`auto` steps 1–3.

- Kickoff creates the worktree with the declared `worktree.create`; never switch branches in the
  primary checkout, other runs share it.
- The owner's settled decisions for N go into the manifest's `decisions`, verbatim; `artifacts.issue`
  is N.
- `launch` runs with `PIPELINE_NO_OPEN=1`: the run is unattended.
- Start the workflow `pipeline-auto` with `launch`'s JSON as `args`, in the background, and add its run
  id → N to the dispatch record. Do not wait on it; its completion notice arrives.

Commits wanted on a ready PR, after `gh pr ready --undo <P>`:

```bash
php -r '$m = json_decode(file_get_contents($argv[1]), true); $m["decisions"][] = $argv[2]; file_put_contents($argv[1], json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");' <manifest> "<the owner's request, verbatim>"
git -C <worktree> diff origin/<base>...HEAD > <manifest stem>.diff
PIPELINE_NO_OPEN=1 php ~/.claude/skills/pipeline/checks/dispatch_cli.php launch <manifest> <manifest stem>.diff --from review-pr
```

then a new `pipeline-auto` workflow with that JSON.

## Finish

On a run's completion notice:

```bash
php ~/.claude/skills/pipeline/checks/dispatch_cli.php finish <manifest> '<the workflow return, as JSON>'
gh pr ready <P> -R <repo>                                                      # only on done
php ~/.claude/skills/pipeline/checks/run_cost_cli.php <the run's transcript dir>
git -C <worktree> diff origin/<base>...HEAD > <manifest stem>.diff
php ~/.claude/skills/pipeline/checks/run_audit.php <manifest> <manifest stem>.diff <the run's transcript dir>
```

A stalled run: `TaskStop` its workflow first, then
`finish <manifest> '{"action":"halt","reason":"stalled: no notice, commit or PR change for 90 minutes"}'`.
~~~

- [ ] **Step 3: Check nothing stale is left**

Run: `grep -n -e 'engine_peak' -e 'agent id' -e 'Agent tool' -e 'SendMessage the run' skills/orchestrate/SKILL.md skills/orchestrate/references/commands.md`
Expected: no output. (`SendMessage … notify_when_idle` for adopted sessions in §Watch stays: it concerns other sessions, not runs.)

- [ ] **Step 4: Commit**

```bash
git add skills/orchestrate/SKILL.md skills/orchestrate/references/commands.md
git commit -m "orchestrate docs: runs are pipeline-auto workflows; TaskStop and finish for a stall"
```

---

### Task 10: Baseline cost per old run, into the PR body (spec step 9)

**Files:**
- Scratch (not committed): `$TMPDIR/pipeline-baseline.py`
- PR #50 body (via `gh pr edit`)

**Interfaces:**
- Consumes: `~/.claude/token-audit/2026-09-22/rows.json` (read-only; one row per transcript with a `cost` already weighted as `usage.py` weighs), `~/.claude/projects/**/subagents/*.meta.json` (`spawnDepth`, `parentAgentId`, `description`), `pipeline_code_lines()` (triggers.php).
- Produces: the baseline median and quartiles that Task 11 and the keep / revert criteria compare against.

An old engine run is what the 2026-09-22 audit (`an13.py`) counted as one: a depth-1 subagent since 2026-09-14 whose description names a pipeline, an issue or `#N`, and which used the Agent tool. Its cost is its own row plus every row in the tree of agents it dispatched (`parentAgentId`, same session directory). Its PR is the PR URL its tree mentions most; its code lines are `pipeline_code_lines()` over that PR's diff.

- [ ] **Step 1: Write the baseline script**

Write `$TMPDIR/pipeline-baseline.py`:

```python
#!/usr/bin/env python3
"""Baseline cost per old /pipeline auto run (spec 2026-09-23 §Measurement), from the 2026-09-22 audit."""
import collections, glob, json, os, re, statistics, subprocess, sys

AUDIT = os.path.expanduser('~/.claude/token-audit/2026-09-22')
CHECKS = sys.argv[1]
SINCE = '2026-09-14'
ROWS = {r['path']: r for r in json.load(open(f'{AUDIT}/rows.json'))}
META = {m[:-len('.meta.json')] + '.jsonl': json.load(open(m))
        for m in glob.glob(os.path.expanduser('~/.claude/projects/**/subagents/*.meta.json'), recursive=True)}
PR_URL = re.compile(r'https://github\.com/([\w.-]+/[\w.-]+)/pull/(\d+)')


def agent_id(path):
    return os.path.basename(path)[len('agent-'):-len('.jsonl')]


children = collections.defaultdict(list)
for path, meta in META.items():
    if meta.get('parentAgentId'):
        children[(os.path.dirname(path), meta['parentAgentId'])].append(path)


def tree(path):
    return [path] + [p for child in children[(os.path.dirname(path), agent_id(path))] for p in tree(child)]


def used_agent_tool(path):
    for line in open(path, errors='ignore'):
        try:
            message = json.loads(line).get('message') or {}
        except ValueError:
            continue
        content = message.get('content')
        if message.get('role') == 'assistant' and isinstance(content, list) and any(
                isinstance(b, dict) and b.get('type') == 'tool_use' and b.get('name') == 'Agent' for b in content):
            return True
    return False


def is_engine(path, meta):
    row = ROWS.get(path)
    return (meta.get('spawnDepth') == 1 and row is not None and row['start'] and row['start'][:10] >= SINCE
            and re.search(r'(pipeline|issue|#\d)', meta.get('description', ''), re.I) and used_agent_tool(path))


def pr_of(paths):
    urls = collections.Counter(m for p in paths if os.path.exists(p) for m in PR_URL.findall(open(p, errors='ignore').read()))
    return urls.most_common(1)[0][0] if urls else None


def code_lines(repo, number):
    diff = subprocess.run(['gh', 'pr', 'diff', number, '-R', repo], capture_output=True, text=True)
    if diff.returncode != 0:
        return None
    php = subprocess.run(['php', '-r', 'require $argv[1] . "/triggers.php"; echo pipeline_code_lines(stream_get_contents(STDIN));', CHECKS],
                         input=diff.stdout, capture_output=True, text=True)
    return int(php.stdout) if php.returncode == 0 and php.stdout.strip().isdigit() else None


runs = []
for path, meta in META.items():
    if not is_engine(path, meta):
        continue
    agents = tree(path)
    measured = [p for p in agents if p in ROWS]
    pr = pr_of(agents)
    runs.append(dict(start=ROWS[path]['start'][:10], description=meta.get('description', ''), agents=len(agents),
                     unmeasured=len(agents) - len(measured), cost=sum(ROWS[p]['cost'] for p in measured),
                     pr=f'{pr[0]}#{pr[1]}' if pr else None, lines=code_lines(*pr) if pr else None))

costs = sorted(r['cost'] for r in runs)
q1, median, q3 = statistics.quantiles(costs, n=4)
print(f'{len(runs)} old engine runs since {SINCE}: median {median / 1e6:.2f}M weighted, quartiles {q1 / 1e6:.2f}M–{q3 / 1e6:.2f}M')
print()
print('| Start | Run | PR | Agents | Cost | Code lines |')
print('|---|---|---|---|---|---|')
for r in sorted(runs, key=lambda r: r['start']):
    agents = f"{r['agents']} ({r['unmeasured']} unmeasured)" if r['unmeasured'] else str(r['agents'])
    lines = '—' if r['lines'] is None else r['lines']
    print(f"| {r['start']} | {r['description']} | {r['pr'] or '—'} | {agents} | {r['cost'] / 1e6:.2f}M | {lines} |")
```

- [ ] **Step 2: Run it**

Run: `python3 "$TMPDIR/pipeline-baseline.py" "$PWD/skills/pipeline/checks" > "$TMPDIR/pipeline-baseline.md"; head -3 "$TMPDIR/pipeline-baseline.md"`
Expected: a first line `N old engine runs since 2026-09-14: median X.XXM weighted, quartiles …` with N close to the audit's count of engines since 2026-09-14 (18), then the table. If N differs from 18 by more than a couple, compare the selection with `an13.py`'s before going on; the audit's selection is the reference.

- [ ] **Step 3: Add the baseline and the criteria to the PR body**

```bash
gh pr view 50 --json body --jq .body > "$TMPDIR/pr50-body.md"
cat >> "$TMPDIR/pr50-body.md" <<'EOF'

## Baseline: cost per old `/pipeline auto` run

Weighted tokens (input 1, 5m cache write 1.25, 1h cache write 2, cache read 0.1, output 5), per old engine run since 2026-09-14: the engine plus every agent it dispatched, from the 2026-09-22 token audit's `rows.json`. PR attribution is the PR URL a run's transcripts mention most; code lines are `pipeline_code_lines()` over that PR's diff. The comparison with new runs is indicative, not controlled.

EOF
cat "$TMPDIR/pipeline-baseline.md" >> "$TMPDIR/pr50-body.md"
cat >> "$TMPDIR/pr50-body.md" <<'EOF'

New runs are measured with `run_cost_cli.php` (the same weights over one workflow run's agent transcripts) and `run_audit.php`.

- **Keep** when, after 6 runs, the median cost per run is at least 30 percent below the baseline median, the run audit found nothing, and no unattended run stalled on a permission.
- **Add the Haiku check** (a separate agent that only runs `returned` and passes its output on) on the first run-audit mismatch.
- **Revert** on an unattended permission stall that allow rules cannot clear, `implement` peaking above 250k on a plan under 300 code lines, or a median cost not at least 30 percent below the baseline after 6 runs.
EOF
gh pr edit 50 --body-file "$TMPDIR/pr50-body.md"
```

Expected: `gh pr view 50 --json body --jq .body | tail -20` shows the criteria.

- [ ] **Step 4: No commit** — nothing in the repo changed.

---

### Task 11: Suites, `/critique pr`, one real run (spec step 10)

Done by the executing session; checklists, not code.

**Files:**
- Any fixes `/critique pr` leads to (test-first, as in Tasks 1–7).
- PR #50 body.

**Interfaces:**
- Consumes: everything above.

- [ ] **Step 1: Both suites and the hook tests green**

Run:
```bash
./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests
./vendor/bin/pest -c skills/critique/checks/phpunit.xml --test-directory=skills/critique/checks/tests
bash hooks/tests/git-freshness-sync.test.sh
bash hooks/tests/vault-sync.test.sh
grep -rn -e 'engine_peak' -e '150k' -e 'The dispatcher' skills/ hooks/ README.md CLAUDE.md
```
Expected: all green; the grep prints nothing.

- [ ] **Step 2: Push and review**

- `git push origin feature/redesign-the-pipeline-auto-engine-as-a-pure`.
- Invoke `/critique pr` on PR #50. Act on each point per the house rules (integrate what is worth it, test-first; record the rest); rerun Step 1 after any change; push.
- Add to the PR body: what the review raised and what was done with each point, and the twelve smoke-run outcomes from Task 6 (scenario, labels as expected, return, cursor after `finish`).

- [ ] **Step 3: One real `/pipeline autoflow` run**

Before merge, `~/.claude/skills/pipeline` still points at the main checkout (the old `SKILL.md` and `engine.md`) and `~/.claude/workflows/` holds no `pipeline-autoflow.js`. So:

- Ask the owner (one `AskUserQuestion`) which small issue in which slot-enabled Laravel project to run, recommending one that touches the UI so `verify-ui` runs.
- Link the script for this run only: `ln -s /Users/jroelofs/GitProjects/LaravelClaudeMd/LaravelClaudeMd/.claude/worktrees/pipeline-dispatcher-loop/skills/pipeline/workflow/pipeline-autoflow.js ~/.claude/workflows/pipeline-autoflow.js` — only when that entry does not exist (a Workflow `scriptPath` outside the project's working directory is refused).
- In a session in that project, in auto permission mode, follow **this branch's** `skills/pipeline/SKILL.md` §`autoflow` section's steps 1–6 by hand, with `CHECKS=/Users/jroelofs/GitProjects/LaravelClaudeMd/LaravelClaudeMd/.claude/worktrees/pipeline-dispatcher-loop/skills/pipeline/checks` for `launch` and `finish`. `launch` then passes that directory as `checks`, so every step's `brief` runs this branch's code; the `engine.md` sections a brief cites are still read from the main checkout.
- Record, for the PR body: the issue and PR, the workflow's return, wall time, every permission prompt or denial seen (none expected; `gh pr ready` runs in the invoking session), `run_cost_cli.php`'s lines against the baseline median, the run's code lines (`pipeline_code_lines()` over the final PR diff), and `run_audit.php`'s lines. It is run 1 of the 6 the keep criterion counts.
- Remove the temporary link: `rm ~/.claude/workflows/pipeline-autoflow.js` (after merge, `hooks/git-freshness.sh` links the main checkout's copy).
- Still open from spec step 1: `gh pr ready` from an orchestrator's own `claude --bg` session; the first orchestrated run shows it. Note it in the PR body.

- [ ] **Step 4: Vendor hacks and the final check**

- This repo has no `vendor/it4web/`; nothing to port.
- Review the whole diff against the repo's conventions (`git diff origin/main...HEAD`): procedural PHP in the style of the neighbouring checks, no new abstractions beyond the spec's, docs that name no dispatcher.
- Leave the PR draft; the owner decides when it is ready.
