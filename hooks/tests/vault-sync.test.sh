#!/usr/bin/env bash
#
# Tests for hooks/vault-sync.sh.
#
#   bash hooks/tests/vault-sync.test.sh
#
# Every case builds a throwaway vault under $TMPDIR — a bare "origin", this
# machine's clone ("vault") and the second machine's clone ("other") — and runs
# the hook the way Claude Code does: as a subprocess, pointed at the fixture
# with VAULT_DIR. Nothing in ~/GitProjects is touched; run_hook refuses a
# fixture outside $root, for the reason given in git-freshness-sync.test.sh.

set -uo pipefail

here=$(cd "$(dirname "$0")" && pwd)
hook="$here/../vault-sync.sh"

[ -f "$hook" ] || { echo "cannot find $hook"; exit 1; }

# pwd -P: on macOS $TMPDIR is /var/folders/... while git reports /private/var/...
root=$(cd "$(mktemp -d "${TMPDIR:-/tmp}/vault-sync-tests.XXXXXX")" && pwd -P)
trap 'rm -rf "$root"' EXIT

export GIT_CONFIG_GLOBAL="$root/gitconfig"
export GIT_CONFIG_SYSTEM=/dev/null
export GIT_AUTHOR_NAME=test GIT_AUTHOR_EMAIL=test@example.com
export GIT_COMMITTER_NAME=test GIT_COMMITTER_EMAIL=test@example.com
git config --global init.defaultBranch main
git config --global user.name test
git config --global user.email test@example.com
git config --global pull.rebase true

passed=0
failed=0
ttl=0   # fetch on every session run unless a case says otherwise

ok()   { passed=$((passed + 1)); printf '  ok    %s\n' "$1"; }
fail() { failed=$((failed + 1)); printf '  FAIL  %s\n' "$1"; [ $# -gt 1 ] && printf '        %s\n' "$2"; return 0; }
die()  { printf 'FATAL: %s\n' "$1"; exit 1; }

is()       { if [ "$1" = "$2" ]; then ok "$3"; else fail "$3" "expected '$2', got '$1'"; fi; }
contains() { case "$1" in *"$2"*) ok "$3" ;; *) fail "$3" "expected to contain '$2', got: $1" ;; esac; }
lacks()    { case "$1" in *"$2"*) fail "$3" "expected NOT to contain '$2', got: $1" ;; *) ok "$3" ;; esac; }
holds()    { local label=$1; shift; if "$@"; then ok "$label"; else fail "$label"; fi; }

json_is_valid() {
    command -v python3 >/dev/null 2>&1 || return 0
    printf '%s' "$1" | python3 -c 'import json,sys; json.load(sys.stdin)' 2>/dev/null
}

# Bare origin, a seed commit (index, one topic, the owner's note, union merge
# for indexes), then this machine's and the other machine's clones.
make_fixture() {
    (
        set -e
        local dir="$root/$1"
        mkdir -p "$dir"
        git init -q --bare -b main "$dir/origin.git"
        git clone -q "$dir/origin.git" "$dir/seed" 2>/dev/null
        mkdir -p "$dir/seed/memory"
        printf '# Memory index\n' > "$dir/seed/memory/MEMORY.md"
        printf 'shared fact\n' > "$dir/seed/memory/topic.md"
        printf 'my own note\n' > "$dir/seed/notes.md"
        printf 'memory/**/MEMORY.md merge=union\n' > "$dir/seed/.gitattributes"
        git -C "$dir/seed" add .gitattributes notes.md memory/MEMORY.md memory/topic.md
        git -C "$dir/seed" commit -qm "initial"
        git -C "$dir/seed" push -q -u origin main
        git clone -q "$dir/origin.git" "$dir/vault" 2>/dev/null
        git clone -q "$dir/origin.git" "$dir/other" 2>/dev/null
        printf '%s' "$dir"
    )
}

fixture() {
    local path
    path=$(make_fixture "$1") || die "fixture '$1' failed to build"
    case "$path" in "$root"/*) ;; *) die "fixture path '$path' escaped \$root" ;; esac
    [ -d "$path/vault" ] || die "fixture '$1' has no vault"
    printf '%s' "$path"
}

# run_hook <mode> <fixture> — no payload on stdin, like SessionStart/SessionEnd.
run_hook() {
    case "$2" in "$root"/*) ;; *) die "refusing to run the hook outside \$root: '$2'" ;; esac
    VAULT_DIR="$2/vault" VAULT_FETCH_TTL_SECONDS="$ttl" bash "$hook" "$1" </dev/null 2>/dev/null
}

git_dir()    { git -C "$1/vault" rev-parse --absolute-git-dir; }
origin_has() { git -C "$1/origin.git" cat-file -e "main:$2" 2>/dev/null; }
on_main()    { [ "$(git -C "$1/vault" symbolic-ref --short -q HEAD)" = main ]; }
mid_rebase() { [ -d "$(git_dir "$1")/rebase-merge" ] || [ -d "$(git_dir "$1")/rebase-apply" ]; }
not_mid_rebase() { ! mid_rebase "$1"; }
committed()  { [ "$(git -C "$1/vault" log --name-only --format= main | grep -cx -- "$2")" -gt 0 ]; }

# Pushes are detached, so give one up to ten seconds to land.
origin_gets() {
    local i
    for i in $(seq 1 40); do
        origin_has "$1" "$2" && return 0
        sleep 0.25
    done
    return 1
}

# The other machine saves a memory and pushes it.
other_saves() { # other_saves <fixture> <file> <line>
    git -C "$1/other" pull -q
    mkdir -p "$(dirname "$1/other/$2")"
    printf '%s\n' "$3" >> "$1/other/$2"
    git -C "$1/other" add -- "$2"
    git -C "$1/other" commit -qm "memory: other machine"
    git -C "$1/other" push -q
}

# Both machines rewrite memory/topic.md, so rebasing one onto the other conflicts.
diverge_topic() {
    printf 'other version\n' > "$1/other/memory/topic.md"
    git -C "$1/other" commit -qam "memory: other edit"
    git -C "$1/other" push -q
    printf 'local version\n' > "$1/vault/memory/topic.md"
}

# Stop the vault halfway through a conflicting rebase, as a killed hook or a
# session that ended mid-resolution would leave it.
leave_rebase_in_progress() {
    diverge_topic "$1"
    git -C "$1/vault" commit -qam "memory: local edit"
    git -C "$1/vault" fetch -q
    git -C "$1/vault" rebase -q origin/main >/dev/null 2>&1
    mid_rebase "$1" || die "fixture did not leave a rebase in progress"
}

echo "git $(git --version | awk '{print $3}') — testing $(basename "$hook")"
echo

# ---------------------------------------------------------------------------
echo "case 1: a new memory is committed and pushed at session end"
f=$(fixture new-memory)
printf 'fresh fact\n' > "$f/vault/memory/fresh.md"
out=$(run_hook end "$f")
is "$out" "" "end prints nothing"
is "$(git -C "$f/vault" log -1 --format=%s)" "memory: sync" "sweep committed"
is "$(git -C "$f/vault" status --porcelain -- memory | grep -c .)" "0" "memory/ is clean afterwards"
holds "detached push reached origin" origin_gets "$f" memory/fresh.md
echo

# ---------------------------------------------------------------------------
echo "case 2: the owner's root notes are never staged"
f=$(fixture root-notes)
printf 'owner edit\n' >> "$f/vault/notes.md"
printf 'draft\n' > "$f/vault/scratch.md"
printf 'fact\n' > "$f/vault/memory/fact.md"
run_hook end "$f" >/dev/null
is "$(git -C "$f/vault" diff-tree --no-commit-id --name-only -r HEAD)" "memory/fact.md" "the commit holds only memory/"
contains "$(git -C "$f/vault" status --porcelain)" " M notes.md" "owner's edit left uncommitted"
contains "$(git -C "$f/vault" status --porcelain)" "?? scratch.md" "owner's new file left untracked"
echo

# ---------------------------------------------------------------------------
echo "case 3: something the owner staged outside memory/ blocks the sweep"
f=$(fixture owner-staged)
printf 'owner edit\n' >> "$f/vault/notes.md"
git -C "$f/vault" add notes.md
printf 'fact\n' > "$f/vault/memory/fact.md"
before=$(git -C "$f/vault" rev-parse HEAD)
out=$(ttl=99999 run_hook session "$f")
is "$(git -C "$f/vault" rev-parse HEAD)" "$before" "nothing committed"
contains "$out" "staged changes outside memory/" "warning says why"
holds "warning is valid JSON" json_is_valid "$out"
echo

# ---------------------------------------------------------------------------
echo "case 4: a secret holds back only its own file"
f=$(fixture secret)
fake_token="gh""p_$(printf 'a%.0s' $(seq 1 36))"   # split so GitHub push protection doesn't flag this file
printf 'token: %s\n' "$fake_token" > "$f/vault/memory/leaky.md"
printf 'harmless\n' > "$f/vault/memory/clean.md"
out=$(run_hook session "$f")
holds "clean file committed" committed "$f" memory/clean.md
holds "leaky file never committed" eval '! committed "$f" memory/leaky.md'
contains "$(git -C "$f/vault" status --porcelain)" "?? memory/leaky.md" "leaky file still on disk, untracked"
contains "$out" "memory/leaky.md" "warning names the file"
contains "$out" "GitHub token" "warning names the pattern"
lacks "$out" "$fake_token" "warning never repeats the secret"
holds "warning is valid JSON" json_is_valid "$out"
echo

# ---------------------------------------------------------------------------
echo "case 5: a memory that only mentions a token prefix still commits"
f=$(fixture mention)
printf 'GitHub tokens start with ghp_ and are 40 characters long.\n' > "$f/vault/memory/tokens.md"
run_hook end "$f" >/dev/null
holds "prose about ghp_ committed" committed "$f" memory/tokens.md
echo

# ---------------------------------------------------------------------------
echo "case 6: session start catches up with the other machine, then pushes"
f=$(fixture catch-up)
other_saves "$f" memory/remote.md "fact from the other machine"
printf 'local fact\n' > "$f/vault/memory/local.md"
out=$(run_hook session "$f")
is "$(cat "$f/vault/memory/remote.md" 2>/dev/null)" "fact from the other machine" "the other machine's memory arrived"
holds "still on main, not detached" on_main "$f"
holds "local memory pushed on top" origin_gets "$f" memory/local.md
is "$out" "" "a clean catch-up is silent"
echo

# ---------------------------------------------------------------------------
echo "case 7: both machines appending to MEMORY.md merge by union"
f=$(fixture union)
other_saves "$f" memory/MEMORY.md "- [Remote](remote.md) — from the other machine"
printf -- '- [Local](local.md) — from this machine\n' >> "$f/vault/memory/MEMORY.md"
out=$(run_hook session "$f")
contains "$(cat "$f/vault/memory/MEMORY.md")" "from the other machine" "remote index line kept"
contains "$(cat "$f/vault/memory/MEMORY.md")" "from this machine" "local index line kept"
lacks "$out" "conflict" "no conflict reported"
echo

# ---------------------------------------------------------------------------
echo "case 8: a topic-file conflict is aborted and handed to Claude"
f=$(fixture conflict)
diverge_topic "$f"
out=$(run_hook session "$f")
status=$?
is "$status" "0" "exits 0 even on a conflict"
holds "vault not left mid-rebase" not_mid_rebase "$f"
holds "still on main" on_main "$f"
is "$(cat "$f/vault/memory/topic.md")" "local version" "local version kept"
contains "$out" "conflict in: memory/topic.md" "warning names the conflicted file"
contains "$out" "resolve it now" "warning tells Claude to resolve it"
holds "warning is valid JSON" json_is_valid "$out"
echo

# ---------------------------------------------------------------------------
echo "case 9: a stale rebase is aborted before the sweep commits"
f=$(fixture stale-rebase)
leave_rebase_in_progress "$f"
printf 'written during the conflict\n' > "$f/vault/memory/new.md"
run_hook session "$f" >/dev/null
holds "back on main, not a detached HEAD" on_main "$f"
holds "memory written mid-rebase committed on main" committed "$f" memory/new.md
holds "no rebase left in progress" not_mid_rebase "$f"
echo

# ---------------------------------------------------------------------------
echo "case 10: session end mid-rebase commits nothing and parks a warning"
f=$(fixture end-mid-rebase)
leave_rebase_in_progress "$f"
printf 'late fact\n' > "$f/vault/memory/late.md"
head_before=$(git -C "$f/vault" rev-parse HEAD)
run_hook end "$f" >/dev/null
is "$(git -C "$f/vault" rev-parse HEAD)" "$head_before" "nothing committed on the detached HEAD"
holds "warning parked for the next session" test -s "$(git_dir "$f")/vault-sync-warnings"
out=$(run_hook session "$f")
contains "$out" "a rebase is in progress" "next session reports the parked warning"
holds "parked warning cleared" test ! -e "$(git_dir "$f")/vault-sync-warnings"
holds "late memory committed on main by the next session" committed "$f" memory/late.md
echo

# ---------------------------------------------------------------------------
echo "case 11: a push rejected at session end is retried at the next start"
f=$(fixture rejected-push)
other_saves "$f" memory/remote.md "remote fact"
printf 'local fact\n' > "$f/vault/memory/local.md"
run_hook end "$f" >/dev/null   # end never fetches, so this push is rejected
sleep 1
holds "end's push was rejected" eval '! origin_has "$f" memory/local.md'
run_hook session "$f" >/dev/null
holds "next session rebased and pushed" origin_gets "$f" memory/local.md
echo

# ---------------------------------------------------------------------------
echo "case 12: the hook returns before a slow push finishes"
f=$(fixture slow-push)
printf '#!/bin/sh\nsleep 3\n' > "$f/origin.git/hooks/pre-receive"
chmod +x "$f/origin.git/hooks/pre-receive"
printf 'fact\n' > "$f/vault/memory/fact.md"
start=$SECONDS
run_hook end "$f" >/dev/null
elapsed=$((SECONDS - start))
holds "returned in ${elapsed}s, before the 3s push" test "$elapsed" -le 1
holds "the push still lands" origin_gets "$f" memory/fact.md
echo

# ---------------------------------------------------------------------------
echo "case 13: no fetch within the TTL"
f=$(fixture ttl)
git -C "$f/vault" fetch -q   # FETCH_HEAD is now fresh
other_saves "$f" memory/remote.md "remote fact"
ttl=900 run_hook session "$f" >/dev/null
holds "did not fetch inside 15 minutes" test ! -e "$f/vault/memory/remote.md"
echo

# ---------------------------------------------------------------------------
echo "case 14: a held index.lock is waited on, then left to the next sweep"
f=$(fixture locked)
: > "$(git_dir "$f")/index.lock"
printf 'fact\n' > "$f/vault/memory/fact.md"
run_hook end "$f" >/dev/null
contains "$(cat "$(git_dir "$f")/vault-sync-warnings" 2>/dev/null)" "index locked" "lock contention parked as a warning"
holds "another session's lock left alone" test -e "$(git_dir "$f")/index.lock"
rm -f "$(git_dir "$f")/index.lock"
run_hook session "$f" >/dev/null
holds "committed once the lock is gone" committed "$f" memory/fact.md
echo

# ---------------------------------------------------------------------------
echo "case 15: the owner's uncommitted note survives the rebase"
f=$(fixture autostash)
other_saves "$f" memory/remote.md "remote fact"
printf 'owner draft line\n' >> "$f/vault/notes.md"
run_hook session "$f" >/dev/null
contains "$(cat "$f/vault/notes.md")" "owner draft line" "owner's edit survived"
contains "$(git -C "$f/vault" status --porcelain)" " M notes.md" "and is still uncommitted"
holds "the remote memory arrived" test -e "$f/vault/memory/remote.md"
echo

# ---------------------------------------------------------------------------
echo "case 16: nothing to sync — silent"
f=$(fixture idle)
out=$(run_hook session "$f")
is "$out" "" "no output"
echo

# ---------------------------------------------------------------------------
echo "case 17: no vault cloned — one warning at session start, silence at end"
out=$(VAULT_DIR="$root/does-not-exist" bash "$hook" session </dev/null 2>/dev/null)
contains "$out" "no vault at" "session start warns"
holds "warning is valid JSON" json_is_valid "$out"
out=$(VAULT_DIR="$root/does-not-exist" bash "$hook" end </dev/null 2>/dev/null)
is "$out" "" "session end stays silent"
echo

# ---------------------------------------------------------------------------
echo "case 18: a deleted memory is committed as a deletion"
f=$(fixture deletion)
rm "$f/vault/memory/topic.md"
run_hook end "$f" >/dev/null
is "$(git -C "$f/vault" diff-tree --no-commit-id --name-status -r HEAD)" "D	memory/topic.md" "deletion committed"
echo

echo "----------------------------------------"
printf '%d passed, %d failed\n' "$passed" "$failed"
[ "$failed" -eq 0 ]
