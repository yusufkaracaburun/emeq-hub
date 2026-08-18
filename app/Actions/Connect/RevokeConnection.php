<?php

declare(strict_types=1);

namespace App\Actions\Connect;

use App\Integrations\Exceptions\ProviderDisabledException;
use App\Integrations\OAuth\OAuthFlowRegistry;
use App\Models\Connection;

class RevokeConnection
{
    public function __construct(private readonly OAuthFlowRegistry $registry) {}

    public function handle(Connection $connection): void
    {
        $provider = $connection->provider->value;

        if (in_array($provider, $this->registry->providers(), true)) {
            try {
                $this->registry->for($provider)->revoke($connection);

                return;
            } catch (ProviderDisabledException) {
            }
        }

        $connection->update(['status' => 'revoked', 'revoked_at' => now()]);
    }
}
