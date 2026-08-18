<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Mollie\Http\AccountSubscriptions;

use App\Billing\Account\SubscriptionStatus;
use App\Jobs\Webhooks\ForwardWebhookToConsumerJob;
use App\Models\AccountSubscription;
use Emeq\MollieApi\Webhooks\MollieWebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mollie\Api\Exceptions\NotFoundException as MollieNotFoundException;
use Tests\Feature\Integrations\Mollie\Concerns\StubsMollieClient;
use Tests\TestCase;

class AccountSubscriptionWebhookFlowTest extends TestCase
{
    use RefreshDatabase;
    use StubsMollieClient;

    private string $secret = 'whsec_test_xyz';

    protected function setUp(): void
    {
        parent::setUp();
        config(['mollie.webhook.secret' => $this->secret]);
    }

    public function test_payment_failed_with_mandate_invalid_transitions_subscription_to_paused(): void
    {
        Bus::fake();

        [, , , $connection] = $this->setupMollieConsumer();

        $sub = AccountSubscription::factory()
            ->forConnection($connection)
            ->active()
            ->create(['mollie_subscription_id' => 'sub_123', 'mollie_customer_id' => 'cst_abc']);

        $this->bindMollieStubs([
            'payments' => fn (string $op, mixed $arg) => $this->makePayment([
                'id' => 'tr_failed',
                'status' => 'failed',
                'subscriptionId' => 'sub_123',
                'details' => ['failureReason' => 'mandate_invalid'],
            ]),
        ]);

        $payload = json_encode(['id' => 'tr_failed'], JSON_THROW_ON_ERROR);
        $signature = MollieWebhookSignature::sign($payload, $this->secret);

        $response = $this->call(
            'POST',
            "/webhooks/mollie/{$connection->id}",
            [], [], [],
            ['HTTP_X_MOLLIE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $payload,
        );

        $response->assertStatus(202);

        $fresh = $sub->fresh();
        $this->assertSame(SubscriptionStatus::Paused, $fresh->status);
        $this->assertNotNull($fresh->paused_at);
        $this->assertSame('failed_mandate_invalid', $fresh->last_payment_status);
        $this->assertNotNull($fresh->last_webhook_event_at);

        $this->assertSame([], $this->mollieCaptured['subscription_cancel_for_id']);
    }

    public function test_subscription_webhook_with_sub_prefix_syncs_state_to_unknown_on_mollie_404(): void
    {
        Bus::fake();

        [, , , $connection] = $this->setupMollieConsumer();

        $sub = AccountSubscription::factory()
            ->forConnection($connection)
            ->active()
            ->create(['mollie_subscription_id' => 'sub_lost', 'mollie_customer_id' => 'cst_lost']);

        $this->bindMollieStubs([
            'subscriptions' => fn (string $op, mixed $arg) => $this->fakeMollieNotFoundException(),
        ]);

        $payload = json_encode(['id' => 'sub_lost'], JSON_THROW_ON_ERROR);
        $signature = MollieWebhookSignature::sign($payload, $this->secret);

        $response = $this->call(
            'POST',
            "/webhooks/mollie/{$connection->id}",
            [], [], [],
            ['HTTP_X_MOLLIE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $payload,
        );

        $response->assertStatus(202);
        $this->assertSame(SubscriptionStatus::Unknown, $sub->fresh()->status);
    }

    public function test_paid_payment_recurring_updates_last_payment_status_without_state_flip(): void
    {
        Bus::fake();

        [, , , $connection] = $this->setupMollieConsumer();

        $sub = AccountSubscription::factory()
            ->forConnection($connection)
            ->active()
            ->create([
                'mollie_subscription_id' => 'sub_retry',
                'mollie_customer_id' => 'cst_retry',
                'last_payment_status' => 'failed_insufficient_funds',
            ]);

        $this->bindMollieStubs([
            'payments' => fn (string $op, mixed $arg) => $this->makePayment([
                'id' => 'tr_paid_retry',
                'status' => 'paid',
                'subscriptionId' => 'sub_retry',
            ]),
        ]);

        $payload = json_encode(['id' => 'tr_paid_retry'], JSON_THROW_ON_ERROR);
        $signature = MollieWebhookSignature::sign($payload, $this->secret);

        $response = $this->call(
            'POST',
            "/webhooks/mollie/{$connection->id}",
            [], [], [],
            ['HTTP_X_MOLLIE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $payload,
        );

        $response->assertStatus(202);
        $fresh = $sub->fresh();
        $this->assertSame(SubscriptionStatus::Active, $fresh->status);
        $this->assertSame('paid', $fresh->last_payment_status);
        $this->assertNotNull($fresh->last_webhook_event_at);
    }

    public function test_tampered_signature_returns_400_without_state_mutation(): void
    {
        Bus::fake();

        [, , , $connection] = $this->setupMollieConsumer();

        $sub = AccountSubscription::factory()
            ->forConnection($connection)
            ->active()
            ->create(['mollie_subscription_id' => 'sub_tamper', 'mollie_customer_id' => 'cst_tamper']);

        $payload = json_encode(['id' => 'sub_tamper'], JSON_THROW_ON_ERROR);
        $tampered = MollieWebhookSignature::sign($payload, 'wrong_secret');

        $response = $this->call(
            'POST',
            "/webhooks/mollie/{$connection->id}",
            [], [], [],
            ['HTTP_X_MOLLIE_SIGNATURE' => $tampered, 'CONTENT_TYPE' => 'application/json'],
            $payload,
        );

        $response->assertStatus(400)
            ->assertJsonPath('error', 'invalid_signature');

        $this->assertSame(SubscriptionStatus::Active, $sub->fresh()->status);
        Bus::assertNotDispatched(ForwardWebhookToConsumerJob::class);
    }

    private function fakeMollieNotFoundException(): MollieNotFoundException
    {
        $reflection = new \ReflectionClass(MollieNotFoundException::class);
        /** @var MollieNotFoundException $ex */
        $ex = $reflection->newInstanceWithoutConstructor();

        $messageProp = (new \ReflectionClass(\Exception::class))->getProperty('message');
        $messageProp->setAccessible(true);
        $messageProp->setValue($ex, 'Subscription not found at Mollie');

        $codeProp = (new \ReflectionClass(\Exception::class))->getProperty('code');
        $codeProp->setAccessible(true);
        $codeProp->setValue($ex, 404);

        return $ex;
    }

    public function test_unknown_subscription_id_with_sub_prefix_returns_202_no_state_mutation(): void
    {
        Bus::fake();

        [, , , $connection] = $this->setupMollieConsumer();

        $this->bindMollieStubs([
            'subscriptions' => fn (string $op, mixed $arg) => $this->fail(
                "Mollie subscriptions->{$op} mocht niet aangeroepen worden voor onbekende sub_*-id.",
            ),
        ]);

        $payload = json_encode(['id' => 'sub_orphan'], JSON_THROW_ON_ERROR);
        $signature = MollieWebhookSignature::sign($payload, $this->secret);

        $response = $this->call(
            'POST',
            "/webhooks/mollie/{$connection->id}",
            [], [], [],
            ['HTTP_X_MOLLIE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $payload,
        );

        $response->assertStatus(202);
        $this->assertSame(0, AccountSubscription::query()->count(), 'Geen AccountSubscription mag aangemaakt zijn.');
        $this->assertSame([], $this->mollieCaptured['subscription_get_for_id']);
    }
}
