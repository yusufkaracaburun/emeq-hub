# Architecture

Structurele map voor agents. De canonieke high-level beschrijving (Consumer→Account→Connection-keten, domeinmodel-tabel, invariants) staat in `CLAUDE.md` (`.ai/project rules`-block); dit document is de **document-existing** laag- en componentkaart. Gemigreerd uit `.planning/codebase/ARCHITECTURE.md` (analyse 2026-05-15) bij de GSD→ai-kit-overgang.

## System overview

```text
Consumer (SaaS-app van Emeq of derde)
  │  Bearer-PAT (Sanctum, abilities: snelstart:*/mollie:*/billing:*/admin)
  │  HTTP/REST + X-Account-Id-header            ▲ outbound webhook fan-out
  ▼                                             │
emeq-hub (Laravel 13.9)
  Routes (api.php /v1/*, webhooks.php /webhooks/*)
    → Middleware (resolve.{snelstart,mollie}.account, emeq.admin, ability:…)
    → Controllers (Api\V1\*, Webhooks\*) — orchestratie + audit, geen domeinlogica
    → Domain/Service (app/{Models,OAuth,Mollie,Services,Billing,Support,Jobs})
    → SDK-packages → Partner-API
       ├─ emeq/snelstart-api (Saloon connector + OData QueryBuilder) → Snelstart (clientkey)
       └─ emeq/mollie-api + mollie/mollie-api-php + cashier-mollie → Mollie (Connect OAuth2)

Postgres 16: consumers, accounts, connections, pass_through_calls,
  personal_access_tokens, webhook_calls, subscriptions/orders/payments (Cashier), users
Redis 7: queue (default + webhooks), sessions, cache, Horizon-supervision
```

## Layers

| Laag | Locatie | Verantwoordelijkheid |
|------|---------|----------------------|
| Routes | `routes/{api,webhooks,web,console}.php` | HTTP-ingress declaration; middleware-stacks; named routes |
| Controllers | `app/Http/Controllers/{Api/V1,Webhooks}/` | Request → SDK-call → response + audit-write; géén domeinlogica |
| Middleware | `app/Http/Middleware/` | Cross-cutting auth/scoping/guards vóór controllers |
| Models | `app/Models/` | Eloquent multi-tenant data + Cashier `Billable` |
| Service/Domain | `app/{OAuth,Mollie,Services,Billing,Support}/` | Provider-specifieke logica die niet in een Model hoort en niet in de dunne SDK mag |
| Jobs | `app/Jobs/` | Async werk via Horizon-queue |
| Console | `app/Console/Commands/` | Artisan-ops (`HubConsumerCreate`, `PruneOAuthPendingConnections`) |
| SDK-packages | `packages/<sdk>/` (gitignored, VCS-require) | Dunne Saloon/HTTP-laag; géén Hub-domeinmodellen |

## Component-verantwoordelijkheden

| Component | Rol | File |
|-----------|-----|------|
| `Consumer` | Sanctum-authenticatable SaaS-app; `Billable`; bezit `accounts()` + encrypted `webhook_callback_url/secret` | `app/Models/Consumer.php` |
| `Account` | Eindgebruiker bij Consumer; uniek `(consumer_id, external_id)`; bezit `connections()` | `app/Models/Account.php` |
| `Connection` | OAuth- óf key-koppeling per Account/provider; encrypted casts; `fingerprint()` | `app/Models/Connection.php` |
| `PassThroughCall` | Immutable audit-row per pass-through; `$timestamps=false`, eigen `created_at` | `app/Models/PassThroughCall.php` |
| `OAuthFlow` contract | Provider-agnostisch OAuth2: `getAuthorizationUrl`/`exchangeCode`/`refreshToken`/`revoke` | `app/OAuth/Contracts/OAuthFlow.php` |
| `OAuthFlowRegistry` | Singleton-registry; `register()` + `for(provider)` via container | `app/OAuth/OAuthFlowRegistry.php` |
| `MollieConnectOAuthFlow` | Live Connect-impl; `Illuminate\Http\Client` + `Cache::lock` voor refresh | `app/OAuth/Mollie/MollieConnectOAuthFlow.php` |
| `MollieConnectionContext` | Per-request scoped holder voor huidige Mollie-Connection | `app/Mollie/MollieConnectionContext.php` |
| `Hub{Mollie,Snelstart}CredentialResolver` | Implementeert SDK-`*CredentialResolver`-contract per Connection; lazy refresh vóór expiry | `app/Mollie/`, `app/Services/Snelstart/` |
| `Resolve{Mollie,Snelstart}Account` mw | Leest `X-Account-Id`, scopt Account op Consumer, bindt resolver/context | `app/Http/Middleware/` |
| `AbstractMolliePassThroughController` | Gedeelde write-pipeline 8 Mollie-controllers: ability-guard, 415-guard, exception-map, audit, Idempotency-Key-forward | `app/Http/Controllers/Api/V1/Mollie/` |
| Snelstart `PassThroughController` | Catch-all `/v1/snelstart/{path}` via `RawSnelstartRequest` + eigen audit | `app/Http/Controllers/Api/V1/Snelstart/` |
| `MollieWebhookController` | Connect webhook-ingress: signature-verify, lookup, anti-spoofing fetch, Spatie-audit, fan-out | `app/Http/Controllers/Webhooks/` |
| `ForwardMollieWebhookToConsumer` | Async fan-out via Spatie webhook-server + per-Consumer secret | `app/Jobs/` |
| `{Snelstart,Mollie}UpstreamErrorMapper` | SDK-exceptions → `{status,body,headers,short_code}`; 401/403→502 cloaked | `app/Support/{Snelstart,Mollie}/` |
| `{Snelstart,Mollie}HeaderForwarder` | Whitelist forward-headers; blokkeert credential-headers | `app/Support/{Snelstart,Mollie}/` |
| `TokenAbilities` | `final class` met `public const` (geen enum — Sanctum vergelijkt ruwe strings) | `app/Sanctum/TokenAbilities.php` |
| `PlanResolver` | Config-driven plan-lookup (`config/billing-plans.php`); throwt `UnknownPlanException` | `app/Billing/PlanResolver.php` |

## Data-flow (samengevat)

- **Mollie pass-through** (`POST /v1/mollie/payments`): Sanctum-PAT → `ResolveMollieAccount` zet `MollieConnectionContext` → `AbstractMolliePassThroughController` (ability+415-guard, Idempotency-Key forward) → `HubMollieCredentialResolver` (proactieve refresh < expiry+5min) → SDK-call → exception-map → audit-write (template-path) → verbatim Mollie-wire-response.
- **Snelstart pass-through** (`ANY /v1/snelstart/{path}`): `ResolveSnelstartAccount` doet `app()->instance(SnelstartCredentialResolver)` + `forgetInstance(Snelstart::class)` → `PassThroughController` bouwt `RawSnelstartRequest` → OData/REST → error-map → audit → verbatim body.
- **Mollie Connect webhook** (`POST /webhooks/mollie/{connection_id}`): hard-fail op ontbrekende secret → signature-verify → Connection-lookup → anti-spoofing `payments->get(id)` → Spatie `WebhookCall::create` → `ForwardMollieWebhookToConsumer::dispatch`.
- **OAuth Connect** (Mollie): `POST /v1/oauth/mollie/init` maakt `Connection(status=pending, oauth_state, expires=now+30min)` → Mollie-redirect → `GET /v1/oauth/mollie/callback` matcht state → `exchangeCode` → encrypted tokens, `status=active`. Lazy refresh via `Cache::lock` (anti-thundering-herd).
- **Cashier-Mollie billing**: `/v1/billing/subscription` (read) + `/v1/admin/billing/subscriptions` (`emeq.admin`-write); Mollie's reguliere webhooks → `/cashier/webhook*` (secret-mw); Cashier default-routes uit via `Cashier::ignoreRoutes()`.

## Key abstractions

- **`OAuthFlow` contract** — provider-agnostisch; `MollieConnectOAuthFlow` (live) + `FakeOAuthFlow` (tests, container-bindable). Nieuwe provider registreren in `AppServiceProvider::register()`.
- **`CredentialResolver` per SDK** — SDK kent géén Hub-domein; Hub bindt per Connection (Snelstart `app()->instance(...)`, Mollie scoped context).
- **`MollieConnectionContext`** — `scoped()` (niet singleton) zodat `Mollie::client()` per call een verse client met juiste credentials bouwt.
- **`PassThroughCall` audit-row** — immutable, sub-second, partial index `WHERE status >= 500`. ADR: `.docs/decisions/pass-through-calls-table.md`.

## Invariants (niet zonder approval doorbreken)

- **Multi-tenant:** resolution ALTIJD `Bearer → Consumer → Account (consumer_id-scoped) → Connection (account_id-scoped)`. Nooit `?connection_id=`, nooit `X-Connection-Id` zonder Consumer-validatie. `X-Account-Id` is de enige tenant-routing-header.
- **Encryption-at-rest:** `access_token`/`refresh_token`/`client_key`/`subscription_key`/`webhook_callback_secret` hebben `encrypted` cast. `subscription_id` is **niet** encrypted (tenant-UUID, geen secret). Raw credentials nooit in logs/exceptions — gebruik `fingerprint()` (`sha256(secret)[0..12]`).
- **Provider-agnostiek:** Hub-domeinmodellen bestaan **alleen** in `emeq-hub`; SDK-packages mogen ze niet importeren (PSR-4 + Composer-grens).
- **Migrations forward-only:** geen `down()` in productie-pad; schema-change = nieuwe migration.

## Anti-patterns

- **Query-string Connection-resolutie** (`?connection_id=` / `X-Connection-Id`) → cross-tenant lek. Gebruik `X-Account-Id` + Consumer-scoped lookup.
- **Hub-domeinmodellen importeren in SDK** → breekt herbruik + `.ai/rules/engineering.md`. Gebruik het `*CredentialResolver`-contract + DTO.
- **Raw secrets in audit/logs** → DB-dump lekt tokens. Gebruik `fingerprint()`.
- **Path-met-query-string in `pass_through_calls.path`** → blokkeert group-by + privacy. Gebruik endpoint-template + `query_keys`-CSV.
- **`down()` in bestaande migration editen** → prod heeft 'm al gerund. Nieuwe migration toevoegen.

## Error handling

SDK-exceptions → Hub-HTTP via mappers; Sentry captured non-mapped. Patronen: 401/403→502 cloaked (`upstream_auth_failed`); 422 field-preserving; 429 + `Retry-After` forward; audit-write óók bij fout (`upstream_error` short-code); webhook hard-fail bij ontbrekende secret; OAuth state-CSRF → 400.

## Cross-cutting

- **Auth:** Sanctum-PAT + abilities; webhooks publiek + signature/secret-verified; admin-billing extra `emeq.admin` (config-allowlist + Filament-panel `/admin`).
- **No-index:** global `SetNoIndexHeaders` (`X-Robots-Tag: noindex, nofollow`).
- **API-docs:** `dedoc/scramble` op `/docs/api` (Gate `viewApiDocs` + `?token=`).
- **Validation:** Form-Requests aan Hub-rand; SDK krijgt al-gevalideerde payloads.
- **Feature-flags:** Pennant provider kill-switch (`feature.provider:{provider}`), auto-defined op `config('hub-providers')`. Zie `.docs/decisions/feature-flags-pennant-kill-switch.md`.
