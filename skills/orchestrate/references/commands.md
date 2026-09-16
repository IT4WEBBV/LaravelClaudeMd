# orchestrate — commands

The commands behind `../SKILL.md`, in step order. `jq` is not installed on the owner's machines:
`gh --jq` and `python3` filter instead. `<repo>` is `repo:` from `.claude/work-on.config.md`.

## Where am I

```bash
claude agents --json --all | python3 -c 'import json,os,sys; [print(a["kind"], a["cwd"]) for a in json.load(sys.stdin) if a.get("sessionId") == os.environ["CLAUDE_CODE_SESSION_ID"]]'
git worktree list | head -1     # the primary checkout
```
`background <primary checkout>` → the orchestrator. Anything else → the launcher.

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

- **A run this session dispatched:** the dispatch record (agent id → issue) is the owner. Search nothing.
- **Anything else:**
  ```bash
  claude agents --json --all | python3 ~/.claude/skills/orchestrate/owners.py <worktree>
  ```
  One line: in flight, owned by that session. No output: orphaned. Several lines: ask which one.
  An open PR with no worktree has no owner to find: treat it as orphaned.

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

```
Run /pipeline auto <N> in <owner/repo>. This session sits in the primary checkout <path>.

Unattended: do not open the proof page in a browser (PIPELINE_NO_OPEN=1, pipeline engine.md §The proof store).
<only in a repo without scripts/worktree.sh:> Worktree: create it with the declared worktree.create (<command>). Never switch branches in <path>; other runs share it.

Settled decisions (owner, <date>): <each decision verbatim | none>

Pointers: issue #<N>; depends on <#M (PR #P, merged) | none>.

Return: the PR number, draft or ready, the halt reason if it halted, and its open questions verbatim.
```
Add nothing else (`pipeline` `references/engine.md` §What a leg brief consists of).

## Watch

One background Bash (`run_in_background: true`) per PR. Each exits on the change being waited for:
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
claude agents --json --all | python3 ~/.claude/skills/orchestrate/owners.py <worktree>   # nothing, and no pending notice of yours
```
Then, from the primary checkout:
```bash
./scripts/worktree.sh remove <N> --force-local-branch-removal     # declared remove is scripts/worktree.sh
```
```bash
<the declared worktree.remove>                                    # any other repo
git branch -D <branch>
```
