<?php

/**
 * The `handoff` step (`../references/engine.md` §Stations): push the branch, open the draft PR or adopt
 * the one the branch has, set the board Component, file the run's proof page. The decisions are pure; `pipeline_handoff()` runs them
 * over two runners, and `dispatch_cli.php handoff` records what it returns or the halt it throws.
 */

require_once __DIR__ . '/board.php';
require_once __DIR__ . '/dispatch.php';
require_once __DIR__ . '/manifest.php';
require_once __DIR__ . '/proof.php';

/** What every read of the run's PR asks gh for. */
const PIPELINE_HANDOFF_PR_FIELDS = 'number,url,state,isDraft,baseRefName,headRefName,body';

/** Why the step stops; `dispatch_cli_handoff()` records it as the halt's reason. */
final class PipelineHandoffHalt extends RuntimeException
{
}

/** git's or gh's words for a reason or a note: trimmed and valid UTF-8, so the manifest and the answer stay JSON. */
function pipeline_handoff_words(string $words, int $code): string
{
    $words = mb_scrub(trim($words));

    return $words === '' ? "exit {$code}" : $words;
}

/** The spec's first H1, less its design suffix; a spec without an H1 takes the branch. */
function pipeline_handoff_heading(string $spec, string $branch): string
{
    return preg_match('/^#[ \t]+(.+?)\s*$/m', $spec, $match) === 1
        ? preg_replace('/\s+[—-]\s+design$/u', '', $match[1])
        : $branch;
}

/** `Implement: <the spec's heading> (issue: #<n>)`. */
function pipeline_handoff_title(string $spec, string $branch, ?int $issue): string
{
    return 'Implement: ' . pipeline_handoff_heading($spec, $branch) . ($issue === null ? '' : " (issue: #{$issue})");
}

/**
 * The proof page `handoff` files (`../references/engine.md` §The proof store): where the run belongs, from the
 * manifest and the PR as gh lists it, `base` being the branch the PR now goes into. The title is a default, so a
 * title a step wrote stays when the step runs again.
 *
 * @return array{payload: array, defaults: array{title: string}}
 */
function pipeline_handoff_proof(array $manifest, array $pr, string $heading): array
{
    [$owner, $repo] = array_slice(explode('/', trim((string) parse_url((string) $pr['url'], PHP_URL_PATH), '/')), 0, 2);
    $issue = $manifest['artifacts']['issue'] ?? null;

    return [
        'payload' => [
            'nameWithOwner' => "{$owner}/{$repo}",
            'repo' => $repo,
            'branch' => (string) $manifest['branch'],
            'mode' => (string) $manifest['mode'],
            'worktree' => rtrim((string) $manifest['worktree'], '/'),
            'pr' => (int) $pr['number'],
            'prState' => 'OPEN',
            ...($issue === null ? [] : ['issue' => (int) $issue]),
            'base' => (string) ($manifest['base'] ?? $pr['baseRefName']),
        ],
        'defaults' => ['title' => proof_short_title("PR #{$pr['number']}: {$heading}")],
    ];
}

/** A new PR's body: what an empty one gains. */
function pipeline_handoff_body(string $spec, string $plan, ?int $issue): string
{
    return (string) pipeline_handoff_body_update('', $spec, $plan, $issue);
}

/**
 * An existing body with the lines it lacks put in front, or null when it names the spec, the plan and the
 * issue: a body is only ever added to, so what `implement` or the finish step wrote stays. `Part of` is
 * the non-closing form (`../references/engine.md` §Closing links).
 */
function pipeline_handoff_body_update(string $body, string $spec, string $plan, ?int $issue): ?string
{
    $design = str_contains($body, $spec) && str_contains($body, $plan)
        ? null
        : "Implements the design in `{$spec}`.\nPlan: `{$plan}`.";
    $link = $issue === null || preg_match("/#{$issue}(?!\d)/", $body) === 1 ? null : "Part of #{$issue}.";
    $added = array_filter([$design, $link]);

    return $added === [] ? null : trim(implode("\n\n", [...$added, trim($body)]));
}

/**
 * Where the PR's Component goes, from `pipeline_repo_board()`'s answer: the field and the option, a note
 * (a string) when the section names a default it cannot use, or null when the repo sets none.
 *
 * @return array{name: string, field: string, option: string}|string|null
 */
function pipeline_handoff_component(array $board): array|string|null
{
    $default = $board['board']['component-default'] ?? null;
    if ($board['state'] !== 'valid' || $default === null) {
        return null;
    }
    [$name, $option] = array_map(trim(...), explode('=', $default, 2) + [1 => '']);
    if ($name === '' || $option === '') {
        return 'the Component was not set: `component-default` is not `<Name>=<option id>`';
    }
    $field = $board['board']['component-field-id'] ?? null;

    return $field === null
        ? 'the Component was not set: `component-default` needs a `component-field-id`'
        : ['name' => $name, 'field' => $field, 'option' => $option];
}

/**
 * What to do with the open PRs gh lists for the branch: null to create one, the PR to adopt, or the
 * reason to halt (a string): one that is not a draft, in the invariant check's words, or more than one.
 */
function pipeline_handoff_choice(array $prs): array|string|null
{
    if ($prs === []) {
        return null;
    }
    if (count($prs) > 1) {
        $named = array_map(fn (array $pr) => "#{$pr['number']} into {$pr['baseRefName']}", $prs);

        return 'the branch has more than one open PR (' . implode(', ', $named) . '): close all but one';
    }

    return pipeline_pr_problem($prs[0]['number'], $prs[0]) ?? $prs[0];
}

/**
 * The step, in the order that pushes nothing a halt would leave behind: the preflight and the PR lookup
 * read only, then the push, then the PR, then the Component. `$git` and `$gh` run git in the worktree and
 * gh from it, each `array $args → [code, out, err]`. Every outward act is idempotent, so the step can run
 * again after any failure.
 *
 * @return array{pr: int, url: string, created: bool, notes: list<string>, page: array{payload: array, defaults: array{title: string}}}
 *
 * @throws PipelineHandoffHalt
 */
function pipeline_handoff(array $manifest, callable $git, callable $gh): array
{
    $branch = (string) $manifest['branch'];
    [$spec, $plan] = pipeline_handoff_design($manifest, $git);
    pipeline_handoff_on_branch($branch, $git);
    $board = pipeline_handoff_board($manifest);
    $existing = pipeline_handoff_find($manifest, $gh);
    pipeline_handoff_push($branch, $git);

    $issue = isset($manifest['artifacts']['issue']) ? (int) $manifest['artifacts']['issue'] : null;
    $base = $manifest['base'] ?? null;
    $specText = $git(['show', "HEAD:{$spec}"])[1];
    $pr = $existing ?? pipeline_handoff_create($branch, $base, pipeline_handoff_title($specText, $branch, $issue), pipeline_handoff_body($spec, $plan, $issue), $gh);
    $aligned = $existing === null ? [] : pipeline_handoff_align($existing, $base, $spec, $plan, $issue, $gh);

    return [
        'pr' => (int) $pr['number'],
        'url' => (string) $pr['url'],
        'created' => $existing === null,
        'notes' => [...$aligned, ...pipeline_handoff_component_notes($board, $pr, $gh)],
        'page' => pipeline_handoff_proof($manifest, $pr, pipeline_handoff_heading($specText, $branch)),
    ];
}

/** @return array{0: string, 1: string} the spec and the plan as git names them: both set, both at `HEAD` */
function pipeline_handoff_design(array $manifest, callable $git): array
{
    $paths = [];
    foreach (['spec', 'plan'] as $name) {
        $path = $manifest['artifacts'][$name] ?? null;
        if (! is_string($path) || $path === '') {
            throw new PipelineHandoffHalt("artifacts.{$name} is not set: handoff names the spec and the plan in the PR");
        }
        $path = pipeline_relative_path((string) $manifest['worktree'], $path);
        if ($git(['cat-file', '-e', "HEAD:{$path}"])[0] !== 0) {
            throw new PipelineHandoffHalt("the {$name} {$path} does not exist at HEAD: commit it first");
        }
        $paths[] = $path;
    }

    return $paths;
}

function pipeline_handoff_on_branch(string $branch, callable $git): void
{
    [$code, $head, $err] = $git(['rev-parse', '--abbrev-ref', 'HEAD']);
    if ($code !== 0) {
        throw new PipelineHandoffHalt("the worktree's branch cannot be read: " . pipeline_handoff_words($err, $code));
    }
    if ($head !== $branch) {
        throw new PipelineHandoffHalt("the worktree is on {$head}, not on the run's branch {$branch}");
    }
}

/** `pipeline_repo_board()` over the worktree's config; a repo without the file has no board, and an `invalid` section halts as it does at kickoff. */
function pipeline_handoff_board(array $manifest): array
{
    $config = rtrim((string) $manifest['worktree'], '/') . '/.claude/work-on.config.md';
    $board = pipeline_repo_board(is_file($config) ? (string) file_get_contents($config) : '');
    if ($board['state'] === 'invalid') {
        throw new PipelineHandoffHalt("the ## Board section is invalid: {$board['error']}");
    }

    return $board;
}

/**
 * The run's PR when it has one, or null when one is to be created: the recorded PR, which must be an open
 * draft on this branch, else the one open PR of the branch (#118), which must be a draft.
 */
function pipeline_handoff_find(array $manifest, callable $gh): ?array
{
    $branch = (string) $manifest['branch'];
    $known = $manifest['artifacts']['pr'] ?? null;
    if ($known === null) {
        $choice = pipeline_handoff_choice(pipeline_handoff_open_prs($branch, $gh));

        return is_string($choice) ? throw new PipelineHandoffHalt($choice) : $choice;
    }
    [$code, $out] = $gh(['pr', 'view', (string) $known, '--json', PIPELINE_HANDOFF_PR_FIELDS]);
    $view = $code === 0 ? json_decode($out, true) : null;
    $view = is_array($view) ? $view : null;
    $problem = pipeline_pr_problem($known, $view);
    if ($problem !== null) {
        throw new PipelineHandoffHalt($problem);
    }
    $head = (string) ($view['headRefName'] ?? '');
    if ($head !== $branch) {
        throw new PipelineHandoffHalt("PR #{$known} is for the branch {$head}, not the run's branch {$branch}");
    }

    return $view;
}

/** The open PRs whose head is the branch. A gh that cannot list halts: a run that cannot tell whether a PR exists never creates one. */
function pipeline_handoff_open_prs(string $branch, callable $gh): array
{
    [$code, $out, $err] = $gh(['pr', 'list', '--head', $branch, '--state', 'open', '--json', PIPELINE_HANDOFF_PR_FIELDS]);
    $prs = $code === 0 ? json_decode($out, true) : null;
    if (! is_array($prs) || ! array_is_list($prs)) {
        throw new PipelineHandoffHalt("gh could not list the open PRs of {$branch}: " . pipeline_handoff_words($err, $code));
    }

    return $prs;
}

/** The branch is named because kickoff leaves it without an upstream. Never forced. */
function pipeline_handoff_push(string $branch, callable $git): void
{
    [$code, , $err] = $git(['push', '-u', 'origin', $branch]);
    if ($code !== 0) {
        throw new PipelineHandoffHalt("git refused the push of {$branch}: " . pipeline_handoff_words($err, $code));
    }
}

/** Opens the draft PR and reads it back: its number, URL and base come from gh's listing, never from what `create` printed. */
function pipeline_handoff_create(string $branch, ?string $base, string $title, string $body, callable $gh): array
{
    [$code, , $err] = $gh(['pr', 'create', '--draft', '--head', $branch, ...($base === null ? [] : ['--base', $base]), '--title', $title, '--body', $body]);
    if ($code !== 0) {
        throw new PipelineHandoffHalt('gh could not open the PR: ' . pipeline_handoff_words($err, $code) . '; the branch is pushed, and the next run of this step opens or adopts it');
    }
    $pr = pipeline_handoff_choice(pipeline_handoff_open_prs($branch, $gh));

    return match (true) {
        is_array($pr) => $pr,
        $pr === null => throw new PipelineHandoffHalt("gh opened a PR for {$branch} but does not list it yet; the next run of this step adopts it"),
        default => throw new PipelineHandoffHalt($pr),
    };
}

/**
 * Brings an adopted or re-used PR in line: into the run's base, and naming the spec, the plan and the
 * issue. Its title stays as it is.
 *
 * @return list<string> notes
 */
function pipeline_handoff_align(array $pr, ?string $base, string $spec, string $plan, ?int $issue, callable $gh): array
{
    $number = (string) $pr['number'];
    $notes = [];
    if ($base !== null && ($pr['baseRefName'] ?? null) !== $base) {
        pipeline_handoff_edit($number, ['--base', $base], $gh);
        $notes[] = "PR #{$number} retargeted to {$base}";
    }
    $body = pipeline_handoff_body_update((string) ($pr['body'] ?? ''), $spec, $plan, $issue);
    if ($body !== null) {
        pipeline_handoff_edit($number, ['--body', $body], $gh);
    }

    return $notes;
}

function pipeline_handoff_edit(string $number, array $change, callable $gh): void
{
    [$code, , $err] = $gh(['pr', 'edit', $number, ...$change]);
    if ($code !== 0) {
        throw new PipelineHandoffHalt("gh could not edit PR #{$number} ({$change[0]}): " . pipeline_handoff_words($err, $code));
    }
}

/**
 * The Component on the PR's board item. Whatever goes wrong is a note, never a halt, as kickoff's claim
 * is: a PR without a Component is a finished handoff.
 *
 * @return list<string>
 */
function pipeline_handoff_component_notes(array $board, array $pr, callable $gh): array
{
    $target = pipeline_handoff_component($board);
    if ($target === null) {
        return [];
    }
    if (is_string($target)) {
        return [$target];
    }
    $error = pipeline_board_set($gh, $board['board'], (string) $pr['url'], $target['field'], $target['option']);

    return [$error === null
        ? "Component {$target['name']} set on board {$board['board']['number']}"
        : 'the Component was not set: ' . mb_scrub($error)];
}
