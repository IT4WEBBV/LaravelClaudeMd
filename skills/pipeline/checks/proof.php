<?php

/**
 * The durable proof store (`../references/engine.md` §The proof store): the run's rules, its directory, its file.
 *
 * Everything here is a *rendering input*. The engine never reads this store to decide which
 * leg runs next, whether a gate passed, or whether to loop back — deleting the whole of
 * `_proofs/` changes no run's behaviour. That is what keeps a durable store compatible with
 * the skill's non-goal: "no persistent state not reconstructable from git + gh".
 */

/**
 * The longest a run's `title` or a shot's `title` may be. A title *names* something — the page
 * heading, the browser tab, the store index — and a sentence of findings stops naming it.
 */
const PROOF_TITLE_MAX = 70;

/** The longest a run's `clientSummary` may be: one to three sentences for an hour registration. */
const PROOF_SUMMARY_MAX = 400;

/** The keys the store owns. A payload's values for them are ignored, and `shotSources` is consumed, never stored. */
const PROOF_STORE_KEYS = ['addedTests', 'schema', 'createdAt', 'updatedAt', 'shotSources'];

/** What a shot shows, set by the step that captured it: the ribbon on the shot. */
enum ProofShotState: string
{
    case Before = 'before';
    case After = 'after';
    case Defect = 'defect';

    public function label(): string
    {
        return match ($this) {
            self::Before => 'Before',
            self::After => 'After',
            self::Defect => 'Defect',
        };
    }

    /** `before, after or defect`: the states as a refusal names them. */
    public static function named(): string
    {
        $values = array_column(self::cases(), 'value');

        return implode(', ', array_slice($values, 0, -1)) . ' or ' . end($values);
    }
}

/**
 * Why a payload cannot be filed, one line per problem; an empty list means it can.
 *
 * Titles are checked here, at filing time, because nothing else stops them growing: each run
 * modelled its payload on the one before, and the headline used as the title went from 84 to 596
 * characters in five runs. The summary belongs in `headline`, a shot's detail in its `caption`,
 * and neither has a limit. Every filed run obeys these, the page `handoff` files included.
 *
 * @return list<string>
 */
function proof_validate_run(array $run): array
{
    $problems = [];

    $title = trim((string) ($run['title'] ?? ''));
    if ($title === '') {
        $problems[] = 'title is missing: name the run in at most ' . PROOF_TITLE_MAX . ' characters, e.g. "PR #430: service logs that follow"';
    } elseif (mb_strlen($title) > PROOF_TITLE_MAX) {
        $problems[] = 'title is ' . mb_strlen($title) . ' characters, at most ' . PROOF_TITLE_MAX . ': move the summary to headline';
    }

    foreach (array_values($run['shots'] ?? []) as $i => $shot) {
        $number = $i + 1;
        $length = mb_strlen(trim((string) ($shot['title'] ?? '')));
        if ($length > PROOF_TITLE_MAX) {
            $problems[] = "shot {$number} title is {$length} characters, at most " . PROOF_TITLE_MAX . ': move the detail to caption';
        }
        $state = proof_shot_state_problem($number, $shot['state'] ?? null);
        if ($state !== null) {
            $problems[] = $state;
        }
    }

    return $problems;
}

function proof_shot_state_problem(int $number, mixed $state): ?string
{
    return match (true) {
        $state === null => "shot {$number} has no state: " . ProofShotState::named(),
        ! is_string($state) || ProofShotState::tryFrom($state) === null
            => "shot {$number} state is " . json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ': ' . ProofShotState::named(),
        default => null,
    };
}

/**
 * What an agent's `write` must leave on the page, beside `proof_validate_run()`: the Dutch client summary and the
 * plain-language explainer. Judged on the run as it will be filed, so a write that leaves them out passes when an
 * earlier write filed them. The page `handoff` files is the one place they may be missing.
 *
 * @return list<string>
 */
function proof_validate_prose(array $run): array
{
    return [...proof_summary_problems($run), ...proof_explainer_problems($run['explainer'] ?? null)];
}

/** @return list<string> */
function proof_summary_problems(array $run): array
{
    $summary = trim((string) ($run['clientSummary'] ?? ''));
    if ($summary === '') {
        return ['clientSummary is missing: one to three Dutch sentences for the hour registration, what the client gets, at most ' . PROOF_SUMMARY_MAX . ' characters'];
    }
    $branch = (string) ($run['branch'] ?? '');
    $length = mb_strlen($summary);
    $reference = preg_match('/#\d+/', $summary, $match) === 1 ? $match[0] : null;

    return array_values(array_filter([
        $length > PROOF_SUMMARY_MAX ? "clientSummary is {$length} characters, at most " . PROOF_SUMMARY_MAX : null,
        $reference === null ? null : "clientSummary holds an issue or PR reference ({$reference}): name what the client gets, in the client's words",
        str_contains($summary, '`') ? 'clientSummary holds a backtick: plain words, no code' : null,
        proof_names_branch($summary, $branch) ? "clientSummary holds the branch name {$branch}" : null,
    ]));
}

/** @return list<string> */
function proof_explainer_problems(mixed $explainer): array
{
    if (! is_array($explainer)) {
        return ['explainer is missing: {problem, solution}, a paragraph each for a reader who knows nothing about the issue'];
    }
    $missing = array_filter(['problem', 'solution'], fn (string $key) => ! is_string($explainer[$key] ?? null) || trim($explainer[$key]) === '');

    return array_values(array_map(fn (string $key) => "explainer.{$key} is missing", $missing));
}

/**
 * Whether `$text` names the branch: whole, or the part after its first `/`, case-insensitive, as a word of its
 * own. Inside a word it does not count: a topic like `ui` would otherwise refuse every *gebruiker*.
 */
function proof_names_branch(string $text, string $branch): bool
{
    $separator = strpos($branch, '/');
    $names = array_filter([$branch, $separator === false ? '' : substr($branch, $separator + 1)], fn (string $name) => $name !== '');

    $named = array_filter($names, fn (string $name) => preg_match('/(?<![\p{L}\p{N}])' . preg_quote($name, '/') . '(?![\p{L}\p{N}])/iu', $text) === 1);

    return $named !== [];
}

/**
 * The run as it will be filed: the stored run with the payload over it, key by key at the top level. A key the
 * payload carries replaces the stored one whole (a list is replaced, never appended to); a key it leaves out is
 * kept. `$defaults` fill only keys the stored run lacks, so `handoff`'s title never replaces one a step wrote.
 * The store's own keys (`PROOF_STORE_KEYS`) are never taken from a payload or a default.
 */
function proof_merge_run(array $stored, array $payload, array $defaults = []): array
{
    $owned = array_flip(PROOF_STORE_KEYS);

    return [...array_diff_key($defaults, $owned), ...$stored, ...array_diff_key($payload, $owned)];
}

/** At most `PROOF_TITLE_MAX` characters: cut at the last word boundary that fits, with `…`. */
function proof_short_title(string $title): string
{
    $title = trim($title);
    if (mb_strlen($title) <= PROOF_TITLE_MAX) {
        return $title;
    }
    $cut = mb_substr($title, 0, PROOF_TITLE_MAX - 1);
    $space = mb_strrpos($cut, ' ');
    $words = $space === false ? $cut : mb_substr($cut, 0, $space);

    return preg_replace('/[\s,;:—-]+$/u', '', $words) . '…';
}

/**
 * The store root. `PIPELINE_PROOF_ROOT` exists so tests never write to the real store —
 * a test that pollutes `~/GitProjects/_proofs` would be indistinguishable from a real run.
 */
function proof_root(): string
{
    $override = getenv('PIPELINE_PROOF_ROOT');
    if (is_string($override) && $override !== '') {
        return rtrim($override, '/');
    }

    return rtrim((string) getenv('HOME'), '/') . '/GitProjects/_proofs';
}

/**
 * Branch (or repo) name → exactly one safe path segment.
 *
 * `/` becomes `-`, matching the changelog-fragment convention in CLAUDE.md. Anything outside
 * `[A-Za-z0-9._-]` follows it, and any surviving run of dots is collapsed: `..` is the one
 * sequence that would let a careless branch name write outside its own directory.
 */
function proof_slug(string $name): string
{
    $slug = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
    $slug = preg_replace('/\.{2,}/', '-', $slug);
    $slug = preg_replace('/-{2,}/', '-', $slug);
    $slug = trim($slug, '-.');

    return $slug === '' ? 'unnamed' : $slug;
}

/**
 * The one path segment a run owns inside its repo's folder.
 *
 * Keyed by PR number, because the PR is what a reader has in hand when they come looking, and
 * it sorts by age. The branch's topic is kept beside it — a bare `967` is sortable but names
 * nothing — minus the `feature/` namespace, which is the same word on nearly every branch, and
 * minus any `issue-919-` marker, because a second number in one segment reads as a second PR.
 *
 * `verify-ui` runs after `handoff`, so a PR normally exists. A run that opened none — a
 * `review-plan` bound-exhaustion halt, or a first write that beat `handoff` — keeps the branch
 * slug, the only name it has. `proof_cli.php write` adopts such a directory once a PR appears.
 */
function proof_run_slug(string $branch, int|string|null $pr = null): string
{
    if (empty($pr) || ! is_numeric($pr)) {
        return proof_slug($branch);
    }

    $separator = strpos($branch, '/');
    $topic = $separator === false ? $branch : substr($branch, $separator + 1);
    $topic = (string) preg_replace('/^issue[-_]?\d+[-_]/i', '', $topic);

    return 'pr-' . (int) $pr . '-' . proof_slug($topic);
}

/**
 * Keyed `<repo>/<run>`, never by the run segment alone — roughly twenty repos share this store
 * and PR numbers collide across them as readily as `feature/fix-typo` ever did.
 */
function proof_run_dir(string $root, string $repo, string $branch, int|string|null $pr = null): string
{
    return rtrim($root, '/') . '/' . proof_slug($repo) . '/' . proof_run_slug($branch, $pr);
}

function proof_read_run(string $dir): ?array
{
    $path = rtrim($dir, '/') . '/run.json';
    if (! is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * Write `run.json` as schema 2, preserving `createdAt`. The run is already merged (`proof_merge_run()`): three
 * points write it, `handoff` files the page, `verify-ui` adds the shots, the finish step finalises it.
 *
 * `$now` is a parameter rather than a call to `time()` so the round-trip is testable without
 * a clock and a run's timestamps can be made to match the leg that produced them.
 *
 * @return array the run as written, including the fields this function fills in
 */
function proof_write_run(string $dir, array $run, string $now): array
{
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $existing = proof_read_run($dir);

    $run['schema'] = 2;
    $run['createdAt'] = $existing['createdAt'] ?? $now;
    $run['updatedAt'] = $now;

    file_put_contents(
        rtrim($dir, '/') . '/run.json',
        json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
    );

    return $run;
}

/**
 * Pure predicate — no filesystem, no `gh`, no clock.
 *
 * Two rules the store depends on, both stated in the spec's §Retention:
 *  - a PR merged this morning is exactly the one still worth looking at this afternoon,
 *    hence the grace period rather than deleting the moment it closes;
 *  - a run that opened no PR is never auto-pruned. `review-plan` bound-exhaustion halts
 *    *before* `handoff` and deliberately opens none, so those runs exist; the index flags
 *    them for manual pruning rather than a silent cap deleting them.
 *
 * Anything unparseable answers "do not prune". Deleting proof is irreversible; keeping it
 * costs disk.
 */
function proof_should_prune(array $run, string $now, int $graceDays = 14): bool
{
    if (empty($run['pr'])) {
        return false;
    }
    if (! in_array($run['prState'] ?? '', ['MERGED', 'CLOSED'], true)) {
        return false;
    }

    $updated = strtotime((string) ($run['updatedAt'] ?? ''));
    $nowTs = strtotime($now);
    if ($updated === false || $nowTs === false) {
        return false;
    }

    return $updated < $nowTs - $graceDays * 86400;
}

/**
 * Every run in the store, newest first. Shape is fixed at `<root>/<repo>/<run>/run.json`,
 * so one glob covers the whole store — and it is blind to how the run segment was named, which
 * is what lets `prune` reach runs filed under an earlier scheme.
 *
 * @return list<array{dir: string, run: array}>
 */
function proof_scan_runs(string $root): array
{
    $runs = [];
    foreach (glob(rtrim($root, '/') . '/*/*/run.json') ?: [] as $path) {
        $dir = dirname($path);
        $run = proof_read_run($dir);
        if ($run !== null) {
            $runs[] = ['dir' => $dir, 'run' => $run];
        }
    }

    usort($runs, fn ($a, $b) => strcmp((string) ($b['run']['updatedAt'] ?? ''), (string) ($a['run']['updatedAt'] ?? '')));

    return $runs;
}

/**
 * The argv for opening a finished run's page in the desktop browser.
 *
 * An **array**, never a shell string. The page path is derived from repo/branch/PR values that
 * reach this store from a JSON payload, so it is untrusted input: `proof_cli.php` hands this
 * array straight to `proc_open()`, which executes an array form *without a shell*, and a path
 * holding a space, a quote or a `;` therefore stays one argument and can never become a second
 * command. Nothing here escapes or interpolates, because nothing here builds a command line.
 *
 * Returns null whenever there is nothing to do, which is never an error — opening is cosmetic:
 *  - **no page** — a run that halted before `handoff` has none;
 *  - **suppressed** — `PIPELINE_NO_OPEN` is set to anything but `0`, for headless, CI and
 *    unattended batch runs;
 *  - **no opener** — a platform this does not know how to open on.
 *
 * `PIPELINE_OPEN_CMD` overrides the platform default with an executable that receives the page
 * path as its single argument. It is the portability escape hatch (an unknown platform, a
 * specific browser via a one-line wrapper) and the seam the tests stub, so no suite ever pops
 * a browser window.
 */
function proof_open_argv(?string $path, string $platform = PHP_OS_FAMILY): ?array
{
    if ($path === null || $path === '' || ! is_file($path)) {
        return null;
    }

    $suppress = (string) getenv('PIPELINE_NO_OPEN');
    if ($suppress !== '' && $suppress !== '0') {
        return null;
    }

    $binary = (string) getenv('PIPELINE_OPEN_CMD');
    if ($binary === '') {
        $binary = match ($platform) {
            'Darwin' => 'open',
            'Linux' => 'xdg-open',
            default => '',
        };
    }

    return $binary === '' ? null : [$binary, $path];
}
