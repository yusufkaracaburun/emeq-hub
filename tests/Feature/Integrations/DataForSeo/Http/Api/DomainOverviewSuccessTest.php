<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\DataForSeo\Http\Api;

use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Emeq\DataForSeoApi\Http\Request\DomainOverviewRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

final class DomainOverviewSuccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MockClient::destroyGlobal();
    }

    public function test_valid_domain_overview_returns_expected_response(): void
    {
        MockClient::global([
            DomainOverviewRequest::class => MockResponse::make(
                body: [
                    'tasks' => [
                        [
                            'result' => [
                                [
                                    'domain' => 'example.com',
                                    'metrics' => [
                                        'organic' => [
                                            'count' => 3890,
                                            'etv' => 12450,
                                        ],
                                    ],
                                    'backlinks_count' => 142000,
                                    'referring_domains' => 1250,
                                ],
                            ],
                        ],
                    ],
                ],
                status: 200,
            ),
        ]);

        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::DATAFORSEO_READ]);
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
        Connection::factory()->forDataForSeo()->for($account)->create();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school-A')
            ->getJson('/v1/dataforseo/domain-overview?domain=example.com');

        $response->assertStatus(200)
            ->assertJson([
                'domain' => 'example.com',
            ]);
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
