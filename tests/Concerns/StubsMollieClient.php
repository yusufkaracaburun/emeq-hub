<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Emeq\MollieApi\Mollie;
use Illuminate\Testing\TestResponse;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Customer;
use Mollie\Api\Resources\Mandate;
use Mollie\Api\Resources\MandateCollection;
use Mollie\Api\Resources\Method;
use Mollie\Api\Resources\MethodCollection;
use Mollie\Api\Resources\Payment;
use Mollie\Api\Resources\PaymentLink;
use Mollie\Api\Resources\PaymentLinkCollection;
use Mollie\Api\Resources\Refund;
use Mollie\Api\Resources\RefundCollection;
use Mollie\Api\Resources\Subscription;
use Mollie\Api\Resources\SubscriptionCollection;
use Tests\Feature\Api\V1\Mollie\StubMollieClient;
use Throwable;

/**
 * Test-helper voor Mollie-pass-through-tests. Binds een Mollie-wrapper-mock
 * waarvan `client()` een test-only MollieApiClient-subclass retourneert met
 * een stub `payments`-endpoint. De stub capture't payloads en
 * Idempotency-Key-runtime-state vlak vóór elke call zodat tests precies
 * kunnen asserten wat naar Mollie zou zijn gegaan.
 *
 * Patroon overgenomen van Plan 05a-02's ThrowingMollieApiClient, uitgebreid
 * met success-pad (Payment-resource-return) + key-capture.
 */
trait StubsMollieClient
{
    /**
     * @var array<string, array<int, mixed>>
     */
    protected array $mollieCaptured = [
        // Payments (Plan 05a-03)
        'create' => [],
        'get' => [],
        'cancel' => [],
        'idempotency_keys' => [],
        // Customers (Plan 05a-04 Task 1)
        'customer_create' => [],
        'customer_get' => [],
        'customer_page' => [],
        // Methods (Plan 05a-04 Task 1)
        'method_all' => [],
        // PaymentRefunds + Refunds (Plan 05a-04 Task 2)
        'refund_create_for_id' => [],
        'refund_page_for_id' => [],
        'refund_get_for_id' => [],
        // CustomerMandates (Plan 05a-04 Task 3)
        'mandate_page_for_id' => [],
        'mandate_get_for_id' => [],
        'mandate_revoke_for_id' => [],
        // Subscriptions (Plan 05a-05 Task 1) — nested onder Customer
        'subscription_create_for_id' => [],
        'subscription_get_for_id' => [],
        'subscription_page_for_id' => [],
        'subscription_cancel_for_id' => [],
        // PaymentLinks (Plan 05a-05 Task 1) — top-level
        'payment_link_create' => [],
        'payment_link_get' => [],
        'payment_link_page' => [],
    ];

    protected ?StubMollieClient $mollieClient = null;

    /**
     * @param  callable(string $op, mixed $arg): (Payment|Throwable)  $resolver
     *                                                                           $op = 'create'|'get'|'cancel'; $arg = payload-array of payment-id-string.
     *                                                                           Return een Payment (success) of een Throwable (error). Throwables
     *                                                                           worden binnen de stub re-thrown zodat de controller-catch-block ze
     *                                                                           normaal mapt via MollieExceptionMapper.
     */
    protected function bindMollieStub(callable $resolver): StubMollieClient
    {
        return $this->bindMollieStubs(['payments' => $resolver]);
    }

    /**
     * Binds een StubMollieClient met meerdere endpoint-stubs in één keer.
     * Per resource een aparte resolver-callable die de stub voor die
     * property bouwt. De resolver-signature verschilt per resource:
     *
     *  - 'payments'         : callable(string $op, mixed $arg): Payment|Throwable
     *                         (compat met Plan 05a-03 — $op = create|get|cancel)
     *  - 'customers'        : callable(string $op, mixed $arg): Customer|Throwable
     *                         ($op = create | get | page; $arg = payload-array of id-string of [$from, $limit])
     *  - 'methods'          : callable(array $query): MethodCollection|Throwable
     *  - 'paymentRefunds'   : callable(string $op, mixed $arg): Refund|RefundCollection|Throwable
     *                         ($op = createForId | pageForId | getForId)
     *  - 'mandates'         : callable(string $op, mixed $arg): Mandate|MandateCollection|null|Throwable
     *                         ($op = pageForId | getForId | revokeForId)
     *                         (Mollie SDK exposes het op `MollieApiClient::$mandates`, niet `$customerMandates`)
     *  - 'subscriptions'    : callable(string $op, mixed $arg): Subscription|SubscriptionCollection|Throwable
     *                         ($op = createForId | getForId | pageForId | cancelForId)
     *                         (Vendor: `MollieApiClient::$subscriptions` — NIET `$customerSubscriptions`)
     *  - 'paymentLinks'     : callable(string $op, mixed $arg): PaymentLink|PaymentLinkCollection|Throwable
     *                         ($op = create | get | page)
     *
     * @param  array<string, callable>  $resolvers
     */
    protected function bindMollieStubs(array $resolvers): StubMollieClient
    {
        $captured = &$this->mollieCaptured;
        $clientRef = &$this->mollieClient;

        $paymentsResolver = $resolvers['payments'] ?? null;
        $paymentsStub = $paymentsResolver !== null
            ? $this->makePaymentsStub($paymentsResolver, $captured, $clientRef)
            : new class
            {
                // Lege placeholder zodat StubMollieClient een 'payments'-property heeft
                // (vereist door MollieApiClient::$payments magic-getter elders).
            };

        $extras = [];
        if (isset($resolvers['customers'])) {
            $extras['customers'] = $this->makeCustomersStub($resolvers['customers'], $captured);
        }
        if (isset($resolvers['methods'])) {
            $extras['methods'] = $this->makeMethodsStub($resolvers['methods'], $captured);
        }
        if (isset($resolvers['paymentRefunds'])) {
            $extras['paymentRefunds'] = $this->makePaymentRefundsStub($resolvers['paymentRefunds'], $captured, $clientRef);
        }
        if (isset($resolvers['mandates'])) {
            $extras['mandates'] = $this->makeMandatesStub($resolvers['mandates'], $captured);
        }
        if (isset($resolvers['subscriptions'])) {
            $extras['subscriptions'] = $this->makeSubscriptionsStub($resolvers['subscriptions'], $captured, $clientRef);
        }
        if (isset($resolvers['paymentLinks'])) {
            $extras['paymentLinks'] = $this->makePaymentLinksStub($resolvers['paymentLinks'], $captured, $clientRef);
        }

        $this->mollieClient = new StubMollieClient($paymentsStub, $extras);

        $mollie = $this->createMock(Mollie::class);
        $mollie->method('client')->willReturn($this->mollieClient);
        $this->app->instance(Mollie::class, $mollie);

        return $this->mollieClient;
    }

    /**
     * @param  callable(string, mixed): (Payment|Throwable)  $resolver
     * @param  array<string, array<int, mixed>>  $captured
     */
    private function makePaymentsStub(callable $resolver, array &$captured, ?StubMollieClient &$clientRef): object
    {
        return new class($resolver, $captured, $clientRef)
        {
            public function __construct(
                private $resolver,
                private array &$captured,
                private ?StubMollieClient &$mollieClient,
            ) {}

            public function create(array $payload): Payment
            {
                $this->captured['create'][] = $payload;
                $this->captured['idempotency_keys'][] = $this->mollieClient?->getIdempotencyKey();
                $result = ($this->resolver)('create', $payload);
                if ($result instanceof Throwable) {
                    throw $result;
                }

                return $result;
            }

            public function get(string $id): Payment
            {
                $this->captured['get'][] = $id;
                $this->captured['idempotency_keys'][] = $this->mollieClient?->getIdempotencyKey();
                $result = ($this->resolver)('get', $id);
                if ($result instanceof Throwable) {
                    throw $result;
                }

                return $result;
            }

            public function cancel(string $id): Payment
            {
                $this->captured['cancel'][] = $id;
                $this->captured['idempotency_keys'][] = $this->mollieClient?->getIdempotencyKey();
                $result = ($this->resolver)('cancel', $id);
                if ($result instanceof Throwable) {
                    throw $result;
                }

                return $result;
            }
        };
    }

    /**
     * @param  callable(string, mixed): (Customer|Throwable)  $resolver
     * @param  array<string, array<int, mixed>>  $captured
     */
    private function makeCustomersStub(callable $resolver, array &$captured): object
    {
        return new class($resolver, $captured)
        {
            public function __construct(
                private $resolver,
                private array &$captured,
            ) {}

            public function create(array $payload): Customer
            {
                $this->captured['customer_create'][] = $payload;
                $result = ($this->resolver)('create', $payload);
                if ($result instanceof Throwable) {
                    throw $result;
                }

                return $result;
            }

            public function get(string $id): Customer
            {
                $this->captured['customer_get'][] = $id;
                $result = ($this->resolver)('get', $id);
                if ($result instanceof Throwable) {
                    throw $result;
                }

                return $result;
            }

            public function page(?string $from = null, ?int $limit = null): mixed
            {
                $this->captured['customer_page'][] = ['from' => $from, 'limit' => $limit];
                $result = ($this->resolver)('page', ['from' => $from, 'limit' => $limit]);
                if ($result instanceof Throwable) {
                    throw $result;
                }

                return $result;
            }
        };
    }

    /**
     * @param  callable(array): (MethodCollection|Throwable)  $resolver
     * @param  array<string, array<int, mixed>>  $captured
     */
    private function makeMethodsStub(callable $resolver, array &$captured): object
    {
        return new class($resolver, $captured)
        {
            public function __construct(
                private $resolver,
                private array &$captured,
            ) {}

            public function all(array $query = []): mixed
            {
                $this->captured['method_all'][] = $query;
                $result = ($this->resolver)($query);
                if ($result instanceof Throwable) {
                    throw $result;
                }

                return $result;
            }
        };
    }

    /**
     * @param  callable(string, mixed): (Refund|mixed|Throwable)  $resolver
     * @param  array<string, array<int, mixed>>  $captured
     */
    private function makePaymentRefundsStub(callable $resolver, array &$captured, ?StubMollieClient &$clientRef): object
    {
        return new class($resolver, $captured, $clientRef)
        {
            public function __construct(
                private $resolver,
                private array &$captured,
                private ?StubMollieClient &$mollieClient,
            ) {}

            public function createForId(string $paymentId, array $payload = []): Refund
            {
                $this->captured['refund_create_for_id'][] = ['payment_id' => $paymentId, 'payload' => $payload];
                $this->captured['idempotency_keys'][] = $this->mollieClient?->getIdempotencyKey();
                $result = ($this->resolver)('createForId', ['payment_id' => $paymentId, 'payload' => $payload]);
                if ($result instanceof Throwable) {
                    throw $result;
                }

                return $result;
            }

            public function pageForId(string $paymentId, ?string $from = null, ?int $limit = null): mixed
            {
                $this->captured['refund_page_for_id'][] = ['payment_id' => $paymentId, 'from' => $from, 'limit' => $limit];
                $result = ($this->resolver)('pageForId', ['payment_id' => $paymentId, 'from' => $from, 'limit' => $limit]);
                if ($result instanceof Throwable) {
                    throw $result;
                }

                return $result;
            }

            public function getForId(string $paymentId, string $refundId): Refund
            {
                $this->captured['refund_get_for_id'][] = ['payment_id' => $paymentId, 'refund_id' => $refundId];
                $result = ($this->resolver)('getForId', ['payment_id' => $paymentId, 'refund_id' => $refundId]);
                if ($result instanceof Throwable) {
                    throw $result;
                }

                return $result;
            }
        };
    }

    /**
     * @param  callable(string, mixed): (Mandate|mixed|Throwable|null)  $resolver
     * @param  array<string, array<int, mixed>>  $captured
     */
    private function makeMandatesStub(callable $resolver, array &$captured): object
    {
        return new class($resolver, $captured)
        {
            public function __construct(
                private $resolver,
                private array &$captured,
            ) {}

            public function pageForId(string $customerId, ?string $from = null, ?int $limit = null): mixed
            {
                $this->captured['mandate_page_for_id'][] = ['customer_id' => $customerId, 'from' => $from, 'limit' => $limit];
                $result = ($this->resolver)('pageForId', ['customer_id' => $customerId, 'from' => $from, 'limit' => $limit]);
                if ($result instanceof Throwable) {
                    throw $result;
                }

                return $result;
            }

            public function getForId(string $customerId, string $mandateId): Mandate
            {
                $this->captured['mandate_get_for_id'][] = ['customer_id' => $customerId, 'mandate_id' => $mandateId];
                $result = ($this->resolver)('getForId', ['customer_id' => $customerId, 'mandate_id' => $mandateId]);
                if ($result instanceof Throwable) {
                    throw $result;
                }

                return $result;
            }

            public function revokeForId(string $customerId, string $mandateId): void
            {
                $this->captured['mandate_revoke_for_id'][] = ['customer_id' => $customerId, 'mandate_id' => $mandateId];
                $result = ($this->resolver)('revokeForId', ['customer_id' => $customerId, 'mandate_id' => $mandateId]);
                if ($result instanceof Throwable) {
                    throw $result;
                }
            }
        };
    }

    /**
     * @param  callable(string, mixed): (Subscription|SubscriptionCollection|Throwable)  $resolver
     * @param  array<string, array<int, mixed>>  $captured
     */
    private function makeSubscriptionsStub(callable $resolver, array &$captured, ?StubMollieClient &$clientRef): object
    {
        return new class($resolver, $captured, $clientRef)
        {
            public function __construct(
                private $resolver,
                private array &$captured,
                private ?StubMollieClient &$mollieClient,
            ) {}

            public function createForId(string $customerId, array $payload = [], bool $testmode = false): Subscription
            {
                $this->captured['subscription_create_for_id'][] = ['customer_id' => $customerId, 'payload' => $payload];
                $this->captured['idempotency_keys'][] = $this->mollieClient?->getIdempotencyKey();
                $result = ($this->resolver)('createForId', ['customer_id' => $customerId, 'payload' => $payload]);
                if ($result instanceof Throwable) {
                    throw $result;
                }

                return $result;
            }

            public function getForId(string $customerId, string $subscriptionId, bool|array $testmode = false): Subscription
            {
                $this->captured['subscription_get_for_id'][] = ['customer_id' => $customerId, 'subscription_id' => $subscriptionId];
                $result = ($this->resolver)('getForId', ['customer_id' => $customerId, 'subscription_id' => $subscriptionId]);
                if ($result instanceof Throwable) {
                    throw $result;
                }

                return $result;
            }

            public function pageForId(string $customerId, ?string $from = null, ?int $limit = null, array $filters = []): mixed
            {
                $this->captured['subscription_page_for_id'][] = ['customer_id' => $customerId, 'from' => $from, 'limit' => $limit];
                $result = ($this->resolver)('pageForId', ['customer_id' => $customerId, 'from' => $from, 'limit' => $limit]);
                if ($result instanceof Throwable) {
                    throw $result;
                }

                return $result;
            }

            public function cancelForId(string $customerId, string $subscriptionId, bool $testmode = false): Subscription
            {
                $this->captured['subscription_cancel_for_id'][] = ['customer_id' => $customerId, 'subscription_id' => $subscriptionId];
                $result = ($this->resolver)('cancelForId', ['customer_id' => $customerId, 'subscription_id' => $subscriptionId]);
                if ($result instanceof Throwable) {
                    throw $result;
                }

                return $result;
            }
        };
    }

    /**
     * @param  callable(string, mixed): (PaymentLink|PaymentLinkCollection|Throwable)  $resolver
     * @param  array<string, array<int, mixed>>  $captured
     */
    private function makePaymentLinksStub(callable $resolver, array &$captured, ?StubMollieClient &$clientRef): object
    {
        return new class($resolver, $captured, $clientRef)
        {
            public function __construct(
                private $resolver,
                private array &$captured,
                private ?StubMollieClient &$mollieClient,
            ) {}

            public function create(array $payload = [], bool $testmode = false): PaymentLink
            {
                $this->captured['payment_link_create'][] = $payload;
                $this->captured['idempotency_keys'][] = $this->mollieClient?->getIdempotencyKey();
                $result = ($this->resolver)('create', $payload);
                if ($result instanceof Throwable) {
                    throw $result;
                }

                return $result;
            }

            public function get(string $paymentLinkId, bool|array $testmode = false): PaymentLink
            {
                $this->captured['payment_link_get'][] = $paymentLinkId;
                $result = ($this->resolver)('get', $paymentLinkId);
                if ($result instanceof Throwable) {
                    throw $result;
                }

                return $result;
            }

            public function page(?string $from = null, ?int $limit = null, bool|array $testmode = false): mixed
            {
                $this->captured['payment_link_page'][] = ['from' => $from, 'limit' => $limit];
                $result = ($this->resolver)('page', ['from' => $from, 'limit' => $limit]);
                if ($result instanceof Throwable) {
                    throw $result;
                }

                return $result;
            }
        };
    }

    /**
     * Helper voor een Subscription-resource met dynamic-properties gevuld.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeSubscription(array $attributes): Subscription
    {
        $subscription = new Subscription(new MollieApiClient);
        foreach ($attributes as $key => $value) {
            $subscription->{$key} = $value;
        }

        return $subscription;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    protected function makeSubscriptionCollection(array $items): SubscriptionCollection
    {
        $client = new MollieApiClient;
        $subscriptions = [];
        foreach ($items as $item) {
            $subscription = new Subscription($client);
            foreach ($item as $k => $v) {
                $subscription->{$k} = $v;
            }
            $subscriptions[] = $subscription;
        }

        return new SubscriptionCollection($client, $subscriptions, null);
    }

    /**
     * Helper voor een PaymentLink-resource met dynamic-properties gevuld.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makePaymentLink(array $attributes): PaymentLink
    {
        $link = new PaymentLink(new MollieApiClient);
        foreach ($attributes as $key => $value) {
            $link->{$key} = $value;
        }

        return $link;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    protected function makePaymentLinkCollection(array $items): PaymentLinkCollection
    {
        $client = new MollieApiClient;
        $links = [];
        foreach ($items as $item) {
            $link = new PaymentLink($client);
            foreach ($item as $k => $v) {
                $link->{$k} = $v;
            }
            $links[] = $link;
        }

        return new PaymentLinkCollection($client, $links, null);
    }

    /**
     * Helper voor een Customer-resource met dynamic-properties gevuld.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeCustomer(array $attributes): Customer
    {
        $customer = new Customer(new MollieApiClient);
        foreach ($attributes as $key => $value) {
            $customer->{$key} = $value;
        }

        return $customer;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    protected function makeMethodCollection(array $items): MethodCollection
    {
        $client = new MollieApiClient;
        $methods = [];
        foreach ($items as $item) {
            $method = new Method($client);
            foreach ($item as $k => $v) {
                $method->{$k} = $v;
            }
            $methods[] = $method;
        }

        return new MethodCollection($client, $methods, null);
    }

    /**
     * Helper voor een Refund-resource met dynamic-properties gevuld.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeRefund(array $attributes): Refund
    {
        $refund = new Refund(new MollieApiClient);
        foreach ($attributes as $key => $value) {
            $refund->{$key} = $value;
        }

        return $refund;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    protected function makeRefundCollection(array $items): RefundCollection
    {
        $client = new MollieApiClient;
        $refunds = [];
        foreach ($items as $item) {
            $refund = new Refund($client);
            foreach ($item as $k => $v) {
                $refund->{$k} = $v;
            }
            $refunds[] = $refund;
        }

        return new RefundCollection($client, $refunds, null);
    }

    /**
     * Helper voor een Mandate-resource met dynamic-properties gevuld.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeMandate(array $attributes): Mandate
    {
        $mandate = new Mandate(new MollieApiClient);
        foreach ($attributes as $key => $value) {
            $mandate->{$key} = $value;
        }

        return $mandate;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    protected function makeMandateCollection(array $items): MandateCollection
    {
        $client = new MollieApiClient;
        $mandates = [];
        foreach ($items as $item) {
            $mandate = new Mandate($client);
            foreach ($item as $k => $v) {
                $mandate->{$k} = $v;
            }
            $mandates[] = $mandate;
        }

        return new MandateCollection($client, $mandates, null);
    }

    /**
     * Helper voor een Payment-resource met dynamic-properties gevuld.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makePayment(array $attributes): Payment
    {
        $payment = new Payment(new MollieApiClient);
        foreach ($attributes as $key => $value) {
            $payment->{$key} = $value;
        }

        return $payment;
    }

    /**
     * Setup een Consumer + PAT + Account + actieve Mollie-Connection.
     *
     * @param  list<string>  $abilities
     * @return array{0:Consumer, 1:string, 2:Account, 3:Connection}
     */
    protected function setupMollieConsumer(array $abilities = [TokenAbilities::MOLLIE_WRITE], string $externalId = 'school-A'): array
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create(['external_id' => $externalId]);
        $connection = Connection::factory()->forMollie()->active()->for($account)->create();
        $token = $consumer->createToken('test', $abilities)->plainTextToken;

        return [$consumer, $token, $account, $connection];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $extraHeaders
     */
    protected function callMollie(string $token, string $method, string $uri, array $payload = [], array $extraHeaders = [], string $accountId = 'school-A'): TestResponse
    {
        $headers = array_merge([
            'Authorization' => "Bearer {$token}",
            'X-Account-Id' => $accountId,
            'Accept' => 'application/json',
        ], $extraHeaders);

        return $this->withHeaders($headers)->json($method, $uri, $payload);
    }
}
