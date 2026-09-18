#!/usr/bin/env bash
#
# Tests for sync_base_branch() in hooks/git-freshness.sh.
#
#   bash hooks/tests/git-freshness-sync.test.sh
#
# Every case builds a throwaway repo (a bare "origin" plus one or two checkouts)
# under $TMPDIR and exercises the function directly, so nothing in ~/GitProjects
# is ever touched. The function is reached by sourcing the hook with
# GIT_FRESHNESS_LIB=1, which defines the functions without running the hook.
#
# The case that matters most is "base checked out in ANOTHER worktree": git only
# refuses to write a ref that is the *current* worktree's branch, so a naive
# implementation silently leaves the other checkout's index claiming a staged
# revert of everything that just arrived. sync_base_branch must consult `git
# worktree list` rather than trusting git to say no. Case 3 is that guard.
#
# Note the fixture() and run_sync() guards below. An earlier version of this
# file let a failed fixture yield an empty path, after which every `git -C ""`
# and `cd ""` quietly fell through to the checkout the tests live in — which
# then really did grow stray branches. A test that mutates real repositories
# when it breaks is worse than no test, so both helpers abort unless the path
# they were handed is a directory inside $root.

set -uo pipefail

here=$(cd "$(dirname "$0")" && pwd)
hook="$here/../git-freshness.sh"

[ -f "$hook" ] || { echo "cannot find $hook"; exit 1; }

# Resolved with `pwd -P`: on macOS $TMPDIR is /var/folders/... while git reports
# the real /private/var/folders/..., which would make both the $root containment
# guards and any path assertion compare two spellings of the same directory.
root=$(cd "$(mktemp -d "${TMPDIR:-/tmp}/git-freshness-tests.XXXXXX")" && pwd -P)
trap 'rm -rf "$root"' EXIT

# Isolate from the user's real git config: init.defaultBranch, templates, hooks
# and aliases would otherwise leak into the fixtures.
export GIT_CONFIG_GLOBAL="$root/gitconfig"
export GIT_CONFIG_SYSTEM=/dev/null
export GIT_AUTHOR_NAME=test GIT_AUTHOR_EMAIL=test@example.com
export GIT_COMMITTER_NAME=test GIT_COMMITTER_EMAIL=test@example.com
git config --global init.defaultBranch main
git config --global user.name test
git config --global user.email test@example.com

# Session mode also syncs the config repos and links their skills into
# ~/.claude/skills. No case may reach the real ones: default both to nothing,
# and let the config cases below point them at fixtures explicitly.
export GIT_FRESHNESS_CONFIG_REPOS=""
export GIT_FRESHNESS_SKILLS_DIR="$root/no-skills-dir"

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

checksum() {
    [ -f "$1" ] || die "checksum: no such file '$1'"
    md5 -q "$1" 2>/dev/null || md5sum "$1" | awk '{print $1}'
}

# Build a fixture: bare origin, a primary checkout, and $2 commits pushed by
# "somebody else". If $3 is given, each upstream commit also touches that file,
# which is how the lockfile / migration consequences get triggered.
#
# Runs under `set -e` in its own subshell so a broken step fails the fixture
# rather than yielding a half-built repo.
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

# make_fixture, but a path that is empty, outside $root, or not a directory is
# fatal instead of being handed on to git.
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

# Reset the globals the function appends to, then run it inside $1.
run_sync() {
    local target=${1:-}
    case "$target" in
        "$root"/*) ;;
        *) die "refusing to run sync outside \$root: '$target'" ;;
    esac
    [ -d "$target" ] || die "not a directory: '$target'"

    sync_notes=""
    sync_tags=""
    insights=""
    tags=""
    cd "$target" || die "cannot cd to '$target'"
    sync_base_branch "origin/main"
}

# shellcheck disable=SC1090
GIT_FRESHNESS_LIB=1 . "$hook" </dev/null

echo "git $(git --version | awk '{print $3}') — testing $(basename "$hook")"
echo

# ---------------------------------------------------------------------------
echo "case 1: base checked out nowhere — pure ref update, no working tree touched"
repo=$(fixture nowhere 2)
git -C "$repo" checkout -q -b feature
before_head=$(git -C "$repo" rev-parse HEAD)
run_sync "$repo"
is "$(git -C "$repo" rev-list --count main..origin/main)" "0" "main fast-forwarded to origin/main"
is "$(git -C "$repo" rev-parse HEAD)" "$before_head" "HEAD (feature) did not move"
is "$(git -C "$repo" status --porcelain | wc -l | tr -d ' ')" "0" "working tree stayed clean"
is "$sync_tags" "" "silent: no user-visible tag for a ref-only update"
echo

# ---------------------------------------------------------------------------
echo "case 2: base checked out here, clean, lockfile moved — tree fast-forwarded + warned"
repo=$(fixture here 2 composer.lock)
run_sync "$repo"
is "$(git -C "$repo" rev-list --count main..origin/main)" "0" "main fast-forwarded"
is "$(git -C "$repo" status --porcelain | wc -l | tr -d ' ')" "0" "working tree clean afterwards"
is "$(tail -1 "$repo/app.php")" "upstream 2" "working tree actually holds the new content"
contains "$sync_notes" "composer install" "note says composer install is needed"
contains "$sync_tags" "restart" "visible tag mentions a restart"
echo

# ---------------------------------------------------------------------------
echo "case 3: base checked out in ANOTHER worktree — the git 2.33 footgun"
repo=$(fixture otherwt 2 composer.lock)
git -C "$repo" worktree add -q "$root/otherwt/slot" -b slot
run_sync "$root/otherwt/slot"
is "$(git -C "$repo" rev-list --count main..origin/main)" "0" "main fast-forwarded"
is "$(git -C "$repo" status --porcelain | wc -l | tr -d ' ')" "0" \
   "REGRESSION GUARD: primary checkout has no phantom staged revert"
is "$(tail -1 "$repo/app.php")" "upstream 2" "primary checkout's files match the new main"
contains "$sync_notes" "$repo" "note names the checkout that moved"
echo

# ---------------------------------------------------------------------------
echo "case 4: base checked out but dirty — left strictly alone"
repo=$(fixture dirty 2 composer.lock)
echo "work in progress" >> "$repo/app.php"
before_sum=$(checksum "$repo/app.php")
before_main=$(git -C "$repo" rev-parse main)
run_sync "$repo"
is "$(git -C "$repo" rev-parse main)" "$before_main" "main did not move"
is "$(checksum "$repo/app.php")" "$before_sum" "uncommitted work preserved byte-for-byte"
contains "$sync_notes" "uncommitted" "note explains it was skipped as dirty"
echo

# ---------------------------------------------------------------------------
echo "case 5: local commits on base — never rewritten"
repo=$(fixture diverged 2)
echo "my local work" > "$repo/local.php"
git -C "$repo" add local.php
git -C "$repo" commit -qm "local work"
before_main=$(git -C "$repo" rev-parse main)
git -C "$repo" checkout -q -b feature
run_sync "$repo"
is "$(git -C "$repo" rev-parse main)" "$before_main" "main untouched"
is "$(git -C "$repo" log -1 --format=%s main)" "local work" "local commit still on top"
contains "$sync_notes" "local commit" "note explains it is not fast-forwardable"
echo

# ---------------------------------------------------------------------------
echo "case 6: already up to date — completely silent"
repo=$(fixture uptodate 0)
run_sync "$repo"
is "$sync_notes" "" "no notes"
is "$sync_tags" "" "no tags"
echo

# ---------------------------------------------------------------------------
echo "case 7: mid-rebase checkout — left alone"
repo=$(fixture midrebase 2)
gitdir=$(git -C "$repo" rev-parse --absolute-git-dir)
mkdir -p "$gitdir/rebase-merge"
before_main=$(git -C "$repo" rev-parse main)
run_sync "$repo"
is "$(git -C "$repo" rev-parse main)" "$before_main" "main untouched during a rebase"
contains "$sync_notes" "operation in progress" "note explains the rebase blocked it"
rm -rf "$gitdir/rebase-merge"
echo

# ---------------------------------------------------------------------------
echo "case 8: application-code-only fast-forward — moves, but stays quiet"
repo=$(fixture quiet 2)
run_sync "$repo"
is "$(git -C "$repo" rev-list --count main..origin/main)" "0" "main fast-forwarded"
is "$sync_tags" "" "no user-visible noise when nothing needs rebuilding"
lacks "$sync_notes" "restart" "no restart advice when no lockfile moved"
echo

# ---------------------------------------------------------------------------
echo "case 9: migrations moved — flagged for a migrate"
repo=$(fixture migrations 2 database/migrations/2026_01_01_000000_add_thing.php)
run_sync "$repo"
contains "$sync_notes" "migrate" "note says migrate is needed"
contains "$sync_tags" "restart" "visible tag raised"
echo

# ---------------------------------------------------------------------------
# The unit cases above call sync_base_branch directly. These run the hook the
# way Claude Code does — as a subprocess, fed a JSON payload on stdin — because
# a hook that syncs correctly but emits malformed JSON breaks every session.
echo "case 10: the hook as a subprocess — valid JSON, sync reported"

json_is_valid() {
    command -v python3 >/dev/null 2>&1 || return 0
    printf '%s' "$1" | python3 -c 'import json,sys; json.load(sys.stdin)' 2>/dev/null
}

repo=$(fixture hookrun 2 composer.lock)
payload="{\"session_id\":\"test-session\",\"cwd\":\"$repo\"}"
out=$(printf '%s' "$payload" | bash "$hook" session 2>/dev/null)

# grep -c '' rather than wc -l: command substitution has already stripped the
# trailing newline, and wc would report 0 for a perfectly good single line.
is "$(printf '%s' "$out" | grep -c '')" "1" "emits exactly one line"
if json_is_valid "$out"; then ok "output parses as JSON"; else fail "output parses as JSON" "$out"; fi
contains "$out" '"hookEventName":"SessionStart"' "declares the right hook event"
contains "$out" 'fast-forwarded main' "context reports the sync"
contains "$out" '"systemMessage"' "raises a visible message (composer.lock moved)"
is "$(git -C "$repo" rev-list --count main..origin/main)" "0" "the hook really did fast-forward main"
lacks "$out" "Config repos" "no config section when no config repos are set"
echo

echo "case 11: edit mode caches per repo — second write stays silent"
repo=$(fixture editmode 2)
payload="{\"session_id\":\"test-edit\",\"file_path\":\"$repo/app.php\"}"
rm -rf "${TMPDIR:-/tmp}/claude-git-freshness/test-edit"
first=$(printf '%s' "$payload" | bash "$hook" edit 2>/dev/null)
second=$(printf '%s' "$payload" | bash "$hook" edit 2>/dev/null)
contains "$first" '"hookEventName":"PostToolUse"' "first write checks the repo"
is "$second" "" "second write in the same repo is a no-op"
is "$(git -C "$repo" rev-list --count main..origin/main)" "0" "main synced on the first write"
rm -rf "${TMPDIR:-/tmp}/claude-git-freshness/test-edit"
echo

echo "case 12: the SecondBrain vault is left to vault-sync.sh"
repo=$(fixture vaultskip 2)
payload="{\"session_id\":\"test-vault\",\"file_path\":\"$repo/app.php\"}"
rm -rf "${TMPDIR:-/tmp}/claude-git-freshness/test-vault"
out=$(printf '%s' "$payload" | VAULT_DIR="$repo" bash "$hook" edit 2>/dev/null)
is "$out" "" "no report for the vault"
is "$(git -C "$repo" rev-list --count main..origin/main)" "2" "the vault's main was not fast-forwarded"
rm -rf "${TMPDIR:-/tmp}/claude-git-freshness/test-vault"
echo

# Push a commit to a fixture's origin from its "other" clone, then fetch it into
# the checkout, so the next sync has something to fast-forward.
push_upstream() { # push_upstream <fixture name> <path> <content>
    local other="$root/$1/other"
    [ -d "$other" ] || die "no fixture '$1'"
    mkdir -p "$(dirname "$other/$2")"
    echo "$3" > "$other/$2"
    git -C "$other" add "$2"
    git -C "$other" commit -qm "add $2"
    git -C "$other" push -q origin main
    git -C "$root/$1/work" fetch -q origin
}

echo "case 13: session start syncs a config repo and links its new skills"
cfg=$(fixture config 2 skills/newskill/SKILL.md)
push_upstream config skills/taken/SKILL.md "taken"
push_upstream config skills/notaskill/README.md "no SKILL.md here"
skills="$root/config/skills-dir"
mkdir -p "$skills" "$root/config/elsewhere/taken"
ln -s "$root/config/elsewhere/taken" "$skills/taken"
mkdir -p "$root/config/plain"
payload="{\"session_id\":\"test-config\",\"cwd\":\"$root/config/plain\"}"
out=$(printf '%s' "$payload" \
    | GIT_FRESHNESS_CONFIG_REPOS="$cfg" GIT_FRESHNESS_SKILLS_DIR="$skills" bash "$hook" session 2>/dev/null)
is "$(printf '%s' "$out" | grep -c '')" "1" "emits exactly one line, even from outside a repo"
if json_is_valid "$out"; then ok "output parses as JSON"; else fail "output parses as JSON" "$out"; fi
is "$(git -C "$cfg" rev-list --count main..origin/main)" "0" "config repo's main fast-forwarded"
if [ -f "$cfg/skills/newskill/SKILL.md" ]; then ok "config checkout holds the new skill"; else fail "config checkout holds the new skill"; fi
is "$(readlink "$skills/newskill")" "$cfg/skills/newskill" "new skill linked into the skills dir"
is "$(readlink "$skills/taken")" "$root/config/elsewhere/taken" "an existing entry with the same name is left alone"
if [ -e "$skills/notaskill" ]; then fail "a folder without SKILL.md is not linked"; else ok "a folder without SKILL.md is not linked"; fi
contains "$out" "linked new skill newskill" "the new link is reported"
lacks "$out" "linked new skill taken" "the collision is not reported as linked"
contains "$out" '"systemMessage"' "a new skill earns a visible line"
echo

echo "case 14: a config checkout on a feature branch is flagged"
cfg=$(fixture config2 2)
git -C "$cfg" checkout -q -b feature
sess=$(fixture sessionrepo 0)
payload="{\"session_id\":\"test-config2\",\"cwd\":\"$sess\"}"
out=$(printf '%s' "$payload" \
    | GIT_FRESHNESS_CONFIG_REPOS="$cfg" GIT_FRESHNESS_SKILLS_DIR="$root/config2/none" bash "$hook" session 2>/dev/null)
is "$(printf '%s' "$out" | grep -c '')" "1" "still exactly one line alongside the session repo's own report"
if json_is_valid "$out"; then ok "output parses as JSON"; else fail "output parses as JSON" "$out"; fi
contains "$out" "nothing incoming that affects this work" "the session repo's own report is kept"
contains "$out" "skills from 'feature'" "the feature-branch checkout is flagged"
is "$(git -C "$cfg" rev-list --count main..origin/main)" "0" "main still fast-forwarded, as a ref-only write"
is "$(git -C "$cfg" symbolic-ref --short HEAD)" "feature" "the checkout stays on its branch"
echo

echo "case 15: a skills dir that is itself a symlink gets nothing linked into it"
cfg=$(fixture config3 1 skills/another/SKILL.md)
mkdir -p "$root/config3/realskills"
ln -s "$root/config3/realskills" "$root/config3/skills-link"
payload="{\"session_id\":\"test-config3\",\"cwd\":\"$root/config3\"}"
out=$(printf '%s' "$payload" \
    | GIT_FRESHNESS_CONFIG_REPOS="$cfg" GIT_FRESHNESS_SKILLS_DIR="$root/config3/skills-link" bash "$hook" session 2>/dev/null)
if [ -e "$root/config3/realskills/another" ]; then fail "nothing written through the symlink"; else ok "nothing written through the symlink"; fi
echo

echo "----------------------------------------"
printf '%d passed, %d failed\n' "$passed" "$failed"
[ "$failed" -eq 0 ]
