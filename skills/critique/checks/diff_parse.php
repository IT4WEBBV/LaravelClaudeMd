<?php

/**
 * Parse a unified diff into per-file added lines.
 * `line` is the line number in the NEW file; `text` drops the leading '+'.
 *
 * @return array<int, array{file: string, added: array<int, array{line: int, text: string}>}>
 */
function parse_diff(string $diff): array
{
    $files = [];
    $idx = -1;
    $newLine = 0;

    foreach (explode("\n", $diff) as $raw) {
        if (str_starts_with($raw, '+++ ')) {
            $path = preg_replace('#^b/#', '', trim(substr($raw, 4)));
            $files[] = ['file' => $path, 'added' => []];
            $idx = count($files) - 1;
            continue;
        }
        if (str_starts_with($raw, '--- ')) {
            continue;
        }
        if (str_starts_with($raw, '@@')) {
            $newLine = preg_match('/\+(\d+)/', $raw, $m) ? (int) $m[1] : 0;
            continue;
        }
        if ($idx < 0) {
            continue;
        }
        if (str_starts_with($raw, '+')) {
            $files[$idx]['added'][] = ['line' => $newLine, 'text' => substr($raw, 1)];
            $newLine++;
        } elseif (str_starts_with($raw, '-')) {
            // removed line: does not advance the new-file counter
        } else {
            $newLine++; // context line
        }
    }

    return $files;
}
