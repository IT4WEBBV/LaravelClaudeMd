# PHPUnit to Pest migration

Migrating a Laravel test suite from class-based PHPUnit to Pest with `pest-plugin-drift`, as a
**mechanical phase-1 PR that lands fast**, followed by idiomatic polish in small per-domain PRs. First
done on ViewieMedia PR #1983 (2026-07-23): 163 files, 844 tests, zero behaviour change. Its plan and
design spec are in that repo: `docs/superpowers/plans/2026-07-23-pest-migration.md` and
`docs/superpowers/specs/2026-07-23-pest-migration-design.md`.

The shape: capture the baseline → hand-fix the constructs drift corrupts → swap dependencies → one
pure drift commit → fix the fallout → parity gate.

## Drift silently drops tests

Drift matches the `/** @test */` docblock exactly. A double-spaced `/** @test  */` is not recognised,
so the method becomes a plain top-level function and stops being collected. The suite reports
**green** at N-1 tests. Only a test-count gate catches it. Grep `^function (it_|test_|should_)` across
the converted suite as a cheap sweep for the same class of miss.

## The parity gate is the review

A "mechanical" conversion isn't: drift rewrites ~45 assertion types to `expect()`. Three hard checks:
(1) the test count equals the baseline, (2) the suite is green, (3) the normalised test-name diff is
empty. Also assert the normalised list has **no duplicates**: a dropped test paired with a duplicated
one keeps the count identical.

- **Capture the baseline before touching dependencies.** Once PHPUnit 12 is installed the old
  `/** @test */` tests can't be trusted to run. Capture `php artisan test` (count) and
  `vendor/bin/phpunit --list-tests` (names) first.
- **The two runners' `--list-tests` formats differ.** PHPUnit emits ` - Ns\Class::test_foo`; Pest emits
  ` - P\Ns\Class::__pest_evaluable_foo`. Normalise by dropping the `P\` root prefix and
  `__pest_evaluable_` / a leading `test_`. Two more divergences: datasets (PHPUnit `method"label"` vs
  Pest `method"dataset "label""`) and namespaces (PHPUnit prints the declared namespace, Pest derives
  it from the file path). Apply every rule symmetrically to both sides so a real drop still surfaces.
- Assertion counts are advisory but came out identical (3229 before and after), so a large swing is
  worth investigating.

## Hand-fix before drift

Four construct classes, each verifiable green on the old stack:

1. **A second top-level class** in a `*Test.php`: `RemoveClass` unwraps every top-level class.
2. **Anonymous classes with methods**: `ConvertMethodCall` is receiver-blind, so an `apply()` defined
   in the file turns every `$obj->apply()` into a bare `apply()`.
3. **Helper names duplicated across files**: non-test methods become top-level functions and Pest
   loads all test files into one process, so different bodies collide with `Cannot redeclare`.
4. **Class constants**: drift has no `ClassConst` rule, so `private const` lands at file scope (parse
   error) and every `self::FOO` breaks. Replace it with a private method (drift rewrites
   `$this->helper()` → `helper()`), an inline literal or a top-level `const`.

## Commits and dependencies

- **Commit the raw drift output as one commit with zero hand edits**, and every fix in separate themed
  commits after it. That keeps an 800-test diff reviewable: the reviewer trusts the tool for one
  commit and reads the gate plus the fixes.
- `pestphp/pest-plugin` must be allow-listed in `composer.json` → `config.allow-plugins`, or
  `composer require` aborts with a `PluginManager` error.
- **PHPUnit 12 rejects the PHPUnit 9 schema** (`<filter><whitelist>`, `backupStaticAttributes`,
  `convert*ToExceptions`): rewrite `phpunit.xml` to the `<source>` schema. Copy the `<php>` env block
  verbatim; older apps read legacy `*_DRIVER` names and switch the database host on
  `APP_ENV=testing`. Gitignore `.phpunit.cache/`.
- On Laravel 13, **Pest 4 is forced**: `pest-plugin-laravel` supports Laravel 13 only from v4.1, which
  requires Pest 4, which pins `phpunit/phpunit ^12.5`. Remove the direct `phpunit/phpunit` dependency
  rather than bumping it.
- **No Pest plugins needed** when tests use the `Livewire::test()` facade and get Laravel assertions
  from a retained `Tests\TestCase`.
- **Keep the base `TestCase`**: `uses(Tests\TestCase::class)->in('Feature')` in `tests/Pest.php`. Pest
  binds each closure to the TestCase instance and its scope, so faked disks in `setUp()`, protected
  helpers and Mockery teardown keep working.
- **Defer global `RefreshDatabase` to phase 2.** `uses()->in()` has no per-file exclusion. Leave
  drift's per-file `uses(RefreshDatabase::class)` in phase 1; when centralising later, strip the
  per-file `uses()` in the same commit. Leave `WithFaker` per file.
- Run all dependency and test work inside the project's container against `composer.lock`. CI needs
  no change (`php artisan test` delegates to Pest once installed), but confirm it with a green CI run.
