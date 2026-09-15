# Critique reviewer falls back to Opus on a usage limit — design

**Design size:** Bounded

## Problem
`/critique` dispatches its reviewer on Fable. When that dispatch dies on a usage limit, nothing
switches model: Claude Code has no fallback for plan limits (`fallbackModel` covers overload only)
and no per-model usage a skill can read beforehand. The pipeline then applies "reviewer returns
nothing after a single retry → halt", so a run stops at `review-plan` or `review-pr` while Opus is
still available. On 2026-09-09 three runs died this way and sat idle until resumed by hand.

## Change
- `skills/critique/SKILL.md` Stage 2: a reviewer that terminates early on a usage-limit error is
  re-dispatched once, same brief, on `model: "opus"`; partial output is discarded; a limit on the
  Opus dispatch too is reported with its reset time and stops. Stage 3 prints one line saying the
  review ran on Opus. Applies to every agent the skill dispatches.
- `skills/pipeline/references/engine.md` §Failure policy: that re-dispatch is not the single retry
  and not a hard failure; a limit that also blocks Opus is.

## Done when
A `/critique` or pipeline session handed a failed Fable reviewer with a `rate_limit` / HTTP 429
error re-dispatches on Opus instead of halting, and says so in one chat line.

## Assumptions
- Any usage-limit error falls back, not only one naming Fable: the text of a Fable-only limit has
  never been observed, and an account-wide limit costs one failed Opus call before the halt.
- Visibility is the chat line only; no ledger, PR or proof-page field (owner decision).
- A main session on Fable is out of scope: Claude Code prompts to switch there itself.
