<?php

// Load whichever pipeline check functions exist so far (added across Phase A tasks),
// so each task's "watch it fail" is an undefined-function failure, not a missing-file fatal.
require_once __DIR__ . '/../../../critique/checks/diff_parse.php'; // reuse the tested parser
foreach (['triggers.php', 'pipeline.php', 'manifest.php', 'checks.php', 'board.php', 'proof.php', 'proof_render.php', 'proof_tests.php', 'proof_store.php', 'design_size.php', 'suite.php', 'dispatch.php', 'agents.php', 'brief.php', 'record.php', 'run_cost.php', 'gh.php', 'kickoff.php', 'handoff.php', 'ci.php', 'statusline.php'] as $f) {
    $path = __DIR__ . '/../' . $f;
    if (is_file($path)) {
        require_once $path;
    }
}

/**
 * A run filed into a fresh temp store at `<store>/Deploy/pr-5-logs`, its page rendered: the page's path, as
 * `artifacts.proof` holds it. Filed now, so the prune pass's grace period never removes it.
 */
function proof_test_page(array $run = []): string
{
    $dir = sys_get_temp_dir() . '/proof-store-' . uniqid() . '/Deploy/pr-5-logs';
    $filed = proof_write_run($dir, [
        'repo' => 'Deploy', 'branch' => 'feature/logs', 'pr' => 5, 'prState' => 'OPEN', 'title' => 'PR #5: logs that follow', 'schema' => 2,
        ...$run,
    ], date('c'));
    file_put_contents("{$dir}/index.html", proof_render_run($filed));

    return "{$dir}/index.html";
}
