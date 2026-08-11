<?php

declare(strict_types=1);

namespace App\Jobs\Webhooks;

use App\Enums\Provider;
use App\Models\Connection;
use App\Webhooks\ConsumerWebhookHeaders;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Spatie\WebhookServer\WebhookCall;

/**
 * Fan-out van een geverifieerde Mollie-webhook naar de Consumer-callback-URL.
 *
 * Consumer moet `webhook_callback_url` + `webhook_callback_secret` (encrypted)
 * geconfigureerd hebben (Plan 05a-01 schema). Geen URL → silent skip (geen retry).
 * Spatie's webhook-server doet retry/backoff per zijn config defaults.
 */
final class ForwardMollieWebhookToConsumerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public Connection $mollieConnection,
        public array $payload,
    ) {}

    public function handle(): void
    {
        $consumer = $this->mollieConnection->account?->consumer;

        if ($consumer === null || ! $consumer->webhook_callback_url) {
            Log::info('webhook.fanout_skipped', [
                'provider' => Provider::Mollie->value,
                'connection_id' => $this->mollieConnection->id,
                'consumer_id' => $consumer?->id,
                'reason' => $consumer === null
                    ? 'consumer_chain_missing'
                    : 'callback_url_not_configured',
            ]);

            return;
        }

        WebhookCall::create()
            ->url($consumer->webhook_callback_url)
            ->payload($this->payload)
            ->useSecret((string) $consumer->webhook_callback_secret)
            ->withHeaders(ConsumerWebhookHeaders::make())
            ->dispatch();
    }
}
