<?php

declare(strict_types=1);

namespace App\Integrations\Webhooks;

use App\Enums\Provider;
use Carbon\CarbonInterface;

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
     * @param  bool  $hubAuthored  of de Hub deze entity ooit zelf schreef — géén
     *                             uitspraak over wie déze wijziging veroorzaakte
     * @param  string|null  $entityId  het partner-eigen id van de gewijzigde
     *                                 entity ({@see ResolvesCanonicalEntity})
     * @param  string|null  $action  canonieke actie uit {@see CanonicalAction}
     * @return array<string, mixed>
     */
    public static function make(
        string $event,
        Provider $provider,
        string $accountId,
        array $data,
        bool $hubAuthored = false,
        ?string $entityId = null,
        ?string $action = null,
        ?CarbonInterface $hubLastWroteAt = null,
    ): array {
        return array_filter([
            'event' => $event,
            'provider' => $provider->value,
            'account_id' => $accountId,
            'entity_id' => $entityId,
            'action' => $action,
            // Wanneer de Hub het event uitstuurde. De partner levert zelden een eigen
            // tijdstempel; doen alsof van wel zou liegen over de bron.
            'occurred_at' => now()->toIso8601String(),
            'hub_authored' => $hubAuthored ?: null,
            'hub_last_wrote_at' => $hubLastWroteAt?->toIso8601String(),
            'data' => $data,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
