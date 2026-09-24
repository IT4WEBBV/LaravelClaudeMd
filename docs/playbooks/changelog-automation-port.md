# Porting the changelog automation into a project

PRs drop a `.changelog/unreleased/*.md` fragment instead of editing `CHANGELOG.md`. Publishing a
GitHub Release rolls the fragments into a `## vX.Y.Z` section and opens a `changelog/vX.Y.Z` bot PR;
merging that PR syncs the section into the Release notes. First landed in GmTool, PR #420 (2026-07-03).

## Source and references

Port from **LaravelTemplate** (`.changelog/unreleased/` and the workflows), not from Deploy or
whichever project was touched last. Reference ports: viewiemedia #1925 (workflows) and #1918
(scaffolding and CHANGELOG baseline), GmTool #420 (first port onto an existing changelog), Deploy
#419 (`master` branch, Dutch changelog, legacy-heading guard).

## Files that travel together

`.changelog/unreleased/TEMPLATE.md`, `.changelog/unreleased/README.md`,
`.github/workflows/changelog-format.yml`, `release-changelog.yml`, `sync-release-notes.yml`, and a
`paths-ignore` tweak to the existing CI (`main.yml`).

## Gotchas

- **Swap the repo URL** in TEMPLATE.md and README.md (`IT4WEBBV/LaravelTemplate` →
  `IT4WEBBV/<Project>`). The three workflows use the `github` context and need no edits, **unless the
  default branch is `master`**: LaravelTemplate hardcodes `main` in three places (`release-changelog.yml`
  checkout `ref:` and `gh pr create --base`, `sync-release-notes.yml` `branches:`). BreinStraat2 uses
  `github.event.repository.default_branch` plus a guard step instead.
- **A changelog in a different format needs adapting, not copying.** The workflows key off
  `^## v[0-9]`. If `CHANGELOG.md` uses `# vX.Y.Z` headings (GmTool did), relevel only the version
  headings (`sed 's/^# \(v[0-9]\)/## \1/'`) and prepend a `# Changelog <Project>` title plus a
  `## Unreleased` block with the fragment comment. Leave non-version H1s and entry bodies alone;
  old entries keep their prose style.
- **Undated legacy version headings defeat the idempotency check.** LaravelTemplate's "already
  exists" test greps `^## ${VERSION} ` (trailing space, a dated heading), so a tag equal to an old
  undated `## vX.Y.Z` heading writes a second section with the same version (Deploy: exit 0, two
  `## v1.0.90`). Where the old changelog ran ahead of the tags (Deploy: headings to v1.0.96, tags to
  v1.0.88), add `grep -qxF "## ${VERSION}"` → `exit 1`, and document "tag ≥ first free version" in the
  README and CLAUDE.md.
- **The port PR re-conflicts every time a direct `CHANGELOG.md` edit lands first**, and on a changelog
  that runs ahead of the tags each such merge also moves the tag floor. Write the relevel as a
  script, check that it reproduces the port's own `CHANGELOG.md` byte for byte from the old base, then
  resolve with `git show origin/<base>:CHANGELOG.md > CHANGELOG.md` and re-run it. Let the guard's
  error message read the newest heading instead of hardcoding the floor, and merge the port soon after
  it is green.
- **A merge conflict silently blocks all `pull_request` CI.** GitHub runs those workflows against the
  test-merge commit; a conflicting PR has none, so zero checks run (`gh pr checks` is empty, status
  `pending`). `gh pr view N --json mergeable` showing `CONFLICTING` is the tell.
- New workflow files only register (in `gh workflow list`, as required checks) after they land on the
  default branch. On the introducing PR only `changelog-format` runs (it is `paths`-triggered on
  `.changelog/unreleased/**`) plus the existing CI.
- Dependabot: viewiemedia #1925 only removed a dead root-level `dependabot.yml`; the real config
  lives at `.github/dependabot.yml`. Check the target first rather than adding or deleting blindly.
- Add a seed fragment for the bootstrap PR itself, named after the branch. It follows the "changelog
  on every PR" rule and smoke-tests `changelog-format` on a real fragment.

## Moving open PRs' direct CHANGELOG.md edits into fragments

Done on Deploy for 6 PRs (2026-09-11). Per branch: a *detached* temporary worktree at
`origin/<branch>` (never touching a slot's local ref) → `git checkout <merge-base> -- CHANGELOG.md` →
generate the fragment by script from the removed hunk (single headline bullet → `<summary>`,
sub-bullets de-indented, backticks → `<code>` since Markdown doesn't render in `<summary>`) → assert
the fragment body equals the removed hunk → non-force `push origin HEAD:refs/heads/<branch>`.
Dry-run without the push first, and fast-forward any clean slot holding the branch afterwards.

## Verify locally before pushing

Dry-run the three bash scripts against fixtures built from the project's actual restructured
`CHANGELOG.md`: (1) the rollup inserts `## vX.Y.Z` under `## Unreleased`, above the newest version,
and consumes the fragments; (2) release-notes extraction is bounded to one version; (3) fragment
validation rejects a missing `<details>` wrapper. Extract the `run:` blocks with `ruby -ryaml` so
the test runs the YAML's own code, and run them in the `bash:5` image with `apk add git`
(`release-changelog.yml` uses `mapfile`, which macOS bash 3.2 lacks). A background job's
`~/.claude/jobs/.../tmp` is not a Docker Desktop shared path: stream files in with
`tar -cf - … | docker run -i … tar -xf -` instead of `-v`.
