# A live status line of the `autoflow` runs, read from the pipeline manifests — design

**Design size:** Architectural

**Date:** 2026-09-29
**Issue:** IT4WEBBV/LaravelClaudeMd#91
**Canonical home:** `skills/pipeline/checks/statusline.php` (discovery and rendering) and
`statusline/statusline-command.sh` (the status line itself, moved into this repo); README §Status line
holds the per-machine setup.
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. Every question the brainstorm
would have asked is answered in *Assumptions*, so `/critique plan` audits exactly those. Nothing below was
built or run. The status line facts come from the Claude Code docs (`code.claude.com/docs/en/statusline`,
read 2026-09-29); the manifest facts from `dispatch_cli.php`, `kickoff.php` and this repo's live
worktrees.

## Problem

An `/orchestrate` session drives up to four `autoflow` runs and has no overview of which issue is at
which step. Finding out means asking the orchestrator (tokens, and an interrupted turn) or opening each
slot's manifest by hand.

## Settled direction

- **The issue** (#91): a status line section read from the manifests the pipeline already writes; no new
  state file, no new writes, no `gh` calls. `mode: autoflow` only, skip finished runs. One line per run,
  capped at four: `#415  implement  pending  47m  PR#419`. Age from the manifest's mtime, warning color
  past 90 minutes; `halted` in error color with its reason, truncated; the issue and PR numbers as OSC 8
  links. `refreshInterval: 5`. Discovery through `git worktree list --porcelain` from
  `workspace.current_dir`, bound to the repo, not the session. Tests render fixture manifests (running,
  halted, stale, done, `auto`, missing `artifacts.pr`).
- **The owner** (manifest `decisions`): the current machine-local `~/.claude/statusline-command.sh`
  moves into this repo with the new section, so both machines get it. `~/.claude/statusline-command.sh`
  becomes a symlink to the repo file, set up through the README's machine setup and linked by
  `hooks/git-freshness.sh` where that fits. `refreshInterval: 5` is documented in the setup. The run does
  not replace the local file or edit `~/.claude/settings.json`; the PR body says the owner runs the setup
  step on each machine after merge.

## What the platform gives (Claude Code docs, statusline)

- *Multiple lines*: "each `echo` or `print` statement displays as a separate row".
- *Links*: OSC 8, `ESC ]8;;URL BEL text ESC ]8;; BEL`, as the docs' Python example writes it.
- `refreshInterval`: "re-runs your command every N seconds in addition to the event-driven updates. The
  minimum is `1`." It is a key of the `statusLine` setting, beside `command`. The docs name the case
  this issue has: event triggers "can go quiet when the main session is idle, for example while a
  coordinator waits on background subagents".
- "Scripts that exit with non-zero codes or produce no output cause the status line to go blank", and a
  new update cancels a script still running. The section must therefore never fail the status line and
  must stay fast.
- `workspace.current_dir` is on stdin (the current script already reads it).

## A fact the issue missed: the snapshot beside the manifest

The issue's discovery globs `.claude/pipeline/*.json`. Every `autoflow` step boundary also writes
`<stem>.before.json` there (`dispatch_cli_files()`, written by `dispatch_cli_emit()` and `brief`), a copy
of the manifest with the same `mode` and `cursor`. All four live worktrees of this repo hold one. A glob
would print every run twice. So discovery does not glob: each worktree entry of `git worktree list
--porcelain` names its branch, and the manifest path is the one kickoff writes,
`<worktree>/.claude/pipeline/<branch with / as ->.json`. That rule becomes `manifest_path()` in
`manifest.php`, used by kickoff and by the status line, so the two cannot drift.

## Approaches

1. **PHP renderer in the pipeline's `checks/`, called by the moved Python status line (chosen).**
   `skills/pipeline/checks/statusline.php` discovers and renders; `statusline_cli.php <cwd>` prints the
   lines; the moved `statusline-command.sh` runs it and appends its output below the existing line.
   It reuses `manifest_read`, the manifest path rule, `pipeline_git_run` and
   `pipeline_repo_config_value`, and its tests join the pipeline suite, which every change to the
   manifest's shape already runs.
2. **The section in Python, inside the status line script.** One process fewer (~30 ms of PHP start
   every 5 s saved), but it re-implements the manifest path rule, the finished rule and the config parse
   outside the tested PHP, and needs a Python test harness this repo does not have. Rejected.
3. **Rewrite the whole status line in PHP.** One language, but a rewrite of a working 150-line script
   for no user-visible gain, and the owner decided to *move* it. Rejected.

**Where the renderer lives.** The issue suggests `skills/orchestrate/statusline.php`. It reads the
pipeline's own file format, and it shows any `autoflow` run in the repo, one started by `/pipeline` from
an interactive session as much as one an orchestrator started. The pipeline suite is also the one that
fails when the manifest's shape changes (`LockStepTest`, `ManifestTest`); `orchestrate` has no PHP suite.
So it lives in `skills/pipeline/checks/`.

## Design

### Units

| Unit | Does | Depends on |
|---|---|---|
| `manifest_path(string $worktree, string $branch): string` (`manifest.php`) | `<worktree>/.claude/pipeline/<branch, / → ->.json`; kickoff's `pipeline_kickoff_prepare()` calls it instead of building the path inline | nothing |
| `manifest_finished(array $manifest): bool` (`manifest.php`) | `cursor.status === 'done'` or `cursor.leg === 'done'`; moved from `dispatch_cli_finished()`, whose two call sites use it | nothing |
| `pipeline_status_scan(string $cwd): array{repo: ?string, runs: list<array{manifest: array, mtime: int}>}` (`statusline.php`) | `git -C <cwd> worktree list --porcelain`; for each entry with a `branch refs/heads/<b>`, reads `manifest_path(<worktree>, <b>)`; `repo` is `repo:` under `## Repo` of the **first** entry's (the primary checkout's) `.claude/work-on.config.md`, else null. A cwd outside a repo, or a git failure, is `['repo' => null, 'runs' => []]` | `pipeline_git_run`, `manifest_read`, `manifest_path`, `pipeline_repo_config_value` |
| `pipeline_status_lines(array $runs, int $now, ?string $repo): list<string>` | filter, order, cap, render (below). Pure | `pipeline_status_line` |
| `pipeline_status_line(array $manifest, int $age, ?string $repo): string` | one run's line. Pure | `pipeline_status_age`, `pipeline_status_link` |
| `pipeline_status_age(int $seconds): string` | `47m`, `1h05m`, `2d` | nothing |
| `pipeline_status_link(string $text, ?string $url): string` | OSC 8 around `$text`; `$text` alone when `$url` is null | nothing |
| `statusline_cli.php <cwd>` | routes PHP warnings to stderr (`ini_set('display_errors', 'stderr')`), then prints `pipeline_status_lines(scan runs, time(), scan repo)` joined by `\n`, no trailing newline; nothing when there are none; exit 0 | `statusline.php` |
| `statusline/statusline-command.sh` | the current `~/.claude/statusline-command.sh`, byte for byte, plus the runs section | `php`, `statusline_cli.php` |

### What a line shows

Filter: `mode === 'autoflow'` and not `manifest_finished()`. Everything else (`interactive`, `auto`, a
manifest without `mode`) shows nothing.

`<issue>  <leg>  <status>  <age>[  <PR>][  <reason>]`, fields separated by two spaces, no padding:

- **issue**: `#<artifacts.issue>`, linked to `https://github.com/<repo>/issues/<n>`; without an issue
  (a run from an idea) the manifest's `branch`, unlinked.
- **leg**: `cursor.leg`. **status**: `cursor.status`.
- **age**: `$now - mtime`, by `pipeline_status_age()`: under an hour `<m>m` (`0m` under a minute), under
  a day `<h>h<mm>m` (`1h05m`), else `<d>d`. **Past 90 minutes** (`> 5400` s) the age is wrapped in
  yellow, `\033[33m…\033[0m`, the warning color the status line already uses. Yellow means no step
  boundary for 90 minutes: commits and PR changes do not touch the manifest, so this is not
  orchestrate's stall rule, which counts them too.
- **PR**: `PR#<artifacts.pr>` (issue and PR cast to `int` before they enter a URL) linked to `https://github.com/<repo>/pull/<n>`; absent without
  `artifacts.pr`.
- **halted**: the status word and the reason in red, `\033[31m…\033[0m`, each wrapped on its own. The
  reason is `cursor.reason` with control characters removed, whitespace runs collapsed to one space,
  trimmed, cut by `mb_strimwidth(…, 0, 60, '…')`. No reason, no segment.
- Links are `\033]8;;<url>\a<text>\033]8;;\a`; with `repo` null the text stands alone.

Order: halted runs first (they need the owner), then by issue number ascending, runs without an issue
last, then by branch. Stable across refreshes, so rows do not jump when a step starts. **At most four
lines**; with more runs, the fourth line ends in `  +<n> more`.

Examples (`repo` null):

```
#415  implement  pending  47m  PR#419
#420  design  halted  12m  the diff file is missing
#431  review-pr  continued  1h52m  PR#433        ← age in yellow
```

### The status line script

`statusline/statusline-command.sh` is the current file, copied unchanged (sha256
`d99c4916b544907a8a5c9cb04d38e67cca252b16bde1f836ec7c60cc0979f30e` on this machine, 4746 bytes), executable. Two edits:

1. A function before the final print:

   ```python
   RUNS_CLI = os.path.join(os.path.dirname(os.path.realpath(__file__)), '..', 'skills', 'pipeline', 'checks', 'statusline_cli.php')


   def autoflow_runs(cwd):
       if not cwd or not os.path.isfile(RUNS_CLI):
           return ''
       try:
           result = subprocess.run(['php', RUNS_CLI, cwd], capture_output=True, text=True, timeout=2)
       except (OSError, subprocess.TimeoutExpired):
           return ''
       return result.stdout.rstrip('\n') if result.returncode == 0 else ''
   ```

   `realpath` resolves `~/.claude/statusline-command.sh` to the repo file, so the CLI is found through
   the symlink. A missing `php`, a PHP fatal (exit 255, its message on stdout under CLI
   `display_errors`), a slow run: the section is empty and the first line prints exactly as before.
   A warning or a deprecation is different: CLI PHP prints it on stdout and still exits 0, so the
   Python side would show it as a row. `statusline_cli.php` therefore sets `display_errors` to
   `stderr` first; stdout holds only rows, and a fatal still exits 255.
2. The final print appends `"\n" + runs` when `runs` is not empty; otherwise it is unchanged.

### Wiring (the owner's decision)

- **README**, a new §Status line: what it shows; bootstrap step 5,
  `ln -sfn ~/GitProjects/LaravelClaudeMd/LaravelClaudeMd/statusline/statusline-command.sh ~/.claude/statusline-command.sh`;
  the `statusLine` setting with `"refreshInterval": 5`; and, for a machine that already has its own
  file, "compare it with the repo's first (`diff`), then the same `ln -sfn` replaces it". The
  settings-wiring paragraph lists `statusLine` beside the hooks as a per-machine step.
- **`hooks/git-freshness.sh`**, `link_statusline`, called from `sync_config_repos` beside
  `link_new_skills`: when a config repo has `statusline/statusline-command.sh` and the status line path
  (`GIT_FRESHNESS_STATUSLINE`, default `$HOME/.claude/statusline-command.sh`) is neither a file nor a
  link, `ln -s` it and tag `linked the status line`. **An existing entry is never replaced**, the hook's
  rule for skills and workflows, so on both current machines the hook does nothing: the owner's setup
  step replaces the local file. What the hook adds is a new machine getting the link with its first
  session. It does not touch `settings.json`.
- **PR body**: after merge, on each machine, the owner runs the README step (diff, `ln -sfn`) and adds
  `"refreshInterval": 5` to `statusLine` in `~/.claude/settings.json`.

### Docs

- Pipeline `SKILL.md`, a bullet in the overview list: **Run status line** — the status line shows each
  unfinished `autoflow` run in the repo from its manifest (`checks/statusline.php`); setup in the README.
- `references/manifest.md`, the helpers paragraph: `manifest_path` and `manifest_finished` join the
  helper list, and one sentence: the status line reads manifests and never writes them.
- Orchestrate `SKILL.md`, §Where it runs: one sentence — the owner watches the runs on the status line
  (pipeline `SKILL.md`), the orchestrator never reads it.

## Tests

Written first, seen red.

- **`StatuslineTest.php`** (pipeline suite; `Pest.php` loads `statusline.php`), on fixture manifests:
  a running run (exact line); links with a repo (exact OSC 8 string); no `artifacts.pr`; halted (red
  status, reason collapsed and cut at 60 with `…`); stale (91 minutes yellow, 90 not); `status: done`,
  `leg: done`, `auto`, `interactive` and no `mode` show nothing; the age format (`0m`, `59m`, `1h05m`,
  `2d`); no issue shows the branch; order (halted first, then issue) and the cap (`+2 more` on the fourth
  of six).
- **Discovery**, same file: a throwaway repo (`suite_repo()`) with two worktrees, an `autoflow` manifest
  and its `.before.json` in one, an `interactive` one in the other, `- repo: acme/app` in the primary's
  config: `statusline_cli.php <worktree>/sub` prints exactly one line, linked to `acme/app`. A temp dir
  outside git prints nothing and exits 0. A manifest with `mode: autoflow` and an empty `cursor`
  through `statusline_cli.php` prints its row and no `Warning` on stdout.
- **`ManifestTest.php`**: `manifest_path()` for a branch with and without `/`; `manifest_finished()`.
  The existing kickoff and dispatch tests cover the two refactored call sites.
- **`statusline/tests/statusline.test.sh`** (bash, like `hooks/tests/`): the script outside a repo prints
  one line and no run; in a fixture repo with an `autoflow` manifest it prints two, the second holding
  the issue; through a symlink the same; with a fake `php` that exits 1 first on `PATH`, one line.
- **`hooks/tests/git-freshness-sync.test.sh`**: `GIT_FRESHNESS_STATUSLINE` isolated at the top like the
  other paths; a new case: absent → linked and reported; an existing regular file → left alone, not
  reported.

The pipeline suite, the status line test and the hook tests pass. *Within seconds of a step change
without an orchestrator turn* rests on `refreshInterval`, which no suite can run: the owner sees it after
the setup step, and the PR body says so.

## Out of scope

- `~/.claude/subagent-statusline.sh`, the other machine-local status line: not asked for.
- The step within a leg (`review` / `resolve`): it lives only in the `.before.json` snapshot's cursor;
  the line shows the leg, as the issue's example does.
- Binding runs to the orchestrating session through `lease`: nothing writes it (the issue).
- A hook reminder while the local file is still a regular file: the PR body carries the one-time step.
- An atomic `manifest_write`: it is a plain `file_put_contents`, so a read that lands mid-write decodes
  to null and that run's row is gone for one 5-second refresh. Harmless flicker, not a bug.

## Assumptions

Questions the brainstorm would have asked the owner, with the answer assumed.

1. **Where does the moved script live, and under which name?** `statusline/statusline-command.sh` at the
   repo root, the name unchanged so the symlink `~/.claude/statusline-command.sh` → repo file reads as
   what it is and `settings.json`'s `command` stays as it is. It is not a hook, so not under `hooks/`.
2. **Whose copy moves when the two machines differ?** This machine's (sha256 above). The README step and
   the PR body say to `diff` the other machine's file against the repo's before `ln -sfn`, so a local
   tweak is seen, not lost.
3. **Renderer in `skills/pipeline/checks/` rather than `skills/orchestrate/`?** Yes (*Approaches*): it
   reads the pipeline's file, shows any `autoflow` run, and belongs to the suite that guards the
   manifest's shape.
4. **Glob `*.json` as the issue says?** No: it would double every run through `.before.json`. The path
   comes from each worktree's branch, by the rule kickoff writes it with.
5. **Where does the repo name for the links come from, with no `gh` call?** `repo:` in the primary
   checkout's `.claude/work-on.config.md`, which kickoff already requires for every `autoflow` run. Not
   the `origin` URL, whose SSH and HTTPS spellings would need parsing. No config, no links.
6. **Which runs count as finished?** `manifest_finished()`, the dispatcher's own rule (`status: done` or
   the old engine's `leg: done`), moved so both read one definition.
7. **Column alignment?** None: the issue's two-space format. At most four rows; aligning would need
   padding outside the OSC 8 and color codes for little gain.
8. **More than four runs?** The first four in order, and `+<n> more` on the fourth, so a hidden run is
   never silent. Halted runs sort first because they are the ones the owner has to act on.
9. **Truncation length for the reason?** 60 display columns with `…`: long enough for a
   `dispatch_cli.php` halt's subject, short enough for one row beside the rest.
10. **Age past a day?** `<d>d`: a halted run can sit for days, and hours and minutes stop mattering there.
11. **Does the hook replace or nag about an existing local file?** Neither: it links only when nothing is
    there (its rule for skills and workflows), and the one-time replacement is the owner's setup step.
12. **Does the hook write `refreshInterval` into `settings.json`?** No. The run edits no settings; the
    README and the PR body document it (owner's decision).
13. **Is `php` on the status line's `PATH`?** Assumed yes: the pipeline already runs `php` on the host on
    both machines. When it is not, the section is empty and the first line is unchanged.
14. **Changelog?** This repository has no `.changelog/` and no `CHANGELOG.md`, so none is written.
15. **Does this PR close #91?** Yes, every *Done when* line is built; the refresh one is confirmed by the
    owner after the setup step. `review-pr` settles the closing link (engine.md §Closing links).
