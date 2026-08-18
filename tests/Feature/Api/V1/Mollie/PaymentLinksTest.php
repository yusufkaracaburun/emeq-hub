<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mollie;

use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\StubsMollieClient;
use Tests\TestCase;

class PaymentLinksTest extends TestCase
{
    use RefreshDatabase;
    use StubsMollieClient;

    public function test_post_payment_links_creates_resource_returns_201(): void
    {
        [, $token, , $connection] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        $this->bindMollieStubs([
            'paymentLinks' => fn (string $op, mixed $arg) => $this->makePaymentLink([
                'id' => 'pl_new_1',
                'description' => $arg['description'] ?? null,
                'amount' => $arg['amount'] ?? null,
                '_links' => [
                    'paymentLink' => ['href' => 'https://paymentlink.mollie.com/pl_new_1'],
                ],
            ]),
        ]);

        $payload = [
            'description' => 'Kort betaalverzoek',
            'amount' => ['currency' => 'EUR', 'value' => '12.34'],
        ];

        $response = $this->callMollie($token, 'POST', '/v1/mollie/payment-links', $payload);

        $response->assertCreated()
            ->assertJsonPath('id', 'pl_new_1')
            ->assertJsonPath('description', 'Kort betaalverzoek')
            ->assertJsonPath('_links.paymentLink.href', 'https://paymentlink.mollie.com/pl_new_1');

        $this->assertCount(1, $this->mollieCaptured['payment_link_create']);
        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'method' => 'POST',
            'path' => '/v2/payment-links',
            'status' => 201,
            'connection_id' => $connection->getKey(),
        ]);
    }

    public function test_get_payment_links_lists_via_sdk(): void
    {
        [, $token, , $connection] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_READ]);

        $this->bindMollieStubs([
            'paymentLinks' => fn (string $op, mixed $arg) => $this->makePaymentLinkCollection([
                ['id' => 'pl_a', 'description' => 'Eerste link'],
                ['id' => 'pl_b', 'description' => 'Tweede link'],
            ]),
        ]);

        $response = $this->callMollie($token, 'GET', '/v1/mollie/payment-links');

        $response->assertOk()->assertJsonCount(2);

        $this->assertSame(
            ['from' => null, 'limit' => null],
            $this->mollieCaptured['payment_link_page'][0],
        );
        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'method' => 'GET',
            'path' => '/v2/payment-links',
            'status' => 200,
            'connection_id' => $connection->getKey(),
        ]);
    }

    public function test_get_payment_link_by_id_returns_resource(): void
    {
        [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_READ]);

        $this->bindMollieStubs([
            'paymentLinks' => fn (string $op, mixed $arg) => $this->makePaymentLink([
                'id' => $arg,
                'description' => 'Persisted link',
            ]),
        ]);

        $response = $this->callMollie($token, 'GET', '/v1/mollie/payment-links/pl_xyz');

        $response->assertOk()
            ->assertJsonPath('id', 'pl_xyz')
            ->assertJsonPath('description', 'Persisted link');

        $this->assertSame(['pl_xyz'], $this->mollieCaptured['payment_link_get']);
        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'method' => 'GET',
            'path' => '/v2/payment-links/{id}',
            'status' => 200,
        ]);
    }
}
