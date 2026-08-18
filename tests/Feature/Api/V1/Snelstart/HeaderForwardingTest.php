<?php

namespace Tests\Feature\Api\V1\Snelstart;

use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Emeq\SnelstartApi\Http\Request\RawSnelstartRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Tests\Concerns\PrimesSnelstartTokenCache;
use Tests\TestCase;

class HeaderForwardingTest extends TestCase
{
    use PrimesSnelstartTokenCache;
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $captured = [];

    protected function setUp(): void
    {
        parent::setUp();
        MockClient::destroyGlobal();
        config(['snelstart.http.retry.times' => 1, 'snelstart.http.retry.sleep' => 0]);
        $this->captured = [];
    }

    protected function tearDown(): void
    {
        MockClient::destroyGlobal();
        parent::tearDown();
    }

    public function test_authorization_header_is_stripped_before_sdk_call(): void
    {
        $token = $this->bootSnelstartConsumerAndArmMock();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school-A')
            ->getJson('/v1/snelstart/echo/ping')
            ->assertOk();

        $forwarded = $this->lowercasedForwardedHeaders();

        $this->assertNotEquals("bearer {$token}", $forwarded['authorization'] ?? null);
    }

    public function test_x_account_id_header_is_stripped_before_sdk_call(): void
    {
        $token = $this->bootSnelstartConsumerAndArmMock();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school-A')
            ->getJson('/v1/snelstart/echo/ping')
            ->assertOk();

        $forwarded = $this->lowercasedForwardedHeaders();
        $this->assertArrayNotHasKey('x-account-id', $forwarded, 'X-Account-Id mag niet naar Snelstart');
    }

    public function test_user_agent_and_cookie_are_stripped_before_sdk_call(): void
    {
        $token = $this->bootSnelstartConsumerAndArmMock();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school-A')
            ->withHeader('User-Agent', 'consumer/app 1.0')
            ->withHeader('Cookie', 'session=should-not-leak')
            ->getJson('/v1/snelstart/echo/ping')
            ->assertOk();

        $forwarded = $this->lowercasedForwardedHeaders();
        $this->assertArrayNotHasKey('cookie', $forwarded);

        $ua = $forwarded['user-agent'] ?? '';
        $this->assertStringNotContainsString('consumer/app 1.0', is_array($ua) ? implode(',', $ua) : (string) $ua);
    }

    private function bootSnelstartConsumerAndArmMock(): string
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
        $connection = Connection::factory()->forSnelstart()->for($account)->create();
        $this->primeSnelstartToken($connection);

        MockClient::global([
            RawSnelstartRequest::class => function (PendingRequest $pr) {
                $this->captured = $pr->headers()->all();

                return MockResponse::make(['ok' => true], 200);
            },
        ]);

        return $consumer->createToken('test', [TokenAbilities::SNELSTART_READ])->plainTextToken;
    }

    /** @return array<string, mixed> */
    private function lowercasedForwardedHeaders(): array
    {
        $out = [];
        foreach ($this->captured as $name => $value) {
            $out[strtolower((string) $name)] = $value;
        }

        return $out;
    }
}
