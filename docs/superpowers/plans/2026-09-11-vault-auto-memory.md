# SecondBrain vault as auto-memory — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the SecondBrain vault the storage of Claude Code's auto-memory, synced between the owner's two machines by a session-boundary git hook, with repo-specific memories in per-repo folders.

**Architecture:** `autoMemoryDirectory` points every session at `~/GitProjects/SecondBrain/SecondBrain/memory`. A new hook, `hooks/vault-sync.sh`, commits `memory/` and syncs with origin at `SessionStart` and `SessionEnd` only — never while a session writes. `git-freshness.sh` learns to skip the vault. Repo scoping is a folder convention (`memory/repos/<key>/` plus one pointer line per repo in the global index); there is no repo-memory hook. basic-memory is removed.

**Tech Stack:** bash 3.2 (macOS), git, BSD `grep`/`sed`/`stat`, Claude Code hooks and settings. Throwaway `python3` scripts are used only for the one-off migration, never in a hook.

**Spec:** `docs/superpowers/specs/2026-09-11-vault-auto-memory-design.md`

## Global Constraints

- Hooks run on macOS bash 3.2 with BSD tools: no associative arrays, no `mapfile`, no `jq`, no `python3`, no `\s` in regexes (use `[[:space:]]`).
- Every hook path exits 0 and never blocks a session.
- Vault: `~/GitProjects/SecondBrain/SecondBrain`, branch `main`, remote `origin`; overridable with `VAULT_DIR` (tests use it).
- `autoMemoryDirectory` value: `~/GitProjects/SecondBrain/SecondBrain/memory`.
- The sync hook stages `memory/` and nothing else. Never `git add -A`, in the hook or anywhere in this plan.
- Sweep commits use the message `memory: sync`.
- Fetch at most every 900 s (`FETCH_HEAD` mtime), capped at 10 s, ssh with `BatchMode=yes`; `SessionStart` matcher `startup|resume|clear`; hook timeouts 20 s.
- Secret patterns (a hit holds back only that file): private-key block; `ghp_[A-Za-z0-9]{30,}` or `github_pat_[A-Za-z0-9_]{50,}`; `sk_live_[A-Za-z0-9]{20,}`; `AKIA[0-9A-Z]{16}`; `xox[abp]-[A-Za-z0-9-]{20,}`. The spec lists the prefixes; the minimum lengths are added so a memory that only *mentions* a prefix still commits.
- Repo key: basename of `git remote get-url origin`, `.git` stripped, lowercased (`IT4WEBBV/ViewieMedia` → `viewiemedia`); remote-less repos use the lowercased directory name.
- LaravelClaudeMd work happens on branch `feature/vault-auto-memory` and lands through a PR. No `Co-Authored-By`, `Claude-Session` or "Generated with Claude Code" lines in commits or the PR. PR text is an impersonal record of the work.
- The vault commits straight to `main` (declared in the vault's own `CLAUDE.md`).
- LaravelClaudeMd has no changelog convention (no `CHANGELOG.md`, no `.changelog/`); none is added.

## Phases and where each runs

| Phase | Tasks | Runs in |
|---|---|---|
| A — probes | 1 | A session rooted at `~` that is **not** inside `EnterWorktree`. The worktree guard refuses `git` commands aimed at other repos, and the probes create throwaway repos. From the worktree session: `ExitWorktree` with `action: "keep"`, run Task 1, then `EnterWorktree` with `path: ~/GitProjects/LaravelClaudeMd/LaravelClaudeMd/.claude/worktrees/vault-auto-memory` to record the results. |
| A — build | 2–4 | The worktree `~/GitProjects/LaravelClaudeMd/LaravelClaudeMd/.claude/worktrees/vault-auto-memory`, branch `feature/vault-auto-memory`. Ends with a PR. |
| **Checkpoint** | — | **The owner merges the PR.** The hooks are inert until Task 6 wires them. The CLAUDE.md text describes the post-cut-over state, so Tasks 5–6 follow the merge directly. |
| B — migrate and cut over | 5–6 | A fresh session from `~`, launched as `CLAUDE_CODE_DISABLE_AUTO_MEMORY=1 claude`, so the migrating session cannot save into the folders it is retiring. Not inside a worktree. |
| C — follow-ups | 7 | Later: one week and two weeks after cut-over, and on the second machine. |

## File structure

| File | Responsibility | Task |
|---|---|---|
| `hooks/vault-sync.sh` (create) | Commit `memory/` and sync the vault with origin at session start/end | 2 |
| `hooks/tests/vault-sync.test.sh` (create) | End-to-end tests on throwaway vaults under `$TMPDIR` | 2 |
| `hooks/git-freshness.sh` (modify) | Skip the vault | 3 |
| `hooks/tests/git-freshness-sync.test.sh` (modify) | Case 12: the vault is skipped | 3 |
| `CLAUDE.md` (modify) | Memory section replaces both "Second Brain" sections; bootstrap steps and hook JSON | 4 |
| `docs/superpowers/specs/2026-09-11-vault-auto-memory-design.md` (modify) | Append probe results | 1 |
| Vault `memory/**`, `.gitattributes`, `CLAUDE.md`, `README.md`, `.gitignore`, `.claude/settings.json` if P2 requires it; old folders removed | Migrated memory | 5 |
| `~/.claude/settings.json`, `~/.claude/projects/*/memory` → `.bak`, basic-memory | Machine cut-over | 6 |

---

### Task 1: Probes — go/no-go

**Files:**
- Modify: `docs/superpowers/specs/2026-09-11-vault-auto-memory-design.md` (append `## Probe results`)
- Throwaway, deleted at the end: `~/.vault-probe`, `~/.vault-probe-repo`, `~/.vault-probe-origin.git`

**Interfaces:**
- Produces: a recorded result for each probe. Task 4 needs **P5** to pick one CLAUDE.md sentence. Task 5 needs **P2** to decide whether the vault gets `.claude/settings.json`.

**Go/no-go:** P1a, P1b, P2 and P3 must pass, P2 and P3 at least with the probe's `.claude/settings.json`. If P2 or P3 fails even with it, **stop and ask the owner**: set `bgIsolation: none` in `~/.claude/settings.local.json` (affects every home-rooted background job), or remove the vault. P1c and P5 are informational.

- [ ] **Step 1: Build the probe vault** (from `~`, outside any worktree)

```bash
cd ~
rm -rf ~/.vault-probe ~/.vault-probe-repo ~/.vault-probe-origin.git
mkdir -p ~/.vault-probe/memory
git -C ~/.vault-probe init -q
printf '# Probe memory index\n' > ~/.vault-probe/memory/MEMORY.md
git -C ~/.vault-probe add memory/MEMORY.md
git -C ~/.vault-probe commit -qm init
printf '{"autoMemoryDirectory":"~/.vault-probe/memory"}\n' > ~/.vault-probe/settings.json
```

- [ ] **Step 2: P1a — the setting is honoured, with `~` expansion**

```bash
claude -p --settings ~/.vault-probe/settings.json "Reply with only the absolute path of your auto-memory directory, exactly as your instructions state it."
```

Expected: `/Users/jroelofs/.vault-probe/memory` (a trailing slash is fine). FAIL if it names `~/.claude/projects/...` → stop, the design rests on this.

- [ ] **Step 3: P1b — a nested repo memory is written and stamped**

```bash
claude -p --settings ~/.vault-probe/settings.json "Save this as a memory the normal auto-memory way. It is only true in the repo 'probeproject', so put the file in repos/probeproject/ inside your memory directory and index it in repos/probeproject/MEMORY.md: 'Probe P1: the probe colour is teal.'"
ls ~/.vault-probe/memory/repos/probeproject/
grep -n '^modified:' ~/.vault-probe/memory/repos/probeproject/*.md
```

Expected: a topic file plus `MEMORY.md` in `repos/probeproject/`, and the topic file carries a `modified:` line. If the write was refused as a *permission prompt* (not a guard), rerun with `--permission-mode acceptEdits` and record that print mode needed it; that is not a blocker.

- [ ] **Step 4: P1c — the age reminder covers nested memories (informational)**

```bash
f=$(ls ~/.vault-probe/memory/repos/probeproject/*.md | grep -v '/MEMORY.md$' | head -1)
sed -i '' -E 's/^modified: .*/modified: 2026-08-01T00:00:00.000Z/' "$f"
touch -t 202608010000 "$f"
claude -p --settings ~/.vault-probe/settings.json "Read the file $f. Then quote verbatim any system reminder you received about that memory's age, or reply NONE."
```

Expected: a quote like `This memory is N days old`. `NONE` → record it; not a blocker.

- [ ] **Step 5: P2 — a background job rooted at `~` can save a memory into a git checkout**

```bash
cd ~ && claude --bg --settings ~/.vault-probe/settings.json "Save this as a global memory the normal auto-memory way: 'Probe P2a: background jobs can write memories.' Then finish."
for i in $(seq 1 36); do grep -rl 'Probe P2a' ~/.vault-probe/memory && break; sleep 5; done
```

Expected PASS: a file path is printed. If nothing appears within 3 minutes, read the refusal from the job's transcript and record it verbatim:

```bash
t=$(ls -t ~/.claude/projects/-Users-jroelofs/*.jsonl | head -1)
grep -o -E '[^"]{0,120}(isolat|worktree|refus|reject)[^"]{0,160}' "$t" | head -5
```

Then run variant b, with the folder-level guard override in the probe vault:

```bash
mkdir -p ~/.vault-probe/.claude
printf '{"worktree":{"bgIsolation":"none"}}\n' > ~/.vault-probe/.claude/settings.json
cd ~ && claude --bg --settings ~/.vault-probe/settings.json "Save this as a global memory the normal auto-memory way: 'Probe P2b: background jobs can write memories.' Then finish."
for i in $(seq 1 36); do grep -rl 'Probe P2b' ~/.vault-probe/memory && break; sleep 5; done
```

Record which variant passed: a / only b / neither.

- [ ] **Step 6: P3 — a session inside `EnterWorktree` of another repo can save a memory**

```bash
rm -rf ~/.vault-probe/.claude
git init -q --bare -b main ~/.vault-probe-origin.git
git clone -q ~/.vault-probe-origin.git ~/.vault-probe-repo
git -C ~/.vault-probe-repo commit -q --allow-empty -m init
git -C ~/.vault-probe-repo push -q -u origin main
cd ~/.vault-probe-repo && claude --bg --settings ~/.vault-probe/settings.json "First call the EnterWorktree tool with name p3. Then save this as a global memory the normal auto-memory way: 'Probe P3: worktree sessions can write memories.' Then finish."
for i in $(seq 1 36); do grep -rl 'Probe P3' ~/.vault-probe/memory && break; sleep 5; done
```

Expected PASS: a file path is printed. If not, read the transcript as in Step 5 (its project dir is `~/.claude/projects/-Users-jroelofs--vault-probe-repo/`), record the refusal, then repeat with `~/.vault-probe/.claude/settings.json` from Step 5 in place.

- [ ] **Step 7: P5 — does a `SessionStart` hook run before `MEMORY.md` is loaded (informational)**

```bash
cat > ~/.vault-probe/p5-settings.json <<'EOF'
{
  "autoMemoryDirectory": "~/.vault-probe/memory",
  "hooks": {
    "SessionStart": [ { "hooks": [ { "type": "command", "command": "printf '%s\\n' '- PROBE-P5 marker' >> \"$HOME/.vault-probe/memory/MEMORY.md\"" } ] } ]
  }
}
EOF
cd ~ && claude -p --settings ~/.vault-probe/p5-settings.json "Does your auto-memory index, as loaded into your context at session start, contain a line with PROBE-P5? Answer only YES or NO."
grep -c 'PROBE-P5' ~/.vault-probe/memory/MEMORY.md
```

Expected: the `grep` prints `1` or more, proving the hook ran. `YES` → the hook runs before the index is read. `NO` → memories from the other machine arrive one session late.

- [ ] **Step 8: Clean up the throwaway probes**

```bash
rm -rf ~/.vault-probe ~/.vault-probe-repo ~/.vault-probe-origin.git
```

- [ ] **Step 9: Record the results in the spec** (back in the worktree via `EnterWorktree` with `path`)

Append to `docs/superpowers/specs/2026-09-11-vault-auto-memory-design.md`:

```markdown
## Probe results (<date>, Claude Code <claude --version>)

| # | Result | Evidence |
|---|---|---|
| P1a | PASS / FAIL | <path printed> |
| P1b | PASS / FAIL | <files listed; modified: line> |
| P1c | reminder / NONE | <quote> |
| P2 | a / only b / neither | <file path or refusal quote> |
| P3 | PASS / needs .claude/settings.json / FAIL | <file path or refusal quote> |
| P5 | YES / NO | <model answer; grep count> |
```

Fill in every cell from the outputs above. Then:

```bash
git add docs/superpowers/specs/2026-09-11-vault-auto-memory-design.md
git commit -m "Record vault auto-memory probe results"
```

---

### Task 2: `vault-sync.sh` and its tests

**Files:**
- Create: `hooks/tests/vault-sync.test.sh`
- Create: `hooks/vault-sync.sh`

**Interfaces:**
- Produces: `hooks/vault-sync.sh <session|end>`. It reads `VAULT_DIR` (default `~/GitProjects/SecondBrain/SecondBrain`), `VAULT_FETCH_TTL_SECONDS` (default 900) and `VAULT_MAX_FETCH_SECONDS` (default 10), and ignores stdin. In `session` mode it prints one line of hook JSON (`hookSpecificOutput.hookEventName: "SessionStart"`, `additionalContext`, `suppressOutput: true`) only when there is a warning. `end` mode prints nothing and parks warnings in `<git-dir>/vault-sync-warnings`. Always exits 0. Task 4 wires it.

- [ ] **Step 1: Write the failing test suite**

Create `hooks/tests/vault-sync.test.sh`:

```bash
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
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `bash hooks/tests/vault-sync.test.sh`
Expected: `cannot find .../hooks/vault-sync.sh`, exit 1.

- [ ] **Step 3: Write the hook**

Create `hooks/vault-sync.sh`:

```bash
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
```

```bash
chmod +x hooks/vault-sync.sh
```

- [ ] **Step 4: Run the tests to confirm they pass**

Run: `bash hooks/tests/vault-sync.test.sh`
Expected: every line `ok`, last line `N passed, 0 failed`, exit 0. A failing case means the hook is wrong, not the test. Fix the hook; change a test only if it contradicts the spec, and say so in the commit message.

- [ ] **Step 5: Commit**

```bash
git add hooks/vault-sync.sh hooks/tests/vault-sync.test.sh
git commit -m "Add vault-sync hook: commit and sync the memory vault at session boundaries"
```

---

### Task 3: `git-freshness.sh` skips the vault

**Files:**
- Modify: `hooks/tests/git-freshness-sync.test.sh` (new case 12, before the summary lines)
- Modify: `hooks/git-freshness.sh:53-55` (config block) and `:342-344` (`check_repo`)

**Interfaces:**
- Consumes: the same `VAULT_DIR` default as Task 2 (`~/GitProjects/SecondBrain/SecondBrain`).
- Produces: `check_repo` returns silently for the vault. Every other repo behaves exactly as before.

- [ ] **Step 1: Write the failing test**

In `hooks/tests/git-freshness-sync.test.sh`, insert before the line `echo "----------------------------------------"`:

```bash
echo "case 12: the SecondBrain vault is left to vault-sync.sh"
repo=$(fixture vaultskip 2)
payload="{\"session_id\":\"test-vault\",\"file_path\":\"$repo/app.php\"}"
rm -rf "${TMPDIR:-/tmp}/claude-git-freshness/test-vault"
out=$(printf '%s' "$payload" | VAULT_DIR="$repo" bash "$hook" edit 2>/dev/null)
is "$out" "" "no report for the vault"
is "$(git -C "$repo" rev-list --count main..origin/main)" "2" "the vault's main was not fast-forwarded"
rm -rf "${TMPDIR:-/tmp}/claude-git-freshness/test-vault"
echo

```

- [ ] **Step 2: Run it to confirm it fails**

Run: `bash hooks/tests/git-freshness-sync.test.sh`
Expected: cases 1–11 `ok`; case 12 `FAIL  no report for the vault` (it prints PostToolUse JSON) and `FAIL  the vault's main was not fast-forwarded` (expected 2, got 0).

- [ ] **Step 3: Implement the skip**

In `hooks/git-freshness.sh`, after the line `max_listed_files=6      # the conflict list is a prompt, not an inventory`, add:

```bash

# The SecondBrain vault is synced by vault-sync.sh and never checked here.
# Resolved with pwd -P so it compares equal to git's --show-toplevel; empty
# when the vault is not cloned.
vault_toplevel=$(cd "${VAULT_DIR:-$HOME/GitProjects/SecondBrain/SecondBrain}" 2>/dev/null && pwd -P)
```

In `check_repo()`, after the line `    git remote get-url origin >/dev/null 2>&1 || return 0`, add:

```bash

    # vault-sync.sh pulls and pushes the vault itself. Checking it here would
    # fetch it concurrently and tell Claude "Do NOT pull" about the one repo
    # that is meant to be pulled automatically.
    if [ -n "$vault_toplevel" ] && [ "$(git rev-parse --show-toplevel 2>/dev/null)" = "$vault_toplevel" ]; then
        return 0
    fi
```

- [ ] **Step 4: Run both suites**

Run: `bash hooks/tests/git-freshness-sync.test.sh && bash hooks/tests/vault-sync.test.sh`
Expected: both end in `N passed, 0 failed`.

- [ ] **Step 5: Commit**

```bash
git add hooks/git-freshness.sh hooks/tests/git-freshness-sync.test.sh
git commit -m "git-freshness: leave the memory vault to vault-sync"
```

---

### Task 4: CLAUDE.md, then the PR

**Files:**
- Modify: `CLAUDE.md` — "Bootstrapping a new machine" (steps 1 and 4, the wiring sentence, the JSON block, the modes and test paragraphs) and lines 540–586 (both "Second Brain" sections)

**Interfaces:**
- Consumes: P5 from Task 1 picks one of two sentences below.
- Produces: the anchor `#memory-secondbrain-vault`, used by the playbook memory in Task 5.

- [ ] **Step 1: Bootstrap step 1 — clone the vault too**

Replace:

~~~~
# 1. Clone both config repos into nested wrapper dirs (~/GitProjects/<Repo>/<Repo>/)
mkdir -p ~/GitProjects/LaravelClaudeMd ~/GitProjects/DevOps-Claude-Config
git clone git@github.com:IT4WEBBV/LaravelClaudeMd.git ~/GitProjects/LaravelClaudeMd/LaravelClaudeMd
git clone git@github.com:IT4WEBBV/DevOps-Claude-Config.git ~/GitProjects/DevOps-Claude-Config/DevOps-Claude-Config
~~~~

with:

~~~~
# 1. Clone both config repos and the memory vault into nested wrapper dirs (~/GitProjects/<Repo>/<Repo>/)
mkdir -p ~/GitProjects/LaravelClaudeMd ~/GitProjects/DevOps-Claude-Config ~/GitProjects/SecondBrain
git clone git@github.com:IT4WEBBV/LaravelClaudeMd.git ~/GitProjects/LaravelClaudeMd/LaravelClaudeMd
git clone git@github.com:IT4WEBBV/DevOps-Claude-Config.git ~/GitProjects/DevOps-Claude-Config/DevOps-Claude-Config
git clone git@github.com:jonneroelofs/SecondBrain.git ~/GitProjects/SecondBrain/SecondBrain
~~~~

- [ ] **Step 2: Bootstrap step 4 — both hooks executable**

Replace:

~~~~
# 4. Make the stale-checkout hook executable
chmod +x ~/GitProjects/LaravelClaudeMd/LaravelClaudeMd/hooks/git-freshness.sh
~~~~

with:

~~~~
# 4. Make the hooks executable
chmod +x ~/GitProjects/LaravelClaudeMd/LaravelClaudeMd/hooks/git-freshness.sh \
         ~/GitProjects/LaravelClaudeMd/LaravelClaudeMd/hooks/vault-sync.sh
~~~~

- [ ] **Step 3: The wiring sentence and JSON block**

Replace the sentence that begins "Then wire that hook into" and the JSON block after it with:

~~~~markdown
Then point auto-memory at the vault and wire the hooks in `~/.claude/settings.json` — the scripts live in this repo and update with a `git pull`, so only this wiring is per-machine:

```json
"autoMemoryDirectory": "~/GitProjects/SecondBrain/SecondBrain/memory",
"hooks": {
  "SessionStart": [
    { "hooks": [ { "type": "command", "command": "$HOME/GitProjects/LaravelClaudeMd/LaravelClaudeMd/hooks/git-freshness.sh session", "timeout": 20, "statusMessage": "Checking git freshness…" } ] },
    { "matcher": "startup|resume|clear", "hooks": [ { "type": "command", "command": "$HOME/GitProjects/LaravelClaudeMd/LaravelClaudeMd/hooks/vault-sync.sh session", "timeout": 20, "statusMessage": "Syncing memory…" } ] }
  ],
  "PostToolUse": [
    { "matcher": "Edit|Write", "hooks": [ { "type": "command", "command": "$HOME/GitProjects/LaravelClaudeMd/LaravelClaudeMd/hooks/git-freshness.sh edit", "timeout": 20, "statusMessage": "Checking git freshness…" } ] },
    { "matcher": "Bash", "hooks": [ { "type": "command", "command": "$HOME/GitProjects/LaravelClaudeMd/LaravelClaudeMd/hooks/git-freshness.sh checkout", "if": "Bash(git checkout:*)", "timeout": 10 } ] }
  ],
  "SessionEnd": [
    { "hooks": [ { "type": "command", "command": "$HOME/GitProjects/LaravelClaudeMd/LaravelClaudeMd/hooks/vault-sync.sh end", "timeout": 20 } ] }
  ]
}
```
~~~~

- [ ] **Step 4: The modes and test paragraphs**

After the paragraph that begins "The three modes are", add a new paragraph:

~~~~markdown
`vault-sync.sh` has two: `session` (commit `memory/`, then fetch, rebase and push — at startup, resume and clear) and `end` (commit and push when the session ends). See [Memory (SecondBrain vault)](#memory-secondbrain-vault).
~~~~

Replace the paragraph that begins "Run `bash hooks/tests/git-freshness-sync.test.sh` after changing the hook." with:

~~~~markdown
Run `bash hooks/tests/git-freshness-sync.test.sh` or `bash hooks/tests/vault-sync.test.sh` after changing the matching hook. Both build throwaway repos under `$TMPDIR`; the first covers every branch of the base-branch sync, including the sibling-worktree case that is easy to get silently wrong, the second every sync path of the memory vault, including two machines writing at once.
~~~~

- [ ] **Step 5: Replace both "Second Brain" sections**

Delete everything from the line `## Second Brain (basic-memory archival memory)` up to, not including, `## Font Awesome Pro icons`. Put this in its place, keeping one of the two P5 bullets as noted:

~~~~markdown
## Memory (SecondBrain vault)

Auto-memory lives in the SecondBrain vault — `~/GitProjects/SecondBrain/SecondBrain`, private repo
`jonneroelofs/SecondBrain` — not in machine-local `~/.claude/projects/*/memory`. The
`autoMemoryDirectory` setting points every session at its `memory/` folder, so memory is versioned in
git and shared by both machines. It is the only memory system: save memories the normal auto-memory
way; there is nothing else to write to.

- **Repo-specific memories** — facts only true inside one repo — go in `memory/repos/<key>/`, with their
  index line in that folder's own `MEMORY.md`. `<key>` is the repo's GitHub name, lowercased
  (`IT4WEBBV/ViewieMedia` → `viewiemedia`). Each repo folder has one pointer line in the global
  `MEMORY.md`: read that repo's index before working in, or answering about, that repo. Everything else
  is global.
- **Never store secrets, credentials or client PII** — every memory is pushed to GitHub. `vault-sync.sh`
  holds back a file that looks like it contains a key; that is a backstop, not a licence.
- **Syncing is automatic.** `hooks/vault-sync.sh` commits `memory/` and syncs with origin at session start
  and end. Don't commit or push memory changes by hand.
- **Vault sync conflicts are yours to resolve** — an exception, for the vault only, to the Git Workflow
  rule against pulling, rebasing or merging on your own initiative. When the hook reports a conflict,
  rebase onto the upstream, keep both sides' facts in each conflicted file, continue, push.
- P5 = YES → **A memory saved on the other machine is there at the next session start.**
  P5 = NO → **A memory saved on the other machine arrives one session late:** the index is read before the start-of-session sync runs.
- The owner's own notes at the vault root are theirs; the hook never stages them.

A new machine gets the vault, the setting and the hooks from [Bootstrapping a new machine](#bootstrapping-a-new-machine).
If it already has local memories, start one session with `CLAUDE_CODE_DISABLE_AUTO_MEMORY=1 claude` from `~`
and have it follow the vault memory `playbook_migrate_machine_memory.md`.
~~~~

Keep only the P5 bullet that matches the recorded result, without its `P5 = …` prefix.

- [ ] **Step 6: Check the edit**

```bash
grep -n -E 'basic-memory|Second Brain|SecondBrain|vault-sync|autoMemoryDirectory' CLAUDE.md
```

Expected: no `basic-memory` and no `Second Brain` hits. `SecondBrain`, `vault-sync` and `autoMemoryDirectory` appear only in the bootstrap block and the new Memory section.

- [ ] **Step 7: Commit, push, update the PR and mark it ready**

The handoff opened a draft PR for this branch, so this step retitles it, replaces its body and takes it out of draft. Running the plan without that PR: use `gh pr create --base main --title "Move auto-memory into the SecondBrain vault" --body-file - <<'EOF'` in place of the `gh pr edit` line, and skip `gh pr ready`.

```bash
git add CLAUDE.md
git commit -m "CLAUDE.md: memory lives in the SecondBrain vault; replace the basic-memory sections"
git push -u origin feature/vault-auto-memory
gh pr edit --title "Move auto-memory into the SecondBrain vault" --body-file - <<'EOF'
## Summary

Auto-memory moves into the SecondBrain vault so the one memory system in use is versioned in git and shared by both machines. basic-memory is retired.

- `hooks/vault-sync.sh`: commits `memory/` and syncs the vault with origin at SessionStart (startup/resume/clear) and SessionEnd only. Stages nothing outside `memory/`, holds back files that look like they contain a credential, and hands rebase conflicts to Claude (vault-only exception, documented in CLAUDE.md).
- `hooks/git-freshness.sh`: skips the vault, which vault-sync owns.
- `CLAUDE.md`: a Memory section replaces both Second Brain sections; the bootstrap steps clone the vault, set `autoMemoryDirectory` and wire the new hook.

Spec: `docs/superpowers/specs/2026-09-11-vault-auto-memory-design.md` (includes probe results). Plan: `docs/superpowers/plans/2026-09-11-vault-auto-memory.md`.

## Tests

- `bash hooks/tests/vault-sync.test.sh` — <paste the summary line>
- `bash hooks/tests/git-freshness-sync.test.sh` — <paste the summary line>

## After merge

The hooks do nothing until `~/.claude/settings.json` wires them. Migrating the memories into the vault and the cut-over on this machine (plan Tasks 5–6) follow the merge directly. Until then, the new Memory section describes a setup that is not yet live.
EOF
gh pr ready
```

Before running `gh pr edit`, replace both `<paste the summary line>` markers with the real `N passed, 0 failed` lines from Task 3 Step 4.

**Checkpoint:** stop here. The owner merges the PR. The `git-freshness` hook then fast-forwards the primary checkout's `main`. Confirm with `git -C ~/GitProjects/LaravelClaudeMd/LaravelClaudeMd log -1 --format=%s main` before Task 5.

---

### Task 5: Migrate the memories into the vault

Runs in a fresh session from `~`, started as `CLAUDE_CODE_DISABLE_AUTO_MEMORY=1 claude`, not inside a worktree.

**Files (vault repo, committed straight to `main`):**
- Create: `memory/MEMORY.md`, `memory/*.md`, `memory/repos/<key>/MEMORY.md`, `memory/repos/<key>/*.md`, `memory/playbook_migrate_machine_memory.md`, `.gitattributes`
- Modify: `CLAUDE.md`, `README.md`, `.gitignore`
- Create only if P2 needed variant b: `.claude/settings.json`
- Delete: `decisions/`, `packages/`, `playbooks/`, `projects/`, `references/`
- Throwaway, not committed: `$TMPDIR/vault-migration/build_ledger.py`, `$TMPDIR/vault-migration/verify_migration.py`, `$TMPDIR/vault-migration/ledger.tsv`

**Interfaces:**
- Consumes: the merged hooks (Tasks 2–3); the P2 result (Task 1).
- Produces: a vault whose `memory/` passes `verify_migration.py`. Task 6 points `autoMemoryDirectory` at it.

- [ ] **Step 1: Bring the vault up to date**

```bash
VAULT=~/GitProjects/SecondBrain/SecondBrain
git -C "$VAULT" pull --rebase
git -C "$VAULT" status --short
```

Expected: pull succeeds, status empty or only the owner's root notes. Anything else under `decisions/` … `references/` → stop and report it.

- [ ] **Step 2: Confirm the two package keys**

```bash
for d in ~/GitProjects/packages/*/talldatatable* ~/GitProjects/packages/*/tallformbuilder* ~/GitProjects/*/TallDataTable ~/GitProjects/*/TallFormbuilder; do
  [ -e "$d/.git" ] && printf '%s -> %s\n' "$d" "$(git -C "$d" remote get-url origin | sed -E 's#.*/##; s/\.git$//' | tr 'A-Z' 'a-z')"
done
```

Expected: `talldatatable` and `tallformbuilder`. A different key → use it everywhere this task says `talldatatable` or `tallformbuilder`.

- [ ] **Step 3: Write the ledger builder**

Create `$TMPDIR/vault-migration/build_ledger.py`:

```python
#!/usr/bin/env python3
"""Build the migration ledger: every source note gets exactly one outcome.

    build_ledger.py <ledger.tsv> [--apply]

--apply copies every auto-memory source whose outcome is `global` or `repo`
to its target. Vault notes and merges are converted by hand (Step 5); the
script prints them as a checklist. Exits 1 if any source has no outcome.
"""
import glob, os, shutil, sys

HOME = os.path.expanduser('~')
PROJECTS = f'{HOME}/.claude/projects'
CURRENT = f'{PROJECTS}/-Users-jroelofs/memory'
VAULT = f'{HOME}/GitProjects/SecondBrain/SecondBrain'
MEM = f'{VAULT}/memory'

# outcome, target (relative to memory/), reason
G = lambda target, reason='global fact': ('global', target, reason)
R = lambda key, target, reason: ('repo', f'repos/{key}/{target}', reason)
M = lambda target, reason: ('merge', target, reason)
D = lambda reason: ('drop', '', reason)

CURRENT_EXCEPTIONS = {
    'reference_viewiemedia_prod_nfs_storage.md': R('viewiemedia', 'reference_viewiemedia_prod_nfs_storage.md', 'ViewieMedia production only'),
    'viewiemedia_slot_dir_casing.md': R('viewiemedia', 'viewiemedia_slot_dir_casing.md', 'ViewieMedia checkout only'),
    'breinstraat2_mid_rewrite_base_branch.md': R('breinstraat2', 'breinstraat2_mid_rewrite_base_branch.md', 'BreinStraat2 only'),
    'breinstraat2_legacy_env_committed.md': R('breinstraat2', 'breinstraat2_legacy_env_committed.md', 'BreinStraat2 only'),
    'breinstraat2_parity_scope_portal_vs_admin.md': R('breinstraat2', 'breinstraat2_parity_scope_portal_vs_admin.md', 'BreinStraat2 only'),
    'pipeline_triggers_nested_app_blind_spot.md': R('laravelclaudemd', 'pipeline_triggers_nested_app_blind_spot.md', 'regression marker for the pipeline skill code'),
    'second_brain_vault.md': D('superseded by CLAUDE.md → Memory (SecondBrain vault)'),
}

P = '-Users-jroelofs-GitProjects'
STALE = {
    f'{P}-Asimo-Asimo/feedback_no_accessors.md': G('feedback_no_accessors.md', 'coding preference, not Asimo-specific'),
    f'{P}-Breinstraat2-BreinStraat2/handoff-at-sensible-moments.md': G('feedback_handoff_at_sensible_moments.md', 'workflow preference'),
    f'{P}-Breinstraat2-BreinStraat2/handoff-persist-carryovers.md': G('feedback_handoff_persist_carryovers.md', 'workflow preference'),
    f'{P}-Breinstraat2-BreinStraat2/visual-parity-measure-dont-eyeball.md': M('feedback_use_parity_harness_not_eyeballing.md', 'same rule'),
    f'{P}-Deploy-Deploy/feedback_never_guess_verify.md': G('feedback_never_guess_verify.md', 'workflow preference'),
    f'{P}-Deploy-Deploy/local-dev-url-deploy-it4web-net.md': R('deploy', 'local_dev_url.md', 'Deploy only'),
    f'{P}-Deploy-Deploy/tabbedform-active-tab-rendering.md': M('reference_tallformbuilder_package.md', 'tallformbuilder usage gotcha'),
    f'{P}-LaravelClaudeMd-LaravelClaudeMd/docker-stack-no-hesitation.md': G('feedback_docker_stack_no_hesitation.md', 'workflow preference'),
    f'{P}-LaravelClaudeMd-LaravelClaudeMd/skill-naming.md': G('feedback_skill_naming.md', 'naming preference, all skill repos'),
    f'{P}-ViewieMedia-viewiemedia/feedback_always_storage_fake.md': G('feedback_always_storage_fake.md', 'testing rule'),
    f'{P}-ViewieMedia-viewiemedia/feedback_branch_specific_baselines.md': G('feedback_branch_specific_baselines.md', 'workflow rule'),
    f'{P}-ViewieMedia-viewiemedia/feedback_descriptive_variable_names.md': D('covered by CLAUDE.md → Code Style (descriptive naming)'),
    f'{P}-ViewieMedia-viewiemedia/feedback_no_ai_attribution.md': M('feedback_no_ai_attribution_wins.md', 'same rule'),
    f'{P}-ViewieMedia-viewiemedia/feedback_no_concurrent_tests.md': G('feedback_no_concurrent_tests.md', 'true for every it4web stack'),
    f'{P}-ViewieMedia-viewiemedia/feedback_no_worktrees.md': R('viewiemedia', 'feedback_no_worktrees.md', 'ViewieMedia only; age reminder flags it'),
    f'{P}-ViewieMedia-viewiemedia/feedback_nullable_date_cast_pattern.md': R('viewiemedia', 'feedback_nullable_date_cast_pattern.md', 'ViewieMedia only'),
    f'{P}-ViewieMedia-viewiemedia/feedback_org_type_binary.md': R('viewiemedia', 'feedback_org_type_binary.md', 'ViewieMedia only'),
    f'{P}-ViewieMedia-viewiemedia/feedback_prefer_collections.md': D('covered by CLAUDE.md → Code Style (prefer collections)'),
    f'{P}-ViewieMedia-viewiemedia/feedback_preserve_polymorphic_columns.md': R('viewiemedia', 'feedback_preserve_polymorphic_columns.md', 'ViewieMedia media cloning'),
    f'{P}-ViewieMedia-viewiemedia/feedback_progress_visibility.md': G('feedback_progress_visibility.md', 'workflow preference'),
    f'{P}-ViewieMedia-viewiemedia/feedback_use_existing_ui_components.md': R('viewiemedia', 'feedback_use_existing_ui_components.md', 'ViewieMedia component names'),
    f'{P}-ViewieMedia-viewiemedia/feedback_visual_companion_base64.md': G('feedback_visual_companion_base64.md', 'tooling gotcha'),
    f'{P}-ViewieMedia-viewiemedia/project_flux_pro_dependency.md': M('composer_auth_json_committed_leak.md', 'same auth.json / Flux Pro fact'),
    f'{P}-ViewieMedia-viewiemedia/project_lw3_upgrade_baseline.md': D('one-off Livewire 3 upgrade baseline; baselines are branch-specific'),
    f'{P}-ViewieMedia-viewiemedia/project_lw3_visual_tokens_upstream_followup.md': R('viewiemedia', 'project_lw3_visual_tokens_upstream_followup.md', 'open ViewieMedia follow-up'),
    f'{P}-ViewieMedia-viewiemedia/project_talldatatableviewie_fork.md': R('viewiemedia', 'project_talldatatableviewie_fork.md', 'fork used by ViewieMedia'),
    f'{P}-car-charger/peblar-rfid-temporarily-disabled.md': R('car-charger', 'peblar_rfid_temporarily_disabled.md', 'car-charger only'),
    f'{P}-packages-it4web-talldatatable/booleanfield-table-render-crash.md': R('talldatatable', 'booleanfield_table_render_crash.md', 'package development'),
    f'{P}-packages-it4web-talldatatable/icon-views-gitignored-macos.md': R('talldatatable', 'icon_views_gitignored_macos.md', 'package repo .gitignore'),
    f'{P}-packages-it4web-talldatatable/respond-in-english.md': G('feedback_respond_in_english.md', 'user preference'),
    f'{P}/avoid-word-chrome.md': G('feedback_avoid_word_chrome.md', 'terminology preference'),
    f'{P}/deploy-slot-worktrees.md': R('deploy', 'deploy_slot_worktrees.md', 'Deploy only'),
    f'{P}/it4web-package-tests-make-test.md': G('it4web_package_tests_make_test.md', 'every it4web package'),
    f'{P}/prefer-composition-over-inheritance.md': G('feedback_prefer_composition_over_inheritance.md', 'coding preference'),
    f'{P}/retenium-tallui-form-inputs.md': R('retenium', 'retenium_tallui_form_inputs.md', 'Retenium only'),
}

LW = 'reference_livewire_alpine_techniques.md'
VAULT_NOTES = {
    'decisions/Blaze- enable the function compiler in projects, keep packages Blaze-safe.md': G('decision_blaze_function_compiler.md', 'it4web-wide decision'),
    'decisions/Cursor pagination sidesteps the COUNT offset pagination needs.md': G('reference_cursor_pagination_skips_count.md', 'technique'),
    'decisions/canonical-slot-source.md': G('decision_canonical_slot_source.md', 'it4web-wide decision'),
    'decisions/it4web packages use Larastan, not plain PHPStan.md': G('decision_packages_use_larastan.md', 'it4web-wide decision'),
    'decisions/prefer-report-over-log.md': M('prefer_report_over_log_for_flare.md', 'duplicate of the auto-memory note'),
    'packages/TallDataTable (it4web datatable package).md': G('reference_talldatatable_package.md', 'usage facts global; package-development facts split to repos/talldatatable/'),
    'packages/tallformbuilder.md': G('reference_tallformbuilder_package.md', 'usage facts global; package-development facts split to repos/tallformbuilder/'),
    'playbooks/Base image upgrade to bookworm + PHP 8.4.md': G('playbook_base_image_bookworm_php84.md', 'playbook'),
    'playbooks/Changelog automation port playbook.md': G('playbook_changelog_automation_port.md', 'playbook'),
    'playbooks/PHPStan tmpDir must not live on a bind mount.md': G('phpstan_tmpdir_not_on_bind_mount.md', 'gotcha'),
    'playbooks/PHPUnit to Pest migration playbook.md': G('playbook_phpunit_to_pest.md', 'playbook'),
    'playbooks/Slot DB host leaks to base name on Docker Compose below 2.17.md': G('slot_db_host_leak_compose_below_2_17.md', 'gotcha'),
    'playbooks/slot-functionality-port.md': M('slot_functionality_port_playbook.md', 'duplicate of the auto-memory playbook'),
    'projects/it4web rebrand- bedrijfsnaam-onderzoek en shortlist.md': G('project_it4web_rebrand_shortlist.md', 'company-level, not a repo'),
    'projects/retenium.md': R('retenium', 'project_retenium_profile.md', 'Retenium profile'),
    'projects/revolve.md': R('revolve', 'project_revolve_profile.md', 'Revolve profile'),
    'projects/viewiemedia.md': R('viewiemedia', 'project_viewiemedia_profile.md', 'ViewieMedia profile'),
    'references/$wire in Alpine expressions — zero-roundtrip Livewire UI.md': M(LW, 'Livewire/Alpine technique'),
    'references/AI workflow design at fleet scale (macro proposal).md': G('reference_ai_workflow_fleet_scale.md', 'reference'),
    'references/Livewire Data Tables screencast reference.md': M(LW, 'Livewire/Alpine technique'),
    'references/Livewire third-party JS library integration recipe.md': M(LW, 'Livewire/Alpine technique'),
    'references/Per-field Livewire validation- blur binding alone shows nothing.md': M(LW, 'Livewire/Alpine technique'),
    'references/Reactive shared Livewire Form state across sibling components.md': M(LW, 'Livewire/Alpine technique'),
    'references/Sushi- array-backed Eloquent for fixtures and lookup tables.md': G('reference_sushi_array_eloquent.md', 'Eloquent technique'),
    'references/x-modelable makes Alpine components dual-bindable.md': M(LW, 'Livewire/Alpine technique'),
    'references/x-teleport escapes overflow and z-index traps for inline overlays.md': M(LW, 'Livewire/Alpine technique'),
}

rows, missing = [], []

for path in sorted(glob.glob(f'{CURRENT}/*.md')):
    name = os.path.basename(path)
    if name != 'MEMORY.md':
        rows.append((path, *CURRENT_EXCEPTIONS.get(name, G(name))))

for path in sorted(glob.glob(f'{PROJECTS}/*/memory/*.md')):
    if path.startswith(CURRENT + '/') or path.endswith('/MEMORY.md'):
        continue
    key = path[len(PROJECTS) + 1:].replace('/memory/', '/')
    if key in STALE:
        rows.append((path, *STALE[key]))
    else:
        missing.append(path)

for path in sorted(glob.glob(f'{VAULT}/*/*.md')):
    rel = path[len(VAULT) + 1:]
    if rel.startswith('memory/'):   # the migration target, once --apply has run
        continue
    if rel in VAULT_NOTES:
        rows.append((path, *VAULT_NOTES[rel]))
    else:
        missing.append(path)

if missing:
    sys.exit('No outcome for:\n  ' + '\n  '.join(missing))

with open(sys.argv[1], 'w') as out:
    for row in rows:
        out.write('\t'.join(row) + '\n')
print(f'{len(rows)} sources → {sys.argv[1]}')

if '--apply' in sys.argv:
    manual = []
    for source, outcome, target, reason in rows:
        from_memory = source.startswith(PROJECTS)
        if outcome in ('global', 'repo') and from_memory:
            os.makedirs(os.path.dirname(f'{MEM}/{target}'), exist_ok=True)
            shutil.copy2(source, f'{MEM}/{target}')
        elif outcome != 'drop':
            manual.append(f'{outcome:6} {target:55} ← {source}')
    print('\nConvert / merge by hand (Step 5):')
    print('\n'.join(manual))
```

- [ ] **Step 4: Build the ledger and copy the mechanical moves**

```bash
mkdir -p "$TMPDIR/vault-migration"
python3 "$TMPDIR/vault-migration/build_ledger.py" "$TMPDIR/vault-migration/ledger.tsv"
```

Expected: `N sources → …/ledger.tsv`, N = 41 + 35 + 26 = 102. `No outcome for: …` → a new memory appeared since this plan was written. Add it to the right dict with an outcome and rerun.

```bash
python3 "$TMPDIR/vault-migration/build_ledger.py" "$TMPDIR/vault-migration/ledger.tsv" --apply
```

Expected: `memory/` in the vault now holds the copied files, and a checklist of about 40 rows to convert or merge by hand is printed.

Also read `~/.claude/projects/-Users-jroelofs-GitProjects-Cornels-Cornels/memory/MEMORY.md`. If it holds anything beyond headings, move that fact into a memory under the right outcome and add a row for it to `ledger.tsv`.

- [ ] **Step 5: Convert the vault notes and do the merges**

Work through the printed checklist. For each row:

- **Converted vault note (`global` or `repo`):** write the target as an auto-memory file.
  - Frontmatter: `name` (kebab-case slug of the target filename), `description` (one line, from the note's first paragraph), `metadata.type` (`reference` for references, techniques and playbooks; `project` for project and decision notes).
  - Body: the note's prose, then `## Observations` bullets turned into plain bullets with the `[category]` tags dropped, then `## Relations` turned into `[[slug]]` links to the new target names.
  - Drop `title`, `permalink`, `confidence`, `evidence`, `tags`, `created`.
  - For the two package notes, move facts about *developing* the package (its repo, tests, CI) into `repos/<key>/` files, and keep facts about *using* it global.

  Example, `decisions/it4web packages use Larastan, not plain PHPStan.md` → `memory/decision_packages_use_larastan.md`:

  ```markdown
  ---
  name: decision-packages-use-larastan
  description: it4web packages use Larastan (it bootstraps via testbench's CreatesApplication), level 5; measured gain over plain PHPStan is small
  metadata:
    type: project
  ---

  Larastan **does** bootstrap inside an it4web package that has no Laravel application of its own —
  only `orchestra/testbench` as a dev dependency — so packages do **not** fall back to plain
  `phpstan/phpstan`.

  - Larastan ships an explicit package path: `vendor/larastan/larastan/bootstrap.php` falls through to
    `ApplicationResolver::resolve()` when there is no `bootstrap/app.php` …
  - (every remaining Observation, verbatim, as a plain bullet)

  Related: [[phpstan-tmpdir-not-on-bind-mount]], [[reference-tallformbuilder-package]]
  ```

- **Merge:** append the source's facts that the target doesn't already state to the target file. Keep the target's frontmatter and widen its `description` if it now covers more. Never keep two statements of the same fact.
- **`reference_livewire_alpine_techniques.md`:** created by the first merge into it, with one `## <technique>` section per merged reference and `metadata.type: reference`.

- [ ] **Step 6: Write the indexes**

`memory/MEMORY.md` — exactly this header, then the two sections:

```markdown
# Memory index

Global memories are listed under Global. Facts only true inside one repo live in `repos/<key>/`
(`<key>` = the repo's GitHub name, lowercased) with their own `MEMORY.md`; each repo folder has one
pointer line under Repos. Read a repo's index before working in, or answering about, that repo. A new
repo folder gets its pointer line here.

## Repos

## Global
```

- **Under `## Repos`:** one line per `repos/<key>/` folder, naming the repo and what's inside:
  `- [ViewieMedia repo memory](repos/viewiemedia/MEMORY.md) — read before working in or answering about ViewieMedia: prod NFS storage, slot dir casing, media cloning, UI components, talldatatableviewie fork, …`
- **Under `## Global`:** one line per global memory. Reuse the memory's existing line from the old `MEMORY.md` it came from, verbatim, and fix the link if the filename changed. New files get a line in the same style: `- [Title](file.md) — hook`.
- **`memory/repos/<key>/MEMORY.md`:** `# <Repo> repo memory`, then one line per memory in that folder, links relative to the folder.
- **Old lines in the current index that pointed at merged or dropped files:** removed.

- [ ] **Step 7: Add the playbook memory for the second machine**

Create `memory/playbook_migrate_machine_memory.md` and add its line under `## Global`:

```markdown
---
name: playbook-migrate-machine-memory
description: One-off steps to move a machine's local auto-memory into the SecondBrain vault and retire basic-memory
metadata:
  type: reference
---

Run once per machine, in a session started with `CLAUDE_CODE_DISABLE_AUTO_MEMORY=1 claude` from `~`
(not inside a worktree), after LaravelClaudeMd is pulled and the vault is cloned (CLAUDE.md →
Bootstrapping a new machine).

1. `git -C ~/GitProjects/SecondBrain/SecondBrain pull --rebase`.
2. List `~/.claude/projects/*/memory/*.md` (skip `MEMORY.md`). For each file record one outcome in a
   ledger: same fact already in the vault → drop, or merge what's new; only true in one repo →
   `memory/repos/<key>/` (key = the repo's GitHub name, lowercased); otherwise global.
3. Apply the ledger, add each index line (global `MEMORY.md`, or the repo's `MEMORY.md` plus a pointer
   line for a new repo folder), commit to the vault's `main` with the ledger in the message, push.
4. Wire `~/.claude/settings.json` as in CLAUDE.md → Bootstrapping a new machine (`autoMemoryDirectory`
   and the vault-sync hooks), through the update-config skill.
5. Rename each old `~/.claude/projects/*/memory` to `memory.bak-<date>`.
6. If basic-memory is installed: `claude mcp remove basic-memory -s user`, `uv tool uninstall basic-memory`,
   `rm -rf ~/.basic-memory`.
7. A week later, check each `.bak` against the ledger for anything saved after step 3, migrate it, then
   delete the `.bak` directories.

**Why:** auto-memory is machine-local; without this, corrections taught on one machine never reach the other.
**How to apply:** only when setting up or catching up a machine; the hooks keep things in sync after that.
```

- [ ] **Step 8: Vault housekeeping files**

`.gitattributes` (create):

```
memory/**/MEMORY.md merge=union
```

`CLAUDE.md` (replace the whole file):

```markdown
# SecondBrain vault

This repo holds Claude Code's auto-memory for its owner, shared by both of the owner's machines.

- `memory/` is the `autoMemoryDirectory` of every session (LaravelClaudeMd `CLAUDE.md` → Memory).
  Claude writes it through auto-memory. `memory/MEMORY.md` is the global index; `memory/repos/<key>/`
  holds facts only true in one repo, with its own index.
- Commits go straight to `main` — no branches, no PRs. `hooks/vault-sync.sh` in LaravelClaudeMd commits
  `memory/` and syncs with origin at session start and end; memory changes are never committed by hand.
- Sync conflicts are Claude's to resolve: keep both sides' facts.
- Never store secrets, credentials or client PII — this repo is pushed to GitHub.
- Files at the root are the owner's own notes; the hook never stages them.
```

`README.md` (replace the whole file):

```markdown
# SecondBrain

Claude Code's auto-memory, versioned and shared between machines. `memory/` is written by Claude
through auto-memory and synced by `hooks/vault-sync.sh` in LaravelClaudeMd at every session start and
end. Notes at the root are personal and never touched by the hook.

Setup on a new machine: LaravelClaudeMd `CLAUDE.md` → Bootstrapping a new machine.
```

`.gitignore`: delete the basic-memory comment line and the `.basic-memory/`, `*.db`, `*.db-wal`, `*.db-shm` lines; keep the Obsidian and OS-cruft blocks.

Only if Task 1 recorded P2 = "only b": create `.claude/settings.json`:

```json
{"worktree":{"bgIsolation":"none"}}
```

- [ ] **Step 9: Write and run the verifier**

Create `$TMPDIR/vault-migration/verify_migration.py`:

```python
#!/usr/bin/env python3
"""Check the migrated memory against the ledger and the conventions.

    verify_migration.py <ledger.tsv>
"""
import glob, os, re, sys

MEM = os.path.expanduser('~/GitProjects/SecondBrain/SecondBrain/memory')
SECRETS = [
    r'-----BEGIN [A-Z ]*PRIVATE KEY-----', r'ghp_[A-Za-z0-9]{30,}', r'github_pat_[A-Za-z0-9_]{50,}',
    r'sk_live_[A-Za-z0-9]{20,}', r'AKIA[0-9A-Z]{16}', r'xox[abp]-[A-Za-z0-9-]{20,}',
]
problems = []
rel = lambda p: os.path.relpath(p, MEM)

global_index = f'{MEM}/MEMORY.md'
lines = open(global_index).read().splitlines()
size = os.path.getsize(global_index)
if len(lines) > 200 or size > 25_000:
    problems.append(f'global MEMORY.md is {len(lines)} lines / {size} bytes (cap 200 / 25KB)')

indexed = set()
for index in [global_index] + glob.glob(f'{MEM}/repos/*/MEMORY.md'):
    for link in re.findall(r'\]\(([^)\s]+\.md)\)', open(index).read()):
        target = os.path.normpath(os.path.join(os.path.dirname(index), link))
        if not os.path.exists(target):
            problems.append(f'{rel(index)} links to missing {link}')
        indexed.add(target)

for folder in glob.glob(f'{MEM}/repos/*/'):
    index = os.path.normpath(os.path.join(folder, 'MEMORY.md'))
    if not os.path.exists(index):
        problems.append(f'{rel(folder)} has no MEMORY.md')
    elif index not in indexed:
        problems.append(f'no pointer line for {rel(index)} in the global index')

for path in glob.glob(f'{MEM}/**/*.md', recursive=True):
    path = os.path.normpath(path)
    if os.path.basename(path) == 'MEMORY.md':
        continue
    if path not in indexed:
        problems.append(f'{rel(path)} is not in any index')
    text = open(path).read()
    front = re.match(r'^---\n(.*?)\n---\n', text, re.S)
    if not front:
        problems.append(f'{rel(path)} has no frontmatter')
    else:
        # type sits under metadata: in current files, at the top level in older ones
        for field in (r'^name:', r'^description:', r'^\s*type:\s*(user|feedback|project|reference)\s*$'):
            if not re.search(field, front.group(1), re.M):
                problems.append(f'{rel(path)} frontmatter lacks {field}')
    for pattern in SECRETS:
        if re.search(pattern, text):
            problems.append(f'{rel(path)} matches secret pattern {pattern}')

sources = set()
for row in open(sys.argv[1]).read().splitlines():
    source, outcome, target, reason = row.split('\t')
    if source in sources:
        problems.append(f'{source} appears twice in the ledger')
    sources.add(source)
    if outcome == 'drop' and not reason:
        problems.append(f'{source} dropped without a reason')
    if outcome != 'drop' and not os.path.exists(f'{MEM}/{target}'):
        problems.append(f'{source} → {target} ({outcome}) but the target does not exist')

if problems:
    print('\n'.join(problems))
    sys.exit(1)
print(f'OK — {len(sources)} sources accounted for, {len(indexed)} files indexed, global index {len(lines)} lines')
```

```bash
python3 "$TMPDIR/vault-migration/verify_migration.py" "$TMPDIR/vault-migration/ledger.tsv"
```

Expected: `OK — 102 sources accounted for, …, global index <200 lines`. Fix every reported problem and rerun until it passes.

- [ ] **Step 10: Commit and push the vault**

```bash
VAULT=~/GitProjects/SecondBrain/SecondBrain
git -C "$VAULT" add memory .gitattributes CLAUDE.md README.md .gitignore
[ -f "$VAULT/.claude/settings.json" ] && git -C "$VAULT" add .claude/settings.json
git -C "$VAULT" rm -r -q decisions packages playbooks projects references
git -C "$VAULT" status --short
```

Expected: only `memory/…`, the four housekeeping files (plus `.claude/settings.json` if created) and the deletions. No root notes.

```bash
{
  printf 'Move auto-memory into the vault\n\n'
  printf 'memory/ becomes the autoMemoryDirectory of every session. Sources: the auto-memory folders of\n'
  printf 'this machine and the basic-memory notes (decisions, packages, playbooks, projects, references),\n'
  printf 'converted to the auto-memory format. basic-memory is retired.\n\n'
  printf 'Ledger (source, outcome, target, reason):\n'
  sed "s#$HOME#~#g" "$TMPDIR/vault-migration/ledger.tsv"
} > "$TMPDIR/vault-migration/commit-message.txt"
git -C "$VAULT" commit -q -F "$TMPDIR/vault-migration/commit-message.txt"
git -C "$VAULT" push -q
git -C "$VAULT" log -1 --stat --format=%s | head -5
```

Expected: commit `Move auto-memory into the vault`, pushed.

---

### Task 6: Cut over this machine

Same session as Task 5.

**Files:**
- Modify: `~/.claude/settings.json`
- Rename: every `~/.claude/projects/*/memory` → `memory.bak-<date>`
- Remove: the basic-memory MCP registration, tool and index

**Interfaces:**
- Consumes: the merged hooks at `~/GitProjects/LaravelClaudeMd/LaravelClaudeMd/hooks/` and the migrated vault.
- Produces: new sessions whose auto-memory is the vault.

- [ ] **Step 1: Settings, through the update-config skill**

Invoke the `update-config` skill and have it merge into `~/.claude/settings.json`, keeping every existing key and hook entry:

- top-level `"autoMemoryDirectory": "~/GitProjects/SecondBrain/SecondBrain/memory"`
- a second `SessionStart` entry: `{ "matcher": "startup|resume|clear", "hooks": [ { "type": "command", "command": "$HOME/GitProjects/LaravelClaudeMd/LaravelClaudeMd/hooks/vault-sync.sh session", "timeout": 20, "statusMessage": "Syncing memory…" } ] }`
- a `SessionEnd` entry: `{ "hooks": [ { "type": "command", "command": "$HOME/GitProjects/LaravelClaudeMd/LaravelClaudeMd/hooks/vault-sync.sh end", "timeout": 20 } ] }`

Check the result:

```bash
python3 -c "import json,os; s=json.load(open(os.path.expanduser('~/.claude/settings.json'))); print(s['autoMemoryDirectory']); print([h['hooks'][0]['command'].split('/')[-1] for h in s['hooks']['SessionStart']]); print([h['hooks'][0]['command'].split('/')[-1] for h in s['hooks']['SessionEnd']])"
```

Expected: the vault path; `['git-freshness.sh session', 'vault-sync.sh session']`; `['vault-sync.sh end']`.

- [ ] **Step 2: Retire the old memory folders**

```bash
stamp=$(date +%F)
for d in "$HOME"/.claude/projects/*/memory; do mv "$d" "$d.bak-$stamp"; done
ls -d "$HOME"/.claude/projects/*/memory* | sed "s#$HOME#~#"
```

Expected: only `memory.bak-<date>` entries remain.

- [ ] **Step 3: Remove basic-memory**

```bash
claude mcp remove basic-memory -s user
uv tool uninstall basic-memory
rm -rf "$HOME/.basic-memory"
claude mcp list 2>&1 | grep -c basic-memory
```

Expected: the last command prints `0`.

- [ ] **Step 4: Verify in fresh sessions**

```bash
VAULT=~/GitProjects/SecondBrain/SecondBrain
cd ~ && claude -p "Reply with only the absolute path of your auto-memory directory."
```

Expected: `/Users/jroelofs/GitProjects/SecondBrain/SecondBrain/memory`.

```bash
cd ~ && claude -p "What do you remember about how ViewieMedia's production storage works? Follow your memory index; say which memory files you read."
```

Expected: the answer names `repos/viewiemedia/MEMORY.md` and the NFS memory, and mentions NFS. This proves the pointer line leads to repo memory.

```bash
cd ~ && claude -p "Save a global memory the normal auto-memory way: 'Cut-over check $(date +%F): this memory may be deleted.'"
sleep 3
git -C "$VAULT" log -3 --format='%h %s' --name-only | head -12
git -C "$VAULT" fetch -q && git -C "$VAULT" log -1 --format='%h %s' origin/main
```

Expected: a `memory: sync` commit containing the new file, and origin at the same commit, pushed by the SessionEnd sweep. If SessionEnd did not fire for a `-p` session, the commit appears after the next session starts. Record which happened.

```bash
cd ~ && claude -p "Delete the memory whose text starts with 'Cut-over check' and remove its line from the memory index."
cd ~ && claude -p "Reply OK."   # the SessionStart sweep commits the deletion if SessionEnd did not
git -C "$VAULT" log -2 --format='%h %s' --name-status | head -8
```

Expected: a `memory: sync` commit deleting that file.

```bash
printf '{"session_id":"cutover-check","file_path":"%s/memory/MEMORY.md"}' "$VAULT" \
  | bash ~/GitProjects/LaravelClaudeMd/LaravelClaudeMd/hooks/git-freshness.sh edit
rm -rf "${TMPDIR:-/tmp}/claude-git-freshness/cutover-check"
```

Expected: no output, confirming git-freshness skips the vault.

**Rollback**, if a Step 4 check fails and can't be fixed on the spot:

```bash
# 1. Through the update-config skill: remove autoMemoryDirectory and both vault-sync entries.
# 2. Restore the old memory folders:
for d in "$HOME"/.claude/projects/*/memory.bak-*; do mv "$d" "${d%.bak-*}"; done
# 3. Undo the vault migration commit:
VAULT=~/GitProjects/SecondBrain/SecondBrain
git -C "$VAULT" revert --no-edit "$(git -C "$VAULT" log --grep='^Move auto-memory into the vault' -1 --format=%H)"
git -C "$VAULT" push -q
# 4. basic-memory: reinstall with the steps in the pre-merge CLAUDE.md "Second Brain (multi-machine setup)" section (git history).
```

- [ ] **Step 5: Report**

Record the outcomes of Steps 1–4 as a comment on the merged PR, using `gh pr comment <number> --body-file -` with an impersonal record: what was migrated (counts from the verifier), the cut-over checks and their results, and what is left (Task 7).

---

### Task 7: Follow-ups (not executable today)

- [ ] **One week after cut-over — retire the `.bak` folders.**

  Check that every file in `~/.claude/projects/*/memory.bak-*` is in the ledger (see the vault migration commit message) or was deliberately left out. A file saved after the migration commit gets migrated first. Then `rm -rf ~/.claude/projects/*/memory.bak-*`.

  ```bash
  git -C ~/GitProjects/SecondBrain/SecondBrain log --grep='^Move auto-memory into the vault' -1 --format=%B > "$TMPDIR/ledger-from-commit.txt"
  for f in ~/.claude/projects/*/memory.bak-*/*.md; do
    case "$(basename "$f")" in MEMORY.md) continue ;; esac
    grep -q -F "$(printf '%s' "$f" | sed "s#$HOME#~#; s#memory.bak-[0-9-]*#memory#")" "$TMPDIR/ledger-from-commit.txt" || echo "not in ledger: $f"
  done
  ```

- [ ] **Second machine.** The owner starts a session there as `CLAUDE_CODE_DISABLE_AUTO_MEMORY=1 claude` from `~`, after pulling LaravelClaudeMd and cloning the vault, and asks it to follow `playbook_migrate_machine_memory.md`.

- [ ] **Two weeks after cut-over — success criteria (spec).**

  ```bash
  git -C ~/GitProjects/SecondBrain/SecondBrain log --grep='^memory: sync' --since=2.weeks --format='%h %ad %s' --date=short | wc -l
  ls ~/GitProjects/SecondBrain/SecondBrain/memory/repos/
  wc -l ~/GitProjects/SecondBrain/SecondBrain/memory/MEMORY.md
  ```

  Memories committed without anyone asking, both machines holding the same `main`, and repo facts under `repos/` → done. A global index past ~120 lines, or a session that missed a repo fact it should have read → revisit the deferred repo-memory hook (spec, Rejected alternatives).
