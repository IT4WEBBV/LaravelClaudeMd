# Visual Parity v2 — Categorized Worklist (design)

**Date:** 2026-06-23
**Status:** Approved design, pre-implementation
**Skill:** `skills/visual-parity/` (`SKILL.md` + `parity-harness.mjs`)

## Summary

The visual-parity skill proves a rebuild matches a reference by **measurement**, not
eyeballing. Today the harness cuts each page into content-anchored bands and emits one
artifact: a `report.html` showing `legacy │ rebuild │ diff` per band, plus a per-band
`%diff` scalar.

That report is an excellent **human** tool and a poor **agent** tool. The whole skill
exists because the agent cannot reliably read a diff image — it misses the connector
dot, the z-order, the 76px shift. So the report optimizes for the eyes the human has,
not the ones the agent has.

v2 adds an **agent-facing artifact** alongside the existing human-facing one: a
structured, located, categorized **worklist** of diff regions, and turns the report
into a **two-way editor** over that worklist. Everything that exists today is kept
intact; v2 is purely additive.

## Motivation

The single `%diff` scalar conflates several independent axes and the skill's own docs
already warn not to trust it ("% undercounts background-heavy bands → trust the diff
image + heights"). Concretely, the metric is `differingPixels / totalPixels` where each
pixel is a **binary** verdict (`pixelmatch` threshold `0.1`, `includeAA:false`):

- **Magnitude discarded** — a slightly-off pixel and a catastrophically-wrong pixel both
  count as exactly `1`.
- **Area-weighted** — a wrong-colored CTA over 4% of a white band scores the same `4%`
  as a faint global tint over the same area. Importance and concentration are invisible.
- **Offset = catastrophe** — `pixelmatch` does no alignment, so a 10px shift makes every
  row differ; a trivial position bug and a full redesign can produce the same number.
- **Kind invisible** — the pixel layer cannot tell *recolor* from *shift* from *resize*
  from *missing*; it only sees "these pixels differ."

The agent needs the same diff re-expressed as facts it *can* act on: **where** each
difference is, **what kind** it is, and the **exact numbers**.

## Goals

- Emit a structured `worklist.json` of located, categorized diff regions per band.
- Classify each region's **kind** (the chosen axis) with exact supporting numbers.
- Make the served report a **two-way** surface: the human marks/annotates regions, and
  those marks merge into the *same* worklist the harness generates.
- Keep the honest pixel `%`, add a located **region count** and a `wontfix`-adjusted `%`.
- Update `SKILL.md` so the loop teaches: run → (optionally) annotate → work the worklist
  → re-run.

## Non-goals

- **No weighted severity score.** We deliberately chose the *kind* axis over the
  *severity* axis. The metric refinement is limited to "how many open regions remain,"
  not a perceptual-weight model.
- **No full DOM-to-DOM pairing.** The skill went pixel-first precisely because a rewrite
  has different markup. v2 keeps that and joins on geometry only at points of known
  pixel disagreement (see Component 1).
- **No change to band cutting / anchoring / masking** (`prepare()`, `anchorTops()`,
  cookie dismissal, lazy-image scroll, dynamic-region masking) — untouched.

## Architecture overview

```
              ┌─────────────────────── existing (unchanged) ───────────────────────┐
  reference ─▶│ prepare → anchorTops → fullPagePng → cropBand → diffPngs (pixel %) │
   rebuild ──▶│                                                          │          │
              └──────────────────────────────────────────────────────────┼──────────┘
                                                                          │ diff mask
                                                          ┌───────────────▼────────────────┐
                                                          │ NEW: auto-detection pipeline    │
                                                          │  1. cluster mask → regions      │
                                                          │  2. hit-test regions on BOTH    │
                                                          │  3. classify kind + numbers     │
                                                          └───────────────┬────────────────┘
                                                                          │ source:auto
                                            ┌─────────────────────────────▼─────────────────┐
                                            │ worklist.json  (regions[])                     │
                                            │  source: auto | human   status: open|wontfix|fixed
                                            └───────┬───────────────────────────────┬────────┘
                                                    │ seeds                          │ reads
                                  ┌─────────────────▼──────────────┐        ┌────────▼────────┐
                                  │ NEW: serve mode (report editor) │        │  the agent      │
                                  │  draw/click boxes, set kind,    │  POST  │ works the list, │
                                  │  note, status → POST /worklist ─┼───────▶│ then re-runs    │
                                  └─────────────────────────────────┘        └─────────────────┘
```

## Component 1 — Auto-detection pipeline (the "smarter harness")

Runs per band, after the existing `diffPngs()` produces the diff mask. Three stages:

### 1a. Cluster the diff mask → located regions

`pixelmatch` already writes a diff buffer marking changed pixels. Run **connected-
component / blob clustering** over that mask to group changed pixels into bounding
boxes. Filter boxes below a noise threshold (a few px², tunable) so anti-alias
speckle and 1-px seams don't become regions.

Output per region: `box = [x, y, w, h]` in **band-image coordinates**, plus the changed-
pixel count inside the box (used later for ordering and for the adjusted %).

This stage alone replaces "one 4% scalar" with "N located boxes," which is the single
biggest agent-usability win — concentration becomes visible and addressable.

### 1b. Hit-test each region on both renders

Geometry is the join key — we never pair the full DOM. For each region:

1. Convert the box from band-image coords to **document coords**: `docY = bandTop +
   boxY` (the band was cropped from a full-page screenshot at `deviceScaleFactor:1`, so
   x maps directly and y is offset by the band's top).
2. On each side (legacy, rebuild), scroll the point into view and call
   `document.elementFromPoint(viewportX, viewportY)` at the region's centre (and corners
   as fallback / for multi-element regions).
3. Capture for the hit element on both sides: `getBoundingClientRect` (normalized to
   document coords), tag name, and a focused `getComputedStyle` slice
   (`color`, `background-color`, `background-image`, `background-size/position`,
   `border-radius`, `box-shadow`, `font-family/size/weight`, `z-index`, plus the box).

This yields a **localized candidate pair** even when the surrounding markup is 100%
different, because we only ask "what element is at this pixel" — robust across rewrites.

### 1c. Classify kind from the paired elements

Mechanical rules over the rect + computed-style deltas of the pair:

| Condition (legacy vs rebuild element) | `kind` | `detail` emitted |
|---|---|---|
| both present, box ≈ same, color/bg differs | `recolor` | both color values |
| size ≈ same, origin moved | `shift` | Δx / Δy |
| origin ≈ same, size differs | `resize` | Δw / Δh |
| legacy hit, rebuild hits ancestor/empty | `missing` | what legacy had |
| rebuild hit, legacy hits ancestor/empty | `extra` | what rebuild added |
| differing `z-index` / paint order | `overlap` | best-effort note |
| font family/size/weight differ | `typography` | both font values |
| anything the rules can't confidently label | `unclassified` | located box + raw style-diff dump |

**`unclassified` is mandatory and load-bearing:** the pipeline must never invent a label
it cannot back with numbers. An honest "here's the box and the raw diff, you decide"
degrades gracefully and is still more useful than a scalar. The human review pass
(Component 3) is where `unclassified` and mislabels get corrected.

All regions from this pipeline carry `source: "auto"`, `status: "open"`.

## Component 2 — The shared worklist file

The unification point: machine output and human input write to the **same** file.

`out/worklist.<surface>.<viewport>.json`:

```jsonc
{
  "surface": "home",
  "viewport": "desktop",
  "section": "hero",
  "pixelPct": 4.2,          // unchanged honest scalar
  "regions": [
    {
      "id": "r1",
      "box": [340, 156, 120, 44],   // document coords
      "source": "auto",
      "kind": "recolor",
      "detail": "bg #ff6a00 → #ff8c00",
      "status": "open"              // open | wontfix | fixed
    },
    {
      "id": "r2",
      "box": [12, 400, 8, 8],
      "source": "human",
      "kind": "missing",
      "note": "connector dot you skipped",
      "status": "open"
    },
    {
      "id": "r3",
      "box": [24, 520, 300, 180],
      "source": "human",
      "kind": "ignore",
      "note": "intentional — copy changed",
      "status": "wontfix"
    }
  ]
}
```

- `source`: `auto` (harness) or `human` (annotated).
- `status`: `open` (todo), `wontfix` (intentional/accepted — excluded from adjusted %),
  `fixed` (resolved; useful for diffing runs).
- `kind`: the classification vocabulary above plus `ignore`/`other` for human use.
- The harness **seeds** auto-regions; the serve endpoint **merges** human edits without
  clobbering them (see merge rule in Component 3).

## Component 3 — Serve mode + annotation report

`node parity-harness.mjs --serve` (port configurable). Serves the existing report plus:

- **Auto-regions are drawn as boxes** on each band's diff image — **amber** = "auto draft,
  review me." Human-added boxes are **blue**; `wontfix` boxes are **dashed/grey**.
- The overlay maps *displayed* pixels back to band/document coords, so a box the human
  draws is stored as a real coordinate (not relative to a scaled screenshot).
- **Drag a rectangle** on a panel → new box → editor opens, `source: human`. (Lets the
  human flag something the harness missed, even where there is no red — e.g. a masked
  region.)
- **Click a box** → editor popover: `kind` dropdown, `note` text field, `status` radio
  (open / wontfix / fixed), and **delete** (kills a false-positive auto-box).
- **`wontfix`** greys the box and drops it from the adjusted % (Component 4).
- **Save worklist** (sticky header) → `POST /worklist` → writes `out/worklist.*.json` to
  disk; a toast confirms. No copy-paste.

**Server scope:** a small static file server (serves `out/` and the report) plus one
`POST /worklist` handler that validates and writes JSON. No framework; Node's `http` is
enough. The harness already depends on Node + Playwright, so this adds no new runtime.

**Merge rule on save:** the POST body is the full region set for that
surface/viewport/section as edited in the browser (auto + human, with edits applied).
The server writes it verbatim, so the browser is the source of truth during a session.
On a *fresh harness run*, auto-regions are regenerated; human regions and any
`wontfix`/`fixed` statuses on still-matching boxes should be **preserved** by re-reading
an existing `worklist.json` and re-attaching human/status data to overlapping boxes
(match by IoU over `box`). This keeps the human's "intentional diff" decisions across
re-runs instead of resetting them every time.

## Component 4 — Metric refinement (kept deliberately small)

We chose *kind*, not *severity*, so no weighted score. The report header gains, per band:

- the unchanged **pixel `%`** (honest scalar, still shown),
- **# open regions** (the count the agent actually works through),
- **adjusted %** = pixel % recomputed excluding pixels inside `wontfix` boxes.

That's it — "how many real things are left," not a perceptual model.

## Component 5 — `SKILL.md` prose updates

The "measure don't eyeball" spine is unchanged; v2 just pre-extracts the measurements.
Update the loop and setup sections to:

1. Run the harness → it writes `report.html` **and** `worklist.*.json` (auto-seeded).
2. Optional: `--serve`, annotate in the browser, Save → human edits merge into the
   worklist.
3. **Work the `worklist.json` top to bottom** instead of eyeballing the diff image — each
   region already says where it is, what kind it is, and the numbers.
4. Re-run; `fixed`/`wontfix` regions visually drop out and leave the adjusted % / open
   count.

Add a short note that `unclassified` regions are the ones to measure manually (the
existing DOM-measurement snippet still applies there), and that the worklist — not the
`%` — is now the gate.

## Data flow (one cycle)

1. `node parity-harness.mjs` → bands diffed → auto-detection → `report.html` +
   `worklist.*.json` (all `source:auto, status:open`).
2. `node parity-harness.mjs --serve` → human reviews amber boxes, corrects kinds, adds
   missed boxes, flips intentional diffs to `wontfix`, Saves → merged `worklist.*.json`.
3. Agent reads `worklist.*.json`, works each `open` region using `box` + `kind` +
   `detail`, sets them `fixed` as it goes (or just re-runs).
4. Re-run harness → auto-regions regenerate, human/`wontfix` decisions preserved by IoU
   match → converge until `open` count is 0 (modulo `wontfix`).

## Error handling / edge cases

- **Hit-test misses** (point lands on a transparent overlay, a masked iframe, or a
  scroll-clipped element): fall back to corner samples; if still ambiguous, emit
  `unclassified` rather than guess.
- **Region spans multiple elements:** sample centre + corners; if they disagree, keep the
  region but mark `unclassified` with all hits listed.
- **Coordinate drift** from `deviceScaleFactor`/zoom: pin `deviceScaleFactor:1` (already
  set) and assert the screenshot dimensions match the layout viewport before mapping.
- **Server not running** but `worklist.json` requested by agent: the file from the last
  plain run is still on disk and valid — serve mode is only needed for *editing*.
- **Stale worklist across re-runs:** the IoU preserve-merge (Component 3) prevents human
  decisions from being wiped; non-overlapping old human regions are kept too (the diff
  may have moved, not vanished).
- **Noise threshold too low/high:** expose it in the CONFIG block so it's tunable per
  project, like the viewports and surfaces already are.

## Validation strategy

This is a Node tool (no Laravel/Pest harness here), so validation is by self-test fixtures:

- [ ] A tiny pair of local HTML fixtures with **known, planted** differences — a recolor,
      a 24px shift, a removed dot, a resized box — served from `file://` or a local dir.
- [ ] Assert the auto-pipeline produces one region per planted diff, with the right
      `kind` and numbers within tolerance.
- [ ] Assert background-only changes that should be ignored stay below the noise
      threshold.
- [ ] Assert `--serve` writes a posted worklist to disk and that a subsequent run
      preserves a `wontfix` region by IoU.
- [ ] Manual: run `--serve` against the fixtures, draw a box, set `wontfix`, Save, confirm
      the JSON and the adjusted %.
- [ ] Re-read `SKILL.md` end-to-end: the loop, red-flags, and rationalization table still
      cohere with the worklist being the gate.

## Open implementation details (resolve during planning)

- Clustering algorithm: simple union-find connected components over the diff mask vs. a
  coarse grid-merge. Start with connected components; grid-merge if too many micro-boxes.
- IoU threshold for preserve-merge across runs (start ~0.5).
- Exact computed-style slice to capture (the table in 1c is the starting set).
- Whether to keep one combined `worklist.json` or per-surface/viewport files (lean
  per-surface/viewport to mirror the per-band report and keep POST payloads small).
- Whether the harness stays a single `.mjs` (easy to copy into a project) or splits the
  server/clustering into sibling files. Bias toward **single file** to preserve the
  "copy one file into `tools/visual-parity/`" ergonomics; split only if it gets unwieldy.

## File layout

```
skills/visual-parity/
  SKILL.md            ← updated loop + worklist-is-the-gate prose
  parity-harness.mjs  ← + clustering, hit-test, classify, --serve, worklist I/O
                        (CONFIG block gains: noiseThreshold, servePort)
```

No new files shipped with the skill unless the harness is split during implementation;
the worklist and report remain generated into the project's `out/`.
