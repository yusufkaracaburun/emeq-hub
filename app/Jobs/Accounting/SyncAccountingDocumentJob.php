<?php

declare(strict_types=1);

namespace App\Jobs\Accounting;

use App\Accounting\AccountingSyncRunner;
use App\Accounting\FinancialDocument;
use App\Integrations\Webhooks\CanonicalEvent;
use App\Integrations\Webhooks\ConsumerWebhookEnvelope;
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
            ->payload(ConsumerWebhookEnvelope::make(
                CanonicalEvent::DOCUMENT_SYNCED,
                $this->accountingConnection->provider,
                (string) $this->account->external_id,
                $outcome->responseBody,
            ))
            ->useSecret((string) $consumer->webhook_callback_secret)
            ->withHeaders(ConsumerWebhookHeaders::make((string) Str::uuid()))
            ->dispatch();
    }
}
