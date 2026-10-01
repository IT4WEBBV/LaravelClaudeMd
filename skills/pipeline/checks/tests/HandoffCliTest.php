<?php

/**
 * A fake gh in `{$root}/bin`, written in PHP so every call's argv is logged as it was given (one JSON line
 * per call in `{$root}/calls`). It keeps the repo's PRs in `{$root}/prs.json`: `pr list` and `pr view`
 * read it, `pr create` and `pr edit` write it. A file `{$root}/<first two words, dashed>-fails` makes
 * that subcommand fail with the file's content on stderr; `pr-create-vanishes` makes `pr create` succeed
 * without the PR showing up.
 *
 * @return array{PATH: string, GH_FAKE: string} the environment that puts it first on PATH
 */
function handoff_gh(string $root, array $prs = []): array
{
    mkdir("{$root}/bin");
    file_put_contents("{$root}/prs.json", json_encode($prs));
    file_put_contents("{$root}/bin/gh", '#!' . PHP_BINARY . "\n" . <<<'PHP'
<?php
$root = getenv('GH_FAKE');
$args = array_slice($argv, 1);
file_put_contents("{$root}/calls", json_encode($args) . "\n", FILE_APPEND);
$command = implode(' ', array_slice($args, 0, 2));
$fails = "{$root}/" . str_replace(' ', '-', $command) . '-fails';
if (is_file($fails)) {
    fwrite(STDERR, file_get_contents($fails));
    exit(1);
}
$flag = fn (string $name) => in_array($name, $args, true) ? $args[array_search($name, $args, true) + 1] : null;
$prs = json_decode(file_get_contents("{$root}/prs.json"), true);
$save = fn (array $prs) => file_put_contents("{$root}/prs.json", json_encode($prs));
switch ($command) {
    case 'pr list':
        echo json_encode(array_values(array_filter($prs, fn (array $pr) => $pr['state'] === 'OPEN' && $pr['headRefName'] === $flag('--head'))));
        break;
    case 'pr view':
        $found = array_values(array_filter($prs, fn (array $pr) => (string) $pr['number'] === $args[2]));
        if ($found === []) {
            fwrite(STDERR, 'no pull requests found');
            exit(1);
        }
        echo json_encode($found[0]);
        break;
    case 'pr create':
        $number = count($prs) + 7;
        if (! is_file("{$root}/pr-create-vanishes")) {
            $save([...$prs, ['number' => $number, 'url' => "https://github.com/acme/app/pull/{$number}", 'state' => 'OPEN', 'isDraft' => in_array('--draft', $args, true), 'baseRefName' => $flag('--base') ?? 'main', 'headRefName' => $flag('--head'), 'body' => $flag('--body')]]);
        }
        echo "https://github.com/acme/app/pull/{$number}\n";
        break;
    case 'pr edit':
        $change = array_filter(['baseRefName' => $flag('--base'), 'body' => $flag('--body')], fn ($value) => $value !== null);
        $save(array_map(fn (array $pr) => (string) $pr['number'] === $args[2] ? [...$pr, ...$change] : $pr, $prs));
        break;
    case 'project item-add':
        echo '{"id":"ITEM_1"}';
        break;
    case 'project item-edit':
        break;
    default:
        fwrite(STDERR, 'unexpected gh ' . implode(' ', $args));
        exit(1);
}
PHP);
    chmod("{$root}/bin/gh", 0755);

    return ['PATH' => "{$root}/bin:" . getenv('PATH'), 'GH_FAKE' => $root, 'PIPELINE_PROOF_ROOT' => "{$root}/proofs"];
}

/** A PR as gh lists it: open, draft, `feature` into `main`. */
function handoff_pr(array $overrides = []): array
{
    return ['number' => 7, 'url' => 'https://github.com/acme/app/pull/7', 'state' => 'OPEN', 'isDraft' => true, 'baseRefName' => 'main', 'headRefName' => 'feature', 'body' => '', ...$overrides];
}

/**
 * An autoflow run on `handoff:run`, briefed, for issue 125: `base_repo()`'s clone of a bare origin on
 * `feature` with a committed spec and plan, `$config` as its `.claude/work-on.config.md` when given, and
 * the fake gh holding `$prs`. The config is written before `record_fixture()` commits, so it is committed
 * and pushed with the branch: the command reads it from the worktree either way.
 */
function handoff_fixture(array $manifest = [], string $config = '', array $prs = []): array
{
    $dir = base_repo();
    $env = handoff_gh(dirname($dir), $prs);
    if ($config !== '') {
        mkdir("{$dir}/.claude");
        file_put_contents("{$dir}/.claude/work-on.config.md", $config);
    }
    $fixture = record_fixture('handoff', 'run', [
        'branch' => 'feature',
        'artifacts' => ['spec' => 'spec.md', 'plan' => 'plan.md', 'pr' => null, 'issue' => 125],
        ...$manifest,
    ], dir: $dir);

    return [...$fixture, 'root' => dirname($dir), 'env' => $env];
}

function handoff(array $fixture): array
{
    return dispatch_cli(['handoff', $fixture['manifest']], $fixture['env']);
}

/** @return list<list<string>> the argv of each call of the fake gh whose first two words are `$command`, in order */
function handoff_calls(array $fixture, string $command): array
{
    $path = "{$fixture['root']}/calls";
    $calls = is_file($path) ? array_map(fn (string $line) => json_decode($line, true), file($path, FILE_IGNORE_NEW_LINES)) : [];

    return array_values(array_filter($calls, fn (array $args) => implode(' ', array_slice($args, 0, 2)) === $command));
}

function handoff_pushed(array $fixture): bool
{
    return pipeline_git_run($fixture['repo'], ['ls-remote', '--exit-code', '--heads', 'origin', 'feature'])[0] === 0;
}

function handoff_board(): string
{
    return implode("\n", ['## Board', '- org: acme', '- number: 7', '- project-id: PVT_1', '- status-field-id: F_1', '- in-progress-option-id: O_1', '- component-field-id: CF_1', '- component-default: Deploy=OPT_1', '']);
}

/** The page `handoff` files for the fixture's PR #7 of acme/app on `feature`. */
function handoff_page(array $fixture): string
{
    return "{$fixture['root']}/proofs/app/pr-7-feature";
}

const HANDOFF_BODY = "Implements the design in `spec.md`.\nPlan: `plan.md`.\n\nPart of #125.";

it('pushes the branch, opens the draft PR and records it, and the next brief accepts the run', function () {
    $fixture = handoff_fixture();
    $head = pipeline_git($fixture['repo'], ['rev-parse', 'HEAD']);

    $result = handoff($fixture);

    expect($result['code'])->toBe(0);
    expect($result['json'])->toBe([
        'action' => 'recorded', 'leg' => 'handoff', 'step' => 'run', 'status' => 'continued', 'last_sha' => $head, 'entry' => null, 'replaced' => [],
        'pr' => 7, 'url' => 'https://github.com/acme/app/pull/7', 'created' => true, 'notes' => [], 'proof' => handoff_page($fixture) . '/index.html',
    ]);
    expect(pipeline_git($fixture['repo'], ['rev-parse', 'origin/feature']))->toBe($head);
    expect(handoff_calls($fixture, 'pr create'))->toBe([['pr', 'create', '--draft', '--head', 'feature', '--title', 'Implement: x (issue: #125)', '--body', HANDOFF_BODY]]);
    expect(handoff_calls($fixture, 'project item-add'))->toBe([]);
    expect(manifest_read($fixture['manifest']))->toMatchArray(['last_sha' => $head, 'cursor' => ['leg' => 'handoff', 'status' => 'continued']]);
    expect(manifest_read($fixture['manifest'])['artifacts']['pr'])->toBe(7);
    expect(manifest_read($fixture['manifest'])['artifacts']['proof'])->toBe(handoff_page($fixture) . '/index.html');
    expect(boundary_brief($fixture, 'implement', 'run', 'handoff:run', ['--status', 'continued'])['stdout'])->toContain('`implement` leg, `run` step');
});

it('adopts the open draft PR the branch already has, instead of opening a second one (#118)', function () {
    $fixture = handoff_fixture(prs: [handoff_pr(['body' => 'Opened by hand.'])]);

    $result = handoff($fixture);

    expect($result['json'])->toMatchArray(['action' => 'recorded', 'status' => 'continued', 'pr' => 7, 'created' => false, 'notes' => []]);
    expect(handoff_calls($fixture, 'pr create'))->toBe([]);
    expect(handoff_calls($fixture, 'pr edit'))->toBe([['pr', 'edit', '7', '--body', HANDOFF_BODY . "\n\nOpened by hand."]]);
    expect(handoff_pushed($fixture))->toBeTrue();
    expect(manifest_read($fixture['manifest'])['artifacts']['pr'])->toBe(7);
});

it('runs again over the PR the manifest knows: pushed, its body left as it is, recorded again', function () {
    $body = "Implements the design in `spec.md`.\nPlan: `plan.md`.\n\nCloses #125.\n\nHalted: CI red.";
    $fixture = handoff_fixture(['artifacts' => ['spec' => 'spec.md', 'plan' => 'plan.md', 'pr' => 7, 'issue' => 125]], prs: [handoff_pr(['body' => $body])]);

    $result = handoff($fixture);

    expect($result['json'])->toMatchArray(['action' => 'recorded', 'status' => 'continued', 'pr' => 7, 'created' => false]);
    expect(handoff_calls($fixture, 'pr view'))->toBe([['pr', 'view', '7', '--json', PIPELINE_HANDOFF_PR_FIELDS]]);
    expect(handoff_calls($fixture, 'pr list'))->toBe([]);
    expect(handoff_calls($fixture, 'pr create'))->toBe([]);
    expect(handoff_calls($fixture, 'pr edit'))->toBe([]);
    expect(handoff_pushed($fixture))->toBeTrue();
});

it('opens the PR of a run on a base into that base, and retargets one that exists', function () {
    $created = handoff_fixture(['base' => 'feature/integration']);
    expect(handoff($created)['json'])->toMatchArray(['status' => 'continued', 'created' => true, 'notes' => []]);
    expect(handoff_calls($created, 'pr create')[0])->toBe(['pr', 'create', '--draft', '--head', 'feature', '--base', 'feature/integration', '--title', 'Implement: x (issue: #125)', '--body', HANDOFF_BODY]);

    $existing = handoff_fixture(['base' => 'feature/integration'], prs: [handoff_pr(['body' => HANDOFF_BODY])]);
    expect(handoff($existing)['json'])->toMatchArray(['status' => 'continued', 'created' => false, 'notes' => ['PR #7 retargeted to feature/integration']]);
    expect(handoff_calls($existing, 'pr edit'))->toBe([['pr', 'edit', '7', '--base', 'feature/integration']]);
});

it('sets the Component on a board that names a default, and carries on with a note when the board does not answer', function () {
    $fixture = handoff_fixture(config: handoff_board());
    expect(handoff($fixture)['json'])->toMatchArray(['status' => 'continued', 'notes' => ['Component Deploy set on board 7']]);
    expect(handoff_calls($fixture, 'project item-add'))->toBe([['project', 'item-add', '7', '--owner', 'acme', '--url', 'https://github.com/acme/app/pull/7', '--format', 'json']]);
    expect(handoff_calls($fixture, 'project item-edit'))->toBe([['project', 'item-edit', '--id', 'ITEM_1', '--project-id', 'PVT_1', '--field-id', 'CF_1', '--single-select-option-id', 'OPT_1']]);

    $failing = handoff_fixture(config: handoff_board());
    file_put_contents("{$failing['root']}/project-item-add-fails", 'HTTP 401: Bad credentials');
    expect(handoff($failing)['json'])->toMatchArray(['action' => 'recorded', 'status' => 'continued', 'pr' => 7, 'notes' => ['the Component was not set: HTTP 401: Bad credentials']]);
});

it('records a halt before anything is pushed', function (Closure $arrange, string $reason) {
    $fixture = $arrange();

    $result = handoff($fixture);

    expect($result['code'])->toBe(0);
    expect($result['json'])->toMatchArray(['action' => 'recorded', 'leg' => 'handoff', 'step' => 'run', 'status' => 'halted']);
    expect($result['json']['reason'])->toStartWith($reason);
    expect(manifest_read($fixture['manifest'])['cursor'])->toMatchArray(['leg' => 'handoff', 'status' => 'halted']);
    expect(manifest_read($fixture['manifest'])['cursor']['reason'])->toStartWith($reason);
    expect(handoff_pushed($fixture))->toBeFalse();
    expect(handoff_calls($fixture, 'pr create'))->toBe([]);
})->with([
    'an invalid board' => [
        fn () => handoff_fixture(config: "## Board\n- org: acme\n"),
        "the ## Board section is invalid: '## Board' is all-or-nothing; missing: number, project-id, status-field-id, in-progress-option-id",
    ],
    'a spec that is not committed' => [
        fn () => handoff_fixture(['artifacts' => ['spec' => 'missing.md', 'plan' => 'plan.md', 'pr' => null, 'issue' => 125]]),
        'the spec missing.md does not exist at HEAD: commit it first',
    ],
    'no plan in the manifest' => [
        fn () => handoff_fixture(['artifacts' => ['spec' => 'spec.md', 'plan' => null, 'pr' => null, 'issue' => 125]]),
        'artifacts.plan is not set: handoff names the spec and the plan in the PR',
    ],
    'a PR that is not a draft' => [
        fn () => handoff_fixture(prs: [handoff_pr(['isDraft' => false])]),
        'PR #7 is not a draft; a run only works on a draft PR (`gh pr ready --undo 7` first)',
    ],
    'two open PRs' => [
        fn () => handoff_fixture(prs: [handoff_pr(), handoff_pr(['number' => 8, 'baseRefName' => 'feature/integration'])]),
        'the branch has more than one open PR (#7 into main, #8 into feature/integration): close all but one',
    ],
    'gh unable to list' => [
        function () {
            $fixture = handoff_fixture();
            file_put_contents("{$fixture['root']}/pr-list-fails", 'HTTP 502: Bad Gateway');

            return $fixture;
        },
        'gh could not list the open PRs of feature: HTTP 502: Bad Gateway',
    ],
    'gh words that are not UTF-8' => [
        function () {
            $fixture = handoff_fixture();
            file_put_contents("{$fixture['root']}/pr-list-fails", "HTTP 502 \xff");

            return $fixture;
        },
        'gh could not list the open PRs of feature: HTTP 502 ',
    ],
    'a known PR that is closed' => [
        fn () => handoff_fixture(['artifacts' => ['spec' => 'spec.md', 'plan' => 'plan.md', 'pr' => 7, 'issue' => 125]], prs: [handoff_pr(['state' => 'CLOSED'])]),
        'PR #7 is closed',
    ],
    'another branch\'s PR' => [
        fn () => handoff_fixture(['artifacts' => ['spec' => 'spec.md', 'plan' => 'plan.md', 'pr' => 7, 'issue' => 125]], prs: [handoff_pr(['headRefName' => 'feature/other'])]),
        "PR #7 is for the branch feature/other, not the run's branch feature",
    ],
    'another branch checked out' => [
        function () {
            $fixture = handoff_fixture();
            pipeline_git($fixture['repo'], ['switch', '-q', '-c', 'other']);

            return $fixture;
        },
        "the worktree is on other, not on the run's branch feature",
    ],
    'a detached HEAD' => [
        function () {
            $fixture = handoff_fixture();
            pipeline_git($fixture['repo'], ['switch', '-q', '--detach']);

            return $fixture;
        },
        "the worktree is on HEAD, not on the run's branch feature",
    ],
]);

it('records a halt with git\'s words when the push is refused, and opens no PR', function () {
    $fixture = handoff_fixture();
    pipeline_git($fixture['repo'], ['push', '-q', 'origin', 'feature']);
    base_moves($fixture['repo'], ['elsewhere.php' => "<?php\n"], 'feature');

    $result = handoff($fixture);

    expect($result['json'])->toMatchArray(['action' => 'recorded', 'status' => 'halted']);
    expect($result['json']['reason'])->toStartWith('git refused the push of feature: ')->toContain('rejected');
    expect(handoff_calls($fixture, 'pr create'))->toBe([]);
});

it('halts when gh opened a PR it does not list yet, with the branch pushed', function () {
    $fixture = handoff_fixture();
    touch("{$fixture['root']}/pr-create-vanishes");

    $result = handoff($fixture);

    expect($result['json'])->toMatchArray(['action' => 'recorded', 'status' => 'halted', 'reason' => 'gh opened a PR for feature but does not list it yet; the next run of this step adopts it']);
    expect(handoff_pushed($fixture))->toBeTrue();
});

it('refuses when the write does not land, names the PR, and adopts it on the next run (#118)', function () {
    $fixture = handoff_fixture();
    chmod($fixture['manifest'], 0444);

    $refused = handoff($fixture);
    chmod($fixture['manifest'], 0644);

    expect($refused['code'])->toBe(1);
    expect($refused['json'])->toBe(['action' => 'refused', 'reason' => "the write did not land at {$fixture['manifest']}; PR #7 is open and the next run of this step adopts it"]);
    expect(manifest_read($fixture['manifest'])['cursor'])->toBe(['leg' => 'handoff', 'status' => 'pending']);

    expect(handoff($fixture)['json'])->toMatchArray(['action' => 'recorded', 'status' => 'continued', 'pr' => 7, 'created' => false]);
    expect(handoff_calls($fixture, 'pr create'))->toHaveCount(1);
    expect(handoff_calls($fixture, 'pr edit'))->toBe([]);
    expect(manifest_read($fixture['manifest'])['artifacts']['pr'])->toBe(7);
});

it('refuses without this step\'s snapshot or on the retired mode, before gh is called', function () {
    $bare = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'handoff', 'status' => 'pending']]);
    $bare = [...$bare, 'root' => $bare['dir'], 'env' => handoff_gh($bare['dir'])];
    $result = handoff($bare);
    expect($result['code'])->toBe(1);
    expect($result['json'])->toBe(['action' => 'refused', 'reason' => "no snapshot at {$bare['before']}: record follows this step's brief (next in interactive)"]);

    $other = record_fixture('implement', 'run');
    $other = [...$other, 'root' => $other['dir'], 'env' => handoff_gh($other['dir'])];
    expect(handoff($other)['json'])->toBe(['action' => 'refused', 'reason' => 'the snapshot is of the implement run step, not handoff run']);

    $retired = dispatch_fixture(['mode' => 'auto', 'cursor' => ['leg' => 'handoff', 'status' => 'pending']]);
    copy($retired['manifest'], $retired['before']);
    $retired = [...$retired, 'root' => $retired['dir'], 'env' => handoff_gh($retired['dir'])];
    expect(handoff($retired)['json']['reason'])->toStartWith('mode auto was removed');

    foreach ([$bare, $other, $retired] as $fixture) {
        expect(is_file("{$fixture['root']}/calls"))->toBeFalse();
    }
    expect(dispatch_cli(['handoff'])['code'])->toBe(1);
    expect(dispatch_cli(['handoff', $bare['manifest'], 'extra'])['code'])->toBe(1);
});

it('records an interactive handoff without an issue, and returned routes to implement', function () {
    $dir = base_repo();
    $env = handoff_gh(dirname($dir));
    rereview_commit($dir, ['spec.md' => "# x — design\n\n**Design size:** Architectural\n", 'plan.md' => "# x Implementation Plan\n"]);
    $fixture = dispatch_fixture([
        'mode' => 'interactive', 'branch' => 'feature', 'worktree' => $dir,
        'cursor' => ['leg' => 'handoff', 'status' => 'pending'],
        'artifacts' => ['spec' => 'spec.md', 'plan' => 'plan.md', 'pr' => null, 'issue' => null],
    ]);
    $fixture = [...$fixture, 'root' => dirname($dir), 'env' => $env];
    expect(dispatch_cli(['next', $fixture['manifest']])['json'])->toMatchArray(['action' => 'dispatch', 'leg' => 'handoff', 'step' => 'run']);

    expect(handoff($fixture)['json'])->toMatchArray(['action' => 'recorded', 'status' => 'continued', 'pr' => 7, 'created' => true]);
    expect(handoff_calls($fixture, 'pr create'))->toBe([['pr', 'create', '--draft', '--head', 'feature', '--title', 'Implement: x', '--body', "Implements the design in `spec.md`.\nPlan: `plan.md`."]]);
    expect(dispatch_cli(['returned', $fixture['manifest'], $fixture['diff']])['json'])->toMatchArray(['action' => 'dispatch', 'leg' => 'implement', 'step' => 'run']);
});

it('files the run\'s page from what it knows, with the prose pending, and records it', function () {
    // No `base` in the manifest: the page's base is the one gh lists for the PR.
    $fixture = handoff_fixture();

    expect(handoff($fixture)['json'])->toMatchArray(['status' => 'continued', 'notes' => [], 'proof' => handoff_page($fixture) . '/index.html']);

    $run = proof_read_run(handoff_page($fixture));
    expect($run)->toMatchArray([
        'nameWithOwner' => 'acme/app', 'repo' => 'app', 'branch' => 'feature', 'mode' => 'autoflow',
        'worktree' => $fixture['repo'], 'pr' => 7, 'prState' => 'OPEN', 'issue' => 125, 'base' => 'main',
        'title' => 'PR #7: x', 'addedTests' => [], 'schema' => 2,
    ]);
    expect(file_get_contents(handoff_page($fixture) . '/index.html'))->toContain('Pending: written by the step that finishes the run.');
    expect(file_get_contents("{$fixture['root']}/proofs/index.html"))->toContain('pr-7-feature/index.html');
});

it('merges over the page on a re-run, keeping the title a step wrote', function () {
    $fixture = handoff_fixture();
    mkdir(handoff_page($fixture), 0777, true);
    file_put_contents(handoff_page($fixture) . '/run.json', json_encode(['repo' => 'app', 'branch' => 'feature', 'pr' => 7, 'title' => 'PR #7: logs that follow', 'headline' => 'Logs follow', 'schema' => 2, 'revision' => 2]));

    handoff($fixture);

    expect(proof_read_run(handoff_page($fixture)))->toMatchArray(['title' => 'PR #7: logs that follow', 'headline' => 'Logs follow', 'base' => 'main', 'worktree' => $fixture['repo'], 'revision' => 3, 'status' => ['state' => 'running']]);
});

it('records continued without a page, and says why, when the page cannot be filed', function (Closure $arrange, string $why) {
    $fixture = handoff_fixture();
    $arrange($fixture);

    $result = handoff($fixture);
    chmod("{$fixture['root']}/proofs", 0755);

    expect($result['code'])->toBe(0);
    expect($result['json'])->toMatchArray(['action' => 'recorded', 'status' => 'continued', 'pr' => 7, 'proof' => null]);
    expect($result['json']['notes'][0])->toStartWith("the proof page was not filed: {$why}");
    expect(manifest_read($fixture['manifest'])['artifacts'])->not->toHaveKey('proof');
})->with([
    'a stored shot without a state' => [
        function (array $fixture) {
            mkdir(handoff_page($fixture), 0777, true);
            file_put_contents(handoff_page($fixture) . '/run.json', json_encode(['repo' => 'app', 'branch' => 'feature', 'pr' => 7, 'title' => 'PR #7: x', 'shots' => [['title' => 'Log']]]));
        },
        'shot 1 has no state: before, after or defect',
    ],
    'a store root it cannot write' => [
        function (array $fixture) {
            mkdir("{$fixture['root']}/proofs");
            chmod("{$fixture['root']}/proofs", 0555);
        },
        'cannot create',
    ],
]);
