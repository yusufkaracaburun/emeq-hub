<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mollie;

use App\Sanctum\TokenAbilities;
use Emeq\MollieApi\Idempotency\UuidV7IdempotencyKeyGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\StubsMollieClient;
use Tests\TestCase;

/**
 * Bewijst MOLL-03 SC-5 (ROADMAP hard gate per B3) + D-06 forward-pad.
 *
 * Twee POST's met dezelfde Idempotency-Key MOETEN dezelfde Mollie-payment-id
 * retourneren (server-side dedup-emulation in stub) en de stub-client moet
 * verifieerbaar zien dat de Hub die exacte key heeft doorgegeven via
 * MollieApiClient::setIdempotencyKey() (preferred pad per PREFLIGHT.md V1).
 */
class MollieIdempotencyForwardTest extends TestCase
{
    use RefreshDatabase;
    use StubsMollieClient;

    public function test_two_post_with_same_idempotency_key_returns_same_mollie_payment_id(): void
    {
        [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        // Stub emuleert Mollie's server-side dedup: dezelfde Payment-id voor beide calls.
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

        // Beide calls bezaten exact dezelfde Idempotency-Key op de MollieApiClient
        // vóór ::create() — bewijst dat de Hub de key VERBATIM heeft doorgezet.
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

        // De Hub heeft GEEN runtime-key gezet (setIdempotencyKey is alleen voor de
        // consumer-override). De SDK-default UuidV7-generator wordt door Mollie's
        // ApplyIdempotencyKey-middleware aangeroepen pas tijdens send() — niet
        // observable via getIdempotencyKey() pre-call. Asserteren we dus dat de
        // pre-call setter NULL is (default-pad bevestigd) én dat de
        // config-generator op UuidV7IdempotencyKeyGenerator staat.
        $this->assertSame([null], $this->mollieCaptured['idempotency_keys']);
        $this->assertSame(
            UuidV7IdempotencyKeyGenerator::class,
            config('mollie.idempotency.generator'),
        );

        // Smoke-check dat de generator daadwerkelijk een UUID-v7 produceert.
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
        // En het is geen UUID-v7 fallback.
        $this->assertDoesNotMatchRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $this->mollieCaptured['idempotency_keys'][0],
        );
    }
}
