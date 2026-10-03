<?php

/** One `## ` section of a reference doc, heading included. */
function lockstep_section(string $doc, string $heading): string
{
    $markdown = (string) file_get_contents(__DIR__ . "/../../references/{$doc}");
    $start = strpos($markdown, "\n## {$heading}");
    expect($start)->not->toBeFalse("{$doc} has no section starting '## {$heading}'");
    $end = strpos($markdown, "\n## ", $start + 1);

    return $end === false ? substr($markdown, $start) : substr($markdown, $start, $end - $start);
}

it('keeps shared/proof-payload.md in lock-step with the fields the store files and checks', function () {
    $section = lockstep_section('shared/proof-payload.md', 'The payload');

    foreach (['clientSummary', 'explainer', 'worktree', 'base', 'state', ...PROOF_STORE_KEYS, ...array_column(ProofShotState::cases(), 'value'), ...array_column(QuestionKind::cases(), 'value')] as $field) {
        expect($section)->toContain("`{$field}`");
    }
    expect($section)->toContain('at most ' . PROOF_SUMMARY_MAX . ' characters');
});

it('keeps work-on out of the shared dev-stack rules', function () {
    expect((string) file_get_contents(__DIR__ . '/../../references/shared/dev-stack.md'))->not->toContain('`work-on`');
});

it('opens every shared file with the steps that read it', function () {
    $files = glob(__DIR__ . '/../../references/shared/*.md');

    expect($files)->toHaveCount(9);
    foreach ($files as $path) {
        expect((string) file_get_contents($path))->toMatch('/\A# .+\n\nRead by: `steps\/[a-z-]+\.md`/', basename($path) . ' opens without its readers');
    }
});

it('keeps manifest.md in lock-step with the statuses and the leg-writable keys', function () {
    $section = lockstep_section('manifest.md', 'What a leg writes');

    foreach (LegStatus::cases() as $status) {
        expect($section)->toContain("`{$status->value}`");
    }
    foreach (pipeline_leg_writable_keys() as $key) {
        expect($section)->toContain("`{$key}`");
    }
});

it('keeps gates.md in lock-step with the loop-back targets', function () {
    $section = lockstep_section('gates.md', 'Loop-backs');

    foreach (pipeline_legs() as $leg) {
        $target = pipeline_loop_target($leg);
        if ($target !== null) {
            expect($section)->toContain("`{$leg}` → `{$target}`");
        }
    }
});

it('keeps every engine.md section a brief names', function () {
    preg_match_all('/^## (.+?)(?: — .*)?$/m', (string) file_get_contents(__DIR__ . '/../../references/engine.md'), $headings);
    $lines = array_merge(
        ...array_values(pipeline_leg_overrides('autoflow', '/tmp/m.json')),
        ...array_values(pipeline_leg_overrides('interactive', '/tmp/m.json')),
        ...[[
            pipeline_review_scope_line(['since' => 'abc', 'base' => 'origin/main', 'commits' => 1, 'files' => []]),
            pipeline_catch_up_line(['worktree' => '/tmp/wt'], ['base' => 'origin/main', 'behind' => 1, 'shared' => []]),
            pipeline_conflict_round_line('review'),
            pipeline_conflict_round_line('resolve'),
            pipeline_answer_round_line('review'),
            pipeline_answer_round_line('resolve'),
        ]],
    );
    preg_match_all('/§([^,):;]+)/', implode("\n", $lines), $names);

    expect($names[1])->not->toBeEmpty();
    foreach ($names[1] as $name) {
        expect(array_filter($headings[1], fn (string $heading) => str_starts_with($name, $heading)))->not->toBeEmpty("engine.md has no section '{$name}'");
    }
});

it('keeps every model and effort out of the autoflow script, which takes them from launch', function () {
    $script = (string) file_get_contents(__DIR__ . '/../../workflow/pipeline-autoflow.js');

    foreach ([...PIPELINE_AGENT_MODELS, 'haiku', ...PIPELINE_AGENT_EFFORTS] as $name) {
        expect(preg_match("/(['\"`]){$name}\\1/", $script))->toBe(0, "the autoflow script names '{$name}'");
    }
});

it('keeps machinery.md\'s agents table in lock-step with pipeline_agent_table()', function () {
    $section = lockstep_section('machinery.md', 'Agents per step');
    $table = pipeline_agent_table([]);
    $cell = fn (array $entry) => "{$entry['model']} {$entry['effort']}";
    $same = fn (array $entry) => implode(' | ', array_fill(0, count(AgentTier::cases()), $cell($entry)));
    $rows = [];
    foreach (pipeline_agent_steps() as $step) {
        $rows[] = "| `{$step}` | " . implode(' | ', array_map(fn (AgentTier $tier) => $cell($table[$tier->value][$step]), AgentTier::cases())) . ' |';
    }
    foreach ($table['loopedBack'] as $step => $entry) {
        $rows[] = "| `{$step}` after a loop-back | {$same($entry)} |";
    }
    $rows[] = "| a review that returned nothing, once | {$same($table['retry'])} |";
    $rows[] = "| a smoke run's stub step, and the relay check | {$same($table['smoke'])} |";

    expect($section)->toContain('| Step | `full` | `medium` | `light` | Why |');
    expect($rows)->toHaveCount(12);
    foreach ($rows as $row) {
        expect($section)->toContain($row);
    }
});

it('keeps session.md\'s repo config section in lock-step with the keys the parsers read', function () {
    $section = lockstep_section('session.md', 'The repo config');

    foreach ([...PIPELINE_CHECK_KEYS, ...PIPELINE_BOARD_KEYS, ...PIPELINE_BOARD_OPTIONAL_KEYS] as $key) {
        expect($section)->toContain("`{$key}`");
    }
    foreach (['| `Repo` | `repo` |', '| `Worktree` | `create` |', '| `Worktree` | `remove` |', '| `Branch convention` | `issue` |'] as $row) {
        expect($section)->toContain($row);
    }
});

it('keeps steps/implement.md whole, down to its last paragraph', function () {
    expect(lockstep_section('steps/implement.md', 'Implement'))
        ->toContain('`gh pr checks <pr> --watch`')
        ->toContain('**`/work-on <pr>` on a pipeline PR is outside the run.**');
});

it('keeps work-on out of the step files', function () {
    foreach (['steps/implement.md', 'steps/handoff.md'] as $doc) {
        $text = (string) file_get_contents(__DIR__ . "/../../references/{$doc}");
        expect($text)->not->toContain('`work-on`', "{$doc} names `work-on`")->not->toContain('`work-on`\'s');
    }
});

it('has every step file read exactly the shared files that name it', function () {
    $references = __DIR__ . '/../../references';
    $readBy = [];
    foreach (glob("{$references}/shared/*.md") as $path) {
        preg_match('/^Read by: (.+)$/m', (string) file_get_contents($path), $line);
        preg_match_all('/`(steps\/[a-z-]+\.md)`/', $line[1] ?? '', $steps);
        foreach ($steps[1] as $step) {
            expect("{$references}/{$step}")->toBeFile('shared/' . basename($path) . " names {$step}");
            $readBy[$step][] = 'shared/' . basename($path);
        }
    }
    $files = glob("{$references}/steps/*.md");

    expect($files)->toHaveCount(8);
    foreach ($files as $path) {
        $step = 'steps/' . basename($path);
        expect((string) file_get_contents($path))->toMatch('/\A# .+\n\nRead also: /', "{$step} opens without its Read also line");
        preg_match('/^Read also: (.+)$/m', (string) file_get_contents($path), $line);
        preg_match_all('/`(shared\/[a-z-]+\.md)`/', $line[1], $shared);
        expect($shared[1])->toEqualCanonicalizing($readBy[$step] ?? [], "{$step}'s Read also line");
    }
});

it('keeps work-on out of the session\'s CI gate and out of SKILL.md', function () {
    expect(lockstep_section('session.md', 'The CI gate'))->not->toContain('`work-on`');
    foreach (glob(__DIR__ . '/../../references/{,steps/,shared/}*.md', GLOB_BRACE) as $path) {
        expect((string) file_get_contents($path))->not->toContain('`work-on`\'s', basename($path));
    }
    expect((string) file_get_contents(__DIR__ . '/../../SKILL.md'))->not->toContain('`work-on`');
});

it('keeps proof-store.md in lock-step with the statuses, the seen key and who files a page', function () {
    $statuses = lockstep_section('proof-store.md', 'Statuses');

    foreach (ProofRunStatus::cases() as $status) {
        expect($statuses)->toContain("`{$status->value}`");
    }
    expect($statuses)->toContain('proof_cli.php status <page>');
    expect(lockstep_section('proof-store.md', 'The index'))->toContain('`seen:<repo>/<run>`');
    expect(lockstep_section('proof-store.md', 'Where a page lives'))->toContain('`handoff` files');
});
