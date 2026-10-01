<?php

/**
 * `run.json` → HTML. Pure string building: no filesystem, no network, no clock.
 *
 * The page must open over `file://`, so every asset reference is relative and every style
 * is inline. It is an impersonal record — it never addresses a person, never uses second
 * person, and never invites a reply.
 */

require_once __DIR__ . '/proof.php';
require_once __DIR__ . '/proof_tests.php';

const PROOF_PENDING = "<p class=\"pending\">Pending: written by the step that finishes the run.</p>\n";

function proof_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function proof_render_styles(): string
{
    return <<<'CSS'
:root { --bg:#fff; --fg:#18181b; --muted:#71717a; --line:#e4e4e7; --card:#fafafa; --accent:#dc2626;
  --before:#71717a; --after:#16a34a; --defect:var(--accent); }
@media (prefers-color-scheme: dark) {
  :root { --bg:#18181b; --fg:#f4f4f5; --muted:#a1a1aa; --line:#3f3f46; --card:#27272a; --accent:#ef4444;
    --before:#a1a1aa; --after:#22c55e; }
}
* { box-sizing:border-box; }
body { margin:0; padding:2rem 1.5rem 4rem; background:var(--bg); color:var(--fg);
  font:15px/1.6 ui-sans-serif,-apple-system,"Segoe UI",sans-serif; max-width:60rem; margin-inline:auto; }
h1 { font-size:1.5rem; margin:0 0 .25rem; }
.lead { font-size:1.05rem; margin:.75rem 0 0; }
.caption { display:block; margin-top:.2rem; }
h2 { font-size:1rem; text-transform:uppercase; letter-spacing:.05em; color:var(--muted);
  margin:2.5rem 0 .75rem; padding-bottom:.4rem; border-bottom:1px solid var(--line); }
.meta { color:var(--muted); font-size:.875rem; margin-bottom:.5rem; }
.meta code { background:var(--card); padding:.1rem .35rem; border-radius:.25rem; }
.shot { position:relative; display:block; margin:0 0 .5rem; cursor:zoom-in; }
.shot img { width:100%; display:block; border:1px solid var(--line); border-radius:.5rem; }
.badge { position:absolute; width:26px; height:26px; border-radius:50%; background:var(--accent);
  color:#fff; font-weight:700; font-size:13px; display:flex; align-items:center; justify-content:center;
  box-shadow:0 2px 6px rgba(0,0,0,.35); transform:translate(-50%,-50%); cursor:help; }
figure { margin:0 0 2rem; }
figcaption { color:var(--muted); font-size:.875rem; margin-bottom:.5rem; }
ol.legend { padding-left:1.25rem; }
ol.legend li { margin-bottom:.4rem; }
ul { padding-left:1.25rem; }
a { color:inherit; text-decoration:underline; text-underline-offset:.15em; }
table { border-collapse:collapse; width:100%; font-size:.9rem; }
td, th { text-align:left; padding:.4rem .6rem; border-bottom:1px solid var(--line); vertical-align:top; }
.flag { color:var(--accent); font-weight:600; }
details { margin-top:1rem; }
summary { cursor:pointer; color:var(--muted); }
h3 { font-size:.95rem; margin:1.25rem 0 .4rem; }
.section-head { display:flex; align-items:center; justify-content:space-between; gap:1rem;
  margin:2.5rem 0 .75rem; padding-bottom:.4rem; border-bottom:1px solid var(--line); }
.section-head h2 { margin:0; padding:0; border:0; }
button.copy { font:inherit; font-size:.8rem; padding:.2rem .7rem; border:1px solid var(--line); border-radius:.35rem;
  background:var(--card); color:var(--fg); cursor:pointer; }
.pending { color:var(--muted); font-style:italic; }
.tag { display:inline-block; margin-left:.4rem; padding:0 .4rem; border:1px solid var(--line); border-radius:.25rem;
  color:var(--muted); font-size:.75rem; font-weight:600; }
.tag-added { color:var(--after); border-color:var(--after); }
.ribbon { position:absolute; top:.6rem; left:.6rem; padding:.1rem .55rem; border-radius:.25rem; color:#fff;
  font-size:12px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; }
.ribbon-before { background:var(--before); }
.ribbon-after { background:var(--after); }
.ribbon-defect { background:var(--defect); }
.badge .tip { display:none; position:absolute; top:calc(100% + 6px); left:50%; transform:translateX(-50%);
  width:max-content; max-width:16rem; padding:.35rem .55rem; border-radius:.3rem; background:var(--fg); color:var(--bg);
  font-size:12px; font-weight:400; line-height:1.4; text-align:left; z-index:2; }
.badge:hover .tip, .badge:focus .tip { display:block; }
.pair { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
@media (max-width:700px) { .pair { grid-template-columns:1fr; } }
dialog.zoom { padding:0; border:0; max-width:95vw; max-height:95vh; overflow:auto; background:var(--bg); }
dialog.zoom::backdrop { background:rgba(0,0,0,.75); }
dialog.zoom .shot { width:max-content; margin:0; cursor:zoom-out; }
dialog.zoom .shot img { width:auto; max-width:none; }
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

/** The Dutch client summary for the hour registration, with its copy button; pending until a step writes it. */
function proof_render_summary(array $run): string
{
    $summary = trim((string) ($run['clientSummary'] ?? ''));
    if ($summary === '') {
        return "<h2>Client summary</h2>\n" . PROOF_PENDING;
    }

    return "<div class=\"section-head\"><h2>Client summary</h2><button type=\"button\" class=\"copy\" data-copy=\"client-summary\">Copy</button></div>\n"
        . '<p lang="nl" id="client-summary">' . proof_e($summary) . "</p>\n";
}

/** The problem and the solution for a reader who knows nothing about the issue; pending until a step writes them. */
function proof_render_explainer(array $run): string
{
    $explainer = $run['explainer'] ?? null;
    $written = is_array($explainer)
        && trim((string) ($explainer['problem'] ?? '')) !== ''
        && trim((string) ($explainer['solution'] ?? '')) !== '';

    return "<h2>In plain language</h2>\n" . ($written
        ? "<h3>The problem</h3>\n" . proof_render_prose((string) $explainer['problem'])
            . "<h3>The solution</h3>\n" . proof_render_prose((string) $explainer['solution'])
        : PROOF_PENDING);
}

/** @param list<array{file: string, cases: list<array{name: string, change: string}>}> $files */
function proof_render_tests(array $files): string
{
    if ($files === []) {
        return "<h2>Tests this PR adds</h2>\n<p class=\"meta\">This PR adds or changes no test cases.</p>\n";
    }
    $out = "<h2>Tests this PR adds</h2>\n";
    foreach ($files as $file) {
        $out .= '<h3><code>' . proof_e((string) $file['file']) . "</code></h3>\n<ul class=\"tests\">\n";
        foreach ($file['cases'] as $case) {
            $change = ProofTestChange::from((string) $case['change']);
            $out .= '<li>' . proof_e((string) $case['name']) . ' <span class="tag tag-' . $change->value . '">' . $change->label() . "</span></li>\n";
        }
        $out .= "</ul>\n";
    }

    return $out;
}

/**
 * The page's one script: the copy button (the clipboard API, else a selected textarea and `execCommand('copy')`,
 * which works over `file://`) and the zoom (a click on a shot shows a copy of it at natural size in the dialog;
 * Escape, the backdrop or the zoomed shot closes it).
 */
function proof_render_script(): string
{
    return <<<'JS'
(function () {
  var dialog = document.getElementById('zoom');
  function fallback(text) {
    var area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    var copied = document.execCommand('copy');
    area.remove();
    if (!copied) { throw new Error('copy failed'); }
  }
  function copyText(text) {
    try {
      return navigator.clipboard.writeText(text).catch(function () { fallback(text); });
    } catch (error) {
      return new Promise(function (resolve) { fallback(text); resolve(); });
    }
  }
  function flash(button, label) {
    button.textContent = label;
    setTimeout(function () { button.textContent = 'Copy'; }, 2000);
  }
  function zoom(shot) {
    dialog.replaceChildren(shot.cloneNode(true));
    dialog.showModal();
  }
  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-copy]');
    if (button) {
      copyText(document.getElementById(button.dataset.copy).textContent)
        .then(function () { flash(button, 'Copied'); }, function () { flash(button, 'Copy failed'); });
      return;
    }
    if (dialog.open) {
      if (event.target === dialog || event.target.closest('#zoom .shot')) { dialog.close(); }
      return;
    }
    var shot = event.target.closest('.shot');
    if (shot) { zoom(shot); }
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Enter' && !dialog.open && event.target.classList && event.target.classList.contains('shot')) {
      zoom(event.target);
    }
  });
})();
JS;
}

function proof_render_shots(array $shots): string
{
    if ($shots === []) {
        return '';
    }

    return "<h2>Visual result</h2>\n" . implode('', array_map(
        fn (array $row) => count($row) === 2
            ? "<div class=\"pair\">\n" . proof_render_shot($row[0]) . proof_render_shot($row[1]) . "</div>\n"
            : proof_render_shot($row[0]),
        proof_shot_rows(array_values($shots)),
    ));
}

/**
 * The shots by row: a `before` directly followed by an `after` share one, every other shot has its own.
 * Pairing is positional; no field names the pair.
 *
 * @return list<list<array>>
 */
function proof_shot_rows(array $shots): array
{
    $rows = [];
    $i = 0;
    while ($i < count($shots)) {
        $paired = ($shots[$i]['state'] ?? null) === ProofShotState::Before->value
            && ($shots[$i + 1]['state'] ?? null) === ProofShotState::After->value;
        $width = $paired ? 2 : 1;
        $rows[] = array_slice($shots, $i, $width);
        $i += $width;
    }

    return $rows;
}

/** One shot: its caption, the image with its ribbon and badges (each holding its note), and the legend below. */
function proof_render_shot(array $shot): string
{
    $badges = '';
    $legend = '';
    foreach ($shot['badges'] ?? [] as $badge) {
        $title = proof_e((string) ($badge['title'] ?? ''));
        $note = proof_e((string) ($badge['note'] ?? ''));
        $badges .= sprintf(
            '<span class="badge" style="top:%s%%;left:%s%%" tabindex="0">%s<span class="tip" role="tooltip">%s — %s</span></span>',
            proof_e((string) (0 + ($badge['topPct'] ?? 0))),
            proof_e((string) (0 + ($badge['leftPct'] ?? 0))),
            proof_e((string) ($badge['num'] ?? '')),
            $title,
            $note,
        );
        // Carry the badge's own number onto the list marker. The <ol> would otherwise
        // renumber from 1 per figure, so a run that numbers its badges continuously across
        // shots — which nothing forbids — renders a "5" on the image above a "1." in the
        // legend, and the two stop referring to each other.
        $marker = is_numeric($badge['num'] ?? null)
            ? ' value="' . proof_e((string) (int) $badge['num']) . '"'
            : '';

        $legend .= '<li' . $marker . '><strong>' . $title . '</strong> — ' . $note . "</li>\n";
    }

    $state = is_string($shot['state'] ?? null) ? ProofShotState::tryFrom($shot['state']) : null;
    $ribbon = $state === null ? '' : '<span class="ribbon ribbon-' . $state->value . '">' . $state->label() . '</span>';
    $caption = empty($shot['caption'])
        ? ''
        : '<span class="caption">' . proof_e((string) $shot['caption']) . '</span>';

    return "<figure>\n"
        . '<figcaption><strong>' . proof_e((string) ($shot['title'] ?? '')) . '</strong> — <code>'
        . proof_e((string) ($shot['route'] ?? '')) . '</code>' . $caption . "</figcaption>\n"
        . '<span class="shot" role="button" tabindex="0" title="Zoom"><img alt="' . proof_e((string) ($shot['title'] ?? '')) . '" src="'
        . proof_e((string) ($shot['file'] ?? '')) . '">' . $ribbon . $badges . "</span>\n"
        . ($legend === '' ? '' : "<ol class=\"legend\">\n{$legend}</ol>\n")
        . "</figure>\n";
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

/**
 * A GitHub URL for this run, or null when the payload carries no `nameWithOwner` and there is
 * therefore nothing to build one from.
 *
 * Absolute by necessity: the page is opened straight off disk, so a relative href would resolve
 * against `file://` and reach nothing.
 */
function proof_github_url(array $run, string $path): ?string
{
    $nameWithOwner = trim((string) ($run['nameWithOwner'] ?? ''));

    return $nameWithOwner === '' ? null : 'https://github.com/' . $nameWithOwner . '/' . $path;
}

/** A reference that becomes a link when there is a URL for it, and stays plain text otherwise. */
function proof_render_ref(?string $url, string $label): string
{
    return $url === null
        ? proof_e($label)
        : '<a href="' . proof_e($url) . '">' . proof_e($label) . '</a>';
}

/**
 * The issue a run is for. `issue` is authoritative; the branch name is the fallback, so runs
 * filed before the field existed still link.
 *
 * A number is never invented — neither source yielding one means no reference at all, because a
 * confidently wrong issue link is worse than none. The boundary before `issue` keeps a branch
 * like `feature/reissue-5-retry` from matching.
 */
function proof_issue_number(array $run): ?int
{
    if (is_numeric($run['issue'] ?? null)) {
        return (int) $run['issue'];
    }

    return preg_match('/(?:^|[^0-9a-z])issue[-_]?(\d+)/i', (string) ($run['branch'] ?? ''), $match) === 1
        ? (int) $match[1]
        : null;
}

/**
 * The short name a run goes by in its heading, its tab and the store index.
 *
 * Runs filed before `title` existed fall back to their branch, never to `headline`: those
 * headlines are the summaries that made the index unreadable in the first place.
 */
function proof_run_title(array $run): string
{
    return (string) ($run['title'] ?? ($run['branch'] ?? 'pipeline run'));
}

function proof_render_run(array $run): string
{
    $title = proof_run_title($run);

    $pr = empty($run['pr'])
        ? 'no PR'
        : proof_render_ref(
            proof_github_url($run, 'pull/' . (int) $run['pr']),
            '#' . (string) $run['pr'] . ' (' . (string) ($run['prState'] ?? '?') . ')',
        );

    $issue = proof_issue_number($run);
    $issueRef = $issue === null ? '' : proof_render_ref(proof_github_url($run, 'issues/' . $issue), 'issue #' . $issue);

    // array_filter drops the issue reference when the run has none, so the separators stay right.
    $meta = implode(' · ', array_filter([
        '<code>' . proof_e((string) ($run['repo'] ?? '')) . '</code>',
        $pr,
        $issueRef,
        '<code>' . proof_e((string) ($run['branch'] ?? '')) . '</code>',
        proof_e((string) ($run['mode'] ?? '')) . ' mode',
        proof_e((string) ($run['updatedAt'] ?? '')),
    ]));

    $body = "<h1>" . proof_e($title) . "</h1>\n<p class=\"meta\">{$meta}</p>\n";

    // A run filed before schema 2 renders as it did: pending lines on a finished old page would claim work is
    // outstanding.
    $current = (int) ($run['schema'] ?? 1) >= 2;
    if ($current) {
        $body .= proof_render_summary($run) . proof_render_explainer($run);
    }

    if (! empty($run['headline'])) {
        $body .= '<p class="lead">' . proof_e((string) $run['headline']) . "</p>\n";
    }

    if (! empty($run['problem'])) {
        $body .= "<h2>Problem</h2>\n" . proof_render_prose((string) $run['problem']);
    }
    if (! empty($run['solution'])) {
        $body .= "<h2>Solution</h2>\n" . proof_render_prose((string) $run['solution']);
    }

    if ($current) {
        $body .= proof_render_tests($run['addedTests'] ?? []);
    }
    $body .= proof_render_shots($run['shots'] ?? []);
    $body .= proof_render_checks($run['checks'] ?? []);
    $body .= proof_render_list('Open questions', $run['openQuestions'] ?? []);
    $body .= proof_render_ledger($run['ledger'] ?? []);
    $body .= "<dialog class=\"zoom\" id=\"zoom\"></dialog>\n<script>\n" . proof_render_script() . "\n</script>\n";

    $styles = proof_render_styles();

    return "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n"
        . "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n"
        . '<title>' . proof_e($title) . "</title>\n<style>\n{$styles}\n</style>\n</head>\n<body>\n"
        . $body
        . "</body>\n</html>\n";
}

/**
 * The index is the join from a PR back to its page — the PR body deliberately carries no
 * local path, so this is how a run is found again.
 *
 * Links are relative to the store root, so the index works when opened over `file://`.
 *
 * @param list<array{dir: string, run: array}> $runs newest first, from `proof_scan_runs()`
 */
function proof_render_index(array $runs): string
{
    $rows = '';
    foreach ($runs as $entry) {
        $run = $entry['run'];
        // The link comes from the directory the run was found in, never from re-deriving a name
        // out of the run: a run filed under an earlier naming scheme has to stay reachable.
        $href = implode('/', array_slice(explode('/', trim((string) $entry['dir'], '/')), -2)) . '/index.html';

        // A run that opened no PR is unreachable by the prune pass by design, so the index
        // is where its accumulation becomes visible rather than silent.
        $pr = empty($run['pr'])
            ? '<span class="flag">no PR — prune manually</span>'
            : proof_e('#' . (string) $run['pr'] . ' ' . (string) ($run['prState'] ?? ''));

        $rows .= '<tr><td><code>' . proof_e((string) ($run['repo'] ?? '')) . '</code></td>'
            . '<td>' . $pr . '</td>'
            . '<td><a href="' . proof_e($href) . '">' . proof_e(proof_run_title($run)) . '</a></td>'
            . '<td>' . proof_e((string) count($run['shots'] ?? [])) . '</td>'
            . '<td>' . proof_e(substr((string) ($run['updatedAt'] ?? ''), 0, 10)) . "</td></tr>\n";
    }

    $body = $rows === ''
        ? "<p class=\"meta\">No runs recorded.</p>\n"
        : "<table>\n<tr><th>Repo</th><th>PR</th><th>Run</th><th>Shots</th><th>Updated</th></tr>\n{$rows}</table>\n";

    $styles = proof_render_styles();

    return "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n"
        . "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n"
        . "<title>Pipeline proof store</title>\n<style>\n{$styles}\n</style>\n</head>\n<body>\n"
        . "<h1>Pipeline proof store</h1>\n"
        . $body
        . "</body>\n</html>\n";
}
