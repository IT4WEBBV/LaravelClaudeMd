<?php

function proof_fixture_run(array $overrides = []): array
{
    return array_merge([
        'repo' => 'ViewieMedia',
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
    // A page that reaches the network is not self-contained: it must open over file://
    expect($html)->not->toContain('http://');
    expect($html)->not->toContain('https://');
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
