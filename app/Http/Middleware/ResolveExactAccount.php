<?php

namespace App\Http\Middleware;

use App\Enums\Provider;
use App\Models\Account;
use App\Models\Connection;
use App\Services\Exact\ConnectionTokenStore;
use App\Services\Exact\HubExactCredentialResolver;
use Closure;
use Emeq\ExactApi\Contracts\ExactCredentialResolver;
use Emeq\ExactApi\Contracts\TokenStore;
use Emeq\ExactApi\Exact;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves de Exact-pass-through-context (mirror ResolveSnelstartAccount):
 *
 * 1. Leest X-Account-Id; ontbreekt -> 400.
 * 2. Scoped Account-lookup op de geauthenticeerde Consumer; fail -> 404.
 * 3. Actieve Exact-Connection-lookup; fail -> 404.
 * 4. Bindt HubExactCredentialResolver + ConnectionTokenStore per-request — de SDK
 *    leest beide uit de container (TokenStore is verplicht: geen stille default).
 * 5. Forget de Exact-singleton zodat de nieuwe bindings effectief zijn.
 * 6. Zet account + connection op request->attributes voor controller en audit.
 */
class ResolveExactAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $accountHeader = $request->header('X-Account-Id');

        if (! is_string($accountHeader) || $accountHeader === '') {
            return response()->json([
                'error' => 'missing_account_header',
                'message' => 'Vereiste header X-Account-Id ontbreekt.',
            ], 400);
        }

        $consumerId = $request->user()?->getKey();

        $account = Account::query()
            ->where('consumer_id', $consumerId)
            ->where('external_id', $accountHeader)
            ->first();

        if ($account === null) {
            return response()->json([
                'error' => 'account_not_found',
                'message' => 'Account niet gevonden voor deze Consumer.',
            ], 404);
        }

        $connection = Connection::query()
            ->where('account_id', $account->getKey())
            ->where('provider', Provider::Exact->value)
            ->whereNull('revoked_at')
            ->first();

        if ($connection === null) {
            return response()->json([
                'error' => 'connection_not_found',
                'message' => 'Geen actieve Exact-Connection voor dit Account.',
            ], 404);
        }

        app()->instance(ExactCredentialResolver::class, new HubExactCredentialResolver($connection));
        app()->instance(TokenStore::class, new ConnectionTokenStore($connection));
        app()->forgetInstance(Exact::class);

        $request->attributes->set('exact_account', $account);
        $request->attributes->set('exact_connection', $connection);

        return $next($request);
    }
}
