---
name: critique
description: Use when reviewing a design or plan before implementation, or a change before merge — the plan, pr, alternatives, and missing review modes. Triggers on "/critique", "review this plan", "review this PR", "critique the design", "what alternatives are there", "what's missing or untested".
---

# critique

One review skill, four modes, encoding the two review gates otherwise done from
memory: reviewing a design/plan before implementation, and reviewing a change before
merge. Same pipeline, different rubric per mode.

**What this skill is for** — not better reasoning (asking an independent model "what
do you think of this" already works). Its value is four narrower things: the rubric
is **written down** so review quality stops depending on how the prompt was phrased
that day; the review **step exists and can be named**; **target assembly** (the whole
diff incl. uncommitted, the linked plan, the searches a rubric line depends on) is
boring and easy to do incompletely; and `alternatives` gives **divergent generation
on demand**, when nobody is present to ask for options.

What it is emphatically **not** for is shaping the review's prose: the reviewer writes
what the review wants to be, and no stage of this skill reformats it.

## Invocation

```
/critique plan [path]              # a design, or design + implementation plan
/critique pr [number]              # default: the whole current change (see below)
/critique alternatives [path|number]
/critique missing [number|path]    # code targets only
/critique                          # infer the mode, state which and why
/critique pr --verify              # upgrade pass: promote/refute code-defect findings
```

**The default `pr` target is the whole change:** the branch diffed against its
**base** (resolve it — see Stage 0 — do **not** assume `main`) **plus uncommitted
working-tree changes**, so work in progress is reviewable without committing first.
With a number, it is `gh pr diff <n>`.

**Mode inference** for the bare form, in order:
1. an uncommitted or newer-than-HEAD spec in `docs/superpowers/specs/` → `plan`
   (a brainstorm ends with an uncommitted spec, so this precedes rule 2);
2. otherwise any change against the base branch (resolved as in Stage 0), committed
   or not → `pr`;
3. otherwise ask.

**Always announce the chosen mode first.**

## Reviewer contract

- **Crafted context, never session history.** The reviewer receives the target and
  the rubric — not the conversation that produced the work. A reviewer holding the
  author's context inherits the author's blind spots, which is the whole point of
  asking someone else.
- **Read-only on this checkout.** No mutation of the working tree, index, `HEAD`, or
  branch state. Inspect via `git show` / `git diff` / `git log`; if another revision
  must be materialised, `git worktree add` into a temp dir. A reviewer that moves
  `HEAD` in an active slot destroys work in progress.
- **Never posts to GitHub.** No comment, review, reaction, or issue without an
  explicit instruction, and nothing this skill writes ever addresses a person.

## Pipeline

### Stage 0 — deterministic pre-pass (no model tokens)

Assemble the target diff and run the tested PHP checks over it. **Resolve the base
branch first — never assume `main`** (an older repo may be `master`; a stacked branch
diffs against its parent):

```bash
BASE=$(git symbolic-ref --quiet --short refs/remotes/origin/HEAD 2>/dev/null | sed 's@^origin/@@')
BASE=${BASE:-$(gh repo view --json defaultBranchRef -q .defaultBranchRef.name 2>/dev/null)}
BASE=${BASE:-main}   # last-resort default; on a stacked branch, set BASE to the parent

# whole current change = committed diff against base + uncommitted working tree:
{ git diff "origin/$BASE...HEAD"; git diff HEAD; } | php skills/critique/checks/run-checks.php
gh pr diff <n>                                     | php skills/critique/checks/run-checks.php
```

The script prints `{ "exact": [...], "heuristic": [...] }`:

- **`exact`** findings (`blade-php`, `vendor-hack`) are correct by construction — fold
  them straight into the report, no adjudication.
- **`heuristic`** candidates (`null-safe-op`, `null-coalesce`, `each-on-builder`,
  `migration-write`, `changelog-fragment`) are grep-level guesses — pass them **with
  their surrounding context** into the Stage 2 prompt for the reviewer to judge.

State the assembled target out loud: **"N files, M uncommitted."** If PHP is
unavailable the pre-pass degrades to the reviewer; it is belt-and-suspenders, not a
gate.

### Stage 1 — assemble context

- `plan`: the design doc plus its implementation plan if one exists.
- `pr`: the whole change **plus** the linked issue/plan when referenced.
- `alternatives`: the design; on a PR target the linked plan or issue is **required**
  (a diff shows what changed, not why that approach was chosen).
- `missing`: the change plus the searches its rubric lines require.

Abort clearly if the target is empty.

### Stage 2 — review

**One agent.** It receives the assembled target, the mode's rubric from
`references/rubrics.md`, and the heuristic candidates from stage 0. It writes the review.

**No output contract.** There is no table, no severity ranking, no score, no required
section, no cap on how much or how little it says. If the useful thing is one paragraph
about a single flaw, that is the review. **What is worth reporting, and how much of it,
is the reviewer's call** — this skill does not second-guess it.

One courtesy, not a format: cite `file:line` when pointing at something specific, so a
claim can be checked without a search. And end with a plain bottom line — ready, ready
with fixes, or needs rework, and why in a sentence.

Model configurable, **Fable by default**. Reasoning effort is session-level — there is
no per-dispatch override.

### Stage 3 — report in chat

The review, as the reviewer wrote it. Do not reformat it into a table, re-rank it,
summarise it into bullets, or drop parts of it. Relaying it faithfully is the whole job
— a consumer that reshapes the review reintroduces exactly what this skill stopped
doing.

State the mode and the assembled target before it (stage 0), so the reader knows what
was reviewed.

### Stage 4 — triage

Decide what to act on. *How* to evaluate a review — verify before implementing, no
performative agreement, push back with reasoning, stop if something is unclear — is
`superpowers:receiving-code-review`'s job; this skill points at it rather than
restating it. Nothing is filed or posted unless chosen.

### Stage 5 — feed the rubric, on a pattern

**No state between runs.** When the same issue keeps recurring across reviews — within
a session, or against a pasted prior report — surface the pattern and **propose a
deliberate amendment** to `references/rubrics.md`. Amendments
are hand-authored edits requiring approval, never automatic — a reviewer that silently
learns to suppress is a reviewer that quietly stops working. Nothing is written to the
rubric behind your back.

## `--verify`

Dispatches a skeptic over the review's checkable claims — the ones asserting that
something in the code is wrong at a location. It reports which hold up against the code
and which do not, in prose. No effect in `plan` or `missing`, where the claims are about
absence and judgment and have no positive evidence to check.

## Re-review

A fresh run over the whole change; there is no stored delta, because there is no store.
Paste the previous report and each prior finding is labelled *fixed* / *still present* /
*no change needed*; otherwise the run stands alone.

## Modes

Each mode's rubric lives in **`references/rubrics.md`** — read the relevant section
there; it is not duplicated here.

- **`plan`** — falsifiability, unverified assumptions, the project-vs-package call,
  mechanism failure/cost; plan-conformance questions only when a plan is present.
- **`pr`** — the compatibility hazard classes (database / in flight / inside the app /
  outward-facing), each returning a verdict, reported conditionally; package weighting
  for `it4web/*`; the principles pointer.
- **`alternatives`** — N agents, each assigned a different axis of variation; each
  alternative must state the condition under which it wins. A PR target needs the
  linked plan/issue.
- **`missing`** — untested edge cases and unhandled error paths; the third-repetition
  and in-repo-convention lines, which must **cite ≥2 real paths** or go unreported.

## Non-goals

- **Generic bug hunting** — `/code-review` owns it; `/critique pr` says when a change
  warrants `ultra`.
- **Whole-codebase sweeps** — that is `review-round`.
- **Posting to GitHub**, storing findings between runs, or reviewing individual commits
  (the unit is always the whole change).
