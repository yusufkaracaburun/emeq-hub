---
phase: 05a-mollie-sdk-resources-webhooks-pass-through-api
plan: 03
type: execute
wave: 2
depends_on: [05a-01]
files_modified:
  - app/Http/Controllers/Api/V1/Mollie/PaymentsController.php
  - app/Http/Requests/Api/V1/Mollie/CreatePaymentRequest.php
  - app/Http/Requests/Api/V1/Mollie/UpdatePaymentRequest.php
  - app/Providers/AppServiceProvider.php
  - config/mollie.php
  - routes/api.php
  - tests/Feature/Api/V1/Mollie/PaymentsTest.php
  - tests/Feature/Api/V1/Mollie/MollieIdempotencyForwardTest.php
  - tests/Feature/Api/V1/Mollie/MolliePassThroughErrorMappingTest.php
  - tests/Feature/Api/V1/Mollie/MolliePassThroughAuditTest.php
autonomous: true
requirements: [MOLL-03, HUB-03]
tags:
  - laravel
  - mollie
  - payments
  - idempotency
  - phpunit

must_haves:
  decisions: [D-01, D-02, D-04, D-06, D-13, D-14]
  truths:
    - "MOLL-03 SC-1: POST /v1/mollie/payments met geldige PAT + Account-id + payload retourneert 201 + Mollie's _links.checkout.href + status='open'"
    - "MOLL-03 SC-5: 2× POST /v1/mollie/payments met dezelfde Idempotency-Key retourneert ÉÉN Mollie-payment-id (geen duplicate)"
    - "GET /v1/mollie/payments/{id} resolved via Mollie::client()->payments->get($id) en streamt response 200 verbatim"
    - "DELETE /v1/mollie/payments/{id} roept Mollie's payments->cancel aan en retourneert resource"
    - "Form Request CreatePaymentRequest valideert minimaal description+amount (per .docs/partners/mollie/payments-api.md)"
    - "WebhookUrl-injectie: als Consumer geen webhookUrl in payload zet, vult Hub automatisch url('/webhooks/mollie/{connection_id}') in"
    - "Error-mapping: Mollie 422 → Hub 422 validation_failed; Mollie 401 → Hub 502 mollie_auth_failed; Mollie 5xx → Hub 502 mollie_unavailable"
    - "Audit-rij per call met provider='mollie', path='/v2/payments[/{id}]', query_keys-only, NULL fingerprint bij empty body"
  artifacts:
    - path: "app/Http/Controllers/Api/V1/Mollie/PaymentsController.php"
      provides: "Resourceful (store/show/destroy) controller extending AbstractMolliePassThroughController"
      contains: "class PaymentsController extends AbstractMolliePassThroughController"
    - path: "app/Http/Requests/Api/V1/Mollie/CreatePaymentRequest.php"
      provides: "Form request met regels uit payments-api.md"
      contains: "class CreatePaymentRequest"
    - path: "config/mollie.php"
      provides: "Idempotency.generator binding op UuidV7IdempotencyKeyGenerator (D-06)"
      contains: "UuidV7IdempotencyKeyGenerator"
    - path: "routes/api.php"
      provides: "3 routes: POST /v1/mollie/payments + GET /v1/mollie/payments/{id} + DELETE /v1/mollie/payments/{id}"
      contains: "mollie/payments"
  key_links:
    - from: "PaymentsController::store"
      to: "Mollie::client()->payments->create"
      via: "Mollie::client()->payments->create($validated)->toArray()"
      pattern: "payments->create"
    - from: "PaymentsController"
      to: "AbstractMolliePassThroughController::handle"
      via: "$this->handle($request, '/v2/payments', fn() => ...)"
      pattern: "\\$this->handle\\("
---

<objective>
Mollie Payments resource: create + get + cancel via pass-through. Plus de Idempotency-Key infrastructuur (D-06) en bewijs van MOLL-03 SC-1 (Mollie checkout-URL terug) en SC-5 (Idempotency-Key forward — geen duplicates).

Purpose: MOLL-03 (Payments-deel) + HUB-03 (eerste resource-pad live) + bewijs van Mollie-error-envelope (D-13) en pass-through-audit (D-05) op een echte resource.

Output: 1 controller + 2 form requests + config/mollie.php + AppServiceProvider-update + 3 routes in api.php + 4 feature-tests (PaymentsTest, IdempotencyForward, ErrorMapping, AuditNoSecrets).
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-CONTEXT.md
@.planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-PATTERNS.md
@.planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-01-PLAN.md
@.docs/partners/mollie/payments-api.md
@.docs/partners/mollie/api-idempotency.md
@.docs/partners/mollie/errors.md
@.docs/decisions/mollie-passthrough-api.md
@CLAUDE.md
@app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php
@app/Http/Middleware/ResolveMollieAccount.php
@app/Support/Mollie/MollieUpstreamErrorMapper.php
@app/Mollie/MollieConnectionContext.php
@app/Models/Connection.php
@app/Sanctum/TokenAbilities.php
@app/Providers/AppServiceProvider.php
@routes/api.php
@bootstrap/app.php
@packages/mollie-api/src/Mollie.php
@packages/mollie-api/src/MollieServiceProvider.php
@packages/mollie-api/src/Idempotency/UuidV7IdempotencyKeyGenerator.php
@packages/mollie-api/src/Exceptions/ValidationException.php

<interfaces>
<!-- Bestaande contracten die dit plan consumeert. -->

From app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php (Plan 05a-01):
```php
abstract class AbstractMolliePassThroughController extends Controller {
    /**
     * @param  callable(\Illuminate\Http\Request): array<string,mixed>  $sdkCall
     *         Returnt resource-array. Mag {status,body}-wrapper returnen voor non-default status.
     */
    protected function handle(\Illuminate\Http\Request $request, string $endpoint, callable $sdkCall): \Symfony\Component\HttpFoundation\Response;
}
```

From packages/mollie-api/src/Mollie.php:
```php
class Mollie {
    public function client(): \Mollie\Api\MollieApiClient;  // builds fresh per call
}
```

Mollie's MollieApiClient API (verifieer in vendor/mollie/mollie-api-php):
```php
$client->payments->create(array $data, array $filters = []): Payment;
$client->payments->get(string $paymentId, array $parameters = []): Payment;
$client->payments->cancel(string $paymentId, array $parameters = []): Payment;
// Payment::toArray() levert array-shape; preferred over ::__toString() of ->jsonSerialize()
```

Mollie idempotency (vendor mollie/mollie-api-php):
- MollieApiClient constructor accepteert `?IdempotencyKeyGeneratorContract $idempotencyKeyGenerator` als 4e arg.
- Onze SDK Mollie::client() roept `applyIdempotencyGenerator()` aan die uit `config('mollie.idempotency.generator')` leest.
- Voor consumer-forward: er bestaat **geen** publieke `setIdempotencyKey($key)` op MollieApiClient.
  → Pad voor consumer-forward: maak een one-shot generator die `$consumerKey` returnt zolang gezet, anders fallback naar UuidV7. Voorbeeld in action-sectie.

From config/mollie.php (te creëren in dit plan):
```php
return [
    'idempotency' => [
        'generator' => \Emeq\MollieApi\Idempotency\UuidV7IdempotencyKeyGenerator::class,
    ],
    'facade_alias' => 'EmeqMollie',
];
```

From .docs/partners/mollie/payments-api.md (verifieer payload-shape bij implement):
- POST /v2/payments — required: `amount.currency` (ISO-4217), `amount.value` (decimal-string), `description` (string).
  Optional: `redirectUrl`, `cancelUrl`, `webhookUrl`, `method`, `metadata`, `sequenceType` (oneoff|first|recurring), `customerId`, `mandateId`, `profileId`, `locale`, `testmode`, `applicationFee`, `routing`.
- GET /v2/payments/{id} — geen body, optional `?include=details,refunds,...`
- DELETE /v2/payments/{id} = cancel.
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 0 (pre-flight): Verify Mollie SDK Idempotency-Key forward-pad + fake-helper voor SC-5</name>
  <files>
    (read-only — geen wijzigingen, alleen vendor-discovery)
  </files>
  <behavior>
    **B3 (locked decision D-06):** SC-5 is een harde gate uit ROADMAP. Twee identieke `POST /v1/mollie/payments` met dezelfde `Idempotency-Key`-header MOETEN één Mollie-payment-ID retourneren — bewezen via `MollieApiClient::fake()`. Geen pragmatic fallback, geen `markTestSkipped`. Deze pre-flight bevestigt dat de SDK-API dat technisch ondersteunt VOORDAT Task 2 + 3 erop bouwen. Bij blokkerende vendor-discovery: STOP en escaleer naar user — niet stilzwijgend skippen.

    **Verifie-punt 1 — `MollieApiClient`-constructor-arg-volgorde + idempotency-generator-injectie:**
    - Lees `vendor/mollie/mollie-api-php/src/MollieApiClient.php` rond regels 140-220.
    - Bevestig EXACT:
      - constructor-signature (named-args volgorde van: httpAdapter, httpAdapterPicker, idempotencyKeyGenerator, en eventuele extra args)
      - publieke method/setter naar runtime-key-injectie (mogelijk: `setIdempotencyKey($key)`, `withIdempotencyKey($key)`, of constructor-only via 4e arg)
      - of `setApiKey($key)` + `setAccessToken($token)` post-construction werken op een vers gebouwde MollieApiClient zonder ServiceProvider-tussenkomst
    - Documenteer welk pad voor consumer-Idempotency-Key forward werkt: (a) constructor-injection met one-shot generator, (b) runtime setter, of (c) Guzzle-middleware-injectie. Pad (a) is preferred per Plan 05a-03 Task 2 sketch.

    **Verifie-punt 2 — `IdempotencyKeyGeneratorContract` method-naam:**
    - Lees `vendor/mollie/mollie-api-php/src/Contracts/IdempotencyKeyGeneratorContract.php`.
    - Bevestig de exacte method-naam (`generate()` vs `generateKey()` vs `__invoke()`).
    - Pas `ConsumerIdempotencyKeyGenerator::class`-implementatie in Task 1 aan tot dezelfde method-naam.

    **Verifie-punt 3 — `MollieApiClient::fake()` test-helper-existence + dedup-emulation-pad:**
    - Bevestig dat `MollieApiClient::fake()` (of equivalent test-helper) bestaat in vendor. Lees relevante helper-class.
    - Bevestig hoe een fake-mock kan reageren OP een idempotency-key-header zodat 2 requests met dezelfde key dezelfde Payment-id retourneren (deduplication-emulation voor SC-5 W7-test).
    - Als `MollieApiClient::fake()` ontbreekt: fallback-pad bepalen — bv. via container-bind van een mock-MollieApiClient OF via een Mockery-double op `Mollie::client()`. Documenteer welk pad Task 3 moet gebruiken.

    **Output:** `.planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-03-PREFLIGHT.md` met sectie per V1/V2/V3.

    **Bij blokker** (bv. constructor accepteert geen idempotency-generator + geen runtime-setter + geen Guzzle-hook): STOP — escaleer naar user voor decision tussen (i) phase-split 05a-03b voor Idempotency-Key-forward (B3 optie b), of (ii) andere mitigatie. NIET silent doorgaan met skip-clause.
  </behavior>
  <read_first>
    - vendor/mollie/mollie-api-php/src/MollieApiClient.php (regels 140-220 — constructor + setters)
    - vendor/mollie/mollie-api-php/src/Contracts/IdempotencyKeyGeneratorContract.php (method-naam)
    - vendor/mollie/mollie-api-php/src/ — zoek naar `Fake.php`, `MollieApiClient::fake()`-helper, of test-double-classes
    - .docs/partners/mollie/api-idempotency.md (server-side dedup-semantics — bevestig dat Mollie zelf dedupliceert binnen 24h-venster)
    - packages/mollie-api/src/Mollie.php (regels 50-95 — applyIdempotencyGenerator implementation)
  </read_first>
  <action>
    Lees de drie bronnen, verzamel uitkomst per verifie-punt. Geen file-wijzigingen. Documenteer in PREFLIGHT.md:

    ```markdown
    # Plan 05a-03 Pre-flight verifie (B3 — SC-5 hard gate)

    ## V1 — MollieApiClient constructor + idempotency-generator-injectie
    Constructor-signature: `__construct(<args verbatim>)`
    Bron: vendor/mollie/mollie-api-php/src/MollieApiClient.php:<regel>
    Pad voor consumer-key-forward: `<a-constructor | b-runtime-setter | c-guzzle-middleware>`
    Implicatie Task 2 buildClient(): <verbatim implementatie-snippet>

    ## V2 — IdempotencyKeyGeneratorContract method-naam
    Method: `<generate() | generateKey() | __invoke()>`
    Bron: vendor/mollie/mollie-api-php/src/Contracts/IdempotencyKeyGeneratorContract.php:<regel>
    Implicatie Task 1 ConsumerIdempotencyKeyGenerator: aanpassen tot exacte method-naam.

    ## V3 — MollieApiClient::fake() + dedup-emulation
    Fake-helper bestaat: `<ja, klasse <X> | nee, fallback Mockery>`
    Bron: <vendor-file>:<regel>
    Pad voor SC-5 W7-test (2× POST same key → same Payment.id): <verbatim test-skelet-snippet>
    ```

    Bij groene uitkomst: Tasks 1-3 hebben pad-zekerheid. Bij blokker: STOP + user-escalatie (geen skip-clause).
  </action>
  <verify>
    <automated>test -f .planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-03-PREFLIGHT.md && grep -cE "## V[123]" .planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-03-PREFLIGHT.md</automated>
  </verify>
  <acceptance_criteria>
    - `test -f .planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-03-PREFLIGHT.md`
    - `grep -c "## V1" .planning/phases/.../05a-03-PREFLIGHT.md` == 1
    - `grep -c "## V2" .planning/phases/.../05a-03-PREFLIGHT.md` == 1
    - `grep -c "## V3" .planning/phases/.../05a-03-PREFLIGHT.md` == 1
  </acceptance_criteria>
  <done>SC-5 forward-pad + dedup-test-pad bevestigd vóór Tasks 1-3. Geen skip-clauses meer mogelijk in Task 3.</done>
</task>

<task type="auto">
  <name>Task 1: config/mollie.php + AppServiceProvider-binding voor idempotency-generator</name>
  <files>
    config/mollie.php,
    app/Providers/AppServiceProvider.php
  </files>
  <behavior>
    De SDK leest `config('mollie.idempotency.generator')` om te weten welke generator-class te instantiëren. Default in Phase 2: `UuidV7IdempotencyKeyGenerator`. Hub-app moet deze config publishen of zelf neerzetten.

    **Stap 1 — Maak `config/mollie.php`** met:
    ```php
    <?php

    return [
        // SDK gebruikt deze generator wanneer geen consumer-Idempotency-Key is gezet (D-06).
        'idempotency' => [
            'generator' => \Emeq\MollieApi\Idempotency\UuidV7IdempotencyKeyGenerator::class,
        ],

        // Facade-alias om collision met laravel-mollie te voorkomen (Phase 6 Cashier).
        'facade_alias' => 'EmeqMollie',

        // Production-guard tegen test_-prefix in production env (later in v0.3).
        'enforce_environment' => env('MOLLIE_ENFORCE_ENVIRONMENT', false),
    ];
    ```

    **Stap 2 — AppServiceProvider** behoeft GEEN nieuwe binding voor de generator zelf — de SDK leest het uit config. Maar voor **consumer-Idempotency-Key forward** (zie Plan-controller) bouwen we per-request een one-shot generator. Helper-class als support:

    Voeg een **kleine support-class** `app/Support/Mollie/ConsumerIdempotencyKeyGenerator.php` toe die `Mollie\Api\Contracts\IdempotencyKeyGeneratorContract` implementeert:

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Support\Mollie;

    use Mollie\Api\Contracts\IdempotencyKeyGeneratorContract;

    /**
     * One-shot generator die een vaste consumer-uitgegeven idempotency-key
     * returnt. Gebruikt door PaymentsController wanneer de Consumer een
     * Idempotency-Key-header meestuurt. Anders gebruikt de SDK de default
     * UuidV7IdempotencyKeyGenerator (D-06).
     */
    final class ConsumerIdempotencyKeyGenerator implements IdempotencyKeyGeneratorContract
    {
        public function __construct(private readonly string $key) {}

        public function generate(): string
        {
            return $this->key;
        }
    }
    ```

    Verifieer bij implement de exacte methode-naam op `IdempotencyKeyGeneratorContract` (waarschijnlijk `generate(): string` — anders alias). Geen wijziging aan AppServiceProvider nodig — de generator wordt per-request in de controller geïnjecteerd via een nieuwe MollieApiClient.
  </behavior>
  <read_first>
    - packages/mollie-api/src/Idempotency/UuidV7IdempotencyKeyGenerator.php (verifieer method-signature)
    - vendor/mollie/mollie-api-php/src/Contracts/IdempotencyKeyGeneratorContract.php (verifieer method-naam)
    - vendor/mollie/mollie-api-php/src/MollieApiClient.php (regels 156-210 — constructor accepteert generator als 4e arg)
    - packages/mollie-api/src/Mollie.php (zie applyIdempotencyGenerator-methode — hoe SDK de config-generator instantieert)
    - app/Providers/AppServiceProvider.php (huidige register() — geen wijziging hier nodig)
    - .planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-CONTEXT.md sectie `<decisions>` D-06
  </read_first>
  <action>
    **Stap 1 — Maak `config/mollie.php`** (zie behavior).

    **Stap 2 — Maak `app/Support/Mollie/ConsumerIdempotencyKeyGenerator.php`:**

    Verifieer eerst de exacte method-naam in `vendor/mollie/mollie-api-php/src/Contracts/IdempotencyKeyGeneratorContract.php`. Pas implementatie aan als de method `generateKey()` of `__invoke()` heet.

    **Stap 3 — Smoke + cache:**
    ```bash
    vendor/bin/pint --dirty --format agent
    php artisan config:clear
    php -r "var_dump(config('mollie.idempotency.generator'));" 2>/dev/null || true
    php artisan test --compact   # geen regressies
    ```

    Geen TDD nodig — pure config + helper-class.
  </action>
  <verify>
    <automated>test -f config/mollie.php && test -f app/Support/Mollie/ConsumerIdempotencyKeyGenerator.php && php -l config/mollie.php app/Support/Mollie/ConsumerIdempotencyKeyGenerator.php</automated>
  </verify>
  <acceptance_criteria>
    - `test -f config/mollie.php`
    - `test -f app/Support/Mollie/ConsumerIdempotencyKeyGenerator.php`
    - `grep -c "UuidV7IdempotencyKeyGenerator" config/mollie.php` == 1
    - `grep -c "EmeqMollie" config/mollie.php` == 1
    - `grep -c "implements IdempotencyKeyGeneratorContract" app/Support/Mollie/ConsumerIdempotencyKeyGenerator.php` == 1
    - `grep -c "private readonly string \\\$key" app/Support/Mollie/ConsumerIdempotencyKeyGenerator.php` == 1
    - `php artisan test --compact` exit 0
  </acceptance_criteria>
  <done>Config-publish + one-shot generator-helper. SDK leest default-generator uit config; controller kan per-request override via ConsumerIdempotencyKeyGenerator.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: CreatePaymentRequest + UpdatePaymentRequest + PaymentsController + 3 routes</name>
  <files>
    app/Http/Requests/Api/V1/Mollie/CreatePaymentRequest.php,
    app/Http/Requests/Api/V1/Mollie/UpdatePaymentRequest.php,
    app/Http/Controllers/Api/V1/Mollie/PaymentsController.php,
    routes/api.php
  </files>
  <behavior>
    **CreatePaymentRequest** — minimale Form Request met regels uit `.docs/partners/mollie/payments-api.md`:

    ```php
    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'array'],
            'amount.currency' => ['required', 'string', 'size:3'],         // ISO-4217
            'amount.value' => ['required', 'string', 'regex:/^\d+\.\d{2,}$/'], // Mollie verwacht decimal-string
            'redirectUrl' => ['nullable', 'url'],
            'cancelUrl' => ['nullable', 'url'],
            'webhookUrl' => ['nullable', 'url'],
            'method' => ['nullable'],                                       // string OR string[]
            'metadata' => ['nullable'],
            'sequenceType' => ['nullable', 'string', 'in:oneoff,first,recurring'],
            'customerId' => ['nullable', 'string'],
            'mandateId' => ['nullable', 'string'],
            'profileId' => ['nullable', 'string'],
            'locale' => ['nullable', 'string'],
            'testmode' => ['nullable', 'boolean'],
        ];
    }
    ```

    `authorize(): bool { return true; }` — auth gebeurt op middleware-niveau.

    **UpdatePaymentRequest** — subset (Mollie laat alleen metadata/redirect/webhook/description updaten via PATCH; verifieer in payments-api.md).

    **PaymentsController** — extends `AbstractMolliePassThroughController`. 3 acties:

    ```php
    use App\Http\Requests\Api\V1\Mollie\CreatePaymentRequest;
    use App\Models\Connection;
    use App\Support\Mollie\ConsumerIdempotencyKeyGenerator;
    use Emeq\MollieApi\Facades\Mollie;
    use Illuminate\Http\Request;
    use Mollie\Api\MollieApiClient;
    use Symfony\Component\HttpFoundation\Response;

    class PaymentsController extends AbstractMolliePassThroughController
    {
        public function store(CreatePaymentRequest $request): Response
        {
            return $this->handle($request, '/v2/payments', function (Request $request) {
                $payload = $request->validated();

                // WebhookUrl-injectie (D-08): vul automatisch in als Consumer 'm leeg laat
                if (empty($payload['webhookUrl'])) {
                    /** @var Connection $connection */
                    $connection = $request->attributes->get('mollie_connection');
                    $payload['webhookUrl'] = url("/webhooks/mollie/{$connection->id}");
                }

                $client = $this->buildClient($request);
                $payment = $client->payments->create($payload);

                return ['status' => 201, 'body' => $payment->toArray()];
            });
        }

        public function show(Request $request, string $id): Response
        {
            return $this->handle($request, '/v2/payments/{id}', function (Request $request) use ($id) {
                return Mollie::client()->payments->get($id)->toArray();
            });
        }

        public function destroy(Request $request, string $id): Response
        {
            return $this->handle($request, '/v2/payments/{id}', function (Request $request) use ($id) {
                return Mollie::client()->payments->cancel($id)->toArray();
            });
        }

        /**
         * Bouw een MollieApiClient. Als Consumer een Idempotency-Key-header
         * meestuurt: gebruik ConsumerIdempotencyKeyGenerator zodat Mollie
         * dezelfde key krijgt en duplicates dedupliceert.
         */
        private function buildClient(Request $request): MollieApiClient
        {
            $client = Mollie::client();

            $consumerKey = $request->header('Idempotency-Key');
            if (is_string($consumerKey) && $consumerKey !== '') {
                // Verifieer in vendor of dit veilig herinjecteerd kan worden
                // op een al-gebouwde client. Indien niet: gebruik reflection
                // of bouw een verse client via Mollie::class->client() met
                // custom-bound generator. Executor mag pad kiezen.
                $generator = new ConsumerIdempotencyKeyGenerator($consumerKey);

                // Mollie's MollieApiClient::__construct accepteert generator als 4e arg.
                // Voor een al-gebouwde client zit er typisch GEEN public setter op;
                // pad: bouw een nieuwe client via reflection of via Mollie::class
                // overschrijven van de generator-config voor deze ene call.
                //
                // PAD per Task 0 V1-uitkomst (B3: SC-5 hard gate, geen skip-pad).
                // Bouw nieuwe MollieApiClient met generator-arg per pre-flight V1-bevinding.
                // Constructor-arg-volgorde MOET overeenkomen met PREFLIGHT.md V1.

                $creds = app(\Emeq\MollieApi\Mollie::class)->credentials();
                $fresh = new MollieApiClient(
                    httpAdapter: null,
                    httpAdapterPicker: null,
                    idempotencyKeyGenerator: $generator,
                );
                match (true) {
                    $creds instanceof \Emeq\MollieApi\Data\MollieApiKeyCredentials => $fresh->setApiKey($creds->apiKey),
                    $creds instanceof \Emeq\MollieApi\Data\MollieOAuthCredentials => $fresh->setAccessToken($creds->accessToken),
                    default => null,
                };

                return $fresh;
            }

            return $client;
        }
    }
    ```

    **NB voor executor (B3 — SC-5 hard gate):** Task 0 (pre-flight) bevestigt het idempotency-forward-pad VÓÓR Task 2 start. Pas `buildClient()` aan conform PREFLIGHT.md V1-uitkomst. `markTestSkipped` is GEEN optie meer — als constructor-injection niet werkt is de pre-flight al geëscaleerd naar user (B3 optie b: phase-split 05a-03b). Verwacht: groene pre-flight → constructor-injection per V1-snippet → SC-5 test bewijst end-to-end deduplication.

    **3 routes in `routes/api.php`** — voeg toe binnen de bestaande `Route::middleware('auth:sanctum')->group(...)` (zie regel 19-37). Plaats NA de Snelstart-passthrough-route en VÓÓR de sluitende `});`:

    ```php
    use App\Http\Controllers\Api\V1\Mollie\PaymentsController;

    // (binnen het bestaande auth:sanctum group)
    Route::prefix('mollie')->middleware('resolve.mollie.account')->group(function (): void {
        Route::post('/payments', [PaymentsController::class, 'store'])->name('api.mollie.payments.store');
        Route::get('/payments/{id}', [PaymentsController::class, 'show'])->name('api.mollie.payments.show');
        Route::delete('/payments/{id}', [PaymentsController::class, 'destroy'])->name('api.mollie.payments.destroy');
    });
    ```
  </behavior>
  <read_first>
    - app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php (Plan 05a-01 — handle-method signature)
    - app/Http/Middleware/ResolveMollieAccount.php (Plan 05a-01)
    - app/Http/Requests/Api/V1/StoreConnectionRequest.php (template voor Form Request shape — `authorize()` + `rules()`)
    - app/Models/Connection.php (relations)
    - app/Sanctum/TokenAbilities.php (MOLLIE_READ + MOLLIE_WRITE)
    - packages/mollie-api/src/Mollie.php (regels 50-95 — `client()` + `credentials()` + `applyIdempotencyGenerator()`)
    - packages/mollie-api/src/Data/MollieApiKeyCredentials.php
    - packages/mollie-api/src/Data/MollieOAuthCredentials.php
    - vendor/mollie/mollie-api-php/src/MollieApiClient.php (regels 140-220 — constructor-args + setApiKey/setAccessToken)
    - vendor/mollie/mollie-api-php/src/Endpoints/PaymentEndpoint.php (verifieer create/get/cancel signatures)
    - .docs/partners/mollie/payments-api.md (payload-shape + status-codes)
    - .docs/partners/mollie/api-idempotency.md (Idempotency-Key gedrag)
    - routes/api.php (huidige route-structuur)
    - .planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-CONTEXT.md sectie `<decisions>` D-01, D-02, D-04, D-06
    - .planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-PATTERNS.md regels 207-264 (PaymentsController) + 666-720 (Form Requests)
    - .planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-PATTERNS.md regels 829-879 (routes/api.php diff)
  </read_first>
  <action>
    **Stap 1 — Maak directories:**
    ```bash
    mkdir -p app/Http/Requests/Api/V1/Mollie app/Http/Controllers/Api/V1/Mollie
    ```

    **Stap 2 — Maak Form Requests:**
    ```bash
    php artisan make:request Api/V1/Mollie/CreatePaymentRequest --no-interaction
    php artisan make:request Api/V1/Mollie/UpdatePaymentRequest --no-interaction
    ```

    Vul `rules()` per behavior-sectie. `authorize()`: `return true;`.

    **Stap 3 — Maak PaymentsController:**

    ```bash
    php artisan make:controller Api/V1/Mollie/PaymentsController --no-interaction
    ```

    Implementeer per behavior-sectie. Belangrijke verifie-stap: lees `vendor/mollie/mollie-api-php/src/MollieApiClient.php` rond regel 156 om de exacte constructor-arg-volgorde te bepalen. Pas `buildClient()` aan zodat `new MollieApiClient(...)` syntactisch klopt.

    **Stap 4 — Update `routes/api.php`** met de 3 routes. Behoud bestaande Snelstart-passthrough + OAuth-routes ongewijzigd. Voeg de Mollie-prefix-block toe ÉÉN regel vóór de sluitende `});` van de auth:sanctum-group.

    **Stap 5 — Smoke:**
    ```bash
    vendor/bin/pint --dirty --format agent
    php artisan route:list --path=v1/mollie
    php -l app/Http/Controllers/Api/V1/Mollie/PaymentsController.php app/Http/Requests/Api/V1/Mollie/CreatePaymentRequest.php
    ```

    Verwacht: 3 nieuwe routes onder `/v1/mollie/payments[/{id}]` met middleware-stack `auth:sanctum, resolve.mollie.account`.
  </action>
  <verify>
    <automated>php artisan route:list --path=v1/mollie 2>&1 | grep -c "mollie/payments" && php -l app/Http/Controllers/Api/V1/Mollie/PaymentsController.php</automated>
  </verify>
  <acceptance_criteria>
    - `test -f app/Http/Requests/Api/V1/Mollie/CreatePaymentRequest.php`
    - `test -f app/Http/Requests/Api/V1/Mollie/UpdatePaymentRequest.php`
    - `test -f app/Http/Controllers/Api/V1/Mollie/PaymentsController.php`
    - `grep -c "class PaymentsController extends AbstractMolliePassThroughController" app/Http/Controllers/Api/V1/Mollie/PaymentsController.php` == 1
    - `grep -c "payments->create" app/Http/Controllers/Api/V1/Mollie/PaymentsController.php` == 1
    - `grep -c "payments->get" app/Http/Controllers/Api/V1/Mollie/PaymentsController.php` == 1
    - `grep -c "payments->cancel" app/Http/Controllers/Api/V1/Mollie/PaymentsController.php` == 1
    - `grep -c "/v2/payments" app/Http/Controllers/Api/V1/Mollie/PaymentsController.php` >= 3
    - `grep -c "webhookUrl" app/Http/Controllers/Api/V1/Mollie/PaymentsController.php` >= 1
    - `grep -c "Idempotency-Key" app/Http/Controllers/Api/V1/Mollie/PaymentsController.php` >= 1
    - `grep -cE "(amount.currency|amount.value|description)" app/Http/Requests/Api/V1/Mollie/CreatePaymentRequest.php` >= 3
    - `grep -c "Route::prefix('mollie')" routes/api.php` == 1
    - `grep -c "resolve.mollie.account" routes/api.php` == 1
    - `php artisan route:list --name=api.mollie.payments` toont 3 named-routes (store, show, destroy)
    - `php -l` exit 0 voor alle 3 nieuwe files
  </acceptance_criteria>
  <done>Controller + form requests + 3 routes geregistreerd. Webhook-URL-auto-injectie werkt. Idempotency-Key-forward-pad zit in code (executor verifieert de exacte vendor-constructor-shape).</done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: 4 feature-tests — Payments + IdempotencyForward + ErrorMapping + AuditNoSecrets</name>
  <files>
    tests/Feature/Api/V1/Mollie/PaymentsTest.php,
    tests/Feature/Api/V1/Mollie/MollieIdempotencyForwardTest.php,
    tests/Feature/Api/V1/Mollie/MolliePassThroughErrorMappingTest.php,
    tests/Feature/Api/V1/Mollie/MolliePassThroughAuditTest.php
  </files>
  <behavior>
    **`PaymentsTest`** (~5 cases) — bewijst MOLL-03 SC-1 + happy paths:

    1. `test_post_payments_proxies_through_sdk_and_returns_201_with_mollie_payload` — Mock Mollie's `payments->create` om een Payment-resource met `id`, `status='open'`, `_links.checkout.href` te returneren; assert response 201 + `_links.checkout.href` present + audit-rij.
    2. `test_post_payments_auto_injects_webhook_url_when_consumer_omits_it` — payload zonder `webhookUrl`; mock-callable capture't payload dat naar Mollie ging; assert `$captured['webhookUrl'] === url("/webhooks/mollie/{$connection->id}")`.
    3. `test_post_payments_respects_consumer_provided_webhook_url` — Consumer stuurt `webhookUrl: 'https://consumer.test/cb'`; mock-capture't; assert `$captured['webhookUrl'] === 'https://consumer.test/cb'`.
    4. `test_get_payments_id_proxies_through_sdk_with_get` — Mock `payments->get('tr_xxx')` om resource te returnen; GET `/v1/mollie/payments/tr_xxx`; assert 200 + body matched.
    5. `test_delete_payments_id_calls_cancel` — Mock `payments->cancel('tr_xxx')`; DELETE; assert 200.

    **`MollieIdempotencyForwardTest`** (~3 cases) — bewijst MOLL-03 SC-5:

    1. `test_two_post_with_same_idempotency_key_returns_same_mollie_payment_id` (W7 — bewijst SC-5 verbatim): configureer `MollieApiClient::fake()`-mock (per PREFLIGHT.md V3-snippet) zodat 2 calls met identieke `Idempotency-Key: idem-test-001` dezelfde Payment-`id` (bv. `tr_dedup_xyz`) returnen — emuleert Mollie's server-side deduplication. Doe 2× POST `/v1/mollie/payments` met dezelfde `Idempotency-Key`-header. Assert: response 1.json.id === response 2.json.id === `tr_dedup_xyz` (bewijst 'één Mollie-payment-ID' uit SC-5). Plus: assert mock-headers/payloads van beide calls hadden EXACT dezelfde Idempotency-Key (geen Hub-side rewrite).
    2. `test_post_without_idempotency_key_uses_uuid7_default_generator` — mock-capture't generator-output; Consumer stuurt geen header; assert Mollie's create-call kreeg een UUID-v7 (regex: `^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$`).
    3. `test_consumer_idempotency_key_is_forwarded_verbatim_to_mollie` (W6 — runtime-bewijs van consumer-key-forward, vervangt grep-only verify): Consumer stuurt `Idempotency-Key: my-custom-key-xyz`; mock-capture't headers EN payload van de Mollie-call; assert Mollie kreeg EXACT `my-custom-key-xyz` als idempotency-key (geen UUID-v7 fallback). Deze case is de runtime-validator van Task 2's buildClient()-pad — als deze test groen draait, is consumer-Idempotency-Key forward aantoonbaar werkend en is geen extra grep-acceptance nodig op `'Idempotency-Key'` literal.

    **B3 STRICT — geen skip-pad:** SC-5 is een harde ROADMAP-gate (D-06). Cases 1 + 3 MOETEN echt draaien. Als implementatie faalt: blokker tegen plan-completion, NIET `markTestSkipped`. Bij vendor-discovery blokker is Task 0 (pre-flight) al naar user geëscaleerd. Test 1 emuleert Mollie's server-side dedup via fake-mock per PREFLIGHT V3.

    **`MolliePassThroughErrorMappingTest`** (~6 cases) — bewijst D-13 mapping-tabel via een echte HTTP-call (niet pure unit zoals Task 1 van Plan 05a-01):

    | Mollie throws | Hub status | error-key | audit upstream_error |
    |---|---|---|---|
    | AuthenticationException | 502 | `mollie_auth_failed` | `mollie_auth` |
    | NotFoundException | 404 | `not_found` | NULL |
    | ValidationException | 422 | `validation_failed` | NULL |
    | RateLimitException | 429 | `rate_limited` | NULL |
    | ServerException | 502 | `mollie_unavailable` | `mollie_5xx` |
    | onverwachte \RuntimeException | 502 | `mollie_error` | `mollie_unknown` |

    Per case: mock `payments->create` om de specifieke exception te gooien; POST een payload; assert response-status + `error`-key + audit `upstream_error`.

    **`MolliePassThroughAuditTest`** (~4 cases) — bewijst D-05 fixes + geen secrets in audit:

    1. `test_audit_row_after_post_has_provider_mollie_and_correct_path_template` — POST /v1/mollie/payments; assert audit-row `provider='mollie'`, `path='/v2/payments'`, `method='POST'`, `query_keys=null`.
    2. `test_audit_row_for_get_with_query_string_stores_query_keys_only_not_values` — GET /v1/mollie/payments/tr_xxx?include=details,refunds; assert `path='/v2/payments/{id}'`, `query_keys='include'`.
    3. `test_audit_row_request_fingerprint_is_null_for_empty_post_body` — POST /v1/mollie/payments met body `{}`; mock returnt 422 (validation); assert audit-row `request_fingerprint IS NULL`.
    4. `test_audit_row_does_not_contain_access_token_or_credentials` — Setup met `Connection::factory()->forMollie()->create(['access_token' => 'access_test_RAWTOKEN_DO_NOT_LEAK'])`; doe een succesvolle POST; haal alle string-kolommen van de audit-row op; assert geen kolom bevat `'access_test_RAWTOKEN_DO_NOT_LEAK'`.

    **SDK mock-strategie** — bind een mock op `Emeq\MollieApi\Mollie::class`:

    ```php
    protected function fakeMollie(callable $configurator): array
    {
        $captured = ['create' => [], 'get' => [], 'cancel' => [], 'idempotency_keys' => []];

        $paymentEndpoint = new class($configurator, $captured) {
            public function __construct(private $configurator, private array &$captured) {}
            public function create(array $data, array $filters = []) {
                $this->captured['create'][] = $data;
                return ($this->configurator)('create', $data, $filters);
            }
            public function get(string $id, array $params = []) {
                $this->captured['get'][] = $id;
                return ($this->configurator)('get', $id, $params);
            }
            public function cancel(string $id, array $params = []) {
                $this->captured['cancel'][] = $id;
                return ($this->configurator)('cancel', $id, $params);
            }
        };

        // Variant: capture idempotency-key door MollieApiClient stub te bouwen
        // die ook setIdempotencyKey() respect — executor mag pad kiezen.
        // ...
    }
    ```

    Dit is niet-triviaal — executor mag een eenvoudigere mock (bv. via Mockery) gebruiken zolang `Mollie::client()->payments->...` controllable is per case en de captured-payload assertable is.

    **Setup-helper (mag in een trait `Tests\Concerns\WithMollieFakes`):**

    ```php
    /** @return array{0:Consumer,1:string,2:Account,3:Connection} */
    protected function setupMollieConsumer(array $abilities): array
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
        $connection = Connection::factory()->forMollie()->active()->for($account)->create();
        $token = $consumer->createToken('test', $abilities)->plainTextToken;
        return [$consumer, $token, $account, $connection];
    }

    protected function callMollie(string $token, string $method, string $uri, array $payload = [], array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(array_merge(
            ['Authorization' => "Bearer {$token}", 'X-Account-Id' => 'school-A', 'Accept' => 'application/json'],
            $headers,
        ))->json($method, $uri, $payload);
    }
    ```

    Run alle 4 test-files:
    ```bash
    vendor/bin/pint --dirty --format agent
    php artisan test --compact --filter='PaymentsTest|MollieIdempotencyForwardTest|MolliePassThroughErrorMappingTest|MolliePassThroughAuditTest'
    php artisan test --compact   # full suite
    ```
  </behavior>
  <read_first>
    - app/Http/Controllers/Api/V1/Mollie/PaymentsController.php (Task 2 output)
    - app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php (Plan 05a-01)
    - tests/Feature/Api/V1/Mollie/MolliePassThroughResolutionTest.php (Plan 05a-01 — pattern voor setup-helper + Consumer/Account/Connection-shape)
    - tests/Concerns/BindsMollieConnectionContext.php (Plan 05a-01)
    - app/Models/PassThroughCall.php (fillable kolommen die getest worden)
    - packages/mollie-api/src/Mollie.php (Mollie::class binding + client()-shape)
    - packages/mollie-api/src/Exceptions/* (alle 6 exception-types die in ErrorMappingTest voorkomen)
    - vendor/mollie/mollie-api-php/src/Resources/Payment.php (verifieer toArray() output-shape voor mock-returns)
    - .planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-CONTEXT.md sectie `<decisions>` D-04, D-05, D-06, D-13, D-14
    - .planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-PATTERNS.md regels 994-1072 (test-skeletons + ErrorMapping-tabel + Audit-strategie)
    - .docs/partners/mollie/api-idempotency.md (idempotency-semantics)
  </read_first>
  <action>
    **Stap 1 — Maak directory:**
    ```bash
    mkdir -p tests/Feature/Api/V1/Mollie
    ```

    **Stap 2 — Optioneel: maak `tests/Concerns/WithMollieFakes.php`-trait** met de helpers uit behavior-sectie. Niet verplicht — mag inline in elke test.

    **Stap 3 — Genereer test-files:**
    ```bash
    php artisan make:test --phpunit Api/V1/Mollie/PaymentsTest --no-interaction
    php artisan make:test --phpunit Api/V1/Mollie/MollieIdempotencyForwardTest --no-interaction
    php artisan make:test --phpunit Api/V1/Mollie/MolliePassThroughErrorMappingTest --no-interaction
    php artisan make:test --phpunit Api/V1/Mollie/MolliePassThroughAuditTest --no-interaction
    ```

    **Stap 4 — Schrijf alle ~18 test-cases** (5+3+6+4) per de behavior-sectie. Cases 1 + 3 in `MollieIdempotencyForwardTest` MOETEN echt draaien (B3: SC-5 hard gate, geen `markTestSkipped`-pad). Test 1 emuleert Mollie's server-side deduplication via `MollieApiClient::fake()`-mock per PREFLIGHT.md V3 — assert beide responses dezelfde `id`-waarde hebben. Test 3 capture't en assert't dat de Idempotency-Key verbatim van Consumer-header naar Mollie-call is doorgegeven.

    **Stap 5 — Run + verifieer:**
    ```bash
    vendor/bin/pint --dirty --format agent
    php artisan test --compact --filter='PaymentsTest|MollieIdempotencyForwardTest|MolliePassThroughErrorMappingTest|MolliePassThroughAuditTest'
    php artisan test --compact
    ```
  </action>
  <verify>
    <automated>php artisan test --compact --filter='PaymentsTest|MollieIdempotencyForwardTest|MolliePassThroughErrorMappingTest|MolliePassThroughAuditTest'</automated>
  </verify>
  <acceptance_criteria>
    - `grep -cE "public function test_" tests/Feature/Api/V1/Mollie/PaymentsTest.php` >= 5
    - `grep -cE "public function test_" tests/Feature/Api/V1/Mollie/MollieIdempotencyForwardTest.php` >= 3
    - `grep -cE "public function test_" tests/Feature/Api/V1/Mollie/MolliePassThroughErrorMappingTest.php` >= 6
    - `grep -cE "public function test_" tests/Feature/Api/V1/Mollie/MolliePassThroughAuditTest.php` >= 4
    - **Totaal nieuw: >= 18 tests**
    - `grep -cE "(_links|checkout|status.*open)" tests/Feature/Api/V1/Mollie/PaymentsTest.php` >= 1
    - `grep -c "webhookUrl" tests/Feature/Api/V1/Mollie/PaymentsTest.php` >= 2 (auto-inject + respect-consumer)
    - `grep -cE "(AuthenticationException|NotFoundException|ValidationException|RateLimitException|ServerException)" tests/Feature/Api/V1/Mollie/MolliePassThroughErrorMappingTest.php` >= 5
    - `grep -c "access_test_RAWTOKEN_DO_NOT_LEAK" tests/Feature/Api/V1/Mollie/MolliePassThroughAuditTest.php` >= 1
    - `grep -c "request_fingerprint" tests/Feature/Api/V1/Mollie/MolliePassThroughAuditTest.php` >= 1
    - `php artisan test --compact --filter='PaymentsTest|MollieIdempotencyForwardTest|MolliePassThroughErrorMappingTest|MolliePassThroughAuditTest'` exit 0
    - `php artisan test --compact` exit 0 (geen regressies in eerdere plans)
  </acceptance_criteria>
  <done>18+ tests groen die MOLL-03 Payments + SC-1 + SC-5 + Mollie-error-mapping + audit-no-secrets bewijzen.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Consumer-payload → Mollie-API (POST /v2/payments) | Form Request validatie + WebhookUrl-injectie zorgt dat Hub de fan-out-URL controlt |
| Idempotency-Key forward → Mollie-deduplication | Per-Connection scope op Mollie-side (Mollie indexeert key per OAuth-token) |
| Mollie-error → Hub-response | 401/403 cloaked naar 502, geen auth-state-disclosure |
| Audit-rij → DB | Geen access-token, geen request-body content |

## STRIDE Threat Register

| Threat ID | Category | Component | Severity | Disposition | Mitigation Plan |
|-----------|----------|-----------|----------|-------------|-----------------|
| T-05a-14 | Tampering | Consumer zet `webhookUrl` naar attacker-controlled URL → fan-out skipt Hub | medium | accept | Consumer mag eigen `webhookUrl` gebruiken (compatibility met direct-Mollie-flow). Hub's auto-inject pakt het pas als 'ie LEEG is. Backlog: optioneel beleid om `webhookUrl` ALTIJD te overrulen naar Hub-URL (config-flag). |
| T-05a-15 | Information disclosure | Idempotency-Key bleed tussen Connections (Consumer A's key herbruikt voor Connection B) | low | accept | Mollie scopet idempotency-keys per credential-set (per access_token). Een key uit Connection A's context bevuilt Connection B niet — Mollie ziet ze als verschillende clients. CONTEXT canonical-refs §"Open verifie-punten" #1 — bij twijfel verifie via test. |
| T-05a-16 | Spoofing | Consumer faked Idempotency-Key om dubbele Mollie-call te triggeren | low | accept | Mollie's idempotency dedupliceert binnen het tijdvenster (24h per Mollie-docs). Geen server-side bedreiging. |
| T-05a-17 | Information disclosure | ValidationException-message lekt interne Mollie-error-detail naar Consumer | low | accept | Mollie's eigen error-messages zijn al public-facing (Consumer kan ze ook direct van Mollie krijgen). Geen Hub-toegevoegde informatie-disclosure. |
| T-05a-18 | DoS | Consumer-spam met geldige PAT → gerichte uitputting van Mollie-rate-limits per Connection | low | accept | throttle:api (60/min per Consumer) op `'api'`-middleware; Mollie's eigen rate-limit (per Mollie-docs) absorbeert overflow met 429 → Hub mapt naar 429+Retry-After. Volgt Mollie's defaults. |
</threat_model>

<verification>
- 3 nieuwe Payments-routes geregistreerd
- 18+ feature-tests groen
- Volledige `php artisan test --compact` exit 0
- pint clean
- Geen wijziging onder packages/mollie-api/**
</verification>

<success_criteria>
- MOLL-03 (Payments-deel) geleverd: create + get + cancel via pass-through
- MOLL-03 SC-1: bewijs van Mollie-checkout-URL (via mock — real-Mollie-test-mode-roundtrip kan in Plan 05a-05 acceptance)
- MOLL-03 SC-5: Idempotency-Key forward bewezen — 2× POST met dezelfde key retourneert dezelfde Payment-id via `MollieApiClient::fake()`-emulated dedup (B3, ROADMAP hard gate)
- D-01, D-02, D-04, D-06, D-13, D-14 ingelost in code
</success_criteria>

<output>
Na completion: `.planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-03-SUMMARY.md` per template, met expliciete vermelding van:
- Bevestiging dat consumer-Idempotency-Key forward werkt + 2 IdempotencyForwardTest-cases (1 + 3) groen draaien (SC-5 hard gate per B3)
- Mock-strategie die uiteindelijk gebruikt is voor `Mollie::client()->payments->...` in tests
- Test-counts per file
- Eventuele Form Request-veld-discrepanties tegen `.docs/partners/mollie/payments-api.md` (executor mag wijzigen, documenteer afwijking)
- Trigger `docs-sync` skill als follow-up — nieuwe routes (3 mollie-payments-routes), nieuwe `config/mollie.php`, nieuwe `app/Support/Mollie/ConsumerIdempotencyKeyGenerator.php`. Update STACK.md / ARCHITECTURE.md / CONVENTIONS.md indien nodig.
</output>
</content>
</invoke>