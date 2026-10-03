# Open questions carry a kind; blocking ones are asked before the PR goes ready — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** every open question a resolve step writes carries a kind (`blocking`, `follow-up`, `remark`);
`finish` and the CI gate answer `ask` while a `blocking` one is unanswered, so an `autoflow` run cannot
reach `gh pr ready` before the owner answered it, and a run with only `follow-up` or `remark` questions
goes ready as today.

**Architecture:** a new pure `checks/questions.php` holds `QuestionKind`, the answer prefix and three reads
over the manifest (`pipeline_open_questions()`, `pipeline_unanswered()`, `pipeline_follow_ups()`). `record`
refuses an open question without a valid kind; the resolve briefs ask for one; `finish` answers `ask` or
`done` with `followUps`; the CI gate answers `ask` first, before it reads git or gh. An owner's answer is a
`decisions` entry starting `Answer to open question <id>`, written by the existing `launch --decision`.
The proof page's `openQuestions` become `{kind, question}` items, validated on the payload and rendered
with a label. engine.md gains §Open questions; manifest.md, pipeline `SKILL.md` and the orchestrate docs
follow.

**Tech Stack:** PHP 8.3+ (no 8.4-only functions such as `array_any`) with Pest 4 in
`skills/pipeline/checks/tests`; Markdown docs.

**Spec:** `docs/superpowers/specs/2026-10-02-pipeline-open-questions-carry-a-kind-design.md`. Read it with
this plan: the plan argues from it, and its `## Assumptions` 14–21 are the answers this plan assumed.

## Global Constraints

- Run every command from the worktree root
  `/Users/jroelofs/GitProjects/LaravelClaudeMd/LaravelClaudeMd/.claude/worktrees/worktree-issue-146-pipeline-open-questions-carry-a-kind-blocking-ones`.
  This repo is not a Docker project: Pest runs on the host. The worktree has no `vendor/`: run
  `composer install` once before the first Pest call.
- Pest: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
  (append `--filter "<text>"` for some files or cases).
- Test-first: each task writes its tests, sees them fail, then writes the code. `php -l` every PHP file
  you change.
- Constants and texts, verbatim (spec §1–§6, Assumptions 14–21):
  - `const PIPELINE_ANSWER = 'Answer to open question ';` (trailing space)
  - a question's id: `gate_ledger[<i>].actions[<j>]`, both 0-based list indexes
  - a question's `decision`: `PIPELINE_ANSWER . "{$id} (\"{$question}\"): "` (trailing space)
  - the kinds, in this order: `blocking`, `follow-up`, `remark`; `QuestionKind::listed()` is
    `blocking, follow-up, remark`; labels `Blocking`, `Follow-up`, `Remark`
  - `record` refusals: ``actions[0] has no `kind` ``, ``actions[0]: `kind` is not one of blocking, follow-up, remark``,
    ``actions[0] has an unknown key `kind` ``
  - proof payload refusals: `openQuestions[0] has no question`, `openQuestions[0] has no kind: blocking, follow-up, remark`
- Answers' key order (the tests use `toBe`): `finish`'s `ask` is `['action', 'proof', 'questions', 'followUps']`,
  its `done` is `['action', 'proof', 'followUps']`; the gate's `ask` is `['action', 'questions']`. A
  `questions` item is `['id', 'gate', 'kind', 'question', 'note', 'decision']`; a `followUps` item
  `['question', 'note']`.
- `interactive` is unchanged except for what its resolve step writes and what the page shows: `returned`
  answers `done` as today (no `followUps`), and the gate's `ask` is `autoflow`-only.
- No changelog entry: the repo has no `.changelog/` and no `CHANGELOG.md`.
- Commits: stage explicit paths; no `Co-Authored-By`, no AI attribution; every message ends on `(#146)`.

## Review Focus

1. **A question's text holds a double quote or a newline.** The `decision` carries it verbatim, and the
   question is still answered by a decision built from that `decision` plus an answer: matching reads only
   the prefix up to the id. Pinned in Task 1.
2. **A ledger entry still open (a review without `actions`), or a `verify-ui` entry.** It holds no
   questions and must not break the reads. Pinned in Task 1.
3. **An answer recorded under a mistyped prefix.** The question stays open and the gate asks again: it
   fails closed. Pinned in Task 5.
4. **A run filed before kinds.** Its stored string `openQuestions` are neither refused on a later write
   that leaves `openQuestions` out nor rendered as `Array`; a manifest action without `kind` reads as
   `blocking`. Pinned in Tasks 1 and 6.
5. **`interactive` with a `blocking` question.** The gate answers as today: the human is in the finish
   step. Pinned in Task 5.

---

## File Structure

| File | Responsibility |
|---|---|
| `skills/pipeline/checks/questions.php` (create) | `QuestionKind`, `PIPELINE_ANSWER`, `pipeline_open_questions()`, `pipeline_unanswered()`, `pipeline_follow_ups()` |
| `skills/pipeline/checks/record.php` (modify) | requires `questions.php` (Task 1); `pipeline_record_action_problem()` requires `kind` on an `open-question` and refuses it elsewhere |
| `skills/pipeline/checks/brief.php` (modify) | the actions line, the kind sentence, the PR body line, the proof lines, `pipeline_answer_round_line()` |
| `skills/pipeline/checks/dispatch_cli.php` (modify) | `finish` answers `ask` or `done` with `followUps`; `ci` answers `ask` before its reads |
| `skills/pipeline/checks/ci.php` (modify) | `pipeline_ci_answer()` takes `$unanswered`; `pipeline_ci_ask()` |
| `skills/pipeline/checks/proof.php` (modify) | requires `questions.php`; `proof_open_questions_problems()`, `proof_open_question_problem()` |
| `skills/pipeline/checks/proof_cli.php` (modify) | `write` refuses a payload whose `openQuestions` items are not `{kind, question}` |
| `skills/pipeline/checks/proof_render.php` (modify) | `proof_render_open_questions()` replaces `proof_render_list()` |
| `skills/pipeline/checks/tests/Pest.php` (modify) | loads `questions.php` |
| `skills/pipeline/checks/tests/QuestionsTest.php` (create) | the three reads and the enum |
| `skills/pipeline/checks/tests/RecordTest.php` (modify) | the kind refusals; a completed entry keeps `kind` |
| `skills/pipeline/checks/tests/BriefTest.php` (modify) | the new lines and the answer round |
| `skills/pipeline/checks/tests/LockStepTest.php` (modify) | the answer round's `§` names; the proof store names the kinds |
| `skills/pipeline/checks/tests/DispatchCliTest.php` (modify) | `finish`'s `ask` and `followUps`; `ci`'s `ask` through the CLI |
| `skills/pipeline/checks/tests/CiTest.php` (modify) | `pipeline_ci_answer()` answers `ask` first |
| `skills/pipeline/checks/tests/ProofWriteTest.php` (modify) | payload refusals; a stored string run still files |
| `skills/pipeline/checks/tests/ProofRenderTest.php` (modify) | the kind label |
| `skills/pipeline/references/engine.md` (modify) | new §Open questions; §`autoflow`, §Stations, §The proof store, §The CI gate, §Resolving a review |
| `skills/pipeline/references/manifest.md` (modify) | `actions[].kind`, the `open-question` disposition, `decisions` |
| `skills/pipeline/SKILL.md` (modify) | §`autoflow` steps 4–6 |
| `skills/orchestrate/SKILL.md`, `skills/orchestrate/references/commands.md` (modify) | step 2, step 5 and §Finish |

`ProofTest.php` does not change: its only `openQuestions` (the merge test) is input to `proof_merge_run()`,
which validates nothing, and strings stay valid there.

---

### Task 1: The kinds and the open-question reads

**Files:**
- Create: `skills/pipeline/checks/questions.php`
- Create: `skills/pipeline/checks/tests/QuestionsTest.php`
- Modify: `skills/pipeline/checks/record.php` (requires `questions.php`)
- Modify: `skills/pipeline/checks/tests/Pest.php` (the file list)

**Interfaces:**
- Consumes: `ActionDisposition::OpenQuestion` (`checks/record.php`, loaded by `dispatch_cli.php` and
  `tests/Pest.php`). `record.php` requires `questions.php` from this task on, so every entry point that loads
  `record.php` (`dispatch_cli.php` for `record` and `brief`) has `QuestionKind` before Tasks 2 and 3 use it.
- Produces:
  - `enum QuestionKind: string { Blocking = 'blocking'; FollowUp = 'follow-up'; Remark = 'remark' }` with
    `public static function listed(): string` and `public function label(): string`
  - `const PIPELINE_ANSWER = 'Answer to open question ';`
  - `pipeline_open_questions(array $manifest): array` — `list<array{id: string, gate: string, kind: string, question: string, note: string}>`
  - `pipeline_unanswered(array $manifest): array` — the `blocking` ones not answered, each plus `decision: string`
  - `pipeline_follow_ups(array $manifest): array` — `list<array{question: string, note: string}>`

- [ ] **Step 1: Write the failing tests**

Add `'questions.php'` to the list in `skills/pipeline/checks/tests/Pest.php`, right after `'record.php'`:

```php
foreach (['triggers.php', 'pipeline.php', 'manifest.php', 'checks.php', 'board.php', 'proof.php', 'proof_render.php', 'proof_tests.php', 'proof_store.php', 'design_size.php', 'suite.php', 'dispatch.php', 'agents.php', 'brief.php', 'record.php', 'questions.php', 'run_cost.php', 'gh.php', 'kickoff.php', 'handoff.php', 'ci.php', 'statusline.php'] as $f) {
```

Create `skills/pipeline/checks/tests/QuestionsTest.php`:

```php
<?php

function questions_entry(string $gate, array $actions): array
{
    return ['gate' => $gate, 'leg' => pipeline_leg_of_gate($gate), 'cycle' => 1, 'at' => '2026-10-02T10:00:00Z', 'review' => 'r', 'actions' => $actions, 'outcome' => 'continued'];
}

function questions_action(string $claim, ?string $kind, string $note = 'n'): array
{
    return ['claim' => $claim, 'disposition' => 'open-question', 'note' => $note, ...($kind === null ? [] : ['kind' => $kind])];
}

function questions_manifest(array $ledger, array $decisions = []): array
{
    return ['branch' => 'feature/x', 'mode' => 'autoflow', 'gate_ledger' => $ledger, 'decisions' => $decisions];
}

it('names the kinds for a refusal and labels each for the page', function () {
    expect(array_column(QuestionKind::cases(), 'value'))->toBe(['blocking', 'follow-up', 'remark']);
    expect(QuestionKind::listed())->toBe('blocking, follow-up, remark');
    expect(array_map(fn (QuestionKind $kind) => $kind->label(), QuestionKind::cases()))->toBe(['Blocking', 'Follow-up', 'Remark']);
});

it('lists every open question across plan-approval and pr-review entries with its ledger id, a kindless one as blocking', function () {
    $integrated = ['claim' => 'step 4 drops the link', 'disposition' => 'integrated', 'note' => 'restored it'];
    $manifest = questions_manifest([
        questions_entry('plan-approval', [$integrated, questions_action('Queue or cron?', null, 'cron (built), queue')]),
        ['gate' => 'verify-ui', 'cycle' => 1, 'at' => '2026-10-02T11:00:00Z', 'outcome' => 'continued'],
        questions_entry('pr-review', [questions_action('Rename the column?', 'follow-up')]),
        ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 2, 'at' => '2026-10-02T12:00:00Z', 'review' => 'r'],
    ]);

    expect(pipeline_open_questions($manifest))->toBe([
        ['id' => 'gate_ledger[0].actions[1]', 'gate' => 'plan-approval', 'kind' => 'blocking', 'question' => 'Queue or cron?', 'note' => 'cron (built), queue'],
        ['id' => 'gate_ledger[2].actions[0]', 'gate' => 'pr-review', 'kind' => 'follow-up', 'question' => 'Rename the column?', 'note' => 'n'],
    ]);
    expect(pipeline_open_questions(['branch' => 'feature/x']))->toBe([]);
});

it('keeps a blocking question open until a decision starts with its answer prefix, so actions[20] answers nothing of actions[2]', function () {
    $actions = array_map(fn (int $n) => questions_action("Q{$n}", 'blocking'), range(0, 20));
    $manifest = questions_manifest([questions_entry('pr-review', $actions)], ['Keep the guard', 'Answer to open question gate_ledger[0].actions[20] ("Q20"): keep it']);

    $open = pipeline_unanswered($manifest);

    expect(array_column($open, 'id'))->toHaveCount(20)->toContain('gate_ledger[0].actions[2]')->not->toContain('gate_ledger[0].actions[20]');
    expect($open[2])->toBe([
        'id' => 'gate_ledger[0].actions[2]', 'gate' => 'pr-review', 'kind' => 'blocking', 'question' => 'Q2', 'note' => 'n',
        'decision' => 'Answer to open question gate_ledger[0].actions[2] ("Q2"): ',
    ]);
});

it('asks only blocking questions, and builds a decision that answers its own question whatever the text holds', function () {
    $question = "Keep the \"<x-time>\" tag,\nor a plain div?";
    $manifest = questions_manifest([questions_entry('pr-review', [
        questions_action($question, 'blocking', '<x-time> (built), a plain div'),
        questions_action('File the cleanup?', 'follow-up'),
        questions_action('self-end alignment', 'remark'),
        questions_action('Old-style question', null),
    ])]);

    $open = pipeline_unanswered($manifest);

    expect(array_column($open, 'id'))->toBe(['gate_ledger[0].actions[0]', 'gate_ledger[0].actions[3]']);
    expect($open[0]['decision'])->toBe("Answer to open question gate_ledger[0].actions[0] (\"{$question}\"): ");
    expect(pipeline_unanswered([...$manifest, 'decisions' => [$open[0]['decision'] . 'a plain div', $open[1]['decision'] . 'yes']]))->toBe([]);
});

it('lists a follow-up once across two entries, with the first one\'s note, and never a remark or a blocking question', function () {
    $manifest = questions_manifest([
        questions_entry('plan-approval', [questions_action('File the cleanup?', 'follow-up', 'first'), questions_action('A remark', 'remark')]),
        questions_entry('pr-review', [questions_action('File the cleanup?', 'follow-up', 'second'), questions_action('Fork?', 'blocking')]),
    ]);

    expect(pipeline_follow_ups($manifest))->toBe([['question' => 'File the cleanup?', 'note' => 'first']]);
    expect(pipeline_follow_ups(questions_manifest([])))->toBe([]);
});
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter QuestionsTest`
Expected: FAIL — `Class "QuestionKind" not found` and `Call to undefined function pipeline_open_questions()`.

- [ ] **Step 3: Write the implementation**

Create `skills/pipeline/checks/questions.php`:

```php
<?php

/**
 * Open questions (`../references/engine.md` §Open questions): the kind a resolve step gives each, which ones are
 * still open, and how an owner's answer is recorded. Pure: it reads the manifest array and requires no other
 * check file. `pipeline_open_questions()` reads `ActionDisposition` from `record.php`, which requires this file;
 * the proof path (`proof.php`) loads this file without `record.php` and uses `QuestionKind` only.
 */

enum QuestionKind: string
{
    case Blocking = 'blocking';
    case FollowUp = 'follow-up';
    case Remark = 'remark';

    /** `blocking, follow-up, remark`: the kinds as a refusal names them. */
    public static function listed(): string
    {
        return implode(', ', array_column(self::cases(), 'value'));
    }

    public function label(): string
    {
        return match ($this) {
            self::Blocking => 'Blocking',
            self::FollowUp => 'Follow-up',
            self::Remark => 'Remark',
        };
    }
}

/** How an owner's answer starts in `decisions`, followed by the id of the action that asked it. */
const PIPELINE_ANSWER = 'Answer to open question ';

/**
 * Every `open-question` action on every ledger entry, in ledger order. `id` is its place in the ledger, stable for
 * the run's life: entries are append-only and a completed entry's actions are never rewritten. An action written
 * before kinds existed reads as `blocking`.
 *
 * @return list<array{id: string, gate: string, kind: string, question: string, note: string}>
 */
function pipeline_open_questions(array $manifest): array
{
    $questions = [];
    foreach ($manifest['gate_ledger'] ?? [] as $i => $entry) {
        foreach ($entry['actions'] ?? [] as $j => $action) {
            if ($action['disposition'] !== ActionDisposition::OpenQuestion->value) {
                continue;
            }
            $questions[] = [
                'id' => "gate_ledger[{$i}].actions[{$j}]",
                'gate' => $entry['gate'],
                'kind' => $action['kind'] ?? QuestionKind::Blocking->value,
                'question' => $action['claim'],
                'note' => $action['note'],
            ];
        }
    }

    return $questions;
}

/**
 * The `blocking` questions no decision answers, each with `decision`: the text the session completes with the
 * owner's answer and records through `launch --decision`. The id ends in `]`, so `actions[2]` never matches
 * `actions[20]`, and the quoted question after it never decides whether a question is answered.
 *
 * @return list<array{id: string, gate: string, kind: string, question: string, note: string, decision: string}>
 */
function pipeline_unanswered(array $manifest): array
{
    $decisions = $manifest['decisions'] ?? [];
    $answered = fn (array $question) => array_filter($decisions, fn (string $decision) => str_starts_with($decision, PIPELINE_ANSWER . $question['id'])) !== [];
    $open = array_filter(
        pipeline_open_questions($manifest),
        fn (array $question) => $question['kind'] === QuestionKind::Blocking->value && ! $answered($question),
    );

    return array_values(array_map(
        fn (array $question) => [...$question, 'decision' => PIPELINE_ANSWER . "{$question['id']} (\"{$question['question']}\"): "],
        $open,
    ));
}

/**
 * The `follow-up` questions, each text once, with the first one's note: a fix round's resolve step may carry one again.
 *
 * @return list<array{question: string, note: string}>
 */
function pipeline_follow_ups(array $manifest): array
{
    $followUps = [];
    foreach (pipeline_open_questions($manifest) as $question) {
        if ($question['kind'] === QuestionKind::FollowUp->value) {
            $followUps[$question['question']] ??= ['question' => $question['question'], 'note' => $question['note']];
        }
    }

    return array_values($followUps);
}
```

In `skills/pipeline/checks/record.php`, add after the file's docblock, before `enum ActionDisposition`:

```php
require_once __DIR__ . '/questions.php';
```

`record.php` is the file whose `pipeline_record_action_problem()` reads `QuestionKind` (Task 2), and
`dispatch_cli.php` loads it for every command, `brief` included (Task 3's `pipeline_leg_overrides()` names the
kinds). There is no cycle: `questions.php` requires nothing, and its use of `ActionDisposition` sits inside a
function body, resolved at call time.

- [ ] **Step 4: Run the tests to see them pass**

Run: `php -l skills/pipeline/checks/questions.php && php -l skills/pipeline/checks/record.php && ./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter QuestionsTest`
Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/questions.php skills/pipeline/checks/record.php skills/pipeline/checks/tests/QuestionsTest.php skills/pipeline/checks/tests/Pest.php
git commit -m "feat(pipeline): open questions carry a kind, and the manifest says which blocking ones are unanswered (#146)"
```

---

### Task 2: `record` refuses an open question without a kind

**Files:**
- Modify: `skills/pipeline/checks/record.php` (`pipeline_record_action_problem()`, `pipeline_record_actions()`'s docblock)
- Modify: `skills/pipeline/checks/tests/RecordTest.php`
- Modify: `skills/pipeline/references/manifest.md` (the `actions[]` rows)

**Interfaces:**
- Consumes: `QuestionKind`, `QuestionKind::listed()` (Task 1).
- Produces: a completed ledger entry's `open-question` actions always carry `kind`, one of
  `QuestionKind`'s values; no other action carries it.

- [ ] **Step 1: Write the failing tests**

In `skills/pipeline/checks/tests/RecordTest.php`, add four rows to the dataset of
`it('refuses an actions file that is not a list of {claim, disposition, note}, naming the item and the key', …)`,
after `'a note that is no string'`:

```php
    'an open question without a kind' => ['[{"claim":"x","disposition":"open-question","note":"n"}]', 'actions[0] has no `kind`'],
    'an open question of an unknown kind' => ['[{"claim":"a","disposition":"integrated","note":"n"},{"claim":"x","disposition":"open-question","note":"n","kind":"urgent"}]', 'actions[1]: `kind` is not one of blocking, follow-up, remark'],
    'a kind that is no string' => ['[{"claim":"x","disposition":"open-question","note":"n","kind":1}]', 'actions[0]: `kind` is not one of blocking, follow-up, remark'],
    'a kind on an integrated action' => ['[{"claim":"x","disposition":"integrated","note":"n","kind":"remark"}]', 'actions[0] has an unknown key `kind`'],
```

And add this test after that one:

```php
it('completes the open entry with each open question\'s kind kept (#146)', function () {
    $before = record_snapshot('review-pr', 'resolve');
    $actions = [
        ['claim' => 'Keep the <x-time> tag?', 'disposition' => 'open-question', 'note' => '<x-time> (built), a plain div', 'kind' => 'blocking'],
        ['claim' => 'self-end alignment', 'disposition' => 'open-question', 'note' => 'kept', 'kind' => 'remark'],
        ['claim' => 'step 4 drops the link', 'disposition' => 'integrated', 'note' => 'restored it'],
    ];
    $given = record_given('review-pr', 'resolve', 'continued', ['actions-file' => json_encode($actions)]);

    expect(pipeline_record($before, $before, 'review-pr', 'resolve', $given, record_facts())['gate_ledger'][0])
        ->toBe([...record_open('pr-review'), 'actions' => $actions, 'outcome' => 'continued']);
});
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter RecordTest`
Expected: FAIL — the four new rows and the new test: an open question without a kind is accepted, and
`kind` is refused as an unknown key on every action.

- [ ] **Step 3: Write the implementation**

In `skills/pipeline/checks/record.php`, replace `pipeline_record_action_problem()` with:

```php
/** What is wrong with one action, as the words after `actions[n]`, or null. An `open-question` carries a `kind` (`../references/engine.md` §Open questions); no other action does. */
function pipeline_record_action_problem(mixed $action): ?string
{
    $ticked = fn (array $names) => implode(', ', array_map(fn (int|string $name) => "`{$name}`", $names));
    if (! is_array($action)) {
        return ' is not an object';
    }
    $open = ($action['disposition'] ?? null) === ActionDisposition::OpenQuestion->value;
    $keys = ['claim', 'disposition', 'note', ...($open ? ['kind'] : [])];
    $unknown = array_values(array_diff(array_keys($action), $keys));
    $missing = array_values(array_diff($keys, array_keys($action)));

    return match (true) {
        $unknown !== [] => " has an unknown key {$ticked($unknown)}",
        $missing !== [] => " has no {$ticked($missing)}",
        ! is_string($action['claim']) || trim($action['claim']) === '' => ': `claim` is not a non-empty string',
        ! is_string($action['disposition']) || ActionDisposition::tryFrom($action['disposition']) === null
            => ': `disposition` is not one of ' . implode(', ', array_column(ActionDisposition::cases(), 'value')),
        ! is_string($action['note']) => ': `note` is not a string',
        $open && (! is_string($action['kind']) || QuestionKind::tryFrom($action['kind']) === null) => ': `kind` is not one of ' . QuestionKind::listed(),
        default => null,
    };
}
```

and change `pipeline_record_actions()`'s docblock to
`/** @return list<array{claim: string, disposition: string, note: string, kind?: string}>|string the actions, or why the file does not hold them */`.

In `skills/pipeline/references/manifest.md`, replace the `actions[].disposition` row with these two rows:

```markdown
| `actions[].disposition` | `integrated` (edited and committed) \| `recorded` (logged, no edit) \| `open-question` (carried verbatim into the PR body with its kind) |
| `actions[].kind` | **only on an `open-question`, which must carry it:** `blocking` (a fork: the answer changes this PR's code) \| `follow-up` (work outside this PR) \| `remark` (a note on a choice already made); `blocking` when unsure. `record` refuses an open question without one and a `kind` on any other action; an open question written before kinds reads as `blocking` (`engine.md` §Open questions) |
```

- [ ] **Step 4: Run the tests to see them pass**

Run: `php -l skills/pipeline/checks/record.php && ./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "RecordTest|RecordCliTest|QuestionsTest"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/record.php skills/pipeline/checks/tests/RecordTest.php skills/pipeline/references/manifest.md
git commit -m "feat(pipeline): record refuses an open question without a kind (#146)"
```

---

### Task 3: The resolve briefs ask for a kind; an answer is a finding of `review-pr`; engine.md §Open questions

**Files:**
- Modify: `skills/pipeline/checks/brief.php` (`pipeline_leg_overrides()`, `pipeline_brief_overrides()`, new `pipeline_answer_round_line()`)
- Modify: `skills/pipeline/checks/tests/BriefTest.php`
- Modify: `skills/pipeline/checks/tests/LockStepTest.php`
- Modify: `skills/pipeline/references/engine.md` (new §Open questions; §Stations' `review-pr` row; §Resolving a review)

**Interfaces:**
- Consumes: `QuestionKind`, `PIPELINE_ANSWER` (Task 1); `pipeline_decisions_starting(array $manifest, string $prefix): int` (`checks/ci.php`, already used by `brief.php`).
- Produces: `pipeline_answer_round_line(string $step): string`.

- [ ] **Step 1: Write the failing tests**

In `skills/pipeline/checks/tests/BriefTest.php`, in `it('says what each step passes to record, and describes no JSON', …)`, replace the
`review-plan` resolve expectation's string with:

```php
        ->toContain('- Write what you did with each point to `/tmp/wt/.claude/pipeline/feature-x.actions.json` as a JSON list of `{claim, disposition, note}`, `disposition` one of `integrated`, `recorded`, `open-question`, plus `kind` on an `open-question`, one of `blocking`, `follow-up`, `remark` (engine.md §Open questions), and `[]` when you acted on nothing; `record` completes the open entry from it.');
```

and in the same test replace the `review-pr` resolve proof-page string with:

```php
        ->toContain('- Write the proof page (engine.md §The proof store): `clientSummary` and `explainer` as the finished work stands, the suite line under `checks`, the final open questions, each `{kind, question}`, and the ledger; `repo`, `branch` and `pr` from the `run.json` beside `artifacts.proof`, and a run without `artifacts.proof` gets its page from this write, with `repo` (the GitHub name), `branch` and `pr` from the PR.')
```

Add these tests after `it('makes a recorded conflict with the base a finding …', …)`:

```php
it('has both resolve steps give every open question a kind, blocking when unsure, in both modes (#146)', function (string $mode, string $leg) {
    $open = ['gate' => pipeline_gate_of($leg), 'leg' => $leg, 'cycle' => 1, 'at' => '2026-09-22T12:00:00Z', 'review' => 'r'];
    $brief = pipeline_brief(brief_manifest($leg, ['mode' => $mode, 'gate_ledger' => [$open]]), $leg, '/tmp/m.json', 'resolve');

    expect($brief)
        ->toContain('- Carry anything unresolved verbatim as an open question with its kind: `blocking` when the answer changes this PR\'s code, `follow-up` for work outside it, `remark` for a note on a choice already made; `blocking` when unsure. A `blocking` question names its options in `note`, the one the PR built first (engine.md §Open questions).')
        ->not->toContain('- Carry anything unresolved verbatim as an open question.');
})->with(['autoflow', 'interactive'])->with(['review-plan', 'review-pr']);

it('has review-pr\'s finish step lead each PR body open question with its kind, and verify-ui file an unrenderable before state as a remark (#146)', function () {
    $open = ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => '2026-09-22T12:00:00Z', 'review' => 'r'];

    expect(pipeline_brief(brief_manifest('review-pr', ['mode' => 'autoflow', 'gate_ledger' => [$open]]), 'review-pr', '/tmp/m.json', 'resolve'))
        ->toContain('- Under the PR body\'s `## Open questions`, one line per open question led by its kind (`- **blocking:** …`), or `None.`; a question a settled `Answer to open question` decision answers is no longer open.');
    expect(pipeline_brief(brief_manifest('verify-ui', ['mode' => 'autoflow']), 'verify-ui', '/tmp/m.json', 'run'))
        ->toContain('- A before state the base cannot render is left out, and is an open question of kind `remark` in `openQuestions`, whose items are `{kind, question}` (engine.md §The proof store).');
});

it('makes a recorded answer to an open question a finding of review-pr\'s review and resolve steps, and only then (#146)', function () {
    $open = ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 2, 'at' => '2026-09-22T12:00:00Z', 'review' => 'r'];
    $answer = 'Answer to open question gate_ledger[3].actions[0] ("Queue or cron?"): queue';
    $round = ['mode' => 'autoflow', 'decisions' => ['The engine never edits.', $answer]];
    $review = 'A settled `Answer to open question` decision whose answer departs from what the PR built is a finding of this review: name what it changes (engine.md §Open questions).';
    $resolve = 'Integrate each settled `Answer to open question` decision that departs from what the PR built, and name it in `actions`; the question it answers is no longer open (engine.md §Open questions).';

    expect(pipeline_brief(brief_manifest('review-pr', $round), 'review-pr', '/tmp/m.json', 'review'))->toContain($review)->not->toContain($resolve);
    expect(pipeline_brief(brief_manifest('review-pr', [...$round, 'gate_ledger' => [$open]]), 'review-pr', '/tmp/m.json', 'resolve'))->toContain($resolve)->not->toContain($review);
    expect(pipeline_brief(brief_manifest('review-pr', ['mode' => 'autoflow']), 'review-pr', '/tmp/m.json', 'review'))->not->toContain($review);
    expect(pipeline_brief(brief_manifest('review-pr', ['mode' => 'autoflow', 'gate_ledger' => [$open]]), 'review-pr', '/tmp/m.json', 'resolve'))->not->toContain($resolve);
    expect(pipeline_brief(brief_manifest('implement', $round), 'implement', '/tmp/m.json'))->not->toContain($review)->not->toContain($resolve);
});
```

In `skills/pipeline/checks/tests/LockStepTest.php`, in `it('keeps every engine.md section a brief names', …)`, add the answer round's lines
to the inline list after `pipeline_conflict_round_line('resolve'),`:

```php
            pipeline_answer_round_line('review'),
            pipeline_answer_round_line('resolve'),
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "BriefTest|LockStepTest"`
Expected: FAIL — the changed strings are not in the briefs, and `Call to undefined function pipeline_answer_round_line()`.

- [ ] **Step 3: Write the implementation**

In `skills/pipeline/checks/brief.php`, `pipeline_leg_overrides()`:

Replace the `$writeActions` line with:

```php
    $kinds = implode(', ', array_map(fn (QuestionKind $kind) => "`{$kind->value}`", QuestionKind::cases()));
    $writeActions = "Write what you did with each point to `{$files['actions']}` as a JSON list of `{claim, disposition, note}`, `disposition` one of {$dispositions}, plus `kind` on an `open-question`, one of {$kinds} (engine.md §Open questions), and `[]` when you acted on nothing; `record` completes the open entry from it.";
```

Replace `$actOnReview`'s third item, `'Carry anything unresolved verbatim as an open question.',`, with:

```php
        'Carry anything unresolved verbatim as an open question with its kind: `blocking` when the answer changes this PR\'s code, `follow-up` for work outside it, `remark` for a note on a choice already made; `blocking` when unsure. A `blocking` question names its options in `note`, the one the PR built first (engine.md §Open questions).',
```

In `'verify-ui:run'`, add after the `'Write the proof page …'` item:

```php
            'A before state the base cannot render is left out, and is an open question of kind `remark` in `openQuestions`, whose items are `{kind, question}` (engine.md §The proof store).',
```

In `'review-pr:resolve'`, add after `...$actOnReview,`:

```php
            'Under the PR body\'s `## Open questions`, one line per open question led by its kind (`- **blocking:** …`), or `None.`; a question a settled `Answer to open question` decision answers is no longer open.',
```

and in its `'Write the proof page …'` item replace `the final open questions and ledger;` with
`the final open questions, each `{kind, question}`, and the ledger;` (the PHP string is single-quoted; the
backticks need no escaping).

In `pipeline_brief_overrides()`, after the conflict round's `if`:

```php
    if ($leg === 'review-pr' && pipeline_decisions_starting($manifest, PIPELINE_ANSWER) > 0) {
        $lines[] = pipeline_answer_round_line($step);
    }
```

After `pipeline_conflict_round_line()`:

```php
/** An owner's answer to a `blocking` open question (engine.md §Open questions): one that departs from what the PR built is a finding of `review-pr`. */
function pipeline_answer_round_line(string $step): string
{
    return $step === 'review'
        ? 'A settled `Answer to open question` decision whose answer departs from what the PR built is a finding of this review: name what it changes (engine.md §Open questions).'
        : 'Integrate each settled `Answer to open question` decision that departs from what the PR built, and name it in `actions`; the question it answers is no longer open (engine.md §Open questions).';
}
```

In `skills/pipeline/references/engine.md`, insert this section after §Resolving a review, right before
`## Failure policy — what still stops`:

````markdown
## Open questions — a kind each, and when each reaches the owner

On IT4WEBBV/Deploy #480 the run finished, the CI gate went green, `gh pr ready` ran and the proof page
opened; only then were the two open questions from the PR body asked, and the owner had already merged.
Both were remarks on a choice already made, not forks. Before #146 nothing told a fork from a remark, and
no command read open questions. Now every open question carries a kind, and the kind says when it
reaches the owner:

| Kind | Meaning | When it reaches the owner |
|---|---|---|
| `blocking` | a real fork: the answer changes this PR's code | before `gh pr ready` and before the proof is presented: `finish` answers `ask`, and the session asks it with `AskUserQuestion` right away; the PR goes ready only once no `blocking` question is open |
| `follow-up` | work outside this PR | after the report, once, as one batched question (*file an issue* / *drop*), or filed directly |
| `remark` | a note on a choice already made | never asked; recorded in the PR body only |

- **Who writes the kind.** Both resolve steps, on every `open-question` action (`manifest.md`,
  `actions[].kind`); `record` refuses an open question without one. Unsure is `blocking`. A `blocking`
  question names its options in `note`, the one the PR built first. An action written before kinds
  existed reads as `blocking`. In the PR body, under `## Open questions`, each open question is one line
  led by its kind (`- **blocking:** …`), or the section says `None.`; on the proof page each is
  `{kind, question}` (§The proof store).
- **The id and the answer.** A question's id is its place in the ledger, `gate_ledger[<i>].actions[<j>]`:
  entries are append-only and a completed entry's actions are never rewritten, so the id holds for the
  run's life. An answer is a `decisions` entry that starts `Answer to open question <id>`, written by
  `launch --decision` (`../checks/questions.php`). A question is open while no decision starts with its
  prefix, so a mistyped prefix leaves it open and the gate asks again. Every question counts,
  `plan-approval` ones included; one a later loop-back made moot is answered by keeping what was built.
  A `plan-approval` question is asked only after the run built one branch of its fork through
  `review-pr`, so an answer that picks the other branch goes through the detour below and may spend a
  `pr-review` loop-back.
- **`finish` answers `ask`** while a `blocking` question is unanswered:
  `{"action":"ask","proof":…,"questions":[{id, gate, kind, question, note, decision}],"followUps":[{question, note}]}`,
  and otherwise `done` with `followUps` too (`[]` when there are none). The cursor says `done` either
  way: the workflow is finished, and what is left is the session's. `decision` is the text the session
  completes with the owner's answer.
- **The CI gate is the backstop.** In `autoflow`, while any `blocking` question is unanswered, `ci`
  answers `{"action":"ask","questions":[…]}` before it reads git or gh (§The CI gate), so after a resume,
  a skipped ask or a mistyped answer, `ready` stays out of reach. `interactive` has neither `ask`: its
  finish step has the human at hand, who settles the questions there.
- **The session's sequence on `ask`** (`../SKILL.md` §`autoflow` step 4; orchestrate
  `references/commands.md` §Finish):
  1. Ask every question in one `AskUserQuestion`: 2–4 options per question from its `note`, the one the
     PR built first, recommendation first.
  2. Record each answer: the question's `decision` with the owner's answer appended, as `--decision`.
     Append the same lines at the end of the PR body (fetch the body, append, `gh pr edit --body-file`,
     as a halt's reason is appended), below the `## Open questions` section a fix round rewrites, so the
     answer outlives the disposable manifest.
  3. Every answer keeps what the PR built: `launch <manifest> "<manifest stem>.diff" --decision "…"`…
     answers `done`; then the CI gate. Any answer changes the code: `launch <manifest> "<manifest
     stem>.diff" --from review-pr --decision "…"`… with all the answers, and a new workflow through the
     detour. Its review step names what an answer changes as a finding, its resolve step integrates it,
     and its `finish` may ask again about new `blocking` questions. The cycle is owner-paced, so it has
     no bound.
- **`follow-up`s** are listed once in the report with the ready PR (`finish`'s `followUps`), then asked
  as one batched *file an issue* / *drop* question, or filed directly. A **`remark`** is never asked.
- **A design that departs from a mockup** (#145, when it lands) records the departure as a `blocking`
  open question.
````

In §Stations' `review-pr` row, replace `the finalised open questions and gate ledger |` with
`the finalised open questions, each with its kind, and the gate ledger |`.

In §Resolving a review, replace the bullet

```markdown
- **Never interrupt on a finding.** Anything unresolved goes into the PR body as an open question,
  carried **verbatim**. Ambiguity buys a line in the PR, not an interrupt.
```

with

```markdown
- **Never interrupt on a finding.** Anything unresolved goes into the PR body as an open question with
  its kind, carried **verbatim** (§Open questions). Ambiguity buys a line in the PR, not an interrupt:
  the run is still never interrupted mid-workflow, and a `blocking` question is asked once the workflow
  returns.
```

- [ ] **Step 4: Run the tests to see them pass**

Run: `php -l skills/pipeline/checks/brief.php && ./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "BriefTest|LockStepTest|DispatchCliTest"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/brief.php skills/pipeline/checks/tests/BriefTest.php skills/pipeline/checks/tests/LockStepTest.php skills/pipeline/references/engine.md
git commit -m "feat(pipeline): resolve steps give each open question a kind, and an owner's answer is a review-pr finding (#146)"
```

---

### Task 4: `finish` answers `ask` while a `blocking` question is unanswered

**Files:**
- Modify: `skills/pipeline/checks/dispatch_cli.php` (`dispatch_cli_finish()`; new `dispatch_cli_finished()`)
- Modify: `skills/pipeline/checks/tests/DispatchCliTest.php`
- Modify: `skills/pipeline/references/engine.md` (§`autoflow`'s command block and its `finish` bullet)
- Modify: `skills/pipeline/references/manifest.md` (the `decisions` row)

**Interfaces:**
- Consumes: `pipeline_unanswered()`, `pipeline_follow_ups()` (Task 1); `dispatch_cli_done(string $manifestPath, array $manifest): array` (unchanged; `returned` keeps using it).
- Produces: `dispatch_cli_finished(string $manifestPath, array $manifest): array` — `finish`'s answer for a
  `done` that holds: `['action' => 'ask', 'proof', 'questions', 'followUps']` or `['action' => 'done', 'proof', 'followUps']`.

- [ ] **Step 1: Write the failing tests**

In `skills/pipeline/checks/tests/DispatchCliTest.php`, in `it('finishes only after a review-pr resolve step whose return holds', …)`,
change the last-but-one expectation to:

```php
    expect($finish())->toBe(['action' => 'done', 'proof' => null, 'followUps' => []]);
```

Add after that test:

```php
/** A finished `autoflow` run's review-pr resolve step that completed its entry with `$actions`; its `finish` answer. */
function finish_with_actions(array $actions): array
{
    $open = boundary_open('pr-review');
    $fixture = boundary_fixture('review-pr', 'resolve', ['cursor' => ['leg' => 'review-pr', 'status' => 'pending'], 'gate_ledger' => [$open]]);
    dispatch_leg_writes($fixture['manifest'], fn (array $m) => [...$m, 'cursor' => ['leg' => 'review-pr', 'status' => 'continued'], 'gate_ledger' => [[...$open, 'actions' => $actions, 'outcome' => 'continued']]]);

    return [...$fixture, 'answer' => dispatch_cli(['finish', $fixture['manifest'], '{"action":"done"}'])['json']];
}

it('answers ask on a done run with an unanswered blocking question, the cursor done all the same (#146)', function () {
    $finished = finish_with_actions([
        ['claim' => 'Keep the <x-time> tag?', 'disposition' => 'open-question', 'note' => '<x-time> (built), a plain div', 'kind' => 'blocking'],
        ['claim' => 'File the date-format cleanup?', 'disposition' => 'open-question', 'note' => 'outside this PR', 'kind' => 'follow-up'],
        ['claim' => 'self-end alignment', 'disposition' => 'open-question', 'note' => 'kept', 'kind' => 'remark'],
    ]);

    expect($finished['answer'])->toBe([
        'action' => 'ask', 'proof' => null,
        'questions' => [[
            'id' => 'gate_ledger[0].actions[0]', 'gate' => 'pr-review', 'kind' => 'blocking', 'question' => 'Keep the <x-time> tag?', 'note' => '<x-time> (built), a plain div',
            'decision' => 'Answer to open question gate_ledger[0].actions[0] ("Keep the <x-time> tag?"): ',
        ]],
        'followUps' => [['question' => 'File the date-format cleanup?', 'note' => 'outside this PR']],
    ]);
    expect(manifest_read($finished['manifest'])['cursor'])->toBe(['leg' => 'review-pr', 'status' => 'done']);
});

it('answers done with the follow-ups on a run with only follow-up and remark questions, and done after an answer launch recorded (#146)', function () {
    $followUp = ['claim' => 'File the date-format cleanup?', 'disposition' => 'open-question', 'note' => 'outside this PR', 'kind' => 'follow-up'];
    $remark = ['claim' => 'self-end alignment', 'disposition' => 'open-question', 'note' => 'kept', 'kind' => 'remark'];

    expect(finish_with_actions([$followUp, $remark])['answer'])
        ->toBe(['action' => 'done', 'proof' => null, 'followUps' => [['question' => 'File the date-format cleanup?', 'note' => 'outside this PR']]]);

    $asked = finish_with_actions([['claim' => 'Queue or cron?', 'disposition' => 'open-question', 'note' => 'cron (built), queue', 'kind' => 'blocking']]);
    $decision = $asked['answer']['questions'][0]['decision'] . 'cron, as built';
    expect(dispatch_cli(['launch', $asked['manifest'], $asked['diff'], '--decision', $decision])['json'])->toBe(['action' => 'done']);
    expect(manifest_read($asked['manifest']))->toMatchArray(['cursor' => ['leg' => 'review-pr', 'status' => 'done'], 'decisions' => [$decision]]);
    expect(pipeline_unanswered(manifest_read($asked['manifest'])))->toBe([]);
});
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter DispatchCliTest`
Expected: FAIL — `finish` answers `['action' => 'done', 'proof' => null]`: no `followUps`, no `ask`.

- [ ] **Step 3: Write the implementation**

`dispatch_cli.php` has `questions.php` through `record.php` (Task 1); it needs no require of its own.

In `skills/pipeline/checks/dispatch_cli.php`, in `dispatch_cli_finish()`, replace

```php
        return $problem === null ? dispatch_cli_done($manifestPath, $manifest) : dispatch_cli_halt($manifestPath, $manifest, $leg, $problem);
```

with

```php
        return $problem === null ? dispatch_cli_finished($manifestPath, $manifest) : dispatch_cli_halt($manifestPath, $manifest, $leg, $problem);
```

and add after `dispatch_cli_done()`:

```php
/**
 * An `autoflow` `done` that holds: recorded as done, then `ask` while a `blocking` open question is unanswered,
 * so the session asks the owner before the CI gate, else `done`; both carry the `follow-up` questions for the
 * report (`../references/engine.md` §Open questions).
 */
function dispatch_cli_finished(string $manifestPath, array $manifest): array
{
    $done = dispatch_cli_done($manifestPath, $manifest);
    $questions = pipeline_unanswered($manifest);
    $followUps = pipeline_follow_ups($manifest);

    return $questions === []
        ? [...$done, 'followUps' => $followUps]
        : ['action' => 'ask', 'proof' => $done['proof'], 'questions' => $questions, 'followUps' => $followUps];
}
```

The file's usage docblock lists commands, not answers: it does not change.

In `skills/pipeline/references/engine.md` §`autoflow`, replace the `finish` answer comment

```bash
# → {"action":"done","proof":<artifacts.proof, or null>} | {"action":"halt","reason":…} | {"action":"halt","reason":"relay: …","relaunch":true}, once
```

with

```bash
# → {"action":"done","proof":<artifacts.proof, or null>,"followUps":[…]} | {"action":"ask","proof":…,"questions":[…],"followUps":[…]} (§Open questions)
#   | {"action":"halt","reason":…} | {"action":"halt","reason":"relay: …","relaunch":true}, once
```

and in the **`finish`** bullet, after the sentence ending `on a halt after `handoff`, §Failure policy's duties.` (a line break after `handoff`,), insert:

```markdown
  A `done` that holds answers `ask` instead while a `blocking` open question is unanswered: the cursor
  still says `done`, and the session asks the owner and records the answers before the CI gate runs
  (§Open questions). Both answers carry `followUps`, the `follow-up` questions for the report.
```

In `skills/pipeline/references/manifest.md`, in the `decisions` row, replace
`or one of the CI gate's three records, a red CI, an unreviewed merge or a conflict with the base (`engine.md` §The CI gate).`
with
`one of the CI gate's three records, a red CI, an unreviewed merge or a conflict with the base (`engine.md` §The CI gate), or an owner's answer to a `blocking` open question, starting `Answer to open question <id>` (`engine.md` §Open questions).`

- [ ] **Step 4: Run the tests to see them pass**

Run: `php -l skills/pipeline/checks/dispatch_cli.php && ./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "DispatchCliTest|ReturnedTest|LockStepTest"`
Expected: PASS. `it('names the proof page in the done answer, so the session can mark it ready', …)` still
expects `['action' => 'done', 'proof' => $page]`: that is `returned`, which keeps `dispatch_cli_done()`.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/dispatch_cli.php skills/pipeline/checks/tests/DispatchCliTest.php skills/pipeline/references/engine.md skills/pipeline/references/manifest.md
git commit -m "feat(pipeline): finish answers ask while a blocking open question is unanswered, and lists the follow-ups (#146)"
```

---

### Task 5: The CI gate answers `ask` before it reads anything

**Files:**
- Modify: `skills/pipeline/checks/ci.php` (`pipeline_ci_answer()`, new `pipeline_ci_ask()`)
- Modify: `skills/pipeline/checks/dispatch_cli.php` (`dispatch_cli_ci()`)
- Modify: `skills/pipeline/checks/tests/CiTest.php`
- Modify: `skills/pipeline/checks/tests/DispatchCliTest.php` (`ci_fixture()` gains `$extra`; new cases)
- Modify: `skills/pipeline/references/engine.md` (§The CI gate)

**Interfaces:**
- Consumes: `pipeline_unanswered()` (Task 1).
- Produces:
  - `pipeline_ci_ask(array $unanswered): array` — `['action' => 'ask', 'questions' => $unanswered]`
  - `pipeline_ci_answer(array $manifest, ?array $view, string $head, bool $workflows, int $poll, array $unreviewed = [], array $unanswered = []): array`
    answers `pipeline_ci_ask($unanswered)` before anything else when `$unanswered` is not empty.

- [ ] **Step 1: Write the failing tests**

In `skills/pipeline/checks/tests/CiTest.php`, add after
`it('answers one review round for a merge the last review did not see, …', …)`:

```php
it('answers ask while a blocking open question is unanswered, before a merge round, a mismatch, an unreadable PR or a green head (#146)', function () {
    $questions = [[
        'id' => 'gate_ledger[0].actions[0]', 'gate' => 'pr-review', 'kind' => 'blocking', 'question' => 'Queue or cron?', 'note' => 'cron (built), queue',
        'decision' => 'Answer to open question gate_ledger[0].actions[0] ("Queue or cron?"): ',
    ]];
    $ask = ['action' => 'ask', 'questions' => $questions];
    $green = ci_view([ci_run('ci', 'COMPLETED', 'SUCCESS')]);

    expect(pipeline_ci_answer(ci_manifest(), $green, 'def456', true, 1, ['a.php'], $questions))->toBe($ask);
    expect(pipeline_ci_answer(ci_manifest(), $green, 'def456', true, 3, [], $questions))->toBe($ask);
    expect(pipeline_ci_answer(ci_manifest(), null, 'abc123', true, 120, [], $questions))->toBe($ask);
    expect(pipeline_ci_answer(ci_manifest(), $green, 'abc123', true, 1, [], $questions))->toBe($ask);
    expect(pipeline_ci_answer(ci_manifest(), $green, 'abc123', true, 1, [], []))->toBe(['action' => 'ready', 'verdict' => 'green', 'sha' => 'abc123']);
});
```

In `skills/pipeline/checks/tests/DispatchCliTest.php`, give `ci_fixture()` a last parameter laid over its
manifest:

```php
function ci_fixture(?array $view, bool $workflows = true, array $decisions = [], ?string $head = 'abc123', array $extra = []): array
{
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'review-pr', 'status' => 'done'], 'artifacts' => ['spec' => null, 'plan' => null, 'pr' => 7, 'issue' => null], 'decisions' => $decisions, ...$extra]);
```

(the rest of the function unchanged), and add after
`it('answers the merge round for an autoflow run whose finish step merged a shared file, …', …)`:

```php
/**
 * A completed pr-review entry whose resolve step left one blocking open question, `gate_ledger[0].actions[0]`. It has
 * no `reviewed_sha`, so the merge round finds no review to scope from (`pipeline_review_base()`) and stays out of it.
 */
function ci_asking_ledger(): array
{
    return ['gate_ledger' => [[
        'gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => '2026-10-02T10:00:00Z', 'review' => 'r',
        'actions' => [['claim' => 'Queue or cron?', 'disposition' => 'open-question', 'note' => 'cron (built), queue', 'kind' => 'blocking']],
        'outcome' => 'continued',
    ]]];
}

it('answers ask on an unanswered autoflow run without asking git or gh, and ready once launch recorded the answer (#146)', function () {
    $fixture = ci_fixture(ci_head('SUCCESS'), true, [], null, ci_asking_ledger());
    $decision = 'Answer to open question gate_ledger[0].actions[0] ("Queue or cron?"): ';

    $asked = ci_gate($fixture)['json'];
    expect($asked['action'])->toBe('ask');
    expect(array_column($asked['questions'], 'decision'))->toBe([$decision]);
    expect(is_file($fixture['dir'] . '/calls'))->toBeFalse();

    file_put_contents($fixture['dir'] . '/head', 'abc123');
    expect(dispatch_cli(['launch', $fixture['manifest'], $fixture['diff'], '--decision', "{$decision}cron, as built"])['json'])->toBe(['action' => 'done']);
    expect(ci_gate($fixture)['json'])->toBe(['action' => 'ready', 'verdict' => 'green', 'sha' => 'abc123']);
});

it('asks again after an answer recorded under a mistyped prefix, and never in an interactive run (#146)', function () {
    $typo = ci_fixture(ci_head('SUCCESS'), true, ['Answer to open question gate_ledger[0].action[0] ("Queue or cron?"): cron'], 'abc123', ci_asking_ledger());
    expect(ci_gate($typo)['json']['action'])->toBe('ask');

    $interactive = ci_fixture(ci_head('SUCCESS'), true, [], 'abc123', ['mode' => 'interactive', ...ci_asking_ledger()]);
    expect(ci_gate($interactive)['json'])->toBe(['action' => 'ready', 'verdict' => 'green', 'sha' => 'abc123']);
});
```

The first case's `$head` is `null`: the fake git fails, so an answer other than a halt on HEAD proves git
was not asked; no `calls` file proves gh was not.

- [ ] **Step 2: Run the tests to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "CiTest|DispatchCliTest"`
Expected: FAIL — `pipeline_ci_answer()` ignores the seventh argument and answers `fix`, `halt`, `wait` or
`ready`; `ci` halts on the unreadable HEAD instead of asking.

- [ ] **Step 3: Write the implementation**

In `skills/pipeline/checks/ci.php`, extend `pipeline_ci_answer()`'s docblock with one sentence at its end —
`` `$unanswered` the `blocking` open questions no decision answers (`pipeline_unanswered()`), which the owner answers before anything else is read (`../references/engine.md` §Open questions). `` —
change its signature and first lines to:

```php
function pipeline_ci_answer(array $manifest, ?array $view, string $head, bool $workflows, int $poll, array $unreviewed = [], array $unanswered = []): array
{
    if ($unanswered !== []) {
        return pipeline_ci_ask($unanswered);
    }
    if ($unreviewed !== [] && pipeline_merge_rounds($manifest) === 0) {
```

(the rest unchanged), and add before `pipeline_ci_mismatch()`:

```php
/** A `blocking` open question no decision answers keeps the PR draft: the session asks the owner, records the answers and runs the gate again. */
function pipeline_ci_ask(array $unanswered): array
{
    return ['action' => 'ask', 'questions' => $unanswered];
}
```

In `skills/pipeline/checks/dispatch_cli.php`, `dispatch_cli_ci()`: after the `artifacts.pr` check and before
`$worktree = …`, add:

```php
    $unanswered = $manifest['mode'] === 'autoflow' ? pipeline_unanswered($manifest) : [];
    if ($unanswered !== []) {
        return pipeline_ci_ask($unanswered);
    }
```

and extend its docblock's first sentence: `… and what the session does next; while a `blocking` open
question is unanswered in an `autoflow` run, `ask` before git or gh is read.` (`pipeline_ci_answer()` is
called as today, without the seventh argument: the questions were answered above.)

In `skills/pipeline/references/engine.md` §The CI gate:

- In the paragraph starting `- **One gate, in the session that runs `gh pr ready`:**`, replace
  `the invoking session once `finish` prints `done`` with
  `the invoking session once `finish` prints `done`, or `ask` and every answer is recorded,`.
- Add as the table's first row, above the `merge` row:

```markdown
| `ask`: a `blocking` open question no decision answers (`autoflow`) | `ask`, with the questions, before git or gh is read; it spends no round (§Open questions) |
```

- Add after the **`ready`** bullet:

```markdown
- **`ask`** → the questions go to the owner as on `finish`'s `ask` (§Open questions); once every answer
  is recorded, the gate runs again. The poll loop ends on it at the first read, as on every answer but
  `wait`. It is the backstop for a resume, a skipped ask or an answer recorded under a mistyped prefix.
```

- In the **In `interactive`** bullet, replace `there is no automatic round, no merge round and no conflict round:`
  with `there is no automatic round, no merge round, no conflict round and no `ask`:`.

- [ ] **Step 4: Run the tests to see them pass**

Run: `php -l skills/pipeline/checks/ci.php && php -l skills/pipeline/checks/dispatch_cli.php && ./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "CiTest|DispatchCliTest|LockStepTest"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/ci.php skills/pipeline/checks/dispatch_cli.php skills/pipeline/checks/tests/CiTest.php skills/pipeline/checks/tests/DispatchCliTest.php skills/pipeline/references/engine.md
git commit -m "feat(pipeline): the CI gate answers ask while a blocking open question is unanswered (#146)"
```

---

### Task 6: The proof page's open questions are `{kind, question}`

**Files:**
- Modify: `skills/pipeline/checks/proof.php` (requires `questions.php`; new `proof_open_questions_problems()`, `proof_open_question_problem()`)
- Modify: `skills/pipeline/checks/proof_cli.php` (`proof_cli_write()`)
- Modify: `skills/pipeline/checks/proof_render.php` (`proof_render_open_questions()` replaces `proof_render_list()`)
- Modify: `skills/pipeline/checks/tests/ProofWriteTest.php`, `skills/pipeline/checks/tests/ProofRenderTest.php`, `skills/pipeline/checks/tests/LockStepTest.php`
- Modify: `skills/pipeline/references/engine.md` (§The proof store)

**Interfaces:**
- Consumes: `QuestionKind`, `QuestionKind::listed()`, `QuestionKind::label()` (Task 1).
- Produces: `proof_open_questions_problems(array $payload): array` (`list<string>`); `proof_render_open_questions(array $items): string`.

- [ ] **Step 1: Write the failing tests**

In `skills/pipeline/checks/tests/ProofWriteTest.php`, in
`it('keeps what an earlier write filed when a later one leaves it out', …)`, replace the three
`openQuestions` values: the first write's `['a', 'b']` with
`[['kind' => 'remark', 'question' => 'a'], ['kind' => 'remark', 'question' => 'b']]`, the second write's and
the expectation's `['c']` with `[['kind' => 'follow-up', 'question' => 'c']]`. Then add:

```php
it('files nothing for open questions that are not {kind, question}, and names each item (#146)', function () {
    $root = sys_get_temp_dir() . '/proof-write-' . uniqid();
    mkdir($root);

    $result = proof_write_cli(proof_write_payload(['openQuestions' => [
        ['kind' => 'blocking', 'question' => 'Keep the guard?'],
        'A string from before kinds',
        ['kind' => 'urgent', 'question' => 'Rename it?'],
        ['kind' => 'remark', 'question' => ' '],
        ['question' => 'No kind'],
    ]]), $root);

    expect($result['stdout'])->not->toContain("{$root}/Deploy/");
    expect($result['stderr'])->toContain('proof: payload rejected')
        ->toContain('openQuestions[1] has no question')
        ->toContain('openQuestions[2] has no kind: blocking, follow-up, remark')
        ->toContain('openQuestions[3] has no question')
        ->toContain('openQuestions[4] has no kind: blocking, follow-up, remark')
        ->not->toContain('openQuestions[0]');
    expect(is_dir("{$root}/Deploy"))->toBeFalse();
});

it('files a later write over a run filed before kinds, keeping its string questions, when the write leaves them out (#146)', function () {
    $root = sys_get_temp_dir() . '/proof-write-' . uniqid();
    proof_write_run("{$root}/Deploy/pr-5-logs", [...proof_write_payload(), 'openQuestions' => ['Filed before kinds']], date('c'));

    $result = proof_write_cli(proof_write_payload(['headline' => 'Logs follow']), $root);

    expect($result['stdout'])->toContain("{$root}/Deploy/pr-5-logs/index.html");
    expect(proof_write_stored($root)['openQuestions'])->toBe(['Filed before kinds']);
    expect((string) file_get_contents("{$root}/Deploy/pr-5-logs/index.html"))->toContain('<li>Filed before kinds</li>');
});
```

In `skills/pipeline/checks/tests/ProofRenderTest.php`, add after
`it('renders open questions verbatim and flags suppressions as not yet judged', …)` (which keeps its string
item: a run filed before kinds renders as today):

```php
it('labels each open question with its kind, and a string filed before kinds without one (#146)', function () {
    $html = proof_render_run(proof_fixture_run(['openQuestions' => [
        ['kind' => 'blocking', 'question' => 'Keep the <x-time> tag?'],
        ['kind' => 'follow-up', 'question' => 'File the cleanup?'],
        ['kind' => 'remark', 'question' => 'self-end alignment'],
        'Filed before kinds.',
    ]]));

    expect($html)->toContain("<h2>Open questions</h2>\n<ul>\n")
        ->toContain('<li><strong>Blocking:</strong> Keep the &lt;x-time&gt; tag?</li>')
        ->toContain('<li><strong>Follow-up:</strong> File the cleanup?</li>')
        ->toContain('<li><strong>Remark:</strong> self-end alignment</li>')
        ->toContain('<li>Filed before kinds.</li>');
});
```

In `skills/pipeline/checks/tests/LockStepTest.php`, in
`it('keeps engine.md §The proof store in lock-step with the fields the store files and checks', …)`, add
`...array_column(QuestionKind::cases(), 'value')` to the `foreach` list, after
`...array_column(ProofShotState::cases(), 'value')`.

- [ ] **Step 2: Run the tests to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "ProofWriteTest|ProofRenderTest|LockStepTest"`
Expected: FAIL — the bad payload is filed; the label test sees `Array` with a warning; the proof store
section names no kind.

- [ ] **Step 3: Write the implementation**

In `skills/pipeline/checks/proof.php`, add after the file's docblock:

```php
require_once __DIR__ . '/questions.php';
```

and after `proof_shot_state_problem()`:

```php
/**
 * What is wrong with a payload's `openQuestions`: each item an object with a non-empty `question` and a `kind`
 * (`../references/engine.md` §Open questions). Judged on the payload, not the run as filed, so a run filed before
 * kinds, with string items, is not refused on a write that leaves `openQuestions` out.
 *
 * @return list<string>
 */
function proof_open_questions_problems(array $payload): array
{
    $items = array_values((array) ($payload['openQuestions'] ?? []));

    return array_values(array_filter(array_map(proof_open_question_problem(...), array_keys($items), $items)));
}

function proof_open_question_problem(int $index, mixed $item): ?string
{
    return match (true) {
        ! is_array($item) || ! is_string($item['question'] ?? null) || trim($item['question']) === '' => "openQuestions[{$index}] has no question",
        ! is_string($item['kind'] ?? null) || QuestionKind::tryFrom($item['kind']) === null => "openQuestions[{$index}] has no kind: " . QuestionKind::listed(),
        default => null,
    };
}
```

In `skills/pipeline/checks/proof_cli.php`, `proof_cli_write()`, replace the `proof_store_file(…)` call's rule with:

```php
    $filed = proof_store_file($payload, date('c'), fn (array $run): array => [
        ...proof_validate_run($run),
        ...proof_validate_prose($run),
        ...proof_open_questions_problems($payload),
    ]);
```

In `skills/pipeline/checks/proof_render.php`, replace `proof_render_list()` (its only caller is the line
below) with:

```php
/** Each open question with its kind's label before the text; an item filed before kinds, a string, without one. */
function proof_render_open_questions(array $items): string
{
    if ($items === []) {
        return '';
    }
    $out = "<h2>Open questions</h2>\n<ul>\n";
    foreach ($items as $item) {
        $out .= '<li>' . proof_render_open_question($item) . "</li>\n";
    }

    return $out . "</ul>\n";
}

/** A stored item passed `proof_open_questions_problems()` when it was filed, so an object's kind is one of `QuestionKind`. */
function proof_render_open_question(string|array $item): string
{
    return is_string($item)
        ? proof_e($item)
        : '<strong>' . proof_e(QuestionKind::from($item['kind'])->label()) . ':</strong> ' . proof_e($item['question']);
}
```

and replace `$body .= proof_render_list('Open questions', $run['openQuestions'] ?? []);` with
`$body .= proof_render_open_questions($run['openQuestions'] ?? []);`.

In `skills/pipeline/references/engine.md` §The proof store:

- Replace the row `| `openQuestions` | list, carried verbatim |` with

```markdown
| `openQuestions` | list of `{kind, question}`: `question` verbatim, `kind` one of `blocking`, `follow-up`, `remark` (§Open questions), shown as a label before the text. `write` refuses a payload with any other item; a run filed before kinds keeps its string items, shown without a label |
```

- In the finish step's item (`3. **The finish step** …`), replace `and the final open questions
  and ledger.` with `and the final open questions, each with its kind, and the ledger.` (the phrase spans a
  line break in the file).
- In *Before, after and defect shots*, replace `the before shot is left out and that is an open question, never a halt.`
  with `the before shot is left out and that is an open question of kind `remark`, never a halt.`

- [ ] **Step 4: Run the tests to see them pass**

Run: `php -l skills/pipeline/checks/proof.php && php -l skills/pipeline/checks/proof_cli.php && php -l skills/pipeline/checks/proof_render.php && ./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "Proof|LockStepTest|HandoffCliTest|RunCostTest"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/proof.php skills/pipeline/checks/proof_cli.php skills/pipeline/checks/proof_render.php skills/pipeline/checks/tests/ProofWriteTest.php skills/pipeline/checks/tests/ProofRenderTest.php skills/pipeline/checks/tests/LockStepTest.php skills/pipeline/references/engine.md
git commit -m "feat(pipeline): the proof page's open questions carry a kind, shown as a label (#146)"
```

---

### Task 7: The session's docs: pipeline `SKILL.md` and orchestrate

**Files:**
- Modify: `skills/pipeline/SKILL.md` (§`autoflow` steps 4–6)
- Modify: `skills/orchestrate/SKILL.md` (steps 2 and 5)
- Modify: `skills/orchestrate/references/commands.md` (§Finish)

**Interfaces:**
- Consumes: `finish`'s `ask` / `done` with `followUps` (Task 4); the gate's `ask` (Task 5); engine.md §Open questions (Task 3).
- Produces: nothing code reads.

- [ ] **Step 1: Find the texts to change**

Run: `grep -n "relaunch: true\|finish. printed .done.: the CI gate\|^6\. \*\*Report\*\*" skills/pipeline/SKILL.md; grep -n "fewer than 4 runs\|Open questions: ask only a genuine fork" skills/orchestrate/SKILL.md; grep -n "only when finish printed done\|^The CI gate (pipeline" skills/orchestrate/references/commands.md`
Expected: one line for each phrase.

- [ ] **Step 2: pipeline `SKILL.md`**

Step 4: after its paragraph that ends `A `relay:` halt without `relaunch` is a halt like any other.`, add:

```markdown
   **`ask`** (a `blocking` open question is unanswered; `references/engine.md` §Open questions): the run
   is done but the PR stays draft. Ask every question in one `AskUserQuestion`, 2–4 options each from its
   `note`, the one the PR built first, recommendation first. Record each answer as the question's
   `decision` with the answer appended, and append the same lines to the PR body (`gh pr view <pr> --json
   body`, append, `gh pr edit <pr> --body-file`). When every answer keeps what the PR built: the diff as
   in step 2, `launch <manifest> "<manifest stem>.diff" --decision "…"`… (it answers `done`), then step 5.
   When any changes the code: `launch <manifest> "<manifest stem>.diff" --from review-pr --decision "…"`…
   with all the answers, and steps 3–5 again.
```

Step 5: replace `5. **`finish` printed `done`: the CI gate**` with
`5. **`finish` printed `done`, or `ask` and every answer is recorded: the CI gate**`, and add the sentence
`**`ask`:** as step 4's `ask`.` right after the `fix` sentence, which ends `and steps` on one line and
`3–5 again.` on the next, before `**`halt`:**`.

Step 6: after `6. **Report** the result, naming the proof page (the `proof` `finish` printed),` insert
`listing `finish`'s `followUps` once and then asking them as one batched *file an issue* / *drop* question, or filing them directly (a `remark` is never asked),`.

- [ ] **Step 3: orchestrate `SKILL.md`**

Step 2: replace `(a run in its CI gate counts; a PR awaiting merge does not)` with
`(a run in its CI gate counts; a run waiting on an answer to its open questions and a PR awaiting merge do not)`.

Step 5: replace `when `finish` prints `done`, run the CI gate` with
`when `finish` prints `done`, or `ask` and every answer is recorded, run the CI gate`, and replace the
sentences

```markdown
Open questions: ask only a genuine fork (two paths that ship different code), in one batched `AskUserQuestion`, 2–4 options, recommendation first. Decide remarks and mechanical calls; report them.
```

with

```markdown
Open questions carry a kind (pipeline `engine.md` §Open questions): on `finish`'s or the gate's `ask`, the `blocking` ones go into the batched `AskUserQuestion`, 2–4 options each, recommendation first, and the CI gate runs only once every answer is recorded (commands §Finish); `follow-up`s are listed once with the ready PR, then asked as one batched *file an issue* / *drop* question, or filed directly; a `remark` is never asked. Decide mechanical calls; report them.
```

- [ ] **Step 4: orchestrate `references/commands.md` §Finish**

In the code block, replace the gate line's comment `# only when finish printed done; run_in_background` with
`# only when finish printed done, or ask and every answer is recorded; run_in_background`. Before the paragraph
starting `The CI gate (pipeline `engine.md` §The CI gate) runs in one background Bash`, add:

```markdown
**`ask`** (pipeline `engine.md` §Open questions): `finish` printed `ask` because a `blocking` open
question is unanswered; the manifest says done and the PR stays draft. Its `questions` go into the
batched `AskUserQuestion` (Step 5, *Ask last*), and no gate runs yet. Record each answer as the question's
`decision` with the answer appended, and append those lines to the PR body (`gh pr view <P> -R <repo>
--json body`, append, `gh pr edit <P> -R <repo> --body-file`). Every answer keeps what the PR built: the
diff, `launch <manifest> <manifest stem>.diff --decision "…"`… (it answers `done`), then the gate. Any
answer changes the code: the diff, `launch <manifest> <manifest stem>.diff --from review-pr --decision
"…"`… with all the answers, then a new `pipeline-autoflow` workflow, as §Launch, in the dispatch record.
The gate answers `ask` too while one is open: the same. `followUps` (with `done` and `ask`): listed once
in the ready report, then one batched *file an issue* / *drop* question, or filed directly.
```

- [ ] **Step 5: Run the whole suite and the orchestrate tests**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests && sh skills/orchestrate/tests/needs_input_test.sh && sh skills/orchestrate/tests/owners_test.sh`
Expected: PASS; no test pins the replaced orchestrate sentences.

- [ ] **Step 6: Commit**

```bash
git add skills/pipeline/SKILL.md skills/orchestrate/SKILL.md skills/orchestrate/references/commands.md
git commit -m "docs(pipeline): the session asks blocking open questions before the CI gate, and reports follow-ups once (#146)"
```
