<?php

declare(strict_types=1);

namespace App\Http\Concerns;

use App\Models\Account;
use App\Models\Connection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/**
 * Resolvet de boekhoud-Connection voor een consumer-request via de strikte keten
 * `Bearer → Consumer → Account (X-Account-Id) → actieve Connection`. Gedeeld door de
 * boek- en validate-edges; de ability-check blijft per controller (boeken = write,
 * valideren = read). Faalpaden gooien de exacte JSON-respons als HttpResponseException.
 */
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

        /** @var Connection|null $connection */
        $connection = $account->connections()
            ->whereNull('revoked_at')
            ->whereIn('provider', $providers)
            ->first();

        if ($connection === null) {
            $this->failAccounting('no_accounting_connection', 'Geen actieve boekhoud-Connection voor dit Account.', 404);
        }

        return [$account, $connection];
    }

    private function failAccounting(string $error, string $message, int $status): never
    {
        throw new HttpResponseException(response()->json([
            'error' => $error,
            'message' => $message,
        ], $status));
    }
}
