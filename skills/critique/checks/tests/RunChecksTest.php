<?php

// run-checks.php is loaded by tests/Pest.php once it exists (like the other
// check files), so no top-level require here — that keeps the pre-impl run a
// clean undefined-function failure rather than a missing-file fatal.

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
