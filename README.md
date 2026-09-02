# LaravelClaudeMd

Claude Code instructions and personal skills for Laravel work at IT4WEB. `CLAUDE.md` lives here, is
symlinked to `~/.claude/CLAUDE.md`, and documents everything else — full machine bootstrap, hook
wiring, conventions. This README covers one thing: getting the skills linked.

## Linking the skills

`~/.claude/skills/` is a **real directory** holding one symlink per skill, pooled from this repo and
from `DevOps-Claude-Config` (a single symlink could only ever point at one of them). Run this after
cloning, and again whenever either repo adds a skill — `ln -sfn` replaces a link in place, so
re-running it changes nothing for skills already linked:

```bash
mkdir -p ~/.claude/skills
for repo in LaravelClaudeMd DevOps-Claude-Config; do
  for skill in ~/GitProjects/$repo/$repo/skills/*/; do
    ln -sfn "$skill" ~/.claude/skills/"$(basename "$skill")"
  done
done
```

`-n` is required: without it, `ln -sf` follows the existing symlink and drops the new link *inside*
the old skill folder instead of replacing it. And the loop only ever adds, so sweep the dangling
link a renamed or removed skill leaves behind:

```bash
find ~/.claude/skills -maxdepth 1 -type l ! -exec test -e {} \; -print -delete
```

A newly linked skill is picked up by the **next** Claude Code session. Existing ones need no
relinking — each symlink points back into its repo, so `git pull` (or `/memory-sync`) updates them
in place.

Everything else: [`CLAUDE.md` § Skills (multi-machine setup)](CLAUDE.md#skills-multi-machine-setup).
