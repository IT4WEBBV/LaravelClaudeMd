# Every run gets a proof page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `handoff` files a proof page for every run, writes merge into it, and the page opens with a
Dutch client summary (with a copy button), a plain-language explainer and the tests the PR adds, with
before/after/defect ribbons, before–after pairs, badge notes on hover and a zoom on its shots.

**Architecture:** `proof.php` gains the run's rules: the shot-state enum, the prose validation, the
merge, the short title. A new `proof_tests.php` turns a diff and a file reader into the test cases a
branch adds or changes. A new `proof_store.php` is the one filing path (`proof_store_file()`), shared by
`proof_cli.php write` and `dispatch_cli.php handoff`: locate, merge, validate, ingest shots, extract the
tests with git in the run's worktree, write, render. `proof_render.php` renders the new sections and the
shot presentation. `handoff.php` builds the page's payload; `dispatch_cli.php` files it and records
`artifacts.proof` through `record.php`'s `handoff:run` row. The two step briefs and the docs follow.

**Tech Stack:** PHP 8.3+ (no framework; no `array_any()`), Pest 4 in `skills/pipeline/checks/tests`, git in the CLI tests,
inline CSS and plain JavaScript in the rendered page.

**Spec:** `docs/superpowers/specs/2026-10-01-pipeline-every-run-gets-a-proof-page-design.md`. Read it with
this plan: the plan argues from it, and its `## Assumptions` 17–26 are the answers this plan assumed.

## Global Constraints

- Everything lives under `skills/pipeline/`. Run every command from the worktree root
  `/Users/jroelofs/GitProjects/LaravelClaudeMd/LaravelClaudeMd/.claude/worktrees/worktree-issue-141-pipeline-every-run-gets-a-proof-page-opening-with`.
- Tests: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
  (append `--filter <Name>` for one file). This repo is not a Docker project: Pest runs on the host. A fresh
  worktree has no `vendor/`: run `composer install` once first. The suite needs `node` on `PATH`.
- Test-first: every task writes its test, sees it fail, then writes the code. `php -l` every PHP file you
  change.
- **No test writes to the real store** (`~/GitProjects/_proofs`): every test that reaches filing sets
  `PIPELINE_PROOF_ROOT` to a temp dir (Task 5 makes it the `dispatch_cli()` helper's default).
- Limits: `PROOF_TITLE_MAX = 70` (unchanged), `PROOF_SUMMARY_MAX = 400`. Shot states: `before`, `after`,
  `defect`. Store schema written from now on: `2`.
- Refusal messages, verbatim:
  - `clientSummary is missing: one to three Dutch sentences for the hour registration, what the client gets, at most 400 characters`
  - `clientSummary is 431 characters, at most 400`
  - `clientSummary holds an issue or PR reference (#141): name what the client gets, in the client's words`
  - `clientSummary holds a backtick: plain words, no code`
  - `clientSummary holds the branch name feature/issue-12-logs`
  - `explainer is missing: {problem, solution}, a paragraph each for a reader who knows nothing about the issue`
  - `explainer.solution is missing`
  - `shot 2 has no state: before, after or defect`
  - `shot 2 state is "fixed": before, after or defect`
- Page copy, verbatim: `Client summary`, `Copy`, `Copied`, `Copy failed`, `In plain language`,
  `The problem`, `The solution`, `Tests this PR adds`, `This PR adds or changes no test cases.`,
  `Pending: written by the step that finishes the run.`, case tags `new` / `changed`, ribbons `Before` /
  `After` / `Defect`.
- Filing is never a halt and `handoff`'s answer stays one JSON line: a page that cannot be filed is the
  note `the proof page was not filed: <problems joined by "; ">`.
- git and gh run as argv arrays (`pipeline_git_run()`), never through a shell.
- The page still opens over `file://` with no external asset: styles and script inline.
- Native backed enums, never string constants. Guard clauses; full type hints.
- No changelog entry: the repo has no `.changelog/` and no `CHANGELOG.md`.
- Commits: no `Co-Authored-By`, no AI attribution; every message ends on `(#141)`.

## File Structure

| File | Responsibility |
|---|---|
| `checks/proof.php` (modify) | `ProofShotState`, `PROOF_SUMMARY_MAX`, `PROOF_STORE_KEYS`; `proof_validate_run()` gains states; `proof_validate_prose()`, `proof_names_branch()`, `proof_merge_run()`, `proof_short_title()`; schema 2 |
| `checks/proof_tests.php` (create) | `ProofTestChange`; `proof_added_tests()` and its helpers: pure |
| `checks/proof_store.php` (create) | `proof_store_file()`: the one filing path; shot ingest (moved from `proof_cli.php`); the git read for the tests |
| `checks/proof_cli.php` (modify) | `write` files through `proof_store_file()` with both rule sets |
| `checks/proof_render.php` (modify) | summary, explainer, tests, pending, ribbons, pairs, tooltips, zoom dialog, copy script |
| `checks/handoff.php` (modify) | `pipeline_handoff_heading()`, `pipeline_handoff_proof()`; `pipeline_handoff()` returns the page |
| `checks/dispatch_cli.php` (modify) | `dispatch_cli_handoff_page()`; `handoff` files the page and records it |
| `checks/record.php` (modify) | `handoff:run` `continued` takes an optional `--proof`; `pipeline_record_pr()` sets it |
| `checks/brief.php` (modify) | the `verify-ui:run` and `review-pr:resolve` proof lines |
| `checks/tests/Pest.php` (modify) | loads `proof_tests.php` and `proof_store.php` |
| `checks/tests/ProofTest.php`, `ProofRenderTest.php`, `ProofWriteTest.php` (modify) | the rules, the page, filing |
| `checks/tests/ProofAddedTestsTest.php` (create) | the test extraction |
| `checks/tests/HandoffTest.php`, `HandoffCliTest.php`, `RecordTest.php`, `DispatchCliTest.php`, `BriefTest.php`, `LockStepTest.php` (modify) | handoff's page, the record flag, the proof root default, the brief lines, the doc lock-step |
| `references/engine.md`, `references/manifest.md`, `SKILL.md` (modify) | every run has a page; the payload table |

## Review Focus

Inputs the spec implies and its test list does not name, most likely first. Each has its test in the task
that owns the code.

1. **A short branch topic inside ordinary Dutch words** (`feature/ui` and *gebruiker*, *uitslag*). Expected:
   no refusal; only the topic as a word of its own refuses. Test in Task 1 (the `feature/ui` case).
2. **A run filed before this change, finalised after it** (its stored shots have no `state`). Expected: the
   finish write is refused naming the shot, and passes once the re-sent shots carry states; the page then
   renders the new sections. Test in Task 3.
3. **A store root `handoff` cannot write.** Expected: `continued` is recorded without `artifacts.proof`, the
   answer is one JSON line, and `notes` holds `the proof page was not filed: cannot create …`. Test in Task 5.
4. **A Pest case with no `})` at its own indentation** (an arrow-function one-liner) followed by another
   case. Expected: its range ends before the next case, so a change in the next case is not credited to it.
   Test in Task 2.
5. **Markup and quotes in the summary, the explainer, a test name, a file path.** Expected: escaped on the
   page; the copy button copies the text as written (it reads `textContent`). Test in Task 4.

---

### Task 1: The run's rules in `proof.php`

**Files:**
- Modify: `skills/pipeline/checks/proof.php`
- Test: `skills/pipeline/checks/tests/ProofTest.php`

**Interfaces:**
- Produces: `enum ProofShotState: string` (`Before`, `After`, `Defect`) with `label(): string` and
  `static named(): string` (`before, after or defect`); `const PROOF_SUMMARY_MAX = 400`;
  `const PROOF_STORE_KEYS = ['addedTests', 'schema', 'createdAt', 'updatedAt', 'shotSources']`;
  `proof_validate_run(array $run): array` (list of problems, now with states);
  `proof_validate_prose(array $run): array`; `proof_names_branch(string $text, string $branch): bool`;
  `proof_merge_run(array $stored, array $payload, array $defaults = []): array`;
  `proof_short_title(string $title): string`; `proof_write_run()` writes `schema: 2`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/ProofTest.php`, and update the two existing tests named below.

```php
/** A run an agent's `write` may file: title, branch, prose, one shot with a state. */
function proof_prose_run(array $overrides = []): array
{
    return [
        'title' => 'PR #12: logs that follow',
        'branch' => 'feature/issue-12-logs',
        'clientSummary' => 'De servicelogboeken lopen nu live mee, zodat een storing direct zichtbaar is.',
        'explainer' => [
            'problem' => 'The service log stopped at the last line it had.',
            'solution' => 'The log now keeps following as new lines arrive.',
        ],
        'shots' => [['title' => 'Following log', 'state' => 'after']],
        ...$overrides,
    ];
}

/** Both rule sets, as `proof_cli.php write` applies them. */
function proof_all_problems(array $run): array
{
    return [...proof_validate_run($run), ...proof_validate_prose($run)];
}

it('passes a run with a valid summary, explainer and shot states', function () {
    expect(proof_all_problems(proof_prose_run()))->toBe([]);
});

it('refuses each broken rule of the prose and the shots with its own message', function (array $overrides, string $message) {
    $run = proof_prose_run($overrides);

    expect(proof_all_problems(array_filter($run, fn ($value) => $value !== null)))->toBe([$message]);
})->with([
    'no summary' => [['clientSummary' => null], 'clientSummary is missing: one to three Dutch sentences for the hour registration, what the client gets, at most 400 characters'],
    'a blank summary' => [['clientSummary' => "  \n"], 'clientSummary is missing: one to three Dutch sentences for the hour registration, what the client gets, at most 400 characters'],
    'a long summary' => [['clientSummary' => str_repeat('a', 431)], 'clientSummary is 431 characters, at most 400'],
    'an issue reference' => [['clientSummary' => 'Opgelost in #141, de logs lopen mee.'], "clientSummary holds an issue or PR reference (#141): name what the client gets, in the client's words"],
    'a backtick' => [['clientSummary' => 'De `tail` volgt nu het logboek.'], 'clientSummary holds a backtick: plain words, no code'],
    'the whole branch' => [['clientSummary' => 'Gebouwd op feature/issue-12-logs.'], 'clientSummary holds the branch name feature/issue-12-logs'],
    'the branch topic, in capitals' => [['clientSummary' => 'Zie ISSUE-12-LOGS voor de details.'], 'clientSummary holds the branch name feature/issue-12-logs'],
    'no explainer' => [['explainer' => null], 'explainer is missing: {problem, solution}, a paragraph each for a reader who knows nothing about the issue'],
    'an explainer that is text' => [['explainer' => 'The log follows now.'], 'explainer is missing: {problem, solution}, a paragraph each for a reader who knows nothing about the issue'],
    'an empty solution' => [['explainer' => ['problem' => 'It stopped.', 'solution' => ' ']], 'explainer.solution is missing'],
    'a shot without a state' => [['shots' => [['title' => 'Log', 'state' => 'after'], ['title' => 'Header']]], 'shot 2 has no state: before, after or defect'],
    'a shot with another state' => [['shots' => [['title' => 'Log', 'state' => 'after'], ['title' => 'Header', 'state' => 'fixed']]], 'shot 2 state is "fixed": before, after or defect'],
]);

it('matches the branch topic as a word of its own, so a short topic does not refuse ordinary Dutch', function () {
    $run = proof_prose_run(['branch' => 'feature/ui', 'clientSummary' => 'De gebruiker ziet de uitslag nu direct.']);

    expect(proof_all_problems($run))->toBe([]);
    expect(proof_names_branch('De nieuwe ui staat klaar.', 'feature/ui'))->toBeTrue();
    expect(proof_names_branch('Alles werkt.', 'main'))->toBeFalse();
});

it('labels each shot state and names them for a refusal', function () {
    expect(array_map(fn (ProofShotState $state) => $state->label(), ProofShotState::cases()))->toBe(['Before', 'After', 'Defect']);
    expect(ProofShotState::named())->toBe('before, after or defect');
});

it('merges a payload over the stored run key by key, replacing a list whole and keeping what it leaves out', function () {
    $stored = ['title' => 'PR #7: x', 'headline' => 'H', 'openQuestions' => ['a', 'b'], 'schema' => 2, 'createdAt' => '2026-10-01T10:00:00+02:00', 'addedTests' => [['file' => 'tests/XTest.php', 'cases' => []]]];
    $payload = ['openQuestions' => ['c'], 'schema' => 9, 'createdAt' => 'never', 'updatedAt' => 'never', 'addedTests' => [], 'shotSources' => ['/tmp/a.png']];

    expect(proof_merge_run($stored, $payload))->toBe([
        'title' => 'PR #7: x', 'headline' => 'H', 'openQuestions' => ['c'], 'schema' => 2,
        'createdAt' => '2026-10-01T10:00:00+02:00', 'addedTests' => [['file' => 'tests/XTest.php', 'cases' => []]],
    ]);
    expect(proof_merge_run($stored, ['openQuestions' => []])['openQuestions'])->toBe([]);
});

it('fills a default only where the stored run lacks the key', function () {
    expect(proof_merge_run([], ['pr' => 7], ['title' => 'PR #7: x']))->toBe(['title' => 'PR #7: x', 'pr' => 7]);
    expect(proof_merge_run(['title' => 'PR #7: logs that follow'], ['pr' => 7], ['title' => 'PR #7: x'])['title'])->toBe('PR #7: logs that follow');
});

it('shortens a title over 70 characters at a word boundary, and leaves one at or under 70 alone', function () {
    $at = str_repeat('a', PROOF_TITLE_MAX);
    expect(proof_short_title($at))->toBe($at);
    expect(proof_short_title('  PR #7: short  '))->toBe('PR #7: short');

    $short = proof_short_title('PR #141: Every run gets a proof page, opening with a Dutch client summary and a plain-language explainer');
    expect(mb_strlen($short))->toBeLessThanOrEqual(PROOF_TITLE_MAX);
    expect($short)->toBe('PR #141: Every run gets a proof page, opening with a Dutch client…');
    expect(mb_strlen(proof_short_title(str_repeat('é', 90))))->toBe(PROOF_TITLE_MAX);
});
```

Update the existing tests:

- *round-trips a run and preserves createdAt across the second write*: `expect($first['schema'])->toBe(1);`
  becomes `expect($first['schema'])->toBe(2);`.
- *accepts a run named by a short title, whatever the length of its summary and captions*: the shot gains
  `'state' => 'after'`.
- *rejects a shot title that belongs in its caption*: both shots gain `'state' => 'after'`.

- [ ] **Step 2: Run the tests to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter ProofTest`
Expected: FAIL: `Call to undefined function proof_validate_prose()`, `Class "ProofShotState" not found`, and
the schema assertion (`1` is not `2`).

- [ ] **Step 3: Write the code**

In `proof.php`, replace the file docblock's first line `The durable visual proof store (\`../references/engine.md\` §verify-ui).`
with `The durable proof store (\`../references/engine.md\` §The proof store): the run's rules, its directory, its file.`

Below `const PROOF_TITLE_MAX = 70;` add:

```php
/** The longest a run's `clientSummary` may be: one to three sentences for an hour registration. */
const PROOF_SUMMARY_MAX = 400;

/** The keys the store owns. A payload's values for them are ignored, and `shotSources` is consumed, never stored. */
const PROOF_STORE_KEYS = ['addedTests', 'schema', 'createdAt', 'updatedAt', 'shotSources'];

/** What a shot shows, set by the step that captured it: the ribbon on the shot. */
enum ProofShotState: string
{
    case Before = 'before';
    case After = 'after';
    case Defect = 'defect';

    public function label(): string
    {
        return match ($this) {
            self::Before => 'Before',
            self::After => 'After',
            self::Defect => 'Defect',
        };
    }

    /** `before, after or defect`: the states as a refusal names them. */
    public static function named(): string
    {
        $values = array_column(self::cases(), 'value');

        return implode(', ', array_slice($values, 0, -1)) . ' or ' . end($values);
    }
}
```

In `proof_validate_run()`, replace the shots loop with:

```php
    foreach (array_values($run['shots'] ?? []) as $i => $shot) {
        $number = $i + 1;
        $length = mb_strlen(trim((string) ($shot['title'] ?? '')));
        if ($length > PROOF_TITLE_MAX) {
            $problems[] = "shot {$number} title is {$length} characters, at most " . PROOF_TITLE_MAX . ': move the detail to caption';
        }
        $state = proof_shot_state_problem($number, $shot['state'] ?? null);
        if ($state !== null) {
            $problems[] = $state;
        }
    }
```

and extend its docblock with one sentence: `Every filed run obeys these, the page \`handoff\` files included.`

Below `proof_validate_run()` add:

```php
function proof_shot_state_problem(int $number, mixed $state): ?string
{
    return match (true) {
        $state === null => "shot {$number} has no state: " . ProofShotState::named(),
        ! is_string($state) || ProofShotState::tryFrom($state) === null
            => "shot {$number} state is " . json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ': ' . ProofShotState::named(),
        default => null,
    };
}

/**
 * What an agent's `write` must leave on the page, beside `proof_validate_run()`: the Dutch client summary and the
 * plain-language explainer. Judged on the run as it will be filed, so a write that leaves them out passes when an
 * earlier write filed them. The page `handoff` files is the one place they may be missing.
 *
 * @return list<string>
 */
function proof_validate_prose(array $run): array
{
    return [...proof_summary_problems($run), ...proof_explainer_problems($run['explainer'] ?? null)];
}

/** @return list<string> */
function proof_summary_problems(array $run): array
{
    $summary = trim((string) ($run['clientSummary'] ?? ''));
    if ($summary === '') {
        return ['clientSummary is missing: one to three Dutch sentences for the hour registration, what the client gets, at most ' . PROOF_SUMMARY_MAX . ' characters'];
    }
    $branch = (string) ($run['branch'] ?? '');
    $length = mb_strlen($summary);
    $reference = preg_match('/#\d+/', $summary, $match) === 1 ? $match[0] : null;

    return array_values(array_filter([
        $length > PROOF_SUMMARY_MAX ? "clientSummary is {$length} characters, at most " . PROOF_SUMMARY_MAX : null,
        $reference === null ? null : "clientSummary holds an issue or PR reference ({$reference}): name what the client gets, in the client's words",
        str_contains($summary, '`') ? 'clientSummary holds a backtick: plain words, no code' : null,
        proof_names_branch($summary, $branch) ? "clientSummary holds the branch name {$branch}" : null,
    ]));
}

/** @return list<string> */
function proof_explainer_problems(mixed $explainer): array
{
    if (! is_array($explainer)) {
        return ['explainer is missing: {problem, solution}, a paragraph each for a reader who knows nothing about the issue'];
    }
    $missing = array_filter(['problem', 'solution'], fn (string $key) => ! is_string($explainer[$key] ?? null) || trim($explainer[$key]) === '');

    return array_values(array_map(fn (string $key) => "explainer.{$key} is missing", $missing));
}

/**
 * Whether `$text` names the branch: whole, or the part after its first `/`, case-insensitive, as a word of its
 * own. Inside a word it does not count: a topic like `ui` would otherwise refuse every *gebruiker*.
 */
function proof_names_branch(string $text, string $branch): bool
{
    $separator = strpos($branch, '/');
    $names = array_filter([$branch, $separator === false ? '' : substr($branch, $separator + 1)], fn (string $name) => $name !== '');

    $named = array_filter($names, fn (string $name) => preg_match('/(?<![\p{L}\p{N}])' . preg_quote($name, '/') . '(?![\p{L}\p{N}])/iu', $text) === 1);

    return $named !== [];
}

/**
 * The run as it will be filed: the stored run with the payload over it, key by key at the top level. A key the
 * payload carries replaces the stored one whole (a list is replaced, never appended to); a key it leaves out is
 * kept. `$defaults` fill only keys the stored run lacks, so `handoff`'s title never replaces one a step wrote.
 * The store's own keys (`PROOF_STORE_KEYS`) are never taken from a payload or a default.
 */
function proof_merge_run(array $stored, array $payload, array $defaults = []): array
{
    $owned = array_flip(PROOF_STORE_KEYS);

    return [...array_diff_key($defaults, $owned), ...$stored, ...array_diff_key($payload, $owned)];
}

/** At most `PROOF_TITLE_MAX` characters: cut at the last word boundary that fits, with `…`. */
function proof_short_title(string $title): string
{
    $title = trim($title);
    if (mb_strlen($title) <= PROOF_TITLE_MAX) {
        return $title;
    }
    $cut = mb_substr($title, 0, PROOF_TITLE_MAX - 1);
    $space = mb_strrpos($cut, ' ');
    $words = $space === false ? $cut : mb_substr($cut, 0, $space);

    return preg_replace('/[\s,;:—-]+$/u', '', $words) . '…';
}
```

The shortening test's expected value, counted: the first 69 characters of that title are
`PR #141: Every run gets a proof page, opening with a Dutch client sum`, whose last space is at offset 65,
so the result is `PR #141: Every run gets a proof page, opening with a Dutch client` plus `…` (66 characters).

In `proof_write_run()`: `$run['schema'] = 1;` becomes `$run['schema'] = 2;`, and its docblock becomes:

```php
/**
 * Write `run.json` as schema 2, preserving `createdAt`. The run is already merged (`proof_merge_run()`): three
 * points write it, `handoff` files the page, `verify-ui` adds the shots, the finish step finalises it.
 *
 * `$now` is a parameter rather than a call to `time()` so the round-trip is testable without
 * a clock and a run's timestamps can be made to match the leg that produced them.
 *
 * @return array the run as written, including the fields this function fills in
 */
```

In `proof_open_argv()`'s docblock, the line `**no page** — a backend-only run never triggers \`verify-ui\` and has none;`
becomes `**no page** — a run that halted before \`handoff\` has none;`.

- [ ] **Step 4: Run the tests to see them pass**

Run: `php -l skills/pipeline/checks/proof.php && ./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter ProofTest`
Expected: PASS. (`ProofWriteTest` and `ProofRenderTest` are made green by Tasks 3 and 4.)

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/proof.php skills/pipeline/checks/tests/ProofTest.php
git commit -m "feat(pipeline): proof runs merge, carry shot states and a validated client summary and explainer (#141)"
```

---

### Task 2: The tests a branch adds, `proof_tests.php`

**Files:**
- Create: `skills/pipeline/checks/proof_tests.php`
- Modify: `skills/pipeline/checks/tests/Pest.php`
- Test: `skills/pipeline/checks/tests/ProofAddedTestsTest.php` (create)

**Interfaces:**
- Consumes: `parse_diff(string $diff): array` from `skills/critique/checks/diff_parse.php` (each file
  `{file, old, added: list<{line, text}>, removed}`; a deleted file's `file` is `/dev/null`).
- Produces: `enum ProofTestChange: string` (`Added = 'added'`, `Changed = 'changed'`) with `label(): string`
  (`new`, `changed`); `proof_added_tests(string $diff, callable $source): array` returning
  `list<array{file: string, cases: list<array{name: string, change: string}>}>`, `$source(string $path): ?string`
  the file at `HEAD`.

- [ ] **Step 1: Write the failing tests**

In `tests/Pest.php`, add `'proof_tests.php'` to the file list right after `'proof_render.php'`.

Create `tests/ProofAddedTestsTest.php`:

```php
<?php

/** A diff of one file that is `$content` at HEAD: lines numbered in `$added` are `+`, the rest context. */
function added_tests_diff(string $path, string $content, array $added): string
{
    $lines = explode("\n", $content);
    $body = array_map(fn (string $line, int $i) => (in_array($i + 1, $added, true) ? '+' : ' ') . $line, $lines, array_keys($lines));

    return "--- a/{$path}\n+++ b/{$path}\n@@ -1," . (count($lines) - count($added)) . ' +1,' . count($lines) . " @@\n" . implode("\n", $body) . "\n";
}

/** `proof_added_tests()` over one file whose content the reader returns. */
function added_tests(string $path, string $content, array $added): array
{
    return proof_added_tests(added_tests_diff($path, $content, $added), fn (string $asked) => $asked === $path ? $content : null);
}

const ADDED_TESTS_PEST = <<<'PHP'
<?php

it('follows the log', function () {
    expect(true)->toBeTrue();
});

test('stops at "eof"', function () {
    expect(1)->toBe(1);
});

it('can\'t lose a line', function () {
    expect(2)->toBe(2);
});
PHP;

it('lists a Pest case it adds as new and one it changes as changed, by its unescaped description', function () {
    expect(added_tests('tests/Feature/LogsTest.php', ADDED_TESTS_PEST, [3, 4, 5, 8, 11, 12, 13]))->toBe([[
        'file' => 'tests/Feature/LogsTest.php',
        'cases' => [
            ['name' => 'follows the log', 'change' => 'added'],
            ['name' => 'stops at "eof"', 'change' => 'changed'],
            ['name' => "can't lose a line", 'change' => 'added'],
        ],
    ]]);
});

it('lists a PHPUnit test method and a #[Test] method, and not a helper method changed beside them', function () {
    $content = <<<'PHP'
<?php

class LogsTest extends TestCase
{
    public function test_stops_at_eof(): void
    {
        $this->assertTrue(true);
    }

    #[Test]
    public function it_follows(): void
    {
        $this->assertTrue(true);
    }

    private function helper(): void
    {
        // nothing
    }
}
PHP;

    expect(added_tests('tests/Unit/LogsTest.php', $content, [7, 10, 11, 12, 13, 14, 18]))->toBe([[
        'file' => 'tests/Unit/LogsTest.php',
        'cases' => [
            ['name' => 'test_stops_at_eof', 'change' => 'changed'],
            ['name' => 'it_follows', 'change' => 'added'],
        ],
    ]]);
});

it('leaves out a file whose added lines fall outside every case', function () {
    $content = "<?php\n\nuse App\\Logs;\n\nit('follows the log', function () {\n    expect(true)->toBeTrue();\n});";

    expect(added_tests('tests/Feature/LogsTest.php', $content, [3]))->toBe([]);
});

it('ends a case without a closing line before the next one, so the next case\'s change is not credited to it', function () {
    $content = "<?php\n\nit('is quick', fn () => expect(true)->toBeTrue());\n\nit('is thorough', function () {\n    expect(1)->toBe(1);\n});";

    expect(added_tests('tests/Feature/SpeedTest.php', $content, [6]))->toBe([[
        'file' => 'tests/Feature/SpeedTest.php',
        'cases' => [['name' => 'is thorough', 'change' => 'changed']],
    ]]);
});

it('reads only PHP test files: not a PHP file outside tests, not a JavaScript test, not a deleted file', function () {
    $php = "<?php\n\nfunction test_helper(): void\n{\n}";
    $js = "it('follows the log', () => {\n  expect(true).toBe(true)\n})";
    $deleted = "--- a/tests/OldTest.php\n+++ /dev/null\n@@ -1,2 +0,0 @@\n-<?php\n-it('old', fn () => true);\n";

    expect(added_tests('app/Support/Helpers.php', $php, [3, 4, 5]))->toBe([]);
    expect(added_tests('tests/js/logs.test.js', $js, [1, 2, 3]))->toBe([]);
    expect(proof_added_tests($deleted, fn () => throw new RuntimeException('a deleted file is never read')))->toBe([]);
});

it('counts a file named *Test.php outside a tests directory, and skips a file the reader cannot give', function () {
    $content = "<?php\n\nit('works', function () {\n    expect(true)->toBeTrue();\n});";

    expect(added_tests('packages/logs/LogsTest.php', $content, [3, 4, 5])[0]['cases'])->toBe([['name' => 'works', 'change' => 'added']]);
    expect(proof_added_tests(added_tests_diff('tests/GoneTest.php', $content, [3]), fn () => null))->toBe([]);
});

it('tags an added case new and a changed one changed', function () {
    expect(ProofTestChange::Added->label())->toBe('new');
    expect(ProofTestChange::Changed->label())->toBe('changed');
});
```

Line numbers in the first test: `ADDED_TESTS_PEST` is line 1 `<?php`, 2 blank, 3–5 the first case, 6 blank,
7–9 the second (line 8 changed, declaration 7 not added), 10 blank, 11–13 the third.

- [ ] **Step 2: Run the tests to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter ProofAddedTestsTest`
Expected: FAIL: `Call to undefined function proof_added_tests()`.

- [ ] **Step 3: Write the code**

Create `skills/pipeline/checks/proof_tests.php`:

```php
<?php

/**
 * The test cases a branch adds or changes, for the proof page's *Tests this PR adds* (`../references/engine.md`
 * §The proof store). Pure: the diff and a reader of a file at `HEAD` come in, the cases go out. A change that only
 * removes lines inside a case is not seen, nor a `describe()` prefix, nor a dataset's rows.
 */

require_once __DIR__ . '/../../critique/checks/diff_parse.php';

/** How the branch touches a case; its label is the tag on the page. */
enum ProofTestChange: string
{
    case Added = 'added';
    case Changed = 'changed';

    public function label(): string
    {
        return match ($this) {
            self::Added => 'new',
            self::Changed => 'changed',
        };
    }
}

/**
 * Per test file in the diff, in diff order, the cases whose declaration line is added (`added`) or that hold
 * another added line (`changed`). A file with no such case is left out.
 *
 * @param callable(string): ?string $source the file's content at `HEAD`, or null
 * @return list<array{file: string, cases: list<array{name: string, change: string}>}>
 */
function proof_added_tests(string $diff, callable $source): array
{
    $files = [];
    foreach (parse_diff($diff) as $file) {
        if ($file['file'] === '/dev/null' || $file['added'] === [] || ! proof_is_test_file($file['file'])) {
            continue;
        }
        $content = $source($file['file']);
        $cases = $content === null ? [] : proof_touched_cases(proof_test_cases($content), array_column($file['added'], 'line'));
        if ($cases !== []) {
            $files[] = ['file' => $file['file'], 'cases' => $cases];
        }
    }

    return $files;
}

/** A PHP file under a `tests/` directory at any depth, or one named `*Test.php`. */
function proof_is_test_file(string $path): bool
{
    return str_ends_with($path, '.php')
        && (preg_match('#(^|/)tests/#', $path) === 1 || str_ends_with($path, 'Test.php'));
}

/**
 * Every case the file declares, in file order: Pest's `it(` or `test(` with a quoted description, a method
 * `function test…(`, a method after `#[Test]`. A case runs from its first line (the attribute's, for `#[Test]`)
 * to the first later line that closes it at the declaration's own indentation (`})` for Pest, `}` for a method),
 * else to the line before the next case, else to the end of the file.
 *
 * @return list<array{name: string, declared: int, start: int, end: int}>
 */
function proof_test_cases(string $content): array
{
    $lines = explode("\n", $content);
    $found = [];
    $attribute = null;
    foreach ($lines as $index => $line) {
        $number = $index + 1;
        if (preg_match('/^(\s*)(?:it|test)\(\s*([\'"])((?:\\\\.|(?!\2).)*)\2/', $line, $pest) === 1) {
            $found[] = ['name' => proof_unquote($pest[3], $pest[2]), 'declared' => $number, 'start' => $number, 'close' => $pest[1] . '})'];

            continue;
        }
        if (preg_match('/^\s*#\[\\\\?(?:PHPUnit\\\\Framework\\\\Attributes\\\\)?Test\b/', $line) === 1) {
            $attribute = $number;

            continue;
        }
        if (preg_match('/^(\s*)(?:(?:public|protected|private|static|final|abstract)\s+)*function\s+(\w+)\s*\(/', $line, $method) === 1) {
            if ($attribute !== null || str_starts_with($method[2], 'test')) {
                $found[] = ['name' => $method[2], 'declared' => $number, 'start' => $attribute ?? $number, 'close' => $method[1] . '}'];
            }
            $attribute = null;
        }
    }

    return array_map(fn (array $case, int $i) => [
        'name' => $case['name'],
        'declared' => $case['declared'],
        'start' => $case['start'],
        'end' => proof_case_end($lines, $case, ($found[$i + 1]['start'] ?? count($lines) + 1) - 1),
    ], $found, array_keys($found));
}

/** The first line after the declaration that starts with the case's closing token at its indentation, else `$limit`. */
function proof_case_end(array $lines, array $case, int $limit): int
{
    for ($number = $case['declared'] + 1; $number <= $limit; $number++) {
        if (str_starts_with($lines[$number - 1], $case['close'])) {
            return $number;
        }
    }

    return $limit;
}

/** A quoted description as PHP reads it. */
function proof_unquote(string $text, string $quote): string
{
    return $quote === "'" ? strtr($text, ['\\\\' => '\\', "\\'" => "'"]) : stripcslashes($text);
}

/**
 * @param list<array{name: string, declared: int, start: int, end: int}> $cases
 * @param list<int> $addedLines
 * @return list<array{name: string, change: string}>
 */
function proof_touched_cases(array $cases, array $addedLines): array
{
    $added = array_flip($addedLines);
    $touched = [];
    foreach ($cases as $case) {
        $change = match (true) {
            isset($added[$case['declared']]) => ProofTestChange::Added,
            array_filter(range($case['start'], $case['end']), fn (int $line) => isset($added[$line])) !== [] => ProofTestChange::Changed,
            default => null,
        };
        if ($change !== null) {
            $touched[] = ['name' => $case['name'], 'change' => $change->value];
        }
    }

    return $touched;
}
```

- [ ] **Step 4: Run the tests to see them pass**

Run: `php -l skills/pipeline/checks/proof_tests.php && ./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter ProofAddedTestsTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/proof_tests.php skills/pipeline/checks/tests/ProofAddedTestsTest.php skills/pipeline/checks/tests/Pest.php
git commit -m "feat(pipeline): the test cases a branch adds or changes, read from its diff (#141)"
```

---

### Task 3: One filing path, `proof_store.php`, and `write` through it

**Files:**
- Create: `skills/pipeline/checks/proof_store.php`
- Modify: `skills/pipeline/checks/proof_cli.php`, `skills/pipeline/checks/tests/Pest.php`
- Test: `skills/pipeline/checks/tests/ProofWriteTest.php`

**Interfaces:**
- Consumes: Task 1's `proof_merge_run()`, `proof_validate_run()`, `proof_validate_prose()`, `proof_write_run()`;
  Task 2's `proof_added_tests()`; `pipeline_git_run(string $worktree, array $args): array{0: int, 1: string, 2: string}`
  (`suite.php`, output trimmed); `proof_render_run()`, `proof_render_index()`, `proof_scan_runs()`.
- Produces: `proof_store_file(array $payload, string $now, callable $rules, array $defaults = []): array{page: ?string, problems: list<string>}`
  (`$rules(array $run): list<string>`); `proof_store_dirs(string $root, array $run): array{0: string, 1: string}`;
  `proof_store_shots()`, `proof_store_ingest_shot()`, `proof_store_added_tests(array $run): array|string`.

- [ ] **Step 1: Write the failing tests**

In `tests/Pest.php`, add `'proof_store.php'` to the file list right after `'proof_tests.php'`.

In `tests/ProofWriteTest.php`, update the first test's payload to a valid one, and append the new tests:

```php
/** A payload an agent's `write` may file into `Deploy/pr-5-logs`. */
function proof_write_payload(array $overrides = []): array
{
    return [
        'repo' => 'Deploy', 'branch' => 'feature/logs', 'pr' => 5, 'title' => 'PR #5: logs that follow',
        'clientSummary' => 'De servicelogboeken lopen nu live mee.',
        'explainer' => ['problem' => 'The log stopped at its last line.', 'solution' => 'It keeps following now.'],
        ...$overrides,
    ];
}

function proof_write_stored(string $root): array
{
    return json_decode((string) file_get_contents("{$root}/Deploy/pr-5-logs/run.json"), true);
}
```

The first test (*files a run named by a short title and prints the page path*) calls
`proof_write_cli(proof_write_payload(), $root)`. The second (*files nothing and says why when the title is a
summary*) calls `proof_write_cli(proof_write_payload(['title' => str_repeat('verify-ui passed and everything it checked, ', 10)]), $root)`.

```php
it('keeps what an earlier write filed when a later one leaves it out', function () {
    $root = sys_get_temp_dir() . '/proof-write-' . uniqid();
    proof_write_cli(proof_write_payload(['headline' => 'Logs follow', 'openQuestions' => ['a', 'b']]), $root);

    $result = proof_write_cli(['repo' => 'Deploy', 'branch' => 'feature/logs', 'pr' => 5, 'openQuestions' => ['c']], $root);

    expect($result['stdout'])->toContain("{$root}/Deploy/pr-5-logs/index.html");
    expect(proof_write_stored($root))->toMatchArray([
        'title' => 'PR #5: logs that follow', 'headline' => 'Logs follow', 'openQuestions' => ['c'],
        'clientSummary' => 'De servicelogboeken lopen nu live mee.', 'schema' => 2,
    ]);
});

it('files nothing for a run without a client summary, and says so', function () {
    // The root exists, as in the title test: the prune pass after `write` writes the store index into it
    // and prints its count on stdout.
    $root = sys_get_temp_dir() . '/proof-write-' . uniqid();
    mkdir($root);

    $result = proof_write_cli(proof_write_payload(['clientSummary' => null]), $root);

    expect($result['stdout'])->not->toContain("{$root}/Deploy/");
    expect($result['stderr'])->toContain('proof: payload rejected')->toContain('clientSummary is missing');
    expect(is_dir("{$root}/Deploy"))->toBeFalse();
});

it('stores the tests the run\'s branch adds, from git in its worktree, and ignores a payload\'s own list', function () {
    $root = sys_get_temp_dir() . '/proof-write-' . uniqid();
    $worktree = base_repo();
    mkdir("{$worktree}/tests/Feature", 0777, true);
    rereview_commit($worktree, ['tests/Feature/LogsTest.php' => "<?php\n\nit('follows the log', function () {\n    expect(true)->toBeTrue();\n});\n"]);

    proof_write_cli(proof_write_payload(['worktree' => $worktree, 'base' => 'main', 'addedTests' => [['file' => 'invented.php', 'cases' => []]]]), $root);

    expect(proof_write_stored($root)['addedTests'])->toBe([[
        'file' => 'tests/Feature/LogsTest.php',
        'cases' => [['name' => 'follows the log', 'change' => 'added']],
    ]]);
});

it('keeps the stored test list and says why when the worktree is gone', function () {
    $root = sys_get_temp_dir() . '/proof-write-' . uniqid();
    $worktree = base_repo();
    mkdir("{$worktree}/tests", 0777, true);
    rereview_commit($worktree, ['tests/LogsTest.php' => "<?php\n\nit('follows', function () {\n});\n"]);
    proof_write_cli(proof_write_payload(['worktree' => $worktree, 'base' => 'main']), $root);

    $result = proof_write_cli(proof_write_payload(['worktree' => "{$worktree}-removed"]), $root);

    expect($result['stderr'])->toContain('proof: tests not extracted: no worktree at');
    expect(proof_write_stored($root)['addedTests'][0]['file'])->toBe('tests/LogsTest.php');
});

it('keeps a carried shot\'s file next to a newly ingested one, named by its content', function () {
    $root = sys_get_temp_dir() . '/proof-write-' . uniqid();
    mkdir($root);
    file_put_contents("{$root}-one.png", 'first screenshot');
    file_put_contents("{$root}-two.png", 'second screenshot');
    $defect = ['title' => 'Log stops', 'route' => '/logs', 'state' => 'defect'];
    proof_write_cli(proof_write_payload(['shots' => [$defect], 'shotSources' => ["{$root}-one.png"]]), $root);
    $carried = proof_write_stored($root)['shots'][0]['file'];

    proof_write_cli(proof_write_payload([
        'shots' => [[...$defect, 'file' => $carried], ['title' => 'Log follows', 'route' => '/logs', 'state' => 'after']],
        'shotSources' => [null, "{$root}-two.png"],
    ]), $root);

    $shots = proof_write_stored($root)['shots'];
    expect($carried)->toBe('shots/01-logs-' . substr(sha1('first screenshot'), 0, 8) . '.png');
    expect($shots[0]['file'])->toBe($carried);
    expect($shots[1]['file'])->toBe('shots/02-logs-' . substr(sha1('second screenshot'), 0, 8) . '.png');
    expect(file_get_contents("{$root}/Deploy/pr-5-logs/{$carried}"))->toBe('first screenshot');
    expect(array_key_exists('shotSources', proof_write_stored($root)))->toBeFalse();
});

it('refuses to finalise a run filed before shot states until its shots carry them, then renders the new page', function () {
    $root = sys_get_temp_dir() . '/proof-write-' . uniqid();
    mkdir("{$root}/Deploy/pr-5-logs", 0777, true);
    file_put_contents("{$root}/Deploy/pr-5-logs/run.json", json_encode([
        'repo' => 'Deploy', 'branch' => 'feature/logs', 'pr' => 5, 'title' => 'PR #5: logs that follow', 'schema' => 1,
        'shots' => [['title' => 'Log follows', 'route' => '/logs', 'file' => 'shots/01-logs.png']],
    ]));

    $refused = proof_write_cli(proof_write_payload(), $root);
    expect($refused['stderr'])->toContain('shot 1 has no state: before, after or defect');

    $filed = proof_write_cli(proof_write_payload(['shots' => [['title' => 'Log follows', 'route' => '/logs', 'file' => 'shots/01-logs.png', 'state' => 'after']]]), $root);
    expect($filed['stdout'])->toContain('index.html');
    expect(file_get_contents("{$root}/Deploy/pr-5-logs/index.html"))->toContain('id="client-summary"')->toContain('ribbon-after');
});
```

(The last test's `id="client-summary"` and `ribbon-after` assertions pass once Task 4 renders them; run it
again after Task 4. Mark it with `->todo()` only if you take Task 4 out of order.)

- [ ] **Step 2: Run the tests to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter ProofWriteTest`
Expected: FAIL: the second write replaces the first (`headline` missing), no `addedTests`, a shot named
`01-logs.png`, and the prose refusal absent.

- [ ] **Step 3: Write the code**

Create `skills/pipeline/checks/proof_store.php`:

```php
<?php

/**
 * Filing a run (`../references/engine.md` §The proof store): the one path by which `proof_cli.php write` and
 * `dispatch_cli.php handoff` put a run into the store. A write is merged over the run as filed, never replaces
 * it. Impure: the filesystem, `sips`, and git in the run's worktree. Never a halt: what cannot be filed comes
 * back as problems.
 */

require_once __DIR__ . '/proof.php';
require_once __DIR__ . '/proof_render.php';
require_once __DIR__ . '/proof_tests.php';
require_once __DIR__ . '/suite.php';

/**
 * Files `$payload` merged over the stored run when the merged run passes `$rules`: nothing is written otherwise.
 * Then the shots are ingested, `addedTests` extracted, `run.json` written and the page and the store index
 * rendered. It never prunes.
 *
 * @param callable(array): list<string> $rules
 * @param array $defaults keys filled only where the stored run lacks them (`proof_merge_run()`)
 * @return array{page: ?string, problems: list<string>}
 */
function proof_store_file(array $payload, string $now, callable $rules, array $defaults = []): array
{
    $root = proof_root();
    [$dir, $home] = proof_store_dirs($root, $payload);
    $run = proof_merge_run(proof_read_run($home) ?? [], $payload, $defaults);
    $problems = $rules($run);
    if ($problems !== []) {
        return ['page' => null, 'problems' => $problems];
    }
    if ($home !== $dir) {
        @rename($home, $dir);
    }
    if (! is_dir("{$dir}/shots") && ! @mkdir("{$dir}/shots", 0777, true) && ! is_dir("{$dir}/shots")) {
        return ['page' => null, 'problems' => ["cannot create {$dir}/shots"]];
    }
    $run = proof_store_shots($dir, $run, array_values($payload['shotSources'] ?? []));
    $tests = proof_store_added_tests($run);
    if (is_string($tests)) {
        fwrite(STDERR, "proof: tests not extracted: {$tests}\n");
    } else {
        $run['addedTests'] = $tests;
    }
    $run = proof_write_run($dir, $run, $now);
    file_put_contents("{$dir}/index.html", proof_render_run($run));
    file_put_contents("{$root}/index.html", proof_render_index(proof_scan_runs($root)));

    return ['page' => "{$dir}/index.html", 'problems' => []];
}

/**
 * Where the run goes and where it is now. A run filed before its directory was keyed by PR, or by a write that
 * beat the PR into existence, still lives under its branch slug: filing adopts that directory rather than
 * starting an empty one beside it, which would orphan its shots and show the run twice in the index.
 *
 * @return array{0: string, 1: string} the PR-keyed directory, and the one the stored run is in
 */
function proof_store_dirs(string $root, array $run): array
{
    $repo = (string) ($run['repo'] ?? 'unknown');
    $branch = (string) ($run['branch'] ?? 'unknown');
    $dir = proof_run_dir($root, $repo, $branch, $run['pr'] ?? null);
    $legacy = proof_run_dir($root, $repo, $branch);

    return [$dir, $dir !== $legacy && ! is_dir($dir) && is_dir($legacy) ? $legacy : $dir];
}

/**
 * Ingests each source as the shot at its position, named `<NN>-<route>-<hash>.png` by the first eight hex
 * digits of its sha1: a shot carried forward (`null` in `shotSources`) keeps its file, no new shot can
 * overwrite it, and the same source writes the same name. A source that is not a file is skipped.
 */
function proof_store_shots(string $dir, array $run, array $sources): array
{
    foreach ($sources as $i => $source) {
        $source = (string) $source;
        if (! is_file($source)) {
            continue;
        }
        $route = proof_slug((string) ($run['shots'][$i]['route'] ?? 'state'));
        $name = sprintf('%02d-%s-%s.png', $i + 1, $route, substr((string) sha1_file($source), 0, 8));
        if (proof_store_ingest_shot($source, "{$dir}/shots/{$name}")) {
            $run['shots'][$i]['file'] = "shots/{$name}";
        }
    }

    return $run;
}

/**
 * Downscale to at most 1600px wide. PNG is kept rather than JPEG: JPEG artefacts on UI text
 * are exactly the kind of difference a proof page must not introduce.
 */
function proof_store_ingest_shot(string $source, string $destination): bool
{
    if (! is_file($source) || ! copy($source, $destination)) {
        return false;
    }

    $size = @getimagesize($destination);
    if (is_array($size) && $size[0] > 1600) {
        exec('sips --resampleWidth 1600 ' . escapeshellarg($destination) . ' 2>/dev/null', $out, $code);
    }

    return true;
}

/**
 * The tests the run's branch adds or changes against its base, read in its worktree at every write, or why not:
 * the caller then keeps the stored list, so a page filed after the worktree was removed keeps the one it had.
 *
 * @return list<array{file: string, cases: list<array{name: string, change: string}>}>|string
 */
function proof_store_added_tests(array $run): array|string
{
    $worktree = rtrim((string) ($run['worktree'] ?? ''), '/');
    $base = (string) ($run['base'] ?? '');
    if ($worktree === '') {
        return 'the run names no worktree';
    }
    if (! is_dir($worktree)) {
        return "no worktree at {$worktree}";
    }
    if ($base === '') {
        return 'the run names no base';
    }
    [$code, $diff, $err] = pipeline_git_run($worktree, ['diff', "origin/{$base}...HEAD"]);
    if ($code !== 0) {
        return "git diff origin/{$base}...HEAD failed: {$err}";
    }

    return proof_added_tests($diff, function (string $path) use ($worktree): ?string {
        [$code, $content] = pipeline_git_run($worktree, ['show', "HEAD:{$path}"]);

        return $code === 0 ? $content : null;
    });
}
```

In `proof_cli.php`:

- The `require_once` lines become `require_once __DIR__ . '/proof_store.php';` (it loads `proof.php` and
  `proof_render.php`).
- Delete `proof_cli_ingest_shot()` (moved to `proof_store_ingest_shot()`).
- Replace `proof_cli_write()` with:

```php
function proof_cli_write(string $payloadPath): int
{
    $payload = json_decode((string) @file_get_contents($payloadPath), true);
    if (! is_array($payload)) {
        fwrite(STDERR, "proof: unreadable payload at {$payloadPath}\n");

        return 0;
    }

    // Nothing is filed until the run as it will be filed passes: a page written anyway would carry its title into
    // the store index for good. The leg sees no page path on stdout, fixes the payload, writes again.
    $filed = proof_store_file($payload, date('c'), fn (array $run): array => [...proof_validate_run($run), ...proof_validate_prose($run)]);
    if ($filed['page'] === null) {
        fwrite(STDERR, "proof: payload rejected, nothing written:\n  - " . implode("\n  - ", $filed['problems']) . "\n");

        return 0;
    }

    echo $filed['page'] . "\n";

    return 0;
}
```

- In `proof_cli_open()`'s docblock, `which is the normal state of a backend-only run that never triggered \`verify-ui\``
  becomes `which is the state of a run that halted before \`handoff\``.

- [ ] **Step 4: Run the tests to see them pass**

Run: `php -l skills/pipeline/checks/proof_store.php && php -l skills/pipeline/checks/proof_cli.php && ./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "ProofWriteTest|ProofOpenTest|ProofTest"`
Expected: PASS, except the last `ProofWriteTest` (it needs Task 4's page).

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/proof_store.php skills/pipeline/checks/proof_cli.php skills/pipeline/checks/tests/ProofWriteTest.php skills/pipeline/checks/tests/Pest.php
git commit -m "feat(pipeline): one filing path merges each write over the stored run and stores the tests the branch adds (#141)"
```

---

### Task 4: The page, `proof_render.php`

**Files:**
- Modify: `skills/pipeline/checks/proof_render.php`
- Test: `skills/pipeline/checks/tests/ProofRenderTest.php`

**Interfaces:**
- Consumes: `ProofShotState` (Task 1), `ProofTestChange` (Task 2).
- Produces: `proof_render_run(array $run): string` with the new sections; `proof_render_summary(array $run)`,
  `proof_render_explainer(array $run)`, `proof_render_tests(array $files)`, `proof_render_shot(array $shot)`,
  `proof_shot_rows(array $shots): list<list<array>>`, `proof_render_script(): string`; `const PROOF_PENDING`.

- [ ] **Step 1: Write the failing tests**

Update existing tests in `tests/ProofRenderTest.php`:

- *renders a self-contained page with only relative image paths*: `expect($html)->not->toContain('<script');`
  becomes `expect($html)->not->toContain('<script src');`.
- *carries each badge number onto its legend marker*: `toContain('class="badge" style="top:85%;left:4%">5<')`
  becomes `toContain('class="badge" style="top:85%;left:4%" tabindex="0">5<')`.

Append:

```php
/** A run as filed from now on: schema 2, with the prose and a test list. */
function proof_current_run(array $overrides = []): array
{
    return proof_fixture_run([
        'schema' => 2,
        'clientSummary' => 'Bij elke bestelregel staat nu een overzicht van de producten.',
        'explainer' => ['problem' => 'An order row did not say what was ordered.', 'solution' => 'Each row now lists its products.'],
        'addedTests' => [['file' => 'tests/Feature/OrdersTest.php', 'cases' => [['name' => 'shows the grid', 'change' => 'added'], ['name' => 'test_totals', 'change' => 'changed']]]],
        ...$overrides,
    ]);
}

it('opens with the Dutch client summary and its copy button, then the explainer, above the technical account', function () {
    $html = proof_render_run(proof_current_run());

    expect($html)->toContain('<p lang="nl" id="client-summary">Bij elke bestelregel staat nu een overzicht van de producten.</p>');
    expect($html)->toContain('<button type="button" class="copy" data-copy="client-summary">Copy</button>');
    expect($html)->toContain("<h2>In plain language</h2>\n<h3>The problem</h3>\n<p>An order row did not say what was ordered.</p>");
    expect($html)->toContain("<h3>The solution</h3>\n<p>Each row now lists its products.</p>");
    expect(strpos($html, 'id="client-summary"'))->toBeLessThan(strpos($html, 'In plain language'));
    expect(strpos($html, 'In plain language'))->toBeLessThan(strpos($html, '<p class="lead">'));
    expect(strpos($html, '<p class="lead">'))->toBeLessThan(strpos($html, '<h2>Problem</h2>'));
});

it('lists the tests the PR adds per file, tagged new or changed, and says so when there are none', function () {
    $html = proof_render_run(proof_current_run());

    expect($html)->toContain("<h2>Tests this PR adds</h2>\n<h3><code>tests/Feature/OrdersTest.php</code></h3>");
    expect($html)->toContain('<li>shows the grid <span class="tag tag-added">new</span></li>');
    expect($html)->toContain('<li>test_totals <span class="tag tag-changed">changed</span></li>');
    expect(strpos($html, 'Tests this PR adds'))->toBeLessThan(strpos($html, '<h2>Checks</h2>'));
    expect(proof_render_run(proof_current_run(['addedTests' => []])))->toContain('This PR adds or changes no test cases.');
});

it('marks the summary and the explainer pending on the page handoff files, with no copy button', function () {
    $run = proof_current_run();
    unset($run['clientSummary'], $run['explainer']);
    $html = proof_render_run($run);

    expect(substr_count($html, '<p class="pending">Pending: written by the step that finishes the run.</p>'))->toBe(2);
    expect($html)->not->toContain('data-copy=');
});

it('renders a run filed before this change as before: no summary, explainer, tests or pending lines', function () {
    // Shaped like _proofs/Asimo/pr-210: schema 1, shots without a state.
    $html = proof_render_run(proof_fixture_run([
        'schema' => 1,
        'shots' => [['file' => 'shots/01-klant-dashboard.png', 'title' => 'Hero house number list', 'route' => '/klant/dashboard',
            'badges' => [['num' => 1, 'topPct' => 46, 'leftPct' => 37, 'title' => 'No stale empty line', 'note' => 'The list starts at -1.']]]],
    ]));

    foreach (['Client summary', 'In plain language', 'Tests this PR adds', 'Pending:', 'class="ribbon'] as $absent) {
        expect($html)->not->toContain($absent);
    }
    expect($html)->toContain('<h2>Problem</h2>')->toContain('<h2>Visual result</h2>');
});

it('puts a ribbon on each shot by its state', function () {
    $html = proof_render_run(proof_current_run(['shots' => [
        ['file' => 'shots/01.png', 'title' => 'Old', 'route' => '/', 'state' => 'defect', 'badges' => []],
        ['file' => 'shots/02.png', 'title' => 'Lone before', 'route' => '/', 'state' => 'before', 'badges' => []],
    ]]));

    expect($html)->toContain('<span class="ribbon ribbon-defect">Defect</span>');
    expect($html)->toContain('<span class="ribbon ribbon-before">Before</span>');
    expect($html)->not->toContain('class="pair"');
});

it('pairs a before shot directly followed by an after shot in one row, and only those', function () {
    $shot = fn (string $title, string $state) => ['file' => "shots/{$title}.png", 'title' => $title, 'route' => '/', 'state' => $state, 'badges' => []];
    $html = proof_render_run(proof_current_run(['shots' => [$shot('b1', 'before'), $shot('a1', 'after'), $shot('a2', 'after'), $shot('b2', 'before'), $shot('d1', 'defect')]]));

    expect(substr_count($html, '<div class="pair">'))->toBe(1);
    expect(proof_shot_rows([$shot('b1', 'before'), $shot('a1', 'after'), $shot('a2', 'after'), $shot('b2', 'before'), $shot('d1', 'defect')]))
        ->toBe([[$shot('b1', 'before'), $shot('a1', 'after')], [$shot('a2', 'after')], [$shot('b2', 'before')], [$shot('d1', 'defect')]]);
});

it('holds each badge\'s note in a focusable tooltip, and keeps the legend', function () {
    $html = proof_render_run(proof_current_run(['shots' => [[
        'file' => 'shots/01.png', 'title' => 'Orders', 'route' => '/orders', 'state' => 'after',
        'badges' => [['num' => 1, 'topPct' => 10, 'leftPct' => 20, 'title' => 'Grid', 'note' => 'Was: nothing']],
    ]]]));

    expect($html)->toContain('<span class="badge" style="top:10%;left:20%" tabindex="0">1<span class="tip" role="tooltip">Grid — Was: nothing</span></span>');
    expect($html)->toContain('<li value="1"><strong>Grid</strong> — Was: nothing</li>');
});

it('carries the zoom dialog and the copy script inline', function () {
    $html = proof_render_run(proof_current_run());

    expect($html)->toContain('<dialog class="zoom" id="zoom"></dialog>');
    expect($html)->toContain('navigator.clipboard.writeText');
    expect($html)->toContain("document.execCommand('copy')");
    expect($html)->toContain('showModal()');
    expect($html)->not->toContain('<script src');
    expect(proof_render_index([]))->not->toContain('<script');
});

it('escapes the summary, the explainer, the test names and their files', function () {
    $html = proof_render_run(proof_current_run([
        'clientSummary' => 'Klant <b>"blij"</b> & tevreden',
        'explainer' => ['problem' => '<script>x</script>', 'solution' => 'a < b'],
        'addedTests' => [['file' => 'tests/<i>X</i>Test.php', 'cases' => [['name' => 'shows <em>it</em>', 'change' => 'added']]]],
    ]));

    expect($html)->toContain('Klant &lt;b&gt;&quot;blij&quot;&lt;/b&gt; &amp; tevreden');
    expect($html)->toContain('&lt;script&gt;x&lt;/script&gt;')->toContain('a &lt; b');
    expect($html)->toContain('tests/&lt;i&gt;X&lt;/i&gt;Test.php')->toContain('shows &lt;em&gt;it&lt;/em&gt;');
    expect($html)->not->toContain('<script>x</script>');
});
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter ProofRenderTest`
Expected: FAIL: no `client-summary`, no `proof_shot_rows()`, no `tabindex`.

- [ ] **Step 3: Write the code**

At the top of `proof_render.php`, after the file docblock:

```php
require_once __DIR__ . '/proof.php';
require_once __DIR__ . '/proof_tests.php';

const PROOF_PENDING = "<p class=\"pending\">Pending: written by the step that finishes the run.</p>\n";
```

In `proof_render_styles()`, replace the first three lines (`:root`, the dark `@media`) with:

```css
:root { --bg:#fff; --fg:#18181b; --muted:#71717a; --line:#e4e4e7; --card:#fafafa; --accent:#dc2626;
  --before:#71717a; --after:#16a34a; --defect:var(--accent); }
@media (prefers-color-scheme: dark) {
  :root { --bg:#18181b; --fg:#f4f4f5; --muted:#a1a1aa; --line:#3f3f46; --card:#27272a; --accent:#ef4444;
    --before:#a1a1aa; --after:#22c55e; }
}
```

replace the two `.shot` lines with:

```css
.shot { position:relative; display:block; margin:0 0 .5rem; cursor:zoom-in; }
.shot img { width:100%; display:block; border:1px solid var(--line); border-radius:.5rem; }
```

add `cursor:help;` to the `.badge` rule, and append before the closing `CSS;`:

```css
h3 { font-size:.95rem; margin:1.25rem 0 .4rem; }
.section-head { display:flex; align-items:center; justify-content:space-between; gap:1rem;
  margin:2.5rem 0 .75rem; padding-bottom:.4rem; border-bottom:1px solid var(--line); }
.section-head h2 { margin:0; padding:0; border:0; }
button.copy { font:inherit; font-size:.8rem; padding:.2rem .7rem; border:1px solid var(--line); border-radius:.35rem;
  background:var(--card); color:var(--fg); cursor:pointer; }
.pending { color:var(--muted); font-style:italic; }
.tag { display:inline-block; margin-left:.4rem; padding:0 .4rem; border:1px solid var(--line); border-radius:.25rem;
  color:var(--muted); font-size:.75rem; font-weight:600; }
.tag-added { color:var(--after); border-color:var(--after); }
.ribbon { position:absolute; top:.6rem; left:.6rem; padding:.1rem .55rem; border-radius:.25rem; color:#fff;
  font-size:12px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; }
.ribbon-before { background:var(--before); }
.ribbon-after { background:var(--after); }
.ribbon-defect { background:var(--defect); }
.badge .tip { display:none; position:absolute; top:calc(100% + 6px); left:50%; transform:translateX(-50%);
  width:max-content; max-width:16rem; padding:.35rem .55rem; border-radius:.3rem; background:var(--fg); color:var(--bg);
  font-size:12px; font-weight:400; line-height:1.4; text-align:left; z-index:2; }
.badge:hover .tip, .badge:focus .tip { display:block; }
.pair { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
@media (max-width:700px) { .pair { grid-template-columns:1fr; } }
dialog.zoom { padding:0; border:0; max-width:95vw; max-height:95vh; overflow:auto; background:var(--bg); }
dialog.zoom::backdrop { background:rgba(0,0,0,.75); }
dialog.zoom .shot { width:max-content; margin:0; cursor:zoom-out; }
dialog.zoom .shot img { width:auto; max-width:none; }
```

Replace `proof_render_shots()` with:

```php
function proof_render_shots(array $shots): string
{
    if ($shots === []) {
        return '';
    }

    return "<h2>Visual result</h2>\n" . implode('', array_map(
        fn (array $row) => count($row) === 2
            ? "<div class=\"pair\">\n" . proof_render_shot($row[0]) . proof_render_shot($row[1]) . "</div>\n"
            : proof_render_shot($row[0]),
        proof_shot_rows(array_values($shots)),
    ));
}

/**
 * The shots by row: a `before` directly followed by an `after` share one, every other shot has its own.
 * Pairing is positional; no field names the pair.
 *
 * @return list<list<array>>
 */
function proof_shot_rows(array $shots): array
{
    $rows = [];
    $i = 0;
    while ($i < count($shots)) {
        $paired = ($shots[$i]['state'] ?? null) === ProofShotState::Before->value
            && ($shots[$i + 1]['state'] ?? null) === ProofShotState::After->value;
        $width = $paired ? 2 : 1;
        $rows[] = array_slice($shots, $i, $width);
        $i += $width;
    }

    return $rows;
}

/** One shot: its caption, the image with its ribbon and badges (each holding its note), and the legend below. */
function proof_render_shot(array $shot): string
{
    $badges = '';
    $legend = '';
    foreach ($shot['badges'] ?? [] as $badge) {
        $title = proof_e((string) ($badge['title'] ?? ''));
        $note = proof_e((string) ($badge['note'] ?? ''));
        $badges .= sprintf(
            '<span class="badge" style="top:%s%%;left:%s%%" tabindex="0">%s<span class="tip" role="tooltip">%s — %s</span></span>',
            proof_e((string) (0 + ($badge['topPct'] ?? 0))),
            proof_e((string) (0 + ($badge['leftPct'] ?? 0))),
            proof_e((string) ($badge['num'] ?? '')),
            $title,
            $note,
        );
        // Carry the badge's own number onto the list marker. The <ol> would otherwise
        // renumber from 1 per figure, so a run that numbers its badges continuously across
        // shots — which nothing forbids — renders a "5" on the image above a "1." in the
        // legend, and the two stop referring to each other.
        $marker = is_numeric($badge['num'] ?? null)
            ? ' value="' . proof_e((string) (int) $badge['num']) . '"'
            : '';

        $legend .= '<li' . $marker . '><strong>' . $title . '</strong> — ' . $note . "</li>\n";
    }

    $state = is_string($shot['state'] ?? null) ? ProofShotState::tryFrom($shot['state']) : null;
    $ribbon = $state === null ? '' : '<span class="ribbon ribbon-' . $state->value . '">' . $state->label() . '</span>';
    $caption = empty($shot['caption'])
        ? ''
        : '<span class="caption">' . proof_e((string) $shot['caption']) . '</span>';

    return "<figure>\n"
        . '<figcaption><strong>' . proof_e((string) ($shot['title'] ?? '')) . '</strong> — <code>'
        . proof_e((string) ($shot['route'] ?? '')) . '</code>' . $caption . "</figcaption>\n"
        . '<span class="shot" role="button" tabindex="0" title="Zoom"><img alt="' . proof_e((string) ($shot['title'] ?? '')) . '" src="'
        . proof_e((string) ($shot['file'] ?? '')) . '">' . $ribbon . $badges . "</span>\n"
        . ($legend === '' ? '' : "<ol class=\"legend\">\n{$legend}</ol>\n")
        . "</figure>\n";
}
```

Add after `proof_render_prose()`:

```php
/** The Dutch client summary for the hour registration, with its copy button; pending until a step writes it. */
function proof_render_summary(array $run): string
{
    $summary = trim((string) ($run['clientSummary'] ?? ''));
    if ($summary === '') {
        return "<h2>Client summary</h2>\n" . PROOF_PENDING;
    }

    return "<div class=\"section-head\"><h2>Client summary</h2><button type=\"button\" class=\"copy\" data-copy=\"client-summary\">Copy</button></div>\n"
        . '<p lang="nl" id="client-summary">' . proof_e($summary) . "</p>\n";
}

/** The problem and the solution for a reader who knows nothing about the issue; pending until a step writes them. */
function proof_render_explainer(array $run): string
{
    $explainer = $run['explainer'] ?? null;
    $written = is_array($explainer)
        && trim((string) ($explainer['problem'] ?? '')) !== ''
        && trim((string) ($explainer['solution'] ?? '')) !== '';

    return "<h2>In plain language</h2>\n" . ($written
        ? "<h3>The problem</h3>\n" . proof_render_prose((string) $explainer['problem'])
            . "<h3>The solution</h3>\n" . proof_render_prose((string) $explainer['solution'])
        : PROOF_PENDING);
}

/** @param list<array{file: string, cases: list<array{name: string, change: string}>}> $files */
function proof_render_tests(array $files): string
{
    if ($files === []) {
        return "<h2>Tests this PR adds</h2>\n<p class=\"meta\">This PR adds or changes no test cases.</p>\n";
    }
    $out = "<h2>Tests this PR adds</h2>\n";
    foreach ($files as $file) {
        $out .= '<h3><code>' . proof_e((string) $file['file']) . "</code></h3>\n<ul class=\"tests\">\n";
        foreach ($file['cases'] as $case) {
            $change = ProofTestChange::from((string) $case['change']);
            $out .= '<li>' . proof_e((string) $case['name']) . ' <span class="tag tag-' . $change->value . '">' . $change->label() . "</span></li>\n";
        }
        $out .= "</ul>\n";
    }

    return $out;
}

/**
 * The page's one script: the copy button (the clipboard API, else a selected textarea and `execCommand('copy')`,
 * which works over `file://`) and the zoom (a click on a shot shows a copy of it at natural size in the dialog;
 * Escape, the backdrop or the zoomed shot closes it).
 */
function proof_render_script(): string
{
    return <<<'JS'
(function () {
  var dialog = document.getElementById('zoom');
  function fallback(text) {
    var area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    var copied = document.execCommand('copy');
    area.remove();
    if (!copied) { throw new Error('copy failed'); }
  }
  function copyText(text) {
    try {
      return navigator.clipboard.writeText(text).catch(function () { fallback(text); });
    } catch (error) {
      return new Promise(function (resolve) { fallback(text); resolve(); });
    }
  }
  function flash(button, label) {
    button.textContent = label;
    setTimeout(function () { button.textContent = 'Copy'; }, 2000);
  }
  function zoom(shot) {
    dialog.replaceChildren(shot.cloneNode(true));
    dialog.showModal();
  }
  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-copy]');
    if (button) {
      copyText(document.getElementById(button.dataset.copy).textContent)
        .then(function () { flash(button, 'Copied'); }, function () { flash(button, 'Copy failed'); });
      return;
    }
    if (dialog.open) {
      if (event.target === dialog || event.target.closest('#zoom .shot')) { dialog.close(); }
      return;
    }
    var shot = event.target.closest('.shot');
    if (shot) { zoom(shot); }
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Enter' && !dialog.open && event.target.classList && event.target.classList.contains('shot')) {
      zoom(event.target);
    }
  });
})();
JS;
}
```

In `proof_render_run()`, replace the body assembly from `$body = "<h1>"…` through `$body .= proof_render_ledger(...)` with:

```php
    $body = "<h1>" . proof_e($title) . "</h1>\n<p class=\"meta\">{$meta}</p>\n";

    // A run filed before schema 2 renders as it did: pending lines on a finished old page would claim work is
    // outstanding.
    $current = (int) ($run['schema'] ?? 1) >= 2;
    if ($current) {
        $body .= proof_render_summary($run) . proof_render_explainer($run);
    }

    if (! empty($run['headline'])) {
        $body .= '<p class="lead">' . proof_e((string) $run['headline']) . "</p>\n";
    }

    if (! empty($run['problem'])) {
        $body .= "<h2>Problem</h2>\n" . proof_render_prose((string) $run['problem']);
    }
    if (! empty($run['solution'])) {
        $body .= "<h2>Solution</h2>\n" . proof_render_prose((string) $run['solution']);
    }

    if ($current) {
        $body .= proof_render_tests($run['addedTests'] ?? []);
    }
    $body .= proof_render_shots($run['shots'] ?? []);
    $body .= proof_render_checks($run['checks'] ?? []);
    $body .= proof_render_list('Open questions', $run['openQuestions'] ?? []);
    $body .= proof_render_ledger($run['ledger'] ?? []);
    $body .= "<dialog class=\"zoom\" id=\"zoom\"></dialog>\n<script>\n" . proof_render_script() . "\n</script>\n";
```

`proof_render_index()` does not change.

- [ ] **Step 4: Run the tests to see them pass**

Run: `php -l skills/pipeline/checks/proof_render.php && ./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "ProofRenderTest|ProofWriteTest|ProofTest|ProofAddedTestsTest|ProofOpenTest"`
Expected: PASS, the last `ProofWriteTest` included.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/proof_render.php skills/pipeline/checks/tests/ProofRenderTest.php
git commit -m "feat(pipeline): the proof page opens with the client summary, the explainer and the tests the PR adds; shots get ribbons, pairs, tooltips and a zoom (#141)"
```

---

### Task 5: `handoff` files the page and records it

**Files:**
- Modify: `skills/pipeline/checks/handoff.php`, `skills/pipeline/checks/dispatch_cli.php`, `skills/pipeline/checks/record.php`
- Test: `skills/pipeline/checks/tests/HandoffTest.php`, `HandoffCliTest.php`, `RecordTest.php`, `DispatchCliTest.php`

**Interfaces:**
- Consumes: `proof_store_file()` (Task 3), `proof_validate_run()`, `proof_short_title()`, `proof_read_run()` (Task 1).
- Produces: `pipeline_handoff_heading(string $spec, string $branch): string`;
  `pipeline_handoff_proof(array $manifest, array $pr, string $heading): array{payload: array, defaults: array{title: string}}`;
  `pipeline_handoff()` returns `{pr, url, created, notes, page}`; `dispatch_cli_handoff_page(array $page): array{proof: ?string, notes: list<string>}`;
  `handoff`'s answer gains `proof` (string or null); `pipeline_record_pr(array $manifest, array $flags)`;
  `handoff:run` `continued` takes `--pr` and optionally `--proof`.

- [ ] **Step 1: Write the failing tests**

`tests/DispatchCliTest.php`, in `dispatch_cli()`: the environment becomes
`[...getenv(), 'PIPELINE_NO_OPEN' => '0', 'PIPELINE_PROOF_ROOT' => sys_get_temp_dir() . '/pipeline-proofs-' . uniqid(), ...$env]`,
so no CLI test reaches the real store.

`tests/HandoffCliTest.php`: `handoff_gh()` returns
`['PATH' => "{$root}/bin:" . getenv('PATH'), 'GH_FAKE' => $root, 'PIPELINE_PROOF_ROOT' => "{$root}/proofs"]`
(update its `@return`). Add the helper, update the first test's exact answer, and append the new tests:

```php
/** The page `handoff` files for the fixture's PR #7 of acme/app on `feature`. */
function handoff_page(array $fixture): string
{
    return "{$fixture['root']}/proofs/app/pr-7-feature";
}
```

In *pushes the branch, opens the draft PR and records it*, the expected answer gains
`'proof' => handoff_page($fixture) . '/index.html'` after `'notes' => []`, and add
`expect(manifest_read($fixture['manifest'])['artifacts']['proof'])->toBe(handoff_page($fixture) . '/index.html');`.

```php
it('files the run\'s page from what it knows, with the prose pending, and records it', function () {
    // No `base` in the manifest: the page's base is the one gh lists for the PR.
    $fixture = handoff_fixture();

    expect(handoff($fixture)['json'])->toMatchArray(['status' => 'continued', 'notes' => [], 'proof' => handoff_page($fixture) . '/index.html']);

    $run = proof_read_run(handoff_page($fixture));
    expect($run)->toMatchArray([
        'nameWithOwner' => 'acme/app', 'repo' => 'app', 'branch' => 'feature', 'mode' => 'autoflow',
        'worktree' => $fixture['repo'], 'pr' => 7, 'prState' => 'OPEN', 'issue' => 125, 'base' => 'main',
        'title' => 'PR #7: x', 'addedTests' => [], 'schema' => 2,
    ]);
    expect(file_get_contents(handoff_page($fixture) . '/index.html'))->toContain('Pending: written by the step that finishes the run.');
    expect(file_get_contents("{$fixture['root']}/proofs/index.html"))->toContain('pr-7-feature/index.html');
});

it('merges over the page on a re-run, keeping the title a step wrote', function () {
    $fixture = handoff_fixture();
    mkdir(handoff_page($fixture), 0777, true);
    file_put_contents(handoff_page($fixture) . '/run.json', json_encode(['repo' => 'app', 'branch' => 'feature', 'pr' => 7, 'title' => 'PR #7: logs that follow', 'headline' => 'Logs follow', 'schema' => 2]));

    handoff($fixture);

    expect(proof_read_run(handoff_page($fixture)))->toMatchArray(['title' => 'PR #7: logs that follow', 'headline' => 'Logs follow', 'base' => 'main', 'worktree' => $fixture['repo']]);
});

it('records continued without a page, and says why, when the page cannot be filed', function (Closure $arrange, string $why) {
    $fixture = handoff_fixture();
    $arrange($fixture);

    $result = handoff($fixture);
    chmod("{$fixture['root']}/proofs", 0755);

    expect($result['code'])->toBe(0);
    expect($result['json'])->toMatchArray(['action' => 'recorded', 'status' => 'continued', 'pr' => 7, 'proof' => null]);
    expect($result['json']['notes'][0])->toStartWith("the proof page was not filed: {$why}");
    expect(manifest_read($fixture['manifest'])['artifacts'])->not->toHaveKey('proof');
})->with([
    'a stored shot without a state' => [
        function (array $fixture) {
            mkdir(handoff_page($fixture), 0777, true);
            file_put_contents(handoff_page($fixture) . '/run.json', json_encode(['repo' => 'app', 'branch' => 'feature', 'pr' => 7, 'title' => 'PR #7: x', 'shots' => [['title' => 'Log']]]));
        },
        'shot 1 has no state: before, after or defect',
    ],
    'a store root it cannot write' => [
        function (array $fixture) {
            mkdir("{$fixture['root']}/proofs");
            chmod("{$fixture['root']}/proofs", 0555);
        },
        'cannot create',
    ],
]);
```

(The `chmod` back to 0755 runs in both rows; on the first it is a no-op. The shot-state row creates
`proofs` through `mkdir(..., true)`.)

`tests/HandoffTest.php`, append:

```php
it('takes the page heading from the spec, less its design suffix, else the branch', function () {
    expect(pipeline_handoff_heading("# Logs that follow — design\n\nbody", 'feature'))->toBe('Logs that follow');
    expect(pipeline_handoff_heading('no heading', 'feature/logs'))->toBe('feature/logs');
});

it('builds the page handoff files from the manifest and the PR, the title as a default', function () {
    $manifest = ['branch' => 'feature/logs', 'mode' => 'autoflow', 'worktree' => '/tmp/wt/', 'artifacts' => ['issue' => 125]];
    $pr = ['number' => 7, 'url' => 'https://github.com/acme/app/pull/7', 'baseRefName' => 'main'];

    expect(pipeline_handoff_proof($manifest, $pr, 'Logs that follow'))->toBe([
        'payload' => [
            'nameWithOwner' => 'acme/app', 'repo' => 'app', 'branch' => 'feature/logs', 'mode' => 'autoflow',
            'worktree' => '/tmp/wt', 'pr' => 7, 'prState' => 'OPEN', 'issue' => 125, 'base' => 'main',
        ],
        'defaults' => ['title' => 'PR #7: Logs that follow'],
    ]);
    expect(pipeline_handoff_proof([...$manifest, 'base' => 'feature/integration', 'artifacts' => ['issue' => null]], $pr, 'x')['payload'])
        ->toMatchArray(['base' => 'feature/integration'])->not->toHaveKey('issue');
    expect(mb_strlen(pipeline_handoff_proof($manifest, $pr, str_repeat('a long heading ', 10))['defaults']['title']))->toBeLessThanOrEqual(PROOF_TITLE_MAX);
});
```

`tests/RecordTest.php`:

- The expectation `'--plan is not a flag of handoff run with --status continued, which takes --pr'` becomes
  `'--plan is not a flag of handoff run with --status continued, which takes --pr, --proof'`.
- Append:

```php
it('records handoff\'s PR, and the page it filed when it filed one', function () {
    $before = record_before('handoff');

    $with = pipeline_record($before, $before, 'handoff', 'run', record_given('handoff', 'run', 'continued', ['proof' => '/proofs/app/pr-7-x/index.html']), record_facts());
    $without = pipeline_record($before, $before, 'handoff', 'run', record_given('handoff', 'run', 'continued'), record_facts());

    expect($with['artifacts'])->toMatchArray(['pr' => 7, 'proof' => '/proofs/app/pr-7-x/index.html']);
    expect($without['artifacts']['pr'])->toBe(7);
    expect($without['artifacts'])->not->toHaveKey('proof');
});
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "HandoffTest|HandoffCliTest|RecordTest"`
Expected: FAIL: `pipeline_handoff_heading()` undefined, no `proof` in the answer, `--proof is not a flag of handoff run`.

- [ ] **Step 3: Write the code**

`record.php`:

- In `pipeline_record_table()`: `'handoff:run' => ['continued' => $row(['pr'], ['proof']), ...$gap, ...$halted],`.
- In `pipeline_record()`'s `match`: `$leg === 'handoff' => pipeline_record_pr($manifest, $flags),`.
- Replace `pipeline_record_pr()`:

```php
/** `handoff`: the PR, and the proof page it filed when it filed one. */
function pipeline_record_pr(array $manifest, array $flags): array|string
{
    if (! ctype_digit($flags['pr'])) {
        return "--pr {$flags['pr']} is not a PR number";
    }

    return [...$manifest, 'artifacts' => [
        ...($manifest['artifacts'] ?? []),
        'pr' => (int) $flags['pr'],
        ...array_intersect_key($flags, ['proof' => true]),
    ]];
}
```

`handoff.php`:

- Add `require_once __DIR__ . '/proof.php';` to the requires, and to the file docblock's first sentence add
  `, file the run's proof page` after `set the board Component`.
- Replace `pipeline_handoff_title()` with:

```php
/** The spec's first H1, less its design suffix; a spec without an H1 takes the branch. */
function pipeline_handoff_heading(string $spec, string $branch): string
{
    return preg_match('/^#[ \t]+(.+?)\s*$/m', $spec, $match) === 1
        ? preg_replace('/\s+[—-]\s+design$/u', '', $match[1])
        : $branch;
}

/** `Implement: <the spec's heading> (issue: #<n>)`. */
function pipeline_handoff_title(string $spec, string $branch, ?int $issue): string
{
    return 'Implement: ' . pipeline_handoff_heading($spec, $branch) . ($issue === null ? '' : " (issue: #{$issue})");
}

/**
 * The proof page `handoff` files (`../references/engine.md` §The proof store): where the run belongs, from the
 * manifest and the PR as gh lists it, `base` being the branch the PR now goes into. The title is a default, so a
 * title a step wrote stays when the step runs again.
 *
 * @return array{payload: array, defaults: array{title: string}}
 */
function pipeline_handoff_proof(array $manifest, array $pr, string $heading): array
{
    [$owner, $repo] = array_slice(explode('/', trim((string) parse_url((string) $pr['url'], PHP_URL_PATH), '/')), 0, 2);
    $issue = $manifest['artifacts']['issue'] ?? null;

    return [
        'payload' => [
            'nameWithOwner' => "{$owner}/{$repo}",
            'repo' => $repo,
            'branch' => (string) $manifest['branch'],
            'mode' => (string) $manifest['mode'],
            'worktree' => rtrim((string) $manifest['worktree'], '/'),
            'pr' => (int) $pr['number'],
            'prState' => 'OPEN',
            ...($issue === null ? [] : ['issue' => (int) $issue]),
            'base' => (string) ($manifest['base'] ?? $pr['baseRefName']),
        ],
        'defaults' => ['title' => proof_short_title("PR #{$pr['number']}: {$heading}")],
    ];
}
```

- In `pipeline_handoff()`: read the spec once and return the page.

```php
    $issue = isset($manifest['artifacts']['issue']) ? (int) $manifest['artifacts']['issue'] : null;
    $base = $manifest['base'] ?? null;
    $specText = $git(['show', "HEAD:{$spec}"])[1];
    $pr = $existing ?? pipeline_handoff_create($branch, $base, pipeline_handoff_title($specText, $branch, $issue), pipeline_handoff_body($spec, $plan, $issue), $gh);
    $aligned = $existing === null ? [] : pipeline_handoff_align($existing, $base, $spec, $plan, $issue, $gh);

    return [
        'pr' => (int) $pr['number'],
        'url' => (string) $pr['url'],
        'created' => $existing === null,
        'notes' => [...$aligned, ...pipeline_handoff_component_notes($board, $pr, $gh)],
        'page' => pipeline_handoff_proof($manifest, $pr, pipeline_handoff_heading($specText, $branch)),
    ];
```

  and its `@return` becomes `array{pr: int, url: string, created: bool, notes: list<string>, page: array{payload: array, defaults: array{title: string}}}`.

`dispatch_cli.php`:

- Add `require_once __DIR__ . '/proof_store.php';` after `require_once __DIR__ . '/ci.php';`.
- In `dispatch_cli_handoff()`, replace the lines from `$recorded = $record(['status' => LegStatus::Continued->value, …` to the end of the function with:

```php
    $page = dispatch_cli_handoff_page($done['page']);
    $recorded = $record([
        'status' => LegStatus::Continued->value,
        'pr' => (string) $done['pr'],
        ...($page['proof'] === null ? [] : ['proof' => $page['proof']]),
    ]);

    return $recorded['action'] === 'recorded'
        ? [...$recorded, ...array_diff_key($done, ['page' => true]), 'notes' => [...$done['notes'], ...$page['notes']], 'proof' => $page['proof']]
        : dispatch_cli_refuse("{$recorded['reason']}; PR #{$done['pr']} is open and the next run of this step adopts it");
}

/**
 * Files the run's proof page under `proof_validate_run()` alone: the prose is the finish step's. Never a halt: a
 * page that cannot be filed is a note, and the step records without one.
 *
 * @param array{payload: array, defaults: array} $page
 * @return array{proof: ?string, notes: list<string>}
 */
function dispatch_cli_handoff_page(array $page): array
{
    $filed = proof_store_file($page['payload'], date('c'), proof_validate_run(...), $page['defaults']);

    return $filed['page'] === null
        ? ['proof' => null, 'notes' => ['the proof page was not filed: ' . implode('; ', $filed['problems'])]]
        : ['proof' => $filed['page'], 'notes' => []];
}
```

- `dispatch_cli_handoff()`'s docblock: after `then \`pipeline_handoff()\` over the snapshot \`record\` builds on,`
  add ` then the proof page (\`dispatch_cli_handoff_page()\`),`.

- [ ] **Step 4: Run the tests to see them pass**

Run: `php -l skills/pipeline/checks/handoff.php && php -l skills/pipeline/checks/dispatch_cli.php && php -l skills/pipeline/checks/record.php && ./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "HandoffTest|HandoffCliTest|RecordTest|RecordCliTest|DispatchCliTest|BriefTest"`
Expected: PASS. Then confirm the real store was not touched: `ls -t ~/GitProjects/_proofs | head -3` shows no
`app` directory.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/handoff.php skills/pipeline/checks/dispatch_cli.php skills/pipeline/checks/record.php skills/pipeline/checks/tests/HandoffTest.php skills/pipeline/checks/tests/HandoffCliTest.php skills/pipeline/checks/tests/RecordTest.php skills/pipeline/checks/tests/DispatchCliTest.php
git commit -m "feat(pipeline): handoff files every run's proof page and records it as artifacts.proof (#141)"
```

---

### Task 6: The step briefs

**Files:**
- Modify: `skills/pipeline/checks/brief.php`
- Test: `skills/pipeline/checks/tests/BriefTest.php`

**Interfaces:**
- Consumes: nothing new. Produces: the two brief lines below, verbatim.

- [ ] **Step 1: Write the failing test**

In `tests/BriefTest.php`, in *says what each step passes to record, and describes no JSON*, replace the
`verify-ui` expectation and add the `review-pr` one:

```php
    expect($brief('autoflow', 'verify-ui', 'run'))
        ->toContain('- Write the proof page (engine.md §The proof store): `clientSummary` and `explainer` (a first version), and a `state` on every shot; before shots only when the spec names a before state to show, captured on the base; a defect found is shot as `defect`, and a later pass carries the earlier defect shots forward beside its own. `repo`, `branch` and `pr` are the ones in the `run.json` beside `artifacts.proof`.')
        ->toContain('- Post the text-only record comment; the path `write` printed goes to `record` as `--proof`.')
        ->toContain('- Return `continued`, or `looped-back` when the check fails.');
    expect($brief('autoflow', 'review-pr', 'resolve'))
        ->toContain('- Write the proof page (engine.md §The proof store): `clientSummary` and `explainer` as the finished work stands, the suite line under `checks`, the final open questions and ledger; `repo`, `branch` and `pr` from the `run.json` beside `artifacts.proof`.')
        ->toContain('After `record`, the last action is `proof_cli.php open` on the path `write` printed (engine.md §The proof store).')
        ->not->toContain('When `artifacts.proof` is set');
```

- [ ] **Step 2: Run the test to see it fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter BriefTest`
Expected: FAIL on the new `verify-ui` line.

- [ ] **Step 3: Write the code**

In `brief.php`, `pipeline_leg_overrides()`:

- `verify-ui:run`'s second entry becomes two entries:

```php
            'Write the proof page (engine.md §The proof store): `clientSummary` and `explainer` (a first version), and a `state` on every shot; before shots only when the spec names a before state to show, captured on the base; a defect found is shot as `defect`, and a later pass carries the earlier defect shots forward beside its own. `repo`, `branch` and `pr` are the ones in the `run.json` beside `artifacts.proof`.',
            'Post the text-only record comment; the path `write` printed goes to `record` as `--proof`.',
```

- `review-pr:resolve`: `'When \`artifacts.proof\` is set, rewrite the proof page with the final open questions and ledger.',` becomes

```php
            'Write the proof page (engine.md §The proof store): `clientSummary` and `explainer` as the finished work stands, the suite line under `checks`, the final open questions and ledger; `repo`, `branch` and `pr` from the `run.json` beside `artifacts.proof`.',
```

  and the tail `' After \`record\`, the last action is \`proof_cli.php open\` on \`artifacts.proof\` (engine.md §The proof store).'`
  becomes `' After \`record\`, the last action is \`proof_cli.php open\` on the path \`write\` printed (engine.md §The proof store).'`.

- [ ] **Step 4: Run the tests to see them pass**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "BriefTest|LockStepTest|AutoflowScriptTest"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/brief.php skills/pipeline/checks/tests/BriefTest.php
git commit -m "feat(pipeline): verify-ui and the finish step write the client summary, the explainer and shot states (#141)"
```

---

### Task 7: The docs, held to the code

**Files:**
- Modify: `skills/pipeline/references/engine.md` (§Stations, §The proof store), `skills/pipeline/references/manifest.md`, `skills/pipeline/SKILL.md`
- Test: `skills/pipeline/checks/tests/LockStepTest.php`

**Interfaces:**
- Consumes: `PROOF_STORE_KEYS`, `PROOF_SUMMARY_MAX`, `ProofShotState` (Task 1).

- [ ] **Step 1: Write the failing test**

Append to `tests/LockStepTest.php`:

```php
it('keeps engine.md §The proof store in lock-step with the fields the store files and checks', function () {
    $section = lockstep_section('engine.md', 'The proof store');

    foreach (['clientSummary', 'explainer', 'worktree', 'base', 'state', ...PROOF_STORE_KEYS, ...array_column(ProofShotState::cases(), 'value')] as $field) {
        expect($section)->toContain("`{$field}`");
    }
    expect($section)->toContain('at most ' . PROOF_SUMMARY_MAX . ' characters');
    expect($section)->toContain('`handoff` files');
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter LockStepTest`
Expected: FAIL: `` `clientSummary` `` not found.

- [ ] **Step 3: Write the docs**

**engine.md §The proof store.** Replace the paragraph that starts `` `verify-ui` instead writes a self-contained page `` (through
`` A store-wide `index.html` is the join from a PR back to its page. ``) with:

```markdown
**Every run that reaches `handoff` has a page**, a self-contained one at
`~/GitProjects/_proofs/<repo>/pr-<n>-<topic>/` — keyed by repo *and* run, because a PR number collides
across the repos that share this store as readily as a branch name ever did. The run segment is the PR
number, which is what a reader has in hand when they come looking, plus the branch's topic so the
directory still names something; a run that opened no PR keeps its branch slug, and filing adopts that
directory once a PR appears. Three steps write the page, and every write is **merged** over the run as
filed, key by key at the top level: a key the payload carries replaces the stored one whole (a list is
replaced, never appended to; `[]` empties one), and a key it leaves out is kept.

1. **`handoff` files the page** from what it knows: `nameWithOwner` and `repo` from the PR's URL,
   `branch`, `mode`, `worktree`, `pr`, `prState`, `issue`, the `base` the PR goes into, and the title
   `PR #<n>: <the spec's heading>` cut to 70 characters, which fills only a run that has no title. It
   records the page as `artifacts.proof`. A page it cannot file is a note in its answer, never a halt.
   Its page shows the client summary and the explainer as *Pending*.
2. **`verify-ui`** (a UI run) adds the shots and a first client summary and explainer.
3. **The finish step** (`review-pr`'s resolve step, every run) writes the client summary and the
   explainer as the finished work stands, the suite line under `checks`, and the final open questions
   and ledger.

An agent's write takes `repo`, `branch` and `pr` from the `run.json` beside `artifacts.proof`, so it
lands in the directory `handoff` filed; a run without `artifacts.proof` gets its page from that write.
The page opens with the **client summary** (Dutch, for the hour registration, with a copy button), then
**In plain language** (the problem and the solution for a reader who knows nothing about the issue), the
headline and the technical Problem and Solution, **Tests this PR adds**, the shots, the checks, the open
questions and the ledger. A store-wide `index.html` is the join from a PR back to its page.
```

In the payload table: replace the `` `shots` `` and `` `shotSources` `` rows, and add rows, so that the table reads
(rows not shown here stay as they are, in this order):

```markdown
| `repo`, `nameWithOwner`, `branch`, `pr`, `issue`, `prState`, `mode` | where the run belongs; `nameWithOwner` makes the PR and issue references links |
| `worktree`, `base` | the run's worktree and the branch its PR goes into, filed by `handoff`; every write diffs `origin/<base>...HEAD` there for `addedTests` |
| `title` | **required, at most 70 characters.** The run's name: page heading, browser tab, store index. `PR #430: service logs that follow`, not a sentence of findings |
| `clientSummary` | **required on every agent `write`.** One to three Dutch sentences for the hour registration: what the client gets, in the client's words, at most 400 characters. No `#<number>`, no backtick, and not the branch name (whole, or the part after its first `/`, as a word of its own) |
| `explainer` | **required on every agent `write`.** `{problem, solution}`: a paragraph each, in English, for a reader who knows nothing about the issue; blank lines become paragraphs |
| `headline` | one or two sentences: what was verified and the outcome. Rendered as the lead under the explainer |
| `problem`, `solution` | the technical account; prose, blank lines become paragraphs |
| `checks` | `tests`, `staticAnalysis` (scope-qualified), `format`, `suppressions` (list) |
| `openQuestions` | list, carried verbatim |
| `ledger` | list of `{gate, outcome, note}` |
| `shots` | list of `{title, caption, route, badges, state}`. `title` is at most 70 characters and names the state shown ("Unreachable swarm"); `caption` says what the shot proves and has no limit. `state` is **required**: `before`, `after` or `defect`, the ribbon on the shot; a `before` directly followed by an `after` renders as one pair. A badge's `note` also shows on hover |
| `shotSources` | absolute paths of the screenshots, in `shots` order, `null` for a shot carried forward with its `file`; ingested into the run's `shots/` as `<NN>-<route>-<hash>.png`, so a new shot never overwrites a carried one |
| `addedTests` | **the store's, never a payload's**: per test file, the cases the branch adds (`added`, tagged *new*) or changes (`changed`), extracted at every write by git in `worktree`; kept as filed when git cannot answer. A payload's `addedTests`, `schema`, `createdAt` and `updatedAt` are ignored |
```

Replace the refusal paragraph (`` `write` refuses a payload whose `title` is missing… `` through `Runs filed before \`title\` existed are named by their branch.`) with:

```markdown
**Before, after and defect shots.** `verify-ui` takes before shots only when the spec names a before
state to show: it checks out the base detached in the run's worktree (`git checkout --detach
origin/<base>`), captures them, and checks the branch out again before any after shot. When the base
cannot render the state (a migration it does not expect), the before shot is left out and that is an
open question, never a halt. A pass that finds a defect shoots it as `defect`; the next pass carries the
earlier defect shots forward (their `file`, `null` in `shotSources`) beside its own.

`write` judges the run as it will be filed, the payload merged over the stored run, and refuses one
whose `title` is missing or longer than 70 characters, whose shot title is too long, whose shot has no
valid `state`, or whose `clientSummary` or `explainer` breaks the rules above. It prints `proof: payload
rejected` and the problems on stderr, prints no page path, and files nothing. Fix the payload and write
again. The page `handoff` files is judged on the title and shot rules only. Runs filed before `title`
existed are named by their branch; runs filed before the client summary (`schema` 1) render without the
summary, explainer and tests sections.
```

In *The finished page opens itself*, replace `` `write` runs at least twice per run — `verify-ui` builds the page, `review-pr` finalises it — ``
with `` `handoff` files the page and `write` runs once or twice after it — `verify-ui` adds the shots, `review-pr` finalises it — ``.

Replace the paragraph *A run with no page opens nothing.* with:

```markdown
**A run with no page opens nothing.** A run that halted before `handoff` has none. `open` given a
missing path — or none — logs and returns 0; it is a silent no-op, never an error.
```

**engine.md §Stations.** In the table:

- `handoff` row, *Autonomous form* column: append `; files the run's **proof page** (§The proof store)`
  after `posts no comment`. *Manifest I/O* column: `the command records the PR# pointer and the proof page itself, through \`record\`'s code`.
- `verify-ui` row, *Autonomous form*: `runs the check, adds the shots to the run's page in the **proof store** (\`~/GitProjects/_proofs/<repo>/pr-<n>-<topic>/\`) via \`checks/proof_cli.php write\` — a \`state\` on every shot, and a first client summary and explainer — and posts a **text-only** record comment to the PR`.
- `review-pr` row, *Manifest I/O*: `feeds the PR-review gate; writes \`issue_links\` onto the entry; re-runs \`checks/proof_cli.php write\` with the client summary, the explainer, the finalised open questions and gate ledger`.

In *`handoff` in order*, replace `The Component is two idempotent board calls, and its failure is a note.` with
`The Component is two idempotent board calls, and its failure is a note. Then the page: the command files the run's proof page (§The proof store), and a page it cannot file is a note.`
and `The command records \`continued\` with the PR,` with `The command records \`continued\` with the PR and the page,`.

**manifest.md**, `artifacts` row: `` `proof` — the proof page `verify-ui` wrote `` becomes
`` `proof` — the proof page `handoff` filed (the step that first wrote it, when `handoff` could not) ``.

**SKILL.md**, replace the *Visual proof* bullet with:

```markdown
- **Visual proof** — every run that reaches `handoff` has a durable page at
  `~/GitProjects/_proofs/<repo>/pr-<n>-<topic>/index.html`: `handoff` files it, `verify-ui` adds the
  screenshots when `pipeline_triggers(...)['ui']` fires (and the PR gets a text-only record comment), and
  the finish step writes the Dutch client summary and the plain-language explainer
  (`references/engine.md` §The proof store). The finished page **opens in the browser once**, as the
  run's last action; `PIPELINE_NO_OPEN=1` suppresses that for headless and unattended runs.
```

- [ ] **Step 4: Run the whole suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, all files. Then `grep -n "backend-only\|when the run has a proof page" skills/pipeline/references/engine.md skills/pipeline/SKILL.md skills/pipeline/checks/*.php`
Expected: no hit.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/references/engine.md skills/pipeline/references/manifest.md skills/pipeline/SKILL.md skills/pipeline/checks/tests/LockStepTest.php
git commit -m "docs(pipeline): every run that reaches handoff has a proof page; the payload table names the summary, the explainer, states and tests (#141)"
```

---

### Task 8: In the browser

No code; the evidence goes in the PR body (two lines) and the `verify-ui` record. Use temp paths only.

- [ ] **Step 1: File two fixture runs into a temp store**

```bash
export PIPELINE_PROOF_ROOT=$(mktemp -d)/proofs PIPELINE_NO_OPEN=1
SHOTS=$(ls -d ~/GitProjects/_proofs/Asimo/pr-210-*/shots | head -1)
php -r '
require "skills/pipeline/checks/proof_store.php";
$root = getenv("PIPELINE_PROOF_ROOT");
$backend = ["repo" => "Demo", "branch" => "feature/issue-9-backend", "pr" => 9, "prState" => "OPEN", "mode" => "autoflow"];
var_dump(proof_store_file($backend, date("c"), proof_validate_run(...), ["title" => "PR #9: a backend run"]));
'
```

Open `$PIPELINE_PROOF_ROOT/Demo/pr-9-backend/index.html`: it shows two *Pending* lines and the empty tests line.
Then write a UI run with `proof_cli.php write` and a payload holding `clientSummary`, `explainer`, a
`before` then `after` shot (sources: two PNGs from `$SHOTS`), a `defect` shot, and badges with notes; and
write the backend run once more with `clientSummary` and `explainer`.

- [ ] **Step 2: Check them with Playwright**

Open each page over `file://` (if the browser tool refuses `file://`, serve the store with
`php -S 127.0.0.1:8141 -t "$PIPELINE_PROOF_ROOT"` and say so in the record). At 1440 px and at 390 px, and
with `prefers-color-scheme: dark`:

- the copy button reads `Copied` after a click, and the clipboard holds the summary (read it back with
  `navigator.clipboard.readText()` where the tool grants the permission);
- a click on a shot opens the dialog at natural size with the ribbon and the badges on their spots; Escape
  closes it, and so does a click on the backdrop and on the zoomed shot;
- hovering a badge shows its note; Tab onto a badge shows it too;
- the `Before` / `After` / `Defect` ribbons, the pair in two columns at 1440 and stacked at 390;
- the backend page after its second write shows the summary and the explainer, no pending line;
- no horizontal scroll at 390 px.

Invoke `browser-verification` for the annotated screenshots.

- [ ] **Step 3: Record**

Two lines in the PR body: what was checked, at which widths and themes, and the result. No commit unless
a defect found here was fixed (then its test first, in the owning task's test file).
