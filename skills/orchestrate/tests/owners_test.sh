#!/usr/bin/env bash
# Fixture test for owners.py: only working or blocked sessions other than the caller own a worktree,
# when one of their transcripts (main, subagents/ or subagents/workflows/wf_*/) has an entry whose cwd
# lies inside it, or that names it through the pipeline: a dispatch_cli.php call on the run's
# manifest, a kickoff or launch answer, or the Workflow call. A live session with a transcript that
# cannot be read fails closed (exit 2, never "no owner") when its own cwd is inside the worktree,
# contains it, lies in the same git repository, or is missing; rooted anywhere else it is skipped.
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

# autoflow: every entry carries the launch directory (the primary checkout) as cwd.
PRIMARY="$TMP/Shop/Shop"
START='{"action":"start","startLeg":"design","manifest":"'"$WT"'/.claude/pipeline/b.json","worktree":"'"$WT"'"}'
prompt() { printf '{"type":"user","cwd":"%s","message":{"role":"user","content":"%s"}}\n' "$PRIMARY" "$1"; }
bash_call() { printf '{"type":"assistant","cwd":"%s","message":{"role":"assistant","content":[{"type":"tool_use","id":"t1","name":"Bash","input":{"command":"%s"}}]}}\n' "$PRIMARY" "$1"; }
result() { printf '{"type":"user","cwd":"%s","message":{"role":"user","content":[{"type":"tool_result","tool_use_id":"t1","content":%s}]}}\n' "$PRIMARY" "$1"; }
json() { python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$1"; }
BRIEF="php /x/checks/dispatch_cli.php brief $WT/.claude/pipeline/feature/issue-4-x.json design run"
workflow() { mkdir -p "$P/c/$1/subagents/workflows/$2"; printf '%s/subagents/workflows/%s/%s' "$P/c/$1" "$2" "$3"; }
{ prompt "Run $BRIEF"; bash_call "$BRIEF"; } > "$(workflow wf-step wf_1-abc agent-s1.jsonl)"   # a step's brief call -> owner
printf '{"type":"launched"}\n' > "$P/c/wf-step/subagents/workflows/wf_1-abc/journal.jsonl"          # the workflow's journal: no cwd, not a transcript
bash_call "cd $PRIMARY; php /x/checks/dispatch_cli.php launch \\\"$WT/.claude/pipeline/b.json\\\" \\\"$WT/.claude/pipeline/b.diff\\\"" \
  > "$(workflow wf-quoted wf_6-abc agent-s7.jsonl)"                                              # a quoted manifest, after a cd -> owner
bash_call "php /x/checks/dispatch_cli.php brief $TMP/Shop/Shop-40/.claude/pipeline/b.json design run" \
  > "$(workflow wf-prefix wf_2-abc agent-s2.jsonl)"                                              # prefix trap -> not an owner
{ entry "$PRIMARY"; result "[{\"type\":\"text\",\"text\":$(json '{"action":"ready","manifest":"'"$WT"'/.claude/pipeline/b.json","worktree":"'"$WT"'","branch":"b","notes":[]}')}]"; } \
  > "$P/c/kick.jsonl"                                                                             # kickoff's answer -> owner
{ entry "$PRIMARY"; result "$(json "--- b
$START")"; } > "$P/c/launch.jsonl"                                                               # launch's answer -> owner
{ entry "$PRIMARY"; printf '{"type":"assistant","cwd":"%s","message":{"role":"assistant","content":[{"type":"tool_use","id":"t2","name":"Workflow","input":{"name":"pipeline-autoflow","args":%s}}]}}\n' "$PRIMARY" "$START"; } \
  > "$P/c/wfcall.jsonl"                                                                           # the Workflow call -> owner
{ prompt "Run $BRIEF"; result "$(json '{"worktree":"'"$WT"'","mode":"autoflow"}')"; } > "$P/c/mention.jsonl"   # mentions only -> not an owner
bash_call "grep -c 'dispatch_cli.php brief $WT/.claude/pipeline/b.json' $P/c/wfcall.jsonl" > "$P/c/grep.jsonl"   # a command quoting the call -> owner (accepted: the safe side)

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
 {"id":"kkk","name":"old run, transcript gone","state":"done","sessionId":"kkk"},
 {"id":"wf-step","name":"workflow step","state":"working","sessionId":"wf-step"},
 {"id":"wf-quoted","name":"quoted launch","state":"working","sessionId":"wf-quoted"},
 {"id":"wf-prefix","name":"workflow on Shop-40","state":"working","sessionId":"wf-prefix"},
 {"id":"kick","name":"kicked off","state":"working","sessionId":"kick"},
 {"id":"launch","name":"launched","state":"blocked","sessionId":"launch"},
 {"id":"wfcall","name":"started the workflow","state":"working","sessionId":"wfcall"},
 {"id":"mention","name":"mentions the brief","state":"working","sessionId":"mention"},
 {"id":"grep","name":"greps for the brief","state":"working","sessionId":"grep"}
]'

fail() { printf 'FAIL owners.py: %s\n' "$1"; exit 1; }

actual="$(printf '%s' "$AGENTS" | python3 "$HERE/../owners.py" "$WT" --projects-dir "$P" --session-id ccc | sort)"
expected="$(printf 'run a\taaa\tworking\t1\nrun e\teee\tblocked\t1\nworkflow step\twf-step\tworking\t1\nkicked off\tkick\tworking\t1\nlaunched\tlaunch\tblocked\t1\nstarted the workflow\twfcall\tworking\t1\nquoted launch\twf-quoted\tworking\t1\ngreps for the brief\tgrep\tworking\t1\n' | sort)"
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

# Fail closed per transcript: a readable main transcript does not hide an unreadable workflow step.
printf '{"type":"assistant","message":"no cwd here"}\n' > "$(workflow half wf_3-abc agent-s3.jsonl)"
entry "$PRIMARY" > "$P/c/half.jsonl"
blocks half ",\"cwd\":\"$PRIMARY\""
printf '{"type":"assistant","message":"no cwd here"}\n' > "$(workflow half-far wf_5-abc agent-s5.jsonl)"
entry "$PRIMARY" > "$P/c/half-far.jsonl"
skipped half-far "$TMP/Other/Other"

# An owner stays an owner when another of its transcripts cannot be read.
bash_call "$BRIEF" > "$(workflow half-owner wf_4-abc agent-s4.jsonl)"
printf '{"type":"assistant","message":"no cwd here"}\n' > "$P/c/half-owner/subagents/workflows/wf_4-abc/agent-s6.jsonl"
actual="$(printf '[{"id":"half-owner","name":"owner","state":"working","sessionId":"half-owner","cwd":"%s"}]' "$PRIMARY" \
  | python3 "$HERE/../owners.py" "$WT" --projects-dir "$P" --session-id ccc)" || fail "an owner with an unreadable step transcript did not exit 0"
[ "$actual" = "$(printf 'owner\thalf-owner\tworking\t1')" ] || fail "an owner with an unreadable step transcript: $actual"

echo "PASS owners.py"
