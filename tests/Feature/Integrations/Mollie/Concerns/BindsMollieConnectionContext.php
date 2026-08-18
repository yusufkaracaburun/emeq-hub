<?php

namespace Tests\Feature\Integrations\Mollie\Concerns;

use App\Integrations\Mollie\MollieConnectionContext;
use App\Models\Connection;

trait BindsMollieConnectionContext
{
    protected function bindMollieConnection(Connection $connection): void
    {
        app(MollieConnectionContext::class)->set($connection);
    }
}
