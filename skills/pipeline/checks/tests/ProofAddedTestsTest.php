<?php

/** A diff of one file that is `$content` at HEAD: lines numbered in `$added` are `+`, the rest context. */
function added_tests_diff(string $path, string $content, array $added): string
{
    $lines = explode("\n", $content);
    $body = array_map(fn (string $line, int $i) => (in_array($i + 1, $added, true) ? '+' : ' ') . $line, $lines, array_keys($lines));

    return "--- a/{$path}\n+++ b/{$path}\n@@ -1," . (count($lines) - count($added)) . ' +1,' . count($lines) . " @@\n" . implode("\n", $body) . "\n";
}

/** `proof_added_tests()` over one file whose content the reader returns. */
function added_tests(string $path, string $content, array $added): array
{
    return proof_added_tests(added_tests_diff($path, $content, $added), fn (string $asked) => $asked === $path ? $content : null);
}

const ADDED_TESTS_PEST = <<<'PHP'
<?php

it('follows the log', function () {
    expect(true)->toBeTrue();
});

test('stops at "eof"', function () {
    expect(1)->toBe(1);
});

it('can\'t lose a line', function () {
    expect(2)->toBe(2);
});
PHP;

it('lists a Pest case it adds as new and one it changes as changed, by its unescaped description', function () {
    expect(added_tests('tests/Feature/LogsTest.php', ADDED_TESTS_PEST, [3, 4, 5, 8, 11, 12, 13]))->toBe([[
        'file' => 'tests/Feature/LogsTest.php',
        'cases' => [
            ['name' => 'follows the log', 'change' => 'added'],
            ['name' => 'stops at "eof"', 'change' => 'changed'],
            ['name' => "can't lose a line", 'change' => 'added'],
        ],
    ]]);
});

it('lists a PHPUnit test method and a #[Test] method, and not a helper method changed beside them', function () {
    $content = <<<'PHP'
<?php

class LogsTest extends TestCase
{
    public function test_stops_at_eof(): void
    {
        $this->assertTrue(true);
    }

    #[Test]
    public function it_follows(): void
    {
        $this->assertTrue(true);
    }

    private function helper(): void
    {
        // nothing
    }
}
PHP;

    expect(added_tests('tests/Unit/LogsTest.php', $content, [7, 10, 11, 12, 13, 14, 18]))->toBe([[
        'file' => 'tests/Unit/LogsTest.php',
        'cases' => [
            ['name' => 'test_stops_at_eof', 'change' => 'changed'],
            ['name' => 'it_follows', 'change' => 'added'],
        ],
    ]]);
});

it('leaves out a file whose added lines fall outside every case', function () {
    $content = "<?php\n\nuse App\\Logs;\n\nit('follows the log', function () {\n    expect(true)->toBeTrue();\n});";

    expect(added_tests('tests/Feature/LogsTest.php', $content, [3]))->toBe([]);
});

it('ends a case without a closing line before the next one, so the next case\'s change is not credited to it', function () {
    $content = "<?php\n\nit('is quick', fn () => expect(true)->toBeTrue());\n\nit('is thorough', function () {\n    expect(1)->toBe(1);\n});";

    expect(added_tests('tests/Feature/SpeedTest.php', $content, [6]))->toBe([[
        'file' => 'tests/Feature/SpeedTest.php',
        'cases' => [['name' => 'is thorough', 'change' => 'changed']],
    ]]);
});

it('reads no case out of a heredoc or nowdoc fixture, so test source embedded as a fixture is not listed', function () {
    $content = <<<'PHP'
<?php

const FIXTURE = <<<'SOURCE'
it('is a fixture', function () {
    expect(true)->toBeTrue();
});
SOURCE;

it('reads the fixture', function () {
    $more = <<<SOURCE
    public function test_also_a_fixture(): void
    {
    }
    SOURCE;
    expect(FIXTURE)->toBeString();
});
PHP;

    expect(added_tests('tests/Feature/FixtureTest.php', $content, range(3, 17)))->toBe([[
        'file' => 'tests/Feature/FixtureTest.php',
        'cases' => [['name' => 'reads the fixture', 'change' => 'added']],
    ]]);
});

it('reads only PHP test files: not a PHP file outside tests, not a JavaScript test, not a deleted file', function () {
    $php = "<?php\n\nfunction test_helper(): void\n{\n}";
    $js = "it('follows the log', () => {\n  expect(true).toBe(true)\n})";
    $deleted = "--- a/tests/OldTest.php\n+++ /dev/null\n@@ -1,2 +0,0 @@\n-<?php\n-it('old', fn () => true);\n";

    expect(added_tests('app/Support/Helpers.php', $php, [3, 4, 5]))->toBe([]);
    expect(added_tests('tests/js/logs.test.js', $js, [1, 2, 3]))->toBe([]);
    expect(proof_added_tests($deleted, fn () => throw new RuntimeException('a deleted file is never read')))->toBe([]);
});

it('counts a file named *Test.php outside a tests directory, and skips a file the reader cannot give', function () {
    $content = "<?php\n\nit('works', function () {\n    expect(true)->toBeTrue();\n});";

    expect(added_tests('packages/logs/LogsTest.php', $content, [3, 4, 5])[0]['cases'])->toBe([['name' => 'works', 'change' => 'added']]);
    expect(proof_added_tests(added_tests_diff('tests/GoneTest.php', $content, [3]), fn () => null))->toBe([]);
});

it('tags an added case new and a changed one changed', function () {
    expect(ProofTestChange::Added->label())->toBe('new');
    expect(ProofTestChange::Changed->label())->toBe('changed');
});
