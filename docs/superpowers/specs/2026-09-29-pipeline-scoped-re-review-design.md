# A re-review of the PR reads what changed since the last completed review — design

**Design size:** Architectural

**Date:** 2026-09-29
**Issue:** IT4WEBBV/LaravelClaudeMd#88
**Canonical home:** `skills/pipeline/checks/brief.php` (the base, the scope and the brief line),
`skills/pipeline/checks/dispatch.php` (`reviewed_sha` required and kept), `skills/pipeline/checks/dispatch_cli.php`
(git handed to the brief), pipeline `references/engine.md` §Scoped re-review (the rule and why),
`references/manifest.md` (the key).
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. Every question the brainstorm
would have asked is answered in *Assumptions*, so `/critique plan` audits exactly those. Nothing below was
built or run; what it relies on was read (see *What was read*), and one probe answered one question (see
*Probe*).

## Problem

Re-entering a finished run (`dispatch_cli.php launch <manifest> <diff> --from review-pr`, the CI gate's fix
round, an owner's request on a ready PR) always starts with a full `review-pr:review` step: a fresh agent
re-reads the whole PR, even when what changed since the last review is a two-line fix or a merge with
main. Measured on IT4WEBBV/Asimo PR #183 (2026-09-25): the delta since the cycle-1 review was 4 files /
13 lines, the PR 16 files / 1493 lines; each relaunch's review step peaked at 156k–208k context
(~0.4M weighted tokens), and three relaunches each paid it. A cheaper model lowers the price per token,
not the ~200k the reviewer reads; scoping the target is the lever.

Nothing records which commit a review saw. `gate_ledger` entries carry `at`, not a sha, and `last_sha` is
whatever the last leg wrote.

## Change (from the issue)

1. The review step writes the HEAD it reviewed into its entry as `reviewed_sha`; the resolve step cannot
   rewrite it.
2. When a completed (`continued`) `pr-review` entry exists and its `reviewed_sha` is an ancestor of HEAD,
   the brief scopes the review to the branch's own commits since then, as patches, plus the files where a
   merge since then met this branch's changes, read whole. Otherwise a full review, as today.
3. The base is the newest `continued` review, not the newest entry: a halted cycle's findings were never
   dispositioned.

Not included, as the issue says: carrying the earlier review's text into the brief (the Reviewer contract
and engine.md §What a leg brief consists of forbid it), and skipping the review for owner-requested
mechanical work.

## Approaches

1. **The brief computes the scope through an injected git runner (chosen).** `pipeline_brief()` takes an
   optional `callable $git`; only on `review-pr`'s review step does it ask git anything, and any git
   failure falls back to the full review. The CLI hands it `pipeline_git_run()` bound to the worktree. The
   review step gets concrete commands and a concrete file list, so what it reads does not depend on how
   it interprets a rule.
2. **The brief states the rule and the reviewer works the scope out.** No git in PHP, but the reviewer
   would have to find the base entry in the manifest (reading the earlier review next to it, which the
   Reviewer contract forbids) and assemble the file list itself: the boring, easy-to-do-incompletely
   target assembly `/critique` exists to take off the reviewer.
3. **`launch` computes the scope and stores it in the manifest.** A recomputable field stored
   (`manifest.md` *Two rules*), and a scope computed once per launch goes stale on the Opus retry of an
   empty review; the brief is computed per step anyway.

## Design

### Recording: `reviewed_sha`

- **`review-pr:review`'s entry line** (`pipeline_leg_overrides()`, both modes) asks for `reviewed_sha`
  beside `gate`, `leg`, `cycle`, `at`, `review` and `annotations`: *the output of `git rev-parse HEAD` in
  the worktree, the commit you reviewed*. `review-plan:review`'s line does not change.
- **`pipeline_ledger_problem()`** requires it: a `review-pr` review step that adds its one open entry
  without a 40-character lowercase hex `reviewed_sha` fails with *the review-pr review step must record
  reviewed_sha, the HEAD it reviewed (`git rev-parse HEAD`), on its entry*. The review-step check moves
  into `pipeline_review_entry_problem(array $added, string $gate): ?string`, which keeps today's *the
  review step must add exactly one open `<gate>` entry* and adds the sha rule for `pr-review` only.
- **`$kept`** gains `reviewed_sha`: a resolve step that adds, changes or removes it on the open entry
  halts with the existing wording (*review-pr changed reviewed_sha on ledger entry N (pr-review)*). Every
  closed entry is already kept whole.

### The base: `pipeline_review_base(array $ledger): ?string`

The `reviewed_sha` of the newest `pr-review` entry (by ledger position; the ledger only grows) whose
`outcome` is `continued`, that records a `reviewed_sha`, and whose `at` is newer than the latest
`design-size` escalation or plan gap. Otherwise null.

- `continued` only: `halted`, `looped-back` and open entries are skipped, so a halted cycle's
  undispositioned findings are never scoped away (issue point 3). A `looped-back` cycle's findings went
  to `implement`, whose commits are after the older `continued` sha, so they are inside the scope.
- Any `continued` review is a sound base: it reviewed the PR as of its sha, and everything since is in
  the scope. The newest one is only the tightest. An entry without `reviewed_sha` (written before this
  change) is skipped, not a reason for a full review, when an older one has it.
- The escalation/plan-gap cut is `pipeline_done_legs()`'s: a plan that grew is a different plan, and code
  reviewed against the old one must be reviewed again whole. It is extracted from `pipeline_done_legs()`
  into `pipeline_reset_at(array $ledger): string` (the newest `at` of an escalation or plan gap, `''`
  when none), which both call.

### The scope: `pipeline_review_scope(array $manifest, callable $git): ?array`

`$git` is `fn (array $args): array{0: int, 1: string, 2: string}`, `pipeline_git_run()`'s shape. It returns
`['since' => <sha>, 'base' => <ref>, 'commits' => <int>, 'files' => list<string>]`, or null for a full
review. In order, null at the first step that fails or answers no:

1. `since` = `pipeline_review_base(pipeline_ledger($manifest))`.
2. `git merge-base --is-ancestor <since> HEAD` exits 0. (A sha equal to HEAD is its own ancestor: an empty
   scope, below.)
3. `base` = `origin/<manifest base>` on a run with a base, else `git symbolic-ref -q --short
   refs/remotes/origin/HEAD` (`origin/main` here).
4. `commits` = the number of lines of `git rev-list --no-merges <since>..HEAD ^<base>`: the branch's own
   commits since the review. `^<base>` leaves out every commit a merge of main brought in; a commit that
   reached the branch as a merge's second parent without being on main (a `git pull` of the PR branch
   with local commits) is still counted.
5. `files`, sorted and unique, over each merge in `git rev-list --merges <since>..HEAD ^<base>` (the
   branch's merges since, not main's own), from `pipeline_merge_files()`:
   - its parents, `git rev-parse <merge>^@`; for each parent `P` after the first `F`, the files both sides
     changed since they last met: `git diff --name-only --no-renames F...P` intersected with `git diff
     --name-only --no-renames P...F`. Every conflicted file is one, and so is a file both changed that git
     merged cleanly, or that the resolution settled by taking one side (which drops the other side's
     change, and must be read);
   - plus `git diff-tree -c --no-commit-id --name-only <merge>`: the files the merge result changed
     against every parent, which adds an edit made in the merge commit itself to a file only one side, or
     neither, touched.

Every git call that exits non-zero makes the whole scope null: the review is never narrower than git
could prove.

### The brief

- `pipeline_brief(array $manifest, string $leg, string $manifestPath, ?string $step = null, ?callable $git = null)`.
  Only for `review-pr:review` with a `$git` does it call `pipeline_review_scope()`; every other step, and a
  call without `$git`, asks git nothing and is byte-for-byte today's brief.
- With a scope, `pipeline_brief_overrides()` appends one line (`pipeline_review_scope_line()`), after the
  entry line and before the `autoflow` lines:

  > Scoped re-review (engine.md §Scoped re-review): a review of this PR completed at `<since>`, which HEAD
  > contains, so your target is what changed since, not the whole PR: the branch's own commits since
  > (`<n>`), as patches, `git log -p --no-merges <since>..HEAD ^<base>`, plus `git diff HEAD` (Stage 0 runs
  > over both); and read whole at HEAD, the files where a merge since met this branch's changes: `a.php`,
  > `b.php`; what the settled decisions above ask of the PR stays in your target wherever it lies. Read
  > beyond the target only where a finding needs it.

  With no merge files the file clause reads *and no file more: no merge since met this branch's changes*.
  With 0 commits and no files the target clause reads *nothing was committed on this branch since
  `<since>`: review only what the settled decisions above ask of the PR, and say so*, so a CI fix round or
  an owner's request on an unchanged branch still gets its review without widening to the whole PR.
- The line names the sha, never the entry it came from: the review step reads no earlier review
  (engine.md §What a leg brief consists of), and the existing *crafted context* test keeps holding.
- `annotations` stay the content triggers over the whole PR's diff: they are facts about the PR, not
  findings of this review.

### `dispatch_cli.php`

`dispatch_cli_git(array $manifest): Closure` returns `fn (array $args) => pipeline_git_run(<worktree>, $args)`;
`dispatch_cli_emit()` (`next`, `returned`) and `dispatch_cli_brief()` (`brief`) pass it to
`pipeline_brief()`. So `interactive` and `autoflow` scope the same way, and `launch --from review-pr` needs
no change: the first `brief` after it computes the scope.

### Docs

- **engine.md**: a new `## Scoped re-review — a review of the PR after a completed one reads what changed
  since`, before §Resolving a review: the rule, the base, the target, the fallbacks, why (#88's numbers),
  what is not carried (the earlier review), and that a chain of scoped reviews is as sound as its earliest
  full review. §What a leg brief consists of: a scoped brief names the sha the last completed review saw,
  never that review. §`autoflow`'s `launch` bullet: the review a `--from review-pr` run starts with is
  scoped (§Scoped re-review).
- **manifest.md**: `reviewed_sha` in the `gate_ledger` key table (`pr-review` entries only, written by the
  review step, required there, never changed after), and the JSON example's shape note.

### What does not change

`pipeline-autoflow.js`, the routing tables, `launch`, `finish`, the CI gate, `run_audit.php`, the
`critique` skill (the brief's override states the target, as briefs do), `review-plan`'s review, and a
first `review-pr` review, which has no `continued` entry to scope from.

## Tests

Written first, each seen red:

- **`DoneLegsTest`**: `pipeline_reset_at()` over the ledgers the file already uses (`''` without a reset,
  the escalation's and the plan gap's `at` with one); `pipeline_done_legs()`'s cases unchanged.
- **`ReviewScopeTest`** (new): `pipeline_review_base()` picks the newest `continued` entry with a sha,
  skips a newer halted, looped-back or open one and a `continued` one without a sha, and ignores one older
  than an escalation or a plan gap. `pipeline_review_scope()` over throwaway repos (`suite_repo()`, main
  mirrored at `origin/main` and `origin/HEAD`, a `feature` branch): commits since the review counted,
  main's commits not; a clean merge touching a branch file lists it and not main's other files; a conflict
  resolution, a resolution that took one side, and an edit made in the merge commit are listed; a second
  parent's own commits (a pull) counted; the manifest's `base` used over `origin/HEAD`; null for no
  `continued` entry, a sha that is not an ancestor, a sha git does not know, and a base it cannot resolve.
  A recording fake `$git` shows `pipeline_brief()` asks git nothing outside `review-pr`'s review step.
- **`BriefTest`**: the scoped line with the since sha, the log command, the count and the files; the
  *no merge* and *nothing committed* forms; no scoped line without `$git` or without a base; the scoped
  brief names no ledger index and no earlier review text; `review-pr:review`'s entry line asks for
  `reviewed_sha` and `review-plan:review`'s does not.
- **`ReturnedTest`**: a `review-pr` review step whose entry lacks `reviewed_sha`, or carries a short one,
  halts; a 40-hex one continues; a `review-plan` review step needs none; a resolve step that changes,
  adds or removes `reviewed_sha` on the open entry halts naming the key.
- **`DispatchCliTest`**: the issue's Done-when through the real commands: `launch --from review-pr` on a
  manifest whose worktree has a `continued` review at an ancestor, then `brief review-pr review`, prints
  the scoped line; with the sha on another branch, or with only a halted entry, it prints none. `next` in
  `interactive` scopes the same way.
- **`AutoflowScriptTest`**: the smoke pass's stub review step writes what its brief now asks, a
  `reviewed_sha`.

The whole pipeline suite passes.

## Probe

**Question:** can git name, from the merge commit alone, the files an edit inside the merge commit
touched, beside the files both sides changed? If not, the scope would list only the intersection and an
edit made in a merge would go unreviewed.
**Probe** (git 2.33.0, a throwaway repo): a base with four files; the branch changes `both.txt` (line 1)
and `conflict.txt`; main changes `both.txt` (line 7), `conflict.txt` and `mainonly.txt`; the branch merges
main, resolves `conflict.txt` and also edits `evil.txt` in the merge commit.
**Showed:** `git diff-tree -c --name-only HEAD` printed the merge's sha, then `both.txt`, `conflict.txt`,
`evil.txt` (not `mainonly.txt`), so `--no-commit-id` is needed to drop the sha line. `F...P` listed
`both.txt`, `conflict.txt`, `mainonly.txt`; `P...F` listed `both.txt`, `conflict.txt`; their intersection
is `both.txt`, `conflict.txt`. `git rev-parse HEAD^@` printed the two parents. So the union of the
intersection and `diff-tree -c` is the design above.

## What was read

- `skills/pipeline/checks/brief.php` (`pipeline_brief()`, `pipeline_brief_overrides()`,
  `pipeline_leg_overrides()`), `dispatch.php` (`pipeline_ledger_problem()` and its `$kept`,
  `pipeline_entry_change()`), `dispatch_cli.php` (`dispatch_cli_emit()`, `dispatch_cli_brief()`, the two
  `pipeline_brief()` callers), `pipeline.php` (`pipeline_done_legs()`, `pipeline_is_plan_gap()`),
  `suite.php` (`pipeline_git_run()`: `[code, trimmed stdout, trimmed stderr]`, stderr to a file;
  `pipeline_git()` throws on a non-zero exit).
- `skills/critique/SKILL.md` (Stage 0's target, the Reviewer contract) and `checks/diff_parse.php`: the
  parser keys on `--- `, `+++ `, `@@`, `+` and `-` lines only, so `git log -p` output, whose commit
  headers and indented messages start with none of those, parses as a diff for Stage 0.
- `references/engine.md`, `manifest.md`, `gates.md`; the tests `BriefTest`, `ReturnedTest`,
  `DispatchCliTest` (`dispatch_fixture()`, whose `worktree` is a plain temp dir: git fails there, so every
  existing brief stays full), `SuiteTest` (`suite_repo()`), `AutoflowScriptTest` (the smoke pass writes a
  `pr-review` entry from its stub review step), `LockStepTest`.
- `git symbolic-ref -q --short refs/remotes/origin/HEAD` in this worktree prints `origin/main`.

## Out of scope

- `git log --remerge-diff` (git 2.36+, one machine runs 2.33): it would show a merge as only what its
  resolution changed; the file list above covers the same files, read whole.
- Checking at the next boundary that `reviewed_sha` equals HEAD: the review step is read-only, so HEAD
  cannot move past what it saw; a wrong sha is either an older ancestor (a wider scope) or not an ancestor
  (a full review), never a narrower one.
- The `critique` skill learning a *since* target of its own.

## Assumptions

Questions the brainstorm would have asked the owner, with the answer assumed.

1. **Is `reviewed_sha` required, or best-effort?** Required on `pr-review` review entries, as a 40-hex sha,
   checked where every other return is checked: the Done-when says a review step records it, and a check
   the pipeline does not enforce is a hope. A missing one halts a run once, and the brief says exactly
   what to write.
2. **The issue's `git log -p --first-parent --no-merges`, or `--no-merges … ^<base>`?** The latter.
   `--first-parent` hides the commits of any merge's second parent: after a `git pull` of the PR branch
   onto local commits, the pulled commits would be neither patches nor (unless both sides touched a file)
   co-touched files, an unreviewed change. `^<base>` leaves out only what main brought in; a stale
   `origin/<base>` only adds main's newer commits to the patches, a wider scope, never a narrower one.
3. **"Files both the branch and main touched" — against what?** Per merge since the review, the two sides
   since they last met (`F...P` ∩ `P...F`), which needs no base, plus what the merge commit itself changed
   against every parent (*Probe*). Main's own merges are left out by `^<base>` on the merge list.
4. **Where does the base ref come from?** The manifest's `base`, else `origin/HEAD`. When neither
   resolves, a full review: the same fail-safe direction as every other git failure.
5. **Which reviews can be a base?** `continued` `pr-review` entries with a sha, newest first, newer than
   the latest escalation or plan gap (the cut `pipeline_done_legs()` makes, extracted and shared).
6. **What does an empty target review?** The settled decisions against the PR (an owner's request, the CI
   round's failure), and it says the branch did not change; it does not widen to the whole PR.
7. **Does `interactive` scope too?** Yes: its review step is a dispatched agent on the same brief.
8. **Does the scoped brief point at the base entry?** No, only at its sha: pointing at the entry would
   hand the reviewer the earlier review (Reviewer contract).
9. **Renames?** `--no-renames` on both sides of the intersection, so a file one side renamed and the
   other changed is listed under its old path; a listed path missing at HEAD tells the reviewer the merge
   moved or deleted it.
10. **Changelog?** This repository has no `.changelog/` and no `CHANGELOG.md`, so none is written.
