<?php

namespace App\Http\Controllers\Api\V1\Exact;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Connection;
use App\Support\Exact\ExactForwarder;
use Dedoc\Scramble\Attributes\Group;
use Emeq\ExactApi\Http\Request\Read\GetJournals;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Named Exact-resource: dagboeken. Mapt op de Exact OData-endpoint
 * `GET financial/Journals` (read-only) — de referentiedata voor de
 * doc-type→dagboek-mapping van de accounting-sync. Eigen Scramble-groep +
 * dezelfde PassThroughCall-audit als de generieke pass-through.
 */
#[Group(name: 'Exact · Journals', description: 'Dagboeken van de gekoppelde administratie. Mapt op de Exact OData-endpoint `GET financial/Journals` (read-only).', weight: 64)]
class JournalsController extends Controller
{
    public function __construct(private readonly ExactForwarder $forwarder) {}

    /**
     * List journals.
     *
     * Forward naar Exact `GET /financial/Journals` met de OAuth-tokens van de
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
            new GetJournals($request->query()),
        );
    }
}
