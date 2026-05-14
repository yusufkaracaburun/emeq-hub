---
phase: 05a-mollie-sdk-resources-webhooks-pass-through-api
reviewed: 2026-05-15T00:00:00Z
depth: standard
files_reviewed: 50
files_reviewed_list:
  - .env.example
  - app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php
  - app/Http/Controllers/Api/V1/Mollie/CustomersController.php
  - app/Http/Controllers/Api/V1/Mollie/MandatesController.php
  - app/Http/Controllers/Api/V1/Mollie/PaymentLinksController.php
  - app/Http/Controllers/Api/V1/Mollie/PaymentMethodsController.php
  - app/Http/Controllers/Api/V1/Mollie/PaymentsController.php
  - app/Http/Controllers/Api/V1/Mollie/RefundsController.php
  - app/Http/Controllers/Api/V1/Mollie/SubscriptionsController.php
  - app/Http/Controllers/Webhooks/MollieWebhookController.php
  - app/Http/Middleware/ResolveMollieAccount.php
  - app/Http/Requests/Api/V1/Mollie/CreateCustomerRequest.php
  - app/Http/Requests/Api/V1/Mollie/CreatePaymentLinkRequest.php
  - app/Http/Requests/Api/V1/Mollie/CreatePaymentRequest.php
  - app/Http/Requests/Api/V1/Mollie/CreateRefundRequest.php
  - app/Http/Requests/Api/V1/Mollie/CreateSubscriptionRequest.php
  - app/Http/Requests/Api/V1/Mollie/UpdatePaymentRequest.php
  - app/Jobs/ForwardMollieWebhookToConsumer.php
  - app/Models/Consumer.php
  - app/Support/Mollie/ConsumerIdempotencyKeyGenerator.php
  - app/Support/Mollie/MollieHeaderForwarder.php
  - app/Support/Mollie/MollieUpstreamErrorMapper.php
  - bootstrap/app.php
  - config/mollie.php
  - config/services.php
  - config/webhook-client.php
  - config/webhook-server.php
  - database/factories/ConsumerFactory.php
  - database/migrations/2026_05_16_000001_add_webhook_callback_to_consumers_table.php
  - routes/api.php
  - routes/webhooks.php
  - tests/Concerns/BindsMollieConnectionContext.php
  - tests/Concerns/StubsMollieClient.php
  - tests/Feature/Api/SanctumAbilityTest.php
  - tests/Feature/Api/V1/Mollie/CustomersTest.php
  - tests/Feature/Api/V1/Mollie/MandatesTest.php
  - tests/Feature/Api/V1/Mollie/MollieIdempotencyForwardTest.php
  - tests/Feature/Api/V1/Mollie/MolliePassThroughAuditTest.php
  - tests/Feature/Api/V1/Mollie/MolliePassThroughErrorMappingTest.php
  - tests/Feature/Api/V1/Mollie/MolliePassThroughResolutionTest.php
  - tests/Feature/Api/V1/Mollie/PaymentLinksTest.php
  - tests/Feature/Api/V1/Mollie/PaymentMethodsTest.php
  - tests/Feature/Api/V1/Mollie/PaymentsTest.php
  - tests/Feature/Api/V1/Mollie/RefundsTest.php
  - tests/Feature/Api/V1/Mollie/StubMollieClient.php
  - tests/Feature/Api/V1/Mollie/SubscriptionsTest.php
  - tests/Feature/Documentation/ScrambleRouteDiscoveryTest.php
  - tests/Feature/Webhooks/MollieWebhookAntiSpoofingTest.php
  - tests/Feature/Webhooks/MollieWebhookFanOutTest.php
  - tests/Feature/Webhooks/MollieWebhookSignatureTest.php
  - tests/Feature/Webhooks/ThrowingMollieApiClient.php
  - tests/Unit/Support/Mollie/MollieUpstreamErrorMapperTest.php
findings:
  critical: 2
  warning: 6
  info: 5
  total: 13
status: critical
---

# Phase 05a: Code Review Report

**Reviewed:** 2026-05-15
**Depth:** standard
**Files Reviewed:** 50
**Status:** critical

## Summary

Solide pass-through fundament: multi-tenant Connection-resolution (Bearer → Consumer → Account → Connection) is afgesloten, audit-row-shape is netjes (path = template, query_keys-only, NULL-fingerprint bij lege body), error-mapping cloaked 401/403 naar 502 conform threat T-05a-06, en webhook-ingress voert HMAC-verify + anti-spoofing-fetch uit voordat het de fan-out dispatcht. RED/GREEN TDD-discipline is zichtbaar (stub-clients in tests, idempotency-forward-bewijs, vendor-discovery in MandatesController/SubscriptionsController-doccomments).

**Twee BLOCKERs** zijn echter overgeslagen:

1. **Idempotency-Key forwarding (D-06) is ALLEEN geïmplementeerd op `PaymentsController::store`.** De vier andere POST-controllers (Customers, Refunds, Subscriptions, PaymentLinks) bouwen de Mollie-client niet via `buildClient()` en negeren dus de `Idempotency-Key`-header van de Consumer. D-06 noemt expliciet alle vijf POST-endpoints. Dit is een correctheidsfout met klant-impact (dubbele customers/refunds/subscriptions/payment-links bij retry-storm).
2. **`MollieWebhookController` accepteert webhooks zonder HMAC-secret.** `config('services.mollie.webhook_secret')` defaults naar `null` (zie `.env.example`: `MOLLIE_WEBHOOK_SECRET=`). De `(string) config(...)` cast levert dan een lege string `''` op, en `SignatureValidator` accepteert een lege secret zonder error — een aanvaller die een geldige HMAC met lege secret produceert kan de signature-stap omzeilen. Threat T-05a-06 / D-08 stap 1 vereist een hard fail bij ontbrekende platform-secret.

Daarnaast vier waarschuwingen rond dead-code (`MollieHeaderForwarder`, `ConsumerIdempotencyKeyGenerator`, `UpdatePaymentRequest`), een whole-header-dump in webhook-audit, een ongedefinieerd `cancelUrl`-veld in `CreatePaymentRequest`/`UpdatePaymentRequest` dat Mollie niet kent, en een test-bug in `MolliePassThroughAuditTest` (dubbele `bindMollieStub()` met inconsistente intentie).

## Critical Issues

### CR-01: Idempotency-Key wordt op 4 van 5 POST-endpoints NIET doorgegeven aan Mollie (D-06 violation)

**File:** `app/Http/Controllers/Api/V1/Mollie/CustomersController.php:65`, `app/Http/Controllers/Api/V1/Mollie/RefundsController.php:35`, `app/Http/Controllers/Api/V1/Mollie/SubscriptionsController.php:54`, `app/Http/Controllers/Api/V1/Mollie/PaymentLinksController.php:49`

**Issue:**
Alleen `PaymentsController::store` gebruikt `buildClient($request)` om de Consumer's `Idempotency-Key`-header via `MollieApiClient::setIdempotencyKey()` te forwarden. De overige POST-controllers roepen `Mollie::client()->{endpoint}->create(...)` direct aan, waardoor de header van de Consumer verloren gaat en de SDK-default UuidV7-generator wordt gebruikt — een fresh key per call.

Dit breekt D-06 voor `customers`, `refunds`, `subscriptions` en `payment-links`. Consumer-retries op netwerk-timeouts produceren duplicate resources bij Mollie (dubbele Customers/Refunds/Subscriptions/PaymentLinks), wat boekhoudkundig pijn doet bij refunds en subscriptions — daar veroorzaakt het feitelijk geld-bewegingen.

D-06 (05a-CONTEXT.md): "Write operations (POST payments/customers/refunds/subscriptions/payment-links) should forward Consumer's Idempotency-Key header to Mollie unchanged."

Bewijs van het missing-test-gat: `MollieIdempotencyForwardTest` test alleen `/v1/mollie/payments`. Er is geen test die `Idempotency-Key` voor de andere vier endpoints valideert.

**Why it matters here:**
- Naschool is de v0.2 consumer; dubbele refunds bij Mollie = direct financieel risico.
- Bij subscription-retries kan een Account meerdere actieve subscriptions krijgen (Mollie staat dit toe; debiteert beide).
- Anti-AI-cliché-vrij: de claim "alle write-endpoints forwarden" in 05a-CONTEXT.md D-06 is dus onwaar; review-blocker.

**Fix:**
Verplaats `buildClient()` (en de bijbehorende `Idempotency-Key`-header-pickup) naar `AbstractMolliePassThroughController` zodat alle controllers het pad gebruiken. Voorbeeld:

```php
// In AbstractMolliePassThroughController
protected function mollieClient(Request $request): MollieApiClient
{
    $client = app(\Emeq\MollieApi\Mollie::class)->client();

    $consumerKey = $request->header('Idempotency-Key');
    if (is_string($consumerKey) && $consumerKey !== '') {
        $client->setIdempotencyKey($consumerKey);
    }

    return $client;
}
```

Update de vier write-controllers om `$this->mollieClient($r)->customers->create(...)` (resp. `paymentRefunds->createForId`, `subscriptions->createForId`, `paymentLinks->create`) aan te roepen. Refactor `PaymentsController::buildClient` weg.

Voeg per controller een test toe in de stijl van `MollieIdempotencyForwardTest::test_consumer_idempotency_key_is_forwarded_verbatim_to_mollie` — bv. `CustomersTest::test_consumer_idempotency_key_is_forwarded_on_customer_create`.

---

### CR-02: `MollieWebhookController` accepteert webhooks wanneer `MOLLIE_WEBHOOK_SECRET` leeg is

**File:** `app/Http/Controllers/Webhooks/MollieWebhookController.php:39-52`

**Issue:**
```php
$valid = MollieWebhookSignature::verify(
    $request,
    (string) config('services.mollie.webhook_secret'),
);
```

`MOLLIE_WEBHOOK_SECRET` is leeg in `.env.example` (line 74) en `config('services.mollie.webhook_secret')` returnt `null` als de env ontbreekt. De `(string)`-cast maakt er `''` van. Mollie's `SignatureValidator` werkt vervolgens met een lege secret en accepteert elke signature die met die lege secret berekend is — `hash_hmac('sha256', $payload, '')` is gewoon een geldige HMAC. Een aanvaller die de payload kent kan de bijbehorende signature triviaal genereren.

In productie zonder gezette `MOLLIE_WEBHOOK_SECRET` is de webhook-ingress dus effectief open. Threat T-05a-06 en D-08 stap 1 vereisen een hard fail.

**Why it matters here:**
- `services.mollie.webhook_secret` heeft geen default én geen runtime-guard in `MollieWebhookController`.
- Een misconfiguratie op deploy (vergeten env-var) leidt stilzwijgend tot een open ingress in plaats van een 5xx — exact het anti-pattern dat de chirurgisch-wijzigen / fail-loudly regel uit `.ai/rules/engineering.md` probeert te voorkomen.
- `auditFailedWebhook` zou nooit getriggerd worden voor deze klasse fouten, dus er is ook geen monitoring-signaal.

**Fix:**
Hard fail vóór `MollieWebhookSignature::verify()`:

```php
$secret = config('services.mollie.webhook_secret');
if (! is_string($secret) || $secret === '') {
    $this->auditFailedWebhook($request, 'webhook_secret_not_configured');
    return response()->json(['error' => 'webhook_misconfigured'], 500);
}

try {
    $valid = MollieWebhookSignature::verify($request, $secret);
} catch (InvalidSignatureException $e) {
    // ...
}
```

Voeg een test toe in `MollieWebhookSignatureTest`:

```php
public function test_missing_platform_secret_returns_500_and_does_not_dispatch(): void
{
    config(['services.mollie.webhook_secret' => null]);
    // ...assert 500 + Bus::assertNotDispatched
}
```

Optioneel + breder: voeg een boot-time guard in `AppServiceProvider::boot()` of een dedicated provider die in `production` env een `RuntimeException` gooit wanneer de secret leeg is.

## Warnings

### WR-01: `MollieWebhookController` dumpt alle inkomende headers in `webhook_calls`-audit

**File:** `app/Http/Controllers/Webhooks/MollieWebhookController.php:89`, `:105`

**Issue:**
```php
'headers' => $request->headers->all(),
```

Mollie stuurt geen Authorization-headers (de auth is HMAC-via-body), dus directe secret-leak is onwaarschijnlijk. Maar:

1. De `X-Mollie-Signature` waarde wordt op-geslagen — dat is de HMAC zelf. Op zichzelf niet geheim (de secret is dat), maar het is een aanvalsvector als de audit-tabel ooit gelekt wordt en de Consumer hetzelfde HMAC-pattern voor zijn eigen API gebruikt.
2. `config/webhook-client.php:57` heeft `'store_headers' => []` als policy (Spatie default = niets opslaan tenzij expliciet). Deze controller bypasst die config en doet het zelf — patroon-conflict (`.ai/rules/engineering.md` "conflicten oppervlakken, niet uitmiddelen").
3. Iedere ingress-tester of partner-proxy kan extra headers injecteren die we daarna in de DB hebben (User-Agent, X-Forwarded-For met IP-PII).

**Fix:**
Maak een whitelist analoog aan `MollieHeaderForwarder` voor inkomende audit, bv. `['Content-Type', 'X-Mollie-Signature', 'User-Agent']` waarbij X-Mollie-Signature optioneel ge-fingerprint wordt:

```php
private function auditHeaders(Request $request): array
{
    $allow = ['Content-Type', 'User-Agent'];
    $out = [];
    foreach ($allow as $name) {
        if ($v = $request->header($name)) {
            $out[$name] = $v;
        }
    }
    if ($sig = $request->header('X-Mollie-Signature')) {
        $out['X-Mollie-Signature-Fingerprint'] = substr(hash('sha256', $sig), 0, 12);
    }
    return $out;
}
```

---

### WR-02: `CreatePaymentRequest` / `UpdatePaymentRequest` valideren `cancelUrl` — niet bestaand bij Mollie's Create Payment

**File:** `app/Http/Requests/Api/V1/Mollie/CreatePaymentRequest.php:36`, `app/Http/Requests/Api/V1/Mollie/UpdatePaymentRequest.php:32`

**Issue:**
Mollie's Create Payment v2 endpoint kent geen `cancelUrl` parameter (zie [docs.mollie.com/reference/create-payment](https://docs.mollie.com/reference/create-payment)). De velden zijn `redirectUrl` en `webhookUrl`. `cancelUrl` is wel een veld op `paymentLinks->create` (en op `orders` v1 legacy), maar niet op `payments`.

Door `cancelUrl` als nullable URL te whitelisten gaat een Consumer denken dat de Hub het veld accepteert; de Hub geeft het dan door aan Mollie, die het ofwel silent dropt (geen feedback) ofwel een 422 met "no such field" terugkaatst (afhankelijk van Mollie's strict-mode). `.ai/rules/global.md` ("Geen verzonnen partner-features") verbiedt dit pattern expliciet.

**Why it matters here:**
- De plan-docstring noemt `redirectUrl` en `webhookUrl` als toegestaan; `cancelUrl` is daar niet bij.
- Spec-drift maakt latere SDK-bumps van `mollie/mollie-api-php` riskant.

**Fix:**
Verwijder `cancelUrl` uit beide rule-arrays. Als de Consumer per ongeluk `cancelUrl` stuurt, valt dat vanzelf onder Laravel's input-passthrough — maar dan zonder false-positive Hub-validatie.

---

### WR-03: `MolliePassThroughAuditTest::test_audit_row_request_fingerprint_is_null_for_empty_post_body` overschrijft zijn eigen stub

**File:** `tests/Feature/Api/V1/Mollie/MolliePassThroughAuditTest.php:71-87`

**Issue:**
De test bindt eerst een stub die `ValidationException` gooit (line 72), bindt vervolgens **opnieuw** een succes-stub (line 82) en doet uiteindelijk een `GET` om de NULL-fingerprint-pad te dekken. De eerste `bindMollieStub` op line 72 is dead code — die wordt direct overschreven en heeft geen invloed op de assertie. Het commentaarblok ertussen erkent dit gedeeltelijk ("Voor de NULL-fingerprint-case is `GET` zonder body de juiste reproductie") maar de eerste bind blijft staan, wat de test-intentie troebel maakt.

Dit verbergt potentieel een test-quality regressie: een toekomstige reviewer kan denken dat de stub-volgorde betekent iets en daarop voortbouwen.

**Fix:**
Verwijder lines 72 en het commentaarblok daaromheen. Behoud alleen:

```php
public function test_audit_row_request_fingerprint_is_null_for_empty_post_body(): void
{
    [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_READ]);

    $this->bindMollieStub(fn (string $op, mixed $arg) => $this->makePayment([
        'id' => $arg,
        'status' => 'paid',
    ]));

    $this->callMollie($token, 'GET', '/v1/mollie/payments/tr_empty_body')->assertOk();

    $row = PassThroughCall::query()->latest('id')->first();
    $this->assertNotNull($row);
    $this->assertNull($row->request_fingerprint);
}
```

En overweeg een aparte test `test_audit_row_request_fingerprint_is_null_for_failed_validation` voor het ValidationException-pad als die coverage gewenst is.

---

### WR-04: `RateLimitException` mapping laat `Retry-After` header weg — Mollie's docs vereisen client-backoff

**File:** `app/Support/Mollie/MollieUpstreamErrorMapper.php:76-90`

**Issue:**
De inline comment erkent het probleem: "RateLimitException exposeert (nog) geen retry-after-getter; we laten de header leeg. Mollie's docs zeggen dat clients een default-backoff van 60s mogen hanteren."

Daarmee laat de Hub Consumer-apps stuurloos: ze krijgen een 429 zonder `Retry-After` en weten niet hoe lang ze moeten wachten. RFC 6585 (429) markeert `Retry-After` als sterk-aanbevolen. Bij geen-vendor-getter is een statische default (Mollie's documented 60s) beter dan helemaal geen header.

**Why it matters here:**
- Pass-through-pattern: de Hub MOET de upstream-semantiek doorgeven; deze hard-coded silence breekt dat principe.
- Naschool's batch-importer kan in een retry-storm raken zonder Retry-After-info en Mollie meerdere keren raken — wat de rate-limit erger maakt.

**Fix:**
Voeg een statische default toe tot de SDK een getter exposeert:

```php
if ($exception instanceof RateLimitException) {
    return [
        'status' => 429,
        'body' => [
            'error' => 'rate_limited',
            'message' => $exception->getMessage(),
            'upstream_status' => 429,
        ],
        'headers' => ['Retry-After' => '60'],
        'short_code' => null,
    ];
}
```

Plus een TODO/feedback-memory om de SDK uit te breiden met een `getRetryAfter()`-method.

---

### WR-05: `Webhook-callback URL` op Consumer is een `string` — geen scheme-validatie of HTTPS-enforcement

**File:** `app/Models/Consumer.php:12`, `database/migrations/2026_05_16_000001_add_webhook_callback_to_consumers_table.php:11-13`

**Issue:**
`webhook_callback_url` is een nullable `string` zonder validatie op insert/update of een runtime-check vóór `WebhookCall::create()->url(...)->dispatch()`. Een Consumer (of admin) kan een `http://`-URL of zelfs `file:///` insteken, en Spatie zou dan probeer-loggen via die URL. `config/webhook-server.php:80` heeft `verify_ssl => true` maar dat raakt alleen TLS-cert-validatie, niet protocol-enforcement.

Daarnaast: er is geen tabelbreedte-check (Postgres `string` default 255), dus zeer lange URLs kunnen silently afkappen of een runtime-error veroorzaken op insert.

**Why it matters here:**
- `.ai/rules/global.md` security-section vraagt om strict per-Connection secrets — een per-Consumer non-HTTPS URL is een MITM-vector voor het outgoing-pad.
- Cross-Consumer-leakage-policy: als Consumer A's callback URL `http://A.example.com` is en die DNS gespoofed wordt, gaat de payload (inclusief Mollie-id's van Consumer A's tenants) naar de aanvaller.

**Fix:**
Twee opties (kies één):
1. **Runtime-check in `ForwardMollieWebhookToConsumer::handle()`:**
   ```php
   if (! str_starts_with($consumer->webhook_callback_url, 'https://')) {
       Log::warning('Consumer webhook_callback_url is not HTTPS', ['consumer' => $consumer->id]);
       return;
   }
   ```
2. **Model-level validatie** via een dedicated Form Request voor Consumer-CRUD (Phase 9 Filament admin-UI?) die `url:https` enforced.

Voeg een test toe: `test_forward_job_drops_non_https_callback_url`.

---

### WR-06: `MollieWebhookController` audit dubbel-blokken bij `auditFailedWebhook` — payload retrieved zonder rewind

**File:** `app/Http/Controllers/Webhooks/MollieWebhookController.php:106`

**Issue:**
```php
'payload' => $request->json()->all() ?: ['_raw' => substr($request->getContent(), 0, 1000)],
```

`Illuminate\Http\Request::json()` parses een JSON-body op de eerste call en cached het. `getContent()` haalt de raw input stream op die in PHP een one-shot stream is. Op een Laravel-`Request` is dit gepatcht om idempotent te zijn (via `getContent(true)`-pattern in Symfony's `Request`), maar in CLI-/PSR-context kan dit problematisch zijn. Het patroon werkt vandaag, maar de fallback `['_raw' => substr(...)]` is bedoeld voor non-JSON payloads — Mollie stuurt echter altijd `application/x-www-form-urlencoded` (NIET JSON) voor productie-webhooks. **Dat is een correctheidsissue:** als Mollie ooit een productie-webhook met `Content-Type: application/x-www-form-urlencoded; charset=utf-8` stuurt (zoals hun docs aangeven voor legacy v1 én voor sommige v2-call-backs), dan is `$request->json()->all()` leeg → val terug op `_raw` (truncated tot 1000 chars) en het hele controller-pad faalt al bij `$request->json()->all()` op line 68 met een `missing_payload_id` audit. Tests die `application/json` versturen dekken dit niet af.

**Why it matters here:**
- Mollie's officiële webhook-spec ([docs.mollie.com/reference/v2/payments-api/webhook](https://docs.mollie.com/reference/v2/payments-api/webhook)) gebruikt een **`application/x-www-form-urlencoded`** POST met `id=tr_xxx` als body. De Hub verwacht JSON. Dit is een potentiële complete bypass: alle echte Mollie-webhooks worden afgekeurd met `missing_id` en de fan-out gebeurt nooit.
- Vraag: heeft preflight 05a-02 dit getoetst tegen een echte Mollie testmode-webhook? De tests in `MollieWebhookSignatureTest`/`MollieWebhookFanOutTest` versturen allemaal `Content-Type: application/json`, dat is dus geen valide preflight.

**Fix:**
Parse de body methode-agnostisch:

```php
$contentType = strtolower((string) $request->header('Content-Type', ''));
$payload = match (true) {
    str_starts_with($contentType, 'application/json') => $request->json()->all(),
    str_starts_with($contentType, 'application/x-www-form-urlencoded') => $request->request->all(),
    default => [],
};
```

Voeg een test toe met form-encoded body:

```php
public function test_form_encoded_webhook_with_valid_signature_is_accepted(): void {
    // ...$this->call('POST', ..., ['id' => 'tr_form_1'], ..., ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 'id=tr_form_1');
}
```

Verifieer parallel of `MollieWebhookSignature::verify` ook op `application/x-www-form-urlencoded` body's correct werkt (signature wordt over de raw body berekend, dus body-type onafhankelijk — maar bewijs het via test).

## Info

### IN-01: `MollieHeaderForwarder` is dead code

**File:** `app/Support/Mollie/MollieHeaderForwarder.php`

**Issue:**
De class staat in het support-namespace, exposeert `forward(Request)` met Accept + Content-Type-whitelist, maar wordt door geen enkele controller of test aangeroepen (`grep -rn MollieHeaderForwarder app/ routes/ tests/` → enkel de definition). Doccomment verwijst naar D-06 maar dat pad gebruikt `setIdempotencyKey()` direct.

**Fix:**
Ofwel: gebruiken in `AbstractMolliePassThroughController` om consistent Accept-header naar Mollie te forwarden, ofwel: verwijderen. Engineering-rule "chirurgisch wijzigen" gaat niet over scope-creep maar over dead-code-vermijding bij feature-PRs.

---

### IN-02: `ConsumerIdempotencyKeyGenerator` is dead code

**File:** `app/Support/Mollie/ConsumerIdempotencyKeyGenerator.php`

**Issue:**
Geen enkele caller (`grep -rn ConsumerIdempotencyKeyGenerator app/ tests/`). Het doccomment erkent dat: "Voor het reguliere consumer-Idempotency-Key forward-pad gebruikt PaymentsController de eenvoudiger MollieApiClient::setIdempotencyKey()". De class blijft "beschikbaar voor toekomstige call-pad" en "tests die generator-injection willen verifiëren" — geen daarvan bestaat in deze codebase.

**Fix:**
Verwijderen. Bij toekomstige behoefte is de class triviaal te herintroduceren (5 regels). Speculatief-houden van code is een anti-pattern voor "chirurgisch wijzigen".

---

### IN-03: `UpdatePaymentRequest` heeft geen route

**File:** `app/Http/Requests/Api/V1/Mollie/UpdatePaymentRequest.php`

**Issue:**
De class staat klaar voor `PATCH /v1/mollie/payments/{id}`, maar `routes/api.php` registreert geen patch-route en `PaymentsController` heeft geen `update`-method. Doccomment zegt zelf: "PATCH-route zelf wordt in 05a-04+ geactiveerd indien nodig — request-class staat klaar."

Speculatief code-pad — dezelfde regel als IN-02.

**Fix:**
Verwijderen tot een Phase plant en bouwt. Of: registreer de PATCH-route + controller-method nu zodat Scramble het ook documenteert.

---

### IN-04: Hardcoded URL-template via `url()` ipv named route in `PaymentsController`

**File:** `app/Http/Controllers/Api/V1/Mollie/PaymentsController.php:44`

**Issue:**
```php
$payload['webhookUrl'] = url("/webhooks/mollie/{$connection->getKey()}");
```

`routes/webhooks.php:14` definieert `->name('webhooks.mollie')`. Laravel's `route()` helper is idiomatisch en robuust tegen route-renames; `url()` met hardcoded path breekt silently bij refactor.

**Fix:**
```php
$payload['webhookUrl'] = route('webhooks.mollie', ['connection_id' => $connection->getKey()]);
```

Voeg in `PaymentsTest::test_post_payments_auto_injects_webhook_url_when_consumer_omits_it` dezelfde assertie aan via `route()`.

---

### IN-05: Subscription-validatie regex accepteert `1 day` maar Mollie wil minimaal 1 day en max ~10 years — geen upper-bound

**File:** `app/Http/Requests/Api/V1/Mollie/CreateSubscriptionRequest.php:42`

**Issue:**
```php
'interval' => ['required', 'string', 'regex:/^\d+\s+(day|days|week|weeks|month|months)$/'],
```

Mollie's docs ([reference/create-subscription](https://docs.mollie.com/reference/create-subscription)) noemen geen expliciete max, maar de regex accepteert ook `999999 months`. Edge-validatie hoort grove fouten te vangen; dit haalt 0-prefix-strings ("0 days") binnen die Mollie alsnog moet afkeuren met 422 — wat het hele "lager Mollie-quota-burn"-doel ondermijnt.

Bovendien: Mollie's interval-regex zelf is `^[1-9]\d* (day|days|week|weeks|month|months)$` (geen leading 0). Stem af.

**Fix:**
```php
'interval' => ['required', 'string', 'regex:/^[1-9]\d{0,3}\s+(day|days|week|weeks|month|months)$/'],
```

(Verifieer in `.docs/partners/mollie/` of de officiële regex pinpoint-aanhalen.)

## What was good

- **D-05 audit-row-discipline is sterk** — `MolliePassThroughAuditTest` dekt alle drie de eerder-gevonden 5b-blockers (path = template, query_keys-only-keys, NULL-fingerprint bij empty body) plus een dedicated test dat raw access-tokens niet in audit-kolommen lekken. Patroon waarop volgende providers mogen voortbouwen.
- **Multi-tenant resolution chain is consistent** — `MolliePassThroughResolutionTest` dekt happy + 4 failure paths (missing header / unknown account / cross-Consumer / no active Connection) en valideert dat `MollieConnectionContext::has()` true is. Geen `?connection_id=`-shortcuts, geen header-based tenancy zonder Consumer-validatie.
- **Vendor-discovery via doccomments** — `MandatesController.php` en `SubscriptionsController.php` documenteren expliciet dat ze plan-skelet hebben gecorrigeerd (`$mandates` ipv `$customerMandates`, `$subscriptions` ipv `$customerSubscriptions`). Goede paper-trail voor "lezen vóór schrijven" uit `.ai/rules/engineering.md`.
- **D-08 anti-spoofing in webhook-controller** — `MollieWebhookAntiSpoofingTest` test zowel NotFoundException-pad als AuthenticationException-pad voor de stap-3 re-fetch. De controller faalt closed met 400 `resource_ownership_failed`.
- **401/403-cloaking** — `MollieUpstreamErrorMapper` mapt `AuthenticationException` naar 502 `mollie_auth_failed` en lekt geen upstream-detail; threat T-05a-06 conformant en getest in `MolliePassThroughErrorMappingTest`.
- **RED/GREEN-discipline zichtbaar** — `MollieIdempotencyForwardTest` heeft drie sub-tests (verbatim forward, default UUID v7 fallback, dedup-emulation) die elk een ander faalpad zouden vangen.
- **`Connection.fingerprint()`** keert sha256(access_token)[:12] terug — geen ruwe secret-exposure, conform `.ai/rules/global.md`. Het Hidden-attribute (`#[Hidden]`) op `access_token`/`refresh_token`/`client_key`/`subscription_key` zorgt dat ze niet per ongeluk in JSON resources verschijnen.

---

_Reviewed: 2026-05-15_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
