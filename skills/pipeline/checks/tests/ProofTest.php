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
