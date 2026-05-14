---
phase: 05a-mollie-sdk-resources-webhooks-pass-through-api
verified: 2026-05-15T02:00:00Z
status: gaps_found
score: 11/13 must-haves verified
overrides_applied: 0
re_verification:
  previous_status: none
  previous_score: n/a
  gaps_closed: []
  gaps_remaining: []
  regressions: []
gaps:
  - truth: "D-06: Consumer Idempotency-Key forward op alle 5 POST-endpoints (payments + customers + refunds + subscriptions + payment-links)"
    status: partial
    reason: "Alleen PaymentsController forward't de Consumer Idempotency-Key via buildClient()/setIdempotencyKey(). CustomersController, RefundsController, SubscriptionsController en PaymentLinksController roepen Mollie::client()->...->create() rechtstreeks aan; de SDK-default UuidV7-generator genereert dan een nieuwe key per call. D-06 in 05a-CONTEXT.md zegt expliciet: 'Als Consumer-request een Idempotency-Key-header heeft, forward die letterlijk naar Mollie via SDK' — generiek, niet payments-only. ROADMAP SC-5 is payments-scoped en blijft VERIFIED, maar D-06 is voor 4 van 5 endpoints niet geleverd. Bevestigd door REVIEW CR-01."
    severity: blocker
    artifacts:
      - path: "app/Http/Controllers/Api/V1/Mollie/CustomersController.php"
        issue: "store() roept Mollie::client()->customers->create() rechtstreeks aan; geen Idempotency-Key-forward"
      - path: "app/Http/Controllers/Api/V1/Mollie/RefundsController.php"
        issue: "store() roept rechtstreeks aan; geen Idempotency-Key-forward — financieel risico bij retry-storm (dubbele refund)"
      - path: "app/Http/Controllers/Api/V1/Mollie/SubscriptionsController.php"
        issue: "store() roept rechtstreeks aan; geen Idempotency-Key-forward — boekhoudkundig risico (dubbele Subscription)"
      - path: "app/Http/Controllers/Api/V1/Mollie/PaymentLinksController.php"
        issue: "store() roept rechtstreeks aan; geen Idempotency-Key-forward"
    missing:
      - "Verplaats Idempotency-Key-forward-logica (huidige PaymentsController::buildClient) naar AbstractMolliePassThroughController als helper (e.g. ::mollieClient(Request))"
      - "Refactor de 4 write-controllers om de helper te gebruiken bij ->create()-calls"
      - "Voeg per controller één feature-test toe (analoog aan MollieIdempotencyForwardTest::test_consumer_idempotency_key_is_forwarded_verbatim_to_mollie) voor customers/refunds/subscriptions/payment-links"
  - truth: "MOLL-04 SC-3 / D-08 stap 1: Tampered/missing platform-secret-config faalt closed (hard fail bij MOLLIE_WEBHOOK_SECRET leeg)"
    status: partial
    reason: "MollieWebhookController gebruikt (string) config('services.mollie.webhook_secret') — bij ontbrekende env-var resulteert dit in een lege string ''. Mollie's SignatureValidator accepteert vervolgens elke HMAC die met '' als secret berekend is — een aanvaller die de payload kent kan triviaal een match produceren. config/services.php heeft MOLLIE_WEBHOOK_SECRET zonder default; .env.example heeft MOLLIE_WEBHOOK_SECRET= leeg. Op een production-deploy waar de env-var vergeten is, is webhook-ingress effectief open. Tests gebruiken altijd whsec_test_xyz; deze klasse fouten wordt nooit door tests gevangen. Bevestigd door REVIEW CR-02. SC-3 acceptance (tampered signature → 400) is wel groen onder normale config, maar de defense-in-depth bij misconfiguratie ontbreekt — threat T-05a-06 / D-08 stap 1 verwachten een hard fail."
    severity: blocker
    artifacts:
      - path: "app/Http/Controllers/Webhooks/MollieWebhookController.php"
        issue: "Lines 38-52: geen guard tegen lege/missing webhook_secret; (string)-cast verbergt null silently"
      - path: ".env.example"
        issue: "MOLLIE_WEBHOOK_SECRET= leeg zonder warning of deploy-check"
      - path: "config/services.php"
        issue: "Line 55: 'webhook_secret' => env('MOLLIE_WEBHOOK_SECRET') zonder default of validation"
    missing:
      - "Hard fail in MollieWebhookController::__invoke vóór MollieWebhookSignature::verify: als secret null/'' → 500 + auditFailedWebhook('webhook_secret_not_configured')"
      - "Feature-test: test_missing_platform_secret_returns_500_and_does_not_dispatch in MollieWebhookSignatureTest"
      - "(Optioneel) Boot-time AppServiceProvider-guard die in 'production' env een RuntimeException gooit"
deferred: []
human_verification:
  - test: "Browser-render /docs/api Scramble UI"
    expected: "Alle 22 Mollie-routes + 3 OAuth-routes + 3 webhook-routes verschijnen met working 'Try it out'-buttons. Payments + Customers + PaymentLinks tonen edit-baar request-body schema."
    why_human: "Scramble UI render kan niet headless gevalideerd worden zonder echte browser; OpenAPI-paths zijn wel programmatisch bevestigd (ScrambleRouteDiscoveryTest 7/7 groen) maar UI-rendering + Try-it-out-functionaliteit zelf zijn visueel-interactief."
  - test: "Real Mollie testmode webhook hit naar /webhooks/mollie/{connection_id}"
    expected: "Mollie's next-gen subscription-webhook (X-Mollie-Signature, JSON body) verifieert correct, antifoofing-fetch slaagt, fan-out POST verschijnt op een test-Consumer-callback (bv. https://webhook.site). Audit-rij in webhook_calls heeft geen exception."
    why_human: "Vereist een live Mollie testmode account + Connect-koppeling + publiek bereikbare ngrok-/Caddy-tunnel. SDK-pad is via MollieWebhookSignature-helper getest met stubs maar de eind-tot-eind validatie tegen Mollie's eigen signer is niet geautomatiseerd."
  - test: "Concrete POST /v1/mollie/payments → ouder doorloopt Mollie test-mode → webhook ontvangen"
    expected: "Naschool-scenario (NSCH-03 dependency): Consumer-PAT met mollie:write + Account-id van een test-school stuurt POST payment → ontvangt _links.checkout.href → ouder doorloopt Mollie test-modus → Mollie post webhook naar Hub → fan-out naar Naschool-callback succesvol."
    why_human: "End-to-end smoke met echte Mollie test-omgeving is buiten scope van Phase 5a (valt onder Phase 8 NSCH-03) maar bewijst SC-1 + SC-3 + SC-4 hard."
---

# Phase 5a: Mollie SDK Resources + Webhooks + Pass-through API Verification Report

**Phase Goal:** Deliver Mollie SDK resources (Payments, Customers, PaymentMethods, Refunds, Mandates, Subscriptions, PaymentLinks) as pass-through `/v1/mollie/*` endpoints, plus webhook ingress + fan-out, plus Scramble OpenAPI discovery, plus SanctumAbility gating.
**Verified:** 2026-05-15T02:00:00Z
**Status:** gaps_found
**Re-verification:** No — initial verification
**Test suite:** 201 passed / 1 incomplete (Phase 4 placeholder, unrelated) / 0 failed — 675 assertions in ~3.7s

## Goal Achievement

### Observable Truths (consolidated from 5 plan-frontmatter must-haves + 5 ROADMAP SCs)

| #   | Truth | Status | Evidence |
| --- | ----- | ------ | -------- |
| 1   | MOLL-03 SC-1: POST /v1/mollie/payments retourneert Mollie's `_links.checkout.href` | VERIFIED | `tests/Feature/Api/V1/Mollie/PaymentsTest.php:21-45` `test_post_payments_proxies_through_sdk_and_returns_201_with_mollie_payload` asserteert `_links.checkout.href = https://mollie.test/checkout/tr_happy_1`. PaymentsController::store passes payload via `Mollie::client()->payments->create()`; response body is verbatim Mollie-shape. |
| 2   | MOLL-03 SC-2: Alle 7 Mollie-resources callable via `/v1/mollie/*` | VERIFIED | `php artisan route:list` toont 22 mollie-routes (route:list output): payments (3) + customers (3) + payment-methods (1) + refunds (3) + mandates (3) + subscriptions (4) + payment-links (3) + 2 nested. Alle 7 ResourceControllers extenden AbstractMolliePassThroughController. |
| 3   | MOLL-03 SC-2 / HUB-03: 7 resource-paths in Scramble OpenAPI `/docs/api` | VERIFIED | `tests/Feature/Documentation/ScrambleRouteDiscoveryTest.php` 7 tests groen: test_openapi_spec_contains_mollie_{payments,customers,payment_methods,refunds,mandates,subscriptions,payment_links}_routes. Programmatic dump bevestigt: `/mollie/payments`, `/mollie/customers`, `/mollie/payment-methods`, `/mollie/payment-links`, `/mollie/payments/{payment_id}/refunds`, `/mollie/customers/{customer_id}/mandates`, `/mollie/customers/{customer_id}/subscriptions`. |
| 4   | MOLL-03 SC-5: 2× POST /v1/mollie/payments met dezelfde Idempotency-Key → één Mollie-payment-ID | VERIFIED | `tests/Feature/Api/V1/Mollie/MollieIdempotencyForwardTest.php:26` `test_two_post_with_same_idempotency_key_returns_same_mollie_payment_id` groen. PaymentsController::buildClient leest `Idempotency-Key`-header en forward't via `MollieApiClient::setIdempotencyKey()`. **NB:** Scope is payments — andere 4 POST-endpoints zijn buiten SC-5 acceptance maar in scope D-06 (zie gap #1). |
| 5   | MOLL-04 SC-3 — happy: geldige X-Mollie-Signature → 202 + audit + dispatch | VERIFIED | `tests/Feature/Webhooks/MollieWebhookSignatureTest.php:32` `test_valid_signature_returns_202_and_writes_inbound_audit_row` groen — assert 202, webhook_calls-rij zonder exception, `ForwardMollieWebhookToConsumer::dispatch` aangeroepen. |
| 6   | MOLL-04 SC-3 — tampered: ongeldige signature → 400 + GEEN dispatch + audit met exception | VERIFIED | `MollieWebhookSignatureTest::test_tampered_signature_returns_400_and_no_dispatch` groen — assert 400, `invalid_signature`, `Bus::assertNotDispatched`, audit-rij heeft exception-veld. Aanvullend: `test_missing_signature_header_returns_400_with_missing_signature` voor het ontbrekende-header-pad. |
| 7   | MOLL-04 / D-08: Anti-spoofing (resource-ownership-fetch) | VERIFIED | `tests/Feature/Webhooks/MollieWebhookAntiSpoofingTest.php`: 2 tests (`test_webhook_for_id_that_returns_404_from_mollie_returns_400_resource_ownership_failed` + `test_webhook_for_id_that_returns_auth_error_from_mollie_returns_400`). MollieWebhookController:75-83 doet `Mollie::client()->payments->get($payload['id'])` na connection-binding. |
| 8   | MOLL-04: Fan-out via spatie/laravel-webhook-server naar consumer.webhook_callback_url | VERIFIED | `tests/Feature/Webhooks/MollieWebhookFanOutTest.php`: 3 tests (dispatch verify + Spatie WebhookCall->url/payload/useSecret + silent no-callback-url). `app/Jobs/ForwardMollieWebhookToConsumer.php` wraps Spatie's `WebhookCall::create()`. |
| 9   | HUB-03 SC-4: Pass-through audit-rij per call met provider='mollie', path-template, query_keys-only, NULL fingerprint bij empty body | VERIFIED | `tests/Feature/Api/V1/Mollie/MolliePassThroughAuditTest.php`: 4 tests groen (path-template-after-POST, query_keys-only, NULL-fingerprint-on-empty-body, geen access_token/credentials in audit). pass_through_calls tabel hergebruikt uit Phase 5b conform D-05 ADR. |
| 10  | HUB-03: Multi-tenant resolution chain (Bearer → Consumer → Account → mollie-Connection) + cross-Consumer → 404 | VERIFIED | `tests/Feature/Api/V1/Mollie/MolliePassThroughResolutionTest.php`: 7 tests (happy + missing-header + unknown-account + cross-Consumer-404 + no-active-mollie-connection + only-snelstart-404 + unauthenticated-401). ResolveMollieAccount middleware (alias `resolve.mollie.account`) aangedreven door Phase 4 MollieConnectionContext. |
| 11  | HUB-03 / D-13: Error-mapping (401→502 cloaked, 422→422, 404→404, 429→429+RetryAfter, 5xx→502, timeout→504) | VERIFIED | `tests/Feature/Api/V1/Mollie/MolliePassThroughErrorMappingTest.php`: 7 tests groen. MollieUpstreamErrorMapper static mapper conform D-13 tabel. **Caveat:** Retry-After header ontbreekt op 429 (REVIEW WR-04) — niet roadmap-acceptance-blokkerend maar suboptimaal. |
| 12  | D-06: Consumer Idempotency-Key forward op alle 5 POST-endpoints | FAILED | Alleen PaymentsController gebruikt `buildClient()` met setIdempotencyKey. Customers/Refunds/Subscriptions/PaymentLinks roepen rechtstreeks aan en negeren Consumer's header. Bevestigd door REVIEW CR-01. Zie gap #1. |
| 13  | MOLL-04 / D-08 stap 1 / T-05a-06: Hard fail bij ontbrekende platform-webhook-secret | FAILED | MollieWebhookController gebruikt `(string) config('services.mollie.webhook_secret')` → '' bij ontbrekende env. SignatureValidator accepteert dan elke HMAC die met '' berekend is. Geen runtime-guard, geen test. Bevestigd door REVIEW CR-02. Zie gap #2. |

**Score:** 11/13 truths verified

### Deferred Items

Geen gefilterde items — beide gaps zijn 5a-internal concerns, niet addressed door latere milestone-fases (Phase 6 Cashier-Mollie raakt subscriptions maar lost geen Mollie-pass-through-idempotency op; Phase 7 doet account-level subs bovenop deze pass-through; Phase 8 Naschool consumeert de bestaande endpoints).

### Required Artifacts

| Artifact | Expected | Status | Details |
| -------- | -------- | ------ | ------- |
| `app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php` | Abstract base met handle(Request, $endpoint, callable): Response | VERIFIED | 184 lines; extends Controller; `handle()` method aanwezig + `resourceToArray`/`collectionToArray` helpers; gewired vanaf alle 7 concrete controllers. |
| `app/Http/Controllers/Api/V1/Mollie/PaymentsController.php` | store/show/destroy + buildClient Idempotency-forward | VERIFIED | Extends AbstractMolliePassThroughController; 3 acties; webhookUrl-auto-injectie; buildClient setIdempotencyKey(). |
| `app/Http/Controllers/Api/V1/Mollie/CustomersController.php` | index/show/store | VERIFIED — but **HOLLOW for D-06** | Bestaat, gewired, tests groen. `store()` roept echter Mollie::client()->customers->create() rechtstreeks aan zonder Idempotency-Key-forward. |
| `app/Http/Controllers/Api/V1/Mollie/PaymentMethodsController.php` | Single-action __invoke | VERIFIED | List-only, GET /v1/mollie/payment-methods. |
| `app/Http/Controllers/Api/V1/Mollie/RefundsController.php` | store/index/show nested + standalone | VERIFIED — but **HOLLOW for D-06** | Idem CustomersController issue. |
| `app/Http/Controllers/Api/V1/Mollie/MandatesController.php` | index/show/destroy nested | VERIFIED | DELETE → revoke; geen write-create dus D-06 N/A. |
| `app/Http/Controllers/Api/V1/Mollie/SubscriptionsController.php` | index/store/show/destroy nested | VERIFIED — but **HOLLOW for D-06** | Idem CustomersController issue. |
| `app/Http/Controllers/Api/V1/Mollie/PaymentLinksController.php` | index/store/show | VERIFIED — but **HOLLOW for D-06** | Idem CustomersController issue. |
| `app/Http/Controllers/Webhooks/MollieWebhookController.php` | __invoke met signature + connection-lookup + anti-spoof + audit + dispatch | VERIFIED — but **CR-02 BLOCKER** | Volledig flow geïmplementeerd; mist hard-fail bij lege secret. |
| `app/Jobs/ForwardMollieWebhookToConsumer.php` | Queueable Spatie WebhookCall fan-out | VERIFIED | Spatie\WebhookServer\WebhookCall::create()->url()->payload()->useSecret()->dispatch(). |
| `app/Http/Middleware/ResolveMollieAccount.php` | Middleware met alias resolve.mollie.account | VERIFIED | 69 lines; gewired in bootstrap/app.php als alias 'resolve.mollie.account'. |
| `app/Support/Mollie/MollieUpstreamErrorMapper.php` | Static ::mapException per D-13 | VERIFIED | 119 lines; 7 mapping-paden + Retry-After-leemte gedocumenteerd. |
| `app/Support/Mollie/MollieHeaderForwarder.php` | Static ::forward(Request) header-whitelist | VERIFIED — but **ORPHANED** (REVIEW IN-01) | Bestaat, geen caller. Dead code per IN-01. Niet roadmap-acceptance-blokkerend. |
| `database/migrations/2026_05_16_000001_add_webhook_callback_to_consumers_table.php` | Schema-add webhook_callback_url + webhook_callback_secret | VERIFIED | Migration heeft up() + down(); webhook_callback_secret encrypted-cast op Consumer-model. |
| `routes/api.php` | 22 mollie-routes onder prefix mollie + middleware resolve.mollie.account | VERIFIED | Route::prefix('mollie')->middleware(['ability:...', 'resolve.mollie.account']) groepen; mollie:read voor GET, mollie:write voor POST/DELETE. |
| `routes/webhooks.php` | POST /webhooks/mollie/{connection_id} no auth | VERIFIED | Geregistreerd; route name webhooks.mollie. |
| `bootstrap/app.php` | Middleware alias + routes/webhooks.php geladen | VERIFIED | Alias declared; routes/webhooks.php in withRouting. |
| `config/mollie.php` | UuidV7IdempotencyKeyGenerator binding | VERIFIED | idempotency.generator key wijst naar UuidV7IdempotencyKeyGenerator (gevalideerd via PaymentsTest fallback-test). |
| `config/services.php` | mollie.webhook_secret env-binding | VERIFIED — but **CR-02 BLOCKER** (geen default + geen guard) | Line 55: 'webhook_secret' => env('MOLLIE_WEBHOOK_SECRET'). |

### Key Link Verification

| From | To | Via | Status | Details |
| ---- | -- | --- | ------ | ------- |
| ResolveMollieAccount middleware | MollieConnectionContext (Phase 4 scoped singleton) | app(MollieConnectionContext::class)->set($connection) | WIRED | Bewezen in MolliePassThroughResolutionTest::test_happy_path_sets_attributes_and_mollie_connection_context. |
| AbstractMolliePassThroughController | MollieUpstreamErrorMapper | MollieUpstreamErrorMapper::mapException($throwable) | WIRED | Bewezen in MolliePassThroughErrorMappingTest 7 cases. |
| AbstractMolliePassThroughController | pass_through_calls audit-tabel | PassThroughCall::create(['provider' => 'mollie', ...]) | WIRED | Bewezen in MolliePassThroughAuditTest 4 cases. |
| MollieWebhookController | MollieWebhookSignature::verify (SDK helper) | MollieWebhookSignature::verify($request, config(...)) | WIRED — but **CR-02** | Geverifieerd in MollieWebhookSignatureTest 6 cases; **lege secret-config-pad ontbreekt**. |
| MollieWebhookController | ForwardMollieWebhookToConsumer | ForwardMollieWebhookToConsumer::dispatch($connection, $payload) | WIRED | Bewezen in MollieWebhookFanOutTest. |
| ForwardMollieWebhookToConsumer | Spatie WebhookCall (laravel-webhook-server) | Spatie\WebhookServer\WebhookCall::create()->url()->payload()->useSecret()->dispatch() | WIRED | Bewezen in MollieWebhookFanOutTest::test_forward_job_handle_calls_spatie_webhook_server_with_consumer_callback. |
| PaymentsController::store | Mollie::client()->payments->create | $client->payments->create($payload) | WIRED | PaymentsTest 5 cases. |
| Idempotency-Key Consumer-header → MollieApiClient | $client->setIdempotencyKey($consumerKey) | PaymentsController::buildClient | WIRED voor payments — **NOT WIRED voor 4 andere POST-endpoints** | MollieIdempotencyForwardTest dekt alleen payments; CR-01. |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
| -------- | ------------- | ------ | ------------------ | ------ |
| PaymentsController::store response body | `$payment` resource | Mollie SDK `$client->payments->create($payload)` | Echte Mollie-shape (test-stub `MockResponse::ok([...])` retourneert _links/_embedded) — productie ook | FLOWING |
| Scramble `/docs/api.json` paths | OpenAPI paths-array | dedoc/scramble vendor middleware → route:list scan | Alle 22 mollie-routes verschijnen programmatisch (geverifieerd via kernel-handle + json_decode) | FLOWING |
| MollieWebhookController fan-out payload | `$payload` array | `$request->json()->all()` | Productie-shape: Mollie next-gen webhook = JSON body met `id`-veld (zie .docs/partners/mollie/webhooks-overview.md). Tests volgen deze shape. **NB:** REVIEW WR-06 noemde form-encoded risico, maar Mollie's docs zelf zeggen next-gen webhooks zijn JSON (legacy v1 was form-encoded). Phase 5a gebruikt next-gen expliciet. | FLOWING |
| Audit row pass_through_calls | request_fingerprint/path/query_keys | AbstractMolliePassThroughController::audit | Hash + endpoint-template + queryKeys array; geen credentials. Bewezen door audit-tests. | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
| -------- | ------- | ------ | ------ |
| Full test suite | `php artisan test --compact` | 201 passed / 1 incomplete (Phase 4 placeholder, ongerelateerd) / 0 failed | PASS |
| Phase 5a scoped tests | `php artisan test --compact tests/Feature/Documentation/ScrambleRouteDiscoveryTest.php tests/Feature/Api/SanctumAbilityTest.php tests/Feature/Webhooks/ tests/Feature/Api/V1/Mollie/` | 72 passed / 0 failed | PASS |
| Mollie routes geregistreerd | `php artisan route:list --path=mollie` | 22 mollie + 3 oauth-mollie + 1 webhooks.mollie routes | PASS |
| OpenAPI paths gerenderd | Bootstrap + dispatch GET /docs/api.json | 7 distinct mollie-resource-paden present | PASS |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
| ----------- | ----------- | ----------- | ------ | -------- |
| MOLL-03 | 05a-03, 05a-04, 05a-05 | Resources + DTOs voor 7 Mollie-resources + Idempotency-Key auto-injectie | PARTIAL | 7 resources callable + Scramble groen. Idempotency-Key auto-injectie via SDK's UuidV7Generator werkt. **Consumer-Idempotency-Key forward** alleen op payments — andere 4 POSTs gap (CR-01). |
| MOLL-04 | 05a-02, 05a-05 | MollieWebhookVerifier — HMAC-SHA256 (Mollie-Signature) namens platform-secret; happy + tampered paths gedekt | PARTIAL | 6 webhook-tests groen + queueable fan-out + anti-spoofing. **Lege-secret-misconfiguratie** is een blockable gap (CR-02). |
| HUB-03 | 05a-01, 05a-03, 05a-04, 05a-05 | Pass-through REST API `/v1/mollie/*` met Bearer-PAT → Account → Connection.access_token → SDK + audit + Scramble | SATISFIED | Multi-tenant resolution + audit + error-mapping + Scramble alle WIRED. |

**Geen orphaned requirements** — alle MOLL-03 / MOLL-04 / HUB-03 claims door minstens één plan declared. Geen requirement-IDs in REQUIREMENTS.md die deze fase claimt maar geen plan dekt.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
| ---- | ---- | ------- | -------- | ------ |
| app/Http/Controllers/Api/V1/Mollie/CustomersController.php | 65 | Direct Mollie::client()->...->create() bypass van buildClient | BLOCKER | D-06 violation (CR-01) |
| app/Http/Controllers/Api/V1/Mollie/RefundsController.php | 35 | Direct create-call bypass | BLOCKER | D-06 violation — financieel risico |
| app/Http/Controllers/Api/V1/Mollie/SubscriptionsController.php | 54 | Direct create-call bypass | BLOCKER | D-06 violation — dubbele subs |
| app/Http/Controllers/Api/V1/Mollie/PaymentLinksController.php | 49 | Direct create-call bypass | BLOCKER | D-06 violation |
| app/Http/Controllers/Webhooks/MollieWebhookController.php | 41 | `(string) config('services.mollie.webhook_secret')` zonder hard-fail | BLOCKER | CR-02 — lege secret = open ingress |
| app/Http/Controllers/Webhooks/MollieWebhookController.php | 89, 105 | `$request->headers->all()` dump in audit | WARNING | REVIEW WR-01 — headers-payload mogelijk PII |
| app/Http/Requests/Api/V1/Mollie/CreatePaymentRequest.php | 36 | `cancelUrl` validate maar bestaat niet op Mollie's Create Payment | WARNING | REVIEW WR-02 — verzonnen partner-feature |
| app/Http/Requests/Api/V1/Mollie/UpdatePaymentRequest.php | 32 | Idem cancelUrl | WARNING | REVIEW WR-02 |
| app/Support/Mollie/MollieUpstreamErrorMapper.php | 76-90 | RateLimitException mapping zonder Retry-After header | WARNING | REVIEW WR-04 |
| app/Models/Consumer.php | 12 | `webhook_callback_url` string zonder HTTPS-enforcement | WARNING | REVIEW WR-05 — MITM-vector |
| app/Http/Controllers/Webhooks/MollieWebhookController.php | 68, 106 | `$request->json()->all()` — legacy form-encoded niet ondersteund | INFO | REVIEW WR-06; Mollie's next-gen webhooks zijn JSON per docs; not a real production gap voor scope. |
| app/Support/Mollie/MollieHeaderForwarder.php | (whole file) | Dead code — geen caller | INFO | REVIEW IN-01 |
| app/Support/Mollie/ConsumerIdempotencyKeyGenerator.php | (whole file) | Dead code — geen caller | INFO | REVIEW IN-02 |
| app/Http/Requests/Api/V1/Mollie/UpdatePaymentRequest.php | (whole file) | Geen route, speculatief | INFO | REVIEW IN-03 |
| app/Http/Controllers/Api/V1/Mollie/PaymentsController.php | 44 | `url("/webhooks/mollie/...")` ipv `route('webhooks.mollie', ...)` | INFO | REVIEW IN-04 |
| app/Http/Requests/Api/V1/Mollie/CreateSubscriptionRequest.php | 42 | interval-regex te zwak (accepteert 0-prefix) | INFO | REVIEW IN-05 |

### Human Verification Required

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

Twee BLOCKER-issues, beide reeds geflagd in `05a-REVIEW.md` (CR-01 + CR-02), worden bevestigd door codebase-grep en bestaande test-coverage:

**Gap 1 — D-06 incomplete (CR-01).** `PaymentsController` forward't Consumer's `Idempotency-Key`-header naar Mollie via `MollieApiClient::setIdempotencyKey()`; `CustomersController`, `RefundsController`, `SubscriptionsController` en `PaymentLinksController` doen dat NIET — ze roepen `Mollie::client()->...->create()` rechtstreeks aan en gebruiken de SDK-default UuidV7-generator (een fresh key per call). D-06 in `05a-CONTEXT.md` zegt expliciet "Als Consumer-request een Idempotency-Key-header heeft, forward die letterlijk naar Mollie via SDK" — generiek, niet payments-only. ROADMAP SC-5 is wel payments-scoped en blijft VERIFIED, maar de claim in 05a-CONTEXT.md D-06 dat "alle write-endpoints forwarden" is feitelijk onwaar. Financieel risico bij refunds (dubbele refund) en boekhoudkundig risico bij subscriptions (dubbele Subscription / debit). Fix: helper-methode op `AbstractMolliePassThroughController` (zie REVIEW CR-01 fix-blok).

**Gap 2 — Empty-secret webhook ingress (CR-02).** `MollieWebhookController` doet `(string) config('services.mollie.webhook_secret')`; bij ontbrekende env-var resulteert dit in `''`. Mollie's `SignatureValidator` accepteert vervolgens elke HMAC die met `''` berekend is — een aanvaller die de payload kent kan triviaal een match produceren. `config/services.php` heeft `MOLLIE_WEBHOOK_SECRET` zonder default; `.env.example` heeft `MOLLIE_WEBHOOK_SECRET=` leeg. Op een production-deploy waar de env-var vergeten is, is webhook-ingress effectief open. Tests gebruiken altijd een geldige `whsec_test_xyz`-config; deze klasse fouten wordt nooit door tests gevangen. Threat T-05a-06 / D-08 stap 1 verwachten een hard fail. Fix: guard vóór `MollieWebhookSignature::verify()` + boot-time check + dedicated test (zie REVIEW CR-02 fix-blok).

**Gaps related — proposed grouping for `/gsd-plan-phase --gaps`:** beide gaps zijn pure controller-laag chirurgie en kunnen samen in één korte hardening-plan landen. Geen migration-changes nodig, geen route-changes, geen externe afhankelijkheden. Realistisch in één korte plan-cycle (1 task, 2 commits: RED-test → GREEN-fix).

---

_Verified: 2026-05-15T02:00:00Z_
_Verifier: Claude (gsd-verifier)_
