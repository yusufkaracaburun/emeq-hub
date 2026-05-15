<!-- refreshed: 2026-05-15 -->
# Architecture

**Analysis Date:** 2026-05-15

## System Overview

```text
┌─────────────────────────────────────────────────────────────────────────────┐
│                       Consumer (SaaS-app van Emeq of derde)                  │
│  Bearer-PAT (Sanctum, abilities: snelstart:*/mollie:*/billing:*/admin/*)     │
└──────────────┬────────────────────────────────────────────────┬─────────────┘
               │ HTTP/REST + X-Account-Id-header                │ outbound
               │                                                │ webhook
               ▼                                                ▲ fan-out
┌──────────────────────────────────────────────────────────────────────────────┐
│                              emeq-hub (Laravel 13.9)                          │
│  ┌────────────────────────────────────────────────────────────────────────┐  │
│  │  HTTP Layer (`routes/api.php` /v1/*, `routes/webhooks.php` /webhooks/*)│  │
│  │  - Sanctum `auth:sanctum` + `throttle:api` (api group)                 │  │
│  │  - Webhook routes signature-verified, geen Sanctum                     │  │
│  └─────────────────┬────────────────────────────┬─────────────────────────┘  │
│                    │                            │                            │
│  ┌─────────────────▼──────────┐  ┌──────────────▼────────────────────────┐  │
│  │ Middleware                  │  │ Controllers (`app/Http/Controllers/`) │  │
│  │ - `resolve.snelstart.account│  │ - `Api\V1\AccountController`          │  │
│  │ - `resolve.mollie.account`  │  │ - `Api\V1\ConnectionController`       │  │
│  │ - `emeq.admin`              │  │ - `Api\V1\OAuth\Init/CallbackCtrl`    │  │
│  │ - `cashier.webhook.secret`  │  │ - `Api\V1\Snelstart\PassThroughCtrl`  │  │
│  │ - `ability:…`               │  │ - `Api\V1\Mollie\*Controller` (8)     │  │
│  └─────────────┬───────────────┘  │ - `Api\V1\Billing\SubscriptionCtrl`   │  │
│                │                  │ - `Api\V1\Admin\Billing\SubsCtrl`     │  │
│                │                  │ - `Webhooks\MollieWebhookCtrl`        │  │
│                │                  └────────────┬──────────────────────────┘  │
│                │                               │                              │
│  ┌─────────────▼───────────────────────────────▼──────────────────────────┐  │
│  │ Domain / Service Layer                                                  │  │
│  │ - `app/Models/{Consumer,Account,Connection,PassThroughCall,User}`       │  │
│  │ - `app/OAuth/` (Contracts\OAuthFlow, Mollie\MollieConnectOAuthFlow,     │  │
│  │   OAuthFlowRegistry, Testing\FakeOAuthFlow)                             │  │
│  │ - `app/Mollie/` (MollieConnectionContext scoped, HubMollieCredential…)  │  │
│  │ - `app/Services/Snelstart/HubSnelstartCredentialResolver`               │  │
│  │ - `app/Billing/{PlanResolver,Exceptions\UnknownPlanException}`          │  │
│  │ - `app/Support/{Snelstart,Mollie}/{HeaderForwarder,UpstreamErrorMapper}`│  │
│  │ - `app/Jobs/ForwardMollieWebhookToConsumer` (Spatie webhook-server)     │  │
│  └─────────┬───────────────────────────────┬───────────────────────────────┘  │
│            │                               │                                  │
│            │ Saloon v4 connector           │ MollieApiClient runtime swap     │
│            ▼                               ▼                                  │
└────────────┬───────────────────────────────┬──────────────────────────────────┘
             │                               │
             ▼                               ▼
   ┌─────────────────────┐         ┌───────────────────────────┐
   │ emeq/snelstart-api  │         │ emeq/mollie-api wrapper   │
   │ (Saloon connector + │         │ + mollie/mollie-api-php   │
   │ OData QueryBuilder) │         │ + laravel-cashier-mollie  │
   └──────────┬──────────┘         └──────────────┬────────────┘
              │ OData/REST                        │ REST (Connect OAuth2)
              ▼                                   ▼
        ┌─────────────────┐               ┌───────────────────┐
        │ Snelstart API   │               │ Mollie API        │
        │ (clientkey-flow)│               │ (Connect partner) │
        └─────────────────┘               └───────────────────┘

Postgres 16 (tabellen: consumers, accounts, connections, pass_through_calls,
personal_access_tokens, webhook_calls, subscriptions, orders, payments,
order_items, credits, refunds, refund_items, applied_coupons, redeemed_coupons,
users, jobs, cache)
Redis 7 (queue: default + webhooks, sessions, cache, Horizon supervision)
```

## Component Responsibilities

| Component | Responsibility | File |
|-----------|----------------|------|
| `Consumer` model | Sanctum-authenticatable SaaS-app-registratie; `Billable` voor Cashier-Mollie; bezit `accounts()` en `webhook_callback_url`/`webhook_callback_secret` (encrypted) | `app/Models/Consumer.php` |
| `Account` model | Eindgebruiker bij een Consumer; uniek op `(consumer_id, external_id)`; bezit `connections()` | `app/Models/Account.php` |
| `Connection` model | Eén OAuth- óf key-koppeling per Account/provider; encrypted casts op `access_token`/`refresh_token`/`client_key`/`subscription_key`; `fingerprint()`-accessor | `app/Models/Connection.php` |
| `PassThroughCall` model | Immutable audit-row per HTTP-pass-through; `$timestamps = false`, eigen `created_at` via migration; geen `direction` (5c gepland) | `app/Models/PassThroughCall.php` |
| `OAuthFlow` contract | Provider-agnostisch OAuth2-contract: `getAuthorizationUrl`, `exchangeCode`, `refreshToken`, `revoke` | `app/OAuth/Contracts/OAuthFlow.php` |
| `OAuthFlowRegistry` | Singleton-registry; `register(provider, class)` + `for(provider)` resolved via container | `app/OAuth/OAuthFlowRegistry.php` |
| `MollieConnectOAuthFlow` | Live Mollie Connect implementatie; gebruikt `Illuminate\Http\Client` direct, Cache::lock voor refresh | `app/OAuth/Mollie/MollieConnectOAuthFlow.php` |
| `MollieConnectionContext` | Per-request scoped container voor "huidige" Mollie-Connection; gelezen door SDK-resolver | `app/Mollie/MollieConnectionContext.php` |
| `HubMollieCredentialResolver` | Implementeert `Emeq\MollieApi\Contracts\MollieCredentialResolver`; lazy refresh ruim vóór expiry | `app/Mollie/HubMollieCredentialResolver.php` |
| `HubSnelstartCredentialResolver` | Implementeert `Emeq\SnelstartApi\Contracts\SnelstartCredentialResolver`; per-Connection instance | `app/Services/Snelstart/HubSnelstartCredentialResolver.php` |
| `ResolveSnelstartAccount` middleware | Leest `X-Account-Id`, scopt naar Consumer, bindt per-request resolver + `forgetInstance(Snelstart::class)` | `app/Http/Middleware/ResolveSnelstartAccount.php` |
| `ResolveMollieAccount` middleware | Leest `X-Account-Id`, scopt naar Consumer, set `MollieConnectionContext` (geen forgetInstance — Mollie::client() bouwt fresh) | `app/Http/Middleware/ResolveMollieAccount.php` |
| `AbstractMolliePassThroughController` | Gedeelde write-pipeline voor 8 Mollie-controllers: ability-guard, 415-guard, exception-mapping, audit-write, `buildClient()` met Idempotency-Key forward | `app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php` |
| Snelstart `PassThroughController` | Catch-all `/v1/snelstart/{path}` via `RawSnelstartRequest` + Saloon connector; eigen audit-write (geen abstract base) | `app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php` |
| `MollieWebhookController` | 7-stap Connect webhook ingress: signature-verify, Connection-lookup, anti-spoofing fetch, Spatie audit-write, fan-out dispatch | `app/Http/Controllers/Webhooks/MollieWebhookController.php` |
| `ForwardMollieWebhookToConsumer` job | Async fan-out via `Spatie\WebhookServer\WebhookCall::create()` met per-Consumer `webhook_callback_secret` | `app/Jobs/ForwardMollieWebhookToConsumer.php` |
| `PlanResolver` | Config-driven plan-lookup (`config/billing-plans.php`); `find()` throwt `UnknownPlanException` | `app/Billing/PlanResolver.php` |
| `TokenAbilities` | `final class` met `public const` constants — geen enum (Sanctum vergelijkt ruwe strings) | `app/Sanctum/TokenAbilities.php` |
| `UpstreamErrorMapper` (Snelstart) | Mapt SDK-exceptions → `{status, body, headers, short_code}`; 401/403 → 502 cloaked | `app/Support/Snelstart/UpstreamErrorMapper.php` |
| `MollieUpstreamErrorMapper` | Idem voor Mollie-SDK-exceptions | `app/Support/Mollie/MollieUpstreamErrorMapper.php` |
| `HeaderForwarder` (Snelstart) | Whitelist (`Accept`, `Content-Type`, `If-Match`, `If-None-Match`); blokkeert credential-headers | `app/Support/Snelstart/HeaderForwarder.php` |
| `MollieHeaderForwarder` | Equivalent voor Mollie pass-through | `app/Support/Mollie/MollieHeaderForwarder.php` |

## Pattern Overview

**Overall:** Multi-tenant pass-through gateway met provider-agnostisch credential-resolver pattern.

**Key Characteristics:**

- Layered Laravel-app: Routes → Middleware → Controllers → Models/Services → SDK-packages → Partner-API.
- Per-request resolver-binding: middleware leest `X-Account-Id`, resolved Connection, en bindt een `*CredentialResolver` voor de duur van het request (SDK-contract uit `emeq/*` packages).
- Twee divergente provider-shapes verenigd in één `connections`-tabel: Mollie = OAuth2 (`access_token`/`refresh_token`/`expires_at`/`scopes`), Snelstart = clientkey (`client_key`/`subscription_key`/`subscription_id`). Niet-gebruikte kolommen blijven NULL.
- Audit-first: elk pass-through-request schrijft één rij in `pass_through_calls` (immutable, `$timestamps=false`, eigen `created_at`); webhook-ingress schrijft één rij in `webhook_calls` (Spatie).
- Pass-through is endpoint-template-aware: `path`-kolom in audit krijgt template (`/v2/payments/{id}`), nooit query-string of concrete id (privacy + group-by analytics).
- Hub-domeinmodellen (`Consumer`, `Account`, `Connection`) bestaan **alleen** in `emeq-hub` — SDK-packages in `packages/` zijn dun en kennen Hub niet.

## Layers

**Routes (`routes/*.php`):**

- Purpose: HTTP-ingress declaration.
- Location: `routes/api.php`, `routes/webhooks.php`, `routes/web.php`, `routes/console.php`.
- Contains: Route-bindings, middleware-stacks, name-routes.
- Depends on: Controller-klassen.
- Used by: Laravel router (`bootstrap/app.php::withRouting`).

**HTTP / Controllers (`app/Http/Controllers/`):**

- Purpose: Request → SDK-call → response orchestratie + audit-write.
- Location: `app/Http/Controllers/Api/V1/`, `app/Http/Controllers/Webhooks/`.
- Contains: Single-action invokables én resource-controllers; geen domein-logica.
- Depends on: Models, Resolvers, SDK-facades (`Mollie::client()`, `Snelstart`), Support-mappers.
- Used by: Routes.

**Middleware (`app/Http/Middleware/`):**

- Purpose: Cross-cutting auth/scoping/guards vóór controllers.
- Location: `app/Http/Middleware/`.
- Contains: `ResolveSnelstartAccount`, `ResolveMollieAccount`, `EnsureEmeqAdminToken`, `RequireCashierWebhookSecret`, `SetNoIndexHeaders` (global), plus Sanctum's `CheckForAnyAbility` aliased als `ability`.
- Depends on: Models, container-bindings.
- Used by: Route-groups (alias-keys in `bootstrap/app.php`).

**Domain / Models (`app/Models/`):**

- Purpose: Eloquent-laag rond multi-tenant data + Cashier-Billable trait.
- Location: `app/Models/`.
- Contains: 5 models (Consumer, Account, Connection, PassThroughCall, User); Cashier-vendor-models leven in `vendor/laravel/cashier-mollie/`.
- Depends on: Database schema, encryption-cast, Sanctum's `HasApiTokens`, Cashier's `Billable`.
- Used by: Controllers, middleware, resolvers, jobs.

**Service / Domain (`app/OAuth`, `app/Mollie`, `app/Services`, `app/Billing`, `app/Support`):**

- Purpose: Provider-specifieke logica die niet in een Model thuishoort en niet in de dunne SDK mag.
- Location: `app/OAuth/` (OAuth-flows), `app/Mollie/` (Mollie context + resolver), `app/Services/Snelstart/` (Snelstart resolver), `app/Billing/` (Cashier plan-resolver), `app/Support/{Snelstart,Mollie}/` (header-forwarders + error-mappers).
- Depends on: Models, SDK-contracts, `Illuminate\Http\Client`.
- Used by: Controllers, middleware, jobs.

**Jobs (`app/Jobs/`):**

- Purpose: Async werk via Horizon queue.
- Location: `app/Jobs/`.
- Contains: `ForwardMollieWebhookToConsumer` (enige job in v0.2; Cashier dispatcht eigen vendor-jobs).
- Depends on: Models, Spatie webhook-server.
- Used by: Webhook-controllers.

**Console (`app/Console/Commands/`):**

- Purpose: Artisan-commands voor ops + scheduling.
- Location: `app/Console/Commands/`.
- Contains: `HubConsumerCreate` (provisioning), `PruneOAuthPendingConnections` (cleanup).
- Used by: Operators + Horizon scheduler (configuratie pending).

**SDK packages (`packages/<sdk>/`):**

- Purpose: Dunne Saloon-/HTTP-laag rond partner-API's; geen Hub-domeinmodellen.
- Location: `packages/snelstart-api/` (read-clone van `github.com:yusufkaracaburun/emeq-snelstart-api`), `packages/mollie-api/` (read-clone van `github.com:yusufkaracaburun/emeq-mollie-api`).
- Note: `packages/` is gitignored; Composer require't via VCS-repository (zie `composer.json` § repositories).
- Used by: Controllers via facade (`Mollie::client()`, `app(Snelstart::class)`) en resolvers.

## Data Flow

### Primary Request Path — Mollie pass-through (`POST /v1/mollie/payments`)

1. Request raakt `routes/api.php:60` met `auth:sanctum` + `throttle:api` + `resolve.mollie.account` middleware (`routes/api.php:27,60`).
2. Sanctum resolved Bearer-PAT → `Consumer` ingelogd op `$request->user()` (`bootstrap/app.php:30`).
3. `ResolveMollieAccount::handle()` leest `X-Account-Id`, scopt `Account` op `consumer_id`, lookup `Connection` waar `provider=mollie AND revoked_at IS NULL`, en set `MollieConnectionContext::set($connection)` (`app/Http/Middleware/ResolveMollieAccount.php:25-66`).
4. `PaymentsController::store()` → `AbstractMolliePassThroughController::handle()`:
   - Ability-guard op token (`mollie:write` of `*`) (`AbstractMolliePassThroughController.php:42-57`).
   - 415-guard: `Content-Type` moet `application/json` zijn voor write (`:60-69`).
   - `buildClient()` haalt `Mollie::client()` op, forward't `Idempotency-Key`-header runtime via `MollieApiClient::setIdempotencyKey()` (`:196-206`).
5. SDK-resolver: `HubMollieCredentialResolver::resolve()` leest `Connection` uit context, refresh't proactief als `expires_at < now + 5 min`, returnt `MollieOAuthCredentials` (`app/Mollie/HubMollieCredentialResolver.php:17-29`).
6. SDK-call gaat naar Mollie's API; resultaat of `MollieApiException` komt terug.
7. Exception → `MollieUpstreamErrorMapper::mapException()` → `{status, body, headers, short_code}`. 401/403 cloaked naar 502 (`app/Support/Mollie/MollieUpstreamErrorMapper.php`).
8. Audit-write naar `pass_through_calls` (template-path, geen query-string, fingerprint NULL bij lege body) (`AbstractMolliePassThroughController.php:96-119`).
9. Response render't met origin-Mollie-wire-shape (verbatim JSON via `BaseResource::getResponse()->body()`) (`:128-185`).

### Snelstart pass-through (`ANY /v1/snelstart/{path}`)

1. Same Sanctum + throttle + `resolve.snelstart.account` middleware-stack (`routes/api.php:41-44`).
2. Middleware `app()->instance(SnelstartCredentialResolver::class, new HubSnelstartCredentialResolver($connection))` en `app()->forgetInstance(Snelstart::class)` om singleton te resetten (`app/Http/Middleware/ResolveSnelstartAccount.php:66-74`).
3. `PassThroughController::__invoke()` bouwt `RawSnelstartRequest` (Saloon `Method`-enum + endpoint + query + body + whitelisted headers) (`app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php:76-83`).
4. SDK doet OData/REST-call; failed-status throwt SDK-eigen exception → `UpstreamErrorMapper` (`:98-104`).
5. Audit-write naar `pass_through_calls` met template-path (`endpoint`-string), `query_keys` (CSV), fingerprint, status (`:111-127`).
6. Response stream't body verbatim met origin `Content-Type` (`:129-132`).

### Mollie Connect webhook ingress (`POST /webhooks/mollie/{connection_id}`)

1. Public route (no Sanctum), inherits `api`-group middleware via `bootstrap/app.php::withRouting->then`.
2. `MollieWebhookController::__invoke()`:
   - Hard-fail guard: `services.mollie.webhook_secret` config moet leesbaar zijn anders 500 + audit-row (`MollieWebhookController.php:41-46`).
   - Signature-verify via `Emeq\MollieApi\Webhooks\MollieWebhookSignature::verify($request, $secret)` (`:49-60`).
   - Connection-lookup op `connection_id` + `provider=mollie` + `revoked_at IS NULL` (`:63-72`).
   - Payload `id`-veld required (`:75-81`).
   - Anti-spoofing: `app(MollieConnectionContext::class)->set($connection)` + `Mollie::client()->payments->get($payload['id'])` — als die call faalt is de payload niet van deze Connection (`:83-92`).
   - Audit-write naar Spatie `WebhookCall::create([name=mollie])` (`:94-99`).
   - `ForwardMollieWebhookToConsumer::dispatch($connection, $payload)` (`:102`).
3. Job leest `$connection->account->consumer`, dispatch't Spatie `WebhookServer\WebhookCall` met `webhook_callback_url` + per-Consumer `webhook_callback_secret` (`app/Jobs/ForwardMollieWebhookToConsumer.php:35-47`).

### OAuth Connect flow (Mollie)

1. `POST /v1/oauth/mollie/init` (Sanctum + `ability:mollie:write`) → `InitController::__invoke()` creëert `Connection(status=pending, oauth_state, oauth_state_expires_at = now+30min)` en returnt `{connection_id, redirect_url}` (`app/Http/Controllers/Api/V1/OAuth/InitController.php`).
2. Browser-redirect naar Mollie `https://my.mollie.com/oauth2/authorize?…` met state.
3. Mollie callback `GET /v1/oauth/mollie/callback?code=…&state=…` (publiek, state = auth-token) → `CallbackController` lookup pending Connection op `(oauth_state, oauth_state_expires_at > now)` → `OAuthFlowRegistry::for('mollie')->exchangeCode($connection, $code)` → encrypted `access_token`/`refresh_token`/`expires_at`/`scopes` opgeslagen, `status=active`, `oauth_state=null`.
4. Lazy refresh: `HubMollieCredentialResolver::resolve()` triggert `refreshToken()` als `expires_at < now + 5min`; `Cache::lock("oauth:refresh:{id}", 30)->block(15, …)` voorkomt thundering-herd (`app/OAuth/Mollie/MollieConnectOAuthFlow.php:56-78`).

### Cashier-Mollie billing (Phase 6, shipped)

1. `GET /v1/billing/subscription` (Sanctum + `ability:billing:read|billing:write|*`) → `Billing\SubscriptionController::show()` leest `$consumer->subscription('main')` en derived state via `active()`/`cancelled()`/`onTrial()`/`onGracePeriod()`/`ended()`.
2. Emeq-admin: `POST /v1/admin/billing/subscriptions` (Sanctum + `ability:billing:write|*` + `emeq.admin` allowlist-middleware) → `Admin\Billing\SubscriptionController::store()` resolved `PlanResolver::find($slug)`, dispatcht `$consumer->newSubscription($name, $slug)->create()`. First-payment-flow retourneert `{mandate_redirect_url}`.
3. Mollie's reguliere webhooks (NIET Connect) raken `/cashier/webhook*` achter `cashier.webhook.secret`-middleware → Cashier-vendor controllers handlen state-machine intern (`routes/webhooks.php:28-37`). Cashier's default-routes uitgezet via `Cashier::ignoreRoutes()` in `AppServiceProvider::register()`.

### State Management

- Per-request state: middleware-bound resolvers (Snelstart instance-bind, Mollie scoped context).
- Persistent state: Postgres (Consumer/Account/Connection/PassThroughCall + Cashier-tabellen).
- Async: Redis-queue `default` (algemeen) + `webhooks` (Spatie). Horizon supervisor configured in `config/horizon.php`.
- Cache: `oauth:refresh:{connection_id}` lock; Snelstart-token cache via `Emeq\SnelstartApi\Auth\LaravelTokenCache`.

## Key Abstractions

**OAuthFlow contract (`app/OAuth/Contracts/OAuthFlow.php`):**

- Purpose: Provider-agnostisch OAuth2-pattern voor Hub.
- Examples: `MollieConnectOAuthFlow` (live), `FakeOAuthFlow` (`app/OAuth/Testing/FakeOAuthFlow.php`, container-bindable in tests).
- Pattern: Registry + container-resolve (`OAuthFlowRegistry::for('mollie')`), nieuwe providers registreren in `AppServiceProvider::register()`.

**CredentialResolver per SDK:**

- Purpose: SDK-contracten die middleware per-request bindt aan de juiste Connection.
- Examples: `Emeq\SnelstartApi\Contracts\SnelstartCredentialResolver` ← `HubSnelstartCredentialResolver`; `Emeq\MollieApi\Contracts\MollieCredentialResolver` ← `HubMollieCredentialResolver`.
- Pattern: SDK kent géén Hub-domein; Hub bindt een implementatie per Connection (Snelstart via `app()->instance(...)`, Mollie via scoped `MollieConnectionContext`).

**ConnectionContext (Mollie):**

- Purpose: Per-request scoped state-holder zodat `Mollie::client()` (singleton-resolve) een verse `MollieApiClient` kan bouwen die de juiste credentials kent.
- Examples: `app/Mollie/MollieConnectionContext.php` (gebound als `scoped`).
- Pattern: alternatief voor `forgetInstance()` (zoals Snelstart doet) — Mollie-SDK bouwt elke call een verse client uit de scoped resolver.

**PassThroughCall audit-row:**

- Purpose: Eén row per Consumer-getriggerde HTTP-pass-through, immutable, sub-second.
- Pattern: `$timestamps=false`, eigen `created_at` (`useCurrent()`), partial index `WHERE status >= 500`, geen `direction` (5c voegt `direction` enum + `event_id`-uniqueness toe).
- ADR: `.docs/decisions/pass-through-calls-table.md`.

## Entry Points

**`/` smoke + `/up` health-check:**

- Location: `routes/web.php`.
- Triggers: HTTP GET (`/up` ook via Laravel's `health: '/up'` in `bootstrap/app.php:21`).
- Responsibilities: liveness (database + Redis ping).

**`/v1/*` consumer-API:**

- Location: `routes/api.php` (prefix gezet in `bootstrap/app.php:20` als `apiPrefix: 'v1'`).
- Triggers: HTTP via Sanctum-PAT.
- Responsibilities: Account/Connection-provisioning, OAuth-init/callback, Mollie + Snelstart pass-through, billing-read + admin-write.

**`/webhooks/*` publieke webhook-ingress:**

- Location: `routes/webhooks.php` (geregistreerd in `bootstrap/app.php:23-26` binnen `Route::middleware('api')->group`).
- Triggers: HTTP POST van Mollie (Connect signed) of Mollie (Cashier unsigned + secret).
- Responsibilities: Signature/secret-verify, audit, fan-out.
- Memory: Webhook-routes erven `throttle:api` — bursting partners kunnen expliciete `withoutMiddleware(['throttle:api'])` nodig hebben (geen actie nu, future-flag).

**`php artisan` commands:**

- Location: `routes/console.php` (alleen `inspire`), `app/Console/Commands/` (`HubConsumerCreate`, `PruneOAuthPendingConnections`).
- Triggers: CLI of Horizon scheduler (scheduler-binding pending).
- Responsibilities: Consumer-provisioning, pending-OAuth cleanup.

## Architectural Constraints

- **Threading:** Standaard PHP-FPM single-process per request. Async werk via Horizon-Redis queues; geen worker-threads.
- **Global state:** `MollieConnectionContext` is `scoped()` — niet `singleton()` — om cross-request leakage uit te sluiten. `Snelstart`-facade-target is een vendor-singleton; middleware doet `app()->forgetInstance(Snelstart::class)` om verse resolve af te dwingen.
- **Circular imports:** None known. Hub depends on SDKs; SDKs depend on niets uit `app/`.
- **Provider-agnostiek invariant:** Hub-domeinmodellen (`Consumer`/`Account`/`Connection`) bestaan **alleen** in de Hub. SDK-packages (`packages/snelstart-api/`, `packages/mollie-api/`) mogen ze niet importeren. Bewaakt door PSR-4 + Composer-grenzen.
- **Multi-tenant invariant:** Connection-resolution ALTIJD via `Bearer → Consumer → Account (consumer_id-scoped) → Connection (account_id-scoped)`. Nooit `?connection_id=` op de URL, nooit X-Connection-Id zonder Consumer-validatie. `X-Account-Id` is de enige toegestane tenant-routing-header.
- **Encryption-at-rest invariant:** `access_token`, `refresh_token`, `client_key`, `subscription_key`, `webhook_callback_secret` hebben Eloquent `encrypted` cast. `subscription_id` is **niet** encrypted (tenant-UUID, geen secret — D-01 in Phase 3). Verboden om raw credentials in logs of exception-messages te zetten; fingerprint (`sha256(secret)[0..12]`) is OK.
- **Migrations forward-only:** Geen `down()`-aanroepen in productie-pad. Nieuwe schema-changes = nieuwe migration (zie `2026_05_15_000002_add_query_keys_to_pass_through_calls_table.php`).

## Anti-Patterns

### Query-string-based Connection-resolutie

**What happens:** Een ontwikkelaar voegt `?connection_id=…` of `X-Connection-Id`-header toe om een specifieke Connection te kiezen.
**Why it's wrong:** Breekt multi-tenant invariant — Consumer A kan dan Connection-ID van Consumer B opgeven en cross-tenant data lekken.
**Do this instead:** Resolve altijd via `X-Account-Id` + Consumer-scoped Account-lookup + scoped Connection. Zie `ResolveMollieAccount.php:35-60` of `ResolveSnelstartAccount.php:38-64`.

### Hub-domeinmodellen importeren in SDK-packages

**What happens:** SDK-resource heeft `use App\Models\Connection;` om "even" een tenant-veld te lezen.
**Why it's wrong:** Maakt SDK afhankelijk van Hub, blokkeert herbruik in andere consumer-apps, breekt `.ai/rules/engineering.md` ("SDK-grenzen niet doorbreken om snel klaar te zijn").
**Do this instead:** SDK definieert een `*CredentialResolver`-contract en een DTO (`SnelstartCredentials`/`MollieOAuthCredentials`). Hub implementeert het contract in `app/Mollie/` / `app/Services/Snelstart/` en bindt via container.

### Raw secrets in audit/logs

**What happens:** Iemand logt `$connection->access_token` of zet `'client_key' => $key` in een exception-message.
**Why it's wrong:** Een DB-dump of log-pipe lekt productie-tokens. Breekt `.ai/rules/global.md` security-regel.
**Do this instead:** Gebruik `$connection->fingerprint()` (returnt `sha256(secret)[0..12]`). Zie `app/Models/Connection.php:39-48`.

### Path-with-query-string in `pass_through_calls.path`

**What happens:** Auditor schrijft `$request->fullUrl()` of `$endpoint . '?' . http_build_query(...)` in de `path`-kolom.
**Why it's wrong:** Maakt group-by analytics onmogelijk, en concrete id's (`/v2/payments/tr_abc`) zijn high-cardinality én privacy-gevoelig.
**Do this instead:** Audit krijgt endpoint-template (`/v2/payments/{id}`); query-keys CSV (`query_keys`-kolom); concrete id alleen via Mollie's eigen audit-trail. Zie `AbstractMolliePassThroughController.php:109-110` en `PassThroughController.php:117`.

### `down()` in vendor-migrations editen

**What happens:** Ontwikkelaar past een eerdere migration aan in plaats van een nieuwe te maken.
**Why it's wrong:** Production heeft die migration al gerund; `migrate:fresh` is alleen dev-pad.
**Do this instead:** Nieuwe migration toevoegen die `Schema::table()->...` doet. Zie `2026_05_15_000001_add_oauth_state_to_connections_table.php` (volgt op `2026_05_14_000003_create_connections_table.php`).

## Error Handling

**Strategy:** SDK-exceptions worden gemapt naar Hub-HTTP-responses via dedicated mappers (`Support/Snelstart/UpstreamErrorMapper`, `Support/Mollie/MollieUpstreamErrorMapper`); Sentry captured non-mapped exceptions via `Integration::handles($exceptions)` in `bootstrap/app.php:40-42`.

**Patterns:**

- **Cloaking:** Upstream 401/403 → Hub 502 (`upstream_auth_failed`) om partner-auth-state niet te lekken (threat T-05a-06 / T-05b-10).
- **Validation pass-through:** Mollie 422 → Hub 422 + `{field}`-veld preserved; Snelstart `ValidationException` → 400 + `error_codes` array.
- **Rate-limit forward:** 429 + `Retry-After`-header doorgegeven aan Consumer.
- **Audit on error:** Audit-write gebeurt ook bij upstream-fout; `upstream_error`-kolom krijgt short-code (`mollie_auth`, `snelstart_5xx`, `mollie_5xx`, `snelstart_timeout`, etc.).
- **Webhook hard-fail:** Ontbrekende webhook-secret-config → 500 + audit-row, géén accept (`MollieWebhookController.php:41-46`, `RequireCashierWebhookSecret.php:28-36`).
- **OAuth state CSRF:** Tampered/expired state → 400 in `CallbackController` (matched op `oauth_state` + `oauth_state_expires_at > now`).

## Cross-Cutting Concerns

**Logging:** Default Laravel `stack` channel; Sentry voor uncaught exceptions; geen application-level audit log voor admin-acties (`HUB-AUDIT` is backlog-item).

**Validation:** Form-Requests in `app/Http/Requests/` voor write-endpoints (`StoreAccountRequest`, `StoreConnectionRequest`, `CreatePaymentRequest`, `CreateSubscriptionRequest`, etc.). Inline `$request->validate(...)` voor OAuth-callbacks. Edge-validatie aan Hub-rand; SDK krijgt al-gevalideerde payloads.

**Authentication:** Sanctum-PAT (`auth:sanctum`) + abilities (`ability:mollie:read,*`); Consumer-model gebruikt `HasApiTokens`. Webhook-routes zijn publiek en signature/secret-verified. Admin-billing-routes gebruiken extra `emeq.admin`-middleware (config-allowlist tot Phase 9 Filament-panel komt).

**No-index:** Global middleware `SetNoIndexHeaders` zet `X-Robots-Tag: noindex, nofollow` voor app-wide bot-blocking.

**API docs:** `dedoc/scramble` genereert OpenAPI op `/docs/api` met Bearer-security-scheme; toegang via Gate `viewApiDocs` + `?token=`-query (`AppServiceProvider::boot()`).

---

*Architecture analysis: 2026-05-15. Active milestone v0.2 (Phases 2/3/4/5a/5b/6 shipped; 5c blocked op Snelstart partner-respons; 7/8/9 planned).*
