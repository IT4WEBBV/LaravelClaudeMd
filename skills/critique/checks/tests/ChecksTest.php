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
