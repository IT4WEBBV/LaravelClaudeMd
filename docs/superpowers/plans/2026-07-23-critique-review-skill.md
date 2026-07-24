# /critique Review Skill Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the `/critique` skill — a four-mode review skill whose deterministic pre-pass runs as tested PHP and whose modes/pipeline live in an authored SKILL.md.

**Architecture:** Two phases. **Phase A** is the deterministic stage-0 pre-pass: plain PHP functions over a parsed unified diff (plus one filesystem check), TDD with Pest, in the skill repo's own `composer.json`. **Phase B** is the prose skill — `references/rubrics.md` and `SKILL.md` — authored with `superpowers:writing-skills`, wiring in the Phase A checks and verified by a real dry run.

**Tech Stack:** PHP 8.x (pure functions, no framework), Pest for tests. The `LaravelClaudeMd` skill repo gets its own `composer.json` with `pestphp/pest` as a dev dependency; `vendor/` is gitignored. Checks read a unified diff on stdin and shell out to `git`/`find` only for the one filesystem check.

**Spec:** `docs/superpowers/specs/2026-07-23-critique-review-skill-design.md` (v6, approved).

## Global Constraints

Every task inherits these, copied verbatim from the spec plus the runner decision:

- **Runner decision (plan-time):** stage-0 checks are **PHP + Pest**, not shell and not Node. They are pure string processing (no Laravel), run **on the host** over host-side `git` output — meta-tooling, not a Laravel application command, so the Docker rule does not apply. If the host lacks PHP the skill degrades to the LLM reviewer; stage 0 is belt-and-suspenders.
- **The unit of review is the whole change:** `git diff origin/main...HEAD` plus uncommitted working-tree changes; with a PR number, `gh pr diff <n>`. Never commit ranges, never individual commits.
- **A stage-0 heuristic check must return ≤5 candidates on a normal diff.** One that returns a long list has relocated noise, not removed it, and is cut.
- **An exact check has no licence to be wrong.** One false positive on a real branch demotes it to heuristic or deletes it.
- **No findings store.** No ledger, no state between runs, except the drop tally inside `rubrics.md`. Re-review is a fresh run or labels a pasted prior report.
- **Nothing is posted to GitHub** without an explicit instruction, and nothing ever addresses a person.
- **Default reviewer model is Fable**, configurable. Reasoning effort is session-level; the skill promises no per-dispatch effort.
- **Findings cap: 15**, remainder as category-slug one-liners; Tier-3 dropped and disclosed by category.
- **The verdict column (CONFIRMED/PLAUSIBLE) appears only in `pr` mode.**
- **Skill home:** `skills/critique/`, symlinked into `~/.claude/skills/critique`; no per-project config.

---

## Phase A — Deterministic pre-pass (TDD, PHP + Pest)

### Task 1: Pest setup + diff parser

**Files:**
- Create/modify: `composer.json` (repo root — add Pest dev dep)
- Modify: `.gitignore` (add `/vendor`)
- Create: `skills/critique/checks/diff_parse.php`
- Test: `skills/critique/checks/tests/DiffParseTest.php`
- Create: `skills/critique/checks/tests/Pest.php` (Pest bootstrap for this suite)

**Interfaces:**
- Produces: `parse_diff(string $diff): array` → list of `['file' => string, 'added' => array<['line' => int, 'text' => string]>]`. `line` is the new-file line number; `text` excludes the leading `+`.

- [ ] **Step 1: Add Pest to the skill repo**

```bash
cd ~/GitProjects/LaravelClaudeMd/LaravelClaudeMd
composer require --dev pestphp/pest --with-all-dependencies
printf '/vendor\n' >> .gitignore
```

Expected: `vendor/bin/pest` exists; `.gitignore` ignores `/vendor`.

- [ ] **Step 2: Point Pest at the checks suite**

```php
// skills/critique/checks/tests/Pest.php
<?php
// Load the check functions for every test in this directory.
require_once __DIR__ . '/../diff_parse.php';
require_once __DIR__ . '/../checks.php';
require_once __DIR__ . '/../vendor_hacks.php';
```

Create `skills/critique/checks/phpunit.xml` so `pest` finds the suite:

```xml
<?xml version="1.0"?>
<phpunit bootstrap="vendor/autoload.php" colors="true">
  <testsuites>
    <testsuite name="critique-checks">
      <directory>skills/critique/checks/tests</directory>
    </testsuite>
  </testsuites>
</phpunit>
```

- [ ] **Step 3: Write the failing test**

```php
// skills/critique/checks/tests/DiffParseTest.php
<?php

$sample = <<<'DIFF'
diff --git a/app/Foo.php b/app/Foo.php
--- a/app/Foo.php
+++ b/app/Foo.php
@@ -10,3 +10,4 @@ class Foo
 context line
-old line
+new line one
+new line two
diff --git a/app/Bar.php b/app/Bar.php
--- a/app/Bar.php
+++ b/app/Bar.php
@@ -1,2 +1,3 @@
+first added
 unchanged
DIFF;

it('groups added lines by file with new-file line numbers', function () use ($sample) {
    $files = parse_diff($sample);
    expect($files)->toHaveCount(2);
    expect($files[0]['file'])->toBe('app/Foo.php');
    expect($files[0]['added'])->toBe([
        ['line' => 11, 'text' => 'new line one'],
        ['line' => 12, 'text' => 'new line two'],
    ]);
    expect($files[1]['file'])->toBe('app/Bar.php');
    expect($files[1]['added'])->toBe([['line' => 1, 'text' => 'first added']]);
});

it('returns an empty array for empty input', function () {
    expect(parse_diff(''))->toBe([]);
});
```

- [ ] **Step 4: Run test to verify it fails**

Run: `cd ~/GitProjects/LaravelClaudeMd/LaravelClaudeMd && ./vendor/bin/pest -c skills/critique/checks/phpunit.xml`
Expected: FAIL — `Call to undefined function parse_diff()`.

- [ ] **Step 5: Write minimal implementation**

```php
// skills/critique/checks/diff_parse.php
<?php

/**
 * Parse a unified diff into per-file added lines.
 * `line` is the line number in the NEW file; `text` drops the leading '+'.
 *
 * @return array<int, array{file: string, added: array<int, array{line: int, text: string}>}>
 */
function parse_diff(string $diff): array
{
    $files = [];
    $idx = -1;
    $newLine = 0;

    foreach (explode("\n", $diff) as $raw) {
        if (str_starts_with($raw, '+++ ')) {
            $path = preg_replace('#^b/#', '', trim(substr($raw, 4)));
            $files[] = ['file' => $path, 'added' => []];
            $idx = count($files) - 1;
            continue;
        }
        if (str_starts_with($raw, '--- ')) {
            continue;
        }
        if (str_starts_with($raw, '@@')) {
            $newLine = preg_match('/\+(\d+)/', $raw, $m) ? (int) $m[1] : 0;
            continue;
        }
        if ($idx < 0) {
            continue;
        }
        if (str_starts_with($raw, '+')) {
            $files[$idx]['added'][] = ['line' => $newLine, 'text' => substr($raw, 1)];
            $newLine++;
        } elseif (str_starts_with($raw, '-')) {
            // removed line: does not advance the new-file counter
        } else {
            $newLine++; // context line
        }
    }

    return $files;
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `./vendor/bin/pest -c skills/critique/checks/phpunit.xml`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add composer.json composer.lock .gitignore skills/critique/checks/
git commit -m "feat(critique): Pest setup and unified diff parser for stage-0"
```

---

### Task 2: `@php` in Blade — exact check

**Files:**
- Create: `skills/critique/checks/checks.php`
- Test: `skills/critique/checks/tests/ChecksTest.php`

**Interfaces:**
- Consumes: `parse_diff` output (Task 1).
- Produces: `check_blade_php(array $files): array` → `['check' => 'blade-php', 'file' => , 'line' => , 'text' => ]` items.

- [ ] **Step 1: Write the failing test**

```php
// skills/critique/checks/tests/ChecksTest.php
<?php

it('flags @php only in added Blade lines', function () {
    $diff = <<<'DIFF'
+++ b/resources/views/x.blade.php
@@ -1,0 +1,2 @@
+@php $x = 1; @endphp
+<div>ok</div>
+++ b/app/Y.php
@@ -1,0 +1,1 @@
+// @php in a comment in a php file, not blade
DIFF;
    $findings = check_blade_php(parse_diff($diff));
    expect($findings)->toHaveCount(1)
        ->and($findings[0]['check'])->toBe('blade-php')
        ->and($findings[0]['file'])->toBe('resources/views/x.blade.php')
        ->and($findings[0]['line'])->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest -c skills/critique/checks/phpunit.xml`
Expected: FAIL — `Call to undefined function check_blade_php()`.

- [ ] **Step 3: Write minimal implementation**

```php
// skills/critique/checks/checks.php
<?php

/** @return array<int, array{check: string, file: string, line: int, text: string}> */
function check_blade_php(array $files): array
{
    $findings = [];
    foreach ($files as $f) {
        if (! str_ends_with($f['file'], '.blade.php')) {
            continue;
        }
        foreach ($f['added'] as $a) {
            if (preg_match('/@php\b/', $a['text'])) {
                $findings[] = ['check' => 'blade-php', 'file' => $f['file'], 'line' => $a['line'], 'text' => trim($a['text'])];
            }
        }
    }

    return $findings;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest -c skills/critique/checks/phpunit.xml`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add skills/critique/checks/checks.php skills/critique/checks/tests/ChecksTest.php
git commit -m "feat(critique): exact check for @php in Blade"
```

---

### Task 3: Null-safety heuristic — `?->` and `??`

**Files:**
- Modify: `skills/critique/checks/checks.php`
- Modify: `skills/critique/checks/tests/ChecksTest.php`

**Interfaces:**
- Produces: `check_null_safety(array $files): array` → items with `check` of `null-safe-op` or `null-coalesce`.

- [ ] **Step 1: Write the failing test**

```php
// append to skills/critique/checks/tests/ChecksTest.php
it('flags ?-> anywhere and ?? outside config only', function () {
    $diff = <<<'DIFF'
+++ b/app/Service.php
@@ -1,0 +1,2 @@
+$name = $user?->profile?->name;
+$fallback = $value ?? 'default';
+++ b/config/app.php
@@ -1,0 +1,1 @@
+'env' => env('APP_ENV') ?? 'production',
DIFF;
    $kinds = array_map(
        fn ($c) => "{$c['check']}@{$c['file']}",
        check_null_safety(parse_diff($diff)),
    );
    expect($kinds)->toContain('null-safe-op@app/Service.php')
        ->and($kinds)->toContain('null-coalesce@app/Service.php')
        ->and($kinds)->not->toContain('null-coalesce@config/app.php');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest -c skills/critique/checks/phpunit.xml`
Expected: FAIL — `Call to undefined function check_null_safety()`.

- [ ] **Step 3: Write minimal implementation**

```php
// append to skills/critique/checks/checks.php

/** @return array<int, array{check: string, file: string, line: int, text: string}> */
function check_null_safety(array $files): array
{
    $candidates = [];
    foreach ($files as $f) {
        if (! str_ends_with($f['file'], '.php')) {
            continue;
        }
        $inConfig = str_starts_with($f['file'], 'config/');
        foreach ($f['added'] as $a) {
            if (str_contains($a['text'], '?->')) {
                $candidates[] = ['check' => 'null-safe-op', 'file' => $f['file'], 'line' => $a['line'], 'text' => trim($a['text'])];
            }
            if (! $inConfig && str_contains($a['text'], '??')) {
                $candidates[] = ['check' => 'null-coalesce', 'file' => $f['file'], 'line' => $a['line'], 'text' => trim($a['text'])];
            }
        }
    }

    return $candidates;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest -c skills/critique/checks/phpunit.xml`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add skills/critique/checks/checks.php skills/critique/checks/tests/ChecksTest.php
git commit -m "feat(critique): null-safety heuristic (?-> and ?? outside config)"
```

---

### Task 4: `->each(` on a possible builder — heuristic

**Files:**
- Modify: `skills/critique/checks/checks.php`
- Modify: `skills/critique/checks/tests/ChecksTest.php`

**Interfaces:**
- Produces: `check_each_on_builder(array $files): array` → `each-on-builder` items.

- [ ] **Step 1: Write the failing test**

```php
// append to skills/critique/checks/tests/ChecksTest.php
it('flags ->each( but not ->get()->each(', function () {
    $diff = <<<'DIFF'
+++ b/app/Report.php
@@ -1,0 +1,2 @@
+User::query()->where('active', true)->each(fn ($u) => $u->touch());
+User::query()->where('active', true)->get()->each(fn ($u) => $u->touch());
DIFF;
    $c = check_each_on_builder(parse_diff($diff));
    expect($c)->toHaveCount(1)->and($c[0]['line'])->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest -c skills/critique/checks/phpunit.xml`
Expected: FAIL — `Call to undefined function check_each_on_builder()`.

- [ ] **Step 3: Write minimal implementation**

```php
// append to skills/critique/checks/checks.php

/** @return array<int, array{check: string, file: string, line: int, text: string}> */
function check_each_on_builder(array $files): array
{
    $candidates = [];
    foreach ($files as $f) {
        if (! str_ends_with($f['file'], '.php')) {
            continue;
        }
        foreach ($f['added'] as $a) {
            if (preg_match('/->each\(/', $a['text']) && ! preg_match('/->get\(\)\s*->each\(/', $a['text'])) {
                $candidates[] = ['check' => 'each-on-builder', 'file' => $f['file'], 'line' => $a['line'], 'text' => trim($a['text'])];
            }
        }
    }

    return $candidates;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest -c skills/critique/checks/phpunit.xml`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add skills/critique/checks/checks.php skills/critique/checks/tests/ChecksTest.php
git commit -m "feat(critique): each-on-builder heuristic"
```

---

### Task 5: Writes inside `database/migrations/` — heuristic

**Files:**
- Modify: `skills/critique/checks/checks.php`
- Modify: `skills/critique/checks/tests/ChecksTest.php`

**Interfaces:**
- Produces: `check_migration_writes(array $files): array` → `migration-write` items.

- [ ] **Step 1: Write the failing test**

```php
// append to skills/critique/checks/tests/ChecksTest.php
it('flags data writes only under database/migrations', function () {
    $diff = <<<'DIFF'
+++ b/database/migrations/2026_01_01_000000_x.php
@@ -1,0 +1,2 @@
+        Schema::table('orders', fn (Blueprint $t) => $t->string('status'));
+        DB::table('orders')->update(['status' => 'active']);
+++ b/app/Actions/DoThing.php
@@ -1,0 +1,1 @@
+        DB::table('orders')->update(['x' => 1]);
DIFF;
    $c = check_migration_writes(parse_diff($diff));
    expect($c)->toHaveCount(1)
        ->and($c[0]['file'])->toBe('database/migrations/2026_01_01_000000_x.php')
        ->and($c[0]['text'])->toContain('DB::table');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest -c skills/critique/checks/phpunit.xml`
Expected: FAIL — `Call to undefined function check_migration_writes()`.

- [ ] **Step 3: Write minimal implementation**

```php
// append to skills/critique/checks/checks.php

/** @return array<int, array{check: string, file: string, line: int, text: string}> */
function check_migration_writes(array $files): array
{
    $writeRe = '/\bDB::|->insert\(|->update\(|->delete\(|::create\(|::insert\(/';
    $candidates = [];
    foreach ($files as $f) {
        if (! preg_match('#^database/migrations/.*\.php$#', $f['file'])) {
            continue;
        }
        foreach ($f['added'] as $a) {
            if (preg_match($writeRe, $a['text'])) {
                $candidates[] = ['check' => 'migration-write', 'file' => $f['file'], 'line' => $a['line'], 'text' => trim($a['text'])];
            }
        }
    }

    return $candidates;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest -c skills/critique/checks/phpunit.xml`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add skills/critique/checks/checks.php skills/critique/checks/tests/ChecksTest.php
git commit -m "feat(critique): migration data-write heuristic"
```

---

### Task 6: Changelog fragment — heuristic question

**Files:**
- Modify: `skills/critique/checks/checks.php`
- Modify: `skills/critique/checks/tests/ChecksTest.php`

**Interfaces:**
- Produces: `check_changelog_fragment(array $files): array` → at most one `changelog-fragment` item carrying a `question`.

- [ ] **Step 1: Write the failing test**

```php
// append to skills/critique/checks/tests/ChecksTest.php
it('asks when code changed but no fragment added', function () {
    $codeOnly = <<<'DIFF'
+++ b/app/Foo.php
@@ -1,0 +1,1 @@
+// change
DIFF;
    expect(check_changelog_fragment(parse_diff($codeOnly)))->toHaveCount(1);

    $withFragment = <<<'DIFF'
+++ b/app/Foo.php
@@ -1,0 +1,1 @@
+// change
+++ b/.changelog/unreleased/feature-x.md
@@ -1,0 +1,1 @@
+<details>
DIFF;
    expect(check_changelog_fragment(parse_diff($withFragment)))->toHaveCount(0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest -c skills/critique/checks/phpunit.xml`
Expected: FAIL — `Call to undefined function check_changelog_fragment()`.

- [ ] **Step 3: Write minimal implementation**

```php
// append to skills/critique/checks/checks.php

/** @return array<int, array{check: string, question: string}> */
function check_changelog_fragment(array $files): array
{
    $hasCode = false;
    $hasFragment = false;
    foreach ($files as $f) {
        if (preg_match('/\.(php|js|vue)$/', $f['file']) && $f['added'] !== []) {
            $hasCode = true;
        }
        if (str_starts_with($f['file'], '.changelog/unreleased/') && str_ends_with($f['file'], '.md')) {
            $hasFragment = true;
        }
    }
    if ($hasCode && ! $hasFragment) {
        return [[
            'check' => 'changelog-fragment',
            'question' => 'No .changelog/unreleased/ fragment. Is this a user-visible change that needs one?',
        ]];
    }

    return [];
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest -c skills/critique/checks/phpunit.xml`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add skills/critique/checks/checks.php skills/critique/checks/tests/ChecksTest.php
git commit -m "feat(critique): changelog-fragment heuristic question"
```

---

### Task 7: Vendor-hack — exact filesystem check

**Files:**
- Create: `skills/critique/checks/vendor_hacks.php`
- Test: `skills/critique/checks/tests/VendorHacksTest.php`

**Interfaces:**
- Produces: `check_vendor_hacks(string $repoRoot): array` → `vendor-hack` items for files under `vendor/it4web/` newer than `vendor/composer/installed.json`.

- [ ] **Step 1: Write the failing test**

```php
// skills/critique/checks/tests/VendorHacksTest.php
<?php

it('finds it4web php files newer than installed.json', function () {
    $root = sys_get_temp_dir() . '/critique-vendor-' . uniqid();
    mkdir("$root/vendor/composer", 0777, true);
    mkdir("$root/vendor/it4web/tallui", 0777, true);
    file_put_contents("$root/vendor/composer/installed.json", '{}');
    touch("$root/vendor/composer/installed.json", time() - 60);
    file_put_contents("$root/vendor/it4web/tallui/Hacked.php", '<?php');

    $findings = check_vendor_hacks($root);
    expect($findings)->toHaveCount(1)
        ->and($findings[0]['file'])->toContain('Hacked.php')
        ->and($findings[0]['check'])->toBe('vendor-hack');
});

it('returns empty when vendor/it4web is absent', function () {
    $root = sys_get_temp_dir() . '/critique-vendor-' . uniqid();
    mkdir($root, 0777, true);
    expect(check_vendor_hacks($root))->toBe([]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest -c skills/critique/checks/phpunit.xml`
Expected: FAIL — `Call to undefined function check_vendor_hacks()`.

- [ ] **Step 3: Write minimal implementation**

```php
// skills/critique/checks/vendor_hacks.php
<?php

/**
 * Files under vendor/it4web/ modified after the last composer install —
 * the CLAUDE.md "vendor hack" check. Filesystem state, not the diff.
 *
 * @return array<int, array{check: string, file: string}>
 */
function check_vendor_hacks(string $repoRoot): array
{
    $marker = "$repoRoot/vendor/composer/installed.json";
    $dir = "$repoRoot/vendor/it4web";
    if (! is_file($marker) || ! is_dir($dir)) {
        return [];
    }
    $cmd = sprintf(
        'find %s -newer %s -name %s',
        escapeshellarg($dir),
        escapeshellarg($marker),
        escapeshellarg('*.php'),
    );
    $out = shell_exec($cmd) ?? '';
    $findings = [];
    foreach (array_filter(explode("\n", trim($out))) as $file) {
        $findings[] = ['check' => 'vendor-hack', 'file' => $file];
    }

    return $findings;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest -c skills/critique/checks/phpunit.xml`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add skills/critique/checks/vendor_hacks.php skills/critique/checks/tests/VendorHacksTest.php
git commit -m "feat(critique): vendor-hack filesystem check"
```

---

### Task 8: Dispatcher + CLI + candidate-cap guard

**Files:**
- Create: `skills/critique/checks/run-checks.php`
- Test: `skills/critique/checks/tests/RunChecksTest.php`

**Interfaces:**
- Consumes: every check from Tasks 2–7.
- Produces: `run_checks(string $diff, string $repoRoot): array` → `['exact' => [...], 'heuristic' => [...]]`. CLI: reads a diff on stdin, prints JSON.

- [ ] **Step 1: Write the failing test**

```php
// skills/critique/checks/tests/RunChecksTest.php
<?php

require_once __DIR__ . '/../run-checks.php';

$combined = <<<'DIFF'
+++ b/resources/views/x.blade.php
@@ -1,0 +1,1 @@
+@php $x = 1; @endphp
+++ b/app/Service.php
@@ -1,0 +1,1 @@
+$n = $user?->name;
DIFF;

it('routes exact vs heuristic findings', function () use ($combined) {
    $r = run_checks($combined, '/nonexistent-root');
    $exact = array_column($r['exact'], 'check');
    $heur = array_column($r['heuristic'], 'check');
    expect($exact)->toContain('blade-php')
        ->and($heur)->toContain('null-safe-op')
        ->and($heur)->toContain('changelog-fragment');
});

it('keeps every heuristic check at or below 5 candidates', function () use ($combined) {
    $r = run_checks($combined, '/nonexistent-root');
    $byCheck = array_count_values(array_column($r['heuristic'], 'check'));
    foreach ($byCheck as $check => $n) {
        expect($n)->toBeLessThanOrEqual(5, "$check returned $n candidates (>5 relocates noise)");
    }
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest -c skills/critique/checks/phpunit.xml`
Expected: FAIL — `Call to undefined function run_checks()`.

- [ ] **Step 3: Write minimal implementation**

```php
// skills/critique/checks/run-checks.php
<?php

require_once __DIR__ . '/diff_parse.php';
require_once __DIR__ . '/checks.php';
require_once __DIR__ . '/vendor_hacks.php';

/** @return array{exact: array, heuristic: array} */
function run_checks(string $diff, string $repoRoot): array
{
    $files = parse_diff($diff);

    return [
        'exact' => [
            ...check_blade_php($files),
            ...check_vendor_hacks($repoRoot),
        ],
        'heuristic' => [
            ...check_changelog_fragment($files),
            ...check_null_safety($files),
            ...check_each_on_builder($files),
            ...check_migration_writes($files),
        ],
    ];
}

// CLI: `git diff origin/main...HEAD | php run-checks.php`
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $diff = stream_get_contents(STDIN) ?: '';
    echo json_encode(run_checks($diff, getcwd()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest -c skills/critique/checks/phpunit.xml`
Expected: PASS (2 tests).

- [ ] **Step 5: Run the whole Phase A suite**

Run: `./vendor/bin/pest -c skills/critique/checks/phpunit.xml`
Expected: PASS — every check test green.

- [ ] **Step 6: Commit**

```bash
git add skills/critique/checks/run-checks.php skills/critique/checks/tests/RunChecksTest.php
git commit -m "feat(critique): stage-0 dispatcher, CLI, and candidate-cap guard"
```

---

## Phase B — Prose skill (writing-skills)

### Task 9: `references/rubrics.md`

**Files:**
- Create: `skills/critique/references/rubrics.md`

**Interfaces:**
- Consumes: nothing executable; read by the SKILL.md pipeline.
- Produces: the four mode rubrics (hazard classes + principles pointer), the category-slug list (including the stage-0 slugs `blade-php`, `vendor-hack`, `null-safe-op`, `null-coalesce`, `each-on-builder`, `migration-write`, `changelog-fragment`), and a drop-tally block.

- [ ] **Step 1: Invoke the authoring skill**

Announce and invoke `superpowers:writing-skills`. It governs the format of this reference file and the SKILL.md in Task 10.

- [ ] **Step 2: Write `rubrics.md`** transcribing, verbatim in structure, the spec's "Rubric structure" and "Modes" sections:
  - `pr` hazard classes: database / in flight / inside the app / outward-facing, each requiring a *hazard found / checked, clean / not applicable* verdict, reported as **conditional** hazards; package-repo weighting via `composer.json` `name` match.
  - `plan` questions (always vs plan-present subsets).
  - `missing` rules, including the **cite ≥2 in-repo paths** evidence requirement.
  - `alternatives` axis assignment and the distinctness pass.
  - Principles layer as a **pointer** to `~/GitProjects/LaravelClaudeMd/LaravelClaudeMd/CLAUDE.md`, not a copy.
  - Tier definitions: **Tier 3 = subjective preference with no named consequence**; a house-rule violation with a consequence is Tier 2.
  - A `## Drop tally` block: a `category-slug | drops` table, with the rule "at 3 drops in a slug, propose a scoping amendment; approval required; never auto-apply".

- [ ] **Step 3: Commit**

```bash
git add skills/critique/references/rubrics.md
git commit -m "feat(critique): rubrics reference — modes, tiers, drop tally"
```

---

### Task 10: `SKILL.md`

**Files:**
- Create: `skills/critique/SKILL.md`

**Interfaces:**
- Consumes: `checks/run-checks.php` (Task 8), `references/rubrics.md` (Task 9).
- Produces: the skill entry point.

- [ ] **Step 1: Write the frontmatter and triggers** (via `writing-skills`)

```markdown
---
name: critique
description: Use when reviewing a design/plan before implementation or a change before merge — the plan, pr, alternatives, and missing review modes. Triggers on "/critique", "review this plan", "review this PR", "critique the design", "look for missing cases".
---
```

- [ ] **Step 2: Write the invocation + mode-inference section**, exactly matching the spec: the five forms, the `--verify` flag, and the inference order (spec-newer-than-HEAD → `plan`; any change vs `origin/main` incl. uncommitted → `pr`; else ask). State that the chosen mode is announced first.

- [ ] **Step 3: Write the reviewer contract** — crafted context (never session history) and read-only (never move `HEAD`; `git worktree add` for other revisions).

- [ ] **Step 4: Write the six-stage pipeline**, wiring the pieces:
  - **Stage 0:** assemble the target diff (`git diff origin/main...HEAD` + uncommitted, or `gh pr diff <n>`), pipe it to `php skills/critique/checks/run-checks.php`, fold `exact` into the report and `heuristic` candidates into the Stage-2 prompt. State the assembled target ("N files, M uncommitted").
  - **Stages 1–5** as specified: assemble context, single Fable reviewer (model configurable) over the mode rubric, filter (drop no-scenario + Tier 3, cap 15, disclose remainder by slug), report table (claim ≤60 chars, tier, verdict *pr-only*, category, location, failure scenario) + overall verdict, triage dispositions pointing at `receiving-code-review`.
  - **Stage 6:** on a drop, increment the `rubrics.md` tally; at 3 in a slug, propose an amendment (approval required).

- [ ] **Step 5: Write the four mode sections** as thin pointers into `references/rubrics.md` (do not duplicate the rubric text).

- [ ] **Step 6: Commit**

```bash
git add skills/critique/SKILL.md
git commit -m "feat(critique): SKILL.md — pipeline, modes, reviewer contract"
```

---

### Task 11: Wire up, verify triggering, and smoke-test

**Files:**
- Create: symlink `~/.claude/skills/critique` → `skills/critique/`

- [ ] **Step 1: Symlink the skill** (matches the multi-repo setup in CLAUDE.md)

```bash
ln -sfn ~/GitProjects/LaravelClaudeMd/LaravelClaudeMd/skills/critique ~/.claude/skills/critique
ls -l ~/.claude/skills/critique
```

Expected: symlink resolves to the repo skill dir.

- [ ] **Step 2: Verify the full Phase A suite still passes**

Run: `cd ~/GitProjects/LaravelClaudeMd/LaravelClaudeMd && ./vendor/bin/pest -c skills/critique/checks/phpunit.xml`
Expected: PASS — every check test green.

- [ ] **Step 3: `writing-skills` trigger check** — confirm the description/triggers fire on the intended phrasings and not on unrelated ones, per the `writing-skills` verification guidance.

- [ ] **Step 4: Smoke-test `plan` mode on this very spec** (the spec's own validation item)

In a fresh session: `/critique plan docs/superpowers/specs/2026-07-23-critique-review-skill-design.md`
Expected: it announces `plan` mode, states which artifacts it found (design only), and surfaces ≥1 genuine unverified assumption, with a signal ratio (kept ÷ reported) above 60%. Record the result in the PR description; **do not** post it anywhere on GitHub.

- [ ] **Step 5: Smoke-test the stage-0 CLI on a real diff**

```bash
git diff origin/main...HEAD | php skills/critique/checks/run-checks.php
```

Expected: valid JSON `{ "exact": [...], "heuristic": [...] }`; no heuristic check exceeds 5 candidates.

- [ ] **Step 6: Commit any fixes** surfaced by verification, then stop for review.

```bash
git add -A && git commit -m "chore(critique): wire up skill and record smoke-test results"
```

---

## Self-Review

**1. Spec coverage.** Every spec section maps to a task:
- Stage-0 exact/heuristic checks → Tasks 2–8 (the two cut checks, `if (!$x)` and hand-rolled-markup, are correctly absent — they live in the principles layer, Task 9).
- Modes, hazard classes, tiers, drop tally → Task 9.
- Invocation, inference, pipeline stages 1–6, reviewer contract, verdict format → Task 10.
- Whole-change target incl. uncommitted → Task 10 Stage 0 + Task 11 Step 5.
- Validation items → Task 11 (trigger check, plan smoke-test) + Task 8 (candidate-cap test). Items needing several real targets (signal ratio over ten runs, fan-out A/B, already-merged-PR runs) are **post-merge validation**, not build tasks — flagged so they are not forgotten.
- `--verify`, package weighting, `alternatives`/`missing` details → prose in Tasks 9–10; no executable component, so no dedicated task.

**2. Placeholder scan.** No "TBD"/"handle appropriately". Phase A steps carry complete PHP; Phase B steps are prose authored via `writing-skills` and enumerate their required content rather than deferring it.

**3. Type consistency.** Every check returns `['check' => , 'file'? => , 'line'? => , 'text'?|'question'? => ]`; `run_checks` returns `['exact' => , 'heuristic' => ]`; `parse_diff` returns `[['file' => , 'added' => [['line' => , 'text' => ]]]]`. Names are identical across Tasks 1–8 and referenced unchanged in Task 10.

**Known limitation carried forward:** the signal-ratio, fan-out-A/B, and merged-PR-replay validations require real review runs and live in the spec's validation section as post-merge acceptance, not in this build plan.
