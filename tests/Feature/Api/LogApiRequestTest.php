<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class LogApiRequestTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array<string, mixed>> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        Log::listen(function ($event): void {
            if ($event->message === 'api.request') {
                $this->logged[] = $event->context;
            }
        });
    }

    public function test_logs_metadata_for_a_v1_request(): void
    {
        $consumer = Consumer::factory()->create();
        $token = $consumer->createToken('t', [TokenAbilities::ADMIN])->plainTextToken;

        $this->withToken($token)->getJson('/v1/ping?foo=1&bar=2')->assertOk();

        $this->assertCount(1, $this->logged);

        $entry = $this->logged[0];

        $this->assertSame('GET', $entry['method']);
        $this->assertSame('v1/ping', $entry['path']);
        $this->assertSame('foo,bar', $entry['query_keys']);
        $this->assertSame(200, $entry['status']);
        $this->assertSame($consumer->getKey(), $entry['consumer_id']);
        $this->assertNull($entry['request_fingerprint']);
        $this->assertIsInt($entry['duration_ms']);
        $this->assertIsInt($entry['response_size_bytes']);
    }

    public function test_fingerprints_the_body_without_storing_it(): void
    {
        $token = Consumer::factory()->create()->createToken('t', [TokenAbilities::ADMIN])->plainTextToken;
        $payload = ['iban' => 'NL91ABNA0417164300', 'name' => 'Klant'];

        $this->withToken($token)->postJson('/v1/accounting/documents/validate', $payload);

        $this->assertCount(1, $this->logged);

        $entry = $this->logged[0];

        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $entry['request_fingerprint']);
        $this->assertStringNotContainsString('NL91ABNA0417164300', json_encode($entry, JSON_THROW_ON_ERROR));
    }

    public function test_logs_unauthenticated_requests_without_a_consumer(): void
    {
        $this->getJson('/v1/ping')->assertStatus(401);

        $this->assertCount(1, $this->logged);
        $this->assertSame(401, $this->logged[0]['status']);
        $this->assertNull($this->logged[0]['consumer_id']);
    }

    public function test_ignores_routes_outside_v1(): void
    {
        $this->get('/robots.txt')->assertOk();

        $this->assertSame([], $this->logged);
    }
}
