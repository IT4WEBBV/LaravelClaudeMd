# LaravelClaudeMd

Claude Code configuration and personal skills for Laravel work at IT4WEB.

`CLAUDE.md` is symlinked to `~/.claude/CLAUDE.md` and is the source of truth for the instructions
themselves — this README only covers **installing and updating** what the repo ships.

Personal skills are pooled from **two** repos, so `~/.claude/skills/` is a real directory holding
one symlink per skill:

| Repo | Clone location |
|------|----------------|
| `IT4WEBBV/LaravelClaudeMd` | `~/GitProjects/LaravelClaudeMd/LaravelClaudeMd` |
| `IT4WEBBV/DevOps-Claude-Config` | `~/GitProjects/DevOps-Claude-Config/DevOps-Claude-Config` |

A single symlink at `~/.claude/skills` could only ever point at one of them — hence a real folder
with per-skill symlinks. Both repos are cloned one level deep (`~/GitProjects/<Repo>/<Repo>/`),
the layout `DevOps-Claude-Config`'s `memory-sync` skill expects.

## Structure

```
LaravelClaudeMd/
├── CLAUDE.md                        # Global instructions (symlinked to ~/.claude/CLAUDE.md)
├── composer.json                    # Pest, for the skills that ship mechanical checks
├── hooks/
│   ├── git-freshness.sh             # SessionStart / PostToolUse stale-checkout hook
│   └── tests/
├── docs/superpowers/{plans,specs}/  # Design docs and implementation plans
├── skills/                          # One folder per skill, symlinked into ~/.claude/skills/
└── README.md
```

## Linking the skills (idempotent)

Run this after cloning, and **again whenever either repo adds a skill** — `ln -sfn` replaces an
existing link in place, so re-running it is safe and changes nothing for skills already linked:

```bash
mkdir -p ~/.claude/skills
for repo in LaravelClaudeMd DevOps-Claude-Config; do
  for skill in ~/GitProjects/$repo/$repo/skills/*/; do
    ln -sfn "$skill" ~/.claude/skills/"$(basename "$skill")"
  done
done
```

Two details that bite if changed:

- **`-n` is not optional.** The target is a directory; plain `ln -sf` would follow an existing
  symlink and drop the new link *inside* the old skill folder instead of replacing it.
- **The loop only adds.** A skill that was renamed or removed upstream leaves a dangling symlink
  behind, which Claude Code then reports as a broken skill. Sweep those separately:

  ```bash
  find ~/.claude/skills -maxdepth 1 -type l ! -exec test -e {} \; -print -delete
  ```

A newly linked skill is picked up by the **next** Claude Code session, not the running one.

Existing skills need no relinking to stay current: each symlink points back into its repo, so a
`git pull` updates them in place. `/memory-sync` pulls both repos on demand.

## Setup (new machine)

```bash
# 1. Clone both config repos into nested wrapper dirs (~/GitProjects/<Repo>/<Repo>/)
mkdir -p ~/GitProjects/LaravelClaudeMd ~/GitProjects/DevOps-Claude-Config
git clone git@github.com:IT4WEBBV/LaravelClaudeMd.git ~/GitProjects/LaravelClaudeMd/LaravelClaudeMd
git clone git@github.com:IT4WEBBV/DevOps-Claude-Config.git ~/GitProjects/DevOps-Claude-Config/DevOps-Claude-Config

# 2. Symlink the global CLAUDE.md
ln -sfn ~/GitProjects/LaravelClaudeMd/LaravelClaudeMd/CLAUDE.md ~/.claude/CLAUDE.md

# 3. Link every skill from both repos (the loop above)

# 4. Make the stale-checkout hook executable
chmod +x ~/GitProjects/LaravelClaudeMd/LaravelClaudeMd/hooks/git-freshness.sh
```

Wiring the hook into `~/.claude/settings.json` and the rest of the machine setup live in
[`CLAUDE.md` § Skills (multi-machine setup)](CLAUDE.md#skills-multi-machine-setup).

Do **not** symlink `DevOps-Claude-Config/settings.json` or its `CLAUDE.md` over yours — that repo
is a colleague's personal config. Skill names must stay unique across the two repos: if both ship
the same folder name, the second `ln` silently wins.

## Skills in this repo

| Skill | What it does |
|-------|--------------|
| `browser-verification` | Annotated screenshot proof before claiming visual work is done. |
| `counselors` | Parallel second opinions from other AI coding agents. |
| `critique` | Reviews a design, plan, or change — `plan`, `pr`, `alternatives`, `missing` modes. |
| `experiment` | Proves whether a library/framework feature actually behaves as assumed. |
| `improve-codebase-architecture` | Architectural audit — refactoring opportunities, code health. |
| `pipeline` | Walks a feature through the whole chain (design → review-plan → handoff → implement → verify-ui → review-pr), gates un-skippable by construction. |
| `slots` | Parallel Docker stacks of one project, each from its own worktree (`<Project>-N`). |
| `visual-parity` | Pixel-diff harness for rebuilds that must match a reference implementation. |

`DevOps-Claude-Config` adds `handoff`, `memory-sync`, `release-changelog`, `retenium-prod`,
`review-pr` and `work-on`; see that repo's README.

## Tests

The deterministic guardrails of `critique` and `pipeline` are PHP, run with Pest:

```bash
composer install
composer test                                   # critique's stage-0 checks
./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests
bash hooks/tests/git-freshness-sync.test.sh     # the git-freshness hook
```
