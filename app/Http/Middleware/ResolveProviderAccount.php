<?php

namespace App\Http\Middleware;

use App\Enums\Provider;
use App\Models\Account;
use App\Models\Connection;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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
