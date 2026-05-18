---
phase: 05a-mollie-sdk-resources-webhooks-pass-through-api
plan: 05
type: execute
wave: 4
depends_on: [05a-01, 05a-02, 05a-03, 05a-04]
files_modified:
  - app/Http/Controllers/Api/V1/Mollie/SubscriptionsController.php
  - app/Http/Controllers/Api/V1/Mollie/PaymentLinksController.php
  - app/Http/Requests/Api/V1/Mollie/CreateSubscriptionRequest.php
  - app/Http/Requests/Api/V1/Mollie/CreatePaymentLinkRequest.php
  - routes/api.php
  - tests/Feature/Api/V1/Mollie/SubscriptionsTest.php
  - tests/Feature/Api/V1/Mollie/PaymentLinksTest.php
  - tests/Feature/Documentation/ScrambleRouteDiscoveryTest.php
  - tests/Feature/Api/SanctumAbilityTest.php
autonomous: false
requirements: [MOLL-03, HUB-03, MOLL-04]
tags:
  - laravel
  - mollie
  - subscriptions
  - payment-links
  - acceptance
  - scramble
  - phpunit

must_haves:
  decisions: [D-01, D-02, D-04, D-10, D-13, D-14, D-15]
  truths:
    - "MOLL-03 Subscriptions (create+get+cancel+list-per-customer) via pass-through nested onder customers"
    - "MOLL-03 PaymentLinks (create+get+list) via pass-through op /v1/mollie/payment-links[/{id}]"
    - "Alle 7 Mollie-resources zijn callable via /v1/mollie/* en worden door Scramble in /docs/api opgepakt (MOLL-03 SC-2 + HUB-03)"
    - "BLOCKING phase-acceptance run: php artisan migrate (alle 5a-migrations) + php artisan route:list toont >=13 mollie-routes + php artisan test --compact full-green + Scramble OpenAPI bevat alle resource-paths"
    - "SanctumAbility-test voor mollie-write-required wordt completed: Mollie-equivalent van Phase 3's placeholder; Snelstart-versie blijft groen (Plan 05b-05 geland)"
  artifacts:
    - path: "app/Http/Controllers/Api/V1/Mollie/SubscriptionsController.php"
      provides: "index/store/show/destroy nested onder customers"
      contains: "class SubscriptionsController extends AbstractMolliePassThroughController"
    - path: "app/Http/Controllers/Api/V1/Mollie/PaymentLinksController.php"
      provides: "index/store/show"
      contains: "class PaymentLinksController extends AbstractMolliePassThroughController"
  key_links:
    - from: "SubscriptionsController"
      to: "Mollie's customerSubscriptions endpoint"
      via: "Mollie::client()->customerSubscriptions"
      pattern: "customerSubscriptions->"
    - from: "PaymentLinksController"
      to: "Mollie's paymentLinks endpoint"
      via: "Mollie::client()->paymentLinks"
      pattern: "paymentLinks->"
    - from: "BLOCKING acceptance"
      to: "phase-go-decision"
      via: "8/8 acceptance-checks green"
      pattern: "ACCEPTANCE_PASSED"
---

<objective>
Laatste twee Mollie-resources (Subscriptions + PaymentLinks) + BLOCKING phase-acceptance + Scramble route-discovery + SanctumAbility-completion voor Mollie-pad.

Purpose: MOLL-03 volledig gedekt (alle 7 resources), HUB-03 Scramble-OpenAPI bewijs (SC-2), MOLL-04 webhook-routes in spec, finale phase-acceptance gate die bewijst dat 5a klaar is voor `/gsd-transition`.

Output: 2 controllers + 2 form requests + 9 routes + 2 feature-tests + Scramble-test-uitbreiding + SanctumAbility-completion + BLOCKING checkpoint.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-CONTEXT.md
@.planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-PATTERNS.md
@.planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-01-PLAN.md
@.planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-02-PLAN.md
@.planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-03-PLAN.md
@.planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-04-PLAN.md
@.docs/partners/mollie/subscriptions-api.md
@.docs/partners/mollie/payment-links-api.md
@CLAUDE.md
@app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php
@app/Http/Controllers/Api/V1/Mollie/PaymentsController.php
@app/Http/Controllers/Api/V1/Mollie/CustomersController.php
@routes/api.php
@bootstrap/app.php
@tests/Feature/Api/SanctumAbilityTest.php
@tests/Feature/Documentation/ScrambleRouteDiscoveryTest.php
@app/Providers/AppServiceProvider.php

<interfaces>
Mollie-SDK endpoints (verifieer in vendor):
```
$client->customerSubscriptions  → CustomerSubscriptionEndpoint  (createForId/pageForId/getForId/cancelForId)
$client->subscriptions          → SubscriptionEndpoint           (alleen list-all-for-customer of get-by-id?)
$client->paymentLinks           → PaymentLinkEndpoint            (page/get/create)
```

NB: voor v0.2 scope (CONTEXT.md `<domain>` regel 35) zijn Subscriptions ALTIJD nested onder een customer (`/v2/customers/{id}/subscriptions`). Standalone subscription-routes zijn out-of-scope.
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: SubscriptionsController + PaymentLinksController + 9 routes + 2 feature-tests</name>
  <files>
    app/Http/Controllers/Api/V1/Mollie/SubscriptionsController.php,
    app/Http/Controllers/Api/V1/Mollie/PaymentLinksController.php,
    app/Http/Requests/Api/V1/Mollie/CreateSubscriptionRequest.php,
    app/Http/Requests/Api/V1/Mollie/CreatePaymentLinkRequest.php,
    routes/api.php,
    tests/Feature/Api/V1/Mollie/SubscriptionsTest.php,
    tests/Feature/Api/V1/Mollie/PaymentLinksTest.php
  </files>
  <behavior>
    **SubscriptionsController** — nested onder customers, 4 acties:
    - GET /v1/mollie/customers/{id}/subscriptions (index)
    - POST /v1/mollie/customers/{id}/subscriptions (store)
    - GET /v1/mollie/customers/{id}/subscriptions/{sub_id} (show)
    - DELETE /v1/mollie/customers/{id}/subscriptions/{sub_id} (destroy = cancel)

    Skelet:

    ```php
    namespace App\Http\Controllers\Api\V1\Mollie;

    use App\Http\Requests\Api\V1\Mollie\CreateSubscriptionRequest;
    use Emeq\MollieApi\Facades\Mollie;
    use Illuminate\Http\Request;
    use Symfony\Component\HttpFoundation\Response;

    class SubscriptionsController extends AbstractMolliePassThroughController
    {
        public function index(Request $request, string $customer_id): Response
        {
            return $this->handle($request, '/v2/customers/{id}/subscriptions', function () use ($customer_id) {
                $list = Mollie::client()->customerSubscriptions->pageForId($customer_id);
                return method_exists($list, 'toArray') ? $list->toArray() : iterator_to_array($list);
            });
        }

        public function store(CreateSubscriptionRequest $request, string $customer_id): Response
        {
            return $this->handle($request, '/v2/customers/{id}/subscriptions', function (CreateSubscriptionRequest $r) use ($customer_id) {
                $sub = Mollie::client()->customerSubscriptions->createForId($customer_id, $r->validated());
                return ['status' => 201, 'body' => $sub->toArray()];
            });
        }

        public function show(Request $request, string $customer_id, string $sub_id): Response
        {
            return $this->handle($request, '/v2/customers/{id}/subscriptions/{sub_id}', fn () =>
                Mollie::client()->customerSubscriptions->getForId($customer_id, $sub_id)->toArray()
            );
        }

        public function destroy(Request $request, string $customer_id, string $sub_id): Response
        {
            return $this->handle($request, '/v2/customers/{id}/subscriptions/{sub_id}', function () use ($customer_id, $sub_id) {
                $cancelled = Mollie::client()->customerSubscriptions->cancelForId($customer_id, $sub_id);
                return $cancelled?->toArray() ?? ['status' => 204, 'body' => []];
            });
        }
    }
    ```

    **CreateSubscriptionRequest** — per `.docs/partners/mollie/subscriptions-api.md`:

    ```php
    public function rules(): array
    {
        return [
            'amount' => ['required', 'array'],
            'amount.currency' => ['required', 'string', 'size:3'],
            'amount.value' => ['required', 'string', 'regex:/^\d+\.\d{2,}$/'],
            'interval' => ['required', 'string', 'regex:/^\d+ (day|days|week|weeks|month|months)$/'],
            'description' => ['required', 'string', 'max:255'],
            'startDate' => ['nullable', 'date'],
            'method' => ['nullable'],
            'metadata' => ['nullable'],
            'mandateId' => ['nullable', 'string'],
            'webhookUrl' => ['nullable', 'url'],
            'times' => ['nullable', 'integer', 'min:1'],
        ];
    }
    ```

    **PaymentLinksController** — 3 acties (geen nesting):
    - GET /v1/mollie/payment-links (index)
    - POST /v1/mollie/payment-links (store)
    - GET /v1/mollie/payment-links/{id} (show)

    ```php
    namespace App\Http\Controllers\Api\V1\Mollie;

    use App\Http\Requests\Api\V1\Mollie\CreatePaymentLinkRequest;
    use Emeq\MollieApi\Facades\Mollie;
    use Illuminate\Http\Request;
    use Symfony\Component\HttpFoundation\Response;

    class PaymentLinksController extends AbstractMolliePassThroughController
    {
        public function index(Request $request): Response
        {
            return $this->handle($request, '/v2/payment-links', function (Request $r) {
                $list = Mollie::client()->paymentLinks->page(
                    $r->query('from'),
                    (int) $r->query('limit', 50),
                );
                return method_exists($list, 'toArray') ? $list->toArray() : iterator_to_array($list);
            });
        }

        public function store(CreatePaymentLinkRequest $request): Response
        {
            return $this->handle($request, '/v2/payment-links', function (CreatePaymentLinkRequest $r) {
                $link = Mollie::client()->paymentLinks->create($r->validated());
                return ['status' => 201, 'body' => $link->toArray()];
            });
        }

        public function show(Request $request, string $id): Response
        {
            return $this->handle($request, '/v2/payment-links/{id}', fn () =>
                Mollie::client()->paymentLinks->get($id)->toArray()
            );
        }
    }
    ```

    **CreatePaymentLinkRequest** — per `.docs/partners/mollie/payment-links-api.md`:

    ```php
    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['nullable', 'array'],
            'amount.currency' => ['required_with:amount', 'string', 'size:3'],
            'amount.value' => ['required_with:amount', 'string', 'regex:/^\d+\.\d{2,}$/'],
            'minimumAmount' => ['nullable', 'array'],
            'redirectUrl' => ['nullable', 'url'],
            'webhookUrl' => ['nullable', 'url'],
            'expiresAt' => ['nullable', 'date'],
            'allowedMethods' => ['nullable', 'array'],
            'metadata' => ['nullable'],
        ];
    }
    ```

    **9 routes in `routes/api.php`** — toevoegen aan bestaande mollie-prefix-block:

    ```php
    use App\Http\Controllers\Api\V1\Mollie\PaymentLinksController;
    use App\Http\Controllers\Api\V1\Mollie\SubscriptionsController;

    // Subscriptions nested onder customers (4 routes)
    Route::get('/customers/{id}/subscriptions', [SubscriptionsController::class, 'index'])->name('api.mollie.customers.subscriptions.index');
    Route::post('/customers/{id}/subscriptions', [SubscriptionsController::class, 'store'])->name('api.mollie.customers.subscriptions.store');
    Route::get('/customers/{id}/subscriptions/{sub_id}', [SubscriptionsController::class, 'show'])->name('api.mollie.customers.subscriptions.show');
    Route::delete('/customers/{id}/subscriptions/{sub_id}', [SubscriptionsController::class, 'destroy'])->name('api.mollie.customers.subscriptions.destroy');

    // Payment Links (3 routes)
    Route::get('/payment-links', [PaymentLinksController::class, 'index'])->name('api.mollie.payment-links.index');
    Route::post('/payment-links', [PaymentLinksController::class, 'store'])->name('api.mollie.payment-links.store');
    Route::get('/payment-links/{id}', [PaymentLinksController::class, 'show'])->name('api.mollie.payment-links.show');
    ```

    NB: alleen 7 routes hierboven — de "9" in plan-frontmatter rekent de 4 subscriptions + 3 payment-links + ev. updates aan de uiteindelijke route-totaal-tellen — kies wat correct is.

    **SubscriptionsTest** (~3 cases):
    1. `test_post_customer_subscriptions_creates_via_sdk` — mock; 201
    2. `test_get_customer_subscriptions_lists_via_sdk` — mock; 200
    3. `test_delete_customer_subscription_calls_cancel` — mock; 200 of 204

    **PaymentLinksTest** (~3 cases):
    1. `test_post_payment_links_creates_via_sdk` — 201 + checkout-URL in response
    2. `test_get_payment_links_lists_via_sdk` — 200
    3. `test_get_payment_link_by_id_returns_resource` — 200
  </behavior>
  <read_first>
    - app/Http/Controllers/Api/V1/Mollie/PaymentsController.php (Plan 05a-03 — pattern)
    - app/Http/Controllers/Api/V1/Mollie/CustomersController.php (Plan 05a-04 — page-args pattern)
    - .docs/partners/mollie/subscriptions-api.md (rules + interval-syntax + endpoint-templates)
    - .docs/partners/mollie/payment-links-api.md (rules + amount/minimumAmount mutual-exclusion)
    - vendor/mollie/mollie-api-php/src/Endpoints/CustomerSubscriptionEndpoint.php (verifieer createForId/pageForId/getForId/cancelForId)
    - vendor/mollie/mollie-api-php/src/Endpoints/PaymentLinkEndpoint.php (verifieer page/get/create)
    - vendor/mollie/mollie-api-php/src/MollieApiClient.php (verifieer property-namen $customerSubscriptions, $paymentLinks)
    - tests/Feature/Api/V1/Mollie/PaymentsTest.php (Plan 05a-03 — test-pattern)
    - tests/Feature/Api/V1/Mollie/CustomersTest.php (Plan 05a-04 — test-pattern)
    - .planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-CONTEXT.md sectie `<specifics>` regels 256-277 (route-sketch)
  </read_first>
  <action>
    **Stap 1 — Form Requests + Controllers:**
    ```bash
    php artisan make:request Api/V1/Mollie/CreateSubscriptionRequest --no-interaction
    php artisan make:request Api/V1/Mollie/CreatePaymentLinkRequest --no-interaction
    php artisan make:controller Api/V1/Mollie/SubscriptionsController --no-interaction
    php artisan make:controller Api/V1/Mollie/PaymentLinksController --no-interaction
    ```

    Implementeer per behavior. Verifieer in vendor de exacte methode-namen op `customerSubscriptions->...` en `paymentLinks->...` — pas controller-bodies aan tot ze syntactisch werken.

    **Stap 2 — Update routes/api.php** met 7 nieuwe routes binnen mollie-prefix-block.

    **Stap 3 — Tests:**
    ```bash
    php artisan make:test --phpunit Api/V1/Mollie/SubscriptionsTest --no-interaction
    php artisan make:test --phpunit Api/V1/Mollie/PaymentLinksTest --no-interaction
    ```

    Schrijf 3 + 3 cases per behavior.

    **Stap 4 — Run:**
    ```bash
    vendor/bin/pint --dirty --format agent
    php artisan route:list --name=api.mollie.customers.subscriptions
    php artisan route:list --name=api.mollie.payment-links
    php artisan test --compact --filter='SubscriptionsTest|PaymentLinksTest'
    ```
  </action>
  <verify>
    <automated>php artisan test --compact --filter='SubscriptionsTest|PaymentLinksTest'</automated>
  </verify>
  <acceptance_criteria>
    - `test -f app/Http/Controllers/Api/V1/Mollie/SubscriptionsController.php`
    - `test -f app/Http/Controllers/Api/V1/Mollie/PaymentLinksController.php`
    - `test -f app/Http/Requests/Api/V1/Mollie/CreateSubscriptionRequest.php`
    - `test -f app/Http/Requests/Api/V1/Mollie/CreatePaymentLinkRequest.php`
    - `grep -c "extends AbstractMolliePassThroughController" app/Http/Controllers/Api/V1/Mollie/SubscriptionsController.php` == 1
    - `grep -c "extends AbstractMolliePassThroughController" app/Http/Controllers/Api/V1/Mollie/PaymentLinksController.php` == 1
    - `grep -cE "customerSubscriptions->(create|page|get|cancel)" app/Http/Controllers/Api/V1/Mollie/SubscriptionsController.php` >= 4
    - `grep -cE "paymentLinks->(page|get|create)" app/Http/Controllers/Api/V1/Mollie/PaymentLinksController.php` >= 3
    - `grep -cE "interval.*day|interval.*week|interval.*month" app/Http/Requests/Api/V1/Mollie/CreateSubscriptionRequest.php` >= 1
    - `grep -cE "(api.mollie.customers.subscriptions|api.mollie.payment-links)" routes/api.php` >= 7
    - `grep -cE "public function test_" tests/Feature/Api/V1/Mollie/SubscriptionsTest.php` >= 3
    - `grep -cE "public function test_" tests/Feature/Api/V1/Mollie/PaymentLinksTest.php` >= 3
    - `php artisan test --compact --filter='SubscriptionsTest|PaymentLinksTest'` exit 0
  </acceptance_criteria>
  <done>2 controllers + 2 form-requests + 7 routes + 6+ tests groen.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Scramble route-discovery test + SanctumAbility-completion voor mollie</name>
  <files>
    tests/Feature/Documentation/ScrambleRouteDiscoveryTest.php,
    tests/Feature/Api/SanctumAbilityTest.php
  </files>
  <behavior>
    **Scramble-test uitbreiding (HUB-03 SC-2):** voeg per Mollie-resource minstens één path-discovery-assert toe aan het bestaande `tests/Feature/Documentation/ScrambleRouteDiscoveryTest.php`. Dat bestand bestaat al sinds Plan 05b-05; bewaar bestaande Snelstart-tests, voeg Mollie-tests toe.

    Voorbeeld-cases om toe te voegen (minimaal 7 — 1 per resource):

    1. `test_openapi_spec_contains_mollie_payments_routes` — paths: `/v1/mollie/payments` (post), `/v1/mollie/payments/{id}` (get + delete)
    2. `test_openapi_spec_contains_mollie_customers_routes` — paths: `/v1/mollie/customers` (get + post), `/v1/mollie/customers/{id}` (get)
    3. `test_openapi_spec_contains_mollie_payment_methods_route` — path: `/v1/mollie/payment-methods` (get)
    4. `test_openapi_spec_contains_mollie_refunds_routes` — paths: `/v1/mollie/payments/{id}/refunds` (post + get), `/v1/mollie/refunds/{id}` (get)
    5. `test_openapi_spec_contains_mollie_mandates_routes` — paths: `/v1/mollie/customers/{id}/mandates` (get), `/v1/mollie/customers/{id}/mandates/{mandate_id}` (get + delete)
    6. `test_openapi_spec_contains_mollie_subscriptions_routes` — paths: `/v1/mollie/customers/{id}/subscriptions` (get + post), `/v1/mollie/customers/{id}/subscriptions/{sub_id}` (get + delete)
    7. `test_openapi_spec_contains_mollie_payment_links_routes` — paths: `/v1/mollie/payment-links` (get + post), `/v1/mollie/payment-links/{id}` (get)

    Pattern (her uit Plan 05b-05 task 4):
    ```php
    public function test_openapi_spec_contains_mollie_payments_routes(): void
    {
        config(['scramble.access_token' => 'test-scramble-token']);
        $spec = $this->getJson('/docs/api.json?token=test-scramble-token')->json();

        $this->assertArrayHasKey('paths', $spec);
        $this->assertArrayHasKey('/v1/mollie/payments', $spec['paths']);
        $this->assertArrayHasKey('post', $spec['paths']['/v1/mollie/payments']);
        $this->assertArrayHasKey('/v1/mollie/payments/{id}', $spec['paths']);
        $this->assertArrayHasKey('get', $spec['paths']['/v1/mollie/payments/{id}']);
        $this->assertArrayHasKey('delete', $spec['paths']['/v1/mollie/payments/{id}']);
    }
    ```

    NB: Scramble-route-prefix kan `/v1/mollie/...` of `/mollie/...` zijn afhankelijk van hoe Scramble de apiPrefix-handling doet — verifieer in spec en pas asserts aan.

    **SanctumAbilityTest-Mollie-completion** — `tests/Feature/Api/SanctumAbilityTest.php` heeft sinds Plan 05b-05 een passing test voor Snelstart. Voeg een Mollie-mirror toe:

    ```php
    public function test_token_with_only_snelstart_read_ability_is_rejected_on_mollie_get(): void
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
        Connection::factory()->forMollie()->active()->for($account)->create();

        $token = $consumer->createToken('snelstart-only', [TokenAbilities::SNELSTART_READ])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school-A')
            ->getJson('/v1/mollie/payment-methods')
            ->assertStatus(403)
            ->assertJsonPath('error', 'insufficient_ability');
    }

    public function test_token_with_only_mollie_read_ability_is_rejected_on_mollie_post(): void
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
        Connection::factory()->forMollie()->active()->for($account)->create();

        $token = $consumer->createToken('mollie-read-only', [TokenAbilities::MOLLIE_READ])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school-A')
            ->postJson('/v1/mollie/payments', [
                'description' => 'test',
                'amount' => ['currency' => 'EUR', 'value' => '10.00'],
            ])
            ->assertStatus(403)
            ->assertJsonPath('error', 'insufficient_ability');
    }
    ```
  </behavior>
  <read_first>
    - tests/Feature/Documentation/ScrambleRouteDiscoveryTest.php (Plan 05b-05 — bestaande structuur, NIET breken)
    - tests/Feature/Api/SanctumAbilityTest.php (Plan 05b-05 — bestaande passing tests blijven groen)
    - app/Providers/AppServiceProvider.php (Scramble-config + viewApiDocs-Gate)
    - routes/api.php (alle Mollie-routes na Plans 05a-03/04 + nu)
    - .planning/phases/05b-snelstart-pass-through-api/05b-05-PLAN.md (template voor Scramble-test cases)
  </read_first>
  <action>
    **Stap 1 — Open `tests/Feature/Documentation/ScrambleRouteDiscoveryTest.php`** (bestaat al). Voeg 7 nieuwe `test_openapi_spec_contains_mollie_*_routes`-methods toe. Bewaar bestaande Snelstart-tests.

    **Stap 2 — Open `tests/Feature/Api/SanctumAbilityTest.php`** (bestaat al). Voeg 2 nieuwe Mollie-cases toe. Imports voor `Connection`, `Account`, `Consumer`, `TokenAbilities` zitten waarschijnlijk al door Snelstart-test — verifieer.

    **Stap 3 — Run:**
    ```bash
    vendor/bin/pint --dirty --format agent
    php artisan test --compact --filter='ScrambleRouteDiscoveryTest|SanctumAbilityTest'
    php artisan test --compact   # full suite
    ```

    Als Scramble's `apiPrefix='v1'` zorgt dat paths in spec onder `/mollie/payments` (zonder `/v1/`-prefix) staan, pas asserts aan. Run handmatig één test:
    ```bash
    php artisan tinker --execute "echo json_encode(array_keys(app()->call(\\Dedoc\\Scramble\\Generator::class)->generate(...)->getDocument()->paths));"
    ```
    of haal `/docs/api.json` direct op.
  </action>
  <verify>
    <automated>php artisan test --compact --filter='ScrambleRouteDiscoveryTest|SanctumAbilityTest'</automated>
  </verify>
  <acceptance_criteria>
    - `grep -cE "test_openapi_spec_contains_mollie" tests/Feature/Documentation/ScrambleRouteDiscoveryTest.php` >= 7
    - `grep -c "test_token_with_only_snelstart_read_ability_is_rejected_on_mollie_get" tests/Feature/Api/SanctumAbilityTest.php` >= 1
    - `grep -c "test_token_with_only_mollie_read_ability_is_rejected_on_mollie_post" tests/Feature/Api/SanctumAbilityTest.php` >= 1
    - `php artisan test --compact --filter='ScrambleRouteDiscoveryTest'` exit 0
    - `php artisan test --compact --filter='SanctumAbilityTest'` exit 0 (Snelstart-cases EN Mollie-cases groen)
  </acceptance_criteria>
  <done>Scramble bewijst dat alle 7 Mollie-resources in OpenAPI staan + ability-gating bewezen voor zowel Snelstart-only als Mollie-read-only PATs.</done>
</task>

<task type="checkpoint:human-verify" gate="blocking">
  <name>Task 3: BLOCKING phase-acceptance — 8/8 checks</name>
  <what-built>
    Alle Plans 05a-01..05a-04 + Plan 05a-05 task 1+2 zijn af. Phase 5a is technisch compleet.
    Voer een gestructureerde 8-stappen-acceptance-run uit en presenteer de uitkomst aan de user
    voor de FINALE go/no-go-beslissing voor `/gsd-transition`.

    Acceptance-checks (alle MOETEN groen):

    1. **Migrate green:** `php artisan migrate --force` — moet zonder errors draaien (alle Plan 05a-01 + 5a-02 migrations + Spatie webhook-server).
    2. **Route-list count:** `php artisan route:list --path=v1/mollie --except-vendor` toont >= 13 routes (3 payments + 3 customers + 1 payment-methods + 3 refunds + 3 mandates + 4 subscriptions + 3 payment-links = 20 routes; 13 is ondergrens veiligheid).
    3. **Webhook-route present:** `php artisan route:list --path=webhooks` toont `POST /webhooks/mollie/{connection_id}`.
    4. **Container-bindings smoke:** `php artisan tinker --execute "var_dump(app()->bound(\\App\\Mollie\\MollieConnectionContext::class)); var_dump(app()->bound(\\Emeq\\MollieApi\\Contracts\\MollieCredentialResolver::class)); var_dump(app(\\Emeq\\MollieApi\\Mollie::class) instanceof \\Emeq\\MollieApi\\Mollie);"` — alle 3 booleans true.
    5. **Mollie singleton vs MollieApiClient bind:** `php artisan tinker --execute "echo (app(\\Emeq\\MollieApi\\Mollie::class) === app(\\Emeq\\MollieApi\\Mollie::class)) ? 'singleton-OK' : 'NOT-singleton';"` — verwacht `singleton-OK`. (D-03 verifie-punt.)
    6. **Full suite green:** `php artisan test --compact` — exit 0; toon test-counts (Phase 5a moet >= 50 nieuwe tests hebben opgeleverd: 7 mapper + 7 resolution + 10 webhooks + 18 payments-suite + 11 resources-trio + 6 sub/pl + 7 scramble + 2 sanctum = ~68).
    7. **Scramble OpenAPI valid JSON:** `curl -s http://hub.emeq.test:8090/docs/api.json?token=$(php artisan tinker --execute 'echo config(\"scramble.access_token\");' 2>/dev/null) | jq '.paths | keys | length'` — moet >= 13 mollie-paths tonen + alle bestaande Snelstart-paths. Voor lokale acceptance: `curl http://127.0.0.1:8001/docs/api.json` met `php artisan serve --port=8001` als voorbereiding.
    8. **Pint clean:** `vendor/bin/pint --test --format agent` exit 0 (geen unstaged formatting-issues).

    Als 1 van de 8 faalt: STOP. NIET committen tot fix. Documenteer welke check faalde + reden. Als alle 8 groen: presenteer summary aan user voor approve-stempel.
  </what-built>
  <how-to-verify>
    Voer alle 8 commando's uit en kopieer-plak hun output naar de checkpoint-respons. Per check: groen ✅ of rood ❌ + actie.

    Specifiek:

    ```bash
    # 1. Migrate
    php artisan migrate --force

    # 2. Route-list
    php artisan route:list --path=v1/mollie --except-vendor

    # 3. Webhook-route
    php artisan route:list --path=webhooks

    # 4. Container-bindings
    php artisan tinker --execute 'var_dump(app()->bound(\App\Mollie\MollieConnectionContext::class)); var_dump(app()->bound(\Emeq\MollieApi\Contracts\MollieCredentialResolver::class)); var_dump(app(\Emeq\MollieApi\Mollie::class) instanceof \Emeq\MollieApi\Mollie);'

    # 5. Singleton-check
    php artisan tinker --execute 'echo (app(\Emeq\MollieApi\Mollie::class) === app(\Emeq\MollieApi\Mollie::class)) ? "singleton-OK" : "NOT-singleton";'

    # 6. Full suite
    php artisan test --compact

    # 7. Scramble OpenAPI (W5 — CLI-pad, geen serve+sleep+pkill)
    # Optie a (preferred — als scramble:export bestaat in Scramble v0.x):
    php artisan scramble:export --path=storage/app/openapi-acceptance.json 2>&1 || true
    test -f storage/app/openapi-acceptance.json && jq '.paths | keys | map(select(. | contains("/mollie"))) | length' storage/app/openapi-acceptance.json
    # Optie b (fallback — bewijs via test-suite):
    # De ScrambleRouteDiscoveryTest cases uit Task 2 dekken alle 7 mollie-resources
    # via in-process Spec-build. Als optie a faalt (artisan-command bestaat niet
    # in deze Scramble-versie), is check 7 al gedekt door check 6 (full suite groen).
    # Documenteer welke optie gebruikt is.

    # 8. Pint clean
    vendor/bin/pint --test --format agent
    ```

    Verzamel resultaten in een table met kolommen `Check | Status | Detail` en presenteer.
  </how-to-verify>
  <resume-signal>
    Type "approved" om phase 5a te closen + acceptance-summary in 05a-05-SUMMARY.md te committen.
    Type "fix-X" om eerst issue uit check-X te repareren (vermeld nummer).
    Type "skip-X" als check-X gerechtvaardigd geskipped kan worden (vermeld reden + ADR-referentie).
  </resume-signal>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Subscription-create payload → Mollie | interval-regex valideert format vóór Mollie-quota-burn |
| PaymentLink-create → Mollie | amount/minimumAmount mutual-exclusion door Mollie-side validation |
| BLOCKING acceptance → phase-go | All-or-nothing decision om regression-risico op `/gsd-transition` te elimineren |

## STRIDE Threat Register

| Threat ID | Category | Component | Severity | Disposition | Mitigation Plan |
|-----------|----------|-----------|----------|-------------|-----------------|
| T-05a-22 | Tampering | Consumer maakt subscription met `times: -1` of negatieve interval | low | mitigate | Form Request `min:1` op `times`; interval-regex weigert negatieve formats. |
| T-05a-23 | Information disclosure | Scramble OpenAPI exposes endpoint-tree publiek | medium | accept | Scramble heeft `viewApiDocs`-Gate met `?token=`-query (Phase 4 AppServiceProvider). Productie: token uit env. |
| T-05a-24 | Repudiation | Phase-acceptance overslaan zonder evidence | high | mitigate | BLOCKING checkpoint vereist user-approve; geen merge-pad zonder. |
</threat_model>

<verification>
- 2 nieuwe controllers (Subscriptions, PaymentLinks) extending base
- 2 nieuwe Form Requests
- 7 nieuwe routes
- ~6 + 9 + 2 = 17 nieuwe test-cases
- BLOCKING checkpoint groen
- Volledige suite exit 0
- pint clean
- Geen wijziging packages/mollie-api/**
</verification>

<success_criteria>
- MOLL-03 volledig: alle 7 Mollie-resources callable via /v1/mollie/* (Payments, Customers, PaymentMethods, Refunds, Mandates, Subscriptions, PaymentLinks)
- HUB-03 SC-2: alle resources in /docs/api OpenAPI-spec
- HUB-03 SC-1: bewezen via PaymentsTest (Plan 05a-03) + indirect via acceptance
- MOLL-04 SC-3: bewezen door Plan 05a-02 webhook-tests
- D-15: geen Mollie-Connection-provisioning-endpoint (blijft via OAuth init/callback uit Phase 4)
- BLOCKING acceptance 8/8 — phase 5a klaar voor `/gsd-transition`
</success_criteria>

<output>
Na completion: `.planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-05-SUMMARY.md` per template, met expliciete vermelding van:
- BLOCKING acceptance 8/8 status (per check: ✅/❌)
- Totaal-test-counts en route-counts voor Phase 5a
- HUB-03 SC-1..SC-5 alle bewezen (mapping per SC naar bewijs-plan/test)
- MOLL-03 + MOLL-04 status → "Validated" voor `/gsd-transition`
- Eventuele Scramble-quirks ontdekt (catch-all path-rendering, security-scope-extension)
- Aanbeveling voor `/gsd-transition`: MOLL-03 + MOLL-04 + HUB-03 → Validated; Phase 5a → completed in ROADMAP
- Trigger `docs-sync` skill als follow-up — nieuwe routes (13+), nieuwe Spatie-config, nieuwe Consumer-schema-velden, nieuwe webhook-route. Update STACK.md / ARCHITECTURE.md / CONVENTIONS.md indien nodig.
</output>
</content>
</invoke>