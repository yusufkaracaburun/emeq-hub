<?php

namespace Tests\Feature\Api\V1;

use App\Integrations\Exact\Jobs\DeleteExactWebhookSubscriptionsJob;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class DestroyConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_consumer_can_revoke_own_connection_returns_204_and_sets_revoked_at(): void
    {
        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::CONSUMER_MANAGE_ACCOUNTS]);
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()->forSnelstart()->for($account)->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/v1/connections/{$connection->id}")
            ->assertNoContent();

        $this->assertNotNull($connection->fresh()->revoked_at);
    }

    public function test_public_id_from_the_connect_flow_revokes(): void
    {
        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::CONSUMER_MANAGE_ACCOUNTS]);
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()->forSnelstart()->for($account)->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/v1/connections/{$connection->public_id}")
            ->assertNoContent();

        $this->assertNotNull($connection->fresh()->revoked_at);
    }

    public function test_unknown_connection_id_returns_404_not_500_on_delete(): void
    {
        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::CONSUMER_MANAGE_ACCOUNTS]);
        Account::factory()->for($consumer)->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/v1/connections/con_DOESNOTEXIST')
            ->assertNotFound()
            ->assertJsonPath('error', 'connection_not_found');
    }

    public function test_other_consumers_connection_returns_404_on_delete(): void
    {
        $consumerA = Consumer::factory()->create();
        $accountA = Account::factory()->for($consumerA)->create();
        $connectionA = Connection::factory()->forSnelstart()->for($accountA)->create();

        [, $tokenB] = $this->consumerWithToken([TokenAbilities::CONSUMER_MANAGE_ACCOUNTS]);

        $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->deleteJson("/v1/connections/{$connectionA->id}")
            ->assertNotFound()
            ->assertJsonPath('error', 'connection_not_found');
    }

    public function test_already_revoked_connection_returns_404_on_second_delete(): void
    {
        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::CONSUMER_MANAGE_ACCOUNTS]);
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()
            ->forSnelstart()
            ->for($account)
            ->create(['revoked_at' => now()]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/v1/connections/{$connection->id}")
            ->assertNotFound()
            ->assertJsonPath('error', 'connection_not_found');
    }

    public function test_token_without_required_ability_returns_403(): void
    {
        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::SNELSTART_READ]);
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()->forSnelstart()->for($account)->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/v1/connections/{$connection->id}")
            ->assertForbidden();
    }

    public function test_exact_write_token_revoke_tears_down_provider_and_marks_revoked(): void
    {
        Queue::fake();

        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::EXACT_WRITE]);
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()->forExact()->for($account)->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/v1/connections/{$connection->id}")
            ->assertNoContent();

        Queue::assertPushed(DeleteExactWebhookSubscriptionsJob::class);

        $fresh = $connection->fresh();
        $this->assertSame('revoked', $fresh->status);
        $this->assertNotNull($fresh->revoked_at);
    }

    public function test_disabled_provider_still_revokes_locally_without_teardown(): void
    {
        Queue::fake();
        Feature::define('provider-exact-enabled', fn () => false);

        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::EXACT_WRITE]);
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()->forExact()->for($account)->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/v1/connections/{$connection->id}")
            ->assertNoContent();

        Queue::assertNotPushed(DeleteExactWebhookSubscriptionsJob::class);

        $fresh = $connection->fresh();
        $this->assertSame('revoked', $fresh->status);
        $this->assertNotNull($fresh->revoked_at);
    }

    public function test_integrations_manage_token_can_revoke_any_connection(): void
    {
        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::INTEGRATIONS_MANAGE]);
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()->forSnelstart()->for($account)->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/v1/connections/{$connection->id}")
            ->assertNoContent();

        $this->assertNotNull($connection->fresh()->revoked_at);
    }

    public function test_exact_read_only_token_cannot_revoke_returns_403(): void
    {
        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::EXACT_READ]);
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()->forExact()->for($account)->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/v1/connections/{$connection->id}")
            ->assertForbidden();
    }

    public function test_revoked_at_persists_after_delete_call(): void
    {
        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::CONSUMER_MANAGE_ACCOUNTS]);
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()->forSnelstart()->for($account)->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/v1/connections/{$connection->id}")
            ->assertNoContent();

        $revokedAt = DB::table('connections')->where('id', $connection->id)->value('revoked_at');
        $this->assertNotNull($revokedAt);
    }

    /**
     * @param  list<string>  $abilities
     * @return array{0: Consumer, 1: string}
     */
    private function consumerWithToken(array $abilities): array
    {
        $consumer = Consumer::factory()->create();
        $token = $consumer->createToken('test', $abilities)->plainTextToken;

        return [$consumer, $token];
    }
}
