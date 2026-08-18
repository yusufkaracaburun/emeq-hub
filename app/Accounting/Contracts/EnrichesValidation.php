<?php

declare(strict_types=1);

namespace App\Accounting\Contracts;

use App\Accounting\Validation\Finding;
use App\Models\Connection;

interface EnrichesValidation
{
    /**
     * @param  array<string, mixed>  $payload
     * @return list<Finding>
     */
    public function enrichValidation(array $payload, Connection $connection): array;
}
