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
