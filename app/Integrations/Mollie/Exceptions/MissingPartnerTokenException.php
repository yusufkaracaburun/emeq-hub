<?php

namespace App\Integrations\Mollie\Exceptions;

use RuntimeException;
use Throwable;

final class MissingPartnerTokenException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(
            'Mollie partner-access-token niet geconfigureerd op Hub. Contact Emeq-staff.',
            0,
            $previous,
        );
    }
}
