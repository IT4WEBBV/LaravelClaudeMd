<?php

require_once __DIR__ . '/../../critique/checks/diff_parse.php';

/**
 * Which non-skippable gates / conditional legs a change trips.
 * $repoPackageName = the repo's own composer.json `name` (may be null).
 *
 * @return array{package: bool, migration: bool, auth: bool, ui: bool}
 */
function pipeline_triggers(string $diff, ?string $repoPackageName = null): array
{
    $files = parse_diff($diff);

    // Anchor at the repo root OR at a nested app root. The house-standard it4web
    // project layout puts the Laravel app under `code/www/` (CLAUDE.md §Docker
    // Environment), so a bare `^` matches only repos whose app sits at the top level —
    // and silently reports `false` for every project that follows the convention.
    $appRoot = '(?:^|/)';

    $uiPathRe    = "#(\.blade\.php$|{$appRoot}app/(Http/)?Livewire/|{$appRoot}resources/(views|css|js)/|\.vue$|tailwind\.config)#";
    $migrationRe = "#{$appRoot}database/migrations/.*\.php$#";
    $composerRe  = "#{$appRoot}composer\.json$#";
    $authRe      = '/\bauthorize\(|\bGate::|\bPolicy\b|[\'"]can:|->can\(|middleware\([\'"]can:/';
    // Comment and docblock lines are prose, not authorization. `#[` is a PHP attribute — code.
    $commentRe   = '#^\s*(?://|\#(?!\[)|/?\*)#';

    $ui = $migration = $auth = false;
    $package = $repoPackageName !== null && str_starts_with($repoPackageName, 'it4web/');

    foreach ($files as $f) {
        if (preg_match($uiPathRe, $f['file'])) {
            $ui = true;
        }
        if (preg_match($migrationRe, $f['file'])) {
            $migration = true;
        }
        $isComposer = (bool) preg_match($composerRe, $f['file']);
        foreach ($f['added'] as $a) {
            if (preg_match($authRe, $a['text']) && ! preg_match($commentRe, $a['text'])) {
                $auth = true;
            }
            if ($isComposer && preg_match('#["\']it4web/#', $a['text'])) {
                $package = true;
            }
        }
    }

    return ['package' => $package, 'migration' => $migration, 'auth' => $auth, 'ui' => $ui];
}

/**
 * Lines of code a change touches — added plus removed — outside tests, docs, markdown,
 * changelog fragments and lockfiles. The size a Bounded design may reach before it must grow
 * (`../references/engine.md` §Design size). A deleted file is judged by its old path.
 */
function pipeline_code_lines(string $diff): int
{
    $notCode = '#(?:^|/)(?:tests|docs|\.changelog)/|\.md$|(?:^|/)(?:composer\.lock|package-lock\.json|yarn\.lock|pnpm-lock\.yaml)$#';

    $lines = 0;
    foreach (parse_diff($diff) as $f) {
        $path = $f['file'] === '/dev/null' ? $f['old'] : $f['file'];
        if (! preg_match($notCode, $path)) {
            $lines += count($f['added']) + $f['removed'];
        }
    }

    return $lines;
}
