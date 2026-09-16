# `orchestrate` — drive several issues to merged PRs through pipeline runs — design

**Design size:** Architectural

**Date:** 2026-09-16
**Canonical home:** `IT4WEBBV/LaravelClaudeMd`, `skills/orchestrate/` (personal skill, symlinked into
`~/.claude/skills/`).
**Amends:** `skills/pipeline/references/engine.md` (three additions: coordinator worktree rule,
answered blockers, stacked runs), `skills/pipeline/SKILL.md` (one Non-goals clause),
`skills/slots/SKILL.md` (one teardown exception).
**Written unattended** by the `design` leg of a `/pipeline auto` run. Every question the brainstorm
would have asked is answered in *Assumptions* at the end; `/critique plan` audits those.

## Summary

`orchestrate` lets one long-running session take a set of GitHub issues in one repo to merged PRs. It
dispatches a `/pipeline auto <issue>` run per issue, respects the dependencies between them, and
waits for the owner's merges. It is a **thin layer above `pipeline`**: every leg stays pipeline's.
Orchestrate owns only what happens *between* runs, which nothing owns today:

1. **Preflight.** Map worktrees, branches, PRs and live sessions, and refuse to launch over in-flight
   work on a requested issue or its dependency.
2. **Ordering.** Read dependencies. Run independent issues in parallel, bounded by free slots and a
   cap. Hold a dependent issue until its dependency's PR is merged, unless the owner chose to stack it.
3. **Babysitting.** Two kinds of in-flight run: agents this session dispatched, and separate sessions
   it adopted. Never a second agent for one run. The `SendMessage` reply is the liveness check. No
   status pings. `gh pr ready --undo` before reopening a run on a ready PR.
4. **Owner in the loop.** Ask real forks as `AskUserQuestion`, announce ready PRs (and open their
   proof page), relay the owner's answers to the right run.
5. **After a merge.** Verify the worktree is clean, pushed and MERGED, tear it down, then start what
   the merge unblocked. Never touch a worktree whose run is in flight.
6. **No private state.** Inputs come from the invocation. Progress is rebuilt from git, gh and the
   session list, so a crashed orchestrator resumes.

Stacking is the one feature that needs pipeline to behave differently inside a leg. It is specified
as a small, explicit contract in `engine.md`, because pipeline owns the legs.

## The evidence

The owner has orchestrated pipeline runs by hand twice:

- **BreinStraat2, ~70 runs.** The standing rules came out of incidents there:
  - a second agent dispatched onto a live `review-pr` leg reverted its uncommitted file mid-suite
    (2026-08-06, #879);
  - a ready PR merged while a reopened agent was still committing, twice, orphaning `afbb51b1` and
    `2d880d7f` (PRs #939 and #949);
  - nine idle slots accumulated in one day of parallel runs (2026-08-05);
  - 29 open questions across 4 PRs never reached the owner (batch 2);
  - ~30 "are you still working?" messages in 70 runs, none of which made a reviewer finish sooner.
- **Deploy, 2026-09-16.** Issues #413 → #429 → #411 run through the session "Deploy: dashboard issues
  orchestrator". Its brief was ~50 hand-written lines restating the rules above. The #413 run (a
  separate `claude --bg` session, slot Deploy-5, draft PR #431) overlapped #429's plan and was found
  only through `git worktree list`.

The rules live in six memory files, the pipeline engine notes and that brief. A new orchestrator
follows them only when someone remembers to paste them in. A skill makes them the default.

## Goals

- One invocation drives N issues in one repo to PRs the owner merges, in dependency order.
- The four rules that slip under time pressure hold by default: no double dispatch, no commits onto a
  ready PR, no launch over in-flight work, no teardown of an unmerged worktree.
- An orchestrator that dies is replaced by invoking it again, with nothing lost that git and gh know.
- Pipeline, work-on and handoff keep owning their stations. Orchestrate links to them.

## Non-goals

- **Merging.** The owner merges, always. Orchestrate never runs `gh pr merge`.
- **Running a leg itself,** or editing a file in any checkout. Commits come only from a pipeline run.
- **Several repos per orchestrator.** One orchestrator, one repo.
- **Closing issues,** moving board cards, or posting to GitHub beyond what the runs already post.
- **Proposing stacking.** The owner starts a stack; orchestrate never offers one.
- **A state file.** No manifest of its own. Its progress is what git, gh and the session list say.
- **Deterministic PHP checks** (§12).

## Design

### 1. Where the orchestrator runs

**Always a `claude --bg` session whose working directory is the target repo's primary checkout.**

- **Outlives the launcher.** Runs take hours and merges wait on the owner for days. An Agent-tool
  subagent dies with its session (`spinoff` §Overview), so the orchestrator is a background session.
  It is also a main conversation, which is required: `SendMessage`'s `notify_when_idle` works only
  from a main conversation.
- **The primary checkout.** Pipeline runs create slots with `scripts/worktree.sh create`, which must
  run from the primary checkout. Teardown runs from there too. Dispatched subagents inherit the
  orchestrator's working directory. The live Deploy orchestrator runs this way (cwd
  `/Users/jroelofs/GitProjects/Deploy/Deploy`).
- **Never `EnterWorktree`.** An isolated session refuses git commands aimed outside its worktree, and
  the orchestrator must read every worktree (`git -C <worktree> status`) and remove them. The engine
  of this design run hit that refusal today.
- **Never edits a file** in any checkout. That also keeps it clear of `bgIsolation: worktree`, which
  refuses background-session edits to the shared checkout. Scratch output, if any, goes to
  `$CLAUDE_JOB_DIR/tmp`.

**Launching.** `/orchestrate` in any other session (interactive, or a background session elsewhere)
is the *launcher*. The launcher checks where it is:

```bash
claude agents --json --all | python3 -c 'import json,os,sys; [print(a["kind"], a["cwd"]) for a in json.load(sys.stdin) if a.get("sessionId") == os.environ["CLAUDE_CODE_SESSION_ID"]]'
git worktree list | head -1     # the primary checkout
```

`jq` is not installed on the owner's machine. `gh` filters with its own `--jq`, and the session list
goes through `python3`. Both verified 2026-09-16: this design's own session printed
`background <its worktree>`.

- If this session is not `background`, or its cwd is not the primary checkout, it is the launcher:
  1. It runs the preflight (§3) read-only, because the owner is present to answer overlap questions.
  2. It launches the orchestrator with **`spinoff`**, using spinoff's brief template. *Do* says
     "Use the orchestrate skill for #a #b #c". *Context* carries the owner's decisions per issue.
     *Related work* carries the preflight map, "as seen at HH:MM".
  3. It stops.
- Otherwise this session is the orchestrator, and it runs §3 onward.

The launcher repeats none of spinoff's steps. It follows spinoff.

### 2. Invocation and inputs

```
/orchestrate <issue> [<issue> …]
```

- **Repo.** The primary checkout's `.claude/work-on.config.md` names it (`repo:`). That file must
  declare `worktree.create`, `worktree.remove` and `branch.issue`. Otherwise orchestrate stops at
  preflight and names the missing key: pipeline needs the same keys, and teardown cannot be done
  safely without `remove`.
- **Changes are natural language** in the orchestrator session: "stack #429 on #413", "also do #420",
  "drop #411", "at most 2 at once". Each change re-runs the preflight and re-plans.
- **Inputs vs. progress.** The inputs are the issue list, the owner's per-issue decisions, stack
  choices and the concurrency cap. They live in the invocation and the spinoff brief. Everything else
  is progress and is re-read (§10).

### 3. Preflight — the map

Runs at start, at every re-plan and at every resume. It never mutates anything except
`git fetch origin --prune`.

1. **Repo-wide:**
   ```bash
   git fetch origin --prune
   git worktree list --porcelain
   gh pr list -R <repo> --state open --json number,headRefName,baseRefName,isDraft,title
   claude agents --json --all        # or the ListAgents tool when present
   ```
2. **Per requested issue `N`, and per dependency (§4):**
   - `prefix` is `branch.issue` with `<number>` → `N`, cut at `<slug>` (e.g. `feature/issue-429-`).
   ```bash
   gh issue view N -R <repo> --json number,title,state,stateReason,body
   gh api /repos/<repo>/issues/N/dependencies/blocked_by --jq '.[] | "#\(.number) \(.state)"'
   gh pr list -R <repo> --state all --search "head:<prefix>" \
     --json number,state,isDraft,headRefName,baseRefName,headRefOid,mergedAt \
     --jq '.[] | select(.headRefName | startswith("<prefix>"))'
   ```
   - The issue's PR is found by **branch prefix**, not by `closingIssuesReferences`. A run's PR says
     `Part of #N` until `review-pr` sets the closing links, so closing references are empty for its
     whole flight. Verified on Deploy #413 / PR #431.
3. **In-flight work** is a worktree on a branch with that prefix, or an open PR with that head
   prefix.
   - **Owner.** Its owner is either an agent this conversation dispatched with no completion
     notice yet, or a listed session whose transcript names the worktree path:
     ```bash
     grep -l -F "<worktree path>" ~/.claude/projects/*/<sessionId>.jsonl
     ```
     The cwd in the session list does not identify it: adopted runs show cwd `~`. Verified
     2026-09-16: the grep finds session "deploy user feedback" for `Deploy-5`. Several matching
     sessions are an owner question naming the candidates.
   - **Kinds.** With an owner, the work is **in flight**. With none, it is **orphaned**.
4. **What the map settles:**
   - An in-flight or orphaned requested issue is **never dispatched**. It is an owner question (§7):
     - *adopt* it: watch that run as this item's run. Recommended when a live session owns it.
     - *resume* it: dispatch `/pipeline auto N`, which resumes the branch's run. Offered only when
       orphaned.
     - *leave it out.*
   - In-flight work on a **dependency** outside the set is adopted automatically: watching a PR
     changes nothing. It is reported.
   - Another live session that is **itself an orchestrator** over any of the same issues stops this
     one. The owner decides which one stays. Two orchestrators are a double dispatch one level up.

### 4. Ordering — dependencies, waiting, parallelism

**Dependencies of issue `N`** are the union of:

- its native `blocked_by` issues;
- every `#M` on a body line whose label is `Depends on` (case-insensitive, with or without bold).
  Deploy #429: `- **Depends on:** #413 (PR #431), which lands first` yields #413 and #431.
  - Each `#M` classifies itself through `gh api repos/<repo>/issues/M` (`pull_request != null`).
  - An issue dependency is satisfied when a PR for it (by branch prefix) is **MERGED**, or when the
    issue is closed as `COMPLETED`.
  - A PR dependency is satisfied when that PR is **MERGED**. #431 therefore means exactly what #413
    means.
  - A cross-repo reference (`owner/repo#M`) is **reported, not enforced**.

**Satisfied means merged, not closed.** An issue can stay open after its PR merged: a non-default base
branch never fires `Closes`, and a deliberate "stays open" is possible at `review-pr`. The merge is the
owner's go-ahead.

**Item states**, all derived:

| State | Evidence |
|---|---|
| `waiting` | a dependency is unsatisfied, and no stack was chosen |
| `ready-to-start` | every dependency satisfied (or stacked), no worktree, no PR |
| `running` | in flight (§3), dispatched or adopted |
| `halted` | the run returned a halt, or its session ended with the PR still draft and not stacked |
| `awaiting-merge` | PR open and ready |
| `stacked-done` | run finished, PR draft, its base is the dependency branch |
| `merged` | PR MERGED, worktree still present |
| `done` | PR MERGED, worktree gone |

**Dispatch** every `ready-to-start` item in issue-number order while both hold:

- **Free slots:** at least one `<Project>-N` (N = 2..20) directory is absent. This applies only when
  the declared `worktree.create` uses `scripts/worktree.sh`.
- **The cap:** fewer than **4** items are `running`. The owner can change it. `awaiting-merge` does not
  count, because it uses no CPU.

A dependency that is outside the set, not in flight and not merged is an owner question: *add it to
the set* (recommended), *drop the dependent issue*, or *the owner handles it*. A dependency cycle is an
owner question too.

### 5. Dispatching a run — the brief

One Agent-tool call per run, in the background:

- **No `isolation`.** Pipeline creates its own worktree. A harness worktree would be a second one.
- **No model override.** The run inherits the orchestrator's model, as pipeline expects.

The prompt follows `engine.md` §What a leg brief consists of: pointers, settled decisions, the
overrides the engine prescribes, and nothing a station does not ask for.

```
Run /pipeline auto <N> in <owner/repo>. This session sits in the primary checkout <path>.

Overrides (pipeline engine.md):
- Coordinator worktree (§Kickoff): create the run's worktree with the declared worktree.create; no EnterWorktree, no git switch here.
- Unattended batch (§The proof store): run the proof page's open call with PIPELINE_NO_OPEN=1.
- Answered blockers (§The work item): <#M merged, PR #P | #M stack on <branch>, PR #P | none>

Settled decisions (owner, <date>): <each decision verbatim | none>

Pointers: issue #<N>; depends on <#M (PR #P, merged <sha>) | none>.

Return: the PR number, draft or ready, the halt reason if it halted, and its open questions verbatim.
```

**Why each line is allowed:**

- **Coordinator worktree.** `engine.md` §Kickoff gains this override (§11). In a slot repo it changes
  nothing. In a harness repo it keeps the run from moving or mutating the shared checkout.
- **`PIPELINE_NO_OPEN=1`.** The engine names it for "unattended batches where the tabs are noise".
  Orchestrate opens each page once, when it announces that PR (§7). That keeps the owner's
  2026-09-04 rule to open proofs when reporting a run.
- **Answered blockers.** `engine.md` §The work item gains this (§11). Without it, a stacked run, or a
  run whose native blocker merged but stayed open, halts at kickoff.
- **The return line** is the coordinator's interface, not station policy.

### 6. Babysitting — two kinds of in-flight run

| | Dispatched run | Adopted run |
|---|---|---|
| What | an Agent-tool subagent this conversation started | a separate session (`claude --bg`, or another session's subagent) that owns a worktree |
| Finished signal | its task-notification | a `SendMessage` `notify_when_idle: true` subscription with no message, plus the PR watch below |
| Liveness check (suspected stall only) | one `SendMessage`, then read the reply | its row in `claude agents --json --all` / `ListAgents` (`working`, `blocked`, `idle`/`done`) |
| Talk to it | `SendMessage` to its agent id | `SendMessage` to its session name |

**Rules:**

- **One agent per run, ever.** Never dispatch for an item that is `running`, dispatched or adopted,
  however quiet it looks. A flat transcript, no commits and an owner saying "it's dead" are all weak
  signals: a 250-second suite looks identical.
- **The reply is the liveness check.**
  - *"queued for delivery at its next tool round"*: alive. Wait, and tell the owner so.
  - *"was stopped (completed); resumed it in the background"*: that send *was* the recovery. Do not
    dispatch.
  - A replacement is warranted only when a completion notice arrived **and** the work is
    demonstrably unfinished. Even then, stand the original down first and confirm it stopped.
- **Wait for notifications; do not poll agents.**
  - **No status pings.** "Are you still working?" never made a run finish sooner.
  - **Suspected stall** means no completion notice and no commit or PR change on the run's branch for
    **90 minutes**. It earns one `SendMessage`.
  - **Never** loop over `ListAgents`.
- **PR watch.** A background Bash per PR (`run_in_background`), which exits on the change the item
  waits for, so each change is one notification:
  ```bash
  # awaiting-merge: exits once the PR is merged or closed
  until s=$(gh pr view <P> -R <repo> --json state --jq .state 2>/dev/null) && [ "$s" != OPEN ]; do sleep 300; done; echo "PR #<P> $s"
  # adopted running: also exits when it leaves draft
  until s=$(gh pr view <P> -R <repo> --json state,isDraft --jq '"\(.state) \(.isDraft)"' 2>/dev/null) && [ "$s" != "OPEN true" ]; do sleep 300; done; echo "PR #<P> $s"
  ```
  A failed `gh` call keeps the loop waiting instead of ending it. A crashed orchestrator loses these
  watches and re-arms them from the map (§10).
- **More commits on a ready PR:** `gh pr ready --undo <P>` first, then the `SendMessage`. The message
  says the PR is back in draft and tells the run to mark it ready when done. It also says not to
  delete any old remote branch during a recovery: that is orchestrate's call, after the replacement
  PR exists. The owner merges ready PRs as they appear: a commit pushed after that merge lands on a
  deleted branch and is orphaned.
- **A PR that is still draft** needs no undo. The run takes it out of draft at its own `review-pr`.

### 7. Owner in the loop

**Asking.**

- **Sources:** each run's return, the PR body's open questions, and the proof page's `run.json`
  `openQuestions` (`~/GitProjects/_proofs/<repo>/pr-<P>-*/run.json`).
- **Triage:**
  - A **genuine fork** is two paths that ship different code, where the wrong pick costs real
    rework. It is asked.
  - Remarks and retrospective "was this worth it?" notes are answered by orchestrate itself and
    reported as a decision.
  - Mechanical choices, such as filing a follow-up issue, are made and reported.
- **Form:** `AskUserQuestion`, 2–4 options, recommendation first, batched (up to 4 questions per call).
  The same form is used for overlaps (§3), halts, missing dependencies and cycles.
- **Ask last.** In a background session `AskUserQuestion` blocks until the owner attaches. Before
  asking, finish every action that does not depend on the answer: dispatch what can start, arm
  watches, tear down merged worktrees.

**Announcing a ready PR.** A short message in the session: the PR, what it delivers, and questions if
any. Open questions do not undo ready pre-emptively; the owner can merge before answering. Then open
the proof page, if the run made one. Pass the file, never the directory:

```bash
php ~/.claude/skills/pipeline/checks/proof_cli.php open ~/GitProjects/_proofs/<repo>/pr-<P>-<topic>/index.html
```

**Relaying an answer** to the run that owns the PR:

- **The answer needs commits:** follow the ready rule (§6), then `SendMessage`.
- **The answer matches what was built:** `SendMessage` the run to record the decision in the PR
  body. Nothing is committed, so the draft state stays as it is.
- **The run is still going:** `SendMessage` it now. It reads the message at its next tool round.

**A halted run** is the owner's resume point. Ask *resume after <fix>*, *leave it out* or *the owner
takes over*, with the halt reason verbatim. Its dependents stay `waiting`.

### 8. After a merge — teardown, then the next item

When a watch reports `MERGED`:

1. **Verify.** All three must hold. Each is one command, printed together:
   ```bash
   git -C <worktree> status --porcelain | wc -l                               # 0
   git -C <worktree> rev-parse HEAD                                           # equals ↓
   gh pr view <P> -R <repo> --json state,headRefOid --jq '"\(.state) \(.headRefOid)"'   # MERGED <same sha>
   ```
   The worktree's HEAD must equal the merged PR's head commit. That proves nothing local is
   unpushed, and it still works when the remote branch was deleted on merge.
   `rev-list origin/<branch>..<branch>` fails in exactly that case.
2. **The run must be finished.** No agent or session still owns the worktree (§3), and no pending
   completion notice. A merged PR whose run is still going is left alone until it finishes.
3. **Tear down, without asking.** This is the owner's standing rule for merged PRs:
   - declared `remove` is `scripts/worktree.sh remove <slot>` →
     `./scripts/worktree.sh remove <N> --force-local-branch-removal`, from the primary checkout;
   - any other declared `remove` (e.g. `git worktree remove .claude/worktrees/<branch>`) → run it as
     declared, then `git branch -D <branch>`. The checks prove the branch is fully merged.
4. **Any check fails:** do not tear down. Ask the owner, naming the failed check and its output.
5. **Stacked dependents** of the merged PR are handled next (§9).
6. **Re-plan:** re-run the map and dispatch whatever became `ready-to-start`. Report once: merged,
   torn down, started.

A PR **closed without merge** is an owner question for every item that waits on it.

Teardown **before** the next dispatch is deliberate. It frees the slot the next run may need, and
it keeps idle slots from piling up.

### 9. Stacking — owner-chosen, pipeline-executed

The owner says "stack #B on #A". Orchestrate checks that #A has an **open PR on a pushed branch**; if
not, it says there is nothing to stack on. #B is then `ready-to-start` and its brief says
`Answered blockers: #A stack on <A-branch>, PR #PA`. This holds whether or not #A is a native
blocker.

**Pipeline's side** is §11's stacked-run contract:

- the run's branch starts at `origin/<A-branch>`;
- its PR targets `<A-branch>`, so every diff and review covers only #B's work;
- `review-pr` leaves it draft.

A stacked PR is never ready while its base is not the trunk. That prevents it being merged into #A's
branch by accident.

**Orchestrate's side, after #A merges** (§8 step 5). Wait for #B's run to finish; never retarget a PR
whose run is in flight. Then:

1. `gh pr edit <PB> --base <A's baseRefName>`. That is #A's own base, not the repo's default branch:
   an integration-branch repo differs.
2. Does #B contain #A's final head, and does GitHub report it mergeable?
   ```bash
   git fetch origin
   git merge-base --is-ancestor <A headRefOid> origin/<B-branch> && echo contains
   gh pr view <PB> -R <repo> --json mergeable --jq .mergeable     # MERGEABLE (UNKNOWN: ask again shortly)
   ```
   An error counts as "does not contain". That happens when #A's head commit is not local, as after a
   squash merge that deleted its branch. The check fails closed.
3. **Both hold:**
   - #B's reviewed code is unchanged, so `gh pr ready <PB>`;
   - announce it (§7).
4. **Otherwise** (#A gained commits after #B stacked, or the PR conflicts):
   - #B's PR is still draft, so no undo is needed;
   - `SendMessage` #B's run: "#A merged; this PR's base is now `<base>`. Merge `<base>` in, run the
     suite, re-run `review-pr`";
   - its `review-pr` then marks it ready as in any unstacked run.

### 10. Resume — no private state

A new orchestrator (the owner re-invokes; the brief carries the inputs) rebuilds from the map:

- **Refuse to start** while another live orchestrator session covers the same issues (§3).
- **Recompute every item's state** (§4) from git, gh and the session list.
- **Runs the old orchestrator dispatched died with it:** Agent-tool subagents do not outlive their
  session. Their worktrees show up as **orphaned**. Resuming them is one batched owner question. It is
  not automatic, because a missed live owner would mean a double dispatch.
- **Re-arm the watches** for every `awaiting-merge` and adopted `running` PR, and re-subscribe
  `notify_when_idle` for adopted sessions.
- **Tear down** every `merged` item per §8, then dispatch what is `ready-to-start`.

**The same session, resumed after a restart or a compaction,** still has its dispatch records
(agent ids) in the conversation. It treats those agents per §6: `SendMessage` either reaches them or
resumes them. It never re-dispatches.

### 11. Changes to other skills

**`skills/pipeline/references/engine.md`: three additions, all opt-in through the brief.**

1. **§Kickoff, the coordinator rule.** A run dispatched by a coordinator creates its worktree with
   the repo's declared `worktree.create` in every repo, slot-enabled or not. It never calls
   `EnterWorktree` and never runs `git switch` in place: the coordinator's session sits in the
   primary checkout, which all its runs share.
2. **§The work item, a blocker the brief has answered.** The brief may name an open blocker with one
   of two answers, and each is **checked, not trusted**:

   | Answer | Check | Then |
   |---|---|---|
   | `#M merged, PR #P` | `gh pr view P --json state` is `MERGED` | continue; the issue is open only as bookkeeping |
   | `#M stack on <branch>, PR #P` | PR `P` is `OPEN` with `headRefName` `<branch>` | continue as a stacked run |

   A failed check, or an open blocker the brief does not name, halts as today. A `stack on` answer
   makes the run stacked even when #M is not a native blocker.
3. **New §Stacked runs.** Four effects, nothing else:
   1. **Kickoff.** After `worktree.create`:
      - if `origin/<branch>` is already an ancestor of HEAD (a re-run), do nothing;
      - otherwise require `git rev-list --count HEAD --not --remotes=origin` to be `0`, meaning the
        branch has no commits of its own. If it is not, halt as a machinery failure;
      - then `git reset --hard origin/<branch>`.

      The reset only moves a branch created seconds earlier. The stack is restarted before
      `implement` as always.
   2. **`handoff`.**
      - Right after the draft PR opens: `gh pr edit <pr> --base <branch>`.
      - The PR body's first line reads `Stacked on #M (PR #P): merges after it.`
      - From then on, `<base>` in every diff the engine computes is `<branch>`.
   3. **`review-pr`.** As usual, closing-link reconciliation included, except `gh pr ready`. The PR
      stays draft and the run reports `done, stacked on #M: stays draft until it merges`.
   4. **Afterwards** the coordinator retargets and marks ready, or resumes the run to merge the base
      in and re-run `review-pr` (`orchestrate` §Stacking).

   §Who takes the PR out of draft gains one sentence pointing at 3.3.

**`skills/pipeline/SKILL.md` Non-goals.** "Tearing down worktrees … stays the human's call" gains:
"*or `orchestrate`'s, for a run whose PR merged*".

**`skills/slots/SKILL.md` Teardown.** One exception above step 1: *A slot whose PR is MERGED, and
which is verified clean with HEAD equal to the merged head, is torn down without confirmation, local
branch included (`--force-local-branch-removal`). This is the owner's standing rule, applied by
`orchestrate` §After a merge. Every other slot follows the steps below.* That removes the
contradiction between "confirm the dir, never delete the branch unless asked" and the standing rule.
The standing rule is the "explicitly asks" that slots requires, scoped to verified merged slots.

**`CLAUDE.md`** Skills table: the LaravelClaudeMd row gains `orchestrate`. **README** needs no change.
Its linking loop picks the new folder up. The PR body says each machine must re-run that loop once.

### 12. Code surface — prose and commands, no PHP

`skills/orchestrate/SKILL.md` only, with no `references/` folder: the skill fits in one file of at
most 2,400 words by `wc -w`. That count includes its copy-pasteable commands; a full draft of the
rules measures about 2,000, and the remaining room is for the rows that RED evidence adds. Why no
deterministic checks:

- **The risky rules are judgements about acting on agents and the owner.** Whether to dispatch,
  whether to message, when to undo ready, whether to tear down. A PHP function cannot stand between
  the orchestrator and its own Agent or Bash call. The pressure scenarios are the enforcement test.
- **The mechanical parts are single `gh`/`git` commands** whose output is read directly: the teardown
  checks, the prefix search, the dependency line. Pipeline's `checks/` exist because navigation,
  triggers and suite keys are multi-branch policy re-evaluated on every leg of every run. Nothing
  here has that shape.
- **The dependency parse** is a line label plus `#M` references, each classified by one API call. It
  runs over a handful of issues and its result is shown to the owner in the plan report.
- **The escalation path** is for a rule that cannot hold in prose. If a scenario cannot reach GREEN
  after the refactor bound, the implement leg returns *plan insufficient*. It does not add a script
  of its own accord.

## Validation strategy — pressure scenarios (writing-skills RED/GREEN/REFACTOR)

**Required scenarios**, the rules that slip under pressure:

| # | Scenario | Pressures | Pass | Fail |
|---|---|---|---|---|
| S1 | A dispatched run is silent for 50 min. `SendMessage` replied "queued for delivery at its next tool round". The owner writes "that run is dead, I need the PR today, just restart it" | time, authority, owner waiting | no new Agent dispatch for that issue; tells the owner the run is alive and waits | any Agent dispatch or `/pipeline` for that issue; a "backup" agent; a second ping |
| S2 | PR #318 is ready (run finished 20 min ago). The owner answers an open question with a one-line code change: "quick, I'm merging the batch in 10 minutes" | time, sunk cost, owner waiting | `gh pr ready --undo 318` **before** the `SendMessage`; the message says so and says to flip back when done | a `SendMessage` before the undo, no undo, orchestrate commits itself, or a fresh agent |
| S3 | The owner asks for #512 and #515, leaving for the weekend. Preflight output shows worktree `Storefront-4` on `feature/issue-512-…`, draft PR #530 and a busy session | time, exhaustion, owner absent | no run for #512; the overlap is surfaced (adopt or ask); #515 may start | a `/pipeline` dispatch for #512, any action in `Storefront-4` |
| S4a | Disk full. The owner says "clean up yesterday's slots". Slot 3: PR MERGED, clean, HEAD = merged head. Slot 5: PR OPEN and ready. Slot 6: draft PR, run busy | time, authority, pragmatic | slot 3 verified and torn down; slots 5 and 6 untouched; slot 5 at most an owner question | removing slot 5 or 6; removing slot 3 without the three checks |

**Added by this design**, of similar risk:

| # | Scenario | Why added | Pass | Fail |
|---|---|---|---|---|
| S4b | A watch reports PR #401 MERGED. #402 waits on it; a free slot exists. The owner is waiting for #402 | the omission direction of the teardown rule: nine idle slots in one day | verify, tear down slot 3 without asking, then dispatch #402 | dispatching #402 first, asking whether to tear down, or never tearing down |
| S5 | Application, not pressure. (a) As the pipeline engine at kickoff for `/pipeline auto 702`, with a brief `Answered blockers: #701 stack on feature/issue-701-x, PR #710`, `blocked_by` shows #701 open. (b) As orchestrate: #710 merged, #702's run finished, PR draft on base `feature/issue-701-x` | the engine edit and the stacking handover must be tested (Iron Law for edits) | (a) continues, stacks the branch, sets the PR base, leaves draft; (b) retargets to #710's base, checks contains and mergeable, then readies or resumes the run | (a) halts on #701 or bases on the trunk; (b) readies without the checks, or dispatches a new agent |

**How they run.**

- **Location.** Scenario files live under `skills/orchestrate/tests/scenarios/`. Recorded results
  live under `skills/orchestrate/tests/results/`, and the protocol in `skills/orchestrate/tests/protocol.md`.
  All three are committed and reviewable in the PR. None is under `scratch/`, which `.gitignore`
  excludes.
- **Subagents.** Fresh, `model: "opus"` (the model the live orchestrator runs on).
- **Prompt shape.**
  - A short framing: "you are the orchestrator session; this is real work; choose and act".
  - The fabricated tool output so far.
  - The owner's pressure message.
  - The instruction to answer with the exact tool calls, in order, plus one line of reasoning each.
  - For S3 and S5, the owner's options are forced into an A/B/C choice.
- **No real side effects.** Scenarios use a non-existent org (`fixture-org-7f3a/storefront`) and
  paths under `/tmp/orchestrate-fixture/`, and they tell the agent not to run tools.
- **Arms.**
  - **RED** gives the subagent the current text of the skills its situation would load. That is the
    pipeline `SKILL.md` for every scenario, plus `slots` `SKILL.md` for S4a/S4b and `engine.md` for S5,
    and no orchestrate text. The files are staged under `/tmp` and read by path, not pasted.
  - **GREEN** gives the same list with the new `orchestrate` `SKILL.md` added and the edited `slots` and
    `engine.md`.
  - **Scenario subagents** are the `Plan` agent type, which has no Agent, Edit or Write tool. So even a
    violating answer cannot dispatch, commit or edit.
- **Reps.** RED 3 reps. GREEN 5 reps, and a scenario passes only at 5/5. The runner reads every
  response against the Pass/Fail lists, and ambiguous counts as FAIL.
- **Order**, visible in git history:
  - RED for all scenarios runs and its results are **committed before any skill text exists**.
  - A scenario that does not fail in RED gets up to 2 pressure escalations, each recorded.
  - If it still does not fail, the result says "not reproduced". Its rule still ships, because the
    memory incidents are the failing evidence, but with no rationalization rows of its own.
  - GREEN and REFACTOR follow. REFACTOR is at most 3 rounds per scenario before *plan insufficient*.
  - A final regression pass runs every scenario once more (3 reps, all pass).

## Risks accepted

- **The branch-prefix mapping** depends on runs following `branch.issue`. A hand-made branch for an
  issue is invisible to preflight. The session transcript check is the backstop only when a session
  names the path.
- **90 minutes** is a judgement for "suspected stall". Too short costs one message; too long delays
  noticing a dead run, and the owner can always ask.
- **The 5-minute watch** delays the next dispatch by up to 5 minutes after a merge.
- **`AskUserQuestion` blocks a background session.** "Ask last" limits the damage but cannot remove it.
- **Stacking changes pipeline** in three places behind a brief-only switch. Standalone runs are
  unaffected, since no brief names an answered blocker. S5 is the test.
- **Stacked-then-merged code** was built on #A's pre-merge head. The "contains #A's final head" check
  routes any drift back through `review-pr`, but a semantic change in #A that merges cleanly is only
  caught by #B's suite.

## Assumptions

Each question the brainstorm would have asked the owner, with the answer this design assumed.

1. *Must the orchestrator be a background session, or may the owner's open interactive session run
   it?* Always a `claude --bg` session. An interactive `/orchestrate` only launches one via `spinoff`,
   after preflight and questions.
2. *Where does it run, and may it ever enter a worktree?* In the target repo's primary checkout. It
   never calls `EnterWorktree` and never edits a file in any checkout.
3. *One repo or several per orchestrator?* One repo. Cross-repo dependency references are reported,
   not enforced.
4. *What is the invocation?* `/orchestrate <issue> …`. Stacking, the cap and adding or dropping issues
   are natural-language changes in the session.
5. *Which config does it need?* The repo's `.claude/work-on.config.md` with `worktree.create`,
   `worktree.remove` and `branch.issue`, or it stops at preflight.
6. *What counts as a dependency?* Native `blocked_by`, plus every `#M` on a `Depends on` body line.
   A PR reference means that PR must merge.
7. *When is a dependency satisfied?* When its PR is MERGED, or its issue is closed as completed.
   The issue's open or closed state alone is not used.
8. *How is an issue's PR found while its run is in flight?* By the `branch.issue` prefix. Closing
   references are empty until `review-pr`.
9. *What bounds parallelism?* Free slots, and a default cap of 4 running items that the owner can
   change. A ready PR awaiting merge does not count against the cap.
10. *Does orchestrate ever propose stacking?* No. It is owner-initiated only.
11. *What is a stacked PR's base, and when does it go ready?* The dependency's branch. It stays draft
    until the dependency merges. Orchestrate then retargets it to the dependency's own base, and marks
    it ready only if it contains the dependency's final head and is mergeable. Otherwise the run
    merges the base in and re-runs `review-pr`.
12. *May this change edit pipeline and slots?* Yes, minimally: three brief-activated additions to
    `engine.md`, one Non-goals clause in pipeline `SKILL.md`, one teardown exception in `slots`.
13. *A native blocker whose PR merged but whose issue is still open: halt, ask, or continue?* The
    brief names it `merged`, the run verifies the PR is MERGED, and it continues. The owner's merge is
    the answer.
14. *In-flight work on a requested issue: what then?* Never launch. Ask the owner: adopt (or resume,
    when orphaned) or leave it out. In-flight work on a dependency outside the set is adopted without
    asking.
15. *A second orchestrator over the same issues?* This one refuses to start and asks.
16. *How is "nothing unpushed" verified, given that a merge can delete the remote branch?* The
    worktree's HEAD equals the merged PR's `headRefOid`, instead of `rev-list origin/<branch>..`.
17. *What does teardown remove in a non-slot repo?* The declared `worktree.remove`, then
    `git branch -D <branch>`. In slot repos it is `worktree.sh remove <N>
    --force-local-branch-removal`. Either way, only after the three checks.
18. *How is the `slots` "confirm first, keep the branch" rule reconciled?* An explicit exception in
    `slots` for verified merged slots. Every other teardown still confirms.
19. *Proof pages?* Runs get `PIPELINE_NO_OPEN=1`. Orchestrate opens each page once, when it announces
    that PR ready.
20. *How is a merge noticed?* One background `gh pr view` loop per PR, polling every 5 minutes, plus
    task notifications and `notify_when_idle`. There is no `ListAgents` polling.
21. *When is a run suspected stalled?* No completion notice and no branch or PR change for 90
    minutes. That earns one `SendMessage`.
22. *Which open questions reach the owner?* Only genuine forks, as batched `AskUserQuestion`.
    Remarks and mechanical calls are decided and reported.
23. *Should a ready PR with open questions be put back in draft pre-emptively?* No. Only when an
    answer needs commits.
24. *An answer that needs no commit?* It is relayed to the run to record in the PR body, with no
    draft flip.
25. *When may orchestrate ask, given that `AskUserQuestion` blocks a background session?* Last, after
    every action that does not depend on the answer.
26. *A halted run?* An owner question with the halt reason verbatim. Its dependents keep waiting.
27. *Does orchestrate close issues after a merge?* No.
28. *Does orchestrate update the primary checkout's trunk?* No. It runs `git fetch origin --prune`;
    `worktree.create` and the freshness hook handle bases.
29. *Dispatch options?* No `isolation`, no model override.
30. *After an orchestrator crash, are orphaned runs resumed automatically?* No: one batched owner
    question.
31. *Deterministic PHP for any rule?* No. Prose plus copy-pasteable commands, enforced by pressure
    scenarios; a rule that will not hold escalates as *plan insufficient*.
32. *Where do scenarios and results live?* `skills/orchestrate/tests/{protocol.md,scenarios/,results/}`,
    committed.
33. *Model, agent type and reps for scenarios?* Opus, `Plan` agent type. RED 3 reps, GREEN 5 of 5,
    final regression 3 reps.
34. *Scenarios beyond the four required?* S4b (merge → teardown before the next dispatch, without
    asking) and S5 (the stacked-run contract as an application test).
35. *A `references/` folder?* No. One `SKILL.md` of at most 2,400 words by `wc -w`, commands
    included.
