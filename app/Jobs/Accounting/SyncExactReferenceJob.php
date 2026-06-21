<?php

declare(strict_types=1);

namespace App\Jobs\Accounting;

use App\Accounting\Exact\ExactMappingDeriver;
use App\Accounting\Exact\ExactReferenceSync;
use App\Models\Connection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Spiegelt de Exact-referentiedata (grootboek/BTW/dagboeken) van een Connection naar
 * `connection_accounting_refs`. Async ná OAuth-connect zodat de callback niet blokkeert
 * op de reference-fetches; ook handmatig herbruikbaar via POST /v1/accounting/sync.
 */
final class SyncExactReferenceJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public Connection $exactConnection) {}

    public function handle(ExactReferenceSync $sync, ExactMappingDeriver $deriver): void
    {
        $sync->sync($this->exactConnection);
        $deriver->deriveAndStore($this->exactConnection);
    }
}
