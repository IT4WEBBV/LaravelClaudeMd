# A Proofs app opens the proof store index — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `~/Applications/Proofs.app` opens `~/GitProjects/_proofs/index.html` in Chrome, or says the index does not
exist yet; the `session` hook compiles it from a source in the repo on every machine where it is missing.

**Architecture:** One AppleScript source, `skills/pipeline/apps/Proofs.applescript`, and one hook function,
`build_new_skill_apps()` in `hooks/git-freshness.sh`, called from `sync_config_repos()` beside the existing
`link_new_skill_files()` calls. The function compiles each `skills/*/apps/*.applescript` of a config repo with
`osacompile` into a temporary directory and moves the bundle into `~/Applications` only when no app of that name is
there; an existing app is never replaced. Docs: README (hook bullet, §Proofs app, §Hook tests) and one sentence in
engine.md §The proof store.

**Tech Stack:** Bash (the hook and its test file), AppleScript compiled by `/usr/bin/osacompile` (macOS, no new
dependency), Markdown docs.

**Spec:** `docs/superpowers/specs/2026-10-02-pipeline-proofs-app-design.md`. Read it with this plan: the plan argues
from it, and its `## Assumptions` 11–13 are the answers this plan assumed.

## Global Constraints

- Run every command from the worktree root
  `/Users/jroelofs/GitProjects/LaravelClaudeMd/LaravelClaudeMd/.claude/worktrees/worktree-issue-152-pipeline-a-proofs-app-opens-the-proof-store-index`.
  This repo is not a Docker project: the hook tests run on the host.
- Tests: `bash hooks/tests/git-freshness-sync.test.sh`. It ends with `<n> passed, <m> failed` and exits non-zero
  when `m > 0`. Test-first: write the case, see it fail, then write the code.
- After every change to the hook: `bash -n hooks/git-freshness.sh` (syntax) and the test file.
- **No test writes to the real `~/Applications`**: the test file exports `GIT_FRESHNESS_APPS_DIR="$root/no-apps-dir"`
  at its top, and every case that builds passes its own `GIT_FRESHNESS_APPS_DIR` under `$root`.
- The overridable dir, verbatim: `apps_dir="${GIT_FRESHNESS_APPS_DIR-$HOME/Applications}"`.
- The report tag, verbatim: `built new app <name>` (appended to `config_tags`, like `linked new skill <name>`).
- **An existing app is never replaced** (`-e` or `-L` at `$apps_dir/<name>.app` → skip), whoever put it there.
- Compile into a fresh `mktemp -d` directory, then `mv` the bundle into place, then remove the temp directory: a
  source that fails to compile must leave nothing at `$apps_dir/<name>.app`.
- Skip the whole function when `osacompile` is not on `PATH`. A symlinked apps dir gets nothing; a missing one is
  created. Every path returns 0.
- The app opens Chrome by bundle id: `open -b com.google.Chrome`. No fallback to another browser.
- Alert texts, verbatim: title `No proof store index yet`, message `<path> does not exist: the first proof a
  /pipeline run files creates it.`; title `Could not open the proof store index`, message the error `open` gave.
- The app creates nothing: no directory, no file. The store path is hard-coded (`GitProjects/_proofs/index.html`
  under the home folder); `PIPELINE_PROOF_ROOT` does not reach a GUI app.
- No app icon, no bundle identifier (spec Assumptions 8–9). `~/.zshrc` is not edited (Assumption 7).
- `CLAUDE.md` is not changed. `skills/pipeline/SKILL.md` is not changed (sibling run #154 edits it).
- No changelog entry: the repo has no `.changelog/` and no `CHANGELOG.md`.
- Commits: no `Co-Authored-By`, no AI attribution; every message ends on `(#152)`.

## Review Focus

1. **A source that does not compile** — nothing appears at `$apps_dir/<name>.app`, nothing is reported, and once the
   source is fixed the next session builds it (a broken build must never block later ones). Pinned in Task 1, case 22.
2. **An app already there that the hook did not build** (a user's own, an older build) — left byte-for-byte alone and
   not reported. Pinned in Task 1, case 21 (`Taken.app` with a marker).
3. **The session after the one that built the app** — no rebuild, no second `built new app` line. Pinned in Task 1,
   case 21's second run.
4. **The hook's output when an app was built** — still exactly one line of valid JSON, with the tag in its
   `systemMessage`. Pinned in Task 1, case 21.
5. **A syntax error in the repo's own `Proofs.applescript`** — the hook would skip it silently on every machine, so
   the test file compiles every app source the repo ships. Pinned in Task 2, case 23.

## File Structure

- Modify `hooks/git-freshness.sh`: the header comment's config-repos paragraph (lines 45–51), the overridable dirs
  (after `agents_dir=` at line 72), a new function `build_new_skill_apps()` after `link_new_skill_files()` (ends at
  line 461), and its call in `sync_config_repos()` after `link_new_skill_files "$repo" agents md …` (line 515).
- Modify `hooks/tests/git-freshness-sync.test.sh`: one export at the top (after `GIT_FRESHNESS_AGENTS_DIR` at line
  54), cases 21–23 after case 20 (before the `echo "----…"` summary at line 452).
- Create `skills/pipeline/apps/Proofs.applescript`.
- Modify `README.md`: the `session` bullet (lines 66–69), a new `## Proofs app` section between §Status line and
  §Hook tests (before line 170), the §Hook tests closing sentence.
- Modify `skills/pipeline/references/engine.md` §The proof store: the end of the **The index** paragraph (ends at
  line 953, `…the order is the status order.`).

---

### Task 1: The session hook builds a skill's missing app

**Files:**
- Modify: `hooks/git-freshness.sh:45-51`, `:72`, after `:461`, `:515`
- Modify: `README.md:66-69`, the §Hook tests closing sentence
- Test: `hooks/tests/git-freshness-sync.test.sh` (export near line 54, cases 21–22 before line 452)

**Interfaces:**
- Consumes: the hook's globals `config_tags` (appended to, folded into the output by `emit()`), the test file's
  helpers `fixture <name> <n> [<file>]`, `push_upstream <fixture> <path> <content>`, `is`, `contains`, `lacks`,
  `ok`, `fail`, `json_is_valid`, and `$hook`, `$root`.
- Produces: `build_new_skill_apps <repo>` (bash function, returns 0), the env override `GIT_FRESHNESS_APPS_DIR`, the
  tag `built new app <name>`. Task 2 relies on the hook compiling `skills/pipeline/apps/Proofs.applescript` into
  `Proofs.app`.

Probed: `osacompile` exits non-zero on a source that does not compile and leaves nothing at `-o`: yes, `exit=1`, the
temp dir stayed empty (`d=$(mktemp -d); osacompile -o "$d/B.app" -e 'this is not ( applescript'; echo "exit=$?"; ls "$d"`).

- [ ] **Step 1: Pin the test file to a throwaway apps dir**

In `hooks/tests/git-freshness-sync.test.sh`, after the line
`export GIT_FRESHNESS_AGENTS_DIR="$root/no-agents-dir"`, add:

```bash
export GIT_FRESHNESS_APPS_DIR="$root/no-apps-dir"
```

- [ ] **Step 2: Write the failing cases 21 and 22**

Insert before the closing `echo "----------------------------------------"`:

```bash
echo "case 21: session start builds a skill's app when it is missing, and leaves an existing one alone"
if command -v osacompile >/dev/null 2>&1; then
    cfg=$(fixture config9 1 skills/flow/SKILL.md)
    push_upstream config9 skills/flow/apps/Hello.applescript 'display dialog "hello"'
    push_upstream config9 skills/flow/apps/Taken.applescript 'display dialog "taken"'
    apps="$root/config9/apps"
    mkdir -p "$apps/Taken.app"
    printf 'mine\n' > "$apps/Taken.app/marker"
    out=$(printf '%s' "{\"session_id\":\"test-config9a\",\"cwd\":\"$root/config9\"}" \
        | GIT_FRESHNESS_CONFIG_REPOS="$cfg" GIT_FRESHNESS_SKILLS_DIR="$root/config9/none" GIT_FRESHNESS_APPS_DIR="$apps" bash "$hook" session 2>/dev/null)
    is "$(printf '%s' "$out" | grep -c '')" "1" "emits exactly one line"
    if json_is_valid "$out"; then ok "output parses as JSON"; else fail "output parses as JSON" "$out"; fi
    if [ -f "$apps/Hello.app/Contents/Resources/Scripts/main.scpt" ]; then ok "the app is compiled under its source's base name"; else fail "the app is compiled under its source's base name"; fi
    is "$(ls "$apps/Taken.app")" "marker" "an existing app with the same name is left alone"
    is "$(cat "$apps/Taken.app/marker")" "mine" "its content is untouched"
    contains "$out" "built new app Hello" "the build is reported"
    lacks "$out" "built new app Taken" "the existing app is not reported as built"
    contains "$out" '"systemMessage"' "a new app earns a visible line"
    printf 'built once\n' > "$apps/Hello.app/marker"
    out=$(printf '%s' "{\"session_id\":\"test-config9b\",\"cwd\":\"$root/config9\"}" \
        | GIT_FRESHNESS_CONFIG_REPOS="$cfg" GIT_FRESHNESS_SKILLS_DIR="$root/config9/none" GIT_FRESHNESS_APPS_DIR="$apps" bash "$hook" session 2>/dev/null)
    is "$(cat "$apps/Hello.app/marker" 2>/dev/null)" "built once" "the next session does not rebuild an app it built"
    lacks "$out" "built new app" "the next session reports no build"
else
    echo "  skip  osacompile not found"
fi
echo

echo "case 22: a source that does not compile leaves nothing; a missing apps dir is created; a symlinked one gets nothing"
if command -v osacompile >/dev/null 2>&1; then
    cfg=$(fixture config10 1 skills/flow/SKILL.md)
    push_upstream config10 skills/flow/apps/Broken.applescript 'this is not ( applescript'
    push_upstream config10 skills/flow/apps/Hello.applescript 'display dialog "hello"'
    missing="$root/config10/new/apps"
    out=$(printf '%s' "{\"session_id\":\"test-config10a\",\"cwd\":\"$root/config10\"}" \
        | GIT_FRESHNESS_CONFIG_REPOS="$cfg" GIT_FRESHNESS_SKILLS_DIR="$root/config10/none" GIT_FRESHNESS_APPS_DIR="$missing" bash "$hook" session 2>/dev/null)
    if [ -f "$missing/Hello.app/Contents/Resources/Scripts/main.scpt" ]; then ok "the missing dir is created and the app built"; else fail "the missing dir is created and the app built"; fi
    if [ -e "$missing/Broken.app" ]; then fail "a source that does not compile leaves nothing behind"; else ok "a source that does not compile leaves nothing behind"; fi
    lacks "$out" "built new app Broken" "a failed build is not reported"
    push_upstream config10 skills/flow/apps/Broken.applescript 'display dialog "fixed"'
    out=$(printf '%s' "{\"session_id\":\"test-config10b\",\"cwd\":\"$root/config10\"}" \
        | GIT_FRESHNESS_CONFIG_REPOS="$cfg" GIT_FRESHNESS_SKILLS_DIR="$root/config10/none" GIT_FRESHNESS_APPS_DIR="$missing" bash "$hook" session 2>/dev/null)
    if [ -f "$missing/Broken.app/Contents/Resources/Scripts/main.scpt" ]; then ok "once fixed, the next session builds it"; else fail "once fixed, the next session builds it"; fi
    contains "$out" "built new app Broken" "the late build is reported"
    mkdir -p "$root/config10/realapps"
    ln -s "$root/config10/realapps" "$root/config10/apps-link"
    printf '%s' "{\"session_id\":\"test-config10c\",\"cwd\":\"$root/config10\"}" \
        | GIT_FRESHNESS_CONFIG_REPOS="$cfg" GIT_FRESHNESS_SKILLS_DIR="$root/config10/none" GIT_FRESHNESS_APPS_DIR="$root/config10/apps-link" bash "$hook" session >/dev/null 2>&1
    is "$(ls -A "$root/config10/realapps")" "" "nothing written through a symlinked apps dir"
else
    echo "  skip  osacompile not found"
fi
echo
```

`push_upstream` writes its third argument verbatim (`echo "$3"`), which is why the AppleScript goes through it and not
through `fixture`'s third argument (that appends `changed <n>` lines, which are not AppleScript).

- [ ] **Step 3: Run the tests to see 21 and 22 fail**

Run: `bash hooks/tests/git-freshness-sync.test.sh`
Expected: cases 1–20 pass; FAIL on `the app is compiled under its source's base name`, `the build is reported`,
`a new app earns a visible line` (no other config change happened, so no `systemMessage`), `the next session does
not rebuild an app it built`, `the missing dir is created and the app built`, `once fixed, the next session builds
it`, `the late build is reported`. The summary line ends `… 7 failed`, exit 1.

- [ ] **Step 4: Write `build_new_skill_apps()` and call it**

In `hooks/git-freshness.sh`, after `agents_dir="${GIT_FRESHNESS_AGENTS_DIR-$HOME/.claude/agents}"`:

```bash
apps_dir="${GIT_FRESHNESS_APPS_DIR-$HOME/Applications}"
```

After `link_new_skill_files()` (before the `# Link the status line script …` comment):

```bash
# Compile each AppleScript a skill in repo $1 ships under skills/<skill>/apps/*.applescript
# into the apps dir as <name>.app when no app of that name is there yet, reported as
# "built new app <name>". A built app does not follow its source: an existing app is
# never replaced, whoever put it there, and a changed source reaches a machine by
# deleting the app (README §Proofs app). It compiles into a temp dir and moves the
# bundle in, so a source that fails to compile leaves nothing the skip rule would keep.
# Same dir rules as link_new_skill_files; without osacompile (not macOS) it does nothing.
build_new_skill_apps() {
    local repo=$1 script name tmp

    command -v osacompile >/dev/null 2>&1 || return 0
    [ ! -L "$apps_dir" ] && mkdir -p "$apps_dir" 2>/dev/null || return 0

    for script in "$repo"/skills/*/apps/*.applescript; do
        [ -f "$script" ] || continue
        name=$(basename "$script" .applescript)
        { [ -e "$apps_dir/$name.app" ] || [ -L "$apps_dir/$name.app" ]; } && continue
        tmp=$(mktemp -d "${TMPDIR:-/tmp}/git-freshness-app.XXXXXX") || continue
        osacompile -o "$tmp/$name.app" "$script" >/dev/null 2>&1 \
            && mv "$tmp/$name.app" "$apps_dir/$name.app" 2>/dev/null \
            && config_tags="${config_tags}${config_tags:+, }built new app $name"
        rm -rf "$tmp"
    done

    return 0
}
```

In `sync_config_repos()`, after `link_new_skill_files "$repo" agents md "$agents_dir" agent`:

```bash
        build_new_skill_apps "$repo"
```

Replace the header comment's paragraph that starts `# The config repos get one more:` with:

```bash
# The config repos get one more: a skill that has no symlink in ~/.claude/skills
# yet is linked, and so is a skill's workflow script (skills/<skill>/workflow/*.js)
# that has none in ~/.claude/workflows, a skill's agent definition
# (skills/<skill>/agents/*.md) that has none in ~/.claude/agents, and the status
# line script when ~/.claude/statusline-command.sh does not exist; a skill's
# AppleScript app (skills/<skill>/apps/*.applescript) is compiled into
# ~/Applications when no app of that name is there. So each reaches every machine
# with its next session instead of waiting for a manual relink. An existing entry
# is never replaced.
```

- [ ] **Step 5: Check the syntax and run the tests**

Run: `bash -n hooks/git-freshness.sh && bash hooks/tests/git-freshness-sync.test.sh`
Expected: no syntax output; every case passes, the summary ends `… 0 failed`, exit 0.

- [ ] **Step 6: Document the build in the README**

In `README.md`, replace the `session` bullet under `` `git-freshness.sh` has three modes: `` with:

```markdown
- `session` — at startup: syncs both config repos (fast-forward only, never over local work) and
  links any skill that has no symlink yet (and any skill's `workflow/*.js` into
  `~/.claude/workflows/`, any skill's `agents/*.md` into `~/.claude/agents/`, and the status line
  script when `~/.claude/statusline-command.sh` does not exist), compiles any skill's
  `apps/*.applescript` into `~/Applications/` when no app of that name exists, then checks the
  launch directory.
```

In §Hook tests, replace `plus the config-repo sync
and skill linking.` with:

```markdown
plus the config-repo sync, skill linking and app building (the cases that compile print `skip` on a
machine without `osacompile`).
```

- [ ] **Step 7: Commit**

```bash
git add hooks/git-freshness.sh hooks/tests/git-freshness-sync.test.sh README.md
git commit -m "feat(hooks): the session hook compiles a skill's missing AppleScript app into ~/Applications (#152)"
```

---

### Task 2: The Proofs app source, checked by the hook tests, and its docs

**Files:**
- Create: `skills/pipeline/apps/Proofs.applescript`
- Modify: `README.md` (new `## Proofs app` before `## Hook tests`)
- Modify: `skills/pipeline/references/engine.md` (end of the **The index** paragraph, §The proof store)
- Test: `hooks/tests/git-freshness-sync.test.sh` (case 23 after case 22)

**Interfaces:**
- Consumes: Task 1's `build_new_skill_apps()` (it will compile this source into `Proofs.app` once merged), the test
  file's `$here`, `$root`, `ok`, `fail`, `is`.
- Produces: `skills/pipeline/apps/Proofs.applescript`, which Task 3 compiles into `~/Applications/Proofs.app`.

- [ ] **Step 1: Write the failing case 23**

Insert after case 22, before the closing `echo "----------------------------------------"`:

```bash
echo "case 23: the repo's own app sources compile"
if command -v osacompile >/dev/null 2>&1; then
    sources=0
    mkdir -p "$root/own-apps"
    for script in "$here"/../../skills/*/apps/*.applescript; do
        [ -f "$script" ] || continue
        sources=$((sources + 1))
        name=$(basename "$script" .applescript)
        if osacompile -o "$root/own-apps/$name.app" "$script" >/dev/null 2>&1; then ok "$name.applescript compiles"; else fail "$name.applescript compiles"; fi
    done
    is "$([ "$sources" -gt 0 ] && echo yes || echo no)" "yes" "the repo ships at least one app source"
else
    echo "  skip  osacompile not found"
fi
echo
```

- [ ] **Step 2: Run the tests to see 23 fail**

Run: `bash hooks/tests/git-freshness-sync.test.sh`
Expected: FAIL `the repo ships at least one app source` (`expected 'yes', got 'no'`); everything else passes; the
summary ends `… 1 failed`, exit 1.

- [ ] **Step 3: Write the source**

Create `skills/pipeline/apps/Proofs.applescript` (tabs for indentation, as Script Editor writes it):

```applescript
-- Proofs: opens the pipeline's proof store index (~/GitProjects/_proofs/index.html) in Chrome.
-- hooks/git-freshness.sh compiles this into ~/Applications/Proofs.app when no app of that name is there.

set storeIndex to POSIX path of (path to home folder) & "GitProjects/_proofs/index.html"

try
	do shell script "test -f " & quoted form of storeIndex
on error
	display alert "No proof store index yet" message storeIndex & " does not exist: the first proof a /pipeline run files creates it."
	return
end try

try
	do shell script "open -b com.google.Chrome " & quoted form of storeIndex
on error errorMessage
	display alert "Could not open the proof store index" message errorMessage
end try
```

`storeIndex`, not `index`: `index` is an AppleScript keyword. `path to home folder` ends in `/`, so the
concatenation needs no separator.

- [ ] **Step 4: Run the tests**

Run: `bash hooks/tests/git-freshness-sync.test.sh`
Expected: `ok    Proofs.applescript compiles`, `ok    the repo ships at least one app source`; the summary ends
`… 0 failed`, exit 0.

- [ ] **Step 5: Document the app**

In `README.md`, insert before `## Hook tests`:

````markdown
## Proofs app

`~/Applications/Proofs.app` opens the proof store index, `~/GitProjects/_proofs/index.html`, in Chrome: type
"proofs" in Alfred or Spotlight, or drag it to the Dock. When the index does not exist yet it says so and creates
nothing. Chrome, because the index's New/Updated markers read what a run page stored in `localStorage`, and
`file://` is one origin only in Chrome.

The `session` hook compiles it from `skills/pipeline/apps/Proofs.applescript` when no `Proofs.app` is in
`~/Applications`, and never replaces one. A changed source reaches a machine by deleting the app and starting a
session:

```bash
rm -rf ~/Applications/Proofs.app
```

For the terminal, an optional line in `~/.zshrc`; it replaces an older
`alias proofs='open ~/GitProjects/_proofs/index.html'` where a machine has one:

```bash
alias proofs='open ~/Applications/Proofs.app'
```

````

In `skills/pipeline/references/engine.md`, at the end of the **The index** paragraph, after
`Without \`localStorage\` nothing is marked and the order is the status order.`, append (same line, one space before):

```markdown
Open it with `~/Applications/Proofs.app` (Alfred, Spotlight or the Dock; README §Proofs app).
```

- [ ] **Step 6: Commit**

```bash
git add skills/pipeline/apps/Proofs.applescript hooks/tests/git-freshness-sync.test.sh README.md skills/pipeline/references/engine.md
git commit -m "feat(pipeline): a Proofs app opens the proof store index in Chrome (#152)"
```

---

### Task 3: Check the app on this machine

No unit test drives a GUI; this task is the issue's second *Verify* line, as far as a session can drive it (spec
Assumption 11). It writes to the real `~/Applications` once, and never to the real store. Nothing to commit.

**Files:** none changed.

**Interfaces:**
- Consumes: `skills/pipeline/apps/Proofs.applescript` from Task 2.
- Produces: `~/Applications/Proofs.app` on this machine, the same build the hook would make after the merge.

- [ ] **Step 1: Build the app where the hook would put it**

Run: `test ! -e ~/Applications/Proofs.app && osacompile -o ~/Applications/Proofs.app skills/pipeline/apps/Proofs.applescript && ls ~/Applications/Proofs.app/Contents/Resources/Scripts/`
Expected: `main.scpt`. (When `Proofs.app` is already there from an earlier pass of this step and the source has
changed since, `rm -rf ~/Applications/Proofs.app` first.)

- [ ] **Step 2: Spotlight finds it (what Alfred searches)**

Run: `mdimport ~/Applications/Proofs.app; mdfind -onlyin ~/Applications 'kMDItemFSName == "Proofs.app"'`
Expected: `/Users/jroelofs/Applications/Proofs.app`.

- [ ] **Step 3: It opens the index in Chrome**

Run: `open -W ~/Applications/Proofs.app && osascript -e 'tell application "Google Chrome" to get URL of active tab of front window'`
Expected: `file:///Users/jroelofs/GitProjects/_proofs/index.html`.

- [ ] **Step 4: A missing index gets the alert and nothing is created**

Run:

```bash
sed 's#GitProjects/_proofs/index.html#GitProjects/_proofs-missing-152/index.html#' skills/pipeline/apps/Proofs.applescript > "$TMPDIR/ProofsMissing.applescript"
osacompile -o "$TMPDIR/ProofsMissing.app" "$TMPDIR/ProofsMissing.applescript"
open "$TMPDIR/ProofsMissing.app"
```

Then `screencapture -x "$TMPDIR/proofs-missing-alert.png"` and read the image.
Expected: an alert titled `No proof store index yet` whose message names
`/Users/jroelofs/GitProjects/_proofs-missing-152/index.html`.

Then: `pkill -f ProofsMissing.app; test ! -e ~/GitProjects/_proofs-missing-152 && echo nothing-created; rm -rf "$TMPDIR/ProofsMissing.app" "$TMPDIR/ProofsMissing.applescript"`
Expected: `nothing-created`.

- [ ] **Step 5: Hand the GUI checks to the owner**

The PR body names, as the owner's checks: Alfred "proofs" + Enter opens the index in Chrome; `Proofs.app` can be
dragged to the Dock; on this machine `~/.zshrc`'s `alias proofs='open ~/GitProjects/_proofs/index.html'` can be
swapped for `alias proofs='open ~/Applications/Proofs.app'` (README §Proofs app); the other machine gets the app
from its first session after the merge.
