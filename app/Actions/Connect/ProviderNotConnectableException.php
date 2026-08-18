<?php

declare(strict_types=1);

namespace App\Actions\Connect;

use RuntimeException;

class ProviderNotConnectableException extends RuntimeException
{
    public function __construct(public readonly string $provider)
    {
        parent::__construct("Provider is niet koppelbaar via OAuth: {$provider}");
    }
}
