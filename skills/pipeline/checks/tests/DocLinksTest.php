<?php

/** The pipeline's docs by the name a citation uses: relative to `references/`, and `SKILL.md`, `DECISIONS.md` at the root. */
function doc_links_pipeline_docs(): array
{
    $skill = realpath(__DIR__ . '/../..');
    $docs = ['SKILL.md' => "{$skill}/SKILL.md", 'DECISIONS.md' => "{$skill}/DECISIONS.md"];
    foreach (glob("{$skill}/references/{,steps/,shared/}*.md", GLOB_BRACE) as $path) {
        $docs[substr($path, strlen("{$skill}/references/"))] = $path;
    }

    return $docs;
}

/** A doc's `## ` and `### ` headings, each cut at its ` — `. */
function doc_links_headings(string $path): array
{
    preg_match_all('/^#{2,3} (.+?)(?: — .*)?$/m', (string) file_get_contents($path), $headings);

    return $headings[1];
}

/** The pipeline doc a cited path names, or null for another file (spec A10). */
function doc_links_target(string $token, string $file): ?string
{
    $name = (string) preg_replace('#^(?:~/\.claude/skills/pipeline/|skills/pipeline/|pipeline/|\.\./|\./|references/)+#', '', $token);
    if ($name === 'SKILL.md' && $token === 'SKILL.md' && ! str_contains($file, '/skills/pipeline/')) {
        return null;
    }

    return doc_links_pipeline_docs()[$name] ?? null;
}

/** Whether a cited name, its whitespace collapsed, starts with one of the headings. */
function doc_links_names_heading(string $name, array $headings): bool
{
    $name = trim((string) preg_replace('/\s+/', ' ', $name));

    return array_filter($headings, fn (string $heading) => str_starts_with($name, $heading)) !== [];
}

/** The problems in one file's section references: `<doc>.md §<Heading>`, and in a pipeline doc a bare `§<Heading>`. */
function doc_links_problems(string $file, string $text): array
{
    $problems = [];
    preg_match_all('/`?((?:[\w~.-]+\/)*[\w.-]+\.md)`?\s+§([^,):;]+)/u', $text, $cited, PREG_SET_ORDER);
    foreach ($cited as [, $token, $name]) {
        $target = doc_links_target($token, $file);
        if ($target !== null && ! doc_links_names_heading($name, doc_links_headings($target))) {
            $problems[] = "{$token} §" . trim(strtok($name, "\n"));
        }
    }
    if (str_contains($file, '/skills/pipeline/') && str_ends_with($file, '.md')) {
        $bare = (string) preg_replace(['/`?(?:[\w~.-]+\/)*[\w.-]+\.md`?\s+§[^,):;]+/u', '/`§`/u'], '', $text);
        preg_match_all('/§([^,):;]+)/u', $bare, $names);
        foreach ($names[1] as $name) {
            if (! doc_links_names_heading($name, doc_links_headings($file))) {
                $problems[] = '§' . trim(strtok($name, "\n"));
            }
        }
    }

    return $problems;
}

/** The files the scan reads (spec *Tests*). */
function doc_links_files(): array
{
    $root = realpath(__DIR__ . '/../../../..');
    $markdown = fn (string $dir) => array_map(fn (SplFileInfo $file) => $file->getPathname(), array_filter(
        iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS))),
        fn (SplFileInfo $file) => $file->getExtension() === 'md',
    ));

    return [
        ...$markdown("{$root}/skills/pipeline"), ...$markdown("{$root}/skills/orchestrate"),
        ...glob("{$root}/skills/pipeline/checks/*.php"), ...glob("{$root}/skills/pipeline/workflow/*.js"),
        "{$root}/skills/browser-verification/SKILL.md", "{$root}/skills/slots/SKILL.md", "{$root}/README.md", "{$root}/CLAUDE.md",
    ];
}

it('resolves a wrapped reference and refuses a wrong one', function () {
    $file = realpath(__DIR__ . '/../..') . '/references/steps/implement.md';

    expect(doc_links_problems($file, "see (`gates.md` §Navigation\nguardrail) and `shared/suite.md` §Suite reuse"))->toBe([]);
    expect(doc_links_problems($file, '(`gates.md` §Nowhere) and (§Elsewhere)'))->toBe(['gates.md §Nowhere', '§Elsewhere']);
    expect(doc_links_problems($file, 'as §Nowhere, says'))->toBe(['§Nowhere']);
    expect(doc_links_problems($file, 'the `§` sign and `README.md` §Status line'))->toBe([]);
    expect(doc_links_problems('/x/skills/orchestrate/SKILL.md', '`SKILL.md` §Anything'))->toBe([]);
    expect(doc_links_problems('/x/skills/orchestrate/SKILL.md', 'pipeline `references/session.md` §Nowhere'))->toBe(['references/session.md §Nowhere']);
});

it('resolves every section reference in the pipeline docs and the docs that cite them', function () {
    foreach (doc_links_files() as $file) {
        expect(doc_links_problems($file, (string) file_get_contents($file)))->toBe([], $file);
    }
});

it('leaves no engine.md outside docs/ and DECISIONS.md', function () {
    $root = realpath(__DIR__ . '/../../../..');
    exec('git -C ' . escapeshellarg($root) . " grep -l -F engine.md -- . ':!docs' ':!skills/pipeline/DECISIONS.md' ':!skills/pipeline/checks/tests'", $files);

    expect($files)->toBe([]);
    expect("{$root}/skills/pipeline/references/engine.md")->not->toBeFile();
});

it('keeps history out of the rule text: SKILL.md and every reference', function () {
    $skill = realpath(__DIR__ . '/../..');
    foreach (["{$skill}/SKILL.md", ...glob("{$skill}/references/{,steps/,shared/}*.md", GLOB_BRACE)] as $path) {
        $text = (string) file_get_contents($path);
        $name = substr($path, strlen("{$skill}/"));

        expect($text)->not->toContain('Why (#', $name)->not->toContain('when this lands', $name);
        expect(preg_match('/^Why:/m', $text))->toBe(0, "{$name} has a line starting Why:");
        expect(preg_match('/\bbefore #\d/i', $text))->toBe(0, "{$name} says before #<n>");
    }
});
