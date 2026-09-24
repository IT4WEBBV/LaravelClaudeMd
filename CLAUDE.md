# Claude Code Instructions

> This file lives in the `LaravelClaudeMd` repo and is symlinked to `~/.claude/CLAUDE.md`. I work on
> two machines: anything that has to reach both belongs in a repo, not in one machine's settings or
> memory.

## Docker Environment

Every project runs in Docker Compose. Never run application commands on the host.

- Start or restart a project with `./scripts/restart.sh`, never with `docker compose` by hand. `-p`
  mounts the it4web packages locally, so they can be worked on from the project.
- Containers are named `${COMPOSE_PROJECT_NAME}_<service>`. The name is in `container/.env` (next to
  the compose files), as is the host port the database is mapped to. Services: `web` (artisan,
  composer, npm, tests), `db` (MariaDB), `db_test`, `worker` (queue).
- The code in `code/www/` is mounted at `/var/www`. Inside the containers the database host is the
  container name: `viewiemedia_db`, `viewiemedia_db_test`.
- it4web/* packages run their own tests through their Makefile; a package without one should get one.

```bash
docker exec {project}_web php artisan migrate
docker exec {project}_web php artisan c:d
docker exec {project}_web php artisan test
docker exec -it {project}_web bash
```

## Programming Philosophy

- Think first, act later: while we are still discussing, do not start building. Present a plan I can
  approve first — what we build, the existing code and patterns it touches, the steps, and how we
  verify it. Work that already has one needs no second round: an approved issue, spec or plan, or an
  explicit instruction, counts as approval, so execute without asking again.
- Feel free to ask questions to clear things up.
- **Give me actionable multiple-choice questions, not a blob of text.** When something needs my decision, ask it with the `AskUserQuestion` tool: 2-4 concrete options, your recommendation first. Never bury a decision inside a paragraph of findings or in a trailing remark ("say the word and I'll file those", "worth deciding whether…") — I can't tell which lines are FYI and which are blocking, so nothing gets answered and the work stalls. Keep findings that need no decision as prose, and batch pending decisions into one question call instead of dribbling them out. The flip side: decide mechanical implementation details yourself and just tell me the call you made — only ask about things with real consequences.
- When I ask a question, I'm genuinely curious and want your feedback or explanation. A question does not mean "go change things" — do not start modifying code just because I asked about it. It also does not mean I disagree with the current approach.
- Testing is important! If possible use TDD. Use the tests to check your own work.
- We like elegant code that looks like it was written by e.g. Taylor Otwell or Caleb Porzio.
- Keep it DRY (don't repeat yourself) but do not over optimize, I generally repeat myself once and then when I find myself doing it again I see how I can abstract some concept.
- We like the general ideas Sandi Metz has about programming.
- Avoid null-safety checks (`?->`, `?:`, `if (!$x)` guards) as a solution unless there is a good reason for it. Prefer fixing the root cause — e.g. if `auth()->user()` is null in a test, authenticate a user in the test rather than adding null-safe operators in production code.
- Before building something, check `composer.json` for a package that already does it. Prefer our it4web packages and what is already installed over new code or a new dependency.
- When we implement a feature for a project that seems useful for more projects then lets ask ourselves whether is belongs in one of our it4web packages or even if it is something we should create a new package for.
- Prefer polymorphism over conditionals. Use enums with behavior methods, strategy patterns, or other polymorphic approaches instead of scattered if/else or boolean flags.

---

## Coding Conventions

### Backend Architecture

#### Action Classes
Single-purpose business logic lives in an Action: a static `make()` that takes whatever the action
needs, the work in `handle()`, one responsibility per action.

#### Data Migrations (Deploy Operations)
We use `dragon-code/laravel-deploy-operations` (or its predecessor `dragon-code/laravel-migration-actions` in older projects) for data migrations. **Never put data manipulation (inserts, updates, backfills) inside schema migrations.** Schema migrations should only contain schema changes (add/drop columns, create/drop tables, add indexes, etc.).

- **These only run in production** via `php artisan operations` (or `php artisan actions` in older projects). They do NOT run in dev/test — seeders and factories handle data setup there.
- **Schema migrations and data migrations must be independent.** If an operation needs to read an old column to backfill a new one, do NOT drop the old column in the same migration. Drop it in a follow-up migration after confirming the operation ran in production.
- Generate with `php artisan make:operation BackfillSomething` (or `make:action` in older projects)
- Check `composer.json` to determine which version of the package the project uses

```php
// actions/2025_01_01_000000_backfill_status_enum.php
return new class extends Action {
    public function __invoke(): void
    {
        DB::table('orders')->where('is_active', true)->update([
            'status_enum' => OrderStatusEnum::ACTIVE->value,
        ]);
    }
};
```

#### Enums
Native backed enums (`: int` or `: string`), never string constants. Each gets a `label()` for the
human-readable name and a static `getOptions()` returning `[['id' => $case->value, 'name' => $case->label()], …]`
— the shape TallFormbuilder's SelectField expects.

#### Models
- `protected $guarded = [];` (guard nothing, fillable everything)
- Cast enums in `$casts`
- Side effects of a change go in an explicit Action call at the call site, not in a `booted()` hook or
  an Observer: those are too hidden.
- Relationships with clear names

#### Services and Controllers
- Services for complex business logic and external API integrations: static `make()`, chainable
  methods, and a facade where it makes the call site cleaner.
- Thin controllers: Livewire components preferred for interactive UI, validation in Form Requests or
  inline, business logic in Actions and Services.

#### Flare signals
A handled situation worth watching (a slide skipped because its media file is missing) is
`report(new SomeDedicatedException($context))`, not `Log::warning()`: Flare groups it and counts the
occurrences. Give the exception a `context(): array` with the relevant ids, and report it from one
place so all occurrences land in one Flare error. `Log::` is for low-value debug output.

### Frontend Stack

#### Livewire and the it4web packages
- Livewire 3 components for all interactive UI; traits for shared behavior (HasForm, HasModalEvents).
- Forms through TallFormbuilder, datatables through TallDataTable. Form elements outside the form
  builder come from TallUi or Flux before anything custom.

#### TallFormbuilder Pattern
```php
BasicForm::make()
    ->elements(
        TextField::for('name')
            ->label('Name')
            ->rules(['required', 'string', 'max:255']),
        Footer::make()->elements(
            Button::make()->label('Save')->method('submit')
        )
    );
```

**Important TallFormbuilder conventions:**

- **SelectField options format**: Must be array of arrays with `id` and `name` keys:
  ```php
  // CORRECT
  ->options(Customer::orderBy('name')->get()->map(fn($c) => ['id' => $c->id, 'name' => $c->name])->toArray())

  // WRONG - will cause "Cannot access offset of type string on string" error
  ->options(Customer::pluck('name', 'id')->toArray())
  ```

- **SwitchField** (for boolean toggles): Always add `->rules(['boolean'])` to avoid validation errors
  ```php
  SwitchField::for('enabled')->label('Enabled')->rules(['boolean'])
  ```

- **Use description() not hint()**: TextField has `->description()` method, not `->hint()`

- **Conditional field visibility**: Use `->hidden(bool|callable)` to conditionally hide fields
  ```php
  SelectField::for('user.customer_id')
      ->label('Customer')
      ->hidden($this->user->role !== UserRoleEnum::CUSTOMER)
  ```

#### Blade and Tailwind
- x-components over @includes, with named slots
- Don't use @php in blade. If you think it needed/better ask for permission.
- No custom CSS unless absolutely necessary. Colors are named `primary`, `contrast`, `success`,
  `warning`, `error`.

### Code Style

#### General
- Early returns / guard clauses over nested conditionals
- Full type hints on methods and properties
- Descriptive naming, no abbreviations
- Minimal comments - code should be self-documenting
- OOP or functional over procedural
- Small classes, small methods (Sandi Metz rules as guidance)
- Prefer Eloquent over bypassing it. Use raw `DB::`/query-builder writes only with a clear reason — bypassing Eloquent skips model events, casts, and relationship cleanup, which can silently orphan related rows. When a model already orchestrates its own cleanup (a cascade, or a method like `deleteSlideableAndSelf()`), go through it rather than deleting the row directly.
- Prefer Laravel collections over plain PHP `foreach` loops
- Always use `->get()` before `->each()` on query builders to make it explicit when we transition from query builder to collection:
  ```php
  // CORRECT - clear boundary between query and collection
  User::query()->where('active', true)->get()->each(fn ($user) => ...);

  // WRONG - ambiguous, ->each() on query builder behaves differently
  User::query()->where('active', true)->each(fn ($user) => ...);
  ```

#### Routes
- Named routes always, never hardcoded URLs
- Prefix grouping by domain (admin, api, webhook)
- Livewire components can be routed directly

```php
Route::prefix('admin')->as('admin.')->middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', DashboardComponent::class)->name('dashboard');
});
```

#### Validation
Centralized validation in controllers or Livewire:
```php
$data = request()->validate([
    'email' => ['required', 'email'],
    'name' => ['required', 'string', 'max:255'],
]);
```

### Testing

- Run tests with `php artisan test` inside the web container (`docker exec {project}_web php artisan test`),
  always in the foreground: wait for the run to finish and read its full output before continuing.
- Pest for new projects; PHPUnit is fine in existing ones. We almost never write unit tests when a
  Feature test covers, or can cover, the code.
- Tests never talk to external services: the code that does sits behind a facade, which the test fakes.
- `RefreshDatabase` for isolation, factories for data, `Livewire::test()` for components,
  `assertDatabaseHas()` / `assertDatabaseCount()` for results.
- **Test with related data.** When a component has dropdowns or selects fed by other models, create
  that data first — empty arrays hide formatting bugs:
  ```php
  Customer::factory()->count(3)->create();

  Livewire::test(UserForm::class)->assertStatus(200); // catches SelectField options format bugs
  ```

---

## Workflow

### Git Workflow

- **No co-author**: Do not add `Co-Authored-By` lines to git commit messages.
- **No AI attribution**: Do not include "Generated with Claude Code" or similar AI tool references in PRs, commits, or code.
- **Never commit directly to main**. Always create a feature branch and open a pull request when the work is done.
- **Never address a human without my explicit permission**: posting on PRs and issues is fine — write up what changed, what was measured, and what still stands, even when it resolves someone's review remark. What is off-limits is writing *to* a person: naming or greeting them, second person ("je"/"you"), agreeing with or praising them ("scherp gezien"), asking them anything, inviting a reply, or reacting (👍 etc.) to their comment. Keep it an impersonal record of the work, not a message. If it only makes sense as a message to someone, draft it in chat and let me send it — colleagues read it as me talking, so I decide what gets said and when. Same on Slack, email and tickets.
  - ❌ "Scherp gezien Damion — dat klopte inderdaad niet. Ik heb optie 1 gedaan … Als je dat ook weg wilt hebben, hoor ik het graag."
  - ✅ "Optie 1 geïmplementeerd: de presentatie blijft gepauzeerd bij vorige/volgende. Gemeten op test: … Blijft staan: na een minuut inactiviteit hervat het scherm (bewust, voor etalageschermen)."
- **Never work against a stale checkout.** `hooks/git-freshness.sh` reports staleness by itself, for
  the repo being worked in, and keeps local `main`/`master` fast-forwarded — the only thing it changes
  on its own. When it warns about your working branch, **raise it with me and wait**: do not pull,
  rebase or merge on your own initiative. Without the hook, check by hand before the first edit in a repo:
  ```bash
  git fetch origin
  git rev-list --count HEAD..origin/main   # commits on the base branch this checkout lacks
  ```
  `git status` cannot see this (it only compares against the tracking branch), and `origin/HEAD` is
  often stale: run `git remote set-head origin --auto` before trusting it.
- **Update the changelog**: When creating a PR, add a changelog entry using whichever convention the project uses:
  - **Fragment-based (project has a `.changelog/unreleased/` directory):** copy `.changelog/unreleased/TEMPLATE.md` to `.changelog/unreleased/<branch-name>.md` (branch name with `/` replaced by `-`) and fill in the `<details>` block. Do **not** edit `CHANGELOG.md` directly — the release workflow rolls fragments in at release time. See `.changelog/unreleased/README.md`.
  - **Plain changelog (no `.changelog/` directory):** update the project's `CHANGELOG.md` directly with a summary of the changes. Check the latest version tag first with `git tag --sort=-v:refname | head -5` to determine the correct next version number.
- **Check for vendor hacks**: Before creating a PR, check for modified files in `vendor/it4web/` by running `find vendor/it4web/ -newer vendor/composer/installed.json -name '*.php'` inside the web container. Since `vendor/` is gitignored, git won't track these changes. `installed.json` is written at the end of `composer install/update`, so any PHP file newer than it was manually edited after install. If modifications are found, flag them and remind to port those changes back to the actual package repositories before they get lost on the next `composer install`.

### Before calling work done

- Tests pass.
- It works in the browser: check it with the Playwright MCP, creating an admin account (or taking
  credentials from the DatabaseSeeder) when a login is needed. For visual work — Livewire, Blade,
  CSS, frontend JS — invoke the `browser-verification` skill for annotated screenshot proof before
  claiming it works.
- Review the completed work, including against the project's conventions.

---

## Remote servers (SSH)

- **Ask before every SSH session** to production, acceptance or a swarm node — read-only probes included. Ask with `AskUserQuestion` (which host, which command, read-only or not) and offer a local alternative first: reproduce in a slot, read the code at the release tag, or let me check. **If I name the host and the command, that is the approval — run it.**
- **Use the plain form, nothing wrapped around it:**
  ```bash
  ssh -o BatchMode=yes -o ConnectTimeout=15 jroelofs@<host> "<command>"
  ```
  - Never `-o StrictHostKeyChecking=no`. On a host-key mismatch, stop and compare the offered fingerprint with me.
  - Never wrap it in `perl -e 'alarm …'`, `bash -c` or similar. Permission rules cannot match past such a wrapper, and auto mode reads it as suspicious. `ConnectTimeout` plus the Bash tool's own timeout is enough.
- **Keep the payload visible.** Tinker goes inline with `--execute="…"`, never as a file piped in over stdin — auto mode cannot see into the file and refuses it. Split a long probe into several small commands.
- **`docker exec` only works on the node running the task**, which is usually not the swarm manager. Find it first: `docker service ps <svc> --filter desired-state=running --format '{{.Node}}'`.
- **In a background job a denial is final.** There is no retry prompt, and asking in chat does not lift it. Hand me a `!` one-liner instead of retrying or reshaping the command.

---

## Skills and hooks

Skills come from two repos, this one and `IT4WEBBV/DevOps-Claude-Config`, with one symlink per skill
in `~/.claude/skills/`. At session start `hooks/git-freshness.sh` fast-forwards both repos and links
any new skill, on each machine. Skill names must be unique across the two repos.
`DevOps-Claude-Config` is a colleague's personal config: link only its `skills/`, never its
`settings.json` or `CLAUDE.md`. After changing a hook, run its tests in `hooks/tests/`. Machine
setup and hook wiring: `README.md`. Playbooks for porting a LaravelTemplate feature into a project
(slots, changelog automation, base image upgrade, Pest migration): `docs/playbooks/`.

---

## Memory (SecondBrain vault)

> **Not live yet.** The cut-over — Tasks 5–6 of `docs/superpowers/plans/2026-09-11-vault-auto-memory.md` —
> has not run: the vault has no `memory/` folder on origin, and `settings.json` sets neither
> `autoMemoryDirectory` nor the `vault-sync` hooks. Until it runs:
> - Auto-memory stays in the default `~/.claude/projects/*/memory`, flat — no `repos/<key>/` folders,
>   and nothing syncs between machines.
> - The vault is still the basic-memory archival tier: query it through the basic-memory MCP
>   (`search_notes`, `build_context`) when starting project work or making a decision.
> - To finish it, start `CLAUDE_CODE_DISABLE_AUTO_MEMORY=1 claude` from `~`, execute Tasks 5–6, and
>   delete this note.

The rest of this section describes the setup after the cut-over.

Auto-memory lives in the SecondBrain vault — `~/GitProjects/SecondBrain/SecondBrain`, private repo
`jonneroelofs/SecondBrain` — not in machine-local `~/.claude/projects/*/memory`. The
`autoMemoryDirectory` setting points every session at its `memory/` folder, so memory is versioned in
git and shared by both machines. It is the only memory system: save memories the normal auto-memory
way; there is nothing else to write to.

- **Repo-specific memories** — facts only true inside one repo — go in `memory/repos/<key>/`, with their
  index line in that folder's own `MEMORY.md`. `<key>` is the repo's GitHub name, lowercased
  (`IT4WEBBV/ViewieMedia` → `viewiemedia`). Each repo folder has one pointer line in the global
  `MEMORY.md`: read that repo's index before working in, or answering about, that repo. Everything else
  is global.
- **Never store secrets, credentials or client PII** — every memory is pushed to GitHub. `vault-sync.sh`
  holds back a file that looks like it contains a key; that is a backstop, not a licence.
- **Syncing is automatic.** `hooks/vault-sync.sh` commits `memory/` and syncs with origin at session start
  and end. Don't commit or push memory changes by hand.
- **Vault sync conflicts are yours to resolve** — an exception, for the vault only, to the Git Workflow
  rule against pulling, rebasing or merging on your own initiative. When the hook reports a conflict,
  rebase onto the upstream, keep both sides' facts in each conflicted file, continue, push.
- **A memory saved on the other machine is there at the next session start.**
- The owner's own notes at the vault root are theirs; the hook never stages them.

Setting a machine up for the vault, and migrating its local memories into it: `README.md`.

## Icons

Never add Font Awesome through npm or a CDN. Icons are copied by hand from the shared FontAwesomeCache
mirror: use the `icons` skill.
