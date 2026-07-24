<?php

// Load whichever pipeline check functions exist so far (added across Phase A tasks),
// so each task's "watch it fail" is an undefined-function failure, not a missing-file fatal.
require_once __DIR__ . '/../../../critique/checks/diff_parse.php'; // reuse the tested parser
foreach (['triggers.php', 'pipeline.php', 'manifest.php'] as $f) {
    $path = __DIR__ . '/../' . $f;
    if (is_file($path)) {
        require_once $path;
    }
}
