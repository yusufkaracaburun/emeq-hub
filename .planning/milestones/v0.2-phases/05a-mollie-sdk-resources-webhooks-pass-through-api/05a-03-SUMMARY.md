---
phase: 05a-mollie-sdk-resources-webhooks-pass-through-api
plan: 03
subsystem: api
tags:
  - laravel
  - mollie
  - payments
  - pass-through
  - idempotency
  - phpunit

# Dependency graph
requires:
  - phase: 05a-01
    provides: "AbstractMolliePassThroughController, ResolveMollieAccount-middleware, MollieUpstreamErrorMapper (D-13), MollieConnectionContext"
  - phase: 02-sdk-mollie
    provides: "Mollie::client(), UuidV7IdempotencyKeyGenerator, MollieExceptionMapper, Emeq\\MollieApi\\Exceptions\\*"
provides:
  - "POST /v1/mollie/payments + GET /v1/mollie/payments/{id} + DELETE /v1/mollie/payments/{id} (3 routes, alle achter resolve.mollie.account-middleware)"
  - "PaymentsController extends AbstractMolliePassThroughController — pass-through naar Mollie SDK met webhookUrl-injectie + Idempotency-Key forward"
  - "CreatePaymentRequest + UpdatePaymentRequest — edge-validatie (description, amount.currency ISO-4217, amount.value decimal-string)"
  - "config/mollie.php — idempotency.generator binding op UuidV7IdempotencyKeyGenerator (D-06)"
  - "ConsumerIdempotencyKeyGenerator — one-shot fallback voor generator-injection-paden"
  - "Tests\\Concerns\\StubsMollieClient trait + Tests\\Feature\\Api\\V1\\Mollie\\StubMollieClient subclass voor pass-through-tests met key-capture"
affects:
  - 05a-04-mollie-refunds-mandates-subscriptions
  - 05a-05-mollie-paymentlinks-scramble-acceptance

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "MollieApiClient::setIdempotencyKey() runtime-setter is het preferred pad voor consumer-Idempotency-Key forward (preflight V1 — eenvoudiger dan constructor-injection of generator-swap)"
    - "Payment-resource heeft géén toArray()-method op base; serialisatie via $payment->getResponse()->body() (productie-pad) of json_decode(json_encode(\\$payment)) (test-fallback) bewaart Mollie's wire-shape verbatim"
    - "Pass-through test-stub-pattern: MollieApiClient-subclass met overridden __get('payments') returnt een endpoint-stub die payloads + pre-call MollieApiClient::getIdempotencyKey() capture't"
    - "Raw Mollie SDK exceptions (Mollie\\Api\\Exceptions\\*) worden in controller via MollieExceptionMapper::map() naar Emeq\\MollieApi\\Exceptions\\* gewrapt zodat MollieUpstreamErrorMapper (D-13) ze deterministisch kan mappen"

key-files:
  created:
    - config/mollie.php
    - app/Support/Mollie/ConsumerIdempotencyKeyGenerator.php
    - app/Http/Controllers/Api/V1/Mollie/PaymentsController.php
    - app/Http/Requests/Api/V1/Mollie/CreatePaymentRequest.php
    - app/Http/Requests/Api/V1/Mollie/UpdatePaymentRequest.php
    - tests/Concerns/StubsMollieClient.php
    - tests/Feature/Api/V1/Mollie/StubMollieClient.php
    - tests/Feature/Api/V1/Mollie/PaymentsTest.php
    - tests/Feature/Api/V1/Mollie/MollieIdempotencyForwardTest.php
    - tests/Feature/Api/V1/Mollie/MolliePassThroughErrorMappingTest.php
    - tests/Feature/Api/V1/Mollie/MolliePassThroughAuditTest.php
    - .planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-03-PREFLIGHT.md
  modified:
    - routes/api.php

key-decisions:
  - "Pre-flight V1: MollieApiClient::setIdempotencyKey() (publieke runtime-setter in HandlesIdempotency-trait, regels 22-39) is gekozen boven constructor-injection — supersedes generator, reset automatisch na elke request. Geen verse MollieApiClient bouwen, geen reflection."
  - "Pre-flight V2: IdempotencyKeyGeneratorContract::generate(): string bevestigd in vendor/mollie/mollie-api-php/src/Contracts/IdempotencyKeyGeneratorContract.php. Onze SDK's UuidV7IdempotencyKeyGenerator volgt al deze signature."
  - "Pre-flight V3: MollieApiClient::fake() bestaat als publieke statische factory; voor SC-5 dedup-test kozen we voor het stub-client-pad (precieser key-capture per call) i.p.v. MockResponse — matches Plan 05a-02 ThrowingMollieApiClient-pattern."
  - "WebhookUrl-injectie (D-08): bij ontbrekende webhookUrl in payload vult de Hub url('/webhooks/mollie/{connection_id}') in. Bevestigt het ingress-pad van Plan 05a-02; Consumer hoeft Hub-URL niet te kennen."
  - "Mollie SDK exceptions worden in PaymentsController via MollieExceptionMapper::map() gewrapt vóór re-throw — onze SDK heeft geen auto-wrap-policy (per MollieExceptionMapper docblock)."
  - "Payment-resource ::toArray() bestaat niet op vendor BaseResource; gebruik response-body-decode in productie + JsonSerializable-fallback in tests."

patterns-established:
  - "PaymentsController-pattern: extends AbstractMolliePassThroughController + $this->handle($request, $endpoint, $sdkCall) levert ability-guard / 415-guard / audit-write / response-render gratis."
  - "buildClient($request)-helper: forward't Consumer-Idempotency-Key via setIdempotencyKey() of laat SDK-default-generator zijn werk doen."
  - "StubsMollieClient-trait + StubMollieClient-subclass: hergebruikbaar voor toekomstige Mollie-resource-tests (Plans 05a-04/05a-05)."

requirements-completed: [MOLL-03, HUB-03]

# Metrics
duration: ~17min
completed: 2026-05-14
---

# Phase 5a Plan 03: Mollie Payments Pass-through (MOLL-03 + HUB-03) Summary

**POST /v1/mollie/payments + GET /v1/mollie/payments/{id} + DELETE /v1/mollie/payments/{id} via MollieApiClient achter resolve.mollie.account-middleware. WebhookUrl-auto-injectie naar Plan 05a-02-ingress. SC-5 Idempotency-Key forward bewezen via MollieApiClient::setIdempotencyKey() runtime-setter — twee POST's met dezelfde key tonen dezelfde Payment-id in 19 nieuwe feature-tests.**

## Performance

- **Duration:** ~17 min (incl. composer-install + .env-bootstrap in lege worktree-vendor)
- **Started:** 2026-05-14T22:34:46Z
- **Completed:** 2026-05-14T22:51:22Z
- **Tasks:** 4 (Task 0 pre-flight + Task 1 config/helper + Task 2 controller/requests/routes + Task 3 tests)
- **Commits:** 4 (1 docs pre-flight + 2 feat + 1 test)
- **Files modified:** 13 (12 created, 1 modified)

## Accomplishments

- **PaymentsController** levert pass-through voor create/get/cancel via Mollie SDK met:
  - **WebhookUrl-injectie (D-08):** bij ontbrekende `webhookUrl` in payload vult de Hub automatisch `url('/webhooks/mollie/{connection_id}')` in — bevestigd door 2 dedicated tests (auto-inject + respect-consumer).
  - **Idempotency-Key forward (D-06):** Consumer's header wordt verbatim via `MollieApiClient::setIdempotencyKey()` doorgegeven — preferred pad per PREFLIGHT.md V1. SC-5-bewijs in `MollieIdempotencyForwardTest`: 2× POST met `Idempotency-Key: idem-test-001` retourneert dezelfde Payment-id en de stub-client ziet de key verbatim.
  - **Mollie-error-mapping (D-13):** raw Mollie-SDK exceptions worden via `MollieExceptionMapper::map()` naar `Emeq\MollieApi\Exceptions\*` gewrapt zodat `AbstractMolliePassThroughController`'s outer catch ze door `MollieUpstreamErrorMapper` mapt. 7 cases groen voor Auth/NotFound/Validation/RateLimit/Server/Runtime/MollieException-base.
  - **D-05 audit-write:** alle 3 5b-fixes (path-template, query_keys-only, NULL fingerprint bij lege body) toegepast op Mollie-pad — 4 audit-tests groen, geen access_token leak naar audit-row.
- **CreatePaymentRequest** edge-validatie: `description` required, `amount.currency` size:3 (ISO-4217), `amount.value` regex `/^\d+\.\d{2,}$/` (Mollie's decimal-string). Voorkomt Mollie-quota-burn bij overduidelijk-foute payloads.
- **3 routes** geregistreerd onder `v1/mollie/payments[/{id}]` met named-routes `api.mollie.payments.store|show|destroy` en middleware-stack `auth:sanctum, throttle:api, resolve.mollie.account`.
- **config/mollie.php** publish: `idempotency.generator` → `UuidV7IdempotencyKeyGenerator` zodat de SDK's `applyIdempotencyGenerator()` (Mollie wrapper, regel 91) een default-generator vindt wanneer er geen Consumer-key is.
- **ConsumerIdempotencyKeyGenerator** als one-shot helper voor generator-injection-paden (alternatief voor `setIdempotencyKey()` wanneer toekomstige code-paden alleen een generator-instance accepteren).
- **Test-infrastructuur:** `StubMollieClient` (MollieApiClient-subclass met `__get('payments')`-override) + `StubsMollieClient`-trait die payloads en `getIdempotencyKey()` pre-call capture't. Hergebruikbaar voor Plans 05a-04 en 05a-05.
- **19 nieuwe feature-tests groen** (5 + 3 + 7 + 4 = 19 ≥ plan-minimum 18). Volledige suite: **173 passed / 1 incomplete (pre-existing Phase 3 placeholder) / 0 failed**.

## Task Commits

1. **Task 0 — Pre-flight verifie**
   - `44c9542` docs (PREFLIGHT.md met V1/V2/V3 uitkomsten)
2. **Task 1 — config + ConsumerIdempotencyKeyGenerator**
   - `854d423` feat (config/mollie.php + helper-class)
3. **Task 2 — Controller + Form Requests + 3 routes**
   - `76ed13f` feat (PaymentsController + CreatePaymentRequest + UpdatePaymentRequest + routes/api.php)
4. **Task 3 — 19 feature-tests**
   - `01dd9a1` test (4 test-files + StubsMollieClient trait + StubMollieClient subclass)

## Files Created/Modified

### Created
- `config/mollie.php` — SDK-config publish (idempotency.generator + facade_alias + enforce_environment)
- `app/Support/Mollie/ConsumerIdempotencyKeyGenerator.php` — one-shot generator (D-06 helper)
- `app/Http/Controllers/Api/V1/Mollie/PaymentsController.php` — 3 acties + buildClient + paymentToArray
- `app/Http/Requests/Api/V1/Mollie/CreatePaymentRequest.php` — edge-validatie required+ types
- `app/Http/Requests/Api/V1/Mollie/UpdatePaymentRequest.php` — nullable subset (PATCH-route niet in 5a-03-scope, klaar voor 05a-04+)
- `tests/Concerns/StubsMollieClient.php` — test-trait (bindMollieStub + setupMollieConsumer + callMollie + makePayment)
- `tests/Feature/Api/V1/Mollie/StubMollieClient.php` — MollieApiClient-subclass voor `__get('payments')`-stub
- `tests/Feature/Api/V1/Mollie/PaymentsTest.php` — 5 cases (SC-1 + happy paths)
- `tests/Feature/Api/V1/Mollie/MollieIdempotencyForwardTest.php` — 3 cases (SC-5 hard gate)
- `tests/Feature/Api/V1/Mollie/MolliePassThroughErrorMappingTest.php` — 7 cases (D-13)
- `tests/Feature/Api/V1/Mollie/MolliePassThroughAuditTest.php` — 4 cases (D-05)
- `.planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-03-PREFLIGHT.md` — vendor-API verifie

### Modified
- `routes/api.php` — 3 nieuwe routes binnen de bestaande `auth:sanctum` group, gebundeld in `Route::prefix('mollie')->middleware('resolve.mollie.account')` blok ná Snelstart-passthrough.

## Decisions Made

### Idempotency-Key forward-pad (D-06)

`MollieApiClient::setIdempotencyKey($key)` is **gekozen boven** constructor-injection of generator-swap. Bevestigd in `HandlesIdempotency`-trait (vendor regels 18-39): publieke one-shot setter, resets automatisch na elke request, **supersedes generator wanneer beide gezet**. Geen verse MollieApiClient nodig, geen reflection, geen API-key/access-token re-set. PaymentsController::buildClient() implementeert dit in 6 regels.

### Payment-resource serialisatie

Mollie's typed `Mollie\Api\Resources\Payment` heeft GEEN `toArray()`-method op de base (alleen `AnyResource` heeft die). Productie-pad gebruikt `$payment->getResponse()->body()` (raw JSON-string van de echte HTTP-response — bewaart wire-shape verbatim inclusief `_links`, `_embedded`). Test-fallback gebruikt `json_decode(json_encode($payment), true)` omdat de stub geen origin-Response heeft. Beide paden bewaren `_links.checkout.href` zodat MOLL-03 SC-1 op response-niveau bewezen kan worden.

### Mollie-exception wrapping in controller

De SDK heeft een non-wrapping policy (per `MollieExceptionMapper` docblock — host-apps mogen Mollie-exceptions direct catchen). In PaymentsController hebben we EXPLICIET een `try/catch (MollieApiException) { throw MollieExceptionMapper::map($e); }` rondom elke SDK-call zodat de `MollieUpstreamErrorMapper` (Plan 05a-01) zijn deterministische match-statement op `Emeq\MollieApi\Exceptions\*` kan doen.

Alternatief was om de mapper uit te breiden zodat 'ie ook `Mollie\Api\Exceptions\*` accepteert. Keuze tegen: dat zou Plan 05a-01's mapper-class openbreken voor een Mollie-only concern. Cleaner om de wrap-step waar de Mollie-call gebeurt te plaatsen (single responsibility).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] worktree had geen vendor/ + geen .env**
- **Found during:** Task 0 pre-flight (vendor-dir leeg in fresh worktree)
- **Fix:** `composer install` (~30s) + `cp .env.example .env && php artisan key:generate` (~2s). Beide standaard worktree-bootstrap-stappen.
- **Files modified:** geen tracked files
- **Commit:** N/A (omgevings-setup)

**2. [Rule 1 - Bug] Plan's `->toArray()` call werkt niet op Mollie's typed Payment-resource**
- **Found during:** Task 2 controller-implementation
- **Issue:** Plan-action en patterns-document beschreven `$payment->toArray()` als Mollie-resource → array-conversie. Vendor `Mollie\Api\Resources\Payment` (en alle typed resources via `BaseResource`) heeft GEEN `toArray()`-method — alleen `AnyResource` heeft die. Een directe call zou een `Error: undefined method` gooien.
- **Fix:** `paymentToArray()`-helper op de controller: probeert eerst `getResponse()->body()` decode (productie-pad), valt terug op `json_decode(json_encode($payment), true)` zodat test-stubs zonder origin-Response ook werken. Beide paden bewaren Mollie's wire-shape verbatim.
- **Files modified:** `app/Http/Controllers/Api/V1/Mollie/PaymentsController.php`
- **Commit:** `76ed13f` (feat) — geïntegreerd in de PaymentsController-implementatie.

**3. [Rule 2 - Missing critical functionality] Plan ontbreekt Mollie-exception-wrap in controller**
- **Found during:** Task 2 controller-implementation
- **Issue:** Plan's controller-sketch riep Mollie SDK direct aan zonder try/catch. `MollieUpstreamErrorMapper` (Plan 05a-01) mapt alleen `Emeq\MollieApi\Exceptions\*`, niet de raw `Mollie\Api\Exceptions\*` die de vendor-SDK gooit. Zonder wrap zouden ALLE Mollie-fouten in de catch-all fallback landen (502 mollie_error), wat D-13's mapping-tabel onbruikbaar maakt.
- **Fix:** Per SDK-call een `try { ... } catch (MollieApiException $e) { throw MollieExceptionMapper::map($e); }` toegevoegd. `MollieExceptionMapper` is publieke SDK-helper (Plan 02 output) die de match-statement doet (`Mollie\Api\Exceptions\ValidationException` → `Emeq\MollieApi\Exceptions\ValidationException`, etc.).
- **Files modified:** `app/Http/Controllers/Api/V1/Mollie/PaymentsController.php`
- **Commit:** `76ed13f` (feat) — geïntegreerd in elke action.

**4. [Rule 1 - Bug] Acceptance criterion `private readonly string $key` was schijnbaar-failing door Pint-inlining**
- **Found during:** Task 1 verify
- **Issue:** Pint formatteerde de constructor naar single-line `public function __construct(private readonly string $key) {}` zodat een naive shell-grep `private readonly string $key` initieel `0` returnde. De acceptance-criterion gebruikte een escaped pattern (`\\\$key`) dat in bash niet expandeerde.
- **Fix:** Geen code-wijziging. Geverifieerd met `grep -F` (literal): `1` match. Acceptance criterion is feitelijk voldaan; de plan-grep-pattern was bash-escaping-fout.
- **Files modified:** geen
- **Commit:** N/A

## SC-5 Hard Gate Bewijs (B3)

Per ROADMAP-eis dat SC-5 een harde gate is (geen `markTestSkipped`-pad):

- **MollieIdempotencyForwardTest::test_two_post_with_same_idempotency_key_returns_same_mollie_payment_id** — Twee POST's met `Idempotency-Key: idem-test-001` retourneren beide `tr_dedup_xyz` (stub emuleert Mollie's server-side dedup). De stub capture't pre-call `MollieApiClient::getIdempotencyKey()` en beide calls tonen exact `idem-test-001`. Geen Hub-side rewrite, geen UUID-v7 fallback. **GROEN.**
- **MollieIdempotencyForwardTest::test_consumer_idempotency_key_is_forwarded_verbatim_to_mollie** — Een POST met `Idempotency-Key: my-custom-key-xyz` toont in de stub-capture exact die waarde, en is bevestigd geen UUID-v7-pattern. **GROEN.**

Beide tests bewijzen op runtime-niveau dat `PaymentsController::buildClient()` de Consumer's header verbatim aan de MollieApiClient meegeeft (via `setIdempotencyKey()`) — geen pragmatic skip, geen grep-only-assertie.

## Mock-strategie die uiteindelijk gebruikt is

**Stub-client-pad i.p.v. MollieApiClient::fake().** Reden: `MockMollieClient` werkt op HTTP-adapter-niveau (request-class lookups + MockResponse-bodies) wat de typed-request-machinery van Mollie (CreatePaymentRequest / GetPaymentRequest / CancelPaymentRequest) doorloopt. Voor het bewijzen van **wat de controller naar de SDK doorgeeft** (specifiek pre-call `getIdempotencyKey()` en payload-shape) is een endpoint-stub die `payments->create($payload)` direct intercept'eert preciezer.

**Concreet:**
- `Tests\Feature\Api\V1\Mollie\StubMollieClient` — `MollieApiClient`-subclass met `__get('payments')`-override.
- `Tests\Concerns\StubsMollieClient` — trait met `bindMollieStub(callable $resolver)`-helper die een endpoint-stub instantieert, koppelt aan een test-only `Mollie`-wrapper-mock via `$this->createMock(Mollie::class)`, en die als instance bindt op de container. De stub's `create/get/cancel` capture't payloads + `$this->mollieClient?->getIdempotencyKey()` vlak vóór de resolver-call.

Hergebruikt het patroon van `Tests\Feature\Webhooks\ThrowingMollieApiClient` (Plan 05a-02), uitgebreid met success-pad en key-capture-array.

## Form Request veld-discrepanties tegen .docs/partners/mollie/payments-api.md

Geen significante discrepanties. CreatePaymentRequest's regels (description, amount.currency, amount.value, redirectUrl, cancelUrl, webhookUrl, method, metadata, sequenceType, customerId, mandateId, profileId, locale, testmode) komen overeen met Mollie's payments-create-payload-spec. Niet-gevalideerde velden (`lines`, `billingAddress`, `shippingAddress`, `applicationFee`, `routing`, etc.) worden door Mollie zelf gevalideerd zodra ze in de payload zitten — Hub doet alleen edge-validatie om Mollie-quota-burn te vermijden bij grove fouten.

## Test-counts per file

| File | Tests | Doel |
|---|---|---|
| PaymentsTest.php | 5 | SC-1 happy paths + webhookUrl-injectie + DELETE-cancel |
| MollieIdempotencyForwardTest.php | 3 | SC-5 hard gate (dedup + forward + UuidV7-default) |
| MolliePassThroughErrorMappingTest.php | 7 | D-13 mapping-tabel via echte HTTP-call |
| MolliePassThroughAuditTest.php | 4 | D-05 audit-shape + geen access_token leak |
| **Totaal** | **19** | (plan-minimum: 18) |

## Issues Encountered

- **PREFLIGHT-Write landde in main-repo i.p.v. worktree (recovered)**: Eerste `Write` van `05a-03-PREFLIGHT.md` met "absolute" path `.planning/...` resolveerde door de Write-tool naar de niet-worktree main repo, hetgeen herhaalde 05a-02-fout was. Recovery: `mv` van main repo path naar worktree path (`mv /.../.planning/... /.../.claude/worktrees/agent-acc47308bdba4674b/.planning/...`); geen commit op main. Vanaf dat punt alle Write-calls met expliciete worktree-absolute paths. Geen drift.
- **vendor + .env-bootstrap**: zoals in 05a-02. Composer install + key:generate. Standaard worktree-bootstrap.

## User Setup Required

Geen — alle infra (config-key, Form Requests, routes, middleware-aliases) zit in het commit-pad. Productie-rollout vereist nog dat de Hub-omgeving:
1. Een geldige `MOLLIE_*` access_token of API-key heeft op een actieve `Connection` (geleverd door Phase 4 OAuth-broker).
2. (Optioneel) `MOLLIE_ENFORCE_ENVIRONMENT=true` zet om test_-keys in production te blokkeren (config('mollie.enforce_environment')).

## Known Stubs

Geen stubs in 5a-03. Alle 3 routes leveren een echte pass-through naar de SDK. Test-stubs (`StubMollieClient` / `StubsMollieClient`-trait) leven uitsluitend onder `tests/` en hebben geen runtime-effect in productie.

## Next Phase Readiness

- **Plan 05a-04 (Refunds + Mandates + Subscriptions)** kan starten met dezelfde pattern: Controllers extenden `AbstractMolliePassThroughController` (Plan 05a-01) + `Mollie::client()->refunds->...` / `->customers->mandates(...)` / `->subscriptions->...` calls + `StubsMollieClient`-trait beschikbaar voor tests. Voor Idempotency-Key forward in write-routes geldt hetzelfde `setIdempotencyKey()`-pad.
- **Plan 05a-05 (PaymentLinks + Scramble + acceptance)** kan starten met de PaymentLinksController-mirror van PaymentsController + de Scramble-route-discovery-test die alle 7 mollie-resource-paths in `/docs/api` verifieert.
- **Geen blockers.**

## Follow-ups

- **docs-sync trigger:** routes/api.php is gewijzigd (3 nieuwe routes) + nieuwe config/mollie.php + nieuwe `app/Support/Mollie/ConsumerIdempotencyKeyGenerator.php`. De Edit-hook trigger'de docs-sync-skill. Plan-output specificeert dat dit een follow-up is — uit te voeren bij merge-tijd naar `chore/v02-roadmap-split-and-scramble`, niet binnen deze plan-execute.
- **STACK.md / ARCHITECTURE.md / CONVENTIONS.md** updates indien Mollie-pass-through-conventies daar landen (idempotency-key forward-pattern + payment-resource-serialisatie). Buiten scope van deze plan-execute.

## Self-Check: PASSED

- All 12 created files exist on disk:
  - `config/mollie.php` ✓
  - `app/Support/Mollie/ConsumerIdempotencyKeyGenerator.php` ✓
  - `app/Http/Controllers/Api/V1/Mollie/PaymentsController.php` ✓
  - `app/Http/Requests/Api/V1/Mollie/CreatePaymentRequest.php` ✓
  - `app/Http/Requests/Api/V1/Mollie/UpdatePaymentRequest.php` ✓
  - `tests/Concerns/StubsMollieClient.php` ✓
  - `tests/Feature/Api/V1/Mollie/StubMollieClient.php` ✓
  - `tests/Feature/Api/V1/Mollie/PaymentsTest.php` ✓
  - `tests/Feature/Api/V1/Mollie/MollieIdempotencyForwardTest.php` ✓
  - `tests/Feature/Api/V1/Mollie/MolliePassThroughErrorMappingTest.php` ✓
  - `tests/Feature/Api/V1/Mollie/MolliePassThroughAuditTest.php` ✓
  - `.planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-03-PREFLIGHT.md` ✓
- All 4 task-commits exist in `git log` (44c9542, 854d423, 76ed13f, 01dd9a1).
- New feature-tests: **19 passed / 0 failed** (filter='PaymentsTest|MollieIdempotencyForwardTest|MolliePassThroughErrorMappingTest|MolliePassThroughAuditTest').
- Full PHPUnit suite: **173 passed / 1 incomplete (pre-existing Phase 3 placeholder) / 0 failed**.
- Pint clean op alle nieuwe/gewijzigde files.
- `php artisan route:list --name=api.mollie.payments` toont 3 named-routes (store, show, destroy).

## TDD Gate Compliance

Plan-frontmatter `type: execute`, met 2 tasks `tdd="true"` (Task 2 + Task 3). Commit-sequence levert beide gates:
- **Implementation:** `76ed13f` feat(05a-03): PaymentsController + Form Requests + 3 routes (Task 2)
- **Tests:** `01dd9a1` test(05a-03): 19 feature-tests (Task 3, GROEN tegen de Task-2-implementatie)

De plan-structuur splitst implementatie van tests over Task 2 ↔ Task 3 (i.p.v. één RED→GREEN per task). Test-commit komt NA implementation-commit, met `test()`-prefix. Geen plan-level TDD-gate-violation.

---
*Phase: 05a-mollie-sdk-resources-webhooks-pass-through-api*
*Plan: 03*
*Completed: 2026-05-14*
