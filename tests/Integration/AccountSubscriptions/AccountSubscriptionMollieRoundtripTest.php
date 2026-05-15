<?php

declare(strict_types=1);

namespace Tests\Integration\AccountSubscriptions;

use App\Models\Account;
use App\Models\AccountSubscription;
use App\Models\Connection;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Mollie\Api\MollieApiClient;
use PHPUnit\Framework\Attributes\Group;

/**
 * Phase 7 / D-26 / SC-4 vendor-coverage: real Mollie test-mode roundtrip die
 * bewijst dat de Hub-API een AccountSubscription kan creëren op een merchant-
 * Mollie-account via Connect-`access_token`, en dat de subscription daarna
 * via de Hub-API te cancellen is en in Mollie zichtbaar is.
 *
 * Volgorde:
 *  1. Pre-flight (direct Mollie SDK met Connect-token): customer + valid
 *     directdebit-mandate op de merchant aanmaken — Phase 7 verwacht een
 *     bestaande `cst_*` + (optioneel) `mdt_*` per D-19/D-20 (geen Hub-side
 *     customer-bootstrap).
 *  2. Hub-domain setup: Consumer + Account + Mollie-Connection met de echte
 *     `access_token` overschreven op de factory-default. Sanctum-PAT met
 *     `mollie:write` ability.
 *  3. Hub-call: POST /v1/account-subscriptions met de real `cst_*` + `mdt_*`
 *     → Hub creëert Mollie-subscription via SDK + persist AccountSubscription
 *     in `Active`-state.
 *  4. Assertions: HTTP 201, status `active`, `mollie_subscription_id` met
 *     `sub_`-prefix, en de Mollie API ziet de subscription terug.
 *  5. Cleanup: DELETE via Hub → Mollie-cancel + Hub-state `canceled`. Verify
 *     remote status `canceled`. Mandate intrekken voor opgeruimde test-data.
 */
#[Group('integration')]
final class AccountSubscriptionMollieRoundtripTest extends AccountSubscriptionIntegrationTestCase
{
    public function test_create_via_hub_api_creates_real_mollie_subscription_and_persists_account_subscription_row(): void
    {
        // Stap 1: pre-flight customer + mandate via raw Mollie SDK.
        // Test-IBAN `NL55INGB0000000000` levert direct een valid directdebit-mandate
        // in Mollie's test-mode (gedocumenteerd in Mollie's mandates-api docs).
        $mollieClient = new MollieApiClient;
        $mollieClient->setAccessToken($this->mollieConnectAccessToken);

        $timestamp = now()->timestamp;

        $mollieCustomer = $mollieClient->customers->create([
            'name' => 'Hub Integration Test '.$timestamp,
            'email' => 'integration+'.$timestamp.'@emeq.test',
        ]);
        $this->assertNotEmpty($mollieCustomer->id, 'Mollie customer-create moet een cst_*-id retourneren.');
        $this->assertStringStartsWith('cst_', $mollieCustomer->id);

        $mollieMandate = $mollieClient->mandates->createForId($mollieCustomer->id, [
            'method' => 'directdebit',
            'consumerName' => 'Test Account Holder',
            'consumerAccount' => 'NL55INGB0000000000',
        ]);
        $this->assertSame('valid', $mollieMandate->status, 'Mollie test-mode mandate moet direct valid zijn.');
        $this->assertStringStartsWith('mdt_', $mollieMandate->id);

        // Stap 2: Hub-domain setup. Connection krijgt de echte access_token
        // overschreven op de factory-default; expires_at ruim in de toekomst
        // zodat HubMollieCredentialResolver geen refresh probeert (Personal
        // Access Tokens hebben geen refresh-token).
        $consumer = Consumer::factory()->create();

        /** @var Account $account */
        $account = Account::factory()->for($consumer)->create([
            'external_id' => 'integration-school-'.$timestamp,
        ]);

        Connection::factory()
            ->forMollie()
            ->for($account)
            ->create([
                'access_token' => $this->mollieConnectAccessToken,
                'refresh_token' => null,
                'expires_at' => now()->addYear(),
                'revoked_at' => null,
            ]);

        $token = $consumer->createToken('integration-test', [TokenAbilities::MOLLIE_WRITE])->plainTextToken;

        // Stap 3: Hub-call. Stuur de echte Mollie-id's mee in de body.
        $createResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/account-subscriptions', [
                'account_external_id' => 'integration-school-'.$timestamp,
                'mollie_customer_id' => $mollieCustomer->id,
                'mollie_mandate_id' => $mollieMandate->id,
                'amount' => ['currency' => 'EUR', 'value' => '5.00'],
                'interval' => '1 month',
                'description' => 'Integration test subscription '.$timestamp,
                'times' => 2,
            ]);

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('data.status', 'active');

        $hubId = $createResponse->json('data.id');
        $mollieSubscriptionId = $createResponse->json('data.mollie_subscription_id');

        $this->assertIsInt($hubId, 'Hub AccountSubscription-id moet integer zijn.');
        $this->assertIsString($mollieSubscriptionId);
        $this->assertStringStartsWith('sub_', $mollieSubscriptionId);

        // Stap 4: verify Mollie API ziet dezelfde subscription terug — bewijst
        // dat de Hub-call ook echt op het Mollie-merchant-account is geland.
        $remote = $mollieClient->subscriptions->getForId($mollieCustomer->id, $mollieSubscriptionId);
        $this->assertSame('active', $remote->status);

        // Verify Hub-row consistent met response.
        $sub = AccountSubscription::query()->findOrFail($hubId);
        $this->assertSame($mollieSubscriptionId, $sub->mollie_subscription_id);

        try {
            // Stap 5a: cleanup via Hub-DELETE — moet 204 retourneren en de
            // Mollie-subscription cancellen via de manager.
            $deleteResponse = $this->withHeader('Authorization', "Bearer {$token}")
                ->deleteJson("/v1/account-subscriptions/{$hubId}");
            $deleteResponse->assertNoContent();

            $refreshed = $mollieClient->subscriptions->getForId($mollieCustomer->id, $mollieSubscriptionId);
            $this->assertSame('canceled', $refreshed->status);
        } finally {
            // Stap 5b: cleanup mandate ongeacht test-resultaat — voorkomt
            // dangling test-data in Mollie's dashboard (T-07-07-02 mitigation).
            try {
                $mollieClient->mandates->revokeForId($mollieCustomer->id, $mollieMandate->id);
            } catch (\Throwable) {
                // Best-effort cleanup; Mollie test-mode customers verlopen sowieso
                // na 30 dagen automatisch.
            }
        }
    }
}
