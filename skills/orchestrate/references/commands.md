# orchestrate — commands

The commands behind `../SKILL.md`, in step order. `jq` is not installed on the owner's machines:
`gh --jq` and `python3` filter instead. `<repo>` is `repo:` from `.claude/work-on.config.md`.

## Where am I

```bash
claude agents --json --all | python3 -c 'import json,os,sys; [print(a["kind"], a["cwd"]) for a in json.load(sys.stdin) if a.get("sessionId") == os.environ.get("CLAUDE_CODE_SESSION_ID")]'
git worktree list | head -1     # the primary checkout
```
`background <primary checkout>` → the orchestrator. Anything else → the launcher. The orchestrator
never calls `EnterWorktree`: its guard refuses git outside that worktree, and the orchestrator reads
and removes every worktree.

The launcher fills `spinoff`'s brief: *Do* "Use the orchestrate skill for #a #b"; *Context* the owner's
decisions per issue; *Related work* the map, "as seen at HH:MM".

## Map

```bash
git fetch origin --prune
git worktree list --porcelain
gh pr list -R <repo> --state open --json number,headRefName,baseRefName,isDraft,title
claude agents --json --all
```
Per requested issue N and per dependency, `<prefix>` is `branch.issue` with `<number>` → N, cut at
`<slug>` (e.g. `feature/issue-429-`):
```bash
gh issue view N -R <repo> --json number,title,state,stateReason,body
gh api /repos/<repo>/issues/N/dependencies/blocked_by --jq '.[] | "#\(.number) \(.state)"'
gh pr list -R <repo> --state all --search "head:<prefix>" \
  --json number,state,isDraft,headRefName,baseRefName,headRefOid,mergedAt \
  --jq '.[] | select(.headRefName | startswith("<prefix>"))'
```
The PR is found by prefix: `closingIssuesReferences` stays empty until the run's `review-pr`.

## Owner of in-flight work

- **A run this session dispatched:** the dispatch record (agent id for `auto`, the workflow's task
  id for `autoflow` → issue) is the owner. Search nothing.
- **Anything else:**
  ```bash
  claude agents --json --all | python3 ~/.claude/skills/orchestrate/owners.py <worktree>
  ```
  One line: in flight, owned by that session. Several lines: ask which one. No output and exit 0:
  orphaned. **A non-zero exit is never orphaned**: the lookup could not read the transcript of a live
  session rooted in, above, or in the same repository as the worktree, so treat the worktree as owned
  and ask the owner, quoting the error. An unreadable session rooted anywhere else is skipped.
  Only a `working` or `blocked` session owns a worktree; `done`, `failed` and `stopped` rows never do.
  An open PR with no worktree has no owner to find: treat it as orphaned.
  An `autoflow` run's steps work from the launch directory, so for those `owners.py` also reads the
  workflow step transcripts and matches what names the run's worktree: its `dispatch_cli.php` calls,
  kickoff's and launch's answers, and the `Workflow` call. Fail-closed does not cover that evidence:
  an `autoflow` run that stops being found after a Claude Code update is a transcript format change
  first and an orphan second.

## Dependencies

The `#M` references on `Depends on` lines (cross-repo `owner/repo#M` are listed separately, to report):
```bash
gh issue view N -R <repo> --json body --jq .body | python3 -c '
import re, sys
for line in sys.stdin:
    if re.match(r"\W*depends on\b", line, re.I):
        print("local:", *re.findall(r"(?<![\w/])#(\d+)", line))
        print("cross-repo:", *re.findall(r"[\w.-]+/[\w.-]+#\d+", line))'
```
Classify each `#M`, and read a PR dependency's state:
```bash
gh api repos/<repo>/issues/M --jq '{number, state, state_reason, is_pr: (.pull_request != null)}'
gh pr view M -R <repo> --json state --jq .state
```

## Brief

The `auto` dispatch, one background agent per issue:

```
Run /pipeline auto <N> in <owner/repo>. This session sits in the primary checkout <path>.

Unattended: do not open the proof page in a browser (PIPELINE_NO_OPEN=1, pipeline engine.md §The proof store).
Kickoff: php ~/.claude/skills/pipeline/checks/dispatch_cli.php kickoff <path> <N> --mode auto, with one --decision per settled decision below; a halt ends the run. Never switch branches in <path>; other runs share it.

Settled decisions (owner, <date>): <each decision verbatim | none>

Pointers: issue #<N>; depends on <#M (PR #P, merged) | none>.

Return: the PR number, draft or ready, the halt reason if it halted, and its open questions verbatim.
```
Add nothing else (`pipeline` `references/engine.md` §What a leg brief consists of).

## Launch

`autoflow`, per issue N, from the primary checkout: pipeline `SKILL.md` §`autoflow` — how a run starts
and ends, steps 1–3.

- Kickoff is `php ~/.claude/skills/pipeline/checks/dispatch_cli.php kickoff <primary checkout> N`,
  with one `--decision "<verbatim>"` per settled decision of the owner's for N. `ready` names the
  manifest (`mode: autoflow`, `artifacts.issue` N); a halt: report it and start nothing. Never
  create the worktree another way or switch branches in the primary checkout; other runs share it.
- `launch` runs with `PIPELINE_NO_OPEN=1`: the run is unattended. `done` or a halt: report it and
  start no workflow.
- Start the workflow `pipeline-autoflow` with `launch`'s JSON as `args`, in the background, and add
  its task id → N to the dispatch record (the id `TaskStop` takes and the completion notice carries;
  the `wf_…` run id names the transcript dir). Do not wait on it; its completion notice arrives.

The engine follows the manifest's `mode`, not the batch's argument: `launch` refuses a manifest that is
not `autoflow`, `next` one that is. A dead session's `autoflow` run: `finish` it with a halt, then a new
`launch` and workflow.

Commits wanted on a ready PR, after `gh pr ready --undo <P>`:

```bash
php -r '$m = json_decode(file_get_contents($argv[1]), true); $m["decisions"][] = $argv[2]; file_put_contents($argv[1], json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");' <manifest> "<the owner's request, verbatim>"
git -C <worktree> diff origin/<base>...HEAD > <manifest stem>.diff
PIPELINE_NO_OPEN=1 php ~/.claude/skills/pipeline/checks/dispatch_cli.php launch <manifest> <manifest stem>.diff --from review-pr
```

then a new `pipeline-autoflow` workflow with that JSON.

## Finish

`autoflow`, on a run's completion notice (pipeline `engine.md` §`autoflow` — a program that calls
agents):

```bash
php ~/.claude/skills/pipeline/checks/dispatch_cli.php finish <manifest> '<the workflow return, as JSON>'
gh pr ready <P> -R <repo>                                                      # only when finish printed done
php ~/.claude/skills/pipeline/checks/run_cost_cli.php <the run's transcript dir>
git -C <worktree> diff origin/<base>...HEAD > <manifest stem>.diff
php ~/.claude/skills/pipeline/checks/run_audit.php <manifest> <manifest stem>.diff <the run's transcript dir>
```

The transcript dir is the `wf_<id>` directory the workflow result names, not the task id. `finish`
refuses a `done` whose cursor is not on `review-pr`, or whose last step, `review-pr`'s resolve step,
left a return that does not hold (an open `pr-review` entry, a key only the engine writes): it records
and prints a halt instead. A denied `gh pr ready` writes no halt: the manifest already says done and
the PR stays draft, so the denial goes in the report and the owner runs `gh pr ready` by hand. A
workflow that errored: `finish <manifest> '{"action":"halt","reason":"<the error>"}'`. A halt
after `handoff`: the reason into the PR body, as pipeline `engine.md` §Failure policy — what still
stops (*Bound exhaustion*) says. No proof page opens on a halt in an unattended batch, unlike pipeline
`SKILL.md`'s attended "opened once": it opens only on a ready PR (§Proof page).

A stalled run: `TaskStop` its task id first. Only once it reports the task stopped,
`finish <manifest> '{"action":"halt","reason":"stalled: no notice, commit or PR change for 90 minutes"}'`.
TaskStop finds nothing: the workflow completed just before, so `finish` its real return instead. A
notice that arrives after a stall's `finish` is not finished again.

## Watch

One background Bash (`run_in_background: true`) per PR. Each exits on the change being waited for.
A `run_in_background` loop outlives its call; the Deploy orchestrator's watch on PR #431 ran two hours
and exited on the change (2026-09-16).

```bash
# awaiting merge: exits once the PR is merged or closed
until s=$(gh pr view <P> -R <repo> --json state --jq .state 2>/dev/null) && [ "$s" != OPEN ]; do sleep 300; done; echo "PR #<P> $s"
# adopted run: also exits when the PR leaves draft
until s=$(gh pr view <P> -R <repo> --json state,isDraft --jq '"\(.state) \(.isDraft)"' 2>/dev/null) && [ "$s" != "OPEN true" ]; do sleep 300; done; echo "PR #<P> $s"
```
Adopted session, finished signal: `SendMessage` to its name with `notify_when_idle: true` and no message.

## Proof page

Once, when announcing a ready PR, and only if the run made one. Pass the file, not the directory:
```bash
php ~/.claude/skills/pipeline/checks/proof_cli.php open ~/GitProjects/_proofs/<repo>/pr-<P>-<topic>/index.html
```

## Teardown

Verify, printed together:
```bash
git -C <worktree> status --porcelain | wc -l                                          # 0
git -C <worktree> rev-parse HEAD                                                       # equals the sha below
gh pr view <P> -R <repo> --json state,headRefOid --jq '"\(.state) \(.headRefOid)"'    # MERGED <sha>
claude agents --json --all | python3 ~/.claude/skills/orchestrate/owners.py <worktree>   # nothing, exit 0, and no pending notice of yours
```
A non-zero exit from `owners.py` fails the check: do not tear down; ask, quoting its error.

Then, from the primary checkout:
```bash
./scripts/worktree.sh remove <N> --force-local-branch-removal     # declared remove is scripts/worktree.sh
```
```bash
<the declared worktree.remove>                                    # any other repo
git branch -D <branch>
```
