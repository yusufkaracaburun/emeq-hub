<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AuthRejectionLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_rejected_token_is_logged_with_a_fingerprint(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $event, array $context): bool => $event === 'api.auth_rejected'
                && $context['status'] === 401
                && $context['path'] === 'v1/ping'
                && $context['consumer_id'] === null
                && $context['token_fingerprint'] === substr(hash('sha256', '99|dood-token'), 0, 12));

        $this->withHeader('Authorization', 'Bearer 99|dood-token')
            ->getJson('/v1/ping')
            ->assertUnauthorized();
    }

    public function test_a_missing_ability_is_logged_with_the_consumer(): void
    {
        $consumer = Consumer::factory()->create();
        $token = $consumer->createToken('t', [TokenAbilities::MOLLIE_READ])->plainTextToken;

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $event, array $context): bool => $event === 'api.auth_rejected'
                && $context['status'] === 403
                && $context['consumer_id'] === $consumer->id);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/itheorie/courses')
            ->assertForbidden();
    }

    public function test_a_successful_request_logs_nothing(): void
    {
        $consumer = Consumer::factory()->create();
        $token = $consumer->createToken('t', [TokenAbilities::ADMIN])->plainTextToken;

        Log::shouldReceive('warning')->never();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/ping')
            ->assertOk();
    }
}
