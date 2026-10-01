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

    expect($html)->toContain('<a href="https://github.com/IT4WEBBV/ViewieMedia/pull/967">#967 (MERGED)</a>');
});

it('links the issue the run is for, from the payload field', function () {
    $html = proof_render_run(proof_fixture_run(['issue' => 919]));

    expect($html)->toContain('<a href="https://github.com/IT4WEBBV/ViewieMedia/issues/919">issue #919</a>');
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

it('flags runs that opened no PR, because pruning can never reach them', function () {
    $html = proof_render_index([
        ['dir' => '/store/Deploy/feature-halted', 'run' => proof_fixture_run(['repo' => 'Deploy', 'pr' => null, 'prState' => null])],
    ]);

    expect($html)->toContain('no PR — prune manually');
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
    expect(proof_render_index([]))->not->toContain('<script');
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
