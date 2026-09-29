# The CI gate checks that GitHub's head is the worktree's `HEAD` — design

**Design size:** Architectural

**Date:** 2026-09-29
**Issue:** IT4WEBBV/LaravelClaudeMd#99 (follow-up to #85, PR #98)
**Canonical home:** `skills/pipeline/checks/ci.php` (the answer), `dispatch_cli_ci()` in
`skills/pipeline/checks/dispatch_cli.php` (the reads), pipeline `references/engine.md` §The CI gate (the
rule and why).
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. Every question the brainstorm
would have asked is answered in *Assumptions*, so `/critique plan` audits exactly those. Nothing below was
built or run; what it relies on was read (see *What was read*).

## Problem

`dispatch_cli.php ci <manifest>` reads `gh pr view <pr> --json headRefOid,statusCheckRollup` and judges
CI on `headRefOid`, the head as GitHub has it (`dispatch_cli_ci()`, `pipeline_ci_answer()`). Nothing
compares that sha with the run's worktree. When `review-pr:resolve`'s push failed or was skipped, GitHub's
head is an older commit; CI on it can be green, the gate answers `ready`, and the PR goes ready without the
last fix. That is the gap #85 closed for CI itself, one step earlier: a commit nobody checked. The plan
review of #85 named it as its open question (*"GitHub's head is not the run's last commit"*), and the #85
spec's §Out of scope left it for this issue.

## Change (from the issue)

- The gate compares `git -C <worktree> rev-parse HEAD` with the `headRefOid` it reads, and answers `wait`
  while they differ.
- Still different after the wait: the run halts with both shas, as a CI failure halts it.
- Done when: a `DispatchCliTest` case where the worktree's `HEAD` is not `headRefOid` and the answer is not
  `ready`; `SKILL.md` and engine.md name the check in the `autoflow` finish.

## Approaches

1. **The worktree's `HEAD` goes into the pure answer as a string; `dispatch_cli_ci()` reads it (chosen).**
   `pipeline_ci_answer()` gains a `string $head` parameter and checks it first after gh's read, before the
   checks' verdict. Every rule stays in tested pure PHP, `dispatch_cli_ci()` stays the only place that
   runs processes, and `ci` stays read-only.
2. **Compare with the manifest's `last_sha` instead of `git rev-parse HEAD`.** No git call, but
   `last_sha` is whatever the last leg wrote, not what the worktree holds; the issue names `HEAD`, and a
   resolve step that commits and forgets to update `last_sha` would halt a healthy run.
3. **A separate `head` command polled before `ci`.** Two loops in the session instead of one, and the
   session would have to combine two answers; the one-command, one-answer shape of #85 is simpler.

## Design

### `ci.php`

- **`pipeline_ci_answer(array $manifest, ?array $view, string $head, bool $workflows, int $poll): array`**,
  `$head` the worktree's `HEAD` sha. Order of the rules:
  1. `$view === null` (gh could not read the PR): as today, `wait` / halt at read 120. The head plays no
     part: there is no GitHub head to compare.
  2. **`$view['headRefOid'] !== $head`**: verdict `mismatch`. `wait` before read
     `PIPELINE_CI_PUSH_POLLS` (3), then `halt`. The answer carries `sha` (GitHub's head, as every read
     answer does) and `head` (the worktree's). The halt reason names both:
     *PR #`<pr>`'s head on GitHub is `<sha>`, but the worktree's HEAD is `<head>`: the last push did not
     land; push the branch and run the CI gate again*. It names `review-pr` through
     `pipeline_ci_halt()`, so `finish` records it there, as it does a CI halt.
  3. Otherwise today's verdict table, unchanged: `green`, `none`, `pending`, `red`.

  The mismatch comes before the verdict on purpose: a red on the old commit must not start the fix round
  (the review would read a failure the worktree may already have fixed), and a green must not go ready.
- **`PIPELINE_CI_NONE_POLLS` becomes `PIPELINE_CI_PUSH_POLLS`**, still 3, documented as the reads GitHub
  gets to catch up with a push: its head moved and its checks registered. The `none` rule and the
  mismatch rule share it. The constant is used only in `ci.php`.
- Every answer other than `mismatch` keeps its exact shape (no `head` key), so the existing assertions in
  `CiTest` and `DispatchCliTest` hold once their calls pass a matching head.

### `dispatch_cli.php`

`dispatch_cli_ci()` reads the worktree's `HEAD` with `pipeline_git_run($worktree, ['rev-parse', 'HEAD'])`
(`suite.php`, already loaded) after the PR check and before gh. A non-zero exit halts at once, touching
nothing: *the CI gate cannot read the worktree's HEAD at `<worktree>`: `<stderr>`* — a missing or broken
worktree is a machinery failure, not something a wait cures. The gh read and `pipeline_ci_answer()` follow
as today, with `$head` passed in. The docblock names the second read.

### Docs

- **engine.md §The CI gate**: the command reads the worktree's `HEAD` beside the PR; the table gains a
  first row, `mismatch`: GitHub's head is not the worktree's `HEAD` → `wait`; `halt` at the third read,
  with both shas; checked before the checks' verdict, so neither a green nor a red on an older commit
  counts. The `halt` bullet says what to do after a mismatch halt: push the branch, then run the loop again
  by hand on the halted manifest, as after a halt on the hour (nothing needs re-reviewing).
- **engine.md §Failure policy**: the CI bullet adds *or GitHub's head still not the worktree's `HEAD` at
  the third read*.
- **pipeline `SKILL.md` §`autoflow` step 5**: the gate is on the PR's head commit, *which must be the
  worktree's `HEAD`*, and a `halt` on a mismatch means push, then run the loop again.
- `orchestrate` `SKILL.md` and `references/commands.md` §Finish name the gate by its command and its
  answers (`ready`, `fix`, `halt`), which do not change: no edit.

### What does not change

The shell loop (it repeats while the answer is `wait`), `finish`, the fix round and `pipeline_ci_rounds()`,
`brief.php` (the finish line already says to push), `pipeline-autoflow.js`, the merge watch, `run_audit.php`.

## Tests

Written first, each seen red:

- `CiTest.php`: every existing `pipeline_ci_answer()` call passes `'abc123'` as `$head` (its view's
  `headRefOid`), and their expectations stay as they are. A new case: a green view with head `def456`
  answers `wait` with verdict `mismatch`, `sha` and `head` at reads 1 and 2, and halts on `review-pr`
  with the reason naming both shas at read 3; a red view with a different head answers `mismatch`, not
  `fix`; a null view with any head is still `unreadable`.
- `DispatchCliTest.php`: `ci_fixture()` gains a fake `git` next to the fake `gh`, answering
  `rev-parse HEAD` from a `head` file (default `abc123`, none: git fails). The issue's case: a green PR
  whose head is not the worktree's `HEAD` is not `ready` (`wait`, verdict `mismatch`); at `--poll 3` it
  halts naming both shas, and `finish` with that answer records `halted` on `review-pr`; the manifest is
  byte-identical after both. A worktree whose `HEAD` git cannot read halts at once, before gh is called.

The whole pipeline suite passes.

## What was read

- `skills/pipeline/checks/ci.php`, `dispatch_cli_ci()`, `dispatch_cli_pr_view()` and `pipeline_git_run()`
  (`suite.php`, required by `dispatch_cli.php`; returns `[code, trimmed stdout, trimmed stderr]` and
  runs `git -C <worktree>` from `PATH`, so a fake `git` first on `PATH` answers it in a test, as the fake
  `gh` does).
- `gh pr view 103 --json headRefOid` on this repository: `headRefOid` is the full 40-character sha, the
  same form `git rev-parse HEAD` prints, so a plain string comparison is exact.
- `CiTest.php` and the `ci` tests in `DispatchCliTest.php`: every `pipeline_ci_answer()` call sits in
  `CiTest.php`, and `dispatch_cli_ci()` is the only production caller.

## Out of scope

- Pushing on the run's behalf: the gate reports the mismatch; pushing stays the finish step's job (its
  brief line) or the owner's after the halt.
- A PR whose branch was force-pushed or moved by someone else: it reads as a mismatch and halts, which is
  the right answer for a gate that cannot tell who is right.

## Assumptions

Questions the brainstorm would have asked the owner, with the answer assumed.

1. **How long is "the wait"?** Three reads (~1 minute at 30 s), the bound `none` already uses for GitHub
   to register a push. `git push` returns once the remote has the commit, and GitHub moves `headRefOid`
   within seconds, so a mismatch that outlives a minute is a push that did not land; waiting the hour
   `pending` gets would only delay the halt.
2. **One constant or two?** One, renamed `PIPELINE_CI_PUSH_POLLS`: both rules wait for the same thing, a
   push reaching GitHub. The old name is used nowhere outside `ci.php`.
3. **Compare with `HEAD` or `last_sha`?** `HEAD`, as the issue says: it is what the worktree holds, and
   `last_sha` is a leg's claim about it (approach 2).
4. **Does a mismatch before the verdict also stop the fix round?** Yes: a red on an older commit is not a
   finding about the worktree's code, and a fix round spent on it would leave the next real red to halt.
5. **A worktree whose `HEAD` cannot be read?** Halt at once, not after a wait: git answering an error is
   not something GitHub catching up can change.
6. **The mismatch and the `none` rule share a stateless count.** `ci` keeps no memory between reads, so
   the `none` rule counts from the loop's first read, not from the read the heads first matched. A head
   that first matches at read 2 still gets a `wait` on no checks at read 2. One that first matches at
   read 3 meets `none` at read 3 and answers `ready` without a read that waited for its checks: accepted,
   since it needs GitHub to lag a completed push by a minute and then register no check at all in that
   read, and closing it would need state the read-only command does not keep.
7. **Does `interactive` get the check?** Yes: its finish step runs the same `ci` command.
8. **A fake `git` in `DispatchCliTest` rather than a real repository?** Yes, beside the fake `gh` the `ci`
   tests already use: the tests keep their fixed `abc123` sha, and the git call is one `rev-parse HEAD`
   whose output form was read (*What was read*).
9. **Changelog?** This repository has no `.changelog/` and no `CHANGELOG.md`, so none is written.
