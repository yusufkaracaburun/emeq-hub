<?php

declare(strict_types=1);

namespace App\Integrations\Snelstart\Webhooks;

use App\Integrations\Contracts\ResolvesCanonicalEvent;
use App\Integrations\Webhooks\CanonicalEvent;

final class SnelstartEventResolver implements ResolvesCanonicalEvent
{
    public function resolve(array $payload): ?string
    {
        $type = $payload['type'] ?? null;

        if (! is_string($type)) {
            return null;
        }

        $entity = str_contains($type, '.') ? strstr($type, '.', true) : $type;

        return match ($entity) {
            'Relatie' => CanonicalEvent::RELATION_CHANGED,
            'Verkoopfactuur' => CanonicalEvent::SALES_INVOICE_CHANGED,
            default => null,
        };
    }
}
