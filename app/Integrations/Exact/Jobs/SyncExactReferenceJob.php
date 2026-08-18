<?php

declare(strict_types=1);

namespace App\Integrations\Exact\Jobs;

use App\Accounting\AccountingTargetRegistry;
use App\Integrations\Exceptions\ProviderDisabledException;
use App\Models\Connection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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
            Log::info('accounting.reference_sync_skipped', [
                'connection_id' => $this->exactConnection->id,
                'provider' => $this->exactConnection->provider->value,
                'reason' => 'provider_disabled',
            ]);
        }
    }
}
