<?php

declare(strict_types=1);

namespace App\Integrations\Mollie\Webhooks;

use App\Integrations\Contracts\ResolvesCanonicalEntity;

final class MollieEntityResolver implements ResolvesCanonicalEntity
{
    public function entityId(array $payload): ?string
    {
        $id = $payload['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function action(array $payload): ?string
    {
        return null;
    }
}
