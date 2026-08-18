<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mollie;

use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\StubsMollieClient;
use Tests\TestCase;

class PaymentMethodsTest extends TestCase
{
    use RefreshDatabase;
    use StubsMollieClient;

    public function test_get_payment_methods_returns_list_via_sdk(): void
    {
        [, $token, , $connection] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_READ]);

        $this->bindMollieStubs([
            'methods' => fn (array $query) => $this->makeMethodCollection([
                ['id' => 'ideal', 'description' => 'iDEAL'],
                ['id' => 'creditcard', 'description' => 'Credit card'],
            ]),
        ]);

        $response = $this->callMollie($token, 'GET', '/v1/mollie/payment-methods');

        $response->assertOk()
            ->assertJsonCount(2);

        $this->assertCount(1, $this->mollieCaptured['method_all']);
        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'method' => 'GET',
            'path' => '/v2/methods',
            'status' => 200,
            'connection_id' => $connection->getKey(),
        ]);
    }

    public function test_get_payment_methods_with_query_parameters_passes_them_to_sdk(): void
    {
        [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_READ]);

        $this->bindMollieStubs([
            'methods' => fn (array $query) => $this->makeMethodCollection([]),
        ]);

        $this->callMollie($token, 'GET', '/v1/mollie/payment-methods?amount[currency]=EUR&amount[value]=10.00&locale=nl_NL');

        $captured = $this->mollieCaptured['method_all'][0];
        $this->assertSame(['currency' => 'EUR', 'value' => '10.00'], $captured['amount']);
        $this->assertSame('nl_NL', $captured['locale']);
    }
}
