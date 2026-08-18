<?php

declare(strict_types=1);

namespace App\Integrations\Exact\Accounting;

use App\Integrations\Exact\ExactReferenceData;
use App\Models\Connection;
use App\Models\ConnectionAccountingRef;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ExactReferenceSync
{
    /** @return int aantal gesynct rijen */
    public function sync(Connection $connection): int
    {
        $rows = (new ExactReferenceData($connection))->mirrorRows();
        $now = Carbon::now();

        return DB::transaction(function () use ($connection, $rows, $now): int {
            foreach ($rows as $row) {
                ConnectionAccountingRef::query()->updateOrCreate(
                    [
                        'connection_id' => $connection->getKey(),
                        'kind' => $row['kind'],
                        'code' => $row['code'],
                    ],
                    [
                        'native_id' => $row['native_id'],
                        'label' => $row['label'] !== '' ? $row['label'] : null,
                        'attrs' => $row['attrs'],
                        'synced_at' => $now,
                    ],
                );
            }

            ConnectionAccountingRef::query()
                ->where('connection_id', $connection->getKey())
                ->whereIn('kind', [
                    ConnectionAccountingRef::KIND_GL,
                    ConnectionAccountingRef::KIND_VAT,
                    ConnectionAccountingRef::KIND_JOURNAL,
                    ConnectionAccountingRef::KIND_COST_CENTER,
                    ConnectionAccountingRef::KIND_COST_UNIT,
                ])
                ->where('synced_at', '<', $now)
                ->delete();

            return count($rows);
        });
    }
}
