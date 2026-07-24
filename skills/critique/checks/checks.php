<?php

/** @return array<int, array{check: string, file: string, line: int, text: string}> */
function check_blade_php(array $files): array
{
    $findings = [];
    foreach ($files as $f) {
        if (! str_ends_with($f['file'], '.blade.php')) {
            continue;
        }
        foreach ($f['added'] as $a) {
            if (preg_match('/@php\b/', $a['text'])) {
                $findings[] = ['check' => 'blade-php', 'file' => $f['file'], 'line' => $a['line'], 'text' => trim($a['text'])];
            }
        }
    }

    return $findings;
}
