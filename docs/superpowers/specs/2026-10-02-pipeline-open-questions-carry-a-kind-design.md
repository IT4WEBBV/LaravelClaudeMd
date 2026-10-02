# Open questions carry a kind; blocking ones are asked before the PR goes ready — design

**Design size:** Architectural

**Date:** 2026-10-02
**Issue:** IT4WEBBV/LaravelClaudeMd#146
**Canonical home:** `skills/pipeline/checks/questions.php` (new: the kinds, which questions are open,
the answer record), `skills/pipeline/checks/record.php` (an action's `kind`),
`skills/pipeline/checks/dispatch_cli.php` (`finish` answers `ask`; `ci` passes the open questions),
`skills/pipeline/checks/ci.php` (the gate answers `ask`), `skills/pipeline/checks/brief.php` (the
resolve steps' lines, the answer round's lines), `skills/pipeline/checks/proof.php` and
`proof_render.php` (`openQuestions` items carry `kind`), pipeline `references/engine.md` (new §Open
questions, §The CI gate, §The proof store, §Resolving a review), pipeline `references/manifest.md`
(`actions[].kind`, `decisions`), pipeline `SKILL.md` §`autoflow` steps 4–6, orchestrate `SKILL.md`
step 5 and `references/commands.md` §Finish.
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. The issue body is the approved
scope and is not re-litigated here; where this design departs from its letter, *Assumptions* says so and
why. Every question the brainstorm would have asked is answered in *Assumptions*, so `/critique plan`
audits exactly those. Nothing below was built or run; what it relies on was read (see *What was read*).

## Problem

As the issue states it: on IT4WEBBV/Deploy #480 the run finished, the CI gate went green, `gh pr ready`
ran and the proof page opened; only then were the two open questions from the PR body asked in the CLI.
The owner had already merged the PR. Both were remarks on a choice already made (`<x-time>` instead of a
plain `div`, `self-end` alignment), not forks, so they should not have been asked at all.

Confirmed in the code and docs:

- An open question is an action `{claim, disposition: "open-question", note}` on a resolve step's ledger
  entry (`checks/record.php`, `ActionDisposition::OpenQuestion`; `manifest.md` §gate_ledger), a free-form
  line under the PR body's `## Open questions`, and a string in the proof page's `openQuestions`
  (engine.md §The proof store: *list, carried verbatim*). Nothing distinguishes a fork from a remark.
- No command reads open questions. `finish` answers `done` (`dispatch_cli_finish()` →
  `dispatch_cli_done()`), the CI gate answers `ready` on a green head (`pipeline_ci_answer()`), and the
  invoking session runs `gh pr ready`. Whether the owner hears a question first is left to the session's
  memory: orchestrate `SKILL.md` step 5 says *ask only a genuine fork*, after the ready PR is reported.

## Settled by the owner

The issue's change, taken as the scope:

| Kind | Meaning | When it reaches the owner |
|---|---|---|
| `blocking` | a real fork: the answer changes this PR's code | before `gh pr ready` and before the proof is presented; asked with `AskUserQuestion` right after `finish`; an answer that needs changes runs `launch --from review-pr --decision "<answer>"`; the PR goes ready only once no `blocking` question is open |
| `follow-up` | work outside this PR | after the report, as one batched question (file an issue / drop), or filed directly |
| `remark` | a note on a choice already made | never asked; recorded in the PR body only |

`finish` (or the CI gate) answers `ask` instead of `ready` while a `blocking` question is open; the
report lists `follow-up` items once; an unclear kind is `blocking`. Done when the manifest and `run.json`
carry `kind` with checks tests, a run with a `blocking` question does not reach `gh pr ready` until it is
answered while one with only `follow-up` or `remark` goes ready as today, and the pipeline docs describe
the three kinds and their timing.

## Approaches

**Where an answer is recorded, so a check can tell an open question from an answered one.**

- *(recommended)* **A decision with a fixed prefix, through `launch --decision`.** An answer is a
  `decisions` entry that starts `Answer to open question gate_ledger[<i>].actions[<j>]`, the id of the
  action that asked it. `launch` already appends `--decision` texts verbatim, and does so on a finished
  run without `--from` as well: it writes them and answers `done`, re-arming nothing
  (`dispatch_cli_launch()`: the decisions write precedes the `manifest_finished()` check). So an answer
  that keeps what the PR built is `launch <manifest> <diff> --decision "<answer>"`, and one that changes
  the code is the issue's own `launch … --from review-pr --decision "<answer>"`. A question is open while
  no decision starts with its prefix: the same counting the CI gate's three rounds use
  (`pipeline_decisions_starting()`). No new verb, no rewrite of a closed ledger entry, and a mistyped
  prefix leaves the question open, so the gate asks again: it fails closed. `decisions` already reaches
  every later brief, so a fix round's steps see the answer.
- **A new `answer` verb that writes `answer` onto the ledger action.** Rejected: a second write path into
  the manifest beside `record` and `launch`, and the first that edits a completed ledger entry, which
  every boundary check treats as a halt-worthy rewrite. It would also need its own CLI parsing and tests
  for what `launch --decision` already does.
- **Ask in the CI gate only.** Rejected as the only place: the gate polls CI for up to an hour before a
  `ready`, and the issue wants the question right after `finish`. The gate keeps the check as the
  backstop (below), so a session that skips the ask, or a resume, still cannot reach `ready`.

**Which questions count.** Every `blocking` open-question action on any ledger entry, `plan-approval`
entries included, that has no answer. Ledger entries are append-only and a completed entry's actions are
never rewritten, so `gate_ledger[i].actions[j]` is a stable id for the life of the run. A later fix
round adds new entries, whose new `blocking` questions are asked in turn; answered ones stay answered.

## Design

### 1. The kinds and the open-question reads (`checks/questions.php`, new)

```php
enum QuestionKind: string
{
    case Blocking = 'blocking';
    case FollowUp = 'follow-up';
    case Remark = 'remark';
}

const PIPELINE_ANSWER = 'Answer to open question ';
```

- `pipeline_open_questions(array $manifest): list<array{id, gate, kind, question, note}>` — every action
  with `disposition: open-question` on every ledger entry, in ledger order; `id` is
  `gate_ledger[<i>].actions[<j>]`, `question` the action's `claim`. An action without `kind` (written
  before kinds existed) reads as `blocking`.
- `pipeline_unanswered(array $manifest): list<…>` — the `blocking` ones that no decision answers: none of
  `decisions` starts with `PIPELINE_ANSWER . $id` (the id ends in `]`, so `actions[2]` never matches
  `actions[20]`). Each item gains `decision`, the text the session completes with the owner's answer:
  `PIPELINE_ANSWER . "{$id} (\"{$question}\"): "`.
- `pipeline_follow_ups(array $manifest): list<array{question, note}>` — the `follow-up` ones, the same
  question text listed once (a fix round's resolve step may carry one again).

The file reads the manifest array and nothing else (`$manifest['gate_ledger']`, `$manifest['decisions']`),
so it requires no other check file. `dispatch_cli.php` and `proof.php` require it (`proof_cli.php` loads
`proof.php` through `proof_store.php` without the dispatch files, and the page validates kinds), and
`tests/Pest.php`'s list gains it.

### 2. The resolve steps write a kind (`checks/record.php`, `checks/brief.php`)

- `pipeline_record_action_problem()`: an action with `disposition: open-question` must carry `kind`, one
  of `QuestionKind`'s values; any other disposition must not carry it. The keys become
  `claim, disposition, note` plus `kind` where it applies; the messages follow the existing ones
  (`actions[0] has no \`kind\``, ``actions[0]: `kind` is not one of blocking, follow-up, remark``,
  ``actions[0] has an unknown key `kind` `` on an `integrated` one).
- `$writeActions` (both resolve steps) names the shape: `{claim, disposition, note}`, plus `kind` on an
  `open-question`, one of `blocking`, `follow-up`, `remark` (engine.md §Open questions).
- `$actOnReview`'s *Carry anything unresolved verbatim as an open question.* becomes: *Carry anything
  unresolved verbatim as an open question with its kind: `blocking` when the answer changes this PR's
  code, `follow-up` for work outside it, `remark` for a note on a choice already made; `blocking` when
  unsure. A `blocking` question names its options in `note`, the one the PR built first (engine.md §Open
  questions).*
- `review-pr:resolve` gains: *Under the PR body's `## Open questions`, one line per open question led by
  its kind (`- **blocking:** …`), or `None.`; a question a settled `Answer to open question` decision
  answers is no longer open.*
- The proof-page line of `review-pr:resolve` says *the final open questions, each `{kind, question}`*.
  `verify-ui:run`'s line says that a before state the base cannot render is an open question of kind
  `remark` (engine.md §The proof store, *Before, after and defect shots*).

### 3. `finish` answers `ask` (`checks/dispatch_cli.php`)

In `dispatch_cli_finish()`, a `done` that holds is recorded as today (`dispatch_cli_done()`: cursor
`done`), and then the answer depends on the manifest:

```json
{"action":"ask","proof":"<artifacts.proof or null>","questions":[{"id":"gate_ledger[5].actions[1]","gate":"pr-review","question":"…","note":"…","decision":"Answer to open question gate_ledger[5].actions[1] (\"…\"): "}],"followUps":[{"question":"…","note":"…"}]}
{"action":"done","proof":"<artifacts.proof or null>","followUps":[…]}
```

`ask` while `pipeline_unanswered()` is not empty, else `done`; `followUps` on both, `[]` when there are
none. The cursor says `done` in both: the workflow is finished, and what is left is the session's. A
`done` that does not hold, and every halt, answer as today. `returned` (`interactive`) is unchanged: its
finish step has the human at hand.

### 4. The CI gate answers `ask` (`checks/ci.php`, `checks/dispatch_cli.php`)

`pipeline_ci_answer()` takes the unanswered questions as a new last parameter (`array $unanswered = []`)
and answers `{"action":"ask","questions":[…]}` before anything else, the unreviewed-merge round included.
`dispatch_cli_ci()` passes `pipeline_unanswered($manifest)` in `autoflow` and `[]` in `interactive`, as
`dispatch_cli_unreviewed()` does, and reads neither git nor gh when there are any. The session's poll
loop ends on any answer other than `wait`, so an `ask` ends it at the first read. This is the backstop:
after a resume, a skipped ask or an answer recorded under a mistyped prefix, `ready` stays out of reach.

### 5. The answer round's brief lines (`checks/brief.php`)

As `pipeline_ci_round_line()`, a `pipeline_answer_round_line($step)` for `review-pr` while a decision
starts with `PIPELINE_ANSWER`:

- review: *A settled `Answer to open question` decision whose answer departs from what the PR built is a
  finding of this review: name what it changes (engine.md §Open questions).*
- resolve: *Integrate each settled `Answer to open question` decision that departs from what the PR
  built, and name it in `actions`; the question it answers is no longer open (engine.md §Open
  questions).*

### 6. The proof page (`checks/proof.php`, `checks/proof_render.php`)

- `openQuestions` items are `{kind, question}`. `proof_cli.php write` refuses a payload whose
  `openQuestions` holds an item that is not an object with a non-empty `question` and a `kind` of
  `QuestionKind` (`openQuestions[1] has no kind: blocking, follow-up, remark`). It judges the payload's
  items, not the stored run's, so a run filed before kinds with string items is not refused on a write
  that leaves `openQuestions` out.
- The page renders each item with its kind as a label before the text (*Blocking*, *Follow-up*,
  *Remark*); a stored string renders as today, without a label.

### 7. Docs

- **engine.md, new §Open questions — a kind each, and when each reaches the owner**, after §Resolving a
  review: the issue's table; who writes the kind (both resolve steps, `blocking` when unsure, options in
  `note`); the id and the answer record (`Answer to open question <id>`, through `launch --decision`);
  `finish`'s `ask` and `followUps`; the gate's `ask` backstop; the session's sequence (§8); the PR body
  line; that #145's departures from a mockup are `blocking` by definition when that issue lands.
- **engine.md §Resolving a review**, *Never interrupt on a finding*: unresolved goes into the PR body as
  an open question with its kind; the run is still never interrupted mid-workflow, and a `blocking`
  question is asked once the workflow returns (§Open questions).
- **engine.md §`autoflow`**: the `finish` command's answer line gains `| {"action":"ask",…}`, and the
  *`finish`* bullet says what `ask` means. **§The CI gate**: an `ask` row above the table's rows, and an
  *`ask`* bullet beside *`ready`*, *`fix`* and *`halt`*. **§The proof store**: the `openQuestions` row is
  `list of {kind, question}`; the before-shot sentence says the open question is a `remark`. **§Stations**
  `review-pr` row: *the finalised open questions, each with its kind*.
- **manifest.md**: `actions[].kind` row (`blocking` \| `follow-up` \| `remark`, only with
  `open-question`); the `open-question` disposition says *carried verbatim into the PR body with its
  kind*; the `decisions` row adds *an owner's answer to a `blocking` open question*.
- **pipeline `SKILL.md`** step 4: `finish` may print `ask`; new handling before step 5 (§8); step 5's
  gate may answer `ask`; step 6 lists the follow-ups once.
- **orchestrate `SKILL.md` step 5** and **`references/commands.md` §Finish**: on `ask`, the blocking
  questions go into the batched `AskUserQuestion` (the existing *Ask last* rule holds), and the CI gate
  runs only after every answer is recorded; follow-ups are reported once with the ready PR and asked as
  one batched *file an issue / drop* question, or filed directly; remarks are never asked. A run waiting
  on an answer is not working, so it does not count toward the four. The *Open questions: ask only a
  genuine fork…* sentence is replaced by this, since the kind now says which is a fork.

### 8. The session's sequence on `ask`

1. Ask every question in one `AskUserQuestion`: 2–4 options per question from its `note`, the one the
   PR built first, recommendation first.
2. Record each answer: the question's `decision` text with the owner's answer appended, as `--decision`.
   Append the same lines to the PR body (fetch the body, append, `gh pr edit --body-file`, as a halt's
   reason is appended), so the answer outlives the disposable manifest.
3. Every answer keeps what the PR built: `launch <manifest> <diff> --decision "…"`… (answers `done`),
   then the CI gate as step 5. Any answer changes the code: `launch <manifest> <diff> --from review-pr
   --decision "…"`… with all the answers, and a new workflow through the detour; its `finish` may ask
   again about new `blocking` questions.

## Testing

TDD, on the existing suites; the plan writes each case before its code.

**`QuestionsTest.php` (new).** `pipeline_open_questions()` lists open-question actions across
`plan-approval` and `pr-review` entries with their ids, and reads a kindless one as `blocking`;
`pipeline_unanswered()` drops an answered one, keeps `actions[2]` open when only `actions[20]` is
answered, and builds `decision` verbatim; `pipeline_follow_ups()` lists a follow-up once across two
entries, and never a remark.

**`RecordTest.php`.** The refusal dataset gains: an open question with no `kind`, with an unknown one,
and an `integrated` action with a `kind`; a resolve record with a `blocking` open question completes the
entry with `kind` kept.

**`DispatchCliTest.php`.** `finish` on a done run whose last `pr-review` entry holds a `blocking`
question answers `ask` with the question's `decision` and records the cursor `done`; with only
`follow-up` and `remark` it answers `done` with `followUps`; after `launch --decision "<decision>yes"`
(no `--from`) on that run, `launch` answers `done`, the decision is in `decisions`, and `ci` on a
green head answers `ready`. `ci` on an unanswered run answers `ask` without calling gh (the fake gh records no
call).

**`CiTest.php`.** `pipeline_ci_answer()` with unanswered questions answers `ask` ahead of an unreviewed
merge, a mismatch and a green head; with none, every existing case keeps its answer.

**`BriefTest.php`.** The actions line (line 604's expectation) names `kind`; both resolve briefs carry
the kind sentence; `review-pr`'s review and resolve briefs carry the answer round's line with an
`Answer to open question` decision and not without.

**`ProofWriteTest.php` / `ProofRenderTest.php` / `ProofTest.php`.** A payload with a string item or an
unknown kind is refused with the message; a stored string run written without `openQuestions` is not;
the page renders the kind label; the existing fixtures move to `{kind, question}` items where they are
payloads.

**`LockStepTest.php`.** The new §Open questions heading is named by the new brief lines, so the
*every engine.md section a brief names* case covers it.

**Suite.** `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`.

## Done when

- The suite passes with the cases above.
- An open question in the manifest without a valid `kind` is refused by `record`; one in a proof payload
  by `proof_cli.php write`.
- On a run with an unanswered `blocking` question, `finish` answers `ask` and `ci` answers `ask`, so
  `ready` is unreachable; once each is answered by a `launch --decision`, `ci` answers as today. A run
  with only `follow-up` or `remark` questions gets `done` and `ready` as today.
- engine.md, manifest.md, pipeline `SKILL.md` and the orchestrate docs say what §7 and §8 say.

## Assumptions

Each question the brainstorm would have asked the owner, and the answer assumed.

1. **Where is an answer recorded?** In `decisions`, through `launch --decision`, under a fixed prefix
   naming the question's ledger id (§Approaches). The issue names `launch --from review-pr --decision`
   for the change path; the no-change path uses the same flag without `--from`, which `launch` already
   accepts on a finished run.
2. **Is the answer also on the PR?** Yes: the session appends it to the PR body (§8 step 2). The
   manifest is disposable (manifest.md), so an answer that only it holds would be lost with it; this is
   the same impersonal append a halt's reason gets.
3. **Do `plan-approval` questions count?** Yes: every `open-question` action carries a kind, at both
   resolve steps (the issue: *every open question*), and an unanswered `blocking` one from `review-plan`
   is asked before ready like one from `review-pr`. A question a later loop-back made moot costs one
   question, answered "moot" by keeping what was built.
4. **What is a kindless open question?** `blocking`. Only runs in flight when this merges have them (the
   siblings in this batch); asking those once is the heavier path the issue prescribes for an unclear
   kind.
5. **Does `interactive` change?** Only in what its resolve step writes (a kind) and what the page shows.
   `returned` answers as today, and the gate's backstop is `autoflow`-only, as the merge round is: the
   human is in the finish step and settles questions there.
6. **Who decides whether an answer needs changes?** The invoking session, comparing the answer with the
   option the PR built, which the resolve step names first in `note`. A wrong call either way is caught:
   a change recorded as "keeps" ships the built code with the answer on record in the PR body; a "keep"
   sent to a fix round costs one scoped review.
7. **May a question's text hold a quote?** Yes: the `decision` the session completes carries the
   question in double quotes, and the session passes the whole text to `--decision` with its quotes
   escaped, as it does for any owner text. The match needs only the prefix up to the id, so the quoted
   part never decides whether a question is answered.
8. **Is the ask→fix→ask cycle bounded?** It is owner-paced: each round needs a new answer, so it cannot
   churn unattended. No bound is added.
9. **What does "the pipeline README" mean?** The pipeline has no README; its entry point is
   `skills/pipeline/SKILL.md`, with the detail in engine.md. The repo `README.md` describes machine
   setup and does not describe open questions; it stays as it is.
10. **What happens to the proof page status while a question waits?** Nothing new: it stays `running`
   until `ready`. A new status is not in the issue's scope.
11. **The status line?** Unchanged: it reads the cursor, which says `done` once `finish` ran.
12. **#145's mockup departures?** That issue's `design` leg writes them; it is open and unbuilt. This
    design gives it the kind and the ask; how `design` records an open question is #145's to decide.
13. **Changelog?** The repo has no `CHANGELOG.md` and no `.changelog/`: none.

Added by the `design` leg's `plan` step, where the plan needed an answer the sections above do not give:

14. **Do `ask`'s `questions` items carry `kind`?** Yes: they are `pipeline_unanswered()`'s items whole,
    `{id, gate, kind, question, note, decision}` (§1), `kind` always `blocking`. §3's JSON example lists
    them without it; the tests pin the full shape, so the CLI answer and the function cannot drift.
15. **How does a proof payload refusal number its item?** From 0, `openQuestions[0]`, as `record` numbers
    `actions[0]`: both name a list index. Shots keep their own 1-based `shot 1`.
16. **How do the refusals name the kinds?** `blocking, follow-up, remark`, comma-joined, as `record`
    names the dispositions, from one `QuestionKind::listed()`. `record` says
    ``actions[0]: `kind` is not one of blocking, follow-up, remark``; the proof store says
    `openQuestions[0] has no kind: blocking, follow-up, remark` for a missing or unknown kind, and
    `openQuestions[0] has no question` for an item that is not an object with a non-empty `question`.
17. **Where does `ci` answer `ask`?** After the `artifacts.pr` check, which reads no file and no command,
    and before `git rev-parse HEAD`: neither git nor gh is called. Both `dispatch_cli_ci()` and
    `pipeline_ci_answer()` answer through one `pipeline_ci_ask($unanswered)`, so they cannot answer
    differently.
18. **How does `questions.php` tell an answered question?** With its own `str_starts_with` over
    `decisions` (an `array_filter`, not 8.4's `array_any`: earlier plans name PHP 8.3+), not `ci.php`'s
    `pipeline_decisions_starting()`: §1 has it require no other check file, and `proof.php` requires it.
19. **Is the answer round's brief line mode-bound?** No: it is keyed on a decision that starts
    `Answer to open question`, as the CI round's line is keyed on its record. Only `launch` writes one, and
    `launch` serves `autoflow` runs only.
20. **How does the page show a kind?** The kind's `label()` in `<strong>` before the text,
    `<strong>Blocking:</strong> Keep the guard?`; no new CSS. A stored string item renders as today.
21. **A `follow-up` carried by two entries with different notes?** `pipeline_follow_ups()` keeps the first
    one's `note`, in ledger order.

## Relation to other work

- #145 (open): its mockup departures are `blocking` by definition and reach the owner through §3–§8.
- #150, #168, #169 (siblings in this batch, no PR yet): when one lands first and shares files, the base
  is merged as engine.md §Catching up with the base says.
- #149 (merged): the CI gate's ordering and its `pipeline_decisions_starting()` counting, reused here.

## What was read

Issue #146; `skills/pipeline/checks/record.php` (`ActionDisposition`, `pipeline_record_action_problem`,
`pipeline_record_resolve`); `checks/dispatch_cli.php` (`dispatch_cli_finish`, `dispatch_cli_done`,
`dispatch_cli_launch`, `dispatch_cli_ci`, `dispatch_cli_unreviewed`); `checks/ci.php`
(`pipeline_ci_answer`, the round counters); `checks/brief.php` (`pipeline_leg_overrides`,
`pipeline_brief_overrides`, `pipeline_ci_round_line`, `pipeline_conflict_round_line`); `checks/proof.php`
(`proof_validate_run`); `checks/proof_render.php` (`proof_render_list`); `checks/manifest.php`
(`manifest_finished`); the tests that pin actions, `finish` and `openQuestions` (`RecordTest`,
`BriefTest` line 604, `DispatchCliTest`, `ProofTest`, `ProofWriteTest`, `ProofRenderTest`,
`LockStepTest`'s case list); engine.md §`autoflow`, §Stations, §The proof store, §Who takes the PR out of
draft, §The CI gate, §Closing links, §Resolving a review, §Failure policy; manifest.md; pipeline
`SKILL.md` steps 4–6; orchestrate `SKILL.md` and `references/commands.md` §Finish, §Needs input; PR #171's
body (the `## Open questions` section as resolve steps write it today).
