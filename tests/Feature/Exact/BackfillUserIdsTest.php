<?php

namespace Tests\Feature\Exact;

use App\Models\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackfillUserIdsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.exact.client_id' => 'app_test_id',
            'services.exact.client_secret' => 'app_test_secret',
            'services.exact.redirect_uri' => 'https://hub.test/v1/oauth/exact/callback',
            'services.exact.auth_base_url' => 'https://start.exactonline.nl',
            'services.exact.api_base_url' => 'https://start.exactonline.nl',
        ]);
    }

    public function test_dry_run_reports_without_writing(): void
    {
        Http::fake();

        $connection = Connection::factory()->forExact()->create(['metadata' => null]);

        $this->artisan('exact:backfill-user-ids')->assertExitCode(0);

        $this->assertNull($connection->fresh()->metadata['exact_user_id'] ?? null);
        Http::assertNothingSent();
    }

    public function test_force_fills_the_user_id_and_keeps_existing_metadata(): void
    {
        $this->fakeMe('d3b3f9a1-9c2e-4b7a-8f7e-2f4a1b6c9d0e');

        $connection = Connection::factory()->forExact()->create([
            'metadata' => ['webhook_secret_fingerprint' => 'abc123'],
        ]);

        $this->artisan('exact:backfill-user-ids', ['--force' => true])->assertExitCode(0);

        $metadata = $connection->fresh()->metadata;
        $this->assertSame('d3b3f9a1-9c2e-4b7a-8f7e-2f4a1b6c9d0e', $metadata['exact_user_id']);
        $this->assertSame('abc123', $metadata['webhook_secret_fingerprint']);
    }

    public function test_connections_that_already_have_a_user_id_are_left_alone(): void
    {
        Http::fake();

        Connection::factory()->forExact()->create([
            'metadata' => ['exact_user_id' => 'existing-user-id'],
        ]);

        $this->artisan('exact:backfill-user-ids')
            ->expectsOutputToContain('hebben al een exact_user_id')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_revoked_connections_are_skipped(): void
    {
        Http::fake();

        Connection::factory()->forExact()->create([
            'metadata' => null,
            'status' => 'revoked',
            'revoked_at' => now(),
        ]);

        $this->artisan('exact:backfill-user-ids')
            ->expectsOutputToContain('hebben al een exact_user_id')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_a_failing_me_call_is_reported_and_exits_non_zero(): void
    {
        Http::fake([
            'start.exactonline.nl/api/v1/current/Me' => Http::response(status: 500),
        ]);

        $connection = Connection::factory()->forExact()->create(['metadata' => null]);

        $this->artisan('exact:backfill-user-ids', ['--force' => true])->assertExitCode(1);

        $this->assertNull($connection->fresh()->metadata['exact_user_id'] ?? null);
    }

    private function fakeMe(string $userId): void
    {
        Http::fake([
            'start.exactonline.nl/api/v1/current/Me' => Http::response([
                'd' => ['results' => [[
                    'CurrentDivision' => 4471372,
                    'UserID' => $userId,
                ]]],
            ]),
        ]);
    }
}
