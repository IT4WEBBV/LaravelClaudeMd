# work-on — per-repo config

Per-repo settings for the global `work-on` skill. This repo is the Claude Code
config/skills repo (CLAUDE.md + personal skills), not a Docker Laravel app, so:
there is **no board**, no Docker stack, and worktrees are the harness-native
`.claude/worktrees/` kind (there is no `scripts/worktree.sh` here).

## Repo
- repo: IT4WEBBV/LaravelClaudeMd    # GitHub repo hosting issues/PRs (gh --repo)

## Board
# Omitted on purpose — board-less repo. work-on skips the status-move (step 3)
# and the Component wrap-up, and runs everything else normally.

## Worktree
# Harness-native worktrees under .claude/worktrees/ (preferred: the EnterWorktree
# tool, which made e.g. .claude/worktrees/visual-parity-worklist-spec). The command
# below is the plain-git equivalent for the work-on engine's step 6; paths are
# repo-relative so this config stays portable across machines.
- create: git worktree add .claude/worktrees/<branch> -b <branch> origin/main
- remove: git worktree remove .claude/worktrees/<branch>

## Branch convention
- issue: worktree-issue-<number>-<slug>   # issue pickup (issues are rare here; PRs dominate)
- pr: use head.ref                        # PR pickup uses the PR's own head branch

## Wrap-up
- handoff-pr-mode: true    # scenario A (spec+plan) hands off via the global handoff skill's pr-flow
