# Every #number says whether it is an issue or a PR — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The *Name work by what it does* bullet in `CLAUDE.md` makes every `#number` carry its kind ("PR #149",
"issue #153"), with an example that follows the rule and one sentence that covers linked pairs, comments and
other repos.

**Architecture:** One line of prose changes: line 39 of `CLAUDE.md` is replaced, verbatim, by the line the spec
gives under §Design (spec line 42). Nothing else in the repo changes. The check is a byte comparison of the two
lines plus the spec's greps; `CLAUDE.md` has no test harness.

**Tech Stack:** Markdown; `diff`, `grep`, `git` on the host (this repo is not a Docker project).

**Spec:** `docs/superpowers/specs/2026-10-03-claude-md-number-names-its-kind-design.md`. Read it with this plan:
the plan argues from it, and its `## Assumptions` 1–7 settle what is out of scope (skills, provenance
citations, parsed syntax such as `Depends on #N`, the machine-local memory file).

## Global Constraints

- Run every command from the worktree root
  `/Users/jroelofs/GitProjects/LaravelClaudeMd/LaravelClaudeMd/.claude/worktrees/worktree-issue-172-claude-md-always-say-whether-a-number-is-an-issue`.
  Edit the worktree's `CLAUDE.md` (a regular file), never `~/.claude/CLAUDE.md` or the main checkout.
- The new line is exactly line 42 of the spec, byte for byte: straight `"` quotes, backticks around `#153`,
  `Closes #N`, `Depends on #N` and `AskUserQuestion`, no trailing whitespace, one line (no wrapping at 100 columns: spec Assumption 7).
- Only line 39 of `CLAUDE.md` changes. The `Depends on #N` bullet (line 255) and every other line stay as they are.
- No changelog entry: the repo has no `.changelog/` and no `CHANGELOG.md`.
- No visual proof: there is no UI.
- Commits: no `Co-Authored-By`, no AI attribution; the message ends on `(#172)`. Stage explicit paths.
- In this shell `grep` is a function over `ugrep`: a pattern that starts with `-` needs `-e`. The commands below
  use `-F` with patterns that do not start with `-`.

## Review Focus

- **The line gets wrapped or reflowed by the editor.** Expected: `git diff --numstat` shows `1	1	CLAUDE.md`;
  Task 1 Step 4 pins it.
- **Curly quotes or other typographic substitution** in the pasted line. Expected: the byte comparison with spec
  line 42 passes; Task 1 Step 4 pins it (`diff` exits 0).
- **The old example survives beside the new one** (the edit inserted rather than replaced). Expected: the old
  example grep is `0`; Task 1 Step 4 pins it.
- **The machine-read `Depends on #N` syntax is rewritten** as "Depends on issue #N" by an over-eager reading of
  the rule. Expected: the Git Workflow bullet's ``own `Depends on #N` line`` still counts `1`; Task 1 Step 4 pins it.
  (`Depends on #N` alone now counts `2`: the new line names it as syntax that stays bare.)
- **The bullets around line 39 shift**, so "line 39" points at another bullet after the edit. Expected: the new
  bullet sits between *Ask which one when I'm unclear* and *When I ask a question*; Task 1 Step 4 pins it with
  `sed -n 38,40p`.

---

### Task 1: Rewrite the naming bullet in `CLAUDE.md`

**Files:**
- Modify: `CLAUDE.md:39`
- Test: none (prose; checked by `diff` and `grep` against the spec)

**Interfaces:**
- Consumes: spec §Design, the fenced line (spec line 42).
- Produces: nothing other tasks rely on.

- [ ] **Step 1: See the checks fail on today's file**

Run:

```bash
diff <(sed -n 42p docs/superpowers/specs/2026-10-03-claude-md-number-names-its-kind-design.md) <(sed -n 39p CLAUDE.md) >/dev/null; echo "same line: $?"
grep -cF 'CI gate fix (PR #149) and proof back link (issue #153)' CLAUDE.md
grep -cE '\((#[0-9]+)\) and proof back link' CLAUDE.md
```

Expected: `same line: 1`, then `0`, then `1` (the new text is absent, the old example present).

- [ ] **Step 2: Replace line 39**

Replace the whole of line 39 of `CLAUDE.md`, which today reads

```markdown
- **Name work by what it does, not by its number.** Whenever issues, PRs or runs come up for me to choose between or follow, give each a few plain words on what it is about, with the number after it in parentheses: "CI gate fix (#149) and proof back link (#153) now", not "#149 + #153 now". A bare list of numbers gives me nothing to decide on. This holds for `AskUserQuestion` labels and descriptions too.
```

with exactly this line (copy of spec line 42):

```markdown
- **Name work by what it does, not by its number, and say what kind it is.** Whenever issues, PRs or runs come up for me to choose between or follow, give each a few plain words on what it is about, with its kind and number after it in parentheses: "CI gate fix (PR #149) and proof back link (issue #153) now", not "#149 + #153 now" and not "CI gate fix (#149)". Issues and PRs share one number sequence, so a bare `#153` doesn't tell me whether it is still to be built or waiting for review. The kind goes with every number, also when two are linked ("issue #153 is fixed by PR #160") and in PR and issue comments; a number in another repo carries the repo name ("Deploy PR #408"). Syntax a tool parses stays bare: `Closes #N` and `Depends on #N`. A bare list of numbers gives me nothing to decide on. This holds for `AskUserQuestion` labels and descriptions too.
```

Use the Edit tool with the old line as `old_string` (it is unique in the file) and the new line as `new_string`.

- [ ] **Step 3: Confirm the line matches the spec byte for byte**

Run:

```bash
diff <(sed -n 42p docs/superpowers/specs/2026-10-03-claude-md-number-names-its-kind-design.md) <(sed -n 39p CLAUDE.md) && echo same
```

Expected: `same`, and no diff output.

- [ ] **Step 4: Run the spec's checks and the Review Focus checks**

Run:

```bash
grep -cF 'CI gate fix (PR #149) and proof back link (issue #153)' CLAUDE.md
grep -cF 'issue #153 is fixed by PR #160' CLAUDE.md
grep -cF 'Deploy PR #408' CLAUDE.md
grep -cE '\((#[0-9]+)\) and proof back link' CLAUDE.md
grep -cF 'Syntax a tool parses stays bare' CLAUDE.md
grep -cF 'own `Depends on #N` line' CLAUDE.md
git diff --numstat -- CLAUDE.md
sed -n 38,40p CLAUDE.md | cut -c1-60
```

Expected, in order:

```
1
1
1
0
1
1
1	1	CLAUDE.md
- **Ask which one when I'm unclear.** "This one also has con
- **Name work by what it does, not by its number, and say wh
- When I ask a question, I'm genuinely curious and want your
```

- [ ] **Step 5: Commit**

```bash
git add CLAUDE.md
git commit -m "docs(claude-md): every #number in the naming rule says issue or PR (#172)"
git diff --stat origin/main...HEAD -- CLAUDE.md
```

Expected: the last command prints ` CLAUDE.md | 2 +-` and ` 1 file changed, 1 insertion(+), 1 deletion(-)`.
