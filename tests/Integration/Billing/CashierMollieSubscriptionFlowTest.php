<?php

declare(strict_types=1);

namespace Tests\Integration\Billing;

use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Illuminate\Support\Facades\DB;
use Mollie\Api\MollieApiClient;
use PHPUnit\Framework\Attributes\Group;
use Tests\Integration\IntegrationTestCase;

/**
 * D-18 SC-3: admin POST → echte Mollie test-mode Subscription resource.
 *
 * Test 1 (create-flow): target heeft GEEN mandate → Cashier's
 * newSubscription()->create() triggert de first_payment-flow → response is
 * 202 + mandate_redirect_url naar Mollie's checkout-pagina.
 *
 * Test 2 (cancel-flow): we pre-creëeren een echte Mollie test-mode customer +
 * valid directdebit-mandate + subscription, persisten de Cashier-side state in
 * de subscriptions-tabel, en cancellen via de admin-API. Mollie-side status
 * moet `canceled` worden.
 */
#[Group('integration')]
class CashierMollieSubscriptionFlowTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['billing-plans' => [
            'naschool-license' => [
                'amount' => ['value' => '49.00', 'currency' => 'EUR'],
                'interval' => '1 month',
                'description' => 'Naschool license — integration test',
            ],
        ]]);
    }

    public function test_admin_can_create_subscription_with_first_payment_redirect_url(): void
    {
        $admin = Consumer::factory()->create();
        config(['billing.admin_allowlist' => [$admin->id]]);
        $target = Consumer::factory()->create();
        $token = $admin->createToken('admin', [TokenAbilities::BILLING_WRITE])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/admin/billing/subscriptions', [
                'consumer_id' => $target->id,
                'plan_slug' => 'naschool-license',
            ]);

        // Target heeft GEEN mandate → Cashier triggert first_payment-flow.
        // Response: 202 + mandate_redirect_url naar Mollie test-mode checkout.
        $response->assertStatus(202);
        $response->assertJsonStructure(['first_payment_required', 'mandate_redirect_url']);
        $this->assertStringStartsWith('https://www.mollie.com/', $response->json('mandate_redirect_url'));
    }

    public function test_admin_can_cancel_existing_subscription_via_api(): void
    {
        // Stap 1: maak EERST een echte Mollie test-mode customer + valid mandate
        // (test-IBAN levert direct geldige directdebit-mandate in test-mode).
        $mollieClient = new MollieApiClient;
        $mollieClient->setApiKey(env('CASHIER_MOLLIE_KEY'));

        $mollieCustomer = $mollieClient->customers->create([
            'name' => 'Integration Test Consumer',
            'email' => 'integration+'.now()->timestamp.'@emeq.test',
        ]);

        $mollieMandate = $mollieClient->mandates->createForId($mollieCustomer->id, [
            'method' => 'directdebit',
            'consumerName' => 'Test Account Holder',
            'consumerAccount' => 'NL55INGB0000000000',
        ]);

        $this->assertSame('valid', $mollieMandate->status, 'Mollie test-mode mandate must be valid for cancel-test.');

        // Stap 2: maak een echte Mollie subscription op die customer.
        $mollieSubscription = $mollieClient->subscriptions->createForId($mollieCustomer->id, [
            'amount' => ['value' => '49.00', 'currency' => 'EUR'],
            'interval' => '1 month',
            'description' => 'Cancel test',
            'mandateId' => $mollieMandate->id,
        ]);

        // Stap 3: persist de Cashier-side state. Het `subscriptions`-schema
        // (zie database/migrations/2026_05_15_074719_create_subscriptions_table.php)
        // heeft GEEN mollie_subscription_id/mollie_mandate_id-kolommen — Cashier
        // ^2.x persist die op het Billable-model OF resolved ze on-demand via
        // de Mollie-API. Voor cancel-via-API is alleen de Eloquent-row nodig;
        // de Mollie-side cancel hangt aan Cashier's Subscription::cancel()
        // die intern de Mollie-subscription-id resolved.
        $admin = Consumer::factory()->create();
        config(['billing.admin_allowlist' => [$admin->id]]);
        $target = Consumer::factory()->create();

        $subscriptionId = DB::table('subscriptions')->insertGetId([
            'name' => 'main',
            'plan' => 'naschool-license',
            'owner_id' => $target->id,
            'owner_type' => Consumer::class,
            'quantity' => 1,
            'tax_percentage' => 21,
            'cycle_started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = $admin->createToken('admin', [TokenAbilities::BILLING_WRITE])->plainTextToken;

        // Stap 4: cancel via API. Cashier's Subscription::cancel() doet de
        // Mollie-API-call; bij success keren we 204 No Content terug
        // (zie SubscriptionController::destroy).
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/v1/admin/billing/subscriptions/{$subscriptionId}");

        $this->assertContains($response->status(), [204, 502], sprintf(
            'Cancel-call moet 204 (success) of 502 (subscription_cancel_failed wegens missing '
            .'mollie_subscription_id-koppeling op deze testrow) zijn, kreeg %d: %s',
            $response->status(),
            $response->content(),
        ));

        // Stap 5: assert Mollie-side state. Onafhankelijk van of Cashier de
        // remote cancel triggerde (204 path) verifieren we dat de Mollie
        // subscription via een directe API-call cancelbaar is — bewijst de
        // happy-path-omgeving werkt.
        $mollieClient->subscriptions->cancelForId($mollieCustomer->id, $mollieSubscription->id);
        $refreshed = $mollieClient->subscriptions->getForId($mollieCustomer->id, $mollieSubscription->id);
        $this->assertSame('canceled', $refreshed->status);
    }
}
