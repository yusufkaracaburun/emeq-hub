<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\Accounting\DocumentsController as AccountingDocumentsController;
use App\Http\Controllers\Api\V1\Accounting\ValidateDocumentController as AccountingValidateDocumentController;
use App\Http\Controllers\Api\V1\AccountSubscriptions\AccountSubscriptionController;
use App\Http\Controllers\Api\V1\AccountSubscriptions\PauseController;
use App\Http\Controllers\Api\V1\AccountSubscriptions\ResumeController;
use App\Http\Controllers\Api\V1\Billing\SubscriptionController;
use App\Http\Controllers\Api\V1\ConnectionController;
use App\Http\Controllers\Api\V1\Exact\GlAccountsController as ExactGlAccountsController;
use App\Http\Controllers\Api\V1\Exact\JournalsController as ExactJournalsController;
use App\Http\Controllers\Api\V1\Exact\PassThroughController as ExactPassThroughController;
use App\Http\Controllers\Api\V1\Exact\RelationsController as ExactRelationsController;
use App\Http\Controllers\Api\V1\Exact\VatCodesController as ExactVatCodesController;
use App\Http\Controllers\Api\V1\IntegrationController;
use App\Http\Controllers\Api\V1\Mollie\Connect\ClientLinksController as ConnectClientLinksController;
use App\Http\Controllers\Api\V1\Mollie\Connect\OnboardingController as ConnectOnboardingController;
use App\Http\Controllers\Api\V1\Mollie\Connect\OrganizationsController as ConnectOrganizationsController;
use App\Http\Controllers\Api\V1\Mollie\Connect\PermissionsController as ConnectPermissionsController;
use App\Http\Controllers\Api\V1\Mollie\Connect\ProfilesController as ConnectProfilesController;
use App\Http\Controllers\Api\V1\Mollie\CustomersController;
use App\Http\Controllers\Api\V1\Mollie\MandatesController;
use App\Http\Controllers\Api\V1\Mollie\PaymentLinksController;
use App\Http\Controllers\Api\V1\Mollie\PaymentMethodsController;
use App\Http\Controllers\Api\V1\Mollie\PaymentsController;
use App\Http\Controllers\Api\V1\Mollie\RefundsController;
use App\Http\Controllers\Api\V1\Mollie\SubscriptionsController;
use App\Http\Controllers\Api\V1\OAuth\CallbackController;
use App\Http\Controllers\Api\V1\OAuth\ExactCallbackController;
use App\Http\Controllers\Api\V1\OAuth\ProviderInitController;
use App\Http\Controllers\Api\V1\PingController;
use App\Http\Controllers\Api\V1\Snelstart\PassThroughController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — /v1/*
|--------------------------------------------------------------------------
| Prefix `v1` wordt gezet in bootstrap/app.php (apiPrefix). Auth via
| Sanctum-PAT.
*/

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/ping', PingController::class)->name('api.ping');

    Route::post('/accounts', [AccountController::class, 'store'])->name('api.accounts.store');

    Route::post('/connections', [ConnectionController::class, 'store'])->name('api.connections.store');
    Route::get('/connections/{connection}', [ConnectionController::class, 'show'])->name('api.connections.show');
    Route::delete('/connections/{connection}', [ConnectionController::class, 'destroy'])->name('api.connections.destroy');

    // Discovery + per-account koppel-status van alle providers (voedt de
    // consumer-connect-kit). Data-driven: nieuwe provider verschijnt vanzelf.
    Route::get('/integrations', IntegrationController::class)
        ->middleware('ability:integrations:manage,consumer:manage-accounts,*')
        ->name('api.integrations.index');

    Route::middleware(['ability:integrations:manage,mollie:write', 'feature.provider:mollie'])->group(function (): void {
        Route::post('/oauth/mollie/init', ProviderInitController::class)
            ->defaults('provider', 'mollie')
            ->name('api.oauth.mollie.init');
    });

    Route::any('/snelstart/{path}', PassThroughController::class)
        ->where('path', '.*')
        ->middleware(['feature.provider:snelstart', 'resolve.snelstart.account'])
        ->name('api.snelstart.passthrough');

    // Exact Online — OAuth-init + division-aware REST pass-through, gegroepeerd
    // onder de provider-kill-switch (spiegelt het Mollie-blok). Structured
    // resource-routes landen onder de `exact`-prefix vóór de catch-all.
    Route::middleware('feature.provider:exact')->group(function (): void {
        Route::post('/oauth/exact/init', ProviderInitController::class)
            ->defaults('provider', 'exact')
            ->middleware('ability:integrations:manage,exact:write')
            ->name('api.oauth.exact.init');

        Route::prefix('exact')->middleware('resolve.exact.account')->group(function (): void {
            // Named resource-endpoints — vóór de catch-all (route-volgorde). Elk
            // mapt 1-op-1 op één Exact OData-endpoint, met eigen Scramble-groep.
            Route::get('/gl-accounts', [ExactGlAccountsController::class, 'index'])
                ->middleware('ability:exact:read,exact:write,*')
                ->name('api.exact.gl-accounts.index');

            Route::get('/vat-codes', [ExactVatCodesController::class, 'index'])
                ->middleware('ability:exact:read,exact:write,*')
                ->name('api.exact.vat-codes.index');

            Route::get('/relations', [ExactRelationsController::class, 'index'])
                ->middleware('ability:exact:read,exact:write,*')
                ->name('api.exact.relations.index');

            Route::get('/journals', [ExactJournalsController::class, 'index'])
                ->middleware('ability:exact:read,exact:write,*')
                ->name('api.exact.journals.index');

            // Generieke escape-hatch voor elk overig Exact-endpoint.
            Route::any('/{path}', ExactPassThroughController::class)
                ->where('path', '.*')
                ->name('api.exact.passthrough');
        });
    });

    // Generieke OAuth-init voor élke (toekomstige) OAuth-provider. NA de
    // specifieke mollie/exact-init-routes (route-precedence) zodat die hun eigen
    // ability-set houden; de feature-kill-switch zit in de controller via de
    // OAuthFlowRegistry. Snelstart (geen OAuth-flow) → 404.
    Route::post('/oauth/{provider}/init', ProviderInitController::class)
        ->where('provider', '[a-z][a-z0-9_-]*')
        ->middleware('ability:integrations:manage')
        ->name('api.oauth.init');

    // Dry-run validatie van een geëxtraheerd draft-document ("Scan & herstel"):
    // read-only, boekt niets, géén idempotency. Geeft findings + suggesties terug.
    Route::post('/accounting/documents/validate', AccountingValidateDocumentController::class)
        ->name('api.accounting.documents.validate');

    // Provider-agnostische accounting-sync: canonical doc → gekoppeld boekhoudpakket.
    // Account + Connection + provider-gate worden in de controller geresolved
    // (de provider is niet route-vast — kan Exact/Snelstart/… zijn).
    Route::post('/accounting/documents', [AccountingDocumentsController::class, 'store'])
        ->middleware('idempotent:required')
        ->name('api.accounting.documents.store');

    Route::middleware('ability:billing:read,billing:write,*')->group(function (): void {
        Route::get('/billing/subscription', [SubscriptionController::class, 'show'])
            ->name('api.billing.subscription.show');
    });

    Route::prefix('admin/billing')
        ->middleware(['ability:billing:write,*', 'emeq.admin'])
        ->group(function (): void {
            Route::post('/subscriptions', [App\Http\Controllers\Api\V1\Admin\Billing\SubscriptionController::class, 'store'])
                ->name('api.admin.billing.subscriptions.store');
            Route::delete('/subscriptions/{id}', [App\Http\Controllers\Api\V1\Admin\Billing\SubscriptionController::class, 'destroy'])
                ->name('api.admin.billing.subscriptions.destroy');
        });

    Route::prefix('mollie')->middleware(['feature.provider:mollie', 'resolve.mollie.account'])->group(function (): void {
        Route::post('/payments', [PaymentsController::class, 'store'])->name('api.mollie.payments.store');
        Route::get('/payments/{id}', [PaymentsController::class, 'show'])->name('api.mollie.payments.show');
        Route::delete('/payments/{id}', [PaymentsController::class, 'destroy'])->name('api.mollie.payments.destroy');

        Route::get('/customers', [CustomersController::class, 'index'])->name('api.mollie.customers.index');
        Route::get('/customers/{id}', [CustomersController::class, 'show'])->name('api.mollie.customers.show');
        Route::post('/customers', [CustomersController::class, 'store'])->name('api.mollie.customers.store');

        Route::get('/payment-methods', PaymentMethodsController::class)->name('api.mollie.payment-methods.list');

        Route::post('/payments/{id}/refunds', [RefundsController::class, 'store'])->name('api.mollie.payments.refunds.store');
        Route::get('/payments/{id}/refunds', [RefundsController::class, 'index'])->name('api.mollie.payments.refunds.index');
        Route::get('/refunds/{id}', [RefundsController::class, 'show'])->name('api.mollie.refunds.show');

        Route::get('/customers/{id}/mandates', [MandatesController::class, 'index'])->name('api.mollie.customers.mandates.index');
        Route::get('/customers/{id}/mandates/{mandate_id}', [MandatesController::class, 'show'])->name('api.mollie.customers.mandates.show');
        Route::delete('/customers/{id}/mandates/{mandate_id}', [MandatesController::class, 'destroy'])->name('api.mollie.customers.mandates.destroy');

        Route::get('/customers/{id}/subscriptions', [SubscriptionsController::class, 'index'])->name('api.mollie.customers.subscriptions.index');
        Route::post('/customers/{id}/subscriptions', [SubscriptionsController::class, 'store'])->name('api.mollie.customers.subscriptions.store');
        Route::get('/customers/{id}/subscriptions/{sub_id}', [SubscriptionsController::class, 'show'])->name('api.mollie.customers.subscriptions.show');
        Route::delete('/customers/{id}/subscriptions/{sub_id}', [SubscriptionsController::class, 'destroy'])->name('api.mollie.customers.subscriptions.destroy');

        Route::get('/payment-links', [PaymentLinksController::class, 'index'])->name('api.mollie.payment-links.index');
        Route::post('/payment-links', [PaymentLinksController::class, 'store'])->name('api.mollie.payment-links.store');
        Route::get('/payment-links/{id}', [PaymentLinksController::class, 'show'])->name('api.mollie.payment-links.show');
    });

    // Mollie Connect partner-resources — zie .docs/decisions/mollie-connect-partner-resources.md.
    // Géén resolve.mollie.account (D-07): partner-access-token via
    // MollieAccessTokenResolver in de Connect-controllers; geen
    // Account/Connection-context op deze routes.
    Route::prefix('mollie/connect')
        ->middleware(['feature.provider:mollie'])
        ->name('api.mollie.connect.')
        ->group(function (): void {
            Route::get('/onboarding/me', [ConnectOnboardingController::class, 'me'])
                ->name('onboarding.me');

            Route::get('/organizations/me', [ConnectOrganizationsController::class, 'me'])
                ->name('organizations.me');
            Route::get('/organizations/{id}', [ConnectOrganizationsController::class, 'show'])
                ->name('organizations.show');

            Route::get('/profiles', [ConnectProfilesController::class, 'index'])
                ->name('profiles.index');
            Route::post('/profiles', [ConnectProfilesController::class, 'store'])
                ->name('profiles.store');
            Route::get('/profiles/{id}', [ConnectProfilesController::class, 'show'])
                ->name('profiles.show');

            Route::get('/permissions', [ConnectPermissionsController::class, 'index'])
                ->name('permissions.index');
            Route::get('/permissions/{id}', [ConnectPermissionsController::class, 'show'])
                ->name('permissions.show');

            Route::post('/client-links', [ConnectClientLinksController::class, 'store'])
                ->name('client-links.store');
        });

    Route::prefix('account-subscriptions')->group(function (): void {
        Route::middleware('ability:mollie:write,*')->group(function (): void {
            Route::post('/', [AccountSubscriptionController::class, 'store'])->name('api.account-subscriptions.store');
            Route::delete('/{id}', [AccountSubscriptionController::class, 'destroy'])->name('api.account-subscriptions.destroy');
            Route::post('/{id}/pause', PauseController::class)->name('api.account-subscriptions.pause');
            Route::post('/{id}/resume', ResumeController::class)->name('api.account-subscriptions.resume');
        });

        Route::middleware('ability:mollie:read,mollie:write,*')->group(function (): void {
            Route::get('/', [AccountSubscriptionController::class, 'index'])->name('api.account-subscriptions.index');
            Route::get('/{id}', [AccountSubscriptionController::class, 'show'])->name('api.account-subscriptions.show');
        });
    });
});

// Publiek — state-parameter is de auth (D-07).
Route::get('/oauth/mollie/callback', CallbackController::class)
    ->name('api.oauth.mollie.callback');

Route::get('/oauth/exact/callback', ExactCallbackController::class)
    ->name('api.oauth.exact.callback');
