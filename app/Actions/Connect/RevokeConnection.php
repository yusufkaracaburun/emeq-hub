<?php

declare(strict_types=1);

namespace App\Actions\Connect;

use App\Integrations\OAuth\Exceptions\ProviderDisabledException;
use App\Integrations\OAuth\OAuthFlowRegistry;
use App\Models\Connection;

/**
 * Provider-side deprovisioning (token-revoke + webhook-teardown) via de
 * OAuthFlow als die bestaat; anders alleen lokaal markeren (Snelstart heeft
 * geen OAuth-flow). Een uitgeschakelde provider mag het loskoppelen niet
 * blokkeren — bij ProviderDisabledException valt het terug op lokaal.
 *
 * Gedeeld door de consumer-API (`DELETE /v1/connections/{id}`) en de
 * handoff-pagina waar de eindgebruiker zelf ontkoppelt. Het notificeren van de
 * consumer-app hoort niet hier: de API-caller weet het al, de eindgebruiker-
 * route moet juist wél fan-outen.
 */
class RevokeConnection
{
    public function __construct(private readonly OAuthFlowRegistry $registry) {}

    public function handle(Connection $connection): void
    {
        $provider = $connection->provider->value;

        if (in_array($provider, $this->registry->providers(), true)) {
            try {
                $this->registry->for($provider)->revoke($connection);

                return;
            } catch (ProviderDisabledException) {
                // val door naar lokale revoke
            }
        }

        $connection->update(['status' => 'revoked', 'revoked_at' => now()]);
    }
}
