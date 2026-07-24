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
