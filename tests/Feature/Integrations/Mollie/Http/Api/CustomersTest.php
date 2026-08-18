<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Mollie\Http\Api;

use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Integrations\Mollie\Concerns\StubsMollieClient;
use Tests\TestCase;

class CustomersTest extends TestCase
{
    use RefreshDatabase;
    use StubsMollieClient;

    public function test_get_customers_returns_paginated_list_via_sdk(): void
    {
        [, $token, , $connection] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_READ]);

        $this->bindMollieStubs([
            'customers' => fn (string $op, mixed $arg) => match ($op) {
                'page' => $this->makeCustomer([
                    'id' => 'cst_listed_1',
                    'name' => 'School A',
                    'email' => 'a@example.test',
                ]),
            },
        ]);

        $response = $this->callMollie($token, 'GET', '/v1/mollie/customers');

        $response->assertOk()
            ->assertJsonPath('id', 'cst_listed_1')
            ->assertJsonPath('name', 'School A');

        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'method' => 'GET',
            'path' => '/v2/customers',
            'status' => 200,
            'connection_id' => $connection->getKey(),
        ]);
    }

    public function test_get_customer_by_id_returns_resource(): void
    {
        [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_READ]);

        $this->bindMollieStubs([
            'customers' => fn (string $op, mixed $arg) => $this->makeCustomer([
                'id' => $arg,
                'name' => 'Persisted Customer',
            ]),
        ]);

        $response = $this->callMollie($token, 'GET', '/v1/mollie/customers/cst_xyz');

        $response->assertOk()
            ->assertJsonPath('id', 'cst_xyz')
            ->assertJsonPath('name', 'Persisted Customer');

        $this->assertSame(['cst_xyz'], $this->mollieCaptured['customer_get']);
        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'method' => 'GET',
            'path' => '/v2/customers/{id}',
            'status' => 200,
        ]);
    }

    public function test_post_customers_creates_resource_returns_201(): void
    {
        [, $token, , $connection] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        $this->bindMollieStubs([
            'customers' => fn (string $op, mixed $arg) => $this->makeCustomer([
                'id' => 'cst_new_1',
                'name' => $arg['name'] ?? null,
                'email' => $arg['email'] ?? null,
            ]),
        ]);

        $payload = [
            'name' => 'Nieuwe klant',
            'email' => 'new@example.test',
            'locale' => 'nl_NL',
        ];

        $response = $this->callMollie($token, 'POST', '/v1/mollie/customers', $payload);

        $response->assertCreated()
            ->assertJsonPath('id', 'cst_new_1')
            ->assertJsonPath('name', 'Nieuwe klant');

        $this->assertCount(1, $this->mollieCaptured['customer_create']);
        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'method' => 'POST',
            'path' => '/v2/customers',
            'status' => 201,
            'connection_id' => $connection->getKey(),
        ]);
    }
}
