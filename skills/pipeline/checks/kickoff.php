<?php

/**
 * `dispatch_cli.php kickoff` (`../references/engine.md` §Kickoff): §The work item and §Kickoff in one
 * call for the unattended modes. Every value comes from the repo's config, gh or git; a value kickoff
 * would have to compute is a halt.
 */

require_once __DIR__ . '/board.php';
require_once __DIR__ . '/dispatch.php';
require_once __DIR__ . '/manifest.php';
require_once __DIR__ . '/suite.php';

/** A `- key: value` line under `## <section>` of `.claude/work-on.config.md`, its trailing `# …` stripped; null when absent or empty. */
function pipeline_repo_config_value(string $configMarkdown, string $section, string $key): ?string
{
    $inSection = false;
    foreach (preg_split('/\R/', $configMarkdown) as $line) {
        if (preg_match('/^##\s+(.+?)\s*$/', $line, $heading)) {
            $inSection = $heading[1] === $section;

            continue;
        }
        if ($inSection && preg_match('/^\s*-\s*' . preg_quote($key, '/') . '\s*:\s*(.*)$/', $line, $match)) {
            $value = trim(preg_replace('/\s+#.*$/', '', $match[1]));

            return $value === '' ? null : $value;
        }
    }

    return null;
}

/** `work-on`'s slug rule: lowercase, runs outside `[a-z0-9]` become `-`, at most 50 characters, cut at a word. */
function pipeline_slug(string $text): string
{
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
    if (strlen($slug) <= 50) {
        return $slug;
    }
    $cut = strrpos(substr($slug, 0, 51), '-');

    return substr($slug, 0, $cut === false ? 50 : $cut);
}

/** The first `<name>` a caller would still have to fill in; shell redirections are not names. */
function pipeline_placeholder(string $text): ?string
{
    return preg_match('/<[A-Za-z][A-Za-z0-9_-]*>/', $text, $match) ? $match[0] : null;
}

/** `branch.issue` up to `<slug>`, the number filled in: every branch for the issue starts with it, whoever slugged the title. Null without a `<slug>`, or without a `<number>` before it. */
function pipeline_branch_prefix(string $pattern, int $number): ?string
{
    $head = strstr($pattern, '<slug>', true);

    return $head === false || ! str_contains($head, '<number>') ? null : str_replace('<number>', (string) $number, $head);
}
