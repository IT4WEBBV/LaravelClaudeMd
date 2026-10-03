# The invoking session — driving a run

The invoking session — the main session, or `orchestrate` for its runs — holds a run's loop in
`interactive` and its two edges in `autoflow`. What the code does at each command is `machinery.md`'s.

## Modes — who holds the loop

Both modes walk the same legs with the same briefs, which `autoflow` extends (`machinery.md` §What a leg
brief consists of). They differ in who holds the loop:

| Mode | Who holds the loop | Behaviour | Commands |
|---|---|---|---|
| `interactive` *(default)* | the session; the human resolves each review (§Interactive) | the human is present; run one leg, show the review, wait. Every point in it is the human's to judge. Advance by saying so (§Navigation) | `next` / `returned` |
| `autoflow` | the saved workflow `pipeline-autoflow`, a program (`machinery.md` §The workflow script) | run the legs unattended. The reviews still run; a fresh resolve step reads each and acts, looping back where the work is wrong and never interrupting on a finding (`shared/resolving.md` §Resolving a review). Hard failures and bound exhaustion still stop | `launch` / `brief` / `finish` |

`mode` is the only knob, and **anything that is not `autoflow` behaves as `interactive`** — the
stricter of the two. `manifest_validate` checks key *presence*, not value, so a manifest with a mangled
`mode` must still fail safe. There is no per-gate override: both gates behave the same way within a mode.
What no mode can do is stop a review *leg* from running (`gates.md` §Navigation guardrail).

`launch` refuses a manifest whose mode is not `autoflow`, and `next` refuses one whose mode is. Every
command, `kickoff --mode auto` included, refuses `auto` (#87) with a halt that names `autoflow`
(`pipeline_retired_mode()`). An `auto` manifest resumes once its `mode` says `autoflow`, through `launch`.

**Wait for the completion notice.** No `sleep`, `date`, file-mtime or `ListAgents` polling while a
step runs.

## Invocation

```
/pipeline [interactive|autoflow] [medium|light] [base <branch>] <idea | number | spec-path>   # start a run
/pipeline                                                                                     # resume the current branch's run
```

- **One entry point.** `/pipeline` starts a run, or — when a manifest (or reconstructable
  PR/branch state) for the current branch exists — **resumes** it after the invariant checks.
- **A number is an issue or a PR, and it classifies itself** (§The work item). There is no separate
  issue and PR syntax to remember.
- **Mode defaults to `interactive`.** `autoflow` is the explicit opt-in for an unattended run; a
  fresh `/pipeline <idea>` never runs unattended by surprise. `/pipeline auto` is refused, naming
  `autoflow` (#87).
- **`medium` or `light` permits a small design.** A Bounded design is a ~15-line spec and a ~10-line
  plan instead of a full design; every leg and both reviews still run (`steps/design.md` §Design size).
  In `autoflow` the word also names the agents tier: `medium` runs lighter agents on the design,
  review-plan, verify-ui and resolve steps, and `light` also runs cheaper models on most steps
  (`machinery.md` §Agents per step); an Architectural design or an escalation runs on `full` whatever the
  word. With neither, `interactive` asks when brainstorming finds the change small, and `autoflow` always
  writes the full design. The permit matters only while `design` has not run; on a resume the size comes
  from the spec and the word permits nothing more, with a note saying so (the tier kickoff recorded still
  picks a Bounded design's agents).
- **`base <branch>`** becomes `autoflow` kickoff's `--base`; `interactive` does §Kickoff's *A run on a
  base* by hand.
- **Navigation is natural language, not more commands** (§Navigation).
- **One guardrail on jumps.** Backward navigation is free; forward past a gate that has not run is
  refused (`gates.md` §Navigation guardrail).

## The repo config — what the pipeline reads from .claude/work-on.config.md

A repo configures the pipeline in `.claude/work-on.config.md`, the file the `work-on` skill reads too. It
is shared on purpose, so a run and a `/work-on` session on the same issue land on the same branch and the
same board. This section lists every key the pipeline reads, and the pipeline reads nothing else in the
file.

| Section | Key | Read by | |
|---|---|---|---|
| `Repo` | `repo` | kickoff (the issue lookup, §The work item), the status line, `orchestrate` | required |
| `Worktree` | `create` | kickoff (§Kickoff), with `<branch>` substituted | required |
| `Worktree` | `remove` | nothing: the teardown recognises the checkout's kind instead (`orchestrate/teardown.py`, §After the merge); accepted so a shared config parses | — |
| `Branch convention` | `issue` | kickoff: the run's branch and the check that no branch of the issue exists | required for an issue |
| `Board` | `org`, `number`, `project-id`, `status-field-id`, `in-progress-option-id` | kickoff's claim (§The work item) | all or none |
| `Board` | `component-field-id`, `component-default` | `handoff` (the PR's Component) | optional |
| `Board` | `component-alts`, `docs` | nothing: accepted so a `work-on` config parses | — |
| `Checks` | `static-analysis`, `format` | `implement`, `review-pr` (`shared/checks.md` §Mechanical checks); `<N>` expands to the slot suffix | optional, committed |

`## Board` and `## Checks` are tri-state (`absent`, `valid`, `invalid`), as §The work item and
`shared/checks.md` §Mechanical checks say. A missing required key halts kickoff, naming the key.

## The work item — resolved before anything is created

A run that carries a GitHub issue owes that issue three things: it claims it on the board, it
refuses to start on blocked work, and at the end it settles whether merging closes it
(`steps/finish.md` §Closing links). This section is the first two; all of it runs **before the worktree
exists**, because a run that must not start should leave nothing behind.

**Resolve the item first. A bare number classifies itself:**

```bash
gh api repos/<repo>/issues/<number> \
  --jq '{number, title, html_url, node_id, state, is_pr: (.pull_request != null)}'
```

The `issues` endpoint returns both, and a PR has a non-null `pull_request`, which is why
`/pipeline <number>` needs no separate issue and PR syntax.

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

Any open blocker → **halt at kickoff**, in every mode, naming the blockers: whether to wait, work around
it or pick the blocker up first is the human's call, and nothing exists yet to leave behind.

**Then the board — claim the item before the slow steps.** Board identifiers are **not** in this
skill; they live in the `## Board` section of the repo's `.claude/work-on.config.md` (§The repo config), the same
single source `work-on` and the pipeline's `handoff` command read. Parse it with `pipeline_repo_board()`
(`../checks/board.php`), which returns the same three states, for the same reason, as
`pipeline_repo_checks()`:

| State | Meaning | Behaviour |
|---|---|---|
| `absent` | no `## Board` section, or the untouched template scaffold, and no board-only key anywhere else | not adopted — skip the status move **silently**, exactly as if this section did not exist. The run says nothing about a board |
| `valid` | all five of `org`, `number`, `project-id`, `status-field-id`, `in-progress-option-id` are filled in | move the item to **In Progress** |
| `invalid` | a typo'd heading, an unknown key, or a half-filled section | **machinery failure — halt.** `error` carries the reason |

**`absent` is silent; only what happened speaks.** `valid` reports the move it made, `invalid` halts and
says why: silence means "this does not apply here", never "this failed".

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
recomputable from the board, and storing a recomputable field is a latent drift bug
(`manifest.md` §Two rules that keep the file honest). The issue number is a pointer, which is what the
manifest is for.

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
    run's own base, see *A run on a base* below.)
  - **otherwise** (e.g. this config repo): a plain feature branch in place — `git switch -c
    <branch>` or a harness-native worktree under `.claude/worktrees/<branch>`.
  - **headless with no such machinery and no consent** → stop and report; never mutate the
    primary checkout unattended.
- **From an `issue#`** → the branch name comes from the repo's `branch.issue` pattern in
  `.claude/work-on.config.md` (e.g. `feature/issue-<number>-<slug>`), **not** from the slugifier
  above — a run and a `/work-on` session on the same issue must land on the same branch name, or
  the second one silently opens a second branch for one issue. Then create the worktree exactly
  as above.
- **From a spec-path or `pr#`** → the branch is known (the spec's branch; the PR's `head.ref`) →
  create the worktree for it, or use the current checkout if you are already on it.
- **Already launched inside a claimed feature worktree** → use it; create nothing.

Record the `worktree` absolute path in the manifest so every leg and every resume operates in
the right place. A resume locates the run's worktree via `git worktree list` for the branch.
**One worktree for the entire run** — `implement` works in this worktree and claims no slot
(`steps/implement.md`), so no *second* slot ever appears mid-chain. **Never torn down mid-run; torn down after
the merge** by the session that created it (§After the merge).

**Keep the manifest out of git before writing it.** Many repos do not ignore `.claude/`, and a
manifest that git can see would be committed by a stray `git add -A` and would change the suite's
tree key on every write (`shared/suite.md` §Suite reuse). At kickoff, before the first `manifest_write`:

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

### A run on a base

`--base <branch>` cuts the run from a long-lived integration branch instead of
the default branch, diffs against it and opens its PR into it: for work that must reach the default
branch in one go, such as a set of issues whose deploy operations may not run in production in
between. Where a repo mid-rewrite declares a `--base` in its `worktree.create` for every run (above),
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
  from the wrong code. Then it writes `base` into the first manifest. `dispatch_cli.php handoff` opens the
  PR with `--base <base>` and retargets a PR the branch already had (`gh pr edit --base`,
  `machinery.md` §`handoff` in order): kickoff sets no `gh-merge-base` config, and no brief asks a step
  to check the PR's base.
- **Every leg after it** reads the base from its brief (`pipeline_brief_state()`), and every
  `origin/<base>` diff, `run_audit.php`'s diff and the CI gate's fix round use it (`manifest.md` §The
  run's files). `launch` needs no flag for it: the manifest carries it, and a leg that changes `base` is a
  return that does not hold (it is not in `pipeline_leg_writable_keys()`).
- **A merge into the base closes no issue**: GitHub closes issues only on merges into the default
  branch. The finish step still settles the closing links (`steps/finish.md` §Closing links); the issue
  closes when someone closes it (`orchestrate` does that for its batch), or through the base's own PR
  into the default branch.
- **The pipeline never opens the base's own PR** into the default branch: that PR is the owner's.

## `autoflow` — the session holds the two edges

The invoking session is an agent only at the two edges of an `autoflow` run, and runs one tested command
at each. Nothing reads a step's work in between; a halt lands in the session that launched the run, with
its reason. What each command checks is `machinery.md`'s.

```bash
CHECKS="$HOME/.claude/skills/pipeline/checks"
php "$CHECKS/dispatch_cli.php" kickoff <primary checkout> <number | "<idea>"> [--medium|--light] [--base <branch>] [--decision "<verbatim>"]…
# → {"action":"ready","manifest":…,"worktree":…,"branch":…,"notes":[…]} | {"action":"halt","reason":…}
git -C <worktree> diff origin/<base>...HEAD > "<manifest stem>.diff"
php "$CHECKS/dispatch_cli.php" launch <manifest> "<manifest stem>.diff" [--from <leg>] [--decision "<verbatim>"]…
# → {"action":"start","startLeg":…,"startStep":…,"loops":{…},"ui":…,"size":…,"manifest":…,"worktree":…,"checks":…,"tables":{…},"profile":…,"tier":…,"escalated":…,"agents":{…}}
#   | {"action":"done"} | {"action":"halt","reason":…}
# start: through the detour (step 3 below)
php "$CHECKS/dispatch_cli.php" finish <manifest> '<the workflow return, as JSON>'
# → {"action":"done","proof":<artifacts.proof, or null>,"followUps":[…]} | {"action":"ask","proof":…,"questions":[…],"followUps":[…]} (§Open questions)
#   | {"action":"halt","reason":…} | {"action":"halt","reason":"relay: …","relaunch":true}, once
php "$CHECKS/dispatch_cli.php" ci <manifest> --poll <n>                  # after done: the CI gate, polled (§The CI gate)
```

1. **Kickoff.** `kickoff` does §The work item and §Kickoff in one call. `--base` cuts the run from that
   branch on origin instead of the default branch, records it as the manifest's `base`, and routes the PR
   into it (§Kickoff, *A run on a base*). `ready`: its `manifest` is the run's, and its `notes` go into
   the report. A halt: report it and stop; never create the worktree another way. A denied kickoff call
   is reported like a halt: nothing is retried in another form. A resume skips this step.
2. **Launch.** Write the diff, then `launch <manifest> "<manifest stem>.diff"`.
   `done` or a halt: report it and stop. A resume starts here: `launch` starts from the cursor, and the
   step it names runs again.
   A `fresh session:` halt is resumed from a new session, where `/pipeline` runs `launch` from the cursor:
   in this session every start halts alike.
3. **Start the saved workflow `pipeline-autoflow` through the detour** (#134). Claude Code relays the
   owner's last chat message to every step of a workflow started in a reply a human message opened,
   and the steps then do that message instead of their own work; a reply a background job's notice
   opened carries no such message. So a start takes two replies:
   - **The launching reply** runs everything the start needs (steps 1–2; in `orchestrate` also its
     watches, teardowns and `needs_input.py`) and ends with one background Bash, `sleep 5`
     (`run_in_background: true`), as its **last tool call**; after it only the reply's text.
   - **The starting reply** is the one that wait's completion notice opens. Its **first tool call** is
     the `Workflow` call: `pipeline-autoflow` by name, with `launch`'s JSON as `args` (several runs
     started together: several `Workflow` calls in that first block). After them only the dispatch
     record and, in `orchestrate`, `needs_input.py` and its line; **never an `AskUserQuestion`**.
   - A human message that opens a reply before the notice: answer it, and start the run first thing in
     the reply the notice opens, or first in that same reply when the notice was absorbed into it.

   Then wait for the workflow's completion notice. The script's first agent checks that the start was
   clean (`machinery.md` §The relay check). Starting it from this skill is the owner's opt-in;
   unattended runs need auto permission mode or allow rules for `git push`, `gh` and `docker`, and the
   allow rules for the merge of the base and for `handoff` in their `cd <dir> &&` form (`README.md`,
   *Permissions for unattended runs*). The `cd` part is decided by the auto-mode classifier; a machine
   without auto mode adds `Bash(cd *)` as well.
4. **Finish.** `finish <manifest> '<its return as JSON>'`, or
   `'{"action":"halt","reason":"<the error>"}'` when the workflow errored (`machinery.md` §`finish`).
   **`relaunch: true`** (the first `relay:` halt in a row: the start was framed, no step ran): steps 2–3
   again, with no `--from` and no `--decision`, and nothing else: no PR body entry, no proof page, no
   question; the report gets one line, *restarted through the detour: the first start was framed*. A
   `relay:` halt without `relaunch` is a halt like any other.
   A `fresh session:` halt (the relay check found its agent type missing) is resumed from a new session, as
   in step 2.
   **`ask`** (a `blocking` open question is unanswered): the run is done but the PR stays draft; the
   session's sequence is §Open questions.
   A halt: §Failure policy.
5. **The CI gate** once `finish` printed `done`, or `ask` and every answer is recorded (§The CI gate).
6. **Report** the result (§The report), naming the proof page (the `proof` `finish` printed), listing
   `finish`'s `followUps` once and then asking them as one batched *file an issue* / *drop* question, or
   filing them directly (a `remark` is never asked), and arm the merge watch (§After the merge, which
   marks the page `merged` or `closed` when it fires).

## Interactive — the same loop, the human resolves

`interactive` runs the loop in the session, one step at a time:

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

Each prints one JSON line. On `dispatch` or `retry`, pass its `prompt` — one line naming the brief
file `pipeline_brief()` wrote — to a background agent; when `inline` is true, run the step in this
session instead. On `halt`, stop (§Failure policy). On `done`, return.

`inline` is true for `design` (the human drives the brainstorm) and for every `resolve` step: the session
shows the review from the open ledger entry, the human decides, the session carries that out — on
`review-pr` including the finish work (`steps/finish.md`) — records it with `dispatch_cli.php record`
(the human's `actions` in `<manifest stem>.actions.json`), as the inline `design` step records its spec
and plan, and runs `returned`. Every other step is dispatched to a fresh background agent, `handoff`
included: the agent runs the same command. After each step the session stops and continues when the
human says so (§Navigation).

Per leg, in `interactive`:

- **`design`**: the human drives the brainstorm dialogue; if brainstorming classifies Bounded without
  `medium` or `light`, the pipeline asks (`steps/design.md` §Design size); re-invoke `/pipeline` to
  continue.
- **`review-plan`, `review-pr`**: the reviewer writes a review; the human reads it and decides.
- **`verify-ui`**: the skill's "show me" hand-off is an interactive nicety.

## The CI gate — the session's half

`gh pr ready` waits for CI on the PR's head commit, whichever step pushed it (#85). **One gate, in the
session that runs `gh pr ready`:** the invoking session once `finish` prints `done`, or `ask` and every
answer is recorded, in `autoflow`; the finish step in `interactive` (`steps/finish.md`). In `autoflow`
the session, not a step, runs `gh pr ready`: the auto-mode classifier denies it a workflow agent, and it
is the most consequential outward write a run makes. Nothing marks the PR ready before `review-pr`'s
finish step has run (`gates.md` §Navigation guardrail). `implement` does not wait on CI in `autoflow`,
and a skipped check reads green, so the `ci` label goes on before the first push (`steps/implement.md`
§The `ci` label). What `ci` reads and each verdict is `machinery.md` §The CI gate.

The session polls the gate in one background Bash and waits for its completion notice:

```bash
poll=1; while answer=$(php "$CHECKS/dispatch_cli.php" ci <manifest> --poll $poll); echo "$answer" | grep -q '"action":"wait"'; do sleep 30; poll=$((poll + 1)); done; echo "$answer"
```

- **`ready`** → `gh pr ready <pr>`, then `php "$CHECKS/proof_cli.php" status <proof> ready`, `<proof>` the
  `proof` that `finish`'s `done` named (null: nothing to mark). The manifest already says done; when
  `gh pr ready` is denied the PR stays draft and no halt is written: put the denial in the report, and
  the owner runs `gh pr ready` by hand.
- **`ask`** → the questions go to the owner as on `finish`'s `ask` (§Open questions); once every answer
  is recorded, the gate runs again. The poll loop ends on it at the first read, as on every answer but
  `wait`. It is the backstop for a resume, a skipped ask or an answer recorded under a mistyped prefix.
- **`fix`** → one automatic round per run for each of the gate's three records: a red CI (owner, #85), a
  merge the review did not see and a conflict with the base. The answer's `decision` goes into
  `decisions` verbatim with the re-arm:
  `git -C <worktree> diff origin/<base>...HEAD > "<manifest stem>.diff"`, then
  `launch <manifest> "<manifest stem>.diff" --from review-pr --decision "<its decision>"` and a new
  `pipeline-autoflow` workflow, started through the detour (§`autoflow`, step 3). The review and finish
  steps work the round (`shared/review-pr.md` §The rounds); then `finish`, and this gate again.
- **`halt`** → `finish <manifest> '<the answer>'`: the answer names `review-pr` and its reason, so
  `finish` records the halt there. The PR stays draft, and §Failure policy's duties after `handoff`
  follow. An empty answer (a usage error) is a halt as well. After a halt on the hour, or on a `mismatch`
  once the heads match (push the branch, or reconcile it when GitHub is ahead), nothing needs
  re-reviewing: run the loop again by hand on the halted manifest (`ci` is read-only and refuses only a
  retired mode, a missing PR or a worktree whose `HEAD` git cannot read) rather than `launch`, which would
  re-run `review-pr:review`.

## Open questions — when each reaches the owner

Every open question carries a kind (`shared/resolving.md` §Open questions), and the kind says when it
reaches the owner:

| Kind | When it reaches the owner |
|---|---|
| `blocking` | before `gh pr ready` and before the proof is presented: `finish` answers `ask`, and the session asks it with `AskUserQuestion` right away; the PR goes ready only once no `blocking` question is open |
| `follow-up` | after the report, once, as one batched question (*file an issue* / *drop*), or filed directly |
| `remark` | never asked; recorded in the PR body only |

- **The id and the answer.** A question's id is its place in the ledger, `gate_ledger[<i>].actions[<j>]`:
  entries are append-only and a completed entry's actions are never rewritten, so the id holds for the
  run's life. An answer is a `decisions` entry that starts `Answer to open question <id>`, written by
  `launch --decision` (`../checks/questions.php`). A question is open while no decision starts with its
  prefix, so a mistyped prefix leaves it open and the gate asks again. Every question counts,
  `plan-approval` ones included; one a later loop-back made moot is answered by keeping what was built.
  A `plan-approval` question is asked only after the run built one branch of its fork through
  `review-pr`, so an answer that picks the other branch goes through the detour below and may spend a
  `pr-review` loop-back.
- **`finish` answers `ask`** while a `blocking` question is unanswered:
  `{"action":"ask","proof":…,"questions":[{id, gate, kind, question, note, decision}],"followUps":[{question, note}]}`,
  and otherwise `done` with `followUps` too (`[]` when there are none). The cursor says `done` either
  way: the workflow is finished, and what is left is the session's. `decision` is the text the session
  completes with the owner's answer.
- **The CI gate is the backstop.** In `autoflow`, while any `blocking` question is unanswered, `ci`
  answers `{"action":"ask","questions":[…]}` before it reads git or gh (§The CI gate), so after a resume,
  a skipped ask or a mistyped answer, `ready` stays out of reach. `interactive` has neither `ask`: its
  finish step has the human at hand, who settles the questions there.
- **The session's sequence on `ask`** (§`autoflow`, step 4; `../../orchestrate/references/commands.md`
  §Finish):
  1. Ask every question in one `AskUserQuestion`: 2–4 options per question from its `note`, the one the
     PR built first, recommendation first.
  2. Record each answer: the question's `decision` with the owner's answer appended, as `--decision`.
     Append the same lines at the end of the PR body (fetch the body, append, `gh pr edit --body-file`,
     as a halt's reason is appended), below the `## Open questions` section a fix round rewrites, so the
     answer outlives the disposable manifest.
  3. Every answer keeps what the PR built: write the diff, then `launch <manifest> "<manifest
     stem>.diff" --decision "…"`… answers `done`; then the CI gate. Any answer changes the code: `launch
     <manifest> "<manifest stem>.diff" --from review-pr --decision "…"`… with all the answers, and a new
     workflow through the detour (`shared/review-pr.md` §The rounds). The cycle is owner-paced, so it has
     no bound.
- **`follow-up`s** are listed once in the report with the ready PR (`finish`'s `followUps`), then asked
  as one batched *file an issue* / *drop* question, or filed directly. A **`remark`** is never asked.

## The report — after every run

After every `autoflow` run the invoking session reports two facts with the result:

```bash
php "$CHECKS/run_cost_cli.php" <the run's transcript dir> [<artifacts.proof>]
git -C <worktree> diff origin/<base>...HEAD > "<manifest stem>.diff"
php "$CHECKS/run_audit.php" <manifest> "<manifest stem>.diff" <the run's transcript dir>
```

The transcript dir is `~/.claude/projects/<project>/<session>/subagents/workflows/wf_<id>/`, named in
the Workflow result. `run_cost_cli.php` prints per step the weighted cost — each call's token types
weighed per model (`PIPELINE_MODEL_FACTORS`, relative to Opus), the models named after the step's
label — the peak context, the wall
time and the part of it spent waiting on tools, and on its `run:` line the total, the run's span in
minutes and the largest step peak. Given the run's page (`artifacts.proof`, when the manifest sets it) it also
files those figures into it, so the page and the store index show them (`proof-store.md` §Time and cost).
`run_audit.php` prints whether `ui` over the final diff agrees with
a `verify-ui` entry, whether each gate's newest ledger entries agree with what the steps reported, and
(`bound:`) whether each gate the run looped back at holds no more `looped-back` ledger entries than
`PIPELINE_LOOP_BOUND`, the loop-back the run halted on not counted: `tables.bound` and `loops` reach the
script through the invoking session's copy of `launch`'s answer, and no boundary check counts
loop-backs. It stays as the after-run report: with the boundary check in place a `MISMATCH` means a
check has a hole or the script ran with another bound, never a halt.

**The run status line** shows each unfinished `autoflow` run of the session's repo, one row each (issue,
leg, status, age, PR), read from the manifests by `../checks/statusline_cli.php`; no writes, no `gh`.
Setup: `README.md` §Status line.

## After the merge — the run removes its own slot

The worktree a run created is the run's to clean up, not the owner's: a finished run that leaves its
slot standing hands the owner a chore per PR. Only the slot **this run created** at §Kickoff; never a
checkout the run was launched inside, never another session's slot, never before the PR is `MERGED`.
A run started by `orchestrate` is covered by its own step 6 — this section is for a standalone
`/pipeline`.

1. **Arm the watch** as the run's report goes out (ready PR or halted-after-`handoff`): one background
   Bash per PR, exactly the "awaiting merge" loop of `../../orchestrate/references/commands.md` §Watch,
   with its `timeout: 7200000`, armed again when it ends without its `PR #<P>` line.
   It polls `gh` every 5 minutes in a shell, so it costs no tokens while it waits; the session wakes
   once, on the change. The watch stays on `state`: once the PR is ready, CI on its head has settled.
2. **On `MERGED`**, a session sitting inside the worktree leaves it first (`ExitWorktree` with `keep`),
   then, from the primary checkout:
   ```bash
   python3 ~/.claude/skills/orchestrate/teardown.py <worktree> <P> --repo <repo>
   ```
   It marks `artifacts.proof` `merged`, prints `orchestrate`'s checks (clean, `HEAD` equals the merged
   `headRefOid`, the PR's branch, no owner) and removes the slot or worktree and its branch only when
   all hold: **remove without asking**, ahead of `slots`' confirm step. A check fails: it removes
   nothing; report its last line, no question.
3. **Closed without merge**: the same call marks the page `closed`, removes nothing and exits 1. Say so
   in one line; the owner decides.

**The watch dies with the session.** A merge the session never saw — or the owner saying "merged" —
is handled the same way the next time `/pipeline` runs in that repo: a manifest whose PR is `MERGED`
gets step 2 before anything else.

## Failure policy — what still stops

Under `autoflow` these are the only stops. **No finding stops a run.**

- **Hard failure** — a station errors: tests won't go green, a tool dies, the stack won't start,
  or a review step returns nothing after a single retry (in `interactive`
  `returned` answers `retry` once, then `halt`). → **halt.** `finish` (`returned` in `interactive`)
  writes the failure to the manifest
  (`cursor.status: halted`, `cursor.reason`), which also marks the proof page Halted with that reason when
  `artifacts.proof` is set (`proof-store.md` §Statuses); a halt nobody records (a workflow that dies before `finish` runs)
  leaves the page Running until the next `launch`, `write` or recorded halt, so Running on the page is no guarantee
  the run is alive; a human resumes. **No silent retry** beyond that one — a retry hides
  the failure and the machinery may be in an unknown state.
  The one exception is a `relay:` halt (`machinery.md` §The relay check): the run started framed and no
  step ran, so the manifest is as `launch` left it; `finish` answers `relaunch: true` once, and the
  invoking session starts the run again through the detour without asking. A second in a row is a halt
  like any other.
  - **A halted manifest is the one the check rejected.** When the reason names a key the leg was not
    allowed to change, or a ledger entry it rewrote (*design added actions to ledger entry 1 (plan
    gap)*: the leg, what changed and the entry), repair it from `<manifest stem>.before.json`, the
    snapshot taken at dispatch, before the next `next` or `launch`; otherwise the run resumes with the
    leg's change in place.
  - **In `autoflow`** a review step that returns nothing runs once more, on the retry entry (Opus,
    `machinery.md` §Agents per step); a step that throws,
    any other step that returns nothing, or a station that would need an agent the step cannot start,
    halts at once. The halt reaches the invoking session as the workflow's return, and `finish` writes it to
    the manifest.
  - **A halt `dispatch_cli.php handoff` recorded** (a refused push, a PR that is not a draft, a gh that
    cannot answer) is a hard failure like any other. A resume runs the command again, and it adopts the PR
    the branch has.
- **A Fable usage limit is not a hard failure.** `/critique` moves the reviewer to Opus itself
  (`../../critique/SKILL.md` §Stage 2). That switch is not the single retry above: a reviewer that
  then returns nothing still gets its retry, on Opus. Its record is `/critique`'s one chat line; the
  ledger entry and the PR carry the review and what was done about it, as for any review. A usage
  limit on the Opus dispatch too is the hard failure: halt, and put the reset time in the failure
  written to the manifest so the human knows when a resume can work. In `autoflow` the review step
  itself runs on Fable (`machinery.md` §Agents per step); when it returns nothing — a usage limit in a background
  session — the script runs it once more on the retry entry, Opus (in an interactive session a usage
  limit pauses the workflow, which
  continues by itself), and a usage limit on that run too is the same hard failure.
- **Kickoff halts** (§The work item) — these fire *before* the worktree exists, so they leave
  nothing behind and there is no manifest yet to write to; report and stop.
  - **An open blocker** on the run's issue → halt in every mode. Which of wait / work around /
    pick the blocker up first applies is the human's call, not a finding to resolve.
  - **`pipeline_repo_board()` returns `invalid`** → machinery failure, same treatment as an
    `invalid` `## Checks` block. A board-less repo returns `absent` and is unaffected.
- **Bound exhaustion.** A loop-back past its bound (`gates.md` §Loop-backs) **halts in-session** — stop,
  leave the work in the worktree, and say why. The bound is what keeps an autonomous loop from churning
  indefinitely without ever surfacing.
  - **Before `handoff`** (`review-plan`) → **no branch push, no draft PR.** Twice-rejected work is
    not worth a PR round-trip; the human reads it live.
  - **After `handoff`** (`verify-ui`, `review-pr`) → the draft PR already exists, so there is
    nothing to not-push. Leave it **draft**, append the reason to the PR body without reading it
    (`gh pr view <pr> --json body --jq .body > "$TMPDIR/body.md"`, append the reason,
    `gh pr edit <pr> --body-file "$TMPDIR/body.md"`), stop. In `autoflow` the invoking session does
    this after `finish`, and its report names the proof page when `artifacts.proof` is set
    (`proof-store.md` §No page opens by itself).
  - The entry is not marked halted: the resolve step records `outcome: looped-back` as it returns,
    and `finish` halts the run through the cursor (`cursor.status: halted`, `cursor.reason`) —
    `returned` in `interactive`.
- **Mechanical-check exhaustion** → the same bound-exhaustion halt (`shared/checks.md` §Mechanical checks).
- **A return the checks cannot account for** — a moved cursor, a key only the pipeline writes, a
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

## Navigation

Once loaded in a session the pipeline holds the manifest cursor, so it is driven by **natural
language** — *"next step"*, *"go to step X"*, *"re-run review-plan"*, *"skip ahead to handoff"*.
A slash command is only a cold-session trigger; there is no separate `/next`. Every jump goes
through `pipeline_can_navigate(from, to, doneLegs, triggers)`: **backward is free; forward past a
gate leg that has not run is refused** (`gates.md` §Navigation guardrail). That refusal is the
un-skippable-review promise made mechanical.
