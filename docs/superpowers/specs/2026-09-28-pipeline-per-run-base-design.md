# A per-run base for `pipeline` and `orchestrate` — design

**Design size:** Architectural

**Date:** 2026-09-28
**Issue:** IT4WEBBV/LaravelClaudeMd#102
**Canonical home:** `pipeline_kickoff_base()` and `pipeline_kickoff_on_base()` in
`skills/pipeline/checks/kickoff.php`, `kickoff --base` in `skills/pipeline/checks/dispatch_cli.php`, the
base line in `pipeline_brief_state()` (`skills/pipeline/checks/brief.php`), and pipeline
`references/engine.md` §Kickoff, *A run on a base*, which holds the rule and why.
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. Every question the brainstorm
would have asked is answered in *Assumptions*, so `/critique plan` audits exactly those. Nothing below was
built or run; the `gh` and `git` behaviour it relies on was read (see *What was read*).

## Problem

`/pipeline autoflow` can only target the default branch. Every run is cut from `origin/<default>`, diffed
against it and opens its PR into it. ViewieMedia epic #2042 needs #2121–#2125 to land on the integration
branch `feature/issue-2042-kleurenpaletten` first and reach `main` in one go, because their deploy
operations may not run in production in between. The owner wants all code to go through the pipeline.

What stops it today, read in the code:

- `dispatch_cli_kickoff_args()` accepts only `--light`, `--mode` and `--decision`; `kickoff … --base <b>` is
  a usage error (exit 1).
- `pipeline_kickoff_create_command()` substitutes only `<branch>` into the declared `worktree.create`, and
  the fixture-style `git worktree add … origin/main` or `scripts/worktree.sh create <branch>` cuts from the
  default branch.
- `pipeline_kickoff_unclaimed()` halts on any existing branch with the issue's prefix, so pre-creating the
  branch on the integration branch is no workaround.
- `handoff pr` (DevOps-Claude-Config) runs `gh pr create --draft` without `--base`.
- engine.md, gates.md, manifest.md, pipeline `SKILL.md` and orchestrate `references/commands.md` diff with
  `git diff origin/<base>...HEAD`, but `<base>` is defined nowhere. A branch cut from the integration
  branch and diffed against `main` would review all earlier integration work as its own.

## Settled decisions (owner, passed with `--decision`)

1. Append `--base origin/<base>` to the declared `worktree.create`; no `<base>` placeholder, because
   `work-on` and `orchestrate` share that command and substitute only `<branch>`.
2. After a PR merges into the base, `orchestrate` closes that issue itself; kickoff keeps halting on open
   native blockers.

## Approaches

1. **A per-run `--base` on kickoff, recorded once in the manifest; everything downstream reads it from
   there (chosen).** Kickoff fetches and checks the base, appends `--base origin/<base>` to the create,
   verifies `HEAD`, sets `branch.<branch>.gh-merge-base` so `gh pr create` routes the PR without a
   `handoff` change, and writes `base` into the first manifest. Every brief states it; every `<base>` in
   the docs is defined as the manifest's `base`, else the default branch. One new key, one new flag.
2. **A `<base>` placeholder in the declared `worktree.create`.** Rejected by settled decision 1: the
   placeholder would reach `scripts/worktree.sh` literally from `work-on` and `orchestrate`, and a base
   belongs to a run, not to the repo.
3. **A `--base` flag on `launch` as well, or the base passed in `launch`'s JSON to the workflow script.**
   Two sources for one fact. The manifest already travels to every step, the brief is built from it, and
   `pipeline_leg_writable_keys()` already makes a leg that changes a key the dispatcher owns a return that
   does not hold. No `launch` or `pipeline-autoflow.js` change is needed.

## Design

### `kickoff --base <branch>` (`dispatch_cli.php`)

`dispatch_cli_kickoff_args()` accepts `--base <branch>` beside `--mode` and `--decision`: a value-taking
flag, `base` defaulting to `null` in the options. `--base` without a value is a usage error (exit 1), as
`--decision` without one is today. The header docblock, the `@return` shape and the usage string name it.
`pipeline_kickoff()`'s `@param` gains `base: ?string`.

### The base is checked before anything exists (`kickoff.php`)

New `pipeline_kickoff_base(string $repoRoot, ?string $base): ?string`, called in `pipeline_kickoff()`'s
first `try` after `pipeline_kickoff_branch()` and before `pipeline_kickoff_create_command()`. Null in,
null out. Otherwise, in order, each a `PipelineKickoffHalt`:

1. **Unsafe for `sh`**: the same `#^[A-Za-z0-9._/-]+$#` the branch passes →
   `the base '<b>' holds characters kickoff will not pass to a shell`.
2. **Not a branch on origin**: `git -C <repo> fetch -q origin +refs/heads/<b>:refs/remotes/origin/<b>`
   fails → `the base <b> is not a branch on origin: <git's stderr>`. The fetch is also what leaves
   `origin/<b>` current for the create and the `HEAD` check. The `+` accepts a force-pushed base.
3. **The default branch itself**: `git -C <repo> ls-remote --symref origin HEAD` names
   `ref: refs/heads/<default>`; a base equal to it → `the base <b> is origin's default branch: leave --base
   out`. When that line cannot be read → `origin's default branch could not be read: <stderr>`
   (fail-closed: the brief line below would otherwise tell every leg that a merge into `main` closes
   nothing).

A fetch updates only a remote-tracking ref; no worktree and no branch exist yet, so every halt here leaves
nothing behind, as `kickoff_left_nothing()` asserts.

### The create gets `--base origin/<base>` appended

`pipeline_kickoff_create_command(string $config, string $branch, ?string $base)` returns the declared
command with `<branch>` filled in, as today, and on a run with a base `"{$command} --base origin/{$base}"`.
The placeholder check still runs over the declared command before the append. The docblock names settled
decision 1 as the reason. A declared create that already carries a repo-level `--base` gets a second one;
the repo's script decides (the companion `worktree.sh` takes the last), and the `HEAD` check below catches
a script that does not.

### After the create: `HEAD` on the base, the PR routed into it

New `pipeline_kickoff_on_base(string $worktree, string $branch, string $base): void`, called first in
`pipeline_kickoff_prepare()` when `$base !== null` (its signature gains `?string $base`):

- `git rev-parse HEAD` in the worktree must equal `git rev-parse origin/<b>`; otherwise
  `the worktree's HEAD (<sha>) is not origin/<b> (<sha>): the declared worktree.create did not honour
  --base`. It is thrown inside the second `try`, so the halt the session sees is today's *"kickoff created
  <worktree> but could not finish it, and wrote no manifest: …; remove the worktree and its branch before
  kicking off again"*, naming what to clean up.
- `git config branch.<branch>.gh-merge-base <b>`. gh 2.85's `gh pr create` reads it when `--base` is
  absent, so `handoff pr` opens the PR into the base with no change in DevOps-Claude-Config.

A run without a base sets no `gh-merge-base`.

### `base` in the first manifest

`pipeline_kickoff_manifest()` adds `'base' => <b>` after `mode` when the option is set; a run without one
has no `base` key, so today's exact-manifest test stays as it is. `base` is not in
`pipeline_leg_writable_keys()`, so `pipeline_return_problem()` already halts a leg that changes it
(*"the leg changed base, which only the dispatcher writes"*): no routing change.

### Every leg is told (`brief.php`)

`pipeline_brief_state()` adds, after the decisions and before `last_sha`, on a manifest with `base`:

```
- base: `<b>`: this branch was cut from `origin/<b>` and its PR goes into it, not into the default branch; diff with `git diff origin/<b>...HEAD`, and a merge into it closes no issue (engine.md §Kickoff)
```

The state block is on every leg and step, so every brief carries it. A manifest without `base` gets no
such line.

### `<base>` defined once (docs)

engine.md §The loop, under the `<manifest stem>` paragraph: *`<base>` is the manifest's `base` on a run
kicked off with one (§Kickoff, *A run on a base*), and the repo's default branch otherwise: every
`origin/<base>` in this skill and in `orchestrate` means that.* That covers every `origin/<base>` diff in
engine.md, gates.md, manifest.md and `SKILL.md`, the CI gate's fix round, and the diff the session hands
`run_audit.php`. Pipeline `SKILL.md` step 2 and orchestrate `commands.md`'s preamble point at it.

### engine.md

- **§The loop**: the definition above.
- **§`autoflow`**: the kickoff line in the command block gains `[--base <branch>]`.
- **§Kickoff**: the command gains `[--base <branch>]`; *"with only `<branch>` substituted"* gains *"(and
  `--base` appended on a run with a base, below)"*; a new paragraph **A run on a base** with bullets —
  before anything exists (the three checks); the create gets `--base origin/<base>` appended, and why not a
  placeholder (settled decision 1, and a base belongs to a run); after the create (the `HEAD` check,
  `gh-merge-base`, `base` in the manifest); every leg after it (the brief line, the diffs, `launch` needs no
  flag, a leg that changes `base` does not hold); a merge into the base closes no issue; the pipeline never
  opens the base's own PR. The repo-level `--base` bullet points at it. *The first `manifest_write`* lists
  `base`.
- **§Closing links**: a paragraph — a run on a base reconciles the same way, records each issue's outcome
  as it would be on the default branch, and says in the PR body that the merge into `<base>` closes nothing
  and the issue closes when the base reaches the default branch (`orchestrate` closes it after the merge).

### manifest.md

A `base` row: optional; the branch kickoff's `--base` cut the run from and its PR goes into; absent means
the default branch; written once by kickoff; a leg that changes it halts; a reconstructed manifest recovers
it from `git config branch.<branch>.gh-merge-base`.

### pipeline `SKILL.md`

Step 1's kickoff gains `[--base <branch>]` and one sentence on what it does (cut from the base on origin,
recorded as `base`, PR into it; the base's own PR is never the run's). Step 2's diff names `<base>`'s
definition.

### `orchestrate`

- **`SKILL.md`**: a paragraph *A batch on a base*: `/orchestrate <issue> … base <branch>` (or a brief saying
  "with base <branch>") passes `--base <branch>` to every kickoff; `launch` takes it from the manifest; the
  branch must exist on origin; a merge into the base closes nothing, so after each merge the orchestrator
  closes that issue (Step 6), or its dependents halt at kickoff on an open blocker; the base's own PR into
  the default branch is not a run's, never opened or merged by the orchestrator unless its brief says so.
  Step 6 gains *In a batch on a base, close the merged run's issue (commands §Teardown).*
- **`references/commands.md`**: the preamble defines `<base>`; §Launch's kickoff bullet gains
  `--base <branch>` in a batch on a base; §Teardown ends with the close:
  `gh issue close N -R <repo> --reason completed --comment "Merged into <base> in #<P>; reaches the default
  branch with <base>."` — impersonal, per the repo's rule on writing to people.

### What does not change

`launch`, `brief`'s arguments, `finish`, `ci`, `dispatch.php`'s routing, `pipeline_leg_writable_keys()`,
`run_audit.php`, `pipeline-autoflow.js` (its implement prompt already takes `<base>` from the PR's
`baseRefName`, which `gh-merge-base` makes the base), `handoff` and `work-on` (DevOps-Claude-Config), and
`scripts/worktree.sh` in viewiemedia (the companion, IT4WEBBV/viewiemedia#2128, lands separately).

## Tests

Written first, each seen red. Suite: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml
--test-directory=skills/pipeline/checks/tests` (354 green on main).

`DispatchCliTest.php`, through the real CLI against the existing kickoff fixture (a bare `origin.git`, a
cloned primary, a fake `gh` on `PATH`), with a helper `kickoff_integration_branch()` that pushes a branch
one commit ahead of `main` to origin, then deletes both the local branch and the primary's remote-tracking
ref (so only kickoff's fetch can bring it back), and puts a `create-wt` script on `PATH` that honours
`--base`:

- a kickoff with `--base`: `ready`; the worktree's `HEAD` equals the pushed sha;
  `branch.<branch>.gh-merge-base` is the base; the manifest has `base`;
- no `gh-merge-base` without a base;
- a halt with nothing created (the create never ran; `kickoff_left_nothing()`) on a base missing on
  origin, on one unsafe for `sh`, and on origin's default branch;
- a halt on a create that ignores `--base`, naming the worktree it left and both shas;
- a usage error on `--base` without a value (a row in the existing *cannot parse* dataset).

`BriefTest.php`: the base line on `design`, `handoff`, `implement` and `review-pr` briefs of a manifest
with `base`, and no `- base:` line without one.

The whole pipeline suite passes.

## What was read

- `gh pr create --help` on gh 2.85.0: *"If not provided, the value of `gh-merge-base` git branch config
  will be used. If not configured, the repository's default branch will be used. Run `git config
  branch.{current}.gh-merge-base {base}` to configure."*
- `git ls-remote --symref origin HEAD` on this repository prints `ref: refs/heads/main\tHEAD` then
  `<sha>\tHEAD` (a read-only probe of the output shape, not of the plan's code).
- The declared `worktree.create` of every local it4web repo with one: all end in `create <branch>` or
  `create <branch> --no-start`, so an appended `--base origin/<b>` reaches `worktree.sh`.
- Closed PR #101 (commit `e1c39ad`, on the same base commit as this branch) holds an earlier
  implementation of this design; this design follows it and adds the default-branch check, the forced
  refspec, the fetch proven by the test, and the `gh-merge-base` note in manifest.md.

## Out of scope

- The base's own PR into the default branch: the owner's.
- A `<base>` placeholder in `worktree.create` (settled decision 1).
- `interactive` mode's kickoff, which the session does by hand from engine.md §Kickoff; the brief line and
  the `<base>` definition serve it once a manifest carries `base`.
- `scripts/worktree.sh create --base` in viewiemedia (#2128).

## Assumptions

Questions the brainstorm would have asked the owner, with the answer assumed.

1. **Does `launch` take a `--base` too?** No. "`launch` reads the base from the manifest" is read as: the
   manifest is the one source, and nothing in `launch`'s JSON or the workflow script needs it, since every
   step's brief is built from the manifest.
2. **Where in the brief does the base go?** In the *Settled decisions and state* block, which every step
   has, rather than an override line per leg.
3. **Should kickoff refuse `--base <default branch>`?** Yes (the third check). The issue does not ask for
   it, but without it the brief line would tell every leg a merge into `main` closes nothing, and the
   finish step would leave the issue open. One `ls-remote`, fail-closed.
4. **Plain or forced refspec for the fetch?** Forced (`+`): an integration branch may be rebased, and a
   stale `origin/<b>` that refuses the update would halt a valid run.
5. **Does the `HEAD` check halt with the worktree left behind?** Yes, inside the second `try`, with the
   existing *remove the worktree and its branch* text. Kickoff does not remove what a repo's script made:
   removal belongs to the declared `worktree.remove`, which kickoff does not run.
6. **Where does the `HEAD` check sit relative to unsetting the upstream?** Before it: a worktree on the
   wrong commit halts before anything else is changed in it.
7. **What does the finish step write on a run with a base?** Each issue's outcome as it would be on the
   default branch, plus that the merge into `<base>` closes nothing and the issue closes when the base
   reaches the default branch. No closing keyword is removed or added for the base's sake.
8. **Who closes the issue for a standalone `/pipeline` run on a base?** Nobody automatically; the PR body
   says so. Settled decision 2 covers `orchestrate` only.
9. **Is the `orchestrate` close comment an impersonal record?** Yes: *"Merged into <base> in #<P>;
   reaches the default branch with <base>."* No person is addressed.
10. **Does `orchestrate` open the base's PR into the default branch when the batch is done?** No; only
    when its brief says so, and never merge it.
11. **Changelog?** This repository has no `.changelog/` and no `CHANGELOG.md`, so none is written.
