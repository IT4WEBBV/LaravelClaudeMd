# When the plan falls short

Read by: `steps/review-plan-review.md`, `steps/review-plan-resolve.md`, `steps/handoff.md`, `steps/implement.md`, `steps/verify-ui.md`, `steps/review-pr-review.md`, `steps/finish.md`

## On a Bounded spec — the escalation check

**When to check.** Only while the spec says Bounded (`**Design size:** Bounded`), by the step itself — its brief says so:
- after every commit in `implement`, and
- first thing in every later step, except a resolve step, which loops back instead (§A resolve step loops back).

```bash
CHECKS="$HOME/.claude/skills/pipeline/checks"
git diff origin/<base>...HEAD > "<manifest stem>.diff"
# triggers.php loads the diff parser itself — do not require it a second time
php -r 'require $argv[1] . "/triggers.php"; require $argv[1] . "/design_size.php";
        $diff = file_get_contents($argv[2]);
        $size = DesignSize::fromSpec(file_get_contents($argv[3]));
        echo $size->escalation(pipeline_triggers($diff), pipeline_code_lines($diff)) ?? "", "\n";' \
  "$CHECKS" "<manifest stem>.diff" "<spec path>"
# empty line → stays Bounded; otherwise the printed reason is why it must grow
```

**What escalates.**
- `migration` or `auth` fires: a 15-line spec may not name what the diff contains.
- More than `DesignSize::MAX_CODE_LINES` (100) code lines, added + deleted.
- `package` does **not** escalate. In a project it is a constraint bump whose code was reviewed in
  the package's own PR; it keeps its annotation.
- **Judgement also escalates:**
  - brainstorming's ratchet upgrades the path;
  - an `autoflow` assumption turns out to change what gets built;
  - `implement` needs files or behaviour the plan did not name. The implement step returns
    **"plan insufficient"** instead of improvising.

**On escalation**, `record --status plan-insufficient --reason <why>` appends the ledger entry
`{gate: 'design-size', leg: <current leg>, at, reason, outcome: 'escalated'}`. The run goes back to
`design`, which grows the design (`steps/design.md` §The grow form).

## On an Architectural spec — a plan gap

A step on an Architectural spec that needs files or behaviour the plan does not name returns
`plan-insufficient` too, and does not improvise. There is no size to grow, so this is a **loop-back
of the plan approval**: the plan passed `review-plan` and turned out not to cover the change.

The step returns `plan-insufficient` with `record --reason`, which appends
`{gate: 'plan-approval', leg: <its leg>, cycle, at, reason, outcome: 'looped-back'}`. A return
without that entry halts. The `reason` names what the plan lacks.
The size alone is never a gap: an Architectural plan needs no approval beyond `review-plan`'s (#96).

The run goes back to `design` through `review-plan`'s bound (`gates.md` §Loop-backs); the third halts
(`session.md` §Failure policy). `design` extends the plan to cover the `reason`
(`steps/design.md` §Answering a plan gap).

## A resolve step loops back

**A resolve step never returns `plan-insufficient`.** A resolve step that finds a plan gap or a Bounded
escalation returns `looped-back`: its open entry is completed and no bound is charged twice. If
`implement` then finds the plan short, it reports the gap itself. **A review step that returns
`plan-insufficient` appends no review entry**, so an escalation found after the review was written
cannot leave a stale open review behind. In `autoflow` a resolve step's schema has no
`plan-insufficient`; in `interactive` `pipeline_returned()` halts on either.
