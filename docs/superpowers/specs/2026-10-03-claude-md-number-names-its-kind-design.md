# Every #number says whether it is an issue or a PR — design

**Design size:** Architectural (an `autoflow` run without `medium` or `light`: the brief requires this path)

**Date:** 2026-10-03
**Issue:** IT4WEBBV/LaravelClaudeMd#172
**Canonical home:** `CLAUDE.md` §Programming Philosophy, the bullet *Name work by what it does, not by its number*
(line 39).
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. The issue body is the approved scope.
Every question the brainstorm would have asked is answered in *Assumptions*, so `/critique plan` audits exactly
those. Nothing was built or probed: the change is prose, and every claim below was checked by reading or grep.

## Problem

The rule on line 39 already makes every issue, PR or run come with a few plain words on what it is about, but
its own example puts a bare number in the parentheses: "CI gate fix (#149) and proof back link (#153)". GitHub
numbers issues and pull requests from one sequence, so `#149` does not say whether it is work still to be built
(an issue) or a change waiting for review or merge (a PR). The reader has to open the link, or guess, before
they can decide anything about it. Because the example is the part of a rule that gets copied, every report,
`AskUserQuestion` option and posted comment that follows it carries the same ambiguity.

## Approaches

1. **Extend the existing bullet in place (chosen).** The title gains "and say what kind it is", the parentheses
   carry the kind before the number, the example is rewritten, and one sentence covers the cases the issue
   names: two linked items, comments, another repo. One rule about naming work stays one bullet, which is what
   the issue's acceptance asks for ("the rule in `CLAUDE.md` names the kind").
2. **A separate bullet after it** ("Every #number names its kind"). Rejected: two bullets about the same
   reference, each with its own example, invite one being followed without the other, and the first bullet's
   example would still need rewriting to stop contradicting the second.
3. **A hook that flags a bare `#N` in output.** Rejected: a hook sees chat text only after the turn (a
   `Stop` hook reading the transcript), when the reader already has it, and a regex cannot tell prose from
   syntax that must stay bare (`Depends on #N`, `Closes #N`). Taking the
   LLM out of the loop pays when a rule keeps being broken; this one has not been tried yet.

## Design

Line 39 of `CLAUDE.md` becomes exactly this (one line, as it is today; the file's other long bullets in that
section are single lines as well):

```markdown
- **Name work by what it does, not by its number, and say what kind it is.** Whenever issues, PRs or runs come up for me to choose between or follow, give each a few plain words on what it is about, with its kind and number after it in parentheses: "CI gate fix (PR #149) and proof back link (issue #153) now", not "#149 + #153 now" and not "CI gate fix (#149)". Issues and PRs share one number sequence, so a bare `#153` doesn't tell me whether it is still to be built or waiting for review. The kind goes with every number, also when two are linked ("issue #153 is fixed by PR #160") and in PR and issue comments; a number in another repo carries the repo name ("Deploy PR #408"). A bare list of numbers gives me nothing to decide on. This holds for `AskUserQuestion` labels and descriptions too.
```

What changes, against today's line:

| part | today | after |
|---|---|---|
| title | *Name work by what it does, not by its number.* | *…, and say what kind it is.* |
| instruction | "with the number after it in parentheses" | "with its kind and number after it in parentheses" |
| example | "CI gate fix (#149) and proof back link (#153) now", not "#149 + #153 now" | "CI gate fix (PR #149) and proof back link (issue #153) now", not "#149 + #153 now" and not "CI gate fix (#149)" |
| why | — | one sentence: one number sequence, so the kind is the decision-relevant part |
| reach | `AskUserQuestion` labels and descriptions | also linked pairs, PR and issue comments, other repos (with an example each) |

The two closing sentences ("A bare list of numbers…", "This holds for `AskUserQuestion`…") stay as they are.

Nothing else in the repo changes. `CHANGELOG.md` and `.changelog/` do not exist in this repo, so there is no
changelog entry (`CLAUDE.md` §Git Workflow asks for one only in the project's own convention).

## Verification

`CLAUDE.md` has no test harness; the change is checked by grep in the worktree:

- `grep -c 'CI gate fix (PR #149) and proof back link (issue #153)' CLAUDE.md` → `1`
- `grep -c 'issue #153 is fixed by PR #160' CLAUDE.md` → `1`
- `grep -c 'Deploy PR #408' CLAUDE.md` → `1`
- `grep -cE '\((#[0-9]+)\) and proof back link' CLAUDE.md` → `0` (the old example is gone)
- `grep -c 'Depends on #N' CLAUDE.md` → `1` (the machine-read syntax on the Git Workflow bullet is untouched)
- `git diff --stat origin/main...HEAD -- CLAUDE.md` → one file, one line changed.

There is no UI, so no visual proof.

## Assumptions

1. **Scope: `CLAUDE.md` only, or also the skills that print numbers?** Assumed `CLAUDE.md` only, as the issue's
   *Acceptance* lists. `skills/orchestrate/needs_input.py:37` prints `PR #109 (#91)`, whose `#91` is an issue
   left bare; it is a job-list marker matched within 200 characters after the colon (commands.md §Needs input)
   and it already carries no plain words, so it follows its own format, not this rule. It is also in the
   orchestrate files a sibling run (PR #176) is changing. Left as is.
2. **Do provenance citations in skill references count?** `engine.md` and the skill files cite their history as
   `(#87)`, `(#134)`. Assumed no: those are read by agents running the skill, not shown to the owner to choose
   between or follow, which is where the rule applies. Not changed.
3. **Syntax that tools parse stays bare.** `Depends on #N` (read by `/orchestrate`), `Closes #N` / `Fixes #N`
   (GitHub's closing keywords, which do not match "Closes issue #N"), and the `(#60)` suffix on commit subjects
   are syntax, not a reference written for the reader. Assumed exempt without saying so in the rule: the rule
   is about naming work for the owner to read, and the `Depends on #N` bullet a few lines further down already
   prescribes its own form. Not stated in the new line, to keep it about what to do.
4. **"Runs" have no number of their own.** The rule names issues, PRs and runs; a `/pipeline` run is named by its
   issue or its PR, so the kind is "issue" or "PR". Assumed no third kind ("run #…") is needed.
5. **Spelling of the kind.** "PR #149" and "issue #153": `PR` in capitals as GitHub and the rest of `CLAUDE.md`
   write it, `issue` lower case mid-sentence. In Dutch text the same tokens (`PR`, `issue`) are used, as the
   owner already writes them.
6. **The machine-local memory file** `describe-issues-not-numbers.md` repeats the old rule. It lives outside the
   repo (`~/.claude/projects/*/memory`), so this PR cannot change it; `CLAUDE.md` loads in every session and
   wins. Not touched.
7. **Line wrapping.** The bullet stays a single line, matching lines 37–40 around it, rather than wrapped at 100
   columns like the bullets further down; a reflow would make the diff hide the wording change.
