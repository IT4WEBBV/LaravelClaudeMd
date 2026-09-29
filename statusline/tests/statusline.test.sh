#!/usr/bin/env bash
#
# Tests for statusline/statusline-command.sh: the autoflow run rows below the status line, and a
# status line exactly as before without them.
#
#   bash statusline/tests/statusline.test.sh
#
# Every case builds a throwaway repo under $TMPDIR; nothing in ~/GitProjects is touched.

set -uo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
script="$HERE/../statusline-command.sh"
root="$(cd "$(mktemp -d)" && pwd -P)"
trap 'rm -rf "$root"' EXIT
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1

passed=0
failed=0
ok()   { passed=$((passed + 1)); printf '  ok    %s\n' "$1"; }
fail() { failed=$((failed + 1)); printf '  FAIL  %s\n' "$1"; [ $# -gt 1 ] && printf '        %s\n' "$2"; return 0; }
is()       { if [ "$1" = "$2" ]; then ok "$3"; else fail "$3" "expected [$2], got [$1]"; fi; }
contains() { case "$1" in *"$2"*) ok "$3" ;; *) fail "$3" "[$2] not in [$1]" ;; esac; }

# run <script> <cwd>: the status line for a session in <cwd>
run()   { printf '{"workspace":{"current_dir":"%s"}}' "$2" | "$1"; }
# grep -c '' counts a last line without a newline, which the status line never prints
lines() { printf '%s' "$1" | grep -c ''; }
row()   { printf '%s' "$1" | sed -n "${2}p"; }

repo="$root/Shop"
git init -q "$repo"
git -C "$repo" -c user.name=t -c user.email=t@t commit -q --allow-empty -m init
git -C "$repo" worktree add -q "$root/Shop-7" -b feature/issue-7-x
mkdir -p "$root/Shop-7/.claude/pipeline" "$root/plain"
printf '{"branch":"feature/issue-7-x","worktree":"%s","mode":"autoflow","cursor":{"leg":"implement","status":"pending"},"artifacts":{"issue":7}}\n' \
    "$root/Shop-7" > "$root/Shop-7/.claude/pipeline/feature-issue-7-x.json"

echo "case 1: outside a repo, the status line alone"
out=$(run "$script" "$root/plain")
is "$(lines "$out")" "1" "one row"
echo

echo "case 2: a repo with an autoflow run gets its row below the status line"
out=$(run "$script" "$repo")
is "$(lines "$out")" "2" "two rows"
contains "$(row "$out" 2)" "#7  implement  pending" "the run's row"
echo

echo "case 3: run through a symlink, as ~/.claude/statusline-command.sh"
ln -s "$script" "$root/statusline-command.sh"
out=$(run "$root/statusline-command.sh" "$repo")
contains "$(row "$out" 2)" "#7  implement  pending" "the CLI is found through the link"
echo

echo "case 4: a php that fails adds nothing, not even its error text"
mkdir -p "$root/bin"
printf '#!/bin/sh\necho "PHP Fatal error: boom"\nexit 255\n' > "$root/bin/php"
chmod +x "$root/bin/php"
out=$(PATH="$root/bin:$PATH" run "$script" "$repo")
is "$(lines "$out")" "1" "one row"
case "$out" in *"Fatal"*) fail "no error text" "$out" ;; *) ok "no error text" ;; esac
echo

echo "----------------------------------------"
printf '%d passed, %d failed\n' "$passed" "$failed"
[ "$failed" -eq 0 ]
