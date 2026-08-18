<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mollie;

use App\Sanctum\TokenAbilities;
use Emeq\MollieApi\Idempotency\UuidV7IdempotencyKeyGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\StubsMollieClient;
use Tests\TestCase;

class MollieIdempotencyForwardTest extends TestCase
{
    use RefreshDatabase;
    use StubsMollieClient;

    public function test_two_post_with_same_idempotency_key_returns_same_mollie_payment_id(): void
    {
        [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        $this->bindMollieStub(fn () => $this->makePayment([
            'id' => 'tr_dedup_xyz',
            'status' => 'open',
            '_links' => ['checkout' => ['href' => 'https://mollie.test/checkout/tr_dedup_xyz']],
        ]));

        $payload = [
            'description' => 'SC-5 dedup-test',
            'amount' => ['currency' => 'EUR', 'value' => '9.99'],
            'redirectUrl' => 'https://consumer.test/r',
        ];
        $headers = ['Idempotency-Key' => 'idem-test-001'];

        $resp1 = $this->callMollie($token, 'POST', '/v1/mollie/payments', $payload, $headers);
        $resp2 = $this->callMollie($token, 'POST', '/v1/mollie/payments', $payload, $headers);

        $resp1->assertCreated()->assertJsonPath('id', 'tr_dedup_xyz');
        $resp2->assertCreated()->assertJsonPath('id', 'tr_dedup_xyz');
        $this->assertSame($resp1->json('id'), $resp2->json('id'));

        $this->assertCount(2, $this->mollieCaptured['idempotency_keys']);
        $this->assertSame('idem-test-001', $this->mollieCaptured['idempotency_keys'][0]);
        $this->assertSame('idem-test-001', $this->mollieCaptured['idempotency_keys'][1]);
    }

    public function test_post_without_idempotency_key_uses_uuid7_default_generator(): void
    {
        [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        $this->bindMollieStub(fn () => $this->makePayment(['id' => 'tr_uuid7_1', 'status' => 'open']));

        $this->callMollie($token, 'POST', '/v1/mollie/payments', [
            'description' => 'Geen consumer-header',
            'amount' => ['currency' => 'EUR', 'value' => '1.00'],
        ])->assertCreated();

        $this->assertSame([null], $this->mollieCaptured['idempotency_keys']);
        $this->assertSame(
            UuidV7IdempotencyKeyGenerator::class,
            config('mollie.idempotency.generator'),
        );

        $generator = new UuidV7IdempotencyKeyGenerator;
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $generator->generate(),
        );
    }

    public function test_consumer_idempotency_key_is_forwarded_verbatim_to_mollie(): void
    {
        [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        $this->bindMollieStub(fn () => $this->makePayment(['id' => 'tr_verbatim', 'status' => 'open']));

        $this->callMollie($token, 'POST', '/v1/mollie/payments', [
            'description' => 'Forward-bewijs',
            'amount' => ['currency' => 'EUR', 'value' => '2.50'],
        ], ['Idempotency-Key' => 'my-custom-key-xyz'])->assertCreated();

        $this->assertCount(1, $this->mollieCaptured['idempotency_keys']);
        $this->assertSame('my-custom-key-xyz', $this->mollieCaptured['idempotency_keys'][0]);
        $this->assertDoesNotMatchRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $this->mollieCaptured['idempotency_keys'][0],
        );
    }
}
