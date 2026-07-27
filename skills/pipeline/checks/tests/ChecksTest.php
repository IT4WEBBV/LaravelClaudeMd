<?php

$configWithout = <<<'MD'
# work-on — per-repo config

## Repo
- repo: IT4WEBBV/Deploy

## Worktree
- restart: ./scripts/restart.sh
MD;

$configWith = <<<'MD'
# work-on — per-repo config

## Repo
- repo: IT4WEBBV/Deploy

## Checks
- static-analysis: docker exec deploy<N>_web ./vendor/bin/phpstan analyse --no-progress
- format: docker exec deploy<N>_web ./vendor/bin/pint

## Branch convention
- issue: feature/issue-<number>-<slug>
MD;

it('reports absent when the repo has not adopted checks', function () use ($configWithout) {
    $result = pipeline_repo_checks($configWithout);

    expect($result['state'])->toBe('absent');
    expect($result['commands'])->toBe([]);
    expect($result['error'])->toBeNull();
});

it('parses both declared checks and stops at the next section', function () use ($configWith) {
    $result = pipeline_repo_checks($configWith);

    expect($result['state'])->toBe('valid');
    expect($result['commands'])->toBe([
        'static-analysis' => 'docker exec deploy<N>_web ./vendor/bin/phpstan analyse --no-progress',
        'format' => 'docker exec deploy<N>_web ./vendor/bin/pint',
    ]);
    expect($result['error'])->toBeNull();
});

it('accepts a section declaring only one of the two checks', function () {
    $md = "## Checks\n- format: docker exec app_web ./vendor/bin/pint\n";

    $result = pipeline_repo_checks($md);

    expect($result['state'])->toBe('valid');
    expect($result['commands'])->toBe(['format' => 'docker exec app_web ./vendor/bin/pint']);
});

it('treats a misspelled heading as invalid, never as absent', function () {
    // The whole point of the tri-state: this must NOT read as "not adopted".
    $md = "## Check\n- static-analysis: docker exec app_web ./vendor/bin/phpstan analyse\n";

    $result = pipeline_repo_checks($md);

    expect($result['state'])->toBe('invalid');
    expect($result['error'])->toContain('outside');
});

it('treats an unknown or mis-cased key inside the section as invalid', function () {
    $underscored = "## Checks\n- static_analysis: docker exec app_web ./vendor/bin/phpstan analyse\n";
    $miscased = "## Checks\n- Static-Analysis: docker exec app_web ./vendor/bin/phpstan analyse\n";

    expect(pipeline_repo_checks($underscored)['state'])->toBe('invalid');
    expect(pipeline_repo_checks($underscored)['error'])->toContain('static_analysis');
    expect(pipeline_repo_checks($miscased)['state'])->toBe('invalid');
});

it('treats a declared key with an empty value as invalid', function () {
    $md = "## Checks\n- format:\n";

    $result = pipeline_repo_checks($md);

    expect($result['state'])->toBe('invalid');
    expect($result['error'])->toContain('empty');
});

it('treats an empty Checks section as invalid', function () {
    $md = "## Checks\n\n## Branch convention\n- issue: feature/x\n";

    $result = pipeline_repo_checks($md);

    expect($result['state'])->toBe('invalid');
    expect($result['error'])->toContain('no check');
});

it('ignores comment lines and strips trailing comments from commands', function () {
    $md = "## Checks\n# format deliberately omitted for now\n- static-analysis: docker exec app_web ./vendor/bin/phpstan analyse   # level 5\n";

    $result = pipeline_repo_checks($md);

    expect($result['state'])->toBe('valid');
    expect($result['commands'])->toBe(['static-analysis' => 'docker exec app_web ./vendor/bin/phpstan analyse']);
});
