# Gates — content triggers, `verify-ui`, the navigation guardrail and loop-backs

Two kinds of thing shape the chain: **mode-driven station gates** (a human turn, or a resolve step
acting on a review; `session.md` §Modes) and **content triggers** (facts about the diff). The
forward-navigation guardrail is a third, separate mechanism — it makes the review *legs* un-skippable
*by construction*, not by memory, and it reads neither the mode nor anything a review said.

All three are backed by tested Phase A functions in `../checks/pipeline.php` and
`../checks/triggers.php`. This doc mirrors those functions; keep them in lock-step.

**`medium` and `light` are not modes:** they permit a Bounded design (`steps/design.md` §Design size)
and name the agents tier (`session.md` §Invocation); legs, gates and navigation are the same for both sizes.

## Content triggers — three annotate, one gates a leg

`pipeline_triggers($diff, $repoPackageName)` returns four booleans —
`['package' => bool, 'migration' => bool, 'auth' => bool, 'ui' => bool]`. Three annotate; `ui`
gates the `verify-ui` leg (next section).

| Trigger | Detection (`pipeline_triggers`) | Effect |
|---|---|---|
| touches an `it4web/*` package | `$repoPackageName` starts `it4web/` (the change is *in* a package repo), **or** an added `composer.json` line names an `it4web/*` constraint | annotation |
| writes a DB migration | an added/changed file path matches `database/migrations/…\.php` | annotation |
| touches authorization | an added line matches `authorize(` / `Gate::` / `Policy` / `can:` / `->can(` / `middleware('can:` | annotation |
| the project-vs-package call | **none mechanical** — a `/critique plan` judgment, made in prose (the `plan` rubric asks for it) | the resolve step acts on it like any other part of the review (`shared/resolving.md` §Resolving a review) |

**On a Bounded design, `migration` and `auth` escalate** instead of only annotating: the design grows
to Architectural and is re-reviewed (`shared/plan-falls-short.md` §On a Bounded spec). The auth match ignores comment and
docblock lines, because on a Bounded design a false positive costs a re-review, not a footnote.
`package` only ever annotates.

**The three annotating triggers do not stop the chain.** They are **facts** — a path matched —
not findings to be refuted, so "resolving" them is incoherent; the only real question is
whether the fact warrants a human, and under the governing principle (the pipeline never merges;
a bad PR is trashable) it does not. Each fires a **mandatory, prominent annotation** — "this PR
contains a migration", "this PR touches authorization" — in the PR body and in the manifest's
`gate_ledger`, and the run continues.

Authorization and migration defects are the easiest to miss in a quick skim: the annotation leads the
PR body, never a footnote.

(Migration detection is by *path*, not by data-write — a `DB::table()->update()` in an Action does
not trip it; a file under `database/migrations/` does.)

A leg that needs the triggers itself — the package annotation, the Bounded escalation check — calls
`pipeline_triggers()` over the run's diff, with the repo's `composer.json` `name` for the in-package
case:

```bash
php -r 'require "skills/pipeline/checks/triggers.php";
        echo json_encode(pipeline_triggers(
          file_get_contents("<manifest stem>.diff"),
          json_decode(file_get_contents("composer.json"), true)["name"] ?? null
        )), "\n";'
# → {"package":…,"migration":…,"auth":…,"ui":…}
```

## `verify-ui` — non-skippable when the UI is touched

When `pipeline_triggers(...)['ui']` is true (a `*.blade.php`, a Livewire component under
`app/Livewire/` or `app/Http/Livewire/`, `resources/{views,css,js}/…`, a `.vue`, or
`tailwind.config`), the `verify-ui` leg becomes a **gate leg** and the chain cannot reach
`review-pr` without attached `browser-verification` proof. When `ui` is false, `verify-ui` is
skipped entirely (`pipeline_next_leg` steps over it). The leg itself is `steps/verify-ui.md`.

**The `ui` trigger decides whether a *leg runs*, not whether a human is asked.** The other three are
answered with an annotation; this one is answered with the leg: mandatory, in every mode.

## Navigation guardrail — forward past an un-run gate is refused

`pipeline_can_navigate($from, $to, $doneLegs, $triggers)`:

- **Backward or same position → always allowed.** Jump back to redo a review or revise the spec.
- **Forward → allowed only if every gate leg strictly before `$to` is in `$doneLegs`.**

The gate legs are `review-plan` and `review-pr` **always**, plus `verify-ui` **only when**
`$triggers['ui']` is true (`pipeline_gate_legs`). So "skip ahead to review-pr" is refused while
a triggered `verify-ui` has not run, and "jump to implement" is refused while `review-plan` has
not run. **This refusal is the un-skippable-review promise** — there is no path to a non-draft
PR that has not passed `review-plan` and `review-pr` against the recorded artifact. What no mode can
do is stop a review *leg* from running.

Navigation is pure functions — call `pipeline_can_navigate` / `pipeline_next_leg` /
`pipeline_gate_legs` directly (they take no I/O). The manifest's `gate_ledger` records which gates
have run; `pipeline_can_navigate`'s `$doneLegs` is `pipeline_done_legs()` over it, which drops gate
passes older than the latest `design-size` escalation or plan gap and never counts an open entry.
**Gates count again** after either: the pass over the small design, or the plan approval a gap
overturned, cannot carry navigation past the re-review.

**The PR stays draft until `review-pr`'s finish step.** `handoff` opens the PR draft and nothing marks
it ready before `review-pr`'s finish step: `implement` runs before `verify-ui` and `review-pr`, so an
`implement` that undrafted would skip both.

## Loop-backs — where a looped-back leg goes

`pipeline_loop_target($leg)` (`../checks/dispatch.php`):

- `review-plan` → `design`
- `verify-ui` → `implement`
- `review-pr` → `implement`

Each is bounded to 2 per gate, counted from the gate's `looped-back` ledger entries; the third halts,
and so does any loop-back once the count is `unknown` (`manifest.md` §Reconstruction). In
`interactive` `pipeline_returned()` evaluates both; in `autoflow` the workflow script does, on
`tables.loopTarget` and `tables.bound` from `launch`'s `start` answer (`pipeline_routing_tables()`),
starting from `launch`'s ledger counts. Keep this list in lock-step with the function: `LockStepTest`
fails when it drifts.

- **Count the `looped-back` entries, not all entries.** A gate's history also holds halts and
  human-ordered re-reviews, and counting those would over-count into a spurious stop. The count is read
  from the ledger, never from an in-memory counter: `pipeline_returned()` in `interactive`,
  `pipeline_loop_counts()` plus each loop-back the script routes in `autoflow`.
- **A plan gap is a loop-back.** A step's `plan-insufficient` on an Architectural spec appends a
  `plan-approval` entry with `outcome: looped-back` and counts toward `review-plan`'s bound
  (`shared/plan-falls-short.md` §On an Architectural spec).
- **An escalation is not a loop-back.** A Bounded design's escalation does not count toward
  `review-plan`'s bound. In `interactive`, `pipeline_route` sends every Bounded `plan-insufficient` to
  `design` without counting repeats, and relies on the grow-form brief to make the spec Architectural;
  `autoflow` exempts one per run, a resume included, and counts the rest toward `review-plan`'s bound.
- **A count that cannot be read is not a count of zero.** The ledger lives in the disposable manifest,
  and no durable probe can rebuild it: git and gh record *that* a review happened, not how many times the
  run looped. A run whose manifest was reconstructed carries an **unknown** cycle count, and unknown
  permits **no** loop-back — the next one halts (in `autoflow`, `launch` gives such a gate the bound).
  Otherwise a manifest lost mid-loop would silently grant two fresh cycles. A fresh run writes its own
  manifest at kickoff and is never reconstructed.

What a halt on the bound leaves behind, before and after `handoff`, is `session.md` §Failure policy.

## Path anchoring — the app root is not always the repo root

`pipeline_triggers` anchors its path patterns at `(?:^|/)`, not `^`. The house-standard it4web
project layout puts the Laravel app under **`code/www/`** (CLAUDE.md §Docker Environment), so a diff
names `code/www/app/Livewire/UserForm.php`, not `app/Livewire/UserForm.php`.

The failure mode is the dangerous direction: a gate that silently does not run looks identical to a
gate that ran and found nothing. `TriggersTest` pins both the nested-path cases and the
segment-boundary cases (`docs/bootstrap/Livewire.md` must not fire) so the anchor cannot regress to
`^`.
