<?php

/**
 * Parse a unified diff into per-file added lines and a removed-line count.
 * `line` is the line number in the NEW file; `text` drops the leading '+'.
 * `old` is the pre-change path — the only real path of a deleted file, whose `file` is /dev/null.
 *
 * @return array<int, array{file: string, old: string, added: array<int, array{line: int, text: string}>, removed: int}>
 */
function parse_diff(string $diff): array
{
    $files = [];
    $idx = -1;
    $newLine = 0;
    $oldPath = null;

    foreach (explode("\n", $diff) as $raw) {
        if (str_starts_with($raw, '--- ')) {
            $oldPath = preg_replace('#^a/#', '', trim(substr($raw, 4)));
            continue;
        }
        if (str_starts_with($raw, '+++ ')) {
            $path = preg_replace('#^b/#', '', trim(substr($raw, 4)));
            $files[] = ['file' => $path, 'old' => $oldPath ?? $path, 'added' => [], 'removed' => 0];
            $idx = count($files) - 1;
            $oldPath = null;
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
            $files[$idx]['removed']++; // does not advance the new-file counter
        } else {
            $newLine++; // context line
        }
    }

    return $files;
}
