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

#[Group('integration')]
final class AccountSubscriptionMollieRoundtripTest extends AccountSubscriptionIntegrationTestCase
{
    public function test_create_via_hub_api_creates_real_mollie_subscription_and_persists_account_subscription_row(): void
    {
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

        $remote = $mollieClient->subscriptions->getForId($mollieCustomer->id, $mollieSubscriptionId);
        $this->assertSame('active', $remote->status);

        $sub = AccountSubscription::query()->findOrFail($hubId);
        $this->assertSame($mollieSubscriptionId, $sub->mollie_subscription_id);

        try {
            $deleteResponse = $this->withHeader('Authorization', "Bearer {$token}")
                ->deleteJson("/v1/account-subscriptions/{$hubId}");
            $deleteResponse->assertNoContent();

            $refreshed = $mollieClient->subscriptions->getForId($mollieCustomer->id, $mollieSubscriptionId);
            $this->assertSame('canceled', $refreshed->status);
        } finally {
            try {
                $mollieClient->mandates->revokeForId($mollieCustomer->id, $mollieMandate->id);
            } catch (\Throwable) {
            }
        }
    }
}
