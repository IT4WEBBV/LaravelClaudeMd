# A CI gate on the PR's head commit before `gh pr ready` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task by task. Steps use checkbox (`- [ ]`) syntax for tracking. In an `autoflow` implement step, execute inline, task by task, with no subagents.

**Goal:** `gh pr ready` waits for CI on the PR's head commit through one tested, read-only command the invoking session polls; a red head gets one automatic fix round through `launch --from review-pr --decision`, and a red after it halts with the PR draft; `implement` no longer waits on CI in `autoflow`.

**Architecture:**
- New `skills/pipeline/checks/ci.php`: pure `pipeline_ci_check()`, `pipeline_ci_verdict()`, `pipeline_ci_answer()`, `pipeline_ci_rounds()` and three constants.
- `skills/pipeline/checks/dispatch_cli.php`: a `ci <manifest> [--poll <n>]` command (one `gh pr view`, never a write) and `launch … [--decision <text>]…`, with launch's arguments parsed by `dispatch_cli_launch_args()`.
- `skills/pipeline/checks/brief.php`: the `implement` and `review-pr:resolve` lines per mode, and two fix-round lines added on `review-pr` while a CI decision is recorded.
- Docs: engine.md §The CI gate (new) and five sections that point at it; pipeline `SKILL.md` step 5; `orchestrate` `SKILL.md` and `references/commands.md`; `manifest.md`'s `decisions` row.

**Tech Stack:** PHP 8.4 on the host, Pest 4, Markdown skill references, `gh` (faked in tests).

**Spec:** `docs/superpowers/specs/2026-09-28-pipeline-ci-gate-before-ready-design.md`

## Global Constraints

- Suite, from the worktree root on the host: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`. `vendor/` is missing in a fresh worktree: run `composer install --no-interaction --quiet` first.
- `ci` never writes the manifest; only `launch --decision` writes a CI decision. Every `ci` test that reads a manifest asserts it byte-identical afterwards.
- Every `ci` answer is one JSON line whose `action` is `wait`, `ready`, `fix` or `halt`; a `halt` carries `leg: review-pr` (except the input halts: no manifest, invalid, retired mode, no PR, which are `pipeline_halt()`'s shape) so it is `finish`'s input unchanged.
- The decision prefix is exactly `CI red on the PR's head commit ` (`PIPELINE_CI_RED`); the brief lines and the docs quote it as `CI red on the PR's head commit`.
- `pipeline-autoflow.js`, `dispatch.php`, `finish`, `run_audit.php` and the merge watch do not change. `work-on` (another repository) is not edited.
- The engine.md heading is exactly `## The CI gate — CI on the PR's head commit, before \`gh pr ready\``, so `LockStepTest` reads it as `The CI gate`. Task 4 lands it before Task 5 adds the brief lines that name it.
- This repo has no `.changelog/` and no `CHANGELOG.md`: no changelog entry.

## Review Focus

1. **Idempotent polling.** `ci` is read-only; the round is counted from `decisions`, which only `launch --decision` writes. A `ci` that wrote would turn a re-polled first red into "red again".
2. **Array order in the tests.** `toBe()` on arrays is `===`: key order matters. `pipeline_ci_answer()` builds `action`, then (on a halt) `leg`, `reason`, then `verdict`, `sha`, `failing`, `decision` in that order; the tests pin it.
3. **`none` with and without workflows.** Without `.github/workflows/*.y*ml` in the worktree (this repository) `none` is `ready` at read 1; with them only from read 3.
4. **`launch`'s parsing moves into `dispatch_cli_launch_args()`.** `--from ''` must still reach `dispatch_cli_launch()` (the existing *no leg* dataset); a missing positional or a flag without its value becomes a usage error (exit 1).
5. **The autoflow finish line must not contain `gh pr ready`** (the existing test asserts it), and the interactive one must.

---

## File Structure

- Create `skills/pipeline/checks/ci.php`, `skills/pipeline/checks/tests/CiTest.php`.
- Modify `skills/pipeline/checks/tests/Pest.php` (load `ci.php`), `skills/pipeline/checks/dispatch_cli.php`, `skills/pipeline/checks/tests/DispatchCliTest.php`, `skills/pipeline/checks/brief.php`, `skills/pipeline/checks/tests/BriefTest.php`.
- Modify `skills/pipeline/references/engine.md`, `skills/pipeline/references/manifest.md`, `skills/pipeline/SKILL.md`, `skills/orchestrate/SKILL.md`, `skills/orchestrate/references/commands.md`.

---

### Task 1: `ci.php` — the verdict and the answer

**Files:**
- Create: `skills/pipeline/checks/ci.php`
- Modify: `skills/pipeline/checks/tests/Pest.php`
- Test: `skills/pipeline/checks/tests/CiTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `pipeline_ci_check(array $item): array{name: string, link: string, state: string}`, `pipeline_ci_verdict(array $rollup): array{verdict: string, failing: list<array{name: string, link: string}>, pending: list<string>}`, `pipeline_ci_answer(array $manifest, ?array $view, bool $workflows, int $poll): array`, `pipeline_ci_rounds(array $manifest): int`, constants `PIPELINE_CI_POLLS`, `PIPELINE_CI_NONE_POLLS`, `PIPELINE_CI_RED`.

- [ ] **Step 1: Load the file in the tests.** In `Pest.php`, add `'ci.php'` to the list after `'kickoff.php'`:

```php
foreach (['triggers.php', 'pipeline.php', 'manifest.php', 'checks.php', 'board.php', 'proof.php', 'proof_render.php', 'design_size.php', 'suite.php', 'dispatch.php', 'brief.php', 'run_cost.php', 'kickoff.php', 'ci.php'] as $f) {
```

- [ ] **Step 2: Write the failing tests.** Create `CiTest.php`:

```php
<?php

function ci_run(string $name, string $status, string $conclusion = '', string $workflow = 'CI'): array
{
    return ['__typename' => 'CheckRun', 'name' => $name, 'workflowName' => $workflow, 'status' => $status, 'conclusion' => $conclusion, 'detailsUrl' => "https://github.com/acme/app/actions/runs/11/job/{$name}"];
}

function ci_status(string $context, string $state): array
{
    return ['__typename' => 'StatusContext', 'context' => $context, 'state' => $state, 'targetUrl' => "https://ci.example/{$context}"];
}

function ci_manifest(array $decisions = []): array
{
    return ['branch' => 'feature/x', 'worktree' => '/tmp/wt', 'mode' => 'autoflow', 'cursor' => ['leg' => 'review-pr', 'status' => 'done'], 'artifacts' => ['pr' => 7], 'decisions' => $decisions];
}

function ci_view(array $rollup): array
{
    return ['headRefOid' => 'abc123', 'statusCheckRollup' => $rollup];
}

it('classifies a check run by its status and conclusion, and a commit status by its state', function (array $item, string $state) {
    expect(pipeline_ci_check($item)['state'])->toBe($state);
})->with([
    'queued' => [ci_run('ci', 'QUEUED'), 'pending'],
    'in progress' => [ci_run('ci', 'IN_PROGRESS'), 'pending'],
    'success' => [ci_run('ci', 'COMPLETED', 'SUCCESS'), 'green'],
    'neutral' => [ci_run('ci', 'COMPLETED', 'NEUTRAL'), 'green'],
    'skipped' => [ci_run('ci', 'COMPLETED', 'SKIPPED'), 'green'],
    'failure' => [ci_run('ci', 'COMPLETED', 'FAILURE'), 'red'],
    'timed out' => [ci_run('ci', 'COMPLETED', 'TIMED_OUT'), 'red'],
    'cancelled' => [ci_run('ci', 'COMPLETED', 'CANCELLED'), 'red'],
    'a status that passed' => [ci_status('ext', 'SUCCESS'), 'green'],
    'a pending status' => [ci_status('ext', 'PENDING'), 'pending'],
    'an expected status' => [ci_status('ext', 'EXPECTED'), 'pending'],
    'a status in error' => [ci_status('ext', 'ERROR'), 'red'],
    'a failed status' => [ci_status('ext', 'FAILURE'), 'red'],
]);

it('names a check run by its workflow and a status by its context, with their links', function () {
    expect(pipeline_ci_check(ci_run('ci', 'COMPLETED', 'FAILURE')))->toBe(['name' => 'CI / ci', 'link' => 'https://github.com/acme/app/actions/runs/11/job/ci', 'state' => 'red']);
    expect(pipeline_ci_check(ci_run('ci', 'COMPLETED', 'FAILURE', ''))['name'])->toBe('ci');
    expect(pipeline_ci_check(ci_status('ext', 'ERROR')))->toBe(['name' => 'ext', 'link' => 'https://ci.example/ext', 'state' => 'red']);
});

it('reads no checks as none, a red check over a pending one as red, and all finished and passing as green', function () {
    expect(pipeline_ci_verdict([]))->toBe(['verdict' => 'none', 'failing' => [], 'pending' => []]);
    expect(pipeline_ci_verdict([ci_run('ci', 'IN_PROGRESS'), ci_run('validate', 'COMPLETED', 'FAILURE', 'Validate')]))
        ->toBe(['verdict' => 'red', 'failing' => [['name' => 'Validate / validate', 'link' => 'https://github.com/acme/app/actions/runs/11/job/validate']], 'pending' => ['CI / ci']]);
    expect(pipeline_ci_verdict([ci_run('ci', 'IN_PROGRESS'), ci_run('validate', 'COMPLETED', 'SUCCESS')])['verdict'])->toBe('pending');
    expect(pipeline_ci_verdict([ci_run('ci', 'COMPLETED', 'SUCCESS'), ci_run('notes', 'COMPLETED', 'SKIPPED')])['verdict'])->toBe('green');
});

it('answers ready on green, and on no checks at once without workflows or from the third read with them', function () {
    expect(pipeline_ci_answer(ci_manifest(), ci_view([ci_run('ci', 'COMPLETED', 'SUCCESS')]), true, 1))->toBe(['action' => 'ready', 'verdict' => 'green', 'sha' => 'abc123']);
    expect(pipeline_ci_answer(ci_manifest(), ci_view([]), false, 1))->toBe(['action' => 'ready', 'verdict' => 'none', 'sha' => 'abc123']);
    expect(pipeline_ci_answer(ci_manifest(), ci_view([]), true, 2))->toBe(['action' => 'wait', 'verdict' => 'none', 'sha' => 'abc123']);
    expect(pipeline_ci_answer(ci_manifest(), ci_view([]), true, 3))->toBe(['action' => 'ready', 'verdict' => 'none', 'sha' => 'abc123']);
});

it('waits on pending checks and on a PR gh cannot read, and halts at the hour', function () {
    $pending = ci_view([ci_run('ci', 'IN_PROGRESS')]);

    expect(pipeline_ci_answer(ci_manifest(), $pending, true, 119))->toBe(['action' => 'wait', 'verdict' => 'pending', 'sha' => 'abc123']);
    expect(pipeline_ci_answer(ci_manifest(), $pending, true, 120))->toBe(['action' => 'halt', 'leg' => 'review-pr', 'reason' => 'CI on abc123 has not finished after an hour: CI / ci', 'verdict' => 'pending', 'sha' => 'abc123']);
    expect(pipeline_ci_answer(ci_manifest(), null, true, 119))->toBe(['action' => 'wait', 'verdict' => 'unreadable']);
    expect(pipeline_ci_answer(ci_manifest(), null, true, 120))->toBe(['action' => 'halt', 'leg' => 'review-pr', 'reason' => 'the checks of PR #7 could not be read for an hour', 'verdict' => 'unreadable']);
});

it('answers one fix round on red, with the failures verbatim as its decision, and halts on red after it', function () {
    $red = ci_view([ci_run('ci', 'COMPLETED', 'FAILURE'), ci_run('validate', 'COMPLETED', 'TIMED_OUT', 'Validate'), ci_run('notes', 'COMPLETED', 'SUCCESS')]);
    $failing = [
        ['name' => 'CI / ci', 'link' => 'https://github.com/acme/app/actions/runs/11/job/ci'],
        ['name' => 'Validate / validate', 'link' => 'https://github.com/acme/app/actions/runs/11/job/validate'],
    ];
    $failures = 'CI / ci failed (https://github.com/acme/app/actions/runs/11/job/ci); Validate / validate failed (https://github.com/acme/app/actions/runs/11/job/validate)';
    $decision = "CI red on the PR's head commit abc123: {$failures}";

    expect(pipeline_ci_answer(ci_manifest(['Keep the guard']), $red, true, 1))
        ->toBe(['action' => 'fix', 'verdict' => 'red', 'sha' => 'abc123', 'failing' => $failing, 'decision' => $decision]);
    expect(pipeline_ci_answer(ci_manifest(['Keep the guard', $decision]), $red, true, 1))
        ->toBe(['action' => 'halt', 'leg' => 'review-pr', 'reason' => "CI red again after the fix round, on abc123: {$failures}", 'verdict' => 'red', 'sha' => 'abc123', 'failing' => $failing]);
});

it('counts the recorded CI failures in decisions, and nothing else', function () {
    expect(pipeline_ci_rounds(ci_manifest(['Keep the guard'])))->toBe(0);
    expect(pipeline_ci_rounds(ci_manifest(['Keep the guard', "CI red on the PR's head commit abc123: CI / ci failed (x)"])))->toBe(1);
    expect(pipeline_ci_rounds(['branch' => 'feature/x']))->toBe(0);
});
```

- [ ] **Step 3: Run them to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter=CiTest`
Expected: FAIL, `Call to undefined function pipeline_ci_check()` (the file does not exist yet, so Pest.php skips it).

- [ ] **Step 4: Write `ci.php`**

```php
<?php

/**
 * The CI gate on the PR's head commit, before `gh pr ready` (`../references/engine.md` §The CI gate).
 * Pure: `dispatch_cli.php ci` reads the PR with gh and hands the view in.
 */

/** Reads before a pending or unreadable CI halts: an hour, 30 s apart. */
const PIPELINE_CI_POLLS = 120;

/** With workflows in the worktree, no check at all counts as no CI only from this read on: GitHub registers a push's checks seconds after it. */
const PIPELINE_CI_NONE_POLLS = 3;

/** How the gate's failure record starts in `decisions`; one such decision is the run's fix round spent. */
const PIPELINE_CI_RED = "CI red on the PR's head commit ";

/**
 * One `statusCheckRollup` item: a check run is pending until it completes, then green on success,
 * neutral or skipped; a commit status is green on success and pending while pending or expected.
 * Anything else is red.
 *
 * @return array{name: string, link: string, state: string}
 */
function pipeline_ci_check(array $item): array
{
    if ($item['__typename'] === 'CheckRun') {
        return [
            'name' => $item['workflowName'] === '' ? $item['name'] : "{$item['workflowName']} / {$item['name']}",
            'link' => $item['detailsUrl'],
            'state' => match (true) {
                $item['status'] !== 'COMPLETED' => 'pending',
                in_array($item['conclusion'], ['SUCCESS', 'NEUTRAL', 'SKIPPED'], true) => 'green',
                default => 'red',
            },
        ];
    }

    return [
        'name' => $item['context'],
        'link' => $item['targetUrl'],
        'state' => match ($item['state']) {
            'SUCCESS' => 'green',
            'PENDING', 'EXPECTED' => 'pending',
            default => 'red',
        },
    ];
}

/**
 * The head commit's checks as one verdict: `none`, `red` (a red check wins over a pending one),
 * `pending` or `green`.
 *
 * @return array{verdict: string, failing: list<array{name: string, link: string}>, pending: list<string>}
 */
function pipeline_ci_verdict(array $rollup): array
{
    $checks = array_map(pipeline_ci_check(...), $rollup);
    $failing = array_values(array_filter($checks, fn (array $check) => $check['state'] === 'red'));
    $pending = array_values(array_filter($checks, fn (array $check) => $check['state'] === 'pending'));

    return [
        'verdict' => match (true) {
            $checks === [] => 'none',
            $failing !== [] => 'red',
            $pending !== [] => 'pending',
            default => 'green',
        },
        'failing' => array_map(fn (array $check) => ['name' => $check['name'], 'link' => $check['link']], $failing),
        'pending' => array_column($pending, 'name'),
    ];
}

/**
 * What the session does next: `wait` and read again, `ready` (`gh pr ready`), `fix` (the decision into
 * `decisions` through `launch --from review-pr --decision`), or `halt` (`finish`'s input). `$view` is
 * `gh pr view <pr> --json headRefOid,statusCheckRollup`, null when gh could not read it; `$workflows`
 * whether the worktree has GitHub Actions workflows; `$poll` this read's number, from 1.
 */
function pipeline_ci_answer(array $manifest, ?array $view, bool $workflows, int $poll): array
{
    $last = $poll >= PIPELINE_CI_POLLS;
    if ($view === null) {
        return $last
            ? pipeline_ci_halt("the checks of PR #{$manifest['artifacts']['pr']} could not be read for an hour", ['verdict' => 'unreadable'])
            : ['action' => 'wait', 'verdict' => 'unreadable'];
    }
    $ci = pipeline_ci_verdict($view['statusCheckRollup']);
    $read = ['verdict' => $ci['verdict'], 'sha' => $view['headRefOid']];

    return match ($ci['verdict']) {
        'green' => ['action' => 'ready', ...$read],
        'none' => ['action' => $workflows && $poll < PIPELINE_CI_NONE_POLLS ? 'wait' : 'ready', ...$read],
        'pending' => $last
            ? pipeline_ci_halt("CI on {$read['sha']} has not finished after an hour: " . implode(', ', $ci['pending']), $read)
            : ['action' => 'wait', ...$read],
        'red' => pipeline_ci_red($manifest, [...$read, 'failing' => $ci['failing']]),
    };
}

/** The first red of a run is its fix round; a red after it halts. */
function pipeline_ci_red(array $manifest, array $read): array
{
    $failures = implode('; ', array_map(fn (array $check) => "{$check['name']} failed ({$check['link']})", $read['failing']));

    return pipeline_ci_rounds($manifest) === 0
        ? ['action' => 'fix', ...$read, 'decision' => PIPELINE_CI_RED . "{$read['sha']}: {$failures}"]
        : pipeline_ci_halt("CI red again after the fix round, on {$read['sha']}: {$failures}", $read);
}

/** A gate halt names `review-pr`, so `finish` records it there as it stands. */
function pipeline_ci_halt(string $reason, array $read): array
{
    return ['action' => 'halt', 'leg' => 'review-pr', 'reason' => $reason, ...$read];
}

/** The fix rounds this run has had: the decisions the gate's failure record starts. */
function pipeline_ci_rounds(array $manifest): int
{
    return count(array_filter($manifest['decisions'] ?? [], fn (string $decision) => str_starts_with($decision, PIPELINE_CI_RED)));
}
```

- [ ] **Step 5: Run the tests and the suite**

Run: `php -l skills/pipeline/checks/ci.php`
Expected: `No syntax errors detected in skills/pipeline/checks/ci.php`

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter=CiTest`
Expected: PASS, 19 tests (13 dataset rows of the classification test, and 6 more).

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, 0 failed.

- [ ] **Step 6: Commit**

```bash
git add skills/pipeline/checks/ci.php skills/pipeline/checks/tests/CiTest.php skills/pipeline/checks/tests/Pest.php
git commit -m "feat(pipeline): ci.php reads the PR head's checks as one verdict and answers wait, ready, fix or halt (#85)"
```

---

### Task 2: `dispatch_cli.php ci`

**Files:**
- Modify: `skills/pipeline/checks/dispatch_cli.php`
- Test: `skills/pipeline/checks/tests/DispatchCliTest.php`

**Interfaces:**
- Consumes: Task 1's `pipeline_ci_answer()`; existing `dispatch_cli_invalid()`, `pipeline_retired_mode()`, `pipeline_halt()`, `manifest_read()`, and `dispatch_cli_pr_view(string $worktree, int|string $pr): ?array`, which gains `string $fields = 'state,isDraft'`.
- Produces: `dispatch_cli_ci(string $manifestPath, int $poll): array`, `dispatch_cli_ci_command(array $arguments): ?array`; the CLI command `ci <manifest> [--poll <n>]`.

- [ ] **Step 1: Write the failing tests.** In `DispatchCliTest.php`, add before `function kickoff_issue(`:

```php
/** A finished autoflow run on PR 7, and a fake gh first on PATH that answers `pr view` from pr.json (none: gh fails). */
function ci_fixture(?array $view, bool $workflows = true, array $decisions = []): array
{
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'review-pr', 'status' => 'done'], 'artifacts' => ['spec' => null, 'plan' => null, 'pr' => 7, 'issue' => null], 'decisions' => $decisions]);
    mkdir($fixture['dir'] . '/bin');
    file_put_contents($fixture['dir'] . '/bin/gh', <<<'SH'
#!/bin/sh
echo "$*" >> "$GH_FAKE/calls"
[ -f "$GH_FAKE/pr.json" ] || { echo 'HTTP 502: Bad Gateway' >&2; exit 1; }
cat "$GH_FAKE/pr.json"
SH);
    chmod($fixture['dir'] . '/bin/gh', 0755);
    if ($view !== null) {
        file_put_contents($fixture['dir'] . '/pr.json', json_encode($view));
    }
    if ($workflows) {
        mkdir($fixture['dir'] . '/.github/workflows', 0777, true);
        file_put_contents($fixture['dir'] . '/.github/workflows/ci.yml', "on: pull_request\n");
    }

    return [...$fixture, 'env' => ['PATH' => $fixture['dir'] . '/bin:' . getenv('PATH'), 'GH_FAKE' => $fixture['dir']]];
}

function ci_gate(array $fixture, array $arguments = []): array
{
    return dispatch_cli(['ci', $fixture['manifest'], ...$arguments], $fixture['env']);
}

function ci_head(string $conclusion): array
{
    return ['headRefOid' => 'abc123', 'statusCheckRollup' => [['__typename' => 'CheckRun', 'name' => 'ci', 'workflowName' => 'CI', 'status' => 'COMPLETED', 'conclusion' => $conclusion, 'detailsUrl' => 'https://github.com/acme/app/actions/runs/11/job/12']]];
}

it('gates the PR\'s head commit with one gh read, and never writes the manifest', function () {
    $fixture = ci_fixture(ci_head('SUCCESS'));
    $before = file_get_contents($fixture['manifest']);

    $result = ci_gate($fixture);

    expect($result['code'])->toBe(0);
    expect($result['json'])->toBe(['action' => 'ready', 'verdict' => 'green', 'sha' => 'abc123']);
    expect(file($fixture['dir'] . '/calls', FILE_IGNORE_NEW_LINES))->toBe(['pr view 7 --json headRefOid,statusCheckRollup']);
    expect(file_get_contents($fixture['manifest']))->toBe($before);
});

it('answers a red head with a fix round the first time, and with a halt finish records once the round is spent', function () {
    $decision = "CI red on the PR's head commit abc123: CI / ci failed (https://github.com/acme/app/actions/runs/11/job/12)";
    $first = ci_fixture(ci_head('FAILURE'));
    $before = file_get_contents($first['manifest']);

    expect(ci_gate($first)['json'])->toMatchArray(['action' => 'fix', 'verdict' => 'red', 'decision' => $decision]);
    expect(file_get_contents($first['manifest']))->toBe($before);

    $spent = ci_fixture(ci_head('FAILURE'), true, [$decision]);
    $halt = ci_gate($spent)['stdout'];
    $reason = 'CI red again after the fix round, on abc123: CI / ci failed (https://github.com/acme/app/actions/runs/11/job/12)';
    expect(json_decode($halt, true))->toMatchArray(['action' => 'halt', 'leg' => 'review-pr', 'reason' => $reason]);

    dispatch_cli(['finish', $spent['manifest'], trim($halt)]);
    expect(manifest_read($spent['manifest'])['cursor'])->toBe(['leg' => 'review-pr', 'status' => 'halted', 'reason' => $reason]);
});

it('waits on no checks while the worktree has workflows, and answers ready at once without them', function () {
    $none = ['headRefOid' => 'abc123', 'statusCheckRollup' => []];

    expect(ci_gate(ci_fixture($none))['json'])->toBe(['action' => 'wait', 'verdict' => 'none', 'sha' => 'abc123']);
    expect(ci_gate(ci_fixture($none), ['--poll', '3'])['json'])->toBe(['action' => 'ready', 'verdict' => 'none', 'sha' => 'abc123']);
    expect(ci_gate(ci_fixture($none, false))['json'])->toBe(['action' => 'ready', 'verdict' => 'none', 'sha' => 'abc123']);
});

it('waits while gh cannot read the PR, and halts at the last read', function () {
    expect(ci_gate(ci_fixture(null))['json'])->toBe(['action' => 'wait', 'verdict' => 'unreadable']);
    expect(ci_gate(ci_fixture(null), ['--poll', '120'])['json'])->toMatchArray(['action' => 'halt', 'leg' => 'review-pr']);
});

it('halts the gate on a run without a PR or a readable manifest, and leaves the manifest alone', function () {
    $fixture = dispatch_fixture(['mode' => 'autoflow']);
    $before = file_get_contents($fixture['manifest']);

    expect(dispatch_cli(['ci', $fixture['manifest']])['json'])->toBe(['action' => 'halt', 'reason' => 'the CI gate needs a PR: artifacts.pr is not set']);
    expect(file_get_contents($fixture['manifest']))->toBe($before);
    expect(dispatch_cli(['ci', '/nonexistent/m.json'])['json'])->toBe(['action' => 'halt', 'reason' => 'no readable manifest at /nonexistent/m.json']);
});

it('refuses a ci it cannot parse', function (array $arguments) {
    expect(ci_gate(ci_fixture(ci_head('SUCCESS')), $arguments)['code'])->toBe(1);
})->with([
    'a poll without its value' => [['--poll']],
    'a poll of zero' => [['--poll', '0']],
    'a poll that is no number' => [['--poll', 'soon']],
    'an unknown flag' => [['--wait', '3']],
]);
```

and add a row to the dataset of *refuses a manifest that still says auto in every command*:

```php
    'ci' => [['ci', '<manifest>']],
```

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='gates the PR|red head|no checks while|gh cannot read|without a PR or a readable|refuses a ci|still says auto'`
Expected: FAIL: `ci` is not a command yet, so every call exits 1 with no JSON (`code` 1, `json` null); the parse-refusal rows pass already.

- [ ] **Step 3: Implement.** In `dispatch_cli.php`:

1. Add `require_once __DIR__ . '/ci.php';` after `require_once __DIR__ . '/kickoff.php';`.
2. Change `dispatch_cli_pr_view()` to take the fields:

```php
/** `gh pr view` from the worktree, or null when gh cannot read the PR. */
function dispatch_cli_pr_view(string $worktree, int|string $pr, string $fields = 'state,isDraft'): ?array
{
    $process = proc_open(['gh', 'pr', 'view', (string) $pr, '--json', $fields], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $worktree);
```

   (the rest of the function is unchanged).
3. Add after `dispatch_cli_ui()`:

```php
/**
 * The CI gate's read (`../references/engine.md` §The CI gate): the PR's head commit and its checks in one
 * gh call, and what the session does next. It never writes the manifest, so polling it changes nothing.
 */
function dispatch_cli_ci(string $manifestPath, int $poll): array
{
    $manifest = manifest_read($manifestPath);
    if ($manifest === null) {
        return pipeline_halt("no readable manifest at {$manifestPath}");
    }
    $problem = dispatch_cli_invalid($manifest) ?? pipeline_retired_mode((string) $manifest['mode']);
    if ($problem !== null) {
        return pipeline_halt($problem);
    }
    $pr = $manifest['artifacts']['pr'] ?? null;
    if ($pr === null) {
        return pipeline_halt('the CI gate needs a PR: artifacts.pr is not set');
    }
    $worktree = rtrim($manifest['worktree'], '/');

    return pipeline_ci_answer(
        $manifest,
        dispatch_cli_pr_view($worktree, $pr, 'headRefOid,statusCheckRollup'),
        glob("{$worktree}/.github/workflows/*.y*ml") !== [],
        $poll,
    );
}

/** `ci <manifest> [--poll <n>]`, n counted from 1; null is a usage error. */
function dispatch_cli_ci_command(array $arguments): ?array
{
    $poll = match (count($arguments)) {
        1 => '1',
        3 => $arguments[1] === '--poll' ? (string) $arguments[2] : '',
        default => '',
    };

    return ctype_digit($poll) && (int) $poll > 0 ? dispatch_cli_ci((string) $arguments[0], (int) $poll) : null;
}
```

4. In the command `match`, add `'ci' => dispatch_cli_ci_command(array_slice($argv, 2)),` after the `'ui'` row.
5. In the header docblock add ` *                 php dispatch_cli.php ci <manifest> [--poll <n>]` after the `ui` line, and change `(a \`kickoff\` or a \`brief\` it cannot parse included)` to `(a \`kickoff\`, a \`launch\`, a \`brief\` or a \`ci\` it cannot parse included)`. In the usage string append ` | ci <manifest> [--poll <n>]` after `ui <diff-file>`.

- [ ] **Step 4: Run the tests and the suite**

Run: `php -l skills/pipeline/checks/dispatch_cli.php`
Expected: `No syntax errors detected in skills/pipeline/checks/dispatch_cli.php`

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='gates the PR|red head|no checks while|gh cannot read|without a PR or a readable|refuses a ci|still says auto'`
Expected: PASS.

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, 0 failed.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/dispatch_cli.php skills/pipeline/checks/tests/DispatchCliTest.php
git commit -m "feat(pipeline): dispatch_cli.php ci: one read-only gh read of the PR's head commit and the gate's answer (#85)"
```

---

### Task 3: `launch --decision`

**Files:**
- Modify: `skills/pipeline/checks/dispatch_cli.php`
- Test: `skills/pipeline/checks/tests/DispatchCliTest.php`

**Interfaces:**
- Consumes: existing `dispatch_cli_launch(string $manifestPath, string $diffPath, ?string $from): array`, which gains `array $decisions = []`.
- Produces: `dispatch_cli_launch_args(array $arguments): ?array` (`[manifest, diff, from, decisions]`), `dispatch_cli_launch_command(array $arguments): ?array`.

- [ ] **Step 1: Write the failing tests.** In `DispatchCliTest.php`, add after the test *re-arms nothing on a manifest that is not an autoflow run's*:

```php
it('appends each --decision verbatim as it re-arms the run, and nothing when --from is refused', function () {
    $reviewed = ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => '2026-09-22T11:00:00Z', 'review' => 'r', 'outcome' => 'continued'];
    $passed = [...$reviewed, 'gate' => 'plan-approval', 'leg' => 'review-plan', 'at' => '2026-09-22T10:00:00Z'];
    $decision = "CI red on the PR's head commit abc123: CI / ci failed (https://github.com/acme/app/actions/runs/11/job/12)";
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'review-pr', 'status' => 'done'], 'decisions' => ['Keep the guard'], 'gate_ledger' => [$passed, $reviewed]]);

    expect(dispatch_cli(['launch', $fixture['manifest'], $fixture['diff'], '--from', 'review-pr', '--decision', $decision])['json'])
        ->toMatchArray(['action' => 'start', 'startLeg' => 'review-pr', 'startStep' => 'review']);
    expect(manifest_read($fixture['manifest']))->toMatchArray(['cursor' => ['leg' => 'review-pr', 'status' => 'pending'], 'decisions' => ['Keep the guard', $decision]]);

    $refused = dispatch_fixture(['mode' => 'autoflow', 'decisions' => ['Keep the guard']]);
    $before = file_get_contents($refused['manifest']);
    expect(dispatch_cli(['launch', $refused['manifest'], $refused['diff'], '--from', 'reveiw-pr', '--decision', $decision])['json']['action'])->toBe('halt');
    expect(file_get_contents($refused['manifest']))->toBe($before);
});

it('refuses a launch it cannot parse', function (array $arguments) {
    expect(dispatch_cli(['launch', ...$arguments])['code'])->toBe(1);
})->with([
    'no diff file' => [['/tmp/m.json']],
    'a decision without its text' => [['/tmp/m.json', '/tmp/d.diff', '--decision']],
    'a from without its leg' => [['/tmp/m.json', '/tmp/d.diff', '--from']],
]);
```

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='appends each --decision|refuses a launch it cannot parse'`
Expected: FAIL: `decisions` is still `['Keep the guard']` after the re-arm (today's `launch` ignores `--decision`), and the parse rows answer a halt with exit 0 instead of exit 1.

- [ ] **Step 3: Implement.** In `dispatch_cli.php`:

1. `dispatch_cli_launch()`'s signature becomes `function dispatch_cli_launch(string $manifestPath, string $diffPath, ?string $from, array $decisions = []): array`, and its docblock gains: `` `$decisions` are appended to `decisions` verbatim, in the same write as `--from`'s re-arm and after its checks. `` In its `--from` block, drop the `manifest_write($manifestPath, $manifest);` line after the cursor assignment, and add after that block (before `if (dispatch_cli_finished($manifest)) {`):

```php
    if ($decisions !== []) {
        $manifest = [...$manifest, 'decisions' => [...($manifest['decisions'] ?? []), ...$decisions]];
    }
    if ($from !== null || $decisions !== []) {
        manifest_write($manifestPath, $manifest);
    }
```

2. Add after `dispatch_cli_brief_command()`:

```php
/**
 * `launch <manifest> <diff-file> [--from <leg>] [--decision <text>]...`; null is a usage error. `--from`
 * is passed as given (an empty or unknown leg is `launch`'s halt, not a usage error).
 *
 * @return array{0: string, 1: string, 2: ?string, 3: list<string>}|null
 */
function dispatch_cli_launch_args(array $arguments): ?array
{
    $positional = [];
    $from = null;
    $decisions = [];
    while ($arguments !== []) {
        $argument = (string) array_shift($arguments);
        if (! in_array($argument, ['--from', '--decision'], true)) {
            $positional[] = $argument;

            continue;
        }
        $value = array_shift($arguments);
        if ($value === null) {
            return null;
        }
        if ($argument === '--from') {
            $from = (string) $value;
        } else {
            $decisions[] = (string) $value;
        }
    }

    return count($positional) === 2 ? [...$positional, $from, $decisions] : null;
}

function dispatch_cli_launch_command(array $arguments): ?array
{
    $parsed = dispatch_cli_launch_args($arguments);

    return $parsed === null ? null : dispatch_cli_launch(...$parsed);
}
```

3. Delete the line `$flag = array_search('--from', $argv, true);` and change the `'launch'` row of the command `match` to `'launch' => dispatch_cli_launch_command(array_slice($argv, 2)),`.
4. In the header docblock and the usage string, `launch <manifest> <diff-file> [--from <leg>]` becomes `launch <manifest> <diff-file> [--from <leg>] [--decision <text>]...`.

- [ ] **Step 4: Run the tests and the suite**

Run: `php -l skills/pipeline/checks/dispatch_cli.php`
Expected: `No syntax errors detected in skills/pipeline/checks/dispatch_cli.php`

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='appends each --decision|refuses a launch it cannot parse|refuses --from with a leg|re-arms it with --from|re-arms nothing'`
Expected: PASS (the existing `--from` tests included: `--from ''` still halts naming `''`).

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, 0 failed.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/dispatch_cli.php skills/pipeline/checks/tests/DispatchCliTest.php
git commit -m "feat(pipeline): launch --decision appends to decisions with the re-arm; launch's arguments parsed like kickoff's (#85)"
```

---

### Task 4: engine.md §The CI gate and the sections that point at it

**Files:**
- Modify: `skills/pipeline/references/engine.md`

**Interfaces:**
- Consumes: Tasks 1–3's commands and answers, as documented.
- Produces: the heading `## The CI gate — CI on the PR's head commit, before \`gh pr ready\``, which Task 5's brief lines name as `§The CI gate`.

- [ ] **Step 1: Add the section.** Insert immediately before the line `## Closing links — settled at \`review-pr\`, never assumed`, with one blank line on each side:

````markdown
## The CI gate — CI on the PR's head commit, before `gh pr ready`

`gh pr ready` waits for CI on the PR's head commit, whichever step pushed it. Before #85 the only CI wait
was `implement`'s, inherited from `work-on`'s leg 8: `verify-ui` and `review-pr`'s finish step push
commits no step watched, and a HeaderHarbor PR went ready while CI still ran on such a commit, which then
went red unnoticed. The same wait held `implement` for about 7 of its ~21 minutes in both viewiemedia
runs (#77), while `review-pr:review` reads the diff, not CI.

- **`implement` does not wait on CI in `autoflow`.** It adds the `ci` label before its first push (§Who
  takes the PR out of draft), pushes and returns; its brief overrides `work-on`'s CI watch.
  `review-pr:review` runs while CI runs. `interactive` keeps `work-on`'s watch.
- **One gate, in the session that runs `gh pr ready`:** the invoking session once `finish` prints `done`
  in `autoflow`, the finish step in `interactive`. `dispatch_cli.php ci <manifest> --poll <n>`
  (`../checks/ci.php`) reads the PR's head commit and its checks once
  (`gh pr view <pr> --json headRefOid,statusCheckRollup`), writes nothing, and prints one JSON line:

| Verdict on the head commit | Answer |
|---|---|
| `green`: every check finished `SUCCESS`, `NEUTRAL` or `SKIPPED` | `ready` |
| `none`: no check at all | `ready`; with `.github/workflows/*.yml` in the worktree only from the third read, since GitHub registers a push's checks seconds after it |
| `pending` | `wait`; `halt` at the 120th read (an hour at 30 s) |
| `red`: any other conclusion, or a status in `FAILURE` or `ERROR` | `fix` the first time in a run; `halt` once that round is spent |
| `unreadable`: `gh` failed | `wait`; `halt` at the 120th read |

A skipped check reads green, so the `ci` label has to be on before the push (§Who takes the PR out of
draft). The session polls the gate in one background Bash and waits for its completion notice:

```bash
poll=1; while answer=$(php "$CHECKS/dispatch_cli.php" ci <manifest> --poll $poll); echo "$answer" | grep -q '"action":"wait"'; do sleep 30; poll=$((poll + 1)); done; echo "$answer"
```

- **`ready`** → `gh pr ready <pr>`.
- **`fix`** → one automatic fix round (owner, #85). The answer's `decision`, `CI red on the PR's head
  commit <sha>: <check> failed (<link>)`, goes into `decisions` verbatim with the re-arm:
  `git -C <worktree> diff origin/<base>...HEAD > "<manifest stem>.diff"`, then
  `launch <manifest> "<manifest stem>.diff" --from review-pr --decision "<its decision>"` and a new
  `pipeline-autoflow` workflow. The review step reads the failing job's log and states the failure as a
  finding; the finish step fixes it, or shows it unrelated (the same failure on the base branch, or a
  flake whose failed jobs it reruns without waiting); then `finish`, and this gate again.
- **`halt`** → `finish <manifest> '<the answer>'`: the answer names `review-pr` and its reason, so
  `finish` records the halt there. The PR stays draft, and §Failure policy's duties after `handoff`
  follow. A red after the round halts: a decision that starts `CI red on the PR's head commit` is the
  round spent, once per run, so a resumed run halts on its next red too. An empty answer (a usage error)
  is a halt as well.
- **In `interactive`** the finish step runs the same loop, `gh pr ready` on `ready`, and shows any other
  answer to the human; there is no automatic round.
- **The merge watch stays on `state`** (§After the merge): once the PR is ready, CI on its head has
  settled.
````

- [ ] **Step 2: Point the other sections at it.** Make these replacements in engine.md, each old text exactly once:

1. §`autoflow`, the diagram line
   `                   … on its return: dispatch_cli.php finish → gh pr ready | halt duties → report`
   becomes
   `                   … on its return: dispatch_cli.php finish → dispatch_cli.php ci → gh pr ready | fix round | halt duties → report`
2. §`autoflow`, the bash block: `launch <manifest> "<manifest stem>.diff" [--from <leg>]` becomes `launch <manifest> "<manifest stem>.diff" [--from <leg>] [--decision "<verbatim>"]…`, and after the line `php "$CHECKS/dispatch_cli.php" finish <manifest> '<the workflow return, as JSON>'` add the line
   `php "$CHECKS/dispatch_cli.php" ci <manifest> --poll <n>                  # after done: the CI gate, polled (§The CI gate)`
3. §`autoflow`, the `launch` bullet: `a PR that needs new commits gets a new run with \`--from review-pr\`, without\n  editing a file.` becomes `a PR that needs new commits gets a new run with \`--from review-pr\`, without\n  editing a file; \`--decision <text>\`, repeatable, appends that text to \`decisions\` verbatim in the same\n  write, after \`--from\`'s checks (§The CI gate).` (keep the line wrap at ~105 columns as the paragraph does).
4. §`autoflow`, the `finish` bullet: `When \`finish\` prints \`done\` the invoking session then runs **\`gh pr ready <pr>\`** (§Who\n  takes the PR out of draft); on a halt after \`handoff\`, §Failure policy's duties.` becomes `When \`finish\` prints \`done\` the invoking session runs the CI gate and, on its \`ready\`,\n  **\`gh pr ready <pr>\`** (§The CI gate, §Who takes the PR out of draft); on a halt after \`handoff\`,\n  §Failure policy's duties.`
5. §Stations, the `implement` row: `**Leaves the PR draft** (below).` becomes `**Leaves the PR draft** (below); in \`autoflow\` it does not wait on CI (§The CI gate).` The `review-pr` row: `and the invoking session runs \`gh pr ready\` after \`finish\` (§Who takes the PR out of draft)` becomes `and the invoking session runs the CI gate and \`gh pr ready\` after \`finish\` (§The CI gate, §Who takes the PR out of draft)`.
6. §Who takes the PR out of draft, the `autoflow` paragraph: `after the workflow returns \`done\` and \`finish\` records it, the\ninvoking session runs \`gh pr ready <pr>\`.` becomes `after the workflow returns \`done\` and \`finish\` records it, the\ninvoking session runs the CI gate and then \`gh pr ready <pr>\` (§The CI gate).`
7. §Who takes the PR out of draft, the `ci` label paragraph: replace

```markdown
on, and until then `gh pr checks` reads the skipped CI check as green.
`work-on`'s leg 8 adds it before the push whose CI it watches; say it in the `implement` brief as
well: **"add the `ci` label (`gh pr edit <pr> --add-label ci`) before the push whose CI you watch."**
```

   with

```markdown
on, and until then `gh pr checks`, and the CI gate, read the skipped CI check as green.
`work-on`'s leg 8 adds it before the push whose CI it watches, and the `implement` brief says it as
well: in `interactive` **"add the `ci` label (`gh pr edit <pr> --add-label ci`) before the push whose CI
you watch"**; in `autoflow` before its first push, without waiting on CI (§The CI gate).
```

8. §What a leg brief consists of, the `autoflow` paragraph: `\`implement\` executes the plan inline, with no subagents; the finish step\nleaves the PR draft;` becomes `\`implement\` executes the plan inline, with no subagents, and does not wait on CI; the\nfinish step pushes and leaves the PR draft;`
9. §Failure policy: insert before the bullet that starts `- **A stopped \`autoflow\` workflow**`:

```markdown
- **CI on the PR's head commit red after the fix round, or not settled in an hour** (§The CI gate) →
  **halt**, the PR still draft: `finish` records the gate's answer on `review-pr`, and the duties after
  `handoff` under *Bound exhaustion* apply.
```

- [ ] **Step 3: Check the headings and pointers**

Run: `grep -n '^## The CI gate — CI on the PR' skills/pipeline/references/engine.md`
Expected: one line, after the `## Who takes the PR out of draft` line and before `## Closing links`.

Run: `grep -c '§The CI gate' skills/pipeline/references/engine.md`
Expected: 8 or more.

Run: `grep -n 'gh pr ready <pr>\`\*\* (§Who' skills/pipeline/references/engine.md`
Expected: no output (replacement 4 done).

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter=LockStep`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add skills/pipeline/references/engine.md
git commit -m "docs(pipeline): engine.md §The CI gate: CI on the PR's head commit before gh pr ready; one fix round (#85, #77)"
```

---

### Task 5: the brief lines

**Files:**
- Modify: `skills/pipeline/checks/brief.php`
- Test: `skills/pipeline/checks/tests/BriefTest.php`

**Interfaces:**
- Consumes: Task 1's `pipeline_ci_rounds()`; Task 4's `§The CI gate`; existing `pipeline_leg_overrides(string $mode): array`, `pipeline_brief_overrides(array $manifest, string $leg, string $step): string`, and the test helper `brief_manifest(string $leg, array $extra = []): array` (default mode `interactive`).
- Produces: `pipeline_ci_round_line(string $step): string`.

- [ ] **Step 1: Write the failing tests.** In `BriefTest.php`:

1. Replace the test *tells implement to add the ci label before the push whose CI it watches, in both modes* with:

```php
it('tells an autoflow implement not to wait on CI, and an interactive one to label before the push whose CI it watches', function () {
    expect(pipeline_brief(brief_manifest('implement', ['mode' => 'autoflow']), 'implement', '/tmp/m.json'))
        ->toContain('Add the `ci` label (`gh pr edit <pr> --add-label ci`) before your first push, in a repo that has one, and do not wait on CI after it: this overrides `work-on`\'s CI watch; the CI gate reads the PR\'s head commit before `gh pr ready` (engine.md §The CI gate).')
        ->not->toContain('the push whose CI you watch');
    expect(pipeline_brief(brief_manifest('implement'), 'implement', '/tmp/m.json'))
        ->toContain('Add the `ci` label (`gh pr edit <pr> --add-label ci`) before the push whose CI you watch.');
});
```

2. In the test *leaves the PR draft at the autoflow finish step for the session that launched the run*, replace `->toContain('Leave the PR draft; the session that launched the run marks it ready.')` with `->toContain('Push your commits and leave the PR draft; the session that launched the run marks it ready after the CI gate (engine.md §The CI gate).')`.

3. Add after that test:

```php
it('has the interactive finish step run the CI gate before gh pr ready', function () {
    $open = ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r'];

    expect(pipeline_brief(brief_manifest('review-pr', ['gate_ledger' => [$open]]), 'review-pr', '/tmp/m.json'))
        ->toContain('Run the CI gate (engine.md §The CI gate) and `gh pr ready` when it answers `ready`; show any other answer to the human. The last action is `proof_cli.php open`');
});

it('makes a recorded red CI a finding of review-pr\'s review and resolve steps, and only then', function () {
    $open = ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 2, 'at' => '2026-09-22T12:00:00Z', 'review' => 'r'];
    $round = ['mode' => 'autoflow', 'decisions' => ['The engine never edits.', "CI red on the PR's head commit abc123: CI / ci failed (https://github.com/acme/app/actions/runs/11/job/12)"]];
    $review = 'The settled `CI red on the PR\'s head commit` decision is a finding of this review: read the failing job\'s log (`gh run view <run> --log-failed`, the run id from its link) and state the failure and its cause (engine.md §The CI gate).';
    $resolve = 'Fix the `CI red` finding, or show it is unrelated to this change (the same failure on the base branch, or a flake: start `gh run rerun <run> --failed` and do not wait on it), and say which in `actions`; the CI gate reads the head commit again (engine.md §The CI gate).';

    expect(pipeline_brief(brief_manifest('review-pr', $round), 'review-pr', '/tmp/m.json', 'review'))->toContain($review)->not->toContain($resolve);
    expect(pipeline_brief(brief_manifest('review-pr', [...$round, 'gate_ledger' => [$open]]), 'review-pr', '/tmp/m.json', 'resolve'))->toContain($resolve)->not->toContain($review);
    expect(pipeline_brief(brief_manifest('review-pr', ['mode' => 'autoflow']), 'review-pr', '/tmp/m.json', 'review'))->not->toContain('finding of this review: read the failing job');
    expect(pipeline_brief(brief_manifest('implement', $round), 'implement', '/tmp/m.json'))->not->toContain($review)->not->toContain($resolve);
});
```

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter=BriefTest`
Expected: FAIL: the four new or changed tests (the autoflow `implement` line, the autoflow finish line, the interactive finish line and the fix-round lines are not in `brief.php` yet).

- [ ] **Step 3: Implement.** In `brief.php`:

1. In `pipeline_leg_overrides()`, `'implement:run'`, replace the line `'Add the \`ci\` label (\`gh pr edit <pr> --add-label ci\`) before the push whose CI you watch.',` with:

```php
            $autoflow
                ? 'Add the `ci` label (`gh pr edit <pr> --add-label ci`) before your first push, in a repo that has one, and do not wait on CI after it: this overrides `work-on`\'s CI watch; the CI gate reads the PR\'s head commit before `gh pr ready` (engine.md §The CI gate).'
                : 'Add the `ci` label (`gh pr edit <pr> --add-label ci`) before the push whose CI you watch.',
```

2. In `'review-pr:resolve'`, replace the last line

```php
            ($autoflow ? 'Leave the PR draft; the session that launched the run marks it ready.' : 'Run `gh pr ready`.') . ' The last action is `proof_cli.php open` on `artifacts.proof` (engine.md §The proof store).',
```

   with

```php
            ($autoflow
                ? 'Push your commits and leave the PR draft; the session that launched the run marks it ready after the CI gate (engine.md §The CI gate).'
                : 'Run the CI gate (engine.md §The CI gate) and `gh pr ready` when it answers `ready`; show any other answer to the human.')
            . ' The last action is `proof_cli.php open` on `artifacts.proof` (engine.md §The proof store).',
```

3. In `pipeline_brief_overrides()`, add before `if ($leg !== 'design') {`:

```php
    if ($leg === 'review-pr' && pipeline_ci_rounds($manifest) > 0) {
        $lines[] = pipeline_ci_round_line($step);
    }
```

4. Add after `pipeline_plan_gap_lines()`:

```php
/** The CI gate's fix round (engine.md §The CI gate): the recorded failure is a finding of `review-pr`. */
function pipeline_ci_round_line(string $step): string
{
    return $step === 'review'
        ? 'The settled `CI red on the PR\'s head commit` decision is a finding of this review: read the failing job\'s log (`gh run view <run> --log-failed`, the run id from its link) and state the failure and its cause (engine.md §The CI gate).'
        : 'Fix the `CI red` finding, or show it is unrelated to this change (the same failure on the base branch, or a flake: start `gh run rerun <run> --failed` and do not wait on it), and say which in `actions`; the CI gate reads the head commit again (engine.md §The CI gate).';
}
```

- [ ] **Step 4: Run the tests, the lock-step test and the suite**

Run: `php -l skills/pipeline/checks/brief.php`
Expected: `No syntax errors detected in skills/pipeline/checks/brief.php`

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='BriefTest|keeps every engine.md section a brief names'`
Expected: PASS (the section test now also finds `§The CI gate`).

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, 0 failed.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/brief.php skills/pipeline/checks/tests/BriefTest.php
git commit -m "feat(pipeline): briefs: autoflow implement does not wait on CI; the finish step defers to the CI gate; a recorded red is a review finding (#85, #77)"
```

---

### Task 6: `SKILL.md`, `orchestrate` and `manifest.md`

**Files:**
- Modify: `skills/pipeline/SKILL.md`, `skills/orchestrate/SKILL.md`, `skills/orchestrate/references/commands.md`, `skills/pipeline/references/manifest.md`

**Interfaces:**
- Consumes: engine.md §The CI gate (Task 4), `ci` and `launch --decision` (Tasks 2–3).
- Produces: nothing for code.

- [ ] **Step 1: Pipeline `SKILL.md` §`autoflow`, step 5.** Replace

```markdown
5. **`finish` printed `done`:** `gh pr ready <pr>`. The manifest already says done; when
   `gh pr ready` is denied the PR stays draft and no halt is written: put the denial in the report,
   and the owner runs `gh pr ready` by hand. **A halt after `handoff`:** the reason into the PR body
   and the proof page opened once (`references/engine.md` §Failure policy).
```

with

```markdown
5. **`finish` printed `done`: the CI gate** on the PR's head commit (`references/engine.md` §The CI
   gate), polled in one background Bash; wait for its completion notice:
   `poll=1; while answer=$(php "$CHECKS/dispatch_cli.php" ci <manifest> --poll $poll); echo "$answer" | grep -q '"action":"wait"'; do sleep 30; poll=$((poll + 1)); done; echo "$answer"`.
   **`ready`:** `gh pr ready <pr>`. The manifest already says done; when `gh pr ready` is denied the
   PR stays draft and no halt is written: put the denial in the report, and the owner runs
   `gh pr ready` by hand. **`fix`:** the diff as in step 2, then
   `launch <manifest> "<manifest stem>.diff" --from review-pr --decision "<its decision>"`, and steps
   3–5 again. **`halt`:** `finish <manifest> '<the answer>'`, then as any halt. **A halt after
   `handoff`:** the reason into the PR body and the proof page opened once (`references/engine.md`
   §Failure policy).
```

- [ ] **Step 2: `orchestrate` `SKILL.md`.**
1. Step 2: `(a PR awaiting merge does not count)` becomes `(a run in its CI gate counts; a PR awaiting merge does not)`.
2. Step 5: `\`finish\` it, then when \`finish\` prints \`done\` run \`gh pr ready <P>\` yourself;` becomes `\`finish\` it; when \`finish\` prints \`done\`, run the CI gate in a background shell (commands §Finish) and on its \`ready\` run \`gh pr ready <P>\` yourself; on \`fix\`, the fix round (commands §Finish); on \`halt\`, \`finish\` its answer, and the run is halted;` (the rest of step 5 is unchanged).
3. The rule *Commits wanted on a ready PR*: `Then the request into the manifest's \`decisions\`, \`launch --from review-pr\` and a new workflow (commands §Launch).` becomes `Then \`launch --from review-pr --decision "<the request, verbatim>"\` and a new workflow (commands §Launch).`

- [ ] **Step 3: `orchestrate` `references/commands.md`.**
1. §Launch, the *Commits wanted on a ready PR* block: replace

```bash
php -r '$m = json_decode(file_get_contents($argv[1]), true); $m["decisions"][] = $argv[2]; file_put_contents($argv[1], json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");' <manifest> "<the owner's request, verbatim>"
git -C <worktree> diff origin/<base>...HEAD > <manifest stem>.diff
PIPELINE_NO_OPEN=1 php ~/.claude/skills/pipeline/checks/dispatch_cli.php launch <manifest> <manifest stem>.diff --from review-pr
```

   with

```bash
git -C <worktree> diff origin/<base>...HEAD > <manifest stem>.diff
PIPELINE_NO_OPEN=1 php ~/.claude/skills/pipeline/checks/dispatch_cli.php launch <manifest> <manifest stem>.diff --from review-pr --decision "<the owner's request, verbatim>"
```

2. §Finish: replace the line

```bash
gh pr ready <P> -R <repo>                                                      # only when finish printed done
```

   with

```bash
poll=1; while answer=$(php ~/.claude/skills/pipeline/checks/dispatch_cli.php ci <manifest> --poll $poll); echo "$answer" | grep -q '"action":"wait"'; do sleep 30; poll=$((poll + 1)); done; echo "$answer"   # only when finish printed done; run_in_background
gh pr ready <P> -R <repo>                                                      # only when the gate answered ready
```

   and add after the paragraph that ends `…it opens only on a ready PR (§Proof page).`:

```markdown
The CI gate (pipeline `engine.md` §The CI gate) runs in one background Bash and wakes you with its
answer; a run in its gate still counts as working. `fix`: the fix round, as §Launch's *commits wanted*
block with `--decision "<its decision>"` in place of the owner's request and no `gh pr ready --undo`
(the PR is still draft), then a new `pipeline-autoflow` workflow in the dispatch record. `halt`:
`finish <manifest> '<the answer>'`, and the run is halted like any other.
```

- [ ] **Step 4: `manifest.md`.** In the schema table, the `decisions` row's text `the settled decisions from the invocation, verbatim, as a list. Every brief carries them` becomes `the settled decisions from the invocation, verbatim, as a list, and what \`launch --decision\` adds: an owner's request on a ready PR, or the CI gate's failure record (\`engine.md\` §The CI gate). Every brief carries them`.

- [ ] **Step 5: Check and run the suite**

Run: `grep -n 'php -r' skills/orchestrate/references/commands.md`
Expected: no output.

Run: `grep -c 'ci <manifest> --poll' skills/pipeline/SKILL.md skills/orchestrate/references/commands.md skills/pipeline/references/engine.md`
Expected: `skills/pipeline/SKILL.md:1`, `skills/orchestrate/references/commands.md:1`, `skills/pipeline/references/engine.md:3`.

Run: `bash skills/orchestrate/tests/owners_test.sh`
Expected: last line `PASS owners.py` (the script is unchanged; this guards the orchestrate directory).

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, 0 failed.

- [ ] **Step 6: Commit**

```bash
git add skills/pipeline/SKILL.md skills/orchestrate/SKILL.md skills/orchestrate/references/commands.md skills/pipeline/references/manifest.md
git commit -m "docs(pipeline, orchestrate): the session runs the CI gate before gh pr ready; launch --decision replaces the php -r write (#85)"
```
