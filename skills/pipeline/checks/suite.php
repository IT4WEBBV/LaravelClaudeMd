<?php

/**
 * Suite reuse — once per tree (`../references/engine.md` §Suite reuse). A green full suite is
 * reused while the working tree's *content* is unchanged; committing already-tested content keeps
 * the key, because the key is a tree, not a commit.
 */

/** Run git in $worktree without a shell. @return array{0: int, 1: string, 2: string} */
function pipeline_git_run(string $worktree, array $args, array $env = []): array
{
    // stderr goes to a file, not a second pipe: while stdout is drained, git blocks on a full
    // stderr pipe (a CRLF warning per file is enough), and both processes wait forever
    $stderr = tmpfile();
    $process = proc_open(
        ['git', '-C', $worktree, ...$args],
        [1 => ['pipe', 'w'], 2 => $stderr],
        $pipes,
        null,
        $env === [] ? null : [...getenv(), ...$env],
    );
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $code = proc_close($process);

    rewind($stderr);
    $err = stream_get_contents($stderr);
    fclose($stderr);

    return [$code, trim($out), trim($err)];
}

/** Run git and fail loudly: a key that cannot be computed is a machinery failure, never a reuse. */
function pipeline_git(string $worktree, array $args, array $env = []): string
{
    [$code, $out, $err] = pipeline_git_run($worktree, $args, $env);
    if ($code !== 0) {
        throw new RuntimeException('git ' . implode(' ', $args) . " failed ({$code}): {$err}");
    }

    return $out;
}

/** git prints some paths relative to the worktree it ran in. */
function pipeline_git_path(string $worktree, string $path): string
{
    return str_starts_with($path, '/') ? $path : rtrim($worktree, '/') . '/' . $path;
}

/** The tree the working copy would commit right now — untracked, non-ignored files included. */
function pipeline_tree_key(string $worktree): string
{
    $index = sys_get_temp_dir() . '/pipeline-index-' . uniqid();
    $realIndex = pipeline_git_path($worktree, pipeline_git($worktree, ['rev-parse', '--git-path', 'index']));
    if (is_file($realIndex)) {
        copy($realIndex, $index);
    }

    try {
        pipeline_git($worktree, ['add', '-A'], ['GIT_INDEX_FILE' => $index]);

        return pipeline_git($worktree, ['write-tree'], ['GIT_INDEX_FILE' => $index]);
    } finally {
        if (is_file($index)) {
            unlink($index);
        }
    }
}

/**
 * Keep `.claude/pipeline/` out of git in repos that do not ignore `.claude/`. Local (`info/exclude`
 * is never pushed), shared by every worktree of the repo, and idempotent.
 */
function pipeline_exclude_manifest(string $worktree): void
{
    // check-ignore exits 0 when the path is already ignored
    [$checkIgnoreExit] = pipeline_git_run($worktree, ['check-ignore', '-q', '.claude/pipeline/manifest.json']);
    if ($checkIgnoreExit === 0) {
        return;
    }

    $exclude = pipeline_git_path($worktree, pipeline_git($worktree, ['rev-parse', '--git-common-dir'])) . '/info/exclude';
    if (! is_dir(dirname($exclude))) {
        mkdir(dirname($exclude), 0777, true);
    }
    $current = is_file($exclude) ? (string) file_get_contents($exclude) : '';
    $separator = $current === '' || str_ends_with($current, "\n") ? '' : "\n";
    file_put_contents($exclude, $separator . ".claude/pipeline/\n", FILE_APPEND);
}

/** Only a green result on this exact tree lets a full suite be skipped. */
function pipeline_suite_needed(?array $last, string $tree): bool
{
    return ! ($last !== null && ($last['outcome'] ?? null) === 'green' && ($last['tree'] ?? null) === $tree);
}
