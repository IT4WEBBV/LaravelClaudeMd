# Machinery — how the code enforces a run

How the code enforces a run, for whoever changes it. Rule text the steps and the session follow lives in
their files; this file says what the commands and the script do about it. Why each piece exists is
`../DECISIONS.md`.

## The control rule

**The whole model, and it fails closed:**

- **Every step is a fresh agent** briefed by `pipeline_brief($manifest, $leg, $manifestPath, $step)`
  (`../checks/brief.php`). It records its results and a status with `dispatch_cli.php record`
  (`manifest.md` §What a leg writes), which refuses a result the next check would halt on, and replies
  with one line. The session never reads that reply for content: `returned` compares the manifest with
  the snapshot taken at dispatch and **halts** on anything it cannot account for.
- **Legs never pick the next leg and never write a brief.** `pipeline_returned()`
  (`../checks/dispatch.php`) routes: `continued` → the next step or leg (`pipeline_next_leg`),
  `looped-back` → `gates.md` §Loop-backs within the bound, `halted` → stop, `plan-insufficient` →
  grow a Bounded design, or loop an Architectural one back to `design` within `review-plan`'s bound
  (`shared/plan-falls-short.md`).
- **Auto-continuation spans only dispatched steps.** The loop never tries to "become a skill inline
  and then regain control": a skill that tail-calls its successor (as `brainstorming` invokes
  `writing-plans`) would never return, so an inline auto-continuation would silently walk past the
  next gate. A lost step **halts the chain; it never skips a gate.**

## The workflow script

The `autoflow` loop is `../workflow/pipeline-autoflow.js`, the saved workflow `pipeline-autoflow`: the order of
steps, the loop-backs, their bounds and the halts are JavaScript, and agents exist only inside steps.

```
invoking session   dispatch_cli.php kickoff → dispatch_cli.php launch → Workflow pipeline-autoflow (args: launch's JSON)
                   … on its return: dispatch_cli.php finish → dispatch_cli.php ci → gh pr ready | fix round | halt duties → report
workflow script    first: the relay check, agent(prompt, {agentType: pipeline-relay-check, schema {head}}) → clean, or return a halt
                   per step: agent(prompt, {schema}) → {status, reason, ui, size} → next step, loop-back or return
step agent         dispatch_cli.php brief <manifest> <leg> <step> [--after …] → the leg's work → the manifest → {status, reason}
```

The script gives each step a schema whose `status` allows only what that step may return
(`tables.allowed`, from `LegStatus::allowedFor()`), continues, loops back or returns on that status,
counts each loop-back against `tables.bound` as `gates.md` §Loop-backs says, and returns `{action: done}` or
`{action: halt, leg, reason}`. Nothing ends a run as `done` except `review-pr`'s resolve step
continuing. The design size it goes by is the one `launch` read, then the one each `design` step
copied from its spec. It exempts one Bounded escalation per run, a resume included (`escalated`); every
other `plan-insufficient` counts toward
`review-plan`'s bound. A status it cannot route halts, and so do `args` that are not a `launch` `start` answer
or whose `tables.loopTarget` has no `review-plan`, the gate every `plan-insufficient` is charged to; `tables`
missing or incomplete halts with a reason that names them. `AutoflowScriptTest` replays the script
on `launch`'s answer. Every step runs on the model and effort `agents` gives it (§Agents per step),
and `agents`, `profile` or `tier` missing or incomplete halts before any agent, and so does an
`escalated` that is not a boolean; a review step that returns
nothing runs once more on the retry entry; a step that throws or returns nothing halts the run.

## `launch` — what a run checks at its start

`launch` does once what the run needs at its start: `manifest_validate` and a cursor on a leg,
`mode: autoflow` (`launch`, `brief` and `finish` each halt on any other mode, and touch nothing), the
finished rule (a cursor whose status is `done` answers `done`), the invariant check
(`manifest.md` §Invariant check), the step to start at (`pipeline_step()`), the loop-backs so far
per looping leg (`pipeline_loop_counts()`; an `unknown` cycle gives that gate the bound, which
permits no loop-back), `ui` from the diff and the design size from the spec's header. `--from <leg>`
re-arms a run at that leg through `pipeline_can_navigate()`, after those checks and only for one of
`pipeline_legs()`: a PR that needs new commits gets a new run with `--from review-pr`, without
editing a file, and its review reads what changed since the last completed one (`steps/review-pr-review.md` §Scoped re-review);
`--decision <text>`, repeatable, appends that text to `decisions` verbatim in the same
write, after `--from`'s checks (`session.md` §The CI gate). `checks` is the directory `launch` ran from, so every
step's `brief` runs the same code.

`tables` is what the script routes by, `pipeline_routing_tables()`: the legs in order, each leg's
steps, the loop-back targets, the statuses per `<leg>:<step>` and the bound, built from the
functions `interactive` routes by, so the script keeps no copy of them.
`agents`, `profile` and `tier` are the step agents' models and efforts, the profile the run starts
on and the tier its invocation named (§Agents per step); `escalated` is whether the ledger records
an escalation (`pipeline_escalated()`), from which the script seeds its own, so a resume keeps
`full` and the one exemption as a run does; an invalid `agents` override in the
manifest halts `launch` with the other manifest checks, before anything is written.
So does a relay-check agent that is not linked (`dispatch_cli_relay_agent_problem()`): when
`~/.claude/agents/pipeline-relay-check.md` is not a file (no link, or a dangling one), `launch` halts with
`fresh session: ~/.claude/agents/pipeline-relay-check.md is not linked, …`, which names the hook to run and a
new session to resume from: Claude Code reads agent types only when a session starts (#150).

## The relay check

**The relay check** (#134) is the script's first `agent()`, before any step and on a smoke run too: label
`relay-check`, agent type `pipeline-relay-check` (`../agents/pipeline-relay-check.md`, `tools: Read`,
linked into `~/.claude/agents/` by `hooks/git-freshness.sh`), the `smoke` entry, a `{head}` schema.
It exists because Claude Code relays the owner's last chat message to every agent of a run started in a
reply a human message opened; whether a run is framed is fixed at its start.
The agent copies the first 40 characters of its first message outside the system-reminder blocks (Claude
Code puts its CLAUDE.md context ahead of the task, framed or clean); the script normalises them (lower case,
letters and digits, single spaces) and accepts a head that starts with the harness's clean label
`[Workflow harness — computed task]` or with its own prompt's first 40 characters. Anything else,
an empty head or no answer, halts with `relay: … (head: "<normalised head>")` before any step, so the
manifest is as `launch` left it. A check whose agent type is not found halts with `fresh session: this
session started before … was linked, …`: `launch` found the link, so this session predates it (#150). Any
other throw halts with `the relay check failed: <message>`. Neither carries `relay:`, so `finish` relaunches
neither: a start from the same session halts alike, and the run is resumed from a new session. The
start-step check runs before it, so a halt that needs no agent still starts none.

**Remove when** upstream fixes the relay (anthropics/claude-code#95369, #96640) or ships a switch that
works, which shows as the relay check no longer halting with a relay head: the detour (`session.md`
§`autoflow`, step 3), the check, `launch`'s link check and the `fresh session:` reasons,
`../agents/pipeline-relay-check.md` and the hook's agents link go together.

## A step — brief, work, record, return

A step first runs `dispatch_cli.php brief <manifest> <leg> <step>`, followed on every step but
the run's first by what the step before it returned: `--after <leg>:<step> --status <status>`, plus
`--ui` after `implement` and `--size` after `design` (§The check at the next boundary). It checks that
return, then writes `cursor: {leg, status: pending}` — so after a `TaskStop` or a dead session the
cursor still names the step that was running — and the snapshot `<manifest stem>.before.json`, and
prints the brief. It prints a halt instead when the return does not hold, or when the ledger does not
support the step (`resolve` with no open review, `review` with one already open, the design step the
manifest does not call for, `pipeline_design_step()`; `launch` starts a run at the one it does call for). The step records its results with `dispatch_cli.php record`
(`manifest.md` §What a leg writes) — `handoff:run` excepted, whose command `dispatch_cli.php handoff`
does the step and records `continued` or `halted` itself (`steps/handoff.md`) —, then returns the status it
recorded as `{status, reason}`;
`implement` also returns `ui`, copied from `dispatch_cli.php ui <diff>` (`pipeline_triggers()` over
its diff), and each `design` step returns `size`, copied from `dispatch_cli.php size <manifest>`
(`DesignSize::fromSpec()` over the committed spec). Both are read-only and run after `record`: `size`
reads the spec `record` just set. Both are required on every return of their step and ignored on a
halt; the script takes `ui` only from `implement` and `size` only from `design`, after each design
step: the spec step's size decides whether the plan step runs (`steps/design.md` §`autoflow`'s design).

## The check at the next boundary

The script routes on the `status` a step returns; the step's
return is checked at the next command, by a separate process and no extra agent. `brief`, told by
`--after` which step returned, compares the manifest with that step's snapshot, as `returned` does in
`interactive`: `pipeline_reported_problem()` (`../checks/dispatch.php`) runs `pipeline_return_problem()` and
then compares what the script was told with what the manifest and the tree say — the status with
`cursor.status`, `ui` with `pipeline_triggers()` over the implement step's own `<manifest stem>.diff`
(one older than its snapshot halts), `size` with the spec's header. A halt writes
`cursor: {leg: <the step that failed>, status: halted, reason}`, so a resume re-runs that step. Without
`--after` (the run's first step) nothing is checked. A snapshot of the very step being briefed is the
script's Opus retry of a review step that returned nothing: an unchanged manifest is briefed again, a
changed one halts. `launch` removes an earlier run's snapshot when it answers `start`, so a `brief`
without `--after` that finds a snapshot of another step halts: the step agent dropped the flags it was
given, and the check cannot be skipped by leaving them out. `finish` runs the same check for
`review-pr`'s resolve step before it records `done`.
What a step claims about work outside the manifest is caught by the next gate (`review-plan` reads the
spec and plan, `review-pr` the code). `run_audit.php` reports after the run whether the steps and the
ledger agree (`session.md` §The report).

## `finish` — recording the workflow's return

`finish` records the return: `done` sets `cursor.status: done`, but only with the cursor on
`review-pr` — anywhere else it records the halt "the workflow returned done at <leg>" — and only when
the last snapshot is `review-pr`'s resolve step's and that step's return holds (§The check at the next
boundary); otherwise it records that halt. A halt sets `cursor: {leg, status: halted, reason}`,
keeping the cursor's leg when the cursor already says `halted` (`brief` or the step wrote it, on the
step that failed) or when the return names none of the pipeline's legs. When the workflow itself
errored, the session passes `{"action":"halt","reason":"<the error>"}`: the cursor keeps the step that was
running.
A `done` that holds answers `ask` instead while a `blocking` open question is unanswered: the cursor
still says `done` (`session.md` §Open questions). Both answers carry `followUps`, the `follow-up`
questions for the report.
A `relay:` halt also answers `relaunch: true` unless the cursor it overwrites already holds a `relay:`
halt. A run that got past the check overwrote that cursor at its first `brief`, so the count starts over
with every run. What the session does with each answer is `session.md` §`autoflow`.

## Where a step works

**The worktree travels in the brief** (*"Work only in `<worktree>`"*) and in
absolute paths, never in the launch directory: `orchestrate` launches up to four runs from its primary
checkout. Commands that key on the working directory (`shared/suite.md` §Suite reuse's tree key) run from `cd <worktree>`
or with `git -C <worktree>`, as the `autoflow` brief says.

**What a workflow agent cannot do.** It cannot start agents, so no step dispatches one; its brief says
how each station's dispatch is done by the step itself. And the auto-mode classifier denies it
`gh pr ready`, so that write stays with the invoking session.

## Agents per step — one table, explicit model and effort

Every `autoflow` step's agent runs on a model and an effort from one table, `PIPELINE_AGENTS` in
`../checks/agents.php`; no step inherits the session's `~/.claude/settings.json`, which differs per
machine and changes silently. `launch` hands the table to the script as `agents` in its `start` answer
(`pipeline_agent_table()`, the manifest's override laid over it) with `profile`, the profile the run
starts on (`pipeline_start_profile()`), and `tier`, the tier its invocation named
(`AgentTier::fromManifest()`); the script names no model or effort, and a missing or incomplete
`agents`, `profile` or `tier`, or an `escalated` that is not a boolean, halts it before any agent.
Models are `agent()`'s aliases, efforts its
levels. The owner's constraints are tokens (plan limits), quality and speed, not price.

Three tiers, picked by the invocation's word: `full` with no word, `medium`, and `light` for a tiny
change.

| Step | `full` | `medium` | `light` | Why |
|---|---|---|---|---|
| `design:spec` | opus high | opus medium | opus medium | Full: a mistake surfaces only at `review-plan` and costs a loop (design, review, resolve). Medium: a ~25-line design, and escalation is the safety net. Light: the same ~25-line design; `review-plan` catches a mistake. |
| `design:plan` | opus high | opus medium | opus medium | As `design:spec`. The plan step runs only on an Architectural design, so on `full`; the `medium` and `light` entries keep every step in every tier. |
| `review-plan:review` | fable high | fable medium | opus medium | Full: independent of the author, Fable's documented starting point; xhigh added nothing measurable in two runs, and `low` answers from memory more. Medium: a short spec is flatter work. Light: spares Fable quota; the same model as the author, accepted on a ~15-line spec (owner decision), and the PR review stays independent. |
| `review-plan:resolve` | opus high | opus medium | sonnet medium | Full: it decides which findings to reject. Medium and light: few findings on a short spec. |
| `handoff:run` | sonnet low | sonnet low | sonnet low | Runs one command (`dispatch_cli.php handoff`) and returns the status it printed. Haiku 4.5 has no effort setting: rejected. |
| `implement:run` | opus high | opus high | sonnet high | Medium keeps high: TDD and the escalation check after every commit happen here, and its time goes to CI and Pint, not the model. Light: a tiny change; a `verify-ui` or `review-pr` loop-back still reruns it on the loop-back entry. |
| `verify-ui:run` | sonnet high | sonnet medium | sonnet medium | Mostly browser operation; full stays high because it is a gate that can send the run back to `implement`. Medium and light: few states to capture, and no lower, since it is a gate. |
| `review-pr:review` | fable high | fable high | opus high | The last gate before a human merges: high on every tier. Light: on the first round Opus is independent of Sonnet's code, and it spares Fable quota. |
| `review-pr:resolve` | opus high | opus medium | sonnet high | Full: nothing reviews it afterwards unless it loops back. Medium and light: targeted fixes on a small diff. |
| `implement:run` after a loop-back | opus xhigh | opus xhigh | opus xhigh | A `verify-ui` or `review-pr` loop-back is the failure signal to rerun with more effort. |
| a review that returned nothing, once | opus xhigh | opus xhigh | opus xhigh | Rare; it fires on `null`, not on a review with no findings, and compensates for reviewing with the author's model. |
| a smoke run's stub step, and the relay check | sonnet low | sonnet low | sonnet low | A stub does no real work; the check copies 40 characters. |

**Which profile.** The word names the tier (`AgentTier::fromManifest()`): the manifest's `tier`,
`medium` for a legacy `light: true`, else `full`; `launch` halts on a `tier` that is not `medium` or
`light`, as on an invalid `agents` override.
The design size moves a run up, never down (`AgentTier::forDesign()`): an Architectural design runs on
`full`, a Bounded one on the named tier, so a Bounded design with no word stays on `full`. `launch`
starts the run on `full` once the ledger records an `escalated` entry; else, once a spec exists, on the
tier `forDesign()` gives for its size; else on the named tier, the only signal before `design` runs.
The script then sets the profile after every `continued` design step from the size it returned (the
tier for Bounded, unless the run has escalated; `full` otherwise), and to `full` on a Bounded
escalation, so the grow-form design and every step after it run on `full`: escalation is one way, in
the run as on a resume. Every step, `design` included, runs on the current profile. The script checks
`full` and the run's tier, the only tables it can reach, and halts on a tier whose table misses a step.

**The loop-back entry.** A step whose leg a gate has looped back to in this run — `loops` counts on from
the ledger's, so a resume keeps it — takes its `loopedBack` entry when it has one: `implement:run` after
a `verify-ui` or `review-pr` loop-back, on every tier. A plan gap loops back to `design`, which has none.

**The override.** A manifest may set `agents: {"<leg>:<step>": {"model": …, "effort": …}}` by hand for a
one-off experiment; either field may be left out and keeps the table's. It replaces that step's entry
in every tier and its loop-back entry; the retry and smoke entries are not overridable. `launch`
halts on an override that is not an object of `autoflow` steps each naming a known model or effort
(*the manifest's agents override is invalid: …*), and a leg that writes `agents` halts at the next brief.

**Fable stays the reviewer on `full` and `medium`.** Reviews on Opus would be the largest token lever,
but give up an independent reviewer. `light` takes that lever for a tiny change (owner decision): its
PR review on Opus is still independent of Sonnet's code on the first round. `run_cost_cli.php` weighs
each call by its model (`session.md` §The report), so a model swap shows in the figure; effort shows mostly as turns
and wall time.

## What a leg brief consists of

Every brief is generated by `pipeline_brief($manifest, $leg, $manifestPath, $step)`
(`../checks/brief.php`); nobody writes one by hand — not the invoking session, not a leg, not a
coordinator. In `interactive` `next` writes it to `<manifest stem>.brief.md`
and the dispatch prompt is one line naming that file; in `autoflow` the step prints its own with
`dispatch_cli.php brief`. A brief consists of:

- **pointers** to the step's reference (`steps/<file>.md`, `pipeline_step_reference()`), the artifacts
  (idea, spec, plan, PR, issue, proof page), the manifest, and — on a
  resolve step or after a loop-back — the ledger entry to act on, by index;
- **the settled decisions** (`decisions`) and the manifest state the step needs, including the last
  suite tree (`shared/suite.md` §Suite reuse) and the permitted design size on `design`;
- **the overrides for that leg and step** from `pipeline_leg_overrides()`, each citing the file that
  holds its rule, e.g. *"leave the PR draft"* (`steps/implement.md`); on a
  step that writes to the branch, first among them the order to merge the base when the branch fell
  behind it (`shared/catch-up.md` §Catching up with the base);
- **the return contract**: the literal `record` command for each status the step may return, with the
  manifest's full path, printed from `pipeline_record_table()` (`../checks/record.php`), with
  `handoff:run`'s own command in the place of its `record --status continued`
  (`PIPELINE_STEP_COMMANDS`); the snapshot beside the manifest is named so that no step mistakes it for
  the manifest;
- **nothing a station does not ask for.** No test policy, proof format or process of anyone's own
  invention.

A brief cites files, never sections: a step reads its reference whole.

**The draft rule stays in the `implement` brief.** A plan's last task or an older PR's `handoff` prompt
comment can tell `implement` to mark the PR ready (`steps/implement.md` §Leave the PR draft), so the
brief carries the rule verbatim (`pipeline_leg_overrides()`): *"Leave the PR draft, whatever the plan or
a PR comment says about marking it ready (steps/implement.md)."* A cold-resume session that picks the PR
up from its comment is outside the loop, so nothing mechanical can stop it undrafting early: the
instruction in the brief is the only control. Keep it there.

**An `autoflow` brief adds what a workflow agent needs**
(`pipeline_leg_overrides('autoflow', <manifest path>)`): a review step applies `/critique`'s procedure
itself (`shared/reviewing.md` §A review step); `review-plan`'s resolve step has no independent read; `implement` executes
the plan inline, with no subagents, and does not wait on CI; the finish step pushes and leaves the PR
draft; every step works from `cd <worktree>` and is told the owner authorised the run; and `## Return`
asks for a structured `{status, reason}` instead of a line.

## `handoff` in order

`record`'s own checks first (the manifest, this step's snapshot), so a refusal
pushes nothing. Then, read-only: `artifacts.spec` and `artifacts.plan` exist at `HEAD`, the worktree is on
the run's branch, the `## Board` section is not `invalid`, and the PR lookup — `artifacts.pr` when set,
else `gh pr list --head <branch> --state open`. A PR that is not an open draft, one on another branch,
more than one, or a gh that cannot answer is a halt, still with nothing pushed. Then the push, then
`gh pr create --draft --head <branch> [--base <base>]` read back through the same listing, or, on an
existing PR, `gh pr edit --base` when its base differs and `gh pr edit --body` when its body lacks the
spec, the plan or the issue: a body is only added to and a title never changed. The Component is two
idempotent board calls, and its failure is a note. Then the page: the command files the run's proof page
(`proof-store.md` §Where a page lives), and a page it cannot file is a note. The command records `continued` with the PR and the page, or
`halted` with the reason, through `record`'s code; a record refused once the PR is open names the PR, and
the next run adopts it instead of opening a second one (#118). Every outward act is idempotent: after any
failure, running the step again is the repair.

**`handoff` takes the spec and the plan from the manifest.** The command reads `artifacts.spec` and
`artifacts.plan`; it detects nothing from the last commits or the newest files, which a merge of the base
empties or crowds.

## The review scope — what `brief` computes for a re-review

`review-pr`'s review step reviews what changed since the last completed review
(`steps/review-pr-review.md` §Scoped re-review); `brief` works out what that is.

**The base** is `pipeline_review_base()`: the `reviewed_sha` of the newest `continued` `pr-review` entry
that has one, newer than the latest escalation or plan gap (`pipeline_reset_at()`, the cut
`pipeline_done_legs()` makes: code reviewed against a plan that grew is reviewed whole again). A halted,
looped-back or open review is never a base: its findings were not dispositioned there.

**The target** is `pipeline_review_scope()`, which `brief` (and `next` / `returned` in `interactive`)
computes with git in the worktree for `review-pr`'s review step only, and writes into its brief as one
override line:

- the branch's own commits since the base, as patches: `git log -p --no-merges <sha>..HEAD ^<base>`, plus
  `git diff HEAD`; Stage 0 runs over both. `<base>` is `origin/<manifest base>`, else `origin/HEAD`.
  `^<base>` leaves out what a merge of main brought in and keeps a merged-in side's commits that are not
  on main (a pull of the PR branch onto local commits), which `--first-parent` would drop;
- read whole at HEAD, the files where a merge since the base met the branch's changes: per merge not on
  the base, the files both sides changed since they last met (every conflict, a clean merge of a shared
  file, a resolution that took one side), and the files the merge commit changed against every parent
  (an edit made in the merge itself).

**Otherwise the review is full:** no `continued` entry with a sha, a sha HEAD does not contain
(a rebase, a force-push), a base ref git cannot resolve (with no manifest `base` and `origin/HEAD` unset,
every re-review on that machine stays full; `git remote set-head origin --auto` sets it), or any git call
that fails. The scope is never narrower than git could prove textually. It cannot see a semantic
conflict: a merge that changes only files the branch did not touch lists none, even where the branch's
code depends on them. The full review has that blind spot too; the suite and CI cover it.

## The CI gate — what `ci` computes

`dispatch_cli.php ci <manifest> --poll <n>`
(`../checks/ci.php`) reads the worktree's `HEAD` (`git rev-parse HEAD`; a git error halts at once), then
the PR's head commit, whether it merges into its base, and its checks once (`gh pr view <pr> --json
headRefOid,mergeable,statusCheckRollup`), writes nothing, and prints one JSON line. GitHub's head has to
be the worktree's `HEAD` before its checks count (#99): a push that failed or was skipped leaves an older
head whose CI can be green, and the PR would go ready without the last fix. That comparison comes first,
so neither a green nor a red on an older commit counts; whether the PR merges comes next, before the
checks (#149):

| Verdict on the head commit | Answer |
|---|---|
| `ask`: a `blocking` open question no decision answers (`autoflow`) | `ask`, with the questions, before git or gh is read; it spends no round (`session.md` §Open questions) |
| `merge`: a merge since the last completed review met the branch's changes (`autoflow`) | `fix` the first time in a run, before the PR is read; after that round the gate goes on to the rows below |
| `mismatch`: GitHub's head is not the worktree's `HEAD` | `wait`; `halt` at the third read, naming both shas: a push GitHub shows within seconds, and one it does not show by then did not land |
| `conflicting`: GitHub reports the PR `CONFLICTING` with its base | `fix` the first time in a run; `halt` once that round is spent |
| `unknown`: GitHub has not worked out mergeability yet (it computes it lazily, after a push or `gh pr ready`) | `wait`; `halt` at the 120th read |
| `green`: every check finished `SUCCESS`, `NEUTRAL` or `SKIPPED` | `ready` |
| `none`: no check at all | `ready`; with `.github/workflows/*.yml` or `*.yaml` in the worktree only from the third read, since GitHub registers a push's checks seconds after it |
| `pending` | `wait`; `halt` at the 120th read (an hour at 30 s) |
| `red`: any other conclusion, or a status in `FAILURE` or `ERROR` | `fix` the first time in a run; `halt` once that round is spent |
| `unreadable`: `gh` failed | `wait`; `halt` at the 120th read |

**The CI round.** A red's `fix` carries the decision `CI red on the PR's head commit <sha>: <check> failed
(<link>)`. A decision that starts `CI red on the PR's head commit` is the round spent, once per run, so a
resumed run halts on its next red too.

**A merge the review did not see** (#124). The finish step is a run's last, so a merge it makes
(`shared/catch-up.md`), conflict resolutions included, would reach a ready PR unreviewed. In
`autoflow`, `ci` computes `pipeline_review_scope()` and hands its `files` to the gate: after `finish`
recorded `done` the scope's base is the review that just completed, so the files are exactly what a
merge since that review met. When there are any, the gate answers `fix` with verdict `merge`, the files
and the decision `Unreviewed merge on the PR's head commit <sha>: a merge since the last completed
review met this branch's changes in <files>` (`<sha>` is the worktree's `HEAD`), before it reads the PR
or its checks. The re-review is scoped and records a `reviewed_sha` that contains the merge, so the
gate's next read finds nothing unreviewed. Once per run, counted as the CI round is and apart from it: a
run may have one of each. A further unreviewed merge neither halts nor loops; the gate goes on to CI, and
the PR body's `## Base merges` line is its record. The round fires on a clean merge of a shared file as on
a conflict: git 2.33 cannot tell the two apart afterwards, and a textual merge of a file both sides
changed is what a review is for.

**A conflict with the base** (#149). A sibling merged into the base after this run's review can leave
the PR `CONFLICTING`, and on a repo without CI the checks' `none` would still read `ready`. GitHub's
`mergeable` is its answer for its head, so the gate reads it after the head comparison, and before the
checks: GitHub runs no CI on a conflicting PR's merge ref, so its checks say nothing about the code that
would merge. `CONFLICTING` answers `fix` with verdict `conflicting` and the decision `Conflict with the
base on the PR's head commit <sha>: GitHub reports PR #<pr> CONFLICTING with its base; review-pr's
resolve step merges the base (shared/catch-up.md)`. Once per run, counted from `decisions` apart from
the other two rounds: a conflict after it halts, naming `review-pr`. The round is counted by the
decision's prefix, so a decision that cites another file still counts. `UNKNOWN` is a `wait`, as a
pending check is, and a halt at the 120th read.

What the session does with each answer is `session.md` §The CI gate; what the review and finish steps do
in a round is `shared/review-pr.md` §The rounds.

## The tests and the links

The deterministic guardrails are tested PHP in `../checks/`: run
`./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`.

`~/.claude/workflows/pipeline-autoflow.js` is a symlink to `../workflow/pipeline-autoflow.js`, and
`~/.claude/agents/pipeline-relay-check.md` one to `../agents/pipeline-relay-check.md`, both linked by
`hooks/git-freshness.sh` as it links the skills.
