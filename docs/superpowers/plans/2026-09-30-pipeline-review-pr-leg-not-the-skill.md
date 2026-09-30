# The review-pr briefs say the leg is not the /review-pr skill Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task by task. Steps use checkbox (`- [ ]`) syntax for tracking. In an `autoflow` implement step, execute inline, task by task, with no subagents.

**Goal:** The brief of both `review-pr` steps, in either mode, opens its overrides with one line saying the leg's name is not a skill to invoke, so a step agent does not run the `review-pr` skill of `DevOps-Claude-Config` (#127).

**Architecture:**
- `skills/pipeline/checks/brief.php`: one local variable `$notTheSkill` in `pipeline_leg_overrides()`, first element of the `review-pr:review` and `review-pr:resolve` entries. Nothing else in the brief changes.
- `skills/pipeline/references/engine.md`: one paragraph in §Who takes the PR out of draft, after the `autoflow` paragraph. No new heading.
- One comment on issue #127 with the transcript count the issue's *Done when* asks for.

**Tech Stack:** PHP 8.4 on the host, Pest 4, Markdown skill references, `gh`.

**Spec:** `docs/superpowers/specs/2026-09-30-pipeline-review-pr-leg-not-the-skill-design.md`

## Global Constraints

- Suite, from the worktree root on the host: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`. `vendor/` is missing in a fresh worktree: run `composer install --no-interaction --quiet` first.
- The line, verbatim (the spec's text): ``The leg's name is not a skill to invoke: do not invoke the `review-pr` skill (`/review-pr`), which posts its own review comment and changes the PR's draft state. This brief is the whole step (engine.md §Who takes the PR out of draft).``
- The line is outside any `$autoflow` branch: both modes get it.
- Unchanged: the leg's name, `pipeline_legs()`, the gate name `pr-review`, the manifest format, `pipeline-autoflow.js`, `pipeline_brief_role()`, `pipeline_brief_overrides()`, `SKILL.md`, `gates.md`, `manifest.md`, `orchestrate`, and the `review-pr` skill (another repository).
- engine.md gets no new `## ` heading: `LockStepTest` resolves the `§` names of the briefs against the existing headings, and `§Who takes the PR out of draft` exists.
- The comment on #127 is an impersonal record (`CLAUDE.md`, *Never address a human*): no name, no greeting, no second person, no question.
- This repo has no `.changelog/` and no `CHANGELOG.md`: no changelog entry. The PR closes #127.
- Sibling runs (#52, #105) may change `brief.php` or engine.md: when the step's brief orders a merge of the base, a conflict in these files is resolved keeping both sides (the variable, the two array elements, the paragraph).

## Review Focus

1. **The catch-up line stays first on `review-pr:resolve`.** When the base moved, `pipeline_brief_overrides()` prepends the catch-up line; the new line must come second, not push the merge down. Task 1's test pins the order with `brief_git_behind()`.
2. **`interactive` gets the line too.** An `interactive` step agent has the same skill linked; the dataset of Task 1's test covers both modes on both steps.
3. **The line is the first of the step's own overrides**, directly followed by the step's existing first line (the `/critique` line on review, `You are the finish step.` on resolve). Task 1's test asserts the two lines adjacent, in that order.
4. **No other step gets it.** `handoff` does invoke the `handoff` skill; a line like this there would be wrong. Task 1's second test walks every other leg and step in both modes.
5. **The `autoflow` finish brief still holds no `gh pr ready`.** The existing test *leaves the PR draft at the autoflow finish step for the session that launched the run* asserts `not->toContain('gh pr ready')`; the line says "changes the PR's draft state" and never names the command. That test runs unchanged in Task 1, Step 4.

---

## File Structure

- Modify `skills/pipeline/checks/brief.php` (`pipeline_leg_overrides()`).
- Modify `skills/pipeline/checks/tests/BriefTest.php` (two new tests).
- Modify `skills/pipeline/references/engine.md` (§Who takes the PR out of draft).
- No file for Task 2: it posts one comment on the issue.

---

### Task 1: both review-pr briefs open with the line, and engine.md says why

**Files:**
- Modify: `skills/pipeline/checks/brief.php:22` (a variable beside `$checks`) and `:82-99` (the two `review-pr` entries)
- Modify: `skills/pipeline/references/engine.md:822-826` (a paragraph after the `autoflow` paragraph of §Who takes the PR out of draft)
- Test: `skills/pipeline/checks/tests/BriefTest.php` (two tests, after *makes the review-pr resolve step the finish step*, line 215-220)

**Interfaces:**
- Consumes, all existing: `pipeline_brief(array $manifest, string $leg, string $manifestPath, ?string $step = null, ?callable $git = null): string`; `pipeline_leg_overrides(string $mode): array` keyed `<leg>:<step>`; `pipeline_legs(): array`; `pipeline_steps(string $leg, string $mode): array`; the test helpers `brief_manifest(string $leg, array $extra = []): array` (mode `interactive` unless overridden) and `brief_git_behind(): Closure` (a git 27 commits behind `origin/main`), both in `BriefTest.php`. The overrides section renders as `## Overrides`, a blank line, then one `- ` bullet per line.
- Produces: nothing a later task calls.

- [ ] **Step 1: Write the tests.** In `BriefTest.php`, directly after the test *makes the review-pr resolve step the finish step*, add:

```php
it('tells both review-pr steps the leg is not the review-pr skill, in either mode', function (string $mode, string $step, string $next) {
    $line = '- The leg\'s name is not a skill to invoke: do not invoke the `review-pr` skill (`/review-pr`), which posts its own review comment and changes the PR\'s draft state. This brief is the whole step (engine.md §Who takes the PR out of draft).';
    $open = ['gate' => 'pr-review', 'leg' => 'review-pr', 'cycle' => 1, 'at' => '2026-09-22T10:00:00Z', 'review' => 'r'];
    $manifest = brief_manifest('review-pr', ['mode' => $mode, 'gate_ledger' => $step === 'resolve' ? [$open] : []]);

    expect(pipeline_brief($manifest, 'review-pr', '/tmp/m.json', $step))
        ->toContain("## Overrides\n\n{$line}\n- {$next}");
    expect(pipeline_brief($manifest, 'review-pr', '/tmp/m.json', 'resolve', brief_git_behind()))
        ->toContain("## Overrides\n\n- Catch up with the base first")
        ->toContain("Record the merge as that section says.\n{$line}\n- You are the finish step.");
})->with([
    'autoflow review' => ['autoflow', 'review', 'Apply `/critique`\'s `pr` procedure'],
    'interactive review' => ['interactive', 'review', 'Invoke `/critique pr`'],
    'autoflow resolve' => ['autoflow', 'resolve', 'You are the finish step.'],
    'interactive resolve' => ['interactive', 'resolve', 'You are the finish step.'],
]);

it('says the leg is not a skill to no other step', function () {
    foreach (['autoflow', 'interactive'] as $mode) {
        foreach (array_diff(pipeline_legs(), ['review-pr']) as $leg) {
            foreach (pipeline_steps($leg, $mode) as $step) {
                expect(pipeline_brief(brief_manifest($leg, ['mode' => $mode]), $leg, '/tmp/m.json', $step))
                    ->not->toContain('not a skill to invoke');
            }
        }
    }
});
```

The second `expect` of the first test runs the resolve brief with a git that is behind, whatever the dataset's step: it pins Review Focus 1 in both modes.

- [ ] **Step 2: Run them to see the first one fail.**

Run: `composer install --no-interaction --quiet` (only when `vendor/` is missing), then
`./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests --filter='not the review-pr skill|not a skill to no other step'`

Expected: the four datasets of *tells both review-pr steps the leg is not the review-pr skill, in either mode* FAIL (the brief does not contain the line). *says the leg is not a skill to no other step* PASSES already: it is a guard on the change, not a driver of it.

- [ ] **Step 3: Add the line.** In `skills/pipeline/checks/brief.php`, `pipeline_leg_overrides()`, directly after the `$checks = …;` line (line 22):

```php
    $notTheSkill = 'The leg\'s name is not a skill to invoke: do not invoke the `review-pr` skill (`/review-pr`), which posts its own review comment and changes the PR\'s draft state. This brief is the whole step (engine.md §Who takes the PR out of draft).';
```

Then make it the first element of both `review-pr` entries; every existing line stays as it is:

```php
        'review-pr:review' => [
            $notTheSkill,
            ($autoflow ? $yourself('pr', 'the PR') . ' State the suite line above.' : 'Invoke `/critique pr`, stating the suite line above.') . ' ' . $checks,
```

```php
        'review-pr:resolve' => [
            $notTheSkill,
            'You are the finish step.',
```

Run: `php -l skills/pipeline/checks/brief.php`
Expected: `No syntax errors detected in skills/pipeline/checks/brief.php`

- [ ] **Step 4: Say it in engine.md.** In `skills/pipeline/references/engine.md`, §Who takes the PR out of draft, after the paragraph that ends `before `review-pr`'s finish step has run.` and before `This is not a preference; …`, add this paragraph with a blank line on either side:

```markdown
**The leg is not the `review-pr` skill.** `DevOps-Claude-Config` ships a skill named `review-pr`, linked
into every session, and the leg does not use it: its review step is `/critique pr`, its resolve step the
finish step. That skill settles the draft status itself (`gh pr ready` on a clean review) and posts its
own review comment, which would come before the CI gate. Both `review-pr` steps' briefs therefore open
their overrides with (`pipeline_leg_overrides()`): **"The leg's name is not a skill to invoke: do not
invoke the `review-pr` skill (`/review-pr`), which posts its own review comment and changes the PR's
draft state. This brief is the whole step."**
```

- [ ] **Step 5: Run the whole pipeline suite.**

Run: `./vendor/bin/pest -c skills/pipeline/checks/phpunit.xml --test-directory=skills/pipeline/checks/tests`

Expected: all green, the two new tests among them, and unchanged: *puts the catch-up line first on every step that writes to the branch, with the merge command in its literal form*, *makes the review-pr resolve step the finish step*, *leaves the PR draft at the autoflow finish step for the session that launched the run*, and `LockStepTest`'s *keeps every engine.md section a brief names*.

- [ ] **Step 6: Commit.**

```bash
git add skills/pipeline/checks/brief.php skills/pipeline/checks/tests/BriefTest.php skills/pipeline/references/engine.md
git commit -m "feat(pipeline): both review-pr briefs say the leg is not the review-pr skill (#127)"
```

---

### Task 2: the transcript count on the issue

**Files:** none. One comment on `IT4WEBBV/LaravelClaudeMd#127`.

**Interfaces:**
- Consumes: the session transcripts of this machine, `~/.claude/projects/**/*.jsonl` (read-only); `gh`.
- Produces: nothing a task calls.

- [ ] **Step 1: Count again, so the numbers are those of the day the comment is posted.** Each as its own command:

```bash
cd ~/.claude/projects && find . -name '*.jsonl' | wc -l
```

```bash
cd ~/.claude/projects && grep -rlE 'dispatch_cli\.php brief [^"]* review-pr (review|resolve)' --include='*.jsonl' . | sort > /tmp/issue-127-briefs.txt; wc -l < /tmp/issue-127-briefs.txt
```

```bash
cd ~/.claude/projects && grep -rlE '"name":"Skill","input":\{[^}]*"skill":"(review-pr|[a-z-]+:review-pr)"' --include='*.jsonl' . | sort > /tmp/issue-127-skill.txt; wc -l < /tmp/issue-127-skill.txt
```

```bash
comm -12 /tmp/issue-127-briefs.txt /tmp/issue-127-skill.txt | wc -l
```

Expected: four numbers, in order: the transcripts read, those that ran a `review-pr` brief, those holding a `Skill` call naming `review-pr`, and those in both lists. The spec measured 1720, 192, 14 and 0 on 2026-09-30; the first three grow with every session. A last number other than 0 does not change Task 1: post the comment with the number found and name the transcript files in it.

- [ ] **Step 2: Post the record.** Fill in the four numbers and the date; the text is otherwise verbatim:

````bash
gh issue comment 127 --repo IT4WEBBV/LaravelClaudeMd --body "$(cat <<'EOF'
Transcript count for the *Done when* of this issue, measured <date> over the session transcripts of one machine (`~/.claude/projects/**/*.jsonl`, <N> files). The second machine's transcripts are not included.

| what | count |
|---|---|
| transcripts that ran `dispatch_cli.php brief … review-pr review\|resolve` (a step agent, or the session that holds it) | <briefs> |
| of those, holding a `Skill` call naming `review-pr` | <both> |
| any transcript holding a `Skill` call naming `review-pr` | <skill> |

Patterns, run from `~/.claude/projects`:

```
grep -rlE 'dispatch_cli\.php brief [^"]* review-pr (review|resolve)' --include='*.jsonl' .
grep -rlE '"name":"Skill","input":\{[^}]*"skill":"(review-pr|[a-z-]+:review-pr)"' --include='*.jsonl' .
```

No step agent of a `review-pr` step was seen invoking the skill. The brief line is preventive: both `review-pr` briefs now open with it, in either mode.
EOF
)"
````

When the number in both lists is not 0, replace the last paragraph with: `<both> transcript(s) of a review-pr step hold the call: <file names>. Both review-pr briefs now open with the line, in either mode.`

Expected: `gh` prints the comment's URL. Put that URL in the reason the step returns; there is nothing to commit for this task.
