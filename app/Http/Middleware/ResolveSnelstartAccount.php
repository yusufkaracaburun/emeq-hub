<?php

namespace App\Http\Middleware;

use App\Models\Account;
use App\Models\Connection;
use App\Services\Snelstart\HubSnelstartCredentialResolver;
use Closure;
use Emeq\SnelstartApi\Contracts\SnelstartCredentialResolver;
use Emeq\SnelstartApi\Snelstart;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves de Snelstart-pass-through-context voor het inkomende request:
 *
 * 1. Leest X-Account-Id; ontbreekt -> 400.
 * 2. Scoped Account-lookup op de geauthenticeerde Consumer; fail -> 404.
 * 3. Actieve Snelstart-Connection-lookup; fail -> 404.
 * 4. Bindt HubSnelstartCredentialResolver per-request in de container.
 * 5. Forget de Snelstart-singleton zodat de nieuwe resolver effectief is.
 * 6. Zet account + connection op request->attributes voor controller en audit.
 *
 * Beslissingen in 05b-CONTEXT.md §<decisions> ### Resolver binding.
 */
class ResolveSnelstartAccount
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
            ->where('provider', 'snelstart')
            ->whereNull('revoked_at')
            ->first();

        if ($connection === null) {
            return response()->json([
                'error' => 'connection_not_found',
                'message' => 'Geen actieve Snelstart-Connection voor dit Account.',
            ], 404);
        }

        app()->instance(
            SnelstartCredentialResolver::class,
            new HubSnelstartCredentialResolver($connection),
        );

        // Snelstart::class is een singleton (zie SnelstartServiceProvider); forget
        // zodat een al-geboot-singleton vanuit een eerdere request niet de oude
        // resolver vasthoudt en de nieuwe binding effectief is.
        app()->forgetInstance(Snelstart::class);

        $request->attributes->set('snelstart_account', $account);
        $request->attributes->set('snelstart_connection', $connection);

        return $next($request);
    }
}
