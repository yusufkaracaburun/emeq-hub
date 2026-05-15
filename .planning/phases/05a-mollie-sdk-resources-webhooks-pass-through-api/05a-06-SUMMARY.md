---
phase: 05a-mollie-sdk-resources-webhooks-pass-through-api
plan: 06
subsystem: api
tags:
  - mollie
  - webhooks
  - idempotency
  - security
  - gap-closure
  - tdd

# Dependency graph
requires:
  - phase: 05a-03
    provides: "PaymentsController::buildClient pattern + StubsMollieClient capture-of-idempotency-keys"
  - phase: 05a-04
    provides: "CustomersController / RefundsController + paymentRefunds/customers stubs"
  - phase: 05a-05
    provides: "SubscriptionsController / PaymentLinksController + subscriptions/paymentLinks stubs"
  - phase: 05a-02
    provides: "MollieWebhookController + MollieWebhookSignature SDK helper + auditFailedWebhook pattern"
provides:
  - "AbstractMolliePassThroughController::buildClient(Request): MollieApiClient — gedeelde Idempotency-Key-forward helper voor alle 5 write-endpoints"
  - "D-06 verbreding: Customers / Refunds / Subscriptions / PaymentLinks forwarden Consumer's Idempotency-Key verbatim (was alleen Payments)"
  - "MollieWebhookController stap-0 guard: empty/null MOLLIE_WEBHOOK_SECRET → 500 + audit('webhook_secret_not_configured') vóór signature-verify"
  - "4 nieuwe feature-tests (CustomersIdempotencyForwardTest / RefundsIdempotencyForwardTest / SubscriptionsIdempotencyForwardTest / PaymentLinksIdempotencyForwardTest)"
  - "2 nieuwe testpaden in MollieWebhookSignatureTest (null + empty-string secret)"
  - "Phase 5a 13/13 truths verified — gaps_closed: [truth-12, truth-13]"
affects:
  - "Phase 8 NSCH-03 (Naschool) — bouwt op een correcte D-06 + dichte webhook-ingress voort"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Helper-hoist: protected buildClient(Request) verhuist van PaymentsController naar AbstractMolliePassThroughController — één gedeeld pad voor alle 5 write-endpoints i.p.v. payments-only"
    - "Hard-fail guard pattern voor security-config: validate vóór SDK-aanroep + audit + 500 (geen silent-open ingress)"
    - "RED-first TDD per controller: één test per write-resource bewijst Idempotency-Key forward vóór de refactor"
    - "Test-infra fix (Rule 3): makeCustomersStub captureerde idempotency_keys nog niet zoals zijn 3 zusterstubs — uitgebreid met StubMollieClient-referentie voor pre-call key-observability"

key-files:
  created:
    - .planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-06-SUMMARY.md
    - tests/Feature/Api/V1/Mollie/CustomersIdempotencyForwardTest.php
    - tests/Feature/Api/V1/Mollie/RefundsIdempotencyForwardTest.php
    - tests/Feature/Api/V1/Mollie/SubscriptionsIdempotencyForwardTest.php
    - tests/Feature/Api/V1/Mollie/PaymentLinksIdempotencyForwardTest.php
  modified:
    - app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php
    - app/Http/Controllers/Api/V1/Mollie/PaymentsController.php
    - app/Http/Controllers/Api/V1/Mollie/CustomersController.php
    - app/Http/Controllers/Api/V1/Mollie/RefundsController.php
    - app/Http/Controllers/Api/V1/Mollie/SubscriptionsController.php
    - app/Http/Controllers/Api/V1/Mollie/PaymentLinksController.php
    - app/Http/Controllers/Webhooks/MollieWebhookController.php
    - tests/Concerns/StubsMollieClient.php
    - tests/Feature/Webhooks/MollieWebhookSignatureTest.php

key-decisions:
  - "Hoist helper naar Abstract i.p.v. trait of static-utility — PaymentsController gebruikte de exacte zelfde signature, inheritance is de kortste DRY-route en past in het bestaande extend-pattern van alle 7 concrete controllers"
  - "Guard returnt 500 (server error) niet 400/403 — een lege secret is een server-misconfiguratie, geen client-fout. 500 maakt de fout zichtbaar in error-monitoring i.p.v. wegduwen naar de Consumer als '400 invalid signature'"
  - "Boot-time AppServiceProvider-guard gedeferred (zie threat_model 'Deferred' sectie van 05a-06-PLAN) — runtime-guard dekt het concrete attack-vector; boot-time guard raakt ook artisan+queue en hoort in een dedicated production-precondition-pas"
  - "Test-infra fix voor makeCustomersStub is part of Rule 3 (blocking) — zonder die fix kan de Customers-RED-test geen idempotency_keys observen; de andere 3 stubs (paymentRefunds/subscriptions/paymentLinks) hadden 't al"

# Metrics
metrics:
  duration_minutes: 22
  completed: 2026-05-15
---

# Phase 05a Plan 06: Gap-closure D-06 idempotency + D-08 webhook-secret Summary

Sluit de 2 BLOCKER-gaps uit `05a-VERIFICATION.md` (truth #12 D-06 verbreding, truth #13 webhook-secret hard-fail) door één gedeelde `buildClient`-helper te hoisen naar `AbstractMolliePassThroughController` en een stap-0 guard te plaatsen vóór `MollieWebhookSignature::verify`. Phase 5a gaat van 11/13 naar 13/13 verified.

## Goal

Twee FAILED truths uit de verifier-output omzetten naar VERIFIED:

1. **Truth #12 (D-06):** Consumer `Idempotency-Key`-header wordt nu verbatim doorgegeven aan Mollie voor **alle 5 write-endpoints** (was alleen `payments`). Voorkomt dubbele refunds (direct financieel risico) en dubbele subscriptions (recurring-debit risico) bij retry-storms.
2. **Truth #13 (D-08 stap 1 / T-05a-06):** `MollieWebhookController` faalt nu closed bij empty/null `MOLLIE_WEBHOOK_SECRET` — geen silent-open ingress meer als de env-var op deploy vergeten wordt. Aanvalsvector: HMAC met `''` als secret is triviaal door iedereen die de payload kent.

Plan-status: `gap_closure: true`. 7 productie-files gewijzigd, 5 test-files geraakt (4 nieuw, 1 uitgebreid), 1 test-infra fix.

## What was built

**Productie-code (7 files)**

- `AbstractMolliePassThroughController.php`: `+ protected function buildClient(Request): MollieApiClient` + 2 imports (`Emeq\MollieApi\Facades\Mollie`, `Mollie\Api\MollieApiClient`). Verbatim kopie van de oude PaymentsController-helper, doccomment generaliseert naar "gedeeld pad voor alle 5 write-endpoints (D-06)".
- `PaymentsController.php`: `- protected function buildClient(...)` + `- use Mollie\Api\MollieApiClient`. Lijn 47 (`$client = $this->buildClient($request)`) blijft staan — werkt nu via inheritance. Doccomment 'Idempotency-Key forward'-paragraaf gecollapseerd tot één regel.
- `CustomersController.php` / `RefundsController.php` / `SubscriptionsController.php` / `PaymentLinksController.php`: `store()` gebruikt nu `$this->buildClient($r)->...->create(...)` i.p.v. `Mollie::client()->...->create(...)`. Imports onveranderd (`Mollie::client()` blijft in gebruik voor index/show/destroy waar geen Idempotency-Key forward nodig is).
- `MollieWebhookController.php`: stap-0 guard vóór de `MollieWebhookSignature::verify`-try-catch:
  ```php
  $secret = config('services.mollie.webhook_secret');
  if (! is_string($secret) || $secret === '') {
      $this->auditFailedWebhook($request, 'webhook_secret_not_configured');
      return response()->json(['error' => 'webhook_misconfigured'], 500);
  }
  ```
  De `(string)`-cast op het verify-argument is verwijderd; `$secret` is na de guard gegarandeerd een niet-lege string.

**Tests (5 files)**

- `tests/Feature/Api/V1/Mollie/CustomersIdempotencyForwardTest.php` — `test_consumer_idempotency_key_is_forwarded_on_customer_create`
- `tests/Feature/Api/V1/Mollie/RefundsIdempotencyForwardTest.php` — `test_consumer_idempotency_key_is_forwarded_on_refund_create`
- `tests/Feature/Api/V1/Mollie/SubscriptionsIdempotencyForwardTest.php` — `test_consumer_idempotency_key_is_forwarded_on_subscription_create`
- `tests/Feature/Api/V1/Mollie/PaymentLinksIdempotencyForwardTest.php` — `test_consumer_idempotency_key_is_forwarded_on_payment_link_create`
- `tests/Feature/Webhooks/MollieWebhookSignatureTest.php` — `+ test_null_platform_secret_returns_500_and_does_not_dispatch` + `+ test_empty_string_platform_secret_returns_500_and_does_not_dispatch`

**Test-infra (1 file — Rule 3 deviation)**

- `tests/Concerns/StubsMollieClient.php`: `makeCustomersStub` captureerde `idempotency_keys` nog niet in tegenstelling tot z'n 3 zusterstubs (`paymentRefunds`, `subscriptions`, `paymentLinks`). Zonder die capture is de Customers-RED-test geen valide bewijs van de gap. Stub krijgt nu een `StubMollieClient`-referentie en pusht `$this->mollieClient?->getIdempotencyKey()` vlak vóór de resolver-call — identiek aan het pattern in de andere drie.

## Commits

| Commit | Type | Files | Description |
| ------ | ---- | ----- | ----------- |
| `e6c88dc` | test | 5 | RED Task 1: 4 nieuwe IdempotencyForwardTest-files + StubsMollieClient.makeCustomersStub fix — bewijst gap |
| `32967e9` | refactor | 6 | GREEN Task 1: hoist buildClient naar Abstract + 5 controllers refactored — sluit D-06 gap |
| `386b0b2` | test | 1 | RED Task 2: 2 nieuwe MollieWebhookSignatureTest-paden voor null + '' secret — bewijst gap |
| `9bd2905` | feat | 1 | GREEN Task 2: stap-0 guard in MollieWebhookController + (string)-cast verwijderd — sluit D-08 stap 1 gap |

RED-paper-trail intact (TDD-discipline): elke task heeft één RED-commit (failing test) gevolgd door één GREEN-commit (implementatie). Beide RED-commits werden geverifieerd door run-output vóór GREEN-fase.

## Tests

| Scope | Status | Detail |
| ----- | ------ | ------ |
| `tests/Feature/Api/V1/Mollie/*IdempotencyForwardTest.php` (4 new) | 4/4 pass | Bewijst Consumer-Idempotency-Key forward op alle 4 niet-payments POST-endpoints |
| `tests/Feature/Api/V1/Mollie/MollieIdempotencyForwardTest.php` (existing, 3) | 3/3 pass | Inheritance-pad bewezen; payments-tests blijven groen |
| `tests/Feature/Webhooks/MollieWebhookSignatureTest.php` (8) | 8/8 pass | 6 bestaande blijven groen + 2 nieuwe null/empty-secret-paden groen |
| `tests/Feature/Api/V1/Mollie/` | 49/49 pass | Volledige Mollie-pass-through-scope, 0 regressies |
| `tests/Feature/Webhooks/` | 13/13 pass | Volledige webhook-scope, 0 regressies |
| `php artisan test --compact` | **207 passed / 1 incomplete / 0 failed** | 697 assertions in ~3.9s. Was 201 → +6 nieuwe tests = 207. Incomplete is Phase 4 placeholder (unrelated). |

Pint: `vendor/bin/pint --dirty --format agent` exit 0.

## Re-verify the 2 FAILED truths

| # | Truth | Before | After | Evidence |
|---|-------|--------|-------|----------|
| 12 | D-06: Consumer Idempotency-Key forward op alle 5 POST-endpoints | FAILED (payments-only) | VERIFIED | 4 nieuwe IdempotencyForwardTest-files groen + bestaande MollieIdempotencyForwardTest blijft groen via inheritance. `grep -c 'protected function buildClient(' AbstractMolliePassThroughController.php` = 1. `grep -c '\$this->buildClient(\$r)' {Customers,Refunds,Subscriptions,PaymentLinks}Controller.php` elk = 1. |
| 13 | MOLL-04 SC-3 / D-08 stap 1 / T-05a-06: Hard fail bij empty platform-webhook-secret | FAILED (silent-open ingress) | VERIFIED | 2 nieuwe testpaden in MollieWebhookSignatureTest groen. `grep -c 'webhook_secret_not_configured' MollieWebhookController.php` = 1. `grep -c "(string) config('services.mollie.webhook_secret')" MollieWebhookController.php` = 0 (oude silent-cast verwijderd). is_string-guard op line 42, MollieWebhookSignature::verify-call op line 50 — guard correct vóór verify. |

## Anti-Patterns gesloten

Uit de "Anti-Patterns Found"-tabel van `05a-VERIFICATION.md`:

- `CustomersController.php:65` BLOCKER → **resolved**
- `RefundsController.php:35` BLOCKER → **resolved**
- `SubscriptionsController.php:54` BLOCKER → **resolved**
- `PaymentLinksController.php:49` BLOCKER → **resolved**
- `MollieWebhookController.php:41` BLOCKER → **resolved**

De 6 WARNING- en 5 INFO-anti-patterns (WR-01 t/m IN-05 uit `05a-REVIEW.md`) blijven open — out-of-scope voor deze gap-closure plan, te plannen als follow-up als ze in roadmap-scope landen.

## Deviations from Plan

### Rule 3 — Auto-fixed blocking issue (test-infra)

**1. [Rule 3 - Blocking] StubsMollieClient::makeCustomersStub captureerde idempotency_keys niet**

- **Gevonden tijdens:** Task 1, eerste RED-run van `CustomersIdempotencyForwardTest`. Test faalde met `actual size 0 matches expected size 1` — geen idempotency_keys ge-pushed door de stub, zelfs niet wanneer de controller `setIdempotencyKey()` zou aanroepen.
- **Issue:** De plan-`<interfaces>` claimde dat alle resource-stubs `idempotency_keys` capturen vóór de resolver-call, maar `makeCustomersStub` (lines 211-253) deed dat niet — alleen `paymentRefunds`, `subscriptions` en `paymentLinks` waren al uitgerust met de `StubMollieClient`-referentie + key-capture. Customers had de hint nooit gekregen.
- **Why blocking:** zonder deze capture is de Customers-RED-test geen valide gap-bewijs, en de Customers-GREEN-test zou ook na de refactor 0 entries zien — dus geen acceptance-coverage voor de meest gebruikte write-endpoint.
- **Fix:** `makeCustomersStub` krijgt een 3e parameter `?StubMollieClient &$clientRef` (zelfde pattern als de andere 3 stubs) en pusht `$this->mollieClient?->getIdempotencyKey()` in `create()` vóór de resolver-aanroep. Call-site in `bindMollieStubs` aangepast om `$clientRef` door te geven.
- **Files modified:** `tests/Concerns/StubsMollieClient.php` (lines 128 + 211-225)
- **Commit:** Onderdeel van de RED-commit `e6c88dc` (zodat de test-infra-fix en de RED-tests samen één coherente RED-fase vormen).

### Rule 1/2/4

Geen Rule-1/2/4-deviaties. Plan was deterministisch genoeg om exact te volgen: vaste file-paden, vaste lijn-nummers, vaste signature-kopie, vaste testboilerplate.

### Threat-model coverage

Beide STRIDE-threats uit de plan-`<threat_model>` zijn gemitigeerd door de afgesloten gaps:

- **T-05a-07 (Tampering/Repudiation):** 4 controllers routen nu via `buildClient($r)` — Consumer-Idempotency-Key reaches Mollie verbatim.
- **T-05a-06 (Spoofing/Elevation):** `MollieWebhookController` faalt closed bij empty secret — geen open ingress meer.

Geen nieuwe threat-surface geintroduceerd (alle wijzigingen blijven binnen bestaande controllers + één Abstract-helper).

## Known Stubs

Geen. De refactor verandert geen rendering-paden of data-bronnen — alleen het pad waarlangs de Mollie-client wordt opgebouwd.

## Self-Check: PASSED

Bewijs:

- **Files created (5):**
  - `tests/Feature/Api/V1/Mollie/CustomersIdempotencyForwardTest.php` FOUND
  - `tests/Feature/Api/V1/Mollie/RefundsIdempotencyForwardTest.php` FOUND
  - `tests/Feature/Api/V1/Mollie/SubscriptionsIdempotencyForwardTest.php` FOUND
  - `tests/Feature/Api/V1/Mollie/PaymentLinksIdempotencyForwardTest.php` FOUND
  - `.planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-06-SUMMARY.md` FOUND (this file)
- **Commits in worktree branch:**
  - `e6c88dc` FOUND (test RED Task 1)
  - `32967e9` FOUND (refactor GREEN Task 1)
  - `386b0b2` FOUND (test RED Task 2)
  - `9bd2905` FOUND (feat GREEN Task 2)
- **Test-suite:** 207 passed / 1 incomplete / 0 failed (Phase 4 incomplete is unrelated Phase 4 placeholder).
- **Pint:** clean.
- **Acceptance criteria 1-15 (Task 1) + 1-9 (Task 2):** all met (grep-verified).
