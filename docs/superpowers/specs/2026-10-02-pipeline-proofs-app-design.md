# A Proofs app opens the proof store index — design

**Design size:** Architectural (the run requires the Architectural path; the change is one AppleScript source, one
hook function and its tests, and docs)

**Date:** 2026-10-02
**Issue:** IT4WEBBV/LaravelClaudeMd#152
**Canonical home:** `skills/pipeline/apps/Proofs.applescript` (new), `hooks/git-freshness.sh`
(`build_new_skill_apps()`, called from `sync_config_repos()`), `hooks/tests/git-freshness-sync.test.sh`,
`README.md` (§Proofs app, the `session` mode bullet), pipeline `references/engine.md` §The proof store (one
sentence).
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. The issue body is the approved scope and
is not re-litigated here. Every question the brainstorm would have asked is answered in *Assumptions*, so
`/critique plan` audits exactly those. Nothing of the design was built or run; one throwaway probe answered whether
the chosen approach works at all (see *Approaches*).

## Problem

The proof store index, `~/GitProjects/_proofs/index.html` (`proof_root()` in `skills/pipeline/checks/proof.php`,
written by `proof_store_file()` and `proof_store_amend()`), has no quick way in. The one shortcut is a line in this
machine's `~/.zshrc`, `alias proofs='open ~/GitProjects/_proofs/index.html'`: terminal only, this machine only, and
it opens the default browser, while the index's New/Updated markers rely on Chrome (`file://` is one origin in
Chrome, so a page's `seen:` write is readable by the index; engine.md §The proof store, #142).

## Approaches

1. **An AppleScript source in the repo, compiled by the session hook into `~/Applications/Proofs.app` when
   missing (chosen).** `osacompile` ships with macOS, so no dependency is added; the result is a real application
   bundle in a standard location, which Spotlight and Alfred index and the Dock accepts. AppleScript gives the
   "says so" message natively (`display alert`), and the app needs no `php`, which a GUI app's `PATH` would not
   find. It mirrors how the hook already links a skill's workflow scripts and agent definitions: a file under
   `skills/<skill>/<kind>/` reaches a per-machine directory when it has no entry there yet, never over one.
   Probed: `osacompile` builds a complete applet bundle from a few lines with `do shell script … quoted form of …`
   inside `try` and `display alert`: yes, `Contents/MacOS/applet`, `Contents/Resources/Scripts/main.scpt`,
   `Contents/Info.plist`, ad-hoc signed, in 0.06 s (`osacompile -o "$(mktemp -d)/Probe.app" -e …`, deleted
   after).
2. **A hand-written `.app` bundle committed to the repo (`Info.plist` plus an executable shell script),
   symlinked into `~/Applications`.** Updates would flow with every pull, but Spotlight does not index symbolic
   links, and Alfred finds applications through Spotlight's metadata, so "type proofs in Alfred or Spotlight"
   would fail; a shell-script bundle also needs `osascript` for its message anyway. Rejected.
3. **A compiled stub that delegates to a script in the repo** (the app runs
   `~/GitProjects/LaravelClaudeMd/LaravelClaudeMd/…/open-proofs.sh`). Behaviour changes would reach the app
   without a rebuild, at the cost of two files, a repo path baked into the app, and a dialog shelled out through
   `osascript`. The app is a dozen lines that will rarely change; a documented rebuild (delete it, start a session)
   is cheaper than the indirection. Rejected.

## Design

### The source: `skills/pipeline/apps/Proofs.applescript`

The app belongs to the pipeline skill (it opens that skill's store), so it lives beside the skill's `workflow/` and
`agents/`. The file's base name is the app's name: `Proofs.applescript` builds `Proofs.app`.

What it does, in order:

1. Builds the index path from the home folder: `POSIX path of (path to home folder) & "GitProjects/_proofs/index.html"`
   (`path to home folder` ends in `/`). It hard-codes the store path `proof_root()` defaults to; the
   `PIPELINE_PROOF_ROOT` override exists for tests and does not reach a GUI app.
2. Checks the file with `do shell script "test -f " & quoted form of <path>` inside `try`. When the check fails
   (no store directory, or a store without `index.html`) it shows
   `display alert "No proof store index yet" message "<path> does not exist: the first proof a /pipeline run files creates it."`
   and returns. It creates nothing: no directory, no file.
3. Otherwise opens the file in Chrome by bundle id, `do shell script "open -b com.google.Chrome " & quoted form of <path>`
   (`com.google.Chrome` is Chrome's `CFBundleIdentifier`, read with `mdls` on this machine; a bundle id survives a
   renamed or relocated app where `open -a "Google Chrome"` would not). When `open` fails (Chrome not installed)
   it shows `display alert "Could not open the proof store index" message <the error>` rather than falling back to
   another browser silently.

A store that exists but holds no runs has an `index.html` (the index is rewritten on every write and prune, and
`proof_render_index()` renders `No runs recorded.` for an empty list), so step 3 opens it: "empty" needs no case of
its own.

The source starts with a two-line comment naming what it is and that `hooks/git-freshness.sh` builds it into
`~/Applications`. Variable names avoid AppleScript keywords (`storeIndex`, not `index`).

### The build: `build_new_skill_apps()` in `hooks/git-freshness.sh`

A new overridable directory beside the others, `apps_dir="${GIT_FRESHNESS_APPS_DIR-$HOME/Applications}"`, and a
function called from `sync_config_repos()` after `link_new_skill_files … agents …`:

```bash
build_new_skill_apps "$repo"
```

For each `"$repo"/skills/*/apps/*.applescript`, with `name` the base name without `.applescript`:

- Skip when `osacompile` is not on `PATH` (`command -v osacompile`): a Linux box has no `~/Applications` to fill.
  This guard is once per call, before the loop.
- Same directory rules as `link_new_skill_files()`: a symlinked `apps_dir` gets nothing, a missing one is created
  (`[ ! -L "$apps_dir" ] && mkdir -p "$apps_dir" || return 0`).
- Skip when `$apps_dir/$name.app` exists or is a link (`-e` or `-L`): **an existing app is never replaced**,
  whether the hook built it, it is an older build, or someone put their own there.
- Otherwise compile into a fresh `mktemp -d` directory and move the bundle into place, then remove the temporary
  directory:
  `osacompile -o "$tmp/$name.app" "$source" >/dev/null 2>&1 && mv "$tmp/$name.app" "$apps_dir/$name.app"`.
  Compiling beside the destination rather than into it means a source that fails to compile leaves nothing at
  `$apps_dir/$name.app`, so it cannot block every later session with a broken bundle the skip rule would keep.
- On success append `built new app $name` to `config_tags`, which `emit()` folds into the session's one hook line
  as it does for `linked new skill …`.

Every path returns 0, as everywhere in the hook. The cost is one `osacompile` (about 0.06 s, probed above) only in
a session where an app is missing; otherwise a glob and a `-e` test.

The header comment's paragraph that lists what the config repos get ("a skill that has no symlink … the status line
script …") gains the app: a skill's AppleScript app (`skills/<skill>/apps/*.applescript`) is compiled into
`~/Applications` when no app of that name is there.

### The alias

The README names it as an optional per-machine line rather than shipping shell config: the repo has no shared
shell setup, and adding one for one alias is more machinery than the alias. The line goes through the app, so the
terminal gets the same Chrome choice and the same missing-store message:

```bash
alias proofs='open ~/Applications/Proofs.app'
```

### Docs

- **README.md**
  - The `session` bullet under `git-freshness.sh has three modes` adds "and compiles any skill's
    `apps/*.applescript` into `~/Applications/` when no app of that name exists".
  - A new short section **Proofs app**, after §Status line: `~/Applications/Proofs.app` opens
    `~/GitProjects/_proofs/index.html` in Chrome (Alfred or Spotlight "proofs", or the Dock: drag it there), or
    says the index does not exist yet. The `session` hook builds it from `skills/pipeline/apps/Proofs.applescript`
    when it is missing and never replaces it, so a changed source reaches a machine by deleting the app
    (`rm -rf ~/Applications/Proofs.app`) and starting a session. The optional alias line above, replacing the old
    `open ~/GitProjects/_proofs/index.html` alias where a machine has one.
- **engine.md §The proof store**, the *The index* paragraph: one sentence at its end, "Open it with
  `~/Applications/Proofs.app` (Alfred, Spotlight or the Dock; README §Proofs app)."
- `CLAUDE.md` §Skills and hooks is left alone: it names the hook's linking in one line for sessions, and the app is
  machine setup, which it points to the README for.

## Testing

All in `hooks/tests/git-freshness-sync.test.sh`, which is what the issue's *Verify* names ("the hook tests cover the
link step"). The file's top exports `GIT_FRESHNESS_APPS_DIR="$root/no-apps-dir"` beside the other overrides, so no
case can write to the real `~/Applications`. Cases that compile are skipped with a printed `skip` line when
`osacompile` is missing, so the file still passes on a box without it.

- **Case 21: session start builds a skill's app when it is missing, and leaves an existing one alone.** A fixture
  config repo with `skills/flow/SKILL.md`, then `push_upstream` of `skills/flow/apps/Hello.applescript` (valid,
  `display dialog "hello"`) and `skills/flow/apps/Taken.applescript`; `$apps/Taken.app` pre-exists as a directory
  holding a marker file. After `session`:
  - `$apps/Hello.app/Contents/Resources/Scripts/main.scpt` exists (built);
  - `$apps/Taken.app` still holds only the marker (untouched);
  - the output contains `built new app Hello` and lacks `built new app Taken`.
  (`push_upstream` writes the content verbatim, where `make_fixture`'s third argument appends `changed <n>` lines
  that are not AppleScript.)
- **Case 22: a source that does not compile leaves nothing behind; a symlinked apps dir gets nothing; a missing
  one is created.** `Broken.applescript` with `this is not ( applescript` → no `$apps/Broken.app`, no report; a
  symlinked apps dir → nothing written through it; an apps dir that does not exist yet → created and the app built.
- **Case 23: the repo's own app sources compile.** Every `skills/*/apps/*.applescript` of the checkout the tests
  live in compiles with `osacompile` into `$root`. This is what catches a syntax error in `Proofs.applescript`
  before a session silently skips it on every machine.

The app's run-time behaviour (Chrome opens the index; the alert when it is missing) is not unit-testable without
driving a GUI; implement checks it by hand on this machine, which is the issue's second *Verify* line: Alfred
"proofs" + Enter opens the index in Chrome, and the app can be dragged to the Dock. The missing-index alert is
checked without touching the real store: a copy of the source with `GitProjects/_proofs/index.html` replaced by a
path that does not exist, compiled into `$TMPDIR` and opened once, shows the alert and creates nothing.

## Assumptions

1. **Where does the source live, `skills/pipeline/` or a new top-level `apps/`?** Assumed
   `skills/pipeline/apps/Proofs.applescript`: the app belongs to the pipeline skill, and the hook already walks
   `skills/*/<kind>/` for workflow scripts and agents, so the build follows the same shape.
2. **Symlink a committed bundle, or build one per machine?** Assumed build with `osacompile` (Approaches 1 and 2):
   Spotlight does not index symbolic links, and Alfred's application search rides on Spotlight, so a symlinked app
   would not be found by name. The cost accepted: a built app does not follow source changes on its own.
3. **Should the hook rebuild an app whose source is newer?** Assumed no. The issue says an existing app is never
   replaced; the README documents the rebuild (delete it, start a session).
4. **Is `~/Applications` indexed by Alfred on these machines?** Assumed yes: it is a standard per-user
   applications folder in Spotlight's index, and this machine's Alfred has no custom default-results scope
   (`preferences/features/defaultresults/prefs.plist` does not exist). Implement confirms it with the issue's
   Alfred check; if Alfred misses it, adding `~/Applications` to Alfred's search scope is a one-time per-machine
   setting the README would name.
5. **Chrome missing: fall back to the default browser?** Assumed no: the app shows an alert naming the error. Both
   machines have Chrome, the index's markers rely on it, and a silent fallback would show an index whose New/Updated
   markers quietly stop working.
6. **"Empty or missing store": what counts as which?** Assumed: no `index.html` (no directory, or a directory
   without the file) gets the alert; a store whose index lists no runs is opened, since the index itself says
   `No runs recorded.`
7. **Does implement edit `~/.zshrc` on this machine?** Assumed no: it is the owner's machine-local file, outside
   the repo and the worktree. The README carries the replacement line; swapping it on this machine is the owner's
   one-line edit, named in the PR body.
8. **An app icon?** Assumed the default applet icon `osacompile` gives. A custom icon is a binary asset to keep in
   sync with nothing; YAGNI.
9. **A bundle identifier?** Assumed none: `osacompile` sets no `CFBundleIdentifier` (probed: `PlistBuddy` reports
   the entry missing), and nothing here looks the app up by id; the alias opens it by path.
10. **Does sibling run #154 touch these files?** #154 changes when pages open and adds `status.js`; it edits the
    proof scripts, `SKILL.md` and engine.md §The proof store. This design touches engine.md in one sentence at the
    end of the *The index* paragraph and leaves `SKILL.md` alone, to keep a merge of the base small.

## What was read

- `hooks/git-freshness.sh`: `sync_config_repos()`, `link_new_skills()`, `link_new_skill_files()`,
  `link_statusline()`, the overridable directory variables, `emit()`, the header comment.
- `hooks/tests/git-freshness-sync.test.sh`: the overrides at the top, `make_fixture()`, `push_upstream()`, cases
  12–20.
- `skills/pipeline/checks/proof.php` (`proof_root()`, `proof_open_argv()`), `proof_cli.php` (`open`),
  `proof_store.php` (index writes), `proof_render.php` (`No runs recorded.`, the `seen:` script).
- `README.md` (bootstrap, hook modes, §Status line), engine.md §The proof store, pipeline `SKILL.md`, issue #154.
- On this machine: `~/.zshrc` line 144 (the alias), `/usr/bin/osacompile`, `/Applications/Google Chrome.app`
  (`kMDItemCFBundleIdentifier = "com.google.Chrome"`), `~/Applications` exists, Alfred has no custom scope file.
