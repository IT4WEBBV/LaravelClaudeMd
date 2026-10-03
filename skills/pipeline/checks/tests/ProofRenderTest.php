<?php

function proof_fixture_run(array $overrides = []): array
{
    return array_merge([
        'repo' => 'ViewieMedia',
        'nameWithOwner' => 'IT4WEBBV/ViewieMedia',
        'branch' => 'feature/orders-export',
        'pr' => 412,
        'prState' => 'OPEN',
        'mode' => 'autoflow',
        'updatedAt' => '2026-08-25T15:30:00+02:00',
        'title' => 'PR #412: product summary grid',
        'headline' => 'Order rows gain a product summary grid',
        'problem' => 'Order rows showed no product detail.',
        'solution' => 'Added a summary grid to the row partial.',
        'checks' => ['tests' => '142 passed', 'staticAnalysis' => '0 new findings over app/', 'format' => 'clean', 'suppressions' => []],
        'openQuestions' => [],
        'ledger' => [],
        'shots' => [],
    ], $overrides);
}

it('escapes every value it interpolates', function () {
    $html = proof_render_run(proof_fixture_run([
        'title' => '<b>bold</b>',
        'headline' => '<script>alert(1)</script>',
        'problem' => 'a & b < c',
        'shots' => [['file' => 'shots/01.png', 'title' => '<i>shot</i>', 'caption' => '<u>caption</u>', 'route' => '/', 'badges' => []]],
    ]));

    expect($html)->not->toContain('<script>alert(1)</script>');
    expect($html)->toContain('&lt;script&gt;');
    expect($html)->toContain('a &amp; b &lt; c');
    expect($html)->toContain('&lt;b&gt;bold&lt;/b&gt;');
    expect($html)->toContain('&lt;i&gt;shot&lt;/i&gt;');
    expect($html)->toContain('&lt;u&gt;caption&lt;/u&gt;');
});

it('names the page by its short title and gives the headline its own lead paragraph', function () {
    $html = proof_render_run(proof_fixture_run());

    expect($html)->toContain('<title>PR #412: product summary grid</title>');
    expect($html)->toContain('<h1>PR #412: product summary grid</h1>');
    expect($html)->toContain('<p class="lead">Order rows gain a product summary grid</p>');
});

it('names a run filed before titles existed by its branch, not by its long headline', function () {
    $run = proof_fixture_run(['headline' => str_repeat('A long summary written as a title. ', 15)]);
    unset($run['title']);

    $page = proof_render_run($run);
    $index = proof_render_index([['dir' => '/store/ViewieMedia/pr-412-orders-export', 'run' => $run]]);

    expect($page)->toContain('<h1>feature/orders-export</h1>');
    expect($page)->toContain('<p class="lead">A long summary');
    expect($index)->toContain('index.html">feature/orders-export</a>');
});

it('labels each shot by its short title and puts the detail in a caption beneath it', function () {
    $html = proof_render_run(proof_fixture_run([
        'shots' => [['file' => 'shots/01-orders.png', 'title' => 'Orders index', 'caption' => 'Every row shows the product grid', 'route' => '/orders', 'badges' => []]],
    ]));

    expect($html)->toContain('<strong>Orders index</strong>');
    expect($html)->toContain('alt="Orders index"');
    expect($html)->toContain('<span class="caption">Every row shows the product grid</span>');
});

it('renders a shot without a caption without an empty caption line', function () {
    $html = proof_render_run(proof_fixture_run([
        'shots' => [['file' => 'shots/01-orders.png', 'title' => 'Orders index', 'route' => '/orders', 'badges' => []]],
    ]));

    expect($html)->not->toContain('class="caption"');
});

it('renders a self-contained page with only relative image paths', function () {
    $html = proof_render_run(proof_fixture_run([
        'shots' => [['file' => 'shots/01-orders.png', 'title' => 'Orders index', 'route' => '/orders', 'badges' => []]],
    ]));

    expect($html)->toStartWith('<!doctype html>');
    expect($html)->toContain('src="shots/01-orders.png"');
    // A page that *fetches* over the network is not self-contained. A hyperlink is not a fetch:
    // the PR and issue references are absolute precisely because the page opens over file://.
    expect($html)->not->toContain('src="http');
    expect($html)->not->toContain('<link ');
    expect($html)->not->toContain('<script src');
});

it('places numbered badges from percentage positions and lists them in a legend', function () {
    $html = proof_render_run(proof_fixture_run([
        'shots' => [[
            'file' => 'shots/01-orders.png',
            'title' => 'Orders index',
            'route' => '/orders',
            'badges' => [['num' => 1, 'topPct' => 12.4, 'leftPct' => 58.0, 'title' => 'Product summary grid', 'note' => 'Was: no product detail']],
        ]],
    ]));

    expect($html)->toContain('top:12.4%');
    expect($html)->toContain('left:58%');
    expect($html)->toContain('Product summary grid');
    expect($html)->toContain('Was: no product detail');
});

it('carries each badge number onto its legend marker, so a continuously-numbered run still matches', function () {
    // The <ol> renumbers from 1 per figure. A run that numbers badges continuously across shots
    // put a "5" on the image and a "1." in the legend beneath it, referring to nothing.
    $html = proof_render_run(proof_fixture_run([
        'shots' => [[
            'file' => 'shots/05-werk.png',
            'title' => 'Werk block',
            'route' => '/professionals',
            'badges' => [['num' => 5, 'topPct' => 85, 'leftPct' => 4, 'title' => 'Three 16px gaps', 'note' => 'the overrides were inert']],
        ]],
    ]));

    expect($html)->toContain('class="badge" style="top:85%;left:4%" tabindex="0">5<');
    expect($html)->toContain('<li value="5">');
});

it('omits the list marker when a badge number is not numeric', function () {
    // value="" is only meaningful on an <ol> item and only accepts an integer; a non-numeric
    // label must fall back to the list's own numbering rather than emit an invalid attribute.
    $html = proof_render_run(proof_fixture_run([
        'shots' => [[
            'file' => 'shots/01-orders.png',
            'title' => 'Orders index',
            'route' => '/orders',
            'badges' => [['num' => 'A', 'topPct' => 10, 'leftPct' => 10, 'title' => 'Lettered callout', 'note' => 'no value attribute']],
        ]],
    ]));

    expect($html)->toContain('>A<');
    expect($html)->not->toContain('<li value=');
});

it('links the PR reference to GitHub with an absolute URL', function () {
    $html = proof_render_run(proof_fixture_run(['pr' => 967, 'prState' => 'MERGED']));

    expect($html)->toContain('<a href="https://github.com/IT4WEBBV/ViewieMedia/pull/967" target="_blank" rel="noopener">#967 (MERGED)</a>');
});

it('links the issue the run is for, from the payload field', function () {
    $html = proof_render_run(proof_fixture_run(['issue' => 919]));

    expect($html)->toContain('<a href="https://github.com/IT4WEBBV/ViewieMedia/issues/919" target="_blank" rel="noopener">issue #919</a>');
});

it('derives the issue number from the branch when the payload carries none', function () {
    // Runs filed before `issue` existed still have to link somewhere.
    $html = proof_render_run(proof_fixture_run(['branch' => 'feature/issue-919-body-margin-sweep']));

    expect($html)->toContain('https://github.com/IT4WEBBV/ViewieMedia/issues/919');
});

it('renders no issue reference at all when no source yields a number', function () {
    // A confidently wrong issue link is worse than none, so a number is never invented.
    $html = proof_render_run(proof_fixture_run(['branch' => 'feature/reissue-5-retry']));

    expect($html)->not->toContain('/issues/');
    expect($html)->not->toContain('issue #');
});

it('keeps references as plain text when the run names no repo to link into', function () {
    $html = proof_render_run(proof_fixture_run(['nameWithOwner' => null, 'issue' => 919]));

    expect($html)->not->toContain('<a href="https://github.com');
    expect($html)->toContain('#412 (OPEN)');
    expect($html)->toContain('issue #919');
});

it('omits the visual section entirely when there are no shots', function () {
    $html = proof_render_run(proof_fixture_run());

    expect($html)->not->toContain('Visual result');
});

it('renders open questions verbatim and flags suppressions as not yet judged', function () {
    $html = proof_render_run(proof_fixture_run([
        'openQuestions' => ['The empty-state copy was not reviewed.'],
        'checks' => ['tests' => '142 passed', 'staticAnalysis' => '0 new findings over app/', 'format' => 'clean', 'suppressions' => ['Orders.php:88 — argument.type']],
    ]));

    expect($html)->toContain('The empty-state copy was not reviewed.');
    expect($html)->toContain('Orders.php:88 — argument.type');
    expect($html)->toContain('not yet judged');
});

it('labels each open question with its kind, and a string filed before kinds without one (#146)', function () {
    $html = proof_render_run(proof_fixture_run(['openQuestions' => [
        ['kind' => 'blocking', 'question' => 'Keep the <x-time> tag?'],
        ['kind' => 'follow-up', 'question' => 'File the cleanup?'],
        ['kind' => 'remark', 'question' => 'self-end alignment'],
        'Filed before kinds.',
    ]]));

    expect($html)->toContain("<h2>Open questions</h2>\n<ul>\n")
        ->toContain('<li><strong>Blocking:</strong> Keep the &lt;x-time&gt; tag?</li>')
        ->toContain('<li><strong>Follow-up:</strong> File the cleanup?</li>')
        ->toContain('<li><strong>Remark:</strong> self-end alignment</li>')
        ->toContain('<li>Filed before kinds.</li>');
});

it('states the analysed scope rather than an unqualified all-clear', function () {
    $html = proof_render_run(proof_fixture_run());

    // "0 new findings" without its scope reads as covering database/, routes/, config/ and tests/.
    expect($html)->toContain('0 new findings over app/');
});

it('links each run by its relative directory so the index works over file://', function () {
    $html = proof_render_index([
        ['dir' => '/store/ViewieMedia/feature-b', 'run' => proof_fixture_run(['repo' => 'ViewieMedia', 'branch' => 'feature/b'])],
    ]);

    expect($html)->toContain('href="ViewieMedia/feature-b/index.html"');
    expect($html)->not->toContain('/store/');
});

it('links a run at the directory it was found in, not at one re-derived from the run', function () {
    // Runs filed under an earlier naming scheme sit beside PR-keyed ones and must stay reachable.
    $html = proof_render_index([
        ['dir' => '/store/BreinStraat2/pr-967-body-margin-sweep', 'run' => proof_fixture_run(['repo' => 'BreinStraat2', 'branch' => 'feature/issue-919-body-margin-sweep', 'pr' => 967])],
        ['dir' => '/store/Deploy/feature-legacy-shape', 'run' => proof_fixture_run(['repo' => 'Deploy', 'branch' => 'feature/legacy-shape', 'pr' => 404])],
    ]);

    expect($html)->toContain('href="BreinStraat2/pr-967-body-margin-sweep/index.html"');
    expect($html)->toContain('href="Deploy/feature-legacy-shape/index.html"');
});

it('names a run that opened no PR plainly, since the prune pass now removes it', function () {
    $html = proof_render_index([
        ['dir' => '/store/Deploy/feature-halted', 'run' => proof_fixture_run(['repo' => 'Deploy', 'pr' => null, 'prState' => null])],
    ]);

    expect($html)->toContain('<td data-sort=""><span class="reason">no PR</span></td>');
    expect($html)->not->toContain('prune manually');
});

it('links the PR column of the index to the PR on GitHub', function () {
    $html = proof_render_index([
        ['dir' => '/store/ViewieMedia/pr-412-orders-export', 'run' => proof_fixture_run(['pr' => 412, 'prState' => 'OPEN'])],
    ]);

    expect($html)->toContain('<td data-sort="412"><a href="https://github.com/IT4WEBBV/ViewieMedia/pull/412" target="_blank" rel="noopener">#412 OPEN</a></td>');
});

it('keeps the PR column as plain text for a run that names no repo to link into', function () {
    $html = proof_render_index([
        ['dir' => '/store/Deploy/pr-404-legacy', 'run' => proof_fixture_run(['repo' => 'Deploy', 'nameWithOwner' => null, 'pr' => 404, 'prState' => 'MERGED'])],
    ]);

    expect($html)->toContain('<td data-sort="404">#404 MERGED</td>');
    expect($html)->not->toContain('/pull/404');
});

it('renders an empty store without failing', function () {
    $html = proof_render_index([]);

    expect($html)->toStartWith('<!doctype html>');
    expect($html)->toContain('No runs recorded');
});

it('links each run in the index by its short title', function () {
    $html = proof_render_index([
        ['dir' => '/store/ViewieMedia/pr-412-orders-export', 'run' => proof_fixture_run()],
    ]);

    expect($html)->toContain('index.html">PR #412: product summary grid</a>');
    expect($html)->not->toContain('Order rows gain a product summary grid');
});

it('shows the PR number and state for a run that has one', function () {
    $html = proof_render_index([
        ['dir' => '/store/ViewieMedia/feature-b', 'run' => proof_fixture_run(['pr' => 412, 'prState' => 'MERGED'])],
    ]);

    expect($html)->toContain('412');
    expect($html)->toContain('MERGED');
    expect($html)->not->toContain('prune manually');
});

/** A run as filed from now on: schema 2, with the prose and a test list. */
function proof_current_run(array $overrides = []): array
{
    return proof_fixture_run([
        'schema' => 2,
        'clientSummary' => 'Bij elke bestelregel staat nu een overzicht van de producten.',
        'explainer' => ['problem' => 'An order row did not say what was ordered.', 'solution' => 'Each row now lists its products.'],
        'addedTests' => [['file' => 'tests/Feature/OrdersTest.php', 'cases' => [['name' => 'shows the grid', 'change' => 'added'], ['name' => 'test_totals', 'change' => 'changed']]]],
        ...$overrides,
    ]);
}

it('opens with the Dutch client summary and its copy button, then the explainer, above the technical account', function () {
    $html = proof_render_run(proof_current_run());

    expect($html)->toContain('<p lang="nl" id="client-summary">Bij elke bestelregel staat nu een overzicht van de producten.</p>');
    expect($html)->toContain('<button type="button" class="copy" data-copy="client-summary">Copy</button>');
    expect($html)->toContain("<h2>In plain language</h2>\n<h3>The problem</h3>\n<p>An order row did not say what was ordered.</p>");
    expect($html)->toContain("<h3>The solution</h3>\n<p>Each row now lists its products.</p>");
    expect(strpos($html, 'id="client-summary"'))->toBeLessThan(strpos($html, 'In plain language'));
    expect(strpos($html, 'In plain language'))->toBeLessThan(strpos($html, '<p class="lead">'));
    expect(strpos($html, '<p class="lead">'))->toBeLessThan(strpos($html, '<h2>Problem</h2>'));
});

it('lists the tests the PR adds per file, tagged new or changed, and says so when there are none', function () {
    $html = proof_render_run(proof_current_run());

    expect($html)->toContain("<h2>Tests this PR adds</h2>\n<h3><code>tests/Feature/OrdersTest.php</code></h3>");
    expect($html)->toContain('<li>shows the grid <span class="tag tag-added">new</span></li>');
    expect($html)->toContain('<li>test_totals <span class="tag tag-changed">changed</span></li>');
    expect(strpos($html, 'Tests this PR adds'))->toBeLessThan(strpos($html, '<h2>Checks</h2>'));
    expect(proof_render_run(proof_current_run(['addedTests' => []])))->toContain('This PR adds or changes no test cases.');
});

it('marks the summary and the explainer pending on the page handoff files, with no copy button', function () {
    $run = proof_current_run();
    unset($run['clientSummary'], $run['explainer']);
    $html = proof_render_run($run);

    expect(substr_count($html, '<p class="pending">Pending: written by the step that finishes the run.</p>'))->toBe(2);
    expect($html)->not->toContain('data-copy=');
});

it('renders a run filed before this change as before: no summary, explainer, tests or pending lines', function () {
    // Shaped like _proofs/Asimo/pr-210: schema 1, shots without a state.
    $html = proof_render_run(proof_fixture_run([
        'schema' => 1,
        'shots' => [['file' => 'shots/01-klant-dashboard.png', 'title' => 'Hero house number list', 'route' => '/klant/dashboard',
            'badges' => [['num' => 1, 'topPct' => 46, 'leftPct' => 37, 'title' => 'No stale empty line', 'note' => 'The list starts at -1.']]]],
    ]));

    foreach (['Client summary', 'In plain language', 'Tests this PR adds', 'Pending:', 'class="ribbon'] as $absent) {
        expect($html)->not->toContain($absent);
    }
    expect($html)->toContain('<h2>Problem</h2>')->toContain('<h2>Visual result</h2>');
});

it('puts a ribbon on each shot by its state', function () {
    $html = proof_render_run(proof_current_run(['shots' => [
        ['file' => 'shots/01.png', 'title' => 'Old', 'route' => '/', 'state' => 'defect', 'badges' => []],
        ['file' => 'shots/02.png', 'title' => 'Lone before', 'route' => '/', 'state' => 'before', 'badges' => []],
    ]]));

    expect($html)->toContain('<span class="ribbon ribbon-defect">Defect</span>');
    expect($html)->toContain('<span class="ribbon ribbon-before">Before</span>');
    expect($html)->not->toContain('class="pair"');
});

it('pairs a before shot directly followed by an after shot in one row, and only those', function () {
    $shot = fn (string $title, string $state) => ['file' => "shots/{$title}.png", 'title' => $title, 'route' => '/', 'state' => $state, 'badges' => []];
    $html = proof_render_run(proof_current_run(['shots' => [$shot('b1', 'before'), $shot('a1', 'after'), $shot('a2', 'after'), $shot('b2', 'before'), $shot('d1', 'defect')]]));

    expect(substr_count($html, '<div class="pair">'))->toBe(1);
    expect(proof_shot_rows([$shot('b1', 'before'), $shot('a1', 'after'), $shot('a2', 'after'), $shot('b2', 'before'), $shot('d1', 'defect')]))
        ->toBe([[$shot('b1', 'before'), $shot('a1', 'after')], [$shot('a2', 'after')], [$shot('b2', 'before')], [$shot('d1', 'defect')]]);
});

it('holds each badge\'s note in a focusable tooltip, and keeps the legend', function () {
    $html = proof_render_run(proof_current_run(['shots' => [[
        'file' => 'shots/01.png', 'title' => 'Orders', 'route' => '/orders', 'state' => 'after',
        'badges' => [['num' => 1, 'topPct' => 10, 'leftPct' => 20, 'title' => 'Grid', 'note' => 'Was: nothing']],
    ]]]));

    expect($html)->toContain('<span class="badge" style="top:10%;left:20%" tabindex="0">1<span class="tip" role="tooltip">Grid — Was: nothing</span></span>');
    expect($html)->toContain('<li value="1"><strong>Grid</strong> — Was: nothing</li>');
});

it('carries the zoom dialog and the copy script inline', function () {
    $html = proof_render_run(proof_current_run());

    expect($html)->toContain('<dialog class="zoom" id="zoom"></dialog>');
    expect($html)->toContain('navigator.clipboard.writeText');
    expect($html)->toContain("document.execCommand('copy')");
    expect($html)->toContain('showModal()');
    expect($html)->not->toContain('<script src');
});

it('escapes the summary, the explainer, the test names and their files', function () {
    $html = proof_render_run(proof_current_run([
        'clientSummary' => 'Klant <b>"blij"</b> & tevreden',
        'explainer' => ['problem' => '<script>x</script>', 'solution' => 'a < b'],
        'addedTests' => [['file' => 'tests/<i>X</i>Test.php', 'cases' => [['name' => 'shows <em>it</em>', 'change' => 'added']]]],
    ]));

    expect($html)->toContain('Klant &lt;b&gt;&quot;blij&quot;&lt;/b&gt; &amp; tevreden');
    expect($html)->toContain('&lt;script&gt;x&lt;/script&gt;')->toContain('a &lt; b');
    expect($html)->toContain('tests/&lt;i&gt;X&lt;/i&gt;Test.php')->toContain('shows &lt;em&gt;it&lt;/em&gt;');
    expect($html)->not->toContain('<script>x</script>');
});

it('shows the run\'s status between the heading and the meta line', function (array $overrides, string $pill, string $label) {
    $html = proof_render_run(proof_current_run($overrides));

    expect($html)->toContain("<p class=\"status\"><span class=\"pill pill-{$pill}\">{$label}</span></p>");
    expect(strpos($html, '<p class="status">'))->toBeGreaterThan(strpos($html, '</h1>'))->toBeLessThan(strpos($html, '<p class="meta">'));
})->with([
    'stored running' => [['status' => ['state' => 'running']], 'running', 'Running'],
    'stored ready' => [['status' => ['state' => 'ready']], 'ready', 'Ready for review'],
    'stored merged' => [['status' => ['state' => 'merged']], 'merged', 'Merged'],
    'stored closed' => [['status' => ['state' => 'closed']], 'closed', 'Closed'],
    'an older merged run' => [['prState' => 'MERGED'], 'merged', 'Merged'],
    'an older open run' => [[], 'running', 'Running'],
]);

it('puts a halted run\'s reason beside its pill, escaped', function () {
    $html = proof_render_run(proof_current_run(['status' => ['state' => 'halted', 'reason' => 'CI red on <b>abc</b>']]));

    expect($html)->toContain('<p class="status"><span class="pill pill-halted">Halted</span> <span class="reason">CI red on &lt;b&gt;abc&lt;/b&gt;</span></p>');
});

it('names the revision in the meta line, puts the seen number on the body, and leaves both out for a run without a revision', function () {
    $html = proof_render_run(proof_current_run(['revision' => 3, 'attention' => 2]));
    expect($html)->toContain(' · revision 3</p>')->toContain("<body data-seen=\"5\">\n")->not->toContain('data-revision');

    expect(proof_render_run(proof_current_run(['revision' => 3])))->toContain("<body data-seen=\"3\">\n");
    expect(proof_render_run(proof_current_run()))->not->toContain(' · revision')->toContain("<body>\n");
});

it('records the page as seen at its number, keyed by its repo and run directories, before the copy and zoom code', function () {
    $html = proof_render_run(proof_current_run(['revision' => 3]));

    expect($html)->toContain('var seen = document.body.dataset.seen;');
    expect($html)->toContain("localStorage.setItem('seen:' + run, seen)");
    expect($html)->toContain(".split('/').filter(Boolean).slice(-2)");
    expect(strpos($html, "localStorage.setItem('seen:'"))->toBeLessThan(strpos($html, 'navigator.clipboard.writeText'));
    expect(strpos($html, 'navigator.clipboard.writeText'))->toBeLessThan(strpos($html, 'showModal()'));
});

/** One step's figures as `run_cost_cli.php` files them. */
function proof_cost_step(string $label, float $cost, float $wall = 60.0, array $models = ['opus']): array
{
    return ['label' => $label, 'models' => $models, 'cost' => $cost, 'calls' => 1, 'peak' => 182000, 'wall' => $wall, 'waiting' => 0.0];
}

it('shows each step\'s time and cost after the open questions and before the ledger, with the run\'s totals', function () {
    $html = proof_render_run(proof_current_run([
        'openQuestions' => ['Keep the guard?'],
        'ledger' => [['gate' => 'pr-review', 'outcome' => 'continued', 'note' => 'n']],
        'cost' => [['workflow' => 'wf_a', 'span' => 1200.0, 'steps' => [
            ['label' => 'implement:run', 'models' => ['sonnet'], 'cost' => 2310000.0, 'calls' => 41, 'peak' => 182000, 'wall' => 780.0, 'waiting' => 312.0],
        ]]],
    ]));

    expect($html)->toContain('<h2>Time and cost</h2>');
    expect($html)->toContain('<tr><th>implement:run</th><td>sonnet</td><td class="num">13.0 min</td><td class="num">5.2 min</td><td class="num">2.31M</td><td class="num">182k</td></tr>');
    expect($html)->toContain('<tr class="total"><th>Total</th><td></td><td class="num">20.0 min</td><td></td><td class="num">2.31M</td><td></td></tr>');
    expect($html)->not->toContain('<tr class="workflow">');
    expect(strpos($html, 'Time and cost'))->toBeGreaterThan(strpos($html, 'Keep the guard?'))->toBeLessThan(strpos($html, 'Gate ledger'));
});

it('names each workflow of a run that had several, in filing order, and sums their spans and costs', function () {
    $html = proof_render_run(proof_current_run(['cost' => [
        ['workflow' => 'wf_first', 'span' => 600.0, 'steps' => [proof_cost_step('implement:run', 1000000.0)]],
        ['workflow' => 'wf_fix', 'span' => 300.0, 'steps' => [proof_cost_step('review-pr:review', 500000.0)]],
    ]]));

    expect($html)->toContain('<tr class="workflow"><th colspan="6">wf_first</th></tr>');
    expect(strpos($html, 'wf_fix'))->toBeGreaterThan(strpos($html, 'implement:run'))->toBeLessThan(strpos($html, 'review-pr:review'));
    expect($html)->toContain('<td class="num">15.0 min</td><td></td><td class="num">1.50M</td>');
});

it('has no time and cost section for a run without figures', function () {
    expect(proof_render_run(proof_current_run()))->not->toContain('Time and cost');
});

it('escapes the workflow names, the step labels and the models', function () {
    $html = proof_render_run(proof_current_run(['cost' => [
        ['workflow' => '<i>wf</i>', 'span' => 1.0, 'steps' => [proof_cost_step('<b>step</b>', 1.0, 1.0, ['<s>m</s>'])]],
        ['workflow' => 'wf_b', 'span' => 1.0, 'steps' => []],
    ]]));

    expect($html)->toContain('&lt;i&gt;wf&lt;/i&gt;')->toContain('&lt;b&gt;step&lt;/b&gt;')->toContain('&lt;s&gt;m&lt;/s&gt;');
    expect($html)->not->toContain('<b>step</b>');
});

/** An index entry for a Deploy run in `/store/Deploy/<name>`. */
function proof_index_entry(string $name, array $run): array
{
    return ['dir' => "/store/Deploy/{$name}", 'run' => proof_fixture_run(['repo' => 'Deploy', ...$run])];
}

it('orders the index by attention: halted, then ready, then the rest, each newest first by time', function () {
    $runs = [
        proof_index_entry('running', ['updatedAt' => '2026-10-01T02:00:00Z']),
        proof_index_entry('ready-old', ['status' => ['state' => 'ready'], 'updatedAt' => '2026-09-20T12:00:00+02:00']),
        // 04:30 UTC on 1 October: newer than `running`, though its string sorts before it.
        proof_index_entry('merged-new', ['status' => ['state' => 'merged'], 'updatedAt' => '2026-09-30T23:30:00-05:00']),
        proof_index_entry('halted-old', ['status' => ['state' => 'halted', 'reason' => 'r'], 'updatedAt' => '2026-09-01T12:00:00+02:00']),
        proof_index_entry('ready-new', ['status' => ['state' => 'ready'], 'updatedAt' => '2026-09-30T09:00:00Z']),
        proof_index_entry('halted-new', ['status' => ['state' => 'halted', 'reason' => 'r'], 'updatedAt' => '2026-09-29T12:00:00+02:00']),
    ];

    expect(array_map(fn (array $entry) => basename($entry['dir']), proof_index_order($runs)))
        ->toBe(['halted-new', 'halted-old', 'ready-new', 'ready-old', 'merged-new', 'running']);

    $html = proof_render_index($runs);
    expect(strpos($html, 'halted-old/index.html'))->toBeLessThan(strpos($html, 'ready-new/index.html'));
    expect(strpos($html, 'ready-old/index.html'))->toBeLessThan(strpos($html, 'merged-new/index.html'));
    expect(strpos($html, 'merged-new/index.html'))->toBeLessThan(strpos($html, '/running/index.html'));
});

it('gives each row what the index script needs, its status, its figures, and a copy button only with a summary', function () {
    $ready = proof_index_entry('pr-5-logs', [
        'revision' => 3, 'status' => ['state' => 'ready'], 'updatedAt' => '2026-10-01T10:00:00+02:00',
        'clientSummary' => 'De logboeken lopen mee.',
        'cost' => [['workflow' => 'wf_a', 'span' => 1200.0, 'steps' => [proof_cost_step('implement:run', 2310000.0)]]],
    ]);
    $halted = ['dir' => '/store/Asimo/feature-old', 'run' => proof_fixture_run(['repo' => 'Asimo', 'updatedAt' => '2026-09-01T10:00:00+02:00', 'status' => ['state' => 'halted', 'reason' => 'CI red']])];
    $html = proof_render_index([$ready, $halted]);
    $copy = 'summary-' . substr(sha1('Deploy/pr-5-logs'), 0, 8);

    expect($html)->toContain('<tr data-run="Deploy/pr-5-logs" data-repo="Deploy" data-group="1" data-status="ready" data-finished="0" data-updated="2026-10-01T10:00:00+02:00" data-seen="3" data-search="pr #412: product summary grid #412 feature/orders-export de logboeken lopen mee." data-hash="' . proof_index_row_hash($ready) . '">');
    expect($html)->toContain('<tr data-run="Asimo/feature-old" data-repo="Asimo" data-group="0" data-status="halted" data-finished="0" data-updated="2026-09-01T10:00:00+02:00" data-search="pr #412: product summary grid #412 feature/orders-export" data-hash="' . proof_index_row_hash($halted) . '">');
    expect(proof_render_index_row(proof_index_entry('pr-7-x', ['revision' => 3, 'attention' => 2])))
        ->toContain(' data-updated="2026-08-25T15:30:00+02:00" data-seen="5" data-search=');
    expect($html)->not->toContain('data-revision');
    expect($html)->toContain('<td data-sort="0"><span class="pill pill-halted">Halted</span> <span class="reason">CI red</span></td>');
    expect($html)->toContain('index.html">PR #412: product summary grid</a><span class="marker"></span></td>');
    expect($html)->toContain('<td class="num" data-sort="1200">20.0 min</td><td class="num" data-sort="2310000">2.31M</td>');
    expect($html)->toContain('<td class="num" data-sort=""></td><td class="num" data-sort=""></td>');
    expect($html)->toContain("<button type=\"button\" class=\"copy\" data-copy=\"{$copy}\">Copy</button><span id=\"{$copy}\" lang=\"nl\" hidden>De logboeken lopen mee.</span>");
    expect(substr_count($html, 'class="copy"'))->toBe(1);
    expect(substr_count($html, 'class="dot"'))->toBe(1);
    expect($html)->toContain('<select id="repo-filter"><option value="">All repos</option><option value="Asimo">Asimo</option><option value="Deploy">Deploy</option></select>');
});

it('carries the index script: seen markers, the remembered filters and toggle, search, header sorting, local times and the copy code', function () {
    $html = proof_render_index([proof_index_entry('pr-5-logs', [])]);

    expect($html)->toContain('<body class="index">');
    expect($html)->toContain("storage.getItem('seen:' + row.dataset.run)");
    expect($html)->toContain("remember('proof:repo', repo.value)")
        ->toContain("remember('proof:status', status.value)")
        ->toContain("remember('proof:finished', finished.checked ? '1' : '0')")
        ->toContain("restore(repo, 'proof:repo')")
        ->toContain("restore(status, 'proof:status')");
    // The toggle governs only All statuses: an explicit Merged or Closed shows those rows whatever it says.
    expect($html)->toContain("status.value === '' ? finished.checked || row.dataset.finished === '0' : row.dataset.status === status.value");
    expect($html)->toContain("search.addEventListener('input', show)");
    expect($html)->toContain("header.setAttribute('aria-sort'");
    expect($html)->toContain("querySelectorAll('time[datetime]')");
    expect($html)->toContain("window.addEventListener('pageshow'");
    expect($html)->toContain('navigator.clipboard.writeText');
    expect($html)->not->toContain('showModal()');
    expect(proof_render_index([]))->not->toContain('id="repo-filter"')->toContain('No runs recorded');
});

it('renders an index script that parses as JavaScript', function () {
    $file = sys_get_temp_dir() . '/proof-index-' . uniqid() . '.js';
    file_put_contents($file, proof_render_index_script());
    exec('node --check ' . escapeshellarg($file) . ' 2>&1', $output, $code);
    unlink($file);

    expect($code)->toBe(0, implode("\n", $output));
});

it('escapes the repo, the title, the reason and the summary in the index', function () {
    $html = proof_render_index([['dir' => '/store/X/pr-1-x', 'run' => proof_fixture_run([
        'repo' => '<b>R</b>', 'title' => '<i>T</i>', 'status' => ['state' => 'halted', 'reason' => '<u>why</u>'],
        'clientSummary' => 'Klant <b>"blij"</b>', 'updatedAt' => '"><script>',
    ])]]);

    expect($html)->toContain('&lt;b&gt;R&lt;/b&gt;')->toContain('&lt;i&gt;T&lt;/i&gt;')->toContain('&lt;u&gt;why&lt;/u&gt;');
    expect($html)->toContain('Klant &lt;b&gt;&quot;blij&quot;&lt;/b&gt;')->toContain('data-updated="&quot;&gt;&lt;script&gt;"');
    expect($html)->toContain('data-search="&lt;i&gt;t&lt;/i&gt; #412 feature/orders-export klant &lt;b&gt;&quot;blij&quot;&lt;/b&gt;"');
    expect($html)->toContain('<td data-sort="&lt;b&gt;R&lt;/b&gt;"><code>&lt;b&gt;R&lt;/b&gt;</code></td>');
    expect($html)->toContain('<td data-sort=""></td><td>');
    expect($html)->not->toContain('<b>R</b>');
});

it('names a row\'s copy target by its run, the same on every render', function () {
    $entry = proof_index_entry('pr-5-logs', ['clientSummary' => 'De logboeken lopen mee.']);
    $id = 'summary-' . substr(sha1('Deploy/pr-5-logs'), 0, 8);

    expect(proof_render_index_row($entry))->toBe(proof_render_index_row($entry))
        ->toContain("data-copy=\"{$id}\"")->toContain("<span id=\"{$id}\" lang=\"nl\" hidden>");
    expect(proof_render_index_row(proof_index_entry('pr-6-other', ['clientSummary' => 'x'])))->not->toContain($id);
});

it('renders status.js with each run\'s key, status, revision, seen number, hash and row, in the attention order', function () {
    $ready = proof_index_entry('pr-5-logs', ['revision' => 3, 'status' => ['state' => 'ready'], 'updatedAt' => '2026-10-01T10:00:00+02:00']);
    $halted = proof_index_entry('pr-6-old', ['status' => ['state' => 'halted', 'reason' => 'CI red'], 'updatedAt' => '2026-09-01T10:00:00+02:00']);

    $runs = proof_test_status_runs(proof_render_status_js([$ready, $halted]));

    expect(array_column($runs, 'key'))->toBe(['Deploy/pr-6-old', 'Deploy/pr-5-logs']);
    expect($runs[1])->toBe([
        'key' => 'Deploy/pr-5-logs',
        'status' => 'ready',
        'revision' => 3,
        'seen' => 3,
        'hash' => proof_index_row_hash($ready),
        'row' => proof_render_index_row($ready),
    ]);
    // A run filed before revisions existed has none, and gets no marker.
    expect($runs[0])->toMatchArray(['status' => 'halted', 'revision' => null, 'seen' => null]);
    expect(proof_index_row_hash($ready))->toMatch('/^[0-9a-f]{12}$/');
    expect($runs[1]['row'])->toContain(' data-hash="' . proof_index_row_hash($ready) . '">');
    expect(proof_test_status_runs(proof_render_status_js([proof_index_entry('pr-7-x', ['revision' => 3, 'attention' => 2])]))[0])
        ->toMatchArray(['revision' => 3, 'seen' => 5]);
    expect(proof_test_status_runs(proof_render_status_js([])))->toBe([]);
});

it('changes a run\'s hash when its status, revision, attention or cost changes, and only then', function () {
    $entry = proof_index_entry('pr-5-logs', ['revision' => 1, 'status' => ['state' => 'running']]);
    $hash = proof_index_row_hash($entry);
    $with = fn (array $changes): string => proof_index_row_hash(['dir' => $entry['dir'], 'run' => [...$entry['run'], ...$changes]]);

    expect(proof_index_row_hash($entry))->toBe($hash);
    expect($with(['status' => ['state' => 'halted', 'reason' => 'CI red']]))->not->toBe($hash);
    expect($with(['revision' => 2]))->not->toBe($hash);
    expect($with(['attention' => 1]))->not->toBe($hash);
    expect($with(['cost' => [['workflow' => 'wf_a', 'span' => 60.0, 'steps' => []]]]))->not->toBe($hash);
    expect(proof_index_row_hash(['dir' => '/store/Asimo/pr-5-logs', 'run' => $entry['run']]))->not->toBe($hash);
});

it('keeps status.js one valid script whatever a title or summary holds', function () {
    $nasty = "</script><script>alert(1)</script> \"quoted\" it's \u{2028}line\u{2029}para";
    $js = proof_render_status_js([proof_index_entry('pr-5-logs', ['title' => $nasty, 'clientSummary' => $nasty])]);
    $file = sys_get_temp_dir() . '/proof-status-' . uniqid() . '.js';
    file_put_contents($file, $js);
    exec('node --check ' . escapeshellarg($file) . ' 2>&1', $output, $code);
    unlink($file);

    expect($code)->toBe(0, implode("\n", $output));
    expect($js)->not->toContain('</script>')->not->toContain("\u{2028}")->not->toContain("\u{2029}");
    expect(proof_test_status_runs($js)[0]['row'])->toContain('&lt;/script&gt;')->not->toContain('<script>');
});

it('puts the repo and status filters, the search and the toggle with its count above the table', function () {
    $html = proof_render_index([
        proof_index_entry('pr-1-merged', ['status' => ['state' => 'merged']]),
        proof_index_entry('pr-2-closed', ['prState' => 'CLOSED']),
        ['dir' => '/store/Asimo/pr-3-running', 'run' => proof_fixture_run(['repo' => 'Asimo', 'status' => ['state' => 'running']])],
    ]);

    expect($html)->toContain("<div class=\"controls\">\n"
        . "<label>Repo <select id=\"repo-filter\"><option value=\"\">All repos</option><option value=\"Asimo\">Asimo</option><option value=\"Deploy\">Deploy</option></select></label>\n"
        . "<label>Status <select id=\"status-filter\"><option value=\"\">All statuses</option><option value=\"running\">Running</option><option value=\"halted\">Halted</option><option value=\"ready\">Ready for review</option><option value=\"merged\">Merged</option><option value=\"closed\">Closed</option></select></label>\n"
        . "<input type=\"search\" id=\"search\" placeholder=\"Title, PR, branch or summary\" aria-label=\"Search runs\">\n"
        . "<label><input type=\"checkbox\" id=\"show-finished\"> Show merged and closed (<span id=\"finished-count\">2</span>)</label>\n"
        . "</div>\n");
    expect(strpos($html, 'class="controls"'))->toBeLessThan(strpos($html, '<table id="runs">'));
});

it('gives each row its status, whether it is finished, and the lower-cased text the search matches', function () {
    $merged = proof_index_entry('pr-5-logs', [
        'title' => 'PR #5: Logs That Follow', 'pr' => 5, 'branch' => 'feature/Logs', 'status' => ['state' => 'merged'],
        'clientSummary' => 'De Logboeken lopen mee.', 'updatedAt' => '2026-10-01T10:00:00+02:00',
    ]);
    $halted = proof_index_entry('feature-halted', [
        'title' => null, 'pr' => null, 'prState' => null, 'branch' => 'feature/halted',
        'status' => ['state' => 'halted', 'reason' => 'r'], 'updatedAt' => '2026-09-01T10:00:00+02:00',
    ]);
    $html = proof_render_index([$merged, $halted]);

    expect($html)->toContain('<tr data-run="Deploy/pr-5-logs" data-repo="Deploy" data-group="2" data-status="merged" data-finished="1" data-updated="2026-10-01T10:00:00+02:00" data-search="pr #5: logs that follow #5 feature/logs de logboeken lopen mee." data-hash="' . proof_index_row_hash($merged) . '">');
    // Without a title the run is named by its branch, which the search text then holds once.
    expect($html)->toContain('<tr data-run="Deploy/feature-halted" data-repo="Deploy" data-group="0" data-status="halted" data-finished="0" data-updated="2026-09-01T10:00:00+02:00" data-search="feature/halted" data-hash="' . proof_index_row_hash($halted) . '">');
});

it('makes every column but Summary sortable, each with its type and the direction of a first click', function () {
    $html = proof_render_index([proof_index_entry('pr-5-logs', [])]);

    expect($html)->toContain('<thead><tr>'
        . '<th data-sort-type="number" data-sort-first="asc"><button type="button" class="sort">Status</button></th>'
        . '<th data-sort-type="text" data-sort-first="asc"><button type="button" class="sort">Repo</button></th>'
        . '<th data-sort-type="number" data-sort-first="desc"><button type="button" class="sort">PR</button></th>'
        . '<th data-sort-type="text" data-sort-first="asc"><button type="button" class="sort">Run</button></th>'
        . '<th data-sort-type="number" data-sort-first="desc" class="num"><button type="button" class="sort">Shots</button></th>'
        . '<th data-sort-type="number" data-sort-first="desc" class="num"><button type="button" class="sort">Time</button></th>'
        . '<th data-sort-type="number" data-sort-first="desc" class="num"><button type="button" class="sort">Cost</button></th>'
        . '<th data-sort-type="number" data-sort-first="desc"><button type="button" class="sort">Updated</button></th>'
        . "<th>Summary</th></tr></thead>\n");
    expect(substr_count($html, 'class="sort"'))->toBe(8);
});

it('gives each sortable cell its key, and an empty key where there is nothing to sort by', function () {
    $html = proof_render_index([
        proof_index_entry('pr-5-logs', [
            'pr' => 5, 'title' => 'PR #5: logs', 'status' => ['state' => 'closed'], 'updatedAt' => '2026-10-01T20:29:13+00:00',
            'shots' => [['title' => 'a'], ['title' => 'b']],
            'cost' => [['workflow' => 'wf_a', 'span' => 1200.0, 'steps' => [proof_cost_step('implement:run', 2310000.0)]]],
        ]),
        proof_index_entry('feature-halted', ['pr' => null, 'prState' => null, 'status' => ['state' => 'halted', 'reason' => 'r'], 'updatedAt' => 'not a date']),
    ]);

    expect($html)->toContain('<td data-sort="4"><span class="pill pill-closed">Closed</span></td>'
        . '<td data-sort="Deploy"><code>Deploy</code></td>'
        . '<td data-sort="5"><a href="https://github.com/IT4WEBBV/ViewieMedia/pull/5" target="_blank" rel="noopener">#5 OPEN</a></td>'
        . '<td data-sort="PR #5: logs"><a href="Deploy/pr-5-logs/index.html">PR #5: logs</a><span class="marker"></span></td>'
        . '<td class="num" data-sort="2">2</td><td class="num" data-sort="1200">20.0 min</td><td class="num" data-sort="2310000">2.31M</td>'
        . '<td data-sort="1790886553"><time datetime="2026-10-01T20:29:13+00:00" title="2026-10-01T20:29:13+00:00">01-10 20:29</time></td>');
    expect($html)->toContain('<td data-sort="0"><span class="pill pill-halted">Halted</span> <span class="reason">r</span></td>');
    expect($html)->toContain('<td data-sort=""><span class="reason">no PR</span></td>');
    expect($html)->toContain('<td class="num" data-sort="0">0</td><td class="num" data-sort=""></td><td class="num" data-sort=""></td><td data-sort=""></td><td></td></tr>');
});

it('shows Updated as day-month and time in the timestamp\'s own offset, with the full timestamp on hover', function () {
    $html = proof_render_index([proof_index_entry('pr-5-logs', ['updatedAt' => '2026-10-01T22:29:13+02:00'])]);

    expect($html)->toContain('<td data-sort="1790886553"><time datetime="2026-10-01T22:29:13+02:00" title="2026-10-01T22:29:13+02:00">01-10 22:29</time></td>');
    expect($html)->not->toContain('>01-10 20:29<')->not->toContain('>2026-10-01<');
});

it('wraps the table so it scrolls on its own, and renders the no-match line hidden', function () {
    $html = proof_render_index([proof_index_entry('pr-5-logs', [])]);

    expect($html)->toContain("<div class=\"table-wrap\">\n<table id=\"runs\">\n<thead>");
    expect($html)->toContain("</table>\n</div>\n<p id=\"no-match\" class=\"meta\" hidden>No runs match.</p>\n<script>");
    expect($html)->toContain('.table-wrap { overflow-x:auto; }')->toContain('.controls {')->not->toContain('.filter {');
    expect(proof_render_index([]))->not->toContain('class="controls"')->not->toContain('id="no-match"');
});

it('opens the run page with one link back to the store index, relative, above the title', function () {
    $html = proof_render_run(proof_current_run());
    $link = '<nav class="back" aria-label="Proof store"><a href="../../index.html">← All proofs</a></nav>';

    expect($html)->toContain($link);
    expect(substr_count($html, 'class="back"'))->toBe(1);
    expect(strpos($html, $link))->toBeGreaterThan(strpos($html, '<body'))->toBeLessThan(strpos($html, '<h1>'));
});

it('gives a run filed before schema 2 the link back too', function () {
    $html = proof_render_run(proof_fixture_run(['schema' => 1]));
    $link = '<nav class="back" aria-label="Proof store"><a href="../../index.html">← All proofs</a></nav>';

    expect($html)->toContain($link);
    expect(strpos($html, $link))->toBeLessThan(strpos($html, '<h1>'));
    expect($html)->not->toContain('Pending:');
});

it('gives the store index no link back, since it is the root', function () {
    $html = proof_render_index([
        ['dir' => '/store/ViewieMedia/pr-412-orders-export', 'run' => proof_fixture_run()],
    ]);

    expect($html)->not->toContain('class="back"');
    expect($html)->not->toContain('All proofs');
});

it('resolves the link back to the index of the store the page is filed in', function () {
    $page = proof_test_page();

    expect(proof_store_amend($page, fn (array $run): array => $run))->toBeNull();
    expect(is_file(dirname($page) . '/../../index.html'))->toBeTrue();
    expect(realpath(dirname($page) . '/../../index.html'))->toBe(realpath(dirname($page, 3) . '/index.html'));
    expect(dirname(proof_run_dir('/store', 'Deploy', 'feature/logs', 5), 2))->toBe('/store');
});

it('names the index tab Proofs and gives it a favicon link with the four icons to pick from', function () {
    $html = proof_render_index([proof_index_entry('pr-5-logs', [])]);
    $icons = proof_index_icons();

    expect(array_keys($icons))->toBe(['none', 'halted', 'ready', 'unread']);
    foreach ($icons as $icon) {
        expect($icon)->toStartWith('data:image/svg+xml,')->not->toContain('"');
    }
    expect(rawurldecode($icons['halted']))->toContain("fill='#dc2626'");
    expect(rawurldecode($icons['ready']))->toContain("fill='#16a34a'");
    expect(rawurldecode($icons['unread']))->toContain("fill='#2563eb'");
    expect(rawurldecode($icons['none']))->toContain("fill='none'")->toContain("stroke='#71717a'");
    expect($html)->toContain("<title>Proofs</title>\n")->toContain('<h1>Pipeline proof store <span id="unread-count" class="unread-count"></span></h1>');
    expect($html)->toContain('<link rel="icon" id="favicon" href="' . proof_e($icons['none']) . '" data-none="' . proof_e($icons['none'])
        . '" data-halted="' . proof_e($icons['halted']) . '" data-ready="' . proof_e($icons['ready']) . '" data-unread="' . proof_e($icons['unread']) . "\">\n");
    expect(strpos($html, 'id="favicon"'))->toBeLessThan(strpos($html, '</head>'));
});

it('renders an empty store with the favicon and a script that only polls', function () {
    $html = proof_render_index([]);

    expect($html)->toContain('No runs recorded')->toContain('id="favicon"')->toContain("<title>Proofs</title>")
        ->toContain("<script>\n")->toContain("'status.js?t=' + Date.now()");
    expect($html)->not->toContain('class="controls"')->not->toContain('id="no-match"')->not->toContain('<table');
});

it('carries the poll and the tab signal in the index script', function () {
    $script = proof_render_index_script();

    expect($script)->toContain("'status.js?t=' + Date.now()")
        ->toContain('setInterval(check, 30000)')
        ->toContain("document.addEventListener('visibilitychange'")
        ->toContain("window.addEventListener('focus', check)")
        ->toContain('refresh(); check();')
        ->toContain("document.title = unseen.length ? '(' + unseen.length + ') Proofs' : 'Proofs'")
        ->toContain("getElementById('favicon')")
        ->toContain("has('halted') ? 'halted' : has('ready') ? 'ready' : unseen.length ? 'unread' : 'none'")
        ->toContain('current.dataset.hash === entry.hash')
        ->toContain("createElement('template')")
        ->toContain('function unread(row)')
        ->toContain("proofUnread(Number(row.dataset.seen || 0)")
        ->toContain("row.dataset.finished === '0' && unread(row) !== ''")
        ->toContain("getElementById('finished-count')")
        ->toContain('location.reload()');
});

it('reads a row as unread by the rule, and names what it needs now', function () {
    $calls = [
        'no number' => [0, null, 'halted', ''],
        'never opened' => [3, null, 'running', 'New'],
        'opened at its number' => [3, '3', 'halted', ''],
        'opened above its number' => [3, '4', 'ready', ''],
        'halted since' => [5, '3', 'halted', 'Halted'],
        'ready since' => [5, '3', 'ready', 'Ready'],
        'filed again since' => [5, '3', 'running', 'Updated'],
        'marked unread, running' => [5, '0', 'running', 'Unread'],
        'marked unread, halted' => [5, '0', 'halted', 'Halted'],
        'merged below its number' => [5, '3', 'merged', 'Updated'],
    ];
    $arguments = json_encode(array_values(array_map(fn (array $call): array => array_slice($call, 0, 3), $calls)));
    $script = proof_render_unread_script()
        . "\nconsole.log(JSON.stringify({$arguments}.map(function (call) { return proofUnread(call[0], call[1], call[2]); })));";

    exec('node -e ' . escapeshellarg($script) . ' 2>&1', $output, $code);

    expect($code)->toBe(0, implode("\n", $output));
    expect(array_combine(array_keys($calls), json_decode(implode('', $output), true)))
        ->toBe(array_map(fn (array $call): string => $call[3], $calls));
});

it('puts the dot before the title of a run with a seen number, and none on a run without one', function () {
    $row = proof_render_index_row(proof_index_entry('pr-5-logs', ['revision' => 3]));

    expect($row)->toContain('<td data-sort="PR #412: product summary grid"><button type="button" class="dot"></button><a href="Deploy/pr-5-logs/index.html">PR #412: product summary grid</a><span class="marker"></span></td>');
    expect(proof_render_index_row(proof_index_entry('pr-6-old', [])))->not->toContain('class="dot"');
});

it('counts the unread runs beside the heading of a store with runs, and leaves an empty store\'s heading alone', function () {
    expect(proof_render_index([proof_index_entry('pr-5-logs', ['revision' => 1])]))
        ->toContain('<h1>Pipeline proof store <span id="unread-count" class="unread-count"></span></h1>');
    expect(proof_render_index([]))->toContain("<h1>Pipeline proof store</h1>\n")->not->toContain('id="unread-count"');
});

it('renders the unread rule before the index script, and both parse as JavaScript', function () {
    $html = proof_render_index([proof_index_entry('pr-5-logs', ['revision' => 1])]);
    $file = sys_get_temp_dir() . '/proof-unread-' . uniqid() . '.js';
    file_put_contents($file, proof_render_unread_script() . "\n" . proof_render_index_script());
    exec('node --check ' . escapeshellarg($file) . ' 2>&1', $output, $code);
    unlink($file);

    expect($code)->toBe(0, implode("\n", $output));
    expect(strpos($html, 'function proofUnread(seen, stored, status)'))->toBeGreaterThan(0)
        ->toBeLessThan(strpos($html, 'function unread(row)'));
});

it('carries the inbox wiring in the index script, and styles a row only once the script marked it', function () {
    $script = proof_render_index_script();
    $styles = proof_render_styles();

    expect($script)->toContain("return storage ? proofUnread(Number(row.dataset.seen || 0), storage.getItem('seen:' + row.dataset.run), row.dataset.status) : '';")
        ->toContain("row.classList.toggle('unread', state !== '')")
        ->toContain("row.classList.toggle('read', state === '')")
        ->toContain("var label = state === '' ? 'Mark as unread' : 'Mark as read';")
        ->toContain("dot.setAttribute('aria-label', label)")
        ->toContain("body.addEventListener('click'")
        ->toContain("event.target.closest('button.dot')")
        ->toContain("storage.setItem('seen:' + row.dataset.run, unread(row) === '' ? '0' : row.dataset.seen)")
        ->toContain("getElementById('unread-count')")
        ->toContain("count.textContent = unseen.length ? unseen.length + ' unread' : ''")
        ->toContain("row.dataset.rank = state === '' && row.dataset.seen && row.dataset.group === '1' ? '2' : row.dataset.group")
        ->not->toContain('dataset.revision');
    // Without localStorage `mark()` never runs, so no row is unread or read and every dot stays hidden.
    expect($script)->toContain('if (storage) { rows.forEach(mark); }');
    expect($styles)->toContain('tr.unread td { font-weight:700; }')
        ->toContain('tr.read td { color:var(--muted); }')
        ->toContain('.dot { display:none;')
        ->toContain('tr.unread .dot, tr.read .dot { display:inline-flex;')
        ->toContain('border:1.5px solid var(--muted);')
        ->toContain('tr.unread .dot::before { background:var(--ready); border-color:var(--ready); }')
        ->toContain('.dot:hover::before, .dot:focus-visible::before { border-color:var(--fg); }')
        ->toContain('.dot:focus-visible { outline:')
        ->toContain('.unread-count {');
});

/** Every `<a>` opening tag: one to GitHub opens a new tab, any other (the store's own) stays in this one. */
function proof_expect_github_links_in_a_new_tab(string $html, int $atLeast): void
{
    preg_match_all('/<a [^>]*>/', $html, $tags);

    expect(count($tags[0]))->toBeGreaterThanOrEqual($atLeast);
    expect($tags[0])->each(fn ($tag) => str_starts_with($tag->value, '<a href="https://github.com/')
        ? $tag->toContain(' target="_blank" rel="noopener"')
        : $tag->not->toContain('target='));
}

it('opens every GitHub link on a run page in a new tab, and keeps the link back in this one', function () {
    // PR, Files changed, issue and ← All proofs: an empty match cannot pass.
    proof_expect_github_links_in_a_new_tab(proof_render_run(proof_fixture_run(['issue' => 919])), 4);
});

it('opens the PR link of the index in a new tab, and keeps the run link in this one, in status.js too', function () {
    $entry = ['dir' => '/store/ViewieMedia/pr-412-orders-export', 'run' => proof_fixture_run(['pr' => 412, 'prState' => 'OPEN'])];

    // The PR column and the title link.
    proof_expect_github_links_in_a_new_tab(proof_render_index([$entry]), 2);
    expect(proof_test_status_runs(proof_render_status_js([$entry]))[0]['row'])
        ->toContain('<a href="https://github.com/IT4WEBBV/ViewieMedia/pull/412" target="_blank" rel="noopener">#412 OPEN</a>')
        ->toContain('<a href="ViewieMedia/pr-412-orders-export/index.html">');
    expect(proof_render_index([$entry]))->not->toContain('Files changed');
});

it('links the PR\'s diff right after the PR reference on the run page', function () {
    $html = proof_render_run(proof_fixture_run(['pr' => 967, 'prState' => 'MERGED']));

    expect($html)->toContain(
        '<a href="https://github.com/IT4WEBBV/ViewieMedia/pull/967" target="_blank" rel="noopener">#967 (MERGED)</a>'
        . ' · <a href="https://github.com/IT4WEBBV/ViewieMedia/pull/967/files" target="_blank" rel="noopener">Files changed</a>'
    );
    expect(substr_count($html, 'Files changed'))->toBe(1);
    expect(proof_render_run(proof_fixture_run(['pr' => '967'])))
        ->toContain('<a href="https://github.com/IT4WEBBV/ViewieMedia/pull/967/files" target="_blank" rel="noopener">Files changed</a>');
    expect(proof_render_run(proof_current_run(['pr' => 967])))
        ->toContain('<a href="https://github.com/IT4WEBBV/ViewieMedia/pull/967/files" target="_blank" rel="noopener">Files changed</a>');
});

it('has no diff link for a run without a PR', function () {
    $html = proof_render_run(proof_fixture_run(['pr' => null, 'prState' => null]));

    expect($html)->not->toContain('Files changed')->not->toContain('/files');
    expect($html)->toContain('<code>ViewieMedia</code> · no PR · <code>feature/orders-export</code>');
});

it('has no diff link for a run that names no repo to link into', function (?string $nameWithOwner) {
    $html = proof_render_run(proof_fixture_run(['nameWithOwner' => $nameWithOwner]));

    expect($html)->not->toContain('Files changed');
    expect($html)->toContain('<code>ViewieMedia</code> · #412 (OPEN) · <code>feature/orders-export</code>');
})->with([
    'missing' => [null],
    'blank' => ['   '],
]);
