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
| `cursor` | **required** | `{leg, status, reason?, retried?}` — the current leg; `status` is `pending` (set by the dispatcher), the status the leg returned, or `done` (set by the dispatcher on a finished run; `next` then answers `done` and dispatches nothing); `reason` only with `halted`; `retried` only after a review step's single retry |
| `pipeline_id` | optional | stable id alongside `branch` |
| `artifacts` | optional | pointers: idea, spec path, plan path, PR number, issue number (`engine.md` §The work item), `proof` — the proof page `verify-ui` wrote |
| `last_sha` | optional | HEAD at the last completed leg |
| `gate_ledger` | optional | the audit trail — each gate's review, what the resolve step or the human did about it, and the content-trigger annotations (shape below) |
| `lease` | optional | session id + timestamp (single-driver guard) |
| `suite` | optional | the last full suite: `{tree, outcome: green\|red, passed, failed, at}` — see *Two rules* for why a recomputable field is stored |
| `decisions` | optional | the settled decisions from the invocation, verbatim, as a list. Every brief carries them (`engine.md` §What a leg brief consists of) |
| `light` | optional | the invocation's `light`; read only while `design` has not run |

`manifest_validate($data)` returns the list of **missing required keys** — `branch`,
`worktree`, `mode`, `cursor`. An empty list means valid. Keep this table and that function
in lock-step: the four required rows above are exactly the four keys the function checks.

## Two rules that keep the file honest

- **Pointers, never content — with one named exception.** Store the spec *path*, not the spec; the
  PR *number*, not the PR. The reviewed diff never carries orchestration bookkeeping. The exception
  is `gate_ledger[].review`: a review has no durable source (`/critique` stores nothing by design)
  and the plan↔review loop runs entirely **before** `handoff`, so there is no PR body to recover it
  from. `decisions` is the second exception, for the same reason: the invocation that carried them
  is gone once the run starts. Everything else stays a pointer.
- **Recomputable fields are derived at leg start, never trusted from the file.** A field that
  git/gh can recompute (the diff's triggers, whether the PR is ready) is recomputed each leg.
  Storing it is a latent drift bug.
- **Named exception: `suite`.** A suite result is recomputable (re-run it), yet it is stored,
  because it cannot go stale silently: it is used only when `pipeline_tree_key()` of the current
  working tree equals the recorded `tree`, and losing it costs one re-run (`engine.md` §Suite reuse).

## `gate_ledger` — the audit trail that keeps a gate from being decoration

Under `auto` the resolve step overrules reviewers routinely (`engine.md` §`auto`). That is fine; doing it
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
| `gate` | `plan-approval` \| `pr-review` \| `verify-ui` \| `design-size` |
| `leg` | the leg that produced the entry |
| `cycle` | 1-based — which pass through this gate produced the entry; `"unknown"` after a reconstruction, which permits no further loop-back (§reconstruction) |
| `at` | timestamp; the audit trail's only ordering |
| `review` | the reviewer's text, verbatim — the one named exception to *Pointers, never content* above |
| `annotations` | the content triggers that fired (`package`, `migration`, `auth`) — facts, not findings |
| `actions[].claim` | the point from the review the resolve step or human acted on |
| `actions[].disposition` | `integrated` (edited and committed) \| `recorded` (logged, no edit) \| `open-question` (carried verbatim into the PR body) |
| `actions[].note` | what was done, or why it was not |
| `issue_links` | **`pr-review` entries only** — the closing-link reconciliation, one entry per related issue: `{"issue": 1926, "outcome": "closes" \| "stays-open" \| "dropped-but-closes"}` (`engine.md` §Closing links). Absent on a run with no linked issue |
| `outcome` | `continued` \| `looped-back` \| `halted` \| `escalated` (only on `design-size`). **Absent on an open entry**: a review step writes the review without an outcome, and only the resolve step sets it |

**A `verify-ui` entry is the thin shape**: `gate`, `cycle`, `at`, `outcome`, and nothing else —
no `review`, no `actions`, because nothing reviews it. It exists for two reasons, and both are
load-bearing: it carries the `implement`↔`verify-ui` loop bound, and it is how a *completed*
`verify-ui` reaches `pipeline_can_navigate`'s `$doneLegs` (`gates.md`). Omit it and a triggered
`verify-ui` can never be recorded as run, so every later forward jump is refused.

**A `design-size` entry** records a Bounded design growing to Architectural
(`engine.md` §Design size): `gate`, `leg`, `at`, `reason` (the string `DesignSize->escalation()`
returned, or the judgement in a sentence) and `outcome: escalated`. It is not a loop-back and never
counts toward a gate's cycle bound. It resets which gates count as run: `pipeline_done_legs()`
ignores every gate pass older than it.

**A plan gap** is a `plan-approval` entry written by a leg after `review-plan` on an Architectural
design (`engine.md` §Design size): `gate`, `leg` (the leg that found the gap), `cycle`, `at`, `reason`
and `outcome: looped-back`, and no `review`. Unlike an escalation it **is** a loop-back and counts
toward `review-plan`'s bound; like one, it resets `pipeline_done_legs()`.

**The loop bound is read from here, never from memory.** A review may drive a loop-back twice
before the third must halt (`engine.md` §failure policy). Count **this gate's entries whose
`outcome` is `looped-back`** — not its entries in total: a gate's history also holds halts and
human-ordered re-reviews, and counting those turns a single real loop-back into the forbidden
third cycle, halting for no reason. That count is the one place the ledger is *read* rather
than appended to, and it does not violate the recomputable-fields rule above: it is a fact about
history, not a cached derivation of current state.

An `interactive` entry is the same shape with the human in the resolve step's place: `review` and
`annotations` still recorded, `actions` holding what the human decided, and their decision as the
`outcome`.

## What a leg writes — checked on every return

A leg writes only its results: `artifacts`, `last_sha`, `suite`, its `gate_ledger` entry, and
`cursor.status` — plus `cursor.reason` when it halts. It never moves `cursor.leg` and never writes a
brief. After every return the dispatcher compares the manifest with its snapshot
(`pipeline_returned()`, `../checks/dispatch.php`) and **halts** when any other key changed, when an
existing ledger entry was rewritten (the resolve step may only complete the open entry), or when the
status does not agree with the ledger.

| `cursor.status` | Meaning |
|---|---|
| `continued` | the step did its work; a review step has appended one open entry |
| `looped-back` | a resolve step or `verify-ui` sends the work back (`gates.md` §Loop-backs); its entry says so |
| `halted` | a hard failure; `cursor.reason` says what |
| `plan-insufficient` | the plan does not cover what the change needs. Bounded: a `design-size` entry with `outcome: escalated` is appended and the design grows. Architectural: a `plan-approval` entry with `outcome: looped-back` is appended and the run loops back to `design` within `review-plan`'s bound (`engine.md` §Design size) |

Keep this section in lock-step with `LegStatus` and `pipeline_leg_writable_keys()`; `LockStepTest`
fails when they drift.

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
| `planApproved` | the `gate_ledger` holds a `plan-approval` entry with `outcome: continued` newer than the latest `design-size` escalation or plan gap — a human approval, or the resolve step's own continue under `auto` — else re-run `review-plan` (a re-review is cheap and stateless) |
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

**The issue pointer is recovered separately, and it does not move the cursor.** It is not a probe
above and `manifest_infer_cursor` never sees it — the resume leg does not depend on it. Recover it
the way `engine.md` §The work item resolves it in the first place: the PR's
`closingIssuesReferences`, else the issue number in the branch name via the repo's `branch.issue`
pattern. Recovering it matters for one leg only: `review-pr` cannot reconcile closing links for an
issue it cannot name, and a reconstructed run that quietly finds none would report "no linked
issue" for work that has one. So a reconstruction that can find no issue says **unknown**, not
none — and `review-pr` then reports the reconciliation as not performed rather than as clean.

**One field does not reconstruct, and it fails closed.** The `gate_ledger`'s loop-cycle count has
no durable source — git and gh record *that* a review happened, not how many times the run
looped back — and the plan↔review loop runs entirely **before** `handoff`, so there is not even a
PR to have projected it onto. A reconstructed run therefore treats the count as **unknown**, not
zero, and an unknown count permits **no** further loop-back: the next one halts (`engine.md`
§failure policy). Record `"cycle": "unknown"` on the entry so the ledger says why. This is the sole exception to *a missing manifest is never fatal* —
and it is the same instinct as the invariant check above: state that cannot be trusted is not
guessed at, it is handed back.

Because the whole run stays in **one worktree** (see `engine.md` §worktree), the manifest and
its `lease` stay valid for the entire chain — there is no second worktree on the same branch
for the lease to be blind to.
