<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Integrations\Webhooks\CanonicalEvent;
use App\Jobs\Webhooks\ForwardConnectionRevokedToConsumerJob;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Spatie\WebhookServer\CallWebhookJob;
use Tests\TestCase;

class ForwardConnectionRevokedToConsumerJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_dispatches_connection_revoked_payload_to_consumer(): void
    {
        Bus::fake([CallWebhookJob::class]);

        $consumer = Consumer::factory()->withWebhookCallback(url: 'https://consumer.test/hooks')->create();
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()->forExact()->for($account)->create([
            'status' => 'revoked',
            'revoked_at' => now(),
        ]);

        (new ForwardConnectionRevokedToConsumerJob($connection, 'exact_app_center', 'evt-revoked-1'))->handle();

        Bus::assertDispatched(CallWebhookJob::class, function (CallWebhookJob $job) use ($connection, $account): bool {
            return $job->webhookUrl === 'https://consumer.test/hooks'
                && $job->payload['event'] === CanonicalEvent::CONNECTION_REVOKED
                && $job->payload['provider'] === 'exact'
                // Dezelfde envelope als elke andere consumer-webhook: de tenant-sleutel
                // heet overal `account_id`, de eventspecifieke velden staan in `data`.
                && $job->payload['account_id'] === $account->external_id
                && is_string($job->payload['occurred_at'])
                && $job->payload['data']['connection_id'] === $connection->id
                && $job->payload['data']['source'] === 'exact_app_center'
                && $job->payload['data']['revoked_at'] !== null
                && ($job->headers['X-Emeq-Event-Id'] ?? null) === 'evt-revoked-1';
        });
    }

    public function test_handle_skips_silently_without_callback_url(): void
    {
        Bus::fake([CallWebhookJob::class]);
        Log::shouldReceive('info')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'webhook.fanout_skipped'
                && $context['provider'] === 'exact'
                && $context['event_id'] === 'evt-revoked-2'
                && $context['reason'] === 'callback_url_not_configured');

        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()->forExact()->for($account)->create([
            'status' => 'revoked',
            'revoked_at' => now(),
        ]);

        (new ForwardConnectionRevokedToConsumerJob($connection, 'exact_app_center', 'evt-revoked-2'))->handle();

        Bus::assertNotDispatched(CallWebhookJob::class);
    }
}
