<?php

declare(strict_types=1);

namespace App\Accounting;

use App\Models\Connection;
use App\Models\IdempotencyKey;
use App\Models\ProviderEntityLink;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;

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

    public function releaseClaim(ProviderEntityLink $link): void
    {
        ProviderEntityLink::query()
            ->whereKey($link->getKey())
            ->whereNull('provider_entity_id')
            ->delete();
    }

    public function claimIsStale(ProviderEntityLink $link): bool
    {
        return $link->provider_entity_id === null
            && $link->last_synced_at !== null
            && $link->last_synced_at->addSeconds(IdempotencyKey::leaseSeconds())->isPast();
    }

    public function secondsUntilClaimStale(ProviderEntityLink $link): int
    {
        if ($link->last_synced_at === null) {
            return 1;
        }

        $remaining = (int) ceil(now()->diffInSeconds($link->last_synced_at->addSeconds(IdempotencyKey::leaseSeconds()), false));

        return max(1, min($remaining, IdempotencyKey::retryAfterCeilingSeconds()));
    }

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
