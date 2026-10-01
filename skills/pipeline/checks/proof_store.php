<?php

/**
 * Filing a run (`../references/engine.md` §The proof store): the one path by which `proof_cli.php write` and
 * `dispatch_cli.php handoff` put a run into the store. A write is merged over the run as filed, never replaces
 * it. Impure: the filesystem, `sips`, and git in the run's worktree. Never a halt: what cannot be filed comes
 * back as problems.
 */

require_once __DIR__ . '/proof.php';
require_once __DIR__ . '/proof_render.php';
require_once __DIR__ . '/proof_tests.php';
require_once __DIR__ . '/suite.php';

/**
 * Files `$payload` merged over the stored run when the merged run passes `$rules`: nothing is written otherwise.
 * Then the shots are ingested, `addedTests` extracted, `run.json` written and the page and the store index
 * rendered. It never prunes.
 *
 * @param callable(array): list<string> $rules
 * @param array $defaults keys filled only where the stored run lacks them (`proof_merge_run()`)
 * @return array{page: ?string, problems: list<string>}
 */
function proof_store_file(array $payload, string $now, callable $rules, array $defaults = []): array
{
    $root = proof_root();
    [$dir, $home] = proof_store_dirs($root, $payload);
    $run = proof_merge_run(proof_read_run($home) ?? [], $payload, $defaults);
    $sources = array_values($payload['shotSources'] ?? []);
    $problems = [...$rules($run), ...proof_store_unsourced_shots($run, $sources)];
    if ($problems !== []) {
        return ['page' => null, 'problems' => $problems];
    }
    if ($home !== $dir) {
        @rename($home, $dir);
    }
    if (! is_dir("{$dir}/shots") && ! @mkdir("{$dir}/shots", 0777, true) && ! is_dir("{$dir}/shots")) {
        return ['page' => null, 'problems' => ["cannot create {$dir}/shots"]];
    }
    $run = proof_store_shots($dir, $run, $sources);
    $tests = proof_store_added_tests($run);
    if (is_string($tests)) {
        fwrite(STDERR, "proof: tests not extracted: {$tests}\n");
    } else {
        $run['addedTests'] = $tests;
    }
    $run = proof_write_run($dir, $run, $now);
    file_put_contents("{$dir}/index.html", proof_render_run($run));
    file_put_contents("{$root}/index.html", proof_render_index(proof_scan_runs($root)));

    return ['page' => "{$dir}/index.html", 'problems' => []];
}

/**
 * A shot with neither a stored `file` nor a source at its position would render as a broken image: a carried
 * shot re-sent without its `file` is refused instead.
 *
 * @return list<string>
 */
function proof_store_unsourced_shots(array $run, array $sources): array
{
    $problems = [];
    foreach ($run['shots'] ?? [] as $i => $shot) {
        if (! isset($shot['file']) && ! isset($sources[$i])) {
            $problems[] = sprintf('shot %d has no file and no source: carry its file, or send its screenshot', $i + 1);
        }
    }

    return $problems;
}

/**
 * Where the run goes and where it is now. A run filed before its directory was keyed by PR, or by a write that
 * beat the PR into existence, still lives under its branch slug: filing adopts that directory rather than
 * starting an empty one beside it, which would orphan its shots and show the run twice in the index.
 *
 * @return array{0: string, 1: string} the PR-keyed directory, and the one the stored run is in
 */
function proof_store_dirs(string $root, array $run): array
{
    $repo = (string) ($run['repo'] ?? 'unknown');
    $branch = (string) ($run['branch'] ?? 'unknown');
    $dir = proof_run_dir($root, $repo, $branch, $run['pr'] ?? null);
    $legacy = proof_run_dir($root, $repo, $branch);

    return [$dir, $dir !== $legacy && ! is_dir($dir) && is_dir($legacy) ? $legacy : $dir];
}

/**
 * Ingests each source as the shot at its position, named `<NN>-<route>-<hash>.png` by the first eight hex
 * digits of its sha1: a shot carried forward (`null` in `shotSources`) keeps its file, no new shot can
 * overwrite it, and the same source writes the same name. A source that is not a file is skipped.
 */
function proof_store_shots(string $dir, array $run, array $sources): array
{
    foreach ($sources as $i => $source) {
        $source = (string) $source;
        if (! is_file($source)) {
            continue;
        }
        $route = proof_slug((string) ($run['shots'][$i]['route'] ?? 'state'));
        $name = sprintf('%02d-%s-%s.png', $i + 1, $route, substr((string) sha1_file($source), 0, 8));
        if (proof_store_ingest_shot($source, "{$dir}/shots/{$name}")) {
            $run['shots'][$i]['file'] = "shots/{$name}";
        }
    }

    return $run;
}

/**
 * Downscale to at most 1600px wide. PNG is kept rather than JPEG: JPEG artefacts on UI text
 * are exactly the kind of difference a proof page must not introduce.
 */
function proof_store_ingest_shot(string $source, string $destination): bool
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

/**
 * The tests the run's branch adds or changes against its base, read in its worktree at every write, or why not:
 * the caller then keeps the stored list, so a page filed after the worktree was removed keeps the one it had.
 *
 * @return list<array{file: string, cases: list<array{name: string, change: string}>}>|string
 */
function proof_store_added_tests(array $run): array|string
{
    $worktree = rtrim((string) ($run['worktree'] ?? ''), '/');
    $base = (string) ($run['base'] ?? '');
    if ($worktree === '') {
        return 'the run names no worktree';
    }
    if (! is_dir($worktree)) {
        return "no worktree at {$worktree}";
    }
    if ($base === '') {
        return 'the run names no base';
    }
    [$code, $diff, $err] = pipeline_git_run($worktree, ['diff', "origin/{$base}...HEAD"]);
    if ($code !== 0) {
        return "git diff origin/{$base}...HEAD failed: {$err}";
    }

    return proof_added_tests($diff, function (string $path) use ($worktree): ?string {
        [$code, $content] = pipeline_git_run($worktree, ['show', "HEAD:{$path}"]);

        return $code === 0 ? $content : null;
    });
}
