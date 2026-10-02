# LaravelClaudeMd

Claude Code instructions and personal skills for Laravel work at IT4WEB. `CLAUDE.md` lives here,
is symlinked to `~/.claude/CLAUDE.md`, and holds what every session needs. This README holds what a
person needs once per machine: bootstrapping, hook wiring and skill linking.

## The two config repos

Skills are pooled from two repos, both cloned one level deep — `~/GitProjects/<Repo>/<Repo>/`:

| Repo | Clone location |
|------|----------------|
| `IT4WEBBV/LaravelClaudeMd` | `~/GitProjects/LaravelClaudeMd/LaravelClaudeMd` |
| `IT4WEBBV/DevOps-Claude-Config` | `~/GitProjects/DevOps-Claude-Config/DevOps-Claude-Config` |

The nested layout matches `DevOps-Claude-Config`'s own README and its `memory-sync` skill, which
expects that path. Keep it so Mark's skills work unmodified.

## Bootstrapping a new machine

```bash
# 1. Clone both config repos into nested wrapper dirs (~/GitProjects/<Repo>/<Repo>/)
mkdir -p ~/GitProjects/LaravelClaudeMd ~/GitProjects/DevOps-Claude-Config
git clone git@github.com:IT4WEBBV/LaravelClaudeMd.git ~/GitProjects/LaravelClaudeMd/LaravelClaudeMd
git clone git@github.com:IT4WEBBV/DevOps-Claude-Config.git ~/GitProjects/DevOps-Claude-Config/DevOps-Claude-Config

# 2. Symlink CLAUDE.md as the global CLAUDE.md
ln -sfn ~/GitProjects/LaravelClaudeMd/LaravelClaudeMd/CLAUDE.md ~/.claude/CLAUDE.md

# 3. Make ~/.claude/skills a REAL directory and link every skill from BOTH repos
mkdir -p ~/.claude/skills
for repo in LaravelClaudeMd DevOps-Claude-Config; do
  for skill in ~/GitProjects/$repo/$repo/skills/*/; do
    ln -sfn "$skill" ~/.claude/skills/"$(basename "$skill")"
  done
done

# 4. Make the hook executable
chmod +x ~/GitProjects/LaravelClaudeMd/LaravelClaudeMd/hooks/git-freshness.sh

# 5. Link the status line script (settings.json's statusLine runs ~/.claude/statusline-command.sh)
ln -sfn ~/GitProjects/LaravelClaudeMd/LaravelClaudeMd/statusline/statusline-command.sh ~/.claude/statusline-command.sh
```

Then wire the hooks and the status line in `~/.claude/settings.json`. The scripts live in this repo and update with
every pull, so this wiring is the only per-machine step — and the one thing that does not reach
the other machine on its own:

```json
"hooks": {
  "SessionStart": [
    { "hooks": [ { "type": "command", "command": "$HOME/GitProjects/LaravelClaudeMd/LaravelClaudeMd/hooks/git-freshness.sh session", "timeout": 20, "statusMessage": "Checking git freshness…" } ] }
  ],
  "PostToolUse": [
    { "matcher": "Edit|Write", "hooks": [ { "type": "command", "command": "$HOME/GitProjects/LaravelClaudeMd/LaravelClaudeMd/hooks/git-freshness.sh edit", "timeout": 20, "statusMessage": "Checking git freshness…" } ] },
    { "matcher": "Bash", "hooks": [ { "type": "command", "command": "$HOME/GitProjects/LaravelClaudeMd/LaravelClaudeMd/hooks/git-freshness.sh checkout", "if": "Bash(git checkout:*)", "timeout": 10 } ] }
  ]
}
```

```json
"statusLine": { "type": "command", "command": "$HOME/.claude/statusline-command.sh", "refreshInterval": 5 }
```

`git-freshness.sh` has three modes:
- `session` — at startup: syncs both config repos (fast-forward only, never over local work) and
  links any skill that has no symlink yet (and any skill's `workflow/*.js` into
  `~/.claude/workflows/`, any skill's `agents/*.md` into `~/.claude/agents/`, and the status line
  script when `~/.claude/statusline-command.sh` does not exist), then checks the launch directory.
- `edit` — the repo owning the file being written, once per repo per session.
- `checkout` — drops cached verdicts after a branch switch.

### Permissions for unattended runs

A `/pipeline` run merges its base into its own branch when its brief says so (pipeline `engine.md`
§Catching up with the base). A denial in a background step is final, so allow them, and the `handoff`
command below, in `~/.claude/settings.json`: user level, so they reach every project on the machine, and
a per-machine step like the hooks:

```json
"permissions": { "allow": [
  "Bash(git -C * merge --no-edit origin/*)",
  "Bash(git -C * merge --abort)",
  "Bash(git -C * commit --no-edit)",
  "Bash(php */dispatch_cli.php handoff *)"
] }
```

The form matters: a rule matches the command as typed, so a project's `Bash(git merge:*)` does not cover
`git -C <worktree> merge`, and a chained command is matched part by part. The brief prints these
commands in exactly this form and tells the step to run each as its own command. A `*` in a Bash rule
matches any text, spaces included, and a command that matches an allow rule is decided there, before the
auto-mode classifier sees it (Claude Code docs, *permissions* §Wildcard patterns and *permission-modes*
§How the classifier evaluates actions).

Claude Code warns at startup about an allow rule with a `*` before the subcommand, and
`git -C * merge …` has that shape: the warning is expected, and the rules stay. What the docs leave open
is whether a rule that draws the warning still matches; the first batch after this lands shows it, and a
denial still halts the step with the command named. If the rules turn out inert, the form that avoids
the warning is `cd <worktree> && git merge --no-edit origin/<base>` with
`Bash(git merge --no-edit origin/*)`. That is a change of the brief line (`pipeline_catch_up_line()`)
and of these rules together, not a rule to swap by hand.

`git -C <worktree> add <file>` has no rule here: no `git add` rule matches the `-C` form either, and
those adds pass because the classifier allows them, as it does in every step that stages a file.

The `handoff` step pushes the branch and calls gh from inside one command,
`php <checks>/dispatch_cli.php handoff <manifest>`, as `kickoff` creates the worktree and edits the board
from inside one `php` call. The push is a run's first outward write, so its rule is listed above with
the merge rules: `Bash(php */dispatch_cli.php handoff *)`. The brief prints the command bare, with no
`cd … &&` in front, which is the form the rule matches. Without the rule a denial halts the step with
the command named; nothing is pushed by then, and a resume after the rule is added runs the command
again.

The pipeline skill's suite needs `node` on PATH: `AutoflowScriptTest` replays the autoflow Workflow
script under it, and fails rather than skips without it, so a machine without `node` has a red suite.

## Linking the skills

`~/.claude/skills/` is a **real directory** holding one symlink per skill (a single symlink could
only ever point at one of the two repos). Step 3 above links everything once. After that, the
`session` hook links each new skill by itself; it never replaces an existing entry. Existing skills
need no relinking — each symlink points back into its repo, so a pull updates them in place.

Re-running step 3 is safe. `-n` is required: without it, `ln -sf` follows the existing symlink and
drops the new link *inside* the old skill folder instead of replacing it. Neither the loop nor the
hook removes anything, so sweep the dangling link a renamed or removed skill leaves behind:

```bash
find ~/.claude/skills -maxdepth 1 -type l ! -exec test -e {} \; -print -delete
```

A newly linked skill is picked up by the **next** Claude Code session.

**Caveats**
- Only link the `skills/` folders. Do **not** symlink `DevOps-Claude-Config/settings.json` or its
  `CLAUDE.md` over yours — that repo is a colleague's personal config; its settings and
  instructions are not ours.
- Skill names must be unique across the two repos. On a clash the hook keeps whichever was linked
  first, and step 3 lets the second `ln` win — both silently. Rename one.

## Status line

`statusline/statusline-command.sh` is the status line: directory, branch, model, context and rate
limits on the first row, and below it one row per unfinished `autoflow` run of the session's repo,
read from the run manifests by `skills/pipeline/checks/statusline_cli.php`:

```
#415  implement  pending  47m  PR#419
```

The age is the time since the manifest last changed and turns yellow past 90 minutes without a step
boundary (commits do not touch the manifest, so this is not orchestrate's stall rule); a halted run
shows its reason in red; the issue and the PR are links. At most four rows. `refreshInterval: 5`
re-runs the script every five seconds, so the rows move while the session waits on its workflows; it
costs no tokens. Without runs, or without `php`, the first row is all there is.

`~/.claude/statusline-command.sh` is a symlink to it (step 5). The `session` hook creates that link when
nothing is there, never over an existing file. A machine that still has its own copy: compare it, then
replace it, and add `"refreshInterval": 5` to `statusLine` in `~/.claude/settings.json`:

```bash
diff ~/.claude/statusline-command.sh ~/GitProjects/LaravelClaudeMd/LaravelClaudeMd/statusline/statusline-command.sh
ln -sfn ~/GitProjects/LaravelClaudeMd/LaravelClaudeMd/statusline/statusline-command.sh ~/.claude/statusline-command.sh
```

Its tests: `bash statusline/tests/statusline.test.sh`; the rows' rendering is in the pipeline suite
(`StatuslineTest.php`).

## Hook tests

Run after changing the hook:

```bash
bash hooks/tests/git-freshness-sync.test.sh
```

It builds throwaway repos under `$TMPDIR` and covers every branch of the base-branch sync,
including the sibling-worktree case that is easy to get silently wrong, plus the config-repo sync
and skill linking.
