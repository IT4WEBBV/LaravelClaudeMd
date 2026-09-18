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

it('detects the same paths when the Laravel app is nested under code/www', function () {
    // The house-standard it4web project layout puts the app at code/www/, not at the
    // repo root (see CLAUDE.md §Docker Environment). Anchoring on ^ made every one of
    // these miss, so a Livewire-only change reported ui=false and verify-ui was skipped.
    $livewire = <<<'DIFF'
+++ b/code/www/app/Livewire/UserForm.php
@@ -1,0 +1,1 @@
+// component
DIFF;
    expect(pipeline_triggers($livewire)['ui'])->toBeTrue();

    $httpLivewire = <<<'DIFF'
+++ b/code/www/app/Http/Livewire/Legacy.php
@@ -1,0 +1,1 @@
+// component
DIFF;
    expect(pipeline_triggers($httpLivewire)['ui'])->toBeTrue();

    $css = <<<'DIFF'
+++ b/code/www/resources/css/app.css
@@ -1,0 +1,1 @@
+.x { color: red }
DIFF;
    expect(pipeline_triggers($css)['ui'])->toBeTrue();

    $migration = <<<'DIFF'
+++ b/code/www/database/migrations/2026_07_01_000000_add_status.php
@@ -1,0 +1,1 @@
+Schema::table('orders', fn ($t) => $t->string('status'));
DIFF;
    expect(pipeline_triggers($migration)['migration'])->toBeTrue();

    $bump = <<<'DIFF'
+++ b/code/www/composer.json
@@ -1,0 +1,1 @@
+        "it4web/talldatatable": "^3.1",
DIFF;
    expect(pipeline_triggers($bump, 'acme/project')['package'])->toBeTrue();
});

it('does not fire on a path that merely ends in a matching segment name', function () {
    // `app/` and `database/` must be path segments, not substrings: a file called
    // `myapp/Livewire.php` or `scoreboard/migrations.php` is not a Laravel app.
    $notAnApp = <<<'DIFF'
+++ b/docs/bootstrap/Livewire.md
@@ -1,0 +1,1 @@
+text
DIFF;
    expect(pipeline_triggers($notAnApp)['ui'])->toBeFalse();

    $notAMigration = <<<'DIFF'
+++ b/scripts/database/seed.php
@@ -1,0 +1,1 @@
+// not a migrations dir
DIFF;
    expect(pipeline_triggers($notAMigration)['migration'])->toBeFalse();

    // composer.json must be the file itself, not any file whose name ends that way
    $notComposer = <<<'DIFF'
+++ b/docs/not-composer.json
@@ -1,0 +1,1 @@
+        "it4web/talldatatable": "^3.1",
DIFF;
    expect(pipeline_triggers($notComposer, 'acme/project')['package'])->toBeFalse();
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
