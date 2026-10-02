<?php

/**
 * The pipeline's commands (`../references/engine.md` §The loop).
 *
 *   interactive:  php dispatch_cli.php next <manifest>
 *                 php dispatch_cli.php returned <manifest> <diff-file>
 *   autoflow:     php dispatch_cli.php kickoff <repo-root> <number|idea> [--medium|--light] [--base <branch>] [--decision <text>]...
 *                 php dispatch_cli.php launch <manifest> <diff-file> [--from <leg>] [--decision <text>]...
 *                 php dispatch_cli.php brief <manifest> <leg> <step> [--after <leg>:<step> --status <status> [--ui true|false] [--size <size>]]
 *                 php dispatch_cli.php finish <manifest> <decision-json>
 *                 php dispatch_cli.php size <manifest>
 *                 php dispatch_cli.php ui <diff-file>
 *                 php dispatch_cli.php ci <manifest> [--poll <n>]
 *   both:         php dispatch_cli.php record <manifest> <leg> <step> --status <status> [flags]
 *                 php dispatch_cli.php suite <manifest> --outcome green|red --passed <n> --failed <n>
 *                 php dispatch_cli.php handoff <manifest>
 *
 * `brief` prints the brief as Markdown; `size` and `ui` print a bare value for a step to copy
 * (`Bounded` / `Architectural`, `true` / `false`); every other answer, and a `brief` that halts, is
 * one JSON line. Exits 0 on every decision, a halt included; `record`, `suite` and `handoff` exit 1 on
 * a refusal, which is no decision about the run and leaves the manifest untouched, so a failed write cannot
 * be missed in an `&&` chain. Exits 1 on a usage error (a `kickoff`, a `launch`, a `brief` or a `ci` it
 * cannot parse included), and when `size` has no readable manifest or `ui` no diff file.
 */

require_once __DIR__ . '/triggers.php';
require_once __DIR__ . '/pipeline.php';
require_once __DIR__ . '/manifest.php';
require_once __DIR__ . '/design_size.php';
require_once __DIR__ . '/dispatch.php';
require_once __DIR__ . '/agents.php';
require_once __DIR__ . '/brief.php';
require_once __DIR__ . '/record.php';
require_once __DIR__ . '/suite.php';
require_once __DIR__ . '/kickoff.php';
require_once __DIR__ . '/gh.php';
require_once __DIR__ . '/handoff.php';
require_once __DIR__ . '/ci.php';
require_once __DIR__ . '/proof_store.php';

/** git in the run's worktree, for the scope of a re-review of the PR (`pipeline_review_scope()`). */
function dispatch_cli_git(array $manifest): Closure
{
    $worktree = rtrim($manifest['worktree'], '/');

    return fn (array $args): array => pipeline_git_run($worktree, $args);
}

function dispatch_cli_emit(string $manifestPath, array $manifest, string $action = 'dispatch'): array
{
    $leg = $manifest['cursor']['leg'];
    $step = pipeline_step($manifest, $leg);
    $files = manifest_files($manifestPath);

    manifest_write($manifestPath, $manifest);
    manifest_write($files['before'], $manifest);
    file_put_contents($files['brief'], pipeline_brief($manifest, $leg, $manifestPath, $step, dispatch_cli_git($manifest)));

    return [
        'action' => $action,
        'leg' => $leg,
        'step' => $step,
        'inline' => pipeline_runs_inline((string) $manifest['mode'], $leg, $step),
        'prompt' => "You are the `{$leg}` leg ({$step}) of a /pipeline run. Your brief is {$files['brief']}; read it first, it is complete.",
    ];
}

/**
 * Marks the run's proof page (`artifacts.proof`) when the manifest names one (`../references/engine.md` §The proof
 * store, *who writes each status*). Never part of the answer and never a halt: a page that cannot be amended is one
 * line on stderr. The engine still never reads the store to decide anything.
 */
function dispatch_cli_proof_status(array $manifest, ProofRunStatus $status, string $reason = ''): void
{
    $page = $manifest['artifacts']['proof'] ?? null;
    if (! is_string($page) || $page === '') {
        return;
    }
    $problem = proof_store_status($page, $status, $reason);
    if ($problem !== null) {
        fwrite(STDERR, "proof: status not written: {$problem}\n");
    }
}

function dispatch_cli_halt(string $manifestPath, array $manifest, string $leg, string $reason): array
{
    manifest_write($manifestPath, [...$manifest, 'cursor' => ['leg' => $leg, 'status' => 'halted', 'reason' => $reason]]);
    dispatch_cli_proof_status($manifest, ProofRunStatus::Halted, $reason);

    return pipeline_halt($reason);
}

function dispatch_cli_next(string $manifestPath): array
{
    $manifest = manifest_read($manifestPath);
    if ($manifest === null) {
        return pipeline_halt("no readable manifest at {$manifestPath}");
    }
    $mode = (string) ($manifest['mode'] ?? '');
    $refusal = pipeline_retired_mode($mode) ?? ($mode === 'autoflow' ? 'an autoflow run resumes with launch, not next' : null);
    if ($refusal !== null) {
        return pipeline_halt($refusal);
    }
    if (manifest_finished($manifest)) {
        return ['action' => 'done'];
    }
    $invalid = dispatch_cli_invalid($manifest);
    if ($invalid !== null) {
        return pipeline_halt($invalid);
    }
    dispatch_cli_proof_status($manifest, ProofRunStatus::Running);

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

/** `launch`, `brief` and `finish` serve `autoflow` runs only; a valid manifest of any other mode but the removed `auto` resumes with `next`. */
function dispatch_cli_mode_problem(string $refusal, array $manifest): ?string
{
    $mode = (string) ($manifest['mode'] ?? '');

    return pipeline_retired_mode($mode) ?? ($mode === 'autoflow' ? null : "{$refusal}; this run's mode is {$mode} (resume it with /pipeline, which uses next)");
}

/** A hand-set `agents` override `launch` cannot hand to the script (`../references/engine.md` §Agents per step), or null. */
function dispatch_cli_agents_problem(array $manifest): ?string
{
    $problem = pipeline_agent_override_problem($manifest['agents'] ?? []);

    return $problem === null ? null : "the manifest's agents override is invalid: {$problem}";
}

/** A `tier` kickoff cannot have written (`../references/manifest.md`): present, and not `medium` or `light`; or null. */
function dispatch_cli_tier_problem(array $manifest): ?string
{
    if (! array_key_exists('tier', $manifest) || in_array($manifest['tier'], [AgentTier::Medium->value, AgentTier::Light->value], true)) {
        return null;
    }

    return "the manifest's tier is invalid: " . json_encode($manifest['tier']) . ' is not medium or light';
}

function dispatch_cli_returned(string $manifestPath, string $diffPath): array
{
    $before = manifest_read(manifest_files($manifestPath)['before']);
    $after = manifest_read($manifestPath);
    if ($before === null || $after === null || ! is_file($diffPath)) {
        return pipeline_halt('cannot check the return: the snapshot, the manifest or the diff file is missing');
    }
    $retired = pipeline_retired_mode((string) ($before['mode'] ?? ''));
    if ($retired !== null) {
        return pipeline_halt($retired);
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

/**
 * A finished run says so in its cursor, so a later `next` does not re-dispatch review-pr. The answer names the
 * proof page, so the session that runs `gh pr ready` marks it ready (`proof_cli.php status <page> ready`) without
 * reading the manifest.
 */
function dispatch_cli_done(string $manifestPath, array $manifest): array
{
    manifest_write($manifestPath, [...$manifest, 'cursor' => ['leg' => $manifest['cursor']['leg'], 'status' => 'done']]);

    return ['action' => 'done', 'proof' => $manifest['artifacts']['proof'] ?? null];
}

/** Read from the committed spec, never stored; a spec that cannot be read is Architectural. */
function dispatch_cli_design_size(array $manifest): DesignSize
{
    $spec = (string) ($manifest['artifacts']['spec'] ?? '');
    $path = $spec === '' || str_starts_with($spec, '/') ? $spec : rtrim($manifest['worktree'], '/') . '/' . $spec;

    return DesignSize::fromSpec($path !== '' && is_file($path) ? (string) file_get_contents($path) : '');
}

/**
 * What the run needs once, at its start (`../references/engine.md` §`autoflow` — a program that calls agents): the workflow script's `args`.
 * `$decisions` are appended to `decisions` verbatim, in the same write as `--from`'s re-arm and after its checks.
 */
function dispatch_cli_launch(string $manifestPath, string $diffPath, ?string $from, array $decisions = []): array
{
    $manifest = manifest_read($manifestPath);
    if ($manifest === null || ! is_file($diffPath)) {
        return pipeline_halt("cannot launch: the manifest {$manifestPath} or the diff file {$diffPath} is missing");
    }
    $problem = dispatch_cli_invalid($manifest)
        ?? dispatch_cli_mode_problem('launch starts autoflow runs', $manifest)
        ?? dispatch_cli_agents_problem($manifest)
        ?? dispatch_cli_tier_problem($manifest);
    if ($problem !== null) {
        return pipeline_halt($problem);
    }
    $triggers = pipeline_triggers((string) file_get_contents($diffPath));

    if ($from !== null) {
        if (! in_array($from, pipeline_legs(), true)) {
            return pipeline_halt("cannot re-arm the run at '{$from}': not a leg");
        }
        if (! pipeline_can_navigate($manifest['cursor']['leg'], $from, pipeline_done_legs(pipeline_ledger($manifest)), $triggers)) {
            return pipeline_halt("cannot re-arm the run at {$from}: a gate before it has not run");
        }
        $manifest = [...$manifest, 'cursor' => ['leg' => $from, 'status' => 'pending']];
    }
    if ($decisions !== []) {
        $manifest = [...$manifest, 'decisions' => [...($manifest['decisions'] ?? []), ...$decisions]];
    }
    if ($from !== null || $decisions !== []) {
        manifest_write($manifestPath, $manifest);
    }
    if (manifest_finished($manifest)) {
        return ['action' => 'done'];
    }
    $leg = $manifest['cursor']['leg'];
    $problem = dispatch_cli_invariant_problem($manifest);
    if ($problem !== null) {
        return dispatch_cli_halt($manifestPath, $manifest, $leg, $problem);
    }

    $snapshot = manifest_files($manifestPath)['before'];
    if (is_file($snapshot)) {
        unlink($snapshot);
    }
    $size = dispatch_cli_design_size($manifest);
    dispatch_cli_proof_status($manifest, ProofRunStatus::Running);

    return [
        'action' => 'start',
        'startLeg' => $leg,
        'startStep' => pipeline_step($manifest, $leg),
        'loops' => pipeline_loop_counts(pipeline_ledger($manifest)),
        'ui' => $triggers['ui'],
        'size' => $size->value,
        'manifest' => $manifestPath,
        'worktree' => $manifest['worktree'],
        'noOpen' => ! in_array((string) getenv('PIPELINE_NO_OPEN'), ['', '0'], true),
        'checks' => __DIR__,
        'tables' => pipeline_routing_tables(),
        'profile' => pipeline_start_profile($manifest, $size),
        'tier' => AgentTier::fromManifest($manifest)->value,
        'escalated' => pipeline_escalated(pipeline_ledger($manifest)),
        'agents' => pipeline_agent_table($manifest['agents'] ?? []),
    ];
}

/** Whether `$path`, relative to the worktree or absolute under it, exists at `$ref`. */
function dispatch_cli_exists_at(string $worktree, string $ref, string $path): bool
{
    return pipeline_git_run($worktree, ['cat-file', '-e', "{$ref}:" . pipeline_relative_path($worktree, $path)])[0] === 0;
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
        if (! dispatch_cli_exists_at($worktree, $sha, $path)) {
            return "the recorded {$name} {$path} does not exist at {$sha}";
        }
    }
    $pr = $manifest['artifacts']['pr'] ?? null;

    return $pr === null ? null : pipeline_pr_problem($pr, dispatch_cli_pr_view($worktree, $pr));
}

/** `gh pr view` from the worktree, or null when gh cannot read the PR. */
function dispatch_cli_pr_view(string $worktree, int|string $pr, string $fields = 'state,isDraft'): ?array
{
    $process = proc_open(['gh', 'pr', 'view', (string) $pr, '--json', $fields], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $worktree);
    if (! is_resource($process)) {
        return null;
    }
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $view = proc_close($process) === 0 ? json_decode($out, true) : null;

    return is_array($view) ? $view : null;
}

/**
 * An `autoflow` step's first command. It checks the step before it when the script names one
 * (`--after`), then the step the script chose becomes the cursor, the snapshot is taken, and the brief
 * is printed. A halt from that check leaves the cursor on the step that failed it.
 *
 * @param array{after?: string, status?: string, ui?: string, size?: string} $reported
 */
function dispatch_cli_brief(string $manifestPath, string $leg, string $step, array $reported = []): array|string
{
    $manifest = manifest_read($manifestPath);
    if ($manifest === null) {
        return pipeline_halt("no readable manifest at {$manifestPath}");
    }
    $problem = dispatch_cli_invalid($manifest) ?? dispatch_cli_mode_problem('brief serves autoflow steps', $manifest);
    if ($problem !== null) {
        return pipeline_halt($problem);
    }
    $boundary = dispatch_cli_boundary_problem($manifestPath, $manifest, "{$leg}:{$step}", $reported);
    if ($boundary !== null) {
        return dispatch_cli_halt($manifestPath, $manifest, $boundary['leg'], $boundary['reason']);
    }
    $problem = pipeline_step_problem($manifest, $leg, $step);
    if ($problem !== null) {
        return pipeline_halt($problem);
    }
    $manifest = [...$manifest, 'cursor' => ['leg' => $leg, 'status' => 'pending']];
    manifest_write($manifestPath, $manifest);
    manifest_write(manifest_files($manifestPath)['before'], $manifest);

    return pipeline_brief($manifest, $leg, $manifestPath, $step, dispatch_cli_git($manifest));
}

/**
 * What stops `$next` from being briefed after the step `--after` names: null when nothing does, or
 * when no step ran before it in this run (no `--after`, and `launch` left no snapshot). A brief
 * without `--after` over another step's snapshot halts: the step agent dropped the script's flags.
 *
 * @return array{leg: string, reason: string}|null
 */
function dispatch_cli_boundary_problem(string $manifestPath, array $manifest, string $next, array $reported): ?array
{
    $after = $reported['after'] ?? null;
    $files = manifest_files($manifestPath);
    $before = manifest_read($files['before']);
    $snapshot = dispatch_cli_snapshot_step($before);
    $label = fn (string $pair) => str_replace(':', ' ', $pair);

    return match (true) {
        $snapshot === null && $after === null => null,
        $snapshot === null => ['leg' => explode(':', $after)[0], 'reason' => "cannot check the {$label($after)} step's return: no snapshot at {$files['before']}"],
        $snapshot === $next => pipeline_normalized($before) === pipeline_normalized($manifest)
            ? null
            : ['leg' => $before['cursor']['leg'], 'reason' => "the {$label($next)} step returned nothing and changed the manifest"],
        $after === null => ['leg' => $before['cursor']['leg'], 'reason' => "a snapshot of the {$label($snapshot)} step exists, but the script names no step before {$label($next)}"],
        $snapshot !== $after => ['leg' => explode(':', $after)[0], 'reason' => "the snapshot is of the {$label($snapshot)} step, but the script says {$label($after)} returned: it did not run brief"],
        default => dispatch_cli_return_problem($manifestPath, $before, $manifest, $reported),
    };
}

/** `<leg>:<step>` the snapshot was taken for, as `returned` reads it; null when there is no valid snapshot. */
function dispatch_cli_snapshot_step(?array $before): ?string
{
    if ($before === null || dispatch_cli_invalid($before) !== null) {
        return null;
    }
    $leg = $before['cursor']['leg'];

    return "{$leg}:" . pipeline_step($before, $leg);
}

/** `pipeline_reported_problem()` over the snapshot, with the design size and, after `implement`, the step's own diff. */
function dispatch_cli_return_problem(string $manifestPath, array $before, array $manifest, array $reported): ?array
{
    $leg = $before['cursor']['leg'];
    $files = manifest_files($manifestPath);
    if ($leg === 'implement' && (! is_file($files['diff']) || filemtime($files['diff']) < filemtime($files['before']))) {
        return ['leg' => $leg, 'reason' => "the implement step did not write {$files['diff']}"];
    }
    $diffUi = $leg === 'implement' ? pipeline_triggers((string) file_get_contents($files['diff']))['ui'] : null;
    $reason = pipeline_reported_problem($before, $manifest, $reported, dispatch_cli_design_size($manifest), $diffUi);

    return $reason === null ? null : ['leg' => $leg, 'reason' => $reason];
}

/** A halt the script's relay check returned: the run started framed by a relayed chat message, and no step ran (`../references/engine.md` §`autoflow`). */
function dispatch_cli_is_relay(string $reason): bool
{
    return str_starts_with(trim($reason), 'relay:');
}

/**
 * Records the workflow's return; anything that is not `done` is a halt, and a halt with no reason says
 * so. `done` counts only on `review-pr`, and only when its resolve step's return holds: nothing else
 * may lead to `gh pr ready`. A halt keeps the cursor's leg when the cursor already records a halt
 * (`brief` or the step wrote it there) or when the return names no leg of the pipeline, so a later
 * `launch` resumes at the step that failed. A `relay:` halt (dispatch_cli_is_relay()) also answers
 * `relaunch: true`, unless the cursor it overwrites is already one: the invoking session starts the run
 * once more through the detour, and a run that got past the check has overwritten that cursor at its
 * first brief.
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
        $problem = $leg === 'review-pr' ? dispatch_cli_finish_problem($manifestPath, $manifest) : "the workflow returned done at {$leg}";

        return $problem === null ? dispatch_cli_done($manifestPath, $manifest) : dispatch_cli_halt($manifestPath, $manifest, $leg, $problem);
    }
    $reason = trim((string) ($decision['reason'] ?? ''));
    $named = $decision['leg'] ?? null;
    $recorded = ($manifest['cursor']['status'] ?? null) === 'halted';

    $halt = dispatch_cli_halt(
        $manifestPath,
        $manifest,
        in_array($named, pipeline_legs(), true) && ! $recorded ? $named : $leg,
        $reason === '' ? "the workflow returned no decision: {$decisionJson}" : $reason,
    );
    $relaunched = $recorded && dispatch_cli_is_relay((string) ($manifest['cursor']['reason'] ?? ''));

    return dispatch_cli_is_relay($reason) && ! $relaunched ? [...$halt, 'relaunch' => true] : $halt;
}

/** Why a `done` return does not hold: the last step must be `review-pr`'s resolve step, and its return must pass the check. */
function dispatch_cli_finish_problem(string $manifestPath, array $manifest): ?string
{
    $before = manifest_read(manifest_files($manifestPath)['before']);
    $snapshot = dispatch_cli_snapshot_step($before);
    if ($snapshot !== 'review-pr:resolve') {
        return $snapshot === null
            ? 'the workflow returned done, but there is no snapshot at ' . manifest_files($manifestPath)['before']
            : 'the workflow returned done, but the last snapshot is of the ' . str_replace(':', ' ', $snapshot) . ' step';
    }

    return dispatch_cli_return_problem($manifestPath, $before, $manifest, ['status' => 'continued'])['reason'] ?? null;
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
 * The CI gate's reads (`../references/engine.md` §The CI gate): the worktree's `HEAD`, then the PR's head
 * commit and its checks in one gh call, the merges since the last completed review in an `autoflow` run,
 * and what the session does next. It never writes the manifest, so polling it changes nothing.
 */
function dispatch_cli_ci(string $manifestPath, int $poll): array
{
    $manifest = manifest_read($manifestPath);
    if ($manifest === null) {
        return pipeline_halt("no readable manifest at {$manifestPath}");
    }
    $problem = dispatch_cli_invalid($manifest) ?? pipeline_retired_mode((string) $manifest['mode']);
    if ($problem !== null) {
        return pipeline_halt($problem);
    }
    $pr = $manifest['artifacts']['pr'] ?? null;
    if ($pr === null) {
        return pipeline_halt('the CI gate needs a PR: artifacts.pr is not set');
    }
    $worktree = rtrim($manifest['worktree'], '/');
    [$code, $head, $error] = pipeline_git_run($worktree, ['rev-parse', 'HEAD']);
    if ($code !== 0) {
        return pipeline_halt("the CI gate cannot read the worktree's HEAD at {$worktree}: {$error}");
    }

    return pipeline_ci_answer(
        $manifest,
        dispatch_cli_pr_view($worktree, $pr, 'headRefOid,statusCheckRollup'),
        $head,
        glob("{$worktree}/.github/workflows/*.y*ml") !== [],
        $poll,
        dispatch_cli_unreviewed($manifest),
    );
}

/**
 * The files where a merge since the last completed review met the branch's changes (`../references/engine.md`
 * §The CI gate). `autoflow` only: there `finish` closed that review before the gate runs; an `interactive`
 * finish step runs the gate while its own review entry is still open.
 */
function dispatch_cli_unreviewed(array $manifest): array
{
    return $manifest['mode'] === 'autoflow' ? pipeline_review_scope($manifest, dispatch_cli_git($manifest))['files'] ?? [] : [];
}

/** `ci <manifest> [--poll <n>]`, n counted from 1; null is a usage error. */
function dispatch_cli_ci_command(array $arguments): ?array
{
    $poll = match (count($arguments)) {
        1 => '1',
        3 => $arguments[1] === '--poll' ? (string) $arguments[2] : '',
        default => '',
    };

    return ctype_digit($poll) && (int) $poll > 0 ? dispatch_cli_ci((string) $arguments[0], (int) $poll) : null;
}

/**
 * `kickoff <repo-root> <number|idea> [--medium|--light] [--base <branch>] [--decision <text>]...`; null
 * is a usage error, and so is a second tier flag. `--mode autoflow` is accepted and changes nothing;
 * `--mode auto` parses, so that `dispatch_cli_kickoff()` can halt it by name. `interactive` keeps its
 * session-driven kickoff.
 *
 * @return array{repoRoot: string, item: string, mode: string, tier: AgentTier, base: ?string, decisions: list<string>}|null
 */
function dispatch_cli_kickoff_args(array $arguments): ?array
{
    $options = ['mode' => 'autoflow', 'tier' => AgentTier::Full, 'base' => null, 'decisions' => []];
    $positional = [];
    while ($arguments !== []) {
        $argument = (string) array_shift($arguments);
        if (in_array($argument, ['--medium', '--light'], true)) {
            if ($options['tier'] !== AgentTier::Full) {
                return null;
            }
            $options['tier'] = AgentTier::from(substr($argument, 2));

            continue;
        }
        if (in_array($argument, ['--mode', '--base', '--decision'], true)) {
            $value = array_shift($arguments);
            if ($value === null) {
                return null;
            }
            if ($argument === '--decision') {
                $options['decisions'][] = (string) $value;
            } else {
                $options[substr($argument, 2)] = (string) $value;
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
    if ($parsed === null) {
        return null;
    }
    $retired = pipeline_retired_mode($parsed['mode']);

    return $retired === null ? pipeline_kickoff($parsed['repoRoot'], $parsed['item'], $parsed) : pipeline_halt($retired);
}

/**
 * `brief <manifest> <leg> <step> [--after <leg>:<step> --status <status> [--ui true|false] [--size <size>]]`;
 * null is a usage error. The values are the script's copy of the previous step's return, compared as given.
 *
 * @return array{0: string, 1: string, 2: string, 3: array<string, string>}|null
 */
function dispatch_cli_brief_args(array $arguments): ?array
{
    $positional = [];
    $reported = [];
    while ($arguments !== []) {
        $argument = (string) array_shift($arguments);
        if (! str_starts_with($argument, '--')) {
            $positional[] = $argument;

            continue;
        }
        $name = substr($argument, 2);
        $value = array_shift($arguments);
        if (! in_array($name, ['after', 'status', 'ui', 'size'], true) || $value === null || isset($reported[$name])) {
            return null;
        }
        $reported[$name] = (string) $value;
    }
    [$leg, $step] = explode(':', $reported['after'] ?? '', 2) + [1 => ''];
    $after = isset($reported['after']) ? in_array($leg, pipeline_legs(), true) && in_array($step, pipeline_steps($leg, 'autoflow'), true) : $reported === [];

    return count($positional) === 3 && $after ? [...$positional, $reported] : null;
}

function dispatch_cli_brief_command(array $arguments): array|string|null
{
    $parsed = dispatch_cli_brief_args($arguments);

    return $parsed === null ? null : dispatch_cli_brief(...$parsed);
}

/**
 * `launch <manifest> <diff-file> [--from <leg>] [--decision <text>]...`; null is a usage error. `--from`
 * is passed as given (an empty or unknown leg is `launch`'s halt, not a usage error).
 *
 * @return array{0: string, 1: string, 2: ?string, 3: list<string>}|null
 */
function dispatch_cli_launch_args(array $arguments): ?array
{
    $positional = [];
    $from = null;
    $decisions = [];
    while ($arguments !== []) {
        $argument = (string) array_shift($arguments);
        if (! in_array($argument, ['--from', '--decision'], true)) {
            $positional[] = $argument;

            continue;
        }
        $value = array_shift($arguments);
        if ($value === null) {
            return null;
        }
        if ($argument === '--from') {
            $from = (string) $value;
        } else {
            $decisions[] = (string) $value;
        }
    }

    return count($positional) === 2 ? [...$positional, $from, $decisions] : null;
}

function dispatch_cli_launch_command(array $arguments): ?array
{
    $parsed = dispatch_cli_launch_args($arguments);

    return $parsed === null ? null : dispatch_cli_launch(...$parsed);
}

/** `record`'s and `suite`'s no: exit 1, the manifest untouched. */
function dispatch_cli_refuse(string $reason): array
{
    return ['action' => 'refused', 'reason' => $reason];
}

/** Why a step may not write at `$manifestPath`, or null: the snapshot's own path (#122), no manifest, an invalid one. */
function dispatch_cli_write_problem(string $manifestPath): ?string
{
    if (str_ends_with($manifestPath, '.before.json')) {
        return "{$manifestPath} is the dispatcher's snapshot; the manifest is " . substr($manifestPath, 0, -strlen('.before.json')) . '.json';
    }
    $manifest = manifest_read($manifestPath);

    return $manifest === null ? "no readable manifest at {$manifestPath}" : dispatch_cli_invalid($manifest);
}

/** Writes the manifest and reads it back: null when it landed, else the refusal. A result that is no JSON is never written. */
function dispatch_cli_write(string $manifestPath, array $manifest): ?array
{
    if (json_encode($manifest) === false) {
        return dispatch_cli_refuse('the result cannot be written as JSON: ' . json_last_error_msg());
    }
    @manifest_write($manifestPath, $manifest); // the read-back below is the check; a warning would break the one JSON line

    return pipeline_normalized(manifest_read($manifestPath) ?? []) === pipeline_normalized($manifest)
        ? null
        : dispatch_cli_refuse("the write did not land at {$manifestPath}");
}

/**
 * `$given` with `--review-file` and `--actions-file` replaced by what they hold, or why not: each is a file
 * no older than the step's snapshot, so a step cannot hand in the file of an earlier one; `--proof` is a file.
 */
function dispatch_cli_record_files(string $snapshotPath, array $given): array|string
{
    foreach (['review-file' => 'review', 'actions-file' => 'actions'] as $flag => $what) {
        if (! isset($given[$flag])) {
            continue;
        }
        $path = $given[$flag];
        if (! is_file($path)) {
            return "--{$flag} {$path} is not a file";
        }
        if (filemtime($path) < filemtime($snapshotPath)) {
            return "--{$flag} {$path} is older than this step's snapshot: write this step's {$what} to it first";
        }
        $given[$flag] = (string) file_get_contents($path);
    }

    return isset($given['proof']) && ! is_file($given['proof']) ? "--proof {$given['proof']} is not a file" : $given;
}

/**
 * The base to diff against: `pipeline_base_ref()`, else origin's default branch as origin itself names
 * it, fetched, as kickoff resolves it; null when neither gives one. A checkout without `origin/HEAD`
 * must not cost a review step its review.
 */
function dispatch_cli_base_ref(array $manifest, callable $git): ?string
{
    $base = pipeline_base_ref($manifest, $git);
    if ($base !== null) {
        return $base;
    }
    try {
        $branch = pipeline_kickoff_default_branch(rtrim((string) $manifest['worktree'], '/'));
    } catch (PipelineKickoffHalt) {
        return null;
    }
    [$code] = $git(['fetch', '-q', 'origin', "+refs/heads/{$branch}:refs/remotes/origin/{$branch}"]);

    return $code === 0 ? "origin/{$branch}" : null;
}

/** The content triggers that fired over the branch's diff against its base, by name; null when git cannot give the diff. */
function dispatch_cli_annotations(array $manifest, callable $git): ?array
{
    $base = dispatch_cli_base_ref($manifest, $git);
    [$code, $diff] = $base === null ? [1, ''] : $git(['diff', "{$base}...HEAD"]);
    if ($code !== 0) {
        return null;
    }
    $composer = rtrim((string) $manifest['worktree'], '/') . '/composer.json';
    $package = is_file($composer) ? json_decode((string) file_get_contents($composer), true)['name'] ?? null : null;

    return pipeline_annotations(pipeline_triggers($diff, $package));
}

/**
 * What only the machine knows, for `pipeline_record()`: `now`, the design size of the spec the step
 * names (else the snapshot's), and from git `head`, `annotations` and which of the given spec and plan
 * paths exist at `HEAD`. A halt asks git nothing, so a step can always record its halt.
 *
 * @return array{head: ?string, now: string, annotations: ?list<string>, size: DesignSize, committed: list<string>}
 */
function dispatch_cli_record_facts(array $before, array $given): array
{
    $sized = [...$before, 'artifacts' => [...($before['artifacts'] ?? []), ...array_intersect_key($given, ['spec' => true])]];
    $facts = ['head' => null, 'now' => gmdate('Y-m-d\TH:i:s\Z'), 'annotations' => null, 'size' => dispatch_cli_design_size($sized), 'committed' => []];
    if ($given['status'] === LegStatus::Halted->value) {
        return $facts;
    }
    $worktree = rtrim((string) $before['worktree'], '/');
    $git = dispatch_cli_git($before);
    [$code, $head] = $git(['rev-parse', 'HEAD']);
    $paths = array_map(
        fn (string $path) => pipeline_relative_path($worktree, $path),
        array_values(array_intersect_key($given, ['spec' => true, 'plan' => true])),
    );

    return [
        ...$facts,
        'head' => $code === 0 ? $head : null,
        'annotations' => dispatch_cli_annotations($before, $git),
        'committed' => array_values(array_filter($paths, fn (string $path) => dispatch_cli_exists_at($worktree, 'HEAD', $path))),
    ];
}

/** Why `$leg` `$step` may not write at `$manifestPath`, or null: `dispatch_cli_write_problem()`, and the snapshot beside it is this step's. */
function dispatch_cli_step_problem(string $manifestPath, string $leg, string $step): ?string
{
    $problem = dispatch_cli_write_problem($manifestPath);
    if ($problem !== null) {
        return $problem;
    }
    $snapshotPath = manifest_files($manifestPath)['before'];
    $snapshot = dispatch_cli_snapshot_step(manifest_read($snapshotPath));

    return match (true) {
        $snapshot === "{$leg}:{$step}" => null,
        $snapshot === null => "no snapshot at {$snapshotPath}: record follows this step's brief (next in interactive)",
        default => 'the snapshot is of the ' . str_replace(':', ' ', $snapshot) . " step, not {$leg} {$step}",
    };
}

/**
 * A step's one manifest write (`../references/manifest.md` §What a leg writes): the snapshot of the step
 * `brief` or `next` ran for, plus what the flags add, checked by the next boundary's own
 * `pipeline_return_problem()` before it is written, and read back after.
 */
function dispatch_cli_record(string $manifestPath, string $leg, string $step, array $given): array
{
    $problem = dispatch_cli_step_problem($manifestPath, $leg, $step);
    if ($problem !== null) {
        return dispatch_cli_refuse($problem);
    }
    $snapshotPath = manifest_files($manifestPath)['before'];
    $before = (array) manifest_read($snapshotPath);
    $given = dispatch_cli_record_files($snapshotPath, $given);
    if (is_string($given)) {
        return dispatch_cli_refuse($given);
    }
    $facts = dispatch_cli_record_facts($before, $given);
    $current = (array) manifest_read($manifestPath);
    $candidate = pipeline_record($before, $current, $leg, $step, $given, $facts);
    if (is_string($candidate)) {
        return dispatch_cli_refuse($candidate);
    }
    $problem = pipeline_return_problem(pipeline_normalized($before), pipeline_normalized($candidate), $leg, $step, $facts['size']);
    if ($problem !== null) {
        return dispatch_cli_refuse("the next check would halt on this: {$problem}");
    }

    return dispatch_cli_write($manifestPath, $candidate) ?? [
        'action' => 'recorded',
        'leg' => $leg,
        'step' => $step,
        'status' => $given['status'],
        'last_sha' => $candidate['last_sha'] ?? null,
        'entry' => pipeline_record_entry($before, $candidate, $leg, $step),
        'replaced' => pipeline_record_replaced($before, $current),
    ];
}

/**
 * `record <manifest> <leg> <step> --status <status> [flags]`; null is a usage error: a flag `record` does
 * not know, one without a value, one given twice (`--issue-link` repeats), or no `--status`. A flag the
 * step's row does not list is a refusal, not a usage error (`pipeline_record()`).
 *
 * @return array{0: string, 1: string, 2: string, 3: array<string, string|list<string>>}|null
 */
function dispatch_cli_record_args(array $arguments): ?array
{
    $positional = [];
    $given = [];
    while ($arguments !== []) {
        $argument = (string) array_shift($arguments);
        if (! str_starts_with($argument, '--')) {
            $positional[] = $argument;

            continue;
        }
        $name = substr($argument, 2);
        $value = array_shift($arguments);
        if (! in_array($name, ['status', ...PIPELINE_RECORD_FLAGS], true) || $value === null) {
            return null;
        }
        if ($name === 'issue-link') {
            $given[$name][] = (string) $value;

            continue;
        }
        if (isset($given[$name])) {
            return null;
        }
        $given[$name] = (string) $value;
    }

    return count($positional) === 3 && isset($given['status']) ? [...$positional, $given] : null;
}

function dispatch_cli_record_command(array $arguments): ?array
{
    $parsed = dispatch_cli_record_args($arguments);

    return $parsed === null ? null : dispatch_cli_record(...$parsed);
}

/**
 * The write a step makes before it returns (`../references/engine.md` §Suite reuse): only the `suite` key,
 * into the manifest as it is, with the tree key computed here. A key that cannot be computed is a machinery
 * failure: nothing is written, so that run is never reused.
 */
function dispatch_cli_suite(string $manifestPath, string $outcome, int $passed, int $failed): array
{
    $problem = dispatch_cli_write_problem($manifestPath)
        ?? ($outcome === 'green' && $failed > 0 ? "a green suite has no failures: --failed is {$failed}" : null);
    if ($problem !== null) {
        return dispatch_cli_refuse($problem);
    }
    $manifest = (array) manifest_read($manifestPath);
    try {
        $tree = pipeline_tree_key(rtrim((string) $manifest['worktree'], '/'));
    } catch (RuntimeException $exception) {
        return dispatch_cli_refuse("cannot compute the tree key, so this run is not reusable: run the suite again next time ({$exception->getMessage()})");
    }
    $suite = ['tree' => $tree, 'outcome' => $outcome, 'passed' => $passed, 'failed' => $failed, 'at' => gmdate('Y-m-d\TH:i:s\Z')];

    return dispatch_cli_write($manifestPath, [...$manifest, 'suite' => $suite]) ?? ['action' => 'recorded', 'suite' => $suite];
}

/** `suite <manifest> --outcome green|red --passed <n> --failed <n>`, each flag once; null is a usage error. */
function dispatch_cli_suite_command(array $arguments): ?array
{
    $manifestPath = array_shift($arguments);
    $options = [];
    while ($arguments !== []) {
        $name = (string) array_shift($arguments);
        $value = array_shift($arguments);
        if (! in_array($name, ['--outcome', '--passed', '--failed'], true) || $value === null || isset($options[$name])) {
            return null;
        }
        $options[$name] = (string) $value;
    }
    $valid = $manifestPath !== null
        && count($options) === 3
        && in_array($options['--outcome'], ['green', 'red'], true)
        && ctype_digit($options['--passed'])
        && ctype_digit($options['--failed']);

    return $valid ? dispatch_cli_suite((string) $manifestPath, $options['--outcome'], (int) $options['--passed'], (int) $options['--failed']) : null;
}

/**
 * The whole `handoff` step (`../references/engine.md` §Stations): `record`'s checks before anything
 * leaves the machine, then `pipeline_handoff()` over the snapshot `record` builds on, then the proof page
 * (`dispatch_cli_handoff_page()`), then the step's one write through `dispatch_cli_record()`, for a halt as
 * for the PR. A record refused once the PR is open names the PR: the next run adopts it (#118).
 */
function dispatch_cli_handoff(string $manifestPath): array
{
    $problem = dispatch_cli_step_problem($manifestPath, 'handoff', 'run');
    if ($problem !== null) {
        return dispatch_cli_refuse($problem);
    }
    $manifest = (array) manifest_read(manifest_files($manifestPath)['before']);
    $retired = pipeline_retired_mode((string) $manifest['mode']);
    if ($retired !== null) {
        return dispatch_cli_refuse($retired);
    }
    $worktree = rtrim((string) $manifest['worktree'], '/');
    $record = fn (array $given): array => dispatch_cli_record($manifestPath, 'handoff', 'run', $given);

    try {
        $done = pipeline_handoff($manifest, dispatch_cli_git($manifest), fn (array $args): array => pipeline_gh_run($worktree, $args));
    } catch (PipelineHandoffHalt $halt) {
        $reason = $halt->getMessage();
        $recorded = $record(['status' => LegStatus::Halted->value, 'reason' => $reason]);

        return $recorded['action'] === 'recorded'
            ? [...$recorded, 'reason' => $reason]
            : dispatch_cli_refuse("{$recorded['reason']}; the step halted on: {$reason}");
    }
    $page = dispatch_cli_handoff_page($done['page']);
    $recorded = $record([
        'status' => LegStatus::Continued->value,
        'pr' => (string) $done['pr'],
        ...($page['proof'] === null ? [] : ['proof' => $page['proof']]),
    ]);

    return $recorded['action'] === 'recorded'
        ? [...$recorded, ...array_diff_key($done, ['page' => true]), 'notes' => [...$done['notes'], ...$page['notes']], 'proof' => $page['proof']]
        : dispatch_cli_refuse("{$recorded['reason']}; PR #{$done['pr']} is open and the next run of this step adopts it");
}

/**
 * Files the run's proof page under `proof_validate_run()` alone: the prose is the finish step's. Never a halt: a
 * page that cannot be filed is a note, and the step records without one.
 *
 * @param array{payload: array, defaults: array} $page
 * @return array{proof: ?string, notes: list<string>}
 */
function dispatch_cli_handoff_page(array $page): array
{
    $filed = proof_store_file($page['payload'], date('c'), proof_validate_run(...), $page['defaults']);

    return $filed['page'] === null
        ? ['proof' => null, 'notes' => ['the proof page was not filed: ' . implode('; ', $filed['problems'])]]
        : ['proof' => $filed['page'], 'notes' => []];
}

/** `handoff <manifest>`; null is a usage error. */
function dispatch_cli_handoff_command(array $arguments): ?array
{
    return count($arguments) === 1 ? dispatch_cli_handoff((string) $arguments[0]) : null;
}

$result = match ($argv[1] ?? '') {
    'kickoff' => dispatch_cli_kickoff(array_slice($argv, 2)),
    'next' => dispatch_cli_next((string) ($argv[2] ?? '')),
    'returned' => dispatch_cli_returned((string) ($argv[2] ?? ''), (string) ($argv[3] ?? '')),
    'launch' => dispatch_cli_launch_command(array_slice($argv, 2)),
    'brief' => dispatch_cli_brief_command(array_slice($argv, 2)),
    'finish' => dispatch_cli_finish((string) ($argv[2] ?? ''), (string) ($argv[3] ?? '')),
    'size' => dispatch_cli_size((string) ($argv[2] ?? '')),
    'ui' => dispatch_cli_ui((string) ($argv[2] ?? '')),
    'ci' => dispatch_cli_ci_command(array_slice($argv, 2)),
    'record' => dispatch_cli_record_command(array_slice($argv, 2)),
    'suite' => dispatch_cli_suite_command(array_slice($argv, 2)),
    'handoff' => dispatch_cli_handoff_command(array_slice($argv, 2)),
    default => null,
};

if ($result === null) {
    fwrite(STDERR, "usage: dispatch_cli.php kickoff <repo-root> <number|idea> [--medium|--light] [--base <branch>] [--decision <text>]... | next <manifest> | returned <manifest> <diff-file> | launch <manifest> <diff-file> [--from <leg>] [--decision <text>]... | brief <manifest> <leg> <step> [--after <leg>:<step> --status <status> [--ui true|false] [--size <size>]] | finish <manifest> <decision-json> | size <manifest> | ui <diff-file> | ci <manifest> [--poll <n>] | record <manifest> <leg> <step> --status <status> [--spec <path>] [--plan <path>] [--pr <number>] [--proof <path>] [--review-file <path>] [--actions-file <path>] [--issue-link <n>=<outcome>]... [--reason <text>] | suite <manifest> --outcome green|red --passed <n> --failed <n> | handoff <manifest> (size needs a readable manifest, ui an existing diff file)\n");
    exit(1);
}

echo is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
exit(is_array($result) && ($result['action'] ?? null) === 'refused' ? 1 : 0);
