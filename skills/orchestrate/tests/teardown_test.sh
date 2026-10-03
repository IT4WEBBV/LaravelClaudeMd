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
ENGINE=skills/pipeline/references/session.md
has   $ENGINE 'python3 ~/.claude/skills/orchestrate/teardown.py <worktree> <P> --repo <repo>'
has   $ENGINE 'timeout: 7200000'
has   $ENGINE '| `Worktree` | `remove` | nothing:'
lacks $ENGINE 'checks and removal as written there'
lacks $ENGINE 'A check fails: ask'
has   skills/slots/SKILL.md 'orchestrate/teardown.py'
has   skills/slots/SKILL.md 'opened its PR from'
lacks skills/slots/SKILL.md 'commands.md` §Teardown pass'

has CLAUDE.md '**Watch the PR you open.**'
has CLAUDE.md '`timeout: 7200000`'
has CLAUDE.md '[ "$s" != OPEN ]; do sleep 60; done; echo "PR #<P> $s"'
has CLAUDE.md 'python3 ~/.claude/skills/orchestrate/teardown.py <checkout> <P> --repo <repo>'
has CLAUDE.md '`cd <primary checkout> && python3 '
has CLAUDE.md 'a pipeline step or a subagent arms none'
has CLAUDE.md "the teardown's \`git pull --ff-only\`"

echo "PASS teardown.py"
