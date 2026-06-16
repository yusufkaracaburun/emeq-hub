<?php

namespace Tests\Feature\Api\V1\Exact;

use App\Models\Connection;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Emeq\ExactApi\Http\Request\RawExactRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

class VatCodesTest extends TestCase
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
     * @return array{0: Consumer, 1: Connection}
     */
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

    public function test_vat_codes_forwards_to_vat_vatcodes_endpoint(): void
    {
        MockClient::global([
            RawExactRequest::class => MockResponse::make([
                'd' => ['results' => [['ID' => 'vat-1', 'Code' => '4', 'Description' => 'Hoog', 'Percentage' => 21]]],
            ], 200),
        ]);

        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_READ])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->getJson('/v1/exact/vat-codes?$top=1')
            ->assertOk()
            ->assertJsonPath('d.results.0.Code', '4');

        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'exact',
            'method' => 'GET',
            'path' => '/vat/VATCodes',
            'status' => 200,
        ]);
    }

    public function test_vat_codes_requires_exact_ability(): void
    {
        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::MOLLIE_READ])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->getJson('/v1/exact/vat-codes')
            ->assertForbidden();
    }
}
