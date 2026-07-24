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

/** @return array<int, array{check: string, file: string, line: int, text: string}> */
function check_null_safety(array $files): array
{
    $candidates = [];
    foreach ($files as $f) {
        if (! str_ends_with($f['file'], '.php')) {
            continue;
        }
        $inConfig = str_starts_with($f['file'], 'config/');
        foreach ($f['added'] as $a) {
            if (str_contains($a['text'], '?->')) {
                $candidates[] = ['check' => 'null-safe-op', 'file' => $f['file'], 'line' => $a['line'], 'text' => trim($a['text'])];
            }
            if (! $inConfig && str_contains($a['text'], '??')) {
                $candidates[] = ['check' => 'null-coalesce', 'file' => $f['file'], 'line' => $a['line'], 'text' => trim($a['text'])];
            }
        }
    }

    return $candidates;
}

/** @return array<int, array{check: string, file: string, line: int, text: string}> */
function check_each_on_builder(array $files): array
{
    $candidates = [];
    foreach ($files as $f) {
        if (! str_ends_with($f['file'], '.php')) {
            continue;
        }
        foreach ($f['added'] as $a) {
            if (preg_match('/->each\(/', $a['text']) && ! preg_match('/->get\(\)\s*->each\(/', $a['text'])) {
                $candidates[] = ['check' => 'each-on-builder', 'file' => $f['file'], 'line' => $a['line'], 'text' => trim($a['text'])];
            }
        }
    }

    return $candidates;
}
