<?php

namespace Tests\Feature\Webhooks;

use App\Jobs\Webhooks\ForwardMollieWebhookToConsumerJob;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use Emeq\MollieApi\Mollie;
use Emeq\MollieApi\Webhooks\MollieWebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Api\MollieApiClient;
use Spatie\WebhookServer\CallWebhookJob;
use Tests\TestCase;

class MollieWebhookFanOutTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'whsec_test_xyz';

    protected function setUp(): void
    {
        parent::setUp();
        config(['mollie.webhook.secret' => $this->secret]);
    }

    public function test_valid_webhook_dispatches_forward_job_with_connection_and_payload(): void
    {
        Bus::fake();
        $this->fakeMolliePaymentGet('tr_fanout_1');

        $consumer = Consumer::factory()->withWebhookCallback()->create();
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()->forMollie()->active()->for($account)->create();

        $payload = json_encode(['id' => 'tr_fanout_1']);
        $signature = MollieWebhookSignature::sign($payload, $this->secret);

        $response = $this->call(
            'POST',
            "/webhooks/mollie/{$connection->id}",
            [], [], [],
            ['HTTP_X_MOLLIE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $payload,
        );

        $response->assertStatus(202);

        Bus::assertDispatched(
            ForwardMollieWebhookToConsumerJob::class,
            fn (ForwardMollieWebhookToConsumerJob $job) => $job->mollieConnection->id === $connection->id
                && $job->payload['id'] === 'tr_fanout_1',
        );
    }

    public function test_forward_job_handle_calls_spatie_webhook_server_with_consumer_callback(): void
    {
        Queue::fake();

        $consumer = Consumer::factory()->withWebhookCallback(
            url: 'https://consumer.test/hooks',
            secret: 'consumer_secret_123',
        )->create();
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()->forMollie()->active()->for($account)->create();

        $job = new ForwardMollieWebhookToConsumerJob($connection, ['id' => 'tr_handle_1']);
        $job->handle();

        // Spatie's webhook-server dispatcht intern een CallWebhookJob.
        Queue::assertPushed(CallWebhookJob::class, function (CallWebhookJob $pushed) {
            return $pushed->webhookUrl === 'https://consumer.test/hooks'
                && is_array($pushed->payload)
                && ($pushed->payload['id'] ?? null) === 'tr_handle_1';
        });
    }

    public function test_forward_job_silently_returns_when_consumer_has_no_callback_url(): void
    {
        Queue::fake();
        Log::shouldReceive('info')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'webhook.fanout_skipped'
                && $context['provider'] === 'mollie'
                && $context['reason'] === 'callback_url_not_configured');

        // Consumer zonder webhook_callback_url (default state).
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()->forMollie()->active()->for($account)->create();

        $job = new ForwardMollieWebhookToConsumerJob($connection, ['id' => 'tr_no_callback']);
        $job->handle();

        Queue::assertNothingPushed();
    }

    private function fakeMolliePaymentGet(string $id): MollieApiClient
    {
        $fake = MollieApiClient::fake([
            GetPaymentRequest::class => MockResponse::ok([
                'resource' => 'payment',
                'id' => $id,
                'status' => 'paid',
                'mode' => 'test',
                'createdAt' => '2026-01-01T00:00:00+00:00',
                'amount' => ['currency' => 'EUR', 'value' => '10.00'],
                'description' => 'test',
                'redirectUrl' => 'https://example.test/return',
            ]),
        ]);

        $mollie = $this->createMock(Mollie::class);
        $mollie->method('client')->willReturn($fake);
        $this->app->instance(Mollie::class, $mollie);

        return $fake;
    }
}
