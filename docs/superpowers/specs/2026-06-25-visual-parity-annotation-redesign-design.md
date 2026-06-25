# Visual-parity annotation redesign — design

**Date:** 2026-06-25
**Status:** approved design, pending spec sign-off → experiments → port to skill → PR
**Canonical home:** `IT4WEBBV/LaravelClaudeMd`, `skills/visual-parity/` (the PR target).
**Prototyped in:** `BreinStraat2` `tools/visual-parity/` (real surfaces + reports, branch `feature/laravel-template-rewrite`).

## Summary

Rework the `visual-parity` skill so the human's only touchpoint is the **report** — open it, mark up the readable images, paste the feedback back. Everything upstream (config, capture, pixel-diff, categorization) is Claude's. The redesign:

1. Stop copying the engine into projects — run it **from the skill** against a small project-committed **config that Claude owns**.
2. Make the report's third pane a **fix list** (the punch-list of differences) instead of the unreadable red diff; annotate on the **legacy/rebuild** panes.
3. **Claude owns categories** — they are read-only machine labels; the human never picks one.
4. Handback is **"Copy feedback" → Markdown** (works `file://` *and* served), or a screenshot. No server round-trip required.
5. Support **named, multiple reports** in one sweep; **per-surface auth** is just one reason two surfaces land in different reports.

This dissolves the original "Problem 1" (file:// Save hangs on a CORS'd `fetch`) by construction, and generalizes the original "Problem 2" (one combined report per auth) into "named reports grouped by intent."

## Background — current state

The harness is a Node script (`parity-harness.mjs` + dependency-free `parity-lib.mjs`) copied into each project's `tools/visual-parity/`, with a per-project CONFIG/SURFACES block edited in place. It launches Playwright, screenshots both sides full-page, crops content-anchored bands, pixel-diffs each band (`pixelmatch`), clusters red blobs into regions, classifies each region by hit-testing the DOM on both sides (`recolor`/`shift`/`resize`/`missing`/`extra`/`typography`/`overlap`/`unclassified`), and writes `out/report.html` + `out/worklist.<surface>.<viewport>.json`. `--serve` hosts the report so in-browser annotations `POST /worklist` back to disk.

Three problems motivated this work; brainstorming reframed all three:

- **P1 — file:// Save hangs.** Opening `report.html` as a file and clicking Save does `fetch('/worklist')` from origin `null` → blocked → button stuck on "Saving…". → *Dissolved:* handback no longer needs the server.
- **P2 — one combined report needs 3 runs** (guest / maria-incomplete / karel-completed), because a single global `storageState` is used and `report.html` is overwritten each run. → *Generalized:* named reports + per-surface auth.
- **P3 — annotation ergonomics.** The kind dropdown asked the human to classify; the red diff is unreadable; the skill copies code into projects. → *Reworked:* fix-list pane, machine-owned categories, run-from-skill.

Key structural facts that make this low-risk:
- The run loop **already** creates a fresh `newContext` per `(surface, viewport)` and **already** accumulates all surfaces into one `results` array → one `report.html`. Per-surface auth and named reports are therefore small, surgical changes.
- `parity-lib.mjs` is **byte-identical** between skill and test-bed; the harness differs only in the CONFIG/SURFACES block, `NOISE_MIN_PIXELS` (40 vs 12), and one `storageState` line — the embryo of P2.
- `main` of the skill repo has an **uncommitted** harness change (the `prompt()`-chain editor replaced by the inline popover `#editor`). It is folded into this PR.

## Goals

- The human interacts only with the report: read, draw on readable images, write a note, paste/screenshot back.
- Claude owns config, running, and categorization.
- Nothing but a small config (and `out/`) lives in the project; the engine and its deps live once in the skill.
- One sweep can emit several intent-scoped reports without clobbering each other.
- Backward-compatible: existing copied setups and single-report/single-auth usage keep working.

## Non-goals

- Replacing the Node engine (evaluated; kept for determinism, cheap re-runs, free full sweeps).
- Replacing the pixel-diff approach itself (it is the skill's whole point).
- Re-doing the classifier's kind vocabulary (it stays; only *who sets it* changes).

## Design

### 1. Distribution — run from the skill, config is Claude's

- Engine (`parity-harness.mjs`, `parity-lib.mjs`, report template, `node_modules`) lives **only in the skill**; deps install once there.
- A project commits **one** `visual-parity.config.mjs` (authored/maintained by Claude) plus its `out/`. Nothing else copied.
- Invocation:
  ```
  node ~/.claude/skills/visual-parity/parity-harness.mjs --config ./tools/visual-parity/visual-parity.config.mjs
  ```
  Deps resolve from the harness's own location; the config is imported by absolute path.
- **Backward-compatible:** with no `--config`, the harness uses its in-file CONFIG block exactly as today, so existing copied projects are untouched.

Config shape (Claude's artifact, committed for reproducibility):
```js
export default {
  legacy:  'https://act.breinstraat.nl',
  rebuild: 'https://breinstraat2.it4web.net',
  viewports: [
    { name: 'desktop', width: 1920, height: 1080 },
    { name: 'mobile',  width: 390,  height: 844 },
  ],
  noiseMinPixels: 40,
  threshold: 0.1,
  authProfiles: {                 // profile name -> storageState file (relative to config)
    maria: 'auth.maria.json',
    karel: 'auth.karel.json',
  },
  surfaces: [
    { name: 'home',    path: '/',                    waitText: '…', sections: [/* … */] },
    { name: 'landing', path: '/mijnverhaal',          waitText: '…', auth: 'maria', report: 'members', sections: [/* … */] },
    { name: 'edit',    path: '/mijnverhaal/aanpassen', waitText: '…', auth: 'karel', report: 'members', sections: [/* … */] },
  ],
};
```

### 2. Report layout — legacy | rebuild | fix-list

```
home @ desktop · hero                         [machine: 2 · you: 1]
┌ legacy ──────────┐ ┌ rebuild ─────────────┐ ┌ fix list ───────────────────┐
│ readable          │ │ readable image        │ │ • [recolor] bg #ff6a00→#ff8c00│
│ ← draw a box      │ │ machine boxes + your  │ │   "looks too dark"   [ignore] │
│                   │ │ boxes (hover row↔box) │ │ • [shift] Δy +12px            │
│                   │ │                       │ │ • [you] "logo too big"   ✎    │
└───────────────────┘ └───────────────────────┘ └───────────────────────────────┘
```

- **Legacy and rebuild are the annotation surface** — both readable, both drawable.
- **Machine findings render as category-labeled boxes on the readable panes** (mapped out of diff-space using the band's `legacyTop`/`rebuildTop`): `missing` shows on legacy, `extra` on rebuild, the rest on rebuild (and legacy where useful).
- **The fix-list replaces the red diff pane.** It lists every difference *item* for the section — machine-found and human-drawn — and is a preview of what we will fix. Row ↔ box are linked (hover a row highlights its box; drawing a box adds a row).
- The **raw red diff PNG is still generated** (it's how regions are found) and is reachable behind a per-section toggle for pixel-level inspection — never the default view.

### 3. Categories are machine-owned

- The human editor loses the **kind dropdown**. It becomes: a **note** field, an **intentional/ignore** toggle (→ `status: 'wontfix'`, excluded from the adjusted %), and **delete**.
- Region kind is set by the classifier (auto regions) or by Claude from the pasted feedback (human regions). Human-drawn boxes are **classified on the next run** by extending the existing both-sides hit-test to human regions, so a box you drew comes back labeled.
- Region shape is unchanged: `{ id, box, source, kind, detail, note, status }`; `status ∈ {open, wontfix, fixed}`. The human sets only `note` and `status`.

### 4. Handback — "Copy feedback" → Markdown

- A **Copy feedback** button serializes the fix list to Markdown and writes it to the clipboard via `navigator.clipboard.writeText` on the button's user gesture. Fallbacks if the clipboard API is unavailable: select-in-`<textarea>` and/or a **Download .md**. Never blocks, never needs the server.
- Markdown shape (what you see is what Claude gets):
  ```
  ## Visual parity feedback — report: members
  ### landing @ desktop
  - [recolor] content — bg #fff → #f7f7f7 @ (40,10,160,48) — note: "too grey" — open
  - [you] content — @ (120,200,80,30) — note: "logo too big" — open
  ### landing @ mobile
  - …
  ```
  Grouped by `surface @ viewport`; `fixed` items omitted; `wontfix` flagged. Human-added items with no machine kind yet render as `[you]`.
- **Screenshot path** stays first-class: the report is fully labeled, so a screenshot of a section is actionable on its own.
- The server `POST /worklist` path is kept **only when served** (`location.protocol` is http/https) as optional disk persistence; on `file://` it is skipped entirely. The existing serve write/merge/path-clamp is unchanged.

### 5. Named, multiple reports + per-surface auth

- A surface may carry `report: '<name>'` (default = unnamed). A run **groups captured surfaces by report tag** and writes one `report.<name>.html` per tag (untagged → `report.html`, back-compat). No clobber between groups.
- A surface may carry `auth: '<profile>'`; `storageState` is resolved from `authProfiles[profile]`. With no profile, it falls back to the existing single default (`auth.json` if present, else none) → back-compat.
- One sweep with surfaces tagged `public`/`members` and `auth` `maria`/`karel` therefore yields `report.public.html` + `report.members.html`, each captured in the correct state, in a single run.
- `--report <name>` narrows a focused re-run to one group. Worklist filenames stay `worklist.<surface>.<viewport>.json` (surface+viewport already unique; a surface belongs to exactly one report).
- `login-state.mjs` becomes a generic template shipped with the skill: `node login-state.mjs <email> <password> <out.json>` mints one profile's combined (both-sides) storageState.

## Backward compatibility

- No `--config` → in-file CONFIG block (today's behavior).
- No `report` tags → single `report.html`.
- No `auth` profiles → single default `storageState`.
- Region/worklist JSON shape unchanged; existing worklists still load and merge.

## Validation strategy

**Experiments first (prove on the BreinStraat2 test-bed before porting):**
- **E1 — handback.** Open the report `file://` in the Playwright MCP, draw a box, click **Copy feedback**, read the clipboard, assert the Markdown is well-formed and usable. Then `--serve` and repeat; assert the optional POST still merges into `out/worklist.*.json`.
- **E2 — named reports + auth.** One run over a config with `public` + `members` tags and `maria`/`karel` profiles → assert `report.public.html` and `report.members.html` both exist, each contains only its surfaces, and member pages show gated (logged-in) content. (karel: try `karel@test.nl/1234` by the maria convention; if it isn't a completed user, prove the mechanism with guest+maria and leave karel a config entry.)
- **E3 — human box gets categorized.** Seed a worklist with a human-drawn box, re-run, assert it comes back with a machine `kind` (classification extended to human regions).

**Keep the skill's tests green and add to them:**
- `parity-lib.test.mjs` — extend for any new pure helpers (e.g. report grouping, classification-of-human-regions) kept dependency-free in the lib.
- `report.test.mjs` — update for the new three-pane / fix-list structure; **add** a test for the new `feedbackMarkdown()` pure serializer.
- `integration.test.mjs` — keep the serve round-trip + clamp tests; **add** a `file://` handback smoke test (clipboard path) and a config-loading (`--config`) test.
- Extract the Markdown serializer and report-grouping as **pure functions in `parity-lib.mjs`** so they test without npm.

**Definition of done:** experiments green; skill tests green; new tests added; harness code byte-identical between skill and test-bed except the config; SKILL.md updated.

## Rollout / PR plan

1. Prototype + prove (E1–E3) in `BreinStraat2/tools/visual-parity` against a real `visual-parity.config.mjs`.
2. Port proven engine changes into the skill on a **feature branch** of `LaravelClaudeMd` (never `main`), folding in the pending editor-popover change.
3. Run the skill's test suite; add the new tests; update `SKILL.md` (run-from-skill, config, fix-list, copy-feedback, named reports + auth, login-state template).
4. Convert the test-bed to the run-from-skill model (config only; no engine copy).
5. One PR to `IT4WEBBV/LaravelClaudeMd` — feature branch, **no co-author / no AI-attribution** lines; update the changelog if the repo keeps one.

## Risks / open questions

- **Clipboard on `file://`** needs a secure-ish context + user gesture. Chrome generally allows `writeText` from a click on `file://`; E1 confirms, and the `<textarea>`/download fallback covers the rest.
- **Diff-space → image-space mapping** for machine boxes on the readable panes must line up with the band offsets; verify visually during E2.
- **Running from the skill with a remote config** relies on deps resolving from the harness's own `node_modules`; verify under `--config`.
- **karel credentials** for fully exercising the second member state (see E2 fallback).
