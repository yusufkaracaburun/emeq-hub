<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\Accounting\DocumentsController as AccountingDocumentsController;
use App\Http\Controllers\Api\V1\Accounting\MappingController as AccountingMappingController;
use App\Http\Controllers\Api\V1\Accounting\ReadController as AccountingReadController;
use App\Http\Controllers\Api\V1\Accounting\ValidateDocumentController as AccountingValidateDocumentController;
use App\Http\Controllers\Api\V1\AccountSubscriptions\AccountSubscriptionController;
use App\Http\Controllers\Api\V1\AccountSubscriptions\PauseController;
use App\Http\Controllers\Api\V1\AccountSubscriptions\ResumeController;
use App\Http\Controllers\Api\V1\Billing\SubscriptionController;
use App\Http\Controllers\Api\V1\ConnectionController;
use App\Http\Controllers\Api\V1\ConnectSessionController;
use App\Integrations\Exact\Http\Api\GlAccountsController as ExactGlAccountsController;
use App\Integrations\Exact\Http\Api\JournalsController as ExactJournalsController;
use App\Integrations\Exact\Http\Api\PassThroughController as ExactPassThroughController;
use App\Integrations\Exact\Http\Api\RelationsController as ExactRelationsController;
use App\Integrations\Exact\Http\Api\VatCodesController as ExactVatCodesController;
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
use App\Integrations\Exact\Http\OAuth\ExactCallbackController;
use App\Http\Controllers\Api\V1\OAuth\ProviderInitController;
use App\Http\Controllers\Api\V1\PingController;
use App\Integrations\Snelstart\Http\Api\PassThroughController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/ping', PingController::class)->name('api.ping');

    Route::post('/accounts', [AccountController::class, 'store'])->name('api.accounts.store');

    Route::post('/connections', [ConnectionController::class, 'store'])->name('api.connections.store');
    Route::get('/connections/{connection}', [ConnectionController::class, 'show'])->name('api.connections.show');
    Route::delete('/connections/{connection}', [ConnectionController::class, 'destroy'])->name('api.connections.destroy');

    Route::get('/integrations', IntegrationController::class)
        ->middleware('ability:integrations:manage,consumer:manage-accounts,*')
        ->name('api.integrations.index');

    Route::post('/connect-sessions', ConnectSessionController::class)
        ->middleware('ability:integrations:manage,consumer:manage-accounts,*')
        ->name('api.connect-sessions.store');

    Route::middleware(['ability:integrations:manage,mollie:write', 'feature.provider:mollie'])->group(function (): void {
        Route::post('/oauth/mollie/init', ProviderInitController::class)
            ->defaults('provider', 'mollie')
            ->name('api.oauth.mollie.init');
    });

    Route::any('/snelstart/{path}', PassThroughController::class)
        ->where('path', '.*')
        ->middleware(['feature.provider:snelstart', 'resolve.snelstart.account'])
        ->name('api.snelstart.passthrough');

    Route::middleware('feature.provider:exact')->group(function (): void {
        Route::post('/oauth/exact/init', ProviderInitController::class)
            ->defaults('provider', 'exact')
            ->middleware('ability:integrations:manage,exact:write')
            ->name('api.oauth.exact.init');

        Route::prefix('exact')->middleware('resolve.exact.account')->group(function (): void {
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

            Route::any('/{path}', ExactPassThroughController::class)
                ->where('path', '.*')
                ->name('api.exact.passthrough');
        });
    });

    Route::post('/oauth/{provider}/init', ProviderInitController::class)
        ->where('provider', '[a-z][a-z0-9_-]*')
        ->middleware('ability:integrations:manage')
        ->name('api.oauth.init');

    Route::post('/accounting/documents/validate', AccountingValidateDocumentController::class)
        ->name('api.accounting.documents.validate');

    Route::post('/accounting/documents', [AccountingDocumentsController::class, 'store'])
        ->middleware('idempotent:required')
        ->name('api.accounting.documents.store');

    Route::get('/accounting/capabilities', [AccountingMappingController::class, 'capabilities'])
        ->name('api.accounting.capabilities');

    Route::get('/accounting/documents', [AccountingReadController::class, 'documents'])
        ->name('api.accounting.documents.index');
    Route::get('/accounting/bank-statements', [AccountingReadController::class, 'bankStatements'])
        ->name('api.accounting.bank-statements');
    Route::get('/accounting/ledger-accounts', [AccountingReadController::class, 'ledgerAccounts'])
        ->name('api.accounting.ledger-accounts');
    Route::get('/accounting/tax-codes', [AccountingReadController::class, 'taxCodes'])
        ->name('api.accounting.tax-codes');
    Route::get('/accounting/customers', [AccountingReadController::class, 'customers'])
        ->name('api.accounting.customers');
    Route::get('/accounting/suppliers', [AccountingReadController::class, 'suppliers'])
        ->name('api.accounting.suppliers');
    Route::post('/accounting/sync', [AccountingMappingController::class, 'sync'])
        ->name('api.accounting.sync');
    Route::get('/accounting/reference-data', [AccountingMappingController::class, 'referenceData'])
        ->name('api.accounting.reference-data');
    Route::get('/accounting/mapping', [AccountingMappingController::class, 'show'])
        ->name('api.accounting.mapping.show');
    Route::put('/accounting/mapping', [AccountingMappingController::class, 'update'])
        ->name('api.accounting.mapping.update');

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

Route::get('/oauth/mollie/callback', CallbackController::class)
    ->name('api.oauth.mollie.callback');

Route::get('/oauth/exact/callback', ExactCallbackController::class)
    ->name('api.oauth.exact.callback');
