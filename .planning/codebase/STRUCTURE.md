# Codebase Structure

**Analysis Date:** 2026-05-15

## Directory Layout

```
emeq-hub/
├── .ai/                      # Auto-loaded AI-rules + skills + guidelines (committed)
│   ├── rules/                # `.md` auto-loaded by Claude (global, engineering, dev-setup, packages, git-policy, project, claude-mem-context)
│   ├── skills/               # docs-sync, laravel-best-practices, configuring-horizon
│   └── guidelines/emeq-hub/  # Laravel-boost-block guidelines
├── .claude/                  # Claude-local state (plans, hooks, worktrees, skills)
├── .cursor/                  # Cursor IDE rules + skills mirror
├── .docs/                    # Werkdocumentatie (gitignored)
│   ├── decisions/            # ADRs (pass-through-calls-table, mollie-passthrough-api, …)
│   ├── partners/             # Officiële partner-API research (snelstart/, mollie/)
│   ├── plans/                # Transient implementatie-plannen
│   ├── todos/                # Geparkeerde follow-ups
│   ├── errors/               # Lessons-bugs
│   ├── stack/                # Tool-specifieke patronen
│   └── .archive/             # Voltooide plans/todos
├── .junie/                   # Junie IDE skills mirror
├── .planning/                # GSD workflow artefacten
│   ├── ROADMAP.md, STATE.md, PROJECT.md, REQUIREMENTS.md, MILESTONES.md, config.json
│   ├── phases/<NN-slug>/     # Per-fase CONTEXT/PLAN/SUMMARY/REVIEW/VERIFICATION
│   ├── milestones/v0.1-phases/
│   ├── quick/<datum-slug>/   # `/gsd-quick`-uitvoeringen
│   └── codebase/             # ← deze map: STACK/INTEGRATIONS/ARCHITECTURE/STRUCTURE/CONVENTIONS/TESTING/CONCERNS
├── app/                      # Laravel-applicatie-code (PSR-4 `App\`)
│   ├── Billing/              # Cashier-Mollie plan-resolver + UnknownPlanException
│   ├── Console/Commands/     # Artisan-commands (HubConsumerCreate, PruneOAuthPendingConnections)
│   ├── Http/
│   │   ├── Controllers/      # Api\V1\{…}, Webhooks\…
│   │   ├── Middleware/       # Resolve{Mollie,Snelstart}Account, EnsureEmeqAdminToken, RequireCashierWebhookSecret, SetNoIndexHeaders
│   │   ├── Requests/         # Form-Requests (Admin/Billing/…, Api/V1/{Mollie,…})
│   │   └── Resources/Api/V1/ # JsonResource klassen (AccountResource, ConnectionResource)
│   ├── Jobs/                 # ForwardMollieWebhookToConsumer
│   ├── Models/               # Consumer, Account, Connection, PassThroughCall, User
│   ├── Mollie/               # MollieConnectionContext (scoped), HubMollieCredentialResolver
│   ├── OAuth/                # Contracts\OAuthFlow, OAuthFlowRegistry, Mollie\MollieConnectOAuthFlow, Testing\FakeOAuthFlow
│   ├── Providers/            # AppServiceProvider (scoped/singleton bindings + Scramble + RateLimiter)
│   ├── Sanctum/              # TokenAbilities (final class met public const)
│   ├── Services/Snelstart/   # HubSnelstartCredentialResolver
│   └── Support/              # Snelstart\{HeaderForwarder,UpstreamErrorMapper}, Mollie\{MollieHeaderForwarder,MollieUpstreamErrorMapper,ConsumerIdempotencyKeyGenerator}
├── bin/                      # Repo-tooling (lokale scripts)
├── bootstrap/                # Laravel application-bootstrap (`app.php` met withRouting/Middleware/Exceptions)
├── config/                   # Laravel-config (app, auth, cashier, cashier_plans, cashier_coupons, billing, billing-plans, mollie, services, sentry, horizon, webhook-client, webhook-server, scramble, …)
├── database/
│   ├── factories/            # ConsumerFactory, AccountFactory, ConnectionFactory (forSnelstart/forMollie/pending/active/expired), PassThroughCallFactory, UserFactory
│   ├── migrations/           # 24 migraties (incl. Cashier-Mollie published set)
│   └── seeders/              # DatabaseSeeder (production-guarded)
├── docker/                   # Lokale Caddy/Postgres/Redis-compose-assets
├── packages/                 # SDK read-clones (gitignored; VCS-installed via composer)
│   ├── mollie-api/           # github.com:yusufkaracaburun/emeq-mollie-api  (Saloon-loos, wrapt mollie/mollie-api-php)
│   │   └── src/{Mollie.php, MollieServiceProvider.php, Contracts/, Data/, Exceptions/, Facades/, Idempotency/, Testing/, Webhooks/}
│   └── snelstart-api/        # github.com:yusufkaracaburun/emeq-snelstart-api  (Saloon v4 connector + OData QueryBuilder)
│       └── src/{Snelstart.php, SnelstartServiceProvider.php, Auth/, Contracts/, Data/, Exceptions/, Facades/, Http/, OData/}
├── public/                   # Laravel public/index.php + assets
├── resources/                # Vue/Blade/CSS (views/partners/ minimaal — Hub is API-first)
├── routes/                   # web.php, api.php (`/v1/*`), webhooks.php, console.php
├── storage/                  # Laravel runtime
├── tests/                    # PHPUnit (Feature/Unit/Integration)
├── vendor/                   # Composer dependencies
├── .env.example, .env        # Env-template + lokaal config (laatste gitignored)
├── AGENTS.md, CLAUDE.md, README.md
├── api.json                  # OpenAPI (Scramble snapshot)
├── boost.json, composer.json, composer.lock, package.json, vite.config.js
├── docker-compose.yml
├── phpunit.xml, phpunit.integration.xml   # Twee suites; integration via `composer test:integration`
└── artisan
```

## Directory Purposes

**`app/Models/`:**

- Purpose: Eloquent-laag voor multi-tenant Hub-data + Cashier-Billable.
- Contains: 5 modellen, géén vendor-models (Cashier-modellen leven in `vendor/`).
- Key files: `Connection.php` (encrypted casts + `fingerprint()`), `Consumer.php` (`Billable` + `HasApiTokens`), `Account.php`, `PassThroughCall.php` (`$timestamps=false`), `User.php` (Filament-staff, Phase 9).

**`app/Http/Controllers/Api/V1/`:**

- Purpose: REST-controllers per ressource.
- Contains: `AccountController`, `ConnectionController`, `PingController`, `OAuth/{Init,Callback}Controller`, `Billing/SubscriptionController`, `Admin/Billing/SubscriptionController`, `Snelstart/PassThroughController`, `Mollie/AbstractMolliePassThroughController` + 8 concrete Mollie-controllers (`Customers`, `Mandates`, `PaymentLinks`, `PaymentMethods`, `Payments`, `Refunds`, `Subscriptions`).
- Key files: `Mollie/AbstractMolliePassThroughController.php` is de gedeelde pipeline-base voor alle Mollie write-paths.

**`app/Http/Controllers/Webhooks/`:**

- Purpose: Webhook-ingress controllers.
- Contains: `MollieWebhookController` (enige eigen webhook-controller; Cashier-webhooks gebruiken vendor-controllers).

**`app/Http/Middleware/`:**

- Purpose: Cross-cutting request-preprocessing.
- Contains: `Resolve{Mollie,Snelstart}Account` (tenant-resolution), `EnsureEmeqAdminToken` (admin-allowlist), `RequireCashierWebhookSecret` (Cashier secret-guard), `SetNoIndexHeaders` (app-wide).

**`app/OAuth/`:**

- Purpose: Provider-agnostisch OAuth2-broker-laag.
- Contains: `Contracts/OAuthFlow` interface, `OAuthFlowRegistry` (provider → class map), `Mollie/MollieConnectOAuthFlow` (live), `Testing/FakeOAuthFlow` (test-fixture).
- Adding a new provider: implementeer `OAuthFlow`, registreer in `AppServiceProvider::register()` via `$registry->register('exact', ExactOnlineOAuthFlow::class)`.

**`app/Mollie/`, `app/Services/Snelstart/`:**

- Purpose: Per-SDK credential-resolver + context-state.
- Note: Beide implementeren SDK-contracten uit `packages/<sdk>/src/Contracts/`.

**`app/Billing/`:**

- Purpose: Cashier-Mollie domein-helpers (use-case A = Phase 6).
- Contains: `PlanResolver` (config-driven), `Exceptions\UnknownPlanException`.
- Memory: Account-level subscriptions (use-case B = Phase 7) krijgen aparte `app/Billing/Account/` subnamespace met `SubscriptionStatus`-enum + `StateTransitions` + `AccountSubscriptionManager` (zie Phase 7 CONTEXT).

**`app/Support/`:**

- Purpose: Stateless helpers per partner (geen DI, statische methodes).
- Contains: `Snelstart/{HeaderForwarder,UpstreamErrorMapper}`, `Mollie/{MollieHeaderForwarder,MollieUpstreamErrorMapper,ConsumerIdempotencyKeyGenerator}`.

**`app/Sanctum/`:**

- Purpose: Sanctum-specific constants/helpers buiten de Laravel-default-layout.
- Contains: `TokenAbilities` (`final class` met `public const SNELSTART_READ = 'snelstart:read'`, etc.).

**`app/Jobs/`:**

- Purpose: Queueable jobs (`ShouldQueue`).
- Contains: `ForwardMollieWebhookToConsumer` (Spatie-webhook-server-trigger).

**`app/Console/Commands/`:**

- Purpose: Artisan-commands.
- Contains: `HubConsumerCreate` (provisioning), `PruneOAuthPendingConnections` (cleanup expired OAuth-states).

**`database/migrations/`:**

- Purpose: Schema-evolution. Forward-only in production.
- Naming: `YYYY_MM_DD_HHMMSS_<verb>_<noun>_table.php` (Laravel default).
- Key migrations: `2026_05_14_000001_create_consumers_table.php`, `…_000002_create_accounts_table.php`, `…_000003_create_connections_table.php`, `2026_05_15_000001_create_pass_through_calls_table.php`, plus 9 Cashier-Mollie-published migraties met timestamp `2026_05_15_074719_*`.

**`database/factories/`:**

- Purpose: Eloquent test-factories per model.
- Convention: één factory per model; provider-specifieke states via `forSnelstart()` / `forMollie()` / `pending()` / `active()` / `expired()` op `ConnectionFactory`.

**`packages/`:**

- Purpose: SDK read-clones voor referentie/grep. **Gitignored**, niet onderdeel van Composer-path-repo (zie `.ai/packages.md`).
- Workflow: Edit in eigen SDK-repo → push → `composer update emeq/<sdk>` in Hub → commit `composer.lock`.

**`tests/`:**

- Purpose: PHPUnit-tests (geen Pest in de Hub; Pest leeft in `packages/<sdk>/tests/`).
- Sub-suites: `Unit/`, `Feature/`, `Integration/`. Integration heeft eigen `phpunit.integration.xml` en wordt los gerund met `composer test:integration` (excluded via group `integration`).
- Test-Concerns: `tests/Concerns/{BindsMollieConnectionContext,PrimesSnelstartTokenCache,StubsMollieClient}.php` zijn herbruikbare traits.

**`routes/`:**

- Purpose: HTTP-route-declaraties.
- Files: `web.php` (smoke `/`, `/up`), `api.php` (`/v1/*`), `webhooks.php` (`/webhooks/*` + `/cashier/webhook*`), `console.php` (artisan).

**`config/`:**

- Purpose: Laravel-config files.
- Key files: `services.php` (Mollie Connect + Snelstart + Cashier secrets), `mollie.php` (Mollie-SDK config), `billing.php` + `billing-plans.php` (Cashier plans), `cashier.php` + `cashier_plans.php` + `cashier_coupons.php` (Cashier-Mollie published), `horizon.php`, `webhook-server.php`, `webhook-client.php`, `scramble.php`, `sentry.php`.

**`.planning/`:**

- Purpose: GSD-workflow artefacten (gecommit).
- Layout: `ROADMAP.md` (active milestone), `STATE.md` (frontmatter + progress), `phases/<NN-slug>/` (CONTEXT → N-PLAN → N-SUMMARY → REVIEW → VERIFICATION), `quick/<datum-slug>/` voor ad-hoc, `milestones/v0.1-phases/` voor archief.

**`.docs/`:**

- Purpose: Werkdocumentatie (gitignored).
- Subfolders: zie root-layout. ADRs in `decisions/`, partner-research in `partners/<provider>/`.

**`.ai/rules/`:**

- Purpose: Auto-loaded Claude-rules.
- Files: `global.md` (taal/security/OAuth/multi-tenant), `engineering.md` (chirurgisch wijzigen, lezen vóór schrijven), `dev-setup.md`, `git-policy.md`, `packages.md`, `project.md`, `claude-mem-context.md`.

## Key File Locations

**Entry Points:**

- `public/index.php`: Laravel front-controller.
- `routes/api.php`: alle `/v1/*`-routes.
- `routes/webhooks.php`: publieke webhook-routes.
- `routes/web.php`: `/` smoke + `/up` health.
- `bootstrap/app.php`: `withRouting` + `withMiddleware` + `withExceptions` configuratie (apiPrefix=`v1`, middleware-aliases).
- `artisan`: CLI-entry.

**Configuration:**

- `composer.json`: dependencies + VCS-repositories voor `emeq/*`-SDKs + scripts (`composer test`, `composer test:integration`).
- `config/services.php`: partner-credentials (env-driven).
- `config/billing-plans.php`: Cashier-Mollie plans (Naschool-license, Planny-license).
- `config/billing.php`: `default_subscription_name`, `admin_allowlist`.
- `config/horizon.php`: queue supervisors.
- `phpunit.xml` / `phpunit.integration.xml`: twee suites; integration excluded via `<groups><exclude>integration</exclude></groups>`.
- `.env.example`: template met alle vereiste env-keys.

**Core Logic:**

- `app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php`: gedeelde Mollie write-pipeline.
- `app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php`: catch-all Snelstart pass-through.
- `app/Http/Controllers/Webhooks/MollieWebhookController.php`: webhook ingress + anti-spoofing.
- `app/OAuth/OAuthFlowRegistry.php`: provider-registry.
- `app/Mollie/HubMollieCredentialResolver.php`: lazy-refresh resolver.
- `app/Services/Snelstart/HubSnelstartCredentialResolver.php`: per-Connection Snelstart resolver.
- `app/Billing/PlanResolver.php`: Cashier-plan-lookup.
- `app/Providers/AppServiceProvider.php`: alle container-bindings (`scoped(MollieConnectionContext)`, `singleton(OAuthFlowRegistry)`, `bind(MollieCredentialResolver, HubMollieCredentialResolver)`, `Cashier::ignoreRoutes()`).

**Testing:**

- `tests/TestCase.php`: base Laravel-test (`RefreshDatabase` per testklasse).
- `tests/Integration/IntegrationTestCase.php`: live-Mollie integration-base (PHPUnit `@group integration`).
- `tests/Concerns/`: herbruikbare traits voor Mollie context-binding en Snelstart token-cache priming.
- `tests/Feature/Api/V1/`: HTTP-feature-tests per controller.
- `tests/Feature/Webhooks/`: webhook signature + fan-out + anti-spoofing tests.
- `tests/Feature/OAuth/`: contract + Mollie-flow tests.
- `tests/Feature/Mollie/`, `tests/Feature/Services/`: resolver-tests.
- `tests/Feature/Billing/`: Consumer-Billable + Cashier subscription integration.
- `tests/Feature/Console/`: artisan-command-tests.
- `tests/Feature/Documentation/ScrambleRouteDiscoveryTest.php`: borgt dat Scramble alle routes ziet.
- `tests/Unit/Support/`: stateless mapper/forwarder-tests.
- `tests/Unit/Billing/PlanResolverTest.php`: config-resolver unit-test.

## Naming Conventions

**Files:**

- Modellen: `PascalCase`-noun, enkelvoud (`Connection.php`, `PassThroughCall.php`).
- Controllers: `PascalCase` + `Controller`-suffix (`PaymentsController.php`); single-action invokables krijgen geen `Action`-suffix (`PingController` is `__invoke`).
- Middleware: `PascalCase`, verb-first (`ResolveMollieAccount`, `EnsureEmeqAdminToken`, `RequireCashierWebhookSecret`).
- Form-Requests: `PascalCase` + `Request`-suffix in `app/Http/Requests/<Group>/<Sub>/` (`StoreAccountRequest`, `CreatePaymentRequest`).
- Resources: `PascalCase` + `Resource`-suffix in `app/Http/Resources/Api/V1/` (`AccountResource`, `ConnectionResource`).
- Jobs: `PascalCase`-verb-noun (`ForwardMollieWebhookToConsumer`).
- Migrations: `YYYY_MM_DD_HHMMSS_<snake_verb>_<snake_noun>.php`.
- Tests: `<Subject>Test.php` (PHPUnit, niet Pest).

**Directories:**

- Per-provider sub-namespaces: `app/Http/Controllers/Api/V1/Mollie/`, `app/Http/Controllers/Api/V1/Snelstart/`, `app/OAuth/Mollie/`, `app/Support/Snelstart/`, `app/Support/Mollie/`, `app/Http/Requests/Api/V1/Mollie/`. Provider-naam = lowercase in routes (`/v1/mollie/*`), `PascalCase` in PHP-namespaces (`App\Http\Controllers\Api\V1\Mollie`).
- API-versioning: `Api/V1/` voor alle versioned controllers + requests + resources.
- Admin-scoped controllers: `Admin/Billing/` subfolder (eigen middleware `emeq.admin`).

**Partner-domeintermen:**

- Snelstart-vocabulair blijft Nederlands in code/identifiers waar de partner-API NL-velden gebruikt (`administratie_id`, `Relaties`, `Verkoopfacturen`). Mollie blijft Engels (`Payments`, `Customers`, `Mandates`, `Subscriptions`).
- Hub-eigen identifiers zijn Engels (`Connection`, `Account`, `Consumer`).
- Conformance per `.ai/rules/global.md`: "Domeintermen volgen de partner-API."

**Class types:**

- `final class` voor "shouldn't be extended" services en helpers (`OAuthFlowRegistry`, `TokenAbilities`, `PlanResolver`, `MollieConnectionContext`, alle mappers/forwarders).
- `readonly` class voor immutable DTO's en credential-holders (`HubSnelstartCredentialResolver`).
- `abstract class` voor controller-bases (`AbstractMolliePassThroughController`).
- Plain `class` voor Eloquent-models (Laravel-conventie: niet final i.v.m. mocking/extensibility).

## Where to Add New Code

**Nieuw `/v1/<resource>`-endpoint (consumer pass-through):**

- Route: `routes/api.php` binnen `Route::middleware('auth:sanctum')->group(...)`.
- Controller: `app/Http/Controllers/Api/V1/<Provider>/<Resource>Controller.php` — extend `AbstractMolliePassThroughController` voor Mollie of kopieer `Snelstart/PassThroughController`-pattern.
- Form-Request: `app/Http/Requests/Api/V1/<Provider>/<Verb><Resource>Request.php`.
- Resource (alleen voor Hub-eigen ressources, niet voor pass-through): `app/Http/Resources/Api/V1/<Resource>Resource.php`.
- Test: `tests/Feature/Api/V1/<Verb><Resource>Test.php` (PHPUnit, `RefreshDatabase`).

**Nieuwe OAuth-provider (Exact, Ibanity):**

- Flow-implementatie: `app/OAuth/<Provider>/<Provider>ConnectOAuthFlow.php` implements `App\OAuth\Contracts\OAuthFlow`.
- Registreer in `AppServiceProvider::register()` via `$registry->register('exact', ExactOAuthFlow::class)`.
- Credential-resolver: `app/<Provider>/Hub<Provider>CredentialResolver.php` implements het SDK-contract uit `packages/<provider>-api/src/Contracts/`.
- Middleware: `app/Http/Middleware/Resolve<Provider>Account.php` (kopieer `ResolveMollieAccount` of `ResolveSnelstartAccount`).
- Connection-shape: nieuwe nullable kolommen op `connections`-tabel via nieuwe migration (geen `down()`).

**Nieuwe Mollie-resource:**

- Controller: `app/Http/Controllers/Api/V1/Mollie/<Resource>Controller.php` extends `AbstractMolliePassThroughController`, public methods returnen `$this->handle($request, '/v2/<resource>', $callable)`.
- Form-Request: `app/Http/Requests/Api/V1/Mollie/Create<Resource>Request.php`.
- Route: `routes/api.php` binnen `Route::prefix('mollie')->middleware('resolve.mollie.account')->group(...)`.

**Nieuwe artisan-command:**

- Class: `app/Console/Commands/<VerbNoun>.php` met property-stijl `protected $signature` + `$description` (NIET `#[Signature]`/`#[Description]`-attributes — matched 03-05 deviation).
- Auto-discovery via Laravel kernel; geen registratie nodig.

**Nieuwe migration:**

- File: `database/migrations/YYYY_MM_DD_HHMMSS_<verb>_<noun>.php` (gegenereerd via `php artisan make:migration`).
- Forward-only: schrijf `up()` voor schema-change. `down()` mag minimaal blijven (vendor-default) maar wordt niet uitgevoerd in productie.

**Nieuwe webhook-handler:**

- Controller: `app/Http/Controllers/Webhooks/<Provider>WebhookController.php`.
- Job: `app/Jobs/Forward<Provider>WebhookToConsumer.php`.
- Route: `routes/webhooks.php` met signature-verify-middleware of inline-guard.
- Memory: routes erven `throttle:api` — check of partner-volume `withoutMiddleware(['throttle:api'])` vereist.

**Nieuwe Cashier-plan:**

- Config: `config/billing-plans.php` met shape `{amount: {value, currency}, interval, description}`.
- Resolver test: `tests/Unit/Billing/PlanResolverTest.php` voegt assert toe.

## Special Directories

**`packages/`:**

- Purpose: Lees-clones van SDK-repos voor grep/referentie.
- Generated: Nee — handmatig `git clone` per `.ai/packages.md`.
- Committed: Nee (in `.gitignore`).
- Live-edit: Nee — Composer require't via VCS-repository (zie `composer.json` § repositories). Workflow: edit in SDK-repo → push → `composer update emeq/<name>` in Hub.

**`vendor/`:**

- Purpose: Composer dependencies (Laravel, Cashier-Mollie, Sanctum, Saloon, Mollie-SDK, etc.).
- Generated: Ja (`composer install`).
- Committed: Nee.

**`.docs/`:**

- Purpose: Werkdocumentatie.
- Committed: Nee (gitignored). Bewust geen team-source-of-truth — gebruik `CLAUDE.md` / `README.md` / `.planning/codebase/` voor shared specs.

**`.planning/codebase/`:**

- Purpose: Output van `/gsd-map-codebase`. Bron voor `/gsd-plan-phase` en `/gsd-execute-phase` context-loading.
- Generated: Ja (door `/gsd-map-codebase` subagents).
- Committed: Ja.

**`bootstrap/cache/`:**

- Purpose: Compiled config/services cache.
- Generated: Ja (`php artisan config:cache`).
- Committed: Nee.

**`storage/`:**

- Purpose: Logs, sessions, framework-runtime.
- Committed: Nee (`storage/app/{public,private}/.gitignore` houdt user-content uit).

## Phase Status

**Shipped (per `.planning/ROADMAP.md` + `STATE.md`):**

- Phase 2 — `emeq/mollie-api` foundation (SDK).
- Phase 3 — Hub-skeleton (Consumer/Account/Connection + Sanctum).
- Phase 4 — Mollie Connect OAuth-broker.
- Phase 5a — Mollie SDK Resources + Webhooks + Pass-through API.
- Phase 5b — Snelstart-pass-through API.
- Phase 6 — Cashier-Mollie integratie (use-case A).

**In-flight / planned:**

- Phase 5c — Snelstart webhook-handler (blocked op partner-respons; 5 plans gedraft, niet uitgevoerd).
- Phase 7 — Account-level subscriptions / use-case B (CONTEXT gathered, 8 plans gedraft, niet uitgevoerd; introduces `app/Billing/Account/` namespace).
- Phase 8 — Naschool wiring (externe repo, niet in deze tree).
- Phase 9 — Filament admin-UI (CONTEXT gedraft, plans TBD; introduces `app/Filament/` of `app/Providers/Filament/`).

---

*Structure analysis: 2026-05-15. Reflects branch `feat/v02-account-subscriptions` op commit `1b97fa6`.*
