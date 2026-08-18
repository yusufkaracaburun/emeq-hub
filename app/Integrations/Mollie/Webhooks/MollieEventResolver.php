<?php

declare(strict_types=1);

namespace App\Integrations\Mollie\Webhooks;

use App\Integrations\Contracts\ResolvesCanonicalEvent;
use App\Integrations\Webhooks\CanonicalEvent;

final class MollieEventResolver implements ResolvesCanonicalEvent
{
    public function resolve(array $payload): ?string
    {
        $id = $payload['id'] ?? null;

        if (! is_string($id)) {
            return null;
        }

        return match (true) {
            str_starts_with($id, 'sub_') => CanonicalEvent::SUBSCRIPTION_CHANGED,
            default => CanonicalEvent::PAYMENT_CHANGED,
        };
    }
}
