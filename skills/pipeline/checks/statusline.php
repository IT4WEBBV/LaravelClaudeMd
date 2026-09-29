<?php

/**
 * The status line's section of `autoflow` runs (README §Status line): one row per unfinished
 * `autoflow` run of the repo, read from the manifests the pipeline already writes. It reads, never
 * writes, and calls no `gh`. `statusline_cli.php` prints it.
 */

require_once __DIR__ . '/manifest.php';
require_once __DIR__ . '/suite.php';
require_once __DIR__ . '/kickoff.php';

const PIPELINE_STATUS_MAX_LINES = 4;
const PIPELINE_STATUS_STALE_SECONDS = 90 * 60;
const PIPELINE_STATUS_RED = "\033[31m";
const PIPELINE_STATUS_YELLOW = "\033[33m";

/**
 * Every worktree's manifest, by the branch git lists for it (never a glob: `<stem>.before.json`
 * sits beside each manifest), and the repo named in the primary checkout's work-on config.
 *
 * @return array{repo: ?string, runs: list<array{manifest: array, mtime: int}>}
 */
function pipeline_status_scan(string $cwd): array
{
    // `git -C ''` would run wherever PHP was started
    if (! is_dir($cwd)) {
        return ['repo' => null, 'runs' => []];
    }
    [$code, $porcelain] = pipeline_git_run($cwd, ['worktree', 'list', '--porcelain']);
    if ($code !== 0) {
        return ['repo' => null, 'runs' => []];
    }
    $worktrees = pipeline_status_worktrees($porcelain);

    $runs = [];
    foreach ($worktrees as $worktree) {
        if ($worktree['branch'] === null) {
            continue;
        }
        $path = manifest_path($worktree['path'], $worktree['branch']);
        $manifest = manifest_read($path);
        if ($manifest !== null) {
            $runs[] = ['manifest' => $manifest, 'mtime' => (int) filemtime($path)];
        }
    }

    return ['repo' => pipeline_status_repo($worktrees[0]['path']), 'runs' => $runs];
}

/** @return list<array{path: string, branch: ?string}> the primary checkout first, as git lists it */
function pipeline_status_worktrees(string $porcelain): array
{
    return array_map(fn (string $entry) => [
        'path' => preg_match('/^worktree (.+)$/m', $entry, $path) ? $path[1] : '',
        'branch' => preg_match('#^branch refs/heads/(.+)$#m', $entry, $branch) ? $branch[1] : null,
    ], preg_split('/\n\n+/', $porcelain));
}

function pipeline_status_repo(string $primary): ?string
{
    $config = $primary . '/.claude/work-on.config.md';

    return is_file($config) ? pipeline_repo_config_value((string) file_get_contents($config), 'Repo', 'repo') : null;
}

/** @param list<array{manifest: array, mtime: int}> $runs @return list<string> */
function pipeline_status_lines(array $runs, int $now, ?string $repo): array
{
    $open = array_values(array_filter(
        $runs,
        fn (array $run) => ($run['manifest']['mode'] ?? null) === 'autoflow' && ! manifest_finished($run['manifest']),
    ));
    usort($open, fn (array $a, array $b) => pipeline_status_order($a['manifest']) <=> pipeline_status_order($b['manifest']));

    $lines = array_map(
        fn (array $run) => pipeline_status_line($run['manifest'], $now - $run['mtime'], $repo),
        array_slice($open, 0, PIPELINE_STATUS_MAX_LINES),
    );
    $hidden = count($open) - count($lines);
    if ($hidden > 0) {
        $lines[count($lines) - 1] .= "  +{$hidden} more";
    }

    return $lines;
}

/** Halted first, the runs the owner has to act on; then by issue, runs without one last. */
function pipeline_status_order(array $manifest): array
{
    $issue = pipeline_status_number($manifest['artifacts']['issue'] ?? null);

    return [
        ($manifest['cursor']['status'] ?? null) === 'halted' ? 0 : 1,
        $issue ?? PHP_INT_MAX,
        (string) ($manifest['branch'] ?? ''),
    ];
}

function pipeline_status_line(array $manifest, int $age, ?string $repo): string
{
    $cursor = $manifest['cursor'];
    $issue = pipeline_status_number($manifest['artifacts']['issue'] ?? null);
    $pr = pipeline_status_number($manifest['artifacts']['pr'] ?? null);
    $halted = ($cursor['status'] ?? null) === 'halted';
    $reason = $halted ? pipeline_status_reason((string) ($cursor['reason'] ?? '')) : '';

    return implode('  ', array_filter([
        $issue === null ? (string) $manifest['branch'] : pipeline_status_link("#{$issue}", pipeline_status_url($repo, "issues/{$issue}")),
        (string) $cursor['leg'],
        $halted ? pipeline_status_color('halted', PIPELINE_STATUS_RED) : (string) $cursor['status'],
        $age > PIPELINE_STATUS_STALE_SECONDS ? pipeline_status_color(pipeline_status_age($age), PIPELINE_STATUS_YELLOW) : pipeline_status_age($age),
        $pr === null ? '' : pipeline_status_link("PR#{$pr}", pipeline_status_url($repo, "pull/{$pr}")),
        $reason === '' ? '' : pipeline_status_color($reason, PIPELINE_STATUS_RED),
    ], fn (string $part) => $part !== ''));
}

/** An issue or PR as the manifest records it, a number or its GitHub URL, as the number a URL can carry. */
function pipeline_status_number(int|string|null $recorded): ?int
{
    return preg_match('/(\d+)$/', (string) $recorded, $number) ? (int) $number[1] : null;
}

function pipeline_status_age(int $seconds): string
{
    $minutes = intdiv(max(0, $seconds), 60);

    return match (true) {
        $minutes < 60 => "{$minutes}m",
        $minutes < 24 * 60 => sprintf('%dh%02dm', intdiv($minutes, 60), $minutes % 60),
        default => intdiv($minutes, 24 * 60) . 'd',
    };
}

/**
 * One row, whatever the halt wrote: control characters (escape sequences included) out, whitespace collapsed, 60 columns.
 * Only the reason needs it: the dispatcher refuses a `cursor.leg` or `cursor.status` outside its set, and a branch is a git refname.
 */
function pipeline_status_reason(string $reason): string
{
    $printable = preg_replace('/[\x00-\x08\x0E-\x1F\x7F]/', '', $reason);

    return mb_strimwidth(trim(preg_replace('/\s+/u', ' ', $printable)), 0, 60, '…');
}

function pipeline_status_url(?string $repo, string $path): ?string
{
    return $repo === null ? null : "https://github.com/{$repo}/{$path}";
}

function pipeline_status_link(string $text, ?string $url): string
{
    return $url === null ? $text : "\033]8;;{$url}\007{$text}\033]8;;\007";
}

function pipeline_status_color(string $text, string $color): string
{
    return "{$color}{$text}\033[0m";
}
