<?php

/**
 * Files under vendor/it4web/ modified after the last composer install —
 * the CLAUDE.md "vendor hack" check. Filesystem state, not the diff.
 *
 * @return array<int, array{check: string, file: string}>
 */
function check_vendor_hacks(string $repoRoot): array
{
    $marker = "$repoRoot/vendor/composer/installed.json";
    $dir = "$repoRoot/vendor/it4web";
    if (! is_file($marker) || ! is_dir($dir)) {
        return [];
    }
    $cmd = sprintf(
        'find %s -newer %s -name %s',
        escapeshellarg($dir),
        escapeshellarg($marker),
        escapeshellarg('*.php'),
    );
    $out = shell_exec($cmd) ?? '';
    $findings = [];
    foreach (array_filter(explode("\n", trim($out))) as $file) {
        $findings[] = ['check' => 'vendor-hack', 'file' => $file];
    }

    return $findings;
}
