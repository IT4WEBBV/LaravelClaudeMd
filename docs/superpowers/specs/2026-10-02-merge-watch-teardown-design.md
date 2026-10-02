# A session that opened a PR notices the merge and cleans up by itself — design

**Design size:** Architectural (a new shared script that three callers depend on, a change to `owners.py`'s
contract, and a new global rule in `CLAUDE.md`)

**Date:** 2026-10-02
**Issue:** IT4WEBBV/LaravelClaudeMd#168
**Canonical home:** `skills/orchestrate/teardown.py` (new), `skills/orchestrate/tests/teardown_test.sh` (new),
`skills/orchestrate/owners.py` and `tests/owners_test.sh`, orchestrate `SKILL.md` step 6 and
`references/commands.md` §Watch and §Teardown, pipeline `references/engine.md` §After the merge, `slots/SKILL.md`
(*Exception — your own slot*), `CLAUDE.md` §Git Workflow.
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. The issue body is the approved scope, minus
the bullet the owner dropped (below). Every question the brainstorm would have asked is answered in *Assumptions*, so
`/critique plan` audits exactly those. Nothing was built; the two probes are named where they are relied on.

**Settled (owner, 2026-10-02):** orchestrate's merge watch keeps polling every 300 s (commands §Watch). The issue's
"Orchestrate polls every 60 s" bullet is dropped, and so is the verify line that depends on it ("an orchestrator's
ready PR … torn down within about a minute"): an orchestrator's ready PR is torn down within about five minutes of
the merge.

## Problem

After merging a PR on GitHub the owner has to find the session that opened it and type "merged" (128 times in 30
days, 84 of them that one word), often followed by "cleanup". Orchestrate and a standalone `/pipeline` already watch
their PRs and tear down after the merge, but the checks and the removal are prose in orchestrate commands §Teardown
that each session re-types (pipeline engine.md §After the merge points there "as written"). A plain session that
opens a PR — in viewiemedia, Deploy, Asimo, the packages, often started from `~` — has no watch at all, and its
worktree, slot or feature branch stays until the owner asks.

## Approaches

1. **One tested script that decides and removes; each caller only watches and calls it (chosen).**
   `skills/orchestrate/teardown.py <checkout> <pr>` reads the PR's state, marks the proof page, runs every check,
   prints them together, and removes what the checkout's kind calls for, or nothing. Orchestrate §Teardown, pipeline
   §After the merge and the `CLAUDE.md` rule all call it, so the three cannot drift (the issue's own requirement).
   Python, beside `owners.py` and `needs_input.py`, which it calls; tested by a bash fixture test like theirs.
2. **A PHP subcommand in `skills/pipeline/checks/` (`dispatch_cli.php teardown`).** Pest is the better test
   harness, but the `CLAUDE.md` rule would then route every plain session through the pipeline's dispatcher, and
   the ownership check is Python already. Rejected: the script belongs to orchestrate's teardown, which pipeline
   already defers to.
3. **Keep the prose, add the `CLAUDE.md` rule pointing at commands §Teardown.** No code, but the issue asks for one
   tested script, and a plain session would have to read a skill's reference to clean up. Rejected.

The watch loop itself stays an inline shell `until` loop in each caller: it is one line, it differs per caller only
in its interval, and a script would add nothing to test.

## Design

### `teardown.py`

```
teardown.py <checkout> <pr> [--repo <owner/name>] [--proof <page.html>] [--projects-dir DIR]
```

- `<checkout>`: the working tree the PR's branch is checked out in — a linked worktree, a slot, or the primary
  checkout. Resolved with `os.path.realpath`; it must be the top level of a git working tree
  (`git -C <checkout> rev-parse --show-toplevel` equals it), else usage on stderr, exit 2.
- `<pr>`: a PR number or its URL, passed to `gh pr view` as given. `--repo` adds `-R <owner/name>`; without it `gh`
  resolves the repo from the checkout (every `gh` call runs with the checkout as its cwd).
- `--proof`: the proof page to mark. Without it, the page is the run manifest's `artifacts.proof` when
  `<checkout>/.claude/pipeline/<headRefName, "/" → "-">.json` exists (the path `manifest_path()` builds,
  `skills/pipeline/checks/manifest.php:23`); no manifest or no `artifacts.proof`: no page.
- `--projects-dir`: passed on to `owners.py` (default `~/.claude/projects`), for the test fixture.
- The session id `owners.py` skips is its own default, `CLAUDE_CODE_SESSION_ID` from the environment.
  *Probed: the Bash tool's environment carries `CLAUDE_CODE_SESSION_ID` (`echo $CLAUDE_CODE_SESSION_ID` printed a
  uuid).*

It runs in this order and prints one line per stage, so the caller relays its output unedited.

1. **Read the PR:** `gh pr view <pr> [-R …] --json number,state,headRefOid,headRefName,baseRefName`. A failed call:
   its stderr, then `teardown: nothing removed: gh could not read PR <pr>`, exit 1.
2. **Mark the page** when there is one: `MERGED` → `php <skills>/pipeline/checks/proof_cli.php status <page> merged`,
   `CLOSED` → `… closed`, where `<skills>` is two levels up from the script's real path (the repo's `skills/`, also
   behind the `~/.claude/skills/` symlinks). A failed mark prints `page: not marked: <stderr>` and goes on:
   the prune pass corrects a page's status from GitHub (engine.md §The proof store), and the manifest that names the
   page is about to go with the worktree. It is marked before anything is checked or removed, as today.
3. **Not merged:** `OPEN` → `teardown: nothing removed: PR #<P> is still open`, exit 1. `CLOSED` →
   `teardown: nothing removed: PR #<P> was closed without merge`, exit 1.
4. **The checks**, every one computed and printed together, `ok` or `FAIL` with what was found:
   - `clean`: `git status --porcelain` prints nothing;
   - `head`: `git rev-parse HEAD` equals `headRefOid`;
   - `branch`: `git branch --show-current` equals `headRefName` (the branch about to be deleted is the PR's);
   - `outside`: the script's own cwd is not inside a linked worktree it is about to remove (the script runs from the
     primary checkout, and a session that entered the worktree must `ExitWorktree` with `keep` first; the `FAIL`
     names both; for the primary checkout this check is always `ok`);
   - `owners`: `claude agents --json --all | owners.py <checkout> [--projects-dir …]` exits 0 and prints nothing;
     otherwise its stdout and stderr are shown.
   Any `FAIL`: `teardown: nothing removed: <the failed checks' names>`, exit 1. Nothing was touched but the page.
5. **Remove**, by the checkout's kind:

   | kind | how it is recognised | removal (cwd: the primary checkout) | last line |
   |---|---|---|---|
   | slot | the primary checkout has `scripts/worktree.sh`, and the checkout is `<dirname(primary)>/<basename(primary)>-<N>` | `bash scripts/worktree.sh remove <N> --force-local-branch-removal`, then `git branch -D <head>` only if the branch still exists | `teardown: removed slot <N> (<checkout>), its stack and branch <head>` |
   | linked worktree | `git rev-parse --git-dir` differs from `--git-common-dir`, and it is not a slot | `git worktree remove <checkout>`, then `git branch -D <head>` | `teardown: removed worktree <checkout> and branch <head>` |
   | primary checkout | `--git-dir` equals `--git-common-dir` | `git switch <base>`, `git pull --ff-only origin <base>`, `git branch -D <head>` | `teardown: <checkout> back on <base> at <short sha>; removed branch <head>` |

   The primary checkout's line ends with `; not run: ./scripts/restart.sh (it reseeds the database)` when the
   checkout has `scripts/restart.sh`: the stack is never restarted (`CLAUDE.md` §Docker Environment), only offered.
   A removal command that fails: its output, then `teardown: stopped at <command>: <what was already done>`, exit 3,
   and nothing after it runs. Success: exit 0.

   `git branch -D`, not `-d`: the checks proved `HEAD` is the merged head, so the branch holds nothing unmerged even
   when GitHub squashed it.

Exit codes: 0 removed, 1 nothing removed (open, closed, a check failed, `gh` failed), 2 usage, 3 stopped part-way.
The last line always starts with `teardown: `, so a caller can quote just that line.

### `owners.py`: a nested worktree is not the checkout

The primary checkout contains `.claude/worktrees/*`, so today a session working in one of those counts as an owner
of the primary checkout (`inside()` is a path prefix). `works_in()` gains one rule: an entry whose cwd lies inside
another registered worktree nested in the target (from `git -C <target> worktree list --porcelain`, every path inside
the target but not equal to it) is not evidence for the target. Nothing else changes: the pipeline evidence already
matches the worktree path exactly, and the fail-closed rule (`related()`) is untouched, so an unreadable session in a
nested worktree still blocks (same repository). For a linked worktree or slot the list is empty and the behaviour is
exactly today's.

### The watch: armed by whoever opened the PR, re-armed at its limit

The loop is orchestrate commands §Watch's "awaiting merge" loop, unchanged, with the caller's interval:
`until s=$(gh pr view <P> -R <repo> --json state --jq .state 2>/dev/null) && [ "$s" != OPEN ]; do sleep <n>; done; echo "PR #<P> $s"`.

- orchestrate and a standalone `/pipeline`: `sleep 300` (settled);
- a plain session (`CLAUDE.md`): `sleep 60`, as the issue asks: about 60 GraphQL calls an hour per waiting PR.

The Bash tool caps a background command at 7,200,000 ms and stops it at 1,800,000 ms when no `timeout` is passed
(its own description in this session). So every watch passes `timeout: 7200000`, and a watch that ends without a
`PR #<P> <state>` line was stopped at its limit: the session re-arms it, once per two hours of waiting, and says
nothing about it. commands §Watch's note that a loop "outlives its call" is replaced by this rule; it is what makes
the issue's "a watch armed for more than 2 hours still exits on the merge" hold.

### The callers

**`CLAUDE.md` §Git Workflow**, a new bullet right after *Never commit directly to main … open a pull request*:

> **Watch the PR you open.** Right after `gh pr create`, arm one background Bash (`run_in_background: true`,
> `timeout: 7200000`):
> `until s=$(gh pr view <P> -R <repo> --json state --jq .state 2>/dev/null) && [ "$s" != OPEN ]; do sleep 60; done; echo "PR #<P> $s"`.
> It ends without that line at its time limit: arm it again. When it prints the state, run the teardown from the
> primary checkout, leaving the worktree first if you entered it (`ExitWorktree`, `keep`):
> `cd <primary checkout> && python3 ~/.claude/skills/orchestrate/teardown.py <checkout> <P> --repo <repo>`; report its last line, without asking first: on a merge it removes the worktree, slot or feature branch only when
> every check holds, and otherwise removes nothing and says why. Its output is a report, not a question. One watch
> per PR: a `/pipeline` or `/orchestrate` session arms its own, and a pipeline step or any subagent arms none.

The exact wording is the plan's; the content above is the rule. *Never work against a stale checkout* gains half a
sentence: the teardown's `git pull --ff-only` of the base after a merge is not the working branch, so it is not
covered by raise-and-wait.

**orchestrate commands §Teardown** becomes: the *no pending notice of yours* check (the dispatch record, which only
the orchestrator knows), then

```bash
python3 ~/.claude/skills/orchestrate/teardown.py <worktree> <P> --repo <repo> [--proof <proof>]
```

with `<proof>` the one `finish` printed (the manifest's `artifacts.proof` is the script's own fallback), and the
batch-on-base `gh issue close` after a successful run, unchanged. The verify block, the `proof_cli.php status` call
and both removal blocks go: they are the script. **SKILL.md step 6** keeps its rules (tear down without asking, ahead
of `slots`' confirm step, re-map after) and points at the script; *A check fails: ask, quoting the output* becomes
*report its output; a later report repeats the slot still standing*, as the issue wants a plain report. A PR closed
without merge is still asked about, with everything waiting on it: that is a dependency fork, not a teardown check.

**pipeline engine.md §After the merge**: step 2 is the script call (mark, checks and removal in one), and *A check
fails: ask* becomes *report its output*; step 3's closed PR is the script's exit 1 after marking `closed`. Step 1 keeps
orchestrate's loop and gains the `timeout`/re-arm rule. *The watch dies with the session* stays.

**slots `SKILL.md`**, *Exception — your own slot after its PR merged*: "a slot a `/pipeline` or `orchestrate` run
created" widens to also "or a session opened its PR from (`CLAUDE.md` §Git Workflow)", and the checks it names become
`orchestrate/teardown.py`'s.

## Testing

`skills/orchestrate/tests/teardown_test.sh`, a bash fixture test in the style of `owners_test.sh`: a temp dir with a
bare `origin`, a primary clone `Shop/Shop` with a commit pushed on `main`, and stubs first on `PATH`:

- `gh`: prints the JSON in `$TEARDOWN_GH` (the case sets state, `headRefOid`, branches), and appends its argv to a log;
- `claude`: prints `$TEARDOWN_AGENTS` (default `[]`);
- `php`: appends its argv to a log and exits `$TEARDOWN_PHP_EXIT` (default 0);
- the slot cases add `Shop/Shop/scripts/worktree.sh`, a stub that logs its argv and runs
  `git worktree remove --force` on the slot and `git branch -D` when given `--force-local-branch-removal`.

Cases (each asserts the exit code, the last line, and what is or is not left on disk):

1. merged, a linked worktree under `Shop/Shop/.claude/worktrees/b`, clean, HEAD = sha: exit 0, the directory and
   the branch are gone, `git worktree list` no longer names it.
2. merged, a slot `Shop/Shop-4`: exit 0, `worktree.sh` was called with `remove 4 --force-local-branch-removal`, the
   slot and branch are gone.
3. merged, the primary checkout on `feature/x` whose head was pushed and merged into `origin/main`: exit 0, the
   checkout is on `main` at `origin/main`, `feature/x` is gone; with `scripts/restart.sh` present the last line names
   it as not run, and nothing ran it.
4. dirty tree (an untracked file): exit 1, last line names `clean`, worktree and branch still there.
5. HEAD is not the merged sha (one more local commit): exit 1, names `head`.
6. another live session owns it (`$TEARDOWN_AGENTS` plus a transcript fixture under `--projects-dir` whose cwd is the
   worktree): exit 1, names `owners`, and the owner line is printed.
7. `owners.py` fails closed (a live session with no transcript, rooted in the worktree): exit 1, names `owners`, its
   stderr is shown.
8. the script's cwd is inside the worktree: exit 1, names `outside`.
9. closed without merge: exit 1, the `closed` line, the page marked `closed` (php log), nothing removed.
10. still open: exit 1, nothing marked, nothing removed.
11. the page: with a manifest at `.claude/pipeline/feature-x.json` holding `artifacts.proof`, the php log shows
    `status <page> merged` and it is logged before `worktree.sh` (slot case log order); `--proof` overrides it; a
    failing `php` prints `page: not marked` and the removal still happens.
12. a removal that fails (the `worktree.sh` stub exits 1): exit 3, last line `stopped at …`, `git branch -D` not run.
13. every check that fails is printed in one run (dirty and HEAD off together): both `FAIL` lines appear.
14. usage: a path that is not a working tree's top level → exit 2, usage on stderr.

`owners_test.sh` gains one case: a live session whose transcript's cwd is inside `Shop/Shop/.claude/worktrees/b` does
not own `Shop/Shop`, while one whose cwd is `Shop/Shop` itself still does.

Both tests run with `bash skills/orchestrate/tests/<name>_test.sh` and print `PASS <script>`.

**Verification on this machine** (the issue's *Verify*): the live check needs the owner's merge with the admin
bypass, which an unattended run cannot do, so it is the owner's acceptance step after the PR, written into the PR body:
a plain session opens a throwaway PR in this repo and arms the watch as `CLAUDE.md` says; the owner merges it; within
about a minute the session reports the script's last line with no message from the owner, and the branch (and
worktree) are gone. Everything the session does after the watch fires is the script, which the fixture test proves. The re-arm is proven by reading: the tool's stated cap, and the rule that re-arms on
a missing state line.

## Done when

- `teardown.py` exists with the interface, order, output and exit codes above, and both fixture tests pass.
- `owners.py` no longer counts sessions in nested worktrees as owners of the primary checkout.
- orchestrate commands §Teardown, SKILL step 6, pipeline §After the merge and slots' exception call the script and
  carry no copy of its checks or removal.
- Every watch passes `timeout: 7200000` and is re-armed when it ends without a state line; orchestrate's interval is
  still 300 s.
- `CLAUDE.md` §Git Workflow carries the *Watch the PR you open* rule.

## Assumptions

Each question the brainstorm would have asked the owner, and the answer assumed.

1. **Where does the script live, and in what language?** `skills/orchestrate/teardown.py`, Python, beside
   `owners.py` (which it calls) and `needs_input.py`; `hooks/git-freshness.sh` links the whole skill directory on each
   machine, so nothing is installed. Named after the section it replaces (commands §Teardown).
2. **Does the script run the repo's declared `worktree.remove` from `.claude/work-on.config.md`?** No: it recognises
   the checkout's kind instead. The 13 configs on this machine declare exactly two kinds of remove,
   `scripts/worktree.sh remove <slot|N>` and `git worktree remove .claude/worktrees/<branch>`, with placeholders that
   differ per repo, and the plain-git one guesses the path from the branch (an `EnterWorktree` worktree's directory
   is not its branch). Recognising a slot by `scripts/worktree.sh` plus the `<Project>-<N>` path, and removing any
   other linked worktree by its real path, does what both declared commands do without executing a config line.
   Asimo's remove prefixes `cd … && bash`; the script always runs `bash scripts/worktree.sh` from the primary.
3. **Is the primary checkout's switch safe while other sessions live there?** The `owners` check covers it: a session
   whose transcript works in the primary checkout blocks the switch, including the orchestrator and a session that
   started there before `EnterWorktree`. That makes the primary case refuse more often than strictly needed; it is
   the safe side, and the report names who blocked it. Sessions working only in a nested worktree no longer block it
   (the `owners.py` change).
4. **A failed check: ask or report?** Report, in all three callers, as the issue says ("a plain report, not a question
   that holds the session"). Orchestrate's step 6 asked until now; a leftover slot is not a fork between two paths,
   and the next report repeats it. A PR closed without merge is still asked about by orchestrate, because its
   dependents are a real fork.
5. **Is the proof page marked even when a check fails?** Yes: `merged`/`closed` is GitHub's fact, not the teardown's.
   It is marked before the checks, as engine.md §After the merge does today, so the manifest that names it is read
   before anything can remove it.
6. **A failed `proof_cli.php status`: stop?** No, print it and go on. The prune pass corrects status from GitHub, and
   stopping would leave a merged slot standing over a page label.
7. **`git branch -D` or `-d`?** `-D`, because a squash merge leaves `-d` refusing a branch whose head the checks
   proved is the merged head.
8. **The primary checkout's pull: `git pull --ff-only` or `origin <base>`?** `git pull --ff-only origin <base>`, so a
   base without a tracking branch still updates. A diverged local base fails it: exit 3, with the branch not yet
   deleted, and the report says so.
9. **Watch interval for plain sessions?** 60 s, from the issue. The owner's settled decision fixes orchestrate's 300 s
   only; a standalone `/pipeline` keeps orchestrate's loop as engine.md says.
10. **What about the Bash tool's background limit?** Measured from the tool's own description in this session: default
    1,800,000 ms, max 7,200,000 ms, stopped and the session re-invoked at the limit. Without a rule, a plain watch dies
    after 30 minutes. Every watch passes the max and is re-armed on a missing state line. The issue's 2-hour Deploy
    note (2026-09-16) predates or sits at that cap; the re-arm makes the outcome independent of it.
11. **Who arms the watch when a pipeline step opens the PR?** Not the step: its agent ends with the step. The session
    that runs `/pipeline` or `/orchestrate` arms it (pipeline SKILL step 6, orchestrate step 5). The `CLAUDE.md` rule
    says a pipeline step or subagent arms none, so no PR gets two watches and two teardowns.
12. **Does the script call `ExitWorktree`?** It cannot (a tool, not a command). It refuses with `outside` instead, and
    the rule tells the session to leave first, as engine.md §After the merge already does.
13. **A plain session's `needs input:` line?** Out of scope: only orchestrate prints one, and its step 6 already drops
    a merged PR from it. The issue's "the line for that PR goes away" is today's behaviour there.
14. **Does the script delete the remote branch?** No: not asked, and GitHub's auto-delete setting is per repo.
15. **Does `slots/SKILL.md` change although the issue names only three places?** One sentence: its exception names who
    may remove a slot without the confirm step, and a plain session's slot is now one of them; leaving it would have
    the `slots` skill contradict the rule.
16. **Should the `CLAUDE.md` rule hold for `/work-on` sessions?** Yes, unchanged: the issue says user instructions
    take precedence over a skill, and work-on is not edited (DevOps-Claude-Config).
17. **Is a changelog entry needed?** No: this repo has neither `.changelog/` nor `CHANGELOG.md`.
18. **What happens to `Worktree.remove` in the repo config?** Nothing reads it any more once the script recognises
    the checkout's kind (*Assumptions* 2). Its row in pipeline engine.md §The repo config stays (the pipeline's
    `LockStepTest` pins `| \`Worktree\` | \`remove\` |`, and the file is shared with `work-on`), its *Read by* says the
    teardown recognises the checkout's kind instead and its requirement becomes `—`; orchestrate `SKILL.md` stops
    naming `worktree.remove` among the keys it needs. No PHP reads the key, so kickoff is unchanged.
19. **How are paths compared?** As real paths. *Probed: `git worktree list --porcelain` prints a worktree's real path,
    `/private/var/…` for one added under `/var/…` (a throwaway repo under `mktemp -d`, then
    `git worktree list --porcelain`).* So `owners.py`'s nested-worktree rule compares `os.path.realpath` of the entry's
    cwd with the listed paths, and the teardown fixture builds under `pwd -P`.
20. **`claude agents` itself fails?** The `owners` check fails with its stderr: the same fail-closed side as
    `owners.py`'s exit 2.
21. **How is the primary checkout found?** The first `worktree` entry of `git -C <checkout> worktree list
    --porcelain`: git lists the main working tree first, and it is the one whose git dir is the common dir, so this is
    the *kind* table's rule read from one command. A checkout equal to it is the primary kind.
22. **engine.md §The proof store's status table** names `merged`/`closed` as written by the session holding the merge
    watch. It now names `orchestrate/teardown.py`, run by that session; the rest of the row is unchanged.

## Relation to other work

- Sibling runs in this batch (#146, #150, #169) had no PR at kickoff; a plan that changes the same files merges the
  base first (engine.md §Catching up with the base).
- #161 (merged) fixed the prune pass the *page not marked* fallback relies on for old-scheme runs.
