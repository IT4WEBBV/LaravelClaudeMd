<?php

/** A `feature` branch cut from `main`, which holds `$files`; `origin/main` and `origin/HEAD` as a clone has them. */
function rereview_repo(array $files = []): string
{
    $dir = suite_repo();
    pipeline_git($dir, ['branch', '-M', 'main']);
    if ($files !== []) {
        rereview_commit($dir, $files, 'base');
    }
    rereview_publish($dir);
    pipeline_git($dir, ['symbolic-ref', 'refs/remotes/origin/HEAD', 'refs/remotes/origin/main']);
    pipeline_git($dir, ['switch', '-q', '-c', 'feature']);

    return $dir;
}

/** `origin/main` catches up with the local `main`. */
function rereview_publish(string $dir): void
{
    pipeline_git($dir, ['update-ref', 'refs/remotes/origin/main', 'main']);
}

/** Write the files, commit them on the current branch, and return the new HEAD. */
function rereview_commit(string $dir, array $files, string $message = 'work'): string
{
    foreach ($files as $path => $content) {
        file_put_contents("{$dir}/{$path}", $content);
    }
    pipeline_git($dir, ['add', '-A']);
    pipeline_git($dir, ['commit', '-q', '-m', $message]);

    return pipeline_git($dir, ['rev-parse', 'HEAD']);
}

/** Main moves on and is published; the checkout goes back to `feature`. */
function rereview_main_moves(string $dir, array $files): void
{
    pipeline_git($dir, ['switch', '-q', 'main']);
    rereview_commit($dir, $files, 'main work');
    rereview_publish($dir);
    pipeline_git($dir, ['switch', '-q', 'feature']);
}

/** Merge `$ref` into `feature`; `$files` land in the merge commit, resolving a conflict or editing beside it. */
function rereview_merge(string $dir, array $files = [], string $ref = 'origin/main'): string
{
    pipeline_git_run($dir, ['merge', '-q', '--no-ff', '--no-commit', $ref]); // exits 1 on a conflict

    return rereview_commit($dir, $files, "merge {$ref}");
}

/** A `pr-review` entry; a null outcome is an open entry, a null sha none recorded. */
function rereview_entry(?string $outcome, ?string $sha, string $at = '2026-09-25T12:00:00Z'): array
{
    return array_filter([
        'gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => $at, 'review' => 'EARLIER REVIEW TEXT', 'annotations' => [],
        'reviewed_sha' => $sha, 'outcome' => $outcome,
    ], fn ($value) => $value !== null);
}

/** A run on `$dir` whose ledger holds one completed review of the PR at `$sha`. */
function rereview_manifest(string $dir, string $sha, array $extra = []): array
{
    return [
        'branch' => 'feature', 'worktree' => $dir, 'mode' => 'autoflow',
        'cursor' => ['leg' => 'review-pr', 'status' => 'pending'],
        'gate_ledger' => [rereview_entry('continued', $sha)],
        ...$extra,
    ];
}

function rereview_scope(string $dir, array $manifest): ?array
{
    return pipeline_review_scope($manifest, fn (array $args) => pipeline_git_run($dir, $args));
}

it('bases a re-review on the newest completed review of the PR that recorded its commit', function () {
    [$a, $b, $c] = [str_repeat('a', 40), str_repeat('b', 40), str_repeat('c', 40)];
    $planPass = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-25T13:00:00Z', 'reviewed_sha' => $b, 'outcome' => 'continued'];

    expect(pipeline_review_base([]))->toBeNull();
    expect(pipeline_review_base([rereview_entry('continued', $a)]))->toBe($a);
    expect(pipeline_review_base([rereview_entry('continued', $a), rereview_entry('continued', $b, '2026-09-25T13:00:00Z')]))->toBe($b);
    expect(pipeline_review_base([rereview_entry('continued', $a), rereview_entry('halted', $b), rereview_entry('looped-back', $c), rereview_entry(null, $c)]))->toBe($a);
    expect(pipeline_review_base([rereview_entry('continued', $a), rereview_entry('continued', null)]))->toBe($a);
    expect(pipeline_review_base([rereview_entry('halted', $a), $planPass]))->toBeNull();
});

it('does not base a re-review on a review older than the latest escalation or plan gap', function () {
    $sha = str_repeat('a', 40);
    $gap = ['gate' => 'plan-approval', 'leg' => 'review-pr', 'cycle' => 2, 'at' => '2026-09-25T13:00:00Z', 'reason' => 'needs a queue', 'outcome' => 'looped-back'];
    $escalated = ['gate' => 'design-size', 'leg' => 'review-pr', 'at' => '2026-09-25T13:00:00Z', 'reason' => 'migration', 'outcome' => 'escalated'];

    expect(pipeline_review_base([rereview_entry('continued', $sha), $gap]))->toBeNull();
    expect(pipeline_review_base([rereview_entry('continued', $sha), $escalated]))->toBeNull();
    expect(pipeline_review_base([rereview_entry('continued', $sha), $gap, rereview_entry('continued', $sha, '2026-09-25T14:00:00Z')]))->toBe($sha);
});

it('scopes to the branch\'s own commits since the review, leaving out what main brought in', function () {
    $dir = rereview_repo();
    $reviewed = rereview_commit($dir, ['feature.php' => "<?php // v1\n"]);
    rereview_main_moves($dir, ['main.php' => "<?php\n"]);
    rereview_commit($dir, ['feature.php' => "<?php // v2\n"]);
    rereview_merge($dir);
    rereview_commit($dir, ['more.php' => "<?php\n"]);

    expect(rereview_scope($dir, rereview_manifest($dir, $reviewed)))
        ->toBe(['since' => $reviewed, 'base' => 'origin/main', 'commits' => 2, 'files' => []]);
});

it('lists the files where a merge since the review met the branch: merged clean, conflicted, one side taken, or edited in the merge', function () {
    $lines = "a\nb\nc\nd\ne\nf\ng\n";
    $dir = rereview_repo(['clean.php' => $lines, 'conflict.php' => "x\n", 'ours.php' => "x\n", 'edited.php' => "x\n", 'main-only.php' => "x\n", 'branch-only.php' => "x\n"]);
    $reviewed = rereview_commit($dir, ['clean.php' => str_replace('a', 'A', $lines), 'conflict.php' => "feature\n", 'ours.php' => "feature\n", 'branch-only.php' => "feature\n"]);
    rereview_main_moves($dir, ['clean.php' => str_replace('g', 'G', $lines), 'conflict.php' => "main\n", 'ours.php' => "main\n", 'main-only.php' => "main\n"]);
    rereview_merge($dir, ['conflict.php' => "resolved\n", 'ours.php' => "feature\n", 'edited.php' => "edited in the merge\n"]);

    expect(rereview_scope($dir, rereview_manifest($dir, $reviewed)))
        ->toBe(['since' => $reviewed, 'base' => 'origin/main', 'commits' => 0, 'files' => ['clean.php', 'conflict.php', 'edited.php', 'ours.php']]);
});

it('counts a merged-in side\'s own commits that are not on main, as after a pull of the PR branch', function () {
    $dir = rereview_repo();
    $reviewed = rereview_commit($dir, ['feature.php' => "<?php\n"]);
    pipeline_git($dir, ['branch', 'pushed-elsewhere']);
    rereview_commit($dir, ['local.php' => "<?php\n"]);
    pipeline_git($dir, ['switch', '-q', 'pushed-elsewhere']);
    rereview_commit($dir, ['web-edit.php' => "<?php\n"]);
    pipeline_git($dir, ['switch', '-q', 'feature']);
    rereview_merge($dir, [], 'pushed-elsewhere');

    expect(rereview_scope($dir, rereview_manifest($dir, $reviewed)))
        ->toBe(['since' => $reviewed, 'base' => 'origin/main', 'commits' => 2, 'files' => []]);
});

it('scopes to nothing when the branch has not moved since the review', function () {
    $dir = rereview_repo();
    $reviewed = rereview_commit($dir, ['feature.php' => "<?php\n"]);

    expect(rereview_scope($dir, rereview_manifest($dir, $reviewed)))
        ->toBe(['since' => $reviewed, 'base' => 'origin/main', 'commits' => 0, 'files' => []]);
});

it('diffs against the run\'s base when it has one, and against origin/HEAD otherwise', function () {
    $dir = rereview_repo();
    $reviewed = rereview_commit($dir, ['feature.php' => "<?php\n"]);
    rereview_commit($dir, ['more.php' => "<?php\n"]);
    pipeline_git($dir, ['update-ref', 'refs/remotes/origin/integration', 'HEAD']);

    expect(rereview_scope($dir, rereview_manifest($dir, $reviewed, ['base' => 'integration'])))
        ->toBe(['since' => $reviewed, 'base' => 'origin/integration', 'commits' => 0, 'files' => []]);
    expect(rereview_scope($dir, rereview_manifest($dir, $reviewed)))
        ->toBe(['since' => $reviewed, 'base' => 'origin/main', 'commits' => 1, 'files' => []]);
});

it('reviews the whole PR without a completed review, on a sha HEAD does not contain or git does not know, and on a base it cannot resolve', function () {
    $dir = rereview_repo();
    $reviewed = rereview_commit($dir, ['feature.php' => "<?php\n"]);
    pipeline_git($dir, ['switch', '-q', '-c', 'elsewhere', 'main']);
    $elsewhere = rereview_commit($dir, ['other.php' => "<?php\n"]);
    pipeline_git($dir, ['switch', '-q', 'feature']);

    expect(rereview_scope($dir, rereview_manifest($dir, $reviewed, ['gate_ledger' => [rereview_entry('halted', $reviewed)]])))->toBeNull();
    expect(rereview_scope($dir, rereview_manifest($dir, $elsewhere)))->toBeNull();
    expect(rereview_scope($dir, rereview_manifest($dir, str_repeat('0', 40))))->toBeNull();
    expect(rereview_scope($dir, rereview_manifest($dir, $reviewed, ['base' => 'gone'])))->toBeNull();
    expect(rereview_scope($dir . '/missing', rereview_manifest($dir, $reviewed)))->toBeNull();
    pipeline_git($dir, ['symbolic-ref', '--delete', 'refs/remotes/origin/HEAD']);
    expect(rereview_scope($dir, rereview_manifest($dir, $reviewed)))->toBeNull();
});
