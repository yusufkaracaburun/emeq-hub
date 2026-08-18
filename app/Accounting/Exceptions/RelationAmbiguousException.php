<?php

declare(strict_types=1);

namespace App\Accounting\Exceptions;

use RuntimeException;

class RelationAmbiguousException extends RuntimeException
{
    /** @param  list<array{id: string, name: string}>  $candidates */
    public function __construct(string $message, public readonly array $candidates)
    {
        parent::__construct($message);
    }
}
