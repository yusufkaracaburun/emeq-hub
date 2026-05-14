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
use Mollie\Api\Resources\Payment;
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
     * @var array{create: array<int, array<string, mixed>>, get: array<int, string>, cancel: array<int, string>, idempotency_keys: array<int, ?string>}
     */
    protected array $mollieCaptured = [
        'create' => [],
        'get' => [],
        'cancel' => [],
        'idempotency_keys' => [],
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
        $captured = &$this->mollieCaptured;

        $payments = new class($resolver, $captured, $this->mollieClient)
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

        $this->mollieClient = new StubMollieClient($payments);

        $mollie = $this->createMock(Mollie::class);
        $mollie->method('client')->willReturn($this->mollieClient);
        $this->app->instance(Mollie::class, $mollie);

        return $this->mollieClient;
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
