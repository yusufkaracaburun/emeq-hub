<?php

declare(strict_types=1);

namespace App\Jobs\Webhooks;

use App\Enums\Provider;
use App\Models\Connection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Spatie\WebhookServer\WebhookCall;

/**
 * Fan-out van een geverifieerde Exact-webhook naar de Consumer-callback-URL.
 *
 * Anti-correlation: outbound HMAC gebruikt `consumers.webhook_callback_secret`
 * (per-Consumer, encrypted). Exact's app-brede inbound-secret komt hier nooit langs.
 *
 * Consumer zonder `webhook_callback_url` → silent skip (geen retry).
 * Spatie's webhook-server doet retry/backoff per `config/webhook-server.php`.
 */
final class ForwardExactWebhookToConsumerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public Connection $exactConnection,
        public array $payload,
        public string $eventId,
    ) {
        $this->onQueue('webhooks');
    }

    public function handle(): void
    {
        $consumer = $this->exactConnection->account?->consumer;

        if ($consumer === null || ! $consumer->webhook_callback_url) {
            Log::info('webhook.fanout_skipped', [
                'provider' => Provider::Exact->value,
                'connection_id' => $this->exactConnection->id,
                'consumer_id' => $consumer?->id,
                'event_id' => $this->eventId,
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
            ->withHeaders(['X-Emeq-Event-Id' => $this->eventId])
            ->dispatch();
    }
}
