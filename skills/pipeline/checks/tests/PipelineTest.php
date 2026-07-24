<?php

$uiOn  = ['package' => false, 'migration' => false, 'auth' => false, 'ui' => true];
$uiOff = ['package' => false, 'migration' => false, 'auth' => false, 'ui' => false];

it('orders the chain and skips verify-ui when the UI is untouched', function () use ($uiOn, $uiOff) {
    expect(pipeline_next_leg('design', $uiOff))->toBe('review-plan');
    expect(pipeline_next_leg('implement', $uiOn))->toBe('verify-ui');
    expect(pipeline_next_leg('implement', $uiOff))->toBe('review-pr');
    expect(pipeline_next_leg('review-pr', $uiOff))->toBeNull();
});

it('refuses forward navigation past an un-run gate but allows backward', function () use ($uiOn, $uiOff) {
    // design → implement with review-plan not done: refused
    expect(pipeline_can_navigate('design', 'implement', ['design'], $uiOff))->toBeFalse();
    // once review-plan is done: allowed
    expect(pipeline_can_navigate('review-plan', 'implement', ['design', 'review-plan'], $uiOff))->toBeTrue();
    // backward is always allowed
    expect(pipeline_can_navigate('implement', 'design', ['design', 'review-plan'], $uiOff))->toBeTrue();
    // to review-pr while a triggered verify-ui hasn't run: refused
    expect(pipeline_can_navigate('implement', 'review-pr', ['design', 'review-plan', 'handoff', 'implement'], $uiOn))->toBeFalse();
    // same set, but UI untouched (verify-ui not a gate): allowed
    expect(pipeline_can_navigate('implement', 'review-pr', ['design', 'review-plan', 'handoff', 'implement'], $uiOff))->toBeTrue();
});

it('resolves interactive to stop-every-boundary and auto to park-at-gates', function () {
    $i = pipeline_resolve_policy('interactive');
    expect($i['auto_continue'])->toBeFalse();

    $a = pipeline_resolve_policy('auto');
    expect($a['auto_continue'])->toBeTrue()
        ->and($a['gates']['plan-approval'])->toBe('stop')
        ->and($a['gates']['pr-review'])->toBe('stop');
});
