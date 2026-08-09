<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\GuardsTokenAbility;
use App\Http\Controllers\Controller;
use App\Sanctum\TokenAbilities;
use App\Support\Connect\ProviderConnectStatus;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

/**
 * Discovery + per-account koppel-status van alle providers. Data-driven uit
 * ProviderShowcase (config) + de live connection-status — een nieuwe provider
 * verschijnt hier automatisch zodra zijn config-rijen + OAuthFlow bestaan, geen
 * code-wijziging bij de consumer. Voedt de consumer-connect-kit.
 *
 * De status-berekening zelf leeft in ProviderConnectStatus, gedeeld met de
 * handoff-pagina waar de eindgebruiker zelf koppelt.
 */
#[Group(name: 'Integrations', description: 'Welke providers een Account kan koppelen, met live status.', weight: 25)]
class IntegrationController extends Controller
{
    use GuardsTokenAbility;

    /**
     * @return list<array{key:string,label:string,tagline:string,category:string,logo:?string,brand:?string,connectable:bool,status:string,connection_id:?string}>
     */
    public function __invoke(Request $request, ProviderConnectStatus $status): array
    {
        $this->guardAbility($request, [
            TokenAbilities::INTEGRATIONS_MANAGE,
            TokenAbilities::CONSUMER_MANAGE_ACCOUNTS,
            TokenAbilities::ADMIN,
        ]);

        $accountExternalId = $request->query('account_external_id');

        $account = is_string($accountExternalId) && $accountExternalId !== ''
            ? $request->user()->accounts()->where('external_id', $accountExternalId)->first()
            : null;

        return $status->for($account);
    }
}
