# `run_cost_cli.php` reports wall time per step — design

**Design size:** Architectural

**Date:** 2026-09-25
**Issue:** IT4WEBBV/LaravelClaudeMd#78
**Canonical home:** `skills/pipeline/checks/run_cost.php` (pure functions), `skills/pipeline/checks/run_cost_cli.php`
(the command), their test `skills/pipeline/checks/tests/RunCostTest.php`, and the two sentences that say what the
command prints: pipeline `references/engine.md` §`autoflow` and pipeline `SKILL.md` *Cost per run*.
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. Every question the brainstorm
would have asked is answered in *Assumptions*, so `/critique plan` audits exactly those.

## Problem

After an `autoflow` run the invoking session runs `php run_cost_cli.php <run transcript dir>`. It prints
one line per step (weighted tokens, calls, peak context) and a `run:` line (total weighted tokens, the
largest step peak). It says nothing about how long a step took. Wall time is the owner's other cost: a
viewiemedia run takes 42–54 minutes of workflow, and the per-step split had to be counted by hand from the
transcripts for the #50 trial table.

## Settled direction (owner, 2026-09-25)

Per step: wall time from the step agent's first to last transcript record, plus the share spent waiting on
tools (each `tool_use` to its `tool_result`), for example

```
implement:run: 0.90M over 54 calls, peak 137k, 21.4 min (17.7 waiting on tools)
```

The `run:` line adds the total workflow time.

## What a step transcript gives

Verified on this machine against real `wf_*` directories (2026-09-25):

- Every record of `agent-<id>.jsonl` (`user`, `assistant`, `attachment`) carries an ISO 8601 UTC
  `timestamp` with milliseconds, e.g. `2026-09-25T12:25:54.056Z`. Records are appended in time order.
- A tool call is a content block `{"type":"tool_use","id":…}` in an `assistant` record. Its answer is a
  content block `{"type":"tool_result","tool_use_id":…}` in a later `user` record. One assistant turn
  that calls several tools writes one record per `tool_use`, and their results follow; the calls run in
  parallel.
- `journal.jsonl` has no timestamps. The run's time can only come from the step transcripts.

Prototype numbers on a real 10-step Asimo run (`wf_c355120f`): `implement:run` 17.5 min wall, 14.8 min
waiting on tools; `review-plan:review` 7.4 min wall, 1.3 min waiting when overlapping calls are counted
once but 4.0 min when every call is summed. The run spans 63.8 min; the step walls sum to 63.6.

## Design

### Per step: `pipeline_transcript_time(string $jsonl)`

A pure function in `run_cost.php` beside `pipeline_transcript_cost()`. It returns

```php
['start' => ?float, 'end' => ?float, 'wall' => float, 'waiting' => float]  // seconds; start/end are Unix times
```

- `start` and `end` are the earliest and latest `timestamp` of the transcript's records (min and max, not
  first and last line, so an out-of-order line cannot make wall time negative). A transcript with no
  timestamped record (empty, missing, or only unparseable lines) has `start` and `end` null, and `wall`
  and `waiting` 0.0.
- `wall` is `end - start`.
- `waiting` is the length of the **union** of the intervals `[tool_use record time, tool_result record
  time]`. Parallel calls overlap, so summing them would count the same wait more than once and could
  exceed `wall` (the 4.0 against 1.3 above). The union never exceeds `wall`.
- A `tool_use` with no `tool_result` in the transcript (the agent was cut off) adds nothing. A
  `tool_result` whose `tool_use_id` names no earlier `tool_use` adds nothing. An interval whose result is
  timestamped before its call is clamped to zero length.

Helpers in the same file, one job each:

- `pipeline_jsonl(string $jsonl): list<array>` decodes each line and keeps the JSON objects. Today
  `pipeline_transcript_usage()` and `pipeline_run_journal()` each repeat that loop head; the new function
  would be the third copy, so all three use the helper. Their behaviour does not change (the existing
  tests pin it).
- `pipeline_record_time(array $entry): ?float` parses a record's `timestamp` with `DateTimeImmutable`
  and reads it back as `(float) $time->format('U.u')`, which keeps the milliseconds. A `timestamp` that is
  missing, not a string, or unparseable gives null: the record is skipped for timing and still counts for
  cost as today.
- `pipeline_union_seconds(list<array{float, float}> $intervals): float` sorts by start; each interval adds
  only the part past the furthest end so far, so overlap counts once and a reversed interval adds 0.
- `pipeline_run_seconds(list<array> $steps): float` is the run's span (next section).

### The run: total workflow time

The run's time is the span from the earliest `start` to the latest `end` over the steps that have one,
not the sum of step walls: it includes the script's few seconds between steps and stays right if steps
ever run in parallel. With no timed step it is 0.0.

### Output

`pipeline_run_cost_lines()` takes each step's cost and time fields together and prints minutes with one
decimal (`%.1f` of seconds / 60); the numbers below are illustrative:

```
design:run: 0.31M over 38 calls, peak 92k, 6.3 min (0.7 waiting on tools)
implement:run: 0.90M over 54 calls, peak 137k, 21.4 min (17.7 waiting on tools)
run: 3.10M weighted over 10 steps in 63.8 min; largest step peak 137k (implement:run)
```

The `run:` line keeps its existing phrases in order and gains `in <min> min` after the step count. The
no-steps line (`run: not measured (no step transcripts)`) is unchanged.

A step whose transcript has no timestamps prints `0.0 min (0.0 waiting on tools)`, just as a step with no
usage prints `0.00M over 0 calls, peak 0k`: the line shape never varies.

### The command

`run_cost_cli.php` reads each step's transcript once and passes it to both `pipeline_transcript_cost()`
and `pipeline_transcript_time()`. Its docblock names the new fields. It still always exits 0.

### Docs

- pipeline `references/engine.md` §`autoflow`: "`run_cost_cli.php` prints the weighted cost per step and
  the largest step peak" gains "each step's wall time and the part of it spent waiting on tools, and the
  run's total workflow time".
- pipeline `SKILL.md` *Cost per run*: the parenthesis "(weighted cost per step, the largest step peak)"
  gains "wall time per step and per run".
- `orchestrate` references only run the command and quote its lines; they need no change.

## Tests

`RunCostTest.php` stays the one test file. The fixture helpers gain a timestamp: `cost_call()` takes an
optional `?string $at`, and two new helpers `cost_tool_use(string $id, string $at)` and
`cost_tool_result(string $id, string $at)` write an `assistant` record with a `tool_use` block and a `user`
record with a `tool_result` block.

1. **Wall and waiting of one transcript** (`pipeline_transcript_time`): records out of line order give
   `start` = the minimum and `end` = the maximum; two overlapping calls and one later call give the union
   (overlap counted once); a `tool_use` without result, a `tool_result` without call, an untimed record, a
   non-JSON line and an empty line add nothing; an empty transcript gives
   `['start' => null, 'end' => null, 'wall' => 0.0, 'waiting' => 0.0]`.
2. **The command** (existing test, expectations extended): two timed steps with a gap between them print
   each step's minutes and waiting, and the `run:` line's `in <min> min` is the span including the gap,
   larger than the sum of the two walls. `/nonexistent` and no argument behave as today.
3. **A step without timestamps** prints `0.0 min (0.0 waiting on tools)` and does not shift the run's
   span, which comes from the timed steps only.

The existing cost and journal tests stay unchanged and pin that `pipeline_jsonl()` changes nothing.
Suite: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
from the worktree root, on the host. After the suite, the command runs once against a real `wf_*`
directory as a smoke check; its lines go in the PR body.

## Out of scope

- Filling the wall-time column of the PR #50 run table. The output now carries the numbers; the table is
  edited by whoever reports the trial.
- Kickoff and finish time outside the workflow (the ~10 minutes the issue mentions). They happen in the
  invoking session, not in the `wf_*` directory this command reads.
- Splitting waiting per tool (CI, suite, Pint). One waiting figure is what the owner asked for.
- `run_audit.php`: it uses `pipeline_run_journal()`, whose behaviour does not change.

## Assumptions

Questions the brainstorm would have asked the owner, with the answer assumed.

1. **Parallel tool calls: sum each call or count overlapping time once?** Assumed once (the union). The
   owner's example reads as "of 21.4 minutes, 17.7 were spent waiting", which only holds when waiting is
   part of wall. On the real Asimo run summing turns `review-plan:review`'s 1.3 min into 4.0.
2. **Total workflow time: span of the run or sum of step walls?** Assumed the span, earliest step start
   to latest step end. It is what the owner waits; the two differ by the seconds between steps.
3. **Where does the `run:` line put the total?** Assumed `run: <M> weighted over <n> steps in <min> min;
   largest step peak …`, keeping every existing phrase in order. Nothing parses this line; the owner and
   `orchestrate` quote it.
4. **Unit and precision?** Minutes with one decimal, as in the owner's example (`21.4 min`, `17.7`).
5. **A step with no timestamps (missing transcript, or a test fixture)?** Prints `0.0 min (0.0 waiting
   on tools)` rather than dropping the phrase, so every step line has the same shape. Real transcripts
   always carry timestamps.
6. **A tool call with no result?** Adds nothing to waiting. The agent was cut off mid-call; its last
   record is then the `tool_use` itself, so there is no time to attribute anyway.
7. **Factor out the JSONL loop?** Yes, `pipeline_jsonl()`: the new function would be the third copy of the
   same decode-and-filter loop, which is where the owner's DRY rule says to abstract.
8. **Changelog?** This repo has no `.changelog/` directory and no `CHANGELOG.md`, so none is written.
