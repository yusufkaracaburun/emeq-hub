<?php

namespace Tests\Feature\Integrations\Exact\Http\Api;

use App\Models\Connection;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Emeq\ExactApi\Http\Request\Read\GetRelations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

class RelationsTest extends TestCase
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

    /** @return array{0: Consumer, 1: Connection} */
    private function consumerWithExactConnection(): array
    {
        $consumer = Consumer::factory()->create();
        $account = $consumer->accounts()->create([
            'external_id' => 'school1',
            'display_name' => 'School 1',
        ]);

        $connection = Connection::factory()->forExact()->create([
            'account_id' => $account->id,
            'status' => 'active',
            'expires_at' => now()->addSeconds(600),
        ]);

        return [$consumer, $connection];
    }

    public function test_relations_forwards_to_crm_accounts_endpoint(): void
    {
        MockClient::global([
            GetRelations::class => MockResponse::make([
                'd' => ['results' => [['ID' => 'acc-1', 'Name' => 'Klant BV', 'Code' => '                 1']]],
            ], 200),
        ]);

        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_READ])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->getJson('/v1/exact/relations?$top=1')
            ->assertOk()
            ->assertJsonPath('d.results.0.Name', 'Klant BV');

        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'exact',
            'method' => 'GET',
            'path' => '/crm/Accounts',
            'status' => 200,
        ]);
    }

    public function test_relations_requires_exact_ability(): void
    {
        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::MOLLIE_READ])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->getJson('/v1/exact/relations')
            ->assertForbidden();
    }
}
