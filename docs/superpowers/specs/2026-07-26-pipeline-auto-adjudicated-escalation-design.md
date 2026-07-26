# `pipeline` — adjudicated escalation in auto mode — design

**Date:** 2026-07-26
**Status:** draft → user review → implementation plan → PR
**Canonical home:** `IT4WEBBV/LaravelClaudeMd`, `skills/pipeline/` (global skill, symlinked into `~/.claude/skills/`).
**Amends:** `2026-07-24-pipeline-skill-design.md` (the original pipeline design). This spec changes that design's gate policy; everything else in it stands.
**Surfaced by:** a live `/pipeline auto` run on viewiemedia issue #1926, which parked for a human verdict after a clean `approve-with-nits` review with zero blocking findings.

## Summary

In `auto` mode the pipeline currently stops at both review gates unconditionally. It should instead
**integrate reasonable review feedback itself and continue**, escalating to a human only when an
independent check confirms a finding that genuinely needs a human decision.

The governing principle, from the skill's owner:

> The entire idea of auto mode is that escalating to a human should be an exception, not the norm.
> If it sometimes results in a bad PR that is okay — we can always trash the PR.

That risk position is sound for this machinery: **the pipeline never merges.** Every output is a PR
read before merge, and `implement` runs against a slot's own database, never production. The worst
case is a discarded branch. An unnecessary interrupt, by contrast, costs the one thing auto mode
exists to protect.

## The problem

`pipeline_resolve_policy()` sets `auto_continue` from the mode but hardcodes both gates to `stop`:

```php
return [
    'auto_continue' => $mode === 'auto',
    'gates' => ['plan-approval' => 'stop', 'pr-review' => 'stop'],  // mode-independent
];
```

So `auto` means "don't ask me between legs" but still "ask me at every gate." On a clean run the
human turn carries no information: on the #1926 run the reviewer returned `approve-with-nits`,
0 Tier-1, 2 Tier-2, no architecture concern, and no content trigger — and the run stopped anyway.

### What is *not* the problem

The un-skippable-review promise does **not** depend on the `stop` policy.
`pipeline_can_navigate()` tests whether the gate *leg ran*:

```php
if ($gi < $ti && ! in_array($gate, $doneLegs, true)) { return false; }
```

It never consults a human verdict. So "there is no path to a non-draft PR that has not passed
`review-plan` and `review-pr`" stays true as long as the reviews keep running. The unconditional
`stop` is a **separate, stricter policy layered on top** — and it is the only thing this spec
changes.

## Principle

Reviews always run. Their findings are **proposals, not verdicts**. The engine continues by default
and escalates only on a finding an independent check confirms. Ambiguity resolves toward continuing;
the ambiguity is surfaced on the PR instead of in an interrupt.

## Design

### 1. Verdict contract

`review-plan` and `review-pr` must return a structured block:

| Field | Meaning |
|---|---|
| `verdict` | `approve` \| `approve-with-nits` \| `rework` |
| `findings[]` | each `{tier: 1\|2, claim, evidence, suggested_action}` |
| `architecture_judgment` | `none`, or the project-vs-package (or comparable) concern |

Today this shape is a prompt convention invented per-run, not a guarantee. It becomes part of the
leg's contract, because every downstream decision reads it.

**A malformed or missing block is a machinery failure, not a finding.** Retry the reviewer once;
if it is still malformed, halt. Fail-closed is retained exactly where it belongs — broken tooling —
without spending it on findings.

### 2. Triage

| Finding | Route |
|---|---|
| Tier-2 | integrate directly; no verification |
| Tier-1, `rework`, or an architecture concern | adjudication (§3) |

### 3. Adjudication

Each suspicious finding goes to a **fresh subagent that never saw the design leg**, is given the
finding plus the code, and is asked to **refute** it, citing `file:line`.

Independence is the point. At `review-plan` the engine would otherwise be adjudicating a critique of
a plan it just wrote — the exact self-review bias `/critique` exists as a separate agent to avoid.

| Adjudication | Disposition |
|---|---|
| **refuted** (with evidence) | downgrade to advisory, log, continue |
| **confirmed** | escalate — stop with a packaged parcel |
| **uncertain** | continue; carry the finding verbatim into the PR body as an open question |

The `uncertain` branch is a deliberate choice of the owner's risk position over the reviewer's
caution: ambiguity does not buy an interrupt, it buys a line in the PR. This is the most likely
origin of an occasional bad PR, and that is accepted.

### 4. Integration

Actionable Tier-2s and refuted Tier-1s are applied to the spec/plan and committed. Non-actionable
ones (already-mitigated observations, notes for posterity) are recorded without an edit.

### 5. What still stops

The exceptions, and only these:

- **Hard failures** — the stack won't start, tests won't go green, a tool dies, reviewer output is
  malformed after one retry.
- **A confirmed Tier-1 or architecture concern** — adjudication upheld it.
- **Bound exhaustion** — a confirmed `rework` loops back to `design` *autonomously* rather than
  stopping, but is bounded to **2** design↔review cycles before escalating. `verify-ui` failure
  loops back to `implement` under the same bound. The bound is what keeps an autonomous loop from
  churning indefinitely without ever surfacing.

### 6. Audit trail

Every finding, its adjudication, the cited evidence, and the disposition are written to the
manifest's `gate_ledger` and projected onto the PR. **Overruling a reviewer is fine; overruling one
invisibly is what turns a gate into decoration.**

### 7. Content triggers become annotations, not stops

This section concerns the three triggers that produce **stops** — `migration`, `auth`, and
`package`. It does **not** touch `ui`: that trigger selects whether the `verify-ui` *leg runs*, and
that stays mandatory and unchanged. A UI-touching change still cannot reach `review-pr` without
`browser-verification` proof, exactly as today.

The three stop-producing triggers are **facts** (a path matched), not findings to be refuted — so
"adjudicating" them is incoherent; the only real question is whether the fact warrants a human, and
under the governing principle it does not.

They become **loud mandatory annotations** on the PR and in the manifest — "this PR contains a
migration", "this PR touches authorization" — and the run continues.

**Caveat, recorded once:** authorization and migration defects are the ones most easily missed in a
quick PR skim, precisely because they look small. The annotation must be prominent in the PR body,
not a footnote.

This deliberately rewrites a documented invariant. `gates.md` currently states content gates are
"non-skippable in both modes" and "never downgraded to report-only, in either mode." That text is
replaced, consciously rather than silently.

### 8. Code surface

| Change | Where |
|---|---|
| gates become mode-dependent: `auto` → `'adjudicate'`, `interactive` → `'stop'` | `checks/pipeline.php` — `pipeline_resolve_policy()` |
| new pure helper `pipeline_should_escalate(array $finding, string $adjudication): bool` — `$adjudication` is `'none'` for findings that never went to adjudication (all Tier-2s), so the helper is total over every finding the triage produces | `checks/pipeline.php` |
| adjudication procedure; revised failure policy incl. the retry bound | `references/engine.md` |
| revised modes table; content triggers as annotations; the invariant rewrite | `references/gates.md` |
| `gate_ledger` entry shape for findings/adjudications | `references/manifest.md` |
| update the test pinning `auto` → `'stop'`; add cases for the new helper | `checks/tests/PipelineTest.php:26` |

## Decisions log

1. **Trust the reviewer's tier, or apply a second test?** → *Adjudicate.* `/critique` already tiers
   findings, but tiering is fallible in both directions: a plan-invalidating risk can arrive as
   Tier-2 ("this assumption is unverified"), and a confidently-wrong Tier-1 can burn an interrupt on
   a claim the code refutes. Adjudication catches both, and subsumes the `needs_human` escalation
   flag considered earlier — with adjudication on every suspicious finding, the reviewer needs no
   special channel.

2. **A volume cap on Tier-2 count (escalate if > N)?** → *Rejected.* The threshold is arbitrary and
   misfires both ways; on the run that motivated this change it would have done nothing (2 nits).

3. **A "structural change" test (auto-integrate only cosmetic nits)?** → *Rejected.* No mechanical
   test distinguishes structural from cosmetic, so the gate would become model-dependent — and the
   very first real case was ambiguous: a nit deleting an *optional* command from a plan step reads
   as either.

4. **Architecture call — always stop, or adjudicatable?** → *Adjudicatable* (owner's decision;
   reverses the initial recommendation). It escalates when adjudication confirms it, like any other
   Tier-1.

5. **`uncertain` → escalate or continue?** → *Continue* (owner's decision; reverses the initial
   recommendation, which resolved ambiguity toward the human). Consistent with §Principle.

6. **Content triggers — stop or annotate?** → *Annotate* (owner's decision; reverses the initial
   recommendation). Rationale in §7: they are facts, not findings, and the pipeline never merges.

7. **Does `interactive` change?** → *No.* It keeps `stop` at every gate. This spec touches only the
   `auto` path.

## Testing strategy

The deterministic guardrails stay tested PHP (`checks/`, run with
`./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`):

- `pipeline_resolve_policy('auto')` → gates `'adjudicate'`; `('interactive')` → gates `'stop'`
- `pipeline_should_escalate()` across the matrix: Tier-2 → false; Tier-1 refuted → false;
  Tier-1 confirmed → true; Tier-1 uncertain → false; architecture confirmed → true
- `pipeline_can_navigate()` regression — unchanged behaviour, proving the un-skippable-review
  promise survives the policy change
- the existing `auto` → `'stop'` assertion is *replaced*, not deleted, so the change is visible in
  the diff

## Risks accepted

- **A confirmed-wrong adjudication lets a real defect through to a PR.** Accepted: the PR is the
  backstop, and it is trashable.
- **`uncertain` findings reach the PR body rather than the human's attention directly.** Accepted,
  per §3.
- **Content-trigger annotations can be skimmed past.** Mitigated by prominence, not eliminated.

## Out of scope

- Any change to `interactive` mode.
- Any change to `/critique`'s own review logic (only its *return shape* is constrained here).
- Merging, auto-merge, or anything past PR-review.
- Worktree teardown — still never automatic.
