<?php

declare(strict_types=1);

namespace App\Integrations\Snelstart\Webhooks;

use App\Integrations\Contracts\ResolvesCanonicalEntity;
use App\Integrations\Webhooks\CanonicalAction;

/**
 * Snelstart's `type` draagt `Entity.Action` ('Verkoopfactuur.Updated') — zie
 * {@see SnelstartEventResolver} voor het entity-deel. Dit levert het actie-deel.
 *
 * Geen `entityId()`: buiten `administratieId`, `eventId` en `type` is Snelstart's
 * webhook-payload niet geverifieerd (zie `.docs/decisions/snelstart-webhook-ingress.md`
 * — vijf ❓-aannames, defensief gebouwd, partner-antwoord op de payload-vorm zelf
 * staat nog open). Een entity-id-veldnaam verzinnen zou de invariant "geen
 * verzonnen partner-features" doorbreken; `null` is hier het eerlijke antwoord
 * totdat `partner@snelstart.nl` de vorm bevestigt.
 */
final class SnelstartEntityResolver implements ResolvesCanonicalEntity
{
    public function entityId(array $payload): ?string
    {
        return null;
    }

    public function action(array $payload): ?string
    {
        $type = $payload['type'] ?? null;

        if (! is_string($type) || ! str_contains($type, '.')) {
            return null;
        }

        $action = substr($type, strpos($type, '.') + 1);

        return match (strtolower($action)) {
            'created' => CanonicalAction::CREATED,
            'updated' => CanonicalAction::UPDATED,
            'deleted' => CanonicalAction::DELETED,
            default => CanonicalAction::UNMAPPED,
        };
    }
}
