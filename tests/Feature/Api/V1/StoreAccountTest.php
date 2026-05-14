<?php

namespace Tests\Feature\Api\V1;

use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_account_with_snelstart_write_ability_returns_201_and_resource_shape(): void
    {
        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::SNELSTART_WRITE]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/accounts', [
                'external_id' => 'school-007',
                'display_name' => 'School 7',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.external_id', 'school-007')
            ->assertJsonPath('data.display_name', 'School 7');

        $this->assertIsInt($response->json('data.id'));
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            (string) $response->json('data.created_at'),
        );
        $this->assertDatabaseHas('accounts', [
            'consumer_id' => $consumer->id,
            'external_id' => 'school-007',
        ]);
    }

    public function test_consumer_manage_accounts_ability_can_also_create_account(): void
    {
        [, $token] = $this->consumerWithToken([TokenAbilities::CONSUMER_MANAGE_ACCOUNTS]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/accounts', [
                'external_id' => 'ext-1',
                'display_name' => 'Test',
            ])
            ->assertCreated();
    }

    public function test_mollie_write_ability_can_also_create_account(): void
    {
        [, $token] = $this->consumerWithToken([TokenAbilities::MOLLIE_WRITE]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/accounts', [
                'external_id' => 'ext-2',
                'display_name' => 'Test',
            ])
            ->assertCreated();
    }

    public function test_token_without_required_ability_returns_403(): void
    {
        [, $token] = $this->consumerWithToken([TokenAbilities::MOLLIE_READ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/accounts', [
                'external_id' => 'ext-3',
                'display_name' => 'Test',
            ])
            ->assertForbidden();
    }

    public function test_validation_error_returns_422_with_errors_object(): void
    {
        [, $token] = $this->consumerWithToken([TokenAbilities::SNELSTART_WRITE]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/accounts', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['external_id']);
    }

    public function test_duplicate_external_id_for_same_consumer_returns_409_with_account_exists(): void
    {
        [, $token] = $this->consumerWithToken([TokenAbilities::SNELSTART_WRITE]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/accounts', [
                'external_id' => 'dup-1',
                'display_name' => 'First',
            ])
            ->assertCreated();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/accounts', [
                'external_id' => 'dup-1',
                'display_name' => 'Second',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error', 'account_exists');
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->postJson('/v1/accounts', [
            'external_id' => 'no-auth',
        ])->assertUnauthorized();
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
