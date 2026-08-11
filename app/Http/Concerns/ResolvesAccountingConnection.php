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
 * boek-, validate- en lees-edges; de ability-check blijft per controller (boeken =
 * write, lezen = read). Faalpaden gooien de exacte JSON-respons als
 * HttpResponseException.
 *
 * Heeft een Account meer dan één boekhoudkoppeling, dan moet de consumer kiezen met
 * `X-Provider`. Zolang er precies één is blijft die header optioneel — impliciet
 * werken is het normale geval en hoeft niet duurder te worden.
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

    /**
     * @param  list<Connection>  $candidates
     */
    private function pickConnection(Request $request, array $candidates): Connection
    {
        $requested = $request->header('X-Provider');
        $available = array_map(static fn (Connection $c): string => $c->provider->value, $candidates);

        if (is_string($requested) && $requested !== '') {
            foreach ($candidates as $candidate) {
                if ($candidate->provider->value === $requested) {
                    return $candidate;
                }
            }

            $this->failAccounting(
                'no_accounting_connection',
                "Geen actieve '{$requested}'-koppeling voor dit Account. Beschikbaar: ".implode(', ', $available).'.',
                404,
                ['providers' => $available],
            );
        }

        if (count($candidates) > 1) {
            // Zonder deze afslag koos `->first()` op rij-volgorde welk boekhoudpakket
            // de boeking kreeg. Dat is niet zichtbaar voor de consumer en niet stabiel
            // tussen requests, dus liever weigeren dan gokken.
            $this->failAccounting(
                'multiple_accounting_connections',
                'Dit Account heeft meerdere boekhoudkoppelingen. Kies er één met de header X-Provider. Beschikbaar: '.implode(', ', $available).'.',
                409,
                ['providers' => $available],
            );
        }

        return $candidates[0];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function failAccounting(string $error, string $message, int $status, array $extra = []): never
    {
        throw new HttpResponseException(response()->json([
            'error' => $error,
            'message' => $message,
            ...$extra,
        ], $status));
    }
}
