<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

use App\Models\Connection;

interface DetectsHubOrigin
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function causedByHub(Connection $connection, array $payload): bool;
}
