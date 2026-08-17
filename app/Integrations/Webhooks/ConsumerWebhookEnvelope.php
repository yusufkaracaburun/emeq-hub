<?php

declare(strict_types=1);

namespace App\Integrations\Webhooks;

use App\Enums\Provider;

/**
 * De body van elke webhook die de Hub naar een consumer stuurt.
 *
 * De vorm staat als belofte in de integratiehandleiding — "elke webhook heeft
 * dezelfde vorm" — dus mag geen enkele emitter er zijn eigen platte payload naast
 * zetten. Dat gebeurde wel: de partner-fan-out stuurde de envelope, terwijl het
 * boekingsresultaat en de ingetrokken koppeling nog hun eigen velden hadden. Een
 * consumer die op `account_id` routeert kreeg daar `undefined`.
 */
final class ConsumerWebhookEnvelope
{
    /**
     * @param  string  $accountId  het id dat de consumer zelf aanleverde bij het
     *                             koppelen — die kent z'n eigen `X-Account-Id`,
     *                             niet onze primary key
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function make(string $event, Provider $provider, string $accountId, array $data, bool $causedByHub = false): array
    {
        return array_filter([
            'event' => $event,
            'provider' => $provider->value,
            'account_id' => $accountId,
            // Wanneer de Hub het event uitstuurde. De partner levert zelden een eigen
            // tijdstempel; doen alsof van wel zou liegen over de bron.
            'occurred_at' => now()->toIso8601String(),
            'caused_by_hub' => $causedByHub ?: null,
            'data' => $data,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
