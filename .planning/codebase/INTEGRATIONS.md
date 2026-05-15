# External Integrations

**Analysis Date:** 2026-05-15

## APIs & External Services

**Boekhoud-providers (NL):**

- **Snelstart B2B-API v2** — Live wired (Phase 5b done).
  - Base URL: `https://b2bapi.snelstart.nl/v2` (auth-server `https://auth.snelstart.nl/b2b/token`).
  - SDK: `emeq/snelstart-api` (dev-master, VCS, ref `caa15d4`), wrappt Snelstart OData via Saloon v4.
  - Auth-shape: dual-credential — `clientkey` (per administratie/tenant, OAuth2 `grant_type=clientkey`) + `Ocp-Apim-Subscription-Key` header (partner-niveau, één per Emeq).
  - Hub-resolver: `app/Services/Snelstart/HubSnelstartCredentialResolver.php` — bouwt `SnelstartCredentials` DTO uit decrypted Eloquent-casts op `App\Models\Connection`.
  - Per-request binding in `app/Http/Middleware/ResolveSnelstartAccount.php:66-74` (rebind + `app()->forgetInstance(Snelstart::class)` om singleton-staleness te vermijden).
  - Pass-through: `routes/api.php:41` → `App\Http\Controllers\Api\V1\Snelstart\PassThroughController`. Forwards `/v1/snelstart/{path*}` met `X-Account-Id`-header naar Snelstart-OData.
  - Partner-research: `.docs/partners/snelstart/` (24 files, OpenAPI `api-definition.yaml`).

- **Moneybird** — Gepland, niet gewired.
  - Env-keys aanwezig in `.env.example:108-109` (`MONEYBIRD_PARTNER_CLIENT_ID`, `MONEYBIRD_PARTNER_CLIENT_SECRET`).
  - Geen SDK in `composer.json`, geen `OAuthFlow`-registratie, geen migration, geen routes.

- **Exact Online** — Gepland, niet gewired.
  - Geen env-keys, geen SDK, geen code-spoor.

- **Ibanity (PSD2/banking)** — Gepland, niet gewired.
  - Geen env-keys, geen SDK, geen code-spoor.

**Betaal-providers:**

- **Mollie API v2 (pass-through, multi-tenant)** — Live wired (Phase 5a done).
  - Base URL: `https://api.mollie.com/v2`.
  - SDK: `emeq/mollie-api` v0.1.0-alpha.1 (VCS), wrappt `mollie/mollie-api-php` v3.11.0.
  - Auth: OAuth2 Bearer (via Mollie Connect, niet API-key). Resolver `app/Mollie/HubMollieCredentialResolver.php` bouwt `MollieOAuthCredentials` DTO; refresht via `OAuthFlowRegistry::for('mollie')` als token <5 min from expiry.
  - Per-request binding via `MollieConnectionContext` (scoped singleton in `app/Providers/AppServiceProvider.php:26`).
  - Idempotency: `Emeq\MollieApi\Idempotency\UuidV7IdempotencyKeyGenerator` (zie `config/mollie.php:6`), met consumer-override via `Idempotency-Key`-header → `app/Support/Mollie/ConsumerIdempotencyKeyGenerator.php`.
  - Facade-alias `EmeqMollie` (collision-vrij naast Cashier's `Mollie`-facade), zie `config/mollie.php:14`.
  - Pass-through routes: `routes/api.php:60-87` — payments, customers, payment-methods, refunds, mandates, subscriptions, payment-links.

- **Mollie Connect OAuth-broker** — Live wired (Phase 4 done).
  - Authorize URL: `https://my.mollie.com/oauth2/authorize` (`app/OAuth/Mollie/MollieConnectOAuthFlow.php:21`).
  - Token URL: `https://api.mollie.com/oauth2/tokens` (`MollieConnectOAuthFlow.php:33,63,85`).
  - Init: `POST /v1/oauth/mollie/init` (Sanctum-PAT met ability `mollie:write`).
  - Callback: `GET /v1/oauth/mollie/callback` (publiek, state-param is de auth — zie `routes/api.php:91`).
  - Scopes (`config/services.php:43-53`): `payments.{read,write}`, `customers.{read,write}`, `subscriptions.{read,write}`, `mandates.read`, `organizations.read`, `onboarding.read`.
  - Refresh-token-strategy: 30s cache-lock (`Cache::lock("oauth:refresh:{$connection->id}")`), met double-check pattern om dubbel-refresh te voorkomen (`MollieConnectOAuthFlow.php:56-78`).

- **Cashier-Mollie subscriptions (use-case A)** — Wired (Phase 6 done).
  - Versie: `mollie/laravel-cashier-mollie` v2.20.1 op Emeq's eigen Mollie test-account (NIET Connect — dit is intra-tenant billing van Emeq → Consumers).
  - Billable model: `App\Models\Consumer` (`config/cashier.php:user_model`).
  - Plans: `config/cashier_plans.php` (Cashier-Mollie's plan-shape) + `config/billing-plans.php` (eigen plan-resolver-source; `naschool-license`, `planny-license`, beide placeholder `0.00` om safety-net-fail bij Mollie te triggeren).
  - Coupons: `config/cashier_coupons.php`.
  - Default-routes uitgezet via `Cashier::ignoreRoutes()` in `app/Providers/AppServiceProvider.php:40`.
  - Plan-resolver: `app/Billing/PlanResolver.php`.
  - Admin-API: `POST /v1/admin/billing/subscriptions`, `DELETE /v1/admin/billing/subscriptions/{id}` — gated via `EnsureEmeqAdminToken`-middleware + `BILLING_ADMIN_CONSUMER_IDS`-allowlist (`config/billing.php`).

## Data Storage

**Databases:**
- **PostgreSQL 16-alpine** — primary store.
  - Lokaal: `127.0.0.1:5433` (docker-compose), credentials via env.
  - Connection: `DB_CONNECTION=pgsql` (zie `.env.example:20-25`).
  - Migrations: `database/migrations/` — Hub-tabellen (`consumers`, `accounts`, `connections`, `pass_through_calls`, `personal_access_tokens`, `webhook_calls`, `attachments`) + Cashier-Mollie suite (`subscriptions`, `orders`, `order_items`, `payments`, `refunds`, `refund_items`, `credits`, `applied_coupons`, `redeemed_coupons`).
  - Eloquent-encryption op `Connection.access_token`, `refresh_token`, `client_key`, `subscription_key` (`app/Models/Connection.php:54-58`) en `Consumer.webhook_callback_secret` (`app/Models/Consumer.php:25`).

**File Storage:**
- `FILESYSTEM_DISK=local` (`.env.example:34`). Geen cloud-storage gewired.

**Caching:**
- Redis 7-alpine — cache + queue + session.
- Cache-driver: `CACHE_STORE=redis` (`.env.example:37`).
- Queue: `QUEUE_CONNECTION=redis` (`.env.example:35`), Horizon-managed.
- Session: `SESSION_DRIVER=redis` (`.env.example:27`).
- Lokale port: 6380 (host) → 6379 (container).

## Authentication & Identity

**Consumer-auth:**
- Laravel Sanctum v4.3.2 — Personal Access Tokens, Bearer-auth.
- Billable + tokens op `App\Models\Consumer` (`Billable, HasApiTokens, HasFactory` traits).
- Token-abilities (`app/Sanctum/TokenAbilities.php`):
  - `snelstart:read`, `snelstart:write`
  - `mollie:read`, `mollie:write`
  - `consumer:manage-accounts`
  - `billing:read`, `billing:write`
  - `*` (admin)
- ADR: `.docs/decisions/sanctum-token-abilities.md`.
- Stateful-domain: `hub.emeq.test` (`.env.example:48`).

**Partner-auth:**
- Snelstart: clientkey + subscription-key (per-Connection, encrypted at rest).
- Mollie: OAuth2 access/refresh tokens (per-Connection, encrypted at rest).

**Admin-gate (intermediate, tot Filament-panel landt — Phase 9):**
- `app/Http/Middleware/EnsureEmeqAdminToken.php` checked Consumer-ID tegen `BILLING_ADMIN_CONSUMER_IDS`-allowlist.
- `viewApiDocs` Gate: `app/Providers/AppServiceProvider.php:45-53` — `SCRAMBLE_ACCESS_TOKEN`-querystring-match, default-deny op non-local.

## Monitoring & Observability

**Error Tracking:**
- Sentry (exception capture only).
  - Package: `sentry/sentry-laravel` v4.25.1.
  - Wired in `bootstrap/app.php:14,41` (`Sentry\Laravel\Integration::handles($exceptions)`).
  - Config: `config/sentry.php`.
  - Traces sample-rate = 0 (`SENTRY_TRACES_SAMPLE_RATE=0.0`) — Nightwatch doet APM.
  - Ignored transactions: `/up` health-check.

**APM:**
- Laravel Nightwatch v1.26.1 (`laravel/nightwatch`).
  - Token: `NIGHTWATCH_TOKEN`.
  - Sample-rate: `NIGHTWATCH_REQUEST_SAMPLE_RATE=0.1` (10%).
  - Logging-channel: `LOG_STACK=single,laravel-cloud-socket,nightwatch` (`.env.example:16`).

**Logs:**
- `LOG_CHANNEL=stack`, level `debug` lokaal.
- Pail (`laravel/pail`) voor tail-during-dev (via `composer run dev`).

## CI/CD & Deployment

**Hosting:**
- Laravel Cloud (zie `.docs/decisions/hosting-platform.md`).
- Note: `packages/` is gitignored → SDK's worden bij deploy via Composer-VCS uit GitHub gepulld (zie `.ai/packages.md`).

**CI Pipeline:**
- Niet gewired in repo (geen `.github/workflows/`, geen `.gitlab-ci.yml` aanwezig in scan).
- Integration-tests (Cashier-Mollie tegen live Mollie test-API) bedoeld voor dedicated CI-step met key uit secrets (zie `.env.example:98-105`).

## Webhook Configuration

**Inbound — Mollie Connect:**
- Route: `POST /webhooks/mollie/{connection_id}` (`routes/webhooks.php:17-19`).
- Controller: `app/Http/Controllers/Webhooks/MollieWebhookController.php`.
- Signature-verify: HMAC-SHA256 op `X-Mollie-Signature` met `MOLLIE_WEBHOOK_SECRET` (platform-secret), via `Emeq\MollieApi\Webhooks\MollieWebhookSignature::verify`.
- 7-stappen-flow:
  1. Hard-fail guard: lege secret → 500 + audit-row (D-08 stap 1, anti-open-ingress).
  2. Signature-verify.
  3. Connection-lookup (`provider=mollie`, niet revoked).
  4. Payload-id-check.
  5. Anti-spoofing: fetch resource via deze Connection's access_token (zie `MollieWebhookController.php:84-91`).
  6. Inbound audit → `Spatie\WebhookClient\Models\WebhookCall`.
  7. Fan-out via `App\Jobs\ForwardMollieWebhookToConsumer` → 202 Accepted.
- Audit-table: `webhook_calls` (Spatie), 30-day retention.

**Inbound — Cashier-Mollie (Emeq's eigen Mollie-account):**
- Routes (`routes/webhooks.php:28-37`), allemaal achter `RequireCashierWebhookSecret`-middleware:
  - `POST /cashier/webhook` → `Laravel\Cashier\Http\Controllers\WebhookController` (default — payment/refund).
  - `POST /cashier/webhook/first-payment` → `FirstPaymentWebhookController` (first-mandate).
  - `POST /cashier/webhook/aftercare` → `AftercareWebhookController` (refund/chargeback).
- Hard-fail bij lege `CASHIER_WEBHOOK_SECRET` (D-10/D-11, `.env.example:85-89`).
- Cashier's eigen default-routes (`webhooks/mollie*`) zijn uitgezet via `Cashier::ignoreRoutes()` in `AppServiceProvider::register()` om collision met `/webhooks/mollie/{connection_id}` te voorkomen.

**Outbound — Consumer-callbacks:**
- Job: `app/Jobs/ForwardMollieWebhookToConsumer.php`.
- Uses `Spatie\WebhookServer\WebhookCall`.
- Per-Consumer config: `consumers.webhook_callback_url`, `consumers.webhook_callback_secret` (encrypted).
- Silent skip als URL ontbreekt; geen retry zonder URL.
- Spatie defaults: 3s timeout, 3 retries, exponential backoff, SSL-verify (`config/webhook-server.php`).

**Webhook-routing inheritance:**
- `routes/webhooks.php` zit in `Route::middleware('api')->group(...)` (`bootstrap/app.php:24-26`).
- Throttle-aware: `throttle:api` (60/min per consumer-of-IP) is op alle webhook-routes actief — voor bursting partners moet expliciet `->withoutMiddleware(['throttle:api'])` worden gezet (memory: `webhook-routes-inherit-throttle-api`).

## Environment Configuration

**Required env-vars (productie/staging):**
- DB: `DB_*` (Postgres connection).
- Redis: `REDIS_HOST`, `REDIS_PORT`, `REDIS_CLIENT=predis`.
- App: `APP_KEY`, `APP_URL`.
- Sanctum: `SANCTUM_STATEFUL_DOMAINS`.
- Snelstart partner-account: `SNELSTART_PARTNER_APP_NAME`, `SNELSTART_PRIMARY_KEY`, `SNELSTART_SECONDARY_KEY`.
- Mollie Connect: `MOLLIE_CONNECT_CLIENT_ID`, `MOLLIE_CONNECT_CLIENT_SECRET`, `MOLLIE_CONNECT_REDIRECT_URI`, `MOLLIE_WEBHOOK_SECRET`.
- Cashier-Mollie (use-case A): `CASHIER_MOLLIE_KEY`, `MOLLIE_KEY` (moeten identiek zijn), `CASHIER_WEBHOOK_SECRET`.
- Billing admin: `BILLING_ADMIN_CONSUMER_IDS`, `BILLING_DEFAULT_SUBSCRIPTION_NAME`.
- Observability: `NIGHTWATCH_TOKEN`, `SENTRY_LARAVEL_DSN`.
- Scramble: `SCRAMBLE_ACCESS_TOKEN` (productie default-deny zonder token).

**Secrets storage:**
- Lokaal: `.env` (gitignored, niet gelezen door deze analyse).
- Productie: Laravel Cloud env-config.
- Encrypted at rest in DB voor tokens: zie `Connection`/`Consumer` casts.

## Generated artifacts

- `api.json` — Scramble-exported OpenAPI 3.0 spec (committed, 118KB).
- `api/_index.md`, `api-definition.yaml` etc. — partner-research onder `.docs/partners/snelstart/`, `.docs/partners/mollie/`.

## OAuth Flow Registry

Centrale provider-aware OAuth-resolver (`app/OAuth/OAuthFlowRegistry.php`):
- Geregistreerd in `AppServiceProvider::register()` als singleton.
- Huidige providers: `'mollie' => MollieConnectOAuthFlow::class` (`AppServiceProvider.php:30`).
- Snelstart heeft géén OAuth-flow-registratie omdat clientkey-flow per-request via resolver loopt, niet via een autorisatie-callback.
- Contract: `App\OAuth\Contracts\OAuthFlow` — methods `getAuthorizationUrl`, `exchangeCode`, `refreshToken`, `revoke`.
- ADR: `.docs/decisions/oauth-flow-registry.md`.

## Audit / Pass-through Calls

- Tabel: `pass_through_calls` (`database/migrations/2026_05_15_000001_create_pass_through_calls_table.php`).
- Model: `app/Models/PassThroughCall.php` — immutable (`public $timestamps = false`, alleen `created_at`).
- Fields: `consumer_id`, `account_id`, `connection_id`, `provider`, `method`, `path`, `query_keys` (JSON), `status`, `duration_ms`, `request_fingerprint`, `response_size_bytes`, `upstream_error`.
- ADR: `.docs/decisions/pass-through-calls-table.md`.

## Wired vs. Stubbed Summary

| Integration | Status | Files |
|---|---|---|
| Snelstart B2B-API pass-through | Live | `routes/api.php:41`, `app/Http/Middleware/ResolveSnelstartAccount.php`, `app/Services/Snelstart/` |
| Mollie API pass-through | Live | `routes/api.php:60-87`, `app/Http/Controllers/Api/V1/Mollie/`, `app/Mollie/` |
| Mollie Connect OAuth-broker | Live | `app/OAuth/Mollie/MollieConnectOAuthFlow.php`, `app/Http/Controllers/Api/V1/OAuth/` |
| Mollie Connect webhooks (inbound) | Live | `app/Http/Controllers/Webhooks/MollieWebhookController.php`, `routes/webhooks.php:17-19` |
| Consumer webhook fan-out (outbound) | Live | `app/Jobs/ForwardMollieWebhookToConsumer.php` |
| Cashier-Mollie subscriptions | Live | `config/cashier.php`, `config/cashier_plans.php`, `routes/api.php:46-58`, `app/Billing/PlanResolver.php` |
| Cashier-Mollie webhooks | Live | `routes/webhooks.php:28-37`, `app/Http/Middleware/RequireCashierWebhookSecret.php` |
| Sentry exception capture | Live | `bootstrap/app.php:14,41`, `config/sentry.php` |
| Nightwatch APM | Live | `config/logging.php` stack, env-driven sample-rate |
| Sanctum PAT auth | Live | `app/Sanctum/TokenAbilities.php`, `routes/api.php:27` |
| Scramble OpenAPI | Live | `config/scramble.php`, `api.json`, `AppServiceProvider::boot()` |
| Moneybird OAuth | Stub | Alleen `.env.example:108-109` keys, geen SDK/flow/routes |
| Exact Online | Not started | Geen artefacten |
| Ibanity / PSD2 | Not started | Geen artefacten |
| Snelstart webhooks (inbound) | Planning | HUB-06 plans onder `.planning/phases/05c-*` |
| Account-level subscriptions (use-case B) | Planning | SUB-02 plans onder `.planning/phases/07-*` |

---

*Integration audit: 2026-05-15*
