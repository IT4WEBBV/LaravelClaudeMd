# The `handoff` leg runs `dispatch_cli.php handoff`, not the `handoff` skill — design

**Design size:** Architectural

**Date:** 2026-09-30
**Issue:** IT4WEBBV/LaravelClaudeMd#125, and with it the rest of #118 (a resumed handoff adopts the PR
the branch already has)
**Canonical home:** `skills/pipeline/checks/handoff.php` (new: what the step does),
`skills/pipeline/checks/dispatch_cli.php` (the `handoff` command),
`skills/pipeline/checks/brief.php` (`handoff:run`'s overrides and its `## Return`),
`skills/pipeline/checks/kickoff.php` (the `gh-merge-base` config goes), pipeline
`references/engine.md` §Stations and the sections that say `handoff pr`.
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. Every question the brainstorm
would have asked is answered in *Assumptions*, so `/critique plan` audits exactly those. Nothing below was
built or run; what it relies on was read (see *What was read*). No probe was needed: no choice below
hinges on whether an approach works at all.

## Problem

The `handoff` leg invokes the `handoff` skill of `IT4WEBBV/DevOps-Claude-Config`. That skill was written
for a human who closes a plan cycle, and it changes independently of this repo. What a run needs from it
is small and mechanical: push the branch, open a draft PR that names the spec and the plan and references
the issue without a closing keyword, set the board Component, and record the PR's number. `engine.md`
§Agents per step already calls the step *near-mechanical*.

The rest of the skill works against a run:

- **Phase B.3** puts an execution-strategy proposal to the user and waits, and says it is not to be
  skipped under Auto Mode. A question in an unattended step is a halt.
- **The generated prompt**, posted as a PR comment, tells the next session to take the PR out of draft
  and to pick up small refactors. The `implement` brief has to override the first in prose, and says
  nothing about the second.
- **The workspace block** tells a later session to claim a fresh slot; a run's next leg works in the
  same worktree.
- **PR language** follows the other repo's rule (the issue's language since `666a43e`), while this repo
  made PR text English again in `8d85feb`.
- **Spec and plan detection** from the last commits or the newest files, which a merge of the base
  empties or crowds; the brief carries a line to skip it.
- **The base.** The skill's `gh pr create` passes no `--base`, so kickoff sets
  `branch.<branch>.gh-merge-base` and the brief has the step check `baseRefName` and retarget.

And the step is where three of four mechanical halts came from (#118, #122 twice): the PR was open, the
manifest write failed, and a plain resume would have opened a second PR. #52 made the write a tested
command (`record`); the work before it is still a skill run by the cheapest agent in the table.

## Settled by the owner

- The same handoff command runs in every mode, `interactive` included: no strategy proposal, no
  next-session prompt comment, no workspace block. Not chosen: keeping the `handoff` skill in
  `interactive`. (2026-09-30)
- This run also delivers the rest of #118: a resumed handoff adopts an existing draft PR for the branch
  instead of opening a second one. It builds on the write verbs of #52. (2026-09-30)

These answer two of the issue's three open points (the PR comment, `interactive`). The third, whether the
step stays an agent, is Assumption 1.

## Approaches

1. **One command that is the whole step, its own record included (chosen).**
   `dispatch_cli.php handoff <manifest>` pushes, opens or adopts the PR, sets the Component and then
   writes the manifest through `record`'s own code path. It records `continued` with the PR, or `halted`
   with the reason; the agent runs it and returns the status it printed. Every outward act is
   idempotent, so running it again after any failure is safe.
2. **A command that opens the PR and prints its number; the agent then runs `record --pr <n>`.** Two
   calls, and the second is the one that was skipped or botched in #118 and #122. `record` made that
   call robust, but a step that stops between the two still leaves a PR the manifest does not know.
   With adoption that is recoverable, so this works; it is rejected because the issue asks that *the
   manifest write is the command's and not the agent's*, and one call leaves nothing in between.
3. **Keep the skill and add brief lines** (skip B.3, post no comment, write English). This is today's
   approach, one override at a time, against a skill that changed 58 times in a month. Rejected by the
   issue itself.

## Design

### The command

```
php dispatch_cli.php handoff <manifest>
```

It serves both modes (as `record` does) and refuses the retired `auto`. In order:

1. **May it write here?** `record`'s checks, before anything leaves the machine: the path is not the
   `.before.json` snapshot, the manifest is readable and valid, and the snapshot beside it is of
   `handoff:run` (`dispatch_cli_snapshot_step()`). A failure is `{"action":"refused","reason":…}`, exit
   1, nothing pushed.
2. **Preflight**, read-only. Each failure is a halt (step 8) before anything is pushed:
   - `artifacts.spec` and `artifacts.plan` are set and exist at `HEAD` (`dispatch_cli_exists_at()`);
   - the worktree is on the manifest's `branch` (`git rev-parse --abbrev-ref HEAD`);
   - the repo's `## Board` section is not `invalid` (`pipeline_repo_board()` over the worktree's
     `.claude/work-on.config.md`; no such file reads as no board). `interactive` runs never pass
     kickoff's check, so this is where they meet it.
3. **Find the PR**, before the push, so a PR the run may not work on halts with nothing pushed:
   - `artifacts.pr` set (a re-run after an escalation or a plan gap): `gh pr view <pr> --json
     number,url,state,isDraft,baseRefName,headRefName,body`. It must be open and draft
     (`pipeline_pr_problem()`, the invariant check's own words) and its head must be the manifest's
     branch.
   - otherwise: `gh pr list --head <branch> --state open --json
     number,url,state,isDraft,baseRefName,headRefName,body`. None: a PR will be created. One: it is
     **adopted** (#118), and must be a draft, by the same function. More than one (the same head into
     different bases): a halt naming them.
   - gh failing here is a halt: a run that cannot tell whether a PR exists never creates one.
4. **Push**: `git -C <worktree> push -u origin <branch>`. The branch is named because kickoff leaves it
   without an upstream (a `git worktree add -b … origin/main` would otherwise track `main`). Never
   forced. A push git refuses is a halt with git's stderr.
5. **Create, or bring the adopted PR in line.**
   - *Create:* `gh pr create --draft --head <branch> [--base <base>] --title <title> --body <body>`,
     with `--base` exactly when the manifest has `base`. Arguments go to gh as an argv list
     (`proc_open`), never through a shell. Then the PR is **read back** with step 3's `gh pr list`; the
     number, the URL and the base come from that read, not from parsing what `create` printed.
   - *Existing:* `gh pr edit <pr> --base <base>` when the manifest has `base` and `baseRefName`
     differs; `gh pr edit <pr> --body <body>` when the body must gain lines (below). The title is left
     as it is.
   - A gh failure in this step is a halt. The branch is pushed by then; the next run of the command
     adopts or creates, so nothing is left to repair.
6. **The Component**, when the board is `valid`, names a `component-field-id` and a `component-default`
   of the form `<Name>=<option id>`: `gh project item-add <number> --owner <org> --url <PR url>
   --format json`, then `gh project item-edit --id <item> --project-id <project-id> --field-id
   <component-field-id> --single-select-option-id <option id>`. Both calls are idempotent. A failure
   is a **note**, never a halt, as kickoff's claim is (`pipeline_kickoff_claim()`): a PR without a
   Component is a finished handoff. A board that is `absent`, or `valid` without a `component-default`,
   is skipped silently; a `component-default` without `=`, or one without a `component-field-id`, is a
   note naming the key.
7. **Record** `continued` through `dispatch_cli_record()` with the PR's number: the same candidate,
   the same boundary check, the same read-back. `last_sha` is `HEAD`, which is what was pushed.
8. **A halt** is recorded the same way: `--status halted` with the reason, which needs no git.

**The answer** is one JSON line, `record`'s with the step's own facts added:

```
{"action":"recorded","leg":"handoff","step":"run","status":"continued","last_sha":…,"entry":null,"replaced":[…],"pr":7,"url":"https://github.com/…/pull/7","created":true,"notes":[…]}
{"action":"recorded","leg":"handoff","step":"run","status":"halted","reason":"…","replaced":[…]}
{"action":"refused","reason":"…"}
```

Exit 0 for a recorded result, a halt included (a halt is a decision about the run); exit 1 for a
refusal, as `record`. `created` is false for an adopted or re-used PR. `notes` holds the Component line
(`Component <Name> set on board <number>`, or why not) and `PR #7 retargeted to <base>` when that
happened.

**When the record is refused after the PR is open** (the write did not land, #118's case), the answer is
that refusal with the PR named in its reason: *`… ; PR #7 is open and the next run of this step adopts
it`*. The step returns `halted`, `finish` writes the cursor, and a resume runs `handoff` again, which
finds #7 and records it. No second PR, no hand repair.

### Title and body

Fixed English words around the spec's own heading; nothing is translated and nothing is asked.

- **Title:** `Implement: <heading> (issue: #<n>)`, the form every run's PR in this repo has. `<heading>`
  is the spec's first `# ` line, less a trailing ` — design` (or ` - design`). Without
  `artifacts.issue` the suffix is left out. A spec without an H1 takes the branch name as its heading.
- **Body of a new PR:**

  ```
  Implements the design in `<artifacts.spec>`.
  Plan: `<artifacts.plan>`.

  Part of #<n>.
  ```

  The last line only with an issue. `Part of` is the non-closing form §Closing links requires at
  `handoff`; the finish step reconciles it.
- **Body of an existing PR: only ever added to.** When it names neither path or only one, the two
  design lines are put in front of it. When it holds no `#<n>` at all, `Part of #<n>.` goes with them. A
  body that already names both paths and the issue is not edited. So a re-run never undoes what
  `implement` or the finish step wrote (a `Closes #<n>`, annotations, an appended halt reason), and an
  adopted PR someone opened by hand keeps its text.

No comment is posted. A run resumes from its manifest; a cold pickup (`/work-on <pr>`) reads the spec
and the plan from the body, which `work-on` already treats as the normal flow for a PR without a handoff
prompt.

### The base

`--base` comes from the manifest, so `pipeline_kickoff_on_base()` stops setting
`branch.<branch>.gh-merge-base`, and the brief's *"The PR must open into `<base>`: … `gh pr view` …
`gh pr edit --base`"* line goes: the command creates into the base and retargets an existing PR itself.
A run without `base` passes no `--base`, as today.

### The brief

`handoff:run`'s overrides become, in both modes:

- *Run `php <checks>/dispatch_cli.php handoff <manifest>` as its own command: it pushes the branch,
  opens the draft PR or adopts the one the branch has, and records this step. It is the whole step
  (engine.md §Stations).*
- *The leg's name is not a skill to invoke: do not invoke the `handoff` skill (`/handoff`), which asks
  the owner a question and posts a prompt comment.*
- *Repair nothing it reports: no force-push, no `gh pr create` or `gh pr edit` by hand. A halt it
  recorded, a refusal, or a denied command is a halt with that reason.*

The line about giving `handoff pr` the spec and plan paths goes, and so does the base line. The generic
lines stay: the plan-gap lines (`pipeline_plan_gap_lines()`) and, in `autoflow`, the worktree and
authorisation lines.

`## Return` for this step names the command first, then `record` for the two statuses the command does
not write:

```
- `php <checks>/dispatch_cli.php handoff <manifest>`  (records `continued`, or `halted` with its reason)
- `php <checks>/dispatch_cli.php record <manifest> handoff run --status plan-insufficient --reason "<what the plan lacks>"`
- `… --status halted --reason "<why>"`
```

Its first sentence says the last act is that command or one `record`. The brief never prints
`record … --status continued --pr <number>` for this step, so no agent is pointed at opening a PR by
hand. `pipeline_record_table()` keeps the row: `record --pr` stays valid for a repair by hand and for
the replays that use it.

### Where the code goes

- **`handoff.php`** (new). Pure functions, tested without git or gh: the title
  (`pipeline_handoff_title(string $spec, string $branch, ?int $issue)`), the new body, what an existing
  body must gain (the body back, or null for no edit), the Component target from a parsed board (field
  and option, a note, or nothing), and the choice among the PRs gh listed (create, adopt, or a halt
  reason). And the step itself, which takes the manifest and two runners (git and gh, each `array $args
  → [code, out, err]`) and returns `['pr' => …, 'url' => …, 'created' => …, 'notes' => […]]` or throws
  a halt with its reason.
- **`gh.php`** (new, small): `pipeline_gh_run(string $cwd, array $args)`, which is
  `pipeline_kickoff_gh()` moved and renamed now that a second file needs it. `kickoff.php` requires it.
- **Board writes.** `pipeline_kickoff_claim()` and the Component are the same two gh calls with a
  different field and option: one function sets a single-select field on the board item of a URL and
  returns the error or null, and both use it.
- **`dispatch_cli.php`**: `dispatch_cli_handoff()` does steps 1, 7 and 8 around `handoff.php`'s step,
  with `dispatch_cli_git()` and `pipeline_gh_run()` as the runners. `record`'s opening checks (write
  problem, snapshot of this step) become one function both commands call. The header comment, the
  final `match` and the usage line gain `handoff <manifest>`, listed under *both*.
- **`brief.php`**: the overrides above; `pipeline_record_commands()` prints the `handoff` command in
  place of this step's `continued` line; the `$leg === 'handoff' && $base !== null` block goes.
- **`kickoff.php`**: the `git config … gh-merge-base` line and its docblock sentence go.
- **`record.php`, `dispatch.php`, `agents.php`, the workflow script**: unchanged. The step keeps its
  statuses (`continued`, `plan-insufficient`, `halted`), its agent (sonnet, low) and its prompt, which
  already ends on *"Finish as the brief's `## Return` says"*.

### The documents

- **`engine.md`**
  - §Stations: the opening sentence no longer says the pipeline only invokes skills; `handoff`'s row
    reads *`dispatch_cli.php handoff`* in both forms: pushes the branch, opens the draft PR or adopts
    the branch's, English title and body naming the spec and the plan, `Part of #N`, the Component,
    writes the PR pointer. The *projection* sentence about the PR comment goes.
  - §Kickoff, *A run on a base*: the third bullet says the command passes `--base` and retargets an
    existing PR; no `gh-merge-base`.
  - §The work item: the config is the source `work-on` and the pipeline read; `handoff` there means
    the command.
  - §Design size, escalation step 4 and plan-gap step 3: *`handoff` re-runs: it pushes and keeps the
    existing PR.*
  - §Who takes the PR out of draft: the paragraph about the prompt `handoff pr` writes says PRs opened
    before this change may carry one; the `implement` line stays verbatim.
  - §Catching up with the base, *`handoff` takes the spec and the plan from the manifest*: one
    sentence, the command reads `artifacts.spec` and `artifacts.plan`.
  - §Agents per step: `handoff:run`'s *Why* is *runs one command*.
  - §`autoflow`'s command list and the step bullet name `handoff` as the one step whose `continued` a
    command records.
  - §Failure policy: a halt the command recorded is a hard failure like any other; a resume runs it
    again.
- **`manifest.md`** §What a leg writes: `handoff`'s write is made by its command, through `record`'s
  code.
- **`gates.md`**'s command list and **`SKILL.md`**: the station list loses `handoff`, the non-goal
  reads *beyond what the `handoff` command and `work-on` do*.
- **`README.md`**, *Permissions for unattended runs*: the command pushes and calls gh from inside one
  `php … dispatch_cli.php handoff <manifest>` call, as `kickoff` creates the worktree and edits the
  board; a denial of that call halts the step with the command named.

### What does not change

The manifest's shape; `LegStatus::allowedFor()` and the routing tables; every check in `dispatch.php`;
`record` and `suite`; `launch`, `brief`'s checks, `finish`, `ci`; the workflow script; the agents
table; the `handoff` skill itself, which `work-on` and people keep using.

## Tests

Pest, in `skills/pipeline/checks/tests`, run with
`./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`.
Test-first, in `implement`.

- **`HandoffTest.php`** (new, pure): the title with and without an issue, with the ` — design` suffix
  cut, and from the branch for a spec without an H1; the new body with and without an issue; an
  existing body that names both paths and the issue is left alone, one that names neither gains the
  lines in front, one that holds `Closes #n` gains no `Part of`; the Component target for a valid board
  with a default, without one, with a default that has no `=`, without a field id, and for an absent
  board; the choice among listed PRs: none, one draft, one that is not a draft, two.
- **`HandoffCliTest.php`** (new): `base_repo()`'s clone of a bare origin with a committed spec and plan,
  `brief` run first, and a fake `gh` first on `PATH` that logs its calls and answers from files, as
  `kickoff_fixture()` and `ci_fixture()` do. The cases the issue names, and the ones the design adds:
  - *a new PR*: the branch is on origin, `pr create` carries `--draft`, `--head`, the English title
    and the body; the manifest holds `artifacts.pr`, `continued` and `last_sha`; the next `brief
    --after handoff:run --status continued` accepts it;
  - *an existing PR the manifest does not know* (#118): no `pr create` among the calls, the PR is
    recorded, `created` is false;
  - *an existing PR the manifest knows* (a re-run): pushed, body left as it is, recorded again;
  - *a run on a base*: `pr create` carries `--base <base>`; an existing PR on another base gets
    `pr edit --base`;
  - *a repo with a board*: `project item-add` and `item-edit` with the Component's field and option,
    and the note; a board call that fails is a note and the step still records `continued`;
  - *a repo without a board*: no `project` call and no note;
  - *an invalid board*, *a spec that is not committed*, *a PR that is not a draft*, *gh unable to
    list*: `halted` recorded with the reason, and neither a push nor a `pr create` happened;
  - *a failed push* (origin's branch moved ahead, so git refuses): `halted` recorded with git's words,
    no `pr create`;
  - *a failed manifest write* (the file read-only, as `RecordCliTest` does it): `refused`, exit 1, the
    reason names the PR; with the file writable again the command adopts that PR and records it, with
    one `pr create` in the log in total;
  - *no snapshot, or the snapshot of another step*: `refused`, and gh was never called;
  - *an `interactive` manifest* (`next` run first): records, and `returned` routes to `implement`.
- **`BriefTest.php`**: `handoff:run` names the literal command with the manifest's full path in both
  modes and says not to invoke the skill; no line says `handoff pr`; no *"The PR must open into"* line
  on a run with a base; `## Return` prints the `handoff` command and no `--pr <number>`. The three
  existing cases that pin the old lines are rewritten.
- **`DispatchCliTest.php`**: kickoff on a base sets no `gh-merge-base` (the two existing cases become
  one that asserts its absence); the claim still makes its two board calls through the shared function.
- **`AutoflowScriptTest.php`**, **`RecordTest.php`**, **`LockStepTest.php`**: unchanged and green; the
  replay that records `handoff:run` with `--pr 7` stays, as `record --pr` stays valid.

## What was read

- `skills/pipeline/checks/dispatch_cli.php` whole: `dispatch_cli_record()` and its opening checks,
  `dispatch_cli_write_problem()`, `dispatch_cli_snapshot_step()`, `dispatch_cli_write()`,
  `dispatch_cli_record_facts()` (a halt asks git nothing), `dispatch_cli_exists_at()`,
  `dispatch_cli_pr_view()`, `dispatch_cli_invariant_problem()`, the final `match` and exit codes.
- `record.php` whole (`pipeline_record_table()`, `pipeline_record_pr()`); `dispatch.php` whole
  (`LegStatus::allowedFor()`, `pipeline_return_problem()`, `pipeline_pr_problem()`); `brief.php` whole
  (`pipeline_leg_overrides()`, the base block in `pipeline_brief_overrides()`,
  `pipeline_record_commands()`, `pipeline_brief_return()`, `pipeline_cli()`); `kickoff.php` whole
  (`pipeline_kickoff_gh()`, `pipeline_kickoff_claim()`, `pipeline_kickoff_on_base()`,
  `pipeline_kickoff_prepare()`'s upstream removal); `board.php`, `suite.php`, `manifest.php`,
  `pipeline.php`, `ci.php` whole.
- `workflow/pipeline-autoflow.js` whole. The Workflow script reference: *"No filesystem or Node.js API
  access"*, so a script cannot run a command.
- `tests/DispatchCliTest.php`'s `dispatch_fixture()`, `dispatch_cli()`, `ci_fixture()`,
  `kickoff_fixture()` and their fake `gh`; `tests/RecordCliTest.php`'s `record_fixture()`;
  `tests/BaseStateTest.php`'s `base_repo()`; the `handoff` mentions in every test file.
- `references/engine.md` §The loop, §`autoflow`, §Agents per step, §Interactive, §The work item,
  §Kickoff, §Stations, §Design size, §Who takes the PR out of draft, §Closing links, §What a leg brief
  consists of, §Catching up with the base, §Failure policy; `manifest.md` §What a leg writes and
  §Invariant check; `SKILL.md` whole; `README.md` *Permissions for unattended runs*.
- The `handoff` skill's `SKILL.md`: A.3, B.3, D, E.2.a–d. `work-on/SKILL.md`'s table row for a PR
  without a handoff prompt.
- `gh pr create --help`, `gh pr list --help`, `gh pr edit --help` (gh 2.85.0): `--head` *"to explicitly
  skip any forking or pushing behavior"*; `--base`, with `gh-merge-base` read only when it is absent;
  `pr list --head` and `--state`; `pr edit --base`, `--body`.
- `gh pr list` of this repo: every run's PR is titled `Implement: <spec H1> (issue: #<n>)`.
- Issues #125, #118, #122; `docs/superpowers/specs/2026-09-30-pipeline-dispatch-cli-write-verbs-design.md`.

## Out of scope

- Changing or retiring the `handoff` skill, or `work-on`'s use of it.
- Removing `plan-insufficient` from `handoff:run`, or running the Bounded escalation check in code.
- Running the step without an agent.
- Editing the title or replacing the body of an existing PR.
- Converting a ready PR back to draft: that halt names `gh pr ready --undo`, the owner's call.
- A retry of a failed push or gh call inside the command.
- Where a PR should be Dutch (left open by `8d85feb`).

## Assumptions

Each is a question the brainstorm would have put to the owner, with the answer assumed.

1. **Does the step stay an agent?** Yes. A Workflow script has no filesystem or process access, so
   only an agent can run the command; in `interactive` the dispatched background agent runs it too.
   The agent, its model and effort, and the script stay as they are.
2. **Is `handoff` the command's name?** Yes: the issue proposes it, it is the leg's name, and
   `dispatch_cli.php` has no command by it.
3. **Does the command write the manifest itself, or print a number for `record --pr`?** Itself, through
   `record`'s code. The issue asks for it, and one call leaves no state between the PR and the write.
4. **Does it record its own halts?** Yes. The alternative is a refusal the sonnet-low agent must turn
   into `record --status halted`, and an agent told *"fix what it names"* about a refused push may
   force it. The brief says to repair nothing.
5. **Is a transient gh failure retried?** No. It is a halt; a resume runs the command again, and every
   act in it is idempotent.
6. **Which PRs are adopted?** An open PR whose head is the run's branch, when it is a draft. A ready
   one halts with the invariant check's words; a closed or merged one is not the run's PR, and a new
   one is created.
7. **Is an existing PR's body rewritten?** No, only added to, and only when it lacks the spec path, the
   plan path or any reference to the issue. Later legs write into that body.
8. **Is an existing PR's title changed?** No.
9. **What is the title's form?** `Implement: <spec H1 less " — design"> (issue: #<n>)`: the form the
   repo's PRs have, in English, with the design suffix cut because the PR is not a design.
10. **Is the spec's heading translated when it is not English?** No. Design steps write English specs;
    the command adds only fixed English words. *A run's PR title and body are English* holds for what
    the command writes.
11. **Is the push forced, ever?** No. A rejected push is a halt.
12. **Does a failing Component call stop the run?** No, it is a note in the answer, as kickoff's claim
    is. An `invalid` `## Board` section does halt, before the push, as it does at kickoff.
13. **Does a run without `base` pass `--base`?** No. gh then opens into the default branch, as today. A
    repo that declares a repo-level `--base` in its `worktree.create` is unchanged by this.
14. **Does the `gh-merge-base` config go, as the issue says?** Yes. Nothing else in this repo reads it
    (grep: `kickoff.php`, its two tests, one `engine.md` sentence, `manifest.md`'s `base` row is about
    the manifest key). A worktree kicked off before this change keeps a config that now has no reader.
15. **Does `handoff:run` keep `plan-insufficient`?** Yes. The status, the plan-gap brief lines and the
    script's routing for it are shared by every step after `design`; taking it from one step changes
    `LegStatus::allowedFor()`, the tables and their tests for no halt avoided.
16. **Does `record --status continued --pr` stay valid for this step?** Yes, for a repair by hand and
    the existing replays; the brief no longer prints it.
17. **Will auto mode allow the call?** Assumed yes: `kickoff`, which creates a worktree and edits the
    board from inside one `php` call, runs in the same sessions. It cannot be probed from a design
    step. A denial is a halt naming the command, and the README says which rule allows it.
18. **Is the PR read back after `gh pr create`?** Yes, with the same `gh pr list` that looked for it.
    One more call, and the number never depends on the text `create` prints.
19. **Does the PR say anything about the base?** No. §Closing links has the finish step say that a
    merge into the base closes nothing.
20. **Is a changelog entry needed?** No: this repo has no `.changelog/` directory and no `CHANGELOG.md`.

Added by the plan step, where the plan needed an answer the design above does not give:

21. **What does an existing body gain when it names both paths but not the issue?** Only
    `Part of #<n>.`, in front. And `#<n>` counts as named only as a whole number: a body that holds
    `#1250` does not name `#125`.
22. **What does the answer of a recorded halt hold?** `record`'s whole answer (`last_sha` and `entry`
    included, as `record --status halted` prints them) with `reason` added; the example under *The
    answer* abbreviates it. A halt whose record is refused answers that refusal, with the halt's reason
    appended to it.
23. **Which manifest does the step read?** The snapshot, which is what `record` builds on. And
    `handoff.php` asks its git runner (`cat-file -e HEAD:<path>`) whether the spec and the plan exist at
    `HEAD`: `dispatch_cli_exists_at()` lives in the CLI script, which the pure tests do not load.
24. **What if git's or gh's words are not valid UTF-8?** They are scrubbed (`mb_scrub()`) before they go
    into a reason or a note, so the halt can still be written and printed as JSON.
25. **What if `gh pr create` succeeds and the read-back lists no PR?** A halt that says so; the next run
    of the step adopts the PR. The number is never parsed from what `create` printed (Assumption 18).
26. **How does `## Return` word the refusal of the `handoff` command?** As a halt with its reason, where a
    refused `record` still says *fix what it names and run it again*: the two differ, and the brief's
    *repair nothing* line would otherwise contradict the return contract.
