<?php

namespace Tests\Feature\Webhooks;

use App\Models\InboundWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashierWebhookSecretGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_secret_returns_500_and_writes_audit_row(): void
    {
        config(['services.cashier.webhook_secret' => '']);

        $response = $this->postJson('/cashier/webhook', ['id' => 'tr_test_xyz']);

        $response->assertStatus(500);
        $response->assertJsonPath('error', 'webhook_misconfigured');

        $event = InboundWebhookEvent::query()->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertSame('cashier', $event->provider);
        $this->assertSame('misconfigured', $event->outcome);
        $this->assertSame(500, $event->status);
    }

    public function test_null_secret_returns_500_and_writes_audit_row(): void
    {
        config(['services.cashier.webhook_secret' => null]);

        $response = $this->postJson('/cashier/webhook', ['id' => 'tr_test_xyz']);

        $response->assertStatus(500);
        $response->assertJsonPath('error', 'webhook_misconfigured');

        $event = InboundWebhookEvent::query()->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertSame('cashier', $event->provider);
        $this->assertSame('misconfigured', $event->outcome);
        $this->assertSame(500, $event->status);
    }

    public function test_set_secret_passes_guard_and_does_not_write_misconfigured_audit(): void
    {
        config(['services.cashier.webhook_secret' => 'whsec_cashier_test_abc']);

        $this->postJson('/cashier/webhook', ['id' => 'tr_test_xyz']);

        $misconfigured = InboundWebhookEvent::query()
            ->where('provider', 'cashier')
            ->where('outcome', 'misconfigured')
            ->first();

        $this->assertNull(
            $misconfigured,
            'Guard mag GEEN misconfigured-audit schrijven wanneer secret gezet is.',
        );
    }

    public function test_audit_row_uses_provider_cashier_not_mollie(): void
    {
        config(['services.cashier.webhook_secret' => '']);

        $this->postJson('/cashier/webhook', ['id' => 'tr_test']);

        $latest = InboundWebhookEvent::query()->latest('id')->first();
        $this->assertNotNull($latest);
        $this->assertSame('cashier', $latest->provider);
    }
}
