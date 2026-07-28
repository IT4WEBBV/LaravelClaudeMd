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
    // repo root (see CLAUDE.md §Project Structure). Anchoring on ^ made every one of
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
