<?php

namespace App\Providers;

use App\Accounting\AccountingTargetRegistry;
use App\Accounting\Contracts\ReferenceResolver;
use App\Enums\Provider;
use App\Integrations\Errors\UpstreamErrorMapperRegistry;
use App\Integrations\Exact\Accounting\ConnectionMappingExactReferenceResolver;
use App\Integrations\Exact\Accounting\ExactAccountingTarget;
use App\Integrations\Exact\Errors\UpstreamErrorMapper as ExactUpstreamErrorMapper;
use App\Integrations\Exact\OAuth\ExactOAuthFlow;
use App\Integrations\Exact\Webhooks\ExactEchoDetector;
use App\Integrations\Exact\Webhooks\ExactEventResolver;
use App\Integrations\Mollie\Errors\UpstreamErrorMapper as MollieUpstreamErrorMapper;
use App\Integrations\Mollie\HubMollieCredentialResolver;
use App\Integrations\Mollie\MollieAccessTokenResolver;
use App\Integrations\Mollie\MollieConnectionContext;
use App\Integrations\Mollie\OAuth\MollieConnectOAuthFlow;
use App\Integrations\Mollie\Webhooks\MollieEventResolver;
use App\Integrations\OAuth\OAuthFlowRegistry;
use App\Integrations\Snelstart\Errors\UpstreamErrorMapper as SnelstartUpstreamErrorMapper;
use App\Integrations\Snelstart\Webhooks\SnelstartEventResolver;
use App\Integrations\Webhooks\CanonicalEventRegistry;
use App\Integrations\Webhooks\HubOriginRegistry;
use App\Models\User;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Emeq\MollieApi\Contracts\MollieCredentialResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use Opcodes\LogViewer\Facades\LogViewer;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(MollieConnectionContext::class);

        $this->app->singleton(OAuthFlowRegistry::class, function (Application $app): OAuthFlowRegistry {
            $registry = new OAuthFlowRegistry($app);
            $registry->register(Provider::Mollie->value, MollieConnectOAuthFlow::class);
            $registry->register(Provider::Exact->value, ExactOAuthFlow::class);

            return $registry;
        });

        // Partner-exceptions → Hub-HTTP, per provider. Provider-neutrale code (de
        // accounting-runner, de canonieke lees-endpoints) vraagt hier de juiste
        // mapper op in plaats van er één te importeren.
        $this->app->singleton(UpstreamErrorMapperRegistry::class, function (): UpstreamErrorMapperRegistry {
            $registry = new UpstreamErrorMapperRegistry;
            $registry->register(Provider::Exact->value, ExactUpstreamErrorMapper::class);
            $registry->register(Provider::Mollie->value, MollieUpstreamErrorMapper::class);
            $registry->register(Provider::Snelstart->value, SnelstartUpstreamErrorMapper::class);

            return $registry;
        });

        // Partner-payload → canonieke event-naam. De consumer krijgt één envelope
        // met één vocabulaire, ongeacht welk pakket z'n eindgebruiker koppelde.
        $this->app->singleton(CanonicalEventRegistry::class, function (): CanonicalEventRegistry {
            $registry = new CanonicalEventRegistry;
            $registry->register(Provider::Exact, ExactEventResolver::class);
            $registry->register(Provider::Mollie, MollieEventResolver::class);
            $registry->register(Provider::Snelstart, SnelstartEventResolver::class);

            return $registry;
        });

        $this->app->singleton(HubOriginRegistry::class, function (): HubOriginRegistry {
            $registry = new HubOriginRegistry;
            $registry->register(Provider::Exact, ExactEchoDetector::class);

            return $registry;
        });

        // Accounting-sync: canonical FinancialDocument → boekhoudpakket per provider.
        // Alleen accounting-providers worden geregistreerd (Mollie = betalingen, niet hier).
        $this->app->singleton(AccountingTargetRegistry::class, function (Application $app): AccountingTargetRegistry {
            $registry = new AccountingTargetRegistry($app);
            $registry->register(Provider::Exact->value, ExactAccountingTarget::class);

            return $registry;
        });

        // Eén resolver, want er is één accounting-provider. Zodra provider #2 landt
        // wordt dit contextueel: ->when(ExactAccountingTarget::class)->needs(...)->give(...).
        // Let op bij die stap: contextuele bindings winnen stilzwijgend van deze
        // bind(), dus de `bind(ReferenceResolver::class, <fake>)`-seams in de
        // accounting-tests stoppen dan met werken zónder rood te worden. Die moeten
        // in dezelfde commit mee omgezet worden.
        $this->app->bind(ReferenceResolver::class, ConnectionMappingExactReferenceResolver::class);

        $this->app->bind(MollieCredentialResolver::class, HubMollieCredentialResolver::class);

        // Partner-token via Closure → fresh per resolveFor('partner')-call. Vereist
        // voor long-running workers (Horizon, octane): env-rotatie van
        // MOLLIE_PARTNER_ACCESS_TOKEN werkt anders pas door na container-restart
        // omdat de singleton de string-waarde bij boot capture't.
        $this->app->singleton(MollieAccessTokenResolver::class, fn (Application $app): MollieAccessTokenResolver => new MollieAccessTokenResolver(
            $app->make(MollieConnectionContext::class),
            static fn (): ?string => $app['config']->get('services.mollie.partner_access_token'),
        ));

        // D-10: Cashier's default-routes (webhooks/mollie*) uitzetten zodat wij ze
        // zelf onder /cashier/webhook* registreren achter RequireCashierWebhookSecret.
        // Moet in register() staan — CashierServiceProvider::boot() leest deze flag.
        Cashier::ignoreRoutes();
    }

    public function boot(): void
    {
        // opcodesio/log-viewer (/log-viewer) — alleen super-admin. Hier staan de
        // applicatie-logs (laravel.log), incl. de getAuthorizationUrl-fouten.
        LogViewer::auth(fn (Request $request): bool => $request->user()?->hasRole('super-admin') ?? false);

        Gate::define('manage-staff', fn (User $user): bool => $user->hasRole('super-admin'));

        // Eén limiter-naam volstaat: een tweede named limiter zou per HTTP-methode
        // een andere `throttle:`-alias op de route vergen, maar de Exact/Snelstart
        // pass-through (`Route::any('/{path}', ...)`) bedient lezen én schrijven op
        // dezelfde route — daar valt geen route-group-grens te trekken. In plaats
        // daarvan split de `by()`-sleutel op methode, zodat lezen en schrijven een
        // los budget per consumer krijgen zonder elkaar te verdringen.
        RateLimiter::for('api', function (Request $request): Limit {
            $consumerId = $request->user()?->getKey();
            $scope = $consumerId ? "consumer:{$consumerId}" : "ip:{$request->ip()}";

            $isWrite = ! $request->isMethodSafe();
            $limit = (int) config($isWrite ? 'hub.rate_limits.writes_per_minute' : 'hub.rate_limits.reads_per_minute');

            // Een leeggelaten env-var casat naar 0, en perMinute(0) sluit de hele
            // API af. Een tikfout in .env mag geen totale storing worden.
            return Limit::perMinute(max(1, $limit))->by($scope.':'.($isWrite ? 'write' : 'read'));
        });

        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi): void {
                $openApi->secure(SecurityScheme::http('bearer'));
            });
    }
}
