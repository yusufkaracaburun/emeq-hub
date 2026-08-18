<?php

declare(strict_types=1);

namespace App\Integrations\Snelstart\Webhooks;

use App\Integrations\Contracts\ResolvesCanonicalEntity;
use App\Integrations\Webhooks\CanonicalAction;

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
