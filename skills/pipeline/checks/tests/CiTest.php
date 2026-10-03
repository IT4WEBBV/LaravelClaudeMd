<?php

function ci_run(string $name, string $status, string $conclusion = '', string $workflow = 'CI'): array
{
    return ['__typename' => 'CheckRun', 'name' => $name, 'workflowName' => $workflow, 'status' => $status, 'conclusion' => $conclusion, 'detailsUrl' => "https://github.com/acme/app/actions/runs/11/job/{$name}"];
}

function ci_status(string $context, string $state): array
{
    return ['__typename' => 'StatusContext', 'context' => $context, 'state' => $state, 'targetUrl' => "https://ci.example/{$context}"];
}

function ci_manifest(array $decisions = []): array
{
    return ['branch' => 'feature/x', 'worktree' => '/tmp/wt', 'mode' => 'autoflow', 'cursor' => ['leg' => 'review-pr', 'status' => 'done'], 'artifacts' => ['pr' => 7], 'decisions' => $decisions];
}

function ci_view(array $rollup, string $mergeable = 'MERGEABLE'): array
{
    return ['headRefOid' => 'abc123', 'mergeable' => $mergeable, 'statusCheckRollup' => $rollup];
}

it('classifies a check run by its status and conclusion, and a commit status by its state', function (array $item, string $state) {
    expect(pipeline_ci_check($item)['state'])->toBe($state);
})->with([
    'queued' => [ci_run('ci', 'QUEUED'), 'pending'],
    'in progress' => [ci_run('ci', 'IN_PROGRESS'), 'pending'],
    'success' => [ci_run('ci', 'COMPLETED', 'SUCCESS'), 'green'],
    'neutral' => [ci_run('ci', 'COMPLETED', 'NEUTRAL'), 'green'],
    'skipped' => [ci_run('ci', 'COMPLETED', 'SKIPPED'), 'green'],
    'failure' => [ci_run('ci', 'COMPLETED', 'FAILURE'), 'red'],
    'timed out' => [ci_run('ci', 'COMPLETED', 'TIMED_OUT'), 'red'],
    'cancelled' => [ci_run('ci', 'COMPLETED', 'CANCELLED'), 'red'],
    'a status that passed' => [ci_status('ext', 'SUCCESS'), 'green'],
    'a pending status' => [ci_status('ext', 'PENDING'), 'pending'],
    'an expected status' => [ci_status('ext', 'EXPECTED'), 'pending'],
    'a status in error' => [ci_status('ext', 'ERROR'), 'red'],
    'a failed status' => [ci_status('ext', 'FAILURE'), 'red'],
]);

it('names a check run by its workflow and a status by its context, with their links', function () {
    expect(pipeline_ci_check(ci_run('ci', 'COMPLETED', 'FAILURE')))->toBe(['name' => 'CI / ci', 'link' => 'https://github.com/acme/app/actions/runs/11/job/ci', 'state' => 'red']);
    expect(pipeline_ci_check(ci_run('ci', 'COMPLETED', 'FAILURE', ''))['name'])->toBe('ci');
    expect(pipeline_ci_check(ci_status('ext', 'ERROR')))->toBe(['name' => 'ext', 'link' => 'https://ci.example/ext', 'state' => 'red']);
});

it('reads no checks as none, a red check over a pending one as red, and all finished and passing as green', function () {
    expect(pipeline_ci_verdict([]))->toBe(['verdict' => 'none', 'failing' => [], 'pending' => []]);
    expect(pipeline_ci_verdict([ci_run('ci', 'IN_PROGRESS'), ci_run('validate', 'COMPLETED', 'FAILURE', 'Validate')]))
        ->toBe(['verdict' => 'red', 'failing' => [['name' => 'Validate / validate', 'link' => 'https://github.com/acme/app/actions/runs/11/job/validate']], 'pending' => ['CI / ci']]);
    expect(pipeline_ci_verdict([ci_run('ci', 'IN_PROGRESS'), ci_run('validate', 'COMPLETED', 'SUCCESS')])['verdict'])->toBe('pending');
    expect(pipeline_ci_verdict([ci_run('ci', 'COMPLETED', 'SUCCESS'), ci_run('notes', 'COMPLETED', 'SKIPPED')])['verdict'])->toBe('green');
});

it('answers ready on green, and on no checks at once without workflows or from the third read with them', function () {
    expect(pipeline_ci_answer(ci_manifest(), ci_view([ci_run('ci', 'COMPLETED', 'SUCCESS')]), 'abc123', true, 1))->toBe(['action' => 'ready', 'verdict' => 'green', 'sha' => 'abc123']);
    expect(pipeline_ci_answer(ci_manifest(), ci_view([]), 'abc123', false, 1))->toBe(['action' => 'ready', 'verdict' => 'none', 'sha' => 'abc123']);
    expect(pipeline_ci_answer(ci_manifest(), ci_view([]), 'abc123', true, 2))->toBe(['action' => 'wait', 'verdict' => 'none', 'sha' => 'abc123']);
    expect(pipeline_ci_answer(ci_manifest(), ci_view([]), 'abc123', true, 3))->toBe(['action' => 'ready', 'verdict' => 'none', 'sha' => 'abc123']);
});

it('waits on pending checks and on a PR gh cannot read, and halts at the hour', function () {
    $pending = ci_view([ci_run('ci', 'IN_PROGRESS')]);

    expect(pipeline_ci_answer(ci_manifest(), $pending, 'abc123', true, 119))->toBe(['action' => 'wait', 'verdict' => 'pending', 'sha' => 'abc123']);
    expect(pipeline_ci_answer(ci_manifest(), $pending, 'abc123', true, 120))->toBe(['action' => 'halt', 'leg' => 'review-pr', 'reason' => 'CI on abc123 has not finished after an hour: CI / ci', 'verdict' => 'pending', 'sha' => 'abc123']);
    expect(pipeline_ci_answer(ci_manifest(), null, 'abc123', true, 119))->toBe(['action' => 'wait', 'verdict' => 'unreadable']);
    expect(pipeline_ci_answer(ci_manifest(), null, 'abc123', true, 120))->toBe(['action' => 'halt', 'leg' => 'review-pr', 'reason' => 'CI on PR #7 had not settled after an hour, and gh could not read its checks at the last read', 'verdict' => 'unreadable']);
});

it('answers one fix round on red, with the failures verbatim as its decision, and halts on red after it', function () {
    $red = ci_view([ci_run('ci', 'COMPLETED', 'FAILURE'), ci_run('validate', 'COMPLETED', 'TIMED_OUT', 'Validate'), ci_run('notes', 'COMPLETED', 'SUCCESS')]);
    $failing = [
        ['name' => 'CI / ci', 'link' => 'https://github.com/acme/app/actions/runs/11/job/ci'],
        ['name' => 'Validate / validate', 'link' => 'https://github.com/acme/app/actions/runs/11/job/validate'],
    ];
    $failures = 'CI / ci failed (https://github.com/acme/app/actions/runs/11/job/ci); Validate / validate failed (https://github.com/acme/app/actions/runs/11/job/validate)';
    $decision = "CI red on the PR's head commit abc123: {$failures}";

    expect(pipeline_ci_answer(ci_manifest(['Keep the guard']), $red, 'abc123', true, 1))
        ->toBe(['action' => 'fix', 'verdict' => 'red', 'sha' => 'abc123', 'failing' => $failing, 'decision' => $decision]);
    expect(pipeline_ci_answer(ci_manifest(['Keep the guard', $decision]), $red, 'abc123', true, 1))
        ->toBe(['action' => 'halt', 'leg' => 'review-pr', 'reason' => "CI red again after the fix round, on abc123: {$failures}", 'verdict' => 'red', 'sha' => 'abc123', 'failing' => $failing]);
});

it('answers mismatch while GitHub\'s head is not the worktree\'s HEAD, before any verdict, and halts with both shas at the third read', function () {
    $mismatch = ['action' => 'wait', 'verdict' => 'mismatch', 'sha' => 'abc123', 'head' => 'def456'];
    $reason = "PR #7's head on GitHub is abc123, but the worktree's HEAD is def456: the two must match before its checks count; push the branch, or reconcile it when GitHub is ahead, and run the CI gate again";
    $green = ci_view([ci_run('ci', 'COMPLETED', 'SUCCESS')]);

    expect(pipeline_ci_answer(ci_manifest(), $green, 'def456', true, 1))->toBe($mismatch);
    expect(pipeline_ci_answer(ci_manifest(), $green, 'def456', true, 2))->toBe($mismatch);
    expect(pipeline_ci_answer(ci_manifest(), $green, 'def456', true, 3))
        ->toBe(['action' => 'halt', 'leg' => 'review-pr', 'reason' => $reason, 'verdict' => 'mismatch', 'sha' => 'abc123', 'head' => 'def456']);
    expect(pipeline_ci_answer(ci_manifest(), ci_view([ci_run('ci', 'COMPLETED', 'FAILURE')]), 'def456', true, 1))->toBe($mismatch);
    expect(pipeline_ci_answer(ci_manifest(), ci_view([]), 'def456', false, 1))->toBe($mismatch);
    expect(pipeline_ci_answer(ci_manifest(), null, 'def456', true, 1))->toBe(['action' => 'wait', 'verdict' => 'unreadable']);
});

it('counts the recorded CI failures in decisions, and nothing else', function () {
    expect(pipeline_ci_rounds(ci_manifest(['Keep the guard'])))->toBe(0);
    expect(pipeline_ci_rounds(ci_manifest(['Keep the guard', "CI red on the PR's head commit abc123: CI / ci failed (x)"])))->toBe(1);
    expect(pipeline_ci_rounds(['branch' => 'feature/x']))->toBe(0);
});

it('answers one review round for a merge the last review did not see, before the head or the checks are read', function () {
    $decision = "Unreviewed merge on the PR's head commit def456: a merge since the last completed review met this branch's changes in a.php, b.php";
    $fix = ['action' => 'fix', 'verdict' => 'merge', 'files' => ['a.php', 'b.php'], 'decision' => $decision];
    $green = ci_view([ci_run('ci', 'COMPLETED', 'SUCCESS')]);

    expect(pipeline_ci_answer(ci_manifest(), $green, 'def456', true, 1, ['a.php', 'b.php']))->toBe($fix);
    expect(pipeline_ci_answer(ci_manifest(), null, 'def456', true, 120, ['a.php', 'b.php']))->toBe($fix);
    expect(pipeline_ci_answer(ci_manifest(), $green, 'abc123', true, 1, []))->toBe(['action' => 'ready', 'verdict' => 'green', 'sha' => 'abc123']);
    expect(pipeline_ci_answer(ci_manifest([$decision]), $green, 'abc123', true, 1, ['a.php']))->toBe(['action' => 'ready', 'verdict' => 'green', 'sha' => 'abc123']);
});

it('answers ask while a blocking open question is unanswered, before a merge round, a mismatch, an unreadable PR or a green head (#146)', function () {
    $questions = [[
        'id' => 'gate_ledger[0].actions[0]', 'gate' => 'pr-review', 'kind' => 'blocking', 'question' => 'Queue or cron?', 'note' => 'cron (built), queue',
        'decision' => 'Answer to open question gate_ledger[0].actions[0] ("Queue or cron?"): ',
    ]];
    $ask = ['action' => 'ask', 'questions' => $questions];
    $green = ci_view([ci_run('ci', 'COMPLETED', 'SUCCESS')]);

    expect(pipeline_ci_answer(ci_manifest(), $green, 'def456', true, 1, ['a.php'], $questions))->toBe($ask);
    expect(pipeline_ci_answer(ci_manifest(), $green, 'def456', true, 3, [], $questions))->toBe($ask);
    expect(pipeline_ci_answer(ci_manifest(), null, 'abc123', true, 120, [], $questions))->toBe($ask);
    expect(pipeline_ci_answer(ci_manifest(), $green, 'abc123', true, 1, [], $questions))->toBe($ask);
    expect(pipeline_ci_answer(ci_manifest(), $green, 'abc123', true, 1, [], []))->toBe(['action' => 'ready', 'verdict' => 'green', 'sha' => 'abc123']);
});

it('counts the merge round and the CI fix round apart', function () {
    $merge = "Unreviewed merge on the PR's head commit def456: a merge since the last completed review met this branch's changes in a.php";
    $ci = "CI red on the PR's head commit abc123: CI / ci failed (https://github.com/acme/app/actions/runs/11/job/ci)";
    $red = ci_view([ci_run('ci', 'COMPLETED', 'FAILURE')]);

    expect(pipeline_merge_rounds(ci_manifest(['Keep the guard'])))->toBe(0);
    expect(pipeline_merge_rounds(ci_manifest([$ci])))->toBe(0);
    expect(pipeline_merge_rounds(ci_manifest([$merge])))->toBe(1);
    expect(pipeline_merge_rounds(['branch' => 'feature/x']))->toBe(0);
    expect(pipeline_ci_rounds(ci_manifest([$merge])))->toBe(0);

    expect(pipeline_ci_answer(ci_manifest([$merge]), $red, 'abc123', true, 1, ['a.php']))->toMatchArray(['action' => 'fix', 'verdict' => 'red', 'decision' => $ci]);
    expect(pipeline_ci_answer(ci_manifest([$ci]), $red, 'abc123', true, 1, ['a.php']))->toMatchArray(['action' => 'fix', 'verdict' => 'merge']);
});

function ci_conflict_decision(): string
{
    return "Conflict with the base on the PR's head commit abc123: GitHub reports PR #7 CONFLICTING with its base; review-pr's resolve step merges the base (shared/catch-up.md)";
}

it('answers one conflict round on a PR that conflicts with its base, whatever its checks, and halts on a conflict after it (#149)', function () {
    $fix = ['action' => 'fix', 'verdict' => 'conflicting', 'sha' => 'abc123', 'decision' => ci_conflict_decision()];
    $reason = 'PR #7 conflicts with its base again after the conflict round, on abc123: merge the base into the branch (shared/catch-up.md), push, and run the CI gate again';

    expect(pipeline_ci_answer(ci_manifest(), ci_view([ci_run('ci', 'COMPLETED', 'SUCCESS')], 'CONFLICTING'), 'abc123', true, 1))->toBe($fix);
    expect(pipeline_ci_answer(ci_manifest(), ci_view([], 'CONFLICTING'), 'abc123', true, 1))->toBe($fix);
    expect(pipeline_ci_answer(ci_manifest(), ci_view([ci_run('ci', 'IN_PROGRESS')], 'CONFLICTING'), 'abc123', true, 1))->toBe($fix);
    expect(pipeline_ci_answer(ci_manifest(), ci_view([ci_run('ci', 'COMPLETED', 'FAILURE')], 'CONFLICTING'), 'abc123', true, 1))->toBe($fix);
    expect(pipeline_ci_answer(ci_manifest(['Keep the guard', ci_conflict_decision()]), ci_view([ci_run('ci', 'COMPLETED', 'SUCCESS')], 'CONFLICTING'), 'abc123', true, 1))
        ->toBe(['action' => 'halt', 'leg' => 'review-pr', 'reason' => $reason, 'verdict' => 'conflicting', 'sha' => 'abc123']);
    expect($reason)->not->toContain("'");
});

it('waits while GitHub has not worked out whether the PR merges, even without checks or workflows, and halts at the hour (#149)', function () {
    $wait = ['action' => 'wait', 'verdict' => 'unknown', 'sha' => 'abc123'];
    $unknown = ci_view([ci_run('ci', 'COMPLETED', 'SUCCESS')], 'UNKNOWN');
    $reason = 'GitHub had not worked out whether PR #7 merges into its base after an hour, on abc123';

    expect(pipeline_ci_answer(ci_manifest(), $unknown, 'abc123', true, 1))->toBe($wait);
    expect(pipeline_ci_answer(ci_manifest(), $unknown, 'abc123', true, 119))->toBe($wait);
    expect(pipeline_ci_answer(ci_manifest(), ci_view([], 'UNKNOWN'), 'abc123', false, 1))->toBe($wait);
    expect(pipeline_ci_answer(ci_manifest(), $unknown, 'abc123', true, 120))
        ->toBe(['action' => 'halt', 'leg' => 'review-pr', 'reason' => $reason, 'verdict' => 'unknown', 'sha' => 'abc123']);
    expect($reason)->not->toContain("'");
});

it('reads mergeability after the merge round and the head comparison, and any other value goes on to the checks (#149)', function () {
    $mismatch = ['action' => 'wait', 'verdict' => 'mismatch', 'sha' => 'abc123', 'head' => 'def456'];
    $merge = "Unreviewed merge on the PR's head commit def456: a merge since the last completed review met this branch's changes in a.php";
    $green = fn (string $mergeable) => ci_view([ci_run('ci', 'COMPLETED', 'SUCCESS')], $mergeable);

    expect(pipeline_ci_answer(ci_manifest(), ci_view([], 'CONFLICTING'), 'def456', true, 1))->toBe($mismatch);
    expect(pipeline_ci_answer(ci_manifest(), ci_view([], 'UNKNOWN'), 'def456', true, 1))->toBe($mismatch);
    expect(pipeline_ci_answer(ci_manifest(), ci_view([], 'CONFLICTING'), 'def456', true, 1, ['a.php']))
        ->toBe(['action' => 'fix', 'verdict' => 'merge', 'files' => ['a.php'], 'decision' => $merge]);
    expect(pipeline_ci_answer(ci_manifest(), $green('SOMETHING_NEW'), 'abc123', true, 1))->toBe(['action' => 'ready', 'verdict' => 'green', 'sha' => 'abc123']);
    expect(pipeline_ci_answer(ci_manifest(), $green(''), 'abc123', true, 1))->toBe(['action' => 'ready', 'verdict' => 'green', 'sha' => 'abc123']);
    expect(pipeline_ci_answer(ci_manifest(), ci_view([ci_run('ci', 'COMPLETED', 'FAILURE')]), 'abc123', true, 1))->toMatchArray(['action' => 'fix', 'verdict' => 'red']);
});

it('counts the conflict round apart from the CI and merge rounds (#149)', function () {
    $merge = "Unreviewed merge on the PR's head commit def456: a merge since the last completed review met this branch's changes in a.php";
    $ci = "CI red on the PR's head commit abc123: CI / ci failed (https://github.com/acme/app/actions/runs/11/job/ci)";

    expect(pipeline_conflict_rounds(ci_manifest(['Keep the guard', $merge, $ci])))->toBe(0);
    expect(pipeline_conflict_rounds(ci_manifest([ci_conflict_decision()])))->toBe(1);
    expect(pipeline_conflict_rounds(['branch' => 'feature/x']))->toBe(0);
    expect(pipeline_ci_rounds(ci_manifest([ci_conflict_decision()])))->toBe(0);
    expect(pipeline_merge_rounds(ci_manifest([ci_conflict_decision()])))->toBe(0);

    expect(pipeline_ci_answer(ci_manifest([ci_conflict_decision()]), ci_view([ci_run('ci', 'COMPLETED', 'FAILURE')]), 'abc123', true, 1))
        ->toMatchArray(['action' => 'fix', 'verdict' => 'red']);
    expect(pipeline_ci_answer(ci_manifest([$ci, $merge]), ci_view([], 'CONFLICTING'), 'abc123', true, 1, ['a.php']))
        ->toMatchArray(['action' => 'fix', 'verdict' => 'conflicting']);
});

it('counts a conflict round recorded before the decision cited shared/catch-up.md (#128)', function () {
    $old = "Conflict with the base on the PR's head commit abc123: GitHub reports PR #7 CONFLICTING with its base; review-pr's resolve step merges the base (engine.md §Catching up with the base)";

    expect(pipeline_conflict_rounds(ci_manifest([$old])))->toBe(1);
});
