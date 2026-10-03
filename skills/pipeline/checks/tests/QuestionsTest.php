<?php

function questions_entry(string $gate, array $actions): array
{
    return ['gate' => $gate, 'leg' => pipeline_leg_of_gate($gate), 'cycle' => 1, 'at' => '2026-10-02T10:00:00Z', 'review' => 'r', 'actions' => $actions, 'outcome' => 'continued'];
}

function questions_action(string $claim, ?string $kind, string $note = 'n'): array
{
    return ['claim' => $claim, 'disposition' => 'open-question', 'note' => $note, ...($kind === null ? [] : ['kind' => $kind])];
}

function questions_manifest(array $ledger, array $decisions = []): array
{
    return ['branch' => 'feature/x', 'mode' => 'autoflow', 'gate_ledger' => $ledger, 'decisions' => $decisions];
}

it('names the kinds for a refusal and labels each for the page', function () {
    expect(array_column(QuestionKind::cases(), 'value'))->toBe(['blocking', 'follow-up', 'remark']);
    expect(QuestionKind::listed())->toBe('blocking, follow-up, remark');
    expect(array_map(fn (QuestionKind $kind) => $kind->label(), QuestionKind::cases()))->toBe(['Blocking', 'Follow-up', 'Remark']);
});

it('lists every open question across plan-approval and pr-review entries with its ledger id, a kindless one as blocking', function () {
    $integrated = ['claim' => 'step 4 drops the link', 'disposition' => 'integrated', 'note' => 'restored it'];
    $manifest = questions_manifest([
        questions_entry('plan-approval', [$integrated, questions_action('Queue or cron?', null, 'cron (built), queue')]),
        ['gate' => 'verify-ui', 'cycle' => 1, 'at' => '2026-10-02T11:00:00Z', 'outcome' => 'continued'],
        questions_entry('pr-review', [questions_action('Rename the column?', 'follow-up')]),
        ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 2, 'at' => '2026-10-02T12:00:00Z', 'review' => 'r'],
    ]);

    expect(pipeline_open_questions($manifest))->toBe([
        ['id' => 'gate_ledger[0].actions[1]', 'gate' => 'plan-approval', 'kind' => 'blocking', 'question' => 'Queue or cron?', 'note' => 'cron (built), queue'],
        ['id' => 'gate_ledger[2].actions[0]', 'gate' => 'pr-review', 'kind' => 'follow-up', 'question' => 'Rename the column?', 'note' => 'n'],
    ]);
    expect(pipeline_open_questions(['branch' => 'feature/x']))->toBe([]);
});

it('keeps a blocking question open until a decision starts with its answer prefix, so actions[20] answers nothing of actions[2]', function () {
    $actions = array_map(fn (int $n) => questions_action("Q{$n}", 'blocking'), range(0, 20));
    $manifest = questions_manifest([questions_entry('pr-review', $actions)], ['Keep the guard', 'Answer to open question gate_ledger[0].actions[20] ("Q20"): keep it']);

    $open = pipeline_unanswered($manifest);

    expect(array_column($open, 'id'))->toHaveCount(20)->toContain('gate_ledger[0].actions[2]')->not->toContain('gate_ledger[0].actions[20]');
    expect($open[2])->toBe([
        'id' => 'gate_ledger[0].actions[2]', 'gate' => 'pr-review', 'kind' => 'blocking', 'question' => 'Q2', 'note' => 'n',
        'decision' => 'Answer to open question gate_ledger[0].actions[2] ("Q2"): ',
    ]);
});

it('asks only blocking questions, and builds a decision that answers its own question whatever the text holds', function () {
    $question = "Keep the \"<x-time>\" tag,\nor a plain div?";
    $manifest = questions_manifest([questions_entry('pr-review', [
        questions_action($question, 'blocking', '<x-time> (built), a plain div'),
        questions_action('File the cleanup?', 'follow-up'),
        questions_action('self-end alignment', 'remark'),
        questions_action('Old-style question', null),
    ])]);

    $open = pipeline_unanswered($manifest);

    expect(array_column($open, 'id'))->toBe(['gate_ledger[0].actions[0]', 'gate_ledger[0].actions[3]']);
    expect($open[0]['decision'])->toBe("Answer to open question gate_ledger[0].actions[0] (\"{$question}\"): ");
    expect(pipeline_unanswered([...$manifest, 'decisions' => [$open[0]['decision'] . 'a plain div', $open[1]['decision'] . 'yes']]))->toBe([]);
});

it('lists a follow-up once across two entries, with the first one\'s note, and never a remark or a blocking question', function () {
    $manifest = questions_manifest([
        questions_entry('plan-approval', [questions_action('File the cleanup?', 'follow-up', 'first'), questions_action('A remark', 'remark')]),
        questions_entry('pr-review', [questions_action('File the cleanup?', 'follow-up', 'second'), questions_action('Fork?', 'blocking')]),
    ]);

    expect(pipeline_follow_ups($manifest))->toBe([['question' => 'File the cleanup?', 'note' => 'first']]);
    expect(pipeline_follow_ups(questions_manifest([])))->toBe([]);
});
