<?php

declare(strict_types=1);

namespace App\Jobs\Webhooks;

use App\Integrations\Webhooks\ConsumerWebhookHeaders;
use App\Models\Connection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Spatie\WebhookServer\WebhookCall;

/**
 * Fan-out van een Hub-eigen `connection.revoked`-event naar de Consumer-
 * callback-URL. Zonder dit signaal weet de consumer-app niet dat de koppeling
 * weg is en loopt die stuk op de revoked-guard bij de volgende pass-through.
 *
 * Provider-agnostisch: elke revoke-bron (App Center-deprovision, admin-actie,
 * toekomstige partner-flows) kan dezelfde job dispatchen met een eigen `source`.
 */
final class ForwardConnectionRevokedToConsumerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Connection $revokedConnection,
        public string $source,
        public string $eventId,
    ) {
        $this->onQueue('webhooks');
    }

    public function handle(): void
    {
        $account = $this->revokedConnection->account;
        $consumer = $account?->consumer;

        if ($consumer === null || ! $consumer->webhook_callback_url) {
            Log::info('webhook.fanout_skipped', [
                'provider' => $this->revokedConnection->provider->value,
                'connection_id' => $this->revokedConnection->id,
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
            ->payload([
                'event' => 'connection.revoked',
                'provider' => $this->revokedConnection->provider->value,
                'connection_id' => $this->revokedConnection->id,
                'account_external_id' => $account->external_id,
                'source' => $this->source,
                'revoked_at' => $this->revokedConnection->revoked_at?->toIso8601String(),
            ])
            ->useSecret((string) $consumer->webhook_callback_secret)
            ->withHeaders(ConsumerWebhookHeaders::make($this->eventId))
            ->dispatch();
    }
}
