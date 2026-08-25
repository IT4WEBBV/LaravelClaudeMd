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
| `artifacts` | optional | pointers: spec path, plan path, PR number |
| `last_sha` | optional | HEAD at the last completed leg |
| `gate_ledger` | optional | the audit trail — each gate's review, what the engine or the human did about it, and the content-trigger annotations (shape below) |
| `lease` | optional | session id + timestamp (single-driver guard) |

`manifest_validate($data)` returns the list of **missing required keys** — `branch`,
`worktree`, `mode`, `cursor`. An empty list means valid. Keep this table and that function
in lock-step: the four required rows above are exactly the four keys the function checks.

## Two rules that keep the file honest

- **Pointers, never content — with one named exception.** Store the spec *path*, not the spec; the
  PR *number*, not the PR. The reviewed diff never carries orchestration bookkeeping. The exception
  is `gate_ledger[].review`: a review has no durable source (`/critique` stores nothing by design)
  and the plan↔review loop runs entirely **before** `handoff`, so there is no PR body to recover it
  from. Everything else stays a pointer.
- **Recomputable fields are derived at leg start, never trusted from the file.** A field that
  git/gh can recompute (the diff's triggers, whether the PR is ready) is recomputed each leg.
  Storing it is a latent drift bug.

## `gate_ledger` — the audit trail that keeps a gate from being decoration

Under `auto` the engine overrules reviewers routinely (`engine.md` §`auto`). That is fine; doing it
*invisibly* is not. So each pass through a gate appends one entry, and the entry is projected onto
the PR.

```json
{
  "gate": "plan-approval",
  "leg": "review-plan",
  "cycle": 1,
  "at": "2026-07-28T11:04:22Z",
  "review": "Step 4 drops the closing-issue link, so the issue stays open after merge …",
  "annotations": ["migration", "auth"],
  "actions": [
    {
      "claim": "step 4 drops the closing-issue link",
      "disposition": "integrated",
      "note": "restored `Closes #1926`"
    }
  ],
  "outcome": "continued"
}
```

| Key | Values |
|---|---|
| `gate` | `plan-approval` \| `pr-review` \| `verify-ui` |
| `leg` | the leg that produced the entry |
| `cycle` | 1-based — which pass through this gate produced the entry; `"unknown"` after a reconstruction, which permits no further loop-back (§reconstruction) |
| `at` | timestamp; the audit trail's only ordering |
| `review` | the reviewer's text, verbatim — the one named exception to *Pointers, never content* above |
| `annotations` | the content triggers that fired (`package`, `migration`, `auth`) — facts, not findings |
| `actions[].claim` | the point from the review the engine or human acted on |
| `actions[].disposition` | `integrated` (edited and committed) \| `recorded` (logged, no edit) \| `open-question` (carried verbatim into the PR body) |
| `actions[].note` | what was done, or why it was not |
| `outcome` | `continued` \| `looped-back` \| `halted` |

**A `verify-ui` entry is the thin shape**: `gate`, `cycle`, `at`, `outcome`, and nothing else —
no `review`, no `actions`, because nothing reviews it. It exists for two reasons, and both are
load-bearing: it carries the `implement`↔`verify-ui` loop bound, and it is how a *completed*
`verify-ui` reaches `pipeline_can_navigate`'s `$doneLegs` (`gates.md`). Omit it and a triggered
`verify-ui` can never be recorded as run, so every later forward jump is refused.

**The loop bound is read from here, never from memory.** A review may drive a loop-back twice
before the third must halt (`engine.md` §failure policy). Count **this gate's entries whose
`outcome` is `looped-back`** — not its entries in total: a gate's history also holds halts and
human-ordered re-reviews, and counting those turns a single real loop-back into the forbidden
third cycle, halting for no reason. That count is the one place the ledger is *read* rather
than appended to, and it does not violate the recomputable-fields rule above: it is a fact about
history, not a cached derivation of current state.

An `interactive` entry is the same shape with the human in the engine's place: `review` and
`annotations` still recorded, `actions` holding what the human decided, and their decision as the
`outcome`.

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
| `planApproved` | the `gate_ledger` holds a `plan-approval` entry with `outcome: continued` — a human approval, or the engine's own continue under `auto` — else re-run `review-plan` (a re-review is cheap and stateless) |
| `pr` | `gh pr list --head <branch>` → PR number, else null |
| `implemented` | PR marked ready / implementation commits present |
| `uiNeeded` | `pipeline_triggers(<diff>)['ui']` over `git diff origin/<base>...HEAD` |
| `verifyUi` | a `browser-verification` **record comment** is attached to the PR (text-only — the images live in the proof store, `engine.md` §The proof store) |
| `prReviewed` | the `gate_ledger` holds a `pr-review` entry with `outcome: continued` |

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

**One field does not reconstruct, and it fails closed.** The `gate_ledger`'s loop-cycle count has
no durable source — git and gh record *that* a review happened, not how many times the engine
looped back — and the plan↔review loop runs entirely **before** `handoff`, so there is not even a
PR to have projected it onto. A reconstructed run therefore treats the count as **unknown**, not
zero, and an unknown count permits **no** further loop-back: the next one halts (`engine.md`
§failure policy). Record `"cycle": "unknown"` on the entry so the ledger says why. This is the sole exception to *a missing manifest is never fatal* —
and it is the same instinct as the invariant check above: state that cannot be trusted is not
guessed at, it is handed back.

Because the whole run stays in **one worktree** (see `engine.md` §worktree), the manifest and
its `lease` stay valid for the entire chain — there is no second worktree on the same branch
for the lease to be blind to.
