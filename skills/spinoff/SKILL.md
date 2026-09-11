---
name: spinoff
description: Use when a side task from the current session should run in its own independent Claude session that keeps going after this one ends — "spin this off", "split X off", "do X in a separate/background session", "start a session in repo Y for Z", "/spinoff" — or when about to hand work that must outlive this session to a subagent or background shell.
---

# Spinoff

## Overview

Hands a side task to a new, independent `claude --bg` session, so this session can carry on or close. The new session starts with zero context: the brief you write is everything it knows, and the user talks with it later through `claude attach <id>`.

**Not this skill:** work whose result this session needs (Agent tool subagent; it dies with this session), the same work continuing after `/clear` (`handoff`), or a copy of this whole conversation (built-in `/fork`, which only the user can type).

## Steps

1. **Pin the task.** If more than one item from this session fits the request's wording (two things that could be "the checkout issue"), ask with AskUserQuestion, one option per candidate. Also in a background job. Then work out the target repo.
2. **Pick the launch directory:** the target repo's primary checkout. For the repo you are in, that is the first path of `git worktree list`; for another repo, its clone (usually `~/GitProjects/<Repo>/<Repo>`). The new session isolates itself in its own worktree.
3. **Write the brief** from the template below, every slot filled.
4. **Leave your worktree.** If this session called EnterWorktree, call `ExitWorktree(action: "keep")` now. The worktree guard refuses `claude '<brief>'` whenever the brief mentions git, and a real brief always does. `keep` deletes nothing.
5. **Launch**, from the launch directory, with the brief as one single-quoted argument:
   ```bash
   cd <launch dir> && claude --bg -n "<repo>: <task in 3-6 words>" '<brief>'
   ```
   The brief contains no single quote: write "do not", "it is". Everything inside single quotes stays literal, so a `$PWD` in a repro command reaches the new session unexpanded.
6. **Verify it started.** `claude --bg` prints "backgrounded · <id>" even when the session dies at startup, so check:
   ```bash
   python3 ~/.claude/skills/spinoff/check-launch.py <id>
   ```
   | Exit | Meaning | Next |
   |---|---|---|
   | 0 | working, tool calls seen | report |
   | 1 | failed or not found | read the output, fix, launch again |
   | 2 | blocked on the user (permission prompt or question) | report, and tell the user to `claude attach <id>` |
   | 3 | no tool calls after 60s | run it again with `--wait 120` |
7. **Go back** with `EnterWorktree(path: "<your worktree>")` if you left one in step 4.
8. **Report** to the user: id and name, launch directory, the state from step 6, `claude attach <id>` to open it, and the brief verbatim in a fenced block.

## Brief template

Fill every slot; write "none known" rather than dropping one. The last line is fixed, verbatim.

```
In <owner/repo>, <the task in one sentence>.

Why: <why it matters>

Context from the previous session:
- <finding, with the numbers, file paths and error text>
- Reproduce with: <exact command>

Related work, as seen at <HH:MM>. Re-check the current state of each before relying on it:
- <PR #N / branch / checkout>: <state as last seen>. <Do not touch it | how to stay clear of it>

Do:
1. <step>
2. <step>

Verify: <what proves it is done: tests, CI run, linter output, screenshot>

Deliver: <a PR against main following the repo CLAUDE.md conventions | a report in this session>

I will talk with you in this session; ask me before anything with real consequences.
```

## Common mistakes

| Mistake | Why it fails |
|---|---|
| Agent tool, `run_in_background`, `claude -p … &` | Dies with this session. Only `claude --bg` survives it. |
| Launching from this session's worktree or `$CLAUDE_JOB_DIR` | Both can be deleted together with this session. |
| Brief in a heredoc or `"$(cat file)"` | The worktree guard refuses both ("too complex to verify"); double quotes also expand `$`. |
| Guard refusal handed to the user as a command to paste | Step 4 fixes it: exit the worktree, launch, re-enter. |
| "PR #N is open" stated as fact | It may be merged by the time the brief is read. Say when you saw it, and have the new session re-check. |
| `claude agents \| grep <id>` | Without a TTY, `claude agents` prints only an error, so grep finds nothing. The checker reads `--json --all`. |
| Scraping `claude logs <id>` | Raw cursor-addressed TUI without newlines. The checker reads the transcript instead. |
