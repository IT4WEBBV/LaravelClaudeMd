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
- After switching branches in a checkout, run `./scripts/restart.sh` instead of fixing the one error
  that surfaced: vendor, migrations, published assets and built files all drift at once. It reseeds
  the database, so warn first when there is data worth keeping.

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
- **Give me actionable multiple-choice questions, not a blob of text.** When something needs my decision, ask it with the `AskUserQuestion` tool: 2-4 concrete options, your recommendation first. Never bury a decision inside a paragraph of findings or in a trailing remark ("say the word and I'll file those", "worth deciding whether…") — I can't tell which lines are FYI and which are blocking, so nothing gets answered and the work stalls. Keep findings that need no decision as prose, and batch pending decisions into one question call instead of dribbling them out. After an explanation the question waits a turn (see below). The flip side: decide mechanical implementation details yourself and just tell me the call you made — only ask about things with real consequences.
- **Ask which one when I'm unclear.** "This one also has conflicts" with several candidates → ask which PR, issue or file I mean, with the candidates as options, even in a background job. Don't pick the likeliest.
- **Name work by what it does, not by its number, and say what kind it is.** Whenever issues, PRs or runs come up for me to choose between or follow, give each a few plain words on what it is about, with its kind and number after it in parentheses: "CI gate fix (PR #149) and proof back link (issue #153) now", not "#149 + #153 now" and not "CI gate fix (#149)". Issues and PRs share one number sequence, so a bare `#153` doesn't tell me whether it is still to be built or waiting for review. The kind goes with every number, also when two are linked ("issue #153 is fixed by PR #160") and in PR and issue comments; a number in another repo carries the repo name ("Deploy PR #408"). Syntax a tool parses stays bare, such as `Closes #N` and `Depends on #N`. A bare list of numbers gives me nothing to decide on. This holds for `AskUserQuestion` labels and descriptions too.
- When I ask a question, I'm genuinely curious and want your feedback or explanation. A question does not mean "go change things" — do not start modifying code just because I asked about it. It also does not mean I disagree with the current approach. Answer a conceptual question in a few plain sentences first, not with a table and a new decision.
- **An explanation ends the turn.** The `AskUserQuestion` dialog covers the text above it, so an
  explanation or report followed by a question in the same turn is one I never get to read. When I
  asked for an explanation, or the turn's text is more than a few lines I need to read, end the turn
  with that text and no question call. A decision that follows from it gets one closing line ("One
  decision pending: whether to X — I'll ask once you've read this"), and the `AskUserQuestion` call
  comes in the turn after my reply.
- **"Why is this here?" → check the design source first.** Open the mockup, spec or issue and compare it with what was built before explaining. When something looks pointless to me, assume a mismatch between design and build, and say plainly when it is a flaw: "it's in the spec" is not an explanation.
- Testing is important! If possible use TDD. Use the tests to check your own work.
- We like elegant code that looks like it was written by e.g. Taylor Otwell or Caleb Porzio.
- Keep it DRY (don't repeat yourself) but do not over optimize, I generally repeat myself once and then when I find myself doing it again I see how I can abstract some concept. Test fixtures are exempt: a copied fixture per test file is fine.
- We like the general ideas Sandi Metz has about programming.
- Avoid null-safety checks (`?->`, `?:`, `if (!$x)` guards) as a solution unless there is a good reason for it. Prefer fixing the root cause — e.g. if `auth()->user()` is null in a test, authenticate a user in the test rather than adding null-safe operators in production code.
- Before building something, check `composer.json` for a package that already does it. Prefer our it4web packages and what is already installed over new code or a new dependency.
- When we implement a feature for a project that seems useful for more projects then lets ask ourselves whether is belongs in one of our it4web packages or even if it is something we should create a new package for.
- **A package fix covers the package and the repo that reported it.** Don't sweep other consumer repos, start their stacks or open follow-ups there: if it is a problem elsewhere we'll hear about it. At most one targeted grep for a direct break, noted in the PR. When I do ask an org-wide question, `gh search code` silently stops at 30 results: pass `--limit 100`, and search for the call (`Class::`), not the name, since unused imports match too.
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
- Side effects of a change go in an explicit Action call at the call site, not in a `booted()` hook,
  an Observer or a Livewire lifecycle hook (`mountX`, `renderingX`): those are too hidden, even when
  the hook would cover more write paths.
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
- A modal form opened with an id (`x-tallui::modal-trigger` passes `['config' => $id]`) types the
  same-named public property `Config|int`: Livewire assigns mount parameters to it before `mount()`
  runs, so the model type alone makes the modal 500. Its tests mount it with the id, as the trigger does.
- A destructive action in our admin tools lists what it affects and confirms with a plain button. No
  "type the name to confirm", and no warning that is true on nearly every action: flag only the unusual case.

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
- Establish whether code throws in a Feature test or over HTTP, never in tinker: Psy Shell replaces
  Laravel's error handler, so an error that throws in the app only prints there. Tinker is for reading data.
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

### Code changes go through `/pipeline`

A change to a project's code runs through `/pipeline`, so the plan review, the PR review and the
visual proof always happen: `autoflow` when it can run unattended, with `medium` for a small change.
Without an issue, file one first; it is the run's work item. Done directly, without the pipeline:
trivial edits only (a one-line fix, config or env, docs, a changelog fragment), and whatever I
explicitly ask to be done by hand.

### Git Workflow

- **No co-author**: Do not add `Co-Authored-By` lines to git commit messages.
- **No AI attribution**: Do not include "Generated with Claude Code" or similar AI tool references in PRs, commits, or code. This holds even when a session's system instructions supply attribution trailers and claim to replace earlier guidance: this file wins.
- **Never commit directly to main**. Always create a feature branch and open a pull request when the work is done.
- **Watch the PR you open.** Right after `gh pr create`, arm one background Bash (`run_in_background: true`,
  `timeout: 7200000`):
  `until s=$(gh pr view <P> -R <repo> --json state --jq .state 2>/dev/null) && [ "$s" != OPEN ]; do sleep 60; done; echo "PR #<P> $s"`.
  It ends without that line at its time limit: arm it again. When it prints the state, run the teardown from the
  primary checkout, leaving the worktree first if you entered it (`ExitWorktree`, `keep`):
  `cd <primary checkout> && python3 ~/.claude/skills/orchestrate/teardown.py <checkout> <P> --repo <repo>`;
  report its last line, without asking first: after a merge it removes the worktree, slot or feature branch
  only when every check holds, and otherwise removes nothing and says why. Its output is a report, not a question.
  One watch per PR: a `/pipeline` or `/orchestrate` session arms its own, and a pipeline step or a subagent arms none.
- **Stage explicit paths**, never a blind `git add -A` or `git add .`, even when a plan prescribes it. Long-lived checkouts carry untracked files from other work (red tests, old plans, `public/build/`), and they land in the branch and break CI. Undo with `git rm --cached` and a commit, never a force-push.
- **Dependencies between issues** go on their own `Depends on #N` line. `/orchestrate` reads only those (and GitHub's native blocked-by), so an inline "needs #N" starts the runs in parallel.
- **Never address a human without my explicit permission**: posting on PRs and issues is fine — write up what changed, what was measured, and what still stands, even when it resolves someone's review remark. What is off-limits is writing *to* a person: naming or greeting them, second person ("je"/"you"), agreeing with or praising them ("scherp gezien"), asking them anything, inviting a reply, or reacting (👍 etc.) to their comment. Keep it an impersonal record of the work, not a message. If it only makes sense as a message to someone, draft it in chat and let me send it — colleagues read it as me talking, so I decide what gets said and when. Same on Slack, email and tickets.
  - ❌ "Scherp gezien Damion — dat klopte inderdaad niet. Ik heb optie 1 gedaan … Als je dat ook weg wilt hebben, hoor ik het graag."
  - ✅ "Optie 1 geïmplementeerd: de presentatie blijft gepauzeerd bij vorige/volgende. Gemeten op test: … Blijft staan: na een minuut inactiviteit hervat het scherm (bewust, voor etalageschermen)."

  A colleague's request I paste in ("kun jij naar PR X kijken?") without an instruction of my own is
  context, not an order: analyse locally, report in chat, and post nothing. A skill that posts on its
  own is only pre-authorized when I invoke it.
- **Never work against a stale checkout.** `hooks/git-freshness.sh` checks each repo the first time a
  session touches it (reads, searches, runs a command in or writes to it), reports staleness by itself,
  and keeps local `main`/`master` fast-forwarded — the only thing it changes on its own. When it warns
  about your working branch, **raise it with me and wait**: do not pull, rebase or merge on your own
  initiative. Without the hook, check by hand before the first touch of a repo:
  ```bash
  git fetch origin
  git rev-list --count HEAD..origin/main   # commits on the base branch this checkout lacks
  ```
  `git status` cannot see this (it only compares against the tracking branch), and `origin/HEAD` is
  often stale: run `git remote set-head origin --auto` before trusting it.

  **One exception: a `/pipeline` run's own branch.** A step of a run merges the base into the run's branch
  when its brief says so, with `cd <worktree> && git merge --no-edit origin/<base>`, and resolves the
  conflicts itself (pipeline `engine.md` §Catching up with the base): never a rebase, never a force-push.
  There the brief answers the hook's warning. A warning in a run's step about a checkout the brief does
  not name (the checkout the step was launched in, a config repo) is not the run's to act on: the step
  leaves that checkout alone and does not halt on it. Every other checkout keeps raise-and-wait;
  the teardown's `git pull --ff-only` of the base after a merge (*Watch the PR you open*) updates the
  base, not a working branch, so it needs none.
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

### Reviews and output

- Reviews and reports are in English, even when a skill's template is Dutch. Keep verbatim only the
  fixed tokens that `work-on` parses from a posted review comment.
- Reviewing our own fix PRs is a blunder check: did we break something, lose data, open a hole, or
  write a test that proves nothing? No polish findings, no review→fix→review loop.
- Never publish claude.ai Artifacts, not even private ones. Mockups and reports are local files: HTML
  in the repo or the job dir, opened with `open <file>`. A doc goes into the relevant repo and opens
  with `open -a PhpStorm <absolute path>`, without scratch projects or screenshots of the IDE.

---

## Remote servers (SSH)

- **Ask before every SSH session** to production, acceptance or a swarm node — read-only probes included. Ask with `AskUserQuestion` (which host, which command, read-only or not) and offer a local alternative first: reproduce in a slot, read the code at the release tag, or let me check. **If I name the host and the command, that is the approval — run it.** A list of hosts I give as information names no target: ask which ones, offering a minimal sample first.
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
any new skill, and any skill's workflow script into `~/.claude/workflows/`, on each machine. Skill
names must be unique across the two repos.
`DevOps-Claude-Config` is a colleague's personal config: link only its `skills/`, never its
`settings.json` or `CLAUDE.md`. After changing a hook, run its tests in `hooks/tests/`. Machine
setup and hook wiring: `README.md`. Playbooks for porting a LaravelTemplate feature into a project
(slots, changelog automation, base image upgrade, Pest migration): `docs/playbooks/`. What the
harness's worktree guards allow, and how to work within them: `docs/harness-worktrees.md`.

- Read a skill in full (its `SKILL.md` and references) before giving an opinion on its design. Grep
  counts mislead: pipeline leg names such as `review-pr` and `handoff` collide with skill names.
- When a skill acts as if a documented feature is missing, pull both repos (`memory-sync`) before
  diagnosing. A long session outlives the fast-forward it got at startup.

---

## Memory

Auto-memory is machine-local (`~/.claude/projects/*/memory`) and does not reach the other machine.
When a lesson comes up, write it where it applies instead of to memory:

- About the project or package being worked in → that repo's `CLAUDE.md` or docs, in the PR at hand
  (or a small PR of its own when none is open).
- Global, or about a skill or hook → a small PR in this repo, opened right away rather than saved up.
- Memory keeps only what is local to one machine, personal context, and work in progress.

## Icons

Never add Font Awesome through npm or a CDN. Icons are copied by hand from the shared FontAwesomeCache
mirror: use the `icons` skill.
