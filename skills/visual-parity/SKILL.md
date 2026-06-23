---
name: visual-parity
description: Use when rebuilding, migrating, or porting a page that must visually match an existing reference implementation (legacy→rewrite, framework swap, redesign-to-spec) and the goal is pixel / near-pixel parity. Sets up a side-by-side pixel-diff harness and a measure-don't-eyeball comparison loop.
---

# Visual Parity (reference-matched rework)

## Overview

**Core principle: parity is proven by MEASUREMENT and side-by-side pixel diffs — never by eyeballing.**

When there is a reference to match (a legacy app, an old design, a page being ported to a new stack), you **cannot trust your own glance** — not even at a screenshot. You will repeatedly miss real differences the user sees instantly: a bubble overlapping an avatar, wrong z-order, missing connector dots, a peach dome that's 200px too short, spacing that's 76px off, the wrong shade of orange. "It looks the same to me" is not evidence.

Replace looking with two instruments:

1. A **pixel-diff harness** that loads both sides, crops them into content-aligned bands, and renders a `legacy │ rebuild │ diff` report (red = differs).
2. **DOM measurement** of *both* sides (`getBoundingClientRect` + `getComputedStyle` + element screenshots) before and after every change — reproduce the reference's exact numbers.

This is the complement to **browser-verification**: that skill proves *your* work renders; this one proves it **matches a reference**. Use both — measure parity here, then present the proof with browser-verification.

## When to use

- Rewriting a page onto a new stack (Vue→Livewire, AngularJS→React, Blade→Inertia…) where it must look the same.
- Porting across framework/CSS versions (Tailwind 3→4, Bootstrap→Tailwind).
- Implementing a design that already exists somewhere (a staging URL, a Figma export rendered in a browser, the old production site).

If there is **no** reference to match, you don't need this — use browser-verification alone.

## The loop

```dot
digraph parity {
  "Have a reference URL + rebuild URL?" [shape=diamond];
  "Stand up the pixel-diff harness" [shape=box];
  "Run harness → report.html + worklist.*.json" [shape=box];
  "Worklist empty and trivial %?" [shape=diamond];
  "Optionally --serve and annotate" [shape=box];
  "Work worklist top to bottom" [shape=box];
  "Pick region: read kind + box + numbers" [shape=box];
  "unclassified region?" [shape=diamond];
  "Open BOTH in Playwright @ same viewport" [shape=box];
  "Measure reference: geometry + computed styles + EVERY sub-element" [shape=box];
  "Element-screenshot BOTH; compare" [shape=box];
  "Reproduce exact offsets in the rebuild" [shape=box];
  "Reload, re-measure, re-screenshot" [shape=box];
  "Numbers + diff image match?" [shape=diamond];
  "Re-run harness; worklist shrinks" [shape=box];

  "Have a reference URL + rebuild URL?" -> "Stand up the pixel-diff harness" [label="yes"];
  "Stand up the pixel-diff harness" -> "Run harness → report.html + worklist.*.json";
  "Run harness → report.html + worklist.*.json" -> "Worklist empty and trivial %?" [label=""];
  "Worklist empty and trivial %?" -> "done" [label="yes — parity reached"];
  "Worklist empty and trivial %?" -> "Optionally --serve and annotate" [label="no — regions remain"];
  "Optionally --serve and annotate" -> "Work worklist top to bottom";
  "Work worklist top to bottom" -> "Pick region: read kind + box + numbers";
  "Pick region: read kind + box + numbers" -> "unclassified region?" ;
  "unclassified region?" -> "Open BOTH in Playwright @ same viewport" [label="yes — fall back to DOM measurement"];
  "unclassified region?" -> "Reproduce exact offsets in the rebuild" [label="no — kind tells you what to fix"];
  "Open BOTH in Playwright @ same viewport" -> "Measure reference: geometry + computed styles + EVERY sub-element";
  "Measure reference: geometry + computed styles + EVERY sub-element" -> "Element-screenshot BOTH; compare";
  "Element-screenshot BOTH; compare" -> "Reproduce exact offsets in the rebuild";
  "Reproduce exact offsets in the rebuild" -> "Reload, re-measure, re-screenshot";
  "Reload, re-measure, re-screenshot" -> "Numbers + diff image match?";
  "Numbers + diff image match?" -> "Reproduce exact offsets in the rebuild" [label="no — iterate"];
  "Numbers + diff image match?" -> "Re-run harness; worklist shrinks" [label="yes"];
  "Re-run harness; worklist shrinks" -> "Worklist empty and trivial %?";
}
```

**The worklist — not the % — is the gate.** The harness writes `out/worklist.<surface>.<viewport>.json` alongside `report.html`. Each region in the worklist has a bounding box, a kind, pixel count, and status (`open` / `wontfix` / `fixed`). Work the open regions top to bottom; when the worklist is empty, the surface is done.

**Kind vocabulary:** The auto-classifier emits `recolor`, `shift`, `resize`, `missing`, `extra`, `typography`, `overlap`, or `unclassified`. Human annotations (drawn via `--serve`) may additionally use `other` (free-form, the default for drag-added regions) or `ignore`. Marking a region's status `wontfix` — not its kind — is the mechanism for intentional diffs; they are excluded from the adjusted %.

- **`unclassified` regions** are where the harness couldn't match a DOM element — fall back to manual measurement with `getBoundingClientRect`.
- **`wontfix` regions** are intentional differences (a removed feature, a known divergence). Mark them via `--serve`; they are excluded from the **adjusted %** on re-run.
- **Fixed regions** drop out automatically on the next harness run once the diff pixels disappear.

## 1. Stand up the harness

Copy **both** `parity-harness.mjs` **and** `parity-lib.mjs` (next to this skill) into the project (e.g. `tools/visual-parity/`). `parity-harness.mjs` imports `parity-lib.mjs` at runtime — both files must be in the same directory. Then edit the **CONFIG block** at the top of `parity-harness.mjs`: `LEGACY` / `REBUILD` URLs, the `VIEWPORTS`, and `SURFACES` (each page + its sections, cut by heading-text anchors top→bottom). Then:

```bash
cd tools/visual-parity
npm init -y && npm i -D playwright pixelmatch pngjs && npx playwright install chromium
node parity-harness.mjs                # ALL surfaces, ALL viewports → out/report.html + out/worklist.*.json
node parity-harness.mjs home           # one surface, BOTH viewports (no viewport arg!)
node parity-harness.mjs --serve        # serve the report for annotation (default :8088)
open out/report.html
```

**Run without a viewport arg to keep desktop + mobile in one report.** Passing a single viewport (`node parity-harness.mjs home desktop`) overwrites `report.html` with just that viewport — a common footgun. The report is regenerated each run.

**Annotation via `--serve`:** `node parity-harness.mjs --serve` starts an HTTP server (default port 8088) serving `report.html` with interactive overlays. Click a region to edit its kind, note, or status; draw a new rectangle to add a human region. Hit **Save worklist** — your edits POST back to the server and merge into the same `worklist.*.json` files on disk. `wontfix` regions persist across re-runs and are excluded from the **adjusted %**. Use this to mark intentional differences so they don't pollute future worklists.

**CONFIG knobs** (at the top of `parity-harness.mjs`, below the SURFACES block):

| Knob | Default | Effect |
|------|---------|--------|
| `NOISE_MIN_PIXELS` | `12` | Diff blobs smaller than this changed-pixel count are silently dropped. Raise to suppress anti-aliasing noise; lower to surface tiny regressions. |
| `SERVE_PORT` | `8088` | HTTP port for `--serve` annotation mode. Override on the CLI by passing a bare number: `node parity-harness.mjs --serve 9000`. |

How it works (and why): the two sides are different DOM (a rewrite), so it diffs **pixels**, not elements — aligned by **content anchors** (heading text), section by section. It dismisses cookie banners, scrolls to trigger lazy images, masks dynamic regions (iframes/video), and ignores anti-aliasing (`includeAA:false`) so font-hinting differences across stacks don't show as diffs. Each band reports `%diff`, `adj %` (wontfix excluded), open-region count, **and** `legacy Xpx / rebuild Ypx` heights. The worklist JSON captures every located region with kind, box, and status so you work it top to bottom rather than chasing the `%` number.

## 2. Measure — don't eyeball

For the diverging band, open the reference and the rebuild in Playwright at the **same** viewport and read real numbers. Glancing at the report tells you *that* something differs; measuring tells you *what*.

```javascript
// Element geometry relative to a section's top — run on BOTH sides, compare.
() => {
  const r = el => { const b = el.getBoundingClientRect();
    return { top: Math.round(b.top + scrollY), left: Math.round(b.left),
             w: Math.round(b.width), h: Math.round(b.height) }; };
  const find = re => [...document.querySelectorAll('*')]
    .find(e => re.test(e.textContent) && e.children.length === 0);
  // measure the box, the target element, AND every sub-element:
  // wrappers, badges, dots, ::before/::after, z-order, and getComputedStyle
  // (color, font, padding, border-radius, background-size/position).
  return { /* … */ };
}
```

Rules that catch what eyeballing misses:

- **Account for EVERY sub-element.** The thing you're missing is usually a small one — a connector dot, a badge, a tail, a pseudo-element, a shadow, a z-index. Enumerate the reference's children; don't approximate the shape.
- **Reproduce exact offsets**, then re-measure to confirm (legacy top 156 → rebuild top 156, not "looks about right").
- **Element-screenshot both** (`browser_take_screenshot` with `target`) and look at them next to each other — but only as a check on the numbers, not a substitute.
- **Iterate in place**: edit → reload → re-measure → re-screenshot. Don't batch changes blindly.

## Caveats that bite

- **The % undercounts on background-dominated bands.** A section that's mostly white/peach with small content can read **4%** while looking completely wrong — the changed pixels are a tiny fraction. **Trust the diff IMAGE and the band-height convergence, not the % alone.** A low % is necessary, not sufficient.
- **Anti-aliasing / font hinting** differs across stacks → keep `includeAA:false`, or text edges flood the diff.
- **Dynamic regions** (video thumbnails, carousels, ad slots, randomized content) must be masked/hidden or they diff run-to-run.
- **Dismiss consent/cookie overlays** on both sides, and **scroll to trigger lazy images** before screenshotting.
- A reference's **production overlays** (surveys, chat widgets) may appear — hide them in the page before measuring.

## Red Flags — STOP

- "It looks the same to me" / "looks close enough" — you can't tell by looking; measure.
- "The diff is only 4%, ship it" — open the diff image; the % undercounts background-heavy bands.
- "I'll eyeball the spacing/position" — read the reference's `getBoundingClientRect`.
- Claiming parity without ever measuring the reference's element geometry.
- Reproducing the shape but skipping its sub-elements (dots, badges, pseudo, shadows).
- Running one viewport and calling the report complete.
- Batching many CSS guesses, then looking once at the end.

## Rationalization table

| Excuse | Reality |
|--------|---------|
| "I can see it matches" | You repeatedly can't — that's why this skill exists. Measure both sides. |
| "4% diff is basically identical" | On a white/peach band, 4% can be a totally wrong layout. Check the diff image + heights. |
| "Pixel-perfect is overkill" | The bar is whatever the user set. If they said pixel/near parity, measure to it. |
| "The screenshot looks right" | Screenshots fool you too. The numbers don't. Use the screenshot to confirm the numbers. |
| "Close enough, the dome shape is the same" | If a value is 76px off, it's not close — find the px and fix it. |
| "It's just a decorative dot" | The user will notice the missing dot. Enumerate every sub-element of the reference. |
| "I'll check all viewports at the end" | Run them together now; mobile usually diverges most and silently. |
| "The worklist is empty so it's done" | Empty worklist with a non-trivial pixel% means the noise floor hid regions or the diff is background-only — open the diff image and lower `NOISE_MIN_PIXELS`. |
