# SecondBrain vault as auto-memory — design

**Date:** 2026-09-11
**Status:** draft → review (Fable: design review folded in; second opinion → reduced version) → user review → implementation plan → PR
**Canonical home:** `IT4WEBBV/LaravelClaudeMd` — `hooks/`, `CLAUDE.md`. Storage lives in the private vault repo `jonneroelofs/SecondBrain`.
**Replaces:** the "Second Brain (basic-memory archival memory)" and "Second Brain (multi-machine setup)" sections of `CLAUDE.md`.

## Summary

Point Claude Code's auto-memory at the SecondBrain vault (`autoMemoryDirectory`), so the one memory
system Claude reliably uses is also git-versioned and shared by both machines. A small hook syncs the
vault at session start and end. Repo-specific memories live in per-repo folders, reached through one
pointer line per repo in the global index — no hook. basic-memory is removed.

## The problem

The vault was set up on 2026-06-25 as an "archival tier" next to native auto-memory ("hot tier").
Measured on 2026-09-11 over the 381 local transcripts that survive (2026-08-06 onward):

- **Creating notes fails.** No session created a vault note: 0 `write_note` or `edit_note` calls.
  Meanwhile auto-memory gained 42 files since the vault started (74 total). Example: on 2026-09-07 a
  vault search for "vpn" found nothing, and the resulting knowledge was saved to auto-memory.
- **Updating works when a note matches the task.** On 2026-09-11 a session searched the vault, found
  the changelog-port playbook, used it, and committed two improvements to it.
- **Reads are rare:** about 5 lookups in 5 weeks.

Root causes:

1. **Same trigger, two destinations.** Both systems say "save what's non-obvious and reusable".
   Auto-memory lives in the system prompt of every session with a fixed path and always-loaded
   tools; the vault is one CLAUDE.md paragraph and its MCP tools are deferred. The built-in wins.
2. **The tier boundary is not usable at save time.** "Current sprint" vs "archival" is a horizon you
   can't know when saving; auto-memory is full of durable facts the vault's rules claim.
3. **Duplication** already happened (slot-port playbook, prefer-report-over-log exist in both).

So auto-memory works; having two stores does not. What auto-memory cannot do is **sync between
machines** and **keep history**: it is machine-local and unversioned. Today the 16 `feedback_*`
corrections — the memories that change Claude's behaviour — exist on one machine only. The vault is
worth keeping for exactly those two properties.

## Decisions (owner, 2026-09-11)

| Question | Decision |
|---|---|
| Retire or improve? | Improve — reduced to what auto-memory can't do on its own. |
| One shared memory folder? | Yes. The owner always launches from `~`, so `-Users-jroelofs/memory` already is the de facto shared folder. Hard requirement: repo-specific memories must be recognizable as repo-specific. |
| Does the owner browse the vault? | Rarely or never. Shape for Claude's recall; Obsidian niceties are out of scope. |
| When do repo-specific memories load? | Only when the session works in, or is asked about, that repo. |
| Second machine | The owner's other machine, used weekly or more — the reason sync is worth building. |
| Direction | Vault as auto-memory + session-boundary sync + repo folders by convention. The full design with a repo-memory hook was reviewed and cut back (see Rejected alternatives). |
| basic-memory | Drop it. |
| Who resolves a vault sync conflict? | **Claude.** The vault holds Claude's own notes. An explicit exception, for the vault only, to the global "do not pull, rebase or merge on your own initiative" rule. |

## Design

### 1. Layout

```
SecondBrain/                         (private git repo, commits straight to main)
├── .gitattributes                   memory/**/MEMORY.md merge=union
├── CLAUDE.md, README.md             rewritten, short
├── 2026-06-30.md, 2026-08-05.md     the owner's own notes — untouched, never staged by the hook
└── memory/                          ← autoMemoryDirectory
    ├── MEMORY.md                    global index; the harness loads its first 200 lines / 25KB
    ├── <memory>.md                  global memories
    └── repos/<key>/
        ├── MEMORY.md                repo index, read on demand
        └── <memory>.md              repo-specific memories
```

- **Setting** (user scope, both machines): `"autoMemoryDirectory": "~/GitProjects/SecondBrain/SecondBrain/memory"`
  in `~/.claude/settings.json`. Every session, from any launch directory, uses this one folder.
- **Format:** unchanged auto-memory format — frontmatter `name`, `description`, `metadata.type`
  (`user|feedback|project|reference`); feedback/project bodies carry **Why:** / **How to apply:**.
  The harness keeps stamping `modified`.
- **Union merge** on every `MEMORY.md`: two machines each appending an index line would otherwise
  conflict (adjacent appends at end-of-file do). Union keeps both lines; a duplicate is harmless.

### 2. Repo scoping by convention

Repo-specific memories are recognizable by *where they live*, and load the way every topic file
already loads — on demand, from an index line that is always in context.

- **Folder:** `repos/<key>/`, where `<key>` is the repo's GitHub name lowercased (the basename of
  `git remote get-url origin` without `.git`): `IT4WEBBV/ViewieMedia` → `viewiemedia`. Slots,
  worktrees, directory casing and the other machine all agree on it. Remote-less repos use the
  lowercased directory name; projects without a local repo (e.g. Revolve) use their project name.
- **Pointer line:** each repo folder gets exactly one line in the global index, naming the repo and
  what's inside so recall has something to match on:
  `- [ViewieMedia repo memory](repos/viewiemedia/MEMORY.md) — read before working in or answering about ViewieMedia: prod NFS storage, slot dir casing, …`
  Asking about BreinStraat2 or touching its files meets the same cue: the index names the file to read.
- **Filing rule**, in the header of the global `MEMORY.md` so it is in context at every save: a fact
  only true inside one repo goes to `repos/<key>/`, with its line in that repo's `MEMORY.md`; a new
  repo folder also gets its pointer line here. Everything else is global. Examples: "BreinStraat2 is
  mid-rewrite" → `repos/breinstraat2/`; "auth.json is committed in 18 repos" and "TallDataTable
  selects only declared fields" (true in every consuming repo) → global. A misfiled fact still lives
  in the vault and still syncs; the cost of a miss is scope, not loss.
- **Why no hook:** the global index is 41 of 200 lines, about 7 memories are repo-specific, and their
  names already say which repo. A hook that injects repo indexes (three trigger modes, payload parsing,
  per-agent markers, a spawn on every tool call) was designed and reviewed, and is deferred until the
  convention measurably fails — see Success criteria.

### 3. Vault-sync hook — `hooks/vault-sync.sh`

Keeps the vault committed and in sync with origin, with network work only at session boundaries and
nothing while the session is writing. It reads nothing from the hook payload beyond its mode, so it
needs no JSON parsing. Vault path defaults to `~/GitProjects/SecondBrain/SecondBrain`, overridable
with `VAULT_DIR` (used by the tests).

| Mode | Event | Does |
|---|---|---|
| `session` | `SessionStart`, matcher `startup\|resume\|clear` | 1) a rebase or merge left in progress (`rebase-merge`, `rebase-apply`, `MERGE_HEAD`) is aborted **before** anything is committed; 2) **sweep**: `git add -- memory/` → secret scan → commit `memory: sync`; 3) fetch, at most every 15 minutes (the git-freshness `FETCH_HEAD` TTL), bounded at 10 s with `BatchMode` ssh; 4) if behind: `git rebase --autostash origin/main`; on conflict abort and tell Claude to resolve (below); 5) detached push if ahead; 6) report any warning the previous `end` run left |
| `end` | `SessionEnd` | the sweep, then a detached push. SessionEnd cannot return context, so problems go to `.git/vault-sync-warnings` and the next `session` run reports them. |

- **No per-write commits.** Memories are files on disk the moment they're written; the next sweep
  (session end, or the next start after a crash) commits them. Nothing commits while a rebase is in
  progress, so an abort never drops work.
- **Conflicts are resolved by Claude.** With union merge on the indexes, only a topic file edited on
  both machines can conflict. The hook aborts the rebase — the vault is never left half-rebased while a
  session writes — and returns: `SecondBrain vault diverged; conflict in <files>. Resolve it now:
  rebase onto origin/main, keep both sides' facts in the markdown, continue, push.`
- **Detached push:** `( GIT_TERMINAL_PROMPT=0 GIT_SSH_COMMAND="ssh -o BatchMode=yes -o ConnectTimeout=5" nohup git push -q </dev/null >/dev/null 2>&1 & )`,
  so the hook returns without waiting on the network.
- **What the hook stages:** only `memory/`. The owner's root notes are never staged; `--autostash`
  keeps their uncommitted edits safe across the rebase. No `git add -A`.
- **Secret scan** over the staged diff's added lines, BSD-grep compatible: private-key blocks and
  token prefixes (`ghp_`, `github_pat_`, `sk_live_`, `AKIA[0-9A-Z]{16}`, `xox[abp]-`). Deliberately
  narrow — no generic `password=` pattern — because a false positive would hold a memory back
  indefinitely. On a hit, **only the offending file** is unstaged; the rest still commits; the warning
  names file and pattern, never the value. The no-secrets rule in CLAUDE.md stays the real control.
- **Contention:** `index.lock` held by a concurrent session → retry 3× with a short backoff, then leave
  it to the next sweep.

### 4. `git-freshness.sh` skips the vault

Its `edit` mode fires on every Write/Edit and resolves the file's repo, so every memory write would
make it fetch the vault and — whenever the other machine has pushed — tell Claude "origin/main has N
commits … Do NOT pull", contradicting the sync hook. One early exit when the toplevel is the vault
(`VAULT_DIR`, same default) fixes it. `hooks/tests/git-freshness-sync.test.sh` gets a case for it.

### 5. Migration (one-off)

Sources:

| Source | Count | State |
|---|---|---|
| `~/.claude/projects/-Users-jroelofs/memory` | 41 | current |
| 9 other per-repo memory dirs (Asimo, ViewieMedia, Deploy, car-charger, GitProjects, BreinStraat2, LaravelClaudeMd, talldatatable; Cornels holds only a `MEMORY.md`) | 33 | stale — newest files February–July |
| Vault notes in `decisions/`, `packages/`, `playbooks/`, `projects/`, `references/` (`reviews/` is empty) | 26 | basic-memory format |
| The second machine's local auto-memory | unknown | migrated there, see below |

Rules:

- **Every source note gets exactly one recorded outcome** — moved global / moved `repos/<key>` /
  merged into `<name>` / dropped (reason). The ledger goes in the vault migration commit's message.
  No silent drops.
- **Vault notes are converted** to the auto-memory format: filenames become slugs (today's contain
  spaces, `$` and `—`), `## Observations` become plain bullets, `## Relations` and wikilinks become
  `[[name]]` links to the new slugs, `confidence`/`evidence`/`permalink` fields are dropped. The nine
  Livewire/Alpine references are candidates to merge into one reference memory with one index line.
- **Duplicates are merged:** slot-functionality-port playbook, prefer-report-over-log. The
  `second_brain_vault` memory is deleted — CLAUDE.md documents the new setup.
- **Stale memories are kept** unless clearly superseded; the harness's "this memory is N days old"
  reminder flags age at read time.
- **The migration commit is scanned** with the same secret patterns before it is committed (a
  pre-check on 2026-09-11 found no hits in either source).
- Expected size: about 35 global memory lines, about 15 from vault notes, and one pointer line per
  repo folder — around 55 lines in the global index, against a 200-line cap.

Cut-over order on this machine — run in a session started with `CLAUDE_CODE_DISABLE_AUTO_MEMORY=1`,
so the migrating session cannot save into the folder it is retiring:

1. Probes P1–P3 pass (P5 is informational; see Testing).
2. `vault-sync.sh`, the `git-freshness.sh` vault skip and their tests merged in LaravelClaudeMd.
3. Migration commit in the vault: `memory/` tree, old folders removed, `.gitattributes`,
   vault `CLAUDE.md`/`README.md` rewritten, basic-memory lines dropped from `.gitignore`. (No
   `.claude/settings.json`: probe P2 passed without it.)
4. `~/.claude/settings.json`: `autoMemoryDirectory` plus hook wiring (through the `update-config` skill).
5. Old dirs renamed `memory.bak-2026-09-11`; the harness stops reading them once the setting is set.
6. basic-memory removed: `claude mcp remove basic-memory -s user`, `uv tool uninstall basic-memory`,
   `rm -rf ~/.basic-memory` (a rebuildable cache; the markdown is the data).
7. After a week of normal use, each `.bak` dir is diffed against the ledger, then deleted.

**Rollback** at any point before step 7: remove `autoMemoryDirectory` and the hook entries from
`~/.claude/settings.json`, rename the `.bak` dirs back, `git revert` the vault migration commit.

**Second machine:** a global playbook memory, "migrate a machine's local auto-memory into the
vault", lets one session on that machine run steps 4–7 and classify and merge its local memories
against what's already in the vault. The owner starts that session; the hook takes it from there.

### 6. Instructions

- **`CLAUDE.md`** — replace both "Second Brain" sections with one short "Memory (SecondBrain vault)"
  section: where memory lives, the repo-folder convention and key, no secrets, the hook does the git
  work, and **the vault exception to the no-rebase rule** (Claude resolves vault sync conflicts
  itself). Bootstrap steps: clone the vault, set `autoMemoryDirectory`, wire the hook (JSON below),
  run the migration playbook. Drop the basic-memory install steps and "pull the vault at session start".
- **Vault `CLAUDE.md` / `README.md`** — what `memory/` is, that Claude writes it through
  auto-memory, that the hook commits to main, that root notes belong to the owner.
- **Harness memory instructions** are untouched; only the directory moves.

Hook wiring, added next to the existing git-freshness entries:

```json
"autoMemoryDirectory": "~/GitProjects/SecondBrain/SecondBrain/memory",
"hooks": {
  "SessionStart": [ { "matcher": "startup|resume|clear", "hooks": [ { "type": "command", "command": "$HOME/GitProjects/LaravelClaudeMd/LaravelClaudeMd/hooks/vault-sync.sh session", "timeout": 20 } ] } ],
  "SessionEnd":   [ { "hooks": [ { "type": "command", "command": "$HOME/GitProjects/LaravelClaudeMd/LaravelClaudeMd/hooks/vault-sync.sh end", "timeout": 20 } ] } ]
}
```

## Error handling

- The hook always exits 0. Problems surface as a one-line `additionalContext` warning at session
  start; it never blocks the session.
- Vault not cloned → the hook no-ops with a single warning.
- Secret found → that file is not committed; the warning names file and pattern.
- Sync conflict → rebase aborted, local commits kept, Claude told to resolve.
- Anything detected at `SessionEnd` → stored in `.git/vault-sync-warnings`, reported next session.

## Testing

**Probes before anything is built** — throwaway, reverted after:

| # | Question | How | If it fails |
|---|---|---|---|
| P1 | Is `autoMemoryDirectory` honoured, with `~` expansion, on 2.1.268? Do `modified` stamping and the age reminder also work for files under `memory/repos/`? | set it to a scratch dir, start a session, check the memory path in its instructions and `/context`; write and re-read a nested memory | stop — the design rests on it |
| P2 | Can a bg job rooted at `~` write a memory into the vault? The permissions docs say project settings load only from the cwd's `.claude/` (no parent fallback), which contradicts the 2026-07-06 observation that the guard walks up from the edited file. | one throwaway Write from a `claude --bg` session, with and without the vault's `.claude/settings.json` | stop and decide with the owner: `bgIsolation: none` in `~/.claude/settings.local.json` (all home-rooted bg jobs), or remove the vault |
| P3 | Can a session inside an `EnterWorktree` worktree of another repo write a memory into the vault? | one throwaway Write from such a session | same as P2 |
| P5 | Does a `SessionStart` hook run before the harness reads `MEMORY.md`? | a stub hook appends a marker line to a scratch `MEMORY.md`; check whether the same session sees it | informational: the other machine's memories then arrive one session late; CLAUDE.md says so |

**`hooks/tests/vault-sync.test.sh`**, in the style of `git-freshness-sync.test.sh` (throwaway repos
under `$TMPDIR`): the sweep commits `memory/` only and ignores root notes; a stale rebase is aborted
before the sweep commits; a secret holds back only its file; two machines appending to `MEMORY.md`
merge by union; a topic-file conflict aborts and returns the Claude-facing message; a rejected push is
retried next session; the hook returns before a slow push finishes; `index.lock` contention; the
owner's uncommitted root note survives the rebase; a warning from `end` is reported by the next
`session`; the fetch TTL is respected.

**`git-freshness-sync.test.sh`**: new case — edits in the vault are ignored.

**After cut-over:** the ledger accounts for every source note; the global `MEMORY.md` is under 200
lines with one pointer line per repo folder; a new memory is committed and pushed by the next sweep;
the second machine sees it at its next session start (or the one after, per P5).

## Success criteria (checked after two weeks)

- `git -C <vault> log --grep '^memory:'` shows memories committed during normal work, without anyone
  asking for them.
- Both machines hold the same memories.
- Repo-specific memories are filed under `repos/<key>/`, and sessions working on a repo read its index.

**Revisit the deferred repo-memory hook** when the global index passes ~120 lines, or when a session
demonstrably misses a repo fact it should have read.

## Rejected alternatives

- **Remove the vault, keep plain auto-memory.** Strictly simpler and loses nothing *measured* today,
  but keeps both machines drifting apart and has no history. Right only if the second machine were
  incidental; it is used weekly or more.
- **Full design with `repo-memory.sh`** (SessionStart / PostToolUse / UserPromptSubmit injection of
  repo indexes, python payload parsing, per-agent markers, a shared `lib.sh`). Reviewed and folded,
  then cut: about 400 extra lines and a spawn on every tool call, for a scoping problem the index
  convention already covers at 41 of 200 lines. The pointer line also covers the "asked about a repo
  without touching its files" case the prompt trigger was kept for.
- **B — vault for repo notes only, auto-memory stays local.** Saving repo facts stays a
  which-system choice, the thing that fails today; global memories never reach the second machine.
- **C — disable auto-memory, basic-memory as the only store.** Saving would depend on CLAUDE.md
  wording and deferred MCP tools, the mechanism that failed; loses the harness's index-size warnings.
- **User-level path-scoped rules for repo memory.** They fire only when Claude reads a matching file,
  and their `paths:` matching from a `~` launch is unverified.
- **Committing and pushing on every write.** A background `pull --rebase` while the session keeps
  writing can leave the vault mid-rebase, and a later abort then drops commits. Network and commits
  happen only at session boundaries.
- **A synced folder (iCloud / Dropbox) as `autoMemoryDirectory`.** One setting and no hook, but silent
  "conflicted copy" files and no history.

## Non-goals

- Human browsing, Obsidian graph/links, semantic search.
- Cloud / claude.ai sessions (local hooks don't run there).
- Syncing settings.json or transcripts across machines.
- Sharing memory with colleagues (the vault is personal and private).
- Changing what the harness decides to save.

## Risks

1. **Guards vs a git-repo memory dir** (P2, P3). If writes into the vault are refused in bg jobs or
   worktree sessions, the owner decides between loosening the guard and removing the vault.
2. **The pointer line is still a cue Claude must follow.** It is the same mechanism every existing
   memory relies on, and it is always in context — much stronger than the old "search the vault when
   starting a project". The success criteria watch for misses.
3. **Global index growth:** about 55 lines at cut-over; the harness warns before the 200-line cap.
4. **Claude resolving sync conflicts** could merge two contradicting facts badly. Bounded: only topic
   files can conflict, both sides stay in git history, the resolution is itself a commit.
5. **Secret-scan false negatives.** The scan is narrow by design; the rule is the control.

## Probe results (2026-09-14, Claude Code 2.1.270)

Run with `--settings` pointing `autoMemoryDirectory` at a throwaway git repo `~/.vault-probe/memory`,
with `worktree.bgIsolation` at `"worktree"` in `~/.claude/settings.local.json` (the strict setting).

| # | Result | Evidence |
|---|---|---|
| P1a | PASS | `claude -p` answered `/Users/jroelofs/.vault-probe/memory/` — `~` expanded |
| P1b | PASS | `repos/probeproject/MEMORY.md` + `repos/probeproject/probe-colour.md` written; the stamp is `  modified: 2026-09-14T10:06:01.218Z`, **nested under `metadata:`**, so a `^modified:` grep misses it |
| P1c | reminder | after back-dating the nested stamp and mtime to 2026-08-01, reading the file returned: "This memory is 44 days old. Memories are point-in-time observations, not live state — …" |
| P2 | a | a `claude --bg` job rooted at `~` wrote `probe-p2a-background-memory-write.md` and its index line, **without** the vault's `.claude/settings.json` |
| P3 | PASS | a `claude --bg` job in another repo called `EnterWorktree` (transcript under `-Users-jroelofs--vault-probe-repo--claude-worktrees-p3`) and wrote `probe-p3-worktree-memory-write.md` |
| P5 | YES | the stub hook appended `- PROBE-P5 nonce N515428272`; the same session, asked without tools, quoted that line verbatim from its loaded index (grep count 1 per run) |

Consequences:

- **P2 = a:** the vault gets no `.claude/settings.json`. Auto-memory writes are not refused by the
  background-isolation guard, so the `bgIsolation` line in the Layout above is not needed.
- **P5 = YES:** a memory saved on the other machine is in context at the next session start — the
  `SessionStart` sync runs before the index is read.
