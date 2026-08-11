<?php

declare(strict_types=1);

namespace App\Jobs\Accounting;

use App\Accounting\AccountingSyncRunner;
use App\Accounting\FinancialDocument;
use App\Integrations\Webhooks\ConsumerWebhookHeaders;
use App\Models\Account;
use App\Models\Connection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\WebhookServer\WebhookCall;

/**
 * Async accounting-push: voert de partner-boeking uit en meldt de uitkomst per
 * webhook terug aan de Consumer-callback-URL (anti-correlation: outbound HMAC met
 * `consumers.webhook_callback_secret`, nooit een partner-secret).
 *
 * `$tries = 1`: Exact heeft geen native idempotency-key, dus een retry op de hele
 * job zou de boeking dubbel doen. De `run()` vangt alle fouten af en fire't dan een
 * `failed`-webhook — de job zelf throwt niet, dus tries=1 is genoeg. De webhook-delivery
 * retryt los via spatie/laravel-webhook-server.
 *
 * Consumer zonder `webhook_callback_url` → silent skip (de boeking is geaudit; alleen
 * de terugmelding vervalt). De edge weigert async zonder callback al met 400.
 */
final class SyncAccountingDocumentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public FinancialDocument $document,
        public Connection $accountingConnection,
        public Account $account,
        public int $consumerId,
    ) {
        $this->onQueue('webhooks');
    }

    public function handle(AccountingSyncRunner $runner): void
    {
        $outcome = $runner->run($this->document, $this->accountingConnection, $this->account, $this->consumerId);

        $consumer = $this->account->consumer;

        if ($consumer === null || ! $consumer->webhook_callback_url) {
            Log::info('accounting.result_fanout_skipped', [
                'provider' => $this->accountingConnection->provider->value,
                'connection_id' => $this->accountingConnection->id,
                'consumer_id' => $consumer?->id,
                'external_id' => $this->document->externalId,
                'reason' => $consumer === null
                    ? 'consumer_chain_missing'
                    : 'callback_url_not_configured',
            ]);

            return;
        }

        WebhookCall::create()
            ->url($consumer->webhook_callback_url)
            ->payload(['event' => 'accounting.document.synced', ...$outcome->responseBody])
            ->useSecret((string) $consumer->webhook_callback_secret)
            ->withHeaders(ConsumerWebhookHeaders::make((string) Str::uuid()))
            ->dispatch();
    }
}
