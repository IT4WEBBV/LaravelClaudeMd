# Resolving

Read by: `steps/review-plan-resolve.md`, `steps/finish.md`

## Resolving a review — the resolve step acts on it

In `autoflow` a fresh resolve agent acts on each review; in `interactive` the session does, with the
human deciding (`session.md` §Interactive). Both keep the edit/rework boundary below; the rest is `autoflow`'s.

**A review is prose, not a verdict** (`shared/reviewing.md` §A review step). The resolve step reads it
the way a person would and acts on its own judgment. The risk position behind that: the pipeline never
merges, so every output is a PR read before merge and the worst case is a discarded branch, while a
needless interrupt costs the one thing `autoflow` exists to protect.

**What the resolve step does with a review** — and its brief says so:

- **Act on what is worth acting on.** Edits to the spec, the plan or the code, and small code fixes,
  are integrated and committed by the resolve step. Rework — a review saying the work is
  fundamentally wrong — is not an edit; it loops back (next bullet). Record the rest —
  already-mitigated observations, notes for posterity — without an edit. **Change nothing the review
  did not name.**
- **Loop back** where the review says the work is fundamentally wrong: `review-plan` → `design`,
  `verify-ui` → `implement`, `review-pr` → `implement`, within the bound (`gates.md` §Loop-backs).
- **Never interrupt on a finding.** Anything unresolved goes into the PR body as an open question with
  its kind, carried **verbatim** (§Open questions, below). Ambiguity buys a line in the PR, not an interrupt:
  the run is still never interrupted mid-workflow, and a `blocking` question is asked once the workflow
  returns.
- **Log** the actions and the outcome on the open entry (`manifest.md` §`gate_ledger`), projected onto the PR.
  *Overruling a reviewer is fine; overruling one invisibly is what turns a gate into decoration.*

## Open questions — the kind each carries

Every open question carries a kind, and the kind says what it is:

- **`blocking`** — a real fork: the answer changes this PR's code;
- **`follow-up`** — work outside this PR;
- **`remark`** — a note on a choice already made.

When each reaches the owner is the session's (`session.md` §Open questions).

- **Who writes the kind.** Both resolve steps, on every `open-question` action (`manifest.md`
  §`gate_ledger`, `actions[].kind`); `record` refuses an open question without one. Unsure is `blocking`. A `blocking`
  question names its options in `note`, the one the PR built first. An action without a kind reads as
  `blocking`. In the PR body, under `## Open questions`, each open question is one line
  led by its kind (`- **blocking:** …`), or the section says `None.`; on the proof page each is
  `{kind, question}` (`shared/proof-payload.md` §The payload).
- **The actions file.** The resolve step writes what it did with each point to
  `<manifest stem>.actions.json` (`manifest.md` §The run's files): a JSON list of `{claim, disposition, note}`,
  plus `kind` on an `open-question`, and `[]` when it acted on nothing; `record` completes the open entry
  from it. `manifest.md` §`gate_ledger` defines the keys and their values.
