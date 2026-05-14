<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mollie;

use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\StubsMollieClient;
use Tests\TestCase;

/**
 * Bewijst MOLL-03 SC-1 (Mollie-checkout-URL terug) + happy paths
 * (store/show/destroy) via SDK-stub.
 */
class PaymentsTest extends TestCase
{
    use RefreshDatabase;
    use StubsMollieClient;

    public function test_post_payments_proxies_through_sdk_and_returns_201_with_mollie_payload(): void
    {
        [, $token, , $connection] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        $this->bindMollieStub(fn (string $op) => match ($op) {
            'create' => $this->makePayment([
                'id' => 'tr_happy_1',
                'status' => 'open',
                'mode' => 'test',
                '_links' => ['checkout' => ['href' => 'https://mollie.test/checkout/tr_happy_1']],
            ]),
        });

        $payload = [
            'description' => 'Test betaling',
            'amount' => ['currency' => 'EUR', 'value' => '12.34'],
            'redirectUrl' => 'https://consumer.test/return',
        ];

        $response = $this->callMollie($token, 'POST', '/v1/mollie/payments', $payload);

        $response->assertCreated()
            ->assertJsonPath('id', 'tr_happy_1')
            ->assertJsonPath('status', 'open')
            ->assertJsonPath('_links.checkout.href', 'https://mollie.test/checkout/tr_happy_1');

        $this->assertCount(1, $this->mollieCaptured['create']);
        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'method' => 'POST',
            'path' => '/v2/payments',
            'status' => 201,
            'connection_id' => $connection->getKey(),
        ]);
    }

    public function test_post_payments_auto_injects_webhook_url_when_consumer_omits_it(): void
    {
        [, $token, , $connection] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        $this->bindMollieStub(fn () => $this->makePayment(['id' => 'tr_inject_1', 'status' => 'open']));

        $this->callMollie($token, 'POST', '/v1/mollie/payments', [
            'description' => 'No webhookUrl',
            'amount' => ['currency' => 'EUR', 'value' => '5.00'],
        ])->assertCreated();

        $captured = $this->mollieCaptured['create'][0];
        $this->assertSame(url("/webhooks/mollie/{$connection->getKey()}"), $captured['webhookUrl']);
    }

    public function test_post_payments_respects_consumer_provided_webhook_url(): void
    {
        [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        $this->bindMollieStub(fn () => $this->makePayment(['id' => 'tr_respect_1', 'status' => 'open']));

        $this->callMollie($token, 'POST', '/v1/mollie/payments', [
            'description' => 'Custom webhookUrl',
            'amount' => ['currency' => 'EUR', 'value' => '5.00'],
            'webhookUrl' => 'https://consumer.test/cb',
        ])->assertCreated();

        $this->assertSame('https://consumer.test/cb', $this->mollieCaptured['create'][0]['webhookUrl']);
    }

    public function test_get_payments_id_proxies_through_sdk_with_get(): void
    {
        [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_READ]);

        $this->bindMollieStub(fn (string $op, mixed $arg) => $this->makePayment([
            'id' => $arg,
            'status' => 'paid',
        ]));

        $response = $this->callMollie($token, 'GET', '/v1/mollie/payments/tr_xyz');

        $response->assertOk()
            ->assertJsonPath('id', 'tr_xyz')
            ->assertJsonPath('status', 'paid');

        $this->assertSame(['tr_xyz'], $this->mollieCaptured['get']);
        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'method' => 'GET',
            'path' => '/v2/payments/{id}',
            'status' => 200,
        ]);
    }

    public function test_delete_payments_id_calls_cancel(): void
    {
        [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        $this->bindMollieStub(fn (string $op, mixed $arg) => $this->makePayment([
            'id' => $arg,
            'status' => 'canceled',
        ]));

        $response = $this->callMollie($token, 'DELETE', '/v1/mollie/payments/tr_cancel_me');

        $response->assertOk()
            ->assertJsonPath('status', 'canceled');

        $this->assertSame(['tr_cancel_me'], $this->mollieCaptured['cancel']);
        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'method' => 'DELETE',
            'path' => '/v2/payments/{id}',
        ]);
    }
}
