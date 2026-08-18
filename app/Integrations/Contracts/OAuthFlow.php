<?php

namespace App\Integrations\Contracts;

use App\Models\Account;
use App\Models\Connection;

interface OAuthFlow
{
    /** @param  list<string>  $scopes */
    public function getAuthorizationUrl(Account $account, array $scopes, string $state): string;

    public function exchangeCode(Connection $connection, string $code): Connection;

    public function refreshToken(Connection $connection): Connection;

    public function revoke(Connection $connection): void;
}
