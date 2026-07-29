#!/usr/bin/env bash
#
# git-freshness.sh — warn when the current checkout is stale relative to origin.
#
# Wired into ~/.claude/settings.json as a SessionStart hook and as a PostToolUse
# hook on `git checkout`. Prints Claude Code hook JSON on stdout.
#
# Why this exists: `git status` only compares HEAD against its *tracking* branch,
# so a feature branch that is perfectly in sync with its own remote reads as
# "up to date" even when origin/main has moved underneath it. That is the most
# common stale-checkout case, and it is invisible to `git status`.
#
# This script never touches the working tree or any local branch. It fetches and
# refreshes remote-tracking metadata only; deciding whether to rebase/merge is
# left to the human.
#
# Every path exits 0: a hook must never take a session down with it.
#
# Usage: git-freshness.sh <SessionStart|PostToolUse>

set -uo pipefail

event="${1:-SessionStart}"
repo="${CLAUDE_PROJECT_DIR:-$PWD}"

max_fetch_seconds=10    # hard cap on the network call
fetch_ttl_seconds=900   # skip the network entirely if we fetched within 15 min

cd "$repo" 2>/dev/null || exit 0
git rev-parse --git-dir >/dev/null 2>&1 || exit 0
git remote get-url origin >/dev/null 2>&1 || exit 0

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

# FETCH_HEAD is per-worktree: a fetch from inside a worktree writes
# .git/worktrees/<name>/FETCH_HEAD, leaving the common dir's copy untouched (and
# often hours stale). Take whichever is newer so both layouts read correctly.
abs_git_path() {
    local d
    d=$(git rev-parse "$1" 2>/dev/null) || return
    case "$d" in
        /*) printf '%s' "$d" ;;
        *)  printf '%s/%s' "$PWD" "$d" ;;
    esac
}

newest_fetch_mtime() {
    local newest=0 dir m
    for dir in "$(abs_git_path --git-dir)" "$(abs_git_path --git-common-dir)"; do
        [ -n "$dir" ] || continue
        m=$(mtime "$dir/FETCH_HEAD"); m=${m:-0}
        [ "$m" -gt "$newest" ] && newest=$m
    done
    printf '%s' "$newest"
}

now=$(date +%s)
last=$(newest_fetch_mtime)
age=$((now - last))

if [ "$age" -ge "$fetch_ttl_seconds" ]; then
    GIT_TERMINAL_PROMPT=0 \
    GIT_SSH_COMMAND="${GIT_SSH_COMMAND:-ssh} -o BatchMode=yes" \
        git fetch --quiet origin >/dev/null 2>&1 &
    fetch_pid=$!

    ticks=0
    while kill -0 "$fetch_pid" 2>/dev/null; do
        if [ "$ticks" -ge "$((max_fetch_seconds * 4))" ]; then
            kill "$fetch_pid" 2>/dev/null
            break
        fi
        sleep 0.25
        ticks=$((ticks + 1))
    done
    wait "$fetch_pid" 2>/dev/null

    # Re-point origin/HEAD at the remote's real default branch. This symref is
    # cached at clone time and goes stale silently — a clone made when `develop`
    # was default still claims `develop` years after the repo moved to `main`,
    # which would have us measure staleness against the wrong branch.
    git remote set-head origin --auto >/dev/null 2>&1

    last=$(newest_fetch_mtime)
    age=$((now - last))
fi

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

warnings=""
add_warning() { warnings="${warnings}${warnings:+$'\n'}  - $1"; }

# Behind the branch's own upstream.
if [ -n "$upstream" ]; then
    counts=$(git rev-list --left-right --count "$upstream...HEAD" 2>/dev/null || echo "0 0")
    behind_up=$(echo "$counts" | awk '{print $1+0}')
    ahead_up=$(echo "$counts" | awk '{print $2+0}')
    if [ "$behind_up" -gt 0 ]; then
        add_warning "$upstream has $behind_up commit(s) not in this checkout (local is also $ahead_up ahead)"
    fi
fi

# Behind the base branch — the case `git status` cannot see.
if [ -n "$base_ref" ] && [ "$upstream" != "$base_ref" ]; then
    behind_base=$(git rev-list --count "HEAD..$base_ref" 2>/dev/null || echo 0)
    if [ "$behind_base" -gt 0 ]; then
        merge_base=$(git merge-base HEAD "$base_ref" 2>/dev/null | cut -c1-9)
        add_warning "$base_ref has $behind_base commit(s) not in this branch (merge-base ${merge_base:-unknown})"
    fi
fi

fetched=$(human_age "$age")

if [ -n "$warnings" ]; then
    context="Stale checkout: $(basename "$repo") on branch '$branch'
${warnings}
Last fetch: ${fetched}.

Do NOT pull, rebase, or merge on your own initiative. Raise this with the user
before starting work and let them decide whether to bring the branch up to date
or deliberately continue on the current base."
    summary="Stale checkout: '$branch' is behind origin — see details before starting work."
else
    context="git freshness: '$branch' is current with ${base_ref:-origin} ${upstream:+and $upstream }(fetched ${fetched})."
    summary=""
fi

printf '{'
printf '"hookSpecificOutput":{"hookEventName":"%s","additionalContext":"%s"}' \
    "$(json_escape "$event")" "$(json_escape "$context")"
if [ -n "$summary" ]; then
    printf ',"systemMessage":"%s"' "$(json_escape "$summary")"
else
    printf ',"suppressOutput":true'
fi
printf '}\n'

exit 0
