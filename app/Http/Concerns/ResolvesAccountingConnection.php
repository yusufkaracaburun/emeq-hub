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
 * Heeft een Account meer dan één boekhoudkoppeling, dan wijst de consumer er één aan
 * met `X-Connection-Id` — de publieke sleutel uit `GET /v1/integrations`. Zolang er
 * precies één is blijft die header optioneel: impliciet werken is het normale geval
 * en hoeft niet duurder te worden.
 *
 * Bewust géén provider-header. De Unified API belooft dat een consumer niet hoeft te
 * weten welk boekhoudpakket eronder hangt; een `X-Provider` zou die belofte breken en
 * kan bovendien twee koppelingen bij dezelfde provider niet uit elkaar houden.
 *
 * De keten blijft strikt: de kandidaten komen uit `$account->connections()`, en dat
 * Account is al op de Consumer van de Bearer-token gescopet. Een id van een andere
 * Consumer matcht dus niets.
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
            // Zonder deze afslag koos `->first()` op rij-volgorde welke administratie
            // de boeking kreeg. Dat is niet zichtbaar voor de consumer en niet stabiel
            // tussen requests, dus liever weigeren dan gokken.
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
     * Genoeg om de consumer een keuze te laten tonen: de sleutel om mee te sturen,
     * plus waar die koppeling naartoe wijst. De provider staat er als label bij, niet
     * als selector — kiezen gebeurt op `connection_id`.
     *
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
