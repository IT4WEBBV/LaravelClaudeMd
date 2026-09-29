<?php

/**
 * `php statusline_cli.php <cwd>`: one row per unfinished `autoflow` run of the repo holding <cwd>
 * (`statusline.php`), joined by newlines, without a trailing one; nothing outside a repo or without
 * runs. `statusline/statusline-command.sh` appends it below the status line and drops it on any exit
 * but 0.
 */

// a warning exits 0 on stdout, which the status line would print as a row; a fatal still exits 255
ini_set('display_errors', 'stderr');

require_once __DIR__ . '/statusline.php';

$scan = pipeline_status_scan((string) ($argv[1] ?? ''));

echo implode("\n", pipeline_status_lines($scan['runs'], time(), $scan['repo']));
