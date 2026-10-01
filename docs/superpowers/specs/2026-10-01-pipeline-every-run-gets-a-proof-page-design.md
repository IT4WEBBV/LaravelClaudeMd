# Every run gets a proof page, opening with a Dutch client summary and a plain-language explainer — design

**Design size:** Architectural

**Date:** 2026-10-01
**Issue:** IT4WEBBV/LaravelClaudeMd#141
**Canonical home:** `skills/pipeline/checks/proof.php` (the run's rules: merge, validation, shot
states), `skills/pipeline/checks/proof_tests.php` (new: the test cases a diff adds or changes),
`skills/pipeline/checks/proof_store.php` (new: filing a run, shared by `write` and `handoff`),
`skills/pipeline/checks/proof_cli.php`, `skills/pipeline/checks/proof_render.php`,
`skills/pipeline/checks/handoff.php` and `dispatch_cli.php` (the page at `handoff`),
`skills/pipeline/checks/record.php` (`handoff` records the page), `skills/pipeline/checks/brief.php`
(`verify-ui:run`, `review-pr:resolve`), pipeline `references/engine.md` §Stations and §The proof store,
`SKILL.md` *Visual proof*, `references/manifest.md` `artifacts.proof`.
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. The issue body is the approved
scope and is not re-litigated here. Every question the brainstorm would have asked is answered in
*Assumptions*, so `/critique plan` audits exactly those. Nothing below was built or run; what it relies
on was read (see *What was read*). No probe was needed: no choice below hinges on whether an approach
works at all (the clipboard fallback the issue asks for is built whichever way Chrome answers).

## Problem

As the issue states it, confirmed in the code:

- **Only UI runs have a page.** `verify-ui` is the first step that runs `proof_cli.php write`
  (`brief.php` `verify-ui:run`), and it runs only when `pipeline_triggers(...)['ui']` fires. The finish
  step rewrites the page only "when `artifacts.proof` is set". A backend run, and any run that halts
  before `verify-ui`, has no page and no row in the store index (`proof_render_index()` lists what
  `proof_scan_runs()` finds, and nothing was filed).
- **`write` replaces the run.** `proof_write_run()` writes the payload as `run.json`, keeping only
  `createdAt` from the file it finds. Whatever a later write leaves out is gone, so nothing an earlier
  step filed survives unless every later step re-sends it.
- **The page is written for an insider.** `proof_render_run()` renders title, meta, `headline`,
  Problem, Solution, shots, checks, open questions and ledger. Nothing on it is in the client's words,
  and nothing says which tests the PR adds.
- **Shots do not say what they show.** A shot is `{title, caption, route, badges}`; before, after and
  defect are told apart only by caption text. Each shot renders at page width (`.shot img
  {width:100%}`) with its badge legend in an `<ol>` below it, away from the badge.

## Settled by the owner

- The issue body is the spec input, brainstormed and approved; its scope stands (the run's
  `decisions`). The issue leaves two calls to the design: who writes the two prose sections, and when
  they become required (Assumptions 1 and 2).

## Approaches

**Where the page is born.**

1. **The `handoff` command files it (chosen).** `dispatch_cli.php handoff` is the one place every run,
   in both modes, passes once its PR exists, and it is a command, not an agent (#125). It files a page
   from what it knows (the run's identity, a title from the spec's heading, the tests so far) and
   records `artifacts.proof`. Nothing about it can be skipped or botched by an agent.
2. **The `handoff` agent runs `proof_cli.php write`.** Puts an agent duty back into the step #125 made
   mechanical, for a payload that is entirely mechanical. Rejected.
3. **The finish step creates the page when there is none.** A run that halts in `implement` or
   `review-pr` would still have no page, and the issue asks for one from `handoff` on. Rejected.

**Who writes the client summary and the explainer.**

1. **Every agent `write` leaves the page with both (chosen).** `write` validates the run as it will be
   filed (the stored run with the payload merged over it), so `verify-ui` writes a first version on a
   UI run and the finish step revises or writes them on every run. Only the page `handoff` files lacks
   them, and shows them as pending.
2. **Only a final write requires them (`write --final`).** A flag the finish step can leave out, and a
   UI run's page would sit pending between `verify-ui` and the finish though `verify-ui` knew enough to
   write them. Rejected.
3. **The spec step writes them and `handoff` copies them.** A client summary written before the work
   is done describes the plan, not what the client gets, and review can still change that. Rejected.

**Where the test list comes from.**

1. **`write` runs git in the run's worktree (chosen).** `handoff` files the worktree and the PR's base
   into `run.json`; every write after it diffs `origin/<base>...HEAD` there and reads the changed test
   files at `HEAD`. Always as current as the write, and no agent writes it, as the issue asks.
2. **The payload names a diff file.** The `<manifest>.diff` the workflow keeps is refreshed only by
   `implement`, so the finish step's own commits would be missing. Rejected.

## Design

### The run as filed: merged, not replaced

`write` and `handoff` both file through one function in `proof_store.php`. It locates the page's
directory (as today: `proof_run_dir()` from `repo`, `branch`, `pr`, adopting a branch-slug directory
once a PR appears), reads the stored `run.json`, and **merges the payload over it, key by key at the top
level**: a key the payload carries replaces the stored one whole (a list is replaced, never appended to);
a key it leaves out is kept. To empty a list, send `[]`. `shotSources` is consumed and never stored;
`addedTests`, `schema`, `createdAt` and `updatedAt` are the store's and a payload's values for them are
ignored. The merge is a pure function in `proof.php` (`proof_merge_run($stored, $payload)`).

The merged run is validated (below); on any problem nothing is written and the problems are printed as
today. Then shots are ingested, `addedTests` is extracted, `run.json` is written with `schema: 2`, and the
page and the store index are rendered. The function returns the page path or the problems; it never
prunes (the `write` subcommand still runs the prune pass after it, `handoff` does not, so `handoff` makes
no `gh` call per stored run).

**Ingested shot names** gain the first eight hex digits of the source file's sha1:
`<NN>-<route slug>-<hash>.png`. A shot that carries a `file` and no source (an earlier pass's shot,
carried forward) keeps that file, so a later pass's new shots can never overwrite it whatever their
position, and re-sending the same source writes the same name.

`proof.php`'s file docblock and `proof_write_run()`'s docblock say what they now are: three write points
(`handoff` files, `verify-ui` adds shots, the finish step finalises), merged.

### Validation: two sets of rules

`proof_validate_run($run)` (today's function, extended) holds what **every** filed run obeys, `handoff`'s
page included:

- `title` present and at most 70 characters; each shot title at most 70 (unchanged).
- **Each shot has a `state`**: one of `before`, `after`, `defect`. A missing one is `shot 2 has no
  state: before, after or defect`; another value is `shot 2 state is "fixed": before, after or defect`.

`proof_validate_prose($run)` (new) holds what an **agent's `write`** must leave on the page:

- `clientSummary` present: `clientSummary is missing: one to three Dutch sentences for the hour
  registration, what the client gets, at most 400 characters`.
- at most 400 characters (`mb_strlen` of the trimmed text): `clientSummary is 431 characters, at most
  400`.
- no issue or PR reference, `#` followed by a digit: `clientSummary holds an issue or PR reference
  (#141): name what the client gets, in the client's words`.
- no backtick: `clientSummary holds a backtick: plain words, no code`.
- not the branch name, case-insensitive, matched as the whole branch and as the part after its first
  `/`: `clientSummary holds the branch name feature/issue-12-logs`.
- `explainer` is an object whose `problem` and `solution` are non-empty strings: `explainer is missing:
  {problem, solution}, a paragraph each for a reader who knows nothing about the issue`, or
  `explainer.solution is missing`.

`proof_cli.php write` applies both; `handoff` applies the first only. The 400-character limit is a
constant beside `PROOF_TITLE_MAX` (`PROOF_SUMMARY_MAX`). The shot states are a backed enum in
`proof.php`, `ProofShotState: string` (`Before`, `After`, `Defect`) with `label()` (`Before`, `After`,
`Defect`), which the validation (`tryFrom`) and the renderer (the ribbon's text and class) both use.

### The page `handoff` files

After the PR is open or adopted (and retargeted), `dispatch_cli_handoff()` builds a payload with a pure
`pipeline_handoff_proof()` in `handoff.php` and files it through `proof_store.php`:

| Field | From |
|---|---|
| `nameWithOwner` | the PR's URL, `https://github.com/<owner>/<repo>/pull/<n>` |
| `repo` | the `<repo>` part of `nameWithOwner`: the GitHub name, so every later write lands in the same directory |
| `branch`, `mode`, `worktree` | the manifest |
| `pr`, `prState` | the PR's number; `OPEN` |
| `issue` | `artifacts.issue` when set |
| `base` | the base the PR now goes into: the manifest's `base`, else the PR's `baseRefName` |
| `title` | `PR #<n>: <the spec's heading, less its design suffix>` (the heading `pipeline_handoff_title()` already reads), shortened to 70 characters at a word boundary with `…` (`proof_short_title()` in `proof.php`). Left out of the payload when the stored run has a title, so a re-run `handoff` never replaces the one `verify-ui` wrote |

Filing is never a halt, as everywhere in the store: a problem (validation, a directory that cannot be
made) becomes a note in the command's answer, `the proof page was not filed: <why>`, and the step
records `continued` without a page. When the page is filed, the command passes its path to `record`
as `proof`: `handoff:run`'s `continued` row gains `proof` as an optional flag
(`pipeline_record_table()`), and `pipeline_record_pr()` sets `artifacts.proof` beside `artifacts.pr`.
The handoff's own order (§Stations, *`handoff` in order*) gains the step between the PR and the
Component: preflight, push, PR, **page**, Component, record.

A re-run `handoff` (a plan gap, an escalation) merges over the page it filed before: identity fields
and `addedTests` refresh, everything a later step wrote stays.

### Tests this PR adds

`proof_tests.php` holds one pure function, `proof_added_tests(string $diff, callable $source): array`.
`$source($path)` returns a file's content at `HEAD`, or null. The result, in diff order:

```json
[{"file": "tests/Feature/LogsTest.php", "cases": [{"name": "follows the log", "change": "added"}, {"name": "test_stops_at_eof", "change": "changed"}]}]
```

- **Test files**: a `.php` path in the diff (`parse_diff()` from `critique/checks/diff_parse.php`, as
  `triggers.php` loads it) that lies under a `tests/` directory at any depth or ends in `Test.php`, and
  that has added lines. A deleted file (`/dev/null`) is skipped.
- **Declarations** in the file's content at `HEAD`, by line:
  - Pest: `it(` or `test(` followed by a quoted description; the name is the description, unescaped.
  - PHPUnit: a method `function test…(`, with any modifiers before it; the name is the method's.
  - PHPUnit `#[Test]` (or `#[\PHPUnit\Framework\Attributes\Test]`): the next `function <name>(` after
    it; the case starts at the attribute's line.
- **A case's range** runs from its first line to the first later line that closes it at the
  declaration's own indentation (`})` for Pest, `}` for a method), else to the line before the next
  declaration, else to the end of the file.
- **Its change**: `added` when the declaration line (the `it(` / `test(` / `function` line) is an added
  line; `changed` when another added line falls inside its range. Cases with neither are left out, and
  so is a file with no case left.

`proof_store.php` feeds it: when the merged run names a `worktree` that is a directory and a `base`, it
runs `git -C <worktree> diff origin/<base>...HEAD` and `git -C <worktree> show HEAD:<path>` (argv
arrays, no shell), and stores the result as `addedTests`. A git failure, or no worktree, keeps the stored
`addedTests` and logs `proof: tests not extracted: <why>` on stderr: a page filed after the worktree was
removed keeps the list it had.

### The page

`proof_render_run()` renders, in this order:

1. `h1` title and the meta line (unchanged).
2. **Client summary** (`h2`), the text in a `<p lang="nl" id="client-summary">`, and a `Copy` button
   beside the heading.
3. **In plain language** (`h2`), with `h3` *The problem* and *The solution*, each rendered by
   `proof_render_prose()` from `explainer.problem` and `explainer.solution`.
4. The `headline` as the lead, then the technical **Problem** and **Solution** (unchanged).
5. **Tests this PR adds** (`h2`): per file its path as `code`, and a list of case names, each tagged
   `new` or `changed`. An empty `addedTests` reads `This PR adds or changes no test cases.`
6. **Visual result**: shots (below).
7. **Checks**, **Open questions**, the gate ledger (unchanged).

**Pending.** A `schema: 2` run without `clientSummary` or `explainer` renders that section with one
muted line, `Pending: written by the step that finishes the run.`, and no copy button. A run filed
before this change has `schema: 1` (or none): it renders as today, with no summary, explainer or tests
section and no pending lines, so a finished old page does not claim work is outstanding. A shot without
a `state` (only on such a page) renders without a ribbon.

**Shots.**

- **Ribbon**: every shot with a `state` carries a label in its top-left corner, `Before`, `After` or
  `Defect` (`ProofShotState::label()`), coloured by a token per state (`--before` muted, `--after`
  green, `--defect` the accent red), set on `:root` and again for dark mode.
- **Pairs**: a `before` shot directly followed by an `after` shot renders with it in one row, two
  columns (`.pair`, a CSS grid); below 700 px the two stack. Every other shot renders alone, as today.
  Pairing is positional: no new field.
- **Badge notes on hover**: each badge holds its note in a tooltip span (`title — note`), shown on
  `:hover` and `:focus` of the badge, which is focusable (`tabindex="0"`). The legend below the shot
  stays, for reading and copying.
- **Zoom**: a click on a shot opens a `<dialog>` holding a copy of that shot (image, ribbon, badges)
  at the image's natural width, scrolling inside the dialog; Escape, or a click on the backdrop or on
  the zoomed shot, closes it. Badge positions are percentages, so they stay on their spot at any size.

**The copy button** runs a small inline script: `navigator.clipboard.writeText()` first; when that
throws or the API is absent, a temporary `<textarea>`, `select()` and `document.execCommand('copy')`,
which works over `file://`. The button reads `Copied` for two seconds after a copy, `Copy failed`
when both paths fail. The page still opens over `file://` with no external asset; the script and the
styles are inline. The index page gets none of this (#142 adds its own).

### The steps that write

- **`verify-ui:run`** (`brief.php`): the payload carries `clientSummary` and `explainer` (a first
  version) and a `state` on every shot; before shots only when the spec names a before state to show,
  captured on the base (Assumption 6); a pass that finds a defect captures it as `defect`, and the next
  pass carries the earlier defect shots forward (their `file`, no source) beside its own. The location
  keys `repo`, `branch` and `pr` are the ones in the `run.json` beside `artifacts.proof`.
- **`review-pr:resolve`** (`brief.php`): no longer conditional. *Write the proof page (engine.md §The
  proof store): `clientSummary` and `explainer` as the finished work stands, the suite line under
  `checks`, the final open questions and ledger; `repo`, `branch` and `pr` from the `run.json` beside
  `artifacts.proof`.* The open after `record` stays, on the path `write` printed. A run without
  `artifacts.proof` (its `handoff` predates this change, or could not file) gets its page from this
  write.

### engine.md and the other docs

- **§The proof store**: the opening paragraphs say `handoff` files every run's page, `verify-ui` adds the
  shots and `review-pr` finalises it; writes merge. The payload table gains `clientSummary`,
  `explainer`, `worktree`, `base` (filed by `handoff`), `addedTests` (filed by `write`, never by a
  payload), and the shots row gains `state`; the refusal paragraph lists the new rules; *A run with no
  page opens nothing* now means a run that halted before `handoff`.
- **§Stations**: the `handoff` row and *`handoff` in order* name the page; the `verify-ui` row says it
  adds shots to the run's page; the `review-pr` row drops "when the run has a proof page (`ui` fired)".
- **`SKILL.md`** *Visual proof*: every run that reached `handoff` has a page; backend runs are no longer
  "unaffected".
- **`manifest.md`**: `artifacts.proof` is the page `handoff` filed (`verify-ui` when `handoff` could
  not).

## Testing

Pest, in `skills/pipeline/checks/tests`, test first. No test writes to the real store: every test that
reaches `proof_store.php`, the `handoff` command's included, sets `PIPELINE_PROOF_ROOT` to a temp dir.

- **`ProofTest`**: the merge (a left-out key kept, a list replaced whole, `addedTests` and `schema` from
  a payload ignored); every refusal message above, one dataset row each, and a valid run passing;
  `proof_short_title()` at, under and over 70.
- **`ProofAddedTestsTest`** (new): Pest `it()`/`test()` added and changed; a PHPUnit `test_*` method and
  a `#[Test]` method; a change outside any case not listed; a non-test PHP file and a `.js` file
  ignored; a deleted file skipped; a quote escaped in a description.
- **`ProofRenderTest`**: the summary with its copy button and `lang="nl"`; the explainer above the
  technical Problem; pending lines on a `schema: 2` run; none on a `schema: 1` run, which renders as
  before (a fixture shaped like `Asimo/pr-210`); the tests section, empty and filled; a ribbon per
  state; a before–after pair in one `.pair`, a lone before not paired; the tooltip inside each badge;
  the dialog and script present; every new value escaped.
- **`ProofWriteTest`**: a second write keeps the first one's keys; a refusal for a missing summary
  files nothing; a write into a run naming a git worktree stores the tests its branch adds; a carried
  shot keeps its file next to a newly ingested one.
- **`HandoffTest` / `HandoffCliTest`**: the skeleton payload (title shortened, base, repo from the URL);
  a handoff files the page and records `artifacts.proof`; a re-run keeps a stored title; a filing
  failure is a note and the step still records `continued`.
- **`RecordTest`**: `handoff:run` with `--proof` sets both pointers. **`BriefTest`**: the two brief lines.

**In the browser**, before the PR leaves draft: `implement` files two fixture runs into a temp
`PIPELINE_PROOF_ROOT`, a UI run (a before–after pair, a defect shot, badges) and a backend run (the page
`handoff` would file, then one with prose), and checks them with Playwright at 1440 and 390 px and in
dark mode: the copy button puts the summary on the clipboard, the zoom opens and closes, a badge shows
its note on hover, the ribbons and the pair, the pending lines.

## Done when

- `handoff` files a page for every run, backend runs included, and records `artifacts.proof`; the
  page has a row in the store index.
- `write` refuses a run without a valid client summary or explainer, or with a shot without a valid
  `state`, and prints the problems as it does for `title`.
- The page shows the client summary with a working copy button, the explainer, the tests the PR adds,
  ribbons, before–after pairs, badge notes on hover and a zoom.
- Pages filed before this change render as they did.
- The tests above pass; engine.md §The proof store's payload table and the two step briefs name the
  new fields.
- After the merge, outside this PR's gates: one UI run and one backend run of `autoflow` produce the
  new page, checked in the browser (the issue's last line; #142's own run is the backend one).

## Assumptions

Each question the brainstorm would have asked the owner, and the answer assumed.

1. **Who writes the two prose sections?** Every agent `write`: `verify-ui` on a UI run, the finish step
   on every run (it revises). `handoff` cannot, being a command.
2. **When do they become required?** On every `write`, judged on the run as it will be filed, so a
   finish write that leaves them out passes when `verify-ui` already wrote them. The page `handoff`
   files is the one place they may be missing, and it says *Pending*.
3. **Does `write` still replace the run?** No: top-level merge. Without it the fields `handoff` files
   (`worktree`, `base`, `nameWithOwner`) would be lost to the first agent write that does not know them.
4. **Where does the test list come from, and when?** `write` (and `handoff`) runs git in the worktree
   `handoff` filed, at every write; `addedTests` is never taken from a payload.
5. **What counts as a changed test?** An added line inside the case's range. A change that only removes
   lines inside a case is not seen (`parse_diff()` counts removed lines but does not place them), nor
   is a `describe()` prefix added to the name, nor is a dataset expanded into its rows. Only PHP test
   files count: `it(` in a JavaScript test is not a Pest case.
6. **How does `verify-ui` take a before shot, when the branch shows only the after?** When the spec
   names a before state, `verify-ui` checks out the base detached in the run's worktree (`git checkout
   --detach origin/<base>`), captures the before shots, and checks the branch out again before any after
   shot. The code is mounted, so the stack serves the base; the database keeps the branch's migrations.
   When the base cannot render the state (a migration it does not expect), the before shot is left out
   and that is carried as an open question, never a halt.
7. **How does a before shot find its after shot?** By position: a `before` directly followed by an
   `after`. No pairing field.
8. **What does zoom open?** A dialog with the same shot at natural size, badges kept, over opening the
   PNG in the tab, which would lose the badges. The legend stays below each shot.
9. **How are old pages told apart?** By `schema`: the store writes `2` from now on; a `1` renders as
   today. Pending lines on an old, finished page would claim outstanding work.
10. **What is "the branch name" in the summary rule?** The whole branch, and the part after its first
    `/`, case-insensitive. A short topic (`feature/logs`) may then refuse an innocent word; the agent
    rephrases.
11. **What title does `handoff` give the page?** `PR #<n>: <spec heading>`, shortened at a word boundary
    to 70 characters; `verify-ui` or the finish step may replace it, and a re-run `handoff` never does.
12. **Which `repo` names the directory?** The GitHub repo name from the PR URL. Agents take `repo`,
    `branch` and `pr` from the `run.json` beside `artifacts.proof` rather than deriving their own, so a
    repo whose checkout folder differs from its GitHub name still has one page.
13. **Does the finish step record the page?** No new flag on `review-pr:resolve`: it writes to the page
    at `artifacts.proof`, and when that is unset its write creates one and the open uses the path
    `write` printed.
14. **Does the store index change?** No: a backend run gets its row because it now has a page. Status,
    New/Updated and the per-row copy button are #142.
15. **Is the Dutch checked?** No: language is the brief's rule, the length and the forbidden tokens are
    `write`'s.
16. **Does `handoff` prune the store?** No: pruning asks `gh` once per stored run, which a mechanical
    step should not pay; the next agent `write` prunes as today.

## Relation to other work

- **#142** builds on `clientSummary` (its per-row copy button) and on every run having a page. It also
  bumps a `revision` per write; the merge here is where that will go.
- **#128** (split engine.md by reader) touches §The proof store and the step briefs. Whichever lands
  second merges.

## What was read

`proof.php`, `proof_cli.php`, `proof_render.php` (whole); `handoff.php` (whole);
`dispatch_cli.php` `dispatch_cli_handoff()`, `dispatch_cli_record_files()`, `dispatch_cli_base_ref()`;
`record.php` `pipeline_record_table()`, `pipeline_record()`, `pipeline_record_pr()`,
`pipeline_record_verified()`; `brief.php` `pipeline_leg_overrides()`; `triggers.php`;
`critique/checks/diff_parse.php`; `workflow/pipeline-autoflow.js` `stepPrompt()`; engine.md §Stations,
§Design size, §The proof store; `manifest.md` §Fields; `SKILL.md` *Visual proof*; the tests
`ProofWriteTest`, `HandoffCliTest` (its fake gh and fixture), the test names of `ProofRenderTest`,
`ProofTest`, `HandoffTest`; `~/GitProjects/_proofs/Asimo/pr-210-…/run.json` for a real page's shape.
