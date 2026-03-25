# Browser Verification Skill — Design Spec

## Summary

A discipline-enforcing skill that prevents Claude from claiming visual work is complete without screenshot proof. When Claude finishes work that has a visual component, this skill requires it to navigate to the relevant page(s) in a browser, annotate the changed elements, take a screenshot, and present the proof in the visual companion browser — not just claim "it works."

## Problem

Claude routinely claims browser verification passed without providing evidence. The user cannot see what Claude saw, and has no way to quickly confirm the claim. This erodes trust and forces manual re-verification.

## Dependencies

This skill relies on two existing systems:

- **`superpowers:verification-before-completion`** — An existing skill from the superpowers plugin (`~/.claude/plugins/cache/claude-plugins-official/superpowers/*/skills/verification-before-completion/`). It enforces evidence-before-claims for all work completion. This new skill extends that principle specifically for visual work by requiring screenshot proof.
- **Visual companion server** — An existing browser-based presentation system from the superpowers plugin (`~/.claude/plugins/cache/claude-plugins-official/superpowers/*/skills/brainstorming/scripts/`). It serves HTML files to a local browser via a file-watching HTTP server. Started with `start-server.sh --project-dir <path>`, it watches a directory for HTML files and serves the newest one. This skill uses it to display annotated screenshots and legends.
- **Playwright MCP** — Browser automation via `@playwright/mcp`, already configured in `~/.claude/plugins/`. Provides `browser_navigate`, `browser_evaluate`, `browser_take_screenshot`, and other browser interaction tools.

## Approach

**Standalone custom skill + CLAUDE.md instruction.** The skill lives in `~/.claude/skills/browser-verification/SKILL.md` and is triggered by a single line in CLAUDE.md's validation section. This avoids forking the superpowers `verification-before-completion` skill while integrating naturally into the verification flow.

## Trigger

The skill activates as part of the verification-before-completion flow when the completed work involves any visual component:

- Livewire components
- Blade templates
- CSS / Tailwind changes
- Frontend JavaScript / Alpine.js

It does NOT activate for backend-only changes (migrations, API endpoints, queue jobs, console commands, etc.).

**CLAUDE.md instruction** (added to Validation Checklist section):

> "When verifying completion of work with a visual component (Livewire, Blade, CSS, frontend JS), invoke the browser-verification skill before claiming it works in the browser."

## The Proof Flow

When triggered, Claude follows this sequence:

### 1. Identify Verification Targets

List the visual elements that changed and the page(s) they appear on. Example: "New status badge on order detail page", "Form validation error messages on user create form."

### 2. Navigate to the Page

Use Playwright MCP (`browser_navigate`) to open the relevant URL. Log in if needed — check database seeders for credentials or create an admin account.

### 3. Interact to Reach Target State

Fill forms, click buttons, trigger the scenario that exercises the changed visual elements. Some verifications require multiple states (e.g., form empty state, validation errors, success state).

### 4. Annotate the DOM

Use `browser_evaluate` to inject CSS overlays onto the verification targets:

- Red borders (2-3px solid red) around target elements
- Numbered badges (circled numbers: ①②③) positioned at the top-right of each target
- Optional subtle pulsing animation for emphasis

The annotation script should:
- Accept a list of CSS selectors + label numbers
- Not break the page layout (use `outline` instead of `border` to avoid reflow)
- Use high z-index overlays so they appear on top of everything
- Be removable (for the "show me" flow)

**How Claude determines selectors:** Claude knows what code it just wrote/modified — it uses that knowledge to identify the relevant DOM elements. It may also use `browser_snapshot` to inspect the accessibility tree and find suitable selectors. The selectors are determined per-verification, not pre-configured.

### 5. Take Screenshot

Use `browser_take_screenshot` to capture the annotated page.

### 6. Present Proof in Visual Companion

Start the visual companion server if not already running (reuse if active). Push an HTML page containing:

- The annotated screenshot (full width)
- A numbered legend below the screenshot explaining each callout (e.g., "① Status badge shows 'Active' — was 'Pending' before this change")
- A verdict section: what was verified, what passed

### 7. Report in Terminal

Short one-liner only: `"Browser verification proof available at http://localhost:XXXXX"`

No screenshot in the terminal. No lengthy description. The proof lives in the visual companion.

## Multiple Verification States

When work requires verifying more than one state:

- Each distinct state gets its own annotated screenshot
- The visual companion page shows them as a storyboard (sequential, scrollable)
- Each screenshot has its own legend
- **Scope guard:** Max ~5 screenshots per verification round. If more are needed, that signals the work should have been verified in smaller increments.

Examples of multi-state verification:
- Form: empty state → validation errors → success message
- Toggle: before state → after state
- List: empty state → populated state

## "Show Me" Hand-off

When the user says "show me" or "let me see" after viewing proof:

1. Claude navigates the browser to the exact same state — same page, same interactions — but **without** the annotation overlays, so the user sees the real page
2. Claude posts a short terminal message: what page is open, what credentials were used, what to look at
3. Claude waits — no further action until the user confirms or reports an issue

## Visual Companion Lifecycle

- If the visual companion is already running (e.g., from brainstorming), reuse it
- If not running, start it with `--project-dir` pointing to the current project
- Leave the server running after presenting proof (user may revisit or trigger "show me")
- Don't stop the server — it auto-exits after 30 minutes of inactivity

## Skill File Location

```
~/.claude/skills/
  browser-verification/
    SKILL.md              # Main skill document
  improve-codebase-architecture/
    SKILL.md              # Existing skill
```

## Red Flags — STOP

These are signs Claude is about to violate this skill:

- "It works in the browser" without a screenshot
- "I verified visually" without proof
- "The component renders correctly" without navigating to it
- Skipping browser verification because "the tests pass"
- Claiming the Playwright MCP isn't available or isn't working without trying

## Rationalization Prevention

| Excuse | Reality |
|--------|---------|
| "Tests cover this" | Tests verify logic, not visual rendering. Screenshot it. |
| "It's a small CSS change" | Small changes break layouts. Screenshot it. |
| "Playwright isn't available" | Try first. If genuinely unavailable, tell the user — don't skip. |
| "The page requires auth I can't get" | Check seeders, create an account, ask the user. Don't skip. |
| "Too many states to screenshot" | Pick the most critical states, max 5. Don't skip entirely. |

## Success Criteria

- Claude never claims visual work is complete without presenting screenshot proof
- Proof is always annotated so the user knows what to look at
- Proof is always in the visual companion, not cluttering the terminal
- "Show me" puts the user at the exact right spot in the browser
- The skill integrates naturally without modifying plugin-owned skills
