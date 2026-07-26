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

it('resolves interactive to stop at every gate and auto to adjudicate', function () {
    $i = pipeline_resolve_policy('interactive');
    expect($i['auto_continue'])->toBeFalse()
        ->and($i['gates']['plan-approval'])->toBe('stop')
        ->and($i['gates']['pr-review'])->toBe('stop');

    // Was: auto → 'stop' at both gates. Auto now resolves its own findings and escalates only
    // what an independent check confirms — see references/gates.md §modes.
    $a = pipeline_resolve_policy('auto');
    expect($a['auto_continue'])->toBeTrue()
        ->and($a['gates']['plan-approval'])->toBe('adjudicate')
        ->and($a['gates']['pr-review'])->toBe('adjudicate');

    // An unrecognised mode falls back to the stricter policy.
    $u = pipeline_resolve_policy('nonsense');
    expect($u['auto_continue'])->toBeFalse()
        ->and($u['gates']['plan-approval'])->toBe('stop')
        ->and($u['gates']['pr-review'])->toBe('stop');
});

it('keeps the un-skippable-review promise out of reach of the gate policy', function () use ($uiOn, $uiOff) {
    // The promise rides on pipeline_can_navigate, which reads *which legs ran* — never a human
    // verdict, never the gate policy. That is why relaxing auto's gates to 'adjudicate' cannot
    // open a path around a review. Pin the signature so no policy argument sneaks in later.
    $params = array_map(
        fn (ReflectionParameter $p) => $p->getName(),
        (new ReflectionFunction('pipeline_can_navigate'))->getParameters()
    );
    expect($params)->toBe(['from', 'to', 'doneLegs', 'triggers']);

    // ...and the refusals themselves are unchanged by this plan.
    expect(pipeline_can_navigate('design', 'handoff', ['design'], $uiOff))->toBeFalse();
    expect(pipeline_can_navigate('design', 'implement', ['design'], $uiOff))->toBeFalse();
    expect(pipeline_can_navigate('handoff', 'review-pr', ['design', 'review-plan', 'handoff'], $uiOn))->toBeFalse();
    expect(pipeline_can_navigate('review-plan', 'implement', ['design', 'review-plan'], $uiOff))->toBeTrue();
    expect(pipeline_can_navigate('review-pr', 'design', [], $uiOn))->toBeTrue();
});

it('escalates only a finding that is blocking *and* confirmed', function () {
    $tier1 = ['tier' => 1, 'claim' => 'the plan drops the closing-issue link'];
    $tier2 = ['tier' => 2, 'claim' => 'wording nit in step 4'];
    $arch  = ['kind' => 'architecture', 'claim' => 'this belongs in it4web/tallui, not the project'];

    // Tier-2s never reach adjudication; 'none' is their disposition and they continue.
    expect(pipeline_should_escalate($tier2, 'none'))->toBeFalse();

    // Tier-1 goes to adjudication, and only a confirmation stops the run.
    expect(pipeline_should_escalate($tier1, 'refuted'))->toBeFalse();
    expect(pipeline_should_escalate($tier1, 'uncertain'))->toBeFalse();
    expect(pipeline_should_escalate($tier1, 'confirmed'))->toBeTrue();

    // An architecture judgment escalates on the same terms, carrying no tier of its own.
    expect(pipeline_should_escalate($arch, 'refuted'))->toBeFalse();
    expect(pipeline_should_escalate($arch, 'uncertain'))->toBeFalse();
    expect(pipeline_should_escalate($arch, 'confirmed'))->toBeTrue();

    // Total over the triage: a Tier-2 that somehow arrives confirmed is still not blocking,
    // and a shapeless finding defaults to advisory rather than to an interrupt.
    expect(pipeline_should_escalate($tier2, 'confirmed'))->toBeFalse();
    expect(pipeline_should_escalate(['claim' => 'no tier, no kind'], 'confirmed'))->toBeFalse();
});
