<?php

declare(strict_types=1);

namespace App\Integrations\Exact\Webhooks;

use App\Integrations\Contracts\ResolvesCanonicalEntity;
use App\Integrations\Webhooks\CanonicalAction;

final class ExactEntityResolver implements ResolvesCanonicalEntity
{
    public function entityId(array $payload): ?string
    {
        $key = $payload['Content']['Key'] ?? $payload['Key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    public function action(array $payload): ?string
    {
        $action = $payload['Content']['Action'] ?? $payload['Action'] ?? null;

        if (! is_string($action)) {
            return null;
        }

        return match (strtolower($action)) {
            'create' => CanonicalAction::CREATED,
            'update' => CanonicalAction::UPDATED,
            'delete' => CanonicalAction::DELETED,
            default => CanonicalAction::UNMAPPED,
        };
    }
}
