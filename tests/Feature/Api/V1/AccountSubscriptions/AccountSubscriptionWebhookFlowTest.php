<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\AccountSubscriptions;

use App\Billing\Account\SubscriptionStatus;
use App\Jobs\ForwardMollieWebhookToConsumer;
use App\Models\AccountSubscription;
use Emeq\MollieApi\Webhooks\MollieWebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mollie\Api\Exceptions\NotFoundException as MollieNotFoundException;
use Tests\Concerns\StubsMollieClient;
use Tests\TestCase;

/**
 * Plan 07-06 Task 2 — feature-tests voor de Mollie-webhook ingress voor
 * AccountSubscriptions (Plan 07-05 WebhookPayloadRouter dispatch).
 *
 * Bewijst:
 *  - SC-2 (D-16): `payment.failed` met `details.failureReason='mandate_invalid'`
 *    op een matching AccountSubscription transitioneert state Active → Paused
 *    zonder Mollie-cancel-call.
 *  - SC-4 edge (deleted customer, D-17): `sub_*`-id payload + Mollie GET 404 →
 *    AccountSubscription state Unknown.
 *  - SC-4 edge (failed-retry-happy): `tr_*` paid recurring payment update't
 *    last_payment_status='paid' zonder state-flip.
 *  - D-31 (regressie-vrij): tampered signature → 400, state niet aangeraakt.
 *  - skip-pad: onbekend `sub_*`-id → 202 + geen state-mutatie + geen Mollie GET
 *    (handler skipt vóór anti-spoof-call zodat Mollie-quota niet verbrand).
 */
class AccountSubscriptionWebhookFlowTest extends TestCase
{
    use RefreshDatabase;
    use StubsMollieClient;

    private string $secret = 'whsec_test_xyz';

    protected function setUp(): void
    {
        parent::setUp();
        config(['mollie.webhook.secret' => $this->secret]);
    }

    public function test_payment_failed_with_mandate_invalid_transitions_subscription_to_paused(): void
    {
        Bus::fake();

        [, , , $connection] = $this->setupMollieConsumer();

        $sub = AccountSubscription::factory()
            ->forConnection($connection)
            ->active()
            ->create(['mollie_subscription_id' => 'sub_123', 'mollie_customer_id' => 'cst_abc']);

        $this->bindMollieStubs([
            'payments' => fn (string $op, mixed $arg) => $this->makePayment([
                'id' => 'tr_failed',
                'status' => 'failed',
                'subscriptionId' => 'sub_123',
                // Array-shape matched AccountSubscriptionManagerRecordPaymentEventTest
                // + plan 07-03 D-13 manager-contract `array<string, mixed>`. Mollie
                // SDK levert in productie een stdClass — die kennis-mismatch is
                // een Phase 7 integration-test concern (D-26).
                'details' => ['failureReason' => 'mandate_invalid'],
            ]),
        ]);

        $payload = json_encode(['id' => 'tr_failed'], JSON_THROW_ON_ERROR);
        $signature = MollieWebhookSignature::sign($payload, $this->secret);

        $response = $this->call(
            'POST',
            "/webhooks/mollie/{$connection->id}",
            [], [], [],
            ['HTTP_X_MOLLIE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $payload,
        );

        $response->assertStatus(202);

        $fresh = $sub->fresh();
        $this->assertSame(SubscriptionStatus::Paused, $fresh->status);
        $this->assertNotNull($fresh->paused_at);
        $this->assertSame('failed_mandate_invalid', $fresh->last_payment_status);
        $this->assertNotNull($fresh->last_webhook_event_at);

        // D-16: geen Mollie-cancel-call (Mollie's eigen retry stopt automatisch,
        // Consumer kan nieuwe mandate opzetten + resume).
        $this->assertSame([], $this->mollieCaptured['subscription_cancel_for_id']);
    }

    public function test_subscription_webhook_with_sub_prefix_syncs_state_to_unknown_on_mollie_404(): void
    {
        // SC-4 edge case — deleted customer/subscription at Mollie side.
        Bus::fake();

        [, , , $connection] = $this->setupMollieConsumer();

        $sub = AccountSubscription::factory()
            ->forConnection($connection)
            ->active()
            ->create(['mollie_subscription_id' => 'sub_lost', 'mollie_customer_id' => 'cst_lost']);

        $this->bindMollieStubs([
            'subscriptions' => fn (string $op, mixed $arg) => $this->fakeMollieNotFoundException(),
        ]);

        $payload = json_encode(['id' => 'sub_lost'], JSON_THROW_ON_ERROR);
        $signature = MollieWebhookSignature::sign($payload, $this->secret);

        $response = $this->call(
            'POST',
            "/webhooks/mollie/{$connection->id}",
            [], [], [],
            ['HTTP_X_MOLLIE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $payload,
        );

        $response->assertStatus(202);
        $this->assertSame(SubscriptionStatus::Unknown, $sub->fresh()->status);
    }

    public function test_paid_payment_recurring_updates_last_payment_status_without_state_flip(): void
    {
        // SC-4 edge — failed-retry-happy: na een eerdere `failed_insufficient_funds`
        // levert Mollie nu een paid-payment. last_payment_status update't naar
        // 'paid', state blijft Active.
        Bus::fake();

        [, , , $connection] = $this->setupMollieConsumer();

        $sub = AccountSubscription::factory()
            ->forConnection($connection)
            ->active()
            ->create([
                'mollie_subscription_id' => 'sub_retry',
                'mollie_customer_id' => 'cst_retry',
                'last_payment_status' => 'failed_insufficient_funds',
            ]);

        $this->bindMollieStubs([
            'payments' => fn (string $op, mixed $arg) => $this->makePayment([
                'id' => 'tr_paid_retry',
                'status' => 'paid',
                'subscriptionId' => 'sub_retry',
            ]),
        ]);

        $payload = json_encode(['id' => 'tr_paid_retry'], JSON_THROW_ON_ERROR);
        $signature = MollieWebhookSignature::sign($payload, $this->secret);

        $response = $this->call(
            'POST',
            "/webhooks/mollie/{$connection->id}",
            [], [], [],
            ['HTTP_X_MOLLIE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $payload,
        );

        $response->assertStatus(202);
        $fresh = $sub->fresh();
        $this->assertSame(SubscriptionStatus::Active, $fresh->status);
        $this->assertSame('paid', $fresh->last_payment_status);
        $this->assertNotNull($fresh->last_webhook_event_at);
    }

    public function test_tampered_signature_returns_400_without_state_mutation(): void
    {
        // D-31 invariant — Phase 5a regressie-vrij; bevestigt dat tampered
        // signature de state-machine NOOIT bereikt.
        Bus::fake();

        [, , , $connection] = $this->setupMollieConsumer();

        $sub = AccountSubscription::factory()
            ->forConnection($connection)
            ->active()
            ->create(['mollie_subscription_id' => 'sub_tamper', 'mollie_customer_id' => 'cst_tamper']);

        $payload = json_encode(['id' => 'sub_tamper'], JSON_THROW_ON_ERROR);
        $tampered = MollieWebhookSignature::sign($payload, 'wrong_secret');

        $response = $this->call(
            'POST',
            "/webhooks/mollie/{$connection->id}",
            [], [], [],
            ['HTTP_X_MOLLIE_SIGNATURE' => $tampered, 'CONTENT_TYPE' => 'application/json'],
            $payload,
        );

        $response->assertStatus(400)
            ->assertJsonPath('error', 'invalid_signature');

        // State niet aangeraakt — geen Mollie-call, geen fan-out.
        $this->assertSame(SubscriptionStatus::Active, $sub->fresh()->status);
        Bus::assertNotDispatched(ForwardMollieWebhookToConsumer::class);
    }

    /**
     * Mollie's NotFoundException-constructor verwacht een Response-object. Voor
     * een unit/feature-test maken we de exception via reflection — instanceof
     * blijft geldig voor de manager's catch-block (D-17 → state Unknown).
     */
    private function fakeMollieNotFoundException(): MollieNotFoundException
    {
        $reflection = new \ReflectionClass(MollieNotFoundException::class);
        /** @var MollieNotFoundException $ex */
        $ex = $reflection->newInstanceWithoutConstructor();

        $messageProp = (new \ReflectionClass(\Exception::class))->getProperty('message');
        $messageProp->setAccessible(true);
        $messageProp->setValue($ex, 'Subscription not found at Mollie');

        $codeProp = (new \ReflectionClass(\Exception::class))->getProperty('code');
        $codeProp->setAccessible(true);
        $codeProp->setValue($ex, 404);

        return $ex;
    }

    public function test_unknown_subscription_id_with_sub_prefix_returns_202_no_state_mutation(): void
    {
        // Onbekende `sub_*`-id (geen matching AccountSubscription) → handler skipt
        // vóór de Mollie GET (D-15 SubscriptionWebhookHandler skip-pad), 202 +
        // geen state-mutatie. Mollie GET wordt NIET aangeroepen.
        Bus::fake();

        [, , , $connection] = $this->setupMollieConsumer();

        // Stub die uitsluit dat Mollie wordt geraakt — als de handler toch
        // getForId aanroept, faalt de assertion onderaan.
        $this->bindMollieStubs([
            'subscriptions' => fn (string $op, mixed $arg) => $this->fail(
                "Mollie subscriptions->{$op} mocht niet aangeroepen worden voor onbekende sub_*-id.",
            ),
        ]);

        $payload = json_encode(['id' => 'sub_orphan'], JSON_THROW_ON_ERROR);
        $signature = MollieWebhookSignature::sign($payload, $this->secret);

        $response = $this->call(
            'POST',
            "/webhooks/mollie/{$connection->id}",
            [], [], [],
            ['HTTP_X_MOLLIE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $payload,
        );

        $response->assertStatus(202);
        $this->assertSame(0, AccountSubscription::query()->count(), 'Geen AccountSubscription mag aangemaakt zijn.');
        $this->assertSame([], $this->mollieCaptured['subscription_get_for_id']);
    }
}
