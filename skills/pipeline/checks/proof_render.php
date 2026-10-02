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
  --before:#71717a; --after:#16a34a; --defect:var(--accent);
  --running:var(--muted); --halted:var(--accent); --ready:#2563eb; --merged:var(--after); --closed:var(--muted); }
@media (prefers-color-scheme: dark) {
  :root { --bg:#18181b; --fg:#f4f4f5; --muted:#a1a1aa; --line:#3f3f46; --card:#27272a; --accent:#ef4444;
    --before:#a1a1aa; --after:#22c55e; --ready:#60a5fa; }
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
.back { margin:0 0 .75rem; font-size:.875rem; }
.back a { color:var(--muted); }
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
.status { margin:.25rem 0 .5rem; }
.pill { display:inline-block; padding:0 .55rem; border:1px solid currentColor; border-radius:999px;
  font-size:.75rem; font-weight:700; line-height:1.6; white-space:nowrap; }
.pill-running { color:var(--running); }
.pill-halted { color:var(--halted); }
.pill-ready { color:var(--ready); }
.pill-merged { color:var(--merged); }
.pill-closed { color:var(--closed); }
.reason { color:var(--muted); font-size:.8rem; }
td .reason { display:block; margin-top:.15rem; }
.num { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
tr.workflow th { color:var(--muted); font-weight:600; padding-top:.9rem; }
tr.total th, tr.total td { font-weight:700; border-top:2px solid var(--line); }
.marker { margin-left:.4rem; color:var(--ready); font-size:.7rem; font-weight:700; letter-spacing:.04em; text-transform:uppercase; }
.controls { display:flex; flex-wrap:wrap; align-items:center; gap:.5rem 1rem; margin:1rem 0; color:var(--muted); font-size:.875rem; }
.controls label { display:flex; align-items:center; gap:.4rem; }
.controls select, .controls input[type=search] { font:inherit; color:var(--fg); background:var(--card); border:1px solid var(--line); border-radius:.35rem; padding:.2rem .5rem; }
.controls input[type=search] { flex:1 1 14rem; max-width:24rem; }
.table-wrap { overflow-x:auto; }
th button.sort { font:inherit; color:inherit; background:none; border:0; padding:0; cursor:pointer; }
th[aria-sort=ascending] button.sort::after { content:" ▲"; font-size:.7em; }
th[aria-sort=descending] button.sort::after { content:" ▼"; font-size:.7em; }
body.index { max-width:80rem; }
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
 * The page's one script: the seen write, the copy button, the zoom. The index uses the copy part
 * (`proof_render_copy_script()`) with its own (`proof_render_index_script()`).
 */
function proof_render_script(): string
{
    return proof_render_seen_script() . "\n" . proof_render_copy_script() . "\n" . proof_render_zoom_script();
}

/**
 * Opening a page stores its revision under `seen:<repo>/<run>`, the last two directories of its own path (a trailing
 * `index.html` dropped), which are what the index links to: `file://` is one origin in Chrome, so the index reads it.
 */
function proof_render_seen_script(): string
{
    return <<<'JS'
(function () {
  var revision = document.body.dataset.revision;
  if (!revision) { return; }
  var run = location.pathname.replace(/\/index\.html$/, '').split('/').filter(Boolean).slice(-2).map(decodeURIComponent).join('/');
  try { localStorage.setItem('seen:' + run, revision); } catch (error) {}
})();
JS;
}

/** A `[data-copy]` button copies the text of the element it names: the clipboard API, else a selected textarea and `execCommand('copy')`, which works over `file://`. */
function proof_render_copy_script(): string
{
    return <<<'JS'
(function () {
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
  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-copy]');
    if (!button) { return; }
    copyText(document.getElementById(button.dataset.copy).textContent)
      .then(function () { flash(button, 'Copied'); }, function () { flash(button, 'Copy failed'); });
  });
})();
JS;
}

/** A click on a shot shows a copy of it at natural size in the dialog; Escape, the backdrop or the zoomed shot closes it. */
function proof_render_zoom_script(): string
{
    return <<<'JS'
(function () {
  var dialog = document.getElementById('zoom');
  function zoom(shot) {
    dialog.replaceChildren(shot.cloneNode(true));
    dialog.showModal();
  }
  document.addEventListener('click', function (event) {
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

/** The run's status pill, and for a halted run the reason beside it. */
function proof_render_status(array $run): string
{
    $status = ProofRunStatus::of($run);
    $reason = proof_status_reason($run);

    return '<span class="pill pill-' . $status->value . '">' . proof_e($status->label()) . '</span>'
        . ($reason === '' ? '' : ' <span class="reason">' . proof_e($reason) . '</span>');
}

/**
 * Per step what it took and cost, the workflows in filing order with a row naming each when there is more than one,
 * and the run's totals: the summed spans and the summed weighted cost. Nothing for a run without figures.
 */
function proof_render_cost(array $cost): string
{
    if ($cost === []) {
        return '';
    }
    $named = count($cost) > 1;
    $rows = '';
    foreach ($cost as $workflow) {
        $rows .= $named ? '<tr class="workflow"><th colspan="6">' . proof_e((string) ($workflow['workflow'] ?? '')) . "</th></tr>\n" : '';
        $rows .= implode('', array_map(proof_render_cost_row(...), $workflow['steps'] ?? []));
    }
    $totals = proof_cost_totals($cost);

    return "<h2>Time and cost</h2>\n<table>\n"
        . '<thead><tr><th>Step</th><th>Models</th><th class="num">Minutes</th><th class="num">Waiting on tools</th>'
        . "<th class=\"num\">Weighted cost</th><th class=\"num\">Peak context</th></tr></thead>\n<tbody>\n"
        . $rows
        . '<tr class="total"><th>Total</th><td></td><td class="num">' . proof_minutes($totals['seconds']) . '</td><td></td>'
        . '<td class="num">' . proof_millions($totals['cost']) . "</td><td></td></tr>\n"
        . "</tbody>\n</table>\n"
        . "<p class=\"meta\">Minutes are each step's wall time; the total is the summed spans of the run's workflows. The weighted cost is the proxy <code>run_cost.php</code> defines, not money.</p>\n";
}

function proof_render_cost_row(array $step): string
{
    return '<tr><th>' . proof_e((string) ($step['label'] ?? '')) . '</th>'
        . '<td>' . proof_e(implode('+', $step['models'] ?? [])) . '</td>'
        . '<td class="num">' . proof_minutes((float) ($step['wall'] ?? 0)) . '</td>'
        . '<td class="num">' . proof_minutes((float) ($step['waiting'] ?? 0)) . '</td>'
        . '<td class="num">' . proof_millions((float) ($step['cost'] ?? 0)) . '</td>'
        . '<td class="num">' . intdiv((int) ($step['peak'] ?? 0), 1000) . "k</td></tr>\n";
}

/** Seconds as minutes, one decimal, as `run_cost_cli.php` prints them: `20.0 min`. */
function proof_minutes(float $seconds): string
{
    return sprintf('%.1f min', $seconds / 60);
}

/** A weighted cost in millions, two decimals, as `run_cost_cli.php` prints it: `2.31M`. */
function proof_millions(float $cost): string
{
    return sprintf('%.2fM', $cost / 1e6);
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
        isset($run['revision']) ? 'revision ' . (int) $run['revision'] : '',
    ]));

    // Every run page sits at <root>/<repo>/<run>/index.html, so the store index is always two levels up.
    $body = "<nav class=\"back\" aria-label=\"Proof store\"><a href=\"../../index.html\">← All proofs</a></nav>\n"
        . '<h1>' . proof_e($title) . "</h1>\n<p class=\"status\">" . proof_render_status($run) . "</p>\n<p class=\"meta\">{$meta}</p>\n";

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
    $body .= proof_render_cost($run['cost'] ?? []);
    $body .= proof_render_ledger($run['ledger'] ?? []);
    $body .= "<dialog class=\"zoom\" id=\"zoom\"></dialog>\n<script>\n" . proof_render_script() . "\n</script>\n";

    $styles = proof_render_styles();
    $revision = isset($run['revision']) ? ' data-revision="' . (int) $run['revision'] . '"' : '';

    return "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n"
        . "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n"
        . '<title>' . proof_e($title) . "</title>\n<style>\n{$styles}\n</style>\n</head>\n<body{$revision}>\n"
        . $body
        . "</body>\n</html>\n";
}

/**
 * The index is the join from a PR back to its page — the PR body deliberately carries no
 * local path, so this is how a run is found again.
 *
 * Links are relative to the store root, so the index works when opened over `file://`. Left open, it shows in its
 * tab what changed, from the `status.js` beside it (`proof_render_index_script()`); an empty store's page polls too.
 *
 * @param list<array{dir: string, run: array}> $runs in any order: it is ordered here
 */
function proof_render_index(array $runs): string
{
    $runs = proof_index_order($runs);
    $body = $runs === []
        ? "<p class=\"meta\">No runs recorded.</p>\n"
        : proof_render_index_controls($runs)
            . "<div class=\"table-wrap\">\n<table id=\"runs\">\n" . proof_render_index_head() . "<tbody>\n"
            . implode('', array_map(proof_render_index_row(...), $runs))
            . "</tbody>\n</table>\n</div>\n<p id=\"no-match\" class=\"meta\" hidden>No runs match.</p>\n";
    $script = "<script>\n" . proof_render_copy_script() . "\n" . proof_render_index_script() . "\n</script>\n";

    $styles = proof_render_styles();

    return "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n"
        . "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n"
        . "<title>Proofs</title>\n" . proof_render_favicon() . "<style>\n{$styles}\n</style>\n</head>\n<body class=\"index\">\n"
        . "<h1>Pipeline proof store</h1>\n"
        . $body
        . $script
        . "</body>\n</html>\n";
}

/**
 * The tab's icons, one circle each in a 16×16 SVG data URI: red when an unread run is halted, green when one is ready
 * to merge, blue when one is new or updated, and a grey ring when nothing is unread, so a pinned tab always has an
 * icon and never keeps a stale dot. Defined here, only picked by the script.
 *
 * @return array{none: string, halted: string, ready: string, unread: string}
 */
function proof_index_icons(): array
{
    $svg = fn (string $circle): string => 'data:image/svg+xml,' . rawurlencode("<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'>{$circle}</svg>");
    $dot = fn (string $fill): string => $svg("<circle cx='8' cy='8' r='7' fill='{$fill}'/>");

    return [
        'none' => $svg("<circle cx='8' cy='8' r='5.5' fill='none' stroke='#71717a' stroke-width='2'/>"),
        'halted' => $dot('#dc2626'),
        'ready' => $dot('#16a34a'),
        'unread' => $dot('#2563eb'),
    ];
}

/** The favicon link: the ring first, every icon in a `data-` attribute for the script to pick. */
function proof_render_favicon(): string
{
    $icons = proof_index_icons();
    $data = implode('', array_map(fn (string $name, string $uri): string => " data-{$name}=\"" . proof_e($uri) . '"', array_keys($icons), $icons));

    return '<link rel="icon" id="favicon" href="' . proof_e($icons['none']) . "\"{$data}>\n";
}

/**
 * The runs in the index's order: Halted, then Ready, then the rest, each newest first. The index script then moves a
 * Ready run this browser has seen into the rest, which only the browser knows.
 *
 * @param list<array{dir: string, run: array}> $runs
 * @return list<array{dir: string, run: array}>
 */
function proof_index_order(array $runs): array
{
    usort($runs, fn (array $a, array $b): int => [ProofRunStatus::of($a['run'])->group(), proof_updated_time($b['run'])]
        <=> [ProofRunStatus::of($b['run'])->group(), proof_updated_time($a['run'])]);

    return $runs;
}

/** `updatedAt` as a Unix time, 0 when it does not parse: the store holds several offsets, so strings do not compare. */
function proof_updated_time(array $run): int
{
    return strtotime((string) ($run['updatedAt'] ?? '')) ?: 0;
}

/**
 * Above the table: the repo filter (the repos present), the status filter (all five, always), the search, and the
 * toggle that shows the finished runs, with how many there are. The index script applies and remembers them.
 */
function proof_render_index_controls(array $runs): string
{
    $repos = array_values(array_unique(array_filter(array_map(fn (array $entry): string => (string) ($entry['run']['repo'] ?? ''), $runs))));
    sort($repos, SORT_STRING | SORT_FLAG_CASE);
    $statuses = array_combine(
        array_column(ProofRunStatus::cases(), 'value'),
        array_map(fn (ProofRunStatus $status): string => $status->label(), ProofRunStatus::cases()),
    );
    $finished = count(array_filter($runs, fn (array $entry): bool => ProofRunStatus::of($entry['run'])->finished()));

    return "<div class=\"controls\">\n"
        . '<label>Repo <select id="repo-filter"><option value="">All repos</option>' . proof_render_options(array_combine($repos, $repos)) . "</select></label>\n"
        . '<label>Status <select id="status-filter"><option value="">All statuses</option>' . proof_render_options($statuses) . "</select></label>\n"
        . "<input type=\"search\" id=\"search\" placeholder=\"Title, PR, branch or summary\" aria-label=\"Search runs\">\n"
        . "<label><input type=\"checkbox\" id=\"show-finished\"> Show merged and closed (<span id=\"finished-count\">{$finished}</span>)</label>\n"
        . "</div>\n";
}

/** `<option>`s from value => text, both escaped. A numeric repo name arrives as an int key. */
function proof_render_options(array $options): string
{
    return implode('', array_map(
        fn (int|string $value, string $text): string => '<option value="' . proof_e((string) $value) . '">' . proof_e($text) . '</option>',
        array_keys($options),
        $options,
    ));
}

/**
 * The index's columns in order: the label, how the script compares the column (`text` or `number`; null, not
 * sortable), which way a first click sorts it, and whether it is right-aligned. `proof_render_index_row()` renders
 * its cells in this order.
 *
 * @return list<array{label: string, type: ?string, first: ?string, num: bool}>
 */
function proof_index_columns(): array
{
    return [
        ['label' => 'Status', 'type' => 'number', 'first' => 'asc', 'num' => false],
        ['label' => 'Repo', 'type' => 'text', 'first' => 'asc', 'num' => false],
        ['label' => 'PR', 'type' => 'number', 'first' => 'desc', 'num' => false],
        ['label' => 'Run', 'type' => 'text', 'first' => 'asc', 'num' => false],
        ['label' => 'Shots', 'type' => 'number', 'first' => 'desc', 'num' => true],
        ['label' => 'Time', 'type' => 'number', 'first' => 'desc', 'num' => true],
        ['label' => 'Cost', 'type' => 'number', 'first' => 'desc', 'num' => true],
        ['label' => 'Updated', 'type' => 'number', 'first' => 'desc', 'num' => false],
        ['label' => 'Summary', 'type' => null, 'first' => null, 'num' => false],
    ];
}

/** The header row: a sort button in every sortable column's header. */
function proof_render_index_head(): string
{
    $cells = array_map(fn (array $column): string => $column['type'] === null
        ? "<th>{$column['label']}</th>"
        : "<th data-sort-type=\"{$column['type']}\" data-sort-first=\"{$column['first']}\"" . ($column['num'] ? ' class="num"' : '')
            . "><button type=\"button\" class=\"sort\">{$column['label']}</button></th>",
        proof_index_columns());

    return '<thead><tr>' . implode('', $cells) . "</tr></thead>\n";
}

/**
 * `<repo>/<run>`, the last two directories the run was found in: its row's `data-run`, its link, its page's `seen:`
 * key and its `status.js` key. Never re-derived from the run: a run filed under an earlier naming scheme has to stay
 * reachable.
 */
function proof_index_key(array $entry): string
{
    return implode('/', array_slice(explode('/', trim((string) $entry['dir'], '/')), -2));
}

/** The first 12 hex digits of a sha1 over the key and the run as stored: any change to the filed run changes it. */
function proof_index_row_hash(array $entry): string
{
    return substr(sha1(proof_index_key($entry) . "\n" . proof_run_json($entry['run'])), 0, 12);
}

/**
 * `status.js`, which the open index polls (`proof_render_index_script()`): per run in the attention order its key,
 * status, revision (null before revisions existed), hash and row. JSON's default escaping keeps it one valid script
 * whatever a title holds (`/`, U+2028 and U+2029 escaped); it is a file of its own, so no value can end a tag.
 *
 * @param list<array{dir: string, run: array}> $runs
 */
function proof_render_status_js(array $runs): string
{
    $entries = array_map(fn (array $entry): array => [
        'key' => proof_index_key($entry),
        'status' => ProofRunStatus::of($entry['run'])->value,
        'revision' => isset($entry['run']['revision']) ? (int) $entry['run']['revision'] : null,
        'hash' => proof_index_row_hash($entry),
        'row' => proof_render_index_row($entry),
    ], proof_index_order($runs));

    return 'window.proofStatus = ' . json_encode(['runs' => $entries], JSON_INVALID_UTF8_SUBSTITUTE) . ";\n";
}

/**
 * One run: what the index script reads (its hash last), its status, where it lives, its page, its figures, and its
 * summary to copy. `status.js` carries the same text, so a row the script inserts or replaces is this one; its copy
 * target is named by the run, so it never collides with another row's.
 */
function proof_render_index_row(array $entry): string
{
    $run = $entry['run'];
    $key = proof_index_key($entry);
    $status = ProofRunStatus::of($run);
    $repo = (string) ($run['repo'] ?? '');
    $title = proof_run_title($run);
    $shots = count($run['shots'] ?? []);
    $cost = $run['cost'] ?? [];
    $totals = proof_cost_totals($cost);
    $updated = proof_updated_time($run);
    $summary = trim((string) ($run['clientSummary'] ?? ''));

    // The prune pass removes a run that opened no PR two weeks after its last filing, so the index only names it.
    $pr = empty($run['pr'])
        ? '<span class="reason">no PR</span>'
        : proof_render_ref(
            proof_github_url($run, 'pull/' . (int) $run['pr']),
            '#' . (string) $run['pr'] . ' ' . (string) ($run['prState'] ?? ''),
        );

    $data = [
        'run' => $key,
        'repo' => $repo,
        'group' => (string) $status->group(),
        'status' => $status->value,
        'finished' => $status->finished() ? '1' : '0',
        'updated' => (string) ($run['updatedAt'] ?? ''),
        ...(isset($run['revision']) ? ['revision' => (string) (int) $run['revision']] : []),
        'search' => proof_index_search($run),
        'hash' => proof_index_row_hash($entry),
    ];
    $attributes = implode('', array_map(fn (string $name, string $value): string => " data-{$name}=\"" . proof_e($value) . '"', array_keys($data), $data));
    $target = 'summary-' . substr(sha1($key), 0, 8);
    $copy = $summary === ''
        ? ''
        : "<button type=\"button\" class=\"copy\" data-copy=\"{$target}\">Copy</button><span id=\"{$target}\" lang=\"nl\" hidden>" . proof_e($summary) . '</span>';

    return "<tr{$attributes}>"
        . proof_render_index_cell($status->order(), proof_render_status($run))
        . proof_render_index_cell($repo, '<code>' . proof_e($repo) . '</code>')
        . proof_render_index_cell(empty($run['pr']) ? '' : (int) $run['pr'], $pr)
        . proof_render_index_cell($title, '<a href="' . proof_e("{$key}/index.html") . '">' . proof_e($title) . '</a><span class="marker"></span>')
        . proof_render_index_cell($shots, (string) $shots, 'num')
        . proof_render_index_cell($cost === [] ? '' : $totals['seconds'], $cost === [] ? '' : proof_minutes($totals['seconds']), 'num')
        . proof_render_index_cell($cost === [] ? '' : $totals['cost'], $cost === [] ? '' : proof_millions($totals['cost']), 'num')
        . proof_render_index_cell($updated === 0 ? '' : $updated, proof_render_updated($run))
        . "<td>{$copy}</td></tr>\n";
}

/** A sortable cell: its key in `data-sort`, which the index script compares instead of the text the cell shows. */
function proof_render_index_cell(int|float|string $sort, string $content, string $class = ''): string
{
    $attribute = $class === '' ? '' : " class=\"{$class}\"";

    return "<td{$attribute} data-sort=\"" . proof_e((string) $sort) . "\">{$content}</td>";
}

/**
 * When the run was last filed: `d-m H:i` in the timestamp's own offset (PHP's default timezone is UTC here), the
 * full timestamp on hover; the index script rewrites both to the browser's time. Nothing when it does not parse.
 */
function proof_render_updated(array $run): string
{
    if (proof_updated_time($run) === 0) {
        return '';
    }
    $time = new DateTimeImmutable((string) $run['updatedAt']);
    $full = proof_e($time->format(DATE_ATOM));

    return "<time datetime=\"{$full}\" title=\"{$full}\">" . $time->format('d-m H:i') . '</time>';
}

/** What the search matches, lower-cased: the title as the index shows it, `#<pr>`, the branch and the client summary. */
function proof_index_search(array $run): string
{
    $parts = [
        proof_run_title($run),
        empty($run['pr']) ? '' : '#' . (int) $run['pr'],
        (string) ($run['branch'] ?? ''),
        trim((string) ($run['clientSummary'] ?? '')),
    ];

    return mb_strtolower(implode(' ', array_unique(array_filter($parts, fn (string $part): bool => $part !== ''))));
}

/**
 * The index's script. With a table, on `DOMContentLoaded` and again on a `pageshow` from the back/forward cache (Back
 * from a page is how the index is reached again):
 *  - each Updated `<time>` in the browser's time, `dd-mm HH:MM`, the full `YYYY-MM-DD HH:MM:SS` on hover;
 *  - per row with a revision `New` when this browser never opened it, `Updated` when it was filed again since
 *    (`unread()`, the one rule #160 replaces); a seen Ready row drops among the rest (its rank);
 *  - the rows in the attention order (rank, then newest first), or by the column whose header was clicked: its first
 *    direction, reversed by a second click, empty keys last either way, ties in the attention order;
 *  - a row shows when the repo filter, the status filter (or, under All statuses, the toggle) and every search term
 *    let it; `#no-match` when none does;
 *  - the tab (`tab()`): the title counts the unread runs that are not merged or closed, whatever the filters show
 *    (`(2) Proofs`, else `Proofs`), and the favicon is the link's halted, ready, unread or none icon, in that order.
 * It polls `status.js` every 30 seconds, when the tab becomes visible, when the window gains focus and after a
 * `pageshow` from the cache, through a script tag, since `fetch()` is refused over `file://` (`poll()`): a row whose
 * hash changed is replaced, a new run's row inserted and a pruned run's removed, then every row is marked, ordered and
 * filtered again and the tab updated (`apply()`); the page is never reloaded. A failed load changes nothing. Without a
 * table (an empty store) it only polls, and reloads once `status.js` names a run.
 * The repo filter, the status filter and the toggle are remembered (`proof:repo`, `proof:status`, `proof:finished`);
 * the search and the sort are not. Without `localStorage` (a private window, blocked site data) no row is marked,
 * nothing is unread, and every control works unremembered.
 */
function proof_render_index_script(): string
{
    return <<<'JS'
(function () {
  var favicon = document.getElementById('favicon');
  var table = document.getElementById('runs');
  var storage = null;
  try {
    storage = window.localStorage;
    storage.getItem('proof:repo');
  } catch (error) {
    storage = null;
  }
  function poll(apply) {
    window.proofStatus = undefined;
    var script = document.createElement('script');
    script.onload = function () {
      script.remove();
      var answer = window.proofStatus;
      if (answer && Array.isArray(answer.runs)) { apply(answer); }
    };
    script.onerror = function () { script.remove(); };
    script.src = 'status.js?t=' + Date.now();
    document.head.appendChild(script);
  }
  function watch(apply) {
    function check() { poll(apply); }
    setInterval(check, 30000);
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') { check(); } });
    window.addEventListener('focus', check);
    return check;
  }
  if (!table) {
    watch(function (answer) { if (answer.runs.length) { location.reload(); } });
    return;
  }
  var body = table.tBodies[0];
  var headers = Array.prototype.slice.call(table.tHead.rows[0].cells);
  var rows = Array.prototype.slice.call(body.rows);
  var repo = document.getElementById('repo-filter');
  var status = document.getElementById('status-filter');
  var search = document.getElementById('search');
  var finished = document.getElementById('show-finished');
  var finishedCount = document.getElementById('finished-count');
  var empty = document.getElementById('no-match');
  var template = document.createElement('template');
  var sorted = null;
  function remember(key, value) {
    try { if (storage) { storage.setItem(key, value); } } catch (error) {}
  }
  function restore(select, key) {
    var saved = storage.getItem(key);
    if (Array.prototype.some.call(select.options, function (option) { return option.value === saved; })) { select.value = saved; }
  }
  function pad(number) { return String(number).padStart(2, '0'); }
  function local(time) {
    var date = new Date(time.getAttribute('datetime'));
    if (isNaN(date.getTime())) { return; }
    var clock = pad(date.getHours()) + ':' + pad(date.getMinutes());
    time.textContent = pad(date.getDate()) + '-' + pad(date.getMonth() + 1) + ' ' + clock;
    time.title = date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) + ' ' + clock + ':' + pad(date.getSeconds());
  }
  function unread(row) {
    var revision = Number(row.dataset.revision || 0);
    if (!storage || !revision) { return ''; }
    var seen = storage.getItem('seen:' + row.dataset.run);
    return seen === null ? 'New' : Number(seen) < revision ? 'Updated' : '';
  }
  function mark(row) {
    var state = unread(row);
    row.querySelector('.marker').textContent = state;
    row.dataset.rank = state === '' && row.dataset.revision && row.dataset.group === '1' ? '2' : row.dataset.group;
  }
  function attention(a, b) {
    return (Number(a.dataset.rank || a.dataset.group) - Number(b.dataset.rank || b.dataset.group))
      || ((Date.parse(b.dataset.updated) || 0) - (Date.parse(a.dataset.updated) || 0));
  }
  function byColumn(a, b) {
    var x = a.cells[sorted.index].dataset.sort;
    var y = b.cells[sorted.index].dataset.sort;
    if (x === '' || y === '') { return (x === '') - (y === ''); }
    var difference = sorted.type === 'number' ? Number(x) - Number(y) : x.localeCompare(y, undefined, { sensitivity: 'base', numeric: true });
    return sorted.direction === 'asc' ? difference : -difference;
  }
  function order() {
    rows.sort(attention);
    if (sorted) { rows.sort(byColumn); }
    rows.forEach(function (row) { body.appendChild(row); });
    headers.forEach(function (header, index) {
      if (sorted && sorted.index === index) {
        header.setAttribute('aria-sort', sorted.direction === 'asc' ? 'ascending' : 'descending');
      } else {
        header.removeAttribute('aria-sort');
      }
    });
  }
  function visible(row, terms) {
    return (repo.value === '' || row.dataset.repo === repo.value)
      && (status.value === '' ? finished.checked || row.dataset.finished === '0' : row.dataset.status === status.value)
      && terms.every(function (term) { return row.dataset.search.indexOf(term) !== -1; });
  }
  function show() {
    var terms = search.value.toLowerCase().split(/\s+/).filter(Boolean);
    var shown = 0;
    rows.forEach(function (row) {
      row.hidden = !visible(row, terms);
      shown += row.hidden ? 0 : 1;
    });
    empty.hidden = shown > 0;
  }
  function tab() {
    var unseen = rows.filter(function (row) { return row.dataset.finished === '0' && unread(row) !== ''; });
    function has(state) { return unseen.some(function (row) { return row.dataset.status === state; }); }
    var icon = favicon.dataset[has('halted') ? 'halted' : has('ready') ? 'ready' : unseen.length ? 'unread' : 'none'];
    document.title = unseen.length ? '(' + unseen.length + ') Proofs' : 'Proofs';
    if (favicon.getAttribute('href') === icon) { return; }
    var next = favicon.cloneNode();
    next.setAttribute('href', icon);
    favicon.replaceWith(next);
    favicon = next;
  }
  function adopt(html) {
    template.innerHTML = html;
    var row = template.content.firstElementChild;
    row.querySelectorAll('time[datetime]').forEach(local);
    return row;
  }
  function apply(answer) {
    var present = {};
    answer.runs.forEach(function (entry) {
      present[entry.key] = true;
      var current = rows.filter(function (row) { return row.dataset.run === entry.key; })[0];
      if (current && current.dataset.hash === entry.hash) { return; }
      var row = adopt(entry.row);
      if (current) {
        current.replaceWith(row);
        rows[rows.indexOf(current)] = row;
      } else {
        body.appendChild(row);
        rows.push(row);
      }
    });
    rows = rows.filter(function (row) {
      if (present[row.dataset.run]) { return true; }
      row.remove();
      return false;
    });
    if (storage) { rows.forEach(mark); }
    finishedCount.textContent = rows.filter(function (row) { return row.dataset.finished === '1'; }).length;
    order();
    show();
    tab();
  }
  function refresh() {
    table.querySelectorAll('time[datetime]').forEach(local);
    if (storage) {
      rows.forEach(mark);
      restore(repo, 'proof:repo');
      restore(status, 'proof:status');
      finished.checked = storage.getItem('proof:finished') === '1';
    }
    order();
    show();
    tab();
  }
  var check = watch(apply);
  headers.forEach(function (header, index) {
    var button = header.querySelector('button.sort');
    if (!button) { return; }
    button.addEventListener('click', function () {
      var again = sorted && sorted.index === index;
      var direction = again ? (sorted.direction === 'asc' ? 'desc' : 'asc') : header.dataset.sortFirst;
      sorted = { index: index, type: header.dataset.sortType, direction: direction };
      order();
    });
  });
  repo.addEventListener('change', function () { remember('proof:repo', repo.value); show(); });
  status.addEventListener('change', function () { remember('proof:status', status.value); show(); });
  finished.addEventListener('change', function () { remember('proof:finished', finished.checked ? '1' : '0'); show(); });
  search.addEventListener('input', show);
  document.addEventListener('DOMContentLoaded', refresh);
  window.addEventListener('pageshow', function (event) { if (event.persisted) { refresh(); check(); } });
})();
JS;
}
