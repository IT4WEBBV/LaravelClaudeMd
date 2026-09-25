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
                   … on its return: dispatch_cli.php finish → gh pr ready | halt duties → report
workflow script    per step: agent(prompt, {schema}) → {status, reason, ui, size} → next step, loop-back or return
step agent         dispatch_cli.php brief <manifest> <leg> <step> [--after …] → the leg's work → the manifest → {status, reason}
```

```bash
CHECKS="$HOME/.claude/skills/pipeline/checks"
php "$CHECKS/dispatch_cli.php" kickoff <primary checkout> <number | "<idea>"> [--light] [--decision "<verbatim>"]…
# → {"action":"ready","manifest":…,"worktree":…,"branch":…,"notes":[…]} | {"action":"halt","reason":…}
git -C <worktree> diff origin/<base>...HEAD > "<manifest stem>.diff"
PIPELINE_NO_OPEN=<1 unattended, else 0> php "$CHECKS/dispatch_cli.php" launch <manifest> "<manifest stem>.diff" [--from <leg>]
# → {"action":"start","startLeg":…,"startStep":…,"loops":{…},"ui":…,"size":…,"manifest":…,"worktree":…,"noOpen":…,"checks":…,"tables":{…}}
#   | {"action":"done"} | {"action":"halt","reason":…}
# start: the workflow pipeline-autoflow with that JSON as args, in the background; wait for its completion notice
php "$CHECKS/dispatch_cli.php" finish <manifest> '<the workflow return, as JSON>'
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
  editing a file. `checks` is the directory `launch` ran from, so every step's `brief` runs the same
  code.
  `tables` is what the script routes by, `pipeline_routing_tables()`: the legs in order, each leg's
  steps, the loop-back targets, the statuses per `<leg>:<step>` and the bound, built from the
  functions `interactive` routes by, so the script keeps no copy of them.
- **The script** gives each step a schema whose `status` allows only what that step may return
  (`tables.allowed`, from `LegStatus::allowedFor()`), continues, loops back or returns on that status,
  counts each loop-back against `tables.bound`, 2 per gate (`gates.md` §Loop-backs), and returns `{action: done}` or
  `{action: halt, leg, reason}`. Nothing ends a run as `done` except `review-pr`'s resolve step
  continuing. The design size it goes by is the one `launch` read, then the one each `design` step
  copied from its spec. A Bounded escalation is not a loop-back, and escalation is one-way (§Design
  size), so the script exempts one per run; every other `plan-insufficient` counts toward
  `review-plan`'s bound. A status it cannot route halts, and so do `args` that are not a `launch` `start` answer; `tables`
  missing or incomplete halts with a reason that names them. `AutoflowScriptTest` replays the script
  on `launch`'s answer. A review step runs on Fable, and once more on Opus when it returns nothing;
  `handoff` runs at low effort; a step that throws or returns nothing halts the run.
- **A step** first runs `dispatch_cli.php brief <manifest> <leg> <step>`, followed on every step but
  the run's first by what the step before it returned: `--after <leg>:<step> --status <status>`, plus
  `--ui` after `implement` and `--size` after `design` (§The check at the next boundary). It checks that
  return, then writes `cursor: {leg, status: pending}` — so after a `TaskStop` or a dead session the
  cursor still names the step that was running — and the snapshot `<manifest stem>.before.json`, and
  prints the brief. It prints a halt instead when the return does not hold, or when the ledger does not
  support the step (`resolve` with no open review, `review` with one already open). The step writes
  its results into the manifest (`manifest.md` §What a leg writes) and returns `{status, reason}`;
  `implement` also returns `ui`, copied from `dispatch_cli.php ui <diff>` (`pipeline_triggers()` over
  its diff), and `design` returns `size`, copied from `dispatch_cli.php size <manifest>`
  (`DesignSize::fromSpec()` over the spec it committed). Both are required on every return of their
  step and ignored on a halt; the script takes `ui` only from `implement` and `size` only from `design`.
- **`finish`** records the return: `done` sets `cursor.status: done`, but only with the cursor on
  `review-pr` — anywhere else it records the halt "the workflow returned done at <leg>" — and only when
  the last snapshot is `review-pr`'s resolve step's and that step's return holds (§The check at the next
  boundary); otherwise it records that halt. A halt sets `cursor: {leg, status: halted, reason}`,
  keeping the cursor's leg when the cursor already says `halted` (`brief` or the step wrote it, on the
  step that failed) or when the return names none of the pipeline's legs. When the workflow itself
  errored, pass `{"action":"halt","reason":"<the error>"}`: the cursor keeps the step that was
  running. When `finish` prints `done` the invoking session then runs **`gh pr ready <pr>`** (§Who
  takes the PR out of draft); on a halt after `handoff`, §Failure policy's duties.
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
the Workflow result. `run_cost_cli.php` prints per step the weighted cost, the peak context, the wall
time and the part of it spent waiting on tools, and on its `run:` line the total, the run's span in
minutes and the largest step peak. `run_audit.php` prints whether `ui` over the final diff agrees with
a `verify-ui` entry, and whether each gate's newest ledger entries agree with what the steps reported.
It stays as the after-run report: with the boundary check in place a `MISMATCH` means the check has a
hole, never a halt.

**Where a step works.** The worktree travels in the brief (*"Work only in `<worktree>`"*) and in
absolute paths, never in the launch directory: `orchestrate` launches up to four runs from its primary
checkout. Commands that key on the working directory (§Suite reuse's tree key) run from `cd <worktree>`
or with `git -C <worktree>`, as the `autoflow` brief says.

**What a workflow agent cannot do.** It cannot start agents, so no step dispatches one; its brief says
how each station's dispatch is done by the step itself. And the auto-mode classifier denies it
`gh pr ready`, so that write stays with the invoking session.

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
php "$CHECKS/dispatch_cli.php" kickoff <primary checkout> <number | "<idea>"> [--light] [--decision "<verbatim>"]…
```

It runs the declared `worktree.create` as declared, from the primary checkout, with only `<branch>`
substituted: slot choice belongs to the repo's script, and a declared command that still needs a
value computed (`<next-free-N>`) is a halt naming the config line. A create that fails — a sandbox
refusal, a script that asks a question, a stale path — halts with the command's output, and nothing
is retried in another form. A classifier only ever sees the kickoff call itself: a denial of it
reaches the session before any PHP runs, and is reported like a halt. An existing branch for the
item halts too (for an issue, any branch under `branch.issue` cut at `<slug>`): resume that run with
`launch`. After the create it unsets the new branch's upstream, keeps the manifest out of git, writes
the first manifest (below) and claims the board last. The create runs through `sh -c` behind the one
kickoff call, so an allow rule for `dispatch_cli.php kickoff` is an allow rule for whatever
`worktree.create` declares. `interactive` follows the rest of this section by hand.

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
    flag cuts the run's branch from the wrong code, and every leg after it looks healthy. This
    is `work-on`'s own rule too (use the configured command, "never `git worktree add` by
    hand"), and the pipeline invokes stations rather than reimplementing them.
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
`branch`, `worktree`, `mode`, `cursor: {leg: design, status: pending}`, `light: true` when the
invocation said `light`, the pointers `artifacts.idea` / `artifacts.issue` when the invocation named
an idea file or an issue, and `decisions` (verbatim) when settled decisions were stated inline.
Nothing that runs the loop, in any mode, reads an artifact to recover any of these, so a field
kickoff leaves out is simply absent from every brief: a `light` run would get an Architectural design
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
| **design** *(compound)* | `superpowers:brainstorming`, then `superpowers:writing-plans` for an **Architectural** design (one leg — brainstorming already tail-calls writing-plans; two legs would double-run it); for a **Bounded** design, brainstorming's Bounded path with no `writing-plans` (§Design size) | human drives the brainstorm dialogue; if brainstorming classifies Bounded without `light`, the pipeline asks (§Design size); re-invoke `/pipeline` to continue | a subagent turns a tight brief into a spec **and must write the questions it would have asked plus its assumed answers into the spec**, so `/critique plan` audits exactly those assumptions. The brief says which path is permitted: Bounded only with `light`, otherwise Architectural | writes spec + plan pointers; the size is the spec's `**Design size:**` header, never stored |
| **review-plan** | `/critique plan` | reviewer writes a review; you read it and decide | two steps (`pipeline_step`): a **review** agent invokes `/critique plan` (in `autoflow` it applies `/critique plan`'s procedure itself: it cannot start a reviewer) and appends the review verbatim as an open `plan-approval` entry; a fresh **resolve** agent acts on it (§Resolving a review) | feeds the plan-approval gate; the project-vs-package call arrives as part of the review |
| **handoff** | `handoff pr` | — | pushes the branch, opens the **draft PR**; its PR comment is a **projection** of the manifest, not a second source of truth. References the issue **without a closing keyword** (§Closing links) — this PR carries no implementation yet | writes the PR# pointer |
| **implement** | `work-on`'s logic **in the current worktree** (no second slot) — read the item, validate against the code, execute the plan **test-first, running the suite and the repo's mechanical checks after each step** (§Mechanical checks), set closing-issue links (§Closing links — `review-pr` reconciles them before the PR goes ready). **Leaves the PR draft** (below). The step brings the stack up itself (§Dev-stack readiness). | — | autonomous-capable; needs the stack up | updates `last_sha`, marks implemented |
| **verify-ui** *(conditional — runs only when `pipeline_triggers(...)['ui']`)* | `browser-verification` | the skill's "show me" hand-off is an interactive nicety | runs the check, writes the run's page to the **proof store** (`~/GitProjects/_proofs/<repo>/pr-<n>-<topic>/`) via `checks/proof_cli.php write` — the payload carries `nameWithOwner`, `pr` and `issue` so the page can link back to both — and posts a **text-only** record comment to the PR | records `verifyUi`; **non-skippable once triggered** |
| **review-pr** | `/critique pr` | reviewer writes a review; you read it and decide | a **review** agent invokes `/critique pr` (in `autoflow` it applies `/critique pr`'s procedure itself) and appends an open `pr-review` entry; the **finish** step (its resolve step) acts on it, runs the suite unless reused, reconciles closing links (§Closing links), rewrites the proof page, runs `gh pr ready`, and opens the page last (§The proof store). In `autoflow` the finish step leaves the PR draft, and the invoking session runs `gh pr ready` after `finish` (§Who takes the PR out of draft) | feeds the PR-review gate; writes `issue_links` onto the entry; when the run has a proof page (`ui` fired), re-runs `checks/proof_cli.php write` with the finalised open questions and gate ledger |

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

`/pipeline [interactive|autoflow] [light] <idea | number | spec-path>`. The word `light` **permits** Bounded.
It matters only while `design` has not run; on a resume the size comes from the spec and `light` is
ignored, with a note saying so.

| | with `light` | without `light` |
|---|---|---|
| `interactive` | brainstorming runs normally; Bounded when it classifies Bounded | when brainstorming classifies Bounded, **ask** as one multiple-choice question: *"This looks like a small change: continue with a short design (Bounded), or write the full spec and plan?"* Yes → Bounded. No → tell brainstorming to take the Architectural path |
| `autoflow` | the design brief permits the Bounded path | the design brief requires the Architectural path |

brainstorming's own rule applies in every cell: *when in doubt between two paths, take the heavier
one.* A classification never selects Bounded on its own authority.

**Refuse Bounded in a package repo.** When the repo's `composer.json` `name` starts with `it4web/`,
say so and take the Architectural path. A shared package is never small.

### What a Bounded design commits

Two commits, spec then plan, so `handoff pr` finds both in the last two commits exactly as it does
for an Architectural design.

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
exempts one per run and counts the rest toward `review-plan`'s bound.

### A plan gap on an Architectural design — a loop-back, not a halt

A step on an Architectural spec that needs files or behaviour the plan does not name returns
`plan-insufficient` too, and does not improvise. There is no size to grow, so this is a **loop-back
of the plan approval**: the plan passed `review-plan` and turned out not to cover the change.

1. The step appends `{gate: 'plan-approval', leg: <its leg>, cycle, at, reason, outcome: 'looped-back'}`
   and returns `plan-insufficient`. A return without that entry halts.
2. The run goes back to `design` through the same bound as a `review-plan` loop-back
   (`pipeline_loop_back()` in `interactive`, `tables.bound` in `autoflow`): the entry
   counts toward the 2 cycles, and the third halts — before
   `handoff` with no push, after it with the PR left draft (§Failure policy).
3. `design` extends the plan, and the spec where it must say more, to cover the entry's `reason`;
   what is already built is described as state, not re-designed. Then `review-plan`, `handoff pr`
   (updating the existing PR) and `implement` run again, as after an escalation.

The entry resets `pipeline_done_legs()` like an escalation does, so the earlier plan approval cannot
carry navigation past the re-review.

**A resolve step never returns `plan-insufficient`.** A resolve step that finds a plan gap or a Bounded
escalation returns `looped-back`: its open entry is completed and no bound is charged twice. If
`implement` then finds the plan short, it reports the gap itself. **A review step that returns
`plan-insufficient` appends no review entry**, so an escalation found after the review was written
cannot leave a stale open review behind. In `autoflow` a resolve step's schema has no
`plan-insufficient`; in `interactive` `pipeline_returned()` halts on either.

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
invoking session runs `gh pr ready <pr>`. The guarantee is unchanged: nothing marks the PR ready
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
on, and until then `gh pr checks` reads the skipped CI check as green.
`work-on`'s leg 8 adds it before the push whose CI it watches; say it in the `implement` brief as
well: **"add the `ci` label (`gh pr edit <pr> --add-label ci`) before the push whose CI you watch."**

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
  this file that holds each rule, e.g. *"leave the PR draft"* (§Who takes the PR out of draft);
- **the return contract**: which keys the step may write and which statuses it may return;
- **nothing a station does not ask for.** No test policy, proof format or process of anyone's own
  invention.

**An `autoflow` brief adds what a workflow agent needs** (`pipeline_leg_overrides('autoflow')`): a
review step applies `/critique`'s procedure itself — Stage 0, Stage 1 and the rubric — because it
cannot start the reviewer, so `--verify` and `alternatives` are unavailable; `review-plan`'s resolve
step has no independent read; `implement` executes the plan inline, with no subagents; the finish step
leaves the PR draft; every step works from `cd <worktree>` and is told the owner authorised the run;
and `## Return` asks for a structured `{status, reason}` instead of a line.

**A review step's brief is crafted context** (`../../critique/SKILL.md` §Reviewer contract): pointers,
decisions and overrides — never an earlier review, an earlier action, or another step's output.

**Plans and specs committed before 2026-09-14 are not exemplars** for test or proof policy. Many carry
the rules below, and a design subagent that reads them as examples copies the rules forward.

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

**What runs, and when.** After each step: the test suite (skipped when §Suite reuse finds this tree
already green), then `static-analysis` over the whole
declared scope, then `format` over the whole tree. **No file lists and no diff-scoping** — measured
on Deploy, scoping to two files costs 4.7s against 11.1s for all of `app/` because the analyser's
bootstrap is a fixed ~4.5s floor, and paying that 6.4s removes host→container path mapping,
touched-file tracking, and any need for a pre-ready backstop.

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
    allowed to change, repair it from `<manifest stem>.before.json`, the snapshot taken at dispatch,
    before the next `next` or `launch`; otherwise the run resumes with the leg's change in place.
  - **In `autoflow`** a review step that returns nothing runs once more, on Opus; a step that throws,
    any other step that returns nothing, or a station that would need an agent the step cannot start,
    halts at once. The halt reaches the invoking session as the workflow's return, and `finish` writes it to
    the manifest.
- **A Fable usage limit is not a hard failure.** `/critique` moves the reviewer to Opus itself
  (`../../critique/SKILL.md` §Stage 2). That switch is not the single retry above: a reviewer that
  then returns nothing still gets its retry, on Opus. Its record is `/critique`'s one chat line; the
  ledger entry and the PR carry the review and what was done about it, as for any review. A usage
  limit on the Opus dispatch too is the hard failure: halt, and put the reset time in the failure
  written to the manifest so the human knows when a resume can work. In `autoflow` the review step
  itself runs on Fable; when it returns nothing — a usage limit in a background session — the script
  runs it once more on Opus (in an interactive session a usage limit pauses the workflow, which
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
  `review` with one already open) → **halt**.
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
