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

it('builds a run directory keyed by repo and branch, never by branch alone', function () {
    // Branch alone collides across ~20 repos that all grow a `feature/fix-typo`.
    $a = proof_run_dir('/store', 'ViewieMedia', 'feature/fix-typo');
    $b = proof_run_dir('/store', 'Deploy', 'feature/fix-typo');

    expect($a)->toBe('/store/ViewieMedia/feature-fix-typo');
    expect($a)->not->toBe($b);
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
