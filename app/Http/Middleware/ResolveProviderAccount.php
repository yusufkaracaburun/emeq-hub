<?php

namespace App\Http\Middleware;

use App\Enums\Provider;
use App\Models\Account;
use App\Models\Connection;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gedeelde pass-through-context-resolutie voor de provider-middleware:
 *
 * 1. Leest X-Account-Id; ontbreekt → 400.
 * 2. Scoped Account-lookup op de geauthenticeerde Consumer; fail → 404.
 * 3. Actieve Connection-lookup voor deze provider; fail → 404.
 * 4. Bindt de provider-SDK per-request (bindSdk — het enige dat per provider verschilt).
 * 5. Zet account + connection op request->attributes onder "{provider}_account" /
 *    "{provider}_connection" voor controller en audit.
 *
 * Per provider verschilt alleen: de Provider-enum, het label in de
 * connection_not_found-melding, en de SDK-binding.
 */
abstract class ResolveProviderAccount
{
    abstract protected function provider(): Provider;

    abstract protected function connectionLabel(): string;

    abstract protected function bindSdk(Connection $connection): void;

    public function handle(Request $request, Closure $next): Response
    {
        $accountHeader = $request->header('X-Account-Id');

        if (! is_string($accountHeader) || $accountHeader === '') {
            return response()->json([
                'error' => 'missing_account_header',
                'message' => 'Vereiste header X-Account-Id ontbreekt.',
            ], 400);
        }

        $account = Account::query()
            ->where('consumer_id', $request->user()?->getKey())
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
            ->where('provider', $this->provider()->value)
            ->whereNull('revoked_at')
            ->first();

        if ($connection === null) {
            return response()->json([
                'error' => 'connection_not_found',
                'message' => "Geen actieve {$this->connectionLabel()}-Connection voor dit Account.",
            ], 404);
        }

        $this->bindSdk($connection);

        $prefix = $this->provider()->value;
        $request->attributes->set("{$prefix}_account", $account);
        $request->attributes->set("{$prefix}_connection", $connection);

        return $next($request);
    }
}
