# Phase 05a: Mollie SDK Resources + Webhooks + Pass-through API — Pattern Map

**Mapped:** 2026-05-14
**Files analyzed:** ~38 te creëren / 4 te modifiëren (per CONTEXT D-01..D-13)
**Analogs found:** 38 / 38 (volledig dekkend op Phase 5b shipped + Phase 4 shipped)

## Hoe dit document te gebruiken

Per nieuwe of te wijzigen file: **role**, **data flow**, **closest existing analog**, **match-quality**. Daaronder de **concrete code-uittreksels** die de planner letterlijk kan referencen in `PLAN.md`-acties (bestand + regel-nummers + snippet). Cross-cutting patterns (auth, error-handling, audit, header-forward, idempotency, encryption) staan in §"Shared Patterns" — niet bij elke file herhalen.

## Read-first volgorde voor de planner

1. `.planning/phases/05b-snelstart-pass-through-api/05b-REVIEW.md` — drie BLOCKER-lessen die 5a vanaf dag 1 vermijdt (path zonder query-string, NULL-fingerprint bij lege body, 415-guard)
2. `.docs/decisions/mollie-passthrough-api.md` — payload-shape contract (Mollie-shape verbatim in/out)
3. `.docs/decisions/pass-through-calls-table.md` — schema is provider-agnostisch, géén nieuwe migration
4. `.docs/decisions/upstream-error-mapping.md` — patroon dat `MollieUpstreamErrorMapper` mirror-t (eigen mapping-tabel per `errors.md`)
5. `.docs/partners/mollie/{payments,customers,refunds,mandates,subscriptions,payment-links,payment-methods}-api.md` — payload-shapes voor Form Requests
6. `.docs/partners/mollie/{webhooks-overview,api-idempotency,errors}.md` — D-06/D-07/D-08/D-13 invulling

---

## File Classification

### Te creëren (38)

| File | Role | Data Flow | Closest Analog | Match Quality | D-ref |
|------|------|-----------|----------------|---------------|-------|
| `app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php` | controller (abstract base) | request-response | `app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php` | role + data-flow (refactored: split per-resource ipv catch-all) | D-01 |
| `app/Http/Controllers/Api/V1/Mollie/PaymentsController.php` | controller (per-resource) | request-response (CRUD) | `Snelstart/PassThroughController.php` + `OAuth/InitController.php` | composite | D-01, D-02 |
| `app/Http/Controllers/Api/V1/Mollie/CustomersController.php` | controller | request-response (CRUD) | `Snelstart/PassThroughController.php` | role-match | D-01, D-02 |
| `app/Http/Controllers/Api/V1/Mollie/PaymentMethodsController.php` | controller (single-action `__invoke`) | request-response (read-only list) | `Api/V1/PingController.php` (single-action) + `Snelstart/PassThroughController.php` (SDK-call shape) | composite | D-01, D-02 |
| `app/Http/Controllers/Api/V1/Mollie/RefundsController.php` | controller | request-response (CRUD nested) | `Snelstart/PassThroughController.php` | role-match | D-01, D-02 |
| `app/Http/Controllers/Api/V1/Mollie/MandatesController.php` | controller | request-response (nested) | `Snelstart/PassThroughController.php` | role-match | D-01, D-02 |
| `app/Http/Controllers/Api/V1/Mollie/SubscriptionsController.php` | controller | request-response (nested CRUD) | `Snelstart/PassThroughController.php` | role-match | D-01, D-02 |
| `app/Http/Controllers/Api/V1/Mollie/PaymentLinksController.php` | controller | request-response (CRUD) | `Snelstart/PassThroughController.php` | role-match | D-01, D-02 |
| `app/Http/Controllers/Webhooks/MollieWebhookController.php` | controller (webhook ingress) | event-driven (signed inbound) | nieuw — geen exacte analog; partial: `Api/V1/OAuth/CallbackController.php` (publiek + state-validation in plaats van signature) | partial (auth-shape) | D-07, D-08 |
| `app/Http/Requests/Api/V1/Mollie/CreatePaymentRequest.php` | form-request | input-validation | `app/Http/Requests/Api/V1/StoreConnectionRequest.php` | role-match | D-01, D-05 |
| `app/Http/Requests/Api/V1/Mollie/UpdatePaymentRequest.php` | form-request | input-validation | `StoreConnectionRequest.php` | role-match | D-01 |
| `app/Http/Requests/Api/V1/Mollie/CreateCustomerRequest.php` | form-request | input-validation | `StoreConnectionRequest.php` | role-match | D-01 |
| `app/Http/Requests/Api/V1/Mollie/CreateRefundRequest.php` | form-request | input-validation | `StoreConnectionRequest.php` | role-match | D-01 |
| `app/Http/Requests/Api/V1/Mollie/CreateSubscriptionRequest.php` | form-request | input-validation | `StoreConnectionRequest.php` | role-match | D-01 |
| `app/Http/Requests/Api/V1/Mollie/CreatePaymentLinkRequest.php` | form-request | input-validation | `StoreConnectionRequest.php` | role-match | D-01 |
| `app/Http/Middleware/ResolveMollieAccount.php` | middleware (tenant-resolver) | request-response (binding) | `app/Http/Middleware/ResolveSnelstartAccount.php` | exact (mirror, andere binding-pad) | D-03 |
| `app/Support/Mollie/MollieUpstreamErrorMapper.php` | support (exception mapper) | transform | `app/Support/Snelstart/UpstreamErrorMapper.php` | exact (mirror, andere mapping-tabel) | D-13 |
| `app/Support/Mollie/MollieHeaderForwarder.php` | support (header whitelist) | transform | `app/Support/Snelstart/HeaderForwarder.php` | exact (mirror, beperktere whitelist — geen If-Match) | D-04 (impliciet) |
| `app/Jobs/ForwardMollieWebhookToConsumer.php` | job (Spatie webhook-server) | event-driven fan-out | nieuw — geen analog; Spatie's `\Spatie\WebhookServer\WebhookCall` API-call-pattern | no-analog (use `RESEARCH.md` / Spatie docs) | D-08 |
| `config/mollie.php` | config | config | nieuw — geen analog; SDK levert `Spatie\LaravelPackageTools\Package::hasConfigFile('mollie')` publish-pad | no-analog (SDK-publish) | D-06 |
| `routes/webhooks.php` | route-file | request-response (publiek) | `routes/api.php` (auth'd block + publieke callback-route) | partial | D-07 |
| `database/migrations/<datum>_add_webhook_callback_to_consumers_table.php` | migration | schema | `database/migrations/2026_05_15_000001_add_oauth_state_to_connections_table.php` | exact (kolommen-toevoegen-pattern) | D-09 |
| `tests/Concerns/BindsMollieConnectionContext.php` | test-trait | test-fixture | `tests/Concerns/PrimesSnelstartTokenCache.php` | role-match (analoog: pre-fill per-request-context) | nieuw |
| `tests/Feature/Api/V1/Mollie/PaymentsTest.php` | test (feature) | request-response | `tests/Feature/Api/V1/Snelstart/PassThroughEchoPingTest.php` | role + data-flow | D-01 |
| `tests/Feature/Api/V1/Mollie/CustomersTest.php` | test (feature) | request-response | `Snelstart/PassThroughEchoPingTest.php` | role-match | D-01 |
| `tests/Feature/Api/V1/Mollie/PaymentMethodsTest.php` | test (feature) | request-response (read-only) | `Snelstart/PassThroughEchoPingTest.php` | role-match | D-01 |
| `tests/Feature/Api/V1/Mollie/RefundsTest.php` | test (feature) | request-response | `Snelstart/PassThroughEchoPingTest.php` | role-match | D-01 |
| `tests/Feature/Api/V1/Mollie/MandatesTest.php` | test (feature) | request-response | `Snelstart/PassThroughEchoPingTest.php` | role-match | D-01 |
| `tests/Feature/Api/V1/Mollie/SubscriptionsTest.php` | test (feature) | request-response | `Snelstart/PassThroughEchoPingTest.php` | role-match | D-01 |
| `tests/Feature/Api/V1/Mollie/PaymentLinksTest.php` | test (feature) | request-response | `Snelstart/PassThroughEchoPingTest.php` | role-match | D-01 |
| `tests/Feature/Api/V1/Mollie/MolliePassThroughResolutionTest.php` | test (feature) | tenant-resolution edge-cases | `Snelstart/PassThroughResolutionTest.php` | exact (mirror) | D-03 |
| `tests/Feature/Api/V1/Mollie/MolliePassThroughErrorMappingTest.php` | test (feature) | exception-mapping per status | `Snelstart/PassThroughErrorMappingTest.php` | exact (mirror, andere short-codes) | D-13 |
| `tests/Feature/Api/V1/Mollie/MolliePassThroughAuditTest.php` | test (feature) | audit-row shape | `Snelstart/PassThroughAuditNoSecretsTest.php` | exact (mirror) | D-05 |
| `tests/Feature/Api/V1/Mollie/MollieIdempotencyForwardTest.php` | test (feature) | idempotency forward | nieuw — geen exacte analog; SDK heeft `MollieApiClient::fake()` voor request-assertions | partial (gebruik SDK-fake) | D-06 |
| `tests/Feature/Webhooks/MollieWebhookSignatureTest.php` | test (feature) | webhook signature happy + tampered | partial: `tests/Feature/Api/OAuth/CallbackTest.php` (state-validation publieke endpoint) | partial | D-07, D-08 |
| `tests/Feature/Webhooks/MollieWebhookFanOutTest.php` | test (feature) | fan-out job-dispatch | nieuw — geen analog; `Bus::fake()` + Spatie-job-assertion | no-analog | D-08 |
| `tests/Feature/Webhooks/MollieWebhookAntiSpoofingTest.php` | test (feature) | anti-spoofing fetch | nieuw — geen analog; SDK `MollieApiClient::fake()` op `payments->get()` | no-analog | D-08 |
| `tests/Unit/Support/Mollie/MollieUpstreamErrorMapperTest.php` | test (unit) | per-exception mapping | `tests/Unit/Support/Snelstart/UpstreamErrorMapperTest.php` (genoemd in 05b-REVIEW maar nog niet gelezen — zelfde pattern) | exact (mirror) | D-13 |

### Te modifiëren (4)

| File | Reden | Closest Analog (voor diff-stijl) |
|------|-------|----------------------------------|
| `routes/api.php` | nieuw `Route::prefix('mollie')->middleware('resolve.mollie.account')->group(...)` blok | bestaand `Route::any('/snelstart/{path}', PassThroughController::class)` blok in `routes/api.php:33-36` |
| `bootstrap/app.php` | alias `'resolve.mollie.account' => ResolveMollieAccount::class` toevoegen + `routes/webhooks.php` registreren in `withRouting()` | bestaand `'resolve.snelstart.account' => ResolveSnelstartAccount::class` in `bootstrap/app.php:21` |
| `app/Models/Consumer.php` | `webhook_callback_url` + `webhook_callback_secret` aan `#[Fillable]` toevoegen + `casts()` voor `webhook_callback_secret => 'encrypted'` | bestaand `app/Models/Connection.php` `casts()`-pattern (regels 53-66) |
| `app/Providers/AppServiceProvider.php` | binding voor `config('mollie.idempotency.generator')` opzetten via SDK-publish OR config-merge | bestaand `register()` in `AppServiceProvider.php:23-35` |

---

## Pattern Assignments

### `AbstractMolliePassThroughController.php` (controller, abstract base)

**Analog:** `app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php` — single-controller catch-all uit 5b. 5a splitst per-resource maar de **niet-controller-specifieke verantwoordelijkheden** (ability-guard, content-type-guard, try/catch + mapper, audit-write, response-render) komen 1-op-1 in een abstract base.

**Imports** (`Snelstart/PassThroughController.php:1-17`):

```php
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Connection;
use App\Models\PassThroughCall;
use App\Sanctum\TokenAbilities;
// vervang Snelstart-imports door Mollie-equivalenten:
use App\Support\Mollie\MollieHeaderForwarder;
use App\Support\Mollie\MollieUpstreamErrorMapper;
use Emeq\MollieApi\Facades\Mollie; // of: Emeq\MollieApi\Mollie via container
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
```

**Ability-guard pattern** (`Snelstart/PassThroughController.php:34-46`) — verbatim, alleen `SNELSTART_*` → `MOLLIE_*`:

```php
$required = $method === 'GET'
    ? [TokenAbilities::MOLLIE_READ, TokenAbilities::MOLLIE_WRITE, TokenAbilities::ADMIN]
    : [TokenAbilities::MOLLIE_WRITE, TokenAbilities::ADMIN];

$token = $request->user()?->currentAccessToken();
$hasAbility = $token !== null && collect($required)->contains(fn (string $ability) => $token->can($ability));

if (! $hasAbility) {
    return response()->json([
        'error' => 'insufficient_ability',
        'message' => 'Token mist vereiste ability voor deze methode.',
    ], Response::HTTP_FORBIDDEN);
}
```

**Content-Type-guard pattern** (`Snelstart/PassThroughController.php:48-59`) — verbatim:

```php
if (in_array($method, ['POST', 'PATCH'], true)) {
    $contentType = strtolower((string) $request->header('Content-Type', ''));
    if (! str_starts_with($contentType, 'application/json')) {
        return response()->json([
            'error' => 'unsupported_content_type',
            'message' => 'Pass-through accepteert alleen application/json voor POST/PATCH.',
        ], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
    }
    $body = $request->json()->all();
} else {
    $body = null;
}
```

**Try/catch + mapper-call** (`Snelstart/PassThroughController.php:65-104`) — vervang Snelstart-SDK-call door per-resource `Mollie::client()->...->...()`-call die de concrete subclass levert:

```php
$start = microtime(true);
$upstreamError = null;
$responseBody = '';
$status = 0;
$contentType = 'application/json';
$extraHeaders = [];

try {
    // CONCRETE-controller levert deze call via abstract method:
    //   protected abstract function performCall(Request $request, array $body): array;
    // Returnt: ['status' => int, 'body' => array, 'content_type' => string, 'extra_headers' => array<string,string>]
    $result = $this->performCall($request, $body ?? []);
    $status = $result['status'];
    $responseBody = json_encode($result['body'], JSON_THROW_ON_ERROR);
    $contentType = $result['content_type'] ?? 'application/json';
    $extraHeaders = $result['extra_headers'] ?? [];
} catch (Throwable $e) {
    $mapped = MollieUpstreamErrorMapper::mapException($e);
    $status = $mapped['status'];
    $responseBody = json_encode($mapped['body'], JSON_THROW_ON_ERROR);
    $contentType = 'application/json';
    $extraHeaders = $mapped['headers'];
    $upstreamError = $mapped['short_code'];
}
```

**Audit-write** (`Snelstart/PassThroughController.php:106-127`) — alle drie 5b-CRITICAL-fixes already-applied; per-resource controllers passen alleen `endpoint` aan:

```php
/** @var Account $account */
$account = $request->attributes->get('mollie_account');
/** @var Connection $connection */
$connection = $request->attributes->get('mollie_connection');

PassThroughCall::create([
    'consumer_id' => $request->user()->getKey(),
    'account_id' => $account->getKey(),
    'connection_id' => $connection->getKey(),
    'provider' => 'mollie',
    'method' => $method,
    // CRITICAL: endpoint = template ZONDER query-string, NIET request-URI
    'path' => $endpoint,                         // bv. '/v2/payments' of '/v2/payments/{id}'
    'query_keys' => $query !== [] ? implode(',', array_keys($query)) : null,
    'status' => $status,
    'duration_ms' => (int) round((microtime(true) - $start) * 1000),
    // CRITICAL: NULL bij empty body (NIET sha256('[]'))
    'request_fingerprint' => (is_array($body) && $body !== [])
        ? substr(hash('sha256', json_encode($body, JSON_THROW_ON_ERROR)), 0, 12)
        : null,
    'response_size_bytes' => strlen($responseBody),
    'upstream_error' => $upstreamError,
    'created_at' => now(),
]);
```

**Response-render** (`Snelstart/PassThroughController.php:129-132`) — verbatim:

```php
return response($responseBody, $status)->withHeaders(array_merge(
    ['Content-Type' => $contentType],
    $extraHeaders,
));
```

**Afwijking 5b → 5a:** geen `RawSnelstartRequest`-equivalent — concrete controllers callen `Mollie::client()->payments->create($body)` etc. en moeten zelf de Mollie-resource-array (uit `->toArray()`) aan de base-class teruggeven via `performCall()`. Endpoint-template (`/v2/payments`, `/v2/payments/{id}`) wordt door concrete controller geleverd, NIET uit `$path` zoals 5b — concrete routes betekenen er is geen `{path}`-parameter.

---

### `PaymentsController.php` (controller, request-response, CRUD)

**Analog (composite):** abstract base + Mollie SDK-API.

**Imports + class-shape** (`Api/V1/OAuth/InitController.php:1-13` — single-action `__invoke` style mag, of resource-controller met `store/show/destroy`):

```php
namespace App\Http\Controllers\Api\V1\Mollie;

use App\Http\Requests\Api\V1\Mollie\CreatePaymentRequest;
use Emeq\MollieApi\Facades\Mollie; // of via container

class PaymentsController extends AbstractMolliePassThroughController
{
    public function store(CreatePaymentRequest $request): \Symfony\Component\HttpFoundation\Response
    {
        return $this->handle($request, '/v2/payments', fn ($validated) =>
            Mollie::client()->payments->create($validated)->toArray()
        );
    }

    public function show(\Illuminate\Http\Request $request, string $id): \Symfony\Component\HttpFoundation\Response
    {
        return $this->handle($request, '/v2/payments/{id}', fn () =>
            Mollie::client()->payments->get($id)->toArray()
        );
    }

    public function destroy(\Illuminate\Http\Request $request, string $id): \Symfony\Component\HttpFoundation\Response
    {
        return $this->handle($request, '/v2/payments/{id}', fn () =>
            Mollie::client()->payments->cancel($id)->toArray()
        );
    }
}
```

**Idempotency-Key forward (D-06)** — als per-controller-stap binnen de SDK-call, `MollieApiClient::setIdempotencyKey()` vóór de resource-call (verifie API-shape bij planning tegen `vendor/mollie/mollie-api-php/src/MollieApiClient.php`):

```php
$client = Mollie::client();
if ($idempotencyKey = $request->header('Idempotency-Key')) {
    $client->setIdempotencyKey($idempotencyKey);
}
// Anders: SDK's UuidV7IdempotencyKeyGenerator wordt gebruikt (geconfigureerd via config/mollie.php)
$payment = $client->payments->create($validated);
```

**Webhook-URL injectie** (D-08 / specifics-CONTEXT line 279):

```php
// In CreatePaymentRequest::validated() of in controller:
$validated = $request->validated();
if (empty($validated['webhookUrl'])) {
    $connection = $request->attributes->get('mollie_connection');
    $validated['webhookUrl'] = url("/webhooks/mollie/{$connection->id}");
}
```

---

### `PaymentMethodsController.php` (controller, single-action, read-only)

**Analog:** `app/Http/Controllers/Api/V1/PingController.php` (single-action `__invoke` retournerend plain array — Laravel cast't naar JSON, zie STATE.md regel 84). Single-action past omdat alleen `list` in 5a-scope is (`payment-methods-api.md`).

**Pattern** (`PingController.php`-shape + abstract-base SDK-call):

```php
namespace App\Http\Controllers\Api\V1\Mollie;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PaymentMethodsController extends AbstractMolliePassThroughController
{
    public function __invoke(Request $request): Response
    {
        return $this->handle($request, '/v2/methods', fn () =>
            \Emeq\MollieApi\Facades\Mollie::client()->methods->all($request->query())->toArray()
        );
    }
}
```

---

### `MollieWebhookController.php` (controller, webhook ingress)

**Analog (partial):** `app/Http/Controllers/Api/V1/OAuth/CallbackController.php` — publieke endpoint, eigen auth-mechanisme (state in plaats van signature), 400-response bij failure. Mollie-webhook is publiek + signature-auth.

**Imports + skelet** (mirror van `CallbackController.php:1-15`):

```php
namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ForwardMollieWebhookToConsumer;
use App\Models\Connection;
use Emeq\MollieApi\Facades\Mollie;
use Emeq\MollieApi\Webhooks\MollieWebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mollie\Api\Exceptions\InvalidSignatureException;
use Spatie\WebhookClient\Models\WebhookCall;

class MollieWebhookController extends Controller
{
    public function __invoke(Request $request, int $connection_id): JsonResponse
    {
        // 1. Signature-verify (D-08 stap 1) — SDK helper, Laravel-Request-aware
        try {
            $valid = MollieWebhookSignature::verify(
                $request,
                config('services.mollie.webhook_secret'),
            );
        } catch (InvalidSignatureException $e) {
            $this->auditFailedWebhook($request, "invalid_signature: {$e->getMessage()}");
            return response()->json(['error' => 'invalid_signature'], 400);
        }
        if (! $valid) {
            $this->auditFailedWebhook($request, 'missing_signature_header');
            return response()->json(['error' => 'missing_signature'], 400);
        }

        // 2. Connection-lookup (D-08 stap 2) — 410 Gone als revoked
        $connection = Connection::query()
            ->where('id', $connection_id)
            ->where('provider', 'mollie')
            ->whereNull('revoked_at')
            ->first();

        if ($connection === null) {
            return response()->json(['error' => 'connection_gone'], 410);
        }

        // 3. Anti-spoofing fetch (D-08 stap 3) — claim moet bij deze Connection horen
        $payload = $request->json()->all();
        if (! isset($payload['id'])) {
            return response()->json(['error' => 'missing_id'], 400);
        }

        app(\App\Mollie\MollieConnectionContext::class)->set($connection);
        try {
            Mollie::client()->payments->get($payload['id']);
        } catch (\Throwable $e) {
            // Spoof: webhook claimt resource die deze Connection niet ziet
            $this->auditFailedWebhook($request, "spoof_check_failed: {$e->getMessage()}");
            return response()->json(['error' => 'resource_ownership_failed'], 400);
        }

        // 4. Inkomend audit naar Spatie's webhook_calls-tabel (D-08 stap 4)
        WebhookCall::create([
            'name' => 'mollie',
            'url' => $request->fullUrl(),
            'headers' => $request->headers->all(),
            'payload' => $payload,
        ]);

        // 5. Fan-out (D-08 stap 5) — gequeued, niet wachten
        ForwardMollieWebhookToConsumer::dispatch($connection, $payload);

        // 6. 202 Accepted (D-08 stap 6)
        return response()->json(['status' => 'accepted'], 202);
    }

    private function auditFailedWebhook(Request $request, string $exception): void
    {
        // Spatie-tabel-shape — exception-veld voor failure-tracking
        WebhookCall::create([
            'name' => 'mollie',
            'url' => $request->fullUrl(),
            'headers' => $request->headers->all(),
            'payload' => $request->json()->all() ?: ['_raw' => $request->getContent()],
            'exception' => $exception,
        ]);
    }
}
```

**SDK-helper** (`packages/mollie-api/src/Webhooks/MollieWebhookSignature.php:32-47`):

```php
public static function verify(Request $request, string|array $signingSecrets): bool
{
    $signatureHeaders = array_values(array_filter(
        $request->headers->all(SignatureValidator::SIGNATURE_HEADER), // 'X-Mollie-Signature'
        static fn (?string $value): bool => null !== $value && '' !== $value,
    ));
    if ([] === $signatureHeaders) { return false; }
    return (new SignatureValidator($signingSecrets))->validatePayload(
        payload: $request->getContent(),
        signatures: $signatureHeaders,
    );
}
```

---

### `ResolveMollieAccount.php` (middleware, tenant-resolver)

**Analog:** `app/Http/Middleware/ResolveSnelstartAccount.php` — exact mirror, alleen het binding-pad wijkt af (D-03).

**Imports + class-shape** (`ResolveSnelstartAccount.php:1-13`) — vervang Snelstart-imports:

```php
namespace App\Http\Middleware;

use App\Models\Account;
use App\Models\Connection;
use App\Mollie\MollieConnectionContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
```

**Header-resolutie + 400** (`ResolveSnelstartAccount.php:30-37`) — verbatim copy:

```php
$accountHeader = $request->header('X-Account-Id');

if (! is_string($accountHeader) || $accountHeader === '') {
    return response()->json([
        'error' => 'missing_account_header',
        'message' => 'Vereiste header X-Account-Id ontbreekt.',
    ], 400);
}
```

**Account-lookup + 404** (`ResolveSnelstartAccount.php:39-51`) — verbatim:

```php
$consumerId = $request->user()?->getKey();

$account = Account::query()
    ->where('consumer_id', $consumerId)
    ->where('external_id', $accountHeader)
    ->first();

if ($account === null) {
    return response()->json([
        'error' => 'account_not_found',
        'message' => 'Account niet gevonden voor deze Consumer.',
    ], 404);
}
```

**Connection-lookup met `provider='mollie'` filter** (`ResolveSnelstartAccount.php:53-64`) — wijzig provider-string:

```php
$connection = Connection::query()
    ->where('account_id', $account->getKey())
    ->where('provider', 'mollie')        // ← was 'snelstart'
    ->whereNull('revoked_at')
    ->first();

if ($connection === null) {
    return response()->json([
        'error' => 'connection_not_found',
        'message' => 'Geen actieve Mollie-Connection voor dit Account.',
    ], 404);
}
```

**KRITIEKE AFWIJKING van Snelstart-pad** (D-03) — geen `app()->instance(...)` rebind, wel `MollieConnectionContext::set()`:

```php
// NIET (Snelstart-stijl):
//   app()->instance(SnelstartCredentialResolver::class, new HubSnelstartCredentialResolver($connection));
//   app()->forgetInstance(Snelstart::class);
//
// WEL (Mollie-stijl per CONTEXT D-03 + AppServiceProvider regel 25):
app(MollieConnectionContext::class)->set($connection);

// NB: AppServiceProvider regel 25 doet `$this->app->scoped(MollieConnectionContext::class)`
// — dat is een per-request-singleton; set() vult de context, HubMollieCredentialResolver
// leest 'm. Géén forgetInstance() nodig omdat:
// (a) MollieCredentialResolver::class is via `bind` (NIET singleton — regel 34) gebound
//     → elke resolve() bouwt nieuw, leest fresh context.
// (b) Mollie::class IS singleton (zie packages/mollie-api/src/MollieServiceProvider.php:31)
//     MAAR de constructor neemt resolver via container-resolve, en client() roept
//     resolver->resolve() bij elke call → tenant-switch werkt zonder forget.
//
// VERIFY bij planning: bevestig in vendor `MollieServiceProvider` dat Mollie::class
// inderdaad singleton is en dat client() altijd verse resolve() doet.
// Indien onverwacht caching: voeg `app()->forgetInstance(Mollie::class)` toe als
// veiligheidsmaatregel, identiek aan Snelstart-middleware regel 74.

$request->attributes->set('mollie_account', $account);
$request->attributes->set('mollie_connection', $connection);

return $next($request);
```

---

### `MollieUpstreamErrorMapper.php` (support, exception transform)

**Analog:** `app/Support/Snelstart/UpstreamErrorMapper.php` — exact mirror van class-shape; mapping-tabel is anders (per `errors.md` + CONTEXT D-13).

**Class-skelet** (`UpstreamErrorMapper.php:1-28`):

```php
<?php

declare(strict_types=1);

namespace App\Support\Mollie;

use Emeq\MollieApi\Exceptions\AuthenticationException;
use Emeq\MollieApi\Exceptions\MollieException;
use Emeq\MollieApi\Exceptions\NotFoundException;
use Emeq\MollieApi\Exceptions\RateLimitException;
use Emeq\MollieApi\Exceptions\ServerException;
use Emeq\MollieApi\Exceptions\ValidationException;
use Mollie\Api\Exceptions\ApiException as MollieApiException;
use Saloon\Exceptions\Request\FatalRequestException;     // alleen als Mollie-SDK Saloon kent — anders weghalen
use Throwable;

final class MollieUpstreamErrorMapper
{
    /**
     * @return array{status: int, body: array<string, mixed>, headers: array<string, string>, short_code: ?string}
     */
    public static function mapException(Throwable $exception): array
    {
        // Eerst-remap: vendor Mollie-exception kan ongemapt zijn als de catch
        // op het basis-niveau gebeurt. Optioneel: $exception = MollieExceptionMapper::map($exception);
        // (zie packages/mollie-api/src/Exceptions/MollieExceptionMapper.php)
```

**Per-status mapping** — letterlijk uit CONTEXT D-13 + `errors.md`:

```php
        if ($exception instanceof ValidationException) {
            return [
                'status' => 422,
                'body' => [
                    'error' => 'validation_failed',
                    'message' => $exception->getMessage(),
                    'upstream_status' => 422,
                    'field' => $exception->getField(), // SDK exposeert ::getField() — zie ValidationException.php:38
                ],
                'headers' => [],
                'short_code' => null, // user-input, geen Hub-cause
            ];
        }

        if ($exception instanceof AuthenticationException) {
            return [
                'status' => 502, // 401/403 cloaked — info-disclosure-mitigatie
                'body' => [
                    'error' => 'mollie_auth_failed',
                    'message' => 'Upstream auth failed',
                    'upstream_status' => 401,
                    'upstream_detail' => 'authentication_failed',
                ],
                'headers' => [],
                'short_code' => 'mollie_auth',
            ];
        }

        if ($exception instanceof NotFoundException) {
            return [
                'status' => 404,
                'body' => ['error' => 'not_found', 'message' => $exception->getMessage(), 'upstream_status' => 404],
                'headers' => [],
                'short_code' => null,
            ];
        }

        if ($exception instanceof RateLimitException) {
            $headers = [];
            // SDK exposeert retry-after — zie packages/mollie-api/src/Exceptions/RateLimitException.php
            // Verifieer property-naam bij planning (waarschijnlijk geen retryAfterSeconds zoals Snelstart;
            // Mollie's TooManyRequestsException heeft headers in $exception->getResponse()).
            return [
                'status' => 429,
                'body' => ['error' => 'rate_limited', 'message' => $exception->getMessage(), 'upstream_status' => 429],
                'headers' => $headers,
                'short_code' => null,
            ];
        }

        if ($exception instanceof ServerException) {
            return [
                'status' => 502,
                'body' => ['error' => 'mollie_unavailable', 'message' => 'Mollie returned 5xx', 'upstream_status' => 503, 'upstream_detail' => 'server_error'],
                'headers' => [],
                'short_code' => 'mollie_5xx',
            ];
        }

        if ($exception instanceof FatalRequestException) {
            return [
                'status' => 504,
                'body' => ['error' => 'mollie_timeout', 'message' => 'Upstream did not respond in time', 'upstream_status' => 0],
                'headers' => [],
                'short_code' => 'mollie_timeout',
            ];
        }

        // Catch-all (MollieException base + onverwachte Throwable)
        return [
            'status' => 502,
            'body' => ['error' => 'mollie_error', 'message' => 'Unexpected upstream failure', 'upstream_status' => 0, 'upstream_detail' => 'unknown'],
            'headers' => [],
            'short_code' => 'mollie_unknown',
        ];
    }
}
```

**Verschillen vs Snelstart-mapper** (`UpstreamErrorMapper.php` regel-vergelijk):
- ValidationException: 5b returnt `400 upstream_validation`; 5a returnt `422 validation_failed` (Mollie's ValidationException IS 422, zie `errors.md` regel 57). Andere `error`-key per CONTEXT D-13.
- ServerException: 5b extracted status uit message (`HTTP 503` regex regel 135-142); 5a kan dat overnemen of fallback `503` constant.
- RateLimitException: `retryAfterSeconds` is Snelstart-property. Mollie SDK exposeert dat NIET zoals Snelstart — bij planning verifieer of we de `Retry-After`-header uit de previous-Throwable's response moeten plukken (`$exception->getResponse()->getHeader('Retry-After')`).
- Short-codes: `snelstart_*` → `mollie_*` (per audit-codes-tabel D-13).

---

### `MollieHeaderForwarder.php` (support, header whitelist)

**Analog:** `app/Support/Snelstart/HeaderForwarder.php` — exact mirror, beperktere whitelist (Mollie heeft geen ETag/If-Match-pad).

**Class** (`HeaderForwarder.php:1-47`) — verbatim, alleen `ALLOWED` aanpassen:

```php
<?php

declare(strict_types=1);

namespace App\Support\Mollie;

use Illuminate\Http\Request;

final class MollieHeaderForwarder
{
    /** @var list<string> */
    private const ALLOWED = ['Accept', 'Content-Type', 'Idempotency-Key']; // Mollie kent geen If-Match/If-None-Match

    /** @return array<string, string> */
    public static function forward(Request $request): array
    {
        $out = [];
        foreach (self::ALLOWED as $name) {
            $value = $request->header($name);
            if (is_string($value) && $value !== '') {
                $out[$name] = $value;
            }
        }
        return $out;
    }
}
```

**NB:** `Idempotency-Key` zit hier alleen als Hub'm naar Mollie wil propageren via raw-header-pad. In de typed-SDK-call-stijl (`Mollie::client()->payments->create($body)`) gebruikt de SDK de `IdempotencyKeyGeneratorContract`; raw-header-forward is dan overbodig. **Beslissing bij planning:** wel/niet header forwarden. CONTEXT D-06 zegt forward via SDK (`->withIdempotencyKey($key)` of equivalent) — dus deze HeaderForwarder kan zonder `Idempotency-Key` blijven.

---

### Form Requests `app/Http/Requests/Api/V1/Mollie/*.php`

**Analog:** `app/Http/Requests/Api/V1/StoreConnectionRequest.php` — folder + class-shape + `authorize() { return true; }` + `rules(): array` (5b-conventie).

**Skelet voor `CreatePaymentRequest.php`** (template — payload-velden uit `payments-api.md`):

```php
<?php

namespace App\Http\Requests\Api\V1\Mollie;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Required (zie payments-api.md "Required" tabel)
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'array'],
            'amount.currency' => ['required', 'string', 'size:3'],            // ISO-4217
            'amount.value' => ['required', 'string', 'regex:/^\d+\.\d{2,}$/'], // Mollie verwacht decimal-string, niet float
            'redirectUrl' => ['nullable', 'url'],
            // Optional (selectie — laat Mollie de rest zelf valideren)
            'webhookUrl' => ['nullable', 'url'],
            'method' => ['nullable'],                                         // string OR string[]
            'metadata' => ['nullable'],
            'sequenceType' => ['nullable', Rule::in(['oneoff', 'first', 'recurring'])],
            'customerId' => ['nullable', 'string'],
            'mandateId' => ['nullable', 'string'],
            'profileId' => ['nullable', 'string'],
            'locale' => ['nullable', 'string', Rule::in([/* zie payments-api.md Locale-lijst */])],
            'testmode' => ['nullable', 'boolean'],
        ];
    }
}
```

**Per-resource Form Requests** volgen exact dit pattern; vereiste velden uit:
- `CreateCustomerRequest`: `customers-api.md` (alle velden optional → minimaal type-checks + `locale` Rule::in)
- `CreateRefundRequest`: `refunds-api.md` (description+amount required, externalReference optional)
- `CreateSubscriptionRequest`: `subscriptions-api.md` (amount+interval+description required; `interval` regex `/^\d+ (day|days|week|weeks|month|months)$/`)
- `CreatePaymentLinkRequest`: `payment-links-api.md` (description required; mutually-exclusive amount/minimumAmount)
- `UpdatePaymentRequest`: subset van `CreatePaymentRequest` (alleen `metadata`, `redirectUrl`, `webhookUrl`, `description`)

**Geen Form Request voor read-only endpoints** (PaymentMethods list, get-by-id, etc.) — query-params worden door Mollie zelf gevalideerd, Hub-edge alleen `mollie:read` ability.

---

### Migration: `add_webhook_callback_to_consumers_table.php`

**Analog:** `database/migrations/2026_05_15_000001_add_oauth_state_to_connections_table.php` — kolommen-toevoegen-pattern op een bestaande tabel.

**Inhoud:**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consumers', function (Blueprint $table): void {
            $table->string('webhook_callback_url')->nullable()->after('slug');
            $table->text('webhook_callback_secret')->nullable()->after('webhook_callback_url'); // text — encrypted-cast levert variabele lengte
        });
    }

    public function down(): void
    {
        Schema::table('consumers', function (Blueprint $table): void {
            $table->dropColumn(['webhook_callback_url', 'webhook_callback_secret']);
        });
    }
};
```

**`Consumer.php` model-aanpassing** (mirror van `Connection.php:53-66` casts-pattern):

```php
#[Fillable(['name', 'slug', 'webhook_callback_url', 'webhook_callback_secret'])]
class Consumer extends Authenticatable
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'webhook_callback_secret' => 'encrypted',
        ];
    }

    // ... bestaande accounts() relation ...
}
```

---

### `routes/webhooks.php` (nieuwe route-file)

**Analog:** `routes/api.php` regels 39-41 (publieke OAuth-callback). Webhook is publiek op identieke manier; signature is de auth.

**Inhoud:**

```php
<?php

use App\Http\Controllers\Webhooks\MollieWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhook Routes — /webhooks/{provider}/{...}
|--------------------------------------------------------------------------
| Publiek; signature is de auth. NIET geprefixed met /v1/. Geregistreerd
| in bootstrap/app.php's withRouting().
*/

Route::post('/webhooks/mollie/{connection_id}', MollieWebhookController::class)
    ->where('connection_id', '[0-9]+')
    ->name('webhooks.mollie');
```

**`bootstrap/app.php` aanpassing** — analog: huidige `withRouting()`-block (regels 11-17). Voeg `then()` of `using()` toe om `routes/webhooks.php` te laden als publiek (zonder `api`-prefix):

```php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    api: __DIR__.'/../routes/api.php',
    apiPrefix: 'v1',
    commands: __DIR__.'/../routes/console.php',
    health: '/up',
    then: function (): void {
        Route::middleware('api') // throttle:api auto-prepended via withMiddleware()
            ->group(base_path('routes/webhooks.php'));
    },
)
```

**Middleware-alias toevoegen** (`bootstrap/app.php:21`):

```php
$middleware->alias([
    'resolve.snelstart.account' => ResolveSnelstartAccount::class,
    'resolve.mollie.account' => ResolveMollieAccount::class, // ← NEW
    'abilities' => CheckAbilities::class,
    'ability' => CheckForAnyAbility::class,
]);
```

---

### `routes/api.php` (modify — voeg Mollie-blok toe)

**Analog:** bestaande Snelstart-blok (`routes/api.php:33-36`) en OAuth-blok (`routes/api.php:28-31`).

**Diff** — voeg toe binnen de bestaande `Route::middleware('auth:sanctum')->group(...)` op regel 19 ná het Snelstart-blok:

```php
use App\Http\Controllers\Api\V1\Mollie\CustomersController;
use App\Http\Controllers\Api\V1\Mollie\MandatesController;
use App\Http\Controllers\Api\V1\Mollie\PaymentLinksController;
use App\Http\Controllers\Api\V1\Mollie\PaymentMethodsController;
use App\Http\Controllers\Api\V1\Mollie\PaymentsController;
use App\Http\Controllers\Api\V1\Mollie\RefundsController;
use App\Http\Controllers\Api\V1\Mollie\SubscriptionsController;

// (binnen het bestaande auth:sanctum group)
Route::prefix('mollie')->middleware('resolve.mollie.account')->group(function (): void {
    // Payments (specifics CONTEXT regel 256)
    Route::get('/payments/{id}', [PaymentsController::class, 'show'])->name('api.mollie.payments.show');
    Route::post('/payments', [PaymentsController::class, 'store'])->name('api.mollie.payments.store');
    Route::delete('/payments/{id}', [PaymentsController::class, 'destroy'])->name('api.mollie.payments.destroy');

    // Refunds nested onder payments
    Route::post('/payments/{id}/refunds', [RefundsController::class, 'store'])->name('api.mollie.payments.refunds.store');
    Route::get('/payments/{id}/refunds', [RefundsController::class, 'index'])->name('api.mollie.payments.refunds.index');
    Route::get('/refunds/{id}', [RefundsController::class, 'show'])->name('api.mollie.refunds.show');

    // Customers
    Route::get('/customers', [CustomersController::class, 'index'])->name('api.mollie.customers.index');
    Route::get('/customers/{id}', [CustomersController::class, 'show'])->name('api.mollie.customers.show');
    Route::post('/customers', [CustomersController::class, 'store'])->name('api.mollie.customers.store');

    // Mandates nested onder customers
    Route::get('/customers/{id}/mandates', [MandatesController::class, 'index'])->name('api.mollie.customers.mandates.index');
    Route::get('/customers/{id}/mandates/{mandate_id}', [MandatesController::class, 'show'])->name('api.mollie.customers.mandates.show');
    Route::delete('/customers/{id}/mandates/{mandate_id}', [MandatesController::class, 'destroy'])->name('api.mollie.customers.mandates.destroy');

    // Subscriptions nested onder customers
    Route::get('/customers/{id}/subscriptions', [SubscriptionsController::class, 'index'])->name('api.mollie.customers.subscriptions.index');
    Route::post('/customers/{id}/subscriptions', [SubscriptionsController::class, 'store'])->name('api.mollie.customers.subscriptions.store');
    Route::get('/customers/{id}/subscriptions/{sub_id}', [SubscriptionsController::class, 'show'])->name('api.mollie.customers.subscriptions.show');
    Route::delete('/customers/{id}/subscriptions/{sub_id}', [SubscriptionsController::class, 'destroy'])->name('api.mollie.customers.subscriptions.destroy');

    // Payment Methods (alleen list — single-action)
    Route::get('/payment-methods', PaymentMethodsController::class)->name('api.mollie.payment-methods.list');

    // Payment Links
    Route::get('/payment-links', [PaymentLinksController::class, 'index'])->name('api.mollie.payment-links.index');
    Route::post('/payment-links', [PaymentLinksController::class, 'store'])->name('api.mollie.payment-links.store');
    Route::get('/payment-links/{id}', [PaymentLinksController::class, 'show'])->name('api.mollie.payment-links.show');
});
```

---

### `config/mollie.php` (nieuw)

**Analog:** geen Hub-side analog. SDK levert default-config via `Spatie\LaravelPackageTools\Package::hasConfigFile('mollie')` (`packages/mollie-api/src/MollieServiceProvider.php:20-22`). Hub-app moet `php artisan vendor:publish --tag=mollie-config` of een eigen `config/mollie.php` neerzetten.

**Minimaal** (voor D-06 idempotency-binding):

```php
<?php

return [
    'idempotency' => [
        // Generator wordt per request via container resolved (Mollie::client() doet make($value))
        'generator' => \Emeq\MollieApi\Idempotency\UuidV7IdempotencyKeyGenerator::class,
    ],

    // Facade-alias (vermijd collision met laravel-mollie); STATE.md: 'EmeqMollie'
    'facade_alias' => 'EmeqMollie',

    // Production-guard tegen test_-prefix in production env
    'enforce_environment' => env('MOLLIE_ENFORCE_ENVIRONMENT', false),
];
```

**`config/services.php` aanpassing** — voeg `mollie.webhook_secret`-key toe (CONTEXT canonical-refs regel 244):

```php
'mollie' => [
    // bestaande connect-block uit Phase 4 …
    'webhook_secret' => env('MOLLIE_WEBHOOK_SECRET'),
],
```

---

### `app/Jobs/ForwardMollieWebhookToConsumer.php` (nieuw)

**Analog:** geen — Spatie's `laravel-webhook-server` levert `WebhookCall::create($url)->payload($payload)->useSecret($secret)->dispatch()` als de canonical pattern. Geen Hub-side analog; gebruik Spatie-docs direct.

**Skelet:**

```php
<?php

namespace App\Jobs;

use App\Models\Connection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Spatie\WebhookServer\WebhookCall;

class ForwardMollieWebhookToConsumer implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(
        public Connection $connection,
        /** @var array<string, mixed> */
        public array $payload,
    ) {}

    public function handle(): void
    {
        $consumer = $this->connection->account->consumer;
        if (! $consumer->webhook_callback_url) {
            return; // Consumer heeft geen callback geconfigureerd — silently skip
        }

        WebhookCall::create()
            ->url($consumer->webhook_callback_url)
            ->payload($this->payload)
            ->useSecret((string) $consumer->webhook_callback_secret) // encrypted cast levert plain string
            ->dispatch();
    }
}
```

---

### `BindsMollieConnectionContext` test-trait (nieuw)

**Analog:** `tests/Concerns/PrimesSnelstartTokenCache.php` — pre-fill van per-request-state voor SDK-calls in tests.

**Skelet:**

```php
<?php

namespace Tests\Concerns;

use App\Models\Connection;
use App\Mollie\MollieConnectionContext;

trait BindsMollieConnectionContext
{
    protected function bindMollieConnection(Connection $connection): void
    {
        app(MollieConnectionContext::class)->set($connection);
    }
}
```

---

### Feature-tests `tests/Feature/Api/V1/Mollie/*.php`

**Per-resource tests** — analog: `tests/Feature/Api/V1/Snelstart/PassThroughEchoPingTest.php` (struktuur + `setupSnelstartConsumer`-helper + `RefreshDatabase` + abilities + audit-row assertions).

**Mock-strategie:** Mollie's `MollieApiClient::fake([...])` (verifieer in `vendor/mollie/mollie-api-php/src/MollieApiClient.php` of equivalent test-helper bestaat; SDK heeft `packages/mollie-api/src/Testing/FakeMollieCredentialResolver.php` voor credentials, en de officiële mollie-api-php levert mock-responses via Guzzle). Bij planning verifieer welke fake-API beschikbaar is.

**Skelet voor `PaymentsTest.php`** — mirror van `PassThroughEchoPingTest.php:36-64`:

```php
<?php

namespace Tests\Feature\Api\V1\Mollie;

use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\PassThroughCall;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BindsMollieConnectionContext;
use Tests\TestCase;

class PaymentsTest extends TestCase
{
    use BindsMollieConnectionContext;
    use RefreshDatabase;

    public function test_post_payments_proxies_through_sdk_and_returns_mollie_payload(): void
    {
        [$consumer, $token, $account, $connection] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        // SDK fake — verifieer exacte API bij planning
        // Mollie::client() of MollieApiClient::fake([...]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', $account->external_id)
            ->postJson('/v1/mollie/payments', [
                'amount' => ['currency' => 'EUR', 'value' => '12.34'],
                'description' => 'Test',
                'redirectUrl' => 'https://example.com/return',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('status', 'open');
        $response->assertJsonStructure(['_links' => ['checkout' => ['href']]]);

        $row = PassThroughCall::query()->first();
        $this->assertSame('mollie', $row->provider);
        $this->assertSame('POST', $row->method);
        $this->assertSame('/v2/payments', $row->path);
        $this->assertNotNull($row->request_fingerprint); // niet-lege body
        $this->assertNull($row->upstream_error);
    }

    /** @return array{0: Consumer, 1: string, 2: Account, 3: Connection} */
    private function setupMollieConsumer(array $abilities): array
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
        $connection = Connection::factory()->forMollie()->active()->for($account)->create();
        $token = $consumer->createToken('test', $abilities)->plainTextToken;
        return [$consumer, $token, $account, $connection];
    }
}
```

**`MolliePassThroughResolutionTest.php`** — exacte mirror van `PassThroughResolutionTest.php` (regel 23-112), vervang:
- `/v1/snelstart/echo/ping` → een Mollie GET-route, bv. `/v1/mollie/payment-methods`
- `forSnelstart()` → `forMollie()->active()`
- `forMollie()` (stale-test pad) → `forSnelstart()` (om te bewijzen dat de provider-filter klopt)

**`MolliePassThroughErrorMappingTest.php`** — mirror van `PassThroughErrorMappingTest.php` met andere short-codes uit `errors.md` + D-13:
| Mollie response | Hub status | error-key | short_code |
|---|---|---|---|
| 401 / 403 | 502 | `mollie_auth_failed` | `mollie_auth` |
| 503 | 502 | `mollie_unavailable` | `mollie_5xx` |
| 422 | 422 | `validation_failed` | `null` |
| 404 | 404 | `not_found` | `null` |
| 429 | 429 + `Retry-After` | `rate_limited` | `null` |
| timeout | 504 | `mollie_timeout` | `mollie_timeout` |

**`MolliePassThroughAuditTest.php`** — mirror van `PassThroughAuditNoSecretsTest.php` (assert: geen `access_token` in audit-row, geen body-content, alleen `request_fingerprint`).

**`MollieIdempotencyForwardTest.php`** — bewijs SC-5 per `api-idempotency.md` regel 84-100:
- 2× identieke POST + identieke `Idempotency-Key`-header → 1 Mollie-payment-id (asserted via fake-counter).

---

### Webhook-tests `tests/Feature/Webhooks/*.php`

**`MollieWebhookSignatureTest.php`** — partial analog: `tests/Feature/Api/OAuth/CallbackTest.php` (publieke endpoint, valid/tampered/expired paden).

**Pattern voor signed payload generation** (gebruik SDK-helper `MollieWebhookSignature::sign()` — `packages/mollie-api/src/Webhooks/MollieWebhookSignature.php:53-56`):

```php
public function test_valid_signature_returns_202_and_dispatches_fan_out_job(): void
{
    Bus::fake();
    config(['services.mollie.webhook_secret' => 'whsec_test_xyz']);

    $connection = Connection::factory()->forMollie()->active()->create();
    $payload = json_encode(['id' => 'tr_abc123']);
    $signature = MollieWebhookSignature::sign($payload, 'whsec_test_xyz');

    // Anti-spoofing fetch moet slagen — fake Mollie::client()->payments->get()

    $response = $this->call(
        'POST',
        "/webhooks/mollie/{$connection->id}",
        [], [], [],
        ['HTTP_X_MOLLIE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
        $payload,
    );

    $response->assertStatus(202);
    Bus::assertDispatched(ForwardMollieWebhookToConsumer::class);
}

public function test_tampered_signature_returns_400_and_no_dispatch(): void
{
    Bus::fake();
    // … signature met wrong_secret …
    $response->assertStatus(400);
    Bus::assertNotDispatched(ForwardMollieWebhookToConsumer::class);
    // Audit-row in Spatie webhook_calls met exception-veld
    $this->assertDatabaseHas('webhook_calls', ['name' => 'mollie', 'exception' => /* not null */]);
}
```

**`MollieWebhookFanOutTest.php`** — assert dat `WebhookCall::create()->url($consumer->webhook_callback_url)->payload($payload)->useSecret(...)->dispatch()` werkt (gebruik Spatie's webhook-server-test-helpers; partial-pattern uit `Bus::fake()`).

**`MollieWebhookAntiSpoofingTest.php`** — fake Mollie's `payments->get()` om 404 te returnen → expect 400 + audit + geen fan-out.

---

### Documentation-test (modify of mirror)

**Analog:** `tests/Feature/Documentation/ScrambleRouteDiscoveryTest.php` (regel 25-48 = pattern per route-bewijs).

**Voeg toe:** assert-blokken per Mollie-resource-route (SC-2 acceptance — CONTEXT specifics regel 283):

```php
public function test_openapi_spec_contains_mollie_payments_routes(): void
{
    $spec = $this->fetchSpec();
    $this->assertArrayHasKey('/mollie/payments', $spec['paths'] ?? []);
    $this->assertArrayHasKey('post', $spec['paths']['/mollie/payments']);
    $this->assertArrayHasKey('/mollie/payments/{id}', $spec['paths'] ?? []);
    $this->assertArrayHasKey('get', $spec['paths']['/mollie/payments/{id}']);
    $this->assertArrayHasKey('delete', $spec['paths']['/mollie/payments/{id}']);
}
// … herhaal per resource: customers, payment-methods, refunds, mandates, subscriptions, payment-links
```

---

## Shared Patterns

### 1. Ability-guard (5b-conventie, alle Mollie-controllers)

**Source:** `app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php:38-46` (inline) + `app/Http/Controllers/Api/V1/AccountController.php:45-51` (helper-method).

**Apply to:** alle controllers in `app/Http/Controllers/Api/V1/Mollie/`. Plaats in `AbstractMolliePassThroughController` als `protected function guardAbility(Request $request): void` zodat alle subclasses hem hergebruiken.

```php
private function guardAbility(Request $request, array $allowed): void
{
    $token = $request->user()?->currentAccessToken();
    $has = $token && collect($allowed)->contains(fn (string $ability) => $token->can($ability));
    abort_unless($has, Response::HTTP_FORBIDDEN, 'insufficient_ability');
}
```

**Mollie-specifiek mapping** (D-14):
- `GET /v1/mollie/*` → any-of `[mollie:read, mollie:write, *]`
- `POST/PATCH/DELETE /v1/mollie/*` → any-of `[mollie:write, *]`
- `POST /webhooks/mollie/{connection_id}` → géén ability (signature is auth)

### 2. Encryption-at-rest cast (alle nieuwe secret-velden)

**Source:** `app/Models/Connection.php:53-66` (model-casts).

**Apply to:** `Consumer::$casts` voor `webhook_callback_secret` (D-09).

```php
protected function casts(): array
{
    return [
        'webhook_callback_secret' => 'encrypted',
    ];
}
```

### 3. Audit-row write (alle pass-through-controllers)

**Source:** `app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php:111-127` (al de drie 5b-CRITICAL-fixes ingebakken).

**Apply to:** `AbstractMolliePassThroughController` — concrete subclasses leveren `endpoint`-string + `body`-array. CRITICAL invariants:
- `path` = endpoint-template (`/v2/payments/{id}`), géén query-string, géén PII.
- `query_keys` = `implode(',', array_keys($query))` of `null`.
- `request_fingerprint` = NULL bij empty/missing body, anders `substr(hash('sha256', json_encode($body)), 0, 12)`.
- `provider` = `'mollie'`.

### 4. Tenant-resolution failure-shape (middleware)

**Source:** `app/Http/Middleware/ResolveSnelstartAccount.php:30-64`.

**Apply to:** `ResolveMollieAccount` — verbatim copy met provider-string-swap. 400 voor missing header, 404 voor unknown account, 404 voor unknown connection.

### 5. Connection-encryption fingerprint-only logging

**Source:** `app/Models/Connection.php:39-48` (`fingerprint()` accessor).

**Apply to:** alle log-statements + audit-rows. Mollie-pad gebruikt `access_token` als secret (regel 43-44). Hub mag fingerprint, NOOIT `access_token` zelf.

### 6. Single-action vs. resourceful controller (Claude's Discretion)

**Source:** `app/Http/Controllers/Api/V1/PingController.php` (single-action `__invoke`) + `app/Http/Controllers/Api/V1/AccountController.php` (resourceful).

**Apply to:**
- Single-action `__invoke` → PaymentMethods (alleen list)
- Resourceful (`store/show/destroy/index`) → Payments, Customers, Refunds, Mandates, Subscriptions, PaymentLinks (multiple operations per resource)

### 7. Test-feature struktuur (`Tests\Feature\Api\V1\Mollie\`)

**Source:** `tests/Feature/Api/V1/Snelstart/` (6 test-files: Resolution, EchoPing, ErrorMapping, Audit, Headers, OdataRelaties).

**Apply to:** `tests/Feature/Api/V1/Mollie/` — minimaal 7 test-files (1 per resource + Resolution + ErrorMapping + Audit + Idempotency).

---

## No Analog Found

Geen file is volledig zonder analog. Drie files vereisen RESEARCH/SDK-docs ipv codebase-analog:

| File | Reason | Compensating reference |
|------|--------|------------------------|
| `app/Jobs/ForwardMollieWebhookToConsumer.php` | Spatie laravel-webhook-server is niet eerder gebruikt in de Hub | Spatie-docs (zie CONTEXT canonical-refs regel 26) — `WebhookCall::create()->url()->payload()->useSecret()->dispatch()` |
| `config/mollie.php` | Geen Hub-side config-publish eerder gedaan | SDK levert via `MollieServiceProvider::configurePackage()` (`packages/mollie-api/src/MollieServiceProvider.php:18-23`) |
| `routes/webhooks.php` | Eerste publieke webhook-route-file in Hub | Pattern uit Laravel-conventie + `bootstrap/app.php`'s `withRouting(then: ...)`-callback voor extra route-files |

---

## Open verifie-punten voor planner

1. **Mollie SDK-API voor idempotency** (D-06): is het `$client->setIdempotencyKey($key)` (zoals Stripe-style) of `$client->payments->withIdempotencyKey($key)->create(...)` (fluent)? Verifieer in `vendor/mollie/mollie-api-php/src/MollieApiClient.php` bij plan-pass.
2. **Mollie SDK-API voor `payments.get()` failure** (D-08 anti-spoofing): retourneert het `NotFoundException` of een silent-empty-response? Verifieer in `vendor/mollie/mollie-api-php/src/Endpoints/PaymentEndpoint.php`.
3. **`Mollie::class` singleton-vs-bind binding** (D-03): bevestig in `packages/mollie-api/src/MollieServiceProvider.php:31` dat singleton-shape geen tenant-cache veroorzaakt — anders middleware ook `forgetInstance()` toevoegen zoals Snelstart-middleware regel 74.
4. **`MollieApiClient::fake()` of equivalent** (alle tests): bestaat een SDK-fake helper, of moet Hub eigen Mockery-pad gebruiken?
5. **`RateLimitException::retryAfterSeconds`-equivalent** (D-13): Mollie's SDK exposeert die NIET zoals Snelstart's. Verifieer `packages/mollie-api/src/Exceptions/RateLimitException.php` (Read pas niet gedaan — file is 233 bytes klein) of de retry-after via parent `getResponse()->getHeader('Retry-After')` haalbaar is.

---

## Metadata

**Analog search scope:** `app/Http/Controllers/Api/V1/`, `app/Http/Middleware/`, `app/Http/Requests/Api/V1/`, `app/Models/`, `app/Mollie/`, `app/OAuth/`, `app/Sanctum/`, `app/Services/Snelstart/`, `app/Support/Snelstart/`, `database/migrations/`, `database/factories/`, `routes/`, `tests/Concerns/`, `tests/Feature/Api/`, `tests/Feature/Documentation/`, `tests/Feature/Mollie/`, `tests/Feature/OAuth/`, `packages/mollie-api/src/`.

**Files scanned:** 32 Hub-source-files + 7 SDK-source-files + 7 Mollie partner-doc-files + 3 ADR-files = 49 files.

**Pattern extraction date:** 2026-05-14.
