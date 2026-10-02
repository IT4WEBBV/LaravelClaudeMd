# The CI gate reads whether the PR merges cleanly Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `dispatch_cli.php ci` reads the PR's `mergeable` with its head and checks: `CONFLICTING` answers
`fix` (one conflict round per run, then a halt), `UNKNOWN` answers `wait` (a halt at the 120th read), and
anything else goes on to the checks as today.

**Architecture:** `dispatch_cli_ci()` asks gh for one more field. `pipeline_ci_answer()` (`checks/ci.php`)
reads it after the head comparison and before the checks, through two new helpers beside
`pipeline_ci_red()`; the conflict round is counted from `decisions` by a new prefix constant, as the CI
and merge rounds are. `pipeline_brief_overrides()` (`checks/brief.php`) gives `review-pr`'s review and
resolve steps one line each while a conflict round is recorded; the resolve step's existing catch-up
override does the merge. engine.md, manifest.md and pipeline `SKILL.md` say so.

**Tech Stack:** PHP 8.3+ with Pest 4 in `skills/pipeline/checks/tests`; Markdown docs.

**Spec:** `docs/superpowers/specs/2026-10-02-pipeline-ci-gate-reads-mergeable-design.md`. Read it with this
plan: the plan argues from it, and its `## Assumptions` 10–13 are the answers this plan assumed.

## Global Constraints

- Run every command from the worktree root
  `/Users/jroelofs/GitProjects/LaravelClaudeMd/LaravelClaudeMd/.claude/worktrees/worktree-issue-149-pipeline-the-ci-gate-answers-ready-on-a-pr-that`.
  This repo is not a Docker project: Pest runs on the host. The worktree has no `vendor/`: run
  `composer install` once before the first Pest call.
- Pest: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
  (append `--filter "<text>"` for some cases).
- Test-first: each task writes its tests, sees them fail, then writes the code. `php -l` every PHP file
  you change.
- Constants and texts, verbatim (spec §1, §2):
  - `const PIPELINE_CONFLICT = "Conflict with the base on the PR's head commit ";` (trailing space)
  - the gh fields: `headRefOid,mergeable,statusCheckRollup`
  - the conflict decision: `PIPELINE_CONFLICT . "{$sha}: GitHub reports PR #{$pr} CONFLICTING with its base; review-pr's resolve step merges the base (engine.md §Catching up with the base)"`
  - the spent-round halt: `PR #{$pr} conflicts with its base again after the conflict round, on {$sha}: merge the base into the branch (engine.md §Catching up with the base), push, and run the CI gate again`
  - the unknown halt: `GitHub had not worked out whether PR #{$pr} merges into its base after an hour, on {$sha}`
  - verdicts: `conflicting`, `unknown`
  - the review line: ``The settled `Conflict with the base` decision is a finding of this review unless HEAD already contains the base's tip (`git merge-base --is-ancestor origin/<base> HEAD`): name the conflict and leave the merge to the resolve step, since a review step does not merge (engine.md §Catching up with the base).``
  - the resolve line: ``Resolve the `Conflict with the base` finding with the merge this brief's catch-up override asks for, and name it in `actions`; when this brief has no such override, say so in `actions` and change nothing for it: the CI gate reads the PR's mergeability again (engine.md §The CI gate).``
- `<base>` in the review line is literal text, not substituted.
- Answers' key order (the tests use `toBe`): `fix` is `['action', 'verdict', 'sha', 'decision']`; a
  `wait` is `['action', 'verdict', 'sha']`; a halt is `['action', 'leg', 'reason', 'verdict', 'sha']`
  (`pipeline_ci_halt()` puts `action`, `leg`, `reason` first).
- No new null-safety: `$view['mergeable']` is read directly; gh always returns a field it was asked for.
- No changelog entry: the repo has no `.changelog/` and no `CHANGELOG.md`.
- Commits: no `Co-Authored-By`, no AI attribution; every message ends on `(#149)`.

## File Structure

| File | Responsibility |
|---|---|
| `skills/pipeline/checks/ci.php` (modify) | `PIPELINE_CONFLICT`; the mergeability read in `pipeline_ci_answer()`; `pipeline_ci_unknown()`, `pipeline_ci_conflict()`, `pipeline_conflict_rounds()` |
| `skills/pipeline/checks/dispatch_cli.php` (modify) | `dispatch_cli_ci()` asks gh for `mergeable` too |
| `skills/pipeline/checks/brief.php` (modify) | `pipeline_conflict_round_line()`; `pipeline_brief_overrides()` adds it after the CI round's line |
| `skills/pipeline/checks/tests/CiTest.php` (modify) | `ci_view()` takes a `mergeable`; the gate's new answers, their order, the counters |
| `skills/pipeline/checks/tests/DispatchCliTest.php` (modify) | the fixtures' views carry `mergeable`; the issue's three answers and the spent halt through the CLI |
| `skills/pipeline/checks/tests/BriefTest.php` (modify) | the two brief lines, and only on a recorded conflict |
| `skills/pipeline/checks/tests/LockStepTest.php` (modify) | the new lines' `§` names are engine.md headings |
| `skills/pipeline/references/engine.md` (modify) | §The CI gate: the gh call, two table rows, the conflict bullet, `fix` and `interactive` bullets |
| `skills/pipeline/references/manifest.md` (modify) | the `decisions` row names three records |
| `skills/pipeline/SKILL.md` (modify) | §`autoflow` step 5's `fix` names the conflict |

Orchestrate's `SKILL.md` and `references/commands.md` treat `fix` generically: unchanged (spec §3).

## Review Focus

1. **A conflicting PR whose checks are still pending, or red.** Expected: `fix` / `conflicting` at once,
   not a `wait` on the pending checks nor the CI fix round (GitHub runs no CI on a conflicting PR's merge
   ref, so its checks say nothing). Test in Task 1 (`answers one conflict round…`).
2. **`UNKNOWN` on a repo with no workflows and no checks**, the issue's first read after `gh pr ready`.
   Expected: `wait` / `unknown`, never `ready` / `none`. Test in Task 1 (`waits while GitHub has not
   worked out…`).
3. **A `mergeable` value GitHub does not send today** (a new enum value, or an empty string). Expected:
   on to the checks as before, so the gate never holds a PR the old gate readied. Test in Task 1 (`reads
   mergeability after…`, the `SOMETHING_NEW` and `''` lines).
4. **A halt reason with a single quote**, which ends `finish <manifest> '<the answer>'`'s shell argument
   early. Expected: neither new reason holds one (spec assumption 10). Test in Task 1 (both halts'
   `not->toContain("'")`).
5. **A `§` name in the new brief lines that engine.md does not have** (a heading renamed later).
   Expected: the suite fails. Test in Task 2 (`LockStepTest`).

---

### Task 1: The gate reads the PR's mergeability

**Files:**
- Modify: `skills/pipeline/checks/ci.php` (constants at 14–18, `pipeline_ci_answer()` at 76–110, the
  round counters at 149–159)
- Modify: `skills/pipeline/checks/dispatch_cli.php:494`
- Test: `skills/pipeline/checks/tests/CiTest.php`, `skills/pipeline/checks/tests/DispatchCliTest.php`

**Interfaces:**
- Consumes: `pipeline_ci_halt(string $reason, array $read): array`,
  `pipeline_decisions_starting(array $manifest, string $prefix): int`, `PIPELINE_CI_POLLS` (all existing,
  `ci.php`).
- Produces: `const PIPELINE_CONFLICT`; `pipeline_conflict_rounds(array $manifest): int`;
  `pipeline_ci_conflict(array $manifest, array $read): array`;
  `pipeline_ci_unknown(array $manifest, string $sha, bool $last): array`. Task 2 uses
  `pipeline_conflict_rounds()`.

- [ ] **Step 1: Give the test views a `mergeable`**

In `CiTest.php`, replace `ci_view()` (lines 18–21):

```php
function ci_view(array $rollup, string $mergeable = 'MERGEABLE'): array
{
    return ['headRefOid' => 'abc123', 'mergeable' => $mergeable, 'statusCheckRollup' => $rollup];
}
```

In `DispatchCliTest.php`, replace `ci_head()` (lines 797–800):

```php
function ci_head(string $conclusion, string $mergeable = 'MERGEABLE'): array
{
    return ['headRefOid' => 'abc123', 'mergeable' => $mergeable, 'statusCheckRollup' => [['__typename' => 'CheckRun', 'name' => 'ci', 'workflowName' => 'CI', 'status' => 'COMPLETED', 'conclusion' => $conclusion, 'detailsUrl' => 'https://github.com/acme/app/actions/runs/11/job/12']]];
}
```

and in `it('waits on no checks while the worktree has workflows…')` change the `$none` line to:

```php
    $none = ['headRefOid' => 'abc123', 'mergeable' => 'MERGEABLE', 'statusCheckRollup' => []];
```

In `it('gates the PR\'s head commit with one gh read…')` change the calls expectation to:

```php
    expect(file($fixture['dir'] . '/calls', FILE_IGNORE_NEW_LINES))->toBe(['pr view 7 --json headRefOid,mergeable,statusCheckRollup']);
```

- [ ] **Step 2: Write the failing gate cases in `CiTest.php`**

Append to `CiTest.php`:

```php
function ci_conflict_decision(): string
{
    return "Conflict with the base on the PR's head commit abc123: GitHub reports PR #7 CONFLICTING with its base; review-pr's resolve step merges the base (engine.md §Catching up with the base)";
}

it('answers one conflict round on a PR that conflicts with its base, whatever its checks, and halts on a conflict after it (#149)', function () {
    $fix = ['action' => 'fix', 'verdict' => 'conflicting', 'sha' => 'abc123', 'decision' => ci_conflict_decision()];
    $reason = 'PR #7 conflicts with its base again after the conflict round, on abc123: merge the base into the branch (engine.md §Catching up with the base), push, and run the CI gate again';

    expect(pipeline_ci_answer(ci_manifest(), ci_view([ci_run('ci', 'COMPLETED', 'SUCCESS')], 'CONFLICTING'), 'abc123', true, 1))->toBe($fix);
    expect(pipeline_ci_answer(ci_manifest(), ci_view([], 'CONFLICTING'), 'abc123', true, 1))->toBe($fix);
    expect(pipeline_ci_answer(ci_manifest(), ci_view([ci_run('ci', 'IN_PROGRESS')], 'CONFLICTING'), 'abc123', true, 1))->toBe($fix);
    expect(pipeline_ci_answer(ci_manifest(), ci_view([ci_run('ci', 'COMPLETED', 'FAILURE')], 'CONFLICTING'), 'abc123', true, 1))->toBe($fix);
    expect(pipeline_ci_answer(ci_manifest(['Keep the guard', ci_conflict_decision()]), ci_view([ci_run('ci', 'COMPLETED', 'SUCCESS')], 'CONFLICTING'), 'abc123', true, 1))
        ->toBe(['action' => 'halt', 'leg' => 'review-pr', 'reason' => $reason, 'verdict' => 'conflicting', 'sha' => 'abc123']);
    expect($reason)->not->toContain("'");
});

it('waits while GitHub has not worked out whether the PR merges, even without checks or workflows, and halts at the hour (#149)', function () {
    $wait = ['action' => 'wait', 'verdict' => 'unknown', 'sha' => 'abc123'];
    $unknown = ci_view([ci_run('ci', 'COMPLETED', 'SUCCESS')], 'UNKNOWN');
    $reason = 'GitHub had not worked out whether PR #7 merges into its base after an hour, on abc123';

    expect(pipeline_ci_answer(ci_manifest(), $unknown, 'abc123', true, 1))->toBe($wait);
    expect(pipeline_ci_answer(ci_manifest(), $unknown, 'abc123', true, 119))->toBe($wait);
    expect(pipeline_ci_answer(ci_manifest(), ci_view([], 'UNKNOWN'), 'abc123', false, 1))->toBe($wait);
    expect(pipeline_ci_answer(ci_manifest(), $unknown, 'abc123', true, 120))
        ->toBe(['action' => 'halt', 'leg' => 'review-pr', 'reason' => $reason, 'verdict' => 'unknown', 'sha' => 'abc123']);
    expect($reason)->not->toContain("'");
});

it('reads mergeability after the merge round and the head comparison, and any other value goes on to the checks (#149)', function () {
    $mismatch = ['action' => 'wait', 'verdict' => 'mismatch', 'sha' => 'abc123', 'head' => 'def456'];
    $merge = "Unreviewed merge on the PR's head commit def456: a merge since the last completed review met this branch's changes in a.php";
    $green = fn (string $mergeable) => ci_view([ci_run('ci', 'COMPLETED', 'SUCCESS')], $mergeable);

    expect(pipeline_ci_answer(ci_manifest(), ci_view([], 'CONFLICTING'), 'def456', true, 1))->toBe($mismatch);
    expect(pipeline_ci_answer(ci_manifest(), ci_view([], 'UNKNOWN'), 'def456', true, 1))->toBe($mismatch);
    expect(pipeline_ci_answer(ci_manifest(), ci_view([], 'CONFLICTING'), 'def456', true, 1, ['a.php']))
        ->toBe(['action' => 'fix', 'verdict' => 'merge', 'files' => ['a.php'], 'decision' => $merge]);
    expect(pipeline_ci_answer(ci_manifest(), $green('SOMETHING_NEW'), 'abc123', true, 1))->toBe(['action' => 'ready', 'verdict' => 'green', 'sha' => 'abc123']);
    expect(pipeline_ci_answer(ci_manifest(), $green(''), 'abc123', true, 1))->toBe(['action' => 'ready', 'verdict' => 'green', 'sha' => 'abc123']);
    expect(pipeline_ci_answer(ci_manifest(), ci_view([ci_run('ci', 'COMPLETED', 'FAILURE')]), 'abc123', true, 1))->toMatchArray(['action' => 'fix', 'verdict' => 'red']);
});

it('counts the conflict round apart from the CI and merge rounds (#149)', function () {
    $merge = "Unreviewed merge on the PR's head commit def456: a merge since the last completed review met this branch's changes in a.php";
    $ci = "CI red on the PR's head commit abc123: CI / ci failed (https://github.com/acme/app/actions/runs/11/job/ci)";

    expect(pipeline_conflict_rounds(ci_manifest(['Keep the guard', $merge, $ci])))->toBe(0);
    expect(pipeline_conflict_rounds(ci_manifest([ci_conflict_decision()])))->toBe(1);
    expect(pipeline_conflict_rounds(['branch' => 'feature/x']))->toBe(0);
    expect(pipeline_ci_rounds(ci_manifest([ci_conflict_decision()])))->toBe(0);
    expect(pipeline_merge_rounds(ci_manifest([ci_conflict_decision()])))->toBe(0);

    expect(pipeline_ci_answer(ci_manifest([ci_conflict_decision()]), ci_view([ci_run('ci', 'COMPLETED', 'FAILURE')]), 'abc123', true, 1))
        ->toMatchArray(['action' => 'fix', 'verdict' => 'red']);
    expect(pipeline_ci_answer(ci_manifest([$ci, $merge]), ci_view([], 'CONFLICTING'), 'abc123', true, 1, ['a.php']))
        ->toMatchArray(['action' => 'fix', 'verdict' => 'conflicting']);
});
```

- [ ] **Step 3: Write the failing CLI cases in `DispatchCliTest.php`**

Insert after `it('waits while gh cannot read the PR, and halts at the last read', …);`:

```php
it('answers the issue\'s three through the CLI: a conflicting PR fix, an unknown one wait, a clean one ready (#149)', function () {
    $view = fn (string $mergeable) => ['headRefOid' => 'abc123', 'mergeable' => $mergeable, 'statusCheckRollup' => []];
    $decision = "Conflict with the base on the PR's head commit abc123: GitHub reports PR #7 CONFLICTING with its base; review-pr's resolve step merges the base (engine.md §Catching up with the base)";
    $conflicting = ci_fixture($view('CONFLICTING'), false);
    $before = file_get_contents($conflicting['manifest']);

    expect(ci_gate($conflicting)['json'])->toBe(['action' => 'fix', 'verdict' => 'conflicting', 'sha' => 'abc123', 'decision' => $decision]);
    expect(file_get_contents($conflicting['manifest']))->toBe($before);
    expect(ci_gate(ci_fixture($view('UNKNOWN'), false))['json'])->toBe(['action' => 'wait', 'verdict' => 'unknown', 'sha' => 'abc123']);
    expect(ci_gate(ci_fixture($view('MERGEABLE'), false))['json'])->toBe(['action' => 'ready', 'verdict' => 'none', 'sha' => 'abc123']);
});

it('halts a conflict once the conflict round is spent, and finish records it on review-pr (#149)', function () {
    $decision = "Conflict with the base on the PR's head commit abc123: GitHub reports PR #7 CONFLICTING with its base; review-pr's resolve step merges the base (engine.md §Catching up with the base)";
    $reason = 'PR #7 conflicts with its base again after the conflict round, on abc123: merge the base into the branch (engine.md §Catching up with the base), push, and run the CI gate again';
    $spent = ci_fixture(ci_head('SUCCESS', 'CONFLICTING'), true, [$decision]);

    $halt = ci_gate($spent)['stdout'];
    expect(json_decode($halt, true))->toBe(['action' => 'halt', 'leg' => 'review-pr', 'reason' => $reason, 'verdict' => 'conflicting', 'sha' => 'abc123']);

    dispatch_cli(['finish', $spent['manifest'], trim($halt)]);
    expect(manifest_read($spent['manifest'])['cursor'])->toBe(['leg' => 'review-pr', 'status' => 'halted', 'reason' => $reason]);
});
```

- [ ] **Step 4: Run them to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "CiTest|DispatchCliTest"`
Expected: FAIL — three of the four `(#149)` cases in `CiTest` (`Call to undefined function
pipeline_conflict_rounds()` in the counter case; `ready`, `wait` / `none`, `wait` / `pending` or `fix` /
`red` where `conflicting` or `unknown` is expected in the other two), the two `(#149)` cases in
`DispatchCliTest` (`ready` / `none` and `ready` / `green` where `conflicting` and `unknown` are
expected), and `gates the PR's head commit with one gh read` (the call is `pr view 7 --json
headRefOid,statusCheckRollup`). `reads mergeability after the merge round and the head comparison…`
passes already: it pins that the new read goes after those two and lets other values through, which the
current gate does by not reading the field. Every other case passes: the views' `mergeable` is
`MERGEABLE`.

- [ ] **Step 5: Implement the read in `ci.php`**

After `PIPELINE_MERGE_UNREVIEWED` (line 18) add:

```php

/** How the gate's conflict record starts in `decisions`; one such decision is the run's conflict round spent. */
const PIPELINE_CONFLICT = "Conflict with the base on the PR's head commit ";
```

Replace the docblock and body of `pipeline_ci_answer()` (lines 76–110) with:

```php
/**
 * What the session does next: `wait` and read again, `ready` (`gh pr ready`), `fix` (the decision into
 * `decisions` through `launch --from review-pr --decision`), or `halt` (`finish`'s input). `$view` is
 * `gh pr view <pr> --json headRefOid,mergeable,statusCheckRollup`, null when gh could not read it; `$head`
 * the worktree's `HEAD`, which GitHub's head must be before its mergeability or checks count; `$workflows`
 * whether the worktree has GitHub Actions workflows; `$poll` this read's number, from 1; `$unreviewed` the
 * files where a merge since the last completed review met the branch's changes, which get one review round
 * before the PR or its checks are read. A PR that conflicts with its base, or whose mergeability GitHub has
 * not worked out, is answered before its checks: GitHub runs no CI on a conflicting PR's merge ref.
 */
function pipeline_ci_answer(array $manifest, ?array $view, string $head, bool $workflows, int $poll, array $unreviewed = []): array
{
    if ($unreviewed !== [] && pipeline_merge_rounds($manifest) === 0) {
        return pipeline_ci_unreviewed($head, $unreviewed);
    }
    $last = $poll >= PIPELINE_CI_POLLS;
    if ($view === null) {
        return $last
            ? pipeline_ci_halt("CI on PR #{$manifest['artifacts']['pr']} had not settled after an hour, and gh could not read its checks at the last read", ['verdict' => 'unreadable'])
            : ['action' => 'wait', 'verdict' => 'unreadable'];
    }
    if ($view['headRefOid'] !== $head) {
        return pipeline_ci_mismatch($manifest, $view['headRefOid'], $head, $poll);
    }
    if ($view['mergeable'] === 'UNKNOWN') {
        return pipeline_ci_unknown($manifest, $view['headRefOid'], $last);
    }
    if ($view['mergeable'] === 'CONFLICTING') {
        return pipeline_ci_conflict($manifest, ['verdict' => 'conflicting', 'sha' => $view['headRefOid']]);
    }
    $ci = pipeline_ci_verdict($view['statusCheckRollup']);
    $read = ['verdict' => $ci['verdict'], 'sha' => $view['headRefOid']];

    return match ($ci['verdict']) {
        'green' => ['action' => 'ready', ...$read],
        'none' => ['action' => $workflows && $poll < PIPELINE_CI_PUSH_POLLS ? 'wait' : 'ready', ...$read],
        'pending' => $last
            ? pipeline_ci_halt("CI on {$read['sha']} has not finished after an hour: " . implode(', ', $ci['pending']), $read)
            : ['action' => 'wait', ...$read],
        'red' => pipeline_ci_red($manifest, [...$read, 'failing' => $ci['failing']]),
    };
}
```

After `pipeline_ci_red()` (ends line 130) add:

```php

/** GitHub works mergeability out lazily, after a push or `gh pr ready`: waited for, a halt at the hour. */
function pipeline_ci_unknown(array $manifest, string $sha, bool $last): array
{
    $read = ['verdict' => 'unknown', 'sha' => $sha];

    return $last
        ? pipeline_ci_halt("GitHub had not worked out whether PR #{$manifest['artifacts']['pr']} merges into its base after an hour, on {$sha}", $read)
        : ['action' => 'wait', ...$read];
}

/** The first conflict with the base of a run is its conflict round, where review-pr's resolve step merges the base; a conflict after it halts. */
function pipeline_ci_conflict(array $manifest, array $read): array
{
    $pr = $manifest['artifacts']['pr'];

    return pipeline_conflict_rounds($manifest) === 0
        ? ['action' => 'fix', ...$read, 'decision' => PIPELINE_CONFLICT . "{$read['sha']}: GitHub reports PR #{$pr} CONFLICTING with its base; review-pr's resolve step merges the base (engine.md §Catching up with the base)"]
        : pipeline_ci_halt("PR #{$pr} conflicts with its base again after the conflict round, on {$read['sha']}: merge the base into the branch (engine.md §Catching up with the base), push, and run the CI gate again", $read);
}
```

After `pipeline_merge_rounds()` add:

```php

/** The conflict rounds this run has had: the decisions the gate's conflict record starts. */
function pipeline_conflict_rounds(array $manifest): int
{
    return pipeline_decisions_starting($manifest, PIPELINE_CONFLICT);
}
```

- [ ] **Step 6: Ask gh for the field in `dispatch_cli.php`**

In `dispatch_cli_ci()` (line 494) change:

```php
        dispatch_cli_pr_view($worktree, $pr, 'headRefOid,statusCheckRollup'),
```

to:

```php
        dispatch_cli_pr_view($worktree, $pr, 'headRefOid,mergeable,statusCheckRollup'),
```

and in its docblock (lines 467–468, the phrase spans the line break after `head`) change `then the PR's
head commit and its checks in one gh call` to `then the PR's head commit, its mergeability and its checks
in one gh call`, rewrapping the docblock to its 110-column lines.

- [ ] **Step 7: Run them to see them pass**

Run: `php -l skills/pipeline/checks/ci.php && php -l skills/pipeline/checks/dispatch_cli.php && ./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "CiTest|DispatchCliTest"`
Expected: `No syntax errors detected` twice, then PASS, every case.

- [ ] **Step 8: Commit**

```bash
git add skills/pipeline/checks/ci.php skills/pipeline/checks/dispatch_cli.php skills/pipeline/checks/tests/CiTest.php skills/pipeline/checks/tests/DispatchCliTest.php
git commit -m "feat(pipeline): the CI gate reads mergeable: a conflict is a fix round, an unknown a wait (#149)"
```

---

### Task 2: The conflict round's lines in `review-pr`'s briefs

**Files:**
- Modify: `skills/pipeline/checks/brief.php` (`pipeline_brief_overrides()` at 230–232, after
  `pipeline_ci_round_line()` at 261–267)
- Test: `skills/pipeline/checks/tests/BriefTest.php`, `skills/pipeline/checks/tests/LockStepTest.php`

**Interfaces:**
- Consumes: `pipeline_conflict_rounds(array $manifest): int` (Task 1, `ci.php`).
- Produces: `pipeline_conflict_round_line(string $step): string` (`'review'` gives the review line, any
  other step the resolve line, as `pipeline_ci_round_line()` does).

- [ ] **Step 1: Write the failing brief case**

In `BriefTest.php`, insert after `it('makes a recorded red CI a finding of review-pr\'s review and resolve steps, and only then', …);`:

```php
it('makes a recorded conflict with the base a finding of review-pr\'s review and resolve steps, after the CI round\'s line, and only then (#149)', function () {
    $open = ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 2, 'at' => '2026-09-22T12:00:00Z', 'review' => 'r'];
    $conflict = "Conflict with the base on the PR's head commit abc123: GitHub reports PR #42 CONFLICTING with its base; review-pr's resolve step merges the base (engine.md §Catching up with the base)";
    $red = "CI red on the PR's head commit abc123: CI / ci failed (https://github.com/acme/app/actions/runs/11/job/12)";
    $round = ['mode' => 'autoflow', 'decisions' => ['The engine never edits.', $conflict]];
    $review = 'The settled `Conflict with the base` decision is a finding of this review unless HEAD already contains the base\'s tip (`git merge-base --is-ancestor origin/<base> HEAD`): name the conflict and leave the merge to the resolve step, since a review step does not merge (engine.md §Catching up with the base).';
    $resolve = 'Resolve the `Conflict with the base` finding with the merge this brief\'s catch-up override asks for, and name it in `actions`; when this brief has no such override, say so in `actions` and change nothing for it: the CI gate reads the PR\'s mergeability again (engine.md §The CI gate).';

    expect(pipeline_brief(brief_manifest('review-pr', $round), 'review-pr', '/tmp/m.json', 'review'))->toContain($review)->not->toContain($resolve);
    expect(pipeline_brief(brief_manifest('review-pr', [...$round, 'gate_ledger' => [$open]]), 'review-pr', '/tmp/m.json', 'resolve'))->toContain($resolve)->not->toContain($review);
    expect(pipeline_brief(brief_manifest('review-pr', ['mode' => 'autoflow', 'decisions' => [$red]]), 'review-pr', '/tmp/m.json', 'review'))->not->toContain('`Conflict with the base` decision is a finding');
    expect(pipeline_brief(brief_manifest('implement', $round), 'implement', '/tmp/m.json'))->not->toContain($review)->not->toContain($resolve);

    $both = pipeline_brief(brief_manifest('review-pr', ['mode' => 'autoflow', 'decisions' => [$red, $conflict]]), 'review-pr', '/tmp/m.json', 'review');
    expect($both)->toContain('`CI red on the PR\'s head commit` decision is a finding')->toContain($review);
    expect(strpos($both, '`CI red on the PR\'s head commit` decision is a finding'))->toBeLessThan(strpos($both, $review));
});
```

In `LockStepTest.php`, `it('keeps every engine.md section a brief names', …)`, extend the inner list
(after the `pipeline_catch_up_line(…)` line):

```php
            pipeline_conflict_round_line('review'),
            pipeline_conflict_round_line('resolve'),
```

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "BriefTest|LockStepTest"`
Expected: FAIL — `makes a recorded conflict with the base a finding…` (the review brief does not contain
the review line) and `keeps every engine.md section a brief names` (`Call to undefined function
pipeline_conflict_round_line()`). Every other case passes.

- [ ] **Step 3: Implement the lines in `brief.php`**

In `pipeline_brief_overrides()`, right after the CI round's block:

```php
    if ($leg === 'review-pr' && pipeline_ci_rounds($manifest) > 0) {
        $lines[] = pipeline_ci_round_line($step);
    }
```

add:

```php
    if ($leg === 'review-pr' && pipeline_conflict_rounds($manifest) > 0) {
        $lines[] = pipeline_conflict_round_line($step);
    }
```

After `pipeline_ci_round_line()` add:

```php

/** The CI gate's conflict round (engine.md §The CI gate): the review names the conflict, the resolve step's catch-up override merges the base. */
function pipeline_conflict_round_line(string $step): string
{
    return $step === 'review'
        ? 'The settled `Conflict with the base` decision is a finding of this review unless HEAD already contains the base\'s tip (`git merge-base --is-ancestor origin/<base> HEAD`): name the conflict and leave the merge to the resolve step, since a review step does not merge (engine.md §Catching up with the base).'
        : 'Resolve the `Conflict with the base` finding with the merge this brief\'s catch-up override asks for, and name it in `actions`; when this brief has no such override, say so in `actions` and change nothing for it: the CI gate reads the PR\'s mergeability again (engine.md §The CI gate).';
}
```

- [ ] **Step 4: Run them to see them pass**

Run: `php -l skills/pipeline/checks/brief.php && ./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "BriefTest|LockStepTest"`
Expected: `No syntax errors detected`, then PASS, every case.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/brief.php skills/pipeline/checks/tests/BriefTest.php skills/pipeline/checks/tests/LockStepTest.php
git commit -m "feat(pipeline): review-pr's briefs carry the conflict round's finding and its merge (#149)"
```

---

### Task 3: The docs say what the gate now does

**Files:**
- Modify: `skills/pipeline/references/engine.md` (§The CI gate, from line 1075)
- Modify: `skills/pipeline/references/manifest.md:28`
- Modify: `skills/pipeline/SKILL.md:141`

**Interfaces:**
- Consumes: the texts of Tasks 1 and 2 (Global Constraints).
- Produces: nothing code reads, except that §The CI gate's heading stays as it is (`LockStepTest`).

- [ ] **Step 1: engine.md §The CI gate, the gh call**

Replace:

```
  the PR's head commit and its checks once (`gh pr view <pr> --json headRefOid,statusCheckRollup`), writes
  nothing, and prints one JSON line. GitHub's head has to be the worktree's `HEAD` before its checks count
  (#99): a push that failed or was skipped leaves an older head whose CI can be green, and the PR would go
  ready without the last fix. That comparison comes first, so neither a green nor a red on an older
  commit counts:
```

with:

```
  the PR's head commit, whether it merges into its base, and its checks once (`gh pr view <pr> --json
  headRefOid,mergeable,statusCheckRollup`), writes nothing, and prints one JSON line. GitHub's head has to
  be the worktree's `HEAD` before its checks count (#99): a push that failed or was skipped leaves an older
  head whose CI can be green, and the PR would go ready without the last fix. That comparison comes first,
  so neither a green nor a red on an older commit counts; whether the PR merges comes next, before the
  checks (#149):
```

- [ ] **Step 2: engine.md, two table rows**

After the row that starts with the `mismatch` verdict ("GitHub's head is not the worktree's HEAD"), insert:

```
| `conflicting`: GitHub reports the PR `CONFLICTING` with its base | `fix` the first time in a run; `halt` once that round is spent |
| `unknown`: GitHub has not worked out mergeability yet (it computes it lazily, after a push or `gh pr ready`) | `wait`; `halt` at the 120th read |
```

- [ ] **Step 3: engine.md, the `fix` bullet's first sentence**

Replace:

```
- **`fix`** → one automatic fix round (owner, #85). The answer's `decision`, `CI red on the PR's head
  commit <sha>: <check> failed (<link>)`, goes into `decisions` verbatim with the re-arm:
```

with:

```
- **`fix`** → one automatic round per run for each of the gate's three records: a red CI (owner, #85), a
  merge the review did not see and a conflict with the base (both below). The answer's `decision`, for a
  red `CI red on the PR's head commit <sha>: <check> failed (<link>)`, goes into `decisions` verbatim with
  the re-arm:
```

- [ ] **Step 4: engine.md, the conflict bullet**

After the bullet that starts `- **A merge the review did not see** (#124).` and ends `changed is what a
review is for.`, insert:

```
- **A conflict with the base** (#149). A sibling merged into the base after this run's review can leave
  the PR `CONFLICTING`, and on a repo without CI the checks' `none` would still read `ready`. GitHub's
  `mergeable` is its answer for its head, so the gate reads it after the head comparison, and before the
  checks: GitHub runs no CI on a conflicting PR's merge ref, so its checks say nothing about the code that
  would merge. `CONFLICTING` answers `fix` with verdict `conflicting` and the decision `Conflict with the
  base on the PR's head commit <sha>: GitHub reports PR #<pr> CONFLICTING with its base; review-pr's
  resolve step merges the base (engine.md §Catching up with the base)`, and the session does what it does
  for a red. The review step names the conflict as a finding unless `HEAD` already contains the base's tip;
  the resolve step makes the merge its catch-up override asks for (a textual conflict means both sides
  changed a file, so §Catching up with the base gives that override), resolves it, runs the suite and
  pushes. At the next gate the merge round reviews those resolutions when it is unspent. Once per run,
  counted from `decisions` apart from the other two rounds: a conflict after it halts, naming `review-pr`.
  `UNKNOWN` is a `wait`, as a pending check is, and a halt at the 120th read.
```

- [ ] **Step 5: engine.md, the `interactive` bullet**

Replace:

```
  answer to the human; there is no automatic round, and no merge round: the human resolves the review
  and sees the merge as it is made.
```

with:

```
  answer to the human; there is no automatic round, no merge round and no conflict round: the human
  resolves the review, sees the merge as it is made, and on a `conflicting` answer merges the base.
```

- [ ] **Step 6: manifest.md and pipeline `SKILL.md`**

In `skills/pipeline/references/manifest.md` line 28 replace

```
or one of the CI gate's two records, a red CI or an unreviewed merge
```

with

```
or one of the CI gate's three records, a red CI, an unreviewed merge or a conflict with the base
```

In `skills/pipeline/SKILL.md` line 141 replace

```
**`fix`** (a red CI, or a merge the last review did not see):
```

with

```
**`fix`** (a red CI, a merge the last review did not see, or a conflict with the base):
```

- [ ] **Step 7: Check the docs and run the whole suite**

Run:

```bash
grep -c 'headRefOid,mergeable,statusCheckRollup' skills/pipeline/references/engine.md
grep -c '^| `conflicting`: GitHub reports' skills/pipeline/references/engine.md
grep -c '^| `unknown`: GitHub has not worked out' skills/pipeline/references/engine.md
grep -c 'A conflict with the base\*\* (#149)' skills/pipeline/references/engine.md
grep -c 'three records, a red CI, an unreviewed merge or a' skills/pipeline/references/manifest.md
grep -c 'or a conflict with the base):' skills/pipeline/SKILL.md
grep -c 'json headRefOid,statusCheckRollup' skills/pipeline/references/engine.md
```

Expected: `1` six times, then `0` (that last grep exits 1: the old gh call is gone; the new call's
field list wraps after `--json`, so the first grep counts the line holding the fields).

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, every file (`LockStepTest` with §The CI gate's heading unchanged and the new lines' `§`
names found).

- [ ] **Step 8: Commit**

```bash
git add skills/pipeline/references/engine.md skills/pipeline/references/manifest.md skills/pipeline/SKILL.md
git commit -m "docs(pipeline): the CI gate's conflict round and its wait on an unknown mergeability (#149)"
```
