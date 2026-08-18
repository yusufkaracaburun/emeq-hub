<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\GuardsTokenAbility;
use App\Http\Controllers\Controller;
use App\Sanctum\TokenAbilities;
use App\Support\Connect\ProviderConnectStatus;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

#[Group(name: 'Integrations', description: 'Welke providers een Account kan koppelen, met live status.', weight: 25)]
class IntegrationController extends Controller
{
    use GuardsTokenAbility;

    /** @return list<array{key:string,label:string,tagline:string,category:string,logo:?string,brand:?string,connectable:bool,status:string,connection_id:?string}> */
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
