<?php

namespace Tests\Feature\Console;

use App\Models\InboundWebhookEvent;
use App\Models\PassThroughCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelPruningTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_pass_through_calls_older_than_retention_window(): void
    {
        config(['hub.retention.pass_through_days' => 90]);

        $old = PassThroughCall::factory()->create(['created_at' => now()->subDays(120)]);
        $recent = PassThroughCall::factory()->create(['created_at' => now()->subDays(30)]);

        $this->artisan('model:prune', ['--model' => [PassThroughCall::class]])->assertExitCode(0);

        $this->assertNull(PassThroughCall::find($old->id));
        $this->assertNotNull(PassThroughCall::find($recent->id));
    }

    public function test_prunes_inbound_webhook_events_older_than_retention_window(): void
    {
        config(['hub.retention.webhook_days' => 90]);

        $old = InboundWebhookEvent::factory()->create(['received_at' => now()->subDays(120)]);
        $recent = InboundWebhookEvent::factory()->create(['received_at' => now()->subDays(10)]);

        $this->artisan('model:prune', ['--model' => [InboundWebhookEvent::class]])->assertExitCode(0);

        $this->assertNull(InboundWebhookEvent::find($old->id));
        $this->assertNotNull(InboundWebhookEvent::find($recent->id));
    }

    public function test_retention_zero_disables_pruning(): void
    {
        config(['hub.retention.pass_through_days' => 0]);

        $ancient = PassThroughCall::factory()->create(['created_at' => now()->subDays(3650)]);

        $this->artisan('model:prune', ['--model' => [PassThroughCall::class]])->assertExitCode(0);

        $this->assertNotNull(PassThroughCall::find($ancient->id));
    }
}
