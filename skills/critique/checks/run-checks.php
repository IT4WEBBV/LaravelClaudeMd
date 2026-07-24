<?php

require_once __DIR__ . '/diff_parse.php';
require_once __DIR__ . '/checks.php';
require_once __DIR__ . '/vendor_hacks.php';

/** @return array{exact: array, heuristic: array} */
function run_checks(string $diff, string $repoRoot): array
{
    $files = parse_diff($diff);

    return [
        'exact' => [
            ...check_blade_php($files),
            ...check_vendor_hacks($repoRoot),
        ],
        'heuristic' => [
            ...check_changelog_fragment($files),
            ...check_null_safety($files),
            ...check_each_on_builder($files),
            ...check_migration_writes($files),
        ],
    ];
}

// CLI: `git diff origin/main...HEAD | php run-checks.php`
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $diff = stream_get_contents(STDIN) ?: '';
    echo json_encode(run_checks($diff, getcwd()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}
