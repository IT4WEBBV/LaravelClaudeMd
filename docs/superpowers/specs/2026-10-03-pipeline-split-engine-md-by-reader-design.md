# pipeline: split engine.md by reader, one reference per step — design

**Design size:** Architectural

Issue #128, with its two scope additions of 2026-10-03 (one rule in one place, a decisions file, rule
text in the present tense, and a one-page overview as the first file a reader opens).

## Problem

`skills/pipeline/references/engine.md` is 1,748 lines (139 kB, about 21k words) and serves three
readers at once:

- **the invoking session** (the main session or `orchestrate`): §The loop, §`autoflow`, §The work
  item, §Kickoff, §After the merge, §The CI gate, §Failure policy, §Navigation;
- **a step agent**: the rules of its own step, spread over §Design size, §What design proves,
  §Mechanical checks, §Suite reuse, §Closing links, §The proof store, §Resolving a review, §Scoped
  re-review, §Catching up with the base, §Implement;
- **a maintainer**: why a rule exists. Issue numbers, measurements and retired rules sit inside the
  rule text (§What design proves, §The CI gate, §Scoped re-review, §Catching up with the base, the
  retired-rules table in §What a leg brief consists of).

Three more problems sit across the four docs (`SKILL.md`, `engine.md`, `gates.md`, `manifest.md`,
about 28k words together):

- **Rules are restated.** The modes are in `SKILL.md`, `gates.md` §Modes and `engine.md` §The loop;
  the loop bound in `gates.md` §Loop-backs, `engine.md` §Failure policy and `manifest.md`
  §`gate_ledger`; the design size and the tiers in `SKILL.md`, `gates.md`, `engine.md` §Design size,
  §Agents per step and `manifest.md` §Fields; the draft-until-`review-pr` guarantee in `gates.md`
  §Navigation guardrail, `engine.md` §Who takes the PR out of draft and `SKILL.md`. `SKILL.md`
  §`autoflow` repeats engine.md's session procedure (kickoff, launch, detour, finish, ask, CI gate,
  report) step by step.
- **History in rule text.** "Why (#92): before this rule …", "A run in flight when this lands halts
  once", "as it did before this section existed", "Before #146 nothing told a fork from a remark".
- **No map.** Nothing shows the six legs as a state machine with who runs each step, what it writes
  and where a run can stop; a reader reconstructs that from 1,748 lines.

`brief.php` names `engine.md` sections in 28 override lines, and every brief's first paragraph says
the sections are in `engine.md`, so a step agent sent to one section has the whole file in front of it.

## Measurement (issue step 1)

Method: every workflow step agent transcript under
`~/.claude/projects/*/*/subagents/workflows/wf_*/agent-*.jsonl`, the step taken from the `description`
in the `.meta.json` beside it. Per agent: the `Read` calls on `engine.md` plus the `Bash` calls whose
command names `engine.md` with `cat`, `sed`, `head`, `tail`, `awk` or `grep`, and the lines their
results returned; the peak context is the largest `input + cache_read + cache_creation` of one turn.
Runs in this repo are left out of the main table, because there `engine.md` is often the work's subject
rather than a reference. The script was a throwaway (`/tmp/m128/measure.py`) and is reproduced at the
end of this spec.

Runs from 2026-10-01 to 2026-10-03, other repos (Deploy, Asimo, GmTool, viewiemedia, deployclient):

| Step | Agents | Read engine.md | Calls (avg) | Lines (avg) | Lines (median) | Lines (max) | Peak context (avg) |
|---|---|---|---|---|---|---|---|
| `design:spec` | 26 | 21 | 1.96 | 228 | 228 | 714 | 131k |
| `design:plan` | 29 | 13 | 1.03 | 110 | 0 | 367 | 156k |
| `review-plan:review` | 30 | 27 | 2.03 | 191 | 169 | 652 | 142k |
| `review-plan:resolve` | 30 | 30 | 1.60 | 123 | 126 | 280 | 67k |
| `handoff:run` | 30 | 2 | 0.20 | 13 | 0 | 258 | 45k |
| `implement:run` | 32 | 32 | 2.19 | 303 | 304 | 680 | 140k |
| `verify-ui:run` | 16 | 16 | 2.38 | 356 | 333 | 670 | 128k |
| `review-pr:review` | 27 | 27 | 2.48 | 285 | 236 | 641 | 135k |
| `review-pr:resolve` | 26 | 26 | 2.19 | 290 | 339 | 402 | 81k |

All runs in other repos (since the first workflow run) give the same picture: 73–312 lines on average
per step, never the whole file (the largest single agent read 756 lines). At about 80 bytes, roughly 20
tokens, per line, a step reads up to 7k tokens of `engine.md`, at most 7% of its peak context; a
per-step file saves part of that, a few thousand tokens a step at best. A whole `autoflow` run reads about 2,000 lines, some 40k
tokens. The agents navigate by `grep -n '^## '` and then read slices: the fear that an agent sent to
one section reads the whole file is not borne out.

**What the numbers decide.** By the issue's own test the token saving is small, and on tokens alone
steps 2 and 3 would not be worth their cost. The split goes ahead on the scope additions' grounds
instead: one rule in one place, history out of rule text, and a map, which are maintenance and
correctness problems the token count does not measure (see *Assumptions*, A1). The spec makes no
claim that the split lowers a run's cost; the after-run `run_cost_cli.php` figures are not a success
criterion.

## Approaches

1. **The full split (chosen).** Per-step references, one session reference, a maintainer reference,
   shared rule files for rules several steps need, `DECISIONS.md` for history, the overview as
   `SKILL.md`, and `engine.md` deleted. It is the only option that meets both scope additions: the
   overview's "links into the per-step references" presume them, and "a step's reference holds nothing
   addressed to another reader" cannot hold for a 1,748-line file.
2. **Rewrite `engine.md` in place.** Deduplicate, move history to `DECISIONS.md`, present tense, add
   the overview; keep one file. Cheaper and still a big improvement for maintainers, but it leaves every
   step agent in a file addressed to the session and the maintainer, and fails the issue's done-when
   ("no brief names an `engine.md` section").
3. **Close with the numbers.** What the issue's step 1 foresaw for a small reading share. Rejected
   because the scope additions, written after the issue, ask for the restructure on other grounds.

## Design

### The files

```
skills/pipeline/
  SKILL.md                 the overview: the first file every reader opens; no rule text
  DECISIONS.md             history: one entry per issue
  references/
    session.md             the invoking session, both modes
    machinery.md           the maintainer: how the code enforces a run
    proof-store.md         the proof store: pages, statuses, the index, retention
    gates.md               kept: content triggers, verify-ui, the navigation guardrail, loop-backs
    manifest.md            kept: the fields, the ledger, what a leg writes, reconstruction
    steps/
      design.md            design:spec, design:plan, design:run
      review-plan-review.md
      review-plan-resolve.md
      handoff.md
      implement.md
      verify-ui.md
      review-pr-review.md
      finish.md            review-pr:resolve
    shared/
      catch-up.md          design, implement, review-plan:resolve, finish
      reviewing.md         review-plan:review, review-pr:review
      resolving.md         review-plan:resolve, finish
      review-pr.md         review-pr:review, finish
      plan-falls-short.md  every step after design
      suite.md             implement, finish
      checks.md            implement, review-pr:review
      proof-payload.md     verify-ui, finish
      dev-stack.md         design, implement, verify-ui
```

`references/engine.md` is deleted, not left as a stub: a stale reference to it then fails a test
instead of sending a reader to a pointer page.

**The ownership rule.** A rule lives in exactly one file:

- a rule only one step needs lives in that step's file under `steps/`;
- a rule two or more steps need lives in one file under `shared/`, which opens with the steps that read
  it; each of those steps' files links it in a *Read also* line at its top;
- what the invoking session does lives in `session.md`; how the code enforces it lives in
  `machinery.md`; the store's behaviour in `proof-store.md`; triggers, the guardrail and the loop bound
  in `gates.md`; the manifest's shape and `record`'s contract in `manifest.md`;
- every other file that needs the rule points at it (`file.md` §Heading) and does not restate it. A
  command a procedure runs may appear in the procedure that runs it; its meaning is stated once.

A step file addresses only its step's agent (and, in `interactive`, the session acting as that step:
`design`, both resolve steps). It says nothing about what the session, another step or the code does,
beyond the one line a step needs to know where its work goes next.

### `SKILL.md` — the overview

The frontmatter and description stay (the description's trigger words are unchanged). The body:

1. **What the pipeline is**: one paragraph, from today's *Overview*, without its rule text.
2. **Invocation**: the `/pipeline [interactive|autoflow] [medium|light] [base <branch>] <…>` block and
   one line: the invoking session reads `references/session.md` before driving a run.
3. **The state machine**: a Mermaid `stateDiagram-v2` block, readable as text: the six legs in order,
   `verify-ui` conditional on the `ui` trigger, the loop-backs (`review-plan` → `design`, `verify-ui` →
   `implement`, `review-pr` → `implement`), the Bounded escalation and the plan gap back to `design`,
   `done` after `review-pr`, and the gates (`review-plan`, `verify-ui` when triggered, `review-pr`)
   marked.
4. **Per step**: one table, a row per step (`design:spec`, `design:plan`, `review-plan:review`,
   `review-plan:resolve`, `handoff:run`, `implement:run`, `verify-ui:run`, `review-pr:review`,
   `review-pr:resolve`) plus the two session edges (kickoff/launch, finish/CI gate/ready): who runs it
   (invoking session, workflow step agent, or the session inline in `interactive`), what it writes
   (manifest keys, commits, PR, proof page, PR comment) and through which command (`kickoff`, `launch`,
   `brief`, `record`, `suite`, `handoff`, `proof_cli.php write`, `finish`, `ci`, `gh pr ready`), and its
   reference file.
5. **Where a run stops**: a table: kickoff halts (nothing exists), a halt before `handoff` (commits on
   the branch, no push, no PR), a halt after `handoff` (draft PR with the reason in its body, proof
   page Halted), `done` (the PR ready after the CI gate), `ask` (the PR draft until answered); each row
   links the rule that governs it.
6. **The references**: which file each reader opens, as a list of links.
7. **Non-goals**: unchanged; they bound the skill, not a run.

What `SKILL.md` carries today that is rule text moves out: §`autoflow` steps 1–6 to `session.md`, the
work-item, mechanical-checks, visual-proof, cost-per-run and status-line bullets to the files that own
those rules, the "Remove when" paragraph to `machinery.md` and `DECISIONS.md` (#134). The overview's
table cells describe; they do not restate a rule. Target: one page, under 150 lines.

### `references/session.md` — the invoking session

From `engine.md`: §The loop (the mode table, `next` / `returned`, wait for the completion notice),
§`autoflow`'s commands at the two edges and what the session does with each answer (merged with
`SKILL.md` §`autoflow` steps 1–6 into one procedure, not two), §Interactive, §The repo config, §The work
item, §Kickoff (with *A run on a base*), §After the merge, the session's half of §The CI gate (the poll
loop, `ready`/`ask`/`fix`/`halt`, the three rounds' re-arm), the session's half of §Open questions (the
kinds table, `finish`'s `ask`, the session's sequence, follow-ups), §Failure policy, §Navigation, and
the after-run report (`run_cost_cli.php`, `run_audit.php`).

It owns: the modes (moved from `gates.md` §Modes, with the fail-safe "anything that is not `autoflow`
behaves as `interactive`"), the invocation words (`medium`/`light` permit Bounded and name the tier;
`base`), who runs `gh pr ready` and marks the page ready, and the halt duties before and after
`handoff`.

### `references/machinery.md` — the maintainer

How the code enforces a run, with no history: the control rule (§The loop's three bullets), the
workflow script (§`autoflow`'s *The script* and *A step* bullets, the relay check and its "Remove when",
*The check at the next boundary*, *Where a step works*, *What a workflow agent cannot do*), `launch`'s
and `finish`'s checks, §Agents per step (the table, the profile, the loop-back entry, the override; the
*Why* column keeps one line per row), §What a leg brief consists of (without the retired-rules table),
the `handoff` command's order (§Stations, *`handoff` in order*), and the CI gate's verdict table and
the `merge` and `conflict` rounds' mechanics (what `ci.php` computes). The session's half of the CI gate
links here for the verdicts.

### `references/proof-store.md`

§The proof store minus the payload schema and the shot rules: where a page lives and who files it, the
page's layout, the statuses and who writes each, the index and the open index tab, retention, time and
cost, "the store is never load-bearing", "no page opens by itself" and `proof_cli.php open`. Reader:
the maintainer and the owner; the session and the steps link to it.

### `references/steps/*.md`

| File | Holds |
|---|---|
| `design.md` | the design size (Bounded/Architectural, the header, read never stored, the package-repo refusal, the `interactive` ask), `autoflow`'s spec step and plan step and which reruns on a loop-back, what a Bounded design commits (the two templates), the grow form after an escalation, answering a plan gap, what design proves (reading, the two probes, the `Probed:` line, a refuted claim), and "plans and specs before 2026-09-14 are no exemplars" |
| `review-plan-review.md` | `/critique plan` on the spec and the plan; what goes into the review file; read-only |
| `review-plan-resolve.md` | the independent read (`interactive` only); what a resolve step at this gate may edit (spec, plan) |
| `handoff.md` | run the command, what it does and reports, repair nothing, it is not the `handoff` skill |
| `implement.md` | §Implement whole (read, stack, validate names, execute test-first, format once, the `ci` label, the push, CI by mode, leave the PR draft, what the plan does not name, what the step does not do, `/work-on` on a pipeline PR) |
| `verify-ui.md` | invoke `browser-verification`, before/after/defect shots, the base checkout and the switch back, the text-only record comment, loop back on a failed check |
| `review-pr-review.md` | `/critique pr`, the suite line, the scope of a re-review (§Scoped re-review: the base, the target, `reviewed_sha`) |
| `finish.md` | the PR body's `## Open questions`, a loop-back stops there, the suite, the closing links (§Closing links whole), the final proof page, push and leave draft (`autoflow`) or the CI gate and `gh pr ready` (`interactive`) |

### `references/shared/*.md`

| File | Holds |
|---|---|
| `catch-up.md` | §Catching up with the base: which steps merge, the state table, the merge line, conflicts, the suite after a merge, the `## Base merges` record, no rebase and no force-push |
| `reviewing.md` | a review step applies `/critique`'s procedure itself in `autoflow`; the review is prose, stored verbatim; crafted context (no earlier review); a review step that returns `plan-insufficient` writes no review file |
| `resolving.md` | §Resolving a review (act, loop back, never interrupt, log) and the resolve step's half of §Open questions (who writes the kind, the kinds, `blocking` names its options, the actions file) |
| `review-pr.md` | the leg is not the `review-pr` skill; the CI round, the conflict round and the answer round as findings of the review and work of the finish step |
| `plan-falls-short.md` | the escalation check on a Bounded spec (the command, what escalates), `plan-insufficient` on an Architectural spec, the size alone is no gap, a resolve step loops back instead |
| `suite.md` | §Suite reuse |
| `checks.md` | §Mechanical checks (the states, slot expansion, what runs and when, two failure kinds, suppressions, into `review-pr`) |
| `proof-payload.md` | the payload table, the `write` refusals, `clientSummary` and `explainer` rules, before/after/defect shots and their pairing |
| `dev-stack.md` | bring the stack up without asking; a design probe's stack; a stack that will not start |

### `gates.md` and `manifest.md`

Both keep their content, lose their copies of rules owned elsewhere, and point at the owner instead:

- `gates.md` §Modes moves to `session.md`; the "`medium` and `light` are not modes" paragraph becomes
  a pointer to `session.md` and `steps/design.md`. §How a run calls Phase A is removed: the session's
  commands are in `session.md`, the steps' in their briefs and `machinery.md`; the `pipeline_triggers()`
  snippet stays in §Content triggers. §Loop-backs becomes the one home of the bound (2 per gate, counted
  from `looped-back` entries, `unknown` permits none); `session.md` §Failure policy and `manifest.md`
  §`gate_ledger` link it. The navigation guardrail stays the home of "no path to a non-draft PR that has
  not passed `review-plan` and `review-pr`".
- `manifest.md`'s `engine.md` pointers are rewritten to the new files; the bound paragraph in
  §`gate_ledger` becomes a pointer to `gates.md` §Loop-backs, keeping only what the ledger records.

### `DECISIONS.md`

One entry per issue, newest first, `## #NN — <title>`: what was decided, the measurement when there is
one, and what it replaced. History with no issue number gets an entry under the PR that made it, else
under its date. Into it go: every "Why (#NN)" and "Why:" passage, the measurements (#77, #79, #88, #92,
#105, the static-analysis scoping figures, the 70-run retired-rules table), the "before #NN" and "a run
in flight when this lands" passages, `gates.md`'s deleted report-only override and the replaced
content-gate invariant, the path-anchoring history, the relay workaround's background (#134, #150), and
`SKILL.md`'s `auto` versus `autoflow` comparison (#87, PR #50). Rule text keeps at most one line of why
and the issue number, e.g. "One automatic fix round per run (#85)".

**Present tense.** Rule text describes what the pipeline does now. Compatibility rules that still act
stay, worded as behaviour: a manifest with `light: true` and no `tier` reads as `medium`; an
`open-question` action without a kind reads as `blocking`; a run filed before statuses reads as its
`prState` says.

### The briefs (`brief.php`)

- `pipeline_brief_role()`: the paragraph names the references directory instead of `engine.md`:
  *"The references this brief names are in `~/.claude/skills/pipeline/references/`; read your step's,
  `steps/<file>.md`, first."*
- A pointer line in `## Pointers`: `- your step's reference: \`<absolute path>\``, from a new
  `pipeline_step_reference(string $leg, string $step): string` that maps every `<leg>:<step>` of both
  modes (`design:run`, `design:spec` and `design:plan` → `steps/design.md`; `review-pr:resolve` →
  `steps/finish.md`; the others by name).
- Every override that cites `engine.md §X` cites the owning file instead, by its path relative to
  `references/`, with no section: `(shared/catch-up.md)`, `(steps/implement.md)`. That covers every line
  in `pipeline_leg_overrides()`, the plan-gap lines, the CI, conflict and answer round lines, the
  catch-up line, the scoped re-review line, the grow-form lines and the base pointer.
- `ci.php`'s conflict decision and its second-conflict halt reason cite `shared/catch-up.md`. The round
  is counted by the decision's prefix (`Conflict with the base on the PR's head commit`), which does not
  change, so a manifest that holds the old wording still counts as the round spent.

### Every other reference

Rewritten to the owning file and section: the PHP doc comments in `checks/*.php` (about 60),
`workflow/pipeline-autoflow.js`'s comment, `README.md`, `CLAUDE.md` (the catch-up exception),
`skills/orchestrate/SKILL.md` and `references/commands.md`, `skills/browser-verification/SKILL.md`
(the payload fields → `shared/proof-payload.md`), `skills/slots/SKILL.md`, `hooks/git-freshness.sh`'s
comment, and `skills/orchestrate/tests/teardown_test.sh` (its `ENGINE` assertions move to
`session.md`). Specs and plans under `docs/superpowers/` are records and keep their `engine.md`
references.

### Tests

- **`LockStepTest`**: `lockstep_section()` takes a path relative to `references/`. The existing
  lock-steps move with their sections: the agents table (`machinery.md` §Agents per step), the repo
  config (`session.md` §The repo config), §Implement whole (`steps/implement.md`), work-on kept out of
  the step and CI sections (`steps/implement.md`, `steps/handoff.md`, `shared/dev-stack.md`,
  `session.md` §The CI gate), the proof store (`proof-store.md` for statuses and `seen:`;
  `shared/proof-payload.md` for the payload fields, `ProofShotState`, `QuestionKind` and
  `PROOF_SUMMARY_MAX`).
- **Briefs name files, not sections** (replaces "keeps every engine.md section a brief names"): over
  every override line of both modes and the round, catch-up and scope lines, every parenthesised `<path>.md` citation
  names a file that exists under `references/`; no line contains `engine.md` or `§`; every step of
  `pipeline_steps()` in both modes has a `pipeline_step_reference()` file that exists, and its brief
  names that path.
- **Every section reference resolves** (new, `DocLinksTest`): in `skills/pipeline/**/*.md`,
  `skills/pipeline/checks/*.php`, `skills/pipeline/workflow/*.js`, `skills/orchestrate/**/*.md`,
  `skills/browser-verification/SKILL.md`, `skills/slots/SKILL.md`, `README.md` and `CLAUDE.md`, every
  `<pipeline doc>.md §<Heading>` names a `## `/`### ` heading of that file (prefix match, as the current
  test does), and inside `skills/pipeline/**/*.md` a bare `§<Heading>` names a heading of the same file.
  The same scan finds no `engine.md` outside `docs/`.
- **History stays out of rule text** (new): `skills/pipeline/SKILL.md` and `references/**/*.md` contain
  no `Why (#`, no line starting `Why:`, no `before #<digits>` and no `when this lands`.
- **`BriefTest`, `CiTest`, `DispatchCliTest`**: their expected strings follow the new citations.
- `teardown_test.sh` passes with its assertions on `session.md`.

The pipeline suite and `teardown_test.sh` run green; `hooks/tests/` runs for the hook's comment change.

## Done when

- The measurement above is posted on issue #128 as an impersonal comment.
- `skills/pipeline/references/engine.md` no longer exists, and nothing outside `docs/` names it.
- No brief names a section; every brief names its step's reference file.
- Each rule of the four docs lives in one file; `SKILL.md` is the one-page overview with the state
  machine, the per-step table, the stops and the links; `DECISIONS.md` holds the history, one entry
  per issue; rule text is in the present tense.
- `LockStepTest`, `DocLinksTest` and the history guard pass, with the rest of the pipeline suite.
- A step's reference holds nothing addressed to another reader (checked by `review-plan` and
  `review-pr`; not mechanically).

## Assumptions

Questions brainstorming would have asked the owner, and the answer assumed:

- **A1. The measurement shows step agents read little of `engine.md` (up to 7k tokens a step). Does the
  split still go ahead?** Assumed yes: the two scope additions were written on 2026-10-03, after the
  issue's step 1, from a review of the skill that already had the size figures, and they ask for the
  restructure on maintenance grounds (one rule in one place, history out, a map). The token saving is
  stated as small and is no success criterion. If the owner meant step 1 as a hard gate, the run should
  stop at review-plan with Approach 3.
- **A2. Is the overview a new file or `SKILL.md`?** Assumed `SKILL.md`: it is the file the harness
  loads when the skill is invoked, so it is literally the first file a reader opens, and a separate
  overview would leave `SKILL.md` as a second summary to keep in step.
- **A3. Rules that several steps need: copy into each step file, or one shared file?** Assumed one file
  under `shared/` per rule set, linked from each step file, because the first scope addition asks for
  each rule in exactly one file. The cost is more files (9 shared, 8 step files) and a step reading two
  to four files instead of slices of one.
- **A4. Is the maintainer's "how it works" (the script, the boundary check, the agents table) rule
  text or history?** Assumed rule text, in its own `machinery.md`; `DECISIONS.md` holds only why and
  when. The issue's third reader needs both.
- **A5. Keep `engine.md` as a pointer stub?** Assumed no: deleted, so a stale reference fails
  `DocLinksTest` rather than leading a reader to a stub. Old specs and plans under `docs/` keep their
  references as records.
- **A6. Do briefs name sections inside a file?** Assumed no, per the issue ("the brief names the file,
  not a section"); the files are small enough to read whole. Docs may still name sections of each other.
- **A7. `DECISIONS.md` order and key.** Assumed newest first, keyed by issue number, falling back to the
  PR number, then the date, for history no issue carries.
- **A8. Is a mechanical check for "nothing addressed to another reader" wanted?** Assumed no: it is a
  judgment `review-plan` and `review-pr` make; the history guard and the link test are the mechanical
  part.
- **A9. Does the measurement go on the issue?** Assumed yes, as an impersonal comment posted by the
  implement step, since "the measurement is recorded in this issue" is a done-when.

## Appendix: the measurement script

```python
import json, glob, os, re, collections
DOCS = ['engine.md', 'gates.md', 'manifest.md', 'pipeline/SKILL.md']
rows = collections.defaultdict(list)
for meta in glob.glob(os.path.expanduser('~/.claude/projects/*/*/subagents/workflows/wf_*/*.meta.json')):
    label = json.load(open(meta)).get('description', '?')
    jl = meta.replace('.meta.json', '.jsonl')
    if not os.path.exists(jl):
        continue
    uses, per, peak = {}, {d: [0, 0] for d in DOCS}, 0
    for line in open(jl):
        try:
            o = json.loads(line)
        except ValueError:
            continue
        m = o.get('message') or {}
        u = m.get('usage')
        if u:
            peak = max(peak, u.get('input_tokens', 0) + u.get('cache_read_input_tokens', 0) + u.get('cache_creation_input_tokens', 0))
        c = m.get('content')
        if not isinstance(c, list):
            continue
        for b in c:
            if b.get('type') == 'tool_use':
                inp = b.get('input', {})
                s = inp.get('file_path', '') if b['name'] == 'Read' else (inp.get('command', '') if b['name'] == 'Bash' else '')
                for d in DOCS:
                    if d in s and (b['name'] == 'Read' or re.search(r'\b(cat|sed|head|tail|awk|grep|less)\b', s)):
                        uses[b['id']] = d
            elif b.get('type') == 'tool_result' and b.get('tool_use_id') in uses:
                t = b.get('content')
                if isinstance(t, list):
                    t = '\n'.join(x.get('text', '') for x in t if isinstance(x, dict))
                d = uses[b['tool_use_id']]
                per[d][0] += 1
                per[d][1] += str(t).count('\n')
    rows[label].append((per, peak, jl))
# filtered per step: path without 'LaravelClaudeMd', file mtime >= 2026-10-01
```
