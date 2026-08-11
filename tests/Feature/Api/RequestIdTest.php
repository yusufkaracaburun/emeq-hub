<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RequestIdTest extends TestCase
{
    use RefreshDatabase;

    private function token(): string
    {
        return Consumer::factory()->create()
            ->createToken('t', [TokenAbilities::ADMIN])->plainTextToken;
    }

    public function test_generates_a_request_id_when_the_header_is_absent(): void
    {
        $response = $this->withToken($this->token())->getJson('/v1/ping');

        $response->assertOk();

        $id = $response->headers->get('X-Request-Id');

        $this->assertNotNull($id);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id, 'Verwacht een ULID.');
    }

    public function test_accepts_a_well_formed_inbound_request_id(): void
    {
        $response = $this->withToken($this->token())
            ->withHeader('X-Request-Id', 'trace-abc_123')
            ->getJson('/v1/ping');

        $response->assertOk();
        $this->assertSame('trace-abc_123', $response->headers->get('X-Request-Id'));
    }

    /**
     * De waarde wordt teruggekaatst naar de client; zonder strikte validatie is dat
     * een header-injectie- en log-vervuilingspad.
     */
    #[DataProvider('malformedIds')]
    public function test_rejects_a_malformed_inbound_request_id(string $malformed): void
    {
        $response = $this->withToken($this->token())
            ->withHeader('X-Request-Id', $malformed)
            ->getJson('/v1/ping');

        $response->assertOk();

        $echoed = $response->headers->get('X-Request-Id');

        $this->assertNotSame($malformed, $echoed);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', (string) $echoed);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedIds(): array
    {
        return [
            'spaties' => ['heeft spaties'],
            'te kort' => ['abc'],
            'te lang' => [str_repeat('a', 65)],
            'punten' => ['../../etc/passwd'],
            'leeg' => [''],
        ];
    }

    /**
     * De middleware staat vóór auth en throttle, dus ook een geweigerd request is
     * te correleren. Dit is precies het request dat je tijdens een incident zoekt.
     */
    public function test_request_id_is_present_on_an_unauthenticated_response(): void
    {
        $response = $this->getJson('/v1/ping');

        $response->assertUnauthorized();
        $this->assertNotNull($response->headers->get('X-Request-Id'));
    }

    public function test_request_id_is_available_in_the_log_context(): void
    {
        $this->withToken($this->token())
            ->withHeader('X-Request-Id', 'ctx-check-0001')
            ->getJson('/v1/ping')
            ->assertOk();

        $this->assertSame('ctx-check-0001', Context::get('request_id'));
    }
}
