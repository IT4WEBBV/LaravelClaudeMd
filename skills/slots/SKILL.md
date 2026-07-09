---
name: slots
description: >
  Use when the user asks to start / spin up / "use a slot", or to stop /
  teardown / remove / kill a slot, on an it4web Laravel project. Slots are
  parallel Docker stacks of one project, each from its own git worktree
  (<Project>-N). IMPORTANT: stop / teardown / kill a slot ALWAYS means full
  destructive removal here (containers, volumes, database, worktree) — NEVER a
  keep-data stop.sh.
---

# Slots — parallel dev stacks

A **slot** is one Docker stack of a project run from its own git worktree, so
branches run side-by-side with their own URL, database, and host ports.

- `<Project>` = **slot 1**, the primary checkout. Normal rules; **never a
  teardown target**.
- `<Project>-N` (N = 2..20) = **slot N**. The directory basename *is* the slot
  identity — **never rename a slot dir**.

## The interface

Every it4web slot project drives the lifecycle through **`scripts/worktree.sh`**:

- create   = `./scripts/worktree.sh create <branch>`
- teardown = `./scripts/worktree.sh remove <slot>`

Read its usage header; don't reimplement it. If a project has no
`scripts/worktree.sh`, it isn't slot-enabled — say so rather than improvising.

For **ports and URLs**, each project's own `scripts/slot-env.sh` is the source of
truth — the port scheme differs per project. Read them from the slot's env; never
hardcode or copy another project's numbers.

## Start — "use a slot", "spin up a slot"

1. Get the branch (ask if not given). Run `./scripts/worktree.sh create <branch>`
   **from the primary worktree** (add `-p` for locally-mounted it4web packages).
   Let the script enforce its own rules (primary-only, next free slot, fresh-trunk
   branching, safety checks) — don't pre-model them.
2. Report where it's reachable by re-sourcing the slot's env (works on any
   project, can't drift):

   ```bash
   cd <slot-dir>
   ( set -a; PROJECT_ROOT="$PWD"; source container/.env; source scripts/slot-env.sh
     echo "URL:     https://$WEB_VHOST"
     echo "DB port: $DB_LOCAL_BINDED_PORT" )
   docker ps --filter "name=$(basename "$PWD" | tr '[:upper:]' '[:lower:]')" --format '{{.Names}}\t{{.Ports}}'
   ```

## Teardown — "teardown" / "stop" / "remove" / "kill" the slot

**Full destructive removal, always. This user never wants a keep-data stop — do
NOT run `stop.sh`.**

1. Identify the exact slot (`git worktree list`, `docker ps`). **Confirm the
   directory with the user and state that its database and volumes will be
   destroyed** before running anything.
2. `./scripts/worktree.sh remove <slot>` — handles the `down -v`, the macOS ACL
   workaround, and `git worktree remove`. Run it from inside the slot (no arg →
   the current slot) or from the primary (the `<slot>` arg is required).
3. **Never delete the local branch** unless the user explicitly asks (that is
   `--force-local-branch-removal` / `git branch -D` — it can drop unmerged work).

## Red flags — you're about to get it wrong

- Reaching for `stop.sh` because the user said "stop" → WRONG. Stop = destroy.
- Computing or guessing a slot's ports instead of re-sourcing `slot-env.sh` → the
  scheme differs per project.
- Running teardown without naming the exact dir and confirming the data loss.
- `git branch -D` / `--force-local-branch-removal` when the user didn't ask.

## Rationalizations to reject

| Excuse | Reality |
|--------|---------|
| "They said 'for now' / 'stop', so keep the data (`stop.sh`)" | For this user stop/teardown = destroy. `stop.sh` is never the answer. |
| "The slot's ports follow LaravelTemplate's scheme" | Port bases differ per project. Re-source the slot's own `slot-env.sh`; never copy numbers. |
