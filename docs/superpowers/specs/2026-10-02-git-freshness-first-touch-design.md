# git-freshness checks a repo on the session's first touch — design

**Design size:** Architectural (the run requires the Architectural path; the change is one hook script, its wiring
and its tests)

**Date:** 2026-10-02
**Issue:** IT4WEBBV/LaravelClaudeMd#60
**Canonical home:** `hooks/git-freshness.sh` (a new `touch` mode, its path resolution, the working-branch line, the
fetch's failure signal), `hooks/tests/git-freshness-touch.test.sh` (new), `README.md` (hook wiring, the modes,
*Hook tests*), `CLAUDE.md` (*Never work against a stale checkout*).
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. The issue body is the approved scope and
is not re-litigated here. Every question the brainstorm would have asked is answered in *Assumptions*, so
`/critique plan` audits exactly those. Nothing was built or run; what the design relies on was read, plus one probe
of the installed Claude Code binary (see *What was read and probed*).

## Problem

`git-freshness.sh` checks a repo in two places today:

- `session` (SessionStart) checks the directory the session was launched in. Sessions are almost always launched
  from `~`, which is no repo, so this checks nothing.
- `edit` (PostToolUse on `Edit|Write`) checks the repo owning the written file, once per repo per session. It fires
  only on a write and only *after* it: a session that reads, greps and runs commands in a repo before its first
  edit works the whole time against whatever the checkout held, and the first edit already landed on the old base
  when the warning arrives. A checkout whose session never edits (an investigation, a review) is never checked.

So the CLAUDE.md rule "never work against a stale checkout (raise it and wait)" rarely fires for the repos actually
worked in. Measured 2026-09-23 (issue): the LaravelTemplate checkout sat on `main`, 42 commits behind
`origin/main`.

And when `check_repo` does run, it stays silent about a working branch that is merely behind: it speaks only when
the branch has local work *and* the incoming commits carry a consequence (a migration, a moved lockfile, a predicted
conflict). A feature branch 30 commits behind with no conflicting file passes without a word, which is the case the
issue's acceptance names.

## Approaches

1. **A PreToolUse `touch` mode on every path-bearing tool, reusing `check_repo` (chosen).** The hook resolves the
   repo the tool call acts on, claims a per-session marker for it, and on the first claim runs the existing check:
   fetch, fast-forward the base, report. It runs *before* the tool, so a first Read already sees the fast-forwarded
   `main`, and the warning arrives before any work lands. Everything the check does is already written and tested;
   the new code is the path resolution and the reporting change.
2. **Widen the existing PostToolUse `edit` matcher to `Read|Glob|Grep|Bash|…`.** Smallest diff, but the check then
   runs after the tool: a first Read returns the file as it was, the fast-forward then rewrites it underneath, and
   Claude's next Edit of that file fails as "modified since read". Rejected: the order is wrong exactly where the
   issue's first acceptance line looks.
3. **Sweep every repo under `~/GitProjects` at session start.** Rejected by the issue (*No session-start sweep*):
   a fetch per repo on every start, for repos the session never touches.

## Design

### When it runs: the `touch` mode

`~/.claude/settings.json` wires a PreToolUse hook with matcher
`Read|Edit|Write|MultiEdit|NotebookEdit|Glob|Grep|Bash` running `git-freshness.sh touch` (timeout 20, no status
message: on this matcher it would flash on every tool call of the session, for a hook that is a no-op after the first
touch). For each call:

1. **Resolve the target paths** from the payload's `tool_name`:

   | Tool | Paths |
   |---|---|
   | Read, Edit, Write, MultiEdit | `tool_input.file_path` |
   | NotebookEdit | `tool_input.notebook_path` |
   | Glob, Grep | `tool_input.path`, else the payload's `cwd` |
   | Bash | every directory named by `cd <dir>` or `git -C <dir>` in `tool_input.command`; when there is none, the payload's `cwd` |
   | anything else | none: exit silently |

2. **Resolve each path to its repo**: the path itself when it is a directory, else its `dirname`; then
   `git -C <dir> rev-parse --show-toplevel`. A path that does not exist or is in no repo is dropped. Duplicates are
   dropped. This is the lookup `edit` already does, moved into a function both modes call.
3. **Claim the first touch** per repo: `mkdir "$cache_dir/<toplevel with non-[A-Za-z0-9._-] as _>"`. `mkdir` is
   atomic, so of two parallel tool calls in one repo exactly one claims it; the other exits silently. The marker is
   claimed before the check runs, as `edit` does today, so a repo that fails the check (no origin, a dead network)
   is not retried on every later call. A marker left as a file by today's `edit` mode also makes `mkdir` fail, so
   the two modes share markers.
4. **Check each claimed repo** with `check_repo` and print **one** hook JSON object for the call, with
   `hookEventName: "PreToolUse"` and the reports of every repo checked joined in `additionalContext`. No
   `permissionDecision` is set, so the permission flow is untouched. A call whose repos were all claimed already
   prints nothing: later touches cost a `bash` start, the payload parsing (for Bash also a `sed` and two `awk`s over
   the command, and a `-d` test per named word) and one `git rev-parse` per named path.

The cache dir stays `${TMPDIR:-/tmp}/claude-git-freshness/<session_id>`, and `checkout` mode still drops it after a
`git checkout`, so the next touch re-checks the switched branch.

**Bash command parsing** stays simple, as the issue prefers. `tool_input.command` is taken from the payload with a
`sed` that respects JSON escapes (`"command":"((\\.|[^"\\])*)"`), then `\"`, `\\` and `\n` are unescaped. In it, a
directory is any word following `cd` or `git -C` at a command boundary (line start, `;`, `&`, `|`, `(` or
whitespace before it), up to whitespace, `;`, `&`, `|` or `)`. Surrounding single or double quotes are stripped, a
leading `~` becomes `$HOME`, and a relative path is taken against the payload's `cwd`. Variables, subshell
expansions and paths with spaces inside them are not resolved: such a call falls back to nothing for that word,
and when no word resolves, to the `cwd`. A miss is visible as a missing freshness line; no instrumentation is added.

### Slot and pipeline worktrees

Each worktree has its own `--show-toplevel`, so a slot or a pipeline worktree is its own repo with its own marker
and its own branch, as the issue asks. The fetch is shared only in part: `fetch_if_stale` skips the network when the
newest of the worktree's own and the common dir's `FETCH_HEAD` is under 15 minutes old (`newest_fetch_mtime`,
unchanged), and the remote refs it moves live in the common dir. `FETCH_HEAD` itself is per worktree, so this holds in
one order only: a slot touched right after its primary costs no second fetch (the primary's `FETCH_HEAD` is the common
dir's), while a slot touched first, or a second slot, fetches again. Sibling worktrees checked within seconds of each
other, as an `/orchestrate` batch's step agents are, can also fetch concurrently and collide on the remote-ref locks;
the loser reports `freshness unknown` (see *A fetch that does not finish*), which carries no instruction
(assumption 18).

### What the check reports

`check_repo` keeps its fast-forward of the base branch (`sync_base_branch`, unchanged, with its clean-checkout and
sibling-worktree guards) and its consequence details (`classify_incoming`, `predict_conflicts`). One thing changes:

**The working-branch line.** After the sync, when HEAD is on a branch (not detached) and
`git rev-list --count HEAD..<base_ref>` is N > 0, the report opens with

> Stale checkout: `<repo>` on '`<branch>`' is N commit(s) behind `<base_ref>`.

followed by the consequence details the branch's local work earns (as today), the last-fetch age, and the existing
instruction: do not pull, rebase or merge on your own initiative; raise it with the user before working in this repo
and wait for their decision. The `systemMessage` reads `<repo> '<branch>': N behind <base_ref>`, with the
consequence tags appended (`, 2 migrations, 3 to merge by hand`). This holds for any branch, `main` included: a
`main` the sync could not fast-forward (dirty, mid-merge, local commits) is still behind afterwards, so it gets the
line beside the sync note that says why it was left alone. A `main` the sync did fast-forward is not behind and gets
none.

- When the branch's upstream *is* `<base_ref>` (a local `main` tracking `origin/main`), the existing "pushed to this
  branch elsewhere" line is skipped: the working-branch line says the same thing. For any other upstream it stays.
- **A `/pipeline` run's own branch gets no working-branch line and no consequence details.** When
  `<toplevel>/.claude/pipeline/<branch with / as ->.json` exists (the manifest path `pipeline_manifest_path()` in
  `skills/pipeline/checks/manifest.php` builds), the run's own code decides whether a step merges the base
  (pipeline `engine.md` §Catching up with the base: a run "does not halt on behind"). A first Read in every step's
  worktree telling the step agent to raise it and wait would contradict that brief on every step. The sync notes and
  the upstream line still report.

Today's header paragraph that argues against reporting the count is rewritten: a touched repo is a repo about to be
worked in, and new work on a stale base is the cost, whether or not the branch has commits of its own yet. The
consequence details stay what makes the line actionable.

### A fetch that does not finish: "freshness unknown"

`fetch_if_stale` returns non-zero when it ran a fetch that did not succeed: killed at its cap
(`max_fetch_seconds`, 10) or exited non-zero (offline, auth). It then skips `git remote set-head origin --auto`,
which is a second network call with no cap and today runs regardless. `check_repo` carries on against the remote
refs it has (the fast-forward and the count stay correct for what was last fetched) and adds

> Fetch from origin did not finish (timed out after 10s, or failed); freshness unknown, measured against the last
> fetch (`<age>`).

with the tag `freshness unknown`. It never blocks the tool: the hook still exits 0 and prints its one object.
`sync_config_repos` calls `fetch_if_stale` in a background subshell and ignores its status, so session start is
unchanged.

The cap is per repo and the hook's timeout (20 s) is per call. One Bash command naming two repos, both past the TTL
and both unreachable, spends two capped fetches and can outrun the timeout; Claude Code then drops the hook's output,
and because both markers were claimed first, neither repo is reported again in that session. This is accepted
(assumption 24): it needs two unreachable repos in one call, and it costs a missing line, never a blocked tool.

### `session`, `edit` and unknown modes

- `session` still syncs the config repos and checks the launch directory (the issue rules out a sweep, not this),
  and now claims the launch repo's marker, so the first touch in that repo does not report it a second time.
- `session` also claims each config repo's marker (its `--show-toplevel`) in `sync_config_repos`, which already
  fetches it, fast-forwards its base and flags a checkout not on its base ("skills from '`<branch>`'"). Every skill in
  `~/.claude/skills` is a symlink into a config repo's primary checkout, so nearly every session, and every
  `/pipeline` step agent through its brief's pointer to `engine.md`, reads a file there. Without the claim that read
  would check the primary checkout, and a checkout on a branch behind `origin/main` would put "raise it and wait" in
  sessions that do not work there (assumption 22).
- `edit` stays, as the same code as `touch` with `hookEventName: "PostToolUse"`, for a machine whose
  `settings.json` still wires it: both modes share markers, so with both wired the PreToolUse touch claims first and
  the PostToolUse edit is a no-op.
- The `case` today ends in `session | *`, so a mode the script does not know runs a full session sync on every
  matching tool call. It becomes `session)` plus `*) exit 0`; `mode` still defaults to `session` without an argument.

### `check_repo` emits through the caller

`check_repo` prints its own JSON today, which allows one repo per hook run. It now appends its report to
`repo_context` and its tags to `repo_summary` and returns; each mode calls `emit` once at the end with whatever
was collected (config notes still ride along in `session`). The quiet "nothing incoming that affects this work"
report stays, as context only (`suppressOutput`).

### Documentation

- `README.md`: the hooks JSON block replaces the PostToolUse `Edit|Write` entry with the PreToolUse `touch` entry;
  the modes list describes `touch`, marks `edit` as the legacy wiring to remove, and *Hook tests* names the new
  test file.
- `CLAUDE.md` *Never work against a stale checkout*: the hook checks each repo on the session's first touch;
  "Without the hook, check by hand before the first edit" becomes "before the first touch". Its `/pipeline`
  exception gains one sentence: in a run's step, a warning about a checkout the brief does not name (the checkout the
  step was launched in, a config repo) is not the run's to act on; the step leaves that checkout alone and does not
  halt on it (assumption 23).
- The hook's header comment: the modes, and the reporting rationale above.

`~/.claude/settings.json` itself is not changed by this work (see *Assumptions*).

## Testing

`hooks/tests/git-freshness-touch.test.sh`, a new file with its own copy of the existing harness (the `$root` under
`$TMPDIR`, the isolated git config, `fixture()` with its containment guards, `is`/`contains`/`lacks`,
`json_is_valid`), running the hook as a subprocess with a JSON payload as Claude Code does, each case with its own
`session_id` and cache dir removed before and after. Cases, each tied to the issue's acceptance:

1. **First touch versus a repeat touch.** A Read payload on a file in a fixture: the first run prints one line of
   valid JSON with `"hookEventName":"PreToolUse"`; the second prints nothing.
2. **`main` fast-forwarded on a first Read.** A clean fixture on `main`, behind `origin/main`: after the Read the
   local `main` equals `origin/main` and the file on disk holds the new content.
3. **A behind feature branch reported once.** A fixture on `feature` with one local commit, `origin/main` two ahead
   and touching no shared file: the first touch's context contains `is 2 commit(s) behind origin/main` and the raise
   instruction, the `systemMessage` contains `2 behind`; a second touch prints nothing.
4. **A dirty `main` left alone.** A fixture on `main` with an uncommitted change, behind: `main` does not move, the
   working tree keeps the change, the report carries both the "left alone" sync note and the behind line.
5. **A slot worktree.** A fixture with a worktree on branch `slot`: touching the primary and then the slot both
   report, the slot's report names `slot`, and the primary's marker does not silence the slot.
6. **No fetch for untouched repos.** Two fixtures with `FETCH_HEAD` aged past the TTL (`touch -t`): a touch in the
   first renews its `FETCH_HEAD`, the second's mtime is unchanged.
7. **Bash targets.** A Bash payload with `cwd` outside any repo and `command` `git -C <repo> status` checks `<repo>`;
   one with `cd "<repo>" && ls` (escaped quotes in the JSON) does too; one with neither and `cwd` in a repo checks
   the `cwd`.
8. **Freshness unknown.** A fixture whose `origin` URL points at a missing path, `FETCH_HEAD` aged: the report
   contains `freshness unknown`, the output is still one valid JSON line.
9. **A pipeline run's branch.** A fixture on `feature` behind, with `.claude/pipeline/feature.json` present: no
   behind line and no raise instruction.
10. **Unknown mode.** `git-freshness.sh nonsense` with a payload prints nothing and changes nothing (no config sync:
    `GIT_FRESHNESS_CONFIG_REPOS` points at a fixture whose `main` stays behind).
11. **Config repos are claimed at session start.** A config-repo fixture on `feature`, behind: after a session, a
    Read of a file in it prints nothing; the same Read under another session id reports the repo, so the line is not
    vacuous.

The existing `git-freshness-sync.test.sh` keeps passing unchanged, including its case 11 (`edit` caches per repo)
and the session cases, which now exercise `check_repo` through the collect-then-emit path.

## Assumptions

Each question the brainstorm would have asked, with the answer assumed.

1. **Report the commit count for a behind working branch, against the hook's own "no counts" rationale?** Yes. The
   issue's *Decided* names the line ("`<branch>` is N commits behind `origin/<base>`, raise it and wait") and its
   acceptance asks for it. The rationale was written for a check that also ran on repos not being worked in; on a
   first touch the repo is the one about to be worked in. Consequence details stay as the detail lines.
2. **Also for a branch with no local commits and a clean tree?** Yes: work about to start on it starts on an old
   base, and the issue does not narrow it.
3. **How far to parse Bash commands?** Only `cd <dir>` and `git -C <dir>`, else the `cwd`, as the issue prefers.
   When a command names a directory, the `cwd` is not checked as well: a session's `cwd` is often the launch repo
   while the work goes through `git -C <worktree>`, and the `cwd` repo gets its own check on its own first touch.
   Misses are not instrumented; one found is a follow-up.
4. **On a fetch timeout, retry on the next touch?** No. The marker stays claimed and the report says "freshness
   unknown"; retrying would put up to 10 seconds on every tool call while the network is down.
5. **Should a `/pipeline` run's branch get the "raise it and wait" line?** No: engine.md §Catching up with the base
   gives that decision to the run's code, and the hook would otherwise tell every step agent to halt on its first
   Read. Detected by the manifest file at `pipeline_manifest_path()`'s path; base-branch sync notes still report.
6. **Which tools trigger a check?** The ones that carry a path or a working directory: Read, Edit, Write,
   MultiEdit, NotebookEdit, Glob, Grep, Bash. Not WebFetch, MCP tools or the Task tools.
7. **Does this work edit `~/.claude/settings.json`?** No. The wiring is per machine and the owner's step (README:
   "this wiring is the only per-machine step"); README carries the new block, and the PR body lists the change to
   make on each machine. Until a machine is rewired, its `edit` wiring keeps working unchanged.
8. **Does session start still check its launch directory?** Yes, and it claims that repo's marker; only a sweep over
   untouched repos is ruled out.
9. **Order against #59 (session-start report of slots gc; open, no PR yet)?** No ordering is imposed. This change
   touches `session` mode only to claim a marker and emit through the collect path; whichever lands second merges
   the other in.
10. **Report a behind `main` that could not be fast-forwarded?** Yes, with the line beside the sync note: it is the
    working branch and it is stale.
11. **Detached HEAD?** No working-branch line: a detached checkout names no branch to bring up to date.
12. **New test file or more cases in the existing one?** A new file for the `touch` mode with a copied harness (the
    fixture copy is fine per CLAUDE.md); the existing file stays the sync and session suite.
13. **Several repos in one Bash command?** Each newly touched one is checked, and their reports share the call's one
    JSON object.

Added by the `plan` step, where the plan needed an answer this spec did not give:

14. **Consequence details for a branch whose upstream is the base (a local `main` tracking `origin/main`)?** Yes.
    Today's `upstream != base_ref` gate kept the consequences from doubling the "pushed to this branch elsewhere"
    line; that line is now skipped in exactly that case, so the gate goes and a behind `main` with local commits gets
    its migrations, lockfiles and conflicts like any other branch.
15. **How does the legacy `edit` mode find its path?** From `file_path` in the payload, as today, without looking at
    `tool_name`: the existing `git-freshness-sync.test.sh` case 11 sends no `tool_name` and must keep passing
    unchanged. Only `touch` resolves paths by `tool_name`.
16. **The `systemMessage` format for every report with findings?** One shape: `<repo> '<branch>': <tags>.`. Today's
    "— incoming from `<base_ref>`" suffix goes: the leading "N behind `<base_ref>`" tag names the base, and the
    "branch pushed elsewhere" tag never needed it.
17. **Detached HEAD with local changes and a moved base?** It keeps today's consequence details under today's
    "Stale checkout with consequences: `<repo>` on '(detached HEAD)'" header; only the working-branch line is
    withheld (assumption 11).
18. **Does a fetch that did not finish carry the raise-and-wait instruction?** Not by itself: it is a paragraph of its
    own, like the sync notes, with the `freshness unknown` tag. It says nothing about being behind; the instruction
    comes with a behind line or a consequence, as before.
19. **The manifest path function's name?** It is `manifest_path($worktree, $branch)` in
    `skills/pipeline/checks/manifest.php`; the *Design* section's `pipeline_manifest_path()` names the same thing. The
    hook mirrors its rule in bash (`<toplevel>/.claude/pipeline/<branch with / as ->.json`) and does not call PHP.
20. **Does `session` skip its check when the launch repo's marker already exists?** No: a resume, `/clear` or compact
    fires SessionStart again under the same session id, and today it checks every time. It claims the marker and
    checks regardless of the claim's result.
21. **Which directory does `session` mark?** The launch directory's `--show-toplevel`, so a session launched in a
    repo's subdirectory silences the first touch anywhere in that repo.

Added by the `review-plan` resolve step, answering the plan review:

22. **Does a skill read check the config repo's primary checkout?** No: `session` claims each config repo's marker
    while it syncs it, so the first read through `~/.claude/skills` costs a `rev-parse` and prints nothing. Cost: a
    session working in a config repo's primary checkout gets no first-touch line there; it keeps the session-start
    "skills from '`<branch>`'" tag and the sync notes. Work on a config repo through a pipeline worktree is unaffected
    (own toplevel, own marker).
23. **What does a `/pipeline` step agent do with a warning about a checkout its brief does not name** (its launch
    checkout, checked at SessionStart or through a Bash command's `cwd`; a config repo where SessionStart did not run
    for it)? Nothing: `CLAUDE.md`'s pipeline exception says so. The step works only in its worktree, so the warning is
    not about its work, and a step agent cannot raise anything.
24. **A deadline shared across one hook call?** No. Two repos past the TTL and unreachable in one Bash command can
    outrun the 20 s hook timeout and leave both unreported for the session (*A fetch that does not finish*); accepted
    as rare and harmless rather than adding a budget passed through `check_repo`. A miss found in practice is a
    follow-up.

## What was read and probed

- `hooks/git-freshness.sh` in full: `check_repo`, `sync_base_branch`, `worktree_holding`, `fetch_if_stale`
  (`git remote set-head origin --auto` after the fetch, unconditionally), `newest_fetch_mtime`, `emit`, the
  `checkout`/`edit`/`session | *` case.
- `hooks/tests/git-freshness-sync.test.sh`: the harness, case 10 (subprocess, valid JSON) and case 11 (`edit`
  caching), the fixtures' fresh `FETCH_HEAD` (so fixture tests skip the network unless aged).
- `README.md` (hook wiring and modes, *Hook tests*), `CLAUDE.md` (*Never work against a stale checkout* and its
  `/pipeline` exception), `~/.claude/settings.json` on this machine (SessionStart `session`, PostToolUse
  `Edit|Write` → `edit`, Bash `git checkout` → `checkout`).
- Pipeline `engine.md` §Catching up with the base, `skills/pipeline/checks/brief.php` (`PIPELINE_CATCH_UP_STEPS`,
  `pipeline_catch_up_line()`), `skills/pipeline/checks/manifest.php:25` (the manifest path:
  `<worktree>/.claude/pipeline/<branch with / as ->.json`).
- Probed: Claude Code reads `additionalContext` from a PreToolUse hook's `hookSpecificOutput`: the installed
  2.1.287 binary's hook-result code contains `u?.hookEventName==="PreToolUse"?u.additionalContext:void 0` and
  carries it into the PreToolUse result (`grep -a -o -E '.{0,30}PreToolUse.{0,10}additionalContext.{0,120}'` on
  `~/.local/share/claude/versions/2.1.287`). The probe shows the field is read for PreToolUse; that it reaches the
  model on an allowed call is the implement step's live check once a machine is rewired, and with the event name a
  parameter of the shared code, the PostToolUse `edit` wiring stays a working fallback.
- Probed (plan step): an up-to-date `git fetch` still rewrites `FETCH_HEAD`, which *Testing* case 6 relies on to see
  that a touched repo was fetched: two fetches in a row in this worktree left `FETCH_HEAD`'s mtime equal to the second
  one's time (`stat -f %m "$(git rev-parse --absolute-git-dir)/FETCH_HEAD"; git fetch -q origin; git fetch -q origin;
  stat -f %m …`).
