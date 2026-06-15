<?php

namespace App\Http\Middleware;

use App\Enums\Provider;
use App\Models\Account;
use App\Models\Connection;
use App\Mollie\MollieConnectionContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mirror van ResolveSnelstartAccount voor Mollie. Verschilt in stap 4:
 * gebruikt MollieConnectionContext::set() ipv container-rebind, omdat
 * AppServiceProvider MollieConnectionContext als scoped binding registreert
 * en HubMollieCredentialResolver erop leest via constructor-injection.
 * Geen forgetInstance van Mollie::class nodig — Mollie::client() bouwt elke
 * call een verse MollieApiClient via fresh resolve() (zie vendor/emeq/mollie-api/src/Mollie.php).
 *
 * Beslissingen in 05a-CONTEXT.md §<decisions> D-03.
 */
class ResolveMollieAccount
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
            ->where('provider', Provider::Mollie->value)
            ->whereNull('revoked_at')
            ->first();

        if ($connection === null) {
            return response()->json([
                'error' => 'connection_not_found',
                'message' => 'Geen actieve Mollie-Connection voor dit Account.',
            ], 404);
        }

        app(MollieConnectionContext::class)->set($connection);

        $request->attributes->set('mollie_account', $account);
        $request->attributes->set('mollie_connection', $connection);

        return $next($request);
    }
}
