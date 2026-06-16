<?php

namespace App\Providers;

use App\Accounting\AccountingTargetRegistry;
use App\Accounting\Exact\Contracts\ExactReferenceResolver;
use App\Accounting\Exact\DefaultExactReferenceResolver;
use App\Accounting\Exact\ExactAccountingTarget;
use App\Enums\Provider;
use App\Models\User;
use App\Mollie\HubMollieCredentialResolver;
use App\Mollie\MollieAccessTokenResolver;
use App\Mollie\MollieConnectionContext;
use App\OAuth\Exact\ExactOAuthFlow;
use App\OAuth\Mollie\MollieConnectOAuthFlow;
use App\OAuth\OAuthFlowRegistry;
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

        // Accounting-sync: canonical FinancialDocument → boekhoudpakket per provider.
        // Alleen accounting-providers worden geregistreerd (Mollie = betalingen, niet hier).
        $this->app->singleton(AccountingTargetRegistry::class, function (Application $app): AccountingTargetRegistry {
            $registry = new AccountingTargetRegistry($app);
            $registry->register(Provider::Exact->value, ExactAccountingTarget::class);

            return $registry;
        });

        $this->app->bind(ExactReferenceResolver::class, DefaultExactReferenceResolver::class);

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

        Gate::define('viewApiDocs', function (?User $user): bool {
            $token = config('scramble.access_token');

            if (! $token) {
                return false;
            }

            return hash_equals($token, (string) request()->query('token', ''));
        });

        Gate::define('manage-staff', fn (User $user): bool => $user->hasRole('super-admin'));

        RateLimiter::for('api', function (Request $request): Limit {
            $consumerId = $request->user()?->getKey();

            return Limit::perMinute(60)->by($consumerId ? "consumer:{$consumerId}" : "ip:{$request->ip()}");
        });

        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi): void {
                $openApi->secure(SecurityScheme::http('bearer'));
            });
    }
}
