# Engine — the trampoline loop every `/pipeline` runs

The pipeline holds **no long-lived state of its own**. Each invocation runs one turn of a loop
whose only memory is the manifest (`manifest.md`) and durable git/gh state. Gates and triggers
are `gates.md`. This file is the operational procedure.

## The loop

Both modes walk the same legs with the same briefs, which `autoflow` extends (§What a leg brief
consists of). They differ in who holds the loop:

| Mode | Who holds the loop | Commands |
|---|---|---|
| `interactive` | the session; the human resolves each review (§Interactive) | `next` / `returned`, below |
| `autoflow` | the saved workflow `pipeline-autoflow`, a program (§`autoflow`) | `launch` / `brief` / `finish` |

`launch` refuses a manifest whose mode is not `autoflow`, and `next` refuses one whose mode is. Every
command, `kickoff --mode auto` included, refuses `auto`, the dispatcher engine #87 removed, with a halt
that names `autoflow` (`pipeline_retired_mode()`). An `auto` manifest resumes once its `mode` says
`autoflow`, through `launch`.

```
read manifest (or reconstruct it)          # manifest_read / manifest_infer_cursor
  → invariant check                         # recorded artifact at last_sha; PR as expected
  → dispatch_cli.php next                   # brief + snapshot → one dispatch line
  → dispatch the step                       # a fresh background agent; wait for its completion notice
  → dispatch_cli.php returned               # validate the manifest, route, write it
  → dispatch | retry | halt | done
```

```bash
CHECKS="$HOME/.claude/skills/pipeline/checks"
php "$CHECKS/dispatch_cli.php" next <manifest>                        # start or resume
git -C <worktree> diff origin/<base>...HEAD > "<manifest stem>.diff"
php "$CHECKS/dispatch_cli.php" returned <manifest> "<manifest stem>.diff"   # after every return
```

`<manifest stem>` is the manifest path without `.json`: the diff, the brief (`.brief.md`) and the
dispatch snapshot (`.before.json`) sit next to the manifest, one set per run, so concurrent runs
never share a diff file, and `.claude/pipeline/` keeps them out of git and out of §Suite reuse's key.
`<base>` is the manifest's `base` on a run kicked off with one (§Kickoff, *A run on a base*), and the
repo's default branch otherwise: every `origin/<base>` in this skill and in `orchestrate` means that.

Each prints one JSON line. On `dispatch` or `retry`, pass its `prompt` — one line naming the brief
file `pipeline_brief()` wrote — to a background agent; when `inline` is true, run the step in this
session instead (§Interactive). On `halt`, stop (§Failure policy). On `done`, return.

**Wait for the completion notice.** No `sleep`, `date`, file-mtime or `ListAgents` polling while a
step runs.

**Control rule — the whole model, and it fails closed:**

- **Every step is a fresh agent** briefed by `pipeline_brief($manifest, $leg, $manifestPath, $step)`
  (`../checks/brief.php`). It writes its results and a status into the manifest (`manifest.md`
  §What a leg writes) and replies with one line. The session never reads that reply for content:
  `returned` compares the manifest with the snapshot taken at dispatch and **halts** on anything it
  cannot account for.
- **Legs never pick the next leg and never write a brief.** `pipeline_returned()`
  (`../checks/dispatch.php`) routes: `continued` → the next step or leg (`pipeline_next_leg`),
  `looped-back` → `gates.md` §Loop-backs within the bound, `halted` → stop, `plan-insufficient` →
  grow a Bounded design, or loop an Architectural one back to `design` within `review-plan`'s bound
  (§Design size, *A plan gap on an Architectural design*).
- **Auto-continuation spans only dispatched steps.** The loop never tries to "become a skill inline
  and then regain control": a skill that tail-calls its successor (as `brainstorming` invokes
  `writing-plans`) would never return, so an inline auto-continuation would silently walk past the
  next gate. A lost step **halts the chain; it never skips a gate.**

## `autoflow` — a program that calls agents

The loop is `../workflow/pipeline-autoflow.js`, the saved workflow `pipeline-autoflow`: the order of
steps, the loop-backs, their bounds and the halts are JavaScript, and agents exist only inside steps.

```
invoking session   dispatch_cli.php kickoff → dispatch_cli.php launch → Workflow pipeline-autoflow (args: launch's JSON)
                   … on its return: dispatch_cli.php finish → dispatch_cli.php ci → gh pr ready | fix round | halt duties → report
workflow script    per step: agent(prompt, {schema}) → {status, reason, ui, size} → next step, loop-back or return
step agent         dispatch_cli.php brief <manifest> <leg> <step> [--after …] → the leg's work → the manifest → {status, reason}
```

```bash
CHECKS="$HOME/.claude/skills/pipeline/checks"
php "$CHECKS/dispatch_cli.php" kickoff <primary checkout> <number | "<idea>"> [--medium|--light] [--base <branch>] [--decision "<verbatim>"]…
# → {"action":"ready","manifest":…,"worktree":…,"branch":…,"notes":[…]} | {"action":"halt","reason":…}
git -C <worktree> diff origin/<base>...HEAD > "<manifest stem>.diff"
PIPELINE_NO_OPEN=<1 unattended, else 0> php "$CHECKS/dispatch_cli.php" launch <manifest> "<manifest stem>.diff" [--from <leg>] [--decision "<verbatim>"]…
# → {"action":"start","startLeg":…,"startStep":…,"loops":{…},"ui":…,"size":…,"manifest":…,"worktree":…,"noOpen":…,"checks":…,"tables":{…},"profile":…,"tier":…,"escalated":…,"agents":{…}}
#   | {"action":"done"} | {"action":"halt","reason":…}
# start: the workflow pipeline-autoflow with that JSON as args, in the background; wait for its completion notice
php "$CHECKS/dispatch_cli.php" finish <manifest> '<the workflow return, as JSON>'
php "$CHECKS/dispatch_cli.php" ci <manifest> --poll <n>                  # after done: the CI gate, polled (§The CI gate)
```

The invoking session — the main session or `orchestrate` — is an agent only at the two edges, and runs
one tested command at each. Nothing reads a step's work in between; a halt lands in the session that
launched the run, with its reason.

- **`launch`** does once what the run needs at its start: `manifest_validate` and a cursor on a leg,
  `mode: autoflow` (`launch`, `brief` and `finish` each halt on any other mode, and touch nothing), the
  finished rule (a cursor whose status is `done` answers `done`), the invariant check
  (`manifest.md` §Invariant check), the step to start at (`pipeline_step()`), the loop-backs so far
  per looping leg (`pipeline_loop_counts()`; an `unknown` cycle gives that gate the bound, which
  permits no loop-back), `ui` from the diff and the design size from the spec's header. `--from <leg>`
  re-arms a run at that leg through `pipeline_can_navigate()`, after those checks and only for one of
  `pipeline_legs()`: a PR that needs new commits gets a new run with `--from review-pr`, without
  editing a file, and its review reads what changed since the last completed one (§Scoped re-review);
  `--decision <text>`, repeatable, appends that text to `decisions` verbatim in the same
  write, after `--from`'s checks (§The CI gate). `checks` is the directory `launch` ran from, so every
  step's `brief` runs the same code.
  `tables` is what the script routes by, `pipeline_routing_tables()`: the legs in order, each leg's
  steps, the loop-back targets, the statuses per `<leg>:<step>` and the bound, built from the
  functions `interactive` routes by, so the script keeps no copy of them.
  `agents`, `profile` and `tier` are the step agents' models and efforts, the profile the run starts
  on and the tier its invocation named (§Agents per step); `escalated` is whether the ledger records
  an escalation (`pipeline_escalated()`), from which the script seeds its own, so a resume keeps
  `full` and the one exemption as a run does; an invalid `agents` override in the
  manifest halts `launch` with the other manifest checks, before anything is written.
- **The script** gives each step a schema whose `status` allows only what that step may return
  (`tables.allowed`, from `LegStatus::allowedFor()`), continues, loops back or returns on that status,
  counts each loop-back against `tables.bound`, 2 per gate (`gates.md` §Loop-backs), and returns `{action: done}` or
  `{action: halt, leg, reason}`. Nothing ends a run as `done` except `review-pr`'s resolve step
  continuing. The design size it goes by is the one `launch` read, then the one each `design` step
  copied from its spec. A Bounded escalation is not a loop-back, and escalation is one-way (§Design
  size), so the script exempts one per run, a resume included (`escalated`); every other `plan-insufficient` counts toward
  `review-plan`'s bound. A status it cannot route halts, and so do `args` that are not a `launch` `start` answer
  or whose `tables.loopTarget` has no `review-plan`, the gate every `plan-insufficient` is charged to; `tables`
  missing or incomplete halts with a reason that names them. `AutoflowScriptTest` replays the script
  on `launch`'s answer. Every step runs on the model and effort `agents` gives it (§Agents per step),
  and `agents`, `profile` or `tier` missing or incomplete halts before any agent, and so does an
  `escalated` that is not a boolean; a review step that returns
  nothing runs once more on the retry entry; a step that throws or returns nothing halts the run.
- **A step** first runs `dispatch_cli.php brief <manifest> <leg> <step>`, followed on every step but
  the run's first by what the step before it returned: `--after <leg>:<step> --status <status>`, plus
  `--ui` after `implement` and `--size` after `design` (§The check at the next boundary). It checks that
  return, then writes `cursor: {leg, status: pending}` — so after a `TaskStop` or a dead session the
  cursor still names the step that was running — and the snapshot `<manifest stem>.before.json`, and
  prints the brief. It prints a halt instead when the return does not hold, or when the ledger does not
  support the step (`resolve` with no open review, `review` with one already open, the design step the
  manifest does not call for). The step writes its results into the manifest (`manifest.md` §What a
  leg writes) and returns `{status, reason}`;
  `implement` also returns `ui`, copied from `dispatch_cli.php ui <diff>` (`pipeline_triggers()` over
  its diff), and each `design` step returns `size`, copied from `dispatch_cli.php size <manifest>`
  (`DesignSize::fromSpec()` over the committed spec). Both are required on every return of their
  step and ignored on a halt; the script takes `ui` only from `implement` and `size` only from `design`,
  after each design step: the spec step's size decides whether the plan step runs (§Design size,
  *`autoflow`'s design*).
- **`finish`** records the return: `done` sets `cursor.status: done`, but only with the cursor on
  `review-pr` — anywhere else it records the halt "the workflow returned done at <leg>" — and only when
  the last snapshot is `review-pr`'s resolve step's and that step's return holds (§The check at the next
  boundary); otherwise it records that halt. A halt sets `cursor: {leg, status: halted, reason}`,
  keeping the cursor's leg when the cursor already says `halted` (`brief` or the step wrote it, on the
  step that failed) or when the return names none of the pipeline's legs. When the workflow itself
  errored, pass `{"action":"halt","reason":"<the error>"}`: the cursor keeps the step that was
  running. When `finish` prints `done` the invoking session runs the CI gate and, on its `ready`,
  **`gh pr ready <pr>`** (§The CI gate, §Who takes the PR out of draft); on a halt after `handoff`,
  §Failure policy's duties.
- **Resume** is `/pipeline` as always: `launch` starts from the cursor, and the step it names runs
  again.

**The check at the next boundary.** The script routes on the `status` a step returns; the step's
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
spec and plan, `review-pr` the code). After every run the invoking session reports two facts with the
result:

```bash
php "$CHECKS/run_cost_cli.php" <the run's transcript dir>
git -C <worktree> diff origin/<base>...HEAD > "<manifest stem>.diff"
php "$CHECKS/run_audit.php" <manifest> "<manifest stem>.diff" <the run's transcript dir>
```

The transcript dir is `~/.claude/projects/<project>/<session>/subagents/workflows/wf_<id>/`, named in
the Workflow result. `run_cost_cli.php` prints per step the weighted cost — each call's token types
weighed per model (`PIPELINE_MODEL_FACTORS`, relative to Opus), the models named after the step's
label — the peak context, the wall
time and the part of it spent waiting on tools, and on its `run:` line the total, the run's span in
minutes and the largest step peak. `run_audit.php` prints whether `ui` over the final diff agrees with
a `verify-ui` entry, whether each gate's newest ledger entries agree with what the steps reported, and
(`bound:`) whether each gate the run looped back at holds no more `looped-back` ledger entries than
`PIPELINE_LOOP_BOUND`, the loop-back the run halted on not counted: `tables.bound` and `loops` reach the
script through the invoking session's copy of `launch`'s answer, and no boundary check counts
loop-backs. It stays as the after-run report: with the boundary check in place a `MISMATCH` means a
check has a hole or the script ran with another bound, never a halt.

**Where a step works.** The worktree travels in the brief (*"Work only in `<worktree>`"*) and in
absolute paths, never in the launch directory: `orchestrate` launches up to four runs from its primary
checkout. Commands that key on the working directory (§Suite reuse's tree key) run from `cd <worktree>`
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
| `handoff:run` | sonnet low | sonnet low | sonnet low | Near-mechanical on every tier. Haiku 4.5 has no effort setting and writes `implement`'s prompt: rejected. |
| `implement:run` | opus high | opus high | sonnet high | Medium keeps high: TDD and the escalation check after every commit happen here, and its time goes to CI and Pint, not the model. Light: a tiny change; a `verify-ui` or `review-pr` loop-back still reruns it on the loop-back entry. |
| `verify-ui:run` | sonnet high | sonnet medium | sonnet medium | Mostly browser operation; full stays high because it is a gate that can send the run back to `implement`. Medium and light: few states to capture, and no lower, since it is a gate. |
| `review-pr:review` | fable high | fable high | opus high | The last gate before a human merges: high on every tier. Light: on the first round Opus is independent of Sonnet's code, and it spares Fable quota. |
| `review-pr:resolve` | opus high | opus medium | sonnet high | Full: nothing reviews it afterwards unless it loops back. Medium and light: targeted fixes on a small diff. |
| `implement:run` after a loop-back | opus xhigh | opus xhigh | opus xhigh | A `verify-ui` or `review-pr` loop-back is the failure signal to rerun with more effort. |
| a review that returned nothing, once | opus xhigh | opus xhigh | opus xhigh | Rare; it fires on `null`, not on a review with no findings, and compensates for reviewing with the author's model. |
| a smoke run's stub step | sonnet low | sonnet low | sonnet low | A stub does no real work. |

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
each call by its model (§`autoflow`), so a model swap shows in the figure; effort shows mostly as turns
and wall time.

## Interactive — the same loop, the human resolves

`interactive` runs the same `next` / `returned` pair. `inline` is true for `design` (the human drives
the brainstorm) and for every `resolve` step: the session shows the review from the open ledger entry,
the human decides, the session carries that out — on `review-pr` including the finish work below —
completes the entry with the human's `actions` and `outcome`, and runs `returned`. Every other step is
dispatched to a fresh background agent. After each step the session stops and continues when the human says so
(§Navigation), as `interactive` always has.

## The work item — resolved before anything is created

A run that carries a GitHub issue owes that issue three things `work-on` already does and the
pipeline previously did not: it claims it on the board, it refuses to start on blocked work, and
at the end it settles whether merging closes it (§Closing links). This section is the first two;
all of it runs **before the worktree exists**, because a run that must not start should leave
nothing behind.

**Resolve the item first. A bare number classifies itself:**

```bash
gh api repos/<repo>/issues/<number> \
  --jq '{number, title, html_url, node_id, state, is_pr: (.pull_request != null)}'
```

The `issues` endpoint returns both, and a PR has a non-null `pull_request` — the same probe
`work-on` uses, which is why `/pipeline <number>` needs no separate issue and PR syntax.

| Invocation | The run's issue |
|---|---|
| a number that is an **issue** | itself; the branch comes from the repo's `branch.issue` pattern in `.claude/work-on.config.md`, not from the idea-slugifier below |
| a number that is a **PR** | its `closingIssuesReferences`; failing that, the issue number in its `head.ref` |
| a **spec-path** or an existing branch | the issue number in the branch name, via the same `branch.issue` pattern |
| a bare **idea** | none. Skip this whole section **silently** — a run with no issue has nothing to administer, and saying so on every idea-run is the same noise as announcing an absent board (below). A number that was *given* and does not resolve is the opposite case: that is an error, and it is reported |

**Then the blockers — the only check here that can stop a run:**

```bash
gh api /repos/<repo>/issues/<number>/dependencies/blocked_by \
  | jq -r '.[] | select(.state == "open") | "#\(.number) \(.title)"'
```

Any open blocker → **halt at kickoff**, in every mode, naming the blockers. This is deliberately
*not* the treatment the content triggers get (`gates.md`): those are facts about a diff, answered
with an annotation, and the governing principle there is that the pipeline never merges so a bad
PR is trashable. A blocker is a different claim — that this work may not *start* — and the three
answers to it (wait, work around it, pick the blocker up first) are all the human's. Halting costs
nothing: no worktree, no branch, no PR exists yet, so there is nothing to leave behind and nothing
to clean up.

**Then the board — claim the item before the slow steps.** Board identifiers are **not** in this
skill; they live in the `## Board` section of the repo's `.claude/work-on.config.md`, the same
single source `work-on` and `handoff` read. Parse it with `pipeline_repo_board()`
(`../checks/board.php`), which returns the same three states, for the same reason, as
`pipeline_repo_checks()`:

| State | Meaning | Behaviour |
|---|---|---|
| `absent` | no `## Board` section, or the untouched template scaffold, and no board-only key anywhere else | not adopted — skip the status move **silently**, exactly as if this section did not exist. The run says nothing about a board |
| `valid` | all five of `org`, `number`, `project-id`, `status-field-id`, `in-progress-option-id` are filled in | move the item to **In Progress** |
| `invalid` | a typo'd heading, an unknown key, or a half-filled section | **machinery failure — halt.** `error` carries the reason |

`absent` and `invalid` are different states here for exactly the reason they are under §Mechanical
checks: a status move that silently stops happening is indistinguishable from a repo that never had
a board, and the run believes it is covered either way.

**`absent` is silent, and that is the deliberate half.** §Mechanical checks already sets this rule
for a repo that has not adopted them — *"behaves exactly as it did before, with no mention of
checks"* — and a board is the same kind of opt-in. A repo that has no board has not failed to do
anything, so a line reporting that it has no board is noise on **every run in that repo, forever**;
it also invites the next reader to treat a deliberate non-adoption as a gap to close. The states
that *do* speak are the ones where something happened or should have: `valid` reports the move it
made, `invalid` halts and says why. Silence is reserved for "this does not apply here", never for
"this failed".

```bash
ITEM_ID=$(gh project item-add <board.number> --owner <board.org> --url <html_url> --format json --jq '.id')
gh project item-edit --id "$ITEM_ID" \
  --project-id <board.project-id> \
  --field-id <board.status-field-id> \
  --single-select-option-id <board.in-progress-option-id>
```

Both calls are idempotent: `item-add` returns the existing item id when the issue is already on the
board, and an item already In Progress is a no-op worth one line in the kickoff summary. Failing to
*record* the claim is an annotation, never a halt — the run's work is unaffected by a board that
would not answer.

**Nothing from this section enters the manifest except the issue number.** The board status is
recomputable from the board, and `manifest.md` is explicit that storing a recomputable field is a
latent drift bug. The issue number is a pointer, which is what the manifest is for.

## Kickoff — resolve the worktree, then start the loop

**In `autoflow`, kickoff is one tested command** that does §The work item and this section in one
call and leaves the session nothing to judge:

```bash
php "$CHECKS/dispatch_cli.php" kickoff <primary checkout> <number | "<idea>"> [--medium|--light] [--base <branch>] [--decision "<verbatim>"]…
```

It runs the declared `worktree.create` as declared, from the primary checkout, with only `<branch>`
substituted (and `--base` appended on a run with a base, below): slot choice belongs to the repo's
script, and a declared command that still needs a value computed (`<next-free-N>`) is a halt
naming the config line. A create that fails — a sandbox refusal, a script that asks a question, a
stale path — halts with the command's output, and nothing is retried in another form. A classifier
only ever sees the kickoff call itself: a denial of it reaches the session before any PHP runs, and
is reported like a halt. An existing branch for the
item halts too (for an issue, any branch under `branch.issue` cut at `<slug>`): resume that run with
`launch`. After the create it unsets the new branch's upstream, keeps the manifest out of git, writes
the first manifest (below) and claims the board last. The create runs through `sh -c` behind the one
kickoff call, so an allow rule for `dispatch_cli.php kickoff` is an allow rule for whatever
`worktree.create` declares. `interactive` follows the rest of this section by hand.

**A run on a base.** `--base <branch>` cuts the run from a long-lived integration branch instead of
the default branch, diffs against it and opens its PR into it: for work that must reach the default
branch in one go, such as a set of issues whose deploy operations may not run in production in
between. Where a repo mid-rewrite declares a `--base` in its `worktree.create` for every run (below),
this one is per run.

- **Before anything exists** kickoff fetches `+refs/heads/<base>` from origin into `origin/<base>`. A
  base that holds characters a shell would read, that is not a branch on origin, or that is origin's
  default branch (`git ls-remote --symref origin HEAD`) halts, leaving nothing behind.
- **The create gets `--base origin/<base>` appended** to the declared command; the repo's script
  resolves it (`scripts/worktree.sh create … --base <ref>`). Not a `<base>` placeholder in the declared
  command: that command is shared with `work-on` and `orchestrate`, which substitute only `<branch>`, so a
  placeholder would reach the script literally from each of them; and a base belongs to a run, not to
  the repo. A script that takes no `--base` fails, and its output is the halt. A create declared with a
  repo-level `--base` gets a second one; the script decides which wins, and the check below catches it.
- **After the create** kickoff checks that the worktree's `HEAD` equals `origin/<base>`: a script that
  ignored the flag halts there, naming the worktree it left, rather than handing every leg a branch cut
  from the wrong code. Then it sets `git config branch.<branch>.gh-merge-base <base>`, so `handoff`'s
  `gh pr create`, which passes no `--base`, opens the PR into the base (gh reads that config when
  `--base` is absent), and writes `base` into the first manifest. `handoff`'s brief then has it check the
  PR's `baseRefName` and retarget with `gh pr edit --base` when it differs: a gh that ignores the config,
  or a PR that already existed, would otherwise open into the default branch and look healthy on every
  leg after it.
- **Every leg after it** reads the base from its brief (`pipeline_brief_state()`), and every
  `origin/<base>` diff, `run_audit.php`'s diff and the CI gate's fix round use it (§The loop). `launch`
  needs no flag for it: the manifest carries it, and a leg that changes `base` is a return that does not
  hold (it is not in `pipeline_leg_writable_keys()`).
- **A merge into the base closes no issue**: GitHub closes issues only on merges into the default
  branch. The finish step still settles the closing links (§Closing links); the issue closes when
  someone closes it (`orchestrate` does that for its batch), or through the base's own PR into the
  default branch.
- **The pipeline never opens the base's own PR** into the default branch: that PR is the owner's.

The whole run lives in **one worktree**; the pipeline ensures one exists, creating it if the
current checkout isn't already it. Derive the starting point from the invocation:

- **From an idea** (no branch yet) → slugify the idea into a branch name — lowercase, replace
  each non-`[a-z0-9]` run with `-`, strip leading/trailing `-`, truncate ~50 chars at a word
  boundary, prefix `feature/` — then create the worktree for it:
  - **slot-enabled project** (has `scripts/worktree.sh`): run the repo's **declared**
    `worktree.create` command from `.claude/work-on.config.md`, substituting the branch. Only
    when the repo declares no config, fall back to the bare `scripts/worktree.sh create
    <branch>`. **Never assemble that command yourself when one is declared** —
    `worktree.create` is where a repo records the flags its own slots need, and a repo
    mid-rewrite declares a `--base <ref>` there because its current work lives on a long-lived
    integration branch while `origin/HEAD` still names the pre-cutover default. Dropping the
    flag cuts the run's branch from the wrong code, and every leg after it looks healthy. (For one
    run's own base, see *A run on a base* above.) This is `work-on`'s own rule too (use the
    configured command, "never `git worktree add` by hand"), and the pipeline invokes stations
    rather than reimplementing them.
  - **otherwise** (e.g. this config repo): a plain feature branch in place — `git switch -c
    <branch>` or a harness-native worktree under `.claude/worktrees/<branch>`.
  - **headless with no such machinery and no consent** → stop and report; never mutate the
    primary checkout unattended.
- **From an `issue#`** → the branch name comes from the repo's `branch.issue` pattern in
  `.claude/work-on.config.md` (e.g. `feature/issue-<number>-<slug>`), **not** from the slugifier
  above — a run and a `work-on` session on the same issue must land on the same branch name, or
  the second one silently opens a second branch for one issue. Then create the worktree exactly
  as above.
- **From a spec-path or `pr#`** → the branch is known (the spec's branch; the PR's `head.ref`) →
  create the worktree for it, or use the current checkout if you are already on it.
- **Already launched inside a claimed feature worktree** → use it; create nothing.

Record the `worktree` absolute path in the manifest so every leg and every resume operates in
the right place. A resume locates the run's worktree via `git worktree list` for the branch.
**One worktree for the entire run** — `implement` reuses `work-on`'s logic but **not** its slot
claim, so no *second* slot ever appears mid-chain. **Never torn down mid-run; torn down after the
merge** by the session that created it (§After the merge).

**Keep the manifest out of git before writing it.** Many repos do not ignore `.claude/`, and a
manifest that git can see would be committed by a stray `git add -A` and would change §Suite reuse's
tree key on every write. At kickoff, before the first `manifest_write`:

```bash
CHECKS="$HOME/.claude/skills/pipeline/checks"   # the skill's checks, whichever repo the run is in
php -r 'require $argv[1] . "/suite.php"; pipeline_exclude_manifest(getcwd());' "$CHECKS"
```

It adds `.claude/pipeline/` to the repo's shared `info/exclude`. That file is local, never pushed and
shared by every worktree, so the PR diff stays clean. It does nothing when the path is already
ignored.

**The first `manifest_write` carries everything no step will look up.** Kickoff — the
session that holds the invocation, `/pipeline` itself or `orchestrate` for its runs — writes
`branch`, `worktree`, `mode`, `base` when the invocation named one, `cursor: {leg: design, status:
pending}`, `tier` when the invocation named `medium` or `light` (`tier: "medium"` / `tier: "light"`; nothing for no word, which is `full`), the pointers `artifacts.idea` / `artifacts.issue` when the invocation named
an idea file or an issue, and `decisions` (verbatim) when settled decisions were stated inline.
Nothing that runs the loop, in any mode, reads an artifact to recover any of these, so a field
kickoff leaves out is simply absent from every brief: a `medium` or `light` run would get an Architectural design
brief, and inline decisions would never reach a reviewer.

## After the merge — the run removes its own slot

The worktree a run created is the run's to clean up, not the owner's: a finished run that leaves its
slot standing hands the owner a chore per PR. Only the slot **this run created** at §Kickoff; never a
checkout the run was launched inside, never another session's slot, never before the PR is `MERGED`.
A run started by `orchestrate` is covered by its own step 6 — this section is for a standalone
`/pipeline`.

1. **Arm the watch** as the run's report goes out (ready PR or halted-after-`handoff`): one background
   Bash per PR, exactly `orchestrate`'s §Watch "awaiting merge" loop
   (`../../orchestrate/references/commands.md`). It polls `gh` every 5 minutes in a shell, so it
   costs no tokens while it waits; the session wakes once, on the change.
2. **On `MERGED`**, run `orchestrate`'s §Teardown checks and removal as written there (clean, `HEAD`
   equals the merged `headRefOid`, no owner, then the repo's declared `worktree.remove`). All hold:
   **remove without asking**, ahead of `slots`' confirm step. A check fails: ask, quoting the output.
   A session sitting inside the worktree leaves it first (`ExitWorktree` with `keep`).
3. **Closed without merge**: never torn down. Say so in one line; the owner decides.

**The watch dies with the session.** A merge the session never saw — or the owner saying "merged" —
is handled the same way the next time `/pipeline` runs in that repo: a manifest whose PR is `MERGED`
gets step 2 before anything else.

## Dev-stack readiness — pipeline-owned, no hesitation

Several legs need the worktree's stack: `implement` runs the suite after each step, and
`verify-ui` drives a real browser. **The `implement` step brings the stack up itself, first thing,
without asking** (its brief says so; `verify-ui` does the same if it is down) (`restart.sh`;
non-destructive) and leaves it running afterwards. Starting the stack is a routine owned action,
never a "shall I start docker?" prompt — `work-on` deliberately leaves stack *timing* to its caller,
and under the pipeline the step's brief *is* that caller. This is the house preference [[docker-stack-no-hesitation]]. If the stack
genuinely cannot start, that is a **hard failure** (below), not a reason to hesitate.

*Worktree now, stack later:* creating the worktree is cheap (git); the stack starts lazily, only
before `implement` — nothing is spun up merely to brainstorm.

## Stations — what each leg invokes

The pipeline **invokes** the existing skills; it never reimplements them. Leg names are exactly
`pipeline_legs()`: `design, review-plan, handoff, implement, verify-ui, review-pr`.

| Leg | Invokes | Interactive form | Autonomous form | Manifest I/O |
|---|---|---|---|---|
| **design** *(compound)* | `superpowers:brainstorming`, then `superpowers:writing-plans` for an **Architectural** design (one leg — brainstorming already tail-calls writing-plans; two legs would double-run it); for a **Bounded** design, brainstorming's Bounded path with no `writing-plans` (§Design size) | human drives the brainstorm dialogue; if brainstorming classifies Bounded without `medium` or `light`, the pipeline asks (§Design size); re-invoke `/pipeline` to continue | two steps (`pipeline_steps()`): a **spec** agent turns a tight brief into a spec **and must write the questions it would have asked plus its assumed answers into the spec**, so `/critique plan` audits exactly those assumptions; a fresh **plan** agent reads the committed spec cold and writes the plan. A Bounded design is the spec step alone (§Design size, *`autoflow`'s design*). The brief says which path is permitted: Bounded only with `medium` or `light`, otherwise Architectural | writes spec + plan pointers; the size is the spec's `**Design size:**` header, never stored |
| **review-plan** | `/critique plan` | reviewer writes a review; you read it and decide | two steps (`pipeline_step`): a **review** agent invokes `/critique plan` (in `autoflow` it applies `/critique plan`'s procedure itself: it cannot start a reviewer) and appends the review verbatim as an open `plan-approval` entry; a fresh **resolve** agent acts on it (§Resolving a review) | feeds the plan-approval gate; the project-vs-package call arrives as part of the review |
| **handoff** | `handoff pr` | — | pushes the branch, opens the **draft PR**; its PR comment is a **projection** of the manifest, not a second source of truth. References the issue **without a closing keyword** (§Closing links) — this PR carries no implementation yet | writes the PR# pointer |
| **implement** | `work-on`'s logic **in the current worktree** (no second slot) — read the item, validate against the code, execute the plan **test-first, running the suite and the repo's `static-analysis` after each step and its `format` once before the push** (§Mechanical checks), set closing-issue links (§Closing links — `review-pr` reconciles them before the PR goes ready). **Leaves the PR draft** (below); in `autoflow` it does not wait on CI (§The CI gate). The step brings the stack up itself (§Dev-stack readiness). | — | autonomous-capable; needs the stack up | updates `last_sha`, marks implemented |
| **verify-ui** *(conditional — runs only when `pipeline_triggers(...)['ui']`)* | `browser-verification` | the skill's "show me" hand-off is an interactive nicety | runs the check, writes the run's page to the **proof store** (`~/GitProjects/_proofs/<repo>/pr-<n>-<topic>/`) via `checks/proof_cli.php write` — the payload carries `nameWithOwner`, `pr` and `issue` so the page can link back to both — and posts a **text-only** record comment to the PR | records `verifyUi`; **non-skippable once triggered** |
| **review-pr** | `/critique pr` | reviewer writes a review; you read it and decide | a **review** agent invokes `/critique pr` (in `autoflow` it applies `/critique pr`'s procedure itself) and appends an open `pr-review` entry; the **finish** step (its resolve step) acts on it, runs the suite unless reused, reconciles closing links (§Closing links), rewrites the proof page, runs `gh pr ready`, and opens the page last (§The proof store). In `autoflow` the finish step leaves the PR draft, and the invoking session runs the CI gate and `gh pr ready` after `finish` (§The CI gate, §Who takes the PR out of draft) | feeds the PR-review gate; writes `issue_links` onto the entry; when the run has a proof page (`ui` fired), re-runs `checks/proof_cli.php write` with the finalised open questions and gate ledger |

## Design size — Bounded or Architectural

One chain, one set of legs and gates; only what the design leg writes is proportional to the change.
Every leg after `design` runs unchanged on either size.

| | **Architectural** | **Bounded** |
|---|---|---|
| Station | `brainstorming` → `writing-plans` | `brainstorming` on its Bounded path; `writing-plans` is not invoked |
| Spec | full design | `docs/superpowers/specs/<date>-<slug>-design.md`, ~15 lines |
| Plan | bite-sized TDD plan | `docs/superpowers/plans/<date>-<slug>.md`, ~10 lines |
| Header | none, or `**Design size:** Architectural` | `**Design size:** Bounded` |

**The size is read, never stored.** `DesignSize::fromSpec(<spec markdown>)` returns `Bounded` only for
the exact header line and `Architectural` for anything else, so every older spec keeps the full chain.

### Who picks the size — always a human

`/pipeline [interactive|autoflow] [medium|light] <idea | number | spec-path>`. The word `medium` or
`light` **permits** Bounded, and in `autoflow` names the agents tier (§Agents per step); no word means
`full`. The permit matters only while `design` has not run; on a resume the size comes from the spec
and the word permits nothing more, with a note saying so (the tier kickoff recorded still picks a
Bounded design's agents).

| | with `medium` or `light` | with neither |
|---|---|---|
| `interactive` | brainstorming runs normally; Bounded when it classifies Bounded | when brainstorming classifies Bounded, **ask** as one multiple-choice question: *"This looks like a small change: continue with a short design (Bounded), or write the full spec and plan?"* Yes → Bounded. No → tell brainstorming to take the Architectural path |
| `autoflow` | the design brief permits the Bounded path | the design brief requires the Architectural path |

brainstorming's own rule applies in every cell: *when in doubt between two paths, take the heavier
one.* A classification never selects Bounded on its own authority.

**Refuse Bounded in a package repo.** When the repo's `composer.json` `name` starts with `it4web/`,
say so and take the Architectural path. A shared package is never small.

### `autoflow`'s design — a spec step and a plan step

In `autoflow` the `design` leg is two steps (`pipeline_steps()`), so the agent that writes the plan reads
the committed spec cold, and the spec agent's exploration does not ride along into the plan (#73):

- **`design:spec`** brainstorms, commits the spec, sets `artifacts.spec` and removes `artifacts.plan`: a
  plan written for an earlier spec is not this spec's plan. It stops where brainstorming hands over to
  `writing-plans`, and commits no plan on the Architectural path. On the Bounded path it commits the
  plan as well, beside the spec where `pipeline_plan_path()` puts it, and sets `artifacts.plan`: a Bounded design is this step alone (`PIPELINE_BOUNDED_STEPS`),
  and the script skips `design:plan` on the `size` the spec step returned. It writes `artifacts` once,
  after its last commit, so a halt before that leaves the manifest calling for the spec step again.
- **`design:plan`** reads the spec and the code it points at, invokes `writing-plans`, commits the plan
  and sets `artifacts.plan`. The plan goes beside the spec (`pipeline_plan_path()`:
  `…/specs/<date>-<slug>-design.md` → `…/plans/<date>-<slug>.md`); when that file exists, from an
  earlier pass or the Bounded plan the design grew from, the step updates it in place. An answer the plan
  needs and the spec does not give goes into the spec's `## Assumptions`, committed before the plan.

Both return `size`. The next design step is read from the manifest, as `review` / `resolve` is
(`pipeline_design_step()`): `plan` when the spec is set and the plan is not, or when the newest ledger
entry is a plan return (a `plan-approval` loop-back with no `review`); `spec` otherwise. `launch` starts
there, and `brief` refuses the other step. On a loop-back the script reruns:

| what sent the run back | the script reruns |
|---|---|
| `plan-insufficient` on an Architectural design (a plan gap; `review-plan:review`'s counts too) | `design:plan` only |
| `looped-back` from `review-plan:resolve` | `design:spec`, then `design:plan` |
| `plan-insufficient` on a Bounded design (an escalation) | `design:spec`, which grows the spec, then `design:plan` |

`interactive` keeps one `design:run` step: the human designs inline, in one session.

### What a Bounded design commits

Two commits, spec then plan, as an Architectural design makes them. `handoff`'s brief names both from
the manifest, so a merge commit between or after them hides neither (§Catching up with the base).
They are named as `writing-plans` names them, the spec at
`docs/superpowers/specs/<date>-<slug>-design.md` and the plan beside it at
`docs/superpowers/plans/<date>-<slug>.md` (`pipeline_plan_path()`), so a design that grows finds its plan.

The spec:

```markdown
# <title> — design

**Design size:** Bounded

## Problem
<as found in the code; a bug is reproduced first>

## Change
<the files, and what changes in each>

## Done when
<the observable result>

## Assumptions
<autoflow only: each question that would have been asked, and the answer assumed>
```

The plan:

```markdown
# <title> Implementation Plan

**Spec:** docs/superpowers/specs/<date>-<slug>-design.md

## Test first
<the failing test, and why it can fail on the defect>

## Steps
1. <step, ending in something verifiable>
```

`review-plan` reviews both with the unchanged `/critique plan` rubric. The target is ~25 lines plus
the code they name.

### Escalation — the design grows, one way

**When to check.** Only while the spec says Bounded, by the step itself — its brief says so:
- after every commit in `implement`, and
- first thing in every later step, except a resolve step, which loops back instead (below).

On escalation the step appends the `design-size` entry and returns `plan-insufficient`; the run goes
back to `design` (`pipeline_returned()` in `interactive`, the workflow script in `autoflow`), whose brief asks for the grow form.

```bash
CHECKS="$HOME/.claude/skills/pipeline/checks"
git diff origin/<base>...HEAD > "<manifest stem>.diff"
# triggers.php loads the diff parser itself — do not require it a second time
php -r 'require $argv[1] . "/triggers.php"; require $argv[1] . "/design_size.php";
        $diff = file_get_contents($argv[2]);
        $size = DesignSize::fromSpec(file_get_contents($argv[3]));
        echo $size->escalation(pipeline_triggers($diff), pipeline_code_lines($diff)) ?? "", "\n";' \
  "$CHECKS" "<manifest stem>.diff" "<spec path>"
# empty line → stays Bounded; otherwise the printed reason is why it must grow
```

**What escalates.**
- `migration` or `auth` fires: a 15-line spec may not name what the diff contains.
- More than `DesignSize::MAX_CODE_LINES` (100) code lines, added + deleted.
- `package` does **not** escalate. In a project it is a constraint bump whose code was reviewed in
  the package's own PR; it keeps its annotation.
- **Judgement also escalates:**
  - brainstorming's ratchet upgrades the path;
  - an `autoflow` assumption turns out to change what gets built;
  - `implement` needs files or behaviour the plan did not name. The implement subagent returns
    **"plan insufficient"** instead of improvising.

**On escalation, grow the design; do not re-design it.**
1. Append a ledger entry: `{gate: 'design-size', leg: <current leg>, at, reason, outcome: 'escalated'}`.
2. The run goes back to `design`; backward navigation is always allowed. In grow form:
   - change the spec header to `**Design size:** Architectural`;
   - add a `## Grown from Bounded` section: what changed, why it grew, what already exists (described
     as state, not re-designed), what remains;
   - add the remaining steps to the plan;
   - commit the spec, then the plan.
   In `autoflow` the spec step grows the spec and the plan step adds the remaining steps
   (*`autoflow`'s design*).
3. `review-plan` re-runs over the grown spec and plan **plus `git diff origin/<base>...HEAD`**.
4. `handoff pr` re-runs; it updates the existing PR, so the resume prompt matches the grown plan.
5. `implement` continues.

**Gates count again.** `$doneLegs` is `pipeline_done_legs(gate_ledger)`, which ignores every gate pass
older than the latest escalation. The pass over the small design therefore cannot carry navigation
past the re-review.

**Once, and one way.** An Architectural spec never shrinks, and an escalation is not a loop-back:
- it does not count toward `review-plan`'s cycle bound;
- once the PR exists, bound exhaustion follows the after-`handoff` rule (§Failure policy).

In `interactive`, `pipeline_route` sends every Bounded `plan-insufficient` to `design`
without counting repeats, and relies on the grow-form brief to make the spec Architectural; `autoflow`
exempts one per run, a resume included, and counts the rest toward `review-plan`'s bound.

### A plan gap on an Architectural design — a loop-back, not a halt

A step on an Architectural spec that needs files or behaviour the plan does not name returns
`plan-insufficient` too, and does not improvise. There is no size to grow, so this is a **loop-back
of the plan approval**: the plan passed `review-plan` and turned out not to cover the change.

1. The step appends `{gate: 'plan-approval', leg: <its leg>, cycle, at, reason, outcome: 'looped-back'}`
   and returns `plan-insufficient`. A return without that entry halts. The `reason` names what the plan
   lacks. The size alone is never a gap: an Architectural plan needs no approval beyond `review-plan`'s
   (#96: a `handoff` that read the brief's plan-gap line as a rule for every Architectural spec looped a
   covered plan back for "the owner's plan approval").
2. The run goes back to `design` through the same bound as a `review-plan` loop-back
   (`pipeline_loop_back()` in `interactive`, `tables.bound` in `autoflow`): the entry
   counts toward the 2 cycles, and the third halts — before
   `handoff` with no push, after it with the PR left draft (§Failure policy).
3. `design` extends the plan, and the spec where it must say more, to cover the entry's `reason`;
   what is already built is described as state, not re-designed. It leaves the entry unchanged, with
   no `actions`: what it did goes in the spec, the plan and the reason it returns (#104: a design that
   recorded its answer on the entry halted the run at the next brief). Then `review-plan`, `handoff pr`
   (updating the existing PR) and `implement` run again, as after an escalation. In `autoflow` only
   `design:plan` reruns (*`autoflow`'s design*). `review-plan:review`'s own `plan-insufficient` is
   answered the same way, and its `design` brief carries the same plan-gap line: it is a plan return
   (`pipeline_is_plan_return()`), though no plan gap for `pipeline_done_legs()` or `run_audit.php` (#113).

The entry resets `pipeline_done_legs()` like an escalation does, so the earlier plan approval cannot
carry navigation past the re-review.

**A resolve step never returns `plan-insufficient`.** A resolve step that finds a plan gap or a Bounded
escalation returns `looped-back`: its open entry is completed and no bound is charged twice. If
`implement` then finds the plan short, it reports the gap itself. **A review step that returns
`plan-insufficient` appends no review entry**, so an escalation found after the review was written
cannot leave a stale open review behind. In `autoflow` a resolve step's schema has no
`plan-insufficient`; in `interactive` `pipeline_returned()` halts on either.

## What design proves — reading, not running

`design` writes a spec and a plan; **it does not build or run the plan's code**, in a scratch copy or
anywhere else. It confirms the signatures, APIs and paths the plan relies on by reading them, `php -l`
or grep. The plan's `Expected:` lines are predictions: `implement` proves them, test-first (§Stations),
and a plan that falls short comes back as a plan gap (§Design size).

Why (#92): before this rule every `autoflow` design in this repo built the plan's code in a scratch copy
and ran the suite there, 5–10 suite calls per design, with design peaks here of 119k–269k against
131–156k for viewiemedia designs that did not; `implement` then re-typed the same code. The plan became
a diff in prose, so `review-plan` reviewed code instead of design.

**The one exception is a probe.** When the choice between approaches hinges on whether one of them
works at all, `design` answers that one question with throwaway code: a few lines run on their own,
never the plan's code, never the suite. The question and what the probe showed go into the spec, beside
the approach they decided (owner, #92). The probe is brainstorming's *Spike* steps used as one step
inside an Architectural design, not a third design size: a Spike ends in a reported recommendation with
no spec and no plan, which a run cannot finish on, so the pipeline never classifies a work item as Spike
(§Design size). The probe's terminal state is its sentence in the spec.

**A plan carries no *Verified before writing* header.** The plans that have one are records and stay as
they are; they are not exemplars for it (§What a leg brief consists of).

## The proof store — where the visual record actually lives

**GitHub has no public API for putting an image into a PR comment.** `gh` exposes none; comment
attachments exist only via drag-drop in the web UI. So a leg that claims to attach visual proof
to a PR cannot do it, and the claim previously made here was unimplementable.

`verify-ui` instead writes a self-contained page to `~/GitProjects/_proofs/<repo>/pr-<n>-<topic>/`
— keyed by repo *and* run, because a PR number collides across the repos that share this store
as readily as a branch name ever did. The run segment is the PR number, which is what a reader
has in hand when they come looking, plus the branch's topic so the directory still names
something; a run that opened no PR keeps its branch slug, and `proof_cli.php write` adopts that
directory once a PR appears. The page carries Problem, Solution, the annotated screenshots, the
scope-qualified check result and any open questions, and links out to the PR and the issue.
`review-pr` rewrites it once more to finalise open questions and the ledger. A store-wide
`index.html` is the join from a PR back to its page.

**The payload** that `proof_cli.php write` files. This table is the schema. **An existing `run.json`
is not an example**: runs that copied the previous run's payload grew its title from 84 to 596
characters in five runs.

| Field | What it holds |
|---|---|
| `repo`, `nameWithOwner`, `branch`, `pr`, `issue`, `prState`, `mode` | where the run belongs; `nameWithOwner` makes the PR and issue references links |
| `title` | **required, at most 70 characters.** The run's name: page heading, browser tab, store index. `PR #430: service logs that follow`, not a sentence of findings |
| `headline` | one or two sentences: what was verified and the outcome. Rendered as the lead under the title |
| `problem`, `solution` | prose; blank lines become paragraphs |
| `checks` | `tests`, `staticAnalysis` (scope-qualified), `format`, `suppressions` (list) |
| `openQuestions` | list, carried verbatim |
| `ledger` | list of `{gate, outcome, note}` |
| `shots` | list of `{title, caption, route, badges}`. `title` is at most 70 characters and names the state shown ("Unreachable swarm"); `caption` says what the shot proves and has no limit |
| `shotSources` | absolute paths of the screenshots, in `shots` order; ingested into the run's `shots/` |

`write` refuses a payload whose `title` is missing or whose `title` or shot title is too long. It
prints `proof: payload rejected` and the problems on stderr, prints no page path, and files nothing.
Fix the payload and write again. Runs filed before `title` existed are named by their branch.

**The PR still gets a comment, and it is load-bearing.** The manifest is reconstructable from
git + gh (`manifest.md` §reconstruction), so the only durable evidence that this non-skippable
gate ran must live on the PR. The comment records *what* was verified — routes, states, outcome,
shot count — and no longer claims to carry the images themselves.

**The store is never load-bearing for a gate.** Failing to *capture* proof still halts the run;
failing to *file* it logs and continues. The engine never reads the store to decide anything:
deleting all of `_proofs/` changes no run's behaviour, which is what keeps a durable store
compatible with the non-goal "no persistent state not reconstructable from git + gh".

**The finished page opens itself — once, at the end.** The finish step's last action, after its final
`write`, is `php checks/proof_cli.php open <page>`, passing the path that `write` printed on stdout.
`write` runs at least twice per run — `verify-ui` builds the page, `review-pr` finalises it — so
opening from `write` would open the same page two or more times; a separate subcommand invoked once,
at completion, is the only shape that opens once. A run that **halts** after the page exists opens it
on the same rule: the session that holds the run (in `autoflow`, the invoking session after `finish`) runs `proof_cli.php open <artifacts.proof>` when that pointer is set, because a halted run is exactly the one a human is about to go looking at: one
`open`, at whatever turns out to be the run's last action.

**A run with no page opens nothing.** A backend-only run never triggers `ui`, so `verify-ui` never
ran, no `write` happened and there is no path to pass. `open` given a missing path — or none — logs
and returns 0; it is a silent no-op, never an error.

**Opening is cosmetic, weaker than every other proof policy.** Failing to *capture* proof halts a
run; failing to *file* it logs and continues; failing to *open* it does neither — `open` returns 0
on every path, including a platform it has no opener for (`open` on macOS, `xdg-open` on Linux,
nothing anywhere else). The page path reaches the store from a JSON payload, so `open` never builds
a shell string from it: it hands `proc_open()` an argv **array**, which runs without a shell at all.

- **`PIPELINE_NO_OPEN=1`** suppresses opening entirely — headless boxes, CI, and unattended batches
  where the tabs are noise.
- **`PIPELINE_OPEN_CMD`** replaces the platform default with an executable that receives the page
  path as its single argument.

**Concurrent finishes are left undamped, deliberately.** Legs run as background subagents and
several runs can finish within minutes of each other; each opens only its own page, so four finishes
are four tabs. Damping that — a lock, a debounce, a "just open the store index instead" — would
silently drop some run's page, and a reader who cannot tell *which* run was skipped is worse off
than one who closes a tab. The unattended batch that produces the burst is precisely the case
`PIPELINE_NO_OPEN=1` already covers.

## Who takes the PR out of draft — `review-pr`, never `implement`

`handoff` opens the PR **draft** and it stays draft until **`review-pr`'s finish step**. `implement`
does not run `gh pr ready`, and neither does `verify-ui`.

**In `autoflow` the finish step leaves it draft too.** The auto-mode classifier denies a workflow agent
`gh pr ready`, and it is the most consequential outward write a run makes, so it stays with the
session that answers to the owner: after the workflow returns `done` and `finish` records it, the
invoking session runs the CI gate and then `gh pr ready <pr>` (§The CI gate). The guarantee is unchanged: nothing marks the PR ready
before `review-pr`'s finish step has run.

This is not a preference; it is the same guarantee the navigation guardrail makes. `gates.md` states
that *"there is no path to a non-draft PR that has not passed `review-plan` and `review-pr`"* — and
`implement` runs **before** both `verify-ui` and `review-pr`. An `implement` that marks the PR ready
would undraft it while a triggered `verify-ui` and the whole PR review are still outstanding, which
is exactly the outcome the guardrail exists to prevent.

**The trap is inherited, so state it explicitly at the leg brief.** `work-on` marks ready at the end
of its run, and that is correct *standalone* — nothing follows it there. Under the pipeline something
does. The same applies to the prompt `handoff pr` writes into the PR comment: its template ends with
*"implementation fully done → take the PR out of draft"*, which is right for a human resuming the work
alone and **wrong** under the pipeline. The `implement` brief carries it verbatim
(`pipeline_leg_overrides()`): **"Leave the PR draft; this overrides any mark-ready instruction in the
plan, the PR comment, or `work-on`'s own logic."**

A cold-resume session that picks the PR up from its comment is outside the loop, so nothing mechanical
can stop it undrafting early — the instruction in the brief is the only control. Keep it there.

**A PR stays untested until it carries the `ci` label** — in a repo that has one; a repo without it
tests every push. Every fix pushed during `verify-ui` and `review-pr` is only tested once the label is
on, and until then `gh pr checks`, and the CI gate, read the skipped CI check as green.
`work-on`'s leg 8 adds it before the push whose CI it watches, and the `implement` brief says it as
well: in `interactive` **"add the `ci` label (`gh pr edit <pr> --add-label ci`) before the push whose CI
you watch"**; in `autoflow` before its first push, without waiting on CI (§The CI gate).

## The CI gate — CI on the PR's head commit, before `gh pr ready`

`gh pr ready` waits for CI on the PR's head commit, whichever step pushed it. Before #85 the only CI wait
was `implement`'s, inherited from `work-on`'s leg 8: `verify-ui` and `review-pr`'s finish step push
commits no step watched, and a HeaderHarbor PR went ready while CI still ran on such a commit, which then
went red unnoticed. The same wait held `implement` for about 7 of its ~21 minutes in both viewiemedia
runs (#77), while `review-pr:review` reads the diff, not CI.

- **`implement` does not wait on CI in `autoflow`.** It adds the `ci` label before its first push (§Who
  takes the PR out of draft), pushes and returns; its brief overrides `work-on`'s CI watch.
  `review-pr:review` runs while CI runs. `interactive` keeps `work-on`'s watch.
- **One gate, in the session that runs `gh pr ready`:** the invoking session once `finish` prints `done`
  in `autoflow`, the finish step in `interactive`. `dispatch_cli.php ci <manifest> --poll <n>`
  (`../checks/ci.php`) reads the worktree's `HEAD` (`git rev-parse HEAD`; a git error halts at once), then
  the PR's head commit and its checks once (`gh pr view <pr> --json headRefOid,statusCheckRollup`), writes
  nothing, and prints one JSON line. GitHub's head has to be the worktree's `HEAD` before its checks count
  (#99): a push that failed or was skipped leaves an older head whose CI can be green, and the PR would go
  ready without the last fix. That comparison comes first, so neither a green nor a red on an older
  commit counts:

| Verdict on the head commit | Answer |
|---|---|
| `mismatch`: GitHub's head is not the worktree's `HEAD` | `wait`; `halt` at the third read, naming both shas: a push GitHub shows within seconds, and one it does not show by then did not land |
| `green`: every check finished `SUCCESS`, `NEUTRAL` or `SKIPPED` | `ready` |
| `none`: no check at all | `ready`; with `.github/workflows/*.yml` or `*.yaml` in the worktree only from the third read, since GitHub registers a push's checks seconds after it |
| `pending` | `wait`; `halt` at the 120th read (an hour at 30 s) |
| `red`: any other conclusion, or a status in `FAILURE` or `ERROR` | `fix` the first time in a run; `halt` once that round is spent |
| `unreadable`: `gh` failed | `wait`; `halt` at the 120th read |

A skipped check reads green, so the `ci` label has to be on before the push (§Who takes the PR out of
draft). The session polls the gate in one background Bash and waits for its completion notice:

```bash
poll=1; while answer=$(php "$CHECKS/dispatch_cli.php" ci <manifest> --poll $poll); echo "$answer" | grep -q '"action":"wait"'; do sleep 30; poll=$((poll + 1)); done; echo "$answer"
```

- **`ready`** → `gh pr ready <pr>`.
- **`fix`** → one automatic fix round (owner, #85). The answer's `decision`, `CI red on the PR's head
  commit <sha>: <check> failed (<link>)`, goes into `decisions` verbatim with the re-arm:
  `git -C <worktree> diff origin/<base>...HEAD > "<manifest stem>.diff"`, then
  `launch <manifest> "<manifest stem>.diff" --from review-pr --decision "<its decision>"` and a new
  `pipeline-autoflow` workflow. The review step reads the failing job's log and states the failure as a
  finding; the finish step fixes it, or shows it unrelated (the same failure on the base branch, or a
  flake whose failed jobs it reruns without waiting); then `finish`, and this gate again.
- **`halt`** → `finish <manifest> '<the answer>'`: the answer names `review-pr` and its reason, so
  `finish` records the halt there. The PR stays draft, and §Failure policy's duties after `handoff`
  follow. A red after the round halts: a decision that starts `CI red on the PR's head commit` is the
  round spent, once per run, so a resumed run halts on its next red too. An empty answer (a usage error)
  is a halt as well. After a halt on the hour, or on a `mismatch` once the heads match, nothing needs
  re-reviewing: run the loop again by hand on the halted manifest (`ci` is read-only and refuses only a
  retired mode, a missing PR or a worktree whose `HEAD` git cannot read) rather than `launch`, which would
  re-run `review-pr:review`.
- **In `interactive`** the finish step runs the same loop, `gh pr ready` on `ready`, and shows any other
  answer to the human; there is no automatic round.
- **The merge watch stays on `state`** (§After the merge): once the PR is ready, CI on its head has
  settled.

## Closing links — settled at `review-pr`, never assumed

A PR auto-closes an issue on merge **only** if that issue sits in its `closingIssuesReferences`,
which a closing keyword in the body (`Closes/Fixes/Resolves #N`) or a manual *Development*-panel
link populates. Two failure modes follow, and a run that opens a PR at `handoff` and finishes it at
`review-pr` can produce both:

- **Unintended non-close** — the run delivered the whole issue, no closing link exists, and the
  issue sits open and orphaned after the merge.
- **Unintended close** — a closing keyword is present while the delivery is incomplete, so the
  merge closes work that is still running.

**At `handoff` the answer is always "do not close".** That PR carries a spec and a plan and no
implementation, so a `Closes #N` in its body would close the issue on merge for work nobody has
written. Reference the issue with a non-closing form — `Part of #N` / `Refs #N`. This is not a
preference: it is the same defect `/critique pr`'s own rubric names, *a PR with only a spec and a
plan that closes the issue on merge while nothing is built*, and a run should not hand its reviewer
a finding it created itself.

**At `review-pr`'s finish step, before `gh pr ready`, reconcile — this is the last moment it can be settled.**

1. **Read what will close:**
   ```bash
   gh pr view <pr> --json closingIssuesReferences \
     --jq '.closingIssuesReferences[] | "#\(.number) [\(.state)] \(.title)"'
   ```
2. **Decide what should close.** Per related issue, tag each in-scope acceptance point *delivered*,
   *deliberately dropped* (an explicit, documented decision) or *still TODO*. The issue should close
   iff every point is delivered or deliberately dropped — a deliberate drop does not block closing;
   one still-TODO point does. A parent issue closes only when all of its child scope is delivered.
3. **Correct the body** where the two disagree. Add `Closes #N`, or replace the keyword with
   `Part of #N`. **One keyword per issue** — after a single keyword, a bare `#2` in `Closes #1, #2`
   closes only `#1`. Fetch the body and edit it; never blank it:
   ```bash
   BODY=$(gh pr view <pr> --json body --jq '.body')
   gh pr edit <pr> --body "<$BODY with the closing keywords corrected>"
   ```
4. **A manual Development-panel link cannot be removed via `gh`.** Editing the body will not clear
   it. Surface it as a prominent annotation asking for it to be unlinked in the UI, and continue —
   the run cannot fix it, and halting over it would strand a finished PR.
5. **Record the outcome per issue** in the `pr-review` ledger entry's `issue_links`
   (`manifest.md`) and in the PR body: *closes on merge* / *stays open (still TODO: …)* /
   *deliberately dropped — closes anyway*.

**A mismatch is corrected, not escalated.** The finish step can see what the run built — that is the one
judgment it is best placed to make — so this never interrupts a run in either mode. What it must
never do is leave the outcome implicit: an issue that closes by accident and an issue that closes
by decision are indistinguishable after the merge, which is the whole reason this step is written
down.

**A run on a base** (§Kickoff) reconciles the same way, but its PR goes into the base, and a merge
there closes nothing: record each issue's outcome as it would be on the default branch, and say in
the PR body that the merge into `<base>` closes nothing and the issue closes when someone closes it, or
through the base's own PR (`orchestrate` closes it after the merge).

**A run that never had an issue skips this section silently** (§The work item) — there is nothing
to reconcile. That is not the same as a run *with* an issue whose PR carries no closing link: the
reconciliation ran there and produced an answer, so it is reported like any other outcome.

## What a leg brief consists of

Every brief is generated by `pipeline_brief($manifest, $leg, $manifestPath, $step)`
(`../checks/brief.php`); nobody writes one by hand — not the invoking session, not a leg, not a
coordinator. In `interactive` `next` writes it to `<manifest stem>.brief.md`
and the dispatch prompt is one line naming that file; in `autoflow` the step prints its own with
`dispatch_cli.php brief`. A brief consists of:

- **pointers** to the artifacts (idea, spec, plan, PR, issue, proof page), the manifest, and — on a
  resolve step or after a loop-back — the ledger entry to act on, by index;
- **the settled decisions** (`decisions`) and the manifest state the step needs, including §Suite
  reuse's last suite tree and the permitted design size on `design`;
- **the overrides for that leg and step** from `pipeline_leg_overrides()`, pointing at the section of
  this file that holds each rule, e.g. *"leave the PR draft"* (§Who takes the PR out of draft); on a
  step that writes to the branch, first among them the order to merge the base when the branch fell
  behind it (§Catching up with the base);
- **the return contract**: which keys the step may write and which statuses it may return;
- **nothing a station does not ask for.** No test policy, proof format or process of anyone's own
  invention.

**An `autoflow` brief adds what a workflow agent needs** (`pipeline_leg_overrides('autoflow')`): a
review step applies `/critique`'s procedure itself — Stage 0, Stage 1 and the rubric — because it
cannot start the reviewer, so `--verify` and `alternatives` are unavailable; `review-plan`'s resolve
step has no independent read; `implement` executes the plan inline, with no subagents, and does not wait on CI; the
finish step pushes and leaves the PR draft; every step works from `cd <worktree>` and is told the owner authorised the run;
and `## Return` asks for a structured `{status, reason}` instead of a line.

**A review step's brief is crafted context** (`../../critique/SKILL.md` §Reviewer contract): pointers,
decisions and overrides — never an earlier review, an earlier action, or another step's output. A
re-review of the PR names the commit the last completed review saw, never that review (§Scoped re-review).

**Plans and specs committed before 2026-09-14 are not exemplars** for test or proof policy. Many carry
the rules below, and a design subagent that reads them as examples copies the rules forward. No plan is
an exemplar for a *Verified before writing* header either (§What design proves).

Three rules briefs invented, measured over 70 runs and retired:

| Invented rule | What it cost | Instead |
|---|---|---|
| *"EVERY new assertion must be MUTATION-PROVEN"*, with hash checks and a `*.proof.md` write-up | 4–9 filtered test runs per run plus the write-up; most of what `review-plan` then integrated on small PRs policed it | A test written first has been seen red: that is the proof. Mutation-prove only a test written **after** the code (a test on existing behaviour that could not fail, a test added during review fixes). No proof documents; two lines in the PR body |
| *"Measure your OWN suite baseline first"* | a full suite before any change (one run: 531 s + 179 s) | §Suite reuse: no baseline; a red suite is a failing step |
| Status checks to a running subagent (*"are you still working?"*), sent minutes after dispatch | no reviewer finished sooner; each interrupts a turn | Wait for the completion notification. Check liveness only on a suspected stall: an agent past its usual upper end (~11 min for a `/critique` reviewer). Never dispatch a second agent for the same task |

## Mechanical checks — the deterministic layer inside `implement`

Opt-in per repo. A repo declares its checks in a **committed** `## Checks` block in
`.claude/work-on.config.md`; `pipeline_repo_checks()` (`../checks/checks.php`) parses it and returns
one of three states. The block must be committed because the run's worktree is built from git — a
config written only in the primary checkout is invisible to every run, and deleting the block is a
de-adoption that `review-pr` should see.

| State | Meaning | Behaviour |
|---|---|---|
| `absent` | no `## Checks` section, and no check-shaped keys anywhere | not adopted — `implement` behaves exactly as it did before, with no mention of checks |
| `valid` | section present, every declared key parses to a non-empty command | run the checks |
| `invalid` | malformed: heading typo, unknown or mis-cased key, empty value, empty section | **machinery failure — halt.** `error` carries the reason |

`absent` and `invalid` are deliberately different states. Collapsing them would let a typo'd heading
disable the checks permanently while the run believed it was covered.

**Invocation.** Each command is passed through `pipeline_expand_slot($command, $slotSuffix)` before
running. `<N>` is the run's slot **suffix** — empty on the primary stack, `-2` / `-3` … in a slot —
taken from the slot already resolved for the worktree at kickoff. A hardcoded container name execs
the *primary* stack and analyses the *primary* checkout, reporting no findings and passing green on
code the run never touched.

**What runs, and when.** After each plan step: the test suite (skipped when §Suite reuse finds this
tree already green), then `static-analysis` over the whole declared scope.
`format` runs **once per `implement` step**, over the whole tree, when the step's code is complete:
before its last suite run, so the recorded `suite` covers the formatted tree (a Pint change after the
suite changes the tree key and costs a second full suite at `review-pr`), and before the push, with
what it changed committed. A change after it (the fix for a red suite or a finding) runs it once more.
**No file lists and no diff-scoping** for `static-analysis` — measured
on Deploy, scoping to two files costs 4.7s against 11.1s for all of `app/` because the analyser's
bootstrap is a fixed ~4.5s floor, and paying that 6.4s removes host→container path mapping,
touched-file tracking, and any need for a pre-ready backstop.

**Pint's cache makes every call after the first cheap.** Measured on viewiemedia (#79: 1299 files,
Pint 1.32), a whole-tree run takes ~39 s with an empty cache and ~1.3 s with a warm one. Pint keeps
its cache in the container's temp dir without being told to, and viewiemedia mounts `/tmp` on a named
volume, so only the first call in a fresh stack pays. A changed-files list would save that one call
and nothing after it, so there is none.

The formatter runs over the whole tree because `--dirty` needs a git repository inside the analysed
tree, which the container does not have. That only behaves well once the repo has taken its one-off
blanket format commit, so **that commit is a prerequisite for declaring `format`**.

**Two failure kinds, and only one of them is this file's "hard failure":**

| Kind | Trigger | Response |
|---|---|---|
| **Check failure** | the checks report a finding **in a file this change touched** | the **step is not done**. Fix and re-run, bounded to 2 attempts; on the third, the bound-exhaustion halt (§Failure policy). A finding on its own is not a halt — it is exactly how a failing test behaves |
| **Machinery failure** | probe returns `invalid`, the container is missing, the command errors, the tool is not installed | **halt**, per §Failure policy |

A reported finding in a file the change did **not** touch is an annotation on the PR, not a blocker:
other write paths (plain `work-on`, direct commits, a colleague's merge) reach the same repo without
running checks, and hard-failing a run for someone else's finding leaves it no legal move.

**Suppression is bounded.** Where a finding genuinely cannot be resolved, `implement` may add
`@phpstan-ignore <identifier>` — never the bare form, which suppresses every error on the next line
including future real ones — with a justification comment. **More than two suppressions in one run
triggers the bound-exhaustion halt** (§Failure policy), because the agent whose step is blocked is
otherwise judging its own excuse.

**The result is recomputed at leg start, never stored.** Both the check result (a re-runnable
command) and the suppression count (grep-able from the diff) are recomputable, and `manifest.md` is
explicit that storing a recomputable field is a latent drift bug. Nothing about checks enters the
manifest.

**Into `review-pr`.** The review step states the result, in its `/critique pr` invocation, **qualified by the analysed scope** — "0 new
findings over `app/`", never an unqualified "0 new findings", since the declared scope does not cover
`database/`, `routes/`, `config/` or `tests/`. Any suppressions added during the run are listed and
flagged as **not yet judged**, so one cannot enter reading as already resolved.

## Suite reuse — once per tree

A full suite run proves something about the **content** it ran over, not about a commit. Every point
that runs the full suite asks first:

- after each `implement` step,
- after review fixes,
- before `review-pr`.

```bash
CHECKS="$HOME/.claude/skills/pipeline/checks"
MANIFEST="<the manifest path the brief names>"
TREE=$(php -r 'require $argv[1] . "/suite.php"; echo pipeline_tree_key($argv[2]);' "$CHECKS" "<worktree>")
# manifest `suite` is {tree, outcome: green|red, passed, failed, at}
php -r 'require $argv[1] . "/suite.php";
        $manifest = json_decode(file_get_contents($argv[2]), true);
        exit(pipeline_suite_needed($manifest["suite"] ?? null, $argv[3]) ? 0 : 1);' "$CHECKS" "$MANIFEST" "$TREE" \
  && echo "run the suite" || echo "reuse: this tree is already green"
```

- **The key.** `pipeline_tree_key()` is the tree the working copy would commit right now, untracked
  non-ignored files included, built in a temporary index so the real one is untouched. Committing
  content that was already tested keeps the key, so a run before `git commit` counts for the commit.
- **Record.** After every full run, write `suite: {tree, outcome, passed, failed, at}` to the
  manifest. Only `green` is ever reused.
- **The reviewer is told.** `pipeline_brief()` states, from the manifest, *"full suite green over tree `<tree>` at
  `<sha>`: N passed"*. Whether to re-run stays the reviewer's call.
- **No baseline.** No suite runs before the change. A red full suite is a failing step, fixed and
  bounded like any other.
  - When the leg believes a failure predates the change, that is a **machinery failure → halt**
    with the evidence (§Failure policy), never an annotation.
  - Never switch the run's worktree to the base commit to compare: under a running stack that
    desyncs vendor, migrations and assets, and a wrong red would be filed as pre-existing.
- **Failure to compute the key** (`pipeline_git` throws) is a machinery failure. Run the suite; never
  assume reuse.

## Catching up with the base — a run merges its base into its own branch

A run keeps its own branch current with its base by a plain merge, and does not halt on "behind". Why
(#124): in one `/orchestrate` batch on IT4WEBBV/Deploy (#456–#461, 2026-09-29/30) a run fell behind its
base four times, and each time it halted for an owner answer and a relaunch. The global `CLAUDE.md` told
every step not to merge on its own initiative, no brief said who merges or when, and a step that noticed
improvised: a rebase in one run, a halt in the next.

**Code decides whether, the step merges.** On a step that writes to the branch (`PIPELINE_CATCH_UP_STEPS`
in `../checks/brief.php`: `design`'s steps, both resolve steps and `implement`), `pipeline_base_state()`
fetches the base (`origin/<manifest base>`, else `origin/HEAD`) and compares:

| The branch | The base | The brief |
|---|---|---|
| is not behind | | no line |
| holds code | moved only in files the branch does not change | no line: CI tests the PR's merge ref |
| holds code | moved in a file the branch changes | the line, naming the shared files |
| holds nothing, or only its spec and plan | moved at all | the line: a design is written by reading the code, so it reads current code |

Any git call that fails gives no line: a run that cannot tell carries on, and an offline fetch is no
reason to stop. The fetch runs from PHP, as kickoff's does (`pipeline_kickoff_base()`): it moves only
`refs/remotes/origin/<base>`, never the working tree, which is why `brief` may fetch where it may not
merge (a merge run from PHP would hide the command from the permission layer and change the tree
`brief` reads). With an unreachable remote `brief` waits on git's network timeout, once per writing
step, and then prints the brief without the line. The review steps do not merge (a reviewer that resolves a conflict reviews its own work),
nor does `handoff` or `verify-ui`. Both modes get the line.

**The line is the step's first override** and carries the command:
`git -C <worktree> merge --no-edit origin/<base>`, run as its own command in exactly that form, because a
permission rule matches a command as typed (`README.md`, *Permissions for unattended runs*). A denied
command is a halt naming it, never a reshaped command.

- **The merge comes first**, on the clean tree the previous step left, then the step's own work.
- **Outside the review's and the plan's bounds.** The merge and its conflict resolutions are the
  base's changes, not the step's: they fall outside a resolve step's *"Change nothing the review did
  not name"* (§Resolving a review), and a file only the merge touched is no plan gap and no
  `plan-insufficient` for `implement`.
- **Conflicts.** Resolve each file keeping both sides' intent, leave no conflict marker behind,
  `git -C <worktree> add <file>`, and conclude with `git -C <worktree> commit --no-edit`
  (`git merge --continue` needs an editor, which a step does not have). Where keeping both sides is a
  product decision, the two changes wanting opposite behaviour: `git -C <worktree> merge --abort` and
  return `halted`, quoting the conflicting hunks. After `handoff` the reason goes into the PR body as
  for any halt (§Failure policy). The owner's answer comes back as a `--decision` on the relaunch, and
  that step's brief asks for the merge again.
- **The suite.** Nothing new: a merge changes the tree, so §Suite reuse finds no green run for it and
  the step's next full run covers the merged tree. `implement` merges before its first plan step;
  `review-pr`'s finish step runs the suite after its merge. A design step's merge runs none: the branch
  has no code of its own to test.
- **The record.** When `artifacts.pr` is set, add one line per merge to the PR body, under a
  `## Base merges` heading created once: the base and its sha, the commit count, and per conflicted file
  how it was resolved (*clean* when there was none). Edit the body as §Failure policy does
  (`gh pr view --json body` into a file, append, `gh pr edit --body-file`), never blanking it. A resolve
  step also names the merge in its entry's `actions`. Before a PR exists the merge commit is the record.
  A branch without a commit of its own fast-forwards: no merge commit, nothing to record.
- **No rebase and no force-push, anywhere in a run.** §Scoped re-review treats a rewritten history as
  "review everything again".

**No boundary check verifies that a step merged.** The state is recomputed at every writing step's
brief, so a step that skipped the merge leaves the next one the same line. A merge made by `implement` or
a design step is reviewed with the rest of the diff; one made by the finish step gets its own review
round at the gate (§The CI gate, *A merge the review did not see*).

**`handoff` takes the spec and the plan from the manifest.** `handoff pr` finds them in the last two
commits, and `git log --name-only` lists no files for a merge commit: a merge by `design:plan` between
the spec commit and the plan commit, or by `review-plan`'s resolve step directly before `handoff`,
leaves its spec empty. Its fallback takes the newest file by date and asks on several of one date,
which a merged batch base supplies, and a question in an unattended step is a halt. So `handoff:run`'s
brief names `artifacts.spec` and `artifacts.plan` and tells the step to skip the detection.

**What this does not catch.** A base that changed only files the branch does not touch is not merged,
even where the branch's code depends on them: the blind spot §Scoped re-review names, covered by CI on
the merge ref. A design grown after code exists (a plan gap) is measured by the branch's own files, not
by the files the grown plan names; the step that writes that code catches up at its next brief.

## Scoped re-review — a review of the PR after a completed one reads what changed since

A run re-entered at `review-pr` (`launch --from review-pr`: an owner's request on a ready PR, the CI
gate's fix round) starts with a review step. Once the PR has passed a review, that step reviews what
changed since, not the whole PR again. Why (#88): on IT4WEBBV/Asimo PR #183 the change since the first
review was 4 files / 13 lines of a 16-file / 1493-line PR, and each of three relaunches' review steps
peaked at 156k–208k context. A cheaper model lowers the price per token; only the target lowers the tokens.

**The review step records what it saw.** Its entry carries `reviewed_sha`, the output of
`git rev-parse HEAD`. A `review-pr` review step whose entry lacks a 40-character one halts, and so does a
resolve step that adds, changes or removes it (`pipeline_ledger_problem()`, `manifest.md` §`gate_ledger`).

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

What the settled decisions ask of the PR (an owner's request, the CI round's failure) stays in the
target wherever it lies, also outside the delta. With nothing committed since the base the target is
only that: the review checks what the settled decisions ask of the PR and says the branch did not move;
it does not widen to the whole PR.

**Otherwise the review is full, as before:** no `continued` entry with a sha, a sha HEAD does not contain
(a rebase, a force-push), a base ref git cannot resolve (with no manifest `base` and `origin/HEAD` unset,
every re-review on that machine stays full; `git remote set-head origin --auto` sets it), or any git call
that fails. The scope is never narrower than git could prove textually. It cannot see a semantic
conflict: a merge that changes only files the branch did not touch lists none, even where the branch's
code depends on them. The full review had that blind spot in practice too; the suite and CI cover it.

**A run in flight when this lands halts once.** A `review-pr` review step briefed before `reviewed_sha`
existed returns an entry without one, and the next boundary halts on it. The open entry stays; a relaunch
goes on to its resolve step, and that cycle is simply never a base. It is not a bug.

**The earlier review is not carried:** the brief names its commit, never its entry (§What a leg brief
consists of). A chain of scoped reviews is as sound as the earliest full review in it; the base rule keeps
an undispositioned review out of the chain. Later, `git log --remerge-diff` (git 2.36+; one machine runs
2.33) could show a merge as only what its resolution changed, instead of the file read whole.

## Resolving a review — the resolve step acts on it

In `autoflow` a fresh resolve agent acts on each review; in `interactive` the session does, with the
human deciding (§Interactive). Both keep the edit/rework boundary below; the rest is `autoflow`'s.

**A review is prose, not a verdict.** `/critique` returns the review it wrote — no severity ranking,
no verdict enum, no structured block (`../../critique/SKILL.md`). The review step stores it verbatim in
the open ledger entry; the resolve step reads it the way a person would and acts on its own judgment.
The risk position behind that: the pipeline never merges, so every output is a PR read before merge
and the worst case is a discarded branch, while a needless interrupt costs the one thing `autoflow`
exists to protect.

**What the resolve step does with a review** — and its brief says so:

- **Act on what is worth acting on.** Edits to the spec, the plan or the code, and small code fixes,
  are integrated and committed by the resolve step. Rework — a review saying the work is
  fundamentally wrong — is not an edit; it loops back (next bullet). Record the rest —
  already-mitigated observations, notes for posterity — without an edit. **Change nothing the review
  did not name.**
- **Loop back** where the review says the work is fundamentally wrong: `review-plan` → `design`,
  `verify-ui` → `implement`, `review-pr` → `implement` (`gates.md` §Loop-backs). Bounded (§Failure
  policy).
- **Never interrupt on a finding.** Anything unresolved goes into the PR body as an open question,
  carried **verbatim**. Ambiguity buys a line in the PR, not an interrupt.
- **Log** the actions and the outcome on the open entry (`manifest.md`), projected onto the PR.
  *Overruling a reviewer is fine; overruling one invisibly is what turns a gate into decoration.*

**In `interactive` an independent read is available, and is not a routing rule.** At `review-plan` the
resolve step is judging a critique of a plan another agent wrote, with the author's framing in the
spec. So where
acting on a point is expensive and the resolve step doubts it, it dispatches a **fresh agent that never
saw the design leg**, gives it the point plus the code, and asks it to refute the claim citing
`file:line`. That is judgment exercised where it pays, not a mandatory step with an outcome enum — and
it cannot stop the run; it only informs what the resolve step does next. In `autoflow` there is none:
a workflow agent cannot start one, and its step prompt says so.

## Failure policy — what still stops

Under `autoflow` these are the only stops. **No finding stops a run.**

- **Hard failure** — a station errors: tests won't go green, a tool dies, the stack won't start,
  `work-on` hits a blocker, or a review step returns nothing after a single retry (in `interactive`
  `returned` answers `retry` once, then `halt`). → **halt.** `finish` (`returned` in `interactive`)
  writes the failure to the manifest
  (`cursor.status: halted`, `cursor.reason`); a human resumes. **No silent retry** beyond that one — a retry hides
  the failure and the machinery may be in an unknown state.
  - **A halted manifest is the one the check rejected.** When the reason names a key the leg was not
    allowed to change, or a ledger entry it rewrote (*design added actions to ledger entry 1 (plan
    gap)*: the leg, what changed and the entry), repair it from `<manifest stem>.before.json`, the
    snapshot taken at dispatch, before the next `next` or `launch`; otherwise the run resumes with the
    leg's change in place.
  - **In `autoflow`** a review step that returns nothing runs once more, on the retry entry (Opus,
    §Agents per step); a step that throws,
    any other step that returns nothing, or a station that would need an agent the step cannot start,
    halts at once. The halt reaches the invoking session as the workflow's return, and `finish` writes it to
    the manifest.
- **A Fable usage limit is not a hard failure.** `/critique` moves the reviewer to Opus itself
  (`../../critique/SKILL.md` §Stage 2). That switch is not the single retry above: a reviewer that
  then returns nothing still gets its retry, on Opus. Its record is `/critique`'s one chat line; the
  ledger entry and the PR carry the review and what was done about it, as for any review. A usage
  limit on the Opus dispatch too is the hard failure: halt, and put the reset time in the failure
  written to the manifest so the human knows when a resume can work. In `autoflow` the review step
  itself runs on Fable (§Agents per step); when it returns nothing — a usage limit in a background
  session — the script runs it once more on the retry entry, Opus (in an interactive session a usage
  limit pauses the workflow, which
  continues by itself), and a usage limit on that run too is the same hard failure.
- **Kickoff halts** (§The work item) — these fire *before* the worktree exists, so they leave
  nothing behind and there is no manifest yet to write to; report and stop.
  - **An open blocker** on the run's issue → halt in every mode. Which of wait / work around /
    pick the blocker up first applies is the human's call, not a finding to resolve.
  - **`pipeline_repo_board()` returns `invalid`** → machinery failure, same treatment as an
    `invalid` `## Checks` block. A board-less repo returns `absent` and is unaffected.
- **Bound exhaustion.** Each loop-back is bounded to **2 cycles** per gate; on what would be the
  third, **halt in-session** — stop, leave the work in the worktree, and say why. The bound is what
  keeps an autonomous loop from churning indefinitely without ever surfacing.
  - **Before `handoff`** (`review-plan`) → **no branch push, no draft PR.** Twice-rejected work is
    not worth a PR round-trip; the human reads it live.
  - **After `handoff`** (`verify-ui`, `review-pr`) → the draft PR already exists, so there is
    nothing to not-push. Leave it **draft**, append the reason to the PR body without reading it
    (`gh pr view <pr> --json body --jq .body > "$TMPDIR/body.md"`, append the reason,
    `gh pr edit <pr> --body-file "$TMPDIR/body.md"`), stop. In `autoflow` the invoking session does
    this after `finish`, and opens the proof page once when `artifacts.proof` is set (§The proof store).
  - The entry is not marked halted: the resolve step records `outcome: looped-back` as it returns,
    and `finish` halts the run through the cursor (`cursor.status: halted`, `cursor.reason`) —
    `returned` in `interactive`.
  - `pipeline_returned()` does the counting: count the cycles as the number of that gate's `gate_ledger` entries whose `outcome` is
    **`looped-back`** (`manifest.md`) — not its entries in total, which also include human-ordered
    re-reviews and would over-count into a spurious stop — and never from an in-memory counter. In
    `autoflow` the script starts from `launch`'s per-gate counts of the same entries
    (`pipeline_loop_counts()`) and adds each loop-back it routes.
  - **A count that cannot be read is not a count of zero.** The ledger lives in the disposable
    manifest, and no durable probe can rebuild it: git and gh record *that* a review happened, not
    how many times the engine looped. So a run whose manifest was **reconstructed** (`manifest.md`
    §reconstruction) carries an **unknown** cycle count, and unknown permits **no** loop-back — the
    next one halts immediately (in `autoflow`, `launch` gives such a gate the bound). Without this, a
    manifest lost mid-loop silently grants two fresh cycles, and one lost repeatedly grants them
    forever: the bound would stop bounding at exactly
    the moment it is load-bearing. A fresh run writes its own manifest at kickoff and is never
    reconstructed, so it is unaffected.
- **Mechanical-check exhaustion** (§Mechanical checks) — a check failure that survives its 2 fix
  attempts, or more than two `@phpstan-ignore` suppressions in one run → **the same
  bound-exhaustion halt.**
- **A return the checks cannot account for** — a moved cursor, a key only the engine writes, a
  rewritten ledger entry, a status the ledger does not support (`manifest.md` §What a leg writes) →
  **halt**, with the next `brief`'s or `finish`'s reason (`returned`'s in `interactive`), which also
  halt on a status, `ui` or `size` the step returned to the script that its manifest, diff or spec
  does not bear out.
- **An `autoflow` step the run cannot accept** — a status the step may not return fails the step's
  schema, and `brief` halts a step the ledger does not support (`resolve` with no open review,
  `review` with one already open, the design step the manifest does not call for) → **halt**.
- **CI on the PR's head commit red after the fix round, or not settled in an hour, or GitHub's head still
  not the worktree's `HEAD` at the third read** (§The CI gate) →
  **halt**, the PR still draft: `finish` records the gate's answer on `review-pr`, and the duties after
  `handoff` under *Bound exhaustion* apply.
- **A stopped `autoflow` workflow** — `TaskStop`, a dead session, a workflow error: `finish` with
  `{"action":"halt","reason":…}` records it; the cursor names the step that was running.
- **Playwright genuinely unavailable** → **halt.** No visual claim without proof.

In `interactive` mode every gate stops anyway, so the human sees the review and none of `autoflow`'s
resolution runs.

The scary content facts — a migration, an authorization change, a shared package — **do not stop
the chain**: they are facts, not findings, so they become loud mandatory annotations on the PR and
in the ledger (`gates.md` §content triggers). `verify-ui` is untouched by that and stays mandatory
whenever the `ui` trigger fires.

## Navigation

Once loaded in a session the engine holds the manifest cursor, so it is driven by **natural
language** — *"next step"*, *"go to step X"*, *"re-run review-plan"*, *"skip ahead to handoff"*.
A slash command is only a cold-session trigger; there is no separate `/next`. Every jump goes
through `pipeline_can_navigate(from, to, doneLegs, triggers)`: **backward is free; forward past a
gate leg that has not run is refused** (`gates.md`). That refusal is the un-skippable-review
promise made mechanical. `doneLegs` is always `pipeline_done_legs(gate_ledger)`, so a gate passed
before the latest `design-size` escalation or plan gap no longer counts (§Design size).
