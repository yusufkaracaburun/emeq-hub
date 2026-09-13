<?php

declare(strict_types=1);

namespace Tests\Unit;

use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Listeners\EnforceRequestScheme;
use Tests\TestCase;

class OctaneConfigTest extends TestCase
{
    public function test_enforce_request_scheme_listener_is_registered(): void
    {
        $listeners = config('octane.listeners.'.RequestReceived::class, []);

        $this->assertContains(EnforceRequestScheme::class, $listeners);
    }
}
