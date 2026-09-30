#!/usr/bin/env bash
# Fixture test for needs_input.py: one `needs input:` line for every PR waiting on the owner's merge,
# ordered by PR number, each pair once, never more than 200 characters after the colon (links go first,
# then entries), and every printed line is one the job list's marker pattern captures whole.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

fail() { printf 'FAIL needs_input.py: %s\n' "$1"; exit 1; }
line() { python3 "$HERE/../needs_input.py" "$@"; }

# The job list's marker pattern as read from Claude Code 2.1.285, searched in the last 800 characters of
# the latest message. — and – are the em dash and the en dash.
capture() { # <message>: the text the job list would show as what the session needs
  python3 -c '
import re, sys
found = re.search(r"(?:^|\n)\s*needs input\s*[:—–-]\s*(.{3,200}?)(?=\n|$)", sys.argv[1][-800:], re.I)
print(found.group(1) if found else "NO MARKER")' "$1"
}

marks() { # <line>: the pattern captures everything after "needs input: ", alone and as a report's last line
  local wanted="${1#needs input: }"
  [ "$(capture "$1")" = "$wanted" ] || fail "the classifier's pattern does not capture the whole line: $1"
  [ "$(capture "$(printf 'Run #124 dispatched.\n\nPR #109 is ready: the gate answered ready.\n\n%s' "$1")")" = "$wanted" ] \
    || fail "the classifier's pattern does not capture the line at the end of a report: $1"
}

prints() { # <expected line> <arguments…>
  local expected="$1" actual
  shift
  actual="$(line "$@")" || fail "exited non-zero for: $*"
  [ "$actual" = "$expected" ] || fail "$(printf 'for: %s\n--- expected\n%s\n--- actual\n%s' "$*" "$expected" "$actual")"
  marks "$actual"
}

refuses() { # <arguments…>: usage on stderr, exit 2, nothing on stdout
  local status=0 out
  out="$(line "$@" 2>"$TMP/err")" || status=$?
  [ "$status" -eq 2 ] || fail "exited $status, expected 2, for: $*"
  [ -z "$out" ] || fail "printed on stdout before refusing: $out"
  grep -q '^Usage: ' "$TMP/err" || fail "no usage on stderr for: $*"
}

# One PR: the line with its link.
prints 'needs input: merge PR #12 (#7) https://github.com/acme/app/pull/12' acme/app 12:7

# Three PRs, out of order and one of them twice: ordered by PR number, each once, with links.
prints 'needs input: merge PR #109 (#91) https://github.com/acme/app/pull/109, PR #118 (#104) https://github.com/acme/app/pull/118, PR #121 (#113) https://github.com/acme/app/pull/121' \
  acme/app 118:104 109:91 121:113 109:91

# Four PRs: with links the text passes 200, so every link goes.
prints 'needs input: merge PR #109 (#91), PR #118 (#104), PR #121 (#113), PR #124 (#119)' \
  acme/app 124:119 118:104 109:91 121:113

# This repository's name is long enough for three PRs to drop their links.
prints 'needs input: merge PR #109 (#91), PR #118 (#104), PR #121 (#113)' IT4WEBBV/LaravelClaudeMd 109:91 118:104 121:113

# Numbers are compared as numbers; one PR with two issues prints both, ordered by issue.
prints 'needs input: merge PR #12 (#7) https://github.com/acme/app/pull/12' acme/app 012:7 12:07
prints 'needs input: merge PR #109 (#91) https://github.com/acme/app/pull/109, PR #109 (#92) https://github.com/acme/app/pull/109' \
  acme/app 109:92 109:91

# The boundary: exactly 200 characters after the colon keeps the link, 201 drops it.
# "merge " 6 + "PR #1 (#1)" 10 + " " 1 + "https://github.com/" 19 + the repo 157 + "/pull/1" 7 = 200.
edge="acme/$(printf 'a%.0s' $(seq 1 152))"
prints "needs input: merge PR #1 (#1) https://github.com/$edge/pull/1" "$edge" 1:1
prints 'needs input: merge PR #1 (#1)' "${edge}a" 1:1

# Forty PRs: as many linkless entries as fit, lowest PR numbers first, then " +<n> more".
pairs=()
for i in $(seq 1 40); do pairs+=("$((100 + i)):$i"); done
many="$(line acme/app "${pairs[@]}")" || fail "exited non-zero for forty PRs"
marks "$many"
text="${many#needs input: }"
[ "${#text}" -le 200 ] || fail "forty PRs: ${#text} characters after the colon"
more=' \+([0-9]+) more$'
[[ "$text" =~ $more ]] || fail "forty PRs: does not end in ' +<n> more': $text"
hidden="${BASH_REMATCH[1]}"
shown="$(printf '%s' "$text" | grep -o 'PR #' | wc -l | tr -d ' ')"
[ "$shown" -ge 1 ] || fail "forty PRs: no PR shown: $text"
[ "$((shown + hidden))" -eq 40 ] || fail "forty PRs: $shown shown + $hidden more is not forty"
case "$text" in
  'merge PR #101 (#1), PR #102 (#2), '*'PR #113 (#13) +27 more') ;;
  *) fail "forty PRs: not the thirteen lowest that fit (199 characters): $text" ;;
esac

# Not even one entry fits: still a marker, never a traceback.
prints 'needs input: merge +1 more' acme/app "$(printf '1%.0s' $(seq 1 190)):1"

# No PR waits: no output, exit 0, so it can run before every report.
out="$(line acme/app)" || fail "no pairs did not exit 0"
[ -z "$out" ] || fail "no pairs printed: $out"

# A mistyped call never prints half a line.
refuses acme 12:7
refuses acme/app/extra 12:7
refuses acme/app 12:x
refuses acme/app '#12:7'
refuses acme/app 12:7 13
refuses

echo "PASS needs_input.py"
