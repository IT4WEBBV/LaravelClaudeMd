<?php

/**
 * The pipeline's commands (`../references/engine.md` §The loop).
 *
 *   interactive:  php dispatch_cli.php next <manifest>
 *                 php dispatch_cli.php returned <manifest> <diff-file>
 *   autoflow:     php dispatch_cli.php kickoff <repo-root> <number|idea> [--light] [--mode autoflow|auto] [--decision <text>]...
 *                 php dispatch_cli.php launch <manifest> <diff-file> [--from <leg>]
 *                 php dispatch_cli.php brief <manifest> <leg> <step>
 *                 php dispatch_cli.php finish <manifest> <decision-json>
 *                 php dispatch_cli.php size <manifest>
 *                 php dispatch_cli.php ui <diff-file>
 *
 * `brief` prints the brief as Markdown; `size` and `ui` print a bare value for a step to copy
 * (`Bounded` / `Architectural`, `true` / `false`); every other answer, and a `brief` that halts, is
 * one JSON line. Exits 0 on every decision, a halt included. Exits 1 on a usage error (a `kickoff`
 * it cannot parse included), and when `size` has no readable manifest or `ui` no diff file.
 */

require_once __DIR__ . '/triggers.php';
require_once __DIR__ . '/pipeline.php';
require_once __DIR__ . '/manifest.php';
require_once __DIR__ . '/design_size.php';
require_once __DIR__ . '/dispatch.php';
require_once __DIR__ . '/brief.php';
require_once __DIR__ . '/suite.php';
require_once __DIR__ . '/kickoff.php';

/** @return array{brief: string, before: string} */
function dispatch_cli_files(string $manifestPath): array
{
    $stem = preg_replace('/\.json$/', '', $manifestPath);

    return ['brief' => "{$stem}.brief.md", 'before' => "{$stem}.before.json"];
}

function dispatch_cli_emit(string $manifestPath, array $manifest, string $action = 'dispatch'): array
{
    $leg = $manifest['cursor']['leg'];
    $step = pipeline_step($manifest, $leg);
    $files = dispatch_cli_files($manifestPath);

    manifest_write($manifestPath, $manifest);
    manifest_write($files['before'], $manifest);
    file_put_contents($files['brief'], pipeline_brief($manifest, $leg, $manifestPath, $step));

    return [
        'action' => $action,
        'leg' => $leg,
        'step' => $step,
        'inline' => pipeline_runs_inline((string) $manifest['mode'], $leg, $step),
        'prompt' => "You are the `{$leg}` leg ({$step}) of a /pipeline run. Your brief is {$files['brief']}; read it first, it is complete.",
    ];
}

function dispatch_cli_halt(string $manifestPath, array $manifest, string $leg, string $reason): array
{
    manifest_write($manifestPath, [...$manifest, 'cursor' => ['leg' => $leg, 'status' => 'halted', 'reason' => $reason]]);

    return pipeline_halt($reason);
}

function dispatch_cli_next(string $manifestPath): array
{
    $manifest = manifest_read($manifestPath);
    if ($manifest === null) {
        return pipeline_halt("no readable manifest at {$manifestPath}");
    }
    if (($manifest['mode'] ?? null) === 'autoflow') {
        return pipeline_halt('an autoflow run resumes with launch, not next');
    }
    if (dispatch_cli_finished($manifest)) {
        return ['action' => 'done'];
    }
    $invalid = dispatch_cli_invalid($manifest);
    if ($invalid !== null) {
        return pipeline_halt($invalid);
    }

    return dispatch_cli_emit($manifestPath, [...$manifest, 'cursor' => ['leg' => $manifest['cursor']['leg'], 'status' => 'pending']]);
}

function dispatch_cli_invalid(array $manifest): ?string
{
    $missing = manifest_validate($manifest);
    if ($missing !== []) {
        return 'the manifest is invalid: missing ' . implode(', ', $missing);
    }

    return in_array($manifest['cursor']['leg'] ?? null, pipeline_legs(), true) ? null : 'the manifest is invalid: cursor.leg is not a leg';
}

/** `launch`, `brief` and `finish` serve `autoflow` runs only; a valid manifest of any other mode resumes with `next`. */
function dispatch_cli_mode_problem(string $refusal, array $manifest): ?string
{
    return $manifest['mode'] === 'autoflow' ? null : "{$refusal}; this run's mode is {$manifest['mode']} (resume it with /pipeline, which uses next)";
}

/** Finished: `status: done` as this dispatcher writes it, or the old engine's `leg: done`. */
function dispatch_cli_finished(array $manifest): bool
{
    return ($manifest['cursor']['status'] ?? null) === 'done' || ($manifest['cursor']['leg'] ?? null) === 'done';
}

function dispatch_cli_returned(string $manifestPath, string $diffPath): array
{
    $before = manifest_read(dispatch_cli_files($manifestPath)['before']);
    $after = manifest_read($manifestPath);
    if ($before === null || $after === null || ! is_file($diffPath)) {
        return pipeline_halt('cannot check the return: the snapshot, the manifest or the diff file is missing');
    }

    $triggers = pipeline_triggers((string) file_get_contents($diffPath));
    $decision = pipeline_returned($before, $after, $triggers, dispatch_cli_design_size($after));

    return match ($decision['action']) {
        'dispatch' => dispatch_cli_emit($manifestPath, [...$after, 'cursor' => ['leg' => $decision['leg'], 'status' => 'pending']]),
        'retry' => dispatch_cli_emit($manifestPath, [...$before, 'cursor' => [...$before['cursor'], 'retried' => true]], 'retry'),
        'halt' => dispatch_cli_halt($manifestPath, $after, $before['cursor']['leg'], $decision['reason']),
        'done' => dispatch_cli_done($manifestPath, $after),
    };
}

/** A finished run says so in its cursor, so a later `next` does not re-dispatch review-pr. */
function dispatch_cli_done(string $manifestPath, array $manifest): array
{
    manifest_write($manifestPath, [...$manifest, 'cursor' => ['leg' => $manifest['cursor']['leg'], 'status' => 'done']]);

    return ['action' => 'done'];
}

/** Read from the committed spec, never stored; a spec that cannot be read is Architectural. */
function dispatch_cli_design_size(array $manifest): DesignSize
{
    $spec = (string) ($manifest['artifacts']['spec'] ?? '');
    $path = $spec === '' || str_starts_with($spec, '/') ? $spec : rtrim($manifest['worktree'], '/') . '/' . $spec;

    return DesignSize::fromSpec($path !== '' && is_file($path) ? (string) file_get_contents($path) : '');
}

/** What the run needs once, at its start (`../references/engine.md` §`autoflow` — a program that calls agents): the workflow script's `args`. */
function dispatch_cli_launch(string $manifestPath, string $diffPath, ?string $from): array
{
    $manifest = manifest_read($manifestPath);
    if ($manifest === null || ! is_file($diffPath)) {
        return pipeline_halt("cannot launch: the manifest {$manifestPath} or the diff file {$diffPath} is missing");
    }
    $problem = dispatch_cli_invalid($manifest) ?? dispatch_cli_mode_problem('launch starts autoflow runs', $manifest);
    if ($problem !== null) {
        return pipeline_halt($problem);
    }
    $triggers = pipeline_triggers((string) file_get_contents($diffPath));

    if ($from !== null) {
        if (! in_array($from, pipeline_legs(), true)) {
            return pipeline_halt("cannot re-arm the run at '{$from}': not a leg");
        }
        if (! pipeline_can_navigate($manifest['cursor']['leg'], $from, pipeline_done_legs($manifest['gate_ledger'] ?? []), $triggers)) {
            return pipeline_halt("cannot re-arm the run at {$from}: a gate before it has not run");
        }
        $manifest = [...$manifest, 'cursor' => ['leg' => $from, 'status' => 'pending']];
        manifest_write($manifestPath, $manifest);
    }
    if (dispatch_cli_finished($manifest)) {
        return ['action' => 'done'];
    }
    $leg = $manifest['cursor']['leg'];
    $problem = dispatch_cli_invariant_problem($manifest);
    if ($problem !== null) {
        return dispatch_cli_halt($manifestPath, $manifest, $leg, $problem);
    }

    return [
        'action' => 'start',
        'startLeg' => $leg,
        'startStep' => pipeline_step($manifest, $leg),
        'loops' => pipeline_loop_counts($manifest['gate_ledger'] ?? []),
        'ui' => $triggers['ui'],
        'size' => dispatch_cli_design_size($manifest)->value,
        'manifest' => $manifestPath,
        'worktree' => $manifest['worktree'],
        'noOpen' => ! in_array((string) getenv('PIPELINE_NO_OPEN'), ['', '0'], true),
        'checks' => __DIR__,
    ];
}

/** `../references/manifest.md` §Invariant check, once per launch. */
function dispatch_cli_invariant_problem(array $manifest): ?string
{
    $worktree = rtrim($manifest['worktree'], '/');
    $sha = $manifest['last_sha'] ?? null;
    foreach (['spec', 'plan'] as $name) {
        $path = $manifest['artifacts'][$name] ?? null;
        if ($sha === null || $path === null) {
            continue;
        }
        $relative = str_starts_with($path, "{$worktree}/") ? substr($path, strlen($worktree) + 1) : $path;
        if (pipeline_git_run($worktree, ['cat-file', '-e', "{$sha}:{$relative}"])[0] !== 0) {
            return "the recorded {$name} {$path} does not exist at {$sha}";
        }
    }
    $pr = $manifest['artifacts']['pr'] ?? null;

    return $pr === null ? null : pipeline_pr_problem($pr, dispatch_cli_pr_view($worktree, $pr));
}

/** `gh pr view` from the worktree, or null when gh cannot read the PR. */
function dispatch_cli_pr_view(string $worktree, int|string $pr): ?array
{
    $process = proc_open(['gh', 'pr', 'view', (string) $pr, '--json', 'state,isDraft'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $worktree);
    if (! is_resource($process)) {
        return null;
    }
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $view = proc_close($process) === 0 ? json_decode($out, true) : null;

    return is_array($view) ? $view : null;
}

/** An `autoflow` step's first command: the step the script chose becomes the cursor, then its brief. */
function dispatch_cli_brief(string $manifestPath, string $leg, string $step): array|string
{
    $manifest = manifest_read($manifestPath);
    if ($manifest === null) {
        return pipeline_halt("no readable manifest at {$manifestPath}");
    }
    $problem = dispatch_cli_invalid($manifest)
        ?? dispatch_cli_mode_problem('brief serves autoflow steps', $manifest)
        ?? pipeline_step_problem($manifest, $leg, $step);
    if ($problem !== null) {
        return pipeline_halt($problem);
    }
    $manifest = [...$manifest, 'cursor' => ['leg' => $leg, 'status' => 'pending']];
    manifest_write($manifestPath, $manifest);

    return pipeline_brief($manifest, $leg, $manifestPath, $step);
}

/**
 * Records the workflow's return; anything that is not `done` is a halt, and a halt with no reason says
 * so. `done` counts only on `review-pr`: nothing else may lead to `gh pr ready`. A halt that names no
 * leg of the pipeline keeps the cursor's, so a later `launch` can still read the run.
 */
function dispatch_cli_finish(string $manifestPath, string $decisionJson): array
{
    $manifest = manifest_read($manifestPath);
    if ($manifest === null) {
        return pipeline_halt("no readable manifest at {$manifestPath}");
    }
    $problem = dispatch_cli_invalid($manifest) ?? dispatch_cli_mode_problem('finish records autoflow runs', $manifest);
    if ($problem !== null) {
        return pipeline_halt($problem);
    }
    $leg = $manifest['cursor']['leg'];
    $decision = json_decode($decisionJson, true);
    $decision = is_array($decision) ? $decision : [];
    if (($decision['action'] ?? null) === 'done') {
        return $leg === 'review-pr'
            ? dispatch_cli_done($manifestPath, $manifest)
            : dispatch_cli_halt($manifestPath, $manifest, $leg, "the workflow returned done at {$leg}");
    }
    $reason = trim((string) ($decision['reason'] ?? ''));
    $named = $decision['leg'] ?? null;

    return dispatch_cli_halt(
        $manifestPath,
        $manifest,
        in_array($named, pipeline_legs(), true) ? $named : $leg,
        $reason === '' ? "the workflow returned no decision: {$decisionJson}" : $reason,
    );
}

/** An `autoflow` design step's `size`: the committed spec's, as `launch` reads it. */
function dispatch_cli_size(string $manifestPath): ?string
{
    $manifest = manifest_read($manifestPath);

    return $manifest === null ? null : dispatch_cli_design_size($manifest)->value . "\n";
}

/** An `autoflow` implement step's `ui`: `pipeline_triggers()` over the diff it wrote. */
function dispatch_cli_ui(string $diffPath): ?string
{
    return is_file($diffPath) ? json_encode(pipeline_triggers((string) file_get_contents($diffPath))['ui']) . "\n" : null;
}

/**
 * `kickoff <repo-root> <number|idea> [--light] [--mode autoflow|auto] [--decision <text>]...`; null is a
 * usage error. `interactive` keeps its session-driven kickoff.
 *
 * @return array{repoRoot: string, item: string, mode: string, light: bool, decisions: list<string>}|null
 */
function dispatch_cli_kickoff_args(array $arguments): ?array
{
    $options = ['mode' => 'autoflow', 'light' => false, 'decisions' => []];
    $positional = [];
    while ($arguments !== []) {
        $argument = (string) array_shift($arguments);
        if ($argument === '--light') {
            $options['light'] = true;

            continue;
        }
        if ($argument === '--mode' || $argument === '--decision') {
            $value = array_shift($arguments);
            if ($value === null) {
                return null;
            }
            if ($argument === '--mode') {
                $options['mode'] = (string) $value;
            } else {
                $options['decisions'][] = (string) $value;
            }

            continue;
        }
        if (str_starts_with($argument, '--')) {
            return null;
        }
        $positional[] = $argument;
    }

    return count($positional) === 2 && in_array($options['mode'], ['autoflow', 'auto'], true)
        ? ['repoRoot' => $positional[0], 'item' => $positional[1], ...$options]
        : null;
}

function dispatch_cli_kickoff(array $arguments): ?array
{
    $parsed = dispatch_cli_kickoff_args($arguments);

    return $parsed === null ? null : pipeline_kickoff($parsed['repoRoot'], $parsed['item'], $parsed);
}

$flag = array_search('--from', $argv, true);
$result = match ($argv[1] ?? '') {
    'kickoff' => dispatch_cli_kickoff(array_slice($argv, 2)),
    'next' => dispatch_cli_next((string) ($argv[2] ?? '')),
    'returned' => dispatch_cli_returned((string) ($argv[2] ?? ''), (string) ($argv[3] ?? '')),
    'launch' => dispatch_cli_launch((string) ($argv[2] ?? ''), (string) ($argv[3] ?? ''), $flag === false ? null : (string) ($argv[$flag + 1] ?? '')),
    'brief' => dispatch_cli_brief((string) ($argv[2] ?? ''), (string) ($argv[3] ?? ''), (string) ($argv[4] ?? '')),
    'finish' => dispatch_cli_finish((string) ($argv[2] ?? ''), (string) ($argv[3] ?? '')),
    'size' => dispatch_cli_size((string) ($argv[2] ?? '')),
    'ui' => dispatch_cli_ui((string) ($argv[2] ?? '')),
    default => null,
};

if ($result === null) {
    fwrite(STDERR, "usage: dispatch_cli.php kickoff <repo-root> <number|idea> [--light] [--mode autoflow|auto] [--decision <text>]... | next <manifest> | returned <manifest> <diff-file> | launch <manifest> <diff-file> [--from <leg>] | brief <manifest> <leg> <step> | finish <manifest> <decision-json> | size <manifest> | ui <diff-file> (size needs a readable manifest, ui an existing diff file)\n");
    exit(1);
}

echo is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
exit(0);
