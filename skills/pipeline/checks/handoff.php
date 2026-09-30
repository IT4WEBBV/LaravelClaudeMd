<?php

/**
 * The `handoff` step (`../references/engine.md` §Stations): push the branch, open the draft PR or adopt
 * the one the branch has, set the board Component. The decisions are pure; `pipeline_handoff()` runs them
 * over two runners, and `dispatch_cli.php handoff` records what it returns or the halt it throws.
 */

require_once __DIR__ . '/board.php';
require_once __DIR__ . '/dispatch.php';
require_once __DIR__ . '/manifest.php';

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

/** `Implement: <the spec's first H1, less its design suffix> (issue: #<n>)`; a spec without an H1 takes the branch. */
function pipeline_handoff_title(string $spec, string $branch, ?int $issue): string
{
    $heading = preg_match('/^#[ \t]+(.+?)\s*$/m', $spec, $match) === 1
        ? preg_replace('/\s+[—-]\s+design$/u', '', $match[1])
        : $branch;

    return "Implement: {$heading}" . ($issue === null ? '' : " (issue: #{$issue})");
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
