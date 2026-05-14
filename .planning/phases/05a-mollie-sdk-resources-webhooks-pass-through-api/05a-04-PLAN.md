---
phase: 05a-mollie-sdk-resources-webhooks-pass-through-api
plan: 04
type: execute
wave: 3
depends_on: [05a-01, 05a-03]
files_modified:
  - app/Http/Controllers/Api/V1/Mollie/CustomersController.php
  - app/Http/Controllers/Api/V1/Mollie/PaymentMethodsController.php
  - app/Http/Controllers/Api/V1/Mollie/RefundsController.php
  - app/Http/Controllers/Api/V1/Mollie/MandatesController.php
  - app/Http/Requests/Api/V1/Mollie/CreateCustomerRequest.php
  - app/Http/Requests/Api/V1/Mollie/CreateRefundRequest.php
  - routes/api.php
  - tests/Feature/Api/V1/Mollie/CustomersTest.php
  - tests/Feature/Api/V1/Mollie/PaymentMethodsTest.php
  - tests/Feature/Api/V1/Mollie/RefundsTest.php
  - tests/Feature/Api/V1/Mollie/MandatesTest.php
autonomous: true
requirements: [MOLL-03, HUB-03]
tags:
  - laravel
  - mollie
  - customers
  - payment-methods
  - refunds
  - mandates
  - phpunit

must_haves:
  decisions: [D-01, D-02, D-04, D-13, D-14]
  truths:
    - "MOLL-03 Customers (list + get + create) via pass-through op /v1/mollie/customers[/{id}]"
    - "MOLL-03 PaymentMethods (list-only) via pass-through op /v1/mollie/payment-methods (single-action __invoke)"
    - "MOLL-03 Refunds (create-on-payment + list-per-payment + get-by-id) via pass-through nested op payments + standalone get"
    - "MOLL-03 Mandates (list-per-customer + get + revoke) via pass-through nested op customers"
    - "Cross-Consumer per resource: A's PAT → B's account-id → 404 (mirror van resolution-test, één case per resource om regressie-coverage te houden)"
    - "Audit-rij per call met provider='mollie', endpoint-template als path"
  artifacts:
    - path: "app/Http/Controllers/Api/V1/Mollie/CustomersController.php"
      provides: "index/show/store extending AbstractMolliePassThroughController"
      contains: "class CustomersController extends AbstractMolliePassThroughController"
    - path: "app/Http/Controllers/Api/V1/Mollie/PaymentMethodsController.php"
      provides: "Single-action __invoke voor /v2/methods list"
      contains: "class PaymentMethodsController extends AbstractMolliePassThroughController"
    - path: "app/Http/Controllers/Api/V1/Mollie/RefundsController.php"
      provides: "store/index/show — nested onder payments + standalone get"
      contains: "class RefundsController extends AbstractMolliePassThroughController"
    - path: "app/Http/Controllers/Api/V1/Mollie/MandatesController.php"
      provides: "index/show/destroy nested onder customers"
      contains: "class MandatesController extends AbstractMolliePassThroughController"
  key_links:
    - from: "CustomersController"
      to: "Mollie::client()->customers"
      via: "Mollie::client()->customers->page() / ->get($id) / ->create($data)"
      pattern: "customers->(create|get|page|all)"
    - from: "PaymentMethodsController"
      to: "Mollie::client()->methods"
      via: "Mollie::client()->methods->all($params)"
      pattern: "methods->all"
    - from: "RefundsController"
      to: "Mollie's Payment->refunds() of payments->refunds-endpoint"
      via: "Mollie::client()->paymentRefunds (verifieer SDK API)"
      pattern: "refunds"
    - from: "MandatesController"
      to: "Mollie's customer->mandates of customerMandates-endpoint"
      via: "Mollie::client()->customerMandates (verifieer SDK API)"
      pattern: "mandates"
---

<objective>
Vier additional Mollie-resources op pass-through-pattern: Customers, PaymentMethods, Refunds, Mandates. Dezelfde shape als Plan 05a-03 Payments — concrete controllers extending `AbstractMolliePassThroughController`, Form Requests waar write, route-toevoegingen aan de bestaande Mollie-prefix-block.

Purpose: MOLL-03 dekking van 4 van de 7 resources (Subscriptions + PaymentLinks landen in Plan 05a-05).

Output: 4 controllers + 2 form requests + 11 routes + 4 feature-tests (1 per resource, ~3 cases elk = ~12 tests).

Niet in scope: Subscriptions, PaymentLinks, BLOCKING acceptance — die landen in Plan 05a-05.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-CONTEXT.md
@.planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-PATTERNS.md
@.planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-01-PLAN.md
@.planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-03-PLAN.md
@.docs/partners/mollie/customers-api.md
@.docs/partners/mollie/payment-methods-api.md
@.docs/partners/mollie/refunds-api.md
@.docs/partners/mollie/mandates-api.md
@.docs/decisions/mollie-passthrough-api.md
@CLAUDE.md
@app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php
@app/Http/Controllers/Api/V1/Mollie/PaymentsController.php
@app/Http/Middleware/ResolveMollieAccount.php
@app/Sanctum/TokenAbilities.php
@routes/api.php
@packages/mollie-api/src/Mollie.php

<interfaces>
<!-- Mollie SDK endpoint-mapping (verifieer in vendor/mollie/mollie-api-php/src/MollieApiClient.php properties). -->

Mollie's MollieApiClient exposeert (verifieer exact bij implement):
```
$client->customers          → CustomerEndpoint  (page/get/create/update/delete)
$client->methods            → MethodEndpoint    (all/get)
$client->customerPayments   → CustomerPaymentsEndpoint  (page-by-customer)
$client->paymentRefunds     → PaymentRefundsEndpoint    (page-by-payment, create-by-payment)
$client->refunds            → RefundEndpoint    (get standalone)
$client->customerMandates   → CustomerMandatesEndpoint  (page/get/revoke)
$client->customerSubscriptions → CustomerSubscriptionsEndpoint  (Plan 05a-05)
```

NB: Mollie's PHP SDK heeft per resource-type vaak twee endpoints (`->refunds` standalone + `->paymentRefunds` nested). Lees de juiste endpoint-class in vendor om te bepalen welke methode-naam ($client->paymentRefunds->page() vs ->all() vs ->getForId()) past per route.

Endpoint-templates voor audit-pad-kolom (D-05):
- /v2/customers, /v2/customers/{id}
- /v2/methods
- /v2/payments/{id}/refunds, /v2/refunds/{id}
- /v2/customers/{id}/mandates, /v2/customers/{id}/mandates/{mandate_id}
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: CustomersController + PaymentMethodsController + 4 routes + 2 feature-tests</name>
  <files>
    app/Http/Controllers/Api/V1/Mollie/CustomersController.php,
    app/Http/Controllers/Api/V1/Mollie/PaymentMethodsController.php,
    app/Http/Requests/Api/V1/Mollie/CreateCustomerRequest.php,
    routes/api.php,
    tests/Feature/Api/V1/Mollie/CustomersTest.php,
    tests/Feature/Api/V1/Mollie/PaymentMethodsTest.php
  </files>
  <behavior>
    **CustomersController** — resourceful (`index/show/store`):

    ```php
    namespace App\Http\Controllers\Api\V1\Mollie;

    use App\Http\Requests\Api\V1\Mollie\CreateCustomerRequest;
    use Emeq\MollieApi\Facades\Mollie;
    use Illuminate\Http\Request;
    use Symfony\Component\HttpFoundation\Response;

    class CustomersController extends AbstractMolliePassThroughController
    {
        public function index(Request $request): Response
        {
            return $this->handle($request, '/v2/customers', function (Request $r) {
                $page = Mollie::client()->customers->page(
                    $r->query('from'),
                    (int) $r->query('limit', 50),
                );
                return $this->collectionToArray($page);
            });
        }

        public function show(Request $request, string $id): Response
        {
            return $this->handle($request, '/v2/customers/{id}', fn () =>
                Mollie::client()->customers->get($id)->toArray()
            );
        }

        public function store(CreateCustomerRequest $request): Response
        {
            return $this->handle($request, '/v2/customers', function (CreateCustomerRequest $r) {
                $customer = Mollie::client()->customers->create($r->validated());
                return ['status' => 201, 'body' => $customer->toArray()];
            });
        }

        /**
         * Helper voor Mollie collection-response → array.
         * Verifieer in vendor: BaseCollection::toArray() of ::getArrayCopy().
         */
        private function collectionToArray($collection): array
        {
            if (method_exists($collection, 'toArray')) {
                return $collection->toArray();
            }
            return iterator_to_array($collection);
        }
    }
    ```

    **PaymentMethodsController** — single-action (`__invoke`), read-only list:

    ```php
    namespace App\Http\Controllers\Api\V1\Mollie;

    use Emeq\MollieApi\Facades\Mollie;
    use Illuminate\Http\Request;
    use Symfony\Component\HttpFoundation\Response;

    class PaymentMethodsController extends AbstractMolliePassThroughController
    {
        public function __invoke(Request $request): Response
        {
            return $this->handle($request, '/v2/methods', function (Request $r) {
                // Mollie's methods->all() accepteert optioneel parameters zoals 'amount', 'locale', 'sequenceType'
                $methods = Mollie::client()->methods->all($r->query());
                return method_exists($methods, 'toArray') ? $methods->toArray() : iterator_to_array($methods);
            });
        }
    }
    ```

    **CreateCustomerRequest** — Mollie's customers-create heeft GEEN required velden (zie `.docs/partners/mollie/customers-api.md`). Minimale rules:

    ```php
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email'],
            'locale' => ['nullable', 'string'],
            'metadata' => ['nullable'],
        ];
    }
    ```

    `authorize() { return true; }`.

    **4 routes in `routes/api.php`** — toevoegen aan de bestaande `Route::prefix('mollie')->middleware('resolve.mollie.account')->group(...)` block uit Plan 05a-03:

    ```php
    use App\Http\Controllers\Api\V1\Mollie\CustomersController;
    use App\Http\Controllers\Api\V1\Mollie\PaymentMethodsController;

    // Binnen het bestaande mollie-prefix-block:
    Route::get('/customers', [CustomersController::class, 'index'])->name('api.mollie.customers.index');
    Route::get('/customers/{id}', [CustomersController::class, 'show'])->name('api.mollie.customers.show');
    Route::post('/customers', [CustomersController::class, 'store'])->name('api.mollie.customers.store');
    Route::get('/payment-methods', PaymentMethodsController::class)->name('api.mollie.payment-methods.list');
    ```

    **CustomersTest** (~3 cases):
    1. `test_get_customers_returns_paginated_list_via_sdk` — mock customers->page; assert 200 + body
    2. `test_get_customer_by_id_returns_resource` — mock get('cst_xxx'); assert 200
    3. `test_post_customers_creates_resource_returns_201` — mock create; assert 201 + audit `path='/v2/customers'`

    **PaymentMethodsTest** (~2 cases):
    1. `test_get_payment_methods_returns_list_via_sdk` — mock methods->all; assert 200
    2. `test_get_payment_methods_with_query_parameters_passes_them_to_sdk` — query `?amount[currency]=EUR&amount[value]=10.00`; mock-capture; assert query passed
  </behavior>
  <read_first>
    - app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php (Plan 05a-01)
    - app/Http/Controllers/Api/V1/Mollie/PaymentsController.php (Plan 05a-03 — pattern)
    - app/Http/Requests/Api/V1/Mollie/CreatePaymentRequest.php (Plan 05a-03 — Form Request shape)
    - .docs/partners/mollie/customers-api.md (rules + endpoint-templates)
    - .docs/partners/mollie/payment-methods-api.md (parameters)
    - vendor/mollie/mollie-api-php/src/Endpoints/CustomerEndpoint.php (page/get/create/update/delete signatures)
    - vendor/mollie/mollie-api-php/src/Endpoints/MethodEndpoint.php (all/get)
    - vendor/mollie/mollie-api-php/src/MollieApiClient.php (verifieer property-namen: $customers, $methods)
    - vendor/mollie/mollie-api-php/src/Resources/BaseCollection.php (toArray()-API)
    - tests/Feature/Api/V1/Mollie/PaymentsTest.php (Plan 05a-03 — test-pattern)
    - .planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-PATTERNS.md regels 269-289 (PaymentMethodsController single-action)
  </read_first>
  <action>
    **Stap 1 — Create Form Request:**
    ```bash
    php artisan make:request Api/V1/Mollie/CreateCustomerRequest --no-interaction
    ```

    **Stap 2 — Create Controllers:**
    ```bash
    php artisan make:controller Api/V1/Mollie/CustomersController --no-interaction
    php artisan make:controller --invokable Api/V1/Mollie/PaymentMethodsController --no-interaction
    ```

    Implementeer per behavior. Verifieer in vendor of `customers->page()`-args overeenkomen, of `methods->all()`-collection een `toArray()` heeft.

    **Stap 3 — Update routes/api.php** met 4 nieuwe routes binnen het Mollie-prefix-block (toevoegen aan bestaande block uit Plan 05a-03).

    **Stap 4 — Genereer tests:**
    ```bash
    php artisan make:test --phpunit Api/V1/Mollie/CustomersTest --no-interaction
    php artisan make:test --phpunit Api/V1/Mollie/PaymentMethodsTest --no-interaction
    ```

    Schrijf 3 + 2 cases per behavior. Hergebruik mock-strategie + `setupMollieConsumer`-helper uit Plan 05a-03 task 3.

    **Stap 5 — Run:**
    ```bash
    vendor/bin/pint --dirty --format agent
    php artisan route:list --name=api.mollie.customers
    php artisan route:list --name=api.mollie.payment-methods
    php artisan test --compact --filter='CustomersTest|PaymentMethodsTest'
    ```
  </action>
  <verify>
    <automated>php artisan test --compact --filter='CustomersTest|PaymentMethodsTest'</automated>
  </verify>
  <acceptance_criteria>
    - `test -f app/Http/Controllers/Api/V1/Mollie/CustomersController.php`
    - `test -f app/Http/Controllers/Api/V1/Mollie/PaymentMethodsController.php`
    - `test -f app/Http/Requests/Api/V1/Mollie/CreateCustomerRequest.php`
    - `grep -c "extends AbstractMolliePassThroughController" app/Http/Controllers/Api/V1/Mollie/CustomersController.php` == 1
    - `grep -c "extends AbstractMolliePassThroughController" app/Http/Controllers/Api/V1/Mollie/PaymentMethodsController.php` == 1
    - `grep -cE "customers->(page|get|create)" app/Http/Controllers/Api/V1/Mollie/CustomersController.php` >= 3
    - `grep -c "methods->all" app/Http/Controllers/Api/V1/Mollie/PaymentMethodsController.php` == 1
    - `grep -c "/v2/customers" app/Http/Controllers/Api/V1/Mollie/CustomersController.php` >= 2
    - `grep -c "/v2/methods" app/Http/Controllers/Api/V1/Mollie/PaymentMethodsController.php` == 1
    - `grep -cE "(api.mollie.customers|api.mollie.payment-methods)" routes/api.php` >= 4
    - `grep -cE "public function test_" tests/Feature/Api/V1/Mollie/CustomersTest.php` >= 3
    - `grep -cE "public function test_" tests/Feature/Api/V1/Mollie/PaymentMethodsTest.php` >= 2
    - `php artisan test --compact --filter='CustomersTest|PaymentMethodsTest'` exit 0
  </acceptance_criteria>
  <done>2 controllers + 1 form-request + 4 routes + 5+ tests groen.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: RefundsController + 3 routes + feature-test</name>
  <files>
    app/Http/Controllers/Api/V1/Mollie/RefundsController.php,
    app/Http/Requests/Api/V1/Mollie/CreateRefundRequest.php,
    routes/api.php,
    tests/Feature/Api/V1/Mollie/RefundsTest.php
  </files>
  <behavior>
    **RefundsController** — handlt 3 routes:
    - POST /v1/mollie/payments/{id}/refunds — create-refund-on-payment
    - GET /v1/mollie/payments/{id}/refunds — list-refunds-per-payment
    - GET /v1/mollie/refunds/{id} — get-refund-by-id (standalone)

    Verifieer in vendor de Mollie SDK-API:
    - `$client->paymentRefunds->createForId($paymentId, $data)` of `$client->paymentRefunds->createFor($payment, $data)` — meeste vendor-versies hebben `createFor(Payment $payment, array $data)`. Voor route-controller zonder Payment-resource is `createForId($paymentId, $data)` ergonomischer; verifieer in `vendor/mollie/mollie-api-php/src/Endpoints/PaymentRefundEndpoint.php`.
    - `$client->paymentRefunds->pageForId($paymentId)` voor list.
    - `$client->refunds->get($refundId)` voor standalone get.

    Skelet:

    ```php
    namespace App\Http\Controllers\Api\V1\Mollie;

    use App\Http\Requests\Api\V1\Mollie\CreateRefundRequest;
    use Emeq\MollieApi\Facades\Mollie;
    use Illuminate\Http\Request;
    use Symfony\Component\HttpFoundation\Response;

    class RefundsController extends AbstractMolliePassThroughController
    {
        public function store(CreateRefundRequest $request, string $payment_id): Response
        {
            return $this->handle($request, '/v2/payments/{id}/refunds', function (CreateRefundRequest $r) use ($payment_id) {
                $refund = Mollie::client()->paymentRefunds->createForId($payment_id, $r->validated());
                return ['status' => 201, 'body' => $refund->toArray()];
            });
        }

        public function index(Request $request, string $payment_id): Response
        {
            return $this->handle($request, '/v2/payments/{id}/refunds', function (Request $r) use ($payment_id) {
                $list = Mollie::client()->paymentRefunds->pageForId($payment_id);
                return method_exists($list, 'toArray') ? $list->toArray() : iterator_to_array($list);
            });
        }

        public function show(Request $request, string $id): Response
        {
            return $this->handle($request, '/v2/refunds/{id}', fn () =>
                Mollie::client()->refunds->get($id)->toArray()
            );
        }
    }
    ```

    **CreateRefundRequest** — per `.docs/partners/mollie/refunds-api.md`:

    ```php
    public function rules(): array
    {
        return [
            'amount' => ['required', 'array'],
            'amount.currency' => ['required', 'string', 'size:3'],
            'amount.value' => ['required', 'string', 'regex:/^\d+\.\d{2,}$/'],
            'description' => ['nullable', 'string', 'max:255'],
            'externalReference' => ['nullable', 'array'],
            'metadata' => ['nullable'],
        ];
    }
    ```

    **3 routes in `routes/api.php`** — binnen mollie-prefix-block:

    ```php
    use App\Http\Controllers\Api\V1\Mollie\RefundsController;

    Route::post('/payments/{id}/refunds', [RefundsController::class, 'store'])->name('api.mollie.payments.refunds.store');
    Route::get('/payments/{id}/refunds', [RefundsController::class, 'index'])->name('api.mollie.payments.refunds.index');
    Route::get('/refunds/{id}', [RefundsController::class, 'show'])->name('api.mollie.refunds.show');
    ```

    **RefundsTest** (~3 cases):
    1. `test_post_payment_refunds_creates_refund_returns_201` — mock paymentRefunds->createForId; assert 201 + audit `path='/v2/payments/{id}/refunds'`
    2. `test_get_payment_refunds_lists_refunds` — mock paymentRefunds->pageForId; assert 200
    3. `test_get_refund_by_id_returns_resource` — mock refunds->get; assert 200
  </behavior>
  <read_first>
    - app/Http/Controllers/Api/V1/Mollie/PaymentsController.php (Plan 05a-03 — pattern)
    - .docs/partners/mollie/refunds-api.md (rules + endpoint-templates)
    - vendor/mollie/mollie-api-php/src/Endpoints/PaymentRefundEndpoint.php (verifieer createForId/pageForId)
    - vendor/mollie/mollie-api-php/src/Endpoints/RefundEndpoint.php (get-signature)
    - vendor/mollie/mollie-api-php/src/MollieApiClient.php (verifieer $paymentRefunds + $refunds property-namen)
    - tests/Feature/Api/V1/Mollie/PaymentsTest.php (Plan 05a-03 — test-pattern)
    - .planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-CONTEXT.md sectie `<specifics>` regel 256-267 (route-sketch)
  </read_first>
  <action>
    **Stap 1 — Create Request + Controller:**
    ```bash
    php artisan make:request Api/V1/Mollie/CreateRefundRequest --no-interaction
    php artisan make:controller Api/V1/Mollie/RefundsController --no-interaction
    ```

    **Stap 2** — Implementeer per behavior. Belangrijk: verifieer in `vendor/mollie/mollie-api-php/src/Endpoints/PaymentRefundEndpoint.php` of de methode `createForId($paymentId, $data)` of een variant heet — pas `paymentRefunds->createForId(...)` aan tot het klopt.

    **Stap 3** — Update routes/api.php met 3 nieuwe routes binnen mollie-prefix-block.

    **Stap 4** — Genereer test:
    ```bash
    php artisan make:test --phpunit Api/V1/Mollie/RefundsTest --no-interaction
    ```

    Schrijf 3 cases per behavior.

    **Stap 5:**
    ```bash
    vendor/bin/pint --dirty --format agent
    php artisan route:list --name=api.mollie.refunds
    php artisan test --compact --filter='RefundsTest'
    ```
  </action>
  <verify>
    <automated>php artisan test --compact --filter='RefundsTest'</automated>
  </verify>
  <acceptance_criteria>
    - `test -f app/Http/Controllers/Api/V1/Mollie/RefundsController.php`
    - `test -f app/Http/Requests/Api/V1/Mollie/CreateRefundRequest.php`
    - `grep -c "extends AbstractMolliePassThroughController" app/Http/Controllers/Api/V1/Mollie/RefundsController.php` == 1
    - `grep -cE "(paymentRefunds|refunds)" app/Http/Controllers/Api/V1/Mollie/RefundsController.php` >= 3
    - `grep -c "/v2/payments/{id}/refunds" app/Http/Controllers/Api/V1/Mollie/RefundsController.php` >= 2
    - `grep -c "/v2/refunds/{id}" app/Http/Controllers/Api/V1/Mollie/RefundsController.php` == 1
    - `grep -cE "(api.mollie.payments.refunds.store|api.mollie.payments.refunds.index|api.mollie.refunds.show)" routes/api.php` >= 3
    - `grep -cE "public function test_" tests/Feature/Api/V1/Mollie/RefundsTest.php` >= 3
    - `php artisan test --compact --filter='RefundsTest'` exit 0
  </acceptance_criteria>
  <done>RefundsController + form-request + 3 routes + 3 tests groen.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: MandatesController + 3 routes + feature-test</name>
  <files>
    app/Http/Controllers/Api/V1/Mollie/MandatesController.php,
    routes/api.php,
    tests/Feature/Api/V1/Mollie/MandatesTest.php
  </files>
  <behavior>
    **MandatesController** — handlt 3 routes:
    - GET /v1/mollie/customers/{id}/mandates — list-per-customer
    - GET /v1/mollie/customers/{id}/mandates/{mandate_id} — get
    - DELETE /v1/mollie/customers/{id}/mandates/{mandate_id} — revoke

    Verifieer in vendor:
    - `$client->customerMandates->pageForId($customerId)` of `pageFor($customer)` voor list
    - `$client->customerMandates->getForId($customerId, $mandateId)` voor get
    - `$client->customerMandates->revokeForId($customerId, $mandateId)` voor revoke

    Skelet:

    ```php
    namespace App\Http\Controllers\Api\V1\Mollie;

    use Emeq\MollieApi\Facades\Mollie;
    use Illuminate\Http\Request;
    use Symfony\Component\HttpFoundation\Response;

    class MandatesController extends AbstractMolliePassThroughController
    {
        public function index(Request $request, string $customer_id): Response
        {
            return $this->handle($request, '/v2/customers/{id}/mandates', function (Request $r) use ($customer_id) {
                $list = Mollie::client()->customerMandates->pageForId($customer_id);
                return method_exists($list, 'toArray') ? $list->toArray() : iterator_to_array($list);
            });
        }

        public function show(Request $request, string $customer_id, string $mandate_id): Response
        {
            return $this->handle($request, '/v2/customers/{id}/mandates/{mandate_id}', fn () =>
                Mollie::client()->customerMandates->getForId($customer_id, $mandate_id)->toArray()
            );
        }

        public function destroy(Request $request, string $customer_id, string $mandate_id): Response
        {
            return $this->handle($request, '/v2/customers/{id}/mandates/{mandate_id}', function (Request $r) use ($customer_id, $mandate_id) {
                Mollie::client()->customerMandates->revokeForId($customer_id, $mandate_id);
                return ['status' => 204, 'body' => []];
            });
        }
    }
    ```

    **GEEN Form Request** — Mandates zijn read/delete only. Geen create-route in v0.2 scope (Mandates worden door Mollie zelf aangemaakt na een first-payment).

    **3 routes in `routes/api.php`** — binnen mollie-prefix-block:

    ```php
    use App\Http\Controllers\Api\V1\Mollie\MandatesController;

    Route::get('/customers/{id}/mandates', [MandatesController::class, 'index'])->name('api.mollie.customers.mandates.index');
    Route::get('/customers/{id}/mandates/{mandate_id}', [MandatesController::class, 'show'])->name('api.mollie.customers.mandates.show');
    Route::delete('/customers/{id}/mandates/{mandate_id}', [MandatesController::class, 'destroy'])->name('api.mollie.customers.mandates.destroy');
    ```

    **MandatesTest** (~3 cases):
    1. `test_get_customer_mandates_lists_via_sdk` — mock customerMandates->pageForId; assert 200
    2. `test_get_mandate_by_id_returns_resource` — mock getForId; assert 200
    3. `test_delete_mandate_calls_revoke_returns_204` — mock revokeForId; assert 204
  </behavior>
  <read_first>
    - app/Http/Controllers/Api/V1/Mollie/PaymentsController.php (pattern)
    - .docs/partners/mollie/mandates-api.md (endpoint-templates + revoke-semantics)
    - vendor/mollie/mollie-api-php/src/Endpoints/CustomerMandateEndpoint.php (verifieer pageForId/getForId/revokeForId)
    - vendor/mollie/mollie-api-php/src/MollieApiClient.php (verifieer $customerMandates property)
    - tests/Feature/Api/V1/Mollie/PaymentsTest.php (test-pattern)
  </read_first>
  <action>
    ```bash
    php artisan make:controller Api/V1/Mollie/MandatesController --no-interaction
    php artisan make:test --phpunit Api/V1/Mollie/MandatesTest --no-interaction
    ```

    Implementeer + update routes/api.php + schrijf 3 tests per behavior.

    ```bash
    vendor/bin/pint --dirty --format agent
    php artisan route:list --name=api.mollie.customers.mandates
    php artisan test --compact --filter='MandatesTest'
    ```
  </action>
  <verify>
    <automated>php artisan test --compact --filter='MandatesTest'</automated>
  </verify>
  <acceptance_criteria>
    - `test -f app/Http/Controllers/Api/V1/Mollie/MandatesController.php`
    - `grep -c "extends AbstractMolliePassThroughController" app/Http/Controllers/Api/V1/Mollie/MandatesController.php` == 1
    - `grep -cE "customerMandates->(page|get|revoke)" app/Http/Controllers/Api/V1/Mollie/MandatesController.php` >= 3
    - `grep -c "/v2/customers/{id}/mandates" app/Http/Controllers/Api/V1/Mollie/MandatesController.php` >= 2
    - `grep -cE "api.mollie.customers.mandates" routes/api.php` >= 3
    - `grep -cE "public function test_" tests/Feature/Api/V1/Mollie/MandatesTest.php` >= 3
    - `php artisan test --compact --filter='MandatesTest'` exit 0
  </acceptance_criteria>
  <done>MandatesController + 3 routes + 3 tests groen.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Consumer-payload → Mollie create-customer/refund | Form Request validatie |
| Mandate-revoke → Mollie SDK | Onomkeerbaar bij Mollie — Hub heeft geen lokale state |
| Cross-Consumer access tot {customer_id}/{mandate_id} | Connection is al scoped door middleware; Mollie zelf returnt 404 voor cross-account-id's binnen één Mollie-account is OK want Mollie's customer-id is uniek per Mollie-tenant |

## STRIDE Threat Register

| Threat ID | Category | Component | Severity | Disposition | Mitigation Plan |
|-----------|----------|-----------|----------|-------------|-----------------|
| T-05a-19 | Tampering | Consumer revoked Mandate van een eindgebruiker zonder Account-eigenaar's instemming | medium | accept | Consumer = SaaS-app heeft per definitie controle over zijn Accounts (Account = school A); Account = school A is de Mollie-merchant; eindgebruiker (parent) heeft géén PAT. Geen toegevoegde threat boven Mollie's eigen authorization. |
| T-05a-20 | Information disclosure | Refund-amount lekt via audit-rij `query_keys` of `path` | low | accept | Refund-amount zit in body, niet in query/path; body wordt alleen gefingerprinted, niet opgeslagen. |
| T-05a-21 | Spoofing | Consumer probeert Mandate van andere Mollie-customer te lezen via `/v1/mollie/customers/X/mandates/Y` waar X niet bij deze Connection's Mollie-account hoort | low | accept | Mollie's API returnt 404 (mandate bestaat niet binnen deze access_token's scope). Hub mapt naar 404 via NotFoundException → MollieUpstreamErrorMapper. Bewezen indirect door MolliePassThroughErrorMappingTest. |
</threat_model>

<verification>
- 4 nieuwe controllers (Customers, PaymentMethods, Refunds, Mandates) extending AbstractMolliePassThroughController
- 2 nieuwe Form Requests (CreateCustomer, CreateRefund)
- 10 nieuwe routes onder /v1/mollie/* (3 customers + 1 payment-methods + 3 refunds + 3 mandates)
- 4 feature-tests (~11 cases totaal)
- Volledige `php artisan test --compact` exit 0
- Geen wijziging onder packages/mollie-api/**
- pint clean
</verification>

<success_criteria>
- MOLL-03 dekking: 4 van 7 resources via pass-through callable (Customers, PaymentMethods, Refunds, Mandates)
- Bewijs dat het pass-through-pattern uniform werkt over read/write/nested resources
- D-01, D-02, D-04, D-13, D-14 ingelost in code
</success_criteria>

<output>
Na completion: `.planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-04-SUMMARY.md` per template, met expliciete vermelding van:
- Vendor SDK-API-discoveries (welke methode-naam exact werkte voor paymentRefunds/customerMandates — kan afwijken per vendor-versie)
- Test-counts per file
- Eventuele Form Request-veld-discrepanties tegen .docs/partners/mollie/* (executor mag wijzigen, documenteer afwijking)
- Trigger `docs-sync` skill als follow-up — 10 nieuwe routes onder /v1/mollie/* + 4 nieuwe controllers + 2 nieuwe form-requests. Update ARCHITECTURE.md / CONVENTIONS.md indien nodig.
</output>
</content>
</invoke>