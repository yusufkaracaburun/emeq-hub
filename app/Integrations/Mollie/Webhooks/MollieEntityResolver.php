<?php

declare(strict_types=1);

namespace App\Integrations\Mollie\Webhooks;

use App\Integrations\Contracts\ResolvesCanonicalEntity;

/**
 * Mollie's webhook-body is letterlijk `{"id": "tr_…"}` — dat id ís de entity-id,
 * er valt niets anders uit te lezen. Geen actie: de notificatie draagt er geen;
 * de Hub moet de resource zelf ophalen (zie {@see PaymentWebhookHandler},
 * {@see SubscriptionWebhookHandler}) om te weten wat er veranderde.
 */
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
