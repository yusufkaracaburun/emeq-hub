<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Mollie\Http\Api;

use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Integrations\Mollie\Concerns\StubsMollieClient;
use Tests\TestCase;

class PaymentLinksIdempotencyForwardTest extends TestCase
{
    use RefreshDatabase;
    use StubsMollieClient;

    public function test_consumer_idempotency_key_is_forwarded_on_payment_link_create(): void
    {
        [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        $this->bindMollieStubs([
            'paymentLinks' => fn (string $op, mixed $arg) => $this->makePaymentLink([
                'id' => 'pl_idem_4',
                'amount' => ['currency' => 'EUR', 'value' => '12.34'],
            ]),
        ]);

        $this->callMollie($token, 'POST', '/v1/mollie/payment-links', [
            'amount' => ['currency' => 'EUR', 'value' => '12.34'],
            'description' => 'Test payment link',
        ], ['Idempotency-Key' => 'link-key-004'])->assertCreated();

        $this->assertCount(1, $this->mollieCaptured['idempotency_keys']);
        $this->assertSame('link-key-004', $this->mollieCaptured['idempotency_keys'][0]);
    }
}
