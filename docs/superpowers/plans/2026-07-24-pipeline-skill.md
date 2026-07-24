# `pipeline` Skill Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the `/pipeline` skill — a trampoline that walks the feature chain (design → review-plan → handoff → implement → verify-ui → review-pr) at a chosen mode, whose deterministic guardrails run as tested PHP and whose orchestration lives in an authored SKILL.md + references.

**Architecture:** Two phases. **Phase A** is the deterministic core — pure PHP functions (TDD with Pest) for the three things whose reliability the "un-skippable" promise rests on: content/verify-ui **trigger detection**, the **chain + navigation guardrail**, and **manifest** read/write/validate/reconstruct. It reuses `/critique`'s `parse_diff`. **Phase B** is the prose skill — `references/{manifest,gates,engine}.md` and `SKILL.md` — authored with `superpowers:writing-skills`, wiring in the Phase A helpers and verified by a smoke test.

**Tech Stack:** PHP 8.x (pure functions, no framework), Pest for tests — already a dev dependency in the repo's `composer.json` (`pestphp/pest ^4.7`). The pipeline itself is a Claude Code skill (markdown + reference docs) that invokes the existing station skills (`brainstorming`, `writing-plans`, `/critique`, `handoff`, `work-on`, `browser-verification`). Checks read a unified diff on stdin; the orchestration shells out to `git`/`gh`/`docker`/`restart.sh` at run time.

**Spec:** `docs/superpowers/specs/2026-07-24-pipeline-skill-design.md` (post-critique, approved).

## Global Constraints

Every task inherits these, copied from the spec:

- **Checks are PHP + Pest**, host-side meta-tooling (not a Laravel app command — the Docker rule does not apply), and **reuse `/critique`'s `parse_diff`** (`skills/critique/checks/diff_parse.php`) rather than re-implementing diff parsing. `/critique` is a hard dependency of the pipeline anyway (it is two of the stations).
- **Two modes: `interactive` (default) and `auto`.** `interactive` stops at every leg boundary; `auto` runs the autonomous legs and **parks** at the two station-boundary gates (plan-approval, PR-review). "Run past the plan gate to a PR" is `auto` with that gate flipped to report-only in the policy table — not a third mode.
- **The manifest is a local, gitignored, disposable cursor** at `.claude/pipeline/<branch>.json`, storing **pointers never content**. Durable truth is git + gh + the committed spec/plan + the PR. A missing manifest is **reconstructed** by probing durable state; only ephemeral records are re-established by re-running a leg. Every leg opens with an **invariant check** and **halts on mismatch**.
- **One worktree for the whole run.** The pipeline **creates** a worktree at kickoff (deriving the branch from the idea) if the current checkout isn't already it; `implement` reuses `work-on`'s logic but **not** its slot claim, so no *second* slot appears mid-chain. Teardown is the human's call — the pipeline never removes a worktree.
- **The pipeline starts the dev stack itself, without asking** (`restart.sh`, non-destructive), lazily before `implement`. A genuine failure to start the stack is a **hard failure**, not a hesitation point.
- **Content gates are non-skippable in both modes:** touches an `it4web/*` package (composer.json), writes a DB migration (path), touches authorization (grep), the project-vs-package call (a `/critique plan` judgment). **`verify-ui` is non-skippable when the diff touches the UI.**
- **Failure policy:** hard failure → halt, no retry; blocking review finding (Tier-1 / *rework*) → re-arm the next gate as a human stop and continue to a packaged parcel, **except** a *rework* verdict on the **plan** loops back to revise the plan (never builds on it); Tier-2 → log and continue.
- **Navigation is natural language over one slash command** (`/pipeline`). Backward navigation is free; **forward past a gate that has not run is refused**.
- **No findings store. Nothing posted to GitHub** without instruction; nothing ever addresses a person.
- **Skill home:** `skills/pipeline/`, symlinked into `~/.claude/skills/pipeline`; global, no per-project config.

---

## Phase A — Deterministic core (TDD, PHP + Pest)

### Task 1: Pest wiring + trigger detection

**Files:**
- Create: `skills/pipeline/checks/phpunit.xml`
- Create: `skills/pipeline/checks/tests/Pest.php`
- Create: `skills/pipeline/checks/triggers.php`
- Test: `skills/pipeline/checks/tests/TriggersTest.php`

**Interfaces:**
- Consumes: `parse_diff(string $diff): array` from `skills/critique/checks/diff_parse.php` (list of `['file' => string, 'added' => array<['line' => int, 'text' => string]>]`).
- Produces: `pipeline_triggers(string $diff, ?string $repoPackageName = null): array` → `['package' => bool, 'migration' => bool, 'auth' => bool, 'ui' => bool]`. `$repoPackageName` is the repo's own `composer.json` `name` (the engine reads it), so a change *inside* an `it4web/*` package repo trips `package` even when the diff doesn't touch `composer.json`.

- [ ] **Step 1: Point Pest at the pipeline checks suite**

```xml
<!-- skills/pipeline/checks/phpunit.xml -->
<?xml version="1.0"?>
<phpunit bootstrap="../../../vendor/autoload.php" colors="true">
  <testsuites>
    <testsuite name="pipeline-checks">
      <directory>tests</directory>
    </testsuite>
  </testsuites>
</phpunit>
```

```php
// skills/pipeline/checks/tests/Pest.php
<?php

// Load whichever pipeline check functions exist so far (added across Phase A tasks),
// so each task's "watch it fail" is an undefined-function failure, not a missing-file fatal.
require_once __DIR__ . '/../../../critique/checks/diff_parse.php'; // reuse the tested parser
foreach (['triggers.php', 'pipeline.php', 'manifest.php'] as $f) {
    $path = __DIR__ . '/../' . $f;
    if (is_file($path)) {
        require_once $path;
    }
}
```

- [ ] **Step 2: Write the failing test**

```php
// skills/pipeline/checks/tests/TriggersTest.php
<?php

it('detects a UI-touching diff by path', function () {
    $diff = <<<'DIFF'
+++ b/resources/views/orders/show.blade.php
@@ -1,0 +1,1 @@
+<div>hi</div>
DIFF;
    expect(pipeline_triggers($diff)['ui'])->toBeTrue();

    $livewire = <<<'DIFF'
+++ b/app/Livewire/OrderTable.php
@@ -1,0 +1,1 @@
+// component
DIFF;
    expect(pipeline_triggers($livewire)['ui'])->toBeTrue();

    $backendOnly = <<<'DIFF'
+++ b/app/Models/Order.php
@@ -1,0 +1,1 @@
+protected $guarded = [];
DIFF;
    expect(pipeline_triggers($backendOnly)['ui'])->toBeFalse();
});

it('detects a migration by path, not by data-write', function () {
    $diff = <<<'DIFF'
+++ b/database/migrations/2026_07_01_000000_add_status.php
@@ -1,0 +1,1 @@
+Schema::table('orders', fn ($t) => $t->string('status'));
DIFF;
    expect(pipeline_triggers($diff)['migration'])->toBeTrue();

    $appOnly = <<<'DIFF'
+++ b/app/Actions/DoThing.php
@@ -1,0 +1,1 @@
+DB::table('orders')->update(['x' => 1]);
DIFF;
    expect(pipeline_triggers($appOnly)['migration'])->toBeFalse();
});

it('detects authorization changes by added-line grep', function () {
    $diff = <<<'DIFF'
+++ b/app/Http/Controllers/OrderController.php
@@ -1,0 +1,2 @@
+$this->authorize('update', $order);
+return Gate::allows('view', $order);
DIFF;
    expect(pipeline_triggers($diff)['auth'])->toBeTrue();

    $noAuth = <<<'DIFF'
+++ b/app/Http/Controllers/OrderController.php
@@ -1,0 +1,1 @@
+return view('orders.index');
DIFF;
    expect(pipeline_triggers($noAuth)['auth'])->toBeFalse();
});

it('detects an it4web package by repo name or by a bumped constraint', function () {
    $noDiff = "+++ b/app/Foo.php\n@@ -1,0 +1,1 @@\n+// x\n";
    expect(pipeline_triggers($noDiff, 'it4web/tallui')['package'])->toBeTrue();
    expect(pipeline_triggers($noDiff, 'acme/project')['package'])->toBeFalse();

    $bump = <<<'DIFF'
+++ b/composer.json
@@ -1,0 +1,1 @@
+        "it4web/talldatatable": "^3.1",
DIFF;
    expect(pipeline_triggers($bump, 'acme/project')['package'])->toBeTrue();
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `cd ~/GitProjects/LaravelClaudeMd/LaravelClaudeMd && ./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml`
Expected: FAIL — `Call to undefined function pipeline_triggers()`.

- [ ] **Step 4: Write minimal implementation**

```php
// skills/pipeline/checks/triggers.php
<?php

require_once __DIR__ . '/../../critique/checks/diff_parse.php';

/**
 * Which non-skippable gates / conditional legs a change trips.
 * $repoPackageName = the repo's own composer.json `name` (may be null).
 *
 * @return array{package: bool, migration: bool, auth: bool, ui: bool}
 */
function pipeline_triggers(string $diff, ?string $repoPackageName = null): array
{
    $files = parse_diff($diff);

    $uiPathRe   = '#(\.blade\.php$|^app/(Http/)?Livewire/|^resources/(views|css|js)/|\.vue$|tailwind\.config)#';
    $migrationRe = '#^database/migrations/.*\.php$#';
    $authRe     = '/\bauthorize\(|\bGate::|\bPolicy\b|[\'"]can:|->can\(|middleware\([\'"]can:/';

    $ui = $migration = $auth = false;
    $package = $repoPackageName !== null && str_starts_with($repoPackageName, 'it4web/');

    foreach ($files as $f) {
        if (preg_match($uiPathRe, $f['file'])) {
            $ui = true;
        }
        if (preg_match($migrationRe, $f['file'])) {
            $migration = true;
        }
        $isComposer = $f['file'] === 'composer.json';
        foreach ($f['added'] as $a) {
            if (preg_match($authRe, $a['text'])) {
                $auth = true;
            }
            if ($isComposer && preg_match('#["\']it4web/#', $a['text'])) {
                $package = true;
            }
        }
    }

    return ['package' => $package, 'migration' => $migration, 'auth' => $auth, 'ui' => $ui];
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add skills/pipeline/checks/phpunit.xml skills/pipeline/checks/tests/Pest.php skills/pipeline/checks/triggers.php skills/pipeline/checks/tests/TriggersTest.php
git commit -m "feat(pipeline): trigger detection (package/migration/auth/ui) reusing critique parse_diff"
```

---

### Task 2: Chain, next-leg, navigation guardrail, policy

**Files:**
- Create: `skills/pipeline/checks/pipeline.php`
- Test: `skills/pipeline/checks/tests/PipelineTest.php`

**Interfaces:**
- Produces:
  - `pipeline_legs(): array` → `['design', 'review-plan', 'handoff', 'implement', 'verify-ui', 'review-pr']` (chain order; `verify-ui` is a position that runs only when the ui trigger fires).
  - `pipeline_next_leg(string $cursor, array $triggers): ?string` → the next leg after `$cursor`, skipping `verify-ui` when `$triggers['ui']` is false; `null` at the end.
  - `pipeline_can_navigate(string $from, string $to, array $doneLegs, array $triggers): bool` → backward or same-position is always allowed; forward is allowed only if every **gate leg** earlier in the chain than `$to` is in `$doneLegs`. Gate legs = `review-plan`, `review-pr`, and `verify-ui` **only when** `$triggers['ui']` is true.
  - `pipeline_resolve_policy(string $mode): array` → `['auto_continue' => bool, 'gates' => ['plan-approval' => 'stop'|'report', 'pr-review' => 'stop'|'report']]`.

- [ ] **Step 1: Write the failing test**

```php
// skills/pipeline/checks/tests/PipelineTest.php
<?php

$uiOn  = ['package' => false, 'migration' => false, 'auth' => false, 'ui' => true];
$uiOff = ['package' => false, 'migration' => false, 'auth' => false, 'ui' => false];

it('orders the chain and skips verify-ui when the UI is untouched', function () use ($uiOn, $uiOff) {
    expect(pipeline_next_leg('design', $uiOff))->toBe('review-plan');
    expect(pipeline_next_leg('implement', $uiOn))->toBe('verify-ui');
    expect(pipeline_next_leg('implement', $uiOff))->toBe('review-pr');
    expect(pipeline_next_leg('review-pr', $uiOff))->toBeNull();
});

it('refuses forward navigation past an un-run gate but allows backward', function () use ($uiOn, $uiOff) {
    // design → implement with review-plan not done: refused
    expect(pipeline_can_navigate('design', 'implement', ['design'], $uiOff))->toBeFalse();
    // once review-plan is done: allowed
    expect(pipeline_can_navigate('review-plan', 'implement', ['design', 'review-plan'], $uiOff))->toBeTrue();
    // backward is always allowed
    expect(pipeline_can_navigate('implement', 'design', ['design', 'review-plan'], $uiOff))->toBeTrue();
    // to review-pr while a triggered verify-ui hasn't run: refused
    expect(pipeline_can_navigate('implement', 'review-pr', ['design', 'review-plan', 'handoff', 'implement'], $uiOn))->toBeFalse();
    // same set, but UI untouched (verify-ui not a gate): allowed
    expect(pipeline_can_navigate('implement', 'review-pr', ['design', 'review-plan', 'handoff', 'implement'], $uiOff))->toBeTrue();
});

it('resolves interactive to stop-every-boundary and auto to park-at-gates', function () {
    $i = pipeline_resolve_policy('interactive');
    expect($i['auto_continue'])->toBeFalse();

    $a = pipeline_resolve_policy('auto');
    expect($a['auto_continue'])->toBeTrue()
        ->and($a['gates']['plan-approval'])->toBe('stop')
        ->and($a['gates']['pr-review'])->toBe('stop');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml`
Expected: FAIL — `Call to undefined function pipeline_next_leg()`.

- [ ] **Step 3: Write minimal implementation**

```php
// skills/pipeline/checks/pipeline.php
<?php

/** @return list<string> */
function pipeline_legs(): array
{
    return ['design', 'review-plan', 'handoff', 'implement', 'verify-ui', 'review-pr'];
}

/** Gate legs that must not be skipped forward-over. verify-ui only counts when UI is touched. */
function pipeline_gate_legs(array $triggers): array
{
    $gates = ['review-plan', 'review-pr'];
    if (! empty($triggers['ui'])) {
        $gates[] = 'verify-ui';
    }

    return $gates;
}

function pipeline_next_leg(string $cursor, array $triggers): ?string
{
    $legs = pipeline_legs();
    $i = array_search($cursor, $legs, true);
    if ($i === false) {
        return null;
    }
    for ($j = $i + 1; $j < count($legs); $j++) {
        if ($legs[$j] === 'verify-ui' && empty($triggers['ui'])) {
            continue; // skip the conditional leg
        }

        return $legs[$j];
    }

    return null;
}

function pipeline_can_navigate(string $from, string $to, array $doneLegs, array $triggers): bool
{
    $legs = pipeline_legs();
    $fi = array_search($from, $legs, true);
    $ti = array_search($to, $legs, true);
    if ($fi === false || $ti === false) {
        return false;
    }
    if ($ti <= $fi) {
        return true; // backward or same: always allowed
    }
    // forward: every gate leg strictly before $to must have run
    foreach (pipeline_gate_legs($triggers) as $gate) {
        $gi = array_search($gate, $legs, true);
        if ($gi < $ti && ! in_array($gate, $doneLegs, true)) {
            return false;
        }
    }

    return true;
}

/** @return array{auto_continue: bool, gates: array{plan-approval: string, pr-review: string}} */
function pipeline_resolve_policy(string $mode): array
{
    return [
        'auto_continue' => $mode === 'auto',
        'gates' => ['plan-approval' => 'stop', 'pr-review' => 'stop'],
    ];
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/pipeline.php skills/pipeline/checks/tests/PipelineTest.php
git commit -m "feat(pipeline): chain order, next-leg, forward-gate navigation guardrail, policy"
```

---

### Task 3: Manifest read/write/validate + cursor reconstruction

**Files:**
- Create: `skills/pipeline/checks/manifest.php`
- Test: `skills/pipeline/checks/tests/ManifestTest.php`

**Interfaces:**
- Produces:
  - `manifest_read(string $path): ?array` — decoded manifest, or `null` if absent/unreadable.
  - `manifest_write(string $path, array $data): void` — pretty JSON, creating the parent dir.
  - `manifest_validate(array $data): array` — list of missing required keys (`branch`, `worktree`, `mode`, `cursor`); empty = valid.
  - `manifest_infer_cursor(array $probes): string` — the leg to resume at, from durable-state probes: `['spec'=>bool,'plan'=>bool,'planApproved'=>bool,'pr'=>?int,'implemented'=>bool,'uiNeeded'=>bool,'verifyUi'=>bool,'prReviewed'=>bool]`. Returns `'done'` when the chain is complete.

- [ ] **Step 1: Write the failing test**

```php
// skills/pipeline/checks/tests/ManifestTest.php
<?php

it('round-trips a manifest and reports missing required keys', function () {
    $path = sys_get_temp_dir() . '/pipeline-manifest-' . uniqid() . '.json';
    $data = ['branch' => 'feature/x', 'worktree' => '/tmp/wt', 'mode' => 'interactive', 'cursor' => 'design'];

    manifest_write($path, $data);
    expect(manifest_read($path))->toBe($data);
    expect(manifest_validate($data))->toBe([]);
    expect(manifest_validate(['branch' => 'feature/x']))->toContain('cursor');

    unlink($path);
    expect(manifest_read($path))->toBeNull();
});

it('infers the resume cursor from durable-state probes', function () {
    $base = ['spec' => false, 'plan' => false, 'planApproved' => false, 'pr' => null, 'implemented' => false, 'uiNeeded' => false, 'verifyUi' => false, 'prReviewed' => false];

    expect(manifest_infer_cursor($base))->toBe('design');
    expect(manifest_infer_cursor([...$base, 'spec' => true, 'plan' => true]))->toBe('review-plan');
    expect(manifest_infer_cursor([...$base, 'spec' => true, 'plan' => true, 'planApproved' => true]))->toBe('handoff');
    expect(manifest_infer_cursor([...$base, 'spec' => true, 'plan' => true, 'planApproved' => true, 'pr' => 42]))->toBe('implement');
    expect(manifest_infer_cursor([...$base, 'spec' => true, 'plan' => true, 'planApproved' => true, 'pr' => 42, 'implemented' => true, 'uiNeeded' => true]))->toBe('verify-ui');
    expect(manifest_infer_cursor([...$base, 'spec' => true, 'plan' => true, 'planApproved' => true, 'pr' => 42, 'implemented' => true, 'uiNeeded' => false]))->toBe('review-pr');
    expect(manifest_infer_cursor([...$base, 'spec' => true, 'plan' => true, 'planApproved' => true, 'pr' => 42, 'implemented' => true, 'uiNeeded' => true, 'verifyUi' => true, 'prReviewed' => true]))->toBe('done');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml`
Expected: FAIL — `Call to undefined function manifest_write()`.

- [ ] **Step 3: Write minimal implementation**

```php
// skills/pipeline/checks/manifest.php
<?php

function manifest_read(string $path): ?array
{
    if (! is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : null;
}

function manifest_write(string $path, array $data): void
{
    $dir = dirname($path);
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

/** @return list<string> missing required keys */
function manifest_validate(array $data): array
{
    return array_values(array_filter(
        ['branch', 'worktree', 'mode', 'cursor'],
        fn ($key) => ! array_key_exists($key, $data),
    ));
}

function manifest_infer_cursor(array $p): string
{
    if (empty($p['spec']) || empty($p['plan'])) {
        return 'design';
    }
    if (empty($p['planApproved'])) {
        return 'review-plan';
    }
    if (empty($p['pr'])) {
        return 'handoff';
    }
    if (empty($p['implemented'])) {
        return 'implement';
    }
    if (! empty($p['uiNeeded']) && empty($p['verifyUi'])) {
        return 'verify-ui';
    }
    if (empty($p['prReviewed'])) {
        return 'review-pr';
    }

    return 'done';
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml`
Expected: PASS.

- [ ] **Step 5: Run the whole Phase A suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml`
Expected: PASS — triggers, pipeline, manifest all green.

- [ ] **Step 6: Commit**

```bash
git add skills/pipeline/checks/manifest.php skills/pipeline/checks/tests/ManifestTest.php
git commit -m "feat(pipeline): manifest read/write/validate and cursor reconstruction"
```

---

## Phase B — Prose skill (writing-skills)

### Task 4: `references/manifest.md` and `references/gates.md`

**Files:**
- Create: `skills/pipeline/references/manifest.md`
- Create: `skills/pipeline/references/gates.md`

**Interfaces:**
- Consumes: nothing executable; these docs are read by `engine.md` and `SKILL.md`.
- Produces: the manifest schema + reconstruction contract, and the gate/trigger/navigation contract — each **mirroring the Phase A functions verbatim** so prose and code cannot drift.

- [ ] **Step 1: Invoke the authoring skill.** Announce and invoke `superpowers:writing-skills`; it governs the format of these reference files and the SKILL.md in Tasks 5–6.

- [ ] **Step 2: Write `references/manifest.md`** transcribing spec §2:
  - The field table **exactly matching** `manifest_validate`'s required keys plus the optional ones: `branch`/`pipeline_id`, `worktree` (absolute path), `mode` (`interactive`|`auto`), `gate_policy`, `cursor`, `artifacts` (spec path, plan path, PR#), `last_sha`, `gate_ledger`, `lease` (session id + timestamp).
  - The rule **pointers, never content**; a recomputable field is derived at leg start, never trusted from the file.
  - The **invariant check** (recorded artifact at recorded ref; PR in expected state) → halt on mismatch.
  - The **reconstruction probes** that feed `manifest_infer_cursor`, each named to the git/gh command that gathers it: `spec`/`plan` (files on the branch), `planApproved` (gate ledger, else re-run review-plan), `pr` (`gh pr list --head <branch>`), `implemented` (PR marked ready / commits present), `uiNeeded` (`pipeline_triggers` over the diff), `verifyUi` (proof comment on the PR), `prReviewed` (gate ledger). State plainly that a missing manifest is rebuilt from these, never fatal.

- [ ] **Step 3: Write `references/gates.md`** transcribing spec §3, wired to Phase A:
  - The **mode table** matching `pipeline_resolve_policy`: `interactive` (stop every boundary), `auto` (auto-continue, park at plan-approval + PR-review). The report-only override for well-specified work = a one-line `gate_policy` edit.
  - The **content-gate table** matching `pipeline_triggers`: `package` (composer.json name / bumped `it4web/*` constraint), `migration` (path under `database/migrations/`), `auth` (grep `authorize(`/`Gate::`/`Policy`/`can:`/middleware), `project-vs-package` (a `/critique plan` **judgment**, no mechanical trigger) — all **non-skippable in both modes**.
  - **`verify-ui`** non-skippable when `pipeline_triggers(...)['ui']` is true.
  - The **navigation guardrail** matching `pipeline_can_navigate`: backward free; forward past an un-run gate refused — with the note that this is the mechanism behind the un-skippable-review promise.
  - State how the engine calls Phase A: `git diff origin/<base>...HEAD; git diff HEAD | php skills/pipeline/checks/triggers.php` (or the functions directly), and reads `composer.json` `name` for the package trigger.

- [ ] **Step 4: Cross-check prose against code.** Verify: every field named in `manifest.md` appears in `manifest_validate`/`manifest_infer_cursor`; every gate/trigger named in `gates.md` appears in `pipeline_triggers`/`pipeline_resolve_policy`/`pipeline_can_navigate`; no doc names a field or gate the code doesn't have, and vice-versa. Fix any mismatch in whichever is wrong.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/references/manifest.md skills/pipeline/references/gates.md
git commit -m "feat(pipeline): manifest and gates reference docs, mirroring the checks"
```

---

### Task 5: `references/engine.md` — the leg loop

**Files:**
- Create: `skills/pipeline/references/engine.md`

**Interfaces:**
- Consumes: `references/manifest.md`, `references/gates.md`, and the Phase A checks.
- Produces: the operational procedure every `/pipeline` invocation follows.

- [ ] **Step 1:** (via `writing-skills`) Write the **trampoline loop** (spec §1): read manifest (or reconstruct) → invariant check → pick next leg (`pipeline_next_leg`) → run leg → write manifest → stop or continue. State the control rule plainly: **autonomous legs are subagents that return to the loop; interactive legs stop and are re-invoked; auto-continuation spans only autonomous legs; it fails closed.**

- [ ] **Step 2:** Write **kickoff / worktree resolution** (spec §6): from an idea → derive a branch (slugify: lowercase, non-`[a-z0-9]`→`-`, strip, truncate ~50, prefix `feature/`) and create the worktree (`scripts/worktree.sh create <branch>` on slot-enabled projects; a plain `git switch -c` in place otherwise; headless + no machinery + no consent → stop and report). From a spec-path/`pr#` → use the known branch, or the current checkout if already on it. Record the `worktree` path in the manifest; a resume locates it via `git worktree list`.

- [ ] **Step 3:** Write **dev-stack readiness** (spec §5): before the first leg that needs it (`implement`), bring the stack up with `restart.sh` **without asking** (non-destructive); leave it running; a genuine failure to start is a hard failure (§4). Reference [[docker-stack-no-hesitation]] as the house preference.

- [ ] **Step 4:** Write the **per-station briefs** — for each leg, the skill it invokes, its interactive vs autonomous form, and what it reads/writes in the manifest:
  - **design** (compound `brainstorming`→`writing-plans`, one leg): interactive = human dialogue; autonomous = subagent from a brief that **must write its assumed answers into the spec** for `/critique plan` to audit. Produces spec + plan pointers.
  - **review-plan** → `/critique plan`: feeds the plan-approval gate and the project-vs-package judgment gate. A *rework* verdict **loops back** to design (§4), never proceeds.
  - **handoff** → `handoff pr`: pushes, opens the draft PR; its PR comment is a **projection** of the manifest. Writes the PR# pointer.
  - **implement** → `work-on`'s logic **in the current worktree** (no second slot), test-first; needs the stack up.
  - **verify-ui** (conditional on `pipeline_triggers(...)['ui']`): runs `browser-verification`, attaches proof to the PR; non-skippable when triggered; a broken UI loops back to implement, Playwright-unavailable halts.
  - **review-pr** → `/critique pr`: feeds the PR-review gate.

- [ ] **Step 5:** Write the **failure policy** (spec §4) and **navigation** (spec §7): halt-on-hard-failure (no retry); re-arm-next-gate on a blocking finding (loop-back on a plan *rework*); Tier-2 logs and continues. Natural-language navigation drives `pipeline_can_navigate`; forward-past-an-un-run-gate is refused.

- [ ] **Step 6: Cross-check** every leg name against `pipeline_legs()` and every referenced skill against the installed skills (`brainstorming`, `writing-plans`, `critique`, `handoff`, `work-on`, `browser-verification`). Fix mismatches.

- [ ] **Step 7: Commit**

```bash
git add skills/pipeline/references/engine.md
git commit -m "feat(pipeline): engine reference — trampoline loop, kickoff, stations, failure, navigation"
```

---

### Task 6: `SKILL.md`, wire-up, and smoke test

**Files:**
- Create: `skills/pipeline/SKILL.md`
- Create: symlink `~/.claude/skills/pipeline` → `skills/pipeline/`

**Interfaces:**
- Consumes: `references/{engine,manifest,gates}.md`, the Phase A checks.
- Produces: the skill entry point.

- [ ] **Step 1: Write the frontmatter and triggers** (via `writing-skills`)

```markdown
---
name: pipeline
description: Use to walk a feature end-to-end through the design→review-plan→handoff→implement→verify-ui→review-pr chain, interactive or unattended. Triggers on "/pipeline", "run the pipeline", "take this through the pipeline", "next step"/"go to step X" while a run is active.
---
```

- [ ] **Step 2: Write the invocation + navigation section** exactly matching spec §7: `/pipeline [interactive|auto] <idea | spec-path | pr#>` (mode defaults to **interactive**); bare `/pipeline` resumes the current branch's run; **navigation is natural language** ("next step", "go to step X"), no separate `/next`; forward-past-an-un-run-gate is refused.

- [ ] **Step 3: Write the trampoline overview** (spec §1 summary) and **point at the references** for the detail: `references/engine.md` (the loop + stations), `references/gates.md` (modes, triggers, guardrail), `references/manifest.md` (state). Do not duplicate their content.

- [ ] **Step 4: Write the non-goals** (spec §Non-goals): sequences skills, does not replace their judgment; no new review logic (`/critique`) or bug-hunting (`/code-review`); creates one worktree but never tears one down; no GitHub posting beyond `handoff`/`work-on`; no findings store.

- [ ] **Step 5: Symlink the skill** (matches the multi-repo setup in CLAUDE.md)

```bash
ln -sfn ~/GitProjects/LaravelClaudeMd/LaravelClaudeMd/skills/pipeline ~/.claude/skills/pipeline
ls -l ~/.claude/skills/pipeline
```

Expected: symlink resolves to the repo skill dir.

- [ ] **Step 6: Run the full Phase A suite**

Run: `cd ~/GitProjects/LaravelClaudeMd/LaravelClaudeMd && ./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml`
Expected: PASS — triggers, pipeline, manifest all green.

- [ ] **Step 7: `writing-skills` trigger check** — confirm the description fires on "/pipeline", "run the pipeline", "next step" (during a run) and not on unrelated phrasings, per the `writing-skills` verification guidance.

- [ ] **Step 8: Smoke-test the trigger CLI on a real diff** (if a `triggers.php` CLI shim is added, or call the function via `php -r`)

```bash
git diff origin/main...HEAD | php -r 'require "skills/pipeline/checks/triggers.php"; echo json_encode(pipeline_triggers(stream_get_contents(STDIN), "it4web/laravel-claude-md")), "\n";'
```

Expected: valid JSON `{"package":true,...}` (this repo's name is `it4web/laravel-claude-md`, so `package` is true here).

- [ ] **Step 9: Dry-run the trampoline** in a fresh session on a scratch branch: `/pipeline` bare with no manifest → it reports "no run on this branch; start with `/pipeline <idea|spec|pr>`" and stops (does not invent a run). Then `/pipeline interactive <a tiny idea>` → it derives a branch, creates/uses a worktree, and stops at the design station for input. Record the result in the PR description; **do not** post anywhere on GitHub.

- [ ] **Step 10: Commit** any fixes surfaced by verification, then stop for review.

```bash
git add -A && git commit -m "feat(pipeline): SKILL.md entry point, symlink, and smoke-test results"
```

---

## Self-Review

**1. Spec coverage.** Every spec section maps to a task:
- §1 execution model (trampoline, fails closed) → engine.md Task 5 Step 1; navigation guardrail proven in Task 2.
- §2 manifest (fields, invariant check, reconstruction) → Task 3 (code) + manifest.md Task 4.
- §3 autonomy (modes, content gates, verify-ui, navigation) → Tasks 1–2 (code) + gates.md Task 4.
- §4 failure policy (halt/re-arm/loop-back/Tier-2) → engine.md Task 5 Step 5.
- §5 stations + dev-stack readiness → engine.md Task 5 Steps 3–4.
- §6 worktree ownership (create one, no second slot, human teardown) → engine.md Task 5 Step 2.
- §7 invocation + navigation (`/pipeline` only, natural language, forward-gate refusal) → SKILL.md Task 6 Step 2 + Task 2 code.
- Non-goals → SKILL.md Task 6 Step 4.
- Validation-strategy items that need real runs (signal on a live chain, resume after a real death, content-gate firing end-to-end) are **post-merge acceptance**, exercised the first time `/pipeline` runs on real work — flagged here so they are not mistaken for build tasks.

**2. Placeholder scan.** No "TBD"/"handle appropriately". Phase A steps carry complete PHP + tests. Phase B steps are prose authored via `writing-skills` and enumerate their required content, cross-checked against the Phase A functions, rather than deferring it.

**3. Type consistency.** `pipeline_triggers` returns `['package','migration','auth','ui']` (bools) everywhere it is consumed (Tasks 1, 2, 4, 5, 6). `pipeline_next_leg`/`pipeline_can_navigate`/`pipeline_gate_legs` all take that same `$triggers` shape. Leg names are one set — `pipeline_legs()` = `['design','review-plan','handoff','implement','verify-ui','review-pr']` — reused unchanged by `manifest_infer_cursor` (which returns those names plus `'done'`), `gates.md`, `engine.md`, and `SKILL.md`. `pipeline_resolve_policy` returns `['auto_continue','gates'=>['plan-approval','pr-review']]`, referenced identically in `gates.md`.

**Known limitation carried forward:** the spec's live-run acceptance (a real feature carried to a reviewed PR, resume after a genuine mid-chain death, each content gate firing on real work) requires running the pipeline on an actual feature and lives in the spec's Validation section as post-merge acceptance, not in this build plan.
