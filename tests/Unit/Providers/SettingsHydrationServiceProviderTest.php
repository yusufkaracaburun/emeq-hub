<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Providers\SettingsHydrationServiceProvider;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use PDOException;
use Tests\TestCase;

class SettingsHydrationServiceProviderTest extends TestCase
{
    public function test_boot_slaat_over_als_de_database_onbereikbaar_is(): void
    {
        Schema::shouldReceive('hasTable')
            ->once()
            ->with('settings')
            ->andThrow(new QueryException(
                'pgsql',
                'select 1',
                [],
                new PDOException('SQLSTATE[08006] connection to server failed: Connection refused')
            ));

        config(['services.exact.client_id' => null]);

        (new SettingsHydrationServiceProvider($this->app))->boot();

        $this->assertNull(config('services.exact.client_id'));
    }
}
