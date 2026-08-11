<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureEmeqAdminToken;
use App\Http\Middleware\EnsureIdempotency;
use App\Http\Middleware\EnsureProviderEnabled;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\NormalizeApiErrors;
use App\Http\Middleware\RequireCashierWebhookSecret;
use App\Http\Middleware\ResolveExactAccount;
use App\Http\Middleware\ResolveMollieAccount;
use App\Http\Middleware\ResolveSnelstartAccount;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetNoIndexHeaders;
use App\Support\Seo\SeoMeta;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
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
        // Achter Cloudflare termineert CF de TLS; vertrouw X-Forwarded-Proto/For zodat
        // Laravel https detecteert (secure cookies, url()) en de echte client-IP ziet.
        // Beperk origin-poort 80 tot Cloudflare-IP-ranges (firewall) of gebruik een
        // Cloudflare Tunnel — anders is at:'*' spoofbaar.
        $middleware->trustProxies(at: '*');

        // De default guest-redirect mikt op de (niet-bestaande) `login`-route en
        // wordt door de Authenticate-middleware al geëvalueerd vóór de exception
        // handler — een ongeauthenticeerde `/v1/*`-request zonder
        // `Accept: application/json` zou zo crashen op `Route [login] not defined`
        // (500). Voor de API → geen redirect; de handler levert JSON 401.
        $middleware->redirectGuestsTo(
            fn (Request $request): ?string => $request->is('v1/*') ? null : route('login'),
        );

        // Als eerste in de globale stack: ook een 401 of een gethrottled request
        // moet een correlatie-id dragen en de foutenvelope volgen. NormalizeApiErrors
        // staat ná AssignRequestId zodat het correlatie-id er al is, en buitenom alles
        // zodat het ook gerenderde exceptions ziet.
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
        // /v1/* is een pure JSON-API: render élke exception als JSON, ook als de
        // consumer-proxy geen `Accept: application/json` stuurt. Voorkomt de
        // `Route [login] not defined`-redirect (500) bij een ontbrekende/ongeldige PAT.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('v1/*') || $request->expectsJson(),
        );

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('v1/*')) {
                return response()->json(['code' => 'unauthenticated', 'message' => 'Unauthenticated.'], 401);
            }

            return null;
        });

        // Een verlopen of gemanipuleerde handoff-link is voor de eindgebruiker
        // geen kale 403 maar een verwachte situatie: de link is bewust
        // kortlevend. Toon dezelfde pagina met uitleg + terugweg. Andere
        // signed-routes houden hun standaardgedrag.
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
