<?php

declare(strict_types=1);

namespace App\Support\Connect;

use App\Models\Account;
use App\Models\Connection;
use App\Support\ProviderCredentialDescriptor;
use App\Support\ProviderGate;
use App\Support\ProviderShowcase;
use Illuminate\Support\Collection;

class ProviderConnectStatus
{
    public function __construct(private readonly ProviderShowcase $showcase) {}

    /** @return list<array{key:string,label:string,tagline:string,category:string,logo:?string,brand:?string,connectable:bool,status:string,connection_id:?string}> */
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
                        && ProviderGate::enabled($key),
                    'status' => match (true) {
                        ! $live => 'disconnected',
                        $connection->status === 'active' => 'connected',
                        $connection->status === 'pending' => 'pending',
                        default => 'disconnected',
                    },
                    'connection_id' => $live ? (string) $connection->public_id : null,
                ];
            })
            ->values()
            ->all();
    }

    /** @return Collection<string, Connection> */
    private function connectionStatuses(?Account $account): Collection
    {
        if ($account === null) {
            return collect();
        }

        return $account->connections()
            ->get(['id', 'public_id', 'provider', 'status', 'revoked_at'])
            ->keyBy(fn (Connection $connection): string => $connection->provider->value);
    }
}
