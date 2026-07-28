# `/critique` conversational output, and the gate collapse — design

- **Date**: 2026-07-28
- **Skills**: `critique`, `pipeline`
- **Supersedes**: `2026-07-26-pipeline-auto-adjudicated-escalation-design.md` (§1 Verdict contract,
  §2 Triage, §3 Adjudication, §5 What still stops, §6 Audit trail, §8 Code surface)

## Summary

`/critique` stops shaping its output. It receives the target and the rubric and writes a review —
prose, whatever shape the review wants to be. The tier taxonomy, the CONFIRMED/PLAUSIBLE labels,
the verdict enum, the finding table, the drop policy and the whole "verdict block" return contract
are deleted, from both skills.

The pipeline stops asking for a structure. In `auto` it reads the review and acts; in `interactive`
it shows the review and the human acts. Nothing escalates on a finding: the only autonomous
response to a bad review is to **loop back and redo**, bounded, and the only stops left are
machinery failures and bound exhaustion.

## The problem

Two problems with one cause.

**The output is worse than what it replaced.** The skill was built for its *modes* and its
*pipeline integration*. Asking an independent model to review something already produced good
reviews; layering a mandated table (claim ≤60 chars · tier · verdict · category · location ·
failure scenario), a scored-candidate protocol, a 15-row cap and a drop tally on top made the
output worse without making the review better. The owner's stated position: *"I used to say have
Fable review X and I was fine with what I got back. The entire purpose of the skill was just to
have the modes and have it work inside the pipeline."*

**The gate system is overengineered.** `engine.md`'s `adjudicate` triage, the Tier-2/Tier-1 routing
table, the three-value adjudication outcome, the escalation-and-packaging path, the gate-flipping
trick, and `pipeline_should_escalate()` all exist to answer one question: **when should an `auto`
run interrupt the human?**

Those are the same problem. Tiers exist to rank findings for the interrupt decision; the table
exists to carry the tiers. Answer the interrupt question and both collapse.

### The answer was already arriving

`2026-07-26`'s decisions log records three owner reversals, all in one direction — architecture
calls became adjudicatable rather than always-stopping (4), `uncertain` became continue rather than
escalate (5), content triggers became annotations rather than stops (6). This spec is the end of
that arc, not a new direction: **`auto` never interrupts.**

> *"In auto mode I mostly care about getting the best result without me getting involved. If I
> don't like the PR in the end, that is on me."*

### What is *not* the problem

Unchanged, and load-bearing:

- **`pipeline_can_navigate()` / `pipeline_gate_legs()`** — the un-skippable-review promise. It tests
  whether the gate *leg ran*, never a verdict (`2026-07-26` §What is not the problem). Reviews still
  always run; there is still no path to a non-draft PR that skipped `review-plan` or `review-pr`.
- **Stage 0's deterministic pre-pass** (`critique/checks/*.php`) — tested, tier-free, and target
  assembly is genuine value. Untouched.
- **Content triggers** (`pipeline/checks/triggers.php`) — mechanical facts, already annotations.
- **The loop-back bound** — without it an autonomous loop churns forever.

## Principle

**A review is prose written for a reader. Structure is something a consumer imposes, and this
consumer no longer needs any.** The engine reads the review the way a person would, acts on its own
judgment, and records what it did. Reviews inform; they do not adjudicate, score, or gate.

## Design

### 1. `/critique` writes a review

Stage 2's reviewer instruction drops "return every candidate scored" and becomes: here is the
target, here is the rubric, review it.

- **Stage 3 (filter and shape) — deleted.** The reviewer decides what is worth saying.
- **Stage 4 (report)** becomes guidance, not contract: cite `file:line` where there is one, say what
  breaks and when, end with a plain bottom line. In its own words.
- **Stage 5 (triage)** shrinks to one sentence plus the `superpowers:receiving-code-review` pointer;
  the four-way disposition vocabulary goes.
- **Stage 6 (rubric feedback)** reframes off the drop tally, which no longer exists, onto "the same
  issue keeps recurring." The *never automatic, needs approval* guard stays verbatim.
- **`--verify`** stays, reframed: a skeptic re-checks the review's claims against the code and says
  which hold. No CONFIRMED/PLAUSIBLE labels.
- **Re-review** unchanged — pasting a prose report works.
- **Kept:** the mode announcement and the assembled-target statement (*"N files, M uncommitted"*).
  That is operational transparency, not output formatting.

**Hazard classes stay as required coverage.** All four compatibility classes must still be
*considered*; what goes is the requirement to *report a verdict per class*. The reviewer says
"checked the in-flight jobs, nothing" where that is informative, and stays quiet where it is not.

### 2. What leaves `/critique`

Nothing structured. There is no verdict block, no `architecture_judgment` field, no `findings[]`.

The project-vs-package call still gets made — `plan`'s rubric already asks *"Is the
project-vs-package call made explicitly?"* — it just arrives as a paragraph rather than a field.

### 3. `auto` mode

Run `/critique`, read the review, then:

- **Act.** Fix what is worth fixing and commit it; record what is not worth fixing without an edit.
- **Loop back** when the review says the work is fundamentally wrong — bounded to **2 cycles** per
  gate, counted from `outcome: looped-back` entries in the ledger, never an in-memory counter. A
  reconstructed manifest carries an unknown count and permits **no** loop-back, recorded as
  `"cycle": "unknown"` on the entry (§6) — the rule needs a durable marker, because "the engine
  remembers it reconstructed" is exactly the in-memory state this counting rule forbids.

  **Loop-back destinations**, restated here because §8 rewrites the section that currently holds
  them: `review-plan` → `design`; `verify-ui` → `implement`; `review-pr` → `implement`. A
  `verify-ui` failure is a failed browser check rather than a review, and loops back on the same
  bound.
- **Never interrupt on a finding.** Unresolved concerns go loud into the PR body. The only stops
  are in §5, and neither is finding-driven.
- **Log** the review, the actions and the outcome to `gate_ledger`, projected onto the PR.
  *Overruling a reviewer is fine; overruling one invisibly is what turns a gate into decoration.*

**Independent adjudication survives as a move, not a rule.** `engine.md`'s point stands: at
`review-plan` the engine would otherwise be judging a critique of a plan it just wrote. So *"if you
doubt a finding and acting on it is expensive, get an independent read from a fresh subagent"*
remains available judgment. It is no longer a mandatory routing step with a three-value outcome.

The old "a confirmed Tier-1 at `review-plan` blocks `implement`" protection has a successor, but a
weaker one, and the difference is the point: the old block was **mechanical** — it fired on a
confirmed Tier-1 regardless of the engine's opinion. The new loop-back is the engine's own
judgment, exercised at `review-plan` by the author of the plan under review. See §Risks.

### 4. `interactive` mode

Run `/critique`, show the review, discuss it. No block, no hidden structure, no ledger vocabulary
the human never sees. The ledger records the review and what was decided.

### 5. What still stops

- **Machinery failure** — a station errors, tests will not go green, a tool dies, Playwright is
  genuinely unavailable → **halt**, write the failure to the manifest. No silent retry.
- **Bound exhaustion** — on what would be the third cycle at a gate → **halt in-session**. The work
  stays in the worktree and the human reviews it live (owner's decision; strictly simpler than the
  escalate-and-package path it replaces). What "halt" means depends on where the gate sits relative
  to `handoff`:
  - at **`review-plan`** (before `handoff`) → **no branch push, no draft PR.** Twice-rejected work
    is not worth a PR round-trip.
  - at **`verify-ui`** and **`review-pr`** (after `handoff`) → the draft PR already exists, so there
    is nothing to not-push. Leave it **draft**, write the reason to the PR body, stop.
- **Mechanical-check exhaustion** — the `## Checks` layer (`engine.md` §Mechanical checks) currently
  says a check failure "escalate[s] on the third" attempt and that more than two `@phpstan-ignore`
  suppressions in one run escalates. Escalation no longer exists, so **both map onto the
  bound-exhaustion halt above.** This is the one place where a spec that is *not* superseded here —
  `2026-07-27-pipeline-mechanical-checks-design.md`, which explicitly assumed the escalation rules
  would stay — collides with this one, and it must be resolved rather than left to the grep pass.

That is the whole list. No finding stops a run.

### 6. Audit trail

`gate_ledger` entry shape:

```json
{
  "gate": "review-plan",
  "cycle": 1,
  "at": "2026-07-28T11:04:00Z",
  "review": "<the reviewer's text>",
  "annotations": ["package", "migration"],
  "actions": [
    { "claim": "…", "disposition": "integrated | recorded | open-question", "note": "…" }
  ],
  "outcome": "continued | looped-back | halted"
}
```

- **`gate`, `cycle` and `at` are retained deliberately.** `cycle` is load-bearing: it is where
  `"unknown"` lives after a reconstruction, and without it §3's no-loop-back-after-reconstruction
  rule has no durable home and silently degrades into granting two fresh cycles every time a
  manifest is lost. `at` is the audit trail's only ordering.
- `policy` per entry is dropped along with `gate_policy`; `mode` is already top-level.
- `verdict`, `verdict_adjudication`, `findings[].tier`, `findings[].kind`,
  `findings[].adjudication` and `outcome: escalated` are gone.
- `outcome: looped-back` keeps its exact meaning; the cycle count reads it unchanged.
- **`review` stores content, reversing a stated invariant.** `manifest.md`'s honesty rules say
  *"Pointers, never content. Store the spec path, not the spec; the PR number, not the review."*
  The review is the one genuinely unreconstructable artifact — `/critique` stores nothing, and
  before `handoff` there is no PR body to recover it from — so the rule gets an **explicit,
  named exception** in `manifest.md`, not a silent violation.

### 7. Code surface

`pipeline/checks/pipeline.php`:

| Function | Fate |
|---|---|
| `pipeline_legs`, `pipeline_gate_legs`, `pipeline_next_leg`, `pipeline_can_navigate` | **unchanged** |
| `pipeline_should_escalate` | **deleted** — dead once nothing escalates on a finding |
| `pipeline_resolve_policy` | **deleted** — a two-row table of identical values plus `auto_continue`, which is `$mode === 'auto'`. Mode semantics move to `gates.md` prose |

`critique/checks/*.php` — **no change.** Verified tier-free: they emit `{exact, heuristic}` with
category slugs and never rank.

**One behaviour must survive the deletion of `pipeline_resolve_policy`.** It maps an *unrecognised*
mode to the stricter policy, and `manifest_validate` checks key presence rather than value — so a
manifest with a mangled `mode` currently fails safe. The absorbed prose in `gates.md` must state it
outright: **anything that is not `auto` behaves as `interactive`.** Otherwise a corrupted `mode`
lands wherever the prose happens to leave it, and the safe default is lost with its test.

### 8. Documentation surface

| File | Change |
|---|---|
| `critique/SKILL.md` | stages 2–6, `--verify`; the "what this skill is for" paragraph gains an explicit *does not shape the review's prose* |
| `critique/references/rubrics.md` | delete **Tiers** and **Verdicts** sections; drop the per-class verdict requirement at **`:10-11`** and **`:41-42`** (*not* in §Verdicts — that section is CONFIRMED/PLAUSIBLE) while keeping the hazard classes as coverage; reframe the failure-scenario rule from filter to standard (*if you cannot say what breaks, it is taste — say so plainly rather than dressing it as a defect*); trim the reviewer-slug line. **Keep** both evidence standards: `missing` needs ≥2 cited real paths, `alternatives` needs the condition-under-which-it-wins |
| `pipeline/references/engine.md` | delete §The verdict block; rewrite §Gate policy `adjudicate` and §Failure policy (restoring the loop-back destinations per §3); **and §Mechanical checks** — its two `escalate` rules (`:139`, `:149`) remap onto the bound-exhaustion halt per §5; update the `review-plan` / `review-pr` station rows |
| `pipeline/references/gates.md` | more than the modes table: the §Modes framing (`:3-4`), the `auto` row (`:20`), "both gates always resolve to the same value" (`:22-23`), the **report-only override** (`:25-31`, see §Decisions 8), the project-vs-package row (`:44`), and the Phase-A function list (`:107`). Absorbs mode semantics from the deleted `pipeline_resolve_policy`, including the not-`auto`-is-`interactive` default (§7) |
| `pipeline/references/manifest.md` | `gate_ledger` shape per §6; drop `gate_policy`; **amend the "Pointers, never content" rule** (`:31-32`) with the named `review` exception rather than leaving the file self-contradictory |

## Decisions log

1. **Free prose, prose-plus-a-quiet-tail, or prose-plus-`--structured`?** → *Free prose.* The tail
   preserves an audit of the reviewer's own filtering, which is a property the owner did not ask
   for; the flag is free prose with extra machinery nobody would type.
2. **Keep tiers for the pipeline only?** → *No, delete them.* Severity ranking has exactly one
   consumer — the interrupt decision — and that consumer is gone.
3. **Keep the verdict enum for loop-back detection?** → *No.* Cycles are counted from
   `outcome: looped-back`, never from a verdict, so the enum buys nothing.
4. **Bound exhaustion — PR anyway, or halt?** → *Halt in-session, no PR* (owner's decision).
   Twice-rejected work gets reviewed live, not through a PR.
5. **Delete `pipeline_resolve_policy`?** → *Yes.* Its remaining output is one boolean restatement of
   `mode`. Cost acknowledged: mode semantics stop being mechanically asserted and live in prose.
6. **Keep mandatory adjudication?** → *No, demote to an available move.* Its 2026-07-26
   justification was that *tiering is fallible in both directions*; with no tiers there is nothing
   to correct. The self-review-bias argument it also rested on survives, and that is what it is
   retained for.
7. **Scope — one change or two?** → *One.* Tiers span both skills; splitting leaves an intermediate
   state where the pipeline requests a vocabulary `/critique` no longer emits.

*The following came out of an independent review of this spec (2026-07-28), which found the
mechanism gaps now closed in §3, §5, §6 and §7.*

8. **The report-only override (`gates.md:25-31`) — keep or drop?** → **OPEN, owner's call.** It is
   `auto` with `plan-approval` flipped to `report` in `gate_policy`: "record the findings,
   adjudicate nothing, escalate nothing." Two of those three are now the default, so the override's
   only remaining effect is **suppressing the loop-back** — "review the plan, log it, but do not
   send me back to `design`." That is still a real knob, but it needs a new home since `gate_policy`
   is being deleted. Resolve before implementing.
9. **Store the review text, against `manifest.md`'s "Pointers, never content"?** → *Yes, as a named
   exception.* It is the only unreconstructable artifact in the ledger. The rule gets amended
   explicitly (§8) — the repo's precedent is that a reversal is replaced consciously, not silently.
10. **`engine.md` §Mechanical checks still says "escalate".** → *Remap both rules onto the
    bound-exhaustion halt* (§5). The alternative — leaving them to the grep pass — decides nothing;
    an unattended run hitting a third failed check attempt would have no defined move.
11. **Keep `gate`, `cycle`, `at` on ledger entries?** → *Yes.* `cycle` is load-bearing (it carries
    `"unknown"` after reconstruction, without which the loop-back bound stops bounding); `at` is the
    audit trail's only ordering. Dropping them was an oversight, not a simplification.

## Testing strategy

`./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`

`PipelineTest.php` — 5 tests today:

| Test | Fate |
|---|---|
| orders the chain and skips `verify-ui` when the UI is untouched | keep |
| refuses forward navigation past an un-run gate but allows backward | keep |
| keeps the un-skippable-review promise out of reach of the gate policy | **rewrite** — same guarantee, restated without a policy to be out of reach of: navigation depends only on which legs ran |
| resolves `interactive` to stop and `auto` to adjudicate | **delete** with the function |
| escalates only a finding that is blocking *and* confirmed | **delete** with the function |

`critique/checks` tests must stay green untouched — if any of them move, the tier-free claim in §7
was wrong.

Prose-level verification, since most of this change is documentation:

- Grep both skills for `tier`, `Tier`, `cap at 15`, `CONFIRMED`, `PLAUSIBLE`, `verdict block`,
  `architecture_judgment`, `escalate`, `adjudicat`, `gate_policy`, `cycle` — every surviving hit
  must be deliberate. `adjudicat` and `cycle` are expected to hit (both survive, §3/§6), which is
  precisely why they belong in the pass rather than outside it.
- Live: run `/critique plan` on a real spec and confirm it reads like a review rather than a form.
- Live: run a pipeline `review-plan` leg in each mode and confirm `gate_ledger` fills in the §6
  shape and that `interactive` shows the prose.

## Risks accepted

- **At `review-plan` the engine judges a critique of a plan it just wrote.** This is a structural
  conflict of interest, not random error: the party with the bias is also the party that decides
  whether to invoke the now-optional independent read (§3). Both prior specs treated exactly this
  as the reason adjudication was *mandatory* (`engine.md:210-212`). Accepted deliberately — the
  mandatory version cost an interrupt at every gate, which is the thing `auto` exists to prevent —
  but it is the sharpest edge in this design, and the reason the loop-back bound and the PR
  backstop both matter more than they did before.
- **A defect the engine misjudges on its first read ships to a PR.** Repeated rejection is caught by
  the loop-back bound, which halts in-session (§5); what is not caught is a real problem the engine
  reads once, resolves wrongly, and never revisits. Accepted — the PR is the backstop and it is
  trashable.
- **Review quality now depends on the reviewer's own judgment about what to report.** That is the
  pre-skill behaviour the owner explicitly preferred. The anti-self-grading guard Stage 3 provided
  is deliberately given up.
- **Ledger entries become less machine-comparable** across runs — no tiers to count, no verdicts to
  trend. No consumer of that comparison exists.
- **Mode semantics lose their mechanical assertion** with `pipeline_resolve_policy` (§Decisions 5).

## Out of scope

- `pipeline_can_navigate` / `pipeline_gate_legs` and the un-skippable-review promise.
- `critique/checks/` and `pipeline/checks/triggers.php` behaviour.
- `/code-review`, `review-round`, or anything about generic bug hunting.
- Merging, auto-merge, or anything past PR-review.
