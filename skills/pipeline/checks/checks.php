<?php

/**
 * Parse the `## Checks` block out of a repo's `.claude/work-on.config.md`.
 *
 * Tri-state by design (`../references/engine.md` §Mechanical checks): `absent` and
 * `invalid` must never collapse into one another. A typo'd heading or a mis-cased key
 * that parsed as "not adopted" would disable the checks permanently while the run
 * believed it was covered — the one outcome the design calls worse than no tooling.
 *
 * @return array{state: 'absent'|'valid'|'invalid', commands: array<string, string>, error: ?string}
 */
function pipeline_repo_checks(string $configMarkdown): array
{
    $known = ['static-analysis', 'format'];
    $keyLineRe = '/^\s*-\s*([A-Za-z][A-Za-z0-9_-]*)\s*:\s*(.*)$/';

    $lines = preg_split('/\R/', $configMarkdown);

    $start = null;
    foreach ($lines as $i => $line) {
        if (preg_match('/^##\s+Checks\s*$/', $line)) {
            $start = $i;
            break;
        }
    }

    // No section. Check-shaped keys elsewhere mean the heading is malformed —
    // that is `invalid`, not `absent`.
    if ($start === null) {
        foreach ($lines as $line) {
            if (preg_match($keyLineRe, $line, $m) && in_array(strtolower($m[1]), $known, true)) {
                return [
                    'state' => 'invalid',
                    'commands' => [],
                    'error' => "check key '{$m[1]}' found outside a '## Checks' section",
                ];
            }
        }

        return ['state' => 'absent', 'commands' => [], 'error' => null];
    }

    $commands = [];
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

        if (! in_array($key, $known, true)) {
            return ['state' => 'invalid', 'commands' => [], 'error' => "unknown check key '{$key}'"];
        }
        if ($value === '') {
            return ['state' => 'invalid', 'commands' => [], 'error' => "check key '{$key}' has an empty value"];
        }

        $commands[$key] = $value;
    }

    if ($commands === []) {
        return ['state' => 'invalid', 'commands' => [], 'error' => "'## Checks' section declares no check"];
    }

    return ['state' => 'valid', 'commands' => $commands, 'error' => null];
}
