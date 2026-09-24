# `owners.py` fails closed only on unreadable sessions that could own the worktree — design

**Design size:** Architectural

**Date:** 2026-09-24
**Issue:** IT4WEBBV/LaravelClaudeMd#65
**Canonical home:** `skills/orchestrate/owners.py`, its fixture test `skills/orchestrate/tests/owners_test.sh`,
and the two places in `skills/orchestrate/references/commands.md` that describe its exit codes.
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. Every question the brainstorm
would have asked is answered in *Assumptions*, so `/critique plan` audits exactly those.

## Problem

`owners.py` reads the `claude agents --json --all` rows on stdin and, for every live (`working` or
`blocked`) session other than the caller, looks for transcript entries whose `cwd` lies inside the
worktree. When a live session has no transcript, or its transcripts hold no `cwd` entries, the script
cannot tell whether that session owns the worktree, so it prints nothing, names the session on stderr
and exits 2. The caller (`orchestrate` §Owner of in-flight work and §Teardown) then treats the
worktree as owned and asks the owner.

That rule ignores where the unreadable session runs. One unreadable session anywhere on the machine
blocks every teardown in every repo for as long as it stays live.

Observed on 2026-09-24 (viewiemedia #2108, PR #2110): the teardown of `viewiemedia-2` passed every
check except `owners.py`, which failed closed on `fd367e29`, a `blocked` session with no transcript file
whose `cwd` was `/Users/jroelofs/GitProjects/LaravelClaudeMd/LaravelClaudeMd`, a different repo. The owner
confirmed the teardown by hand.

## What a `claude agents` row gives

Every row carries the session's own `cwd` (verified on this machine: the keys are `pid`, `id`, `cwd`,
`kind`, `startedAt`, `sessionId`, `name`, `status`, `state`). That is the directory the session was
started in. A session cannot own a worktree it has no path to, so this `cwd` decides whether an
unreadable session is a candidate owner at all.

## Design

An unreadable live session **blocks** (the existing exit 2) only when its row's `cwd` is related to the
worktree. It is related when any of these holds:

1. **Inside:** the `cwd` is the worktree or lies below it (the existing `inside()` test).
2. **Above:** the `cwd` is a directory that contains the worktree: the primary checkout for a
   `.claude/worktrees/<branch>` worktree, `~`, or `/`. A launcher or orchestrator rooted in `~` still
   blocks when its transcript is missing, as the issue intends.
3. **Same repository:** the `cwd` lies in a checkout of the same git repository as the worktree, that is
   `git rev-parse --git-common-dir`, resolved against the path, gives the same directory for both. This
   covers slot layouts, where the primary checkout `Shop/Shop` is a sibling of the slot `Shop/Shop-4`,
   not a parent, and still is where `orchestrate` launches the slot's runs from.
4. **Unknown:** the row has no `cwd`. Without it nothing can be ruled out, so the session blocks.

Every other unreadable session is **skipped**: it is neither an owner nor a reason to fail. Skipping is
silent; stdout and the exit code keep their current contract (owners on stdout, exit 0; exit 2 with the
blocking sessions on stderr).

A readable session is judged exactly as today, on its transcript entries. The new rule applies only to
the unreadable branch.

### Shape of the code

`owners.py` stays one stdlib script. Four small functions join `inside()`, one rule each:

- `above(cwd, worktree)`: rule 2.
- `repository(path)` returns the absolute git common dir of `path`, or `None` when `path` is not in a git
  repository or does not exist (`git` exits non-zero). One `subprocess.run` call with `capture_output`,
  cached with `functools.lru_cache`, so the worktree's own lookup runs at most once per invocation.
- `same_repository(cwd, worktree)`: rule 3; `False` when the worktree itself is not in a repository.
- `related(cwd, worktree)` returns `True` for rules 1 to 4, cheapest first. `git` runs only when an
  unreadable session is not already decided by rules 1, 2 and 4, so the common case (every live session
  readable) runs no `git` at all.

The unreadable branch in `main()` changes from "always append" to "append when `related(...)`". The
module docstring's *Fails closed* paragraph is rewritten to say which unreadable sessions block.

### Paths

Both sides are normalised with `os.path.abspath(...).rstrip("/")`, as the worktree already is, except
that `/` stays `/`. The *Above* test is `worktree.startswith(cwd + "/")` with `cwd` stripped of its
trailing slash, so `/` (stripped to the empty string) contains everything, and `Shop/Shop-4` does not
contain `Shop/Shop-40`. The *Same repository* test resolves `git rev-parse --git-common-dir` for each
path: `os.path.join(path, answer)` keeps an absolute answer (a linked worktree) and roots a relative
one (`.git` in a primary checkout) at the path, and `os.path.realpath` normalises both sides alike
(macOS `/var` → `/private/var`). That needs no `--path-format=absolute`, which git before 2.31 echoes
instead of rejecting, so the comparison would silently never match there.

### Docs

`skills/orchestrate/references/commands.md`:

- §Owner of in-flight work: the sentence "A non-zero exit is never orphaned: the lookup could not read a
  live session's transcript" gains which sessions those are: one rooted in, above, or in the same
  repository as the worktree. A session rooted elsewhere is skipped.
- §Teardown: unchanged. A non-zero exit still fails the check.

The interim `autoflow` guard in §Owner stays as it is: narrowing it is #68's settled decision, not this
issue's.

## Tests

`skills/orchestrate/tests/owners_test.sh` stays the one fixture test (`bash
skills/orchestrate/tests/owners_test.sh`, prints `PASS owners.py`). Its worktree `$TMP/Shop/Shop-4`
becomes a real git worktree of `$TMP/Shop/Shop` so rule 3 has something to find. The existing owner
fixtures keep their `cwd` strings; only the directories now exist. New cases, each a live session with
no transcript:

| Row `cwd` | Expected |
|---|---|
| `$TMP/Other/Other` (another git repo) | skipped: exit 0, no stdout, no stderr |
| `$TMP/Plain` (a directory, no repo) | skipped: exit 0, no stdout, no stderr |
| `$TMP/Shop/Shop-40` (prefix trap, not a repo) | skipped: exit 0, no stdout, no stderr |
| `$TMP/Gone/Gone` (does not exist) | skipped: exit 0, no stdout, no stderr |
| `$TMP/Shop/Shop-4/code/www` (inside) | exit 2, names the session |
| `$TMP` (contains the worktree, like `~`) | exit 2, names the session |
| `/` | exit 2, names the session |
| `$TMP/Shop/Shop` (primary checkout, sibling of the slot) | exit 2, names the session |
| no `cwd` key | exit 2, names the session |

The two existing fail-closed cases (`jjj`: no transcript; `lll`: no `cwd` entries) gain a `cwd` inside
the worktree so they keep testing the fail-closed path rather than the new skip. One mixed case checks
that a skipped unreadable session does not hide a real owner: an owner row plus an unrelated unreadable
row prints the owner and exits 0.

No other suite is touched. The pipeline Pest suite (`./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml
--test-directory=skills/pipeline/checks/tests`) runs once at the end as a regression check, since the run
reports on it.

## Out of scope

- Seeing `autoflow` workflow transcripts under `subagents/workflows/wf_*/` and matching on the `brief`
  argument: #68, which depends on this issue.
- The interim `autoflow` guard in `commands.md` §Owner: #68.
- Explaining *why* a live session has no transcript (not yet written, or a `sessionId` that no longer
  matches after `/clear` or `--resume`). The rule above holds whatever the cause.

## Assumptions

Questions the brainstorm would have asked the owner, with the answer assumed.

1. **Does "a directory that contains it, such as the primary checkout" include a slot's primary
   checkout, which is a sibling of the slot, not a parent?** Assumed yes. The issue names the primary
   checkout as a blocking example, and `orchestrate` launches runs from it; in slot repos it only
   relates to the slot through git. Hence rule 3 (same git common dir), which costs one `git` call per
   unreadable session and nothing for readable ones.
2. **A row without `cwd`: skip or block?** Assumed block. The script's rule is to fail closed when it
   cannot tell, and without a `cwd` it cannot tell.
3. **A `cwd` that no longer exists, or is not in a git repository?** Judged on rules 1 and 2 only (string
   comparison); rule 3 simply does not match. A vanished directory in another place is not a path to
   this worktree.
4. **Should skipped sessions be mentioned on stderr?** Assumed no. The observed failure was noise that
   blocked real work; a note on every teardown about an unrelated session would be the same noise in a
   milder form, and callers quote stderr only on a non-zero exit.
5. **Should a readable session also be judged by its row `cwd`?** No. Its transcript already says where
   it worked, which is more precise than where it started; the issue is only about unreadable sessions.
6. **`/` as a `cwd`?** Contains every worktree, so it blocks. Same reasoning as `~`.
7. **Where to keep the new code?** In `owners.py`, stdlib only (`subprocess` and `functools` join the
   imports). No new file: the script is 100 lines and the change adds about 30.
8. **Changelog?** This repo has no `.changelog/` directory and no `CHANGELOG.md`, so none is written.
