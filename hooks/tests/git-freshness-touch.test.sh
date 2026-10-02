#!/usr/bin/env bash
#
# Tests for the touch mode of hooks/git-freshness.sh: the check that runs on the
# first tool call a session makes in a repo.
#
#   bash hooks/tests/git-freshness-touch.test.sh
#
# Every case builds a throwaway repo (a bare "origin" plus a checkout) under
# $TMPDIR and runs the hook the way Claude Code does: as a subprocess, fed a
# JSON payload on stdin. Each case uses its own session_id, and TMPDIR points
# into $root, so the hook's per-session markers are thrown away with the rest.
#
# The fixture() guard aborts unless the path it was handed is a directory inside
# $root: a test that mutates real repositories when it breaks is worse than no
# test (see git-freshness-sync.test.sh, where that happened).

set -uo pipefail

here=$(cd "$(dirname "$0")" && pwd)
hook="$here/../git-freshness.sh"

[ -f "$hook" ] || { echo "cannot find $hook"; exit 1; }

# Resolved with `pwd -P`: on macOS $TMPDIR is /var/folders/... while git reports
# the real /private/var/folders/..., which would make the $root containment
# guard and any path assertion compare two spellings of the same directory.
root=$(cd "$(mktemp -d "${TMPDIR:-/tmp}/git-freshness-touch-tests.XXXXXX")" && pwd -P)
trap 'rm -rf "$root"' EXIT

# The hook keeps its per-session markers under $TMPDIR: keep them in $root.
mkdir -p "$root/tmp"
export TMPDIR="$root/tmp"

# Isolate from the user's real git config.
export GIT_CONFIG_GLOBAL="$root/gitconfig"
export GIT_CONFIG_SYSTEM=/dev/null
export GIT_AUTHOR_NAME=test GIT_AUTHOR_EMAIL=test@example.com
export GIT_COMMITTER_NAME=test GIT_COMMITTER_EMAIL=test@example.com
git config --global init.defaultBranch main
git config --global user.name test
git config --global user.email test@example.com

# Session mode also syncs the config repos and links their skills. No case may
# reach the real ones.
export GIT_FRESHNESS_CONFIG_REPOS=""
export GIT_FRESHNESS_SKILLS_DIR="$root/no-skills-dir"
export GIT_FRESHNESS_WORKFLOWS_DIR="$root/no-workflows-dir"
export GIT_FRESHNESS_AGENTS_DIR="$root/no-agents-dir"
export GIT_FRESHNESS_APPS_DIR="$root/no-apps-dir"
export GIT_FRESHNESS_STATUSLINE="$root/no-statusline/statusline-command.sh"
export GIT_FRESHNESS_VAULT_HOME="$root/no-home"

passed=0
failed=0

ok()   { passed=$((passed + 1)); printf '  ok    %s\n' "$1"; }
fail() { failed=$((failed + 1)); printf '  FAIL  %s\n' "$1"; [ $# -gt 1 ] && printf '        %s\n' "$2"; return 0; }

die() { printf 'FATAL: %s\n' "$1"; exit 1; }

is() { # is <actual> <expected> <label>
    if [ "$1" = "$2" ]; then ok "$3"; else fail "$3" "expected '$2', got '$1'"; fi
}

contains() { # contains <haystack> <needle> <label>
    case "$1" in *"$2"*) ok "$3" ;; *) fail "$3" "expected to contain '$2', got: $1" ;; esac
}

lacks() { # lacks <haystack> <needle> <label>
    case "$1" in *"$2"*) fail "$3" "expected NOT to contain '$2', got: $1" ;; *) ok "$3" ;; esac
}

json_is_valid() {
    command -v python3 >/dev/null 2>&1 || return 0
    printf '%s' "$1" | python3 -c 'import json,sys; json.load(sys.stdin)' 2>/dev/null
}

one_json_line() { # one_json_line <output> <label>
    is "$(printf '%s' "$1" | grep -c '')" "1" "$2: exactly one line"
    if json_is_valid "$1"; then ok "$2: parses as JSON"; else fail "$2: parses as JSON" "$1"; fi
}

checksum() {
    [ -f "$1" ] || die "checksum: no such file '$1'"
    md5 -q "$1" 2>/dev/null || md5sum "$1" | awk '{print $1}'
}

file_mtime() {
    stat -f %m "$1" 2>/dev/null || stat -c %Y "$1" 2>/dev/null
}

# Make the checkout's last fetch look old, so the hook's next check fetches.
age_fetch_head() {
    touch -t 202001010000 "$(git -C "$1" rev-parse --absolute-git-dir)/FETCH_HEAD"
}

# Build a fixture: bare origin, a primary checkout on main ("work"), and $2
# commits pushed by "somebody else". If $3 is given, each upstream commit also
# touches that file. The checkout has fetched them: origin/main is ahead of main.
make_fixture() {
    (
        set -e
        local name=$1
        local upstream=${2:-2}
        local extra=${3:-}
        local dir="$root/$name"
        local i=0

        mkdir -p "$dir"
        git init -q --bare -b main "$dir/origin.git"
        git clone -q "$dir/origin.git" "$dir/work" 2>/dev/null
        echo base > "$dir/work/app.php"
        git -C "$dir/work" add app.php
        git -C "$dir/work" commit -qm "initial"
        git -C "$dir/work" push -q -u origin main

        git clone -q "$dir/origin.git" "$dir/other" 2>/dev/null
        while [ "$i" -lt "$upstream" ]; do
            i=$((i + 1))
            echo "upstream $i" >> "$dir/other/app.php"
            if [ -n "$extra" ]; then
                mkdir -p "$(dirname "$dir/other/$extra")"
                echo "changed $i" >> "$dir/other/$extra"
                git -C "$dir/other" add "$extra"
            fi
            git -C "$dir/other" commit -qam "upstream $i"
        done
        [ "$upstream" -gt 0 ] && git -C "$dir/other" push -q origin main

        git -C "$dir/work" fetch -q origin
        printf '%s' "$dir/work"
    )
}

fixture() {
    local path
    path=$(make_fixture "$@") || die "fixture '$1' failed to build"
    case "$path" in
        "$root"/*) ;;
        *) die "fixture path '$path' escaped \$root" ;;
    esac
    [ -d "$path" ] || die "fixture path '$path' is not a directory"
    printf '%s' "$path"
}

# A tool call's payload as Claude Code sends it on stdin.
tool_payload() { # tool_payload <session> <tool> <cwd> <tool_input json>
    printf '{"session_id":"%s","transcript_path":"%s/t.jsonl","cwd":"%s","hook_event_name":"PreToolUse","tool_name":"%s","tool_input":%s}' \
        "$1" "$root" "$3" "$2" "$4"
}

read_payload() { # read_payload <session> <file>
    tool_payload "$1" Read "$root" "$(printf '{"file_path":"%s"}' "$2")"
}

run_hook() { # run_hook <mode> <payload> → the hook's stdout
    printf '%s' "$2" | bash "$hook" "$1" 2>/dev/null
}

mkdir -p "$root/plain"

echo "git $(git --version | awk '{print $3}') — testing $(basename "$hook") touch"
echo

# ---------------------------------------------------------------------------
echo "case 1: an unknown mode does nothing"
cfg=$(fixture unknownmode 2)
out=$(printf '%s' "{\"session_id\":\"t-unknown\",\"cwd\":\"$cfg\"}" \
    | GIT_FRESHNESS_CONFIG_REPOS="$cfg" bash "$hook" nonsense 2>/dev/null)
is "$out" "" "prints nothing"
is "$(git -C "$cfg" rev-list --count main..origin/main)" "2" "no config sync ran: main still behind"
echo

# ---------------------------------------------------------------------------
echo "case 2: the first touch checks the repo, a repeat touch is silent"
repo=$(fixture firsttouch 0)
first=$(run_hook touch "$(read_payload t-first "$repo/app.php")")
second=$(run_hook touch "$(read_payload t-first "$repo/app.php")")
one_json_line "$first" "first touch"
contains "$first" '"hookEventName":"PreToolUse"' "first touch declares PreToolUse"
contains "$first" "nothing incoming that affects this work" "first touch reports the repo"
lacks "$first" "permissionDecision" "the permission flow is left alone"
is "$second" "" "second touch in the same repo prints nothing"
echo

# ---------------------------------------------------------------------------
echo "case 3: a first Read fast-forwards a clean main"
repo=$(fixture ffread 2)
out=$(run_hook touch "$(read_payload t-ffread "$repo/app.php")")
is "$(git -C "$repo" rev-list --count main..origin/main)" "0" "main equals origin/main"
is "$(tail -1 "$repo/app.php")" "upstream 2" "the file on disk holds the new content"
is "$(git -C "$repo" status --porcelain | wc -l | tr -d ' ')" "0" "working tree clean"
contains "$out" "fast-forwarded main" "the sync is reported"
echo

# ---------------------------------------------------------------------------
echo "case 4: a slot worktree is its own repo"
repo=$(fixture slotted 2)
git -C "$repo" worktree add -q "$root/slotted/slot" -b slot
primary=$(run_hook touch "$(read_payload t-slot "$repo/app.php")")
slot=$(run_hook touch "$(read_payload t-slot "$root/slotted/slot/app.php")")
contains "$primary" "fast-forwarded main" "the primary is checked: its clean main is fast-forwarded"
one_json_line "$slot" "slot touch"
contains "$slot" "on 'slot'" "the slot is reported under its own branch: the primary's marker does not silence it"
echo

# ---------------------------------------------------------------------------
echo "case 5: no fetch for a repo the session never touches"
touched=$(fixture fetched 0)
untouched=$(fixture notfetched 0)
age_fetch_head "$touched"
age_fetch_head "$untouched"
touched_head="$(git -C "$touched" rev-parse --absolute-git-dir)/FETCH_HEAD"
untouched_head="$(git -C "$untouched" rev-parse --absolute-git-dir)/FETCH_HEAD"
aged_touched=$(file_mtime "$touched_head")
aged_untouched=$(file_mtime "$untouched_head")
run_hook touch "$(read_payload t-fetch "$touched/app.php")" >/dev/null
if [ "$(file_mtime "$touched_head")" -gt "$aged_touched" ]; then ok "the touched repo was fetched"; else fail "the touched repo was fetched"; fi
is "$(file_mtime "$untouched_head")" "$aged_untouched" "the untouched repo was not fetched"
echo

# ---------------------------------------------------------------------------
echo "case 6: which paths a tool call names"
repo=$(fixture tools 0)
out=$(run_hook touch "$(tool_payload t-glob Glob "$root/plain" "$(printf '{"pattern":"*.php","path":"%s"}' "$repo")")")
contains "$out" "nothing incoming" "Glob checks its path"
out=$(run_hook touch "$(tool_payload t-grep Grep "$repo" '{"pattern":"base"}')")
contains "$out" "nothing incoming" "Grep without a path checks the cwd"
out=$(run_hook touch "$(tool_payload t-nb NotebookEdit "$root/plain" "$(printf '{"notebook_path":"%s/n.ipynb","new_source":"x"}' "$repo")")")
contains "$out" "nothing incoming" "NotebookEdit checks its notebook_path (a file that does not exist yet: its dir)"
out=$(run_hook touch "$(tool_payload t-write Write "$root/plain" "$(printf '{"file_path":"%s/new.php","content":"x = {\\"file_path\\":\\"%s/app.php\\"}"}' "$repo" "$root/plain")")")
contains "$out" "nothing incoming" "Write checks its file_path, not one quoted inside its content"
out=$(run_hook touch "$(tool_payload t-web WebFetch "$repo" '{"url":"https://example.com"}')")
is "$out" "" "a tool without a path prints nothing, even with a cwd in a repo"
out=$(run_hook touch "$(read_payload t-plain "$root/plain/nothing.txt")")
is "$out" "" "a path in no repo prints nothing"
git init -q "$root/noorigin"
echo x > "$root/noorigin/f.txt"
first=$(run_hook touch "$(read_payload t-noorigin "$root/noorigin/f.txt")")
is "$first" "" "a repo without an origin prints nothing"
is "$(ls "$TMPDIR/claude-git-freshness/t-noorigin" | grep -c .)" "1" "but its marker is claimed, so it is not retried"
echo

# ---------------------------------------------------------------------------
echo "case 7: touch, edit and session share the markers"
repo=$(fixture shared 0)
first=$(run_hook touch "$(read_payload t-shared "$repo/app.php")")
edit=$(run_hook edit "{\"session_id\":\"t-shared\",\"file_path\":\"$repo/app.php\"}")
contains "$first" "nothing incoming" "touch claims the repo"
is "$edit" "" "the legacy edit hook after touch is a no-op"
repo=$(fixture sessionclaim 0)
mkdir -p "$repo/sub"
sess=$(run_hook session "{\"session_id\":\"t-session\",\"cwd\":\"$repo/sub\"}")
touch_after=$(run_hook touch "$(read_payload t-session "$repo/app.php")")
contains "$sess" '"hookEventName":"SessionStart"' "session checks its launch repo"
is "$touch_after" "" "the first touch after a session launched in that repo (a subdirectory) is silent"
again=$(run_hook session "{\"session_id\":\"t-session\",\"cwd\":\"$repo\"}")
contains "$again" "nothing incoming" "a second SessionStart (resume, /clear) still checks"
# Every skill is a symlink into a config repo's primary checkout: a skill read
# must not check it again (spec assumption 22).
cfg=$(fixture configrepo 2)
git -C "$cfg" checkout -q -b feature
sess=$(printf '%s' "{\"session_id\":\"t-config\",\"cwd\":\"$root/plain\"}" \
    | GIT_FRESHNESS_CONFIG_REPOS="$cfg" bash "$hook" session 2>/dev/null)
skill_read=$(run_hook touch "$(read_payload t-config "$cfg/app.php")")
control=$(run_hook touch "$(read_payload t-config-control "$cfg/app.php")")
contains "$sess" "skills from 'feature'" "session flags the config repo on a branch"
is "$skill_read" "" "a read in a config repo after the session synced it is silent"
contains "$control" "work on 'feature'" "without the session's claim the same read reports the repo"
echo

# ---------------------------------------------------------------------------
echo "case 8: Bash calls check the directories their command names, else the cwd"
# Every fixture's checkout is called work, so each gets its own branch name for
# the assertions to tell the reports apart: "work on 'bashone'".
repo=$(fixture bashone 0)
two=$(fixture bashtwo 0)
git -C "$repo" checkout -q -b bashone
git -C "$two" checkout -q -b bashtwo
bash_payload() { # bash_payload <session> <cwd> <command, JSON-escaped>
    tool_payload "$1" Bash "$2" "$(printf '{"command":"%s","description":"x"}' "$3")"
}
out=$(run_hook touch "$(bash_payload t-bash1 "$root/plain" "git -C $repo status")")
contains "$out" "bashone" "git -C <repo> checks that repo from a cwd in no repo"
out=$(run_hook touch "$(bash_payload t-bash2 "$root/plain" "cd \\\"$repo\\\" && ls")")
contains "$out" "bashone" "cd \"<repo>\" (escaped quotes in the JSON) checks that repo"
out=$(run_hook touch "$(bash_payload t-bash3 "$repo" "ls -la")")
contains "$out" "bashone" "a command naming no directory checks the cwd"
out=$(run_hook touch "$(bash_payload t-bash4 "$root/plain" "ls -la")")
is "$out" "" "a command naming no directory, from a cwd in no repo, prints nothing"
out=$(run_hook touch "$(bash_payload t-bash5 "$repo" "cd \$WORKTREE && make")")
contains "$out" "bashone" "a variable falls back to the cwd"
out=$(run_hook touch "$(bash_payload t-bash5b "$repo" "cd \\\"$root/plain/a b\\\" && ls")")
contains "$out" "bashone" "a quoted path with a space (not resolved) falls back to the cwd"
out=$(printf '%s' "$(bash_payload t-bash6 "$root/plain" "ls\\n(cd ../plain; git -C ~/bashone/work log)")" \
    | HOME="$root" bash "$hook" touch 2>/dev/null)
contains "$out" "bashone" "a relative cd and a ~ path inside a subshell, on the line after an escaped newline"
out=$(run_hook touch "$(bash_payload t-bash7 "$root/plain" "git -C $repo status && git -C $two status")")
one_json_line "$out" "two repos in one command"
contains "$out" "bashone" "the first repo is checked"
contains "$out" "bashtwo" "the second repo is checked"
out=$(run_hook touch "$(bash_payload t-bash8 "$repo" "git -C $two status")")
contains "$out" "bashtwo" "the named repo is checked"
lacks "$out" "bashone" "the cwd repo is not checked when the command names a directory"
echo

# ---------------------------------------------------------------------------
echo "case 9: a behind feature branch is reported once"
repo=$(fixture behind 2)
git -C "$repo" checkout -q -b feature
echo mine > "$repo/local.php"
git -C "$repo" add local.php
git -C "$repo" commit -qm "local work"
first=$(run_hook touch "$(read_payload t-behind "$repo/local.php")")
second=$(run_hook touch "$(tool_payload t-behind Grep "$repo" '{"pattern":"x"}')")
one_json_line "$first" "behind branch"
contains "$first" "Stale checkout: work on 'feature' is 2 commit(s) behind origin/main." "the working-branch line"
contains "$first" "Raise this with the user" "the raise-and-wait instruction"
contains "$first" '"systemMessage":"work '"'"'feature'"'"': 2 behind origin/main.' "the on-screen line"
lacks "$first" "to merge by hand" "no conflict when no shared file moved"
is "$second" "" "a later touch stays silent"
echo

# ---------------------------------------------------------------------------
echo "case 10: a dirty main is left alone and still reported behind"
repo=$(fixture dirtymain 2)
echo "work in progress" >> "$repo/app.php"
before_sum=$(checksum "$repo/app.php")
before_main=$(git -C "$repo" rev-parse main)
out=$(run_hook touch "$(read_payload t-dirty "$repo/app.php")")
is "$(git -C "$repo" rev-parse main)" "$before_main" "main did not move"
is "$(checksum "$repo/app.php")" "$before_sum" "the uncommitted change is kept byte for byte"
contains "$out" "uncommitted" "the sync note says why main was left alone"
contains "$out" "work on 'main' is 2 commit(s) behind origin/main" "and main is reported behind"
echo

# ---------------------------------------------------------------------------
echo "case 11: a main with local commits, tracking origin/main, behind"
repo=$(fixture localmain 2 composer.lock)
echo "mine" >> "$repo/app.php"
git -C "$repo" commit -qam "local work on main"
out=$(run_hook touch "$(read_payload t-localmain "$repo/app.php")")
contains "$out" "work on 'main' is 2 commit(s) behind origin/main" "the working-branch line"
contains "$out" "local commit(s)" "the sync note: not a fast-forward"
contains "$out" "composer.lock moved" "the consequences are reported"
contains "$out" "to merge by hand" "the conflict on app.php is predicted"
lacks "$out" "pushed to this branch" "no second line saying the same thing"
echo

# ---------------------------------------------------------------------------
echo "case 12: a /pipeline run's own branch gets no raise-and-wait"
repo=$(fixture piperun 2)
git -C "$repo" checkout -q -b run/feature
mkdir -p "$repo/.claude/pipeline"
echo '{}' > "$repo/.claude/pipeline/run-feature.json"
out=$(run_hook touch "$(read_payload t-pipe "$repo/app.php")")
lacks "$out" "commit(s) behind" "no working-branch line"
lacks "$out" "Raise this with the user" "no raise-and-wait"
contains "$out" "fast-forwarded main" "the base sync still reports"
rm "$repo/.claude/pipeline/run-feature.json"
out=$(run_hook touch "$(read_payload t-pipe-control "$repo/app.php")")
contains "$out" "work on 'run/feature' is 2 commit(s) behind origin/main" "without its manifest the same branch is reported"
echo

# ---------------------------------------------------------------------------
echo "case 13: a detached HEAD names no branch to bring up"
repo=$(fixture detached 2)
git -C "$repo" checkout -q --detach HEAD
out=$(run_hook touch "$(read_payload t-detached "$repo/app.php")")
lacks "$out" "commit(s) behind" "no working-branch line"
lacks "$out" "Raise this with the user" "no raise-and-wait"
contains "$out" "fast-forwarded main" "main, checked out nowhere, is fast-forwarded as a ref"
echo

echo "----------------------------------------"
printf '%d passed, %d failed\n' "$passed" "$failed"
[ "$failed" -eq 0 ]
