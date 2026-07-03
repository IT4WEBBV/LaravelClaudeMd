# Claude Code Instructions

> **Note**: This file lives in the `LaravelClaudeMd` repo and is symlinked to `~/.claude/CLAUDE.md`. Personal skills come from **two** repos — `LaravelClaudeMd/skills/` and `DevOps-Claude-Config/skills/` — each skill symlinked individually into a real `~/.claude/skills/` directory (so both repos' skills coexist; see [Skills (multi-machine setup)](#skills-multi-machine-setup) at the bottom). When starting a conversation, pull **both** config repos first so skills and instructions stay current:
> ```bash
> git -C ~/GitProjects/LaravelClaudeMd/LaravelClaudeMd pull --ff-only -q
> git -C ~/GitProjects/DevOps-Claude-Config/DevOps-Claude-Config pull --ff-only -q
> ```

## Docker Environment

All projects run in Docker containers using Docker Compose. Never run application commands directly on the host machine.
it4web/* package tests can be run with their own make file (if not we should make it so).
it4web/* packages can usually be worked on locally by starting a project with the ./restart.sh -p flag so they are locally mounted

### Starting Projects

Always start projects using the `restart.sh` script located in the `/scripts` folder. Do not manually run docker-compose commands.

```bash
# Start a project
./scripts/restart.sh

# Start with locally mounted it4web packages
./scripts/restart.sh -p
```

### Project Structure

```
project/
├── container/
│   ├── .env                    # Container environment variables
│   ├── docker-compose.dev.yml  # Development compose file
│   └── docker-compose.*.yml    # Other environment configs
├── code/
│   └── www/                    # Application code (mounted to /var/www)
└── scripts/                    # Helper scripts
```

### Programming Philosophy

- Think first act later. So create a todolist or other plan that can be approved before we start building anything.
- Feel free to ask questions to clear things up.
- When I ask a question, I'm genuinely curious and want your feedback or explanation. A question does not mean "go change things" — do not start modifying code just because I asked about it. It also does not mean I disagree with the current approach.
- Testing is important! If possible use TDD. Use the tests to check your own work.
  - Also check in the browser if things work. If available you can use the mcp playwright to do so. Feel free to create an admin account to login if necessary.
- We like elegant code that looks like it was written by e.g. Taylor Otwell or Caleb Porzio.
- Keep it DRY (don't repeat yourself) but do not over optimize, I generally repeat myself once and then when I find myself doing it again I see how I can abstract some concept.
- We like the general ideas Sandi Metz has about programming.
- Avoid null-safety checks (`?->`, `?:`, `if (!$x)` guards) as a solution unless there is a good reason for it. Prefer fixing the root cause — e.g. if `auth()->user()` is null in a test, authenticate a user in the test rather than adding null-safe operators in production code.
- When we implement a feature for a project that seems useful for more projects then lets ask ourselves whether is belongs in one of our it4web packages or even if it is something we should create a new package for.
- Prefer polymorphism over conditionals. Use enums with behavior methods, strategy patterns, or other polymorphic approaches instead of scattered if/else or boolean flags.

### Container Naming Convention

Containers are named using the pattern: `${COMPOSE_PROJECT_NAME}_service`

Common services:
- `web` - Main PHP/web container (run artisan, composer, npm, phpunit here)
- `db` - Database (MariaDB)
- `db_test` - Test database
- `worker` - Queue worker

Example for a project named "viewiemedia":
- `viewiemedia_web`
- `viewiemedia_db`
- `viewiemedia_worker`

### Running Commands

Always execute commands inside the appropriate container:

```bash
# Enter the web container
docker exec -it {project}_web bash

# Or run a single command
docker exec {project}_web php artisan migrate
docker exec {project}_web composer install
docker exec {project}_web npm run build
docker exec {project}_web php artisan test
```

### Finding the Project Name

Check `container/.env` for `COMPOSE_PROJECT_NAME` to determine the container prefix. You can also find at what port the database container port is mapped from the container to the host.

### Working Directory

The application code is mounted at `/var/www` inside containers. When running commands, you're typically in this directory.

## Common Commands

```bash
# Laravel/PHP
docker exec {project}_web php artisan migrate
docker exec {project}_web php artisan c:d

# Testing
docker exec {project}_web php artisan test
```

## Important Notes

- The `web` container is the primary container for running application commands
- Database connections from within containers use the container name as host (e.g., `viewiemedia_db`)
- Test databases are available with `_test` suffix (e.g., `viewiemedia_db_test`)

---

## Coding Conventions

### Backend Architecture

#### Action Classes
Use Action classes for single-purpose business logic:
- Static `make()` factory method for instantiation
- Main logic in `handle()` method
- Constructor dependency injection
- One action = one responsibility

```php
class CreateOrderAction
{
    public static function make(): self
    {
        return new self();
    }

    public function handle(array $data): Order
    {
        // Business logic here
    }
}
```

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
Always use native PHP enums over string constants:
- Use backed enums (`: int` or `: string`)
- Add `label()` method for human-readable names
- Add `getOptions()` for form selectors

```php
enum OrderStatusEnum: int
{
    case PENDING = 0;
    case PROCESSING = 1;
    case COMPLETED = 2;

    public function label(): string
    {
        return match($this) {
            self::PENDING => 'Pending',
            self::PROCESSING => 'Processing',
            self::COMPLETED => 'Completed',
        };
    }

    public static function getOptions(): array
    {
        return collect(self::cases())->map(fn ($case) => [
            'id' => $case->value,
            'name' => $case->label(),
        ])->all();
    }
}
```

#### Models
- Use `protected $guarded = [];` (guard nothing, fillable everything)
- Cast enums in `$casts` array
- Use `static::booted()` for model event hooks
- Define relationships with clear naming

#### Services
Use service classes for complex business logic and external API integrations:
- Static `make()` factory method
- Chainable methods for fluent interface
- Facades for cleaner API access when appropriate

#### Controllers
Keep controllers thin:
- Livewire components preferred for interactive UI
- Validation in Form Requests or inline
- Business logic delegated to Actions/Services

### Frontend Stack

#### Livewire 3 (Primary)
- Use Livewire components for all interactive UI
- Traits for shared behavior (HasForm, HasModalEvents)
- Form builder DSL via TallFormbuilder package
- Datatables via TallDatatable package.
- If you need form elements outside of the Form builder use those from TallUi or FluxUi before creating anything custom.

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

#### Blade
- Use x-components over @includes
- Named slots for flexibility
- Alpinejs components where applicable
- Don't use @php in blade. If you think it needed/better ask for permission.

#### Tailwind CSS
- Utility-first, no custom CSS unless absolutely necessary
- Color naming: `primary`, `contrast`, `success`, `warning`, `error`
- Use `@tailwindcss/forms`, `@tailwindcss/typography` plugins

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

#### Running Tests
- Always run tests with `php artisan test` (inside the web container), e.g. `docker exec {project}_web php artisan test`.
- Always run tests in the foreground — never run the test suite as a background process. Wait for the run to finish and read its full output before continuing.

#### Framework
- Pest PHP preferred for new projects (fluent syntax)
- PHPUnit acceptable for existing projects
- We almost never write unit tests if the relevant code is or can be covered by a Feature test.

#### Organization
```
tests/
├── Feature/      # Integration tests, HTTP tests
│   └── Livewire/ # Livewire component tests
├── Unit/         # Isolated unit tests
└── Browser/      # Playwright browser tests
```

#### Patterns
- Should never talk to external services. Usually achieved by have the code that talks to the external service behind a facade.
- Use `RefreshDatabase` trait for test isolation
- Factories extensively for test data
- Livewire testing with `Livewire::test()`
- Database assertions: `assertDatabaseHas()`, `assertDatabaseCount()`
- **Test with related data**: When testing components with dropdowns/selects that depend on other models, always create that related data first. Empty arrays won't catch formatting bugs:
  ```php
  // This catches SelectField options format bugs
  public function the_component_can_render_with_customers()
  {
      Customer::factory()->count(3)->create();

      Livewire::test(UserForm::class)
          ->assertStatus(200);
  }
  ```

```php
// Pest example
it('creates an order', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(CreateOrderForm::class)
        ->set('name', 'Test Order')
        ->call('submit')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('orders', ['name' => 'Test Order']);
});
```

### Common Packages

These packages are commonly used across projects:
- `livewire/livewire` - Interactive components
- `it4web/tallui` - UI components
- `it4web/talldatatable` - Data tables
- `it4web/tallformbuilder` - Form builder
- `laravel/jetstream` - Authentication scaffolding
- `spatie/laravel-ray` - Debugging
- `maatwebsite/excel` - Excel import/export

### Playwright MCP Browser Fix

If the Playwright MCP fails to launch Chrome with "Opening in existing browser session" errors, delete the stale user-data-dir:

```bash
rm -rf ~/Library/Caches/ms-playwright/mcp-chrome-*
```

This clears the Playwright Chrome profile that conflicts with an already-running Chrome instance.

---

## Workflow

### Git Workflow

- **No co-author**: Do not add `Co-Authored-By` lines to git commit messages.
- **No AI attribution**: Do not include "Generated with Claude Code" or similar AI tool references in PRs, commits, or code.
- **Never commit directly to main**. Always create a feature branch and open a pull request when the work is done.
- **Sync with remote when working on existing branches**: When checking out or reviewing an existing branch, always run `git fetch` and check if the local branch is up to date with the remote (`git status` or `git log --oneline HEAD..origin/<branch>`). Pull the latest changes before starting any work to avoid conflicts and working on stale code.
- **Update the changelog**: When creating a PR, add a changelog entry using whichever convention the project uses:
  - **Fragment-based (project has a `.changelog/unreleased/` directory):** copy `.changelog/unreleased/TEMPLATE.md` to `.changelog/unreleased/<branch-name>.md` (branch name with `/` replaced by `-`) and fill in the `<details>` block. Do **not** edit `CHANGELOG.md` directly — the release workflow rolls fragments in at release time. See `.changelog/unreleased/README.md`.
  - **Plain changelog (no `.changelog/` directory):** update the project's `CHANGELOG.md` directly with a summary of the changes. Check the latest version tag first with `git tag --sort=-v:refname | head -5` to determine the correct next version number.
- **Check for vendor hacks**: Before creating a PR, check for modified files in `vendor/it4web/` by running `find vendor/it4web/ -newer vendor/composer/installed.json -name '*.php'` inside the web container. Since `vendor/` is gitignored, git won't track these changes. `installed.json` is written at the end of `composer install/update`, so any PHP file newer than it was manually edited after install. If modifications are found, flag them and remind to port those changes back to the actual package repositories before they get lost on the next `composer install`.

We use feature branches for development. Create a new branch for each feature or fix, then create a pull request when complete.

```bash
# Create a feature branch
git checkout -b feature/my-new-feature

# After completing work, push and create a PR
git push -u origin feature/my-new-feature
gh pr create
```

### Research & Plan Phase

Before building anything significant:

1. **Understand the request** - Clarify requirements, ask questions if needed
2. **Explore the codebase** - Find relevant existing code, patterns, similar implementations
3. **Identify dependencies** - What existing code will this touch? What needs to change?
4. **Draft a plan** with these sections:

```markdown
## Summary
Brief description of what we're building

## Research Findings
- Relevant existing code found
- Patterns to follow
- Dependencies identified

## Implementation Plan
1. Step one
2. Step two
3. ...

## Validation Strategy
How we'll verify this works:
- [ ] Tests to write (or existing tests to check)
- [ ] Manual checks in browser
- [ ] Edge cases to consider
```

5. **Present for approval** before implementation

### Build Phase

After a plan is approved:

1. **Write tests first** (when applicable) - Define expected behavior
2. **Implement incrementally** - Small, verifiable steps
3. **Run tests frequently** - `docker exec {project}_web php artisan test`
4. **Check in browser** - Use Playwright MCP or manual verification, if needed check the databaseseeder for credentials or create your own.
5. **Mark todos complete** as you go
6. **Handle failures** - If tests fail, fix before proceeding

### Validation Checklist

Before considering work complete:
- [ ] Tests pass
- [ ] Works in browser
  - When verifying visual work (Livewire, Blade, CSS, frontend JS), invoke the `browser-verification` skill for annotated screenshot proof before claiming it works.
- [ ] Code follows project conventions
- [ ] Review the completed work.

---

## Skills (multi-machine setup)

Personal skills are pooled from **two** git repos, so `~/.claude/skills/` is a **real directory** (not a symlink to either repo) holding one symlink per skill:

| Repo | Clone location | Provides |
|------|----------------|----------|
| `IT4WEBBV/LaravelClaudeMd` | `~/GitProjects/LaravelClaudeMd/LaravelClaudeMd` | `browser-verification`, `improve-codebase-architecture` |
| `IT4WEBBV/DevOps-Claude-Config` | `~/GitProjects/DevOps-Claude-Config/DevOps-Claude-Config` | `handoff`, `memory-sync`, `release-changelog`, `retenium-prod` |

> **Nested clone layout**: both repos are cloned one level deep — `~/GitProjects/<Repo>/<Repo>/` — to match `DevOps-Claude-Config`'s own README and its `memory-sync` skill, which expects that path. Keep this layout so Mark's skills work unmodified.

A single symlink at `~/.claude/skills` can only ever point at one repo — that's why it's a real folder with per-skill symlinks instead, letting both repos' skills coexist.

### Keeping skills up to date

Pull **both** repos at the start of each session (see the note at the top of this file). The `/memory-sync` skill does the same on demand. Because each skill is symlinked back to its repo, a `git pull` updates the skills in place.

### Bootstrapping a new machine

```bash
# 1. Clone both config repos into nested wrapper dirs (~/GitProjects/<Repo>/<Repo>/)
mkdir -p ~/GitProjects/LaravelClaudeMd ~/GitProjects/DevOps-Claude-Config
git clone git@github.com:IT4WEBBV/LaravelClaudeMd.git ~/GitProjects/LaravelClaudeMd/LaravelClaudeMd
git clone git@github.com:IT4WEBBV/DevOps-Claude-Config.git ~/GitProjects/DevOps-Claude-Config/DevOps-Claude-Config

# 2. Symlink this file as the global CLAUDE.md
ln -sfn ~/GitProjects/LaravelClaudeMd/LaravelClaudeMd/CLAUDE.md ~/.claude/CLAUDE.md

# 3. Make ~/.claude/skills a REAL directory and link every skill from BOTH repos
mkdir -p ~/.claude/skills
for repo in LaravelClaudeMd DevOps-Claude-Config; do
  for skill in ~/GitProjects/$repo/$repo/skills/*/; do
    ln -sfn "$skill" ~/.claude/skills/"$(basename "$skill")"
  done
done
```

Re-run step 3 whenever either repo adds a new skill (existing ones update via `git pull`; a brand-new skill folder needs its own symlink).

**Caveats**
- Only link the `skills/` folders. Do **not** symlink `DevOps-Claude-Config/settings.json` or its `CLAUDE.md` over yours — that repo is a colleague's personal config; its settings/instructions are not ours.
- Skill names must be unique across the two repos. If both ever ship a folder with the same name, the second `ln` silently wins — rename one before linking.

---

## Second Brain (basic-memory archival memory)

A cross-project knowledge vault lives at `~/GitProjects/SecondBrain/SecondBrain/` (private repo
`jonneroelofs/SecondBrain`), served to AI via the
[`basic-memory`](https://github.com/basicmachines-co/basic-memory) MCP server. It is the
**archival tier** (queried on demand). Native Claude Code auto-memory is the **hot tier** (always
injected, small, "current sprint" horizon). Markdown is the source of truth; the SQLite index in
`~/.basic-memory/` is rebuildable (`basic-memory reindex`). Synced via git, **not** basic-memory cloud.

**READ it** (`search_notes` / `build_context`, or CLI `basic-memory tool search-notes "<query>"`) when:
- starting work on a project → read its `projects/<name>` note first
- touching an it4web package → read its `packages/<name>` note
- making an architecture/tooling decision → search `decisions/`
- hitting a recurring/procedural task → search `playbooks/`

**WRITE to it** (`write_note`) when something non-obvious is worth reusing across sessions — a
decision, gotcha, convention, or "why". One fact per note; new facts start `confidence: provisional`
and become `confirmed` when they recur. Observations as `- [category] ...`, relations as typed
wikilinks (see the vault's own `README.md`).

- **Never store** secrets, credentials, or client PII — it is a git repo.
- **Don't duplicate** what this CLAUDE.md or a skill already documents — link to it instead.
- The vault repo **commits directly to `main`** (no PRs); that exception is declared in the vault's
  own `CLAUDE.md` and applies only there.

### Second Brain (multi-machine setup)

Like the skills, the vault is one more git repo pulled into `~/GitProjects`. On a new machine:

```bash
# 1. Clone the vault
git clone git@github.com:jonneroelofs/SecondBrain.git ~/GitProjects/SecondBrain/SecondBrain

# 2. Install basic-memory (needs Python >=3.12; uv fetches it automatically)
curl -LsSf https://astral.sh/uv/install.sh | sh
uv tool install basic-memory

# 3. Register the project (points basic-memory at the local clone) and make it default
basic-memory project add SecondBrain ~/GitProjects/SecondBrain/SecondBrain
basic-memory project default SecondBrain
basic-memory reindex            # initial index (0.22.1 has no `sync` subcommand)

# 4. Register the MCP server with Claude Code (user scope; takes effect next session)
claude mcp add --scope user basic-memory -- "$HOME/.local/bin/basic-memory" mcp
```

Pull the vault repo at session start alongside the other config repos.
