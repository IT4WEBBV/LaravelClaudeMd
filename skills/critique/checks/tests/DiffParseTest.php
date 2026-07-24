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
