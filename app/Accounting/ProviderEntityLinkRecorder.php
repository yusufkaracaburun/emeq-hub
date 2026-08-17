<?php

declare(strict_types=1);

namespace App\Accounting;

use App\Http\Controllers\Webhooks\ExactWebhookController;
use App\Models\Connection;
use App\Models\IdempotencyKey;
use App\Models\ProviderEntityLink;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;

/**
 * Leest en schrijft de canonieke ⇄ partner-identiteit. Losgetrokken van
 * {@see AccountingSyncRunner} zodat de runner leesbaar blijft en dit los te testen is.
 */
final readonly class ProviderEntityLinkRecorder
{
    public function find(Connection $connection, FinancialDocument $document): ?ProviderEntityLink
    {
        return ProviderEntityLink::query()
            ->where('connection_id', $connection->getKey())
            ->where('entity_type', ProviderEntityLink::ENTITY_FINANCIAL_DOCUMENT)
            ->where('entity_subtype', $document->type->value)
            ->where('external_id', $document->externalId)
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
                'entity_subtype' => $document->type->value,
                'external_id' => $document->externalId,
                'provider' => $connection->provider->value,
                'administratie_id' => self::administratieId($connection),
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
     * Een geslaagde boeking van precies dit document in dezelfde échte administratie,
     * via een ándere Connection.
     *
     * De canonieke unique index sluit per Connection af, maar één administratie mag
     * door meerdere Accounts gekoppeld zijn — de boekhouder via de ene Consumer-app,
     * de ondernemer via de andere. {@see ExactWebhookController}
     * rekent daar expliciet op. Aan de boekkant deelden die twee geen grendel.
     *
     * Gelijke fingerprint is voorwaarde, niet alleen gelijk `external_id`. Twee apps
     * met een eigen nummerreeks gebruiken allebei "2026-001" voor verschillende
     * documenten; alleen op het nummer weigeren zou een echte boeking tegenhouden.
     * Gelijke fingerprint betekent gelijk type, nummer, datum, partij én regels — dan
     * is het hetzelfde document en is tweemaal boeken nooit gewenst.
     *
     * Een lege administratie-id betekent dat de provider er geen levert. Dan valt
     * "dezelfde administratie" niet vast te stellen en doet deze check niets: alle
     * lege waarden op één hoop gooien zou losstaande administraties als één
     * behandelen en echte boekingen weigeren.
     */
    public function findPostedOnSameAdministration(
        Connection $connection,
        FinancialDocument $document,
        string $fingerprint,
    ): ?ProviderEntityLink {
        $administratieId = self::administratieId($connection);

        if ($administratieId === '') {
            return null;
        }

        return ProviderEntityLink::query()
            ->where('provider', $connection->provider->value)
            ->where('administratie_id', $administratieId)
            ->where('entity_type', ProviderEntityLink::ENTITY_FINANCIAL_DOCUMENT)
            ->where('entity_subtype', $document->type->value)
            ->where('external_id', $document->externalId)
            ->where('payload_fingerprint', $fingerprint)
            ->whereNotNull('provider_entity_id')
            ->where('connection_id', '!=', $connection->getKey())
            ->first();
    }

    /**
     * De grendel die {@see findPostedOnSameAdministration()} pas een mutex maakt.
     *
     * Die methode is een lezing. Twee Connections op dezelfde administratie claimen
     * elk hun eigen rij — de unique index sluit per Connection af — zien daarna beide
     * nog geen boeking, en boeken beide. Deze grendel serialiseert dat venster, op
     * exact dezelfde sleutel-scope als de lezing: een andere scope zou het verkeerde
     * afsluiten.
     *
     * Null wanneer de provider geen administratie-id levert. Dan valt "dezelfde
     * administratie" niet vast te stellen en doet de lezing ook niets; een grendel
     * over alle lege waarden heen zou losstaande administraties serialiseren.
     *
     * De TTL is de idempotency-lease: hetzelfde antwoord op "hoe lang kan één boeking
     * duren?" als de claim gebruikt. Korter is gevaarlijk — een grendel die middenin
     * de push verloopt laat precies de dubbele boeking door die hij moest tegenhouden.
     *
     * `external_id` is consumer-invoer en mag alles bevatten, dus de sleutel wordt
     * gehasht in plaats van samengeplakt.
     */
    public function administrationLock(
        Connection $connection,
        FinancialDocument $document,
        string $fingerprint,
    ): ?Lock {
        $administratieId = self::administratieId($connection);

        if ($administratieId === '') {
            return null;
        }

        $scope = implode("\0", [
            $connection->provider->value,
            $administratieId,
            ProviderEntityLink::ENTITY_FINANCIAL_DOCUMENT,
            $document->type->value,
            $document->externalId,
            $fingerprint,
        ]);

        return Cache::lock('accounting:post:'.hash('sha256', $scope), IdempotencyKey::leaseSeconds());
    }

    private static function administratieId(Connection $connection): string
    {
        return (string) ($connection->administratie_id ?? '');
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
     * Seconden tot deze claim als gestorven telt (zie {@see self::claimIsStale()}),
     * minimaal 1 en afgetopt op {@see IdempotencyKey::retryAfterCeilingSeconds()} —
     * bruikbaar als `Retry-After`. Zelfde grenzen als
     * {@see IdempotencyKey::secondsUntilLeaseExpires()}: hetzelfde antwoord op "hoe
     * lang kan één boeking duren?", toegepast op `last_synced_at` in plaats van
     * `locked_at`, en om dezelfde reden afgetopt.
     */
    public function secondsUntilClaimStale(ProviderEntityLink $link): int
    {
        if ($link->last_synced_at === null) {
            return 1;
        }

        $remaining = (int) ceil(now()->diffInSeconds($link->last_synced_at->addSeconds(IdempotencyKey::leaseSeconds()), false));

        return max(1, min($remaining, IdempotencyKey::retryAfterCeilingSeconds()));
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
                'entity_subtype' => $document->type->value,
                'external_id' => $document->externalId,
            ],
            [
                'provider' => $connection->provider->value,
                'administratie_id' => self::administratieId($connection),
                'provider_entity_id' => $result->externalRef,
                'provider_entity_number' => $result->externalNumber === null ? null : (string) $result->externalNumber,
                'payload_fingerprint' => $fingerprint,
                'origin' => ProviderEntityLink::ORIGIN_HUB,
                'last_synced_at' => now(),
            ],
        );
    }
}
