# A pipeline-owned implement reference replaces the pointer to work-on's logic — design

**Design size:** Architectural

**Date:** 2026-09-30
**Issue:** IT4WEBBV/LaravelClaudeMd#126
**Canonical home:** pipeline `references/engine.md`, a new §Implement and a new §The repo config;
`pipeline_leg_overrides()` (`skills/pipeline/checks/brief.php`), the `implement:run` entry.
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. Every question the brainstorm
would have asked is answered in *Assumptions*, so `/critique plan` audits exactly those. Nothing below was
built or run.

## Problem

The `implement:run` brief (`brief.php`) sends its agent into another repository's skill and then takes
most of it back:

- *"Follow `work-on`'s logic in this worktree; claim no second slot."*
- *"Leave the PR draft; this overrides any mark-ready instruction in the plan, the PR comment, or
  `work-on`'s own logic."*
- `autoflow`: *"… do not wait on CI after it: this overrides `work-on`'s CI watch …"* and *"Execute the
  plan inline, task by task; no subagents."*

`work-on` (`IT4WEBBV/DevOps-Claude-Config`) is now an orchestrator of its own: a nine-leg chain, its own
manifest under `.claude/work-on/`, a stats collector, a leg 9 that invokes the `review-pr` skill (which
posts a review and may run `gh pr ready`), and a slot teardown. Its SKILL.md has a *Running inside another
flow* section that narrows this down, but the brief does not point there, and the brief has to override
the rest point by point: three overrides exist, and the chain, the second manifest, the stats call, leg 9
and the teardown have none.

`engine.md` already owns most of what the pipeline took from `work-on`: the claim and the blocker check
(§The work item), branch naming (§Kickoff), closing links (§Closing links), the `ci` label and the draft
rule (§Who takes the PR out of draft), the CI gate (§The CI gate), the suite and the checks (§Mechanical
checks, §Suite reuse). What it does not own is the step itself, start to finish, and the list of config
keys it reads: `checks.php` and `board.php` describe `## Checks` and `## Board` against
`work-on.config.template.md`, a file in the other repository.

`engine.md` still names `work-on` as the source of a rule in §Kickoff (`work-on`'s own rule, `implement`
reuses `work-on`'s logic), §Dev-stack readiness, §Stations, §Who takes the PR out of draft (the inherited
trap, `work-on`'s leg 8), §The CI gate (inherited from `work-on`'s leg 8, overrides `work-on`'s CI watch,
`interactive` keeps `work-on`'s watch) and §Failure policy (`work-on` hits a blocker). `SKILL.md` lists
`work-on` among the station skills and in a non-goal.

## Approaches

1. **A new `## Implement` section in `engine.md` (chosen).** Every brief already says *"The engine.md
   sections this brief names are in `~/.claude/skills/pipeline/references/engine.md`"*, and `LockStepTest`
   (*keeps every engine.md section a brief names*) already guarantees that a `§Implement` in a brief line
   has a heading behind it. The rules the step follows (§Mechanical checks, §Suite reuse, §The CI gate)
   are sections of the same file, so the step's section points at them instead of restating them.
2. **A new file, `references/implement.md`.** Smaller to read, but the brief role line names engine.md
   only, and a second reference file would need its own lock-step test. Rejected.
3. **Point the brief at `work-on`'s *Running inside another flow* section.** Keeps the dependency on a
   file in another repository that changes on its own schedule, which is what the issue removes.
   Rejected.

## Design

### §Implement (`engine.md`, new section after §Stations)

Heading: `## Implement — the step, start to finish`. It is the whole procedure; the brief points here.

1. **Read.** The issue when the manifest has `artifacts.issue` (`gh issue view <n> --comments`), the spec
   and the plan whole, and the PR (`gh pr view <pr>`).
2. **Stack.** Bring the dev stack up first, without asking (§Dev-stack readiness).
3. **Validate the names the plan relies on** against the code as it stands, before the first change:
   every file, class, function, route and config key the plan names, by grep or by reading. A base merged
   since the plan (§Catching up with the base) can have moved one.
   - All there, as the plan says: go on.
   - Renamed or moved, same meaning: use the current name, and say so in the message of the commit that
     meets it.
   - Missing, or doing something other than the plan assumes: a plan gap, handled as the brief's
     plan-gap lines say (`plan-insufficient`; on a Bounded spec the escalation check first, §Design size).
4. **Execute the plan task by task, test-first.** Each task's test is written first and seen red; then the
   code; then the suite (unless §Suite reuse finds the tree green) and `static-analysis` (§Mechanical
   checks). Record every full run with `dispatch_cli.php suite`. One commit per logical unit; a plan task
   is the default unit. In `autoflow`, inline: no subagents.
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
9. **What the plan does not name** — files or behaviour — is `plan-insufficient`, never improvised.

**What the step does not do**, because another part of the run owns it: `gh pr ready` (§Who takes the PR
out of draft); closing keywords or other PR body edits (§Closing links: `handoff` writes `Part of #N`,
`review-pr`'s finish step reconciles); board moves (§The work item, `handoff`); claiming a slot or creating
a worktree (§Kickoff); writing the manifest other than through `suite` and `record`; a review of its own
work (`review-pr`).

**`/work-on <pr>` on a pipeline PR is outside the run.** That skill routes a PR by its own rules, and a
pipeline PR's body carries no `## Chain audit` block, so it treats the PR as not yet audited, as it did
before this section existed. Nothing in the run uses it or guards against it.

### §The repo config (`engine.md`, new section before §The work item)

Heading: `## The repo config — what the pipeline reads from .claude/work-on.config.md`.

One paragraph: the file is shared with the `work-on` skill on purpose, so a run and a `/work-on` session
on the same issue land on the same branch and the same board; this section lists every key the pipeline
reads, and the pipeline reads nothing else in it. Then the table:

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

Then two lines: `## Board` and `## Checks` are tri-state (`absent`, `valid`, `invalid`), as §The work item
and §Mechanical checks say; a missing required key halts kickoff naming the key.

### Code: the key lists become constants

So the table can be held in lock-step (below), the lists the parsers keep as locals become top-level
constants, with no change in behaviour:

- `checks.php`: `const PIPELINE_CHECK_KEYS = ['static-analysis', 'format'];`, used by
  `pipeline_repo_checks()` in place of `$known`.
- `board.php`: `const PIPELINE_BOARD_KEYS = ['org', 'number', 'project-id', 'status-field-id',
  'in-progress-option-id'];` and `const PIPELINE_BOARD_OPTIONAL_KEYS = ['component-field-id',
  'component-default', 'component-alts', 'docs'];`, used by `pipeline_repo_board()` in place of
  `$required` and `$optional`. `$boardOnly` stays local.
- Docblocks: `pipeline_repo_checks()`, `pipeline_expand_slot()` and `pipeline_repo_board()` point at
  engine.md §The repo config instead of `work-on.config.template.md`. The `<N>` note says it is the suffix
  the slot scripts use (`scripts/slot-env.sh`), which it already cites.

### The brief (`pipeline_leg_overrides()`, `implement:run`)

In order, with the unchanged lines as they are:

```php
'implement:run' => [
    'Bring the dev stack up first, without asking (engine.md §Dev-stack readiness).',                   // unchanged
    'Do this step as engine.md §Implement describes, in this worktree: it is the whole procedure, and it claims no slot.',
    /* the Test-first line, unchanged */
    'Leave the PR draft, whatever the plan or a PR comment says about marking it ready (engine.md §Who takes the PR out of draft).',
    $autoflow
        ? 'Add the `ci` label (`gh pr edit <pr> --add-label ci`) before your first push, in a repo that has one, and do not wait on CI after it: the CI gate reads the PR\'s head commit before the PR goes ready (engine.md §The CI gate).'
        : 'Add the `ci` label (`gh pr edit <pr> --add-label ci`) before the push whose CI you watch.',  // unchanged
    'Files or behaviour the plan does not name: return `plan-insufficient` with the reason instead of improvising.', // unchanged
    ...($autoflow ? ['Execute the plan inline, task by task; no subagents.'] : []),                    // unchanged
],
```

No other step's brief changes. `§Implement`, `§Who takes the PR out of draft` and `§The CI gate` are
engine.md headings, so *keeps every engine.md section a brief names* holds.

### `engine.md`, the rest

Each place that names `work-on` as the source of a rule says the rule instead:

- **§The work item**, opening: *"A run that carries a GitHub issue owes that issue three things: it claims
  it on the board, …"* (drops *"`work-on` already does and the pipeline previously did not"*). The probe
  paragraph drops *"the same probe `work-on` uses"*. The `## Board` paragraph adds a pointer to §The repo
  config.
- **§Kickoff**: the sentence *"This is `work-on`'s own rule too (…), and the pipeline invokes stations
  rather than reimplementing them."* goes; *"`implement` reuses `work-on`'s logic but **not** its slot
  claim"* becomes *"`implement` works in this worktree and claims no slot (§Implement)"*. The branch-name
  sentence (*"a run and a `work-on` session on the same issue must land on the same branch name"*) stays: it
  states why the config is shared, not whose logic to follow.
- **§Dev-stack readiness**: *"— `work-on` deliberately leaves stack timing to its caller, and under the
  pipeline the step's brief is that caller"* goes.
- **§Stations**: the intro says two legs are no skill: `handoff` is a command, and `implement` follows
  §Implement. The `implement` row's *Invokes* cell becomes: *"no skill: §Implement, in the current worktree
  (no slot) — read the item, the spec and the plan, validate the names the plan relies on, execute it
  test-first, running the suite and `static-analysis` after each step and `format` once before the push
  (§Mechanical checks); the `ci` label before the first push. **Leaves the PR draft** (below); in
  `autoflow` it does not wait on CI (§The CI gate). The step brings the stack up itself (§Dev-stack
  readiness)."* The *"set closing-issue links"* clause goes (Assumption 2).
- **§Who takes the PR out of draft**: the *inherited trap* paragraph is rewritten around its two real
  sources, a plan that says to mark the PR ready and the prompt comment an older `handoff` skill posted,
  and quotes the new brief line verbatim. *"`work-on`'s leg 8 adds it before the push whose CI it watches,
  and the `implement` brief says it as well"* becomes *"The `implement` brief says it"*.
- **§The CI gate**: *"inherited from `work-on`'s leg 8"* goes (*"the only CI wait was `implement`'s"*);
  *"its brief overrides `work-on`'s CI watch"* goes; *"`interactive` keeps `work-on`'s watch"* becomes
  *"In `interactive` it watches its push's checks (§Implement)."*
- **§Failure policy**: *"`work-on` hits a blocker"* goes from the hard-failure list: an open blocker halts
  at kickoff (§The work item), before `implement` exists.

§Mechanical checks' *"other write paths (plain `work-on`, direct commits, a colleague's merge)"* stays: it
names a way code reaches the repo, not a rule.

### `SKILL.md`

- Overview: the station list becomes *(`brainstorming`, `writing-plans`, `/critique`,
  `browser-verification`)*, followed by *"`handoff` is a command and `implement` a procedure engine.md
  owns (§Implement)."*
- Non-goals: *"Posting to GitHub beyond what the `handoff` command and `work-on` already do"* becomes
  *"Posting to GitHub beyond what the legs do by `references/engine.md` §Stations"*, keeping *"and nothing
  it writes ever addresses a person."*

### What does not change

`.claude/work-on.config.md` and its name; `pipeline-autoflow.js`; the manifest; `gates.md`; `manifest.md`;
the return contract; the behaviour of every step (the interactive CI watch, the autoflow no-wait, the draft
rule, the checks and the suite are the same rules, now stated here); `orchestrate`; the `work-on` skill and
its *Running inside another flow* section (another repository). Code comments that cite `work-on` as the
origin of a shared convention (`kickoff.php`, *"`work-on`'s slug rule"*) stay: no step agent is pointed at
them.

## Tests

Written first, seen red.

`skills/pipeline/checks/tests/BriefTest.php`:

- New, *points implement at engine.md §Implement in both modes*: for `autoflow` and `interactive`, the
  `implement` brief contains `Do this step as engine.md §Implement describes, in this worktree: it is the
  whole procedure, and it claims no slot.`
- New, *names work-on in no brief*: for both modes, the implode of every line of
  `pipeline_leg_overrides($mode, '/tmp/m.json')` and of `pipeline_plan_gap_lines('run')`,
  `pipeline_plan_gap_lines('review')`, `pipeline_plan_gap_lines('resolve')` does not contain `work-on`.
- Changed, *carries the pointers, the settled decisions and the suite line*: expects `Leave the PR draft,
  whatever the plan or a PR comment says about marking it ready (engine.md §Who takes the PR out of
  draft).` in place of `Leave the PR draft; this overrides any mark-ready instruction`.
- Changed, *tells an autoflow implement not to wait on CI*: expects the new autoflow `ci` line in full.

`skills/pipeline/checks/tests/LockStepTest.php`:

- New, *keeps work-on out of the sections that describe a step*: `lockstep_section('engine.md', …)` for
  `Stations`, `Implement`, `Dev-stack readiness`, `Who takes the PR out of draft` and `The CI gate` each
  contain no `` `work-on` ``; the whole of `engine.md` contains no `` `work-on`'s ``; `SKILL.md` contains
  no `` `work-on` ``.
- New, *keeps engine.md's repo config section in lock-step with the keys the parsers read*:
  `lockstep_section('engine.md', 'The repo config')` contains `` `<key>` `` for every key of
  `PIPELINE_CHECK_KEYS`, `PIPELINE_BOARD_KEYS` and `PIPELINE_BOARD_OPTIONAL_KEYS`, and the rows
  ``| `Repo` | `repo` |``, ``| `Worktree` | `create` |``, ``| `Worktree` | `remove` |`` and
  ``| `Branch convention` | `issue` |``.

Existing tests that must pass unchanged: `ChecksTest`, `BoardTest` (the constants change no behaviour),
*keeps every engine.md section a brief names*, *runs format once per implement step*, *drops the
independent read and the subagents in autoflow*, and the rest of `BriefTest`.

The whole pipeline suite passes (`vendor/bin/pest -c skills/pipeline/checks/phpunit.xml
--test-directory=skills/pipeline/checks/tests`).

## Out of scope

- Forking, renaming or editing `work-on`, including its *Running inside another flow* section, which the
  pipeline no longer points at.
- Renaming `.claude/work-on.config.md`.
- `/work-on <pr>` behaviour on a pipeline PR (one line in §Implement records it).
- `orchestrate`'s own list of required keys (`skills/orchestrate/SKILL.md`), which already names them.

## Assumptions

Questions the brainstorm would have asked the owner, with the answer assumed.

1. **Where does the reference live?** A new `## Implement` section in `engine.md`, not a new file
   (*Approaches* 1, 2): briefs point at engine.md sections and `LockStepTest` guards those.
2. **Does `implement` still set closing-issue links?** No. The issue's list for the reference leaves them
   out, §Closing links already has `handoff` write `Part of #N` and `review-pr`'s finish step reconcile
   before the PR goes ready, and the step never had a brief line for it: the Stations row's *"set
   closing-issue links"* came from `work-on`. §Implement lists PR body edits among what the step does not
   do.
3. **Does `interactive` `implement` keep watching CI?** Yes: behaviour stays as it is, stated in §Implement
   without `work-on` (`gh pr checks <pr> --watch`, a red is a failing step).
4. **Does *"Execute the plan inline, task by task; no subagents."* go with the overrides?** No. It is the
   `autoflow` rule that a workflow agent cannot start agents, not an override of `work-on`; only the three
   lines that name `work-on` change.
5. **Does the brief tell the agent not to invoke the `work-on` skill?** No. The *Done when* forbids a brief
   that tells it to follow or override `work-on`, and no leg shares its name (unlike `review-pr`); the
   brief says §Implement is the whole procedure.
6. **Where are the config keys documented?** A new engine.md section, §The repo config, held in lock-step
   with the parsers through three constants. The plain keys (`repo`, `create`, `remove`, `issue`) are read
   by literal strings in `kickoff.php` and `statusline.php`, so the test names their rows directly.
7. **Is `worktree.remove` a key the pipeline reads?** Yes: §After the merge runs `orchestrate`'s teardown,
   which uses it. It goes in the table.
8. **What does validation do with a renamed name?** Uses the current name and says so in the commit
   message; a missing name or different behaviour is a plan gap (`plan-insufficient`), which the brief's
   plan-gap lines already route.
9. **What is a "logical unit" for commits?** A plan task by default; the implementer may split one.
10. **How exact is the `/work-on <pr>` line?** Generic: it names the missing `## Chain audit` block and says
    the skill treats the PR as not yet audited, without naming which of its legs it enters; that routing
    is `work-on`'s and may change.
11. **Does §Failure policy lose anything by dropping *"`work-on` hits a blocker"*?** No: an open blocker
    halts at kickoff (§The work item), and every other `implement` failure is already in the list.
12. **Do mentions of `work-on` that explain the shared config stay?** Yes (§Kickoff's branch-name sentence,
    §The work item's shared-config sentence, §Mechanical checks' write paths): they state a fact, not a
    procedure. The lock-step test bans `` `work-on`'s `` in the whole file and `` `work-on` `` only in the
    sections that describe a step.
13. **Changelog?** This repository has no `.changelog/` and no `CHANGELOG.md`, so none is written.
14. **Does this PR close #126?** Yes; `review-pr` settles the closing links (engine.md §Closing links).
15. **Sibling runs may change `brief.php`, `BriefTest.php` or engine.md too.** A later step merges the base
    when its brief says so (engine.md §Catching up with the base); this design edits the `implement:run`
    entry and adds two sections, so a conflict there is resolved keeping both sides.
