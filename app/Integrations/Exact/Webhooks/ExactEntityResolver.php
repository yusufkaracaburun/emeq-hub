<?php

declare(strict_types=1);

namespace App\Integrations\Exact\Webhooks;

use App\Integrations\Contracts\ResolvesCanonicalEntity;
use App\Integrations\Webhooks\CanonicalAction;

/**
 * Leest `Content.Key` en `Content.Action` — hetzelfde tweetal dat de webhook-
 * subscribe/inbound-contract vastlegt in `packages/exact-api/docs/partners/exact/webhooks.md`.
 * `Action` komt binnen als `Update`/`Delete` (title-case, live-geverifieerd); een
 * `Create` staat er niet in Exact's eigen documentatie, maar wordt hier toch
 * meegenomen — mocht Exact 'm ooit sturen, dan hoort de consumer 'm canoniek te
 * zien in plaats van als `unmapped`.
 */
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
