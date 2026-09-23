<?php

$uiOff = ['package' => false, 'migration' => false, 'auth' => false, 'ui' => false];

it('derives the done gate legs from continued ledger entries', function () {
    $ledger = [
        ['gate' => 'plan-approval', 'leg' => 'review-plan', 'at' => '2026-09-14T10:00:00Z', 'outcome' => 'looped-back'],
        ['gate' => 'plan-approval', 'leg' => 'review-plan', 'at' => '2026-09-14T10:20:00Z', 'outcome' => 'continued'],
        ['gate' => 'verify-ui', 'cycle' => 1, 'at' => '2026-09-14T11:00:00Z', 'outcome' => 'continued'],
    ];
    expect(pipeline_done_legs($ledger))->toBe(['review-plan', 'verify-ui']);
    expect(pipeline_done_legs([]))->toBe([]);
});

it('stops counting gates that passed before a design escalation', function () use ($uiOff) {
    $ledger = [
        ['gate' => 'plan-approval', 'leg' => 'review-plan', 'at' => '2026-09-14T10:00:00Z', 'outcome' => 'continued'],
        ['gate' => 'design-size', 'leg' => 'implement', 'at' => '2026-09-14T10:40:00Z', 'reason' => 'migration', 'outcome' => 'escalated'],
    ];
    expect(pipeline_done_legs($ledger))->toBe([]);
    // the grown plan has not been re-reviewed, so implement is out of reach again
    expect(pipeline_can_navigate('design', 'implement', pipeline_done_legs($ledger), $uiOff))->toBeFalse();

    $reReviewed = [...$ledger, ['gate' => 'plan-approval', 'leg' => 'review-plan', 'at' => '2026-09-14T11:00:00Z', 'outcome' => 'continued']];
    expect(pipeline_done_legs($reReviewed))->toBe(['review-plan']);
    expect(pipeline_can_navigate('review-plan', 'implement', pipeline_done_legs($reReviewed), $uiOff))->toBeTrue();
});

it('stops counting a plan approval once a later leg found the plan insufficient', function () use ($uiOff) {
    $ledger = [
        ['gate' => 'plan-approval', 'leg' => 'review-plan', 'at' => '2026-09-14T10:00:00Z', 'outcome' => 'continued'],
        ['gate' => 'plan-approval', 'leg' => 'implement', 'at' => '2026-09-14T10:40:00Z', 'reason' => 'needs a queue', 'outcome' => 'looped-back'],
    ];
    expect(pipeline_done_legs($ledger))->toBe([]);
    expect(pipeline_can_navigate('design', 'implement', pipeline_done_legs($ledger), $uiOff))->toBeFalse();

    $reReviewed = [...$ledger, ['gate' => 'plan-approval', 'leg' => 'review-plan', 'at' => '2026-09-14T11:00:00Z', 'outcome' => 'continued']];
    expect(pipeline_done_legs($reReviewed))->toBe(['review-plan']);
});
