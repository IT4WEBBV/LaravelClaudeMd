# SecondBrain vault as auto-memory — design

**Date:** 2026-09-11
**Status:** draft → review (Fable) → user review → implementation plan → PR
**Canonical home:** `IT4WEBBV/LaravelClaudeMd` — `hooks/`, `CLAUDE.md`. Storage lives in the private vault repo `jonneroelofs/SecondBrain`.
**Replaces:** the "Second Brain (basic-memory archival memory)" and "Second Brain (multi-machine setup)" sections of `CLAUDE.md`.

## Summary

Point Claude Code's auto-memory at the SecondBrain vault (`autoMemoryDirectory`), so the save path
Claude reliably uses lands in a git-synced repo shared by both machines. Split memory into a
**global index** (loaded every session, as today) and **per-repo indexes** that a hook shows the
first time a session touches that repo. A second hook commits and pushes every memory write.
basic-memory is removed.

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
| When do repo-specific memories load? | Only when the session is working in that repo. |
| Who else writes to the vault? | The owner's second machine (same setup). Memory must sync both ways. |
| Approach | A: the vault *is* auto-memory, two-level index. (Rejected: B — vault for repo notes only, auto-memory stays local; C — disable auto-memory, basic-memory as the only store.) |
| basic-memory | Drop it. |

## Design

### 1. Layout and loading

```
SecondBrain/                         (private git repo, commits straight to main)
├── .claude/settings.json            {"worktree":{"bgIsolation":"none"}} — lets bg jobs write memories
├── CLAUDE.md, README.md             rewritten, short
├── 2026-06-30.md, 2026-08-05.md     the owner's own notes — untouched, never committed by hooks
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
- **Filing rule**, stated in the header of the global `MEMORY.md` so it is in context at every save:
  a fact that is only true inside one repo goes to `repos/<key>/` and gets its index line in that
  repo's `MEMORY.md`; everything else is global. Examples: "BreinStraat2 is mid-rewrite" →
  `repos/breinstraat2/`; "auth.json is committed in 18 repos", "TallDataTable selects only declared
  fields" (true in every consuming repo) → global.
- **Why a hook and not path-scoped rules:** user-level `~/.claude/rules/*.md` with `paths:`
  frontmatter were considered. They fire only when Claude *reads* a matching file (not on Bash
  calls or prompts that name the repo), they would move repo memory out of the auto-memory folder
  and format into a second location, and it is unverified how their `paths:` patterns match files
  outside the launch directory's project root when launching from `~`.

### 2. Repo-memory hook — `hooks/repo-memory.sh`

Shows a repo's memory index the first time a session works in, or talks about, that repo.

| Mode | Event | Fires when |
|---|---|---|
| `session` | `SessionStart` | the launch `cwd` is inside a git repo (slot / pipeline sessions) |
| `tool` | `PostToolUse`, matcher `Read\|Edit\|Write\|Glob\|Grep\|Bash` | a path in `tool_input` (`file_path`, `path`, or absolute/`~/` paths in a Bash `command`; relative ones resolved against payload `cwd`) resolves to a git checkout |
| `prompt` | `UserPromptSubmit` | the prompt names a key that has a `repos/<key>/` folder (case-insensitive, bounded by non-alphanumerics) |

Behaviour:

- **Once per repo per session**, tracked in `$TMPDIR/claude-repo-memory/<session_id>/`. A cached
  `toplevel → key` map makes repeat calls into a known checkout a string-prefix check — no git call.
- **Output** via `hookSpecificOutput.additionalContext`:
  - folder exists → `Repo memory for <key> (memory/repos/<key>/; topic files load on demand):` followed by
    that `MEMORY.md`, capped at 200 lines like the harness cap;
  - no folder yet → one line: `No repo memory for <key> yet — save facts only true in this repo under memory/repos/<key>/.`
- Ignores the vault repo itself. Always exits 0; never blocks a tool call.

### 3. Vault-sync hook — `hooks/vault-sync.sh`

Keeps the vault committed, pushed and current on both machines. Vault path defaults to
`~/GitProjects/SecondBrain/SecondBrain`, overridable with `VAULT_DIR` (used by the tests).

| Mode | Event | Does |
|---|---|---|
| `session` | `SessionStart` | 1) sweep leftovers from a crashed session (`git add -- memory/` → scan → commit `memory: sync`); 2) `git pull --rebase --autostash`; on conflict `git rebase --abort` and warn; 3) push unpushed commits in the background |
| `write` | `PostToolUse`, matcher `Write\|Edit` | only for a `file_path` under `$VAULT/memory/`: `git add -- <file>` → secret scan → `git commit -m "memory: <relpath>"` → background `git pull --rebase -q && git push -q` |
| `end` | `SessionEnd` | the same sweep as `session` step 1 (catches deletes and Bash edits), then push |

- **Scope of what the hook commits:** only paths under `memory/`. The owner's root notes are never
  staged; `--autostash` keeps their uncommitted edits safe across pulls. No `git add -A` anywhere.
- **Secret scan** over the staged diff's added lines: private-key blocks,
  `(password|passwd|secret|token|api[_-]?key|license[_-]?key)\s*[:=]\s*\S{12,}`, `ghp_`/`github_pat_`,
  `sk_live_`, `AKIA[0-9A-Z]{16}`, `xox[abp]-`. On a hit: unstage, don't commit, and return
  `additionalContext` naming the file and pattern (never the value) so Claude removes it. The file stays
  on disk uncommitted; the next sweep re-scans it.
- **Contention:** `index.lock` held by a concurrent session → retry 3× with a short backoff, then leave
  it to the next sweep.
- **Offline / push rejected:** commits stay local; the next `session` run rebases and pushes.
- **Rebase conflict** (both machines edited the same `MEMORY.md` lines): abort, keep local commits,
  warn once per session. One-line index entries rebase cleanly in the common case.

### 4. Shared hook plumbing — `hooks/lib.sh`

`git-freshness.sh` already parses the hook payload without `jq` (not installed) and emits
`hookSpecificOutput` with `printf`. With three hooks needing that, extract `payload_field`,
`emit_context` and the per-session cache helper into `hooks/lib.sh` and source it from all three.
`hooks/tests/git-freshness-sync.test.sh` must stay green after the extraction.

### 5. Migration (one-off)

Sources:

| Source | Count | State |
|---|---|---|
| `~/.claude/projects/-Users-jroelofs/memory` | 41 | current |
| 8 other per-repo memory dirs (Asimo, ViewieMedia, Deploy, car-charger, GitProjects, BreinStraat2, LaravelClaudeMd, talldatatable) | 33 | stale — newest files April–July |
| Vault notes (`decisions/`, `packages/`, `playbooks/`, `projects/`, `references/`) | 27 | basic-memory format |
| The second machine's local auto-memory | unknown | migrated there, see below |

Rules:

- **Every source note gets exactly one recorded outcome** — moved global / moved `repos/<key>` /
  merged into `<name>` / dropped (reason). The ledger goes in the vault migration commit's message.
  No silent drops.
- **Vault notes are converted** to the auto-memory format: `## Observations` become plain bullets,
  `## Relations` become `[[name]]` links, `confidence`/`evidence`/`permalink` fields are dropped.
- **Duplicates are merged:** slot-functionality-port playbook, prefer-report-over-log. The
  `second_brain_vault` memory is deleted — CLAUDE.md documents the new setup.
- **Stale memories are kept** unless clearly superseded; the harness's "this memory is N days old"
  reminder flags age at read time.
- Expected size: about 35 of the 41 current memories stay global plus about 15 vault notes, so
  around 50 lines in the global index, against a 200-line cap.

Cut-over order on this machine:

1. Probes P1–P4 pass (see Testing).
2. Hooks and tests merged.
3. Migration commit in the vault: `memory/` tree, old folders removed, vault `CLAUDE.md`/`README.md`
   rewritten, `.claude/settings.json` added, basic-memory lines dropped from `.gitignore`.
4. `~/.claude/settings.json`: `autoMemoryDirectory` plus hook wiring (through the `update-config` skill).
5. Old dirs renamed `memory.bak-2026-09-11` (the harness stops reading them once the setting is set).
6. basic-memory removed: `claude mcp remove basic-memory -s user`, `uv tool uninstall basic-memory`,
   `rm -rf ~/.basic-memory` (a rebuildable cache; the markdown is the data).
7. After a week of normal use without needing them, the `.bak` dirs are deleted.

**Second machine:** a global playbook memory, "migrate a machine's local auto-memory into the
vault", lets one session on that machine do steps 4–7 plus classify and merge its local memories
against what's already in the vault. The owner starts that session; the vault's hooks take it from
there.

### 6. Instructions

- **`CLAUDE.md`** — replace both "Second Brain" sections with one short "Memory (SecondBrain vault)"
  section: where memory lives, the two-level index, the filing rule and key derivation, no secrets
  (the scan is a backstop, not a licence), hooks do the git work. Bootstrap steps: clone the vault,
  set `autoMemoryDirectory`, wire the hooks (JSON below), run the migration playbook. Drop the
  basic-memory install steps and "pull the vault at session start" (the hook does it).
- **Vault `CLAUDE.md` / `README.md`** — what `memory/` is, that Claude writes it through
  auto-memory, that the hooks commit to main, that root notes belong to the owner.
- **Harness memory instructions** are untouched; only the directory moves.

Hook wiring, added next to the existing git-freshness entries:

```json
"autoMemoryDirectory": "~/GitProjects/SecondBrain/SecondBrain/memory",
"hooks": {
  "SessionStart":     [ { "hooks": [ { "type": "command", "command": "$HOME/GitProjects/LaravelClaudeMd/LaravelClaudeMd/hooks/vault-sync.sh session", "timeout": 20 },
                                     { "type": "command", "command": "$HOME/GitProjects/LaravelClaudeMd/LaravelClaudeMd/hooks/repo-memory.sh session", "timeout": 5 } ] } ],
  "UserPromptSubmit": [ { "hooks": [ { "type": "command", "command": "$HOME/GitProjects/LaravelClaudeMd/LaravelClaudeMd/hooks/repo-memory.sh prompt", "timeout": 5 } ] } ],
  "PostToolUse": [
    { "matcher": "Write|Edit", "hooks": [ { "type": "command", "command": "$HOME/GitProjects/LaravelClaudeMd/LaravelClaudeMd/hooks/vault-sync.sh write", "timeout": 10 } ] },
    { "matcher": "Read|Edit|Write|Glob|Grep|Bash", "hooks": [ { "type": "command", "command": "$HOME/GitProjects/LaravelClaudeMd/LaravelClaudeMd/hooks/repo-memory.sh tool", "timeout": 5 } ] }
  ],
  "SessionEnd":       [ { "hooks": [ { "type": "command", "command": "$HOME/GitProjects/LaravelClaudeMd/LaravelClaudeMd/hooks/vault-sync.sh end", "timeout": 20 } ] } ]
}
```

## Error handling

- Hooks always exit 0. Problems surface as a one-line `additionalContext` warning, at most once per
  session per problem; they never block a tool call or a prompt.
- Vault not cloned → both hooks no-op with a single warning per session.
- Secret found → nothing committed, the warning names the file and pattern.
- Rebase conflict → aborted, local commits kept, warning; the owner resolves.

## Testing

**Probes before anything is built** — each is go/no-go for approach A; throwaway, reverted after:

| # | Question | How |
|---|---|---|
| P1 | Is `autoMemoryDirectory` honoured, with `~` expansion, on 2.1.268? | set it to a scratch dir, start a session, check the memory path in its instructions and `/context` |
| P2 | Can a bg job rooted at `~` write a memory into the vault once the vault has `.claude/settings.json` `bgIsolation: none`? | one throwaway Write from a `claude --bg` session |
| P3 | Can a session inside an `EnterWorktree` worktree of another repo write a memory into the vault? | one throwaway Write from such a session |
| P4 | Does `additionalContext` from `PostToolUse` and `UserPromptSubmit` reach the model? | a stub hook that emits a marker string |

**Hook tests** in the style of `git-freshness-sync.test.sh` (throwaway repos under `$TMPDIR`):

- `repo-memory.test.sh`: key from remote / no remote / slot worktree / casing; once per session;
  prompt match vs non-match; Bash path extraction; vault excluded; missing-folder nudge; 200-line cap.
- `vault-sync.test.sh`: commit on write under `memory/`, ignore root notes, secret refusal (each pattern),
  crashed-session sweep, rebase conflict → abort + warn, rejected push → retried at next session,
  `index.lock` contention, owner's uncommitted root note survives a pull.

**After cut-over:** the ledger accounts for every source note; the global `MEMORY.md` is under 200 lines;
touching ViewieMedia shows its index and an unrelated session does not; a new memory appears
committed and pushed; the second machine sees it at its next session start.

## Success criteria (checked after two weeks)

- `git -C <vault> log --grep '^memory:' --since=2.weeks` shows memories written during normal work,
  without anyone asking for them.
- Repo-specific memories show up only in sessions that touched or named that repo.
- Both machines hold the same memories.

## Non-goals

- Human browsing, Obsidian graph/links, semantic search.
- Cloud / claude.ai sessions (local hooks don't run there).
- Syncing settings.json or transcripts across machines.
- Sharing memory with colleagues (the vault is personal and private).
- Changing what the harness decides to save.

## Risks

1. **Guards vs a git-repo memory dir** (P2, P3). If writes into the vault are refused in bg jobs or
   worktree sessions, approach A needs a different storage path. This is why the probes run first.
2. **Hook cost on every tool call.** The fast path must keep `repo-memory.sh tool` to tens of
   milliseconds; measure during implementation.
3. **Misfiled repo facts** landing in the global index. They're visible there; the harness's
   index-size warnings prompt a tidy.
4. **Global index growth:** about 50 lines at cut-over; at the current pace the 200-line cap is
   months away, and the harness warns before it's reached.
5. **Secret-scan false negatives.** The scan backs up the rule; it doesn't replace it.
