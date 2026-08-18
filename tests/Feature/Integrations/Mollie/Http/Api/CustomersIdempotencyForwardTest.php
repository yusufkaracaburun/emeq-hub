<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Mollie\Http\Api;

use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Integrations\Mollie\Concerns\StubsMollieClient;
use Tests\TestCase;

class CustomersIdempotencyForwardTest extends TestCase
{
    use RefreshDatabase;
    use StubsMollieClient;

    public function test_consumer_idempotency_key_is_forwarded_on_customer_create(): void
    {
        [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        $this->bindMollieStubs([
            'customers' => fn (string $op, mixed $arg) => $this->makeCustomer([
                'id' => 'cst_idem_1',
                'name' => 'Test Klant',
            ]),
        ]);

        $this->callMollie($token, 'POST', '/v1/mollie/customers', [
            'name' => 'Test Klant',
            'email' => 'klant@example.test',
        ], ['Idempotency-Key' => 'customer-key-001'])->assertCreated();

        $this->assertCount(1, $this->mollieCaptured['idempotency_keys']);
        $this->assertSame('customer-key-001', $this->mollieCaptured['idempotency_keys'][0]);
    }
}
