<?php

/**
 * Open questions (`../references/engine.md` §Open questions): the kind a resolve step gives each, which ones are
 * still open, and how an owner's answer is recorded. Pure: it reads the manifest array and requires no other
 * check file. `pipeline_open_questions()` reads `ActionDisposition` from `record.php`, which requires this file;
 * the proof path (`proof.php`) loads this file without `record.php` and uses `QuestionKind` only.
 */

enum QuestionKind: string
{
    case Blocking = 'blocking';
    case FollowUp = 'follow-up';
    case Remark = 'remark';

    /** `blocking, follow-up, remark`: the kinds as a refusal names them. */
    public static function listed(): string
    {
        return implode(', ', array_column(self::cases(), 'value'));
    }

    public function label(): string
    {
        return match ($this) {
            self::Blocking => 'Blocking',
            self::FollowUp => 'Follow-up',
            self::Remark => 'Remark',
        };
    }
}

/** How an owner's answer starts in `decisions`, followed by the id of the action that asked it. */
const PIPELINE_ANSWER = 'Answer to open question ';

/**
 * Every `open-question` action on every ledger entry, in ledger order. `id` is its place in the ledger, stable for
 * the run's life: entries are append-only and a completed entry's actions are never rewritten. An action written
 * before kinds existed reads as `blocking`.
 *
 * @return list<array{id: string, gate: string, kind: string, question: string, note: string}>
 */
function pipeline_open_questions(array $manifest): array
{
    $questions = [];
    foreach ($manifest['gate_ledger'] ?? [] as $i => $entry) {
        foreach ($entry['actions'] ?? [] as $j => $action) {
            if ($action['disposition'] !== ActionDisposition::OpenQuestion->value) {
                continue;
            }
            $questions[] = [
                'id' => "gate_ledger[{$i}].actions[{$j}]",
                'gate' => $entry['gate'],
                'kind' => $action['kind'] ?? QuestionKind::Blocking->value,
                'question' => $action['claim'],
                'note' => $action['note'],
            ];
        }
    }

    return $questions;
}

/**
 * The `blocking` questions no decision answers, each with `decision`: the text the session completes with the
 * owner's answer and records through `launch --decision`. The id ends in `]`, so `actions[2]` never matches
 * `actions[20]`, and the quoted question after it never decides whether a question is answered.
 *
 * @return list<array{id: string, gate: string, kind: string, question: string, note: string, decision: string}>
 */
function pipeline_unanswered(array $manifest): array
{
    $decisions = $manifest['decisions'] ?? [];
    $answered = fn (array $question) => array_filter($decisions, fn (string $decision) => str_starts_with($decision, PIPELINE_ANSWER . $question['id'])) !== [];
    $open = array_filter(
        pipeline_open_questions($manifest),
        fn (array $question) => $question['kind'] === QuestionKind::Blocking->value && ! $answered($question),
    );

    return array_values(array_map(
        fn (array $question) => [...$question, 'decision' => PIPELINE_ANSWER . "{$question['id']} (\"{$question['question']}\"): "],
        $open,
    ));
}

/**
 * The `follow-up` questions, each text once, with the first one's note: a fix round's resolve step may carry one again.
 *
 * @return list<array{question: string, note: string}>
 */
function pipeline_follow_ups(array $manifest): array
{
    $followUps = [];
    foreach (pipeline_open_questions($manifest) as $question) {
        if ($question['kind'] === QuestionKind::FollowUp->value) {
            $followUps[$question['question']] ??= ['question' => $question['question'], 'note' => $question['note']];
        }
    }

    return array_values($followUps);
}
