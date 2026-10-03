# The pipeline prints its allow-ruled commands after a `cd` — design

**Design size:** Architectural

**Date:** 2026-10-03
**Issue:** IT4WEBBV/LaravelClaudeMd#139
**Canonical home:** `skills/pipeline/checks/brief.php` (`pipeline_catch_up_line()`, the handoff command),
`skills/pipeline/checks/tests/BriefTest.php`, `skills/pipeline/checks/tests/DispatchCliTest.php`, pipeline
`references/engine.md` §Catching up with the base, pipeline `references/gates.md` (the command list), pipeline
`SKILL.md` §`autoflow` step 3, `README.md` *Permissions for unattended runs*, `CLAUDE.md` §Git Workflow.
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. The issue body is the approved scope and is
not re-litigated here. Every question the brainstorm would have asked is answered in *Assumptions*, so
`/critique plan` audits exactly those. Nothing was built; two throwaway probes are recorded beside the claims they
check.

## Problem

`README.md` asks for four user-level allow rules so that a background step's catch-up merge and its `handoff` are
not denied (a denial there is final):

```
Bash(git -C * merge --no-edit origin/*)
Bash(git -C * merge --abort)
Bash(git -C * commit --no-edit)
Bash(php */dispatch_cli.php handoff *)
```

Claude Code warns about each at every startup. Its message (Claude Code 2.1.288):

> … has a wildcard before the rest of the command, so it also matches any options inserted at that position and
> approves them without a prompt. Replace that * with the exact value you mean, or only use * after the subcommand

The warning is right: a command that matches an allow rule is decided there, before the auto-mode classifier sees
it, so `git -C /x -c core.hooksPath=/tmp/evil merge --no-edit origin/main` and
`php -r '…' /dispatch_cli.php handoff x` are auto-approved today. The README accepts the warning as expected.

The leading `*` cannot become an exact path: the worktree differs per run, and the handoff command's directory is
`brief.php`'s `__DIR__` — the resolved repo path on each machine, or a worktree of this repo when a run is launched
from one.

## Scope (from the issue)

Print every allow-ruled command bare after a `cd`, so the rules need no wildcard before the subcommand and no path:

| Brief line | Rule |
|---|---|
| `cd <worktree> && git merge --no-edit origin/<base>` | `Bash(git merge --no-edit origin/*)` |
| `cd <worktree> && git merge --abort` | `Bash(git merge --abort)` |
| `cd <worktree> && git commit --no-edit` | `Bash(git commit --no-edit)` |
| `cd <checks> && php dispatch_cli.php handoff <manifest>` | `Bash(php dispatch_cli.php handoff *)` |

`git add <file>` on a conflict takes the same form; it has no rule and passes on the classifier, as today.

## Approaches

- *(recommended, the issue's)* **`cd <dir> && <bare command>`.** A chained command is matched part by part
  (`README.md`, citing the Claude Code permission docs), so the rule sees only the bare command, and every `*` in
  the new rules stands after the subcommand, the form the warning itself recommends. The `cd` part is decided on
  its own: the classifier sees a plain directory change.
- **An absolute path through the symlink** (`php ~/.claude/skills/pipeline/checks/dispatch_cli.php handoff *`).
  Fixes only the handoff rule, not the three `git -C` ones, and a run launched from a worktree of this repo would
  stop running that worktree's checks. Rejected.
- **Keep the rules, accept the warning** (today). Leaves the option injection open. Rejected by the issue.

## Design

### `brief.php`

**The catch-up line.** `pipeline_catch_up_line()` builds its prefix as
`'cd ' . rtrim((string) $manifest['worktree'], '/') . ' && git'` instead of `'git -C ' . …`, and prints the same four
commands with it. The sentence around them is unchanged, so the merge line reads, for worktree `/tmp/wt`:

`` Before any other work run `cd /tmp/wt && git merge --no-edit origin/main`, as its own command in exactly that form. On a conflict, resolve each file keeping both sides' intent, `cd /tmp/wt && git add <file>`, and conclude with `cd /tmp/wt && git commit --no-edit`. Only where both sides cannot be kept: `cd /tmp/wt && git merge --abort` and return `halted`, quoting the conflicting hunks. … ``

Its docblock keeps *"The commands are literal: the allow rules in `README.md` match this form"*, which stays true.

**The handoff command.** The step's own command (`PIPELINE_STEP_COMMANDS`) is printed in two places, both through
`pipeline_cli()`: the `handoff:run` override (*"Run `…` as its own command: it pushes the branch, …"*) and the first
line of `## Return` (`pipeline_record_commands()`). A new function beside `pipeline_cli()` gives both the `cd` form:

```php
/** A step's own command (`PIPELINE_STEP_COMMANDS`) as it copies it: bare after a `cd`, the form its allow rule in `README.md` matches. */
function pipeline_step_cli(string $command, string $manifestPath): string
{
    return 'cd ' . __DIR__ . " && php dispatch_cli.php {$command} {$manifestPath}";
}
```

The `handoff:run` override and `pipeline_record_commands()`'s own-command entry call it in place of `pipeline_cli()`.
Every other `dispatch_cli.php` command a brief prints (`record`, `suite`, `brief`, `size`, `ui`) has no allow rule,
passes on the classifier, and keeps `pipeline_cli()`'s absolute form.

The `cd` changes nothing `handoff` acts on: the manifest path is absolute (`pipeline_cli()`'s callers pass the full
path, #122), `dispatch_cli.php` loads its files by `__DIR__`, and `dispatch_cli_handoff()` runs git through
`dispatch_cli_git($manifest)` and gh through `pipeline_gh_run($worktree, …)`, both in the worktree, never in the cwd.

### Tests

The tests that pin the old form change to the new one, nothing else:

- `BriefTest`: *prints the catch-up line …* (the full line with `/tmp/wt`), the integration-base variant's
  `toStartWith`, *has handoff run its command and not the skill, in both modes* (`$command` becomes
  `'cd ' . realpath(__DIR__ . '/..') . ' && php dispatch_cli.php handoff /tmp/m.json'`), and the every-step brief
  test's `handoff` expectation.
- `DispatchCliTest`: *prints the catch-up line first in the brief of a writing step behind its base …* (`$line`).
- One new expectation: the handoff brief's `## Return` lists the `cd` form as its first command, so both places that
  print it are pinned.
- A guard in `BriefTest`: no brief of either mode, for any step, contains `git -C <worktree> merge`,
  `git -C <worktree> commit` or `/dispatch_cli.php handoff`, so a later edit cannot bring the wildcard-needing form
  back in one place.

`LockStepTest` reads only the `§` names in the catch-up line, which do not change.

### Docs

- **`README.md` *Permissions for unattended runs*.** The JSON lists the four new rules. The paragraph after it says
  the brief prints each ruled command after a `cd <dir> &&`, that a chained command is matched part by part, and
  that every `*` stands after the subcommand, so Claude Code does not warn. The paragraph that calls the warning
  expected and the "if the rules turn out inert" fallback go. The `git add` paragraph keeps its point in the new
  form (`cd <worktree> && git add <file>` has no rule; the classifier allows it). The handoff paragraph names
  `cd <checks> && php dispatch_cli.php handoff <manifest>` and `Bash(php dispatch_cli.php handoff *)`, replacing
  *"The brief prints the command bare, with no `cd … &&` in front"*. A new short paragraph records the trade-off:
  the merge, abort and commit rules approve those commands in every repo and session, not only in a run's step;
  outside a run CLAUDE.md still says a stale branch is raised, but the classifier no longer guards a stray
  `git merge --no-edit origin/…`, judged far less harmful than an injected git option. And: swap the old rules for
  the new on each machine together with pulling this change; a machine that has only the old rules gets the
  `cd` form decided by the classifier, and a denial halts the step with the command named.
- **`CLAUDE.md` §Git Workflow**, the `/pipeline` exception quotes `cd <worktree> && git merge --no-edit origin/<base>` in place
  of the `git -C` form.
- **pipeline `references/engine.md` §Catching up with the base.** The command in *"The line is the step's first
  override …"* and the three in *Conflicts* take the `cd <worktree> && git …` form; the reason stays *"a permission
  rule matches a command as typed"*.
- **pipeline `references/gates.md`**, the command list: `cd "$CHECKS" && php dispatch_cli.php handoff <manifest>`.
- **pipeline `SKILL.md` §`autoflow` step 3:** *"the allow rules for the merge of the base and for `handoff` in their
  `cd <dir> &&` form"*.

The `git -C <worktree> diff …` lines (engine.md, gates.md, orchestrate `commands.md`, the autoflow script's `ui`
instruction) and the brief's *"Run every command from `cd <worktree>` or with `git -C <worktree>`"* have no allow
rule and stay as they are.

### Outside the plan

- **`~/.claude/settings.json` on both machines** swaps the four rules by hand, per machine, as the README says. The
  plan does not edit it.
- **The issue's first two *Done when* lines** (no startup warning; a catch-up merge with a conflict and a handoff run
  unattended without a denial) need the swapped rules and a real autoflow run after merge. The PR body lists them as
  the owner's post-merge check. The plan proves the brief text and a green suite.

## Assumptions

1. **Q: Is the issue's table the scope, or should every `dispatch_cli.php` and `git -C` command a brief prints move
   to the `cd` form?** A: Only the ruled commands and `git add` on a conflict. The others have no rule and pass on
   the classifier; changing them is churn with no warning to remove.
2. **Q: Does a rule whose `*` stands after the subcommand still draw the warning?** A: No. The warning text asks for
   exactly that (*"or only use * after the subcommand"*). Probed: the warning's wording in the installed binary:
   *"has a wildcard before the rest of the command … Replace that * with the exact value you mean, or only use *
   after the subcommand"* (`python3` reading `~/.local/share/claude/versions/2.1.288` around that string).
3. **Q: Is a `cd` outside the project directory denied in a background step?** A: Not in this run's step: `cd /tmp`
   and `cd ~/.claude/skills/pipeline/checks` both ran without a prompt. Probed: a background autoflow step may `cd`
   outside its launch directory: it may (`cd /tmp && pwd; cd ~/.claude/skills/pipeline/checks && pwd -P`). The
   issue's own *Done when* re-checks it on a real run in another project after merge.
4. **Q: Does running `handoff` from `<checks>` instead of the step's cwd change what it does?** A: No (by reading,
   see *The handoff command*).
5. **Q: A separate function, or a flag on `pipeline_cli()`?** A: A separate function, `pipeline_step_cli()`: two
   callers print a step's own command, every other caller prints the absolute form; a boolean flag would be the
   conditional CLAUDE.md asks to avoid.
6. **Q: Keep the old rules in the README beside the new ones for a transition?** A: No. They are what draws the
   warning and opens the injection; a machine not yet swapped fails safe (a classifier decision or a named
   denial).
7. **Q: Does `Bash(git commit --no-edit)` approved everywhere add a risk?** A: Small: outside a merge it commits the
   staged changes with the prepared message, or fails on an empty one; CLAUDE.md's never-on-main and explicit-staging
   rules still apply to the agent. Recorded with the merge trade-off in the README.
8. **Q: Paths with spaces?** A: Out of scope: the `git -C` form had the same limit, and worktree paths have none.
9. **Q: Does `Bash(php dispatch_cli.php handoff *)` approve more than the old rule?** A: No. It runs whichever
   `dispatch_cli.php` sits in the cwd, but `php */dispatch_cli.php handoff *` ran one at any path, and now the `cd`
   that picks the directory is decided by the classifier on its own.
10. **Sibling run #176 (issue #146)** also changes `brief.php`, `BriefTest.php`, `DispatchCliTest.php`, `engine.md`
   and pipeline `SKILL.md`, elsewhere in those files. The plan expects a merge of the base (engine.md §Catching up
   with the base) and no semantic conflict.
