<?php

/**
 * Entry point for the proof store. Everything impure lives here — reading the payload,
 * `sips`, `gh`, writing files, deleting pruned directories — so `proof.php` and
 * `proof_render.php` stay testable without touching any of it.
 *
 *   php proof_cli.php write <payload.json>
 *   php proof_cli.php open [<page.html>]
 *   php proof_cli.php prune
 *
 * Never exits non-zero for a store problem. Failing to *file* proof must not halt a run;
 * only failing to *capture* it does, and that is the leg's decision, not this script's.
 * `open` is weaker still: it is cosmetic, so every one of its paths logs and returns 0.
 */

require_once __DIR__ . '/proof.php';
require_once __DIR__ . '/proof_render.php';

/**
 * Downscale to at most 1600px wide. PNG is kept rather than JPEG: JPEG artefacts on UI text
 * are exactly the kind of difference a proof page must not introduce.
 */
function proof_cli_ingest_shot(string $source, string $destination): bool
{
    if (! is_file($source) || ! copy($source, $destination)) {
        return false;
    }

    $size = @getimagesize($destination);
    if (is_array($size) && $size[0] > 1600) {
        exec('sips --resampleWidth 1600 ' . escapeshellarg($destination) . ' 2>/dev/null', $out, $code);
    }

    return true;
}

function proof_cli_write(string $payloadPath): int
{
    $payload = json_decode((string) @file_get_contents($payloadPath), true);
    if (! is_array($payload)) {
        fwrite(STDERR, "proof: unreadable payload at {$payloadPath}\n");

        return 0;
    }

    $root = proof_root();
    $repo = (string) ($payload['repo'] ?? 'unknown');
    $branch = (string) ($payload['branch'] ?? 'unknown');
    $dir = proof_run_dir($root, $repo, $branch, $payload['pr'] ?? null);

    // A run filed before the directory was keyed by PR — or filed by a write that beat the PR
    // into existence — still lives under its branch slug. Adopt that directory rather than
    // starting an empty one beside it, which would orphan the shots it already holds and leave
    // the store showing the same run twice.
    $legacy = proof_run_dir($root, $repo, $branch);
    if ($dir !== $legacy && ! is_dir($dir) && is_dir($legacy)) {
        rename($legacy, $dir);
    }

    if (! is_dir($dir . '/shots') && ! mkdir($dir . '/shots', 0777, true) && ! is_dir($dir . '/shots')) {
        fwrite(STDERR, "proof: cannot create {$dir}/shots\n");

        return 0;
    }

    $sources = $payload['shotSources'] ?? [];
    unset($payload['shotSources']);

    foreach (array_values($sources) as $i => $source) {
        $name = sprintf('%02d-%s.png', $i + 1, proof_slug((string) ($payload['shots'][$i]['route'] ?? 'state')));
        if (proof_cli_ingest_shot((string) $source, $dir . '/shots/' . $name)) {
            $payload['shots'][$i]['file'] = 'shots/' . $name;
        }
    }

    $run = proof_write_run($dir, $payload, date('c'));

    file_put_contents($dir . '/index.html', proof_render_run($run));
    file_put_contents($root . '/index.html', proof_render_index(proof_scan_runs($root)));

    echo $dir . "/index.html\n";

    return 0;
}

/**
 * Open a finished run's page in the desktop browser — the run's last action, once per run.
 *
 * Cosmetic, and weaker than every other policy in this file: failing to *capture* proof halts a
 * run and failing to *file* it logs and continues, but failing to *open* it does not even rate a
 * distinct outcome. Every path below returns 0, including "there is no page", which is the normal
 * state of a backend-only run that never triggered `verify-ui`.
 *
 * `proof_open_argv()` returns an argv **array** and `proc_open()` runs an array form without a
 * shell, so the page path — which reaches this store from a JSON payload — is passed to the opener
 * as one literal argument. There is no command line for a quote or a `;` in it to escape from.
 *
 * The opener is expected to return immediately (`open` launches and exits; a desktop `xdg-open`
 * delegates and exits). On a headless box where `xdg-open` would fall back to a blocking terminal
 * browser, set `PIPELINE_NO_OPEN=1` — which is what an unattended run wants regardless.
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
 * Refresh one run's PR state from `gh`. A failure returns null and the stored state is kept:
 * a stale `OPEN` simply means the run is not pruned this pass, which is the safe direction.
 */
function proof_cli_pr_state(array $run): ?string
{
    if (empty($run['pr']) || empty($run['nameWithOwner'])) {
        return null;
    }

    $command = sprintf(
        'gh pr view %s --repo %s --json state --jq .state 2>/dev/null',
        escapeshellarg((string) $run['pr']),
        escapeshellarg((string) $run['nameWithOwner']),
    );

    exec($command, $output, $code);
    $state = trim(implode('', $output));

    return ($code === 0 && $state !== '') ? $state : null;
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
        $run = $entry['run'];

        $state = proof_cli_pr_state($run);
        if ($state !== null && $state !== ($run['prState'] ?? null)) {
            $run['prState'] = $state;
            // Preserve updatedAt: the grace period measures age since the run was last
            // written, not since this housekeeping pass noticed the PR had closed.
            $run['updatedAt'] = $run['updatedAt'] ?? $now;
            file_put_contents(
                $entry['dir'] . '/run.json',
                json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            );
            // The run page prints the state in its header, so it goes stale the moment this
            // pass learns the PR has closed. Re-render it, or the index and the page it
            // links to disagree about the same run.
            file_put_contents($entry['dir'] . '/index.html', proof_render_run($run));
        }

        if (proof_should_prune($run, $now)) {
            proof_cli_rmdir($entry['dir']);
            $pruned++;
        }
    }

    file_put_contents($root . '/index.html', proof_render_index(proof_scan_runs($root)));
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

if ($command === 'prune') {
    exit(proof_cli_prune());
}

fwrite(STDERR, "usage: proof_cli.php write <payload.json> | open [<page.html>] | prune\n");
exit(0);
