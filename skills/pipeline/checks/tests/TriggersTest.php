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
