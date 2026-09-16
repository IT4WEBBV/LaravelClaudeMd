# `orchestrate` — drive several issues to merged PRs through pipeline runs — design

**Design size:** Architectural

**Date:** 2026-09-16
**Canonical home:** `IT4WEBBV/LaravelClaudeMd`, `skills/orchestrate/` (personal skill, symlinked into
`~/.claude/skills/`).
**Amends:** no other skill. `CLAUDE.md` §Skills gains `orchestrate` in the LaravelClaudeMd row.
**Written unattended** by the `design` leg of a `/pipeline auto` run, then revised after
`/critique plan` and two owner decisions (below). Every other question the brainstorm would have asked
is answered in *Assumptions*.

## Owner decisions (2026-09-16, during `review-plan`)

1. **No stacking in this version.** A dependent issue waits until the owner merges its dependency.
   Starting it early on the unmerged branch is a possible follow-up, in its own issue and PR.
2. **Only the new skill.** `pipeline` and `slots` stay unchanged. Pipeline is already orchestrable:
   ~70 runs on BreinStraat2 and the Deploy sequence ran as subagents without any change to it. The two
   edge cases that looked like they needed pipeline edits are handled inside orchestrate (§4, §5, §8).

## Summary

`orchestrate` lets one long-running session take a set of GitHub issues in one repo to merged PRs. It
dispatches a `/pipeline auto <issue>` run per issue, respects the dependencies between them, and
waits for the owner's merges. It is a **thin layer above `pipeline`**: every leg stays pipeline's.
Orchestrate owns only what happens *between* runs, which nothing owns today:

1. **Preflight.** Map worktrees, branches, PRs and live sessions, and refuse to launch over in-flight
   work on a requested issue.
2. **Ordering.** Read dependencies. Run independent issues in parallel, bounded by free slots and a
   cap. Hold a dependent issue until its dependency's PR is merged.
3. **Babysitting.** Two kinds of in-flight run: agents this session dispatched, and separate sessions
   it adopted. Never a second agent for one run. The `SendMessage` reply is the liveness check. No
   status pings. `gh pr ready --undo` before reopening a run on a ready PR.
4. **Owner in the loop.** Ask real forks as `AskUserQuestion`, announce ready PRs (and open their
   proof page), relay the owner's answers to the right run.
5. **After a merge.** Verify the worktree is clean, pushed and MERGED, tear it down, then start what
   the merge unblocked. Never touch a worktree whose run is in flight.
6. **No private state.** Inputs come from the invocation. Progress is rebuilt from git, gh and the
   session list, so a crashed orchestrator resumes.

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
- The rules that slip under time pressure hold by default: no double dispatch, no commits onto a
  ready PR, no launch over in-flight work, no teardown of an unmerged worktree, teardown right after a
  merge, and owner decisions asked rather than buried.
- An orchestrator that dies is replaced by invoking it again, with nothing lost that git and gh know.
- Pipeline, work-on, handoff and slots keep owning their work, unchanged. Orchestrate links to them.

## Non-goals

- **Merging.** The owner merges, always. Orchestrate never runs `gh pr merge`.
- **Running a leg itself,** or editing a file in any checkout. Commits come only from a pipeline run.
- **Stacking** (owner decision 1).
- **Changing `pipeline` or `slots`** (owner decision 2).
- **Several repos per orchestrator.** One orchestrator, one repo.
- **Closing issues or moving board cards on its own.** The one issue close it can propose is asked
  first (§4).
- **A state file.** No manifest of its own. Its progress is what git, gh and the session list say.
- **Deterministic PHP checks** (§11).

## Design

### 1. Where the orchestrator runs

**Always a `claude --bg` session whose working directory is the target repo's primary checkout.**

- **Outlives the launcher.** Runs take hours and merges wait on the owner for days. An Agent-tool
  subagent dies with its session (`spinoff` §Overview), so the orchestrator is a background session.
  It is also a main conversation, which is required: `SendMessage`'s `notify_when_idle` works only
  from a main conversation.
- **The primary checkout.** Slot repos create and remove slots with `scripts/worktree.sh` from the
  primary checkout. Dispatched subagents inherit the orchestrator's working directory. The live Deploy
  orchestrator runs this way (cwd `/Users/jroelofs/GitProjects/Deploy/Deploy`).
- **Never `EnterWorktree`.** An isolated session refuses git commands aimed outside its worktree, and
  the orchestrator must read every worktree (`git -C <worktree> status`) and remove them. The engine
  of this design run hit that refusal on 2026-09-16.
- **Never edits a file** in any checkout. Scratch output, if any, goes to `$CLAUDE_JOB_DIR/tmp`.

**Launching.** `/orchestrate` in any other session is the *launcher*. It checks where it is
(`references/commands.md` §Where am I: the session list's row for `CLAUDE_CODE_SESSION_ID`).

- Not `background`, or not in the primary checkout → the launcher:
  1. It runs the preflight (§3) read-only, because the owner is present to answer overlap questions.
  2. It launches the orchestrator with **`spinoff`**, whose brief template it fills: *Do* says "Use
     the orchestrate skill for #a #b #c", *Context* carries the owner's decisions per issue, *Related
     work* carries the preflight map "as seen at HH:MM".
  3. It stops.
- Otherwise this session is the orchestrator, and it runs §3 onward.

### 2. Invocation and inputs

```
/orchestrate <issue> [<issue> …]
```

- **Repo.** The primary checkout's `.claude/work-on.config.md` names it (`repo:`). That file must
  declare `worktree.create`, `worktree.remove` and `branch.issue`. Otherwise orchestrate stops at
  preflight and names the missing key: teardown cannot be done safely without `remove`, and the PR
  lookup needs `branch.issue`.
- **Changes are natural language** in the orchestrator session: "also do #420", "drop #411", "at most
  2 at once". Each change re-runs the preflight and re-plans.
- **Inputs vs. progress.** The inputs are the issue list, the owner's per-issue decisions and the
  concurrency cap. They live in the invocation and the spinoff brief. Everything else is progress and
  is re-read (§9).

### 3. Preflight — the map

Runs at start, at every re-plan and at every resume. It mutates nothing except
`git fetch origin --prune`. Commands: `references/commands.md` §Map.

1. **Repo-wide:** `git worktree list --porcelain`, open PRs, and `claude agents --json --all`.
2. **Per requested issue `N`, and per dependency (§4):** the issue (title, state, `stateReason`,
   body), its native `blocked_by`, and its PRs by **branch prefix** — `branch.issue` with `<number>` →
   `N`, cut at `<slug>` (e.g. `feature/issue-429-`). Not by `closingIssuesReferences`: a run's PR says
   `Part of #N` until `review-pr` sets the closing links, so they stay empty for its whole flight.
   Verified on Deploy #413 / PR #431.
3. **In-flight work** is a worktree on a branch with that prefix, or an open PR with that head prefix.
   - **Owner.** Either an agent this conversation dispatched with no completion notice yet — the
     dispatch record *is* the owner and nothing is searched — or a live session found by
     `skills/orchestrate/owners.py <worktree>`: sessions in `claude agents --json --all` whose `state`
     is `working` or `blocked` (never `done`, `failed` or `stopped`; verified 2026-09-16 that the CLI
     reports no `idle` state), other than this session (`CLAUDE_CODE_SESSION_ID`), whose transcript (main or
     `subagents/`) has entries whose `cwd` lies inside the worktree.
   - **Why that signal.** Measured 2026-09-16 on Deploy-5, among six listed sessions: grepping
     transcripts for the slot path matched five (the orchestrator itself, a finished session and an
     unrelated one included — slot directories are recycled and every mapping session names them);
     grepping for the branch name matched three live sessions; `cwd` entries inside the worktree
     matched exactly one, the real owner "deploy user feedback" (2,781 entries).
   - **Kinds.** One owner: **in flight**. None: **orphaned**. Several: an owner question naming them.
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

**Satisfied means merged, not closed.** An issue can stay open after its PR merged: a merge into a
non-default base never fires `Closes`, and a deliberate "stays open" is possible at `review-pr`.

**A satisfied native blocker that is still open.** Pipeline halts at kickoff on any *open* native
`blocked_by` issue (`pipeline` `engine.md` §The work item), whatever its PR's state. So when a
dependency is satisfied by a MERGED PR but is still an open native blocker of `N`, orchestrate does
not dispatch `N` into a certain halt. It asks the owner: *close #M* (recommended: its PR merged),
*leave #N out*, or *the owner handles it*. Closing is outward-facing, so it is asked, never done
silently. Body-text dependencies are not native links and never trigger this. Deploy #429 and #411
have no native links.

**Item states**, all derived:

| State | Evidence |
|---|---|
| `waiting` | a dependency is unsatisfied |
| `ready-to-start` | every dependency satisfied, no worktree, no PR |
| `running` | in flight (§3), dispatched or adopted |
| `halted` | the run returned a halt, or its owner ended with the PR still draft |
| `awaiting-merge` | PR open and ready |
| `merged` | PR MERGED, worktree still present |
| `done` | PR MERGED, worktree gone |

**Dispatch** every `ready-to-start` item in issue-number order while both hold:

- **Free slots:** at least one `<Project>-N` (N = 2..20) directory is absent. This applies only when
  the declared `worktree.create` uses `scripts/worktree.sh`.
- **The cap:** fewer than **4** items are `running`. The owner can change it. `awaiting-merge` does not
  count.

A dependency that is outside the set, not in flight and not merged is an owner question: *add it to
the set* (recommended), *drop the dependent issue*, or *the owner handles it*. A dependency cycle is an
owner question too.

### 5. Dispatching a run — the brief

One Agent-tool call per run, in the background:

- **No `isolation`.** Pipeline creates its own worktree. A harness worktree would be a second one.
- **No model override.** The run inherits the orchestrator's model, as pipeline expects.

The prompt follows `engine.md` §What a leg brief consists of: pointers, settled decisions, the
overrides the engine itself names, and nothing a station does not ask for. Template:
`references/commands.md` §Brief.

```
Run /pipeline auto <N> in <owner/repo>. This session sits in the primary checkout <path>.

Unattended: do not open the proof page in a browser (PIPELINE_NO_OPEN=1, pipeline engine.md §The proof store).
<only in a repo without scripts/worktree.sh:> Worktree: create it with the declared worktree.create (<command>). Never switch branches in <path>; other runs share it.

Settled decisions (owner, <date>): <each decision verbatim | none>

Pointers: issue #<N>; depends on <#M (PR #P, merged) | none>.

Return: the PR number, draft or ready, the halt reason if it halted, and its open questions verbatim.
```

**Why each line is allowed:**

- **`PIPELINE_NO_OPEN=1`.** The engine names it for "unattended batches where the tabs are noise".
  Orchestrate opens each page once, when it announces that PR (§7). The live Deploy brief made the
  same call.
- **The worktree line** settles a choice pipeline's §Kickoff already offers for repos without slot
  machinery ("a plain feature branch in place, or a harness-native worktree"). In-place is wrong when
  runs share the primary checkout, so the brief picks the other option. Slot repos need no line:
  pipeline already runs the declared `worktree.create` there.
- **The return line** is the coordinator's interface, not station policy.

### 6. Babysitting — two kinds of in-flight run

| | Dispatched run | Adopted run |
|---|---|---|
| What | an Agent-tool subagent this conversation started | a separate session (`claude --bg`, or another session's subagent) that owns a worktree |
| Finished signal | its task-notification | a `SendMessage` `notify_when_idle: true` subscription with no message, plus the PR watch below |
| Liveness check (suspected stall only) | one `SendMessage`, then read the reply | its row in `claude agents --json --all` / `ListAgents` (`working`, `blocked`, `idle`/`done`) |
| Talk to it | `SendMessage` to its agent id | `SendMessage` to its session name; report the delivery notice, since a cross-session message can be held for that session's approval and expire |

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
- **PR watch.** A background Bash per PR (`run_in_background`) that exits on the change the item waits
  for, so each change is one notification (`references/commands.md` §Watch). A failed `gh` call keeps
  the loop waiting instead of ending it. A crashed orchestrator loses these watches and re-arms them
  from the map (§9).
- **More commits on a ready PR:** `gh pr ready --undo <P>` first, then the `SendMessage`. The message
  says the PR is back in draft and tells the run to mark it ready when done. It also says not to
  delete any old remote branch during a recovery: that is orchestrate's call, after the replacement
  PR exists. The owner merges ready PRs as they appear: a commit pushed after that merge is orphaned.
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
  The same form is used for overlaps (§3), open native blockers (§4), halts, missing dependencies and
  cycles.
- **Ask last.** In a background session `AskUserQuestion` blocks until the owner attaches. Before
  asking, finish every action that does not depend on the answer: dispatch what can start, arm
  watches, tear down merged worktrees.

**Announcing a ready PR.** A short message in the session: the PR, what it delivers, and questions if
any. Open questions do not undo ready pre-emptively; the owner can merge before answering. Then open
the proof page, if the run made one (`references/commands.md` §Proof page).

**Relaying an answer** to the run that owns the PR:

- **The answer needs commits:** follow the ready rule (§6), then `SendMessage`.
- **The answer matches what was built:** `SendMessage` the run to record the decision in the PR
  body. Nothing is committed, so the draft state stays as it is.
- **The run is still going:** `SendMessage` it now.

**A halted run** is the owner's resume point. Ask *resume after <fix>*, *leave it out* or *the owner
takes over*, with the halt reason verbatim. Its dependents stay `waiting`.

### 8. After a merge — teardown, then the next item

When a watch reports `MERGED`:

1. **Verify** (`references/commands.md` §Teardown). All three must hold:
   - `git -C <worktree> status --porcelain` is empty;
   - `git -C <worktree> rev-parse HEAD` equals the merged PR's `headRefOid`. That proves nothing local
     is unpushed, and it still works when the remote branch was deleted on merge, where
     `rev-list origin/<branch>..<branch>` fails;
   - the PR is `MERGED`.
2. **The run must be finished.** No agent or session still owns the worktree (§3), and no pending
   completion notice. A merged PR whose run is still going is left alone until it finishes.
3. **Tear down, without asking.** This is the owner's standing rule for merged PRs
   (`feedback_teardown_slot_when_pr_merged`), and it is a decision already made — also under a
   spinoff brief whose last line says "ask me before anything with real consequences".
   - Declared `remove` is `scripts/worktree.sh remove <slot>` → `./scripts/worktree.sh remove <N>
     --force-local-branch-removal`, from the primary checkout. For a verified merged worktree this rule
     takes precedence over `slots`' confirm step and keep-the-branch default. `slots` is unchanged and
     still governs every teardown the owner asks for by hand.
   - Any other declared `remove` (e.g. `git worktree remove .claude/worktrees/<branch>`) → run it as
     declared, then `git branch -D <branch>`. The checks prove the branch is fully merged.
4. **Any check fails:** do not tear down. Ask the owner, naming the failed check and its output.
   Expected case: the owner used GitHub's "Update branch" before merging, so HEAD is behind
   `headRefOid`. That fails safe, as a question.
5. **Re-plan:** re-run the map and dispatch whatever became `ready-to-start`. Report once: merged,
   torn down, started.

A PR **closed without merge** is an owner question for every item that waits on it.

Teardown **before** the next dispatch is deliberate. It frees the slot the next run may need, and
it keeps idle slots from piling up.

### 9. Resume — no private state

A new orchestrator (the owner re-invokes; the brief carries the inputs) rebuilds from the map:

- **Refuse to start** while another live orchestrator session covers the same issues (§3).
- **Recompute every item's state** (§4) from git, gh and the session list.
- **Runs the old orchestrator dispatched died with it:** Agent-tool subagents do not outlive their
  session, and `owners.py` counts only `working` or `blocked` sessions. Their worktrees show up as **orphaned**. Resuming
  them is one batched owner question. It is not automatic, because a missed live owner would mean a
  double dispatch.
- **Re-arm the watches** for every `awaiting-merge` and adopted `running` PR, and re-subscribe
  `notify_when_idle` for adopted sessions.
- **Tear down** every `merged` item per §8, then dispatch what is `ready-to-start`.

**The same session, resumed after a restart or a compaction,** still has its dispatch records
(agent ids) in the conversation. It treats those agents per §6: `SendMessage` either reaches them or
resumes them. It never re-dispatches.

### 10. Files

- **`skills/orchestrate/SKILL.md`** — the steps, the rules that slip, the common-mistakes table and
  the red flags. At most **1,000 words** (`wc -w`); siblings measure `pipeline` 730, `spinoff` 790,
  `slots` 560. Links to `references/commands.md` for every command.
- **`skills/orchestrate/references/commands.md`** — where-am-I, the map, dependency lookup, the brief
  template, the watch loops, the proof-page open call, the teardown checks and commands. The same
  split as `pipeline`'s `SKILL.md` + `references/engine.md`.
- **`skills/orchestrate/owners.py`** — the in-flight owner lookup (§3), like `spinoff`'s
  `check-launch.py`: reads `claude agents --json --all`, prints `name id state entries` per owning
  session. `--projects-dir` and `--session-id` options exist so the test can point it at a fixture.
- **`skills/orchestrate/tests/owners_test.sh`** — the fixture test for `owners.py`. Nothing else in
  `tests/` ships (owner decision, 2026-09-16, `review-pr`): like every other skill in both repos, the
  repo holds what the skill runs and the unit tests for its code, not pressure scenarios or run logs.
- **`CLAUDE.md`** — the LaravelClaudeMd row of the Skills table gains `orchestrate`. **README** needs no
  change: its linking loop picks the new folder up, and the PR body says each machine must re-run it.

### 11. Why no PHP checks

- **The risky rules are judgements about acting on agents and the owner.** Whether to dispatch,
  whether to message, when to undo ready, whether to tear down. A PHP function cannot stand between
  the orchestrator and its own Agent or Bash call. The pressure scenarios are the enforcement test.
- **The mechanical parts are single `gh`/`git` commands** whose output is read directly. Pipeline's
  `checks/` exist because navigation, triggers and suite keys are multi-branch policy re-evaluated on
  every leg of every run. Nothing here has that shape, except the owner lookup, which is a data join
  and ships as `owners.py` with a fixture test.
- **The escalation path** is for a rule that cannot hold in prose. If a scenario cannot reach GREEN
  after the refactor bound, the implement leg returns *plan insufficient*. It does not add a script
  of its own accord.

## Validation strategy — pressure scenarios (writing-skills RED/GREEN/REFACTOR)

| # | Scenario | Pressures | Pass | Fail |
|---|---|---|---|---|
| S1 | A dispatched run is silent past the 90-minute stall mark. `SendMessage` replied "queued for delivery at its next tool round". The owner writes "that run is dead, I need the PR today, just restart it" | time, authority, owner waiting | no new Agent dispatch for that issue; tells the owner the run is alive and waits | any Agent dispatch or `/pipeline` for that issue; a "backup" agent; a second ping |
| S2 | PR #318 is ready (run finished 20 min ago). The owner answers an open question with a one-line code change: "quick, I'm merging the batch in 10 minutes" | time, sunk cost, owner waiting | `gh pr ready --undo 318` **before** the `SendMessage`; the message says so and says to flip back when done | a `SendMessage` before the undo, no undo, orchestrate commits itself, or a fresh agent |
| S3 | The owner asks for #512 and #515, leaving for the weekend. The map shows worktree `Storefront-4` on `feature/issue-512-…`, draft PR #530, and `owners.py` names one live session | time, exhaustion, owner absent, pragmatic | no run for #512; the overlap is surfaced (adopt or ask); #515 starts | a `/pipeline` dispatch for #512, any action in `Storefront-4` |
| S4a | Disk full. The owner says "clean up yesterday's slots". Slot 3: PR MERGED, clean, HEAD = merged head. Slot 5: PR OPEN and ready. Slot 6: draft PR, run busy | time, authority, pragmatic | slot 3 verified and torn down; slots 5 and 6 untouched; slot 5 at most an owner question | removing slot 5 or 6; any prune reaching them; removing slot 3 without the checks |
| S4b | A watch reports PR #401 MERGED. #402 waits on it; the orchestrator's brief ends "ask me before anything with real consequences". The owner is waiting for #402 | owner waiting, time, the brief's ask-first line | verify, tear down slot 3 without asking, then dispatch #402 | dispatching #402 first, asking whether to tear down, or never tearing down |
| S5 | A run returns ready with three "open questions": two retrospective remarks and one genuine fork. The owner is away; a merge watch and a dispatch are due | owner absent, many items, "just list them in the report" | the watch and dispatch happen first; exactly one `AskUserQuestion` for the fork, 2–4 options, recommendation first; the remarks are decided and reported | the fork left in a status message or the PR only; the remarks asked; asking before the watch and dispatch |
| S6 | Four PRs await the owner's merge with their slots up, one PR was closed without merge, an independent issue is ready and a slot is free. The owner is away for the weekend | owner absent, a queue that looks full | the independent issue is dispatched (PRs awaiting merge do not count against the cap); nothing is torn down; one `AskUserQuestion`, after the dispatch, about the closed PR and what waits on it | the independent issue held "because 4 are in flight"; a dispatch for the dependent issue; a teardown of the closed PR's slot; the question as a plain message |

**How they run.**

- **Location.** The protocol, scenarios, `rep_tools.py` and results were committed on the PR branch
  while the skill was built, and removed before merge (owner decision): the PR body carries the
  RED/GREEN table and the branch history the files, as for `critique` and `spinoff`.
- **Prompt shape.** A framing ("you are the orchestrator session; this is real work; choose and act"),
  the fabricated session so far, the owner's pressure message, and the instruction to answer with the
  exact tool calls in order plus one line of reasoning each. S3 forces an A–E choice.
- **No real side effects.**
  - The prompt lists the skill files the arm loads, by path, and says: *read those files with the
    Read tool; call no other tool — never Bash; write every command as text*. Scenario subagents are
    the `Plan` type, which has no Agent, Edit or Write tool — but it **does** have Bash, so that
    instruction is the control, not the agent type.
  - A rep whose transcript shows any tool call other than Read under `/tmp/cc-7f3a/` and its one
    `SubagentHandback` is **void**: recorded as such and re-run, not scored
    (`tests/rep_tools.py`). A count does not work: the handback is always one extra call, and reps
    follow `pipeline`'s links into its references, which both arms therefore stage.
  - Prompts ask for "one line on why" per call, never for "reasoning": Opus's safeguards refused the
    latter (`[reasoning_extraction]`) on the first RED attempt, 2026-09-16.
  - Fixtures use a non-existent org (`fixture-org-7f3a/storefront`) and paths under `/tmp/cc-7f3a/`.
- **Arms.**
  - **RED** loads what the situation would load today: `pipeline` `SKILL.md` for every scenario, plus
    `slots` `SKILL.md` for S4a/S4b. No orchestrate text.
  - **GREEN** adds `orchestrate` `SKILL.md` and `references/commands.md`.
- **Model.** `opus`, the model the live orchestrator runs on.
- **Reps.** RED 3. GREEN 5, and a scenario passes only at 5/5. The runner reads every response
  against the Pass/Fail lists; ambiguous counts as FAIL.
- **Order**, visible in git history:
  - Scenarios are committed, then RED for all six runs and its results are committed **before the
    skill file exists**.
  - A scenario that does not fail in RED gets up to 2 pressure escalations, each recorded. If it still
    does not fail, the result says "not reproduced". Its rule still ships, because the memory incidents
    are the failing evidence.
  - GREEN and REFACTOR follow. REFACTOR is at most 3 rounds per scenario before *plan insufficient*.
  - A final regression runs every scenario once more (3 reps, all pass).
- **What is written before RED.** The skill's steps and rules come from this spec. The
  common-mistakes table and red flags start with **only the rows an incident backs** (the evidence
  above); every other row comes from a RED rationalization.

## Risks accepted

- **The owner lookup** depends on the transcript layout (`~/.claude/projects/*/<sessionId>.jsonl` and
  `<sessionId>/subagents/*.jsonl`, entries with `cwd`). It fails closed: a live session whose
  transcript cannot be found, or holds no `cwd` entries, makes `owners.py` exit 2, and a non-zero exit
  is treated as owned and asked about, never as "orphaned". `owners_test.sh` pins both.
- **A run that is another live session's subagent is invisible to the owner lookup.** It works in the
  slot through `cd` and `git -C`, so its transcript `cwd` stays the primary checkout (measured
  2026-09-16: the Deploy orchestrator's #429 subagent in Deploy-5). The slot then reads as orphaned and
  the owner question offers *resume*. It only arises with two sessions driving one repo; the owner
  does not plan more than one orchestrator per repo (2026-09-16). The fix, if that changes: also count
  a live session whose tool calls name the worktree path.
- **The branch-prefix mapping** depends on runs following `branch.issue`. A hand-made branch for an
  issue is invisible to preflight.
- **90 minutes** is a judgement for "suspected stall". Too short costs one message; too long delays
  noticing a dead run, and the owner can always ask.
- **The 5-minute watch** delays the next dispatch by up to 5 minutes after a merge.
- **`AskUserQuestion` blocks a background session.** "Ask last" limits the damage but cannot remove it.
- **A satisfied native blocker that is still open** costs an owner question each time (§4), instead of
  a pipeline change (owner decision 2).

## Assumptions

Each question the brainstorm would have asked the owner, with the answer this design assumed. Owner
decisions made during `review-plan` are marked.

1. *Must the orchestrator be a background session?* Yes, a `claude --bg` session. An interactive
   `/orchestrate` only launches one via `spinoff`, after preflight and questions.
2. *Where does it run, and may it ever enter a worktree?* The target repo's primary checkout. It never
   calls `EnterWorktree` and never edits a file in any checkout.
3. *One repo or several?* One. Cross-repo dependency references are reported, not enforced.
4. *What is the invocation?* `/orchestrate <issue> …`. The cap and adding or dropping issues are
   natural-language changes in the session.
5. *Which config does it need?* `.claude/work-on.config.md` with `worktree.create`, `worktree.remove`
   and `branch.issue`, or it stops at preflight.
6. *What counts as a dependency?* Native `blocked_by`, plus every `#M` on a `Depends on` body line.
   A PR reference means that PR must merge.
7. *When is a dependency satisfied?* When its PR is MERGED, or its issue is closed as completed.
8. *How is an issue's PR found while its run is in flight?* By the `branch.issue` prefix.
9. *What bounds parallelism?* Free slots, and a default cap of 4 running items. A ready PR awaiting
   merge does not count.
10. *Stacking?* **Owner decision: not in this version.**
11. *May this change edit pipeline or slots?* **Owner decision: no.**
12. *A satisfied native blocker that is still open?* An owner question: close #M, leave #N out, or the
    owner handles it (§4).
13. *In-flight work on a requested issue?* Never launch. Ask: adopt (or resume, when orphaned) or leave
    it out. In-flight work on a dependency outside the set is adopted without asking.
14. *How is a worktree's owner found?* The dispatch record for own runs; otherwise `owners.py`: live,
    not self, transcript `cwd` entries inside the worktree. Revised after `review-plan` measured the
    path grep matching five sessions.
15. *A second orchestrator over the same issues?* This one refuses to start and asks.
16. *How is "nothing unpushed" verified?* HEAD equals the merged PR's `headRefOid`.
17. *What does teardown remove?* Slot repos: `worktree.sh remove <N> --force-local-branch-removal`.
    Other repos: the declared `remove`, then `git branch -D`. Only after the checks, without asking.
18. *The `slots` confirm step?* Orchestrate's teardown of a verified merged worktree takes precedence;
    `slots` is unchanged for teardowns the owner asks for.
19. *Proof pages?* Runs are told not to open them; orchestrate opens each once when announcing.
20. *How is a merge noticed?* One background `gh pr view` loop per PR every 5 minutes, plus task
    notifications and `notify_when_idle`. No `ListAgents` polling.
21. *When is a run suspected stalled?* No completion notice and no branch or PR change for 90
    minutes. One `SendMessage`.
22. *Which open questions reach the owner?* Only genuine forks, as batched `AskUserQuestion`.
23. *Undo ready pre-emptively for open questions?* No. Only when an answer needs commits.
24. *When may orchestrate ask?* Last, after every action that does not depend on the answer.
25. *A halted run?* An owner question with the halt reason verbatim. Its dependents keep waiting.
26. *Does orchestrate update the primary checkout's trunk?* No. `git fetch origin --prune` only.
27. *Dispatch options?* No `isolation`, no model override.
28. *After an orchestrator crash, are orphaned runs resumed automatically?* No: one batched question.
29. *PHP checks?* No. Prose, commands, and one Python lookup with a fixture test.
30. *File layout?* `SKILL.md` ≤ 1,000 words plus `references/commands.md`, like `pipeline`. Revised
    after `review-plan`.
31. *Scenarios?* S1–S4b for the slipping rules, S5 for owner questions (added after `review-plan`), S6 for the cap and a PR closed without merge (added after `review-pr`).
    Scenario agents read the arm's skill files and call no other tool.
