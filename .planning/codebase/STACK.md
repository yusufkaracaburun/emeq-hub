# Technology Stack

**Analysis Date:** 2026-05-15

## Languages

**Primary:**
- PHP 8.4 — Hub-applicatie (`app/`, `routes/`, `config/`, `database/`, `tests/`), pinned in `composer.json` (`"php": "^8.4"`) en `composer.lock` platform-section.

**Secondary:**
- JavaScript (ESM) — alleen voor build-tooling (Vite + Tailwind), géén consumer-facing frontend. Zie `vite.config.js`, `resources/`.

## Runtime

**Environment:**
- PHP 8.4 (host process via `php artisan serve --port=8001`)
- Lokale infra via Docker: Postgres 16-alpine, Redis 7-alpine, Caddy 2 (zie `docker-compose.yml`).
- Caddy reverse-proxy luistert op `${CADDY_PORT:-8090}` → host `php artisan serve` (zie `docker/Caddyfile`).

**Package Manager:**
- Composer 2.9.0 (plugin-api-version uit `composer.lock`)
- Lockfile: aanwezig (`composer.json` + `composer.lock`); `minimum-stability: dev`, `prefer-stable: true`.
- npm 10+ (private package, `package.json`), `.npmrc` aanwezig.

## Frameworks

**Core:**
- `laravel/framework` v13.9.0 — application framework (`bootstrap/app.php` gebruikt Laravel 11+-style fluent config).
- `laravel/sanctum` v4.3.2 — Personal Access Token auth voor consumer-API.
- `laravel/horizon` v5.46.0 — queue dashboard + supervisor over Redis-queue (config `config/horizon.php`).
- `laravel/tinker` v3.0.2 — REPL.
- `laravel/nightwatch` v1.26.1 — APM (primary observability, sample-rate 0.1 via `NIGHTWATCH_REQUEST_SAMPLE_RATE`).
- `laravel/prompts` v0.3.17 — CLI prompts (transitive).

**Partner-SDK laag:**
- `emeq/snelstart-api` dev-master — eigen SDK (VCS, `https://github.com/yusufkaracaburun/emeq-snelstart-api.git`, ref `caa15d4`). Wrappt Snelstart OData via Saloon v4.
- `emeq/mollie-api` v0.1.0-alpha.1 — eigen SDK (VCS, `git@github.com:yusufkaracaburun/emeq-mollie-api.git`). Wrappt `mollie/mollie-api-php` rechtstreeks (géén Saloon-laag).
- `saloonphp/saloon` v4.0.0 — getrokken door `emeq/snelstart-api`, niet rechtstreeks door de Hub.
- `spatie/laravel-data` v4.23.0 — DTOs in beide SDK's.

**Testing:**
- `phpunit/phpunit` ^12.5.12 — Hub-tests (`phpunit.xml`, `phpunit.integration.xml`).
- `mockery/mockery` ^1.6 — test doubles.
- `fakerphp/faker` ^1.23 — factory data.
- `nunomaduro/collision` ^8.6 — CLI error rendering.
- Pest ^3/^4 — alleen in SDK-packages (`packages/snelstart-api/`, `packages/mollie-api/`), niet in Hub.

**Build/Dev:**
- `vite` ^8.0.0 + `laravel-vite-plugin` ^3.1 (asset-build).
- `@tailwindcss/vite` ^4.0.0 + `tailwindcss` ^4.0.0 (alleen `resources/css`).
- `concurrently` ^9.0.1 — `composer run dev` start server+queue+pail+vite parallel.
- `laravel/pint` ^1.27 — code formatter (`vendor/bin/pint --dirty --format agent`).
- `laravel/pail` ^1.2.5 — log tailing.
- `laravel/boost` ^2.4 — MCP server voor Claude.
- `laravel/pao` ^1.0.6 — Laravel scaffolding helper (dev).

## Key Dependencies

**Critical (partner-domein):**
- `mollie/mollie-api-php` v3.11.0 — officiële Mollie PHP SDK, wrappt onder `emeq/mollie-api`.
- `mollie/laravel-cashier-mollie` v2.20.1 — Subscriptions/Cashier-laag voor use-case A (Emeq → Consumers). Default-routes uitgezet via `Cashier::ignoreRoutes()` in `app/Providers/AppServiceProvider.php:40`.
- `mollie/laravel-mollie` v4.1.0 — pulled door Cashier-Mollie; gebruikt `MOLLIE_KEY` env-var. NIET de primaire Mollie-client voor pass-through (dat is `emeq/mollie-api`).
- `dedoc/scramble` v0.13.22 — auto-OpenAPI generator op `/docs/api`, geconfigureerd in `config/scramble.php` + `app/Providers/AppServiceProvider.php:61`.

**Webhook infra:**
- `spatie/laravel-webhook-server` v3.10.0 — outbound fan-out (Hub → Consumer-callback). Config `config/webhook-server.php`: 3s timeout, 3 tries, exponential backoff, SSL-verify aan.
- `spatie/laravel-webhook-client` v3.6.2 — inbound persistence (`webhook_calls` tabel). Config `config/webhook-client.php`: 30-day retention, default config-bucket.

**Infrastructure:**
- `predis/predis` v3.4.2 — Redis-client (`REDIS_CLIENT=predis` in `.env.example:39`).
- `nesbot/carbon` 3.11.4 — date/time (transitive via Laravel).
- `guzzlehttp/guzzle` 7.10.0 — HTTP client (transitive, gebruikt door Cashier/Mollie SDK en Laravel HTTP-facade).
- `sentry/sentry-laravel` v4.25.1 + `sentry/sentry` v4.27.0 — exception-capture only (`SENTRY_TRACES_SAMPLE_RATE=0.0`, Nightwatch doet APM). Wired in `bootstrap/app.php:41` via `Integration::handles($exceptions)`.

**Auth-related:**
- `laravel/serializable-closure` v2.0.13 (Cashier).
- `laravel/sentinel` v1.1.0 (transitive via Boost).

## Configuration

**Environment:**
- `.env.example` — committed template (zie `.env.example`).
- `.env` — local-only, gitignored (bestaat lokaal, niet committed).
- `APP_LOCALE=nl`, `APP_FAKER_LOCALE=nl_NL` — NL-default voor seed-data en validatie-messages.
- Trust-list aanwezig in `config/services.php` (Mollie Connect scopes hardcoded).

**Key env vars (non-secret structure):**
- `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=5433`, `DB_DATABASE=emeq_hub`.
- `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `SESSION_DRIVER=redis`.
- `REDIS_CLIENT=predis`, `REDIS_PORT=6380`.
- `SANCTUM_STATEFUL_DOMAINS=hub.emeq.test`.
- `HORIZON_PREFIX=emeq-hub`.
- `API_VERSION=0.2.0-dev` (gepubliceerd in OpenAPI via Scramble).
- Snelstart: `SNELSTART_PARTNER_APP_NAME`, `SNELSTART_PRIMARY_KEY`, `SNELSTART_SECONDARY_KEY` (subscription-keys).
- Mollie Connect (broker): `MOLLIE_CONNECT_CLIENT_ID`, `MOLLIE_CONNECT_CLIENT_SECRET`, `MOLLIE_CONNECT_REDIRECT_URI`, `MOLLIE_WEBHOOK_SECRET`.
- Mollie partner (legacy): `MOLLIE_PARTNER_CLIENT_ID`, `MOLLIE_PARTNER_CLIENT_SECRET`.
- Cashier-Mollie (use-case A, Emeq's eigen Mollie-account): `CASHIER_MOLLIE_KEY`, `MOLLIE_KEY`, `CASHIER_WEBHOOK_SECRET`.
- Billing admin-gate: `BILLING_ADMIN_CONSUMER_IDS`, `BILLING_DEFAULT_SUBSCRIPTION_NAME=main`.
- Sentry: `SENTRY_LARAVEL_DSN`, `SENTRY_TRACES_SAMPLE_RATE=0.0`.
- Nightwatch: `NIGHTWATCH_TOKEN`, `NIGHTWATCH_REQUEST_SAMPLE_RATE=0.1`.
- Moneybird (gepland, niet gewired): `MONEYBIRD_PARTNER_CLIENT_ID`, `MONEYBIRD_PARTNER_CLIENT_SECRET`.

**Build:**
- `vite.config.js` — Vite + Tailwind + Laravel-plugin.
- `tsconfig.json` — niet aanwezig (TS niet in gebruik).
- `phpunit.xml` — default suite, excludes `integration` group, gebruikt sqlite `:memory:`.
- `phpunit.integration.xml` — separate suite voor Cashier-Mollie integration-tests, runt via `composer test:integration`.
- `boost.json` — Laravel Boost config.

## Platform Requirements

**Development:**
- macOS / Linux met PHP 8.4, Composer, Docker (db+redis+caddy), npm.
- `/etc/hosts`-entry `127.0.0.1 hub.emeq.test` voor Sanctum stateful-domain + Caddy.
- `php artisan serve --port=8001` op host (Caddy proxied → 8090).
- `php artisan horizon` in tweede terminal voor queue-processing.

**Production:**
- Laravel Cloud (per `.docs/decisions/hosting-platform.md`). Note: `packages/` is gitignored — SDK's worden via Composer-VCS gepulld, niet path-symlinked.
- Migrations forward-only in prod (CLAUDE.md invariant).

## Test Setup Split

**Hub (`tests/`):**
- PHPUnit 12 (`phpunit.xml`, `phpunit.integration.xml`).
- Default-suite: SQLite `:memory:`, `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`, integration-group excluded.
- Integration-suite: skipt automatisch als `CASHIER_MOLLIE_KEY` ontbreekt of geen `test_*`-prefix heeft (`tests/Integration/IntegrationTestCase.php`).
- Run: `php artisan test --compact`, `composer test:integration`.

**SDK-packages (`packages/snelstart-api/`, `packages/mollie-api/`):**
- Pest ^3/^4 met `pestphp/pest-plugin-arch` + `pestphp/pest-plugin-laravel`.
- `orchestra/testbench` ^9/10/11 voor Laravel-bootstrapping in package-tests.
- `larastan/larastan` ^3.0 + `phpstan/phpstan-{deprecation-rules,phpunit}` ^2.0.
- Run: `cd packages/<name> && ./vendor/bin/pest`.
- Conventie uit `.ai/packages.md`: SDK-changes worden in de SDK-repo gecommit + gepusht, dan `composer update emeq/<name>` in de Hub.

## Composer Repositories

Twee VCS-repositories voor SDK-distribution (`composer.json:108-118`):

```text
git@github.com:yusufkaracaburun/emeq-mollie-api.git    (SSH, name: emeq-mollie-api)
https://github.com/yusufkaracaburun/emeq-snelstart-api.git  (HTTPS)
```

SDK's installeren als reguliere composer-dependencies via dist-zips uit de GitHub-archive — `packages/` op de host is een **lees-clone** voor referentie/grep, geen path-link.

## Audit & Stability

- `composer.json` audit: alleen `"abandoned": "report"` (drie Saloon v3-advisories opgelost in Phase 11 — Saloon `^4.0` in SDK, `composer audit` exit 0 zonder ignores).
- `minimum-stability: dev` + `prefer-stable: true` om `emeq/mollie-api: ^0.1.0-alpha.1` (en vergelijkbare alpha/dev-deps) toe te staan zonder volledige dev-resolutie. `emeq/snelstart-api` staat sinds Phase 11 op `^0.2.0` (stable tag).

---

*Stack analysis: 2026-05-15*
