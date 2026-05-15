<?php

declare(strict_types=1);

namespace Tests\Integration\Billing;

use Mollie\Api\MollieApiClient;
use PHPUnit\Framework\Attributes\Group;
use Spatie\WebhookClient\Models\WebhookCall;
use Tests\Integration\IntegrationTestCase;

/**
 * D-10/D-11 end-to-end: Mollie POSTs naar /cashier/webhook met set
 * CASHIER_WEBHOOK_SECRET → Cashier's WebhookController accepteert + verwerkt;
 * zonder secret → 500 + audit (regressie-test voor 06-06 guard).
 */
#[Group('integration')]
class CashierWebhookEndToEndTest extends IntegrationTestCase
{
    public function test_webhook_with_valid_secret_triggers_cashier_handler(): void
    {
        // Stap 1: maak een echte Mollie test-mode payment aan zodat we een
        // geldige id hebben die Cashier's webhook-handler kan resolven via
        // de Mollie API (laravel-cashier-mollie's WebhookController doet een
        // payments->get($id)-call om de payment-status op te halen).
        $mollieClient = new MollieApiClient;
        $mollieClient->setApiKey(env('CASHIER_MOLLIE_KEY'));

        $payment = $mollieClient->payments->create([
            'amount' => ['value' => '0.01', 'currency' => 'EUR'],
            'description' => 'Webhook end-to-end test',
            'redirectUrl' => 'https://example.test/done',
        ]);

        $this->assertNotEmpty($payment->id);

        // Stap 2: simuleer Mollie's webhook-POST naar onze endpoint. De
        // RequireCashierWebhookSecret-middleware (06-06) valideert de secret;
        // bij set-secret laat de guard de request door naar Cashier's
        // vendor-controller.
        $secret = env('CASHIER_WEBHOOK_SECRET') ?: 'whsec_integration_test';
        config(['services.cashier.webhook_secret' => $secret]);

        $response = $this->post('/cashier/webhook', ['id' => $payment->id], [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]);

        // Cashier's WebhookController fetcht de payment via de Mollie API en
        // dispatcht de bijbehorende handler. Voor een geïsoleerde payment
        // (geen subscription/order) verwacht Cashier 200 of 404 — beide zijn
        // "guard passed, downstream handled gracefully".
        $this->assertContains($response->status(), [200, 202, 404], sprintf(
            'Cashier webhook handler retourneerde onverwachte status %d: %s',
            $response->status(),
            $response->content(),
        ));

        // Belangrijkste invariant: de 06-06-guard heeft GEEN
        // webhook_secret_not_configured-audit-rij geschreven omdat de secret
        // wel degelijk was gezet.
        $failedRows = WebhookCall::query()
            ->where('name', 'cashier')
            ->get()
            ->filter(fn (WebhookCall $row): bool => $row->exception === 'webhook_secret_not_configured')
            ->count();
        $this->assertSame(0, $failedRows);
    }

    public function test_webhook_without_secret_returns_500_in_integration_env(): void
    {
        // Force de 06-06-guard naar zijn fail-pad door de secret leeg te
        // zetten in de integration-omgeving. Bewijst dat de hard-fail-guard
        // ook werkt wanneer echte Mollie-credentials beschikbaar zijn —
        // anders zou een mis-configured webhook in productie ongezien
        // doorlopen.
        config(['services.cashier.webhook_secret' => '']);

        $response = $this->post('/cashier/webhook', ['id' => 'tr_test_anything']);

        $response->assertStatus(500);
        $response->assertJsonPath('error', 'webhook_misconfigured');

        $auditRow = WebhookCall::query()
            ->where('name', 'cashier')
            ->latest('id')
            ->first();

        $this->assertNotNull($auditRow);
        $this->assertSame('webhook_secret_not_configured', $auditRow->exception);
    }
}
