<?php

namespace Tests\Concerns;

use App\Integrations\Mollie\MollieConnectionContext;
use App\Models\Connection;

/**
 * Helper voor tests die de MollieConnectionContext direct moeten vullen
 * zonder de ResolveMollieAccount-middleware te triggeren (bv. unit-stijl
 * tests die Mollie::client() rechtstreeks aanroepen). Gebruik vóór elke
 * SDK-call in de test.
 */
trait BindsMollieConnectionContext
{
    protected function bindMollieConnection(Connection $connection): void
    {
        app(MollieConnectionContext::class)->set($connection);
    }
}
