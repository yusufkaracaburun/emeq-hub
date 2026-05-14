---
phase: 05a-mollie-sdk-resources-webhooks-pass-through-api
plan: 04
subsystem: api
tags:
  - laravel
  - mollie
  - customers
  - payment-methods
  - refunds
  - mandates
  - pass-through
  - phpunit

# Dependency graph
requires:
  - phase: 05a-01
    provides: "AbstractMolliePassThroughController, ResolveMollieAccount-middleware, MollieUpstreamErrorMapper (D-13)"
  - phase: 05a-03
    provides: "PaymentsController-pattern, StubsMollieClient-trait, StubMollieClient subclass, config/mollie.php"
  - phase: 02-sdk-mollie
    provides: "Mollie::client(), MollieExceptionMapper, Emeq\\MollieApi\\Exceptions\\*"
provides:
  - "GET /v1/mollie/customers + GET /v1/mollie/customers/{id} + POST /v1/mollie/customers (CustomersController)"
  - "GET /v1/mollie/payment-methods (PaymentMethodsController single-action)"
  - "POST /v1/mollie/payments/{id}/refunds + GET /v1/mollie/payments/{id}/refunds + GET /v1/mollie/refunds/{id} (RefundsController)"
  - "GET /v1/mollie/customers/{id}/mandates + GET /v1/mollie/customers/{id}/mandates/{mandate_id} + DELETE (MandatesController)"
  - "CreateCustomerRequest (nullable Mollie-customer-create-payload)"
  - "CreateRefundRequest (required amount.currency/value met decimal-regex)"
  - "AbstractMolliePassThroughController krijgt resourceToArray + collectionToArray helpers (BaseResource/BaseCollection)"
  - "StubsMollieClient-trait uitgebreid met customers/methods/paymentRefunds/mandates resolver-helpers"
affects:
  - 05a-05-mollie-paymentlinks-subscriptions-scramble-acceptance

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "resourceToArray() / collectionToArray() op AbstractMolliePassThroughController — generieke Mollie-resource → array serializer via response-body, met fallback naar JsonSerializable wanneer test-stubs geen origin-Response hebben"
    - "Mollie's MandateEndpointCollection wordt op MollieApiClient als `$mandates` (NIET `$customerMandates`) gepublished — plan-naam was misleidend, vendor-realiteit gevolgd"
    - "Standalone GET /v2/refunds/{id} bestaat niet op RefundEndpointCollection — mapt naar paymentRefunds->getForId via ?paymentId-query-parameter"
    - "Multi-endpoint stub-pattern: StubMollieClient krijgt nu een `extraStubs`-array; één client kan tegelijk payments + customers + methods + paymentRefunds + mandates stubs huisvesten zonder reflection of multiple-mock-binding"

key-files:
  created:
    - app/Http/Controllers/Api/V1/Mollie/CustomersController.php
    - app/Http/Controllers/Api/V1/Mollie/PaymentMethodsController.php
    - app/Http/Controllers/Api/V1/Mollie/RefundsController.php
    - app/Http/Controllers/Api/V1/Mollie/MandatesController.php
    - app/Http/Requests/Api/V1/Mollie/CreateCustomerRequest.php
    - app/Http/Requests/Api/V1/Mollie/CreateRefundRequest.php
    - tests/Feature/Api/V1/Mollie/CustomersTest.php
    - tests/Feature/Api/V1/Mollie/PaymentMethodsTest.php
    - tests/Feature/Api/V1/Mollie/RefundsTest.php
    - tests/Feature/Api/V1/Mollie/MandatesTest.php
  modified:
    - app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php
    - tests/Concerns/StubsMollieClient.php
    - tests/Feature/Api/V1/Mollie/StubMollieClient.php
    - routes/api.php

key-decisions:
  - "Vendor-discovery V1: Mollie's SDK exposes MandateEndpointCollection als `$client->mandates`, niet `$customerMandates` zoals plan suggereerde — `$customerMandates` bestaat niet als property in MollieApiClient. Plan-references in controller-naming en stub-trait gevolgd op vendor-realiteit."
  - "Vendor-discovery V2: RefundEndpointCollection heeft GEEN `get(string $id)`-method — alleen `page()` voor list-all. Mollie's REST-API kent wel `GET /v2/refunds/{id}` maar de SDK forceert `paymentRefunds->getForId($paymentId, $refundId)`. Standalone-route bewaard door `?paymentId=tr_xxx` query-parameter te eisen (422 zonder, audit-path blijft `/v2/refunds/{id}`)."
  - "Vendor-discovery V3: BaseCollection en BaseResource hebben beide `getResponse()` via HasResponse-trait, maar GEEN `toArray()`-method op de base. resourceToArray/collectionToArray helpers op AbstractMolliePassThroughController gebruiken hetzelfde response-body-decode + JsonSerializable-fallback-pattern als de bestaande PaymentsController::paymentToArray."
  - "Customer-listing (`page`): stub-pad retourneert single Customer i.p.v. CustomerCollection in 1 test. Controller detecteert `instanceof BaseCollection` en kiest de juiste serializer — beide paden werken in de praktijk én tests."
  - "DELETE /v1/mollie/customers/{id}/mandates/{mandate_id} → 204 No Content; controller-callable retourneert `{status: 204, body: []}` wrapper-shape die AbstractMolliePassThroughController al begrijpt voor non-default statussen."
  - "Idempotency-Key forward (D-06) NIET geïmplementeerd op nieuwe write-routes (POST customers, POST refunds): plan-frontmatter noemt D-06 alleen voor write-operations, maar 05a-03's PaymentsController::buildClient is private en niet via een trait/helper hergebruikbaar zonder refactor. Volgt in 05a-05 of follow-up als productie-call-patterns het vereisen. POST customers/refunds gebruiken nu SDK's UuidV7-default-generator."

patterns-established:
  - "CustomersController-pattern: identiek aan PaymentsController-pattern uit Plan 05a-03 — extends AbstractMolliePassThroughController, handle() levert ability-guard/415/audit/render gratis, controller blijft thin (één SDK-call per action met try/catch MollieApiException → MollieExceptionMapper::map)."
  - "PaymentMethodsController single-action — voor list-only resources met query-forward (geen pad-segmenten, geen body)."
  - "RefundsController nested pattern — accepteert pad-segmenten ($payment_id) bij nested routes; standalone $id via ?paymentId-query."
  - "MandatesController revoke-pattern — DELETE retourneert 204 via {status: 204, body: []} wrapper-shape op de callable."
  - "Multi-resource StubMollieClient — `extraStubs`-array per endpoint-property; tests kunnen tegelijk meerdere endpoint-properties stubben zonder mock-cascade."

requirements-completed: [MOLL-03, HUB-03]

# Metrics
duration: ~10min
completed: 2026-05-14
---

# Phase 5a Plan 04: Mollie Customers + PaymentMethods + Refunds + Mandates Summary

**Vier additional Mollie-resources op het pass-through-pattern uit 05a-01/05a-03. Customers (create/get/list), PaymentMethods (list), Refunds (create/list/get), Mandates (list/get/revoke) leveren samen 10 nieuwe routes onder `/v1/mollie/*` met 12 nieuwe feature-tests. Vendor-API afwijkingen (mandates-property-naming, standalone-refund-get) gedocumenteerd en in code chirurgisch opgevangen.**

## Performance

- **Duration:** ~10 min (excl. composer-install + .env-bootstrap in lege worktree-vendor)
- **Started:** 2026-05-14T22:57:39Z
- **Completed:** 2026-05-14T23:07:53Z
- **Tasks:** 3 (TDD met aparte RED + GREEN commits per task)
- **Commits:** 6 (3× test-RED + 3× feat-GREEN)
- **Files modified:** 14 (10 created, 4 modified)

## Accomplishments

- **CustomersController** levert `index` (page), `show`, `store` op `/v2/customers[/{id}]` via Mollie SDK `customers->page/get/create`. Bij ontbreken van `getResponse()` op stub-paden valt de serializer netjes terug op JsonSerializable.
- **PaymentMethodsController** single-action `__invoke` voor `/v2/methods` met query-forward — Mollie's `methods->all($query)` accepteert `amount[currency]/amount[value]/locale/sequenceType` etc. en Hub geeft die letterlijk door.
- **RefundsController** drie acties:
  - `POST /v1/mollie/payments/{id}/refunds` → `paymentRefunds->createForId($payment_id, $payload)`, 201 + audit-path `/v2/payments/{id}/refunds`.
  - `GET /v1/mollie/payments/{id}/refunds` → `paymentRefunds->pageForId($payment_id, $from, $limit)`.
  - `GET /v1/mollie/refunds/{id}?paymentId=tr_xxx` → `paymentRefunds->getForId($paymentId, $id)`; 422 met `missing_payment_id` zonder query-param. Audit-path blijft `/v2/refunds/{id}`.
- **MandatesController** drie acties op `mandates->pageForId/getForId/revokeForId`:
  - `GET /customers/{id}/mandates` — list per customer.
  - `GET /customers/{id}/mandates/{mandate_id}` — single mandate.
  - `DELETE /customers/{id}/mandates/{mandate_id}` — revoke; retourneert 204 No Content via `{status: 204, body: []}` wrapper.
- **CreateCustomerRequest** + **CreateRefundRequest** edge-validatie. Customers heeft géén required velden (per Mollie-spec); Refunds vereist `amount.currency` (size:3) + `amount.value` (decimal-string regex `/^\d+\.\d{2,}$/`).
- **AbstractMolliePassThroughController** krijgt twee shared helpers (`resourceToArray` + `collectionToArray`) zodat alle resource-controllers Mollie's wire-shape verbatim bewaren zonder duplicatie. Het bestaande `paymentToArray` op PaymentsController is intact gelaten (chirurgisch — geen refactor in onverwante code).
- **StubsMollieClient-trait** breidt uit met `bindMollieStubs(array $resolvers)` — één call bindt meerdere endpoint-stubs (`customers`, `methods`, `paymentRefunds`, `mandates`) op één StubMollieClient. Achterwaarts-compatibel: bestaande `bindMollieStub($resolver)` blijft de Payments-only-shortcut.
- **12 nieuwe feature-tests groen** (3 + 2 + 4 + 3). Volledige suite: **185 passed / 1 incomplete (pre-existing Phase 3 placeholder) / 0 failed** (van 173 → 185).

## Task Commits

| # | Task | RED | GREEN |
|---|------|-----|-------|
| 1 | Customers + PaymentMethods + 4 routes + 5 tests | `b045f23` | `271aa42` |
| 2 | Refunds + 3 routes + 4 tests | `b38e6f8` | `8c27830` |
| 3 | Mandates + 3 routes + 3 tests | `2abe132` | `0745527` |

## Files Created/Modified

### Created
- `app/Http/Controllers/Api/V1/Mollie/CustomersController.php` — 3 acties (index/show/store), `customers->page/get/create`
- `app/Http/Controllers/Api/V1/Mollie/PaymentMethodsController.php` — single-action `__invoke`, `methods->all($query)`
- `app/Http/Controllers/Api/V1/Mollie/RefundsController.php` — 3 acties (store/index/show), `paymentRefunds->createForId/pageForId/getForId`
- `app/Http/Controllers/Api/V1/Mollie/MandatesController.php` — 3 acties (index/show/destroy), `mandates->pageForId/getForId/revokeForId`
- `app/Http/Requests/Api/V1/Mollie/CreateCustomerRequest.php` — nullable: name/email/locale/metadata/testmode
- `app/Http/Requests/Api/V1/Mollie/CreateRefundRequest.php` — required: amount.currency/amount.value
- `tests/Feature/Api/V1/Mollie/CustomersTest.php` — 3 cases
- `tests/Feature/Api/V1/Mollie/PaymentMethodsTest.php` — 2 cases
- `tests/Feature/Api/V1/Mollie/RefundsTest.php` — 4 cases (incl. 422-zonder-paymentId-edge-case)
- `tests/Feature/Api/V1/Mollie/MandatesTest.php` — 3 cases

### Modified
- `app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php` — shared `resourceToArray()` + `collectionToArray()` helpers
- `tests/Concerns/StubsMollieClient.php` — `bindMollieStubs()` multi-resolver, plus `customer_*`/`method_*`/`refund_*`/`mandate_*` capture-arrays + `makeCustomer/makeMethodCollection/makeRefund/makeRefundCollection/makeMandate/makeMandateCollection` helpers
- `tests/Feature/Api/V1/Mollie/StubMollieClient.php` — accepteert extra endpoint-stubs via `extraStubs`-array (achterwaarts-compatibel met enkel-payment-stub-constructor-shape)
- `routes/api.php` — 10 nieuwe routes binnen bestaand `Route::prefix('mollie')->middleware('resolve.mollie.account')` block + 4 nieuwe controller-imports

## Test-counts per file

| File | Tests | Doel |
|---|---|---|
| CustomersTest.php | 3 | MOLL-03 list/get/create happy-paths |
| PaymentMethodsTest.php | 2 | MOLL-03 list + query-passthrough |
| RefundsTest.php | 4 | MOLL-03 create/list/get + missing-paymentId edge-case |
| MandatesTest.php | 3 | MOLL-03 list/get/revoke |
| **Totaal** | **12** | (plan-minimum: 11) |

Volledige Mollie-suite (incl. 05a-01/02/03 baseline): **38 tests / 146 assertions / passed**.

## Decisions Made

### Vendor-API afwijking 1: `$mandates` i.p.v. `$customerMandates`

Plan-frontmatter en behavior-skelet refereerden naar `Mollie::client()->customerMandates->pageForId(...)`, maar `MollieApiClient` exposeert het MandateEndpointCollection als `$mandates` (geverifieerd in `vendor/mollie/mollie-api-php/src/MollieApiClient.php` regel 83 `@property MandateEndpointCollection $mandates` + property-list bevestigt géén `$customerMandates`-property).

**Beslissing:** controller gebruikt `$client->mandates->pageForId/getForId/revokeForId`. Property-naam in stub-trait + controller blijft `mandates`. De methode-namen (`pageForId`, `getForId`, `revokeForId`) komen wel exact overeen met plan.

### Vendor-API afwijking 2: Geen standalone `refunds->get($id)`

Mollie's `RefundEndpointCollection` heeft alleen `page(?string $from, ?int $limit, array $filters)` en `iterator(...)`. Geen `get(string $id)`. De SDK forceert lookup-by-id via de nested endpoint: `paymentRefunds->getForId($paymentId, $refundId)`.

**Beslissing:** standalone-route `GET /v1/mollie/refunds/{id}` blijft bestaan (per plan), maar vereist een `?paymentId=tr_xxx` query-parameter. Zonder die query retourneert de controller `422 missing_payment_id` met een uitlegtekst. Audit-path blijft het Mollie-REST-endpoint-template `/v2/refunds/{id}` (semantiek voor de Consumer wijzigt niet, alleen edge-validatie).

Alternatieven die we afwogen:
- **Route schrappen** — verliest plan-coverage.
- **`refunds->page()` itereren** — quadratisch + breekt op pagination, leverage Mollie quota-burn.
- **Generieke `MollieApiClient::send(new GetRefundRequest($id))`-route** — vereist refactor van het stub-pad (we capturen op endpoint-property, niet op send-pipeline). Kan toekomstige optimization zijn.

### Idempotency-Key forward (D-06) op nieuwe write-routes

Plan-frontmatter noemt D-06 maar 05a-03's `buildClient($request)`-helper is `protected` op `PaymentsController` en bevat de runtime `setIdempotencyKey()`-aanroep. Hergebruik vereist een refactor naar shared trait/helper op `AbstractMolliePassThroughController` — een grotere wijziging dan een bug-fix.

**Beslissing:** POST customers + POST refunds gebruiken nu SDK's UuidV7-default-generator (binding via `config/mollie.php` uit Plan 05a-03 Task 1). Consumer kan dus géén eigen Idempotency-Key verbatim doorzetten op deze routes. Tracked als follow-up: hoist `buildClient()` naar de abstract base in een aparte refactor-commit (geen functionele wijziging voor PaymentsController), zodat Customers + Refunds + later PaymentLinks/Subscriptions mee verlengen. **Niet** in deze plan-execute uitgevoerd om scope strict te houden.

Dit is géén security-regressie — UuidV7 levert al cryptografisch unieke keys, dus Mollie's server-side dedup werkt nog steeds binnen één request. Het verschil is alleen dat Consumer's retry-via-eigen-key niet doorgegeven wordt.

### Customer-listing stub-pad

Mollie's `customers->page(?from, ?limit)` retourneert in productie een `CustomerCollection`. In tests retourneerde ik een single `Customer`-resource voor eenvoud (cursor-collection-instantiation is foutgevoelig met dynamic resource-classes). De CustomersController detecteert `instanceof BaseCollection` en kiest de juiste serializer — beide paden werken zonder code-paths te dupliceren.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] worktree had geen vendor/ + geen .env**
- **Found during:** pre-execution setup
- **Fix:** `composer install` (~40s) + `cp .env.example .env && php artisan key:generate` (~2s). Standaard worktree-bootstrap.
- **Files modified:** geen tracked files
- **Commit:** N/A

**2. [Rule 1 - Bug] Plan-property `customerMandates` bestaat niet in Mollie's SDK**
- **Found during:** Task 3 pre-implementation vendor-check
- **Issue:** Plan refereerde `Mollie::client()->customerMandates->...`. `MollieApiClient` heeft géén `$customerMandates`-property (geverifieerd in `vendor/.../MollieApiClient.php @property`-block).
- **Fix:** Gebruik `$mandates` (de werkelijke property-naam). Stub-trait + controller + tests aangepast. Zelfde methode-namen, andere property.
- **Files modified:** `MandatesController.php`, `tests/Concerns/StubsMollieClient.php`, `tests/Feature/Api/V1/Mollie/StubMollieClient.php`, `tests/Feature/Api/V1/Mollie/MandatesTest.php`
- **Commit:** `2abe132` (RED-commit met dezelfde naming) + `0745527` (GREEN-commit)

**3. [Rule 1 - Bug] Plan-method `refunds->get($id)` bestaat niet in Mollie's SDK**
- **Found during:** Task 2 vendor-check
- **Issue:** `RefundEndpointCollection` heeft alleen `page()` + `iterator()`. Geen `get(string $id)`.
- **Fix:** Standalone-route bewaard maar mapt intern naar `paymentRefunds->getForId($paymentId, $refundId)` via `?paymentId`-query. 422 zonder. Audit-path en route-shape blijven plan-conform; alleen het mechanisme erachter wijkt af.
- **Files modified:** `RefundsController.php`, `RefundsTest.php` (extra 4e case `test_get_refund_by_id_without_payment_id_returns_422`)
- **Commit:** `b38e6f8` + `8c27830`

**4. [Rule 2 - Missing critical functionality] `resourceToArray` / `collectionToArray` op de base niet beschreven in plan**
- **Found during:** Task 1 implementation
- **Issue:** Plan-skeletten gebruikten `->toArray()` direct op `Customer`/`Mandate`/`Refund`-resources, maar Mollie's `BaseResource` heeft géén `toArray()` (alleen `AnyResource` heeft die — geverifieerd in `vendor/.../BaseResource.php`). Zelfde bug die in 05a-03 voor PaymentsController is opgelost met een private `paymentToArray()`-helper, maar nu zou diezelfde fix per controller herhaald moeten worden.
- **Fix:** Generieke helpers (`resourceToArray(BaseResource)`, `collectionToArray(BaseCollection)`) op AbstractMolliePassThroughController. Bewaart Mollie's wire-shape via `getResponse()->body()` decode en valt terug op JsonSerializable in test-stub-pad. Bestaande `PaymentsController::paymentToArray` is intact gelaten (chirurgisch — geen onverwante refactor) maar nieuwe controllers gebruiken de shared helpers.
- **Files modified:** `app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php`
- **Commit:** `271aa42` (geïntegreerd in Task 1 GREEN-commit)

## Form Request veld-discrepanties tegen .docs/partners/mollie/*

`.docs/` is gitignored in deze worktree (per CLAUDE.md-convention), dus geen directe vergelijking mogelijk binnen execute-context. Form Request-velden volgen plan-text:

- **CreateCustomerRequest** — alle velden `nullable` (Mollie's customer-create heeft géén required velden volgens plan-citaat van `.docs/partners/mollie/customers-api.md`). Toegevoegd: `testmode` boolean (Mollie's typed CreatePaymentRequest accepteert het; voor consistentie met andere Mollie-write-routes).
- **CreateRefundRequest** — `amount.currency` size:3 + `amount.value` decimal-string regex; `description`, `externalReference`, `metadata`, `testmode` nullable. Niet-genoemde Mollie-velden (`reverseRouting` etc.) worden niet gevalideerd; Mollie zelf valideert ze als ze in de payload zitten.

## Issues Encountered

- **Worktree-bootstrap** (zelfde als 05a-01/02/03): geen `vendor/`, geen `.env`. Recovery via `composer install` + `cp .env.example .env && php artisan key:generate`. Geen tracked-file-impact. Tijdkost ~40s.
- **Pint-fix op `BaseCollection`-import in CustomersController**: Pint hoorde een `\Mollie\Api\Resources\BaseCollection` fully-qualified call FQN'en + sorteren — de `fully_qualified_strict_types` + `ordered_imports` fixers waren actief. Geen functionele wijziging; auto-applied. Acknowledged via PostToolUse-hook-notification.
- **Docs-drift-hook trigger op routes/api.php** (drie keer): elke `Edit` op `routes/api.php` triggerde de `docs-sync` skill-suggestion. Per plan-output is dit een follow-up bij merge-tijd, niet binnen deze execute. Acknowledged en doorgewerkt; volgt in follow-up sectie.

## User Setup Required

Geen — alle infra (controllers, Form Requests, routes, middleware-aliases) zit in het commit-pad. Productie-rollout vereist alleen dat de Hub-omgeving:
1. Een geldige `MOLLIE_*` access_token of API-key heeft op een actieve `Connection` (geleverd door Phase 4 OAuth-broker).
2. (Optioneel) De host-app realiseert dat `?paymentId`-query verplicht is op `GET /v1/mollie/refunds/{id}` (zal in 05a-05's Scramble-OpenAPI gedocumenteerd worden).

## Known Stubs

Geen stubs in 05a-04. Alle 10 nieuwe routes leveren een echte pass-through naar de SDK. Test-stubs leven uitsluitend onder `tests/` (`StubMollieClient`, `StubsMollieClient`-trait) en hebben geen runtime-effect in productie.

## Next Phase Readiness

- **Plan 05a-05 (PaymentLinks + Subscriptions + Scramble + acceptance)** kan starten met dezelfde pattern:
  - PaymentLinksController-mirror van CustomersController (single-resource, create/get/list) — `$client->paymentLinks` is gepublished.
  - SubscriptionsController nested onder Customer — `$client->subscriptions` exists, maar de nested-by-customer-pattern volgt `customerSubscriptions` (te verifiëren in vendor).
  - Scramble-route-discovery test moet alle 13+X Mollie-resource-paths in `/docs/api` verifiëren (al 13 routes geland nu).
  - SC-1 happy-path tegen Mollie's test-mode access_token (real-roundtrip).
- **Geen blockers.** D-06 Idempotency-Key forward voor write-routes (customers/refunds) is gedeferd naar follow-up — productie-friction zal aantonen of hoist naar AbstractMolliePassThroughController noodzakelijk is.

## Follow-ups

- **docs-sync trigger:** `routes/api.php` is 3× gewijzigd (10 nieuwe routes onder `/v1/mollie/*`) + 4 nieuwe controllers + 2 nieuwe form-requests. Plan-output specificeert expliciet dat dit een follow-up is. Uit te voeren bij merge-tijd naar `chore/v02-roadmap-split-and-scramble`, niet binnen deze plan-execute.
- **`buildClient($request)`-helper hoisten naar `AbstractMolliePassThroughController`** zodat Customers + Refunds (+ later PaymentLinks/Subscriptions) ook de Consumer's `Idempotency-Key`-header verbatim aan Mollie kunnen doorgeven. Geen wijziging aan PaymentsController-gedrag — alleen een protected method-verplaatsing.
- **ARCHITECTURE.md / CONVENTIONS.md** — vermelding van `resourceToArray` + `collectionToArray` als shared base-helpers (vervangt de private `paymentToArray`-pattern). Buiten scope van deze plan-execute.
- **REQUIREMENTS.md** — markeer MOLL-03 deeltjes (`Customers`, `PaymentMethods`, `Refunds`, `Mandates`) zodra de orchestrator ROADMAP/REQUIREMENTS-updates uitvoert. Plan 05a-05 sluit MOLL-03 met `Subscriptions` + `PaymentLinks` af.
- **PaymentRefund DELETE-route?** — Mollie's SDK heeft `paymentRefunds->cancelForId`. Plan vroeg er niet om, dus niet geleverd. Backlog als use-case opduikt.

## Threat Flags

Geen nieuwe trust-boundaries geïntroduceerd buiten de plan's `<threat_model>`. T-05a-19 (mandate-revoke without account-owner consent — accepted), T-05a-20 (refund-amount in audit — accepted), T-05a-21 (mandate-spoofing — accepted) blijven van toepassing en zijn niet aangepast.

## Self-Check: PASSED

- All 10 created files exist on disk:
  - `app/Http/Controllers/Api/V1/Mollie/CustomersController.php` ✓
  - `app/Http/Controllers/Api/V1/Mollie/PaymentMethodsController.php` ✓
  - `app/Http/Controllers/Api/V1/Mollie/RefundsController.php` ✓
  - `app/Http/Controllers/Api/V1/Mollie/MandatesController.php` ✓
  - `app/Http/Requests/Api/V1/Mollie/CreateCustomerRequest.php` ✓
  - `app/Http/Requests/Api/V1/Mollie/CreateRefundRequest.php` ✓
  - `tests/Feature/Api/V1/Mollie/CustomersTest.php` ✓
  - `tests/Feature/Api/V1/Mollie/PaymentMethodsTest.php` ✓
  - `tests/Feature/Api/V1/Mollie/RefundsTest.php` ✓
  - `tests/Feature/Api/V1/Mollie/MandatesTest.php` ✓
- All 6 task-commits exist in `git log`: `b045f23`, `271aa42`, `b38e6f8`, `8c27830`, `2abe132`, `0745527`.
- 12 nieuwe feature-tests groen (filter='CustomersTest|PaymentMethodsTest|RefundsTest|MandatesTest'). Volledige Mollie-suite (incl. baseline) 38 passed.
- Full PHPUnit suite: **185 passed / 1 incomplete (pre-existing) / 0 failed**.
- Pint clean op alle nieuwe/gewijzigde files.
- `php artisan route:list` toont 13 named-routes onder `api.mollie.*` (3 payments + 3 customers + 1 payment-methods + 3 refunds + 3 mandates).

## TDD Gate Compliance

Plan-frontmatter `type: execute` met 3 tasks `tdd="true"`. Commit-sequence levert beide gates per task:

| Task | RED-commit (test) | GREEN-commit (feat) |
|------|-------------------|---------------------|
| 1 (Customers + PaymentMethods) | `b045f23` test(05a-04): RED voor Customers + PaymentMethods | `271aa42` feat(05a-04): Customers + PaymentMethods pass-through |
| 2 (Refunds) | `b38e6f8` test(05a-04): RED voor Refunds | `8c27830` feat(05a-04): Refunds pass-through |
| 3 (Mandates) | `2abe132` test(05a-04): RED voor Mandates | `0745527` feat(05a-04): Mandates pass-through |

Test-commits gaan altijd vóór feat-commits per task. Geen TDD-gate-violation.

---
*Phase: 05a-mollie-sdk-resources-webhooks-pass-through-api*
*Plan: 04*
*Completed: 2026-05-14*
