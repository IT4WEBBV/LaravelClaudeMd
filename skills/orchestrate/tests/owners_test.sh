#!/usr/bin/env bash
# Fixture test for owners.py: only working or blocked sessions other than the caller, whose
# transcript (main or subagents/) has cwd entries inside the worktree, own it. A live session whose
# transcript cannot be read as one fails closed: exit non-zero, never "no owner".
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
WT="$TMP/Shop/Shop-4"
P="$TMP/projects"
mkdir -p "$P/a" "$P/b/eee/subagents"

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

# A live session with no transcript at all (the layout moved): fail closed.
status=0
out="$(printf '%s' '[{"id":"jjj","name":"live, no transcript","state":"working","sessionId":"jjj"}]' \
  | python3 "$HERE/../owners.py" "$WT" --projects-dir "$P" --session-id ccc 2>"$TMP/err")" || status=$?
[ "$status" -ne 0 ] || fail "a live session without a transcript came back as no owner"
[ -z "$out" ] || fail "printed owners before failing: $out"
grep -q "jjj" "$TMP/err" || fail "the error does not name the session: $(cat "$TMP/err")"

# A live session whose transcript has no cwd entries (the entry format moved): fail closed.
printf '{"type":"assistant","message":"no cwd here"}\n' > "$P/b/lll.jsonl"
status=0
printf '%s' '[{"id":"lll","name":"live, no cwd","state":"blocked","sessionId":"lll"}]' \
  | python3 "$HERE/../owners.py" "$WT" --projects-dir "$P" --session-id ccc >/dev/null 2>"$TMP/err" || status=$?
[ "$status" -ne 0 ] || fail "a live session with no cwd entries came back as no owner"
grep -q "lll" "$TMP/err" || fail "the error does not name the session: $(cat "$TMP/err")"

echo "PASS owners.py"
