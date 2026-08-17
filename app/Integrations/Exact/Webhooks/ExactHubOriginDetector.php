<?php

declare(strict_types=1);

namespace App\Integrations\Exact\Webhooks;

use App\Integrations\Contracts\DetectsHubOrigin;
use App\Models\Connection;
use App\Models\ConnectionAccountingRef;
use App\Models\ProviderEntityLink;
use Carbon\CarbonInterface;

final class ExactHubOriginDetector implements DetectsHubOrigin
{
    public function __construct(private readonly ExactEntityResolver $entities) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function hubAuthored(Connection $connection, array $payload): bool
    {
        $key = $this->entities->entityId($payload);

        if ($key === null) {
            return false;
        }

        if ($this->hasHubAuthoredLink($connection, $key)) {
            return true;
        }

        return $this->hasHubCreatedRelation($connection, $key);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function hubLastWroteAt(Connection $connection, array $payload): ?CarbonInterface
    {
        $key = $this->entities->entityId($payload);

        if ($key === null) {
            return null;
        }

        return ProviderEntityLink::query()
            ->where('connection_id', $connection->id)
            ->where('provider_entity_id', $key)
            ->where('origin', ProviderEntityLink::ORIGIN_HUB)
            ->latest('last_synced_at')
            ->value('last_synced_at');
    }

    private function hasHubAuthoredLink(Connection $connection, string $key): bool
    {
        return ProviderEntityLink::query()
            ->where('connection_id', $connection->id)
            ->where('provider_entity_id', $key)
            ->where('origin', ProviderEntityLink::ORIGIN_HUB)
            ->exists();
    }

    private function hasHubCreatedRelation(Connection $connection, string $key): bool
    {
        $ref = ConnectionAccountingRef::query()
            ->where('connection_id', $connection->id)
            ->where('kind', ConnectionAccountingRef::KIND_RELATION)
            ->where('native_id', $key)
            ->first();

        return ($ref?->attrs['created_by_hub'] ?? false) === true;
    }
}
