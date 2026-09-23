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

it('keeps the autoflow script in lock-step with the legs, loop-backs, statuses and bound it repeats', function () {
    $script = (string) file_get_contents(__DIR__ . '/../../workflow/pipeline-autoflow.js');
    $quoted = fn (array $values) => implode(', ', array_map(fn (string $value) => "'{$value}'", $values));

    expect($script)->toContain('const LEGS = [' . $quoted(pipeline_legs()) . ']');
    foreach (pipeline_legs() as $leg) {
        $target = pipeline_loop_target($leg);
        if ($target !== null) {
            expect($script)->toContain("'{$leg}': '{$target}'");
        }
    }
    $keys = ["'design:run'" => ['design', 'run'], 'review' => ['review-plan', 'review'], 'resolve' => ['review-pr', 'resolve'], "'verify-ui:run'" => ['verify-ui', 'run'], 'run' => ['implement', 'run']];
    foreach ($keys as $key => [$leg, $step]) {
        $statuses = array_map(fn (LegStatus $status) => $status->value, LegStatus::allowedFor($leg, $step));
        expect($script)->toContain("{$key}: [" . $quoted($statuses) . ']');
    }
    expect($script)->toContain('const BOUND = ' . PIPELINE_LOOP_BOUND);
});
