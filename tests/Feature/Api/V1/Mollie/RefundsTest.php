<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mollie;

use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\StubsMollieClient;
use Tests\TestCase;

class RefundsTest extends TestCase
{
    use RefreshDatabase;
    use StubsMollieClient;

    public function test_post_payment_refunds_creates_refund_returns_201(): void
    {
        [, $token, , $connection] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        $this->bindMollieStubs([
            'paymentRefunds' => fn (string $op, mixed $arg) => $this->makeRefund([
                'id' => 're_new_1',
                'paymentId' => $arg['payment_id'] ?? null,
                'amount' => $arg['payload']['amount'] ?? null,
                'status' => 'pending',
            ]),
        ]);

        $payload = [
            'amount' => ['currency' => 'EUR', 'value' => '5.00'],
            'description' => 'Partial refund',
        ];

        $response = $this->callMollie($token, 'POST', '/v1/mollie/payments/tr_test_1/refunds', $payload);

        $response->assertCreated()
            ->assertJsonPath('id', 're_new_1')
            ->assertJsonPath('paymentId', 'tr_test_1')
            ->assertJsonPath('status', 'pending');

        $captured = $this->mollieCaptured['refund_create_for_id'][0];
        $this->assertSame('tr_test_1', $captured['payment_id']);
        $this->assertSame($payload, $captured['payload']);
        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'method' => 'POST',
            'path' => '/v2/payments/{id}/refunds',
            'status' => 201,
            'connection_id' => $connection->getKey(),
        ]);
    }

    public function test_get_payment_refunds_lists_refunds_via_sdk(): void
    {
        [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_READ]);

        $this->bindMollieStubs([
            'paymentRefunds' => fn (string $op, mixed $arg) => $this->makeRefundCollection([
                ['id' => 're_a', 'paymentId' => $arg['payment_id'], 'status' => 'refunded'],
                ['id' => 're_b', 'paymentId' => $arg['payment_id'], 'status' => 'pending'],
            ]),
        ]);

        $response = $this->callMollie($token, 'GET', '/v1/mollie/payments/tr_test_2/refunds');

        $response->assertOk()
            ->assertJsonCount(2);

        $this->assertSame(
            ['payment_id' => 'tr_test_2', 'from' => null, 'limit' => null],
            $this->mollieCaptured['refund_page_for_id'][0],
        );
        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'method' => 'GET',
            'path' => '/v2/payments/{id}/refunds',
            'status' => 200,
        ]);
    }

    public function test_get_refund_by_id_via_query_payment_id_returns_resource(): void
    {
        [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_READ]);

        $this->bindMollieStubs([
            'paymentRefunds' => fn (string $op, mixed $arg) => $this->makeRefund([
                'id' => $arg['refund_id'],
                'paymentId' => $arg['payment_id'],
                'status' => 'refunded',
            ]),
        ]);

        $response = $this->callMollie($token, 'GET', '/v1/mollie/refunds/re_lookup?paymentId=tr_owner');

        $response->assertOk()
            ->assertJsonPath('id', 're_lookup')
            ->assertJsonPath('paymentId', 'tr_owner');

        $this->assertSame(
            ['payment_id' => 'tr_owner', 'refund_id' => 're_lookup'],
            $this->mollieCaptured['refund_get_for_id'][0],
        );
        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'method' => 'GET',
            'path' => '/v2/refunds/{id}',
            'status' => 200,
        ]);
    }

    public function test_get_refund_by_id_without_payment_id_returns_422(): void
    {
        [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_READ]);

        $this->bindMollieStubs([
            'paymentRefunds' => fn (string $op, mixed $arg) => $this->makeRefund(['id' => 're_x']),
        ]);

        $response = $this->callMollie($token, 'GET', '/v1/mollie/refunds/re_orphan');

        $response->assertStatus(422)
            ->assertJsonPath('error', 'missing_payment_id');
    }
}
