# git-freshness checks a repo on the session's first touch — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The first tool call a session makes in a repo (Read, Edit, Write, MultiEdit, NotebookEdit, Glob, Grep,
Bash) runs the freshness check on that repo before the tool runs: local `main` fast-forwarded when safe, a working
branch behind its base reported once with its commit count and "raise it and wait", a fetch that did not finish
reported as "freshness unknown".

**Architecture:** `hooks/git-freshness.sh` gets a `touch` mode. `touch_targets` names the paths a tool call acts on
(by `tool_name`; for Bash, the `cd <dir>` / `git -C <dir>` words of the command, else the `cwd`), `repo_toplevel`
maps each to its repo, `claim_repo` claims a per-session marker with an atomic `mkdir`, and `check_repo` runs once per
claimed repo. `check_repo` stops printing JSON itself: it adds its report to `repo_context` / `repo_summary`, and each
mode calls `emit` once. The report gains the working-branch line (`report_behind_base`), skipped for a `/pipeline`
run's own branch, and `fetch_if_stale` returns non-zero when its fetch did not finish.

**Tech Stack:** bash (must run on macOS's bash 3.2: no associative arrays, no `mapfile`), POSIX `awk`/`sed -E`, git.
Tests are plain bash scripts in `hooks/tests/`.

**Spec:** `docs/superpowers/specs/2026-10-02-git-freshness-first-touch-design.md`. Read it with this plan: the plan
argues from it, and its `## Assumptions` 1–21 are the answers this plan builds on (14–21 were added by the plan step).

## Global Constraints

- Run every command from the worktree root
  `/Users/jroelofs/GitProjects/LaravelClaudeMd/LaravelClaudeMd/.claude/worktrees/worktree-issue-60-git-freshness-check-a-repo-on-first-touch-in-a`.
  This repo is not a Docker project: the hook tests run on the host.
- Tests: `bash hooks/tests/git-freshness-touch.test.sh` (new) and `bash hooks/tests/git-freshness-sync.test.sh`
  (existing). Each prints `N passed, 0 failed` and exits 0 when green. After every task **both** must be green:
  the existing file must keep passing **unchanged** (spec *Testing*), including its case 11 (`edit` caches per repo,
  a payload with no `tool_name`).
- Syntax check after every change to the hook: `bash -n hooks/git-freshness.sh` (no output, exit 0).
- Test-first: write the case, run it, see it fail for the reason given, then write the code.
- Every path in the hook exits 0, and every mode prints at most **one** line of hook JSON.
- The `touch` hook object: `"hookEventName":"PreToolUse"`, `additionalContext` with the report(s), no
  `permissionDecision`. `edit` keeps `"PostToolUse"`, `session` keeps `"SessionStart"`.
- The working-branch line, exactly: `Stale checkout: <repo> on '<branch>' is N commit(s) behind <base_ref>.` and its
  summary tag `N behind <base_ref>`.
- The fetch line, exactly: `Fetch from origin did not finish (timed out after 10s, or failed); freshness unknown,
  measured against the last fetch (<age>).` and its tag `freshness unknown` (the `10` is `$max_fetch_seconds`).
- The marker path stays `${TMPDIR:-/tmp}/claude-git-freshness/<session_id>/<toplevel with non-[A-Za-z0-9._-] as _>`;
  the marker becomes a directory made with `mkdir` (no `-p` on the marker itself).
- `~/.claude/settings.json` is **not** edited (spec assumption 7). README carries the new wiring; the PR body (a later
  leg) lists the per-machine change. Whether `additionalContext` from the PreToolUse hook reaches the model is checked
  live once a machine is rewired, not in this run (spec *What was read and probed*).
- No test may touch a real repo, the real config repos, `~/.claude/skills` or the real `$TMPDIR` cache: the new test
  file exports the same `GIT_FRESHNESS_*` overrides as the existing one, and points `TMPDIR` into its own `$root`.
- No changelog entry: the repo has no `.changelog/` and no `CHANGELOG.md`.
- Commits: stage explicit paths only; no `Co-Authored-By`, no AI attribution; every message ends on `(#60)`.

## Review Focus

1. **A Bash command whose `cd` word only the shell can resolve** (`cd $WORKTREE && make`, `cd "/a b/c"`) — no
   repo is guessed from the word; the call falls back to its `cwd`, so a session working in a repo through such
   commands is still checked there. Pinned in Task 3's case 8 (*a variable falls back to the cwd*, *a quoted path
   with a space (not resolved) falls back to the cwd*).
2. **A local `main` tracking `origin/main`, with local commits, behind** — the behind line plus the consequences
   (spec assumption 14), and no "pushed to this branch elsewhere" line saying the same thing twice. Pinned in Task 4's
   case 11.
3. **A `/pipeline` run on a branch with a slash** (`run/feature` → `.claude/pipeline/run-feature.json`) — no
   raise-and-wait, and the same branch without its manifest does get the line, so the test is not vacuous. Pinned in
   Task 4's case 12.
4. **A Write or Edit whose content holds the text `"file_path":"/elsewhere"`** — the check targets the tool's real
   `file_path`, not the one inside the escaped content. Pinned in Task 2's case 6.
5. **One Bash command touching two new repos** (`git -C <a> status && git -C <b> status`) — both checked, one valid
   JSON line holding both reports. Pinned in Task 3's case 8.

---

### Task 1: collect-then-emit, and an unknown mode does nothing

`check_repo` stops printing JSON; each mode emits once. A mode the script does not know exits silently instead of
running a full session sync.

**Files:**
- Modify: `hooks/git-freshness.sh` (globals at lines 79–81, `emit` at 157–181, `check_repo` at 575–693, the `case`
  at 701–741)
- Create: `hooks/tests/git-freshness-touch.test.sh`

**Interfaces:**
- Produces: globals `repo_context` and `repo_summary` (strings, empty at start); `add_report <context> <summary>`
  appends one repo's report (context joined by a blank line, summary by a space); `check_repo <path>` (one
  argument now, no event) adds to them and prints nothing. The `emitted` global is gone.
- Produces (test harness, used by every later task): `fixture <name> [upstream-commits] [extra-file]`,
  `is`/`contains`/`lacks`, `json_is_valid`, `checksum`, `file_mtime`, `age_fetch_head <repo>`,
  `tool_payload <session> <tool> <cwd> <tool_input-json>`, `run_hook <mode> <payload>`.

- [ ] **Step 1: Create the test file with its harness and case 1**

Create `hooks/tests/git-freshness-touch.test.sh`:

```bash
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
```

New cases in later tasks go directly above the closing `echo "----…"` block, in case-number order.

- [ ] **Step 2: Run it and see it fail**

Run: `bash hooks/tests/git-freshness-touch.test.sh`
Expected: `FAIL  prints nothing` (the `session | *` branch ran and printed a SessionStart object) and
`FAIL  no config sync ran: main still behind` (`expected '2', got '0'`); ends `0 passed, 2 failed`.

- [ ] **Step 3: Collect reports instead of printing them**

In `hooks/git-freshness.sh`, replace the globals

```bash
config_notes=""
config_tags=""
emitted=""
```

with

```bash
config_notes=""
config_tags=""
repo_context=""
repo_summary=""
```

In `emit`, delete the line `    emitted=1`, and change its leading comment to:

```bash
# Print this invocation's one hook JSON object: the repo reports collected in
# repo_context/repo_summary, plus whatever sync_config_repos found, because
# Claude Code reads a single object per hook run.
```

Directly below `emit`, add:

```bash
# Add one repo's report to this invocation's single emit(). Reports are joined
# by a blank line, summaries by a space.
add_report() {
    local context=$1 summary=$2

    repo_context="${repo_context}${repo_context:+

}${context}"
    [ -z "$summary" ] || repo_summary="${repo_summary}${repo_summary:+ }${summary}"
}
```

In `check_repo`: change its comment to

```bash
# Report on the repo containing $1 into repo_context/repo_summary, which the
# mode emits. Adds nothing when the path is not a git repo with an origin.
```

change `    local target=$1 event=$2` to `    local target=$1`, change

```bash
        emit "$event" \
            "git freshness: $(basename "$target") on '$branch' — nothing incoming that affects this work (fetched $(human_age "$age"))." \
            ""
```

to

```bash
        add_report \
            "git freshness: $(basename "$target") on '$branch' — nothing incoming that affects this work (fetched $(human_age "$age"))." \
            ""
```

and change its last line `    emit "$event" "$context" "$summary"` to `    add_report "$context" "$summary"`.

- [ ] **Step 4: Emit from the modes, and stop on an unknown mode**

Replace the `edit)` and `session | *)` branches of the `case` with:

```bash
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

        check_repo "$toplevel"
        [ -z "$repo_context" ] || emit PostToolUse "$repo_context" "$repo_summary"
        ;;

    session)
        # Nothing has been edited yet, so the session's own cwd is all we have.
        repo=$(payload_field cwd)
        [ -n "$repo" ] && [ -d "$repo" ] || repo="$PWD"

        sync_config_repos
        remind_retired_vault
        check_repo "$repo"

        # check_repo adds nothing outside a git repo; config news still gets out.
        [ -z "$repo_context$config_notes$config_tags" ] \
            || emit SessionStart "$repo_context" "$repo_summary"
        ;;

    *)
        exit 0
        ;;
```

(`edit` is rewritten again in Task 2; this step only moves its emit.) `mode` still defaults to `session` without an
argument (`mode="${1:-session}"`, unchanged).

- [ ] **Step 5: Run both suites**

Run: `bash -n hooks/git-freshness.sh && bash hooks/tests/git-freshness-touch.test.sh && bash hooks/tests/git-freshness-sync.test.sh`
Expected: touch suite `2 passed, 0 failed`; sync suite ends `… passed, 0 failed` (case 10 still sees
`fast-forwarded main` and `"systemMessage"`, case 13 still sees `nothing incoming that affects this work` beside
`skills from 'feature'`).

- [ ] **Step 6: Commit**

```bash
git add hooks/git-freshness.sh hooks/tests/git-freshness-touch.test.sh
git commit -m "refactor(hooks): git-freshness collects repo reports and emits once per mode; an unknown mode does nothing (#60)"
```

---

### Task 2: the `touch` mode for path-bearing tools, with markers shared by `edit` and `session`

**Files:**
- Modify: `hooks/git-freshness.sh` (new functions above `check_repo`; the `case`)
- Test: `hooks/tests/git-freshness-touch.test.sh` (cases 2–7)

**Interfaces:**
- Consumes: `check_repo <path>`, `add_report`, `repo_context`/`repo_summary`, `emit <event> <context> <summary>`
  (Task 1); `payload_field <key>` (existing).
- Produces:
  - `absolute_path <path> <dir>` — prints `<path>` when absolute, else `<dir>/<path>`.
  - `repo_toplevel <path>` — prints the `--show-toplevel` of the path (itself when a directory, else its `dirname`);
    status 1 and no output when the path does not exist or is in no repo.
  - `claim_repo <toplevel>` — status 0 only for the first claim of that repo in this session (atomic `mkdir`).
  - `touch_targets` — prints the paths the payload's tool call acts on, one per line. Task 3 replaces its `Bash`
    line.
  - `check_first_touches <event> <paths, one per line>` — claims and checks each new repo, then emits one object.

- [ ] **Step 1: Write cases 2–7**

Add to `hooks/tests/git-freshness-touch.test.sh`, after case 1:

```bash
# ---------------------------------------------------------------------------
echo "case 2: the first touch checks the repo, a repeat touch is silent"
repo=$(fixture firsttouch 0)
first=$(run_hook touch "$(read_payload t-first "$repo/app.php")")
second=$(run_hook touch "$(read_payload t-first "$repo/app.php")")
one_json_line "$first" "first touch"
contains "$first" '"hookEventName":"PreToolUse"' "first touch declares PreToolUse"
contains "$first" "nothing incoming that affects this work" "first touch reports the repo"
lacks "$first" "permissionDecision" "the permission flow is left alone"
is "$second" "" "second touch in the same repo prints nothing"
echo

# ---------------------------------------------------------------------------
echo "case 3: a first Read fast-forwards a clean main"
repo=$(fixture ffread 2)
out=$(run_hook touch "$(read_payload t-ffread "$repo/app.php")")
is "$(git -C "$repo" rev-list --count main..origin/main)" "0" "main equals origin/main"
is "$(tail -1 "$repo/app.php")" "upstream 2" "the file on disk holds the new content"
is "$(git -C "$repo" status --porcelain | wc -l | tr -d ' ')" "0" "working tree clean"
contains "$out" "fast-forwarded main" "the sync is reported"
echo

# ---------------------------------------------------------------------------
echo "case 4: a slot worktree is its own repo"
repo=$(fixture slotted 2)
git -C "$repo" worktree add -q "$root/slotted/slot" -b slot
primary=$(run_hook touch "$(read_payload t-slot "$repo/app.php")")
slot=$(run_hook touch "$(read_payload t-slot "$root/slotted/slot/app.php")")
contains "$primary" "fast-forwarded main" "the primary is checked: its clean main is fast-forwarded"
one_json_line "$slot" "slot touch"
contains "$slot" "on 'slot'" "the slot is reported under its own branch: the primary's marker does not silence it"
echo

# ---------------------------------------------------------------------------
echo "case 5: no fetch for a repo the session never touches"
touched=$(fixture fetched 0)
untouched=$(fixture notfetched 0)
age_fetch_head "$touched"
age_fetch_head "$untouched"
touched_head="$(git -C "$touched" rev-parse --absolute-git-dir)/FETCH_HEAD"
untouched_head="$(git -C "$untouched" rev-parse --absolute-git-dir)/FETCH_HEAD"
aged_touched=$(file_mtime "$touched_head")
aged_untouched=$(file_mtime "$untouched_head")
run_hook touch "$(read_payload t-fetch "$touched/app.php")" >/dev/null
if [ "$(file_mtime "$touched_head")" -gt "$aged_touched" ]; then ok "the touched repo was fetched"; else fail "the touched repo was fetched"; fi
is "$(file_mtime "$untouched_head")" "$aged_untouched" "the untouched repo was not fetched"
echo

# ---------------------------------------------------------------------------
echo "case 6: which paths a tool call names"
repo=$(fixture tools 0)
out=$(run_hook touch "$(tool_payload t-glob Glob "$root/plain" "$(printf '{"pattern":"*.php","path":"%s"}' "$repo")")")
contains "$out" "nothing incoming" "Glob checks its path"
out=$(run_hook touch "$(tool_payload t-grep Grep "$repo" '{"pattern":"base"}')")
contains "$out" "nothing incoming" "Grep without a path checks the cwd"
out=$(run_hook touch "$(tool_payload t-nb NotebookEdit "$root/plain" "$(printf '{"notebook_path":"%s/n.ipynb","new_source":"x"}' "$repo")")")
contains "$out" "nothing incoming" "NotebookEdit checks its notebook_path (a file that does not exist yet: its dir)"
out=$(run_hook touch "$(tool_payload t-write Write "$root/plain" "$(printf '{"file_path":"%s/new.php","content":"x = {\\"file_path\\":\\"%s/app.php\\"}"}' "$repo" "$root/plain")")")
contains "$out" "nothing incoming" "Write checks its file_path, not one quoted inside its content"
out=$(run_hook touch "$(tool_payload t-web WebFetch "$repo" '{"url":"https://example.com"}')")
is "$out" "" "a tool without a path prints nothing, even with a cwd in a repo"
out=$(run_hook touch "$(read_payload t-plain "$root/plain/nothing.txt")")
is "$out" "" "a path in no repo prints nothing"
git init -q "$root/noorigin"
echo x > "$root/noorigin/f.txt"
first=$(run_hook touch "$(read_payload t-noorigin "$root/noorigin/f.txt")")
is "$first" "" "a repo without an origin prints nothing"
is "$(ls "$TMPDIR/claude-git-freshness/t-noorigin" | grep -c .)" "1" "but its marker is claimed, so it is not retried"
echo

# ---------------------------------------------------------------------------
echo "case 7: touch, edit and session share the markers"
repo=$(fixture shared 0)
first=$(run_hook touch "$(read_payload t-shared "$repo/app.php")")
edit=$(run_hook edit "{\"session_id\":\"t-shared\",\"file_path\":\"$repo/app.php\"}")
contains "$first" "nothing incoming" "touch claims the repo"
is "$edit" "" "the legacy edit hook after touch is a no-op"
repo=$(fixture sessionclaim 0)
mkdir -p "$repo/sub"
sess=$(run_hook session "{\"session_id\":\"t-session\",\"cwd\":\"$repo/sub\"}")
touch_after=$(run_hook touch "$(read_payload t-session "$repo/app.php")")
contains "$sess" '"hookEventName":"SessionStart"' "session checks its launch repo"
is "$touch_after" "" "the first touch after a session launched in that repo (a subdirectory) is silent"
again=$(run_hook session "{\"session_id\":\"t-session\",\"cwd\":\"$repo\"}")
contains "$again" "nothing incoming" "a second SessionStart (resume, /clear) still checks"
echo
```

- [ ] **Step 2: Run it and see it fail**

Run: `bash hooks/tests/git-freshness-touch.test.sh`
Expected: case 1 passes; cases 2–6 fail because `touch` is still an unknown mode that prints nothing (e.g.
`FAIL  first touch: exactly one line`, `FAIL  main equals origin/main` with `got '2'`, `FAIL  the touched repo was
fetched`). In case 7, `FAIL  touch claims the repo`; its two "is silent" / "no-op" lines pass for now only because
`touch` prints nothing at all, and they start to mean something once Step 4 is in. Ends `… passed, N failed` with
N > 0.

- [ ] **Step 3: Add the path and marker functions**

In `hooks/git-freshness.sh`, directly above the `# Report on the repo containing $1 …` comment of `check_repo`, add:

```bash
# Path $1 as an absolute path, a relative one taken against directory $2.
absolute_path() {
    case "$1" in
        /*) printf '%s\n' "$1" ;;
        *)  printf '%s/%s\n' "$2" "$1" ;;
    esac
}

# The repo path $1 is in: its --show-toplevel. A path that is no directory is
# looked up by its dirname, so a file about to be written still finds its repo.
# Prints nothing, status 1, when the path does not exist or is in no repo. Each
# worktree, a slot included, is its own toplevel.
repo_toplevel() {
    local dir=$1

    [ -d "$dir" ] || dir=$(dirname "$dir")
    [ -d "$dir" ] || return 1
    git -C "$dir" rev-parse --show-toplevel 2>/dev/null
}

# Claim repo $1 for this session: true the first time only. mkdir is atomic, so
# of two tool calls racing into one repo exactly one wins, and a marker left as
# a file by an older edit mode blocks it as well. The claim comes before the
# check, so a repo that fails it (no origin, a dead network) is not retried.
claim_repo() {
    mkdir -p "$cache_dir" 2>/dev/null || return 1
    mkdir "$cache_dir/$(printf '%s' "$1" | tr -c 'A-Za-z0-9._-' '_')" 2>/dev/null
}

# The paths the payload's tool call acts on, one per line. Tools that carry no
# path (WebFetch, MCP tools, Task tools) name none.
touch_targets() {
    local cwd path

    cwd=$(payload_field cwd)
    case "$(payload_field tool_name)" in
        Read | Edit | Write | MultiEdit) path=$(payload_field file_path) ;;
        NotebookEdit)                    path=$(payload_field notebook_path) ;;
        Glob | Grep)                     path=$(payload_field path); path=${path:-$cwd} ;;
        Bash)                            path=$cwd ;;
        *)                               return 0 ;;
    esac

    [ -z "$path" ] || absolute_path "$path" "$cwd"
}

# Check each repo among paths $2 (one per line) that this session has not
# claimed yet, then print one $1 hook object for all of them, or nothing.
check_first_touches() {
    local event=$1 target toplevel

    while IFS= read -r target; do
        [ -n "$target" ] || continue
        toplevel=$(repo_toplevel "$target") || continue
        [ -n "$toplevel" ] || continue
        claim_repo "$toplevel" || continue
        check_repo "$toplevel"
    done <<< "$2"

    [ -z "$repo_context" ] || emit "$event" "$repo_context" "$repo_summary"
}
```

`payload_field path` matches only the key `"path"` (its pattern starts at the quote), so `"file_path"`,
`"notebook_path"` and `"transcript_path"` never match it; an escaped `\"file_path\"` inside tool content never
matches `"file_path"` either (case 6 pins it).

- [ ] **Step 4: Wire the modes**

Replace the `edit)` branch from Task 1 with:

```bash
    touch)
        check_first_touches PreToolUse "$(touch_targets)"
        ;;

    edit)
        # The legacy PostToolUse wiring: the same check on the written file's
        # repo, sharing touch's markers, so with both wired it is a no-op. It
        # reads file_path without tool_name, as it always has.
        check_first_touches PostToolUse "$(payload_field file_path)"
        ;;
```

In the `session)` branch, replace `        check_repo "$repo"` with:

```bash
        # Mark the launch repo as touched, so its first tool call does not
        # report it again. A resumed or cleared session checks regardless.
        toplevel=$(repo_toplevel "$repo") && claim_repo "$toplevel"
        check_repo "${toplevel:-$repo}"
```

(`repo` is absolute here: the payload's `cwd` or `$PWD`, captured before `sync_config_repos` changes directory.)

- [ ] **Step 5: Run both suites**

Run: `bash -n hooks/git-freshness.sh && bash hooks/tests/git-freshness-touch.test.sh && bash hooks/tests/git-freshness-sync.test.sh`
Expected: touch suite `… passed, 0 failed`; sync suite `… passed, 0 failed` (its case 11 still prints a
`PostToolUse` object first and nothing second).

- [ ] **Step 6: Commit**

```bash
git add hooks/git-freshness.sh hooks/tests/git-freshness-touch.test.sh
git commit -m "feat(hooks): git-freshness touch mode checks a repo on the session's first tool call in it (#60)"
```

---

### Task 3: Bash calls are checked where their command works

**Files:**
- Modify: `hooks/git-freshness.sh` (new functions after `payload_field`; the `Bash` line of `touch_targets`)
- Test: `hooks/tests/git-freshness-touch.test.sh` (case 8)

**Interfaces:**
- Consumes: `absolute_path`, `touch_targets`, `check_first_touches` (Task 2).
- Produces:
  - `json_unescape` (stdin → stdout) — `\"`, `\\`, `\/` become the character, `\n` a newline, `\t` a tab.
  - `payload_string <key>` — the string value of `<key>`, JSON escapes respected, unescaped.
  - `bash_words` (stdin → stdout) — each word after `cd` or `git -C` at a command boundary, as written.
  - `resolve_word <word> <cwd>` — the absolute directory a word names; status 1 for a word with `$` or a backtick.
  - `bash_targets <cwd>` — the existing directories the command names, else `<cwd>`.

- [ ] **Step 1: Write case 8**

Add after case 7:

```bash
# ---------------------------------------------------------------------------
echo "case 8: Bash calls check the directories their command names, else the cwd"
# Every fixture's checkout is called work, so each gets its own branch name for
# the assertions to tell the reports apart: "work on 'bashone'".
repo=$(fixture bashone 0)
two=$(fixture bashtwo 0)
git -C "$repo" checkout -q -b bashone
git -C "$two" checkout -q -b bashtwo
bash_payload() { # bash_payload <session> <cwd> <command, JSON-escaped>
    tool_payload "$1" Bash "$2" "$(printf '{"command":"%s","description":"x"}' "$3")"
}
out=$(run_hook touch "$(bash_payload t-bash1 "$root/plain" "git -C $repo status")")
contains "$out" "bashone" "git -C <repo> checks that repo from a cwd in no repo"
out=$(run_hook touch "$(bash_payload t-bash2 "$root/plain" "cd \\\"$repo\\\" && ls")")
contains "$out" "bashone" "cd \"<repo>\" (escaped quotes in the JSON) checks that repo"
out=$(run_hook touch "$(bash_payload t-bash3 "$repo" "ls -la")")
contains "$out" "bashone" "a command naming no directory checks the cwd"
out=$(run_hook touch "$(bash_payload t-bash4 "$root/plain" "ls -la")")
is "$out" "" "a command naming no directory, from a cwd in no repo, prints nothing"
out=$(run_hook touch "$(bash_payload t-bash5 "$repo" "cd \$WORKTREE && make")")
contains "$out" "bashone" "a variable falls back to the cwd"
out=$(run_hook touch "$(bash_payload t-bash5b "$repo" "cd \\\"$root/plain/a b\\\" && ls")")
contains "$out" "bashone" "a quoted path with a space (not resolved) falls back to the cwd"
out=$(printf '%s' "$(bash_payload t-bash6 "$root/plain" "ls\\n(cd ../plain; git -C ~/bashone/work log)")" \
    | HOME="$root" bash "$hook" touch 2>/dev/null)
contains "$out" "bashone" "a relative cd and a ~ path inside a subshell, on the line after an escaped newline"
out=$(run_hook touch "$(bash_payload t-bash7 "$root/plain" "git -C $repo status && git -C $two status")")
one_json_line "$out" "two repos in one command"
contains "$out" "bashone" "the first repo is checked"
contains "$out" "bashtwo" "the second repo is checked"
out=$(run_hook touch "$(bash_payload t-bash8 "$repo" "git -C $two status")")
contains "$out" "bashtwo" "the named repo is checked"
lacks "$out" "bashone" "the cwd repo is not checked when the command names a directory"
echo
```

In `t-bash6`, `HOME` is set to `$root` for that one hook run (git's config is already pinned by
`GIT_CONFIG_GLOBAL`, and every `GIT_FRESHNESS_*` path is overridden), so `~/bashone/work` is the fixture. The
`cd ../plain` word resolves to `$root/plain`, which exists and is in no repo: it counts as a named directory (so the
cwd is not added) and is dropped at the repo lookup, and the check comes from the `git -C` word.

- [ ] **Step 2: Run it and see it fail**

Run: `bash hooks/tests/git-freshness-touch.test.sh`
Expected: cases 1–7 pass; case 8 fails on every line whose cwd is `$root/plain` (`FAIL  git -C <repo> checks that
repo …`, `FAIL  the second repo is checked`) and on `FAIL  the named repo is checked` / `FAIL  the cwd repo is not
checked …` (today Bash checks the cwd only).

- [ ] **Step 3: Add the parsing functions**

In `hooks/git-freshness.sh`, directly below `payload_field`, add:

```bash
# Undo a JSON string's escapes: \" \\ \/ become the character, \n and \t a
# newline and a tab. \uXXXX is left as uXXXX: no path we resolve needs it.
json_unescape() {
    awk '{
        out = ""
        for (i = 1; i <= length($0); i++) {
            c = substr($0, i, 1)
            if (c == "\\" && i < length($0)) {
                i++
                c = substr($0, i, 1)
                if (c == "n") c = "\n"
                else if (c == "t") c = "\t"
            }
            out = out c
        }
        print out
    }'
}

# A string field of the payload with its JSON escapes respected, which
# payload_field does not do: a Bash command routinely holds quotes.
payload_string() {
    printf '%s' "$payload" \
        | sed -nE 's/.*"'"$1"'"[[:space:]]*:[[:space:]]*"((\\.|[^"\\])*)".*/\1/p' \
        | head -1 \
        | json_unescape
}

# Each word that follows `cd` or `git -C` at a command boundary (line start,
# ; & | ( or whitespace), up to whitespace, ; & | or ), one per line, as
# written. awk's match() rather than grep -o, whose handling of a ^ inside an
# alternation differs between BSD and GNU.
bash_words() {
    awk '{
        line = " " $0
        while (match(line, /[;&|( \t](cd|git[ \t]+-C)[ \t]+[^ \t;&|)]+/)) {
            word = substr(line, RSTART + 1, RLENGTH - 1)
            sub(/^(cd|git[ \t]+-C)[ \t]+/, "", word)
            print word
            line = substr(line, RSTART + RLENGTH)
        }
    }'
}

# The absolute path a word from a Bash command names: surrounding quotes
# stripped, a leading ~ as $HOME, a relative path against directory $2. Status
# 1 for a word only the shell could resolve (a variable, a substitution).
resolve_word() {
    local word=$1

    word=${word#\"}
    word=${word#\'}
    word=${word%\"}
    word=${word%\'}
    case "$word" in
        '' | *'$'* | *'`'*) return 1 ;;
        '~')                word=$HOME ;;
        '~/'*)              word="$HOME/${word#'~/'}" ;;
    esac

    absolute_path "$word" "$2"
}

# The directories a Bash call works in: every existing one its command names
# with cd or git -C, else directory $1, the call's cwd. A word that does not
# resolve is a miss, visible as a missing freshness line.
bash_targets() {
    local cwd=$1 word dir found=""

    while IFS= read -r word; do
        [ -n "$word" ] || continue
        dir=$(resolve_word "$word" "$cwd") || continue
        [ -d "$dir" ] || continue
        printf '%s\n' "$dir"
        found=1
    done <<< "$(payload_string command | bash_words)"

    [ -n "$found" ] || printf '%s\n' "$cwd"
}
```

In `touch_targets`, replace the line

```bash
        Bash)                            path=$cwd ;;
```

with

```bash
        Bash)                            bash_targets "$cwd"; return 0 ;;
```

- [ ] **Step 4: Run both suites**

Run: `bash -n hooks/git-freshness.sh && bash hooks/tests/git-freshness-touch.test.sh && bash hooks/tests/git-freshness-sync.test.sh`
Expected: both `… passed, 0 failed`.

- [ ] **Step 5: Commit**

```bash
git add hooks/git-freshness.sh hooks/tests/git-freshness-touch.test.sh
git commit -m "feat(hooks): git-freshness checks the repos a Bash command names with cd or git -C (#60)"
```

---

### Task 4: the working-branch line, skipped for a `/pipeline` run's own branch

**Files:**
- Modify: `hooks/git-freshness.sh` (`check_repo` replaced; new functions above it)
- Test: `hooks/tests/git-freshness-touch.test.sh` (cases 9–13)

**Interfaces:**
- Consumes: `add_report` (Task 1); `sync_base_branch`, `classify_incoming`, `predict_conflicts`, `resolve_base_ref`,
  `count_lines`, `human_age`, `newest_fetch_mtime`, `fetch_if_stale` (existing, unchanged here).
- Produces (all act on the current directory's repo and the globals `insights`, `tags`, `headline`):
  - `runs_pipeline <branch>` — status 0 when `<toplevel>/.claude/pipeline/<branch with / as ->.json` exists.
  - `report_behind_base <name> <branch, empty when detached> <base_ref>` — sets `headline` and the leading tag
    `N behind <base_ref>`, and adds the consequences.
  - `report_consequences <base_ref>` — today's migrations / operations / lockfiles / `.env.example` / conflicts block.
  - `report_pushed_elsewhere <upstream>` — today's "pushed to this branch elsewhere" line.
  - `add_repo_report <name> <branch label> <age>` — composes context and summary and calls `add_report`. Task 5 adds
    the fetch paragraph to it.

- [ ] **Step 1: Write cases 9–13**

Add after case 8:

```bash
# ---------------------------------------------------------------------------
echo "case 9: a behind feature branch is reported once"
repo=$(fixture behind 2)
git -C "$repo" checkout -q -b feature
echo mine > "$repo/local.php"
git -C "$repo" add local.php
git -C "$repo" commit -qm "local work"
first=$(run_hook touch "$(read_payload t-behind "$repo/local.php")")
second=$(run_hook touch "$(tool_payload t-behind Grep "$repo" '{"pattern":"x"}')")
one_json_line "$first" "behind branch"
contains "$first" "Stale checkout: work on 'feature' is 2 commit(s) behind origin/main." "the working-branch line"
contains "$first" "Raise this with the user" "the raise-and-wait instruction"
contains "$first" '"systemMessage":"work '"'"'feature'"'"': 2 behind origin/main.' "the on-screen line"
lacks "$first" "to merge by hand" "no conflict when no shared file moved"
is "$second" "" "a later touch stays silent"
echo

# ---------------------------------------------------------------------------
echo "case 10: a dirty main is left alone and still reported behind"
repo=$(fixture dirtymain 2)
echo "work in progress" >> "$repo/app.php"
before_sum=$(checksum "$repo/app.php")
before_main=$(git -C "$repo" rev-parse main)
out=$(run_hook touch "$(read_payload t-dirty "$repo/app.php")")
is "$(git -C "$repo" rev-parse main)" "$before_main" "main did not move"
is "$(checksum "$repo/app.php")" "$before_sum" "the uncommitted change is kept byte for byte"
contains "$out" "uncommitted" "the sync note says why main was left alone"
contains "$out" "work on 'main' is 2 commit(s) behind origin/main" "and main is reported behind"
echo

# ---------------------------------------------------------------------------
echo "case 11: a main with local commits, tracking origin/main, behind"
repo=$(fixture localmain 2 composer.lock)
echo "mine" >> "$repo/app.php"
git -C "$repo" commit -qam "local work on main"
out=$(run_hook touch "$(read_payload t-localmain "$repo/app.php")")
contains "$out" "work on 'main' is 2 commit(s) behind origin/main" "the working-branch line"
contains "$out" "local commit(s)" "the sync note: not a fast-forward"
contains "$out" "composer.lock moved" "the consequences are reported"
contains "$out" "to merge by hand" "the conflict on app.php is predicted"
lacks "$out" "pushed to this branch" "no second line saying the same thing"
echo

# ---------------------------------------------------------------------------
echo "case 12: a /pipeline run's own branch gets no raise-and-wait"
repo=$(fixture piperun 2)
git -C "$repo" checkout -q -b run/feature
mkdir -p "$repo/.claude/pipeline"
echo '{}' > "$repo/.claude/pipeline/run-feature.json"
out=$(run_hook touch "$(read_payload t-pipe "$repo/app.php")")
lacks "$out" "commit(s) behind" "no working-branch line"
lacks "$out" "Raise this with the user" "no raise-and-wait"
contains "$out" "fast-forwarded main" "the base sync still reports"
rm "$repo/.claude/pipeline/run-feature.json"
out=$(run_hook touch "$(read_payload t-pipe-control "$repo/app.php")")
contains "$out" "work on 'run/feature' is 2 commit(s) behind origin/main" "without its manifest the same branch is reported"
echo

# ---------------------------------------------------------------------------
echo "case 13: a detached HEAD names no branch to bring up"
repo=$(fixture detached 2)
git -C "$repo" checkout -q --detach HEAD
out=$(run_hook touch "$(read_payload t-detached "$repo/app.php")")
lacks "$out" "commit(s) behind" "no working-branch line"
lacks "$out" "Raise this with the user" "no raise-and-wait"
contains "$out" "fast-forwarded main" "main, checked out nowhere, is fast-forwarded as a ref"
echo
```

- [ ] **Step 2: Run it and see it fail**

Run: `bash hooks/tests/git-freshness-touch.test.sh`
Expected: cases 1–8 pass; fails include `FAIL  the working-branch line` (cases 9, 10, 11, 12's control),
`FAIL  the on-screen line`, and in case 11 `FAIL  no second line saying the same thing` (today it reports
`origin/main has 2 commit(s) not in this checkout — another machine or slot pushed to this branch`).

- [ ] **Step 3: Replace `check_repo` and add its helpers**

In `hooks/git-freshness.sh`, replace the whole of `check_repo` (its comment through its closing `}`) with:

```bash
# Is branch $1 a /pipeline run's own branch? Its manifest sits in the worktree at
# the path manifest_path() in skills/pipeline/checks/manifest.php builds, and the
# run's own code decides whether a step merges the base (pipeline engine.md
# §Catching up with the base): telling every step agent to raise it and wait on
# its first Read would contradict the brief it runs on.
runs_pipeline() {
    local toplevel

    toplevel=$(git rev-parse --show-toplevel 2>/dev/null) || return 1
    [ -f "$toplevel/.claude/pipeline/$(printf '%s' "$1" | tr '/' '-').json" ]
}

# What catching up with base $1 costs the local work: migrations, lockfiles,
# files to merge by hand. Only work to protect earns it: a branch with no local
# commits and a clean tree catches up as a plain fast-forward.
report_consequences() {
    local base_ref=$1 ahead dirty mb conflicts n_conf shown

    ahead=$(git rev-list --count "$base_ref..HEAD" 2>/dev/null || echo 0)
    dirty=$(git status --porcelain 2>/dev/null | grep -c . || true)
    [ "${ahead:-0}" -gt 0 ] || [ "${dirty:-0}" -gt 0 ] || return 0

    mb=$(git merge-base HEAD "$base_ref" 2>/dev/null)
    [ -n "$mb" ] || return 0
    classify_incoming "$mb" "$base_ref"

    # No local commits means no divergence, so nothing can conflict.
    [ "${ahead:-0}" -gt 0 ] || return 0
    conflicts=$(predict_conflicts "$mb" "$base_ref")
    n_conf=$(count_lines "$conflicts")
    [ "${n_conf:-0}" -gt 0 ] || return 0

    shown=$(printf '%s\n' "$conflicts" | head -"$max_listed_files" | sed 's/^/      /')
    insights="${insights}
  - catching up would need manual merging in ${n_conf} file(s):
${shown}"
    if [ "$n_conf" -gt "$max_listed_files" ]; then
        insights="${insights}
      (+$((n_conf - max_listed_files)) more)"
    fi
    tags="${tags}${tags:+, }${n_conf} to merge by hand"
}

# The working branch against base $3, after the base sync. A branch behind it
# gets the headline with its count, then its consequences; a detached HEAD
# ($2 empty) names no branch to bring up, so it gets the consequences only; a
# /pipeline run's own branch gets neither.
report_behind_base() {
    local name=$1 branch=$2 base_ref=$3 behind

    [ -n "$base_ref" ] || return 0
    if [ -n "$branch" ] && runs_pipeline "$branch"; then
        return 0
    fi

    behind=$(git rev-list --count "HEAD..$base_ref" 2>/dev/null || echo 0)
    [ "${behind:-0}" -gt 0 ] || return 0

    if [ -n "$branch" ]; then
        headline="Stale checkout: $name on '$branch' is $behind commit(s) behind $base_ref."
        tags="${tags}${tags:+, }$behind behind $base_ref"
    fi
    report_consequences "$base_ref"
}

# Someone pushed to *this* branch: another machine, or another slot, is ahead
# of this checkout. Always worth knowing and always actionable.
report_pushed_elsewhere() {
    local upstream=$1 behind

    [ -n "$upstream" ] || return 0
    behind=$(git rev-list --count "HEAD..$upstream" 2>/dev/null || echo 0)
    [ "${behind:-0}" -gt 0 ] || return 0

    insights="${insights}
  - $upstream has $behind commit(s) not in this checkout — another machine or slot pushed to this branch; pull before continuing"
    tags="${tags}${tags:+, }branch pushed elsewhere"
}

# Fold this repo's findings into one report: the headline (or the consequences'
# header) with the raise-and-wait instruction, then the notes that ask for no
# decision. A repo with nothing to say gets one quiet line of context.
add_repo_report() {
    local name=$1 branch=$2 age=$3 context="" summary=""

    if [ -n "$headline$insights" ]; then
        context="${headline:-Stale checkout with consequences: $name on '$branch'}${insights}
Last fetch: $age.

Do NOT pull, rebase, or merge on your own initiative. Raise this with the user
before working in this repo and wait for their decision: bring the branch up to
date, or deliberately continue on the current base."
    fi

    # A sync that changed nothing anyone has to act on is still recorded, so
    # the reason main moved is never a mystery; it just earns no line on screen.
    if [ -n "$sync_notes" ]; then
        context="${context}${context:+

}Base branch sync: $name${sync_notes}"
    fi

    if [ -z "$context" ]; then
        add_report "git freshness: $name on '$branch' — nothing incoming that affects this work (fetched $age)." ""
        return 0
    fi

    [ -z "$tags" ] || summary="$name '$branch': $tags."
    if [ -n "$sync_tags" ] && [ -n "$summary" ]; then
        summary="$summary Also: $sync_tags."
    elif [ -n "$sync_tags" ]; then
        summary="$name: $sync_tags."
    fi

    add_report "$context" "$summary"
}

# Report on the repo containing $1 into repo_context/repo_summary, which the
# mode emits. Adds nothing when the path is not a git repo with an origin.
check_repo() {
    local target=$1

    cd "$target" 2>/dev/null || return 0
    git rev-parse --git-dir >/dev/null 2>&1 || return 0
    git remote get-url origin >/dev/null 2>&1 || return 0

    fetch_if_stale "$max_fetch_seconds"

    local name age branch upstream base_ref
    name=$(basename "$target")
    age=$(human_age $(( $(date +%s) - $(newest_fetch_mtime) )))
    branch=$(git symbolic-ref --short -q HEAD 2>/dev/null || echo "")
    upstream=$(git rev-parse --abbrev-ref --symbolic-full-name '@{u}' 2>/dev/null || echo "")
    base_ref=$(resolve_base_ref)

    headline=""
    insights=""
    tags=""
    sync_notes=""
    sync_tags=""

    # Catch the local base branch up first, so the count below, and a branch
    # cut later in this session, measure against the right base.
    sync_base_branch "$base_ref"
    report_behind_base "$name" "$branch" "$base_ref"

    # A branch whose upstream is the base already got the behind line.
    [ "$upstream" = "$base_ref" ] || report_pushed_elsewhere "$upstream"

    add_repo_report "$name" "${branch:-(detached HEAD)}" "$age"
}
```

`report_pushed_elsewhere` counts `HEAD..$upstream`: the same number as the left side of the old
`git rev-list --left-right --count "$upstream...HEAD"`. `headline` and `insights` are globals like `tags`, reset at
the top of every `check_repo`, which matters under `set -u` and when one Bash call checks two repos.

- [ ] **Step 4: Rewrite the header's reporting rationale**

Replace the header paragraph that starts `# What it deliberately does NOT report: the commit count.` (through
`# nothing at all.`) with:

```bash
# What it reports: a working branch behind its base gets one line with the
# count. A checked repo is a repo about to be worked in, and new work on a
# stale base is the cost, whether or not the branch has commits of its own yet.
# What makes that line actionable are the consequences under it: migrations
# your dev database is missing, lockfiles that moved, files you will have to
# merge by hand. A /pipeline run's own branch gets neither, because the run's
# code decides when it merges its base. A repo that is current says so in one
# quiet line of context and nothing on screen.
```

- [ ] **Step 5: Run both suites**

Run: `bash -n hooks/git-freshness.sh && bash hooks/tests/git-freshness-touch.test.sh && bash hooks/tests/git-freshness-sync.test.sh`
Expected: both `… passed, 0 failed`. In the sync suite, case 10 (on `main`, fast-forwarded) and case 13 (up to date)
get no behind line, so they read as before.

- [ ] **Step 6: Commit**

```bash
git add hooks/git-freshness.sh hooks/tests/git-freshness-touch.test.sh
git commit -m "feat(hooks): git-freshness reports a working branch behind its base, except a pipeline run's own (#60)"
```

---

### Task 5: a fetch that does not finish says "freshness unknown"

**Files:**
- Modify: `hooks/git-freshness.sh` (`fetch_if_stale`; `check_repo`; `add_repo_report`; a new `report_fetch_failed`)
- Test: `hooks/tests/git-freshness-touch.test.sh` (case 14)

**Interfaces:**
- Consumes: `check_repo`, `add_repo_report` (Task 4).
- Produces: `fetch_if_stale <cap>` returns 1 when it ran a fetch that was killed at its cap or exited non-zero, 0
  otherwise (no fetch needed, or a fetch that succeeded); `report_fetch_failed <age>` sets the global `fetch_note`
  and adds the tag `freshness unknown`.

- [ ] **Step 1: Write case 14**

Add after case 13:

```bash
# ---------------------------------------------------------------------------
echo "case 14: a fetch that does not finish reports freshness unknown"
repo=$(fixture offline 0)
git -C "$repo" remote set-url origin "$root/offline/missing.git"
age_fetch_head "$repo"
out=$(run_hook touch "$(read_payload t-offline "$repo/app.php")")
one_json_line "$out" "failed fetch"
contains "$out" "Fetch from origin did not finish (timed out after 10s, or failed); freshness unknown" "the fetch line"
contains "$out" '"systemMessage":"work '"'"'main'"'"': freshness unknown.' "the on-screen tag"
lacks "$out" "Raise this with the user" "no raise-and-wait without a behind line"
lacks "$out" "nothing incoming" "not reported as current"
echo
```

- [ ] **Step 2: Run it and see it fail**

Run: `bash hooks/tests/git-freshness-touch.test.sh`
Expected: cases 1–13 pass; case 14 `FAIL  the fetch line`, `FAIL  the on-screen tag`, `FAIL  not reported as
current` (today a failed fetch reads as `nothing incoming that affects this work`).

- [ ] **Step 3: Make `fetch_if_stale` say whether its fetch finished**

Replace the tail of `fetch_if_stale`, from `    wait "$fetch_pid" 2>/dev/null` through its closing `}`, with:

```bash
    wait "$fetch_pid" 2>/dev/null || return 1

    # Re-point origin/HEAD at the remote's real default branch. This symref
    # is cached at clone time and goes stale silently — a clone made when
    # `develop` was default still claims `develop` years after the repo
    # moved to `main`, which would have us measure against the wrong branch.
    # Skipped after a failed fetch: it is a second network call, uncapped.
    git remote set-head origin --auto >/dev/null 2>&1
    return 0
}
```

and change its comment to:

```bash
# Fetch origin in the current repo, unless it fetched within the TTL. The network
# call is capped at $1 seconds: a dead connection must not hang the session.
# Returns 1 when a fetch ran and did not finish (killed at the cap, offline,
# refused), so the caller can say its answer is only as fresh as the last fetch.
```

A killed `git fetch` makes `wait` return 143 and a failed one returns its own non-zero exit: both return 1.
`sync_config_repos` runs it in a background subshell and never reads the status, so session start is unchanged.

- [ ] **Step 4: Report it**

Directly above `add_repo_report`, add:

```bash
# The fetch did not finish: the report stands, but only for the last fetch.
report_fetch_failed() {
    fetch_note="Fetch from origin did not finish (timed out after ${max_fetch_seconds}s, or failed); freshness unknown, measured against the last fetch ($1)."
    tags="${tags}${tags:+, }freshness unknown"
}
```

In `add_repo_report`, directly after the `if [ -n "$headline$insights" ]; then … fi` block, add:

```bash
    if [ -n "$fetch_note" ]; then
        context="${context}${context:+

}${fetch_note}"
    fi
```

In `check_repo`, replace `    fetch_if_stale "$max_fetch_seconds"` with

```bash
    local fetched=1
    fetch_if_stale "$max_fetch_seconds" || fetched=""
```

add `    fetch_note=""` to the block of resets (after `    headline=""`), and directly after the
`[ "$upstream" = "$base_ref" ] || report_pushed_elsewhere "$upstream"` line add:

```bash
    [ -n "$fetched" ] || report_fetch_failed "$age"
```

- [ ] **Step 5: Run both suites**

Run: `bash -n hooks/git-freshness.sh && bash hooks/tests/git-freshness-touch.test.sh && bash hooks/tests/git-freshness-sync.test.sh`
Expected: both `… passed, 0 failed`.

- [ ] **Step 6: Commit**

```bash
git add hooks/git-freshness.sh hooks/tests/git-freshness-touch.test.sh
git commit -m "feat(hooks): git-freshness reports freshness unknown when its fetch does not finish (#60)"
```

---

### Task 6: the documentation follows

**Files:**
- Modify: `hooks/git-freshness.sh` (header: the modes, lines 7–21)
- Modify: `README.md` (hook wiring block, the modes list, *Hook tests*)
- Modify: `CLAUDE.md` (*Never work against a stale checkout*)

**Interfaces:** none; text only.

- [ ] **Step 1: The hook's header**

Replace the header's mode descriptions (from `#   session    SessionStart — …` through `#              The next edit
re-checks.`) with:

```bash
#   session    SessionStart — bring the config repos up to date (see
#              sync_config_repos), then check the directory the session was
#              launched in and mark its repo as touched.
#
#   touch      PreToolUse on Read|Edit|Write|MultiEdit|NotebookEdit|Glob|Grep|Bash
#              — check each repo the tool call acts on, the first time this
#              session touches it (touch_targets says how a call names its
#              paths; for Bash, the cd and git -C words, else the cwd). It runs
#              before the tool, so a first Read already sees the fast-forwarded
#              main and the warning arrives before any work lands on an old
#              base. Later touches in the repo cost one rev-parse.
#
#   edit       PostToolUse on Edit|Write — the legacy wiring: the same check on
#              the written file's repo, sharing touch's markers, so with both
#              wired it is a no-op. Remove it once touch is wired.
#
#   checkout   PostToolUse on `git checkout` — a branch switch changes the
#              answer, so drop this session's cached verdicts and stay silent.
#              The next touch re-checks.
#
# Any other mode does nothing.
```

- [ ] **Step 2: README**

In `README.md`'s hooks JSON block, replace

```json
  "PostToolUse": [
    { "matcher": "Edit|Write", "hooks": [ { "type": "command", "command": "$HOME/GitProjects/LaravelClaudeMd/LaravelClaudeMd/hooks/git-freshness.sh edit", "timeout": 20, "statusMessage": "Checking git freshness…" } ] },
```

with

```json
  "PreToolUse": [
    { "matcher": "Read|Edit|Write|MultiEdit|NotebookEdit|Glob|Grep|Bash", "hooks": [ { "type": "command", "command": "$HOME/GitProjects/LaravelClaudeMd/LaravelClaudeMd/hooks/git-freshness.sh touch", "timeout": 20, "statusMessage": "Checking git freshness…" } ] }
  ],
  "PostToolUse": [
```

Replace the modes list (`` `git-freshness.sh` has three modes: `` through the `checkout` bullet) with:

```markdown
`git-freshness.sh` has these modes:
- `session` — at startup: syncs both config repos (fast-forward only, never over local work) and
  links any skill that has no symlink yet (and any skill's `workflow/*.js` into
  `~/.claude/workflows/`, any skill's `agents/*.md` into `~/.claude/agents/`, and the status line
  script when `~/.claude/statusline-command.sh` does not exist), compiles any skill's
  `apps/*.applescript` into `~/Applications/` when no app of that name exists, then checks the
  launch directory.
- `touch` — before a tool call, the first time a session touches a repo: the repo of the file a
  Read/Edit/Write/MultiEdit/NotebookEdit acts on, the path (else the working directory) of a
  Glob/Grep, and for Bash every directory named by `cd <dir>` or `git -C <dir>` (else the working
  directory). It fast-forwards local `main`/`master` when safe, reports a working branch behind its
  base with "raise it and wait" (not for a `/pipeline` run's own branch), and says "freshness
  unknown" when the fetch does not finish. Later touches in the same repo are silent.
- `edit` — the earlier wiring (PostToolUse on `Edit|Write`). It shares `touch`'s per-repo markers,
  so with both wired it does nothing; a machine that still has it wired should replace it with the
  `touch` entry above.
- `checkout` — drops cached verdicts after a branch switch.
```

Replace the *Hook tests* block and paragraph with:

````markdown
Run after changing the hook:

```bash
bash hooks/tests/git-freshness-sync.test.sh
bash hooks/tests/git-freshness-touch.test.sh
```

They build throwaway repos under `$TMPDIR`. The sync suite covers every branch of the base-branch
sync, including the sibling-worktree case that is easy to get silently wrong, plus the config-repo
sync, skill linking and app building (the cases that compile print `skip` on a machine without
`osacompile`). The touch suite covers the first-touch check: first versus repeat touch, `main`
fast-forwarded on a first Read, a behind branch reported once, a dirty `main` left alone, slot
worktrees, Bash commands, a pipeline run's branch and a fetch that does not finish.
````

- [ ] **Step 3: CLAUDE.md**

In `CLAUDE.md`, replace

```markdown
- **Never work against a stale checkout.** `hooks/git-freshness.sh` reports staleness by itself, for
  the repo being worked in, and keeps local `main`/`master` fast-forwarded — the only thing it changes
  on its own. When it warns about your working branch, **raise it with me and wait**: do not pull,
  rebase or merge on your own initiative. Without the hook, check by hand before the first edit in a repo:
```

with

```markdown
- **Never work against a stale checkout.** `hooks/git-freshness.sh` checks each repo the first time a
  session touches it (reads, searches, runs a command in or writes to it), reports staleness by itself,
  and keeps local `main`/`master` fast-forwarded — the only thing it changes on its own. When it warns
  about your working branch, **raise it with me and wait**: do not pull, rebase or merge on your own
  initiative. Without the hook, check by hand before the first touch of a repo:
```

- [ ] **Step 4: Run both suites once more**

Run: `bash -n hooks/git-freshness.sh && bash hooks/tests/git-freshness-touch.test.sh && bash hooks/tests/git-freshness-sync.test.sh`
Expected: both `… passed, 0 failed`.

- [ ] **Step 5: Commit**

```bash
git add hooks/git-freshness.sh README.md CLAUDE.md
git commit -m "docs(hooks): the touch mode in the hook header, README wiring and CLAUDE.md (#60)"
```

---

## Spec coverage

| Spec section | Task |
|---|---|
| *When it runs*: matcher, target table, repo resolution, `mkdir` claim, one object per call | 2 (Bash row: 3), README wiring: 6 |
| *Bash command parsing* | 3 |
| *Slot and pipeline worktrees* (own toplevel, shared fetch) | 2 (case 4), fetch sharing is `newest_fetch_mtime`, unchanged |
| *What the check reports*: working-branch line, summary, upstream skip, pipeline exemption, header rewrite | 4 |
| *A fetch that does not finish* | 5 |
| *`session`, `edit` and unknown modes* | 1 (unknown, emit), 2 (session claim, edit shares markers) |
| *`check_repo` emits through the caller* | 1 |
| *Documentation* | 6 (header rationale: 4) |
| *Testing* cases 1–10 | touch suite cases 2, 3, 9, 10, 4, 5, 8, 14, 12, 1 |
| Assumptions 14–21 | 4 (14, 16, 17), 2 (15, 20, 21), 5 (18), 4 (19) |
