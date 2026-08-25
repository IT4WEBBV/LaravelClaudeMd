<?php

/**
 * The durable visual proof store (`../references/engine.md` §verify-ui).
 *
 * Everything here is a *rendering input*. The engine never reads this store to decide which
 * leg runs next, whether a gate passed, or whether to loop back — deleting the whole of
 * `_proofs/` changes no run's behaviour. That is what keeps a durable store compatible with
 * the skill's non-goal: "no persistent state not reconstructable from git + gh".
 */

/**
 * The store root. `PIPELINE_PROOF_ROOT` exists so tests never write to the real store —
 * a test that pollutes `~/GitProjects/_proofs` would be indistinguishable from a real run.
 */
function proof_root(): string
{
    $override = getenv('PIPELINE_PROOF_ROOT');
    if (is_string($override) && $override !== '') {
        return rtrim($override, '/');
    }

    return rtrim((string) getenv('HOME'), '/') . '/GitProjects/_proofs';
}

/**
 * Branch (or repo) name → exactly one safe path segment.
 *
 * `/` becomes `-`, matching the changelog-fragment convention in CLAUDE.md. Anything outside
 * `[A-Za-z0-9._-]` follows it, and any surviving run of dots is collapsed: `..` is the one
 * sequence that would let a careless branch name write outside its own directory.
 */
function proof_slug(string $name): string
{
    $slug = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
    $slug = preg_replace('/\.{2,}/', '-', $slug);
    $slug = preg_replace('/-{2,}/', '-', $slug);
    $slug = trim($slug, '-.');

    return $slug === '' ? 'unnamed' : $slug;
}

/**
 * Keyed `<repo>/<branch>`, never by branch alone — roughly twenty repos share this store and
 * every one of them eventually grows a `feature/fix-typo`.
 */
function proof_run_dir(string $root, string $repo, string $branch): string
{
    return rtrim($root, '/') . '/' . proof_slug($repo) . '/' . proof_slug($branch);
}

function proof_read_run(string $dir): ?array
{
    $path = rtrim($dir, '/') . '/run.json';
    if (! is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * Write `run.json`, preserving `createdAt` across the two write points a run has:
 * `verify-ui` builds the page, `review-pr` finalises it.
 *
 * `$now` is a parameter rather than a call to `time()` so the round-trip is testable without
 * a clock and a run's timestamps can be made to match the leg that produced them.
 *
 * @return array the run as written, including the fields this function fills in
 */
function proof_write_run(string $dir, array $run, string $now): array
{
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $existing = proof_read_run($dir);

    $run['schema'] = 1;
    $run['createdAt'] = $existing['createdAt'] ?? $now;
    $run['updatedAt'] = $now;

    file_put_contents(
        rtrim($dir, '/') . '/run.json',
        json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
    );

    return $run;
}

/**
 * Pure predicate — no filesystem, no `gh`, no clock.
 *
 * Two rules the store depends on, both stated in the spec's §Retention:
 *  - a PR merged this morning is exactly the one still worth looking at this afternoon,
 *    hence the grace period rather than deleting the moment it closes;
 *  - a run that opened no PR is never auto-pruned. `review-plan` bound-exhaustion halts
 *    *before* `handoff` and deliberately opens none, so those runs exist; the index flags
 *    them for manual pruning rather than a silent cap deleting them.
 *
 * Anything unparseable answers "do not prune". Deleting proof is irreversible; keeping it
 * costs disk.
 */
function proof_should_prune(array $run, string $now, int $graceDays = 14): bool
{
    if (empty($run['pr'])) {
        return false;
    }
    if (! in_array($run['prState'] ?? '', ['MERGED', 'CLOSED'], true)) {
        return false;
    }

    $updated = strtotime((string) ($run['updatedAt'] ?? ''));
    $nowTs = strtotime($now);
    if ($updated === false || $nowTs === false) {
        return false;
    }

    return $updated < $nowTs - $graceDays * 86400;
}

/**
 * Every run in the store, newest first. Shape is fixed at `<root>/<repo>/<branch>/run.json`,
 * so one glob covers the whole store.
 *
 * @return list<array{dir: string, run: array}>
 */
function proof_scan_runs(string $root): array
{
    $runs = [];
    foreach (glob(rtrim($root, '/') . '/*/*/run.json') ?: [] as $path) {
        $dir = dirname($path);
        $run = proof_read_run($dir);
        if ($run !== null) {
            $runs[] = ['dir' => $dir, 'run' => $run];
        }
    }

    usort($runs, fn ($a, $b) => strcmp((string) ($b['run']['updatedAt'] ?? ''), (string) ($a['run']['updatedAt'] ?? '')));

    return $runs;
}
