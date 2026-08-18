<?php

declare(strict_types=1);

namespace App\Accounting\Validation\Contracts;

use App\Accounting\Validation\Finding;

interface DocumentValidator
{
    /**
     * @param  array<string, mixed>  $payload
     * @return list<Finding>
     */
    public function validate(array $payload): array;
}
