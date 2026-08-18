<?php

use App\Enums\Provider;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureEmeqAdminToken;
use App\Http\Middleware\EnsureIdempotency;
use App\Http\Middleware\EnsureProviderEnabled;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\NormalizeApiErrors;
use App\Http\Middleware\RequireCashierWebhookSecret;
use App\Http\Middleware\ResolveExactAccount;
use App\Http\Middleware\ResolveMollieAccount;
use App\Integrations\Snelstart\Http\Middleware\ResolveSnelstartAccount;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetNoIndexHeaders;
use App\Mail\ConnectionNeedsConsent;
use App\Models\Connection;
use App\Support\Seo\SeoMeta;
use Emeq\ExactApi\Exceptions\AuthenticationException as ExactAuthenticationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
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
        $middleware->trustProxies(at: '*');

        $middleware->redirectGuestsTo(
            fn (Request $request): ?string => $request->is('v1/*') ? null : route('login'),
        );

        $middleware->prepend([AssignRequestId::class, NormalizeApiErrors::class]);

        $middleware->append(SecurityHeaders::class);
        $middleware->append(SetNoIndexHeaders::class);
        $middleware->web(append: [HandleInertiaRequests::class]);
        $middleware->api(prepend: ['throttle:api']);
        $middleware->alias([
            'resolve.snelstart.account' => ResolveSnelstartAccount::class,
            'resolve.mollie.account' => ResolveMollieAccount::class,
            'resolve.exact.account' => ResolveExactAccount::class,
            'emeq.admin' => EnsureEmeqAdminToken::class,
            'cashier.webhook.secret' => RequireCashierWebhookSecret::class,
            'feature.provider' => EnsureProviderEnabled::class,
            'idempotent' => EnsureIdempotency::class,
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('v1/*') || $request->expectsJson(),
        );

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('v1/*')) {
                return response()->json(['code' => 'unauthenticated', 'message' => 'Unauthenticated.'], 401);
            }

            return null;
        });

        $exceptions->report(function (ExactAuthenticationException $e): void {
            if (! $e->requiresReconsent || $e->connectionRef === null) {
                return;
            }

            $connection = Connection::query()
                ->whereKey($e->connectionRef)
                ->where('provider', Provider::Exact->value)
                ->where('status', '!=', 'needs_consent')
                ->first();

            if ($connection === null) {
                return;
            }

            $connection->update(['status' => 'needs_consent']);

            Mail::to(config('mail.notify_address'))->send(new ConnectionNeedsConsent($connection));
        });

        $exceptions->render(function (InvalidSignatureException $e, Request $request) {
            if (! $request->is('connect/*') || $request->expectsJson()) {
                return null;
            }

            return Inertia::render('connect/index', [
                'state' => 'expired',
                'consumerName' => null,
                'accountName' => null,
                'providers' => [],
                'returnUrl' => null,
                'expiresAt' => null,
                'seo' => SeoMeta::make('Koppellink verlopen', 'Deze koppellink is niet meer geldig.'),
            ])->toResponse($request)->setStatusCode(410);
        });

        Integration::handles($exceptions);
    })->create();
