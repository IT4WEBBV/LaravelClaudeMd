<?php

/**
 * The CI gate on the PR's head commit, before `gh pr ready` (`../references/engine.md` §The CI gate).
 * Pure: `dispatch_cli.php ci` reads the PR with gh and hands the view in.
 */

/** Reads before a pending or unreadable CI halts: an hour, 30 s apart. */
const PIPELINE_CI_POLLS = 120;

/** Reads GitHub gets to catch up with a push: its head moved to the pushed commit, its checks registered. */
const PIPELINE_CI_PUSH_POLLS = 3;

/** How the gate's failure record starts in `decisions`; one such decision is the run's fix round spent. */
const PIPELINE_CI_RED = "CI red on the PR's head commit ";

/**
 * One `statusCheckRollup` item: a check run is pending until it completes, then green on success,
 * neutral or skipped; a commit status is green on success and pending while pending or expected.
 * Anything else is red.
 *
 * @return array{name: string, link: string, state: string}
 */
function pipeline_ci_check(array $item): array
{
    if ($item['__typename'] === 'CheckRun') {
        return [
            'name' => $item['workflowName'] === '' ? $item['name'] : "{$item['workflowName']} / {$item['name']}",
            'link' => $item['detailsUrl'],
            'state' => match (true) {
                $item['status'] !== 'COMPLETED' => 'pending',
                in_array($item['conclusion'], ['SUCCESS', 'NEUTRAL', 'SKIPPED'], true) => 'green',
                default => 'red',
            },
        ];
    }

    return [
        'name' => $item['context'],
        'link' => $item['targetUrl'],
        'state' => match ($item['state']) {
            'SUCCESS' => 'green',
            'PENDING', 'EXPECTED' => 'pending',
            default => 'red',
        },
    ];
}

/**
 * The head commit's checks as one verdict: `none`, `red` (a red check wins over a pending one),
 * `pending` or `green`.
 *
 * @return array{verdict: string, failing: list<array{name: string, link: string}>, pending: list<string>}
 */
function pipeline_ci_verdict(array $rollup): array
{
    $checks = array_map(pipeline_ci_check(...), $rollup);
    $failing = array_values(array_filter($checks, fn (array $check) => $check['state'] === 'red'));
    $pending = array_values(array_filter($checks, fn (array $check) => $check['state'] === 'pending'));

    return [
        'verdict' => match (true) {
            $checks === [] => 'none',
            $failing !== [] => 'red',
            $pending !== [] => 'pending',
            default => 'green',
        },
        'failing' => array_map(fn (array $check) => ['name' => $check['name'], 'link' => $check['link']], $failing),
        'pending' => array_column($pending, 'name'),
    ];
}

/**
 * What the session does next: `wait` and read again, `ready` (`gh pr ready`), `fix` (the decision into
 * `decisions` through `launch --from review-pr --decision`), or `halt` (`finish`'s input). `$view` is
 * `gh pr view <pr> --json headRefOid,statusCheckRollup`, null when gh could not read it; `$head` the
 * worktree's `HEAD`, which GitHub's head must be before its checks count; `$workflows` whether the
 * worktree has GitHub Actions workflows; `$poll` this read's number, from 1.
 */
function pipeline_ci_answer(array $manifest, ?array $view, string $head, bool $workflows, int $poll): array
{
    $last = $poll >= PIPELINE_CI_POLLS;
    if ($view === null) {
        return $last
            ? pipeline_ci_halt("CI on PR #{$manifest['artifacts']['pr']} had not settled after an hour, and gh could not read its checks at the last read", ['verdict' => 'unreadable'])
            : ['action' => 'wait', 'verdict' => 'unreadable'];
    }
    if ($view['headRefOid'] !== $head) {
        return pipeline_ci_mismatch($manifest, $view['headRefOid'], $head, $poll);
    }
    $ci = pipeline_ci_verdict($view['statusCheckRollup']);
    $read = ['verdict' => $ci['verdict'], 'sha' => $view['headRefOid']];

    return match ($ci['verdict']) {
        'green' => ['action' => 'ready', ...$read],
        'none' => ['action' => $workflows && $poll < PIPELINE_CI_PUSH_POLLS ? 'wait' : 'ready', ...$read],
        'pending' => $last
            ? pipeline_ci_halt("CI on {$read['sha']} has not finished after an hour: " . implode(', ', $ci['pending']), $read)
            : ['action' => 'wait', ...$read],
        'red' => pipeline_ci_red($manifest, [...$read, 'failing' => $ci['failing']]),
    };
}

/** GitHub's head is not the worktree's: a push still showing is waited for, one that did not land halts. */
function pipeline_ci_mismatch(array $manifest, string $sha, string $head, int $poll): array
{
    $read = ['verdict' => 'mismatch', 'sha' => $sha, 'head' => $head];

    return $poll < PIPELINE_CI_PUSH_POLLS
        ? ['action' => 'wait', ...$read]
        : pipeline_ci_halt("PR #{$manifest['artifacts']['pr']}'s head on GitHub is {$sha}, but the worktree's HEAD is {$head}: the two must match before its checks count; push the branch, or reconcile it when GitHub is ahead, and run the CI gate again", $read);
}

/** The first red of a run is its fix round; a red after it halts. */
function pipeline_ci_red(array $manifest, array $read): array
{
    $failures = implode('; ', array_map(fn (array $check) => "{$check['name']} failed ({$check['link']})", $read['failing']));

    return pipeline_ci_rounds($manifest) === 0
        ? ['action' => 'fix', ...$read, 'decision' => PIPELINE_CI_RED . "{$read['sha']}: {$failures}"]
        : pipeline_ci_halt("CI red again after the fix round, on {$read['sha']}: {$failures}", $read);
}

/** A gate halt names `review-pr`, so `finish` records it there as it stands. */
function pipeline_ci_halt(string $reason, array $read): array
{
    return ['action' => 'halt', 'leg' => 'review-pr', 'reason' => $reason, ...$read];
}

/** The fix rounds this run has had: the decisions the gate's failure record starts. */
function pipeline_ci_rounds(array $manifest): int
{
    return count(array_filter($manifest['decisions'] ?? [], fn (string $decision) => str_starts_with($decision, PIPELINE_CI_RED)));
}
