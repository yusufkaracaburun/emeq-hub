<?php

namespace Tests\Feature\Webhooks;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\WebhookClient\Models\WebhookCall;
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

        $this->assertDatabaseHas('webhook_calls', [
            'name' => 'cashier',
            'exception' => 'webhook_secret_not_configured',
        ]);
    }

    public function test_null_secret_returns_500_and_writes_audit_row(): void
    {
        config(['services.cashier.webhook_secret' => null]);

        $response = $this->postJson('/cashier/webhook', ['id' => 'tr_test_xyz']);

        $response->assertStatus(500);
        $response->assertJsonPath('error', 'webhook_misconfigured');
        $this->assertDatabaseHas('webhook_calls', [
            'name' => 'cashier',
            'exception' => 'webhook_secret_not_configured',
        ]);
    }

    public function test_set_secret_passes_guard_to_cashier_controller(): void
    {
        config(['services.cashier.webhook_secret' => 'whsec_cashier_test_abc']);

        $response = $this->postJson('/cashier/webhook', ['id' => 'tr_test_xyz']);

        // Cashier's eigen WebhookController handle't unknown payment-id;
        // 200/400/422 zijn allemaal acceptabel — wat we asserteren is dat
        // de guard NIET geactiveerd is (= geen 500 + webhook_misconfigured).
        $this->assertNotSame(500, $response->status(), sprintf(
            'Guard mag NIET hard-fail\'en bij gezette secret; kreeg status %d: %s',
            $response->status(),
            $response->content(),
        ));

        $this->assertDatabaseMissing('webhook_calls', [
            'name' => 'cashier',
            'exception' => 'webhook_secret_not_configured',
        ]);
    }

    public function test_audit_row_uses_name_cashier_not_mollie(): void
    {
        config(['services.cashier.webhook_secret' => '']);

        $this->postJson('/cashier/webhook', ['id' => 'tr_test']);

        $latest = WebhookCall::query()->latest('id')->first();
        $this->assertNotNull($latest);
        $this->assertSame('cashier', $latest->name);
    }
}
