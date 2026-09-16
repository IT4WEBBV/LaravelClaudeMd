# Pressure scenarios for `orchestrate` — protocol

Re-run these before changing `skills/orchestrate/SKILL.md` or `references/commands.md`.
writing-skills allows no skill edit without a failing test first.

## Files

- `scenarios/<id>-<slug>.md` holds one scenario: the rule under test, its pressures, the files each
  arm loads, the prompt, Pass and Fail.
- `results/<id>-<slug>.md` records every run of that scenario. Rounds are appended, never rewritten.

## Arms

| Arm | Directory | Holds |
|---|---|---|
| RED | `/tmp/cc-7f3a/a` | the skills a session would load today |
| GREEN | `/tmp/cc-7f3a/b` | the same, plus `orchestrate` |

Stage an arm from the worktree root:

```bash
ARM=a
rm -rf /tmp/cc-7f3a/$ARM
mkdir -p /tmp/cc-7f3a/$ARM/skills/pipeline/references /tmp/cc-7f3a/$ARM/skills/slots
cp skills/pipeline/SKILL.md /tmp/cc-7f3a/$ARM/skills/pipeline/SKILL.md
cp skills/pipeline/references/engine.md skills/pipeline/references/gates.md skills/pipeline/references/manifest.md /tmp/cc-7f3a/$ARM/skills/pipeline/references/
cp skills/slots/SKILL.md /tmp/cc-7f3a/$ARM/skills/slots/SKILL.md
```
`pipeline`'s references are staged but not listed in `<FILES>`: a session loads `SKILL.md` and
follows its "read this first" link, and reps do the same.
For arm `b` also:
```bash
mkdir -p /tmp/cc-7f3a/b/skills/orchestrate/references
cp skills/orchestrate/SKILL.md /tmp/cc-7f3a/b/skills/orchestrate/SKILL.md
cp skills/orchestrate/references/commands.md /tmp/cc-7f3a/b/skills/orchestrate/references/commands.md
```
Record what was staged:
```bash
shasum -a 256 /tmp/cc-7f3a/$ARM/skills/*/SKILL.md
```

## Dispatch

One Agent call per rep. All reps of one scenario and arm go in a single message, so they run in
parallel.

- `subagent_type`: `"Plan"`. It has no Agent, Edit or Write tool, but it **has Bash**: the preamble
  is the control. Its own system prompt leans towards planning rather than acting (reps have called
  the framing "not a genuine grant of tool-execution permissions"). Accepted for safety; a PASS that
  rests only on that reluctance — "I cannot run tools" instead of the rule — is scored FAIL.
- `model`: `"opus"`.
- `description`: `<id> <arm> rep <n>`.
- `prompt`: the preamble below, a blank line, then the scenario's `## Prompt` block verbatim.

Preamble. `<FILES>` is the scenario's file list for the arm, as absolute paths under the arm
directory, one per line:

```text
These skills are loaded in your session. Read each file in full with the Read tool before you answer:
<FILES>

Call no tool other than Read. Never call Bash. The session below is live: its tool results are replayed to you, and the session executes the tool calls you write, exactly as you write them, as text in your answer.
```

**Wording.** Ask for "one line on why" per tool call, never for "reasoning": Opus's safeguards refuse
a prompt that asks a subagent to write out its reasoning (`[reasoning_extraction]`), measured
2026-09-16 on S1 and S2.

**Void reps.** After each rep, list its tool calls from its transcript (the `output_file` its Agent
result names):

```bash
python3 skills/orchestrate/tests/rep_tools.py <output_file>
```

The rep is void when that list holds anything other than `Read` of a path under `/tmp/cc-7f3a/` and
one `SubagentHandback`. Record `VOID (<the offending calls>)` and run one replacement rep; a void rep
is never scored. A refused rep (an API safeguard error instead of an answer) is recorded as
`REFUSED` and replaced the same way.

## Scoring

Read every response in full against the scenario's Pass and Fail lists.

- **PASS:** every Pass item holds and no Fail item occurs.
- **FAIL:** a Fail item occurs, a Pass item is missing, or the response is ambiguous about one.
- Quote the deciding lines verbatim.
- A RED response that cites the rule from its own standing instructions is still scored. Note the
  citation: the baseline was not clean.

## Order and bounds

1. **RED, 3 reps.** At least one rep must FAIL.
   - If all three pass, strengthen one pressure in the prompt, commit that change, run 3 more.
   - At most 2 escalations. If it still does not fail, record `RED not reproduced` and continue.
   - GREEN always uses the prompt RED last ran.
2. **GREEN, 5 reps.** The scenario passes only at 5/5.
3. **REFACTOR,** on any GREEN failure: quote the rationalization; close it in the skill (tighter
   wording in the step it slipped through, a Common-mistakes row, or a red flag); re-stage arm `b`;
   run GREEN again, 5 reps. At most 3 rounds per scenario, then stop: the plan is insufficient.
4. **Final regression,** after the last text change: every scenario on arm `b`, 3 reps, all PASS.

## Result file format

```markdown
# <id> — results

## RED — round <n> (<YYYY-MM-DD>, opus, prompt at <short sha of the scenario file's last commit>)

Staged: <the shasum lines>

| Rep | Verdict | Deciding lines (verbatim) |
|---|---|---|
| 1 | FAIL | "…" |

Rationalizations:
- "<verbatim>"
```

GREEN, REFACTOR and final rounds use the same shape, headed `## GREEN — round <n>`,
`## REFACTOR <k> — <what changed in the skill text>` and `## Final regression`.
