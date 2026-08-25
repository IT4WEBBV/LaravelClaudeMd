<?php

/**
 * Entry point for the proof store. Everything impure lives here — reading the payload,
 * `sips`, `gh`, writing files, deleting pruned directories — so `proof.php` and
 * `proof_render.php` stay testable without touching any of it.
 *
 *   php proof_cli.php write <payload.json>
 *   php proof_cli.php prune
 *
 * Never exits non-zero for a store problem. Failing to *file* proof must not halt a run;
 * only failing to *capture* it does, and that is the leg's decision, not this script's.
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
    $dir = proof_run_dir($root, (string) ($payload['repo'] ?? 'unknown'), (string) ($payload['branch'] ?? 'unknown'));

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

$command = $argv[1] ?? '';

if ($command === 'write') {
    exit(proof_cli_write($argv[2] ?? ''));
}

fwrite(STDERR, "usage: proof_cli.php write <payload.json> | prune\n");
exit(0);
