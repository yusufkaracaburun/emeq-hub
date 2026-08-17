<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Provider;
use App\Integrations\Webhooks\CanonicalEvent;
use App\Integrations\Webhooks\CanonicalEventRegistry;
use App\Integrations\Webhooks\HubOriginRegistry;
use App\Jobs\Webhooks\ForwardWebhookToConsumerJob;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\ProviderEntityLink;
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
        ))->handle(app(CanonicalEventRegistry::class), app(HubOriginRegistry::class));

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

        (new ForwardWebhookToConsumerJob(Provider::Snelstart, $connection, $payload, 'evt-with-secret'))->handle(app(CanonicalEventRegistry::class), app(HubOriginRegistry::class));

        Bus::assertDispatched(CallWebhookJob::class, function (CallWebhookJob $job) use ($payload): bool {
            $signatureHeader = config('webhook-server.signature_header_name', 'Signature');
            // De HMAC gaat over wat er daadwerkelijk de deur uit gaat: de envelope.
            $expectedSignature = hash_hmac('sha256', json_encode($job->payload), 'consumer-secret-abc');

            return $job->webhookUrl === 'https://consumer.test/snelstart'
                && $job->payload['event'] === CanonicalEvent::SALES_INVOICE_CHANGED
                && $job->payload['provider'] === 'snelstart'
                && $job->payload['data'] === $payload
                && ($job->headers[$signatureHeader] ?? null) === $expectedSignature;
        });
    }

    public function test_handle_marks_a_change_the_hub_itself_caused(): void
    {
        Bus::fake([CallWebhookJob::class]);

        $consumer = Consumer::factory()->withWebhookCallback(
            url: 'https://consumer.test/exact',
            secret: 'consumer-secret-abc',
        )->create();
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()->forExact()->active()->for($account)->create();

        ProviderEntityLink::factory()->create([
            'connection_id' => $connection->id,
            'provider_entity_id' => 'guid-hub',
            'origin' => ProviderEntityLink::ORIGIN_HUB,
        ]);

        $payload = ['Content' => ['Topic' => 'SalesEntries', 'Key' => 'guid-hub']];

        (new ForwardWebhookToConsumerJob(Provider::Exact, $connection, $payload, 'evt-echo'))
            ->handle(app(CanonicalEventRegistry::class), app(HubOriginRegistry::class));

        Bus::assertDispatched(CallWebhookJob::class, fn (CallWebhookJob $job): bool => ($job->payload['caused_by_hub'] ?? null) === true);
    }

    public function test_handle_omits_the_marker_for_a_change_the_hub_did_not_cause(): void
    {
        Bus::fake([CallWebhookJob::class]);

        $consumer = Consumer::factory()->withWebhookCallback(
            url: 'https://consumer.test/exact',
            secret: 'consumer-secret-abc',
        )->create();
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()->forExact()->active()->for($account)->create();

        $payload = ['Content' => ['Topic' => 'BankEntries', 'Key' => 'guid-theirs']];

        (new ForwardWebhookToConsumerJob(Provider::Exact, $connection, $payload, 'evt-no-echo'))
            ->handle(app(CanonicalEventRegistry::class), app(HubOriginRegistry::class));

        Bus::assertDispatched(CallWebhookJob::class, fn (CallWebhookJob $job): bool => ! array_key_exists('caused_by_hub', $job->payload));
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
        ))->handle(app(CanonicalEventRegistry::class), app(HubOriginRegistry::class));

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
        ))->handle(app(CanonicalEventRegistry::class), app(HubOriginRegistry::class));

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

        (new ForwardWebhookToConsumerJob(Provider::Snelstart, $connection, $payload, 'evt-anti-corr'))->handle(app(CanonicalEventRegistry::class), app(HubOriginRegistry::class));

        Bus::assertDispatched(CallWebhookJob::class, function (CallWebhookJob $job): bool {
            $signatureHeader = config('webhook-server.signature_header_name', 'Signature');
            $signature = $job->headers[$signatureHeader] ?? null;

            $expectedConsumer = hash_hmac('sha256', json_encode($job->payload), 'consumer-only');
            $unexpectedPartner = hash_hmac('sha256', json_encode($job->payload), 'partner-only');

            return $signature === $expectedConsumer && $signature !== $unexpectedPartner;
        });
    }
}
