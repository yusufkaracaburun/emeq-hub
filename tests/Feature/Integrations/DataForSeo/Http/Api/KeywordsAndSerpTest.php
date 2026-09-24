<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\DataForSeo\Http\Api;

use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Emeq\DataForSeoApi\Http\Request\RelatedKeywordsRequest;
use Emeq\DataForSeoApi\Http\Request\SearchVolumeRequest;
use Emeq\DataForSeoApi\Http\Request\SerpOrganicRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

final class KeywordsAndSerpTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        MockClient::destroyGlobal();

        $consumer = Consumer::factory()->create();
        $this->token = $consumer->createToken('test', [TokenAbilities::DATAFORSEO_READ])->plainTextToken;
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
        Connection::factory()->forDataForSeo()->for($account)->create();
    }

    public function test_search_volume_posts_keywords_and_returns_all_rows(): void
    {
        $rows = [
            ['keyword' => 'theorie examen', 'search_volume' => 22200],
            ['keyword' => 'auto theorie', 'search_volume' => 9900],
        ];
        $mockClient = MockClient::global([SearchVolumeRequest::class => $this->taskEnvelope($rows)]);

        $this->api()
            ->postJson('/v1/dataforseo/search-volume', ['keywords' => ['theorie examen', 'auto theorie']])
            ->assertOk()
            ->assertExactJson($rows);

        $mockClient->assertSent(fn (SearchVolumeRequest $request): bool => $request->body()->all() === [[
            'keywords' => ['theorie examen', 'auto theorie'],
            'location_code' => 2528,
            'language_code' => 'nl',
        ]]);
    }

    public function test_search_volume_forwards_a_numeric_string_location_code_as_integer(): void
    {
        $mockClient = MockClient::global([SearchVolumeRequest::class => $this->taskEnvelope([])]);

        $this->api()
            ->postJson('/v1/dataforseo/search-volume', ['keywords' => ['x'], 'location_code' => '2056'])
            ->assertOk();

        $mockClient->assertSent(fn (SearchVolumeRequest $request): bool => $request->body()->all()[0]['location_code'] === 2056);
    }

    public function test_search_volume_rejects_more_than_1000_keywords_and_overlong_keywords(): void
    {
        $this->api()
            ->postJson('/v1/dataforseo/search-volume', ['keywords' => array_fill(0, 1001, 'x')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('keywords');

        $this->api()
            ->postJson('/v1/dataforseo/search-volume', ['keywords' => [str_repeat('a', 81)]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('keywords.0');
    }

    public function test_serp_organic_forwards_typed_options_and_returns_first_result(): void
    {
        $serp = ['keyword' => 'theorie examen', 'items' => [['type' => 'organic', 'rank_absolute' => 1]]];
        $mockClient = MockClient::global([SerpOrganicRequest::class => $this->taskEnvelope([$serp])]);

        $this->api()
            ->getJson('/v1/dataforseo/serp-organic?keyword=theorie+examen&depth=20&device=mobile&load_async_ai_overview=1')
            ->assertOk()
            ->assertExactJson($serp);

        $mockClient->assertSent(fn (SerpOrganicRequest $request): bool => $request->body()->all() === [[
            'keyword' => 'theorie examen',
            'location_code' => 2528,
            'language_code' => 'nl',
            'depth' => 20,
            'device' => 'mobile',
            'load_async_ai_overview' => true,
        ]]);
    }

    public function test_serp_organic_rejects_depth_above_partner_maximum(): void
    {
        $this->api()
            ->getJson('/v1/dataforseo/serp-organic?keyword=x&depth=201')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('depth');
    }

    public function test_related_keywords_forwards_depth_and_limit(): void
    {
        $related = ['seed_keyword' => 'theorie examen', 'items' => [['keyword_data' => ['keyword' => 'cbr theorie']]]];
        $mockClient = MockClient::global([RelatedKeywordsRequest::class => $this->taskEnvelope([$related])]);

        $this->api()
            ->getJson('/v1/dataforseo/related-keywords?keyword=theorie+examen&depth=2&limit=50')
            ->assertOk()
            ->assertExactJson($related);

        $mockClient->assertSent(fn (RelatedKeywordsRequest $request): bool => $request->body()->all() === [[
            'keyword' => 'theorie examen',
            'location_code' => 2528,
            'language_code' => 'nl',
            'depth' => 2,
            'limit' => 50,
        ]]);
    }

    public function test_related_keywords_requires_keyword(): void
    {
        $this->api()
            ->getJson('/v1/dataforseo/related-keywords')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('keyword');
    }

    public function test_task_level_failure_maps_to_503(): void
    {
        MockClient::global([SerpOrganicRequest::class => $this->taskEnvelope([], 40200, 'Payment Required.')]);

        $this->api()
            ->getJson('/v1/dataforseo/serp-organic?keyword=x')
            ->assertStatus(503)
            ->assertJson(['error' => 'upstream_error', 'upstream_status' => 40200]);
    }

    public function test_other_consumers_account_id_returns_404(): void
    {
        $otherToken = Consumer::factory()->create()
            ->createToken('test', [TokenAbilities::DATAFORSEO_READ])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$otherToken}")
            ->withHeader('X-Account-Id', 'school-A')
            ->postJson('/v1/dataforseo/search-volume', ['keywords' => ['x']])
            ->assertNotFound()
            ->assertJsonPath('error', 'account_not_found');
    }

    private function api(): self
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}")
            ->withHeader('X-Account-Id', 'school-A');
    }

    /**
     * @param  array<int, mixed>  $result
     */
    private function taskEnvelope(array $result, int $statusCode = 20000, string $statusMessage = 'Ok.'): MockResponse
    {
        return MockResponse::make([
            'tasks_error' => $statusCode === 20000 ? 0 : 1,
            'tasks' => [[
                'status_code' => $statusCode,
                'status_message' => $statusMessage,
                'result' => $statusCode === 20000 ? $result : null,
            ]],
        ]);
    }
}
