<?php

/** A finished run: its manifest with this ledger, the final diff, and a transcript dir whose journal holds these step returns. */
function audit_run(array $ledger, string $diff, array $reports): array
{
    $agents = [];
    foreach ($reports as $index => [$label, $result]) {
        $agents["a{$index}"] = [$label, '', $result];
    }
    $dir = cost_run($agents);
    $manifest = dirname($dir) . '/feature-x.json';
    manifest_write($manifest, ['branch' => 'feature/x', 'worktree' => dirname($dir), 'mode' => 'autoflow', 'cursor' => ['leg' => 'review-pr', 'status' => 'done'], 'gate_ledger' => $ledger]);
    file_put_contents(dirname($dir) . '/final.diff', $diff);

    return checks_cli('run_audit.php', [$manifest, dirname($dir) . '/final.diff', $dir]);
}

$entry = fn (string $gate, string $leg, int $cycle, string $outcome) => ['gate' => $gate, 'leg' => $leg, 'cycle' => $cycle, 'at' => "2026-09-23T0{$cycle}:00:00Z", 'review' => 'r', 'outcome' => $outcome];
$chain = [
    ['design:run', ['status' => 'continued']],
    ['review-plan:review', ['status' => 'continued']],
    ['review-plan:resolve', ['status' => 'looped-back']],
    ['design:run', ['status' => 'continued']],
    ['review-plan:review', ['status' => 'continued']],
    ['review-plan:resolve', ['status' => 'continued']],
    ['handoff:run', ['status' => 'continued']],
    ['implement:run', ['status' => 'continued', 'ui' => false]],
    ['review-pr:review', ['status' => 'continued']],
    ['review-pr:resolve', ['status' => 'continued']],
];
$uiDiff = "+++ b/resources/views/x.blade.php\n@@ -1,0 +1,1 @@\n+<div>hi</div>\n";

it('reports a run whose ledger agrees with every step', function () use ($entry, $chain) {
    $ledger = [$entry('plan-approval', 'review-plan', 1, 'looped-back'), $entry('plan-approval', 'review-plan', 2, 'continued'), $entry('pr-review', 'review-pr', 1, 'continued')];

    expect(audit_run($ledger, '', $chain))->toBe(['code' => 0, 'stdout' => implode("\n", [
        'ui: the final diff does not touch the UI, the ledger has no verify-ui entry — agree',
        "review-plan: the steps reported [looped-back, continued], the ledger's newest entries say [looped-back, continued] — agree",
        "verify-ui: the steps reported [], the ledger's newest entries say [] — agree",
        "review-pr: the steps reported [continued], the ledger's newest entries say [continued] — agree",
        "plan gaps: the steps reported [], the ledger's newest entries say [] — agree",
    ])]);
});

it('flags a UI diff without a verify-ui entry, and a gate whose ledger says otherwise', function () use ($entry, $chain, $uiDiff) {
    $ledger = [$entry('plan-approval', 'review-plan', 1, 'looped-back'), $entry('plan-approval', 'review-plan', 2, 'continued'), $entry('pr-review', 'review-pr', 1, 'looped-back')];
    $lines = explode("\n", audit_run($ledger, $uiDiff, $chain)['stdout']);

    expect($lines[0])->toBe('ui: the final diff touches the UI, the ledger has no verify-ui entry — MISMATCH');
    expect($lines[3])->toBe("review-pr: the steps reported [continued], the ledger's newest entries say [looped-back] — MISMATCH");
});

it('compares a reported plan gap with the ledger\'s newest gap', function () use ($entry) {
    $reports = [['implement:run', ['status' => 'plan-insufficient', 'reason' => 'needs a queue', 'ui' => false]], ['design:run', null]];
    $gap = ['gate' => 'plan-approval', 'leg' => 'implement', 'cycle' => 2, 'at' => '2026-09-23T05:00:00Z', 'reason' => 'needs a queue', 'outcome' => 'looped-back'];

    expect(explode("\n", audit_run([$entry('plan-approval', 'review-plan', 1, 'continued'), $gap], '', $reports)['stdout'])[4])
        ->toBe("plan gaps: the steps reported [implement], the ledger's newest entries say [implement] — agree");
    expect(explode("\n", audit_run([$entry('plan-approval', 'review-plan', 1, 'continued')], '', $reports)['stdout'])[4])
        ->toBe("plan gaps: the steps reported [implement], the ledger's newest entries say [] — MISMATCH");
});

it('says so when an input is missing, and exits 0', function () {
    expect(checks_cli('run_audit.php', ['/nonexistent/m.json', '/nonexistent/d.diff', '/nonexistent/wf_x']))
        ->toBe(['code' => 0, 'stdout' => 'run audit: not performed (usage: run_audit.php <manifest> <final PR diff> <run transcript dir>; each must exist)']);
});
