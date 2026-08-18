<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Mollie\Http\Api;

use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Integrations\Mollie\Concerns\StubsMollieClient;
use Tests\TestCase;

class SubscriptionsIdempotencyForwardTest extends TestCase
{
    use RefreshDatabase;
    use StubsMollieClient;

    public function test_consumer_idempotency_key_is_forwarded_on_subscription_create(): void
    {
        [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        $this->bindMollieStubs([
            'subscriptions' => fn (string $op, mixed $arg) => $this->makeSubscription([
                'id' => 'sub_idem_3',
                'status' => 'active',
            ]),
        ]);

        $this->callMollie($token, 'POST', '/v1/mollie/customers/cst_dummy/subscriptions', [
            'amount' => ['currency' => 'EUR', 'value' => '10.00'],
            'interval' => '1 month',
            'description' => 'Test subscription',
        ], ['Idempotency-Key' => 'sub-key-003'])->assertCreated();

        $this->assertCount(1, $this->mollieCaptured['idempotency_keys']);
        $this->assertSame('sub-key-003', $this->mollieCaptured['idempotency_keys'][0]);
    }
}
