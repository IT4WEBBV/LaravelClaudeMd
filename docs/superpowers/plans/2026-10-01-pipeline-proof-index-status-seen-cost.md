# The proof store index shows each run's status, what changed since the last look, and its time and cost — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every proof page and the store index show the run's status (Running, Halted with the reason, Ready for
review, Merged, Closed), the index marks a run New or Updated since it was last opened and sorts by attention,
filters by repo and copies a run's client summary, and an `autoflow` run's page and index row show its time and cost.

**Architecture:** `proof.php` gains the run's status enum (`ProofRunStatus`), three more store keys (`revision`,
`status`, `cost`), a revision bump per filing and the pure cost merge. `proof_store.php` gains the one way to amend a
filed run without counting it as a filing (`proof_store_amend()`), which `proof_cli.php status`, the prune pass,
`run_cost_cli.php <dir> <page>` and `dispatch_cli.php` (Halted on a recorded halt, Running on a start or resume) all
go through. `proof_render.php` renders the status line, the revision, the seen write and the Time and cost section on
the page, and orders, marks and filters the index (PHP orders by status and date; the index script finishes the order
with the browser's seen state). The docs name who writes each status.

**Tech Stack:** PHP 8.4 (no framework), Pest 4 in `skills/pipeline/checks/tests`, inline CSS and plain JavaScript in
the rendered pages, `localStorage`, `gh` (faked on `PATH` in tests).

**Spec:** `docs/superpowers/specs/2026-10-01-pipeline-proof-index-status-seen-cost-design.md`. Read it with this plan:
the plan argues from it, and its `## Assumptions` 16–24 are the answers this plan assumed.

## Global Constraints

- Everything lives under `skills/`. Run every command from the worktree root
  `/Users/jroelofs/GitProjects/LaravelClaudeMd/LaravelClaudeMd/.claude/worktrees/worktree-issue-142-pipeline-the-proof-store-index-shows-each-run-s`.
- Tests: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
  (append `--filter <Name>` for one file). This repo is not a Docker project: Pest runs on the host. The worktree has
  no `vendor/` yet: run `composer install` once first. The suite needs `node` on `PATH`.
- Test-first: every task writes its test, sees it fail, then writes the code. `php -l` every PHP file you change.
- **No test writes to the real store** (`~/GitProjects/_proofs`): every test that files sets `PIPELINE_PROOF_ROOT`
  to a temp dir or files into a temp store through `proof_test_page()` (Task 1); an amendment writes only into the
  store its page is in (`dirname($page, 3)`), never into `proof_root()`.
- Statuses, verbatim: values `running`, `halted`, `ready`, `merged`, `closed`; labels `Running`, `Halted`,
  `Ready for review`, `Merged`, `Closed`; `run.json` holds `status: {"state": "<value>"}`, plus `"reason"` only with
  `halted`. Index groups: Halted `0`, Ready `1`, every other `2`.
- Store keys (`PROOF_STORE_KEYS`): `addedTests`, `schema`, `createdAt`, `updatedAt`, `shotSources`, `revision`,
  `status`, `cost`. A payload's values for them are ignored.
- `localStorage` keys: `seen:<repo>/<run>` (the value is the revision), `proof:repo` (the filter's repo, `''` for all).
- Messages, verbatim (each on stderr, one line, exit 0):
  - `proof: status not written: unknown status 'paused': running, halted, ready, merged or closed`
  - `proof: status not written: halted needs --reason <text>`
  - `proof: status not written: no page given`
  - `proof: status not written: no run at <dir>`
  - `proof: status not written: cannot write the run at <dir>`
  - `proof: cost not filed: <problem>`
- Page and index copy, verbatim: `Time and cost`, table heads `Step`, `Models`, `Minutes`, `Waiting on tools`,
  `Weighted cost`, `Peak context`, row `Total`; index heads `Status`, `Repo`, `PR`, `Run`, `Shots`, `Time`, `Cost`,
  `Updated`, `Summary`; filter `Repo` with `All repos`; markers `New`, `Updated`; button `Copy` (`Copied`,
  `Copy failed`); meta `revision <n>`. Numbers: minutes `%.1f min`, cost `%.2fM`, peak `<n>k`.
- `dispatch_cli.php`'s stdout stays one JSON line: a status write never prints there and never halts; its problems go
  to stderr. `proof_cli.php` and `run_cost_cli.php` exit 0 on every store problem.
- `gh` and every other subprocess run as argv arrays through `proc_open()`, never through a shell.
- Pages still open over `file://` with no external asset: styles and scripts inline. Every interpolated value goes
  through `proof_e()`.
- Native backed enums, never string constants; guard clauses; full type hints.
- No changelog entry: the repo has no `.changelog/` and no `CHANGELOG.md`.
- Commits: no `Co-Authored-By`, no AI attribution; every message ends on `(#142)`.

## File Structure

| File | Responsibility |
|---|---|
| `skills/pipeline/checks/proof.php` (modify) | trait `ProofNamedCases`; enum `ProofRunStatus`; `proof_status_reason()`; `PROOF_STORE_KEYS`; `proof_run_json()`; `proof_write_run()` bumps `revision` and fills `status`; `proof_add_cost()`, `proof_cost_totals()` |
| `skills/pipeline/checks/proof_render.php` (modify) | styles; status line, revision, seen write, Time and cost on the page; the script split; the index's order, filter, rows, columns and script |
| `skills/pipeline/checks/proof_store.php` (modify) | `proof_store_amend()`, `proof_store_status()` |
| `skills/pipeline/checks/proof_cli.php` (modify) | `status` subcommand; the prune pass asks `gh` for `state,isDraft` and amends through the store |
| `skills/pipeline/checks/run_cost.php` (modify) | `pipeline_run_cost_record()` |
| `skills/pipeline/checks/run_cost_cli.php` (modify) | an optional page argument files the record |
| `skills/pipeline/checks/dispatch_cli.php` (modify) | `dispatch_cli_proof_status()`; Halted in `dispatch_cli_halt()`, Running in `next` and `launch`, `proof` in `done` |
| `skills/pipeline/checks/brief.php` (modify) | the `interactive` resolve line marks the page ready |
| `skills/pipeline/checks/tests/Pest.php` (modify) | `proof_test_page()` |
| `skills/pipeline/checks/tests/ProofTest.php`, `ProofWriteTest.php`, `HandoffCliTest.php` (modify) | status, revision, cost merge |
| `skills/pipeline/checks/tests/ProofRenderTest.php` (modify) | the page and the index |
| `skills/pipeline/checks/tests/ProofStatusTest.php` (create) | `status`, the amendment, the prune pass |
| `skills/pipeline/checks/tests/RunCostTest.php` (modify) | the record and its filing |
| `skills/pipeline/checks/tests/DispatchCliTest.php`, `AutoflowScriptTest.php`, `BriefTest.php`, `LockStepTest.php` (modify) | the commands' writes, `done`'s `proof`, the brief line, the doc lock-step |
| `skills/pipeline/references/engine.md`, `skills/pipeline/SKILL.md`, `skills/orchestrate/SKILL.md`, `skills/orchestrate/references/commands.md` (modify) | who writes each status; the index; the cost filing |

## Review Focus

Inputs the spec implies and its test list does not name, most likely first. Each has its test in the task that owns
the code.

1. **`updatedAt` values with different offsets** (`2026-09-30T23:30:00-05:00` beside `2026-10-01T02:00:00Z`, both in
   the real store). Expected: the newer *time* sorts first, though its string sorts second. Test in Task 3.
2. **A page whose `run.json` cannot be written** when a command marks it (a read-only file, a moved store).
   Expected: `status` says `cannot write the run at <dir>` on stderr and exits 0; `dispatch_cli.php finish` still
   prints exactly its one JSON line and records the halt. Tests in Tasks 4 and 6.
3. **Markup in a halt reason, a repo, a summary, a workflow name, a step label or a model.** Expected: escaped on the
   page and in the index; the copy button copies the summary as written (it reads `textContent`). Tests in Tasks 2
   and 3.
4. **An old run filed again** (no `status`, `prState: MERGED`). Expected: it keeps reading Merged, not Running
   (Assumption 16); and a later agent `write` never resets a stored Halted. Tests in Task 1.
5. **A run page reached at its directory URL** (`…/Deploy/pr-5-logs/`, which `php -S` and some links serve).
   Expected: the same `seen:Deploy/pr-5-logs` key as `…/index.html`. Checked in the browser in Task 8.

---

### Task 1: The run's status, revision and cost in `proof.php`

**Files:**
- Modify: `skills/pipeline/checks/proof.php` (the `PROOF_STORE_KEYS` const at line 22, `ProofShotState` at 24–47,
  `proof_write_run()` at 239–255)
- Modify: `skills/pipeline/checks/tests/Pest.php`
- Test: `skills/pipeline/checks/tests/ProofTest.php`, `ProofWriteTest.php`, `HandoffCliTest.php`

**Interfaces:**
- Produces: `trait ProofNamedCases` (`static named(): string`); `enum ProofRunStatus: string` (`Running`, `Halted`,
  `Ready`, `Merged`, `Closed`) with `label(): string`, `group(): int`, `static of(array $run): self`,
  `corrected(string $prState, bool $isDraft): self`, `stored(string $reason = ''): array`;
  `proof_status_reason(array $run): string`; `proof_run_json(array $run): string`;
  `proof_add_cost(array $run, array $record): array`; `proof_cost_totals(array $cost): array{seconds: float, cost: float}`;
  `proof_write_run()` (signature unchanged) now writes `revision` and a `status`; the test helper
  `proof_test_page(array $run = []): string` (a page path in a fresh temp store, run filed at `date('c')`).

- [ ] **Step 1: Write the failing tests**

Append to `tests/ProofTest.php`:

```php
it('reads a run\'s status from the store, else from its PR state', function (array $run, ProofRunStatus $status) {
    expect(ProofRunStatus::of($run))->toBe($status);
})->with([
    'stored' => [['status' => ['state' => 'halted', 'reason' => 'x'], 'prState' => 'MERGED'], ProofRunStatus::Halted],
    'an older merged run' => [['prState' => 'MERGED'], ProofRunStatus::Merged],
    'an older closed run' => [['prState' => 'CLOSED'], ProofRunStatus::Closed],
    'an older open run' => [['prState' => 'OPEN'], ProofRunStatus::Running],
    'a run without a PR' => [[], ProofRunStatus::Running],
    'an unknown stored state' => [['status' => ['state' => 'paused'], 'prState' => 'MERGED'], ProofRunStatus::Merged],
    'a status that is no object' => [['status' => 'halted'], ProofRunStatus::Running],
]);

it('corrects a stored status by what gh says the PR is', function (ProofRunStatus $stored, string $state, bool $draft, ProofRunStatus $corrected) {
    expect($stored->corrected($state, $draft))->toBe($corrected);
})->with([
    'merged' => [ProofRunStatus::Running, 'MERGED', false, ProofRunStatus::Merged],
    'closed' => [ProofRunStatus::Ready, 'CLOSED', false, ProofRunStatus::Closed],
    'open and ready' => [ProofRunStatus::Running, 'OPEN', false, ProofRunStatus::Ready],
    'a halted run whose PR went ready' => [ProofRunStatus::Halted, 'OPEN', false, ProofRunStatus::Ready],
    'an open draft keeps Running' => [ProofRunStatus::Running, 'OPEN', true, ProofRunStatus::Running],
    'an open draft keeps Halted' => [ProofRunStatus::Halted, 'OPEN', true, ProofRunStatus::Halted],
    'a Ready PR put back in draft' => [ProofRunStatus::Ready, 'OPEN', true, ProofRunStatus::Running],
    'an unknown state keeps the stored one' => [ProofRunStatus::Halted, 'WEIRD', false, ProofRunStatus::Halted],
]);

it('orders the statuses for attention, labels them, and stores the reason only with Halted', function () {
    expect(array_map(fn (ProofRunStatus $status) => $status->group(), ProofRunStatus::cases()))->toBe([2, 0, 1, 2, 2]);
    expect(array_map(fn (ProofRunStatus $status) => $status->label(), ProofRunStatus::cases()))
        ->toBe(['Running', 'Halted', 'Ready for review', 'Merged', 'Closed']);
    expect(ProofRunStatus::named())->toBe('running, halted, ready, merged or closed');
    expect(ProofRunStatus::Halted->stored('CI red'))->toBe(['state' => 'halted', 'reason' => 'CI red']);
    expect(ProofRunStatus::Ready->stored('CI red'))->toBe(['state' => 'ready']);
    expect(proof_status_reason(['status' => ['state' => 'halted', 'reason' => 'CI red']]))->toBe('CI red');
    expect(proof_status_reason(['status' => ['state' => 'ready', 'reason' => 'stale']]))->toBe('');
});

it('keeps the store\'s revision, status and cost over a payload\'s', function () {
    $stored = ['title' => 'x', 'revision' => 3, 'status' => ['state' => 'halted', 'reason' => 'r'], 'cost' => [['workflow' => 'wf_a']]];

    expect(proof_merge_run($stored, ['revision' => 99, 'status' => ['state' => 'merged'], 'cost' => [], 'title' => 'y']))
        ->toBe(['title' => 'y', 'revision' => 3, 'status' => ['state' => 'halted', 'reason' => 'r'], 'cost' => [['workflow' => 'wf_a']]]);
});

it('counts every filing in revision and gives a run without a status the one its PR state implies', function () {
    $dir = sys_get_temp_dir() . '/proof-' . uniqid() . '/Deploy/pr-5-logs';

    expect(proof_write_run($dir, ['pr' => 5, 'prState' => 'OPEN'], '2026-10-01T10:00:00+02:00'))
        ->toMatchArray(['revision' => 1, 'status' => ['state' => 'running']]);
    expect(proof_write_run($dir, ['pr' => 5, 'status' => ['state' => 'halted', 'reason' => 'r']], '2026-10-01T11:00:00+02:00'))
        ->toMatchArray(['revision' => 2, 'status' => ['state' => 'halted', 'reason' => 'r']]);

    // An old run filed again keeps reading as what its PR state says (Assumption 16), not Running.
    $old = sys_get_temp_dir() . '/proof-' . uniqid() . '/Deploy/pr-4-old';
    expect(proof_write_run($old, ['pr' => 4, 'prState' => 'MERGED'], '2026-10-01T10:00:00+02:00')['status'])->toBe(['state' => 'merged']);
});

it('files a workflow\'s cost once, replacing its own entry and appending another workflow\'s', function () {
    $first = ['workflow' => 'wf_a', 'span' => 60.0, 'steps' => [['label' => 'implement:run', 'cost' => 1000000.0]]];
    $again = [...$first, 'span' => 90.0];
    $resume = ['workflow' => 'wf_b', 'span' => 30.0, 'steps' => [['label' => 'review-pr:review', 'cost' => 500000.0]]];

    $run = proof_add_cost(['title' => 'x'], $first);
    expect($run['cost'])->toBe([$first]);
    expect(proof_add_cost($run, $again)['cost'])->toBe([$again]);
    expect(proof_add_cost(proof_add_cost($run, $resume), $again)['cost'])->toBe([$again, $resume]);
    expect(proof_cost_totals([$again, $resume]))->toBe(['seconds' => 120.0, 'cost' => 1500000.0]);
    expect(proof_cost_totals([]))->toBe(['seconds' => 0.0, 'cost' => 0.0]);
});
```

Append to `tests/ProofWriteTest.php`:

```php
it('files revision 1 and Running first, then counts each write and never takes a payload\'s status or cost', function () {
    $root = sys_get_temp_dir() . '/proof-write-' . uniqid();
    proof_write_cli(proof_write_payload(), $root);
    expect(proof_write_stored($root))->toMatchArray(['revision' => 1, 'status' => ['state' => 'running']]);

    // A halt recorded since: a later agent write keeps it (spec Assumption 4).
    file_put_contents("{$root}/Deploy/pr-5-logs/run.json", proof_run_json([...proof_write_stored($root), 'status' => ['state' => 'halted', 'reason' => 'CI red']]));
    proof_write_cli(proof_write_payload(['revision' => 40, 'status' => ['state' => 'merged'], 'cost' => [['workflow' => 'wf_x']]]), $root);

    expect(proof_write_stored($root))->toMatchArray(['revision' => 2, 'status' => ['state' => 'halted', 'reason' => 'CI red']]);
    expect(proof_write_stored($root))->not->toHaveKey('cost');
});
```

In `tests/HandoffCliTest.php`, the test `merges over the page on a re-run, keeping the title a step wrote` (line 370):
add `'revision' => 2` to the `json_encode([...])` of the stored run, and `'revision' => 3, 'status' => ['state' => 'running']`
to its `toMatchArray([...])`. The stored run has `pr: 7` and no `prState`, and handoff files `prState: OPEN`, so its
status reads Running.

Append to `tests/Pest.php`:

```php
/**
 * A run filed into a fresh temp store at `<store>/Deploy/pr-5-logs`, its page rendered: the page's path, as
 * `artifacts.proof` holds it. Filed now, so the prune pass's grace period never removes it.
 */
function proof_test_page(array $run = []): string
{
    $dir = sys_get_temp_dir() . '/proof-store-' . uniqid() . '/Deploy/pr-5-logs';
    $filed = proof_write_run($dir, [
        'repo' => 'Deploy', 'branch' => 'feature/logs', 'pr' => 5, 'prState' => 'OPEN', 'title' => 'PR #5: logs that follow', 'schema' => 2,
        ...$run,
    ], date('c'));
    file_put_contents("{$dir}/index.html", proof_render_run($filed));

    return "{$dir}/index.html";
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter 'ProofTest|ProofWriteTest|HandoffCliTest'`
Expected: FAIL with `Class "ProofRunStatus" not found`, `Call to undefined function proof_add_cost()`,
`proof_run_json()`, and the revision assertions.

- [ ] **Step 3: Write the implementation**

In `proof.php`, replace the `PROOF_STORE_KEYS` const:

```php
/**
 * The keys the store owns. A payload's values for them are ignored, and `shotSources` is consumed, never stored.
 * `revision` counts the run's filings, `status` is where the run stands (`ProofRunStatus`), `cost` its time and cost
 * per workflow (`proof_add_cost()`).
 */
const PROOF_STORE_KEYS = ['addedTests', 'schema', 'createdAt', 'updatedAt', 'shotSources', 'revision', 'status', 'cost'];

/** `a, b or c`: an enum's values as a refusal names them. */
trait ProofNamedCases
{
    public static function named(): string
    {
        $values = array_column(self::cases(), 'value');

        return implode(', ', array_slice($values, 0, -1)) . ' or ' . end($values);
    }
}
```

In `enum ProofShotState`, delete its own `named()` method and its doc comment, and add `use ProofNamedCases;` as the
enum's first line (before `case Before`). Below `ProofShotState`, add:

```php
/**
 * Where a run stands, on its page and in the store index (`../references/engine.md` §The proof store, *who writes
 * each status*). `run.json` holds it as `status: {state, reason}`, the reason only with Halted.
 */
enum ProofRunStatus: string
{
    use ProofNamedCases;

    case Running = 'running';
    case Halted = 'halted';
    case Ready = 'ready';
    case Merged = 'merged';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Running',
            self::Halted => 'Halted',
            self::Ready => 'Ready for review',
            self::Merged => 'Merged',
            self::Closed => 'Closed',
        };
    }

    /** The index's attention order: a halted run first, then one ready for review, then the rest. */
    public function group(): int
    {
        return match ($this) {
            self::Halted => 0,
            self::Ready => 1,
            default => 2,
        };
    }

    /** The stored status, else what an older run's `prState` implies: MERGED, CLOSED, else Running. */
    public static function of(array $run): self
    {
        return self::tryFrom((string) ($run['status']['state'] ?? '')) ?? match ($run['prState'] ?? null) {
            'MERGED' => self::Merged,
            'CLOSED' => self::Closed,
            default => self::Running,
        };
    }

    /**
     * What `gh` says the PR is, over this stored status (the prune pass). Merged, closed and an open ready PR are
     * GitHub's to say; an open draft keeps Running or Halted, which GitHub cannot see, and turns anything else back
     * into Running (a PR put back in draft by `gh pr ready --undo`). Any other state keeps the stored status.
     */
    public function corrected(string $prState, bool $isDraft): self
    {
        return match (true) {
            $prState === 'MERGED' => self::Merged,
            $prState === 'CLOSED' => self::Closed,
            $prState === 'OPEN' && ! $isDraft => self::Ready,
            $prState === 'OPEN' => in_array($this, [self::Running, self::Halted], true) ? $this : self::Running,
            default => $this,
        };
    }

    /** As `run.json` holds it: the reason only with Halted. */
    public function stored(string $reason = ''): array
    {
        return $this === self::Halted ? ['state' => $this->value, 'reason' => $reason] : ['state' => $this->value];
    }
}

/** Why the run halted, as its stored status says; empty for any other status. */
function proof_status_reason(array $run): string
{
    return ProofRunStatus::of($run) === ProofRunStatus::Halted ? (string) ($run['status']['reason'] ?? '') : '';
}
```

Replace `proof_write_run()` (keep its doc comment, adding the last paragraph) and add `proof_run_json()` after it:

```php
/**
 * Write `run.json` as schema 2, preserving `createdAt`. The run is already merged (`proof_merge_run()`): three
 * points write it, `handoff` files the page, `verify-ui` adds the shots, the finish step finalises it.
 *
 * `$now` is a parameter rather than a call to `time()` so the round-trip is testable without
 * a clock and a run's timestamps can be made to match the leg that produced them.
 *
 * Every filing counts in `revision`, which the index compares with the revision a browser last opened. A run without
 * a status gets the one its PR state implies (`ProofRunStatus::of()`); a stored one is never reset by a filing.
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
    $run['revision'] = (int) ($existing['revision'] ?? 0) + 1;
    $run['status'] ??= ProofRunStatus::of($run)->stored();

    file_put_contents(rtrim($dir, '/') . '/run.json', proof_run_json($run));

    return $run;
}

/** `run.json`'s text: pretty-printed, slashes unescaped, one trailing newline. */
function proof_run_json(array $run): string
{
    return json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}
```

After `proof_merge_run()`, add:

```php
/**
 * `$run` with one workflow's time and cost filed (`pipeline_run_cost_record()`): it replaces the entry of the same
 * `workflow`, else it is appended, so filing the same transcript dir twice changes nothing and a resume or a CI fix
 * round adds its own.
 */
function proof_add_cost(array $run, array $record): array
{
    $cost = array_values($run['cost'] ?? []);
    $at = array_search($record['workflow'], array_column($cost, 'workflow'), true);
    if ($at === false) {
        $cost[] = $record;
    } else {
        $cost[$at] = $record;
    }

    return [...$run, 'cost' => $cost];
}

/**
 * The run's time and cost: its workflows' summed spans (the idle hours between a halt and its resume are not the
 * run's time) and the summed weighted cost of every step.
 *
 * @return array{seconds: float, cost: float}
 */
function proof_cost_totals(array $cost): array
{
    $steps = array_merge([], ...array_column($cost, 'steps'));

    return [
        'seconds' => (float) array_sum(array_column($cost, 'span')),
        'cost' => (float) array_sum(array_column($steps, 'cost')),
    ];
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php -l skills/pipeline/checks/proof.php && ./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter 'ProofTest|ProofWriteTest|HandoffCliTest|ProofRenderTest'`
Expected: PASS, including the existing `labels each shot state and names them for a refusal` (the trait) and
`round-trips a run and preserves createdAt across the second write`.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/proof.php skills/pipeline/checks/tests/Pest.php skills/pipeline/checks/tests/ProofTest.php skills/pipeline/checks/tests/ProofWriteTest.php skills/pipeline/checks/tests/HandoffCliTest.php
git commit -m "feat(pipeline): a filed run has a status, a revision per filing and its cost per workflow (#142)"
```

---

### Task 2: The page shows the status, the revision and the time and cost, and records itself as seen

**Files:**
- Modify: `skills/pipeline/checks/proof_render.php` (`proof_render_styles()` 21–82, `proof_render_script()` 144–202,
  `proof_render_run()` 380–440)
- Test: `skills/pipeline/checks/tests/ProofRenderTest.php`

**Interfaces:**
- Consumes (Task 1): `ProofRunStatus::of()`, `->label()`, `->value`, `proof_status_reason()`, `proof_cost_totals()`.
- Produces: `proof_render_status(array $run): string` (the pill and, for Halted, `<span class="reason">`);
  `proof_render_cost(array $cost): string`; `proof_minutes(float $seconds): string` (`20.0 min`);
  `proof_millions(float $cost): string` (`2.31M`); `proof_render_copy_script(): string` (the copy button code, its
  own IIFE, used by Task 3's index); `proof_render_script(): string` stays the page's whole script (seen, copy, zoom).

- [ ] **Step 1: Write the failing tests**

Append to `tests/ProofRenderTest.php`:

```php
it('shows the run\'s status between the heading and the meta line', function (array $overrides, string $pill, string $label) {
    $html = proof_render_run(proof_current_run($overrides));

    expect($html)->toContain("<p class=\"status\"><span class=\"pill pill-{$pill}\">{$label}</span></p>");
    expect(strpos($html, '<p class="status">'))->toBeGreaterThan(strpos($html, '</h1>'))->toBeLessThan(strpos($html, '<p class="meta">'));
})->with([
    'stored running' => [['status' => ['state' => 'running']], 'running', 'Running'],
    'stored ready' => [['status' => ['state' => 'ready']], 'ready', 'Ready for review'],
    'stored merged' => [['status' => ['state' => 'merged']], 'merged', 'Merged'],
    'stored closed' => [['status' => ['state' => 'closed']], 'closed', 'Closed'],
    'an older merged run' => [['prState' => 'MERGED'], 'merged', 'Merged'],
    'an older open run' => [[], 'running', 'Running'],
]);

it('puts a halted run\'s reason beside its pill, escaped', function () {
    $html = proof_render_run(proof_current_run(['status' => ['state' => 'halted', 'reason' => 'CI red on <b>abc</b>']]));

    expect($html)->toContain('<p class="status"><span class="pill pill-halted">Halted</span> <span class="reason">CI red on &lt;b&gt;abc&lt;/b&gt;</span></p>');
});

it('names the revision in the meta line and on the body, and leaves both out for a run without one', function () {
    $html = proof_render_run(proof_current_run(['revision' => 3]));
    expect($html)->toContain(' · revision 3</p>')->toContain("<body data-revision=\"3\">\n");

    expect(proof_render_run(proof_current_run()))->not->toContain(' · revision')->toContain("<body>\n");
});

it('records the page as seen at its revision, keyed by its repo and run directories, before the copy and zoom code', function () {
    $html = proof_render_run(proof_current_run(['revision' => 3]));

    expect($html)->toContain("localStorage.setItem('seen:' + run, revision)");
    expect($html)->toContain(".split('/').filter(Boolean).slice(-2)");
    expect(strpos($html, "localStorage.setItem('seen:'"))->toBeLessThan(strpos($html, 'navigator.clipboard.writeText'));
    expect(strpos($html, 'navigator.clipboard.writeText'))->toBeLessThan(strpos($html, 'showModal()'));
});

/** One step's figures as `run_cost_cli.php` files them. */
function proof_cost_step(string $label, float $cost, float $wall = 60.0, array $models = ['opus']): array
{
    return ['label' => $label, 'models' => $models, 'cost' => $cost, 'calls' => 1, 'peak' => 182000, 'wall' => $wall, 'waiting' => 0.0];
}

it('shows each step\'s time and cost after the open questions and before the ledger, with the run\'s totals', function () {
    $html = proof_render_run(proof_current_run([
        'openQuestions' => ['Keep the guard?'],
        'ledger' => [['gate' => 'pr-review', 'outcome' => 'continued', 'note' => 'n']],
        'cost' => [['workflow' => 'wf_a', 'span' => 1200.0, 'steps' => [
            ['label' => 'implement:run', 'models' => ['sonnet'], 'cost' => 2310000.0, 'calls' => 41, 'peak' => 182000, 'wall' => 780.0, 'waiting' => 312.0],
        ]]],
    ]));

    expect($html)->toContain('<h2>Time and cost</h2>');
    expect($html)->toContain('<tr><th>implement:run</th><td>sonnet</td><td class="num">13.0 min</td><td class="num">5.2 min</td><td class="num">2.31M</td><td class="num">182k</td></tr>');
    expect($html)->toContain('<tr class="total"><th>Total</th><td></td><td class="num">20.0 min</td><td></td><td class="num">2.31M</td><td></td></tr>');
    expect($html)->not->toContain('<tr class="workflow">');
    expect(strpos($html, 'Time and cost'))->toBeGreaterThan(strpos($html, 'Keep the guard?'))->toBeLessThan(strpos($html, 'Gate ledger'));
});

it('names each workflow of a run that had several, in filing order, and sums their spans and costs', function () {
    $html = proof_render_run(proof_current_run(['cost' => [
        ['workflow' => 'wf_first', 'span' => 600.0, 'steps' => [proof_cost_step('implement:run', 1000000.0)]],
        ['workflow' => 'wf_fix', 'span' => 300.0, 'steps' => [proof_cost_step('review-pr:review', 500000.0)]],
    ]]));

    expect($html)->toContain('<tr class="workflow"><th colspan="6">wf_first</th></tr>');
    expect(strpos($html, 'wf_fix'))->toBeGreaterThan(strpos($html, 'implement:run'))->toBeLessThan(strpos($html, 'review-pr:review'));
    expect($html)->toContain('<td class="num">15.0 min</td><td></td><td class="num">1.50M</td>');
});

it('has no time and cost section for a run without figures', function () {
    expect(proof_render_run(proof_current_run()))->not->toContain('Time and cost');
});

it('escapes the workflow names, the step labels and the models', function () {
    $html = proof_render_run(proof_current_run(['cost' => [
        ['workflow' => '<i>wf</i>', 'span' => 1.0, 'steps' => [proof_cost_step('<b>step</b>', 1.0, 1.0, ['<s>m</s>'])]],
        ['workflow' => 'wf_b', 'span' => 1.0, 'steps' => []],
    ]]));

    expect($html)->toContain('&lt;i&gt;wf&lt;/i&gt;')->toContain('&lt;b&gt;step&lt;/b&gt;')->toContain('&lt;s&gt;m&lt;/s&gt;');
    expect($html)->not->toContain('<b>step</b>');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter ProofRenderTest`
Expected: FAIL: no `<p class="status">`, no `revision`, no `localStorage`, no `Time and cost`.

- [ ] **Step 3: Write the implementation**

In `proof_render_styles()`, replace the two `:root` blocks with:

```css
:root { --bg:#fff; --fg:#18181b; --muted:#71717a; --line:#e4e4e7; --card:#fafafa; --accent:#dc2626;
  --before:#71717a; --after:#16a34a; --defect:var(--accent);
  --running:var(--muted); --halted:var(--accent); --ready:#2563eb; --merged:var(--after); --closed:var(--muted); }
@media (prefers-color-scheme: dark) {
  :root { --bg:#18181b; --fg:#f4f4f5; --muted:#a1a1aa; --line:#3f3f46; --card:#27272a; --accent:#ef4444;
    --before:#a1a1aa; --after:#22c55e; --ready:#60a5fa; }
}
```

and append, before the closing `CSS;`:

```css
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
.filter { display:flex; align-items:center; gap:.5rem; margin:1rem 0; color:var(--muted); font-size:.875rem; }
.filter select { font:inherit; color:var(--fg); background:var(--card); border:1px solid var(--line); border-radius:.35rem; padding:.2rem .5rem; }
body.index { max-width:80rem; }
```

Replace `proof_render_script()` with the three parts and the page's whole script:

```php
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
```

After `proof_render_ledger()`, add:

```php
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
```

In `proof_render_run()`:

1. In the `$meta` list, after `proof_e((string) ($run['updatedAt'] ?? '')),` add
   `isset($run['revision']) ? 'revision ' . (int) $run['revision'] : '',`.
2. Replace `$body = "<h1>" . proof_e($title) . "</h1>\n<p class=\"meta\">{$meta}</p>\n";` with
   `$body = '<h1>' . proof_e($title) . "</h1>\n<p class=\"status\">" . proof_render_status($run) . "</p>\n<p class=\"meta\">{$meta}</p>\n";`
3. Between `$body .= proof_render_list('Open questions', ...);` and `$body .= proof_render_ledger(...);` add
   `$body .= proof_render_cost($run['cost'] ?? []);`.
4. Before the return, add `$revision = isset($run['revision']) ? ' data-revision="' . (int) $run['revision'] . '"' : '';`
   and in the returned string replace `</head>\n<body>\n` with `</head>\n<body{$revision}>\n`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php -l skills/pipeline/checks/proof_render.php && ./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter ProofRenderTest`
Expected: PASS, the existing `carries the zoom dialog and the copy script inline` included.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/proof_render.php skills/pipeline/checks/tests/ProofRenderTest.php
git commit -m "feat(pipeline): the proof page shows the run's status, revision and time and cost, and records itself as seen (#142)"
```

---

### Task 3: The index orders by attention, marks New and Updated, filters by repo and copies the summary

**Files:**
- Modify: `skills/pipeline/checks/proof_render.php` (`proof_render_index()` 442–484)
- Test: `skills/pipeline/checks/tests/ProofRenderTest.php`

**Interfaces:**
- Consumes (Tasks 1–2): `ProofRunStatus::of()->group()`, `proof_render_status()`, `proof_cost_totals()`,
  `proof_minutes()`, `proof_millions()`, `proof_render_copy_script()`, `proof_run_title()`.
- Produces: `proof_index_order(array $runs): array` (entries `{dir, run}` sorted by group, then `updatedAt` as a time,
  newest first); `proof_updated_time(array $run): int`; `proof_render_index(array $runs): string` (signature
  unchanged); `proof_render_index_filter(array $runs): string`; `proof_render_index_row(int $number, array $entry): string`;
  `proof_render_index_script(): string`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/ProofRenderTest.php`:

```php
/** An index entry for a Deploy run in `/store/Deploy/<name>`. */
function proof_index_entry(string $name, array $run): array
{
    return ['dir' => "/store/Deploy/{$name}", 'run' => proof_fixture_run(['repo' => 'Deploy', ...$run])];
}

it('orders the index by attention: halted, then ready, then the rest, each newest first by time', function () {
    $runs = [
        proof_index_entry('running', ['updatedAt' => '2026-10-01T02:00:00Z']),
        proof_index_entry('ready-old', ['status' => ['state' => 'ready'], 'updatedAt' => '2026-09-20T12:00:00+02:00']),
        // 04:30 UTC on 1 October: newer than `running`, though its string sorts before it.
        proof_index_entry('merged-new', ['status' => ['state' => 'merged'], 'updatedAt' => '2026-09-30T23:30:00-05:00']),
        proof_index_entry('halted-old', ['status' => ['state' => 'halted', 'reason' => 'r'], 'updatedAt' => '2026-09-01T12:00:00+02:00']),
        proof_index_entry('ready-new', ['status' => ['state' => 'ready'], 'updatedAt' => '2026-09-30T09:00:00Z']),
        proof_index_entry('halted-new', ['status' => ['state' => 'halted', 'reason' => 'r'], 'updatedAt' => '2026-09-29T12:00:00+02:00']),
    ];

    expect(array_map(fn (array $entry) => basename($entry['dir']), proof_index_order($runs)))
        ->toBe(['halted-new', 'halted-old', 'ready-new', 'ready-old', 'merged-new', 'running']);

    $html = proof_render_index($runs);
    expect(strpos($html, 'halted-old/index.html'))->toBeLessThan(strpos($html, 'ready-new/index.html'));
    expect(strpos($html, 'ready-old/index.html'))->toBeLessThan(strpos($html, 'merged-new/index.html'));
    expect(strpos($html, 'merged-new/index.html'))->toBeLessThan(strpos($html, '/running/index.html'));
});

it('gives each row what the index script needs, its status, its figures, and a copy button only with a summary', function () {
    $html = proof_render_index([
        proof_index_entry('pr-5-logs', [
            'revision' => 3, 'status' => ['state' => 'ready'], 'updatedAt' => '2026-10-01T10:00:00+02:00',
            'clientSummary' => 'De logboeken lopen mee.',
            'cost' => [['workflow' => 'wf_a', 'span' => 1200.0, 'steps' => [proof_cost_step('implement:run', 2310000.0)]]],
        ]),
        ['dir' => '/store/Asimo/feature-old', 'run' => proof_fixture_run(['repo' => 'Asimo', 'updatedAt' => '2026-09-01T10:00:00+02:00', 'status' => ['state' => 'halted', 'reason' => 'CI red']])],
    ]);

    expect($html)->toContain('<tr data-run="Deploy/pr-5-logs" data-repo="Deploy" data-group="1" data-updated="2026-10-01T10:00:00+02:00" data-revision="3">');
    expect($html)->toContain('<tr data-run="Asimo/feature-old" data-repo="Asimo" data-group="0" data-updated="2026-09-01T10:00:00+02:00">');
    expect($html)->toContain('<td><span class="pill pill-halted">Halted</span> <span class="reason">CI red</span></td>');
    expect($html)->toContain('index.html">PR #412: product summary grid</a><span class="marker"></span></td>');
    expect($html)->toContain('<td class="num">20.0 min</td><td class="num">2.31M</td>');
    expect($html)->toContain('<td class="num"></td><td class="num"></td>');
    expect($html)->toContain('<button type="button" class="copy" data-copy="summary-1">Copy</button><span id="summary-1" lang="nl" hidden>De logboeken lopen mee.</span>');
    expect(substr_count($html, 'class="copy"'))->toBe(1);
    expect($html)->toContain('<select id="repo-filter"><option value="">All repos</option><option value="Asimo">Asimo</option><option value="Deploy">Deploy</option></select>');
    expect($html)->toContain('<th>Status</th><th>Repo</th><th>PR</th><th>Run</th><th class="num">Shots</th><th class="num">Time</th><th class="num">Cost</th><th>Updated</th><th>Summary</th>');
});

it('carries the index script: seen markers, the attention order, the remembered filter and the copy code', function () {
    $html = proof_render_index([proof_index_entry('pr-5-logs', [])]);

    expect($html)->toContain('<body class="index">');
    expect($html)->toContain("storage.getItem('seen:' + row.dataset.run)");
    expect($html)->toContain("storage.setItem('proof:repo', filter.value)");
    expect($html)->toContain("window.addEventListener('pageshow'");
    expect($html)->toContain('navigator.clipboard.writeText');
    expect($html)->not->toContain('showModal()');
    expect(proof_render_index([]))->not->toContain('<script')->not->toContain('repo-filter')->toContain('No runs recorded');
});

it('escapes the repo, the title, the reason and the summary in the index', function () {
    $html = proof_render_index([['dir' => '/store/X/pr-1-x', 'run' => proof_fixture_run([
        'repo' => '<b>R</b>', 'title' => '<i>T</i>', 'status' => ['state' => 'halted', 'reason' => '<u>why</u>'],
        'clientSummary' => 'Klant <b>"blij"</b>', 'updatedAt' => '"><script>',
    ])]]);

    expect($html)->toContain('&lt;b&gt;R&lt;/b&gt;')->toContain('&lt;i&gt;T&lt;/i&gt;')->toContain('&lt;u&gt;why&lt;/u&gt;');
    expect($html)->toContain('Klant &lt;b&gt;&quot;blij&quot;&lt;/b&gt;')->toContain('data-updated="&quot;&gt;&lt;script&gt;"');
    expect($html)->not->toContain('<b>R</b>');
});
```

The row number in `summary-<n>` is the row's position in the ordered list: in the second test the halted Asimo row
sorts first (`0`), the ready Deploy row second (`1`).

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter ProofRenderTest`
Expected: FAIL with `Call to undefined function proof_index_order()` and the missing row attributes.

- [ ] **Step 3: Write the implementation**

Replace `proof_render_index()` (keep its doc comment, adjusting the `@param` line to "in any order: it is ordered
here") with:

```php
function proof_render_index(array $runs): string
{
    $runs = proof_index_order($runs);
    $body = $runs === []
        ? "<p class=\"meta\">No runs recorded.</p>\n"
        : proof_render_index_filter($runs)
            . "<table id=\"runs\">\n<thead><tr><th>Status</th><th>Repo</th><th>PR</th><th>Run</th><th class=\"num\">Shots</th>"
            . "<th class=\"num\">Time</th><th class=\"num\">Cost</th><th>Updated</th><th>Summary</th></tr></thead>\n<tbody>\n"
            . implode('', array_map(proof_render_index_row(...), array_keys($runs), $runs))
            . "</tbody>\n</table>\n<script>\n" . proof_render_copy_script() . "\n" . proof_render_index_script() . "\n</script>\n";

    $styles = proof_render_styles();

    return "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n"
        . "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n"
        . "<title>Pipeline proof store</title>\n<style>\n{$styles}\n</style>\n</head>\n<body class=\"index\">\n"
        . "<h1>Pipeline proof store</h1>\n"
        . $body
        . "</body>\n</html>\n";
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

/** A `<select>` of the repos present, `All repos` first; the index script hides the other repos' rows. */
function proof_render_index_filter(array $runs): string
{
    $repos = array_values(array_unique(array_filter(array_map(fn (array $entry): string => (string) ($entry['run']['repo'] ?? ''), $runs))));
    sort($repos, SORT_STRING | SORT_FLAG_CASE);
    $options = implode('', array_map(fn (string $repo): string => '<option value="' . proof_e($repo) . '">' . proof_e($repo) . '</option>', $repos));

    return "<label class=\"filter\">Repo <select id=\"repo-filter\"><option value=\"\">All repos</option>{$options}</select></label>\n";
}

/** One run: what the index script reads, its status, where it lives, its page, its figures, and its summary to copy. */
function proof_render_index_row(int $number, array $entry): string
{
    $run = $entry['run'];
    // The link comes from the directory the run was found in, never from re-deriving a name out of the run: a run
    // filed under an earlier naming scheme has to stay reachable. The page keys its seen marker on the same segments.
    $key = implode('/', array_slice(explode('/', trim((string) $entry['dir'], '/')), -2));
    $cost = $run['cost'] ?? [];
    $totals = proof_cost_totals($cost);
    $summary = trim((string) ($run['clientSummary'] ?? ''));

    // A run that opened no PR is unreachable by the prune pass by design, so the index is where its accumulation
    // becomes visible rather than silent.
    $pr = empty($run['pr'])
        ? '<span class="flag">no PR — prune manually</span>'
        : proof_e('#' . (string) $run['pr'] . ' ' . (string) ($run['prState'] ?? ''));

    $data = [
        'run' => $key,
        'repo' => (string) ($run['repo'] ?? ''),
        'group' => (string) ProofRunStatus::of($run)->group(),
        'updated' => (string) ($run['updatedAt'] ?? ''),
        ...(isset($run['revision']) ? ['revision' => (string) (int) $run['revision']] : []),
    ];
    $attributes = implode('', array_map(fn (string $name, string $value): string => " data-{$name}=\"" . proof_e($value) . '"', array_keys($data), $data));
    $copy = $summary === ''
        ? ''
        : "<button type=\"button\" class=\"copy\" data-copy=\"summary-{$number}\">Copy</button><span id=\"summary-{$number}\" lang=\"nl\" hidden>" . proof_e($summary) . '</span>';

    return "<tr{$attributes}>"
        . '<td>' . proof_render_status($run) . '</td>'
        . '<td><code>' . proof_e((string) ($run['repo'] ?? '')) . '</code></td>'
        . "<td>{$pr}</td>"
        . '<td><a href="' . proof_e("{$key}/index.html") . '">' . proof_e(proof_run_title($run)) . '</a><span class="marker"></span></td>'
        . '<td class="num">' . count($run['shots'] ?? []) . '</td>'
        . '<td class="num">' . ($cost === [] ? '' : proof_minutes($totals['seconds'])) . '</td>'
        . '<td class="num">' . ($cost === [] ? '' : proof_millions($totals['cost'])) . '</td>'
        . '<td>' . proof_e(substr((string) ($run['updatedAt'] ?? ''), 0, 10)) . '</td>'
        . "<td>{$copy}</td></tr>\n";
}

/**
 * The index's script, on `DOMContentLoaded` and again on a `pageshow` from the back/forward cache (Back from a page is
 * how the index is reached again): per row with a revision `New` when this browser never opened it, `Updated` when it
 * was filed again since; a seen Ready row drops among the rest; the rows re-ordered by that rank and their time; the
 * repo filter applied and remembered. Without `localStorage` (a private window, blocked site data) no row is marked,
 * the order is PHP's, and the filter works without being remembered.
 */
function proof_render_index_script(): string
{
    return <<<'JS'
(function () {
  var body = document.getElementById('runs').tBodies[0];
  var filter = document.getElementById('repo-filter');
  var rows = Array.prototype.slice.call(body.rows);
  var storage = null;
  try {
    storage = window.localStorage;
    storage.getItem('proof:repo');
  } catch (error) {
    storage = null;
  }
  function mark(row) {
    var revision = Number(row.dataset.revision || 0);
    var seen = revision ? storage.getItem('seen:' + row.dataset.run) : null;
    var state = !revision ? '' : seen === null ? 'New' : Number(seen) < revision ? 'Updated' : 'seen';
    row.querySelector('.marker').textContent = state === 'seen' ? '' : state;
    row.dataset.rank = state === 'seen' && row.dataset.group === '1' ? '2' : row.dataset.group;
  }
  function order() {
    rows.sort(function (a, b) {
      return (Number(a.dataset.rank) - Number(b.dataset.rank))
        || ((Date.parse(b.dataset.updated) || 0) - (Date.parse(a.dataset.updated) || 0));
    });
    rows.forEach(function (row) { body.appendChild(row); });
  }
  function show() {
    rows.forEach(function (row) { row.hidden = filter.value !== '' && row.dataset.repo !== filter.value; });
  }
  function refresh() {
    if (storage) {
      rows.forEach(mark);
      order();
      var saved = storage.getItem('proof:repo');
      if (Array.prototype.some.call(filter.options, function (option) { return option.value === saved; })) { filter.value = saved; }
    }
    show();
  }
  filter.addEventListener('change', function () {
    try { if (storage) { storage.setItem('proof:repo', filter.value); } } catch (error) {}
    show();
  });
  document.addEventListener('DOMContentLoaded', refresh);
  window.addEventListener('pageshow', function (event) { if (event.persisted) { refresh(); } });
})();
JS;
}
```

The test string `storage.setItem('proof:repo', filter.value)` is inside the `try` line above; keep it verbatim.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php -l skills/pipeline/checks/proof_render.php && ./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter 'ProofRenderTest|ProofWriteTest'`
Expected: PASS, the existing index tests (relative links, the directory a run was found in, `no PR — prune manually`,
the empty store, the short title, `#412 MERGED`) included.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/proof_render.php skills/pipeline/checks/tests/ProofRenderTest.php
git commit -m "feat(pipeline): the proof store index orders by attention, marks new and updated runs, filters by repo and copies the summary (#142)"
```

---

### Task 4: Amending a filed run: `proof_cli.php status` and the prune pass from `gh`

**Files:**
- Modify: `skills/pipeline/checks/proof_store.php` (append), `skills/pipeline/checks/proof_cli.php` (header comment,
  `proof_cli_pr_state()` 92–113, `proof_cli_prune()` 133–171, the command dispatch 173–176)
- Create: `skills/pipeline/checks/tests/ProofStatusTest.php`

**Interfaces:**
- Consumes (Tasks 1–3): `ProofRunStatus`, `->stored()`, `->corrected()`, `proof_status_reason()`, `proof_run_json()`,
  `proof_read_run()`, `proof_render_run()`, `proof_render_index()`, `proof_scan_runs()`, `proof_test_page()`.
- Produces: `proof_store_amend(string $page, callable $change): ?string` (null, or why nothing was written);
  `proof_store_status(string $page, ProofRunStatus $status, string $reason = ''): ?string`;
  `php proof_cli.php status <page> <running|halted|ready|merged|closed> [--reason <text>]`;
  `proof_cli_pr_view(array $run): ?array{state: string, isDraft: bool}`; `proof_cli_refresh(array $entry): array`.

- [ ] **Step 1: Write the failing tests**

Create `tests/ProofStatusTest.php`:

```php
<?php

/**
 * `proof_cli.php status` and `prune` as a session runs them, as subprocesses: stdout, stderr, the exit code and the
 * files. Every page is filed into its own temp store by `proof_test_page()`.
 */
function proof_status_cli(array $arguments, array $env = []): array
{
    $process = proc_open(
        [PHP_BINARY, __DIR__ . '/../proof_cli.php', ...$arguments],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        sys_get_temp_dir(),
        [...getenv(), 'PIPELINE_PROOF_ROOT' => sys_get_temp_dir() . '/proof-status-' . uniqid(), ...$env],
    );
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

/** A fake `gh` first on PATH that answers `pr view` with `$view`, or fails when it is null. */
function proof_fake_gh(?array $view): array
{
    $bin = sys_get_temp_dir() . '/proof-gh-' . uniqid();
    mkdir($bin);
    file_put_contents("{$bin}/gh", $view === null
        ? "#!/bin/sh\necho 'HTTP 502: Bad Gateway' >&2\nexit 1\n"
        : "#!/bin/sh\necho '" . json_encode($view) . "'\n");
    chmod("{$bin}/gh", 0755);

    return ['PATH' => "{$bin}:" . getenv('PATH')];
}

it('marks a page halted with its reason, leaves revision and updatedAt, and re-renders the page and its store\'s index', function () {
    $page = proof_test_page();
    $before = proof_read_run(dirname($page));

    $result = proof_status_cli(['status', $page, 'halted', '--reason', 'CI red on the head commit']);

    $run = proof_read_run(dirname($page));
    expect($result)->toMatchArray(['code' => 0, 'stdout' => '', 'stderr' => '']);
    expect($run['status'])->toBe(['state' => 'halted', 'reason' => 'CI red on the head commit']);
    expect($run)->toMatchArray(['revision' => $before['revision'], 'updatedAt' => $before['updatedAt']]);
    expect(file_get_contents($page))->toContain('<span class="pill pill-halted">Halted</span> <span class="reason">CI red on the head commit</span>');
    expect(file_get_contents(dirname($page, 3) . '/index.html'))->toContain('pill-halted')->toContain('href="Deploy/pr-5-logs/index.html"');
});

it('drops the reason when a page goes ready, and ignores a reason given with it', function () {
    $page = proof_test_page(['status' => ['state' => 'halted', 'reason' => 'CI red']]);

    proof_status_cli(['status', $page, 'ready', '--reason', 'ignored']);

    expect(proof_read_run(dirname($page))['status'])->toBe(['state' => 'ready']);
});

it('writes nothing, says why and exits 0 for a status it cannot write', function (array $arguments, string $why) {
    $page = proof_test_page();
    $before = file_get_contents(dirname($page) . '/run.json');

    $result = proof_status_cli(array_map(fn (string $argument) => $argument === '<page>' ? $page : $argument, $arguments));

    expect($result)->toBe(['code' => 0, 'stdout' => '', 'stderr' => "proof: status not written: {$why}\n"]);
    expect(file_get_contents(dirname($page) . '/run.json'))->toBe($before);
})->with([
    'an unknown status' => [['status', '<page>', 'paused'], "unknown status 'paused': running, halted, ready, merged or closed"],
    'halted without a reason' => [['status', '<page>', 'halted'], 'halted needs --reason <text>'],
    'halted with a blank reason' => [['status', '<page>', 'halted', '--reason', '  '], 'halted needs --reason <text>'],
    'no page' => [['status', '', 'ready'], 'no page given'],
]);

it('says there is no run beside a page that has none, and creates nothing', function () {
    $missing = sys_get_temp_dir() . '/proof-missing-' . uniqid() . '/Deploy/pr-9-x/index.html';

    expect(proof_status_cli(['status', $missing, 'ready']))
        ->toBe(['code' => 0, 'stdout' => '', 'stderr' => 'proof: status not written: no run at ' . dirname($missing) . "\n"]);
    expect(is_dir(dirname($missing)))->toBeFalse();
});

it('says it cannot write a run whose file is read-only, and exits 0', function () {
    $page = proof_test_page();
    chmod(dirname($page) . '/run.json', 0444);

    $result = proof_status_cli(['status', $page, 'ready']);
    chmod(dirname($page) . '/run.json', 0644);

    expect($result)->toBe(['code' => 0, 'stdout' => '', 'stderr' => 'proof: status not written: cannot write the run at ' . dirname($page) . "\n"]);
    expect(proof_read_run(dirname($page))['status'])->toBe(['state' => 'running']);
});

it('corrects a stale status from gh in the prune pass, keeping updatedAt and the revision', function (array $stored, array $view, array $status) {
    $page = proof_test_page(['nameWithOwner' => 'IT4WEBBV/Deploy', ...$stored]);
    $before = proof_read_run(dirname($page));

    expect(proof_status_cli(['prune'], [...proof_fake_gh($view), 'PIPELINE_PROOF_ROOT' => dirname($page, 3)])['code'])->toBe(0);

    expect(proof_read_run(dirname($page)))->toMatchArray([
        'prState' => $view['state'], 'status' => $status, 'updatedAt' => $before['updatedAt'], 'revision' => $before['revision'],
    ]);
    expect(file_get_contents($page))->toContain('pill-' . $status['state']);
})->with([
    'a draft that went ready' => [['status' => ['state' => 'running']], ['state' => 'OPEN', 'isDraft' => false], ['state' => 'ready']],
    'a stale Ready put back in draft' => [['status' => ['state' => 'ready']], ['state' => 'OPEN', 'isDraft' => true], ['state' => 'running']],
    'a halted draft keeps its reason' => [['status' => ['state' => 'halted', 'reason' => 'CI red']], ['state' => 'OPEN', 'isDraft' => true], ['state' => 'halted', 'reason' => 'CI red']],
    'a merge no session wrote' => [['status' => ['state' => 'ready']], ['state' => 'MERGED', 'isDraft' => false], ['state' => 'merged']],
    'an old run without a status' => [['status' => null], ['state' => 'CLOSED', 'isDraft' => false], ['state' => 'closed']],
]);

it('keeps the stored status and PR state when gh cannot answer', function () {
    $page = proof_test_page(['nameWithOwner' => 'IT4WEBBV/Deploy', 'status' => ['state' => 'ready']]);
    $before = file_get_contents(dirname($page) . '/run.json');

    proof_status_cli(['prune'], [...proof_fake_gh(null), 'PIPELINE_PROOF_ROOT' => dirname($page, 3)]);

    expect(file_get_contents(dirname($page) . '/run.json'))->toBe($before);
});
```

`'status' => null` in the last dataset row: `proof_write_run()`'s `??=` treats null as unset, so the run is filed
with the status its `prState: OPEN` implies, Running, and `gh`'s CLOSED corrects it.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter ProofStatusTest`
Expected: FAIL: `status` prints the usage line and writes nothing; the prune pass never asks for `isDraft`.

- [ ] **Step 3: Write the implementation**

Append to `proof_store.php`:

```php
/**
 * Applies `$change` to the run filed beside `$page`, then re-renders the page and the index of the store the page is
 * in (`dirname($page, 3)`, never `proof_root()`, so a test store and the real one never mix). Not a filing:
 * `updatedAt` and `revision` stay as they are, since the index's Updated counts filings and the prune pass's grace
 * period measures the last one. Never a warning on stdout: a command that amends still prints one answer.
 *
 * @param callable(array): array $change
 * @return ?string null, or why nothing was written
 */
function proof_store_amend(string $page, callable $change): ?string
{
    if ($page === '') {
        return 'no page given';
    }
    $dir = dirname($page);
    $run = proof_read_run($dir);
    if ($run === null) {
        return "no run at {$dir}";
    }
    $run = $change($run);
    $root = dirname($page, 3);
    $written = @file_put_contents("{$dir}/run.json", proof_run_json($run)) !== false
        && @file_put_contents("{$dir}/index.html", proof_render_run($run)) !== false
        && @file_put_contents("{$root}/index.html", proof_render_index(proof_scan_runs($root))) !== false;

    return $written ? null : "cannot write the run at {$dir}";
}

/** Marks the run filed beside `$page` with `$status`; the reason is kept only with Halted. Null, or why not. */
function proof_store_status(string $page, ProofRunStatus $status, string $reason = ''): ?string
{
    return proof_store_amend($page, fn (array $run): array => [...$run, 'status' => $status->stored($reason)]);
}
```

In `proof_cli.php`:

1. In the header comment's command list add `php proof_cli.php status <page.html> <running|halted|ready|merged|closed> [--reason <text>]`
   after the `open` line.
2. After `proof_cli_open()`, add:

```php
/**
 * `status <page> <status> [--reason <text>]`: what a session knows and no command does, right after it made it so:
 * `ready` after `gh pr ready`, `merged` or `closed` when the merge watch answers (`../references/engine.md` §The
 * proof store). Like every store path it logs and returns 0.
 */
function proof_cli_status(array $arguments): int
{
    $page = (string) ($arguments[0] ?? '');
    $state = (string) ($arguments[1] ?? '');
    $reason = ($arguments[2] ?? null) === '--reason' ? trim((string) ($arguments[3] ?? '')) : '';
    $status = ProofRunStatus::tryFrom($state);

    $problem = match (true) {
        $status === null => "unknown status '{$state}': " . ProofRunStatus::named(),
        $status === ProofRunStatus::Halted && $reason === '' => 'halted needs --reason <text>',
        default => proof_store_status($page, $status, $reason),
    };
    if ($problem !== null) {
        fwrite(STDERR, "proof: status not written: {$problem}\n");
    }

    return 0;
}
```

3. Replace `proof_cli_pr_state()` with:

```php
/**
 * `gh`'s answer on the run's PR, or null when there is none to ask about or `gh` cannot answer: the stored state then
 * stands, and a stale `OPEN` only means the run is not pruned this pass, which is the safe direction. An argv array,
 * never a shell string.
 *
 * @return array{state: string, isDraft: bool}|null
 */
function proof_cli_pr_view(array $run): ?array
{
    if (empty($run['pr']) || empty($run['nameWithOwner'])) {
        return null;
    }
    $argv = ['gh', 'pr', 'view', (string) $run['pr'], '--repo', (string) $run['nameWithOwner'], '--json', 'state,isDraft'];
    $process = @proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (! is_resource($process)) {
        return null;
    }
    $out = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $view = proc_close($process) === 0 ? json_decode($out, true) : null;

    return is_array($view) && is_string($view['state'] ?? null) && $view['state'] !== ''
        ? ['state' => $view['state'], 'isDraft' => (bool) ($view['isDraft'] ?? false)]
        : null;
}

/**
 * The run as `gh` sees its PR: its `prState` and its status corrected (`ProofRunStatus::corrected()`), amended into the
 * store when either changed, which re-renders its page so the page and the index agree.
 */
function proof_cli_refresh(array $entry): array
{
    $run = $entry['run'];
    $view = proof_cli_pr_view($run);
    if ($view === null) {
        return $run;
    }
    $stored = ProofRunStatus::of($run);
    $status = $stored->corrected($view['state'], $view['isDraft']);
    if ($view['state'] === ($run['prState'] ?? null) && $status === $stored) {
        return $run;
    }
    $refreshed = [...$run, 'prState' => $view['state'], 'status' => $status->stored(proof_status_reason($run))];
    $problem = proof_store_amend("{$entry['dir']}/index.html", fn (array $filed): array => $refreshed);
    if ($problem !== null) {
        fwrite(STDERR, "proof: {$problem}\n");
    }

    return $refreshed;
}
```

4. In `proof_cli_prune()`, replace the whole `foreach` body with:

```php
        if (proof_should_prune(proof_cli_refresh($entry), $now)) {
            proof_cli_rmdir($entry['dir']);
            $pruned++;
        }
```

5. Before the `prune` branch of the command dispatch, add:

```php
if ($command === 'status') {
    exit(proof_cli_status(array_slice($argv, 2)));
}
```

and make the usage line
`usage: proof_cli.php write <payload.json> | open [<page.html>] | status <page.html> <running|halted|ready|merged|closed> [--reason <text>] | prune`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php -l skills/pipeline/checks/proof_store.php && php -l skills/pipeline/checks/proof_cli.php && ./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter 'ProofStatusTest|ProofWriteTest|ProofOpenTest|ProofTest'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/proof_store.php skills/pipeline/checks/proof_cli.php skills/pipeline/checks/tests/ProofStatusTest.php
git commit -m "feat(pipeline): proof_cli.php status amends a filed run, and the prune pass corrects a stale status from gh (#142)"
```

---

### Task 5: `run_cost_cli.php` files the run's time and cost into its page

**Files:**
- Modify: `skills/pipeline/checks/run_cost.php` (append), `skills/pipeline/checks/run_cost_cli.php`
- Test: `skills/pipeline/checks/tests/RunCostTest.php`

**Interfaces:**
- Consumes (Tasks 1, 4): `proof_add_cost()`, `proof_store_amend()`, `proof_test_page()`; `pipeline_run_seconds()`.
- Produces: `pipeline_run_cost_record(string $workflow, array $steps): array{workflow: string, span: float, steps: list<array{label: string, models: list<string>, cost: float, calls: int, peak: int, wall: float, waiting: float}>}`;
  `php run_cost_cli.php <transcript dir> [<page>]`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/RunCostTest.php`:

```php
it('records a workflow\'s figures for the proof page: its dir name, its span and per step what it prints', function () {
    $steps = [
        ['label' => 'implement:run', 'calls' => 41, 'cost' => 2310000.0, 'peak' => 182000, 'models' => ['sonnet'], 'start' => 100.0, 'end' => 880.0, 'wall' => 780.0, 'waiting' => 312.0],
        ['label' => 'review-pr:review', 'calls' => 3, 'cost' => 10.0, 'peak' => 5, 'models' => [], 'start' => 900.0, 'end' => 1000.0, 'wall' => 100.0, 'waiting' => 0.0],
    ];

    expect(pipeline_run_cost_record('wf_71c2e8b3-c2a', $steps))->toBe(['workflow' => 'wf_71c2e8b3-c2a', 'span' => 900.0, 'steps' => [
        ['label' => 'implement:run', 'models' => ['sonnet'], 'cost' => 2310000.0, 'calls' => 41, 'peak' => 182000, 'wall' => 780.0, 'waiting' => 312.0],
        ['label' => 'review-pr:review', 'models' => [], 'cost' => 10.0, 'calls' => 3, 'peak' => 5, 'wall' => 100.0, 'waiting' => 0.0],
    ]]);
});

it('files the figures into the page it is given, once per workflow, and prints exactly what it printed before', function () {
    $dir = cost_run([
        'a1' => ['implement:run', implode("\n", [
            cost_call('m1', 0, 0, 300000, 2000, null, '10:07:00.000'),
            cost_tool_use('t1', '10:08:00.000'),
            cost_tool_result('t1', '10:20:00.000'),
        ]), ['status' => 'continued']],
    ]);
    $page = proof_test_page();
    $printed = checks_cli('run_cost_cli.php', [$dir])['stdout'];

    expect(checks_cli('run_cost_cli.php', [$dir, $page]))->toBe(['code' => 0, 'stdout' => $printed]);
    checks_cli('run_cost_cli.php', [$dir, $page]);

    $run = proof_read_run(dirname($page));
    expect($run['cost'])->toBe([['workflow' => 'wf_abc-123', 'span' => 780.0, 'steps' => [
        ['label' => 'implement:run', 'models' => [], 'cost' => 40000.0, 'calls' => 1, 'peak' => 300000, 'wall' => 780.0, 'waiting' => 720.0],
    ]]]);
    expect($run['revision'])->toBe(1);
    expect(file_get_contents($page))->toContain('<h2>Time and cost</h2>');
});

it('prints as before and exits 0 given a page with no run beside it, and files nothing for a run it could not measure', function () {
    $dir = cost_run(['a1' => ['implement:run', cost_call('m1', 0, 0, 1000, 10, null, '10:00:00.000'), ['status' => 'continued']]]);
    $missing = sys_get_temp_dir() . '/proof-missing-' . uniqid() . '/Deploy/pr-9-x/index.html';

    expect(checks_cli('run_cost_cli.php', [$dir, $missing]))->toBe(['code' => 0, 'stdout' => checks_cli('run_cost_cli.php', [$dir])['stdout']]);
    expect(is_dir(dirname($missing)))->toBeFalse();

    $page = proof_test_page();
    expect(checks_cli('run_cost_cli.php', ['/nonexistent', $page]))->toBe(['code' => 0, 'stdout' => 'run: not measured (no step transcripts)']);
    expect(proof_read_run(dirname($page)))->not->toHaveKey('cost');
});
```

The expected figures: one call of 300000 cache reads at 0.1 and 2000 output at 5.0 is `40000.0`; its records run from
10:07:00 to 10:20:00 (`780.0` s), the tool call from 10:08 to 10:20 (`720.0` s); `cost_run()` names the dir
`wf_abc-123`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter RunCostTest`
Expected: FAIL with `Call to undefined function pipeline_run_cost_record()` and no `cost` on the page.

- [ ] **Step 3: Write the implementation**

Append to `run_cost.php`:

```php
/**
 * What `run_cost_cli.php` files into the run's proof page for one workflow (`proof_add_cost()`): the transcript dir's
 * name, which keys the entry, the workflow's span, and per step the figures it prints.
 *
 * @param list<array{label: string, calls: int, cost: float, peak: int, models: list<string>, start: ?float, end: ?float, wall: float, waiting: float}> $steps
 * @return array{workflow: string, span: float, steps: list<array{label: string, models: list<string>, cost: float, calls: int, peak: int, wall: float, waiting: float}>}
 */
function pipeline_run_cost_record(string $workflow, array $steps): array
{
    return [
        'workflow' => $workflow,
        'span' => pipeline_run_seconds($steps),
        'steps' => array_map(fn (array $step): array => [
            'label' => $step['label'], 'models' => $step['models'], 'cost' => $step['cost'], 'calls' => $step['calls'],
            'peak' => $step['peak'], 'wall' => $step['wall'], 'waiting' => $step['waiting'],
        ], array_values($steps)),
    ];
}
```

Replace `run_cost_cli.php` with:

```php
<?php

/**
 * After a `/pipeline autoflow` run: per step its weighted cost, peak context, wall time and the part of
 * that spent waiting on tools; for the run the total cost, its span in minutes and the largest step peak.
 *
 *   php run_cost_cli.php <run transcript dir> [<proof page>]
 *
 * The dir is `~/.claude/projects/<project>/<session>/subagents/workflows/wf_<id>/`, named in the
 * Workflow result. Given the run's proof page (`artifacts.proof`) it also files the figures into it, one entry per
 * transcript dir (`proof_add_cost()`), so the page and the store index show them. Always exits 0: cost is reported,
 * never a halt, and a page that cannot take the figures is one line on stderr.
 */

require_once __DIR__ . '/run_cost.php';
require_once __DIR__ . '/proof_store.php';

$dir = rtrim((string) ($argv[1] ?? ''), '/');
$page = (string) ($argv[2] ?? '');
$read = fn (string $path) => is_file($path) ? (string) file_get_contents($path) : '';

$steps = array_map(function (array $step) use ($dir, $read) {
    $transcript = $read("{$dir}/agent-{$step['agent']}.jsonl");

    return ['label' => $step['label'], ...pipeline_transcript_cost($transcript), ...pipeline_transcript_time($transcript)];
}, pipeline_run_journal($read("{$dir}/journal.jsonl")));

echo implode("\n", pipeline_run_cost_lines($steps)), "\n";

if ($page !== '' && $steps !== []) {
    $record = pipeline_run_cost_record(basename($dir), $steps);
    $problem = proof_store_amend($page, fn (array $run): array => proof_add_cost($run, $record));
    if ($problem !== null) {
        fwrite(STDERR, "proof: cost not filed: {$problem}\n");
    }
}
exit(0);
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php -l skills/pipeline/checks/run_cost.php && php -l skills/pipeline/checks/run_cost_cli.php && ./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter RunCostTest`
Expected: PASS, the existing CLI tests included (unchanged output).

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/run_cost.php skills/pipeline/checks/run_cost_cli.php skills/pipeline/checks/tests/RunCostTest.php
git commit -m "feat(pipeline): run_cost_cli.php files a run's time and cost into its proof page (#142)"
```

---

### Task 6: The commands mark the page: Halted on a recorded halt, Running on a start or resume, `proof` on `done`

**Files:**
- Modify: `skills/pipeline/checks/dispatch_cli.php` (`dispatch_cli_halt()` 69–74, `dispatch_cli_next()` 76–96,
  `dispatch_cli_done()` 157–163, `dispatch_cli_launch()` 178–240)
- Test: `skills/pipeline/checks/tests/DispatchCliTest.php`, `skills/pipeline/checks/tests/AutoflowScriptTest.php`

**Interfaces:**
- Consumes (Tasks 1, 4): `ProofRunStatus`, `proof_store_status()`, `proof_test_page()`.
- Produces: `dispatch_cli_proof_status(array $manifest, ProofRunStatus $status, string $reason = ''): void`;
  `dispatch_cli_done()` answers `{"action":"done","proof":<artifacts.proof or null>}`.

- [ ] **Step 1: Write the failing tests**

Update the existing `done` assertions that go through `dispatch_cli_done()`, to
`->toBe(['action' => 'done', 'proof' => null])`:
- `DispatchCliTest.php:137` (`returned` in `records a finished run as done and does not re-dispatch it`; the `next`
  on line 139 keeps `['action' => 'done']`: Assumption 17);
- `DispatchCliTest.php:587` (`finishes only after a review-pr resolve step whose return holds`);
- `AutoflowScriptTest.php:220` and `AutoflowScriptTest.php:493` (the `finish` after the replay; the replay's own
  `$replay['result']` stays `['action' => 'done']`).

Append to `tests/DispatchCliTest.php`:

```php
/** `dispatch_fixture()`'s artifacts with the run's proof page. */
function dispatch_proof_artifacts(string $page): array
{
    return ['artifacts' => ['spec' => null, 'plan' => null, 'pr' => null, 'issue' => null, 'proof' => $page]];
}

it('marks the proof page halted with the reason when finish records a halt', function () {
    $page = proof_test_page();
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'implement', 'status' => 'pending'], ...dispatch_proof_artifacts($page)]);

    $result = dispatch_cli(['finish', $fixture['manifest'], '{"action":"halt","leg":"implement","reason":"the suite stayed red"}']);

    expect($result['stdout'])->toBe("{\"action\":\"halt\",\"reason\":\"the suite stayed red\"}\n");
    expect(proof_read_run(dirname($page))['status'])->toBe(['state' => 'halted', 'reason' => 'the suite stayed red']);
    expect(file_get_contents($page))->toContain('pill-halted');
});

it('marks a resumed run\'s proof page running again when launch starts it', function () {
    $page = proof_test_page(['status' => ['state' => 'halted', 'reason' => 'CI red']]);
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'implement', 'status' => 'halted', 'reason' => 'CI red'], ...dispatch_proof_artifacts($page)]);

    expect(dispatch_cli(['launch', $fixture['manifest'], $fixture['diff']])['json']['action'])->toBe('start');
    expect(proof_read_run(dirname($page))['status'])->toBe(['state' => 'running']);
});

it('marks the proof page running when next dispatches a step', function () {
    $page = proof_test_page(['status' => ['state' => 'halted', 'reason' => 'stub']]);
    $fixture = dispatch_fixture(dispatch_proof_artifacts($page));

    expect(dispatch_cli(['next', $fixture['manifest']])['json']['action'])->toBe('dispatch');
    expect(proof_read_run(dirname($page))['status'])->toBe(['state' => 'running']);
});

it('leaves every page alone when the manifest names none', function () {
    $page = proof_test_page(['status' => ['state' => 'ready']]);
    $before = file_get_contents(dirname($page) . '/run.json');
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'implement', 'status' => 'pending']]);

    expect(dispatch_cli(['finish', $fixture['manifest'], '{"action":"halt","reason":"x"}'])['stdout'])->toBe("{\"action\":\"halt\",\"reason\":\"x\"}\n");
    expect(file_get_contents(dirname($page) . '/run.json'))->toBe($before);
});

it('records the halt and keeps its answer one JSON line when the page it names cannot be amended', function () {
    $fixture = dispatch_fixture(['mode' => 'autoflow', 'cursor' => ['leg' => 'implement', 'status' => 'pending'], ...dispatch_proof_artifacts('/nonexistent/Deploy/pr-5-logs/index.html')]);

    expect(dispatch_cli(['finish', $fixture['manifest'], '{"action":"halt","reason":"x"}']))->toMatchArray(['code' => 0, 'stdout' => "{\"action\":\"halt\",\"reason\":\"x\"}\n"]);
    expect(manifest_read($fixture['manifest'])['cursor'])->toBe(['leg' => 'implement', 'status' => 'halted', 'reason' => 'x']);
});

it('names the proof page in the done answer, so the session can mark it ready', function () {
    $page = proof_test_page();
    $open = ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r'];
    $fixture = dispatch_fixture(['cursor' => ['leg' => 'review-pr', 'status' => 'pending'], 'gate_ledger' => [$open], ...dispatch_proof_artifacts($page)]);
    dispatch_cli(['next', $fixture['manifest']]);
    dispatch_leg_writes($fixture['manifest'], fn (array $m) => [
        ...$m,
        'cursor' => [...$m['cursor'], 'status' => 'continued'],
        'gate_ledger' => [[...$open, 'actions' => [], 'outcome' => 'continued']],
    ]);

    expect(dispatch_cli(['returned', $fixture['manifest'], $fixture['diff']])['json'])->toBe(['action' => 'done', 'proof' => $page]);
});
```

Every fixture leaves `artifacts.pr` null, so no command asks `gh` about a PR.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter 'DispatchCliTest|AutoflowScriptTest'`
Expected: FAIL: the page's status is unchanged, and `done` has no `proof`.

- [ ] **Step 3: Write the implementation**

In `dispatch_cli.php`, after `dispatch_cli_emit()`, add:

```php
/**
 * Marks the run's proof page (`artifacts.proof`) when the manifest names one (`../references/engine.md` §The proof
 * store, *who writes each status*). Never part of the answer and never a halt: a page that cannot be amended is one
 * line on stderr. The engine still never reads the store to decide anything.
 */
function dispatch_cli_proof_status(array $manifest, ProofRunStatus $status, string $reason = ''): void
{
    $page = $manifest['artifacts']['proof'] ?? null;
    if (! is_string($page) || $page === '') {
        return;
    }
    $problem = proof_store_status($page, $status, $reason);
    if ($problem !== null) {
        fwrite(STDERR, "proof: status not written: {$problem}\n");
    }
}
```

`dispatch_cli_halt()` becomes:

```php
function dispatch_cli_halt(string $manifestPath, array $manifest, string $leg, string $reason): array
{
    manifest_write($manifestPath, [...$manifest, 'cursor' => ['leg' => $leg, 'status' => 'halted', 'reason' => $reason]]);
    dispatch_cli_proof_status($manifest, ProofRunStatus::Halted, $reason);

    return pipeline_halt($reason);
}
```

In `dispatch_cli_next()`, replace the last statement with:

```php
    dispatch_cli_proof_status($manifest, ProofRunStatus::Running);

    return dispatch_cli_emit($manifestPath, [...$manifest, 'cursor' => ['leg' => $manifest['cursor']['leg'], 'status' => 'pending']]);
```

`dispatch_cli_done()` becomes (doc comment extended):

```php
/**
 * A finished run says so in its cursor, so a later `next` does not re-dispatch review-pr. The answer names the
 * proof page, so the session that runs `gh pr ready` marks it ready (`proof_cli.php status <page> ready`) without
 * reading the manifest.
 */
function dispatch_cli_done(string $manifestPath, array $manifest): array
{
    manifest_write($manifestPath, [...$manifest, 'cursor' => ['leg' => $manifest['cursor']['leg'], 'status' => 'done']]);

    return ['action' => 'done', 'proof' => $manifest['artifacts']['proof'] ?? null];
}
```

In `dispatch_cli_launch()`, after `$size = dispatch_cli_design_size($manifest);` add
`dispatch_cli_proof_status($manifest, ProofRunStatus::Running);`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php -l skills/pipeline/checks/dispatch_cli.php && ./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter 'DispatchCliTest|AutoflowScriptTest|HandoffCliTest|CiTest'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/dispatch_cli.php skills/pipeline/checks/tests/DispatchCliTest.php skills/pipeline/checks/tests/AutoflowScriptTest.php
git commit -m "feat(pipeline): a recorded halt marks the proof page halted, a start or resume marks it running, and done names the page (#142)"
```

---

### Task 7: Who writes each status: the docs, the `interactive` finish step's brief, the lock-step

**Files:**
- Modify: `skills/pipeline/checks/brief.php` (the `review-pr:resolve` `interactive` line, 109–111)
- Modify: `skills/pipeline/references/engine.md` (§`autoflow`, §After the merge, §The proof store, §Who takes the PR
  out of draft, §The CI gate, §Failure policy), `skills/pipeline/SKILL.md`, `skills/orchestrate/SKILL.md`,
  `skills/orchestrate/references/commands.md`
- Test: `skills/pipeline/checks/tests/BriefTest.php`, `skills/pipeline/checks/tests/LockStepTest.php`

**Interfaces:**
- Consumes: the commands of Tasks 4–6: `proof_cli.php status <page> <status> [--reason <text>]`,
  `run_cost_cli.php <dir> [<page>]`, `finish`'s `{"action":"done","proof":…}`.

- [ ] **Step 1: Write the failing tests**

In `tests/BriefTest.php`, the test `has the interactive finish step run the CI gate before gh pr ready` (line 302):
replace its `toContain(...)` string with

```php
'Run the CI gate (engine.md §The CI gate) and `gh pr ready` when it answers `ready`, then `proof_cli.php status <the path write printed> ready`; show any other answer to the human. After `record`, the last action is `proof_cli.php open`'
```

and in the autoflow test above it (line 295) add `->not->toContain('proof_cli.php status')` to the `$brief` chain.

In `tests/LockStepTest.php`, inside `keeps engine.md §The proof store in lock-step with the fields the store files
and checks`, add before the closing `});`:

```php
    foreach (ProofRunStatus::cases() as $status) {
        expect($section)->toContain("`{$status->value}`");
    }
    expect($section)->toContain('proof_cli.php status <page>');
    expect($section)->toContain('`seen:<repo>/<run>`');
```

(`PROOF_STORE_KEYS` already loops there, so `revision`, `status` and `cost` must appear in backticks too.)

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter 'BriefTest|LockStepTest'`
Expected: FAIL: the brief lacks the status line; engine.md lacks `` `revision` ``, `` `running` ``, the command.

- [ ] **Step 3: Write the brief line and the docs**

`brief.php`, the `interactive` branch of the last `review-pr:resolve` line becomes:

```php
                : 'Run the CI gate (engine.md §The CI gate) and `gh pr ready` when it answers `ready`, then `proof_cli.php status <the path write printed> ready`; show any other answer to the human.')
```

**`skills/pipeline/references/engine.md`:**

1. §`autoflow`, the first code block: after the `finish` line add the comment line
   `# → {"action":"done","proof":<artifacts.proof, or null>} | {"action":"halt","reason":…}`.
2. §`autoflow`, the `finish` bullet: replace `the invoking session runs the CI gate and, on its \`ready\`,
   **\`gh pr ready <pr>\`** (§The CI gate, §Who takes the PR out of draft);` with `the invoking session runs the CI
   gate and, on its \`ready\`, **\`gh pr ready <pr>\`**, then \`proof_cli.php status <proof> ready\` with the \`proof\`
   \`done\` names (§The CI gate, §Who takes the PR out of draft, §The proof store);`.
3. §`autoflow`, the after-run report block: the first line becomes
   `php "$CHECKS/run_cost_cli.php" <the run's transcript dir> [<artifacts.proof>]`, and after the sentence ending
   `…the run's span in minutes and the largest step peak.` add: `Given the run's page (\`artifacts.proof\`, when the
   manifest sets it) it also files those figures into it, so the page and the store index show them (§The proof store).`
4. §After the merge, step 2: replace `**On \`MERGED\`**, run \`orchestrate\`'s §Teardown checks` with
   `**On \`MERGED\`**, first mark the page, \`php "$CHECKS/proof_cli.php" status <artifacts.proof> merged\` (none
   set: skip), then run \`orchestrate\`'s §Teardown checks` (the rest of the step unchanged). Step 3 becomes: `**Closed without merge**:
   \`proof_cli.php status <artifacts.proof> closed\`, and never torn down. Say so in one line; the owner decides.`
5. §The proof store: after the paragraph ending `A store-wide \`index.html\` is the join from a PR back to its page.`
   insert:

```markdown
**Each run has a status**, on its page under the heading and in the index's first column: `running`, `halted`
(with the reason), `ready` (*Ready for review*), `merged`, `closed`. A command writes what it already knows; a
session writes what only it knows, with `proof_cli.php status <page> <status> [--reason <text>]`, right after the
command that made it so:

| Status | Written by | When |
|---|---|---|
| `running` | the store | a filing of a run that has none (`handoff`'s first page): the status its `prState` implies |
| `running` | `dispatch_cli.php launch` (on `start`) and `next` (on a dispatch) | a run starts or resumes, so a resumed halt reads Running again |
| `halted`, with the reason | `dispatch_cli_halt()`: `finish`, `returned`, `brief`'s boundary check, `launch`'s invariant check | the manifest records a halt |
| `ready` | the session that ran `gh pr ready`: the invoking session in `autoflow` (§The CI gate), the finish step in `interactive` | right after `gh pr ready` succeeded: `proof_cli.php status <page> ready` |
| `merged`, `closed` | the session holding the merge watch (§After the merge, `orchestrate` step 6) | the watch prints `MERGED` or `CLOSED`, before any teardown |
| any | the prune pass, after every `write` and on `prune` | `gh pr view --json state,isDraft`: merged, closed and an open ready PR are GitHub's to say; an open draft keeps `running` or `halted`, and turns a stale `ready` back into `running` |

A command writes to `artifacts.proof` only when the manifest sets it, and never changes its answer or halts over it:
a page that cannot be amended is one line on stderr. `finish`'s and `returned`'s `done` carries `proof`
(`artifacts.proof`, or null), the page the session marks ready. A status or a cost written into a filed run is no
filing: `revision` and `updatedAt` stay as they were. A run filed before statuses existed reads as its `prState`
says: `MERGED` Merged, `CLOSED` Closed, else Running.

**The index** lists the runs by attention: `halted` first, then `ready`, then the rest, each newest first. It
filters by repo (remembered per browser), shows per run its status, PR, page, shots, time and cost, and copies its
client summary. **What changed since the last look** is per browser: opening a page stores its `revision` under
`seen:<repo>/<run>` in `localStorage` (`file://` is one origin in Chrome), and the index marks a run never opened
*New*, one filed again since it was opened *Updated*, and drops a seen `ready` run among the rest. A run filed before
`revision` existed gets no marker. Without `localStorage` nothing is marked and the order is the status order.

**Time and cost.** After an `autoflow` run, `run_cost_cli.php <transcript dir> <page>` files its figures into
`cost`, one entry per workflow keyed by the transcript dir's name: filing it again changes nothing, and a resume or a
CI fix round adds its own. The page shows them per step under *Time and cost*, the index the summed spans and cost.
An `interactive` run has none.
```

6. §The proof store, the payload table: add after the `addedTests` row

```markdown
| `revision`, `status`, `cost` | **the store's, never a payload's**: `revision` counts the run's filings (`handoff`'s and every `write`); `status` is `{state, reason}`, the reason only with `halted` (above); `cost` is the figures `run_cost_cli.php` files, per workflow `{workflow, span, steps}` |
```

   and in the `addedTests` row replace `A payload's \`addedTests\`, \`schema\`, \`createdAt\` and \`updatedAt\` are
   ignored` with `A payload's \`addedTests\`, \`schema\`, \`createdAt\`, \`updatedAt\`, \`revision\`, \`status\` and
   \`cost\` are ignored`.
7. §Who takes the PR out of draft, the `autoflow` paragraph: replace `the invoking session runs the CI gate and then
   \`gh pr ready <pr>\` (§The CI gate).` with `the invoking session runs the CI gate and then \`gh pr ready <pr>\`
   (§The CI gate), and marks the proof page ready (\`proof_cli.php status <proof> ready\`, §The proof store).`
8. §The CI gate: `- **\`ready\`** → \`gh pr ready <pr>\`.` becomes `- **\`ready\`** → \`gh pr ready <pr>\`, then
   \`php "$CHECKS/proof_cli.php" status <proof> ready\`, \`<proof>\` the \`proof\` that \`finish\`'s \`done\` named
   (null: nothing to mark).` The `In \`interactive\`` bullet's `\`gh pr ready\` on \`ready\`,` becomes
   `\`gh pr ready\` on \`ready\` and then \`proof_cli.php status <page> ready\`,`.
9. §Failure policy, the *Hard failure* bullet: after `(\`cursor.status: halted\`, \`cursor.reason\`)` insert
   `, which also marks the proof page Halted with that reason when \`artifacts.proof\` is set (§The proof store)`.

**`skills/pipeline/SKILL.md`:**

1. *Visual proof*: after `…writes the Dutch client summary and the plain-language explainer
   (\`references/engine.md\` §The proof store).` add `The page and the store index (\`~/GitProjects/_proofs/index.html\`)
   show each run's status (Running, Halted with its reason, Ready for review, Merged, Closed), mark a run filed
   again since it was last opened, and show an \`autoflow\` run's time and cost per step.`
2. *Cost per run*: `\`checks/run_cost_cli.php\` (cost weighted per model and wall time per step, the run's span, the
   largest step peak)` becomes `\`checks/run_cost_cli.php <dir> <artifacts.proof>\` (cost weighted per model and wall
   time per step, the run's span, the largest step peak; given the page it files them into it)`.
3. Step 5: `**\`ready\`:** \`gh pr ready <pr>\`.` becomes `**\`ready\`:** \`gh pr ready <pr>\`, then
   \`php "$CHECKS/proof_cli.php" status <proof> ready\` with the \`proof\` \`finish\` printed.`
4. Step 6 becomes: `**Report** the result with the two cost-per-run outputs above (\`run_cost_cli.php\` given
   \`artifacts.proof\` files its figures into the page), and arm the merge watch (\`references/engine.md\` §After the
   merge, which marks the page \`merged\` or \`closed\` when it fires).`

**`skills/orchestrate/SKILL.md`:**

1. Step 5: `on its \`ready\` run \`gh pr ready <P>\` yourself;` becomes `on its \`ready\` run \`gh pr ready <P>\`
   yourself, then \`proof_cli.php status <proof> ready\` with the \`proof\` \`finish\` printed (commands §Finish);`.
2. Step 6: after `All hold: **tear down without asking**,` insert `after marking the page \`merged\` (commands
   §Teardown),`; and `A PR **closed without merge** is never torn down or satisfied:` becomes `A PR **closed without
   merge** gets \`closed\` on its page (commands §Teardown) and is never torn down or satisfied:`.

**`skills/orchestrate/references/commands.md`:**

1. §Finish, the code block: after the `gh pr ready <P> -R <repo>` line add
   `php ~/.claude/skills/pipeline/checks/proof_cli.php status <proof> ready              # right after gh pr ready; <proof>: the proof finish printed with done`,
   and the `run_cost_cli.php` line becomes
   `php ~/.claude/skills/pipeline/checks/run_cost_cli.php <the run's transcript dir> <proof>   # <proof>: finish's, else the manifest's artifacts.proof; none: leave it out`.
2. §Teardown: before `Then, from the primary checkout:` add

```markdown
Mark the page before anything is removed (`<proof>`: the `proof` `finish` printed, else
`jq -r '.artifacts.proof // empty' <manifest>`; none: skip):
```bash
php ~/.claude/skills/pipeline/checks/proof_cli.php status <proof> merged
```
A PR closed without merge gets `status <proof> closed` instead, and no teardown.
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php -l skills/pipeline/checks/brief.php && ./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter 'BriefTest|LockStepTest'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/brief.php skills/pipeline/checks/tests/BriefTest.php skills/pipeline/checks/tests/LockStepTest.php skills/pipeline/references/engine.md skills/pipeline/SKILL.md skills/orchestrate/SKILL.md skills/orchestrate/references/commands.md
git commit -m "docs(pipeline): engine.md names who writes each status, the index and the cost filing; sessions mark ready, merged and closed (#142)"
```

---

### Task 8: The whole suite, then the index and a page in the browser

**Files:** none changed unless the check finds a defect (fix it in the task that owns the code, test first, and
commit as that task's follow-up).

- [ ] **Step 1: The whole suite**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
Expected: PASS, no failures.

- [ ] **Step 2: A fixture store, served over one origin**

The Playwright MCP refuses `file:` URLs, so the store is served by `php -S` (one origin, as `file://` is in Chrome).
In one Bash call:

```bash
STORE="$(mktemp -d)/proofs"; echo "$STORE"
PIPELINE_PROOF_ROOT="$STORE" php -r '
require "skills/pipeline/checks/proof_store.php";
$root = getenv("PIPELINE_PROOF_ROOT");
$file = function (string $repo, string $run, int $minutesAgo, array $fields) use ($root) {
    $dir = "{$root}/{$repo}/{$run}";
    $filed = proof_write_run($dir, ["repo" => $repo, "branch" => "feature/{$run}", "schema" => 2, "title" => "{$repo} {$run}", ...$fields], date("c", time() - 60 * $minutesAgo));
    file_put_contents("{$dir}/index.html", proof_render_run($filed));
};
$step = fn (string $label, float $cost) => ["label" => $label, "models" => ["opus"], "cost" => $cost, "calls" => 9, "peak" => 120000, "wall" => 600.0, "waiting" => 120.0];
$file("Deploy", "pr-5-logs", 20, ["pr" => 5, "prState" => "OPEN", "status" => ["state" => "halted", "reason" => "CI red on the head commit after the fix round"]]);
$file("Deploy", "pr-6-ready", 10, ["pr" => 6, "prState" => "OPEN", "status" => ["state" => "ready"], "clientSummary" => "De logboeken lopen nu live mee."]);
$file("Asimo", "pr-7-merged", 5, ["pr" => 7, "prState" => "MERGED", "status" => ["state" => "merged"], "cost" => [
    ["workflow" => "wf_first", "span" => 1500.0, "steps" => [$step("implement:run", 2300000.0)]],
    ["workflow" => "wf_fix", "span" => 600.0, "steps" => [$step("review-pr:review", 700000.0)]],
]]);
file_put_contents("{$root}/index.html", proof_render_index(proof_scan_runs($root)));
'
```

Then start `php -S 127.0.0.1:8765 -t "$STORE"` with `run_in_background: true`.

- [ ] **Step 3: Check it in the browser**

Invoke the `browser-verification` skill (this is visual work) and follow it for annotated screenshots. With the
Playwright MCP, at 1440 × 900 (`browser_resize`), in light and then dark mode (`browser_emulate_media` with
`colorScheme: dark`), at `http://127.0.0.1:8765/index.html`:

1. Expected: the Deploy halted row first, its reason under the pill; then the Ready row marked `New`; then the Asimo
   Merged row with `35.0 min` and `3.00M`; the filter shows `All repos`, `Asimo`, `Deploy`.
2. Click the Ready row's title, then `browser_navigate_back`. Expected: the Ready row has no marker and is now the
   last row, below the Merged row: seen, it dropped among the rest, where the Merged run (filed 5 minutes ago) is
   newer than it (10 minutes ago).
3. Re-file that run once: `PIPELINE_PROOF_ROOT="$STORE" php -r 'require "skills/pipeline/checks/proof_store.php"; $d = getenv("PIPELINE_PROOF_ROOT") . "/Deploy/pr-6-ready"; $r = proof_write_run($d, proof_read_run($d), date("c")); file_put_contents("$d/index.html", proof_render_run($r)); file_put_contents(getenv("PIPELINE_PROOF_ROOT") . "/index.html", proof_render_index(proof_scan_runs(getenv("PIPELINE_PROOF_ROOT"))));'`,
   then reload the index. Expected: that row reads `Updated` and is back second, between the halted and the Merged row.
4. Select `Asimo` in the filter, then reload. Expected: only the Asimo row shows, and the filter still says `Asimo`.
   Select `All repos` again.
5. Click the Ready row's `Copy`. Expected: the button reads `Copied`; `browser_evaluate` of
   `navigator.clipboard.readText()` returns `De logboeken lopen nu live mee.` where the browser grants clipboard read
   (else the button text is the evidence).
6. Review Focus 5: navigate to `http://127.0.0.1:8765/Asimo/pr-7-merged/` (the directory URL), then
   `browser_evaluate` `localStorage.getItem('seen:Asimo/pr-7-merged')`. Expected: `"1"`. The page shows the Merged
   pill, `revision 1` in the meta line, and *Time and cost* with the `wf_first` and `wf_fix` rows and the total
   `35.0 min` / `3.00M`.
7. `browser_console_messages`: no errors on the index or the page.

Stop the `php -S` server when done.

- [ ] **Step 4: Record what was checked**

No commit when nothing changed. The screenshots and the outcome of each numbered check go into the step's report and
the PR's record, as engine.md §Implement asks. The issue's check on the real store over `file://` in Chrome is the
owner's, after the merge (spec *Done when*).
