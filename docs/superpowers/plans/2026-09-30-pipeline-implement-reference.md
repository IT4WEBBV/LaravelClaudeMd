# A pipeline-owned implement reference Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The `implement` step follows a procedure the pipeline owns, engine.md §Implement, instead of
`work-on`'s logic; the config keys the pipeline reads are listed in engine.md §The repo config and held in
lock-step with the parsers; no brief, and no engine.md section that describes a step, names `work-on` as
the source of a rule.

**Architecture:** Two new engine.md sections (§The repo config before §The work item, §Implement after
§Stations). The key lists in `checks.php` and `board.php` become top-level constants, so a lock-step test
can compare them with §The repo config's table. The `implement:run` entry of `pipeline_leg_overrides()`
points at §Implement and loses the three lines that override `work-on`. The remaining `work-on`-as-source
sentences in engine.md and `SKILL.md` state the rule instead. No behaviour changes.

**Tech Stack:** PHP 8.4, no framework; Pest 4 (`skills/pipeline/checks/tests`); Markdown references.

**Spec:** `docs/superpowers/specs/2026-09-30-pipeline-implement-reference-design.md`. Read it with this
plan: the plan argues from it, and its `## Assumptions` 1–17 are the answers this plan assumed (16 and 17
were added for this plan).

## Global Constraints

- Everything lives under `skills/pipeline/`. Run every command from the worktree root.
- Tests: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
  (append `--filter <Name>` for one file or one test). This repo is not a Docker project: Pest runs on the
  host, and there is no dev stack to bring up. A fresh worktree has no `vendor/`: run `composer install`
  once first. The suite needs `node` on `PATH`.
- This repo declares no `## Checks` block (`.claude/work-on.config.md`), so there is no `static-analysis`
  or `format` to run, and it has no `ci` label.
- Test-first: every task writes its test, sees it fail, then writes the code or the text.
- No behaviour change: `ChecksTest`, `BoardTest`, `DispatchCliTest`, `KickoffTest` and every other
  existing test pass unchanged, except the two `BriefTest` expectations Task 2 rewrites.
- `.claude/work-on.config.md` keeps its name. `pipeline-autoflow.js`, `gates.md`, `manifest.md`,
  `record.php`, `dispatch.php` and the manifest's shape do not change. The `work-on` skill (another
  repository) is not touched.
- Code comments that cite `work-on` as the origin of a shared convention stay (`kickoff.php`'s *"`work-on`'s
  slug rule"*, `board.php`'s *"the untouched scaffold `work-on` copies"*).
- In engine.md, no line of a new section may start with `## ` except its heading: `lockstep_section()` and
  the heading scan in *keeps every engine.md section a brief names* both treat a line-initial `## ` as a
  heading. `` `## Chain audit` `` in §Implement stays mid-line.
- After this plan, engine.md contains no `` `work-on`'s `` anywhere, and §Stations, §Implement,
  §Dev-stack readiness, §Who takes the PR out of draft and §The CI gate contain no `` `work-on` ``.
  `SKILL.md` contains no `` `work-on` ``. Mentions that explain the shared config stay (§The work item's
  board paragraph, §Kickoff's `worktree.create` and branch-name sentences, §Mechanical checks' *"plain
  `work-on`"*).
- Top-level constants, as `brief.php`'s `PIPELINE_CATCH_UP_STEPS` is; guard clauses; full type hints.
- No changelog entry: the repo has no `.changelog/` and no `CHANGELOG.md`.
- Commits: no `Co-Authored-By`, no AI attribution; every message ends on `(#126)`.

## Review Focus

1. **A new engine.md section cut short by a line-initial `## `** (a rewrap that puts `` `## Chain audit` ``
   at the start of a line): the lock-step tests would then read half a section and pass on it. Task 2's
   *keeps engine.md §Implement whole* asserts that `lockstep_section('engine.md', 'Implement')` reaches the
   section's last paragraph.
2. **A checkout path that holds `work-on`** (a worktree named after such a branch) reaching the brief
   through the `suite` line's absolute path: Task 2's *names work-on in no brief* test replaces the checks
   directory with `<checks>` before it looks (Assumption 16).
3. **An interactive `implement` that no longer knows to watch CI**, now that no brief line names
   `work-on`'s watch: §Implement step 7 says it (`gh pr checks <pr> --watch`), and the interactive `ci`
   line stays *"before the push whose CI you watch"*. Task 2's test holds both the pointer to §Implement
   and that line in the interactive brief.
4. **The board parser after the constants move**: an unknown key still `invalid`, `component-alts` and
   `docs` still accepted, a half-filled section still `invalid`. `BoardTest` (*rejects an unknown key*,
   *parses a complete board*, *treats a half-filled board as invalid*) and `ChecksTest` (*treats an unknown
   or mis-cased key … as invalid*) cover it unchanged; Task 1 runs them.
5. **A table row that drifts from the parser** (a key added to `PIPELINE_BOARD_OPTIONAL_KEYS` and not to
   §The repo config): Task 1's lock-step test reads every key of the three constants from the section.

## File Structure

| File | Change |
|---|---|
| `skills/pipeline/checks/checks.php` | `PIPELINE_CHECK_KEYS` replaces the local `$known`; two docblocks point at §The repo config |
| `skills/pipeline/checks/board.php` | `PIPELINE_BOARD_KEYS` and `PIPELINE_BOARD_OPTIONAL_KEYS` replace `$required` and `$optional`; the docblock points at §The repo config |
| `skills/pipeline/checks/brief.php` | the `implement:run` entry of `pipeline_leg_overrides()` |
| `skills/pipeline/references/engine.md` | new §The repo config and §Implement; `work-on`-as-source sentences in §The work item, §Kickoff, §Dev-stack readiness, §Stations, §Who takes the PR out of draft, §The CI gate, §Failure policy |
| `skills/pipeline/SKILL.md` | the Overview's station list, one Non-goal |
| `skills/pipeline/checks/tests/LockStepTest.php` | three new tests |
| `skills/pipeline/checks/tests/BriefTest.php` | two new tests, two changed expectations |

---

### Task 1: §The repo config, and the key lists as constants

**Files:**
- Modify: `skills/pipeline/checks/checks.php:3-15` (docblock, `$known`), `:32`, `:61`, `:78-85` (docblock)
- Modify: `skills/pipeline/checks/board.php:3-23` (docblock, `$required`, `$optional`), `:74`, `:86`, `:93`, `:103-110`
- Modify: `skills/pipeline/references/engine.md` (new section before `## The work item`; one clause in §The work item)
- Test: `skills/pipeline/checks/tests/LockStepTest.php`

**Interfaces:**
- Consumes: `lockstep_section(string $doc, string $heading): string` (`LockStepTest.php`, existing).
- Produces: `PIPELINE_CHECK_KEYS = ['static-analysis', 'format']` (`checks.php`);
  `PIPELINE_BOARD_KEYS = ['org', 'number', 'project-id', 'status-field-id', 'in-progress-option-id']` and
  `PIPELINE_BOARD_OPTIONAL_KEYS = ['component-field-id', 'component-default', 'component-alts', 'docs']`
  (`board.php`). The engine.md heading `## The repo config — what the pipeline reads from .claude/work-on.config.md`.

- [ ] **Step 1: Write the failing test**

Append to `skills/pipeline/checks/tests/LockStepTest.php`:

```php
it('keeps engine.md\'s repo config section in lock-step with the keys the parsers read', function () {
    $section = lockstep_section('engine.md', 'The repo config');

    foreach ([...PIPELINE_CHECK_KEYS, ...PIPELINE_BOARD_KEYS, ...PIPELINE_BOARD_OPTIONAL_KEYS] as $key) {
        expect($section)->toContain("`{$key}`");
    }
    foreach (['| `Repo` | `repo` |', '| `Worktree` | `create` |', '| `Worktree` | `remove` |', '| `Branch convention` | `issue` |'] as $row) {
        expect($section)->toContain($row);
    }
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "repo config section"`
Expected: FAIL with `Undefined constant "PIPELINE_CHECK_KEYS"`.

- [ ] **Step 3: The constant in `checks.php`**

Replace the head of the file, from `<?php` through the `$keyLineRe` line of `pipeline_repo_checks()`
(the `$known` line between them goes):

```php
<?php

/** The keys a `## Checks` block may declare (`../references/engine.md` §The repo config). */
const PIPELINE_CHECK_KEYS = ['static-analysis', 'format'];

/**
 * Parse the `## Checks` block out of a repo's `.claude/work-on.config.md`; its keys are
 * `PIPELINE_CHECK_KEYS` (`../references/engine.md` §The repo config).
 *
 * Tri-state by design (`../references/engine.md` §Mechanical checks): `absent` and
 * `invalid` must never collapse into one another. A typo'd heading or a mis-cased key
 * that parsed as "not adopted" would disable the checks permanently while the run
 * believed it was covered — the one outcome the design calls worse than no tooling.
 *
 * @return array{state: 'absent'|'valid'|'invalid', commands: array<string, string>, error: ?string}
 */
function pipeline_repo_checks(string $configMarkdown): array
{
    $keyLineRe = '/^\s*-\s*([A-Za-z][A-Za-z0-9_-]*)\s*:\s*(.*)$/';
```

Then the two uses of `$known`:

```php
            if (preg_match($keyLineRe, $line, $m) && in_array(strtolower($m[1]), PIPELINE_CHECK_KEYS, true)) {
```

```php
        if (! in_array($key, PIPELINE_CHECK_KEYS, true)) {
```

And in `pipeline_expand_slot()`'s docblock replace

```
 * stack, `-2` / `-3` … in a slot. That is how the slot machinery names containers
 * (`scripts/slot-env.sh`: `SUFFIX="-${SLOT}"`), and it mirrors the `<N>` convention
 * `work-on.config.template.md` already uses for `slot-path` and `dev-url`.
```

with

```
 * stack, `-2` / `-3` … in a slot. That is how the slot machinery names containers
 * (`scripts/slot-env.sh`: `SUFFIX="-${SLOT}"`). `../references/engine.md` §The repo config
 * lists the keys whose commands carry it.
```

- [ ] **Step 4: The constants in `board.php`**

Replace the head of the file, from `<?php` through the blank line after the `$optional` line (1-24):

```php
<?php

/** The `## Board` keys, required together (`../references/engine.md` §The repo config). */
const PIPELINE_BOARD_KEYS = ['org', 'number', 'project-id', 'status-field-id', 'in-progress-option-id'];

/** The `## Board` keys a section may add; `component-alts` and `docs` are read by nothing, accepted so a shared config parses. */
const PIPELINE_BOARD_OPTIONAL_KEYS = ['component-field-id', 'component-default', 'component-alts', 'docs'];

/**
 * Parse the `## Board` block out of a repo's `.claude/work-on.config.md`.
 *
 * Same tri-state contract as `pipeline_repo_checks()` (`checks.php`), and for the same
 * reason (`../references/engine.md` §The work item): a board move that silently does not
 * happen looks exactly like a repo that has no board. `absent` therefore means "this repo
 * deliberately has no board", and a malformed section is `invalid` — never `absent`.
 *
 * The section is **all-or-nothing**: the five `PIPELINE_BOARD_KEYS` are required together
 * (`../references/engine.md` §The repo config), so a half-filled section cannot half-run a
 * status move.
 *
 * A value that is still a template placeholder (`<org-login>`) counts as *not filled in*.
 * A section that is nothing but placeholders is the untouched scaffold `work-on` copies
 * into a fresh repo, so it reads `absent`, not `invalid`.
 *
 * @return array{state: 'absent'|'valid'|'invalid', board: array<string, string>, error: ?string}
 */
function pipeline_repo_board(string $configMarkdown): array
{
```

(The `$required` and `$optional` locals go, and the function body opens on the `$boardOnly` comment,
with no blank line before it; `$boardOnly` and its comment stay as they are.) Then every
use of the two locals:

```php
        if (! in_array($key, PIPELINE_BOARD_KEYS, true) && ! in_array($key, PIPELINE_BOARD_OPTIONAL_KEYS, true)) {
```

```php
    $present = array_values(array_filter(PIPELINE_BOARD_KEYS, fn ($k) => isset($values[$k])));
```

```php
    $missing = array_values(array_diff(PIPELINE_BOARD_KEYS, $present));
```

```php
    $board = [];
    foreach (PIPELINE_BOARD_KEYS as $key) {
        $board[$key] = $values[$key];
    }
    foreach (PIPELINE_BOARD_OPTIONAL_KEYS as $key) {
        if (isset($values[$key])) {
            $board[$key] = $values[$key];
        }
    }
```

Check nothing is left: `grep -n '\$known\|\$required\|\$optional' skills/pipeline/checks/checks.php skills/pipeline/checks/board.php`
Expected: no output.

- [ ] **Step 5: §The repo config in engine.md**

Insert before the line `## The work item — resolved before anything is created` (after the §Interactive
section, a blank line on each side):

```markdown
## The repo config — what the pipeline reads from .claude/work-on.config.md

A repo configures the pipeline in `.claude/work-on.config.md`, the file the `work-on` skill reads too. It
is shared on purpose, so a run and a `/work-on` session on the same issue land on the same branch and the
same board. This section lists every key the pipeline reads, and the pipeline reads nothing else in the
file.

| Section | Key | Read by | |
|---|---|---|---|
| `Repo` | `repo` | kickoff (the issue lookup, §The work item), the status line, `orchestrate` | required |
| `Worktree` | `create` | kickoff (§Kickoff), with `<branch>` substituted | required |
| `Worktree` | `remove` | the teardown after the merge (§After the merge), `orchestrate` | required to tear down |
| `Branch convention` | `issue` | kickoff: the run's branch and the check that no branch of the issue exists | required for an issue |
| `Board` | `org`, `number`, `project-id`, `status-field-id`, `in-progress-option-id` | kickoff's claim (§The work item) | all or none |
| `Board` | `component-field-id`, `component-default` | `handoff` (the PR's Component) | optional |
| `Board` | `component-alts`, `docs` | nothing: accepted so a `work-on` config parses | — |
| `Checks` | `static-analysis`, `format` | `implement`, `review-pr` (§Mechanical checks); `<N>` expands to the slot suffix | optional, committed |

`## Board` and `## Checks` are tri-state (`absent`, `valid`, `invalid`), as §The work item and
§Mechanical checks say. A missing required key halts kickoff, naming the key.
```

- [ ] **Step 6: §The work item points at it**

In §The work item replace

```
skill; they live in the `## Board` section of the repo's `.claude/work-on.config.md`, the same
```

with

```
skill; they live in the `## Board` section of the repo's `.claude/work-on.config.md` (§The repo config), the same
```

- [ ] **Step 7: Run the tests**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "LockStepTest|ChecksTest|BoardTest|KickoffTest|HandoffTest"`
Expected: PASS, the new test included; `ChecksTest`, `BoardTest`, `KickoffTest` and `HandoffTest` unchanged.

- [ ] **Step 8: Commit**

```bash
git add skills/pipeline/checks/checks.php skills/pipeline/checks/board.php skills/pipeline/references/engine.md skills/pipeline/checks/tests/LockStepTest.php
git commit -m "feat(pipeline): engine.md §The repo config lists every key the pipeline reads, held in lock-step with the parsers' constants (#126)"
```

---

### Task 2: The `implement` brief points at §Implement

**Files:**
- Modify: `skills/pipeline/checks/brief.php:77-87` (the `implement:run` entry)
- Modify: `skills/pipeline/references/engine.md` (new section before `## Design size`)
- Test: `skills/pipeline/checks/tests/BriefTest.php`, `skills/pipeline/checks/tests/LockStepTest.php`

**Interfaces:**
- Consumes: `pipeline_brief(array $manifest, string $leg, string $manifestPath): string`,
  `pipeline_leg_overrides(string $mode, string $manifestPath): array`, `pipeline_plan_gap_lines(string $step): array`,
  `brief_manifest(string $leg, array $extra = []): array` (BriefTest's helper), `lockstep_section()`
  (LockStepTest's helper; the §Implement check lives in LockStepTest, beside it).
- Produces: the engine.md heading `## Implement — the step, start to finish`, which Task 3's test reads.

- [ ] **Step 1: Write the failing tests**

In `BriefTest.php`, test *carries the pointers, the settled decisions and the suite line*, replace

```php
        ->toContain('Leave the PR draft; this overrides any mark-ready instruction')
```

with

```php
        ->toContain('Leave the PR draft, whatever the plan or a PR comment says about marking it ready (engine.md §Who takes the PR out of draft).')
```

In test *tells an autoflow implement not to wait on CI, and an interactive one to label before the push
whose CI it watches*, replace the autoflow `->toContain(…)` with

```php
        ->toContain('Add the `ci` label (`gh pr edit <pr> --add-label ci`) before your first push, in a repo that has one, and do not wait on CI after it: the CI gate reads the PR\'s head commit before the PR goes ready (engine.md §The CI gate).')
```

Append two tests:

```php
it('points implement at engine.md §Implement in both modes', function (string $mode) {
    $brief = pipeline_brief(brief_manifest('implement', ['mode' => $mode]), 'implement', '/tmp/m.json');

    expect($brief)->toContain('Do this step as engine.md §Implement describes, in this worktree: it is the whole procedure, and it claims no slot.');
    if ($mode === 'interactive') {
        expect($brief)->toContain('Add the `ci` label (`gh pr edit <pr> --add-label ci`) before the push whose CI you watch.');
    }
})->with(['autoflow', 'interactive']);

it('names work-on in no brief', function (string $mode) {
    $lines = array_merge(
        ...array_values(pipeline_leg_overrides($mode, '/tmp/m.json')),
        ...[pipeline_plan_gap_lines('run'), pipeline_plan_gap_lines('review'), pipeline_plan_gap_lines('resolve')],
    );

    $brief = pipeline_brief(brief_manifest('implement', ['mode' => $mode]), 'implement', '/tmp/m.json');

    expect(str_replace(realpath(__DIR__ . '/..'), '<checks>', implode("\n", $lines)))->not->toContain('work-on')
        ->and(str_replace(realpath(__DIR__ . '/..'), '<checks>', $brief))->not->toContain('work-on');
})->with(['autoflow', 'interactive']);
```

(The `str_replace` is Assumption 16: the `suite` line carries the checks directory's absolute path.)

Append to `LockStepTest.php`, so a line-initial `## ` inside the new section cannot cut it short unseen
(Review Focus 1):

```php
it('keeps engine.md §Implement whole, down to its last paragraph', function () {
    expect(lockstep_section('engine.md', 'Implement'))
        ->toContain('`gh pr checks <pr> --watch`')
        ->toContain('**`/work-on <pr>` on a pipeline PR is outside the run.**');
});
```

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "BriefTest|Implement whole"`
Expected: FAIL: the brief lacks `Leave the PR draft, whatever the plan`, the new autoflow `ci` line and
`Do this step as engine.md §Implement describes`; *names work-on in no brief* finds `work-on`; *keeps
engine.md §Implement whole* fails with `engine.md has no section starting '## Implement'`.

- [ ] **Step 3: The overrides**

In `pipeline_leg_overrides()` replace the `'implement:run'` entry with:

```php
        'implement:run' => [
            'Bring the dev stack up first, without asking (engine.md §Dev-stack readiness).',
            'Do this step as engine.md §Implement describes, in this worktree: it is the whole procedure, and it claims no slot.',
            'Test-first; after each plan step the suite and `static-analysis`; `format` once, over the whole tree, when the code is complete: before the last suite run and the push, its changes committed, and again only after a later change (engine.md §Mechanical checks, §Suite reuse). After every full run, record it: ' . $suite . '.',
            'Leave the PR draft, whatever the plan or a PR comment says about marking it ready (engine.md §Who takes the PR out of draft).',
            $autoflow
                ? 'Add the `ci` label (`gh pr edit <pr> --add-label ci`) before your first push, in a repo that has one, and do not wait on CI after it: the CI gate reads the PR\'s head commit before the PR goes ready (engine.md §The CI gate).'
                : 'Add the `ci` label (`gh pr edit <pr> --add-label ci`) before the push whose CI you watch.',
            'Files or behaviour the plan does not name: return `plan-insufficient` with the reason instead of improvising.',
            ...($autoflow ? ['Execute the plan inline, task by task; no subagents.'] : []),
        ],
```

- [ ] **Step 4: Run the tests to see the missing heading**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "BriefTest|LockStepTest"`
Expected: FAIL only in *keeps every engine.md section a brief names* (`engine.md has no section 'Implement
describes'`) and in *keeps engine.md §Implement whole*; all of `BriefTest` passes.

- [ ] **Step 5: §Implement in engine.md**

Insert before the line `## Design size — Bounded or Architectural` (after §Stations' *`handoff` in order*
paragraph, a blank line on each side):

```markdown
## Implement — the step, start to finish

The `implement` step is no skill: this section is the whole procedure, and its brief points here. The
rules it follows are sections of this file, named where they apply rather than restated.

1. **Read.** The issue when the manifest has `artifacts.issue` (`gh issue view <n> --comments`), the spec
   and the plan whole, and the PR (`gh pr view <pr>`).
2. **Stack.** Bring the dev stack up first, without asking (§Dev-stack readiness).
3. **Validate the names the plan relies on** against the code as it stands, before the first change:
   every file, class, function, route and config key the plan names, by grep or by reading. A base merged
   since the plan (§Catching up with the base) can have moved one.
   - All there, as the plan says: go on.
   - Renamed or moved, same meaning: use the current name, and say so in the message of the commit that
     meets it.
   - Missing, or doing something other than the plan assumes: a plan gap, handled as the brief's plan-gap
     lines say (`plan-insufficient`; on a Bounded spec the escalation check first, §Design size).
4. **Execute the plan task by task, test-first.** Each task's test is written first and seen red; then the
   code; then the suite (unless §Suite reuse finds the tree green) and `static-analysis` (§Mechanical
   checks). Record every full run with `dispatch_cli.php suite`. One commit per logical unit: a plan task
   by default, which the step may split. In `autoflow`, inline: no subagents.
5. **Format once** when the code is complete, over the whole tree, before the last suite run and the push,
   its changes committed (§Mechanical checks).
6. **The `ci` label, then the push.** In a repo that has the label (`gh label list --search ci`), add it
   (`gh pr edit <pr> --add-label ci`) before the first push; then `git push`, never forced.
7. **CI.** In `autoflow`, do not wait on it: the CI gate reads the PR's head commit before the PR goes
   ready (§The CI gate). In `interactive`, watch the push's checks (`gh pr checks <pr> --watch`); a red is
   a failing step, fixed and pushed again, and one that predates the change is a halt with the evidence
   (§Suite reuse).
8. **Leave the PR draft**, whatever the plan or a PR comment says about marking it ready (§Who takes the
   PR out of draft).
9. **What the plan does not name**, files or behaviour, is `plan-insufficient`, never improvised.

**What the step does not do**, because another part of the run owns it: `gh pr ready` (§Who takes the PR
out of draft); closing keywords or other PR body edits (§Closing links: `handoff` writes `Part of #N`,
`review-pr`'s finish step reconciles); board moves (§The work item, `handoff`); claiming a slot or creating
a worktree (§Kickoff); writing the manifest other than through `suite` and `record`; a review of its own
work (`review-pr`).

**`/work-on <pr>` on a pipeline PR is outside the run.** That skill routes a PR by its own rules, and a
pipeline PR's body carries no `## Chain audit` block, so it treats the PR as not yet audited, as it did
before this section existed. Nothing in the run uses it or guards against it.
```

Check no line of it starts with a stray `## `:
Run: `grep -n '^## ' skills/pipeline/references/engine.md | grep -n 'Implement\|Chain audit'`
Expected: one line, `## Implement — the step, start to finish`; no `Chain audit`.

- [ ] **Step 6: Run the tests**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "BriefTest|LockStepTest|DispatchCliTest|AutoflowScriptTest"`
Expected: PASS, *keeps every engine.md section a brief names*, *runs format once per implement step* and
*drops the independent read and the subagents in autoflow* included.

- [ ] **Step 7: Commit**

```bash
git add skills/pipeline/checks/brief.php skills/pipeline/references/engine.md skills/pipeline/checks/tests/BriefTest.php skills/pipeline/checks/tests/LockStepTest.php
git commit -m "feat(pipeline): the implement brief points at engine.md §Implement, the whole step, and overrides no work-on logic (#126)"
```

---

### Task 3: engine.md and SKILL.md state the rules, not whose they were

**Files:**
- Modify: `skills/pipeline/references/engine.md` (§The work item, §Kickoff, §Dev-stack readiness, §Stations,
  §Who takes the PR out of draft, §The CI gate, §Failure policy)
- Modify: `skills/pipeline/SKILL.md:12-13`, `:135-136`
- Test: `skills/pipeline/checks/tests/LockStepTest.php`

**Interfaces:**
- Consumes: `lockstep_section()`; the §Implement heading from Task 2.
- Produces: nothing later tasks use.

- [ ] **Step 1: Write the failing test**

Append to `LockStepTest.php`:

```php
it('keeps work-on out of the sections that describe a step', function () {
    foreach (['Stations', 'Implement', 'Dev-stack readiness', 'Who takes the PR out of draft', 'The CI gate'] as $heading) {
        expect(lockstep_section('engine.md', $heading))->not->toContain('`work-on`', "engine.md §{$heading} names `work-on`");
    }
    expect((string) file_get_contents(__DIR__ . '/../../references/engine.md'))->not->toContain('`work-on`\'s');
    expect((string) file_get_contents(__DIR__ . '/../../SKILL.md'))->not->toContain('`work-on`');
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "keeps work-on out"`
Expected: FAIL: `engine.md §Stations names `work-on``.

- [ ] **Step 3: §The work item**

Replace

```
A run that carries a GitHub issue owes that issue three things `work-on` already does and the
pipeline previously did not: it claims it on the board, it refuses to start on blocked work, and
at the end it settles whether merging closes it (§Closing links).
```

with

```
A run that carries a GitHub issue owes that issue three things: it claims it on the board, it
refuses to start on blocked work, and at the end it settles whether merging closes it (§Closing
links).
```

Replace

```
The `issues` endpoint returns both, and a PR has a non-null `pull_request` — the same probe
`work-on` uses, which is why `/pipeline <number>` needs no separate issue and PR syntax.
```

with

```
The `issues` endpoint returns both, and a PR has a non-null `pull_request`, which is why
`/pipeline <number>` needs no separate issue and PR syntax.
```

(The board paragraph's *"the same single source `work-on` and the pipeline's `handoff` command read"*
stays: it states why the file is shared.)

- [ ] **Step 4: §Kickoff**

Replace

```
    flag cuts the run's branch from the wrong code, and every leg after it looks healthy. (For one
    run's own base, see *A run on a base* above.) This is `work-on`'s own rule too (use the
    configured command, "never `git worktree add` by hand"), and the pipeline invokes stations
    rather than reimplementing them.
```

with

```
    flag cuts the run's branch from the wrong code, and every leg after it looks healthy. (For one
    run's own base, see *A run on a base* above.)
```

Replace

```
**One worktree for the entire run** — `implement` reuses `work-on`'s logic but **not** its slot
claim, so no *second* slot ever appears mid-chain.
```

with

```
**One worktree for the entire run** — `implement` works in this worktree and claims no slot
(§Implement), so no *second* slot ever appears mid-chain.
```

(The `worktree.create` sentence *"shared with `work-on` and `orchestrate`"* and the branch-name sentence
*"a run and a `work-on` session on the same issue must land on the same branch name"* stay.)

- [ ] **Step 5: §Dev-stack readiness**

Replace

```
never a "shall I start docker?" prompt — `work-on` deliberately leaves stack *timing* to its caller,
and under the pipeline the step's brief *is* that caller. This is the house preference [[docker-stack-no-hesitation]]. If the stack
```

with

```
never a "shall I start docker?" prompt. This is the house preference [[docker-stack-no-hesitation]]. If the stack
```

- [ ] **Step 6: §Stations**

Replace the intro

```
The pipeline **invokes** the existing skills; it never reimplements their judgment. One leg is no skill:
`handoff` is a command, `dispatch_cli.php handoff` (`../checks/handoff.php`). Pushing a branch and opening
a draft PR is mechanical, and the `handoff` skill, written for a person who closes a plan cycle, asks a
question and posts a prompt that a run cannot use.
```

with

```
The pipeline **invokes** the existing skills; it never reimplements their judgment. Two legs are no skill.
`handoff` is a command, `dispatch_cli.php handoff` (`../checks/handoff.php`). Pushing a branch and opening
a draft PR is mechanical, and the `handoff` skill, written for a person who closes a plan cycle, asks a
question and posts a prompt that a run cannot use. `implement` follows §Implement, a procedure this file
owns.
```

In the `implement` row, replace the *Invokes* cell (from `` `work-on`'s logic **in the current worktree** ``
through `The step brings the stack up itself (§Dev-stack readiness).`) with

```
no skill: §Implement, in the current worktree (no slot) — read the item, the spec and the plan, validate the names the plan relies on, execute it test-first, running the suite and `static-analysis` after each step and `format` once before the push (§Mechanical checks); the `ci` label before the first push. **Leaves the PR draft** (below); in `autoflow` it does not wait on CI (§The CI gate). The step brings the stack up itself (§Dev-stack readiness).
```

The row's other cells (`—`, `autonomous-capable; needs the stack up`, `updates `last_sha`, marks
implemented`) stay. The *"set closing-issue links"* clause goes with the old cell (Assumption 2).

- [ ] **Step 7: §Who takes the PR out of draft**

Replace

```
**The trap is inherited, so state it explicitly at the leg brief.** `work-on` marks ready at the end
of its run, and that is correct *standalone* — nothing follows it there. Under the pipeline something
does. The same applies to the prompt comment a PR opened before `dispatch_cli.php handoff` existed may
carry, from the `handoff` skill: its template ends with *"implementation fully done → take the PR out of
draft"*, which is right for a human resuming the work alone and **wrong** under the pipeline. The command
posts no comment. The `implement` brief carries it verbatim
(`pipeline_leg_overrides()`): **"Leave the PR draft; this overrides any mark-ready instruction in the
plan, the PR comment, or `work-on`'s own logic."**
```

with

```
**The trap comes from outside the run, so state it explicitly at the leg brief.** Two things can tell
`implement` to mark the PR ready, and both are right only for a person finishing the work alone: a plan
whose last task says so, and the prompt comment a PR opened before `dispatch_cli.php handoff` existed may
carry, from the `handoff` skill, whose template ends with *"implementation fully done → take the PR out of
draft"*. Under the pipeline something follows `implement`, so both are **wrong** here. The command posts no
comment. The `implement` brief carries the rule verbatim (`pipeline_leg_overrides()`): **"Leave the PR
draft, whatever the plan or a PR comment says about marking it ready (engine.md §Who takes the PR out of
draft)."**
```

Replace

```
`work-on`'s leg 8 adds it before the push whose CI it watches, and the `implement` brief says it as
well: in `interactive` **"add the `ci` label
```

with

```
The `implement` brief says it: in `interactive` **"add the `ci` label
```

(the rest of that sentence, from `(`gh pr edit <pr> --add-label ci`)` on, stays).

- [ ] **Step 8: §The CI gate**

Replace

```
was `implement`'s, inherited from `work-on`'s leg 8: `verify-ui` and `review-pr`'s finish step push
```

with

```
was `implement`'s: `verify-ui` and `review-pr`'s finish step push
```

Replace

```
  takes the PR out of draft), pushes and returns; its brief overrides `work-on`'s CI watch.
  `review-pr:review` runs while CI runs. `interactive` keeps `work-on`'s watch.
```

with

```
  takes the PR out of draft), pushes and returns.
  `review-pr:review` runs while CI runs. In `interactive` it watches its push's checks (§Implement).
```

- [ ] **Step 9: §Failure policy**

Replace

```
- **Hard failure** — a station errors: tests won't go green, a tool dies, the stack won't start,
  `work-on` hits a blocker, or a review step returns nothing after a single retry (in `interactive`
```

with

```
- **Hard failure** — a station errors: tests won't go green, a tool dies, the stack won't start,
  or a review step returns nothing after a single retry (in `interactive`
```

(An open blocker halts at kickoff, §The work item: Assumption 11.)

- [ ] **Step 10: SKILL.md**

Replace

```
memory. It is the *spine*, not better station logic — each station already owns its own quality
(`brainstorming`, `writing-plans`, `/critique`, `work-on`, `browser-verification`).
```

with

```
memory. It is the *spine*, not better station logic — each station already owns its own quality
(`brainstorming`, `writing-plans`, `/critique`, `browser-verification`). `handoff` is a command and
`implement` a procedure engine.md owns (§Implement).
```

Replace

```
- **Posting to GitHub beyond what the `handoff` command and `work-on` already do**, and nothing it
  writes ever addresses a person.
```

with

```
- **Posting to GitHub beyond what the legs do by `references/engine.md` §Stations**, and nothing it
  writes ever addresses a person.
```

- [ ] **Step 11: Check what is left**

Run: `grep -n "work-on" skills/pipeline/references/engine.md skills/pipeline/SKILL.md`
Expected: only the kept mentions: §The repo config (the shared file, `/work-on`, the `work-on` config
row), §Implement's `` `/work-on <pr>` `` paragraph, §The work item's `.claude/work-on.config.md` lines and
*"the same single source `work-on` and the pipeline's `handoff` command read"*, §Kickoff's
`.claude/work-on.config.md` lines, *"shared with `work-on` and `orchestrate`"* and *"a run and a `work-on`
session"*, §Mechanical checks' `.claude/work-on.config.md` and *"plain `work-on`"*. No `` `work-on`'s ``.

- [ ] **Step 12: Run the tests**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "LockStepTest|BriefTest"`
Expected: PASS.

- [ ] **Step 13: Commit**

```bash
git add skills/pipeline/references/engine.md skills/pipeline/SKILL.md skills/pipeline/checks/tests/LockStepTest.php
git commit -m "docs(pipeline): engine.md and SKILL.md state each rule the implement step follows, not work-on as its source (#126)"
```

---

### Task 4: The whole suite, and the push

**Files:** none new.

- [ ] **Step 1: The whole pipeline suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, every test, the five new tests (three plain, two over two modes: seven new cases) included. Record it with
`dispatch_cli.php suite` as the brief says.

- [ ] **Step 2: Push**

No `## Checks` block and no `ci` label in this repo (Global Constraints). `git push`, never forced. The PR
stays draft.
