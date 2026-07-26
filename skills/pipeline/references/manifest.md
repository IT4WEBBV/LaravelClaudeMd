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
| `gate_ledger` | optional | the audit trail — each gate's verdict, findings, adjudications and dispositions, plus the content-trigger annotations (shape below) |
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

## `gate_ledger` — the audit trail that keeps a gate from being decoration

Under the `adjudicate` policy the engine overrules reviewers routinely (`engine.md`). That is
fine; doing it *invisibly* is not. So each pass through a gate appends one entry, and the entry is
projected onto the PR.

```json
{
  "gate": "plan-approval",
  "leg": "review-plan",
  "cycle": 1,
  "at": "2026-07-26T11:04:22Z",
  "policy": "adjudicate",
  "verdict": "approve-with-nits",
  "annotations": ["migration", "auth"],
  "findings": [
    {
      "kind": "finding",
      "tier": 2,
      "claim": "step 4 drops the closing-issue link",
      "evidence": "docs/superpowers/plans/2026-07-26-x.md:88",
      "suggested_action": "restore `Closes #1926`",
      "adjudication": "none",
      "adjudication_evidence": null,
      "disposition": "integrated"
    }
  ],
  "outcome": "continued"
}
```

| Key | Values |
|---|---|
| `gate` | `plan-approval` \| `pr-review` |
| `cycle` | 1-based — which pass through this gate produced the entry |
| `policy` | the value resolved at the time — `stop` \| `adjudicate` \| `report` |
| `verdict` | the reviewer's own — `approve` \| `approve-with-nits` \| `rework` |
| `annotations` | the content triggers that fired (`package`, `migration`, `auth`) — facts, not findings |
| `findings[].kind` | `finding`, or `architecture` for a synthesised `architecture_judgment` |
| `findings[].adjudication` | `none` (never adjudicated — every Tier-2) \| `refuted` \| `confirmed` \| `uncertain` |
| `findings[].disposition` | `integrated` (edited and committed) \| `recorded` (logged, no edit) \| `open-question` (carried verbatim into the PR body) \| `escalated` |
| `outcome` | `continued` \| `escalated` \| `looped-back` |

`disposition: escalated` is exactly the set for which
`pipeline_should_escalate($finding, $adjudication)` returns `true`.

**The loop bound is read from here, never from memory.** A confirmed `rework` may loop back twice
before it must escalate (`engine.md` §failure policy); the engine counts this gate's existing
entries to know which cycle it is on. That is the one place the ledger is *read* rather than
appended to, and it does not violate the recomputable-fields rule above: the cycle count is a fact
about history, not a cached derivation of current state.

An entry with `"policy": "stop"` is the `interactive` shape — `annotations` and `verdict` still
recorded, every `findings[].adjudication` is `none`, and the human's decision is the `outcome`.

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
| `planApproved` | the `gate_ledger` holds a `plan-approval` entry with `outcome: continued` — a human approval, or an adjudicated continue — else re-run `review-plan` (a re-review is cheap and stateless) |
| `pr` | `gh pr list --head <branch>` → PR number, else null |
| `implemented` | PR marked ready / implementation commits present |
| `uiNeeded` | `pipeline_triggers(<diff>)['ui']` over `git diff origin/<base>...HEAD` |
| `verifyUi` | a `browser-verification` proof comment is attached to the PR |
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

Because the whole run stays in **one worktree** (see `engine.md` §worktree), the manifest and
its `lease` stay valid for the entire chain — there is no second worktree on the same branch
for the lease to be blind to.
