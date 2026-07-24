<?php

function manifest_read(string $path): ?array
{
    if (! is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : null;
}

function manifest_write(string $path, array $data): void
{
    $dir = dirname($path);
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

/** @return list<string> missing required keys */
function manifest_validate(array $data): array
{
    return array_values(array_filter(
        ['branch', 'worktree', 'mode', 'cursor'],
        fn ($key) => ! array_key_exists($key, $data),
    ));
}

function manifest_infer_cursor(array $p): string
{
    if (empty($p['spec']) || empty($p['plan'])) {
        return 'design';
    }
    if (empty($p['planApproved'])) {
        return 'review-plan';
    }
    if (empty($p['pr'])) {
        return 'handoff';
    }
    if (empty($p['implemented'])) {
        return 'implement';
    }
    if (! empty($p['uiNeeded']) && empty($p['verifyUi'])) {
        return 'verify-ui';
    }
    if (empty($p['prReviewed'])) {
        return 'review-pr';
    }

    return 'done';
}
