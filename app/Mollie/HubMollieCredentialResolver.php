<?php

namespace App\Mollie;

use App\Enums\Provider;
use App\OAuth\OAuthFlowRegistry;
use Emeq\MollieApi\Contracts\MollieCredentialResolver;
use Emeq\MollieApi\Data\MollieCredentials;
use Emeq\MollieApi\Data\MollieOAuthCredentials;

final class HubMollieCredentialResolver implements MollieCredentialResolver
{
    public function __construct(
        private readonly MollieConnectionContext $context,
        private readonly OAuthFlowRegistry $registry,
    ) {}

    public function resolve(): MollieCredentials
    {
        $connection = $this->context->current();

        if ($connection->expires_at && $connection->expires_at->lt(now()->addMinutes(5))) {
            $connection = $this->registry->for(Provider::Mollie->value)->refreshToken($connection);
        }

        return new MollieOAuthCredentials(
            accessToken: $connection->access_token,
            expiresAt: $connection->expires_at?->getTimestamp(),
        );
    }
}
