<?php

declare(strict_types=1);

namespace App\Integrations\Exact\Webhooks;

use App\Integrations\Contracts\DetectsHubOrigin;
use App\Models\Connection;
use App\Models\ConnectionAccountingRef;
use App\Models\ProviderEntityLink;

final class ExactEchoDetector implements DetectsHubOrigin
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function causedByHub(Connection $connection, array $payload): bool
    {
        $key = $payload['Content']['Key'] ?? $payload['Key'] ?? null;

        if (! is_string($key) || $key === '') {
            return false;
        }

        if ($this->hasHubAuthoredLink($connection, $key)) {
            return true;
        }

        return $this->hasHubCreatedRelation($connection, $key);
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
