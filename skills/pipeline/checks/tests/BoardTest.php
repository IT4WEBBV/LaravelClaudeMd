<?php

$configBoardless = <<<'MD'
# work-on — per-repo config

## Repo
- repo: IT4WEBBV/LaravelClaudeMd

## Board
# Omitted on purpose — board-less repo.

## Worktree
- create: git worktree add .claude/worktrees/<branch> -b <branch> origin/main
- docs: docs/worktrees.md
MD;

$configWithBoard = <<<'MD'
# work-on — per-repo config

## Repo
- repo: IT4WEBBV/Deploy

## Board
- org: IT4WEBBV
- number: 7
- project-id: PVT_kwDOAbc123
- status-field-id: PVTSSF_lADOStatus
- in-progress-option-id: 47fc9ee4
- component-field-id: PVTSSF_lADOComponent
- component-default: Deploy=b1a2c3d4
- docs: docs/board-ids.md

## Worktree
- create: ./scripts/worktree.sh create <branch>
- docs: docs/worktrees.md
MD;

it('reports absent for a repo that deliberately has no board', function () use ($configBoardless) {
    $result = pipeline_repo_board($configBoardless);

    expect($result['state'])->toBe('absent');
    expect($result['board'])->toBe([]);
    expect($result['error'])->toBeNull();
});

it('parses a complete board and stops at the next section', function () use ($configWithBoard) {
    $result = pipeline_repo_board($configWithBoard);

    expect($result['state'])->toBe('valid');
    expect($result['board'])->toBe([
        'org' => 'IT4WEBBV',
        'number' => '7',
        'project-id' => 'PVT_kwDOAbc123',
        'status-field-id' => 'PVTSSF_lADOStatus',
        'in-progress-option-id' => '47fc9ee4',
        'component-field-id' => 'PVTSSF_lADOComponent',
        'component-default' => 'Deploy=b1a2c3d4',
        'docs' => 'docs/board-ids.md',
    ]);
    expect($result['error'])->toBeNull();
});

it('does not read the Worktree section its own docs key', function () use ($configWithBoard) {
    // Both sections carry `docs`. Reading past the section boundary would pick up the
    // wrong one — the exact bug the handoff skill's section-aware cfg() exists to avoid.
    $result = pipeline_repo_board($configWithBoard);

    expect($result['board']['docs'])->toBe('docs/board-ids.md');
});

it('treats a half-filled board as invalid, never as usable', function () {
    $md = "## Board\n- org: IT4WEBBV\n- number: 7\n- project-id: PVT_kwDOAbc123\n";

    $result = pipeline_repo_board($md);

    expect($result['state'])->toBe('invalid');
    expect($result['board'])->toBe([]);
    expect($result['error'])->toContain('status-field-id');
    expect($result['error'])->toContain('in-progress-option-id');
});

it('treats a misspelled heading as invalid, never as absent', function () {
    // The tri-state's whole point: this must NOT read as "board-less repo", or the
    // status move silently stops happening while the run believes it is covered.
    $md = "## Boards\n- project-id: PVT_kwDOAbc123\n";

    $result = pipeline_repo_board($md);

    expect($result['state'])->toBe('invalid');
    expect($result['error'])->toContain('outside');
});

it('reads the untouched template scaffold as absent, not invalid', function () {
    // work-on copies the template into a fresh repo with every value still a
    // <placeholder>. That repo has not adopted a board; it is not misconfigured.
    $md = <<<'MD'
## Board
- org: <org-login>
- number: <N>
- project-id: <PVT_...>
- status-field-id: <PVTSSF_...>
- in-progress-option-id: <id>
MD;

    $result = pipeline_repo_board($md);

    expect($result['state'])->toBe('absent');
    expect($result['error'])->toBeNull();
});

it('is invalid when only some values are still placeholders', function () {
    $md = <<<'MD'
## Board
- org: IT4WEBBV
- number: 7
- project-id: PVT_kwDOAbc123
- status-field-id: <PVTSSF_...>
- in-progress-option-id: <id>
MD;

    $result = pipeline_repo_board($md);

    expect($result['state'])->toBe('invalid');
    expect($result['error'])->toContain('all-or-nothing');
});

it('rejects an unknown key inside the section', function () {
    $md = "## Board\n- org: IT4WEBBV\n- projekt-id: PVT_kwDOAbc123\n";

    $result = pipeline_repo_board($md);

    expect($result['state'])->toBe('invalid');
    expect($result['error'])->toContain("unknown board key 'projekt-id'");
});

it('ignores comment lines and trailing comments', function () {
    $md = <<<'MD'
## Board
# the ids come from docs/board-ids.md
- org: IT4WEBBV          # the org that owns the board
- number: 7
- project-id: PVT_kwDOAbc123
- status-field-id: PVTSSF_lADOStatus
- in-progress-option-id: 47fc9ee4
MD;

    $result = pipeline_repo_board($md);

    expect($result['state'])->toBe('valid');
    expect($result['board']['org'])->toBe('IT4WEBBV');
});

it('reports absent for a config with no Board section at all', function () {
    $md = "## Repo\n- repo: IT4WEBBV/Deploy\n\n## Worktree\n- docs: docs/worktrees.md\n";

    $result = pipeline_repo_board($md);

    expect($result['state'])->toBe('absent');
    expect($result['error'])->toBeNull();
});

it('sets a single-select field on the board item of a URL with two gh calls, and returns gh\'s words when either fails', function () {
    $board = ['org' => 'acme', 'number' => '7', 'project-id' => 'PVT_1'];
    $url = 'https://github.com/acme/app/pull/7';
    $calls = [];
    $gh = function (array $answers) use (&$calls): Closure {
        return function (array $args) use (&$calls, &$answers): array {
            $calls[] = implode(' ', $args);

            return array_shift($answers);
        };
    };

    expect(pipeline_board_set($gh([[0, '{"id":"ITEM_1"}', ''], [0, '', '']]), $board, $url, 'F_1', 'O_1'))->toBeNull();
    expect($calls)->toBe([
        'project item-add 7 --owner acme --url https://github.com/acme/app/pull/7 --format json',
        'project item-edit --id ITEM_1 --project-id PVT_1 --field-id F_1 --single-select-option-id O_1',
    ]);
    expect(pipeline_board_set($gh([[1, '', 'HTTP 401: Bad credentials']]), $board, $url, 'F_1', 'O_1'))->toBe('HTTP 401: Bad credentials');
    expect(pipeline_board_set($gh([[1, '', '']]), $board, $url, 'F_1', 'O_1'))->toBe('gh project item-add exited 1');
    expect(pipeline_board_set($gh([[0, '{}', '']]), $board, $url, 'F_1', 'O_1'))->toBe('gh project item-add returned no item id');
    expect(pipeline_board_set($gh([[0, '{"id":"ITEM_1"}', ''], [1, '', '']]), $board, $url, 'F_1', 'O_1'))->toBe('gh project item-edit exited 1');
});
