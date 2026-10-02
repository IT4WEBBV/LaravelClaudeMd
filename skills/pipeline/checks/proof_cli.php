<?php

/**
 * Entry point for the proof store. Everything impure lives here — reading the payload,
 * `sips`, `gh`, writing files, deleting pruned directories — so `proof.php` and
 * `proof_render.php` stay testable without touching any of it.
 *
 *   php proof_cli.php write <payload.json>
 *   php proof_cli.php open [<page.html>]
 *   php proof_cli.php status <page.html> <running|halted|ready|merged|closed> [--reason <text>]
 *   php proof_cli.php prune
 *
 * Never exits non-zero for a store problem. Failing to *file* proof must not halt a run;
 * only failing to *capture* it does, and that is the leg's decision, not this script's.
 * `open` is weaker still: it is cosmetic, so every one of its paths logs and returns 0.
 */

require_once __DIR__ . '/proof_store.php';

function proof_cli_write(string $payloadPath): int
{
    $payload = json_decode((string) @file_get_contents($payloadPath), true);
    if (! is_array($payload)) {
        fwrite(STDERR, "proof: unreadable payload at {$payloadPath}\n");

        return 0;
    }

    // Nothing is filed until the run as it will be filed passes: a page written anyway would carry its title into
    // the store index for good. The leg sees no page path on stdout, fixes the payload, writes again.
    $filed = proof_store_file($payload, date('c'), fn (array $run): array => [...proof_validate_run($run), ...proof_validate_prose($run)]);
    if ($filed['page'] === null) {
        fwrite(STDERR, "proof: payload rejected, nothing written:\n  - " . implode("\n  - ", $filed['problems']) . "\n");

        return 0;
    }

    echo $filed['page'] . "\n";

    return 0;
}

/**
 * Open a run's page in the desktop browser, by hand: no step runs this, a run's report names its page instead
 * (`../references/engine.md` §The proof store, *No page opens by itself*).
 *
 * Cosmetic, and weaker than every other policy in this file: failing to *capture* proof halts a
 * run and failing to *file* it logs and continues, but failing to *open* it does not even rate a
 * distinct outcome. Every path below returns 0, including "there is no page", which is the
 * state of a run that halted before `handoff`.
 *
 * `proof_open_argv()` returns an argv **array** and `proc_open()` runs an array form without a
 * shell, so the page path — which reaches this store from a JSON payload — is passed to the opener
 * as one literal argument. There is no command line for a quote or a `;` in it to escape from.
 *
 * The opener is expected to return immediately (`open` launches and exits; a desktop `xdg-open`
 * delegates and exits); `PIPELINE_OPEN_CMD` names another one.
 */
function proof_cli_open(string $path): int
{
    $argv = proof_open_argv($path === '' ? null : $path);

    if ($argv === null) {
        fwrite(STDERR, "proof: nothing to open\n");

        return 0;
    }

    $quiet = ['file', '/dev/null', 'w'];
    $process = @proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => $quiet, 2 => $quiet], $pipes);

    if (! is_resource($process)) {
        fwrite(STDERR, "proof: could not open {$path}\n");

        return 0;
    }

    proc_close($process);

    return 0;
}

/**
 * `status <page> <status> [--reason <text>]`: what a session knows and no command does, right after it made it so:
 * `ready` after `gh pr ready`, `merged` or `closed` when the merge watch answers (`../references/engine.md` §The
 * proof store). Like every store path it logs and returns 0.
 */
function proof_cli_status(array $arguments): int
{
    $page = (string) ($arguments[0] ?? '');
    $state = (string) ($arguments[1] ?? '');
    $reason = ($arguments[2] ?? null) === '--reason' ? trim((string) ($arguments[3] ?? '')) : '';
    $status = ProofRunStatus::tryFrom($state);

    $problem = match (true) {
        $status === null => "unknown status '{$state}': " . ProofRunStatus::named(),
        $status === ProofRunStatus::Halted && $reason === '' => 'halted needs --reason <text>',
        default => proof_store_status($page, $status, $reason),
    };
    if ($problem !== null) {
        fwrite(STDERR, "proof: status not written: {$problem}\n");
    }

    return 0;
}

/**
 * `gh`'s answer on the run's PR, or null when there is none to ask about or `gh` cannot answer: the stored state then
 * stands, and a stale `OPEN` only means the run is not pruned this pass, which is the safe direction. The repo comes
 * from `proof_run_name_with_owner()`, so a run filed before `nameWithOwner` existed is asked about like any other. An
 * argv array, never a shell string.
 *
 * @return array{state: string, isDraft: bool}|null
 */
function proof_cli_pr_view(array $run): ?array
{
    $nameWithOwner = proof_run_name_with_owner($run);
    if (empty($run['pr']) || $nameWithOwner === null) {
        return null;
    }
    $argv = ['gh', 'pr', 'view', (string) $run['pr'], '--repo', $nameWithOwner, '--json', 'state,isDraft'];
    $process = @proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (! is_resource($process)) {
        return null;
    }
    $out = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $view = proc_close($process) === 0 ? json_decode($out, true) : null;

    return is_array($view) && is_string($view['state'] ?? null) && $view['state'] !== ''
        ? ['state' => $view['state'], 'isDraft' => (bool) ($view['isDraft'] ?? false)]
        : null;
}

/**
 * The run as `gh` sees its PR: its `prState` and its status corrected (`ProofRunStatus::corrected()`), amended into the
 * store when either changed, which re-renders its page so the page and the index agree.
 */
function proof_cli_refresh(array $entry): array
{
    $run = $entry['run'];
    $view = proof_cli_pr_view($run);
    if ($view === null) {
        return $run;
    }
    $stored = ProofRunStatus::of($run);
    $status = $stored->corrected($view['state'], $view['isDraft']);
    if ($view['state'] === ($run['prState'] ?? null) && $status === $stored) {
        return $run;
    }
    $refresh = fn (array $filed): array => [
        ...$filed,
        'prState' => $view['state'],
        'status' => $status->stored(proof_status_reason($filed)),
    ];
    $problem = proof_store_amend("{$entry['dir']}/index.html", $refresh);
    if ($problem !== null) {
        fwrite(STDERR, "proof: {$problem}\n");
    }

    return $refresh($run);
}

function proof_cli_rmdir(string $dir): void
{
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $path) {
        $name = basename($path);
        if ($name === '.' || $name === '..') {
            continue;
        }
        is_dir($path) ? proof_cli_rmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}

/**
 * Housekeeping over the store's own contents — never a decision about a run.
 *
 * `gh` failures are non-fatal by design: no network, a rate limit or an auth problem skips
 * the pass rather than breaking a pipeline run.
 */
function proof_cli_prune(): int
{
    $root = proof_root();
    $now = date('c');
    $pruned = 0;

    foreach (proof_scan_runs($root) as $entry) {
        if (proof_should_prune(proof_cli_refresh($entry), $now)) {
            proof_cli_rmdir($entry['dir']);
            $pruned++;
        }
    }

    $problem = proof_store_index($root);
    if ($problem !== null) {
        fwrite(STDERR, "proof: {$problem}\n");
    }
    echo "proof: pruned {$pruned} run(s)\n";

    return 0;
}

$command = $argv[1] ?? '';

if ($command === 'write') {
    $status = proof_cli_write($argv[2] ?? '');
    proof_cli_prune();
    exit($status);
}

if ($command === 'open') {
    exit(proof_cli_open((string) ($argv[2] ?? '')));
}

if ($command === 'status') {
    exit(proof_cli_status(array_slice($argv, 2)));
}

if ($command === 'prune') {
    exit(proof_cli_prune());
}

fwrite(STDERR, "usage: proof_cli.php write <payload.json> | open [<page.html>] | status <page.html> <running|halted|ready|merged|closed> [--reason <text>] | prune\n");
exit(0);
