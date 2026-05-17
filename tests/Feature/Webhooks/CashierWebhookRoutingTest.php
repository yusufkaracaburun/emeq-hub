<?php

namespace Tests\Feature\Webhooks;

use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CashierWebhookRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_phase_5a_mollie_webhook_route_still_uses_mollie_webhook_secret_guard(): void
    {
        config([
            'mollie.webhook.secret' => '',
            'services.cashier.webhook_secret' => 'whsec_cashier_set_xyz',
        ]);

        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()->forMollie()->active()->for($account)->create();

        $response = $this->postJson("/webhooks/mollie/{$connection->id}", ['id' => 'tr_test']);

        // Phase 5a's MollieWebhookController hard-fail't op empty mollie-secret.
        // De cashier-secret die WEL gezet is mag GEEN invloed hebben.
        $response->assertStatus(500);
        $response->assertJsonPath('error', 'webhook_misconfigured');
    }

    public function test_cashier_webhook_route_is_registered_on_distinct_path(): void
    {
        $routes = collect(Route::getRoutes())
            ->map(fn ($route) => $route->uri());

        $this->assertTrue(
            $routes->contains('cashier/webhook'),
            'Cashier-webhook-route /cashier/webhook is niet geregistreerd.',
        );
        $this->assertTrue(
            $routes->contains('webhooks/mollie/{connection_id}'),
            'Phase 5a Connect-webhook-route /webhooks/mollie/{connection_id} mag NIET verwijderd zijn.',
        );
    }
}
