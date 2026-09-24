# Pipeline proportional design (`light`) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the pipeline's design leg proportional: Bounded or Architectural, chosen by a human, with a one-way escalation that grows the plan. Also reuse a green suite per tree, keep review fixes in the engine, and write down what a leg brief consists of.

**Architecture:**
- **Code:** the pipeline stays one chain. New behaviour lives in small, tested PHP functions under `skills/pipeline/checks/`:
  - a `DesignSize` enum read from the committed spec;
  - `pipeline_code_lines()` and a stricter auth match in `triggers.php`;
  - `pipeline_done_legs()`;
  - a `suite.php` for tree-keyed suite reuse.
- **Engine procedure:** the prose in `references/engine.md`, `gates.md`, `manifest.md` and `SKILL.md` is updated to call them.
- **Unchanged:** legs, gates, navigation and reconstruction.

**Tech Stack:** PHP 8.4, Pest 4 (repo-root `composer.json`), git CLI via `proc_open` argv arrays, Markdown.

**Spec:** `docs/superpowers/specs/2026-09-14-pipeline-light-design.md`. Read it first; every task argues from it.

## Global Constraints

- **Design size:**
  - Read only from the committed spec header, exactly `**Design size:** Bounded`.
  - An absent, mangled or other value means `Architectural` (fail strict).
- **Escalation threshold:** more than **100** code lines, counting **added + deleted** lines outside `tests/`, `docs/`, `.changelog/`, `*.md`, `composer.lock`, `package-lock.json`, `yarn.lock` and `pnpm-lock.yaml`.
- **Escalating triggers (Bounded only):** `migration` and `auth`. `package` never escalates; it annotates.
- **Package repos:** Bounded is refused in a repo whose `composer.json` `name` starts with `it4web/`.
- **Must not change:** `pipeline_legs`, `pipeline_gate_legs`, `pipeline_next_leg`, `pipeline_can_navigate` (including its pinned signature test), `manifest_infer_cursor`, `manifest_validate`, and every existing test assertion.
- **Other repos are off limits:** no edits to `handoff`, `work-on` (both in DevOps-Claude-Config), `brainstorming` (plugin) or `browser-verification`.
- **Models:** no model changes anywhere.
- **Tests:** run on the host from the worktree root.
  - Pipeline: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
  - Critique: `./vendor/bin/pest -c skills/critique/checks/phpunit.xml --test-directory=skills/critique/checks/tests`
- **Vendor:** `vendor/` comes from `composer install` **inside the worktree**. Never symlink it to the primary checkout; Pest would bootstrap the primary checkout's code.
- **Commits:** stage explicit paths only, never `git add -A`. Messages carry no co-author and no AI attribution.
- **Written output:** nothing written into docs, commits or PRs addresses a person.

---

### Task 1: `parse_diff` counts removed lines and keeps the old path

**Files:**
- Modify: `skills/critique/checks/diff_parse.php` (whole function)
- Test: `skills/critique/checks/tests/DiffParseTest.php` (append)

**Interfaces:**
- Consumes: nothing.
- Produces: `parse_diff(string $diff): array`. Each file entry is now `array{file: string, old: string, added: list<array{line: int, text: string}>, removed: int}`. `old` is the `--- a/` path, or `file` when the diff has no `---` line; for a deleted file `file` is `/dev/null` and `old` is the real path.

- [ ] **Step 1: Install dependencies in the worktree and confirm the suites are green before changing anything**

Run:
```bash
composer install --no-interaction --quiet
./vendor/bin/pest -c skills/critique/checks/phpunit.xml --test-directory=skills/critique/checks/tests
./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests
```
Expected: both suites PASS. If either fails before any change, stop and report. That is a machinery failure, not this task's.

- [ ] **Step 2: Write the failing tests**

Append to `skills/critique/checks/tests/DiffParseTest.php`:

```php
it('counts removed lines per file and keeps the old path', function () use ($sample) {
    $files = parse_diff($sample);
    expect($files[0]['removed'])->toBe(1);
    expect($files[0]['old'])->toBe('app/Foo.php');
    expect($files[1]['removed'])->toBe(0);
});

it('keeps the old path of a deleted file, whose new path is /dev/null', function () {
    $deleted = <<<'DIFF'
diff --git a/tests/Feature/OldTest.php b/tests/Feature/OldTest.php
deleted file mode 100644
--- a/tests/Feature/OldTest.php
+++ /dev/null
@@ -1,2 +0,0 @@
-<?php
-// gone
DIFF;
    $files = parse_diff($deleted);
    expect($files)->toHaveCount(1);
    expect($files[0]['file'])->toBe('/dev/null');
    expect($files[0]['old'])->toBe('tests/Feature/OldTest.php');
    expect($files[0]['removed'])->toBe(2);
    expect($files[0]['added'])->toBe([]);
});

it('falls back to the new path when a diff has no old-path line', function () {
    $files = parse_diff("+++ b/app/Foo.php\n@@ -1,0 +1,1 @@\n+x\n");
    expect($files[0]['old'])->toBe('app/Foo.php');
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `./vendor/bin/pest -c skills/critique/checks/phpunit.xml --test-directory=skills/critique/checks/tests --filter="removed lines|deleted file|old-path"`
Expected: FAIL with `Undefined array key "removed"` / `Undefined array key "old"`.

- [ ] **Step 4: Implement**

Replace the whole of `skills/critique/checks/diff_parse.php` with:

```php
<?php

/**
 * Parse a unified diff into per-file added lines and a removed-line count.
 * `line` is the line number in the NEW file; `text` drops the leading '+'.
 * `old` is the pre-change path — the only real path of a deleted file, whose `file` is /dev/null.
 *
 * @return array<int, array{file: string, old: string, added: array<int, array{line: int, text: string}>, removed: int}>
 */
function parse_diff(string $diff): array
{
    $files = [];
    $idx = -1;
    $newLine = 0;
    $oldPath = null;

    foreach (explode("\n", $diff) as $raw) {
        if (str_starts_with($raw, '--- ')) {
            $oldPath = preg_replace('#^a/#', '', trim(substr($raw, 4)));
            continue;
        }
        if (str_starts_with($raw, '+++ ')) {
            $path = preg_replace('#^b/#', '', trim(substr($raw, 4)));
            $files[] = ['file' => $path, 'old' => $oldPath ?? $path, 'added' => [], 'removed' => 0];
            $idx = count($files) - 1;
            $oldPath = null;
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
            $files[$idx]['removed']++; // does not advance the new-file counter
        } else {
            $newLine++; // context line
        }
    }

    return $files;
}
```

- [ ] **Step 5: Run both suites to verify everything passes**

Run:
```bash
./vendor/bin/pest -c skills/critique/checks/phpunit.xml --test-directory=skills/critique/checks/tests
./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests
```
Expected: both PASS. The pipeline suite reuses `parse_diff` through `triggers.php`.

- [ ] **Step 6: Commit**

```bash
git add skills/critique/checks/diff_parse.php skills/critique/checks/tests/DiffParseTest.php
git commit -m "feat(critique): parse_diff counts removed lines and keeps the old path"
```

---

### Task 2: `pipeline_code_lines()` and an auth match that ignores comments

**Files:**
- Modify: `skills/pipeline/checks/triggers.php`
- Test: `skills/pipeline/checks/tests/TriggersTest.php` (append)

**Interfaces:**
- Consumes: `parse_diff()` from Task 1 (`file`, `old`, `added`, `removed`).
- Produces:
  - `pipeline_code_lines(string $diff): int`
  - `pipeline_triggers()`, same signature and return shape, whose `auth` no longer fires on comment or docblock lines.

- [ ] **Step 1: Write the failing tests**

Append to `skills/pipeline/checks/tests/TriggersTest.php`:

```php
it('counts added plus removed code lines, skipping tests, docs, markdown, changelog and lockfiles', function () {
    $diff = <<<'DIFF'
--- a/code/www/app/Models/Order.php
+++ b/code/www/app/Models/Order.php
@@ -1,2 +1,2 @@
-old
+new
--- a/code/www/tests/Feature/OrderTest.php
+++ b/code/www/tests/Feature/OrderTest.php
@@ -1,0 +1,1 @@
+test
--- a/docs/superpowers/plans/x.md
+++ b/docs/superpowers/plans/x.md
@@ -1,0 +1,1 @@
+doc
--- a/.changelog/unreleased/feature-x.md
+++ b/.changelog/unreleased/feature-x.md
@@ -1,0 +1,1 @@
+entry
--- a/code/www/composer.lock
+++ b/code/www/composer.lock
@@ -1,1 +1,1 @@
-"a"
+"b"
--- a/README.md
+++ b/README.md
@@ -1,0 +1,1 @@
+readme
DIFF;
    expect(pipeline_code_lines($diff))->toBe(2);
});

it('judges a deleted file by its old path', function () {
    $deletedCode = "--- a/app/Legacy.php\n+++ /dev/null\n@@ -1,3 +0,0 @@\n-a\n-b\n-c\n";
    $deletedTest = "--- a/tests/Feature/LegacyTest.php\n+++ /dev/null\n@@ -1,2 +0,0 @@\n-a\n-b\n";
    expect(pipeline_code_lines($deletedCode))->toBe(3);
    expect(pipeline_code_lines($deletedTest))->toBe(0);
    expect(pipeline_code_lines(''))->toBe(0);
});

it('ignores comment and docblock lines when detecting authorization', function () {
    // On a Bounded design `auth` escalates the run, so a comment must not trip it.
    $comments = <<<'DIFF'
+++ b/app/Http/Controllers/OrderController.php
@@ -1,0 +1,5 @@
+    /**
+     * Authorised upstream, see Gate::allows in the middleware.
+     */
+    // later: $this->authorize('update', $order);
+    # ->can('view') is checked by the route
DIFF;
    expect(pipeline_triggers($comments)['auth'])->toBeFalse();

    // a PHP attribute starts with `#[` and is code, not a comment
    $attribute = "+++ b/app/Http/Controllers/OrderController.php\n@@ -1,0 +1,1 @@\n+#[Middleware('can:update,order')]\n";
    expect(pipeline_triggers($attribute)['auth'])->toBeTrue();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter="code lines|deleted file by its old path|comment and docblock"`
Expected: FAIL with `Call to undefined function pipeline_code_lines()`, and the comments case FAIL with `Failed asserting that true is false`.

- [ ] **Step 3: Implement the comment filter**

In `skills/pipeline/checks/triggers.php`, add below the `$authRe = …;` line:

```php
    // Comment and docblock lines are prose, not authorization. `#[` is a PHP attribute — code.
    $commentRe   = '#^\s*(?://|\#(?!\[)|/?\*)#';
```

and replace

```php
            if (preg_match($authRe, $a['text'])) {
```

with

```php
            if (preg_match($authRe, $a['text']) && ! preg_match($commentRe, $a['text'])) {
```

- [ ] **Step 4: Implement `pipeline_code_lines()`**

Append to `skills/pipeline/checks/triggers.php`:

```php

/**
 * Lines of code a change touches — added plus removed — outside tests, docs, markdown,
 * changelog fragments and lockfiles. The size a Bounded design may reach before it must grow
 * (`../references/engine.md` §Design size). A deleted file is judged by its old path.
 */
function pipeline_code_lines(string $diff): int
{
    $notCode = '#(?:^|/)(?:tests|docs|\.changelog)/|\.md$|(?:^|/)(?:composer\.lock|package-lock\.json|yarn\.lock|pnpm-lock\.yaml)$#';

    $lines = 0;
    foreach (parse_diff($diff) as $f) {
        $path = $f['file'] === '/dev/null' ? $f['old'] : $f['file'];
        if (! preg_match($notCode, $path)) {
            $lines += count($f['added']) + $f['removed'];
        }
    }

    return $lines;
}
```

- [ ] **Step 5: Run the pipeline suite to verify it passes**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, including every pre-existing `TriggersTest` case.

- [ ] **Step 6: Commit**

```bash
git add skills/pipeline/checks/triggers.php skills/pipeline/checks/tests/TriggersTest.php
git commit -m "feat(pipeline): pipeline_code_lines and an auth match that ignores comments"
```

---

### Task 3: `DesignSize` enum

**Files:**
- Create: `skills/pipeline/checks/design_size.php`
- Modify: `skills/pipeline/checks/tests/Pest.php` (load list)
- Test: `skills/pipeline/checks/tests/DesignSizeTest.php` (create)

**Interfaces:**
- Consumes: the `pipeline_triggers()` result shape `array{package: bool, migration: bool, auth: bool, ui: bool}`, and an `int` from `pipeline_code_lines()` (Task 2).
- Produces:
  - `enum DesignSize: string { case Bounded = 'Bounded'; case Architectural = 'Architectural'; }`
  - `DesignSize::MAX_CODE_LINES = 100`
  - `DesignSize::fromSpec(string $markdown): DesignSize`
  - `DesignSize->escalation(array $triggers, int $codeLines): ?string`, returning `'migration'`, `'auth'`, `'code-lines: <n> > 100'` or `null`.

- [ ] **Step 1: Add the file to the test loader**

In `skills/pipeline/checks/tests/Pest.php`, replace

```php
foreach (['triggers.php', 'pipeline.php', 'manifest.php', 'checks.php', 'proof.php', 'proof_render.php'] as $f) {
```

with

```php
foreach (['triggers.php', 'pipeline.php', 'manifest.php', 'checks.php', 'proof.php', 'proof_render.php', 'design_size.php', 'suite.php'] as $f) {
```

- [ ] **Step 2: Write the failing tests**

Create `skills/pipeline/checks/tests/DesignSizeTest.php`:

```php
<?php

$none = ['package' => false, 'migration' => false, 'auth' => false, 'ui' => false];

it('reads the design size from the spec header and fails strict', function () {
    expect(DesignSize::fromSpec("# Title\n\n**Design size:** Bounded\n\nBody"))->toBe(DesignSize::Bounded);
    expect(DesignSize::fromSpec("# Title\n\n**Design size:** Architectural\n"))->toBe(DesignSize::Architectural);
    // every spec written before the header existed keeps the full chain
    expect(DesignSize::fromSpec("# Title\n\nNo header.\n"))->toBe(DesignSize::Architectural);
    // mangled values are not a quiet way into Bounded
    expect(DesignSize::fromSpec("**Design size:** bounded\n"))->toBe(DesignSize::Architectural);
    expect(DesignSize::fromSpec("**Design size:** Bounded-ish\n"))->toBe(DesignSize::Architectural);
    expect(DesignSize::fromSpec("Prose mentioning **Design size:** Bounded mid-line.\n"))->toBe(DesignSize::Architectural);
});

it('escalates a bounded design on a migration, an authorization change, or more than 100 code lines', function () use ($none) {
    expect(DesignSize::Bounded->escalation($none, 100))->toBeNull();
    expect(DesignSize::Bounded->escalation($none, 101))->toBe('code-lines: 101 > 100');
    expect(DesignSize::Bounded->escalation([...$none, 'migration' => true], 5))->toBe('migration');
    expect(DesignSize::Bounded->escalation([...$none, 'auth' => true], 5))->toBe('auth');
});

it('does not escalate a bounded design on a package bump or a UI change alone', function () use ($none) {
    expect(DesignSize::Bounded->escalation([...$none, 'package' => true, 'ui' => true], 5))->toBeNull();
});

it('never escalates an architectural design', function () use ($none) {
    expect(DesignSize::Architectural->escalation([...$none, 'migration' => true, 'auth' => true], 5000))->toBeNull();
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter="design size|bounded design|architectural design"`
Expected: FAIL with `Class "DesignSize" not found`.

- [ ] **Step 4: Implement**

Create `skills/pipeline/checks/design_size.php`:

```php
<?php

/**
 * How much design a change gets (`../references/engine.md` §Design size). Read from the committed
 * spec, never stored: only the exact `**Design size:** Bounded` header line is Bounded, so every
 * spec written before the header existed — and every mangled header — keeps the Architectural chain.
 */
enum DesignSize: string
{
    case Bounded = 'Bounded';
    case Architectural = 'Architectural';

    public const MAX_CODE_LINES = 100;

    public static function fromSpec(string $markdown): self
    {
        if (! preg_match('/^\*\*Design size:\*\*\s*(\S+)\s*$/m', $markdown, $match)) {
            return self::Architectural;
        }

        return self::tryFrom($match[1]) ?? self::Architectural;
    }

    /** Why this design must grow, or null while it may stay as it is. */
    public function escalation(array $triggers, int $codeLines): ?string
    {
        return match ($this) {
            self::Architectural => null,
            self::Bounded => match (true) {
                ! empty($triggers['migration']) => 'migration',
                ! empty($triggers['auth']) => 'auth',
                $codeLines > self::MAX_CODE_LINES => "code-lines: {$codeLines} > " . self::MAX_CODE_LINES,
                default => null,
            },
        };
    }
}
```

- [ ] **Step 5: Run the pipeline suite to verify it passes**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS. `suite.php` does not exist yet; the loader skips missing files.

- [ ] **Step 6: Commit**

```bash
git add skills/pipeline/checks/design_size.php skills/pipeline/checks/tests/DesignSizeTest.php skills/pipeline/checks/tests/Pest.php
git commit -m "feat(pipeline): DesignSize enum — read from the spec, escalates a Bounded design"
```

---

### Task 4: `pipeline_done_legs()` — gates count again after an escalation

**Files:**
- Modify: `skills/pipeline/checks/pipeline.php` (append)
- Test: `skills/pipeline/checks/tests/DoneLegsTest.php` (create)

**Interfaces:**
- Consumes: `gate_ledger` entries as documented in `references/manifest.md`: `gate` (`plan-approval` | `pr-review` | `verify-ui` | `design-size`), `at` (ISO-8601 UTC, `Z` suffix), `outcome` (`continued` | `looped-back` | `halted` | `escalated`). A `verify-ui` entry has no `leg`.
- Produces: `pipeline_done_legs(array $ledger): array`, a list of gate leg names (`review-plan`, `review-pr`, `verify-ui`) in first-pass order. This is the `$doneLegs` argument of the unchanged `pipeline_can_navigate()`.

- [ ] **Step 1: Write the failing tests**

Create `skills/pipeline/checks/tests/DoneLegsTest.php`:

```php
<?php

$uiOff = ['package' => false, 'migration' => false, 'auth' => false, 'ui' => false];

it('derives the done gate legs from continued ledger entries', function () {
    $ledger = [
        ['gate' => 'plan-approval', 'leg' => 'review-plan', 'at' => '2026-09-14T10:00:00Z', 'outcome' => 'looped-back'],
        ['gate' => 'plan-approval', 'leg' => 'review-plan', 'at' => '2026-09-14T10:20:00Z', 'outcome' => 'continued'],
        ['gate' => 'verify-ui', 'cycle' => 1, 'at' => '2026-09-14T11:00:00Z', 'outcome' => 'continued'],
    ];
    expect(pipeline_done_legs($ledger))->toBe(['review-plan', 'verify-ui']);
    expect(pipeline_done_legs([]))->toBe([]);
});

it('stops counting gates that passed before a design escalation', function () use ($uiOff) {
    $ledger = [
        ['gate' => 'plan-approval', 'leg' => 'review-plan', 'at' => '2026-09-14T10:00:00Z', 'outcome' => 'continued'],
        ['gate' => 'design-size', 'leg' => 'implement', 'at' => '2026-09-14T10:40:00Z', 'reason' => 'migration', 'outcome' => 'escalated'],
    ];
    expect(pipeline_done_legs($ledger))->toBe([]);
    // the grown plan has not been re-reviewed, so implement is out of reach again
    expect(pipeline_can_navigate('design', 'implement', pipeline_done_legs($ledger), $uiOff))->toBeFalse();

    $reReviewed = [...$ledger, ['gate' => 'plan-approval', 'leg' => 'review-plan', 'at' => '2026-09-14T11:00:00Z', 'outcome' => 'continued']];
    expect(pipeline_done_legs($reReviewed))->toBe(['review-plan']);
    expect(pipeline_can_navigate('review-plan', 'implement', pipeline_done_legs($reReviewed), $uiOff))->toBeTrue();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter="done gate legs|design escalation"`
Expected: FAIL with `Call to undefined function pipeline_done_legs()`.

- [ ] **Step 3: Implement**

Append to `skills/pipeline/checks/pipeline.php`:

```php

/**
 * The gate legs that have run, as `pipeline_can_navigate`'s `$doneLegs`. A gate counts once it has
 * a `continued` entry — but only one newer than the latest `design-size` escalation: a Bounded
 * design that grew is a different plan, and the pass over the small one must not let navigation
 * skip the re-review (`../references/engine.md` §Design size).
 */
function pipeline_done_legs(array $ledger): array
{
    $legOf = ['plan-approval' => 'review-plan', 'pr-review' => 'review-pr', 'verify-ui' => 'verify-ui'];

    $escalatedAt = array_column(
        array_filter($ledger, fn (array $entry) => ($entry['outcome'] ?? null) === 'escalated'),
        'at',
    );
    $since = $escalatedAt === [] ? '' : max($escalatedAt);

    $done = [];
    foreach ($ledger as $entry) {
        $leg = $legOf[$entry['gate'] ?? ''] ?? null;
        if ($leg !== null && ($entry['outcome'] ?? null) === 'continued' && ($entry['at'] ?? '') > $since) {
            $done[] = $leg;
        }
    }

    return array_values(array_unique($done));
}
```

- [ ] **Step 4: Run the pipeline suite to verify it passes**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, including `makes the un-skippable-review promise depend only on which legs have run` with its pinned signature, unchanged.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/pipeline.php skills/pipeline/checks/tests/DoneLegsTest.php
git commit -m "feat(pipeline): pipeline_done_legs — gate passes before an escalation stop counting"
```

---

### Task 5: Suite reuse — once per tree (code and its docs)

**Files:**
- Create: `skills/pipeline/checks/suite.php`
- Test: `skills/pipeline/checks/tests/SuiteTest.php` (create)
- Modify: `skills/pipeline/references/engine.md` (§Kickoff, §Mechanical checks, new §Suite reuse)
- Modify: `skills/pipeline/references/manifest.md` (§Fields, §Two rules)

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces:
  - `pipeline_git_run(string $worktree, array $args, array $env = []): array{0: int, 1: string, 2: string}`: exit code, trimmed stdout, trimmed stderr; runs without a shell.
  - `pipeline_git(string $worktree, array $args, array $env = []): string`: trimmed stdout; throws `RuntimeException` on a non-zero exit.
  - `pipeline_git_path(string $worktree, string $path): string`: makes a git-printed path absolute.
  - `pipeline_tree_key(string $worktree): string`: tree SHA of the working tree's content, untracked non-ignored files included; the real index is untouched.
  - `pipeline_exclude_manifest(string $worktree): void`: idempotently adds `.claude/pipeline/` to the repo's shared `info/exclude`.
  - `pipeline_suite_needed(?array $last, string $tree): bool`

- [ ] **Step 1: Write the failing tests**

Create `skills/pipeline/checks/tests/SuiteTest.php`:

```php
<?php

/** A throwaway repo with one commit, so no test ever touches a real checkout. */
function suite_repo(): string
{
    $dir = sys_get_temp_dir() . '/pipeline-suite-' . uniqid();
    mkdir($dir);
    foreach ([['init', '-q'], ['config', 'user.email', 'test@example.com'], ['config', 'user.name', 'Test'], ['config', 'commit.gpgsign', 'false']] as $args) {
        pipeline_git($dir, $args);
    }
    file_put_contents($dir . '/app.php', "<?php\n");
    pipeline_git($dir, ['add', 'app.php']);
    pipeline_git($dir, ['commit', '-qm', 'init']);

    return $dir;
}

it('reruns the suite unless this exact tree already went green', function () {
    expect(pipeline_suite_needed(null, 'abc'))->toBeTrue();
    expect(pipeline_suite_needed(['tree' => 'abc', 'outcome' => 'green'], 'abc'))->toBeFalse();
    expect(pipeline_suite_needed(['tree' => 'abc', 'outcome' => 'red'], 'abc'))->toBeTrue();
    expect(pipeline_suite_needed(['tree' => 'abc', 'outcome' => 'green'], 'def'))->toBeTrue();
});

it('keys a clean tree by its HEAD tree', function () {
    $repo = suite_repo();
    expect(pipeline_tree_key($repo))->toBe(pipeline_git($repo, ['rev-parse', 'HEAD^{tree}']));
});

it('changes the key for an untracked file without touching the real index', function () {
    $repo = suite_repo();
    $clean = pipeline_tree_key($repo);

    file_put_contents($repo . '/new.php', "<?php\n");
    expect(pipeline_tree_key($repo))->not->toBe($clean);
    expect(pipeline_git($repo, ['diff', '--cached', '--name-only']))->toBe('');
});

it('keeps the key when the tested content is committed', function () {
    $repo = suite_repo();
    file_put_contents($repo . '/new.php', "<?php\n");
    $tested = pipeline_tree_key($repo);

    pipeline_git($repo, ['add', 'new.php']);
    pipeline_git($repo, ['commit', '-qm', 'add new']);
    expect(pipeline_tree_key($repo))->toBe($tested);
});

it('keeps the manifest out of the key once it is excluded, and excludes it once', function () {
    $repo = suite_repo();
    pipeline_exclude_manifest($repo);
    pipeline_exclude_manifest($repo);
    $before = pipeline_tree_key($repo);

    mkdir($repo . '/.claude/pipeline', 0777, true);
    file_put_contents($repo . '/.claude/pipeline/feature-x.json', '{"suite":{}}');
    expect(pipeline_tree_key($repo))->toBe($before);
    expect(substr_count(file_get_contents($repo . '/.git/info/exclude'), '.claude/pipeline/'))->toBe(1);
});

it('works from a linked worktree, whose index and exclude live elsewhere', function () {
    $repo = suite_repo();
    $worktree = $repo . '-wt';
    pipeline_git($repo, ['worktree', 'add', '--quiet', '-b', 'feature/x', $worktree]);
    pipeline_exclude_manifest($worktree);

    mkdir($worktree . '/.claude/pipeline', 0777, true);
    file_put_contents($worktree . '/.claude/pipeline/feature-x.json', '{}');
    expect(pipeline_tree_key($worktree))->toBe(pipeline_git($worktree, ['rev-parse', 'HEAD^{tree}']));
});

it('fails loudly when git fails, so a key is never guessed', function () {
    expect(fn () => pipeline_tree_key(sys_get_temp_dir() . '/not-a-repo-' . uniqid()))->toThrow(RuntimeException::class);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter="suite|tree|manifest out of the key|linked worktree|fails loudly"`
Expected: FAIL with `Call to undefined function pipeline_suite_needed()` / `pipeline_git()`.

- [ ] **Step 3: Implement**

Create `skills/pipeline/checks/suite.php`:

```php
<?php

/**
 * Suite reuse — once per tree (`../references/engine.md` §Suite reuse). A green full suite is
 * reused while the working tree's *content* is unchanged; committing already-tested content keeps
 * the key, because the key is a tree, not a commit.
 */

/** Run git in $worktree without a shell. @return array{0: int, 1: string, 2: string} */
function pipeline_git_run(string $worktree, array $args, array $env = []): array
{
    $process = proc_open(
        ['git', '-C', $worktree, ...$args],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        $env === [] ? null : [...getenv(), ...$env],
    );
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), trim($out), trim($err)];
}

/** Run git and fail loudly: a key that cannot be computed is a machinery failure, never a reuse. */
function pipeline_git(string $worktree, array $args, array $env = []): string
{
    [$code, $out, $err] = pipeline_git_run($worktree, $args, $env);
    if ($code !== 0) {
        throw new RuntimeException('git ' . implode(' ', $args) . " failed ({$code}): {$err}");
    }

    return $out;
}

/** git prints some paths relative to the worktree it ran in. */
function pipeline_git_path(string $worktree, string $path): string
{
    return str_starts_with($path, '/') ? $path : rtrim($worktree, '/') . '/' . $path;
}

/** The tree the working copy would commit right now — untracked, non-ignored files included. */
function pipeline_tree_key(string $worktree): string
{
    $index = sys_get_temp_dir() . '/pipeline-index-' . uniqid();
    $realIndex = pipeline_git_path($worktree, pipeline_git($worktree, ['rev-parse', '--git-path', 'index']));
    if (is_file($realIndex)) {
        copy($realIndex, $index);
    }

    try {
        pipeline_git($worktree, ['add', '-A'], ['GIT_INDEX_FILE' => $index]);

        return pipeline_git($worktree, ['write-tree'], ['GIT_INDEX_FILE' => $index]);
    } finally {
        if (is_file($index)) {
            unlink($index);
        }
    }
}

/**
 * Keep `.claude/pipeline/` out of git in repos that do not ignore `.claude/`. Local (`info/exclude`
 * is never pushed), shared by every worktree of the repo, and idempotent.
 */
function pipeline_exclude_manifest(string $worktree): void
{
    // check-ignore exits 0 when the path is already ignored
    [$checkIgnoreExit] = pipeline_git_run($worktree, ['check-ignore', '-q', '.claude/pipeline/manifest.json']);
    if ($checkIgnoreExit === 0) {
        return;
    }

    $exclude = pipeline_git_path($worktree, pipeline_git($worktree, ['rev-parse', '--git-common-dir'])) . '/info/exclude';
    if (! is_dir(dirname($exclude))) {
        mkdir(dirname($exclude), 0777, true);
    }
    $current = is_file($exclude) ? (string) file_get_contents($exclude) : '';
    $separator = $current === '' || str_ends_with($current, "\n") ? '' : "\n";
    file_put_contents($exclude, $separator . ".claude/pipeline/\n", FILE_APPEND);
}

/** Only a green result on this exact tree lets a full suite be skipped. */
function pipeline_suite_needed(?array $last, string $tree): bool
{
    return ! ($last !== null && ($last['outcome'] ?? null) === 'green' && ($last['tree'] ?? null) === $tree);
}
```

- [ ] **Step 4: Run the pipeline suite to verify it passes**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS.

- [ ] **Step 5: Document kickoff exclusion in `engine.md`**

In `skills/pipeline/references/engine.md` §Kickoff, directly after the paragraph that starts `Record the \`worktree\` absolute path in the manifest`, insert:

````markdown
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
````

- [ ] **Step 6: Document suite reuse in `engine.md`**

In §Mechanical checks, replace

```markdown
**What runs, and when.** After each step: the test suite, then `static-analysis` over the whole
```

with

```markdown
**What runs, and when.** After each step: the test suite (skipped when §Suite reuse finds this tree
already green), then `static-analysis` over the whole
```

Then insert a new section directly before `## \`auto\` — the engine resolves the review itself`:

````markdown
## Suite reuse — once per tree

A full suite run proves something about the **content** it ran over, not about a commit. Every point
that runs the full suite asks first:

- after each `implement` step,
- after review fixes,
- before `review-pr`.

```bash
CHECKS="$HOME/.claude/skills/pipeline/checks"
MANIFEST=".claude/pipeline/<branch>.json"
TREE=$(php -r 'require $argv[1] . "/suite.php"; echo pipeline_tree_key(getcwd());' "$CHECKS")
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
- **The reviewer is told.** The `review-pr` brief states *"full suite green over tree `<tree>` at
  `<sha>`: N passed"*. Whether to re-run stays the reviewer's call.
- **No baseline.** No suite runs before the change. A red full suite is a failing step, fixed and
  bounded like any other.
  - When the engine believes a failure predates the change, that is a **machinery failure → halt**
    with the evidence (§Failure policy), never an annotation.
  - Never switch the run's worktree to the base commit to compare: under a running stack that
    desyncs vendor, migrations and assets, and a wrong red would be filed as pre-existing.
- **Failure to compute the key** (`pipeline_git` throws) is a machinery failure. Run the suite; never
  assume reuse.
````

- [ ] **Step 7: Document the `suite` field in `manifest.md`**

In `skills/pipeline/references/manifest.md` §Fields, add this row directly after the `lease` row:

```markdown
| `suite` | optional | the last full suite: `{tree, outcome: green\|red, passed, failed, at}` — see *Two rules* for why a recomputable field is stored |
```

In §Two rules, add this bullet after the *Recomputable fields* bullet:

```markdown
- **Named exception: `suite`.** A suite result is recomputable (re-run it), yet it is stored,
  because it cannot go stale silently: it is used only when `pipeline_tree_key()` of the current
  working tree equals the recorded `tree`, and losing it costs one re-run (`engine.md` §Suite reuse).
```

- [ ] **Step 8: Run the pipeline suite again and commit**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS.

```bash
git add skills/pipeline/checks/suite.php skills/pipeline/checks/tests/SuiteTest.php skills/pipeline/references/engine.md skills/pipeline/references/manifest.md
git commit -m "feat(pipeline): reuse a green full suite per tree; keep the manifest out of git"
```

---

### Task 6: Proportional design leg and grow-the-plan escalation (engine docs)

**Files:**
- Modify: `skills/pipeline/references/engine.md` (stations table `design` row, new §Design size, §Navigation)
- Modify: `skills/pipeline/references/gates.md` (§Modes, §Content triggers, §How the engine calls Phase A)
- Modify: `skills/pipeline/references/manifest.md` (§gate_ledger key table, §Reconstruction probe table)
- Modify: `skills/pipeline/SKILL.md` (§Invocation and navigation)

**Interfaces:**
- Consumes (by name, in prose and snippets):
  - `DesignSize::fromSpec()`, `DesignSize->escalation()`, `DesignSize::MAX_CODE_LINES` (Task 3);
  - `pipeline_code_lines()`, `pipeline_triggers()` (Task 2);
  - `pipeline_done_legs()` (Task 4).
- Produces: the engine procedure a run follows. No code.

- [ ] **Step 1: Replace the `design` row of the stations table in `engine.md`**

In §Stations, replace the whole row that starts `| **design** *(compound)* |` with:

```markdown
| **design** *(compound)* | `superpowers:brainstorming`, then `superpowers:writing-plans` for an **Architectural** design; for a **Bounded** design, brainstorming's Bounded path with no `writing-plans` (§Design size) | human drives the brainstorm dialogue; if brainstorming classifies Bounded without `light`, the pipeline asks (§Design size); re-invoke `/pipeline` to continue | a subagent turns a tight brief into a spec **and must write the questions it would have asked plus its assumed answers into the spec**, so `/critique plan` audits exactly those assumptions. The brief says which path is permitted: Bounded only with `light`, otherwise Architectural | writes spec + plan pointers; the size is the spec's `**Design size:**` header, never stored |
```

- [ ] **Step 2: Add §Design size to `engine.md`**

Insert this new section directly before `## The proof store — where the visual record actually lives`:

````markdown
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

`/pipeline [interactive|auto] [light] <idea | spec-path | pr#>`. The word `light` **permits** Bounded.
It matters only while `design` has not run; on a resume the size comes from the spec and `light` is
ignored, with a note saying so.

| | with `light` | without `light` |
|---|---|---|
| `interactive` | brainstorming runs normally; Bounded when it classifies Bounded | when brainstorming classifies Bounded, **ask** as one multiple-choice question: *"This looks like a small change: continue with a short design (Bounded), or write the full spec and plan?"* Yes → Bounded. No → tell brainstorming to take the Architectural path |
| `auto` | the design brief permits the Bounded path | the design brief requires the Architectural path |

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
<auto only: each question that would have been asked, and the answer assumed>
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

**When to check.** Only while the spec says Bounded:
- after every commit in `implement`, and
- at the start of every later leg.

```bash
CHECKS="$HOME/.claude/skills/pipeline/checks"
git diff origin/<base>...HEAD > "$TMPDIR/pipeline.diff"
# triggers.php loads the diff parser itself — do not require it a second time
php -r 'require $argv[1] . "/triggers.php"; require $argv[1] . "/design_size.php";
        $diff = file_get_contents($argv[2]);
        $size = DesignSize::fromSpec(file_get_contents($argv[3]));
        echo $size->escalation(pipeline_triggers($diff), pipeline_code_lines($diff)) ?? "", "\n";' \
  "$CHECKS" "$TMPDIR/pipeline.diff" "<spec path>"
# empty line → stays Bounded; otherwise the printed reason is why it must grow
```

**What escalates.**
- `migration` or `auth` fires: a 15-line spec may not name what the diff contains.
- More than `DesignSize::MAX_CODE_LINES` (100) code lines, added + deleted.
- `package` does **not** escalate. In a project it is a constraint bump whose code was reviewed in
  the package's own PR; it keeps its annotation.
- **Judgement also escalates:**
  - brainstorming's ratchet upgrades the path;
  - an `auto` assumption turns out to change what gets built;
  - `implement` needs files or behaviour the plan did not name. The implement subagent returns
    **"plan insufficient"** instead of improvising.

**On escalation, grow the design; do not re-design it.**
1. Append a ledger entry: `{gate: 'design-size', leg: <current leg>, at, reason, outcome: 'escalated'}`.
2. Move the cursor back to `design`; backward navigation is always allowed. In grow form:
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
````

- [ ] **Step 3: Point `engine.md` §Navigation at `pipeline_done_legs`**

In §Navigation, replace

```markdown
gate leg that has not run is refused** (`gates.md`). That refusal is the un-skippable-review
promise made mechanical.
```

with

```markdown
gate leg that has not run is refused** (`gates.md`). That refusal is the un-skippable-review
promise made mechanical. `doneLegs` is always `pipeline_done_legs(gate_ledger)`, so a gate passed
before the latest `design-size` escalation no longer counts (§Design size).
```

- [ ] **Step 4: Update `gates.md`**

In §Modes, directly after the paragraph that starts `There is no third mode and no per-gate override`, insert:

```markdown
**`light` is not a mode, and not a second chain.** It permits a **Bounded** design (`engine.md`
§Design size): a ~15-line spec and a ~10-line plan instead of a full design. Legs, gates and
navigation are identical for both sizes. `mode` decides how a gate is resolved; the design size
decides how much design a gate reviews. Neither changes which gates exist.
```

In §Content triggers, directly after the table, insert:

```markdown
**On a Bounded design, `migration` and `auth` escalate** instead of only annotating: the design grows
to Architectural and is re-reviewed (`engine.md` §Design size). The auth match ignores comment and
docblock lines, because on a Bounded design a false positive costs a re-review, not a footnote.
`package` only ever annotates.
```

In §How the engine calls Phase A, replace

```markdown
have run; `pipeline_can_navigate`'s `$doneLegs` is derived from it.
```

with

```markdown
have run; `pipeline_can_navigate`'s `$doneLegs` is `pipeline_done_legs()` over it, which drops gate
passes older than the latest `design-size` escalation.
```

- [ ] **Step 5: Update `manifest.md`**

In the `gate_ledger` key table, replace

```markdown
| `gate` | `plan-approval` \| `pr-review` \| `verify-ui` |
```

with

```markdown
| `gate` | `plan-approval` \| `pr-review` \| `verify-ui` \| `design-size` |
```

and replace

```markdown
| `outcome` | `continued` \| `looped-back` \| `halted` |
```

with

```markdown
| `outcome` | `continued` \| `looped-back` \| `halted` \| `escalated` (only on `design-size`) |
```

Directly after the paragraph that starts `**A \`verify-ui\` entry is the thin shape**`, insert:

```markdown
**A `design-size` entry** records a Bounded design growing to Architectural: `gate`, `leg`, `at`,
`reason` (the string `DesignSize->escalation()` returned, or the judgement in a sentence) and
`outcome: escalated`. It is not a loop-back and never counts toward a gate's cycle bound. It resets
which gates count as run: `pipeline_done_legs()` ignores every gate pass older than it.
```

In the §Reconstruction probe table, replace the `planApproved` row with:

```markdown
| `planApproved` | the `gate_ledger` holds a `plan-approval` entry with `outcome: continued` newer than the latest `design-size` escalation — a human approval, or the engine's own continue under `auto` — else re-run `review-plan` (a re-review is cheap and stateless) |
```

- [ ] **Step 6: Update `SKILL.md`**

In §Invocation and navigation, replace

```
/pipeline [interactive|auto] <idea | spec-path | pr#>   # start a run (mode defaults to interactive)
```

with

```
/pipeline [interactive|auto] [light] <idea | spec-path | pr#>   # start a run (mode defaults to interactive)
```

and add this bullet directly after the `**Mode defaults to \`interactive\`.**` bullet:

```markdown
- **`light` permits a small design.** A Bounded design is a ~15-line spec and a ~10-line plan
  instead of a full design; every leg and both reviews still run. Without `light`, `interactive`
  asks when brainstorming finds the change small, and `auto` always writes the full design. A
  Bounded run that turns out bigger grows its design and is re-reviewed (`references/engine.md`
  §Design size).
```

- [ ] **Step 7: Verify the docs name only functions that exist, and the suite still passes**

Run:
```bash
grep -oE "pipeline_[a-z_]+\(|DesignSize(::|->)[a-zA-Z_]+" skills/pipeline/SKILL.md skills/pipeline/references/*.md | sed 's/.*://' | sort -u
grep -hoE "function pipeline_[a-z_]+|function [a-zA-Z]+\(|case [A-Za-z]+|const [A-Z_]+" skills/pipeline/checks/*.php skills/critique/checks/diff_parse.php | sort -u
./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests
```
Expected: every `pipeline_*` and `DesignSize` name from the first command is defined per the second (`fromSpec`, `escalation`, `MAX_CODE_LINES` included), and the suite PASSES.

- [ ] **Step 8: Commit**

```bash
git add skills/pipeline/references/engine.md skills/pipeline/references/gates.md skills/pipeline/references/manifest.md skills/pipeline/SKILL.md
git commit -m "docs(pipeline): proportional design leg — Bounded or Architectural, grow on escalation"
```

---

### Task 7: Review fixes in the engine, and what a leg brief consists of

**Files:**
- Modify: `skills/pipeline/references/engine.md` (§`auto` bullet, new §What a leg brief consists of)
- Modify (outside the repo, not committed): `/Users/jroelofs/.claude/projects/-Users-jroelofs/memory/feedback_never_double_dispatch_subagents.md`

**Interfaces:**
- Consumes: §Suite reuse (Task 5) and §Design size (Task 6), by section name.
- Produces: the brief rules every coordinator and the engine follow. No code.

- [ ] **Step 1: Bound inline review fixes in `engine.md` §`auto`**

Replace

```markdown
- **Act on what is worth acting on.** Apply the fixes to the spec, the plan or the code and commit
  them. Record the rest — already-mitigated observations, notes for posterity — without an edit.
```

with

```markdown
- **Act on what is worth acting on — yourself.** Apply the fixes to the spec, the plan or the code
  and commit them **in the engine session**. Edits to documents the engine already holds, and small
  code fixes, never get a subagent of their own: a fresh agent must first re-read what the engine
  already has. Rework — a review saying the work is fundamentally wrong — is not an edit; it loops
  back (next bullet). Record the rest — already-mitigated observations, notes for posterity — without
  an edit.
```

- [ ] **Step 2: Add §What a leg brief consists of to `engine.md`**

Insert this new section directly before `## Mechanical checks — the deterministic layer inside \`implement\``:

```markdown
## What a leg brief consists of

Every dispatched leg gets a brief, from the engine or from a coordinator running several pipelines.
A brief consists of:

- **pointers** to the artifacts: spec, plan, PR, issue;
- **the settled decisions** and the manifest state the leg needs, including §Suite reuse's last
  green tree;
- **the overrides this file prescribes for that leg**, e.g. *"leave the PR draft"* (§Who takes the PR
  out of draft) or the permitted design size (§Design size);
- **nothing a station does not ask for.** No test policy, proof format or process of the brief
  writer's own invention.

**Plans and specs committed before 2026-09-14 are not exemplars** for test or proof policy. Many carry
the rules below, and a design subagent that reads them as examples copies the rules forward.

Three rules briefs invented, measured over 70 runs and retired:

| Invented rule | What it cost | Instead |
|---|---|---|
| *"EVERY new assertion must be MUTATION-PROVEN"*, with hash checks and a `*.proof.md` write-up | 4–9 filtered test runs per run plus the write-up; most of what `review-plan` then integrated on small PRs policed it | A test written first has been seen red: that is the proof. Mutation-prove only a test written **after** the code (a test on existing behaviour that could not fail, a test added during review fixes). No proof documents; two lines in the PR body |
| *"Measure your OWN suite baseline first"* | a full suite before any change (one run: 531 s + 179 s) | §Suite reuse: no baseline; a red suite is a failing step |
| Status checks to a running subagent (*"are you still working?"*), sent minutes after dispatch | no reviewer finished sooner; each interrupts a turn | Wait for the completion notification. Check liveness only on a suspected stall: an agent past its usual upper end (~11 min for a `/critique` reviewer). Never dispatch a second agent for the same task |
```

- [ ] **Step 3: Point the memory at `engine.md`**

In `/Users/jroelofs/.claude/projects/-Users-jroelofs/memory/feedback_never_double_dispatch_subagents.md`, replace

```markdown
finish sooner (owner decision
2026-09-14, recorded in LaravelClaudeMd `docs/superpowers/specs/2026-09-14-pipeline-light-design.md`).
```

with

```markdown
finish sooner (owner decision
2026-09-14; for pipeline runs the rule lives in LaravelClaudeMd `skills/pipeline/references/engine.md`
§What a leg brief consists of).
```

This file is outside the repo (machine-local memory). Do not stage or commit it.

- [ ] **Step 4: Verify and commit**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS.

```bash
git add skills/pipeline/references/engine.md
git commit -m "docs(pipeline): review fixes stay in the engine; what a leg brief consists of"
```

---

### Task 8: Final verification against the spec

**Files:**
- No new files. Read-only checks, plus fixes if a check fails.

**Interfaces:**
- Consumes: everything above.
- Produces: a verified branch ready for one PR.

- [ ] **Step 1: Run both suites in full**

Run:
```bash
./vendor/bin/pest -c skills/critique/checks/phpunit.xml --test-directory=skills/critique/checks/tests
./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests
```
Expected: both PASS, with no skipped or risky tests beyond any that existed before Task 1.

- [ ] **Step 2: Confirm the must-not-change functions and tests are untouched**

Run:
```bash
git diff origin/main...HEAD -- skills/pipeline/checks/manifest.php skills/pipeline/checks/tests/PipelineTest.php skills/pipeline/checks/tests/ManifestTest.php
git diff origin/main...HEAD -- skills/pipeline/checks/pipeline.php | grep -E "^-" | grep -vE "^---"
```
Expected: the first command prints nothing. The second prints nothing, because `pipeline.php` only gained lines.

- [ ] **Step 3: Confirm the stale design leg wording is gone**

Run:
```bash
grep -n "turn a tight brief into a spec" skills/pipeline/references/engine.md
grep -rn "Design size" skills/pipeline/SKILL.md skills/pipeline/references/
```
Expected: the first match sits in the new `design` row, which now also names the permitted path. The second finds §Design size in `engine.md` and the references in `gates.md`, `manifest.md` and `SKILL.md`.

- [ ] **Step 4: Check the spec's coverage list**

For each item, open the file and confirm the text is present:
- **Spec §1** (one chain, size from header): `engine.md` §Design size table + `DesignSize::fromSpec`.
- **Spec §2** (who picks, interactive question, package refusal, artifacts): `engine.md` §Design size subsections.
- **Spec §3** (escalation triggers, 100 lines, grow form, done legs, once/one-way, `package` annotates): `engine.md` §Escalation + `DesignSize->escalation` + `pipeline_done_legs` + `gates.md`.
- **Spec §4** (suite reuse, `info/exclude`, no baseline, manifest exception): `engine.md` §Suite reuse + §Kickoff + `manifest.md`.
- **Spec §5** (review fixes bounded): `engine.md` §`auto` bullet.
- **Spec §6** (brief composition, three rules, one home): `engine.md` §What a leg brief consists of + the memory pointer.

Expected: every item is found. Fix anything missing in the file named, re-run Step 1, and commit with an explicit path.

- [ ] **Step 5: Push**

```bash
git push
```
Expected: the branch `feature/pipeline-light-chain` is up to date on origin. Opening the PR is the owner's call after this plan's execution report.
