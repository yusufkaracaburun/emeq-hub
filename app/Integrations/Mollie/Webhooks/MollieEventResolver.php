<?php

declare(strict_types=1);

namespace App\Integrations\Mollie\Webhooks;

use App\Integrations\Webhooks\CanonicalEvent;
use App\Integrations\Contracts\ResolvesCanonicalEvent;

/**
 * Mollie stuurt alleen een resource-id; het type zit in de prefix. Dezelfde
 * prefix-tabel als {@see WebhookPayloadRouter} — die routeert intern, deze
 * benoemt naar buiten. `mdt_` staat er niet in: mandaat-events worden nooit
 * ge-fan-out (de router skipt ze), dus er is geen consumer die er een naam voor
 * nodig heeft.
 */
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
            // Ook de default-tak van de router landt op de payment-handler, dus
            // een onbekende prefix is hier een payment — niet 'unmapped'.
            default => CanonicalEvent::PAYMENT_CHANGED,
        };
    }
}
