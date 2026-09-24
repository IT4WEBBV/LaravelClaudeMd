# `owners.py` unreadable-session scope Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `skills/orchestrate/owners.py` fails closed (exit 2) only on unreadable live sessions whose own `cwd` could reach the worktree, and skips the rest (#65).

**Architecture:** The unreadable branch in `main()` asks one new predicate, `related(cwd, worktree)`, built from four one-rule functions: `inside()` (exists), `above()`, `same_repository()` over a cached `repository()` git lookup, and a missing `cwd` counting as related. Readable sessions are judged exactly as today. The fixture test grows a real git worktree so the same-repository rule has something to find.

**Tech Stack:** Python 3 stdlib (`subprocess`, `functools` join the imports); bash fixture test; `git` ≥ 2.31 (`--path-format=absolute`; the host has 2.33.0).

**Spec:** `docs/superpowers/specs/2026-09-24-orchestrate-owners-unreadable-scope-design.md`. Read it first. Its *Assumptions* are settled for this plan.

## Global Constraints

- **Only these paths change** (besides the spec and this plan):
  - `skills/orchestrate/owners.py`
  - `skills/orchestrate/tests/owners_test.sh`
  - `skills/orchestrate/references/commands.md` (§Owner of in-flight work, one sentence)
- **Out of scope, left untouched:** the transcript glob and `subagents/workflows/` (#68), the interim `autoflow` guard paragraph in `commands.md` §Owner (#68), `commands.md` §Teardown, `SKILL.md`.
- **Output contract unchanged:** owners on stdout as `name\tid\tstate\tcount`, exit 0; exit 2 with the blocking sessions on stderr and nothing on stdout. A skipped unreadable session writes nothing to stdout or stderr.
- **Stdlib only.** No new file, no new dependency.
- **No changelog:** the repo has no `.changelog/` directory and no `CHANGELOG.md`.
- **Git inside this worktree:** run every command from `cd <worktree>` or with `git -C <worktree>`. Stage explicit paths only, never `git add -A`. Conventional messages (`fix(orchestrate): …`, `docs(orchestrate): …`), no Co-Authored-By, no AI attribution.
- **Written output addresses no person** (code comments, commits, PR body).

## Review Focus

1. **A `cwd` with a trailing slash** (`/Users/x/`): must still count as *above* its worktree. `above()` strips the trailing slash before appending `/`; `u-root` (`/`) pins the extreme case of the same code path.
2. **A worktree that is not a git repository** (a deleted or never-created directory): `repository(worktree)` is `None`, so `same_repository()` must be `False` rather than `None == None` → `True`. The `u-gone` and `u-plain` skip cases run against a real worktree; the `None` guard is pinned by the Task 1 Step 6 probe.
3. **Prefix trap for *above***: `Shop/Shop-4` must not be counted as above `Shop/Shop-40/...`, nor `Shop/Shop-40` as related to `Shop/Shop-4`. `u-prefix` pins it.
4. **A readable session that also lies outside the worktree** must not start blocking because of the new code: the existing `fff` (neighbour) row stays a non-owner in the first assertion, and the mixed case pins that an unrelated unreadable row neither hides an owner nor fails the run.
5. **macOS `/var` vs `/private/var`**: the same-repository rule compares `git`'s own output for both sides, which resolves symlinks identically. `u-primary` runs under `mktemp -d` (a `/var/folders/...` path) and pins it.

---

### Task 1: Narrow the fail-closed rule in `owners.py`, test-first

**Files:**
- Modify: `skills/orchestrate/tests/owners_test.sh` (whole file shown below)
- Modify: `skills/orchestrate/owners.py` (docstring *Fails closed* paragraph, imports, four new functions after `inside()`, the unreadable branch in `main()`)

**Interfaces:**
- Consumes: `inside(cwd, worktree) -> bool` (exists).
- Produces: `above(cwd: str, worktree: str) -> bool`; `repository(path: str) -> str | None` (cached); `same_repository(cwd: str, worktree: str) -> bool`; `related(cwd: str | None, worktree: str) -> bool`. `worktree` is always `os.path.abspath(...).rstrip("/")`, as `main()` already computes it.

- [ ] **Step 1: Confirm the starting state**

```bash
bash skills/orchestrate/tests/owners_test.sh
```
Expected: `PASS owners.py`.

- [ ] **Step 2: Write the failing test**

Replace `skills/orchestrate/tests/owners_test.sh` with:

```bash
#!/usr/bin/env bash
# Fixture test for owners.py: only working or blocked sessions other than the caller, whose
# transcript (main or subagents/) has cwd entries inside the worktree, own it. A live session whose
# transcript cannot be read fails closed (exit 2, never "no owner") when its own cwd is inside the
# worktree, contains it, lies in the same git repository, or is missing; rooted anywhere else it is
# skipped.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
WT="$TMP/Shop/Shop-4"
P="$TMP/projects"
mkdir -p "$P/a" "$P/b/eee/subagents" "$TMP/Shop" "$TMP/Other" "$TMP/Plain" "$TMP/Shop/Shop-40"
git init -q "$TMP/Shop/Shop"
git -C "$TMP/Shop/Shop" -c user.name=t -c user.email=t@t commit -q --allow-empty -m init
git -C "$TMP/Shop/Shop" worktree add -q "$WT" -b slot-4
git init -q "$TMP/Other/Other"

entry() { printf '{"type":"assistant","cwd":"%s"}\n' "$1"; }
entry "$WT"                > "$P/a/aaa.jsonl"                 # live, works in the worktree   -> owner
entry "$WT"                > "$P/a/bbb.jsonl"                 # done                          -> skipped
entry "$WT"                > "$P/a/ccc.jsonl"                 # the caller itself             -> skipped
printf '{"type":"user","cwd":"%s","message":"see %s"}\n' "$TMP/Shop/Shop" "$WT" > "$P/b/ddd.jsonl"   # only mentions the path -> not an owner
entry "$TMP/Shop/Shop"     > "$P/b/eee.jsonl"
entry "$WT/code/www"       > "$P/b/eee/subagents/agent-1.jsonl"   # live, a subagent works inside -> owner
entry "$TMP/Shop/Shop-40"  > "$P/b/fff.jsonl"                 # prefix trap: Shop-40 is not Shop-4
entry "$WT"                > "$P/a/ggg.jsonl"                 # done, works in the worktree   -> skipped
entry "$WT"                > "$P/a/hhh.jsonl"                 # failed, works in the worktree -> skipped
entry "$WT"                > "$P/a/iii.jsonl"                 # stopped, works in the worktree -> skipped

AGENTS='[
 {"id":"aaa","name":"run a","state":"working","sessionId":"aaa"},
 {"id":"bbb","name":"old run","state":"done","sessionId":"bbb"},
 {"id":"ccc","name":"me","state":"working","sessionId":"ccc"},
 {"id":"ddd","name":"mentions","state":"working","sessionId":"ddd"},
 {"id":"eee","name":"run e","state":"blocked","sessionId":"eee"},
 {"id":"fff","name":"neighbour","state":"working","sessionId":"fff"},
 {"id":"ggg","name":"finished run","state":"done","sessionId":"ggg"},
 {"id":"hhh","name":"crashed run","state":"failed","sessionId":"hhh"},
 {"id":"iii","name":"stopped run","state":"stopped","sessionId":"iii"},
 {"id":"kkk","name":"old run, transcript gone","state":"done","sessionId":"kkk"}
]'

fail() { printf 'FAIL owners.py: %s\n' "$1"; exit 1; }

actual="$(printf '%s' "$AGENTS" | python3 "$HERE/../owners.py" "$WT" --projects-dir "$P" --session-id ccc | sort)"
expected="$(printf 'run a\taaa\tworking\t1\nrun e\teee\tblocked\t1\n' | sort)"
[ "$actual" = "$expected" ] || fail "$(printf 'owners\n--- expected\n%s\n--- actual\n%s' "$expected" "$actual")"

# A live session with no transcript at all (the layout moved), rooted inside the worktree: fail closed.
status=0
out="$(printf '%s' '[{"id":"jjj","name":"live, no transcript","state":"working","sessionId":"jjj","cwd":"'"$WT"'"}]' \
  | python3 "$HERE/../owners.py" "$WT" --projects-dir "$P" --session-id ccc 2>"$TMP/err")" || status=$?
[ "$status" -ne 0 ] || fail "a live session without a transcript came back as no owner"
[ -z "$out" ] || fail "printed owners before failing: $out"
grep -q "jjj" "$TMP/err" || fail "the error does not name the session: $(cat "$TMP/err")"

# A live session whose transcript has no cwd entries (the entry format moved), rooted inside: fail closed.
printf '{"type":"assistant","message":"no cwd here"}\n' > "$P/b/lll.jsonl"
status=0
printf '%s' '[{"id":"lll","name":"live, no cwd","state":"blocked","sessionId":"lll","cwd":"'"$WT"'"}]' \
  | python3 "$HERE/../owners.py" "$WT" --projects-dir "$P" --session-id ccc >/dev/null 2>"$TMP/err" || status=$?
[ "$status" -ne 0 ] || fail "a live session with no cwd entries came back as no owner"
grep -q "lll" "$TMP/err" || fail "the error does not name the session: $(cat "$TMP/err")"

# An unreadable live session whose own cwd could reach the worktree fails closed.
blocks() { # <session id> <row fields, after sessionId>
  local status=0 out
  out="$(printf '[{"id":"%s","name":"unreadable","state":"working","sessionId":"%s"%s}]' "$1" "$1" "$2" \
    | python3 "$HERE/../owners.py" "$WT" --projects-dir "$P" --session-id ccc 2>"$TMP/err")" || status=$?
  [ "$status" -eq 2 ] || fail "unreadable session $1 ($2) exited $status, expected 2"
  [ -z "$out" ] || fail "unreadable session $1 printed owners before failing: $out"
  grep -q "$1" "$TMP/err" || fail "the error does not name $1: $(cat "$TMP/err")"
}
blocks u-inside   ",\"cwd\":\"$WT/code/www\""        # inside the worktree
blocks u-above    ",\"cwd\":\"$TMP/\""               # a directory that contains it, like ~ (trailing slash)
blocks u-root     ",\"cwd\":\"/\""                   # / contains every worktree
blocks u-primary  ",\"cwd\":\"$TMP/Shop/Shop\""      # the slot's primary checkout: a sibling, same repo
blocks u-no-cwd   ""                                  # no cwd in the row: cannot rule it out

# An unreadable live session rooted anywhere else is skipped: no owner, exit 0, silent.
skipped() { # <session id> <cwd>
  local status=0 out
  out="$(printf '[{"id":"%s","name":"unreadable","state":"working","sessionId":"%s","cwd":"%s"}]' "$1" "$1" "$2" \
    | python3 "$HERE/../owners.py" "$WT" --projects-dir "$P" --session-id ccc 2>"$TMP/err")" || status=$?
  [ "$status" -eq 0 ] || fail "unreadable session $1 in $2 exited $status, expected 0: $(cat "$TMP/err")"
  [ -z "$out" ] || fail "unreadable session $1 in $2 printed: $out"
  [ ! -s "$TMP/err" ] || fail "unreadable session $1 in $2 wrote to stderr: $(cat "$TMP/err")"
}
skipped u-other   "$TMP/Other/Other"      # another git repository
skipped u-plain   "$TMP/Plain"            # a directory outside any repository
skipped u-prefix  "$TMP/Shop/Shop-40"     # prefix trap: Shop-40 is not Shop-4
skipped u-gone    "$TMP/Gone/Gone"        # a directory that no longer exists

# A skipped unreadable session does not hide a real owner.
actual="$(printf '[{"id":"aaa","name":"run a","state":"working","sessionId":"aaa"},{"id":"u-other","name":"unreadable","state":"working","sessionId":"u-other","cwd":"%s"}]' "$TMP/Other/Other" \
  | python3 "$HERE/../owners.py" "$WT" --projects-dir "$P" --session-id ccc)" || fail "an owner plus an unrelated unreadable session did not exit 0"
[ "$actual" = "$(printf 'run a\taaa\tworking\t1')" ] || fail "an unrelated unreadable session hid the owner: $actual"

echo "PASS owners.py"
```

- [ ] **Step 3: Run the test to verify it fails**

```bash
bash skills/orchestrate/tests/owners_test.sh
```
Expected: every `blocks` case passes (today's code fails closed on everything), then
`FAIL owners.py: unreadable session u-other in …/Other/Other exited 2, expected 0: owners.py: cannot tell whether these live sessions own the worktree, …`.
Any other failure means the fixture is wrong: fix the fixture, not the code.

- [ ] **Step 4: Write the implementation**

In `skills/orchestrate/owners.py`, replace the docstring's *Fails closed* paragraph with:

```
Fails closed: when a live session has no transcript, or its transcripts hold no cwd entries at all,
the lookup cannot tell whether that session owns the worktree (the transcript layout or format has
changed, or the session has not written its first entry yet). When that session's own cwd, from its
`claude agents` row, is inside the worktree, contains it (the primary checkout, ~), lies in the same
git repository (a slot's primary checkout), or is missing, it prints nothing, names the sessions on
stderr and exits 2. That is never "orphaned". An unreadable session rooted anywhere else has no path
to the worktree and is skipped.
```

Make the imports:

```python
import argparse
import functools
import glob
import json
import os
import subprocess
import sys
```

Add, directly after `inside()`:

```python
def above(cwd, worktree):
    return worktree.startswith(cwd.rstrip("/") + "/")


@functools.lru_cache(maxsize=None)
def repository(path):
    result = subprocess.run(
        ["git", "-C", path, "rev-parse", "--path-format=absolute", "--git-common-dir"],
        capture_output=True,
        text=True,
    )
    return result.stdout.strip() if result.returncode == 0 else None


def related(cwd, worktree):
    if not cwd:
        return True  # no cwd in the row: nothing rules the session out
    cwd = os.path.abspath(cwd)
    return inside(cwd, worktree) or above(cwd, worktree) or same_repository(cwd, worktree)


def same_repository(cwd, worktree):
    return repository(worktree) is not None and repository(cwd) == repository(worktree)
```

In `main()`, replace

```python
        if not cwds:
            unreadable.append(label)
            continue
```

with

```python
        if not cwds:
            if related(session.get("cwd"), worktree):
                unreadable.append(label)
            continue
```

Nothing else in the file changes.

- [ ] **Step 5: Run the test to verify it passes**

```bash
bash skills/orchestrate/tests/owners_test.sh
```
Expected: `PASS owners.py`.

- [ ] **Step 6: Pin Review Focus 2 and probe the live machine**

A worktree path that is not a repository must never match through `None == None`:

```bash
printf '[{"id":"u","name":"x","state":"working","sessionId":"u","cwd":"/tmp/not-a-repo-65"}]' | python3 skills/orchestrate/owners.py /tmp/no-such-worktree-65 --projects-dir /tmp/no-such-projects-65; echo "exit $?"
```
Expected: no output, `exit 0`.

The real session list against this worktree:

```bash
claude agents --json --all | python3 skills/orchestrate/owners.py "$(pwd)"; echo "exit $?"
```
Expected: exit 0 or 2. On exit 2, every session named on stderr has a `cwd` (in `claude agents --json --all`) inside, above, or in the same repository as this worktree. This is a sanity check only; nothing is recorded.

- [ ] **Step 7: Commit**

```bash
git add skills/orchestrate/owners.py skills/orchestrate/tests/owners_test.sh
git commit -m "fix(orchestrate): owners.py fails closed only on unreadable sessions that could own the worktree (#65)"
```

---

### Task 2: Say which unreadable sessions block, then the regression run

**Files:**
- Modify: `skills/orchestrate/references/commands.md` (§Owner of in-flight work, the *non-zero exit* sentence, lines 47-48)

**Interfaces:**
- Consumes: the behaviour from Task 1.
- Produces: nothing code depends on.

- [ ] **Step 1: Rewrite the sentence**

In `skills/orchestrate/references/commands.md` §Owner of in-flight work, replace

```markdown
  orphaned. **A non-zero exit is never orphaned**: the lookup could not read a live session's
  transcript, so treat the worktree as owned and ask the owner, quoting the error.
```

with

```markdown
  orphaned. **A non-zero exit is never orphaned**: the lookup could not read the transcript of a live
  session rooted in, above, or in the same repository as the worktree, so treat the worktree as owned
  and ask the owner, quoting the error. An unreadable session rooted anywhere else is skipped.
```

Leave the rest of §Owner (including the interim `autoflow` guard paragraph) and §Teardown as they are.

- [ ] **Step 2: Check the diff touches only the planned paths**

```bash
git diff --stat origin/main -- skills
```
Expected: exactly `skills/orchestrate/owners.py`, `skills/orchestrate/references/commands.md`, `skills/orchestrate/tests/owners_test.sh`.

- [ ] **Step 3: Run every suite the run reports on**

```bash
bash skills/orchestrate/tests/owners_test.sh
```
Expected: `PASS owners.py`.

The worktree has no `vendor/`; install it once (host PHP, this repo is not dockerised):

```bash
test -d vendor || composer install --no-interaction --quiet
```
```bash
./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests
```
Expected: all tests pass (this change touches no PHP; a failure here is pre-existing and is reported, not fixed in this PR).

- [ ] **Step 4: Commit**

```bash
git add skills/orchestrate/references/commands.md
git commit -m "docs(orchestrate): say which unreadable sessions make owners.py fail closed (#65)"
```
