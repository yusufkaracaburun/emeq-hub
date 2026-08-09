<?php

declare(strict_types=1);

namespace App\Support\Connect;

use App\Models\Account;
use App\Models\Connection;
use App\Support\ProviderCredentialDescriptor;
use App\Support\ProviderShowcase;
use Illuminate\Support\Collection;
use Laravel\Pennant\Feature;

/**
 * Per-account koppelstatus van alle providers, data-driven uit ProviderShowcase
 * (config) + de live connection-rijen. Gedeeld door de consumer-API
 * (`GET /v1/integrations`) en de handoff-pagina waar de eindgebruiker zelf
 * koppelt — beide moeten exact dezelfde waarheid tonen.
 *
 * Een nieuwe provider verschijnt automatisch zodra zijn config-rijen + OAuthFlow
 * bestaan; geen code-wijziging hier of bij de consumer.
 */
class ProviderConnectStatus
{
    public function __construct(private readonly ProviderShowcase $showcase) {}

    /**
     * @return list<array{key:string,label:string,tagline:string,category:string,logo:?string,brand:?string,connectable:bool,status:string,connection_id:?string}>
     */
    public function for(?Account $account): array
    {
        $statusByProvider = $this->connectionStatuses($account);

        return collect($this->showcase->summaries())
            ->map(function (array $summary) use ($statusByProvider): array {
                $key = $summary['key'];
                $descriptor = ProviderCredentialDescriptor::tryFor($key);

                $connection = $statusByProvider->get($key);
                $live = $connection !== null && $connection->revoked_at === null;

                return [
                    'key' => $key,
                    'label' => $summary['label'],
                    'tagline' => $summary['tagline'],
                    'category' => $summary['category'],
                    'logo' => $summary['logo'],
                    'brand' => $summary['brand'],
                    'connectable' => $descriptor?->oauthFlowKey !== null
                        && Feature::active("provider-{$key}-enabled"),
                    'status' => match (true) {
                        ! $live => 'disconnected',
                        $connection->status === 'active' => 'connected',
                        $connection->status === 'pending' => 'pending',
                        default => 'disconnected',
                    },
                    'connection_id' => $live ? (string) $connection->id : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Connection-rijen van het (optionele) account, geïndexeerd op provider-key.
     * Geen account → lege collectie (alles 'disconnected').
     *
     * @return Collection<string, Connection>
     */
    private function connectionStatuses(?Account $account): Collection
    {
        if ($account === null) {
            return collect();
        }

        return $account->connections()
            ->get(['id', 'provider', 'status', 'revoked_at'])
            ->keyBy(fn (Connection $connection): string => $connection->provider->value);
    }
}
