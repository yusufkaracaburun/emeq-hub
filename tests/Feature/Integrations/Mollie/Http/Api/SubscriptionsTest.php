<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Mollie\Http\Api;

use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Integrations\Mollie\Concerns\StubsMollieClient;
use Tests\TestCase;

class SubscriptionsTest extends TestCase
{
    use RefreshDatabase;
    use StubsMollieClient;

    public function test_get_customer_subscriptions_lists_via_sdk(): void
    {
        [, $token, , $connection] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_READ]);

        $this->bindMollieStubs([
            'subscriptions' => fn (string $op, mixed $arg) => $this->makeSubscriptionCollection([
                ['id' => 'sub_a', 'status' => 'active', 'customerId' => $arg['customer_id']],
                ['id' => 'sub_b', 'status' => 'pending', 'customerId' => $arg['customer_id']],
            ]),
        ]);

        $response = $this->callMollie($token, 'GET', '/v1/mollie/customers/cst_abc/subscriptions');

        $response->assertOk()->assertJsonCount(2);

        $this->assertSame(
            ['customer_id' => 'cst_abc', 'from' => null, 'limit' => null],
            $this->mollieCaptured['subscription_page_for_id'][0],
        );
        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'method' => 'GET',
            'path' => '/v2/customers/{id}/subscriptions',
            'status' => 200,
            'connection_id' => $connection->getKey(),
        ]);
    }

    public function test_post_customer_subscriptions_creates_via_sdk(): void
    {
        [, $token, , $connection] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        $this->bindMollieStubs([
            'subscriptions' => fn (string $op, mixed $arg) => $this->makeSubscription([
                'id' => 'sub_new_1',
                'status' => 'pending',
                'customerId' => $arg['customer_id'],
                'amount' => $arg['payload']['amount'] ?? null,
                'interval' => $arg['payload']['interval'] ?? null,
                'description' => $arg['payload']['description'] ?? null,
            ]),
        ]);

        $payload = [
            'amount' => ['currency' => 'EUR', 'value' => '25.00'],
            'interval' => '1 month',
            'description' => 'Maandelijkse contributie',
        ];

        $response = $this->callMollie($token, 'POST', '/v1/mollie/customers/cst_abc/subscriptions', $payload);

        $response->assertCreated()
            ->assertJsonPath('id', 'sub_new_1')
            ->assertJsonPath('customerId', 'cst_abc')
            ->assertJsonPath('description', 'Maandelijkse contributie');

        $this->assertCount(1, $this->mollieCaptured['subscription_create_for_id']);
        $this->assertSame('cst_abc', $this->mollieCaptured['subscription_create_for_id'][0]['customer_id']);
        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'method' => 'POST',
            'path' => '/v2/customers/{id}/subscriptions',
            'status' => 201,
            'connection_id' => $connection->getKey(),
        ]);
    }

    public function test_get_subscription_by_id_returns_resource(): void
    {
        [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_READ]);

        $this->bindMollieStubs([
            'subscriptions' => fn (string $op, mixed $arg) => $this->makeSubscription([
                'id' => $arg['subscription_id'],
                'customerId' => $arg['customer_id'],
                'status' => 'active',
            ]),
        ]);

        $response = $this->callMollie($token, 'GET', '/v1/mollie/customers/cst_xyz/subscriptions/sub_lookup');

        $response->assertOk()
            ->assertJsonPath('id', 'sub_lookup')
            ->assertJsonPath('customerId', 'cst_xyz')
            ->assertJsonPath('status', 'active');

        $this->assertSame(
            ['customer_id' => 'cst_xyz', 'subscription_id' => 'sub_lookup'],
            $this->mollieCaptured['subscription_get_for_id'][0],
        );
        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'method' => 'GET',
            'path' => '/v2/customers/{id}/subscriptions/{sub_id}',
            'status' => 200,
        ]);
    }

    public function test_delete_customer_subscription_calls_cancel(): void
    {
        [, $token, , $connection] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        $this->bindMollieStubs([
            'subscriptions' => fn (string $op, mixed $arg) => $this->makeSubscription([
                'id' => $arg['subscription_id'],
                'customerId' => $arg['customer_id'],
                'status' => 'canceled',
            ]),
        ]);

        $response = $this->callMollie($token, 'DELETE', '/v1/mollie/customers/cst_cancel/subscriptions/sub_to_cancel');

        $response->assertOk()
            ->assertJsonPath('id', 'sub_to_cancel')
            ->assertJsonPath('status', 'canceled');

        $this->assertSame(
            ['customer_id' => 'cst_cancel', 'subscription_id' => 'sub_to_cancel'],
            $this->mollieCaptured['subscription_cancel_for_id'][0],
        );
        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'method' => 'DELETE',
            'path' => '/v2/customers/{id}/subscriptions/{sub_id}',
            'status' => 200,
            'connection_id' => $connection->getKey(),
        ]);
    }
}
