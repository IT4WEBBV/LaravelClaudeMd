# The pipeline prints its allow-ruled commands after a `cd` — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every command a brief prints that has a user-level allow rule (the catch-up merge, its `add`, `commit`
and `abort`, and `handoff`) is printed bare after a `cd <dir> &&`, so the four rules in `README.md` need no `*`
before the subcommand and Claude Code stops warning about them.

**Architecture:** `pipeline_catch_up_line()` swaps its `git -C <worktree>` prefix for `cd <worktree> && git`. A new
`pipeline_step_cli()` beside `pipeline_cli()` prints a step's own command (`PIPELINE_STEP_COMMANDS`, today only
`handoff`) as `cd <checks> && php dispatch_cli.php <command> <manifest>`; the `handoff:run` override and
`pipeline_record_commands()` call it. Every other `dispatch_cli.php` command keeps `pipeline_cli()`'s absolute form.
The README, CLAUDE.md, engine.md, gates.md and pipeline SKILL.md follow.

**Tech Stack:** PHP 8.4 (no framework), Pest 4 in `skills/pipeline/checks/tests`.

**Spec:** `docs/superpowers/specs/2026-10-03-pipeline-commands-after-cd-design.md`. Read it with this plan: the plan
argues from it, and its `## Assumptions` 11–12 are the answers this plan assumed.

## Global Constraints

- Run every command from the worktree root
  `/Users/jroelofs/GitProjects/LaravelClaudeMd/LaravelClaudeMd/.claude/worktrees/worktree-issue-139-pipeline-print-commands-after-cd-so-the-permission`.
- Tests: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
  (append `--filter '<pattern>'` for a subset). This repo is not a Docker project: Pest runs on the host. The
  worktree has no `vendor/` yet: run `composer install` once first. The suite needs `node` on `PATH`.
- Test-first: change the test, see it fail, then the code. `php -l` every PHP file you change.
- The four rules, verbatim:
  `Bash(git merge --no-edit origin/*)`, `Bash(git merge --abort)`, `Bash(git commit --no-edit)`,
  `Bash(php dispatch_cli.php handoff *)`.
- The printed forms, verbatim: `cd <worktree> && git merge --no-edit <base>`, `cd <worktree> && git add <file>`,
  `cd <worktree> && git commit --no-edit`, `cd <worktree> && git merge --abort`,
  `cd <checks> && php dispatch_cli.php handoff <manifest>` (`<checks>` is `brief.php`'s `__DIR__`, `<manifest>` the
  full path).
- Unchanged: `pipeline_cli()` and every other command it prints (`record`, `suite`, `brief`, `size`, `ui`); the
  brief's *"Run every command from `cd <worktree>` or with `git -C <worktree>`"* line; every `git -C <worktree> diff …`
  line (engine.md, gates.md, orchestrate `commands.md`, `pipeline-autoflow.js`); the `§` names in the catch-up line
  (`LockStepTest` reads them).
- Do not edit `~/.claude/settings.json`: swapping the rules there is the owner's per-machine step.
- Sibling run #176 (issue #146) edits `brief.php`, `BriefTest.php`, `DispatchCliTest.php`, `engine.md` and pipeline
  `SKILL.md` elsewhere: touch only the lines this plan names, and a merge of the base follows engine.md §Catching up
  with the base.
- No changelog entry: the repo has no `.changelog/` and no `CHANGELOG.md`.
- Commits: stage explicit paths, no `Co-Authored-By`, no AI attribution; every message ends on `(#139)`.

## Review Focus

1. **A worktree path with a trailing slash** (`/tmp/wt/`) — prints `cd /tmp/wt && git …`, never `cd /tmp/wt/ && …`
   or a doubled slash: the `rtrim` stays. Pinned by *words the design-only case and a single commit* in Task 1, whose
   manifest has `/tmp/wt/`.
2. **A run on an integration base** (`origin/feature/integration`) — the merge prints
   `git merge --no-edit origin/feature/integration`, which `Bash(git merge --no-edit origin/*)` matches because the
   `*` stands after the subcommand. Pinned by the same test's `toStartWith`.
3. **A later edit that prints one ruled command in the old form again** (a new override, a copied line) — no brief
   of either mode, for any step, built with the catch-up line present, contains `git -C /tmp/wt merge`,
   `git -C /tmp/wt commit`, `git -C /tmp/wt add` or `/dispatch_cli.php handoff`. Pinned by the guard test in Task 1,
   which also asserts the new forms are present so it cannot pass on briefs that print neither.
4. **`handoff` run from the checks directory instead of the worktree** — it must still push and open the PR for the
   manifest's worktree. By reading (spec *The handoff command*): `dispatch_cli.php` loads by `__DIR__`, git and gh
   run in `$manifest['worktree']`. Already exercised: `HandoffCliTest` starts `dispatch_cli.php` through
   `dispatch_cli()` with `proc_open(…, null, …)`, the test runner's cwd, never the fixture's worktree. No new test.
5. **A run launched from a worktree of this repo** — the handoff command's `cd` names that worktree's checks
   directory (`__DIR__`), not the symlinked `~/.claude/skills/…` one. Pinned by every handoff expectation in Task 1,
   built from `realpath(__DIR__ . '/..')` of the test file.

## File Structure

- Modify `skills/pipeline/checks/brief.php`: the `handoff:run` override (line 73), `pipeline_catch_up_line()`
  (lines 443–458), a new `pipeline_step_cli()` after `pipeline_cli()` (lines 495–499), and
  `pipeline_record_commands()`'s return (line 534).
- Modify `skills/pipeline/checks/tests/BriefTest.php`: lines 473, 496, 501–509, 537–539, 551–557, 608–609, and one
  new test.
- Modify `skills/pipeline/checks/tests/DispatchCliTest.php`: line 1413.
- Modify `README.md` *Permissions for unattended runs* (lines 89–129), `CLAUDE.md` §Git Workflow (line 276),
  `skills/pipeline/references/engine.md` §Catching up with the base (lines 1442–1456),
  `skills/pipeline/references/gates.md` (line 144), `skills/pipeline/SKILL.md` §`autoflow` step 3 (lines 127–129).

Line numbers are as of `bcfc76c`; after a merge of the base, find each by its quoted text.

---

### Task 1: The brief prints the ruled commands after a `cd`

**Files:**
- Modify: `skills/pipeline/checks/brief.php:73`, `:443-458`, `:495-499`, `:534`
- Test: `skills/pipeline/checks/tests/BriefTest.php` (lines 473, 496, 501–509, 537–539, 551–557, 608–609; one new
  test after *has handoff run its command and not the skill, in both modes*),
  `skills/pipeline/checks/tests/DispatchCliTest.php:1413`

**Interfaces:**
- Consumes: `pipeline_brief(array $manifest, string $leg, string $manifestPath, ?string $step = null, ?callable $git = null): string`,
  `pipeline_brief_return(string $leg, string $step, string $mode, string $manifestPath): string`,
  `pipeline_catch_up_line(array $manifest, array $state): string`, `PIPELINE_STEP_COMMANDS`
  (`['handoff:run' => 'handoff']`); test helpers in `BriefTest.php`: `brief_manifest(string $leg, array $extra = []): array`
  (worktree `/tmp/wt`), `brief_git_behind(array $files = ['a.php', 'b.php'], string $behind = '27'): Closure`,
  `brief_steps(): array` (every `[mode, leg, step]`).
- Produces: `pipeline_step_cli(string $command, string $manifestPath): string`, returning
  `'cd ' . __DIR__ . " && php dispatch_cli.php {$command} {$manifestPath}"`. Task 2's docs quote the printed forms.

- [ ] **Step 1: Install the dependencies**

Run: `composer install`
Expected: `vendor/bin/pest` exists.

- [ ] **Step 2: Change the catch-up tests to the `cd` form**

In `BriefTest.php`, *puts the catch-up line first on every step that writes to the branch, …* (line 473), the
expected line becomes:

```php
    expect($brief)->toContain("## Overrides\n\n- Catch up with the base first (engine.md §Catching up with the base): `origin/main` is 27 commits ahead and changed files this branch changes too (`a.php`, `b.php`). Before any other work run `cd /tmp/wt && git merge --no-edit origin/main`, as its own command in exactly that form. On a conflict, resolve each file keeping both sides' intent, `cd /tmp/wt && git add <file>`, and conclude with `cd /tmp/wt && git commit --no-edit`. Only where both sides cannot be kept: `cd /tmp/wt && git merge --abort` and return `halted`, quoting the conflicting hunks. Never rebase, never force-push. A denied command is a halt naming it; do not reshape it. Record the merge as that section says.\n- ");
```

In *words the design-only case and a single commit* (line 496):

```php
        ->toStartWith('Catch up with the base first (engine.md §Catching up with the base): `origin/feature/integration` is 1 commit ahead and this branch holds only its design. Before any other work run `cd /tmp/wt && git merge --no-edit origin/feature/integration`, as its own command in exactly that form.');
```

In `DispatchCliTest.php`, *prints the catch-up line first in the brief of a writing step behind its base, …*
(line 1413):

```php
    $line = fn (string $dir) => "## Overrides\n\n- Catch up with the base first (engine.md §Catching up with the base): `origin/main` is 1 commit ahead and changed files this branch changes too (`shared.php`). Before any other work run `cd {$dir} && git merge --no-edit origin/main`, as its own command in exactly that form.";
```

- [ ] **Step 3: Run them to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter 'catch-up line|design-only case'`
Expected: FAIL — the briefs still print `git -C /tmp/wt merge --no-edit …`.

- [ ] **Step 4: Print the catch-up commands after a `cd`**

In `brief.php`, `pipeline_catch_up_line()`, replace

```php
    $git = 'git -C ' . rtrim((string) $manifest['worktree'], '/');
```

with

```php
    $git = 'cd ' . rtrim((string) $manifest['worktree'], '/') . ' && git';
```

The rest of the function and its docblock stay as they are.

- [ ] **Step 5: Run them to see them pass**

Run: the Step 3 command.
Expected: PASS.

- [ ] **Step 6: Change the handoff tests to the `cd` form**

In *has handoff run its command and not the skill, in both modes* (lines 501–509), replace the `$command` line and
add the `## Return` expectation, so the test reads:

```php
it('has handoff run its command and not the skill, in both modes', function (string $mode) {
    $command = 'cd ' . realpath(__DIR__ . '/..') . ' && php dispatch_cli.php handoff /tmp/m.json';

    expect(pipeline_brief(brief_manifest('handoff', ['mode' => $mode]), 'handoff', '/tmp/m.json', 'run'))
        ->toContain("- Run `{$command}` as its own command: it pushes the branch, opens the draft PR or adopts the one the branch has, and records this step. It is the whole step (engine.md §Stations).")
        ->toContain("the dispatcher's snapshot).\n\n- `{$command}` (records `continued`, or `halted` with its reason)\n")
        ->toContain('- The leg\'s name is not a skill to invoke: do not invoke the `handoff` skill (`/handoff`), which asks the owner a question and posts a prompt comment.')
        ->toContain('- Repair nothing it reports: no force-push, no `gh pr create` or `gh pr edit` by hand. A halt it recorded, a refusal, or a denied command is a halt with that reason.')
        ->not->toContain('handoff pr')
        ->not->toContain('--pr <number>');
})->with(['autoflow', 'interactive']);
```

In *ends every brief of both modes on the literal record command, …*, the own-command branch (lines 537–539)
becomes:

```php
            ->toContain($own === null
                ? "- `{$command} {$leg} {$step} --status continued"
                : '- `cd ' . realpath(__DIR__ . '/..') . " && php dispatch_cli.php {$own} {$path}` (records `continued`, or `halted` with its reason)")
```

In *prints the return of a handoff step as its command, …* (lines 551–557), add a `$step` variable after `$cli` and
use it for the first command line only; the `record` line keeps `$cli`:

```php
    $cli = 'php ' . realpath(__DIR__ . '/..') . '/dispatch_cli.php';
    $step = 'cd ' . realpath(__DIR__ . '/..') . ' && php dispatch_cli.php';
```

```php
        . "- `{$step} handoff /tmp/m.json` (records `continued`, or `halted` with its reason)\n"
        . "- `{$cli} record /tmp/m.json handoff run --status plan-insufficient --reason \"<what the plan lacks>\"`\n"
```

In *says what each step passes to record, …* (lines 608–609):

```php
    expect($brief('autoflow', 'handoff', 'run'))
        ->toContain('- Run `cd ' . realpath(__DIR__ . '/..') . " && php dispatch_cli.php handoff {$path}` as its own command:");
```

- [ ] **Step 7: Add the guard test**

Directly after *has handoff run its command and not the skill, in both modes*:

```php
it('prints no ruled command in the form whose allow rule needs a wildcard before the subcommand', function () {
    $briefs = array_map(
        fn (array $step) => pipeline_brief(brief_manifest($step[1], ['mode' => $step[0]]), $step[1], '/tmp/m.json', $step[2], brief_git_behind()),
        brief_steps(),
    );

    foreach ($briefs as $brief) {
        expect($brief)
            ->not->toContain('git -C /tmp/wt merge')
            ->not->toContain('git -C /tmp/wt add')
            ->not->toContain('git -C /tmp/wt commit')
            ->not->toContain('/dispatch_cli.php handoff');
    }
    expect(implode("\n", $briefs))
        ->toContain('`cd /tmp/wt && git merge --no-edit origin/main`')
        ->toContain('`cd ' . realpath(__DIR__ . '/..') . ' && php dispatch_cli.php handoff /tmp/m.json`');
});
```

- [ ] **Step 8: Run the handoff tests to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter 'handoff|literal record command|passes to record|wildcard before the subcommand'`
Expected: FAIL — the briefs still print `php <checks>/dispatch_cli.php handoff …`, and the guard finds
`/dispatch_cli.php handoff`.

- [ ] **Step 9: Add `pipeline_step_cli()` and use it for a step's own command**

In `brief.php`, directly after `pipeline_cli()`:

```php
/** A step's own command (`PIPELINE_STEP_COMMANDS`) as it copies it: bare after a `cd`, the form its allow rule in `README.md` matches. */
function pipeline_step_cli(string $command, string $manifestPath): string
{
    return 'cd ' . __DIR__ . " && php dispatch_cli.php {$command} {$manifestPath}";
}
```

In the `handoff:run` override (line 73), `pipeline_cli('handoff', $manifestPath)` becomes
`pipeline_step_cli('handoff', $manifestPath)`:

```php
            'Run `' . pipeline_step_cli('handoff', $manifestPath) . '` as its own command: it pushes the branch, opens the draft PR or adopts the one the branch has, and records this step. It is the whole step (engine.md §Stations).',
```

In `pipeline_record_commands()`, the return (line 534) becomes:

```php
    return [...($own === null ? [] : [pipeline_step_cli($own, $manifestPath)]), ...$lines];
```

Run: `php -l skills/pipeline/checks/brief.php`
Expected: `No syntax errors detected`.

- [ ] **Step 10: Run them to see them pass, then the whole suite**

Run: the Step 8 command.
Expected: PASS.

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, no failures (`LockStepTest` included: the `§` names did not change).

- [ ] **Step 11: Commit**

```bash
git add skills/pipeline/checks/brief.php skills/pipeline/checks/tests/BriefTest.php skills/pipeline/checks/tests/DispatchCliTest.php
git commit -m "Pipeline: print the allow-ruled commands bare after a cd (#139)"
```

---

### Task 2: The docs name the new form and the new rules

**Files:**
- Modify: `README.md:89-129`, `CLAUDE.md:276`, `skills/pipeline/references/engine.md:1442-1456`,
  `skills/pipeline/references/gates.md:144`, `skills/pipeline/SKILL.md:127-129`

**Interfaces:**
- Consumes: the printed forms Task 1 produces (Global Constraints).
- Produces: nothing code reads.

- [ ] **Step 1: `README.md` *Permissions for unattended runs***

Replace everything from the JSON block through the handoff paragraph (the paragraph ending *"runs the command
again."*) with the text below; the opening paragraph above the JSON and the `node` paragraph after it stay.

````markdown
```json
"permissions": { "allow": [
  "Bash(git merge --no-edit origin/*)",
  "Bash(git merge --abort)",
  "Bash(git commit --no-edit)",
  "Bash(php dispatch_cli.php handoff *)"
] }
```

The form matters: a rule matches the command as typed, and a chained command is matched part by part. The
brief prints each of these commands bare after a `cd`, as its own command:
`cd <worktree> && git merge --no-edit origin/<base>` (and `… && git merge --abort`, `… && git commit --no-edit`),
and `cd <checks> && php dispatch_cli.php handoff <manifest>`. The `cd` part is decided on its own, by the
auto-mode classifier; the rule sees only the bare command. Every `*` in these rules stands after the
subcommand, the form Claude Code recommends, so it does not warn about them at startup. A `*` in a Bash rule
matches any text, spaces included, and a command that matches an allow rule is decided there, before the
classifier sees it (Claude Code docs, *permissions* §Wildcard patterns and *permission-modes* §How the
classifier evaluates actions): a `*` before the subcommand would also approve any option inserted at that
position.

`cd <worktree> && git add <file>` has no rule here: those adds pass because the classifier allows them, as it
does in every step that stages a file.

The `handoff` step pushes the branch and calls gh from inside one command,
`cd <checks> && php dispatch_cli.php handoff <manifest>`, as `kickoff` creates the worktree and edits the board
from inside one `php` call. The push is a run's first outward write, so its rule is listed above with the merge
rules: `Bash(php dispatch_cli.php handoff *)`. `<checks>` is the pipeline skill's `checks` directory the run was
launched from, and `<manifest>` is a full path, so the `cd` changes nothing `handoff` acts on. Without the rule a
denial halts the step with the command named; nothing is pushed by then, and a resume after the rule is added
runs the command again.

The trade-off: these rules approve the bare commands in every repo and session, not only in a run's step. Outside
a run CLAUDE.md still says a stale branch is raised and waited on, but the classifier no longer guards a stray
`git merge --no-edit origin/…`, and `git commit --no-edit` commits what is staged; both are far less harmful than
an injected git option. Swap the old `git -C *` and `php */dispatch_cli.php` rules for these on each machine
together with pulling this change: on a machine that has only the old rules the classifier decides the `cd` form,
and a denial halts the step with the command named.

The `cd` part needs auto mode: no rule above covers it, and the classifier decides it, where the old
`git -C *` and `php */dispatch_cli.php` rules approved the directory deterministically. A machine that runs
unattended on allow rules alone adds a fifth rule, `Bash(cd *)`: its `*` stands after the subcommand, so it draws
no warning, and a `cd` approves nothing by itself. That a chained command is matched part by part is what the
Claude Code docs say; the first real run after the swap is what checks it.
````

- [ ] **Step 2: `CLAUDE.md` §Git Workflow**

In the `/pipeline` exception (line 276), replace

```markdown
  when its brief says so, with `git -C <worktree> merge --no-edit origin/<base>`, and resolves the
```

with

```markdown
  when its brief says so, with `cd <worktree> && git merge --no-edit origin/<base>`, and resolves the
```

and re-wrap the paragraph to the file's line width only if the line now runs past it.

- [ ] **Step 3: engine.md §Catching up with the base**

Replace (lines 1442–1444)

```markdown
**The line is the step's first override** and carries the command:
`git -C <worktree> merge --no-edit origin/<base>`, run as its own command in exactly that form, because a
permission rule matches a command as typed (`README.md`, *Permissions for unattended runs*). A denied
```

with

```markdown
**The line is the step's first override** and carries the command:
`cd <worktree> && git merge --no-edit origin/<base>`, run as its own command in exactly that form, because a
permission rule matches a command as typed (`README.md`, *Permissions for unattended runs*). A denied
```

and in *Conflicts* (lines 1452–1455) replace `` `git -C <worktree> add <file>` `` with
`` `cd <worktree> && git add <file>` ``, `` `git -C <worktree> commit --no-edit` `` with
`` `cd <worktree> && git commit --no-edit` ``, and `` `git -C <worktree> merge --abort` `` with
`` `cd <worktree> && git merge --abort` ``. Re-wrap the bullet so no line runs past the file's width.

- [ ] **Step 4: gates.md, the command list**

Replace line 144

```
php "$CHECKS/dispatch_cli.php" handoff <manifest>
```

with

```
cd "$CHECKS" && php dispatch_cli.php handoff <manifest>
```

The comment lines under it stay.

- [ ] **Step 5: pipeline `SKILL.md` §`autoflow` step 3**

Replace (lines 127–129)

```markdown
   unattended runs need auto permission mode or allow rules for `git push`, `gh` and `docker`, and the
   allow rules for the merge of the base in its `git -C <worktree>` form (`README.md`, *Permissions for
   unattended runs*).
```

with

```markdown
   unattended runs need auto permission mode or allow rules for `git push`, `gh` and `docker`, and the
   allow rules for the merge of the base and for `handoff` in their `cd <dir> &&` form (`README.md`,
   *Permissions for unattended runs*). The `cd` part is decided by the auto-mode classifier; a machine
   without auto mode adds `Bash(cd *)` as well.
```

- [ ] **Step 6: Check that no doc still names a ruled command in the old form**

Run: `grep -rnE 'git -C [^ ]+ (merge|commit --no-edit|add <file>)|\*/dispatch_cli\.php handoff|"\$CHECKS/dispatch_cli\.php" handoff|<checks>/dispatch_cli\.php handoff' README.md CLAUDE.md skills/pipeline/SKILL.md skills/pipeline/references`
Expected: no output.

Run: `grep -rn 'git -C <worktree> diff' skills/pipeline/references/engine.md skills/pipeline/references/gates.md | head -3`
Expected: the unruled `diff` lines are still there (at least one line).

- [ ] **Step 7: Commit**

```bash
git add README.md CLAUDE.md skills/pipeline/references/engine.md skills/pipeline/references/gates.md skills/pipeline/SKILL.md
git commit -m "Docs: the allow rules and briefs use the cd form (#139)"
```

---

## Outside the plan

- **`~/.claude/settings.json` on both machines**: the owner swaps the four rules by hand, per machine, as the README
  says. No step edits it.
- **The issue's first two *Done when* lines** (no startup warning; a catch-up merge with a conflict and a handoff run
  unattended without a denial) need the swapped rules and a real autoflow run after merge. The PR body lists them as
  the owner's post-merge check; this plan proves the brief text and a green suite.
