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
