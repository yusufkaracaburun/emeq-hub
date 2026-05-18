# Phase 5a: Mollie SDK Resources + Webhooks + Pass-through API — Context

**Gathered:** 2026-05-14
**Status:** Ready for planning (research-precondition: `.docs/partners/mollie/` import — zie D-10)
**Requirements:** MOLL-03, MOLL-04, HUB-03
**Depends on:** Phase 2 (SDK), Phase 3 (Hub-skeleton), Phase 4 (OAuth-broker + `HubMollieCredentialResolver` binding)

<domain>
## Phase Boundary

Consumer doet HTTP-call naar `/v1/mollie/<resource>[/{id}[/<action>]]` met `Authorization: Bearer <PAT>` + `X-Account-Id: <external_id>`. De Hub:

1. Authoriseert de PAT op `mollie:read` (GET) / `mollie:write` (POST/PATCH/DELETE)
2. Resolved `X-Account-Id` naar een `Account` van de geauthenticeerde Consumer (cross-Consumer → 404)
3. Resolved de actieve Mollie-`Connection` voor die Account (`provider='mollie'`, `status='active'`, `revoked_at=null`)
4. `MollieConnectionContext::set($connection)` (per-request scoped — Phase 4 D-16 binding) zodat `HubMollieCredentialResolver` de juiste `access_token` levert; lazy refresh (Phase 4 D-04/D-06) hangt eraan
5. Roept de SDK aan via typed resource-methods (`Mollie::client()->payments->create($payload)`, etc.) — niet via raw HTTP-passthrough
6. Mapt `Mollie\Api\Exceptions\*` via SDK's `MollieExceptionMapper` → `Emeq\MollieApi\Exceptions\*` → Hub's `MollieUpstreamErrorMapper` → response-envelope per `mollie-passthrough-api.md` ADR
7. Schrijft een audit-rij in `pass_through_calls` (provider=`mollie`); raw payload nooit in audit
8. Stroomt response terug — Mollie's body verbatim bij 2xx, Emeq-error-envelope bij 4xx/5xx

Plus webhook-ingress + fan-out:

9. `POST /webhooks/mollie/{connection_id}` (publiek, no Sanctum) — `MollieWebhookSignature::verify($request, config('services.mollie.webhook_secret'))` (SDK helper); tampered/missing → 400, niet doorgegeven aan Consumer
10. Anti-spoofing: fetch het Mollie-resource (`payments->get($id)`) met deze Connection's `access_token` — als Mollie 404 of access-denied returnt, claimt de webhook iets dat niet bij deze Connection hoort
11. Outgoing fan-out naar `consumers.webhook_callback_url` (nieuwe kolom, één per Consumer) via `spatie/laravel-webhook-server` queueable job; entry in `webhook_calls` (Spatie's tabel — apart van `pass_through_calls`)

**In scope (7 resources):**
- Payments (create/get/cancel)
- Customers (list/get/create)
- PaymentMethods (list)
- Refunds (create/get/list-per-payment)
- Mandates (list-per-customer/get/revoke)
- Subscriptions (create/get/cancel/list-per-customer)
- PaymentLinks (create/get/list)

**Niet in 5a:**
- Snelstart-pass-through (`/v1/snelstart/{path}`) → Phase 5b (al geland)
- Mollie Connect partner-resources (Onboarding-status, Organizations, Profiles, Permissions, ClientLinks) → backlog `MOLL-CONNECT-RES` (zie D-09)
- Cashier-Mollie billing voor Emeq's eigen Consumers → Phase 6
- Account-level `AccountSubscription`-state-machine → Phase 7
- Refresh-lock (Redis `Cache::lock`) per Connection → gedeferd (D-07)
- Per-Connection webhook-callback-URL override → gedeferd (Consumer-level voor v0.2)

</domain>

<decisions>
## Implementation Decisions

### Route shape

- **D-01: Per-resource routes + Form Requests, gedeelde abstract `MolliePassThroughController`.** Voor elk van de 7 resources expliciete routes + dedicated controller (single-action `__invoke` of resource-controller met method per Mollie-operation) en Form Requests per write-endpoint. Een gedeelde abstract base verzorgt tenant-resolutie, exception-mapping, audit-logging en idempotency-forwarding zodat de concrete controllers één SDK-call per actie houden.

  **Waarom niet catch-all (5b-stijl):**
  - `mollie-passthrough-api.md` ADR (Consequences) zegt expliciet: *"Hub Phase 5a-scope = controllers + Form Requests + audit-rows. Een gedeelde abstract `MolliePassthroughController` houdt de tenant-resolutie + exception-mapping + audit-laag DRY."*
  - Per-resource Form Requests vangen ongeldige `amount.value`/`webhookUrl`-shapes vóór Mollie's roundtrip (snellere consumer-feedback, lagere Mollie-quota-burn).
  - Scramble OpenAPI rendert per-route operations met "Try it out" — een catch-all rendert één wildcard-operation met onbruikbare payload-hints.
  - Mollie-SDK's typed resources (`payments->create($payload)`) geven typed exceptions die `MollieExceptionMapper` (SDK v0.1.0-alpha.1) deterministisch mapt; raw-HTTP-passthrough zou die exception-precisie verliezen.
  - Phase 5b's REVIEW.md heeft drie BLOCKER-issues blootgelegd (raw body niet JSON-veilig, PII in `path`, fingerprint-collision op lege body) die uit catch-all-stijl kwamen. Per-resource sluit die klasse fouten uit.

- **D-02: Route-namespace + folder-structuur** — `routes/api.php` krijgt een `Route::prefix('mollie')->middleware('resolve.mollie.account')->group(...)` blok ná de Snelstart-passthrough. Controllers leven in `app/Http/Controllers/Api/V1/Mollie/{Payments,Customers,PaymentMethods,Refunds,Mandates,Subscriptions,PaymentLinks}Controller.php`. Form Requests in `app/Http/Requests/Api/V1/Mollie/` (één bestand per write-operation, bv. `CreatePaymentRequest`).

### SDK invocation + tenant-resolutie

- **D-03: Nieuwe middleware `ResolveMollieAccount` (alias `resolve.mollie.account`).** Mirror van `ResolveSnelstartAccount` (`provider='mollie'`-filter) maar wijkt af op binding-pad:
  - Resolve Account + Connection (zelfde 400/404-shape als Snelstart-middleware).
  - **Niet** `app()->instance(MollieCredentialResolver::class, ...)` rebinden. Phase 4 bond `MollieCredentialResolver` al aan `HubMollieCredentialResolver`. De middleware roept `app(MollieConnectionContext::class)->set($connection)` (scoped singleton) — `HubMollieCredentialResolver::resolve()` leest die context.
  - Vergeet **niet** `Mollie::class` singleton (zoals Snelstart-middleware doet) want SDK's `Mollie` is per-request gebouwd via `bind`, niet `singleton`; check `MollieServiceProvider` bij planning of dat klopt — anders `forgetInstance(Mollie::class)` toevoegen voor robuustheid.
  - Set `$request->attributes->set('mollie_account', $account)` + `mollie_connection`.

- **D-04: Typed SDK-calls in controllers, niet raw HTTP-forward.** `Mollie::client()->payments->create($validated)`, `->customers->get($id)`, etc. Exception-pad: `try { $resource = $client->...->create(...); } catch (\Throwable $e) { $mapped = MollieUpstreamErrorMapper::mapException($e); }`. Response-body = `$resource->toArray()` (of `->raw()`) — Mollie's payload-shape blijft intact inclusief `_links`/`_embedded`.

### Audit-log

- **D-05: Hergebruik `pass_through_calls` met `provider='mollie'`.** Per `pass-through-calls-table.md` ADR ("Phase 5a kan dezelfde tabel hergebruiken — `provider`-kolom voorziet daar al in"). Pas drie 5b-REVIEW-fixes meteen toe (anders erven we de blockers):
  1. **`path`-kolom = endpoint-template zonder query-string** (`/v2/payments`, `/v2/payments/{id}`) — niet de raw request-URI. Query-keys (de namen, niet de waarden) gaan naar `query_keys`-kolom (al toegevoegd in `2026_05_15_000002_add_query_keys_to_pass_through_calls_table.php`).
  2. **`request_fingerprint` is `null` bij empty/missing body** — niet `sha256('[]')`. Conditioneel hashen: `$body !== null && $body !== []` → fingerprint, anders null.
  3. **Content-Type enforcement** — alle write-routes accepteren alleen `application/json` (Form Request `$expectsJson`-check of een rule). Mollie's API is JSON-only; dit voorkomt silent body-corruption.

  De `MolliePassThroughController` abstract base verzamelt deze velden uniform; concrete controllers hoeven het niet te kennen.

### Idempotency

- **D-06: Consumer-Idempotency-Key forward + SDK-fallback.**
  - Als Consumer-request een `Idempotency-Key`-header heeft, forward die letterlijk naar Mollie via SDK (`$client->payments->withIdempotencyKey($key)->create(...)` — SDK-API verifiëren bij planning).
  - Anders genereert SDK's `UuidV7IdempotencyKeyGenerator` (al gepubliceerd in SDK v0.1.0-alpha.1) er één — host-app bindt via `config('mollie.idempotency.generator')` op `UuidV7IdempotencyKeyGenerator::class`. Plan-stap: zet de config-key (waarschijnlijk via `config/mollie.php` publish of host-side `config/services.php` extension).
  - Audit-log slaat de gegenereerde of geforwarde key NIET op (geen body-content-traces — alleen `request_fingerprint`).
  - Acceptance (SC-5): twee identieke `POST /v1/mollie/payments` met dezelfde `Idempotency-Key`-header retourneren één Mollie-payment-ID — bewezen via `MollieApiClient::fake()` in feature-test.

### Webhook-ingress + fan-out

- **D-07: `POST /webhooks/mollie/{connection_id}` in nieuwe `routes/webhooks.php`-file.** Geen Sanctum, geen `v1`-prefix. `{connection_id}` is de Hub-interne PK (niet `external_id` — Mollie ziet die niet, dus geen privacy-issue; gebruikt bij Payment-create als `webhookUrl = url("/webhooks/mollie/{$connection->id}")`).

- **D-08: Webhook-flow.**
  1. `MollieWebhookSignature::verify($request, config('services.mollie.webhook_secret'))` — één platform-secret (Connect-webhooks zijn platform-signed, niet per-Connection). Tampered/missing → 400 + audit-rij in `webhook_calls` met `exception` gevuld.
  2. Lookup `Connection::find($connection_id)` waar `provider='mollie'` en `revoked_at` is null. Niet gevonden → 410 Gone (Mollie retried niet bij 4xx anders dan 408/429 — 410 is bewust permanent).
  3. **Anti-spoofing:** fetch de payload-resource via SDK met deze Connection's `access_token`. Webhook-body zit minimaal de Payment-id in. `$payment = Mollie::client()->payments->get($webhookPayload['id'])` — als Mollie 401/404 returnt op deze Connection: webhook claimt iets dat niet hier hoort → 400 + audit. (Connect-webhooks zijn platform-signed — een aanvaller die de platform-secret kent kan elke `id` posten naar elke connection-URL; deze stap controleert resource-ownership.)
  4. Schrijf inkomende-rij naar Spatie's `webhook_calls` (Spatie's standaardflow).
  5. Fan-out: dispatch `ForwardMollieWebhookToConsumer`-job (`spatie/laravel-webhook-server`) → POST naar `consumers.webhook_callback_url` met dezelfde payload + HMAC-signed met een **Hub-uitgegeven per-Consumer-secret** (niet Mollie's secret — Consumer mag Hub's signature verifiëren, niet Mollie's). Toevoegen: kolom `consumers.webhook_callback_url` + `consumers.webhook_callback_secret` (encrypted).
  6. Hub returnt 202 Accepted aan Mollie zodra fan-out queued is — niet wachten op Consumer-callback-respons.

- **D-09: Consumer-callback-URL leeft op Consumer-niveau (één per Consumer).** Niet per-Connection. Past bij ADR-tekst *"Hub doet pass-through fan-out naar Naschool's callback-URL"*. Per-Connection overrides → backlog wanneer een Consumer multiple sub-tenants met aparte URLs blijkt te willen. v0.2-realiteit: één SaaS-app (Naschool) = één callback-URL voor alle haar Accounts.

### Connect partner-resources

- **D-10: Mollie Connect partner-resources (Onboarding-status, Organizations, Profiles, Permissions, ClientLinks) zijn OUT-of-scope voor 5a.** Backlog `MOLL-CONNECT-RES`. Promote zodra een host-app productie-go-live het vereist (eerste merchant die Connect-onboarding via Hub doet). Het pass-through-pattern uit deze fase strekt zich straks zonder schema-change naar die resources uit.

### OAuth-scope-check

- **D-11: Geen pre-flight scope-check in Hub.** Laat Mollie het 403 sturen; `MollieUpstreamErrorMapper` mapt naar Hub-`502 mollie_auth_failed`. Phase 4 D-11 idee om de hint te returnen "je connection mist scope X — re-koppel" parkeren tot productie-friction toont dat een scope-gerelateerde 403 in praktijk regelmatig optreedt. Reden: scope-set kan op resource-combinaties zitten die Hub niet zonder Mollie-docs-duplicatie kan voorspellen; éénvoud > marginale UX-winst in v0.2.

### Refresh-lock

- **D-12: Refresh-lock per-Connection (Phase 4 D-05) blijft uit 5a.** `HubMollieCredentialResolver::resolve()` doet lazy refresh zonder Redis-lock. Lock landt op het moment dat een meetbaar concurrency-issue optreedt — waarschijnlijk Phase 7 (account-subs jobs paralleliseren binnen één Account). v0.2-schaal heeft geen concurrent calls per minuut per Connection.

### Mollie-error envelope

- **D-13: `App\Support\Mollie\MollieUpstreamErrorMapper` — mirror van Snelstart-mapper, eigen mapping-tabel** (de provider-agnostische ADR `mollie-passthrough-api.md`-tabel is leidend):

  | SDK-exception (`Emeq\MollieApi\Exceptions\*`) | Hub-status | `error`-code | `upstream_error`-audit-code |
  |---|---|---|---|
  | `ValidationException` | 422 | `validation_failed` | `null` (user-input) |
  | `AuthenticationException` | **502** | `mollie_auth_failed` | `mollie_auth` |
  | `NotFoundException` | 404 | `not_found` | `null` |
  | `RateLimitException` | 429 + `Retry-After` | `rate_limited` | `null` |
  | `ServerException` | **502** | `mollie_unavailable` | `mollie_5xx` |
  | `MollieException` (base/onbekend) | **502** | `mollie_error` | `mollie_unknown` |
  | `FatalRequestException`/timeout (Guzzle) | **504** | `mollie_timeout` | `mollie_timeout` |

  Reden 401/403 → 502: identiek aan Snelstart-mapping (info-disclosure-mitigatie — Consumer mag niet kunnen onderscheiden of Hub-PAT faalt vs Mollie-access_token).

### PAT-abilities

- **D-14: Bestaande `TokenAbilities::MOLLIE_READ` + `MOLLIE_WRITE` constants worden gebruikt** (`app/Sanctum/TokenAbilities.php` heeft ze al). Gating-logica in abstract base-controller:

  | Endpoint | Required abilities (any of) |
  |---|---|
  | `GET /v1/mollie/*` | `mollie:read`, `mollie:write`, `*` |
  | `POST/PATCH/DELETE /v1/mollie/*` | `mollie:write`, `*` |
  | `POST /webhooks/mollie/{connection_id}` | (geen — signature is de auth) |

  `SanctumAbilityTest`-placeholder uit Phase 3 (markTestIncomplete voor 5b) krijgt in 5b al een echte test; 5a voegt Mollie-equivalent toe.

### Provisioning (Mollie-side connection-create)

- **D-15: Geen nieuwe Connection-provisioning-endpoint voor Mollie.** Mollie-Connections ontstaan via `POST /v1/oauth/mollie/init` (Phase 4) → callback → `status='active'`. `POST /v1/connections` (uit Phase 5b) blijft Snelstart-only — `StoreConnectionRequest::rules()` heeft `Rule::in(['snelstart'])` op `provider`. Niet aanraken in 5a. Documenteer in OpenAPI dat Mollie-Connection-create via OAuth-flow gaat, niet via deze provisioning-route.

### Claude's Discretion

- Exacte controller-shape per resource (single-action `__invoke` vs resource-controller met `index/show/store`-methods) — kies wat het cleanst is per resource. Payments (create/get/cancel) past bij apart controller-per-resource; PaymentMethods (alleen list) past bij single-action.
- Resource-payload-validation in Form Requests: minimaal verplichte velden + types (amount.value regex, currency ISO-4217, redirectUrl/webhookUrl URL). Niet álle Mollie-velden valideren — Mollie zelf doet de echte validatie. Hub-edge-validatie houdt het Mollie-quota-burn laag bij overduidelijk-foute payloads.
- Test-organisatie: per resource één `tests/Feature/Api/V1/Mollie/<Resource>Test.php` + `MollieWebhookIngressTest.php` + `MolliePassThroughAuditTest.php`. Mock-strategie: `MollieApiClient::fake()` (SDK heeft het) voor de meeste tests; real-Mollie-test-mode-roundtrip alleen voor SC-1 happy-path-bewijs.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Plan-source + scope (autoritief)
- `.planning/ROADMAP.md` §"Phase 5a: Mollie SDK Resources + Webhooks + Pass-through API" (regels 114-132) — goal, 5 success criteria, depends-on chain
- `.planning/REQUIREMENTS.md` — MOLL-03 + MOLL-04 + HUB-03
- `.docs/decisions/mollie-passthrough-api.md` — LOCKED architectuur-baseline (pass-through pattern, error-envelope-tabel, scope-rationale)
- `.docs/decisions/pass-through-calls-table.md` — LOCKED audit-tabel-keuze (`pass_through_calls`, provider-agnostisch)
- `.docs/decisions/upstream-error-mapping.md` — referentie-mapping (Snelstart's pad) — Mollie gebruikt eigen mapper maar zelfde patroon

### Architectuur-invariants
- `.ai/rules/global.md` — tokens encrypted-at-rest, fingerprint-only in logs, OAuth-flows volgen RFC 6749, automatisch refresh vóór 401, multi-tenant scope
- `.ai/rules/engineering.md` — chirurgisch wijzigen, conflicten oppervlakken, lezen vóór schrijven
- `.ai/project` rules — Consumer ↔ Account ↔ Connection chain is strict; geen Hub-modellen in SDK; tokens encrypted
- `CLAUDE.md` — geen verzonnen partner-features (Mollie-docs moeten lokaal staan — zie D-10 / `.docs/partners/mollie/` import)

### Mollie SDK (Phase 2 output — al gepubliceerd)
- `packages/mollie-api/src/Mollie.php` — `client(): MollieApiClient` factory die per-call resolved
- `packages/mollie-api/src/Contracts/MollieCredentialResolver.php` — contract dat `HubMollieCredentialResolver` implementeert
- `packages/mollie-api/src/Exceptions/MollieExceptionMapper.php` — maps `Mollie\Api\Exceptions\*` → `Emeq\MollieApi\Exceptions\*` (gebruikt in `MollieUpstreamErrorMapper`-pad)
- `packages/mollie-api/src/Exceptions/{ValidationException,AuthenticationException,NotFoundException,RateLimitException,ServerException,MollieException}.php` — exception-hiërarchie die de Hub-mapper match
- `packages/mollie-api/src/Webhooks/MollieWebhookSignature.php` — `::verify($request, $signingSecret)` + `::sign()` voor tests (D-08)
- `packages/mollie-api/src/Idempotency/UuidV7IdempotencyKeyGenerator.php` — default-generator, bind via `config('mollie.idempotency.generator')` (D-06)

### Hub-skeleton + Phase 4 output (fundering)
- `app/Mollie/MollieConnectionContext.php` — per-request scoped context (`set/current/has`) — middleware vult deze (D-03)
- `app/Mollie/HubMollieCredentialResolver.php` — lazy refresh `<5min` venster (Phase 4 D-04/D-06)
- `app/OAuth/OAuthFlowRegistry.php` — `for('mollie')` levert `MollieConnectOAuthFlow` voor refresh
- `app/Models/Connection.php` — encrypted-casts + `fingerprint()`-accessor (Mollie-pad: `sha256(access_token)[0..12]`)
- `app/Models/Account.php` — `consumer_id + external_id`-scoped lookup
- `app/Sanctum/TokenAbilities.php` — `MOLLIE_READ` + `MOLLIE_WRITE` constants (D-14)
- `app/Providers/AppServiceProvider.php` — bestaande `MollieCredentialResolver`-binding + `OAuthFlowRegistry`-registratie (niet wijzigen — alleen `services.mollie.webhook_secret` toevoegen)
- `routes/api.php` — uitbreiden met `/v1/mollie/*`-blok
- `bootstrap/app.php` — `apiPrefix='v1'` (niet wijzigen)

### Sibling phase-context (referentie-pattern)
- `.planning/phases/05b-snelstart-pass-through-api/05b-CONTEXT.md` — sibling pass-through-pattern (gedeeltelijk hergebruikbaar)
- `.planning/phases/05b-snelstart-pass-through-api/05b-REVIEW.md` — **MUST READ:** drie BLOCKER-findings die 5a vanaf dag 1 wil vermijden (D-05 fixes)
- `app/Http/Middleware/ResolveSnelstartAccount.php` — middleware-shape die `ResolveMollieAccount` mirror't (D-03 — let op: niet rebind van resolver-interface zoals Snelstart, maar `set()` op de context)
- `app/Support/Snelstart/UpstreamErrorMapper.php` — pattern-template voor `MollieUpstreamErrorMapper` (D-13)
- `app/Support/Snelstart/HeaderForwarder.php` — pattern voor `MollieHeaderForwarder` (Mollie heeft géén If-Match-pad — beperkter whitelist)
- `app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php` — referentie maar **niet kopiëren** (REVIEW-blockers)
- `database/migrations/2026_05_15_000001_create_pass_through_calls_table.php` + `..._000002_add_query_keys_to_pass_through_calls_table.php` — bestaande schema, geen wijziging
- `app/Models/PassThroughCall.php` — model dat `provider='mollie'`-rijen ook accepteert

### Mollie-docs (partner-API — moet lokaal staan vóór planning)
- `.docs/partners/mollie/` — **momenteel alleen README-stub.** D-10 vereist research-import vóór `/gsd-plan-phase 5a` voor:
  - https://docs.mollie.com/reference/payments-api (create, get, cancel)
  - https://docs.mollie.com/reference/customers-api (list, get, create)
  - https://docs.mollie.com/reference/payment-methods-api (list)
  - https://docs.mollie.com/reference/refunds-api (create, get, list-per-payment)
  - https://docs.mollie.com/reference/mandates-api (list, get, revoke)
  - https://docs.mollie.com/reference/subscriptions-api (create, get, cancel, list)
  - https://docs.mollie.com/reference/payment-links-api (create, get, list)
  - https://docs.mollie.com/reference/webhooks-overview + signature-verifie-pagina
  - https://docs.mollie.com/reference/api-idempotency
  - https://docs.mollie.com/reference/errors (foutcode-tabel)
  - https://docs.mollie.com/oauth/overview (Connect partner-scope details)

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets

- **`MollieConnectionContext`** (`app/Mollie/MollieConnectionContext.php`): scoped singleton, `set()` per request via middleware, `current()` via resolver. Werkpad voor multi-tenant Mollie-call zonder de `MollieCredentialResolver`-binding te overschrijven (= cleaner dan Snelstart's `app()->instance()`-pad).
- **`HubMollieCredentialResolver`** (`app/Mollie/HubMollieCredentialResolver.php`): doet al de lazy refresh (5-min window). 5a hoeft hier niets aan te wijzigen — alleen erop bouwen.
- **SDK `MollieExceptionMapper`**: pakt Mollie's exception-hiërarchie en mapt naar Emeq-types. Hub-side `MollieUpstreamErrorMapper` consumeert die output — geen dubbel match-statement nodig op `Mollie\Api\Exceptions\*`.
- **SDK `MollieWebhookSignature::verify($request, $secret)`**: Laravel-Request-aware, bool-return. Klaar voor de webhook-controller.
- **SDK `UuidV7IdempotencyKeyGenerator`**: gewoon binden via `config('mollie.idempotency.generator')`. Geen Hub-implementatie nodig.
- **`pass_through_calls`-tabel + `PassThroughCall`-model**: provider-agnostisch, klaar voor `provider='mollie'`-rijen. `query_keys`-kolom uit 5b's fix-pass al aanwezig.
- **Spatie `webhook_calls`-tabel** (Spatie's standaard schema, Phase 0 migration): voor inkomende Mollie-webhook-payloads + outgoing fan-out. Géén custom kolommen — Spatie's `webhook-server` schrijft direct.

### Established Patterns

- **Form Requests in `app/Http/Requests/Api/V1/`** (5b-pad: `StoreAccountRequest`, `StoreConnectionRequest`) — folder-by-version, één request per write-actie.
- **Resources in `app/Http/Resources/Api/V1/`** — voor JSON-shaping. Mollie's payload-shape blijft pass-through (`->raw()` of `->toArray()`), dus 5a heeft minder Resources dan 5b.
- **Tests in `tests/Feature/Api/V1/<Provider>/`** sub-namespace (5b-conventie).
- **`MollieApiClient::fake()`** uit Mollie's lib voor de meeste feature-tests — set fake-responses, assert outgoing requests (zelfde patroon als SDK's PackageSmokeTest).
- **`Tests\Concerns\PrimesSnelstartTokenCache`**-stijl test-traits — Mollie-equivalent niet nodig (geen subscription-key-cache zoals Snelstart), maar `Tests\Concerns\BindsMollieConnectionContext` of equivalent helper kan handig zijn.

### Integration Points

- `routes/api.php` — `Route::prefix('mollie')->middleware(['resolve.mollie.account'])->group(...)` blok toevoegen onder de `Route::middleware('auth:sanctum')->group(...)` (zelfde Sanctum-laag als Snelstart-passthrough).
- `routes/webhooks.php` — **nieuwe file**, registreren in `bootstrap/app.php`'s `withRouting(api: ...)` of via `Route::middleware('api')->group(base_path('routes/webhooks.php'))`. `POST /webhooks/mollie/{connection_id}` zonder auth, met signature-verificatie als de echte gate.
- `config/services.php` — nieuwe key `mollie.webhook_secret` (env `MOLLIE_WEBHOOK_SECRET`) + `mollie.idempotency_generator` (env optioneel, default `UuidV7IdempotencyKeyGenerator::class`).
- `app/Providers/AppServiceProvider.php` — voeg `config('mollie.idempotency.generator')`-binding toe in `register()`. Geen wijziging aan `MollieCredentialResolver`-binding.
- `app/Http/Middleware/ResolveMollieAccount.php` — **nieuwe middleware**, registreer alias in `bootstrap/app.php` (waar `resolve.snelstart.account` al staat).
- `database/migrations/<datum>_add_webhook_callback_to_consumers_table.php` — **nieuwe migration**, voegt `webhook_callback_url` (string nullable) + `webhook_callback_secret` (encrypted string nullable) toe aan `consumers`. Forward-only.

</code_context>

<specifics>
## Specific Ideas

- **Resource-routes (sketch — definitieve mapping bij planning):**
  ```
  GET    /v1/mollie/payments/{id}
  POST   /v1/mollie/payments
  DELETE /v1/mollie/payments/{id}                  (Mollie: cancel)
  POST   /v1/mollie/payments/{id}/refunds          (refund-on-payment)
  GET    /v1/mollie/payments/{id}/refunds          (list-refunds-per-payment)
  GET    /v1/mollie/customers
  GET    /v1/mollie/customers/{id}
  POST   /v1/mollie/customers
  GET    /v1/mollie/customers/{id}/mandates
  GET    /v1/mollie/customers/{id}/mandates/{mandate_id}
  DELETE /v1/mollie/customers/{id}/mandates/{mandate_id}
  GET    /v1/mollie/customers/{id}/subscriptions
  POST   /v1/mollie/customers/{id}/subscriptions
  GET    /v1/mollie/customers/{id}/subscriptions/{sub_id}
  DELETE /v1/mollie/customers/{id}/subscriptions/{sub_id}
  GET    /v1/mollie/payment-methods
  GET    /v1/mollie/refunds/{id}
  GET    /v1/mollie/payment-links
  POST   /v1/mollie/payment-links
  GET    /v1/mollie/payment-links/{id}
  ```
  Sluit aan bij Mollie's eigen URL-pad-structuur (Refunds + Mandates + Subscriptions nested onder Customer/Payment). Validatie van exacte paden bij planning tegen Mollie-docs-import.

- **`webhookUrl`-injectie in `POST /v1/mollie/payments`:** Hub mag (a) Consumer's `webhookUrl` doorgeven verbatim als die in de payload zit, of (b) standaard de Hub-URL `https://hub.emeq.test/webhooks/mollie/{connection_id}` invullen als Consumer 'm leeg laat — beslissing bij planning. Voorkeur: optie (b) zodat fan-out automatisch werkt zonder dat Consumer Hub-URLs hoeft te kennen.

- **SC-1 happy-path-bewijs:** `POST /v1/mollie/payments` met realistische payload (`amount: {currency: 'EUR', value: '12.34'}, description: 'Test', redirectUrl: '...'`) tegen Mollie's test-mode access_token → assert response heeft `_links.checkout.href` (de checkout-URL) en `status === 'open'`. Test-mode access_token kan in `.env.testing` of via `Mollie::client()->setAccessToken('access_test_...')` in test-bootstrap.

- **SC-2 OpenAPI-bewijs:** Scramble route-discovery test (5b heeft `ScrambleRouteDiscoveryTest`) uitbreiden — assert dat alle 7 resource-paths in `/docs/api` voorkomen, allen onder de `mollie:read|write` security-scope.

- **SC-3 tampered signature-test:** `MollieWebhookSignature::sign('payload', 'wrong_secret')` → POST naar `/webhooks/mollie/{id}` → assert 400 + audit-rij in `webhook_calls` met exception-veld gevuld, en geen outgoing fan-out-job dispatched.

- **SC-5 idempotency-test:** twee `POST /v1/mollie/payments` met dezelfde `Idempotency-Key`-header tegen `MollieApiClient::fake()` die op `Idempotency-Key`-header matched en dezelfde response retourneert. Assert dat de Hub maar één Mollie-call uitvoert (via `MollieApiClient::fake()->assertSent`-teller).

- **Folder-conventie:** controllers `app/Http/Controllers/Api/V1/Mollie/`, form-requests `app/Http/Requests/Api/V1/Mollie/`, support `app/Support/Mollie/`. Mirror van `Snelstart/`-trio.

</specifics>

<deferred>
## Noted for Later

- **`MOLL-CONNECT-RES` backlog promotion** — Mollie-Connect-partner-resources (Onboarding-status / Organizations / Profiles / Permissions / ClientLinks). Promoten wanneer een host-app productie-go-live een merchant via Hub moet onboarden.
- **Refresh-lock per-Connection** (Phase 4 D-05 Redis `Cache::lock`) — implementeren wanneer concurrency-incident optreedt of Phase 7 jobs paralleliseren binnen één Connection.
- **Per-Connection webhook-callback-URL override** — als Consumer-niveau-URL te grof blijkt. v0.2 = één URL per Consumer.
- **Scope-hint-response** (Phase 4 D-11) — bij 403 van Mollie de missende scope teruggeven (`"je connection mist scope X — re-koppel"`). Parkeren tot productie-friction.
- **Hub-side OpenAPI-edge-validatie via Scramble's response-schema** — vandaag valideren we requests via Form Requests, maar responses worden niet tegen Mollie's schema gecheckt. Zou bij Mollie-API-changes vroege fout-detectie geven. Backlog.
- **`pass_through_calls` retention/partitioning** — zelfde overweging als 5b. Data-volume eerst meten.
- **Per-Account rate-limit** — huidige throttle is per-Consumer (60/min). Phase 5b heeft 'm ook geparkeerd; consistent gedeferd.
- **`MOLL-CASHIER`-compat-check** (uit STATE.md SUB-01) — Phase 6 concern, niet 5a.
- **Cron-based pre-emptive refresh** (Phase 4 D-04 alternatief) — pure lazy blijft default; pas heroverwegen als productie laat zien dat de eerste-call-latency een UX-issue is.
- **Mollie's Settlements/Chargebacks/Invoices/Onboarding/Profiles-resources** — REQUIREMENTS.md zegt al expliciet "Out of Scope voor v0.2". Bevestigd; geen actie.

</deferred>

---

*Phase: 05a-mollie-sdk-resources-webhooks-pass-through-api*
*Context gathered: 2026-05-14 — autonomous discuss-phase pass (no clarifying-questions per user directive)*
