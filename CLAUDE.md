# Claude Code Instructions

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
- Testing is important! If possible use TDD. Use the tests to check your own work.
  - Also check in the browser if things work. If available you can use the mcp playwright to do so. Feel free to create an admin account to login if necessary.
- We like elegant code that looks like it was written by e.g. Taylor Otwell or Caleb Porzio.
- Keep it DRY (don't repeat yourself) but do not over optimize, I generally repeat myself once and then when I find myself doing it again I see how I can abstract some concept.
- We like the general ideas Sandi Metz has about programming.
- When we implement a feature for a project that seems useful for more projects then lets ask ourselves whether is belongs in one of our it4web packages or even if it is something we should create a new package for. 

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

#### Blade
- Use x-components over @includes
- Named slots for flexibility
- Alpinejs components where applicable

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

---

## Workflow

### Git Workflow

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
- [ ] Code follows project conventions
- [ ] Review the completed work.
