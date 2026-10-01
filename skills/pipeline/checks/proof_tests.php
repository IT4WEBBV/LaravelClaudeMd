<?php

/**
 * The test cases a branch adds or changes, for the proof page's *Tests this PR adds* (`../references/engine.md`
 * §The proof store). Pure: the diff and a reader of a file at `HEAD` come in, the cases go out. A change that only
 * removes lines inside a case is not seen, nor a `describe()` prefix, nor a dataset's rows.
 */

require_once __DIR__ . '/../../critique/checks/diff_parse.php';

/** How the branch touches a case; its label is the tag on the page. */
enum ProofTestChange: string
{
    case Added = 'added';
    case Changed = 'changed';

    public function label(): string
    {
        return match ($this) {
            self::Added => 'new',
            self::Changed => 'changed',
        };
    }
}

/**
 * Per test file in the diff, in diff order, the cases whose declaration line is added (`added`) or that hold
 * another added line (`changed`). A file with no such case is left out.
 *
 * @param callable(string): ?string $source the file's content at `HEAD`, or null
 * @return list<array{file: string, cases: list<array{name: string, change: string}>}>
 */
function proof_added_tests(string $diff, callable $source): array
{
    $files = [];
    foreach (parse_diff($diff) as $file) {
        if ($file['file'] === '/dev/null' || $file['added'] === [] || ! proof_is_test_file($file['file'])) {
            continue;
        }
        $content = $source($file['file']);
        $cases = $content === null ? [] : proof_touched_cases(proof_test_cases($content), array_column($file['added'], 'line'));
        if ($cases !== []) {
            $files[] = ['file' => $file['file'], 'cases' => $cases];
        }
    }

    return $files;
}

/** A PHP file under a `tests/` directory at any depth, or one named `*Test.php`. */
function proof_is_test_file(string $path): bool
{
    return str_ends_with($path, '.php')
        && (preg_match('#(^|/)tests/#', $path) === 1 || str_ends_with($path, 'Test.php'));
}

/**
 * Every case the file declares, in file order: Pest's `it(` or `test(` with a quoted description, a method
 * `function test…(`, a method after `#[Test]`, none inside a heredoc or nowdoc. A case runs from its first line
 * (the attribute's, for `#[Test]`) to the first later line that closes it at the declaration's own indentation
 * (`})` for Pest, `}` for a method), else to the line before the next case, else to the end of the file.
 *
 * @return list<array{name: string, declared: int, start: int, end: int}>
 */
function proof_test_cases(string $content): array
{
    $lines = explode("\n", $content);
    $found = [];
    $attribute = null;
    $heredoc = null;
    foreach ($lines as $index => $line) {
        $number = $index + 1;
        if ($heredoc !== null) {
            $heredoc = preg_match('/^\s*' . preg_quote($heredoc, '/') . '\b/', $line) === 1 ? null : $heredoc;

            continue;
        }
        $heredoc = proof_heredoc_label($line);
        if (preg_match('/^(\s*)(?:it|test)\(\s*([\'"])((?:\\\\.|(?!\2).)*)\2/', $line, $pest) === 1) {
            $found[] = ['name' => proof_unquote($pest[3], $pest[2]), 'declared' => $number, 'start' => $number, 'close' => $pest[1] . '})'];

            continue;
        }
        if (preg_match('/^\s*#\[\\\\?(?:PHPUnit\\\\Framework\\\\Attributes\\\\)?Test\b/', $line) === 1) {
            $attribute = $number;

            continue;
        }
        if (preg_match('/^(\s*)(?:(?:public|protected|private|static|final|abstract)\s+)*function\s+(\w+)\s*\(/', $line, $method) === 1) {
            if ($attribute !== null || str_starts_with($method[2], 'test')) {
                $found[] = ['name' => $method[2], 'declared' => $number, 'start' => $attribute ?? $number, 'close' => $method[1] . '}'];
            }
            $attribute = null;
        }
    }

    return array_map(fn (array $case, int $i) => [
        'name' => $case['name'],
        'declared' => $case['declared'],
        'start' => $case['start'],
        'end' => proof_case_end($lines, $case, ($found[$i + 1]['start'] ?? count($lines) + 1) - 1),
    ], $found, array_keys($found));
}

/** The label a line opens a heredoc or nowdoc with, whose body is a string and declares no case; else null. */
function proof_heredoc_label(string $line): ?string
{
    return preg_match('/<<<\s*([\'"]?)(\w+)\1\s*$/', $line, $opener) === 1 ? $opener[2] : null;
}

/** The first line after the declaration that starts with the case's closing token at its indentation, else `$limit`. */
function proof_case_end(array $lines, array $case, int $limit): int
{
    for ($number = $case['declared'] + 1; $number <= $limit; $number++) {
        if (str_starts_with($lines[$number - 1], $case['close'])) {
            return $number;
        }
    }

    return $limit;
}

/** A quoted description as PHP reads it. */
function proof_unquote(string $text, string $quote): string
{
    return $quote === "'" ? strtr($text, ['\\\\' => '\\', "\\'" => "'"]) : stripcslashes($text);
}

/**
 * @param list<array{name: string, declared: int, start: int, end: int}> $cases
 * @param list<int> $addedLines
 * @return list<array{name: string, change: string}>
 */
function proof_touched_cases(array $cases, array $addedLines): array
{
    $added = array_flip($addedLines);
    $touched = [];
    foreach ($cases as $case) {
        $change = match (true) {
            isset($added[$case['declared']]) => ProofTestChange::Added,
            array_filter(range($case['start'], $case['end']), fn (int $line) => isset($added[$line])) !== [] => ProofTestChange::Changed,
            default => null,
        };
        if ($change !== null) {
            $touched[] = ['name' => $case['name'], 'change' => $change->value];
        }
    }

    return $touched;
}
