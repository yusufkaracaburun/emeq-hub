<?php

namespace Tests\Feature\Exact;

use App\Models\Connection;
use Emeq\ExactApi\Exceptions\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarksConnectionNeedingReconsentTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_refused_refresh_token_marks_the_connection(): void
    {
        $connection = Connection::factory()->forExact()->active()->create();

        report(AuthenticationException::refreshFailed(
            400,
            '{"error":"invalid_grant","error_description":"Token is not allowed, because of invalid or empty chainId"}',
            'fp',
            connectionRef: (string) $connection->id,
        ));

        $this->assertSame('needs_consent', $connection->fresh()->status);
    }

    public function test_a_transient_refresh_failure_leaves_the_connection_active(): void
    {
        $connection = Connection::factory()->forExact()->active()->create();

        report(AuthenticationException::refreshFailed(500, 'gateway down', 'fp', connectionRef: (string) $connection->id));

        $this->assertSame('active', $connection->fresh()->status);
    }

    public function test_it_only_marks_exact_connections(): void
    {
        $exact = Connection::factory()->forExact()->active()->create();
        $snelstart = Connection::factory()->forSnelstart()->active()->create();

        report(AuthenticationException::refreshFailed(
            400,
            '{"error":"invalid_grant"}',
            'fp',
            connectionRef: (string) $snelstart->id,
        ));

        $this->assertSame('active', $snelstart->fresh()->status);
        $this->assertSame('active', $exact->fresh()->status);
    }
}
