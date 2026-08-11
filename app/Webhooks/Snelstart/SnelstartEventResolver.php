<?php

declare(strict_types=1);

namespace App\Webhooks\Snelstart;

use App\Webhooks\CanonicalEvent;
use App\Webhooks\Contracts\ResolvesCanonicalEvent;

/**
 * Snelstart beschrijft z'n events als `Entity.Action` — 'Relatie.Created',
 * 'Verkoopfactuur.Updated'. Alleen het entity-deel bepaalt de canonieke naam;
 * de actie blijft in `data`.
 *
 * Snelstart-domeintermen worden niet vertaald in de partner-payload zelf, maar de
 * canonieke naam is Hub-vocabulaire en dus Engels: 'Relatie' → relation.
 */
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
