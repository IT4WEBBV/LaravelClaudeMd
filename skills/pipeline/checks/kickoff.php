<?php

/**
 * `dispatch_cli.php kickoff` (`../references/session.md` §The work item and `../references/session.md` §Kickoff): both in one
 * call for the unattended mode, `autoflow`. Every value comes from the repo's config, gh or git; a
 * value kickoff would have to compute is a halt.
 */

require_once __DIR__ . '/agents.php';
require_once __DIR__ . '/board.php';
require_once __DIR__ . '/gh.php';
require_once __DIR__ . '/dispatch.php';
require_once __DIR__ . '/manifest.php';
require_once __DIR__ . '/suite.php';

/** A `- key: value` line under `## <section>` of `.claude/work-on.config.md`, its trailing `# …` stripped; null when absent or empty. */
function pipeline_repo_config_value(string $configMarkdown, string $section, string $key): ?string
{
    $inSection = false;
    foreach (preg_split('/\R/', $configMarkdown) as $line) {
        if (preg_match('/^##\s+(.+?)\s*$/', $line, $heading)) {
            $inSection = $heading[1] === $section;

            continue;
        }
        if ($inSection && preg_match('/^\s*-\s*' . preg_quote($key, '/') . '\s*:\s*(.*)$/', $line, $match)) {
            $value = trim(preg_replace('/\s+#.*$/', '', $match[1]));

            return $value === '' ? null : $value;
        }
    }

    return null;
}

/** `work-on`'s slug rule: lowercase, runs outside `[a-z0-9]` become `-`, at most 50 characters, cut at a word. */
function pipeline_slug(string $text): string
{
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
    if (strlen($slug) <= 50) {
        return $slug;
    }
    $cut = strrpos(substr($slug, 0, 51), '-');

    return substr($slug, 0, $cut === false ? 50 : $cut);
}

/** The first `<name>` a caller would still have to fill in; shell redirections are not names. */
function pipeline_placeholder(string $text): ?string
{
    return preg_match('/<[A-Za-z][A-Za-z0-9_-]*>/', $text, $match) ? $match[0] : null;
}

/** `branch.issue` up to `<slug>`, the number filled in: every branch for the issue starts with it, whoever slugged the title. Null without a `<slug>`, or without a `<number>` before it. */
function pipeline_branch_prefix(string $pattern, int $number): ?string
{
    $head = strstr($pattern, '<slug>', true);

    return $head === false || ! str_contains($head, '<number>') ? null : str_replace('<number>', (string) $number, $head);
}

/** A refusal at kickoff; `pipeline_kickoff()` turns it into the halt it prints. */
final class PipelineKickoffHalt extends RuntimeException
{
}

/**
 * The spec's steps in order (`docs/superpowers/specs/2026-09-24-pipeline-kickoff-design.md`). Up to the
 * create nothing exists, so a halt there leaves nothing behind.
 *
 * @param  array{mode: string, tier: AgentTier, base: ?string, decisions: list<string>}  $options
 */
function pipeline_kickoff(string $repoRoot, string $item, array $options): array
{
    try {
        $config = pipeline_kickoff_config($repoRoot);
        $board = pipeline_kickoff_board($config);
        $issue = pipeline_kickoff_issue($repoRoot, $config, $item);
        $branch = pipeline_kickoff_branch($config, $item, $issue);
        $base = pipeline_kickoff_base($repoRoot, $options['base']);
        $command = pipeline_kickoff_create_command($config, $branch, $base);
        pipeline_kickoff_unclaimed($repoRoot, $config, $branch, $issue);
        $worktree = pipeline_kickoff_create($repoRoot, $command, $branch);
    } catch (RuntimeException $halt) {
        return pipeline_halt($halt->getMessage());
    }

    try {
        $manifest = pipeline_kickoff_prepare($worktree, $branch, $base, pipeline_kickoff_manifest($branch, $worktree, $item, $issue, $options));
    } catch (RuntimeException $failure) {
        return pipeline_halt("kickoff created {$worktree} but could not finish it, and wrote no manifest: {$failure->getMessage()}; remove the worktree and its branch before kicking off again");
    }

    $notes = $issue !== null && $board['state'] === 'valid' ? pipeline_kickoff_claim($repoRoot, $board['board'], $issue) : [];

    return ['action' => 'ready', 'manifest' => $manifest, 'worktree' => $worktree, 'branch' => $branch, 'notes' => $notes];
}

function pipeline_kickoff_config(string $repoRoot): string
{
    $path = rtrim($repoRoot, '/') . '/.claude/work-on.config.md';
    if (! is_file($path)) {
        throw new PipelineKickoffHalt("no {$path}: kickoff reads repo, worktree.create and branch.issue from it");
    }

    return (string) file_get_contents($path);
}

function pipeline_kickoff_required(string $config, string $section, string $key): string
{
    return pipeline_repo_config_value($config, $section, $key)
        ?? throw new PipelineKickoffHalt(".claude/work-on.config.md declares no `- {$key}:` under `## {$section}`");
}

/**
 * §The work item for a number: the issue itself, never a PR, and no open blocker. An idea has no work
 * item and asks gh nothing.
 *
 * @return array{number: int, title: string, url: string}|null
 */
function pipeline_kickoff_issue(string $repoRoot, string $config, string $item): ?array
{
    if (! preg_match('/^#?(\d+)$/', $item, $match)) {
        return null;
    }
    $number = (int) $match[1];
    $repo = pipeline_kickoff_required($config, 'Repo', 'repo');
    $issue = pipeline_kickoff_gh_json($repoRoot, ['api', "repos/{$repo}/issues/{$number}"], "#{$number} does not resolve in {$repo}");
    if (($issue['pull_request'] ?? null) !== null) {
        throw new PipelineKickoffHalt("#{$number} is a pull request: kickoff starts runs for issues and ideas; a PR's run resumes with launch --from");
    }
    $blockers = pipeline_kickoff_gh_json($repoRoot, ['api', "/repos/{$repo}/issues/{$number}/dependencies/blocked_by"], "the blockers of #{$number} could not be read");
    $open = array_filter($blockers, fn (mixed $blocker) => is_array($blocker) && ($blocker['state'] ?? null) === 'open');
    if ($open !== []) {
        throw new PipelineKickoffHalt("#{$number} is blocked by " . implode(', ', array_map(fn (array $blocker) => "#{$blocker['number']} {$blocker['title']}", $open)));
    }

    return ['number' => $number, 'title' => (string) ($issue['title'] ?? ''), 'url' => (string) ($issue['html_url'] ?? '')];
}

function pipeline_kickoff_gh_json(string $cwd, array $args, string $failure): array
{
    [$code, $out, $err] = pipeline_gh_run($cwd, $args);
    $decoded = json_decode($out, true);
    if ($code !== 0 || ! is_array($decoded)) {
        throw new PipelineKickoffHalt("{$failure}: " . ($err === '' ? "gh exited {$code}" : $err));
    }

    return $decoded;
}

/** `branch.issue` for an issue, `feature/<slug>` for an idea; anything left to compute, or unsafe for sh, halts. */
function pipeline_kickoff_branch(string $config, string $item, ?array $issue): string
{
    $branch = $issue === null
        ? 'feature/' . pipeline_kickoff_slug($item)
        : str_replace(['<number>', '<slug>'], [(string) $issue['number'], pipeline_kickoff_slug($issue['title'])], pipeline_kickoff_required($config, 'Branch convention', 'issue'));

    $placeholder = pipeline_placeholder($branch);
    if ($placeholder !== null) {
        throw new PipelineKickoffHalt("the declared branch.issue needs {$placeholder}, which kickoff does not compute (`- issue:` under `## Branch convention`)");
    }
    if (! preg_match('#^[A-Za-z0-9._/-]+$#', $branch)) {
        throw new PipelineKickoffHalt("the branch '{$branch}' holds characters kickoff will not pass to a shell");
    }

    return $branch;
}

function pipeline_kickoff_slug(string $text): string
{
    $slug = pipeline_slug($text);
    if ($slug === '') {
        throw new PipelineKickoffHalt("'{$text}' has nothing to make a branch name from");
    }

    return $slug;
}

/**
 * A per-run base (`../references/session.md` §Kickoff, *A run on a base*) is a branch on origin other than its default.
 * The fetch proves it is one and leaves `origin/<base>` current for the create and the check after it.
 */
function pipeline_kickoff_base(string $repoRoot, ?string $base): ?string
{
    if ($base === null) {
        return null;
    }
    if (! preg_match('#^[A-Za-z0-9._/-]+$#', $base)) {
        throw new PipelineKickoffHalt("the base '{$base}' holds characters kickoff will not pass to a shell");
    }
    [$code, , $err] = pipeline_git_run($repoRoot, ['fetch', '-q', 'origin', "+refs/heads/{$base}:refs/remotes/origin/{$base}"]);
    if ($code !== 0) {
        throw new PipelineKickoffHalt("the base {$base} is not a branch on origin: {$err}");
    }
    if ($base === pipeline_kickoff_default_branch($repoRoot)) {
        throw new PipelineKickoffHalt("the base {$base} is origin's default branch: leave --base out");
    }

    return $base;
}

/** origin's `HEAD` as origin itself names it; the local `origin/HEAD` is often stale. */
function pipeline_kickoff_default_branch(string $repoRoot): string
{
    [$code, $out, $err] = pipeline_git_run($repoRoot, ['ls-remote', '--symref', 'origin', 'HEAD']);
    if ($code !== 0 || ! preg_match('#^ref: refs/heads/(\S+)\tHEAD$#m', $out, $match)) {
        throw new PipelineKickoffHalt("origin's default branch could not be read: " . ($err === '' ? "git exited {$code}" : $err));
    }

    return $match[1];
}

/**
 * The declared `worktree.create` with `<branch>` filled in, its only substitution, and ` --base
 * origin/<base>` after it on a run with a base: the declared command is shared with `work-on` and
 * `orchestrate`, which substitute only `<branch>`, so a `<base>` placeholder would reach the script
 * literally from each of them.
 */
function pipeline_kickoff_create_command(string $config, string $branch, ?string $base): string
{
    $declared = pipeline_kickoff_required($config, 'Worktree', 'create');
    $command = str_replace('<branch>', $branch, $declared);
    $placeholder = pipeline_placeholder($command);
    if ($placeholder !== null) {
        throw new PipelineKickoffHalt("the declared worktree.create needs {$placeholder}, which kickoff does not compute: `- create: {$declared}`");
    }

    return $base === null ? $command : "{$command} --base origin/{$base}";
}

/**
 * An existing branch for the item belongs to a run or session kickoff cannot see: never reuse it. For an
 * issue that is any branch under `pipeline_branch_prefix()`, so a hand-made or work-on branch counts.
 */
function pipeline_kickoff_unclaimed(string $repoRoot, string $config, string $branch, ?array $issue): void
{
    $prefix = $issue === null ? null : pipeline_branch_prefix(pipeline_kickoff_required($config, 'Branch convention', 'issue'), $issue['number']);
    $refs = preg_split('/\R/', pipeline_git($repoRoot, ['for-each-ref', '--format=%(refname:lstrip=2)', 'refs/heads/']), -1, PREG_SPLIT_NO_EMPTY);
    foreach ($refs as $ref) {
        if ($ref === $branch || ($prefix !== null && str_starts_with($ref, $prefix))) {
            throw new PipelineKickoffHalt("branch {$ref} already exists: a run or session has it; resume it with launch");
        }
    }
}

/** Runs the declared create as declared: from the primary checkout, no stdin, one output stream. @return string the worktree path */
function pipeline_kickoff_create(string $repoRoot, string $command, string $branch): string
{
    $process = proc_open(['sh', '-c', $command], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $repoRoot);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $code = proc_close($process);
    $tail = implode("\n", array_slice(preg_split('/\R/', trim((string) $output)), -40));

    if ($code !== 0) {
        throw new PipelineKickoffHalt("the declared worktree.create failed (exit {$code}): `{$command}`\n{$tail}");
    }

    return pipeline_worktree_of($repoRoot, $branch)
        ?? throw new PipelineKickoffHalt("the declared worktree.create exited 0 but no worktree has {$branch}: `{$command}`\n{$tail}\ncheck git worktree list");
}

/** The worktree git lists for the branch, whichever command made it. */
function pipeline_worktree_of(string $repoRoot, string $branch): ?string
{
    foreach (preg_split('/\n\n+/', pipeline_git($repoRoot, ['worktree', 'list', '--porcelain'])) as $entry) {
        if (preg_match('/^worktree (.+)$/m', $entry, $path) && preg_match('/^branch ' . preg_quote("refs/heads/{$branch}", '/') . '$/m', $entry)) {
            return $path[1];
        }
    }

    return null;
}

/** On its base, no upstream on the new branch, the manifest out of git, then the first write. @return string the manifest path */
function pipeline_kickoff_prepare(string $worktree, string $branch, ?string $base, array $manifest): string
{
    if ($base !== null) {
        pipeline_kickoff_on_base($worktree, $base);
    }
    if (pipeline_git_run($worktree, ['rev-parse', '--abbrev-ref', "{$branch}@{upstream}"])[0] === 0) {
        pipeline_git($worktree, ['branch', '--unset-upstream', $branch]);
    }
    pipeline_exclude_manifest($worktree);
    $path = manifest_path($worktree, $branch);
    manifest_write($path, $manifest);

    return $path;
}

/** The create honoured the base: the worktree's `HEAD` is `origin/<base>`. `dispatch_cli.php handoff` opens the PR into it with `--base`. */
function pipeline_kickoff_on_base(string $worktree, string $base): void
{
    $head = pipeline_git($worktree, ['rev-parse', 'HEAD']);
    $tip = pipeline_git($worktree, ['rev-parse', "origin/{$base}"]);
    if ($head !== $tip) {
        throw new PipelineKickoffHalt("the worktree's HEAD ({$head}) is not origin/{$base} ({$tip}): the declared worktree.create did not honour --base");
    }
}

/** Everything no step will look up (`../references/session.md` §Kickoff); a key with nothing to say is absent. */
function pipeline_kickoff_manifest(string $branch, string $worktree, string $item, ?array $issue, array $options): array
{
    return [
        'branch' => $branch,
        'worktree' => $worktree,
        'mode' => $options['mode'],
        ...($options['base'] === null ? [] : ['base' => $options['base']]),
        'cursor' => ['leg' => 'design', 'status' => 'pending'],
        'artifacts' => $issue === null ? ['idea' => $item] : ['issue' => $issue['number']],
        ...($options['tier'] === AgentTier::Full ? [] : ['tier' => $options['tier']->value]),
        ...($options['decisions'] === [] ? [] : ['decisions' => $options['decisions']]),
    ];
}

/** `pipeline_repo_board()`, with `invalid` a machinery failure that halts before anything exists. */
function pipeline_kickoff_board(string $config): array
{
    $board = pipeline_repo_board($config);
    if ($board['state'] === 'invalid') {
        throw new PipelineKickoffHalt("the ## Board section is invalid: {$board['error']}");
    }

    return $board;
}

/**
 * `../references/session.md` §The work item's two calls. Failing to record the claim is a note, never a halt.
 *
 * @param  array{number: int, title: string, url: string}  $issue
 * @return list<string>
 */
function pipeline_kickoff_claim(string $repoRoot, array $board, array $issue): array
{
    $error = pipeline_board_set(
        fn (array $args): array => pipeline_gh_run($repoRoot, $args),
        $board,
        $issue['url'],
        $board['status-field-id'],
        $board['in-progress-option-id'],
    );

    return [$error === null ? "#{$issue['number']} is In Progress on board {$board['number']}" : "the board claim was not recorded: {$error}"];
}
