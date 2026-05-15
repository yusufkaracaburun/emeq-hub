---
phase: 05a-mollie-sdk-resources-webhooks-pass-through-api
plan: 06
type: execute
wave: 5
depends_on: [05a-01, 05a-02, 05a-03, 05a-04, 05a-05]
gap_closure: true
autonomous: true
requirements: [MOLL-03, MOLL-04, HUB-03]
tags: [mollie, webhooks, idempotency, security, gap-closure]
files_modified:
  - app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php
  - app/Http/Controllers/Api/V1/Mollie/PaymentsController.php
  - app/Http/Controllers/Api/V1/Mollie/CustomersController.php
  - app/Http/Controllers/Api/V1/Mollie/RefundsController.php
  - app/Http/Controllers/Api/V1/Mollie/SubscriptionsController.php
  - app/Http/Controllers/Api/V1/Mollie/PaymentLinksController.php
  - app/Http/Controllers/Webhooks/MollieWebhookController.php
  - tests/Feature/Api/V1/Mollie/CustomersIdempotencyForwardTest.php
  - tests/Feature/Api/V1/Mollie/RefundsIdempotencyForwardTest.php
  - tests/Feature/Api/V1/Mollie/SubscriptionsIdempotencyForwardTest.php
  - tests/Feature/Api/V1/Mollie/PaymentLinksIdempotencyForwardTest.php
  - tests/Feature/Webhooks/MollieWebhookSignatureTest.php

must_haves:
  truths:
    - "Alle 4 niet-payments write-controllers (Customers, Refunds, Subscriptions, PaymentLinks) forwarden Consumer's Idempotency-Key-header verbatim naar Mollie via AbstractMolliePassThroughController-helper (D-06)."
    - "PaymentsController gebruikt dezelfde helper als de andere 4 controllers — geen eigen buildClient-implementatie meer (één pad voor alle 5 write-endpoints)."
    - "Empty/null MOLLIE_WEBHOOK_SECRET in config → MollieWebhookController retourneert 500 + auditrij met exception 'webhook_secret_not_configured' + GEEN ForwardMollieWebhookToConsumer-dispatch (D-08 stap 1 hard-fail vóór MollieWebhookSignature::verify)."
    - "5 nieuwe feature-tests groen + bestaande 201/201 suite blijft groen + Pint clean."
  artifacts:
    - path: "app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php"
      provides: "buildClient(Request)-helper die Consumer Idempotency-Key forward't naar MollieApiClient::setIdempotencyKey()"
      contains: "protected function buildClient("
    - path: "app/Http/Controllers/Webhooks/MollieWebhookController.php"
      provides: "Hard-fail guard vóór signature-verify bij empty/null webhook_secret"
      contains: "webhook_secret_not_configured"
    - path: "tests/Feature/Api/V1/Mollie/CustomersIdempotencyForwardTest.php"
      provides: "Bewijst Idempotency-Key forward op POST /v1/mollie/customers"
      contains: "test_consumer_idempotency_key_is_forwarded"
    - path: "tests/Feature/Api/V1/Mollie/RefundsIdempotencyForwardTest.php"
      provides: "Bewijst Idempotency-Key forward op POST /v1/mollie/payments/{id}/refunds"
      contains: "test_consumer_idempotency_key_is_forwarded"
    - path: "tests/Feature/Api/V1/Mollie/SubscriptionsIdempotencyForwardTest.php"
      provides: "Bewijst Idempotency-Key forward op POST /v1/mollie/customers/{id}/subscriptions"
      contains: "test_consumer_idempotency_key_is_forwarded"
    - path: "tests/Feature/Api/V1/Mollie/PaymentLinksIdempotencyForwardTest.php"
      provides: "Bewijst Idempotency-Key forward op POST /v1/mollie/payment-links"
      contains: "test_consumer_idempotency_key_is_forwarded"
  key_links:
    - from: "AbstractMolliePassThroughController::buildClient"
      to: "MollieApiClient::setIdempotencyKey"
      via: "Mollie::client()->setIdempotencyKey($consumerKey)"
      pattern: "setIdempotencyKey\\("
    - from: "CustomersController::store / RefundsController::store / SubscriptionsController::store / PaymentLinksController::store"
      to: "AbstractMolliePassThroughController::buildClient"
      via: "$this->buildClient($r)->...->create(...)"
      pattern: "\\$this->buildClient\\("
    - from: "MollieWebhookController::__invoke"
      to: "auditFailedWebhook('webhook_secret_not_configured')"
      via: "guard vóór MollieWebhookSignature::verify"
      pattern: "webhook_secret_not_configured"
---

<objective>
Sluit de twee BLOCKER-gaps uit `05a-VERIFICATION.md` (truths #12 + #13, beide bevestigd door `05a-REVIEW.md` CR-01 + CR-02):

1. **Gap #1 (D-06 / CR-01):** Hijs de `Idempotency-Key`-forward-helper uit `PaymentsController::buildClient` (lines 91-101) naar `AbstractMolliePassThroughController` zodat de 4 andere POST-controllers (Customers, Refunds, Subscriptions, PaymentLinks) Consumer-headers verbatim doorgeven aan Mollie i.p.v. ze stilzwijgend weg te gooien.

2. **Gap #2 (D-08 stap 1 / CR-02):** Faal closed in `MollieWebhookController::__invoke` wanneer `config('services.mollie.webhook_secret')` null of leeg is — vóór de signature-verify-stap. Schrijf een auditrij met `webhook_secret_not_configured` en retourneer 500 zodat de fout zichtbaar wordt op deploy i.p.v. een silent-open ingress.

Purpose: Sluit de twee FAILED truths uit `05a-VERIFICATION.md` zodat Phase 5a 13/13 verified haalt en Phase 8 (NSCH-03) op een correcte basis kan landen. Dubbele refunds + dubbele subscriptions bij retry-storms zijn direct financieel risico; een lege webhook-secret is een open ingress in productie.

Output: 7 gewijzigde productie-files + 4 nieuwe feature-tests + 1 uitgebreide bestaande webhook-test. Geen migration, geen route-change, geen externe dependency, geen nieuwe SDK-abilities.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@./CLAUDE.md
@./.ai/rules/global.md
@./.ai/rules/engineering.md
@.planning/STATE.md
@.planning/ROADMAP.md
@.planning/REQUIREMENTS.md
@.planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-CONTEXT.md
@.planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-VERIFICATION.md
@.planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-REVIEW.md
@.planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-PATTERNS.md
@app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php
@app/Http/Controllers/Api/V1/Mollie/PaymentsController.php
@app/Http/Controllers/Api/V1/Mollie/CustomersController.php
@app/Http/Controllers/Api/V1/Mollie/RefundsController.php
@app/Http/Controllers/Api/V1/Mollie/SubscriptionsController.php
@app/Http/Controllers/Api/V1/Mollie/PaymentLinksController.php
@app/Http/Controllers/Webhooks/MollieWebhookController.php
@tests/Feature/Api/V1/Mollie/MollieIdempotencyForwardTest.php
@tests/Feature/Webhooks/MollieWebhookSignatureTest.php
@tests/Concerns/StubsMollieClient.php

<interfaces>
<!-- Sleutel-contracten voor de executor — direct uit codebase geëxtraheerd. -->
<!-- GEEN scavenger-hunt nodig: alles wat executor moet weten staat hier. -->

# Huidige PaymentsController helper (te hoisen, lines 85-101)

```php
/**
 * Bouwt een MollieApiClient voor de huidige request. Forward't de
 * Consumer's Idempotency-Key-header naar Mollie via de runtime-setter
 * (verifieerd in 05a-03-PREFLIGHT.md V1). De default UuidV7-generator
 * blijft de fallback zonder Consumer-header.
 */
protected function buildClient(Request $request): MollieApiClient
{
    $client = Mollie::client();

    $consumerKey = $request->header('Idempotency-Key');
    if (is_string($consumerKey) && $consumerKey !== '') {
        $client->setIdempotencyKey($consumerKey);
    }

    return $client;
}
```

# AbstractMolliePassThroughController — huidige imports (uitbreiden)

```php
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Connection;
use App\Models\PassThroughCall;
use App\Sanctum\TokenAbilities;
use App\Support\Mollie\MollieUpstreamErrorMapper;
use Illuminate\Http\Request;
use Mollie\Api\Resources\BaseCollection;
use Mollie\Api\Resources\BaseResource;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
```

Te toevoegen aan de imports van de Abstract:
```php
use Emeq\MollieApi\Facades\Mollie;
use Mollie\Api\MollieApiClient;
```

# Huidige 4 direct-call locaties (te refactoren)

CustomersController.php:65       — `Mollie::client()->customers->create($r->validated())`
RefundsController.php:35         — `Mollie::client()->paymentRefunds->createForId($payment_id, $r->validated())`
SubscriptionsController.php:54   — `Mollie::client()->subscriptions->createForId($customer_id, $r->validated())`
PaymentLinksController.php:49    — `Mollie::client()->paymentLinks->create($r->validated())`

# Huidige MollieWebhookController guard-locatie (lines 36-52)

```php
public function __invoke(Request $request, int $connection_id): JsonResponse
{
    // 1. Signature-verify
    try {
        $valid = MollieWebhookSignature::verify(
            $request,
            (string) config('services.mollie.webhook_secret'),
        );
    } catch (InvalidSignatureException $e) {
        $this->auditFailedWebhook($request, "invalid_signature: {$e->getMessage()}");

        return response()->json(['error' => 'invalid_signature'], 400);
    }
    if (! $valid) {
        $this->auditFailedWebhook($request, 'missing_signature_header');

        return response()->json(['error' => 'missing_signature'], 400);
    }
    // ... rest van flow
}
```

# auditFailedWebhook signature (lines 100-109)

```php
private function auditFailedWebhook(Request $request, string $exception): void
{
    WebhookCall::create([
        'name' => 'mollie',
        'url' => $request->fullUrl(),
        'headers' => $request->headers->all(),
        'payload' => $request->json()->all() ?: ['_raw' => substr($request->getContent(), 0, 1000)],
        'exception' => $exception,
    ]);
}
```

# Test-trait API (uit StubsMollieClient)

```php
// Stub-binding (per resource):
$this->bindMollieStubs([
    'customers' => fn (string $op, mixed $arg) => $this->makeCustomer([...]),       // $op = create|get|page
    'paymentRefunds' => fn (string $op, mixed $arg) => $this->makeRefund([...]),    // $op = createForId|pageForId|getForId
    'subscriptions' => fn (string $op, mixed $arg) => $this->makeSubscription([...]),// $op = createForId|...
    'paymentLinks' => fn (string $op, mixed $arg) => $this->makePaymentLink([...]), // $op = create|get|page
]);

// Setup + HTTP-helpers:
[$consumer, $token, $account, $connection] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);
$response = $this->callMollie($token, 'POST', '/v1/mollie/customers', $payload, ['Idempotency-Key' => 'my-key-xyz']);

// Asserties op gecapturede keys (het ENIGE bewijs dat de Hub setIdempotencyKey aanriep vóór create()):
$this->assertCount(1, $this->mollieCaptured['idempotency_keys']);
$this->assertSame('my-key-xyz', $this->mollieCaptured['idempotency_keys'][0]);
```

LET OP: het `idempotency_keys`-array is GEDEELD over alle endpoint-stubs in `StubsMollieClient` (zie `$captured = &$this->mollieCaptured` reference). Elke create-call op een gestubde endpoint pusht de pre-call key. Voor de 4 nieuwe tests betekent dat: één POST-call → exact 1 entry in `idempotency_keys` met de verwachte Consumer-key.

# Route-paden (uit routes/api.php — gebruik deze verbatim in tests)

POST /v1/mollie/customers                                — CreateCustomerRequest
POST /v1/mollie/payments/{id}/refunds                    — CreateRefundRequest ($id = tr_xxx)
POST /v1/mollie/customers/{id}/subscriptions             — CreateSubscriptionRequest ($id = cst_xxx)
POST /v1/mollie/payment-links                            — CreatePaymentLinkRequest
</interfaces>

<form_request_payload_shapes>
<!-- Minimale geldige payloads — komen uit de bestaande Form Requests in app/Http/Requests/Api/V1/Mollie/. -->
<!-- Bij twijfel: open de Request-class en lees rules(). -->

# CreateCustomerRequest
{
  "name": "Test Klant",
  "email": "klant@example.test"
}

# CreateRefundRequest (op /v1/mollie/payments/{id}/refunds)
{
  "amount": {"currency": "EUR", "value": "5.00"},
  "description": "Test refund"
}

# CreateSubscriptionRequest (op /v1/mollie/customers/{id}/subscriptions)
{
  "amount": {"currency": "EUR", "value": "10.00"},
  "interval": "1 month",
  "description": "Test subscription"
}

# CreatePaymentLinkRequest
{
  "amount": {"currency": "EUR", "value": "12.34"},
  "description": "Test payment link"
}
</form_request_payload_shapes>
</context>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Consumer → Hub `/v1/mollie/*` POST | Untrusted JSON-payload + Idempotency-Key-header crosses hier; Sanctum-PAT + Resolve-middleware bevestigen Consumer-identiteit. Idempotency-Key wordt verbatim doorgezet — moet correct geforward worden anders zijn dubbele resources mogelijk bij Consumer-retries. |
| Mollie → Hub `/webhooks/mollie/{connection_id}` | Publiek endpoint zonder Sanctum; enige auth is X-Mollie-Signature HMAC. Een lege/null platform-secret accepteert elke HMAC die met '' berekend is — een aanvaller die de payload kent kan triviaal valideren. |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-05a-07 | Tampering / Repudiation | `CustomersController::store`, `RefundsController::store`, `SubscriptionsController::store`, `PaymentLinksController::store` | mitigate | Hijs `buildClient(Request)` naar `AbstractMolliePassThroughController` en route alle 4 write-controllers' `create`-calls erlangs. Verifieer per controller dat `$this->mollieCaptured['idempotency_keys'][0] === Consumer-header` (RED-first per controller). Bij retry-storm zonder forward = duplicate Refund (geld-beweging) / duplicate Subscription (recurring debit). Severity: high. Blocked-by-test: yes. |
| T-05a-06 | Spoofing / Elevation-of-Privilege | `MollieWebhookController::__invoke` | mitigate | Hard-fail guard vóór `MollieWebhookSignature::verify`: `$secret = config('services.mollie.webhook_secret'); if (! is_string($secret) || $secret === '') → audit('webhook_secret_not_configured') + return 500`. Een aanvaller die de Hub-URL kent kan zonder deze guard met een lege secret elke geldige HMAC produceren en webhooks injecteren. Severity: high. Blocked-by-test: yes (twee scenarios: null en empty string). |

**Deferred (gemotiveerd):** Optionele AppServiceProvider boot-time guard die in `app()->environment('production')` een `RuntimeException` gooit bij empty `MOLLIE_WEBHOOK_SECRET`. Reden voor deferral: (a) de runtime-guard in deze plan dekt het concrete attack-vector (een echte inkomende webhook met empty secret) en faalt zichtbaar in audit; (b) een boot-time guard raakt ook artisan-commands en queue-workers in productie, wat een bredere blast-radius heeft dan deze gap-closure verdient; (c) een dedicated environment-check past beter bij Phase 9 admin-UI of een quick `260514-*`-deploy-precondition-task die ook andere production-only secrets dekt (Sanctum-key-rotation, Snelstart-client-key-rotation). Logged voor opvolging in `## Deferred Items` van STATE.md zodra plan-execute klaar is.
</threat_model>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Hijs Idempotency-Key-forward-helper naar AbstractMolliePassThroughController + refactor 4 write-controllers + 4 RED-first tests</name>

  <files>
    app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php,
    app/Http/Controllers/Api/V1/Mollie/PaymentsController.php,
    app/Http/Controllers/Api/V1/Mollie/CustomersController.php,
    app/Http/Controllers/Api/V1/Mollie/RefundsController.php,
    app/Http/Controllers/Api/V1/Mollie/SubscriptionsController.php,
    app/Http/Controllers/Api/V1/Mollie/PaymentLinksController.php,
    tests/Feature/Api/V1/Mollie/CustomersIdempotencyForwardTest.php,
    tests/Feature/Api/V1/Mollie/RefundsIdempotencyForwardTest.php,
    tests/Feature/Api/V1/Mollie/SubscriptionsIdempotencyForwardTest.php,
    tests/Feature/Api/V1/Mollie/PaymentLinksIdempotencyForwardTest.php
  </files>

  <read_first>
    1. `app/Http/Controllers/Api/V1/Mollie/PaymentsController.php` — lees lines 85-101 zorgvuldig; de exacte signature van `buildClient(Request)` wordt 1:1 gehoisd. Lees ook lines 36-57 (store-method) om te zien hoe `$client = $this->buildClient($request);` in een controller wordt gebruikt.
    2. `app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php` — lees de hele file (184 regels) om imports + huidige protected/abstract-method-conventies te zien. De nieuwe `buildClient` komt onderaan, na `collectionToArray()`.
    3. `app/Http/Controllers/Api/V1/Mollie/CustomersController.php` line 60-72 (`store`-method) — de directe `Mollie::client()->customers->create(...)`-call. Vervang `Mollie::client()` door `$this->buildClient($r)`.
    4. `app/Http/Controllers/Api/V1/Mollie/RefundsController.php` line 30-42 (`store`-method) — idem, vervang `Mollie::client()` door `$this->buildClient($r)`. LET OP: gebruikt `paymentRefunds->createForId($payment_id, ...)`.
    5. `app/Http/Controllers/Api/V1/Mollie/SubscriptionsController.php` line 49-61 (`store`-method) — idem. LET OP: gebruikt `subscriptions->createForId($customer_id, ...)`.
    6. `app/Http/Controllers/Api/V1/Mollie/PaymentLinksController.php` line 44-56 (`store`-method) — idem. LET OP: gebruikt `paymentLinks->create(...)`.
    7. `tests/Feature/Api/V1/Mollie/MollieIdempotencyForwardTest.php` — lees de hele file. Het 3e testpad (`test_consumer_idempotency_key_is_forwarded_verbatim_to_mollie`, lines 89-107) is het template dat je 4× kopieert (één keer per write-controller). Asserties: `$this->mollieCaptured['idempotency_keys']` heeft exact 1 entry met de Consumer-header-waarde.
    8. `tests/Concerns/StubsMollieClient.php` — lees lines 84-153 (`bindMollieStubs` API + per-resource resolver-signatures in de doccomment) en bekijk de `idempotency_keys`-capture-pad in `makePaymentRefundsStub` (line 298), `makeSubscriptionsStub` (line 394), `makePaymentLinksStub` (line 455) — alle drie capturen al `$this->mollieClient?->getIdempotencyKey()` vóór hun resolver-call, dus het test-pad is al klaar.
    9. `routes/api.php` lines 45-75 — verifieer de exacte route-paden + path-parameters (`{id}` voor refunds = `tr_xxx`, `{id}` voor subscriptions = `cst_xxx`).
  </read_first>

  <behavior>
    - **RED-fase per controller (1× per nieuwe test):**
      - Test 1 (CustomersIdempotencyForwardTest): `POST /v1/mollie/customers` met `Idempotency-Key: customer-key-001` → Mollie's customers->create wordt aangeroepen + de Hub setIdempotencyKey('customer-key-001') vóór die call → assert 201 + assert exactly 1 entry in `$this->mollieCaptured['idempotency_keys']` met value `'customer-key-001'`. Test moet FAIL'en vóór de refactor (CustomersController gebruikt nu `Mollie::client()` direct → key is null in capture).
      - Test 2 (RefundsIdempotencyForwardTest): `POST /v1/mollie/payments/tr_dummy/refunds` met `Idempotency-Key: refund-key-002` → `paymentRefunds->createForId('tr_dummy', ...)` → assert 201 + exactly 1 entry met `'refund-key-002'`. FAILS vóór refactor.
      - Test 3 (SubscriptionsIdempotencyForwardTest): `POST /v1/mollie/customers/cst_dummy/subscriptions` met `Idempotency-Key: sub-key-003` → `subscriptions->createForId('cst_dummy', ...)` → assert 201 + exactly 1 entry met `'sub-key-003'`. FAILS vóór refactor.
      - Test 4 (PaymentLinksIdempotencyForwardTest): `POST /v1/mollie/payment-links` met `Idempotency-Key: link-key-004` → `paymentLinks->create(...)` → assert 201 + exactly 1 entry met `'link-key-004'`. FAILS vóór refactor.
    - **GREEN-fase (refactor):**
      - `buildClient(Request)` zit op `AbstractMolliePassThroughController` — verbatim kopie van PaymentsController-signature.
      - PaymentsController gebruikt `$this->buildClient($request)` (inherited) — eigen `protected function buildClient` is verwijderd.
      - 4 andere controllers' store-methods gebruiken `$this->buildClient($r)->...->create(...)` i.p.v. `Mollie::client()->...->create(...)`.
    - Bestaande `MollieIdempotencyForwardTest` blijft 3/3 groen (gebruikt nog steeds dezelfde helper, nu via inheritance).
  </behavior>

  <action>
    **Stap 1.1 — Schrijf 4 RED-tests (één per write-controller).**

    Maak per controller een feature-test, mirror van `tests/Feature/Api/V1/Mollie/MollieIdempotencyForwardTest::test_consumer_idempotency_key_is_forwarded_verbatim_to_mollie` (lines 89-107). Elke test heeft één methode: `test_consumer_idempotency_key_is_forwarded_on_<resource>_create`.

    Boilerplate-shape per test (concreet, geen "fill in the blanks"):

    ```php
    <?php

    declare(strict_types=1);

    namespace Tests\Feature\Api\V1\Mollie;

    use App\Sanctum\TokenAbilities;
    use Illuminate\Foundation\Testing\RefreshDatabase;
    use Tests\Concerns\StubsMollieClient;
    use Tests\TestCase;

    class CustomersIdempotencyForwardTest extends TestCase
    {
        use RefreshDatabase;
        use StubsMollieClient;

        public function test_consumer_idempotency_key_is_forwarded_on_customer_create(): void
        {
            [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

            $this->bindMollieStubs([
                'customers' => fn (string $op, mixed $arg) => $this->makeCustomer([
                    'id' => 'cst_idem_1',
                    'name' => 'Test Klant',
                ]),
            ]);

            $this->callMollie($token, 'POST', '/v1/mollie/customers', [
                'name' => 'Test Klant',
                'email' => 'klant@example.test',
            ], ['Idempotency-Key' => 'customer-key-001'])->assertCreated();

            $this->assertCount(1, $this->mollieCaptured['idempotency_keys']);
            $this->assertSame('customer-key-001', $this->mollieCaptured['idempotency_keys'][0]);
        }
    }
    ```

    Voor de andere drie:

    - **RefundsIdempotencyForwardTest** — `bindMollieStubs(['paymentRefunds' => fn ($op, $arg) => $this->makeRefund(['id' => 're_idem_2', 'amount' => ['currency' => 'EUR', 'value' => '5.00']])]);` — URL: `/v1/mollie/payments/tr_dummy/refunds`; payload: `['amount' => ['currency' => 'EUR', 'value' => '5.00'], 'description' => 'Test refund']`; header: `'Idempotency-Key' => 'refund-key-002'`.
    - **SubscriptionsIdempotencyForwardTest** — `bindMollieStubs(['subscriptions' => fn ($op, $arg) => $this->makeSubscription(['id' => 'sub_idem_3', 'status' => 'active'])]);` — URL: `/v1/mollie/customers/cst_dummy/subscriptions`; payload: `['amount' => ['currency' => 'EUR', 'value' => '10.00'], 'interval' => '1 month', 'description' => 'Test subscription']`; header: `'Idempotency-Key' => 'sub-key-003'`.
    - **PaymentLinksIdempotencyForwardTest** — `bindMollieStubs(['paymentLinks' => fn ($op, $arg) => $this->makePaymentLink(['id' => 'pl_idem_4', 'amount' => ['currency' => 'EUR', 'value' => '12.34']])]);` — URL: `/v1/mollie/payment-links`; payload: `['amount' => ['currency' => 'EUR', 'value' => '12.34'], 'description' => 'Test payment link']`; header: `'Idempotency-Key' => 'link-key-004'`.

    Run alle 4 tests: `php artisan test --compact tests/Feature/Api/V1/Mollie/CustomersIdempotencyForwardTest.php tests/Feature/Api/V1/Mollie/RefundsIdempotencyForwardTest.php tests/Feature/Api/V1/Mollie/SubscriptionsIdempotencyForwardTest.php tests/Feature/Api/V1/Mollie/PaymentLinksIdempotencyForwardTest.php`.

    **Verwacht: alle 4 RED.** De assertCount/assertSame faalt omdat de controllers nu `Mollie::client()` direct gebruiken — `$this->mollieCaptured['idempotency_keys'][0]` zal `null` zijn (geen setIdempotencyKey-aanroep) i.p.v. de verwachte Consumer-key.

    Commit (RED-fase): `test(05a-06): add failing idempotency-forward tests for customers/refunds/subscriptions/payment-links`. **Wel committen** (TDD-discipline: RED → GREEN cycle zichtbaar in git history).

    **Stap 1.2 — Hijs `buildClient` naar AbstractMolliePassThroughController.**

    Open `app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php`:

    1. Voeg twee imports toe (boven `use App\Http\Controllers\Controller;`-blok):
       ```php
       use Emeq\MollieApi\Facades\Mollie;
       use Mollie\Api\MollieApiClient;
       ```
    2. Voeg onder de bestaande `collectionToArray()`-method (na regel 183) deze method toe — VERBATIM kopie van PaymentsController::buildClient (lines 85-101), incl. doccomment maar zonder de "(verifieerd in 05a-03-PREFLIGHT.md V1)"-zin omdat dit nu generiek geldt:

       ```php
       /**
        * Bouwt een MollieApiClient voor de huidige request. Forward't de
        * Consumer's Idempotency-Key-header naar Mollie via de runtime-setter
        * (MollieApiClient::setIdempotencyKey()). De default UuidV7-generator
        * blijft de fallback zonder Consumer-header.
        *
        * Gedeeld pad voor alle 5 write-endpoints (D-06 / 05a-06-PLAN). PaymentsController
        * gebruikte 'm eerst als eigen method; gehoisd hierheen na verificatie-gap CR-01.
        */
       protected function buildClient(Request $request): MollieApiClient
       {
           $client = Mollie::client();

           $consumerKey = $request->header('Idempotency-Key');
           if (is_string($consumerKey) && $consumerKey !== '') {
               $client->setIdempotencyKey($consumerKey);
           }

           return $client;
       }
       ```

    **Stap 1.3 — Refactor PaymentsController om de geërfde helper te gebruiken.**

    In `app/Http/Controllers/Api/V1/Mollie/PaymentsController.php`:

    1. **Verwijder** lines 85-101 (de hele `protected function buildClient()`-method + doccomment). De controller erft 'm nu via `AbstractMolliePassThroughController`.
    2. **Verwijder** de unused import op line 13 (`use Mollie\Api\MollieApiClient;`) — deze was alleen voor de eigen helper-signature.
    3. **Behoud** line 47 (`$client = $this->buildClient($request);`) — die werkt nu via inheritance.
    4. Update de doccomment van de class (lines 18-33) — verwijder de "Idempotency-Key forward (D-06, pre-flight V1)"-paragraaf (lines 29-33) want die hoort nu bij de Abstract; vervang door één regel: `* Idempotency-Key forward via AbstractMolliePassThroughController::buildClient (D-06).`

    **Stap 1.4 — Refactor 4 write-controllers.**

    Per controller exact één wijziging in de `store()`-method:

    - **CustomersController.php** (line 65): vervang `Mollie::client()->customers->create($r->validated())` door `$this->buildClient($r)->customers->create($r->validated())`.
    - **RefundsController.php** (line 35): vervang `Mollie::client()->paymentRefunds->createForId($payment_id, $r->validated())` door `$this->buildClient($r)->paymentRefunds->createForId($payment_id, $r->validated())`.
    - **SubscriptionsController.php** (line 54): vervang `Mollie::client()->subscriptions->createForId($customer_id, $r->validated())` door `$this->buildClient($r)->subscriptions->createForId($customer_id, $r->validated())`.
    - **PaymentLinksController.php** (line 49): vervang `Mollie::client()->paymentLinks->create($r->validated())` door `$this->buildClient($r)->paymentLinks->create($r->validated())`.

    Imports blijven ongewijzigd in de 4 controllers — `Mollie::client()` wordt nog steeds gebruikt voor `index`/`show`/`destroy` (geen Idempotency-Key forward nodig op reads).

    **Stap 1.5 — Run tests.**

    ```bash
    php artisan test --compact tests/Feature/Api/V1/Mollie/CustomersIdempotencyForwardTest.php tests/Feature/Api/V1/Mollie/RefundsIdempotencyForwardTest.php tests/Feature/Api/V1/Mollie/SubscriptionsIdempotencyForwardTest.php tests/Feature/Api/V1/Mollie/PaymentLinksIdempotencyForwardTest.php tests/Feature/Api/V1/Mollie/MollieIdempotencyForwardTest.php
    ```

    Verwacht: 5 tests passing (4 nieuw + 3 bestaand in MollieIdempotencyForwardTest = 7 totaal, allemaal groen).

    Vervolgens vol pass-through-scope om regressies te vangen:
    ```bash
    php artisan test --compact tests/Feature/Api/V1/Mollie/
    ```
    Verwacht: 0 failures.

    **Stap 1.6 — Pint clean.**
    ```bash
    ./vendor/bin/pint --dirty --format agent
    ```

    Commit (GREEN-fase): `refactor(05a-06): hoist Idempotency-Key-forward to AbstractMolliePassThroughController` (verzamel files: Abstract + 5 controllers).
  </action>

  <verify>
    <automated>php artisan test --compact tests/Feature/Api/V1/Mollie/CustomersIdempotencyForwardTest.php tests/Feature/Api/V1/Mollie/RefundsIdempotencyForwardTest.php tests/Feature/Api/V1/Mollie/SubscriptionsIdempotencyForwardTest.php tests/Feature/Api/V1/Mollie/PaymentLinksIdempotencyForwardTest.php tests/Feature/Api/V1/Mollie/MollieIdempotencyForwardTest.php</automated>
  </verify>

  <acceptance_criteria>
    Alle criteria moeten gelijktijdig waar zijn (grep-verifiable, exacte strings):

    1. `grep -c "protected function buildClient(" app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php` retourneert `1`.
    2. `grep -c "protected function buildClient(" app/Http/Controllers/Api/V1/Mollie/PaymentsController.php` retourneert `0` (helper is verwijderd, wordt geërfd).
    3. `grep -c "\$this->buildClient(\$request)" app/Http/Controllers/Api/V1/Mollie/PaymentsController.php` retourneert `1` (line 47 onveranderd).
    4. `grep -c "\$this->buildClient(\$r)" app/Http/Controllers/Api/V1/Mollie/CustomersController.php` retourneert `1`.
    5. `grep -c "\$this->buildClient(\$r)" app/Http/Controllers/Api/V1/Mollie/RefundsController.php` retourneert `1`.
    6. `grep -c "\$this->buildClient(\$r)" app/Http/Controllers/Api/V1/Mollie/SubscriptionsController.php` retourneert `1`.
    7. `grep -c "\$this->buildClient(\$r)" app/Http/Controllers/Api/V1/Mollie/PaymentLinksController.php` retourneert `1`.
    8. `grep -c "Mollie::client()->customers->create(" app/Http/Controllers/Api/V1/Mollie/CustomersController.php` retourneert `0` (alleen `$this->buildClient($r)->customers->create` blijft).
    9. `grep -c "Mollie::client()->paymentRefunds->createForId(" app/Http/Controllers/Api/V1/Mollie/RefundsController.php` retourneert `0`.
    10. `grep -c "Mollie::client()->subscriptions->createForId(" app/Http/Controllers/Api/V1/Mollie/SubscriptionsController.php` retourneert `0`.
    11. `grep -c "Mollie::client()->paymentLinks->create(" app/Http/Controllers/Api/V1/Mollie/PaymentLinksController.php` retourneert `0`.
    12. 4 nieuwe test-files bestaan: `tests/Feature/Api/V1/Mollie/CustomersIdempotencyForwardTest.php`, `RefundsIdempotencyForwardTest.php`, `SubscriptionsIdempotencyForwardTest.php`, `PaymentLinksIdempotencyForwardTest.php`.
    13. Elke nieuwe test bevat `test_consumer_idempotency_key_is_forwarded_on_<resource>_create`-methodename: `grep -l "test_consumer_idempotency_key_is_forwarded" tests/Feature/Api/V1/Mollie/{Customers,Refunds,Subscriptions,PaymentLinks}IdempotencyForwardTest.php` toont alle 4 files.
    14. `php artisan test --compact tests/Feature/Api/V1/Mollie/` retourneert 0 failures.
    15. `./vendor/bin/pint --dirty --test --format agent` exit-code 0.
  </acceptance_criteria>

  <done>
    - `AbstractMolliePassThroughController` bezit `buildClient(Request)` als protected method (1× gedefinieerd, 5× gebruikt via inheritance).
    - PaymentsController's eigen `buildClient`-implementatie is verwijderd; gebruikt nog steeds `$this->buildClient($request)` op line 47.
    - 4 write-controllers (Customers, Refunds, Subscriptions, PaymentLinks) gebruiken `$this->buildClient($r)->...->create(...)` voor hun create-call.
    - 4 nieuwe feature-tests bewijzen verbatim-forward van Idempotency-Key op alle 4 endpoints.
    - Bestaande `MollieIdempotencyForwardTest` (3 tests) blijft groen via inheritance.
    - Pint clean.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Hard-fail guard voor empty MOLLIE_WEBHOOK_SECRET in MollieWebhookController + RED-first test</name>

  <files>
    app/Http/Controllers/Webhooks/MollieWebhookController.php,
    tests/Feature/Webhooks/MollieWebhookSignatureTest.php
  </files>

  <read_first>
    1. `app/Http/Controllers/Webhooks/MollieWebhookController.php` — lees de hele file (110 regels). Specifiek: lines 36-52 (signature-verify pad waar de guard vóór moet) en lines 100-109 (`auditFailedWebhook` helper-signature die je gebruikt).
    2. `tests/Feature/Webhooks/MollieWebhookSignatureTest.php` — lees de hele file (212 regels). Specifiek lines 26-30 (`setUp` met `config(['services.mollie.webhook_secret' => $this->secret]);`) en lines 56-79 (`test_tampered_signature_returns_400_and_no_dispatch`) als pattern voor de nieuwe tests. De `makeMollieConnection()`-helper op line 174-180 wordt hergebruikt.
    3. `.env.example` line 74 (`MOLLIE_WEBHOOK_SECRET=` leeg) — bevestig waarom dit echt mogelijk is in productie.
    4. `config/services.php` line 55 (`'webhook_secret' => env('MOLLIE_WEBHOOK_SECRET')`) — bevestig dat geen default = `null` bij missing env.
  </read_first>

  <behavior>
    - **RED-fase:** Twee nieuwe testpaden in `MollieWebhookSignatureTest` (één gedeelde test-methode met data-provider OR twee aparte methoden — kies aparte methoden voor leesbaarheid):
      - `test_null_platform_secret_returns_500_and_does_not_dispatch`: `config(['services.mollie.webhook_secret' => null]);` → POST naar `/webhooks/mollie/{id}` met geldige connection + dummy signature → assert 500, response body has `error: webhook_misconfigured`, `WebhookCall` audit-rij heeft `exception = 'webhook_secret_not_configured'`, `Bus::assertNotDispatched(ForwardMollieWebhookToConsumer::class)`.
      - `test_empty_string_platform_secret_returns_500_and_does_not_dispatch`: idem maar `config(['services.mollie.webhook_secret' => '']);`.
    - **GREEN-fase:** Guard in `MollieWebhookController::__invoke` vóór de signature-verify try-catch:
      - Lees `$secret = config('services.mollie.webhook_secret');`
      - Als `! is_string($secret) || $secret === ''` → `$this->auditFailedWebhook($request, 'webhook_secret_not_configured');` + return 500 met `{"error": "webhook_misconfigured"}`.
    - Bestaande 6 testpaden in `MollieWebhookSignatureTest` blijven groen (ze setten allemaal `$this->secret = 'whsec_test_xyz'` in `setUp`).
  </behavior>

  <action>
    **Stap 2.1 — Schrijf 2 RED-tests in bestaande `MollieWebhookSignatureTest`.**

    Open `tests/Feature/Webhooks/MollieWebhookSignatureTest.php`. Voeg na `test_payload_without_id_returns_400_missing_id` (na line 172, vóór de private helpers op line 174) twee nieuwe methoden toe:

    ```php
    public function test_null_platform_secret_returns_500_and_does_not_dispatch(): void
    {
        Bus::fake();
        config(['services.mollie.webhook_secret' => null]);

        $connection = $this->makeMollieConnection();
        $payload = json_encode(['id' => 'tr_test123']);
        // Signature is irrelevant — guard moet faillen vóór verify
        $signature = MollieWebhookSignature::sign($payload, 'any-value');

        $response = $this->call(
            'POST',
            "/webhooks/mollie/{$connection->id}",
            [], [], [],
            ['HTTP_X_MOLLIE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $payload,
        );

        $response->assertStatus(500);
        $response->assertJsonPath('error', 'webhook_misconfigured');
        Bus::assertNotDispatched(ForwardMollieWebhookToConsumer::class);

        $row = WebhookCall::query()->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('webhook_secret_not_configured', $row->exception);
    }

    public function test_empty_string_platform_secret_returns_500_and_does_not_dispatch(): void
    {
        Bus::fake();
        config(['services.mollie.webhook_secret' => '']);

        $connection = $this->makeMollieConnection();
        $payload = json_encode(['id' => 'tr_test123']);
        $signature = MollieWebhookSignature::sign($payload, '');

        $response = $this->call(
            'POST',
            "/webhooks/mollie/{$connection->id}",
            [], [], [],
            ['HTTP_X_MOLLIE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $payload,
        );

        $response->assertStatus(500);
        $response->assertJsonPath('error', 'webhook_misconfigured');
        Bus::assertNotDispatched(ForwardMollieWebhookToConsumer::class);

        $row = WebhookCall::query()->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('webhook_secret_not_configured', $row->exception);
    }
    ```

    Run de specifieke tests:
    ```bash
    php artisan test --compact --filter="test_null_platform_secret_returns_500|test_empty_string_platform_secret_returns_500" tests/Feature/Webhooks/MollieWebhookSignatureTest.php
    ```

    **Verwacht: beide RED.** Met lege/null secret returnt de huidige controller óf 202 (HMAC met '' is geldig op een bekende payload) óf 400 (`InvalidSignatureException` van SDK); niet 500. De audit-rij heeft niet de string `webhook_secret_not_configured`.

    Commit (RED-fase): `test(05a-06): add failing tests for empty/null MOLLIE_WEBHOOK_SECRET hard-fail`. **Wel committen** voor RED-paper-trail.

    **Stap 2.2 — Implementeer guard.**

    Open `app/Http/Controllers/Webhooks/MollieWebhookController.php`. Vervang lines 36-52 (de huidige stap-1 `Signature-verify`-block) door:

    ```php
    public function __invoke(Request $request, int $connection_id): JsonResponse
    {
        // 0. Hard-fail guard: platform-secret moet geconfigureerd zijn.
        // Anders accepteert MollieWebhookSignature::verify elke HMAC die met
        // '' berekend is — open ingress bij vergeten env-var (D-08 stap 1,
        // verificatie-gap CR-02 / threat T-05a-06).
        $secret = config('services.mollie.webhook_secret');
        if (! is_string($secret) || $secret === '') {
            $this->auditFailedWebhook($request, 'webhook_secret_not_configured');

            return response()->json(['error' => 'webhook_misconfigured'], 500);
        }

        // 1. Signature-verify
        try {
            $valid = MollieWebhookSignature::verify($request, $secret);
        } catch (InvalidSignatureException $e) {
            $this->auditFailedWebhook($request, "invalid_signature: {$e->getMessage()}");

            return response()->json(['error' => 'invalid_signature'], 400);
        }
        if (! $valid) {
            $this->auditFailedWebhook($request, 'missing_signature_header');

            return response()->json(['error' => 'missing_signature'], 400);
        }
    ```

    Belangrijk: de `(string)`-cast op `config('services.mollie.webhook_secret')` is weg, en het tweede argument aan `MollieWebhookSignature::verify` is nu de gevalideerde `$secret`-variable (verzekerd niet-leeg dankzij de guard).

    **Stap 2.3 — Run alle webhook-tests.**

    ```bash
    php artisan test --compact tests/Feature/Webhooks/
    ```

    Verwacht: alle bestaande webhook-tests (6 in MollieWebhookSignatureTest + de andere webhook-tests in dezelfde dir) blijven groen + 2 nieuwe groen = totaal 0 failures.

    Vervolgens vol scope:
    ```bash
    php artisan test --compact
    ```

    Verwacht: 203 passed / 1 incomplete (Phase 4 placeholder, ongerelateerd) / 0 failed (was 201 → 203 door de 4+2 = 6 nieuwe tests in deze plan: 4 nieuwe + 2 nieuwe + bestaande 197 blijven groen).

    **Stap 2.4 — Pint clean.**
    ```bash
    ./vendor/bin/pint --dirty --format agent
    ```

    Commit (GREEN-fase): `feat(05a-06): hard-fail webhook ingress on empty MOLLIE_WEBHOOK_SECRET`.
  </action>

  <verify>
    <automated>php artisan test --compact tests/Feature/Webhooks/MollieWebhookSignatureTest.php</automated>
  </verify>

  <acceptance_criteria>
    Alle criteria moeten gelijktijdig waar zijn:

    1. `grep -c "webhook_secret_not_configured" app/Http/Controllers/Webhooks/MollieWebhookController.php` retourneert `1` (de string in `auditFailedWebhook($request, 'webhook_secret_not_configured')`).
    2. `grep -n "is_string(\$secret)" app/Http/Controllers/Webhooks/MollieWebhookController.php` toont een match in `__invoke` BEFORE de regel met `MollieWebhookSignature::verify` (guard staat eerder in de flow). Verifieer: line-nummer van `is_string($secret)` < line-nummer van `MollieWebhookSignature::verify`.
    3. `grep -c "webhook_misconfigured" app/Http/Controllers/Webhooks/MollieWebhookController.php` retourneert `1` (de error-response-key).
    4. `grep -c "(string) config('services.mollie.webhook_secret')" app/Http/Controllers/Webhooks/MollieWebhookController.php` retourneert `0` (de oude cast-pad is weg).
    5. `grep -c "test_null_platform_secret_returns_500_and_does_not_dispatch" tests/Feature/Webhooks/MollieWebhookSignatureTest.php` retourneert `1`.
    6. `grep -c "test_empty_string_platform_secret_returns_500_and_does_not_dispatch" tests/Feature/Webhooks/MollieWebhookSignatureTest.php` retourneert `1`.
    7. `php artisan test --compact tests/Feature/Webhooks/MollieWebhookSignatureTest.php` retourneert 8 tests passed / 0 failures (was 6, nu 8).
    8. `php artisan test --compact` retourneert 0 failures.
    9. `./vendor/bin/pint --dirty --test --format agent` exit-code 0.
  </acceptance_criteria>

  <done>
    - `MollieWebhookController::__invoke` heeft stap-0 hard-fail guard die `config('services.mollie.webhook_secret')` valideert vóór `MollieWebhookSignature::verify`.
    - Bij null/empty secret: 500 response + `webhook_misconfigured` error + auditrij met `webhook_secret_not_configured` + geen dispatch.
    - 2 nieuwe testpaden in `MollieWebhookSignatureTest` bewijzen beide scenarios.
    - 6 bestaande webhook-signature-tests blijven groen.
    - Pint clean.
  </done>
</task>

</tasks>

<verification>
**Phase-acceptance grep-gates (verifier zal deze runnen):**

```bash
# Gap #1 — D-06 verbreding
grep -c "protected function buildClient(" app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php  # == 1
grep -c "protected function buildClient(" app/Http/Controllers/Api/V1/Mollie/PaymentsController.php                  # == 0
for ctrl in Customers Refunds Subscriptions PaymentLinks; do
  grep -c '\$this->buildClient(\$r)' "app/Http/Controllers/Api/V1/Mollie/${ctrl}Controller.php"                       # each == 1
done
ls tests/Feature/Api/V1/Mollie/CustomersIdempotencyForwardTest.php \
   tests/Feature/Api/V1/Mollie/RefundsIdempotencyForwardTest.php \
   tests/Feature/Api/V1/Mollie/SubscriptionsIdempotencyForwardTest.php \
   tests/Feature/Api/V1/Mollie/PaymentLinksIdempotencyForwardTest.php                                                  # all exist

# Gap #2 — Webhook hard-fail
grep -c "webhook_secret_not_configured" app/Http/Controllers/Webhooks/MollieWebhookController.php                     # == 1
grep -c "webhook_misconfigured" app/Http/Controllers/Webhooks/MollieWebhookController.php                             # == 1
grep -c "(string) config('services.mollie.webhook_secret')" app/Http/Controllers/Webhooks/MollieWebhookController.php # == 0
grep -c "test_null_platform_secret_returns_500" tests/Feature/Webhooks/MollieWebhookSignatureTest.php                  # == 1
grep -c "test_empty_string_platform_secret_returns_500" tests/Feature/Webhooks/MollieWebhookSignatureTest.php          # == 1

# Run-gates
php artisan test --compact tests/Feature/Api/V1/Mollie/ tests/Feature/Webhooks/                                       # 0 failures
php artisan test --compact                                                                                            # 0 failures
./vendor/bin/pint --dirty --test --format agent                                                                       # exit 0
```

**Re-verify de 2 FAILED truths uit 05a-VERIFICATION.md:**

| # | Truth | New Status | Evidence |
|---|-------|-----------|----------|
| 12 | D-06: Consumer Idempotency-Key forward op alle 5 POST-endpoints | VERIFIED | 5 tests groen: bestaande MollieIdempotencyForwardTest (payments) + 4 nieuwe IdempotencyForwardTest-files (customers/refunds/subscriptions/payment-links). Helper geërfd via AbstractMolliePassThroughController. |
| 13 | MOLL-04 / D-08 stap 1 / T-05a-06: Hard fail bij ontbrekende platform-webhook-secret | VERIFIED | 2 nieuwe testpaden in MollieWebhookSignatureTest (null + empty string). Guard vóór `MollieWebhookSignature::verify`. |

**Anti-Patterns die verwijderd zijn (uit VERIFICATION.md "Anti-Patterns Found" tabel):**

- CustomersController.php:65 BLOCKER → resolved
- RefundsController.php:35 BLOCKER → resolved
- SubscriptionsController.php:54 BLOCKER → resolved
- PaymentLinksController.php:49 BLOCKER → resolved
- MollieWebhookController.php:41 BLOCKER → resolved

De WARNING-/INFO-anti-patterns (WR-01 t/m IN-05) blijven open — niet in scope voor deze gap-closure plan.
</verification>

<success_criteria>
Plan is voltooid wanneer:

- [ ] `AbstractMolliePassThroughController::buildClient(Request): MollieApiClient` bestaat (verbatim kopie van PaymentsController-implementatie).
- [ ] PaymentsController's eigen `buildClient` is verwijderd; controller gebruikt geërfde versie.
- [ ] 4 write-controllers (Customers, Refunds, Subscriptions, PaymentLinks) routen hun create-call door `$this->buildClient($r)`.
- [ ] 4 nieuwe feature-tests (één per write-resource) bewijzen verbatim forward van Idempotency-Key.
- [ ] MollieWebhookController heeft stap-0 hard-fail guard vóór `MollieWebhookSignature::verify`.
- [ ] 2 nieuwe testpaden in MollieWebhookSignatureTest dekken null + empty-string scenarios.
- [ ] Volledige test-suite `php artisan test --compact`: 0 failures (was 201 passing → 207 passing na 6 nieuwe tests).
- [ ] Pint clean (`./vendor/bin/pint --dirty --test --format agent` exit 0).
- [ ] Twee commits met RED-paper-trail (`test(05a-06): ...`) en GREEN-implementatie (`refactor(05a-06): ...` + `feat(05a-06): ...`).
- [ ] Geen wijzigingen in routes, migrations, of externe dependencies — alleen controller-laag chirurgie.
</success_criteria>

<output>
Na voltooiing van Task 1 + Task 2:

1. Maak `.planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-06-SUMMARY.md` met:
   - Beide gaps gesloten (D-06 verbreding + D-08 stap 1 hard-fail) met verwijzing naar 05a-VERIFICATION.md truths #12 + #13.
   - Aantal nieuwe tests (4 idempotency + 2 webhook-secret = 6 totaal).
   - Volledige test-suite count (was 201, nu 207).
   - Verwijzing naar de twee commits (RED + GREEN per task).
   - Geen Rule-1-deviaties verwacht — alle paden zijn deterministisch.

2. Trigger `/gsd-verify-phase 5a` zodat de verifier `gaps_closed: [truth-12, truth-13]` kan registreren en de phase naar 13/13 verified gaat.
</output>
