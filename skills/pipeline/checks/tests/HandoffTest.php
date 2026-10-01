<?php

function handoff_listed(array $overrides = []): array
{
    return ['number' => 7, 'url' => 'https://github.com/acme/app/pull/7', 'state' => 'OPEN', 'isDraft' => true, 'baseRefName' => 'main', 'headRefName' => 'feature', 'body' => '', ...$overrides];
}

it('titles the PR from the spec\'s heading, less its design suffix, with the issue when there is one', function (string $spec, ?int $issue, string $title) {
    expect(pipeline_handoff_title($spec, 'feature/x', $issue))->toBe($title);
})->with([
    'an issue' => ["# The handoff leg runs a command — design\n\n**Design size:** Architectural\n", 125, 'Implement: The handoff leg runs a command (issue: #125)'],
    'no issue' => ["# The handoff leg runs a command — design\n", null, 'Implement: The handoff leg runs a command'],
    'a hyphen before design' => ["# Palettes - design\n", 7, 'Implement: Palettes (issue: #7)'],
    'no design suffix' => ["# Palettes\n", 7, 'Implement: Palettes (issue: #7)'],
    'the first H1, not an H2 before it' => ["## Problem\n\n# Palettes — design\n", null, 'Implement: Palettes'],
    'CRLF line ends' => ["# Palettes — design\r\n\r\ntext\r\n", null, 'Implement: Palettes'],
    'no H1: the branch' => ["## Problem\n\ntext\n", 7, 'Implement: feature/x (issue: #7)'],
    'an empty spec: the branch' => ['', null, 'Implement: feature/x'],
]);

it('writes a new body that names the spec and the plan, and the issue without a closing keyword', function () {
    expect(pipeline_handoff_body('docs/spec.md', 'docs/plan.md', 125))
        ->toBe("Implements the design in `docs/spec.md`.\nPlan: `docs/plan.md`.\n\nPart of #125.");
    expect(pipeline_handoff_body('docs/spec.md', 'docs/plan.md', null))
        ->toBe("Implements the design in `docs/spec.md`.\nPlan: `docs/plan.md`.");
});

it('only ever adds to an existing body, and leaves one that names the spec, the plan and the issue alone', function (string $body, ?int $issue, ?string $updated) {
    expect(pipeline_handoff_body_update($body, 'docs/spec.md', 'docs/plan.md', $issue))->toBe($updated);
})->with([
    'names all three' => ["Implements the design in `docs/spec.md`.\nPlan: `docs/plan.md`.\n\nPart of #125.", 125, null],
    'a Closes written by a later leg gains no Part of' => ["Spec docs/spec.md, plan docs/plan.md.\n\nCloses #125.\n\nHalted: CI red.", 125, null],
    'names neither path' => ['Opened by hand.', 125, "Implements the design in `docs/spec.md`.\nPlan: `docs/plan.md`.\n\nPart of #125.\n\nOpened by hand."],
    'names one path' => ['See docs/spec.md. Part of #125.', 125, "Implements the design in `docs/spec.md`.\nPlan: `docs/plan.md`.\n\nSee docs/spec.md. Part of #125."],
    'names the paths, not the issue' => ['docs/spec.md and docs/plan.md', 125, "Part of #125.\n\ndocs/spec.md and docs/plan.md"],
    'another number that starts the same' => ['docs/spec.md and docs/plan.md, after #1250', 125, "Part of #125.\n\ndocs/spec.md and docs/plan.md, after #1250"],
    'no issue on the run' => ['docs/spec.md and docs/plan.md', null, null],
    'an empty body' => ['', 125, "Implements the design in `docs/spec.md`.\nPlan: `docs/plan.md`.\n\nPart of #125."],
]);

it('finds the Component target on a valid board with a default, a note where the default cannot be used, and nothing otherwise', function (array $board, array|string|null $target) {
    expect(pipeline_handoff_component($board))->toBe($target);
})->with([
    'a default and a field' => [
        ['state' => 'valid', 'board' => ['number' => '7', 'component-field-id' => 'CF_1', 'component-default' => 'Deploy=OPT_1'], 'error' => null],
        ['name' => 'Deploy', 'field' => 'CF_1', 'option' => 'OPT_1'],
    ],
    'no default' => [['state' => 'valid', 'board' => ['number' => '7', 'component-field-id' => 'CF_1'], 'error' => null], null],
    'a default without =' => [
        ['state' => 'valid', 'board' => ['number' => '7', 'component-field-id' => 'CF_1', 'component-default' => 'Deploy'], 'error' => null],
        'the Component was not set: `component-default` is not `<Name>=<option id>`',
    ],
    'a default without a field id' => [
        ['state' => 'valid', 'board' => ['number' => '7', 'component-default' => 'Deploy=OPT_1'], 'error' => null],
        'the Component was not set: `component-default` needs a `component-field-id`',
    ],
    'an absent board' => [['state' => 'absent', 'board' => [], 'error' => null], null],
]);

it('creates when the branch has no open PR, adopts its one draft, and halts on a ready one or on several', function () {
    expect(pipeline_handoff_choice([]))->toBeNull();
    expect(pipeline_handoff_choice([handoff_listed()]))->toBe(handoff_listed());
    expect(pipeline_handoff_choice([handoff_listed(['isDraft' => false])]))
        ->toBe('PR #7 is not a draft; a run only works on a draft PR (`gh pr ready --undo 7` first)');
    expect(pipeline_handoff_choice([handoff_listed(), handoff_listed(['number' => 8, 'baseRefName' => 'feature/integration'])]))
        ->toBe('the branch has more than one open PR (#7 into main, #8 into feature/integration): close all but one');
});

it('keeps git\'s and gh\'s words JSON-safe, and names the exit code when they said nothing', function () {
    expect(pipeline_handoff_words("  HTTP 502: Bad Gateway\n", 1))->toBe('HTTP 502: Bad Gateway');
    expect(pipeline_handoff_words('', 128))->toBe('exit 128');
    expect(json_encode(pipeline_handoff_words("HTTP 502 \xff", 1)))->not->toBeFalse();
});

it('takes the page heading from the spec, less its design suffix, else the branch', function () {
    expect(pipeline_handoff_heading("# Logs that follow — design\n\nbody", 'feature'))->toBe('Logs that follow');
    expect(pipeline_handoff_heading('no heading', 'feature/logs'))->toBe('feature/logs');
});

it('builds the page handoff files from the manifest and the PR, the title as a default', function () {
    $manifest = ['branch' => 'feature/logs', 'mode' => 'autoflow', 'worktree' => '/tmp/wt/', 'artifacts' => ['issue' => 125]];
    $pr = ['number' => 7, 'url' => 'https://github.com/acme/app/pull/7', 'baseRefName' => 'main'];

    expect(pipeline_handoff_proof($manifest, $pr, 'Logs that follow'))->toBe([
        'payload' => [
            'nameWithOwner' => 'acme/app', 'repo' => 'app', 'branch' => 'feature/logs', 'mode' => 'autoflow',
            'worktree' => '/tmp/wt', 'pr' => 7, 'prState' => 'OPEN', 'issue' => 125, 'base' => 'main',
        ],
        'defaults' => ['title' => 'PR #7: Logs that follow'],
    ]);
    expect(pipeline_handoff_proof([...$manifest, 'base' => 'feature/integration', 'artifacts' => ['issue' => null]], $pr, 'x')['payload'])
        ->toMatchArray(['base' => 'feature/integration'])->not->toHaveKey('issue');
    expect(mb_strlen(pipeline_handoff_proof($manifest, $pr, str_repeat('a long heading ', 10))['defaults']['title']))->toBeLessThanOrEqual(PROOF_TITLE_MAX);
});
