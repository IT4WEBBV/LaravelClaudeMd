<?php

function proof_fixture_run(array $overrides = []): array
{
    return array_merge([
        'repo' => 'ViewieMedia',
        'nameWithOwner' => 'IT4WEBBV/ViewieMedia',
        'branch' => 'feature/orders-export',
        'pr' => 412,
        'prState' => 'OPEN',
        'mode' => 'auto',
        'updatedAt' => '2026-08-25T15:30:00+02:00',
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
        'headline' => '<script>alert(1)</script>',
        'problem' => 'a & b < c',
    ]));

    expect($html)->not->toContain('<script>alert(1)</script>');
    expect($html)->toContain('&lt;script&gt;');
    expect($html)->toContain('a &amp; b &lt; c');
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
    expect($html)->not->toContain('<script');
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

    expect($html)->toContain('class="badge" style="top:85%;left:4%">5<');
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

it('shows the PR number and state for a run that has one', function () {
    $html = proof_render_index([
        ['dir' => '/store/ViewieMedia/feature-b', 'run' => proof_fixture_run(['pr' => 412, 'prState' => 'MERGED'])],
    ]);

    expect($html)->toContain('412');
    expect($html)->toContain('MERGED');
    expect($html)->not->toContain('prune manually');
});
