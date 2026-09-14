#!/usr/bin/env bash
#
# vault-sync.sh — keep the SecondBrain vault, where Claude's auto-memory lives,
# committed and in sync with origin on every machine.
#
# Wired into ~/.claude/settings.json next to git-freshness.sh.
#
#   session   SessionStart (matcher startup|resume|clear). Abort a rebase an
#             earlier run left behind, sweep memory/ into a commit, fetch (at
#             most every 15 minutes), rebase onto the upstream when behind,
#             push when ahead, and report anything that went wrong — including
#             what the last `end` run parked.
#
#   end       SessionEnd. Sweep, then push. SessionEnd cannot hand context to
#             Claude, so problems are parked in .git/vault-sync-warnings for
#             the next `session` run to report.
#
# Why only at session boundaries: sessions write memories while they run. A
# rebase started underneath those writes leaves the vault mid-rebase on a
# detached HEAD, and the next abort discards whatever was committed there.
# Nothing here runs while a session is working, and nothing is committed while
# a rebase is in progress, so an abort can never drop a memory.
#
# What it stages: memory/ and nothing else. The owner's notes at the vault root
# are never staged; --autostash carries their uncommitted edits across a rebase.
#
# Conflicts are Claude's to resolve: the vault holds Claude's own notes (a
# vault-only exception to the no-rebase rule in CLAUDE.md). The MEMORY.md
# indexes merge by union (.gitattributes), so only a topic file edited on both
# machines can conflict.
#
# Every path exits 0: a sync problem must never take a session down with it.

set -uo pipefail

mode="${1:-session}"
vault="${VAULT_DIR:-$HOME/GitProjects/SecondBrain/SecondBrain}"

max_fetch_seconds="${VAULT_MAX_FETCH_SECONDS:-10}"
fetch_ttl_seconds="${VAULT_FETCH_TTL_SECONDS:-900}"

# Additions that look like a credential keep their file out of the commit.
# Deliberately narrow, and each token needs its real length: a memory that only
# mentions "ghp_" must still commit, because a false positive holds a memory
# back indefinitely. The rule in CLAUDE.md is the real control. label::regex
secret_patterns=(
    'private key::-----BEGIN [A-Z ]*PRIVATE KEY-----'
    'GitHub token::ghp_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{50,}'
    'Stripe live key::sk_live_[A-Za-z0-9]{20,}'
    'AWS access key::AKIA[0-9A-Z]{16}'
    'Slack token::xox[abp]-[A-Za-z0-9-]{20,}'
)

[ -t 0 ] || cat >/dev/null 2>&1   # the payload carries nothing this hook needs

warnings=""
warn() {
    warnings="${warnings}${warnings:+
}$1"
}

# As in git-freshness.sh, plus tabs and carriage returns: warnings quote file
# names and git output, and one stray control character invalidates the JSON.
json_escape() {
    printf '%s' "$1" \
        | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' -e "s/$(printf '\t')/\\\\t/g" -e "s/$(printf '\r')//g" \
        | awk 'BEGIN { ORS = "" } { print (NR > 1 ? "\\n" : "") $0 }'
}

emit_warnings() {
    [ -n "$warnings" ] || return 0
    printf '{"hookSpecificOutput":{"hookEventName":"SessionStart","additionalContext":"%s"},"suppressOutput":true}\n' \
        "$(json_escape "$warnings")"
}

mtime() {
    stat -f %m "$1" 2>/dev/null || stat -c %Y "$1" 2>/dev/null
}

vgit() {
    git -C "$vault" "$@"
}

# Retry while a concurrent session holds the index lock; give up after three.
vgit_locked() {
    local attempt
    for attempt in 1 2 3; do
        vgit "$@" >/dev/null 2>&1 && return 0
        [ -e "$git_dir/index.lock" ] || return 1
        sleep 0.3
    done
    return 1
}

operation_in_progress() {
    [ -d "$git_dir/rebase-merge" ] || [ -d "$git_dir/rebase-apply" ] || [ -f "$git_dir/MERGE_HEAD" ]
}

# A rebase or merge an earlier run left behind (a killed hook, a session that
# ended while Claude was resolving a conflict). Aborting restores the branch as
# it was; nothing is lost because nothing is committed during one.
abort_stale_operation() {
    if [ -d "$git_dir/rebase-merge" ] || [ -d "$git_dir/rebase-apply" ]; then
        vgit rebase --abort >/dev/null 2>&1
    fi
    if [ -f "$git_dir/MERGE_HEAD" ]; then
        vgit merge --abort >/dev/null 2>&1
    fi
    return 0
}

# The label of the first secret pattern the staged additions of $1 match.
secret_in() {
    local added entry n
    added=$(vgit diff --cached --no-color --no-ext-diff -- "$1" | grep '^+' | grep -v '^+++')
    for entry in "${secret_patterns[@]}"; do
        # grep -c rather than -q: see matches_path() in git-freshness.sh.
        n=$(printf '%s\n' "$added" | grep -cE -- "${entry#*::}")
        if [ "${n:-0}" -gt 0 ]; then
            printf '%s' "${entry%%::*}"
            return 0
        fi
    done
    return 1
}

hold_back_secrets() {
    local file label
    while IFS= read -r file; do
        [ -n "$file" ] || continue
        label=$(secret_in "$file") || continue
        vgit reset -q -- "$file" >/dev/null 2>&1
        warn "SecondBrain: $file was not committed — it contains what looks like a $label. Remove it from that memory; the next sync commits the file."
    done < <(vgit diff --cached --name-only --diff-filter=AM -- memory)
}

sweep() {
    [ -d "$vault/memory" ] || return 0

    # Never commit mid-rebase: the next abort would throw the commit away.
    if operation_in_progress; then
        warn "SecondBrain: a rebase is in progress in the vault, so memories were not committed. The next session start aborts it and syncs again."
        return 0
    fi

    # Whatever the owner staged outside memory/ is theirs to commit.
    if [ -n "$(vgit diff --cached --name-only -- . ':(exclude)memory')" ]; then
        warn "SecondBrain: the vault has staged changes outside memory/ that are the owner's to commit, so memories were not committed."
        return 0
    fi

    if ! vgit_locked add -- memory; then
        warn "SecondBrain: could not stage memory/ (index locked by another session); the next sync retries."
        return 0
    fi

    hold_back_secrets

    vgit diff --cached --quiet -- memory && return 0
    vgit_locked commit -q -m "memory: sync" \
        || warn "SecondBrain: committing memory/ failed; the next sync retries."
}

# The same bounded, TTL'd fetch as git-freshness.sh's check_repo().
fetch_if_stale() {
    local now last fetch_pid ticks=0
    now=$(date +%s)
    last=$(mtime "$git_dir/FETCH_HEAD"); last=${last:-0}
    [ $((now - last)) -ge "$fetch_ttl_seconds" ] || return 0

    GIT_TERMINAL_PROMPT=0 \
    GIT_SSH_COMMAND="${GIT_SSH_COMMAND:-ssh} -o BatchMode=yes -o ConnectTimeout=5" \
        git -C "$vault" fetch --quiet origin >/dev/null 2>&1 &
    fetch_pid=$!
    while kill -0 "$fetch_pid" 2>/dev/null; do
        if [ "$ticks" -ge "$((max_fetch_seconds * 4))" ]; then
            kill "$fetch_pid" 2>/dev/null
            warn "SecondBrain: fetching the vault took longer than ${max_fetch_seconds}s and was stopped; the next session syncs again."
            break
        fi
        sleep 0.25
        ticks=$((ticks + 1))
    done
    wait "$fetch_pid" 2>/dev/null
    return 0
}

catch_up() {
    local upstream behind conflicts
    upstream=$(vgit rev-parse --abbrev-ref --symbolic-full-name '@{u}' 2>/dev/null) || return 0
    behind=$(vgit rev-list --count "HEAD..$upstream" 2>/dev/null || echo 0)
    [ "${behind:-0}" -gt 0 ] || return 0

    vgit rebase --quiet --autostash "$upstream" >/dev/null 2>&1 && return 0

    conflicts=$(vgit diff --name-only --diff-filter=U 2>/dev/null | tr '\n' ' ' | sed 's/ $//')
    vgit rebase --abort >/dev/null 2>&1
    warn "SecondBrain: the vault diverged from $upstream and rebasing hit a conflict in: ${conflicts:-unknown files}. These are your own notes — resolve it now: git -C $vault rebase $upstream; in each conflicted file keep both sides' facts; git -C $vault add <file>; git -C $vault rebase --continue; git -C $vault push."
}

push_if_ahead() {
    local upstream ahead
    upstream=$(vgit rev-parse --abbrev-ref --symbolic-full-name '@{u}' 2>/dev/null) || return 0
    ahead=$(vgit rev-list --count "$upstream..HEAD" 2>/dev/null || echo 0)
    [ "${ahead:-0}" -gt 0 ] || return 0

    # Detached, so the hook returns at once: Claude Code reads a hook's stdout
    # until it closes, and a push holding that pipe would stall the session
    # until the timeout killed it. A rejected push is retried next session.
    ( GIT_TERMINAL_PROMPT=0 \
      GIT_SSH_COMMAND="${GIT_SSH_COMMAND:-ssh} -o BatchMode=yes -o ConnectTimeout=5" \
      nohup git -C "$vault" push --quiet </dev/null >/dev/null 2>&1 & )
    return 0
}

if [ ! -e "$vault/.git" ]; then
    if [ "$mode" = session ]; then
        warn "SecondBrain: no vault at $vault, so memories are not being synced. Clone it (CLAUDE.md, Bootstrapping a new machine)."
        emit_warnings
    fi
    exit 0
fi

git_dir=$(vgit rev-parse --absolute-git-dir 2>/dev/null) || exit 0
parked="$git_dir/vault-sync-warnings"

case "$mode" in
    end)
        sweep
        push_if_ahead
        [ -n "$warnings" ] && printf '%s\n' "$warnings" >> "$parked"
        ;;
    session | *)
        if [ -s "$parked" ]; then
            warn "$(cat "$parked")"
            rm -f "$parked"
        fi
        abort_stale_operation
        sweep
        fetch_if_stale
        catch_up
        push_if_ahead
        emit_warnings
        ;;
esac

exit 0
