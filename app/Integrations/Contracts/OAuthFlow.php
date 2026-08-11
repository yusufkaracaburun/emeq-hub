<?php

namespace App\Integrations\Contracts;

use App\Models\Account;
use App\Models\Connection;

interface OAuthFlow
{
    /**
     * Bouw de authorize-URL die de browser naar de partner stuurt.
     *
     * @param  list<string>  $scopes
     */
    public function getAuthorizationUrl(Account $account, array $scopes, string $state): string;

    /**
     * Ruil authorization-code in voor access/refresh tokens en schrijf
     * encrypted naar de Connection. Zet status='active' en oauth_state=null.
     */
    public function exchangeCode(Connection $connection, string $code): Connection;

    /**
     * Lazy-refresh de access_token bij naderende expiry. Idempotent —
     * mag meermaals aangeroepen worden binnen het refresh-window.
     */
    public function refreshToken(Connection $connection): Connection;

    /**
     * Trek de koppeling in bij de partner én zet status='revoked' lokaal.
     */
    public function revoke(Connection $connection): void;
}
