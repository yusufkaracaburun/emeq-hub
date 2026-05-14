<?php

namespace Tests\Feature\Api\V1\Mollie;

use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Mollie\MollieConnectionContext;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MolliePassThroughResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['auth:sanctum', 'resolve.mollie.account'])
            ->get('/v1/__test__/mollie-resolution', function (Request $request) {
                return response()->json([
                    'account_external_id' => $request->attributes->get('mollie_account')?->external_id,
                    'connection_id' => $request->attributes->get('mollie_connection')?->getKey(),
                    'context_has' => app(MollieConnectionContext::class)->has(),
                ]);
            });
    }

    public function test_missing_x_account_id_header_returns_400_missing_account_header(): void
    {
        [, $token] = $this->setupConsumerWithToken([TokenAbilities::MOLLIE_READ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/__test__/mollie-resolution')
            ->assertStatus(400)
            ->assertJsonPath('error', 'missing_account_header');
    }

    public function test_unknown_x_account_id_returns_404_account_not_found(): void
    {
        [, $token] = $this->setupConsumerWithToken([TokenAbilities::MOLLIE_READ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'nonexistent-school')
            ->getJson('/v1/__test__/mollie-resolution')
            ->assertStatus(404)
            ->assertJsonPath('error', 'account_not_found');
    }

    public function test_other_consumers_account_id_returns_404_not_403(): void
    {
        [, $tokenA] = $this->setupConsumerWithToken([TokenAbilities::MOLLIE_READ]);
        $consumerB = Consumer::factory()->create();
        Account::factory()->for($consumerB)->create(['external_id' => 'school-B']);

        // Consumer A's PAT met Consumer B's account-external-id
        $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->withHeader('X-Account-Id', 'school-B')
            ->getJson('/v1/__test__/mollie-resolution')
            ->assertStatus(404)
            ->assertJsonPath('error', 'account_not_found'); // NIET 403 — info-disclosure-policy
    }

    public function test_account_without_active_mollie_connection_returns_404_connection_not_found(): void
    {
        [$consumer, $token] = $this->setupConsumerWithToken([TokenAbilities::MOLLIE_READ]);
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
        Connection::factory()->forMollie()->active()->for($account)->create([
            'revoked_at' => now()->subMinute(),
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school-A')
            ->getJson('/v1/__test__/mollie-resolution')
            ->assertStatus(404)
            ->assertJsonPath('error', 'connection_not_found');
    }

    public function test_account_with_only_snelstart_connection_returns_404_connection_not_found(): void
    {
        [$consumer, $token] = $this->setupConsumerWithToken([TokenAbilities::MOLLIE_READ]);
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
        Connection::factory()->forSnelstart()->for($account)->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school-A')
            ->getJson('/v1/__test__/mollie-resolution')
            ->assertStatus(404)
            ->assertJsonPath('error', 'connection_not_found');
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/v1/__test__/mollie-resolution')->assertStatus(401);
    }

    public function test_happy_path_sets_attributes_and_mollie_connection_context(): void
    {
        [$consumer, $token] = $this->setupConsumerWithToken([TokenAbilities::MOLLIE_READ]);
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
        $connection = Connection::factory()->forMollie()->active()->for($account)->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school-A')
            ->getJson('/v1/__test__/mollie-resolution')
            ->assertOk()
            ->assertJsonPath('account_external_id', 'school-A')
            ->assertJsonPath('connection_id', $connection->getKey())
            ->assertJsonPath('context_has', true);
    }

    /**
     * @param  list<string>  $abilities
     * @return array{0: Consumer, 1: string}
     */
    private function setupConsumerWithToken(array $abilities): array
    {
        $consumer = Consumer::factory()->create();
        $plainToken = $consumer->createToken('test', $abilities)->plainTextToken;

        return [$consumer, $plainToken];
    }
}
