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

echo "----------------------------------------"
printf '%d passed, %d failed\n' "$passed" "$failed"
[ "$failed" -eq 0 ]
