#!/usr/bin/env bash
#
# git-freshness.sh — warn when a checkout is stale relative to origin.
#
# Wired into ~/.claude/settings.json. Prints Claude Code hook JSON on stdout.
#
#   session    SessionStart — check the directory the session was launched in.
#              The only thing knowable before anything has been touched.
#
#   edit       PostToolUse on Edit|Write — check the repo that owns the file
#              being written, once per repo per session. This is the one that
#              matters: it anchors on the repo actually being worked in, which
#              is not necessarily where the session was launched, and it fires
#              at the moment staleness starts costing something — right before
#              new work lands on an old base.
#
#   checkout   PostToolUse on `git checkout` — a branch switch changes the
#              answer, so drop this session's cached verdicts and stay silent.
#              The next edit re-checks.
#
# Why this exists: `git status` only compares HEAD against its *tracking* branch,
# so a feature branch perfectly in sync with its own remote reads as "up to date"
# even when origin/main has moved underneath it. That is the most common
# stale-checkout case, and it is invisible to `git status`.
#
# This script never touches the working tree or any local branch. It fetches and
# refreshes remote-tracking metadata only; deciding whether to rebase or merge is
# left to the human.
#
# Every path exits 0: a hook must never take a session down with it.

set -uo pipefail

mode="${1:-session}"

max_fetch_seconds=10    # hard cap on the network call
fetch_ttl_seconds=900   # skip the network entirely if we fetched within 15 min

payload=""
[ -t 0 ] || payload=$(cat 2>/dev/null)

# Pull a simple string field out of the hook's JSON payload. Enough for the
# flat, known-shape fields we need; jq is not installed on this machine.
payload_field() {
    printf '%s' "$payload" \
        | sed -n "s/.*\"$1\"[[:space:]]*:[[:space:]]*\"\([^\"]*\)\".*/\1/p" \
        | head -1
}

session_id=$(payload_field session_id)
cache_dir="${TMPDIR:-/tmp}/claude-git-freshness/${session_id:-nosession}"

mtime() {
    stat -f %m "$1" 2>/dev/null || stat -c %Y "$1" 2>/dev/null
}

human_age() {
    local s=$1
    if   [ "$s" -lt 60 ];    then echo "just now"
    elif [ "$s" -lt 3600 ];  then echo "$((s / 60))m ago"
    elif [ "$s" -lt 86400 ]; then echo "$((s / 3600))h ago"
    else                          echo "$((s / 86400))d ago"
    fi
}

# Escape a string for embedding in a JSON string literal.
json_escape() {
    printf '%s' "$1" \
        | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' \
        | awk 'BEGIN { ORS = "" } { print (NR > 1 ? "\\n" : "") $0 }'
}

abs_git_path() {
    local d
    d=$(git rev-parse "$1" 2>/dev/null) || return
    case "$d" in
        /*) printf '%s' "$d" ;;
        *)  printf '%s/%s' "$PWD" "$d" ;;
    esac
}

# FETCH_HEAD is per-worktree: a fetch from inside a worktree writes
# .git/worktrees/<name>/FETCH_HEAD, leaving the common dir's copy untouched (and
# often hours stale). Take whichever is newer so both layouts read correctly.
newest_fetch_mtime() {
    local newest=0 dir m
    for dir in "$(abs_git_path --git-dir)" "$(abs_git_path --git-common-dir)"; do
        [ -n "$dir" ] || continue
        m=$(mtime "$dir/FETCH_HEAD"); m=${m:-0}
        [ "$m" -gt "$newest" ] && newest=$m
    done
    printf '%s' "$newest"
}

emit() {
    local event=$1 context=$2 summary=$3
    printf '{'
    printf '"hookSpecificOutput":{"hookEventName":"%s","additionalContext":"%s"}' \
        "$(json_escape "$event")" "$(json_escape "$context")"
    if [ -n "$summary" ]; then
        printf ',"systemMessage":"%s"' "$(json_escape "$summary")"
    else
        printf ',"suppressOutput":true'
    fi
    printf '}\n'
}

# Report on the repo containing $1. Prints hook JSON, or nothing when the path
# is not a git repo with an origin.
check_repo() {
    local target=$1 event=$2

    cd "$target" 2>/dev/null || return 0
    git rev-parse --git-dir >/dev/null 2>&1 || return 0
    git remote get-url origin >/dev/null 2>&1 || return 0

    local now last age
    now=$(date +%s)
    last=$(newest_fetch_mtime)
    age=$((now - last))

    if [ "$age" -ge "$fetch_ttl_seconds" ]; then
        GIT_TERMINAL_PROMPT=0 \
        GIT_SSH_COMMAND="${GIT_SSH_COMMAND:-ssh} -o BatchMode=yes" \
            git fetch --quiet origin >/dev/null 2>&1 &
        local fetch_pid=$! ticks=0
        while kill -0 "$fetch_pid" 2>/dev/null; do
            if [ "$ticks" -ge "$((max_fetch_seconds * 4))" ]; then
                kill "$fetch_pid" 2>/dev/null
                break
            fi
            sleep 0.25
            ticks=$((ticks + 1))
        done
        wait "$fetch_pid" 2>/dev/null

        # Re-point origin/HEAD at the remote's real default branch. This symref
        # is cached at clone time and goes stale silently — a clone made when
        # `develop` was default still claims `develop` years after the repo
        # moved to `main`, which would have us measure against the wrong branch.
        git remote set-head origin --auto >/dev/null 2>&1

        last=$(newest_fetch_mtime)
        age=$((now - last))
    fi

    local branch upstream base_ref candidate
    branch=$(git symbolic-ref --short -q HEAD 2>/dev/null || echo "")
    [ -z "$branch" ] && branch="(detached HEAD)"

    upstream=$(git rev-parse --abbrev-ref --symbolic-full-name '@{u}' 2>/dev/null || echo "")

    base_ref=$(git symbolic-ref -q --short refs/remotes/origin/HEAD 2>/dev/null || echo "")
    if [ -z "$base_ref" ]; then
        for candidate in origin/main origin/master; do
            if git rev-parse --verify --quiet "$candidate" >/dev/null 2>&1; then
                base_ref="$candidate"
                break
            fi
        done
    fi

    local warnings="" counts behind_up ahead_up behind_base merge_base
    warnings=""

    if [ -n "$upstream" ]; then
        counts=$(git rev-list --left-right --count "$upstream...HEAD" 2>/dev/null || echo "0 0")
        behind_up=$(echo "$counts" | awk '{print $1+0}')
        ahead_up=$(echo "$counts" | awk '{print $2+0}')
        if [ "$behind_up" -gt 0 ]; then
            warnings="${warnings}
  - $upstream has $behind_up commit(s) not in this checkout (local is also $ahead_up ahead)"
        fi
    fi

    # Behind the base branch — the case `git status` cannot see.
    if [ -n "$base_ref" ] && [ "$upstream" != "$base_ref" ]; then
        behind_base=$(git rev-list --count "HEAD..$base_ref" 2>/dev/null || echo 0)
        if [ "$behind_base" -gt 0 ]; then
            merge_base=$(git merge-base HEAD "$base_ref" 2>/dev/null | cut -c1-9)
            warnings="${warnings}
  - $base_ref has $behind_base commit(s) not in this branch (merge-base ${merge_base:-unknown})"
        fi
    fi

    local fetched context summary refs
    fetched=$(human_age "$age")

    if [ -n "$warnings" ]; then
        context="Stale checkout: $(basename "$target") on branch '$branch'${warnings}
Last fetch: ${fetched}.

Do NOT pull, rebase, or merge on your own initiative. Raise this with the user
before starting work and let them decide whether to bring the branch up to date
or deliberately continue on the current base."
        summary="Stale checkout: '$branch' is behind origin — see details before starting work."
    else
        refs="${base_ref:-origin}"
        if [ -n "$upstream" ] && [ "$upstream" != "$base_ref" ]; then
            refs="$refs and $upstream"
        fi
        context="git freshness: $(basename "$target") on '$branch' is current with ${refs} (fetched ${fetched})."
        summary=""
    fi

    emit "$event" "$context" "$summary"
}

case "$mode" in
    checkout)
        # A branch switch invalidates every verdict cached for this session.
        [ -n "$session_id" ] && rm -rf "$cache_dir" 2>/dev/null
        exit 0
        ;;

    edit)
        file_path=$(payload_field file_path)
        [ -n "$file_path" ] || exit 0

        dir=$file_path
        [ -d "$dir" ] || dir=$(dirname "$file_path")
        [ -d "$dir" ] || exit 0

        toplevel=$(git -C "$dir" rev-parse --show-toplevel 2>/dev/null) || exit 0
        [ -n "$toplevel" ] || exit 0

        # Once per repo per session. The marker is written before the check runs
        # so that non-repos and origin-less repos are cached too, and a second
        # edit never re-spawns the work.
        marker="$cache_dir/$(printf '%s' "$toplevel" | tr -c 'A-Za-z0-9._-' '_')"
        [ -e "$marker" ] && exit 0
        mkdir -p "$cache_dir" 2>/dev/null && : > "$marker" 2>/dev/null

        check_repo "$toplevel" PostToolUse
        ;;

    session | *)
        # Nothing has been edited yet, so the session's own cwd is all we have.
        repo=$(payload_field cwd)
        [ -n "$repo" ] && [ -d "$repo" ] || repo="$PWD"
        check_repo "$repo" SessionStart
        ;;
esac

exit 0
