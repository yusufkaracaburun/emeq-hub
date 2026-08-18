<?php

declare(strict_types=1);

namespace Tests\Integration\Billing;

use App\Models\InboundWebhookEvent;
use Mollie\Api\MollieApiClient;
use PHPUnit\Framework\Attributes\Group;
use Tests\Integration\IntegrationTestCase;

#[Group('integration')]
class CashierWebhookEndToEndTest extends IntegrationTestCase
{
    public function test_webhook_with_valid_secret_triggers_cashier_handler(): void
    {
        $mollieClient = new MollieApiClient;
        $mollieClient->setApiKey(env('CASHIER_MOLLIE_KEY'));

        $payment = $mollieClient->payments->create([
            'amount' => ['value' => '0.01', 'currency' => 'EUR'],
            'description' => 'Webhook end-to-end test',
            'redirectUrl' => 'https://example.test/done',
        ]);

        $this->assertNotEmpty($payment->id);

        $secret = env('CASHIER_WEBHOOK_SECRET') ?: 'whsec_integration_test';
        config(['services.cashier.webhook_secret' => $secret]);

        $response = $this->post('/cashier/webhook', ['id' => $payment->id], [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]);

        $this->assertContains($response->status(), [200, 202, 404], sprintf(
            'Cashier webhook handler retourneerde onverwachte status %d: %s',
            $response->status(),
            $response->content(),
        ));

        $failedRows = InboundWebhookEvent::query()
            ->where('provider', 'cashier')
            ->where('outcome', 'misconfigured')
            ->count();
        $this->assertSame(0, $failedRows);
    }

    public function test_webhook_without_secret_returns_500_in_integration_env(): void
    {
        config(['services.cashier.webhook_secret' => '']);

        $response = $this->post('/cashier/webhook', ['id' => 'tr_test_anything']);

        $response->assertStatus(500);
        $response->assertJsonPath('error', 'webhook_misconfigured');

        $auditRow = InboundWebhookEvent::query()
            ->where('provider', 'cashier')
            ->latest('id')
            ->first();

        $this->assertNotNull($auditRow);
        $this->assertSame('misconfigured', $auditRow->outcome);
    }
}
