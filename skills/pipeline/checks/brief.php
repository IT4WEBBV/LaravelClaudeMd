<?php

/**
 * Every step's brief (`../references/engine.md` §What a leg brief consists of). A brief points at the
 * rules; it never restates them, and it holds nothing a station does not ask for.
 */

/**
 * @return array<string, list<string>> keyed `<leg>:<step>`. An `autoflow` step is a workflow agent: it
 * cannot start agents, so where a station would dispatch one it does that work itself.
 */
function pipeline_leg_overrides(string $mode): array
{
    $autoflow = $mode === 'autoflow';
    $actOnReview = [
        'Act on the open review with the edit/rework boundary (engine.md §Resolving a review): integrate and commit edits and small fixes; where the review says the work is fundamentally wrong, loop back.',
        'Change nothing the review did not name.',
        'Carry anything unresolved verbatim as an open question.',
    ];
    $completeEntry = 'Complete the open entry: `actions`, then `outcome`, equal to the status you return.';
    $yourself = fn (string $procedure, string $subject) => "Apply `/critique`'s `{$procedure}` procedure to {$subject} yourself: Stage 0, Stage 1 and the rubric in `~/.claude/skills/critique/references/rubrics.md`. You are the reviewer; do not dispatch one, so `--verify` and `alternatives` are not available.";
    $checks = 'When the repo declares a `## Checks` block, run its checks first and state their result qualified by its scope (engine.md §Mechanical checks); a repo that declares none says nothing about checks.';

    return [
        'design:run' => [
            'Invoke `superpowers:brainstorming`; on the Architectural path it hands over to `superpowers:writing-plans` (engine.md §Design size).',
            'Where brainstorming would ask the human, write each question and the answer you assumed into the spec\'s `## Assumptions` section, so `/critique plan` audits exactly those.',
            'Do not build or run the plan\'s code, in a scratch copy or anywhere else: confirm the signatures and APIs it relies on by reading, `php -l` or grep; `implement` proves the plan\'s Expected lines (engine.md §What design proves).',
            'The one exception: when the choice between approaches hinges on whether one of them works at all, answer that question with a throwaway probe (a few lines run on their own, never the plan\'s code, never the suite) and write the question and what the probe showed into the spec.',
            'Plans and specs committed before 2026-09-14 are not exemplars for test or proof policy, and no plan\'s `Verified before writing` header is part of the format.',
            'Commit the spec, then the plan: two commits. Set `artifacts.spec` and `artifacts.plan`.',
        ],
        'review-plan:review' => [
            $autoflow ? $yourself('plan', 'the spec and the plan') : 'Invoke `/critique plan` on the spec and the plan.',
            'Append its review verbatim as a new `plan-approval` ledger entry with `gate`, `leg`, `cycle`, `at`, `review` and `annotations`, and no `outcome`.',
            'Act on nothing. Read-only on the checkout; the manifest is the only file you write.',
        ],
        'review-plan:resolve' => [
            ...$actOnReview,
            ...($autoflow ? [] : ['The independent read (engine.md §Resolving a review) is available.']),
            $completeEntry,
        ],
        'handoff:run' => [
            'Invoke `handoff pr`. The PR opens draft and references the issue without a closing keyword (engine.md §Closing links). Set `artifacts.pr`.',
        ],
        'implement:run' => [
            'Bring the dev stack up first, without asking (engine.md §Dev-stack readiness).',
            'Follow `work-on`\'s logic in this worktree; claim no second slot.',
            'Test-first; after each plan step the suite and `static-analysis`; `format` once, over the whole tree, when the code is complete: before the last suite run and the push, its changes committed, and again only after a later change (engine.md §Mechanical checks, §Suite reuse). Record `suite` after every full run.',
            'Leave the PR draft; this overrides any mark-ready instruction in the plan, the PR comment, or `work-on`\'s own logic.',
            $autoflow
                ? 'Add the `ci` label (`gh pr edit <pr> --add-label ci`) before your first push, in a repo that has one, and do not wait on CI after it: this overrides `work-on`\'s CI watch; the CI gate reads the PR\'s head commit before the PR goes ready (engine.md §The CI gate).'
                : 'Add the `ci` label (`gh pr edit <pr> --add-label ci`) before the push whose CI you watch.',
            'Files or behaviour the plan does not name: return `plan-insufficient` with the reason instead of improvising.',
            ...($autoflow ? ['Execute the plan inline, task by task; no subagents.'] : []),
        ],
        'verify-ui:run' => [
            'Bring the dev stack up if it is down. Invoke `browser-verification`.',
            'Write the proof page (engine.md §The proof store), set `artifacts.proof` to the path `write` printed, and post the text-only record comment.',
            'Append the thin `verify-ui` entry with outcome `continued`, or `looped-back` when the check fails.',
        ],
        'review-pr:review' => [
            ($autoflow ? $yourself('pr', 'the PR') . ' State the suite line above.' : 'Invoke `/critique pr`, stating the suite line above.') . ' ' . $checks,
            'Append its review verbatim as a new `pr-review` ledger entry with `gate`, `leg`, `cycle`, `at`, `review`, `annotations` and `reviewed_sha` (the output of `git rev-parse HEAD` in the worktree: the commit you reviewed), and no `outcome`.',
            'Act on nothing. Read-only on the checkout; the manifest is the only file you write.',
        ],
        'review-pr:resolve' => [
            'You are the finish step.',
            ...$actOnReview,
            $autoflow ? 'On a loop-back, stop there: no suite.' : 'On a loop-back, stop there: no suite, no `gh pr ready`.',
            'Run the suite unless engine.md §Suite reuse finds this tree green; record `suite`.',
            'Reconcile the closing links (engine.md §Closing links) and write `issue_links` on the entry.',
            'When `artifacts.proof` is set, rewrite the proof page with the final open questions and ledger.',
            $completeEntry,
            ($autoflow
                ? 'Push your commits and leave the PR draft; the session that launched the run marks it ready after the CI gate (engine.md §The CI gate).'
                : 'Run the CI gate (engine.md §The CI gate) and `gh pr ready` when it answers `ready`; show any other answer to the human.')
            . ' The last action is `proof_cli.php open` on `artifacts.proof` (engine.md §The proof store).',
        ],
    ];
}

/**
 * `$step` is given in `autoflow` (the workflow script names it) and derived from the ledger in `interactive`.
 * `$git` runs git in the worktree; only `review-pr`'s review step asks it, for its scope (engine.md §Scoped re-review).
 */
function pipeline_brief(array $manifest, string $leg, string $manifestPath, ?string $step = null, ?callable $git = null): string
{
    $step ??= pipeline_step($manifest, $leg);
    $scope = $git !== null && "{$leg}:{$step}" === 'review-pr:review' ? pipeline_review_scope($manifest, $git) : null;

    return implode("\n\n", [
        pipeline_brief_role($manifest, $leg, $step),
        pipeline_brief_pointers($manifest, $manifestPath, $leg, $step),
        pipeline_brief_state($manifest, $leg),
        pipeline_brief_overrides($manifest, $leg, $step, $scope),
        pipeline_brief_return($leg, $step, (string) $manifest['mode']),
    ]) . "\n";
}

function pipeline_brief_role(array $manifest, string $leg, string $step): string
{
    return "# Brief: `{$leg}` leg, `{$step}` step\n\n"
        . "You are the `{$leg}` leg, `{$step}` step, of a `/pipeline {$manifest['mode']}` run. "
        . "Work only in `{$manifest['worktree']}` on `{$manifest['branch']}`. "
        . 'The engine.md sections this brief names are in `~/.claude/skills/pipeline/references/engine.md`.';
}

function pipeline_brief_pointers(array $manifest, string $manifestPath, string $leg, string $step): string
{
    $ledger = pipeline_ledger($manifest);
    $lines = ["- manifest: `{$manifestPath}`"];

    foreach ($manifest['artifacts'] ?? [] as $name => $value) {
        if ($value !== null && $value !== '') {
            $lines[] = "- {$name}: `{$value}`";
        }
    }
    if ($step === 'review') {
        $lines[] = '- this review\'s `cycle`: `' . pipeline_next_cycle($ledger, pipeline_gate_of($leg)) . '`';
    }
    if ($step === 'resolve') {
        $lines[] = '- the open review: `gate_ledger[' . pipeline_open_entry($ledger, pipeline_gate_of($leg)) . ']`';
    }
    $loopBack = pipeline_loop_back_entry($ledger, $leg);
    if ($loopBack !== null) {
        $lines[] = "- redo what `gate_ledger[{$loopBack}]` looped back for";
    }

    return "## Pointers\n\n" . implode("\n", $lines);
}

/** 1-based pass number for the next entry of this gate; `unknown` stays unknown (`../references/manifest.md` §reconstruction). */
function pipeline_next_cycle(array $ledger, string $gate): int|string
{
    $cycles = array_column(array_filter($ledger, fn ($entry) => ($entry['gate'] ?? null) === $gate), 'cycle');

    return in_array('unknown', $cycles, true) ? 'unknown' : count($cycles) + 1;
}

/** The newest ledger entry, when it looped back to this leg. */
function pipeline_loop_back_entry(array $ledger, string $leg): ?int
{
    $index = array_key_last($ledger);
    if ($index === null) {
        return null;
    }
    $entry = $ledger[$index];
    $from = pipeline_leg_of_gate((string) ($entry['gate'] ?? ''));

    return ($entry['outcome'] ?? null) === 'looped-back' && $from !== null && pipeline_loop_target($from) === $leg ? $index : null;
}

function pipeline_brief_state(array $manifest, string $leg): string
{
    $decisions = $manifest['decisions'] ?? [];
    $lines = $decisions === [] ? ['- settled decisions: none'] : array_map(fn (string $decision) => "- settled: {$decision}", $decisions);
    $base = $manifest['base'] ?? null;
    if ($base !== null) {
        $lines[] = "- base: `{$base}`: this branch was cut from `origin/{$base}` and its PR goes into it, not into the default branch; diff with `git diff origin/{$base}...HEAD`, and a merge into it closes no issue (engine.md §Kickoff)";
    }
    $sha = $manifest['last_sha'] ?? 'unknown';
    $lines[] = "- last_sha: `{$sha}`";

    $suite = $manifest['suite'] ?? null;
    if ($suite !== null) {
        $lines[] = "- full suite {$suite['outcome']} over tree `{$suite['tree']}` at `{$sha}`: {$suite['passed']} passed, {$suite['failed']} failed";
    }
    if ($leg === 'design') {
        $lines[] = empty($manifest['light'])
            ? '- design size: the Architectural path is required (no `light`)'
            : '- design size: the Bounded path is permitted (`light`)';
    }

    return "## Settled decisions and state\n\n" . implode("\n", $lines);
}

function pipeline_brief_overrides(array $manifest, string $leg, string $step, ?array $scope = null): string
{
    $lines = pipeline_leg_overrides((string) $manifest['mode'])["{$leg}:{$step}"];
    $ledger = pipeline_ledger($manifest);

    if ($leg === 'design' && pipeline_design_grows($ledger)) {
        $lines[] = 'Grow form: the design escalated from Bounded (engine.md §Design size). Grow the spec and the plan; do not re-design them.';
    }
    if ($leg === 'design' && pipeline_is_plan_gap(end($ledger) ?: [])) {
        $lines[] = 'Plan gap: extend the plan (and the spec where it must say more) to cover the entry\'s `reason`; describe what is already built as state, do not re-design it (engine.md §Design size). Leave that entry as it is, with no `actions`: what you did goes in the spec, the plan and the reason you return.';
    }
    if ($leg === 'review-pr' && pipeline_ci_rounds($manifest) > 0) {
        $lines[] = pipeline_ci_round_line($step);
    }
    if ($scope !== null) {
        $lines[] = pipeline_review_scope_line($scope);
    }
    $base = $manifest['base'] ?? null;
    if ($leg === 'handoff' && $base !== null) {
        $lines[] = "The PR must open into `{$base}`: after `handoff pr`, `gh pr view <pr> --json baseRefName --jq .baseRefName` prints `{$base}`; otherwise `gh pr edit <pr> --base {$base}` before setting `artifacts.pr` (engine.md §Kickoff).";
    }
    if ($leg !== 'design') {
        $lines = [...$lines, ...pipeline_plan_gap_lines($step)];
    }
    $issue = $manifest['artifacts']['issue'] ?? null;
    if (is_numeric($issue) && pipeline_writes_pr_text($leg, $step)) {
        $lines[] = "PR text — the title, the body and PR comments — is in the language of issue #{$issue}; the spec, the plan, code and commit messages keep the repo's language (engine.md §PR language).";
    }
    if ($manifest['mode'] === 'autoflow') {
        $lines[] = "Run every command from `cd {$manifest['worktree']}` or with `git -C {$manifest['worktree']}`: the session that started this run may sit in another checkout.";
        $lines[] = 'The owner authorised this run, including pushing the branch and opening the draft PR; the pipeline never merges.';
    }

    return "## Overrides\n\n" . implode("\n", array_map(fn (string $line) => "- {$line}", $lines));
}

/** The steps that write onto the PR: its title and body, closing links, the record comment (engine.md §PR language). */
function pipeline_writes_pr_text(string $leg, string $step): bool
{
    return in_array("{$leg}:{$step}", ['handoff:run', 'implement:run', 'verify-ui:run', 'review-pr:resolve'], true);
}

/** How a step after `design` reports a plan that falls short (engine.md §Design size). A resolve step completes its open entry, so it loops back instead. */
function pipeline_plan_gap_lines(string $step): array
{
    if ($step === 'resolve') {
        return ['A plan gap or a Bounded escalation found while resolving is a loop-back: return `looped-back` and name it in the entry\'s `actions`; the leg the run goes back to handles it.'];
    }

    return [
        'On a Bounded spec (its header says `**Design size:** Bounded`): run the escalation check first (engine.md §Design size), and only on escalation append the `design-size` entry and return `plan-insufficient`.',
        'On an Architectural spec: only when the plan falls short of what this step needs (files or behaviour it does not name), append a `plan-approval` entry with `leg`, `cycle`, `at`, a `reason` naming what the plan lacks, and outcome `looped-back`, then return `plan-insufficient`. The size alone is no gap: an Architectural plan needs no approval beyond `review-plan`\'s.',
        ...($step === 'review' ? ['When you return `plan-insufficient`, append no review entry.'] : []),
    ];
}

/** The CI gate's fix round (engine.md §The CI gate): the recorded failure is a finding of `review-pr`. */
function pipeline_ci_round_line(string $step): string
{
    return $step === 'review'
        ? 'The settled `CI red on the PR\'s head commit` decision is a finding of this review: read the failing job\'s log (`gh run view <run> --log-failed`, the run id from its link) and state the failure and its cause (engine.md §The CI gate).'
        : 'Fix the `CI red` finding, or show it is unrelated to this change (the same failure on the base branch, or a flake: start `gh run rerun <run> --failed` and do not wait on it), and say which in `actions`; the CI gate reads the head commit again (engine.md §The CI gate).';
}

/** A design-size escalation that no plan approval has answered yet. */
function pipeline_design_grows(array $ledger): bool
{
    $grows = false;
    foreach ($ledger as $entry) {
        $outcome = $entry['outcome'] ?? null;
        $grows = match ($entry['gate'] ?? null) {
            'design-size' => $outcome === 'escalated' ? true : $grows,
            'plan-approval' => $outcome === 'continued' ? false : $grows,
            default => $grows,
        };
    }

    return $grows;
}

/**
 * The commit the newest completed review of the PR saw (`../references/engine.md` §Scoped re-review): the
 * `reviewed_sha` of the newest `continued` `pr-review` entry that records one, newer than the latest
 * escalation or plan gap. A halted, looped-back or open review is never a base.
 */
function pipeline_review_base(array $ledger): ?string
{
    $since = pipeline_reset_at($ledger);
    $bases = array_filter($ledger, fn (array $entry) => ($entry['gate'] ?? null) === 'pr-review'
        && ($entry['outcome'] ?? null) === 'continued'
        && ($entry['at'] ?? '') > $since
        && is_string($entry['reviewed_sha'] ?? null));

    return $bases === [] ? null : end($bases)['reviewed_sha'];
}

/**
 * What a review of the PR after a completed one reads (`../references/engine.md` §Scoped re-review), or null
 * for the whole PR: no base, a base HEAD does not contain, a base ref that does not resolve, or any git call
 * that fails. `$git` runs git in the worktree, as `pipeline_git_run()` does.
 *
 * @return array{since: string, base: string, commits: int, files: list<string>}|null
 */
function pipeline_review_scope(array $manifest, callable $git): ?array
{
    $since = pipeline_review_base(pipeline_ledger($manifest));
    if ($since === null || $git(['merge-base', '--is-ancestor', $since, 'HEAD'])[0] !== 0) {
        return null;
    }
    $base = isset($manifest['base'])
        ? "origin/{$manifest['base']}"
        : (pipeline_git_lines($git, ['symbolic-ref', '-q', '--short', 'refs/remotes/origin/HEAD'])[0] ?? null);
    if ($base === null) {
        return null;
    }
    $range = ["{$since}..HEAD", "^{$base}"];
    $commits = pipeline_git_lines($git, ['rev-list', '--no-merges', ...$range]);
    $merges = pipeline_git_lines($git, ['rev-list', '--merges', ...$range]);
    $files = $merges === null ? null : pipeline_meeting_files($merges, $git);

    return $commits === null || $files === null ? null : ['since' => $since, 'base' => $base, 'commits' => count($commits), 'files' => $files];
}

/** The lines git printed, or null when it failed. */
function pipeline_git_lines(callable $git, array $args): ?array
{
    [$code, $out] = $git($args);

    return $code === 0 ? array_values(array_filter(explode("\n", $out), fn (string $line) => $line !== '')) : null;
}

/** The files where these merges met the branch's changes, sorted and unique, or null when git fails. */
function pipeline_meeting_files(array $merges, callable $git): ?array
{
    $files = [];
    foreach ($merges as $merge) {
        $merged = pipeline_merge_files($merge, $git);
        if ($merged === null) {
            return null;
        }
        $files = [...$files, ...$merged];
    }
    $files = array_values(array_unique($files));
    sort($files);

    return $files;
}

/**
 * One merge's meeting files: per parent after the first, what both sides changed since they last met
 * (a conflict, a clean merge of a shared file, a resolution that took one side), and what the merge
 * commit changed against every parent (an edit made in the merge itself).
 */
function pipeline_merge_files(string $merge, callable $git): ?array
{
    $parents = pipeline_git_lines($git, ['rev-parse', "{$merge}^@"]);
    $files = pipeline_git_lines($git, ['diff-tree', '-c', '--no-commit-id', '--name-only', $merge]);
    if ($parents === null || $files === null) {
        return null;
    }
    $first = array_shift($parents);
    foreach ($parents as $parent) {
        $theirs = pipeline_git_lines($git, ['diff', '--name-only', '--no-renames', "{$first}...{$parent}"]);
        $ours = pipeline_git_lines($git, ['diff', '--name-only', '--no-renames', "{$parent}...{$first}"]);
        if ($theirs === null || $ours === null) {
            return null;
        }
        $files = [...$files, ...array_intersect($theirs, $ours)];
    }

    return $files;
}

/** The review-pr review step's target once a review of the PR has completed (`../references/engine.md` §Scoped re-review). */
function pipeline_review_scope_line(array $scope): string
{
    ['since' => $since, 'base' => $base, 'commits' => $commits, 'files' => $files] = $scope;
    $whole = $files === []
        ? "no file more: no merge since met this branch's changes"
        : "read whole at HEAD, the files where a merge since met this branch's changes: " . implode(', ', array_map(fn (string $file) => "`{$file}`", $files));
    $target = $commits === 0 && $files === []
        ? "nothing was committed on this branch since `{$since}`: review only what the settled decisions above ask of the PR, and say so"
        : "the branch's own commits since ({$commits}), as patches, `git log -p --no-merges {$since}..HEAD ^{$base}`, plus `git diff HEAD` (Stage 0 runs over both); and {$whole}; what the settled decisions above ask of the PR stays in your target wherever it lies";

    return "Scoped re-review (engine.md §Scoped re-review): a review of this PR completed at `{$since}`, which HEAD contains, so your target is what changed since, not the whole PR: {$target}. Read beyond the target only where a finding needs it.";
}

function pipeline_brief_return(string $leg, string $step, string $mode): string
{
    $keys = implode(', ', array_map(fn (string $key) => "`{$key}`", pipeline_leg_writable_keys()));
    $statuses = implode(', ', array_map(fn (LegStatus $status) => "`{$status->value}`", LegStatus::allowedFor($leg, $step)));
    $reply = $mode === 'autoflow'
        ? 'then return `{status, reason}` as your structured result (with `reason` whenever you have one) instead of replying with a line'
        : 'and reply with one line naming it';

    return "## Return\n\n"
        . "Write your results into the manifest ({$keys}; `cursor.reason` only when you halt) and nothing else. Never move `cursor.leg`.\n"
        . "Set `cursor.status` to one of {$statuses}, {$reply}.";
}
