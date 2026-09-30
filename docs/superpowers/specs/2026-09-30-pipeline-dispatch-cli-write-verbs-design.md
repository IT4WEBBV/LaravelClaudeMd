# A step writes the manifest through `dispatch_cli.php record`, not by hand — design

**Design size:** Architectural

**Date:** 2026-09-30
**Issue:** IT4WEBBV/LaravelClaudeMd#52, and with it #122 and the tested-command part of #118
**Canonical home:** `skills/pipeline/checks/record.php` (new: what a step's write looks like),
`skills/pipeline/checks/dispatch_cli.php` (the `record` and `suite` commands),
`skills/pipeline/checks/brief.php` (`## Return` and the override lines that describe JSON today),
pipeline `references/manifest.md` §What a leg writes and `references/engine.md` (the sections that say a
step "writes the manifest").
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. Every question the brainstorm
would have asked is answered in *Assumptions*, so `/critique plan` audits exactly those. Nothing below was
built or run; what it relies on was read (see *What was read*). No probe was needed: no choice below
hinges on whether an approach works at all.

## Problem

Every step ends by writing its results into the manifest, and every step does that by hand, with whatever
tool it picks. The brief describes the JSON shape in prose; the check at the next boundary
(`pipeline_return_problem()`) halts the run when the shape is wrong. Four halts in this repo came from
that write, none from the step's actual work:

1. **#104**: a `design` step added `actions` to a plan-gap ledger entry. The next brief halted on *the leg
   rewrote ledger entry 1* after a full design leg, and the manifest was repaired by hand from the
   snapshot.
2. **#122, first**: `handoff:run` wrote with `jq … > /tmp/m.json && mv …`. `jq` is not installed, `&&`
   skipped the `mv`, and the agent returned `continued`.
3. **#122, second**: `handoff:run` located the manifest with `glob(".claude/pipeline/*.json")[0]`, which
   sorts `<stem>.before.json` first. `artifacts.pr` and the status landed in the dispatcher's snapshot.
4. **#118**: `handoff:run` wrote with a Python heredoc that had shell lines in it. `SyntaxError`, exit 1,
   manifest unchanged, `continued` returned.

In 2–4 the PR was already open, so a plain resume would have opened a second one. The check worked each
time; the run still stopped on a mechanical failure, after the expensive part was done.

## Settled by the owner

- This run delivers #52, and with it #122: *a step brief names one way to write the manifest that cannot
  silently leave it unchanged, and names the manifest by its full path so the `.before.json` snapshot is
  never written.* It also delivers the tested-command part of #118. (2026-09-30)
- From #52: the fail-closed check (`returned`, and `brief --after` / `finish` in `autoflow`) stays the
  gate. The commands only make passing it the easy path.

## Approaches

1. **One command for a step's return, which checks its own result before it writes (chosen).**
   `record <manifest> <leg> <step> --status <status> [what that step adds]`. It builds the manifest the
   step should leave, runs the boundary's own check over it against the snapshot, and writes only when
   that check passes. `gate`, `leg`, `cycle`, `at`, `reviewed_sha`, `outcome`, `annotations` and
   `last_sha` are stamped by code. A refusal exits non-zero with the manifest untouched and names what
   is wrong, while the agent is still there to fix it. One more command, `suite`, covers the one write a
   step makes before it returns.
2. **Small verbs, one per kind of write** (#52's own example: set an artifact, append an open review,
   complete the open entry, set the status). Each verb is simple, but a return becomes three or four
   calls. Any one can be skipped, which is today's failure with more steps; the manifest passes through
   states no check accepts, so a verb cannot check its own result; and `design:spec`'s *"in one manifest
   write"* (setting `artifacts.spec` and removing `artifacts.plan` together) would need its own rule
   again. Rejected.
3. **A brief line only** (#122's second direction: *"`jq` is not available; read the manifest back"*).
   No code, and it fixes the first #122 instance only if a low-effort agent follows it. It does nothing
   for #104's shape or for the glob. The owner's decision asks for a way that *cannot* fail silently.
   Rejected.

## Design

### `record`: the step's one write

```
php dispatch_cli.php record <manifest> <leg> <step> --status <status> [flags]
```

- **`<leg> <step>`** are given as `brief` takes them, and must be the step the snapshot
  `<manifest stem>.before.json` was taken for (`dispatch_cli_snapshot_step()`). That ties a record to the
  step whose `brief` or `next` ran, and lets every flag be judged for one known step.
- **It starts from the snapshot, not from the file.** The result is the snapshot, plus the manifest's
  current `suite` (the one write a step makes earlier, below), plus what the flags add. So a second
  `record` in the same step replaces the first instead of stacking on it, and nothing a hand edit left
  in the file can reach the result. The answer names the keys it found changed and did not keep
  (`replaced`), so an overwritten hand edit is visible.
- **It checks before it writes.** The candidate goes through `pipeline_return_problem($before, $candidate,
  $leg, $step, $size)`, the function the next boundary runs. A problem is a refusal. What `record`
  writes therefore passes the manifest half of the next check by construction; the other half compares
  what the step tells the script (`status`, `ui`, `size`) and is unchanged.
- **It reads its write back.** After `manifest_write()` it reads the file and compares; a difference is
  a refusal (*the write did not land*).
- **It answers** one JSON line on stdout.
  `{"action":"recorded","leg":…,"step":…,"status":…,"last_sha":…,"entry":<index|null>,"replaced":[…]}`
  with exit 0, or `{"action":"refused","reason":…}` with **exit 1** and the manifest untouched. A usage
  error is exit 1 with the usage on stderr, as for every command. This is the one place the file's
  *"exits 0 on every decision"* does not hold: a refusal is not a decision about the run, and a
  non-zero exit is what makes a failed write impossible to miss in an `&&` chain.

What `record` stamps, so no step writes it:

| Field | Value |
|---|---|
| `cursor.status` | `--status`; `cursor.reason` only with `halted` |
| `last_sha` | `git rev-parse HEAD` in the worktree, on every status but `halted` |
| an entry's `gate`, `leg` | from the step (`pipeline_gate_of()`) |
| `cycle` | `pipeline_next_cycle()` for that gate; `unknown` stays `unknown` |
| `at` | now, UTC, `Y-m-d\TH:i:s\Z` |
| `reviewed_sha` | `git rev-parse HEAD`, on a `pr-review` entry |
| `annotations` | the content triggers that fired: `pipeline_triggers()` over `git diff <base ref>...HEAD` (`pipeline_base_ref()`), with the worktree's `composer.json` `name`; the names among `package`, `migration`, `auth` |
| `outcome` | the status, on the entry a resolve step completes and on `verify-ui`'s; `looped-back` or `escalated` on a plan gap or an escalation |

### What each step passes

One table in `record.php` holds, per `<leg>:<step>` and status, the flags that are required and the flags
that are allowed. `record` validates against it and `pipeline_brief_return()` prints the commands from
it, so the brief cannot name a flag `record` refuses. A flag the step's row does not list is a refusal
that names the flags the row does list.

| Step | `continued` | `looped-back` | `plan-insufficient` |
|---|---|---|---|
| `design:spec` | `--spec <path>`; `--plan <path>` exactly when that spec is Bounded | | |
| `design:plan` | `--plan <path>` | | |
| `design:run` (`interactive`) | `--spec <path> --plan <path>` | | |
| `review-plan:review`, `review-pr:review` | `--review-file <path>` | | `--reason <text>` |
| `review-plan:resolve` | `--actions-file <path>` | `--actions-file <path>` | |
| `review-pr:resolve` | `--actions-file <path>`, `[--issue-link <n>=<outcome>]…` | the same | |
| `handoff:run` | `--pr <number>` | | `--reason <text>` |
| `implement:run` | nothing | | `--reason <text>` |
| `verify-ui:run` | `--proof <path>` | `[--proof <path>]` | `--reason <text>` |

Every step also takes `--status halted --reason <text>`, and nothing else with it. An empty cell is a
status the step may not return (`LegStatus::allowedFor()`); a test holds the table and that function
together.

What the flags do:

- **`--spec`, `--plan`**: set `artifacts.spec` / `artifacts.plan`, stored relative to the worktree. The
  file must exist at `HEAD` (`git cat-file -e HEAD:<path>`, the test `launch`'s invariant check makes),
  so an uncommitted spec is refused here and not at the next `launch`. On `design:spec` the size is read
  from that spec (`DesignSize::fromSpec()`): Architectural removes `artifacts.plan` in the same write
  and refuses `--plan`; Bounded requires `--plan`. That is the rule `pipeline_design_step()` routes by,
  now produced by code.
- **`--pr`**: digits; sets `artifacts.pr` as an integer. No `gh` call: `launch`'s invariant check reads
  the PR's state.
- **`--proof`**: an existing file; sets `artifacts.proof`. `verify-ui` also gets its thin entry
  (`gate`, `cycle`, `at`, `outcome`) appended, on `continued` and on `looped-back`.
- **`--review-file`**: the review, verbatim, in a file the step writes with its file tool. `record`
  appends the open entry: `gate`, `leg`, `cycle`, `at`, `review`, `annotations`, and `reviewed_sha` on
  `pr-review`, with no `outcome`. The text is stored as read, less trailing newlines; an empty file is
  refused.
- **`--actions-file`**: a JSON list, `[]` allowed, of `{claim, disposition, note}`: `claim` a non-empty
  string, `disposition` one of `integrated`, `recorded`, `open-question` (a backed enum,
  `ActionDisposition`), `note` a string, no other key. `record` completes the open entry with `actions`
  and `outcome`. Invalid JSON or a wrong item is a refusal that names the item and the key.
- **`--issue-link <n>=<outcome>`**, repeatable, `review-pr:resolve` only: `outcome` one of `closes`,
  `stays-open`, `dropped-but-closes`; writes `issue_links` on the completed entry. Absent when not given.
- **`--reason` with `plan-insufficient`**: what the plan lacks. `record` reads the design size from the
  committed spec (`dispatch_cli_design_size()`) and appends the entry that size calls for: on Bounded
  `{gate: design-size, leg, at, reason, outcome: escalated}`, on Architectural
  `{gate: plan-approval, leg, cycle, at, reason, outcome: looped-back}`. A review step that returns it
  gets no review entry, because there is no flag that would add one.

**The two files sit beside the manifest**, where the diff and the snapshot already are:
`<manifest stem>.review.md` and `<manifest stem>.actions.json` (`dispatch_cli_files()` names them). That
directory is out of git and out of §Suite reuse's tree key, so a review step stays read-only on the
checkout. `record` refuses a file older than the snapshot, as the boundary check refuses an `implement`
diff older than its snapshot: a review step that forgot to write its review cannot submit the previous
one.

**#104 is closed by absence.** A `design` step's row has no ledger flag, and the result starts from the
snapshot, so a plan-gap entry cannot gain `actions`.

### `suite`: the write a step makes before it returns

```
php dispatch_cli.php suite <manifest> --outcome green|red --passed <n> --failed <n>
```

`implement` records a suite after every full run, and the finish step after its own. `suite` computes
`tree` itself (`pipeline_tree_key()` in the manifest's worktree), stamps `at`, and writes only the
`suite` key into the manifest as it is. It refuses `green` with a non-zero `--failed`, a key it cannot
compute (the machinery failure §Suite reuse names), and a write that did not land; its answer and exit
codes are `record`'s (`{"action":"recorded","suite":{…}}`). Whether a suite run is needed stays the
`php -r` read in §Suite reuse: this change is about writes.

### Naming the manifest (#122)

- Every command the brief prints carries the manifest's full path and the checks directory's full path
  (`__DIR__` of `brief.php`, the directory `launch` hands the script as `checks`), so a step copies a
  command and never looks for the file.
- `record` and `suite` refuse a path that ends in `.before.json`: *that is the dispatcher's snapshot;
  the manifest is `<path without .before>`*. A snapshot path would find no snapshot of its own anyway;
  the dedicated message says what to do.
- `## Return` says in one sentence that `<stem>.before.json` beside the manifest is the dispatcher's
  snapshot and that the manifest is never found by a glob.

### The brief

`pipeline_brief_return()` takes the manifest path and prints, per step, the literal command for each
status it may return. For `handoff:run` in `autoflow`:

> ## Return
>
> Your last act is one `record` command. It is the only way you write the manifest: do not edit the
> file, and never find it by a glob (`<stem>.before.json` beside it is the dispatcher's snapshot).
>
> - `php <checks>/dispatch_cli.php record <manifest> handoff run --status continued --pr <number>`
> - `… --status plan-insufficient --reason "<what the plan lacks>"`
> - `… --status halted --reason "<why>"`
>
> It prints `{"action":"recorded",…}`, or `{"action":"refused","reason":…}` with exit 1 and the manifest
> untouched: fix what it names and run it again. Return the `status` it printed as your structured
> `{status, reason}`. When it refuses a `halted`, return `halted` with its reason all the same.

`interactive` ends on *"and reply with one line naming it"* as today. The sentence listing the writable
keys goes: the commands are the contract.

The override lines that describe JSON change to say what to pass:

| Step | Today | After |
|---|---|---|
| `design:run`, `design:spec`, `design:plan` | *"Set `artifacts.spec` and remove `artifacts.plan` … in one manifest write"* and its variants | commit first; the paths go to `record` (`--spec`, `--plan`), which sets and removes what the size calls for. `design:spec` keeps *"a halt before that leaves the manifest calling for this step again"* |
| the two review steps | *"Append its review verbatim as a new … ledger entry with `gate`, `leg`, `cycle`, `at`, …"* | *"Write its review verbatim to `<stem>.review.md`; `record` appends it as the open entry."* |
| the two resolve steps | `$completeEntry`; *"write `issue_links` on the entry"* | *"Write what you did with each point to `<stem>.actions.json` as `[{claim, disposition, note}]`"*; the closing links go as `--issue-link` |
| `handoff:run` | *"Set `artifacts.pr`."* | the number goes to `record --pr` |
| `verify-ui:run` | *"set `artifacts.proof` …"*, *"Append the thin `verify-ui` entry …"* | the path `write` printed goes to `record --proof`; the entry line goes |
| `implement:run`, `review-pr:resolve` | *"Record `suite` after every full run"*, *"record `suite`"* | the literal `suite` command |
| `pipeline_plan_gap_lines()` | *"append a `plan-approval` entry with `leg`, `cycle`, `at`, a `reason` … then return `plan-insufficient`"*, and the `design-size` twin | *"return `plan-insufficient` with `--reason` naming what the plan lacks"*; the conditions (escalation check first on Bounded; the size alone is no gap) stay word for word |

The pointer *"this review's `cycle`"* goes from `pipeline_brief_pointers()`: `record` stamps it, and a
brief holds nothing a station does not ask for. *"the open review: `gate_ledger[n]`"* stays; the resolve
step reads it.

### Where the code goes

- **`record.php`** (new, pure, no I/O): the step table; `ActionDisposition`; and
  `pipeline_record(array $before, array $current, string $leg, string $step, array $given, array $facts): array`,
  which returns the candidate manifest or a refusal reason. `$given` is the parsed flags with the two
  files already read; `$facts` is what only the machine knows: `head`, `now`, `annotations`, the design
  size, and which of the given paths exist at `HEAD`. Every fact is a value, so the tests need no git.
- **`dispatch_cli.php`**: `dispatch_cli_record_command()` and `dispatch_cli_suite_command()` parse the
  arguments, read the files, gather the facts with `pipeline_git_run()`, run the boundary check, write,
  read back, and answer. The final `echo`/`exit` takes its exit code from the answer (`refused` is 1).
  The header comment and the usage line gain both commands.
- **`dispatch.php`**: unchanged. `pipeline_return_problem()` and `pipeline_leg_writable_keys()` are the
  contract `record` writes to; neither learns about `record`.
- **The git-at-`HEAD` test** that `dispatch_cli_invariant_problem()` makes inline becomes one small
  function both it and `record` call.

### When a fact cannot be read

A `record` that needs `HEAD`, the diff for `annotations`, or a path at `HEAD`, and cannot get it, refuses
and names the git call. It never writes an entry with a fact left out. `--status halted` needs none of
them, so a step can always record its halt; and when even that is refused (no snapshot), the step returns
`halted` to the script with `record`'s reason, and `finish` writes the cursor as it does today.

### The documents

- **`manifest.md` §What a leg writes**: a step writes through `record` (and `suite`); the keys and
  statuses stay listed (`LockStepTest` pins them); the checks stay as described, and are said to accept a
  hand-written manifest that holds, which is how a repair by the owner still works.
- **`engine.md`**: §The loop's control rule and §`autoflow`'s step bullet say *record*, not *writes the
  manifest*; the `<manifest stem>` paragraph names the two new files; §Interactive's inline steps end on
  `record` before `returned`; §Design size's escalation step 1 and plan-gap step 1 say the entry is
  `record`'s; §Suite reuse's *Record* bullet names `suite`; §What a leg brief consists of says the return
  contract is the commands.
- **`gates.md`**'s command list and **`SKILL.md`**'s one-line description of a step gain `record`.

### What does not change

The manifest's shape, the ledger's shapes, `pipeline_leg_writable_keys()`, every check in `dispatch.php`
and the halts they produce; the workflow script (its prompt already ends on *"Finish as the brief's
`## Return` says"*, and `size` and `ui` stay the read-only commands they are, run after `record`); the
routing tables; `launch`, `brief`'s checks, `finish`, `ci`, `kickoff`; `manifest_write()` for its other
callers; `run_audit.php`.

## Tests

Pest, in `skills/pipeline/checks/tests`, run with
`./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`.
Test-first, in `implement`.

- **`RecordTest.php`** (new, pure): for every step of both modes and every status `LegStatus::allowedFor()`
  gives it, `pipeline_record()` over valid flags returns a manifest that `pipeline_return_problem()`
  accepts; the table has a row for exactly those pairs. Per shape: the open review entry, with
  `reviewed_sha` only on `pr-review`; the completed entry keeps its seven kept keys; `issue_links`; the
  thin `verify-ui` entry; the escalation on Bounded and the plan gap on Architectural; `design:spec`
  removes `artifacts.plan` on Architectural and requires `--plan` on Bounded; `last_sha` absent from a
  halt. Refusals: a flag the row does not list; a missing required flag; an uncommitted spec; a bad
  action item; a bad issue link; `halted` without a reason. The result starts from the snapshot: a
  second record does not stack, `suite` is kept, a hand-added `actions` on a plan gap is dropped and
  named in `replaced`.
- **`RecordCliTest.php`** (new; a real throwaway repo, as `ReviewScopeTest.php` builds them, with `brief`
  or `next` run first): `record` prints `recorded`, exits 0, and the next `brief --after` / `returned`
  accepts the manifest; a refusal exits 1 and leaves the file byte-identical; the snapshot's path is
  refused by name; no snapshot, and a snapshot of another step, are refused; a review file older than
  the snapshot is refused; `--status halted` records outside a git repository; `suite` writes the tree
  key of the worktree and nothing else, and refuses `green` with failures.
- **`BriefTest.php`**: `## Return` carries the literal `record` command with the manifest's full path on
  every step of both modes, one line per allowed status; it names `.before.json`; no override line says
  *ledger entry with `gate`* or *Set `artifacts`*; the `cycle` pointer is gone.
- **`AutoflowScriptTest.php`**: `autoflow_replay.mjs`'s stub step gains a `record` form (it runs the
  `record` command with the given flags where today it merges `write` into the file); one replay walks
  `design:plan` to `done` with every step writing through `record`. The existing hand-written replays
  stay: a hand-written manifest is still what the checks judge.
- **`LockStepTest.php`**: still green; `manifest.md` keeps every status and writable key in §What a leg
  writes.

## What was read

- `skills/pipeline/checks/dispatch_cli.php` whole: `dispatch_cli_files()`, `dispatch_cli_brief()`,
  `dispatch_cli_boundary_problem()`, `dispatch_cli_return_problem()`, `dispatch_cli_snapshot_step()`,
  `dispatch_cli_design_size()`, `dispatch_cli_invariant_problem()`, `dispatch_cli_git()`, the final
  `match` and its exit codes.
- `skills/pipeline/checks/dispatch.php` whole: `LegStatus::allowedFor()`, `pipeline_return_problem()`,
  `pipeline_ledger_problem()` and its kept keys, `pipeline_review_entry_problem()`,
  `pipeline_reported_problem()`, `pipeline_normalized()`, `pipeline_keep_retried()`,
  `pipeline_changed_keys()`, `pipeline_design_step()`.
- `skills/pipeline/checks/brief.php` whole: `pipeline_leg_overrides()`, `pipeline_brief_pointers()`,
  `pipeline_next_cycle()`, `pipeline_plan_gap_lines()`, `pipeline_brief_return()`, `pipeline_base_ref()`.
- `manifest.php`, `suite.php` (`pipeline_git_run()`, `pipeline_tree_key()`), `triggers.php`
  (`pipeline_triggers()`), `pipeline.php` (`pipeline_is_plan_gap()`, `pipeline_is_plan_return()`).
- `skills/pipeline/workflow/pipeline-autoflow.js` whole; `tests/DispatchCliTest.php`'s fixture and CLI
  runner; `tests/AutoflowScriptTest.php`'s stub passes and `autoflow_replay.mjs`'s `play()`;
  `tests/LockStepTest.php`'s manifest.md test.
- `references/manifest.md` whole; `references/engine.md` §The loop, §`autoflow`, §Design size, §What a
  leg brief consists of, §Suite reuse; `references/gates.md`'s command list and trigger snippet.
- Issues #52, #122, #118 and their comments.

## Out of scope

- #118's other suggestions: `handoff:run` adopting an existing draft PR on resume, and the prompt file
  written to `/tmp`.
- The script taking a step's status from the manifest instead of from its structured return.
- A command for the *"is a suite run needed"* read, and for the escalation check: both are reads.
- Refusing a hand-written manifest at the boundary: the checks judge the manifest, however it was
  written.
- The smoke run's own stub prompt (`args.stub.prompt`), which is not in this repo.

## Assumptions

Each is a question the brainstorm would have put to the owner, with the answer assumed.

1. **One command per return, or a verb per kind of write as #52 sketches?** One, `record`, plus `suite`
   for the write that happens mid-step. A return in several calls can be left half done, and no single
   call could check its own result.
2. **Is `record` the name?** Yes: #118 proposes it, and it does not collide with a command
   `dispatch_cli.php` has.
3. **May `record` refuse, or must it always write?** It refuses, with exit 1 and the file untouched, when
   the result would not pass the boundary check. A refusal costs one more call in the same step; a
   written manifest that fails the next brief costs the run.
4. **Does a refusal break *"exits 0 on every decision"*?** Yes, on purpose, for `record` and `suite`
   only.
5. **Does a second `record` in one step add to the first or replace it?** It replaces it: the result is
   built from the snapshot. A hand edit made before it is replaced too, and named in `replaced`.
6. **Who supplies `cycle`, `at`, `reviewed_sha`, `last_sha`?** `record`. None is a judgement.
7. **Who supplies `annotations`?** `record`, from `pipeline_triggers()` over the branch's diff against
   its base. They are facts about the diff (`gates.md` §content triggers), which `manifest.md` says to
   derive and not to trust from a hand. When the diff cannot be read, `record` refuses.
8. **How does a long review reach the command?** As a file beside the manifest, written with the step's
   file tool. A heredoc in a shell call is where #118 failed, and an argument would need the review
   quoted for the shell.
9. **And the actions?** A JSON file beside the manifest, validated item by item. A repeatable flag per
   action would put three free-text fields through shell quoting.
10. **Must the two files be fresh?** Yes, newer than the snapshot, as the `implement` diff must be.
11. **Does `record` check the PR with `gh`?** No. It stays offline; `launch` checks the PR once per
    launch.
12. **Is `last_sha` stamped on a halt?** No: the field is *HEAD at the last completed leg*, and a halted
    step completed nothing. It is stamped on `looped-back` and `plan-insufficient`: those steps finished.
13. **Are spec and plan paths stored relative to the worktree?** Yes; an absolute path under the
    worktree has the prefix stripped. Every reader already accepts both.
14. **Is `--proof` required when `verify-ui` loops back?** No. It is required on `continued`: §Failure
    policy allows no visual claim without proof.
15. **Does `interactive` use `record` too?** Yes. `next` takes a snapshot for every step, inline ones
    included, so the session records a human's resolution with the same command, then runs `returned`.
16. **Do the briefs still list the writable keys?** No. The commands replace the list; `manifest.md`
    keeps it for the checks.
17. **Does the workflow script change?** No. `size` and `ui` stay separate read-only commands, run after
    `record`; folding them into `record`'s answer would change the script's prompt and its replay tests
    for no fewer halts.
18. **Do the existing tests that write the manifest by hand move to `record`?** No. They test the checks
    against hand-written manifests, which stay legal input. One replay is added that writes only through
    `record`.
19. **Is a changelog entry needed?** No: this repo has no `.changelog/` directory and no `CHANGELOG.md`.
