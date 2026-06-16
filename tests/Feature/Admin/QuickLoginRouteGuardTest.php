<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Application;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Regressie-vangnet voor REVIEW.md CR-01 — /admin/quick-login moet STRICT
 * geguard zijn met `app()->environment('local', 'testing')`, niet de bredere
 * `! app()->isProduction()` (die op preview/staging-deploys open zou staan).
 */
class QuickLoginRouteGuardTest extends TestCase
{
    public function test_quick_login_route_is_registered_when_environment_is_testing(): void
    {
        // PHPUnit-runner draait altijd in `testing`-env → route bestaat
        $this->assertSame('testing', $this->app->environment());
        $this->assertNotNull(app('router')->getRoutes()->getByName('admin.quick-login'));
    }

    public function test_quick_login_route_is_no_t_registered_on_preview_or_staging_environments(): void
    {
        foreach (['staging', 'preview', 'uat', 'production'] as $env) {
            $app = $this->createFreshApp($env);
            $this->assertNull(
                $app['router']->getRoutes()->getByName('admin.quick-login'),
                "Quick-login route mag NIET bestaan in env={$env} — CR-01 regressie."
            );
        }
    }

    private function createFreshApp(string $env): Application
    {
        $app = require __DIR__.'/../../../bootstrap/app.php';
        $app['env'] = $env;
        $app->detectEnvironment(fn () => $env);

        // Re-laad routes/web.php tegen deze app instance zonder de testing-cache.
        Route::clearResolvedInstances();
        $router = $app->make('router');
        $router->setRoutes(new RouteCollection);
        require __DIR__.'/../../../routes/web.php';

        return $app;
    }
}
