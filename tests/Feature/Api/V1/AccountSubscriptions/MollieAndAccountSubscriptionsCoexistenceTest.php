<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\AccountSubscriptions;

use App\Jobs\ForwardMollieWebhookToConsumer;
use App\Models\AccountSubscription;
use App\Sanctum\TokenAbilities;
use Emeq\MollieApi\Webhooks\MollieWebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\StubsMollieClient;
use Tests\TestCase;

/**
 * Plan 07-06 Task 2 — D-30 + D-31 coëxistentie:
 *
 *  - Phase 5a `/v1/mollie/customers/{id}/subscriptions/*` blijft pure
 *    pass-through (geen Hub-side AccountSubscription-rij).
 *  - Phase 7 `/v1/account-subscriptions/*` is een PARALLEL, hogere-laag-API.
 *  - Beide samen werkend in 1 request-cycle zonder credential-cross-contamination.
 *  - Webhook-default-pad (`tr_*` zonder `subscriptionId`) blijft de Phase 5a-flow
 *    volgen: fan-out-job wordt gedispatched — bevestigt D-31 invariant.
 */
class MollieAndAccountSubscriptionsCoexistenceTest extends TestCase
{
    use RefreshDatabase;
    use StubsMollieClient;

    private string $webhookSecret = 'whsec_test_xyz';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.mollie.webhook_secret' => $this->webhookSecret]);
    }

    public function test_phase_5a_passthrough_subscription_create_still_works_after_phase_7_refactor(): void
    {
        // Phase 5a route — pure pass-through. Géén AccountSubscription-rij.
        [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        $this->bindMollieStubs([
            'subscriptions' => fn (string $op, mixed $arg) => $this->makeSubscription([
                'id' => 'sub_5a',
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
            'description' => 'Pure pass-through 5a',
        ];

        $this->callMollie($token, 'POST', '/v1/mollie/customers/cst_abc/subscriptions', $payload)
            ->assertCreated()
            ->assertJsonPath('id', 'sub_5a');

        $this->assertSame(0, AccountSubscription::query()->count(), 'Phase 5a-create mag geen Hub AccountSubscription-rij maken.');
    }

    public function test_phase_7_create_and_phase_5a_create_can_coexist_in_same_request_cycle(): void
    {
        [, $token, , $connection] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        // Mollie subscriptions-stub dient beide endpoints — capture op call-volgorde.
        $this->bindMollieStubs([
            'subscriptions' => fn (string $op, mixed $arg) => $this->makeSubscription([
                'id' => 'sub_capt_'.count($this->mollieCaptured['subscription_create_for_id']),
                'status' => 'active',
                'customerId' => $arg['customer_id'],
            ]),
        ]);

        // 1. Phase 7 create — Hub-state-management.
        $phase7Body = [
            'account_external_id' => 'school-A',
            'mollie_customer_id' => 'cst_abc',
            'amount' => ['currency' => 'EUR', 'value' => '10.00'],
            'interval' => '1 month',
            'description' => 'Phase 7 — eigen Hub-row',
        ];

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/account-subscriptions', $phase7Body)
            ->assertCreated();

        $this->assertSame(1, AccountSubscription::query()->count());

        // 2. Phase 5a passthrough — geen extra Hub-row.
        $phase5aBody = [
            'amount' => ['currency' => 'EUR', 'value' => '7.50'],
            'interval' => '1 month',
            'description' => 'Phase 5a — pass-through',
        ];

        $this->callMollie($token, 'POST', '/v1/mollie/customers/cst_xyz/subscriptions', $phase5aBody)
            ->assertCreated();

        $this->assertSame(1, AccountSubscription::query()->count(), 'Phase 5a-call mag geen extra Hub-row maken.');

        // Beide Mollie-calls op dezelfde Connection (geen credential cross-contamination).
        $this->assertCount(2, $this->mollieCaptured['subscription_create_for_id']);
        $this->assertSame('cst_abc', $this->mollieCaptured['subscription_create_for_id'][0]['customer_id']);
        $this->assertSame('cst_xyz', $this->mollieCaptured['subscription_create_for_id'][1]['customer_id']);
        unset($connection);
    }

    public function test_phase_5a_webhook_payment_without_subscription_id_still_dispatches_fanout_job(): void
    {
        // D-31 invariant — `tr_*`-payload zonder `subscriptionId` valt in de
        // Phase 5a default-pad: anti-spoof Mollie GET → audit → fan-out.
        // PaymentWebhookHandler retourneert ok() (geen recordPaymentEvent) en
        // de controller dispatcht de fan-out-job.
        Bus::fake();

        [, , , $connection] = $this->setupMollieConsumer();

        $this->bindMollieStubs([
            'payments' => fn (string $op, mixed $arg) => $this->makePayment([
                'id' => 'tr_oneshot',
                'status' => 'paid',
                'subscriptionId' => null,
            ]),
        ]);

        $payload = json_encode(['id' => 'tr_oneshot'], JSON_THROW_ON_ERROR);
        $signature = MollieWebhookSignature::sign($payload, $this->webhookSecret);

        $this->call(
            'POST',
            "/webhooks/mollie/{$connection->id}",
            [], [], [],
            ['HTTP_X_MOLLIE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $payload,
        )->assertStatus(202);

        // D-31: Phase 5a fan-out-pad blijft werkend.
        Bus::assertDispatched(
            ForwardMollieWebhookToConsumer::class,
            fn (ForwardMollieWebhookToConsumer $job) => $job->mollieConnection->id === $connection->id
                && ($job->payload['id'] ?? null) === 'tr_oneshot',
        );

        $this->assertSame(0, AccountSubscription::query()->count(), 'Geen Hub-state-mutatie voor `tr_*` zonder subscriptionId.');
    }
}
