<?php

declare(strict_types=1);

namespace App\Accounting;

use App\Models\Connection;
use App\Models\IdempotencyKey;
use App\Models\ProviderEntityLink;
use Illuminate\Database\UniqueConstraintViolationException;

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
     * Claimt dit `external_id` vóórdat er geboekt wordt, met de unique index als mutex —
     * dezelfde truc als bij de idempotency-key.
     *
     * Nodig omdat de idempotency-key alléén beschermt tegen retries mét dezelfde
     * sleutel. Een client die per poging een verse UUID genereert (een veelgemaakte
     * fout) omzeilt die volledig: twee gelijktijdige requests zagen allebei geen link,
     * boekten allebei, en de tabel ving dat pas achteraf.
     *
     * De claim is een rij zonder `provider_entity_id`. Slaagt de INSERT niet, dan is er
     * al een claim of een echte link en beslist de aanroeper wat dat betekent.
     */
    public function claim(FinancialDocument $document, Connection $connection): ?ProviderEntityLink
    {
        try {
            return ProviderEntityLink::query()->create([
                'connection_id' => $connection->getKey(),
                'entity_type' => ProviderEntityLink::ENTITY_FINANCIAL_DOCUMENT,
                'external_id' => $document->externalId,
                'provider' => $connection->provider->value,
                'provider_entity_id' => null,
                'payload_fingerprint' => null,
                'origin' => ProviderEntityLink::ORIGIN_HUB,
                'last_synced_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * Geeft een claim vrij die niet tot een boeking leidde, zodat een volgende poging
     * mag. Alleen een rij zonder `provider_entity_id` — een echte link blijft staan.
     */
    public function releaseClaim(ProviderEntityLink $link): void
    {
        ProviderEntityLink::query()
            ->whereKey($link->getKey())
            ->whereNull('provider_entity_id')
            ->delete();
    }

    /**
     * Een claim waarvan het request kennelijk gestorven is: geen `provider_entity_id` en
     * ouder dan de idempotency-lease. Dezelfde grens, want het gaat om dezelfde vraag —
     * hoe lang kan één boeking duren?
     */
    public function claimIsStale(ProviderEntityLink $link): bool
    {
        return $link->provider_entity_id === null
            && $link->last_synced_at !== null
            && $link->last_synced_at->addSeconds(IdempotencyKey::leaseSeconds())->isPast();
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
