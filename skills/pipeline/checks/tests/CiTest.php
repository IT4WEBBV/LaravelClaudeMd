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

function ci_view(array $rollup): array
{
    return ['headRefOid' => 'abc123', 'statusCheckRollup' => $rollup];
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
    expect(pipeline_ci_answer(ci_manifest(), ci_view([ci_run('ci', 'COMPLETED', 'SUCCESS')]), true, 1))->toBe(['action' => 'ready', 'verdict' => 'green', 'sha' => 'abc123']);
    expect(pipeline_ci_answer(ci_manifest(), ci_view([]), false, 1))->toBe(['action' => 'ready', 'verdict' => 'none', 'sha' => 'abc123']);
    expect(pipeline_ci_answer(ci_manifest(), ci_view([]), true, 2))->toBe(['action' => 'wait', 'verdict' => 'none', 'sha' => 'abc123']);
    expect(pipeline_ci_answer(ci_manifest(), ci_view([]), true, 3))->toBe(['action' => 'ready', 'verdict' => 'none', 'sha' => 'abc123']);
});

it('waits on pending checks and on a PR gh cannot read, and halts at the hour', function () {
    $pending = ci_view([ci_run('ci', 'IN_PROGRESS')]);

    expect(pipeline_ci_answer(ci_manifest(), $pending, true, 119))->toBe(['action' => 'wait', 'verdict' => 'pending', 'sha' => 'abc123']);
    expect(pipeline_ci_answer(ci_manifest(), $pending, true, 120))->toBe(['action' => 'halt', 'leg' => 'review-pr', 'reason' => 'CI on abc123 has not finished after an hour: CI / ci', 'verdict' => 'pending', 'sha' => 'abc123']);
    expect(pipeline_ci_answer(ci_manifest(), null, true, 119))->toBe(['action' => 'wait', 'verdict' => 'unreadable']);
    expect(pipeline_ci_answer(ci_manifest(), null, true, 120))->toBe(['action' => 'halt', 'leg' => 'review-pr', 'reason' => 'the checks of PR #7 could not be read for an hour', 'verdict' => 'unreadable']);
});

it('answers one fix round on red, with the failures verbatim as its decision, and halts on red after it', function () {
    $red = ci_view([ci_run('ci', 'COMPLETED', 'FAILURE'), ci_run('validate', 'COMPLETED', 'TIMED_OUT', 'Validate'), ci_run('notes', 'COMPLETED', 'SUCCESS')]);
    $failing = [
        ['name' => 'CI / ci', 'link' => 'https://github.com/acme/app/actions/runs/11/job/ci'],
        ['name' => 'Validate / validate', 'link' => 'https://github.com/acme/app/actions/runs/11/job/validate'],
    ];
    $failures = 'CI / ci failed (https://github.com/acme/app/actions/runs/11/job/ci); Validate / validate failed (https://github.com/acme/app/actions/runs/11/job/validate)';
    $decision = "CI red on the PR's head commit abc123: {$failures}";

    expect(pipeline_ci_answer(ci_manifest(['Keep the guard']), $red, true, 1))
        ->toBe(['action' => 'fix', 'verdict' => 'red', 'sha' => 'abc123', 'failing' => $failing, 'decision' => $decision]);
    expect(pipeline_ci_answer(ci_manifest(['Keep the guard', $decision]), $red, true, 1))
        ->toBe(['action' => 'halt', 'leg' => 'review-pr', 'reason' => "CI red again after the fix round, on abc123: {$failures}", 'verdict' => 'red', 'sha' => 'abc123', 'failing' => $failing]);
});

it('counts the recorded CI failures in decisions, and nothing else', function () {
    expect(pipeline_ci_rounds(ci_manifest(['Keep the guard'])))->toBe(0);
    expect(pipeline_ci_rounds(ci_manifest(['Keep the guard', "CI red on the PR's head commit abc123: CI / ci failed (x)"])))->toBe(1);
    expect(pipeline_ci_rounds(['branch' => 'feature/x']))->toBe(0);
});
