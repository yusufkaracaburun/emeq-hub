<?php

namespace Tests\Feature\Console;

use App\Models\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneOAuthPendingConnectionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_expired_pending_connections(): void
    {
        $expired = Connection::factory()->forMollie()->pending()->expired()->create();
        $fresh = Connection::factory()->forMollie()->pending()->create();
        $active = Connection::factory()->forMollie()->active()->create();

        $this->artisan('oauth:prune-pending')->assertExitCode(0);

        $this->assertNull(Connection::find($expired->id));
        $this->assertNotNull(Connection::find($fresh->id));
        $this->assertNotNull(Connection::find($active->id));
    }

    public function test_does_not_prune_expired_pending_with_tokens(): void
    {
        $relinking = Connection::factory()->forMollie()->expired()->create([
            'status' => 'pending',
        ]);

        $this->artisan('oauth:prune-pending')->assertExitCode(0);

        $this->assertNotNull(Connection::find($relinking->id));
    }

    public function test_dry_run_does_not_delete_anything(): void
    {
        $expired = Connection::factory()->forMollie()->pending()->expired()->create();

        $this->artisan('oauth:prune-pending', ['--dry-run' => true])
            ->expectsOutputToContain('Dry-run')
            ->assertExitCode(0);

        $this->assertNotNull(Connection::find($expired->id));
    }
}
