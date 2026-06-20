<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\GuardsTokenAbility;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Sanctum\TokenAbilities;
use App\Support\ProviderCredentialDescriptor;
use App\Support\ProviderShowcase;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Laravel\Pennant\Feature;

/**
 * Discovery + per-account koppel-status van alle providers. Data-driven uit
 * ProviderShowcase (config) + de live connection-status — een nieuwe provider
 * verschijnt hier automatisch zodra zijn config-rijen + OAuthFlow bestaan, geen
 * code-wijziging bij de consumer. Voedt de consumer-connect-kit.
 */
#[Group(name: 'Integrations', description: 'Welke providers een Account kan koppelen, met live status.', weight: 25)]
class IntegrationController extends Controller
{
    use GuardsTokenAbility;

    /**
     * @return list<array{key:string,label:string,tagline:string,category:string,logo:?string,brand:?string,connectable:bool,status:string,connection_id:?string}>
     */
    public function __invoke(Request $request, ProviderShowcase $showcase): array
    {
        $this->guardAbility($request, [
            TokenAbilities::INTEGRATIONS_MANAGE,
            TokenAbilities::CONSUMER_MANAGE_ACCOUNTS,
            TokenAbilities::ADMIN,
        ]);

        $statusByProvider = $this->connectionStatuses($request);

        return collect($showcase->summaries())
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
     * Onbekend/ontbrekend account → lege collectie (alles 'disconnected').
     *
     * @return Collection<string, Connection>
     */
    private function connectionStatuses(Request $request): Collection
    {
        $accountExternalId = $request->query('account_external_id');

        if (! is_string($accountExternalId) || $accountExternalId === '') {
            return collect();
        }

        $account = $request->user()->accounts()
            ->where('external_id', $accountExternalId)
            ->first();

        if ($account === null) {
            return collect();
        }

        return $account->connections()
            ->get(['id', 'provider', 'status', 'revoked_at'])
            ->keyBy(fn ($connection): string => $connection->provider->value);
    }
}
