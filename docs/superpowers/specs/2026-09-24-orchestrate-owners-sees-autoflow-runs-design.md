# `owners.py` sees another session's `autoflow` run — design

**Design size:** Architectural

**Date:** 2026-09-24
**Issue:** IT4WEBBV/LaravelClaudeMd#68 (depends on #65, merged as PR #75)
**Canonical home:** `skills/orchestrate/owners.py`, its fixture test `skills/orchestrate/tests/owners_test.sh`,
and `skills/orchestrate/references/commands.md` §Owner of in-flight work.
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. Every question the brainstorm
would have asked is answered in *Assumptions*, so `/critique plan` audits exactly those.

## Problem

`owners.py` names a live session as the owner of a worktree when one of its transcripts
(`<projects>/*/<session>.jsonl`, `<projects>/*/<session>/subagents/*.jsonl`) has entries whose `cwd`
lies inside the worktree. An `autoflow` run leaves neither:

- its step agents' transcripts sit one level deeper, in
  `<projects>/*/<session>/subagents/workflows/wf_<id>/agent-<id>.jsonl` (next to a `journal.jsonl` and
  `*.meta.json` files), which the glob does not reach;
- every entry of those transcripts carries the launch directory as `cwd`, not the run's worktree.

Measured on this machine: the 8 step transcripts of `wf_76ac19f5-a8b` (viewiemedia #2077) all carry
`cwd` `/Users/jroelofs/GitProjects/viewiemedia/viewiemedia`, and each step's first Bash call is
`php <checks>/dispatch_cli.php brief /Users/jroelofs/GitProjects/viewiemedia/viewiemedia-3/.claude/pipeline/feature/issue-2077-property-slide-500s-the-whole-presentation.json <leg> <step>`.
The launching session's own transcript has the same blind spot: in this repo's orchestrator session
the kickoff and launch calls ran from the primary checkout, with the manifest path in shell variables,
and only their printed answers and the `Workflow` call's `args` carry the worktree literally.

So while another session drives an `autoflow` run, `owners.py` prints nothing and exits 0, and
`orchestrate` reads the run as orphaned. A *resume* on that answer starts a second workflow on a
worktree that already has one.

The interim guard in `commands.md` §Owner (*an `autoflow` manifest with a `pending` cursor: ask, never
resume on "no owner"*) has a gap: between a step's return and the next step's `brief`, the cursor holds
the returned status, and a live run reads as unowned.

## Design

### What owns a worktree

A live session owns a worktree when one of its transcript entries **works in** it. An entry works in
a worktree when either holds:

1. **Its `cwd` lies inside the worktree** (unchanged).
2. **It names the worktree through the pipeline's own interface.** Three shapes, each one written by a
   machine, not prose:
   - **A `dispatch_cli.php` call on the run's manifest.** An assistant `tool_use` block named `Bash`
     whose command runs `dispatch_cli.php <subcommand> <path>` with `<path>` under
     `<worktree>/.claude/pipeline/`. The worktree is `<path>` up to `/.claude/pipeline/`. This is the
     `brief` every workflow step starts with; it also covers literal `launch`, `finish`, `size`, `ui`
     calls and an `auto` dispatcher's `next` / `returned`. `kickoff`'s argument is the primary
     checkout, which has no `/.claude/pipeline/` in it, so it never matches.
   - **A kickoff or launch answer in a tool result.** A line of a `tool_result` block's text that
     parses as a JSON object with `action` `ready` (kickoff) or `start` (launch) and a string
     `worktree`. Those are the only two answers `dispatch_cli.php` prints with a `worktree` key
     (`kickoff.php` `pipeline_kickoff()`, `dispatch_cli.php` `launch`).
   - **The `Workflow` call itself.** An assistant `tool_use` block named `Workflow` whose `input.args`
     is such a `start` answer; the engine passes `launch`'s JSON through as `args` unchanged.

   In each shape the named worktree must **equal** the worktree asked about (both through
   `os.path.abspath(...).rstrip("/")`). Equality, not `inside()`: the path names the run's worktree
   exactly, and a primary checkout that contains `.claude/worktrees/<branch>` must not inherit its
   runs.

A mention is still not ownership. A user prompt, a `cat` of a manifest, or a step's prompt text that
contains the `brief` command does not match: only an executed call, a printed answer, or the
`Workflow` call does.

Together these cover an `autoflow` run from the moment kickoff prints until the launching session
ends: kickoff's answer, then launch's answer and the `Workflow` call, then each step's `brief`. The
evidence accumulates in the transcripts, so no window opens between steps, which is the gap the
`pending`-cursor guard had.

### Which transcripts are read

`transcripts()` adds `<projects>/*/<session>/subagents/workflows/wf_*/*.jsonl`, minus files named
`journal.jsonl` (the workflow's own event log, which has no `cwd` and is not a transcript). Every
`*.jsonl` is taken, not only `agent-*.jsonl`, so a renamed step file is still read, and fails closed
below if its format moved.

### Fail closed, per transcript

Today a session is unreadable only when **all** its transcripts together hold no `cwd` entry. A
session with a readable main transcript and an unreadable workflow transcript would pass as readable
and silently drop that workflow's evidence. The rule becomes per transcript:

- a session **owns** the worktree when any of its entries works in it (whatever else it holds);
- otherwise it is **unreadable** when it has no transcript at all, or any one of its transcripts holds
  no `cwd` entry;
- an unreadable session blocks (exit 2) when `related()` holds for its row's `cwd`, exactly as #65 set
  it, and is skipped otherwise.

Measured on this machine: of 146 main, 740 subagent and 175 workflow-step transcripts, none lacks a
`cwd` entry, so the tighter rule costs no false blocks on today's data.

### Shape of the code

`owners.py` stays one stdlib script (`re` joins the imports). Small functions, one job each:

- `transcripts(projects_dir, session_id)`: the three globs, `journal.jsonl` dropped.
- `entries(path)`: yields each JSON object of one transcript; skips lines that do not parse (a line
  still being written) and lines that are not objects. Replaces `cwd_entries()`.
- `answer_worktree(value)`: the `worktree` of a kickoff `ready` / launch `start` answer, else `None`;
  used for tool-result lines and `Workflow` args alike.
- `result_text(block)`: a `tool_result` block's text: its `content` when a string, its `text` parts
  joined when a list.
- `named_in_result(block)` and `named_in_call(block)`: the worktrees a tool result or a tool call names
  (the second and first / third shapes above).
- `named_worktrees(entry)`: both, over `entry["message"]["content"]` when that is a list of blocks.
- `normalised(path)`: `os.path.abspath(path).rstrip("/")`, for the worktree argument and every named
  worktree alike.
- `works_in(entry, worktree)`: rule 1 or rule 2.
- `read(path, worktree)`: one pass over a transcript, returning `(has_cwd, matches)`.
- `unreadable_transcripts(reads)`: no transcripts, or one without a `cwd` entry.

`main()` sums `matches` over the session's transcripts. `matches > 0` → owner line, count = matching
entries, as today. Otherwise no transcripts, or any `has_cwd` false, → unreadable, then `related()`.
The output contract is unchanged: owners on stdout as `name\tid\tstate\tcount`, exit 0; exit 2 with the
blocking sessions on stderr and nothing on stdout.

The `dispatch_cli.php` pattern: `dispatch_cli\.php['"]?\s+[a-z-]+\s+['"]?([^\s'"]+)`, the captured path
tested for `/.claude/pipeline/`. A command may hold several calls (a loop, `;`-joined lines, a `cd` first);
any match makes the entry count, once. Paths with spaces are not supported: no worktree path in these repos
has one, and the pipeline's own prompts do not quote the manifest.

### Docs

- `owners.py` docstring: the ownership paragraph names rule 2 and the workflow transcripts; the *Fails
  closed* paragraph says "any one of its transcripts holds no `cwd` entry".
- `commands.md` §Owner of in-flight work: the interim guard sentence (*"A worktree whose manifest has
  `mode: autoflow` and a `pending` cursor may be another live session's workflow, which `owners.py`
  cannot see yet: …"*) is removed. One sentence says what `owners.py` now reads: `cwd` entries, and for
  `autoflow` runs the run's `dispatch_cli.php` calls, kickoff and launch answers and the `Workflow` call.
- `commands.md` §Teardown: unchanged. A non-zero exit still fails the check.

## Tests

`skills/orchestrate/tests/owners_test.sh` stays the one fixture test (`bash
skills/orchestrate/tests/owners_test.sh`, prints `PASS owners.py`). Every existing case keeps its
fixture and its expectation. New cases, test-first (they fail on the current script, which neither
globs `workflows/` nor reads anything but `cwd`):

| Session | Transcripts | Expected |
|---|---|---|
| `wf-step` (working) | only `wf_1-abc/agent-s1.jsonl`: entries with `cwd` the primary checkout `$TMP/Shop/Shop`, one a Bash `tool_use` running `php /x/checks/dispatch_cli.php brief $WT/.claude/pipeline/feature/issue-4-x.json design run`; plus a `journal.jsonl` with no `cwd` | owner, count 1 (the issue's *Done when*) |
| `wf-quoted` (working) | `wf_6-abc/agent-s7.jsonl`: `cd <primary>; php … launch "$WT/.claude/pipeline/b.json" "$WT/.claude/pipeline/b.diff"` | owner, count 1 (quoted argument, second call on the line) |
| `wf-prefix` (working) | `wf_2-abc/agent-s2.jsonl`: `brief $TMP/Shop/Shop-40/.claude/pipeline/b.json` | not an owner of `Shop-4` |
| `kick` (working) | main: `cwd` primary; a `tool_result` whose `content` is a list of `text` blocks holding the kickoff `ready` answer for `$WT` | owner, count 1 |
| `launch` (blocked) | main: `cwd` primary; a string `tool_result` of two lines, `--- b` and the launch `start` answer for `$WT` | owner, count 1 |
| `wfcall` (working) | main: `cwd` primary; a `Workflow` `tool_use` with `args` the `start` answer for `$WT` | owner, count 1 |
| `mention` (working) | main: `cwd` primary; a user message whose text is the `brief` command for `$WT`, and a `tool_result` line `{"worktree":"$WT","mode":"autoflow"}` (no `action`) | not an owner |

These run in the first multi-row assertion, so the expected owner list grows by the five owners.

Fail closed, per transcript:

| Session | Transcripts | Row `cwd` | Expected |
|---|---|---|---|
| `half` | main with `cwd` primary; `wf_3-abc/agent-s3.jsonl` with no `cwd` entry | `$TMP/Shop/Shop` | exit 2, names `half` |
| `half-far` | same shape, own files | `$TMP/Other/Other` | skipped: exit 0, silent |
| `half-owner` | `wf_4-abc/agent-s4.jsonl` with the `brief` for `$WT`; a second step file with no `cwd` | `$TMP/Shop/Shop` | owner, exit 0 |

The pipeline Pest suite (`./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml
--test-directory=skills/pipeline/checks/tests`) runs once at the end as a regression check; nothing in
it changes.

## Out of scope

- Liveness of a workflow inside a live session. The workflow journal has no *completed* event (of 50
  journals on this machine, none records one), so a finished workflow's steps still name their
  worktree while the session that launched it stays live. That errs toward "owned", the safe side,
  the same way a live session's old `cwd` entries already do.
- `SKILL.md`, `engine.md`, the pipeline checks and the workflow script: no change. The pipeline's
  answer shapes are read, not altered.

## Assumptions

Questions the brainstorm would have asked the owner, with the answer assumed.

1. **Narrow the guard, or remove it?** Removed. With kickoff's answer, launch's answer, the
   `Workflow` call and every step's `brief` read, the only moment left unseen is kickoff itself before
   it prints (it writes the manifest, claims the board, then prints: about a second). A guard for that
   second would keep a judgment rule in the orchestrator's hands for a case the script now decides.
2. **Match only the `brief` call, as the issue proposes, or also the launching session's answers?**
   Both. `brief` alone leaves the launching session invisible from kickoff until the first step's
   first Bash call (kickoff, diff, launch, the workflow start, an agent spinning up), and on a resume
   the cursor in that window is `halted`, which invites a resume. The orchestrator's real calls keep
   the manifest in shell variables, so its commands name nothing; its printed answers and `Workflow`
   `args` do.
3. **Any `dispatch_cli.php` subcommand, or `brief` only?** Any whose first argument lies under
   `<worktree>/.claude/pipeline/`. Every such call acts on that run, the rule stays one regex, and an
   `auto` dispatcher's `next` / `returned` become visible at no cost.
4. **Is reading tool results "grepping for the path", which the docstring rules out?** No. Only a
   line that is exactly a kickoff `ready` or launch `start` answer counts: a machine-written object
   whose `worktree` key the pipeline defines. A manifest printed by `cat`, a path in prose, or a user
   prompt does not match, and a fixture pins that.
5. **Equality or `inside()` for a named worktree?** Equality. `inside()` would make a primary
   checkout that holds `.claude/worktrees/<branch>` the owner of every run nested under it.
6. **Per-transcript fail-closed: scope creep?** No. It is what *"a live session whose workflow
   transcripts cannot be read is never orphaned"* means once a session holds both kinds: with the
   aggregate rule, a readable main transcript would hide an unreadable workflow. It applies to all
   transcripts alike because none of the 1061 on this machine lacks a `cwd` entry.
7. **A step transcript that exists but has no complete line yet (spin-up)?** It holds no `cwd` entry,
   so a related session briefly reads unreadable (exit 2, ask). By then the launching session's
   answers already name the worktree, so in practice it is an owner, not unreadable.
8. **`journal.jsonl` and `*.meta.json`?** Not transcripts. `meta.json` is outside the `*.jsonl` glob;
   `journal.jsonl` is dropped by name.
9. **Changelog?** This repo has no `.changelog/` directory and no `CHANGELOG.md`, so none is written.
