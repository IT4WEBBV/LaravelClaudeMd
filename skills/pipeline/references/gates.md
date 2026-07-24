# Gates — modes, content triggers, and the navigation guardrail

Two kinds of thing stop the chain: **mode-driven station gates** (the human turns) and
**content-triggered gates** (non-skippable in both modes). The forward-navigation guardrail is
the mechanism that makes the review gates un-skippable *by construction*, not by memory.

All three are backed by tested Phase A functions in `../checks/pipeline.php` and
`../checks/triggers.php`. This doc mirrors those functions; keep them in lock-step.

## Modes — one choice, resolved to a policy table

`pipeline_resolve_policy($mode)` returns `['auto_continue' => bool, 'gates' => ['plan-approval'
=> 'stop', 'pr-review' => 'stop']]`. The mode sets **only** `auto_continue`; both
station-boundary gates resolve to `stop`.

| Mode | `auto_continue` | Behaviour |
|---|---|---|
| **`interactive`** *(default)* | `false` | you are present; run one leg, show you, wait. Advance by saying so (see navigation). |
| **`auto`** | `true` | run the autonomous legs unattended and **park** at plan-approval and PR-review with a packaged parcel; hard-stop at the content gates and on failure. |

**Report-only override.** "Run past the plan gate straight to a PR" is not a third mode — it is
`auto` with the `plan-approval` gate flipped to `report` in the manifest's `gate_policy`. That
is a **one-line, visible edit to stored data**, for well-specified work; `pipeline_resolve_policy`
itself never returns `report`. The two review gates are never *silently* skipped.

## Content-triggered gates — non-skippable in both modes

`pipeline_triggers($diff, $repoPackageName)` returns four booleans —
`['package' => bool, 'migration' => bool, 'auth' => bool, 'ui' => bool]`. Three feed content
gates; `ui` gates the `verify-ui` leg (below).

| Gate | Trigger (`pipeline_triggers`) | Kind |
|---|---|---|
| touches an `it4web/*` package | `$repoPackageName` starts `it4web/` (the change is *in* a package repo), **or** an added `composer.json` line names an `it4web/*` constraint | deterministic |
| writes a DB migration | an added/changed file path matches `database/migrations/…\.php` | deterministic, detective (sees a migration already written) |
| touches authorization | an added line matches `authorize(` / `Gate::` / `Policy` / `can:` / `->can(` / `middleware('can:` | deterministic |
| the project-vs-package call | **none mechanical** — a `/critique plan` judgment the reviewer returns an explicit verdict on | judgment |

Deterministic gates stop the chain when their trigger fires; the judgment gate stops whenever
the `review-plan` reviewer flags it. **Neither is ever downgraded to report-only, in either
mode.** (Migration detection is by *path*, not by data-write — a `DB::table()->update()` in an
Action does not trip it; a file under `database/migrations/` does.)

## `verify-ui` — non-skippable when the UI is touched

When `pipeline_triggers(...)['ui']` is true (a `*.blade.php`, a Livewire component under
`app/Livewire/` or `app/Http/Livewire/`, `resources/{views,css,js}/…`, a `.vue`, or
`tailwind.config`), the `verify-ui` leg becomes a **gate leg** and the chain cannot reach
`review-pr` without attached `browser-verification` proof. When `ui` is false, `verify-ui` is
skipped entirely (`pipeline_next_leg` steps over it). See `engine.md` for the leg itself.

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

Navigation and policy are pure functions — call `pipeline_can_navigate` /
`pipeline_next_leg` / `pipeline_resolve_policy` directly (they take no I/O). The manifest's
`gate_ledger` records which gates have run; `pipeline_can_navigate`'s `$doneLegs` is derived
from it.
