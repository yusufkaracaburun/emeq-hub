<?php

namespace App\Http\Controllers\Api\V1\Exact;

use App\Http\Controllers\Controller;
use App\Integrations\Exact\PassThrough\ExactForwarder;
use App\Models\Account;
use App\Models\Connection;
use Dedoc\Scramble\Attributes\Group;
use Emeq\ExactApi\Http\Request\Read\GetJournals;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

#[Group(name: 'Exact · Journals', description: 'Dagboeken van de gekoppelde administratie. Mapt op de Exact OData-endpoint `GET financial/Journals` (read-only).', weight: 64)]
class JournalsController extends Controller
{
    public function __construct(private readonly ExactForwarder $forwarder) {}

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
