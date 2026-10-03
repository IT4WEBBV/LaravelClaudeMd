# A click that seems to do nothing is diagnosed before it is bypassed — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `browser-verification` §When a click does nothing stops calling the cause open and stops sending agents
straight to `Livewire.find`: it states what the 2026-10-03 probe found, has the agent check for a Livewire request
and inspect the clicked element first, and keeps the component bypass as the last step.

**Architecture:** One Markdown section is replaced in place (same heading, so links hold) and one line is appended
to the skill's Red Flags list. No code, no package change.

**Tech Stack:** Markdown (a Claude Code skill file); the Playwright MCP tool names it cites; grep for verification.

**Spec:** `docs/superpowers/specs/2026-10-03-browser-verification-click-diagnosis-design.md`. Read it with this
plan: the plan argues from it, and its `## Assumptions` 7–10 are the answers this plan assumed.

## Global Constraints

- Run every command from the worktree root
  `/Users/jroelofs/GitProjects/LaravelClaudeMd/LaravelClaudeMd/.claude/worktrees/worktree-issue-165-playwright-mcp-clicks-don-t-fire-alpine-livewire`.
  This repo is not a Docker project; there is nothing to install and no test suite for this skill.
- One file changes: `skills/browser-verification/SKILL.md`. Nothing outside §When a click does nothing and the
  Red Flags list moves.
- The heading stays exactly `## When a click does nothing`.
- The four parts come in this order as bold lead-ins, not numbered: **What is known.**, **Did the click reach the
  server?**, **No request: look at the target.**, **Only then drive the component.** (spec *Assumptions* 9).
- The probe facts, verbatim: probed 2026-10-03, TallDataTable 4.4, Livewire 4.2, Playwright MCP 0.0.83. The full
  version list belongs in the PR body, not the skill (spec *Assumptions* 4).
- The tool calls named must exist in the Playwright MCP: `browser_click`, `browser_network_requests` (parameters
  `filter`, a URL regexp, and the required `static`), `browser_network_request` (`index`, `part`),
  `browser_wait_for` (`text`), `browser_evaluate` (`function`, `target`).
- The `Livewire.find(...).call(...)` snippet is kept byte for byte.
- The new Red Flags line, verbatim: `- Bypassing a click with \`Livewire.find\` before checking whether a Livewire request went out`
- No changelog entry: the repo has no `.changelog/` and no `CHANGELOG.md`.
- Commits: no `Co-Authored-By`, no AI attribution; the message ends on `(#165)`. Stage the explicit path.

## File Structure

| File | Responsibility |
|---|---|
| `skills/browser-verification/SKILL.md` (modify) | §When a click does nothing (lines 149–168) replaced; Red Flags list gains one line |
| the draft PR's body (no repo file) | gains a `## Probe record` section (Task 1 Step 6) |

## Review Focus

The ways the new guidance could mislead an agent who follows it, most likely first. Each has its check in Task 1.

1. **The bypass still reads as the first move.** Expected: `browser_network_requests` appears in the section
   before the `window.Livewire.find(` line, and the bypass paragraph requires the proof to say no Livewire request
   went out. Check in Task 1 Step 4 (the ordering `awk`) and Step 4's grep for `no Livewire request`.
2. **The slow click misread as dead.** The snapshot `browser_click` returns can predate the round trip. Expected:
   the section says so and sends the agent to `browser_wait_for` on the result's text. Check in Task 1 Step 4
   (grep `browser_wait_for`).
3. **A tool call the MCP does not accept.** `browser_network_requests` requires `static`; `browser_evaluate`
   reaches an element only through `target`. Expected: the section's calls carry those parameters. Check in Task 1
   Step 4 (grep `static: false` and `target`).
4. **The inspection snippet throws on an element scrolled out of view** (`elementFromPoint` returns `null`
   off-screen). Expected: the function scrolls the element to centre first and reads the top element with `?.`.
   Check by reading the snippet in Task 1 Step 3; nothing runs it here (engine.md §What design proves).
5. **Readers of other sections lose their anchor.** Expected: the heading is unchanged and appears once. Check in
   Task 1 Step 4 (grep `^## When a click does nothing$`).

---

### Task 1: Rewrite §When a click does nothing as a diagnosis

**Files:**
- Modify: `skills/browser-verification/SKILL.md:149-168` (the section) and `:170-181` (Red Flags list)

**Interfaces:**
- Consumes: nothing from another task.
- Produces: the section under its unchanged heading `## When a click does nothing`.

- [ ] **Step 1: Write the checks that pin the new section**

These greps are this task's tests. Run them all as one block:

```bash
f=skills/browser-verification/SKILL.md
echo "still-open: $(grep -c 'still open' $f)"
echo "heading: $(grep -c '^## When a click does nothing$' $f)"
echo "snippet: $(grep -c 'window.Livewire.find(' $f)"
echo "red-flag: $(grep -c '^- Bypassing a click with `Livewire.find` before checking whether a Livewire request went out$' $f)"
awk '/^## When a click does nothing$/{s=1} /^## Red Flags/{s=0} s' $f > /tmp/click-section.md
for t in 'browser_network_requests' 'static: false' 'browser_network_request`' 'browser_wait_for' 'browser_evaluate' 'target' 'elementFromPoint' 'untouched' 'no Livewire request' 'What is known' 'TallDataTable 4.4' 'Playwright MCP 0.0.83'; do
  echo "$t: $(grep -c -- "$t" /tmp/click-section.md)"
done
net=$(grep -n 'browser_network_requests' /tmp/click-section.md | head -1 | cut -d: -f1)
find=$(grep -n 'window.Livewire.find(' /tmp/click-section.md | head -1 | cut -d: -f1)
echo "order: network=${net:-none} find=${find:-none}"
```

- [ ] **Step 2: Run the checks to see them fail**

Run: the Step 1 block.
Expected: `still-open: 1`, `red-flag: 0`, and `0` for every term from `browser_network_requests` through
`Playwright MCP 0.0.83` except `untouched: 1`; `heading: 1`, `snippet: 1`; `order: network=none find=12`.

- [ ] **Step 3: Replace the section and add the Red Flags line**

Replace everything from the line `## When a click does nothing` up to (not including) the blank line before
`## Red Flags — STOP` with exactly this:

````markdown
## When a click does nothing

**What is known.** `browser_click` on a TallDataTable row action
(`<button x-on:click="$wire.call(...)">`) fires its handler, and so does a plain Playwright
`page.click` (probed 2026-10-03 on TallDataTable 4.4, Livewire 4.2, Playwright MCP 0.0.83, #165).
Before TallDataTable 4.2 row actions are `<div>`s, not buttons, and ViewieMedia's fork renders
`<span>`s; that markup is not covered. The dead clicks first reported were on ViewieMedia and on a
Flux modal `wire:click` button; neither is reproduced. A click that seems to do nothing is a
question to answer, not a harness fact to work around.

**Did the click reach the server?** The snapshot `browser_click` returns can be taken before a
Livewire round trip lands, so it is not the verdict. Before clicking, run `browser_network_requests`
with `filter: "livewire"`, `static: false` and note the last number: the list holds every request
since the page loaded, earlier Livewire POSTs included. After the click, run it again: only a POST
numbered higher is the click's. `browser_network_request` with that `index` and
`part: "request-body"` showing the expected method (`"method":"showModal"`) means the handler fired:
`browser_wait_for` the text the result shows (the modal's heading, the new row) and snapshot again.
Reached this section after the click, with no list taken before it? Take one now and click again
when the action is safe to repeat (it opens a modal or a form); when it changes data (`duplicate`,
`delete`), read the database count first, since the first click may have landed late and a second
one would double it.

**No request: look at the target.** Run `browser_evaluate` with the clicked element's ref as
`target`:

```javascript
(element) => {
  element.scrollIntoView({ block: 'center' });
  const rect = element.getBoundingClientRect();
  const onTop = document.elementFromPoint(rect.x + rect.width / 2, rect.y + rect.height / 2);
  return { clicked: element.outerHTML.slice(0, 300), onTop: onTop?.outerHTML.slice(0, 300) };
}
```

Is the handler on the element that was clicked or on a wrapper, is it a `<span>` or `<div>` rather
than a `<button>`, does something else sit on top (a backdrop, a `pointer-events` wrapper)? Then
click an **untouched** action on the same row: if that one is dead too, it is the page or the
harness, not your change.

**Only then drive the component.** When no Livewire request went out after the click:

```javascript
const root = [...document.querySelectorAll('[wire\\:id]')]
  .find(e => (e.getAttribute('wire:snapshot') || '').includes('customer.slide-table'));
const component = window.Livewire.find(root.getAttribute('wire:id'));
await component.call('showModal', 'duplicate', '106');   // what the row action fires
await component.call('duplicate', '106');                 // what the modal's button fires
```

This runs the real component method and database, only the click is skipped. Prove the result
with a before/after database count, not by reading the rendered table, and say in the proof that
the click was bypassed and that no Livewire request went out after it. Custom selects that ignore
scripted clicks get their value through `$wire.set(...)` the same way.
````

Then append one line at the end of the `## Red Flags — STOP` list, after
`- Treating the visual companion as optional "presentation infrastructure"`:

```markdown
- Bypassing a click with `Livewire.find` before checking whether a Livewire request went out
```

- [ ] **Step 4: Run the checks to see them pass**

Run: the Step 1 block, then `git diff --stat`.
Expected: `still-open: 0`, `heading: 1`, `snippet: 1`, `red-flag: 1`; every term from `browser_network_requests`
through `Playwright MCP 0.0.83` at `1` or more; `order:` with `network` a smaller number than `find`. `git diff
--stat` lists only `skills/browser-verification/SKILL.md`. Then read the section once top to bottom in the file:
the snippet between `const root` and `// what the modal's button fires` is unchanged (`git diff` shows none of its
lines as removed).

- [ ] **Step 5: Commit**

```bash
git add skills/browser-verification/SKILL.md
git commit -m "Diagnose a dead click before bypassing it in browser-verification (#165)"
```

- [ ] **Step 6: Record the probe in the PR body**

The draft PR exists (`artifacts.pr`, opened by `handoff`). Append the text under `## PR body` below to its body
under a `## Probe record` heading, the way engine.md §Catching up with the base edits it for `## Base merges`:
read the body into a file, append, write it back, never blanking it, and only when no `## Probe record` heading is
there yet.

```bash
gh pr view <pr> --json body -q .body > /tmp/pr-165-body.md
test -s /tmp/pr-165-body.md && ! grep -q '^## Probe record$' /tmp/pr-165-body.md
printf '\n## Probe record\n\n' >> /tmp/pr-165-body.md
# append the fenced text of `## PR body` below, without its fence lines
gh pr edit <pr> --body-file /tmp/pr-165-body.md
```

Expected: `gh pr view <pr> --json body -q .body` shows the earlier body unchanged, followed by `## Probe record` and
the probe text. No closing keyword is written: `review-pr`'s finish step settles that (engine.md §Closing links).

## PR body

Appended by Task 1 Step 6 under `## Probe record` (spec *Assumptions* 10). It records the probe so the issue's
questions have their answers in one place:

```markdown
`browser-verification` §When a click does nothing no longer calls the cause open or sends agents straight to
`Livewire.find`. It states what was probed, checks for a Livewire request after the click, inspects the clicked
element, and keeps the component bypass as the last step, which the proof must then justify.

Probed 2026-10-03 against a running Deploy slot (`/admin/user-management`, seeded admin), whose TallDataTable row
actions are `<button x-on:click="$wire.call('showModal', 'edit', '1')">` inside `x-data="{}"`:

| Layer | Version |
|---|---|
| it4web/talldatatable | v4.4.0 |
| livewire/livewire | v4.2.4 |
| livewire/flux | v2.13.2 |
| @playwright/mcp | 0.0.83 (Playwright 1.64 alpha) |
| Google Chrome | 154 |

- Plain Playwright (`page.locator('button[title=Edit]').first().click()`): one Livewire POST with
  `"method":"showModal"`, the `User` dialog opened.
- Playwright MCP `browser_click` on the `Edit` button: the returned snapshot already held the `User` dialog.
- So neither the MCP's click nor TallDataTable's row-action markup is the cause on current versions; no package
  markup is changed.

Still open, not reproduced: the Flux modal `wire:click` case (its probe was refused by the permission classifier),
and ViewieMedia's forked table (`it4web/talldatatableviewie`), whose row actions are `<span x-on:click>`, seen under
an earlier Playwright MCP release. TallDataTable before v4.2.0 renders row actions as `<div>`s, which the probe did not
cover either. The new diagnosis steps name either cause the next time it happens.
```
