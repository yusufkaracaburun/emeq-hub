<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\InboundWebhookEvents\InboundWebhookEventResource;
use App\Filament\Resources\PassThroughCalls\PassThroughCallResource;
use App\Filament\Widgets\OperationalHealthWidget;
use App\Models\Connection;
use App\Models\InboundWebhookEvent;
use App\Models\PassThroughCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalHealthWidgetTest extends TestCase
{
    use RefreshDatabase;

    private function counts(): array
    {
        return (new OperationalHealthWidget)->attentionCounts();
    }

    public function test_clean_state_reports_all_zero(): void
    {
        $this->assertSame(
            [
                'failed_pass_throughs' => 0,
                'webhook_problems' => 0,
                'expiring_connections' => 0,
                'pending_oauth' => 0,
            ],
            $this->counts(),
        );
    }

    public function test_counts_failed_pass_throughs_within_24h_only(): void
    {
        PassThroughCall::factory()->create(['status' => 500, 'created_at' => now()]);
        PassThroughCall::factory()->create(['status' => 404, 'created_at' => now()->subHours(3)]);
        PassThroughCall::factory()->create(['status' => 200, 'created_at' => now()]);
        PassThroughCall::factory()->create(['status' => 500, 'created_at' => now()->subDays(2)]);

        $this->assertSame(2, $this->counts()['failed_pass_throughs']);
    }

    public function test_counts_unprocessed_webhooks_within_24h_only(): void
    {
        InboundWebhookEvent::factory()->create(['outcome' => 'malformed', 'received_at' => now()]);
        InboundWebhookEvent::factory()->create(['outcome' => 'invalid_signature', 'received_at' => now()->subHours(2)]);
        InboundWebhookEvent::factory()->create(['outcome' => 'processed', 'received_at' => now()]);
        InboundWebhookEvent::factory()->create(['outcome' => 'duplicate', 'received_at' => now()]);
        InboundWebhookEvent::factory()->create(['outcome' => 'malformed', 'received_at' => now()->subDays(2)]);

        $this->assertSame(2, $this->counts()['webhook_problems']);
    }

    public function test_counts_connections_expiring_within_seven_days(): void
    {
        Connection::factory()->create(['expires_at' => now()->addDays(3), 'revoked_at' => null]);
        Connection::factory()->create(['expires_at' => now()->addDays(30), 'revoked_at' => null]);
        Connection::factory()->create(['expires_at' => now()->addDays(2), 'revoked_at' => now()]);
        Connection::factory()->create(['expires_at' => null, 'revoked_at' => null]);

        $this->assertSame(1, $this->counts()['expiring_connections']);
    }

    public function test_counts_pending_oauth_handshakes(): void
    {
        Connection::factory()->create(['status' => 'pending']);
        Connection::factory()->create(['status' => 'pending']);
        Connection::factory()->create(['status' => 'active']);

        $this->assertSame(2, $this->counts()['pending_oauth']);
    }

    public function test_nav_badge_surfaces_recent_failures(): void
    {
        PassThroughCall::factory()->create(['status' => 503, 'created_at' => now()]);
        InboundWebhookEvent::factory()->create(['outcome' => 'misconfigured', 'received_at' => now()]);

        $this->assertSame('1', PassThroughCallResource::getNavigationBadge());
        $this->assertSame('1', InboundWebhookEventResource::getNavigationBadge());
    }

    public function test_nav_badge_is_null_when_clean(): void
    {
        $this->assertNull(PassThroughCallResource::getNavigationBadge());
        $this->assertNull(InboundWebhookEventResource::getNavigationBadge());
    }
}
