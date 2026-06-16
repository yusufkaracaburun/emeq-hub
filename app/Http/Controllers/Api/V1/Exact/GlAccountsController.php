<?php

namespace App\Http\Controllers\Api\V1\Exact;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Connection;
use App\Support\Exact\ExactForwarder;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Named Exact-resource: grootboekrekeningen. Eerste tracer voor het pattern —
 * een Hub-endpoint dat 1-op-1 op één gedocumenteerde Exact OData-endpoint mapt,
 * met eigen Scramble-groep (docs-traceability) en dezelfde PassThroughCall-audit
 * als de generieke pass-through (runtime-traceability).
 */
#[Group(name: 'Exact · GL Accounts', description: 'Grootboekrekeningen van de gekoppelde administratie. Mapt op de Exact OData-endpoint `GET financial/GLAccounts` (read-only).', weight: 61)]
class GlAccountsController extends Controller
{
    public function __construct(private readonly ExactForwarder $forwarder) {}

    /**
     * List GL accounts.
     *
     * Forward naar Exact `GET /financial/GLAccounts` met de OAuth-tokens van de
     * gekoppelde Account. OData-query (`$select`, `$filter`, `$top`, …) wordt
     * ongewijzigd doorgegeven; het antwoord is Exact's payload zoals ontvangen.
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
            'GET',
            '/financial/GLAccounts',
            $request->query(),
        );
    }
}
