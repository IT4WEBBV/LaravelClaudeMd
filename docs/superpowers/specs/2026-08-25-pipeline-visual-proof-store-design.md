# `pipeline` — visual proof store (`_proofs`) — design

**Date:** 2026-08-25
**Status:** draft → user review → implementation plan → PR
**Canonical home:** `IT4WEBBV/LaravelClaudeMd`, `skills/pipeline/` and `skills/browser-verification/` (global skills, symlinked into `~/.claude/skills/`).
**Amends:** `2026-07-24-pipeline-skill-design.md`. This spec replaces the delivery mechanism of the `verify-ui` leg. **The gate model, the leg list, the `ui` trigger and the navigation guardrail are untouched.**
**Surfaced by:** a brainstorm on 2026-08-25, opening with: *"it currently doesn't show me any visual proof of the changes … when I or you using a subagent run pipeline auto I don't really get to see visuals of the end result."*

## Summary

`verify-ui` promises proof **attached to the PR**. It cannot deliver that promise, and never could:
GitHub has no public API for putting an image into an issue or PR comment. In practice the proof
either does not exist (a text-only comment making a visual claim) or it lives in the brainstorming
**visual companion** — a session-scoped localhost server that dies when the session does. Under
`auto`, dispatched into a subagent, nobody ever sees it.

This spec replaces that delivery mechanism with a **durable local proof store** at
`~/GitProjects/_proofs/`: one self-contained HTML page per UI-touching run, holding *Problem →
Solution → Visual result → Checks → Open questions*, plus a global index across every repo.

Three properties define it, and each rules something out:

- **Read-only.** It is a record, not a review tool. No annotation, no feedback capture.
- **Local and single-user.** No hosting, no sharing, no third-party service.
- **Conditional.** The page exists **only when `pipeline_triggers(...)['ui']` fires** — the same
  mechanical trigger that already decides whether `verify-ui` runs at all. A backend-only run
  behaves exactly as it does today.

## The evidence

All verified on 2026-08-25 on this machine.

| Claim | How it was verified |
|---|---|
| GitHub has no public API for attaching images to a PR/issue comment | `gh` exposes no such command; comment attachments are produced only by drag-drop in the web UI |
| `verify-ui` promises image proof on the PR | `skills/pipeline/references/engine.md:87` — "**attaches annotated proof to the PR**" |
| the manifest defines the gate in those terms | `skills/pipeline/references/manifest.md:120` — `verifyUi` = "a `browser-verification` proof **comment is attached to the PR**" |
| `browser-verification`'s only delivery mechanism is the companion | its SKILL.md marks the visual companion a **REQUIRED SUB-SKILL**: "pasting screenshots inline in the terminal is NOT a substitute" |
| the companion is session-scoped, not an archive | serves only the **newest** file in `screen_dir`; access gated by a per-session `?key=`; **4-hour idle auto-exit**; `--project-dir` writes under `<project>/.superpowers/brainstorm/<pid>-<ts>/` |
| an unstructured local dump degenerates | `skills/visual-parity/out/` — **83 MB, 530 loose PNGs** in one flat namespace; same-named surfaces overwrite each other; no per-run separation, no history, no pruning |
| branch alone is not a unique key across ~20 repos | `~/.claude/pipeline/feature-adopt-phpstan-larastan-pint.json` sits in the **global** dir while `manifest.md:3` documents the manifest as repo-relative `.claude/pipeline/<branch>.json` |
| the `ui` trigger is mechanical and already tested | `skills/pipeline/checks/triggers.php` → `pipeline_triggers()`; covered by `checks/tests/TriggersTest.php` |
| every skill this touches is ours to change | `pipeline`, `browser-verification`, `visual-parity`, `critique` → `LaravelClaudeMd`. `handoff` and `work-on` → `DevOps-Claude-Config` (a colleague's repo) and are **not touched** |
| the host toolchain is present | PHP 8.4.6, Node 24.12.0, `vendor/bin/pest` present in `LaravelClaudeMd` (absent in `DevOps-Claude-Config`) |

### What this evidence does and does not establish

It establishes that **the current promise is structurally unimplementable**, and that an
unstructured local folder measurably degrades — 83 MB of overwriting PNGs is not a prediction, it
is the state on disk today.

It does **not** establish that a local page gets *read*. Nothing here proves a proof page changes
review behaviour; that is the bet this spec makes. *Rollout* names how the bet gets checked and
what would falsify it.

## The problem

Three distinct failures sit behind one complaint, and only one of them is about screenshots.

1. **The promise is false.** Two reference files assert that proof is attached to the PR. No code
   does it, and no code can. A gate whose evidence does not exist is decoration — precisely the
   failure mode `gates.md` was written to prevent everywhere else.
2. **Proof is ephemeral.** What `browser-verification` produces today lives in a server with a
   4-hour idle timeout, keyed to one session, serving one file at a time. The moment the session
   ends the evidence is gone, so nothing can be revisited at review time.
3. **`auto` runs are invisible.** The whole point of `auto` is that the human is not watching. The
   person who most needs the visual — someone who did not see the run happen — is the one
   guaranteed not to get it.

**Why it is worth building.** The vault's fleet-scale note already describes the target loop as
*"Gate 2: PR review — two or three PRs that already survived machine review, with screenshot proof
and green tests attached"*, and names its dominant risk as **review-gate collapse**: plausible,
green, proofed PRs arriving faster than one person can genuinely judge them, across twenty client
codebases that share one recipe book. The proof page is not archival tidiness. It is what makes
that gate survivable.

## What is *not* the problem

- **Not capture.** Playwright MCP plus `browser-verification`'s badge-and-legend format already
  produce good screenshots. Nothing about capture changes.
- **Not the gate.** `verify-ui` is already non-skippable when triggered, and
  `pipeline_can_navigate()` already refuses to step over it. The guardrail works.
- **Not presentation.** The annotated-overlay format is good and is kept. It is the **delivery**
  that fails.
- **Not sharing.** The audience is one person on one machine. Colleague access is explicitly out
  of scope (*Out of scope*), and that is what makes a local folder sufficient.

## Principle

> **Proof outlives the run that made it, and lives outside everything that gets torn down.**

The corollary decides the location on its own. Worktrees are destroyed — slot teardown is
*always* full destructive removal, by house policy — so proof stored inside a worktree dies with
the work it documents. `/tmp`, `$CLAUDE_JOB_DIR`, container filesystems and the companion's
session directory are all wrong for exactly the same reason. A path that survives is not a
convenience here; it is the requirement.

A second principle governs failure:

> **The store is never load-bearing for a gate.**

Failing to **capture** proof halts the run (that rule already exists and is unchanged). Failing to
**file** it does not. A full disk must not kill a feature.

## Design

### 1. Location and key

```
~/GitProjects/_proofs/
├── index.html                        # every run, every repo
├── index.json                        # machine-readable registry, rendering input for index.html
└── <repo>/<branch-slug>/
    ├── index.html                    # the run's page — self-contained, opens over file://
    ├── run.json                      # rendering input for this page
    └── shots/
        ├── 01-orders-index.png
        └── 02-orders-create.png
```

- **`_proofs`**, leading underscore: sorts to the top of `~/GitProjects/`, and reads as
  "not one of the repos". It is deliberately **not** a git repository.
- **Keyed `<repo>/<branch-slug>`.** Branch alone collides across ~20 repos that all grow a
  `feature/fix-typo`; the manifest has that latent bug today. `<repo>` is the GitHub repo name;
  `<branch-slug>` replaces every `/` with `-`, matching the changelog-fragment convention already
  in `CLAUDE.md`.
- **Not keyed by PR number**, because the branch is known at kickoff and the PR only exists after
  `handoff`. The PR number is recorded *inside* and is what the index sorts and joins on.
- **Sibling PNGs, not base64.** `browser-verification` inlines base64 only because the companion
  server will not serve sibling files. We own this directory, so relative `<img src="shots/…">`
  resolves fine over `file://`, the HTML stays small and diffable, and a PNG can be opened
  directly.

### 2. Trigger — the page exists only when `ui` fires

The store is written by the `verify-ui` leg. `verify-ui` runs only when
`pipeline_triggers($diff, $repoPackageName)['ui']` is true — a `*.blade.php`, a Livewire component
under `app/Livewire/` or `app/Http/Livewire/`, `resources/{views,css,js}/…`, a `.vue`, or
`tailwind.config`. When it is false, `pipeline_next_leg()` steps over the leg and **no folder,
no page and no index entry are created**.

This is deliberately not a new rule. It reuses the existing tested trigger, so there is exactly
one definition of "this change is visual" and no second predicate to drift out of sync.

*Consequence, accepted:* a backend-only PR has no page. There is no single place to look for
*every* PR — only for every *visual* PR. That is the narrower scope chosen in the brainstorm.

### 3. Page structure

One page, seven blocks, in this order:

| Block | Source | Notes |
|---|---|---|
| **Header** | manifest + `gh` | repo · PR #N and state · branch · mode · date · leg strip |
| **Problem** | the spec's problem statement / the issue body | prose, 2–4 sentences. The "before" state is **described in words** — no before-screenshots |
| **Solution** | the spec + the diff | what was built, key decisions, files touched, any recorded rejected alternative |
| **Visual result** | `browser-verification` | numbered badge overlays on clean screenshots, plus an always-visible legend; max 5 states |
| **Checks** | `implement`'s mechanical checks | result **qualified by analysed scope** ("0 new findings over `app/`"), and every `@phpstan-ignore` added during the run listed and flagged **not yet judged** |
| **Open questions** | `review-plan` / `review-pr` | carried **verbatim**; nothing resolved silently |
| *Gate ledger* | manifest `gate_ledger` | collapsed `<details>`; which gates ran, outcomes, loop-backs |

The page is an **impersonal record**. It never addresses a person, never uses second person, never
invites a reply — the same rule that governs what the pipeline writes to GitHub.

The "before in words, after in pixels" choice is what keeps this cheap: capturing a true before
would require screenshotting target routes at `implement`-leg start and would make the plan
declare its target routes upfront. Rejected as disproportionate for the value.

### 4. Write points — two, not six

The page is written **twice**:

1. **At `verify-ui`**, after capture: the whole page. Everything above is already known by then —
   `design`, `review-plan`, `handoff` and `implement` have all run, so Problem, Solution and Checks
   are read from the spec and manifest at this moment rather than accumulated leg by leg.
2. **At `review-pr`**, on completion: Open questions and the gate ledger are refreshed and the
   header status is finalised.

Writing once at `review-pr` was rejected: during a long `auto` run the visual would not appear
until the very end, which is most of the original complaint. Writing at every leg was rejected as
machinery with no reader — with the page gated on `ui`, the four legs before `verify-ui` have
nothing visual to show.

### 5. `run.json` is a rendering input, never engine state

`run.json` and `index.json` are inputs to HTML rendering. **The engine never reads them to make a
control-flow decision** — not which leg runs next, not whether a gate has passed, not whether to
loop back. This preserves the skill's non-goal, *"a findings store, or any persistent state not
reconstructable from git + gh"*: deleting the whole of `_proofs/` changes no run's behaviour.

The one component that does read `run.json` is the prune pass (§8), and it reads it only to decide
whether to delete a directory. That is housekeeping over the store's own contents, never a decision
about the run.

```jsonc
{
  "schema": 1,
  "repo": "ViewieMedia",
  "nameWithOwner": "IT4WEBBV/ViewieMedia",   // needed for `gh pr view --repo`
  "branch": "feature/orders-export",
  "branchSlug": "feature-orders-export",
  "pr": 412,
  "prState": "OPEN",
  "mode": "auto",
  "createdAt": "2026-08-25T14:02:11+02:00",
  "updatedAt": "2026-08-25T14:31:47+02:00",
  "shots": [
    { "file": "shots/01-orders-index.png", "route": "/orders", "title": "…", "note": "…" }
  ]
}
```

### 6. The PR keeps a text-only record — this is load-bearing

`manifest.md:120` defines `verifyUi` as *"a proof comment is attached to the PR"*, and the manifest
is explicitly **reconstructable** from git and `gh`. If the only evidence that `verify-ui` ran were
a local folder, a reconstructed manifest could not tell whether a **non-skippable gate** had been
passed — and `gates.md` guarantees no path to a non-draft PR that has not passed it.

So `verify-ui` still posts a PR comment. What changes is only what it claims:

- **it does** record what was verified — routes, states exercised, outcome, and the count of
  captured shots. This is the durable `gh`-side signal the reconstruction reads.
- **it does not** claim to carry images, because it cannot.

`engine.md:87` and `manifest.md:120` are reworded to match. This is the part of the spec that turns
a false statement into a true one.

### 7. The index

`_proofs/index.html`, regenerated on every write. One row per run: repo · PR # and state · branch ·
date · shot count · headline. Sorted newest first, grouped by repo. It is the **join from a PR back
to its page**, which is what makes the store navigable without the PR body pointing at a local path.

Rows whose run has **no PR number** are flagged **"no PR — prune manually"** (see *Retention*).

### 8. Retention — prune on merged/closed PRs

On each write, walk `_proofs/*/*/run.json`; for every run carrying a `pr`, ask
`gh pr view <n> --repo <nameWithOwner> --json state,mergedAt`. Delete the run directory when the
state is `MERGED` or `CLOSED` **and** `updatedAt` is older than **14 days**. The delay exists so a
PR merged this morning can still be looked at this afternoon.

Two deliberate limits, stated rather than engineered around:

- **A `gh` failure is never fatal.** No network, rate limit, or auth problem skips the prune pass
  and reports it in the index. Pruning is housekeeping; it does not get to break a run.
- **Runs with no PR are never auto-pruned.** `review-plan` bound-exhaustion halts *before*
  `handoff` and explicitly opens no PR, so such runs exist. A per-repo hard cap was offered and
  declined, so rather than adding one silently the index **flags** them. The failure mode is
  visible accumulation, not invisible accumulation.

**Screenshot weight.** Full-page shots at a 1920 viewport run 1–3 MB each; at the existing max of 5
states that is up to 15 MB per run. Each PNG is downscaled on write to a maximum width of 1600 px
with `sips --resampleWidth 1600` (macOS built-in, only when wider). PNG is kept rather than JPEG:
JPEG artefacts on UI text are exactly the kind of difference a proof page must not introduce.

### 9. Code surface

```
skills/pipeline/checks/proof.php            # paths, slugify, run.json read/write, prune eligibility
skills/pipeline/checks/proof_render.php     # run page + index HTML
skills/pipeline/checks/tests/ProofTest.php  # Pest
```

`checks/` is already the pipeline's tested-PHP helper library rather than "the mechanical checks" —
it holds `manifest.php` and `triggers.php`, neither of which is a check. Landing here means the
existing documented command covers the new tests with **no change to the test invocation**:

```
./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests
```

**PHP, not Node** — rejected alternative recorded: Node would allow sharing code with
`visual-parity/parity-harness.mjs`, but the only reusable parts there are its annotation and
feedback-capture layers, which a **read-only** page does not want. What remains is pure file
manipulation, and PHP costs no new toolchain, no `package.json`, and no second test command.

### 10. Failure policy

| Situation | Response |
|---|---|
| Playwright genuinely unavailable | **halt** — unchanged; no visual claim without proof |
| capture fails mid-run | **halt** — unchanged |
| the store cannot be written (disk, permissions) | **log to the manifest and continue.** The run's closing report says the page is missing and why |
| the prune pass fails | skip, report in the index, continue |
| `_proofs/` does not exist | create it |

### 11. What does not change

The gate model; the leg list; `pipeline_can_navigate()`; the `ui` trigger and its tests; who takes
the PR out of draft; `interactive` behaviour at gates; `handoff` and `work-on` (both in a
colleague's repo); the mechanical-checks layer; `visual-parity` and its harness.

The **manifest schema** is unchanged too, with one clarification: `verifyUi` keeps its name, its
type and its role as a gate record. Only its *prose definition* is reworded (§6), because the
current wording describes something that cannot happen.

## Decisions log

| # | Decision | Rejected alternative | Why |
|---|---|---|---|
| 1 | Local folder at `~/GitProjects/_proofs/` | a Claude Artifact per PR (durable URL, phone-readable, zero local state) | declined outright by the owner |
| 2 | `~/GitProjects/_proofs/` | `~/.claude/proofs/`; `~/Documents/ClaudeProofs/` | sits where the repos it describes already live; `~/.claude` is harness-managed and never browsed by hand |
| 3 | Page only when `ui` fires | a page on every run; a page on `ui` **or** halt | strictest reading of "only if applicable"; zero change for backend-only work |
| 4 | Read-only page | port `visual-parity`'s click-to-annotate + "Copy feedback" | real appeal against review-gate collapse, but a much larger build; deferred, not discarded |
| 5 | Prune on merged/closed PRs | per-repo hard cap; never auto-delete | self-maintaining, and matches the existing "tear down a slot once its PR is merged" habit |
| 6 | Before in words, after in pixels | capture a real before at `implement`-leg start | would force the plan to declare target routes and roughly double the image budget |
| 7 | Sibling PNGs | base64 inline | we control serving here; keeps HTML small and PNGs directly openable |
| 8 | Two write points | once at the end; once per leg | end-only recreates the invisibility complaint; per-leg has no reader while gated on `ui` |
| 9 | PHP in `checks/` | Node alongside `parity-harness.mjs` | no new toolchain, no new test command; the reusable Node code is annotation, which is out of scope |
| 10 | **No local path in the PR body** | one line in the verify-ui comment pointing at the page | local paths are noise for colleagues reading the PR; the index is the join. **Easily reversed** — one line in an already-existing comment — and flagged here for the review gate |

## Testing strategy

Deterministic logic goes through Pest, matching the existing `checks/` harness:

- **Path derivation** — repo + branch → directory; slug rules (`/` → `-`, casing, awkward branch
  names); no escape from the root.
- **`run.json`** — round-trip; schema version; second write preserves `createdAt` and advances
  `updatedAt`.
- **Prune eligibility** — a pure predicate over `(state, updatedAt, now)`, tested without touching
  `gh` or the filesystem: `MERGED`/`CLOSED` **and** older than 14 days deletes; `OPEN` never does;
  a missing `pr` never does; a `gh` failure never does.
- **Rendering** — a run with 0 shots, with 5 shots, with open questions, with suppressions; assert
  the page is self-contained and every `<img>` path is relative.
- **Index** — multiple repos; sort order; the "no PR" flag renders.

Not unit-tested, and deliberately so: screenshot capture (Playwright MCP), `sips`, and the live
`gh` call. These get a single manual end-to-end run at rollout.

## Risks accepted

- **The page may not get read.** The bet is unproven. *Rollout* names the check.
- **`_proofs` grows for PR-less runs.** Visible in the index rather than capped. Accepted with
  decision 5.
- **No page for backend-only PRs**, so there is no one habitual place to look. Accepted with
  decision 3.
- **Single machine, no backup.** `_proofs` is not a repo and is not synced. Losing it loses proof
  of *past* runs; it costs nothing for open work, which can simply re-run `verify-ui`.
- **Host PHP dependency.** `checks/` already relies on it; this adds no new class of dependency.

## Out of scope

Sharing with colleagues or any hosted URL. Annotation and feedback capture (decision 4). Any change
to `handoff` or `work-on`. Before-screenshots. Non-UI runs. Replacing the brainstorming visual
companion for its own brainstorming use — only `browser-verification`'s proof path moves.

## Rollout

1. Land `proof.php`, `proof_render.php` and tests; the code is unreachable until step 2.
2. Wire the `verify-ui` leg to write the store, and reword `engine.md:87` and `manifest.md:120` so
   both describe what the leg actually does.
3. Point `browser-verification` at the store for the proof path, keeping the companion for the
   interactive "show me" hand-off.
4. One manual end-to-end run on a real UI change; confirm the page opens over `file://`, the index
   links resolve, and the PR carries the text record.

**How the bet gets checked.** After roughly five UI-touching runs, the question is whether the page
was actually opened at review time. If it was not, the fault is *placement* — nothing points at it
from the PR — and decision 10 is the first thing to reverse.
