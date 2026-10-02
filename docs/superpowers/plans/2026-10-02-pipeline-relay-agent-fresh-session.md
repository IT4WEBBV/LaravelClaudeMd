# A run that cannot load the relay-check agent names a fresh session — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A run whose relay-check agent cannot load halts with a `fresh session:` reason that names the way
out: `launch` halts before anything is written when the agent link is missing, and the workflow script halts
with the session reason when the link exists but this session predates it; orchestrate never resumes such a
halt from the session that got it.

**Architecture:** `dispatch_cli.php` gains `dispatch_cli_relay_agent_problem()`, the last link of `launch`'s
problem chain, which checks `<agents dir>/pipeline-relay-check.md` with `is_file()`. `pipeline-autoflow.js`'s
`relayProblem()` catch tells an *agent type not found* (the session case) from any other throw. The pipeline
and orchestrate docs name the `fresh session:` prefix and the resume from a new session.

**Tech Stack:** PHP 8.3+ with Pest 4 in `skills/pipeline/checks/tests`, JavaScript (the Workflow script,
replayed by node), Markdown docs.

**Spec:** `docs/superpowers/specs/2026-10-02-pipeline-relay-agent-fresh-session-design.md`. Read it with this
plan: the plan argues from it, and its `## Assumptions` 12–14 are the answers this plan assumed.

## Global Constraints

- Run every command from the worktree root
  `/Users/jroelofs/GitProjects/LaravelClaudeMd/LaravelClaudeMd/.claude/worktrees/worktree-issue-150-pipeline-the-relay-check-halts-every-workflow-of-a`.
  This repo is not a Docker project: Pest, node and php run on the host. The worktree has no `vendor/`: run
  `composer install` once before the first Pest call.
- Pest: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
  (append `--filter "<text>"` for some cases). `AutoflowScriptTest` needs `node` on `PATH`.
- Test-first: each task writes its test, sees it fail, then writes the code. `php -l` every PHP file you
  change; `node --check` fails on the script's top-level `return`, so the replay is its syntax check.
- The prefix of both new reasons is `fresh session: ` (a colon and one space). It never starts with
  `relay:`, so `dispatch_cli_is_relay()` stays as it is and `finish` answers no `relaunch` for it.
- `launch`'s reason, verbatim:
  `fresh session: ~/.claude/agents/pipeline-relay-check.md is not linked, and Claude Code reads agent types only when a session starts: run hooks/git-freshness.sh session (README.md), then resume the run from a new session`
  The path in it is always written `~/.claude/agents/…`, whatever the agents dir is.
- The script's reason, verbatim:
  `fresh session: this session started before ~/.claude/agents/pipeline-relay-check.md was linked, and Claude Code reads agent types only when a session starts: resume the run from a new session`
  It never quotes the thrown message (it holds single quotes; the session hands the return to `finish` inside
  single quotes).
- Any other throw: `the relay check failed: <message>`, with no trailing `; is … linked …?` question.
- The agents dir: `getenv('PIPELINE_AGENTS_DIR')` when set and not empty, else `$HOME/.claude/agents`.
  A dangling link counts as missing (`is_file()` follows links).
- The script's match: `RELAY_AGENT = 'pipeline-relay-check'`, the regex `` new RegExp(`${RELAY_AGENT}\\W+not found`, 'i') ``.
- No fallback to another agent when the type is missing (spec §Approaches): the check halts.
- No changelog entry: the repo has no `.changelog/` and no `CHANGELOG.md`.
- Commits: no `Co-Authored-By`, no AI attribution; every message ends on `(#150)`. Stage explicit paths.

## File Structure

| File | Responsibility |
|---|---|
| `skills/pipeline/checks/dispatch_cli.php` (modify) | `dispatch_cli_relay_agent_problem()`; `launch`'s problem chain gains it |
| `skills/pipeline/checks/tests/DispatchCliTest.php` (modify) | the helper's `PIPELINE_AGENTS_DIR`; `launch`'s link cases; `finish`'s `fresh session:` rows |
| `skills/pipeline/workflow/pipeline-autoflow.js` (modify) | `RELAY_AGENT`; `relayProblem()`'s catch names the session case |
| `skills/pipeline/checks/tests/AutoflowScriptTest.php` (modify) | the thrown-message dataset; the session halt through `finish` |
| `skills/pipeline/references/engine.md` (modify) | §`autoflow`: the `launch` bullet, the relay-check paragraph, *Remove when* |
| `skills/pipeline/SKILL.md` (modify) | §`autoflow` steps 2 and 4, *Remove when* |
| `skills/orchestrate/SKILL.md` (modify) | Steps 5 and 7, *Common mistakes* |
| `skills/orchestrate/references/commands.md` (modify) | §Launch |

## Review Focus

Inputs the spec implies and its test list does not name, most likely first. Each has its test in the task
that owns the code.

1. **A real machine, where no test seam is set** (`PIPELINE_AGENTS_DIR` empty or unset). Expected: `launch`
   reads `$HOME/.claude/agents`, halting without the link there and starting with it. Test in Task 1
   (*reads the agent link from ~/.claude/agents*).
2. **A CI fix round on a machine without the link** (`launch --from review-pr --decision …` on a `done` run).
   Expected: halts with `launch`'s reason before the re-arm, the run stays `done`, the decision is not
   appended. Test in Task 1 (*halts a CI fix round's re-arm*).
3. **A `fresh session:` halt right after a framed start** (the cursor already holds a `relay:` halt).
   Expected: no `relaunch` either way, recorded as a halt. Test in Task 1 (the `finish` dataset rows).
4. **The session halt as the invoking session hands it over**, JSON inside `finish`'s argument. Expected:
   `finish` records exactly the session reason on the start leg and answers no `relaunch`. Test in Task 2
   (*brings a fresh-session halt to finish*).
5. **A thrown message that names the type and `not found` apart**
   (`agent pipeline-relay-check returned nothing; tool Read not found`). Expected:
   `the relay check failed: …`, not the session reason. Test in Task 2 (the thrown-message dataset).

---

### Task 1: `launch` halts when the relay-check agent is not linked

**Files:**
- Modify: `skills/pipeline/checks/dispatch_cli.php:143-151` (a new function after `dispatch_cli_tier_problem()`), `:207-210` (the problem chain)
- Test: `skills/pipeline/checks/tests/DispatchCliTest.php`

**Interfaces:**
- Produces: `function dispatch_cli_relay_agent_problem(): ?string`; the test helpers
  `dispatch_agents_dir(string $kind): string` (`empty`, `dangling`, `missing`, `linked`) and the constant
  `DISPATCH_RELAY_AGENT_UNLINKED`; `dispatch_cli()` now sets `PIPELINE_AGENTS_DIR` to the repo's
  `skills/pipeline/agents` ahead of the caller's `$env`, so every other `launch` in the suite (Task 2's
  `autoflow_start()` included) still starts.

- [ ] **Step 1: Point the test helper at the repo's own agents dir**

In `DispatchCliTest.php`, `dispatch_cli()`'s environment line becomes:

```php
        [...getenv(), 'PIPELINE_PROOF_ROOT' => sys_get_temp_dir() . '/pipeline-proofs-' . uniqid(), 'PIPELINE_AGENTS_DIR' => realpath(__DIR__ . '/../../agents'), ...$env],
```

- [ ] **Step 2: Write the failing tests**

After the *halts a launch whose tier is not medium or light* test (its dataset ends at line 279), add:

```php
const DISPATCH_RELAY_AGENT_UNLINKED = 'fresh session: ~/.claude/agents/pipeline-relay-check.md is not linked, and Claude Code reads agent types only when a session starts: run hooks/git-freshness.sh session (README.md), then resume the run from a new session';

/** A temp agents dir as `$kind` leaves it: `empty`; `dangling`, a pipeline-relay-check.md link whose target is gone; `missing`, no such directory; `linked`, a link to the repo's agent definition. */
function dispatch_agents_dir(string $kind): string
{
    $dir = sys_get_temp_dir() . '/pipeline-agents-' . uniqid();
    if ($kind === 'missing') {
        return $dir;
    }
    mkdir($dir);
    match ($kind) {
        'empty' => null,
        'dangling' => symlink($dir . '/gone.md', $dir . '/pipeline-relay-check.md'),
        'linked' => symlink(realpath(__DIR__ . '/../../agents/pipeline-relay-check.md'), $dir . '/pipeline-relay-check.md'),
    };

    return $dir;
}

it('halts a launch when the relay-check agent is not linked, and leaves the manifest as it was (#150)', function (string $kind) {
    $fixture = dispatch_fixture(['mode' => 'autoflow']);
    $before = file_get_contents($fixture['manifest']);

    expect(dispatch_cli(['launch', $fixture['manifest'], $fixture['diff'], '--decision', 'Keep the guard'], ['PIPELINE_AGENTS_DIR' => dispatch_agents_dir($kind)])['json'])
        ->toBe(['action' => 'halt', 'reason' => DISPATCH_RELAY_AGENT_UNLINKED]);
    expect(file_get_contents($fixture['manifest']))->toBe($before);
})->with([
    'an empty agents dir' => ['empty'],
    'a dangling link' => ['dangling'],
    'no agents dir' => ['missing'],
]);

it('launches when the relay-check agent is linked through a symlink (#150)', function () {
    $fixture = dispatch_fixture(['mode' => 'autoflow']);

    expect(dispatch_cli(['launch', $fixture['manifest'], $fixture['diff']], ['PIPELINE_AGENTS_DIR' => dispatch_agents_dir('linked')])['json']['action'])
        ->toBe('start');
});

it('reads the agent link from ~/.claude/agents when PIPELINE_AGENTS_DIR is empty (#150)', function (string $kind, array $answer) {
    $fixture = dispatch_fixture(['mode' => 'autoflow']);
    $home = sys_get_temp_dir() . '/pipeline-home-' . uniqid();
    mkdir($home . '/.claude', 0777, true);
    rename(dispatch_agents_dir($kind), $home . '/.claude/agents');

    expect(dispatch_cli(['launch', $fixture['manifest'], $fixture['diff']], ['HOME' => $home, 'PIPELINE_AGENTS_DIR' => ''])['json'])
        ->toMatchArray($answer);
})->with([
    'not linked there' => ['empty', ['action' => 'halt', 'reason' => DISPATCH_RELAY_AGENT_UNLINKED]],
    'linked there' => ['linked', ['action' => 'start']],
]);

it('halts a CI fix round\'s re-arm when the relay-check agent is not linked, and keeps the run done (#150)', function () {
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'review-pr', 'status' => 'done']]);
    $before = file_get_contents($fixture['manifest']);

    expect(dispatch_cli(['launch', $fixture['manifest'], $fixture['diff'], '--from', 'review-pr', '--decision', 'CI is red'], ['PIPELINE_AGENTS_DIR' => dispatch_agents_dir('empty')])['json'])
        ->toBe(['action' => 'halt', 'reason' => DISPATCH_RELAY_AGENT_UNLINKED]);
    expect(file_get_contents($fixture['manifest']))->toBe($before);
});
```

The `linked` symlink's target is absolute, so `rename()` of its directory keeps it pointing at the file.

In the *answers a relay halt with one relaunch* dataset (after its `'any other halt'` row), add two rows:

```php
    'a fresh-session halt (#150)' => [
        ['leg' => 'implement', 'status' => 'pending'],
        '{"action":"halt","leg":"implement","reason":"fresh session: x"}',
        ['action' => 'halt', 'reason' => 'fresh session: x'],
        ['leg' => 'implement', 'status' => 'halted', 'reason' => 'fresh session: x'],
    ],
    'a fresh-session halt after a relay halt (#150)' => [
        ['leg' => 'implement', 'status' => 'halted', 'reason' => 'relay: x'],
        '{"action":"halt","leg":"implement","reason":"fresh session: x"}',
        ['action' => 'halt', 'reason' => 'fresh session: x'],
        ['leg' => 'implement', 'status' => 'halted', 'reason' => 'fresh session: x'],
    ],
```

- [ ] **Step 3: Run the tests to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "#150"`
Expected: FAIL on the three *halts a launch …* cases, the *not linked there* case and the *CI fix round* case
(each answers something other than `launch`'s link halt; the CI case's re-arm also writes the manifest). The
*linked through a symlink* case, the *linked there* case and the two `finish` rows PASS already: they pin what
must keep holding (`finish` already answers no `relaunch` for any reason that does not start with `relay:`).

- [ ] **Step 4: Write the function**

In `dispatch_cli.php`, after `dispatch_cli_tier_problem()`:

```php
/**
 * Why this machine cannot run the script's relay check: its agent type is not linked where Claude Code reads it
 * (`../references/engine.md` §`autoflow`), or null. The dir is `PIPELINE_AGENTS_DIR`, a test seam as
 * `PIPELINE_PROOF_ROOT` is, else `~/.claude/agents`, where `hooks/git-freshness.sh` links it. `is_file()`
 * follows a link, so a dangling one counts as missing.
 */
function dispatch_cli_relay_agent_problem(): ?string
{
    $override = getenv('PIPELINE_AGENTS_DIR');
    $dir = is_string($override) && $override !== '' ? rtrim($override, '/') : rtrim((string) getenv('HOME'), '/') . '/.claude/agents';

    return is_file($dir . '/pipeline-relay-check.md')
        ? null
        : 'fresh session: ~/.claude/agents/pipeline-relay-check.md is not linked, and Claude Code reads agent types only when a session starts: run hooks/git-freshness.sh session (README.md), then resume the run from a new session';
}
```

In `dispatch_cli_launch()`, the problem chain becomes:

```php
    $problem = dispatch_cli_invalid($manifest)
        ?? dispatch_cli_mode_problem('launch starts autoflow runs', $manifest)
        ?? dispatch_cli_agents_problem($manifest)
        ?? dispatch_cli_tier_problem($manifest)
        ?? dispatch_cli_relay_agent_problem();
```

It returns through `pipeline_halt($problem)` before `--from`'s re-arm and the `--decision` write, as the other
links do. That also puts it before `manifest_finished()`: a plain `launch` on a `done` run, on a machine without
the link, answers the `fresh session:` reason instead of `done` (spec Assumption 7, an accepted trade-off).

- [ ] **Step 5: Run the tests to see them pass**

Run: `php -l skills/pipeline/checks/dispatch_cli.php && ./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "DispatchCliTest|AutoflowScriptTest"`
Expected: `No syntax errors detected`, then PASS, every case (the helper's `PIPELINE_AGENTS_DIR` keeps every
older `launch` starting).

- [ ] **Step 6: Commit**

```bash
git add skills/pipeline/checks/dispatch_cli.php skills/pipeline/checks/tests/DispatchCliTest.php
git commit -m "feat(pipeline): launch halts with fresh session: when the relay-check agent is not linked (#150)"
```

---

### Task 2: the script names the session case

**Files:**
- Modify: `skills/pipeline/workflow/pipeline-autoflow.js:25-31` (the relay constants), `:90-101` (`relayProblem()`)
- Test: `skills/pipeline/checks/tests/AutoflowScriptTest.php:563-568`

**Interfaces:**
- Consumes: `dispatch_cli()` with Task 1's `PIPELINE_AGENTS_DIR` (through `autoflow_start()`), so `launch`
  answers `start`; the replay fake's `input.relay.throw` (unchanged: it throws `new Error(<message>)`).
- Produces: the script constant `RELAY_AGENT = 'pipeline-relay-check'`; the test constant
  `AUTOFLOW_FRESH_SESSION`.

- [ ] **Step 1: Write the failing tests**

In `AutoflowScriptTest.php`, replace the test *halts without the relay prefix when the check itself fails
(#134)* (lines 563–568) with:

```php
const AUTOFLOW_FRESH_SESSION = 'fresh session: this session started before ~/.claude/agents/pipeline-relay-check.md was linked, and Claude Code reads agent types only when a session starts: resume the run from a new session';

it('halts without the relay prefix when the check itself fails, naming a fresh session when its agent type is not found (#134, #150)', function (string $thrown, string $reason) {
    $replay = autoflow_replay(autoflow_start('handoff'), [], input: ['relay' => ['throw' => $thrown]]);

    expect($replay['labels'])->toBe([]);
    expect($replay['result'])->toBe(['action' => 'halt', 'leg' => 'handoff', 'reason' => $reason]);
})->with([
    'the type not found, as Claude Code words it' => ["agent({agentType}): agent type 'pipeline-relay-check' not found. Available agents: claude, Explore", AUTOFLOW_FRESH_SESSION],
    'other quotes, other case' => ['agent type "Pipeline-Relay-Check" not found', AUTOFLOW_FRESH_SESSION],
    'another failure' => ['the schema is unsatisfiable', 'the relay check failed: the schema is unsatisfiable'],
    'the type unknown, not the not-found wording' => ['unknown agent type pipeline-relay-check', 'the relay check failed: unknown agent type pipeline-relay-check'],
    'the type and not found, apart' => ['agent pipeline-relay-check returned nothing; tool Read not found', 'the relay check failed: agent pipeline-relay-check returned nothing; tool Read not found'],
]);

it('brings a fresh-session halt to finish, which records it and relaunches nothing (#150)', function () {
    $start = autoflow_start('handoff');
    $replay = autoflow_replay($start, [], input: ['relay' => ['throw' => "agent type 'pipeline-relay-check' not found"]]);

    expect(dispatch_cli(['finish', $start['manifest'], json_encode($replay['result'])])['json'])
        ->toBe(['action' => 'halt', 'reason' => AUTOFLOW_FRESH_SESSION]);
    expect(manifest_read($start['manifest'])['cursor'])->toBe(['leg' => 'handoff', 'status' => 'halted', 'reason' => AUTOFLOW_FRESH_SESSION]);
});
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "AutoflowScriptTest"`
Expected: FAIL on all five dataset cases (today's reason ends on `; is ~/.claude/agents/pipeline-relay-check.md
linked (hooks/git-freshness.sh)?`, and the first two are not `fresh session:`) and on *brings a fresh-session
halt to finish*; every other case PASS.

- [ ] **Step 3: Write the script change**

In `pipeline-autoflow.js`, after `RELAY_SCHEMA`:

```js
const RELAY_AGENT = 'pipeline-relay-check'
const RELAY_AGENT_MISSING = new RegExp(`${RELAY_AGENT}\\W+not found`, 'i')
```

`relayProblem()` and the comment above it become:

```js
// Why the run may not start: a head that is not the clean label or the prompt halts as `relay:`, which
// finish relaunches once. An agent type not found halts as `fresh session:`: launch found the link, so this
// session started before it and only a new one loads the type (#150). Any other throw halts as `the relay
// check failed:`. Neither of those two carries `relay:`, since a relaunch from this session would fail alike.
async function relayProblem() {
  try {
    const result = await agent(RELAY_PROMPT, { label: 'relay-check', phase: args.startLeg, agentType: RELAY_AGENT, schema: RELAY_SCHEMA, ...setting(agents.smoke) })
    const head = typeof result?.head === 'string' ? normalised(result.head) : null
    const clean = head && [RELAY_LABEL, RELAY_PROMPT.slice(0, 40)].some(start => head.startsWith(normalised(start)))
    return clean ? null : `relay: the run's first agent did not receive its own task first (head: ${head === null ? 'none' : `"${head}"`})`
  } catch (error) {
    const message = String(error?.message ?? error)
    return RELAY_AGENT_MISSING.test(message)
      ? `fresh session: this session started before ~/.claude/agents/${RELAY_AGENT}.md was linked, and Claude Code reads agent types only when a session starts: resume the run from a new session`
      : `the relay check failed: ${message}`
  }
}
```

The session reason leaves the thrown message out on purpose (it holds single quotes).

- [ ] **Step 4: Run the tests to see them pass**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter "AutoflowScriptTest|LockStepTest"`
Expected: PASS, every case (`LockStepTest` checks the script still names no model or effort).

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/workflow/pipeline-autoflow.js skills/pipeline/checks/tests/AutoflowScriptTest.php
git commit -m "feat(pipeline): the relay check names a fresh session when its agent type is not found (#150)"
```

---

### Task 3: the docs name the `fresh session:` halt and its resume

**Files:**
- Modify: `skills/pipeline/references/engine.md` (§`autoflow`, lines ~110–112, ~143–145, ~181–184)
- Modify: `skills/pipeline/SKILL.md` (§`autoflow` step 2 line ~108, step 4 lines ~132–135, *Remove when* ~152–154)
- Modify: `skills/orchestrate/SKILL.md` (Step 5 line 28, Step 7 line 30, *Common mistakes* after line 51)
- Modify: `skills/orchestrate/references/commands.md` (§Launch, the *`launch` answers `done` or a halt* bullet)

**Interfaces:**
- Consumes: the two reasons and the prefix from *Global Constraints*; `dispatch_cli_relay_agent_problem()`
  (Task 1).

- [ ] **Step 1: engine.md §`autoflow`**

The `launch` bullet ends on:

```
  `full` and the one exemption as a run does; an invalid `agents` override in the
  manifest halts `launch` with the other manifest checks, before anything is written.
```

Append to it:

```
  So does a relay-check agent that is not linked (`dispatch_cli_relay_agent_problem()`): when
  `~/.claude/agents/pipeline-relay-check.md` is not a file (no link, or a dangling one), `launch` halts with
  `fresh session: ~/.claude/agents/pipeline-relay-check.md is not linked, …`, which names the hook to run and a
  new session to resume from: Claude Code reads agent types only when a session starts (#150).
```

In the relay-check paragraph, replace

```
  manifest is as `launch` left it; a check that throws halts with `the relay check failed: …` and no
  `relay:` prefix. The start-step check runs before it, so a halt that needs no agent still starts none.
```

with

```
  manifest is as `launch` left it. A check whose agent type is not found halts with `fresh session: this
  session started before … was linked, …`: `launch` found the link, so this session predates it (#150). Any
  other throw halts with `the relay check failed: <message>`. Neither carries `relay:`, so `finish` relaunches
  neither: a start from the same session halts alike, and the run is resumed from a new session. The
  start-step check runs before it, so a halt that needs no agent still starts none.
```

*Remove when*: replace `§\`autoflow\` step 3), the check, \`../agents/pipeline-relay-check.md\` and the hook's
agents link go` with `§\`autoflow\` step 3), the check, \`launch\`'s link check and the \`fresh session:\`
reasons, \`../agents/pipeline-relay-check.md\` and the hook's agents link go` (keep the line wrapping at 110
columns).

- [ ] **Step 2: pipeline `SKILL.md` §`autoflow`**

Step 2, after `` `done` or a halt: report it and stop. A resume starts here: `launch` starts from the cursor. ``
add:

```
   A `fresh session:` halt is resumed from a new session, where `/pipeline` runs `launch` from the cursor:
   in this session every start halts alike.
```

Step 4, after `` `relay:` halt without `relaunch` is a halt like any other. `` add:

```
   A `fresh session:` halt (the relay check found its agent type missing) is resumed from a new session, as
   in step 2.
```

*Remove when*: `the detour, the relay check, \`agents/pipeline-relay-check.md\` and the hook's agents link go
together` becomes `the detour, the relay check, \`launch\`'s link check and the \`fresh session:\` reasons,
\`agents/pipeline-relay-check.md\` and the hook's agents link go together`.

- [ ] **Step 3: orchestrate `SKILL.md`**

Step 5 (line 28): after `Halted: ask *resume after <fix>* / *leave it out* / *owner takes over*, quoting the
reason.` insert:

```
A `fresh session:` halt (from `launch` or `finish`) means this session can start no run: start no further run in it, and ask *resume in a fresh orchestrator* (recommended: `spinoff` an orchestrator over the batch's unfinished issues, as the launcher does, commands §Where am I; this session then dispatches nothing more) / *owner takes over*, quoting the reason.
```

(on the same line: the step is one paragraph.)

Step 7 (line 30): append ` A \`fresh session:\` halt is resumed only from a session started after the link
existed, never relaunched from the one that halted.`

*Common mistakes*: after the row *Relaunching a stopped `autoflow` run without `finish`*, add:

```
| Resuming a `fresh session:` halt in the same session | Claude Code reads agent types once per session: the resume halted again (`wf_a682740e-f05`, after the hook had made the link). |
```

- [ ] **Step 4: orchestrate `references/commands.md` §Launch**

The bullet `` - `launch` answers `done` or a halt: report it and start no workflow. `` becomes:

```
- `launch` answers `done` or a halt: report it and start no workflow. A `fresh session:` halt is Step 5's,
  for every issue still to start: no further `launch` from this session.
```

- [ ] **Step 5: Check the docs and run the suite**

Run: `grep -c "fresh session:" skills/pipeline/references/engine.md skills/pipeline/SKILL.md skills/orchestrate/SKILL.md skills/orchestrate/references/commands.md && ! grep -rn "is ~/.claude/agents/pipeline-relay-check.md linked" skills`
Expected: a count of at least 1 per file (engine.md 3, SKILL.md 3, orchestrate SKILL.md 3, commands.md 1), and
the second grep finds nothing (exit 0 from `!`).

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, the whole pipeline checks suite (`LockStepTest` reads engine.md and SKILL.md).

- [ ] **Step 6: Commit**

```bash
git add skills/pipeline/references/engine.md skills/pipeline/SKILL.md skills/orchestrate/SKILL.md skills/orchestrate/references/commands.md
git commit -m "docs(pipeline,orchestrate): a fresh session: halt is resumed from a new session (#150)"
```
