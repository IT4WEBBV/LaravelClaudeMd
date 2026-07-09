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

Each project's own `CLAUDE.md` + `scripts/slot-env.sh` are the source of truth
for its URLs, ports, and dir naming. **Discover; never assume another project's
scheme.**

## Discover the slot interface first

Slot tooling comes in two shapes. Detect which before doing anything:

- **`scripts/worktree.sh` exists** → create = `./scripts/worktree.sh create <branch>`,
  teardown = `./scripts/worktree.sh remove <slot>`. It is the interface — read
  its usage header; do not reimplement it.
- **No `worktree.sh`, but `scripts/restart.sh` accepts `--worktree`** → create =
  `./scripts/restart.sh --worktree <branch>`; teardown is manual (see below).
- **Only `scripts/slot-env.sh`, no create path** → the project can run as a slot
  but has no create helper. Do it by hand and offer to port `worktree.sh`.
- **None of these** → not a slot project. Say so; don't invent one.

## Start — "use a slot", "spin up a slot"

1. Get the branch (ask if not given). Run the project's create command **from the
   primary worktree**. Add `-p` for locally-mounted it4web packages. Let the
   script enforce its own rules (primary-only, next free slot, safety checks) —
   don't pre-model them.
2. Report where it's reachable. Re-source the slot's env rather than guessing
   ports — this works on any slot-capable project and can't drift:

   ```bash
   cd <slot-dir>
   ( set -a; PROJECT_ROOT="$PWD"; source container/.env; source scripts/slot-env.sh
     echo "URL:      https://$WEB_VHOST"
     echo "DB port:  $DB_LOCAL_BINDED_PORT" )
   docker ps --filter "name=$(basename "$PWD" | tr '[:upper:]' '[:lower:]')" --format '{{.Names}}\t{{.Ports}}'
   ```

## Teardown — "teardown" / "stop" / "remove" / "kill" the slot

**Full destructive removal, always. This user never wants a keep-data stop — do
NOT run `stop.sh`.**

1. Identify the exact slot (`git worktree list`, `docker ps`). **Confirm the
   directory with the user and state that its database and volumes will be
   destroyed** before running anything.
2. **Gen-2** (`worktree.sh`): `./scripts/worktree.sh remove <slot>` — handles
   `down -v`, the macOS ACL workaround, and `git worktree remove`.
3. **Gen-1** (no `worktree.sh`): from inside the slot
   `cd container && docker compose -f docker-compose.dev.yml down -v`, then from
   the primary `chmod -RN <slot-dir>` (macOS) and
   `git worktree remove --force <slot-dir>`. Offer to port `worktree.sh` so next
   time is one command.
4. **Never delete the local branch** unless the user explicitly asks (that is
   `--force-local-branch-removal` / `git branch -D` — it can drop unmerged work).

## Red flags — you're about to get it wrong

- Reaching for `stop.sh` because the user said "stop" → WRONG. Stop = destroy.
- Assuming `worktree.sh` exists, or hardcoding another project's ports/URL →
  discover the interface first.
- Running teardown without naming the exact dir and confirming the data loss.
- `git branch -D` / `--force-local-branch-removal` when the user didn't ask.

## Rationalizations to reject

| Excuse | Reality |
|--------|---------|
| "They said 'for now' / 'stop', so keep the data (`stop.sh`)" | For this user stop/teardown = destroy. `stop.sh` is never the answer. |
| "worktree.sh must exist / the ports follow LaravelTemplate's scheme" | It's two shapes and per-project port schemes. Discover, don't assume. |
| "I'll just `git worktree add` manually to create it" | Both shapes have a create command (`worktree.sh create` or `restart.sh --worktree`). Use it. |
