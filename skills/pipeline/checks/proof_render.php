<?php

/**
 * `run.json` → HTML. Pure string building: no filesystem, no network, no clock.
 *
 * The page must open over `file://`, so every asset reference is relative and every style
 * is inline. It is an impersonal record — it never addresses a person, never uses second
 * person, and never invites a reply.
 */

function proof_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function proof_render_styles(): string
{
    return <<<'CSS'
:root { --bg:#fff; --fg:#18181b; --muted:#71717a; --line:#e4e4e7; --card:#fafafa; --accent:#dc2626; }
@media (prefers-color-scheme: dark) {
  :root { --bg:#18181b; --fg:#f4f4f5; --muted:#a1a1aa; --line:#3f3f46; --card:#27272a; --accent:#ef4444; }
}
* { box-sizing:border-box; }
body { margin:0; padding:2rem 1.5rem 4rem; background:var(--bg); color:var(--fg);
  font:15px/1.6 ui-sans-serif,-apple-system,"Segoe UI",sans-serif; max-width:60rem; margin-inline:auto; }
h1 { font-size:1.5rem; margin:0 0 .25rem; }
h2 { font-size:1rem; text-transform:uppercase; letter-spacing:.05em; color:var(--muted);
  margin:2.5rem 0 .75rem; padding-bottom:.4rem; border-bottom:1px solid var(--line); }
.meta { color:var(--muted); font-size:.875rem; margin-bottom:.5rem; }
.meta code { background:var(--card); padding:.1rem .35rem; border-radius:.25rem; }
.shot { position:relative; display:block; margin:0 0 .5rem; border:1px solid var(--line); border-radius:.5rem; overflow:hidden; }
.shot img { width:100%; display:block; }
.badge { position:absolute; width:26px; height:26px; border-radius:50%; background:var(--accent);
  color:#fff; font-weight:700; font-size:13px; display:flex; align-items:center; justify-content:center;
  box-shadow:0 2px 6px rgba(0,0,0,.35); transform:translate(-50%,-50%); }
figure { margin:0 0 2rem; }
figcaption { color:var(--muted); font-size:.875rem; margin-bottom:.5rem; }
ol.legend { padding-left:1.25rem; }
ol.legend li { margin-bottom:.4rem; }
ul { padding-left:1.25rem; }
table { border-collapse:collapse; width:100%; font-size:.9rem; }
td, th { text-align:left; padding:.4rem .6rem; border-bottom:1px solid var(--line); vertical-align:top; }
.flag { color:var(--accent); font-weight:600; }
details { margin-top:1rem; }
summary { cursor:pointer; color:var(--muted); }
CSS;
}

/** A block of author-written prose: escaped, with blank lines becoming paragraphs. */
function proof_render_prose(string $text): string
{
    $paragraphs = preg_split('/\R{2,}/', trim($text)) ?: [];
    $out = '';
    foreach ($paragraphs as $p) {
        if (trim($p) === '') {
            continue;
        }
        $out .= '<p>' . nl2br(proof_e(trim($p))) . "</p>\n";
    }

    return $out;
}

function proof_render_shots(array $shots): string
{
    if ($shots === []) {
        return '';
    }

    $out = "<h2>Visual result</h2>\n";
    foreach ($shots as $shot) {
        $badges = '';
        $legend = '';
        foreach ($shot['badges'] ?? [] as $badge) {
            $badges .= sprintf(
                '<span class="badge" style="top:%s%%;left:%s%%">%s</span>',
                proof_e((string) (0 + ($badge['topPct'] ?? 0))),
                proof_e((string) (0 + ($badge['leftPct'] ?? 0))),
                proof_e((string) ($badge['num'] ?? '')),
            );
            $legend .= '<li><strong>' . proof_e((string) ($badge['title'] ?? '')) . '</strong> — '
                . proof_e((string) ($badge['note'] ?? '')) . "</li>\n";
        }

        $out .= "<figure>\n"
            . '<figcaption>' . proof_e((string) ($shot['title'] ?? '')) . ' — <code>'
            . proof_e((string) ($shot['route'] ?? '')) . "</code></figcaption>\n"
            . '<span class="shot"><img alt="' . proof_e((string) ($shot['title'] ?? '')) . '" src="'
            . proof_e((string) ($shot['file'] ?? '')) . '">' . $badges . "</span>\n"
            . ($legend === '' ? '' : "<ol class=\"legend\">\n{$legend}</ol>\n")
            . "</figure>\n";
    }

    return $out;
}

function proof_render_checks(array $checks): string
{
    $rows = '';
    foreach (['tests' => 'Test suite', 'staticAnalysis' => 'Static analysis', 'format' => 'Format'] as $key => $label) {
        if (! empty($checks[$key])) {
            $rows .= '<tr><th>' . proof_e($label) . '</th><td>' . proof_e((string) $checks[$key]) . "</td></tr>\n";
        }
    }

    // A suppression must never arrive reading as already resolved — the agent whose step was
    // blocked is otherwise judging its own excuse.
    foreach ($checks['suppressions'] ?? [] as $suppression) {
        $rows .= '<tr><th class="flag">Suppression</th><td>' . proof_e((string) $suppression)
            . ' <span class="flag">(not yet judged)</span></td></tr>' . "\n";
    }

    return $rows === '' ? '' : "<h2>Checks</h2>\n<table>\n{$rows}</table>\n";
}

function proof_render_list(string $heading, array $items): string
{
    if ($items === []) {
        return '';
    }
    $out = '<h2>' . proof_e($heading) . "</h2>\n<ul>\n";
    foreach ($items as $item) {
        $out .= '<li>' . proof_e((string) $item) . "</li>\n";
    }

    return $out . "</ul>\n";
}

function proof_render_ledger(array $ledger): string
{
    if ($ledger === []) {
        return '';
    }
    $rows = '';
    foreach ($ledger as $entry) {
        $rows .= '<tr><th>' . proof_e((string) ($entry['gate'] ?? '')) . '</th><td>'
            . proof_e((string) ($entry['outcome'] ?? '')) . ' — '
            . proof_e((string) ($entry['note'] ?? '')) . "</td></tr>\n";
    }

    return "<details><summary>Gate ledger</summary>\n<table>\n{$rows}</table>\n</details>\n";
}

function proof_render_run(array $run): string
{
    $title = (string) ($run['headline'] ?? ($run['branch'] ?? 'pipeline run'));
    $pr = empty($run['pr']) ? 'no PR' : '#' . (string) $run['pr'] . ' (' . (string) ($run['prState'] ?? '?') . ')';

    $meta = sprintf(
        '<code>%s</code> · %s · <code>%s</code> · %s mode · %s',
        proof_e((string) ($run['repo'] ?? '')),
        proof_e($pr),
        proof_e((string) ($run['branch'] ?? '')),
        proof_e((string) ($run['mode'] ?? '')),
        proof_e((string) ($run['updatedAt'] ?? '')),
    );

    $body = "<h1>" . proof_e($title) . "</h1>\n<p class=\"meta\">{$meta}</p>\n";

    if (! empty($run['problem'])) {
        $body .= "<h2>Problem</h2>\n" . proof_render_prose((string) $run['problem']);
    }
    if (! empty($run['solution'])) {
        $body .= "<h2>Solution</h2>\n" . proof_render_prose((string) $run['solution']);
    }

    $body .= proof_render_shots($run['shots'] ?? []);
    $body .= proof_render_checks($run['checks'] ?? []);
    $body .= proof_render_list('Open questions', $run['openQuestions'] ?? []);
    $body .= proof_render_ledger($run['ledger'] ?? []);

    $styles = proof_render_styles();

    return "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n"
        . "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n"
        . '<title>' . proof_e($title) . "</title>\n<style>\n{$styles}\n</style>\n</head>\n<body>\n"
        . $body
        . "</body>\n</html>\n";
}
