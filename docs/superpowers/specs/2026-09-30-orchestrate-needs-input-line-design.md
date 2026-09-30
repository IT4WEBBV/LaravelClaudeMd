# orchestrate: a PR waiting on the owner's merge ends every report with `needs input:` — design

**Design size:** Architectural

**Date:** 2026-09-30
**Issue:** IT4WEBBV/LaravelClaudeMd#112
**Canonical home:** `skills/orchestrate/SKILL.md` (Steps 5 and 6, the rule),
`skills/orchestrate/needs_input.py` (the line), `skills/orchestrate/references/commands.md`
(§Needs input, the command and where the line goes).
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. Every question the brainstorm
would have asked is answered in *Assumptions*, so `/critique plan` audits exactly those. Nothing below was
built or run. The classifier facts were read from the Claude Code 2.1.285 binary and from
`~/.claude/jobs/*/state.json` and `timeline.jsonl` on this machine (2026-09-30).

## Problem

An orchestrator with a PR ready for the owner's merge shows as `working` in the job list for as long as
the merge takes. Step 5 tells the owner "in two lines" and arms a merge watch, a background
`until gh pr view … != OPEN` shell. Every later turn (a run's completion notice, a CI gate answer) ends
on a plain status message, and the job list is classified from the latest message, so nothing shows that
the owner is the blocker. On 2026-09-29 the LaravelClaudeMd batch had PR #109 green and mergeable for 99
minutes; all four orchestrators showed `working`, and the wait was found only by opening each session.

## What the classifier reads (Claude Code 2.1.285)

- The job's state is one of `working`, `blocked`, `done`, `failed`; `claude agents` prints `blocked` as
  `waitingFor: input needed`. Its definition of `blocked` names the marker this issue uses: "the last
  message ends on a direct question or explicit request for the user ("want me to…?", "which do you
  prefer?", "approve this?", "needs input: …")".
- A marker pattern is matched on the message (whether always ahead of the model's own reading was not
  confirmed; both name the same marker):
  `(?:^|\n)\s*needs input\s*[:—–-]\s*(.{3,200}?)(?=\n|$)`, case-insensitive. The line starts with
  `needs input`, and **the text after the colon is 3 to 200 characters to the end of the line**: a longer
  line does not match at all. The capture becomes the job's `needs` text.
- It is searched in **the last 800 characters** of the message, and a match **inside a code fence** is
  skipped.
- A `blocked` marker followed by three or more non-empty paragraphs is dropped, and the branch also
  tests the tail for `nothing needed|required from you` and `no (user) action needed|required`. How that
  test ends was not read (the read of that part of the binary was denied); the rule below keeps both
  phrases out of the report's closing lines, which is safe either way.
- `blocked` does not stop the session: on 2026-09-29 the viewiemedia orchestrator was classified
  `blocked` at 13:49 ("3 PRs ready … awaiting merges") and handled a merge-watch notice at 14:50
  (`~/.claude/jobs/3653e6b2/timeline.jsonl`). `owners.py` counts `blocked` as live (`LIVE_STATES`), so a
  flagged orchestrator still owns its worktrees.
- That same timeline shows the classifier sometimes reaching `blocked` from prose alone. The marker
  makes it the rule instead of luck.

## Approaches

1. **A script prints the line; the skill says when it goes in a report (chosen).**
   `needs_input.py <repo> <pr>:<issue> …` prints one `needs input:` line for every waiting PR. The two
   things a hand-written line gets wrong without anyone noticing are decided in code: the 200-character
   limit (three PRs with their links already pass it for this repo) and the line's shape. Its test pins
   the line against the classifier's own pattern.
2. **A prose rule alone, with a test that greps `SKILL.md`.** Rejected: the test would pin words, not the
   line. A line of 201 characters is silently no marker, which is this issue's failure again.
3. **The script finds the waiting PRs itself** (`gh pr list`, the `branch.issue` prefix). Rejected: a
   network call and a config parse for something the orchestrator already holds: a PR waits exactly
   while its merge watch is armed.
4. **Another signal**: ending the turn on `AskUserQuestion` ("merged yet?"), or writing `state.json`.
   Rejected: the issue keeps the merge watch and "ask last" as they are; a question would hold the turn
   open against the other runs' notices, and `state.json` is Claude Code's file, rewritten on every turn.

## Design

### Which PRs wait

**A PR waits on the owner's merge while its merge watch is armed**: from `gh pr ready <P>` and the watch
(Step 5, also for an adopted run whose PR left draft) until the watch fires. It leaves the list when it
is MERGED (Step 6), closed without merge (its question goes through `AskUserQuestion`, as now), or put
back in draft by `gh pr ready --undo` for commits the owner wants. After a resume, Step 7 re-arms the
watches from the map, and the list with them. No new state is kept.

### The rule (`SKILL.md`)

- **Step 5**, the *Ready PR* sentence gains the line: tell the owner in two lines, open its proof page
  once, arm the merge watch, and end the report with the `needs input:` line (commands §Needs input).
  Then the rule itself: **while any merge watch is armed, every message that ends a turn ends with that
  line, naming every waiting PR, even while other runs are working**, because the job list reads only the
  latest message.
- **Step 6**: a merged PR leaves the line; with no merge watch armed, reports are plain status again.
- **Common mistakes**, one row: a ready PR announced once and plain status after it, with the 99 minutes
  of #109 as the why.
- "Ask last" is unchanged: the line is the last line of the message text, and a batched
  `AskUserQuestion` still comes after dispatch, watches and teardown.

`SKILL.md` stands at 1,343 words, so the mechanics go to `commands.md` and the skill carries only when
the line is due.

### The line (`needs_input.py`)

```
python3 ~/.claude/skills/orchestrate/needs_input.py <repo> <pr>:<issue> [<pr>:<issue> …]
```

| Unit | Does |
|---|---|
| `entry(repo, pr, issue, link)` | `PR #<pr> (#<issue>)`, plus ` https://github.com/<repo>/pull/<pr>` when `link` |
| `request(repo, pairs)` | the text after the colon, at most `LIMIT = 200` characters (below) |
| `main()` | parses the arguments, prints `needs input: <request>` or nothing |

- Pairs are de-duplicated and ordered by PR number, so the line is the same on every report.
- `request` is the first of these that fits in 200 characters:
  1. `merge ` + the entries with their links, joined by `, `;
  2. the same without links (the links stand in the report above the line);
  3. as many linkless entries as fit, followed by ` +<n> more`.
- No pairs: prints nothing and exits 0, so the orchestrator can run it before every report without a
  condition.
- A repo that is not `owner/name`, or a pair that is not `<digits>:<digits>`: usage on stderr, exit 2.
- No `gh` call, no file read: pure formatting, like `owners.py` a single Python file with a docstring
  that carries the usage and the classifier facts above.

Examples:

```
needs input: merge PR #109 (#91) https://github.com/IT4WEBBV/LaravelClaudeMd/pull/109
needs input: merge PR #109 (#91), PR #118 (#104), PR #121 (#113)
```

The issue's example says `, CI green`. The line leaves it out: the script cannot know it, and a restated
claim nobody re-checked is worth less than no claim (*Assumptions* 2). The two-line announcement in
Step 5 still says what the gate answered.

### Where the line goes (`commands.md`, §Needs input, after §Watch)

The command, the two example lines, and four placement rules with the reason in one sentence (the job
list reads the last 800 characters of the latest message, Claude Code 2.1.285):

1. the line is the **last line** of the message, copied as the script printed it;
2. plain text, **not in a code block**;
3. nothing after it, and no "no action needed" or "nothing needed from you" in the closing lines;
4. one line: the script already put every waiting PR on it.

### What does not change

The merge watch, teardown, the cap of four working runs (a waiting PR still does not count), "ask last"
through `AskUserQuestion`, `owners.py`, and the pipeline.

## Tests

Written first, seen red.

**`skills/orchestrate/tests/needs_input_test.sh`** (bash, like `owners_test.sh`; ends `PASS needs_input.py`):

- one PR, repo `acme/app`: the exact line with its link;
- three PRs given out of order and one of them twice: the exact line, ordered by PR number, each once,
  with links;
- four PRs: the exact line without links (with links it passes 200);
- forty PRs: the text after `needs input: ` is at most 200 characters and ends in ` +<n> more`, and the
  PRs shown plus `n` make forty;
- no PRs: no output, exit 0;
- `acme` as repo, and a pair `12:x`: exit 2, nothing on stdout;
- **every line printed above matches the classifier's pattern** (copied into the test with its version,
  2.1.285) and the capture equals everything after `needs input: `: the proof that the job list reads
  the whole line;
- the pins the issue asks for: the `5.` line and the `6.` line of `SKILL.md` each contain
  `` `needs input:` ``, and `commands.md` contains `needs_input.py`.

`bash skills/orchestrate/tests/owners_test.sh` still passes (untouched).

**The skill edit** follows `superpowers:writing-skills` where the `implement` leg can start an agent: one
small scenario (a PR made ready earlier, a second run's completion notice arrives, "write the report"),
against the current and the changed `SKILL.md`. The numbers go in the PR body; no scenario file is
committed. Where it cannot start an agent, the PR body says the scenario was not run.

**What no suite can show**: the job list going to needs-input and back. The issue's second *Done when*
line is the owner's to see in the first batch after the merge; the PR body says so.

## Out of scope

- A draft PR whose `gh pr ready` was denied, which also waits on the owner. It stays a line in the
  report, as Step 5 has it (*Assumptions* 5).
- The base's own PR into the default branch in a batch on a base: it is not a run's PR.
- The run status line (`statusline.php`): it shows unfinished runs, and a run with a ready PR is done.
- Re-checking CI or mergeability on every report.

## Assumptions

Questions the brainstorm would have asked the owner, with the answer assumed.

1. **A rule in prose, or a script for the line?** A script: the 200-character limit and the marker's
   shape fail silently when written by hand, and "a test pins the line" is then a test of the line.
2. **Does the line say `CI green`, as the issue's example does?** No. The issue gives it "for example";
   the line is restated on every report and nothing re-checks CI then. A PR is only made ready after the
   gate answered `ready`, and the announcement says so once.
3. **One line for all waiting PRs, or one line each?** One. The job list shows a single `needs` text (the
   classifier keeps the last marker it finds), so a line per PR would show only the last PR.
4. **What when the links do not fit in 200 characters?** They are dropped from the line, all or none, so
   the line's shape does not vary per PR. The report above the line and the job's PR children carry them.
   Past that, ` +<n> more`, so the line never grows out of the pattern.
5. **Does a denied `gh pr ready` count as waiting on the owner?** Not in this change: the issue scopes
   the line to a PR ready for the merge and lists the rest as unchanged. It is a natural follow-up.
6. **Does the rule cover every message, or only "reports"?** Every message that ends a turn while a merge
   watch is armed: the classifier reads whichever message is latest, a one-line "dispatched #124"
   included.
7. **A phone notification on every such turn?** Accepted. `blocked` is what pings the owner, and the
   issue asks for the line on every report while a PR waits. It is a true "come back", not a false one.
8. **Which PR number order?** Ascending by PR number, de-duplicated, so consecutive reports print the
   same line.
9. **Does Step 7 (resume) need text?** No. It re-arms the merge watches, and the rule hangs on an armed
   watch.
10. **Python or PHP?** Python with a bash fixture test, as `owners.py`: `orchestrate` has no PHP suite,
    and the script shares nothing with the pipeline's checks.
11. **Where is the classifier's pattern kept?** In the script's docstring and the test, with the CLI
    version. A later CLI that changes it is found by the owner in the job list, not by the suite; the
    test proves the line fits the pattern as read on 2026-09-30.
12. **Changelog?** This repository has no `.changelog/` and no `CHANGELOG.md`, so none is written.
13. **Does this PR close #112?** Yes; the job-list line of *Done when* is confirmed by the owner in the
    next batch. `review-pr` settles the closing link (engine.md §Closing links).
