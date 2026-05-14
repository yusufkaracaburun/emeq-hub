<?php

namespace Tests\Feature\Api\V1;

use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StoreConnectionTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_KEY = 'CK-test-clientkey-1234567890';

    private const SUBSCRIPTION_KEY = 'SK-test-subscription-1234567890';

    public function test_creates_snelstart_connection_with_encrypted_credentials_and_returns_fingerprint_only(): void
    {
        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::SNELSTART_WRITE]);
        $account = Account::factory()->for($consumer)->create();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/connections', [
                'account_id' => $account->id,
                'provider' => 'snelstart',
                'credentials' => [
                    'client_key' => self::CLIENT_KEY,
                    'subscription_key' => self::SUBSCRIPTION_KEY,
                    'subscription_id' => '00000000-0000-4000-8000-000000000001',
                ],
            ]);

        $response->assertCreated();

        $expectedKeys = ['id', 'account_id', 'provider', 'status', 'fingerprint', 'revoked_at', 'created_at'];
        $forbiddenKeys = ['client_key', 'subscription_key', 'subscription_id', 'access_token', 'refresh_token'];

        $data = $response->json('data');
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $data, "Missing key {$key} in response");
        }
        foreach ($forbiddenKeys as $key) {
            $this->assertArrayNotHasKey($key, $data, "Raw credential key {$key} leaked in response");
        }

        $this->assertSame('snelstart', $data['provider']);
        $this->assertSame('active', $data['status']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{12}$/', (string) $data['fingerprint']);

        $rawClientKey = DB::table('connections')->where('id', $data['id'])->value('client_key');
        $this->assertNotSame(self::CLIENT_KEY, $rawClientKey);
        $this->assertNotEmpty($rawClientKey);
    }

    public function test_response_never_contains_raw_credentials(): void
    {
        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::SNELSTART_WRITE]);
        $account = Account::factory()->for($consumer)->create();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/connections', [
                'account_id' => $account->id,
                'provider' => 'snelstart',
                'credentials' => [
                    'client_key' => self::CLIENT_KEY,
                    'subscription_key' => self::SUBSCRIPTION_KEY,
                ],
            ]);

        $response->assertCreated();
        $response->assertDontSeeText(self::CLIENT_KEY);
        $response->assertDontSeeText(self::SUBSCRIPTION_KEY);
    }

    public function test_consumer_manage_accounts_ability_can_create_connection(): void
    {
        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::CONSUMER_MANAGE_ACCOUNTS]);
        $account = Account::factory()->for($consumer)->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/connections', [
                'account_id' => $account->id,
                'provider' => 'snelstart',
                'credentials' => [
                    'client_key' => self::CLIENT_KEY,
                    'subscription_key' => self::SUBSCRIPTION_KEY,
                ],
            ])
            ->assertCreated();
    }

    public function test_token_without_required_ability_returns_403(): void
    {
        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::SNELSTART_READ]);
        $account = Account::factory()->for($consumer)->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/connections', [
                'account_id' => $account->id,
                'provider' => 'snelstart',
                'credentials' => [
                    'client_key' => self::CLIENT_KEY,
                    'subscription_key' => self::SUBSCRIPTION_KEY,
                ],
            ])
            ->assertForbidden();
    }

    public function test_cross_consumer_account_id_returns_422_via_rule_exists(): void
    {
        $consumerA = Consumer::factory()->create();
        $accountA = Account::factory()->for($consumerA)->create();

        [, $tokenB] = $this->consumerWithToken([TokenAbilities::SNELSTART_WRITE]);

        $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->postJson('/v1/connections', [
                'account_id' => $accountA->id,
                'provider' => 'snelstart',
                'credentials' => [
                    'client_key' => self::CLIENT_KEY,
                    'subscription_key' => self::SUBSCRIPTION_KEY,
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['account_id']);
    }

    public function test_duplicate_active_snelstart_connection_for_same_account_returns_409(): void
    {
        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::SNELSTART_WRITE]);
        $account = Account::factory()->for($consumer)->create();
        Connection::factory()->forSnelstart()->for($account)->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/connections', [
                'account_id' => $account->id,
                'provider' => 'snelstart',
                'credentials' => [
                    'client_key' => self::CLIENT_KEY,
                    'subscription_key' => self::SUBSCRIPTION_KEY,
                ],
            ])
            ->assertStatus(409)
            ->assertJsonPath('error', 'connection_exists');
    }

    public function test_revoked_connection_does_not_block_new_connection_creation(): void
    {
        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::SNELSTART_WRITE]);
        $account = Account::factory()->for($consumer)->create();
        Connection::factory()->forSnelstart()->for($account)->create(['revoked_at' => now()]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/connections', [
                'account_id' => $account->id,
                'provider' => 'snelstart',
                'credentials' => [
                    'client_key' => self::CLIENT_KEY,
                    'subscription_key' => self::SUBSCRIPTION_KEY,
                ],
            ])
            ->assertCreated();
    }

    public function test_validation_error_for_missing_credentials_returns_422(): void
    {
        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::SNELSTART_WRITE]);
        $account = Account::factory()->for($consumer)->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/connections', [
                'account_id' => $account->id,
                'provider' => 'snelstart',
                'credentials' => [
                    'subscription_key' => self::SUBSCRIPTION_KEY,
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['credentials.client_key']);
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
