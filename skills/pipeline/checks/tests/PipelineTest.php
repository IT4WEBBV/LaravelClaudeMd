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

it('makes the un-skippable-review promise depend only on which legs have run', function () use ($uiOn, $uiOff) {
    // The promise rides on pipeline_can_navigate, which reads *which legs ran* — never a human
    // decision, never a review outcome, never the mode. That is why the gate collapse cannot open
    // a path around a review. Pin the signature so no such argument sneaks in later.
    $params = array_map(
        fn (ReflectionParameter $p) => $p->getName(),
        (new ReflectionFunction('pipeline_can_navigate'))->getParameters()
    );
    expect($params)->toBe(['from', 'to', 'doneLegs', 'triggers']);

    // ...and the refusals themselves are unchanged by the gate collapse.
    expect(pipeline_can_navigate('design', 'handoff', ['design'], $uiOff))->toBeFalse();
    expect(pipeline_can_navigate('design', 'implement', ['design'], $uiOff))->toBeFalse();
    expect(pipeline_can_navigate('handoff', 'review-pr', ['design', 'review-plan', 'handoff'], $uiOn))->toBeFalse();
    expect(pipeline_can_navigate('review-plan', 'implement', ['design', 'review-plan'], $uiOff))->toBeTrue();
    expect(pipeline_can_navigate('review-pr', 'design', [], $uiOn))->toBeTrue();
});
