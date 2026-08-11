<?php

declare(strict_types=1);

namespace App\Accounting;

use App\Models\Connection;
use App\Models\ProviderEntityLink;

/**
 * Leest en schrijft de canonieke ⇄ partner-identiteit. Losgetrokken van
 * {@see AccountingSyncRunner} zodat de runner leesbaar blijft en dit los te testen is.
 */
final readonly class ProviderEntityLinkRecorder
{
    public function find(Connection $connection, string $externalId): ?ProviderEntityLink
    {
        return ProviderEntityLink::query()
            ->where('connection_id', $connection->getKey())
            ->where('entity_type', ProviderEntityLink::ENTITY_FINANCIAL_DOCUMENT)
            ->where('external_id', $externalId)
            ->first();
    }

    /**
     * `updateOrCreate` op de canonieke sleutel: een geforceerde herboeking of een
     * latere ontdekking aan partnerzijde moet op dezelfde rij landen, niet naast.
     */
    public function record(
        FinancialDocument $document,
        Connection $connection,
        AccountingResult $result,
        string $fingerprint,
    ): ProviderEntityLink {
        return ProviderEntityLink::query()->updateOrCreate(
            [
                'connection_id' => $connection->getKey(),
                'entity_type' => ProviderEntityLink::ENTITY_FINANCIAL_DOCUMENT,
                'external_id' => $document->externalId,
            ],
            [
                'provider' => $connection->provider->value,
                'provider_entity_id' => $result->externalRef,
                'provider_entity_number' => $result->externalNumber === null ? null : (string) $result->externalNumber,
                'payload_fingerprint' => $fingerprint,
                'origin' => ProviderEntityLink::ORIGIN_HUB,
                'last_synced_at' => now(),
            ],
        );
    }
}
