<?php

declare(strict_types=1);

namespace App\Actions\Connect;

use RuntimeException;

/**
 * De provider bestaat niet, of bestaat wél maar heeft geen OAuth-flow (bv.
 * Snelstart = clientkey) en is dus niet via een authorize-redirect koppelbaar.
 */
class ProviderNotConnectableException extends RuntimeException
{
    public function __construct(public readonly string $provider)
    {
        parent::__construct("Provider is niet koppelbaar via OAuth: {$provider}");
    }
}
