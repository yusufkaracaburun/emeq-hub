<?php

declare(strict_types=1);

namespace App\Accounting\Exact;

use App\Accounting\Party;
use App\Models\Connection;
use App\Models\ConnectionAccountingRef;
use App\Services\Exact\ExactReferenceData;

/**
 * Lazy relatie-resolutie (de volatiele set — nul consumer-upkeep): map `party.external_id`
 * → Exact-relatie-GUID via de mirror; bij een miss match op de party-data (VATNumber, anders
 * Name) en leer de link in de mirror. Niet gevonden → null (de caller geeft een 422).
 *
 * Auto-create van een ontbrekende relatie is Fase 1b (opt-in) — hier nog niet.
 */
final class ExactRelationResolver
{
    public function resolve(Party $party, Connection $connection): ?string
    {
        $externalId = $party->externalId;

        if ($externalId !== null && $externalId !== '') {
            $hit = ConnectionAccountingRef::query()
                ->where('connection_id', $connection->getKey())
                ->where('kind', ConnectionAccountingRef::KIND_RELATION)
                ->where('code', $externalId)
                ->first();

            if ($hit !== null) {
                return $hit->native_id;
            }
        }

        $match = (new ExactReferenceData($connection))->findRelation($party->vatNumber, $party->name);

        if ($match === null) {
            return null;
        }

        if ($externalId !== null && $externalId !== '') {
            ConnectionAccountingRef::query()->updateOrCreate(
                [
                    'connection_id' => $connection->getKey(),
                    'kind' => ConnectionAccountingRef::KIND_RELATION,
                    'code' => $externalId,
                ],
                [
                    'native_id' => $match['id'],
                    'label' => $match['name'] !== '' ? $match['name'] : null,
                    'synced_at' => now(),
                ],
            );
        }

        return $match['id'];
    }
}
