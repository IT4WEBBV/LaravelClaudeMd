# `dispatch_cli.php kickoff` — the unattended kickoff as one tested command — design

**Design size:** Architectural

Issue #69. Builds on `2026-09-23-pipeline-auto-workflow-design.md` (PR #50): everything after
kickoff in an `autoflow` run is a tested command (`launch`, `brief`, `finish`). Kickoff —
`references/engine.md` §The work item and §Kickoff — is still prose the invoking session carries out
by hand, and it is where the first real `autoflow` run (viewiemedia #2077) stumbled three times:

- the session filled `<next-free-N>` itself, chose slot 2, and the auto-mode classifier denied the
  `git worktree add` because the same session had marked slot 2 off-limits;
- the declared create path had a stale capitalisation that the repo's `slot-env.sh` rejects;
- the new branch tracked `origin/main`, so the session had to notice and unset it before any step
  could `git push`.

A classifier denial is not something to override. It is made unlikely by leaving nothing to judge,
and when a create fails anyway the run stops with the command's output instead of the session
improvising a second form of it.

## The command

```bash
php "$CHECKS/dispatch_cli.php" kickoff <repo-root> <number|idea> [--light] [--mode autoflow|auto] [--decision "<verbatim>"]...
# → {"action":"ready","manifest":…,"worktree":…,"branch":…,"notes":[…]}
#   | {"action":"halt","reason":…}
```

`<repo-root>` is the primary checkout: the directory the declared `worktree.create` runs in and the
one holding `.claude/work-on.config.md`. `--mode` defaults to `autoflow`; `interactive` keeps its
session-driven kickoff and is a usage error here. `--decision` repeats, one per settled decision, in
order. Like every other decision `dispatch_cli.php` prints, both answers are one JSON line and exit 0;
a usage error (no repo root, no item, an unknown flag or mode) exits 1.

`ready` names the manifest `launch` takes next. `notes` carries the lines for the owner's report
that the run does not act on: the board move it made, or a board claim that could not be recorded.
It is empty when there is nothing to say, so a board-less repo and an idea say nothing about a board
(engine.md §The work item, *`absent` is silent*).

## What it does, in order

Every step up to the create runs before anything exists, so a halt there leaves nothing behind.

1. **Read the config.** `.claude/work-on.config.md` under `<repo-root>`: `repo` (`## Repo`),
   `create` (`## Worktree`), `issue` (`## Branch convention`), each value with its trailing `# …`
   comment stripped as `board.php` does. A missing file, or a missing key the item needs, halts
   naming it. `## Board` goes through `pipeline_repo_board()`; `invalid` halts with its `error`
   (a machinery failure, engine.md §The work item), now, before a worktree exists.
2. **Resolve the item** (a number, with or without a leading `#`; anything else is an idea).
   - A number: `gh api repos/<repo>/issues/<n>`, decoded in PHP. `gh` failing halts: a number that
     was given and does not resolve is an error. A PR (`pull_request` not null) halts: kickoff starts
     runs for issues and ideas, and a PR's run resumes with `launch --from`.
   - Its blockers: `gh api /repos/<repo>/issues/<n>/dependencies/blocked_by`; any open one halts
     naming each as `#M title`. `gh` failing here halts too: a blocker check that could not run is
     not a pass.
   - An idea skips both, silently.
3. **The branch.** An issue: `branch.issue` with `<number>` and `<slug>` substituted. An idea:
   `feature/<slug>`. `<slug>` is `pipeline_slug()`: lowercase; every run of characters outside
   `[a-z0-9]` becomes `-`; leading and trailing `-` stripped; longer than 50 characters, cut at the
   last `-` within the first 51, so a word ending at 50 is kept (a hard cut at 50 when there is
   none); a title or idea with nothing to slug halts. A pattern that still holds a `<placeholder>`
   after that halts naming the key (`- issue:` under `## Branch convention`). A branch outside
   `[A-Za-z0-9._/-]` halts.
4. **Already started?** A local branch of that name, or a worktree on it, halts: *"branch <b>
   already exists: a run or session has it; resume it with launch"*. This is also the double-run
   guard for two sessions kicking off one issue.
5. **The declared create, as declared.** `<branch>` is substituted and nothing else. A command that
   still holds a `<placeholder>` (`<next-free-N>`) halts naming the config line: *"the declared
   worktree.create needs <next-free-N>, which kickoff does not compute: `- create: …`"*. Slot
   choice belongs to the repo's script (`worktree.sh create` picks the free slot). The command runs
   through `sh -c` from `<repo-root>`, stdout and stderr captured together.
6. **A failed create halts with its output.** Non-zero exit (a classifier denial arrives as one):
   the reason is the command, its exit code and its output. Nothing is retried in another form.
   An exit 0 after which no worktree carries the branch (`git worktree list --porcelain`) halts
   too, with the output.
7. **After the create.** A failure from here on halts saying the worktree exists, so the owner
   knows there is something to look at.
   The worktree path comes from `git worktree list --porcelain`, the entry
   whose `branch` is `refs/heads/<branch>`, never from parsing the command or its output, so
   `git worktree add` and `worktree.sh create` are read the same way. Then:
   - **no upstream on the new branch:** when `<branch>@{upstream}` resolves,
     `git branch --unset-upstream <branch>`;
   - `pipeline_exclude_manifest(<worktree>)`;
   - the first `manifest_write` at `<worktree>/.claude/pipeline/<branch, / → ->.json` with `branch`,
     `worktree`, `mode`, `cursor: {leg: design, status: pending}`, `artifacts.issue` (an issue) or
     `artifacts.idea` (an idea, as given), `light: true` with `--light`, and `decisions` with any
     `--decision`. A key with nothing to say is absent, as engine.md §Kickoff has it.
8. **The board claim, last.** `valid`: `gh project item-add` then `gh project item-edit` to
   In Progress, the two calls engine.md §The work item shows, and the note *"#N is In Progress on
   board <number>"*. A failure is the note *"the board claim was not recorded: …"*, never a halt: the
   run is unaffected by a board that would not answer. It runs after the create, so a create that
   fails leaves no issue In Progress with no run behind it.

Nothing is guessed: every value comes from the config, `gh`, or `git`, and a value kickoff would have
to compute is a halt.

## Where it lives

- `skills/pipeline/checks/kickoff.php` — the pure parts (`pipeline_repo_config_value()`,
  `pipeline_slug()`, `pipeline_kickoff_branch()`, `pipeline_placeholder()`) and the probes that
  shell out (`pipeline_kickoff_gh()`, `pipeline_kickoff_create()`, `pipeline_worktree_of()`), plus
  `pipeline_kickoff()` that walks the steps above. `board.php` and `suite.php` are reused as they
  are; `pipeline_git_run()` runs every git command.
- `dispatch_cli.php` — the `kickoff` arm, its argument parsing, the usage line and the docblock.
- `tests/KickoffTest.php` — the pure parts. `tests/DispatchCliTest.php` — the command, end to end,
  against a throwaway origin and clone and a fake `gh` first on `PATH` (below).

## How it is tested

`dispatch_cli()` in the test already passes an environment; the kickoff tests prepend a directory
with a fake `gh` shell script to `PATH`. The script appends its arguments to a calls file and prints
canned JSON per call (the issue, its blockers, `item-add`'s id), so the tests can assert on what was
called and in which order. The repo is a bare origin with one commit on `main` and a clone of it
whose `.claude/work-on.config.md` declares
`git worktree add .claude/worktrees/<branch> -b <branch> origin/main`: the exact form that leaves an
upstream behind. Paths are compared through `realpath()` (`/var` is `/private/var` on macOS).

The cases, from the issue's *Done when* and the steps above:

| Case | Expected |
|---|---|
| an open blocker | halt naming it; no worktree, no branch |
| a create with an unresolved placeholder | halt naming the config line; no worktree |
| a create that fails | halt with its output and exit code |
| success | `ready`; the manifest holds `branch`, `worktree`, `mode`, `cursor`, `artifacts.issue`; the branch has no upstream; `.claude/pipeline/` is excluded |
| `--light`, `--decision` twice | `light: true`, `decisions` verbatim in order |
| a number that is a PR | halt; no worktree |
| the branch already exists | halt; the create never ran |
| an invalid `## Board` | halt before the create |
| a valid `## Board` | `item-add` and `item-edit` called, the move in `notes` |
| a valid `## Board` that will not answer | `ready`, the failure in `notes` |
| a valid `## Board` and a create that fails | halt; no `gh project` call |
| an idea | `feature/<slug>`, `artifacts.idea`, no `gh` call |
| an unknown flag or mode | exit 1 |

## Docs that change

- `skills/pipeline/SKILL.md` §`autoflow` — how a run starts and ends: step 1 becomes the `kickoff`
  command (its `ready` names the manifest; a halt is reported and nothing starts); step 2 `launch`
  takes that manifest.
- `references/engine.md`: the §`autoflow` diagram's first line names `dispatch_cli.php kickoff`;
  §The dispatcher's "It keeps: kickoff" runs it with `--mode auto`; §Kickoff gets one paragraph
  saying the unattended modes do §The work item and this section through `kickoff`, with its rules
  (only `<branch>` substituted, a placeholder or failed create halts, no retry, no upstream).
  `interactive` keeps the prose.
- `skills/orchestrate/references/commands.md` §Launch: kickoff is the command, the owner's decisions
  go in as `--decision` flags, and the manifest comes from `ready`. §Brief's `auto` worktree line
  names `kickoff --mode auto`.

Out of this PR: viewiemedia's config already declares `./scripts/worktree.sh create <branch>
--no-start` (IT4WEBBV/viewiemedia#2114, closed), which is the *Done when*'s second item.

## Alternatives considered

- **Keep kickoff prose, add a checklist.** The run-1 stumbles were each a judgement a checklist
  would still leave to the session. Rejected: the issue's point is that nothing is left to judge.
- **Compute `<next-free-N>` in kickoff.** It would duplicate the slot logic `worktree.sh` owns and
  move the classifier's objection into PHP without removing it. Rejected by the issue: slot choice
  belongs to the repo's script.
- **Print the bare manifest path** (the issue's wording). Every other `dispatch_cli.php` decision is
  one JSON line with an `action`, and the board note needs a place; `ready` carries the path as
  `manifest`. See *Assumptions*.

## Assumptions

Questions brainstorming would have asked, with the answer assumed.

1. *Output shape: a bare manifest path or a JSON line?* A JSON line,
   `{"action":"ready","manifest","worktree","branch","notes"}`, like every other decision the command
   prints; the halt keeps the issue's `{"action":"halt","reason"}`.
2. *How do the owner's settled decisions reach the first manifest?* A repeatable
   `--decision "<verbatim>"`, since engine.md §Kickoff has the first `manifest_write` carry them and
   `orchestrate` has them per issue.
3. *Which item kinds does kickoff take?* Issue numbers and ideas, as the issue's signature says. A PR
   number halts (a PR's run resumes with `launch --from`); spec-paths and existing branches are not
   kicked off here.
4. *Where does an idea go in the manifest?* `artifacts.idea`, as given (text or a path), so the
   design brief lists it among its pointers.
5. *No `worktree.create` declared: fall back to `scripts/worktree.sh create <branch>` as engine.md
   §Kickoff does for a slot-enabled repo without config?* No: halt naming the missing key. The
   fallback is a command kickoff would assemble itself, which is what this issue removes;
   `orchestrate` already requires the key.
6. *Board move before or after the create?* After, and only on success; an `invalid` board still
   halts before. engine.md §The work item puts the claim before the slow steps so a run is claimed
   early; the create is the step that fails in practice, and a claim ahead of a failed create leaves
   an issue In Progress with no run.
7. *An existing branch or worktree for the item?* A halt pointing at `launch`, never reuse: an
   existing branch belongs to a run or session kickoff cannot see, and `orchestrate`'s map decides
   adoption.
8. *`--mode interactive`?* A usage error. The issue names the two unattended modes; interactive's
   kickoff is the human's session.
9. *A closed issue?* Not checked: the issue names no such halt, and `orchestrate`'s map already reads
   the state.
10. *How is the command run?* `sh -c` from `<repo-root>`, because a declared command is a shell
    line (`./scripts/worktree.sh create <branch> --no-start`). The branch is substituted raw, which
    is safe because step 3 halts on a branch outside `[A-Za-z0-9._/-]`.
11. *What counts as a placeholder?* `<name>` with `name` matching `[A-Za-z][A-Za-z0-9_-]*`, so shell
    redirections (`2>&1`, `< file`) are not mistaken for one.
12. *Output of a failed create in the reason: all of it?* The last 40 lines, trimmed, so one noisy
    script cannot flood the halt.
