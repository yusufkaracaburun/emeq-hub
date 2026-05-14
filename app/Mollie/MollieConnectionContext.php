<?php

namespace App\Mollie;

use App\Models\Connection;
use RuntimeException;

final class MollieConnectionContext
{
    private ?Connection $connection = null;

    public function set(Connection $connection): void
    {
        $this->connection = $connection;
    }

    public function current(): Connection
    {
        if ($this->connection === null) {
            throw new RuntimeException(
                'MollieConnectionContext: geen current Connection gezet. '
                .'Roep set() aan voordat HubMollieCredentialResolver wordt aangeroepen.'
            );
        }

        return $this->connection;
    }

    public function has(): bool
    {
        return $this->connection !== null;
    }
}
