<?php

declare(strict_types=1);

namespace App\Integrations\DataForSeo;

use App\Models\Connection;
use Emeq\DataForSeoApi\Contracts\DataForSeoCredentialResolver;
use Emeq\DataForSeoApi\Data\DataForSeoCredentials;

final readonly class HubDataForSeoCredentialResolver implements DataForSeoCredentialResolver
{
    public function __construct(
        private Connection $connection,
    ) {}

    public function resolve(): DataForSeoCredentials
    {
        $raw = (string) $this->connection->access_token;

        $parts = explode(':', $raw, 2);
        $login = $parts[0];
        $password = $parts[1] ?? '';

        return new DataForSeoCredentials(
            login: $login,
            password: $password,
        );
    }
}
