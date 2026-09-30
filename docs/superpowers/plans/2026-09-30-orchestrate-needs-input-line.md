# orchestrate: a PR waiting on the owner's merge ends every report with `needs input:` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task by task. Steps use checkbox (`- [ ]`) syntax for tracking. In an `autoflow` implement step, execute inline, task by task, with no subagents.

**Goal:** While a PR waits on the owner's merge, every message an orchestrator ends a turn on ends with one `needs input:` line naming every waiting PR, so the job list shows the session as needing input (#112).

**Architecture:**
- `skills/orchestrate/needs_input.py`: a pure formatter. `<repo> <pr>:<issue> …` in, one `needs input: merge …` line out, never more than 200 characters after the colon (the job list's marker pattern does not match a longer line). No `gh` call, no file read.
- `skills/orchestrate/SKILL.md` carries the rule (Steps 5 and 6, one *Common mistakes* row); `skills/orchestrate/references/commands.md` carries the command and where the line goes (a new §Needs input after §Watch).
- `skills/orchestrate/tests/needs_input_test.sh`: a bash fixture test like `owners_test.sh`. It pins the printed lines, matches each against the classifier's own pattern, and pins the three places the docs name the line.

**Tech Stack:** Python 3.9 (standard library only), bash 3.2 (macOS), Markdown skill files.

**Spec:** `docs/superpowers/specs/2026-09-30-orchestrate-needs-input-line-design.md`

## Global Constraints

- Every command runs from the worktree root on the host; this repository has no Docker stack.
- The test: `bash skills/orchestrate/tests/needs_input_test.sh`, last line `PASS needs_input.py`. `bash skills/orchestrate/tests/owners_test.sh` still prints `PASS owners.py`; `owners.py` and its test are not touched.
- `LIMIT = 200`: the text after `needs input: ` is 3 to 200 characters. The classifier's pattern (Claude Code 2.1.285) is `(?:^|\n)\s*needs input\s*[:—–-]\s*(.{3,200}?)(?=\n|$)`, case-insensitive, searched in the last 800 characters of the latest message, skipped inside a code fence.
- The line never says `CI green` (spec *Assumptions* 2) and is one line for all waiting PRs (spec *Assumptions* 3).
- Links are on the line for all PRs or for none (spec *Assumptions* 4).
- `python3` only: `jq` is not installed on the owner's machines. The test file must run under bash 3.2: no associative arrays, no `mapfile`.
- Unchanged: the merge watch, teardown, the cap of four working runs, "ask last" through `AskUserQuestion`, Step 7, `owners.py`, the pipeline.
- No file outside `skills/orchestrate/` changes. No scenario file is committed.
- This repository has no `.changelog/` and no `CHANGELOG.md`: no changelog entry. The PR closes #112.
- The PR body says two things: whether the `superpowers:writing-skills` scenario of Task 2 was run (with its numbers) or not, and that the second *Done when* line of #112 (the job list going to needs-input and back) is the owner's to see in the first batch after the merge.

## Review Focus

1. **A line one character over the limit is silently no marker.** Exactly 200 characters after the colon keeps the link; 201 drops it. Task 1's test pins both sides with a repo name sized to the boundary, and checks the 200-character line against the classifier's pattern.
2. **The line at the end of a multi-paragraph report.** The pattern anchors on a line start inside the last 800 characters; Task 1's `marks` helper checks every printed line both alone and as the last line of a report.
3. **A mistyped pair** (`#12:7`, `12:x`, a bare `13`), a repo that is not `owner/name`, or no arguments: exit 2, usage on stderr, nothing on stdout, so half a line never reaches a report. Task 1's `refuses` cases.
4. **The same PR given twice, out of order, or with leading zeros** prints the same line on every report: pairs are compared as numbers, de-duplicated and ordered by PR number. One PR with two issues prints two entries (spec *Assumptions* 16). Task 1's test pins each.
5. **Nothing fits** (a number of about 190 digits): `needs input: merge +1 more`, not a Python traceback in the report (spec *Assumptions* 15). Task 1's test pins it.

---

## File Structure

- Create `skills/orchestrate/needs_input.py`: the line. Mode 644, run through `python3` like `owners.py`.
- Create `skills/orchestrate/tests/needs_input_test.sh`: its fixture test, and the pins on the two Markdown files.
- Modify `skills/orchestrate/SKILL.md`: Step 5 (line 28), Step 6 (line 29), one row at the end of the *Common mistakes* table (after line 53).
- Modify `skills/orchestrate/references/commands.md`: a new `## Needs input` between `## Watch` (ends line 151) and `## Proof page` (line 153).

---

### Task 1: `needs_input.py` prints the line

**Files:**
- Create: `skills/orchestrate/needs_input.py`
- Test: `skills/orchestrate/tests/needs_input_test.sh` (new)

**Interfaces:**
- Consumes: nothing.
- Produces: the command `python3 skills/orchestrate/needs_input.py <repo> <pr>:<issue> [<pr>:<issue> ...]`. With one or more pairs it prints exactly one line, `needs input: <request>`, and exits 0. With a repo and no pair it prints nothing and exits 0. With a malformed repo or pair, or no arguments, it prints `Usage: …` on stderr, nothing on stdout, and exits 2. Task 2's docs name this command; Task 2 appends to this task's test file above its last line, `echo "PASS needs_input.py"`.
- Inside the file: `entry(repo, pr, issue, link) -> str`, `request(repo, pairs) -> str` (pairs: a list of `(pr, issue)` int tuples), `main(arguments) -> int`.

- [ ] **Step 1: Write the failing test.** Create `skills/orchestrate/tests/needs_input_test.sh`:

```bash
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
```

- [ ] **Step 2: Run the test to verify it fails.**

Run: `bash skills/orchestrate/tests/needs_input_test.sh`
Expected: python's `can't open file … needs_input.py` on stderr, then `FAIL needs_input.py: exited non-zero for: acme/app 12:7`, exit status 1.

- [ ] **Step 3: Write the implementation.** Create `skills/orchestrate/needs_input.py`:

```python
#!/usr/bin/env python3
"""Print the `needs input:` line for the PRs that wait on the owner's merge.

Usage: needs_input.py <repo> <pr>:<issue> [<pr>:<issue> ...]

One pair per PR whose merge watch is armed. Prints one line, which ends the orchestrator's message:

    needs input: merge PR #109 (#91) https://github.com/IT4WEBBV/LaravelClaudeMd/pull/109

No pair: prints nothing and exits 0, so it can run before every report. A repo that is not
`owner/name`, or a pair that is not `<digits>:<digits>`: usage on stderr, exit 2.

Why a script: the job list (Claude Code 2.1.285) classifies a session from its latest message, and
shows it as needing input when the last 800 characters hold a line matching

    (?:^|\\n)\\s*needs input\\s*[:—–-]\\s*(.{3,200}?)(?=\\n|$)     (case-insensitive)

outside a code fence. The text after the colon is 3 to 200 characters to the end of the line: a
longer line is no marker at all, and nothing says so. So the request is the first of these that fits
in LIMIT: every PR with its link; every PR without (links on all or on none, so the line's shape
does not vary per PR); as many linkless PRs as fit, then ` +<n> more`.

Pairs are compared as numbers, de-duplicated and ordered by PR number, so consecutive reports print
the same line. The line says nothing about CI: nothing re-checks it when the line is restated.
"""
import re
import sys

LIMIT = 200
PREFIX = "needs input: "
USAGE = "Usage: needs_input.py <repo> <pr>:<issue> [<pr>:<issue> ...]"
REPO = re.compile(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+")
PAIR = re.compile(r"[0-9]+:[0-9]+")


def entry(repo, pr, issue, link):
    text = f"PR #{pr} (#{issue})"
    return f"{text} https://github.com/{repo}/pull/{pr}" if link else text


def merge(repo, pairs, link):
    return "merge " + ", ".join(entry(repo, pr, issue, link) for pr, issue in pairs)


def candidates(repo, pairs):
    yield merge(repo, pairs, True)
    yield merge(repo, pairs, False)
    for shown in range(len(pairs) - 1, -1, -1):
        yield f"{merge(repo, pairs[:shown], False).rstrip()} +{len(pairs) - shown} more"


def request(repo, pairs):
    return next(text for text in candidates(repo, sorted(set(pairs))) if len(text) <= LIMIT)


def main(arguments):
    if not arguments or not REPO.fullmatch(arguments[0]) or not all(PAIR.fullmatch(pair) for pair in arguments[1:]):
        print(USAGE, file=sys.stderr)
        return 2
    pairs = [tuple(int(number) for number in pair.split(":")) for pair in arguments[1:]]
    if pairs:
        print(PREFIX + request(arguments[0], pairs))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
```

Notes for the implementer:
- The docstring is a normal (non-raw) string, so the pattern's backslashes are doubled there. It is documentation only; the code never compiles that pattern. The test holds the pattern that is matched.
- `candidates` ends on `merge +<n> more` (nothing shown, `rstrip()` removes the space after `merge`), which always fits, so `next()` never runs dry.
- `sorted(set(pairs))` orders `(pr, issue)` tuples by PR number, then issue.

- [ ] **Step 4: Run the test to verify it passes.**

Run: `bash skills/orchestrate/tests/needs_input_test.sh`
Expected: `PASS needs_input.py`, exit status 0.

Run: `python3 skills/orchestrate/needs_input.py IT4WEBBV/LaravelClaudeMd 109:91`
Expected: `needs input: merge PR #109 (#91) https://github.com/IT4WEBBV/LaravelClaudeMd/pull/109`

- [ ] **Step 5: Commit.**

```bash
git add skills/orchestrate/needs_input.py skills/orchestrate/tests/needs_input_test.sh
git commit -m "feat(orchestrate): needs_input.py prints the needs input line for the waiting PRs (#112)"
```

---

### Task 2: the skill says when the line is due, and the commands say where it goes

**Files:**
- Modify: `skills/orchestrate/SKILL.md:28` (Step 5), `:29` (Step 6), and the *Common mistakes* table (a row after line 53)
- Modify: `skills/orchestrate/references/commands.md` (a new section between `## Watch` and `## Proof page`)
- Test: `skills/orchestrate/tests/needs_input_test.sh` (the pins, above its last line)

**Interfaces:**
- Consumes: Task 1's command `python3 ~/.claude/skills/orchestrate/needs_input.py <repo> <P>:<N> [<P>:<N> …]` and its output line; Task 1's test file with its `fail` helper, `$HERE`, and its last line `echo "PASS needs_input.py"`.
- Produces: nothing a later task calls.

- [ ] **Step 1: Write the failing pins.** In `skills/orchestrate/tests/needs_input_test.sh`, insert directly above `echo "PASS needs_input.py"`:

```bash
# The skill carries the rule where a PR becomes ready (Step 5) and where it stops waiting (Step 6);
# the commands name the script.
for step in 5 6; do
  grep "^$step\. " "$HERE/../SKILL.md" | grep -F '`needs input:`' >/dev/null \
    || fail "SKILL.md step $step does not carry \`needs input:\`"
done
grep -F 'needs_input.py' "$HERE/../references/commands.md" >/dev/null || fail "commands.md does not name needs_input.py"

```

- [ ] **Step 2: Run the test to verify it fails.**

Run: `bash skills/orchestrate/tests/needs_input_test.sh`
Expected: ``FAIL needs_input.py: SKILL.md step 5 does not carry `needs input:` ``, exit status 1.

- [ ] **Step 3: Step 5 of `SKILL.md`.** In line 28, replace

```
Ready PR: tell the owner in two lines, open its proof page once, arm the merge watch.
```

with

```
Ready PR: tell the owner in two lines, open its proof page once, arm the merge watch, and end the report with the `needs input:` line (commands §Needs input). **While any merge watch is armed, every message that ends a turn ends with that line, naming every waiting PR**, even while other runs work: the job list reads only the latest message.
```

The rest of the step, *Ask last* included, stays as it is.

- [ ] **Step 4: Step 6 of `SKILL.md`.** In line 29, replace

```
Then re-map and start what the merge unblocked.
```

with

```
Then re-map and start what the merge unblocked. A merged PR leaves the `needs input:` line; with no merge watch armed, reports are plain status again.
```

- [ ] **Step 5: One *Common mistakes* row.** After the last row of the table (`| Launching without mapping, or on "its own branch" | … |`), add:

```
| A ready PR announced once, plain status after it | The job list reads only the latest message: PR #109 sat green for 99 minutes while four orchestrators showed `working`. |
```

- [ ] **Step 6: §Needs input in `commands.md`.** Between the last line of `## Watch` (`Adopted session, finished signal: …`) and `## Proof page`, insert this section, with one blank line before and after it:

````markdown
## Needs input

While a merge watch is armed, before every message that ends a turn:

```bash
python3 ~/.claude/skills/orchestrate/needs_input.py <repo> <P>:<N> [<P>:<N> …]
```
One `<P>:<N>` (the PR and its issue) per PR whose merge watch is armed; a PR put back in draft by
`gh pr ready --undo` is left out until it is ready again. No pair prints nothing, so the command can
run before every report. It prints one line, with the links while they fit:

```
needs input: merge PR #109 (#91) https://github.com/IT4WEBBV/LaravelClaudeMd/pull/109
needs input: merge PR #109 (#91), PR #118 (#104), PR #121 (#113)
```

The job list classifies the session from the last 800 characters of its latest message, and matches
at most 200 characters after the colon (Claude Code 2.1.285). So:

1. the line is the **last line** of the message, copied as the script printed it;
2. plain text, **not in a code block**: a marker inside a fence is skipped;
3. nothing after it, and no "no action needed" or "nothing needed from you" in the closing lines;
4. one line: the script already put every waiting PR on it.

A batched `AskUserQuestion` still comes last (Step 5); the line is the last line of the message text.
````

- [ ] **Step 7: Run the tests to verify they pass.**

Run: `bash skills/orchestrate/tests/needs_input_test.sh`
Expected: `PASS needs_input.py`, exit status 0.

Run: `bash skills/orchestrate/tests/owners_test.sh`
Expected: `PASS owners.py`, exit status 0.

Run: `grep -c '^## ' skills/orchestrate/references/commands.md`
Expected: `10` (nine sections before, plus §Needs input).

Run: `git status --short`
Expected: only `skills/orchestrate/SKILL.md`, `skills/orchestrate/references/commands.md` and `skills/orchestrate/tests/needs_input_test.sh` are modified.

- [ ] **Step 8: The skill scenario (`superpowers:writing-skills`).** Only where this session can start an agent: run one scenario against `SKILL.md` as it was before this task (`git show HEAD:skills/orchestrate/SKILL.md`) and as it is now, a few repetitions each, and count how many closing messages end on the `needs input:` line. The scenario: *"You are an orchestrator. PR #109 (issue #91, repo IT4WEBBV/LaravelClaudeMd) was made ready forty minutes ago and its merge watch is armed. The completion notice of the run for #104 just arrived: `finish` printed `done` and its CI gate is running. Write the report that ends this turn, and one line on why it ends the way it does."* Commit no scenario file; keep the two counts for the PR body. Where this session cannot start an agent (an `autoflow` implement step), skip the run and note for the PR body: "writing-skills scenario not run: the implement step cannot start an agent".

- [ ] **Step 9: Commit.** The commit body carries the PR-body note from Step 8.

```bash
git add skills/orchestrate/SKILL.md skills/orchestrate/references/commands.md skills/orchestrate/tests/needs_input_test.sh
git commit -m "feat(orchestrate): every report ends on the needs input line while a PR waits on the merge (#112)" \
  -m "<Step 8's note: the scenario's two counts, or 'writing-skills scenario not run: the implement step cannot start an agent'>" \
  -m "The job list going to needs-input and back is the owner's to see in the first batch after the merge."
```
