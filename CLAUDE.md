# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

<!-- GSD:project-start source:PROJECT.md -->
## Project

**Emeq integration stack (v0.2)**

Een Hub-platform en losse, Saloon-gebaseerde Laravel SDK-packages (`emeq/snelstart-api`, `emeq/mollie-api`) voor Nederlandse boekhoud- en betaal-partner-API's. De Hub (`emeq-hub`) host multi-tenant OAuth-koppelingen, webhook-routing en een pass-through REST-API; SDKs leveren de partner-specifieke wrapping. v0.2 bouwt Mollie + Connect + Subscriptions + Hub-skeleton bovenop het in v0.1 gevalideerde Snelstart-pattern, met Naschool als eerste concrete consumer-feature. Doelgroep v0.2: Emeq's eigen SaaS-apps die nu ad-hoc partner-integraties hebben. Doelgroep v1.0+ (later): commercieel beschikbaar voor andere NL dev-shops.

**Core Value:** **Twee fundamenteel verschillende providers (OData/clientkey + REST/OAuth2) productie-gevalideerd via één SDK-pattern, en beide live in één concrete Naschool-feature.** Dat valideert het pattern voor toekomstige SDKs en levert directe DRY-winst in Naschool.

### Constraints

- **Tech stack**: PHP 8.4, Laravel 13.9, Saloon v4 (gebruikt in `emeq/snelstart-api`; `emeq/mollie-api` wrapt `mollie/mollie-api-php` rechtstreeks, geen Saloon-laag), Spatie laravel-data. Tests: PHPUnit 12 in de Hub, Pest in SDK-packages (`packages/snelstart-api/`, straks `packages/mollie-api/`). Geen afwijking zonder approval.
- **Timeline**: v0.2-indicatie ~8-10 weken vanaf milestone-kickoff 2026-05-14.
- **Repo-grenzen**: SDK-packages krijgen géén Hub-domeinmodellen (`Connection`, `Account`, etc.) — invariant uit CLAUDE.md.
- **Tokens encrypted at rest**: gevoelige credentials (clientkey, subscription-key, API-key) nooit raw in DB of logs. Fingerprint-only voor debugging.
- **Geen verzonnen partner-features**: code moet exact kloppen met officiële Snelstart/Mollie docs (per partner gebundeld in de SDK-repo's onder `packages/<sdk>/docs/partners/`).
- **Git-policy**: nooit op `master` werken, nooit pushen zonder approval, geen `--no-verify`.
<!-- GSD:project-end -->

<!-- GSD:stack-start source:STACK.md -->
## Technology Stack

Zie de Laravel Boost-guidelines onderaan dit bestand (`.ai/project rules` block) voor de canonical stack-beschrijving: PHP 8.4 / Laravel 13.9 / Postgres 16 / Redis 7 / Caddy 2 / Sanctum v4 / Horizon v5 / Spatie webhook-server+client / dedoc/scramble. SDK-laag: Saloon v4 + Spatie laravel-data.

Een aparte `.planning/STACK.md` wordt aangemaakt bij `/gsd-map-codebase` of de eerste phase die diepere stack-conventies vastlegt.
<!-- GSD:stack-end -->

<!-- GSD:conventions-start source:CONVENTIONS.md -->
## Conventions

Authoritative regels staan in `.ai/rules/` (auto-loaded) en `.ai/guidelines/emeq-hub/` (in de Laravel Boost-block onderaan):

- **Taal**: code/identifiers Engels, commits/PRs/docs/conversatie Nederlands, partner-domeintermen volgen de partner-API (`.ai/rules/global.md`).
- **Engineering**: chirurgisch wijzigen, conflicten oppervlakken niet uitmiddelen, lezen vóór schrijven (`.ai/rules/engineering.md`).
- **Security**: tokens encrypted at rest, fingerprint-only in logs, per-Connection webhook-secrets (`.ai/rules/global.md`).
- **Geen verzonnen partner-features**: alles moet kloppen met `.docs/partners/<provider>/`.

Een aparte `.planning/CONVENTIONS.md` volgt zodra projectspecifieke patronen stollen die niet in `.ai/rules/` thuishoren.
<!-- GSD:conventions-end -->

<!-- GSD:architecture-start source:ARCHITECTURE.md -->
## Architecture

De canonical architectuur-beschrijving (Consumer → Account → Connection → SDK-call chain, domeinmodel-tabel, invariants) staat in de Laravel Boost-block onderaan dit bestand (`.ai/project rules`). Lees die vóór architecturele beslissingen.

Snelle pointers:
- **Planning-artefacten**: `.planning/ROADMAP.md`, `.planning/STATE.md`, `.planning/phases/<NN>-<slug>/` voor lopend fase-werk.
- **Werkdocumentatie** (lokaal, gitignored): `.docs/decisions/` (ADRs), `.docs/partners/<provider>/` (officiële API-research), `.docs/plans/`, `.docs/errors/`, `.docs/stack/`. Lees `.docs/README.md` voor de indeling.
- **Routes**: `routes/web.php` (smoke `/`, `/up`; in `local`/`testing`-env ook `/admin/quick-login/{role?}` + `/dev/partners[/{provider}]`), `routes/console.php`, `routes/api.php` (`/v1/*` consumer-API achter Sanctum + `throttle:api`) en `routes/webhooks.php` (`/webhooks/{provider}/{...}` + Cashier-webhooks, publiek signature-verified) zijn geland.
- **Admin-paneel**: Filament v4 op `/admin` (Phase 9, HUB-04). `User` implementeert `FilamentUser` + `HasRoles` (Spatie); admin-access via Spatie-rollen `super-admin`/`staff` (zie `EmeqStaffSeeder`). Resource-management voor `manage-staff` ge-gate via gate in `AppServiceProvider::boot()`. 8 Resources gegroepeerd in 4 navigation-groups (Tenants / Integraties / Abonnementen / Beheer), incl. de read-only `PassThroughCallResource` (Integraties, gate `view-pass-through-calls`).
- **Provider-credential-laag** (D-04): `config/hub-providers.php` + `App\Support\ProviderCredentialDescriptor` is de single source of truth voor per-provider credential-**metadata**. `Connection::fingerprint()` + Filament-views + `ConnectionStatsWidget` consumen via descriptor. Nieuwe provider = config-row + factory-state + infolist Section, geen nieuwe Resource-class. Zie `.docs/decisions/provider-credential-descriptor.md`. De provider-**identiteit** is getypeerd via `App\Enums\Provider` (string-backed, Filament `HasLabel`/`HasColor`); `Connection::provider` is hierop gecast en de enum vervangt verspreide `'mollie'`/`'snelstart'`-literals (audit A1, `docs/reviews/2026-06-15-emeq-hub-architecture-audit.md`).
- **Feature-flags / kill-switch** (Phase 8): Pennant-based provider kill-switch via `feature.provider:{provider}` middleware-alias (`bootstrap/app.php:37` → `EnsureProviderEnabled`) op `/v1/{mollie,snelstart}/*`. `OAuthFlowRegistry::for()` checkt dezelfde feature en gooit `ProviderDisabledException` als inactive. Features auto-gedefinieerd in `FeatureServiceProvider` op basis van `config('hub-providers')` keys — nieuwe provider = nieuwe config-row, geen middleware/registry-edit. Zie `.docs/decisions/feature-flags-pennant-kill-switch.md`.

Een aparte `.planning/ARCHITECTURE.md` wordt aangemaakt door `/gsd-map-codebase` zodra de huidige domeinlaag (OAuth-flow-registry, Mollie + Snelstart pass-through, Cashier-Mollie subscriptions, Filament admin-paneel + provider-descriptor) een vaste vorm krijgt.
<!-- GSD:architecture-end -->

<!-- GSD:skills-start source:skills/ -->
## Project Skills

| Skill | Description | Path |
|-------|-------------|------|
| docs-sync | Detecteert en herstelt documentatie-drift én organisatie-issues in `.docs/`, `CLAUDE.md` en memory voor de emeq-hub repo. Triggert proactief na domein-wijzigingen — niet wachten op merge: model/entity hernoemd, kolom verplaatst, nieuwe migration, nieuwe Sanctum-ability of Connection-provider, OAuth-flow gewijzigd, SDK-package toegevoegd of verwijderd uit `packages/`, route toegevoegd of verwijderd. Triggert ook bij doc-toevoegingen of -verplaatsingen in `.docs/`. Reactief op vragen als "check de docs", "update de docs", "klopt de documentatie nog", "synchroniseer docs", "klaar voor commit?", "ruim de docs op". Vangt zes problemen af: (1) stale class-/file-references, (2) ontbrekende ADR voor architecturele wijzigingen, (3) completed TODOs die niet als ✅ zijn gemarkeerd, (4) structuur-drift (nieuwe folders/files niet in `.docs/README.md` index, files op verkeerde plek), (5) verweesde docs (gemergde plans nog in `plans/`, lange ongewijzigde files), en (6) dode links (markdown-links naar non-existing files of code-paden). Use proactively whenever the user wraps up a domein-wijziging, just merged a branch, ran a refactor, added/moved a doc, or before any commit/push. | `.claude/skills/docs-sync/SKILL.md` |
<!-- GSD:skills-end -->

<!-- GSD:workflow-start source:GSD defaults -->
## GSD Workflow Enforcement

Before using Edit, Write, or other file-changing tools, start work through a GSD command so planning artifacts and execution context stay in sync.

Use these entry points:
- `/gsd-quick` for small fixes, doc updates, and ad-hoc tasks
- `/gsd-debug` for investigation and bug fixing
- `/gsd-execute-phase` for planned phase work

Do not make direct repo edits outside a GSD workflow unless the user explicitly asks to bypass it.
<!-- GSD:workflow-end -->

<!-- GSD:profile-start -->
## Developer Profile

> Profile not yet configured. Run `/gsd-profile-user` to generate your developer profile.
> This section is managed by `generate-claude-profile` -- do not edit manually.
<!-- GSD:profile-end -->

===

<laravel-boost-guidelines>
=== .ai/dev-setup rules ===

## Lokale dev — eerste keer

```bash

# 0. Eenmalig: /etc/hosts toevoegen

echo "127.0.0.1 hub.emeq.test" | sudo tee -a /etc/hosts

# 1. .env van .env.example

cp .env.example .env
php artisan key:generate

# 2. Stack omhoog (postgres + redis + caddy)

docker compose up -d

# 3. Composer + migraties (SDK wordt automatisch vanaf GitHub gepakt)

composer install
php artisan migrate

# 4. (Optioneel) SDK clonen in packages/ voor referentie/grep — geen live-edit-link

mkdir -p packages
git clone git@github.com:yusufkaracaburun/emeq-snelstart-api.git packages/snelstart-api

# 5. Laravel + Horizon op host

php artisan serve --port=8001
php artisan horizon  # in 2e terminal

# 6. SDK-changes: edit in de SDK-repo zelf, commit + push, daarna in de Hub:

#    composer update emeq/snelstart-api

```

Open `http://hub.emeq.test:8090/up` → moet `{"status":"up","database":"ok","redis":"ok"}` teruggeven.

## Veelgebruikte commando's

```bash

# DB

php artisan migrate
php artisan migrate:fresh --seed

# Tests — Hub (PHPUnit)

php artisan test --compact
php artisan test --compact --filter=ExampleTest

# Tests — SDK-package (Pest, eigen vendor)

cd packages/snelstart-api && ./vendor/bin/pest

# Format

./vendor/bin/pint --dirty --format agent   # voor commit

# Horizon

php artisan horizon
php artisan horizon:status

# Routes

php artisan route:list --except-vendor

# Composer audit

composer audit                              # zie ignored advisories in composer.json

```

## Routes

```
routes/web.php       smoke: GET /, GET /up
routes/console.php   artisan-only commands (inspire)
routes/api.php       /v1/* — consumer-API (Bearer Sanctum + throttle:api)
routes/webhooks.php  /webhooks/{provider}/{...} + /cashier/webhook* — publiek, signature-verified
```

=== .ai/git-policy rules ===

## Git policy — harde regels

- Nooit op `master` werken.
- Nooit `git push` zonder expliciete user-toestemming.
- Nooit `--no-verify`, `--no-gpg-sign`, of force-push tenzij user expliciet vraagt.
- Nooit secrets committen. Nooit `.env` aanpassen zonder approval.
- Nooit >3 files wijzigen in één commit zonder approval.

=== .ai/packages rules ===

## Packages-conventie

**`packages/` is gitignored** en is een **lees-clone** voor referentie/grep. SDK-packages hebben elk een eigen GitHub-repo:

- `packages/snelstart-api/` ← `github.com:yusufkaracaburun/emeq-snelstart-api`
- `packages/mollie-api/` ← `github.com:yusufkaracaburun/emeq-mollie-api`

Composer require't de SDKs via een **VCS repository** in `composer.json` — niet meer via een path-symlink. Reden: `packages/` bestaat niet op Laravel Cloud, dus een path-dist in `composer.lock` breekt de deploy.

**Workflow voor SDK-changes:**

1. Edit in de SDK-repo (eigen clone, kan `packages/<name>/` zijn).
2. Commit + push naar de SDK GitHub-repo.
3. In de Hub: `composer update emeq/<name>` — pinst de nieuwe VCS-reference in `composer.lock`.
4. Commit `composer.lock` in de Hub.

Geen live-edit-symlink meer. Voor snelle iteratie in de SDK: werk daar gewoon zelf met `./vendor/bin/pest` in de SDK-repo, en sync pas naar de Hub als de change stabiel is.

=== .ai/project rules ===

## Wat dit project is

`emeq/hub` — multi-tenant integration platform. Eén centrale Laravel-app die OAuth-koppelingen, webhook-routing en een uniforme REST-API exposeert naar boekhoud-/betaal-partner-API's:

- **Snelstart** (boekhouden, NL) — via eigen SDK `emeq/snelstart-api` (VCS-repo, zie packages-conventie)
- **Mollie** (betalingen, NL/EU) — via eigen SDK `emeq/mollie-api` (VCS-repo) bovenop officiële `mollie/mollie-api-php` + `mollie/laravel-cashier-mollie` voor Subscriptions
- **Moneybird** (boekhouden, NL) — gepland, via toekomstige `emeq/moneybird-api` SDK
- **Ibanity** (PSD2/banking) — gepland
- **Exact Online** (boekhouden, NL/BE) — gepland

**Doelgroep v0.2**: Emeq's eigen SaaS-apps die nu ad-hoc partner-integraties hebben (Snelstart-pattern in v0.1 gevalideerd, Mollie + Connect + Subscriptions + Hub-skeleton in v0.2). v1.0+: commercieel beschikbaar voor andere NL dev-shops.

## Stack

- **PHP 8.4**, **Laravel 13.9**
- **Postgres 16** (eigen credentials + connections + audit-tabellen)
- **Redis 7** (queue + cache + session via predis)
- **Caddy 2** (reverse-proxy → host's `php artisan serve` op port 8001)
- **Sanctum** v4 — consumer-app auth (Personal Access Tokens)
- **Horizon** v5 — queue-dashboard + supervisor
- **Spatie webhook-server/client** — partner-event-fan-out naar consumer-callback-URLs
- **dedoc/scramble** — auto-OpenAPI op `/docs/api`

Lokaal: `php artisan serve --port=8001` op host, `docker compose up -d` voor db+redis+caddy → `http://hub.emeq.test:8090`.

## Architectuur

```
┌─────────────┐  HTTP/REST    ┌──────────────────────────┐  SDK calls   ┌─────────────┐
│ Consumer    │ ─────────────►│   emeq/hub (this app)    │ ───────────► │ Partner API │
│ (= SaaS app │               │                          │              │  (Snelstart,│
│  van Emeq   │ ◄─────────────│  Routes Bearer + ConnID  │              │   Mollie,   │
│  of derde)  │  webhooks     │  → right Connection →    │  webhooks    │   Moneybird,│
└─────────────┘               │  right SDK + tokens      │ ◄─────────── │   …)        │
                              │  → forward + audit       │              └─────────────┘
                              │                          │
                              │  Tables:                 │
                              │  - consumers             │
                              │  - personal_access_tokens│
                              │  - accounts              │
                              │  - connections           │
                              │  - pass_through_calls    │
                              │  - webhook_calls (Spatie)│
                              └──────────────────────────┘
```

**Domeinmodel:**

| Entity | Rol |
|---|---|
| **Consumer** | Eén van Emeq's 3 SaaS-apps, óf een betalende derde |
| **PersonalAccessToken** | Sanctum-token waarmee Consumer authentiseert |
| **Account** | Eindgebruiker bij een Consumer (= klant van die SaaS-app) — opgeslagen by `consumer_id + external_id` |
| **Connection** | Eén OAuth-koppeling tussen één Account en één Provider (Mollie/Snelstart/…). Encrypted tokens + expires_at + scopes |
| **PassThroughCall** | Audit-log voor Hub-pass-through-calls (Consumer → Hub → Partner → Consumer). Eén rij per request, immutable. Zie `.docs/decisions/pass-through-calls-table.md`. |
| **WebhookCall** (Spatie) | Fan-out-audit voor inkomende partner-webhooks en uitgaande consumer-callbacks via `spatie/laravel-webhook-client` + `…-server`. |

## Architectuur-invariants — niet zonder approval doorbreken

- **Consumer ↔ Account ↔ Connection chain is strict.** Een endpoint dat een Connection resolved doet dat altijd via `Bearer-token → Consumer → Account → Connection`. Nooit query-string `?connection_id=`, nooit X-headers zonder Consumer-validatie.
- **Tokens zijn versleuteld at rest.** `access_token`, `refresh_token`, `client_key` etc. op het Connection-model krijgen `protected $casts = ['access_token' => 'encrypted', …]`. Geen rauwe tokens in DB.
- **Geen partner-business-logic in SDK-packages.** SDKs zijn dun: HTTP-laag, auth-laag, DTOs. Webhook-routing, multi-tenancy, audit — leeft in de Hub.
- **Migrations zijn forward-only in prod.** Geen `down()` aanroepen na merge; voor schema-changes nieuwe migration.

=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- filament/filament (FILAMENT) - v4
- laravel/framework (LARAVEL) - v13
- laravel/horizon (HORIZON) - v5
- laravel/nightwatch (NIGHTWATCH) - v1
- laravel/pennant (PENNANT) - v1
- laravel/prompts (PROMPTS) - v0
- laravel/sanctum (SANCTUM) - v4
- livewire/livewire (LIVEWIRE) - v3
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- phpunit/phpunit (PHPUNIT) - v12
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.
- To check environment variables, read the `.env` file directly.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This application uses PHPUnit for testing. All tests must be written as PHPUnit classes. Use `php artisan make:test --phpunit {name}` to create a new test.
- If you see a test using "Pest", convert it to PHPUnit.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should cover all happy paths, failure paths, and edge cases.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files; these are core to the application.

## Running Tests

- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `php artisan test --compact`.
- To run all tests in a file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --compact --filter=testName` (recommended after making a change to a related file).

</laravel-boost-guidelines>
