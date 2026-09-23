<?php

/**
 * Every step's brief (`../references/engine.md` §What a leg brief consists of). A brief points at the
 * rules; it never restates them, and it holds nothing a station does not ask for.
 */

/** @return array<string, list<string>> keyed `<leg>:<step>` */
function pipeline_leg_overrides(): array
{
    $actOnReview = [
        'Act on the open review with the edit/rework boundary (engine.md §`auto`): integrate and commit edits and small fixes; where the review says the work is fundamentally wrong, loop back.',
        'Change nothing the review did not name.',
        'Carry anything unresolved verbatim as an open question.',
    ];
    $completeEntry = 'Complete the open entry: `actions`, then `outcome`, equal to the status you return.';

    return [
        'design:run' => [
            'Invoke `superpowers:brainstorming`; on the Architectural path it hands over to `superpowers:writing-plans` (engine.md §Design size).',
            'Where brainstorming would ask the human, write each question and the answer you assumed into the spec\'s `## Assumptions` section, so `/critique plan` audits exactly those.',
            'Plans and specs committed before 2026-09-14 are not exemplars for test or proof policy.',
            'Commit the spec, then the plan: two commits. Set `artifacts.spec` and `artifacts.plan`.',
        ],
        'review-plan:review' => [
            'Invoke `/critique plan` on the spec and the plan.',
            'Append its review verbatim as a new `plan-approval` ledger entry with `gate`, `leg`, `cycle`, `at`, `review` and `annotations`, and no `outcome`.',
            'Act on nothing. Read-only on the checkout; the manifest is the only file you write.',
        ],
        'review-plan:resolve' => [
            ...$actOnReview,
            'The independent read (engine.md §`auto`) is available.',
            $completeEntry,
        ],
        'handoff:run' => [
            'Invoke `handoff pr`. The PR opens draft and references the issue without a closing keyword (engine.md §Closing links). Set `artifacts.pr`.',
        ],
        'implement:run' => [
            'Bring the dev stack up first, without asking (engine.md §Dev-stack readiness).',
            'Follow `work-on`\'s logic in this worktree; claim no second slot.',
            'Test-first; after each step the suite and the mechanical checks (engine.md §Mechanical checks, §Suite reuse). Record `suite` after every full run.',
            'Leave the PR draft; this overrides any mark-ready instruction in the plan, the PR comment, or `work-on`\'s own logic.',
            'Files or behaviour the plan does not name: return `plan-insufficient` with the reason instead of improvising.',
        ],
        'verify-ui:run' => [
            'Bring the dev stack up if it is down. Invoke `browser-verification`.',
            'Write the proof page (engine.md §The proof store), set `artifacts.proof` to the path `write` printed, and post the text-only record comment.',
            'Append the thin `verify-ui` entry with outcome `continued`, or `looped-back` when the check fails.',
        ],
        'review-pr:review' => [
            'Invoke `/critique pr`, stating the suite line above. When the repo declares a `## Checks` block, run its checks first and state their result qualified by its scope (engine.md §Mechanical checks); a repo that declares none says nothing about checks.',
            'Append its review verbatim as a new `pr-review` ledger entry with `gate`, `leg`, `cycle`, `at`, `review` and `annotations`, and no `outcome`.',
            'Act on nothing. Read-only on the checkout; the manifest is the only file you write.',
        ],
        'review-pr:resolve' => [
            'You are the finish step.',
            ...$actOnReview,
            'On a loop-back, stop there: no suite, no `gh pr ready`.',
            'Run the suite unless engine.md §Suite reuse finds this tree green; record `suite`.',
            'Reconcile the closing links (engine.md §Closing links) and write `issue_links` on the entry.',
            'When `artifacts.proof` is set, rewrite the proof page with the final open questions and ledger.',
            $completeEntry,
            'Run `gh pr ready`. The last action is `proof_cli.php open` on `artifacts.proof` (engine.md §The proof store).',
        ],
    ];
}

function pipeline_brief(array $manifest, string $leg, string $manifestPath): string
{
    $step = pipeline_step($manifest, $leg);

    return implode("\n\n", [
        pipeline_brief_role($manifest, $leg, $step),
        pipeline_brief_pointers($manifest, $manifestPath, $leg, $step),
        pipeline_brief_state($manifest, $leg),
        pipeline_brief_overrides($manifest, $leg, $step),
        pipeline_brief_return($leg, $step),
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
    $ledger = $manifest['gate_ledger'] ?? [];
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

function pipeline_brief_overrides(array $manifest, string $leg, string $step): string
{
    $lines = pipeline_leg_overrides()["{$leg}:{$step}"];
    $ledger = $manifest['gate_ledger'] ?? [];

    if ($leg === 'design' && pipeline_design_grows($ledger)) {
        $lines[] = 'Grow form: the design escalated from Bounded (engine.md §Design size). Grow the spec and the plan; do not re-design them.';
    }
    if ($leg === 'design' && pipeline_is_plan_gap(end($ledger) ?: [])) {
        $lines[] = 'Plan gap: extend the plan (and the spec where it must say more) to cover the entry\'s `reason`; describe what is already built as state, do not re-design it (engine.md §Design size).';
    }
    if ($leg !== 'design') {
        $lines[] = 'While the spec\'s header says `**Design size:** Bounded`, run the escalation check first (engine.md §Design size); on escalation append the `design-size` entry and return `plan-insufficient`.';
        $lines[] = 'On an Architectural spec, append a `plan-approval` entry with `leg`, `cycle`, `at`, `reason` and outcome `looped-back` before returning `plan-insufficient`.';
    }

    return "## Overrides\n\n" . implode("\n", array_map(fn (string $line) => "- {$line}", $lines));
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

function pipeline_brief_return(string $leg, string $step): string
{
    $keys = implode(', ', array_map(fn (string $key) => "`{$key}`", pipeline_leg_writable_keys()));
    $statuses = implode(', ', array_map(fn (LegStatus $status) => "`{$status->value}`", LegStatus::allowedFor($leg, $step)));

    return "## Return\n\n"
        . "Write your results into the manifest ({$keys}; `cursor.reason` only when you halt) and nothing else. Never move `cursor.leg`.\n"
        . "Set `cursor.status` to one of {$statuses}, and reply with one line naming it.";
}
