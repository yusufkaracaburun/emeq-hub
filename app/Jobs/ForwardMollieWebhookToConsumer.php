<?php

namespace App\Jobs;

use App\Models\Connection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\WebhookServer\WebhookCall;

/**
 * Fan-out van een geverifieerde Mollie-webhook naar de Consumer-callback-URL.
 *
 * Consumer moet `webhook_callback_url` + `webhook_callback_secret` (encrypted)
 * geconfigureerd hebben (Plan 05a-01 schema). Geen URL → silent skip (geen retry).
 * Spatie's webhook-server doet retry/backoff per zijn config defaults.
 */
class ForwardMollieWebhookToConsumer implements ShouldQueue
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
            return;
        }

        WebhookCall::create()
            ->url($consumer->webhook_callback_url)
            ->payload($this->payload)
            ->useSecret((string) $consumer->webhook_callback_secret)
            ->dispatch();
    }
}
