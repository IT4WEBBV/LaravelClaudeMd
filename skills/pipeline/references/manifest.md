# Manifest — the pipeline's disposable cursor

The manifest is a **local, gitignored file** at `.claude/pipeline/<branch>.json`. It is a
**cursor, not the source of truth**. Durable truth is the committed spec + plan, the branch,
and the PR (state + comments). The manifest only saves the pipeline from re-probing that
durable state on every invocation — delete it and the run reconstructs (see *Reconstruction*).

Read/written by the Phase A helpers in `../checks/manifest.php`:
`manifest_read`, `manifest_write`, `manifest_validate`, `manifest_infer_cursor`.
(`.claude/*` is already gitignored in this repo, so the file is never committed.)

## Fields

| Field | Req? | Purpose |
|---|---|---|
| `branch` | **required** | run identity (also the manifest filename) |
| `worktree` | **required** | absolute path of the run's worktree — where every leg operates |
| `mode` | **required** | `interactive` or `auto` |
| `cursor` | **required** | current leg + status |
| `pipeline_id` | optional | stable id alongside `branch` |
| `gate_policy` | optional | the resolved per-gate table (see `gates.md`) |
| `artifacts` | optional | pointers: spec path, plan path, PR number |
| `last_sha` | optional | HEAD at the last completed leg |
| `gate_ledger` | optional | which gates were approved / waived, and when |
| `lease` | optional | session id + timestamp (single-driver guard) |

`manifest_validate($data)` returns the list of **missing required keys** — `branch`,
`worktree`, `mode`, `cursor`. An empty list means valid. Keep this table and that function
in lock-step: the four required rows above are exactly the four keys the function checks.

## Two rules that keep the file honest

- **Pointers, never content.** Store the spec *path*, not the spec; the PR *number*, not the
  review. The reviewed diff never carries orchestration bookkeeping.
- **Recomputable fields are derived at leg start, never trusted from the file.** A field that
  git/gh can recompute (the diff's triggers, whether the PR is ready) is recomputed each leg.
  Storing it is a latent drift bug.

## Invariant check — every leg opens with one

Before running a leg, confirm the file still matches reality:

- the recorded artifact (spec/plan) exists at the recorded ref (`last_sha`),
- the PR is in the expected state (draft/ready, exists).

**Mismatch → halt**, do not trust the file. A halt is a human resume point, not a silent retry.

## Reconstruction — a missing manifest is never fatal

A fresh checkout, a `/clear`, or a torn-down-and-recreated worktree can leave no manifest.
Rebuild the cursor by probing **durable state**, then feed the probes to
`manifest_infer_cursor($probes)`, which returns the leg to resume at (or `'done'`):

| Probe | How it is gathered | 
|---|---|
| `spec` | spec file present on the branch (`docs/superpowers/specs/…`) |
| `plan` | plan file present on the branch (`docs/superpowers/plans/…`) |
| `planApproved` | the `gate_ledger` records plan approval — else re-run `review-plan` (a re-review is cheap and stateless) |
| `pr` | `gh pr list --head <branch>` → PR number, else null |
| `implemented` | PR marked ready / implementation commits present |
| `uiNeeded` | `pipeline_triggers(<diff>)['ui']` over `git diff origin/<base>...HEAD` |
| `verifyUi` | a `browser-verification` proof comment is attached to the PR |
| `prReviewed` | the `gate_ledger` records the PR review |

The resume order `manifest_infer_cursor` walks (mirrors `pipeline_legs()` plus `'done'`):

```
design → review-plan → handoff → implement → verify-ui → review-pr → done
```

- no `spec` or no `plan` → `design`
- spec + plan, not approved → `review-plan`
- approved, no `pr` → `handoff`
- pr, not `implemented` → `implement`
- implemented, `uiNeeded` and not `verifyUi` → `verify-ui`
- else not `prReviewed` → `review-pr`
- everything done → `done`

Anything recorded only ephemerally (fine-grained `/critique` dispositions — `/critique` stores
nothing by design) is re-established by re-running that leg. The gitignored file is an
optimisation over this probing, never a prerequisite for it.

Because the whole run stays in **one worktree** (see `engine.md` §worktree), the manifest and
its `lease` stay valid for the entire chain — there is no second worktree on the same branch
for the lease to be blind to.
