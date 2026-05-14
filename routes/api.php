<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\ConnectionController;
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
});

// Publiek — state-parameter is de auth (D-07).
Route::get('/oauth/mollie/callback', CallbackController::class)
    ->name('api.oauth.mollie.callback');
