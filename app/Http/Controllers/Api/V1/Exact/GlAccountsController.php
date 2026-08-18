<?php

namespace App\Http\Controllers\Api\V1\Exact;

use App\Http\Controllers\Controller;
use App\Integrations\Exact\PassThrough\ExactForwarder;
use App\Models\Account;
use App\Models\Connection;
use Dedoc\Scramble\Attributes\Group;
use Emeq\ExactApi\Http\Request\Read\GetGlAccounts;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

#[Group(name: 'Exact · GL Accounts', description: 'Grootboekrekeningen van de gekoppelde administratie. Mapt op de Exact OData-endpoint `GET financial/GLAccounts` (read-only).', weight: 61)]
class GlAccountsController extends Controller
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
            new GetGlAccounts($request->query()),
        );
    }
}
