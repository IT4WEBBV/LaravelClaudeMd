# SecondBrain vault as auto-memory — design

**Date:** 2026-09-11
**Status:** draft → review (Fable: *approve with changes*, folded in) → user review → implementation plan → PR
**Canonical home:** `IT4WEBBV/LaravelClaudeMd` — `hooks/`, `CLAUDE.md`. Storage lives in the private vault repo `jonneroelofs/SecondBrain`.
**Replaces:** the "Second Brain (basic-memory archival memory)" and "Second Brain (multi-machine setup)" sections of `CLAUDE.md`.

## Summary

Point Claude Code's auto-memory at the SecondBrain vault (`autoMemoryDirectory`), so the save path
Claude reliably uses lands in a git-synced repo shared by both machines. Split memory into a
**global index** (loaded every session, as today) and **per-repo indexes** that a hook shows the
first time a session touches or names that repo. A second hook commits every memory write locally
and syncs with origin at session start and end. basic-memory is removed.

## The problem

The vault was set up on 2026-06-25 as an "archival tier" next to native auto-memory ("hot tier").
Measured on 2026-09-11 over the 381 local transcripts that survive (2026-08-06 onward):

- **Creating notes fails.** No session created a vault note. Across all transcripts: 0 `write_note`
  or `edit_note` calls. Meanwhile auto-memory gained 42 files since the vault started (74 total).
  Example: on 2026-09-07 a vault search for "vpn" found nothing, and the resulting knowledge was
  saved to auto-memory, not the vault.
- **Updating works when a note matches the task.** On 2026-09-11 a session searched the vault, found
  the changelog-port playbook, used it, and committed two improvements to it.
- **Reads are rare:** about 5 lookups in 5 weeks.

Root causes:

1. **Same trigger, two destinations.** Both systems say "save what's non-obvious and reusable".
   Auto-memory lives in the system prompt of every session with a fixed path and always-loaded
   tools; the vault is one CLAUDE.md paragraph and its MCP tools are deferred. The save decision is
   made once and the built-in wins. Stronger wording would not change that reliably.
2. **The tier boundary is not usable at save time.** "Current sprint" vs "archival" is a horizon you
   can't know when saving. Auto-memory is full of durable facts that by the vault's own rules
   belong in the vault (auth.json leak in 18 repos, ViewieMedia NFS, BreinStraat2 `.env`).
3. **Duplication** already happened (slot-port playbook, prefer-report-over-log exist in both).

Auto-memory has gaps of its own: it is machine-local (the vault has a second machine writing to it)
and repo-specific facts load in every session without scope.

## Decisions already made (owner, 2026-09-11)

| Question | Decision |
|---|---|
| Retire or improve? | Improve the setup. |
| One shared memory folder? | Yes — the owner always launches sessions from `~`, so `-Users-jroelofs/memory` already is the de facto shared folder. Hard requirement: repo-specific memories must be recognizable as repo-specific. |
| Does the owner browse the vault? | Rarely or never. Shape for Claude's recall, not for human reading. Obsidian niceties are out of scope. |
| When do repo-specific memories load? | Only when the session is working in, or asked about, that repo. |
| Who else writes to the vault? | The owner's second machine (same setup). Memory must sync both ways. |
| Approach | A: the vault *is* auto-memory, two-level index. |
| basic-memory | Drop it. |
| Prompt trigger (reviewer suggested dropping it) | **Keep.** Questions are often answered from memory without touching repo files; a missed repo memory is the failure being fixed, a false match costs a few lines once per session. |
| Who resolves a vault sync conflict? | **Claude.** The vault holds Claude's own notes. This is an explicit exception, for the vault only, to the global "do not pull, rebase or merge on your own initiative" rule. |

## Design

### 1. Layout and loading

```
SecondBrain/                         (private git repo, commits straight to main)
├── .claude/settings.json            {"worktree":{"bgIsolation":"none"}} — lets bg jobs write memories (probe P2)
├── .gitattributes                   memory/**/MEMORY.md merge=union
├── CLAUDE.md, README.md             rewritten, short
├── 2026-06-30.md, 2026-08-05.md     the owner's own notes — untouched, never staged by hooks
└── memory/                          ← autoMemoryDirectory
    ├── MEMORY.md                    global index; the harness loads its first 200 lines / 25KB
    ├── <memory>.md                  global memories
    └── repos/<key>/
        ├── MEMORY.md                repo index; shown by the repo-memory hook
        └── <memory>.md              repo-specific memories
```

- **Setting** (user scope, both machines): `"autoMemoryDirectory": "~/GitProjects/SecondBrain/SecondBrain/memory"`
  in `~/.claude/settings.json`. Every session, from any launch directory, uses this one folder.
- **Format:** unchanged auto-memory format — frontmatter `name`, `description`, `metadata.type`
  (`user|feedback|project|reference`); feedback/project bodies carry **Why:** / **How to apply:**.
  The harness keeps stamping `modified`.
- **Repo key:** the basename of `git remote get-url origin`, `.git` stripped, lowercased
  (`IT4WEBBV/ViewieMedia` → `viewiemedia`). Slots (`ViewieMedia-2`), worktrees, directory casing and
  the other machine all resolve to the same key. Remote-less repos fall back to the lowercased
  top-level directory name. Projects without a local repo (e.g. Revolve) can still have
  `repos/<key>/`; they are reached through the prompt trigger.
- **Filing rule** — a fact that is only true inside one repo goes to `repos/<key>/` with its index
  line in that repo's `MEMORY.md`; everything else is global. It is stated in two places that are in
  context at save time: the header of the global `MEMORY.md`, and the last line of every repo-memory
  injection. Examples: "BreinStraat2 is mid-rewrite" → `repos/breinstraat2/`; "auth.json is committed
  in 18 repos", "TallDataTable selects only declared fields" (true in every consuming repo) → global.
  A misfiled fact still lives in the vault and still syncs; the cost of a miss is scope, not loss.
- **Union merge** on every `MEMORY.md`: two machines appending an index line each would otherwise
  conflict (adjacent appends at end-of-file do). Union keeps both lines; a duplicate line is harmless.

### 2. Repo-memory hook — `hooks/repo-memory.sh`

Shows a repo's memory index the first time a session (or subagent) works in, or asks about, that repo.

| Mode | Event | Fires when |
|---|---|---|
| `session` | `SessionStart` | the launch `cwd` is inside a git repo (slot / pipeline sessions). On source `compact` it first clears this session's markers, because compaction dropped the earlier injections. |
| `tool` | `PostToolUse`, matcher `Read\|Edit\|Write\|Glob\|Grep\|Bash` | a path in `tool_input` (`file_path`, `path`, or absolute / `~/` paths in a Bash `command`; relative ones resolved against payload `cwd`) resolves to a git checkout |
| `prompt` | `UserPromptSubmit` | the prompt contains a key that has a `repos/<key>/` folder, case-insensitive, bounded by non-alphanumerics |

Behaviour:

- **Parsing:** payload fields are read with `python3`'s `json` module, from `tool_input` only — never
  from `tool_response`, which for Bash carries arbitrary stdout. (`jq` is not installed; the flat
  `sed` parser in `git-freshness.sh` returns the *last* match and stops at escaped quotes, so it is
  unfit for commands and prompts.)
- **Fast path:** before spawning python, a plain `case` / `grep -q` on the raw payload exits early when
  it contains no `GitProjects` path and (in `prompt` mode) no known key.
- **Once per repo per agent:** markers in `$TMPDIR/claude-repo-memory/<session_id>/<agent_id or "main">/`.
  Hooks also run inside subagents and carry `agent_id`; keying on it stops a subagent from using up
  the main conversation's injection (auto-memory is not loaded into non-fork subagents, so they
  benefit too). A cached `toplevel → key` map keeps repeat calls into a known checkout free of git.
- **Output** is built with `json.dumps` (tabs and control characters escaped) as
  `hookSpecificOutput.additionalContext`:
  - folder exists → `Repo memory for <key> (memory/repos/<key>/; topic files load on demand):`,
    that `MEMORY.md` capped at 200 lines, then the filing-rule line;
  - no folder yet → `No repo memory for <key> yet — save facts only true in this repo under memory/repos/<key>/.`
- **Prompt false positives are accepted** (a key such as `deploy` used as a verb): one index shown once
  per session.
- Ignores the vault repo itself. Always exits 0; never blocks a tool call or a prompt.

### 3. Vault-sync hook — `hooks/vault-sync.sh`

Keeps the vault committed and in sync with origin on both machines, with **one network moment per
session boundary** and no network or rebase while the session is writing. Vault path defaults to
`~/GitProjects/SecondBrain/SecondBrain`, overridable with `VAULT_DIR` (used by the tests).

| Mode | Event | Does |
|---|---|---|
| `write` | `PostToolUse`, matcher `Write\|Edit` | only for a `file_path` under `$VAULT/memory/`, and only when no rebase/merge is in progress: `git add -- <file>` → secret scan → `git commit -m "memory: <relpath>"`. **Local only — no fetch, no rebase, no push.** |
| `session` | `SessionStart`, matcher `startup\|resume\|clear` | 1) a rebase or merge left in progress (`rebase-merge`, `rebase-apply`, `MERGE_HEAD`) is aborted **before** anything is committed — `write` never commits mid-rebase, so nothing is lost; 2) sweep: `git add -- memory/` → scan → commit `memory: sync`; 3) fetch, at most every 15 minutes (the git-freshness `FETCH_HEAD` TTL), bounded at 10 s with `BatchMode` ssh; 4) if behind: `git rebase --autostash origin/main`; on conflict abort and tell Claude to resolve (below); 5) detached push if ahead; 6) report any warning a previous `end` run left behind |
| `end` | `SessionEnd` | the sweep, then a detached push. SessionEnd cannot return context, so problems are written to `.git/vault-sync-warnings` and reported by the next `session` run. |

- **Conflicts are resolved by Claude.** With union merge on the indexes, only a topic file edited on
  both machines can conflict. The hook aborts the rebase (the vault is never left half-rebased while the
  session writes) and returns: `SecondBrain vault diverged; conflict in <files>. Resolve it now:
  rebase onto origin/main, keep both sides' facts in the markdown, continue, push.` The CLAUDE.md
  exception makes this Claude's job for the vault only.
- **Detached push:** `( GIT_TERMINAL_PROMPT=0 GIT_SSH_COMMAND="ssh -o BatchMode=yes -o ConnectTimeout=5" nohup git push -q </dev/null >/dev/null 2>&1 & )`,
  so the hook returns without waiting on the network.
- **What the hook stages:** only paths under `memory/`. The owner's root notes are never staged;
  `--autostash` keeps their uncommitted edits safe across the rebase. No `git add -A`.
- **Secret scan** over the staged diff's added lines, BSD-grep compatible (`[[:space:]]`, not `\s`):
  private-key blocks, `(password|passwd|secret|token|api[_-]?key|license[_-]?key)[[:space:]]*[:=][[:space:]]*[^[:space:]]{12,}`,
  `ghp_`, `github_pat_`, `sk_live_`, `AKIA[0-9A-Z]{16}`, `xox[abp]-`. On a hit, **only the offending
  file** is unstaged and left uncommitted; the rest of a sweep still commits. The warning names file
  and pattern, never the value.
- **Contention:** `index.lock` held by a concurrent session → retry 3× with a short backoff, then leave
  it to the next sweep. A file written but not committed is never lost — it is on disk and the next
  sweep picks it up.

### 4. Shared hook plumbing — `hooks/lib.sh`

Three hooks now need the same plumbing, so extract it and source it from all three:

- `payload_get <dotted.path>` — python3 `json`-based; `python3` missing → the calling hook no-ops
  with a single warning.
- `emit_context <event> <text>` — builds the JSON with `json.dumps`.
- `session_cache_dir` — `$TMPDIR/claude-<hook>/<session_id>/<agent_id or "main">`.
- `VAULT_DIR`.

`git-freshness.sh` switches to these (fixing its latent last-match parse and its subagent-marker bug)
and **skips the vault**: a toplevel equal to `VAULT_DIR` exits early, otherwise its edit mode would
fetch the vault concurrently with `vault-sync.sh` and tell Claude "Do NOT pull" about a repo the sync
hook is meant to pull. `hooks/tests/git-freshness-sync.test.sh` must stay green.

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
  `[[name]]` links to the new slugs, `confidence`/`evidence`/`permalink` fields are dropped.
- **Duplicates are merged:** slot-functionality-port playbook, prefer-report-over-log. The
  `second_brain_vault` memory is deleted — CLAUDE.md documents the new setup.
- **Stale memories are kept** unless clearly superseded; the harness's "this memory is N days old"
  reminder flags age at read time.
- **The migration commit is scanned** with the same secret patterns before it is committed (a
  pre-check on 2026-09-11 found no hits in either source).
- Expected size: about 35 of the 41 current memories stay global plus about 15 vault notes, so
  around 50 lines in the global index, against a 200-line cap.

Cut-over order on this machine — run in a session started with `CLAUDE_CODE_DISABLE_AUTO_MEMORY=1`,
so the migrating session cannot save into the folder it is retiring:

1. Probes P1–P5 pass (see Testing).
2. Hooks, `lib.sh` and tests merged in LaravelClaudeMd.
3. Migration commit in the vault: `memory/` tree, old folders removed, `.gitattributes`,
   `.claude/settings.json`, vault `CLAUDE.md`/`README.md` rewritten, basic-memory lines dropped
   from `.gitignore`.
4. `~/.claude/settings.json`: `autoMemoryDirectory` plus hook wiring (through the `update-config` skill).
5. Old dirs renamed `memory.bak-2026-09-11`; the harness stops reading them once the setting is set.
6. basic-memory removed: `claude mcp remove basic-memory -s user`, `uv tool uninstall basic-memory`,
   `rm -rf ~/.basic-memory` (a rebuildable cache; the markdown is the data).
7. After a week of normal use, each `.bak` dir is diffed against the ledger, then deleted.

**Rollback** at any point before step 7: remove `autoMemoryDirectory` and the hook entries from
`~/.claude/settings.json`, rename the `.bak` dirs back, `git revert` the vault migration commit.

**Second machine:** a global playbook memory, "migrate a machine's local auto-memory into the
vault", lets one session on that machine run steps 4–7 and classify and merge its local memories
against what's already in the vault. The owner starts that session; the hooks take it from there.

### 6. Instructions

- **`CLAUDE.md`** — replace both "Second Brain" sections with one short "Memory (SecondBrain vault)"
  section: where memory lives, the two-level index, the filing rule and key derivation, no secrets
  (the scan is a backstop, not a licence), the hooks do the git work, and **the vault exception to the
  no-rebase rule** (Claude resolves vault sync conflicts itself). Bootstrap steps: clone the vault, set
  `autoMemoryDirectory`, wire the hooks (JSON below), run the migration playbook. Drop the basic-memory
  install steps and "pull the vault at session start".
- **Vault `CLAUDE.md` / `README.md`** — what `memory/` is, that Claude writes it through
  auto-memory, that the hooks commit to main, that root notes belong to the owner.
- **Harness memory instructions** are untouched; only the directory moves.

Hook wiring, added next to the existing git-freshness entries:

```json
"autoMemoryDirectory": "~/GitProjects/SecondBrain/SecondBrain/memory",
"hooks": {
  "SessionStart": [
    { "matcher": "startup|resume|clear", "hooks": [ { "type": "command", "command": "$HOME/GitProjects/LaravelClaudeMd/LaravelClaudeMd/hooks/vault-sync.sh session", "timeout": 20 } ] },
    { "hooks": [ { "type": "command", "command": "$HOME/GitProjects/LaravelClaudeMd/LaravelClaudeMd/hooks/repo-memory.sh session", "timeout": 5 } ] }
  ],
  "UserPromptSubmit": [ { "hooks": [ { "type": "command", "command": "$HOME/GitProjects/LaravelClaudeMd/LaravelClaudeMd/hooks/repo-memory.sh prompt", "timeout": 5 } ] } ],
  "PostToolUse": [
    { "matcher": "Write|Edit", "hooks": [ { "type": "command", "command": "$HOME/GitProjects/LaravelClaudeMd/LaravelClaudeMd/hooks/vault-sync.sh write", "timeout": 10 } ] },
    { "matcher": "Read|Edit|Write|Glob|Grep|Bash", "hooks": [ { "type": "command", "command": "$HOME/GitProjects/LaravelClaudeMd/LaravelClaudeMd/hooks/repo-memory.sh tool", "timeout": 5 } ] }
  ],
  "SessionEnd": [ { "hooks": [ { "type": "command", "command": "$HOME/GitProjects/LaravelClaudeMd/LaravelClaudeMd/hooks/vault-sync.sh end", "timeout": 20 } ] } ]
}
```

## Error handling

- Hooks always exit 0. Problems surface as a one-line `additionalContext` warning, at most once per
  session per problem; they never block a tool call or a prompt.
- Vault not cloned or `python3` missing → the hook no-ops with a single warning per session.
- Secret found → that file is not committed; the warning names file and pattern.
- Sync conflict → rebase aborted, local commits kept, Claude told to resolve.
- Anything detected at `SessionEnd` → stored in `.git/vault-sync-warnings`, reported next session.

## Testing

**Probes before anything is built** — each is go/no-go; throwaway, reverted after:

| # | Question | How | If it fails |
|---|---|---|---|
| P1 | Is `autoMemoryDirectory` honoured, with `~` expansion, on 2.1.268? Do `modified` stamping and the age reminder also work for files under `memory/repos/`? | set it to a scratch dir, start a session, check the memory path in its instructions and `/context`; write and re-read a nested memory | approach A is off — stop |
| P2 | Can a bg job rooted at `~` write a memory into the vault? The permissions docs say project settings load only from the cwd's `.claude/` (no parent fallback), which contradicts the 2026-07-06 observation that the guard walks up from the edited file. | one throwaway Write from a `claude --bg` session, with and without the vault's `.claude/settings.json` | fall back to a work tree outside any checkout (`~/.claude/memory` as work tree, `git --git-dir` pointing at the vault), or `bgIsolation: none` in `~/.claude/settings.local.json` with owner approval |
| P3 | Can a session inside an `EnterWorktree` worktree of another repo write a memory into the vault? | one throwaway Write from such a session | same fallback as P2 |
| P4 | Does `additionalContext` from `PostToolUse`, `UserPromptSubmit` and `SessionStart` reach the model, and do subagent hook payloads carry `agent_id`? | a stub hook that emits a marker string and logs its payload | redesign the delivery channel |
| P5 | Does a `SessionStart` hook run before the harness reads `MEMORY.md`? | the stub appends a marker line to a scratch `MEMORY.md`; check whether the same session sees it | accept that the other machine's memories arrive one session late; say so in CLAUDE.md |

**Hook tests** in the style of `git-freshness-sync.test.sh` (throwaway repos under `$TMPDIR`):

- `repo-memory.test.sh`: key from remote / no remote / slot worktree / casing; once per session and
  per agent; `compact` clears markers; prompt match and non-match; Bash path extraction from
  `tool_input` while `tool_response` contains a decoy path; vault excluded; missing-folder nudge;
  200-line cap; an index containing a tab still yields valid JSON.
- `vault-sync.test.sh`: commit on write under `memory/`; root notes ignored; `write` skipped mid-rebase;
  stale rebase aborted before the sweep commits; secret refusal per pattern, only the offending file
  held back; two machines appending to `MEMORY.md` merge by union; topic-file conflict → abort +
  Claude-facing message; push rejected → retried next session; the hook returns before a slow push
  finishes; `index.lock` contention; owner's uncommitted root note survives the rebase; a secret found
  at `end` is reported by the next `session`.
- `git-freshness-sync.test.sh`: still green after the `lib.sh` extraction; new case — vault edits are
  ignored.

**After cut-over:** the ledger accounts for every source note; the global `MEMORY.md` is under 200
lines; touching ViewieMedia shows its index and an unrelated session does not; a new memory appears
committed and pushed; the second machine sees it at its next session start (or the one after, per P5).

## Success criteria (checked after two weeks)

- `git -C <vault> log --grep '^memory:' --since=2.weeks` shows memories written during normal work,
  without anyone asking for them.
- Repo-specific memories show up only in sessions that touched or named that repo.
- Both machines hold the same memories.

## Rejected alternatives

- **B — vault for repo notes only, auto-memory stays local.** Saving repo facts stays a
  which-system choice, the thing that fails today; global memories never reach the second machine.
- **C — disable auto-memory, basic-memory as the only store.** Saving would depend on CLAUDE.md
  wording and deferred MCP tools, the mechanism that failed; loses the harness's index-size warnings.
- **User-level path-scoped rules instead of `repo-memory.sh`** (reviewer's alternative 3). They fire
  only when Claude reads a matching file — not on Bash calls or prompts naming the repo — and their
  `paths:` matching from a `~` launch is unverified.
- **Dropping the prompt trigger and Bash path detection** (reviewer's alternative 1). Simpler, but
  misses the question-answered-from-memory case; see decisions.
- **Pushing from `write` mode.** A background `pull --rebase` while the session keeps writing can leave
  the vault mid-rebase, and a later abort then drops commits (reviewer's blocker B1). Network work
  happens only at session boundaries.

## Non-goals

- Human browsing, Obsidian graph/links, semantic search.
- Cloud / claude.ai sessions (local hooks don't run there).
- Syncing settings.json or transcripts across machines.
- Sharing memory with colleagues (the vault is personal and private).
- Changing what the harness decides to save.

## Risks

1. **Guards vs a git-repo memory dir** (P2, P3). Covered by the probes and a named fallback.
2. **Hook cost on every tool call.** The shell fast path must keep `repo-memory.sh tool` to tens of
   milliseconds when python isn't needed; measure during implementation.
3. **Misfiled repo facts** landing in the global index. Visible there; the harness's index-size
   warnings prompt a tidy.
4. **Global index growth:** about 50 lines at cut-over; the 200-line cap is months away and the harness
   warns before it is reached.
5. **Secret-scan false negatives.** The scan backs up the rule; it doesn't replace it.
6. **Claude resolving sync conflicts** could merge two contradicting facts badly. Bounded: only topic
   files can conflict, both sides stay in git history, and the resolution is itself a reviewable commit.
