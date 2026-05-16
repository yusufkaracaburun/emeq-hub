<?php

declare(strict_types=1);

namespace App\OAuth\Exceptions;

use RuntimeException;

final class ProviderDisabledException extends RuntimeException
{
    public function __construct(public readonly string $provider)
    {
        parent::__construct("Provider '{$provider}' is uitgeschakeld via feature-flag.");
    }
}
