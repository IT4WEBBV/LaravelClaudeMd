# The proof payload

Read by: `steps/verify-ui.md`, `steps/finish.md`

## The payload

What `proof_cli.php write` files into the run's page (`proof-store.md` §Where a page lives). This
table is the schema. **An existing `run.json` is not an example.**

An agent's write takes `repo`, `branch` and `pr` from the `run.json` beside `artifacts.proof`, so it
lands in the directory `handoff` filed; a run without `artifacts.proof` gets its page from that write,
with `repo` (the GitHub name), `branch` and `pr` from the PR.

| Field | What it holds |
|---|---|
| `repo`, `nameWithOwner`, `branch`, `pr`, `issue`, `prState`, `mode` | where the run belongs; `nameWithOwner` makes the PR, *Files changed* and issue references links |
| `worktree`, `base` | the run's worktree and the branch its PR goes into, filed by `handoff`; every write diffs `origin/<base>...HEAD` there for `addedTests` |
| `title` | **required, at most 70 characters.** The run's name: page heading, browser tab, store index. `PR #430: service logs that follow`, not a sentence of findings |
| `clientSummary` | **required on every agent `write`.** One to three Dutch sentences for the hour registration: what the client gets, in the client's words, at most 400 characters. No `#<number>`, no backtick, and not the branch name (whole, or the part after its first `/`, as a word of its own) |
| `explainer` | **required on every agent `write`.** `{problem, solution}`: a paragraph each, in English, for a reader who knows nothing about the issue; blank lines become paragraphs |
| `headline` | one or two sentences: what was verified and the outcome. Rendered as the lead under the explainer |
| `problem`, `solution` | the technical account; prose, blank lines become paragraphs |
| `checks` | `tests`, `staticAnalysis` (scope-qualified), `format`, `suppressions` (list) |
| `openQuestions` | list of `{kind, question}`: `question` verbatim, `kind` one of `blocking`, `follow-up`, `remark` (`shared/resolving.md` §Open questions), shown as a label before the text. `write` refuses a payload with any other item; a stored run with string items keeps them, shown without a label |
| `ledger` | list of `{gate, outcome, note}` |
| `shots` | list of `{title, caption, route, badges, state}`. `title` is at most 70 characters and names the state shown ("Unreachable swarm"); `caption` says what the shot proves and has no limit. `state` is **required**: `before`, `after` or `defect`, the ribbon on the shot; a `before` directly followed by an `after` renders as one pair. A badge's `note` also shows on hover |
| `shotSources` | absolute paths of the screenshots, in `shots` order, `null` for a shot carried forward with its `file`; ingested into the run's `shots/` as `<NN>-<route>-<hash>.png`, so a new shot never overwrites a carried one |
| `addedTests` | **the store's, never a payload's**: per test file, the cases the branch adds (`added`, tagged *new*) or changes (`changed`), extracted at every write by git in `worktree`; kept as filed when git cannot answer. A payload's `addedTests`, `schema`, `createdAt`, `updatedAt`, `revision`, `attention`, `status` and `cost` are ignored |
| `revision`, `attention`, `status`, `cost` | **the store's, never a payload's**: `revision` counts the run's filings (`handoff`'s and every `write`); `attention` counts the times its status turned `halted` or `ready` (absent until the first); `status` is `{state, reason}`, the reason only with `halted` (`proof-store.md` §Statuses); `cost` is the figures `run_cost_cli.php` files, per workflow `{workflow, span, steps}` |

## Shots — before, after and defect

Every shot carries a `state`. Pairing is positional: in `shots` each `before` shot is immediately
followed by its `after` shot, so shoot the before shots, then the after shots in the same order, and
interleave them in the payload. Taking them, on the base and back on the branch, is
`steps/verify-ui.md` §Taking the shots. When the base cannot render the state (a migration it does not
expect), the before shot is left out and that is an open question of kind `remark`, never a halt. A pass that finds a
defect shoots it as `defect`; the next pass carries the earlier defect shots forward (their `file`,
`null` in `shotSources`) beside its own.

## What `write` refuses

`write` judges the run as it will be filed, the payload merged over the stored run, and refuses one
whose `title` is missing or longer than 70 characters, whose shot title is too long, whose shot has no
valid `state` or neither a `file` nor a source in `shotSources`, or whose `clientSummary` or
`explainer` breaks the rules above. It prints `proof: payload rejected` and the problems on stderr, prints no page path, and files nothing. Fix the payload and write
again. The page `handoff` files is judged on the title and shot rules only.
