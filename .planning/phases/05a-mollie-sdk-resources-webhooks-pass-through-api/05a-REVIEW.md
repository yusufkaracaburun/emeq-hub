---
phase: 05a-mollie-sdk-resources-webhooks-pass-through-api
reviewed: 2026-05-15T00:00:00Z
depth: standard
scope: 05a-06 gap-closure only (D-06 Idempotency-Key forward + D-08 webhook-secret hard-fail)
files_reviewed: 13
files_reviewed_list:
  - app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php
  - app/Http/Controllers/Api/V1/Mollie/PaymentsController.php
  - app/Http/Controllers/Api/V1/Mollie/CustomersController.php
  - app/Http/Controllers/Api/V1/Mollie/RefundsController.php
  - app/Http/Controllers/Api/V1/Mollie/SubscriptionsController.php
  - app/Http/Controllers/Api/V1/Mollie/PaymentLinksController.php
  - app/Http/Controllers/Webhooks/MollieWebhookController.php
  - tests/Concerns/StubsMollieClient.php
  - tests/Feature/Api/V1/Mollie/CustomersIdempotencyForwardTest.php
  - tests/Feature/Api/V1/Mollie/RefundsIdempotencyForwardTest.php
  - tests/Feature/Api/V1/Mollie/SubscriptionsIdempotencyForwardTest.php
  - tests/Feature/Api/V1/Mollie/PaymentLinksIdempotencyForwardTest.php
  - tests/Feature/Webhooks/MollieWebhookSignatureTest.php
findings:
  blocker: 0
  warning: 4
  info: 4
  total: 8
status: issues_found
---

# Phase 05a Gap-Closure: Code Review Report

**Reviewed:** 2026-05-15
**Depth:** standard
**Files Reviewed:** 13
**Status:** issues_found (4 warnings + 4 info; geen blockers)

## Summary

Focused review van de 05a-06 gap-closure die twee BLOCKER-findings uit de pre-gap-closure-review afsluit:

- **CR-01 (D-06 Idempotency-Key forward)**: `buildClient(Request)` is correct gehoisd naar `AbstractMolliePassThroughController` en wordt door alle 5 write-controllers aangeroepen (Payments::store, Customers::store, Refunds::store, Subscriptions::store, PaymentLinks::store). Vier nieuwe IdempotencyForwardTest-files bewijzen de forward per resource. Vendor's `MollieApiClient::setIdempotencyKey()` reset zichzelf na elke request (per `HandlesIdempotency`-trait); `Mollie::client()` levert bij elke aanroep een fresh client — geen cross-request-leak.
- **CR-02 (D-08 stap 1 webhook-secret hard-fail)**: De stap-0 guard in `MollieWebhookController::__invoke` fires vóór `MollieWebhookSignature::verify`, schrijft een `webhook_secret_not_configured`-audit-row en retourneert 500. Twee nieuwe testpaden dekken zowel `null` als `''` secret-configuratie.

Geen BLOCKER-defects gevonden in deze gap-closure delta. De 4 WARN-findings betreffen test-robustheid, dode duplicatie en documentatie-drift; de 4 INFO-findings zijn stilistisch.

## Critical Issues

_Geen blockers gevonden._

## Warnings

### WR-01: Dode `paymentToArray()`-duplicatie in PaymentsController na hoist

**File:** `app/Http/Controllers/Api/V1/Mollie/PaymentsController.php:88-105`

**Issue:** PaymentsController's private `paymentToArray(Payment)` is functioneel identiek aan de inherited `resourceToArray(BaseResource)` op de base class (`AbstractMolliePassThroughController:134-150`). Beide doen `getResponse() → json_decode(body)` met JSON-fallback naar object-cast. `Payment` extends `BaseResource`, dus `resourceToArray($payment)` werkt volledig. De hoist-stap (zelfde 05a-06-PLAN scope) heeft `buildClient` wel weggehaald maar `paymentToArray` als zombie achtergelaten. Dit verhoogt de kans dat een bug-fix in één van de twee implementaties drift creëert.

**Fix:**
```php
// PaymentsController: verwijder private paymentToArray() en vervang aanroepen
// door $this->resourceToArray($payment).

public function store(CreatePaymentRequest $request): Response
{
    return $this->handle($request, '/v2/payments', function (Request $request) {
        // ... payload + buildClient logica ongewijzigd ...
        return ['status' => 201, 'body' => $this->resourceToArray($payment)];
    });
}
```

### WR-02: Stub-stuk-comment claimt `customerMandates`-property die niet bestaat

**File:** `tests/Feature/Api/V1/Mollie/StubMollieClient.php:17-18`

**Issue:** Docblock zegt _"Plan 05a-04 breidt de subclass uit met customers, methods, paymentRefunds, refunds en **customerMandates**"_, maar de daadwerkelijke `@property`-declaraties op regels 22-27 én de runtime-stub-key (`$extras['mandates']` op `StubsMollieClient:137`) gebruiken `mandates` — conform Mollie's vendor-SDK. De comment is achterhaald sinds de vendor-discovery in 05a-04. Buiten de directe gap-closure-scope, maar de wijziging in `StubsMollieClient::makeCustomersStub` raakt dezelfde docblock-stack en had meegenomen kunnen worden.

**Fix:**
```php
// tests/Feature/Api/V1/Mollie/StubMollieClient.php — pas regel 17-18 aan:
 * Hergebruik-pattern van Tests\Feature\Webhooks\ThrowingMollieApiClient
 * (Plan 05a-02). Plan 05a-04 breidt de subclass uit met customers,
 * methods, paymentRefunds, refunds en mandates zodat de extra
 * resource-controllers dezelfde stub-strategie kunnen hergebruiken.
```

### WR-03: Gedeelde `idempotency_keys`-bucket maakt nieuwe tests fragile bij parallelle stub-bindings

**File:** `tests/Concerns/StubsMollieClient.php:49`

**Issue:** Alle 5 endpoint-stubs (`payments`, `customers`, `paymentRefunds`, `subscriptions`, `paymentLinks`) schrijven hun captured idempotency-key naar dezelfde array (`$this->mollieCaptured['idempotency_keys']`). De vier nieuwe IdempotencyForwardTest-files asserteren `assertCount(1, …)` — dat werkt alleen omdat elke test maar één stub bindt én één POST doet. Zodra een toekomstige test meerdere write-resources combineert (bv. customer-create gevolgd door subscription-create), wordt de assertion ambigu. Dit is geen actieve bug, maar de gap-closure had een tagged shape (`['customers' => 'key-x', 'subscriptions' => 'key-y']`) of per-resource-bucket robuuster gemaakt.

**Fix:**
```php
// Optie A: per-resource keys
'customer_idempotency_keys' => [],
'refund_idempotency_keys' => [],
'subscription_idempotency_keys' => [],
'payment_link_idempotency_keys' => [],
'payment_idempotency_keys' => [],

// Of optie B: tagged tuples in de shared bucket
$this->captured['idempotency_keys'][] = ['resource' => 'customer', 'key' => $key];
```
Hoeft niet nu — als WARN gemarkeerd zodat het in een follow-up phase meegenomen wordt.

### WR-04: Nieuwe IdempotencyForwardTest-tests testen alleen het happy-pad

**File:**
- `tests/Feature/Api/V1/Mollie/CustomersIdempotencyForwardTest.php:21-39`
- `tests/Feature/Api/V1/Mollie/RefundsIdempotencyForwardTest.php:22-40`
- `tests/Feature/Api/V1/Mollie/SubscriptionsIdempotencyForwardTest.php:22-41`
- `tests/Feature/Api/V1/Mollie/PaymentLinksIdempotencyForwardTest.php:21-39`

**Issue:** Elk van deze tests doet één POST met `Idempotency-Key`-header en asserteert één captured key. Ze bewijzen dus dat de forward werkt _wanneer_ de consumer een header stuurt, maar niet:
1. Dat een POST _zonder_ Idempotency-Key netjes naar de UuidV7-default-generator valt (zoals `MollieIdempotencyForwardTest::test_post_without_idempotency_key_uses_uuid7_default_generator` dat wel doet voor Payments).
2. Dat twee identieke POSTs dezelfde key naar Mollie pushen (replay-protection round-trip, zoals SC-5 voor Payments).

Voor de directe gap-closure (CR-01 dichtspijkeren) is dit voldoende, maar de verwachte "MOLL-03 SC-5 hard gate" geldt economisch óók voor Refunds en Subscriptions (financiële retry-storm-risico's zoals in de eigen test-docblock genoemd). Een symmetrische test-set per resource zou de garantie compleet maken.

**Fix:** Voeg per resource minimaal een `test_post_without_idempotency_key_falls_back_to_default()` toe die `assertSame([null], $this->mollieCaptured['idempotency_keys'])` doet, conform het Payments-pattern. Voor Refunds/Subscriptions ook een dedup-round-trip-test (twee POSTs met dezelfde key).

## Info

### IN-01: Test-comment ontbreekt rond geforceerde empty-secret sign-call

**File:** `tests/Feature/Webhooks/MollieWebhookSignatureTest.php:208`

**Issue:** In `test_empty_string_platform_secret_returns_500_and_does_not_dispatch` wordt `MollieWebhookSignature::sign($payload, '')` aangeroepen. Dit werkt (HMAC-SHA256 met lege key produceert een valide hex-digest), maar lezers van de test kunnen verward raken: de zusje-test (regel 174-199) gebruikt `'any-value'` als secret en heeft een expliciete `// Signature is irrelevant — guard moet faillen vóór verify`. De empty-string-variant heeft die comment niet.

**Fix:**
```php
$payload = json_encode(['id' => 'tr_test123']);
// Signature is irrelevant — guard moet faillen vóór verify
$signature = MollieWebhookSignature::sign($payload, '');
```

### IN-02: `fakeMolliePaymentGet()` overbodig aangeroepen in `test_payload_without_id_returns_400_missing_id`

**File:** `tests/Feature/Webhooks/MollieWebhookSignatureTest.php:151`

**Issue:** De payload-id-check op regel 76-81 van `MollieWebhookController` returnt vóór de anti-spoofing-fetch (stap 4). De test setup'ed dus een MollieApiClient-fake die nooit gebruikt wordt. Pre-existing cruft, niet door 05a-06 geïntroduceerd, maar makkelijk weg te halen.

**Fix:**
```php
public function test_payload_without_id_returns_400_missing_id(): void
{
    Bus::fake();
    // Geen fakeMolliePaymentGet nodig — payload-id-check fired vóór anti-spoofing-fetch
    $connection = $this->makeMollieConnection();
    // ... rest ongewijzigd ...
}
```

### IN-03: `webhook_misconfigured`-response heeft geen `Retry-After` of `Retry-Disable`-hint

**File:** `app/Http/Controllers/Webhooks/MollieWebhookController.php:45`

**Issue:** Mollie retried webhooks bij 5xx. De stap-0 hard-fail-guard wordt vooral fired bij operator-fout (vergeten env-var) — een retry-storm helpt dan niet en vult de audit-tabel snel. Een `Retry-After: 3600`-header (of een 4xx in plaats van 500) zou Mollie's retry-loop temperen tot operator-actie. Niet kritiek; gap-closure-decision was bewust een 500 om monitoring/alerting te triggeren.

**Fix:** Optioneel; bij productie-incident overwegen om de status terug te brengen naar 503 + `Retry-After`-header zodat retry-window groter wordt.

### IN-04: Comment-drift in AbstractMolliePassThroughController over `paymentsController gebruikte 'm eerst als eigen method`

**File:** `app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php:194-195`

**Issue:** Comment expliceert _waarom_ de method daar zit ("gehoisd hierheen na verificatie-gap CR-01") — nuttig voor archeologie, maar het verwijst naar een specifieke review-finding waarvan de identifier in de toekomst weinig zegt. Een korte refactor-commit-hash of plan-link zou stabieler zijn dan een review-finding-nummer.

**Fix:**
```php
 * Gedeeld pad voor alle 5 write-endpoints (D-06 / 05a-06-PLAN, plan-section 2.1).
 * PaymentsController had eerder een eigen kopie; hoist na verificatie-gap.
```

---

_Reviewed: 2026-05-15_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
_Scope: 05a-06 gap-closure delta only (CR-01 + CR-02 fixes from 05a-REVIEW-pre-gap-closure.md)_
