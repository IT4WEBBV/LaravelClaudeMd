# A run that cannot load the relay-check agent names a fresh session as the way out — design

**Design size:** Architectural

**Date:** 2026-10-02
**Issue:** IT4WEBBV/LaravelClaudeMd#150
**Canonical home:** `skills/pipeline/checks/dispatch_cli.php` (`launch` checks the agent link),
`skills/pipeline/workflow/pipeline-autoflow.js` (`relayProblem()`'s catch), `skills/pipeline/checks/tests/DispatchCliTest.php`,
`skills/pipeline/checks/tests/AutoflowScriptTest.php`, pipeline `references/engine.md` §`autoflow`, pipeline `SKILL.md`
§`autoflow` step 4, orchestrate `SKILL.md` Steps 5 and 7 and *Common mistakes*, orchestrate `references/commands.md`
§Launch.
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. The issue body is the approved scope and is
not re-litigated here. Every question the brainstorm would have asked is answered in *Assumptions*, so
`/critique plan` audits exactly those. Nothing was built or run; one throwaway probe checked a PHP claim (see
*Assumptions* 5).

## Problem

Since #147 (issue #134) every `pipeline-autoflow` run starts with an agent of type `pipeline-relay-check`
(`skills/pipeline/agents/pipeline-relay-check.md`, linked into `~/.claude/agents/` by `hooks/git-freshness.sh` at
session start). Claude Code reads agent types once, when a session starts. A session that started before the link
existed cannot use the type even after the link is made, so every workflow it starts halts before its first step:

```
the relay check failed: agent({agentType}): agent type 'pipeline-relay-check' not found. Available agents: claude, …; is ~/.claude/agents/pipeline-relay-check.md linked (hooks/git-freshness.sh)?
```

Seen twice in one orchestrator session on 2026-10-02 (`wf_d5513750-689`, then `wf_a682740e-f05` after running
`git-freshness.sh session` by hand, which made the link and changed nothing). The reason asks a question the second
halt had already answered (the link was there), and neither the reason nor orchestrate says that only a new session
gets past it, so a resume in the same session halts the same way.

## Scope (from the issue)

1. The halt reason separates the two cases: link missing → run the session hook; link present → this session
   predates it, restart it (or spin off a fresh orchestrator).
2. Orchestrate's *Resume* names a fresh session as the way out, so a halted run is not relaunched into the same
   failure.
3. Open: whether the check falls back to a plain agent with no tools when the type is missing, instead of halting.

## Approaches

**Where the two cases are told apart.** The workflow script has no filesystem access (the Workflow reference:
"No filesystem or Node.js API access"), so it cannot see whether the link exists; PHP can.

- *(recommended)* **`launch` checks the link; the script names the remaining case.** `launch` already decides
  whether a run may start, writes nothing when it refuses, and runs in the same session just before the workflow.
  When `~/.claude/agents/pipeline-relay-check.md` is missing (or a dangling link) it halts with the
  link-missing reason, before the detour and before any workflow. A workflow therefore only starts with the link
  present, so an *agent type not found* in the script means this session predates the link: the script halts with
  that reason, and needs no new input. Each reason is made where its fact is known and tested there:
  `DispatchCliTest` for the link-missing one, `AutoflowScriptTest` for the session one.
- **`launch` reports the link in `args` (`relayAgentLinked: bool`) and the script picks the reason.** Both
  reasons live in the script and `AutoflowScriptTest` covers both, as the issue's *Verify* words it; but a
  machine without the link still spends the detour and a workflow start to learn it, and the start answer grows a
  key only the catch reads. Rejected.
- **`finish` rewrites the script's reason** after checking the link. The script's reason would be a placeholder
  that PHP replaces, and the fact is checked after the run instead of before it. Rejected.

**The fallback the issue leaves open.** `agent()` takes no `tools` option; a run without `agentType` gets the
default workflow agent with every tool. #134's spec (§Approaches, *How the check agent gets no tools*) rejected that
agent for the check because a framed check agent could act on the relayed request before it answers, and rejected
`agent()`'s undocumented `disallowedTools` because a deny list must name every tool and drifts. No "plain agent with
no tools" exists to fall back to, so the check halts, as today. Decided: **no fallback.**

## Design

### The prefix

Both new reasons start with `fresh session: `, a stable prefix like `relay: `, so orchestrate and the pipeline skill
recognise the halt by its first words. It never matches `dispatch_cli_is_relay()`, so `finish` answers no
`relaunch` for it: a relaunch from the same session would fail alike.

### `launch` checks the link

A new function in `dispatch_cli.php`, beside `dispatch_cli_tier_problem()`:

```php
/** Why this machine cannot run the script's relay check: its agent type is not linked where Claude Code reads it (`../references/engine.md` §`autoflow`). */
function dispatch_cli_relay_agent_problem(): ?string
```

- The directory is `getenv('PIPELINE_AGENTS_DIR')` when set and not empty, else `$HOME/.claude/agents`, the same
  default the hook links into (`GIT_FRESHNESS_AGENTS_DIR`), as `proof_root()` does with `PIPELINE_PROOF_ROOT`.
- It returns null when `<dir>/pipeline-relay-check.md` `is_file()` (which follows a link, so a dangling link counts
  as missing), else:
  `fresh session: ~/.claude/agents/pipeline-relay-check.md is not linked, and Claude Code reads agent types only when a session starts: run hooks/git-freshness.sh session (README.md), then resume the run from a new session`
  The path in the reason is always written `~/.claude/agents/…`, whatever the env says, so the reason reads the
  same on every machine and in every test.
- `dispatch_cli_launch()` adds it as the last link of the problem chain
  (`dispatch_cli_invalid() ?? dispatch_cli_mode_problem() ?? dispatch_cli_agents_problem() ?? dispatch_cli_tier_problem() ?? dispatch_cli_relay_agent_problem()`),
  so it halts through `pipeline_halt()` before any write: no `--from` re-arm, no `--decision`, no snapshot removal,
  no proof status. The manifest stays as it was and a later `launch` resumes from the same cursor.

### The script names the session case

In `pipeline-autoflow.js`:

- A constant `RELAY_AGENT = 'pipeline-relay-check'`, used for `agentType` and for the match below.
- `relayProblem()`'s catch: when `error?.message` names the type as not found
  (`/pipeline-relay-check\W+not found/i`, which matches Claude Code's `agent type 'pipeline-relay-check' not found`),
  it returns
  `fresh session: this session started before ~/.claude/agents/pipeline-relay-check.md was linked, and Claude Code reads agent types only when a session starts: resume the run from a new session`
  — the raw message is left out: it holds single quotes, and the invoking session hands the workflow's return to
  `finish` inside single quotes (#134's spec, *Assumptions* 12).
- Any other throw returns `the relay check failed: <message>`, without today's *is … linked* question: `launch` has
  checked the link by then. The comment above `relayProblem()` names the three outcomes.

### The docs

- **pipeline `references/engine.md` §`autoflow`.** The `launch` bullet: it halts with `fresh session: …` when the
  agent link is missing, before anything is written. The relay-check paragraph: a check whose agent type is not
  found halts with `fresh session: …` (the session predates the link `launch` found); any other throw with `the
  relay check failed: …`; neither carries `relay:`, so neither is relaunched. *Remove when*: the list of parts
  that go together gains `launch`'s link check and the `fresh session:` reasons.
- **pipeline `SKILL.md` §`autoflow`.** Step 2's *a halt: report it and stop* and step 4 gain one sentence: a
  `fresh session:` halt is resumed from a new session (`/pipeline` there runs `launch` from the cursor); in this
  session every start halts alike.
- **orchestrate `SKILL.md`.**
  - Step 5, *Halted*: a `fresh session:` halt (from `launch` or `finish`) means this session can start no run.
    Start no further run in it, and ask *resume in a fresh orchestrator* (recommended: `spinoff` an orchestrator
    over the batch's unfinished issues, as the launcher does, commands §Where am I; this session then dispatches
    nothing more) / *owner takes over*, quoting the reason.
  - Step 7, *Resume*: a `fresh session:` halt is resumed only from a session started after the link existed,
    never relaunched from the one that halted.
  - *Common mistakes*: a row, *Resuming a `fresh session:` halt in the same session* → *Claude Code reads agent
    types once per session: the resume halted again (`wf_a682740e-f05`, after the hook had made the link).*
- **orchestrate `references/commands.md` §Launch.** The bullet *`launch` answers `done` or a halt* gains: a
  `fresh session:` halt is Step 5's, for every issue still to start.

## Testing

Pest, test first, in the pipeline checks suite (`skills/pipeline/checks/phpunit.xml`); `AutoflowScriptTest` needs
`node`.

**The test helper.** `dispatch_cli()` in `DispatchCliTest.php` passes `PIPELINE_AGENTS_DIR` set to the repo's own
`skills/pipeline/agents` (`realpath(__DIR__ . '/../../agents')`, which holds `pipeline-relay-check.md`) ahead of the
caller's `$env`, so every existing `launch` test, and `autoflow_start()` in `AutoflowScriptTest`, keeps answering
`start` on any machine, with or without `~/.claude/agents`.

**`DispatchCliTest.php`:**

1. *halts a launch when the relay-check agent is not linked, and leaves the manifest as it was (#150)*, a dataset
   over the agents dir: an empty temp dir; a temp dir holding a dangling `pipeline-relay-check.md` link; a
   directory path that does not exist. Each launches with `--decision 'Keep the guard'` and expects
   exactly `{action: halt, reason: <the launch reason above>}` and the manifest file byte-identical (the
   `--decision` was not appended).
2. *launches when the agent is linked through a symlink*: a temp dir with a symlink to the repo's
   `pipeline-relay-check.md` → `action: start`.
3. `finish` given a `fresh session: …` halt answers it without `relaunch`: one row added to the existing
   *answers a relay halt with one relaunch* dataset, or a case beside it, cursor `pending` → answer
   `{action: halt, reason: 'fresh session: x'}` with no `relaunch` key, recorded as a halt.

**`AutoflowScriptTest.php`:** the existing *halts without the relay prefix when the check itself fails* case
becomes a dataset over the thrown message:

| thrown | reason |
|---|---|
| `agent({agentType}): agent type 'pipeline-relay-check' not found. Available agents: claude, Explore` (the issue's text) | the script's `fresh session: this session started before …` reason |
| `agent type "Pipeline-Relay-Check" not found` (other quotes, other case) | the same |
| `the schema is unsatisfiable` | `the relay check failed: the schema is unsatisfiable` |
| `unknown agent type pipeline-relay-check` (today's fixture: not the *not found* wording) | `the relay check failed: unknown agent type pipeline-relay-check` |

Each expects `labels` empty (no step ran) and `leg` the start leg. The replay fake already throws
`input.relay.throw`; it needs no change.

## Done when

- A `launch` on a machine without `~/.claude/agents/pipeline-relay-check.md` halts with the link-missing
  `fresh session:` reason and writes nothing.
- A workflow whose relay check finds the agent type missing halts with the session `fresh session:` reason;
  `finish` does not relaunch it.
- Orchestrate never resumes a `fresh session:` halt from the session that got it.
- The pipeline checks suite passes.

## Assumptions

Each question the brainstorm would have asked the owner, and the answer assumed.

1. **Where are the two cases told apart?** `launch` checks the link, the script names the session case
   (§Approaches). The issue's *Verify* puts both reasons in `AutoflowScriptTest`; here the link-missing reason is
   `launch`'s and its test is in `DispatchCliTest`, because a missing link is known before the workflow starts and
   the script cannot read the filesystem.
2. **Fall back to a plain agent?** No (§Approaches): `agent()` has no tools option, and the default agent's full
   tool set is what #134 rejected for the check.
3. **Does Claude Code really read agent types only at session start?** Taken from the issue's evidence: the second
   run halted after the hook had made the link in that session. Not probed: answering it needs a Workflow start
   (this step may not start one). If a later Claude Code reloads agent types mid-session, the session reason is
   simply never produced; nothing else depends on it.
4. **Is the error wording stable?** The match is on the type name followed by `not found`, case-insensitive,
   ignoring the quotes. If Claude Code rewords it, the halt falls back to `the relay check failed: <message>`,
   which still names the type: loud, and the fix is the one regex.
5. **Does a dangling link count as missing?** Yes. `Probed: PHP's is_file() is false for a symlink whose target is
   gone: bool(false) (ln -s <tmp>/nope.md <tmp>/link.md; php -r 'var_dump(is_file($argv[1]));' <tmp>/link.md)`.
6. **Why a `fresh session:` prefix?** Orchestrate and the pipeline skill must recognise the halt to avoid
   resuming it in place; a fixed prefix is how `relay:` is already recognised. No code reads it today (`finish`
   only needs it not to be `relay:`), so no `dispatch_cli_is_…` helper is added.
7. **Does `launch`'s check also guard a `done` manifest?** Yes, it sits in the problem chain before
   `manifest_finished()`, like the agents and tier checks. A `launch` on a finished run happens only with `--from
   review-pr` (the CI fix round), which starts a workflow anyway.
8. **Does a smoke run get the check?** Not `launch`'s: smoke `args` are built by hand. Its relay check still
   halts with the session reason when the type is missing, which on a machine without the link misnames the cause
   as the session's; acceptable for a hand-run smoke test.
9. **What does orchestrate do with the halted runs?** It asks (*resume in a fresh orchestrator* / *owner takes
   over*), since a successor orchestrator is the owner's call and the issue offers both. The fresh orchestrator's
   Step 7 resumes them through `launch` from their cursors, which `launch`'s early halt left untouched.
10. **Should orchestrate run the hook itself when the link is missing?** No: it touches only what its steps
    name, and the reason tells the owner which command to run; a fresh session's start hook makes the link
    anyway when the hook is wired.
11. **Changelog?** The repo has no `CHANGELOG.md` and no `.changelog/`: none.

## Relation to other work

- Siblings in this batch: issues #146, #168 and #169, no PR yet. No file overlap known; when one lands first, the
  base is merged as engine.md §Catching up with the base says.
- #134 / #147: this changes its check's failure reasons and adds `launch`'s link check; *Remove when* still takes
  everything out together.

## What was read

- Issue #150.
- `skills/pipeline/workflow/pipeline-autoflow.js` (`relayProblem()`, the start sequence).
- `skills/pipeline/checks/dispatch_cli.php` (`dispatch_cli_launch()`, its problem chain, `dispatch_cli_finish()`,
  `dispatch_cli_is_relay()`), `dispatch.php` (`pipeline_halt()`), `proof.php` (`PIPELINE_PROOF_ROOT`).
- `skills/pipeline/checks/tests/DispatchCliTest.php` (`dispatch_cli()`, the launch halt cases, the relay relaunch
  dataset), `AutoflowScriptTest.php` (`autoflow_start()`, the relay cases), `autoflow_replay.mjs` (`check()`).
- `skills/pipeline/agents/pipeline-relay-check.md`, `hooks/git-freshness.sh` (`GIT_FRESHNESS_AGENTS_DIR`).
- pipeline `references/engine.md` §`autoflow`, pipeline `SKILL.md` §`autoflow`, orchestrate `SKILL.md`,
  orchestrate `references/commands.md` §Where am I, §Owner, §Launch, §Finish.
- `docs/superpowers/specs/2026-10-01-pipeline-workflow-starts-clean-of-relayed-message-design.md` (§Approaches,
  *Assumptions*).
- The Workflow reference (`agent()` options; no filesystem access in scripts).
