<?php

function manifest_read(string $path): ?array
{
    if (! is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : null;
}

function manifest_write(string $path, array $data): void
{
    $dir = dirname($path);
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

/** Where a branch's manifest lives in its worktree: kickoff writes it there, the status line reads it there. */
function manifest_path(string $worktree, string $branch): string
{
    return rtrim($worktree, '/') . '/.claude/pipeline/' . str_replace('/', '-', $branch) . '.json';
}

/** Finished: `status: done` as the dispatcher writes it, or the old engine's `leg: done`. */
function manifest_finished(array $manifest): bool
{
    return ($manifest['cursor']['status'] ?? null) === 'done' || ($manifest['cursor']['leg'] ?? null) === 'done';
}

/** @return list<string> missing required keys */
function manifest_validate(array $data): array
{
    return array_values(array_filter(
        ['branch', 'worktree', 'mode', 'cursor'],
        fn ($key) => ! array_key_exists($key, $data),
    ));
}

function manifest_infer_cursor(array $p): string
{
    if (empty($p['spec']) || empty($p['plan'])) {
        return 'design';
    }
    if (empty($p['planApproved'])) {
        return 'review-plan';
    }
    if (empty($p['pr'])) {
        return 'handoff';
    }
    if (empty($p['implemented'])) {
        return 'implement';
    }
    if (! empty($p['uiNeeded']) && empty($p['verifyUi'])) {
        return 'verify-ui';
    }
    if (empty($p['prReviewed'])) {
        return 'review-pr';
    }

    return 'done';
}

/** A manifest without a ledger has an empty one. */
function pipeline_ledger(array $manifest): array
{
    return $manifest['gate_ledger'] ?? [];
}

/**
 * The files of one run, beside its manifest (`../references/engine.md` §The loop): the brief, the dispatch
 * snapshot, the step's diff, a review step's review and a resolve step's actions.
 *
 * @return array{brief: string, before: string, diff: string, review: string, actions: string}
 */
function manifest_files(string $manifestPath): array
{
    $stem = preg_replace('/\.json$/', '', $manifestPath);

    return [
        'brief' => "{$stem}.brief.md",
        'before' => "{$stem}.before.json",
        'diff' => "{$stem}.diff",
        'review' => "{$stem}.review.md",
        'actions' => "{$stem}.actions.json",
    ];
}

/** A path as git names it: relative to the worktree, an absolute path under it stripped of that prefix. */
function pipeline_relative_path(string $worktree, string $path): string
{
    $root = rtrim($worktree, '/') . '/';

    return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
}
