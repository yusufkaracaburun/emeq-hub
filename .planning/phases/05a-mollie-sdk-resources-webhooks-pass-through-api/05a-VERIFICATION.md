---
phase: 05a-mollie-sdk-resources-webhooks-pass-through-api
verified: 2026-05-15T09:00:00Z
status: human_needed
score: 13/13 must-haves verified
overrides_applied: 0
re_verification:
  previous_status: gaps_found
  previous_score: 11/13 must-haves verified
  gaps_closed:
    - "D-06: Consumer Idempotency-Key forward op alle 5 POST-endpoints (truth #12)"
    - "MOLL-04 SC-3 / D-08 stap 1 / T-05a-06: Hard fail bij ontbrekende platform-webhook-secret (truth #13)"
  gaps_remaining: []
  regressions: []
deferred: []
human_verification:
  - test: "Browser-render /docs/api Scramble UI"
    expected: "Alle 22 Mollie-routes + 3 OAuth-routes + 3 webhook-routes verschijnen met working 'Try it out'-buttons. Payments + Customers + PaymentLinks tonen edit-baar request-body schema."
    why_human: "Scramble UI render kan niet headless gevalideerd worden zonder echte browser; OpenAPI-paths zijn wel programmatisch bevestigd (ScrambleRouteDiscoveryTest 11/11 groen) maar UI-rendering + Try-it-out-functionaliteit zelf zijn visueel-interactief."
  - test: "Real Mollie testmode webhook hit naar /webhooks/mollie/{connection_id}"
    expected: "Mollie's next-gen subscription-webhook (X-Mollie-Signature, JSON body) verifieert correct, antifoofing-fetch slaagt, fan-out POST verschijnt op een test-Consumer-callback (bv. https://webhook.site). Audit-rij in webhook_calls heeft geen exception."
    why_human: "Vereist een live Mollie testmode account + Connect-koppeling + publiek bereikbare ngrok-/Caddy-tunnel. SDK-pad is via MollieWebhookSignature-helper getest met stubs maar de eind-tot-eind validatie tegen Mollie's eigen signer is niet geautomatiseerd."
  - test: "Concrete POST /v1/mollie/payments → ouder doorloopt Mollie test-mode → webhook ontvangen"
    expected: "Naschool-scenario (NSCH-03 dependency): Consumer-PAT met mollie:write + Account-id van een test-school stuurt POST payment → ontvangt _links.checkout.href → ouder doorloopt Mollie test-modus → Mollie post webhook naar Hub → fan-out naar Naschool-callback succesvol."
    why_human: "End-to-end smoke met echte Mollie test-omgeving is buiten scope van Phase 5a (valt onder Phase 8 NSCH-03) maar bewijst SC-1 + SC-3 + SC-4 hard."
---

# Phase 5a: Mollie SDK Resources + Webhooks + Pass-through API Verification Report (Re-verify after 05a-06)

**Phase Goal:** Een werkende end-to-end Mollie-pass-through: Consumer doet HTTP-call naar Hub, Hub resolved Connection, SDK doet Mollie-call, response stroomt terug — voor alle 6 in-scope resources, inclusief inkomende webhook-verificatie.
**Verified:** 2026-05-15T09:00:00Z
**Status:** human_needed (alle 13/13 must-haves geverifieerd; 3 items wachten op live-omgeving spot-checks)
**Re-verification:** Yes — initial run was `gaps_found` (11/13) op 2026-05-15T02:00:00Z; deze run na merge van plan 05a-06.
**Test suite:** 207 passed / 1 incomplete (Phase 4 placeholder, unrelated) / 0 failed — 697 assertions in ~4.0s.

## Re-verification Summary

| Aspect | Previous (11/13) | Current (13/13) |
| ------ | --------------- | --------------- |
| Status | gaps_found | human_needed |
| Truth #12 (D-06) | FAILED | VERIFIED |
| Truth #13 (webhook-secret hard-fail) | FAILED | VERIFIED |
| Truths #1–#11 | VERIFIED | VERIFIED (no regressions) |
| Suite size | 201 passed / 1 incomplete | 207 passed / 1 incomplete |
| New test-files | n/a | 4 (Customers/Refunds/Subscriptions/PaymentLinks IdempotencyForwardTest) + 2 nieuwe testpaden in MollieWebhookSignatureTest |

Status moves to `human_needed` (niet `passed`) omdat 3 items uit de initial verification structureel niet programmatisch dekbaar zijn (Scramble UI render, live Mollie webhook hit, NSCH-03 end-to-end smoke). Deze items werden niet door 05a-06 geraakt en blijven onveranderd.

## Goal Achievement

### Observable Truths (consolidated from 6 plan-frontmatter must-haves + 5 ROADMAP SCs)

| # | Truth | Status | Evidence |
| --- | ----- | ------ | -------- |
| 1 | MOLL-03 SC-1: POST /v1/mollie/payments retourneert Mollie's `_links.checkout.href` | VERIFIED | `tests/Feature/Api/V1/Mollie/PaymentsTest.php` — 5/5 tests groen, 19 assertions. `test_post_payments_proxies_through_sdk_and_returns_201_with_mollie_payload` asserteert `_links.checkout.href = https://mollie.test/checkout/tr_happy_1`. Geen regressie t.o.v. initial verify. |
| 2 | MOLL-03 SC-2: Alle 7 Mollie-resources callable via `/v1/mollie/*` | VERIFIED | Geen wijzigingen aan controllers' extends-chain; alle 7 ResourceControllers extenden nog steeds `AbstractMolliePassThroughController` (Customers:21, Refunds:28, Subscriptions:27, PaymentLinks:23, Payments:30 — geverifieerd via grep `extends AbstractMolliePassThroughController`). |
| 3 | MOLL-03 SC-2 / HUB-03: 7 resource-paths in Scramble OpenAPI `/docs/api` | VERIFIED | `ScrambleRouteDiscoveryTest`: 11/11 groen (was 7 in initial run; getest met extra paden — geen regressie). |
| 4 | MOLL-03 SC-5: 2× POST /v1/mollie/payments met dezelfde Idempotency-Key → één Mollie-payment-ID | VERIFIED | `MollieIdempotencyForwardTest`: 3/3 groen (`test_two_post_with_same_idempotency_key_returns_same_mollie_payment_id`). PaymentsController gebruikt nu de geërfde `buildClient($request)` op line 43 i.p.v. eigen kopie — identieke runtime-semantiek. |
| 5 | MOLL-04 SC-3 — happy: geldige X-Mollie-Signature → 202 + audit + dispatch | VERIFIED | `MollieWebhookSignatureTest::test_valid_signature_returns_202_and_writes_inbound_audit_row` — onderdeel van 8/8 groen. |
| 6 | MOLL-04 SC-3 — tampered: ongeldige signature → 400 + GEEN dispatch + audit met exception | VERIFIED | `MollieWebhookSignatureTest::test_tampered_signature_returns_400_and_no_dispatch` groen + missing-signature-header pad groen. |
| 7 | MOLL-04 / D-08: Anti-spoofing (resource-ownership-fetch) | VERIFIED | `MollieWebhookAntiSpoofingTest`: 2/2 groen. MollieWebhookController:75-83 doet `Mollie::client()->payments->get($payload['id'])`. |
| 8 | MOLL-04: Fan-out via spatie/laravel-webhook-server naar consumer.webhook_callback_url | VERIFIED | `MollieWebhookFanOutTest`: 3/3 groen. |
| 9 | HUB-03 SC-4: Pass-through audit-rij per call met provider='mollie', path-template, query_keys-only, NULL fingerprint bij empty body | VERIFIED | `MolliePassThroughAuditTest`: 4/4 groen, 24 assertions. |
| 10 | HUB-03: Multi-tenant resolution chain (Bearer → Consumer → Account → mollie-Connection) + cross-Consumer → 404 | VERIFIED | `MolliePassThroughResolutionTest`: 7/7 groen, 15 assertions. |
| 11 | HUB-03 / D-13: Error-mapping (401→502 cloaked, 422→422, 404→404, 429→429+RetryAfter, 5xx→502, timeout→504) | VERIFIED | `MolliePassThroughErrorMappingTest`: 7/7 groen, 21 assertions. (Retry-After header lemma uit WR-04 in initial verification blijft staan als WARNING, niet acceptance-blokkerend.) |
| 12 | **D-06: Consumer Idempotency-Key forward op alle 5 POST-endpoints** | **VERIFIED** | **GAP CLOSED (was FAILED).** `protected function buildClient(Request)` gehoisd naar `AbstractMolliePassThroughController:196` (1 definitie). PaymentsController's eigen kopie verwijderd (`grep -c "protected function buildClient(" PaymentsController.php` = 0). Alle 4 niet-payments-write-controllers gebruiken `$this->buildClient($r)->...->create(...)`: Customers:65, Refunds:35, Subscriptions:54, PaymentLinks:49. Geen directe `Mollie::client()->{resource}->create(...)`-calls meer in de 4 controllers (alle 4 grep-counts = 0). 4 nieuwe IdempotencyForwardTest-files (1251–1464 bytes) bewijzen verbatim-forward per resource — alle bevatten `test_consumer_idempotency_key_is_forwarded`. Run: `php artisan test --compact tests/Feature/Api/V1/Mollie/{Customers,Refunds,Subscriptions,PaymentLinks,Mollie}IdempotencyForwardTest.php` → 7/7 passed, 28 assertions. |
| 13 | **MOLL-04 / D-08 stap 1 / T-05a-06: Hard fail bij ontbrekende platform-webhook-secret** | **VERIFIED** | **GAP CLOSED (was FAILED).** Guard `! is_string($secret) \|\| $secret === ''` op `MollieWebhookController.php:42` — BEFORE `MollieWebhookSignature::verify($request, $secret)` op line 50 (`grep -n` bevestigt line-order). Auditrij krijgt `'webhook_secret_not_configured'` (1× in file). Response: 500 met `error: webhook_misconfigured` (1× in file). Oude silent-cast `(string) config('services.mollie.webhook_secret')` is weg (`grep -c` = 0). 2 nieuwe testpaden `test_null_platform_secret_returns_500_and_does_not_dispatch` (line 174) + `test_empty_string_platform_secret_returns_500_and_does_not_dispatch` (line 201) in `MollieWebhookSignatureTest` — 8/8 groen, 38 assertions. |

**Score:** 13/13 truths verified.

### Deferred Items

Geen — beide gaps zijn intern aan Phase 5a opgelost door plan 05a-06; geen items doorgeschoven naar latere milestone-fases.

### Required Artifacts

| Artifact | Expected | Status | Details |
| -------- | -------- | ------ | ------- |
| `app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php` | Abstract base + buildClient helper (gehoist uit Payments) | VERIFIED | 1× `protected function buildClient(` op line 196; imports `Emeq\MollieApi\Facades\Mollie` + `Mollie\Api\MollieApiClient` aanwezig; doccomment generaliseert naar "gedeeld pad voor alle 5 write-endpoints (D-06)". |
| `app/Http/Controllers/Api/V1/Mollie/PaymentsController.php` | store/show/destroy via geërfde buildClient | VERIFIED | Eigen `buildClient` verwijderd; `$this->buildClient($request)` op line 43 werkt via inheritance. |
| `app/Http/Controllers/Api/V1/Mollie/CustomersController.php` | index/show/store met buildClient-helper | VERIFIED | store():65 gebruikt `$this->buildClient($r)->customers->create($r->validated())`. |
| `app/Http/Controllers/Api/V1/Mollie/PaymentMethodsController.php` | Single-action __invoke | VERIFIED | Niet aangepast door 05a-06 (alleen GET, geen Idempotency-Key forward nodig). |
| `app/Http/Controllers/Api/V1/Mollie/RefundsController.php` | store/index/show nested + standalone met buildClient | VERIFIED | store():35 gebruikt `$this->buildClient($r)->paymentRefunds->createForId(...)`. |
| `app/Http/Controllers/Api/V1/Mollie/MandatesController.php` | index/show/destroy nested | VERIFIED | Niet aangepast door 05a-06 (geen write-create dus D-06 N/A). |
| `app/Http/Controllers/Api/V1/Mollie/SubscriptionsController.php` | index/store/show/destroy nested met buildClient | VERIFIED | store():54 gebruikt `$this->buildClient($r)->subscriptions->createForId(...)`. |
| `app/Http/Controllers/Api/V1/Mollie/PaymentLinksController.php` | index/store/show met buildClient | VERIFIED | store():49 gebruikt `$this->buildClient($r)->paymentLinks->create(...)`. |
| `app/Http/Controllers/Webhooks/MollieWebhookController.php` | __invoke met stap-0 hard-fail guard + signature + connection-lookup + anti-spoof + audit + dispatch | VERIFIED | Lines 41-46: guard fires bij null/empty secret. Lines 50: `MollieWebhookSignature::verify($request, $secret)` — `$secret` nu gegarandeerd niet-leeg na de guard. |
| `app/Jobs/ForwardMollieWebhookToConsumer.php` | Queueable Spatie WebhookCall fan-out | VERIFIED | Geen wijzigingen — `Bus::assertNotDispatched(...)` in de 2 nieuwe webhook-tests bewijst dat guard correct kortsluit. |
| `app/Http/Middleware/ResolveMollieAccount.php` | Middleware met alias resolve.mollie.account | VERIFIED | Niet aangepast door 05a-06; 7/7 ResolutionTest-cases groen. |
| `app/Support/Mollie/MollieUpstreamErrorMapper.php` | Static ::mapException per D-13 | VERIFIED | 7/7 MappingTest-cases groen. |
| `tests/Feature/Api/V1/Mollie/CustomersIdempotencyForwardTest.php` | RED-first test bewijst forward op POST /v1/mollie/customers | VERIFIED | 1251 bytes; bevat `test_consumer_idempotency_key_is_forwarded_on_customer_create`; groen. |
| `tests/Feature/Api/V1/Mollie/RefundsIdempotencyForwardTest.php` | Idem voor refunds | VERIFIED | 1414 bytes; bevat `test_consumer_idempotency_key_is_forwarded_on_refund_create`; groen. |
| `tests/Feature/Api/V1/Mollie/SubscriptionsIdempotencyForwardTest.php` | Idem voor subscriptions | VERIFIED | 1464 bytes; bevat `test_consumer_idempotency_key_is_forwarded_on_subscription_create`; groen. |
| `tests/Feature/Api/V1/Mollie/PaymentLinksIdempotencyForwardTest.php` | Idem voor payment-links | VERIFIED | 1330 bytes; bevat `test_consumer_idempotency_key_is_forwarded_on_payment_link_create`; groen. |
| `tests/Feature/Webhooks/MollieWebhookSignatureTest.php` | 6 bestaande + 2 nieuwe testpaden voor null + empty-string secret | VERIFIED | 8 public test-methods; `test_null_platform_secret_returns_500_and_does_not_dispatch` op line 174, `test_empty_string_platform_secret_returns_500_and_does_not_dispatch` op line 201. 8/8 passed. |
| `tests/Concerns/StubsMollieClient.php` | makeCustomersStub captureert `idempotency_keys` (Rule 3 fix) | VERIFIED | Bevestigd via SUMMARY's self-check; Customers-RED-test groen post-refactor bewijst capture-pad correct werkt. |

### Key Link Verification

| From | To | Via | Status | Details |
| ---- | -- | --- | ------ | ------- |
| AbstractMolliePassThroughController::buildClient | MollieApiClient::setIdempotencyKey | `Mollie::client()->setIdempotencyKey($consumerKey)` op line 202 | WIRED | Single source of truth; alle 5 controllers gebruiken deze helper. |
| CustomersController::store / RefundsController::store / SubscriptionsController::store / PaymentLinksController::store / PaymentsController::store | AbstractMolliePassThroughController::buildClient | `$this->buildClient($r)->...->create(...)` (line 43/65/35/54/49) | WIRED | 4× `$this->buildClient($r)` + 1× `$this->buildClient($request)` — 5 controllers totaal, 5 grep-hits totaal. |
| MollieWebhookController::__invoke (stap 0) | auditFailedWebhook('webhook_secret_not_configured') | `if (! is_string($secret) \|\| $secret === '')` op line 42 → audit + 500 | WIRED | Bewezen door 2 nieuwe testpaden: `test_null_platform_secret_returns_500_and_does_not_dispatch` + `test_empty_string_platform_secret_returns_500_and_does_not_dispatch` (beide assert 500 + audit-row + `Bus::assertNotDispatched`). |
| MollieWebhookController::__invoke (stap 1) | MollieWebhookSignature::verify | `MollieWebhookSignature::verify($request, $secret)` op line 50 — `$secret` post-guard niet-leeg | WIRED | Line-order: guard:42 < verify:50 (grep-verified). |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
| -------- | ------------- | ------ | ------------------ | ------ |
| AbstractMolliePassThroughController::buildClient | `$consumerKey` | `$request->header('Idempotency-Key')` | Bewezen door 4 nieuwe + 3 bestaande IdempotencyForwardTest-cases: captured key === Consumer-header-value | FLOWING |
| MollieWebhookController guard | `$secret` | `config('services.mollie.webhook_secret')` (env-bound, no default) | Productie: kan `null` worden bij ontbrekende env-var; guard vangt dit op via `! is_string($secret) \|\| $secret === ''` | FLOWING |
| Idempotency-Key forward chain | captured `idempotency_keys` array | StubMollieClient::getIdempotencyKey() pre-call hook in stubs (paymentRefunds/subscriptions/paymentLinks + customers na Rule-3-fix) | Real test data: per-controller assertCount(1) + assertSame(Consumer-key) groen | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
| -------- | ------- | ------ | ------ |
| Full test suite | `php artisan test --compact` | 207 passed / 1 incomplete (Phase 4 placeholder) / 0 failed — 697 assertions in 3966ms | PASS |
| 5 idempotency-forward tests | `php artisan test --compact tests/Feature/Api/V1/Mollie/*IdempotencyForwardTest.php` | 7 passed (4 nieuw + 3 bestaand in MollieIdempotencyForwardTest = 7), 28 assertions in 553ms | PASS |
| 8 webhook-signature tests | `php artisan test --compact tests/Feature/Webhooks/MollieWebhookSignatureTest.php` | 8 passed, 38 assertions in 451ms | PASS |
| Mollie + Webhooks full scope | `php artisan test --compact tests/Feature/Api/V1/Mollie/ tests/Feature/Webhooks/` | 62 passed, 248 assertions in 941ms | PASS |
| Spot-check no-regression on 11 prior truths | PaymentsTest 5/5, ScrambleRouteDiscoveryTest 11/11, MollieWebhookAntiSpoofingTest 2/2, MollieWebhookFanOutTest 3/3, MolliePassThroughResolutionTest 7/7, MolliePassThroughErrorMappingTest 7/7, MolliePassThroughAuditTest 4/4, SanctumAbilityTest 5/5 | 44/44 passed | PASS |
| Pint | `./vendor/bin/pint --dirty --test --format agent` | exit 0 (passed) | PASS |
| Grep gates Gap #1 | 12 grep-counts verified (Abstract=1, Payments-own=0, Payments-call=1, 4×Controllers=1, 4×directcreate=0) | All match expected | PASS |
| Grep gates Gap #2 | `webhook_secret_not_configured`=1, `webhook_misconfigured`=1, `(string) cast`=0, `is_string($secret)` line 42 < `MollieWebhookSignature::verify` line 50, 2 test methods present | All match expected | PASS |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
| ----------- | ----------- | ----------- | ------ | -------- |
| MOLL-03 | 05a-03, 05a-04, 05a-05, 05a-06 | Resources + DTOs voor 7 Mollie-resources + Idempotency-Key auto-injectie | SATISFIED | 7 resources callable + Scramble groen. Idempotency-Key auto-injectie via SDK's UuidV7Generator werkt. Consumer-Idempotency-Key forward nu op alle 5 write-endpoints (was payments-only — gap dichtgezet door 05a-06). |
| MOLL-04 | 05a-02, 05a-05, 05a-06 | MollieWebhookVerifier — HMAC-SHA256 (Mollie-Signature) namens platform-secret; happy + tampered paths gedekt | SATISFIED | 8/8 webhook-signature-tests groen + queueable fan-out + anti-spoofing. Lege-secret-misconfiguratie nu hard-fail (gap dichtgezet door 05a-06 stap-0 guard). |
| HUB-03 | 05a-01, 05a-03, 05a-04, 05a-05 | Pass-through REST API `/v1/mollie/*` met Bearer-PAT → Account → Connection.access_token → SDK + audit + Scramble | SATISFIED | Multi-tenant resolution + audit + error-mapping + Scramble alle WIRED. Geen 05a-06 raakvlak. |

**Geen orphaned requirements** — alle MOLL-03 / MOLL-04 / HUB-03 claims door minstens één plan declared en nu volledig satisfied.

### Anti-Patterns Found (post-05a-06)

| File | Line | Pattern | Severity | Impact |
| ---- | ---- | ------- | -------- | ------ |
| app/Http/Controllers/Api/V1/Mollie/PaymentsController.php | 88-105 | `paymentToArray()` private duplicate van `resourceToArray()` op base (REVIEW WR-01) | WARNING | Dode duplicatie; bug-fix drift-risico. Niet acceptance-blokkerend. |
| tests/Feature/Api/V1/Mollie/StubMollieClient.php | 17-18 | Comment-drift `customerMandates` ↔ `mandates` (REVIEW WR-02) | WARNING | Docblock-only. Geen functioneel impact. |
| tests/Concerns/StubsMollieClient.php | 49 | Gedeelde `idempotency_keys`-bucket fragile bij multi-resource testen (REVIEW WR-03) | WARNING | Future-friction; huidige tests werken correct (`assertCount(1)`). |
| tests/Feature/Api/V1/Mollie/*IdempotencyForwardTest.php (4) | (whole file) | Geen no-header-fallback-test + geen dedup-round-trip-test per resource (REVIEW WR-04) | WARNING | Symmetrie-gat met Payments-pattern. Niet acceptance-blokkerend voor D-06. |
| tests/Feature/Webhooks/MollieWebhookSignatureTest.php | 208 | Test-comment ontbreekt rond geforceerde empty-secret sign-call (REVIEW IN-01) | INFO | Leesbaarheid. |
| tests/Feature/Webhooks/MollieWebhookSignatureTest.php | 151 | `fakeMolliePaymentGet()` overbodig in `test_payload_without_id_returns_400_missing_id` (REVIEW IN-02) | INFO | Pre-existing cruft. |
| app/Http/Controllers/Webhooks/MollieWebhookController.php | 45 | 500 zonder Retry-After/Retry-Disable-hint bij hard-fail (REVIEW IN-03) | INFO | Mollie-retry-storm-mitigation. Bewuste choice was 500 voor monitoring-trigger. |
| app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php | 194-195 | Comment-drift over PaymentsController-erfenis (REVIEW IN-04) | INFO | Archeologie-leesbaarheid. |
| app/Http/Controllers/Webhooks/MollieWebhookController.php | 89, 105 | `$request->headers->all()` dump in audit (pre-existing REVIEW WR-01) | WARNING | PII-risico in audit. Buiten 05a-06 scope. |
| app/Http/Requests/Api/V1/Mollie/CreatePaymentRequest.php | 36 | `cancelUrl` veld dat niet op Mollie's Create Payment bestaat (pre-existing WR-02) | WARNING | Verzonnen partner-feature. Buiten 05a-06 scope. |
| app/Http/Requests/Api/V1/Mollie/UpdatePaymentRequest.php | 32 | Idem `cancelUrl` | WARNING | Pre-existing. |
| app/Support/Mollie/MollieUpstreamErrorMapper.php | 76-90 | RateLimitException mapping zonder Retry-After header (pre-existing WR-04) | WARNING | Suboptimaal. |
| app/Models/Consumer.php | 12 | `webhook_callback_url` zonder HTTPS-enforcement (pre-existing WR-05) | WARNING | MITM-vector in callback fan-out. |
| app/Support/Mollie/MollieHeaderForwarder.php | (whole file) | Dead code — geen caller (pre-existing IN-01) | INFO | Cleanup-candidate. |
| app/Support/Mollie/ConsumerIdempotencyKeyGenerator.php | (whole file) | Dead code — geen caller (pre-existing IN-02) | INFO | Cleanup-candidate. |
| app/Http/Requests/Api/V1/Mollie/UpdatePaymentRequest.php | (whole file) | Geen route, speculatief (pre-existing IN-03) | INFO | |
| app/Http/Controllers/Api/V1/Mollie/PaymentsController.php | 44 | `url("/webhooks/...")` ipv `route('webhooks.mollie', ...)` (pre-existing IN-04) | INFO | |

**05a-06 specifieke samenvatting:** 5 BLOCKER anti-patterns uit de initial verification zijn opgelost (Customers/Refunds/Subscriptions/PaymentLinks direct-create calls + MollieWebhookController `(string)`-cast zonder hard-fail). Het 05a-REVIEW.md (focused review op 05a-06 delta) vond 0 critical / 4 warning / 4 info — die warnings + infos zijn hier opgenomen. Geen nieuwe blockers geïntroduceerd.

### Human Verification Required

Drie items uit de initial verification blijven structureel niet programmatisch dekbaar. Deze items werden niet door 05a-06 geraakt en wachten op live-omgeving validatie:

1. **Browser-render /docs/api Scramble UI**
   - Test: Open `http://hub.emeq.test:8090/docs/api` in een browser na `composer install && php artisan serve --port=8001 && docker compose up -d`.
   - Expected: Alle 22 Mollie-routes + 3 OAuth-routes + 3 webhook-routes verschijnen met working "Try it out"-buttons. Payments + Customers + PaymentLinks tonen edit-baar request-body schema.
   - Why human: Scramble UI render kan niet headless gevalideerd worden; OpenAPI-paths zijn wel programmatisch bevestigd maar UI-rendering + Try-it-out-functionaliteit zijn visueel-interactief.

2. **Real Mollie testmode webhook hit**
   - Test: Configureer Mollie testmode-webhook-subscription naar publiek-bereikbare Hub-URL (ngrok of Caddy + DNS), trigger een test-payment.
   - Expected: Mollie's next-gen subscription-webhook (X-Mollie-Signature, JSON body) verifieert correct, anti-spoofing-fetch slaagt, fan-out POST verschijnt op een test-Consumer-callback. Audit-rij in webhook_calls heeft geen exception.
   - Why human: Vereist live Mollie testmode account + Connect-koppeling + publiek bereikbare tunnel. Eind-tot-eind validatie tegen Mollie's eigen signer is niet geautomatiseerd.

3. **End-to-end NSCH-03 smoke (out-of-scope hier, maar bewijst SC-1 + SC-3 + SC-4 hard)**
   - Test: Consumer-PAT met mollie:write + Account-id van een test-school → POST `/v1/mollie/payments` → ontvangt `_links.checkout.href` → ouder doorloopt Mollie test-modus → Mollie post webhook → fan-out succesvol.
   - Expected: Consumer-callback ontvangt een geldig-getekend (Hub-HMAC) payload binnen 5s; school-Mollie-test-dashboard toont de transactie.
   - Why human: End-to-end smoke valt onder Phase 8 NSCH-03; deze Phase 5a-acceptance heeft het pad alleen via stubs gedekt.

### Gaps Summary

Geen blokkerende gaps meer. Beide BLOCKER-truths uit de initial verification (truth #12 D-06 + truth #13 webhook-secret hard-fail) zijn dichtgezet door plan 05a-06:

- **Truth #12 (D-06) GAP CLOSED:** `buildClient(Request)` is gehoist van PaymentsController naar AbstractMolliePassThroughController; alle 5 write-controllers (Customers, Refunds, Subscriptions, PaymentLinks, Payments) gebruiken nu één gedeeld pad voor het forwarden van Consumer's Idempotency-Key-header naar Mollie. Bewezen door 4 nieuwe IdempotencyForwardTest-files + de bestaande MollieIdempotencyForwardTest (3 cases) — 7 tests groen totaal. Geen direct-`Mollie::client()->{resource}->create(...)`-calls meer in de 4 write-controllers (alle 4 grep-counts = 0).

- **Truth #13 (D-08 stap 1 / T-05a-06) GAP CLOSED:** MollieWebhookController heeft nu een stap-0 hard-fail guard die op line 42 (`! is_string($secret) || $secret === ''`) faalt vóór `MollieWebhookSignature::verify` op line 50. Bij null/empty platform-secret retourneert hij 500 + `webhook_misconfigured` + auditrij `webhook_secret_not_configured` + geen ForwardMollieWebhookToConsumer-dispatch. Bewezen door 2 nieuwe testpaden (`test_null_platform_secret_returns_500_and_does_not_dispatch` + `test_empty_string_platform_secret_returns_500_and_does_not_dispatch`). De oude `(string) config(...)`-silent-cast is verwijderd.

**Geen regressies op de 11 eerder-VERIFIED truths.** Spot-check: 44 tests groen over de 8 representatieve test-files (PaymentsTest, ScrambleRouteDiscoveryTest, MollieWebhookAntiSpoofingTest, MollieWebhookFanOutTest, MolliePassThroughResolutionTest, MolliePassThroughErrorMappingTest, MolliePassThroughAuditTest, SanctumAbilityTest).

**Status `human_needed` (niet `passed`)** omdat 3 items uit de initial human-verification-lijst structureel niet programmatisch dekbaar zijn (Scramble UI render, live Mollie webhook hit, NSCH-03 end-to-end smoke). Deze hingen niet aan de gap-closure en wachten op operator-actie / Phase-8 NSCH-03 milestone.

---

_Verified: 2026-05-15T09:00:00Z_
_Verifier: Claude (gsd-verifier)_
_Re-verification: yes (initial run 2026-05-15T02:00:00Z was gaps_found 11/13 → this run 13/13 after 05a-06 merge)_
