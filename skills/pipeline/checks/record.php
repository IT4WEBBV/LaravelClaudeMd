<?php

/**
 * A step's one manifest write (`../references/manifest.md` §What a leg writes). Pure: `dispatch_cli.php
 * record` reads the files and asks git; these functions build the manifest the step leaves from the
 * dispatch snapshot, or say why they will not. The table below is the contract the brief prints
 * (`pipeline_brief_return()`), so a brief cannot name a flag `record` refuses.
 */

require_once __DIR__ . '/questions.php';

enum ActionDisposition: string
{
    case Integrated = 'integrated';
    case Recorded = 'recorded';
    case OpenQuestion = 'open-question';
}

enum IssueLinkOutcome: string
{
    case Closes = 'closes';
    case StaysOpen = 'stays-open';
    case DroppedButCloses = 'dropped-but-closes';
}

/** Every flag `record` takes beside `--status`, without the dashes. */
const PIPELINE_RECORD_FLAGS = ['spec', 'plan', 'pr', 'proof', 'review-file', 'actions-file', 'issue-link', 'reason'];

/**
 * Per `<leg>:<step>` and status, the flags that step must pass and the ones it may. A status without a
 * row is one the step may not return (`LegStatus::allowedFor()`; `RecordTest` holds the two together).
 *
 * @return array<string, array<string, array{required: list<string>, optional: list<string>}>>
 */
function pipeline_record_table(): array
{
    $row = fn (array $required = [], array $optional = []) => ['required' => $required, 'optional' => $optional];
    $halted = ['halted' => $row(['reason'])];
    $gap = ['plan-insufficient' => $row(['reason'])];
    $review = ['continued' => $row(['review-file']), ...$gap, ...$halted];
    $finish = $row(['actions-file'], ['issue-link']);

    return [
        'design:run' => ['continued' => $row(['spec', 'plan']), ...$halted],
        'design:spec' => ['continued' => $row(['spec'], ['plan']), ...$halted],
        'design:plan' => ['continued' => $row(['plan']), ...$halted],
        'review-plan:review' => $review,
        'review-plan:resolve' => ['continued' => $row(['actions-file']), 'looped-back' => $row(['actions-file']), ...$halted],
        'handoff:run' => ['continued' => $row(['pr'], ['proof']), ...$gap, ...$halted],
        'implement:run' => ['continued' => $row(), ...$gap, ...$halted],
        'verify-ui:run' => ['continued' => $row(['proof']), 'looped-back' => $row([], ['proof']), ...$gap, ...$halted],
        'review-pr:review' => $review,
        'review-pr:resolve' => ['continued' => $finish, 'looped-back' => $finish, ...$halted],
    ];
}

/**
 * The manifest the step leaves, or why not (a string). It starts from the snapshot `$before`, keeps the
 * `suite` the file holds now (`$current`), and adds what `$given` and `$facts` say. `$given` is the
 * parsed flags with the review and actions files already read; `$facts` is what only the machine knows
 * (`dispatch_cli_record_facts()`), a fact git could not give being null.
 */
function pipeline_record(array $before, array $current, string $leg, string $step, array $given, array $facts): array|string
{
    $rows = pipeline_record_table()["{$leg}:{$step}"] ?? null;
    if ($rows === null) {
        return "{$leg} has no {$step} step";
    }
    $status = (string) ($given['status'] ?? '');
    $flags = array_diff_key($given, ['status' => true]);
    if (! isset($rows[$status])) {
        return "the {$leg} {$step} step cannot return {$status}; it may return " . implode(', ', array_keys($rows));
    }
    $problem = pipeline_record_flag_problem($rows[$status], array_keys($flags), "{$leg} {$step} with --status {$status}")
        ?? (trim((string) ($flags['reason'] ?? 'given')) === '' ? '--reason is empty: say why' : null);
    if ($problem !== null) {
        return $problem;
    }

    $manifest = pipeline_record_base($before, $current, $status);
    if ($status === LegStatus::Halted->value) {
        return [...$manifest, 'cursor' => [...$manifest['cursor'], 'reason' => trim($flags['reason'])]];
    }
    if ($facts['head'] === null) {
        return 'record needs the worktree\'s HEAD for last_sha, and `git rev-parse HEAD` failed';
    }
    $manifest['last_sha'] = $facts['head'];

    return match (true) {
        $status === LegStatus::PlanInsufficient->value => pipeline_record_gap($manifest, $leg, trim($flags['reason']), $facts),
        $leg === 'design' => pipeline_record_design($manifest, $step, $flags, $facts),
        $step === 'review' => pipeline_record_review($manifest, $leg, $flags['review-file'], $facts),
        $step === 'resolve' => pipeline_record_resolve($manifest, $leg, $status, $flags),
        $leg === 'handoff' => pipeline_record_pr($manifest, $flags),
        $leg === 'verify-ui' => pipeline_record_verified($manifest, $status, $flags, $facts),
        default => $manifest,
    };
}

/** A flag the row does not list, or a required one that is missing, in words that name the row's flags. */
function pipeline_record_flag_problem(array $row, array $given, string $label): ?string
{
    $listed = [...$row['required'], ...$row['optional']];
    $dashed = fn (array $flags) => implode(', ', array_map(fn (string $flag) => "--{$flag}", $flags));
    $unlisted = array_values(array_diff($given, $listed));
    $missing = array_values(array_diff($row['required'], $given));

    return match (true) {
        $unlisted !== [] => "{$dashed($unlisted)} is not a flag of {$label}, which takes " . ($listed === [] ? 'no flag' : $dashed($listed)),
        $missing !== [] => "{$label} needs {$dashed($missing)}",
        default => null,
    };
}

/** The snapshot with the file's current `suite` and the step's status: what every record starts from. */
function pipeline_record_base(array $before, array $current, string $status): array
{
    return [
        ...$before,
        ...(array_key_exists('suite', $current) ? ['suite' => $current['suite']] : []),
        'cursor' => [...array_diff_key($before['cursor'], ['reason' => true]), 'status' => $status],
    ];
}

function pipeline_record_append(array $manifest, array $entry): array
{
    return [...$manifest, 'gate_ledger' => [...pipeline_ledger($manifest), $entry]];
}

/** `plan-insufficient`: an escalation on a Bounded design, a plan gap on an Architectural one (`../references/engine.md` §Design size). */
function pipeline_record_gap(array $manifest, string $leg, string $reason, array $facts): array
{
    $entry = $facts['size'] === DesignSize::Bounded
        ? ['gate' => 'design-size', 'leg' => $leg, 'at' => $facts['now'], 'reason' => $reason, 'outcome' => 'escalated']
        : ['gate' => 'plan-approval', 'leg' => $leg, 'cycle' => pipeline_next_cycle(pipeline_ledger($manifest), 'plan-approval'), 'at' => $facts['now'], 'reason' => $reason, 'outcome' => 'looped-back'];

    return pipeline_record_append($manifest, $entry);
}

/** A design step's artifacts: committed paths, relative to the worktree; the spec step sets or removes the plan as the spec's size calls for. */
function pipeline_record_design(array $manifest, string $step, array $flags, array $facts): array|string
{
    $paths = array_map(
        fn (string $path) => pipeline_relative_path((string) $manifest['worktree'], $path),
        array_intersect_key($flags, ['spec' => true, 'plan' => true]),
    );
    foreach ($paths as $name => $path) {
        if (! in_array($path, $facts['committed'], true)) {
            return "the {$name} {$path} does not exist at HEAD (`git cat-file -e HEAD:{$path}` failed): commit it first";
        }
    }
    $artifacts = $manifest['artifacts'] ?? [];
    if ($step === 'spec') {
        $bounded = $facts['size'] === DesignSize::Bounded;
        if ($bounded !== isset($paths['plan'])) {
            return $bounded
                ? 'a Bounded spec needs --plan: its spec step commits the plan too'
                : 'an Architectural spec takes no --plan: the plan step writes the plan';
        }
        $artifacts = array_diff_key($artifacts, ['plan' => true]);
    }

    return [...$manifest, 'artifacts' => [...$artifacts, ...$paths]];
}

/** A review step's open entry: the review verbatim, less trailing newlines, and what the machine stamps. */
function pipeline_record_review(array $manifest, string $leg, string $review, array $facts): array|string
{
    if (trim($review) === '') {
        return 'the review file is empty';
    }
    if ($facts['annotations'] === null) {
        return 'record needs the branch\'s diff for the entry\'s annotations, and `git diff <base>...HEAD` failed';
    }
    $gate = pipeline_gate_of($leg);

    return pipeline_record_append($manifest, [
        'gate' => $gate,
        'leg' => $leg,
        'cycle' => pipeline_next_cycle(pipeline_ledger($manifest), $gate),
        'at' => $facts['now'],
        'review' => rtrim($review, "\r\n"),
        'annotations' => $facts['annotations'],
        ...($gate === 'pr-review' ? ['reviewed_sha' => $facts['head']] : []),
    ]);
}

/** A resolve step completes the open entry: `actions`, `issue_links` when given, and the status as `outcome`. */
function pipeline_record_resolve(array $manifest, string $leg, string $status, array $flags): array|string
{
    $actions = pipeline_record_actions($flags['actions-file']);
    $links = is_string($actions) ? $actions : pipeline_record_issue_links($flags['issue-link'] ?? []);
    if (is_string($links)) {
        return $links;
    }
    $gate = pipeline_gate_of($leg);
    $ledger = pipeline_ledger($manifest);
    $open = pipeline_open_entry($ledger, $gate);
    if ($open === null) {
        return "no open {$gate} review to complete";
    }
    $ledger[$open] = [...$ledger[$open], 'actions' => $actions, ...($links === [] ? [] : ['issue_links' => $links]), 'outcome' => $status];

    return [...$manifest, 'gate_ledger' => $ledger];
}

/** @return list<array{claim: string, disposition: string, note: string, kind?: string}>|string the actions, or why the file does not hold them */
function pipeline_record_actions(string $json): array|string
{
    $actions = json_decode($json, true);
    if (! is_array($actions) || ! array_is_list($actions)) {
        return 'the actions file is not a JSON list of {claim, disposition, note}';
    }
    foreach ($actions as $index => $action) {
        $problem = pipeline_record_action_problem($action);
        if ($problem !== null) {
            return "actions[{$index}]{$problem}";
        }
    }

    return $actions;
}

/** What is wrong with one action, as the words after `actions[n]`, or null. An `open-question` carries a `kind` (`../references/engine.md` §Open questions); no other action does. */
function pipeline_record_action_problem(mixed $action): ?string
{
    $ticked = fn (array $names) => implode(', ', array_map(fn (int|string $name) => "`{$name}`", $names));
    if (! is_array($action)) {
        return ' is not an object';
    }
    $open = ($action['disposition'] ?? null) === ActionDisposition::OpenQuestion->value;
    $keys = ['claim', 'disposition', 'note', ...($open ? ['kind'] : [])];
    $unknown = array_values(array_diff(array_keys($action), $keys));
    $missing = array_values(array_diff($keys, array_keys($action)));

    return match (true) {
        $unknown !== [] => " has an unknown key {$ticked($unknown)}",
        $missing !== [] => " has no {$ticked($missing)}",
        ! is_string($action['claim']) || trim($action['claim']) === '' => ': `claim` is not a non-empty string',
        ! is_string($action['disposition']) || ActionDisposition::tryFrom($action['disposition']) === null
            => ': `disposition` is not one of ' . implode(', ', array_column(ActionDisposition::cases(), 'value')),
        ! is_string($action['note']) => ': `note` is not a string',
        $open && (! is_string($action['kind']) || QuestionKind::tryFrom($action['kind']) === null) => ': `kind` is not one of ' . QuestionKind::listed(),
        default => null,
    };
}

/** @return list<array{issue: int, outcome: string}>|string `issue_links` from `<n>=<outcome>` flags, or the flag that is not one */
function pipeline_record_issue_links(array $given): array|string
{
    $links = [];
    foreach ($given as $link) {
        if (preg_match('/^(\d+)=(.+)$/', $link, $match) !== 1 || IssueLinkOutcome::tryFrom($match[2]) === null) {
            return "--issue-link {$link} is not <issue number>=<outcome>, the outcome one of " . implode(', ', array_column(IssueLinkOutcome::cases(), 'value'));
        }
        $links[] = ['issue' => (int) $match[1], 'outcome' => $match[2]];
    }

    return $links;
}

/** `handoff`: the PR, and the proof page it filed when it filed one. */
function pipeline_record_pr(array $manifest, array $flags): array|string
{
    if (! ctype_digit($flags['pr'])) {
        return "--pr {$flags['pr']} is not a PR number";
    }

    return [...$manifest, 'artifacts' => [
        ...($manifest['artifacts'] ?? []),
        'pr' => (int) $flags['pr'],
        ...array_intersect_key($flags, ['proof' => true]),
    ]];
}

/** `verify-ui`: the proof page when given, and the thin entry that carries the loop bound (`../references/manifest.md` §gate_ledger). */
function pipeline_record_verified(array $manifest, string $status, array $flags, array $facts): array
{
    $artifacts = [...($manifest['artifacts'] ?? []), ...array_intersect_key($flags, ['proof' => true])];

    return pipeline_record_append([...$manifest, 'artifacts' => $artifacts], [
        'gate' => 'verify-ui',
        'cycle' => pipeline_next_cycle(pipeline_ledger($manifest), 'verify-ui'),
        'at' => $facts['now'],
        'outcome' => $status,
    ]);
}

/** The index of the ledger entry this record appended or completed, or null when it touched none. */
function pipeline_record_entry(array $before, array $candidate, string $leg, string $step): ?int
{
    [$old, $new] = [pipeline_ledger($before), pipeline_ledger($candidate)];

    return match (true) {
        count($new) > count($old) => array_key_last($new),
        $step === 'resolve' && $new !== $old => pipeline_open_entry($old, pipeline_gate_of($leg)),
        default => null,
    };
}

/** @return list<string> the keys the file held changed against the snapshot and `record` did not keep: all but `suite` */
function pipeline_record_replaced(array $before, array $current): array
{
    return array_values(array_diff(pipeline_changed_keys(pipeline_normalized($before), pipeline_normalized($current)), ['suite']));
}

/** @return list<string> the content triggers that fired, by name (`../references/gates.md` §content triggers); `ui` is a leg, not an annotation */
function pipeline_annotations(array $triggers): array
{
    return array_keys(array_filter(array_intersect_key($triggers, ['package' => true, 'migration' => true, 'auth' => true])));
}
