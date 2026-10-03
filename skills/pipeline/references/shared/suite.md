# Suite reuse

Read by: `steps/implement.md`, `steps/finish.md`

## Suite reuse — once per tree

A full suite run proves something about the **content** it ran over, not about a commit. Every point
that runs the full suite asks first:

- after each `implement` step,
- after review fixes,
- before `review-pr`.

```bash
CHECKS="$HOME/.claude/skills/pipeline/checks"
MANIFEST="<the manifest path the brief names>"
TREE=$(php -r 'require $argv[1] . "/suite.php"; echo pipeline_tree_key($argv[2]);' "$CHECKS" "<worktree>")
# manifest `suite` is {tree, outcome: green|red, passed, failed, at}
php -r 'require $argv[1] . "/suite.php";
        $manifest = json_decode(file_get_contents($argv[2]), true);
        exit(pipeline_suite_needed($manifest["suite"] ?? null, $argv[3]) ? 0 : 1);' "$CHECKS" "$MANIFEST" "$TREE" \
  && echo "run the suite" || echo "reuse: this tree is already green"
```

- **The key.** `pipeline_tree_key()` is the tree the working copy would commit right now, untracked
  non-ignored files included, built in a temporary index so the real one is untouched. Committing
  content that was already tested keeps the key, so a run before `git commit` counts for the commit.
- **Record.** After every full run,
  `php "$CHECKS/dispatch_cli.php" suite "$MANIFEST" --outcome <green|red> --passed <n> --failed <n>`
  writes `suite: {tree, outcome, passed, failed, at}` to the manifest, computing `tree` itself. It refuses
  `green` with failures and a key it cannot compute. Only `green` is ever reused.
- **The reviewer is told.** `pipeline_brief()` states, from the manifest, *"full suite green over tree `<tree>` at
  `<sha>`: N passed"*. Whether to re-run stays the reviewer's call.
- **No baseline.** No suite runs before the change. A red full suite is a failing step, fixed and
  bounded like any other.
  - When the leg believes a failure predates the change, that is a **machinery failure → halt**
    with the evidence (`session.md` §Failure policy), never an annotation.
  - Never switch the run's worktree to the base commit to compare: under a running stack that
    desyncs vendor, migrations and assets, and a wrong red would be filed as pre-existing.
- **Failure to compute the key** (`pipeline_git` throws) is a machinery failure. Run the suite; never
  assume reuse.
