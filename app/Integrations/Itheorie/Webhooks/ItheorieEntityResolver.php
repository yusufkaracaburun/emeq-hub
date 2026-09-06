<?php

declare(strict_types=1);

namespace App\Integrations\Itheorie\Webhooks;

use App\Integrations\Contracts\ResolvesCanonicalEntity;

final class ItheorieEntityResolver implements ResolvesCanonicalEntity
{
    /** @param array<string, mixed> $payload */
    public function entityId(array $payload): ?string
    {
        return null;
    }

    /** @param array<string, mixed> $payload */
    public function action(array $payload): ?string
    {
        return null;
    }
}
