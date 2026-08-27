<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Mollie\Billing;

use App\Billing\Account\SubscriptionStatus;
use App\Integrations\Mollie\Billing\AccountSubscriptionManager;
use App\Models\AccountSubscription;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Integrations\Mollie\Concerns\StubsMollieClient;
use Tests\TestCase;

class AccountSubscriptionManagerRecordPaymentEventTest extends TestCase
{
    use RefreshDatabase;
    use StubsMollieClient;

    public function test_mandate_invalid_failure_transitions_active_to_paused(): void
    {
        [, , , $connection] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        $sub = AccountSubscription::factory()
            ->forConnection($connection)
            ->active()
            ->create(['mollie_subscription_id' => 'sub_x']);

        $manager = $this->app->make(AccountSubscriptionManager::class);

        $manager->recordPaymentEvent($sub, [
            'id' => 'tr_failed',
            'status' => 'failed',
            'subscriptionId' => 'sub_x',
            'details' => ['failureReason' => 'mandate_invalid'],
        ]);

        $fresh = $sub->fresh();
        $this->assertSame(SubscriptionStatus::Paused, $fresh->status);
        $this->assertNotNull($fresh->paused_at);
        $this->assertSame('failed_mandate_invalid', $fresh->last_payment_status);
        $this->assertNotNull($fresh->last_webhook_event_at);
    }

    public function test_paid_payment_updates_last_payment_status_without_state_flip(): void
    {
        [, , , $connection] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        $sub = AccountSubscription::factory()
            ->forConnection($connection)
            ->active()
            ->create(['mollie_subscription_id' => 'sub_paid']);

        $manager = $this->app->make(AccountSubscriptionManager::class);

        $manager->recordPaymentEvent($sub, [
            'id' => 'tr_ok',
            'status' => 'paid',
            'subscriptionId' => 'sub_paid',
        ]);

        $fresh = $sub->fresh();
        $this->assertSame(SubscriptionStatus::Active, $fresh->status);
        $this->assertSame('paid', $fresh->last_payment_status);
        $this->assertNotNull($fresh->last_webhook_event_at);
    }

    public function test_failure_other_than_mandate_invalid_does_not_pause(): void
    {
        [, , , $connection] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        $sub = AccountSubscription::factory()
            ->forConnection($connection)
            ->active()
            ->create(['mollie_subscription_id' => 'sub_other']);

        $manager = $this->app->make(AccountSubscriptionManager::class);

        $manager->recordPaymentEvent($sub, [
            'id' => 'tr_insuff',
            'status' => 'failed',
            'subscriptionId' => 'sub_other',
            'details' => ['failureReason' => 'insufficient_funds'],
        ]);

        $fresh = $sub->fresh();
        $this->assertSame(SubscriptionStatus::Active, $fresh->status);
        $this->assertNull($fresh->paused_at);
        $this->assertSame('failed_insufficient_funds', $fresh->last_payment_status);
    }
}
