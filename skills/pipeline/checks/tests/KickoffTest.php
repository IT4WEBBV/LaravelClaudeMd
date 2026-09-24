<?php

it('reads a key from its own section only, without its trailing comment', function () {
    $config = implode("\n", [
        '# work-on — per-repo config',
        '## Repo',
        '- repo: acme/app    # the GitHub repo',
        '## Worktree',
        '# - create: commented out',
        '- create: ./scripts/worktree.sh create <branch> --no-start',
        '## Branch convention',
        '- issue: feature/issue-<number>-<slug>   # issue pickup',
        '- pr: use head.ref',
        '### Worktree notes',
        '- empty:',
    ]);

    expect(pipeline_repo_config_value($config, 'Repo', 'repo'))->toBe('acme/app');
    expect(pipeline_repo_config_value($config, 'Worktree', 'create'))->toBe('./scripts/worktree.sh create <branch> --no-start');
    expect(pipeline_repo_config_value($config, 'Branch convention', 'issue'))->toBe('feature/issue-<number>-<slug>');
    expect(pipeline_repo_config_value($config, 'Repo', 'issue'))->toBeNull();
    expect(pipeline_repo_config_value($config, 'Board', 'org'))->toBeNull();
    expect(pipeline_repo_config_value($config, 'Branch convention', 'empty'))->toBeNull();
});

it('slugs a title into a branch-safe name of at most 50 characters', function (string $title, string $slug) {
    expect(pipeline_slug($title))->toBe($slug);
})->with([
    'punctuation' => ['Pipeline: kickoff as one command', 'pipeline-kickoff-as-one-command'],
    'edges and runs' => ['  --Hello,   World!--  ', 'hello-world'],
    'a dash inside a word' => ['UserForm: e-mailadres moet uniek zijn per tenant', 'userform-e-mailadres-moet-uniek-zijn-per-tenant'],
    'accents' => ['Überschrift ändern', 'berschrift-ndern'],
    'cut at a word' => ['pipeline: kickoff as one tested dispatch_cli command, so no session judgement precedes launch', 'pipeline-kickoff-as-one-tested-dispatch-cli'],
    'a word ending at 50' => [str_repeat('a', 50) . ' b', str_repeat('a', 50)],
    'no word boundary' => [str_repeat('a', 60), str_repeat('a', 50)],
    'nothing to slug' => ['!!!', ''],
]);

it('finds a placeholder, and does not mistake a shell redirection for one', function (string $text, ?string $placeholder) {
    expect(pipeline_placeholder($text))->toBe($placeholder);
})->with([
    'a slot to compute' => ['./scripts/worktree.sh create feature/x --slot <next-free-N>', '<next-free-N>'],
    'redirections' => ['make create 2>&1 < input.txt', null],
    'nothing left' => ['git worktree add .claude/worktrees/feature/x -b feature/x origin/main', null],
]);

it('cuts a branch pattern at the slug, so every branch for the issue shares the prefix', function (string $pattern, ?string $prefix) {
    expect(pipeline_branch_prefix($pattern, 69))->toBe($prefix);
})->with([
    'feature branches' => ['feature/issue-<number>-<slug>', 'feature/issue-69-'],
    'worktree branches' => ['worktree-issue-<number>-<slug>', 'worktree-issue-69-'],
    'no slug' => ['feature/issue-<number>', null],
    'the number after the slug' => ['<slug>-<number>', null],
]);
