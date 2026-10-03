<?php

/** The `## Board` keys, required together (`../references/session.md` §The repo config). */
const PIPELINE_BOARD_KEYS = ['org', 'number', 'project-id', 'status-field-id', 'in-progress-option-id'];

/** The `## Board` keys a section may add; `component-alts` and `docs` are read by nothing, accepted so a shared config parses. */
const PIPELINE_BOARD_OPTIONAL_KEYS = ['component-field-id', 'component-default', 'component-alts', 'docs'];

/**
 * Parse the `## Board` block out of a repo's `.claude/work-on.config.md`.
 *
 * Same tri-state contract as `pipeline_repo_checks()` (`checks.php`), and for the same
 * reason (`../references/session.md` §The work item): a board move that silently does not
 * happen looks exactly like a repo that has no board. `absent` therefore means "this repo
 * deliberately has no board", and a malformed section is `invalid` — never `absent`.
 *
 * The section is **all-or-nothing**: the five `PIPELINE_BOARD_KEYS` are required together
 * (`../references/session.md` §The repo config), so a half-filled section cannot half-run a
 * status move.
 *
 * A value that is still a template placeholder (`<org-login>`) counts as *not filled in*.
 * A section that is nothing but placeholders is the untouched scaffold `work-on` copies
 * into a fresh repo, so it reads `absent`, not `invalid`.
 *
 * @return array{state: 'absent'|'valid'|'invalid', board: array<string, string>, error: ?string}
 */
function pipeline_repo_board(string $configMarkdown): array
{
    // Keys that exist nowhere else in the config. `number`, `org` and `docs` are excluded
    // on purpose: `docs` also lives under Worktree, so it cannot witness a typo'd heading.
    $boardOnly = ['project-id', 'status-field-id', 'in-progress-option-id', 'component-field-id'];

    $keyLineRe = '/^\s*-\s*([A-Za-z][A-Za-z0-9_-]*)\s*:\s*(.*)$/';

    $lines = preg_split('/\R/', $configMarkdown);

    $start = null;
    foreach ($lines as $i => $line) {
        if (preg_match('/^##\s+Board\s*$/', $line)) {
            $start = $i;
            break;
        }
    }

    // No section. A board-only key elsewhere means the heading is malformed —
    // that is `invalid`, not `absent`.
    if ($start === null) {
        foreach ($lines as $line) {
            if (preg_match($keyLineRe, $line, $m) && in_array(strtolower($m[1]), $boardOnly, true)) {
                return [
                    'state' => 'invalid',
                    'board' => [],
                    'error' => "board key '{$m[1]}' found outside a '## Board' section",
                ];
            }
        }

        return ['state' => 'absent', 'board' => [], 'error' => null];
    }

    $values = [];
    for ($i = $start + 1, $n = count($lines); $i < $n; $i++) {
        $line = $lines[$i];

        if (preg_match('/^##\s/', $line)) {
            break;
        }
        if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
            continue;
        }
        if (! preg_match($keyLineRe, $line, $m)) {
            continue;
        }

        $key = $m[1];
        $value = trim(preg_replace('/\s+#.*$/', '', $m[2]));

        if (! in_array($key, PIPELINE_BOARD_KEYS, true) && ! in_array($key, PIPELINE_BOARD_OPTIONAL_KEYS, true)) {
            return ['state' => 'invalid', 'board' => [], 'error' => "unknown board key '{$key}'"];
        }

        // An untouched `<placeholder>` is not a value.
        if ($value === '' || preg_match('/^<.*>$/', $value)) {
            continue;
        }

        $values[$key] = $value;
    }

    $present = array_values(array_filter(PIPELINE_BOARD_KEYS, fn ($k) => isset($values[$k])));

    // Nothing filled in: a board-less repo, or the scaffold as copied. Not adopted.
    if ($present === []) {
        return ['state' => 'absent', 'board' => [], 'error' => null];
    }

    $missing = array_values(array_diff(PIPELINE_BOARD_KEYS, $present));
    if ($missing !== []) {
        return [
            'state' => 'invalid',
            'board' => [],
            'error' => "'## Board' is all-or-nothing; missing: " . implode(', ', $missing),
        ];
    }

    $board = [];
    foreach (PIPELINE_BOARD_KEYS as $key) {
        $board[$key] = $values[$key];
    }
    foreach (PIPELINE_BOARD_OPTIONAL_KEYS as $key) {
        if (isset($values[$key])) {
            $board[$key] = $values[$key];
        }
    }

    return ['state' => 'valid', 'board' => $board, 'error' => null];
}

/**
 * Sets one single-select field on the board item of `$url` (an issue or a PR): `item-add`, which returns
 * the existing item when the URL is already on the board, then `item-edit`. Both calls are idempotent.
 * A call that fails answers gh's words, or `exited <code>` when gh said nothing, the same for both.
 * `$gh` runs gh and returns `[code, out, err]`; `$board` is `pipeline_repo_board()`'s `board`.
 *
 * @return ?string null when the field is set, else why not
 */
function pipeline_board_set(callable $gh, array $board, string $url, string $fieldId, string $optionId): ?string
{
    [$code, $out, $err] = $gh(['project', 'item-add', $board['number'], '--owner', $board['org'], '--url', $url, '--format', 'json']);
    if ($code !== 0) {
        return $err === '' ? "gh project item-add exited {$code}" : $err;
    }
    $item = json_decode($out, true)['id'] ?? null;
    if (! is_string($item)) {
        return 'gh project item-add returned no item id';
    }
    [$code, , $err] = $gh(['project', 'item-edit', '--id', $item, '--project-id', $board['project-id'], '--field-id', $fieldId, '--single-select-option-id', $optionId]);

    return match (true) {
        $code === 0 => null,
        $err === '' => "gh project item-edit exited {$code}",
        default => $err,
    };
}
