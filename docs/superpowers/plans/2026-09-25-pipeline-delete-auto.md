# Delete the `auto` engine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task by task. Steps use checkbox (`- [ ]`) syntax for tracking. In an `autoflow` implement step, execute inline, task by task, with no subagents.

**Goal:** `autoflow` is the only unattended mode. Nothing starts an `auto` run; `kickoff --mode auto` and every command on an `auto` manifest halt with a message naming `autoflow`. `interactive` keeps `next` / `returned` and runs exactly as before.

**Architecture:**
- `pipeline_retired_mode()` in `dispatch.php` is pure: the refusal for `auto`, null for every other mode. `dispatch_cli.php` answers it as a halt in `kickoff`, `next`, `returned`, `launch`, `brief` and `finish`, and writes nothing.
- `pipeline_runs_inline()` treats everything but `autoflow` as `interactive`; `brief.php`'s `$auto` (which meant `autoflow`) becomes `$autoflow`.
- `engine_peak.php`, `engine_peak_cli.php` and `EnginePeakTest` go.
- `engine.md` §`auto` becomes §Resolving a review; `LockStepTest` pins every `engine.md §<name>` a brief points at to a real heading.
- The docs of `pipeline`, `orchestrate` and `browser-verification` keep only `autoflow` and `interactive`.

**Tech Stack:** PHP 8.4 on the host, Pest 4 (`./vendor/bin/pest`), Python 3 (the doc-edit scripts below), Markdown skill references.

**Spec:** `docs/superpowers/specs/2026-09-25-pipeline-delete-auto-design.md`

**Verified before writing (2026-09-25):**
- Every code change, test change and doc-edit script in this plan was applied to a scratch copy of this branch (at `0655fc9`, before the spec commit). The pipeline suite passes there with 306 tests, against 301 on `main`: +1 in `DispatchTest`, +6 in `DispatchCliTest` (a 5-row dataset and one kickoff test), +1 in `LockStepTest`, −3 with `EnginePeakTest`. The orchestrate test (`owners_test.sh`) and the critique suite (14 tests) pass unchanged.
- The three doc-edit scripts were then re-applied to a fresh copy of the branch: each applies cleanly, and every `old` text occurs exactly once (the scripts assert it).
- Red first, as observed: Task 1's new test fails on `pipeline_retired_mode()` being undefined; Task 2's six new tests fail on behaviour once it exists; Task 3's `LockStepTest` addition passes on `main` and fails as soon as the brief points at §Resolving a review, until the heading is renamed.
- **`vendor/` must be a real directory, not a symlink to the primary checkout's.** Pest takes the project root from the real path of `vendor/`, so with a symlink the in-process tests load `main`'s `checks/*.php`. If the worktree has no `vendor/`: `cp -R <primary checkout>/vendor vendor` (it is gitignored).

## Global Constraints

- The refusal text is verbatim, from one place: `mode auto was removed; autoflow is the unattended mode: kick off without --mode, and resume an auto manifest by setting its mode to autoflow and running launch`. Tests compare against `pipeline_retired_mode('auto')`, except `DispatchTest`, which pins the string.
- A refusal writes nothing: no manifest change, no brief, no snapshot, no worktree, no gh call.
- `next`: the retired check runs before today's autoflow refusal and before the finished check. `returned`: after the snapshot, manifest and diff are read, on the snapshot's `mode`. `launch` / `brief` / `finish`: inside `dispatch_cli_mode_problem()`, before the non-`autoflow` refusal. `kickoff`: after parsing, before `pipeline_kickoff()`.
- `kickoff`'s parser is unchanged (it still accepts `--mode autoflow` and `--mode auto`; `--mode interactive` stays a usage error). Its docs no longer show `--mode`.
- The `interactive` tests of `next` / `returned` keep their assertions. Only `dispatch_fixture()`'s default `mode` changes, from `auto` to `interactive`.
- No halt message in `dispatch.php` or `dispatch_cli.php` changes wording except through the new check.
- Suites (from the worktree root, on the host):
  - `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
  - `./vendor/bin/pest -c skills/critique/checks/phpunit.xml --test-directory=skills/critique/checks/tests`
  - `bash skills/orchestrate/tests/owners_test.sh`
- The doc-edit scripts take the worktree root as their only argument. Write each to `$TMPDIR` and run it from there; they are not committed.

## Inputs nobody named

- **A finished `auto` manifest** (three exist on the owner's machine, all `done`): `next` refuses it like any `auto` manifest, because the retired check comes first. Pinned by the `next` row of Task 2's dataset (a `pending` cursor; the order is in the code, Step 3).
- **A mangled mode** keeps behaving as `interactive`. Pinned by Task 1's `mangled` rows.
- **`--mode autoflow` on a kickoff** keeps working. Pinned by Task 2's rewritten *"writes the mode, light and the decisions verbatim"*.
- **A section name followed by prose** (`§Suite reuse finds this tree green`) must not fail the new lock-step test. Pinned by the prefix rule in Task 3.

## File Structure

- Modify `skills/pipeline/checks/dispatch.php`: `pipeline_runs_inline()`, new `pipeline_retired_mode()`.
- Modify `skills/pipeline/checks/dispatch_cli.php`: the refusals, the kickoff halt, the docblocks and the usage line.
- Modify `skills/pipeline/checks/brief.php`: `$auto` → `$autoflow`, the two §`auto` pointers.
- Delete `skills/pipeline/checks/engine_peak.php`, `skills/pipeline/checks/engine_peak_cli.php`, `skills/pipeline/checks/tests/EnginePeakTest.php`; modify `skills/pipeline/checks/tests/Pest.php`.
- Tests: `DispatchTest.php`, `DispatchCliTest.php`, `BriefTest.php`, `LockStepTest.php`, `ReturnedTest.php`, `ProofRenderTest.php`.
- Docs: `skills/pipeline/SKILL.md`, `skills/pipeline/references/{engine,gates,manifest}.md`, `skills/orchestrate/SKILL.md`, `skills/orchestrate/references/commands.md`, `skills/browser-verification/SKILL.md`.

---

### Task 1: `pipeline_retired_mode()` and `pipeline_runs_inline()`

**Files:**
- Modify: `skills/pipeline/checks/dispatch.php` (the `pipeline_runs_inline()` block)
- Test: `skills/pipeline/checks/tests/DispatchTest.php`

**Interfaces:**
- Produces: `pipeline_retired_mode(string $mode): ?string`, loaded wherever `dispatch.php` is (every entry point and `tests/Pest.php`).

- [ ] **Step 1: Write the failing test and update the inline test**

In `DispatchTest.php`, `dispatch_manifest()`'s `'mode' => 'auto'` becomes `'mode' => 'interactive'`. Replace the inline test with:

```php
it('runs design and the resolve step inline outside autoflow', function () {
    expect(pipeline_runs_inline('interactive', 'design', 'run'))->toBeTrue();
    expect(pipeline_runs_inline('interactive', 'review-pr', 'resolve'))->toBeTrue();
    expect(pipeline_runs_inline('interactive', 'review-pr', 'review'))->toBeFalse();
    expect(pipeline_runs_inline('interactive', 'implement', 'run'))->toBeFalse();
    expect(pipeline_runs_inline('mangled', 'design', 'run'))->toBeTrue();
    expect(pipeline_runs_inline('autoflow', 'design', 'run'))->toBeFalse();
    expect(pipeline_runs_inline('autoflow', 'review-pr', 'resolve'))->toBeFalse();
    expect(pipeline_runs_inline('mangled', 'review-plan', 'resolve'))->toBeTrue();
});
```

Append:

```php

it('refuses the removed auto mode by naming autoflow, and nothing else', function () {
    expect(pipeline_retired_mode('auto'))->toBe('mode auto was removed; autoflow is the unattended mode: kick off without --mode, and resume an auto manifest by setting its mode to autoflow and running launch');
    expect(pipeline_retired_mode('autoflow'))->toBeNull();
    expect(pipeline_retired_mode('interactive'))->toBeNull();
    expect(pipeline_retired_mode('mangled'))->toBeNull();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='removed auto mode'`
Expected: FAIL, `Call to undefined function pipeline_retired_mode()`.

- [ ] **Step 3: Implement** (replace the `pipeline_runs_inline()` docblock and function in `dispatch.php`)

```php
/** Anything that is not `autoflow` behaves as interactive (`gates.md` §Modes): the human designs and resolves. */
function pipeline_runs_inline(string $mode, string $leg, string $step): bool
{
    return $mode !== 'autoflow' && ($leg === 'design' || $step === 'resolve');
}

/** `auto`, the dispatcher engine, was removed (#87): every command refuses it by naming the mode that replaced it. */
function pipeline_retired_mode(string $mode): ?string
{
    return $mode === 'auto'
        ? 'mode auto was removed; autoflow is the unattended mode: kick off without --mode, and resume an auto manifest by setting its mode to autoflow and running launch'
        : null;
}
```

- [ ] **Step 4: Run the pipeline suite**

Expected: 302 passed.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/dispatch.php skills/pipeline/checks/tests/DispatchTest.php
git commit -m "feat(pipeline): pipeline_retired_mode() names autoflow for the removed auto mode (#87)"
```

---

### Task 2: every command refuses `auto`

**Files:**
- Modify: `skills/pipeline/checks/dispatch_cli.php` (`dispatch_cli_next()`, `dispatch_cli_mode_problem()`, `dispatch_cli_returned()`, `dispatch_cli_kickoff()`, the file docblock, `dispatch_cli_kickoff_args()`'s docblock, the usage line)
- Test: `skills/pipeline/checks/tests/DispatchCliTest.php`

**Interfaces:**
- Consumes: `pipeline_retired_mode()` (Task 1).

- [ ] **Step 1: Update the fixture and the tests that quoted its mode**

In `DispatchCliTest.php`:
- `dispatch_fixture()`: `'mode' => 'auto',` becomes `'mode' => 'interactive',`.
- `it('runs design inline outside auto', …` is renamed `it('runs design inline in an interactive run', …`.
- *"launches only autoflow runs, and next refuses one"*: `$auto` becomes `$interactive` (three uses) and the expected reason becomes `"launch starts autoflow runs; this run's mode is interactive (resume it with /pipeline, which uses next)"`.
- *"serves brief and finish on autoflow runs only"*: in both dataset rows, `this run's mode is auto` becomes `this run's mode is interactive`.
- *"writes the mode, light and the decisions verbatim into the first manifest"*: `'--mode', 'auto'` becomes `'--mode', 'autoflow'`, and the expected `'mode' => 'auto'` becomes `'mode' => 'autoflow'`.

- [ ] **Step 2: Write the failing tests**

After the *"serves brief and finish on autoflow runs only"* test and its dataset, add:

```php

it('refuses a manifest that still says auto in every command, naming autoflow, and leaves it alone', function (array $arguments) {
    $fixture = dispatch_fixture(['mode' => 'auto']);
    manifest_write($fixture['before'], manifest_read($fixture['manifest']));
    $before = file_get_contents($fixture['manifest']);
    $files = ['<manifest>' => $fixture['manifest'], '<diff>' => $fixture['diff']];

    expect(dispatch_cli(array_map(fn (string $argument) => $files[$argument] ?? $argument, $arguments))['json'])
        ->toBe(['action' => 'halt', 'reason' => pipeline_retired_mode('auto')]);
    expect(file_get_contents($fixture['manifest']))->toBe($before);
    expect(is_file($fixture['brief']))->toBeFalse();
})->with([
    'next' => [['next', '<manifest>']],
    'returned' => [['returned', '<manifest>', '<diff>']],
    'launch' => [['launch', '<manifest>', '<diff>']],
    'brief' => [['brief', '<manifest>', 'review-plan', 'review']],
    'finish' => [['finish', '<manifest>', '{"action":"done"}']],
]);
```

Before `it('kicks off an idea on a feature branch without asking gh', …`, add:

```php
it('halts a kickoff for the removed auto mode before anything is created, naming autoflow', function () {
    $fixture = kickoff_fixture();

    expect(kickoff($fixture, ['69', '--mode', 'auto']))->toMatchArray(['code' => 0, 'json' => ['action' => 'halt', 'reason' => pipeline_retired_mode('auto')]]);
    kickoff_left_nothing($fixture);
    expect(kickoff_calls($fixture))->toBe([]);
});

```

- [ ] **Step 3: Run them to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='still says auto|removed auto mode before'`
Expected: 6 FAIL. `next` prints the dispatch line and writes a brief, `returned` a retry, `launch` / `brief` / `finish` the non-`autoflow` refusal naming mode `auto`, and `kickoff` creates the worktree.

- [ ] **Step 4: Implement** (in `dispatch_cli.php`)

`dispatch_cli_next()`, replace the autoflow refusal:

```php
    $mode = (string) ($manifest['mode'] ?? '');
    $refusal = pipeline_retired_mode($mode) ?? ($mode === 'autoflow' ? 'an autoflow run resumes with launch, not next' : null);
    if ($refusal !== null) {
        return pipeline_halt($refusal);
    }
```

`dispatch_cli_mode_problem()`:

```php
/** `launch`, `brief` and `finish` serve `autoflow` runs only; a valid manifest of any other mode but the removed `auto` resumes with `next`. */
function dispatch_cli_mode_problem(string $refusal, array $manifest): ?string
{
    return pipeline_retired_mode($manifest['mode']) ?? ($manifest['mode'] === 'autoflow' ? null : "{$refusal}; this run's mode is {$manifest['mode']} (resume it with /pipeline, which uses next)");
}
```

`dispatch_cli_returned()`, right after the missing-file halt:

```php
    $retired = pipeline_retired_mode((string) ($before['mode'] ?? ''));
    if ($retired !== null) {
        return pipeline_halt($retired);
    }
```

`dispatch_cli_kickoff()`:

```php
function dispatch_cli_kickoff(array $arguments): ?array
{
    $parsed = dispatch_cli_kickoff_args($arguments);
    if ($parsed === null) {
        return null;
    }
    $retired = pipeline_retired_mode($parsed['mode']);

    return $retired === null ? pipeline_kickoff($parsed['repoRoot'], $parsed['item'], $parsed) : pipeline_halt($retired);
}
```

The docs in the same file:
- the file docblock's kickoff line: `[--light] [--mode autoflow|auto] [--decision <text>]...` becomes `[--light] [--decision <text>]...`;
- `dispatch_cli_kickoff_args()`'s docblock, first two lines, become:

```php
/**
 * `kickoff <repo-root> <number|idea> [--light] [--decision <text>]...`; null is a usage error. `--mode
 * autoflow` is accepted and changes nothing; `--mode auto` parses, so that `dispatch_cli_kickoff()` can
 * halt it by name. `interactive` keeps its session-driven kickoff.
```

- the usage line (`fwrite(STDERR, "usage: …`): `kickoff <repo-root> <number|idea> [--light] [--mode autoflow|auto] [--decision <text>]...` becomes `kickoff <repo-root> <number|idea> [--light] [--decision <text>]...`.

- [ ] **Step 5: Run the pipeline suite**

Expected: 308 passed.

- [ ] **Step 6: Commit**

```bash
git add skills/pipeline/checks/dispatch_cli.php skills/pipeline/checks/tests/DispatchCliTest.php
git commit -m "feat(pipeline): kickoff --mode auto and every command on an auto manifest halt, naming autoflow (#87)"
```

---

### Task 3: §Resolving a review, the brief's pointers and `engine.md`

**Files:**
- Modify: `skills/pipeline/checks/brief.php` (`pipeline_leg_overrides()`)
- Modify: `skills/pipeline/references/engine.md` (script below)
- Test: `skills/pipeline/checks/tests/LockStepTest.php`, `skills/pipeline/checks/tests/BriefTest.php`

- [ ] **Step 1: Add the lock-step test** (append to `LockStepTest.php`)

```php

it('keeps every engine.md section a brief names', function () {
    preg_match_all('/^## (.+?)(?: — .*)?$/m', (string) file_get_contents(__DIR__ . '/../../references/engine.md'), $headings);
    $lines = array_merge(...array_values(pipeline_leg_overrides('autoflow')), ...array_values(pipeline_leg_overrides('interactive')));
    preg_match_all('/§([^,):;]+)/', implode("\n", $lines), $names);

    expect($names[1])->not->toBeEmpty();
    foreach ($names[1] as $name) {
        expect(array_filter($headings[1], fn (string $heading) => str_starts_with($name, $heading)))->not->toBeEmpty("engine.md has no section '{$name}'");
    }
});
```

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='brief names'`
Expected: PASS. Today every pointer resolves, §`auto` included.

- [ ] **Step 2: Point the brief at the new section, and watch the test fail**

In `brief.php`'s `pipeline_leg_overrides()`:
- `$auto = $mode === 'autoflow';` becomes `$autoflow = $mode === 'autoflow';`, and each of its six uses `$auto` becomes `$autoflow`;
- both `engine.md §\`auto\`` become `engine.md §Resolving a review` (in `$actOnReview`'s first line and in `review-plan:resolve`'s independent-read line).

```bash
cd skills/pipeline/checks
perl -pi -e 's/\$auto\b/\$autoflow/g; s/engine\.md §`auto`/engine.md §Resolving a review/g' brief.php
grep -c '\$autoflow' brief.php            # 7
grep -c 'Resolving a review' brief.php     # 2
cd -
```

Run the filtered test again. Expected: FAIL, `engine.md has no section 'Resolving a review'`.

- [ ] **Step 3: Edit `engine.md`**

Write this script to `$TMPDIR/p87_engine.py` and run `python3 "$TMPDIR/p87_engine.py" "$(pwd)"` from the worktree root:

````python
import sys
root = sys.argv[1]
p = root + '/skills/pipeline/references/engine.md'
s = open(p).read()
def rep(old, new):
    global s
    assert s.count(old) == 1, (s.count(old), old)
    s = s.replace(old, new)

# §The loop
rep("""All three modes walk the same legs with the same briefs, which `autoflow` extends (§What a leg brief
consists of). They differ in who holds the loop:

| Mode | Who holds the loop | Commands |
|---|---|---|
| `auto` | the dispatcher, one background agent (§The dispatcher) | `next` / `returned`, below |
| `interactive` | the session; the human resolves each review (§Interactive) | `next` / `returned`, below |
| `autoflow` | the saved workflow `pipeline-autoflow`, a program (§`autoflow`) | `launch` / `brief` / `finish` |

`launch` refuses a manifest whose mode is not `autoflow`, and `next` refuses one whose mode is.""",
"""Both modes walk the same legs with the same briefs, which `autoflow` extends (§What a leg brief
consists of). They differ in who holds the loop:

| Mode | Who holds the loop | Commands |
|---|---|---|
| `interactive` | the session; the human resolves each review (§Interactive) | `next` / `returned`, below |
| `autoflow` | the saved workflow `pipeline-autoflow`, a program (§`autoflow`) | `launch` / `brief` / `finish` |

`launch` refuses a manifest whose mode is not `autoflow`, and `next` refuses one whose mode is. Every
command, `kickoff --mode auto` included, refuses `auto`, the dispatcher engine #87 removed, with a halt
that names `autoflow` (`pipeline_retired_mode()`). An `auto` manifest resumes once its `mode` says
`autoflow`, through `launch`.""")
rep("""  §What a leg writes) and replies with one line. The dispatcher never reads that reply for content:""",
"""  §What a leg writes) and replies with one line. The session never reads that reply for content:""")

# §The dispatcher: delete
start = s.index("## The dispatcher — what it does, and never does\n")
end = s.index("## `autoflow` — a program that calls agents\n")
s = s[:start] + s[end:]

# §autoflow
rep("""  functions `auto` and `interactive` route by, so the script keeps no copy of them.""",
"""  functions `interactive` routes by, so the script keeps no copy of them.""")
rep("""`--after` which step returned, compares the manifest with that step's snapshot, as `returned` does in
`auto`: `pipeline_reported_problem()`""",
"""`--after` which step returned, compares the manifest with that step's snapshot, as `returned` does in
`interactive`: `pipeline_reported_problem()`""")

# §Interactive
rep("""completes the entry with the human's `actions` and `outcome`, and runs `returned`. Every other step is
dispatched as in `auto`. After each step""",
"""completes the entry with the human's `actions` and `outcome`, and runs `returned`. Every other step is
dispatched to a fresh background agent. After each step""")

# §Kickoff
rep("""**In `auto` and `autoflow`, kickoff is one tested command** that does §The work item and this
section in one call and leaves the session nothing to judge:

```bash
php "$CHECKS/dispatch_cli.php" kickoff <primary checkout> <number | "<idea>"> [--light] [--mode autoflow|auto] [--decision "<verbatim>"]…
```
""",
"""**In `autoflow`, kickoff is one tested command** that does §The work item and this section in one
call and leaves the session nothing to judge:

```bash
php "$CHECKS/dispatch_cli.php" kickoff <primary checkout> <number | "<idea>"> [--light] [--decision "<verbatim>"]…
```
""")

# §Stations
rep("""a fresh **resolve** agent acts on it (§`auto`)""", """a fresh **resolve** agent acts on it (§Resolving a review)""")

# §Design size
rep("""`/pipeline [interactive|auto|autoflow] [light] <idea | number | spec-path>`.""",
"""`/pipeline [interactive|autoflow] [light] <idea | number | spec-path>`.""")
rep("""| `auto`, `autoflow` | the design brief permits the Bounded path |""",
"""| `autoflow` | the design brief permits the Bounded path |""")
rep("""<auto and autoflow only: each question that would have been asked, and the answer assumed>""",
"""<autoflow only: each question that would have been asked, and the answer assumed>""")
rep("""back to `design` (`pipeline_returned()` in `auto` and `interactive`, the workflow script in
`autoflow`)""",
"""back to `design` (`pipeline_returned()` in `interactive`, the workflow script in `autoflow`)""")
rep("""  - an `auto` or `autoflow` assumption turns out to change what gets built;""",
"""  - an `autoflow` assumption turns out to change what gets built;""")
rep("""In `auto` and `interactive`, `pipeline_route` sends every Bounded""",
"""In `interactive`, `pipeline_route` sends every Bounded""")
rep("""   (`pipeline_loop_back()` in `auto` and `interactive`, `tables.bound` in `autoflow`)""",
"""   (`pipeline_loop_back()` in `interactive`, `tables.bound` in `autoflow`)""")
rep("""`plan-insufficient`; in `auto` and `interactive` `pipeline_returned()` halts on either.""",
"""`plan-insufficient`; in `interactive` `pipeline_returned()` halts on either.""")

# §The proof store
rep("""on the same rule: the dispatcher runs `proof_cli.php open <artifacts.proof>` when that pointer is set (in `autoflow`, the invoking session after `finish`), because""",
"""on the same rule: the session that holds the run (in `autoflow`, the invoking session after `finish`) runs `proof_cli.php open <artifacts.proof>` when that pointer is set, because""")

# §What a leg brief consists of
rep("""(`../checks/brief.php`); nobody writes one by hand — not the dispatcher, not the invoking session, not
a leg, not a coordinator. In `auto` and `interactive` `next` writes it""",
"""(`../checks/brief.php`); nobody writes one by hand — not the invoking session, not a leg, not a
coordinator. In `interactive` `next` writes it""")
rep("""and `## Return` asks for a structured `{status, reason}` instead of a line. `auto` and `interactive`
briefs are identical.""",
"""and `## Return` asks for a structured `{status, reason}` instead of a line.""")

# §auto -> §Resolving a review
rep("""## `auto` — a fresh agent resolves the review

`interactive` gives every resolve step to the human (§Interactive). Everything below is the resolve
step of `auto` and `autoflow`, which differ only where this section says so.""",
"""## Resolving a review — the resolve step acts on it

In `autoflow` a fresh resolve agent acts on each review; in `interactive` the session does, with the
human deciding (§Interactive). Both keep the edit/rework boundary below; the rest is `autoflow`'s.""")
rep("""and the worst case is a discarded branch, while a needless interrupt costs the one thing `auto` exists
to protect.""",
"""and the worst case is a discarded branch, while a needless interrupt costs the one thing `autoflow`
exists to protect.""")
start = s.index("**Why a fresh agent, not the dispatcher.**")
end = s.index("**Outside `autoflow` an independent read is available")
s = s[:start] + s[end:]
rep("""**Outside `autoflow` an independent read is available, and is not a routing rule.**""",
"""**In `interactive` an independent read is available, and is not a routing rule.**""")

# §Failure policy
rep("""Under `auto` and `autoflow` these are the only stops.""", """Under `autoflow` these are the only stops.""")
rep("""  `work-on` hits a blocker, or a review step returns nothing after a single retry (`dispatch_cli.php`
  answers `retry` once, then `halt`). → **halt.** `returned` writes the failure to the manifest""",
"""  `work-on` hits a blocker, or a review step returns nothing after a single retry (in `interactive`
  `returned` answers `retry` once, then `halt`). → **halt.** `finish` (`returned` in `interactive`)
  writes the failure to the manifest""")
rep("""  - The entry is not marked halted: the resolve step records `outcome: looped-back` as it returns,
    and the dispatcher halts through the cursor (`cursor.status: halted`, `cursor.reason`) — in
    `autoflow`, `finish` does.""",
"""  - The entry is not marked halted: the resolve step records `outcome: looped-back` as it returns,
    and `finish` halts the run through the cursor (`cursor.status: halted`, `cursor.reason`) —
    `returned` in `interactive`.""")
rep("""- **A return the dispatcher cannot account for** — a moved cursor, a key only the dispatcher writes,
  a rewritten ledger entry, a status the ledger does not support (`manifest.md` §What a leg writes) →
  **halt**, with `returned`'s reason; in `autoflow`, with the next `brief`'s or `finish`'s, which also
  halt on""",
"""- **A return the checks cannot account for** — a moved cursor, a key only the engine writes, a
  rewritten ledger entry, a status the ledger does not support (`manifest.md` §What a leg writes) →
  **halt**, with the next `brief`'s or `finish`'s reason (`returned`'s in `interactive`), which also
  halt on""")
rep("""In `interactive` mode every gate stops anyway, so the human sees the review and none of the `auto`
resolution runs.""",
"""In `interactive` mode every gate stops anyway, so the human sees the review and none of `autoflow`'s
resolution runs.""")
open(p, 'w').write(s)
````

Then:

```bash
grep -nE '\bauto\b|dispatcher|150k|engine_peak' skills/pipeline/references/engine.md
```

Expected: only lines 18–19 (the refusal sentence in §The loop) and the words `auto-continuation`, `auto-mode classifier` and `auto-closes`.

Run the filtered test. Expected: PASS.

- [ ] **Step 4: Update `BriefTest.php`**

- `brief_manifest()`: `'mode' => 'auto',` becomes `'mode' => 'interactive',`.
- Replace the test *"briefs an auto run exactly as an interactive one: the dispatcher's steps can dispatch"* with:

```php
it('briefs an interactive run without autoflow\'s lines: its steps can dispatch', function () {
    expect(pipeline_brief(brief_manifest('implement'), 'implement', '/tmp/m.json'))
        ->not->toContain('The owner authorised this run')
        ->not->toContain('`cd /tmp/wt`')
        ->toContain('and reply with one line naming it');
});
```

- *"carries the pointers, the settled decisions and the suite line"*: ``->toContain('`implement` leg, `run` step, of a `/pipeline auto` run')`` becomes ``->toContain('`implement` leg, `run` step, of a `/pipeline interactive` run')``.

- [ ] **Step 5: Run the pipeline suite**

Expected: 309 passed.

- [ ] **Step 6: Commit**

```bash
git add skills/pipeline/checks/brief.php skills/pipeline/checks/tests/LockStepTest.php skills/pipeline/checks/tests/BriefTest.php skills/pipeline/references/engine.md
git commit -m "docs(pipeline): engine.md keeps interactive and autoflow; §Resolving a review, pinned by LockStepTest (#87)"
```

---

### Task 4: delete the 150k invariant's code

**Files:**
- Delete: `skills/pipeline/checks/engine_peak.php`, `skills/pipeline/checks/engine_peak_cli.php`, `skills/pipeline/checks/tests/EnginePeakTest.php`
- Modify: `skills/pipeline/checks/tests/Pest.php`

- [ ] **Step 1: Delete and unload**

```bash
git rm -q skills/pipeline/checks/engine_peak.php skills/pipeline/checks/engine_peak_cli.php skills/pipeline/checks/tests/EnginePeakTest.php
perl -pi -e "s/ 'engine_peak\.php',//" skills/pipeline/checks/tests/Pest.php
grep -rn "engine_peak\|EnginePeak" skills hooks README.md CLAUDE.md    # nothing
```

- [ ] **Step 2: Run the pipeline suite**

Expected: 306 passed.

- [ ] **Step 3: Commit**

```bash
git add -A skills/pipeline/checks
git commit -m "refactor(pipeline): drop engine_peak, the auto dispatcher's 150k invariant (#87)"
```

---

### Task 5: the rest of the pipeline docs, and the fixtures that still say `auto`

**Files:**
- Modify: `skills/pipeline/references/gates.md`, `skills/pipeline/references/manifest.md`, `skills/pipeline/SKILL.md` (script below)
- Modify: `skills/pipeline/checks/tests/ReturnedTest.php`, `skills/pipeline/checks/tests/ProofRenderTest.php`

- [ ] **Step 1: Edit the docs**

Write this script to `$TMPDIR/p87_pipeline_docs.py` and run `python3 "$TMPDIR/p87_pipeline_docs.py" "$(pwd)"` from the worktree root:

````python
import sys
root = sys.argv[1]
def edit(path, pairs, cuts=()):
    p = root + '/' + path
    s = open(p).read()
    for start, end in cuts:
        i = s.index(start); j = s.index(end, i)
        s = s[:i] + s[j:]
    for old, new in pairs:
        assert s.count(old) == 1, (path, s.count(old), old)
        s = s.replace(old, new)
    open(p, 'w').write(s)

edit('skills/pipeline/references/gates.md', [
("""## Modes — one choice, three modes

`mode` is the only knob, and **anything that is neither `auto` nor `autoflow` behaves as
`interactive`** — the strictest of them. That fallback used to be asserted mechanically by `pipeline_resolve_policy()`;
the function is gone (its two gates were always identical to each other and a pure function of
mode), so the rule lives here and has to stay explicit: `manifest_validate` checks key *presence*,
not value, so a manifest with a mangled `mode` must still fail safe.""",
"""## Modes — one choice, two modes

`mode` is the only knob, and **anything that is not `autoflow` behaves as `interactive`** — the
stricter of the two. The one exception is `auto`, the engine #87 removed: every command refuses a
manifest that still says `auto`, naming `autoflow` (`engine.md` §The loop). That fallback used to be
asserted mechanically by `pipeline_resolve_policy()`; the function is gone (its two gates were always
identical to each other and a pure function of mode), so the rule lives here and has to stay
explicit: `manifest_validate` checks key *presence*, not value, so a manifest with a mangled `mode`
must still fail safe."""),
("""| **`auto`** | run the autonomous legs unattended. The reviews still run; a fresh resolve step reads each and acts, looping back where the work is wrong and never interrupting on a finding (`engine.md` §`auto`). Hard failures and bound exhaustion still stop. |
| **`autoflow`** | as `auto`, but the loop is the workflow `pipeline-autoflow`, not an agent (`engine.md` §`autoflow`); for the side-by-side comparison, until the keep/revert decision. |""",
"""| **`autoflow`** | run the autonomous legs unattended; the loop is the workflow `pipeline-autoflow` (`engine.md` §`autoflow`). The reviews still run; a fresh resolve step reads each and acts, looping back where the work is wrong and never interrupting on a finding (`engine.md` §Resolving a review). Hard failures and bound exhaustion still stop. |"""),
("""The **report-only override** that once existed (`auto` with `plan-approval` flipped to `report` in""",
"""The **report-only override** that once existed (an unattended run with `plan-approval` flipped to `report` in"""),
("""| the resolve step acts on it like any other part of the review (`engine.md` §`auto`) |""",
"""| the resolve step acts on it like any other part of the review (`engine.md` §Resolving a review) |"""),
("""and so does any loop-back once the count is `unknown` (`manifest.md` §Reconstruction). In `auto` and
`interactive` `pipeline_returned()` evaluates both;""",
"""and so does any loop-back once the count is `unknown` (`manifest.md` §Reconstruction). In
`interactive` `pipeline_returned()` evaluates both;"""),
("""`auto` and `interactive` call it once per step, one command (`engine.md` §The loop). It computes the
triggers from a diff file the dispatcher (or the session) writes but never reads:""",
"""`interactive` calls it once per step, one command (`engine.md` §The loop). It computes the triggers
from a diff file the session writes but never reads:"""),
])

edit('skills/pipeline/references/manifest.md', [
("""| `mode` | **required** | `interactive`, `auto` or `autoflow` |""",
"""| `mode` | **required** | `interactive` or `autoflow`; every command refuses `auto`, the engine #87 removed, naming `autoflow` |"""),
("""`retried` only after a review step's single retry in `auto` or `interactive` |""",
"""`retried` only after a review step's single retry in `interactive` |"""),
("""Under `auto` the resolve step overrules reviewers routinely (`engine.md` §`auto`).""",
"""Under `autoflow` the resolve step overrules reviewers routinely (`engine.md` §Resolving a review)."""),
("""brief. In `auto` and `interactive`, after every return `returned` compares""",
"""brief. In `interactive`, after every return `returned` compares"""),
("""In `auto` and `interactive` every leg opens with one.""", """In `interactive` every leg opens with one."""),
("""a human approval, or the resolve step's own continue under `auto` —""",
"""a human approval, or the resolve step's own continue under `autoflow` —"""),
])

edit('skills/pipeline/SKILL.md', [
("""Core principle: **a loop that only loops; every step is a fresh agent.** In `auto` the loop is a
dispatcher agent that asks `dispatch_cli.php` for the next step, dispatches it with the brief
`pipeline_brief()` generated and lets the same command validate what came back, never reading
artifacts, reviews, diffs or test output itself. In `autoflow` the loop is a program, the saved
workflow `pipeline-autoflow` (`workflow/pipeline-autoflow.js`), whose steps each run
`dispatch_cli.php brief`, do their leg, write the manifest and return a status. In both, review fixes
and finishing the PR belong to fresh resolve agents. `interactive` walks the same legs through
`next` / `returned`, with the human resolving each review.""",
"""Core principle: **a loop that only loops; every step is a fresh agent.** In `autoflow` the loop is a
program, the saved workflow `pipeline-autoflow` (`workflow/pipeline-autoflow.js`), whose steps each run
`dispatch_cli.php brief`, do their leg, write the manifest and return a status; review fixes and
finishing the PR belong to fresh resolve agents. `interactive` walks the same legs through
`next` / `returned`, with the human resolving each review."""),
("""/pipeline [interactive|auto|autoflow] [light] <idea | number | spec-path>   # start a run (mode defaults to interactive)
/pipeline                                                                   # resume the current branch's run""",
"""/pipeline [interactive|autoflow] [light] <idea | number | spec-path>   # start a run (mode defaults to interactive)
/pipeline                                                              # resume the current branch's run"""),
("""- **Mode defaults to `interactive`.** `auto` and `autoflow` are explicit opt-ins for unattended
  runs; a fresh `/pipeline <idea>` never runs unattended by surprise.""",
"""- **Mode defaults to `interactive`.** `autoflow` is the explicit opt-in for an unattended run; a
  fresh `/pipeline <idea>` never runs unattended by surprise. `auto`, the dispatcher engine, was
  removed (#87): `/pipeline auto` is refused, naming `autoflow`."""),
("""  asks when brainstorming finds the change small, and `auto` and `autoflow` always write the full
  design.""",
"""  asks when brainstorming finds the change small, and `autoflow` always writes the full design."""),
("""The two unattended modes run side by side until the keep/revert decision: after 6 `autoflow` runs,
measured against `docs/superpowers/specs/2026-09-23-pipeline-auto-workflow-design.md` §Measurement,
the decision is "keep `autoflow` and delete `auto`" or "delete `autoflow`"
(`docs/superpowers/plans/2026-09-23-pipeline-auto-workflow.md` Amendment A). PR #50 carries the
numbers.""",
"""`autoflow` is the unattended mode. It ran beside `auto`, the dispatcher engine, for 6 runs, measured
against `docs/superpowers/specs/2026-09-23-pipeline-auto-workflow-design.md` §Measurement; by those
criteria the owner kept `autoflow` and `auto` was removed (#87). PR #50 carries the numbers."""),
], cuts=[("- **The 150k invariant**", "- **Cost per run**")])
````

- [ ] **Step 2: The fixtures**

- `ReturnedTest.php`, `returned_before()`: `'mode' => 'auto',` becomes `'mode' => 'interactive',`. So that the *"a dispatcher key"* row still changes `mode`, its `['mode' => 'interactive']` becomes `['mode' => 'autoflow']`. In *"reports a manifest problem before anything the step reported"*, `['mode' => 'auto', 'branch' => 'other']` becomes `['mode' => 'interactive', 'branch' => 'other']`, so only `branch` changes and the expected message stands.
- `ProofRenderTest.php`: the payload's `'mode' => 'auto',` becomes `'mode' => 'autoflow',`.

```bash
grep -rn "'auto'" skills/pipeline/checks/tests    # only DispatchTest's and DispatchCliTest's refusal tests
```

- [ ] **Step 3: Run the pipeline suite**

Expected: 306 passed (`LockStepTest` reads `manifest.md` §What a leg writes and `gates.md` §Loop-backs, which the script leaves intact).

- [ ] **Step 4: Commit**

```bash
git add skills/pipeline
git commit -m "docs(pipeline): SKILL.md, gates.md and manifest.md keep interactive and autoflow (#87)"
```

---

### Task 6: `orchestrate` and `browser-verification`

**Files:**
- Modify: `skills/orchestrate/SKILL.md`, `skills/orchestrate/references/commands.md`, `skills/browser-verification/SKILL.md` (script below)

- [ ] **Step 1: Edit the docs**

Write this script to `$TMPDIR/p87_orchestrate_docs.py` and run `python3 "$TMPDIR/p87_orchestrate_docs.py" "$(pwd)"` from the worktree root:

````python
import sys
root = sys.argv[1]
def edit(path, pairs, cuts=()):
    p = root + '/' + path
    s = open(p).read()
    for start, end in cuts:
        i = s.index(start); j = s.index(end, i)
        s = s[:i] + s[j:]
    for old, new in pairs:
        assert s.count(old) == 1, (path, s.count(old), old)
        s = s.replace(old, new)
    open(p, 'w').write(s)

edit('skills/orchestrate/SKILL.md', [
("""description: Use when several GitHub issues should go to merged PRs through /pipeline auto or autoflow runs from one long-running session ("/orchestrate 429 411", "/orchestrate autoflow 429", "run #X, then #Y once it merges")""",
"""description: Use when several GitHub issues should go to merged PRs through /pipeline autoflow runs from one long-running session ("/orchestrate 429 411", "run #X, then #Y once it merges")"""),
("""`/orchestrate [auto|autoflow] <issue> …` picks the engine for the batch, `auto` when omitted. It needs""",
"""`/orchestrate <issue> …` runs each issue as a `/pipeline autoflow` run. A leading `auto` or `autoflow` from an older command line changes nothing; when it was `auto`, say once in the plan report that `auto` was removed (#87) and the batch runs as `autoflow`. It needs"""),
("""3. **Dispatch.** `auto`: Agent tool, `run_in_background: true`, no `isolation` or model override, the brief (commands §Brief). `autoflow`: from the primary checkout,""",
"""3. **Dispatch.** From the primary checkout,"""),
("""5. **A run returns.** `auto`: run `php ~/.claude/skills/pipeline/checks/engine_peak_cli.php <its agent id>` and put the line in whichever report follows (pipeline `engine.md` §The dispatcher — what it does, and never does). `autoflow`: `finish` it,""",
"""5. **A run returns.** `finish` it,"""),
("""After a compaction: `auto`, message your agent ids; `autoflow`, your dispatch record (task id → issue) still names each run and its completion notice still arrives.""",
"""After a compaction your dispatch record (task id → issue) still names each run, and its completion notice still arrives."""),
("""- **One agent per run, ever** (`auto`); **one workflow per run at a time** (`autoflow`). Never dispatch for an issue with a dispatched or adopted run: no fresh run, resume agent, backup or restart. Wait for the completion notice.""",
"""- **One workflow per run at a time.** Never dispatch for an issue with a dispatched or adopted run: no fresh run, backup or restart. Wait for the completion notice."""),
("""- **Suspected stall** (no notice, no commit or PR change for 90 minutes). `auto`: one `SendMessage`. "Queued for delivery" means alive; "resumed it in the background" means that send was the recovery. Replace only after a completion notice **and** demonstrably unfinished work, with the original stood down. `autoflow`: `TaskStop` its workflow,""",
"""- **Suspected stall** (no notice, no commit or PR change for 90 minutes): `TaskStop` its workflow,"""),
("""- **Commits wanted on a ready PR:** `gh pr ready --undo <P>` **first**. Then, by the engine that built it: `auto`, `SendMessage` that run, even when finished: back in draft, mark ready when done, delete no remote branch; `autoflow`, the request into the manifest's `decisions`, `launch --from review-pr` and a new workflow (commands §Launch). Never commit yourself or start a new agent for it.""",
"""- **Commits wanted on a ready PR:** `gh pr ready --undo <P>` **first**. Then the request into the manifest's `decisions`, `launch --from review-pr` and a new workflow (commands §Launch). Never commit yourself or start a new agent for it."""),
("""| A fresh run, or "stop it, resume in a new agent", for a quiet run; a second workflow beside one |""",
"""| A fresh run, or "stop it, resume in a new workflow", for a quiet run; a second workflow beside one |"""),
("""| "The run finished, so a new agent isn't a second one" (`auto`) | `SendMessage` resumes it; a new agent is the double dispatch. |
""", ""),
("""- "Obviously dead"; "the owner said restart"; "still undelivered"; "just start another workflow".""",
"""- "Obviously dead"; "the owner said restart"; "just start another workflow"."""),
("""- "Just a resume"; "stop it, then resume" (`autoflow`: a stall only, through `TaskStop` and `finish`); "the first run is done".""",
"""- "Just a resume"; "stop it, then resume" (a stall only, through `TaskStop` and `finish`); "the first run is done"."""),
])

edit('skills/orchestrate/references/commands.md', [
("""- **A run this session dispatched:** the dispatch record (agent id for `auto`, the workflow's task
  id for `autoflow` → issue) is the owner. Search nothing.""",
"""- **A run this session dispatched:** the dispatch record (the workflow's task id → issue) is the
  owner. Search nothing."""),
("""`autoflow`, per issue N, from the primary checkout:""", """Per issue N, from the primary checkout:"""),
("""The engine follows the manifest's `mode`, not the batch's argument: `launch` refuses a manifest that is
not `autoflow`, `next` one that is. A dead session's `autoflow` run: `finish` it with a halt, then a new
`launch` and workflow.""",
"""`launch` refuses a manifest that is not `autoflow`; one that still says `auto` is refused naming
`autoflow` (pipeline `engine.md` §The loop). A dead session's `autoflow` run: `finish` it with a halt,
then a new `launch` and workflow."""),
("""`autoflow`, on a run's completion notice (pipeline `engine.md` §`autoflow` — a program that calls
agents):""",
"""On a run's completion notice (pipeline `engine.md` §`autoflow` — a program that calls agents):"""),
], cuts=[("## Brief\n", "## Launch\n")])

edit('skills/browser-verification/SKILL.md', [
("""they scroll away and an `auto` run's subagent output is never read.""",
"""they scroll away and an unattended run's step output is never read."""),
])
````

- [ ] **Step 2: Check what is left**

```bash
grep -nE '\bauto\b|engine_peak|SendMessage|agent id' skills/orchestrate/SKILL.md skills/orchestrate/references/commands.md
```

Expected: `SKILL.md`'s *Where it runs* sentence about a leading `auto`, `commands.md` §Launch's refusal sentence, and `SendMessage` only in `commands.md` §Watch (adopted sessions). No `## Brief` section: `grep -c '^## Brief' skills/orchestrate/references/commands.md` prints 0.

- [ ] **Step 3: Run the orchestrate test**

Run: `bash skills/orchestrate/tests/owners_test.sh`
Expected: `PASS owners.py`.

- [ ] **Step 4: Commit**

```bash
git add skills/orchestrate skills/browser-verification
git commit -m "docs(orchestrate): one engine, autoflow; drop the auto dispatch, stall and recovery (#87)"
```

---

### Task 7: nothing starts an `auto` run

- [ ] **Step 1: The whole-repo sweep**

```bash
grep -rnE "pipeline auto\b|--mode auto|\[auto\||auto\|autoflow|engine_peak|§\`auto\`|The dispatcher —|150k" skills hooks README.md CLAUDE.md
```

Expected: three lines, each saying `auto` is refused: `skills/pipeline/SKILL.md`'s *"`/pipeline auto` is refused, naming `autoflow`"*, `engine.md`'s *"`kickoff --mode auto` included"* and `dispatch_cli_kickoff_args()`'s docblock (*"`--mode auto` parses"*). Anything else is a missed `auto` path: fix it in the file it names, in the style of the edits above.

- [ ] **Step 2: All three suites**

```bash
./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests     # 306 passed
./vendor/bin/pest -c skills/critique/checks/phpunit.xml --test-directory=skills/critique/checks/tests     # 14 passed
bash skills/orchestrate/tests/owners_test.sh                                                              # PASS owners.py
```

- [ ] **Step 3: The halts, by hand**

```bash
CHECKS="$(pwd)/skills/pipeline/checks"
M="$TMPDIR/p87-auto.json"
printf '{"branch":"x","worktree":"%s","mode":"auto","cursor":{"leg":"design","status":"pending"}}\n' "$(pwd)" > "$M"
php "$CHECKS/dispatch_cli.php" next "$M"                       # {"action":"halt","reason":"mode auto was removed; …"}
php "$CHECKS/dispatch_cli.php" kickoff "$(pwd)" 87 --mode auto # the same halt; no worktree, no gh call
git worktree list | grep -c issue-87                           # 1: only this run's own worktree
```

- [ ] **Step 4: The PR body**

The finish step's PR body states, for the owner: #51's *"`auto` keeps its agent-type route"* bullet no longer applies (no `.claude/agents/` exists to remove), and #52's scope is unchanged, since its verbs would feed `interactive`'s `returned` and `autoflow`'s `brief --after` / `finish` alike (spec §Follow-ups). No issue is edited.
