<?php

$noUi = ['package' => false, 'migration' => false, 'auth' => false, 'ui' => false];
$ui = [...$noUi, 'ui' => true];
$open = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'Step 4 drops the link.'];

function returned_before(string $leg, array $ledger = [], array $cursor = []): array
{
    return [
        'branch' => 'feature/x', 'worktree' => '/tmp/wt', 'mode' => 'auto',
        'cursor' => ['leg' => $leg, 'status' => 'pending', ...$cursor],
        'artifacts' => ['spec' => 'docs/spec.md', 'plan' => 'docs/plan.md', 'pr' => null, 'issue' => null],
        'last_sha' => 'aaa1111', 'gate_ledger' => $ledger,
    ];
}

/** The manifest as a leg returns it: a status, the ledger it left, anything else it changed. */
function returned_after(array $before, string $status, ?array $ledger = null, array $changes = [], ?string $reason = null): array
{
    $cursor = [...$before['cursor'], 'status' => $status];
    if ($reason !== null) {
        $cursor['reason'] = $reason;
    }

    return [...$before, 'cursor' => $cursor, 'gate_ledger' => $ledger ?? $before['gate_ledger'], ...$changes];
}

it('sends a review step on to its resolve step', function () use ($noUi, $open) {
    $before = returned_before('review-plan');

    expect(pipeline_returned($before, returned_after($before, 'continued', [$open]), $noUi, DesignSize::Architectural))
        ->toBe(['action' => 'dispatch', 'leg' => 'review-plan']);
});

it('moves to the next leg once a resolve step continues, and finishes after review-pr', function () use ($noUi, $open) {
    $before = returned_before('review-plan', [$open]);
    $resolved = [[...$open, 'actions' => [], 'outcome' => 'continued']];
    expect(pipeline_returned($before, returned_after($before, 'continued', $resolved), $noUi, DesignSize::Architectural))
        ->toBe(['action' => 'dispatch', 'leg' => 'handoff']);

    $prOpen = [...$open, 'gate' => 'pr-review', 'leg' => 'review-pr'];
    $before = returned_before('review-pr', [$prOpen]);
    expect(pipeline_returned($before, returned_after($before, 'continued', [[...$prOpen, 'outcome' => 'continued']]), $noUi, DesignSize::Architectural))
        ->toBe(['action' => 'done']);
});

it('steps over verify-ui unless ui fired', function () use ($noUi, $ui) {
    $before = returned_before('implement');
    $after = returned_after($before, 'continued', null, ['last_sha' => 'bbb2222']);

    expect(pipeline_returned($before, $after, $noUi, DesignSize::Architectural))->toBe(['action' => 'dispatch', 'leg' => 'review-pr']);
    expect(pipeline_returned($before, $after, $ui, DesignSize::Architectural))->toBe(['action' => 'dispatch', 'leg' => 'verify-ui']);
});

it('loops back twice and halts on the third', function () use ($noUi, $open) {
    $looped = [...$open, 'outcome' => 'looped-back'];

    $before = returned_before('review-plan', [$looped, $open]);
    expect(pipeline_returned($before, returned_after($before, 'looped-back', [$looped, $looped]), $noUi, DesignSize::Architectural))
        ->toBe(['action' => 'dispatch', 'leg' => 'design']);

    $before = returned_before('review-plan', [$looped, $looped, $open]);
    $decision = pipeline_returned($before, returned_after($before, 'looped-back', [$looped, $looped, $looped]), $noUi, DesignSize::Architectural);
    expect($decision['action'])->toBe('halt');
    expect($decision['reason'])->toContain('bound exhausted');
});

it('permits no loop-back once the count is unknown', function () use ($noUi, $open) {
    $unknown = [...$open, 'cycle' => 'unknown'];
    $before = returned_before('review-plan', [$unknown]);
    $decision = pipeline_returned($before, returned_after($before, 'looped-back', [[...$unknown, 'outcome' => 'looped-back']]), $noUi, DesignSize::Architectural);

    expect($decision['action'])->toBe('halt');
    expect($decision['reason'])->toContain('unknown');
});

it('loops verify-ui back to implement on its own thin entry', function () use ($ui) {
    $before = returned_before('verify-ui');
    $entry = ['gate' => 'verify-ui', 'cycle' => 1, 'at' => '2026-09-22T11:00:00Z', 'outcome' => 'looped-back'];

    expect(pipeline_returned($before, returned_after($before, 'looped-back', [$entry]), $ui, DesignSize::Architectural))
        ->toBe(['action' => 'dispatch', 'leg' => 'implement']);
    expect(pipeline_returned($before, returned_after($before, 'continued'), $ui, DesignSize::Architectural)['action'])->toBe('halt');
});

it('grows a Bounded design on plan-insufficient', function () use ($noUi) {
    $before = returned_before('implement');
    $escalated = ['gate' => 'design-size', 'leg' => 'implement', 'at' => '2026-09-22T12:00:00Z', 'reason' => 'migration', 'outcome' => 'escalated'];

    expect(pipeline_returned($before, returned_after($before, 'plan-insufficient', [$escalated], [], 'migration'), $noUi, DesignSize::Bounded))
        ->toBe(['action' => 'dispatch', 'leg' => 'design']);

    $withoutEntry = returned_after($before, 'plan-insufficient', null, [], 'migration');
    expect(pipeline_returned($before, $withoutEntry, $noUi, DesignSize::Bounded)['reason'])->toContain('design-size');
});

it('loops an Architectural plan-insufficient back to design within the plan-approval bound', function () use ($noUi, $open) {
    $gap = ['gate' => 'plan-approval', 'leg' => 'implement', 'cycle' => 2, 'at' => '2026-09-22T12:00:00Z', 'reason' => 'needs a queue', 'outcome' => 'looped-back'];
    $passed = [...$open, 'outcome' => 'continued'];

    $before = returned_before('implement', [$passed]);
    expect(pipeline_returned($before, returned_after($before, 'plan-insufficient', [$passed, $gap], [], 'needs a queue'), $noUi, DesignSize::Architectural))
        ->toBe(['action' => 'dispatch', 'leg' => 'design']);

    $withoutEntry = pipeline_returned($before, returned_after($before, 'plan-insufficient', null, [], 'needs a queue'), $noUi, DesignSize::Architectural);
    expect($withoutEntry['action'])->toBe('halt');
    expect($withoutEntry['reason'])->toContain('plan-approval entry with outcome looped-back');

    $looped = [...$open, 'outcome' => 'looped-back'];
    $before = returned_before('implement', [$looped, $looped, $passed]);
    $exhausted = pipeline_returned($before, returned_after($before, 'plan-insufficient', [$looped, $looped, $passed, $gap], [], 'needs a queue'), $noUi, DesignSize::Architectural);
    expect($exhausted['action'])->toBe('halt');
    expect($exhausted['reason'])->toContain('bound exhausted');
});

it('halts with the leg\'s own reason, and refuses a halt without one', function () use ($noUi) {
    $before = returned_before('implement');

    expect(pipeline_returned($before, returned_after($before, 'halted', null, [], 'the stack will not start'), $noUi, DesignSize::Architectural))
        ->toBe(['action' => 'halt', 'reason' => 'the stack will not start']);
    expect(pipeline_returned($before, returned_after($before, 'halted'), $noUi, DesignSize::Architectural)['reason'])
        ->toContain('without a reason');
});

it('retries an empty review once, then halts; any other empty return halts at once', function () use ($noUi) {
    $before = returned_before('review-plan');
    expect(pipeline_returned($before, $before, $noUi, DesignSize::Architectural))->toBe(['action' => 'retry']);

    $retried = returned_before('review-plan', [], ['retried' => true]);
    expect(pipeline_returned($retried, $retried, $noUi, DesignSize::Architectural)['action'])->toBe('halt');

    $implement = returned_before('implement');
    expect(pipeline_returned($implement, $implement, $noUi, DesignSize::Architectural)['action'])->toBe('halt');
});

it('halts on every return it cannot account for', function (array $after, string $reason) use ($noUi) {
    $decision = pipeline_returned(returned_before('review-plan', [['gate' => 'plan-approval', 'review' => 'old', 'outcome' => 'continued']]), $after, $noUi, DesignSize::Architectural);

    expect($decision['action'])->toBe('halt');
    expect($decision['reason'])->toContain($reason);
})->with(function () {
    $old = ['gate' => 'plan-approval', 'review' => 'old', 'outcome' => 'continued'];
    $before = returned_before('review-plan', [$old]);
    $open = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 2, 'at' => '2026-09-22T13:00:00Z', 'review' => 'new'];

    return [
        'a moved cursor' => [returned_after($before, 'continued', [$old, $open], ['cursor' => ['leg' => 'handoff', 'status' => 'continued']]), 'cursor.leg'],
        'a dispatcher key' => [returned_after($before, 'continued', [$old, $open], ['mode' => 'interactive']), 'mode'],
        'a new top-level key' => [returned_after($before, 'continued', [$old, $open], ['notes' => 'x']), 'notes'],
        'a rewritten entry' => [returned_after($before, 'continued', [[...$old, 'review' => 'edited'], $open]), 'ledger entry 0'],
        'an unknown status' => [returned_after($before, 'done', [$old, $open]), 'not a leg status'],
        'a status the step cannot return' => [returned_after($before, 'looped-back', [$old, $open]), 'cannot return looped-back'],
        'a lost required key' => [array_diff_key(returned_after($before, 'continued', [$old, $open]), ['worktree' => true]), 'worktree'],
        'a review without its entry' => [returned_after($before, 'continued', [$old]), 'one open plan-approval entry'],
    ];
});

it('tolerates a leg that reorders keys or drops cursor.retried', function () use ($noUi, $open) {
    $before = returned_before('review-plan', [], ['retried' => true]);
    $after = returned_after($before, 'continued', [array_reverse($open, true)]);
    $after = array_reverse([...$after, 'cursor' => ['status' => 'continued', 'leg' => 'review-plan']], true);

    expect(pipeline_returned($before, $after, $noUi, DesignSize::Architectural))
        ->toBe(['action' => 'dispatch', 'leg' => 'review-plan']);
});

it('halts when a resolve step rewrites the annotations the review step recorded', function () use ($noUi, $open) {
    $annotated = [...$open, 'annotations' => ['migration']];
    $before = returned_before('review-plan', [$annotated]);
    $decision = pipeline_returned($before, returned_after($before, 'continued', [[...$annotated, 'annotations' => [], 'outcome' => 'continued']]), $noUi, DesignSize::Architectural);

    expect($decision['action'])->toBe('halt');
    expect($decision['reason'])->toContain('ledger entry 0');
});

it('halts when a resolve step sets an outcome other than its status', function () use ($noUi, $open) {
    $before = returned_before('review-plan', [$open]);
    $decision = pipeline_returned($before, returned_after($before, 'continued', [[...$open, 'outcome' => 'looped-back']]), $noUi, DesignSize::Architectural);

    expect($decision['reason'])->toContain('outcome to continued');
});

it('refuses plan-insufficient from a resolve step: a plan gap found there is a loop-back', function () use ($noUi, $open) {
    $before = returned_before('review-plan', [$open]);
    $gap = ['gate' => 'plan-approval', 'leg' => 'review-plan', 'cycle' => 2, 'at' => '2026-09-22T12:00:00Z', 'reason' => 'needs a queue', 'outcome' => 'looped-back'];
    $decision = pipeline_returned($before, returned_after($before, 'plan-insufficient', [[...$open, 'outcome' => 'looped-back'], $gap], [], 'needs a queue'), $noUi, DesignSize::Architectural);

    expect($decision['action'])->toBe('halt');
    expect($decision['reason'])->toContain('cannot return plan-insufficient');
});

it('refuses a review step that returns plan-insufficient and leaves an open review behind', function () use ($noUi, $open) {
    $before = returned_before('review-plan');
    $escalated = ['gate' => 'design-size', 'leg' => 'review-plan', 'at' => '2026-09-22T12:00:00Z', 'reason' => 'migration', 'outcome' => 'escalated'];

    $decision = pipeline_returned($before, returned_after($before, 'plan-insufficient', [$open, $escalated], [], 'migration'), $noUi, DesignSize::Bounded);
    expect($decision['action'])->toBe('halt');
    expect($decision['reason'])->toContain('may add no open plan-approval entry');

    expect(pipeline_returned($before, returned_after($before, 'plan-insufficient', [$escalated], [], 'migration'), $noUi, DesignSize::Bounded))
        ->toBe(['action' => 'dispatch', 'leg' => 'design']);
});

it('passes a return that agrees with what the step reported to the script', function (string $leg, array $changes, array $reported, ?bool $diffUi) {
    $before = returned_before($leg);

    expect(pipeline_reported_problem($before, returned_after($before, 'continued', null, $changes), $reported, DesignSize::Architectural, $diffUi))->toBeNull();
})->with([
    'handoff' => ['handoff', ['artifacts' => ['spec' => 'docs/spec.md', 'plan' => 'docs/plan.md', 'pr' => 7, 'issue' => null]], ['status' => 'continued'], null],
    'implement on a UI diff' => ['implement', ['last_sha' => 'bbb2222'], ['status' => 'continued', 'ui' => 'true'], true],
    'design' => ['design', ['last_sha' => 'bbb2222'], ['status' => 'continued', 'size' => 'Architectural'], null],
]);

it('names what a step reported that its manifest does not say', function (string $leg, bool $writes, array $reported, ?bool $diffUi, string $problem) {
    $before = returned_before($leg);
    $after = $writes ? returned_after($before, 'continued', null, ['last_sha' => 'bbb2222']) : $before;

    expect(pipeline_reported_problem($before, $after, $reported, DesignSize::Architectural, $diffUi))->toBe($problem);
})->with([
    'nothing written' => ['handoff', false, ['status' => 'continued'], null, 'the handoff run step returned without writing the manifest'],
    'another status' => ['handoff', true, ['status' => 'halted'], null, 'the handoff run step returned halted to the script but wrote continued into the manifest'],
    'no status' => ['handoff', true, [], null, 'the handoff run step returned nothing to the script but wrote continued into the manifest'],
    'ui false on a UI diff' => ['implement', true, ['status' => 'continued', 'ui' => 'false'], true, 'the implement step returned ui: false, but its diff says true'],
    'another size' => ['design', true, ['status' => 'continued', 'size' => 'Bounded'], null, 'the design step returned size Bounded, but the spec says Architectural'],
]);

it('reports a manifest problem before anything the step reported', function () {
    $before = returned_before('handoff');

    expect(pipeline_reported_problem($before, returned_after($before, 'continued', null, ['mode' => 'auto', 'branch' => 'other']), ['status' => 'halted'], DesignSize::Architectural))
        ->toBe('the leg changed branch, which only the dispatcher writes');
});

it('passes the returns that route elsewhere than on, when the ledger bears them out', function (string $leg, array $ledger, string $status, array $newLedger, array $reported, DesignSize $size) {
    $before = returned_before($leg, $ledger);

    expect(pipeline_reported_problem($before, returned_after($before, $status, $newLedger, [], $status === 'plan-insufficient' ? 'gap' : null), $reported, $size, false))->toBeNull();
})->with([
    'an Architectural plan gap from implement' => ['implement', [], 'plan-insufficient', [['gate' => 'plan-approval', 'leg' => 'implement', 'cycle' => 2, 'at' => '2026-09-25T11:00:00Z', 'reason' => 'gap', 'outcome' => 'looped-back']], ['status' => 'plan-insufficient', 'ui' => 'false'], DesignSize::Architectural],
    'a Bounded escalation from a review step' => ['review-plan', [], 'plan-insufficient', [['gate' => 'design-size', 'leg' => 'review-plan', 'at' => '2026-09-25T11:00:00Z', 'reason' => 'gap', 'outcome' => 'escalated']], ['status' => 'plan-insufficient'], DesignSize::Bounded],
    'a review-pr loop-back' => ['review-pr', [['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => '2026-09-25T10:00:00Z', 'review' => 'r']], 'looped-back', [['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => '2026-09-25T10:00:00Z', 'review' => 'r', 'actions' => ['rework'], 'outcome' => 'looped-back']], ['status' => 'looped-back'], DesignSize::Architectural],
    'a verify-ui loop-back' => ['verify-ui', [], 'looped-back', [['gate' => 'verify-ui', 'leg' => 'verify-ui', 'at' => '2026-09-25T10:00:00Z', 'outcome' => 'looped-back']], ['status' => 'looped-back'], DesignSize::Architectural],
]);
