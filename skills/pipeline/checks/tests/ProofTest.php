<?php

it('slugs a branch into one safe path segment', function () {
    expect(proof_slug('feature/orders-export'))->toBe('feature-orders-export');
    expect(proof_slug('bugfix/ISSUE-42/retry'))->toBe('bugfix-ISSUE-42-retry');
});

it('refuses to let a branch name escape its own directory', function () {
    // A careless branch name must never write outside the run's folder.
    $slug = proof_slug('feature/../../etc/passwd');

    expect($slug)->not->toContain('..');
    expect($slug)->not->toContain('/');
});

it('builds a run directory keyed by repo, never by the run segment alone', function () {
    // PR numbers collide across the ~20 repos that share this store, exactly as `feature/fix-typo` did.
    $a = proof_run_dir('/store', 'ViewieMedia', 'feature/fix-typo', 412);
    $b = proof_run_dir('/store', 'Deploy', 'feature/fix-typo', 412);

    expect($a)->toBe('/store/ViewieMedia/pr-412-fix-typo');
    expect($a)->not->toBe($b);
});

it('keys a run by its PR number and keeps the branch topic readable beside it', function () {
    // A bare `967` sorts but names nothing; the topic without the namespace and the issue
    // marker names it without putting a second number in the same segment.
    expect(proof_run_slug('feature/issue-919-body-margin-sweep', 967))->toBe('pr-967-body-margin-sweep');
    expect(proof_run_slug('feature/reverb-service-type', 404))->toBe('pr-404-reverb-service-type');
    expect(proof_run_slug('hotfix/ISSUE_42_retry', 7))->toBe('pr-7-retry');
});

it('falls back to the branch slug for a run that opened no PR', function () {
    // review-plan bound-exhaustion halts before handoff, so those runs have only a branch name.
    expect(proof_run_slug('feature/issue-919-body-margin-sweep'))->toBe('feature-issue-919-body-margin-sweep');
    expect(proof_run_slug('feature/x', null))->toBe('feature-x');
    expect(proof_run_slug('feature/x', ''))->toBe('feature-x');
    expect(proof_run_dir('/store', 'Deploy', 'feature/halted'))->toBe('/store/Deploy/feature-halted');
});

it('keeps a PR-keyed run segment inside its own directory', function () {
    expect(proof_run_slug('feature/../../etc/passwd', 5))->not->toContain('..');
    expect(proof_run_slug('feature/../../etc/passwd', 5))->not->toContain('/');
});

it('round-trips a run and preserves createdAt across the second write', function () {
    $dir = sys_get_temp_dir() . '/proof-' . uniqid() . '/ViewieMedia/feature-x';

    $first = proof_write_run($dir, ['repo' => 'ViewieMedia', 'pr' => 412], '2026-08-25T10:00:00+02:00');
    expect($first['createdAt'])->toBe('2026-08-25T10:00:00+02:00');
    expect($first['schema'])->toBe(1);

    $second = proof_write_run($dir, ['repo' => 'ViewieMedia', 'pr' => 412], '2026-08-25T15:30:00+02:00');
    expect($second['createdAt'])->toBe('2026-08-25T10:00:00+02:00');
    expect($second['updatedAt'])->toBe('2026-08-25T15:30:00+02:00');

    expect(proof_read_run($dir))->toBe($second);

    unlink($dir . '/run.json');
});

it('returns null for a directory holding no run', function () {
    expect(proof_read_run(sys_get_temp_dir() . '/proof-missing-' . uniqid()))->toBeNull();
});

it('honours the store-root override so tests never touch the real store', function () {
    putenv('PIPELINE_PROOF_ROOT=/tmp/proof-test-root');
    expect(proof_root())->toBe('/tmp/proof-test-root');

    putenv('PIPELINE_PROOF_ROOT');
    expect(proof_root())->toEndWith('/GitProjects/_proofs');
});

it('prunes a run only once its PR is finished and has been finished a while', function () {
    $now = '2026-08-25T12:00:00+00:00';
    $old = '2026-08-01T12:00:00+00:00';   // 24 days before $now
    $recent = '2026-08-20T12:00:00+00:00'; // 5 days before $now

    expect(proof_should_prune(['pr' => 412, 'prState' => 'MERGED', 'updatedAt' => $old], $now))->toBeTrue();
    expect(proof_should_prune(['pr' => 412, 'prState' => 'CLOSED', 'updatedAt' => $old], $now))->toBeTrue();

    // A PR merged this morning is exactly the one still worth looking at this afternoon.
    expect(proof_should_prune(['pr' => 412, 'prState' => 'MERGED', 'updatedAt' => $recent], $now))->toBeFalse();
});

it('never prunes an open PR, and never prunes a run that opened none', function () {
    $now = '2026-08-25T12:00:00+00:00';
    $old = '2026-08-01T12:00:00+00:00';

    expect(proof_should_prune(['pr' => 412, 'prState' => 'OPEN', 'updatedAt' => $old], $now))->toBeFalse();

    // review-plan bound-exhaustion halts before handoff and opens no PR. Those runs are
    // flagged in the index for manual pruning, never deleted automatically.
    expect(proof_should_prune(['prState' => 'MERGED', 'updatedAt' => $old], $now))->toBeFalse();
    expect(proof_should_prune(['pr' => null, 'prState' => 'MERGED', 'updatedAt' => $old], $now))->toBeFalse();
});

it('never prunes on unusable timestamps', function () {
    $now = '2026-08-25T12:00:00+00:00';

    expect(proof_should_prune(['pr' => 1, 'prState' => 'MERGED', 'updatedAt' => 'not a date'], $now))->toBeFalse();
    expect(proof_should_prune(['pr' => 1, 'prState' => 'MERGED'], $now))->toBeFalse();
    expect(proof_should_prune(['pr' => 1, 'prState' => 'MERGED', 'updatedAt' => '2026-08-01T12:00:00+00:00'], 'nonsense'))->toBeFalse();
});

it('scans every run in the store, newest first', function () {
    $root = sys_get_temp_dir() . '/proof-scan-' . uniqid();

    proof_write_run($root . '/Deploy/feature-a', ['repo' => 'Deploy'], '2026-08-20T10:00:00+00:00');
    proof_write_run($root . '/ViewieMedia/feature-b', ['repo' => 'ViewieMedia'], '2026-08-24T10:00:00+00:00');

    $runs = proof_scan_runs($root);

    expect($runs)->toHaveCount(2);
    expect($runs[0]['run']['repo'])->toBe('ViewieMedia');
    expect($runs[1]['run']['repo'])->toBe('Deploy');
    expect($runs[0]['dir'])->toBe($root . '/ViewieMedia/feature-b');
});

it('scans an empty or missing store without failing', function () {
    expect(proof_scan_runs(sys_get_temp_dir() . '/proof-empty-' . uniqid()))->toBe([]);
});
