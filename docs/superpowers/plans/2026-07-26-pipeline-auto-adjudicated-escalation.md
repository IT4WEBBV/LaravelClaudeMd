# Pipeline — adjudicated escalation in `auto` mode — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** In `auto` mode the pipeline integrates reasonable review feedback itself and continues, escalating to a human only when an independent check confirms a finding that genuinely needs a human decision.

**Architecture:** Two deterministic changes in `skills/pipeline/checks/pipeline.php` — the gate policy becomes mode-dependent (`auto` → `'adjudicate'`, `interactive` → `'stop'`), and a new pure predicate `pipeline_should_escalate()` decides which findings stop a run. Everything else is procedure, and procedure in this skill lives in `skills/pipeline/references/*.md`, which **mirror the check functions by design** — the three reference docs are therefore part of the change, not documentation of it. `pipeline_can_navigate()` is deliberately untouched: the un-skippable-review promise rides on it, and a regression test pins that.

**Tech Stack:** PHP 8.2 plain functions (no framework, no classes), Pest for tests, Markdown reference docs. Spec: `docs/superpowers/specs/2026-07-26-pipeline-auto-adjudicated-escalation-design.md`.

## Global Constraints

- **Only `auto` mode changes.** `interactive` keeps `stop` at every gate. Any task that alters interactive behaviour is wrong.
- **`pipeline_can_navigate()` behaviour must not change.** The un-skippable-review promise rides on it; Task 1 adds a regression test that pins both its behaviour and its signature.
- **The existing assertion at `skills/pipeline/checks/tests/PipelineTest.php:26` pinning `auto` → `'stop'` is REPLACED, never deleted**, so the policy change is visible in the diff.
- **`references/{engine,gates,manifest}.md` stay in lock-step with `checks/*.php`.** The docs mirror the functions; a code change without its doc change is an incomplete task.
- **`gates.md`'s "content gates are non-skippable in both modes" / "never downgraded to report-only, in either mode" wording is being rewritten deliberately.** Replace it consciously and record the caveat it protected — do not preserve it.
- **The `ui` trigger is out of scope.** It selects whether the `verify-ui` *leg runs*; that stays mandatory and unchanged. Only `migration`, `auth` and `package` become annotations.
- **Verdict vocabulary, verbatim from the spec:** verdict ∈ `approve | approve-with-nits | rework`; finding ∈ `{tier: 1|2, claim, evidence, suggested_action}`; adjudication ∈ `none | refuted | confirmed | uncertain`; `architecture_judgment` is `none` or the concern.
- **Loop bound: 2 cycles.** A confirmed `rework` loops back to `design` autonomously, a failed `verify-ui` loops back to `implement`, each bounded to 2 cycles before escalating.
- **Reviewer retry bound: 1.** A malformed or missing verdict block is a machinery failure: retry once, then halt.
- **Test command** (run after every step that touches code):
  ```bash
  ./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests
  ```
  Run from the repo root, `/Users/jroelofs/GitProjects/LaravelClaudeMd/LaravelClaudeMd`. Baseline before this plan: **9 passed, 34 assertions.**
- **Commits: no `Co-Authored-By`, no AI attribution.** Same for the PR body.
- This repo has **no changelog convention** (no `.changelog/` directory, no `CHANGELOG.md`) — do not invent one.

## File Structure

| File | Responsibility | Change |
|---|---|---|
| `skills/pipeline/checks/pipeline.php` | the pure, tested guardrails: leg order, navigation, gate policy | Modify — mode-dependent `pipeline_resolve_policy()`, new `pipeline_should_escalate()` |
| `skills/pipeline/checks/tests/PipelineTest.php` | Pest coverage of those guardrails | Modify — replace the `auto` → `'stop'` assertion, add the escalation matrix, add the navigation regression |
| `skills/pipeline/references/engine.md` | the operational procedure: loop, stations, failure policy | Modify — verdict-block contract, adjudication procedure, rewritten failure policy with both bounds |
| `skills/pipeline/references/gates.md` | modes, content triggers, navigation guardrail | Modify — modes table, content triggers as annotations, the invariant rewrite |
| `skills/pipeline/references/manifest.md` | the cursor file and its fields | Modify — `gate_ledger` entry shape, reconstruction probe wording |

No files are created. `checks/triggers.php`, `checks/manifest.php` and `SKILL.md` are untouched: the trigger *detection* is unchanged (only what the engine does with three of the four booleans changes), the manifest *helpers* are unchanged (`gate_ledger` is an optional free-form field `manifest_validate` never inspects), and `SKILL.md` makes no claim about gate policy.

---

### Task 1: Mode-dependent gate policy, with the navigation promise pinned

The policy change and its regression test belong in one task: the regression exists precisely to prove the relaxed policy did not open a path around a review, so a reviewer judging one is judging the other.

**Files:**
- Modify: `skills/pipeline/checks/pipeline.php:60-67` (`pipeline_resolve_policy`)
- Test: `skills/pipeline/checks/tests/PipelineTest.php:26-34` (replace) and a new test appended after it

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `pipeline_resolve_policy(string $mode): array{auto_continue: bool, gates: array{plan-approval: string, pr-review: string}}` where the gate value is `'adjudicate'` for `'auto'` and `'stop'` for every other input. Tasks 3–5 document this value.

- [ ] **Step 1: Replace the failing assertion**

In `skills/pipeline/checks/tests/PipelineTest.php`, replace the whole existing third test (lines 26–34) with:

```php
it('resolves interactive to stop at every gate and auto to adjudicate', function () {
    $i = pipeline_resolve_policy('interactive');
    expect($i['auto_continue'])->toBeFalse()
        ->and($i['gates']['plan-approval'])->toBe('stop')
        ->and($i['gates']['pr-review'])->toBe('stop');

    // Was: auto → 'stop' at both gates. Auto now resolves its own findings and escalates only
    // what an independent check confirms — see references/gates.md §modes.
    $a = pipeline_resolve_policy('auto');
    expect($a['auto_continue'])->toBeTrue()
        ->and($a['gates']['plan-approval'])->toBe('adjudicate')
        ->and($a['gates']['pr-review'])->toBe('adjudicate');

    // An unrecognised mode falls back to the stricter policy.
    $u = pipeline_resolve_policy('nonsense');
    expect($u['auto_continue'])->toBeFalse()
        ->and($u['gates']['plan-approval'])->toBe('stop')
        ->and($u['gates']['pr-review'])->toBe('stop');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`

Expected: FAIL — `Failed asserting that two strings are equal. Expected 'adjudicate', got 'stop'.` The other 8 tests still pass.

- [ ] **Step 3: Make the gate value mode-dependent**

In `skills/pipeline/checks/pipeline.php`, replace `pipeline_resolve_policy` entirely with:

```php
/**
 * Gate policy for a mode.
 *
 * `interactive` stops at every gate — the human is present, so the gate is their turn.
 * `auto` adjudicates: the reviews still run, but their findings are proposals the engine
 * resolves itself, escalating only what an independent check confirms
 * (`pipeline_should_escalate`). An unrecognised mode gets the stricter policy.
 * See `../references/gates.md`.
 *
 * @return array{auto_continue: bool, gates: array{plan-approval: string, pr-review: string}}
 */
function pipeline_resolve_policy(string $mode): array
{
    $gate = $mode === 'auto' ? 'adjudicate' : 'stop';

    return [
        'auto_continue' => $mode === 'auto',
        'gates' => ['plan-approval' => $gate, 'pr-review' => $gate],
    ];
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`

Expected: PASS — 9 passed.

- [ ] **Step 5: Add the navigation regression test**

Append to `skills/pipeline/checks/tests/PipelineTest.php`. It reuses the `$uiOn` / `$uiOff` fixtures already defined at the top of that file (lines 3–4), so it needs the same `use` clause the other tests have.

```php
it('keeps the un-skippable-review promise out of reach of the gate policy', function () use ($uiOn, $uiOff) {
    // The promise rides on pipeline_can_navigate, which reads *which legs ran* — never a human
    // verdict, never the gate policy. That is why relaxing auto's gates to 'adjudicate' cannot
    // open a path around a review. Pin the signature so no policy argument sneaks in later.
    $params = array_map(
        fn (ReflectionParameter $p) => $p->getName(),
        (new ReflectionFunction('pipeline_can_navigate'))->getParameters()
    );
    expect($params)->toBe(['from', 'to', 'doneLegs', 'triggers']);

    // ...and the refusals themselves are unchanged by this plan.
    expect(pipeline_can_navigate('design', 'handoff', ['design'], $uiOff))->toBeFalse();
    expect(pipeline_can_navigate('design', 'implement', ['design'], $uiOff))->toBeFalse();
    expect(pipeline_can_navigate('handoff', 'review-pr', ['design', 'review-plan', 'handoff'], $uiOn))->toBeFalse();
    expect(pipeline_can_navigate('review-plan', 'implement', ['design', 'review-plan'], $uiOff))->toBeTrue();
    expect(pipeline_can_navigate('review-pr', 'design', [], $uiOn))->toBeTrue();
});
```

- [ ] **Step 6: Run the tests**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`

Expected: PASS — 10 passed. This one is a characterization test: it passes immediately *by design*, because the behaviour it describes is what must not change. If it fails, `pipeline_can_navigate` was altered and the change is wrong.

- [ ] **Step 7: Commit**

```bash
git add skills/pipeline/checks/pipeline.php skills/pipeline/checks/tests/PipelineTest.php
git commit -m "feat(pipeline): auto mode resolves gates to adjudicate, interactive keeps stop

Pins pipeline_can_navigate's behaviour and signature so the un-skippable-review
promise is provably independent of the relaxed gate policy."
```

---

### Task 2: `pipeline_should_escalate()` — which findings stop an auto run

**Files:**
- Modify: `skills/pipeline/checks/pipeline.php` (append after `pipeline_resolve_policy`)
- Test: `skills/pipeline/checks/tests/PipelineTest.php` (append)

**Interfaces:**
- Consumes: nothing from Task 1 at runtime; the two functions are siblings.
- Produces: `pipeline_should_escalate(array $finding, string $adjudication): bool`. `$finding` is a `findings[]` entry `{tier: 1|2, claim, evidence, suggested_action}`, or the synthesised `{kind: 'architecture', claim: …}` entry the engine builds from a non-`none` `architecture_judgment`. `$adjudication` is `'none' | 'refuted' | 'confirmed' | 'uncertain'` — `'none'` for every finding that never went to adjudication, which makes the helper total over the triage. Tasks 3 and 5 document it.

- [ ] **Step 1: Write the failing test**

Append to `skills/pipeline/checks/tests/PipelineTest.php`:

```php
it('escalates only a finding that is blocking *and* confirmed', function () {
    $tier1 = ['tier' => 1, 'claim' => 'the plan drops the closing-issue link'];
    $tier2 = ['tier' => 2, 'claim' => 'wording nit in step 4'];
    $arch  = ['kind' => 'architecture', 'claim' => 'this belongs in it4web/tallui, not the project'];

    // Tier-2s never reach adjudication; 'none' is their disposition and they continue.
    expect(pipeline_should_escalate($tier2, 'none'))->toBeFalse();

    // Tier-1 goes to adjudication, and only a confirmation stops the run.
    expect(pipeline_should_escalate($tier1, 'refuted'))->toBeFalse();
    expect(pipeline_should_escalate($tier1, 'uncertain'))->toBeFalse();
    expect(pipeline_should_escalate($tier1, 'confirmed'))->toBeTrue();

    // An architecture judgment escalates on the same terms, carrying no tier of its own.
    expect(pipeline_should_escalate($arch, 'refuted'))->toBeFalse();
    expect(pipeline_should_escalate($arch, 'uncertain'))->toBeFalse();
    expect(pipeline_should_escalate($arch, 'confirmed'))->toBeTrue();

    // Total over the triage: a Tier-2 that somehow arrives confirmed is still not blocking,
    // and a shapeless finding defaults to advisory rather than to an interrupt.
    expect(pipeline_should_escalate($tier2, 'confirmed'))->toBeFalse();
    expect(pipeline_should_escalate(['claim' => 'no tier, no kind'], 'confirmed'))->toBeFalse();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`

Expected: FAIL with `Error: Call to undefined function pipeline_should_escalate()`.

- [ ] **Step 3: Write the implementation**

Append to `skills/pipeline/checks/pipeline.php`:

```php
/**
 * Does this finding stop an `auto` run?
 *
 * Total over every finding the triage in `../references/engine.md` produces: the findings that
 * never went to adjudication — every Tier-2 — arrive with $adjudication === 'none'. Only a
 * *confirmed* blocking finding escalates; `refuted` and `uncertain` both continue, because
 * ambiguity buys a line in the PR body, not an interrupt. A malformed reviewer block is a
 * machinery failure handled by the engine's retry-then-halt rule, never routed through here.
 *
 * @param  array{tier?: int|string, kind?: string}  $finding  a `findings[]` entry, or the
 *         synthesised `['kind' => 'architecture', …]` entry for a non-`none` architecture_judgment
 * @param  string  $adjudication  'none' | 'refuted' | 'confirmed' | 'uncertain'
 */
function pipeline_should_escalate(array $finding, string $adjudication): bool
{
    if ($adjudication !== 'confirmed') {
        return false;
    }

    return ($finding['kind'] ?? null) === 'architecture'
        || (int) ($finding['tier'] ?? 2) === 1;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`

Expected: PASS — 11 passed.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/checks/pipeline.php skills/pipeline/checks/tests/PipelineTest.php
git commit -m "feat(pipeline): add pipeline_should_escalate, total over the finding triage"
```

---

### Task 3: `engine.md` — the verdict contract, the adjudication procedure, the rewritten failure policy

`references/engine.md` is the operational procedure the engine follows; the two functions from Tasks 1–2 are meaningless without it. Three edits, one commit.

**Files:**
- Modify: `skills/pipeline/references/engine.md:75,79` (two Stations rows), `:81-97` (the whole Failure policy section), plus a new section inserted between them.

**Interfaces:**
- Consumes: `pipeline_resolve_policy` (Task 1) and `pipeline_should_escalate` (Task 2) — reference both by exact name.
- Produces: the vocabulary Tasks 4 and 5 cite — the verdict block fields, the triage table, the adjudication dispositions, and the two bounds.

- [ ] **Step 1: Point the two review rows at the verdict block**

In the Stations table, replace the `review-plan` row (line 75) and the `review-pr` row (line 79) with:

```markdown
| **review-plan** | `/critique plan` | reviewer scores the plan; you verdict | read-only reviewer subagent returning the **verdict block** (below) | feeds the plan-approval gate **and** the project-vs-package judgment |
| **review-pr** | `/critique pr` | reviewer scores the whole change; you verdict | read-only reviewer subagent returning the **verdict block** (below) | feeds the PR-review gate |
```

- [ ] **Step 2: Insert the contract and the procedure**

Insert these two sections immediately after the Stations table and immediately before the `## Failure policy` heading:

```markdown
## The verdict block — a review leg's return contract

`review-plan` and `review-pr` return a structured block. This is a **contract**, not a per-run
prompt convention: every decision below reads it.

| Field | Value |
|---|---|
| `verdict` | `approve` \| `approve-with-nits` \| `rework` |
| `findings[]` | each `{tier: 1\|2, claim, evidence, suggested_action}` |
| `architecture_judgment` | `none`, or the project-vs-package (or comparable) concern |

**A malformed or missing block is a machinery failure, not a finding.** Retry the reviewer
**once**; if it is still malformed, **halt**. Fail-closed is retained exactly where it belongs —
broken tooling — without being spent on findings.

## Gate policy `adjudicate` — reviews are proposals, not verdicts

`pipeline_resolve_policy('auto')` resolves both gates to `adjudicate`; `interactive` keeps `stop`
and none of this section runs (`gates.md`). Under `adjudicate` the reviews still run, unchanged —
what changes is who resolves their findings. **The engine continues by default and escalates only
what an independent check confirms.** The risk position behind that: the pipeline never merges, so
every output is a PR read before merge and the worst case is a discarded branch, while a needless
interrupt costs the one thing `auto` exists to protect.

**Triage** — route each finding exactly once:

| Finding | Route |
|---|---|
| Tier-2 | integrate directly; no adjudication (record `adjudication: none`) |
| Tier-1, an overall `rework`, or a non-`none` `architecture_judgment` | adjudicate |

**Adjudicate** — dispatch a **fresh subagent that never saw the design leg**, give it the finding
plus the code, and ask it to **refute** the claim, citing `file:line`. Independence is the point:
at `review-plan` the engine would otherwise be adjudicating a critique of a plan it just wrote —
the self-review bias `/critique` exists as a separate agent to avoid. Synthesise a non-`none`
`architecture_judgment` into a finding of its own (`{kind: 'architecture', claim: …}`) so it
travels the same path.

| Adjudication | Disposition |
|---|---|
| **refuted** (with cited evidence) | downgrade to advisory, log, continue |
| **confirmed** | **escalate** — stop with a packaged parcel |
| **uncertain** | continue; carry the finding **verbatim** into the PR body as an open question |

`pipeline_should_escalate($finding, $adjudication)` in `../checks/pipeline.php` is that table made
mechanical, and is total over every finding the triage produces. `uncertain` continuing is a
deliberate choice of the owner's risk position over the reviewer's caution: ambiguity does not buy
an interrupt, it buys a line in the PR.

**Integrate** — apply actionable Tier-2s and refuted Tier-1s to the spec/plan and commit.
Non-actionable ones (already-mitigated observations, notes for posterity) are recorded without an
edit.

**Log** — every finding, its adjudication, the cited evidence and the disposition go to the
manifest's `gate_ledger` (`manifest.md`) and are projected onto the PR. *Overruling a reviewer is
fine; overruling one invisibly is what turns a gate into decoration.*
```

- [ ] **Step 3: Rewrite the failure policy**

Replace the entire `## Failure policy — halt, or demote-and-package` section (lines 81–97, from that heading through the paragraph ending "…and re-arming decides nothing.") with:

```markdown
## Failure policy — what still stops

Under `adjudicate` these are the only stops; everything else continues, with a record.

- **Hard failure** (a station errors: tests won't go green, a tool dies, the stack won't start,
  `work-on` hits a blocker, or a verdict block is still malformed after its one retry) → **halt.**
  Write the failure to the manifest; a human resumes. **No silent retry** beyond the single
  documented reviewer retry — a retry hides the failure and the machinery may be in an unknown
  state.
- **A confirmed Tier-1 or architecture concern** (`pipeline_should_escalate` → `true`) → **re-arm
  the next gate as a human stop** (a one-line `gate_policy` edit) and continue to a **packaged
  parcel** (branch pushed, PR open, review posted) so the human reads-and-verdicts.
- **Bound exhaustion.** A confirmed `rework` on the *plan* loops back to `design` **autonomously**
  — it never builds an implementation on a plan judged unshippable — and a `verify-ui` failure
  loops back to `implement`. Each loop is bounded to **2 cycles**; on what would be the third,
  **escalate** instead. The bound is what keeps an autonomous loop from churning indefinitely
  without ever surfacing. Count the cycles from that gate's `gate_ledger` entries
  (`manifest.md`), never from an in-memory counter.
- **Advisory finding** (a Tier-2, or a refuted Tier-1) → **log to the manifest, continue.**
- **Playwright genuinely unavailable** → **halt.** No visual claim without proof.

In `interactive` mode both gates are `stop`, so every finding reaches the present human and none
of this triage runs.

The scary content facts — a migration, an authorization change, a shared package — **no longer
stop the chain**: they are facts, not findings, so they become loud mandatory annotations on the
PR and in the ledger (`gates.md` §content triggers). `verify-ui` is untouched by that and stays
mandatory whenever the `ui` trigger fires.
```

- [ ] **Step 4: Verify the docs did not break the checks**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`

Expected: PASS — 11 passed (unchanged; this step catches an accidental edit to a `.php` file).

Then read back the whole file and confirm three things by eye: the old "demote-and-package" heading is gone, no sentence still claims content gates stop the chain, and every function name mentioned (`pipeline_resolve_policy`, `pipeline_should_escalate`) matches the code.

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/references/engine.md
git commit -m "docs(pipeline): verdict-block contract, adjudication procedure, revised failure policy"
```

---

### Task 4: `gates.md` — modes table, content triggers as annotations, the invariant rewrite

**Files:**
- Modify: `skills/pipeline/references/gates.md:1-5` (intro), `:10-24` (modes), `:26-42` (content gates), `:44-50` (verify-ui, one added sentence), `:83-84` (the pure-functions sentence)

**Interfaces:**
- Consumes: `pipeline_resolve_policy` (Task 1), `pipeline_should_escalate` (Task 2), and the adjudication vocabulary from Task 3.
- Produces: nothing later tasks depend on.

- [ ] **Step 1: Rewrite the intro paragraph**

Replace lines 3–5 (the paragraph beginning "Two kinds of thing stop the chain") with:

```markdown
Two kinds of thing shape the chain: **mode-driven station gates** (a human turn, or an
adjudication) and **content triggers** (facts about the diff). The forward-navigation guardrail is
a third, separate mechanism — it makes the review *legs* un-skippable *by construction*, not by
memory, and it reads neither the mode nor the gate policy.
```

- [ ] **Step 2: Rewrite the modes section**

Replace the whole `## Modes — one choice, resolved to a policy table` section (lines 10–24, through the end of the report-only paragraph) with:

```markdown
## Modes — one choice, resolved to a policy table

`pipeline_resolve_policy($mode)` returns `['auto_continue' => bool, 'gates' => ['plan-approval'
=> …, 'pr-review' => …]]`. The mode sets **both** `auto_continue` and the two station-boundary
gates; an unrecognised mode falls back to the stricter `interactive` policy.

| Mode | `auto_continue` | Gates | Behaviour |
|---|---|---|---|
| **`interactive`** *(default)* | `false` | `stop` | you are present; run one leg, show you, wait. Every finding is yours to verdict. Advance by saying so (see navigation). |
| **`auto`** | `true` | `adjudicate` | run the autonomous legs unattended. The reviews still run, but the engine resolves their findings itself and escalates only what an independent check confirms (`engine.md` §gate policy `adjudicate`). Hard failures and bound exhaustion still stop. |

Both gates always resolve to the **same** value — there is no mode in which one review is
adjudicated and the other parked.

**Report-only override.** "Run past the plan gate straight to a PR" is not a third mode — it is
`auto` with the `plan-approval` gate flipped to `report` in the manifest's `gate_policy`: record
the findings, adjudicate nothing, escalate nothing. That is a **one-line, visible edit to stored
data**, for well-specified work; `pipeline_resolve_policy` itself never returns `report`. What no
mode and no override can do is stop a review *leg* from running — that is the navigation
guardrail below.
```

- [ ] **Step 3: Rewrite the content-gates section**

Replace the whole `## Content-triggered gates — non-skippable in both modes` section (lines 26–42, through the parenthetical about migration detection) with:

```markdown
## Content triggers — three annotate, one gates a leg

`pipeline_triggers($diff, $repoPackageName)` returns four booleans —
`['package' => bool, 'migration' => bool, 'auth' => bool, 'ui' => bool]`. Three annotate; `ui`
gates the `verify-ui` leg (next section). Detection is unchanged; what the engine *does* with the
first three is what this section revises.

| Trigger | Detection (`pipeline_triggers`) | Effect |
|---|---|---|
| touches an `it4web/*` package | `$repoPackageName` starts `it4web/` (the change is *in* a package repo), **or** an added `composer.json` line names an `it4web/*` constraint | annotation |
| writes a DB migration | an added/changed file path matches `database/migrations/…\.php` | annotation |
| touches authorization | an added line matches `authorize(` / `Gate::` / `Policy` / `can:` / `->can(` / `middleware('can:` | annotation |
| the project-vs-package call | **none mechanical** — a `/critique plan` judgment, returned as the verdict block's `architecture_judgment` | adjudicated like a Tier-1 (`engine.md`) |

**The three annotating triggers no longer stop the chain.** They are **facts** — a path matched —
not findings to be refuted, so "adjudicating" them is incoherent; the only real question is
whether the fact warrants a human, and under the governing principle (the pipeline never merges;
a bad PR is trashable) it does not. Each fires a **mandatory, prominent annotation** — "this PR
contains a migration", "this PR touches authorization" — in the PR body and in the manifest's
`gate_ledger`, and the run continues.

> **This deliberately replaces the earlier invariant** that content gates are "non-skippable in
> both modes" and "never downgraded to report-only, in either mode." That text is gone by
> decision, not by oversight. The caveat it protected is real and is recorded rather than
> eliminated: **authorization and migration defects are the ones most easily missed in a quick PR
> skim, precisely because they look small.** The annotation must lead the PR body, not sit in a
> footnote.

(Migration detection is by *path*, not by data-write — a `DB::table()->update()` in an Action does
not trip it; a file under `database/migrations/` does.)
```

- [ ] **Step 4: Fence off `verify-ui` from the annotation change**

Append this sentence to the end of the `## verify-ui — non-skippable when the UI is touched` section, after "See `engine.md` for the leg itself.":

```markdown
**The `ui` trigger is untouched by the annotation change above.** It selects whether a *leg runs*,
not whether a human is asked, so it stays mandatory in both modes: a UI-touching change still
cannot reach `review-pr` without `browser-verification` proof.
```

- [ ] **Step 5: List the new helper among the pure functions**

Replace the sentence at lines 83–84 beginning "Navigation and policy are pure functions" with:

```markdown
Navigation, policy and escalation are pure functions — call `pipeline_can_navigate` /
`pipeline_next_leg` / `pipeline_resolve_policy` / `pipeline_should_escalate` directly (they take
no I/O). The manifest's `gate_ledger` records which gates have run; `pipeline_can_navigate`'s
`$doneLegs` is derived from it.
```

- [ ] **Step 6: Verify**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`

Expected: PASS — 11 passed.

Then grep the file for stale wording and expect **no matches**:

```bash
grep -n "non-skippable in both modes\|never downgraded to report-only\|park at\|parks at" skills/pipeline/references/gates.md
```

Note the `verify-ui` heading legitimately still reads "non-skippable when the UI is touched" — that is a different phrase and must survive.

- [ ] **Step 7: Commit**

```bash
git add skills/pipeline/references/gates.md
git commit -m "docs(pipeline): auto adjudicates its gates; content triggers become annotations

Rewrites the documented invariant that content gates are non-skippable in both
modes, keeping the skim-risk caveat it protected."
```

---

### Task 5: `manifest.md` — the `gate_ledger` entry shape

**Files:**
- Modify: `skills/pipeline/references/manifest.md:24` (the `gate_ledger` field row), `:58` and `:63` (two reconstruction probes), plus a new section appended after `## Two rules that keep the file honest`

**Interfaces:**
- Consumes: the adjudication vocabulary and the loop bound from Task 3; `pipeline_should_escalate` from Task 2.
- Produces: nothing later tasks depend on. This is the last task.

- [ ] **Step 1: Widen the `gate_ledger` field description**

Replace line 24:

```markdown
| `gate_ledger` | optional | the audit trail — each gate's verdict, findings, adjudications and dispositions, plus the content-trigger annotations (shape below) |
```

- [ ] **Step 2: Add the entry-shape section**

Insert this section immediately after the `## Two rules that keep the file honest` section and before `## Invariant check`:

````markdown
## `gate_ledger` — the audit trail that keeps a gate from being decoration

Under the `adjudicate` policy the engine overrules reviewers routinely (`engine.md`). That is
fine; doing it *invisibly* is not. So each pass through a gate appends one entry, and the entry is
projected onto the PR.

```json
{
  "gate": "plan-approval",
  "leg": "review-plan",
  "cycle": 1,
  "at": "2026-07-26T11:04:22Z",
  "policy": "adjudicate",
  "verdict": "approve-with-nits",
  "annotations": ["migration", "auth"],
  "findings": [
    {
      "kind": "finding",
      "tier": 2,
      "claim": "step 4 drops the closing-issue link",
      "evidence": "docs/superpowers/plans/2026-07-26-x.md:88",
      "suggested_action": "restore `Closes #1926`",
      "adjudication": "none",
      "adjudication_evidence": null,
      "disposition": "integrated"
    }
  ],
  "outcome": "continued"
}
```

| Key | Values |
|---|---|
| `gate` | `plan-approval` \| `pr-review` |
| `cycle` | 1-based — which pass through this gate produced the entry |
| `policy` | the value resolved at the time — `stop` \| `adjudicate` \| `report` |
| `verdict` | the reviewer's own — `approve` \| `approve-with-nits` \| `rework` |
| `annotations` | the content triggers that fired (`package`, `migration`, `auth`) — facts, not findings |
| `findings[].kind` | `finding`, or `architecture` for a synthesised `architecture_judgment` |
| `findings[].adjudication` | `none` (never adjudicated — every Tier-2) \| `refuted` \| `confirmed` \| `uncertain` |
| `findings[].disposition` | `integrated` (edited and committed) \| `recorded` (logged, no edit) \| `open-question` (carried verbatim into the PR body) \| `escalated` |
| `outcome` | `continued` \| `escalated` \| `looped-back` |

`disposition: escalated` is exactly the set for which
`pipeline_should_escalate($finding, $adjudication)` returns `true`.

**The loop bound is read from here, never from memory.** A confirmed `rework` may loop back twice
before it must escalate (`engine.md` §failure policy); the engine counts this gate's existing
entries to know which cycle it is on. That is the one place the ledger is *read* rather than
appended to, and it does not violate the recomputable-fields rule above: the cycle count is a fact
about history, not a cached derivation of current state.

An entry with `"policy": "stop"` is the `interactive` shape — `annotations` and `verdict` still
recorded, every `findings[].adjudication` is `none`, and the human's decision is the `outcome`.
````

- [ ] **Step 3: Align the two reconstruction probes**

An adjudicated continue is not literally an "approval", so the probe wording has to widen or a
resumed `auto` run will re-review forever. Replace the `planApproved` row (line 58) and the
`prReviewed` row (line 63) with:

```markdown
| `planApproved` | the `gate_ledger` holds a `plan-approval` entry with `outcome: continued` — a human approval, or an adjudicated continue — else re-run `review-plan` (a re-review is cheap and stateless) |
| `prReviewed` | the `gate_ledger` holds a `pr-review` entry with `outcome: continued` |
```

- [ ] **Step 4: Verify**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`

Expected: PASS — 11 passed.

Then confirm the required-keys table (lines 18–29) is untouched — `manifest_validate` still checks exactly `branch`, `worktree`, `mode`, `cursor`, and `gate_ledger` stays optional:

```bash
grep -n "required" skills/pipeline/references/manifest.md
```

- [ ] **Step 5: Commit**

```bash
git add skills/pipeline/references/manifest.md
git commit -m "docs(pipeline): gate_ledger entry shape for findings, adjudications and annotations"
```

---

### Task 6: Cross-document lock-step check and PR

**Files:** none modified unless the check finds drift.

- [ ] **Step 1: Read the three reference docs against the two functions**

Read `skills/pipeline/checks/pipeline.php` and then each of `references/engine.md`,
`references/gates.md`, `references/manifest.md` end to end. Confirm:

- every gate value a doc names is one `pipeline_resolve_policy` can produce (`stop`, `adjudicate`) or is explicitly flagged as stored-data-only (`report`);
- every adjudication value a doc names is one `pipeline_should_escalate` accepts (`none`, `refuted`, `confirmed`, `uncertain`);
- no doc still says a content trigger stops the chain, and no doc says `verify-ui` is optional;
- `SKILL.md` needs no edit — verify by grepping it for gate-policy claims:
  ```bash
  grep -n "stop\|park\|gate" skills/pipeline/SKILL.md
  ```
  Its only gate claim is the navigation guardrail, which is unchanged. If it says anything else, fix it here.

- [ ] **Step 2: Run the full suite one final time**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`

Expected: PASS — 11 passed. Record the actual count and assertion total; do not claim a number you have not read.

- [ ] **Step 3: Push and open the PR**

```bash
git push -u origin feature/pipeline-auto-adjudicated-escalation
gh pr create --title "Pipeline: adjudicated escalation in auto mode" --body "$(cat <<'EOF'
Implements `docs/superpowers/specs/2026-07-26-pipeline-auto-adjudicated-escalation-design.md`.

In `auto` mode the pipeline now integrates reasonable review feedback itself and continues,
escalating only when an independent check confirms a finding. `interactive` is unchanged.

## What changed

- `pipeline_resolve_policy()` — gates are mode-dependent: `auto` → `adjudicate`, everything else → `stop`.
- `pipeline_should_escalate($finding, $adjudication)` — new pure predicate; escalates only a Tier-1 or architecture finding that adjudication **confirmed**. `refuted` and `uncertain` continue.
- `references/engine.md` — the verdict block becomes a leg contract (malformed → one retry → halt); the adjudication procedure; a rewritten failure policy with the 2-cycle loop bound.
- `references/gates.md` — modes table; the `migration` / `auth` / `package` triggers become mandatory PR annotations instead of stops.
- `references/manifest.md` — the `gate_ledger` entry shape carrying every finding, its adjudication, the cited evidence and the disposition.

## Deliberate invariant rewrite

`gates.md` previously stated content gates are "non-skippable in both modes" and "never downgraded
to report-only, in either mode." That text is replaced by decision, not oversight — see spec §7.
The caveat it protected is kept: authorization and migration defects are the ones most easily
missed in a quick PR skim, so the annotation must lead the PR body.

## What did not change

`pipeline_can_navigate()` — the un-skippable-review promise rides on it, and it reads which legs
ran, never a verdict or a policy. A regression test pins both its behaviour and its signature.
`verify-ui` stays mandatory whenever the UI trigger fires.

Tests: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`
EOF
)"
```

If any task stopped on a blocker, add `--draft` and say why in the body instead.

---

## Self-Review

**Spec coverage** — every section of the spec maps to a task:

| Spec section | Task |
|---|---|
| §1 verdict contract, malformed → retry once → halt | 3 |
| §2 triage table | 3 |
| §3 adjudication, independence, three dispositions | 3 (procedure), 2 (the predicate) |
| §4 integration | 3 |
| §5 what still stops, incl. both bounds | 3 |
| §6 audit trail | 5 (shape), 3 (the instruction to write it) |
| §7 content triggers become annotations; `ui` untouched; invariant rewrite | 4 |
| §8 code surface, all six rows | 1, 2, 3, 4, 5 |
| Testing strategy, all four bullets | 1 (policy + navigation regression + replaced assertion), 2 (escalation matrix) |
| Out of scope — interactive, `/critique` internals, merging, teardown | untouched by every task |

**Placeholders:** none — every code and Markdown step carries its literal replacement text.

**Type consistency:** `pipeline_should_escalate(array $finding, string $adjudication): bool` is named identically in Task 2's test, Task 2's implementation, Task 3's engine.md prose, Task 4's gates.md list and Task 5's manifest.md note. Adjudication values are the same four strings everywhere; gate values are the same two (plus stored-data-only `report`); dispositions are the same four.

**Known judgment call, flagged for the reviewer:** the spec's test matrix lists "architecture confirmed → true" alongside "Tier-1 confirmed → true". These would be the same case if an architecture judgment were simply emitted as a Tier-1 finding. Task 2 instead recognises `kind: 'architecture'` explicitly, so an architecture finding escalates **without needing a tier** — which is what makes the spec's separate matrix row meaningful and lets `tier` default safely to advisory. Task 3 documents the synthesis step that produces that entry.
