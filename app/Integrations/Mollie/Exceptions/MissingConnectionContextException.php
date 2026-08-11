<?php

namespace App\Integrations\Mollie\Exceptions;

use RuntimeException;
use Throwable;

final class MissingConnectionContextException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(
            'MollieConnectionContext is leeg — geen current Connection gezet. Roep ResolveMollieAccount-middleware aan voor deze route.',
            0,
            $previous,
        );
    }
}
