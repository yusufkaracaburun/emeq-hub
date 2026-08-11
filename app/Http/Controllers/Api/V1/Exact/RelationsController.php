<?php

namespace App\Http\Controllers\Api\V1\Exact;

use App\Http\Controllers\Controller;
use App\Integrations\Exact\PassThrough\ExactForwarder;
use App\Models\Account;
use App\Models\Connection;
use Dedoc\Scramble\Attributes\Group;
use Emeq\ExactApi\Http\Request\Read\GetRelations;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Named Exact-resource: relaties (debiteuren/crediteuren). Mapt op de Exact
 * OData-endpoint `GET crm/Accounts` (read-only) — de referentiedata voor de
 * relatie→GUID-mapping van de accounting-sync. Eigen Scramble-groep + dezelfde
 * PassThroughCall-audit als de generieke pass-through.
 */
#[Group(name: 'Exact · Relations', description: 'Relaties (debiteuren/crediteuren) van de gekoppelde administratie. Mapt op de Exact OData-endpoint `GET crm/Accounts` (read-only).', weight: 63)]
class RelationsController extends Controller
{
    public function __construct(private readonly ExactForwarder $forwarder) {}

    /**
     * List relations.
     *
     * Forward naar Exact `GET /crm/Accounts` met de OAuth-tokens van de gekoppelde
     * Account. OData-query (`$select`, `$filter`, `$top`, …) wordt ongewijzigd
     * doorgegeven; het antwoord is Exact's payload zoals ontvangen.
     */
    public function index(Request $request): Response
    {
        /** @var Account $account */
        $account = $request->attributes->get('exact_account');
        /** @var Connection $connection */
        $connection = $request->attributes->get('exact_connection');

        return $this->forwarder->forward(
            $request,
            $account,
            $connection,
            new GetRelations($request->query()),
        );
    }
}
