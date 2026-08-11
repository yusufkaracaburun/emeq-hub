<?php

declare(strict_types=1);

namespace App\Jobs\Webhooks;

use App\Enums\Provider;
use App\Models\Connection;
use App\Webhooks\CanonicalEventRegistry;
use App\Webhooks\ConsumerWebhookHeaders;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Spatie\WebhookServer\WebhookCall;

/**
 * Fan-out van een geverifieerde partner-webhook naar de callback-URL van de
 * Consumer achter deze Connection.
 *
 * Eén job voor alle providers. Er stonden er drie, per provider één, en die
 * waren regel voor regel gelijk op de providernaam na — met als gevolg dat ze
 * uit elkaar liepen zodra er één werd aangepast.
 *
 * Anti-correlatie: de outbound HMAC gebruikt `consumers.webhook_callback_secret`
 * (per Consumer, encrypted). Het inbound secret van de partner komt hier nooit
 * langs. Consumer zonder `webhook_callback_url` → stille skip, geen retry;
 * retry/backoff van de aflevering zelf doet spatie's webhook-server.
 */
final class ForwardWebhookToConsumerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * `$providerConnection` en niet `$connection`: `Queueable` heeft zelf al een
     * `$connection` (de queue-connectie) en PHP weigert de compositie bij een
     * gelijknamige property. Niet "opschonen" naar de kortere naam.
     *
     * @param  array<string, mixed>  $payload
     * @param  string|null  $queue  `webhooks` heeft een eigen Horizon-supervisor; `null`
     *                              zet de job op de default-queue. Dat is een
     *                              capaciteitskeuze en hoort daarom bij de aanroeper,
     *                              niet in deze job.
     */
    public function __construct(
        public Provider $provider,
        public Connection $providerConnection,
        public array $payload,
        public ?string $eventId = null,
        ?string $queue = 'webhooks',
    ) {
        if ($queue !== null) {
            $this->onQueue($queue);
        }
    }

    public function handle(CanonicalEventRegistry $events): void
    {
        $account = $this->providerConnection->account;
        $consumer = $account?->consumer;

        if ($consumer === null || ! $consumer->webhook_callback_url) {
            Log::info('webhook.fanout_skipped', [
                'provider' => $this->provider->value,
                'connection_id' => $this->providerConnection->id,
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
                'event' => $events->eventFor($this->provider, $this->payload),
                'provider' => $this->provider->value,
                // Het id dat de consumer zelf aanleverde bij het koppelen — die kent
                // z'n eigen `X-Account-Id`, niet onze primary key.
                'account_id' => $account->external_id,
                // Wanneer de Hub het event uitstuurde. De partner levert zelden een
                // eigen tijdstempel; doen alsof van wel zou liegen over de bron.
                'occurred_at' => now()->toIso8601String(),
                'data' => $this->payload,
            ])
            ->useSecret((string) $consumer->webhook_callback_secret)
            ->withHeaders(ConsumerWebhookHeaders::make($this->eventId))
            ->dispatch();
    }
}
