<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\ConnectionController;
use App\Http\Controllers\Api\V1\Mollie\CustomersController;
use App\Http\Controllers\Api\V1\Mollie\MandatesController;
use App\Http\Controllers\Api\V1\Mollie\PaymentMethodsController;
use App\Http\Controllers\Api\V1\Mollie\PaymentsController;
use App\Http\Controllers\Api\V1\Mollie\RefundsController;
use App\Http\Controllers\Api\V1\OAuth\CallbackController;
use App\Http\Controllers\Api\V1\OAuth\InitController;
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

    Route::middleware('ability:mollie:write')->group(function (): void {
        Route::post('/oauth/mollie/init', InitController::class)
            ->name('api.oauth.mollie.init');
    });

    Route::any('/snelstart/{path}', PassThroughController::class)
        ->where('path', '.*')
        ->middleware('resolve.snelstart.account')
        ->name('api.snelstart.passthrough');

    Route::prefix('mollie')->middleware('resolve.mollie.account')->group(function (): void {
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
    });
});

// Publiek — state-parameter is de auth (D-07).
Route::get('/oauth/mollie/callback', CallbackController::class)
    ->name('api.oauth.mollie.callback');
