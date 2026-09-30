# A run merges its base into its own branch instead of halting on "behind" — design

**Design size:** Architectural

**Date:** 2026-09-30
**Issue:** IT4WEBBV/LaravelClaudeMd#124
**Canonical home:** `skills/pipeline/checks/brief.php` (the base state and the brief line),
`skills/pipeline/checks/ci.php` and `dispatch_cli.php` (the merge round at the CI gate), pipeline
`references/engine.md` §Catching up with the base (new: the rule and why), `CLAUDE.md` (the exception to
the stale-checkout rule), `README.md` (the allow rules), `skills/orchestrate` (the sibling note).
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. Every question the brainstorm
would have asked is answered in *Assumptions*, so `/critique plan` audits exactly those. Nothing below was
built or run; what it relies on was read (see *What was read*), and one probe answered one question (see
*Probe*).

## Problem

An `autoflow` run halts when its branch falls behind its base, and cannot catch up by itself. In one
`/orchestrate` batch on IT4WEBBV/Deploy (#456–#461, 2026-09-29/30) that happened four times, each needing
an owner answer and a relaunch (issue #124 has the cases). Three causes:

1. **The global `CLAUDE.md`** says *"Never work against a stale checkout … do not pull, rebase or merge on
   your own initiative"*, and every step agent reads that as applying to its own run branch.
2. **Permissions.** Steps run `git -C <worktree> merge …`. A project's `Bash(git merge:*)` does not match
   the `-C` form, and the auto-mode classifier denies it as destructive; a denial in a background step is
   final.
3. **Nothing in the pipeline says when to merge or who does it.** No brief mentions the base moving, so a
   step that notices it improvises: a rebase in one run, a halt in the next.

## Settled by the owner

- A run keeps its own branch current with its base by a plain `git merge --no-edit origin/<base>`. Never a
  rebase of pushed commits, never a force-push. When the base touched none of the branch's files, the run
  carries on without merging: CI tests the PR's merge ref.
- The run resolves conflicts itself, keeping both sides' intent, and halts only when both sides cannot be
  kept, quoting the hunks.
- This run edits the global `CLAUDE.md` in this repo (an exception to the stale-checkout rule for pipeline
  run branches) and documents the allow rule for the `git -C` merge form. Per-project
  `settings.local.json` entries stay with the owner. Not chosen: pipeline text only. (2026-09-30)

## Approaches

1. **The brief computes whether to merge and prints the exact command; the step runs it (chosen).**
   `pipeline_brief()` already takes a git runner for `review-pr`'s scope. On a step that writes to the
   branch it fetches the base, compares, and adds one override line carrying the literal command. The
   step decides nothing about *whether*; it merges, and resolves conflicts, which is judgement only an
   agent has. The command stays visible to the permission layer in one fixed form an allow rule can match.
2. **The brief states the rule and the step works out whether it applies.** No git in PHP, but every
   step would fetch and compare on its own reading of "files the branch also touches", in whatever
   command form it likes: the improvising this issue reports, and no allow rule matches a free form.
3. **`brief` (PHP) runs the merge itself and hands the step only the conflicts.** A clean merge would
   need no permission at all, as kickoff's `worktree.create` runs behind the one kickoff call. Rejected:
   the owner decided to document an allow rule for the visible form, `CLAUDE.md` (§Remote servers) rejects
   wrapping a command so the permission layer cannot see it, and `brief` would change the tree it then
   snapshots.

## Design

### The base state: `pipeline_base_state(array $manifest, callable $git): ?array`

In `brief.php`, beside `pipeline_review_scope()`. It answers "must this step merge the base first", or
null for "no". `$git` runs git in the worktree, as `pipeline_git_run()` does.

1. **The base ref** is `origin/<manifest base>`, else what `origin/HEAD` names
   (`symbolic-ref -q --short refs/remotes/origin/HEAD`): the same resolution `pipeline_review_scope()`
   uses, moved into one `pipeline_base_ref(array $manifest, callable $git): ?string` that both call, so
   the two cannot disagree about what the base is. Unresolvable: null.
2. **Fetch it**: `fetch -q origin +refs/heads/<name>:refs/remotes/origin/<name>`, the refspec kickoff
   uses for a run on a base (`pipeline_kickoff_base()`). A fetch that fails returns null.
3. **Behind**: `rev-list --count HEAD..<base ref>`. Zero: null.
4. **The branch's own files**: `diff --name-only --no-renames <base ref>...HEAD`, minus `artifacts.spec`
   and `artifacts.plan`. **The base's files**: `diff --name-only --no-renames HEAD...<base ref>`. Both are
   three-dot, so each side is measured from where they last met.
5. **Merge when** the branch has no own files (it holds nothing, or only its design), or the two lists
   share a file. Otherwise null.

It returns `['base' => 'origin/<name>', 'behind' => <int>, 'shared' => list<string>]`; `shared` is empty
in the design-only case. Any git call that fails returns null: a run that cannot tell carries on, as a
run does today, and CI tests the merge ref.

**Why a design-only branch merges on any movement.** The issue's overlap rule measures the branch by its
diff, and before `implement` that diff is the spec and the plan: files the base never touches, however
much the base changed the code the plan names (Deploy #457: a docs-only branch ten commits behind). A
design is written by reading the code, so it has to read current code. On such a branch the merge cannot
conflict in code and invalidates no suite run. Once the branch holds code the overlap rule applies as the
issue states it, and a merge costs a suite run (§Suite reuse keys on the tree), so it happens only when
it buys something.

### Which steps catch up

The steps that write to the branch, one constant in `brief.php`:

```php
const PIPELINE_CATCH_UP_STEPS = ['design:run', 'design:spec', 'design:plan', 'review-plan:resolve', 'implement:run', 'review-pr:resolve'];
```

Not the two review steps (*"Read-only on the checkout"*: a reviewer that resolves a conflict reviews its
own work), not `handoff` (it opens the PR; `implement` follows it), not `verify-ui` (it checks, and loops
back to `implement` for fixes).

`pipeline_brief()` computes the state only for those steps and only when it was handed `$git`, and passes
it to `pipeline_brief_overrides()` next to `$scope`. Both modes get it: `interactive`'s `next` and
`returned` hand the same runner in (`dispatch_cli_emit()`).

### The brief line

`pipeline_catch_up_line(array $manifest, array $state): string`, placed **first** under `## Overrides`,
because it is done before anything else. With the values filled in:

> Catch up with the base first (engine.md §Catching up with the base): `origin/main` is 27 commits ahead
> and changed files this branch changes too (`a.php`, `b.php`). Before any other work run
> `git -C <worktree> merge --no-edit origin/main`, as its own command in exactly that form. On a conflict,
> resolve each file keeping both sides' intent, `git -C <worktree> add <file>`, and conclude with
> `git -C <worktree> commit --no-edit`. Only where both sides cannot be kept: `git -C <worktree> merge
> --abort` and return `halted`, quoting the conflicting hunks. Never rebase, never force-push. A denied
> command is a halt naming it; do not reshape it. Record the merge as that section says.

In the design-only case the reason reads *"and this branch holds only its design"* instead of the file
list. The command text is the contract with the allow rules below, so a test pins it.

### What the step does, and what it records (engine.md §Catching up with the base)

A new section in `engine.md`, after §Suite reuse. It holds the rule, the why (the Deploy batch), and:

- **The merge comes first**, on the clean tree the previous step left, then the step's own work.
- **Conflicts.** Resolve keeping both sides' intent; leave no conflict marker in a resolved file; conclude
  with `git commit --no-edit` (see *Probe*: `git merge --continue` needs an editor). Where keeping both
  sides is a product decision (the two changes want opposite behaviour), abort and halt, quoting the
  hunks; after `handoff` the invoking session puts the reason in the PR body, as for any halt (§Failure
  policy). The owner's answer comes back as a `--decision` on the relaunch, and the step's brief asks for
  the merge again.
- **The suite.** Nothing new: a merge changes the tree, so §Suite reuse finds no green run for it and the
  step's next full run covers the merged tree. `implement` merges before its first plan step;
  `review-pr`'s finish step runs the suite after its merge. A design-leg merge runs none: there is no
  code of the branch's to test.
- **The record.** When `artifacts.pr` is set, the step adds to the PR body, under a `## Base merges`
  heading it creates once, one line per merge: the base and its sha, the commit count, and per conflicted
  file how it was resolved (*"clean"* when there was none). It edits the body as §Failure policy does
  (`gh pr view --json body` into a file, append, `gh pr edit --body-file`), never blanking it. A resolve
  step also names the merge in its entry's `actions`. Before a PR exists the merge commit is the record:
  the PR shows it among its commits once `handoff` opens it.
- **No rebase and no force-push, anywhere in a run.** The section says so once, and §Scoped re-review
  already treats a rewritten history as "review everything again".
- **What this does not catch.** A base that changed only files the branch does not touch is not merged,
  even where the branch's code depends on them: the blind spot §Scoped re-review names, covered by CI on
  the merge ref. A design grown after code exists (a plan gap) is measured by the branch's own files, not
  by the files the grown plan names; the step that writes that code catches up at its next brief.

No boundary check verifies that a step merged. The state is recomputed at every writing step's brief, so
a step that skipped the merge leaves the next one the same line; after the last step the CI gate below
and GitHub's own conflict marker on the PR are what the owner sees.

### A merge the review did not see: one round at the CI gate

The finish step is the run's last, so a merge it makes, conflict resolutions included, would reach a
ready PR unreviewed. The issue asks that `review-pr`'s scoped review check the merge commit, and §Scoped
re-review already reads whole *"the files where a merge since the base met the branch's changes"*. What is
missing is the trigger.

- `dispatch_cli_ci()` computes `pipeline_review_scope($manifest, dispatch_cli_git($manifest))` and hands
  its `files` (empty when the scope is null) to `pipeline_ci_answer()` as a new last parameter,
  `array $unreviewed = []`. After `finish` recorded `done`, the scope's base is the review that just
  completed, so `files` is exactly what a merge since that review met.
- **Before it reads the PR's head or its checks**, `pipeline_ci_answer()` answers
  `{action: fix, verdict: merge, files, decision}` when `$unreviewed` is not empty and the run has not
  spent this round. The decision is `Unreviewed merge on the PR's head commit <sha>: a merge since the
  last completed review met this branch's changes in <files>` (prefix constant `PIPELINE_MERGE_UNREVIEWED`,
  beside `PIPELINE_CI_RED`).
- **The invoking session does what it does for a red CI**, with no new instruction: `fix` is
  `launch --from review-pr --decision "<its decision>"` and a new workflow (SKILL.md step 5, `orchestrate`
  commands §Finish). The relaunch's review is scoped, reads those files whole, and records a
  `reviewed_sha` that contains the merge, so the gate's next read finds nothing unreviewed.
- **Once per run**, counted as the CI round is: a decision that starts with the prefix is the round spent
  (`pipeline_merge_rounds()`). A further unreviewed merge after it does not halt and does not loop: the
  gate goes on to CI, and the PR body's `## Base merges` line is the record. It is its own round, separate
  from the CI fix round: a run may have one of each.
- The `review-pr` briefs need no extra line for it: the decision is in `## Settled decisions`, and the
  scope line names the files.

A merge made by `implement` or a design step precedes the review and is reviewed with the rest of the
diff; it never triggers this round.

**`autoflow` only.** `dispatch_cli_ci()` hands the files in only when the manifest's mode is `autoflow`.
In `interactive` the finish step runs the gate while its own review entry is still open, so the scope's
base would be an older review or none; there the human resolves the review and sees the merge as it is
made.

### `CLAUDE.md`

One sub-paragraph under *Never work against a stale checkout*, after its code block:

> **One exception: a `/pipeline` run's own branch.** A step of a run merges the base into the run's branch
> when its brief says so, with `git -C <worktree> merge --no-edit origin/<base>`, and resolves the
> conflicts itself (pipeline `engine.md` §Catching up with the base): never a rebase, never a force-push.
> There the brief answers the hook's warning. Every other checkout keeps raise-and-wait.

### Permissions

The run edits no settings file. `README.md` gains, in its machine setup beside the hook wiring, the allow
rules for `~/.claude/settings.json` (user level, so they reach every project on that machine; a per-machine
step, like the hooks):

```json
"permissions": { "allow": [
  "Bash(git -C * merge --no-edit origin/*)",
  "Bash(git -C * merge --abort)",
  "Bash(git -C * commit --no-edit)"
] }
```

with one sentence on why the form matters: a rule matches the command as typed, so `Bash(git merge:*)`
does not cover `git -C <worktree> merge`, and a chained command is matched part by part. Pipeline
`SKILL.md` step 3 (*"unattended runs need auto permission mode or allow rules for `git push`, `gh` and
`docker`"*) names these rules too and points at the README. `git -C <worktree> add <file>` is left out:
runs stage files in every step today without a denial.

### `orchestrate`: the sibling note

`orchestrate` commands §Launch: before kickoff for issue N, list the batch's other runs in flight from
§Map's open PRs, and per PR its files (`gh pr view <P> -R <repo> --json files --jq '[.files[].path] | join(", ")'`).
When there is at least one, kickoff gets one more `--decision`:

> Sibling runs in flight in this batch (orchestrate's note, not an owner decision): #462 (issue #459)
> changes `a.php`, `b.php`; issue #460 has no PR yet. A plan that changes these files expects a merge of
> the base (pipeline engine.md §Catching up with the base).

`decisions` already carries one machine-written record, the CI gate's. `manifest.md`'s `decisions` row
names both new ones (this note, and the unreviewed-merge record). `orchestrate` `SKILL.md` step 3 gets
half a sentence pointing at it.

### What does not change

The manifest's shape and `pipeline_leg_writable_keys()`; the workflow script; the routing tables and
statuses; §Scoped re-review's scope; `handoff`, `work-on` and `review-pr` (the other repo's skills);
`hooks/git-freshness.sh`, which keeps warning and changes nothing on a working branch.

## Tests

Pest, in `skills/pipeline/checks/tests`, run with
`./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`.
Test-first, in `implement`.

- **`BaseStateTest.php`** (new; real throwaway repos, as `ReviewScopeTest.php` builds them): null when the
  branch is not behind; null when the base moved in files the branch's code does not touch; the shared
  files when it moved in one the branch changes; a merge on any movement when the branch holds nothing, or
  only `artifacts.spec` and `artifacts.plan`; a base moved by another clone is seen (the fetch); the
  manifest's `base` is the ref when set; null on a base that does not resolve or a fetch that fails.
- **`BriefTest.php`**: the line is the first override on each of the six writing steps and absent on
  `review-plan:review`, `review-pr:review`, `handoff:run` and `verify-ui:run`; it carries
  `git -C <worktree> merge --no-edit origin/<base>` literally; the design-only wording; no line without a
  git runner or with a null state.
- **`ReviewScopeTest.php`**: still green after `pipeline_base_ref()` is extracted (no new case).
- **`CiTest.php`**: unreviewed files answer `fix` with the merge decision before the head or the checks
  are read (also with a null view); with the round spent the answer is what CI gives; no files, no change;
  the merge round and the CI round do not count as each other.
- **`DispatchCliTest.php`**: `ci` on an `autoflow` run whose finish step merged a shared file answers the
  merge `fix`, and on an `interactive` one does not;
  `brief` on a writing step behind its base prints the line.

The documents (`engine.md`, `CLAUDE.md`, `README.md`, `SKILL.md`, `orchestrate`) have no test beyond what
`LockStepTest.php` already pins; the plan checks whether any of its lock-step assertions covers a line
this change touches.

## Probe

**Question:** after resolving a conflict, does `git -C <worktree> merge --continue` conclude the merge in
a step that has no editor, or must the line name another command? It decides which command the brief
line and the allow rules carry. **Probe** (git 2.33.0, a throwaway repo under `$TMPDIR`, two branches
changing one line): with a failing editor `git merge --continue` exits 1 and leaves the merge open;
`git commit --no-edit` concludes it with the default message and both parents. The line and the rules
therefore use `commit --no-edit`, not the `merge --continue` the issue suggests.

## What was read

- `skills/pipeline/checks/brief.php`: `pipeline_brief()`, `pipeline_brief_overrides()`,
  `pipeline_review_scope()` and the base resolution inside it, `pipeline_git_lines()`,
  `pipeline_meeting_files()`.
- `skills/pipeline/checks/ci.php`: `pipeline_ci_answer()`, `pipeline_ci_red()`, `pipeline_ci_rounds()`,
  `PIPELINE_CI_RED`; `dispatch_cli.php`: `dispatch_cli_ci()`, `dispatch_cli_git()`, `dispatch_cli_emit()`,
  `dispatch_cli_brief()`.
- `skills/pipeline/checks/kickoff.php`: `pipeline_kickoff_base()`'s fetch refspec.
- `skills/pipeline/references/engine.md` §The loop, §`autoflow`, §Kickoff, §The CI gate, §Suite reuse,
  §Scoped re-review, §Failure policy; `references/manifest.md` §Fields.
- `skills/pipeline/SKILL.md` §`autoflow` steps 1–6; `skills/orchestrate/SKILL.md` and
  `references/commands.md` §Map, §Launch, §Finish.
- `CLAUDE.md` §Git Workflow and §Remote servers; `README.md` machine setup; `hooks/git-freshness.sh`
  (its warnings; it changes only local `main`/`master`).
- `~/.claude/skills/handoff/SKILL.md`: its PR update prepends to the existing body, so a `## Base merges`
  section survives a re-run of `handoff pr`.

## Out of scope

- Rebasing a never-pushed branch: one form, the merge, for every branch.
- `orchestrate` holding back or ordering issues that edit the same files; it only notes them.
- Telling a conflicted merge from a clean merge of a shared file at the gate: `git merge-tree
  --write-tree` needs git 2.38 and one machine runs 2.33, so the round fires on either.
- Per-project `settings.local.json` entries, and editing `~/.claude/settings.json` on either machine:
  the owner's.
- The skills in `IT4WEBBV/DevOps-Claude-Config` (`handoff`, `work-on`, `review-pr`).

## Assumptions

Each is a question the brainstorm would have put to the owner, with the answer assumed.

1. **Which steps merge?** The six that write to the branch. A review step stays read-only and `handoff`
   and `verify-ui` do not merge.
2. **Does a branch that holds only its design merge on any base movement, though the issue says "when the
   base touched none of the branch's files, carry on"?** Yes. The issue's rule is kept for a branch that
   holds code; before that the diff is only the spec and the plan, and the rule would never fire where
   Deploy #457 needed it.
3. **Who decides whether to merge, the step or code?** Code: `brief` fetches and compares, the step runs
   the command it is given.
4. **Must a merge made by the finish step be reviewed in the same run?** Yes, by one extra scoped
   `review-pr` round through the CI gate's existing `fix` answer, once per run; a second such merge goes
   on to CI with the PR body as its record rather than halting.
5. **Does that round fire on a clean merge of a shared file too?** Yes: git 2.33 cannot tell the two
   apart after the fact, and a clean textual merge of a file both sides changed is what a review is for.
6. **What when `brief` cannot fetch or compare?** No line, and the run carries on as today. It does not
   halt: an offline fetch is not a reason to stop a design.
7. **Is a step that was told to merge and did not a halt?** No. The next writing step gets the line
   again; there is no boundary check for it.
8. **Where does the record go before a PR exists?** Nowhere but the merge commit.
9. **Do the allow rules stop the auto-mode classifier's denial?** Assumed from the issue, not verified
   here: a matching allow rule is evaluated before the classifier, and `*` matches inside a Bash rule.
   The first batch after the merge shows it; a denial still halts the step with the command named.
10. **Where do the allow rules go?** Documented in `README.md` for `~/.claude/settings.json`; the run
    writes no settings file.
11. **Is `decisions` the place for orchestrate's sibling note?** Yes, marked as a note: the CI gate's
    record is already a machine-written entry there, and a new manifest key would need every brief and
    check to learn it.
12. **What does the sibling note list?** The batch's open PRs and their files as GitHub shows them at
    kickoff; a sibling still in design shows only its spec and plan, and one without a PR only its issue
    number. Merged siblings are not listed: the run is cut from a base that holds them.
13. **Interactive mode too?** The brief line and the `CLAUDE.md` exception apply to any `/pipeline` run.
    The gate's merge round is `autoflow`'s alone: in `interactive` the human is at the finish step.
