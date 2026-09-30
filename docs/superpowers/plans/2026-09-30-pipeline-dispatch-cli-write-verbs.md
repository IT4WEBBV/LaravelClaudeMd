# `dispatch_cli.php record` and `suite` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A pipeline step writes the manifest with one tested command, `dispatch_cli.php record`, which
checks its own result before it writes and cannot fail silently; `dispatch_cli.php suite` covers the one
write a step makes earlier.

**Architecture:** A new pure file, `record.php`, holds one table (per `<leg>:<step>` and status: the flags
required and allowed) and `pipeline_record()`, which builds the manifest a step leaves from the dispatch
snapshot, the flags and a handful of facts. `dispatch_cli.php` gains the two commands: they read the files,
ask git for the facts, run the boundary's own check (`pipeline_return_problem()`) over the candidate, write,
read back, and exit 1 on a refusal. `brief.php` prints the literal commands from the same table, so a brief
cannot name a flag `record` refuses.

**Tech Stack:** PHP 8.4, no framework; Pest 4 (`skills/pipeline/checks/tests`); Node for the workflow
replay (`autoflow_replay.mjs`).

**Spec:** `docs/superpowers/specs/2026-09-30-pipeline-dispatch-cli-write-verbs-design.md`. Read it with
this plan: the plan argues from it, and its `## Assumptions` 20–30 are the answers this plan assumed.

## Global Constraints

- Everything lives under `skills/pipeline/`. Run every command from the worktree root.
- Tests: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
  (append `--filter <Name>` for one file). This repo is not a Docker project: Pest runs on the host. A fresh
  worktree has no `vendor/`: run `composer install` once first.
- Test-first: every task writes its test, sees it fail, then writes the code.
- `dispatch.php` does not change. `pipeline_return_problem()` and `pipeline_leg_writable_keys()` are the
  contract `record` writes to; neither learns about `record`.
- `workflow/pipeline-autoflow.js` does not change. The manifest's shape and every ledger shape do not change.
- `record` and `suite` answer one JSON line on stdout: `{"action":"recorded",…}` with exit 0, or
  `{"action":"refused","reason":…}` with **exit 1** and the manifest untouched. A usage error is exit 1 with
  the usage on stderr. Every other command keeps exit 0 on every decision.
- A refusal never writes. A record never writes an entry with a fact left out.
- The existing tests that write the manifest by hand stay as they are: a hand-written manifest is still
  what the checks judge.
- Enums are native backed enums, never string constants. Guard clauses over nesting; full type hints.
- No changelog entry: the repo has no `.changelog/` and no `CHANGELOG.md`.
- Commits: no `Co-Authored-By`, no AI attribution; every message ends on `(#52)`.

## File Structure

| File | Responsibility |
|---|---|
| `checks/manifest.php` (modify) | gains `manifest_files()` (the run's files beside the manifest) and `pipeline_relative_path()` |
| `checks/record.php` (create) | pure: `ActionDisposition`, `IssueLinkOutcome`, `pipeline_record_table()`, `pipeline_record()` and its builders |
| `checks/dispatch_cli.php` (modify) | the `record` and `suite` commands, the facts, the read-back, the exit code; `dispatch_cli_files()` goes |
| `checks/brief.php` (modify) | `## Return` prints the commands; the override lines say what to pass; the `cycle` pointer goes |
| `checks/tests/RecordTest.php` (create) | the pure function, no git |
| `checks/tests/RecordCliTest.php` (create) | the two commands against a throwaway repo |
| `checks/tests/autoflow_replay.mjs` (modify) | the stub step's `record` form |
| `references/manifest.md`, `engine.md`, `gates.md`, `SKILL.md` (modify) | a step *records*; the commands are listed |

## Review Focus

Inputs the spec implies and its test list does not name, most likely first. Each has its test in the task
that owns the code.

1. **A review file with a byte that is not UTF-8.** `json_encode()` fails, and `manifest_write()` would
   replace the manifest with an empty line. Expected: a refusal, the manifest byte-identical. (Task 3)
2. **A manifest the process cannot write.** Expected: `the write did not land`, exit 1, never `recorded`.
   (Task 3)
3. **A value that starts with `--`** (`--reason "--force is missing from the plan"`). Expected: taken as
   the value, verbatim. (Task 3)
4. **An actions file that is one object, not a list** (`{"claim":…}`), the shape an agent writes for a
   single action. Expected: a refusal that names the list shape, not a PHP warning. (Task 2)
5. **`suite` where the tree key cannot be computed** (the worktree is gone or is no repository).
   Expected: a refusal that says to run the suite, and no `suite` written. (Task 4)
6. **A review step in a checkout without `refs/remotes/origin/HEAD`** (a `git init` plus
   `git remote add` repo, or a stale clone). Expected: the base is origin's own default branch, as
   kickoff reads it, and the review is recorded; only where origin does not answer either, a refusal, with
   the halt still recordable. (Task 3)

---

### Task 1: The run's files and the git-at-a-ref test, each in one place

**Files:**
- Modify: `skills/pipeline/checks/manifest.php`
- Modify: `skills/pipeline/checks/dispatch_cli.php:34-40` (remove `dispatch_cli_files()`), every
  `dispatch_cli_files(` call, and `dispatch_cli_invariant_problem()` at `:243-260`
- Modify: `skills/pipeline/checks/brief.php:305-312` (`pipeline_design_files()`)
- Test: `skills/pipeline/checks/tests/ManifestTest.php`

**Interfaces:**
- Produces: `manifest_files(string $manifestPath): array{brief: string, before: string, diff: string, review: string, actions: string}`
- Produces: `pipeline_relative_path(string $worktree, string $path): string`
- Produces: `dispatch_cli_exists_at(string $worktree, string $ref, string $path): bool`

- [ ] **Step 1: Write the failing tests**

Append to `skills/pipeline/checks/tests/ManifestTest.php`:

```php
it('names the files of a run beside its manifest, the review and the actions included', function () {
    expect(manifest_files('/tmp/wt/.claude/pipeline/feature-x.json'))->toBe([
        'brief' => '/tmp/wt/.claude/pipeline/feature-x.brief.md',
        'before' => '/tmp/wt/.claude/pipeline/feature-x.before.json',
        'diff' => '/tmp/wt/.claude/pipeline/feature-x.diff',
        'review' => '/tmp/wt/.claude/pipeline/feature-x.review.md',
        'actions' => '/tmp/wt/.claude/pipeline/feature-x.actions.json',
    ]);
});

it('names a path as git does: relative to the worktree', function () {
    expect(pipeline_relative_path('/tmp/wt', '/tmp/wt/docs/spec.md'))->toBe('docs/spec.md');
    expect(pipeline_relative_path('/tmp/wt/', '/tmp/wt/docs/spec.md'))->toBe('docs/spec.md');
    expect(pipeline_relative_path('/tmp/wt', 'docs/spec.md'))->toBe('docs/spec.md');
    expect(pipeline_relative_path('/tmp/wt', '/tmp/wt-other/docs/spec.md'))->toBe('/tmp/wt-other/docs/spec.md');
});
```

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter ManifestTest`
Expected: FAIL, `Call to undefined function manifest_files()` and `pipeline_relative_path()`.

- [ ] **Step 3: Add the two functions to `manifest.php`**

Append to `skills/pipeline/checks/manifest.php`:

```php
/**
 * The files of one run, beside its manifest (`../references/engine.md` §The loop): the brief, the dispatch
 * snapshot, the step's diff, a review step's review and a resolve step's actions.
 *
 * @return array{brief: string, before: string, diff: string, review: string, actions: string}
 */
function manifest_files(string $manifestPath): array
{
    $stem = preg_replace('/\.json$/', '', $manifestPath);

    return [
        'brief' => "{$stem}.brief.md",
        'before' => "{$stem}.before.json",
        'diff' => "{$stem}.diff",
        'review' => "{$stem}.review.md",
        'actions' => "{$stem}.actions.json",
    ];
}

/** A path as git names it: relative to the worktree, an absolute path under it stripped of that prefix. */
function pipeline_relative_path(string $worktree, string $path): string
{
    $root = rtrim($worktree, '/') . '/';

    return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
}
```

- [ ] **Step 4: Use them**

In `skills/pipeline/checks/dispatch_cli.php`:

1. Delete `dispatch_cli_files()` and its docblock (lines 34–40).
2. Replace every remaining `dispatch_cli_files(` with `manifest_files(` (eight call sites; `grep -n
   'dispatch_cli_files' skills/pipeline/checks/dispatch_cli.php` must print nothing afterwards).
3. Add, directly above `dispatch_cli_invariant_problem()`:

```php
/** Whether `$path`, relative to the worktree or absolute under it, exists at `$ref`. */
function dispatch_cli_exists_at(string $worktree, string $ref, string $path): bool
{
    return pipeline_git_run($worktree, ['cat-file', '-e', "{$ref}:" . pipeline_relative_path($worktree, $path)])[0] === 0;
}
```

4. In `dispatch_cli_invariant_problem()`, replace these three lines

```php
        $relative = str_starts_with($path, "{$worktree}/") ? substr($path, strlen($worktree) + 1) : $path;
        if (pipeline_git_run($worktree, ['cat-file', '-e', "{$sha}:{$relative}"])[0] !== 0) {
            return "the recorded {$name} {$path} does not exist at {$sha}";
        }
```

with

```php
        if (! dispatch_cli_exists_at($worktree, $sha, $path)) {
            return "the recorded {$name} {$path} does not exist at {$sha}";
        }
```

In `skills/pipeline/checks/brief.php`, replace the body of `pipeline_design_files()` with:

```php
    $paths = array_filter([$manifest['artifacts']['spec'] ?? null, $manifest['artifacts']['plan'] ?? null]);

    return array_map(fn (string $path) => pipeline_relative_path((string) $manifest['worktree'], $path), array_values($paths));
```

- [ ] **Step 5: Run the whole suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, every test (the two new ones included; `DispatchCliTest`'s *halts a launch whose recorded
spec is missing at last_sha* and `BaseStateTest` still green).

- [ ] **Step 6: Commit**

```bash
git add skills/pipeline/checks/manifest.php skills/pipeline/checks/dispatch_cli.php skills/pipeline/checks/brief.php skills/pipeline/checks/tests/ManifestTest.php
git commit -m "refactor(pipeline): a run's files and the exists-at-a-ref test each live in one function (#52)"
```

---

### Task 2: `record.php`, the manifest a step leaves

**Files:**
- Create: `skills/pipeline/checks/record.php`
- Modify: `skills/pipeline/checks/tests/Pest.php:6` (add `'record.php'` to the list, after `'brief.php'`)
- Test: `skills/pipeline/checks/tests/RecordTest.php` (create)

**Interfaces:**
- Consumes: `pipeline_relative_path()` (Task 1); from the existing code `LegStatus`, `DesignSize`,
  `pipeline_gate_of()`, `pipeline_open_entry()`, `pipeline_ledger()`, `pipeline_next_cycle()` (`brief.php`),
  `pipeline_changed_keys()`, `pipeline_normalized()`.
- Produces:
  - `enum ActionDisposition: string` (`integrated`, `recorded`, `open-question`)
  - `enum IssueLinkOutcome: string` (`closes`, `stays-open`, `dropped-but-closes`)
  - `const PIPELINE_RECORD_FLAGS` — the flag names, without `--`: `spec`, `plan`, `pr`, `proof`,
    `review-file`, `actions-file`, `issue-link`, `reason`
  - `pipeline_record_table(): array<string, array<string, array{required: list<string>, optional: list<string>}>>`
    keyed `<leg>:<step>`, then status
  - `pipeline_record(array $before, array $current, string $leg, string $step, array $given, array $facts): array|string`
    — the candidate manifest, or the refusal reason as a string
  - `pipeline_record_entry(array $before, array $candidate, string $leg, string $step): ?int`
  - `pipeline_record_replaced(array $before, array $current): list<string>`
  - `pipeline_annotations(array $triggers): list<string>`
- `$given`: `['status' => string]` plus one key per flag given, named as the flag: `spec`, `plan`, `pr`,
  `proof`, `reason` hold the value; `review-file` and `actions-file` hold **the file's contents**;
  `issue-link` holds a list of `<n>=<outcome>` strings.
- `$facts`: `['head' => ?string, 'now' => string, 'annotations' => ?list<string>, 'size' => DesignSize, 'committed' => list<string>]`.
  `head` and `annotations` are `null` when git could not give them; `committed` holds the given spec and
  plan paths, relative, that exist at `HEAD`.

- [ ] **Step 1: Write the failing tests**

Create `skills/pipeline/checks/tests/RecordTest.php`:

```php
<?php

const RECORD_HEAD = 'cccccccccccccccccccccccccccccccccccccccc';

function record_before(string $leg, array $extra = []): array
{
    return [
        'branch' => 'feature/x', 'worktree' => '/tmp/wt', 'mode' => 'autoflow',
        'cursor' => ['leg' => $leg, 'status' => 'pending'],
        'artifacts' => ['spec' => 'docs/spec.md', 'plan' => 'docs/plan.md', 'pr' => null, 'issue' => 52],
        'last_sha' => 'aaa1111', 'gate_ledger' => [],
        ...$extra,
    ];
}

function record_facts(array $extra = []): array
{
    return [
        'head' => RECORD_HEAD, 'now' => '2026-09-30T12:00:00Z', 'annotations' => ['migration'],
        'size' => DesignSize::Architectural, 'committed' => ['docs/spec.md', 'docs/plan.md'],
        ...$extra,
    ];
}

/** Valid flags for a step and status, as `dispatch_cli.php record` hands them over: the two files already read. */
function record_given(string $leg, string $step, string $status, array $extra = []): array
{
    $values = [
        'spec' => 'docs/spec.md', 'plan' => 'docs/plan.md', 'pr' => '7', 'proof' => '/proofs/pr-7/index.html',
        'review-file' => "Step 4 drops the link.\n",
        'actions-file' => '[{"claim":"step 4 drops the link","disposition":"integrated","note":"restored it"}]',
        'reason' => 'needs a queue',
    ];
    $row = pipeline_record_table()["{$leg}:{$step}"][$status];

    return ['status' => $status, ...array_intersect_key($values, array_flip($row['required'])), ...$extra];
}

function record_open(string $gate): array
{
    return [
        'gate' => $gate, 'leg' => pipeline_leg_of_gate($gate), 'cycle' => 1, 'at' => '2026-09-30T10:00:00Z',
        'review' => 'r', 'annotations' => [],
        ...($gate === 'pr-review' ? ['reviewed_sha' => str_repeat('a', 40)] : []),
    ];
}

/** @return array<string, array{0: string, 1: string, 2: string}> every `<leg>:<step>` of both modes, with a mode that has it */
function record_steps(): array
{
    $steps = [];
    foreach (['autoflow', 'interactive'] as $mode) {
        foreach (pipeline_legs() as $leg) {
            foreach (pipeline_steps($leg, $mode) as $step) {
                $steps["{$leg}:{$step}"] = [$leg, $step, $mode];
            }
        }
    }

    return $steps;
}

/** A snapshot for the step: a resolve step finds its open review. */
function record_snapshot(string $leg, string $step, string $mode = 'autoflow'): array
{
    return record_before($leg, ['mode' => $mode, 'gate_ledger' => $step === 'resolve' ? [record_open(pipeline_gate_of($leg))] : []]);
}

it('has a row for exactly the steps of both modes, and in each the statuses that step may return', function () {
    $steps = record_steps();

    expect(array_keys(pipeline_record_table()))->toEqualCanonicalizing(array_keys($steps));
    foreach ($steps as $key => [$leg, $step]) {
        expect(array_keys(pipeline_record_table()[$key]))
            ->toEqualCanonicalizing(array_map(fn (LegStatus $status) => $status->value, LegStatus::allowedFor($leg, $step)));
    }
});

it('lists only flags record knows, and asks every halt for a reason and nothing else', function () {
    foreach (pipeline_record_table() as $rows) {
        foreach ($rows as $row) {
            expect(array_diff([...$row['required'], ...$row['optional']], PIPELINE_RECORD_FLAGS))->toBe([]);
        }
        expect($rows['halted'])->toBe(['required' => ['reason'], 'optional' => []]);
    }
});

it('builds, for every step and every status it may return, a manifest the boundary check accepts', function () {
    foreach (record_steps() as [$leg, $step, $mode]) {
        foreach (LegStatus::allowedFor($leg, $step) as $status) {
            $before = record_snapshot($leg, $step, $mode);
            $candidate = pipeline_record($before, $before, $leg, $step, record_given($leg, $step, $status->value), record_facts());

            expect($candidate)->toBeArray("{$leg}:{$step} {$status->value} was refused: " . json_encode($candidate));
            expect(pipeline_return_problem(pipeline_normalized($before), pipeline_normalized($candidate), $leg, $step, DesignSize::Architectural))
                ->toBeNull("{$leg}:{$step} {$status->value}");
            expect($candidate['cursor']['status'])->toBe($status->value);
        }
    }
});

it('stamps last_sha on every status but a halt, and gives only a halt a cursor reason', function () {
    $before = record_snapshot('implement', 'run');
    $record = fn (string $status, array $facts = []) => pipeline_record($before, $before, 'implement', 'run', record_given('implement', 'run', $status), record_facts($facts));

    expect($record('continued'))->toMatchArray(['last_sha' => RECORD_HEAD, 'cursor' => ['leg' => 'implement', 'status' => 'continued']]);
    expect($record('plan-insufficient'))->toMatchArray(['last_sha' => RECORD_HEAD, 'cursor' => ['leg' => 'implement', 'status' => 'plan-insufficient']]);
    expect($record('halted', ['head' => null]))->toMatchArray(['last_sha' => 'aaa1111', 'cursor' => ['leg' => 'implement', 'status' => 'halted', 'reason' => 'needs a queue']]);
    expect($record('continued', ['head' => null]))->toBe('record needs the worktree\'s HEAD for last_sha, and `git rev-parse HEAD` failed');
});

it('appends the open review entry, with reviewed_sha on pr-review only', function () {
    $plan = pipeline_record(record_before('review-plan'), record_before('review-plan'), 'review-plan', 'review', record_given('review-plan', 'review', 'continued'), record_facts());
    $pr = pipeline_record(record_before('review-pr'), record_before('review-pr'), 'review-pr', 'review', record_given('review-pr', 'review', 'continued'), record_facts());

    expect($plan['gate_ledger'])->toBe([[
        'gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-30T12:00:00Z',
        'review' => 'Step 4 drops the link.', 'annotations' => ['migration'],
    ]]);
    expect($pr['gate_ledger'])->toBe([[
        'gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => '2026-09-30T12:00:00Z',
        'review' => 'Step 4 drops the link.', 'annotations' => ['migration'], 'reviewed_sha' => RECORD_HEAD,
    ]]);
    expect(pipeline_record_entry(record_before('review-pr'), $pr, 'review-pr', 'review'))->toBe(0);
});

it('counts the cycle per gate, and keeps an unknown count unknown', function () {
    $looped = [...record_open('plan-approval'), 'actions' => [], 'outcome' => 'looped-back'];
    $second = pipeline_record(record_before('review-plan', ['gate_ledger' => [$looped]]), [], 'review-plan', 'review', record_given('review-plan', 'review', 'continued'), record_facts());
    $unknown = pipeline_record(record_before('review-plan', ['gate_ledger' => [[...$looped, 'cycle' => 'unknown']]]), [], 'review-plan', 'review', record_given('review-plan', 'review', 'continued'), record_facts());

    expect($second['gate_ledger'][1]['cycle'])->toBe(2);
    expect($unknown['gate_ledger'][1]['cycle'])->toBe('unknown');
});

it('refuses a review step whose review is empty or whose diff could not be read', function () {
    $before = record_before('review-plan');
    $given = record_given('review-plan', 'review', 'continued');

    expect(pipeline_record($before, $before, 'review-plan', 'review', [...$given, 'review-file' => " \n\n"], record_facts()))
        ->toBe('the review file is empty');
    expect(pipeline_record($before, $before, 'review-plan', 'review', $given, record_facts(['annotations' => null])))
        ->toBe('record needs the branch\'s diff for the entry\'s annotations, and `git diff <base>...HEAD` failed');
});

it('completes the open entry with actions and outcome, and keeps its seven kept keys', function () {
    $before = record_snapshot('review-pr', 'resolve');
    $given = record_given('review-pr', 'resolve', 'looped-back', ['issue-link' => ['52=closes', '122=stays-open']]);
    $candidate = pipeline_record($before, $before, 'review-pr', 'resolve', $given, record_facts());

    expect($candidate['gate_ledger'])->toBe([[
        ...record_open('pr-review'),
        'actions' => [['claim' => 'step 4 drops the link', 'disposition' => 'integrated', 'note' => 'restored it']],
        'issue_links' => [['issue' => 52, 'outcome' => 'closes'], ['issue' => 122, 'outcome' => 'stays-open']],
        'outcome' => 'looped-back',
    ]]);
    expect(pipeline_record_entry($before, $candidate, 'review-pr', 'resolve'))->toBe(0);

    $plain = pipeline_record($before, $before, 'review-pr', 'resolve', record_given('review-pr', 'resolve', 'continued', ['actions-file' => '[]']), record_facts());
    expect($plain['gate_ledger'][0])->toBe([...record_open('pr-review'), 'actions' => [], 'outcome' => 'continued']);
});

it('refuses an actions file that is not a list of {claim, disposition, note}, naming the item and the key', function (string $json, string $reason) {
    $before = record_snapshot('review-plan', 'resolve');

    expect(pipeline_record($before, $before, 'review-plan', 'resolve', record_given('review-plan', 'resolve', 'continued', ['actions-file' => $json]), record_facts()))
        ->toBe($reason);
})->with([
    'not JSON' => ['claim: x', 'the actions file is not a JSON list of {claim, disposition, note}'],
    'one object, not a list' => ['{"claim":"x","disposition":"integrated","note":"n"}', 'the actions file is not a JSON list of {claim, disposition, note}'],
    'an item that is no object' => ['["x"]', 'actions[0] is not an object'],
    'a missing key' => ['[{"claim":"x","disposition":"integrated"}]', 'actions[0] has no `note`'],
    'an unknown key' => ['[{"claim":"x","disposition":"integrated","note":"n","sha":"abc"}]', 'actions[0] has an unknown key `sha`'],
    'an empty claim' => ['[{"claim":" ","disposition":"integrated","note":"n"}]', 'actions[0]: `claim` is not a non-empty string'],
    'an unknown disposition' => ['[{"claim":"a","disposition":"integrated","note":"n"},{"claim":"x","disposition":"done","note":"n"}]', 'actions[1]: `disposition` is not one of integrated, recorded, open-question'],
    'a note that is no string' => ['[{"claim":"x","disposition":"recorded","note":null}]', 'actions[0]: `note` is not a string'],
]);

it('refuses an issue link that is not <number>=<outcome>', function (string $link) {
    $before = record_snapshot('review-pr', 'resolve');

    expect(pipeline_record($before, $before, 'review-pr', 'resolve', record_given('review-pr', 'resolve', 'continued', ['issue-link' => [$link]]), record_facts()))
        ->toBe("--issue-link {$link} is not <issue number>=<outcome>, the outcome one of closes, stays-open, dropped-but-closes");
})->with(['52', '#52=closes', '52=closed', '=closes']);

it('sets the PR as an integer, and refuses anything but digits', function () {
    $before = record_before('handoff');

    expect(pipeline_record($before, $before, 'handoff', 'run', record_given('handoff', 'run', 'continued'), record_facts())['artifacts']['pr'])->toBe(7);
    expect(pipeline_record($before, $before, 'handoff', 'run', ['status' => 'continued', 'pr' => '#7'], record_facts()))->toBe('--pr #7 is not a PR number');
});

it('adds the thin verify-ui entry with the status as its outcome, and the proof when given', function () {
    $before = record_before('verify-ui');
    $continued = pipeline_record($before, $before, 'verify-ui', 'run', record_given('verify-ui', 'run', 'continued'), record_facts());
    $looped = pipeline_record($before, $before, 'verify-ui', 'run', record_given('verify-ui', 'run', 'looped-back'), record_facts());

    expect($continued['gate_ledger'])->toBe([['gate' => 'verify-ui', 'cycle' => 1, 'at' => '2026-09-30T12:00:00Z', 'outcome' => 'continued']]);
    expect($continued['artifacts']['proof'])->toBe('/proofs/pr-7/index.html');
    expect($looped['gate_ledger'])->toBe([['gate' => 'verify-ui', 'cycle' => 1, 'at' => '2026-09-30T12:00:00Z', 'outcome' => 'looped-back']]);
    expect($looped['artifacts'])->not->toHaveKey('proof');
});

it('answers plan-insufficient with the entry the design size calls for, and no review entry', function () {
    $before = record_before('review-plan');
    $given = record_given('review-plan', 'review', 'plan-insufficient');
    $gap = pipeline_record($before, $before, 'review-plan', 'review', $given, record_facts());
    $escalation = pipeline_record($before, $before, 'review-plan', 'review', $given, record_facts(['size' => DesignSize::Bounded]));

    expect($gap['gate_ledger'])->toBe([['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-30T12:00:00Z', 'reason' => 'needs a queue', 'outcome' => 'looped-back']]);
    expect($escalation['gate_ledger'])->toBe([['gate' => 'design-size', 'leg' => 'review-plan', 'at' => '2026-09-30T12:00:00Z', 'reason' => 'needs a queue', 'outcome' => 'escalated']]);
    expect($gap['cursor'])->toBe(['leg' => 'review-plan', 'status' => 'plan-insufficient']);
    expect(pipeline_return_problem(pipeline_normalized($before), pipeline_normalized($escalation), 'review-plan', 'review', DesignSize::Bounded))->toBeNull();
});

it('lets the design spec step set the spec and remove the plan on Architectural, and require the plan on Bounded', function () {
    $before = record_before('design');
    $spec = ['status' => 'continued', 'spec' => 'docs/spec.md'];
    $both = [...$spec, 'plan' => 'docs/plan.md'];
    $bounded = record_facts(['size' => DesignSize::Bounded]);

    $architectural = pipeline_record($before, $before, 'design', 'spec', $spec, record_facts());
    expect($architectural['artifacts'])->toBe(['spec' => 'docs/spec.md', 'pr' => null, 'issue' => 52]);
    expect(pipeline_record($before, $before, 'design', 'spec', $both, record_facts()))
        ->toBe('an Architectural spec takes no --plan: the plan step writes the plan');
    expect(pipeline_record($before, $before, 'design', 'spec', $both, $bounded)['artifacts'])
        ->toMatchArray(['spec' => 'docs/spec.md', 'plan' => 'docs/plan.md']);
    expect(pipeline_record($before, $before, 'design', 'spec', $spec, $bounded))
        ->toBe('a Bounded spec needs --plan: its spec step commits the plan too');
});

it('stores a spec or plan path relative to the worktree, and refuses one that is not committed', function () {
    $before = record_before('design', ['artifacts' => ['spec' => 'docs/spec.md', 'pr' => null, 'issue' => 52]]);

    expect(pipeline_record($before, $before, 'design', 'plan', ['status' => 'continued', 'plan' => '/tmp/wt/docs/plan.md'], record_facts())['artifacts']['plan'])
        ->toBe('docs/plan.md');
    expect(pipeline_record($before, $before, 'design', 'plan', ['status' => 'continued', 'plan' => 'docs/plan.md'], record_facts(['committed' => ['docs/spec.md']])))
        ->toBe('the plan docs/plan.md does not exist at HEAD (`git cat-file -e HEAD:docs/plan.md` failed): commit it first');
});

it('refuses a flag the step\'s row does not list, naming the ones it does, and a missing required flag', function () {
    $before = record_before('handoff');

    expect(pipeline_record($before, $before, 'handoff', 'run', ['status' => 'continued', 'pr' => '7', 'plan' => 'docs/plan.md'], record_facts()))
        ->toBe('--plan is not a flag of handoff run with --status continued, which takes --pr');
    expect(pipeline_record($before, $before, 'handoff', 'run', ['status' => 'continued'], record_facts()))
        ->toBe('handoff run with --status continued needs --pr');
    expect(pipeline_record($before, $before, 'implement', 'run', ['status' => 'continued', 'pr' => '7'], record_facts()))
        ->toBe('--pr is not a flag of implement run with --status continued, which takes no flag');
    expect(pipeline_record($before, $before, 'handoff', 'run', ['status' => 'halted', 'reason' => 'x', 'pr' => '7'], record_facts()))
        ->toBe('--pr is not a flag of handoff run with --status halted, which takes --reason');
});

it('refuses a status the step may not return, a step its leg does not have, and a reason that is blank', function () {
    $before = record_before('design');

    expect(pipeline_record($before, $before, 'design', 'plan', ['status' => 'looped-back'], record_facts()))
        ->toBe('the design plan step cannot return looped-back; it may return continued, halted');
    expect(pipeline_record($before, $before, 'design', 'resolve', ['status' => 'continued'], record_facts()))
        ->toBe('design has no resolve step');
    expect(pipeline_record($before, $before, 'design', 'plan', ['status' => 'halted', 'reason' => '  '], record_facts()))
        ->toBe('--reason is empty: say why');
    expect(pipeline_record(record_before('implement'), [], 'implement', 'run', ['status' => 'plan-insufficient', 'reason' => ''], record_facts()))
        ->toBe('--reason is empty: say why');
});

it('starts from the snapshot: a second record replaces the first, the suite is kept, a hand edit is dropped and named', function () {
    $gap = ['gate' => 'plan-approval', 'leg' => 'implement', 'cycle' => 1, 'at' => '2026-09-30T09:00:00Z', 'reason' => 'needs a queue', 'outcome' => 'looped-back'];
    $suite = ['tree' => 't1', 'outcome' => 'green', 'passed' => 104, 'failed' => 0, 'at' => '2026-09-30T11:00:00Z'];
    $before = record_before('design', ['gate_ledger' => [$gap]]);
    $edited = [...$before, 'suite' => $suite, 'cursor' => ['leg' => 'design', 'status' => 'continued'], 'gate_ledger' => [[...$gap, 'actions' => []]]];

    $candidate = pipeline_record($before, $edited, 'design', 'plan', record_given('design', 'plan', 'continued'), record_facts());

    expect($candidate['gate_ledger'])->toBe([$gap]);
    expect($candidate['suite'])->toBe($suite);
    expect(pipeline_record_replaced($before, $edited))->toEqualCanonicalizing(['gate_ledger', 'cursor.status']);
    expect(pipeline_record_replaced($before, $before))->toBe([]);

    $review = record_before('review-plan');
    $given = record_given('review-plan', 'review', 'continued');
    $first = pipeline_record($review, $review, 'review-plan', 'review', $given, record_facts());
    $second = pipeline_record($review, $first, 'review-plan', 'review', $given, record_facts());

    expect($second['gate_ledger'])->toHaveCount(1);
    expect(pipeline_record_entry($review, record_before('review-plan', ['cursor' => ['leg' => 'review-plan', 'status' => 'halted', 'reason' => 'x']]), 'review-plan', 'review'))->toBeNull();
});

it('names the content triggers that fired, and never ui', function () {
    expect(pipeline_annotations(['package' => true, 'migration' => false, 'auth' => true, 'ui' => true]))->toBe(['package', 'auth']);
    expect(pipeline_annotations(['package' => false, 'migration' => false, 'auth' => false, 'ui' => true]))->toBe([]);
});
```

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter RecordTest`
Expected: FAIL, `Call to undefined function pipeline_record_table()`.

- [ ] **Step 3: Write `record.php`**

Create `skills/pipeline/checks/record.php`:

```php
<?php

/**
 * A step's one manifest write (`../references/manifest.md` §What a leg writes). Pure: `dispatch_cli.php
 * record` reads the files and asks git; these functions build the manifest the step leaves from the
 * dispatch snapshot, or say why they will not. The table below is the contract the brief prints
 * (`pipeline_brief_return()`), so a brief cannot name a flag `record` refuses.
 */

enum ActionDisposition: string
{
    case Integrated = 'integrated';
    case Recorded = 'recorded';
    case OpenQuestion = 'open-question';
}

enum IssueLinkOutcome: string
{
    case Closes = 'closes';
    case StaysOpen = 'stays-open';
    case DroppedButCloses = 'dropped-but-closes';
}

/** Every flag `record` takes beside `--status`, without the dashes. */
const PIPELINE_RECORD_FLAGS = ['spec', 'plan', 'pr', 'proof', 'review-file', 'actions-file', 'issue-link', 'reason'];

/**
 * Per `<leg>:<step>` and status, the flags that step must pass and the ones it may. A status without a
 * row is one the step may not return (`LegStatus::allowedFor()`; `RecordTest` holds the two together).
 *
 * @return array<string, array<string, array{required: list<string>, optional: list<string>}>>
 */
function pipeline_record_table(): array
{
    $row = fn (array $required = [], array $optional = []) => ['required' => $required, 'optional' => $optional];
    $halted = ['halted' => $row(['reason'])];
    $gap = ['plan-insufficient' => $row(['reason'])];
    $review = ['continued' => $row(['review-file']), ...$gap, ...$halted];
    $finish = $row(['actions-file'], ['issue-link']);

    return [
        'design:run' => ['continued' => $row(['spec', 'plan']), ...$halted],
        'design:spec' => ['continued' => $row(['spec'], ['plan']), ...$halted],
        'design:plan' => ['continued' => $row(['plan']), ...$halted],
        'review-plan:review' => $review,
        'review-plan:resolve' => ['continued' => $row(['actions-file']), 'looped-back' => $row(['actions-file']), ...$halted],
        'handoff:run' => ['continued' => $row(['pr']), ...$gap, ...$halted],
        'implement:run' => ['continued' => $row(), ...$gap, ...$halted],
        'verify-ui:run' => ['continued' => $row(['proof']), 'looped-back' => $row([], ['proof']), ...$gap, ...$halted],
        'review-pr:review' => $review,
        'review-pr:resolve' => ['continued' => $finish, 'looped-back' => $finish, ...$halted],
    ];
}

/**
 * The manifest the step leaves, or why not (a string). It starts from the snapshot `$before`, keeps the
 * `suite` the file holds now (`$current`), and adds what `$given` and `$facts` say. `$given` is the
 * parsed flags with the review and actions files already read; `$facts` is what only the machine knows
 * (`dispatch_cli_record_facts()`), a fact git could not give being null.
 */
function pipeline_record(array $before, array $current, string $leg, string $step, array $given, array $facts): array|string
{
    $rows = pipeline_record_table()["{$leg}:{$step}"] ?? null;
    if ($rows === null) {
        return "{$leg} has no {$step} step";
    }
    $status = (string) ($given['status'] ?? '');
    $flags = array_diff_key($given, ['status' => true]);
    if (! isset($rows[$status])) {
        return "the {$leg} {$step} step cannot return {$status}; it may return " . implode(', ', array_keys($rows));
    }
    $problem = pipeline_record_flag_problem($rows[$status], array_keys($flags), "{$leg} {$step} with --status {$status}")
        ?? (trim((string) ($flags['reason'] ?? 'given')) === '' ? '--reason is empty: say why' : null);
    if ($problem !== null) {
        return $problem;
    }

    $manifest = pipeline_record_base($before, $current, $status);
    if ($status === LegStatus::Halted->value) {
        return [...$manifest, 'cursor' => [...$manifest['cursor'], 'reason' => trim($flags['reason'])]];
    }
    if ($facts['head'] === null) {
        return 'record needs the worktree\'s HEAD for last_sha, and `git rev-parse HEAD` failed';
    }
    $manifest['last_sha'] = $facts['head'];

    return match (true) {
        $status === LegStatus::PlanInsufficient->value => pipeline_record_gap($manifest, $leg, trim($flags['reason']), $facts),
        $leg === 'design' => pipeline_record_design($manifest, $step, $flags, $facts),
        $step === 'review' => pipeline_record_review($manifest, $leg, $flags['review-file'], $facts),
        $step === 'resolve' => pipeline_record_resolve($manifest, $leg, $status, $flags),
        $leg === 'handoff' => pipeline_record_pr($manifest, $flags['pr']),
        $leg === 'verify-ui' => pipeline_record_verified($manifest, $status, $flags, $facts),
        default => $manifest,
    };
}

/** A flag the row does not list, or a required one that is missing, in words that name the row's flags. */
function pipeline_record_flag_problem(array $row, array $given, string $label): ?string
{
    $listed = [...$row['required'], ...$row['optional']];
    $dashed = fn (array $flags) => implode(', ', array_map(fn (string $flag) => "--{$flag}", $flags));
    $unlisted = array_values(array_diff($given, $listed));
    $missing = array_values(array_diff($row['required'], $given));

    return match (true) {
        $unlisted !== [] => "{$dashed($unlisted)} is not a flag of {$label}, which takes " . ($listed === [] ? 'no flag' : $dashed($listed)),
        $missing !== [] => "{$label} needs {$dashed($missing)}",
        default => null,
    };
}

/** The snapshot with the file's current `suite` and the step's status: what every record starts from. */
function pipeline_record_base(array $before, array $current, string $status): array
{
    return [
        ...$before,
        ...(array_key_exists('suite', $current) ? ['suite' => $current['suite']] : []),
        'cursor' => [...array_diff_key($before['cursor'], ['reason' => true]), 'status' => $status],
    ];
}

function pipeline_record_append(array $manifest, array $entry): array
{
    return [...$manifest, 'gate_ledger' => [...pipeline_ledger($manifest), $entry]];
}

/** `plan-insufficient`: an escalation on a Bounded design, a plan gap on an Architectural one (`../references/engine.md` §Design size). */
function pipeline_record_gap(array $manifest, string $leg, string $reason, array $facts): array
{
    $entry = $facts['size'] === DesignSize::Bounded
        ? ['gate' => 'design-size', 'leg' => $leg, 'at' => $facts['now'], 'reason' => $reason, 'outcome' => 'escalated']
        : ['gate' => 'plan-approval', 'leg' => $leg, 'cycle' => pipeline_next_cycle(pipeline_ledger($manifest), 'plan-approval'), 'at' => $facts['now'], 'reason' => $reason, 'outcome' => 'looped-back'];

    return pipeline_record_append($manifest, $entry);
}

/** A design step's artifacts: committed paths, relative to the worktree; the spec step sets or removes the plan as the spec's size calls for. */
function pipeline_record_design(array $manifest, string $step, array $flags, array $facts): array|string
{
    $paths = array_map(
        fn (string $path) => pipeline_relative_path((string) $manifest['worktree'], $path),
        array_intersect_key($flags, ['spec' => true, 'plan' => true]),
    );
    foreach ($paths as $name => $path) {
        if (! in_array($path, $facts['committed'], true)) {
            return "the {$name} {$path} does not exist at HEAD (`git cat-file -e HEAD:{$path}` failed): commit it first";
        }
    }
    $artifacts = $manifest['artifacts'] ?? [];
    if ($step === 'spec') {
        $bounded = $facts['size'] === DesignSize::Bounded;
        if ($bounded !== isset($paths['plan'])) {
            return $bounded
                ? 'a Bounded spec needs --plan: its spec step commits the plan too'
                : 'an Architectural spec takes no --plan: the plan step writes the plan';
        }
        $artifacts = array_diff_key($artifacts, ['plan' => true]);
    }

    return [...$manifest, 'artifacts' => [...$artifacts, ...$paths]];
}

/** A review step's open entry: the review verbatim, less trailing newlines, and what the machine stamps. */
function pipeline_record_review(array $manifest, string $leg, string $review, array $facts): array|string
{
    if (trim($review) === '') {
        return 'the review file is empty';
    }
    if ($facts['annotations'] === null) {
        return 'record needs the branch\'s diff for the entry\'s annotations, and `git diff <base>...HEAD` failed';
    }
    $gate = pipeline_gate_of($leg);

    return pipeline_record_append($manifest, [
        'gate' => $gate,
        'leg' => $leg,
        'cycle' => pipeline_next_cycle(pipeline_ledger($manifest), $gate),
        'at' => $facts['now'],
        'review' => rtrim($review, "\r\n"),
        'annotations' => $facts['annotations'],
        ...($gate === 'pr-review' ? ['reviewed_sha' => $facts['head']] : []),
    ]);
}

/** A resolve step completes the open entry: `actions`, `issue_links` when given, and the status as `outcome`. */
function pipeline_record_resolve(array $manifest, string $leg, string $status, array $flags): array|string
{
    $actions = pipeline_record_actions($flags['actions-file']);
    $links = is_string($actions) ? $actions : pipeline_record_issue_links($flags['issue-link'] ?? []);
    if (is_string($links)) {
        return $links;
    }
    $gate = pipeline_gate_of($leg);
    $ledger = pipeline_ledger($manifest);
    $open = pipeline_open_entry($ledger, $gate);
    if ($open === null) {
        return "no open {$gate} review to complete";
    }
    $ledger[$open] = [...$ledger[$open], 'actions' => $actions, ...($links === [] ? [] : ['issue_links' => $links]), 'outcome' => $status];

    return [...$manifest, 'gate_ledger' => $ledger];
}

/** @return list<array{claim: string, disposition: string, note: string}>|string the actions, or why the file does not hold them */
function pipeline_record_actions(string $json): array|string
{
    $actions = json_decode($json, true);
    if (! is_array($actions) || ! array_is_list($actions)) {
        return 'the actions file is not a JSON list of {claim, disposition, note}';
    }
    foreach ($actions as $index => $action) {
        $problem = pipeline_record_action_problem($action);
        if ($problem !== null) {
            return "actions[{$index}]{$problem}";
        }
    }

    return $actions;
}

/** What is wrong with one action, as the words after `actions[n]`, or null. */
function pipeline_record_action_problem(mixed $action): ?string
{
    $keys = ['claim', 'disposition', 'note'];
    $ticked = fn (array $names) => implode(', ', array_map(fn (int|string $name) => "`{$name}`", $names));
    if (! is_array($action)) {
        return ' is not an object';
    }
    $unknown = array_values(array_diff(array_keys($action), $keys));
    $missing = array_values(array_diff($keys, array_keys($action)));

    return match (true) {
        $unknown !== [] => " has an unknown key {$ticked($unknown)}",
        $missing !== [] => " has no {$ticked($missing)}",
        ! is_string($action['claim']) || trim($action['claim']) === '' => ': `claim` is not a non-empty string',
        ! is_string($action['disposition']) || ActionDisposition::tryFrom($action['disposition']) === null
            => ': `disposition` is not one of ' . implode(', ', array_column(ActionDisposition::cases(), 'value')),
        ! is_string($action['note']) => ': `note` is not a string',
        default => null,
    };
}

/** @return list<array{issue: int, outcome: string}>|string `issue_links` from `<n>=<outcome>` flags, or the flag that is not one */
function pipeline_record_issue_links(array $given): array|string
{
    $links = [];
    foreach ($given as $link) {
        if (preg_match('/^(\d+)=(.+)$/', $link, $match) !== 1 || IssueLinkOutcome::tryFrom($match[2]) === null) {
            return "--issue-link {$link} is not <issue number>=<outcome>, the outcome one of " . implode(', ', array_column(IssueLinkOutcome::cases(), 'value'));
        }
        $links[] = ['issue' => (int) $match[1], 'outcome' => $match[2]];
    }

    return $links;
}

function pipeline_record_pr(array $manifest, string $pr): array|string
{
    return ctype_digit($pr)
        ? [...$manifest, 'artifacts' => [...($manifest['artifacts'] ?? []), 'pr' => (int) $pr]]
        : "--pr {$pr} is not a PR number";
}

/** `verify-ui`: the proof page when given, and the thin entry that carries the loop bound (`../references/manifest.md` §gate_ledger). */
function pipeline_record_verified(array $manifest, string $status, array $flags, array $facts): array
{
    $artifacts = [...($manifest['artifacts'] ?? []), ...array_intersect_key($flags, ['proof' => true])];

    return pipeline_record_append([...$manifest, 'artifacts' => $artifacts], [
        'gate' => 'verify-ui',
        'cycle' => pipeline_next_cycle(pipeline_ledger($manifest), 'verify-ui'),
        'at' => $facts['now'],
        'outcome' => $status,
    ]);
}

/** The index of the ledger entry this record appended or completed, or null when it touched none. */
function pipeline_record_entry(array $before, array $candidate, string $leg, string $step): ?int
{
    [$old, $new] = [pipeline_ledger($before), pipeline_ledger($candidate)];

    return match (true) {
        count($new) > count($old) => array_key_last($new),
        $step === 'resolve' && $new !== $old => pipeline_open_entry($old, pipeline_gate_of($leg)),
        default => null,
    };
}

/** @return list<string> the keys the file held changed against the snapshot and `record` did not keep: all but `suite` */
function pipeline_record_replaced(array $before, array $current): array
{
    return array_values(array_diff(pipeline_changed_keys(pipeline_normalized($before), pipeline_normalized($current)), ['suite']));
}

/** @return list<string> the content triggers that fired, by name (`../references/gates.md` §content triggers); `ui` is a leg, not an annotation */
function pipeline_annotations(array $triggers): array
{
    return array_keys(array_filter(array_intersect_key($triggers, ['package' => true, 'migration' => true, 'auth' => true])));
}
```

In `skills/pipeline/checks/tests/Pest.php`, add `'record.php'` to the list of files, directly after
`'brief.php'`.

- [ ] **Step 4: Run the tests to see them pass**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter RecordTest`
Expected: PASS, every test in `RecordTest.php`.

Then `php -l skills/pipeline/checks/record.php`. Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/record.php skills/pipeline/checks/tests/RecordTest.php skills/pipeline/checks/tests/Pest.php
git commit -m "feat(pipeline): record.php builds the manifest a step leaves, from the snapshot and its flags (#52)"
```

---

### Task 3: The `record` command

**Files:**
- Modify: `skills/pipeline/checks/dispatch_cli.php` (header comment, `require_once`, new functions, the
  final `match`, the usage line, the exit code)
- Test: `skills/pipeline/checks/tests/RecordCliTest.php` (create)

**Interfaces:**
- Consumes: `pipeline_record()`, `pipeline_record_entry()`, `pipeline_record_replaced()`,
  `pipeline_annotations()`, `PIPELINE_RECORD_FLAGS` (Task 2); `manifest_files()`,
  `pipeline_relative_path()`, `dispatch_cli_exists_at()` (Task 1); the existing
  `dispatch_cli_snapshot_step()`, `dispatch_cli_design_size()`, `dispatch_cli_git()`,
  `dispatch_cli_invalid()`, `pipeline_base_ref()`, `pipeline_kickoff_default_branch()`,
  `pipeline_triggers()`, `pipeline_return_problem()`.
- Produces:
  - `dispatch_cli_refuse(string $reason): array{action: 'refused', reason: string}`
  - `dispatch_cli_write_problem(string $manifestPath): ?string`
  - `dispatch_cli_base_ref(array $manifest, callable $git): ?string` — `pipeline_base_ref()`, else origin's
    own default branch, fetched (spec Assumption 21)
  - `dispatch_cli_write(string $manifestPath, array $manifest): ?array` — null when the write landed, else
    the refusal
  - `dispatch_cli_record(string $manifestPath, string $leg, string $step, array $given): array`
  - `dispatch_cli_record_command(array $arguments): ?array`
  - test helpers `record_fixture()` and `record_cli()`, which Task 4 and Task 6 reuse

- [ ] **Step 1: Write the failing tests**

Create `skills/pipeline/checks/tests/RecordCliTest.php`. `rereview_repo()` and `rereview_commit()` come from
`ReviewScopeTest.php`, `base_repo()` from `BaseStateTest.php`, `dispatch_fixture()`, `dispatch_cli()` and
`boundary_brief()` from `DispatchCliTest.php`; Pest loads every test file, so they are in scope.

```php
<?php

/**
 * A throwaway repo on `feature` (with `origin/main` and `origin/HEAD`) that holds a committed spec and
 * plan, an autoflow manifest on `$leg`, and that step's `brief` already run, so its snapshot exists.
 * `$dir` is another repo to build it in: `base_repo()` (`BaseStateTest.php`) has a real `origin`.
 */
function record_fixture(string $leg, string $step, array $manifest = [], string $size = 'Architectural', ?string $dir = null): array
{
    $dir ??= rereview_repo();
    rereview_commit($dir, ['spec.md' => "# x — design\n\n**Design size:** {$size}\n", 'plan.md' => "# x Implementation Plan\n"]);
    $fixture = dispatch_fixture([
        'mode' => 'autoflow', 'worktree' => $dir, 'cursor' => ['leg' => $leg, 'status' => 'pending'],
        'artifacts' => ['spec' => 'spec.md', 'plan' => 'plan.md', 'pr' => null, 'issue' => null],
        ...$manifest,
    ]);
    $files = manifest_files($fixture['manifest']);
    expect(dispatch_cli(['brief', $fixture['manifest'], $leg, $step])['stdout'])->toContain("`{$leg}` leg, `{$step}` step");

    return [...$fixture, 'repo' => $dir, 'review' => $files['review'], 'actions' => $files['actions']];
}

function record_cli(array $fixture, string $leg, string $step, array $flags): array
{
    return dispatch_cli(['record', $fixture['manifest'], $leg, $step, ...$flags]);
}

it('records a step\'s return, exits 0, and leaves a manifest the next brief accepts', function () {
    $fixture = record_fixture('handoff', 'run');
    $head = pipeline_git($fixture['repo'], ['rev-parse', 'HEAD']);

    $result = record_cli($fixture, 'handoff', 'run', ['--status', 'continued', '--pr', '7']);

    expect($result['code'])->toBe(0);
    expect($result['json'])->toBe(['action' => 'recorded', 'leg' => 'handoff', 'step' => 'run', 'status' => 'continued', 'last_sha' => $head, 'entry' => null, 'replaced' => []]);
    expect(manifest_read($fixture['manifest']))->toMatchArray(['last_sha' => $head, 'cursor' => ['leg' => 'handoff', 'status' => 'continued']]);
    expect(manifest_read($fixture['manifest'])['artifacts']['pr'])->toBe(7);
    expect(boundary_brief($fixture, 'implement', 'run', 'handoff:run', ['--status', 'continued'])['stdout'])->toContain('`implement` leg, `run` step');
});

it('refuses with exit 1 and leaves the manifest byte-identical', function () {
    $fixture = record_fixture('handoff', 'run');
    $bytes = file_get_contents($fixture['manifest']);

    $result = record_cli($fixture, 'handoff', 'run', ['--status', 'continued']);

    expect($result['code'])->toBe(1);
    expect($result['json'])->toBe(['action' => 'refused', 'reason' => 'handoff run with --status continued needs --pr']);
    expect(file_get_contents($fixture['manifest']))->toBe($bytes);
});

it('refuses the dispatcher\'s snapshot by name, and says where the manifest is', function () {
    $fixture = record_fixture('handoff', 'run');
    $bytes = file_get_contents($fixture['before']);

    $result = dispatch_cli(['record', $fixture['before'], 'handoff', 'run', '--status', 'continued', '--pr', '7']);

    expect($result['code'])->toBe(1);
    expect($result['json']['reason'])->toBe("{$fixture['before']} is the dispatcher's snapshot; the manifest is {$fixture['manifest']}");
    expect(file_get_contents($fixture['before']))->toBe($bytes);
});

it('refuses without a snapshot, on a snapshot of another step, and on a manifest it cannot read', function () {
    $bare = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'handoff', 'status' => 'pending']]);
    expect(record_cli($bare, 'handoff', 'run', ['--status', 'continued', '--pr', '7'])['json'])
        ->toBe(['action' => 'refused', 'reason' => "no snapshot at {$bare['before']}: record follows this step's brief (next in interactive)"]);

    $fixture = record_fixture('handoff', 'run');
    expect(record_cli($fixture, 'implement', 'run', ['--status', 'continued'])['json'])
        ->toBe(['action' => 'refused', 'reason' => 'the snapshot is of the handoff run step, not implement run']);

    $missing = dispatch_cli(['record', '/nonexistent/manifest.json', 'handoff', 'run', '--status', 'continued', '--pr', '7']);
    expect($missing['code'])->toBe(1);
    expect($missing['json'])->toBe(['action' => 'refused', 'reason' => 'no readable manifest at /nonexistent/manifest.json']);
});

it('appends a review from its file, stamped with the HEAD it reviewed and the triggers of the diff', function () {
    $fixture = record_fixture('review-pr', 'review');
    mkdir($fixture['repo'] . '/database/migrations', 0777, true);
    $head = rereview_commit($fixture['repo'], ['database/migrations/2026_09_30_000000_add_x.php' => "<?php\n"]);
    file_put_contents($fixture['review'], "The migration has no down().\n\n");

    $result = record_cli($fixture, 'review-pr', 'review', ['--status', 'continued', '--review-file', $fixture['review']]);

    expect($result['json'])->toMatchArray(['action' => 'recorded', 'entry' => 0, 'last_sha' => $head]);
    $entry = manifest_read($fixture['manifest'])['gate_ledger'][0];
    expect($entry)->toMatchArray(['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'review' => 'The migration has no down().', 'annotations' => ['migration'], 'reviewed_sha' => $head]);
    expect($entry['at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
    expect($entry)->not->toHaveKey('outcome');
    expect(boundary_brief($fixture, 'review-pr', 'resolve', 'review-pr:review', ['--status', 'continued'])['stdout'])->toContain('`review-pr` leg, `resolve` step');
});

it('records a review in a checkout without origin/HEAD, against the default branch origin itself names', function () {
    $dir = base_repo();
    pipeline_git($dir, ['symbolic-ref', '-d', 'refs/remotes/origin/HEAD']);
    $fixture = record_fixture('review-pr', 'review', dir: $dir);
    mkdir($dir . '/database/migrations', 0777, true);
    rereview_commit($dir, ['database/migrations/2026_09_30_000000_add_x.php' => "<?php\n"]);
    file_put_contents($fixture['review'], 'The migration has no down().');

    $result = record_cli($fixture, 'review-pr', 'review', ['--status', 'continued', '--review-file', $fixture['review']]);

    expect($result['json'])->toMatchArray(['action' => 'recorded', 'entry' => 0]);
    expect(manifest_read($fixture['manifest'])['gate_ledger'][0]['annotations'])->toBe(['migration']);
});

it('refuses a review where neither origin/HEAD nor origin gives a base, and still records the halt', function () {
    $fixture = record_fixture('review-plan', 'review');
    pipeline_git($fixture['repo'], ['symbolic-ref', '-d', 'refs/remotes/origin/HEAD']);
    $bytes = file_get_contents($fixture['manifest']);
    file_put_contents($fixture['review'], 'Step 4 drops the link.');

    $result = record_cli($fixture, 'review-plan', 'review', ['--status', 'continued', '--review-file', $fixture['review']]);

    expect($result['code'])->toBe(1);
    expect($result['json'])->toBe(['action' => 'refused', 'reason' => 'record needs the branch\'s diff for the entry\'s annotations, and `git diff <base>...HEAD` failed']);
    expect(file_get_contents($fixture['manifest']))->toBe($bytes);
    expect(record_cli($fixture, 'review-plan', 'review', ['--status', 'halted', '--reason', 'the base does not resolve'])['json'])
        ->toMatchArray(['action' => 'recorded', 'status' => 'halted']);
});

it('refuses a review or actions file that is missing or older than the step\'s snapshot', function () {
    $fixture = record_fixture('review-plan', 'review');
    $record = fn () => record_cli($fixture, 'review-plan', 'review', ['--status', 'continued', '--review-file', $fixture['review']]);

    expect($record()['json'])->toBe(['action' => 'refused', 'reason' => "--review-file {$fixture['review']} is not a file"]);

    file_put_contents($fixture['review'], 'The review of an earlier cycle.');
    touch($fixture['review'], time() - 60);
    expect($record()['json'])->toBe(['action' => 'refused', 'reason' => "--review-file {$fixture['review']} is older than this step's snapshot: write this step's review to it first"]);

    touch($fixture['review']);
    expect($record()['json']['action'])->toBe('recorded');
});

it('completes the open review from the actions file, and finish accepts the run', function () {
    $open = ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => '2026-09-30T10:00:00Z', 'review' => 'r', 'annotations' => [], 'reviewed_sha' => str_repeat('a', 40)];
    $fixture = record_fixture('review-pr', 'resolve', ['gate_ledger' => [$open]]);
    file_put_contents($fixture['actions'], json_encode([['claim' => 'x', 'disposition' => 'recorded', 'note' => 'no edit']]));

    $result = record_cli($fixture, 'review-pr', 'resolve', ['--status', 'continued', '--actions-file', $fixture['actions'], '--issue-link', '52=closes', '--issue-link', '122=closes']);

    expect($result['json'])->toMatchArray(['action' => 'recorded', 'entry' => 0]);
    expect(manifest_read($fixture['manifest'])['gate_ledger'][0])->toBe([
        ...$open,
        'actions' => [['claim' => 'x', 'disposition' => 'recorded', 'note' => 'no edit']],
        'issue_links' => [['issue' => 52, 'outcome' => 'closes'], ['issue' => 122, 'outcome' => 'closes']],
        'outcome' => 'continued',
    ]);
    expect(dispatch_cli(['finish', $fixture['manifest'], '{"action":"done"}'])['json'])->toBe(['action' => 'done']);
});

it('sets the spec and removes the plan for an Architectural spec step, and refuses a spec that is not committed', function () {
    $fixture = record_fixture('design', 'spec');
    file_put_contents($fixture['repo'] . '/draft.md', "# draft — design\n");

    expect(record_cli($fixture, 'design', 'spec', ['--status', 'continued', '--spec', 'draft.md'])['json'])
        ->toBe(['action' => 'refused', 'reason' => 'the spec draft.md does not exist at HEAD (`git cat-file -e HEAD:draft.md` failed): commit it first']);

    expect(record_cli($fixture, 'design', 'spec', ['--status', 'continued', '--spec', $fixture['repo'] . '/spec.md'])['json']['action'])->toBe('recorded');
    expect(manifest_read($fixture['manifest'])['artifacts'])->toBe(['spec' => 'spec.md', 'pr' => null, 'issue' => null]);
    expect(boundary_brief($fixture, 'design', 'plan', 'design:spec', ['--status', 'continued', '--size', 'Architectural'])['stdout'])->toContain('`design` leg, `plan` step');
});

it('requires the plan of a Bounded spec step, by the size of the spec it is given', function () {
    $fixture = record_fixture('design', 'spec', size: 'Bounded');

    expect(record_cli($fixture, 'design', 'spec', ['--status', 'continued', '--spec', 'spec.md'])['json'])
        ->toBe(['action' => 'refused', 'reason' => 'a Bounded spec needs --plan: its spec step commits the plan too']);
    expect(record_cli($fixture, 'design', 'spec', ['--status', 'continued', '--spec', 'spec.md', '--plan', 'plan.md'])['json']['action'])->toBe('recorded');
});

it('records a plan gap on an Architectural design and an escalation on a Bounded one', function (string $size, array $entry) {
    $fixture = record_fixture('implement', 'run', size: $size);

    $result = record_cli($fixture, 'implement', 'run', ['--status', 'plan-insufficient', '--reason', '--force is missing from the plan']);

    expect($result['json'])->toMatchArray(['action' => 'recorded', 'status' => 'plan-insufficient', 'entry' => 0]);
    expect(manifest_read($fixture['manifest'])['gate_ledger'][0])->toMatchArray([...$entry, 'leg' => 'implement', 'reason' => '--force is missing from the plan']);
})->with([
    'Architectural' => ['Architectural', ['gate' => 'plan-approval', 'cycle' => 1, 'outcome' => 'looped-back']],
    'Bounded' => ['Bounded', ['gate' => 'design-size', 'outcome' => 'escalated']],
]);

it('records a halt outside a git repository, and refuses any other status there by naming the git call', function () {
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'handoff', 'status' => 'pending']]);
    dispatch_cli(['brief', $fixture['manifest'], 'handoff', 'run']);

    expect(record_cli($fixture, 'handoff', 'run', ['--status', 'continued', '--pr', '7'])['json'])
        ->toBe(['action' => 'refused', 'reason' => 'record needs the worktree\'s HEAD for last_sha, and `git rev-parse HEAD` failed']);

    $halt = record_cli($fixture, 'handoff', 'run', ['--status', 'halted', '--reason', 'gh is not logged in']);
    expect($halt['code'])->toBe(0);
    expect($halt['json'])->toMatchArray(['action' => 'recorded', 'status' => 'halted']);
    expect(manifest_read($fixture['manifest'])['cursor'])->toBe(['leg' => 'handoff', 'status' => 'halted', 'reason' => 'gh is not logged in']);
});

it('refuses a proof page that is not a file', function () {
    $fixture = record_fixture('verify-ui', 'run');

    expect(record_cli($fixture, 'verify-ui', 'run', ['--status', 'continued', '--proof', '/nonexistent/index.html'])['json'])
        ->toBe(['action' => 'refused', 'reason' => '--proof /nonexistent/index.html is not a file']);

    file_put_contents($fixture['dir'] . '/index.html', '<html>');
    expect(record_cli($fixture, 'verify-ui', 'run', ['--status', 'continued', '--proof', $fixture['dir'] . '/index.html'])['json'])->toMatchArray(['action' => 'recorded', 'entry' => 0]);
    expect(manifest_read($fixture['manifest'])['artifacts']['proof'])->toBe($fixture['dir'] . '/index.html');
});

it('replaces a hand edit and an earlier record, and names what it did not keep', function () {
    $fixture = record_fixture('handoff', 'run');
    dispatch_leg_writes($fixture['manifest'], fn (array $m) => [...$m, 'branch' => 'other', 'artifacts' => [...$m['artifacts'], 'pr' => 99]]);

    $result = record_cli($fixture, 'handoff', 'run', ['--status', 'continued', '--pr', '7']);

    expect($result['json']['replaced'])->toEqualCanonicalizing(['branch', 'artifacts']);
    expect(manifest_read($fixture['manifest']))->toMatchArray(['branch' => 'feature/x']);
    expect(manifest_read($fixture['manifest'])['artifacts']['pr'])->toBe(7);
    expect(record_cli($fixture, 'handoff', 'run', ['--status', 'continued', '--pr', '8'])['json']['replaced'])->toEqualCanonicalizing(['artifacts', 'last_sha', 'cursor.status']);
    expect(manifest_read($fixture['manifest'])['artifacts']['pr'])->toBe(8);
});

it('refuses a review that cannot be written as JSON, and leaves the manifest as it was', function () {
    $fixture = record_fixture('review-plan', 'review');
    $bytes = file_get_contents($fixture['manifest']);
    file_put_contents($fixture['review'], "Not UTF-8: \xB1\x31");

    $result = record_cli($fixture, 'review-plan', 'review', ['--status', 'continued', '--review-file', $fixture['review']]);

    expect($result['code'])->toBe(1);
    expect($result['json']['reason'])->toStartWith('the result cannot be written as JSON: ');
    expect(file_get_contents($fixture['manifest']))->toBe($bytes);
});

it('refuses when the write does not land, and prints only the JSON line', function () {
    $fixture = record_fixture('handoff', 'run');
    chmod($fixture['manifest'], 0444);

    $result = record_cli($fixture, 'handoff', 'run', ['--status', 'continued', '--pr', '7']);
    chmod($fixture['manifest'], 0644);

    expect($result['code'])->toBe(1);
    expect($result['json'])->toBe(['action' => 'refused', 'reason' => "the write did not land at {$fixture['manifest']}"]);
    expect(manifest_read($fixture['manifest'])['cursor'])->toBe(['leg' => 'handoff', 'status' => 'pending']);
});

it('records an interactive step, and returned accepts it', function () {
    $dir = rereview_repo();
    $fixture = dispatch_fixture(['mode' => 'interactive', 'worktree' => $dir]);
    $files = manifest_files($fixture['manifest']);
    expect(dispatch_cli(['next', $fixture['manifest']])['json'])->toMatchArray(['action' => 'dispatch', 'leg' => 'review-plan', 'step' => 'review']);
    file_put_contents($files['review'], 'Step 4 drops the link.');

    expect(record_cli($fixture, 'review-plan', 'review', ['--status', 'continued', '--review-file', $files['review']])['json']['action'])->toBe('recorded');
    expect(dispatch_cli(['returned', $fixture['manifest'], $fixture['diff']])['json'])->toMatchArray(['action' => 'dispatch', 'leg' => 'review-plan', 'step' => 'resolve']);
});

it('refuses a record it cannot parse as a usage error', function (array $arguments) {
    $result = dispatch_cli(['record', ...$arguments]);

    expect($result['code'])->toBe(1);
    expect($result['stdout'])->toBe('');
})->with([
    'no status' => [['/tmp/m.json', 'handoff', 'run', '--pr', '7']],
    'no step' => [['/tmp/m.json', 'handoff', '--status', 'continued']],
    'a flag without a value' => [['/tmp/m.json', 'handoff', 'run', '--status', 'continued', '--pr']],
    'a flag record does not know' => [['/tmp/m.json', 'handoff', 'run', '--status', 'continued', '--number', '7']],
    'a flag given twice' => [['/tmp/m.json', 'handoff', 'run', '--status', 'continued', '--pr', '7', '--pr', '8']],
]);
```

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter RecordCliTest`
Expected: FAIL. Every `record` call exits 1 with the usage on stderr, so `$result['json']` is null; the
*usage error* dataset passes already.

- [ ] **Step 3: Add the command to `dispatch_cli.php`**

1. After `require_once __DIR__ . '/brief.php';` add `require_once __DIR__ . '/record.php';`.

2. Add these functions above the final `$result = match (…)`:

```php
/** `record`'s and `suite`'s no: exit 1, the manifest untouched. */
function dispatch_cli_refuse(string $reason): array
{
    return ['action' => 'refused', 'reason' => $reason];
}

/** Why a step may not write at `$manifestPath`, or null: the snapshot's own path (#122), no manifest, an invalid one. */
function dispatch_cli_write_problem(string $manifestPath): ?string
{
    if (str_ends_with($manifestPath, '.before.json')) {
        return "{$manifestPath} is the dispatcher's snapshot; the manifest is " . substr($manifestPath, 0, -strlen('.before.json')) . '.json';
    }
    $manifest = manifest_read($manifestPath);

    return $manifest === null ? "no readable manifest at {$manifestPath}" : dispatch_cli_invalid($manifest);
}

/** Writes the manifest and reads it back: null when it landed, else the refusal. A result that is no JSON is never written. */
function dispatch_cli_write(string $manifestPath, array $manifest): ?array
{
    if (json_encode($manifest) === false) {
        return dispatch_cli_refuse('the result cannot be written as JSON: ' . json_last_error_msg());
    }
    @manifest_write($manifestPath, $manifest); // the read-back below is the check; a warning would break the one JSON line

    return pipeline_normalized(manifest_read($manifestPath) ?? []) === pipeline_normalized($manifest)
        ? null
        : dispatch_cli_refuse("the write did not land at {$manifestPath}");
}

/**
 * `$given` with `--review-file` and `--actions-file` replaced by what they hold, or why not: each is a file
 * no older than the step's snapshot, so a step cannot hand in the file of an earlier one; `--proof` is a file.
 */
function dispatch_cli_record_files(string $snapshotPath, array $given): array|string
{
    foreach (['review-file' => 'review', 'actions-file' => 'actions'] as $flag => $what) {
        if (! isset($given[$flag])) {
            continue;
        }
        $path = $given[$flag];
        if (! is_file($path)) {
            return "--{$flag} {$path} is not a file";
        }
        if (filemtime($path) < filemtime($snapshotPath)) {
            return "--{$flag} {$path} is older than this step's snapshot: write this step's {$what} to it first";
        }
        $given[$flag] = (string) file_get_contents($path);
    }

    return isset($given['proof']) && ! is_file($given['proof']) ? "--proof {$given['proof']} is not a file" : $given;
}

/**
 * The base to diff against: `pipeline_base_ref()`, else origin's default branch as origin itself names
 * it, fetched, as kickoff resolves it; null when neither gives one. A checkout without `origin/HEAD`
 * must not cost a review step its review.
 */
function dispatch_cli_base_ref(array $manifest, callable $git): ?string
{
    $base = pipeline_base_ref($manifest, $git);
    if ($base !== null) {
        return $base;
    }
    try {
        $branch = pipeline_kickoff_default_branch(rtrim((string) $manifest['worktree'], '/'));
    } catch (PipelineKickoffHalt) {
        return null;
    }
    [$code] = $git(['fetch', '-q', 'origin', "+refs/heads/{$branch}:refs/remotes/origin/{$branch}"]);

    return $code === 0 ? "origin/{$branch}" : null;
}

/** The content triggers that fired over the branch's diff against its base, by name; null when git cannot give the diff. */
function dispatch_cli_annotations(array $manifest, callable $git): ?array
{
    $base = dispatch_cli_base_ref($manifest, $git);
    [$code, $diff] = $base === null ? [1, ''] : $git(['diff', "{$base}...HEAD"]);
    if ($code !== 0) {
        return null;
    }
    $composer = rtrim((string) $manifest['worktree'], '/') . '/composer.json';
    $package = is_file($composer) ? json_decode((string) file_get_contents($composer), true)['name'] ?? null : null;

    return pipeline_annotations(pipeline_triggers($diff, $package));
}

/**
 * What only the machine knows, for `pipeline_record()`: `now`, the design size of the spec the step
 * names (else the snapshot's), and from git `head`, `annotations` and which of the given spec and plan
 * paths exist at `HEAD`. A halt asks git nothing, so a step can always record its halt.
 *
 * @return array{head: ?string, now: string, annotations: ?list<string>, size: DesignSize, committed: list<string>}
 */
function dispatch_cli_record_facts(array $before, array $given): array
{
    $sized = [...$before, 'artifacts' => [...($before['artifacts'] ?? []), ...array_intersect_key($given, ['spec' => true])]];
    $facts = ['head' => null, 'now' => gmdate('Y-m-d\TH:i:s\Z'), 'annotations' => null, 'size' => dispatch_cli_design_size($sized), 'committed' => []];
    if ($given['status'] === LegStatus::Halted->value) {
        return $facts;
    }
    $worktree = rtrim((string) $before['worktree'], '/');
    $git = dispatch_cli_git($before);
    [$code, $head] = $git(['rev-parse', 'HEAD']);
    $paths = array_map(
        fn (string $path) => pipeline_relative_path($worktree, $path),
        array_values(array_intersect_key($given, ['spec' => true, 'plan' => true])),
    );

    return [
        ...$facts,
        'head' => $code === 0 ? $head : null,
        'annotations' => dispatch_cli_annotations($before, $git),
        'committed' => array_values(array_filter($paths, fn (string $path) => dispatch_cli_exists_at($worktree, 'HEAD', $path))),
    ];
}

/**
 * A step's one manifest write (`../references/manifest.md` §What a leg writes): the snapshot of the step
 * `brief` or `next` ran for, plus what the flags add, checked by the next boundary's own
 * `pipeline_return_problem()` before it is written, and read back after.
 */
function dispatch_cli_record(string $manifestPath, string $leg, string $step, array $given): array
{
    $problem = dispatch_cli_write_problem($manifestPath);
    if ($problem !== null) {
        return dispatch_cli_refuse($problem);
    }
    $snapshotPath = manifest_files($manifestPath)['before'];
    $before = manifest_read($snapshotPath);
    $snapshot = dispatch_cli_snapshot_step($before);
    if ($snapshot !== "{$leg}:{$step}") {
        return dispatch_cli_refuse($snapshot === null
            ? "no snapshot at {$snapshotPath}: record follows this step's brief (next in interactive)"
            : 'the snapshot is of the ' . str_replace(':', ' ', $snapshot) . " step, not {$leg} {$step}");
    }
    $given = dispatch_cli_record_files($snapshotPath, $given);
    if (is_string($given)) {
        return dispatch_cli_refuse($given);
    }
    $facts = dispatch_cli_record_facts($before, $given);
    $current = (array) manifest_read($manifestPath);
    $candidate = pipeline_record($before, $current, $leg, $step, $given, $facts);
    if (is_string($candidate)) {
        return dispatch_cli_refuse($candidate);
    }
    $problem = pipeline_return_problem(pipeline_normalized($before), pipeline_normalized($candidate), $leg, $step, $facts['size']);
    if ($problem !== null) {
        return dispatch_cli_refuse("the next check would halt on this: {$problem}");
    }

    return dispatch_cli_write($manifestPath, $candidate) ?? [
        'action' => 'recorded',
        'leg' => $leg,
        'step' => $step,
        'status' => $given['status'],
        'last_sha' => $candidate['last_sha'] ?? null,
        'entry' => pipeline_record_entry($before, $candidate, $leg, $step),
        'replaced' => pipeline_record_replaced($before, $current),
    ];
}

/**
 * `record <manifest> <leg> <step> --status <status> [flags]`; null is a usage error: a flag `record` does
 * not know, one without a value, one given twice (`--issue-link` repeats), or no `--status`. A flag the
 * step's row does not list is a refusal, not a usage error (`pipeline_record()`).
 *
 * @return array{0: string, 1: string, 2: string, 3: array<string, string|list<string>>}|null
 */
function dispatch_cli_record_args(array $arguments): ?array
{
    $positional = [];
    $given = [];
    while ($arguments !== []) {
        $argument = (string) array_shift($arguments);
        if (! str_starts_with($argument, '--')) {
            $positional[] = $argument;

            continue;
        }
        $name = substr($argument, 2);
        $value = array_shift($arguments);
        if (! in_array($name, ['status', ...PIPELINE_RECORD_FLAGS], true) || $value === null) {
            return null;
        }
        if ($name === 'issue-link') {
            $given[$name][] = (string) $value;

            continue;
        }
        if (isset($given[$name])) {
            return null;
        }
        $given[$name] = (string) $value;
    }

    return count($positional) === 3 && isset($given['status']) ? [...$positional, $given] : null;
}

function dispatch_cli_record_command(array $arguments): ?array
{
    $parsed = dispatch_cli_record_args($arguments);

    return $parsed === null ? null : dispatch_cli_record(...$parsed);
}
```

3. In the final `match`, add after the `'ci'` arm:

```php
    'record' => dispatch_cli_record_command(array_slice($argv, 2)),
```

4. In the usage string, insert before ` (size needs a readable manifest`:
   ` | record <manifest> <leg> <step> --status <status> [--spec <path>] [--plan <path>] [--pr <number>] [--proof <path>] [--review-file <path>] [--actions-file <path>] [--issue-link <n>=<outcome>]... [--reason <text>]`

5. Replace the last two lines of the file,

```php
echo is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
exit(0);
```

with

```php
echo is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
exit(is_array($result) && ($result['action'] ?? null) === 'refused' ? 1 : 0);
```

6. In the header comment, add under the `autoflow:` list a line for both modes, and amend the closing
   paragraph. After the `ci` line add:

```
 *   both:         php dispatch_cli.php record <manifest> <leg> <step> --status <status> [flags]
```

   and replace the sentence `Exits 0 on every decision, a halt included.` with
   `Exits 0 on every decision, a halt included; \`record\` exits 1 on a refusal, which is no decision about
   the run and leaves the manifest untouched, so a failed write cannot be missed in an \`&&\` chain.`

- [ ] **Step 4: Run the tests to see them pass**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter RecordCliTest`
Expected: PASS, every test in `RecordCliTest.php`.

Then the whole suite, same command without `--filter`. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/dispatch_cli.php skills/pipeline/checks/tests/RecordCliTest.php
git commit -m "feat(pipeline): dispatch_cli record writes a step's return and refuses what the next check would halt on (#52)"
```

---

### Task 4: The `suite` command

**Files:**
- Modify: `skills/pipeline/checks/dispatch_cli.php` (two functions, the `match`, the usage line, the header)
- Test: `skills/pipeline/checks/tests/RecordCliTest.php` (append)

**Interfaces:**
- Consumes: `dispatch_cli_refuse()`, `dispatch_cli_write_problem()`, `dispatch_cli_write()`,
  `record_fixture()`, `record_cli()` (Task 3); the existing `pipeline_tree_key()`, which throws
  `RuntimeException` when git fails.
- Produces: `dispatch_cli_suite(string $manifestPath, string $outcome, int $passed, int $failed): array`,
  `dispatch_cli_suite_command(array $arguments): ?array`.

- [ ] **Step 1: Write the failing tests**

Append to `skills/pipeline/checks/tests/RecordCliTest.php`:

```php
it('writes the suite with the worktree\'s tree key and changes nothing else', function () {
    $fixture = record_fixture('implement', 'run');
    $before = manifest_read($fixture['manifest']);

    $result = dispatch_cli(['suite', $fixture['manifest'], '--outcome', 'green', '--passed', '104', '--failed', '0']);

    expect($result['code'])->toBe(0);
    $suite = manifest_read($fixture['manifest'])['suite'];
    expect($result['json'])->toBe(['action' => 'recorded', 'suite' => $suite]);
    expect($suite)->toMatchArray(['tree' => pipeline_tree_key($fixture['repo']), 'outcome' => 'green', 'passed' => 104, 'failed' => 0]);
    expect($suite['at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
    expect(array_diff_key(manifest_read($fixture['manifest']), ['suite' => true]))->toBe($before);
});

it('keeps the suite a step recorded in the record that follows, and the next brief accepts both', function () {
    $fixture = record_fixture('implement', 'run');
    dispatch_cli(['suite', $fixture['manifest'], '--outcome', 'red', '--passed', '100', '--failed', '4']);
    $suite = manifest_read($fixture['manifest'])['suite'];
    file_put_contents($fixture['stepDiff'], '');

    expect(record_cli($fixture, 'implement', 'run', ['--status', 'continued'])['json'])->toMatchArray(['action' => 'recorded', 'replaced' => []]);
    expect(manifest_read($fixture['manifest'])['suite'])->toBe($suite);
    expect(boundary_brief($fixture, 'review-pr', 'review', 'implement:run', ['--status', 'continued', '--ui', 'false'])['stdout'])->toContain('`review-pr` leg, `review` step');
});

it('refuses a green suite with failures, the snapshot\'s path, and a tree key it cannot compute', function () {
    $fixture = record_fixture('implement', 'run');
    $bytes = file_get_contents($fixture['manifest']);

    $green = dispatch_cli(['suite', $fixture['manifest'], '--outcome', 'green', '--passed', '100', '--failed', '4']);
    expect($green['code'])->toBe(1);
    expect($green['json'])->toBe(['action' => 'refused', 'reason' => 'a green suite has no failures: --failed is 4']);

    expect(dispatch_cli(['suite', $fixture['before'], '--outcome', 'green', '--passed', '104', '--failed', '0'])['json']['reason'])
        ->toBe("{$fixture['before']} is the dispatcher's snapshot; the manifest is {$fixture['manifest']}");
    expect(file_get_contents($fixture['manifest']))->toBe($bytes);

    $noRepo = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'implement', 'status' => 'pending']]);
    $result = dispatch_cli(['suite', $noRepo['manifest'], '--outcome', 'green', '--passed', '104', '--failed', '0']);
    expect($result['code'])->toBe(1);
    expect($result['json']['reason'])->toStartWith('cannot compute the tree key, so this run is not reusable: run the suite again next time (');
    expect(manifest_read($noRepo['manifest']))->not->toHaveKey('suite');
});

it('refuses a suite it cannot parse as a usage error', function (array $arguments) {
    $result = dispatch_cli(['suite', ...$arguments]);

    expect($result['code'])->toBe(1);
    expect($result['stdout'])->toBe('');
})->with([
    'no manifest' => [[]],
    'no counts' => [['/tmp/m.json', '--outcome', 'green']],
    'an outcome that is neither' => [['/tmp/m.json', '--outcome', 'yellow', '--passed', '1', '--failed', '0']],
    'a count that is no number' => [['/tmp/m.json', '--outcome', 'green', '--passed', 'all', '--failed', '0']],
    'a flag given twice' => [['/tmp/m.json', '--outcome', 'green', '--outcome', 'red', '--passed', '1', '--failed', '0']],
]);
```

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter RecordCliTest`
Expected: FAIL on the first three new tests (`suite` is an unknown command: exit 1, `json` null); the
*usage error* dataset passes already.

- [ ] **Step 3: Add the command**

In `skills/pipeline/checks/dispatch_cli.php`, below `dispatch_cli_record_command()`:

```php
/**
 * The write a step makes before it returns (`../references/engine.md` §Suite reuse): only the `suite` key,
 * into the manifest as it is, with the tree key computed here. A key that cannot be computed is a machinery
 * failure: nothing is written, so that run is never reused.
 */
function dispatch_cli_suite(string $manifestPath, string $outcome, int $passed, int $failed): array
{
    $problem = dispatch_cli_write_problem($manifestPath)
        ?? ($outcome === 'green' && $failed > 0 ? "a green suite has no failures: --failed is {$failed}" : null);
    if ($problem !== null) {
        return dispatch_cli_refuse($problem);
    }
    $manifest = (array) manifest_read($manifestPath);
    try {
        $tree = pipeline_tree_key(rtrim((string) $manifest['worktree'], '/'));
    } catch (RuntimeException $exception) {
        return dispatch_cli_refuse("cannot compute the tree key, so this run is not reusable: run the suite again next time ({$exception->getMessage()})");
    }
    $suite = ['tree' => $tree, 'outcome' => $outcome, 'passed' => $passed, 'failed' => $failed, 'at' => gmdate('Y-m-d\TH:i:s\Z')];

    return dispatch_cli_write($manifestPath, [...$manifest, 'suite' => $suite]) ?? ['action' => 'recorded', 'suite' => $suite];
}

/** `suite <manifest> --outcome green|red --passed <n> --failed <n>`, each flag once; null is a usage error. */
function dispatch_cli_suite_command(array $arguments): ?array
{
    $manifestPath = array_shift($arguments);
    $options = [];
    while ($arguments !== []) {
        $name = (string) array_shift($arguments);
        $value = array_shift($arguments);
        if (! in_array($name, ['--outcome', '--passed', '--failed'], true) || $value === null || isset($options[$name])) {
            return null;
        }
        $options[$name] = (string) $value;
    }
    $valid = $manifestPath !== null
        && count($options) === 3
        && in_array($options['--outcome'], ['green', 'red'], true)
        && ctype_digit($options['--passed'])
        && ctype_digit($options['--failed']);

    return $valid ? dispatch_cli_suite((string) $manifestPath, $options['--outcome'], (int) $options['--passed'], (int) $options['--failed']) : null;
}
```

In the final `match`, after the `'record'` arm:

```php
    'suite' => dispatch_cli_suite_command(array_slice($argv, 2)),
```

In the usage string, after the `record …` alternative added in Task 3, insert
` | suite <manifest> --outcome green|red --passed <n> --failed <n>`.

In the header comment, after the `record` line added in Task 3:

```
 *                 php dispatch_cli.php suite <manifest> --outcome green|red --passed <n> --failed <n>
```

and in the sentence amended in Task 3 write `\`record\` and \`suite\` exit 1 on a refusal`.

- [ ] **Step 4: Run the tests to see them pass**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter RecordCliTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/dispatch_cli.php skills/pipeline/checks/tests/RecordCliTest.php
git commit -m "feat(pipeline): dispatch_cli suite records a full run with the tree key it computes (#52)"
```

---

### Task 5: The brief prints the commands

**Files:**
- Modify: `skills/pipeline/checks/brief.php` (`pipeline_leg_overrides()`, `pipeline_brief()`,
  `pipeline_brief_pointers()`, `pipeline_brief_overrides()`, `pipeline_plan_gap_lines()`,
  `pipeline_brief_return()`; two new helpers)
- Test: `skills/pipeline/checks/tests/BriefTest.php`, `skills/pipeline/checks/tests/LockStepTest.php:39-40`,
  `skills/pipeline/checks/tests/DispatchCliTest.php:381`

**Interfaces:**
- Consumes: `pipeline_record_table()`, `ActionDisposition`, `IssueLinkOutcome` (Task 2); `manifest_files()`
  (Task 1).
- Produces:
  - `pipeline_cli(string $command, string $manifestPath): string` — `php <checks dir>/dispatch_cli.php <command> <manifest>`
  - `pipeline_record_commands(string $leg, string $step, string $manifestPath): list<string>`
  - `pipeline_leg_overrides(string $mode, string $manifestPath): array` — a second, required argument
  - `pipeline_brief_overrides(array $manifest, string $manifestPath, string $leg, string $step, ?array $scope = null, ?array $catchUp = null): string`
  - `pipeline_brief_return(string $leg, string $step, string $mode, string $manifestPath): string`
  - `pipeline_brief()` keeps its signature.

- [ ] **Step 1: Write the failing tests**

In `skills/pipeline/checks/tests/BriefTest.php`:

**Add** at the end of the file:

```php
/** @return list<array{0: string, 1: string, 2: string}> every step of both modes as `[mode, leg, step]` */
function brief_steps(): array
{
    $steps = [];
    foreach (['autoflow', 'interactive'] as $mode) {
        foreach (pipeline_legs() as $leg) {
            foreach (pipeline_steps($leg, $mode) as $step) {
                $steps[] = [$mode, $leg, $step];
            }
        }
    }

    return $steps;
}

it('ends every brief of both modes on the literal record command, one line per status the step may return', function () {
    $path = '/tmp/wt/.claude/pipeline/feature-x.json';
    $command = 'php ' . realpath(__DIR__ . '/..') . "/dispatch_cli.php record {$path}";

    foreach (brief_steps() as [$mode, $leg, $step]) {
        $return = explode("## Return\n\n", pipeline_brief(brief_manifest($leg, ['mode' => $mode]), $leg, $path, $step))[1];

        expect($return)
            ->toContain("- `{$command} {$leg} {$step} --status continued")
            ->toContain('- `… --status halted --reason "<why>"`')
            ->toContain('`feature-x.before.json` beside it is the dispatcher\'s snapshot')
            ->toContain('never find it by a glob')
            ->not->toContain('Never move `cursor.leg`');
        expect(substr_count($return, "\n- `"))->toBe(count(LegStatus::allowedFor($leg, $step)));
        foreach (LegStatus::allowedFor($leg, $step) as $status) {
            expect($return)->toContain("--status {$status->value}");
        }
    }
});

it('prints the return of a handoff step as its commands, and tells an autoflow step what to return', function () {
    $command = 'php ' . realpath(__DIR__ . '/..') . '/dispatch_cli.php record /tmp/m.json handoff run';

    expect(pipeline_brief_return('handoff', 'run', 'autoflow', '/tmp/m.json'))->toBe(
        "## Return\n\n"
        . "Your last act is one `record` command; only a read-only command your instructions name (`size`, `ui`, the proof page's `open`) comes after it. It is the only way you write the manifest: do not edit the file, and never find it by a glob (`m.before.json` beside it is the dispatcher's snapshot).\n\n"
        . "- `{$command} --status continued --pr <number>`\n"
        . "- `… --status plan-insufficient --reason \"<what the plan lacks>\"`\n"
        . "- `… --status halted --reason \"<why>\"`\n\n"
        . 'Write a `--reason` without double quotes. '
        . 'It prints `{"action":"recorded",…}`, or `{"action":"refused","reason":…}` with exit 1 and the manifest untouched: fix what it names and run it again. '
        . 'A `record` run again in the same step replaces the earlier one, and its `replaced` then names what that one wrote (`last_sha`, `cursor.status`): that is expected. '
        . 'Return the `status` it printed as your structured `{status, reason}`. When it refuses a `halted`, return `halted` with its reason all the same.'
    );
    expect(pipeline_brief_return('handoff', 'run', 'interactive', '/tmp/m.json'))
        ->toEndWith('that is expected. Take the `status` it printed and reply with one line naming it.');
});

it('prints each step\'s flags from the record table: the two files by their path, an optional flag in brackets', function () {
    $commands = fn (string $leg, string $step) => pipeline_record_commands($leg, $step, '/tmp/m.json');

    expect($commands('review-plan', 'review')[0])->toEndWith('review-plan review --status continued --review-file /tmp/m.review.md');
    expect($commands('review-pr', 'resolve'))->toHaveCount(3);
    expect($commands('review-pr', 'resolve')[1])->toBe('… --status looped-back --actions-file /tmp/m.actions.json [--issue-link <issue>=<closes|stays-open|dropped-but-closes>]…');
    expect($commands('design', 'spec')[0])->toEndWith('design spec --status continued --spec <path> [--plan <path>]');
    expect($commands('implement', 'run')[0])->toEndWith('implement run --status continued');
    expect($commands('verify-ui', 'run')[1])->toBe('… --status looped-back [--proof <path>]');
});

it('says what each step passes to record, and describes no JSON', function () {
    $path = '/tmp/wt/.claude/pipeline/feature-x.json';
    $suite = 'php ' . realpath(__DIR__ . '/..') . "/dispatch_cli.php suite {$path} --outcome <green|red> --passed <n> --failed <n>";
    $brief = fn (string $mode, string $leg, string $step) => pipeline_brief(brief_manifest($leg, ['mode' => $mode]), $leg, $path, $step);

    foreach (brief_steps() as [$mode, $leg, $step]) {
        expect($brief($mode, $leg, $step))
            ->not->toContain('ledger entry with `gate`')
            ->not->toContain('Set `artifacts')
            ->not->toContain('set `artifacts.proof`')
            ->not->toContain('`cycle`')
            ->not->toContain('reviewed_sha')
            ->not->toContain('Complete the open entry');
    }
    expect($brief('autoflow', 'review-plan', 'review'))
        ->toContain('- Write its review verbatim to `/tmp/wt/.claude/pipeline/feature-x.review.md`; `record` appends it as the open `plan-approval` entry.')
        ->toContain('- Act on nothing. Read-only on the checkout: that file is the only one you write.');
    expect($brief('interactive', 'review-pr', 'review'))
        ->toContain('`record` appends it as the open `pr-review` entry, with the commit you reviewed.');
    expect($brief('autoflow', 'review-plan', 'resolve'))
        ->toContain('- Write what you did with each point to `/tmp/wt/.claude/pipeline/feature-x.actions.json` as a JSON list of `{claim, disposition, note}`, `disposition` one of `integrated`, `recorded`, `open-question`, and `[]` when you acted on nothing; `record` completes the open entry from it.');
    expect($brief('autoflow', 'review-pr', 'resolve'))
        ->toContain("- Run the suite unless engine.md §Suite reuse finds this tree green, and record the run: `{$suite}`.")
        ->toContain('- Reconcile the closing links (engine.md §Closing links): each related issue\'s outcome goes to `record` as an `--issue-link`.');
    expect($brief('autoflow', 'handoff', 'run'))
        ->toContain('- Invoke `handoff pr`. The PR opens draft and references the issue without a closing keyword (engine.md §Closing links). Its number goes to `record` as `--pr`.');
    expect($brief('autoflow', 'verify-ui', 'run'))
        ->toContain('- Write the proof page (engine.md §The proof store) and post the text-only record comment; the path `write` printed goes to `record` as `--proof`.')
        ->toContain('- Return `continued`, or `looped-back` when the check fails.');
});
```

**Change** these existing tests (the line numbers are today's):

1. `completes the open entry before the finish step's last action` (line 25): rename it to
   `'has the finish step write its actions before its last action'` and replace its `expect` with

```php
    expect(strpos($brief, 'm.actions.json'))->toBeLessThan(strpos($brief, 'After `record`, the last action is `proof_cli.php open`'));
```

2. `has overrides for every leg and step…` (line 36): `pipeline_leg_overrides($mode)` becomes
   `pipeline_leg_overrides($mode, '/tmp/m.json')`.

3. `carries the pointers, the settled decisions and the suite line` (line 62): replace
   `->toContain('Never move \`cursor.leg\`')` with
   `->toContain('dispatch_cli.php record /tmp/wt/.claude/pipeline/feature-x.json implement run --status continued`')`.

4. `splits autoflow's design into a spec step…` (lines 93, 102, 106): replace the three override lines it
   asserts with the new ones:

```php
        ->toContain('- Commit the spec; on the Bounded path, commit the plan as well, a second commit, at `docs/superpowers/plans/<date>-<slug>.md` beside the spec `docs/superpowers/specs/<date>-<slug>-design.md` (`pipeline_plan_path()`): a Bounded design has no plan step, and a grown design\'s plan step extends the plan it finds there. After your last commit the paths go to `record`: `--spec`, and `--plan` on the Bounded path only; it sets `artifacts.spec` and removes or sets `artifacts.plan` as the spec\'s size calls for. A halt before that leaves the manifest calling for this step again.')
```

```php
        ->toContain('- Commit the plan; its path goes to `record` as `--plan`.')
```

```php
        ->toContain('- Commit the spec, then the plan: two commits; their paths go to `record` as `--spec` and `--plan`.');
```

5. `gives the reviewer crafted context…` (line 187): replace
   `->toContain('this review\'s \`cycle\`: \`2\`')` with `->not->toContain('`cycle`')`.

6. `leaves the PR draft at the autoflow finish step…` (line 264): replace its last `expect` with

```php
    expect(strpos($brief, 'm.actions.json'))->toBeLessThan(strpos($brief, 'After `record`, the last action is `proof_cli.php open`'));
```

7. `has the interactive finish step run the CI gate before gh pr ready` (line 271): the asserted line
   becomes `'Run the CI gate (engine.md §The CI gate) and \`gh pr ready\` when it answers \`ready\`; show any other answer to the human. After \`record\`, the last action is \`proof_cli.php open\`'`.

8. `tells every autoflow step where to work…` (line 295): replace
   `->toContain('then return \`{status, reason}\` as your structured result')` with
   `->toContain('Return the \`status\` it printed as your structured \`{status, reason}\`.')`.

9. `tells a resolve step to loop back on a plan gap, and a review step to leave no review behind`
   (lines 308, 311): the two asserted lines become
   `'A plan gap or a Bounded escalation found while resolving is a loop-back: return \`looped-back\` and name it in your actions'`
   and `'When you return \`plan-insufficient\`, write no review file: \`record\` adds no review entry.'`;
   on the `implement` brief replace `->not->toContain('append no review entry')` with
   `->not->toContain('write no review file')`.

10. `runs format once per implement step…` (line 320): the asserted line ends
    `…(engine.md §Mechanical checks, §Suite reuse). After every full run, record it: \`php <checks>/dispatch_cli.php suite /tmp/m.json --outcome <green|red> --passed <n> --failed <n>\`.`
    with `<checks>` written as `' . realpath(__DIR__ . '/..') . '`.

11. `makes handoff on a run on a base check that the PR opened into it` (line 337): in the asserted line
    `before setting \`artifacts.pr\`` becomes `before you record the PR`.

12. `tells a later step to report a plan gap only once it has found one, on either size` (lines 344–346):
    the two `toContain` lines become

```php
            ->toContain('On a Bounded spec (its header says `**Design size:** Bounded`): run the escalation check first (engine.md §Design size), and only on escalation return `plan-insufficient` with `--reason` naming why the design must grow.')
            ->toContain('On an Architectural spec: only when the plan falls short of what this step needs (files or behaviour it does not name), return `plan-insufficient` with `--reason` naming what the plan lacks. The size alone is no gap: an Architectural plan needs no approval beyond `review-plan`\'s.')
            ->not->toContain('append a `plan-approval` entry');
```

13. Delete `has the review-pr review step record the commit it reviewed, and the review-plan one not`
    (lines 355–360): *says what each step passes to record* replaces it.

In `skills/pipeline/checks/tests/LockStepTest.php` (lines 39–40), both calls gain the path:
`pipeline_leg_overrides('autoflow', '/tmp/m.json')` and `pipeline_leg_overrides('interactive', '/tmp/m.json')`.

In `skills/pipeline/checks/tests/DispatchCliTest.php` (line 381), `->toContain('as your structured result')`
becomes `->toContain('as your structured \`{status, reason}\`')`.

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter 'BriefTest|LockStepTest'`
Expected: FAIL: `pipeline_brief_return()` takes three arguments, `pipeline_record_commands()` is undefined,
and the changed assertions do not match today's lines.

- [ ] **Step 3: Rewrite the brief**

In `skills/pipeline/checks/brief.php`:

**a. `pipeline_leg_overrides()`.** The signature becomes
`function pipeline_leg_overrides(string $mode, string $manifestPath): array`. Delete the `$completeEntry`
line and add, after `$autoflow = …`:

```php
    $files = manifest_files($manifestPath);
    $suite = '`' . pipeline_cli('suite', $manifestPath) . ' --outcome <green|red> --passed <n> --failed <n>`';
    $dispositions = implode(', ', array_map(fn (ActionDisposition $disposition) => "`{$disposition->value}`", ActionDisposition::cases()));
    $writeActions = "Write what you did with each point to `{$files['actions']}` as a JSON list of `{claim, disposition, note}`, `disposition` one of {$dispositions}, and `[]` when you acted on nothing; `record` completes the open entry from it.";
    $writeReview = fn (string $gate, string $stamped = '') => "Write its review verbatim to `{$files['review']}`; `record` appends it as the open `{$gate}` entry{$stamped}.";
    $readOnly = 'Act on nothing. Read-only on the checkout: that file is the only one you write.';
```

Then change these lines of the returned array, and no others:

| Step | The line today | The line after |
|---|---|---|
| `design:run` | `'Commit the spec, then the plan: two commits. Set \`artifacts.spec\` and \`artifacts.plan\`.'` | `'Commit the spec, then the plan: two commits; their paths go to \`record\` as \`--spec\` and \`--plan\`.'` |
| `design:spec` | the last sentence pair, from `Then, after your last commit and in one manifest write, …` to `… calling for this step again.` | `After your last commit the paths go to \`record\`: \`--spec\`, and \`--plan\` on the Bounded path only; it sets \`artifacts.spec\` and removes or sets \`artifacts.plan\` as the spec\'s size calls for. A halt before that leaves the manifest calling for this step again.` |
| `design:plan` | `'Commit the plan. Set \`artifacts.plan\`.'` | `'Commit the plan; its path goes to \`record\` as \`--plan\`.'` |
| `review-plan:review` | `'Append its review verbatim as a new \`plan-approval\` ledger entry with …'` | `$writeReview('plan-approval'),` |
| `review-plan:review`, `review-pr:review` | `'Act on nothing. Read-only on the checkout; the manifest is the only file you write.'` | `$readOnly,` |
| `review-plan:resolve` | `$completeEntry,` | `$writeActions,` |
| `handoff:run` | `… (engine.md §Closing links). Set \`artifacts.pr\`.'` | `… (engine.md §Closing links). Its number goes to \`record\` as \`--pr\`.'` |
| `implement:run` | `… (engine.md §Mechanical checks, §Suite reuse). Record \`suite\` after every full run.'` | `… (engine.md §Mechanical checks, §Suite reuse). After every full run, record it: ' . $suite . '.',` |
| `verify-ui:run` | `'Write the proof page (engine.md §The proof store), set \`artifacts.proof\` to the path \`write\` printed, and post the text-only record comment.'` | `'Write the proof page (engine.md §The proof store) and post the text-only record comment; the path \`write\` printed goes to \`record\` as \`--proof\`.'` |
| `verify-ui:run` | `'Append the thin \`verify-ui\` entry with outcome \`continued\`, or \`looped-back\` when the check fails.'` | `'Return \`continued\`, or \`looped-back\` when the check fails.'` |
| `review-pr:review` | `'Append its review verbatim as a new \`pr-review\` ledger entry with … and no \`outcome\`.'` | `$writeReview('pr-review', ', with the commit you reviewed'),` |
| `review-pr:resolve` | `'Run the suite unless engine.md §Suite reuse finds this tree green; record \`suite\`.'` | `'Run the suite unless engine.md §Suite reuse finds this tree green, and record the run: ' . $suite . '.',` |
| `review-pr:resolve` | `'Reconcile the closing links (engine.md §Closing links) and write \`issue_links\` on the entry.'` | `'Reconcile the closing links (engine.md §Closing links): each related issue\'s outcome goes to \`record\` as an \`--issue-link\`.'` |
| `review-pr:resolve` | `$completeEntry,` | `$writeActions,` |
| `review-pr:resolve` | `. ' The last action is \`proof_cli.php open\` on \`artifacts.proof\` (engine.md §The proof store).'` | `. ' After \`record\`, the last action is \`proof_cli.php open\` on \`artifacts.proof\` (engine.md §The proof store).'` |

**b. `pipeline_brief()`.** The last two entries of its `implode` become:

```php
        pipeline_brief_overrides($manifest, $manifestPath, $leg, $step, $scope, $catchUp),
        pipeline_brief_return($leg, $step, (string) $manifest['mode'], $manifestPath),
```

**c. `pipeline_brief_pointers()`.** Delete the `if ($step === 'review') { … }` block that adds the
*this review's `cycle`* line. The `resolve` block stays.

**d. `pipeline_brief_overrides()`.** The signature becomes
`function pipeline_brief_overrides(array $manifest, string $manifestPath, string $leg, string $step, ?array $scope = null, ?array $catchUp = null): string`,
its second spread becomes `...pipeline_leg_overrides((string) $manifest['mode'], $manifestPath)["{$leg}:{$step}"],`,
and in the `handoff` base line `before setting \`artifacts.pr\`` becomes `before you record the PR`.

**e. `pipeline_plan_gap_lines()`.** Replace its body with:

```php
    if ($step === 'resolve') {
        return ['A plan gap or a Bounded escalation found while resolving is a loop-back: return `looped-back` and name it in your actions; the leg the run goes back to handles it.'];
    }

    return [
        'On a Bounded spec (its header says `**Design size:** Bounded`): run the escalation check first (engine.md §Design size), and only on escalation return `plan-insufficient` with `--reason` naming why the design must grow.',
        'On an Architectural spec: only when the plan falls short of what this step needs (files or behaviour it does not name), return `plan-insufficient` with `--reason` naming what the plan lacks. The size alone is no gap: an Architectural plan needs no approval beyond `review-plan`\'s.',
        ...($step === 'review' ? ['When you return `plan-insufficient`, write no review file: `record` adds no review entry.'] : []),
    ];
```

**f. `pipeline_brief_return()`.** Replace the whole function with these three:

```php
/** A `dispatch_cli.php` command as a step copies it: the checks directory and the manifest by their full paths (#122). */
function pipeline_cli(string $command, string $manifestPath): string
{
    return 'php ' . __DIR__ . "/dispatch_cli.php {$command} {$manifestPath}";
}

/**
 * The literal `record` command for each status the step may return, from `pipeline_record_table()`: the
 * first in full, the others by what differs. A required flag is printed bare, an optional one in brackets.
 *
 * @return list<string>
 */
function pipeline_record_commands(string $leg, string $step, string $manifestPath): array
{
    $files = manifest_files($manifestPath);
    $shown = [
        'spec' => '--spec <path>',
        'plan' => '--plan <path>',
        'pr' => '--pr <number>',
        'proof' => '--proof <path>',
        'review-file' => "--review-file {$files['review']}",
        'actions-file' => "--actions-file {$files['actions']}",
        'issue-link' => '--issue-link <issue>=<' . implode('|', array_column(IssueLinkOutcome::cases(), 'value')) . '>',
    ];
    $reason = ['plan-insufficient' => '--reason "<what the plan lacks>"', 'halted' => '--reason "<why>"'];
    $lines = [];
    foreach (pipeline_record_table()["{$leg}:{$step}"] as $status => $row) {
        $flags = [
            ...array_map(fn (string $flag) => $flag === 'reason' ? $reason[$status] : $shown[$flag], $row['required']),
            ...array_map(fn (string $flag) => "[{$shown[$flag]}]" . ($flag === 'issue-link' ? '…' : ''), $row['optional']),
        ];
        $lines[] = ($lines === [] ? pipeline_cli('record', $manifestPath) . " {$leg} {$step}" : '…')
            . rtrim(" --status {$status} " . implode(' ', $flags));
    }

    return $lines;
}

/** The return contract is the commands (`../references/manifest.md` §What a leg writes): `record` writes, the step only passes what it made. */
function pipeline_brief_return(string $leg, string $step, string $mode, string $manifestPath): string
{
    $snapshot = basename(manifest_files($manifestPath)['before']);
    $commands = implode("\n", array_map(fn (string $command) => "- `{$command}`", pipeline_record_commands($leg, $step, $manifestPath)));
    $reply = $mode === 'autoflow'
        ? 'Return the `status` it printed as your structured `{status, reason}`. When it refuses a `halted`, return `halted` with its reason all the same.'
        : 'Take the `status` it printed and reply with one line naming it.';

    return "## Return\n\n"
        . "Your last act is one `record` command; only a read-only command your instructions name (`size`, `ui`, the proof page's `open`) comes after it. It is the only way you write the manifest: do not edit the file, and never find it by a glob (`{$snapshot}` beside it is the dispatcher's snapshot).\n\n"
        . "{$commands}\n\n"
        . 'Write a `--reason` without double quotes. '
        . 'It prints `{"action":"recorded",…}`, or `{"action":"refused","reason":…}` with exit 1 and the manifest untouched: fix what it names and run it again. '
        . 'A `record` run again in the same step replaces the earlier one, and its `replaced` then names what that one wrote (`last_sha`, `cursor.status`): that is expected. '
        . $reply;
}
```

- [ ] **Step 4: Run the tests to see them pass**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, the whole suite. `AutoflowScriptTest` stays green: its stub steps write by hand, and its
prompts come from the script, which did not change.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/brief.php skills/pipeline/checks/tests/BriefTest.php skills/pipeline/checks/tests/LockStepTest.php skills/pipeline/checks/tests/DispatchCliTest.php
git commit -m "feat(pipeline): a brief ends on the literal record command, by the manifest's full path (#52, #122)"
```

---

### Task 6: One replay that writes only through `record`

**Files:**
- Modify: `skills/pipeline/checks/tests/autoflow_replay.mjs`
- Test: `skills/pipeline/checks/tests/AutoflowScriptTest.php` (append)

**Interfaces:**
- Consumes: the `record` command (Task 3); `rereview_repo()`, `rereview_commit()`, `dispatch_fixture()`,
  `dispatch_cli()`, `autoflow_replay()`.
- Produces: in a scripted return, `record: [<flag>, <value>, …]` makes the stub step run `record`; `review`
  (text) and `actions` (a list) are written to the run's two files first and passed as `--review-file` and
  `--actions-file`. A refusal comes back as `{status: 'halted', reason}`.

- [ ] **Step 1: Write the failing test**

Append to `skills/pipeline/checks/tests/AutoflowScriptTest.php`:

```php
it('walks from design:plan to done with every step writing through record (the replay smoke pass)', function () {
    $dir = rereview_repo();
    rereview_commit($dir, ['spec.md' => "# x — design\n\n**Design size:** Architectural\n", 'plan.md' => "# x Implementation Plan\n"]);
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'worktree' => $dir, 'cursor' => ['leg' => 'design', 'status' => 'pending'], 'artifacts' => ['spec' => 'spec.md', 'pr' => null, 'issue' => null]]);
    $start = dispatch_cli(['launch', $fixture['manifest'], $fixture['diff']])['json'];
    expect($start)->toMatchArray(['action' => 'start', 'startLeg' => 'design', 'startStep' => 'plan']);
    $recorded = fn (array $flags = [], array $extra = []) => ['status' => 'continued', 'record' => $flags, ...$extra];

    $replay = autoflow_replay($start, [
        'design:plan' => [$recorded(['--plan', 'plan.md'], ['size' => 'Architectural'])],
        'review-plan:review' => [$recorded(extra: ['review' => 'Step 2 names no test.'])],
        'review-plan:resolve' => [$recorded(extra: ['actions' => [['claim' => 'step 2 names no test', 'disposition' => 'integrated', 'note' => 'added it']]])],
        'handoff:run' => [$recorded(['--pr', '7'])],
        'implement:run' => [$recorded(extra: ['diff' => '', 'ui' => false])],
        'review-pr:review' => [$recorded(extra: ['review' => 'Nothing to change.'])],
        'review-pr:resolve' => [$recorded(['--issue-link', '52=closes'], ['actions' => []])],
    ], steps: true);

    expect($replay['labels'])->toBe(['design:plan', 'review-plan:review', 'review-plan:resolve', 'handoff:run', 'implement:run', 'review-pr:review', 'review-pr:resolve']);
    expect($replay['result'])->toBe(['action' => 'done']);
    expect(dispatch_cli(['finish', $fixture['manifest'], '{"action":"done"}'])['json'])->toBe(['action' => 'done']);

    $manifest = manifest_read($fixture['manifest']);
    $head = pipeline_git($dir, ['rev-parse', 'HEAD']);
    expect($manifest['artifacts'])->toMatchArray(['spec' => 'spec.md', 'plan' => 'plan.md', 'pr' => 7]);
    expect($manifest['last_sha'])->toBe($head);
    expect(array_column($manifest['gate_ledger'], 'outcome'))->toBe(['continued', 'continued']);
    expect($manifest['gate_ledger'][1])->toMatchArray(['gate' => 'pr-review', 'reviewed_sha' => $head, 'issue_links' => [['issue' => 52, 'outcome' => 'closes']]]);
});

it('returns a halt with record\'s reason when a stub step\'s record is refused', function () {
    $dir = rereview_repo();
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'worktree' => $dir, 'cursor' => ['leg' => 'handoff', 'status' => 'pending']]);
    $start = dispatch_cli(['launch', $fixture['manifest'], $fixture['diff']])['json'];

    $replay = autoflow_replay($start, ['handoff:run' => [['status' => 'continued', 'record' => []]]], steps: true);

    expect($replay['result'])->toBe(['action' => 'halt', 'leg' => 'handoff', 'reason' => 'handoff run with --status continued needs --pr']);
});
```

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter AutoflowScriptTest`
Expected: FAIL on the two new tests: `play()` merges no `write`, the manifest stays unchanged, and the next
`brief` halts with `the design plan step returned without writing the manifest`.

- [ ] **Step 3: Give the stub step its `record` form**

In `skills/pipeline/checks/tests/autoflow_replay.mjs`:

1. The import becomes `import { execFileSync, execSync } from 'node:child_process'`.

2. In the header comment, after *"writes its `diff` to the run's diff file, and returns the rest."* add:
   `// A return with \`record\` (a list of flags) writes through the real command instead: its \`review\` and`
   `// \`actions\` go to the run's two files and are passed by path, and a refusal comes back as a halt.`

3. Add below `play()`:

```js
function record(label, { record: flags, review, actions, diff, ...returns }) {
  const path = input.args.manifest
  const stem = path.replace(/\.json$/, '')
  const files = []
  if (review !== undefined) {
    writeFileSync(`${stem}.review.md`, review)
    files.push('--review-file', `${stem}.review.md`)
  }
  if (actions !== undefined) {
    writeFileSync(`${stem}.actions.json`, JSON.stringify(actions))
    files.push('--actions-file', `${stem}.actions.json`)
  }
  if (diff !== undefined) writeFileSync(`${stem}.diff`, diff)
  const [leg, step] = label.split(':')
  const reason = returns.reason ? ['--reason', returns.reason] : []
  try {
    execFileSync('php', [`${input.args.checks}/dispatch_cli.php`, 'record', path, leg, step, '--status', returns.status, ...reason, ...flags, ...files], { encoding: 'utf8' })
  } catch (error) {
    return { ...returns, status: 'halted', reason: JSON.parse(error.stdout).reason }
  }
  return returns
}
```

4. In `agent()`, the last line becomes:

```js
  if (!input.steps) return returns
  return returns.record ? record(opts.label, returns) : play(returns)
```

- [ ] **Step 4: Run the tests to see them pass**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter AutoflowScriptTest`
Expected: PASS, the two new tests and every existing replay.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/tests/autoflow_replay.mjs skills/pipeline/checks/tests/AutoflowScriptTest.php
git commit -m "test(pipeline): one replay walks a run to done with every step writing through record (#52)"
```

---

### Task 7: The documents say *record*

**Files:**
- Modify: `skills/pipeline/references/manifest.md` (§What a leg writes)
- Modify: `skills/pipeline/references/engine.md` (§The loop, §`autoflow`, §Interactive, §Design size,
  §What a leg brief consists of, §Suite reuse)
- Modify: `skills/pipeline/references/gates.md` (§How a run calls Phase A)
- Modify: `skills/pipeline/SKILL.md:17`
- Test: `skills/pipeline/checks/tests/LockStepTest.php` (unchanged; it must stay green)

**Interfaces:**
- Consumes: the commands of Tasks 3 and 4, the brief of Task 5. Produces nothing code relies on.

Keep every line within the file's wrap (110 columns in `engine.md` and `manifest.md`); rewrap the
paragraph you touch.

- [ ] **Step 1: `manifest.md` §What a leg writes**

Replace the section's first paragraph up to and including *"and never writes a brief."* with:

```markdown
A leg writes only its results: `artifacts`, `last_sha`, `suite`, its `gate_ledger` entry, and
`cursor.status` — plus `cursor.reason` when it halts. It never moves `cursor.leg` and never writes a
brief. **It writes them with one command**, `dispatch_cli.php record <manifest> <leg> <step> --status
<status> [flags]` (`../checks/record.php`): the result is the dispatch snapshot plus what the step
passes, with `last_sha`, `cycle`, `at`, `reviewed_sha`, `annotations` and `outcome` stamped by code.
`record` runs the check below over that result and writes only when it holds; a refusal exits 1 with the
manifest untouched and names what is wrong. A review goes in as a file (`<manifest stem>.review.md`), a
resolve step's actions as `<manifest stem>.actions.json`. `suite` is the one key a step writes earlier,
with `dispatch_cli.php suite` (`engine.md` §Suite reuse). The brief's `## Return` prints the step's
commands, and `pipeline_record_table()` holds what each step passes.

The checks judge the manifest, however it was written: one repaired by hand that holds is accepted.
```

The rest of the section (from *"In `interactive`, after every return `returned` compares…"*) stays, as a
new paragraph. The status table and the lock-step sentence stay word for word.

- [ ] **Step 2: `engine.md`**

Eight passages, each found by the phrase quoted:

1. §The loop, the `<manifest stem>` paragraph. *"the diff, the brief (`.brief.md`) and the dispatch
   snapshot (`.before.json`) sit next to the manifest"* becomes *"the diff, the brief (`.brief.md`), the
   dispatch snapshot (`.before.json`), a review step's review (`.review.md`) and a resolve step's actions
   (`.actions.json`) sit next to the manifest (`manifest_files()`)"*.
2. §The loop, the control rule's first bullet. *"It writes its results and a status into the manifest
   (`manifest.md` §What a leg writes) and replies with one line."* becomes *"It records its results and a
   status with `dispatch_cli.php record` (`manifest.md` §What a leg writes), which refuses a result the
   next check would halt on, and replies with one line."*
3. §`autoflow`, the **A step** bullet. *"The step writes its results into the manifest (`manifest.md`
   §What a leg writes) and returns `{status, reason}`;"* becomes *"The step records its results with
   `dispatch_cli.php record` (`manifest.md` §What a leg writes), then returns the status it recorded as
   `{status, reason}`;"*. In the same bullet, after the sentence on `size` and `ui` (*"…copied from
   `dispatch_cli.php size <manifest>` (`DesignSize::fromSpec()` over the committed spec)."*) add: *"Both
   are read-only and run after `record`: `size` reads the spec `record` just set."*
4. §Interactive. *"completes the entry with the human's `actions` and `outcome`, and runs `returned`."*
   becomes *"records it with `dispatch_cli.php record` (the human's `actions` in
   `<manifest stem>.actions.json`), as the inline `design` step records its spec and plan, and runs
   `returned`."*
5. §Design size:
   - *Escalation*: *"On escalation the step appends the `design-size` entry and returns
     `plan-insufficient`;"* becomes *"On escalation the step returns `plan-insufficient` with
     `record --reason`, which appends the `design-size` entry;"*.
   - *On escalation, grow the design*, item 1: *"Append a ledger entry: `{gate: 'design-size', …}`."*
     becomes *"`record --status plan-insufficient --reason <why>` appends the ledger entry:
     `{gate: 'design-size', leg: <current leg>, at, reason, outcome: 'escalated'}`."*
   - *A plan gap*, item 1: *"The step appends `{gate: 'plan-approval', …}` and returns
     `plan-insufficient`."* becomes *"The step returns `plan-insufficient` with `record --reason`, which
     appends `{gate: 'plan-approval', leg: <its leg>, cycle, at, reason, outcome: 'looped-back'}`."* The
     next sentence, *"A return without that entry halts."*, stays.
6. §What a leg brief consists of. The bullet *"**the return contract**: which keys the step may write and
   which statuses it may return;"* becomes *"**the return contract**: the literal `record` command for each
   status the step may return, with the manifest's full path, printed from `pipeline_record_table()`
   (`../checks/record.php`); the snapshot beside the manifest is named so that no step mistakes it for
   the manifest;"*.
7. §What a leg brief consists of, the paragraph after that list. *"(`pipeline_leg_overrides('autoflow')`)"*
   becomes *"(`pipeline_leg_overrides('autoflow', <manifest path>)`)"*: the function takes the manifest
   path since Task 5.
8. §Suite reuse, the **Record** bullet, becomes:

```markdown
- **Record.** After every full run,
  `php "$CHECKS/dispatch_cli.php" suite "$MANIFEST" --outcome <green|red> --passed <n> --failed <n>`
  writes `suite: {tree, outcome, passed, failed, at}` to the manifest, computing `tree` itself. It refuses
  `green` with failures and a key it cannot compute. Only `green` is ever reused.
```

- [ ] **Step 3: `gates.md` and `SKILL.md`**

In `gates.md` §How a run calls Phase A, inside the `autoflow` code block, after the two lines on `brief`
(ending `# → the brief, or {"action":"halt","reason":…}`), add:

```
php "$CHECKS/dispatch_cli.php" record <manifest> <leg> <step> --status <status> [flags]
#   each step's one manifest write, in both modes; the brief's ## Return prints it
# → {"action":"recorded",…} | {"action":"refused","reason":…} (exit 1, the manifest untouched)
php "$CHECKS/dispatch_cli.php" suite <manifest> --outcome green|red --passed <n> --failed <n>
#   after a full suite run → {"action":"recorded","suite":{…}} | {"action":"refused",…}
```

In `SKILL.md` (line 17), *"`dispatch_cli.php brief`, do their leg, write the manifest and return a
status;"* becomes *"`dispatch_cli.php brief`, do their leg, record their result with
`dispatch_cli.php record` and return a status;"*.

- [ ] **Step 4: Run the whole suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, every test; `LockStepTest`'s *keeps manifest.md in lock-step with the statuses and the
leg-writable keys* and *keeps every engine.md section a brief names* among them.

Then: `grep -rn "dispatch_cli_files\|Set \`artifacts\|write the manifest and return" skills/pipeline`.
Expected: no output.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/references/manifest.md skills/pipeline/references/engine.md skills/pipeline/references/gates.md skills/pipeline/SKILL.md
git commit -m "docs(pipeline): a step records its result; manifest.md, engine.md, gates.md and SKILL.md name record and suite (#52)"
```

---

## Self-review notes

- **Spec coverage.** `record` and what it stamps: Tasks 2–3. The step table and its refusals: Task 2.
  The two files and their freshness: Task 3. `suite`: Task 4. Naming the manifest (#122): the full paths
  and the snapshot sentence in Task 5, the snapshot-path refusal in Tasks 3–4. The brief and its override
  table: Task 5. Where the code goes, the exists-at-`HEAD` function included: Tasks 1–3. A fact that
  cannot be read: Tasks 2–3 (*records a halt outside a git repository*). The documents: Task 7. The
  replay: Task 6. `LockStepTest` stays green: Tasks 5 and 7.
- **Not in this plan, as the spec's *Out of scope* says:** `handoff` adopting an existing draft PR, the
  script reading the status from the manifest, a command for the *"is a suite run needed"* read, the smoke
  run's stub prompt.
