#!/usr/bin/env bash
#
# git-freshness.sh — warn when a stale checkout is about to cost you something.
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
# What it deliberately does NOT report: the commit count. "639 commits behind
# origin/main" is a fact with no action attached — it is almost always true and
# almost never means anything. Measured across a full set of checkouts, most
# branches that were "behind" had no local commits at all, so there was nothing
# to protect; and where there was local work, a 639-commit gap came down to five
# touched files, three of which actually conflicted. This script reports
# consequences instead: migrations your dev database is missing, lockfiles that
# moved, files you will have to merge by hand. If none of those apply, it says
# nothing at all.
#
# This script never touches the working tree, the index, or any local branch. It
# fetches and refreshes remote-tracking metadata only, and predicts merges in a
# throwaway index; deciding whether to rebase or merge is left to the human.
#
# Every path exits 0: a hook must never take a session down with it.

set -uo pipefail

mode="${1:-session}"

max_fetch_seconds=10    # hard cap on the network call
fetch_ttl_seconds=900   # skip the network entirely if we fetched within 15 min
max_listed_files=6      # the conflict list is a prompt, not an inventory

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

count_lines() {
    printf '%s\n' "$1" | grep -c . 2>/dev/null || true
}

# Does any line of $1 match the extended regex $2?
#
# Deliberately `grep -c` and not `grep -q`. Under `set -o pipefail`, `grep -q`
# exits the moment it matches, the upstream `printf` takes EPIPE on its
# still-unwritten remainder, and the pipeline reports that failure — so a
# successful match reads as no match once the input outgrows the pipe buffer.
# It fails silently and only on large inputs, which is the worst way to be
# wrong. Counting drains the input, so the status is grep's own.
matches_path() {
    local n
    n=$(printf '%s\n' "$1" | grep -cE "$2" 2>/dev/null)
    [ "${n:-0}" -gt 0 ]
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

# Incoming changes whose arrival has a concrete local consequence. Anything that
# does not land in one of these buckets is, for our purposes, invisible: a
# rebase will absorb it without the human needing to know in advance.
#
# Appends to the globals `insights` (the detail) and `tags` (the headline).
classify_incoming() {
    local mb=$1 base=$2 files n

    files=$(git diff --name-only "$mb" "$base" 2>/dev/null)
    [ -n "$files" ] || return 0

    n=$(printf '%s\n' "$files" | grep -c 'database/migrations/')
    if [ "${n:-0}" -gt 0 ]; then
        insights="${insights}
  - $n new migration(s) on $base — your dev database is behind; migrate after catching up"
        tags="${tags}${tags:+, }$n migrations"
    fi

    n=$(printf '%s\n' "$files" | grep -cE 'database/(actions|operations)/')
    if [ "${n:-0}" -gt 0 ]; then
        insights="${insights}
  - $n new deploy operation(s) on $base — production-only, but review before rebasing onto them"
        tags="${tags}${tags:+, }$n operations"
    fi

    if matches_path "$files" 'composer\.lock'; then
        insights="${insights}
  - composer.lock moved on $base — run composer install after catching up"
        tags="${tags}${tags:+, }composer.lock"
    fi

    if matches_path "$files" 'package-lock\.json|yarn\.lock|pnpm-lock\.yaml'; then
        insights="${insights}
  - JS lockfile moved on $base — run npm install and rebuild assets after catching up"
        tags="${tags}${tags:+, }js lockfile"
    fi

    if matches_path "$files" '\.env\.example'; then
        insights="${insights}
  - .env.example changed on $base — new environment keys may be required"
        tags="${tags}${tags:+, }.env.example"
    fi

    return 0
}

# Which files a catch-up would actually make you merge by hand.
#
# Performed against a throwaway index so the real index and working tree are
# never touched. `merge-tree --write-tree` would be the clean way to do this but
# needs git >= 2.38; `read-tree -m --aggressive` works everywhere and costs about
# 35ms. It resolves only the trivial cases, so this over-reports slightly: a file
# both sides touched in non-overlapping regions still shows up. For a warning,
# that is the right direction to be wrong in.
predict_conflicts() {
    local mb=$1 base=$2 idx paths=""

    idx="${TMPDIR:-/tmp}/claude-freshness-idx.$$"
    rm -f "$idx"
    if GIT_INDEX_FILE="$idx" git read-tree -m --aggressive "$mb" HEAD "$base" >/dev/null 2>&1; then
        paths=$(GIT_INDEX_FILE="$idx" git ls-files -u 2>/dev/null | awk '{print $4}' | sort -u)
    fi
    rm -f "$idx"

    printf '%s' "$paths"
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

    insights=""
    tags=""

    # Someone pushed to *this* branch. Always worth knowing and always
    # actionable — it means another machine, or another slot, is ahead of you.
    local counts behind_up
    if [ -n "$upstream" ]; then
        counts=$(git rev-list --left-right --count "$upstream...HEAD" 2>/dev/null || echo "0 0")
        behind_up=$(echo "$counts" | awk '{print $1+0}')
        if [ "$behind_up" -gt 0 ]; then
            insights="${insights}
  - $upstream has $behind_up commit(s) not in this checkout — another machine or slot pushed to this branch; pull before continuing"
            tags="${tags}${tags:+, }branch pushed elsewhere"
        fi
    fi

    # The base branch has moved. Only speak if there is local work to protect: a
    # branch with no local commits and a clean tree catches up as a risk-free
    # fast-forward, and announcing that is precisely the noise this replaces.
    local behind_base ahead_base dirty mb conflicts n_conf shown
    if [ -n "$base_ref" ] && [ "$upstream" != "$base_ref" ]; then
        behind_base=$(git rev-list --count "HEAD..$base_ref" 2>/dev/null || echo 0)
        ahead_base=$(git rev-list --count "$base_ref..HEAD" 2>/dev/null || echo 0)
        dirty=$(git status --porcelain 2>/dev/null | grep -c . || true)

        if [ "$behind_base" -gt 0 ] && { [ "$ahead_base" -gt 0 ] || [ "${dirty:-0}" -gt 0 ]; }; then
            mb=$(git merge-base HEAD "$base_ref" 2>/dev/null)

            if [ -n "$mb" ]; then
                classify_incoming "$mb" "$base_ref"

                # No local commits means no divergence, so nothing can conflict.
                if [ "$ahead_base" -gt 0 ]; then
                    conflicts=$(predict_conflicts "$mb" "$base_ref")
                    n_conf=$(count_lines "$conflicts")
                    if [ "${n_conf:-0}" -gt 0 ]; then
                        shown=$(printf '%s\n' "$conflicts" | head -"$max_listed_files" | sed 's/^/      /')
                        insights="${insights}
  - catching up would need manual merging in ${n_conf} file(s):
${shown}"
                        if [ "$n_conf" -gt "$max_listed_files" ]; then
                            insights="${insights}
      (+$((n_conf - max_listed_files)) more)"
                        fi
                        tags="${tags}${tags:+, }${n_conf} to merge by hand"
                    fi
                fi
            fi
        fi
    fi

    # Nothing with a consequence attached: stay silent. This is the common case,
    # and keeping it silent is the entire point of the rewrite.
    if [ -z "$insights" ]; then
        emit "$event" \
            "git freshness: $(basename "$target") on '$branch' — nothing incoming that affects this work (fetched $(human_age "$age"))." \
            ""
        return 0
    fi

    local context summary
    context="Stale checkout with consequences: $(basename "$target") on '$branch'${insights}
Last fetch: $(human_age "$age").

Do NOT pull, rebase, or merge on your own initiative. Raise this with the user
before starting work and let them decide whether to bring the branch up to date
or deliberately continue on the current base."
    summary="$(basename "$target") '$branch': ${tags} — incoming from ${base_ref:-origin}."

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
