<?php

declare(strict_types=1);

namespace App\Accounting\Contracts;

use App\Accounting\Validation\Finding;
use App\Models\Connection;

/**
 * Capability `validation.enrich`.
 *
 * Provider-specifieke findings bovenop het agnostische DocumentInspector-rapport —
 * bijvoorbeeld "deze btw-code bestaat niet in jouw administratie". Read-only: een
 * dry-run mag niets muteren.
 */
interface EnrichesValidation
{
    /**
     * @param  array<string, mixed>  $payload
     * @return list<Finding>
     */
    public function enrichValidation(array $payload, Connection $connection): array;
}
