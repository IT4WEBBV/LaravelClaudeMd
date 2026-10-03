# Catching up with the base

Read by: `steps/design.md`, `steps/review-plan-resolve.md`, `steps/implement.md`, `steps/finish.md`

## Catching up with the base — a run merges its base into its own branch

A run keeps its own branch current with its base by a plain merge, and does not halt on "behind" (#124).

**Code decides whether, the step merges.** On a step that writes to the branch (`PIPELINE_CATCH_UP_STEPS`
in `../../checks/brief.php`: `design`'s steps, both resolve steps and `implement`), `pipeline_base_state()`
fetches the base (`origin/<manifest base>`, else `origin/HEAD`) and compares:

| The branch | The base | The brief |
|---|---|---|
| is not behind | | no line |
| holds code | moved only in files the branch does not change | no line: CI tests the PR's merge ref |
| holds code | moved in a file the branch changes | the line, naming the shared files |
| holds nothing, or only its spec and plan | moved at all | the line: a design is written by reading the code, so it reads current code |

Any git call that fails gives no line: a run that cannot tell carries on, and an offline fetch is no
reason to stop. The fetch runs from PHP, as kickoff's does (`pipeline_kickoff_base()`): it moves only
`refs/remotes/origin/<base>`, never the working tree, which is why `brief` may fetch where it may not
merge (a merge run from PHP would hide the command from the permission layer and change the tree
`brief` reads). With an unreachable remote `brief` waits on git's network timeout, once per writing
step, and then prints the brief without the line. The review steps do not merge (a reviewer that
resolves a conflict reviews its own work), nor does `handoff` or `verify-ui`. Both modes get the line.

**The line is the step's first override** and carries the command:
`cd <worktree> && git merge --no-edit origin/<base>`, run as its own command in exactly that form, because a
permission rule matches a command as typed (`README.md`, *Permissions for unattended runs*). A denied
command is a halt naming it, never a reshaped command.

- **The merge comes first**, on the clean tree the previous step left, then the step's own work.
- **Outside the review's and the plan's bounds.** The merge and its conflict resolutions are the
  base's changes, not the step's: they fall outside a resolve step's *"Change nothing the review did
  not name"* (`shared/resolving.md` §Resolving a review), and a file only the merge touched is no plan gap and no
  `plan-insufficient` for `implement`.
- **Conflicts.** Resolve each file keeping both sides' intent, leave no conflict marker behind,
  `cd <worktree> && git add <file>`, and conclude with `cd <worktree> && git commit --no-edit`
  (`git merge --continue` needs an editor, which a step does not have). Where keeping both sides is a
  product decision, the two changes wanting opposite behaviour: `cd <worktree> && git merge --abort`
  and return `halted`, quoting the conflicting hunks. After `handoff` the reason goes into the PR body as
  for any halt (`session.md` §Failure policy). The owner's answer comes back as a `--decision` on the relaunch, and
  that step's brief asks for the merge again.
- **The suite.** Nothing new: a merge changes the tree, so `shared/suite.md` §Suite reuse finds no green run for it and
  the step's next full run covers the merged tree. `implement` merges before its first plan step;
  `review-pr`'s finish step runs the suite after its merge. A design step's merge runs none: the branch
  has no code of its own to test.
- **The record.** When `artifacts.pr` is set, add one line per merge to the PR body, under a
  `## Base merges` heading created once: the base and its sha, the commit count, and per conflicted file
  how it was resolved (*clean* when there was none). Edit the body as `session.md` §Failure policy does
  (`gh pr view --json body` into a file, append, `gh pr edit --body-file`), never blanking it. A resolve
  step also names the merge in its entry's `actions`. Before a PR exists the merge commit is the record.
  A branch without a commit of its own fast-forwards: no merge commit, nothing to record.
- **No rebase and no force-push, anywhere in a run.** `steps/review-pr-review.md` §Scoped re-review treats a rewritten history as
  "review everything again".

**No boundary check verifies that a step merged.** The state is recomputed at every writing step's
brief, so a step that skipped the merge leaves the next one the same line. A merge made by `implement` or
a design step is reviewed with the rest of the diff; one made by the finish step gets its own review
round at the gate (`machinery.md` §The CI gate).

**What this does not catch.** A base that changed only files the branch does not touch is not merged,
even where the branch's code depends on them: the blind spot `steps/review-pr-review.md` §Scoped re-review names, covered by CI on
the merge ref. A design grown after code exists (a plan gap) is measured by the branch's own files, not
by the files the grown plan names; the step that writes that code catches up at its next brief.
