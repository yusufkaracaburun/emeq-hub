<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Mollie\Http\Api;

use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Integrations\Mollie\Concerns\StubsMollieClient;
use Tests\TestCase;

class RefundsIdempotencyForwardTest extends TestCase
{
    use RefreshDatabase;
    use StubsMollieClient;

    public function test_consumer_idempotency_key_is_forwarded_on_refund_create(): void
    {
        [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        $this->bindMollieStubs([
            'paymentRefunds' => fn (string $op, mixed $arg) => $this->makeRefund([
                'id' => 're_idem_2',
                'amount' => ['currency' => 'EUR', 'value' => '5.00'],
            ]),
        ]);

        $this->callMollie($token, 'POST', '/v1/mollie/payments/tr_dummy/refunds', [
            'amount' => ['currency' => 'EUR', 'value' => '5.00'],
            'description' => 'Test refund',
        ], ['Idempotency-Key' => 'refund-key-002'])->assertCreated();

        $this->assertCount(1, $this->mollieCaptured['idempotency_keys']);
        $this->assertSame('refund-key-002', $this->mollieCaptured['idempotency_keys'][0]);
    }
}
