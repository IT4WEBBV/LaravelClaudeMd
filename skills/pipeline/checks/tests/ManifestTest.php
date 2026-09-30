<?php

it('round-trips a manifest and reports missing required keys', function () {
    $path = sys_get_temp_dir() . '/pipeline-manifest-' . uniqid() . '.json';
    $data = ['branch' => 'feature/x', 'worktree' => '/tmp/wt', 'mode' => 'interactive', 'cursor' => 'design'];

    manifest_write($path, $data);
    expect(manifest_read($path))->toBe($data);
    expect(manifest_validate($data))->toBe([]);
    expect(manifest_validate(['branch' => 'feature/x']))->toContain('cursor');

    unlink($path);
    expect(manifest_read($path))->toBeNull();
});

it('infers the resume cursor from durable-state probes', function () {
    $base = ['spec' => false, 'plan' => false, 'planApproved' => false, 'pr' => null, 'implemented' => false, 'uiNeeded' => false, 'verifyUi' => false, 'prReviewed' => false];

    expect(manifest_infer_cursor($base))->toBe('design');
    expect(manifest_infer_cursor([...$base, 'spec' => true, 'plan' => true]))->toBe('review-plan');
    expect(manifest_infer_cursor([...$base, 'spec' => true, 'plan' => true, 'planApproved' => true]))->toBe('handoff');
    expect(manifest_infer_cursor([...$base, 'spec' => true, 'plan' => true, 'planApproved' => true, 'pr' => 42]))->toBe('implement');
    expect(manifest_infer_cursor([...$base, 'spec' => true, 'plan' => true, 'planApproved' => true, 'pr' => 42, 'implemented' => true, 'uiNeeded' => true]))->toBe('verify-ui');
    expect(manifest_infer_cursor([...$base, 'spec' => true, 'plan' => true, 'planApproved' => true, 'pr' => 42, 'implemented' => true, 'uiNeeded' => false]))->toBe('review-pr');
    expect(manifest_infer_cursor([...$base, 'spec' => true, 'plan' => true, 'planApproved' => true, 'pr' => 42, 'implemented' => true, 'uiNeeded' => true, 'verifyUi' => true, 'prReviewed' => true]))->toBe('done');
});

it('reads a manifest without a ledger as an empty one', function () {
    $entry = ['gate' => 'plan-approval', 'review' => 'r'];

    expect(pipeline_ledger(['cursor' => []]))->toBe([]);
    expect(pipeline_ledger(['gate_ledger' => [$entry]]))->toBe([$entry]);
});

it('puts a branch\'s manifest where kickoff writes it', function () {
    expect(manifest_path('/w/', 'feature/issue-7-x'))->toBe('/w/.claude/pipeline/feature-issue-7-x.json');
    expect(manifest_path('/w', 'main'))->toBe('/w/.claude/pipeline/main.json');
});

it('calls a run finished on status done, or on the old engine\'s leg done', function () {
    expect(manifest_finished(['cursor' => ['leg' => 'review-pr', 'status' => 'done']]))->toBeTrue();
    expect(manifest_finished(['cursor' => ['leg' => 'done', 'status' => 'continued']]))->toBeTrue();
    expect(manifest_finished(['cursor' => ['leg' => 'implement', 'status' => 'pending']]))->toBeFalse();
});

it('names the files of a run beside its manifest, the review and the actions included', function () {
    expect(manifest_files('/tmp/wt/.claude/pipeline/feature-x.json'))->toBe([
        'brief' => '/tmp/wt/.claude/pipeline/feature-x.brief.md',
        'before' => '/tmp/wt/.claude/pipeline/feature-x.before.json',
        'diff' => '/tmp/wt/.claude/pipeline/feature-x.diff',
        'review' => '/tmp/wt/.claude/pipeline/feature-x.review.md',
        'actions' => '/tmp/wt/.claude/pipeline/feature-x.actions.json',
    ]);
});

it('names a path as git does: relative to the worktree', function () {
    expect(pipeline_relative_path('/tmp/wt', '/tmp/wt/docs/spec.md'))->toBe('docs/spec.md');
    expect(pipeline_relative_path('/tmp/wt/', '/tmp/wt/docs/spec.md'))->toBe('docs/spec.md');
    expect(pipeline_relative_path('/tmp/wt', 'docs/spec.md'))->toBe('docs/spec.md');
    expect(pipeline_relative_path('/tmp/wt', '/tmp/wt-other/docs/spec.md'))->toBe('/tmp/wt-other/docs/spec.md');
});
