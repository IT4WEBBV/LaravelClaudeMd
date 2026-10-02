# A session that opened a PR notices the merge and cleans up by itself Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task by task. Steps use checkbox (`- [ ]`) syntax for tracking. In an `autoflow` implement step, execute inline, task by task, with no subagents.

**Goal:** One tested script, `skills/orchestrate/teardown.py`, decides and performs the teardown after a PR's merge; orchestrate, a standalone `/pipeline` and every plain session (a new `CLAUDE.md` rule) watch their PR and call it, so the owner never types "merged" again (#168).

**Architecture:**
- `skills/orchestrate/teardown.py <checkout> <pr>`: reads the PR (`gh`), marks the proof page (`proof_cli.php status`), prints every check together (clean, head, branch, outside, owners via `owners.py`), and removes by the checkout's kind: a `Slot` through the repo's `scripts/worktree.sh`, a `LinkedWorktree` with `git worktree remove`, a `PrimaryCheckout` back to its base. One class per kind, each with `outside()` and `remove()`; the last line always starts with `teardown: `.
- `skills/orchestrate/owners.py`: a session working in a worktree nested in the target (a primary checkout's `.claude/worktrees/*`) is no longer evidence that it works in the target.
- The prose callers lose their copies of the checks and removal: orchestrate `references/commands.md` §Watch and §Teardown, orchestrate `SKILL.md` step 6, pipeline `references/engine.md` §After the merge (plus its status-table and repo-config rows), `slots/SKILL.md`'s exception. `CLAUDE.md` §Git Workflow gains *Watch the PR you open*.
- Every watch passes `timeout: 7200000` and is re-armed when it ends without its `PR #<P> <state>` line.

**Tech Stack:** Python 3.9 (standard library only), bash 3.2 (macOS), git 2.33, Markdown skill files. Pest only for the existing `LockStepTest`.

**Spec:** `docs/superpowers/specs/2026-10-02-merge-watch-teardown-design.md`

## Global Constraints

- Every command runs from the worktree root on the host; this repository has no Docker stack.
- Tests: `bash skills/orchestrate/tests/teardown_test.sh` prints `PASS teardown.py`; `bash skills/orchestrate/tests/owners_test.sh` prints `PASS owners.py`; `bash skills/orchestrate/tests/needs_input_test.sh` still prints `PASS needs_input.py`.
- Pest (Task 3 only, for the repo-config row): `composer install --quiet` once if `vendor/` is missing, then `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "LockStep"`.
- Interface, verbatim from the spec: `teardown.py <checkout> <pr> [--repo <owner/name>] [--proof <page.html>] [--projects-dir DIR]`.
- Exit codes: 0 removed, 1 nothing removed (open, closed, a check failed, `gh` failed), 2 usage, 3 stopped part-way. The last line always starts with `teardown: `.
- Order: read the PR, mark the page, stop when not merged, all checks, then removal. The page is marked before anything is checked or removed.
- Watch intervals: orchestrate and a standalone `/pipeline` keep `sleep 300` (owner's settled decision, 2026-10-02); a plain session (`CLAUDE.md`) uses `sleep 60`. Every watch: `run_in_background: true`, `timeout: 7200000`, re-armed when it ends without its state line.
- `git branch -D`, never `-d`. Never the remote branch. Never `./scripts/restart.sh`: only named on the last line.
- Paths are compared as real paths (spec *Assumptions* 19): `git worktree list --porcelain` prints `/private/var/…` for a worktree added under `/var/…`.
- `python3` only: `jq` is not installed on the owner's machines. Bash 3.2: no associative arrays, no `mapfile`.
- `skills/pipeline/references/engine.md` must keep the row prefix `| \`Worktree\` | \`remove\` |` (`LockStepTest`) and must not contain `` `work-on`'s ``; `skills/pipeline/SKILL.md` is not touched.
- No changelog entry: this repository has neither `.changelog/` nor `CHANGELOG.md`. No scenario file is committed. The PR closes #168.
- The PR body carries the owner's acceptance step from the spec's *Verification on this machine*: a plain session opens a throwaway PR in this repo and arms the watch as `CLAUDE.md` says; the owner merges it; within about a minute the session reports the script's last line with no message from the owner, and the branch (and worktree) are gone. An orchestrator's ready PR is torn down within about five minutes of its merge.
- Sibling runs (#146, #150, #169) may touch `skills/orchestrate/` or `skills/pipeline/references/engine.md`: when the brief says to catch up with the base, `git merge --no-edit origin/main` (engine.md §Catching up with the base), never a rebase.

## Review Focus

1. **The primary checkout's local base has diverged from origin** (a local commit on `main` never pushed): the pull cannot fast-forward. Expected: exit 3, last line `teardown: stopped at git pull --ff-only origin main: done: git switch main`, the feature branch kept. Task 2, case *diverged*.
2. **`gh` cannot read the PR** (logged out, a wrong `--repo`, a typo in the number): expected exit 1, `gh`'s error shown, `teardown: nothing removed: gh could not read PR 12`, nothing marked. Task 2, case *gh-fails*.
3. **A worktree under `.claude/worktrees/` in a repo that has `scripts/worktree.sh`** (Deploy has both): it is a linked worktree, not a slot, so `git worktree remove` removes it and `worktree.sh` is never called. Task 2, case *nested-in-slot-repo*.
4. **The checkout given with a trailing slash** (tab completion): realpath'd, so the removal and the last line name the same path as `git worktree list`. Task 2, case *linked* passes `"$WT/"`.
5. **An unreadable live session rooted in a nested worktree** while the primary checkout is torn down: `owners.py` still fails closed (same repository), so the primary is not switched under it. Task 1's owners case.

---

## File Structure

- Modify `skills/orchestrate/owners.py`: `nested_worktrees()`, `in_nested_worktree()`, one condition in `works_in()`, one docstring paragraph.
- Modify `skills/orchestrate/tests/owners_test.sh`: one case block before `echo "PASS owners.py"`.
- Create `skills/orchestrate/teardown.py`: the script. Mode 644, run through `python3` like `owners.py`.
- Create `skills/orchestrate/tests/teardown_test.sh`: its fixture test, and (Tasks 3 and 4) the pins on the Markdown callers.
- Modify `skills/orchestrate/references/commands.md`: §Owner (one sentence, Task 1), §Watch and §Teardown (Task 3).
- Modify `skills/orchestrate/SKILL.md`: the required-keys sentence (line 18) and step 6 (line 29).
- Modify `skills/pipeline/references/engine.md`: the `Worktree`/`remove` row (line 313), §After the merge steps 1–3 (lines 520–531), the `merged`/`closed` status row (line 935).
- Modify `skills/slots/SKILL.md`: *Exception — your own slot after its PR merged* (lines 67–70).
- Modify `CLAUDE.md`: a new bullet after *Never commit directly to main* (line 244), and one sentence in the stale-checkout exception (line 268, which moves down by the new bullet's length).

---

### Task 1: `owners.py` does not count a nested worktree's sessions as owners of the primary checkout

**Files:**
- Modify: `skills/orchestrate/owners.py` (`works_in()`, new helpers above it, the module docstring)
- Modify: `skills/orchestrate/references/commands.md` §Owner of in-flight work
- Test: `skills/orchestrate/tests/owners_test.sh`

**Interfaces:**
- Consumes: nothing.
- Produces: `owners.py <worktree>` unchanged in signature and output; for a primary checkout, an entry whose cwd lies in a registered worktree nested inside it is no evidence. Task 2's `owners` check relies on this for the primary-checkout kind.

- [ ] **Step 1: Write the failing test.** In `skills/orchestrate/tests/owners_test.sh`, insert before the last line `echo "PASS owners.py"`:

```bash
# A session working in a worktree nested in the primary checkout (.claude/worktrees/*) does not own the
# primary; one working in the primary itself does, and so does one beside the nested worktree. git lists
# real paths (/private/var on macOS) while this fixture's paths may not be real.
NESTED="$PRIMARY/.claude/worktrees/b"
git -C "$PRIMARY" worktree add -q "$NESTED" -b nested-b
N="$TMP/projects-nested"
mkdir -p "$N/x"
entry "$NESTED/code/www"              > "$N/x/n-in.jsonl"        # inside the nested worktree -> not an owner
entry "$PRIMARY"                      > "$N/x/n-primary.jsonl"   # the primary itself         -> owner
entry "$PRIMARY/.claude/worktrees/bb" > "$N/x/n-prefix.jsonl"    # prefix trap: bb is not b   -> owner
actual="$(printf '[{"id":"n-in","name":"in nested","state":"working","sessionId":"n-in"},{"id":"n-primary","name":"in primary","state":"working","sessionId":"n-primary"},{"id":"n-prefix","name":"beside nested","state":"working","sessionId":"n-prefix"}]' \
  | python3 "$HERE/../owners.py" "$PRIMARY" --projects-dir "$N" --session-id ccc | sort)"
expected="$(printf 'beside nested\tn-prefix\tworking\t1\nin primary\tn-primary\tworking\t1\n' | sort)"
[ "$actual" = "$expected" ] || fail "$(printf 'nested worktree\n--- expected\n%s\n--- actual\n%s' "$expected" "$actual")"

# An unreadable live session rooted in the nested worktree still blocks the primary: same repository.
status=0
printf '[{"id":"n-ghost","name":"ghost","state":"working","sessionId":"n-ghost","cwd":"%s"}]' "$NESTED" \
  | python3 "$HERE/../owners.py" "$PRIMARY" --projects-dir "$N" --session-id ccc >/dev/null 2>"$TMP/err" || status=$?
[ "$status" -eq 2 ] || fail "an unreadable session in a nested worktree exited $status, expected 2"
grep -q "ghost" "$TMP/err" || fail "the error does not name the nested session: $(cat "$TMP/err")"
```

- [ ] **Step 2: Run it to verify it fails.**

Run: `bash skills/orchestrate/tests/owners_test.sh`
Expected: exit 1, `FAIL owners.py: nested worktree`, with `in nested	n-in	working	1` among the actual lines.

- [ ] **Step 3: Implement.** In `skills/orchestrate/owners.py`, insert above `def works_in(entry, worktree):`:

```python
@functools.lru_cache(maxsize=None)
def nested_worktrees(worktree):
    """The registered worktrees inside this one (a primary checkout's .claude/worktrees/*), as git prints them: real paths."""
    result = subprocess.run(
        ["git", "-C", worktree, "worktree", "list", "--porcelain"],
        capture_output=True,
        text=True,
    )
    root = os.path.realpath(worktree)
    listed = (line[len("worktree "):] for line in result.stdout.splitlines() if line.startswith("worktree "))
    return tuple(path for path in listed if path != root and inside(path, root))


def in_nested_worktree(cwd, worktree):
    real = os.path.realpath(cwd)
    return any(inside(real, nested) for nested in nested_worktrees(worktree))
```

and change the first condition of `works_in()` to:

```python
    if isinstance(cwd, str) and cwd and inside(cwd, worktree) and not in_nested_worktree(cwd, worktree):
        return True
```

A failed `git worktree list` (not a repository) prints nothing on stdout, so the tuple is empty and the behaviour is today's. In the module docstring, after the paragraph that ends `…every session that mapped a worktree mentions it.`, add:

```
A primary checkout contains the worktrees nested in it (.claude/worktrees/*): an entry whose cwd lies in
one of those works in that worktree, not in the primary checkout. Fail-closed is unchanged, so an
unreadable session rooted in a nested worktree still blocks the primary (same repository).
```

In `skills/orchestrate/references/commands.md` §Owner of in-flight work, after the sentence `Only a \`working\` or \`blocked\` session owns a worktree; \`done\`, \`failed\` and \`stopped\` rows never do.`, add:
`A session working in a worktree nested in the primary checkout (\`.claude/worktrees/*\`) does not own the primary checkout.`

- [ ] **Step 4: Run the tests to verify they pass.**

Run: `bash skills/orchestrate/tests/owners_test.sh && bash skills/orchestrate/tests/needs_input_test.sh`
Expected: `PASS owners.py`, then `PASS needs_input.py`.

- [ ] **Step 5: Commit.**

```bash
git add skills/orchestrate/owners.py skills/orchestrate/tests/owners_test.sh skills/orchestrate/references/commands.md
git commit -m "fix(orchestrate): a session in a nested worktree does not own the primary checkout (#168)"
```

---

### Task 2: `teardown.py` reads, marks, checks and removes

Two red-green cycles in one task: first everything that removes nothing (Steps 1–5), then the removal by kind (Steps 6–10).

**Files:**
- Create: `skills/orchestrate/teardown.py`
- Test: `skills/orchestrate/tests/teardown_test.sh` (new)

**Interfaces:**
- Consumes: `owners.py <worktree> [--projects-dir DIR]` reading `claude agents --json --all` on stdin (Task 1); `php skills/pipeline/checks/proof_cli.php status <page> <merged|closed>` (exists, `proof_cli.php:10`); a repo's `scripts/worktree.sh remove <N> --force-local-branch-removal`, run from the primary checkout.
- Produces: the command `python3 ~/.claude/skills/orchestrate/teardown.py <checkout> <pr> [--repo <owner/name>] [--proof <page.html>] [--projects-dir DIR]`, exit codes 0/1/2/3, last line `teardown: …`. Tasks 3 and 4 put exactly this command in the docs and append doc pins to this test above its last line `echo "PASS teardown.py"`.
- Inside the file: `PullRequest.read(pr, repo, cwd) -> PullRequest` (fields `number`, `state`, `head_sha`, `head`, `base`); `Checkout.at(path) -> Slot | LinkedWorktree | PrimaryCheckout`, each with `path`, `primary`, `git(*args) -> str`, `outside(cwd) -> bool`, `remove(pr, steps) -> str` (Step 8); `checks(checkout, pr, projects_dir) -> list[(name, ok, found)]`; `Steps(cwd).run(*command) -> str`, raising `Stopped(command, done)`.

- [ ] **Step 1: Write the failing test.** Create `skills/orchestrate/tests/teardown_test.sh`:

```bash
#!/usr/bin/env bash
# Fixture test for teardown.py: after a merge it removes a slot, a linked worktree or the primary
# checkout's feature branch only when every check holds (clean, HEAD is the merged head, the PR's
# branch, run from outside, no owner), and otherwise removes nothing and says why. The proof page is
# marked before anything is checked. gh, claude and php are stubs on PATH; git is real, against a bare
# origin per case.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd -P)"   # physical: $REPO is this repo, not ~/.claude via the symlink
TEARDOWN="$HERE/../teardown.py"
TMP="$(cd "$(mktemp -d)" && pwd -P)"   # physical: git prints real paths (/private/var on macOS)
trap 'rm -rf "$TMP"' EXIT
LOG="$TMP/log"
STUBS="$TMP/stubs"
mkdir -p "$STUBS"

cat > "$STUBS/gh" <<'STUB'
#!/usr/bin/env bash
echo "gh $*" >> "$TEARDOWN_LOG"
[ -n "${TEARDOWN_GH:-}" ] || { echo "GraphQL: Could not resolve to a PullRequest with the number of 12." >&2; exit 1; }
printf '%s\n' "$TEARDOWN_GH"
STUB
cat > "$STUBS/claude" <<'STUB'
#!/usr/bin/env bash
printf '%s\n' "${TEARDOWN_AGENTS:-[]}"
STUB
cat > "$STUBS/php" <<'STUB'
#!/usr/bin/env bash
echo "php $*" >> "$TEARDOWN_LOG"
[ "${TEARDOWN_PHP_EXIT:-0}" -eq 0 ] || { echo "proof: status not written: no such page" >&2; exit "$TEARDOWN_PHP_EXIT"; }
STUB
chmod +x "$STUBS/gh" "$STUBS/claude" "$STUBS/php"
export PATH="$STUBS:$PATH" TEARDOWN_LOG="$LOG" CLAUDE_CODE_SESSION_ID=me
export GIT_AUTHOR_NAME=t GIT_AUTHOR_EMAIL=t@t GIT_COMMITTER_NAME=t GIT_COMMITTER_EMAIL=t@t

CASE=setup
out=""
fail() { printf 'FAIL teardown.py (%s): %s\n--- output\n%s\n' "$CASE" "$1" "$out"; exit 1; }

case_start() { # <name>: an empty log and the stubs' defaults
  CASE="$1"
  out=""
  : > "$LOG"
  export TEARDOWN_GH='' TEARDOWN_AGENTS='[]' TEARDOWN_PHP_EXIT=0 TEARDOWN_WORKTREE_EXIT=0
}

fixture() { # <dir>: $SHOP, a primary clone <dir>/Shop/Shop on main, pushed to the bare $ORIGIN
  SHOP="$TMP/$1/Shop/Shop"
  ORIGIN="$TMP/$1/origin.git"
  mkdir -p "$SHOP"
  git init -q --bare --initial-branch=main "$ORIGIN"
  git init -q --initial-branch=main "$SHOP"
  git -C "$SHOP" remote add origin "$ORIGIN"
  git -C "$SHOP" commit -q --allow-empty -m init
  git -C "$SHOP" push -q -u origin main >/dev/null 2>&1
}

worktree() { # <path> <branch>: a linked worktree of $SHOP on a new <branch> with one commit, pushed
  git -C "$SHOP" worktree add -q "$1" -b "$2"
  git -C "$1" commit -q --allow-empty -m "work on $2"
  git -C "$1" push -q origin "$2" >/dev/null 2>&1
}

pr() { # <checkout> [state]: gh's answer for PR #12, its head the checkout's HEAD and branch
  printf '{"number":12,"state":"%s","headRefOid":"%s","headRefName":"%s","baseRefName":"main"}' \
    "${2:-MERGED}" "$(git -C "$1" rev-parse HEAD)" "$(git -C "$1" branch --show-current)"
}

linked() { # <dir>: a fixture with the linked worktree $WT on branch b, and $TEARDOWN_GH its merged PR
  fixture "$1"
  WT="$SHOP/.claude/worktrees/b"
  worktree "$WT" b
  TEARDOWN_GH="$(pr "$WT")"
  export TEARDOWN_GH
}

teardown() { # <cwd> <arguments…>: runs teardown.py there; sets $status, $out (stdout and stderr), $last
  local cwd="$1"
  shift
  status=0
  out="$(cd "$cwd" && python3 "$TEARDOWN" "$@" 2>&1)" || status=$?
  last="$(printf '%s\n' "$out" | tail -n 1)"
}

ends() { # <exit code> <last line>
  [ "$status" -eq "$1" ] || fail "exited $status, expected $1"
  [ "$last" = "$2" ] || fail "last line: expected '$2', got '$last'"
}
says() { printf '%s\n' "$out" | grep -qF -- "$1" || fail "the output does not say: $1"; }
logged() { grep -qF -- "$1" "$LOG" || fail "not logged: $1 (log: $(cat "$LOG"))"; }
not_logged() { ! grep -qF -- "$1" "$LOG" || fail "logged: $1 (log: $(cat "$LOG"))"; }
still_there() { # <worktree> <branch>
  [ -d "$1" ] || fail "$1 was removed"
  git -C "$SHOP" rev-parse --verify -q "refs/heads/$2" >/dev/null || fail "branch $2 was deleted"
}

# An untracked file: nothing removed, the clean check names it.
case_start dirty; linked dirty
touch "$WT/notes.txt"
teardown "$TMP" "$WT" 12
ends 1 "teardown: nothing removed: clean"
says "FAIL clean: ?? notes.txt"
still_there "$WT" b

# One more local commit: HEAD is not the merged head.
case_start head; linked head
git -C "$WT" commit -q --allow-empty -m "after the merge"
teardown "$TMP" "$WT" 12
ends 1 "teardown: nothing removed: head"
says "FAIL head: HEAD is $(git -C "$WT" rev-parse HEAD)"
still_there "$WT" b

# Another branch at the merged head: the branch about to be deleted would not be the PR's.
case_start branch; linked branch
git -C "$WT" switch -q -c other
teardown "$TMP" "$WT" 12
ends 1 "teardown: nothing removed: branch"
says "FAIL branch: on other, the PR's branch is b"
still_there "$WT" b

# Every failed check is printed in one run, the passing ones too.
case_start both; linked both
touch "$WT/notes.txt"
git -C "$WT" commit -q --allow-empty -m "after the merge"
teardown "$TMP" "$WT" 12
ends 1 "teardown: nothing removed: clean, head"
says "FAIL clean"; says "FAIL head"; says "ok branch"; says "ok outside"; says "ok owners"

# Another live session works in the worktree: owners, with the owner line shown.
case_start owned; linked owned
mkdir -p "$TMP/projects/x"
printf '{"type":"assistant","cwd":"%s"}\n' "$WT" > "$TMP/projects/x/other.jsonl"
export TEARDOWN_AGENTS='[{"id":"other","name":"other session","state":"working","sessionId":"other"}]'
teardown "$TMP" "$WT" 12 --projects-dir "$TMP/projects"
ends 1 "teardown: nothing removed: owners"
says "FAIL owners: other session"
still_there "$WT" b

# owners.py fails closed: a live session rooted in the worktree without a transcript.
case_start unreadable; linked unreadable
mkdir -p "$TMP/no-projects"
TEARDOWN_AGENTS='[{"id":"ghost","name":"ghost","state":"working","sessionId":"ghost","cwd":"'"$WT"'"}]'
export TEARDOWN_AGENTS
teardown "$TMP" "$WT" 12 --projects-dir "$TMP/no-projects"
ends 1 "teardown: nothing removed: owners"
says "cannot tell whether these live sessions own the worktree"
says "ghost"
still_there "$WT" b

# Run from inside the worktree it would remove: outside.
case_start inside; linked inside
teardown "$WT" "$WT" 12
ends 1 "teardown: nothing removed: outside"
says "FAIL outside: this command runs inside $WT"
still_there "$WT" b

# Closed without merge: the page is marked closed, nothing is checked or removed.
case_start closed; linked closed
TEARDOWN_GH="$(pr "$WT" CLOSED)"
teardown "$TMP" "$WT" 12 --proof "$TMP/page.html"
ends 1 "teardown: nothing removed: PR #12 was closed without merge"
logged "proof_cli.php status $TMP/page.html closed"
not_logged "merged"
still_there "$WT" b

# Still open: nothing marked, nothing removed.
case_start open; linked open
TEARDOWN_GH="$(pr "$WT" OPEN)"
teardown "$TMP" "$WT" 12 --proof "$TMP/page.html"
ends 1 "teardown: nothing removed: PR #12 is still open"
not_logged "php"
still_there "$WT" b

# gh cannot read the PR: its error, nothing marked, nothing removed.
case_start gh-fails; linked gh-fails
TEARDOWN_GH=''
teardown "$TMP" "$WT" 12 --proof "$TMP/page.html"
ends 1 "teardown: nothing removed: gh could not read PR 12"
says "Could not resolve to a PullRequest"
not_logged "php"
still_there "$WT" b

# Not the top level of a working tree, or no directory at all: usage on stderr, exit 2, gh never asked.
case_start usage; linked usage
mkdir -p "$WT/sub"
for path in "$WT/sub" "$TMP/nowhere"; do
  status=0
  python3 "$TEARDOWN" "$path" 12 >/dev/null 2>"$TMP/err" || status=$?
  [ "$status" -eq 2 ] || fail "$path exited $status, expected 2"
  grep -q '^usage: ' "$TMP/err" || fail "no usage on stderr for $path: $(cat "$TMP/err")"
done
not_logged "gh "

echo "PASS teardown.py"
```

- [ ] **Step 2: Run it to verify it fails.**

Run: `bash skills/orchestrate/tests/teardown_test.sh`
Expected: exit 1, `FAIL teardown.py (dirty): exited 2, expected 1` and `can't open file` in the output (no script yet).

- [ ] **Step 3: Implement the read, the mark and the checks.** Create `skills/orchestrate/teardown.py`:

```python
#!/usr/bin/env python3
"""Tear down the checkout of a merged PR, once every check holds.

Usage: teardown.py <checkout> <pr> [--repo <owner/name>] [--proof <page.html>] [--projects-dir DIR]

<checkout> is the working tree the PR's branch is checked out in: a slot, another linked worktree, or
the primary checkout. <pr> is the PR's number or URL, as gh takes it. In order, a line per stage:

1. read the PR (gh pr view, from the checkout, with -R <repo> when given);
2. mark its proof page merged or closed: --proof, else the run manifest's artifacts.proof
   (<checkout>/.claude/pipeline/<branch, / -> ->.json); a failed mark is printed and passed over;
3. stop when the PR is still open or was closed without merge;
4. print every check: clean, head (HEAD is the merged head), branch (the PR's), outside (this command
   does not run inside a worktree it would remove), owners (owners.py finds no other live session);
5. only when all hold, remove from the primary checkout: a slot (<Project>-<N> beside a primary that
   has scripts/worktree.sh) through `scripts/worktree.sh remove <N> --force-local-branch-removal`,
   any other linked worktree with `git worktree remove`, and the primary checkout back to its base
   with `git pull --ff-only origin <base>`; then `git branch -D` of the PR's branch.

The last line starts with `teardown: `. Exit 0 removed, 1 nothing removed, 2 usage, 3 stopped
part-way (the last line names the command that failed and what was already done).
"""
import argparse
import json
import os
import re
import subprocess
import sys
from dataclasses import dataclass

HERE = os.path.dirname(os.path.realpath(__file__))
PROOF_CLI = os.path.join(os.path.dirname(HERE), "pipeline", "checks", "proof_cli.php")
OWNERS = os.path.join(HERE, "owners.py")
PAGE_STATUS = {"MERGED": "merged", "CLOSED": "closed"}


def run(command, cwd, stdin=None):
    return subprocess.run(command, cwd=cwd, input=stdin, capture_output=True, text=True)


def say(line):
    print(line, flush=True)


def end(line, code):
    say("teardown: " + line)
    sys.exit(code)


def inside(path, root):
    return path == root or path.startswith(root + "/")


@dataclass
class PullRequest:
    number: int
    state: str
    head_sha: str
    head: str
    base: str

    @classmethod
    def read(cls, pr, repo, cwd):
        command = ["gh", "pr", "view", pr, "--json", "number,state,headRefOid,headRefName,baseRefName"]
        result = run(command + (["-R", repo] if repo else []), cwd)
        if result.returncode != 0:
            sys.stderr.write(result.stderr)
            sys.stderr.flush()
            end(f"nothing removed: gh could not read PR {pr}", 1)
        view = json.loads(result.stdout)
        return cls(view["number"], view["state"], view["headRefOid"], view["headRefName"], view["baseRefName"])


class Checkout:
    def __init__(self, path, primary):
        self.path, self.primary = path, primary

    @staticmethod
    def at(path):
        primary = primary_checkout(path)
        if path == primary:
            return PrimaryCheckout(path, primary)
        number = slot_number(path, primary)
        return Slot(path, primary, number) if number else LinkedWorktree(path, primary)

    def git(self, *args):
        return run(["git", *args], self.path).stdout.strip()

    def outside(self, cwd):
        return not inside(cwd, self.path)


class LinkedWorktree(Checkout):
    pass


class Slot(Checkout):
    def __init__(self, path, primary, number):
        super().__init__(path, primary)
        self.number = number


class PrimaryCheckout(Checkout):
    def outside(self, cwd):
        return True  # the primary checkout stays on disk


def primary_checkout(path):
    """git lists the main working tree first: the one whose git dir is the common dir."""
    listing = run(["git", "worktree", "list", "--porcelain"], path).stdout
    return os.path.realpath(listing.splitlines()[0][len("worktree "):])


def slot_number(path, primary):
    if not os.path.isfile(os.path.join(primary, "scripts", "worktree.sh")):
        return None
    if os.path.dirname(path) != os.path.dirname(primary):
        return None
    found = re.fullmatch(re.escape(os.path.basename(primary)) + r"-(\d+)", os.path.basename(path))
    return found.group(1) if found else None


def proof_page(given, checkout, pr):
    if given:
        return given
    manifest = os.path.join(checkout.path, ".claude", "pipeline", pr.head.replace("/", "-") + ".json")
    try:
        with open(manifest) as file:
            artifacts = json.load(file).get("artifacts")
    except (OSError, ValueError, AttributeError):
        return None
    return artifacts.get("proof") if isinstance(artifacts, dict) else None


def mark(page, pr):
    status = PAGE_STATUS.get(pr.state)
    if not page or not status:
        return
    result = run(["php", PROOF_CLI, "status", page, status], os.getcwd())
    say(f"page: marked {status}, {page}" if result.returncode == 0 else f"page: not marked: {result.stderr.strip()}")


def owners(path, projects_dir):
    """What owners.py reports: empty when no other live session works in the checkout."""
    agents = run(["claude", "agents", "--json", "--all"], path)
    if agents.returncode != 0:
        return "claude agents failed: " + agents.stderr.strip()
    command = [sys.executable, OWNERS, path] + (["--projects-dir", projects_dir] if projects_dir else [])
    result = run(command, path, stdin=agents.stdout)
    found = (result.stdout + result.stderr).strip()
    return found or ("" if result.returncode == 0 else f"owners.py exited {result.returncode}")


def checks(checkout, pr, projects_dir):
    changes = checkout.git("status", "--porcelain")
    head = checkout.git("rev-parse", "HEAD")
    branch = checkout.git("branch", "--show-current")
    owned = owners(checkout.path, projects_dir)
    return [
        ("clean", not changes, "; ".join(changes.splitlines())),
        ("head", head == pr.head_sha, f"HEAD is {head}, the merged head is {pr.head_sha}"),
        ("branch", branch == pr.head, f"on {branch or 'a detached HEAD'}, the PR's branch is {pr.head}"),
        ("outside", checkout.outside(os.path.realpath(os.getcwd())),
         f"this command runs inside {checkout.path}: run it from the primary checkout {checkout.primary}"
         " (a session that entered the worktree leaves it first: ExitWorktree, keep)"),
        ("owners", not owned, owned),
    ]


def arguments():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("checkout", help="the working tree the PR's branch is checked out in")
    parser.add_argument("pr", help="the PR's number or URL")
    parser.add_argument("--repo", help="owner/name, passed to gh as -R")
    parser.add_argument("--proof", help="the proof page to mark (default: the run manifest's artifacts.proof)")
    parser.add_argument("--projects-dir", help="passed on to owners.py")
    args = parser.parse_args()
    args.checkout = os.path.realpath(args.checkout)
    top = run(["git", "rev-parse", "--show-toplevel"], args.checkout) if os.path.isdir(args.checkout) else None
    if top is None or top.returncode != 0 or os.path.realpath(top.stdout.strip()) != args.checkout:
        parser.error(f"{args.checkout} is not the top level of a git working tree")
    return args


def main():
    args = arguments()
    checkout = Checkout.at(args.checkout)
    pr = PullRequest.read(args.pr, args.repo, checkout.path)
    say(f"PR #{pr.number} {pr.state}: {pr.head} at {pr.head_sha[:7]} into {pr.base}")
    mark(proof_page(args.proof, checkout, pr), pr)
    if pr.state == "OPEN":
        end(f"nothing removed: PR #{pr.number} is still open", 1)
    if pr.state != "MERGED":
        end(f"nothing removed: PR #{pr.number} was closed without merge", 1)

    results = checks(checkout, pr, args.projects_dir)
    for name, ok, found in results:
        say(f"ok {name}" if ok else f"FAIL {name}: " + found.replace("\n", "\n  "))
    failed = [name for name, ok, _ in results if not ok]
    if failed:
        end("nothing removed: " + ", ".join(failed), 1)


if __name__ == "__main__":
    main()
```

Note: `checkout.git("status", "--porcelain")` strips the output, so an untracked file reads `?? notes.txt` and a modified one `M file` on the first line; the test pins only the untracked form.

- [ ] **Step 4: Run it to verify it passes.**

Run: `bash skills/orchestrate/tests/teardown_test.sh`
Expected: `PASS teardown.py`.

- [ ] **Step 5: Commit.**

```bash
git add skills/orchestrate/teardown.py skills/orchestrate/tests/teardown_test.sh
git commit -m "feat(orchestrate): teardown.py reads the PR, marks its page and prints every check (#168)"
```

- [ ] **Step 6: Write the failing removal cases.** In `skills/orchestrate/tests/teardown_test.sh`, add after the `still_there()` helper:

```bash
gone() { # <worktree> <branch>
  [ ! -d "$1" ] || fail "$1 is still there"
  ! git -C "$SHOP" rev-parse --verify -q "refs/heads/$2" >/dev/null || fail "branch $2 is still there"
  ! git -C "$SHOP" worktree list --porcelain | grep -qxF "worktree $1" || fail "git still lists $1"
}

slot_script() { # commits to $SHOP a scripts/worktree.sh that logs, then removes slot <N> as the real one does
  mkdir -p "$SHOP/scripts"
  cat > "$SHOP/scripts/worktree.sh" <<'STUB'
#!/usr/bin/env bash
echo "worktree.sh $*" >> "$TEARDOWN_LOG"
[ "${TEARDOWN_WORKTREE_EXIT:-0}" -eq 0 ] || { echo "ERROR: docker compose down -v failed" >&2; exit 1; }
slot="$(pwd -P)-$2"
branch="$(git -C "$slot" branch --show-current)"
git worktree remove --force "$slot"
[ "${3:-}" != --force-local-branch-removal ] || git branch -D -q "$branch"
STUB
  git -C "$SHOP" add scripts/worktree.sh
  git -C "$SHOP" commit -q -m "worktree.sh"
}

slot() { # <dir>: a fixture with scripts/worktree.sh and the slot $SLOT (Shop-4) on feature/issue-4-x, merged
  fixture "$1"
  slot_script
  SLOT="$(dirname "$SHOP")/Shop-4"
  worktree "$SLOT" feature/issue-4-x
  TEARDOWN_GH="$(pr "$SLOT")"
  export TEARDOWN_GH
}
```

and insert before `echo "PASS teardown.py"`:

```bash
# Merged, a linked worktree under the primary, given with a trailing slash: the worktree and branch go.
case_start linked; linked linked
teardown "$TMP" "$WT/" 12
ends 0 "teardown: removed worktree $WT and branch b"
says "ok clean"; says "ok owners"
gone "$WT" b

# A worktree under .claude/worktrees in a repo with scripts/worktree.sh is a linked worktree, not a slot.
case_start nested-in-slot-repo; fixture nested-in-slot-repo
slot_script
WT="$SHOP/.claude/worktrees/b"
worktree "$WT" b
TEARDOWN_GH="$(pr "$WT")"
teardown "$TMP" "$WT" 12
ends 0 "teardown: removed worktree $WT and branch b"
not_logged "worktree.sh"
gone "$WT" b

# Merged, a slot: worktree.sh removes it, its stack and its branch.
case_start slot; slot slot
teardown "$TMP" "$SLOT" 12
ends 0 "teardown: removed slot 4 ($SLOT), its stack and branch feature/issue-4-x"
logged "worktree.sh remove 4 --force-local-branch-removal"
gone "$SLOT" feature/issue-4-x

# The page is the manifest's artifacts.proof, marked before worktree.sh runs.
case_start manifest; slot manifest
printf '.claude/pipeline/\n' >> "$SHOP/.git/info/exclude"
mkdir -p "$SLOT/.claude/pipeline"
printf '{"artifacts":{"proof":"%s"}}\n' "$TMP/run/index.html" > "$SLOT/.claude/pipeline/feature-issue-4-x.json"
teardown "$TMP" "$SLOT" 12
ends 0 "teardown: removed slot 4 ($SLOT), its stack and branch feature/issue-4-x"
marked="$(grep -n "status $TMP/run/index.html merged" "$LOG" | cut -d: -f1 || true)"
removed="$(grep -n "worktree.sh remove 4" "$LOG" | cut -d: -f1 || true)"
[ -n "$marked" ] && [ -n "$removed" ] && [ "$marked" -lt "$removed" ] \
  || fail "the page was not marked before worktree.sh ran: $(cat "$LOG")"

# --proof overrides the manifest's page.
case_start proof-flag; linked proof-flag
printf '.claude/pipeline/\n' >> "$SHOP/.git/info/exclude"
mkdir -p "$WT/.claude/pipeline"
printf '{"artifacts":{"proof":"%s"}}\n' "$TMP/run/index.html" > "$WT/.claude/pipeline/b.json"
teardown "$TMP" "$WT" 12 --proof "$TMP/other/index.html"
ends 0 "teardown: removed worktree $WT and branch b"
logged "status $TMP/other/index.html merged"
not_logged "$TMP/run/index.html"

# A failing mark is printed and the removal still happens.
case_start mark-fails; linked mark-fails
export TEARDOWN_PHP_EXIT=1
teardown "$TMP" "$WT" 12 --proof "$TMP/page.html"
ends 0 "teardown: removed worktree $WT and branch b"
says "page: not marked: proof: status not written: no such page"
gone "$WT" b

# A removal command that fails: exit 3, the command named, nothing after it run.
case_start stopped; slot stopped
export TEARDOWN_WORKTREE_EXIT=1
teardown "$TMP" "$SLOT" 12
ends 3 "teardown: stopped at bash scripts/worktree.sh remove 4 --force-local-branch-removal: nothing done yet"
says "ERROR: docker compose down -v failed"
still_there "$SLOT" feature/issue-4-x

# Merged from the primary checkout: back on main at origin's main, the branch gone, restart.sh only named.
case_start primary; fixture primary
mkdir -p "$SHOP/scripts"
printf '#!/usr/bin/env bash\necho restarted >> "$TEARDOWN_LOG"\n' > "$SHOP/scripts/restart.sh"
git -C "$SHOP" add scripts/restart.sh
git -C "$SHOP" commit -q -m "restart.sh"
git -C "$SHOP" push -q origin main >/dev/null 2>&1
git -C "$SHOP" switch -q -c feature/x
git -C "$SHOP" commit -q --allow-empty -m "the feature"
git -C "$SHOP" push -q origin feature/x feature/x:main >/dev/null 2>&1   # the merge: origin's main moves to the head
TEARDOWN_GH="$(pr "$SHOP")"
teardown "$TMP" "$SHOP" 12
ends 0 "teardown: $SHOP back on main at $(git -C "$ORIGIN" rev-parse --short main); removed branch feature/x; not run: ./scripts/restart.sh (it reseeds the database)"
[ "$(git -C "$SHOP" branch --show-current)" = main ] || fail "the primary checkout is not on main"
[ "$(git -C "$SHOP" rev-parse HEAD)" = "$(git -C "$ORIGIN" rev-parse main)" ] || fail "main is not at origin's main"
! git -C "$SHOP" rev-parse --verify -q refs/heads/feature/x >/dev/null || fail "feature/x is still there"
not_logged "restarted"

# The primary checkout's local main diverged: stopped at the pull, after the switch, the branch kept.
case_start diverged; fixture diverged
git -C "$SHOP" commit -q --allow-empty -m "local only, never pushed"
git -C "$SHOP" switch -q -c feature/x origin/main
git -C "$SHOP" commit -q --allow-empty -m "the feature"
git -C "$SHOP" push -q origin feature/x feature/x:main >/dev/null 2>&1
TEARDOWN_GH="$(pr "$SHOP")"
teardown "$TMP" "$SHOP" 12
ends 3 "teardown: stopped at git pull --ff-only origin main: done: git switch main"
[ "$(git -C "$SHOP" branch --show-current)" = main ] || fail "the switch to main did not happen"
git -C "$SHOP" rev-parse --verify -q refs/heads/feature/x >/dev/null || fail "feature/x was deleted"
```

- [ ] **Step 7: Run it to verify it fails.**

Run: `bash skills/orchestrate/tests/teardown_test.sh`
Expected: exit 1, `FAIL teardown.py (linked): last line: expected 'teardown: removed worktree … and branch b', got 'ok owners'` (Step 3's `main()` exits 0 silently after the checks).

- [ ] **Step 8: Implement the removal.** In `skills/orchestrate/teardown.py`:

Add after `def inside(...)`:

```python
class Stopped(Exception):
    def __init__(self, command, done):
        super().__init__(command)
        self.command, self.done = command, done


class Steps:
    """Removal commands, run in order from the primary checkout; the first that fails stops the rest."""

    def __init__(self, cwd):
        self.cwd, self.done = cwd, []

    def run(self, *command):
        line = " ".join(command)
        result = run(list(command), self.cwd)
        if result.returncode != 0:
            sys.stdout.write(result.stdout + result.stderr)
            sys.stdout.flush()
            raise Stopped(line, self.done)
        self.done.append(line)
        return result.stdout
```

Add the constant under `PAGE_STATUS`:

```python
RESTART_NOTE = "; not run: ./scripts/restart.sh (it reseeds the database)"
```

Replace the three kind classes with:

```python
class LinkedWorktree(Checkout):
    def remove(self, pr, steps):
        steps.run("git", "worktree", "remove", self.path)
        steps.run("git", "branch", "-D", pr.head)
        return f"removed worktree {self.path} and branch {pr.head}"


class Slot(Checkout):
    def __init__(self, path, primary, number):
        super().__init__(path, primary)
        self.number = number

    def remove(self, pr, steps):
        steps.run("bash", "scripts/worktree.sh", "remove", self.number, "--force-local-branch-removal")
        if run(["git", "rev-parse", "--verify", "--quiet", "refs/heads/" + pr.head], self.primary).returncode == 0:
            steps.run("git", "branch", "-D", pr.head)
        return f"removed slot {self.number} ({self.path}), its stack and branch {pr.head}"


class PrimaryCheckout(Checkout):
    def outside(self, cwd):
        return True  # the primary checkout stays on disk

    def remove(self, pr, steps):
        steps.run("git", "switch", pr.base)
        steps.run("git", "pull", "--ff-only", "origin", pr.base)
        steps.run("git", "branch", "-D", pr.head)
        line = f"{self.path} back on {pr.base} at {self.git('rev-parse', '--short', 'HEAD')}; removed branch {pr.head}"
        return line + (RESTART_NOTE if os.path.isfile(os.path.join(self.path, "scripts", "restart.sh")) else "")
```

Append to `main()`, after the `if failed:` block:

```python
    try:
        end(checkout.remove(pr, Steps(checkout.primary)), 0)
    except Stopped as stopped:
        done = "done: " + ", ".join(stopped.done) if stopped.done else "nothing done yet"
        end(f"stopped at {stopped.command}: {done}", 3)
```

`end()` raises `SystemExit`, which `except Stopped` does not catch.

- [ ] **Step 9: Run the tests to verify they pass.**

Run: `bash skills/orchestrate/tests/teardown_test.sh && bash skills/orchestrate/tests/owners_test.sh`
Expected: `PASS teardown.py`, then `PASS owners.py`.

- [ ] **Step 10: Commit.**

```bash
git add skills/orchestrate/teardown.py skills/orchestrate/tests/teardown_test.sh
git commit -m "feat(orchestrate): teardown.py removes a merged slot, worktree or feature branch by the checkout's kind (#168)"
```

---

### Task 3: orchestrate, pipeline and slots call the script and carry no copy of it

**Files:**
- Modify: `skills/orchestrate/references/commands.md` (§Watch, §Teardown)
- Modify: `skills/orchestrate/SKILL.md` (line 18, step 6 at line 29)
- Modify: `skills/pipeline/references/engine.md` (line 313, lines 520–531, line 935)
- Modify: `skills/slots/SKILL.md` (lines 67–70)
- Test: `skills/orchestrate/tests/teardown_test.sh` (doc pins)

**Interfaces:**
- Consumes: Task 2's command line and exit codes.
- Produces: the callers' text; Task 4 appends its own pins after these.

- [ ] **Step 1: Write the failing pins.** In `skills/orchestrate/tests/teardown_test.sh`, insert before `echo "PASS teardown.py"`:

```bash
# The callers run the script and carry no copy of its checks or removal; every watch has the tool's
# maximum timeout and is re-armed; orchestrate still polls every 300 s.
CASE=docs
out=""
REPO="$(cd "$HERE/../../.." && pwd)"
has() { grep -qF -- "$2" "$REPO/$1" || fail "$1 does not say: $2"; }
lacks() { ! grep -qF -- "$2" "$REPO/$1" || fail "$1 still says: $2"; }
COMMANDS=skills/orchestrate/references/commands.md
has   $COMMANDS 'python3 ~/.claude/skills/orchestrate/teardown.py <worktree> <P> --repo <repo> [--proof <proof>]'
has   $COMMANDS 'timeout: 7200000'
has   $COMMANDS 'arm it again'
lacks $COMMANDS 'status --porcelain'
lacks $COMMANDS 'worktree.sh remove <N>'
lacks $COMMANDS 'status <proof> merged'
lacks $COMMANDS 'outlives its call'
lacks $COMMANDS 'sleep 60'
[ "$(grep -c 'do sleep 300; done' "$REPO/$COMMANDS")" -eq 2 ] || fail "$COMMANDS: both watch loops keep sleep 300"
has   skills/orchestrate/SKILL.md 'teardown.py'
lacks skills/orchestrate/SKILL.md 'A check fails: ask'
lacks skills/orchestrate/SKILL.md '`worktree.remove`'
ENGINE=skills/pipeline/references/engine.md
has   $ENGINE 'python3 ~/.claude/skills/orchestrate/teardown.py <worktree> <P> --repo <repo>'
has   $ENGINE 'timeout: 7200000'
has   $ENGINE '| `Worktree` | `remove` | nothing:'
lacks $ENGINE 'checks and removal as written there'
lacks $ENGINE 'A check fails: ask'
has   skills/slots/SKILL.md 'orchestrate/teardown.py'
has   skills/slots/SKILL.md 'opened its PR from'
lacks skills/slots/SKILL.md 'commands.md` §Teardown pass'
```

- [ ] **Step 2: Run it to verify it fails.**

Run: `bash skills/orchestrate/tests/teardown_test.sh`
Expected: exit 1, `FAIL teardown.py (docs): skills/orchestrate/references/commands.md does not say: python3 ~/.claude/skills/orchestrate/teardown.py <worktree> <P> --repo <repo> [--proof <proof>]`.

- [ ] **Step 3: Edit orchestrate `references/commands.md`.**

In §Watch, replace the two sentences before the code block:

```
One background Bash (`run_in_background: true`) per PR. Each exits on the change being waited for.
A `run_in_background` loop outlives its call; the Deploy orchestrator's watch on PR #431 ran two hours
and exited on the change (2026-09-16).
```

with:

```
One background Bash per PR, with `run_in_background: true` and `timeout: 7200000`, the Bash tool's
maximum: without it the tool stops the loop after 30 minutes. Each loop exits on the change being
waited for and prints its `PR #<P> …` line. A watch that ends without that line was stopped at the
time limit: arm it again, without a report.
```

The two loops (`sleep 300`) stay as they are.

Replace all of §Teardown (from `## Teardown` to the end of the file) with:

````
## Teardown

Once no completion notice of yours is pending for the run (the dispatch record), from the primary
checkout:

```bash
python3 ~/.claude/skills/orchestrate/teardown.py <worktree> <P> --repo <repo> [--proof <proof>]
```

`<proof>`: the `proof` `finish` printed; left out, the script reads the manifest's `artifacts.proof`.
It reads the PR, marks the page `merged` or `closed`, prints every check (clean, `HEAD` equals the
merged `headRefOid`, the PR's branch, run from outside the worktree, nothing owns it per `owners.py`),
and only when all hold removes the slot through `scripts/worktree.sh`, any other worktree with
`git worktree remove`, and the branch. Exit 0: removed. Exit 1: nothing removed, and the last line says
why (still open, closed without merge, the failed checks, `gh` failed). Exit 3: a removal command
failed part-way, and the last line names it and what was already done. Put its last line in the
report, not in a question.

In a batch on a base, after exit 0 the merge closed nothing: close the issue, so the runs it blocks
can kick off.
```bash
gh issue close N -R <repo> --reason completed --comment "Merged into <base> in #<P>; reaches the default branch with <base>."
```
````

- [ ] **Step 4: Edit orchestrate `SKILL.md`.**

Line 18: `It needs \`repo\`, \`worktree.create\`, \`worktree.remove\`, \`branch.issue\` in` → `It needs \`repo\`, \`worktree.create\`, \`branch.issue\` in` (spec *Assumptions* 18; the rest of the sentence stays).

Replace step 6 (the line starting `6. **After a merge**`) with:

```
6. **After a merge** (commands §Teardown): with no pending notice of yours for the run, run `teardown.py` and **tear down without asking**, even under an ask-first brief and ahead of `slots`' confirm step: it marks the page `merged`, checks (clean, HEAD equals the merged `headRefOid`, nothing owns the worktree) and removes only when every check holds. In a batch on a base, close the merged run's issue (commands §Teardown). Then re-map and start what the merge unblocked. A merged PR leaves the `needs input:` line; with no merge watch armed, reports are plain status again. A check fails: report the script's output, no question; a later report repeats the slot still standing. A PR **closed without merge**: the script marks its page `closed` and removes nothing; it is never satisfied: ask about it and everything waiting on it.
```

- [ ] **Step 5: Edit pipeline `references/engine.md`.**

Line 313, the row: replace

```
| `Worktree` | `remove` | the teardown after the merge (§After the merge), `orchestrate` | required to tear down |
```

with

```
| `Worktree` | `remove` | nothing: the teardown recognises the checkout's kind instead (`orchestrate/teardown.py`, §After the merge); accepted so a shared config parses | — |
```

§After the merge, replace steps 1–3 (from `1. **Arm the watch**` through `line; the owner decides.`) with:

````
1. **Arm the watch** as the run's report goes out (ready PR or halted-after-`handoff`): one background
   Bash per PR, exactly `orchestrate`'s §Watch "awaiting merge" loop with its `timeout: 7200000`
   (`../../orchestrate/references/commands.md`), armed again when it ends without its `PR #<P>` line.
   It polls `gh` every 5 minutes in a shell, so it costs no tokens while it waits; the session wakes
   once, on the change.
2. **On `MERGED`**, a session sitting inside the worktree leaves it first (`ExitWorktree` with `keep`),
   then, from the primary checkout:
   ```bash
   python3 ~/.claude/skills/orchestrate/teardown.py <worktree> <P> --repo <repo>
   ```
   It marks `artifacts.proof` `merged`, prints `orchestrate`'s checks (clean, `HEAD` equals the merged
   `headRefOid`, the PR's branch, no owner) and removes the slot or worktree and its branch only when
   all hold: **remove without asking**, ahead of `slots`' confirm step. A check fails: it removes
   nothing; report its last line, no question.
3. **Closed without merge**: the same call marks the page `closed`, removes nothing and exits 1. Say so
   in one line; the owner decides.
````

`**The watch dies with the session.**` and the rest of the section stay.

Line 935, the status row: replace

```
| `merged`, `closed` | the session holding the merge watch (§After the merge, `orchestrate` step 6) | the watch prints `MERGED` or `CLOSED`, before any teardown |
```

with

```
| `merged`, `closed` | `orchestrate/teardown.py`, run by the session holding the merge watch (§After the merge, `orchestrate` step 6) | the watch prints `MERGED` or `CLOSED`, before any teardown |
```

- [ ] **Step 6: Edit `skills/slots/SKILL.md`.** Replace the paragraph starting `**Exception — your own slot after its PR merged.**` (lines 67–70) with:

```
**Exception — your own slot after its PR merged.** A slot a `/pipeline` or `orchestrate` run
created, or one a session opened its PR from (`CLAUDE.md` §Git Workflow), is removed by that session
once the PR is `MERGED`, with no confirm step and with its local branch, by
`orchestrate/teardown.py`, which removes it only when its checks pass (pipeline
`references/engine.md` §After the merge). The owner does not clean up after a run.
```

- [ ] **Step 7: Run the tests to verify they pass.**

Run: `bash skills/orchestrate/tests/teardown_test.sh && (test -d vendor || composer install --quiet) && ./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "LockStep"`
Expected: `PASS teardown.py`, then Pest's `LockStepTest` all passing (it pins `| \`Worktree\` | \`remove\` |` and that engine.md has no `` `work-on`'s ``).

- [ ] **Step 8: Commit.**

```bash
git add skills/orchestrate/references/commands.md skills/orchestrate/SKILL.md skills/pipeline/references/engine.md skills/slots/SKILL.md skills/orchestrate/tests/teardown_test.sh
git commit -m "docs(orchestrate,pipeline,slots): the teardown after a merge is teardown.py; watches re-arm at the tool's limit (#168)"
```

---

### Task 4: `CLAUDE.md`: watch the PR you open

**Files:**
- Modify: `CLAUDE.md` (§Git Workflow)
- Test: `skills/orchestrate/tests/teardown_test.sh` (doc pins)

**Interfaces:**
- Consumes: Task 2's command line; Task 3's `has`/`lacks` helpers and `$REPO` in the test.
- Produces: the global rule every plain session follows.

- [ ] **Step 1: Write the failing pins.** In `skills/orchestrate/tests/teardown_test.sh`, insert before `echo "PASS teardown.py"` (after Task 3's pins):

```bash
has CLAUDE.md '**Watch the PR you open.**'
has CLAUDE.md '`timeout: 7200000`'
has CLAUDE.md '[ "$s" != OPEN ]; do sleep 60; done; echo "PR #<P> $s"'
has CLAUDE.md 'python3 ~/.claude/skills/orchestrate/teardown.py <checkout> <P> --repo <repo>'
has CLAUDE.md '`cd <primary checkout> && python3 '
has CLAUDE.md 'a pipeline step or a subagent arms none'
has CLAUDE.md "the teardown's \`git pull --ff-only\`"
```

- [ ] **Step 2: Run it to verify it fails.**

Run: `bash skills/orchestrate/tests/teardown_test.sh`
Expected: exit 1, `FAIL teardown.py (docs): CLAUDE.md does not say: **Watch the PR you open.**`.

- [ ] **Step 3: Edit `CLAUDE.md`.** After the bullet `- **Never commit directly to main**. Always create a feature branch and open a pull request when the work is done.`, insert:

```
- **Watch the PR you open.** Right after `gh pr create`, arm one background Bash (`run_in_background: true`,
  `timeout: 7200000`):
  `until s=$(gh pr view <P> -R <repo> --json state --jq .state 2>/dev/null) && [ "$s" != OPEN ]; do sleep 60; done; echo "PR #<P> $s"`.
  It ends without that line at its time limit: arm it again. When it prints the state, run the teardown from the
  primary checkout, leaving the worktree first if you entered it (`ExitWorktree`, `keep`):
  `cd <primary checkout> && python3 ~/.claude/skills/orchestrate/teardown.py <checkout> <P> --repo <repo>`;
  report its last line, without asking first: after a merge it removes the worktree, slot or feature branch
  only when every check holds, and otherwise removes nothing and says why. Its output is a report, not a question.
  One watch per PR: a `/pipeline` or `/orchestrate` session arms its own, and a pipeline step or a subagent arms none.
```

In the stale-checkout exception, replace `There the brief answers the hook's warning. Every other checkout keeps raise-and-wait.` with:

```
  There the brief answers the hook's warning. Every other checkout keeps raise-and-wait;
  the teardown's `git pull --ff-only` of the base after a merge (*Watch the PR you open*) updates the
  base, not a working branch, so it needs none.
```

(Keep the two-space indent of the surrounding paragraph.)

- [ ] **Step 4: Run every test to verify they pass.**

Run: `bash skills/orchestrate/tests/teardown_test.sh && bash skills/orchestrate/tests/owners_test.sh && bash skills/orchestrate/tests/needs_input_test.sh`
Expected: `PASS teardown.py`, `PASS owners.py`, `PASS needs_input.py`.

- [ ] **Step 5: Commit.**

```bash
git add CLAUDE.md skills/orchestrate/tests/teardown_test.sh
git commit -m "docs(claude-md): a session watches the PR it opens and tears down after the merge (#168)"
```
