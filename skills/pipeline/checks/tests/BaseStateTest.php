<?php

function base_identity(string $dir): void
{
    foreach ([['config', 'user.email', 'test@example.com'], ['config', 'user.name', 'Test'], ['config', 'commit.gpgsign', 'false']] as $args) {
        pipeline_git($dir, $args);
    }
}

/**
 * A clone of a bare `origin` whose `main` holds `$files`, on a `feature` branch cut from it, with
 * `origin/HEAD` as a clone sets it. Unlike `rereview_repo()` it has a remote, so a fetch works.
 */
function base_repo(array $files = []): string
{
    $seed = suite_repo();
    pipeline_git($seed, ['branch', '-M', 'main']);
    if ($files !== []) {
        rereview_commit($seed, $files, 'base');
    }
    $root = sys_get_temp_dir() . '/pipeline-base-' . uniqid();
    mkdir($root);
    pipeline_git($root, ['clone', '-q', '--bare', $seed, "{$root}/origin.git"]);
    pipeline_git($root, ['clone', '-q', "{$root}/origin.git", "{$root}/work"]);
    base_identity("{$root}/work");
    pipeline_git("{$root}/work", ['switch', '-q', '-c', 'feature']);

    return "{$root}/work";
}

/** Another clone commits `$files` on `$branch` and pushes: origin moves, and the run's checkout has not fetched it. */
function base_moves(string $dir, array $files, string $branch = 'main'): void
{
    $root = dirname($dir);
    $other = "{$root}/other-" . uniqid();
    pipeline_git($root, ['clone', '-q', "{$root}/origin.git", $other]);
    base_identity($other);
    pipeline_git($other, ['switch', '-q', $branch]);
    rereview_commit($other, $files, 'base work');
    pipeline_git($other, ['push', '-q', 'origin', $branch]);
}

function base_state(string $dir, array $extra = []): ?array
{
    $manifest = ['branch' => 'feature', 'worktree' => $dir, 'mode' => 'autoflow', 'cursor' => ['leg' => 'implement', 'status' => 'pending'], ...$extra];

    return pipeline_base_state($manifest, fn (array $args) => pipeline_git_run($dir, $args));
}

it('names the six steps that write to the branch as the ones that catch up', function () {
    expect(PIPELINE_CATCH_UP_STEPS)->toBe(['design:run', 'design:spec', 'design:plan', 'review-plan:resolve', 'implement:run', 'review-pr:resolve']);
});

it('asks for no merge while the branch is not behind its base', function () {
    $dir = base_repo();

    expect(base_state($dir))->toBeNull();
    rereview_commit($dir, ['feature.php' => "<?php\n"]);
    expect(base_state($dir))->toBeNull();
});

it('asks for no merge when the base moved only in files the branch\'s code does not touch', function () {
    $dir = base_repo(['shared.php' => "base\n"]);
    rereview_commit($dir, ['feature.php' => "<?php\n"]);
    base_moves($dir, ['shared.php' => "main\n", 'main-only.php' => "<?php\n"]);

    expect(base_state($dir))->toBeNull();
});

it('asks for a merge, naming the shared files, when the base moved in a file the branch changes, after fetching the base', function () {
    $dir = base_repo(['shared.php' => "base\n", 'other.php' => "base\n"]);
    rereview_commit($dir, ['shared.php' => "feature\n", 'feature.php' => "<?php\n"]);
    base_moves($dir, ['shared.php' => "main\n"]);
    base_moves($dir, ['other.php' => "main\n"]);

    expect(pipeline_git($dir, ['rev-list', '--count', 'HEAD..origin/main']))->toBe('0');
    expect(base_state($dir))->toBe(['base' => 'origin/main', 'behind' => 2, 'shared' => ['shared.php']]);
    expect(pipeline_git($dir, ['rev-list', '--count', 'HEAD..origin/main']))->toBe('2');
});

it('counts a file the branch renamed or deleted as shared when the base changed it', function () {
    $dir = base_repo(['old.php' => "base\n", 'gone.php' => "base\n"]);
    pipeline_git($dir, ['mv', 'old.php', 'new.php']);
    pipeline_git($dir, ['rm', '-q', 'gone.php']);
    pipeline_git($dir, ['commit', '-q', '-m', 'rename and delete']);
    base_moves($dir, ['old.php' => "main\n", 'gone.php' => "main\n"]);

    expect(base_state($dir))->toBe(['base' => 'origin/main', 'behind' => 1, 'shared' => ['gone.php', 'old.php']]);
});

it('asks for a merge on any base movement while the branch holds nothing, or only its spec and plan', function () {
    $artifacts = ['artifacts' => ['spec' => 'docs/spec.md', 'plan' => 'docs/plan.md']];

    $empty = base_repo();
    base_moves($empty, ['main-only.php' => "<?php\n"]);
    expect(base_state($empty))->toBe(['base' => 'origin/main', 'behind' => 1, 'shared' => []]);

    $design = base_repo();
    mkdir("{$design}/docs");
    rereview_commit($design, ['docs/spec.md' => "# spec\n", 'docs/plan.md' => "# plan\n"]);
    base_moves($design, ['main-only.php' => "<?php\n"]);
    expect(base_state($design, $artifacts))->toBe(['base' => 'origin/main', 'behind' => 1, 'shared' => []]);
    expect(base_state($design, ['artifacts' => ['spec' => "{$design}/docs/spec.md", 'plan' => "{$design}/docs/plan.md"]]))
        ->toBe(['base' => 'origin/main', 'behind' => 1, 'shared' => []]);

    rereview_commit($design, ['feature.php' => "<?php\n"]);
    expect(base_state($design, $artifacts))->toBeNull();
});

it('measures against the run\'s base when the manifest has one, and against origin/HEAD otherwise', function () {
    $dir = base_repo();
    pipeline_git($dir, ['push', '-q', 'origin', 'HEAD:refs/heads/integration']);
    base_moves($dir, ['integration-only.php' => "<?php\n"], 'integration');

    expect(base_state($dir, ['base' => 'integration']))->toBe(['base' => 'origin/integration', 'behind' => 1, 'shared' => []]);
    expect(base_state($dir))->toBeNull();
});

it('asks for no merge when the base does not resolve, the fetch fails, or git cannot run', function () {
    $dir = base_repo();
    base_moves($dir, ['main-only.php' => "<?php\n"]);

    expect(base_state($dir, ['base' => 'gone']))->toBeNull();
    expect(pipeline_base_state(['worktree' => "{$dir}/missing"], fn (array $args) => pipeline_git_run("{$dir}/missing", $args)))->toBeNull();

    $offline = base_repo();
    base_moves($offline, ['main-only.php' => "<?php\n"]);
    pipeline_git($offline, ['remote', 'set-url', 'origin', dirname($offline) . '/moved.git']);
    expect(base_state($offline))->toBeNull();

    pipeline_git($dir, ['symbolic-ref', '--delete', 'refs/remotes/origin/HEAD']);
    expect(base_state($dir))->toBeNull();
});
