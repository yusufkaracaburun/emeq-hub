<?php

namespace Tests\Feature\Api\V1\Exact;

use App\Models\Connection;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Emeq\ExactApi\Auth\RefreshTokenRequest;
use Emeq\ExactApi\Http\Request\RawExactRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

class PassThroughTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.exact.client_id' => 'app_test_id',
            'services.exact.client_secret' => 'app_test_secret',
            'services.exact.redirect_uri' => 'https://hub.test/v1/oauth/exact/callback',
            'services.exact.auth_base_url' => 'https://start.exactonline.nl',
            'services.exact.api_base_url' => 'https://start.exactonline.nl',
        ]);
    }

    protected function tearDown(): void
    {
        MockClient::destroyGlobal();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $connectionState
     * @return array{0: Consumer, 1: Connection}
     */
    private function consumerWithExactConnection(array $connectionState = []): array
    {
        $consumer = Consumer::factory()->create();
        $account = $consumer->accounts()->create([
            'external_id' => 'school1',
            'display_name' => 'School 1',
        ]);

        $connection = Connection::factory()->forExact()->create(array_merge([
            'account_id' => $account->id,
            'status' => 'active',
            'expires_at' => now()->addSeconds(600),
        ], $connectionState));

        return [$consumer, $connection];
    }

    public function test_pass_through_forwards_get_to_exact(): void
    {
        MockClient::global([
            RawExactRequest::class => MockResponse::make(['d' => ['results' => [['ID' => 'acc-1']]]], 200),
        ]);

        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_READ])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->getJson('/v1/exact/crm/Accounts')
            ->assertOk()
            ->assertJsonPath('d.results.0.ID', 'acc-1');

        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'exact',
            'method' => 'GET',
            'path' => '/crm/Accounts',
            'status' => 200,
        ]);
    }

    public function test_pass_through_auto_refreshes_rotating_token_when_expired(): void
    {
        MockClient::global([
            RefreshTokenRequest::class => MockResponse::make([
                'access_token' => 'acc_new',
                'token_type' => 'bearer',
                'expires_in' => '600',
                'refresh_token' => 'ref_new',
            ], 200),
            RawExactRequest::class => MockResponse::make(['d' => ['results' => []]], 200),
        ]);

        [$consumer, $connection] = $this->consumerWithExactConnection([
            'expires_at' => now()->subSecond(),
            'access_token' => 'acc_old',
            'refresh_token' => 'ref_old',
        ]);
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_READ])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->getJson('/v1/exact/crm/Accounts')
            ->assertOk();

        $connection->refresh();
        $this->assertSame('acc_new', $connection->access_token);
        $this->assertSame('ref_new', $connection->refresh_token);
    }

    public function test_pass_through_without_account_header_returns_400(): void
    {
        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_READ])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/exact/crm/Accounts')
            ->assertStatus(400)
            ->assertJson(['error' => 'missing_account_header']);
    }

    public function test_pass_through_without_active_connection_returns_404(): void
    {
        $consumer = Consumer::factory()->create();
        $consumer->accounts()->create(['external_id' => 'school1', 'display_name' => 'S1']);
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_READ])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->getJson('/v1/exact/crm/Accounts')
            ->assertStatus(404)
            ->assertJson(['error' => 'connection_not_found']);
    }

    public function test_pass_through_post_without_write_ability_returns_403(): void
    {
        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_READ])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->postJson('/v1/exact/crm/Accounts', ['Name' => 'Acme'])
            ->assertStatus(403)
            ->assertJson(['error' => 'insufficient_ability']);
    }

    public function test_pass_through_forwards_rate_limit_headers_on_success(): void
    {
        MockClient::global([
            RawExactRequest::class => MockResponse::make(['d' => ['results' => []]], 200, [
                'X-RateLimit-Remaining' => '58',
                'X-RateLimit-Reset' => '1718700000000',
                'X-RateLimit-Minutely-Remaining' => '9',
            ]),
        ]);

        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_READ])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->getJson('/v1/exact/crm/Accounts')
            ->assertOk()
            ->assertHeader('X-RateLimit-Remaining', '58')
            ->assertHeader('X-RateLimit-Reset', '1718700000000')
            ->assertHeader('X-RateLimit-Minutely-Remaining', '9');
    }

    public function test_pass_through_surfaces_exact_server_error_and_audits_5xx(): void
    {
        MockClient::global([
            RawExactRequest::class => MockResponse::make(
                '{"error":{"message":{"value":"Can\'t delete: used in journal entry"}}}',
                500,
            ),
        ]);

        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->deleteJson('/v1/exact/crm/Accounts/guid')
            ->assertStatus(502)
            ->assertJsonPath('message', "Can't delete: used in journal entry")
            ->assertJsonPath('upstream_status', 500);

        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'exact',
            'status' => 502,
            'upstream_error' => 'exact_5xx',
        ]);
    }

    public function test_pass_through_masks_403_but_distinguishes_it_in_audit(): void
    {
        MockClient::global([
            RawExactRequest::class => MockResponse::make('forbidden', 403),
        ]);

        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_READ])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->getJson('/v1/exact/crm/Accounts')
            ->assertStatus(502)
            ->assertJsonPath('upstream_status', 403);

        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'exact',
            'status' => 502,
            'upstream_error' => 'exact_forbidden',
        ]);
    }

    public function test_pass_through_without_division_returns_409(): void
    {
        MockClient::global([
            RawExactRequest::class => MockResponse::make([], 200),
        ]);

        [$consumer] = $this->consumerWithExactConnection(['administratie_id' => null]);
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_READ])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->getJson('/v1/exact/crm/Accounts')
            ->assertStatus(409)
            ->assertJson(['error' => 'connection_incomplete']);
    }
}
