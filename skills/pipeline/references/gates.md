# Gates — modes, content triggers, and the navigation guardrail

Two kinds of thing shape the chain: **mode-driven station gates** (a human turn, or an
adjudication) and **content triggers** (facts about the diff). The forward-navigation guardrail is
a third, separate mechanism — it makes the review *legs* un-skippable *by construction*, not by
memory, and it reads neither the mode nor the gate policy.

All three are backed by tested Phase A functions in `../checks/pipeline.php` and
`../checks/triggers.php`. This doc mirrors those functions; keep them in lock-step.

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

## `verify-ui` — non-skippable when the UI is touched

When `pipeline_triggers(...)['ui']` is true (a `*.blade.php`, a Livewire component under
`app/Livewire/` or `app/Http/Livewire/`, `resources/{views,css,js}/…`, a `.vue`, or
`tailwind.config`), the `verify-ui` leg becomes a **gate leg** and the chain cannot reach
`review-pr` without attached `browser-verification` proof. When `ui` is false, `verify-ui` is
skipped entirely (`pipeline_next_leg` steps over it). See `engine.md` for the leg itself.

**The `ui` trigger is untouched by the annotation change above.** The other three decide whether a
*human is asked*, and are now answered with an annotation. This one decides whether a *leg runs* —
a different question, with a different answer: mandatory, in both modes, unchanged.

## Navigation guardrail — forward past an un-run gate is refused

`pipeline_can_navigate($from, $to, $doneLegs, $triggers)`:

- **Backward or same position → always allowed.** Jump back to redo a review or revise the spec.
- **Forward → allowed only if every gate leg strictly before `$to` is in `$doneLegs`.**

The gate legs are `review-plan` and `review-pr` **always**, plus `verify-ui` **only when**
`$triggers['ui']` is true (`pipeline_gate_legs`). So "skip ahead to review-pr" is refused while
a triggered `verify-ui` has not run, and "jump to implement" is refused while `review-plan` has
not run. **This refusal is the un-skippable-review promise** — there is no path to a non-draft
PR that has not passed `review-plan` and `review-pr` against the recorded artifact.

## How the engine calls Phase A

The diff for gate detection is the whole change against the base branch; the package trigger
also needs the repo's own `composer.json` `name`:

```bash
# the change under review
git diff origin/<base>...HEAD > /tmp/pipeline.diff

# triggers over that diff, with the repo's package name for the in-package case
php -r 'require "skills/pipeline/checks/triggers.php";
        echo json_encode(pipeline_triggers(
          file_get_contents("/tmp/pipeline.diff"),
          json_decode(file_get_contents("composer.json"), true)["name"] ?? null
        )), "\n";'
# → {"package":…,"migration":…,"auth":…,"ui":…}
```

Navigation, policy and escalation are pure functions — call `pipeline_can_navigate` /
`pipeline_next_leg` / `pipeline_resolve_policy` / `pipeline_should_escalate` directly (they take
no I/O). The manifest's `gate_ledger` records which gates have run; `pipeline_can_navigate`'s
`$doneLegs` is derived from it.
