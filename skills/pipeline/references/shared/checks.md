# Mechanical checks

Read by: `steps/implement.md`, `steps/review-pr-review.md`

## Mechanical checks — the deterministic layer inside `implement`

Opt-in per repo. A repo declares its checks in a **committed** `## Checks` block in
`.claude/work-on.config.md`; `pipeline_repo_checks()` (`../../checks/checks.php`) parses it and returns
one of three states. The block must be committed because the run's worktree is built from git — a
config written only in the primary checkout is invisible to every run, and deleting the block is a
de-adoption that `review-pr` should see.

| State | Meaning | Behaviour |
|---|---|---|
| `absent` | no `## Checks` section, and no check-shaped keys anywhere | not adopted — `implement` runs no checks and says nothing about them |
| `valid` | section present, every declared key parses to a non-empty command | run the checks |
| `invalid` | malformed: heading typo, unknown or mis-cased key, empty value, empty section | **machinery failure — halt.** `error` carries the reason |

`absent` and `invalid` are deliberately different states: collapsing them would let a typo'd heading
disable the checks permanently while the run believed it was covered.

**Invocation.** Each command is passed through `pipeline_expand_slot($command, $slotSuffix)` before
running. `<N>` is the run's slot **suffix** — empty on the primary stack, `-2` / `-3` … in a slot —
taken from the slot already resolved for the worktree at kickoff. A hardcoded container name execs
the *primary* stack and analyses the *primary* checkout, reporting no findings and passing green on
code the run never touched.

**What runs, and when.** After each plan step: the test suite (skipped when `shared/suite.md` §Suite reuse finds this
tree already green), then `static-analysis` over the whole declared scope.
`format` runs **once per `implement` step**, over the whole tree, when the step's code is complete:
before its last suite run, so the recorded `suite` covers the formatted tree (a Pint change after the
suite changes the tree key and costs a second full suite at `review-pr`), and before the push, with
what it changed committed. A change after it (the fix for a red suite or a finding) runs it once more.
**No file lists and no diff-scoping** for `static-analysis`: the analyser's bootstrap is a fixed floor, so
scoping saves little.

**Pint's cache makes every call after the first cheap** (#79). Pint keeps its cache in the container's
temp dir without being told to, so only the first call in a fresh stack pays; a changed-files list would
save that one call and nothing after it, so there is none.

The formatter runs over the whole tree because `--dirty` needs a git repository inside the analysed
tree, which the container does not have. That only behaves well once the repo has taken its one-off
blanket format commit, so **that commit is a prerequisite for declaring `format`**.

**Two failure kinds, and only one of them is a hard failure:**

| Kind | Trigger | Response |
|---|---|---|
| **Check failure** | the checks report a finding **in a file this change touched** | the **step is not done**. Fix and re-run, bounded to 2 attempts; on the third, the bound-exhaustion halt (`session.md` §Failure policy). A finding on its own is not a halt — it is exactly how a failing test behaves |
| **Machinery failure** | probe returns `invalid`, the container is missing, the command errors, the tool is not installed | **halt**, per `session.md` §Failure policy |

A reported finding in a file the change did **not** touch is an annotation on the PR, not a blocker:
other write paths (plain `work-on`, direct commits, a colleague's merge) reach the same repo without
running checks, and hard-failing a run for someone else's finding leaves it no legal move.

**Suppression is bounded.** Where a finding genuinely cannot be resolved, `implement` may add
`@phpstan-ignore <identifier>` — never the bare form, which suppresses every error on the next line
including future real ones — with a justification comment. **More than two suppressions in one run
triggers the bound-exhaustion halt** (`session.md` §Failure policy): the agent whose step is blocked
would otherwise judge its own excuse.

**Exhaustion is the bound-exhaustion halt.** A check failure that survives its 2 fix attempts, or more
than two `@phpstan-ignore` suppressions in one run, halts the run as an exhausted loop-back bound does,
with the same duties before and after `handoff` (`session.md` §Failure policy).

**The result is recomputed at leg start, never stored.** The check result (a re-runnable command) and
the suppression count (grep-able from the diff) are both recomputable, so nothing about checks enters
the manifest (`manifest.md` §Two rules that keep the file honest).

**Into `review-pr`.** The review step states the result, in its `/critique pr` invocation, **qualified by the analysed scope** — "0 new
findings over `app/`", never an unqualified "0 new findings", since the declared scope does not cover
`database/`, `routes/`, `config/` or `tests/`. Any suppressions added during the run are listed and
flagged as **not yet judged**, so one cannot enter reading as already resolved.
