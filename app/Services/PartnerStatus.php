<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Account;
use App\Models\Connection;
use Illuminate\Support\Collection;

/**
 * Plan 08-05 — Read-only aggregate voor /dev/partners-pagina's (D-06, UI-SPEC §S3).
 *
 * Levert per-Account de Connection-status voor één provider in één eager-loaded
 * query (N+1-guard, T-08-05-04). Status-set komt uit UI-SPEC §Color
 * "Status-widget semantic palette": connected / pending / revoked / none.
 *
 * Status-bepaling:
 * - revoked  → Connection.revoked_at !== null
 * - connected → Connection heeft access_token (OAuth) OF client_key (key-based)
 * - pending  → Connection bestaat maar mist beide credentials (mid-OAuth-flow)
 * - none     → Account heeft geen Connection voor deze provider
 *
 * Geen state, geen writes — index-card-totalen + status-widget-rows in
 * Blade-views consumen via app(PartnerStatus::class)->forProvider($key).
 */
final class PartnerStatus
{
    /**
     * @return Collection<int, array{account: Account, connection: ?Connection, status: string}>
     */
    public function forProvider(string $provider): Collection
    {
        return Account::query()
            ->with(['connections' => fn ($q) => $q->where('provider', $provider)])
            ->get()
            ->map(fn (Account $account): array => [
                'account' => $account,
                'connection' => $account->connections->first(),
                'status' => $this->resolveStatus($account->connections->first()),
            ]);
    }

    /**
     * Aggregate voor index-card "Mollie: 1/2 Accounts gekoppeld" (UI-SPEC §S3 regel 200).
     *
     * @return array{connected: int, total: int}
     */
    public function totalsForProvider(string $provider): array
    {
        $entries = $this->forProvider($provider);

        return [
            'connected' => $entries->where('status', 'connected')->count(),
            'total' => $entries->count(),
        ];
    }

    private function resolveStatus(?Connection $connection): string
    {
        if ($connection === null) {
            return 'none';
        }
        if ($connection->revoked_at !== null) {
            return 'revoked';
        }
        // OAuth providers gebruiken access_token; key-based providers (Snelstart) client_key.
        if ($connection->access_token !== null || $connection->client_key !== null) {
            return 'connected';
        }

        return 'pending';
    }
}
