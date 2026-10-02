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
