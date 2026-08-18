<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Provider;
use App\Integrations\Webhooks\CanonicalAction;
use App\Integrations\Webhooks\CanonicalEntityRegistry;
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
        ))->handle(app(CanonicalEventRegistry::class), app(HubOriginRegistry::class), app(CanonicalEntityRegistry::class));

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

        (new ForwardWebhookToConsumerJob(Provider::Snelstart, $connection, $payload, 'evt-with-secret'))->handle(app(CanonicalEventRegistry::class), app(HubOriginRegistry::class), app(CanonicalEntityRegistry::class));

        Bus::assertDispatched(CallWebhookJob::class, function (CallWebhookJob $job) use ($payload): bool {
            $signatureHeader = config('webhook-server.signature_header_name', 'Signature');
            $expectedSignature = hash_hmac('sha256', json_encode($job->payload), 'consumer-secret-abc');

            return $job->webhookUrl === 'https://consumer.test/snelstart'
                && $job->payload['event'] === CanonicalEvent::SALES_INVOICE_CHANGED
                && $job->payload['provider'] === 'snelstart'
                && $job->payload['data'] === $payload
                && ($job->headers[$signatureHeader] ?? null) === $expectedSignature;
        });
    }

    public function test_handle_marks_an_entity_the_hub_authored(): void
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
            'last_synced_at' => '2026-08-01T10:00:00+00:00',
        ]);

        $payload = ['Content' => ['Topic' => 'SalesEntries', 'Action' => 'Update', 'Key' => 'guid-hub']];

        (new ForwardWebhookToConsumerJob(Provider::Exact, $connection, $payload, 'evt-echo'))
            ->handle(app(CanonicalEventRegistry::class), app(HubOriginRegistry::class), app(CanonicalEntityRegistry::class));

        Bus::assertDispatched(CallWebhookJob::class, function (CallWebhookJob $job): bool {
            return ($job->payload['hub_authored'] ?? null) === true
                && ($job->payload['hub_last_wrote_at'] ?? null) === '2026-08-01T10:00:00+00:00'
                && ($job->payload['entity_id'] ?? null) === 'guid-hub'
                && ($job->payload['action'] ?? null) === CanonicalAction::UPDATED;
        });
    }

    public function test_handle_omits_the_marker_for_an_entity_the_hub_never_wrote(): void
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
            ->handle(app(CanonicalEventRegistry::class), app(HubOriginRegistry::class), app(CanonicalEntityRegistry::class));

        Bus::assertDispatched(CallWebhookJob::class, function (CallWebhookJob $job): bool {
            return ! array_key_exists('hub_authored', $job->payload)
                && ! array_key_exists('hub_last_wrote_at', $job->payload)
                && ($job->payload['entity_id'] ?? null) === 'guid-theirs';
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
        ))->handle(app(CanonicalEventRegistry::class), app(HubOriginRegistry::class), app(CanonicalEntityRegistry::class));

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
        ))->handle(app(CanonicalEventRegistry::class), app(HubOriginRegistry::class), app(CanonicalEntityRegistry::class));

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

        (new ForwardWebhookToConsumerJob(Provider::Snelstart, $connection, $payload, 'evt-anti-corr'))->handle(app(CanonicalEventRegistry::class), app(HubOriginRegistry::class), app(CanonicalEntityRegistry::class));

        Bus::assertDispatched(CallWebhookJob::class, function (CallWebhookJob $job): bool {
            $signatureHeader = config('webhook-server.signature_header_name', 'Signature');
            $signature = $job->headers[$signatureHeader] ?? null;

            $expectedConsumer = hash_hmac('sha256', json_encode($job->payload), 'consumer-only');
            $unexpectedPartner = hash_hmac('sha256', json_encode($job->payload), 'partner-only');

            return $signature === $expectedConsumer && $signature !== $unexpectedPartner;
        });
    }
}
