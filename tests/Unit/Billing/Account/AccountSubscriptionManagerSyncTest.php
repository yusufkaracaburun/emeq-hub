<?php

declare(strict_types=1);

namespace Tests\Unit\Billing\Account;

use App\Integrations\Mollie\Billing\AccountSubscriptionManager;
use App\Billing\Account\SubscriptionStatus;
use App\Models\AccountSubscription;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Exceptions\NotFoundException as MollieNotFoundException;
use Mollie\Api\Http\Response as MollieResponse;
use Tests\Concerns\StubsMollieClient;
use Tests\TestCase;

class AccountSubscriptionManagerSyncTest extends TestCase
{
    use RefreshDatabase;
    use StubsMollieClient;

    public function test_sync_with_mollie_404_transitions_to_unknown(): void
    {
        [, , , $connection] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_READ]);

        $sub = AccountSubscription::factory()
            ->forConnection($connection)
            ->active()
            ->create([
                'mollie_subscription_id' => 'sub_lost',
                'mollie_customer_id' => 'cst_lost',
            ]);

        $this->bindMollieStubs([
            'subscriptions' => fn (string $op, mixed $arg) => $this->fakeMollieNotFoundException(),
        ]);

        $manager = $this->app->make(AccountSubscriptionManager::class);

        $manager->syncFromMollie($sub);

        $this->assertSame(SubscriptionStatus::Unknown, $sub->fresh()->status);
    }

    public function test_sync_with_mollie_canceled_transitions_to_canceled(): void
    {
        [, , , $connection] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_READ]);

        $sub = AccountSubscription::factory()
            ->forConnection($connection)
            ->active()
            ->create([
                'mollie_subscription_id' => 'sub_cancel',
                'mollie_customer_id' => 'cst_cancel',
            ]);

        $this->bindMollieStubs([
            'subscriptions' => fn (string $op, mixed $arg) => $this->makeSubscription([
                'id' => 'sub_cancel',
                'status' => 'canceled',
                'customerId' => $arg['customer_id'],
            ]),
        ]);

        $manager = $this->app->make(AccountSubscriptionManager::class);

        $manager->syncFromMollie($sub);

        $fresh = $sub->fresh();
        $this->assertSame(SubscriptionStatus::Canceled, $fresh->status);
        $this->assertNotNull($fresh->canceled_at);
    }

    private function fakeMollieNotFoundException(): MollieNotFoundException
    {
        $reflection = new \ReflectionClass(MollieNotFoundException::class);
        /** @var MollieNotFoundException $ex */
        $ex = $reflection->newInstanceWithoutConstructor();
        $parent = new \ReflectionClass(ApiException::class);
        $messageProp = (new \ReflectionClass(\Exception::class))->getProperty('message');
        $messageProp->setAccessible(true);
        $messageProp->setValue($ex, 'subscription not found (test)');
        $codeProp = (new \ReflectionClass(\Exception::class))->getProperty('code');
        $codeProp->setAccessible(true);
        $codeProp->setValue($ex, 404);

        if ($parent->hasProperty('response')) {
            $responseProp = $parent->getProperty('response');
            $responseProp->setAccessible(true);
            try {
                $responseProp->setValue($ex, $this->buildMinimalMollieResponse());
            } catch (\Throwable) {
            }
        }

        return $ex;
    }

    private function buildMinimalMollieResponse(): ?MollieResponse
    {
        if (! class_exists(MollieResponse::class)) {
            return null;
        }

        $reflection = new \ReflectionClass(MollieResponse::class);

        try {
            /** @var MollieResponse $response */
            $response = $reflection->newInstanceWithoutConstructor();

            return $response;
        } catch (\Throwable) {
            return null;
        }
    }
}
