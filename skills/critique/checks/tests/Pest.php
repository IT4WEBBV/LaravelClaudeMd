<?php

// Load whichever stage-0 check functions exist so far. Files are added across
// the Phase A tasks (diff_parse → checks → vendor_hacks → run-checks); loading
// only what exists keeps each task's "watch it fail" an undefined-function
// failure rather than a missing-file fatal.
foreach (['diff_parse.php', 'checks.php', 'vendor_hacks.php', 'run-checks.php'] as $checkFile) {
    $path = __DIR__ . '/../' . $checkFile;
    if (is_file($path)) {
        require_once $path;
    }
}
