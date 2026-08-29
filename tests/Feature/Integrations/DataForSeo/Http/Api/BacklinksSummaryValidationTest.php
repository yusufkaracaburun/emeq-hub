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

final class BacklinksSummaryValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MockClient::destroyGlobal();
    }

    public function test_missing_target_returns_422(): void
    {
        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::DATAFORSEO_READ]);
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
        Connection::factory()->forDataForSeo()->for($account)->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school-A')
            ->getJson('/v1/dataforseo/backlinks-summary')
            ->assertStatus(422)
            ->assertJsonPath('error', 'missing_target');
    }

    public function test_task_level_failure_returns_502_upstream_error(): void
    {
        MockClient::global([
            BacklinksSummaryRequest::class => MockResponse::make(
                body: [
                    'tasks_error' => 1,
                    'tasks' => [
                        [
                            'status_code' => 40501,
                            'status_message' => 'Invalid field.',
                            'result' => [],
                        ],
                    ],
                ],
                status: 200,
            ),
        ]);

        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::DATAFORSEO_READ]);
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
        Connection::factory()->forDataForSeo()->for($account)->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school-A')
            ->getJson('/v1/dataforseo/backlinks-summary?target=example.com')
            ->assertStatus(502)
            ->assertJson([
                'error' => 'upstream_error',
                'upstream_status' => 40501,
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
