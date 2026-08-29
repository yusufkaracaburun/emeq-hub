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

final class DomainOverviewTaskFailureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MockClient::destroyGlobal();
    }

    public function test_task_level_failure_does_not_return_a_silent_200(): void
    {
        MockClient::global([
            DomainOverviewRequest::class => MockResponse::make(
                body: [
                    'tasks_error' => 1,
                    'tasks' => [
                        [
                            'status_code' => 40501,
                            'status_message' => 'Invalid Field: language_code.',
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

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school-A')
            ->getJson('/v1/dataforseo/domain-overview?domain=example.com');

        $response->assertStatus(502)
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
