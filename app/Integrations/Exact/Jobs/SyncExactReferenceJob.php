<?php

declare(strict_types=1);

namespace App\Integrations\Exact\Jobs;

use App\Accounting\AccountingTargetRegistry;
use App\Models\Connection;
use App\Integrations\OAuth\Exceptions\ProviderDisabledException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Spiegelt de referentiedata (grootboek/btw/dagboeken) van een Connection naar
 * `connection_accounting_refs` en leidt de default-mapping af. Async ná OAuth-connect
 * zodat de callback niet blokkeert op de reference-fetches; ook handmatig herbruikbaar
 * via POST /v1/accounting/sync.
 *
 * Loopt via de capability in plaats van rechtstreeks langs de Exact-klassen, zodat de
 * volgorde spiegelen-dan-afleiden op één plek staat.
 */
final class SyncExactReferenceJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public Connection $exactConnection) {}

    public function handle(AccountingTargetRegistry $registry): void
    {
        try {
            $registry->syncsReferenceData($this->exactConnection)?->syncReferences($this->exactConnection);
        } catch (ProviderDisabledException) {
            // De provider is uitgezet tussen connect en uitvoering. Dat is precies wat de
            // kill-switch hoort te doen — geen exception die de job laat retryen en in
            // failed_jobs eindigt.
            Log::info('accounting.reference_sync_skipped', [
                'connection_id' => $this->exactConnection->id,
                'provider' => $this->exactConnection->provider->value,
                'reason' => 'provider_disabled',
            ]);
        }
    }
}
