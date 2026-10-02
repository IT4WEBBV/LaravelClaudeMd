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
    expect($first['schema'])->toBe(2);

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

it('prunes a finished run with a PR a week after its last filing, by its status or an older run\'s PR state', function (array $run) {
    $now = '2026-10-02T12:00:00+00:00';

    expect(proof_should_prune([...$run, 'pr' => 412, 'updatedAt' => '2026-09-24T12:00:00+00:00'], $now))->toBeTrue();  // 8 days
    // A PR merged this morning is exactly the one still worth looking at this afternoon.
    expect(proof_should_prune([...$run, 'pr' => 412, 'updatedAt' => '2026-09-26T12:00:00+00:00'], $now))->toBeFalse(); // 6 days
    expect(proof_should_prune([...$run, 'pr' => 412, 'updatedAt' => '2026-09-25T12:00:00+00:00'], $now))->toBeFalse(); // exactly 7
})->with([
    'an older merged run' => [['prState' => 'MERGED']],
    'an older closed run' => [['prState' => 'CLOSED']],
    'a merge the watch wrote while gh could not answer' => [['prState' => 'OPEN', 'status' => ['state' => 'merged']]],
    'a stored closed status' => [['status' => ['state' => 'closed']]],
]);

it('never prunes a run with a PR that is not finished, at any age', function (array $run) {
    expect(proof_should_prune([...$run, 'pr' => 412, 'updatedAt' => '2026-09-02T12:00:00+00:00'], '2026-10-02T12:00:00+00:00'))->toBeFalse();
})->with([
    'running' => [['prState' => 'OPEN', 'status' => ['state' => 'running']]],
    'halted' => [['prState' => 'OPEN', 'status' => ['state' => 'halted', 'reason' => 'CI red']]],
    'ready' => [['prState' => 'OPEN', 'status' => ['state' => 'ready']]],
    'an older open run' => [['prState' => 'OPEN']],
    'a stored running status over a merged PR state' => [['prState' => 'MERGED', 'status' => ['state' => 'running']]],
]);

it('prunes a run that opened no PR two weeks after its last filing, whatever its status', function (array $run) {
    $now = '2026-10-02T12:00:00+00:00';

    expect(proof_should_prune([...$run, 'updatedAt' => '2026-09-17T12:00:00+00:00'], $now))->toBeTrue();  // 15 days
    expect(proof_should_prune([...$run, 'updatedAt' => '2026-09-19T12:00:00+00:00'], $now))->toBeFalse(); // 13 days
})->with([
    'no pr key' => [[]],
    'a null pr' => [['pr' => null, 'prState' => null]],
    'an empty pr' => [['pr' => '']],
    'halted before handoff' => [['status' => ['state' => 'halted', 'reason' => 'review-plan bound']]],
    'a stale merged PR state' => [['pr' => null, 'prState' => 'MERGED']],
]);

it('never prunes on unusable timestamps', function () {
    $now = '2026-10-02T12:00:00+00:00';

    expect(proof_should_prune(['pr' => 1, 'prState' => 'MERGED', 'updatedAt' => 'not a date'], $now))->toBeFalse();
    expect(proof_should_prune(['pr' => 1, 'prState' => 'MERGED'], $now))->toBeFalse();
    expect(proof_should_prune(['pr' => 1, 'prState' => 'MERGED', 'updatedAt' => '2026-08-01T12:00:00+00:00'], 'nonsense'))->toBeFalse();
    expect(proof_should_prune(['pr' => null], $now))->toBeFalse();
    expect(proof_should_prune(['updatedAt' => 'not a date'], $now))->toBeFalse();
    expect(proof_should_prune(['updatedAt' => '2026-08-01T12:00:00+00:00'], 'nonsense'))->toBeFalse();
});

it('names the repo a run\'s PR lives in by its nameWithOwner, else by an old-scheme owner/name repo', function (array $run, ?string $nameWithOwner) {
    expect(proof_run_name_with_owner($run))->toBe($nameWithOwner);
})->with([
    'today\'s scheme' => [['nameWithOwner' => 'IT4WEBBV/Deploy', 'repo' => 'Deploy'], 'IT4WEBBV/Deploy'],
    'nameWithOwner wins over an owner/name repo' => [['nameWithOwner' => 'IT4WEBBV/Deploy', 'repo' => 'acme/Other'], 'IT4WEBBV/Deploy'],
    'the old scheme' => [['repo' => 'IT4WEBBV/Deploy'], 'IT4WEBBV/Deploy'],
    'a null nameWithOwner' => [['nameWithOwner' => null, 'repo' => 'IT4WEBBV/Deploy'], 'IT4WEBBV/Deploy'],
    'a blank nameWithOwner' => [['nameWithOwner' => '  ', 'repo' => 'IT4WEBBV/Deploy'], 'IT4WEBBV/Deploy'],
    'whitespace around the old-scheme repo' => [['repo' => ' IT4WEBBV/Deploy '], 'IT4WEBBV/Deploy'],
    'dots, dashes and underscores' => [['repo' => 'it4web-bv/Laravel_Claude.md'], 'it4web-bv/Laravel_Claude.md'],
    'a bare repo' => [['repo' => 'Deploy'], null],
    'two slashes' => [['repo' => 'IT4WEBBV/Deploy/extra'], null],
    'no owner' => [['repo' => '/Deploy'], null],
    'no name' => [['repo' => 'IT4WEBBV/'], null],
    'a character GitHub rejects' => [['repo' => 'IT4WEBBV/Deploy;rm'], null],
    'whitespace inside' => [['repo' => 'IT4WEBBV/My Deploy'], null],
    'neither key' => [[], null],
]);

it('tells the finished statuses from the open ones', function () {
    expect(array_map(fn (ProofRunStatus $status) => $status->finished(), ProofRunStatus::cases()))->toBe([false, false, false, true, true]);
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

it('accepts a run named by a short title, whatever the length of its summary and captions', function () {
    expect(proof_validate_run([
        'title' => 'PR #430: service logs that follow',
        'headline' => str_repeat('A summary sentence of what was verified. ', 20),
        'shots' => [['title' => 'Unreachable swarm', 'caption' => str_repeat('What the shot proves. ', 20), 'state' => 'after']],
    ]))->toBe([]);
});

it('rejects a run without a title, because the heading, the tab and the index all need one', function () {
    $problems = proof_validate_run(['headline' => 'Order rows gain a product summary grid']);

    expect($problems)->toHaveCount(1);
    expect($problems[0])->toContain('title');
});

it('rejects a run title that is a summary rather than a name', function () {
    // Each run modelled its payload on the one before, and the headline grew from 84 to 596
    // characters. The limit is what stops the next copy from growing it again.
    $problems = proof_validate_run(['title' => str_repeat('x', PROOF_TITLE_MAX + 1)]);

    expect($problems)->toHaveCount(1);
    expect($problems[0])->toContain((string) (PROOF_TITLE_MAX + 1));
    expect(proof_validate_run(['title' => str_repeat('é', PROOF_TITLE_MAX)]))->toBe([]);
});

it('rejects a shot title that belongs in its caption', function () {
    $problems = proof_validate_run([
        'title' => 'PR #430: service logs that follow',
        'shots' => [
            ['title' => 'Unreachable swarm', 'state' => 'after'],
            ['title' => str_repeat('x', PROOF_TITLE_MAX + 1), 'state' => 'after'],
        ],
    ]);

    expect($problems)->toHaveCount(1);
    expect($problems[0])->toContain('shot 2');
    expect($problems[0])->toContain('caption');
});

/** A run an agent's `write` may file: title, branch, prose, one shot with a state. */
function proof_prose_run(array $overrides = []): array
{
    return [
        'title' => 'PR #12: logs that follow',
        'branch' => 'feature/issue-12-logs',
        'clientSummary' => 'De servicelogboeken lopen nu live mee, zodat een storing direct zichtbaar is.',
        'explainer' => [
            'problem' => 'The service log stopped at the last line it had.',
            'solution' => 'The log now keeps following as new lines arrive.',
        ],
        'shots' => [['title' => 'Following log', 'state' => 'after']],
        ...$overrides,
    ];
}

/** Both rule sets, as `proof_cli.php write` applies them. */
function proof_all_problems(array $run): array
{
    return [...proof_validate_run($run), ...proof_validate_prose($run)];
}

it('passes a run with a valid summary, explainer and shot states', function () {
    expect(proof_all_problems(proof_prose_run()))->toBe([]);
});

it('refuses each broken rule of the prose and the shots with its own message', function (array $overrides, string $message) {
    $run = proof_prose_run($overrides);

    expect(proof_all_problems(array_filter($run, fn ($value) => $value !== null)))->toBe([$message]);
})->with([
    'no summary' => [['clientSummary' => null], 'clientSummary is missing: one to three Dutch sentences for the hour registration, what the client gets, at most 400 characters'],
    'a blank summary' => [['clientSummary' => "  \n"], 'clientSummary is missing: one to three Dutch sentences for the hour registration, what the client gets, at most 400 characters'],
    'a long summary' => [['clientSummary' => str_repeat('a', 431)], 'clientSummary is 431 characters, at most 400'],
    'an issue reference' => [['clientSummary' => 'Opgelost in #141, de logs lopen mee.'], "clientSummary holds an issue or PR reference (#141): name what the client gets, in the client's words"],
    'a backtick' => [['clientSummary' => 'De `tail` volgt nu het logboek.'], 'clientSummary holds a backtick: plain words, no code'],
    'the whole branch' => [['clientSummary' => 'Gebouwd op feature/issue-12-logs.'], 'clientSummary holds the branch name feature/issue-12-logs'],
    'the branch topic, in capitals' => [['clientSummary' => 'Zie ISSUE-12-LOGS voor de details.'], 'clientSummary holds the branch name feature/issue-12-logs'],
    'no explainer' => [['explainer' => null], 'explainer is missing: {problem, solution}, a paragraph each for a reader who knows nothing about the issue'],
    'an explainer that is text' => [['explainer' => 'The log follows now.'], 'explainer is missing: {problem, solution}, a paragraph each for a reader who knows nothing about the issue'],
    'an empty solution' => [['explainer' => ['problem' => 'It stopped.', 'solution' => ' ']], 'explainer.solution is missing'],
    'a shot without a state' => [['shots' => [['title' => 'Log', 'state' => 'after'], ['title' => 'Header']]], 'shot 2 has no state: before, after or defect'],
    'a shot with another state' => [['shots' => [['title' => 'Log', 'state' => 'after'], ['title' => 'Header', 'state' => 'fixed']]], 'shot 2 state is "fixed": before, after or defect'],
]);

it('matches the branch topic as a word of its own, so a short topic does not refuse ordinary Dutch', function () {
    $run = proof_prose_run(['branch' => 'feature/ui', 'clientSummary' => 'De gebruiker ziet de uitslag nu direct.']);

    expect(proof_all_problems($run))->toBe([]);
    expect(proof_names_branch('De nieuwe ui staat klaar.', 'feature/ui'))->toBeTrue();
    expect(proof_names_branch('Alles werkt.', 'main'))->toBeFalse();
});

it('labels each shot state and names them for a refusal', function () {
    expect(array_map(fn (ProofShotState $state) => $state->label(), ProofShotState::cases()))->toBe(['Before', 'After', 'Defect']);
    expect(ProofShotState::named())->toBe('before, after or defect');
});

it('merges a payload over the stored run key by key, replacing a list whole and keeping what it leaves out', function () {
    $stored = ['title' => 'PR #7: x', 'headline' => 'H', 'openQuestions' => ['a', 'b'], 'schema' => 2, 'createdAt' => '2026-10-01T10:00:00+02:00', 'addedTests' => [['file' => 'tests/XTest.php', 'cases' => []]]];
    $payload = ['openQuestions' => ['c'], 'schema' => 9, 'createdAt' => 'never', 'updatedAt' => 'never', 'addedTests' => [], 'shotSources' => ['/tmp/a.png']];

    expect(proof_merge_run($stored, $payload))->toBe([
        'title' => 'PR #7: x', 'headline' => 'H', 'openQuestions' => ['c'], 'schema' => 2,
        'createdAt' => '2026-10-01T10:00:00+02:00', 'addedTests' => [['file' => 'tests/XTest.php', 'cases' => []]],
    ]);
    expect(proof_merge_run($stored, ['openQuestions' => []])['openQuestions'])->toBe([]);
});

it('fills a default only where the stored run lacks the key', function () {
    expect(proof_merge_run([], ['pr' => 7], ['title' => 'PR #7: x']))->toBe(['title' => 'PR #7: x', 'pr' => 7]);
    expect(proof_merge_run(['title' => 'PR #7: logs that follow'], ['pr' => 7], ['title' => 'PR #7: x'])['title'])->toBe('PR #7: logs that follow');
});

it('shortens a title over 70 characters at a word boundary, and leaves one at or under 70 alone', function () {
    $at = str_repeat('a', PROOF_TITLE_MAX);
    expect(proof_short_title($at))->toBe($at);
    expect(proof_short_title('  PR #7: short  '))->toBe('PR #7: short');

    $short = proof_short_title('PR #141: Every run gets a proof page, opening with a Dutch client summary and a plain-language explainer');
    expect(mb_strlen($short))->toBeLessThanOrEqual(PROOF_TITLE_MAX);
    expect($short)->toBe('PR #141: Every run gets a proof page, opening with a Dutch client…');
    expect(mb_strlen(proof_short_title(str_repeat('é', 90))))->toBe(PROOF_TITLE_MAX);
});

it('reads a run\'s status from the store, else from its PR state', function (array $run, ProofRunStatus $status) {
    expect(ProofRunStatus::of($run))->toBe($status);
})->with([
    'stored' => [['status' => ['state' => 'halted', 'reason' => 'x'], 'prState' => 'MERGED'], ProofRunStatus::Halted],
    'an older merged run' => [['prState' => 'MERGED'], ProofRunStatus::Merged],
    'an older closed run' => [['prState' => 'CLOSED'], ProofRunStatus::Closed],
    'an older open run' => [['prState' => 'OPEN'], ProofRunStatus::Running],
    'a run without a PR' => [[], ProofRunStatus::Running],
    'an unknown stored state' => [['status' => ['state' => 'paused'], 'prState' => 'MERGED'], ProofRunStatus::Merged],
    'a status that is no object' => [['status' => 'halted'], ProofRunStatus::Running],
]);

it('corrects a stored status by what gh says the PR is', function (ProofRunStatus $stored, string $state, bool $draft, ProofRunStatus $corrected) {
    expect($stored->corrected($state, $draft))->toBe($corrected);
})->with([
    'merged' => [ProofRunStatus::Running, 'MERGED', false, ProofRunStatus::Merged],
    'closed' => [ProofRunStatus::Ready, 'CLOSED', false, ProofRunStatus::Closed],
    'open and ready' => [ProofRunStatus::Running, 'OPEN', false, ProofRunStatus::Ready],
    'a halted run whose PR went ready' => [ProofRunStatus::Halted, 'OPEN', false, ProofRunStatus::Ready],
    'an open draft keeps Running' => [ProofRunStatus::Running, 'OPEN', true, ProofRunStatus::Running],
    'an open draft keeps Halted' => [ProofRunStatus::Halted, 'OPEN', true, ProofRunStatus::Halted],
    'a Ready PR put back in draft' => [ProofRunStatus::Ready, 'OPEN', true, ProofRunStatus::Running],
    'an unknown state keeps the stored one' => [ProofRunStatus::Halted, 'WEIRD', false, ProofRunStatus::Halted],
]);

it('orders the statuses for attention, labels them, and stores the reason only with Halted', function () {
    expect(array_map(fn (ProofRunStatus $status) => $status->group(), ProofRunStatus::cases()))->toBe([2, 0, 1, 2, 2]);
    expect(array_map(fn (ProofRunStatus $status) => $status->label(), ProofRunStatus::cases()))
        ->toBe(['Running', 'Halted', 'Ready for review', 'Merged', 'Closed']);
    expect(ProofRunStatus::named())->toBe('running, halted, ready, merged or closed');
    expect(ProofRunStatus::Halted->stored('CI red'))->toBe(['state' => 'halted', 'reason' => 'CI red']);
    expect(ProofRunStatus::Ready->stored('CI red'))->toBe(['state' => 'ready']);
    expect(proof_status_reason(['status' => ['state' => 'halted', 'reason' => 'CI red']]))->toBe('CI red');
    expect(proof_status_reason(['status' => ['state' => 'ready', 'reason' => 'stale']]))->toBe('');
});

it('keeps the store\'s revision, attention, status and cost over a payload\'s', function () {
    $stored = ['title' => 'x', 'revision' => 3, 'attention' => 2, 'status' => ['state' => 'halted', 'reason' => 'r'], 'cost' => [['workflow' => 'wf_a']]];

    expect(proof_merge_run($stored, ['revision' => 99, 'attention' => 40, 'status' => ['state' => 'merged'], 'cost' => [], 'title' => 'y']))
        ->toBe(['title' => 'y', 'revision' => 3, 'attention' => 2, 'status' => ['state' => 'halted', 'reason' => 'r'], 'cost' => [['workflow' => 'wf_a']]]);
    expect(proof_merge_run(['title' => 'x'], ['attention' => 40]))->toBe(['title' => 'x']);
});

it('counts every filing in revision and gives a run without a status the one its PR state implies', function () {
    $dir = sys_get_temp_dir() . '/proof-' . uniqid() . '/Deploy/pr-5-logs';

    expect(proof_write_run($dir, ['pr' => 5, 'prState' => 'OPEN'], '2026-10-01T10:00:00+02:00'))
        ->toMatchArray(['revision' => 1, 'status' => ['state' => 'running']]);
    expect(proof_write_run($dir, ['pr' => 5, 'status' => ['state' => 'halted', 'reason' => 'r']], '2026-10-01T11:00:00+02:00'))
        ->toMatchArray(['revision' => 2, 'status' => ['state' => 'halted', 'reason' => 'r']]);

    // An old run filed again keeps reading as what its PR state says (Assumption 16), not Running.
    $old = sys_get_temp_dir() . '/proof-' . uniqid() . '/Deploy/pr-4-old';
    expect(proof_write_run($old, ['pr' => 4, 'prState' => 'MERGED'], '2026-10-01T10:00:00+02:00')['status'])->toBe(['state' => 'merged']);
});

it('files a workflow\'s cost once, replacing its own entry and appending another workflow\'s', function () {
    $first = ['workflow' => 'wf_a', 'span' => 60.0, 'steps' => [['label' => 'implement:run', 'cost' => 1000000.0]]];
    $again = [...$first, 'span' => 90.0];
    $resume = ['workflow' => 'wf_b', 'span' => 30.0, 'steps' => [['label' => 'review-pr:review', 'cost' => 500000.0]]];

    $run = proof_add_cost(['title' => 'x'], $first);
    expect($run['cost'])->toBe([$first]);
    expect(proof_add_cost($run, $again)['cost'])->toBe([$again]);
    expect(proof_add_cost(proof_add_cost($run, $resume), $again)['cost'])->toBe([$again, $resume]);
    expect(proof_cost_totals([$again, $resume]))->toBe(['seconds' => 120.0, 'cost' => 1500000.0]);
    expect(proof_cost_totals([]))->toBe(['seconds' => 0.0, 'cost' => 0.0]);
});

it('orders all five statuses for a sort on the Status column: halted, ready, running, merged, closed', function () {
    expect(array_map(fn (ProofRunStatus $status) => $status->order(), ProofRunStatus::cases()))->toBe([2, 0, 1, 3, 4]);
});

it('calls the owner for a halted or ready run, never for a running, merged or closed one: the statuses group() puts first', function () {
    expect(array_map(fn (ProofRunStatus $status) => $status->callsOwner(), ProofRunStatus::cases()))->toBe([false, true, true, false, false])
        ->toBe(array_map(fn (ProofRunStatus $status) => $status->group() < 2, ProofRunStatus::cases()));
});

it('raises attention by one when a change turns the status halted or ready, and only then', function (array $before, array $after, array $counted) {
    expect(proof_count_attention($before, $after))->toBe($counted);
})->with([
    'running to halted' => [
        ['status' => ['state' => 'running']],
        ['status' => ['state' => 'halted', 'reason' => 'CI red']],
        ['status' => ['state' => 'halted', 'reason' => 'CI red'], 'attention' => 1],
    ],
    'running to ready, counted once before' => [
        ['status' => ['state' => 'running'], 'attention' => 1],
        ['status' => ['state' => 'ready'], 'attention' => 1],
        ['status' => ['state' => 'ready'], 'attention' => 2],
    ],
    'halted to ready' => [
        ['status' => ['state' => 'halted', 'reason' => 'r'], 'attention' => 1],
        ['status' => ['state' => 'ready'], 'attention' => 1],
        ['status' => ['state' => 'ready'], 'attention' => 2],
    ],
    'an older open run turning ready' => [
        ['prState' => 'OPEN'],
        ['prState' => 'OPEN', 'status' => ['state' => 'ready']],
        ['prState' => 'OPEN', 'status' => ['state' => 'ready'], 'attention' => 1],
    ],
    'halted again with a new reason' => [
        ['status' => ['state' => 'halted', 'reason' => 'CI red']],
        ['status' => ['state' => 'halted', 'reason' => 'CI still red']],
        ['status' => ['state' => 'halted', 'reason' => 'CI still red']],
    ],
    'ready again' => [['status' => ['state' => 'ready']], ['status' => ['state' => 'ready']], ['status' => ['state' => 'ready']]],
    'halted to running' => [['status' => ['state' => 'halted', 'reason' => 'r']], ['status' => ['state' => 'running']], ['status' => ['state' => 'running']]],
    'ready to merged' => [['status' => ['state' => 'ready']], ['status' => ['state' => 'merged']], ['status' => ['state' => 'merged']]],
    'running to closed' => [['status' => ['state' => 'running']], ['status' => ['state' => 'closed']], ['status' => ['state' => 'closed']]],
    'a cost on a halted run' => [
        ['status' => ['state' => 'halted', 'reason' => 'r']],
        ['status' => ['state' => 'halted', 'reason' => 'r'], 'cost' => [['workflow' => 'wf_a']]],
        ['status' => ['state' => 'halted', 'reason' => 'r'], 'cost' => [['workflow' => 'wf_a']]],
    ],
]);

it('gives a run the number its page stores when opened: its revision plus its attention', function () {
    expect(proof_run_seen(['revision' => 3, 'attention' => 2]))->toBe(5);
    expect(proof_run_seen(['revision' => 3]))->toBe(3);
    expect(proof_run_seen(['attention' => 2]))->toBeNull();
});
