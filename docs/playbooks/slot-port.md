# Porting slot functionality into a project

"Slot functionality" mounts a git worktree into its own isolated Docker stack, so several branches
run side by side, each a "slot". A slot is a sibling directory `<Repo>-N` (N = 2..20); slot 1 is the
primary checkout. The identity is the **directory name at runtime**, never stored in a file. Pure
shell + docker compose + env templating, no PHP. Using slots day to day: the `slots` skill.

## Source: LaravelTemplate, not Deploy

Port from `~/GitProjects/LaravelTemplate/LaravelTemplate`. Deploy uses the old `+10*(N-1)` port
stride with a `${PORT}0` test-port string concat, which collides on multi-database projects and
concatenated test ports. LaravelTemplate replaced it with collision-proof high bands and a dedicated
`worktree.sh` with scripted teardown. Specs: `docs/superpowers/specs/2026-06-01-collision-proof-slot-ports-design.md`
and the worktree-lifecycle design next to it.

## Requirement: Docker Compose 2.17 or newer

```bash
docker compose version          # below 2.17.x: affected
docker exec <project>-N_web printenv | grep DB_HOST
# broken: DB_HOST=<project>_db     (base name)
# fixed:  DB_HOST=<project>-N_db   (slot name)
```

Below v2.17.x every slot stack injects the **base** project's database hostnames into its app
containers, so anything run through `docker exec` in a slot targets slot 1's database.
`container/.env` is both Compose's substitution source and an `env_file:` for the app services; old
Compose resolves `${...}` inside an env_file from the file's own assignments, so the exports from
`scripts/slot-env.sh` lose. The container is *named* `<project>-N_web` but *thinks* it is
`<project>`. `pma` gets the right host because `PMA_HOST` comes from an `environment:` block.

| Compose | `DB_HOST` resolves from |
|---|---|
| 2.4.1 / 2.10.2 / 2.13.0 / 2.16.0 | the in-file assignment: broken |
| 2.18.1 / 2.20.3 / 2.24.0 / 2.29.7 / 5.3.1 | the shell env: correct |

- Only `docker exec` is affected. The web app and queue worker are fine: `build/web/ubuntu_php/dev/run.sh`
  runs `source .env` on the per-slot `/var/www/.env`, which re-exports the corrected values.
- The danger is `php artisan test`: `RefreshDatabase` runs `migrate:fresh`, which drops every table in
  whatever database it reaches, so a slot's test run destroys a running base stack's test database.
- Fix by upgrading Docker Desktop, not by patching repos: the upgrade corrects every derived
  variable (`WEB_VHOSTS`, `PMA_CONTAINER`, container names). Run `restart.sh` once per slot afterwards.
- The upgrade removes Compose v1: scripts calling `docker-compose` (Deploy's start/stop/restart.sh)
  break until switched to `docker compose`.
- On macOS, do not run any docker command during a drag-install of Docker Desktop: the copy is not
  atomic, and a CLI call mid-copy corrupts the bundle into a misleading "Docker is damaged" dialog.

## Port-band math

The registry `DB_LOCAL_BINDED_PORT` must be 3300..3599. `OFFSET=(DB_base-3300)*20+(N-1)`; slot
DB = `10000+OFFSET`, test = `16000+OFFSET`, vite = `22000+OFFSET`. Bands `28000` and `34000` are
reserved for extra per-project ports.

## Files that travel together

`scripts/slot-env.sh`, `worktree.sh`, `stop.sh`, `restart.sh`, `start.sh`, `install-packages.sh`,
`generate_composer_override.php`, `generate_repositories.php`, `tests/test-slot-env.sh`,
`tests/test-worktree.sh`; `container/.env` (`COMPOSE_PROJECT_NAME`, `GITREPOSITORY`,
`DB_LOCAL_BINDED_PORT`, `DB_TEST_BINDED_PORT`, `VITE_PORT`), `container/docker-compose.dev.yml`
(all names and ports as `${...}`, `backend` external network, named volumes),
`docker-compose.pgk.yml`; `code/www/.env.example` (envsubst placeholders), and `vite.config`
(`process.env.WEB_VITE_VHOST` as HMR host).

## Gotchas

- `slot-env.sh` is **sourced**, so the caller needs `set -a` (use `set -au`). Re-derive every
  container name the project actually has (a second database, clamav) and don't reference ones it
  lacks (no `migrations`).
- A second database (GmTool's `db_activitylog`) needs its own port band (28000) or every slot
  collides. Compute `OFFSET` before reassigning `DB_LOCAL_BINDED_PORT`.
- Add an explicit `DB_TEST_BINDED_PORT` to `container/.env` and replace any `${DB_LOCAL_BINDED_PORT}0`
  concat in compose.
- The `restart.sh` envsubst allowlist must match the `.env.example` placeholders exactly, or
  Laravel's own `${APP_NAME}` and friends get blanked. Render `APP_URL=https://${WEB_VHOST}`, all
  `DB_*_HOST` and `VITE_PORT`.
- **Also fix the test container's `build/web/ubuntu_php/test/run.sh`.** Once `.env.example` is an
  envsubst template, a `cp .env.example .env` there leaves `APP_URL=https://${WEB_VHOST}` literal:
  Symfony throws `Invalid Host "${web_vhost}"` and every feature test fails in CI. Mirror
  `restart.sh`: `: "${WEB_VHOST:=<primary vhost>}"` (plus DB_HOST, DB_HOST_TEST, extra DB hosts,
  VITE_PORT defaults), export, then `envsubst '...' < .env.example > .env`. CI's
  `docker run -e DB_HOST_TEST=mysql` still wins through `:=`. The dev container does not cp (the host
  `restart.sh` pre-renders `code/www/.env`), so only the test container needs this. Only CI or
  building the test image catches it.
- Replace hardcoded `~/GitProjects/<Repo>/<Repo>/...` paths in restart/start with a relative
  `PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"`.
- `-p` on a fresh slot: generate `composer.pgk.json` on the host (`generate_composer_override.php`
  through `install-packages.sh`) and delete the in-container `php artisan composer:rewrite` from
  `run.sh` (there is no `vendor/` yet, so it can't boot). Keep `rm -f composer.pgk.lock`.
- The Vite HMR host must follow the slot: pass `WEB_VITE_VHOST` through the web service's
  `environment:` block and read it in the vite config with a primary fallback.
- `worktree.sh remove` needs the macOS `chmod -RN "$slot_dir"` ACL strip before `git worktree remove`
  (VirtioFS deny-delete on the `-p` packages mount).
- FontAwesome `.npmrc`: LaravelTemplate generates one and validates `~/.secrets`. Skip it when the
  project doesn't use FA Pro (GmTool doesn't).
- The slot tests aren't in CI: run `bash scripts/tests/test-slot-env.sh` and `test-worktree.sh` by
  hand after editing.
- The worker needs `restart: on-failure` in `docker-compose.dev.yml` when the web container runs
  `composer install`: on a fresh slot the worker starts `queue:listen` before `vendor/` exists and
  exits 255. (LaravelTemplate uses a `migrations` job plus `depends_on` instead.)
- `-p` depends on the local package checkouts being on a compatible branch: `composer.pgk.json` pins
  the it4web packages to the local path, so a checkout on a newer major (Livewire ^4 against a
  project on ^3) fails `composer install`. That is branch hygiene, not a slot bug; don't switch the
  developer's package branches.

## Verify with a real boot

Unit tests, `docker compose config` and a clean envsubst render all pass while a runtime bug
remains. Boot a real `<Repo>-2` slot (`worktree add --detach` at the feature commit, then
`restart.sh`). Reference port: GmTool, 2026-06-24, branch `worktree-slot-functionality` (base 3456 →
slot 2: db 13121, test 19121, vite 25121, activitylog 31121), serving HTTP 200 at
`https://gmtool-2.it4web.net`.
