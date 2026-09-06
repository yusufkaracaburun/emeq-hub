<?php

namespace App\Providers;

use App\Accounting\AccountingTargetRegistry;
use App\Accounting\BookingWarnings;
use App\Accounting\Contracts\ReferenceResolver;
use App\Enums\Provider;
use App\Integrations\DataForSeo\Errors\UpstreamErrorMapper as DataForSeoUpstreamErrorMapper;
use App\Integrations\DataForSeo\Webhooks\DataForSeoEntityResolver;
use App\Integrations\DataForSeo\Webhooks\DataForSeoEventResolver;
use App\Integrations\Errors\UpstreamErrorMapperRegistry;
use App\Integrations\Exact\Accounting\ConnectionMappingExactReferenceResolver;
use App\Integrations\Exact\Accounting\ExactAccountingTarget;
use App\Integrations\Exact\Errors\UpstreamErrorMapper as ExactUpstreamErrorMapper;
use App\Integrations\Exact\OAuth\ExactOAuthFlow;
use App\Integrations\Exact\Webhooks\ExactEntityResolver;
use App\Integrations\Exact\Webhooks\ExactEventResolver;
use App\Integrations\Exact\Webhooks\ExactHubOriginDetector;
use App\Integrations\Itheorie\Errors\UpstreamErrorMapper as ItheorieUpstreamErrorMapper;
use App\Integrations\Itheorie\HubItheorieCredentialResolver;
use App\Integrations\Itheorie\Webhooks\ItheorieEntityResolver;
use App\Integrations\Itheorie\Webhooks\ItheorieEventResolver;
use App\Integrations\Mollie\Errors\UpstreamErrorMapper as MollieUpstreamErrorMapper;
use App\Integrations\Mollie\HubMollieCredentialResolver;
use App\Integrations\Mollie\MollieAccessTokenResolver;
use App\Integrations\Mollie\MollieConnectionContext;
use App\Integrations\Mollie\OAuth\MollieConnectOAuthFlow;
use App\Integrations\Mollie\Webhooks\MollieEntityResolver;
use App\Integrations\Mollie\Webhooks\MollieEventResolver;
use App\Integrations\OAuth\OAuthFlowRegistry;
use App\Integrations\Snelstart\Errors\UpstreamErrorMapper as SnelstartUpstreamErrorMapper;
use App\Integrations\Snelstart\Webhooks\SnelstartEntityResolver;
use App\Integrations\Snelstart\Webhooks\SnelstartEventResolver;
use App\Integrations\Webhooks\CanonicalEntityRegistry;
use App\Integrations\Webhooks\CanonicalEventRegistry;
use App\Integrations\Webhooks\HubOriginRegistry;
use App\Models\User;
use App\Support\Sentry\SuppressTestEvents;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Emeq\ItheorieApi\Contracts\ItheorieCredentialResolver;
use Emeq\MollieApi\Contracts\MollieCredentialResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use Opcodes\LogViewer\Facades\LogViewer;
use Sentry\State\HubInterface;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(MollieConnectionContext::class);
        $this->app->scoped(BookingWarnings::class);

        $this->app->bind(ItheorieCredentialResolver::class, HubItheorieCredentialResolver::class);

        $this->app->singleton(OAuthFlowRegistry::class, function (Application $app): OAuthFlowRegistry {
            $registry = new OAuthFlowRegistry($app);
            $registry->register(Provider::Mollie->value, MollieConnectOAuthFlow::class);
            $registry->register(Provider::Exact->value, ExactOAuthFlow::class);

            return $registry;
        });

        $this->app->singleton(UpstreamErrorMapperRegistry::class, function (): UpstreamErrorMapperRegistry {
            $registry = new UpstreamErrorMapperRegistry;
            $registry->register(Provider::Exact->value, ExactUpstreamErrorMapper::class);
            $registry->register(Provider::Mollie->value, MollieUpstreamErrorMapper::class);
            $registry->register(Provider::Snelstart->value, SnelstartUpstreamErrorMapper::class);
            $registry->register(Provider::DataForSeo->value, DataForSeoUpstreamErrorMapper::class);
            $registry->register(Provider::Itheorie->value, ItheorieUpstreamErrorMapper::class);

            return $registry;
        });

        $this->app->singleton(CanonicalEventRegistry::class, function (): CanonicalEventRegistry {
            $registry = new CanonicalEventRegistry;
            $registry->register(Provider::Exact, ExactEventResolver::class);
            $registry->register(Provider::Mollie, MollieEventResolver::class);
            $registry->register(Provider::Snelstart, SnelstartEventResolver::class);
            $registry->register(Provider::DataForSeo, DataForSeoEventResolver::class);
            $registry->register(Provider::Itheorie, ItheorieEventResolver::class);

            return $registry;
        });

        $this->app->singleton(HubOriginRegistry::class, function (): HubOriginRegistry {
            $registry = new HubOriginRegistry;
            $registry->register(Provider::Exact, ExactHubOriginDetector::class);

            return $registry;
        });

        $this->app->singleton(CanonicalEntityRegistry::class, function (): CanonicalEntityRegistry {
            $registry = new CanonicalEntityRegistry;
            $registry->register(Provider::Exact, ExactEntityResolver::class);
            $registry->register(Provider::Mollie, MollieEntityResolver::class);
            $registry->register(Provider::Snelstart, SnelstartEntityResolver::class);
            $registry->register(Provider::DataForSeo, DataForSeoEntityResolver::class);
            $registry->register(Provider::Itheorie, ItheorieEntityResolver::class);

            return $registry;
        });

        $this->app->singleton(AccountingTargetRegistry::class, function (Application $app): AccountingTargetRegistry {
            $registry = new AccountingTargetRegistry($app);
            $registry->register(Provider::Exact->value, ExactAccountingTarget::class);

            return $registry;
        });

        $this->app->when(ExactAccountingTarget::class)
            ->needs(ReferenceResolver::class)
            ->give(ConnectionMappingExactReferenceResolver::class);

        $this->app->bind(MollieCredentialResolver::class, HubMollieCredentialResolver::class);

        $this->app->singleton(MollieAccessTokenResolver::class, fn (Application $app): MollieAccessTokenResolver => new MollieAccessTokenResolver(
            $app->make(MollieConnectionContext::class),
            static fn (): ?string => $app['config']->get('services.mollie.partner_access_token'),
        ));

        Cashier::ignoreRoutes();

        // Set directly on the built Client's Options, never on config():
        // sentry-laravel's own ServiceProvider::boot() eagerly resolves
        // HubInterface on every bootstrap (including `artisan config:cache`
        // itself), so any config()->set() with a real Closure is still
        // sitting in the repository when config:cache var_export()'s it —
        // crashes `artisan optimize` in production (Closure has no
        // __set_state()). Mutating the SDK's own Options object sidesteps
        // Laravel's config cache entirely.
        $this->app->booted(function (): void {
            if ($this->app->bound(HubInterface::class)) {
                $this->app->make(HubInterface::class)->getClient()
                    ?->getOptions()
                    ->setBeforeSendCallback(SuppressTestEvents::handle(...));
            }
        });
    }

    public function boot(): void
    {
        LogViewer::auth(fn (Request $request): bool => $request->user()?->hasRole('super-admin') ?? false);

        Gate::define('manage-staff', fn (User $user): bool => $user->hasRole('super-admin'));

        RateLimiter::for('api', function (Request $request): Limit {
            $consumerId = $request->user()?->getKey();
            $scope = $consumerId ? "consumer:{$consumerId}" : "ip:{$request->ip()}";

            $isWrite = ! $request->isMethodSafe();
            $limit = (int) config($isWrite ? 'hub.rate_limits.writes_per_minute' : 'hub.rate_limits.reads_per_minute');

            return Limit::perMinute(max(1, $limit))->by($scope.':'.($isWrite ? 'write' : 'read'));
        });

        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi): void {
                $openApi->secure(SecurityScheme::http('bearer'));
            });
    }
}
