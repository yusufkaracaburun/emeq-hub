# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

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

## Technology Stack

- **PHP 8.4**, **Laravel 13.9**
- **Postgres 16** (eigen credentials + connections + audit-tabellen)
- **Redis 7** (queue + cache + session via predis)
- **FrankenPHP** (app-server, = Caddy + PHP; worker-mode via Octane) — vervangt de losse Caddy-reverse-proxy
- **Laravel Octane** v2 (FrankenPHP worker-mode, dev én prod — runtime-parity)
- **Sanctum** v4 — consumer-app auth (Personal Access Tokens)
- **Horizon** v5 — queue-dashboard + supervisor
- **Spatie webhook-server/client** — partner-event-fan-out naar consumer-callback-URLs
- **dedoc/scramble** — auto-OpenAPI op `/docs/api`
- **SDK-laag**: Saloon v4 (in `emeq/snelstart-api`; `emeq/mollie-api` wrapt `mollie/mollie-api-php` rechtstreeks) + Spatie laravel-data

Lokaal draait **de hele stack in Docker** (app + worker + vite + db + redis): `docker compose up -d --build` → `http://hub.emeq.test:8092`. Dev = FrankenPHP worker-mode met `watch` (instant code-reload, geen rebuild) + Vite-HMR; identiek aan prod op runtime-niveau. Host-`php artisan serve` is enkel nog fallback. Zie `docker/Caddyfile{,.dev}` + `Dockerfile` (multi-stage). Prod: `docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build` (HTTP-origin op `:80` achter Cloudflare-TLS + horizon; `trustProxies` aan).

Stack-details voor agents: `docs/agents/dev-environment.md` (commands + doc-URLs) en `docs/agents/architecture.md` (lagen + componenten). Framework-/package-guidelines (PHP, Laravel, Pint, PHPUnit, Boost) worden onderaan dit bestand via `@AGENTS.md` geïmporteerd.

## Conventions

Authoritative regels staan in `.ai/rules/` (auto-loaded):

- **Taal**: code/identifiers Engels, commits/PRs/docs/conversatie Nederlands, partner-domeintermen volgen de partner-API (`.ai/rules/global.md`).
- **Engineering**: chirurgisch wijzigen, conflicten oppervlakken niet uitmiddelen, lezen vóór schrijven (`.ai/rules/engineering.md`).
- **Security**: tokens encrypted at rest, fingerprint-only in logs, per-Connection webhook-secrets (`.ai/rules/global.md`).
- **Geen verzonnen partner-features**: alles moet kloppen met de partner-research in de SDK-repos (`packages/<sdk>/docs/partners/<provider>/`).

Projectspecifieke conventies stollen in `.ai/rules/`; er is geen aparte conventions-tracker meer.

## Architecture

`emeq/hub` is een multi-tenant integration platform: één centrale Laravel-app die OAuth-koppelingen, webhook-routing en een uniforme REST-API exposeert naar boekhoud-/betaal-partner-API's:

- **Snelstart** (boekhouden, NL) — via eigen SDK `emeq/snelstart-api` (VCS-repo, zie packages-conventie)
- **Mollie** (betalingen, NL/EU) — via eigen SDK `emeq/mollie-api` (VCS-repo) bovenop officiële `mollie/mollie-api-php` + `mollie/laravel-cashier-mollie` voor Subscriptions
- **Moneybird** (boekhouden, NL) — gepland, via toekomstige `emeq/moneybird-api` SDK
- **Ibanity** (PSD2/banking) — gepland
- **Exact Online** (boekhouden, NL/BE) — via eigen SDK `emeq/exact-api` (VCS-repo, Saloon); OAuth2-lifecycle + division-aware pass-through + named read-resources + accounting-sync zijn live

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
                              │  - inbound_webhook_events│
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
| **InboundWebhookEvent** | Metadata-only audit van **inkomende** partner→Hub-webhooks (Snelstart/Exact/Mollie/Cashier) via `App\Webhooks\InboundWebhookRecorder`. **Géén payload/headers** (AVG, de Hub is processor). Getypt voor incident-triage (provider/topic/action/outcome/status/fanout). Outbound fan-out (Hub→consumer) loopt via `spatie/laravel-webhook-server` (persisteert geen rij). |

**Architectuur-invariants — niet zonder approval doorbreken:**

- **Consumer ↔ Account ↔ Connection chain is strict.** Een endpoint dat een Connection resolved doet dat altijd via `Bearer-token → Consumer → Account → Connection`. Nooit query-string `?connection_id=`, nooit X-headers zonder Consumer-validatie.
- **Tokens zijn versleuteld at rest.** `access_token`, `refresh_token`, `client_key` etc. op het Connection-model krijgen `protected $casts = ['access_token' => 'encrypted', …]`. Geen rauwe tokens in DB.
- **Geen partner-business-logic in SDK-packages.** SDKs zijn dun: HTTP-laag, auth-laag, DTOs. Webhook-routing, multi-tenancy, audit — leeft in de Hub.
- **Migrations zijn forward-only in prod.** Geen `down()` aanroepen na merge; voor schema-changes nieuwe migration.

Lees dit vóór architecturele beslissingen.

Snelle pointers — één regel per subsysteem; de volledige versie (alle gotchas + `.docs/decisions`-links) staat in `docs/agents/subsystems.md`:

- **Werkdocumentatie** (lokaal, gitignored) — `.docs/{decisions,plans,errors,stack}/`; indeling in `.docs/README.md`. Partner-research in de SDK-repos.
- **Admin-paneel** — Filament v4 op `/admin`; Spatie-rollen `super-admin`/`staff`/`boekhouder`; 4 NL nav-groups; Books-module top-level, gated via `GatedToBoekhouding`. Zie `.docs/decisions/books-module.md`.
- **Provider-credential-laag** — `config/hub-providers.php` + `ProviderCredentialDescriptor` = single source voor credential-metadata; identiteit getypeerd via `App\Enums\Provider`.
- **Feature-flags / kill-switch** — Pennant `feature.provider:{provider}` op `/v1/*`; features auto-gedefinieerd uit `config('hub-providers')`.
- **Accounting-sync** — canonical `FinancialDocument` op `POST /v1/accounting/documents` → `AccountingTargetRegistry` → provider-adapter (Exact: salesentry/purchaseentry, géén memoriaal); mapping auto-afgeleid na connect via mirror; dry-run via `POST /v1/accounting/documents/validate` (findings-rapport zónder te boeken). Details + gotchas: subsystems.md.
- **Idempotency (Hub-breed)** — `Idempotency-Key`-header via `EnsureIdempotency`; `idempotent:required` op accounting-documents.
- **Exact pass-through + named resources** — `/v1/exact/*` via `ExactForwarder`; named read-resources vóór de `{path}`-catch-all; wire-details leven in de SDK.
- **Partner-credentials in DB** — `ExactSettings` (encrypted at rest) → `config('services.exact.*')`; niet `.env`. Zie `.docs/decisions/db-managed-credentials.md`.
- **Webhooks** — Exact: `POST /webhooks/exact`, HMAC-signature (uppercase hex!), subscriptions in de OAuth-lifecycle, live-gotchas (Cloudflare Bot Fight, CallbackURL-domein) in subsystems.md. Alle inkomende partner→Hub-webhooks ge-audit via `InboundWebhookRecorder` → `inbound_webhook_events`, metadata-only (AVG).
- **OAuth-flow** — `/v1/oauth/{provider}/init` auto-provisiont het Account (`firstOrCreate`, PAT-ability `integrations:manage`); return-to-consumer via `ReturnUrlResolver` + `consumers.app_url` (open-redirect-guard).
- **`/v1/*` error-contract** — alle fouten JSON (ook zonder Accept-header); ontbrekende PAT → `401 {code:"unauthenticated"}`, geen login-redirect.
- **Publieke SEO/GEO-surface** — `App\Support\PublicPages` is de enige bron voor "wat is indexeerbaar" (gedeeld door `SetNoIndexHeaders`, sitemap en robots.txt); per-pagina meta + JSON-LD worden server-side gebouwd in `App\Support\Seo\{SeoMeta,Schema}` en als Inertia-prop gerenderd door `<Seo>`. Crawler-bestanden zijn routes, geen bestanden in `public/`. Inertia-SSR is verplicht voor die surface: zonder SSR zien crawlers zonder JS een lege body. Lokaal: `make ssr` (stopt Vite-HMR), prod: eigen `ssr`-service — die bundel moet óók in het app-image zitten, anders slaat Inertia SSR stil over. **Gotcha:** Cloudflare injecteert een Managed robots.txt-blok vóór het onze dat de trainings-crawlers zone-breed weert; `RobotsController` noemt daarom alleen de bots die naar de bron linken (dubbele groepen voor dezelfde user-agent worden samengevoegd, en Cloudflares `Disallow: /` wint).

De gedetailleerde laag-/componentkaart staat in `docs/agents/architecture.md`.

## Dev-setup

Eerste-keer-setup (hosts-entry, `.env`, Docker-stack, migraties, SDK-clone) staat in `docs/agents/dev-environment.md` § Project-specific notes. Health-check: `http://hub.emeq.test:8092/up` → `{"status":"up","database":"ok","redis":"ok"}`. Dev = FrankenPHP worker-mode met `watch` + Vite-HMR; tests in de container: `docker compose exec app php artisan test --compact`.

### Veelgebruikte commando's

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

### Routes

```
routes/web.php       GET /up; publiek /oauth/{connected/{connection},failed} (signed OAuth-landing) + de indexeerbare marketing-surface: / · /partners{,/{provider}} · /koppelen · /demo · /support · /privacy · /voorwaarden · /verwerkersovereenkomst, plus de crawler-bestanden /sitemap.xml · /robots.txt · /llms.txt
routes/console.php   artisan-only commands (inspire)
routes/api.php       /v1/* — consumer-API (Bearer Sanctum + throttle:api)
routes/webhooks.php  /webhooks/{provider}/{...} + /cashier/webhook* — publiek, signature-verified
```

## Packages-conventie

**`packages/` is gitignored** en is een **lees-clone** voor referentie/grep. SDK-packages hebben elk een eigen GitHub-repo:

- `packages/snelstart-api/` ← `github.com:yusufkaracaburun/emeq-snelstart-api`
- `packages/mollie-api/` ← `github.com:yusufkaracaburun/emeq-mollie-api`

Composer require't de SDKs via een **VCS repository** in `composer.json` — niet meer via een path-symlink. Reden: `packages/` bestaat niet op Laravel Cloud, dus een path-dist in `composer.lock` breekt de deploy.

**Workflow voor SDK-changes**: edit + commit + push in de SDK-repo zelf, dan in de Hub `composer update emeq/<name>` en `composer.lock` committen. Geen live-edit-symlink; itereer in de SDK-repo met `./vendor/bin/pest` en sync pas als de change stabiel is. Beslis-gids: change-sdk-skill.

## Git policy — harde regels

- Nooit op `master` werken.
- Nooit `git push` zonder expliciete user-toestemming.
- Nooit `--no-verify`, `--no-gpg-sign`, of force-push tenzij user expliciet vraagt.
- Nooit secrets committen. Nooit `.env` aanpassen zonder approval.
- Nooit >3 files wijzigen in één commit zonder approval.

## Project Skills

| Skill | Description | Path |
|-------|-------------|------|
| docs-sync | Detecteert + herstelt documentatie-drift en organisatie-issues in `.docs/`, `CLAUDE.md` en memory; proactief na domein-wijzigingen en vóór commit/push. Volledige triggerlijst in de SKILL.md. | `.claude/skills/docs-sync/SKILL.md` |
| add-provider | Step-by-step nieuwe partner-provider: dunne `emeq/<provider>-api` SDK bouwen + aan de Hub koppelen. Laag-grens: state→Hub, protocol→SDK. | `.claude/skills/add-provider/SKILL.md` |
| change-sdk | Beslis-gids voor wijzigingen aan een bestaande SDK, met de tabel "raak ik de Hub aan?". | `.claude/skills/change-sdk/SKILL.md` |

## Workflow & Agent skills

ai-kit draait als plugin (`/ai:*`-skills beschikbaar), geconfigureerd via `.ai-kit-setup` (`tier=full`, `mode=solo-global`). Lifecycle-fase: **development** — schema-migraties vrij te wijzigen, geen backwards-compat-eis vóór productie.

- **Werkwijze**: feature-/fix-branch → tests groen → ff-merge naar `master` (geen PR-ceremonie voor solo-werk). Detail in `docs/agents/workflow.md`. Open + forward-werk staat in GitHub-issues (`P*`/`area/*`-labels; `/ai:next` rankt ze).
- **Entrypoints**: `/ai:tdd` (feature/bugfix TDD), `/ai:diagnose` (onderzoek/bug), `/ai:to-issues` (plan → issues), `/ai:review` (pre-merge). De `branch-guard`-hook blokkeert edits op `master`.
- **Docs**: per-onderwerp in `docs/agents/`; authoritative regels in `.ai/rules/` (auto-loaded); ai-kit canonical rules in `.claude/rules/` (gitignored, aanvullend).

## Laravel Boost guidelines

Boost beheert `AGENTS.md`; hieronder geïmporteerd zodat Claude Code dezelfde guidelines laadt zonder tweede kopie (de inline kopie was al gedivergeerd van AGENTS.md):

@AGENTS.md

Aanvulling uit de oude inline-kopie (niet in AGENTS.md): env-waarden checken → lees `.env` direct.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- filament/filament (FILAMENT) - v4
- inertiajs/inertia-laravel (INERTIA_LARAVEL) - v3
- laravel/framework (LARAVEL) - v13
- laravel/horizon (HORIZON) - v5
- laravel/octane (OCTANE) - v2
- laravel/pennant (PENNANT) - v1
- laravel/prompts (PROMPTS) - v0
- laravel/sanctum (SANCTUM) - v4
- livewire/livewire (LIVEWIRE) - v3
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- phpunit/phpunit (PHPUNIT) - v12
- @inertiajs/react (INERTIA_REACT) - v3
- react (REACT) - v19
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

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-react-development` when working with Inertia client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

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

=== octane/core rules ===

# Laravel Octane

This application uses Laravel Octane, a long-running PHP server. The application bootstraps once and handles many requests within the same process.

- Never store request-specific state in singletons or static properties, because it can leak across requests.
- Use `config('octane.server')` to detect the active driver (`swoole`, `roadrunner`, or `frankenphp`).
- Prefer scoped bindings (`$this->app->scoped()`) over singletons for per-request services.

When working on Octane-specific features (concurrency, shared tables, memory, driver configuration, testing), invoke `octane-development` for detailed rules.

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

=== inertia-react/core rules ===

# Inertia + React

- IMPORTANT: Activate `inertia-react-development` when working with Inertia React client-side patterns.

</laravel-boost-guidelines>
