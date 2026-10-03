# A click that seems to do nothing is diagnosed before it is bypassed — design

**Design size:** Architectural

**Date:** 2026-10-03
**Issue:** IT4WEBBV/LaravelClaudeMd#165
**Canonical home:** `skills/browser-verification/SKILL.md` §When a click does nothing.
**Written unattended** by the `design` leg of a `/pipeline autoflow` run. The issue body is the approved scope and is
not re-litigated here. Every question the brainstorm would have asked is answered in *Assumptions*, so
`/critique plan` audits exactly those. Two throwaway probes (a standalone Playwright script and the Playwright MCP
itself, against a running Deploy slot) chose the approach; they are recorded under *What the probes showed*.

## Problem

`browser-verification` §When a click does nothing tells an agent that on TALL-stack apps a `browser_click` on a
TallDataTable row action (`x-on:click="$wire.call(...)"`) or a Flux modal button (`wire:click`) "can report success
while the handler never fires", that "the cause is still open (#165)", and then to drive the component through
`Livewire.find(...).call(...)`. The issue asks what the cause is, whether a plain Playwright `page.click` behaves like
the MCP tool, and to fix it in whichever layer is responsible.

The guidance as written is a standing licence to skip the click layer on every TALL page. A broken `x-on:click` or
`wire:click` binding, the very thing a browser check is for, then passes unnoticed, and nothing tells the agent how to
tell a dead click from a slow one.

## Scope (from the issue)

1. Find out whether the cause is the Playwright MCP's click, or our markup (a `@click.stop`/`pointer-events` wrapper,
   a Flux modal backdrop).
2. Find out whether a plain Playwright `page.click` behaves like the MCP tool.
3. A minimal reproduction with one TallDataTable row action.
4. Fix in the responsible layer: TallDataTable/TallUi markup, or the skill's click guidance.

## What the probes showed

Both probes ran on 2026-10-03 against the running `deploy-3` slot (`https://deploy-3.it4web.net/admin/user-management`,
the seeded admin), which renders TallDataTable row actions with exactly the issue's handler:

```html
<div wire:key="id1" x-data="{}"> … <button x-on:click="$wire.call('showModal', 'edit', '1')" type="button" title="Edit">
```

Versions: it4web/talldatatable v4.4.0, livewire/livewire v4.2.4, livewire/flux v2.13.2, @playwright/mcp 0.0.83
(Playwright 1.64 alpha), Google Chrome 154.

- **Plain Playwright fires the handler.** A standalone script (`chromium.launch({channel: 'chrome'})`,
  `page.locator('button[title=Edit]').first().click()`) sent one Livewire POST carrying `"method":"showModal"` and the
  `User` dialog opened.
- **The Playwright MCP fires the handler.** `browser_click` on the snapshot ref of `button "Edit"` returned a snapshot
  that already contained the `dialog` with heading `User` and the `Name*`/`Email*` textboxes.
- **The Flux modal `wire:click` button was not probed.** The MCP click on the open modal's
  `wire:click="generatePassword"` button (a button that writes nothing) was refused by the session's permission
  classifier, and the probe stopped there. This spec claims nothing about that case.

So scope items 1–3 resolve as: on current TallDataTable markup the click layer works under both the MCP and plain
Playwright, so neither the MCP's click nor TallDataTable's row-action markup is the cause, and there is no package
markup to fix. The original sighting (2026-07-30) was on ViewieMedia, which renders its tables through its own fork
`it4web/talldatatableviewie`, whose row actions are `<span title=… x-on:click=…>` rather than buttons
(`vendor/it4web/talldatatableviewie/resources/views/components/cell/actions-cell.blade.php`), under an earlier
Playwright MCP release (the server runs as `@playwright/mcp@latest`). Which of those two made the click dead there is not reproduced: the house rule on package fixes keeps
this run out of ViewieMedia's stack (*Assumptions* 3).

## Approaches

- *(recommended)* **Rewrite the skill section as a diagnosis, with the bypass as its last step.** The probes show the
  click usually works, so the guidance's job is to tell a dead click from a slow or misread one, and to keep the bypass
  for the case where the click provably never reached the server. The section records what was found instead of
  "still open".
- **Add the probe result to the section and keep it otherwise.** Cheaper, but the section would still open with "can
  report success while the handler never fires" and go straight to the bypass, which is the habit the issue warns
  about. Rejected.
- **Change TallDataTable or TallUi markup.** The probe found nothing to fix in TallDataTable v4.4.0: its row actions are
  already buttons (IT4WEBBV/TallDataTable#291 part 1) and fire. The span markup lives in ViewieMedia's fork, outside
  this repo and outside this run (*Assumptions* 3). Rejected.

## Design

One file changes: `skills/browser-verification/SKILL.md`, the section `## When a click does nothing`. Nothing else in
the skill moves. The section keeps its heading, so links to it hold.

The new section, in this order:

1. **What is known.** One short paragraph replacing "The cause is still open (#165)": `browser_click` on a
   TallDataTable row action (`<button x-on:click="$wire.call(...)">`) fires its handler, as plain Playwright does
   (probed 2026-10-03, TallDataTable 4.4, Livewire 4.2, Playwright MCP 0.0.83). Before TallDataTable 4.2 row actions are
   `<div>`s, not buttons, and ViewieMedia's fork renders `<span>`s; that markup is not covered. The dead clicks of
   #165 were seen on ViewieMedia and on a Flux modal button; neither is reproduced. So
   a click that seems to do nothing is first a question, not a harness fact.
2. **Did the click reach the server?** The snapshot `browser_click` returns can be taken before a Livewire round trip
   lands, so it is not the verdict. Run `browser_network_requests` with `filter: "livewire"` (and `static: false`)
   before the click and note the last number, since the list holds every request since the page loaded; after the
   click, only a POST numbered higher is the click's. `browser_network_request` with that number and
   `part: "request-body"` showing the expected `"method":"…"` means the handler fired: `browser_wait_for` the text the
   result shows (the modal's heading, the new row) and snapshot again.
3. **No request: look at the target.** `browser_evaluate` on the clicked element's `outerHTML` and on
   `document.elementFromPoint` at its centre: is the handler on the element that was clicked or on a wrapper, is it a
   `<span>`/`<div>` rather than a `<button>`, does something else sit on top (a backdrop, a `pointer-events` wrapper)?
   Then click an **untouched** action on the same row: if that one is dead too, it is the page or the harness, not the
   change under test (the existing advice, kept).
4. **Only then drive the component.** The existing `Livewire.find(...).call(...)` snippet stays as it is, with its
   rule to prove the result by a before/after database count and to say in the proof that the click was bypassed. The
   proof now also says what step 2 showed (no Livewire request after the click). The `$wire.set(...)` remark for custom
   selects stays.

Wording follows the skill's existing voice: imperative, short, code in fenced blocks. The Red Flags list gains one
line: "Bypassing a click with `Livewire.find` before checking whether a Livewire request went out".

## Testing

There is no code and no test suite for this skill. Verification is by reading and by grep on the edited file:

- `grep -n "still open" skills/browser-verification/SKILL.md` prints nothing.
- `grep -n "browser_network_requests" skills/browser-verification/SKILL.md` finds the step inside
  §When a click does nothing, and it comes before the `Livewire.find` snippet in the file.
- `grep -n "Livewire.find" skills/browser-verification/SKILL.md` still finds the snippet once.
- Every tool named in the section exists in the Playwright MCP tool list this session loads
  (`browser_click`, `browser_network_requests`, `browser_network_request`, `browser_wait_for`, `browser_evaluate`).

No pressure scenario is required: the change narrows when an existing workaround applies and adds a check before it;
it adds no rule an agent would be tempted to argue away. No visual change, so `verify-ui` has nothing to screenshot.

## Done when

- §When a click does nothing reads as the four steps above, with "still open" gone and the probe's finding stated.
- The bypass is the last step and requires that no Livewire request went out after the click.
- The Red Flags list carries the new line.
- The PR body records the probe results (versions, what fired, the unprobed Flux case), so the issue's questions have
  their answers in one place.

## Assumptions

1. *Does a probe on the Deploy slot stand in for the issue's "minimal reproduction in a LaravelTemplate slot"?* Yes.
   `deploy-3` was already running, is an it4web project on TallDataTable v4.4.0, and renders the exact handler the
   issue names (`x-on:click="$wire.call('showModal', …)"` inside `x-data="{}"`). Starting a LaravelTemplate slot would
   have reproduced the same markup at more cost.
2. *Is the Flux modal `wire:click` case answered?* No. Its probe was refused by the permission classifier (the click
   was on a user form in another run's slot). The design does not depend on it: step 2's network check tells a dead
   click from a live one for any element, Flux or not, and the bypass stays available when the check shows no
   request.
3. *Should this run reproduce on ViewieMedia or change its forked table?* No. The global rule "A package fix covers the
   package and the repo that reported it" keeps a run out of other consumers' stacks; ViewieMedia's checkout would need
   its stack started (`restart.sh` reseeds its database). If the span row actions there are the cause, the diagnosis in
   step 3 names it the next time it happens, in ViewieMedia's own work.
4. *Should the skill record versions?* Briefly, in the one "What is known" paragraph, so a later reader can tell
   whether the finding still applies after an upgrade. The full version list goes in the PR body, not the skill.
5. *Does #165 close with this PR?* Yes: scope items 1–3 are answered (the MCP and plain Playwright both fire the
   handler on current markup; no package markup is at fault) and item 4 is the skill fix. The unprobed Flux case and
   the ViewieMedia span case are recorded as open in the skill and the PR body, not as new issues.
6. *Does the Livewire request URL contain `livewire` on Livewire 4?* Yes for the probed app: the probe's request filter
   was `url.includes('livewire') && method === 'POST'` and it caught the `showModal` call. Probed: a Livewire 4.2.4
   action POST has `livewire` in its URL: yes, the `showModal` POST matched (`page.on('request', …)` in the throwaway
   Playwright script).
7. *The Testing check says `Livewire.find` is found once, but the new Red Flags line also names it: which count
   holds?* The snippet's call line, `window.Livewire.find(`, appears exactly once; the bare word may appear in the
   Red Flags line and in prose. The plan's grep counts `window.Livewire.find(`.
8. *Does step 3 give the agent a ready `browser_evaluate` function, and how does it reach the clicked element?*
   Yes: one short function taking `(element)`, passed with the clicked element's snapshot ref as `target` (the
   tool's `target` parameter), which scrolls the element into view and returns its `outerHTML` and the
   `outerHTML` of `document.elementFromPoint` at its centre, cut to 300 characters.
9. *Are the four parts numbered?* No: they are four bold lead-ins in the spec's order (*What is known*, *Did the
   click reach the server?*, *No request: look at the target*, *Only then drive the component*), since the first
   is a finding, not a step.
10. *Who writes the PR body's probe record?* The implement step, as the plan's last step: once the draft PR exists it
    appends the text the plan supplies under `## PR body` beneath a `## Probe record` heading, editing the body the way
    engine.md §Catching up with the base does for `## Base merges`. `handoff` writes only its own body and reads no
    plan section. The record carries no closing keyword: `review-pr`'s finish step settles that (§Closing links).

## What was read

- Issue #165 (body; no comments).
- `skills/browser-verification/SKILL.md` (whole file).
- TallDataTable v4.4.0 as installed in `deploy-3`: `resources/views/components/cell/actions-cell.blade.php`,
  `action-handler/click-action-handler.blade.php`, `cell/dropdown-cell.blade.php`, `dropdown/dropdown.blade.php`,
  `icon/icon.blade.php`, `src/Action/Action.php`; the package's git log for #291 and #311.
- ViewieMedia's `vendor/it4web/talldatatableviewie/resources/views/components/cell/actions-cell.blade.php` and its
  installed versions (talldatatable v4.1.0, Livewire v4.3.3, Flux v2.15.0).
- Pipeline `references/engine.md` §Design size, §What design proves, §Dev-stack readiness.
