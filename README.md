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
# 1. Clone both config repos and the memory vault into nested wrapper dirs (~/GitProjects/<Repo>/<Repo>/)
mkdir -p ~/GitProjects/LaravelClaudeMd ~/GitProjects/DevOps-Claude-Config ~/GitProjects/SecondBrain
git clone git@github.com:IT4WEBBV/LaravelClaudeMd.git ~/GitProjects/LaravelClaudeMd/LaravelClaudeMd
git clone git@github.com:IT4WEBBV/DevOps-Claude-Config.git ~/GitProjects/DevOps-Claude-Config/DevOps-Claude-Config
git clone git@github.com:jonneroelofs/SecondBrain.git ~/GitProjects/SecondBrain/SecondBrain

# 2. Symlink CLAUDE.md as the global CLAUDE.md
ln -sfn ~/GitProjects/LaravelClaudeMd/LaravelClaudeMd/CLAUDE.md ~/.claude/CLAUDE.md

# 3. Make ~/.claude/skills a REAL directory and link every skill from BOTH repos
mkdir -p ~/.claude/skills
for repo in LaravelClaudeMd DevOps-Claude-Config; do
  for skill in ~/GitProjects/$repo/$repo/skills/*/; do
    ln -sfn "$skill" ~/.claude/skills/"$(basename "$skill")"
  done
done

# 4. Make the hooks executable
chmod +x ~/GitProjects/LaravelClaudeMd/LaravelClaudeMd/hooks/git-freshness.sh \
         ~/GitProjects/LaravelClaudeMd/LaravelClaudeMd/hooks/vault-sync.sh
```

Then wire the hooks in `~/.claude/settings.json`. The scripts live in this repo and update with
every pull, so this wiring is the only per-machine step — and the one thing that does not reach
the other machine on its own:

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

Leave out `autoMemoryDirectory` and both `vault-sync.sh` entries until the vault cut-over has run
(see `CLAUDE.md` § Memory): before it, the vault has no `memory/` folder to point at.

`git-freshness.sh` has three modes:
- `session` — at startup: syncs both config repos (fast-forward only, never over local work) and
  links any skill that has no symlink yet (and any skill's `workflow/*.js` into
  `~/.claude/workflows/`), then checks the launch directory.
- `edit` — the repo owning the file being written, once per repo per session.
- `checkout` — drops cached verdicts after a branch switch.

`vault-sync.sh` has two: `session` (commit `memory/`, then fetch, rebase and push — at startup,
resume and clear) and `end` (commit and push when the session ends).

A machine that already has local memories migrates them once: start a session with
`CLAUDE_CODE_DISABLE_AUTO_MEMORY=1 claude` from `~` and have it follow the vault memory
`playbook_migrate_machine_memory.md`.

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

## Hook tests

Run after changing the matching hook:

```bash
bash hooks/tests/git-freshness-sync.test.sh
bash hooks/tests/vault-sync.test.sh
```

Both build throwaway repos under `$TMPDIR`. The first covers every branch of the base-branch sync,
including the sibling-worktree case that is easy to get silently wrong, plus the config-repo sync
and skill linking; the second covers every sync path of the memory vault, including two machines
writing at once.
