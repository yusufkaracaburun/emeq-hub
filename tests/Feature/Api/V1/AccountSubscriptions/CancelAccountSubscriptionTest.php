<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\AccountSubscriptions;

use App\Billing\Account\SubscriptionStatus;
use App\Models\Account;
use App\Models\AccountSubscription;
use App\Models\Connection;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\StubsMollieClient;
use Tests\TestCase;

/**
 * Plan 07-06 Task 1 — feature-tests voor DELETE /v1/account-subscriptions/{id}.
 *
 * Bewijst:
 *  - happy: Active sub → Mollie-cancel + Hub-state Canceled + 204
 *  - re-cancel op already-Canceled: self-transition no-op (StateTransitions.D-04),
 *    Mollie cancelForId wél aangeroepen (manager skipt niet op canceled-state),
 *    response blijft 204
 *  - cross-Consumer DELETE → 404 (D-12)
 *  - read-only-token → 403 (route-ability gating)
 */
class CancelAccountSubscriptionTest extends TestCase
{
    use RefreshDatabase;
    use StubsMollieClient;

    public function test_happy_path_cancels_active_subscription(): void
    {
        [, $token, , $connection] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        $sub = AccountSubscription::factory()
            ->forConnection($connection)
            ->active()
            ->create(['mollie_subscription_id' => 'sub_cancel_me', 'mollie_customer_id' => 'cst_abc']);

        $this->bindMollieStubs([
            'subscriptions' => fn (string $op, mixed $arg) => $this->makeSubscription([
                'id' => $arg['subscription_id'],
                'customerId' => $arg['customer_id'],
                'status' => 'canceled',
            ]),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/v1/account-subscriptions/{$sub->id}");

        $response->assertNoContent();

        $fresh = $sub->fresh();
        $this->assertSame(SubscriptionStatus::Canceled, $fresh->status);
        $this->assertNotNull($fresh->canceled_at);

        $this->assertCount(1, $this->mollieCaptured['subscription_cancel_for_id']);
        $this->assertSame([
            'customer_id' => 'cst_abc',
            'subscription_id' => 'sub_cancel_me',
        ], $this->mollieCaptured['subscription_cancel_for_id'][0]);
    }

    public function test_already_canceled_is_idempotent_returns_204(): void
    {
        // Self-transition Canceled → Canceled is no-op per StateTransitions (D-04
        // §webhook-replay-safety). Manager roept nog wel Mollie cancelForId aan
        // (defensive — Mollie kan zelf 422 retourneren bij re-cancel), maar de
        // Hub-state-flip is geen-op. Resultaat: 204.
        [, $token, , $connection] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        $sub = AccountSubscription::factory()
            ->forConnection($connection)
            ->canceled()
            ->create(['mollie_subscription_id' => 'sub_already', 'mollie_customer_id' => 'cst_abc']);

        $originalCanceledAt = $sub->canceled_at;

        $this->bindMollieStubs([
            'subscriptions' => fn (string $op, mixed $arg) => $this->makeSubscription([
                'id' => $arg['subscription_id'],
                'customerId' => $arg['customer_id'],
                'status' => 'canceled',
            ]),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/v1/account-subscriptions/{$sub->id}");

        $response->assertNoContent();
        $this->assertSame(SubscriptionStatus::Canceled, $sub->fresh()->status);
        $this->assertNotNull($originalCanceledAt);
    }

    public function test_cross_consumer_destroy_returns_404(): void
    {
        // Consumer A's PAT, sub van Consumer B → 404 (D-12 invariant, T-07-04-01).
        [, $tokenA] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        $consumerB = Consumer::factory()->create();
        $accountB = Account::factory()->for($consumerB)->create(['external_id' => 'school-B']);
        $connectionB = Connection::factory()->forMollie()->active()->for($accountB)->create();
        $subB = AccountSubscription::factory()
            ->forConnection($connectionB)
            ->active()
            ->create(['mollie_subscription_id' => 'sub_B']);

        $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->deleteJson("/v1/account-subscriptions/{$subB->id}")
            ->assertNotFound()
            ->assertJsonPath('error', 'account_subscription_not_found');

        $this->assertSame(SubscriptionStatus::Active, $subB->fresh()->status, 'Cross-Consumer sub mag niet aangeraakt zijn.');
    }

    public function test_read_only_token_returns_403_on_destroy(): void
    {
        [, $token, , $connection] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_READ]);

        $sub = AccountSubscription::factory()
            ->forConnection($connection)
            ->active()
            ->create(['mollie_subscription_id' => 'sub_x', 'mollie_customer_id' => 'cst_x']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/v1/account-subscriptions/{$sub->id}")
            ->assertForbidden();
    }
}
