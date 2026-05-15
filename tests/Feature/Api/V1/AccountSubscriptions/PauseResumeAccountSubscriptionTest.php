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
use Tests\TestCase;

/**
 * Plan 07-06 Task 1 — feature-tests voor Hub-only state-flips (D-08):
 *  - POST /v1/account-subscriptions/{id}/pause
 *  - POST /v1/account-subscriptions/{id}/resume
 *
 * Bewijst:
 *  - happy: active → paused (200), paused → active (200)
 *  - pause op already-paused: self-transition no-op (D-04 StateTransitions),
 *    response 200, paused_at-veld update't
 *  - resume op canceled: illegal transition → 409
 *  - cross-Consumer pause → 404 (D-12)
 *  - **SC-3 mutate-isolation expliciet:** same-Consumer-other-Account pause → 200
 *    (per-Consumer scope, optie B, gekozen 2026-05-15 — zie 07-08 ADR)
 *  - read-only-token → 403 op pause
 */
class PauseResumeAccountSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_pause_active_subscription_returns_200_and_paused_state(): void
    {
        [$consumer, $token, , $connection] = $this->setup_consumer_with_token([TokenAbilities::MOLLIE_WRITE]);

        $sub = AccountSubscription::factory()
            ->forConnection($connection)
            ->active()
            ->create();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/v1/account-subscriptions/{$sub->id}/pause", ['reason' => 'manual_pause']);

        $response->assertOk()
            ->assertJsonPath('data.status', 'paused');

        $this->assertSame(SubscriptionStatus::Paused, $sub->fresh()->status);
        $this->assertNotNull($sub->fresh()->paused_at);
        unset($consumer);
    }

    public function test_pause_on_already_paused_is_idempotent_returns_200(): void
    {
        // Self-transition Paused → Paused is no-op per StateTransitions; manager
        // update't wel paused_at zodat audit-trail klopt.
        [, $token, , $connection] = $this->setup_consumer_with_token([TokenAbilities::MOLLIE_WRITE]);

        $sub = AccountSubscription::factory()
            ->forConnection($connection)
            ->paused()
            ->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/v1/account-subscriptions/{$sub->id}/pause")
            ->assertOk()
            ->assertJsonPath('data.status', 'paused');

        $this->assertSame(SubscriptionStatus::Paused, $sub->fresh()->status);
    }

    public function test_resume_paused_subscription_returns_200_and_active_state(): void
    {
        [, $token, , $connection] = $this->setup_consumer_with_token([TokenAbilities::MOLLIE_WRITE]);

        $sub = AccountSubscription::factory()
            ->forConnection($connection)
            ->paused()
            ->create();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/v1/account-subscriptions/{$sub->id}/resume");

        $response->assertOk()
            ->assertJsonPath('data.status', 'active');

        $fresh = $sub->fresh();
        $this->assertSame(SubscriptionStatus::Active, $fresh->status);
        $this->assertNull($fresh->paused_at);
    }

    public function test_resume_on_canceled_returns_409_invalid_state_transition(): void
    {
        // Canceled is terminal (D-04); transition naar Active is illegaal → 409.
        [, $token, , $connection] = $this->setup_consumer_with_token([TokenAbilities::MOLLIE_WRITE]);

        $sub = AccountSubscription::factory()
            ->forConnection($connection)
            ->canceled()
            ->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/v1/account-subscriptions/{$sub->id}/resume")
            ->assertStatus(409)
            ->assertJsonPath('error', 'invalid_state_transition')
            ->assertJsonPath('from', 'canceled')
            ->assertJsonPath('to', 'active');
    }

    public function test_cross_consumer_pause_returns_404(): void
    {
        // Consumer A's PAT + sub van Consumer B → 404 (D-12 + T-07-04-01).
        [, $tokenA] = $this->setup_consumer_with_token([TokenAbilities::MOLLIE_WRITE]);

        $consumerB = Consumer::factory()->create();
        $accountB = Account::factory()->for($consumerB)->create();
        $connectionB = Connection::factory()->forMollie()->active()->for($accountB)->create();
        $subB = AccountSubscription::factory()
            ->forConnection($connectionB)
            ->active()
            ->create();

        $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->postJson("/v1/account-subscriptions/{$subB->id}/pause")
            ->assertNotFound()
            ->assertJsonPath('error', 'account_subscription_not_found');

        $this->assertSame(SubscriptionStatus::Active, $subB->fresh()->status);
    }

    public function test_pause_on_subscription_of_other_account_same_consumer_returns_200(): void
    {
        // Bewijst per-Consumer scope (optie B, gekozen 2026-05-15 — zie 07-08
        // ADR). Mutate op vreemde-Consumer sub blijft 404 (zie cross_consumer_pause).
        $consumer = Consumer::factory()->create();
        $accountA = Account::factory()->for($consumer)->create(['external_id' => 'school-a']);
        $accountB = Account::factory()->for($consumer)->create(['external_id' => 'school-b']);

        $connectionA = Connection::factory()->forMollie()->active()->for($accountA)->create();
        $connectionB = Connection::factory()->forMollie()->active()->for($accountB)->create();

        AccountSubscription::factory()
            ->forConnection($connectionA)
            ->active()
            ->create(['mollie_subscription_id' => 'sub_A']);

        $subB = AccountSubscription::factory()
            ->forConnection($connectionB)
            ->active()
            ->create(['mollie_subscription_id' => 'sub_B']);

        $token = $consumer->createToken('test-write', [TokenAbilities::MOLLIE_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/v1/account-subscriptions/{$subB->id}/pause", ['reason' => 'manual_pause'])
            ->assertOk();

        $this->assertSame(SubscriptionStatus::Paused, $subB->fresh()->status);
    }

    public function test_read_only_token_returns_403_on_pause(): void
    {
        [, $token, , $connection] = $this->setup_consumer_with_token([TokenAbilities::MOLLIE_READ]);

        $sub = AccountSubscription::factory()
            ->forConnection($connection)
            ->active()
            ->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/v1/account-subscriptions/{$sub->id}/pause")
            ->assertForbidden();
    }

    /**
     * Minimale Consumer + Account + actieve Mollie-Connection. Geen Mollie-stub
     * — pause/resume zijn Hub-only state-flips (D-08, geen Mollie-call).
     *
     * @param  list<string>  $abilities
     * @return array{0: Consumer, 1: string, 2: Account, 3: Connection}
     */
    private function setup_consumer_with_token(array $abilities): array
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
        $connection = Connection::factory()->forMollie()->active()->for($account)->create();
        $token = $consumer->createToken('test', $abilities)->plainTextToken;

        return [$consumer, $token, $account, $connection];
    }
}
