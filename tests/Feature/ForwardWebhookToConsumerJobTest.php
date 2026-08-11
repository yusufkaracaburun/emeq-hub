<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Provider;
use App\Jobs\Webhooks\ForwardWebhookToConsumerJob;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Spatie\WebhookServer\BackoffStrategy\ExponentialBackoffStrategy;
use Spatie\WebhookServer\CallWebhookJob;
use Tests\TestCase;

class ForwardWebhookToConsumerJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_dispatches_to_webhooks_queue(): void
    {
        Bus::fake([ForwardWebhookToConsumerJob::class]);

        $consumer = Consumer::factory()->withWebhookCallback()->create();
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()->forSnelstart()->active()->for($account)->create();

        ForwardWebhookToConsumerJob::dispatch(
            Provider::Snelstart,
            $connection,
            ['administratieId' => $connection->administratie_id, 'type' => 'Relatie.Created'],
            'evt-queue-1',
        );

        Bus::assertDispatched(
            ForwardWebhookToConsumerJob::class,
            fn (ForwardWebhookToConsumerJob $job): bool => $job->queue === 'webhooks',
        );
    }

    public function test_handle_skips_silently_without_callback_url(): void
    {
        Bus::fake([CallWebhookJob::class]);
        Log::shouldReceive('info')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'webhook.fanout_skipped'
                && $context['provider'] === 'snelstart'
                && $context['event_id'] === 'evt-no-callback'
                && $context['reason'] === 'callback_url_not_configured');

        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()->forSnelstart()->active()->for($account)->create();

        (new ForwardWebhookToConsumerJob(
            Provider::Snelstart,
            $connection,
            ['administratieId' => $connection->administratie_id],
            'evt-no-callback',
        ))->handle();

        Bus::assertNotDispatched(CallWebhookJob::class);
    }

    public function test_handle_dispatches_spatie_webhook_with_consumer_secret(): void
    {
        Bus::fake([CallWebhookJob::class]);

        $consumer = Consumer::factory()->withWebhookCallback(
            url: 'https://consumer.test/snelstart',
            secret: 'consumer-secret-abc',
        )->create();
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()->forSnelstart()->active()->for($account)->create();

        $payload = ['administratieId' => $connection->administratie_id, 'type' => 'Verkoopfactuur.Created'];

        (new ForwardWebhookToConsumerJob(Provider::Snelstart, $connection, $payload, 'evt-with-secret'))->handle();

        Bus::assertDispatched(CallWebhookJob::class, function (CallWebhookJob $job) use ($payload): bool {
            $signatureHeader = config('webhook-server.signature_header_name', 'Signature');
            $expectedSignature = hash_hmac('sha256', json_encode($payload), 'consumer-secret-abc');

            return $job->webhookUrl === 'https://consumer.test/snelstart'
                && $job->payload === $payload
                && ($job->headers[$signatureHeader] ?? null) === $expectedSignature;
        });
    }

    public function test_handle_includes_event_id_header(): void
    {
        Bus::fake([CallWebhookJob::class]);

        $consumer = Consumer::factory()->withWebhookCallback()->create();
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()->forSnelstart()->active()->for($account)->create();

        (new ForwardWebhookToConsumerJob(
            Provider::Snelstart,
            $connection,
            ['administratieId' => $connection->administratie_id],
            'evt-001',
        ))->handle();

        Bus::assertDispatched(CallWebhookJob::class, function (CallWebhookJob $job): bool {
            return ($job->headers['X-Emeq-Event-Id'] ?? null) === 'evt-001';
        });
    }

    public function test_fanout_carries_the_durability_retry_policy(): void
    {
        Bus::fake([CallWebhookJob::class]);

        $consumer = Consumer::factory()->withWebhookCallback()->create();
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()->forSnelstart()->active()->for($account)->create();

        (new ForwardWebhookToConsumerJob(
            Provider::Snelstart,
            $connection,
            ['administratieId' => $connection->administratie_id],
            'evt-retry-policy',
        ))->handle();

        Bus::assertDispatched(CallWebhookJob::class, function (CallWebhookJob $job): bool {
            return $job->tries === 5
                && $job->backoffStrategyClass === ExponentialBackoffStrategy::class
                && $job->requestTimeout === 3;
        });
    }

    public function test_handle_uses_consumer_callback_secret_not_partner_secret(): void
    {
        Bus::fake([CallWebhookJob::class]);

        config(['snelstart.webhook.secret' => 'partner-only']);

        $consumer = Consumer::factory()->withWebhookCallback(
            url: 'https://consumer.test/snelstart',
            secret: 'consumer-only',
        )->create();
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()->forSnelstart()->active()->for($account)->create();

        $payload = ['administratieId' => $connection->administratie_id];

        (new ForwardWebhookToConsumerJob(Provider::Snelstart, $connection, $payload, 'evt-anti-corr'))->handle();

        Bus::assertDispatched(CallWebhookJob::class, function (CallWebhookJob $job) use ($payload): bool {
            $signatureHeader = config('webhook-server.signature_header_name', 'Signature');
            $signature = $job->headers[$signatureHeader] ?? null;

            $expectedConsumer = hash_hmac('sha256', json_encode($payload), 'consumer-only');
            $unexpectedPartner = hash_hmac('sha256', json_encode($payload), 'partner-only');

            return $signature === $expectedConsumer && $signature !== $unexpectedPartner;
        });
    }
}
