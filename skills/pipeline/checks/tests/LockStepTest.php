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

it('keeps every engine.md section a brief names', function () {
    preg_match_all('/^## (.+?)(?: — .*)?$/m', (string) file_get_contents(__DIR__ . '/../../references/engine.md'), $headings);
    $lines = array_merge(...array_values(pipeline_leg_overrides('autoflow')), ...array_values(pipeline_leg_overrides('interactive')));
    preg_match_all('/§([^,):;]+)/', implode("\n", $lines), $names);

    expect($names[1])->not->toBeEmpty();
    foreach ($names[1] as $name) {
        expect(array_filter($headings[1], fn (string $heading) => str_starts_with($name, $heading)))->not->toBeEmpty("engine.md has no section '{$name}'");
    }
});
