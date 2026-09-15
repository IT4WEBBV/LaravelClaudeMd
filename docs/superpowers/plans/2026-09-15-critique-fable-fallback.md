# Critique reviewer falls back to Opus on a usage limit Implementation Plan

**Spec:** docs/superpowers/specs/2026-09-15-critique-fable-fallback-design.md

## Test first
Documentation-only change, so the test is a skill scenario: fresh agents given the current
`critique` + `engine.md` and a failed Fable reviewer notification (`rate_limit`, HTTP 429). Red when
they halt or retry on Fable; green when they re-dispatch once on Opus and print the fallback line.
A second scenario, where the Opus dispatch is limited too, must still halt.

## Steps
1. Run the baseline scenario on the unchanged skills; record what the agents do.
2. Edit `critique/SKILL.md` Stage 2 and Stage 3; edit `engine.md` §Failure policy.
3. Re-run both scenarios on the edited skills; both behave as the spec says.
4. Pest suites for `pipeline` and `critique` stay green.
