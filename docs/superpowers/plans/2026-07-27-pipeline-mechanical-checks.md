# Pipeline Mechanical Checks Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Teach the `pipeline` skill's `implement` leg to run a repo's mechanical checks (PHPStan, Pint) after each step, opt-in per repo, failing closed when the declaration is malformed.

**Architecture:** Two new pure PHP functions in `skills/pipeline/checks/checks.php` — a tri-state parser for the `## Checks` block in a repo's `.claude/work-on.config.md`, and a `<N>` slot-suffix expander — plus documentation of the leg behaviour in `references/engine.md`. The functions are pure (string in, array/string out) so they are testable with no I/O, matching the existing `pipeline.php` / `triggers.php` pattern. Everything else in the skill is untouched.

**Tech Stack:** Plain PHP 8.2+ (no framework), Pest for tests, Markdown for the skill's reference docs.

## Global Constraints

Values copied verbatim from `docs/superpowers/specs/2026-07-27-pipeline-mechanical-checks-design.md`. Every task's requirements implicitly include this section.

- **Tri-state probe.** `pipeline_repo_checks()` returns `state` of exactly `absent`, `valid`, or `invalid`. `absent` and `invalid` must never collapse into one another.
- **`<N>` is the slot *suffix*, not the slot number** — empty string on the primary stack, `-2` / `-3` … in a slot. This matches `scripts/slot-env.sh:49` (`SUFFIX="-${SLOT}"`) and the `<N>` convention already used by `work-on.config.template.md` for `slot-path` and `dev-url`.
- **Two failure kinds, named differently.** *Check failure* = the checks reported a finding the change introduced → the step is not done, fix and re-run, bounded to 2 attempts, escalate on the third. *Machinery failure* = probe returns `invalid`, container missing, command errors, tool not installed → halt per `engine.md`'s existing hard-failure policy. Only the second is "hard failure".
- **Whole-scope every step.** No file lists, no diff-scoping, no path mapping into the container. Measured: 4.7s scoped vs 11.1s whole-app; the Larastan bootstrap is a fixed ~4.5s floor.
- **Blocking is scoped to files the change touched.** A finding in a touched file fails the step; a finding elsewhere is an annotation and does not block.
- **The check result is recomputed at leg start, never stored.** `manifest.md` forbids storing recomputable fields.
- **`review-pr` evidence must be scope-qualified** — "0 new findings over `app/`", never an unqualified "0 new findings".
- **Suppressions:** `@phpstan-ignore <identifier>` form required (never bare), justification comment required, **bounded at 2 per run** — more escalates.
- **Baseline may shrink freely but never grow.** Any diff adding an entry or raising a count is a blocked change.
- **Adopting repos set `reportUnmatchedIgnoredErrors: false`** in `phpstan.neon`, level 5, and a writable `tmpDir`.

**Must NOT be touched** (verify at the end that they are byte-identical to `origin/main`):
- `skills/pipeline/references/manifest.md`
- `pipeline_legs()`, `pipeline_gate_legs()`, `pipeline_can_navigate()`, `pipeline_resolve_policy()` in `skills/pipeline/checks/pipeline.php`
- `pipeline_triggers()` in `skills/pipeline/checks/triggers.php`

**Commit rules:** no `Co-Authored-By` lines, no AI attribution. Work stays on branch `feature/pipeline-mechanical-checks`; never commit to `main`.

**Test command** (run from the repo root, `~/GitProjects/LaravelClaudeMd/LaravelClaudeMd`):

```bash
./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests
```

Baseline before any work: **11 passed (54 assertions)**.

---

## File Structure

| File | Status | Responsibility |
|---|---|---|
| `skills/pipeline/checks/checks.php` | **create** | The two pure functions: `pipeline_repo_checks()`, `pipeline_expand_slot()`. Nothing else. |
| `skills/pipeline/checks/tests/ChecksTest.php` | **create** | Pest tests for both, with the absent-vs-invalid boundary as the centrepiece. |
| `skills/pipeline/checks/tests/Pest.php` | modify (1 line) | Add `checks.php` to the conditional-require list so the "watch it fail" mode is an undefined-function error, not a missing-file fatal. |
| `skills/pipeline/references/engine.md` | modify | The `implement` station row; a new *Mechanical checks* section. |
| `skills/pipeline/SKILL.md` | modify (1 line) | Point at the new behaviour from the overview. |

Three tasks. Task 1 and Task 2 each deliver one function with its tests; Task 3 delivers the documentation that tells the engine how to use them.

---

### Task 1: The tri-state `## Checks` parser

**Files:**
- Create: `skills/pipeline/checks/checks.php`
- Create: `skills/pipeline/checks/tests/ChecksTest.php`
- Modify: `skills/pipeline/checks/tests/Pest.php:6` (the `foreach` file list)

**Interfaces:**
- Consumes: nothing.
- Produces: `pipeline_repo_checks(string $configMarkdown): array` returning
  `['state' => 'absent'|'valid'|'invalid', 'commands' => array<string,string>, 'error' => ?string]`.
  On `valid`, `commands` is keyed by check name (`static-analysis`, `format`) with the raw
  declared command as the value. On `absent` and `invalid`, `commands` is `[]`. `error` is a
  human-readable reason on `invalid`, `null` otherwise.

**Why this shape:** an earlier design returned a bare array, which made a typo'd heading (`## Check`), a mis-cased key, or `static_analysis:` with an underscore indistinguishable from genuine non-adoption — a permanent silent skip with no feedback channel. That is the failure mode this whole task exists to prevent.

- [ ] **Step 1: Add `checks.php` to the Pest bootstrap**

Modify `skills/pipeline/checks/tests/Pest.php`, changing the file list on line 6 from:

```php
foreach (['triggers.php', 'pipeline.php', 'manifest.php'] as $f) {
```

to:

```php
foreach (['triggers.php', 'pipeline.php', 'manifest.php', 'checks.php'] as $f) {
```

The surrounding `is_file()` guard means the missing file is not a fatal error yet — the test will fail with "undefined function", which is the intended failure mode.

- [ ] **Step 2: Write the failing tests**

Create `skills/pipeline/checks/tests/ChecksTest.php`:

```php
<?php

$configWithout = <<<'MD'
# work-on — per-repo config

## Repo
- repo: IT4WEBBV/Deploy

## Worktree
- restart: ./scripts/restart.sh
MD;

$configWith = <<<'MD'
# work-on — per-repo config

## Repo
- repo: IT4WEBBV/Deploy

## Checks
- static-analysis: docker exec deploy<N>_web ./vendor/bin/phpstan analyse --no-progress
- format: docker exec deploy<N>_web ./vendor/bin/pint

## Branch convention
- issue: feature/issue-<number>-<slug>
MD;

it('reports absent when the repo has not adopted checks', function () use ($configWithout) {
    $result = pipeline_repo_checks($configWithout);

    expect($result['state'])->toBe('absent');
    expect($result['commands'])->toBe([]);
    expect($result['error'])->toBeNull();
});

it('parses both declared checks and stops at the next section', function () use ($configWith) {
    $result = pipeline_repo_checks($configWith);

    expect($result['state'])->toBe('valid');
    expect($result['commands'])->toBe([
        'static-analysis' => 'docker exec deploy<N>_web ./vendor/bin/phpstan analyse --no-progress',
        'format' => 'docker exec deploy<N>_web ./vendor/bin/pint',
    ]);
    expect($result['error'])->toBeNull();
});

it('accepts a section declaring only one of the two checks', function () {
    $md = "## Checks\n- format: docker exec app_web ./vendor/bin/pint\n";

    $result = pipeline_repo_checks($md);

    expect($result['state'])->toBe('valid');
    expect($result['commands'])->toBe(['format' => 'docker exec app_web ./vendor/bin/pint']);
});

it('treats a misspelled heading as invalid, never as absent', function () {
    // The whole point of the tri-state: this must NOT read as "not adopted".
    $md = "## Check\n- static-analysis: docker exec app_web ./vendor/bin/phpstan analyse\n";

    $result = pipeline_repo_checks($md);

    expect($result['state'])->toBe('invalid');
    expect($result['error'])->toContain('outside');
});

it('treats an unknown or mis-cased key inside the section as invalid', function () {
    $underscored = "## Checks\n- static_analysis: docker exec app_web ./vendor/bin/phpstan analyse\n";
    $miscased = "## Checks\n- Static-Analysis: docker exec app_web ./vendor/bin/phpstan analyse\n";

    expect(pipeline_repo_checks($underscored)['state'])->toBe('invalid');
    expect(pipeline_repo_checks($underscored)['error'])->toContain('static_analysis');
    expect(pipeline_repo_checks($miscased)['state'])->toBe('invalid');
});

it('treats a declared key with an empty value as invalid', function () {
    $md = "## Checks\n- format:\n";

    $result = pipeline_repo_checks($md);

    expect($result['state'])->toBe('invalid');
    expect($result['error'])->toContain('empty');
});

it('treats an empty Checks section as invalid', function () {
    $md = "## Checks\n\n## Branch convention\n- issue: feature/x\n";

    $result = pipeline_repo_checks($md);

    expect($result['state'])->toBe('invalid');
    expect($result['error'])->toContain('no check');
});

it('ignores comment lines and strips trailing comments from commands', function () {
    $md = "## Checks\n# format deliberately omitted for now\n- static-analysis: docker exec app_web ./vendor/bin/phpstan analyse   # level 5\n";

    $result = pipeline_repo_checks($md);

    expect($result['state'])->toBe('valid');
    expect($result['commands'])->toBe(['static-analysis' => 'docker exec app_web ./vendor/bin/phpstan analyse']);
});
```

- [ ] **Step 3: Run the tests to verify they fail**

```bash
./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests
```

Expected: 8 failures with `Call to undefined function pipeline_repo_checks()`. The pre-existing 11 tests must still pass.

- [ ] **Step 4: Write the implementation**

Create `skills/pipeline/checks/checks.php`:

```php
<?php

/**
 * Parse the `## Checks` block out of a repo's `.claude/work-on.config.md`.
 *
 * Tri-state by design (`../references/engine.md` §Mechanical checks): `absent` and
 * `invalid` must never collapse into one another. A typo'd heading or a mis-cased key
 * that parsed as "not adopted" would disable the checks permanently while the run
 * believed it was covered — the one outcome the design calls worse than no tooling.
 *
 * @return array{state: 'absent'|'valid'|'invalid', commands: array<string, string>, error: ?string}
 */
function pipeline_repo_checks(string $configMarkdown): array
{
    $known = ['static-analysis', 'format'];
    $keyLineRe = '/^\s*-\s*([A-Za-z][A-Za-z0-9_-]*)\s*:\s*(.*)$/';

    $lines = preg_split('/\R/', $configMarkdown);

    $start = null;
    foreach ($lines as $i => $line) {
        if (preg_match('/^##\s+Checks\s*$/', $line)) {
            $start = $i;
            break;
        }
    }

    // No section. Check-shaped keys elsewhere mean the heading is malformed —
    // that is `invalid`, not `absent`.
    if ($start === null) {
        foreach ($lines as $line) {
            if (preg_match($keyLineRe, $line, $m) && in_array(strtolower($m[1]), $known, true)) {
                return [
                    'state' => 'invalid',
                    'commands' => [],
                    'error' => "check key '{$m[1]}' found outside a '## Checks' section",
                ];
            }
        }

        return ['state' => 'absent', 'commands' => [], 'error' => null];
    }

    $commands = [];
    for ($i = $start + 1, $n = count($lines); $i < $n; $i++) {
        $line = $lines[$i];

        if (preg_match('/^##\s/', $line)) {
            break;
        }
        if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
            continue;
        }
        if (! preg_match($keyLineRe, $line, $m)) {
            continue;
        }

        $key = $m[1];
        $value = trim(preg_replace('/\s+#.*$/', '', $m[2]));

        if (! in_array($key, $known, true)) {
            return ['state' => 'invalid', 'commands' => [], 'error' => "unknown check key '{$key}'"];
        }
        if ($value === '') {
            return ['state' => 'invalid', 'commands' => [], 'error' => "check key '{$key}' has an empty value"];
        }

        $commands[$key] = $value;
    }

    if ($commands === []) {
        return ['state' => 'invalid', 'commands' => [], 'error' => "'## Checks' section declares no check"];
    }

    return ['state' => 'valid', 'commands' => $commands, 'error' => null];
}
```

Note the heading regex is deliberately **case-sensitive** (`/^##\s+Checks\s*$/`): `## checks` falls through to the no-section branch, finds its check keys, and returns `invalid` — which is the desired answer, not an accident.

- [ ] **Step 5: Run the tests to verify they pass**

```bash
./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests
```

Expected: **19 passed** (the 11 pre-existing plus the 8 new).

- [ ] **Step 6: Commit**

```bash
git add skills/pipeline/checks/checks.php skills/pipeline/checks/tests/ChecksTest.php skills/pipeline/checks/tests/Pest.php
git commit -m "feat(pipeline): tri-state parser for a repo's ## Checks declaration

absent and invalid are separate states on purpose: a typo'd heading or a
mis-cased key that read as 'not adopted' would disable the checks silently
while the run believed it was covered."
```

---

### Task 2: The `<N>` slot-suffix expander

**Files:**
- Modify: `skills/pipeline/checks/checks.php` (append the second function)
- Modify: `skills/pipeline/checks/tests/ChecksTest.php` (append tests)

**Interfaces:**
- Consumes: nothing from Task 1 at runtime; lives in the same file.
- Produces: `pipeline_expand_slot(string $command, string $slotSuffix): string` — returns the command with every `<N>` replaced by `$slotSuffix`.

**Why this is separate from the parser:** parsing happens once at leg start; expansion happens at invocation time, when the run's slot is known. Keeping them apart means the stored declaration stays verbatim and only the moment of use is slot-aware.

- [ ] **Step 1: Write the failing tests**

Append to `skills/pipeline/checks/tests/ChecksTest.php`:

```php
it('expands the slot token to nothing on the primary stack', function () {
    $command = 'docker exec deploy<N>_web ./vendor/bin/phpstan analyse';

    expect(pipeline_expand_slot($command, ''))
        ->toBe('docker exec deploy_web ./vendor/bin/phpstan analyse');
});

it('expands the slot token to the suffix in a slot', function () {
    $command = 'docker exec deploy<N>_web ./vendor/bin/phpstan analyse';

    expect(pipeline_expand_slot($command, '-2'))
        ->toBe('docker exec deploy-2_web ./vendor/bin/phpstan analyse');
});

it('leaves a command without the token untouched', function () {
    $command = 'docker exec deploy_web ./vendor/bin/pint';

    expect(pipeline_expand_slot($command, '-3'))->toBe($command);
});

it('expands every occurrence of the token', function () {
    $command = 'docker exec app<N>_web sh -c "cat /srv/app<N>.conf"';

    expect(pipeline_expand_slot($command, '-2'))
        ->toBe('docker exec app-2_web sh -c "cat /srv/app-2.conf"');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests
```

Expected: 4 failures with `Call to undefined function pipeline_expand_slot()`. The other 19 still pass.

- [ ] **Step 3: Write the implementation**

Append to `skills/pipeline/checks/checks.php`:

```php
/**
 * Expand the `<N>` slot token in a declared check command.
 *
 * `<N>` is the run's slot *suffix*, not its number: an empty string on the primary
 * stack, `-2` / `-3` … in a slot. That is how the slot machinery names containers
 * (`scripts/slot-env.sh`: `SUFFIX="-${SLOT}"`), and it mirrors the `<N>` convention
 * `work-on.config.template.md` already uses for `slot-path` and `dev-url`.
 *
 * Without this, a hardcoded container name execs the primary stack, analyses the
 * primary checkout instead of the run's worktree, finds nothing, and passes green.
 */
function pipeline_expand_slot(string $command, string $slotSuffix): string
{
    return str_replace('<N>', $slotSuffix, $command);
}
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests
```

Expected: **23 passed**.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/checks.php skills/pipeline/checks/tests/ChecksTest.php
git commit -m "feat(pipeline): expand the <N> slot suffix in declared check commands

A hardcoded container name execs the primary stack and analyses the primary
checkout rather than the run's worktree, reporting no findings and passing
green on code it never read."
```

---

### Task 3: Document the leg behaviour

**Files:**
- Modify: `skills/pipeline/references/engine.md:77` (the `implement` station row)
- Modify: `skills/pipeline/references/engine.md` (insert a new section before `## The verdict block`, currently line 81)
- Modify: `skills/pipeline/SKILL.md` (one bullet in the references list, after line 22)

**Interfaces:**
- Consumes: `pipeline_repo_checks()` and `pipeline_expand_slot()` from Tasks 1–2 — the doc names both, so the signatures must match exactly.
- Produces: nothing consumed by later tasks. This is the final task.

There is no unit test for prose. Verification is the checklist in Step 4 plus the suite staying green.

- [ ] **Step 1: Update the `implement` station row**

In `skills/pipeline/references/engine.md`, replace line 77 exactly:

```markdown
| **implement** | `work-on`'s logic **in the current worktree** (no second slot) — read the item, validate against the code, execute the plan **test-first, running the suite after each step**, set closing-issue links, mark ready | — | autonomous-capable; needs the stack up | updates `last_sha`, marks implemented |
```

with:

```markdown
| **implement** | `work-on`'s logic **in the current worktree** (no second slot) — read the item, validate against the code, execute the plan **test-first, running the suite and the repo's mechanical checks after each step** (§Mechanical checks), set closing-issue links, mark ready | — | autonomous-capable; needs the stack up | updates `last_sha`, marks implemented |
```

- [ ] **Step 2: Insert the new section**

In `skills/pipeline/references/engine.md`, insert the following immediately **before** the line `## The verdict block — a review leg's return contract`:

```markdown
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

**What runs, and when.** After each step: the test suite, then `static-analysis` over the whole
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
| **Check failure** | the checks report a finding **in a file this change touched** | the **step is not done**. Fix and re-run, bounded to 2 attempts; escalate on the third. Exactly how a failing test behaves — *not* a halt |
| **Machinery failure** | probe returns `invalid`, the container is missing, the command errors, the tool is not installed | **halt**, per §Failure policy |

A reported finding in a file the change did **not** touch is an annotation on the PR, not a blocker:
other write paths (plain `work-on`, direct commits, a colleague's merge) reach the same repo without
running checks, and hard-failing a run for someone else's finding leaves it no legal move.

**Suppression is bounded.** Where a finding genuinely cannot be resolved, `implement` may add
`@phpstan-ignore <identifier>` — never the bare form, which suppresses every error on the next line
including future real ones — with a justification comment. **More than two suppressions in one run
escalates**, because the agent whose step is blocked is otherwise judging its own excuse.

**The result is recomputed at leg start, never stored.** Both the check result (a re-runnable
command) and the suppression count (grep-able from the diff) are recomputable, and `manifest.md` is
explicit that storing a recomputable field is a latent drift bug. Nothing about checks enters the
manifest.

**Into `review-pr`.** The brief states the result **qualified by the analysed scope** — "0 new
findings over `app/`", never an unqualified "0 new findings", since the declared scope does not cover
`database/`, `routes/`, `config/` or `tests/`. Any suppressions added during the run are listed and
marked **unadjudicated**, so one cannot enter reading as already resolved.

```

- [ ] **Step 3: Add the SKILL.md pointer**

In `skills/pipeline/SKILL.md`, insert this bullet immediately after line 22 (the `references/manifest.md` bullet):

```markdown
- **Mechanical checks** — `implement` also runs a repo's PHPStan/Pint checks after each step when
  the repo declares them in a committed `## Checks` block (`references/engine.md` §Mechanical
  checks). Opt-in: repos that have not declared them are unaffected.
```

- [ ] **Step 4: Verify the documentation and the untouched surface**

Run each of these and confirm the stated expectation:

```bash
# the new section exists and the implement row references it
grep -c 'Mechanical checks' skills/pipeline/references/engine.md    # expect: 2 (the heading + the §ref in the implement row)
grep -c 'Mechanical checks' skills/pipeline/SKILL.md                # expect: 2 (the bullet label + the §ref)

# manifest.md is byte-identical to main
git diff --stat origin/main -- skills/pipeline/references/manifest.md   # expect: no output

# the five pure functions are untouched
git diff origin/main -- skills/pipeline/checks/pipeline.php skills/pipeline/checks/triggers.php
# expect: no output

# suite still green
./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests
# expect: 23 passed
```

If `git diff` against `origin/main` shows output for `manifest.php`, `pipeline.php` or `triggers.php`, revert those files — the design explicitly does not touch them.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/references/engine.md skills/pipeline/SKILL.md
git commit -m "docs(pipeline): document mechanical checks inside the implement leg

Tri-state probe, <N> slot expansion, whole-scope runs, and the distinction
between a check failure (fix and re-run) and a machinery failure (halt).
manifest.md is deliberately untouched: the check result is recomputable, and
storing a recomputable field is the drift bug that file warns against."
```

---

## Out of scope for this plan

These are **repo adoption** steps, tracked separately, not skill code:

- Installing `larastan/larastan` and `laravel/pint`, writing `phpstan.neon` / `pint.json` / the baseline, and the blanket format commit — Deploy is `IT4WEBBV/Deploy#389`.
- Whether Larastan bootstraps in a testbench-only package — `IT4WEBBV/TallFormbuilder#39`.
- Measuring in-loop yield during Deploy's first runs, which the spec makes a rollout gate.

The skill changes in this plan are inert until at least one repo adopts.

## Self-review

**Spec coverage.** §1 tri-state probe → Task 1. §2 declaration format and `<N>` → Task 2 (code) and Task 3 (contract). §3 whole-scope, no file lists, Pint whole-tree → Task 3. §4 two failure kinds → Task 3. §5 level/baseline and §7 Pint blanket commit → *Out of scope* (repo adoption, per the spec's own split between skill machinery and per-repo artifacts). §6 bounded suppressions → Task 3. §8 scope-qualified evidence and recompute-not-store → Task 3. §9 blocking scoped to touched files → Task 3. §10 code surface → the File Structure table. §11 profiles and rollout gate → *Out of scope*, tracked in the two issues.

**Placeholder scan.** No TBD/TODO; every code step carries the literal content, every verification step carries its command and expected output.

**Type consistency.** `pipeline_repo_checks()` returns `state`/`commands`/`error` in Task 1's implementation, Task 1's tests, and Task 3's prose table. `pipeline_expand_slot(string $command, string $slotSuffix): string` is identical in Task 2's tests, its implementation, and Task 3's invocation paragraph. Check key names are `static-analysis` and `format` throughout.
