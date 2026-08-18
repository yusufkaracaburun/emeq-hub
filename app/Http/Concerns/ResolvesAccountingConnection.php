<?php

declare(strict_types=1);

namespace App\Http\Concerns;

use App\Models\Account;
use App\Models\Connection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

trait ResolvesAccountingConnection
{
    /**
     * @param  list<string>  $providers  toegestane boekhoud-providers (uit de registry)
     * @return array{0: Account, 1: Connection}
     */
    protected function resolveAccountingConnection(Request $request, array $providers): array
    {
        $accountHeader = $request->header('X-Account-Id');

        if (! is_string($accountHeader) || $accountHeader === '') {
            $this->failAccounting('missing_account_header', 'Vereiste header X-Account-Id ontbreekt.', 400);
        }

        $account = Account::query()
            ->where('consumer_id', $request->user()?->getKey())
            ->where('external_id', $accountHeader)
            ->first();

        if ($account === null) {
            $this->failAccounting('account_not_found', 'Account niet gevonden voor deze Consumer.', 404);
        }

        /** @var list<Connection> $candidates */
        $candidates = $account->connections()
            ->whereNull('revoked_at')
            ->whereIn('provider', $providers)
            ->orderBy('id')
            ->get()
            ->all();

        if ($candidates === []) {
            $this->failAccounting('no_accounting_connection', 'Geen actieve boekhoud-Connection voor dit Account.', 404);
        }

        return [$account, $this->pickConnection($request, $candidates)];
    }

    /** @param  list<Connection>  $candidates */
    private function pickConnection(Request $request, array $candidates): Connection
    {
        $requested = $request->header('X-Connection-Id');

        if (is_string($requested) && $requested !== '') {
            foreach ($candidates as $candidate) {
                if ($candidate->public_id === $requested) {
                    return $candidate;
                }
            }

            $this->failAccounting(
                'connection_not_found',
                'Geen actieve boekhoudkoppeling met dit connection_id voor dit Account.',
                404,
                ['connections' => $this->describe($candidates)],
            );
        }

        if (count($candidates) > 1) {
            $this->failAccounting(
                'multiple_accounting_connections',
                'Dit Account heeft meerdere boekhoudkoppelingen. Wijs er één aan met de header X-Connection-Id.',
                409,
                ['connections' => $this->describe($candidates)],
            );
        }

        return $candidates[0];
    }

    /**
     * @param  list<Connection>  $candidates
     * @return list<array{connection_id: string, provider: string, administration: ?string}>
     */
    private function describe(array $candidates): array
    {
        return array_map(static fn (Connection $c): array => [
            'connection_id' => (string) $c->public_id,
            'provider' => $c->provider->value,
            'administration' => $c->administratie_id,
        ], $candidates);
    }

    /** @param  array<string, mixed>  $extra */
    private function failAccounting(string $error, string $message, int $status, array $extra = []): never
    {
        throw new HttpResponseException(response()->json([
            'error' => $error,
            'message' => $message,
            ...$extra,
        ], $status));
    }
}
