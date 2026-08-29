<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\DataForSeo\Http\Api;

use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Emeq\DataForSeoApi\Http\Request\BacklinksSummaryRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

final class BacklinksSummarySuccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MockClient::destroyGlobal();
    }

    public function test_valid_backlinks_summary_returns_expected_response(): void
    {
        MockClient::global([
            BacklinksSummaryRequest::class => MockResponse::make(
                body: [
                    'tasks_error' => 0,
                    'tasks' => [
                        [
                            'status_code' => 20000,
                            'status_message' => 'Ok.',
                            'result' => [
                                [
                                    'target' => 'example.com',
                                    'backlinks' => 142000,
                                    'referring_domains' => 1250,
                                    'referring_main_domains' => 870,
                                    'referring_ips' => 980,
                                    'referring_subnets' => 420,
                                    'rank' => 35,
                                    'info' => [
                                        'external_links' => 3890,
                                        'internal_links' => 156,
                                    ],
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
            ->getJson('/v1/dataforseo/backlinks-summary?target=example.com');

        $response->assertStatus(200)
            ->assertJson([
                'target' => 'example.com',
                'backlinks' => 142000,
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
