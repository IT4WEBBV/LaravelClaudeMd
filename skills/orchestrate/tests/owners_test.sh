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
mkdir -p "$P/a" "$P/b/eee/subagents" "$TMP/Shop" "$TMP/Other" "$TMP/Plain" "$TMP/NoRepo" "$TMP/Shop/Shop-40"
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
skipped() { # <session id> <cwd> [worktree, default $WT]
  local status=0 out
  out="$(printf '[{"id":"%s","name":"unreadable","state":"working","sessionId":"%s","cwd":"%s"}]' "$1" "$1" "$2" \
    | python3 "$HERE/../owners.py" "${3:-$WT}" --projects-dir "$P" --session-id ccc 2>"$TMP/err")" || status=$?
  [ "$status" -eq 0 ] || fail "unreadable session $1 in $2 exited $status, expected 0: $(cat "$TMP/err")"
  [ -z "$out" ] || fail "unreadable session $1 in $2 printed: $out"
  [ ! -s "$TMP/err" ] || fail "unreadable session $1 in $2 wrote to stderr: $(cat "$TMP/err")"
}
skipped u-other   "$TMP/Other/Other"      # another git repository
skipped u-plain   "$TMP/Plain"            # a directory outside any repository
skipped u-prefix  "$TMP/Shop/Shop-40"     # prefix trap: Shop-40 is not Shop-4
skipped u-gone    "$TMP/Gone/Gone"        # a directory that no longer exists
skipped u-plain   "$TMP/Plain" "$TMP/NoRepo"   # a worktree outside any repository: no None == None match

# A skipped unreadable session does not hide a real owner.
actual="$(printf '[{"id":"aaa","name":"run a","state":"working","sessionId":"aaa"},{"id":"u-other","name":"unreadable","state":"working","sessionId":"u-other","cwd":"%s"}]' "$TMP/Other/Other" \
  | python3 "$HERE/../owners.py" "$WT" --projects-dir "$P" --session-id ccc)" || fail "an owner plus an unrelated unreadable session did not exit 0"
[ "$actual" = "$(printf 'run a\taaa\tworking\t1')" ] || fail "an unrelated unreadable session hid the owner: $actual"

echo "PASS owners.py"
