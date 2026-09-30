# The review-pr leg's briefs say the leg is not the /review-pr skill — design

**Design size:** Architectural

**Date:** 2026-09-30
**Issue:** IT4WEBBV/LaravelClaudeMd#127
**Canonical home:** `pipeline_leg_overrides()` (`skills/pipeline/checks/brief.php`), the `review-pr:review`
and `review-pr:resolve` entries; pipeline `references/engine.md` (§Who takes the PR out of draft).
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. Every question the brainstorm
would have asked is answered in *Assumptions*, so `/critique plan` audits exactly those. Nothing below was
built or run; the one measurement (*Measured*) is a read-only grep over session transcripts.

## Problem

The pipeline's last leg is named `review-pr`. `IT4WEBBV/DevOps-Claude-Config` ships a skill of the same
name, linked into every session as `~/.claude/skills/review-pr`. The leg does not use it: its review step
invokes `/critique pr` (in `autoflow` it applies that procedure itself), and its resolve step is the
finish step.

Both briefs open with the collision (`pipeline_brief_role()`):

> You are the `review-pr` leg, `<step>` step, of a `/pipeline <mode>` run.

and nothing in them says the skill of that name is not meant. A step agent that reads the role line as
"run `/review-pr`" gets, from `review-pr/SKILL.md`:

- step 1, `gh pr ready --undo <pr>`: harmless on a draft PR;
- step 7, a review comment posted on the PR, in Dutch: `critique/SKILL.md` forbids a reviewer from
  posting or changing the PR's state, and the review step's brief says "Act on nothing";
- step 8, `gh pr ready <pr>` on a clean and complete review: a ready PR before the CI gate, which is the
  guarantee engine.md §Who takes the PR out of draft and §The CI gate exist for.

### Measured

Session transcripts on this machine (`~/.claude/projects/**/*.jsonl`, 1720 files, 2026-09-30), one of the
owner's two machines:

| what | count |
|---|---|
| transcripts that ran `dispatch_cli.php brief … review-pr review\|resolve` (a step agent, or the session that holds it) | 192 |
| of those, holding a `Skill` call naming `review-pr` | **0** |
| any transcript holding a `Skill` call naming `review-pr` | 14 |
| of those 14, mentioning a pipeline `review-pr` step or `dispatch_cli.php brief` at all | 0 |

The patterns, run from `~/.claude/projects`:

```bash
grep -rlE 'dispatch_cli\.php brief [^"]* review-pr (review|resolve)' --include='*.jsonl' .
grep -rlE '"name":"Skill","input":\{[^}]*"skill":"(review-pr|[a-z-]+:review-pr)"' --include='*.jsonl' .
```

So no step agent has been seen invoking the skill; the 14 calls are direct `/review-pr` uses outside any
pipeline run. The change is preventive: the cost of the misread is a ready PR before the CI gate, and one
line removes it.

## Approaches

The owner settled option 1 of the issue (one line in the brief of both `review-pr` steps; not a rename of
the leg). What remains is where the line lives.

1. **A shared line in `pipeline_leg_overrides()`, first in both `review-pr` entries (chosen).** The
   function already holds every per-step instruction, shares lines through local variables (`$checks`,
   `$completeEntry`, `$actOnReview`), and is the list `LockStepTest` reads for the engine.md sections a
   brief names. The line is the same in both modes: an `interactive` step agent has the same skill linked.
2. **A sentence appended to `pipeline_brief_role()` when the leg is `review-pr`.** It would sit right
   beside the colliding words, but it puts a leg-specific conditional into the one generic part of the
   brief, outside the list `LockStepTest` reads. Rejected.
3. **A conditional in `pipeline_brief_overrides()`**, like the CI-round line. Those conditionals depend on
   manifest state; this line depends on nothing but the step. Rejected.

## Design

### The line (`pipeline_leg_overrides()`, `brief.php`)

A local variable beside `$checks`, used as the first element of `review-pr:review` and of
`review-pr:resolve`:

```php
$notTheSkill = 'The leg\'s name is not a skill to invoke: do not invoke the `review-pr` skill (`/review-pr`), which posts its own review comment and changes the PR\'s draft state. This brief is the whole step (engine.md §Who takes the PR out of draft).';
```

```php
'review-pr:review' => [
    $notTheSkill,
    // the three existing lines, unchanged
],
'review-pr:resolve' => [
    $notTheSkill,
    'You are the finish step.',
    // the rest unchanged
],
```

- Both modes get it: the variable is not inside an `$autoflow` branch.
- It is the first of the step's own overrides. On `review-pr:resolve` the catch-up line, when there is
  one, still comes before it: `pipeline_brief_overrides()` prepends that line to the step's list.
- It names the skill both ways a session sees it (`review-pr` in the skill listing, `/review-pr` as a
  command) and says what the skill would do, so the instruction is not a bare prohibition.
- `§Who takes the PR out of draft` is an existing engine.md heading, so *keeps every engine.md section a
  brief names* (`LockStepTest.php`) holds without a new section.
- No other step's brief changes: `pipeline_leg_overrides()` is keyed `<leg>:<step>`.

### Docs (`references/engine.md`)

§Who takes the PR out of draft, one paragraph after the `autoflow` paragraph: the leg shares its name with
the `review-pr` skill of `DevOps-Claude-Config` and does not use it; that skill settles the draft status
itself (`gh pr ready` on a clean review), which would come before the CI gate, so both `review-pr` steps'
briefs say not to invoke it. No new `## ` heading.

§What a leg brief consists of is not changed: the line is one of "the overrides for that leg and step".

### What does not change

The leg's name, `pipeline_legs()`, the gate name `pr-review`, the manifest, `pipeline-autoflow.js`,
`pipeline_brief_role()`, `SKILL.md`, `gates.md`, `manifest.md`, the `review-pr` skill itself (another
repository), and `orchestrate`.

### The record on the issue

The issue's *Done when* asks for the transcript count to be recorded on the issue. `implement` posts one
comment on #127 with the *Measured* table and the two patterns, re-running the two greps first so the
numbers are those of the day it posts. The comment is an impersonal record (repository `CLAUDE.md`, *Never
address a human*): what was counted, where, and the counts; it says the count covers one machine.

## Tests

Written first, seen red (`skills/pipeline/checks/tests/BriefTest.php`):

- New, *tells both review-pr steps the leg is not the review-pr skill, in either mode*: for `mode` in
  `autoflow`, `interactive` and `step` in `review`, `resolve` (the resolve brief over a manifest whose
  ledger holds an open `pr-review` entry, as the neighbouring tests build it), `pipeline_brief()` contains
  the full line, and the line comes before the step's next override (`strpos` of the line less than
  `strpos` of ``Apply `/critique`'s `` / ``Invoke `/critique pr` `` on review, of `You are the finish
  step.` on resolve).
- New, in the same test or beside it: no other step's brief contains `not a skill to invoke`: every leg
  of `pipeline_legs()` except `review-pr`, with its steps (`review-plan`: `review`, `resolve`; `design`:
  `run` in `interactive`, `spec` and `plan` in `autoflow`; the others `run` in both modes).

Existing tests that must still pass unchanged: *puts the catch-up line first on every step that writes to
the branch* (the catch-up line stays first on `review-pr:resolve`), *makes the review-pr resolve step the
finish step*, *leaves the PR draft at the autoflow finish step* (the new line holds no `gh pr ready`), and
`LockStepTest`'s *keeps every engine.md section a brief names*.

The whole pipeline suite passes (`vendor/bin/pest -c skills/pipeline/checks/phpunit.xml
--test-directory=skills/pipeline/checks/tests`).

## Out of scope

- Renaming the leg (the issue's option 2; not chosen by the owner).
- A mechanical guard (a hook or a return check that fails a step whose transcript holds the `Skill`
  call): nothing measured calls for it.
- The same kind of line for other legs: no other leg of `pipeline_legs()` shares its name with a linked
  skill (`handoff` is a leg that does invoke the `handoff` skill).
- Changing the `review-pr` skill in `DevOps-Claude-Config`.

## Assumptions

Questions the brainstorm would have asked the owner, with the answer assumed.

1. **Both modes, or `autoflow` only?** Both: the owner's decision says "both review-pr steps" without a
   mode, and an `interactive` step agent has the same skill linked.
2. **Where in the brief?** First among the step's overrides, not in the role line (*Approaches* 2).
3. **Should the line say why?** Yes, in one clause (it posts a review comment and changes the draft
   state), and it points at §Who takes the PR out of draft; a bare "do not invoke" invites a step to
   reason the skill is what the leg means anyway.
4. **Does the `interactive` finish step's own `gh pr ready` contradict "changes the PR's draft state"?**
   No: the line forbids the skill, not the finish step's gated `gh pr ready`, which its own override
   still orders after the CI gate.
5. **Who records the transcript count on the issue, and when?** `implement`, as one comment on #127, with
   fresh numbers. This step only measured: a design step writes the spec and the manifest, nothing else.
6. **Is a count from one machine enough?** Yes for the record, stated as such; the other machine's
   transcripts are not reachable from here, and the change does not depend on the count.
7. **Does engine.md need the sentence?** Yes: the brief points at the section, so the section says it.
8. **Does this PR close #127?** Yes; `review-pr` settles the closing links (engine.md §Closing links).
9. **Changelog?** This repository has no `.changelog/` and no `CHANGELOG.md`, so none is written.
10. **Sibling runs (#52, #105) may change `brief.php` or engine.md too.** A later step merges the base
    when its brief says so (engine.md §Catching up with the base); this design adds one variable and two
    array elements, so a conflict there is resolved keeping both sides.
