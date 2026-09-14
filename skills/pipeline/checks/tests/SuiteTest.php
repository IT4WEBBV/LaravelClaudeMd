<?php

/** A throwaway repo with one commit, so no test ever touches a real checkout. */
function suite_repo(): string
{
    $dir = sys_get_temp_dir() . '/pipeline-suite-' . uniqid();
    mkdir($dir);
    foreach ([['init', '-q'], ['config', 'user.email', 'test@example.com'], ['config', 'user.name', 'Test'], ['config', 'commit.gpgsign', 'false']] as $args) {
        pipeline_git($dir, $args);
    }
    file_put_contents($dir . '/app.php', "<?php\n");
    pipeline_git($dir, ['add', 'app.php']);
    pipeline_git($dir, ['commit', '-qm', 'init']);

    return $dir;
}

it('reruns the suite unless this exact tree already went green', function () {
    expect(pipeline_suite_needed(null, 'abc'))->toBeTrue();
    expect(pipeline_suite_needed(['tree' => 'abc', 'outcome' => 'green'], 'abc'))->toBeFalse();
    expect(pipeline_suite_needed(['tree' => 'abc', 'outcome' => 'red'], 'abc'))->toBeTrue();
    expect(pipeline_suite_needed(['tree' => 'abc', 'outcome' => 'green'], 'def'))->toBeTrue();
});

it('keys a clean tree by its HEAD tree', function () {
    $repo = suite_repo();
    expect(pipeline_tree_key($repo))->toBe(pipeline_git($repo, ['rev-parse', 'HEAD^{tree}']));
});

it('changes the key for an untracked file without touching the real index', function () {
    $repo = suite_repo();
    $clean = pipeline_tree_key($repo);

    file_put_contents($repo . '/new.php', "<?php\n");
    expect(pipeline_tree_key($repo))->not->toBe($clean);
    expect(pipeline_git($repo, ['diff', '--cached', '--name-only']))->toBe('');
});

it('keeps the key when the tested content is committed', function () {
    $repo = suite_repo();
    file_put_contents($repo . '/new.php', "<?php\n");
    $tested = pipeline_tree_key($repo);

    pipeline_git($repo, ['add', 'new.php']);
    pipeline_git($repo, ['commit', '-qm', 'add new']);
    expect(pipeline_tree_key($repo))->toBe($tested);
});

it('keeps the manifest out of the key once it is excluded, and excludes it once', function () {
    $repo = suite_repo();
    pipeline_exclude_manifest($repo);
    pipeline_exclude_manifest($repo);
    $before = pipeline_tree_key($repo);

    mkdir($repo . '/.claude/pipeline', 0777, true);
    file_put_contents($repo . '/.claude/pipeline/feature-x.json', '{"suite":{}}');
    expect(pipeline_tree_key($repo))->toBe($before);
    expect(substr_count(file_get_contents($repo . '/.git/info/exclude'), '.claude/pipeline/'))->toBe(1);
});

it('works from a linked worktree, whose index and exclude live elsewhere', function () {
    $repo = suite_repo();
    $worktree = $repo . '-wt';
    pipeline_git($repo, ['worktree', 'add', '--quiet', '-b', 'feature/x', $worktree]);
    pipeline_exclude_manifest($worktree);

    mkdir($worktree . '/.claude/pipeline', 0777, true);
    file_put_contents($worktree . '/.claude/pipeline/feature-x.json', '{}');
    expect(pipeline_tree_key($worktree))->toBe(pipeline_git($worktree, ['rev-parse', 'HEAD^{tree}']));
});

it('does not hang when git writes more to stderr than a pipe holds', function () {
    $repo = suite_repo();
    pipeline_git($repo, ['config', 'core.autocrlf', 'input']);
    foreach (range(1, 1000) as $i) {
        file_put_contents("{$repo}/crlf-{$i}.txt", "line\r\n");
    }

    // git warns once per CRLF file, past the 64 KB a pipe buffer holds
    expect(pipeline_tree_key($repo))->toMatch('/^[0-9a-f]{40}$/');
    [, , $warnings] = pipeline_git_run($repo, ['add', '-A']);
    expect(strlen($warnings))->toBeGreaterThan(65536);
});

it('fails loudly when git fails, so a key is never guessed', function () {
    expect(fn () => pipeline_tree_key(sys_get_temp_dir() . '/not-a-repo-' . uniqid()))->toThrow(RuntimeException::class);
});
