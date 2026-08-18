<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Mollie\Billing;

use App\Integrations\Mollie\Billing\AccountSubscriptionManager;
use App\Integrations\Mollie\Billing\Dto\CreateAccountSubscriptionDto;
use App\Billing\Account\SubscriptionStatus;
use App\Models\AccountSubscription;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mollie\Api\Exceptions\ApiException as MollieApiException;
use Tests\Feature\Integrations\Mollie\Concerns\StubsMollieClient;
use Tests\TestCase;

class AccountSubscriptionManagerCreateTest extends TestCase
{
    use RefreshDatabase;
    use StubsMollieClient;

    public function test_create_persists_pending_then_transitions_to_active_after_mollie_succeeds(): void
    {
        [, , $account, $connection] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        $this->bindMollieStubs([
            'subscriptions' => function (string $op, mixed $arg) {
                $this->assertSame('createForId', $op);
                $this->assertIsArray($arg);
                $this->assertSame('cst_abc', $arg['customer_id']);

                return $this->makeSubscription([
                    'id' => 'sub_new',
                    'status' => 'active',
                    'customerId' => $arg['customer_id'],
                ]);
            },
        ]);

        $manager = $this->app->make(AccountSubscriptionManager::class);

        $sub = $manager->create($account, $connection, $this->dtoFor('cst_abc'));

        $this->assertInstanceOf(AccountSubscription::class, $sub);
        $this->assertSame(SubscriptionStatus::Active, $sub->status);
        $this->assertSame('sub_new', $sub->mollie_subscription_id);
        $this->assertNotNull($sub->starts_at);
        $this->assertSame('cst_abc', $sub->mollie_customer_id);
        $this->assertCount(1, $this->mollieCaptured['subscription_create_for_id']);
    }

    public function test_create_forwards_idempotency_key_to_mollie_client(): void
    {
        [, , $account, $connection] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        $this->bindMollieStubs([
            'subscriptions' => fn (string $op, mixed $arg) => $this->makeSubscription([
                'id' => 'sub_idem',
                'status' => 'active',
                'customerId' => $arg['customer_id'],
            ]),
        ]);

        $manager = $this->app->make(AccountSubscriptionManager::class);

        $manager->create(
            $account,
            $connection,
            $this->dtoFor('cst_xyz'),
            idempotencyKey: 'uuid-v7-test-01HXXX',
        );

        $captured = $this->mollieCaptured['idempotency_keys'];
        $this->assertNotEmpty($captured, 'Verwacht minstens één captured idempotency-key.');
        $this->assertSame('uuid-v7-test-01HXXX', end($captured));
    }

    public function test_create_leaves_hub_row_in_pending_when_mollie_throws_validation_exception(): void
    {
        [, , $account, $connection] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        $this->bindMollieStubs([
            'subscriptions' => fn (string $op, mixed $arg) => $this->fakeMollieValidationException(),
        ]);

        $manager = $this->app->make(AccountSubscriptionManager::class);

        try {
            $manager->create($account, $connection, $this->dtoFor('cst_bad'));
            $this->fail('Expected MollieApiException to bubble up.');
        } catch (MollieApiException $e) {
            $this->assertStringContainsString('validation failed', $e->getMessage());
        }

        $row = AccountSubscription::query()->first();
        $this->assertNotNull($row, 'Hub-row moet als evidence bestaan, ook na Mollie-422.');
        $this->assertSame(SubscriptionStatus::Pending, $row->status);
        $this->assertNull($row->mollie_subscription_id);
    }

    private function fakeMollieValidationException(): MollieApiException
    {
        $reflection = new \ReflectionClass(MollieApiException::class);
        /** @var MollieApiException $ex */
        $ex = $reflection->newInstanceWithoutConstructor();

        $messageProp = (new \ReflectionClass(\Exception::class))->getProperty('message');
        $messageProp->setAccessible(true);
        $messageProp->setValue($ex, 'Mollie SDK: amount.value validation failed');

        $codeProp = (new \ReflectionClass(\Exception::class))->getProperty('code');
        $codeProp->setAccessible(true);
        $codeProp->setValue($ex, 422);

        return $ex;
    }

    private function dtoFor(string $customerId): CreateAccountSubscriptionDto
    {
        return new CreateAccountSubscriptionDto(
            mollieCustomerId: $customerId,
            mollieMandateId: null,
            amountCurrency: 'EUR',
            amountValue: '12.50',
            interval: '1 month',
            description: 'Test bijdrage',
            times: null,
            startDate: null,
            metadata: null,
        );
    }
}
