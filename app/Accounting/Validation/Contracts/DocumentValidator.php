<?php

declare(strict_types=1);

namespace App\Accounting\Validation\Contracts;

use App\Accounting\Validation\Finding;

/**
 * Provider-agnostische validator over een geëxtraheerd draft-document. Leest de
 * payload-array defensief (de input is bewust ongesaneerd — fouten vinden is de taak)
 * en geeft nul of meer findings terug.
 */
interface DocumentValidator
{
    /**
     * @param  array<string, mixed>  $payload
     * @return list<Finding>
     */
    public function validate(array $payload): array;
}
