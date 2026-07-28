# Conversational `/critique` Output and the Gate Collapse — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `/critique` write a conversational review instead of a mandated table, and delete the severity/verdict machinery that existed only to decide when an `auto` pipeline run should interrupt the human.

**Architecture:** Almost entirely documentation. Two skills are edited — `critique` (stops shaping its output) and `pipeline` (stops asking for a structure). Two PHP functions and their tests are deleted; no PHP is added. `pipeline_can_navigate()` and the un-skippable-review promise are untouched throughout.

**Tech Stack:** Markdown skill files; PHP 8 helpers under `skills/*/checks/`; Pest for the helper tests.

**Spec:** `docs/superpowers/specs/2026-07-28-critique-conversational-output-design.md` — the authority. Where this plan and the spec disagree, the spec wins; raise the conflict rather than guessing.

## Global Constraints

- **Branch:** `feature/conversational-critique-output`, in the worktree at `.claude/worktrees/conversational-critique`. Never commit to `main`.
- **Commits:** no `Co-Authored-By` lines, no "Generated with Claude Code" or any AI attribution. (Repo CLAUDE.md §Git Workflow.)
- **No changelog entry.** This repo has neither a `.changelog/` directory nor a `CHANGELOG.md`; the CLAUDE.md changelog rule is conditional on one existing.
- **`vendor/` is gitignored and absent in a fresh worktree.** Run `composer install` once in the worktree before the first test run. Verified working.
- **Test commands** (from the worktree root):
  - `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
  - `./vendor/bin/pest -c skills/critique/checks/phpunit.xml --test-directory=skills/critique/checks/tests`
- **Baselines to preserve:** pipeline 25 passed / 85 assertions *before* Task 4 (23 after, once two tests are removed); critique 11 passed / 31 assertions throughout — `critique/checks/*.php` and its tests must not change at all. If a critique test moves, the spec's tier-free claim was wrong: stop and report.
- **Vocabulary being retired**, everywhere in both skills: `tier` / `Tier 1|2|3`, `CONFIRMED` / `PLAUSIBLE`, `verdict block`, `architecture_judgment`, `suggested_action`, `cap at 15`, the drop tally, `gate_policy`, `escalate` / `escalated`, `report`-as-a-gate-policy.
- **Vocabulary being kept**, deliberately, so greps will hit it: `cycle` (carries `"unknown"` after reconstruction), `adjudicat*` (survives as an optional independent read, never a routing rule), `looped-back`, `halt`.

## File Structure

| File | Responsibility after this change |
|---|---|
| `skills/critique/SKILL.md` | the four modes, target assembly, and a reviewer instruction that mandates no output shape |
| `skills/critique/references/rubrics.md` | what each mode looks for, and the evidence standards — no severity taxonomy |
| `skills/pipeline/references/engine.md` | the loop, the stations, what `auto` does with a review, and what still stops a run |
| `skills/pipeline/references/gates.md` | mode semantics (absorbed from the deleted PHP), content triggers, the navigation guardrail |
| `skills/pipeline/references/manifest.md` | the cursor file's fields and the `gate_ledger` audit shape |
| `skills/pipeline/checks/pipeline.php` | leg ordering and navigation only |
| `skills/pipeline/checks/tests/PipelineTest.php` | tests for the above |

Docs are edited before the PHP is deleted, so the worst intermediate state is a live function nothing references — not a doc pointing at a function that is gone.

---

### Task 1: `/critique` writes a review

**Files:**
- Modify: `skills/critique/SKILL.md`
- Modify: `skills/critique/references/rubrics.md`

**Interfaces:**
- Consumes: nothing — this task is self-contained and has no pipeline dependency.
- Produces: the guarantee later tasks rely on — **`/critique` returns prose and nothing else.** No caller may assume a field, a tier, a verdict word, or a parseable block.

- [ ] **Step 1: Set up the worktree's dependencies (once)**

```bash
cd /Users/jroelofs/GitProjects/LaravelClaudeMd/LaravelClaudeMd/.claude/worktrees/conversational-critique
composer install
./vendor/bin/pest -c skills/critique/checks/phpunit.xml --test-directory=skills/critique/checks/tests
```

Expected: `Tests: 11 passed (31 assertions)`. This is the baseline that must never move.

- [ ] **Step 2: Add the non-goal to the skill's purpose paragraph**

In `skills/critique/SKILL.md`, the paragraph beginning `**What this skill is for**` ends with `...divergent generation on demand, when nobody is present to ask for options.` Append this sentence to that paragraph:

```markdown
What it is emphatically **not** for is shaping the review's prose: the reviewer writes what the
review wants to be, and no stage of this skill reformats it.
```

- [ ] **Step 3: Replace Stage 2 (review)**

Replace the whole `### Stage 2 — review` section with:

```markdown
### Stage 2 — review

**One agent.** It receives the assembled target, the mode's rubric from `references/rubrics.md`,
and the heuristic candidates from stage 0. It writes the review.

**No output contract.** There is no table, no severity tier, no score, no required section, no cap
on how much or how little it says. If the useful thing is one paragraph about a single flaw, that
is the review. Two pieces of guidance, not format:

- Cite `file:line` when pointing at something specific.
- When claiming something is a problem, say what breaks and when — inputs or circumstances leading
  to a bad outcome. If that cannot be said, it is taste; voice it as taste rather than dressing it
  as a defect.

End with a plain bottom line: ready, ready with fixes, or needs rework, and why in a sentence.

Model configurable, **Fable by default**. Reasoning effort is session-level — there is no
per-dispatch override.
```

- [ ] **Step 4: Delete Stage 3 and renumber the rest**

Delete `### Stage 3 — filter and shape (the consumer's job, not the reviewer's)` entirely — heading and body. Then renumber the survivors so the pipeline reads 0, 1, 2, 3, 4, 5:

| Was | Becomes |
|---|---|
| Stage 4 — report in chat | **Stage 3 — report in chat** |
| Stage 5 — triage | **Stage 4 — triage** |
| Stage 6 — feed the rubric, on a pattern | **Stage 5 — feed the rubric, on a pattern** |

- [ ] **Step 5: Replace the (now) Stage 3 report section**

Replace the body of `### Stage 3 — report in chat` with:

```markdown
The review, as the reviewer wrote it. Do not reformat it into a table, re-rank it, summarise it
into bullets, or drop parts of it. Relaying it faithfully is the whole job — a consumer that
reshapes the review reintroduces exactly what this skill stopped doing.

State the mode and the assembled target before it (Stage 0), so the reader knows what was reviewed.
```

- [ ] **Step 6: Replace the (now) Stage 4 triage section**

Replace the body of `### Stage 4 — triage` with:

```markdown
Decide what to act on. *How* to evaluate a review — verify before implementing, no performative
agreement, push back with reasoning, stop if something is unclear — is
`superpowers:receiving-code-review`'s job; this skill points at it rather than restating it.
Nothing is filed or posted unless chosen.
```

- [ ] **Step 7: Reframe the (now) Stage 5 rubric-feedback section**

In `### Stage 5 — feed the rubric, on a pattern`, replace `When you notice the same category dropped repeatedly — within a session, or against a pasted prior report — surface the pattern and` with:

```markdown
When the same issue keeps recurring across reviews — within a session, or against a pasted prior
report — surface the pattern and
```

Leave the rest of that section verbatim: the amendments-are-hand-authored-and-need-approval guard is unchanged and load-bearing.

- [ ] **Step 8: Rewrite `--verify`**

Replace the body of the `## \`--verify\`` section with:

```markdown
Dispatches a skeptic over the review's checkable claims — the ones asserting that something in the
code is wrong at a location. It reports which hold up against the code and which do not, in prose.
No effect in `plan` or `missing`, where the claims are about absence and judgment and have no
positive evidence to check.
```

- [ ] **Step 9: Update the rubrics file's own header**

In `skills/critique/references/rubrics.md`, replace lines 3-5 (`Reference for the four /critique modes: hazard classes, principles pointer, tier definitions, category slugs, and the drop tally. The SKILL.md pipeline reads this file; it is not loaded until a run needs it.`) with:

```markdown
Reference for the four `/critique` modes: hazard classes, the principles pointer, the evidence
standards, and the stage-0 category slugs. The SKILL.md pipeline reads this file; it is not loaded
until a run needs it.
```

- [ ] **Step 10: Drop the per-class verdict requirement**

Two places require a verdict per hazard class. Replace the numbered item at `rubrics.md:10-11`:

```markdown
1. **Hazard classes** — generative questions. Consider every one of them; mention the ones where
   there is something to say, including "checked the in-flight jobs, nothing" where that is
   informative. What is required is the *coverage*, not a recital of a verdict per class.
```

And in `## Mode: \`pr\``, replace `Hazard classes over the whole change plus the stage-0 candidates. **Each class returns a verdict.** Production state is not knowable from a diff` with:

```markdown
Hazard classes over the whole change plus the stage-0 candidates. Production state is not knowable
from a diff
```

- [ ] **Step 11: Delete the Tiers and Verdicts sections**

Delete `## Tiers` (heading through the "whatever it looks like" paragraph) and `## Verdicts (\`pr\` mode only)` (heading through "trained into the reader as ignorable"). In their place, put a single section:

```markdown
## What is worth reporting

A finding is worth reporting when you can say what breaks and under what circumstances — inputs or
state leading to a wrong output, a crash, corrupted data, a contract someone else depends on.

If you cannot say what breaks, it is taste. That is still worth voicing when it matters; say so
plainly rather than dressing it as a defect. What is not acceptable is a claim shaped like a defect
with no consequence behind it.
```

- [ ] **Step 12: Reword the principles-layer closer**

At the end of `## Principles (pointer, not a copy)`, replace `Findings in the principles layer must still name a concrete failure scenario — that is what stops taste from becoming a Tier 3 preference.` with:

```markdown
The principles layer is where taste is most likely to masquerade as a defect, so it is where the
§What is worth reporting standard matters most: say what breaks, or say it is taste.
```

- [ ] **Step 13: Trim the category-slug section**

In `## Category slugs`, replace the opening sentence `Findings carry a kebab-case category slug so recurring noise sources become countable. Stage-0 slugs (emitted by \`checks/run-checks.php\`):` with:

```markdown
The stage-0 checks emit a kebab-case category slug per candidate, so the deterministic layer's
output is countable. These are the checks' own labels; the reviewer is not required to use them or
to invent any of its own.
```

Keep the slug table verbatim. Delete the closing line `Reviewer findings use their own descriptive slugs (e.g. \`hardcoded-url\`, \`missing-edge-case\`, \`plan-drift\`, \`contract-break\`).`

- [ ] **Step 14: Reword the rubric-amendment section**

In `## Amending this rubric`, replace `There is **no persistent drop tally and no state between runs.** When the same category is dropped repeatedly — within a session, or against a pasted prior report — that is a signal the rubric is mis-scoped there.` with:

```markdown
There is **no state between runs.** When the same issue keeps recurring — within a session, or
against a pasted prior report — that is a signal the rubric is mis-scoped there.
```

Leave the rest verbatim.

- [ ] **Step 15: Verify no retired vocabulary survives in `critique/`**

```bash
grep -rniE 'tier|CONFIRMED|PLAUSIBLE|verdict block|architecture_judgment|cap at 15|drop tally|drop policy|scored' skills/critique/ --include='*.md'
```

Expected: **no output.** Any hit is a miss — fix it before committing.

- [ ] **Step 16: Confirm the critique checks are untouched**

```bash
git status --short skills/critique/checks/
./vendor/bin/pest -c skills/critique/checks/phpunit.xml --test-directory=skills/critique/checks/tests
```

Expected: `git status` prints nothing for that path, and `Tests: 11 passed (31 assertions)`.

- [ ] **Step 17: Commit**

```bash
git add skills/critique/
git commit -m "feat(critique): the reviewer writes the review, not a table

Deletes the scored-candidate protocol, the tier taxonomy, the
CONFIRMED/PLAUSIBLE labels and the consumer-side drop policy. Stage 2
now hands over the target and the rubric and asks for a review; Stage 3
relays it unchanged.

The evidence standard survives as a standard rather than a filter: say
what breaks, or say it is taste."
```

---

### Task 2: `engine.md` — the verdict block goes, the triage collapses

**Files:**
- Modify: `skills/pipeline/references/engine.md`

**Interfaces:**
- Consumes: Task 1's guarantee — `/critique` returns prose only.
- Produces: the vocabulary Tasks 3 and 4 mirror — mode `auto` vs `interactive`; loop-back destinations `review-plan → design`, `verify-ui → implement`, `review-pr → implement`; ledger `outcome` values `continued` / `looped-back` / `halted`; the section name `§Failure policy` that other files cite.

- [ ] **Step 1: Update the two review station rows**

In the `## Stations` table, replace the `review-plan` row's third and fourth cells (`reviewer scores the plan; you verdict` and `read-only reviewer subagent returning the **verdict block** (below)`) with, respectively:

```
reviewer writes a review; you read it and decide
```
```
read-only reviewer subagent writes a review; the engine reads it and acts (§`auto`)
```

And in the same row's last cell, replace `feeds the plan-approval gate **and** the project-vs-package judgment` with `feeds the plan-approval gate; the project-vs-package call arrives as part of the review`.

Apply the same two replacements to the `review-pr` row (`reviewer scores the whole change; you verdict` → `reviewer writes a review; you read it and decide`; the verdict-block cell → `read-only reviewer subagent writes a review; the engine reads it and acts (§\`auto\`)`).

- [ ] **Step 2: Delete the verdict-block section**

Delete the entire `## The verdict block — a review leg's return contract` section: the heading, the three-row field table, the "The pipeline owns the translation" paragraph, and the "A malformed or missing block is a machinery failure" paragraph. Nothing replaces it in place — its subject no longer exists.

- [ ] **Step 3: Replace the adjudicate-policy section**

Replace the whole `## Gate policy \`adjudicate\` — reviews are proposals, not verdicts` section (heading through the "Log" paragraph, ending `...is what turns a gate into decoration.*`) with:

```markdown
## `auto` — the engine resolves the review itself

`interactive` stops at every gate: the human reads the review and decides, and none of this section
runs. Everything below is the `auto` path.

**A review is prose, not a verdict.** `/critique` returns the review it wrote — no tiers, no verdict
enum, no structured block (`../../critique/SKILL.md`). The engine reads it the way a person would
and acts on its own judgment. The risk position behind that: the pipeline never merges, so every
output is a PR read before merge and the worst case is a discarded branch, while a needless
interrupt costs the one thing `auto` exists to protect.

**What the engine does with a review:**

- **Act on what is worth acting on.** Apply the fixes to the spec, the plan or the code and commit
  them. Record the rest — already-mitigated observations, notes for posterity — without an edit.
- **Loop back** where the review says the work is fundamentally wrong: `review-plan` → `design`,
  `verify-ui` → `implement`, `review-pr` → `implement`. Bounded (§Failure policy).
- **Never interrupt on a finding.** Anything unresolved goes into the PR body as an open question,
  carried **verbatim**. Ambiguity buys a line in the PR, not an interrupt.
- **Log** the review, the actions taken and the outcome to the manifest's `gate_ledger`
  (`manifest.md`), projected onto the PR. *Overruling a reviewer is fine; overruling one invisibly
  is what turns a gate into decoration.*

**An independent read is available, and is not a routing rule.** At `review-plan` the engine is
judging a critique of a plan it just wrote — the self-review bias `/critique` exists as a separate
agent to avoid. So where acting on a point is expensive and the engine doubts it, dispatch a
**fresh subagent that never saw the design leg**, give it the point plus the code, and ask it to
refute the claim citing `file:line`. That is judgment exercised where it pays, not a mandatory step
with an outcome enum — and it cannot stop the run; it only informs what the engine does next.
```

- [ ] **Step 4: Replace the failure-policy section**

Replace the whole `## Failure policy — what still stops` section (heading through the final `verify-ui` / `ui` trigger paragraph) with:

```markdown
## Failure policy — what still stops

Under `auto` these are the only stops. **No finding stops a run.**

- **Hard failure** — a station errors: tests will not go green, a tool dies, the stack will not
  start, `work-on` hits a blocker, or the reviewer returns nothing after a single retry. → **halt.**
  Write the failure to the manifest; a human resumes. **No silent retry** beyond that one — a retry
  hides the failure and the machinery may be in an unknown state.
- **Bound exhaustion.** Each loop-back is bounded to **2 cycles** per gate; on what would be the
  third, **halt in-session** — stop, leave the work in the worktree, and say why. The bound is what
  keeps an autonomous loop from churning indefinitely without ever surfacing.
  - **Before `handoff`** (`review-plan`) → **no branch push, no draft PR.** Twice-rejected work is
    not worth a PR round-trip; the human reads it live.
  - **After `handoff`** (`verify-ui`, `review-pr`) → the draft PR already exists, so there is
    nothing to not-push. Leave it **draft**, write the reason into the PR body, stop.
  - Record `outcome: halted`.
  - Count the cycles as the number of that gate's `gate_ledger` entries whose `outcome` is
    **`looped-back`** (`manifest.md`) — not its entries in total, which also include human-ordered
    re-reviews and would over-count into a spurious stop — and never from an in-memory counter.
  - **A count that cannot be read is not a count of zero.** The ledger lives in the disposable
    manifest, and no durable probe can rebuild it: git and gh record *that* a review happened, not
    how many times the engine looped. So a run whose manifest was **reconstructed** (`manifest.md`
    §reconstruction) carries an **unknown** cycle count, and unknown permits **no** loop-back — the
    next one halts immediately. Without this, a manifest lost mid-loop silently grants two fresh
    cycles, and one lost repeatedly grants them forever: the bound would stop bounding at exactly
    the moment it is load-bearing. A fresh run writes its own manifest at kickoff and is never
    reconstructed, so it is unaffected.
- **Mechanical-check exhaustion** (§Mechanical checks) — a check failure that survives its 2 fix
  attempts, or more than two `@phpstan-ignore` suppressions in one run → **the same bound-exhaustion
  halt.**
- **Playwright genuinely unavailable** → **halt.** No visual claim without proof.

In `interactive` mode every gate stops anyway, so the human sees the review and none of the `auto`
resolution runs.

The scary content facts — a migration, an authorization change, a shared package — **do not stop
the chain**: they are facts, not findings, so they become loud mandatory annotations on the PR and
in the ledger (`gates.md` §content triggers). `verify-ui` is untouched by that and stays mandatory
whenever the `ui` trigger fires.
```

- [ ] **Step 5: Remap the two mechanical-check escalations**

These are the rules the 2026-07-27 spec wrote assuming escalation would survive. In `## Mechanical checks`, in the **Check failure** row of the "Two failure kinds" table, replace `bounded to 2 attempts; escalate on the third` with:

```
bounded to 2 attempts; on the third, the bound-exhaustion halt (§Failure policy)
```

And replace `**More than two suppressions in one run escalates**, because the agent whose step is blocked is otherwise judging its own excuse.` with:

```markdown
**More than two suppressions in one run triggers the bound-exhaustion halt** (§Failure policy),
because the agent whose step is blocked is otherwise judging its own excuse.
```

- [ ] **Step 6: Verify the retired vocabulary is gone from `engine.md`**

```bash
grep -niE 'tier|verdict block|architecture_judgment|suggested_action|escalat|gate_policy|adjudicate policy' skills/pipeline/references/engine.md
```

Expected: hits **only** on the word `adjudicat*` inside the "An independent read is available" paragraph from Step 3. Every other hit is a miss. In particular there must be no surviving `escalat*`.

- [ ] **Step 7: Commit**

```bash
git add skills/pipeline/references/engine.md
git commit -m "feat(pipeline): auto reads the review instead of a verdict block

Deletes the verdict-block return contract and the tier-routed
adjudication triage. auto now reads the prose, acts, and loops back when
the work is wrong; nothing escalates on a finding.

Bound exhaustion halts in-session, and is now gate-specific: no PR before
handoff, leave the PR draft after it. The mechanical-checks layer's two
remaining escalate rules are remapped onto that halt — they came from a
spec that is not superseded here and assumed escalation would survive."
```

---

### Task 3: `gates.md` and `manifest.md`

**Files:**
- Modify: `skills/pipeline/references/gates.md`
- Modify: `skills/pipeline/references/manifest.md`

**Interfaces:**
- Consumes: Task 2's vocabulary — `§Failure policy`, `§\`auto\``, the `outcome` values, the loop-back destinations.
- Produces: the mode-resolution rule that Task 4 removes from PHP (`anything that is not \`auto\` behaves as \`interactive\``), and the final `gate_ledger` entry shape.

- [ ] **Step 1: Fix the `gates.md` opening**

In the intro paragraph, replace `and it reads neither the mode nor the gate policy.` with:

```markdown
and it reads neither the mode nor anything a review said.
```

- [ ] **Step 2: Replace the whole `## Modes` section**

Replace `## Modes — one choice, resolved to a policy table` — heading, the `pipeline_resolve_policy` paragraph, the table, the "Both gates always resolve to the same value" line, and the **Report-only override** paragraph — with:

```markdown
## Modes — one choice, two behaviours

`mode` is the only knob, and **anything that is not `auto` behaves as `interactive`** — the stricter
of the two. That fallback used to be asserted mechanically by `pipeline_resolve_policy()`; the
function is gone (its two gates were always identical to each other and a pure function of mode), so
the rule lives here and has to stay explicit: `manifest_validate` checks key *presence*, not value,
so a manifest with a mangled `mode` must still fail safe.

| Mode | Behaviour |
|---|---|
| **`interactive`** *(default)* | you are present; run one leg, show you the review, wait. Every point in it is yours to judge. Advance by saying so (see navigation). |
| **`auto`** | run the autonomous legs unattended. The reviews still run; the engine reads them and acts, looping back where the work is wrong and never interrupting on a finding (`engine.md` §`auto`). Hard failures and bound exhaustion still stop. |

There is no third mode and no per-gate override — both gates behave the same way within a mode. The
**report-only override** that once existed (`auto` with `plan-approval` flipped to `report` in a
stored `gate_policy`) is **deleted by decision, not oversight**: two of its three documented effects
— adjudicate nothing, escalate nothing — are now the default everywhere, which left only "do not
loop me back to `design`", and that did not justify a stored per-gate field of its own.

What no mode can do is stop a review *leg* from running — that is the navigation guardrail below.
```

- [ ] **Step 3: Fix the project-vs-package trigger row**

In the content-triggers table, replace the last row's second and third cells (`**none mechanical** — a \`/critique plan\` judgment, returned as the verdict block's \`architecture_judgment\`` and `adjudicated like a Tier-1 (\`engine.md\`)`) with:

```
**none mechanical** — a `/critique plan` judgment, made in prose (the `plan` rubric asks for it)
```
```
the engine acts on it like any other part of the review (`engine.md` §`auto`)
```

- [ ] **Step 4: Fix the Phase A call-list**

In `## How the engine calls Phase A`, replace `Navigation, policy and escalation are pure functions — call \`pipeline_can_navigate\` / \`pipeline_next_leg\` / \`pipeline_resolve_policy\` / \`pipeline_should_escalate\` directly (they take no I/O).` with:

```markdown
Navigation is pure functions — call `pipeline_can_navigate` / `pipeline_next_leg` /
`pipeline_gate_legs` directly (they take no I/O).
```

- [ ] **Step 5: Drop the `gate_policy` field row in `manifest.md`**

In the `## Fields` table, delete the row `| \`gate_policy\` | optional | the resolved per-gate table (see \`gates.md\`) |` entirely. In the same table, replace the `gate_ledger` row's purpose cell with:

```
the audit trail — each gate's review, what the engine or the human did about it, and the content-trigger annotations (shape below)
```

- [ ] **Step 6: Amend the "Pointers, never content" rule**

Replace that bullet in `## Two rules that keep the file honest` with:

```markdown
- **Pointers, never content — with one named exception.** Store the spec *path*, not the spec; the
  PR *number*, not the PR. The exception is `gate_ledger[].review`: a review has no durable source
  (`/critique` stores nothing by design) and the plan↔review loop runs entirely **before**
  `handoff`, so there is no PR body to recover it from. Everything else stays a pointer.
```

- [ ] **Step 7: Replace the `gate_ledger` JSON shape and key table**

Replace the `adjudicate`-policy sentence that opens the section (`Under the \`adjudicate\` policy the engine overrules reviewers routinely (\`engine.md\`). That is fine; doing it *invisibly* is not.`) with:

```markdown
Under `auto` the engine overrules reviewers routinely (`engine.md` §`auto`). That is fine; doing it
*invisibly* is not.
```

Replace the JSON block with:

```json
{
  "gate": "plan-approval",
  "leg": "review-plan",
  "cycle": 1,
  "at": "2026-07-28T11:04:22Z",
  "review": "Step 4 drops the closing-issue link, so the issue stays open after merge …",
  "annotations": ["migration", "auth"],
  "actions": [
    {
      "claim": "step 4 drops the closing-issue link",
      "disposition": "integrated",
      "note": "restored `Closes #1926`"
    }
  ],
  "outcome": "continued"
}
```

Replace the key table with:

```markdown
| Key | Values |
|---|---|
| `gate` | `plan-approval` \| `pr-review` \| `verify-ui` |
| `leg` | the leg that produced the entry |
| `cycle` | 1-based — which pass through this gate produced the entry; `"unknown"` after a reconstruction, which permits no further loop-back (§reconstruction) |
| `at` | timestamp; the audit trail's only ordering |
| `review` | the reviewer's text, verbatim — the one named exception to *Pointers, never content* above |
| `annotations` | the content triggers that fired (`package`, `migration`, `auth`) — facts, not findings |
| `actions[].claim` | the point from the review the engine or human acted on |
| `actions[].disposition` | `integrated` (edited and committed) \| `recorded` (logged, no edit) \| `open-question` (carried verbatim into the PR body) |
| `actions[].note` | what was done, or why it was not |
| `outcome` | `continued` \| `looped-back` \| `halted` |
```

Delete the sentence `\`disposition: escalated\` is exactly the set for which \`pipeline_should_escalate($finding, $adjudication)\` returns \`true\`.` — both the disposition and the function are gone.

- [ ] **Step 8: Fix the loop-bound and interactive-shape paragraphs**

In `**The loop bound is read from here, never from memory.**`, replace `A confirmed \`rework\` may loop back twice before it must escalate (\`engine.md\` §failure policy).` with:

```markdown
A review may drive a loop-back twice before the third must halt (`engine.md` §failure policy).
```

In the same paragraph, replace `counting those turns a single real loop-back into the forbidden third cycle, escalating for no reason` with:

```markdown
counting those turns a single real loop-back into the forbidden third cycle, halting for no reason
```

Replace the paragraph `An entry with \`"policy": "stop"\` is the \`interactive\` shape — \`annotations\` and \`verdict\` still recorded, every \`findings[].adjudication\` is \`none\`, and the human's decision is the \`outcome\`.` with:

```markdown
An `interactive` entry is the same shape with the human in the engine's place: `review` and
`annotations` still recorded, `actions` holding what the human decided, and their decision as the
`outcome`.
```

- [ ] **Step 9: Fix the reconstruction section**

In `**One field does not reconstruct, and it fails closed.**`, replace `an unknown count permits **no** further loop-back: the next confirmed \`rework\` or \`verify-ui\` failure escalates (\`engine.md\` §failure policy).` with:

```markdown
an unknown count permits **no** further loop-back: the next one halts (`engine.md` §failure policy).
```

Also update the `planApproved` probe row in the reconstruction table if it names a verdict — it should read `the \`gate_ledger\` holds a \`plan-approval\` entry with \`outcome: continued\``, which is already correct; confirm it and leave it.

- [ ] **Step 10: Verify both files**

```bash
grep -niE 'tier|verdict|escalat|gate_policy|architecture_judgment|report-only' skills/pipeline/references/gates.md skills/pipeline/references/manifest.md
```

Expected: exactly two hits, both in `gates.md` §Modes, both inside the sentence documenting that the report-only override was deleted by decision. Anything else is a miss.

- [ ] **Step 11: Commit**

```bash
git add skills/pipeline/references/gates.md skills/pipeline/references/manifest.md
git commit -m "feat(pipeline): absorb mode semantics, reshape the gate ledger

gates.md takes over the not-auto-is-interactive fallback from the PHP
that is about to be deleted, and records the report-only override as a
deliberate removal.

The ledger stores the review and what was done about it. gate, cycle and
at are retained: cycle carries \"unknown\" after a reconstruction, and
without it the loop-back bound silently stops bounding. Storing the
review text gets a named exception to the pointers-never-content rule
rather than quietly contradicting it."
```

---

### Task 4: Delete the two PHP functions and their tests

**Files:**
- Modify: `skills/pipeline/checks/tests/PipelineTest.php`
- Modify: `skills/pipeline/checks/pipeline.php`

**Interfaces:**
- Consumes: Task 3's `gates.md` §Modes, which now documents the fallback this PHP used to assert.
- Produces: a `pipeline.php` exporting exactly four functions — `pipeline_legs`, `pipeline_gate_legs`, `pipeline_next_leg`, `pipeline_can_navigate`.

Tests are changed **before** the functions, so each step is independently verifiable and nothing is deleted while something still calls it.

- [ ] **Step 1: Confirm the pre-deletion baseline**

```bash
./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests
```

Expected: `Tests: 25 passed (85 assertions)`.

- [ ] **Step 2: Delete the two tests for the functions being removed**

In `skills/pipeline/checks/tests/PipelineTest.php`, delete these two tests in full:

- `it('resolves interactive to stop at every gate and auto to adjudicate', …)`
- `it('escalates only a finding that is blocking *and* confirmed', …)`

- [ ] **Step 3: Rewrite the un-skippable-promise test so it no longer references policy**

Replace `it('keeps the un-skippable-review promise out of reach of the gate policy', …)` with a version making the same guarantee without a policy to be out of reach of:

```php
it('makes the un-skippable-review promise depend only on which legs have run', function () use ($uiOn, $uiOff) {
    // No mode, no policy, no review outcome is an input here — only $doneLegs.
    expect(pipeline_can_navigate('design', 'implement', [], $uiOff))->toBeFalse()
        ->and(pipeline_can_navigate('design', 'implement', ['review-plan'], $uiOff))->toBeTrue();

    // A triggered verify-ui is a gate leg too, and blocks the jump to review-pr until it has run.
    expect(pipeline_can_navigate('implement', 'review-pr', ['review-plan'], $uiOn))->toBeFalse()
        ->and(pipeline_can_navigate('implement', 'review-pr', ['review-plan', 'verify-ui'], $uiOn))->toBeTrue();
});
```

- [ ] **Step 4: Run the tests — they must pass with the functions still present**

```bash
./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests
```

Expected: `Tests: 23 passed`. This proves the remaining suite no longer touches the two doomed functions — the safe precondition for deleting them.

- [ ] **Step 5: Prove nothing else calls them**

```bash
grep -rn 'pipeline_should_escalate\|pipeline_resolve_policy' skills/ docs/ 2>/dev/null
```

Expected: hits only inside `skills/pipeline/checks/pipeline.php` (the definitions) and inside the two spec/plan documents describing this change. **No hit in any `references/*.md`** — if one appears, Task 2 or 3 missed a spot; fix it there before continuing.

- [ ] **Step 6: Delete the two functions**

In `skills/pipeline/checks/pipeline.php`, delete `pipeline_resolve_policy()` and `pipeline_should_escalate()` — each function's full docblock and body. The file ends after `pipeline_can_navigate()`.

- [ ] **Step 7: Run the tests again**

```bash
./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests
```

Expected: `Tests: 23 passed`. Same count as Step 4 — the deletion changed no behaviour, which is the point.

- [ ] **Step 8: Commit**

```bash
git add skills/pipeline/checks/pipeline.php skills/pipeline/checks/tests/PipelineTest.php
git commit -m "refactor(pipeline): delete the escalation and policy predicates

pipeline_should_escalate is dead once nothing escalates on a finding.
pipeline_resolve_policy returned two identical gate values plus a boolean
restatement of mode; gates.md now carries the not-auto-is-interactive
fallback it used to assert.

pipeline_can_navigate and pipeline_gate_legs are untouched — the
un-skippable-review promise never read either deleted function."
```

---

### Task 5: Consistency sweep

**Files:**
- Modify: any file the sweep finds — expected to be none.

**Interfaces:**
- Consumes: everything above.
- Produces: the finished change.

- [ ] **Step 1: Run the full retired-vocabulary sweep**

```bash
grep -rniE 'tier|CONFIRMED|PLAUSIBLE|verdict block|architecture_judgment|suggested_action|cap at 15|drop tally|gate_policy|escalat|report-only' skills/critique/ skills/pipeline/ --include='*.md' --include='*.php'
```

Expected hits, and **only** these:
- `skills/pipeline/references/gates.md` — the sentence recording that the report-only override was deleted by decision.
- `skills/pipeline/references/engine.md` — nothing. If `escalat*` appears here, Task 2 Step 5 was missed.

Anything else: fix it, then re-run.

- [ ] **Step 2: Confirm the deliberately-kept vocabulary is still there**

```bash
grep -rn 'looped-back' skills/pipeline/references/ && grep -rn '"unknown"' skills/pipeline/references/manifest.md && grep -rni 'adjudicat' skills/pipeline/references/engine.md
```

Expected: all three produce output. These survive on purpose — `looped-back` is how cycles are counted, `"unknown"` is the reconstruction marker without which the loop bound stops bounding, and `adjudicat*` is the optional independent read. A **missing** hit here is the bug.

- [ ] **Step 3: Run both suites**

```bash
./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests
./vendor/bin/pest -c skills/critique/checks/phpunit.xml --test-directory=skills/critique/checks/tests
```

Expected: `23 passed` and `11 passed (31 assertions)`. The critique number must be **identical** to the Task 1 Step 1 baseline.

- [ ] **Step 4: Read both skills end to end**

Read `skills/critique/SKILL.md`, `skills/critique/references/rubrics.md`, `skills/pipeline/references/engine.md`, `skills/pipeline/references/gates.md` and `skills/pipeline/references/manifest.md` in full, as a reader who has not seen this plan. Check for:

- stage numbers in `critique/SKILL.md` running 0, 1, 2, 3, 4, 5 with no gap;
- cross-references resolving — every `§`-reference names a section that still exists, in particular `§Failure policy`, `§`auto`` and `§reconstruction`;
- no paragraph left describing machinery that no longer exists.

Fix anything found.

- [ ] **Step 5: Commit any sweep fixes and push**

```bash
git add -A
git commit -m "docs: consistency sweep across both skills"   # skip if nothing changed
git push
```

- [ ] **Step 6: Open the draft PR**

```bash
gh pr create --draft \
  --title "Conversational /critique output, and the gate collapse" \
  --body "$(cat <<'EOF'
`/critique` writes a review instead of filling in a table, and the severity machinery that existed only to decide when an `auto` run should interrupt a human is deleted.

**What changed**

- `/critique` receives the target and the rubric and writes prose. The tier taxonomy, the CONFIRMED/PLAUSIBLE labels, the finding table, the 15-row cap and the consumer-side drop policy are gone. The evidence standard survives as a standard rather than a filter: say what breaks, or say it is taste.
- The pipeline stops asking for a verdict block. In `auto` it reads the review and acts; in `interactive` the human reads it. Nothing escalates on a finding — the only autonomous response to a bad review is a bounded loop-back.
- Bound exhaustion halts in-session and is gate-specific: no PR before `handoff`, leave the PR draft after it. The mechanical-checks layer's two remaining `escalate` rules are remapped onto that halt.
- `pipeline_should_escalate()` and `pipeline_resolve_policy()` are deleted. `gates.md` absorbs the not-`auto`-is-`interactive` fallback the latter used to assert mechanically.

**What did not change**

`pipeline_can_navigate()` / `pipeline_gate_legs()` and the un-skippable-review promise, the stage-0 deterministic checks, and the content triggers. Reviews still always run.

**Verification**

Pipeline checks 23 passed (2 tests deleted with their functions, 1 rewritten). Critique checks 11 passed / 31 assertions, unchanged — those helpers never ranked findings.

Spec: `docs/superpowers/specs/2026-07-28-critique-conversational-output-design.md`
Plan: `docs/superpowers/plans/2026-07-28-critique-conversational-output.md`

Supersedes the escalation machinery introduced in `2026-07-26-pipeline-auto-adjudicated-escalation-design.md`.
EOF
)"
```

---

## Plan Self-Review

**Spec coverage** — every spec section maps to a task:

| Spec section | Task |
|---|---|
| §1 `/critique` writes a review | 1, steps 2-8 |
| §2 What leaves `/critique` | 1 (nothing structured emitted); 2 step 2 (nothing structured requested) |
| §3 `auto` mode, loop-back destinations | 2 step 3 |
| §4 `interactive` mode | 2 step 3 (opening), 3 step 2 |
| §5 What still stops, incl. mechanical-check remap | 2 steps 4-5 |
| §6 Audit trail | 3 steps 5-9 |
| §7 Code surface, incl. the not-`auto` fallback | 3 step 2, 4 |
| §8 Documentation surface | 1, 2, 3 |
| Decisions 8 (report-only override dropped) | 3 step 2 |
| Testing strategy greps | 5 steps 1-2 |

**Known spec correction, applied here:** the spec's §6 example entry used `"gate": "review-plan"`, conflating two existing keys. The real ledger has both `gate` (`plan-approval` \| `pr-review` \| `verify-ui`) and `leg` (`review-plan` \| `review-pr`), per `manifest.md:73`. Task 3 Step 7 keeps both. Nothing else in the spec depends on the conflation.

**Placeholder scan:** no "TBD", no "handle edge cases", no "similar to Task N". Every prose replacement is given in full.

**Type consistency:** `outcome` is `continued` / `looped-back` / `halted` in Task 2 Step 4, Task 3 Step 7 and Task 5 Step 2. `disposition` is `integrated` / `recorded` / `open-question` in Task 3 Step 7 only. Loop-back destinations are stated identically in Task 2 Step 3 and referenced nowhere that could drift. The four surviving PHP function names in Task 4's Interfaces block match `pipeline.php` exactly.
