<?php

declare(strict_types=1);

namespace App\Integrations\Exact\Accounting;

use App\Models\Connection;
use App\Models\ConnectionAccountingRef;
use App\Integrations\Exact\ExactReferenceData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Spiegelt de stabiele Exact-referentiedata (grootboek, BTW-codes, dagboeken,
 * kostenplaatsen, kostendragers) van één Connection naar `connection_accounting_refs`. De
 * boeking resolvet daarna code→native_id lokaal tegen deze mirror — geen live partner-call
 * op het schrijfpad.
 *
 * Idempotent: upsert op `(connection, kind, code)` + prune van weggevallen
 * gl/vat/journal/cost_center/cost_unit-rijen (Code verdwenen in Exact → uit de mirror).
 * Relaties (kind=relation) worden lazy bijgevuld door de resolver en hier níét aangeraakt.
 */
final class ExactReferenceSync
{
    /**
     * @return int aantal gesynct rijen
     */
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

            // Prune: gl/vat/journal-Codes die in Exact zijn verdwenen (niet in deze run gezien).
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
