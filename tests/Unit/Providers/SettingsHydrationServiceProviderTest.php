<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Providers\SettingsHydrationServiceProvider;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Mockery;
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

    public function test_boot_rapporteert_geen_query_exception_want_db_onbereikbaar_is_verwacht(): void
    {
        Schema::shouldReceive('hasTable')
            ->once()
            ->with('settings')
            ->andThrow(new QueryException(
                'pgsql',
                'select 1',
                [],
                new PDOException('SQLSTATE[08006] could not find driver')
            ));

        $handler = Mockery::mock(ExceptionHandler::class);
        $handler->shouldNotReceive('report');
        $this->app->instance(ExceptionHandler::class, $handler);

        (new SettingsHydrationServiceProvider($this->app))->boot();
    }
}
