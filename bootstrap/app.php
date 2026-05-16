<?php

use App\Http\Middleware\EnsureEmeqAdminToken;
use App\Http\Middleware\EnsureProviderEnabled;
use App\Http\Middleware\RequireCashierWebhookSecret;
use App\Http\Middleware\ResolveMollieAccount;
use App\Http\Middleware\ResolveSnelstartAccount;
use App\Http\Middleware\SetNoIndexHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('api')
                ->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SetNoIndexHeaders::class);
        $middleware->api(prepend: ['throttle:api']);
        $middleware->alias([
            'resolve.snelstart.account' => ResolveSnelstartAccount::class,
            'resolve.mollie.account' => ResolveMollieAccount::class,
            'emeq.admin' => EnsureEmeqAdminToken::class,
            'cashier.webhook.secret' => RequireCashierWebhookSecret::class,
            'feature.provider' => EnsureProviderEnabled::class,
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Integration::handles($exceptions);
    })->create();
