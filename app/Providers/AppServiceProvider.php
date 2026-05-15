<?php

namespace App\Providers;

use App\Models\User;
use App\Mollie\HubMollieCredentialResolver;
use App\Mollie\MollieConnectionContext;
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

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(MollieConnectionContext::class);

        $this->app->singleton(OAuthFlowRegistry::class, function (Application $app): OAuthFlowRegistry {
            $registry = new OAuthFlowRegistry($app);
            $registry->register('mollie', MollieConnectOAuthFlow::class);

            return $registry;
        });

        $this->app->bind(MollieCredentialResolver::class, HubMollieCredentialResolver::class);

        // D-10: Cashier's default-routes (webhooks/mollie*) uitzetten zodat wij ze
        // zelf onder /cashier/webhook* registreren achter RequireCashierWebhookSecret.
        // Moet in register() staan — CashierServiceProvider::boot() leest deze flag.
        Cashier::ignoreRoutes();
    }

    public function boot(): void
    {
        Gate::define('viewApiDocs', function (?User $user): bool {
            $token = config('scramble.access_token');

            if (! $token) {
                return false;
            }

            return hash_equals($token, (string) request()->query('token', ''));
        });

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
