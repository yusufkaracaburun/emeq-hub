<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Mollie\Http\AccountSubscriptions;

use App\Billing\Account\SubscriptionStatus;
use App\Models\Account;
use App\Models\AccountSubscription;
use App\Models\Connection;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

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
