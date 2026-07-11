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
    /**
     * Zonder bereikbare database moet boot() stil overslaan, niet gooien.
     *
     * Dit is geen theoretisch geval: `composer install` draait
     * `artisan package:discover` in de Docker-build, waar geen database bestaat.
     * Een gooiende provider laat de hele image-build klappen.
     */
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
