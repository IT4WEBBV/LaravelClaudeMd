# Claude Code worktrees: what the harness allows

A background job may not edit a shared checkout until it is isolated, and once it is isolated in an
`EnterWorktree` worktree its Bash commands are restricted. Both guards are part of Claude Code, not
of this repo. This page records how they behave, as measured, so a session does not rediscover it.

## Editing without a worktree

The isolation guard accepts an edit when the file is in **any registered git worktree** of its repo,
not only in one under `.claude/worktrees/`. A slot made by `scripts/worktree.sh create`
(`../<Project>-N`) is such a worktree, so `/pipeline` and `/work-on` slot runs need no setting
change. Probe with one throwaway write before asking for approval.

**Live-mounted package work is the exception.** A package mounted into a running slot with
`restart.sh -p` must be edited in place: the slot bind-mounts the package's primary checkout, so an
edit in a worktree never reaches the running app. Never use `EnterWorktree` for that work. Lift the
guard instead, in this order:

1. A `.claude/settings.json` with `{"worktree":{"bgIsolation":"none"}}` at the package root. The
   guard resolves it by walking up from the edited file, so it takes effect mid-session. Create it
   with a shell command (`printf`), because the Write and Edit tools are themselves guarded.
2. Start the job with the package directory as its working directory, so the package's own
   `.claude/settings.json` is the project settings.
3. `"worktree": {"bgIsolation": "none"}` in `~/.claude/settings.local.json`. This needs explicit
   approval (auto mode treats it as editing startup config) and lifts the guard for every job rooted
   in the home directory.

## Bash inside an EnterWorktree worktree

The Bash guard refuses any command it cannot prove stays inside the worktree. Measured refusals:

- **Two or more occurrences of `git`** in one command, including inside file and branch names:
  `git branch -m feature/git-freshness-x` counts twice, and so does any path through
  `~/GitProjects/`.
- **Heredocs whose content names another repo's path**, even when the target file is outside every
  repo. Write such files with the Write tool and pass them with `--body-file` and the like.
- **Loops, multi-repo sweeps, `$(...)` inside `gh`, and `sh -c` payloads.**

So validation against other repos cannot happen from inside the worktree. The sequence that works:

1. Write and commit inside the worktree, with the Write and Edit tools.
2. Push (one `git` mention).
3. `ExitWorktree` with `action: "keep"`.
4. Validate from outside, where the guard is inactive.
5. For fixes, `cd` to the main checkout and `EnterWorktree` with `path:` pointing at the worktree.

`EnterWorktree` anchors on the shell's current directory, not on the directory the session started
in. A `cd` from an earlier command persists, so `cd` to the target repo right before calling it and
check that the returned path names the repo you meant.

Docker Desktop cannot bind-mount the job directory (`~/.claude/jobs/*/tmp`), so a scratch directory
Docker needs goes under `~/GitProjects/zz-*` and is deleted afterwards.

## Never symlink `vendor/` into a worktree

Run a real `composer install` in the worktree. `vendor/bin/pest` derives its root path from the
autoloader's real path, so a symlinked `vendor` makes Pest bootstrap the **primary** checkout's
`Pest.php` and whatever it requires, while PHPUnit collects the worktree's test files. The tests then
run against the other checkout's code: a smoke run passes (the primary has the same tests) and new
functions fail as undefined. To check which `Pest.php` loaded, add a temporary `file_put_contents` to
the worktree's copy and see whether it fires.

In this repo the suites have their own config and test directory: run them as
`vendor/bin/pest -c skills/<skill>/checks/phpunit.xml --test-directory=skills/<skill>/checks/tests`.
