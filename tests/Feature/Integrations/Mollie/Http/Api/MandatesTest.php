<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Mollie\Http\Api;

use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Integrations\Mollie\Concerns\StubsMollieClient;
use Tests\TestCase;

class MandatesTest extends TestCase
{
    use RefreshDatabase;
    use StubsMollieClient;

    public function test_get_customer_mandates_lists_via_sdk(): void
    {
        [, $token, , $connection] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_READ]);

        $this->bindMollieStubs([
            'mandates' => fn (string $op, mixed $arg) => $this->makeMandateCollection([
                ['id' => 'mdt_a', 'status' => 'valid', 'customerId' => $arg['customer_id']],
                ['id' => 'mdt_b', 'status' => 'pending', 'customerId' => $arg['customer_id']],
            ]),
        ]);

        $response = $this->callMollie($token, 'GET', '/v1/mollie/customers/cst_abc/mandates');

        $response->assertOk()->assertJsonCount(2);

        $this->assertSame(
            ['customer_id' => 'cst_abc', 'from' => null, 'limit' => null],
            $this->mollieCaptured['mandate_page_for_id'][0],
        );
        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'method' => 'GET',
            'path' => '/v2/customers/{id}/mandates',
            'status' => 200,
            'connection_id' => $connection->getKey(),
        ]);
    }

    public function test_get_mandate_by_id_returns_resource(): void
    {
        [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_READ]);

        $this->bindMollieStubs([
            'mandates' => fn (string $op, mixed $arg) => $this->makeMandate([
                'id' => $arg['mandate_id'],
                'customerId' => $arg['customer_id'],
                'status' => 'valid',
            ]),
        ]);

        $response = $this->callMollie($token, 'GET', '/v1/mollie/customers/cst_xyz/mandates/mdt_lookup');

        $response->assertOk()
            ->assertJsonPath('id', 'mdt_lookup')
            ->assertJsonPath('customerId', 'cst_xyz')
            ->assertJsonPath('status', 'valid');

        $this->assertSame(
            ['customer_id' => 'cst_xyz', 'mandate_id' => 'mdt_lookup'],
            $this->mollieCaptured['mandate_get_for_id'][0],
        );
        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'method' => 'GET',
            'path' => '/v2/customers/{id}/mandates/{mandate_id}',
            'status' => 200,
        ]);
    }

    public function test_delete_mandate_calls_revoke_returns_204(): void
    {
        [, $token, , $connection] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        $this->bindMollieStubs([
            'mandates' => fn (string $op, mixed $arg) => null,
        ]);

        $response = $this->callMollie($token, 'DELETE', '/v1/mollie/customers/cst_revoke/mandates/mdt_to_revoke');

        $response->assertStatus(204);

        $this->assertSame(
            ['customer_id' => 'cst_revoke', 'mandate_id' => 'mdt_to_revoke'],
            $this->mollieCaptured['mandate_revoke_for_id'][0],
        );
        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'method' => 'DELETE',
            'path' => '/v2/customers/{id}/mandates/{mandate_id}',
            'status' => 204,
            'connection_id' => $connection->getKey(),
        ]);
    }
}
