<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\AccountSubscriptions;

use App\Models\Account;
use App\Models\AccountSubscription;
use App\Models\Connection;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListAccountSubscriptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_with_account_external_id_returns_only_own_account_subs(): void
    {
        $consumer = Consumer::factory()->create();
        $accountA = Account::factory()->for($consumer)->create(['external_id' => 'school-a']);
        $accountB = Account::factory()->for($consumer)->create(['external_id' => 'school-b']);
        $connectionA = Connection::factory()->forMollie()->active()->for($accountA)->create();
        $connectionB = Connection::factory()->forMollie()->active()->for($accountB)->create();

        AccountSubscription::factory()->forConnection($connectionA)->active()->create();
        AccountSubscription::factory()->forConnection($connectionB)->active()->create();

        $token = $consumer->createToken('test', [TokenAbilities::MOLLIE_READ])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/account-subscriptions?account_external_id=school-a');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($accountA->id, $response->json('data.0.id'))
            ?: $this->assertNotNull($response->json('data.0.id'));
        $returnedSub = AccountSubscription::query()->where('account_id', $accountA->id)->firstOrFail();
        $this->assertSame($returnedSub->id, $response->json('data.0.id'));
    }

    public function test_list_response_is_paginated(): void
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school-a']);
        $connection = Connection::factory()->forMollie()->active()->for($account)->create();
        AccountSubscription::factory()->forConnection($connection)->active()->create();

        $token = $consumer->createToken('test', [TokenAbilities::MOLLIE_READ])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/account-subscriptions?account_external_id=school-a');

        $response->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta'])
            ->assertJsonPath('meta.per_page', 25);
    }

    public function test_list_without_account_external_id_returns_422(): void
    {
        $consumer = Consumer::factory()->create();
        $token = $consumer->createToken('test', [TokenAbilities::MOLLIE_READ])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/account-subscriptions')
            ->assertStatus(422)
            ->assertJsonPath('error', 'account_external_id_required');
    }

    public function test_list_with_other_consumer_account_external_id_returns_empty_list(): void
    {
        $consumerA = Consumer::factory()->create();
        $consumerB = Consumer::factory()->create();
        $accountB = Account::factory()->for($consumerB)->create(['external_id' => 'school-secret']);
        $connectionB = Connection::factory()->forMollie()->active()->for($accountB)->create();
        AccountSubscription::factory()->forConnection($connectionB)->active()->create();

        $tokenA = $consumerA->createToken('test', [TokenAbilities::MOLLIE_READ])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->getJson('/v1/account-subscriptions?account_external_id=school-secret')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_list_returns_subs_sorted_by_latest(): void
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school-a']);
        $connection = Connection::factory()->forMollie()->active()->for($account)->create();

        $oldest = AccountSubscription::factory()->forConnection($connection)->active()->create();
        $oldest->created_at = now()->subDays(5);
        $oldest->save();

        $middle = AccountSubscription::factory()->forConnection($connection)->paused()->create();
        $middle->created_at = now()->subDay();
        $middle->save();

        $newest = AccountSubscription::factory()->forConnection($connection)->canceled()->create();
        $newest->created_at = now();
        $newest->save();

        $token = $consumer->createToken('test', [TokenAbilities::MOLLIE_READ])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/account-subscriptions?account_external_id=school-a');

        $response->assertOk()->assertJsonCount(3, 'data');
        $ids = array_column($response->json('data'), 'id');
        $this->assertSame([$newest->id, $middle->id, $oldest->id], $ids, 'Sortering moet desc op created_at zijn.');
    }

    public function test_read_only_token_can_list(): void
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school-a']);
        Connection::factory()->forMollie()->active()->for($account)->create();

        $token = $consumer->createToken('test', [TokenAbilities::MOLLIE_READ])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/account-subscriptions?account_external_id=school-a')
            ->assertOk();
    }

    public function test_write_token_can_access_read_routes_returns_200_when_ability_includes_mollie_write(): void
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school-a']);
        Connection::factory()->forMollie()->active()->for($account)->create();

        $token = $consumer->createToken('write-only', [TokenAbilities::MOLLIE_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/account-subscriptions?account_external_id=school-a')
            ->assertOk();
    }
}
